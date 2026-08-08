<?php
/**
 * Support — open a ticket (subject + description + optional screenshot) and
 * see your existing tickets. Threads live on support_ticket.php; admins get
 * the full queue on admin_tickets.php.
 */
require_once __DIR__ . '/auth.php';

$current = require_login();
$db  = get_db();
$uid = (int)$current['id'];
$isAdmin   = $current['role'] === 'admin';
$site_name = get_setting('site_name', 'Game Night');
$csrf = csrf_token();

$tq = $db->prepare("SELECT t.*, (SELECT COUNT(*) FROM ticket_messages m WHERE m.ticket_id = t.id) AS msg_count
                    FROM tickets t WHERE t.user_id = ?
                    ORDER BY CASE t.status WHEN 'open' THEN 0 ELSE 1 END, t.updated_at DESC");
$tq->execute([$uid]);
$tickets = $tq->fetchAll();

$viewer_tz = new DateTimeZone(display_timezone($uid));
$utc_tz    = new DateTimeZone('UTC');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Support &ndash; <?= htmlspecialchars($site_name) ?></title>
    <link rel="stylesheet" href="/style.css?v=<?= htmlspecialchars(APP_VERSION . '.' . (@filemtime(__DIR__ . '/style.css') ?: 0)) ?>">
    <style>
        .sp-wrap { max-width: 680px; margin: 1.25rem auto 2rem; padding: 0 1rem; }
        .sp-card { background: #fff; border: 1.5px solid #e2e8f0; border-radius: 12px; padding: 1rem 1.15rem; margin-bottom: 1.1rem; }
        .sp-card h2 { font-size: 1rem; font-weight: 800; color: #1e293b; margin: 0 0 .6rem; }
        .sp-field { margin-bottom: .75rem; }
        .sp-field label { display: block; font-size: .8rem; font-weight: 700; color: #475569; margin-bottom: .25rem; }
        .sp-field input[type=text], .sp-field textarea { width: 100%; padding: .5rem .65rem; border: 1.5px solid #cbd5e1; border-radius: 8px; font: inherit; box-sizing: border-box; }
        .sp-row { display: block; background: #fff; border: 1.5px solid #e2e8f0; border-radius: 10px; padding: .65rem .85rem; margin-bottom: .5rem; text-decoration: none; color: inherit; }
        .sp-row:hover { border-color: #93c5fd; }
        .sp-top { display: flex; align-items: baseline; gap: .5rem; flex-wrap: wrap; }
        .sp-subject { font-weight: 700; color: #1e293b; font-size: .92rem; flex: 1; min-width: 0; }
        .sp-when { font-size: .72rem; color: #94a3b8; white-space: nowrap; }
        .sp-chip { font-size: .66rem; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; border-radius: 5px; padding: .08rem .4rem; }
        .sp-open { color: #92400e; background: #fef3c7; border: 1px solid #fde68a; }
        .sp-resolved { color: #166534; background: #dcfce7; border: 1px solid #bbf7d0; }
        .sp-shot-hint { font-size: .75rem; color: #94a3b8; margin-top: .25rem; }
        #spShotPreview { display: none; max-width: 140px; max-height: 100px; border-radius: 8px; border: 1.5px solid #e2e8f0; margin-top: .4rem; }
    </style>
</head>
<body>
<?php $nav_active = 'support'; require __DIR__ . '/_nav.php'; ?>
<div class="sp-wrap">
    <h1 style="font-size:1.3rem;font-weight:800;color:#1e293b;margin:0 0 .35rem">Support</h1>
    <p style="color:#64748b;font-size:.88rem;margin:0 0 1rem">Problem, question, or idea? Open a ticket and the site admins will get back to you.</p>
    <?php if ($isAdmin): ?>
    <p style="margin:0 0 1rem"><a class="btn btn-outline" style="text-decoration:none;font-size:.82rem" href="/admin_tickets.php">Admin: open the full ticket queue</a></p>
    <?php endif; ?>

    <div class="sp-card">
        <h2>Open a ticket</h2>
        <div class="sp-field">
            <label for="spSubject">Subject</label>
            <input type="text" id="spSubject" maxlength="150" placeholder="Short summary of the problem">
        </div>
        <div class="sp-field">
            <label for="spBody">What happened?</label>
            <textarea id="spBody" rows="4" maxlength="5000" placeholder="What did you do, what did you expect, what happened instead?"></textarea>
        </div>
        <div class="sp-field">
            <label for="spShot">Screenshot <span style="font-weight:400;color:#94a3b8">(optional)</span></label>
            <input type="file" id="spShot" accept="image/*" style="font-size:.85rem">
            <div class="sp-shot-hint">JPEG/PNG/GIF/WebP, up to 8 MB.</div>
            <img id="spShotPreview" alt="Screenshot preview">
        </div>
        <button class="btn btn-primary" type="button" id="spSubmit" onclick="createTicket(this)">Submit ticket</button>
    </div>

    <h2 style="font-size:1rem;font-weight:800;color:#1e293b;margin:0 0 .6rem">My tickets</h2>
    <?php if (!$tickets): ?>
    <p style="color:#94a3b8;font-size:.88rem">No tickets yet.</p>
    <?php else: foreach ($tickets as $t):
        try { $when = (new DateTime((string)$t['updated_at'], $utc_tz))->setTimezone($viewer_tz)->format('M j, g:i A'); }
        catch (Throwable $e) { $when = ''; }
    ?>
    <a class="sp-row" href="/support_ticket.php?id=<?= (int)$t['id'] ?>">
        <div class="sp-top">
            <span class="sp-chip <?= $t['status'] === 'open' ? 'sp-open' : 'sp-resolved' ?>"><?= htmlspecialchars($t['status']) ?></span>
            <span class="sp-subject">#<?= (int)$t['id'] ?> &middot; <?= htmlspecialchars($t['subject']) ?></span>
            <span class="sp-when"><?= (int)$t['msg_count'] ?> message<?= (int)$t['msg_count'] === 1 ? '' : 's' ?> &middot; <?= htmlspecialchars($when) ?></span>
        </div>
    </a>
    <?php endforeach; endif; ?>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
<script>
var CSRF = <?= json_encode($csrf) ?>;
var SHOT_PATH = '';

document.getElementById('spShot').addEventListener('change', function () {
    var f = this.files && this.files[0];
    SHOT_PATH = '';
    var prev = document.getElementById('spShotPreview');
    prev.style.display = 'none';
    if (!f) return;
    var fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('image', f);
    var input = this;
    fetch('/upload.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) {
            if (!j.url) { pkAlert(j.error || 'Upload failed.'); input.value = ''; return; }
            SHOT_PATH = j.url;
            prev.src = j.url;
            prev.style.display = 'block';
        })
        .catch(function () { pkAlert('Upload failed.'); input.value = ''; });
});

function createTicket(btn) {
    var subject = document.getElementById('spSubject').value.trim();
    var body    = document.getElementById('spBody').value.trim();
    if (!subject) { pkAlert('Enter a subject.'); return; }
    if (!body) { pkAlert('Describe the problem or question.'); return; }
    var fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('action', 'create');
    fd.append('subject', subject);
    fd.append('body', body);
    fd.append('screenshot_path', SHOT_PATH);
    var p = fetch('/support_dl.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) {
            if (!j.ok) { pkAlert(j.error || 'Could not open the ticket.'); return; }
            location.href = '/support_ticket.php?id=' + j.id;
        });
    if (typeof pkBusy === 'function') pkBusy(btn, p);
}
</script>
</body>
</html>
