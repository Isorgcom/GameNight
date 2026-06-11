<?php
/**
 * Admin: author in-app help bubbles ("ghost bubble" hints) per screen.
 *
 * Pick a screen, write a set of tips; they appear as a small floating/anchored
 * chat bubble to logged-in users on that screen (see _footer.php + help-bubble.js).
 * All mutations go through admin_help_dl.php (POST → JSON); this page is the shell
 * plus a live preview that renders the real bubble component.
 */
require_once __DIR__ . '/auth.php';

$current = require_login();
if (($current['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('Access denied.');
}

$db        = get_db();
$site_name = get_setting('site_name', 'Game Night');
$token     = csrf_token();

// Which screen are we editing? Default to the first known screen.
$screens     = HELP_SCREENS;
$screen_keys = array_keys($screens);
$screen      = $_GET['screen'] ?? '';
if (!array_key_exists($screen, $screens)) {
    $screen = $screen_keys[0];
}

// Per-screen tip counts for the dropdown labels.
$counts = [];
foreach ($db->query("SELECT screen_key, COUNT(*) c FROM help_bubbles GROUP BY screen_key") as $r) {
    $counts[$r['screen_key']] = (int)$r['c'];
}

// Tips for the active screen (inlined so the list + preview render without a fetch).
$tipsStmt = $db->prepare(
    'SELECT id, screen_key, title, body, anchor_selector, bubble_index, sort_order, enabled
     FROM help_bubbles WHERE screen_key = ? ORDER BY sort_order, id'
);
$tipsStmt->execute([$screen]);
$tips = $tipsStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Help Tips &mdash; <?= htmlspecialchars($site_name) ?></title>
    <link rel="stylesheet" href="/style.css">
    <style>
        .hlp-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem; align-items: start; }
        @media (max-width: 760px) { .hlp-grid { grid-template-columns: 1fr; } }
        .hlp-card { background:#fff; border:1.5px solid #e2e8f0; border-radius:10px; padding:1.25rem; }
        .hlp-card h2 { font-size:1.05rem; margin:0 0 .85rem; }
        .hlp-field { margin-bottom:.85rem; }
        .hlp-field label { display:block; font-size:.78rem; font-weight:700; color:#475569; margin-bottom:.3rem; }
        .hlp-field input[type=text], .hlp-field textarea, .hlp-screen-sel {
            width:100%; box-sizing:border-box; padding:.5rem .6rem; border:1.5px solid #e2e8f0;
            border-radius:8px; font-size:.9rem; font-family:inherit;
        }
        .hlp-field .hint { font-size:.72rem; color:#94a3b8; margin:.25rem 0 0; }
        .hlp-tip { border:1px solid #e2e8f0; border-radius:8px; padding:.7rem .8rem; margin-bottom:.6rem; }
        .hlp-tip.off { opacity:.55; }
        .hlp-tip-head { display:flex; align-items:center; gap:.5rem; margin-bottom:.25rem; }
        .hlp-tip-title { font-weight:700; font-size:.9rem; flex:1; }
        .hlp-tip-body { font-size:.85rem; color:#334155; white-space:pre-wrap; }
        .hlp-tip-anchor { font-size:.72rem; color:#7c3aed; margin-top:.3rem; font-family:monospace; }
        .hlp-tip-actions { display:flex; flex-wrap:wrap; gap:.35rem; margin-top:.55rem; }
        .hlp-btn { background:#f1f5f9; border:none; border-radius:6px; padding:.3rem .65rem; font-size:.74rem; font-weight:600; cursor:pointer; color:#334155; }
        .hlp-btn:hover { background:#e2e8f0; }
        .hlp-btn.danger { background:#fef2f2; color:#b91c1c; }
        .hlp-btn.danger:hover { background:#fee2e2; }
        .hlp-btn.primary { background:#2563eb; color:#fff; }
        .hlp-btn.primary:hover { filter:brightness(1.05); }
        .hlp-badge { font-size:.68rem; font-weight:700; padding:.1rem .4rem; border-radius:5px; }
        .hlp-badge.on { background:#dcfce7; color:#166534; }
        .hlp-badge.off { background:#f1f5f9; color:#64748b; }
        .hlp-empty { color:#94a3b8; font-size:.88rem; }
        .hlp-help { margin-top:.5rem; border:1px solid #e2e8f0; border-radius:8px; background:#f8fafc; }
        .hlp-help > summary { cursor:pointer; padding:.5rem .7rem; font-size:.78rem; font-weight:600; color:#2563eb; list-style:none; }
        .hlp-help > summary::-webkit-details-marker { display:none; }
        .hlp-help > summary::before { content:"\25B8\00a0"; }
        .hlp-help[open] > summary::before { content:"\25BE\00a0"; }
        .hlp-help-body { padding:0 .85rem .7rem; font-size:.8rem; color:#475569; line-height:1.5; }
        .hlp-help-body p { margin:.5rem 0; }
        .hlp-help-body ul { margin:.4rem 0 .4rem 1.1rem; padding:0; }
        .hlp-help-body li { margin:.25rem 0; }
        .hlp-help-body code { background:#e2e8f0; padding:.05rem .3rem; border-radius:4px; font-size:.76rem; }
        #hlpMsg { font-size:.82rem; margin:.5rem 0 0; min-height:1em; }
    </style>
</head>
<body>

<?php $nav_active = 'site-settings'; $nav_user = $current; require __DIR__ . '/_nav.php'; ?>

<div class="dash-wrap">
    <?php $admin_tab = 'help'; require __DIR__ . '/_admin_tabs.php'; ?>
    <h1 style="font-size:1.5rem;font-weight:700;margin:0 0 .35rem">Help Tips</h1>
    <p style="color:#64748b;margin:0 0 1.25rem;font-size:.9rem;line-height:1.55">
        Author small "ghost bubble" hints that pop up for signed-in users on a screen. Users can
        dismiss them, and reset all dismissed help from their own Settings page. Leave the anchor
        blank to float the bubble in the corner, or give a CSS selector to point it at an element.
    </p>

    <div class="hlp-field" style="max-width:420px">
        <label for="screenSel">Screen</label>
        <select id="screenSel" class="hlp-screen-sel" onchange="location.href='/admin_help.php?screen='+encodeURIComponent(this.value)">
            <?php foreach ($screens as $key => $label): ?>
            <option value="<?= htmlspecialchars($key) ?>"<?= $key === $screen ? ' selected' : '' ?>>
                <?= htmlspecialchars($label) ?> (<?= (int)($counts[$key] ?? 0) ?>)
            </option>
            <?php endforeach; ?>
        </select>
        <?php $screen_url = $screen === 'index' ? '/' : '/' . $screen . '.php'; ?>
        <p class="hint" style="margin-top:.4rem">
            URL: <a href="<?= htmlspecialchars($screen_url) ?>" target="_blank" rel="noopener"
                   style="font-family:monospace;color:#2563eb;text-decoration:none"><?= htmlspecialchars($screen_url) ?> &#8599;</a>
        </p>
    </div>

    <div class="hlp-grid">
        <!-- Editor -->
        <div class="hlp-card">
            <h2 id="formHeading">Add a tip</h2>
            <form id="tipForm" onsubmit="return false">
                <input type="hidden" id="tipId" value="">
                <div class="hlp-field">
                    <label for="tipTitle">Title <span style="font-weight:400;color:#94a3b8">(optional)</span></label>
                    <input type="text" id="tipTitle" maxlength="120" placeholder="e.g. Adding an event">
                </div>
                <div class="hlp-field">
                    <label for="tipBody">Tip text</label>
                    <textarea id="tipBody" rows="4" placeholder="Keep it short and friendly. Line breaks are preserved."></textarea>
                </div>
                <div class="hlp-field">
                    <label for="tipAnchor">Anchor selector <span style="font-weight:400;color:#94a3b8">(optional)</span></label>
                    <input type="text" id="tipAnchor" placeholder="e.g. .cal-add-btn  or  #addEventBtn">
                    <p class="hint">Leave blank and the bubble floats in the bottom-right corner. Enter a CSS selector to make the bubble point at a specific button or area on the page.</p>
                    <details class="hlp-help">
                        <summary>How anchoring works &amp; how to find a selector</summary>
                        <div class="hlp-help-body">
                            <p>A <strong>selector</strong> names the element to point at. The most common forms:</p>
                            <ul>
                                <li><code>.class-name</code> &mdash; points at the first element with that CSS class (e.g. <code>.cal-add-btn</code>). Starts with a dot.</li>
                                <li><code>#id-name</code> &mdash; points at the element with that exact id (e.g. <code>#addEventBtn</code>). Starts with a hash.</li>
                                <li><code>a[href="/contacts.php"]</code> &mdash; points at a link by its address.</li>
                            </ul>
                            <p><strong>To find one:</strong> open the screen in your browser, right-click the button or area you want to point at, choose <em>Inspect</em>, and read the highlighted tag. Use its <code>id="..."</code> as <code>#that-id</code>, or one of its <code>class="..."</code> names as <code>.that-class</code> (pick a distinctive one).</p>
                            <p><strong>Behaviour:</strong> the bubble sits just below the element (or above it if there's no room) with a little pointer. If the selector matches nothing on that page, the bubble safely falls back to the corner &mdash; it never breaks the page.</p>
                            <p><strong>Popups &amp; modals:</strong> you can anchor a tip to an element inside a popup (e.g. the calendar's Add Event editor). The bubble waits until the popup opens, then appears pointing at the element, and hides again when the popup closes. If the waiting tip is the only one in its step, it shows in the corner until the popup opens. The live preview here always shows the corner position because the target page's elements aren't present on this admin screen.</p>
                        </div>
                    </details>
                </div>
                <div class="hlp-field">
                    <label for="tipIndex">Step index <span style="font-weight:400;color:#94a3b8">(optional)</span></label>
                    <input type="number" id="tipIndex" min="1" step="1" placeholder="e.g. 1" style="max-width:140px">
                    <p class="hint">Tips that share the same index number appear together as one step (Back/Next moves between steps). Leave blank and the tip is its own step.</p>
                </div>
                <div style="display:flex;gap:.5rem">
                    <button type="button" class="hlp-btn primary" onclick="saveTip()">Save tip</button>
                    <button type="button" class="hlp-btn" onclick="resetForm()">Clear</button>
                    <button type="button" class="hlp-btn" style="margin-left:auto" onclick="showPreview()">Show example &#9654;</button>
                </div>
                <p id="hlpMsg"></p>
            </form>
        </div>

        <!-- Tip list -->
        <div class="hlp-card">
            <h2>Tips on this screen</h2>
            <div id="tipList"></div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/_footer.php'; ?>

<script>
const CSRF   = <?= json_encode($token) ?>;
const SCREEN = <?= json_encode($screen) ?>;
let TIPS     = <?= json_encode($tips, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
}

function renderList() {
    const box = document.getElementById('tipList');
    if (!TIPS.length) {
        box.innerHTML = '<p class="hlp-empty">No tips yet for this screen. Add one on the left.</p>';
        return;
    }
    box.innerHTML = TIPS.map((t, i) => {
        const on = Number(t.enabled) === 1;
        return `<div class="hlp-tip ${on ? '' : 'off'}">
            <div class="hlp-tip-head">
                <span class="hlp-tip-title">${t.title ? esc(t.title) : '<span style="color:#94a3b8;font-weight:400">(no title)</span>'}</span>
                <span class="hlp-badge ${on ? 'on' : 'off'}">${on ? 'Shown' : 'Hidden'}</span>
            </div>
            <div class="hlp-tip-body">${esc(t.body)}</div>
            ${t.anchor_selector ? `<div class="hlp-tip-anchor">&#128279; ${esc(t.anchor_selector)}</div>` : ''}
            ${(t.bubble_index !== null && t.bubble_index !== undefined && t.bubble_index !== '') ? `<div class="hlp-tip-anchor" style="color:#2563eb">&#9635; Step index ${esc(t.bubble_index)}</div>` : ''}
            <div class="hlp-tip-actions">
                <button class="hlp-btn" onclick="moveTip(${i},-1)" ${i === 0 ? 'disabled' : ''}>&#9650;</button>
                <button class="hlp-btn" onclick="moveTip(${i},1)" ${i === TIPS.length - 1 ? 'disabled' : ''}>&#9660;</button>
                <button class="hlp-btn" onclick="editTip(${t.id})">Edit</button>
                <button class="hlp-btn" onclick="toggleTip(${t.id})">${on ? 'Hide' : 'Show'}</button>
                <button class="hlp-btn danger" onclick="deleteTip(${t.id})">Delete</button>
            </div>
        </div>`;
    }).join('');
}

function msg(text, isErr) {
    const m = document.getElementById('hlpMsg');
    m.textContent = text || '';
    m.style.color = isErr ? '#b91c1c' : '#166534';
}

async function post(fields) {
    const data = new URLSearchParams();
    data.set('csrf_token', CSRF);
    data.set('screen', SCREEN);
    for (const k in fields) data.set(k, fields[k]);
    const res = await fetch('/admin_help_dl.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: data.toString(),
        credentials: 'same-origin'
    });
    return res.json();
}

async function saveTip() {
    const id   = document.getElementById('tipId').value;
    const body = document.getElementById('tipBody').value.trim();
    if (!body) { msg('Tip text is required.', true); return; }
    const fields = {
        action: id ? 'update' : 'create',
        title:  document.getElementById('tipTitle').value,
        body:   body,
        anchor_selector: document.getElementById('tipAnchor').value,
        bubble_index: document.getElementById('tipIndex').value
    };
    if (id) fields.id = id;
    const r = await post(fields);
    if (!r.ok) { msg(r.error || 'Save failed.', true); return; }
    TIPS = r.tips;
    renderList();
    resetForm();
    msg('Saved.');
}

function editTip(id) {
    const t = TIPS.find(x => Number(x.id) === Number(id));
    if (!t) return;
    document.getElementById('tipId').value = t.id;
    document.getElementById('tipTitle').value = t.title || '';
    document.getElementById('tipBody').value = t.body || '';
    document.getElementById('tipAnchor').value = t.anchor_selector || '';
    document.getElementById('tipIndex').value = (t.bubble_index !== null && t.bubble_index !== undefined) ? t.bubble_index : '';
    document.getElementById('formHeading').textContent = 'Edit tip';
    msg('');
    document.getElementById('tipTitle').focus();
}

function resetForm() {
    document.getElementById('tipId').value = '';
    document.getElementById('tipTitle').value = '';
    document.getElementById('tipBody').value = '';
    document.getElementById('tipAnchor').value = '';
    document.getElementById('tipIndex').value = '';
    document.getElementById('formHeading').textContent = 'Add a tip';
}

async function toggleTip(id) {
    const r = await post({ action: 'toggle', id });
    if (r.ok) { TIPS = r.tips; renderList(); }
}

async function deleteTip(id) {
    if (!confirm('Delete this tip?')) return;
    const r = await post({ action: 'delete', id });
    if (r.ok) { TIPS = r.tips; renderList(); msg('Deleted.'); }
}

async function moveTip(i, dir) {
    const j = i + dir;
    if (j < 0 || j >= TIPS.length) return;
    const tmp = TIPS[i]; TIPS[i] = TIPS[j]; TIPS[j] = tmp;
    renderList();
    const ids = TIPS.map(t => t.id).join(',');
    const r = await post({ action: 'reorder', ids });
    if (r.ok) { TIPS = r.tips; renderList(); }
}

function showPreview() {
    document.querySelectorAll('.help-bubble,.help-pill,.help-stack').forEach(e => e.remove());
    const tips = TIPS.filter(t => Number(t.enabled) === 1).map(t => ({
        id: t.id, title: t.title || '', body: t.body || '', anchor_selector: t.anchor_selector || '',
        idx: (t.bubble_index !== null && t.bubble_index !== undefined && t.bubble_index !== '') ? Number(t.bubble_index) : null
    }));
    if (!tips.length) { msg('No shown tips to preview. Add one (or unhide) first.', true); return; }
    window.__help = { screen: SCREEN, tips, dismissed: false, csrf: '', preview: true };
    const s = document.createElement('script');
    s.src = '/help-bubble.js?preview=' + Date.now();
    document.body.appendChild(s);
    msg('Showing example bubble (anchored tips fall back to the corner here).');
}

renderList();
</script>
</body>
</html>
