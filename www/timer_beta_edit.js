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

var TOKENS = ['eventName','level','clock','gameName','nextGameName','smallBlind','bigBlind','ante',
    'blinds','nextBlinds','players','entries','rebuys','pot','chipCount','avgStack',
    'currentTime','elapsedTime','nextBreak','prizes','prizeList'];
var WHENS = ['always','running','paused','on_break','has_ante','has_rebuys','game_over'];

/* ── Tree access helpers ─────────────────────────────────────────────── */

function nodeAt(path) {
    if (path === '' || path === null) return { row: null, col: null, wrap: { col: [LAYOUT.root] }, isRoot: true, node: LAYOUT.root };
    var parts = path.split('.').map(Number);
    var n = LAYOUT.root;
    for (var i = 0; i < parts.length; i++) n = (n.row || n.col)[parts[i]];
    return { node: n, isRoot: false };
}
function parentOf(path) {
    if (!path) return null;
    var parts = path.split('.').map(Number);
    var idx = parts.pop();
    var p = parts.length ? nodeAt(parts.join('.')).node : LAYOUT.root;
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
    rootRow.textContent = 'Screen (' + kindOf(LAYOUT.root) + ')';
    treeEl.appendChild(rootRow);
    walk(LAYOUT.root, [], 1);

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
function setOrDelete(obj, key, val) {
    if (val === undefined || val === '' || val === false) delete obj[key];
    else obj[key] = val;
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
        LAYOUT.bg = LAYOUT.bg || {};
        var g = LAYOUT.bg.gradient;
        insp.appendChild(field('Solid colour', colorInput(LAYOUT.bg.color, function (v) { setOrDelete(LAYOUT.bg, 'color', v); })));
        insp.appendChild(field('Gradient from', colorInput(g ? g[0] : '', function (v) {
            LAYOUT.bg.gradient = [v, (LAYOUT.bg.gradient || ['#000', '#000'])[1]];
        })));
        insp.appendChild(field('Gradient to', colorInput(g ? g[1] : '', function (v) {
            LAYOUT.bg.gradient = [(LAYOUT.bg.gradient || ['#000', '#000'])[0], v];
        })));
        var clr = document.createElement('button');
        clr.className = 'tbe-mini'; clr.textContent = 'Remove gradient';
        clr.addEventListener('click', function () { pushUndo(); delete LAYOUT.bg.gradient; refresh(true); renderInspector(); });
        insp.appendChild(clr);
        return;
    }

    var node = nodeAt(selPath).node;
    var kind = kindOf(node);

    if (kind === 'cell') {
        inspTitle.textContent = 'Cell';
        var cell = node.cell;

        var ta = document.createElement('textarea');
        ta.rows = 3; ta.value = cell.text || '';
        ta.addEventListener('change', function () { pushUndo(); cell.text = ta.value; refresh(true); });
        insp.appendChild(field('Text', ta));

        var tokRow = document.createElement('div');
        tokRow.className = 'tbe-tokrow';
        var tokSel = document.createElement('select');
        var opt0 = document.createElement('option');
        opt0.value = ''; opt0.textContent = 'Insert token…';
        tokSel.appendChild(opt0);
        TOKENS.forEach(function (t) {
            var o = document.createElement('option');
            o.value = t; o.textContent = '<' + t + '>';
            tokSel.appendChild(o);
        });
        tokSel.addEventListener('change', function () {
            if (!tokSel.value) return;
            pushUndo();
            var at = ta.selectionStart || ta.value.length;
            ta.value = ta.value.slice(0, at) + '<' + tokSel.value + '>' + ta.value.slice(at);
            cell.text = ta.value;
            tokSel.value = '';
            refresh(true);
        });
        tokRow.appendChild(tokSel);
        insp.appendChild(tokRow);

        insp.appendChild(field('Fit to box', boolInput(cell.fit, function (v) { setOrDelete(cell, 'fit', v); renderInspector(); })));
        if (!cell.fit) insp.appendChild(field('Size (% of screen height)', numInput(cell.size, 0.5, 40, 0.1, function (v) { setOrDelete(cell, 'size', v); })));
        insp.appendChild(field('Bold', boolInput(cell.bold, function (v) { setOrDelete(cell, 'bold', v); })));
        insp.appendChild(field('Colour', colorInput(cell.color, function (v) { setOrDelete(cell, 'color', v); })));
        insp.appendChild(field('Background', colorInput(cell.bg, function (v) { setOrDelete(cell, 'bg', v); })));
        insp.appendChild(field('Align', selInput(cell.align || 'center', ['center', 'left', 'right'], function (v) { setOrDelete(cell, 'align', v === 'center' ? undefined : v); })));
        insp.appendChild(field('Padding', textInput(cell.pad, function (v) { setOrDelete(cell, 'pad', v); }, 'e.g. 0.6vh 1vw')));
        insp.appendChild(field('Show when', selInput(cell.when || 'always', WHENS, function (v) { setOrDelete(cell, 'when', v === 'always' ? undefined : v); })));
        insp.appendChild(field('Clock colours (warn/critical)', boolInput(cell.clockColors, function (v) { setOrDelete(cell, 'clockColors', v); })));
        insp.appendChild(field('Weight (share of space)', numInput(node.weight, 0, 50, 0.1, function (v) { setOrDelete(node, 'weight', v); })));
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
        (LAYOUT.root.row || LAYOUT.root.col).push(newNode);
        selPath = '' + ((LAYOUT.root.row || LAYOUT.root.col).length - 1);
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
    selPath = null;
    refresh();
    renderInspector();
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
        undoStack = []; selPath = null;
        refresh(); renderInspector();
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
                refresh(); renderInspector();
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

/* ── Boot: wait for the iframe's renderer, then start from TD Classic ── */

function boot() {
    var w = frame.contentWindow;
    if (!w || !w.TBPreview) { setTimeout(boot, 60); return; }
    PV = w.TBPreview;
    PV.onSelect = function (path) { select(path); };
    LAYOUT = JSON.parse(JSON.stringify(PV.builtins.td_classic));
    delete LAYOUT.name;
    nameInput.value = 'My layout';
    refresh();
    populateLoadList();
}
frame.addEventListener('load', boot);
if (frame.contentWindow && frame.contentWindow.TBPreview) boot();
})();
