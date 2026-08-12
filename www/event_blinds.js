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

    /* Money to 2dp. Blinds are dollars in a home game (.25/.50), not just
     * tournament chips, so every arithmetic result gets rounded here rather
     * than trusting binary floats: 0.1 + 0.2 must not reach the database. */
    function round2(v) { return Math.round((+v || 0) * 100) / 100; }

    /* Classic poker blind ladder: 1 / 1.5 / 2 / 3 / 4 / 6 / 8 per decade.
     * Starts two decades below 1 so a .25/.50 or .50/1 game is on the ladder
     * as well — it used to bottom out at 25, which meant generating a
     * fractional structure jumped straight to chip-sized numbers. */
    var LADDER = [];
    for (var mag = 0.01; mag <= 1000000; mag *= 10) {
        [1, 1.5, 2, 3, 4, 6, 8].forEach(function (b) {
            var raw = b * mag * 25;
            // Whole numbers from 1 up: those rungs are CHIPS, and half a chip
            // does not exist. Rounding only below 1 keeps the tournament part
            // of the ladder byte-identical to what it was before the fractional
            // rungs were added (37.5 must still be 38, not 37.50).
            var v = raw >= 1 ? Math.round(raw) : round2(raw);
            if (LADDER.indexOf(v) === -1) LADDER.push(v);
        });
    }
    LADDER.sort(function (a, b) { return a - b; });
    function ladderNext(v, factor) {
        var target = v * (factor || 1.5);
        for (var i = 0; i < LADDER.length; i++) if (LADDER[i] >= target - 0.001) return LADDER[i];
        return round2(target);
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

    function clamp(v, min, max) { return Math.max(min, Math.min(max, v)); }

    /* One body-level context menu shared by every mount — the editor is
     * remounted whenever check-in re-renders its Setup panes, and a per-mount
     * menu would leak a detached <div> onto <body> each time. */
    var MENU = null;
    var MENU_ACTIONS = null;
    /* Set when a long-press opens the menu. iOS replays a touch as
     * mousedown/mouseup/click once the finger lifts, and that synthetic
     * mousedown lands on the row — i.e. outside the menu — closing it the
     * instant it appeared. Ignore dismissals for a moment afterwards. */
    var lpGuard = 0;

    function menuEl() {
        if (MENU) return MENU;
        MENU = el('div', 'es-menu');
        MENU.addEventListener('click', function (e) {
            var b = e.target.closest('button[data-i]');
            if (!b || !MENU_ACTIONS) return;
            var fn = MENU_ACTIONS[+b.dataset.i];
            closeMenu();
            if (fn) fn();
        });
        document.body.appendChild(MENU);
        return MENU;
    }
    function closeMenu() { if (MENU) MENU.style.display = 'none'; }

    function mount(container, opts) {
        // Drop the previous mount's document-level listeners before wiring new
        // ones, or every remount stacks another copy of each handler.
        if (pkBlindsEditor._teardown) pkBlindsEditor._teardown();
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
                selRows: [],
                undo: [],
                redo: [],
                dirty: false
            };
        if (!S.selRows) S.selRows = [];
        if (!S.undo) { S.undo = []; S.redo = []; }
        pkBlindsEditor._state = S;
        var csrf = opts.csrf;

        container.textContent = '';
        // Marks the query container the layout responds to. The check-in Setup
        // pane is far narrower than the viewport (a whole payouts sidebar sits
        // beside it), so viewport media queries describe the wrong box.
        container.classList.add('es-blinds-root');

        /* Layout: status + controls in a left rail, the rounds grid on the
         * right — the shape blind-structure tools have used forever, so hosts
         * read the schedule as a whole and edit any cell in place. */
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
        var undoRow = el('div', 'es-undorow');
        var undoBtn = el('button', 'es-mini', '↶ Undo'); undoBtn.type='button'; undoBtn.id='esUndo';
        undoBtn.title = 'Undo the last change (Ctrl+Z)';
        var redoBtn = el('button', 'es-mini', '↷ Redo'); redoBtn.type='button'; redoBtn.id='esRedo';
        redoBtn.title = 'Redo (Ctrl+Shift+Z)';
        undoRow.appendChild(undoBtn); undoRow.appendChild(redoBtn);
        ctrls.appendChild(undoRow);
        ctrls.appendChild(el('div', 'es-divider'));
        // No "+ Round" / "+ Break" here: inserting is the row menu's job, and it
        // places the new row WHERE YOU ARE rather than always at the end. The
        // one case the menu cannot serve is an empty grid — nothing to
        // right-click — which the table's empty state handles instead.
        var genTog = el('button', 'es-mini', 'Generator…'); genTog.type='button'; genTog.id='esGenToggle';
        ctrls.appendChild(genTog);
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

        /* ── Rounds grid ── */
        var card = el('div', 'es-card');
        var title = el('div', 'es-card-title', 'Rounds');
        var coarse = window.matchMedia && window.matchMedia('(pointer: coarse)').matches;
        title.appendChild(el('span', 'es-note', coarse
            ? 'Every cell is editable. Long-press a row to insert, move or delete.'
            : 'Every cell is editable. Right-click a row to insert, move or delete; drag ⠿ to reorder.'));
        card.appendChild(title);

        var table = el('table', 'es-table es-grid');
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
         ['start', 'Starting small blind', 25, 0.01, 100000], ['factor', 'Increase factor', 1.5, 1.1, 3],
         ['anteFrom', 'Antes from level (0 = none)', 0, 0, 100], ['breakEvery', 'Break every N levels (0 = none)', 4, 0, 20],
         ['breakMin', 'Break minutes', 10, 1, 120]].forEach(function (f) {
            var lab = el('label', null, f[1]);
            var inp = el('input');
            inp.type = 'number'; inp.value = f[2]; inp.min = f[3]; inp.max = f[4];
            if (f[0] === 'factor') inp.step = '0.1';
            // The starting blind is money: .25 must be typable and must not be
            // rejected as an invalid step.
            if (f[0] === 'start') { inp.step = 'any'; inp.inputMode = 'decimal'; }
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

        function fmtClock(min) {
            var h = Math.floor(min / 60), m = min % 60;
            return h + ':' + (m < 10 ? '0' : '') + m;
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

        /* ── Grid ──
         * Every cell is a live input: there is no read mode to click into, so
         * nothing about a row changes size when you start editing it. Row
         * structure (insert / move / delete / break) goes through the
         * right-click menu and the drag handle. */
        var rowEls = [];      // <tr> per level, index-aligned with S.levels
        var startEls = [];    // the Start Time <td> per row, patched in place
        var dragIdx = null;   // indices being dragged, null when not dragging
        var dropAt = null;    // insertion point (index in S.levels) under the cursor

        /* ── Undo / redo ──
         * Whole-schedule snapshots: the grid is small and every operation
         * (a keystroke, a drag of five rows, a generator run) is one JSON
         * string, so there is nothing to gain from per-field deltas.
         *
         * Typing is coalesced per cell visit rather than per keystroke: the
         * pre-edit snapshot is taken on focus and only banked once the cell
         * actually changed, so one undo steps back over "150" instead of
         * three. */
        var UNDO_MAX = 60;
        var editBase = null;

        function snapshot() { return JSON.stringify(S.levels); }

        function bank(js) {
            if (S.undo.length && S.undo[S.undo.length - 1] === js) return;
            S.undo.push(js);
            if (S.undo.length > UNDO_MAX) S.undo.shift();
            S.redo.length = 0;
            syncUndo();
        }
        /* Bank a cell edit that is still in progress. Any structural change or
         * an undo has to close it first, or the typing and the operation
         * collapse into one step. */
        function flushCell() {
            var b = editBase;
            editBase = null;
            if (b && b !== snapshot()) bank(b);
        }
        function pushUndo() { flushCell(); bank(snapshot()); }

        function restore(js) {
            S.levels = JSON.parse(js);
            S.selRows = []; S.anchor = null; editBase = null;
            setDirty(true); render();
        }
        function doUndo() {
            flushCell();
            if (!S.undo.length) return;
            S.redo.push(snapshot());
            restore(S.undo.pop());
        }
        function doRedo() {
            flushCell();
            if (!S.redo.length) return;
            S.undo.push(snapshot());
            restore(S.redo.pop());
        }
        function syncUndo() {
            undoBtn.disabled = !S.undo.length;
            redoBtn.disabled = !S.redo.length;
        }

        function selIdx() {
            return S.selRows.slice().sort(function (a, b) { return a - b; });
        }
        function setSel(list) {
            S.selRows = list.filter(function (v, i, a) {
                return v >= 0 && v < S.levels.length && a.indexOf(v) === i;
            });
            rowEls.forEach(function (tr, i) {
                tr.classList.toggle('es-row-sel', S.selRows.indexOf(i) !== -1);
            });
        }

        /* A new round inherits from the nearest round ABOVE the insertion point,
         * so inserting mid-ladder continues the ladder rather than restarting. */
        function newLevel(at, isBreak) {
            if (isBreak) return { small_blind: 0, big_blind: 0, ante: 0, duration_minutes: 10, is_break: 1 };
            var ref = null;
            for (var i = Math.min(at, S.levels.length) - 1; i >= 0; i--) {
                if (!S.levels[i].is_break) { ref = S.levels[i]; break; }
            }
            var sb = ref ? ladderNext(ref.small_blind || 25, 1.5) : 25;
            return { small_blind: sb, big_blind: round2(sb * 2), ante: ref ? +ref.ante : 0,
                     duration_minutes: ref ? +ref.duration_minutes : 20, is_break: 0 };
        }

        function focusCell(i, col) {
            var tr = rowEls[i];
            if (!tr) return;
            var inp = tr.querySelector('input[data-col="' + col + '"]');
            if (!inp || inp.disabled) inp = tr.querySelector('input:not([disabled])');
            if (inp) { inp.focus(); inp.select(); }
        }

        function gridKey(e, inp, i, col) {
            if (e.key === 'Enter') {
                e.preventDefault();
                focusCell(i + (e.shiftKey ? -1 : 1), col);
            } else if (e.altKey && (e.key === 'ArrowUp' || e.key === 'ArrowDown')) {
                // Alt+arrow reorders; plain arrows stay as the number stepper.
                e.preventDefault();
                var d = e.key === 'ArrowUp' ? -1 : 1;
                if (i + d < 0 || i + d >= S.levels.length) return;
                pushUndo();
                moveRows([i], d < 0 ? i - 1 : i + 2);
                setDirty(true); render();
                focusCell(i + d, col);
            } else if ((e.ctrlKey || e.metaKey) && (e.key === 'd' || e.key === 'D')) {
                e.preventDefault();
                if (i > 0 && !inp.disabled) {
                    pushUndo();
                    S.levels[i][col] = S.levels[i - 1][col];
                    inp.value = S.levels[i][col];
                    editBase = snapshot();   // the fill is banked; typing over it is a new step
                    setDirty(true); refreshDerived();
                }
            }
        }

        function cellInput(lv, i, key, min, max, cls) {
            var td = el('td', cls);
            var inp = el('input');
            inp.type = 'number'; inp.min = min; inp.max = max;
            // Durations are whole minutes; money is not. step="any" keeps the
            // browser from rejecting 0.25 as an invalid step off 0, and
            // inputmode asks iOS for the keypad WITH a decimal point (a
            // "numeric" keypad has none, which would make .25 untypable).
            var money = key !== 'duration_minutes';
            inp.step = money ? 'any' : '1';
            inp.inputMode = money ? 'decimal' : 'numeric';
            inp.dataset.col = key;
            if (lv.is_break && key !== 'duration_minutes') { inp.disabled = true; inp.value = ''; }
            else inp.value = lv[key];
            // Model updates on every keystroke (so derived totals track live),
            // but the input's own text is left alone until blur — rewriting it
            // mid-type would fight the caret.
            inp.addEventListener('input', function () {
                var raw = money ? parseFloat(inp.value) : parseInt(inp.value, 10);
                var v = clamp(money ? round2(raw || 0) : (raw || 0), min, max);
                // Float equality never holds by luck (0.1*2 !== 0.2 exactly),
                // so the "big blind is still double" test needs a tolerance.
                if (key === 'small_blind' && Math.abs(+lv.big_blind - +lv.small_blind * 2) < 0.005) {
                    lv.big_blind = round2(v * 2);
                    var bb = td.parentNode.querySelector('input[data-col="big_blind"]');
                    if (bb && document.activeElement !== bb) bb.value = lv.big_blind;
                }
                lv[key] = v;
                setDirty(true); refreshDerived();
            });
            inp.addEventListener('change', function () {
                if (!inp.disabled) inp.value = lv[key];
                flushCell();
            });
            inp.addEventListener('keydown', function (e) { gridKey(e, inp, i, key); });
            inp.addEventListener('focus', function () {
                editBase = snapshot();
                if (S.selRows.length < 2) setSel([i]);
                // Touch: one tap should replace the value, not drop a caret
                // mid-number that then needs backspacing out.
                if (coarse) setTimeout(function () { try { inp.select(); } catch (_) {} }, 0);
            });
            td.appendChild(inp);
            return td;
        }

        function buildRow(lv, i, label) {
            var tr = el('tr', lv.is_break ? 'es-row-break' : null);
            var isCur = S.currentLevel > 0 && i === S.currentLevel - 1;
            if (isCur) tr.classList.add('es-row-current');

            var lc = el('td', 'es-round-label');
            var grip = el('span', 'es-drag', '⠿');
            grip.title = 'Drag to reorder';
            lc.appendChild(grip);
            if (isCur) lc.appendChild(el('span', 'es-cur-arrow', '➜'));
            lc.appendChild(el('span', 'es-lbl', label));
            tr.appendChild(lc);

            tr.appendChild(cellInput(lv, i, 'duration_minutes', 1, 999));
            tr.appendChild(cellInput(lv, i, 'ante', 0, 100000000, 'es-cell-num'));
            tr.appendChild(cellInput(lv, i, 'small_blind', 0, 100000000, 'es-cell-num'));
            tr.appendChild(cellInput(lv, i, 'big_blind', 0, 100000000, 'es-cell-num'));
            var st = el('td', 'es-cell-num es-start');
            tr.appendChild(st);
            startEls[i] = st;

            /* Selection: click the row (not a cell) to select, ctrl to add,
             * shift to extend from the last anchor. */
            tr.addEventListener('mousedown', function (e) {
                // Left button only: a right-click fires mousedown first, and
                // resetting the selection here would collapse a multi-row
                // selection the instant you tried to open its menu.
                if (e.button !== 0 || e.target.tagName === 'INPUT') return;
                if (e.shiftKey && S.anchor != null) {
                    var a = Math.min(S.anchor, i), b = Math.max(S.anchor, i), r = [];
                    for (var k = a; k <= b; k++) r.push(k);
                    setSel(r);
                } else if (e.ctrlKey || e.metaKey) {
                    var cur = S.selRows.slice(), at = cur.indexOf(i);
                    if (at === -1) cur.push(i); else cur.splice(at, 1);
                    setSel(cur); S.anchor = i;
                } else {
                    setSel([i]); S.anchor = i;
                }
            });

            tr.addEventListener('contextmenu', function (e) {
                e.preventDefault();
                if (S.selRows.indexOf(i) === -1) { setSel([i]); S.anchor = i; }
                openMenu(e.clientX, e.clientY, i);
            });

            /* Touch has no right-click: long-press opens the same menu — and it
             * does so over a CELL too, matching the desktop, where right-click
             * anywhere on the row (inputs included) already opens it. Skipping
             * inputs here is what made iPad and desktop disagree: a long press
             * in a field raised iOS's Copy / Look Up bar instead of the menu.
             *
             * The field is made unselectable only for the DURATION of the press
             * and restored the moment it ends, so tapping, typing and selecting
             * in that field are untouched. A blanket user-select:none on an
             * input risks taking the caret with it on older iOS. */
            var lp = null, lpInput = null;
            function endPress() {
                clearTimeout(lp);
                if (lpInput) { lpInput.style.webkitUserSelect = ''; lpInput = null; }
            }
            tr.addEventListener('touchstart', function (e) {
                var t = e.touches[0], x = t.clientX, y = t.clientY;
                if (e.target && e.target.tagName === 'INPUT') {
                    lpInput = e.target;
                    lpInput.style.webkitUserSelect = 'none';
                }
                lp = setTimeout(function () {
                    if (S.selRows.indexOf(i) === -1) { setSel([i]); S.anchor = i; }
                    openMenu(x, y, i);
                    lpGuard = Date.now() + 800;
                    endPress();
                }, 550);
            }, { passive: true });
            ['touchend', 'touchmove', 'touchcancel'].forEach(function (ev) {
                tr.addEventListener(ev, endPress, { passive: true });
            });

            /* Drag to reorder. Only the grip arms the row — a permanently
             * draggable <tr> makes text selection inside its inputs impossible. */
            grip.addEventListener('mousedown', function () { tr.draggable = true; });
            tr.addEventListener('dragstart', function (e) {
                dragIdx = (S.selRows.indexOf(i) !== -1 && S.selRows.length > 1) ? selIdx() : [i];
                if (dragIdx.length === 1) { setSel([i]); S.anchor = i; }
                e.dataTransfer.effectAllowed = 'move';
                try { e.dataTransfer.setData('text/plain', 'row'); } catch (_) {}
                dragIdx.forEach(function (k) { if (rowEls[k]) rowEls[k].classList.add('es-dragging'); });
            });
            tr.addEventListener('dragover', function (e) {
                if (!dragIdx) return;
                e.preventDefault();
                e.dataTransfer.dropEffect = 'move';
                var r = tr.getBoundingClientRect();
                var below = (e.clientY - r.top) > r.height / 2;
                clearDropMarks();
                tr.classList.add(below ? 'es-drop-below' : 'es-drop-above');
                dropAt = i + (below ? 1 : 0);
            });
            tr.addEventListener('drop', function (e) {
                if (!dragIdx) return;
                e.preventDefault(); e.stopPropagation();
                pushUndo();
                moveRows(dragIdx, dropAt);
                dragIdx = null;
                setDirty(true); render();
            });
            tr.addEventListener('dragend', endDrag);

            return tr;
        }

        function clearDropMarks() {
            rowEls.forEach(function (t) { t.classList.remove('es-drop-above', 'es-drop-below'); });
        }
        function endDrag() {
            dragIdx = null; dropAt = null;
            clearDropMarks();
            rowEls.forEach(function (t) { t.draggable = false; t.classList.remove('es-dragging'); });
        }

        /* Move rows to an insertion point expressed in the ORIGINAL indexing —
         * anything being moved that sits above the target shifts it left. */
        function moveRows(idxs, targetIdx) {
            if (targetIdx == null) return;
            var sorted = idxs.slice().sort(function (a, b) { return a - b; });
            var moving = sorted.map(function (k) { return S.levels[k]; });
            var before = sorted.filter(function (k) { return k < targetIdx; }).length;
            var rest = S.levels.filter(function (_, k) { return sorted.indexOf(k) === -1; });
            var at = clamp(targetIdx - before, 0, rest.length);
            rest.splice.apply(rest, [at, 0].concat(moving));
            S.levels = rest;
            S.selRows = moving.map(function (m) { return S.levels.indexOf(m); });
            S.anchor = S.selRows[0];
        }

        /* ── Row context menu ── */
        function openMenu(x, y, i) {
            var m = menuEl();
            m.textContent = '';
            MENU_ACTIONS = [];
            var sel = selIdx();
            var n = sel.length || 1;
            var many = n > 1 ? n + ' rows' : 'row';
            var allBreak = sel.every(function (k) { return !!S.levels[k].is_break; });

            function item(label, fn, cls) {
                if (label === null) { m.appendChild(el('div', 'es-menu-sep')); return; }
                var b = el('button', 'es-menu-item' + (cls ? ' ' + cls : ''), label);
                b.type = 'button';
                b.dataset.i = MENU_ACTIONS.length;
                MENU_ACTIONS.push(fn);
                m.appendChild(b);
            }

            item('Insert round above', function () { insertAt(sel[0], false); });
            item('Insert round below', function () { insertAt(sel[sel.length - 1] + 1, false); });
            item('Insert break above', function () { insertAt(sel[0], true); });
            item('Insert break below', function () { insertAt(sel[sel.length - 1] + 1, true); });
            item(null);
            item('Duplicate ' + many, function () {
                pushUndo();
                var copies = sel.map(function (k) {
                    var c = {}; Object.keys(S.levels[k]).forEach(function (p) { c[p] = S.levels[k][p]; });
                    return c;
                });
                S.levels.splice.apply(S.levels, [sel[sel.length - 1] + 1, 0].concat(copies));
                setDirty(true); render();
                setSel(copies.map(function (c) { return S.levels.indexOf(c); }));
            });
            item(allBreak ? 'Make ' + (n > 1 ? 'them rounds' : 'it a round') : 'Make ' + (n > 1 ? 'them breaks' : 'it a break'), function () {
                pushUndo();
                sel.forEach(function (k) {
                    var lv = S.levels[k];
                    if (allBreak) {
                        lv.is_break = 0;
                        var fresh = newLevel(k, false);
                        if (!lv.small_blind) { lv.small_blind = fresh.small_blind; lv.big_blind = fresh.big_blind; }
                    } else {
                        lv.is_break = 1;
                        lv.small_blind = 0; lv.big_blind = 0; lv.ante = 0;
                    }
                });
                setDirty(true); render(); setSel(sel);
            });
            item(null);
            item('Move up', function () {
                if (sel[0] <= 0) return;
                pushUndo();
                moveRows(sel, sel[0] - 1); setDirty(true); render();
            });
            item('Move down', function () {
                if (sel[sel.length - 1] >= S.levels.length - 1) return;
                pushUndo();
                moveRows(sel, sel[sel.length - 1] + 2); setDirty(true); render();
            });
            item('Move to top', function () { pushUndo(); moveRows(sel, 0); setDirty(true); render(); });
            item('Move to bottom', function () { pushUndo(); moveRows(sel, S.levels.length); setDirty(true); render(); });
            item(null);
            item('Fill duration down', function () {
                pushUndo();
                var d = +S.levels[i].duration_minutes;
                for (var k = i + 1; k < S.levels.length; k++) S.levels[k].duration_minutes = d;
                setDirty(true); render();
            });
            item(null);
            item('Delete ' + many, function () {
                pushUndo();
                S.levels = S.levels.filter(function (_, k) { return sel.indexOf(k) === -1; });
                S.selRows = []; S.anchor = null;
                setDirty(true); render();
            }, 'es-menu-danger');

            // Show first so it can be measured, then keep it inside the viewport.
            m.style.display = 'block';
            m.style.left = '0px'; m.style.top = '0px';
            var w = m.offsetWidth, h = m.offsetHeight;
            m.style.left = Math.max(4, Math.min(x, window.innerWidth - w - 6)) + 'px';
            m.style.top = Math.max(4, Math.min(y, window.innerHeight - h - 6)) + 'px';
        }

        function insertAt(at, isBreak) {
            at = clamp(at, 0, S.levels.length);
            pushUndo();
            S.levels.splice(at, 0, newLevel(at, isBreak));
            setDirty(true); render();
            setSel([at]); S.anchor = at;
            focusCell(at, isBreak ? 'duration_minutes' : 'small_blind');
        }

        /* Cheap pass for values derived from the model but not owned by a cell
         * the user is typing in — runs on every keystroke, so it never rebuilds
         * a row (that would blow away focus). */
        function refreshDerived() {
            renderStatus();
            var startMin = 0;
            S.levels.forEach(function (lv, i) {
                if (startEls[i]) startEls[i].textContent = fmtClock(startMin);
                startMin += +lv.duration_minutes || 0;
            });
        }

        /* Deleting the last row leaves nothing to right-click, so the way back
         * has to live in the table itself. */
        function emptyRow() {
            var tr = el('tr', 'es-empty-row');
            var td = el('td');
            td.colSpan = 6;
            var box = el('div', 'es-empty');
            box.appendChild(el('div', 'es-empty-msg', 'No rounds yet.'));
            var acts = el('div', 'es-empty-acts');
            [['Add the first round', false], ['Add a break', true]].forEach(function (a) {
                var b = el('button', 'es-mini', a[0]);
                b.type = 'button';
                b.addEventListener('click', function () { insertAt(0, a[1]); });
                acts.appendChild(b);
            });
            var gb = el('button', 'es-mini', 'Generate a schedule…');
            gb.type = 'button';
            gb.addEventListener('click', function () { if (!gen.classList.contains('open')) genTog.click(); });
            acts.appendChild(gb);
            box.appendChild(acts);
            td.appendChild(box);
            tr.appendChild(td);
            return tr;
        }

        /* Full rebuild — structural changes only (add / delete / move / break). */
        function render() {
            dirtyEl.style.display = S.dirty ? '' : 'none';
            tbody.textContent = '';
            rowEls = []; startEls = [];
            if (!S.levels.length) {
                tbody.appendChild(emptyRow());
                setSel([]);
                refreshDerived();
                syncUndo();
                return;
            }
            var lvlNo = 0, brkNo = 0;
            S.levels.forEach(function (lv, i) {
                var label = lv.is_break ? 'Break ' + (++brkNo) : 'Round ' + (++lvlNo);
                var tr = buildRow(lv, i, label);
                rowEls.push(tr);
                tbody.appendChild(tr);
            });
            setSel(S.selRows || []);
            refreshDerived();
            syncUndo();
        }

        /* ── Wiring ── */
        undoBtn.addEventListener('click', doUndo);
        redoBtn.addEventListener('click', doRedo);
        genTog.addEventListener('click', function () {
            var open = gen.classList.toggle('open');
            genTog.textContent = open ? 'Generator ▴' : 'Generator…';
            if (!open) return;
            // The panel opens BELOW the schedule, which is routinely 20 rows
            // long — off-screen from the button that opened it, so the click
            // read as doing nothing. Centre it rather than align to top: both
            // the site nav and the check-in header are sticky and would cover
            // a top-aligned panel.
            var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            gen.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'center' });
            // Land the caret in the first field, but never on touch: focusing
            // there throws up the on-screen keyboard over the panel we just
            // scrolled to. preventScroll so the focus does not fight the
            // smooth scroll already running.
            if (!coarse) {
                try { G.levels.focus({ preventScroll: true }); G.levels.select(); }
                catch (_) { /* older browsers: focus() takes no options */ }
            }
        });
        genGo.addEventListener('click', function () {
            var n = Math.max(1, Math.min(100, parseInt(G.levels.value, 10) || 15));
            var mins = Math.max(1, Math.min(240, parseInt(G.minutes.value, 10) || 20));
            var sb = Math.max(0.01, round2(parseFloat(G.start.value) || 25));
            var factor = Math.max(1.1, Math.min(3, parseFloat(G.factor.value) || 1.5));
            var anteFrom = Math.max(0, parseInt(G.anteFrom.value, 10) || 0);
            var breakEvery = Math.max(0, parseInt(G.breakEvery.value, 10) || 0);
            var breakMin = Math.max(1, Math.min(120, parseInt(G.breakMin.value, 10) || 10));
            var out = [];
            var cur = ladderAt(sb);
            for (var i = 1; i <= n; i++) {
                out.push({ small_blind: cur, big_blind: round2(cur * 2),
                           ante: (anteFrom > 0 && i >= anteFrom) ? cur : 0,
                           duration_minutes: mins, is_break: 0 });
                if (breakEvery > 0 && i % breakEvery === 0 && i < n) {
                    out.push({ small_blind: 0, big_blind: 0, ante: 0, duration_minutes: breakMin, is_break: 1 });
                }
                cur = ladderNext(cur, factor);
            }
            pushUndo();
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
                pushUndo();
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

        /* Document-level wiring, torn down on the next mount. The menu is
         * body-level so it escapes the table's overflow-x clip; that means the
         * dismiss handlers have to live at document level too. */
        var docs = [
            [document, 'mousedown', function (e) {
                if (Date.now() < lpGuard) return;
                if (!MENU || !MENU.contains(e.target)) closeMenu();
            }],
            [document, 'keydown', function (e) {
                if (e.key === 'Escape') closeMenu();
                if (!(e.ctrlKey || e.metaKey)) return;
                var k = (e.key || '').toLowerCase();
                if (k !== 'z' && k !== 'y') return;
                // Only when this editor is on screen, and never stealing a
                // plain text field's native undo — the grid's own inputs are
                // the exception, since their model updates as you type and
                // native undo would desync it.
                if (!container.offsetParent) return;
                var t = e.target;
                var inGrid = table.contains(t);
                var typing = t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.isContentEditable);
                if (!inGrid && typing) return;
                if (t !== document.body && !container.contains(t)) return;
                e.preventDefault();
                if (k === 'y' || e.shiftKey) doRedo(); else doUndo();
            }],
            [document, 'mouseup', endDrag],
            [window, 'scroll', closeMenu, true],
            [window, 'resize', closeMenu]
        ];
        docs.forEach(function (d) { d[0].addEventListener(d[1], d[2], d[3] || false); });
        pkBlindsEditor._teardown = function () {
            docs.forEach(function (d) { d[0].removeEventListener(d[1], d[2], d[3] || false); });
            closeMenu();
            pkBlindsEditor._teardown = null;
        };

        render();
    }

    window.pkBlindsEditor = { mount: mount, _state: null, _teardown: null };

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
