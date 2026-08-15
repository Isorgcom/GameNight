/**
 * Timer BETA layout editor. The working layout lives here as a plain object;
 * every mutation re-renders the iframe preview (the real renderer) and the
 * structure tree. Server-side sanitation happens on save; this file trusts
 * nothing it loads either (loaded layouts pass through the same renderer that
 * only ever assigns text via textContent).
 *
 * Deliberately uses direct listeners, not data-act: the page includes
 * _footer.php, so pk-dispatch.js is active, and a second declarative layer
 * here would risk exactly the double-dispatch class SECURITY.md documents.
 */
(function () {
'use strict';

var frame = document.getElementById('tbeFrame');
var PV = null;                 // iframe's TBPreview once loaded
var LAYOUT = null;             // working copy
var layoutId = null;           // DB id when editing a saved layout
var editable = true;           // false = loaded someone else's; save forces copy
var selPath = null;            // "0.2.1" or '' for root, null for nothing
var undoStack = [];
var editScreenIndex = 0;       // which screen the tree/inspector edit

// Normalize a loaded layout to the multi-screen form so the editor always
// edits screens[]. A single-screen {bg,root} becomes one "Main" screen.
function normalizeLayout() {
    if (!LAYOUT) return;
    if (!Array.isArray(LAYOUT.screens) || !LAYOUT.screens.length) {
        LAYOUT = { v: LAYOUT.v || 1, aspect: LAYOUT.aspect,
                   screens: [{ name: 'Main', bg: LAYOUT.bg || null, root: LAYOUT.root || { col: [] } }] };
        if (!LAYOUT.aspect) delete LAYOUT.aspect;
    }
    if (editScreenIndex >= LAYOUT.screens.length) editScreenIndex = 0;
}
function curScreen() { return LAYOUT.screens[editScreenIndex]; }

// Open on the CATCH-ALL screen, not screen 0. Conditional screens (Break) sort
// first so the engine tests them before the default — which put every
// multi-screen layout's editor session on the break screen, where cells that
// only exist on Main (the QR, the chips bar) simply are not there to see.
function mainScreenIndex() {
    for (var i = 0; i < (LAYOUT.screens || []).length; i++) {
        if (!LAYOUT.screens[i].when) return i;
    }
    return 0;
}

var ELEMENT_BUILTINS = [];
// Fallback only: replaced at boot by PV.elementNames(), which is the renderer's
// own list. Kept in step so the picker is never empty on a slow frame.
var ELEMENTS = ['eventName','level','levelOrBreak','clock','gameName','nextGameName','smallBlind','bigBlind','ante',
    'blinds','nextBlinds','nextSmallBlind','nextBigBlind','nextAnte','players','playersLeft','playersTotal','entries',
    'rebuys','buyIns','addOns','eliminated','cashedOut','pot','prizePool','bountyPool','jackpotPool',
    'buyInFee','rebuyFee','addOnFee','startChips','addOnChips','tables','seats','startTime',
    'chipCount','avgStack','avgStackBB','currentTime','elapsedTime','nextBreak','roundsToBreak','roundsTotal',
    'prizes','prizeList','prizesStacked','buyinLine'];

/* ── What everything means ─────────────────────────────────────────────────
 * One map, three uses: the "?" reference panel, tooltips in the element
 * picker, and tooltips on tree nodes. An element missing here still works —
 * it just shows without a blurb — so adding an element does not REQUIRE
 * documentation, it only rewards it. */
var ELEMENT_DESC = {
    eventName: "The event's title",
    gameName: "The game being played (No Limit Texas Hold 'Em)",
    nextGameName: 'What the next round is, or "Break"',
    level: "Current round number",
    levelOrBreak: '"Level 5", or "Break" during one — one cell for both',
    clock: "The countdown for this round",
    elapsedTime: "How long the game has been running",
    currentTime: "Wall-clock time",
    startTime: "When the event is scheduled to start",
    nextBreak: "Time until the next break",
    roundsToBreak: 'ROUNDS until the next break (nextBreak answers "how long")',
    roundsTotal: "How many rounds the schedule holds, breaks excluded",
    smallBlind: "Current small blind", bigBlind: "Current big blind", ante: 'Current ante, "-" when none',
    blinds: 'Small / big as one line ("75 / 150")',
    nextBlinds: "The next level's blinds, ante included when it has one",
    nextSmallBlind: "Next level's small blind alone", nextBigBlind: "Next level's big blind alone",
    nextAnte: "Next level's ante alone",
    players: 'Still in / total, as "12/18"',
    playersLeft: "How many are still in", playersTotal: "How many played",
    entries: "Buy-ins including rebuys", buyIns: "First buy-ins only",
    rebuys: "Rebuys taken", addOns: "Add-ons taken",
    eliminated: "Players knocked out", cashedOut: "Players who cashed out",
    lastEliminated: "Name of the most recent player knocked out",
    lastEliminatedPlace: "Their finishing place (13th, 12th, \u2026)",
    pot: "The prize pool, as money", prizePool: "Same figure as pot, named the way a screen labels it",
    bountyPool: "Bounty money collected", jackpotPool: "Jackpot entries collected",
    buyInFee: "What a buy-in costs", rebuyFee: "What a rebuy costs", addOnFee: "What an add-on costs",
    buyinLine: "The buy-in and rebuy prices as one line",
    prizes: 'Payouts on one line ("1st: $525  2nd: ...")',
    prizeList: 'Payouts stacked, with a "Prizes" heading',
    prizesStacked: "Payouts stacked, NO heading — for a cell that writes its own",
    chipCount: "Total chips in play", avgStack: "Average stack",
    avgStackBB: "Average stack in big blinds — the health number",
    startChips: "Chips a buy-in gets", addOnChips: "Chips an add-on gets",
    tables: "Tables in the room", seats: "Seats per table"
};
// The namespaced spellings share the flat descriptions — one map (mirrors
// ELEMENT_NS in timer_beta.js), so both spellings tooltip identically.
(function () {
    var ns = {
        'event.name': 'eventName',
        'game.name': 'gameName', 'game.next': 'nextGameName',
        'round.num': 'level', 'round.orBreak': 'levelOrBreak',
        'round.total': 'roundsTotal', 'round.toBreak': 'roundsToBreak',
        'time.now': 'currentTime', 'time.elapsed': 'elapsedTime',
        'time.nextBreak': 'nextBreak', 'time.start': 'startTime',
        'blinds.small': 'smallBlind', 'blinds.big': 'bigBlind', 'blinds.ante': 'ante',
        'blinds.now': 'blinds', 'blinds.next': 'nextBlinds',
        'blinds.nextSmall': 'nextSmallBlind', 'blinds.nextBig': 'nextBigBlind', 'blinds.nextAnte': 'nextAnte',
        'players.line': 'players', 'players.left': 'playersLeft', 'players.total': 'playersTotal',
        'players.entries': 'entries', 'players.buyIns': 'buyIns', 'players.rebuys': 'rebuys',
        'players.addOns': 'addOns', 'players.out': 'eliminated', 'players.cashed': 'cashedOut',
        'players.lastOut': 'lastEliminated', 'players.lastOutPlace': 'lastEliminatedPlace',
        'chips.total': 'chipCount', 'chips.avg': 'avgStack', 'chips.avgBB': 'avgStackBB',
        'chips.start': 'startChips', 'chips.addOn': 'addOnChips',
        'money.pot': 'prizePool', 'money.bounty': 'bountyPool', 'money.jackpot': 'jackpotPool',
        'money.buyIn': 'buyInFee', 'money.rebuy': 'rebuyFee', 'money.addOn': 'addOnFee',
        'money.line': 'buyinLine',
        'prizes.line': 'prizes', 'prizes.list': 'prizeList', 'prizes.stacked': 'prizesStacked',
        'table.count': 'tables', 'table.seats': 'seats'
    };
    Object.keys(ns).forEach(function (d) {
        if (ELEMENT_DESC[ns[d]]) ELEMENT_DESC[d] = ELEMENT_DESC[ns[d]];
    });
})();

var STRUCTURE_DESC = [
    ["Screen", "One full display. A layout can hold several; conditions decide which one shows (Break screens go before the catch-all Main)."],
    ["Row / Column", "Boxes that split space. A row lays children side by side, a column stacks them. Drag borders in the preview to resize, drag boxes to move."],
    ["Cell", "One box of content: text with live <elements> in it, an image, a QR code, a chip legend, or the final-table seat map."],
    ["Weight", "A box's share of its parent's space. No weight = hug the content. The number the resize handles write."],
    ["Size", "Text size as % of screen height — a MAXIMUM: values that outgrow the box wrap or shrink inside it, never spill."],
    ["Show when", "A condition that hides the box until it matches: a state (on break), or an expression like blinds.big > 10000."],
    ["Variants", "Alternate look or text for one cell behind a condition. First match wins; no match shows the base."],
    ["Element styles", "One element styled apart from its line — <ante> bold and orange while the blinds stay white."],
    ["Box image", "The box's own background picture: plate art that moves and resizes WITH the box."],
    ["Shared style", "A named bundle of colour/size/bold that many cells reference; change it once, every user follows."],
    ["Screen background", "The backdrop: a colour or full-screen picture. Screen shape locks its proportions; Panel colours toggles painted boxes vs artwork."],
    ["Copy / Cut / Paste", "Right-click any box (or Ctrl+C / X / V with one selected). The clipboard survives screen switches and layout loads, so a cell can move between designs."],
    ["Triggers", "Actions that fire ONCE each time a condition BECOMES true: play a sound, show a screen for a few seconds, flash, or speak a line. levelChange and secondsLeft <= 60 and running are the classic ones."],
    ["Trigger sounds", "preset: tones are built in and travel everywhere. Uploaded sounds live in your library, embed in exports, and re-upload on import. QR-scanned screens start muted; the speaker button turns each screen on."]
];

/* ── Tree access helpers ─────────────────────────────────────────────── */

function nodeAt(path) {
    if (path === '' || path === null) return { row: null, col: null, wrap: { col: [curScreen().root] }, isRoot: true, node: curScreen().root };
    var parts = path.split('.').map(Number);
    var n = curScreen().root;
    for (var i = 0; i < parts.length; i++) n = (n.row || n.col)[parts[i]];
    return { node: n, isRoot: false };
}
function parentOf(path) {
    if (!path) return null;
    var parts = path.split('.').map(Number);
    var idx = parts.pop();
    var p = parts.length ? nodeAt(parts.join('.')).node : curScreen().root;
    return { parent: p, list: p.row || p.col, index: idx };
}
function kindOf(n) { return n.cell ? 'cell' : (n.row ? 'row' : 'col'); }

function pushUndo() {
    undoStack.push(JSON.stringify(LAYOUT));
    if (undoStack.length > 50) undoStack.shift();
    // Any edit means this is no longer the pristine built-in — the event
    // binding button must stop offering to bind the built-in by key.
    if (curBuiltin) { curBuiltin = null; updateEventBtn(); }
}

function refresh(keepSel) {
    PV.setLayout(LAYOUT);
    renderTree();
    renderTriggers();
    if (keepSel && selPath !== null) PV.select(selPath);
    // Boxes have moved, so the drag handles have to be re-measured. After the
    // frame paints, or every rect is the previous layout's.
    if (typeof mountResizeHandles === 'function') requestAnimationFrame(mountResizeHandles);
}

/* ── Screens (break screen, pre-game screen, …) ──────────────────────── */

var screensEl = document.getElementById('tbeScreens');

function selectScreen(i) {
    editScreenIndex = i;
    selPath = null;
    treeCollapsed = {};          // paths are per-screen; stale ones would fold the wrong nodes
    if (PV) PV.forceScreen(i);   // pin the preview to the screen being edited
    renderScreensBar();
    renderTree();
    renderInspector();
}

function renderScreensBar() {
    screensEl.textContent = '';
    var tabs = document.createElement('div');
    tabs.className = 'tbe-screen-tabs';
    // Display order ≠ storage order: the catch-all (usually Main) leads the
    // strip because it is the layout's home screen, even though it must sit
    // LAST in the array so conditional screens can win the evaluation scan.
    var order = [];
    LAYOUT.screens.forEach(function (scr, i) {
        if (scr.when === undefined || scr.when === null) order.push(i);
    });
    LAYOUT.screens.forEach(function (scr, i) {
        if (!(scr.when === undefined || scr.when === null)) order.push(i);
    });
    order.forEach(function (i) {
        var scr = LAYOUT.screens[i];
        var btn = document.createElement('button');
        btn.className = 'tbe-screen-tab' + (i === editScreenIndex ? ' active' : '');
        btn.textContent = scr.name || ('Screen ' + (i + 1));
        btn.addEventListener('click', function () { selectScreen(i); });
        tabs.appendChild(btn);
    });
    var add = document.createElement('button');
    add.className = 'tbe-screen-add'; add.textContent = screenMenuOpen ? '× Cancel' : '+ Screen';
    add.addEventListener('click', function () { screenMenuOpen = !screenMenuOpen; renderScreensBar(); });
    tabs.appendChild(add);
    screensEl.appendChild(tabs);

    // "+ Screen" opens a template gallery (the trigger gallery's idiom) —
    // a new screen used to be hard-coded as a Break screen.
    if (screenMenuOpen) {
        var menu = document.createElement('div');
        menu.className = 'tbe-trigger-menu';
        SCREEN_TEMPLATES.forEach(function (t) {
            var it = document.createElement('button');
            it.type = 'button';
            it.className = 'tbe-trigger-menu-item';
            var nm = document.createElement('strong'); nm.textContent = t[0];
            var ds = document.createElement('span'); ds.textContent = t[1];
            it.appendChild(nm); it.appendChild(ds);
            it.addEventListener('click', function () { screenMenuOpen = false; addScreen(t[2]); });
            menu.appendChild(it);
        });
        screensEl.appendChild(menu);
    }

    // Management row for the active screen: name, show-when, delete.
    var scr = curScreen();
    var mgmt = document.createElement('div');
    mgmt.className = 'tbe-screen-mgmt';
    var nm = document.createElement('input');
    nm.type = 'text'; nm.value = scr.name || ''; nm.placeholder = 'Screen name'; nm.maxLength = 40;
    nm.addEventListener('change', function () { pushUndo(); scr.name = nm.value.trim() || 'Screen'; renderScreensBar(); });
    mgmt.appendChild(nm);

    var del = document.createElement('button');
    del.className = 'tbe-mini tbe-mini-danger'; del.textContent = 'Delete screen';
    del.disabled = LAYOUT.screens.length <= 1;
    del.addEventListener('click', function () {
        if (LAYOUT.screens.length <= 1) return;
        pushUndo();
        LAYOUT.screens.splice(editScreenIndex, 1);
        editScreenIndex = 0;
        refresh(); selectScreen(0);
    });
    mgmt.appendChild(del);
    screensEl.appendChild(mgmt);

    var condWrap = document.createElement('div');
    condWrap.className = 'tbe-screen-cond';
    var lbl = document.createElement('span');
    lbl.textContent = 'Show this screen when:';
    condWrap.appendChild(lbl);
    condWrap.appendChild(condEditor(scr.when, function (c) { pushUndo(); setOrDelete(scr, 'when', c); refresh(true); }));
    var note = document.createElement('div');
    note.className = 'tbe-screen-note';
    note.textContent = 'Conditional screens are checked in order and the first match shows; the default screen (no condition) shows when nothing matches.';
    condWrap.appendChild(note);

    // Rotation: dwell seconds; blank = this screen never rotates.
    var cycRow = document.createElement('div');
    cycRow.className = 'tbe-screen-cycle';
    var cycLbl = document.createElement('span');
    cycLbl.textContent = 'Rotate after (seconds):';
    var cyc = document.createElement('input');
    cyc.type = 'number'; cyc.min = 2; cyc.max = 3600; cyc.step = 1; cyc.placeholder = 'off';
    cyc.value = scr.cycle === undefined ? '' : scr.cycle;
    cyc.addEventListener('change', function () {
        pushUndo();
        var v = parseInt(cyc.value, 10);
        setOrDelete(scr, 'cycle', (v >= 2 && v <= 3600) ? v : undefined);
        cyc.value = scr.cycle === undefined ? '' : scr.cycle;
        refresh(true);
    });
    cycRow.appendChild(cycLbl);
    cycRow.appendChild(cyc);
    var cycNote = document.createElement('div');
    cycNote.className = 'tbe-screen-note';
    cycNote.textContent = 'Give two or more screens a rotation time and the display cycles through the ones whose conditions match, each for its own time. A screen without one (Break) still takes over outright.';
    condWrap.appendChild(cycRow);
    condWrap.appendChild(cycNote);
    screensEl.appendChild(condWrap);
}

var screenMenuOpen = false;

// [label, description, factory(bg) → screen]. Every template inherits the
// current screen's background so a themed layout stays themed.
var SCREEN_TEMPLATES = [
    ['Break', 'Takes over during breaks', function (bg) { return {
        name: 'Break', when: 'on_break', bg: bg,
        root: { col: [
            { cell: { text: 'ON BREAK', fit: true, bold: true, color: '#fbbf24' }, weight: 3 },
            { cell: { text: 'Back in <time.nextBreak>', size: 3.5, color: '#e2e8f0' }, weight: 1 }
        ] } }; }],
    ['Final table', 'Seat map once 10 or fewer remain', function (bg) { return {
        name: 'Final Table', when: 'players.left <= 10 and players.left > 1', bg: bg,
        root: { col: [
            { cell: { text: 'FINAL TABLE', fit: true, bold: true, color: '#fbbf24' }, weight: 1 },
            { cell: { text: '', size: 2.6, color: '#e2e8f0', seats: true }, weight: 4 },
            { cell: { text: '<blinds.now>', size: 3.2, color: '#e2e8f0', bold: true }, weight: 0.8 }
        ] } }; }],
    ['Phone', 'A simple stacked view for phones that scan the QR', function (bg) { return {
        name: 'Phone', when: 'mobile', bg: bg,
        root: { col: [
            { cell: { text: '<clock>', fit: true, bold: true, color: '#ffffff' }, weight: 3 },
            { cell: { text: '<blinds.now>', size: 5, color: '#e2e8f0', bold: true }, weight: 1.2 },
            { cell: { text: 'Round <round.num>  ·  <players.left> of <players.total> left', size: 2.8, color: '#94a3b8' }, weight: 0.8 },
            { cell: { text: '<game.name>', size: 2.4, color: '#94a3b8' }, weight: 0.6 }
        ] } }; }],
    ['Game over', 'The wrap-up once a winner stands', function (bg) { return {
        name: 'Game Over', when: 'game_over', bg: bg,
        root: { col: [
            { cell: { text: 'GAME OVER', fit: true, bold: true, color: '#ef4444' }, weight: 2 },
            { cell: { text: '<event.name>', size: 4, color: '#e2e8f0', bold: true }, weight: 1 },
            { cell: { text: '<prizes.line>', size: 3, color: '#94a3b8' }, weight: 1 }
        ] } }; }],
    ['Blank', 'Empty — give it a condition, or the catch-all stays in front of it', function (bg) { return {
        name: 'Screen', bg: bg,
        root: { col: [ { cell: { text: 'New screen', size: 4, color: '#e2e8f0' }, weight: 1 } ] } }; }]
];

function addScreen(make) {
    pushUndo();
    var base = curScreen();
    var fresh = make(base.bg ? JSON.parse(JSON.stringify(base.bg)) : { color: '#000' });
    LAYOUT.screens.push(fresh);
    // A conditional screen must sit before any catch-all (a screen with no
    // `when`), or the catch-all would always win. Keep unconditional ones last.
    LAYOUT.screens.sort(function (a, b) {
        var au = (a.when === undefined || a.when === null), bu = (b.when === undefined || b.when === null);
        return au === bu ? 0 : (au ? 1 : -1);
    });
    refresh();
    selectScreen(LAYOUT.screens.indexOf(fresh));
}

/* ── Structure tree ──────────────────────────────────────────────────── */

var treeEl = document.getElementById('tbeTree');

// Session-only UI state: which container paths are folded shut. Never saved
// with the layout; reset when the edited screen changes (paths are per-screen).
var treeCollapsed = {};

function cellLabel(cell) {
    var t = String(cell.text || '').replace(/\n/g, ' ').trim();
    return t.length > 26 ? t.slice(0, 26) + '…' : (t || '(empty cell)');
}

// One tree row. Containers get a fold toggle; cells get a spacer so labels align.
function treeRow(ps, label, isContainer, depth) {
    var el = document.createElement('div');
    el.className = 'tbe-node' + (selPath === ps ? ' selected' : '');
    el.style.paddingLeft = (10 + depth * 16) + 'px';
    el.setAttribute('data-tpath', ps);
    var tog = document.createElement('span');
    tog.className = 'tbe-node-tog';
    if (isContainer) {
        el.classList.add('tbe-node-container');
        tog.setAttribute('data-tog', ps);
        tog.textContent = treeCollapsed[ps] ? '▸' : '▾';
        tog.title = treeCollapsed[ps] ? 'Expand' : 'Collapse';
    }
    el.appendChild(tog);
    el.appendChild(document.createTextNode(label));
    return el;
}

function renderTree() {
    treeEl.textContent = '';
    treeEl.appendChild(treeRow('', 'Screen (' + kindOf(curScreen().root) + ')', true, 0));
    if (!treeCollapsed['']) walk(curScreen().root, [], 1);

    function walk(node, path, depth) {
        var kids = node.row || node.col;
        if (!kids) return;
        for (var i = 0; i < kids.length; i++) {
            var p = path.concat(i), ps = p.join('.');
            var k = kindOf(kids[i]);
            var label = k === 'cell' ? cellLabel(kids[i].cell)
                      : (k === 'row' ? 'Row' : 'Column') + ' (' + (kids[i].row || kids[i].col).length + ')';
            treeEl.appendChild(treeRow(ps, label, k !== 'cell', depth));
            if (!treeCollapsed[ps]) walk(kids[i], p, depth + 1);
        }
    }
}

treeEl.addEventListener('click', function (e) {
    var t = e.target.closest('[data-tog]');
    if (t) {                       // fold toggle, not a selection
        var tp = t.getAttribute('data-tog');
        if (treeCollapsed[tp]) delete treeCollapsed[tp]; else treeCollapsed[tp] = true;
        renderTree();
        return;
    }
    var n = e.target.closest('[data-tpath]');
    if (n) select(n.getAttribute('data-tpath'));
});

/* ── Selection + inspector ───────────────────────────────────────────── */

function select(path) {
    selPath = path;
    // Unfold every ancestor so the selection is always visible in the tree
    // (a preview click can land on a node inside a collapsed container).
    if (path) {
        delete treeCollapsed[''];
        var parts = path.split('.');
        for (var i = 1; i < parts.length; i++) delete treeCollapsed[parts.slice(0, i).join('.')];
    }
    PV.select(path === '' ? null : path);
    renderTree();
    renderInspector();
}

var insp = document.getElementById('tbeInspector');
var inspTitle = document.getElementById('tbeInspTitle');

function field(label, input) {
    var row = document.createElement('label');
    row.className = 'tbe-field';
    var span = document.createElement('span');
    span.textContent = label;
    row.appendChild(span);
    row.appendChild(input);
    return row;
}
function textInput(value, oninput, placeholder) {
    var i = document.createElement('input');
    i.type = 'text'; i.value = value || ''; i.placeholder = placeholder || '';
    i.addEventListener('change', function () { pushUndo(); oninput(i.value.trim()); refresh(true); });
    return i;
}
function numInput(value, min, max, step, onchange) {
    var i = document.createElement('input');
    i.type = 'number'; i.min = min; i.max = max; i.step = step;
    i.value = value === undefined || value === null ? '' : value;
    i.addEventListener('change', function () {
        pushUndo();
        onchange(i.value === '' ? undefined : Number(i.value));
        refresh(true);
    });
    return i;
}
function boolInput(value, onchange) {
    var i = document.createElement('input');
    i.type = 'checkbox'; i.checked = !!value;
    i.addEventListener('change', function () { pushUndo(); onchange(i.checked); refresh(true); });
    return i;
}
function selInput(value, options, onchange, labels) {
    var s = document.createElement('select');
    options.forEach(function (o, ix) {
        var op = document.createElement('option');
        op.value = o; op.textContent = labels ? labels[ix] : o;
        s.appendChild(op);
    });
    s.value = value || options[0];
    s.addEventListener('change', function () { pushUndo(); onchange(s.value); refresh(true); });
    return s;
}
function colorInput(value, onchange) {
    var wrap = document.createElement('span');
    wrap.className = 'tbe-color';
    var c = document.createElement('input');
    c.type = 'color';
    c.value = /^#[0-9a-fA-F]{6}$/.test(value || '') ? value : '#ffffff';
    var t = document.createElement('input');
    t.type = 'text'; t.value = value || ''; t.placeholder = '#rrggbb / rgb() / none';
    c.addEventListener('input', function () { t.value = c.value; });
    c.addEventListener('change', function () { pushUndo(); onchange(c.value); refresh(true); });
    t.addEventListener('change', function () { pushUndo(); onchange(t.value.trim()); refresh(true); });
    wrap.appendChild(c); wrap.appendChild(t);
    return wrap;
}
// Upload an image via timer_beta_dl.php's upload_image action (byte-level MIME
// check + getimagesize, size cap, per-user daily limit). Stores under
// /uploads/timer_layouts/ and calls back with that URL.
/* Every image pick in the editor comes through here (screen backgrounds, box
 * images, image cells), so the library-first behaviour lands everywhere at
 * once: a grid of what already exists — this user's uploads, then the app's
 * shipped timer art — with uploading as the explicit LAST resort. Re-using a
 * file costs nothing and counts against no quota; re-uploading the same
 * artwork for every layout burned through the daily upload cap. */
function uploadImage(onUrl) {
    fetch('/timer_beta_dl.php?action=list_images')
        .then(function (r) { return r.json(); })
        .then(function (j) {
            var imgs = (j && j.ok && Array.isArray(j.images)) ? j.images : [];
            if (!imgs.length) { pickNewImage(onUrl); return; }   // empty library: straight to upload
            openImagePicker(imgs, onUrl);
        })
        .catch(function () { pickNewImage(onUrl); });
}


function pickNewImage(onUrl) {
    var inp = document.createElement('input');
    inp.type = 'file'; inp.accept = 'image/png,image/jpeg,image/gif,image/webp';
    inp.addEventListener('change', function () {
        var f = inp.files && inp.files[0];
        if (!f) return;
        var fd = new FormData();
        fd.append('action', 'upload_image');
        fd.append('image', f);
        fd.append('csrf_token', TBE_CSRF);
        fetch('/timer_beta_dl.php', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (j && j.url) onUrl(j.url);
                else (window.pkAlert || alert)(j && j.error ? j.error : 'Upload failed.');
            })
            .catch(function () { (window.pkAlert || alert)('Upload failed.'); });
    });
    inp.click();
}

function openImagePicker(imgs, onUrl) {
    var old = document.querySelector('.tbe-imgpick');
    if (old) old.remove();
    var wrap = document.createElement('div');
    wrap.className = 'tbe-imgpick';
    var panel = document.createElement('div');
    panel.className = 'tbe-imgpick-panel';
    var head = document.createElement('div');
    head.className = 'tbe-imgpick-head';
    var title = document.createElement('span');
    title.textContent = 'Pick an image';
    var up = document.createElement('button');
    up.className = 'tbe-mini'; up.textContent = 'Upload new\u2026';
    up.addEventListener('click', function () { wrap.remove(); pickNewImage(onUrl); });
    var x = document.createElement('button');
    x.className = 'tbe-mini'; x.textContent = '\u00d7';
    x.addEventListener('click', function () { wrap.remove(); });
    head.appendChild(title); head.appendChild(up); head.appendChild(x);
    panel.appendChild(head);

    var grid = document.createElement('div');
    grid.className = 'tbe-imgpick-grid';
    var lastMine = null;
    imgs.forEach(function (im) {
        if (lastMine !== im.mine) {
            lastMine = im.mine;
            var lab = document.createElement('div');
            lab.className = 'tbe-imgpick-sec';
            lab.textContent = im.mine ? 'Your uploads' : 'Built-in artwork';
            grid.appendChild(lab);
        }
        var cellBtn = document.createElement('button');
        cellBtn.type = 'button';
        cellBtn.className = 'tbe-imgpick-item';
        cellBtn.title = im.url.split('/').pop();
        var img = document.createElement('img');
        img.src = im.url;
        img.loading = 'lazy';
        img.alt = '';
        cellBtn.appendChild(img);
        cellBtn.addEventListener('click', function () { wrap.remove(); onUrl(im.url); });
        grid.appendChild(cellBtn);
    });
    panel.appendChild(grid);
    wrap.appendChild(panel);
    wrap.addEventListener('click', function (e) { if (e.target === wrap) wrap.remove(); });
    document.body.appendChild(wrap);
}

function setOrDelete(obj, key, val) {
    if (val === undefined || val === '' || val === false) delete obj[key];
    else obj[key] = val;
}

/* A condition builder: state + round + ante/rebuys clauses that AND together.
 * Emits a WHEN string when only a state is set (shorthand), an object when more
 * than one clause is set, or undefined when nothing constrains it. */
var STATE_OPTS = ['', 'running', 'paused', 'on_break', 'pre_game', 'game_over'];
var STATE_LBLS = ['Any state', 'Running', 'Paused', 'On break', 'Pre-game', 'Game over'];

// State names are the string shorthand; any OTHER string in a `when` is an
// expression ("blinds.big > 10000 and not onBreak").
var COND_STATES = ['always', 'running', 'paused', 'on_break', 'pre_game', 'has_ante', 'has_rebuys', 'game_over'];

function condEditor(cond, onchange) {
    var m = {}, expr = '';
    if (typeof cond === 'string') {
        if (COND_STATES.indexOf(cond) !== -1) { if (cond !== 'always') m.state = cond; }
        else if (cond) expr = cond;
    }
    else if (cond && typeof cond === 'object') m = JSON.parse(JSON.stringify(cond));

    var wrap = document.createElement('div');
    wrap.className = 'tbe-cond';

    function emit() {
        // An expression wins outright: it can say everything the widgets can
        // and more, and half-expression half-clauses would be two conditions
        // pretending to be one.
        if (expr.trim() !== '') { onchange(expr.trim()); return; }
        var keys = Object.keys(m).filter(function (k) { return m[k] !== undefined && m[k] !== ''; });
        if (!keys.length) { onchange(undefined); return; }
        if (keys.length === 1 && m.state) { onchange(m.state); return; }   // shorthand
        onchange(JSON.parse(JSON.stringify(m)));
    }

    // ── Expression row: the primary path ──
    var exRow = document.createElement('div');
    exRow.className = 'tbe-cond-expr';
    var ex = document.createElement('input');
    ex.type = 'text';
    ex.placeholder = 'Expression, e.g. blinds.big > 10000 and not onBreak';
    ex.value = expr;
    var exMsg = document.createElement('div');
    exMsg.className = 'tbe-cond-msg';
    function validate() {
        var v = ex.value.trim();
        if (v === '') { exMsg.textContent = ''; exMsg.className = 'tbe-cond-msg'; return true; }
        // The renderer's own parser judges it, so the tick here and the truth
        // on the display can never disagree.
        var res = (PV && PV.validateCondition) ? PV.validateCondition(v) : { ok: true };
        exMsg.textContent = res.ok ? '✓ valid' : res.error;
        exMsg.className = 'tbe-cond-msg ' + (res.ok ? 'ok' : 'bad');
        return res.ok;
    }
    ex.addEventListener('input', validate);
    ex.addEventListener('change', function () {
        expr = ex.value;
        if (validate()) emit();
    });
    exRow.appendChild(ex);
    exRow.appendChild(exMsg);
    // The value reference: one click turns the name blob into a described
    // list, so "what can I compare?" is answered where the question happens.
    var hint = document.createElement('button');
    hint.type = 'button';
    hint.className = 'tbe-cond-hint tbe-cond-hint-btn';
    var condList = null;
    function setHintLabel() {
        hint.textContent = (condList ? '\u25be' : '\u25b8') + ' Values you can use';
    }
    setHintLabel();
    hint.addEventListener('click', function () {
        if (condList) { condList.remove(); condList = null; setHintLabel(); return; }
        condList = document.createElement('div');
        condList.className = 'tbe-cond-list';
        ((PV && PV.conditionNames) ? PV.conditionNames() : Object.keys(COND_DESC)).forEach(function (n) {
            var row = document.createElement('div');
            var nm = document.createElement('code'); nm.textContent = n;
            var ds = document.createElement('span'); ds.textContent = COND_DESC[n] || '';
            row.appendChild(nm); row.appendChild(ds);
            row.addEventListener('click', function () {
                // Clicking a value drops it into the expression at the cursor end.
                ex.value = (ex.value + ' ' + n).trim();
                ex.focus(); validate();
            });
            condList.appendChild(row);
        });
        exRow.appendChild(condList);
        setHintLabel();
    });
    exRow.appendChild(hint);
    wrap.appendChild(exRow);
    validate();

    var st = document.createElement('select');
    STATE_OPTS.forEach(function (o, i) { var op = document.createElement('option'); op.value = o; op.textContent = STATE_LBLS[i]; st.appendChild(op); });
    st.value = m.state || '';
    st.addEventListener('change', function () { if (st.value) m.state = st.value; else delete m.state; emit(); });
    wrap.appendChild(st);

    var rnd = document.createElement('input');
    rnd.type = 'text'; rnd.placeholder = 'Round e.g. >3, even, all'; rnd.value = m.round || '';
    rnd.addEventListener('change', function () { var v = rnd.value.trim(); if (v) m.round = v; else delete m.round; emit(); });
    wrap.appendChild(rnd);

    [['hasAnte', 'Ante'], ['hasRebuys', 'Rebuys']].forEach(function (pair) {
        var s = document.createElement('select');
        [['', 'Any ' + pair[1].toLowerCase()], ['yes', 'Has ' + pair[1].toLowerCase()], ['no', 'No ' + pair[1].toLowerCase()]]
            .forEach(function (o) { var op = document.createElement('option'); op.value = o[0]; op.textContent = o[1]; s.appendChild(op); });
        s.value = m[pair[0]] === true ? 'yes' : (m[pair[0]] === false ? 'no' : '');
        s.addEventListener('change', function () {
            if (s.value === 'yes') m[pair[0]] = true; else if (s.value === 'no') m[pair[0]] = false; else delete m[pair[0]];
            emit();
        });
        wrap.appendChild(s);
    });
    return wrap;
}

/* Conditional variants: alternate emphasis (text/colour/etc.) shown when a
 * condition matches. First matching variant wins over the base — conditional
 * per-cell styling, scoped to emphasis so a variant can never reflow the layout. */
function renderVariants(cell) {
    if (!Array.isArray(cell.variants)) cell.variants = [];
    var box = document.createElement('div');
    box.className = 'tbe-variants';
    var head = document.createElement('div');
    head.className = 'tbe-variants-head';
    head.textContent = 'Variants (' + cell.variants.length + ')';
    box.appendChild(head);

    cell.variants.forEach(function (v, ix) {
        var card = document.createElement('div');
        card.className = 'tbe-variant';
        var top = document.createElement('div');
        top.className = 'tbe-variant-top';
        var lbl = document.createElement('span');
        lbl.textContent = 'When';
        var rm = document.createElement('button');
        rm.className = 'tbe-mini tbe-mini-danger'; rm.textContent = 'Remove';
        rm.addEventListener('click', function () { pushUndo(); cell.variants.splice(ix, 1); refresh(true); renderInspector(); });
        top.appendChild(lbl); top.appendChild(rm);
        card.appendChild(top);
        card.appendChild(condEditor(v.when, function (c) { pushUndo(); setOrDelete(v, 'when', c); refresh(true); }));

        var ta = document.createElement('textarea');
        ta.rows = 2; ta.placeholder = 'Text override (blank = keep base text)'; ta.value = v.text || '';
        ta.addEventListener('change', function () { pushUndo(); setOrDelete(v, 'text', ta.value); refresh(true); });
        card.appendChild(field('Text', ta));
        card.appendChild(field('Colour', colorInput(v.color, function (c) { setOrDelete(v, 'color', c); })));
        card.appendChild(field('Background', colorInput(v.bg, function (c) { setOrDelete(v, 'bg', c); })));
        card.appendChild(field('Bold', boolInput(v.bold, function (c) { setOrDelete(v, 'bold', c); })));
        box.appendChild(card);
    });

    var add = document.createElement('button');
    add.className = 'tbe-mini'; add.textContent = '+ Add variant';
    add.addEventListener('click', function () {
        pushUndo();
        cell.variants.push({ when: 'paused', color: '#ef4444' });
        refresh(true); renderInspector();
    });
    box.appendChild(add);
    insp.appendChild(box);
}

/* Per-element styling: one element inside the cell's line styled apart from
 * the rest — "<ante> bold and orange in the blinds line". Offers only the
 * elements actually present in the cell's text (base + variants): a style for
 * an element that never renders is a setting that does nothing. */
function renderElStyles(cell) {
    var names = [], seen = {};
    [cell.text || ''].concat((cell.variants || []).map(function (v) { return v.text || ''; }))
        .join('\n').replace(/<([a-zA-Z][a-zA-Z0-9]*)>/g, function (m, n) {
            var lc = n.toLowerCase();
            if (!seen[lc]) { seen[lc] = true; names.push(n); }
            return m;
        });
    var have = cell.elStyles && Object.keys(cell.elStyles).length;
    if (!names.length && !have) return;

    var box = document.createElement('div');
    box.className = 'tbe-variants';
    var head = document.createElement('div');
    head.className = 'tbe-variants-head';
    head.textContent = 'Element styles (' + (have ? Object.keys(cell.elStyles).length : 0) + ')';
    box.appendChild(head);

    Object.keys(cell.elStyles || {}).forEach(function (k) {
        var st = cell.elStyles[k];
        var card = document.createElement('div');
        card.className = 'tbe-variant';
        var top = document.createElement('div');
        top.className = 'tbe-variant-top';
        var lbl = document.createElement('span');
        lbl.textContent = '<' + k + '>';
        var rm = document.createElement('button');
        rm.className = 'tbe-mini tbe-mini-danger'; rm.textContent = 'Remove';
        rm.addEventListener('click', function () {
            pushUndo();
            delete cell.elStyles[k];
            if (!Object.keys(cell.elStyles).length) delete cell.elStyles;
            refresh(true); renderInspector();
        });
        top.appendChild(lbl); top.appendChild(rm);
        card.appendChild(top);
        card.appendChild(field('Colour', colorInput(st.color, function (c) { setOrDelete(st, 'color', c); })));
        card.appendChild(field('Bold', boolInput(st.bold, function (c) { setOrDelete(st, 'bold', c || undefined); })));
        var sc = document.createElement('input');
        sc.type = 'number'; sc.step = '0.1'; sc.min = '0.2'; sc.max = '4';
        sc.placeholder = '1 = same size'; sc.value = st.scale !== undefined ? st.scale : '';
        sc.addEventListener('change', function () {
            pushUndo();
            var v = parseFloat(sc.value);
            if (isFinite(v) && v > 0) st.scale = v; else delete st.scale;
            refresh(true);
        });
        card.appendChild(field('Size (x the line)', sc));
        box.appendChild(card);
    });

    var unstyled = names.filter(function (n) {
        return !Object.keys(cell.elStyles || {}).some(function (k) { return k.toLowerCase() === n.toLowerCase(); });
    });
    if (unstyled.length) {
        var row = document.createElement('div');
        row.style.display = 'flex'; row.style.gap = '.3rem'; row.style.alignItems = 'center';
        var sel = document.createElement('select');
        unstyled.forEach(function (n) {
            var o = document.createElement('option');
            o.value = n; o.textContent = '<' + n + '>';
            sel.appendChild(o);
        });
        var add = document.createElement('button');
        add.className = 'tbe-mini'; add.textContent = '+ Style element';
        add.addEventListener('click', function () {
            pushUndo();
            if (!cell.elStyles) cell.elStyles = {};
            cell.elStyles[sel.value] = {};
            refresh(true); renderInspector();
        });
        row.appendChild(sel); row.appendChild(add);
        box.appendChild(row);
    }
    insp.appendChild(box);
}

/* ── Triggers: when a condition BECOMES true, do things ───────────────────
 * Layout-level, like custom elements: they travel with the design. Each card
 * is when-expression + action rows + cooldown/once + Test (runs the actions in
 * the preview immediately — the fastest way to audition a sound). */
var TBE_PRESET_SOUNDS = ['buzzer','chime','casino','horn','countdown','double','descending','five3s','tick','pulse','chirp','gentle'];

// The viewport-tall shell subtracts the site nav's REAL height — measured
// live, because the banner image lands after load and grows the nav.
(function () {
    var nav = document.getElementById('mainNav');
    if (!nav) return;
    function navH() {
        document.documentElement.style.setProperty('--tbe-nav-h', nav.offsetHeight + 'px');
    }
    navH();
    if (typeof ResizeObserver !== 'undefined') new ResizeObserver(navH).observe(nav);
    else { window.addEventListener('resize', navH); window.addEventListener('load', navH); }
})();

var triggersPane = document.getElementById('tbeTriggers');
// Open by default: the preview column scrolls, so the panel costs the preview
// nothing — it just continues below the fold. The toggle stays for tucking it
// away.
var triggersOpen = true;
// Per-card fold state, keyed by index. Existing triggers start COLLAPSED to a
// one-line summary; only a freshly added trigger opens itself for editing.
var openTriggers = {};

// What each comparable value MEANS — the names come live from the renderer
// (PV.conditionNames), so a name added there without a line here still shows,
// just undescribed. Rendered by condEditor's "Values you can use" list.
var COND_DESC = {
    round:              'Current round (level) number',
    'blinds.small':     'Current small blind',
    'blinds.big':       'Current big blind',
    'blinds.ante':      'Current ante (0 when none)',
    'players.left':     'Players still in the game',
    'players.total':    'Everyone who bought in',
    'players.entries':  'Total entries, including re-entries',
    'players.buyIns':   'Number of buy-ins',
    'players.rebuys':   'Number of rebuys',
    'players.addOns':   'Number of add-ons',
    'players.out':      'Players knocked out so far',
    'chips.total':      'Total chips in play',
    'chips.avg':        'Average stack (chips / players left)',
    'money.pot':        'Prize pool in dollars',
    'table.count':      'Tables in play',
    'table.seats':      'Seats per table',
    'clock.minutes':    'Whole minutes left in this round',
    'clock.seconds':    'Seconds left in this round',
    levelChange:        'True for an instant when the round number changes — the classic trigger',
    playerEliminated:   'True for an instant when a player is knocked out (an undo stays silent)',
    running:            'Clock is running',
    paused:             'Clock is paused',
    onBreak:            'Schedule is on a break',
    preGame:            'Game has not started yet',
    gameOver:           'Game is over',
    hasAnte:            'This round has an ante',
    hasRebuys:          'Rebuys are enabled',
    mobile:             'Viewing screen is a phone',
    tablet:             'Viewing screen is a tablet',
    desktop:            'Viewing screen is a PC / TV'
};

// Ready-made triggers: the list of things people actually hook. Each inserts
// a working trigger (deep-copied) and opens it for tweaking — the fastest way
// to see what triggers CAN do without writing an expression first.
var TRIGGER_TEMPLATES = [
    ['Level change', 'Chime when a new round begins', { when: 'levelChange', 'do': [{ sound: 'preset:chime' }] }],
    ['One-minute warning', 'Tick + flash in the last 60 seconds of a round', { when: 'clock.seconds <= 60 and running', 'do': [{ sound: 'preset:tick' }, { flash: 'screen' }] }],
    ['Announce the blinds', 'Speaks "Blinds up" with the new numbers each round', { when: 'levelChange', 'do': [{ announce: 'Blinds up: <blinds.now>' }] }],
    ['Break starts', 'Horn when the schedule reaches a break', { when: 'on_break', 'do': [{ sound: 'preset:horn' }] }],
    ['Final table', 'Fanfare + flash when 10 or fewer remain (once)', { when: 'players.left <= 10 and players.left > 1', 'do': [{ sound: 'preset:casino' }, { flash: 'screen' }], once: true }],
    ['Heads-up', 'Buzzer when two players are left (once)', { when: 'players.left == 2 and running', 'do': [{ sound: 'preset:buzzer' }], once: true }],
    ['Game over', 'Fanfare when a winner stands (once)', { when: 'gameOver', 'do': [{ sound: 'preset:casino' }], once: true }],
    ['Player eliminated', 'Announces who was knocked out, by name', { when: 'playerEliminated', 'do': [{ sound: 'preset:descending' }, { announce: '<players.lastOut> has been eliminated' }] }],
    ['Blank trigger', 'Start from scratch', { when: 'on_break', 'do': [{ sound: 'preset:chime' }] }]
];
var addMenuOpen = false;

// One line that says what a trigger does: its condition plus a compact action
// list — "levelChange — chime" reads as a name without needing one stored.
function triggerLabel(tg) {
    var when = (typeof tg.when === 'string') ? tg.when : 'custom condition';
    var acts = (Array.isArray(tg['do']) ? tg['do'] : []).map(function (a) {
        if (!a || typeof a !== 'object') return null;
        if (a.sound !== undefined) {
            return a.sound.indexOf('preset:') === 0 ? a.sound.slice(7) : a.sound.split('/').pop();
        }
        if (a.takeover !== undefined) return 'show "' + a.takeover + '"';
        if (a.flash !== undefined) return 'flash';
        if (a.announce !== undefined) return 'speak';
        return null;
    }).filter(Boolean);
    return when + (acts.length ? ' — ' + acts.join(', ') : '');
}

function renderTriggers() {
    if (!triggersPane) return;
    triggersPane.textContent = '';
    if (!Array.isArray(LAYOUT.triggers)) LAYOUT.triggers = [];
    var box = document.createElement('div');
    box.className = 'tbe-variants';
    var head = document.createElement('button');
    head.type = 'button';
    head.className = 'tbe-variants-head tbe-triggers-toggle';
    head.textContent = (triggersOpen ? '\u25be ' : '\u25b8 ') + 'Triggers (' + LAYOUT.triggers.length + ')';
    head.addEventListener('click', function () {
        triggersOpen = !triggersOpen;
        renderTriggers();
    });
    box.appendChild(head);
    if (!triggersOpen) {
        if (!LAYOUT.triggers.length) delete LAYOUT.triggers;
        triggersPane.appendChild(box);
        return;
    }
    var note = document.createElement('div');
    note.className = 'tbe-note';
    note.textContent = 'Fire ONCE each time the condition becomes true — a sound, a screen '
                     + 'takeover, a flash, a spoken line. Screens opened from the QR start muted.';
    box.appendChild(note);

    LAYOUT.triggers.forEach(function (tg, ix) {
        if (!Array.isArray(tg['do'])) tg['do'] = [];
        var card = document.createElement('div');
        card.className = 'tbe-variant';

        if (!openTriggers[ix]) {
            // Collapsed: the whole card is one clickable summary line.
            var row = document.createElement('button');
            row.type = 'button';
            row.className = 'tbe-trigger-row';
            row.textContent = '▸ ' + triggerLabel(tg);
            row.title = 'Click to edit this trigger';
            row.addEventListener('click', function () {
                openTriggers[ix] = true;
                renderTriggers();
            });
            card.appendChild(row);
            box.appendChild(card);
            return;
        }

        var top = document.createElement('div');
        top.className = 'tbe-variant-top';
        var lbl = document.createElement('button');
        lbl.type = 'button';
        lbl.className = 'tbe-trigger-row tbe-trigger-row-open';
        lbl.textContent = '▾ ' + triggerLabel(tg);
        lbl.title = 'Collapse';
        lbl.addEventListener('click', function () {
            delete openTriggers[ix];
            renderTriggers();
        });
        var test = document.createElement('button');
        test.className = 'tbe-mini'; test.textContent = '\u25b6 Test';
        test.title = 'Run this trigger\'s actions in the preview right now';
        test.addEventListener('click', function () {
            var w = frame.contentWindow;
            if (w && w.TBPreview && w.TBPreview.runActions) w.TBPreview.runActions(tg['do']);
        });
        var rm = document.createElement('button');
        rm.className = 'tbe-mini tbe-mini-danger'; rm.textContent = 'Remove';
        rm.addEventListener('click', function () {
            pushUndo(); LAYOUT.triggers.splice(ix, 1);
            if (!LAYOUT.triggers.length) delete LAYOUT.triggers;
            openTriggers = {};   // indexes shifted; stale fold state would open the wrong card
            refresh(true); renderInspector();
        });
        top.appendChild(lbl); top.appendChild(test); top.appendChild(rm);
        card.appendChild(top);
        card.appendChild(condEditor(tg.when, function (c) { pushUndo(); tg.when = c || 'always'; refresh(true); }));

        tg['do'].forEach(function (act, ai) {
            card.appendChild(triggerActionRow(tg, act, ai));
        });
        var addAct = document.createElement('button');
        addAct.className = 'tbe-mini'; addAct.textContent = '+ Action';
        addAct.addEventListener('click', function () {
            pushUndo(); tg['do'].push({ sound: 'preset:chime' });
            refresh(true); renderInspector();
        });
        card.appendChild(addAct);

        var optsRow = document.createElement('div');
        optsRow.style.display = 'flex'; optsRow.style.gap = '.6rem'; optsRow.style.alignItems = 'center'; optsRow.style.marginTop = '.35rem';
        var cd = document.createElement('input');
        cd.type = 'number'; cd.min = '0'; cd.max = '3600'; cd.placeholder = 'cooldown s';
        cd.style.width = '6.5rem';
        cd.value = tg.cooldown !== undefined ? tg.cooldown : '';
        cd.title = 'Minimum seconds between fires';
        cd.addEventListener('change', function () {
            pushUndo();
            var v = parseInt(cd.value, 10);
            if (isFinite(v) && v > 0) tg.cooldown = v; else delete tg.cooldown;
            refresh(true);
        });
        var onceL = document.createElement('label');
        var onceC = document.createElement('input');
        onceC.type = 'checkbox'; onceC.checked = !!tg.once;
        onceC.addEventListener('change', function () {
            pushUndo();
            if (onceC.checked) tg.once = true; else delete tg.once;
            refresh(true);
        });
        onceL.appendChild(onceC); onceL.appendChild(document.createTextNode(' once per game'));
        optsRow.appendChild(cd); optsRow.appendChild(onceL);
        card.appendChild(optsRow);
        box.appendChild(card);
    });

    var add = document.createElement('button');
    add.className = 'tbe-mini'; add.textContent = addMenuOpen ? '\u00d7 Cancel' : '+ Add trigger';
    add.addEventListener('click', function () {
        addMenuOpen = !addMenuOpen;
        renderTriggers();
    });
    box.appendChild(add);
    if (addMenuOpen) {
        var menu = document.createElement('div');
        menu.className = 'tbe-trigger-menu';
        TRIGGER_TEMPLATES.forEach(function (t) {
            var it = document.createElement('button');
            it.type = 'button';
            it.className = 'tbe-trigger-menu-item';
            var nm = document.createElement('strong'); nm.textContent = t[0];
            var ds = document.createElement('span'); ds.textContent = t[1];
            it.appendChild(nm); it.appendChild(ds);
            it.addEventListener('click', function () {
                pushUndo();
                if (!Array.isArray(LAYOUT.triggers)) LAYOUT.triggers = [];
                LAYOUT.triggers.push(JSON.parse(JSON.stringify(t[2])));
                openTriggers[LAYOUT.triggers.length - 1] = true;
                addMenuOpen = false;
                refresh(true); renderInspector();
            });
            menu.appendChild(it);
        });
        box.appendChild(menu);
    }
    if (!LAYOUT.triggers.length) delete LAYOUT.triggers;
    triggersPane.appendChild(box);
}

function triggerActionRow(tg, act, ai) {
    var row = document.createElement('div');
    row.className = 'tbe-trigact';
    var type = act.sound !== undefined ? 'sound' : act.takeover !== undefined ? 'takeover'
             : act.flash !== undefined ? 'flash' : 'announce';
    var sel = document.createElement('select');
    [['sound', 'Play sound'], ['takeover', 'Show screen'], ['flash', 'Flash'], ['announce', 'Announce (speak)']]
        .forEach(function (o) {
            var op = document.createElement('option');
            op.value = o[0]; op.textContent = o[1];
            sel.appendChild(op);
        });
    sel.value = type;
    sel.addEventListener('change', function () {
        pushUndo();
        var fresh = sel.value === 'sound' ? { sound: 'preset:chime' }
                  : sel.value === 'takeover' ? { takeover: (LAYOUT.screens[0] || {}).name || 'Main', seconds: 8 }
                  : sel.value === 'flash' ? { flash: 'screen' }
                  : { announce: 'Blinds up: <blinds.now>' };
        tg['do'][ai] = fresh;
        refresh(true); renderInspector();
    });
    row.appendChild(sel);

    if (type === 'sound') {
        var snd = document.createElement('select');
        TBE_PRESET_SOUNDS.forEach(function (k) {
            var op = document.createElement('option');
            op.value = 'preset:' + k; op.textContent = k;
            snd.appendChild(op);
        });
        var upOpt = document.createElement('option');
        upOpt.value = '__mine__'; upOpt.textContent = 'my uploads\u2026';
        snd.appendChild(upOpt);
        if (/^\/uploads\/timer_sounds\//.test(act.sound)) {
            var cur = document.createElement('option');
            cur.value = act.sound; cur.textContent = act.sound.split('/').pop();
            snd.appendChild(cur);
        }
        snd.value = act.sound;
        snd.addEventListener('change', function () {
            if (snd.value === '__mine__') { pickSound(function (url) { pushUndo(); act.sound = url; refresh(true); renderInspector(); }); snd.value = act.sound; return; }
            pushUndo(); act.sound = snd.value; refresh(true);
        });
        row.appendChild(snd);
    } else if (type === 'takeover') {
        var scr = document.createElement('select');
        (LAYOUT.screens || []).forEach(function (sc) {
            var op = document.createElement('option');
            op.value = sc.name; op.textContent = sc.name;
            scr.appendChild(op);
        });
        scr.value = act.takeover;
        scr.addEventListener('change', function () { pushUndo(); act.takeover = scr.value; refresh(true); });
        row.appendChild(scr);
        var secs = document.createElement('input');
        secs.type = 'number'; secs.min = '1'; secs.max = '120'; secs.style.width = '4.5rem';
        secs.value = act.seconds || 8;
        secs.title = 'Seconds before returning';
        secs.addEventListener('change', function () { pushUndo(); act.seconds = Math.max(1, Math.min(120, parseInt(secs.value, 10) || 8)); refresh(true); });
        row.appendChild(secs);
    } else if (type === 'announce') {
        var txt = document.createElement('input');
        txt.type = 'text'; txt.placeholder = 'Blinds up: <blinds.now>';
        txt.value = act.announce || '';
        txt.style.flex = '1';
        txt.addEventListener('change', function () { pushUndo(); act.announce = txt.value; refresh(true); });
        row.appendChild(txt);
    }
    var del = document.createElement('button');
    del.className = 'tbe-mini tbe-mini-danger'; del.textContent = '\u00d7';
    del.title = 'Remove this action';
    del.addEventListener('click', function () {
        pushUndo(); tg['do'].splice(ai, 1);
        refresh(true); renderInspector();
    });
    row.appendChild(del);
    return row;
}

// The sound library: own uploads with a preview, upload as the last resort —
// the image picker's shape.
function pickSound(onUrl) {
    fetch('/timer_beta_dl.php?action=list_sounds')
        .then(function (r) { return r.json(); })
        .then(function (j) {
            var snds = (j && j.ok && Array.isArray(j.sounds)) ? j.sounds : [];
            if (!snds.length) { uploadSoundFile(onUrl); return; }
            var wrap = document.createElement('div');
            wrap.className = 'tbe-imgpick';
            var panel = document.createElement('div');
            panel.className = 'tbe-imgpick-panel';
            var head = document.createElement('div');
            head.className = 'tbe-imgpick-head';
            var title = document.createElement('span'); title.textContent = 'Pick a sound';
            var up = document.createElement('button');
            up.className = 'tbe-mini'; up.textContent = 'Upload new\u2026';
            up.addEventListener('click', function () { wrap.remove(); uploadSoundFile(onUrl); });
            var x = document.createElement('button');
            x.className = 'tbe-mini'; x.textContent = '\u00d7';
            x.addEventListener('click', function () { wrap.remove(); });
            head.appendChild(title); head.appendChild(up); head.appendChild(x);
            panel.appendChild(head);
            var listEl = document.createElement('div');
            listEl.className = 'tbe-sndlist';
            snds.forEach(function (sn) {
                var rowEl = document.createElement('div');
                rowEl.className = 'tbe-sndrow';
                var play = document.createElement('button');
                play.className = 'tbe-mini'; play.textContent = '\u25b6';
                play.addEventListener('click', function () {
                    try { new Audio(sn.url).play().catch(function () {}); } catch (e) {}
                });
                var nm = document.createElement('span');
                nm.textContent = sn.url.split('/').pop();
                nm.style.flex = '1';
                var useB = document.createElement('button');
                useB.className = 'tbe-mini'; useB.textContent = 'Use';
                useB.addEventListener('click', function () { wrap.remove(); onUrl(sn.url); });
                rowEl.appendChild(play); rowEl.appendChild(nm); rowEl.appendChild(useB);
                listEl.appendChild(rowEl);
            });
            panel.appendChild(listEl);
            wrap.appendChild(panel);
            wrap.addEventListener('click', function (e) { if (e.target === wrap) wrap.remove(); });
            document.body.appendChild(wrap);
        })
        .catch(function () { uploadSoundFile(onUrl); });
}

function uploadSoundFile(onUrl) {
    var inp = document.createElement('input');
    inp.type = 'file'; inp.accept = 'audio/*';
    inp.addEventListener('change', function () {
        var f = inp.files && inp.files[0];
        if (!f) return;
        var fd = new FormData();
        fd.append('action', 'upload_sound');
        fd.append('sound', f);
        fd.append('csrf_token', TBE_CSRF);
        fetch('/timer_beta_dl.php', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (j && j.url) onUrl(j.url);
                else (window.pkAlert || alert)(j && j.error ? j.error : 'Upload failed.');
            })
            .catch(function () { (window.pkAlert || alert)('Upload failed.'); });
    });
    inp.click();
}

/* Layout-level custom elements: user-defined <name> = plain-text values, shown
 * when the Screen (layout root) is selected. They live on LAYOUT.customElements
 * and so travel with save and export automatically. Plain text only. */
function renderCustomElements() {
    if (!LAYOUT.customElements || typeof LAYOUT.customElements !== 'object') LAYOUT.customElements = {};
    var ce = LAYOUT.customElements;
    var box = document.createElement('div');
    box.className = 'tbe-variants';   // reuse the bordered-section styling
    var head = document.createElement('div');
    head.className = 'tbe-variants-head';
    head.textContent = 'Custom elements';
    box.appendChild(head);
    var hint = document.createElement('div');
    hint.className = 'tbe-screen-note';
    hint.textContent = 'Define your own <name> values (e.g. sponsor). Use them in any cell\'s text. Plain text only.';
    box.appendChild(hint);

    Object.keys(ce).forEach(function (name) {
        var row = document.createElement('div');
        row.className = 'tbe-ce-row';
        var tag = document.createElement('code');
        tag.className = 'tbe-ce-name';
        tag.textContent = '<' + name + '>';
        var val = document.createElement('input');
        val.type = 'text'; val.value = ce[name]; val.maxLength = 500; val.placeholder = 'value';
        val.addEventListener('change', function () { pushUndo(); ce[name] = val.value; refresh(true); });
        var rm = document.createElement('button');
        rm.className = 'tbe-mini tbe-mini-danger'; rm.textContent = '×'; rm.title = 'Remove ' + name;
        rm.addEventListener('click', function () { pushUndo(); delete ce[name]; refresh(true); renderInspector(); });
        row.appendChild(tag); row.appendChild(val); row.appendChild(rm);
        box.appendChild(row);
    });

    var add = document.createElement('div');
    add.className = 'tbe-ce-add';
    var nameIn = document.createElement('input');
    nameIn.type = 'text'; nameIn.placeholder = 'name (letters/digits)'; nameIn.maxLength = 40;
    var addBtn = document.createElement('button');
    addBtn.className = 'tbe-mini'; addBtn.textContent = '+ Add';
    addBtn.addEventListener('click', function () {
        var nm = nameIn.value.trim();
        if (!/^[a-zA-Z][a-zA-Z0-9]{0,39}$/.test(nm)) { (window.pkAlert || alert)('Name must start with a letter and contain only letters and digits.'); return; }
        if (ELEMENT_BUILTINS.indexOf(nm) !== -1) { (window.pkAlert || alert)('"' + nm + '" is a built-in element name.'); return; }
        pushUndo(); ce[nm] = ce[nm] || ''; refresh(true); renderInspector();
    });
    add.appendChild(nameIn); add.appendChild(addBtn);
    box.appendChild(add);
    insp.appendChild(box);
}

/* Shared named styles: LAYOUT.styles is a name → visual-props map; cells opt
 * in via a "Shared style" dropdown. Editing an entry here restyles every cell
 * that references it, across all screens. */
function renderSharedStyles() {
    if (!LAYOUT.styles || typeof LAYOUT.styles !== 'object') LAYOUT.styles = {};
    var st = LAYOUT.styles;
    var box = document.createElement('div');
    box.className = 'tbe-variants';
    var head = document.createElement('div');
    head.className = 'tbe-variants-head';
    head.textContent = 'Shared styles';
    box.appendChild(head);
    var hint = document.createElement('div');
    hint.className = 'tbe-screen-note';
    hint.textContent = 'Named looks cells can share. Change one here and every cell using it updates, on every screen. A cell\'s own settings still win over its shared style.';
    box.appendChild(hint);

    Object.keys(st).forEach(function (name) {
        var card = document.createElement('div');
        card.className = 'tbe-variant';
        var top = document.createElement('div');
        top.className = 'tbe-variant-top';
        var nm = document.createElement('code');
        nm.className = 'tbe-ce-name';
        nm.textContent = name;
        var rm = document.createElement('button');
        rm.className = 'tbe-mini tbe-mini-danger'; rm.textContent = '×'; rm.title = 'Remove ' + name;
        rm.addEventListener('click', function () { pushUndo(); delete st[name]; refresh(true); renderInspector(); });
        top.appendChild(nm); top.appendChild(rm);
        card.appendChild(top);
        var s = st[name];
        card.appendChild(field('Size (% of screen height)', numInput(s.size, 0.5, 40, 0.1, function (v) { setOrDelete(s, 'size', v); })));
        card.appendChild(field('Bold', boolInput(s.bold, function (v) { setOrDelete(s, 'bold', v); })));
        card.appendChild(field('Colour', colorInput(s.color, function (v) { setOrDelete(s, 'color', v); })));
        card.appendChild(field('Background', colorInput(s.bg, function (v) { setOrDelete(s, 'bg', v); })));
        card.appendChild(field('Align', selInput(s.align || 'center', ['center', 'left', 'right'], function (v) { setOrDelete(s, 'align', v === 'center' ? undefined : v); })));
        box.appendChild(card);
    });

    var add = document.createElement('div');
    add.className = 'tbe-ce-add';
    var nameIn = document.createElement('input');
    nameIn.type = 'text'; nameIn.placeholder = 'name (letters/digits)'; nameIn.maxLength = 32;
    var addBtn = document.createElement('button');
    addBtn.className = 'tbe-mini'; addBtn.textContent = '+ Add';
    addBtn.addEventListener('click', function () {
        var nm = nameIn.value.trim();
        if (!/^[a-zA-Z][a-zA-Z0-9]{0,31}$/.test(nm)) { (window.pkAlert || alert)('Name must start with a letter and contain only letters and digits.'); return; }
        if (Object.keys(st).length >= 20 && !st[nm]) { (window.pkAlert || alert)('A layout can hold at most 20 shared styles.'); return; }
        pushUndo(); st[nm] = st[nm] || {}; refresh(true); renderInspector();
    });
    add.appendChild(nameIn); add.appendChild(addBtn);
    box.appendChild(add);
    insp.appendChild(box);
}

function renderInspector() {
    insp.textContent = '';
    if (selPath === null) {
        inspTitle.textContent = 'Inspector';
        var p = document.createElement('p');
        p.className = 'tbe-empty';
        p.textContent = 'Select something in the preview or the structure tree.';
        insp.appendChild(p);
        return;
    }

    if (selPath === '') {
        inspTitle.textContent = 'Screen background';
        var scr = curScreen();
        scr.bg = scr.bg || {};
        var g = scr.bg.gradient;
        insp.appendChild(field('Solid colour', colorInput(scr.bg.color, function (v) { setOrDelete(scr.bg, 'color', v); })));
        insp.appendChild(field('Gradient from', colorInput(g ? g[0] : '', function (v) {
            scr.bg.gradient = [v, (scr.bg.gradient || ['#000', '#000'])[1]];
        })));
        insp.appendChild(field('Gradient to', colorInput(g ? g[1] : '', function (v) {
            scr.bg.gradient = [(scr.bg.gradient || ['#000', '#000'])[0], v];
        })));
        var clr = document.createElement('button');
        clr.className = 'tbe-mini'; clr.textContent = 'Remove gradient';
        clr.addEventListener('click', function () { pushUndo(); delete scr.bg.gradient; refresh(true); renderInspector(); });
        insp.appendChild(clr);

        // Background image (over the colour/gradient).
        var bgImgWrap = document.createElement('div');
        bgImgWrap.className = 'tbe-field';
        var bgLbl = document.createElement('span'); bgLbl.textContent = 'Background image'; bgImgWrap.appendChild(bgLbl);
        if (scr.bg.image) {
            var th = document.createElement('img'); th.className = 'tbe-img-thumb'; th.src = scr.bg.image; bgImgWrap.appendChild(th);
            bgImgWrap.appendChild(field('Fit', selInput(scr.bg.imageFit || 'cover', ['cover', 'contain'], function (v) { setOrDelete(scr.bg, 'imageFit', v === 'cover' ? undefined : v); })));
            var rmBg = document.createElement('button'); rmBg.className = 'tbe-mini tbe-mini-danger'; rmBg.textContent = 'Remove image';
            rmBg.addEventListener('click', function () { pushUndo(); delete scr.bg.image; delete scr.bg.imageFit; refresh(true); renderInspector(); });
            bgImgWrap.appendChild(rmBg);
        } else {
            var upBg = document.createElement('button'); upBg.className = 'tbe-mini'; upBg.textContent = 'Upload image';
            upBg.addEventListener('click', function () { uploadImage(function (url) { pushUndo(); scr.bg.image = url; refresh(true); renderInspector(); }); });
            bgImgWrap.appendChild(upBg);
        }
        insp.appendChild(bgImgWrap);

        renderSharedStyles();
        renderCustomElements();
        return;
    }

    var node = nodeAt(selPath).node;
    var kind = kindOf(node);

    if (kind === 'cell') {
        inspTitle.textContent = 'Cell';
        var cell = node.cell;

        // Image cell: an uploaded picture instead of text. When set, the text /
        // element fields are hidden; removing the image returns it to a text cell.
        if (cell.image) {
            var imgWrap = document.createElement('div');
            imgWrap.className = 'tbe-field';
            var il = document.createElement('span'); il.textContent = 'Image'; imgWrap.appendChild(il);
            var thumb = document.createElement('img'); thumb.className = 'tbe-img-thumb'; thumb.src = cell.image; imgWrap.appendChild(thumb);
            insp.appendChild(imgWrap);
            insp.appendChild(field('Fit', selInput(cell.imageFit || 'contain', ['contain', 'cover'], function (v) { setOrDelete(cell, 'imageFit', v === 'contain' ? undefined : v); })));
            insp.appendChild(field('Weight (share of space)', numInput(node.weight, 0, 50, 0.1, function (v) { setOrDelete(node, 'weight', v); })));
            insp.appendChild(field('Show when', condEditor(cell.when, function (v) { pushUndo(); setOrDelete(cell, 'when', v); refresh(true); })));
            var rmImg = document.createElement('button'); rmImg.className = 'tbe-mini tbe-mini-danger'; rmImg.textContent = 'Remove image (back to text)';
            rmImg.addEventListener('click', function () { pushUndo(); delete cell.image; delete cell.imageFit; refresh(true); renderInspector(); });
            insp.appendChild(rmImg);
            return;
        }

        // QR cell: a code another screen scans to join this display. There is
        // deliberately no URL field — the target is an enum and the renderer
        // builds the link from the game's own key, so a layout you share can
        // never send someone's scanner somewhere you chose. See SECURITY note
        // in timer_beta_dl.php's sanitizer.
        if (cell.chips) {
            var chWrap = document.createElement('div');
            chWrap.className = 'tbe-field';
            var chl = document.createElement('span'); chl.textContent = 'Chip legend'; chWrap.appendChild(chl);
            var chn = document.createElement('div'); chn.className = 'tbe-note';
            chn.textContent = 'Shows this game\'s chip denominations and their colours. Set them in '
                            + 'the check-in Setup editor, under Game — the layout only says WHERE to '
                            + 'show them. The preview uses sample chips.';
            chWrap.appendChild(chn);
            insp.appendChild(chWrap);
            insp.appendChild(field('Weight (share of space)', numInput(node.weight, 0, 50, 0.1, function (v) { setOrDelete(node, 'weight', v); })));
            insp.appendChild(field('Show when', condEditor(cell.when, function (v) { pushUndo(); setOrDelete(cell, 'when', v); refresh(true); })));
            var rmCh = document.createElement('button'); rmCh.className = 'tbe-mini tbe-mini-danger';
            rmCh.textContent = 'Remove chip legend (back to text)';
            rmCh.addEventListener('click', function () { pushUndo(); delete cell.chips; refresh(true); renderInspector(); });
            insp.appendChild(rmCh);
            return;
        }

        if (cell.seats) {
            var stWrap = document.createElement('div');
            stWrap.className = 'tbe-field';
            var stl = document.createElement('span'); stl.textContent = 'Seat map'; stWrap.appendChild(stl);
            var stn = document.createElement('div'); stn.className = 'tbe-note';
            stn.textContent = 'Every remaining player at their assigned seat — avatar, name, seat '
                            + 'number. Pair it with a screen condition like playersLeft <= 10 for a '
                            + 'final-table view. The preview shows sample players.';
            stWrap.appendChild(stn);
            insp.appendChild(stWrap);
            var tno = document.createElement('input');
            tno.type = 'number'; tno.min = '1'; tno.max = '50'; tno.placeholder = 'auto (busiest table)';
            tno.value = cell.table !== undefined ? cell.table : '';
            tno.addEventListener('change', function () {
                pushUndo();
                var v = parseInt(tno.value, 10);
                if (isFinite(v) && v > 0) cell.table = v; else delete cell.table;
                refresh(true);
            });
            insp.appendChild(field('Table number (blank = auto)', tno));
            insp.appendChild(field('Weight (share of space)', numInput(node.weight, 0, 50, 0.1, function (v) { setOrDelete(node, 'weight', v); })));
            insp.appendChild(field('Show when', condEditor(cell.when, function (v) { pushUndo(); setOrDelete(cell, 'when', v); refresh(true); })));
            var rmSt = document.createElement('button'); rmSt.className = 'tbe-mini tbe-mini-danger';
            rmSt.textContent = 'Remove seat map (back to text)';
            rmSt.addEventListener('click', function () { pushUndo(); delete cell.seats; delete cell.table; refresh(true); renderInspector(); });
            insp.appendChild(rmSt);
            return;
        }

        if (cell.qr) {
            var qrWrap = document.createElement('div');
            qrWrap.className = 'tbe-field';
            var ql = document.createElement('span'); ql.textContent = 'QR code'; qrWrap.appendChild(ql);
            var qn = document.createElement('div'); qn.className = 'tbe-note';
            qn.textContent = 'Scanning it opens this timer on the scanning device, counting in step with this screen. '
                           + 'Viewing needs no login; starting or pausing still needs host rights on that device. '
                           + 'The preview shows a placeholder — a real code only exists once the layout is on a live game.';
            qrWrap.appendChild(qn);
            insp.appendChild(qrWrap);
            insp.appendChild(field('Weight (share of space)', numInput(node.weight, 0, 50, 0.1, function (v) { setOrDelete(node, 'weight', v); })));
            insp.appendChild(field('Show when', condEditor(cell.when, function (v) { pushUndo(); setOrDelete(cell, 'when', v); refresh(true); })));
            var rmQr = document.createElement('button'); rmQr.className = 'tbe-mini tbe-mini-danger';
            rmQr.textContent = 'Remove QR (back to text)';
            rmQr.addEventListener('click', function () { pushUndo(); delete cell.qr; refresh(true); renderInspector(); });
            insp.appendChild(rmQr);
            return;
        }

        var toImg = document.createElement('button');
        toImg.className = 'tbe-mini'; toImg.textContent = 'Use an image instead';
        toImg.addEventListener('click', function () { uploadImage(function (url) { pushUndo(); cell.image = url; refresh(true); renderInspector(); }); });
        insp.appendChild(toImg);

        var toQr = document.createElement('button');
        toQr.className = 'tbe-mini'; toQr.textContent = 'Use a QR code instead';
        toQr.title = 'A code viewers scan to open this timer on their own screen';
        toQr.addEventListener('click', function () { pushUndo(); cell.qr = 'display'; refresh(true); renderInspector(); });
        insp.appendChild(toQr);

        var toChips = document.createElement('button');
        toChips.className = 'tbe-mini'; toChips.textContent = 'Use a chip legend instead';
        toChips.title = "This game's chip denominations and colours";
        toChips.addEventListener('click', function () { pushUndo(); cell.chips = true; refresh(true); renderInspector(); });
        insp.appendChild(toChips);

        var toSeats = document.createElement('button');
        toSeats.className = 'tbe-mini'; toSeats.textContent = 'Use a seat map instead';
        toSeats.title = 'The final table: every remaining player at their assigned seat';
        toSeats.addEventListener('click', function () { pushUndo(); cell.seats = true; refresh(true); renderInspector(); });
        insp.appendChild(toSeats);

        var ta = document.createElement('textarea');
        ta.rows = 3; ta.value = cell.text || '';
        ta.addEventListener('change', function () { pushUndo(); cell.text = ta.value; refresh(true); });
        insp.appendChild(field('Text', ta));

        var elRow = document.createElement('div');
        elRow.className = 'tbe-elrow';
        var elSel = document.createElement('select');
        var opt0 = document.createElement('option');
        opt0.value = ''; opt0.textContent = 'Insert element…';
        elSel.appendChild(opt0);
        // Options show each element's LIVE value from the renderer ("<clock> —
        // 12:31"), and the list itself comes from the renderer, so the picker
        // can never offer an element the engine doesn't have.
        var vals = (PV && PV.elementValues) ? PV.elementValues() : {};
        ELEMENTS.forEach(function (t) {
            var o = document.createElement('option');
            o.value = t;
            var v = vals[t];
            o.textContent = '<' + t + '>' + (v ? '  —  ' + v.replace(/\n/g, ' ').slice(0, 22) : '');
            if (ELEMENT_DESC[t]) o.title = ELEMENT_DESC[t];
            elSel.appendChild(o);
        });
        elSel.addEventListener('change', function () {
            if (!elSel.value) return;
            pushUndo();
            var at = (typeof ta.selectionStart === 'number') ? ta.selectionStart : ta.value.length;
            ta.value = ta.value.slice(0, at) + '<' + elSel.value + '>' + ta.value.slice(at);
            cell.text = ta.value;
            elSel.value = '';
            refresh(true);
        });
        elRow.appendChild(elSel);
        insp.appendChild(elRow);

        var styleNames = Object.keys(LAYOUT.styles || {});
        if (styleNames.length || cell.style) {
            insp.appendChild(field('Shared style', selInput(cell.style || '', [''].concat(styleNames), function (v) {
                setOrDelete(cell, 'style', v || undefined);
            }, ['(none)'].concat(styleNames))));
        }
        insp.appendChild(field('Fit to box', boolInput(cell.fit, function (v) { setOrDelete(cell, 'fit', v); renderInspector(); })));
        if (!cell.fit) insp.appendChild(field('Size (% of screen height)', numInput(cell.size, 0.5, 40, 0.1, function (v) { setOrDelete(cell, 'size', v); })));
        insp.appendChild(field('Bold', boolInput(cell.bold, function (v) { setOrDelete(cell, 'bold', v); })));
        insp.appendChild(field('Colour', colorInput(cell.color, function (v) { setOrDelete(cell, 'color', v); })));
        insp.appendChild(field('Background', colorInput(cell.bg, function (v) { setOrDelete(cell, 'bg', v); })));
        insp.appendChild(field('Align', selInput(cell.align || 'center', ['center', 'left', 'right'], function (v) { setOrDelete(cell, 'align', v === 'center' ? undefined : v); })));
        insp.appendChild(field('Padding', textInput(cell.pad, function (v) { setOrDelete(cell, 'pad', v); }, 'e.g. 0.6vh 1vw')));
        insp.appendChild(field('Show when', condEditor(cell.when, function (v) { pushUndo(); setOrDelete(cell, 'when', v); refresh(true); })));
        insp.appendChild(field('Clock colours (warn/critical)', boolInput(cell.clockColors, function (v) { setOrDelete(cell, 'clockColors', v); })));
        insp.appendChild(field('Weight (share of space)', numInput(node.weight, 0, 50, 0.1, function (v) { setOrDelete(node, 'weight', v); })));
        renderElStyles(cell);
        renderVariants(cell);
    } else {
        inspTitle.textContent = kind === 'row' ? 'Row' : 'Column';
        insp.appendChild(field('Weight (share of space)', numInput(node.weight, 0, 50, 0.1, function (v) { setOrDelete(node, 'weight', v); })));
        insp.appendChild(field('Gap', textInput(node.gap, function (v) { setOrDelete(node, 'gap', v); }, 'e.g. 0.5vh')));
        insp.appendChild(field('Padding', textInput(node.pad, function (v) { setOrDelete(node, 'pad', v); }, 'e.g. 1vh 1vw')));
        insp.appendChild(field('Background', colorInput(node.bg, function (v) { setOrDelete(node, 'bg', v); })));
        insp.appendChild(field('Show when', condEditor(node.when, function (v) { pushUndo(); setOrDelete(node, 'when', v); refresh(true); })));
        insp.appendChild(field('Justify', selInput(node.justify || 'flex-start',
            ['flex-start', 'center', 'flex-end', 'space-between', 'space-around'],
            function (v) { setOrDelete(node, 'justify', v === 'flex-start' ? undefined : v); },
            ['start', 'center', 'end', 'space between', 'space around'])));
    }
}

/* ── Structure operations ────────────────────────────────────────────── */

// Insert into the selected container, or after the selected cell.
function insertNode(newNode) {
    pushUndo();
    if (selPath === null || selPath === '') {
        (curScreen().root.row || curScreen().root.col).push(newNode);
        selPath = '' + ((curScreen().root.row || curScreen().root.col).length - 1);
    } else {
        var n = nodeAt(selPath).node;
        if (n.row || n.col) {
            (n.row || n.col).push(newNode);
            selPath = selPath + '.' + ((n.row || n.col).length - 1);
        } else {
            var p = parentOf(selPath);
            p.list.splice(p.index + 1, 0, newNode);
            selPath = selPath.replace(/\d+$/, String(p.index + 1));
        }
    }
    refresh();
    select(selPath);
}

function newCellNode() { return { cell: { text: 'New cell', size: 2.4, color: '#ffffff' } }; }
function newRowNode()  { return { row: [{ cell: { text: 'Left', size: 2.4, color: '#ffffff' } }, { cell: { text: 'Right', size: 2.4, color: '#ffffff' } }], weight: 1 }; }
function newColNode()  { return { col: [{ cell: { text: 'Top', size: 2.4, color: '#ffffff' } }, { cell: { text: 'Bottom', size: 2.4, color: '#ffffff' } }], weight: 1 }; }
document.getElementById('tbeAddCell').addEventListener('click', function () { insertNode(newCellNode()); });
document.getElementById('tbeAddRow').addEventListener('click', function () { insertNode(newRowNode()); });
document.getElementById('tbeAddCol').addEventListener('click', function () { insertNode(newColNode()); });
// Named so the toolbar and the right-click menu drive the SAME operation —
// two implementations of "duplicate" would drift.
function dupNode() {
    if (!selPath) return;
    pushUndo();
    var p = parentOf(selPath);
    p.list.splice(p.index + 1, 0, JSON.parse(JSON.stringify(p.list[p.index])));
    refresh(); select(selPath);
}
// Node clipboard: a JSON snapshot, so it survives screen switches and layout
// loads — copy a cell from one layout, load another, paste it there.
var nodeClipboard = null;
function copyNode() {
    if (selPath === null || selPath === '') return;
    nodeClipboard = JSON.stringify(nodeAt(selPath).node);
}
function cutNode() {
    if (selPath === null || selPath === '') return;
    nodeClipboard = JSON.stringify(nodeAt(selPath).node);
    removeNode();
}
function pasteNode() {
    if (!nodeClipboard) return;
    // insertNode's placement rules are exactly paste's: into a selected
    // container, after a selected cell, onto the root when nothing is picked.
    insertNode(JSON.parse(nodeClipboard));
    renderInspector();
}

function removeNode() {
    if (!selPath) return;
    pushUndo();
    var p = parentOf(selPath);
    p.list.splice(p.index, 1);
    selPath = null;
    refresh();
    renderInspector();
}
document.getElementById('tbeDup').addEventListener('click', dupNode);
document.getElementById('tbeRemove').addEventListener('click', removeNode);
function move(delta) {
    if (!selPath) return;
    var p = parentOf(selPath);
    var to = p.index + delta;
    if (to < 0 || to >= p.list.length) return;
    pushUndo();
    var n = p.list.splice(p.index, 1)[0];
    p.list.splice(to, 0, n);
    selPath = selPath.replace(/\d+$/, String(to));
    refresh(); select(selPath);
}
document.getElementById('tbeUp').addEventListener('click', function () { move(-1); });
document.getElementById('tbeDown').addEventListener('click', function () { move(1); });

/* ── Right-click menu: preview and structure tree ─────────────────────────
 * Both surfaces show the same tree, so both get the same menu, and it acts on
 * the node you POINTED AT rather than whatever happened to be selected — it
 * selects first, then runs the ordinary operation, so the toolbar and the menu
 * can never diverge. */
var tbeMenu = null, tbeSub = null;
function closeSubMenu() { if (tbeSub) tbeSub.style.display = 'none'; }
function closeNodeMenu() { if (tbeMenu) tbeMenu.style.display = 'none'; closeSubMenu(); }

/* Submenu panel. One element, rebuilt per open, anchored beside the row that
 * spawned it and flipped to its left when there is no room on the right. */
function openSubMenu(anchor, build) {
    if (!tbeSub) {
        tbeSub = document.createElement('div');
        tbeSub.className = 'tbe-menu tbe-submenu';
        document.body.appendChild(tbeSub);
    }
    var sm = tbeSub;
    sm.textContent = '';

    function addRow(label, on, fn) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'tbe-menu-item' + (on ? ' tbe-menu-check on' : '');
        b.textContent = label;
        b.addEventListener('click', function () { closeNodeMenu(); fn(); });
        sm.appendChild(b);
    }
    function addSwatches(list, current, pick) {
        var row = document.createElement('div');
        row.className = 'tbe-swatches';
        list.forEach(function (c) {
            var sw = document.createElement('button');
            sw.type = 'button';
            sw.className = 'tbe-swatch' + (current === c ? ' on' : '');
            sw.style.background = c;
            sw.title = c;
            sw.addEventListener('click', function () { closeNodeMenu(); pick(c); });
            row.appendChild(sw);
        });
        sm.appendChild(row);
    }

    build(sm, addRow, addSwatches);
    sm.style.display = 'block';
    sm.style.left = '0px'; sm.style.top = '0px';
    var r = anchor.getBoundingClientRect();
    var w = sm.offsetWidth, h = sm.offsetHeight;
    var left = r.right + 2;
    if (left + w > window.innerWidth - 6) left = Math.max(4, r.left - w - 2);
    sm.style.left = left + 'px';
    sm.style.top = Math.max(4, Math.min(r.top, window.innerHeight - h - 6)) + 'px';
}
function nodeMenuEl() {
    if (tbeMenu) return tbeMenu;
    tbeMenu = document.createElement('div');
    tbeMenu.className = 'tbe-menu';
    document.body.appendChild(tbeMenu);
    // Dismissal lives at document level because the menu is body-level: it has
    // to escape both the tree's scroll box and the preview iframe.
    document.addEventListener('mousedown', function (e) {
        if (tbeMenu.contains(e.target)) return;
        if (tbeSub && tbeSub.contains(e.target)) return;   // the submenu is part of the menu
        closeNodeMenu();
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeNodeMenu(); });
    window.addEventListener('resize', closeNodeMenu);
    window.addEventListener('scroll', closeNodeMenu, true);
    return tbeMenu;
}

/* The vocabulary below mirrors pk_layout_sanitize() exactly. Anything the
 * sanitizer would drop must not be offered here — a menu that writes a value
 * the server silently strips is worse than no menu. */
var PALETTE = ['#ffffff', '#e2e8f0', '#94a3b8', '#0f172a', '#2563eb', '#60a5fa',
               '#22c55e', '#f59e0b', '#ef4444', '#a855f7'];
var PADS    = [['None', undefined], ['Small', '0.5vh 0.5vw'], ['Medium', '1vh 1vw'], ['Large', '2vh 2vw']];
var GAPS    = [['None', undefined], ['Small', '0.5vh'], ['Medium', '1vh'], ['Large', '2vh']];
var SPACING = [['None', undefined], ['Tight', '-0.02em'], ['Wide', '0.08em']];
var OPACITY = [['100%', undefined], ['75%', 0.75], ['50%', 0.5], ['25%', 0.25]];
var BORDERS = [['None', undefined], ['Thin', '1px solid rgba(255,255,255,0.35)'],
               ['Medium', '2px solid rgba(255,255,255,0.55)'], ['Accent', '2px solid #2563eb']];
var JUSTIFY = [['Start', 'flex-start'], ['Center', 'center'], ['End', 'flex-end'],
               ['Space between', 'space-between'], ['Space around', 'space-around']];
var ALIGNS  = [['Left', 'left'], ['Center', 'center'], ['Right', 'right']];

function openNodeMenu(path, x, y) {
    select(path);
    var m = nodeMenuEl();
    m.textContent = '';
    closeSubMenu();
    var isRoot = (path === '' || path === null);
    var node = nodeAt(path).node;
    var container = !!(node.row || node.col);
    var cell = node.cell;

    // Every property edit is one undo step and one re-render, so the menu can
    // never leave the preview showing something the layout does not say.
    function edit(fn) {
        return function () { pushUndo(); fn(); refresh(true); renderInspector(); };
    }

    function item(label, fn, cls) {
        if (label === null) {
            var sep = document.createElement('div');
            sep.className = 'tbe-menu-sep';
            m.appendChild(sep);
            return;
        }
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'tbe-menu-item' + (cls ? ' ' + cls : '');
        b.textContent = label;
        b.addEventListener('mouseenter', closeSubMenu);
        b.addEventListener('click', function () { closeNodeMenu(); fn(); });
        m.appendChild(b);
        return b;
    }

    // A checkable row: the tick IS the current value, so the menu doubles as a
    // readout of what the node already has.
    function toggle(label, on, fn) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'tbe-menu-item tbe-menu-check' + (on ? ' on' : '');
        b.textContent = label;
        b.addEventListener('mouseenter', closeSubMenu);
        b.addEventListener('click', function () { closeNodeMenu(); fn(); });
        m.appendChild(b);
    }

    // Submenu: choices open beside the parent row rather than making the menu
    // twenty items long.
    function sub(label, build) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'tbe-menu-item tbe-menu-sub';
        b.textContent = label;
        b.addEventListener('mouseenter', function () { openSubMenu(b, build); });
        b.addEventListener('click', function (e) { e.stopPropagation(); openSubMenu(b, build); });
        m.appendChild(b);
    }

    // Choice list for an enum-ish property; current value is ticked.
    function choices(list, current, apply) {
        return function (panel, addRow) {
            list.forEach(function (opt) {
                addRow(opt[0], opt[1] === current || (opt[1] === undefined && current === undefined),
                       edit(function () { apply(opt[1]); }));
            });
        };
    }

    // Colour picker: swatches, a native picker for anything else, and a way
    // back to "not set" (which is not the same as white).
    function colourPanel(current, apply, clearLabel) {
        return function (panel, addRow, addSwatches) {
            addSwatches(PALETTE, current, function (c) { edit(function () { apply(c); })(); });
            addRow('Custom…', false, function () {
                var inp = document.createElement('input');
                inp.type = 'color';
                inp.value = /^#[0-9a-f]{6}$/i.test(current || '') ? current : '#ffffff';
                inp.addEventListener('change', function () { edit(function () { apply(inp.value); })(); });
                inp.click();
            });
            addRow(clearLabel || 'Default', current === undefined, edit(function () { apply(undefined); }));
        };
    }

    // insertNode() puts a node INSIDE a container and AFTER a cell, so the
    // labels say which will happen rather than making you find out.
    var where = container ? ' inside' : ' after';
    item('Add cell' + where,   function () { insertNode(newCellNode()); });
    item('Add row' + where,    function () { insertNode(newRowNode()); });
    item('Add column' + where, function () { insertNode(newColNode()); });

    if (cell) {
        item(null);
        // A cell is text, an image, or a QR. The inspector hides the text
        // fields for the other two because they do not apply; the menu mirrors
        // that rather than offering settings with no effect.
        if (cell.image) {
            sub('Image fit', choices([['Contain', 'contain'], ['Cover', 'cover']],
                cell.imageFit || 'contain',
                function (v) { setOrDelete(cell, 'imageFit', v === 'contain' ? undefined : v); }));
            item('Replace image…', function () {
                uploadImage(function (url) { pushUndo(); cell.image = url; refresh(true); renderInspector(); });
            });
            item('Remove image (back to text)', edit(function () { delete cell.image; delete cell.imageFit; }));
        } else if (cell.qr) {
            item('Remove QR code (back to text)', edit(function () { delete cell.qr; }));
        } else if (cell.chips) {
            item('Remove chip legend (back to text)', edit(function () { delete cell.chips; }));
        } else if (cell.seats) {
            item('Remove seat map (back to text)', edit(function () { delete cell.seats; delete cell.table; }));
        } else {
            toggle('Bold', !!cell.bold, edit(function () { setOrDelete(cell, 'bold', cell.bold ? undefined : true); }));
            toggle('Fit text to box', !!cell.fit, edit(function () { setOrDelete(cell, 'fit', cell.fit ? undefined : true); }));
            toggle('Clock colours', !!cell.clockColors, edit(function () { setOrDelete(cell, 'clockColors', cell.clockColors ? undefined : true); }));
            sub('Align', choices(ALIGNS, cell.align || 'center', function (v) {
                setOrDelete(cell, 'align', v === 'center' ? undefined : v);
            }));
            sub('Text size', function (panel, addRow) {
                var cur = typeof cell.size === 'number' ? cell.size : 2.4;
                addRow('Bigger',  false, edit(function () { delete cell.fit; cell.size = Math.min(40, r2(cur * 1.25)); }));
                addRow('Smaller', false, edit(function () { delete cell.fit; cell.size = Math.max(0.5, r2(cur / 1.25)); }));
                addRow('Reset (2.4)', cur === 2.4, edit(function () { cell.size = 2.4; }));
            });
            sub('Colour',         colourPanel(cell.color, function (v) { setOrDelete(cell, 'color', v); }));
            sub('Letter spacing', choices(SPACING, cell.spacing, function (v) { setOrDelete(cell, 'spacing', v); }));
            var styleNames = Object.keys((LAYOUT && LAYOUT.styles) || {});
            if (styleNames.length) {
                sub('Shared style', function (panel, addRow) {
                    addRow('None', !cell.style, edit(function () { setOrDelete(cell, 'style', undefined); }));
                    styleNames.forEach(function (n) {
                        addRow(n, cell.style === n, edit(function () { cell.style = n; }));
                    });
                });
            }
            item(null);
            item('Use an image instead…', function () {
                uploadImage(function (url) { pushUndo(); cell.image = url; refresh(true); renderInspector(); });
            });
            item('Use a QR code instead', edit(function () { cell.qr = 'display'; }));
            item('Use a chip legend instead', edit(function () { cell.chips = true; }));
            item('Use a seat map instead', edit(function () { cell.seats = true; }));
        }
        // Box properties apply whatever the cell holds.
        sub('Background', colourPanel(cell.bg, function (v) { setOrDelete(cell, 'bg', v); }, 'None'));
        sub('Box image', boxImagePanel(cell));
        sub('Padding',    choices(PADS,    cell.pad,     function (v) { setOrDelete(cell, 'pad', v); }));
        sub('Opacity',    choices(OPACITY, cell.opacity, function (v) { setOrDelete(cell, 'opacity', v); }));
        sub('Border',     choices(BORDERS, cell.border,  function (v) { setOrDelete(cell, 'border', v); }));
    }

    // A box's OWN background image: plate art that moves and resizes with the
    // box, so a value can never misalign with the artwork behind it. Offered on
    // cells and containers alike; default fit is Stretch because plate art is
    // drawn for the box it decorates.
    function boxImagePanel(target) {
        return function (panel, addRow) {
            addRow(target.bgImage ? 'Change image…' : 'Image…', false, function () {
                uploadImage(function (url) {
                    pushUndo();
                    target.bgImage = url;
                    refresh(true); renderInspector();
                });
            });
            if (target.bgImage) {
                addRow('Fit: Stretch', (target.bgImageFit || 'stretch') === 'stretch',
                    edit(function () { delete target.bgImageFit; }));
                addRow('Fit: Cover', target.bgImageFit === 'cover',
                    edit(function () { target.bgImageFit = 'cover'; }));
                addRow('Fit: Contain', target.bgImageFit === 'contain',
                    edit(function () { target.bgImageFit = 'contain'; }));
                addRow('Remove image', false,
                    edit(function () { delete target.bgImage; delete target.bgImageFit; }));
            }
        };
    }

    // The SCREEN's background belongs to the screen, not to any node, so it is
    // offered where you would look for it: right-clicking the screen itself.
    if (isRoot) {
        item(null);
        sub('Screen background', function (panel, addRow, addSwatches) {
            var bg = curScreen().bg || (curScreen().bg = {});
            addSwatches(PALETTE, bg.color, function (c) {
                edit(function () { (curScreen().bg || (curScreen().bg = {})).color = c; })();
            });
            addRow('Image…', false, function () {
                uploadImage(function (url) {
                    pushUndo();
                    var b2 = curScreen().bg || (curScreen().bg = {});
                    b2.image = url;
                    refresh(true); renderInspector();
                });
            });
            if (bg.image) {
                addRow('Fit: Cover',   (bg.imageFit || 'cover') === 'cover',
                    edit(function () { delete curScreen().bg.imageFit; }));
                addRow('Fit: Contain', bg.imageFit === 'contain',
                    edit(function () { curScreen().bg.imageFit = 'contain'; }));
                // Distorts the picture, but the picture then covers exactly the
                // area the layout does, so anything drawn into it stays put on
                // a screen the design was not made for.
                addRow('Fit: Stretch', bg.imageFit === 'stretch',
                    edit(function () { curScreen().bg.imageFit = 'stretch'; }));
                addRow('Remove image', false,
                    edit(function () { delete curScreen().bg.image; delete curScreen().bg.imageFit; }));
            }
        });
        // A design drawn for one shape has to keep it. Whole-layout, like the
        // panel colours below: a screen that letterboxed while its neighbour
        // filled would jump on every rotation.
        sub('Screen shape', function (panel, addRow) {
            var cur = +LAYOUT.aspect || 0;
            function pick(v) { return edit(function () { if (v) LAYOUT.aspect = v; else delete LAYOUT.aspect; })(); }
            addRow('Fill the screen', !cur, function () { pick(0); });
            addRow('Keep 16:10', cur === 1.6, function () { pick(1.6); });
            addRow('Keep 16:9', cur === 1.78, function () { pick(1.78); });
            addRow('Keep 4:3',  cur === 1.33, function () { pick(1.33); });
        });
        // Applies to the whole layout, not this screen: it is one look, and
        // half-and-half is never what anyone wants.
        sub('Panel colours', function (panel, addRow) {
            var on = panelColoursOn();
            addRow('Keep the painted panels', on, function () { setPanelColours(true); });
            addRow('Let the artwork show through', !on, function () { setPanelColours(false); });
        });
    }

    if (container) {
        item(null);
        sub('Justify', choices(JUSTIFY, node.justify, function (v) { setOrDelete(node, 'justify', v); }));
        sub('Gap',     choices(GAPS,    node.gap,     function (v) { setOrDelete(node, 'gap', v); }));
        sub('Padding', choices(PADS,    node.pad,     function (v) { setOrDelete(node, 'pad', v); }));
        sub('Background', colourPanel(node.bg, function (v) { setOrDelete(node, 'bg', v); }, 'None'));
        sub('Box image', boxImagePanel(node));
        sub('Border',  choices(BORDERS, node.border,  function (v) { setOrDelete(node, 'border', v); }));
    }

    if (!isRoot) {
        // Weight is the share of the parent, so it applies to cells and
        // containers alike — the same number the resize handles write.
        sub('Size in parent', function (panel, addRow) {
            var w = typeof node.weight === 'number' ? node.weight : null;
            addRow('More',  false, edit(function () { node.weight = r2(Math.min(50, (w === null ? 1 : w) + 0.5)); }));
            addRow('Less',  false, edit(function () { node.weight = r2(Math.max(0, (w === null ? 1 : w) - 0.5)); }));
            addRow('Content-sized', w === null, edit(function () { delete node.weight; }));
        });
    }

    if (!isRoot) {
        var p = parentOf(path);
        item(null);
        item('Duplicate', dupNode);
        item('Copy', copyNode);
        item('Cut', cutNode);
        // Held by reference, not by index: this menu grew from seven rows to
        // thirty and any positional lookup would silently disable the wrong one.
        var upBtn = item('Move up',   function () { move(-1); });
        var dnBtn = item('Move down', function () { move(1); });
        if (p && p.index === 0) { upBtn.disabled = true; upBtn.title = 'Already first in its container.'; }
        if (p && p.index === p.list.length - 1) { dnBtn.disabled = true; dnBtn.title = 'Already last in its container.'; }
        item(null);
        item('Delete', removeNode, 'tbe-menu-danger');
    }

    // Paste is offered everywhere, root included — that is how a copied cell
    // lands on an empty screen.
    var pasteBtn = item('Paste', pasteNode);
    if (!nodeClipboard) { pasteBtn.disabled = true; pasteBtn.title = 'Nothing copied yet — Copy or Cut a box first.'; }

    item(null);
    var undoBtn = item('Undo last change', doUndo);
    if (!undoStack.length) { undoBtn.disabled = true; undoBtn.title = 'Nothing to undo yet.'; }

    // Show first so it can be measured, then keep it inside the viewport.
    m.style.display = 'block';
    m.style.left = '0px'; m.style.top = '0px';
    m.style.left = Math.max(4, Math.min(x, window.innerWidth  - m.offsetWidth  - 6)) + 'px';
    m.style.top  = Math.max(4, Math.min(y, window.innerHeight - m.offsetHeight - 6)) + 'px';
}

// Structure tree.
document.getElementById('tbeTree').addEventListener('contextmenu', function (e) {
    var row = e.target.closest ? e.target.closest('[data-tpath]') : null;
    if (!row) return;
    e.preventDefault();
    openNodeMenu(row.getAttribute('data-tpath'), e.clientX, e.clientY);
});

/* ── Drag a boundary to resize ─────────────────────────────────────────────
 * Handles live in an overlay INSIDE the iframe, so they sit exactly over the
 * boundaries they control, but the layout data and the undo stack stay here —
 * the renderer is the shipping display and must not learn about editing.
 *
 * The overlay is appended to the iframe's <body>, deliberately NOT to #tbRoot:
 * buildScreen() clears #tbRoot on every re-render, which would take the handles
 * with it.
 *
 * Only the two neighbours either side of a boundary are touched, and their
 * combined weight is held constant, so dragging one boundary cannot disturb the
 * rest of the container. */
var MIN_PX = 8;
var dragState = null;

function overlayLayer() {
    var doc = frame.contentDocument;
    if (!doc || !doc.body) return null;
    var layer = doc.getElementById('tbeOverlay');
    if (layer) return layer;
    var st = doc.createElement('style');
    st.textContent =
        '#tbeOverlay{position:fixed;inset:0;pointer-events:none;z-index:60}' +
        '.tbe-h{position:fixed;pointer-events:auto;background:transparent;transition:background .12s}' +
        '.tbe-h:hover,.tbe-h.on{background:rgba(59,130,246,.85)}' +
        '.tbe-h-v{cursor:col-resize}.tbe-h-h{cursor:row-resize}';
    doc.head.appendChild(st);
    layer = doc.createElement('div');
    layer.id = 'tbeOverlay';
    doc.body.appendChild(layer);
    return layer;
}

function kidsOf(el) {
    return [].filter.call(el.children, function (k) {
        return k.nodeType === 1 && k.hasAttribute('data-path');
    });
}

// Rebuilt after every refresh: the boxes have moved, so the handles must.
function mountResizeHandles() {
    var doc = frame.contentDocument, layer = overlayLayer();
    if (!doc || !layer) return;
    layer.textContent = '';
    [].forEach.call(doc.querySelectorAll('.tb-row, .tb-col'), function (c) {
        var isRow = c.classList.contains('tb-row');
        var kids = kidsOf(c);
        for (var i = 0; i + 1 < kids.length; i++) {
            var ra = kids[i].getBoundingClientRect(), rb = kids[i + 1].getBoundingClientRect();
            // A zero-sized neighbour (a cell hidden by its condition) has no
            // boundary worth grabbing.
            if ((isRow ? ra.width : ra.height) < 2 || (isRow ? rb.width : rb.height) < 2) continue;
            var h = doc.createElement('div');
            h.className = 'tbe-h ' + (isRow ? 'tbe-h-v' : 'tbe-h-h');
            if (isRow) {
                h.style.left = ((ra.right + rb.left) / 2 - 3) + 'px';
                h.style.top = ra.top + 'px';
                h.style.width = '6px';
                h.style.height = ra.height + 'px';
            } else {
                h.style.top = ((ra.bottom + rb.top) / 2 - 3) + 'px';
                h.style.left = ra.left + 'px';
                h.style.height = '6px';
                h.style.width = ra.width + 'px';
            }
            h.setAttribute('data-a', kids[i].getAttribute('data-path'));
            h.setAttribute('data-b', kids[i + 1].getAttribute('data-path'));
            h.setAttribute('data-axis', isRow ? 'x' : 'y');
            h.addEventListener('pointerdown', startResize);
            layer.appendChild(h);
        }
    });
}

function num(v) { return typeof v === 'number' && isFinite(v) ? v : null; }
function r2(v) { return Math.round(v * 100) / 100; }

function startResize(e) {
    if (e.button !== 0) return;
    var doc = frame.contentDocument;
    var h = e.currentTarget;
    var pa = h.getAttribute('data-a'), pb = h.getAttribute('data-b');
    var elA = doc.querySelector('[data-path="' + pa + '"]');
    var elB = doc.querySelector('[data-path="' + pb + '"]');
    if (!elA || !elB) return;
    var horiz = h.getAttribute('data-axis') === 'x';
    var ra = elA.getBoundingClientRect(), rb = elB.getBoundingClientRect();
    var sizeA = horiz ? ra.width : ra.height;
    var sizeB = horiz ? rb.width : rb.height;
    var a = nodeAt(pa).node, b = nodeAt(pb).node;

    // A neighbour with no weight is content-sized, so there is no ratio to
    // adjust yet. Give both a weight matching what is ALREADY on screen, so the
    // first pixel of the drag moves the boundary and nothing else.
    var wA = num(a.weight), wB = num(b.weight);
    if (wA === null || wB === null || wA + wB <= 0) {
        var total0 = sizeA + sizeB || 1;
        wA = r2(2 * sizeA / total0);
        wB = r2(2 - wA);
    }

    pushUndo();   // one undo step for the whole drag, taken before it starts
    dragState = {
        a: a, b: b, elA: elA, elB: elB, horiz: horiz, handle: h,
        start: horiz ? e.clientX : e.clientY,
        sizeA: sizeA, total: sizeA + sizeB, totalW: wA + wB
    };
    h.classList.add('on');
    h.setPointerCapture(e.pointerId);
    h.addEventListener('pointermove', moveResize);
    h.addEventListener('pointerup', endResize);
    h.addEventListener('pointercancel', endResize);
    e.preventDefault();
}

function moveResize(e) {
    var d = dragState;
    if (!d) return;
    var delta = (d.horiz ? e.clientX : e.clientY) - d.start;
    var newA = Math.max(MIN_PX, Math.min(d.total - MIN_PX, d.sizeA + delta));
    var wA = r2(d.totalW * newA / d.total);
    var wB = r2(d.totalW - wA);
    d.a.weight = wA; d.b.weight = wB;
    // Live feedback straight on the DOM. Re-rendering the whole tree on every
    // pointermove would be both slower and jumpier.
    d.elA.style.flexGrow = String(wA); d.elA.style.flexBasis = '0';
    d.elB.style.flexGrow = String(wB); d.elB.style.flexBasis = '0';
    var r = (d.horiz ? d.elA.getBoundingClientRect().right : d.elA.getBoundingClientRect().bottom);
    if (d.horiz) d.handle.style.left = (r - 3) + 'px';
    else d.handle.style.top = (r - 3) + 'px';
}

function endResize(e) {
    var d = dragState;
    if (!d) return;
    dragState = null;
    d.handle.classList.remove('on');
    d.handle.removeEventListener('pointermove', moveResize);
    d.handle.removeEventListener('pointerup', endResize);
    d.handle.removeEventListener('pointercancel', endResize);
    try { d.handle.releasePointerCapture(e.pointerId); } catch (_) {}
    // Commit: re-render from LAYOUT so the weights are what the display would
    // actually do, then re-measure the handles against the result.
    refresh(true);
    renderInspector();
}

/* ── Drag a box somewhere else ─────────────────────────────────────────────
 * A press that MOVES becomes a drag; a press that does not is still a click,
 * so selecting by clicking keeps working. The drop target is the deepest
 * container under the pointer, and the index is decided by comparing the
 * pointer against each child's midpoint ALONG THAT CONTAINER'S AXIS — a row
 * inserts left/right, a column inserts above/below. */
var moveState = null;
var DRAG_SLOP = 5;

function insertionLine() {
    var doc = frame.contentDocument, layer = overlayLayer();
    var ln = doc.getElementById('tbeDropLine');
    if (!ln) {
        ln = doc.createElement('div');
        ln.id = 'tbeDropLine';
        ln.style.cssText = 'position:fixed;background:#22c55e;box-shadow:0 0 6px rgba(34,197,94,.9);' +
                           'pointer-events:none;z-index:70;display:none';
        layer.appendChild(ln);
    }
    return ln;
}

function dropTargetAt(x, y) {
    var doc = frame.contentDocument;
    var el = doc.elementFromPoint(x, y);
    if (!el) return null;
    var cont = el.closest ? el.closest('.tb-row, .tb-col') : null;
    if (!cont) return null;
    var contPath = cont.getAttribute('data-path');
    // Never into itself or its own descendants — that would detach the subtree.
    var from = moveState && moveState.path;
    if (from !== null && from !== undefined &&
        (contPath === from || contPath.indexOf(from + '.') === 0)) return null;
    var horiz = cont.classList.contains('tb-row');
    var kids = kidsOf(cont);
    var pos = horiz ? x : y;
    var idx = kids.length;
    for (var i = 0; i < kids.length; i++) {
        var r = kids[i].getBoundingClientRect();
        if (pos < (horiz ? (r.left + r.right) / 2 : (r.top + r.bottom) / 2)) { idx = i; break; }
    }
    return { contPath: contPath, cont: cont, index: idx, horiz: horiz, kids: kids };
}

function showDropLine(t) {
    var ln = insertionLine();
    if (!t) { ln.style.display = 'none'; return; }
    var cr = t.cont.getBoundingClientRect();
    var at;
    if (t.index >= t.kids.length) {
        var last = t.kids.length ? t.kids[t.kids.length - 1].getBoundingClientRect() : cr;
        at = t.horiz ? last.right : last.bottom;
    } else {
        var r = t.kids[t.index].getBoundingClientRect();
        at = t.horiz ? r.left : r.top;
    }
    ln.style.display = 'block';
    if (t.horiz) {
        ln.style.left = (at - 1.5) + 'px'; ln.style.top = cr.top + 'px';
        ln.style.width = '3px'; ln.style.height = cr.height + 'px';
    } else {
        ln.style.top = (at - 1.5) + 'px'; ln.style.left = cr.left + 'px';
        ln.style.height = '3px'; ln.style.width = cr.width + 'px';
    }
}

/* Reparent in the layout data. Indices are resolved BEFORE the removal, and the
 * target index is adjusted when the node came from earlier in the same list. */
function moveNodeTo(fromPath, toContPath, toIndex) {
    var fp = parentOf(fromPath);
    if (!fp) return null;
    var node = fp.list[fp.index];
    var toParent = (toContPath === '') ? curScreen().root : nodeAt(toContPath).node;
    var toList = toParent.row || toParent.col;
    if (!toList) return null;
    var sameList = (fp.list === toList);
    fp.list.splice(fp.index, 1);
    if (sameList && fp.index < toIndex) toIndex--;
    toIndex = Math.max(0, Math.min(toIndex, toList.length));
    toList.splice(toIndex, 0, node);
    return (toContPath === '' ? '' : toContPath + '.') + toIndex;
}

function wirePreviewDrag() {
    var doc = frame.contentDocument;
    if (!doc || doc.__tbeDragWired) return;
    doc.__tbeDragWired = true;

    doc.addEventListener('pointerdown', function (e) {
        if (e.button !== 0) return;
        if (e.target.closest && e.target.closest('.tbe-h')) return;   // that is a resize
        var el = e.target.closest ? e.target.closest('[data-path]') : null;
        if (!el) return;
        var path = el.getAttribute('data-path');
        if (path === '') return;                                      // the root cannot move
        moveState = { path: path, el: el, x: e.clientX, y: e.clientY, live: false, target: null };
    }, true);

    doc.addEventListener('pointermove', function (e) {
        if (!moveState) return;
        if (!moveState.live) {
            if (Math.abs(e.clientX - moveState.x) < DRAG_SLOP &&
                Math.abs(e.clientY - moveState.y) < DRAG_SLOP) return;
            // Past the slop: this is a drag. Take the undo snapshot now, and
            // hide the resize handles so elementFromPoint sees the layout.
            moveState.live = true;
            pushUndo();
            moveState.el.style.opacity = '.4';
            overlayLayer().querySelectorAll('.tbe-h').forEach(function (h) { h.style.display = 'none'; });
            doc.body.style.cursor = 'grabbing';
            // Dragging across text selects it otherwise, which both looks wrong
            // and leaves a highlight behind when the drag ends.
            doc.body.style.userSelect = 'none';
            doc.body.style.webkitUserSelect = 'none';
        }
        moveState.target = dropTargetAt(e.clientX, e.clientY);
        showDropLine(moveState.target);
    }, true);

    doc.addEventListener('pointerup', function () {
        var m = moveState;
        moveState = null;
        if (!m) return;
        showDropLine(null);
        doc.body.style.cursor = '';
        doc.body.style.userSelect = '';
        doc.body.style.webkitUserSelect = '';
        if (doc.getSelection) { try { doc.getSelection().removeAllRanges(); } catch (_) {} }
        if (m.el) m.el.style.opacity = '';
        if (!m.live) return;                       // a plain click: selection already handled it
        if (!m.target) { refresh(true); return; }  // dropped nowhere useful; undo entry stays
        var newPath = moveNodeTo(m.path, m.target.contPath, m.target.index);
        refresh();
        if (newPath !== null) select(newPath);
    }, true);
}

// Preview. The listener goes on the IFRAME's document (same-origin, which
// timer_beta.php opts into for embed mode), and the coordinates have to be
// translated into this page's space — the menu is rendered here, so that the
// iframe cannot clip it.
function wirePreviewMenu() {
    var doc = frame.contentDocument;
    if (!doc || doc.__tbeMenuWired) return;
    doc.__tbeMenuWired = true;
    doc.addEventListener('contextmenu', function (e) {
        var el = e.target.closest ? e.target.closest('[data-path]') : null;
        e.preventDefault();
        var r = frame.getBoundingClientRect();
        openNodeMenu(el ? el.getAttribute('data-path') : '', r.left + e.clientX, r.top + e.clientY);
    });
}

document.getElementById('tbeUndo').addEventListener('click', doUndo);

/* Editor keyboard shortcuts. Attached to BOTH documents on purpose: clicking
 * anywhere in the preview makes the parent's activeElement the <iframe>, so
 * every subsequent keystroke is delivered to the iframe's document and a
 * listener bound only to the parent never fires. Ctrl+Z was dead after any
 * click, drag or resize in the preview — which is to say, after every edit it
 * was most needed for. */
function editorKeydown(e) {
    if (!(e.ctrlKey || e.metaKey)) return;
    var k = (e.key || '').toLowerCase();
    if (e.target.closest && e.target.closest('input, textarea, select')) return;
    if (k === 'z') { e.preventDefault(); doUndo(); return; }
    if (k === 'c' || k === 'x') {
        // A real text selection keeps the browser's own copy.
        var sel = e.view && e.view.getSelection ? String(e.view.getSelection()) : '';
        if (sel) return;
        if (selPath === null || selPath === '') return;
        e.preventDefault();
        if (k === 'c') copyNode(); else cutNode();
        return;
    }
    if (k === 'v') {
        if (!nodeClipboard) return;
        e.preventDefault();
        pasteNode();
    }
}
document.addEventListener('keydown', editorKeydown);

function wirePreviewKeys() {
    var doc = frame.contentDocument;
    if (!doc || doc.__tbeKeysWired) return;
    doc.__tbeKeysWired = true;
    doc.addEventListener('keydown', editorKeydown);
}
function doUndo() {
    if (!undoStack.length) return;
    LAYOUT = JSON.parse(undoStack.pop());
    normalizeLayout();
    selPath = null;
    refresh();
    selectScreen(Math.min(editScreenIndex, LAYOUT.screens.length - 1));
}

/* ── Preview state chips ─────────────────────────────────────────────── */

var STATES = {
    normal: { level: 5, running: true,  gameOver: false },
    paused: { level: 5, running: false, gameOver: false },
    break:  { level: 7, running: true,  gameOver: false },
    over:   { level: 5, running: false, gameOver: true }
};
document.querySelectorAll('.tbe-chip').forEach(function (b) {
    b.addEventListener('click', function () {
        document.querySelectorAll('.tbe-chip').forEach(function (x) { x.classList.remove('active'); });
        b.classList.add('active');
        PV.setState(STATES[b.getAttribute('data-state')]);
        // Release the pinned screen so the preview shows the REAL screen the
        // conditions would pick for this state — the whole point of the chips is
        // to verify "Main when running / Paused when paused" actually switches.
        // Then follow the preview: edit whichever screen is now showing.
        PV.forceScreen(null);
        var idx = PV.activeScreenIndex();
        if (idx >= 0 && idx < LAYOUT.screens.length && idx !== editScreenIndex) {
            editScreenIndex = idx; selPath = null;
            renderScreensBar(); renderTree(); renderInspector();
        } else {
            renderScreensBar();   // refresh the "(showing)" hint even if unchanged
        }
    });
});

/* ── Preview device toggle (TV/Desktop · Tablet · Phone) ─────────────────
 * Forces the preview's device conditions and reshapes the frame, so device-
 * conditioned cells, columns and whole screens can be auditioned from a PC.
 * Like the state chips, it releases the pinned screen and follows whichever
 * screen the conditions now pick — a screen with `when: mobile` shows the
 * moment Phone is selected. */
var devSeg = document.getElementById('tbeDevSeg');
if (devSeg) {
    devSeg.addEventListener('click', function (e) {
        var btn = e.target && e.target.closest ? e.target.closest('button[data-dev]') : null;
        if (!btn || btn.classList.contains('active')) return;
        devSeg.querySelectorAll('button').forEach(function (x) { x.classList.remove('active'); });
        btn.classList.add('active');
        if (typeof positionSegThumb === 'function') positionSegThumb('tbeDevSeg', true);
        var d = btn.getAttribute('data-dev');
        var fr = document.querySelector('.tbe-preview-frame');
        fr.classList.toggle('tbe-frame-tablet', d === 'tablet');
        fr.classList.toggle('tbe-frame-mobile', d === 'mobile');
        if (PV && PV.device) {
            PV.device(d);
            PV.forceScreen(null);
            var idx = PV.activeScreenIndex();
            if (idx >= 0 && idx < LAYOUT.screens.length && idx !== editScreenIndex) {
                editScreenIndex = idx; selPath = null;
                renderScreensBar(); renderTree(); renderInspector();
            } else {
                renderScreensBar();
            }
        }
        requestAnimationFrame(mountResizeHandles);
    });
    if (typeof positionSegThumb === 'function') requestAnimationFrame(function () { positionSegThumb('tbeDevSeg', false); });
}

/* ── Load / save / delete ────────────────────────────────────────────── */

var loadSel = document.getElementById('tbeLoad');
var nameInput = document.getElementById('tbeName');

function populateLoadList() {
    // Remove everything after the "Load…" placeholder — options AND the
    // optgroup shells. loadSel.remove(1) only pulls options, so the empty
    // group labels piled up on every re-populate (boot + each save).
    while (loadSel.children.length > 1) loadSel.removeChild(loadSel.lastChild);
    var bi = document.createElement('optgroup');
    bi.label = 'Built-in';
    Object.keys(PV.builtins).forEach(function (k) {
        var o = document.createElement('option');
        o.value = 'builtin:' + k;
        o.textContent = PV.builtins[k].name + (EV_ID && evLayoutKey === k ? ' • this event' : '');
        bi.appendChild(o);
    });
    loadSel.appendChild(bi);
    fetch('/timer_beta_dl.php?action=get_layouts')
        .then(function (r) { return r.json(); })
        .then(function (j) {
            if (!j || !j.ok || !j.layouts.length) return;
            var grp = document.createElement('optgroup');
            grp.label = 'Saved';
            j.layouts.forEach(function (L) {
                var o = document.createElement('option');
                o.value = 'id:' + L.id;
                o.textContent = L.name + (L.is_global ? ' (site)' : (L.league_name ? ' (' + L.league_name + ')' : ''))
                              + (EV_ID && evLayoutId === L.id ? ' • this event' : '');
                grp.appendChild(o);
            });
            loadSel.appendChild(grp);
            updateEventBtn();
        }).catch(function () {});
}

/* ── Event binding (event context only) ─────────────────────────────────
 * Opened for a specific game (check-in Setup → Timer, or event_display.php,
 * which inject ES_EVENT_ID / ES_LAYOUT_ID / ES_CSRF), the header grows a
 * "Use for this event" toggle: the LOADED layout becomes what that game's
 * display shows (the live display follows within a poll). The bound layout
 * is marked "• this event" in the Load list. Clicking again unbinds. */
var EV_ID = (typeof window.ES_EVENT_ID !== 'undefined' && window.ES_EVENT_ID) ? (window.ES_EVENT_ID | 0) : null;
var evLayoutId = (typeof window.ES_LAYOUT_ID !== 'undefined' && window.ES_LAYOUT_ID) ? (window.ES_LAYOUT_ID | 0) : null;
var evLayoutKey = (typeof window.ES_LAYOUT_KEY !== 'undefined' && window.ES_LAYOUT_KEY) ? String(window.ES_LAYOUT_KEY) : null;
var curBuiltin = null;   // built-in key currently loaded UNMODIFIED, else null
var evBtn = null;
if (EV_ID) {
    evBtn = document.createElement('button');
    evBtn.id = 'tbeUseForEvent';
    evBtn.className = 'tbe-btn';
    var controls = document.querySelector('.tbe-header-controls');
    controls.insertBefore(evBtn, document.getElementById('tbeDelete'));
    // The header's "Open display" link opens THIS game's display, not sample.
    var od = controls.querySelector('a[href="/timer_beta.php"]');
    if (od) od.href = '/timer_beta.php?event_id=' + EV_ID;
    var bindEvent = function (toId, toKey) {   // neither = back to default
        var body = new URLSearchParams();
        body.set('action', 'set_layout');
        body.set('csrf_token', window.ES_CSRF || TBE_CSRF);
        body.set('event_id', EV_ID);
        body.set('layout_id', toId || '0');
        if (toKey) body.set('builtin', toKey);
        fetch('/event_setup_dl.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: String(body) })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (!j.ok) { (window.pkAlert || alert)(j.error || 'Could not update'); return; }
                evLayoutId = toId || null;
                evLayoutKey = toKey || null;
                populateLoadList();   // re-annotate "• this event" + button state
            })
            .catch(function () { (window.pkAlert || alert)('Network error'); });
    };
    evBtn.addEventListener('click', function () {
        if (curBuiltin) {
            // A pristine built-in binds by KEY — no library copy is created.
            bindEvent(0, evLayoutKey === curBuiltin ? null : curBuiltin);
        } else if (layoutId) {
            bindEvent(evLayoutId === layoutId ? 0 : layoutId, null);
        } else {
            // Custom, never-saved work: it has to become a saved layout to be
            // referenced by the event, so save it (name box) and bind.
            save(false, function () { bindEvent(layoutId, null); });
        }
    });
}
/* ── Site-wide layouts (admins only) ─────────────────────────────────────
 * A site layout appears in every host's Load list, marked "(site)". Only an
 * admin may set the flag; the server enforces that independently, and it now
 * PRESERVES the flag on a save that doesn't mention it, so a layout can never
 * be demoted by an ordinary edit. The toggle is a pending value: it ships with
 * the next Save, so "make this site-wide" is one deliberate action. */
var isGlobal = false;
var globalBtn = null;
if (window.TBE_IS_ADMIN) {
    globalBtn = document.createElement('button');
    globalBtn.id = 'tbeGlobal';
    globalBtn.className = 'tbe-btn';
    document.querySelector('.tbe-header-controls')
        .insertBefore(globalBtn, document.getElementById('tbeExport'));
    globalBtn.addEventListener('click', function () {
        isGlobal = !isGlobal;
        updateGlobalBtn();
        // Already a saved layout: apply immediately rather than leaving the
        // button lying about a state the library doesn't have yet.
        if (layoutId && editable) save(false);
    });
}
function updateGlobalBtn() {
    if (!globalBtn) return;
    globalBtn.textContent = isGlobal ? '✓ Site layout' : 'Site layout';
    globalBtn.title = isGlobal
        ? 'Every host sees this layout in their Load list. Click to make it yours alone.'
        : 'Share this layout with every host on the site (admins only). It saves with the next Save.';
    globalBtn.classList.toggle('tbe-btn-primary', isGlobal);
}

function updateEventBtn() {
    if (!evBtn) return;
    var bound = (!!layoutId && evLayoutId === layoutId) || (!!curBuiltin && evLayoutKey === curBuiltin);
    evBtn.textContent = bound ? '✓ Event display' : 'Use for this event';
    evBtn.title = bound
        ? "This game's display shows this layout — click to go back to the default"
        : "Make this game's display show this layout (a built-in binds as-is; custom work saves to your library first; the live display follows within seconds)";
    evBtn.classList.toggle('tbe-btn-primary', bound);
}

loadSel.addEventListener('change', function () {
    var v = loadSel.value;
    if (!v) return;
    if (v.indexOf('builtin:') === 0) {
        var k = v.slice(8);
        LAYOUT = JSON.parse(JSON.stringify(PV.builtins[k]));
        delete LAYOUT.name;
        layoutId = null; editable = true;
        nameInput.value = PV.builtins[k].name + ' (mine)';
        undoStack = []; selPath = null;
        normalizeLayout();
        editScreenIndex = mainScreenIndex();
        refresh(); selectScreen(editScreenIndex);
        curBuiltin = k;
        isGlobal = false; updateGlobalBtn();   // a fresh copy starts personal
        updateEventBtn();
    } else {
        fetch('/timer_beta_dl.php?action=get_layout&id=' + v.slice(3))
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (!j || !j.ok) return;
                LAYOUT = j.layout;
                layoutId = j.editable ? j.id : null;
                editable = j.editable;
                nameInput.value = j.name + (j.editable ? '' : ' (copy)');
                undoStack = []; selPath = null;
                normalizeLayout();
                editScreenIndex = mainScreenIndex();
                refresh(); selectScreen(editScreenIndex);
                curBuiltin = null;
                // A layout you may not edit becomes a personal copy on save, so
                // it must not carry the site flag into that copy.
                isGlobal = !!j.is_global && !!j.editable; updateGlobalBtn();
                updateEventBtn();
            }).catch(function () {});
    }
    loadSel.value = '';
});

function save(asCopy, onSaved) {
    var name = nameInput.value.trim();
    if (!name) { window.pkAlert ? pkAlert('Give the layout a name first.') : alert('Name required'); return; }
    var body = new URLSearchParams();
    body.set('action', 'save_layout');
    body.set('csrf_token', TBE_CSRF);
    body.set('name', name);
    body.set('layout', JSON.stringify(LAYOUT));
    // Only an admin sends this at all; a save with no is_global leaves the
    // stored scope untouched (see save_layout in timer_beta_dl.php).
    if (window.TBE_IS_ADMIN) body.set('is_global', isGlobal ? '1' : '0');
    if (!asCopy && layoutId) body.set('id', layoutId);
    fetch('/timer_beta_dl.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: String(body) })
        .then(function (r) { return r.json(); })
        .then(function (j) {
            if (!j.ok) { window.pkAlert ? pkAlert(j.error || 'Save failed') : alert(j.error); return; }
            layoutId = j.id; editable = true;
            if (onSaved) onSaved();
            populateLoadList();
            var btn = document.getElementById('tbeSave');
            btn.textContent = 'Saved ✓';
            // Restore the label the markup shipped with, not a hard-coded
            // 'Save' — the button is "Save layout" now, and rewriting it to
            // 'Save' after the first save undid the rename.
            setTimeout(function () { btn.textContent = 'Save layout'; }, 1500);
        })
        .catch(function () { window.pkAlert ? pkAlert('Network error') : alert('Network error'); });
}
document.getElementById('tbeSave').addEventListener('click', function () { save(false); });
document.getElementById('tbeSaveCopy').addEventListener('click', function () { save(true); });

/* ── Export / Import ─────────────────────────────────────────────────────
 * A layout exports as one self-contained JSON file — our own portable format,
 * readable by any GameNight install. Import feeds it straight through the
 * server-side sanitizer (save_layout), so the file is untrusted data, never
 * code; it is only ever JSON.parse'd, never evaluated.
 *
 * Images: the layout references /uploads/timer_layouts/ URLs, which only exist
 * on THIS install. Export therefore embeds each referenced image as a base64
 * data URI in an `images` map alongside the layout; import re-uploads those
 * bytes through the normal upload_image action (byte-level MIME check,
 * getimagesize, size cap, daily cap all re-applied server-side) and rewrites
 * the refs to the fresh local URLs. A data URI never lands in the layout
 * itself, so the saved document is untouched by any of this. */
var IMG_REF_RE = /^\/uploads\/timer_layouts\/[A-Za-z0-9._-]{1,120}$/;

// Visit every node that can carry an `image` ref (screen bg + image cells),
// mirroring pk_lo_image_names() in timer_beta_dl.php.
function walkImageRefs(layout, fn) {
    (function scan(node) {
        if (!node || typeof node !== 'object') return;
        // The walker hands back the KEY too: a node can carry content `image`
        // and box `bgImage` at once, and a callback that assumes .image would
        // silently skip the plates.
        if (typeof node.image === 'string' && IMG_REF_RE.test(node.image)) fn(node, 'image');
        if (typeof node.bgImage === 'string' && IMG_REF_RE.test(node.bgImage)) fn(node, 'bgImage');
        if (node.bg && typeof node.bg === 'object') scan(node.bg);
        if (node.cell && typeof node.cell === 'object') scan(node.cell);
        ['row', 'col', 'screens'].forEach(function (k) {
            if (Array.isArray(node[k])) node[k].forEach(scan);
        });
        if (node.root) scan(node.root);
    })(layout);
}

// Trigger sounds get the same passport: refs walked, bytes embedded on
// export, re-uploaded on import. Only upload refs — preset: sounds are code.
var SND_REF_RE = /^\/uploads\/timer_sounds\/[A-Za-z0-9._-]{1,160}$/;

function walkSoundRefs(layout, fn) {
    (Array.isArray(layout.triggers) ? layout.triggers : []).forEach(function (tg) {
        (Array.isArray(tg['do']) ? tg['do'] : []).forEach(function (act) {
            if (act && typeof act.sound === 'string' && SND_REF_RE.test(act.sound)) fn(act);
        });
    });
}

function slugify(s) {
    return (String(s || 'layout').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '') || 'layout').slice(0, 60);
}
document.getElementById('tbeExport').addEventListener('click', function () {
    var name = nameInput.value.trim() || 'Untitled layout';
    var refs = {};
    walkImageRefs(LAYOUT, function (node, key) { refs[node[key]] = true; });
    var sndRefs = {};
    walkSoundRefs(LAYOUT, function (act) { sndRefs[act.sound] = true; });
    // Fetch each referenced image (same-origin) and embed it as a data URI so
    // the file carries its own images to another install. A ref that fails to
    // fetch (file deleted) is simply not embedded; the export still completes.
    Promise.all(Object.keys(refs).map(function (u) {
        return fetch(u)
            .then(function (r) { if (!r.ok) throw 0; return r.blob(); })
            .then(function (b) {
                return new Promise(function (res) {
                    var fr = new FileReader();
                    fr.onload = function () { res([u, fr.result]); };
                    fr.onerror = function () { res(null); };
                    fr.readAsDataURL(b);
                });
            })
            .catch(function () { return null; });
    })).then(function (pairs) {
        return Promise.all(Object.keys(sndRefs).map(function (u) {
            return fetch(u)
                .then(function (r) { if (!r.ok) throw 0; return r.blob(); })
                .then(function (b) {
                    return new Promise(function (res) {
                        var fr = new FileReader();
                        fr.onload = function () { res([u, fr.result]); };
                        fr.onerror = function () { res(null); };
                        fr.readAsDataURL(b);
                    });
                })
                .catch(function () { return null; });
        })).then(function (sndPairs) { return [pairs, sndPairs]; });
    }).then(function (both) {
        var pairs = both[0], sndPairs = both[1];
        var images = {};
        var count = 0;
        pairs.forEach(function (p) { if (p) { images[p[0]] = p[1]; count++; } });
        var sounds = {};
        var sndCount = 0;
        sndPairs.forEach(function (p) { if (p) { sounds[p[0]] = p[1]; sndCount++; } });
        var envelope = { gnTimerLayout: 1, name: name, layout: LAYOUT };
        if (count) envelope.images = images;
        if (sndCount) envelope.sounds = sounds;
        var blob = new Blob([JSON.stringify(envelope, null, 2)], { type: 'application/json' });
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url; a.download = slugify(name) + '.gntimer.json';
        document.body.appendChild(a); a.click(); a.remove();
        setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
    });
});

// data:image/...;base64 → Blob, or null if it isn't a well-formed image data
// URI. The server re-checks the actual bytes on upload; this is only the
// client-side gate that keeps obvious junk from being POSTed at all.
function dataUriToBlob(uri) {
    var m = /^data:(image\/(?:png|jpeg|gif|webp));base64,([A-Za-z0-9+/=]+)$/.exec(String(uri || ''));
    if (!m) return null;
    try {
        var bin = atob(m[2]);
        if (bin.length > 8 * 1024 * 1024) return null;   // matches the server cap
        var buf = new Uint8Array(bin.length);
        for (var i = 0; i < bin.length; i++) buf[i] = bin.charCodeAt(i);
        return new Blob([buf], { type: m[1] });
    } catch (e) { return null; }
}

// Upload the embedded images one at a time through the normal upload path.
// Calls done(map, failed): map is oldRef → new local URL for the successes.
function uploadEmbeddedImages(images, done) {
    var keys = Object.keys(images).filter(function (k) { return IMG_REF_RE.test(k); }).slice(0, 20);
    var map = {}, failed = 0;
    (function next(i) {
        if (i >= keys.length) { done(map, failed); return; }
        var blob = dataUriToBlob(images[keys[i]]);
        if (!blob) { failed++; next(i + 1); return; }
        var fd = new FormData();
        fd.append('action', 'upload_image');
        fd.append('image', blob, 'imported');
        fd.append('csrf_token', TBE_CSRF);
        fetch('/timer_beta_dl.php', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (j && j.url) map[keys[i]] = j.url; else failed++;
                next(i + 1);
            })
            .catch(function () { failed++; next(i + 1); });
    })(0);
}

// data:audio/...;base64 → Blob. The server re-sniffs the bytes; this only
// keeps obvious junk off the wire (same contract as dataUriToBlob).
function dataUriToAudioBlob(uri) {
    var m = /^data:(audio\/(?:mpeg|mp4|x-m4a|wav|x-wav|ogg|webm|aac));base64,([A-Za-z0-9+/=]+)$/.exec(String(uri || ''));
    if (!m) return null;
    try {
        var bin = atob(m[2]);
        if (bin.length > 5 * 1024 * 1024) return null;   // matches the server cap
        var buf = new Uint8Array(bin.length);
        for (var i = 0; i < bin.length; i++) buf[i] = bin.charCodeAt(i);
        return new Blob([buf], { type: m[1] });
    } catch (e) { return null; }
}

function uploadEmbeddedSounds(sounds, done) {
    var keys = Object.keys(sounds).filter(function (k) { return SND_REF_RE.test(k); }).slice(0, 10);
    var map = {}, failed = 0;
    (function next(i) {
        if (i >= keys.length) { done(map, failed); return; }
        var blob = dataUriToAudioBlob(sounds[keys[i]]);
        if (!blob) { failed++; next(i + 1); return; }
        var fd = new FormData();
        fd.append('action', 'upload_sound');
        fd.append('sound', blob, 'imported');
        fd.append('csrf_token', TBE_CSRF);
        fetch('/timer_beta_dl.php', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (j && j.url) map[keys[i]] = j.url; else failed++;
                next(i + 1);
            })
            .catch(function () { failed++; next(i + 1); });
    })(0);
}

var importFile = document.getElementById('tbeImportFile');
/* ── Panel colours vs background artwork ───────────────────────────────────
 * A layout can paint a solid panel behind a cell AND sit that cell on artwork
 * that already draws that panel. Honouring the colour buries the art; dropping
 * it loses panels a layout with no artwork needs. Which is wanted is a matter
 * of looking at the result, so it is a switch rather than a rule. The stash
 * lives for the session; Undo is the general safety net. */
var panelStash = {};

// Every box that can carry a background: shared styles, cells, and containers.
// The SCREEN's own background is deliberately not in here — that is the
// artwork itself, and this must never be able to switch it off.
function eachPaintedBox(fn) {
    var st = LAYOUT.styles || {};
    Object.keys(st).forEach(function (k) { fn(st[k], 'style:' + k); });
    (LAYOUT.screens || []).forEach(function (s, i) {
        (function walk(n, p) {
            if (!n || typeof n !== 'object') return;
            fn(n.cell ? n.cell : n, p);
            var list = n.row || n.col;
            if (list) list.forEach(function (c, j) { walk(c, p + '.' + j); });
        })(s.root, 's' + i);
    });
}

function panelColoursOn() {
    var any = false;
    eachPaintedBox(function (b) { if (b && b.bg) any = true; });
    return any;
}

function screenHasArt() {
    return (LAYOUT.screens || []).some(function (s) { return s.bg && s.bg.image; });
}

function setPanelColours(on, quiet) {
    if (!quiet) pushUndo();
    eachPaintedBox(function (box, key) {
        if (!box) return;
        if (on) { if (box.bg == null && panelStash[key] != null) box.bg = panelStash[key]; }
        else if (box.bg) { panelStash[key] = box.bg; delete box.bg; }
    });
    // quiet is for the import itself: it has already pushed its own undo step,
    // and a second one would make Ctrl+Z put the panels back instead of
    // undoing the import.
    if (!quiet) { refresh(true); renderInspector(); }
}

document.getElementById('tbeImport').addEventListener('click', function () { importFile.value = ''; importFile.click(); });
importFile.addEventListener('change', function () {
    var file = importFile.files && importFile.files[0];
    if (!file) return;
    // Embedded images make these files big; 8MB/image server cap × up to 20.
    if (file.size > 64 * 1024 * 1024) { (window.pkAlert || alert)('That file is too large to be a layout.'); return; }
    var reader = new FileReader();
    // Read as bytes and decode explicitly: a file that came back off a phone or
    // out of a mail client can carry a BOM, and reading it as text would leave
    // that byte at the front of the JSON where JSON.parse trips over it.
    reader.onload = function () {
        var text = new TextDecoder('utf-8').decode(new Uint8Array(reader.result)).replace(/^\uFEFF/, '');
        onText(text);
    };
    reader.onerror = function () { (window.pkAlert || alert)('Could not read that file.'); };
    reader.readAsArrayBuffer(file);

    function onText(text) {
        // Checked from the CONTENTS, never the name: iOS hands files over from
        // iCloud and Files with names we cannot rely on, and the picker
        // deliberately has no accept filter so the file is selectable there at
        // all.
        var env;
        try { env = JSON.parse(text); }
        catch (e) {
            (window.pkAlert || alert)("That is not a GameNight layout file. Export one from this " +
                "editor (or another install) and it saves as .gntimer.json.");
            return;
        }
        // Accept our envelope, or a bare layout object as a fallback.
        var layout = (env && env.gnTimerLayout && env.layout) ? env.layout
                   : (env && (env.screens || env.root)) ? env : null;
        if (!layout) { (window.pkAlert || alert)("That doesn't look like a GameNight timer layout."); return; }
        var name = (env && env.name) ? String(env.name).slice(0, 80) : (nameInput.value.trim() || 'Imported layout');
        var embedded = (env && env.gnTimerLayout && env.images && typeof env.images === 'object' && !Array.isArray(env.images))
                     ? env.images : null;
        var embeddedSnd = (env && env.gnTimerLayout && env.sounds && typeof env.sounds === 'object' && !Array.isArray(env.sounds))
                        ? env.sounds : null;

        function finish() {
            // Load it into the editor first (through the same renderer, which only
            // assigns text via textContent), then persist a NEW row via the
            // server-sanitized save path so it lands in the Load list.
            LAYOUT = layout; layoutId = null; editable = true; curBuiltin = null;
            isGlobal = false; updateGlobalBtn();   // an import lands in your own library
            normalizeLayout();
            editScreenIndex = mainScreenIndex();
            nameInput.value = name.replace(/\s*\(imported\)\s*$/i, '') + ' (imported)';
            undoStack = []; selPath = null;
            refresh(); selectScreen(editScreenIndex); renderInspector();
            save(true);   // save as a new copy; server sanitizes and rejects anything invalid
        }

        // Sounds first (few and small), then images, then load. A sound whose
        // bytes fail to import falls back to a preset so the trigger still
        // does SOMETHING rather than silently losing its action.
        function importSounds(then) {
            if (!embeddedSnd) { then(0); return; }
            uploadEmbeddedSounds(embeddedSnd, function (map, failed) {
                walkSoundRefs(layout, function (act) {
                    if (map[act.sound]) { act.sound = map[act.sound]; return; }
                    if (Object.prototype.hasOwnProperty.call(embeddedSnd, act.sound)) act.sound = 'preset:chime';
                });
                then(failed);
            });
        }

        if (!embedded) { importSounds(function (sndFailed) {
            finish();
            if (sndFailed) (window.pkAlert || alert)(sndFailed + ' embedded sound' + (sndFailed > 1 ? 's' : '') + " couldn't be imported (replaced with a preset chime).");
        }); return; }
        // Re-upload the embedded images to THIS install, then point the layout's
        // refs at the new local URLs. A ref whose bytes were embedded but failed
        // to upload is dropped (it would just be a broken image here); a ref with
        // no embedded bytes at all (older export) is left as-is for same-install
        // round-trips.
        uploadEmbeddedImages(embedded, function (map, failed) {
            walkImageRefs(layout, function (node, key) {
                if (map[node[key]]) { node[key] = map[node[key]]; return; }
                if (Object.prototype.hasOwnProperty.call(embedded, node[key])) {
                    delete node[key];
                    delete node[key === 'bgImage' ? 'bgImageFit' : 'imageFit'];
                }
            });
            importSounds(function (sndFailed) {
                finish();
                var probs = [];
                if (failed) probs.push(failed + ' embedded image' + (failed > 1 ? 's' : ''));
                if (sndFailed) probs.push(sndFailed + ' embedded sound' + (sndFailed > 1 ? 's' : ''));
                if (probs.length) (window.pkAlert || alert)(probs.join(' and ') + " couldn't be imported (the rest of the layout is fine).");
            });
        });
    }
});

document.getElementById('tbeDelete').addEventListener('click', function () {
    if (!layoutId) { window.pkAlert ? pkAlert('This layout is not saved.') : alert('Not saved'); return; }
    var go = function () {
        var body = new URLSearchParams();
        body.set('action', 'delete_layout'); body.set('csrf_token', TBE_CSRF); body.set('id', layoutId);
        fetch('/timer_beta_dl.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: String(body) })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (!j.ok) { window.pkAlert ? pkAlert(j.error || 'Delete failed') : alert(j.error); return; }
                layoutId = null;
                nameInput.value = '';
                populateLoadList();
            });
    };
    if (window.pkConfirm) pkConfirm('Delete "' + nameInput.value + '"?', { danger: true }).then(function (yes) { if (yes) go(); });
    else if (confirm('Delete layout?')) go();
});

/* ── The "?" reference panel ─────────────────────────────────────────── */
(function () {
    var btn = document.getElementById('tbeHelpBtn');
    if (!btn) return;
    var pop = null;
    function close() { if (pop) { pop.remove(); pop = null; } }
    btn.addEventListener('click', function (e) {
        e.stopPropagation();
        if (pop) { close(); return; }
        pop = document.createElement('div');
        pop.className = 'tbe-helppop';
        var head = document.createElement('div');
        head.className = 'tbe-helppop-head';
        var ht = document.createElement('span');
        ht.textContent = 'What everything means';
        var hx = document.createElement('button');
        hx.className = 'tbe-mini'; hx.textContent = '\u00d7';
        hx.addEventListener('click', close);
        head.appendChild(ht); head.appendChild(hx);
        pop.appendChild(head);
        var body = document.createElement('div');
        body.className = 'tbe-helppop-body';

        function section(title) {
            var h = document.createElement('div');
            h.className = 'tbe-helppop-sec';
            h.textContent = title;
            body.appendChild(h);
        }
        function row(term, desc, mono) {
            var r = document.createElement('div');
            r.className = 'tbe-helppop-row';
            var t = document.createElement('span');
            t.className = 'tbe-helppop-term' + (mono ? ' mono' : '');
            t.textContent = term;
            var d = document.createElement('span');
            d.className = 'tbe-helppop-desc';
            d.textContent = desc;
            r.appendChild(t); r.appendChild(d);
            body.appendChild(r);
        }
        section('The structure');
        STRUCTURE_DESC.forEach(function (x) { row(x[0], x[1]); });
        section('Elements — live values you can put in any cell\'s text');
        // The renderer's list, so a new element appears here the day it ships;
        // described when the map knows it, shown regardless.
        var names = (PV && PV.elementNamesNS) ? PV.elementNamesNS()
                  : (PV && PV.elementNames) ? PV.elementNames() : Object.keys(ELEMENT_DESC);
        names.forEach(function (n) {
            row('<' + n + '>', ELEMENT_DESC[n] || (customElsOf()[n] !== undefined ? 'Custom element defined by this layout' : ''), true);
        });
        section('More');
        row('Full guide', 'The Timer Guide covers all of this with screenshots — the Help button up top.');
        pop.appendChild(body);
        document.body.appendChild(pop);
        // Park it under the button, inside the viewport.
        var r = btn.getBoundingClientRect();
        pop.style.top = (r.bottom + 6) + 'px';
        pop.style.right = Math.max(8, window.innerWidth - r.right - 8) + 'px';
        setTimeout(function () {
            document.addEventListener('click', function onDoc(ev) {
                if (pop && !pop.contains(ev.target)) { close(); document.removeEventListener('click', onDoc); }
            });
            // Clicks inside the preview IFRAME never bubble to this document;
            // without this, clicking the preview left the panel hanging open.
            try {
                var fdoc = document.getElementById('tbeFrame').contentDocument;
                fdoc.addEventListener('mousedown', function onF() {
                    close(); fdoc.removeEventListener('mousedown', onF);
                });
            } catch (e) {}
        }, 0);
    });
    window.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
})();
function customElsOf() {
    return (LAYOUT && LAYOUT.customElements && typeof LAYOUT.customElements === 'object') ? LAYOUT.customElements : {};
}

/* ── Boot: wait for the iframe's renderer, then start from Classic ── */

function boot() {
    var w = frame.contentWindow;
    if (!w || !w.TBPreview) { setTimeout(boot, 60); return; }
    PV = w.TBPreview;
    if (PV.elementNamesNS) { var tn = PV.elementNamesNS(); if (tn && tn.length) ELEMENTS = tn; }
    else if (PV.elementNames) { var tn2 = PV.elementNames(); if (tn2 && tn2.length) ELEMENTS = tn2; }
    ELEMENT_BUILTINS = ELEMENTS.slice();
    PV.onSelect = function (path) { select(path); };
    wirePreviewMenu();
    wirePreviewDrag();
    wirePreviewKeys();
    requestAnimationFrame(mountResizeHandles);
    // The preview is sized by the editor's own layout, so a window resize moves
    // every boundary without any layout change at all.
    window.addEventListener('resize', function () { requestAnimationFrame(mountResizeHandles); });
    // Event context boots on the layout THIS game's display shows — the point
    // of editing here is tweaking that layout, not `classic`. A binding by
    // built-in key loads synchronously; a saved layout follows by fetch.
    var bootKey = (EV_ID && !evLayoutId && evLayoutKey && PV.builtins[evLayoutKey]) ? evLayoutKey : 'classic';
    LAYOUT = JSON.parse(JSON.stringify(PV.builtins[bootKey]));
    delete LAYOUT.name;
    curBuiltin = bootKey;   // fresh boot shows a pristine built-in
    nameInput.value = bootKey === 'classic' ? 'My layout' : PV.builtins[bootKey].name + ' (mine)';
    editScreenIndex = 0;
    normalizeLayout();
    refresh();
    selectScreen(0);
    updateGlobalBtn();
    populateLoadList();
    if (EV_ID && evLayoutId) {
        fetch('/timer_beta_dl.php?action=get_layout&id=' + evLayoutId)
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (!j || !j.ok) return;
                LAYOUT = j.layout;
                layoutId = j.editable ? j.id : null;
                editable = j.editable;
                nameInput.value = j.name + (j.editable ? '' : ' (copy)');
                undoStack = []; selPath = null;
                normalizeLayout();
                editScreenIndex = mainScreenIndex();
                refresh(); selectScreen(editScreenIndex);
                curBuiltin = null;
                // A layout you may not edit becomes a personal copy on save, so
                // it must not carry the site flag into that copy.
                isGlobal = !!j.is_global && !!j.editable; updateGlobalBtn();
                updateEventBtn();
            }).catch(function () {});
    }
}
frame.addEventListener('load', boot);
if (frame.contentWindow && frame.contentWindow.TBPreview) boot();
})();
