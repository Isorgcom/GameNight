/**
 * Timer BETA layout engine — phase A (renderer + built-in layouts).
 *
 * A layout is a JSON tree:
 *   node       := { row: [node…], …props } | { col: [node…], …props } | { cell: {…} }
 *   container  := weight (flex-grow), gap ("0.5vh"), pad, bg, align, border
 *   cell       := text        string; may contain <tokens> and newlines
 *                 size        font size as % of viewport height (scales anywhere)
 *                 fit         true = fill the box instead (clock cells)
 *                 color/bg/bold/pad/align/weight/border/opacity/spacing
 *                 clockColors true = colour tracks warn/critical thresholds
 *                 when        always|running|paused|on_break|has_ante|has_rebuys|game_over
 *
 * The tree renders to nested flexbox, so nothing can overlap or leave the
 * screen — the property the absolutely-positioned theme model could not give.
 * Cell text is authored data: every string lands via textContent, tokens become
 * <span data-tok>, and nothing is ever innerHTML'd. Keep it that way; the
 * editor phase will feed user input straight through this path.
 *
 * The four built-ins are reimplementations of The Tournament Director's
 * shipped screens (Default 1920x1080, Black & Green, Minimalist, Two-Column),
 * authored from its screenshots and .tlo structure. No TD assets are used.
 */
(function () {
'use strict';

/* ── Built-in layouts ─────────────────────────────────────────────────── */

var LAYOUTS = {

    td_classic: {
        name: 'TD Classic',
        bg: { color: '#1d4ed8', gradient: ['#1e40af', '#2563eb'] },
        root: { col: [
            { cell: { text: '<eventName>', size: 4.6, bold: true, color: '#1e3a8a', bg: '#f8fafc', pad: '0.6vh 1vw' } },
            { cell: { text: '<buyinLine>', size: 2.0, color: '#dbeafe', pad: '0.4vh 0' } },
            { row: [
                { col: [
                    { cell: { text: 'Round\n<level>',      size: 2.4, color: '#fff' } },
                    { cell: { text: 'Entries\n<entries>',  size: 2.4, color: '#fff' } },
                    { cell: { text: 'Players In\n<players>', size: 2.4, color: '#fff' } },
                    { cell: { text: 'Rebuys\n<rebuys>',    size: 2.4, color: '#fff', when: 'has_rebuys' } },
                    { cell: { text: 'Chip Count\n<chipCount>', size: 2.4, color: '#fff' } },
                    { cell: { text: 'Avg Stack\n<avgStack>',   size: 2.4, color: '#fff' } },
                    { cell: { text: 'Total Pot\n<pot>',    size: 2.4, color: '#fff' } }
                ], weight: 1, gap: '0.3vh', pad: '1vh 0.5vw', justify: 'center' },
                { col: [
                    { cell: { text: '<clock>', fit: true, bold: true, color: '#fff', clockColors: true }, weight: 5 },
                    { cell: { text: '<gameName>', size: 3.0, color: '#dbeafe' }, weight: 1 },
                    { cell: { text: 'Blinds\n<blinds>', size: 4.2, bold: true, color: '#fff' }, weight: 2 },
                    { cell: { text: 'Ante: <ante>', size: 2.6, color: '#dbeafe', when: 'has_ante' }, weight: 1 }
                ], weight: 3 },
                { col: [
                    { cell: { text: 'Current Time\n<currentTime>', size: 2.4, color: '#fff' } },
                    { cell: { text: 'Elapsed Time\n<elapsedTime>', size: 2.4, color: '#fff' } },
                    { cell: { text: 'Next Break\n<nextBreak>',     size: 2.4, color: '#fff' } }
                ], weight: 1, gap: '0.5vh', pad: '1vh 0.5vw', justify: 'center' }
            ], weight: 1 },
            { cell: { text: 'Next Round: <nextBlinds>', size: 2.4, color: '#bfdbfe', bg: 'rgba(15,23,42,0.35)', pad: '0.8vh 0' } },
            { cell: { text: '<prizes>', size: 2.2, color: '#fff', bg: 'rgba(15,23,42,0.45)', pad: '0.8vh 1vw' } }
        ] }
    },

    black_green: {
        name: 'Black & Green',
        bg: { color: '#000' },
        root: { col: [
            { cell: { text: '<eventName>', size: 4.4, bold: true, color: '#22c55e', pad: '0.8vh 0' } },
            { cell: { text: 'Round: <level>      Next Break: <nextBreak>      Players Remaining: <players>',
                      size: 2.2, bold: true, color: '#000', bg: '#16a34a', pad: '0.7vh 1vw' } },
            { col: [
                { cell: { text: '<clock>', fit: true, bold: true, color: '#fff', clockColors: true }, weight: 4 },
                { cell: { text: '<gameName>', size: 2.8, color: '#e2e8f0' }, weight: 1 },
                { cell: { text: 'Blinds: <blinds>', size: 4.0, bold: true, color: '#22c55e' }, weight: 1.4 },
                { cell: { text: 'Ante: <ante>', size: 2.4, color: '#86efac', when: 'has_ante' }, weight: 0.8 },
                { cell: { text: 'Next Round: <nextGameName>\nNext Blinds: <nextBlinds>', size: 2.2, color: '#94a3b8' }, weight: 1.2 }
            ], weight: 1, pad: '1vh 0' },
            { cell: { text: '# Entries: <entries>   # Chips: <chipCount>   Prize Pool: <pot>   # Rebuys: <rebuys>',
                      size: 2.2, bold: true, color: '#000', bg: '#16a34a', pad: '0.7vh 1vw' } }
        ] }
    },

    minimalist: {
        name: 'Minimalist',
        bg: { color: '#000' },
        root: { col: [
            { cell: { text: '<clock>', fit: true, bold: true, color: '#fff', clockColors: true }, weight: 3 },
            { row: [
                { cell: { text: 'Small Blind\n<smallBlind>', size: 3.4, color: '#cbd5e1' }, weight: 1 },
                { cell: { text: 'Big Blind\n<bigBlind>',     size: 3.4, color: '#cbd5e1' }, weight: 1 }
            ], weight: 1 },
            { cell: { text: 'Ante\n<ante>', size: 3.0, color: '#94a3b8', when: 'has_ante' }, weight: 0.8 }
        ], pad: '2vh 2vw' }
    },

    two_column: {
        name: 'Two Column',
        bg: { color: '#065f46', gradient: ['#064e3b', '#059669'] },
        root: { col: [
            { cell: { text: '<eventName>', size: 4.2, bold: true, color: '#064e3b', bg: '#f0fdf4', pad: '0.6vh 1vw' } },
            { row: [
                { col: [
                    { cell: { text: 'Next Break: <nextBreak>',   size: 2.1, color: '#0f172a', align: 'left' } },
                    { cell: { text: 'Elapsed Time: <elapsedTime>', size: 2.1, color: '#0f172a', align: 'left' } },
                    { cell: { text: 'Current Time: <currentTime>', size: 2.1, color: '#0f172a', align: 'left' } },
                    { cell: { text: 'Round: <level>',            size: 2.1, color: '#0f172a', align: 'left' } },
                    { cell: { text: 'Entries: <entries>',        size: 2.1, color: '#0f172a', align: 'left' } },
                    { cell: { text: 'Players In: <players>',     size: 2.1, color: '#0f172a', align: 'left' } },
                    { cell: { text: 'Chip Count: <chipCount>',   size: 2.1, color: '#0f172a', align: 'left' } },
                    { cell: { text: 'Avg Stack: <avgStack>',     size: 2.1, color: '#0f172a', align: 'left' } },
                    { cell: { text: 'Total Pot: <pot>',          size: 2.1, color: '#0f172a', align: 'left' } },
                    { cell: { text: '<prizeList>', size: 1.9, color: '#334155', align: 'left' }, weight: 1 }
                ], weight: 1, bg: '#f8fafc', pad: '1vh 1vw', gap: '0.2vh' },
                { col: [
                    { cell: { text: '<clock>', fit: true, bold: true, color: '#fff', clockColors: true }, weight: 3 },
                    { cell: { text: '<gameName>', size: 2.6, color: '#d1fae5' }, weight: 0.8 },
                    { cell: { text: 'Blinds\n<blinds>', size: 4.0, bold: true, color: '#fff' }, weight: 1.6 },
                    { cell: { text: 'Next Round: <nextGameName>\nNext Blinds: <nextBlinds>', size: 2.1, color: '#a7f3d0' }, weight: 1 }
                ], weight: 2.2 }
            ], weight: 1 }
        ] }
    }
};

/* ── Live state, normalised for tokens ────────────────────────────────── */

var S = {
    eventName: TB_EVENT_TITLE || 'Tournament Timer',
    level: 1, remaining: 900, running: false, isBreak: false, gameOver: false,
    fetchedAt: Date.now(),
    levels: [], sb: 0, bb: 0, ante: 0, nsb: null, nbb: null, nante: null, nextIsBreak: false,
    players: '-', entries: '-', rebuys: 0, pot: '-', chipCount: '-', avgStack: '-',
    prizes: [], warnSecs: 60,
    sample: !TB_SESSION_ID
};

if (S.sample) {
    S.eventName = 'Saturday Night Poker';
    S.level = 5; S.remaining = 754; S.running = true;
    S.levels = [
        { level_number: 5, small_blind: 75,  big_blind: 150, ante: 0,  duration_minutes: 20, is_break: 0 },
        { level_number: 6, small_blind: 100, big_blind: 200, ante: 25, duration_minutes: 20, is_break: 0 },
        { level_number: 7, small_blind: 0,   big_blind: 0,   ante: 0,  duration_minutes: 10, is_break: 1 },
        { level_number: 8, small_blind: 150, big_blind: 300, ante: 50, duration_minutes: 20, is_break: 0 }
    ];
    S.players = '12/18'; S.entries = 18; S.rebuys = 3; S.pot = '$1,050';
    S.chipCount = '67,500'; S.avgStack = '5,625';
    S.prizes = ['1st: $525', '2nd: $315', '3rd: $210'];
}

function fmtChips(n) { return (n === null || n === undefined || n === '') ? '-' : Number(n).toLocaleString('en-US'); }
function fmtClock(sec) {
    sec = Math.max(0, Math.floor(sec));
    var h = Math.floor(sec / 3600), m = Math.floor((sec % 3600) / 60), s = sec % 60;
    var p = function (x) { return String(x).padStart(2, '0'); };
    return h > 0 ? h + ':' + p(m) + ':' + p(s) : p(m) + ':' + p(s);
}
function liveRemaining() {
    return S.running ? S.remaining - (Date.now() - S.fetchedAt) / 1000 : S.remaining;
}
function findLevel(n) {
    for (var i = 0; i < S.levels.length; i++) if ((S.levels[i].level_number | 0) === n) return S.levels[i];
    return null;
}
// Countdown to the start of the next break: what's left of this level plus the
// full length of every level between here and the first is_break row.
function nextBreakSecs() {
    if (S.isBreak) return 0;
    var secs = liveRemaining(), i, found = false;
    for (i = 0; i < S.levels.length; i++) {
        var lv = S.levels[i];
        if ((lv.level_number | 0) <= S.level) continue;
        if (lv.is_break | 0) { found = true; break; }
        secs += (lv.duration_minutes | 0) * 60;
    }
    return found ? secs : null;
}
// Play time so far: completed levels plus the used part of this one. Ignores
// pauses, which is the honest label for a blind-schedule elapsed figure.
function elapsedSecs() {
    var secs = 0;
    for (var i = 0; i < S.levels.length; i++) {
        var lv = S.levels[i];
        if ((lv.level_number | 0) < S.level) secs += (lv.duration_minutes | 0) * 60;
        else if ((lv.level_number | 0) === S.level) secs += (lv.duration_minutes | 0) * 60 - liveRemaining();
    }
    return Math.max(0, secs);
}

/* ── Token registry — add a token, get it everywhere ──────────────────── */

var TOKENS = {
    eventName:    function () { return S.eventName; },
    level:        function () { return S.isBreak ? 'Break' : String(S.level); },
    clock:        function () { return fmtClock(liveRemaining()); },
    gameName:     function () { return S.isBreak ? 'BREAK' : "No Limit Texas Hold 'Em"; },
    nextGameName: function () { return S.nextIsBreak ? 'Break' : "No Limit Texas Hold 'Em"; },
    smallBlind:   function () { return fmtChips(S.sb); },
    bigBlind:     function () { return fmtChips(S.bb); },
    ante:         function () { return S.ante ? fmtChips(S.ante) : '-'; },
    blinds:       function () { return S.isBreak ? 'On Break' : fmtChips(S.sb) + ' / ' + fmtChips(S.bb); },
    nextBlinds:   function () {
        if (S.nsb === null) return '-';
        if (S.nextIsBreak) return 'Break';
        return fmtChips(S.nsb) + ' / ' + fmtChips(S.nbb) + (S.nante ? '  Ante ' + fmtChips(S.nante) : '');
    },
    players:      function () { return String(S.players); },
    entries:      function () { return String(S.entries); },
    rebuys:       function () { return String(S.rebuys); },
    pot:          function () { return String(S.pot); },
    chipCount:    function () { return String(S.chipCount); },
    avgStack:     function () { return String(S.avgStack); },
    buyinLine:    function () { return S.sample ? '$25.00 Buy-in  ·  $25.00 Rebuys' : ''; },
    currentTime:  function () {
        var d = new Date(), h = d.getHours(), m = String(d.getMinutes()).padStart(2, '0');
        var ap = h >= 12 ? 'pm' : 'am'; h = h % 12 || 12;
        return h + ':' + m + ' ' + ap;
    },
    elapsedTime:  function () { return fmtClock(elapsedSecs()); },
    nextBreak:    function () { var s = nextBreakSecs(); return s === null ? '-' : (S.isBreak ? 'now' : fmtClock(s)); },
    prizes:       function () { return S.prizes.length ? S.prizes.join('    ') : ''; },
    prizeList:    function () { return S.prizes.length ? 'Prizes\n' + S.prizes.join('\n') : ''; }
};

var WHEN = {
    always:     function () { return true; },
    running:    function () { return S.running; },
    paused:     function () { return !S.running; },
    on_break:   function () { return S.isBreak; },
    has_ante:   function () { return (S.ante | 0) > 0; },
    has_rebuys: function () { return (S.rebuys | 0) > 0; },
    game_over:  function () { return S.gameOver; }
};

/* ── Renderer: JSON tree → nested flexbox ─────────────────────────────── */

var root = document.getElementById('tbRoot');
var allCells = [];   // every cell; empties hide themselves (bg bands too)
var fitCells = [];   // { el, inner, lastText }
var tokSpans = [];   // { el, name }
var whenCells = [];  // { el, cond }
var clockCells = []; // cells whose colour tracks warn/critical

function applyBox(el, node) {
    if (node.weight !== undefined) { el.style.flexGrow = String(node.weight); el.style.flexBasis = '0'; }
    if (node.gap) el.style.gap = node.gap;
    if (node.pad) el.style.padding = node.pad;
    if (node.bg) el.style.background = node.bg;
    if (node.border) el.style.border = node.border;
    if (node.justify) el.style.justifyContent = node.justify;
}

function buildCell(spec) {
    var el = document.createElement('div');
    el.className = 'tb-cell';
    var inner = document.createElement('div');
    inner.className = 'tb-cell-inner';
    el.appendChild(inner);

    var lines = String(spec.text || '').split('\n');
    for (var li = 0; li < lines.length; li++) {
        if (li > 0) inner.appendChild(document.createElement('br'));
        // Split "Blinds: <blinds>" into literal and token segments.
        var parts = lines[li].split(/(<[a-zA-Z]+>)/);
        for (var pi = 0; pi < parts.length; pi++) {
            var p = parts[pi];
            if (!p) continue;
            var m = p.match(/^<([a-zA-Z]+)>$/);
            if (m && TOKENS[m[1]]) {
                var span = document.createElement('span');
                span.setAttribute('data-tok', m[1]);
                inner.appendChild(span);
                tokSpans.push({ el: span, name: m[1] });
            } else {
                // Unknown tokens render visibly rather than vanishing — the
                // editor will rely on this to flag typos.
                inner.appendChild(document.createTextNode(m ? '⟨' + m[1] + '⟩' : p));
            }
        }
    }

    if (spec.fit) { el.classList.add('tb-fit'); fitCells.push({ el: el, inner: inner, lastText: '' }); }
    else el.style.fontSize = (spec.size || 2.4) + 'vh';
    if (spec.bold) el.style.fontWeight = '700';
    if (spec.color) el.style.color = spec.color;
    if (spec.bg) el.style.background = spec.bg;
    if (spec.pad) el.style.padding = spec.pad;
    if (spec.opacity !== undefined) el.style.opacity = String(spec.opacity);
    if (spec.spacing) el.style.letterSpacing = spec.spacing;
    if (spec.align === 'left')  { el.style.justifyContent = 'flex-start'; el.style.textAlign = 'left'; }
    if (spec.align === 'right') { el.style.justifyContent = 'flex-end';   el.style.textAlign = 'right'; }
    if (spec.when && WHEN[spec.when]) whenCells.push({ el: el, cond: WHEN[spec.when] });
    if (spec.clockColors) clockCells.push(el);
    allCells.push({ el: el, inner: inner });
    return el;
}

function buildNode(node) {
    if (node.cell) {
        var c = buildCell(node.cell);
        applyBox(c, node);
        return c;
    }
    var kids = node.row || node.col || [];
    var el = document.createElement('div');
    el.className = node.row ? 'tb-row' : 'tb-col';
    applyBox(el, node);
    for (var i = 0; i < kids.length; i++) el.appendChild(buildNode(kids[i]));
    return el;
}

function renderLayout(key) {
    var layout = LAYOUTS[key] || LAYOUTS.td_classic;
    fitCells = []; tokSpans = []; whenCells = []; clockCells = []; allCells = [];
    root.textContent = '';
    if (layout.bg) {
        root.style.background = layout.bg.gradient
            ? 'linear-gradient(160deg, ' + layout.bg.gradient[0] + ', ' + layout.bg.gradient[1] + ')'
            : (layout.bg.color || '#000');
    }
    var top = buildNode(layout.root);
    top.classList.add('tb-top');
    if (layout.root.pad) top.style.padding = layout.root.pad;
    root.appendChild(top);
    updateAll();
    requestAnimationFrame(fitAll);
}

/* ── Update loop ──────────────────────────────────────────────────────── */

function updateAll() {
    for (var i = 0; i < tokSpans.length; i++) {
        var t = tokSpans[i], v = TOKENS[t.name]();
        // prizeList is the one multiline token; rebuild its cell's line breaks.
        if (v.indexOf('\n') !== -1) {
            if (t.el.getAttribute('data-multi') !== v) {
                t.el.setAttribute('data-multi', v);
                t.el.textContent = '';
                var ls = v.split('\n');
                for (var j = 0; j < ls.length; j++) {
                    if (j > 0) t.el.appendChild(document.createElement('br'));
                    t.el.appendChild(document.createTextNode(ls[j]));
                }
            }
        } else if (t.el.textContent !== v) {
            t.el.textContent = v;
        }
    }
    for (var w = 0; w < whenCells.length; w++) {
        whenCells[w].el.style.display = whenCells[w].cond() ? '' : 'none';
    }
    // A cell whose tokens all resolved to nothing hides entirely, so an empty
    // prize bar or buy-in line doesn't paint a bare background band.
    for (var a = 0; a < allCells.length; a++) {
        var ac = allCells[a];
        if (ac.el.style.display === 'none') continue;   // 'when' already hid it
        ac.el.style.display = ac.inner.textContent.trim() === '' ? 'none' : '';
    }
    var rem = liveRemaining();
    for (var c = 0; c < clockCells.length; c++) {
        var el = clockCells[c];
        el.classList.toggle('tb-crit', rem <= 30);
        el.classList.toggle('tb-warn', rem > 30 && rem <= S.warnSecs);
        el.classList.toggle('tb-paused', !S.running && !S.sample);
    }
    // Re-fit only the fit cells whose text actually changed shape.
    for (var f = 0; f < fitCells.length; f++) {
        var fc = fitCells[f], txt = fc.inner.textContent;
        if (txt !== fc.lastText) { fc.lastText = txt; fitCell(fc); }
    }
}

/* Fit-to-box: measure at a probe size, scale to the limiting dimension. */
function fitCell(fc) {
    var boxW = fc.el.clientWidth, boxH = fc.el.clientHeight;
    if (!boxW || !boxH) return;
    fc.inner.style.fontSize = '100px';
    var w = fc.inner.scrollWidth, h = fc.inner.scrollHeight;
    if (!w || !h) return;
    var size = Math.max(8, Math.floor(100 * Math.min(boxW / w, boxH / h) * 0.92));
    fc.inner.style.fontSize = size + 'px';
}
function fitAll() { for (var i = 0; i < fitCells.length; i++) fitCell(fitCells[i]); }

function refreshDerived() {
    var cur = findLevel(S.level), nxt = findLevel(S.level + 1);
    S.isBreak = !!(cur && (cur.is_break | 0));
    S.sb = cur ? cur.small_blind : 0;
    S.bb = cur ? cur.big_blind : 0;
    S.ante = cur ? cur.ante : 0;
    S.nextIsBreak = !!(nxt && (nxt.is_break | 0));
    S.nsb = nxt ? nxt.small_blind : null;
    S.nbb = nxt ? nxt.big_blind : null;
    S.nante = nxt ? nxt.ante : null;
}

function poll() {
    if (!TB_SESSION_ID) return;
    fetch('/timer_dl.php?action=get_state&session_id=' + TB_SESSION_ID)
        .then(function (r) { return r.json(); })
        .then(function (j) {
            if (!j || !j.ok) return;
            S.level = j.timer.current_level | 0;
            S.remaining = j.timer.time_remaining_seconds | 0;
            S.running = !!(j.timer.is_running | 0);
            S.fetchedAt = Date.now();
            S.levels = j.levels || [];
            S.warnSecs = (j.sounds && j.sounds.warning_seconds) || 60;
            if (j.event_title) S.eventName = j.event_title;
            S.gameOver = j.session_status === 'finished';
            var p = j.pool;
            if (p) {
                S.players = (p.still_playing | 0) + '/' + (p.total_players | 0);
                S.entries = p.total_buyins | 0;
                S.rebuys = p.total_rebuys | 0;
                S.pot = '$' + ((p.pool_total | 0) / 100).toLocaleString('en-US');
                S.chipCount = (p.chips_in_play | 0) > 0 ? fmtChips(p.chips_in_play) : '-';
                S.avgStack = (p.chips_in_play | 0) > 0 && (p.still_playing | 0) > 0
                    ? fmtChips(Math.round(p.chips_in_play / p.still_playing)) : '-';
            }
            S.prizes = [];
            var pay = j.payouts || [], poolCents = p ? (p.pool_total | 0) : 0;
            var ord = ['1st', '2nd', '3rd'];
            for (var i = 0; i < pay.length; i++) {
                var label = pay[i].prize_label;
                if (!label && poolCents && Number(pay[i].percentage) > 0) {
                    label = '$' + Math.round(poolCents * Number(pay[i].percentage) / 100 / 100).toLocaleString('en-US');
                }
                if (label) S.prizes.push((ord[(pay[i].place | 0) - 1] || pay[i].place + 'th') + ': ' + label);
            }
            refreshDerived();
            updateAll();
        })
        .catch(function () { /* transient network errors: keep ticking on last state */ });
}

/* Sample mode loops its clock so the display always looks alive. */
function tick() {
    if (S.sample && S.running && liveRemaining() <= 0) {
        S.remaining = 1200; S.fetchedAt = Date.now();
    }
    updateAll();
}

/* ── Layout picker ────────────────────────────────────────────────────── */

var sel = document.getElementById('tbLayoutSelect');
var saved = null;
try { saved = localStorage.getItem('tb_layout'); } catch (e) {}
var params = new URLSearchParams(location.search);
var pick = params.get('layout') || saved;
if (!LAYOUTS[pick]) pick = 'td_classic';

Object.keys(LAYOUTS).forEach(function (k) {
    var o = document.createElement('option');
    o.value = k; o.textContent = LAYOUTS[k].name;
    if (k === pick) o.selected = true;
    sel.appendChild(o);
});
sel.addEventListener('change', function () {
    try { localStorage.setItem('tb_layout', sel.value); } catch (e) {}
    renderLayout(sel.value);
});

window.addEventListener('resize', function () { requestAnimationFrame(fitAll); });

refreshDerived();
renderLayout(pick);
poll();
setInterval(tick, 500);
setInterval(poll, 2000);
})();
