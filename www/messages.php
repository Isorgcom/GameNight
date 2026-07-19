<?php
/**
 * Direct messages — conversation list. Each row links into the thread page.
 * "New message" offers only users the viewer is allowed to start with
 * (shared league/event, host/guest, linked contact, or admins).
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_dm.php';

$current = require_login();
$db  = get_db();
$uid = (int)$current['id'];
$site_name = get_setting('site_name', 'Game Night');
$csrf = csrf_token();

// Conversations I participate in, respecting my cleared watermark: show only
// pairs with at least one message newer than my watermark.
$q = $db->prepare("
    SELECT c.*,
           CASE WHEN c.user_a_id = :me THEN c.user_b_id ELSE c.user_a_id END AS other_id,
           u.username AS other_name,
           (SELECT body FROM dm_messages m WHERE m.conversation_id = c.id
              AND m.id > (CASE WHEN c.user_a_id = :me THEN c.a_cleared_before_id ELSE c.b_cleared_before_id END)
            ORDER BY m.id DESC LIMIT 1) AS last_body,
           (SELECT created_at FROM dm_messages m WHERE m.conversation_id = c.id
              AND m.id > (CASE WHEN c.user_a_id = :me THEN c.a_cleared_before_id ELSE c.b_cleared_before_id END)
            ORDER BY m.id DESC LIMIT 1) AS last_at,
           (SELECT COUNT(*) FROM dm_messages m WHERE m.conversation_id = c.id
              AND m.sender_id <> :me AND m.read_at IS NULL
              AND m.id > (CASE WHEN c.user_a_id = :me THEN c.a_cleared_before_id ELSE c.b_cleared_before_id END)) AS unread
    FROM dm_conversations c
    JOIN users u ON u.id = CASE WHEN c.user_a_id = :me THEN c.user_b_id ELSE c.user_a_id END
    WHERE (c.user_a_id = :me OR c.user_b_id = :me)
    ORDER BY COALESCE(c.last_message_at, c.created_at) DESC");
$q->execute([':me' => $uid]);
$convos = array_values(array_filter($q->fetchAll(), fn($c) => $c['last_body'] !== null));

$eligible = dm_eligible_recipients($db, $current);
// Drop users I already have a visible conversation with — they're in the list.
foreach ($convos as $c) unset($eligible[(int)$c['other_id']]);

$viewer_tz = new DateTimeZone(display_timezone($uid));
$utc_tz    = new DateTimeZone('UTC');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Messages &ndash; <?= htmlspecialchars($site_name) ?></title>
    <link rel="stylesheet" href="/style.css?v=<?= htmlspecialchars(APP_VERSION) ?>">
    <style>
        .dm-wrap { max-width: 680px; margin: 1.25rem auto 2rem; padding: 0 1rem; }
        .dm-row { display:block; background:#fff; border:1.5px solid #e2e8f0; border-radius:10px; padding:.7rem .9rem; margin-bottom:.5rem; text-decoration:none; color:inherit; }
        .dm-row:hover { border-color:#93c5fd; }
        .dm-row.unread { background:#eff6ff; border-color:#bfdbfe; }
        .dm-top { display:flex; align-items:baseline; gap:.5rem; flex-wrap:wrap; }
        .dm-name { font-weight:700; color:#1e293b; font-size:.95rem; flex:1; min-width:0; }
        .dm-when { font-size:.72rem; color:#94a3b8; white-space:nowrap; }
        .dm-pill { background:#dc2626; color:#fff; font-size:.65rem; font-weight:700; border-radius:999px; padding:.05rem .4rem; }
        .dm-snippet { font-size:.82rem; color:#64748b; margin-top:.25rem; overflow:hidden; display:-webkit-box; -webkit-line-clamp:1; -webkit-box-orient:vertical; }
        .dm-new { display:flex; gap:.5rem; margin-bottom:1.1rem; flex-wrap:wrap; }
        .dm-new select { flex:1; min-width:200px; padding:.45rem .6rem; border:1.5px solid #e2e8f0; border-radius:8px; font-size:.9rem; }
    </style>
</head>
<body>
<?php $nav_active = 'messages'; require __DIR__ . '/_nav.php'; ?>
<div class="dm-wrap">
    <h1 style="font-size:1.3rem;font-weight:800;color:#1e293b;margin:0 0 1rem">Messages</h1>

    <?php if ($eligible): ?>
    <div class="dm-new">
        <select id="dmNewUser">
            <option value="">New message to&hellip;</option>
            <?php foreach ($eligible as $eid => $ename): ?>
            <option value="<?= (int)$eid ?>"><?= htmlspecialchars($ename) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="btn" type="button" onclick="var v=document.getElementById('dmNewUser').value; if(!v){pkAlert('Pick a person first.');return;} location.href='/message_thread.php?user='+v;">Start</button>
    </div>
    <?php endif; ?>

    <?php if (!$convos): ?>
    <p style="color:#64748b">No conversations yet<?= $eligible ? ' — pick someone above to start one.' : '.' ?></p>
    <?php else: ?>
    <?php foreach ($convos as $c):
        try { $when = (new DateTime((string)$c['last_at'], $utc_tz))->setTimezone($viewer_tz)->format('M j, g:i A'); }
        catch (Throwable $e) { $when = ''; }
    ?>
    <a class="dm-row <?= (int)$c['unread'] > 0 ? 'unread' : '' ?>" href="/message_thread.php?user=<?= (int)$c['other_id'] ?>">
        <div class="dm-top">
            <span class="dm-name"><?= htmlspecialchars($c['other_name']) ?></span>
            <?php if ((int)$c['unread'] > 0): ?><span class="dm-pill"><?= (int)$c['unread'] ?></span><?php endif; ?>
            <span class="dm-when"><?= htmlspecialchars($when) ?></span>
        </div>
        <div class="dm-snippet"><?= htmlspecialchars(mb_substr((string)$c['last_body'], 0, 160)) ?></div>
    </a>
    <?php endforeach; ?>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
<script>
// Auto-refresh the list when a new message lands anywhere (visible tab only).
var CSRF = <?= json_encode($csrf) ?>;
var LIST_STATE = null;
function pollList() {
    if (document.hidden) return;
    var fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('action', 'list_status');
    fetch('/messages_dl.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(j) {
            if (!j.ok) return;
            var state = j.max_id + ':' + j.unread;
            if (LIST_STATE === null) { LIST_STATE = state; return; }
            if (state !== LIST_STATE) location.reload();
        }).catch(function() {});
}
pollList();
setInterval(pollList, 5000);
</script>
</body>
</html>
