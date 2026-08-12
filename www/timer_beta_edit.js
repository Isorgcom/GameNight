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
        LAYOUT = { v: LAYOUT.v || 1, screens: [{ name: 'Main', bg: LAYOUT.bg || null, root: LAYOUT.root || { col: [] } }] };
    }
    if (editScreenIndex >= LAYOUT.screens.length) editScreenIndex = 0;
}
function curScreen() { return LAYOUT.screens[editScreenIndex]; }

var ELEMENT_BUILTINS = [];
var ELEMENTS = ['eventName','level','levelOrBreak','clock','gameName','nextGameName','smallBlind','bigBlind','ante',
    'blinds','nextBlinds','players','playersLeft','playersTotal','entries','rebuys','pot','chipCount','avgStack','avgStackBB',
    'currentTime','elapsedTime','nextBreak','prizes','prizeList','buyinLine'];

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
}

function refresh(keepSel) {
    PV.setLayout(LAYOUT);
    renderTree();
    if (keepSel && selPath !== null) PV.select(selPath);
}

/* ── Screens (break screen, pre-game screen, …) ──────────────────────── */

var screensEl = document.getElementById('tbeScreens');

function selectScreen(i) {
    editScreenIndex = i;
    selPath = null;
    if (PV) PV.forceScreen(i);   // pin the preview to the screen being edited
    renderScreensBar();
    renderTree();
    renderInspector();
}

function renderScreensBar() {
    screensEl.textContent = '';
    var tabs = document.createElement('div');
    tabs.className = 'tbe-screen-tabs';
    LAYOUT.screens.forEach(function (scr, i) {
        var btn = document.createElement('button');
        btn.className = 'tbe-screen-tab' + (i === editScreenIndex ? ' active' : '');
        btn.textContent = scr.name || ('Screen ' + (i + 1));
        btn.addEventListener('click', function () { selectScreen(i); });
        tabs.appendChild(btn);
    });
    var add = document.createElement('button');
    add.className = 'tbe-screen-add'; add.textContent = '+ Screen';
    add.addEventListener('click', addScreen);
    tabs.appendChild(add);
    screensEl.appendChild(tabs);

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
    note.textContent = 'Screens are checked top to bottom; the first match shows. Put specific ones (Break) before a catch-all Main.';
    condWrap.appendChild(note);
    screensEl.appendChild(condWrap);
}

function addScreen() {
    pushUndo();
    var base = curScreen();
    var fresh = {
        name: 'Break',
        when: 'on_break',
        bg: base.bg ? JSON.parse(JSON.stringify(base.bg)) : { color: '#000' },
        root: { col: [
            { cell: { text: 'ON BREAK', fit: true, bold: true, color: '#fbbf24' }, weight: 3 },
            { cell: { text: 'Back in <nextBreak>', size: 3.5, color: '#e2e8f0' }, weight: 1 }
        ] }
    };
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

function cellLabel(cell) {
    var t = String(cell.text || '').replace(/\n/g, ' ').trim();
    return t.length > 26 ? t.slice(0, 26) + '…' : (t || '(empty cell)');
}

function renderTree() {
    treeEl.textContent = '';
    var rootRow = document.createElement('div');
    rootRow.className = 'tbe-node' + (selPath === '' ? ' selected' : '');
    rootRow.setAttribute('data-tpath', '');
    rootRow.textContent = 'Screen (' + kindOf(curScreen().root) + ')';
    treeEl.appendChild(rootRow);
    walk(curScreen().root, [], 1);

    function walk(node, path, depth) {
        var kids = node.row || node.col;
        if (!kids) return;
        for (var i = 0; i < kids.length; i++) {
            var p = path.concat(i), ps = p.join('.');
            var el = document.createElement('div');
            el.className = 'tbe-node' + (selPath === ps ? ' selected' : '');
            el.style.paddingLeft = (10 + depth * 16) + 'px';
            el.setAttribute('data-tpath', ps);
            var k = kindOf(kids[i]);
            el.textContent = k === 'cell' ? cellLabel(kids[i].cell)
                           : (k === 'row' ? 'Row' : 'Column') + ' (' + (kids[i].row || kids[i].col).length + ')';
            if (k !== 'cell') el.classList.add('tbe-node-container');
            treeEl.appendChild(el);
            walk(kids[i], p, depth + 1);
        }
    }
}

treeEl.addEventListener('click', function (e) {
    var n = e.target.closest('[data-tpath]');
    if (n) select(n.getAttribute('data-tpath'));
});

/* ── Selection + inspector ───────────────────────────────────────────── */

function select(path) {
    selPath = path;
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
function uploadImage(onUrl) {
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

function setOrDelete(obj, key, val) {
    if (val === undefined || val === '' || val === false) delete obj[key];
    else obj[key] = val;
}

/* A condition builder: state + round + ante/rebuys clauses that AND together.
 * Emits a WHEN string when only a state is set (shorthand), an object when more
 * than one clause is set, or undefined when nothing constrains it. */
var STATE_OPTS = ['', 'running', 'paused', 'on_break', 'pre_game', 'game_over'];
var STATE_LBLS = ['Any state', 'Running', 'Paused', 'On break', 'Pre-game', 'Game over'];

function condEditor(cond, onchange) {
    var m = {};
    if (typeof cond === 'string') { if (cond && cond !== 'always') m.state = cond; }
    else if (cond && typeof cond === 'object') m = JSON.parse(JSON.stringify(cond));

    var wrap = document.createElement('div');
    wrap.className = 'tbe-cond';

    function emit() {
        var keys = Object.keys(m).filter(function (k) { return m[k] !== undefined && m[k] !== ''; });
        if (!keys.length) { onchange(undefined); return; }
        if (keys.length === 1 && m.state) { onchange(m.state); return; }   // shorthand
        onchange(JSON.parse(JSON.stringify(m)));
    }

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
            var rmImg = document.createElement('button'); rmImg.className = 'tbe-mini tbe-mini-danger'; rmImg.textContent = 'Remove image (back to text)';
            rmImg.addEventListener('click', function () { pushUndo(); delete cell.image; delete cell.imageFit; refresh(true); renderInspector(); });
            insp.appendChild(rmImg);
            return;
        }

        var toImg = document.createElement('button');
        toImg.className = 'tbe-mini'; toImg.textContent = 'Use an image instead';
        toImg.addEventListener('click', function () { uploadImage(function (url) { pushUndo(); cell.image = url; refresh(true); renderInspector(); }); });
        insp.appendChild(toImg);

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
        renderVariants(cell);
    } else {
        inspTitle.textContent = kind === 'row' ? 'Row' : 'Column';
        insp.appendChild(field('Weight (share of space)', numInput(node.weight, 0, 50, 0.1, function (v) { setOrDelete(node, 'weight', v); })));
        insp.appendChild(field('Gap', textInput(node.gap, function (v) { setOrDelete(node, 'gap', v); }, 'e.g. 0.5vh')));
        insp.appendChild(field('Padding', textInput(node.pad, function (v) { setOrDelete(node, 'pad', v); }, 'e.g. 1vh 1vw')));
        insp.appendChild(field('Background', colorInput(node.bg, function (v) { setOrDelete(node, 'bg', v); })));
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

document.getElementById('tbeAddCell').addEventListener('click', function () {
    insertNode({ cell: { text: 'New cell', size: 2.4, color: '#ffffff' } });
});
document.getElementById('tbeAddRow').addEventListener('click', function () {
    insertNode({ row: [{ cell: { text: 'Left', size: 2.4, color: '#ffffff' } }, { cell: { text: 'Right', size: 2.4, color: '#ffffff' } }], weight: 1 });
});
document.getElementById('tbeAddCol').addEventListener('click', function () {
    insertNode({ col: [{ cell: { text: 'Top', size: 2.4, color: '#ffffff' } }, { cell: { text: 'Bottom', size: 2.4, color: '#ffffff' } }], weight: 1 });
});
document.getElementById('tbeDup').addEventListener('click', function () {
    if (!selPath) return;
    pushUndo();
    var p = parentOf(selPath);
    p.list.splice(p.index + 1, 0, JSON.parse(JSON.stringify(p.list[p.index])));
    refresh(); select(selPath);
});
document.getElementById('tbeRemove').addEventListener('click', function () {
    if (!selPath) return;
    pushUndo();
    var p = parentOf(selPath);
    p.list.splice(p.index, 1);
    selPath = null;
    refresh();
    renderInspector();
});
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

document.getElementById('tbeUndo').addEventListener('click', doUndo);
document.addEventListener('keydown', function (e) {
    if ((e.ctrlKey || e.metaKey) && e.key === 'z' && !e.target.closest('input, textarea, select')) {
        e.preventDefault(); doUndo();
    }
});
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
        o.value = 'builtin:' + k; o.textContent = PV.builtins[k].name;
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
                o.textContent = L.name + (L.is_global ? ' (site)' : (L.league_name ? ' (' + L.league_name + ')' : ''));
                grp.appendChild(o);
            });
            loadSel.appendChild(grp);
        }).catch(function () {});
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
        undoStack = []; selPath = null; editScreenIndex = 0;
        normalizeLayout();
        refresh(); selectScreen(0);
    } else {
        fetch('/timer_beta_dl.php?action=get_layout&id=' + v.slice(3))
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (!j || !j.ok) return;
                LAYOUT = j.layout;
                layoutId = j.editable ? j.id : null;
                editable = j.editable;
                nameInput.value = j.name + (j.editable ? '' : ' (copy)');
                undoStack = []; selPath = null; editScreenIndex = 0;
                normalizeLayout();
                refresh(); selectScreen(0);
            }).catch(function () {});
    }
    loadSel.value = '';
});

function save(asCopy) {
    var name = nameInput.value.trim();
    if (!name) { window.pkAlert ? pkAlert('Give the layout a name first.') : alert('Name required'); return; }
    var body = new URLSearchParams();
    body.set('action', 'save_layout');
    body.set('csrf_token', TBE_CSRF);
    body.set('name', name);
    body.set('layout', JSON.stringify(LAYOUT));
    if (!asCopy && layoutId) body.set('id', layoutId);
    fetch('/timer_beta_dl.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: String(body) })
        .then(function (r) { return r.json(); })
        .then(function (j) {
            if (!j.ok) { window.pkAlert ? pkAlert(j.error || 'Save failed') : alert(j.error); return; }
            layoutId = j.id; editable = true;
            populateLoadList();
            var btn = document.getElementById('tbeSave');
            btn.textContent = 'Saved ✓';
            setTimeout(function () { btn.textContent = 'Save'; }, 1500);
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
        if (typeof node.image === 'string' && IMG_REF_RE.test(node.image)) fn(node);
        if (node.bg && typeof node.bg === 'object') scan(node.bg);
        if (node.cell && typeof node.cell === 'object') scan(node.cell);
        ['row', 'col', 'screens'].forEach(function (k) {
            if (Array.isArray(node[k])) node[k].forEach(scan);
        });
        if (node.root) scan(node.root);
    })(layout);
}

function slugify(s) {
    return (String(s || 'layout').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '') || 'layout').slice(0, 60);
}
document.getElementById('tbeExport').addEventListener('click', function () {
    var name = nameInput.value.trim() || 'Untitled layout';
    var refs = {};
    walkImageRefs(LAYOUT, function (node) { refs[node.image] = true; });
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
        var images = {};
        var count = 0;
        pairs.forEach(function (p) { if (p) { images[p[0]] = p[1]; count++; } });
        var envelope = { gnTimerLayout: 1, name: name, layout: LAYOUT };
        if (count) envelope.images = images;
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

var importFile = document.getElementById('tbeImportFile');
document.getElementById('tbeImport').addEventListener('click', function () { importFile.value = ''; importFile.click(); });
importFile.addEventListener('change', function () {
    var file = importFile.files && importFile.files[0];
    if (!file) return;
    // Embedded images make these files big; 8MB/image server cap × up to 20.
    if (file.size > 64 * 1024 * 1024) { (window.pkAlert || alert)('That file is too large to be a layout.'); return; }
    var reader = new FileReader();
    reader.onload = function () {
        var env;
        try { env = JSON.parse(reader.result); }
        catch (e) { (window.pkAlert || alert)('That file is not valid JSON.'); return; }
        // Accept our envelope, or a bare layout object as a fallback.
        var layout = (env && env.gnTimerLayout && env.layout) ? env.layout
                   : (env && (env.screens || env.root)) ? env : null;
        if (!layout) { (window.pkAlert || alert)("That doesn't look like a GameNight timer layout."); return; }
        var name = (env && env.name) ? String(env.name).slice(0, 80) : (nameInput.value.trim() || 'Imported layout');
        var embedded = (env && env.gnTimerLayout && env.images && typeof env.images === 'object' && !Array.isArray(env.images))
                     ? env.images : null;

        function finish() {
            // Load it into the editor first (through the same renderer, which only
            // assigns text via textContent), then persist a NEW row via the
            // server-sanitized save path so it lands in the Load list.
            LAYOUT = layout; layoutId = null; editable = true; editScreenIndex = 0;
            normalizeLayout();
            nameInput.value = name.replace(/\s*\(imported\)\s*$/i, '') + ' (imported)';
            undoStack = []; selPath = null;
            refresh(); selectScreen(0); renderInspector();
            save(true);   // save as a new copy; server sanitizes and rejects anything invalid
        }

        if (!embedded) { finish(); return; }
        // Re-upload the embedded images to THIS install, then point the layout's
        // refs at the new local URLs. A ref whose bytes were embedded but failed
        // to upload is dropped (it would just be a broken image here); a ref with
        // no embedded bytes at all (older export) is left as-is for same-install
        // round-trips.
        uploadEmbeddedImages(embedded, function (map, failed) {
            walkImageRefs(layout, function (node) {
                if (map[node.image]) { node.image = map[node.image]; return; }
                if (Object.prototype.hasOwnProperty.call(embedded, node.image)) {
                    delete node.image; delete node.imageFit;
                }
            });
            finish();
            if (failed) (window.pkAlert || alert)(failed + ' embedded image' + (failed > 1 ? 's' : '') + " couldn't be imported (the rest of the layout is fine).");
        });
    };
    reader.onerror = function () { (window.pkAlert || alert)('Could not read that file.'); };
    reader.readAsText(file);
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

/* ── Boot: wait for the iframe's renderer, then start from Classic ── */

function boot() {
    var w = frame.contentWindow;
    if (!w || !w.TBPreview) { setTimeout(boot, 60); return; }
    PV = w.TBPreview;
    if (PV.elementNames) { var tn = PV.elementNames(); if (tn && tn.length) ELEMENTS = tn; }
    ELEMENT_BUILTINS = ELEMENTS.slice();
    PV.onSelect = function (path) { select(path); };
    LAYOUT = JSON.parse(JSON.stringify(PV.builtins.classic));
    delete LAYOUT.name;
    nameInput.value = 'My layout';
    editScreenIndex = 0;
    normalizeLayout();
    refresh();
    selectScreen(0);
    populateLoadList();
}
frame.addEventListener('load', boot);
if (frame.contentWindow && frame.contentWindow.TBPreview) boot();
})();
