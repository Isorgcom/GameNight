/**
 * Timer BETA layout engine — phase A (renderer + built-in layouts).
 *
 * A layout is a JSON tree:
 *   node       := { row: [node…], …props } | { col: [node…], …props } | { cell: {…} }
 *   container  := weight (flex-grow), gap ("0.5vh"), pad, bg, align, border
 *   cell       := text        string; may contain <elements> and newlines
 *                 size        font size as % of viewport height (scales anywhere)
 *                 fit         true = fill the box instead (clock cells)
 *                 color/bg/bold/pad/align/weight/border/opacity/spacing
 *                 clockColors true = colour tracks warn/critical thresholds
 *                 when        visibility gate: a WHEN key or a condition object
 *                             {state,hasAnte,hasRebuys,round}; false = cell hidden
 *                 variants    [{ when, text?, color?, bg?, bold?, opacity? }] —
 *                             conditional emphasis, first match wins over base
 *                             (conditional per-cell styling). Structural props
 *                             (size/align/weight) stay base-only.
 *
 * A condition (in `when` or a variant) is a WHEN key string, or an object whose
 * clauses AND together: state (running|paused|on_break|pre_game|game_over),
 * hasAnte, hasRebuys, round (">3","even","all",…).
 *
 * A screen may also carry `cycle` (seconds): when the first matching screen
 * has one, the display rotates through every matching screen that also has
 * one, each shown for its own duration. Screens without `cycle` keep the
 * first-match-wins rule, so a Break screen ordered first still takes over.
 *
 * A layout may carry `styles` — a name → {visual props} map. A cell opts in
 * with style:"name" and inherits size/fit/color/bg/bold/pad/align/opacity/
 * spacing from it; the cell's own props win over the shared ones. Text,
 * when, variants and images never come from a shared style.
 *
 * The tree renders to nested flexbox, so nothing can overlap or leave the
 * screen — the property the absolutely-positioned theme model could not give.
 * Cell text is authored data: every string lands via textContent, elements become
 * <span data-el>, and nothing is ever innerHTML'd. Keep it that way; the
 * editor phase will feed user input straight through this path.
 *
 * Four built-in layouts ship as starting points: Classic (three-column),
 * Black & Green (banded), Minimalist, and Two Column.
 */
(function () {
'use strict';

/* ── Built-in layouts ─────────────────────────────────────────────────── */

var LAYOUTS = {

    classic: {
        name: 'Classic',
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
                    { cell: { text: '<clock>', fit: true, bold: true, color: '#fff', clockColors: true, variants: [
                        { when: 'game_over', text: 'GAME OVER', color: '#ef4444' },
                        { when: 'on_break',  color: '#fbbf24' }
                    ] }, weight: 5 },
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

/* ── Live state, normalised for elements ────────────────────────────────── */

var S = {
    eventName: TB_EVENT_TITLE || 'Tournament Timer',
    level: 1, remaining: 900, running: false, isBreak: false, gameOver: false,
    fetchedAt: Date.now(),
    levels: [], sb: 0, bb: 0, ante: 0, nsb: null, nbb: null, nante: null, nextIsBreak: false,
    players: '-', entries: '-', rebuys: 0, pot: '-', chipCount: '-', avgStack: '-',
    stillNum: 0, totalNum: 0, chipsNum: 0,
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
    S.stillNum = 12; S.totalNum = 18; S.chipsNum = 67500;
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

/* ── Element registry — add an element, get it everywhere ─────────────── */

var ELEMENTS = {
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
    prizeList:    function () { return S.prizes.length ? 'Prizes\n' + S.prizes.join('\n') : ''; },
    playersLeft:  function () { return String(S.stillNum); },
    playersTotal: function () { return String(S.totalNum); },
    // Average stack in big blinds — the poker-meaningful health number a
    // a poker display shows. Falls back to '-' before chips are counted.
    avgStackBB:   function () {
        if (S.chipsNum > 0 && S.stillNum > 0 && S.bb > 0) return Math.round((S.chipsNum / S.stillNum) / S.bb) + ' BB';
        return '-';
    },
    // "Level 5" or "Break" — a single label that follows the break state, so a
    // layout needs one cell instead of a level cell plus a break variant.
    levelOrBreak: function () { return S.isBreak ? 'Break' : 'Level ' + S.level; }
};

// State predicates. `always` is the implicit default; the rest map a tournament
// state to a boolean; these are the condition clauses.
var WHEN = {
    always:     function () { return true; },
    running:    function () { return S.running && !S.isBreak && !S.gameOver; },
    paused:     function () { return !S.running && !S.gameOver; },
    on_break:   function () { return S.isBreak; },
    pre_game:   function () { return S.level <= 1 && !S.running && !S.gameOver; },
    has_ante:   function () { return (S.ante | 0) > 0; },
    has_rebuys: function () { return (S.rebuys | 0) > 0; },
    game_over:  function () { return S.gameOver; }
};

// Numeric comparison for a round clause: ">3", "<=5", "=1", "even", "odd", "all".
function roundMatches(spec) {
    if (!spec || spec === 'all') return true;
    if (spec === 'even') return (S.level % 2) === 0;
    if (spec === 'odd')  return (S.level % 2) === 1;
    var m = String(spec).match(/^(<=|>=|<|>|=|!=)?\s*(\d+)$/);
    if (!m) return true;
    var op = m[1] || '=', n = parseInt(m[2], 10), v = S.level;
    switch (op) {
        case '<':  return v < n;   case '<=': return v <= n;
        case '>':  return v > n;   case '>=': return v >= n;
        case '!=': return v !== n; default:   return v === n;
    }
}

// A condition is a WHEN key (string shorthand) or an object of clauses that AND
// together: { state, hasAnte, hasRebuys, round }. Empty / missing = always.
function matchCond(cond) {
    if (cond === undefined || cond === null || cond === '' || cond === 'always') return true;
    if (typeof cond === 'string') return WHEN[cond] ? WHEN[cond]() : true;
    if (cond.state && WHEN[cond.state] && !WHEN[cond.state]()) return false;
    if (cond.hasAnte === true  && (S.ante | 0) <= 0) return false;
    if (cond.hasAnte === false && (S.ante | 0) >  0) return false;
    if (cond.hasRebuys === true  && (S.rebuys | 0) <= 0) return false;
    if (cond.hasRebuys === false && (S.rebuys | 0) >  0) return false;
    if (cond.round && !roundMatches(cond.round)) return false;
    return true;
}

/* ── Renderer: JSON tree → nested flexbox ─────────────────────────────── */

var root = document.getElementById('tbRoot');
var allCells = [];   // { el, inner, spec, variants, elSpans, lastText, lastVariant, isFit }
var fitCells = [];   // subset of allCells with fit:true
var clockCells = []; // cells whose colour tracks warn/critical

function applyBox(el, node) {
    if (node.weight !== undefined) { el.style.flexGrow = String(node.weight); el.style.flexBasis = '0'; }
    if (node.gap) el.style.gap = node.gap;
    if (node.pad) el.style.padding = node.pad;
    if (node.bg) el.style.background = node.bg;
    if (node.border) el.style.border = node.border;
    if (node.justify) el.style.justifyContent = node.justify;
}

// Build the element/text spans for one line of cell content into `inner`,
// appending any {el,name} element spans it creates to `elList`.
// Layout-defined custom elements: a flat name→plain-text map applied to every
// screen. Resolved after the built-ins, so a built-in name always wins.
var customEls = {};
function isKnownElement(name) { return !!ELEMENTS[name] || customEls.hasOwnProperty(name); }
function elementValue(name) {
    if (ELEMENTS[name]) return String(ELEMENTS[name]());
    if (customEls.hasOwnProperty(name)) return String(customEls[name]);
    return null;
}

function buildInner(inner, text, elList) {
    var lines = String(text || '').split('\n');
    for (var li = 0; li < lines.length; li++) {
        if (li > 0) inner.appendChild(document.createElement('br'));
        var parts = lines[li].split(/(<[a-zA-Z][a-zA-Z0-9]*>)/);
        for (var pi = 0; pi < parts.length; pi++) {
            var p = parts[pi];
            if (!p) continue;
            var m = p.match(/^<([a-zA-Z][a-zA-Z0-9]*)>$/);
            if (m && isKnownElement(m[1])) {
                var span = document.createElement('span');
                span.setAttribute('data-el', m[1]);
                inner.appendChild(span);
                elList.push({ el: span, name: m[1] });
            } else {
                // Unknown elements render visibly (⟨name⟩) rather than vanishing —
                // the editor relies on this to flag typos.
                inner.appendChild(document.createTextNode(m ? '⟨' + m[1] + '⟩' : p));
            }
        }
    }
}

// Only these props may differ between a cell's base and its conditional
// variants; structural props (size/align/weight) stay base-only so a variant
// can never reflow the layout, only re-emphasise it.
var VARIANT_PROPS = ['text', 'color', 'bg', 'bold', 'opacity'];

// Apply the emphasis props of `spec` to the element, clearing any the spec
// omits so a variant switching off leaves no residue.
function applyEmphasis(el, spec) {
    el.style.color = spec.color || '';
    el.style.background = spec.bg || '';
    el.style.fontWeight = spec.bold ? '700' : '';
    el.style.opacity = spec.opacity !== undefined ? String(spec.opacity) : '';
}

// Only same-origin timer-layout upload paths are allowed as image sources;
// anything else (external URL, another feature's upload, javascript:, …) is
// dropped. The server sanitizer enforces the same rule.
function safeImageSrc(v) {
    return (typeof v === 'string' && /^\/uploads\/timer_layouts\/[A-Za-z0-9._-]{1,120}$/.test(v)) ? v : null;
}

function buildCell(spec) {
    var el = document.createElement('div');
    el.className = 'tb-cell';
    var inner = document.createElement('div');
    inner.className = 'tb-cell-inner';
    el.appendChild(inner);

    // Image cell: an <img> filling the box. src is validated; never innerHTML.
    var imgSrc = safeImageSrc(spec.image);
    if (imgSrc) {
        el.classList.add('tb-cell-img');
        var img = document.createElement('img');
        img.src = imgSrc;
        img.alt = '';
        img.className = 'tb-img' + (spec.imageFit === 'cover' ? ' tb-img-cover' : '');
        inner.appendChild(img);
    }

    // Structural / base-only styling, set once.
    if (spec.fit) el.classList.add('tb-fit');
    else el.style.fontSize = (spec.size || 2.4) + 'vh';
    if (spec.pad) el.style.padding = spec.pad;
    if (spec.spacing) el.style.letterSpacing = spec.spacing;
    if (spec.align === 'left')  { el.style.justifyContent = 'flex-start'; el.style.textAlign = 'left'; }
    if (spec.align === 'right') { el.style.justifyContent = 'flex-end';   el.style.textAlign = 'right'; }
    if (spec.clockColors) clockCells.push(el);

    var rec = {
        el: el, inner: inner, spec: spec, isImage: !!imgSrc,
        variants: Array.isArray(spec.variants) ? spec.variants : [],
        elSpans: [], lastText: null, lastVariant: -2, isFit: !!spec.fit
    };
    allCells.push(rec);
    if (spec.fit) fitCells.push(rec);
    return el;
}

// Shared named styles: layout-level name → visual-prop map; cells opt in via
// style:"name". Merge order: shared props under the cell's own (cell wins).
// Only visual props transfer — text/when/variants/image stay per-cell.
var sharedStyles = {};
var SHARED_KEYS = ['size', 'fit', 'color', 'bg', 'bold', 'pad', 'align', 'opacity', 'spacing'];

function withSharedStyle(spec) {
    var st = (spec && typeof spec.style === 'string') ? sharedStyles[spec.style] : null;
    if (!st || typeof st !== 'object') return spec;
    var merged = {};
    for (var i = 0; i < SHARED_KEYS.length; i++) {
        if (st[SHARED_KEYS[i]] !== undefined) merged[SHARED_KEYS[i]] = st[SHARED_KEYS[i]];
    }
    for (var k in spec) if (Object.prototype.hasOwnProperty.call(spec, k)) merged[k] = spec[k];
    return merged;
}

function buildNode(node, path) {
    var el;
    if (node.cell) {
        el = buildCell(withSharedStyle(node.cell));
        applyBox(el, node);
    } else {
        var kids = node.row || node.col || [];
        el = document.createElement('div');
        el.className = node.row ? 'tb-row' : 'tb-col';
        applyBox(el, node);
        for (var i = 0; i < kids.length; i++) el.appendChild(buildNode(kids[i], path.concat(i)));
    }
    // The node's address in the layout tree ("2.0.1"): the editor selects,
    // highlights and edits through these.
    el.setAttribute('data-path', path.join('.'));
    return el;
}

var CURRENT_LAYOUT = null;   // normalized {screens:[...]}
var activeScreen = -1;       // index currently built into the DOM
var forceScreen = null;      // editor pins a screen; null = auto by condition
var _building = false;       // guards against updateAll re-entrancy while building

// A layout is either single-screen ({bg, root}) or multi-screen
// ({screens:[{name, when, bg, root}]}). Normalize to the screens form so the
// renderer only deals with one shape. Screens are scanned in order; the first
// whose condition matches wins, and a screen with no `when` is the default.
function normalizeScreens(layout) {
    if (layout && Array.isArray(layout.screens) && layout.screens.length) return layout;
    // customElements / styles are layout-level; carry them across the wrap.
    return {
        screens: [{ name: 'Main', bg: layout ? layout.bg : null, root: layout ? layout.root : { col: [] } }],
        customElements: layout ? layout.customElements : undefined,
        styles: layout ? layout.styles : undefined
    };
}

// Rotation state for `cycle` screens. Session-only; reset on layout load.
var _cycScreen = -1;   // screen index the rotation is currently showing
var _cycSince = 0;     // when it was entered (ms epoch)

function pickScreen() {
    var scr = CURRENT_LAYOUT.screens;
    if (forceScreen !== null && scr[forceScreen]) return forceScreen;
    var matching = [];
    for (var i = 0; i < scr.length; i++) {
        if (scr[i].when === undefined || scr[i].when === null || matchCond(scr[i].when)) matching.push(i);
    }
    if (!matching.length) return scr.length - 1;
    var first = matching[0];
    // Cycling: only when the screen that WOULD win opts in. That keeps a
    // no-cycle screen ordered first (Break) absolute: it interrupts any
    // rotation for as long as its condition matches.
    if (!(scr[first].cycle > 0)) { _cycScreen = -1; return first; }
    var set = matching.filter(function (ix) { return scr[ix].cycle > 0; });
    if (set.length < 2) { _cycScreen = -1; return first; }
    var now = Date.now();
    var pos = set.indexOf(_cycScreen);
    if (pos === -1) { _cycScreen = set[0]; _cycSince = now; }           // (re)enter the rotation
    else if (now - _cycSince >= scr[_cycScreen].cycle * 1000) {
        _cycScreen = set[(pos + 1) % set.length];                       // dwell over → next
        _cycSince = now;
    }
    return _cycScreen;
}

function buildScreen(idx) {
    _building = true;
    activeScreen = idx;
    customEls = (CURRENT_LAYOUT && CURRENT_LAYOUT.customElements && typeof CURRENT_LAYOUT.customElements === 'object')
        ? CURRENT_LAYOUT.customElements : {};
    sharedStyles = (CURRENT_LAYOUT && CURRENT_LAYOUT.styles && typeof CURRENT_LAYOUT.styles === 'object')
        ? CURRENT_LAYOUT.styles : {};
    var screen = CURRENT_LAYOUT.screens[idx] || { root: { col: [] } };
    fitCells = []; clockCells = []; allCells = [];
    root.textContent = '';
    root.style.background = '';
    root.style.backgroundImage = '';
    if (screen.bg) {
        root.style.background = screen.bg.gradient
            ? 'linear-gradient(160deg, ' + screen.bg.gradient[0] + ', ' + screen.bg.gradient[1] + ')'
            : (screen.bg.color || '#000');
        // Background image sits over the colour/gradient (validated same-origin).
        var bgImg = safeImageSrc(screen.bg.image);
        if (bgImg) {
            root.style.backgroundImage = 'url("' + bgImg + '")';
            root.style.backgroundSize = screen.bg.imageFit === 'contain' ? 'contain' : 'cover';
            root.style.backgroundPosition = 'center';
            root.style.backgroundRepeat = 'no-repeat';
        }
    }
    var top = buildNode(screen.root || { col: [] }, []);
    top.classList.add('tb-top');
    if (screen.root && screen.root.pad) top.style.padding = screen.root.pad;
    root.appendChild(top);
    _building = false;
    updateAll();
    requestAnimationFrame(fitAll);
}

function renderLayoutObj(layout) {
    CURRENT_LAYOUT = normalizeScreens(layout);
    _cycScreen = -1;   // new layout, new screen indices — restart any rotation
    buildScreen(pickScreen());
}

function renderLayout(key) {
    renderLayoutObj(LAYOUTS[key] || LAYOUTS.classic);
}

/* ── Update loop ──────────────────────────────────────────────────────── */

// Refresh one element span in place; multiline ones (prizeList) rebuild breaks.
function paintElSpan(t) {
    var v = elementValue(t.name);
    if (v === null) v = '⟨' + t.name + '⟩';
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

// The active spec for a cell right now: its base, with the first matching
// variant's emphasis props merged over it. Variant index -1 means base only.
function resolveCell(rec) {
    for (var i = 0; i < rec.variants.length; i++) {
        if (matchCond(rec.variants[i].when)) return i;
    }
    return -1;
}

function updateAll() {
    if (typeof syncControls === 'function') syncControls();
    // State may have moved us to a different screen (e.g. onto the break
    // screen). Rebuild for it, unless we're mid-build ourselves.
    if (!_building && CURRENT_LAYOUT && pickScreen() !== activeScreen) {
        buildScreen(pickScreen());
        return;
    }
    for (var a = 0; a < allCells.length; a++) {
        var rec = allCells[a];

        // A cell's `when` gates visibility outright (no matching style → hidden).
        if (rec.spec.when !== undefined && !matchCond(rec.spec.when)) {
            rec.el.style.display = 'none';
            continue;
        }

        // Pick the active variant; only touch the DOM when it changed.
        var vi = rec.variants.length ? resolveCell(rec) : -1;
        var active = vi >= 0 ? rec.variants[vi] : rec.spec;
        if (vi !== rec.lastVariant) {
            rec.lastVariant = vi;
            // Emphasis: variant value if the variant sets it, else base value.
            var eff = {};
            for (var k = 0; k < VARIANT_PROPS.length; k++) {
                var key = VARIANT_PROPS[k];
                eff[key] = (vi >= 0 && active[key] !== undefined) ? active[key] : rec.spec[key];
            }
            applyEmphasis(rec.el, eff);
            // Text is a variant prop too, so rebuild the inner when it changes —
            // but never for an image cell (that would wipe its <img>).
            var newText = (vi >= 0 && active.text !== undefined) ? active.text : rec.spec.text;
            if (!rec.isImage && newText !== rec.lastText) {
                rec.lastText = newText;
                rec.inner.textContent = '';
                rec.elSpans = [];
                buildInner(rec.inner, newText, rec.elSpans);
            }
        }

        // Live element values.
        for (var t = 0; t < rec.elSpans.length; t++) paintElSpan(rec.elSpans[t]);

        // A cell whose content resolved to nothing hides entirely, so an empty
        // prize bar or buy-in line paints no bare background band. Image cells
        // are exempt — they have no text content but should still show.
        rec.el.style.display = (!rec.isImage && rec.inner.textContent.trim() === '') ? 'none' : '';
    }

    var rem = liveRemaining();
    for (var c = 0; c < clockCells.length; c++) {
        var el = clockCells[c];
        el.classList.toggle('tb-crit', rem <= 30);
        el.classList.toggle('tb-warn', rem > 30 && rem <= S.warnSecs);
        el.classList.toggle('tb-paused', !S.running && !S.sample);
    }

    // Re-fit only the fit cells whose rendered text actually changed shape.
    for (var f = 0; f < fitCells.length; f++) {
        var fc = fitCells[f], txt = fc.inner.textContent;
        if (txt !== fc.fitLastText) { fc.fitLastText = txt; fitCell(fc); }
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
                S.stillNum = p.still_playing | 0; S.totalNum = p.total_players | 0; S.chipsNum = p.chips_in_play | 0;
                S.players = S.stillNum + '/' + S.totalNum;
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
            // Control: whether this viewer may drive the timer, and the CSRF
            // token to do it. The server re-checks manage rights on every
            // command regardless, so these only decide what the client offers.
            CAN_CONTROL = !!j.can_control;
            if (j.csrf_token) CSRF = j.csrf_token;
            refreshDerived();
            updateAll();
        })
        .catch(function () { /* transient network errors: keep ticking on last state */ });
}

// Whether keyboard control is live: a real event session, the viewer can
// control it, we have a CSRF token, and we are not the editor's preview iframe.
var CAN_CONTROL = false;
var CSRF = null;
function controlArmed() {
    return !window.TB_EMBED && !!TB_SESSION_ID && CAN_CONTROL && !!CSRF;
}
function sendCommand(cmd) {
    if (!controlArmed()) return;
    var body = new URLSearchParams();
    body.set('action', 'command');
    body.set('cmd', cmd);
    body.set('session_id', String(TB_SESSION_ID));
    body.set('csrf_token', CSRF);
    fetch('/timer_dl.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: String(body) })
        .then(function (r) { return r.json(); })
        .then(function (j) { if (j && j.csrf_token) CSRF = j.csrf_token; poll(); })   // reflect the new state at once
        .catch(function () {});
}

// On-screen control tray. It only exists in the DOM on an event-linked, non
// -embed page; syncControls() (called from updateAll) reveals it once
// get_state confirms the viewer can control, and keeps the play/stop button in
// sync with the running state.
var ctrls = document.getElementById('tbControls');
if (ctrls) {
    ctrls.addEventListener('click', function (e) {
        var btn = e.target.closest('button');
        if (!btn) return;
        if (btn.getAttribute('data-act') === 'fullscreen') {
            var fe = document.documentElement;
            if (document.fullscreenElement) document.exitFullscreen();
            else if (fe.requestFullscreen) fe.requestFullscreen();
            else if (fe.webkitRequestFullscreen) fe.webkitRequestFullscreen();
            return;
        }
        var cmd = btn.getAttribute('data-cmd');
        if (cmd) sendCommand(cmd);
    });
}
// Auto-hide: the tray is solid while active, then fades fully out after a few
// seconds of no interaction. Any pointer move, tap or key brings it back.
var idleTimer = null;
function showControls() {
    if (!ctrls || ctrls.hidden) return;
    ctrls.classList.remove('tb-idle');
    if (idleTimer) clearTimeout(idleTimer);
    idleTimer = setTimeout(function () { if (ctrls) ctrls.classList.add('tb-idle'); }, 3000);
}
if (ctrls) {
    ['mousemove', 'mousedown', 'touchstart', 'keydown'].forEach(function (ev) {
        document.addEventListener(ev, showControls, { passive: true });
    });
}

function syncControls() {
    if (!ctrls) return;
    var armed = controlArmed();
    var wasHidden = ctrls.hidden;
    ctrls.hidden = !armed;
    // First time it becomes available, reveal it and start the idle countdown.
    if (armed && wasHidden) showControls();
    if (!armed && idleTimer) { clearTimeout(idleTimer); idleTimer = null; }
    var play = document.getElementById('tbPlayBtn');
    if (play) {
        play.classList.toggle('is-running', S.running);
        play.innerHTML = S.running ? '&#9208;' : '&#9654;';   // ⏸ / ▶
    }
}

/* Sample mode loops its clock so the display always looks alive. */
function tick() {
    if (S.sample && S.running && liveRemaining() <= 0) {
        S.remaining = 1200; S.fetchedAt = Date.now();
    }
    updateAll();
}

/* ── Embed mode: the editor drives this page inside an iframe ─────────── */

if (window.TB_EMBED) {
    window.TBPreview = {
        builtins:  LAYOUTS,
        setLayout: function (layoutObj) { renderLayoutObj(layoutObj); },
        setState:  function (partial) { Object.assign(S, partial); refreshDerived(); updateAll(); },
        refresh:   function () { updateAll(); requestAnimationFrame(fitAll); },
        // Editor pins the screen it is editing so the preview shows it
        // regardless of the sample state; null returns to auto-by-condition.
        forceScreen: function (i) { forceScreen = (i === null || i === undefined) ? null : i; if (CURRENT_LAYOUT) buildScreen(pickScreen()); },
        activeScreenIndex: function () { return activeScreen; },
        elementNames: function () { return Object.keys(ELEMENTS).concat(Object.keys(customEls)); },
        // Current value of every element, for the editor's picker (so it can show
        // "<clock> — 12:31" and never drift from the renderer's real list).
        elementValues: function () {
            var out = {};
            Object.keys(ELEMENTS).forEach(function (n) { try { out[n] = String(ELEMENTS[n]()); } catch (e) { out[n] = ''; } });
            Object.keys(customEls).forEach(function (n) { out[n] = String(customEls[n]); });
            return out;
        },
        select:    function (pathStr) {
            document.querySelectorAll('.tb-selected').forEach(function (n) { n.classList.remove('tb-selected'); });
            if (pathStr === null || pathStr === undefined) return;
            var el = document.querySelector('[data-path="' + pathStr + '"]');
            if (el) el.classList.add('tb-selected');
        },
        onSelect: null
    };
    document.addEventListener('click', function (e) {
        var el = e.target.closest ? e.target.closest('[data-path]') : null;
        if (!el || !window.TBPreview.onSelect) return;
        e.preventDefault();
        window.TBPreview.onSelect(el.getAttribute('data-path'));
    }, true);
    window.addEventListener('resize', function () { requestAnimationFrame(fitAll); });
    refreshDerived();
    renderLayout('classic');
    setInterval(tick, 500);
} else {

/* ── Layout picker (display mode) ─────────────────────────────────────── */

var sel = document.getElementById('tbLayoutSelect');
var saved = null;
try { saved = localStorage.getItem('tb_layout'); } catch (e) {}
var params = new URLSearchParams(location.search);
var pick = params.get('layout') || saved;

Object.keys(LAYOUTS).forEach(function (k) {
    var o = document.createElement('option');
    o.value = k; o.textContent = LAYOUTS[k].name;
    sel.appendChild(o);
});

// Saved layouts load after the built-ins; the picker groups them.
fetch('/timer_beta_dl.php?action=get_layouts')
    .then(function (r) { return r.json(); })
    .then(function (j) {
        if (!j || !j.ok || !j.layouts.length) return;
        var grp = document.createElement('optgroup');
        grp.label = 'Saved';
        j.layouts.forEach(function (L) {
            var o = document.createElement('option');
            o.value = 'id:' + L.id;
            o.textContent = L.name + (L.is_global ? ' (site)' : (L.league_name ? ' (' + L.league_name + ')' : ''));
            grp.appendChild(o);
        });
        sel.appendChild(grp);
        if (/^id:\d+$/.test(pick || '')) { sel.value = pick; if (sel.value === pick) applyPick(pick); }
    })
    .catch(function () {});

function applyPick(v) {
    if (/^id:\d+$/.test(v)) {
        fetch('/timer_beta_dl.php?action=get_layout&id=' + v.slice(3))
            .then(function (r) { return r.json(); })
            .then(function (j) { if (j && j.ok && j.layout && j.layout.root) renderLayoutObj(j.layout); })
            .catch(function () {});
    } else {
        renderLayout(v);
    }
}

if (!/^id:\d+$/.test(pick || '') && !LAYOUTS[pick]) pick = 'classic';
if (LAYOUTS[pick]) sel.value = pick;
sel.addEventListener('change', function () {
    try { localStorage.setItem('tb_layout', sel.value); } catch (e) {}
    applyPick(sel.value);
});

window.addEventListener('resize', function () { requestAnimationFrame(fitAll); });

// Keyboard control on a live, event-linked display: Space start/stop, Left/Right
// previous/next level. Only reaches the wire when controlArmed() (a session,
// the viewer can control it, a CSRF token, not the editor preview); the server
// re-checks manage rights on every command. Suppressed while typing.
document.addEventListener('keydown', function (e) {
    if (e.target.closest && e.target.closest('input, textarea, select, [contenteditable]')) return;
    if (!controlArmed()) return;
    if (e.code === 'Space') { e.preventDefault(); sendCommand('toggle_play'); }
    else if (e.code === 'ArrowRight') { e.preventDefault(); sendCommand('skip_next'); }
    else if (e.code === 'ArrowLeft')  { e.preventDefault(); sendCommand('skip_prev'); }
});

refreshDerived();
applyPick(LAYOUTS[pick] ? pick : 'classic');
poll();
setInterval(tick, 500);
setInterval(poll, 2000);
}
})();
