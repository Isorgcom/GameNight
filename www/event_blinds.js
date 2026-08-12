/* Blind level editor component (per-event, copy-on-write).
 *
 * Used two ways:
 *  - event_blinds.php auto-mounts it into #esBlindsRoot (standalone page);
 *  - checkin.php mounts it into the Setup editor's "Blinds" pane, which is
 *    re-rendered on every settings refresh — so the component keeps its state
 *    (grid + dirty flag) on window.pkBlindsEditor._state and remounts onto
 *    fresh DOM without losing unsaved edits (opts.reuseState).
 *
 * Nothing touches the server until "Save for this event" POSTs
 * event_setup_dl.php save_blinds (copy-on-write there). Loading a preset only
 * fills the grid; "Save to library" publishes via timer_dl.php save_preset. */
(function () {

    /* Classic poker blind ladder: 1 / 1.5 / 2 / 3 / 4 / 6 / 8 per decade. */
    var LADDER = [];
    for (var mag = 1; mag <= 1000000; mag *= 10) {
        [1, 1.5, 2, 3, 4, 6, 8].forEach(function (b) {
            var v = Math.round(b * mag * 25);
            if (LADDER.indexOf(v) === -1) LADDER.push(v);
        });
    }
    LADDER.sort(function (a, b) { return a - b; });
    function ladderNext(v, factor) {
        var target = v * (factor || 1.5);
        for (var i = 0; i < LADDER.length; i++) if (LADDER[i] >= target - 0.001) return LADDER[i];
        return Math.round(target);
    }
    function ladderAt(v) {
        for (var i = 0; i < LADDER.length; i++) if (LADDER[i] >= v) return LADDER[i];
        return v;
    }

    function el(tag, cls, text) {
        var e = document.createElement(tag);
        if (cls) e.className = cls;
        if (text !== undefined) e.textContent = text;
        return e;
    }

    function mount(container, opts) {
        // Reuse in-memory state across remounts (checkin re-renders its panes);
        // a different event, or no prior state, starts fresh from opts.
        var S = (opts.reuseState && pkBlindsEditor._state && pkBlindsEditor._state.eventId === opts.eventId)
            ? pkBlindsEditor._state
            : {
                eventId: opts.eventId,
                levels: (opts.levels || []).map(function (l) {
                    return { small_blind: +l.small_blind, big_blind: +l.big_blind, ante: +l.ante,
                             duration_minutes: +l.duration_minutes, is_break: +l.is_break };
                }),
                currentLevel: opts.currentLevel || 0,
                isLocal: !!opts.isLocal,
                timerRunning: !!opts.timerRunning,
                dirty: false
            };
        pkBlindsEditor._state = S;
        var csrf = opts.csrf;

        container.textContent = '';

        /* Layout: status + controls in a left rail, the rounds table on the
         * right — the shape blind-structure tools have used forever, so hosts
         * read the schedule first and edit second (click a row to edit it). */
        var flex = el('div', 'es-blinds-flex');
        var side = el('div', 'es-blinds-side');
        var main = el('div', 'es-blinds-main');
        flex.appendChild(side); flex.appendChild(main);
        container.appendChild(flex);

        /* ── Status card ── */
        var statCard = el('div', 'es-card');
        statCard.appendChild(el('div', 'es-card-title', 'Status'));
        var statGrid = el('div', 'es-status');
        var STAT = {};
        ['Levels', 'Rounds', 'Breaks', 'Length', 'Play', 'On break'].forEach(function (k) {
            statGrid.appendChild(el('span', 'es-status-k', k));
            var v = el('span', 'es-status-v', '–');
            statGrid.appendChild(v);
            STAT[k] = v;
        });
        statCard.appendChild(statGrid);
        side.appendChild(statCard);

        /* ── Controls card ── */
        var ctrlCard = el('div', 'es-card');
        ctrlCard.appendChild(el('div', 'es-card-title', 'Controls'));
        var ctrls = el('div', 'es-ctrls');
        var addLvl = el('button', 'es-mini', '+ Round'); addLvl.type='button'; addLvl.id='esAddLevel';
        var addBrk = el('button', 'es-mini', '+ Break'); addBrk.type='button'; addBrk.id='esAddBreak';
        var genTog = el('button', 'es-mini', 'Generator…'); genTog.type='button'; genTog.id='esGenToggle';
        ctrls.appendChild(addLvl); ctrls.appendChild(addBrk); ctrls.appendChild(genTog);
        ctrls.appendChild(el('div', 'es-divider'));
        var presetSel = el('select'); presetSel.id='esPresetSel';
        presetSel.appendChild(new Option('Choose a preset…', ''));
        var loadBtn = el('button', 'es-mini', 'Load into table'); loadBtn.type='button'; loadBtn.id='esLoadBtn';
        loadBtn.title = 'Fills the table only — nothing changes for this event until you save';
        var pubBtn = el('button', 'es-mini', 'Save to library…'); pubBtn.type='button'; pubBtn.id='esPublishBtn';
        pubBtn.title = 'Save the table to your preset library, so other events can load it';
        ctrls.appendChild(presetSel); ctrls.appendChild(loadBtn); ctrls.appendChild(pubBtn);
        ctrls.appendChild(el('div', 'es-divider'));
        var saveBtn = el('button', 'es-btn es-btn-primary', 'Save for this event'); saveBtn.type='button'; saveBtn.id='esSave';
        var saveRow = el('div', 'es-saverow');
        var dirtyEl = el('span', 'es-dirty', '●'); dirtyEl.title = 'Unsaved changes';
        var savedEl = el('span', 'es-saved', 'Saved ✓');
        saveRow.appendChild(dirtyEl); saveRow.appendChild(savedEl);
        ctrls.appendChild(saveBtn); ctrls.appendChild(saveRow);
        var note = el('div', 'es-note', S.isLocal
            ? 'This event has its own schedule (editing it affects only this game).'
            : 'Currently using a shared preset — saving gives this event its own copy.');
        ctrls.appendChild(note);
        if (S.timerRunning) ctrls.appendChild(el('div', 'es-note', 'The clock is running — saving keeps the current level and time.'));
        if (opts.links) {
            var dl = el('a', 'es-note', 'Timer display →');
            dl.href = opts.links.display;
            ctrls.appendChild(dl);
        }
        ctrlCard.appendChild(ctrls);
        side.appendChild(ctrlCard);

        /* ── Rounds table ── */
        var card = el('div', 'es-card');
        var title = el('div', 'es-card-title', 'Rounds');
        title.appendChild(el('span', 'es-note', 'Click a row to edit it.'));
        card.appendChild(title);

        var table = el('table', 'es-table');
        var thead = el('thead'); var hr = el('tr');
        [['Level', ''], ['Duration', ''], ['Ante', 'es-th-num'], ['Small Blind', 'es-th-num'],
         ['Big Blind', 'es-th-num'], ['Start Time', 'es-th-num']].forEach(function (t) {
            hr.appendChild(el('th', t[1] || null, t[0]));
        });
        thead.appendChild(hr); table.appendChild(thead);
        var tbody = el('tbody'); tbody.id='esRows';
        table.appendChild(tbody);
        card.appendChild(table);

        /* Generator (opens under the table) */
        var gen = el('div', 'es-gen'); gen.id='esGen';
        var grid = el('div', 'es-gen-grid');
        var G = {};
        [['levels', 'Levels', 15, 1, 100], ['minutes', 'Minutes per level', 20, 1, 240],
         ['start', 'Starting small blind', 25, 1, 100000], ['factor', 'Increase factor', 1.5, 1.1, 3],
         ['anteFrom', 'Antes from level (0 = none)', 0, 0, 100], ['breakEvery', 'Break every N levels (0 = none)', 4, 0, 20],
         ['breakMin', 'Break minutes', 10, 1, 120]].forEach(function (f) {
            var lab = el('label', null, f[1]);
            var inp = el('input');
            inp.type = 'number'; inp.value = f[2]; inp.min = f[3]; inp.max = f[4];
            if (f[0] === 'factor') inp.step = '0.1';
            lab.appendChild(inp);
            grid.appendChild(lab);
            inp.id = 'esGen' + f[0].charAt(0).toUpperCase() + f[0].slice(1);
            G[f[0]] = inp;
        });
        gen.appendChild(grid);
        var genGo = el('button', 'es-mini', 'Generate (replaces the table)'); genGo.type='button'; genGo.id='esGenGo';
        gen.appendChild(genGo);
        card.appendChild(gen);
        main.appendChild(card);

        function setDirty(d) {
            S.dirty = d;
            dirtyEl.style.display = d ? '' : 'none';
            if (d) savedEl.style.display = 'none';
        }

        function fmtNum(n) { return (+n || 0).toLocaleString('en-US'); }
        function fmtClock(min) {
            var h = Math.floor(min / 60), m = min % 60;
            return h + ':' + (m < 10 ? '0' : '') + m;
        }

        function numCell(value, min, max, oninput, disabled, cls) {
            var td = el('td', cls);
            var inp = el('input');
            inp.type = 'number'; inp.min = min; inp.max = max; inp.value = value;
            if (disabled) inp.disabled = true;
            inp.addEventListener('change', function () {
                oninput(Math.max(min, Math.min(max, parseInt(inp.value, 10) || 0)));
                setDirty(true);
                render();
            });
            td.appendChild(inp);
            return td;
        }

        function renderStatus() {
            var rounds = 0, breaks = 0, play = 0, brk = 0;
            S.levels.forEach(function (lv) {
                if (lv.is_break) { breaks++; brk += +lv.duration_minutes; }
                else { rounds++; play += +lv.duration_minutes; }
            });
            STAT['Levels'].textContent = S.levels.length;
            STAT['Rounds'].textContent = rounds;
            STAT['Breaks'].textContent = breaks;
            STAT['Length'].textContent = fmtClock(play + brk);
            STAT['Play'].textContent = fmtClock(play);
            STAT['On break'].textContent = fmtClock(brk);
        }

        function render() {
            dirtyEl.style.display = S.dirty ? '' : 'none';
            renderStatus();
            tbody.textContent = '';
            var lvlNo = 0, brkNo = 0, startMin = 0;
            S.levels.forEach(function (lv, i) {
                var editing = S.sel === i;
                var tr = el('tr', lv.is_break ? 'es-row-break' : null);
                if (editing) tr.classList.add('es-row-edit');
                var isCur = S.currentLevel > 0 && i === S.currentLevel - 1;
                if (isCur) tr.classList.add('es-row-current');

                var label;
                if (lv.is_break) { brkNo++; label = 'Break ' + brkNo; }
                else { lvlNo++; label = 'Round ' + lvlNo; }
                var lc = el('td', 'es-round-label');
                if (isCur) lc.appendChild(el('span', 'es-cur-arrow', '➜'));
                lc.appendChild(document.createTextNode(label));
                tr.appendChild(lc);

                if (editing) {
                    tr.appendChild(numCell(lv.duration_minutes, 1, 999, function (v) { lv.duration_minutes = v; }));
                    tr.appendChild(numCell(lv.ante, 0, 100000000, function (v) { lv.ante = v; }, !!lv.is_break, 'es-cell-num'));
                    tr.appendChild(numCell(lv.small_blind, 0, 100000000, function (v) { lv.small_blind = v; }, !!lv.is_break, 'es-cell-num'));
                    tr.appendChild(numCell(lv.big_blind, 0, 100000000, function (v) { lv.big_blind = v; }, !!lv.is_break, 'es-cell-num'));

                    var act = el('td');
                    var cluster = el('div', 'es-rowact');
                    var cbLab = el('label', 'es-note', 'Break ');
                    var cb = el('input');
                    cb.type = 'checkbox'; cb.checked = !!lv.is_break;
                    cb.addEventListener('change', function () {
                        lv.is_break = cb.checked ? 1 : 0;
                        if (lv.is_break) { lv.small_blind = 0; lv.big_blind = 0; lv.ante = 0; }
                        setDirty(true); render();
                    });
                    cbLab.appendChild(cb);
                    cluster.appendChild(cbLab);
                    [['▲', -1], ['▼', 1]].forEach(function (m) {
                        var b = el('button', 'es-mini', m[0]); b.type = 'button';
                        b.title = m[1] < 0 ? 'Move up' : 'Move down';
                        b.disabled = (i + m[1] < 0) || (i + m[1] >= S.levels.length);
                        b.addEventListener('click', function () {
                            S.levels.splice(i + m[1], 0, S.levels.splice(i, 1)[0]);
                            S.sel = i + m[1];
                            setDirty(true); render();
                        });
                        cluster.appendChild(b);
                    });
                    var rm = el('button', 'es-mini es-mini-danger', '×'); rm.type = 'button'; rm.title = 'Remove';
                    rm.addEventListener('click', function () {
                        S.levels.splice(i, 1); S.sel = null;
                        setDirty(true); render();
                    });
                    cluster.appendChild(rm);
                    act.appendChild(cluster);
                    tr.appendChild(act);
                } else {
                    tr.appendChild(el('td', null, lv.duration_minutes + 'm'));
                    tr.appendChild(el('td', 'es-cell-num', lv.is_break ? '' : (lv.ante ? fmtNum(lv.ante) : '—')));
                    tr.appendChild(el('td', 'es-cell-num', lv.is_break ? '' : fmtNum(lv.small_blind)));
                    tr.appendChild(el('td', 'es-cell-num', lv.is_break ? '' : fmtNum(lv.big_blind)));
                    tr.appendChild(el('td', 'es-cell-num es-start', fmtClock(startMin)));
                    tr.addEventListener('click', function () { S.sel = i; render(); });
                }

                startMin += +lv.duration_minutes;
                tbody.appendChild(tr);
            });
        }

        /* ── Wiring ── */
        addLvl.addEventListener('click', function () {
            var last = null;
            for (var i = S.levels.length - 1; i >= 0; i--) if (!S.levels[i].is_break) { last = S.levels[i]; break; }
            var sb = last ? ladderNext(last.small_blind || 25, 1.5) : 25;
            S.levels.push({ small_blind: sb, big_blind: sb * 2, ante: last ? last.ante : 0,
                            duration_minutes: last ? last.duration_minutes : 20, is_break: 0 });
            setDirty(true); render();
        });
        addBrk.addEventListener('click', function () {
            S.levels.push({ small_blind: 0, big_blind: 0, ante: 0, duration_minutes: 10, is_break: 1 });
            setDirty(true); render();
        });
        genTog.addEventListener('click', function () { gen.classList.toggle('open'); });
        genGo.addEventListener('click', function () {
            var n = Math.max(1, Math.min(100, parseInt(G.levels.value, 10) || 15));
            var mins = Math.max(1, Math.min(240, parseInt(G.minutes.value, 10) || 20));
            var sb = Math.max(1, parseInt(G.start.value, 10) || 25);
            var factor = Math.max(1.1, Math.min(3, parseFloat(G.factor.value) || 1.5));
            var anteFrom = Math.max(0, parseInt(G.anteFrom.value, 10) || 0);
            var breakEvery = Math.max(0, parseInt(G.breakEvery.value, 10) || 0);
            var breakMin = Math.max(1, Math.min(120, parseInt(G.breakMin.value, 10) || 10));
            var out = [];
            var cur = ladderAt(sb);
            for (var i = 1; i <= n; i++) {
                out.push({ small_blind: cur, big_blind: cur * 2,
                           ante: (anteFrom > 0 && i >= anteFrom) ? cur : 0,
                           duration_minutes: mins, is_break: 0 });
                if (breakEvery > 0 && i % breakEvery === 0 && i < n) {
                    out.push({ small_blind: 0, big_blind: 0, ante: 0, duration_minutes: breakMin, is_break: 1 });
                }
                cur = ladderNext(cur, factor);
            }
            S.levels = out;
            setDirty(true); render();
        });

        fetch('/timer_dl.php?action=get_presets')
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (!j || !j.ok) return;
                j.presets.forEach(function (p) {
                    var o = new Option(p.name + (p.is_default ? ' (default)' : p.is_global ? ' (site)' : p.league_name ? ' (' + p.league_name + ')' : ''), p.id);
                    presetSel.appendChild(o);
                });
            });

        function post(action, fields) {
            var body = new URLSearchParams();
            body.set('action', action);
            body.set('csrf_token', csrf);
            body.set('event_id', S.eventId);
            Object.keys(fields || {}).forEach(function (k) { body.set(k, fields[k]); });
            return fetch('/event_setup_dl.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: String(body) })
                .then(function (r) { return r.json(); });
        }

        loadBtn.addEventListener('click', function () {
            if (!presetSel.value) return;
            post('get_preset_levels', { preset_id: presetSel.value }).then(function (j) {
                if (!j.ok) { (window.pkAlert || alert)(j.error || 'Could not load preset'); return; }
                S.levels = j.levels.map(function (l) {
                    return { small_blind: +l.small_blind, big_blind: +l.big_blind, ante: +l.ante,
                             duration_minutes: +l.duration_minutes, is_break: +l.is_break };
                });
                setDirty(true); render();
            });
        });

        pubBtn.addEventListener('click', function () {
            var ask = window.pkPrompt ? pkPrompt('Preset name for your library:') : Promise.resolve(prompt('Preset name:'));
            ask.then(function (name) {
                if (!name || !name.trim()) return;
                var body = new URLSearchParams();
                body.set('action', 'save_preset');
                body.set('csrf_token', csrf);
                body.set('name', name.trim());
                body.set('levels', JSON.stringify(S.levels.map(function (l, i) {
                    return { level_number: i + 1, small_blind: l.small_blind, big_blind: l.big_blind,
                             ante: l.ante, duration_minutes: l.duration_minutes, is_break: l.is_break };
                })));
                fetch('/timer_dl.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: String(body) })
                    .then(function (r) { return r.json(); })
                    .then(function (j) {
                        if (!j.ok) { (window.pkAlert || alert)(j.error || 'Save failed'); return; }
                        presetSel.appendChild(new Option(name.trim(), j.preset_id));
                        presetSel.value = j.preset_id;
                        (window.pkAlert || alert)('Saved to your preset library.');
                    });
            });
        });

        saveBtn.addEventListener('click', function () {
            if (!S.levels.length) { (window.pkAlert || alert)('Add at least one level.'); return; }
            post('save_blinds', { levels: JSON.stringify(S.levels) }).then(function (j) {
                if (!j.ok) { (window.pkAlert || alert)(j.error || 'Save failed'); return; }
                setDirty(false);
                if (!S.isLocal) { S.isLocal = true; note.textContent = 'This event has its own schedule (editing it affects only this game).'; }
                savedEl.style.display = '';
                setTimeout(function () { savedEl.style.display = 'none'; }, 2000);
            }).catch(function () { (window.pkAlert || alert)('Network error'); });
        });

        render();
    }

    window.pkBlindsEditor = { mount: mount, _state: null };

    /* Standalone page (event_blinds.php) auto-mount. */
    var root = document.getElementById('esBlindsRoot');
    if (root && typeof ES_EVENT_ID !== 'undefined') {
        mount(root, {
            eventId: ES_EVENT_ID, csrf: ES_CSRF,
            levels: ES_LEVELS, currentLevel: ES_CURRENT_LEVEL,
            isLocal: (typeof ES_IS_LOCAL !== 'undefined' && ES_IS_LOCAL),
            timerRunning: (typeof ES_TIMER_RUNNING !== 'undefined' && ES_TIMER_RUNNING)
            /* no links: the page's own strip already swaps to Timer Display */
        });
    }
})();
