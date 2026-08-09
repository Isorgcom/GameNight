<?php
/**
 * One support ticket's thread. Visible to its owner and admins only. Replies
 * post through support_dl.php; admins get an Open/Resolved toggle, and a user
 * reply on a resolved ticket reopens it.
 */
require_once __DIR__ . '/auth.php';

$current = require_login();
$db  = get_db();
$uid = (int)$current['id'];
$isAdmin   = $current['role'] === 'admin';
$site_name = get_setting('site_name', 'Game Night');
$csrf = csrf_token();

$tid = (int)($_GET['id'] ?? 0);
$t = $db->prepare('SELECT t.*, u.username AS owner_name FROM tickets t JOIN users u ON u.id = t.user_id WHERE t.id = ?');
$t->execute([$tid]);
$ticket = $t->fetch();
if (!$ticket || (!$isAdmin && (int)$ticket['user_id'] !== $uid)) {
    http_response_code(404);
    exit('Ticket not found.');
}

$mq = $db->prepare('SELECT m.*, u.username FROM ticket_messages m JOIN users u ON u.id = m.user_id
                    WHERE m.ticket_id = ? ORDER BY m.id');
$mq->execute([$tid]);
$messages = $mq->fetchAll();

// Reading the thread clears its bell rows.
try {
    $db->prepare("UPDATE user_notifications SET is_read = 1
                  WHERE user_id = ? AND notify_type IN ('ticket_reply','ticket_admin')
                    AND link = ? AND is_read = 0")
       ->execute([$uid, '/support_ticket.php?id=' . $tid]);
} catch (Throwable $e) {}

$viewer_tz = new DateTimeZone(display_timezone($uid));
$utc_tz    = new DateTimeZone('UTC');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ticket #<?= (int)$ticket['id'] ?> &ndash; <?= htmlspecialchars($site_name) ?></title>
    <link rel="stylesheet" href="/style.css?v=<?= htmlspecialchars(APP_VERSION . '.' . (@filemtime(__DIR__ . '/style.css') ?: 0)) ?>">
    <style>
        .st-wrap { max-width: 680px; margin: 1.25rem auto 2rem; padding: 0 1rem; }
        .st-head { display: flex; align-items: center; gap: .6rem; flex-wrap: wrap; margin-bottom: .35rem; }
        .st-head h1 { font-size: 1.15rem; font-weight: 800; color: #1e293b; margin: 0; flex: 1; min-width: 0; }
        .sp-chip { font-size: .68rem; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; border-radius: 5px; padding: .1rem .45rem; }
        .sp-open { color: #92400e; background: #fef3c7; border: 1px solid #fde68a; }
        .sp-resolved { color: #166534; background: #dcfce7; border: 1px solid #bbf7d0; }
        .st-msg { background: #fff; border: 1.5px solid #e2e8f0; border-radius: 10px; padding: .65rem .85rem; margin-bottom: .55rem; }
        .st-msg.admin { background: #eff6ff; border-color: #bfdbfe; }
        .st-meta { display: flex; gap: .5rem; align-items: baseline; font-size: .75rem; color: #94a3b8; margin-bottom: .3rem; }
        .st-meta .who { font-weight: 700; color: #334155; }
        .st-admin-tag { font-size: .62rem; font-weight: 700; text-transform: uppercase; color: #1e40af; background: #dbeafe; border-radius: 4px; padding: .05rem .3rem; }
        .st-body { font-size: .9rem; color: #334155; line-height: 1.5; white-space: pre-wrap; word-wrap: break-word; }
        .st-shot { display: block; max-width: 220px; max-height: 160px; border-radius: 8px; border: 1.5px solid #e2e8f0; margin-top: .5rem; }
        .st-reply textarea { width: 100%; padding: .5rem .65rem; border: 1.5px solid #cbd5e1; border-radius: 8px; font: inherit; box-sizing: border-box; }
    </style>
</head>
<body>
<?php $nav_active = 'support'; require __DIR__ . '/_nav.php'; ?>
<div class="st-wrap">
    <div class="st-head">
        <a href="<?= $isAdmin ? '/admin_tickets.php' : '/support.php' ?>" class="btn btn-outline" style="font-size:.8rem;padding:.3rem .6rem;text-decoration:none">&laquo; Tickets</a>
        <h1>#<?= (int)$ticket['id'] ?> &middot; <?= htmlspecialchars($ticket['subject']) ?></h1>
        <span class="sp-chip <?= $ticket['status'] === 'open' ? 'sp-open' : 'sp-resolved' ?>" id="stStatusChip"><?= htmlspecialchars($ticket['status']) ?></span>
        <?php if ($isAdmin): ?>
        <button type="button" class="btn btn-outline" style="font-size:.78rem;padding:.28rem .6rem" id="stStatusBtn"
                data-act="toggleStatus" data-a1="@self"><?= $ticket['status'] === 'open' ? 'Mark resolved' : 'Reopen' ?></button>
        <?php endif; ?>
    </div>
    <p style="font-size:.78rem;color:#94a3b8;margin:0 0 1rem">Opened by <?= htmlspecialchars($ticket['owner_name']) ?></p>

    <?php foreach ($messages as $m):
        try { $when = (new DateTime((string)$m['created_at'], $utc_tz))->setTimezone($viewer_tz)->format('M j, g:i A'); }
        catch (Throwable $e) { $when = ''; }
    ?>
    <div class="st-msg <?= (int)$m['is_admin_reply'] === 1 ? 'admin' : '' ?>">
        <div class="st-meta">
            <span class="who"><?= htmlspecialchars($m['username']) ?></span>
            <?php if ((int)$m['is_admin_reply'] === 1): ?><span class="st-admin-tag">Admin</span><?php endif; ?>
            <span><?= htmlspecialchars($when) ?></span>
        </div>
        <div class="st-body"><?= htmlspecialchars($m['body']) ?></div>
        <?php if (!empty($m['screenshot_path'])): ?>
        <a href="<?= htmlspecialchars($m['screenshot_path']) ?>" target="_blank" rel="noopener"><img class="st-shot" src="<?= htmlspecialchars($m['screenshot_path']) ?>" alt="Screenshot"></a>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>

    <div class="st-reply" style="margin-top:1rem">
        <textarea id="stBody" rows="3" maxlength="5000" placeholder="Write a reply&hellip;"></textarea>
        <div style="display:flex;align-items:center;gap:.6rem;margin-top:.5rem;flex-wrap:wrap">
            <button class="btn btn-primary" type="button" data-act="sendReply" data-a1="@self">Reply</button>
            <label style="font-size:.8rem;color:#64748b">&#128247; Attach screenshot
                <input type="file" id="stShot" accept="image/*" style="font-size:.8rem">
            </label>
        </div>
        <?php if ($ticket['status'] === 'resolved' && !$isAdmin): ?>
        <p style="font-size:.78rem;color:#94a3b8;margin-top:.4rem">This ticket is resolved — replying will reopen it.</p>
        <?php endif; ?>
    </div>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
<script nonce="<?= csp_nonce() ?>">
var CSRF = <?= json_encode($csrf) ?>;
var TID = <?= (int)$ticket['id'] ?>;
var SHOT_PATH = '';

document.getElementById('stShot').addEventListener('change', function () {
    var f = this.files && this.files[0];
    SHOT_PATH = '';
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
        })
        .catch(function () { pkAlert('Upload failed.'); input.value = ''; });
});

function sendReply(btn) {
    var body = document.getElementById('stBody').value.trim();
    if (!body) { pkAlert('Write a reply first.'); return; }
    var fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('action', 'reply');
    fd.append('ticket_id', TID);
    fd.append('body', body);
    fd.append('screenshot_path', SHOT_PATH);
    var p = fetch('/support_dl.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) {
            if (!j.ok) { pkAlert(j.error || 'Could not send the reply.'); return; }
            location.reload();
        });
    if (typeof pkBusy === 'function') pkBusy(btn, p);
}

function toggleStatus(btn) {
    var to = document.getElementById('stStatusChip').textContent.trim() === 'open' ? 'resolved' : 'open';
    var fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('action', 'set_status');
    fd.append('ticket_id', TID);
    fd.append('status', to);
    var p = fetch('/support_dl.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) {
            if (!j.ok) { pkAlert(j.error || 'Failed.'); return; }
            location.reload();
        });
    if (typeof pkBusy === 'function') pkBusy(btn, p);
}
</script>
</body>
</html>
