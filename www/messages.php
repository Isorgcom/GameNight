<?php
/**
 * Direct messages — conversation list (1:1 and groups). Each row links into
 * the thread page. "New message" starts a 1:1; "New group" picks multiple
 * people (scope-checked server-side) with an optional name.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_dm.php';

$current = require_login();
$db  = get_db();
$uid = (int)$current['id'];
$site_name = get_setting('site_name', 'Game Night');
$csrf = csrf_token();

// My conversations with last visible message + unread count (watermark-aware).
$q = $db->prepare("
    SELECT c.*, p.cleared_before_id, p.last_read_msg_id,
           (SELECT m.body FROM dm_messages m WHERE m.conversation_id = c.id
              AND m.id > p.cleared_before_id ORDER BY m.id DESC LIMIT 1) AS last_body,
           (SELECT u2.username FROM dm_messages m JOIN users u2 ON u2.id = m.sender_id
             WHERE m.conversation_id = c.id AND m.id > p.cleared_before_id
             ORDER BY m.id DESC LIMIT 1) AS last_sender,
           (SELECT m.created_at FROM dm_messages m WHERE m.conversation_id = c.id
              AND m.id > p.cleared_before_id ORDER BY m.id DESC LIMIT 1) AS last_at,
           (SELECT COUNT(*) FROM dm_messages m WHERE m.conversation_id = c.id
              AND m.sender_id <> :me
              AND m.id > MAX(p.last_read_msg_id, p.cleared_before_id)) AS unread
    FROM dm_participants p
    JOIN dm_conversations c ON c.id = p.conversation_id
    WHERE p.user_id = :me
    ORDER BY COALESCE(c.last_message_at, c.created_at) DESC");
$q->execute([':me' => $uid]);
$convos = array_values(array_filter($q->fetchAll(), fn($c) => $c['last_body'] !== null || !empty($c['is_group'])));

// Pair partner ids (to hide from the "new message" picker) + display titles + avatars.
$pairOther = [];
$avq = $db->prepare('SELECT username, avatar_path FROM users WHERE id = ?');
foreach ($convos as $i => $c) {
    $convos[$i]['display'] = dm_conversation_title($db, $c, $uid);
    if (empty($c['is_group']) && !empty($c['pair_key'])) {
        [$a, $b] = array_map('intval', explode(':', $c['pair_key']));
        $otherId = $a === $uid ? $b : $a;
        $pairOther[$otherId] = true;
        // 1:1 row → the other user's avatar (photo or colored initial).
        $avq->execute([$otherId]);
        $ou = $avq->fetch();
        $convos[$i]['avatar'] = avatar_html($ou['username'] ?? $convos[$i]['display'], $ou['avatar_path'] ?? null, 40);
    } else {
        // Group/event → colored initial of the group title.
        $convos[$i]['avatar'] = avatar_html($convos[$i]['display'], null, 40);
    }
}

$eligible = dm_eligible_recipients($db, $current);
$pickerEligible = array_diff_key($eligible, $pairOther);

$viewer_tz = new DateTimeZone(display_timezone($uid));
$utc_tz    = new DateTimeZone('UTC');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Messages &ndash; <?= htmlspecialchars($site_name) ?></title>
    <link rel="stylesheet" href="/style.css?v=<?= htmlspecialchars(APP_VERSION . '.' . (@filemtime(__DIR__ . '/style.css') ?: 0)) ?>">
    <style>
        .dm-wrap { max-width: 680px; margin: 1.25rem auto 2rem; padding: 0 1rem; }
        .dm-row { display:block; background:#fff; border:1.5px solid #e2e8f0; border-radius:10px; padding:.7rem .9rem; margin-bottom:.5rem; text-decoration:none; color:inherit; }
        .dm-row:hover { border-color:#93c5fd; }
        .dm-row.unread { background:#eff6ff; border-color:#bfdbfe; }
        .dm-top { display:flex; align-items:baseline; gap:.5rem; flex-wrap:wrap; }
        .dm-name { font-weight:700; color:#1e293b; font-size:.95rem; flex:1; min-width:0; }
        .dm-group-tag { font-size:.62rem; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:#7c3aed; background:#f3e8ff; border:1px solid #ddd6fe; border-radius:5px; padding:.06rem .35rem; }
        .dm-when { font-size:.72rem; color:#94a3b8; white-space:nowrap; }
        .dm-pill { background:#dc2626; color:#fff; font-size:.65rem; font-weight:700; border-radius:999px; padding:.05rem .4rem; }
        .dm-snippet { font-size:.82rem; color:#64748b; margin-top:.25rem; overflow:hidden; display:-webkit-box; -webkit-line-clamp:1; -webkit-box-orient:vertical; }
        .dm-new { display:flex; gap:.5rem; margin-bottom:.6rem; flex-wrap:wrap; }
        .dm-new select { flex:1; min-width:200px; padding:.45rem .6rem; border:1.5px solid #e2e8f0; border-radius:8px; font-size:.9rem; }
        .dm-group-card { display:none; background:#faf5ff; border:1.5px solid #ddd6fe; border-radius:10px; padding:.75rem 1rem; margin-bottom:1rem; }
        .dm-group-card.open { display:block; }
        .dm-group-card label { display:block; font-size:.8rem; font-weight:700; color:#5b21b6; margin:.4rem 0 .25rem; }
        .dm-group-card input[type=text] { width:100%; padding:.45rem .6rem; border:1.5px solid #ddd6fe; border-radius:8px; font-size:.9rem; box-sizing:border-box; }
        .dm-member-list { max-height:180px; overflow-y:auto; background:#fff; border:1.5px solid #ddd6fe; border-radius:8px; padding:.4rem .6rem; }
        .dm-member-list label { display:flex; align-items:center; gap:.45rem; font-size:.88rem; font-weight:400; color:#1e293b; margin:.15rem 0; }
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
            <?php foreach ($pickerEligible as $eid => $ename): ?>
            <option value="<?= (int)$eid ?>"><?= htmlspecialchars($ename) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="btn" type="button" data-act="startDmWithSelected">Start</button>
        <button class="btn btn-outline" type="button" data-toggle-class="dmGroupCard:open">New group</button>
    </div>

    <div class="dm-group-card" id="dmGroupCard">
        <label for="dmGroupName">Group name <span style="font-weight:400;color:#7c3aed">(optional)</span></label>
        <input type="text" id="dmGroupName" maxlength="60" placeholder="e.g. Friday Night Crew">
        <label>Members <span style="font-weight:400;color:#7c3aed">(pick at least two)</span></label>
        <div class="dm-member-list">
            <?php foreach ($eligible as $eid => $ename): ?>
            <label><input type="checkbox" class="dm-member-cb" value="<?= (int)$eid ?>"> <?= htmlspecialchars($ename) ?></label>
            <?php endforeach; ?>
        </div>
        <div style="margin-top:.6rem">
            <button class="btn" type="button" data-act="createGroup" data-a1="@self">Create group</button>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!$convos): ?>
    <p style="color:#64748b">No conversations yet<?= $eligible ? ' — pick someone above to start one.' : '.' ?></p>
    <?php else: ?>
    <?php foreach ($convos as $c):
        try { $when = $c['last_at'] ? (new DateTime((string)$c['last_at'], $utc_tz))->setTimezone($viewer_tz)->format('M j, g:i A') : ''; }
        catch (Throwable $e) { $when = ''; }
        $isGroup = !empty($c['is_group']);
        if ($isGroup) {
            $href = '/message_thread.php?conv=' . (int)$c['id'];
        } else {
            [$a, $b] = array_map('intval', explode(':', (string)$c['pair_key']));
            $href = '/message_thread.php?user=' . ($a === $uid ? $b : $a);
        }
    ?>
    <a class="dm-row <?= (int)$c['unread'] > 0 ? 'unread' : '' ?>" href="<?= htmlspecialchars($href) ?>" style="display:flex;gap:.65rem;align-items:center">
        <?= $c['avatar'] ?>
        <div style="flex:1;min-width:0">
            <div class="dm-top">
                <span class="dm-name"><?= htmlspecialchars($c['display']) ?></span>
                <?php if ($isGroup): ?><span class="dm-group-tag"><?= !empty($c['event_id']) ? 'Event' : 'Group' ?></span><?php endif; ?>
                <?php if ((int)$c['unread'] > 0): ?><span class="dm-pill"><?= (int)$c['unread'] ?></span><?php endif; ?>
                <span class="dm-when"><?= htmlspecialchars($when) ?></span>
            </div>
            <?php if ($c['last_body'] !== null): ?>
            <div class="dm-snippet"><?= $isGroup && $c['last_sender'] ? htmlspecialchars($c['last_sender']) . ': ' : '' ?><?= htmlspecialchars(mb_substr((string)$c['last_body'], 0, 160)) ?></div>
            <?php endif; ?>
        </div>
    </a>
    <?php endforeach; ?>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
<script nonce="<?= csp_nonce() ?>">
var CSRF = <?= json_encode($csrf) ?>;

function createGroup(btn) {
    var ids = [...document.querySelectorAll('.dm-member-cb:checked')].map(function(cb) { return cb.value; });
    if (ids.length < 2) { pkAlert('Pick at least two people for a group.'); return; }
    var fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('action', 'create_group');
    fd.append('title', document.getElementById('dmGroupName').value.trim());
    fd.append('user_ids', ids.join(','));
    var p = fetch('/messages_dl.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(j) {
            if (!j.ok) { pkAlert(j.error || 'Could not create the group.'); return; }
            location.href = '/message_thread.php?conv=' + j.conv_id;
        });
    if (typeof pkBusy === 'function') pkBusy(btn, p);
}

// Auto-refresh the list when a new message lands (visible tab only).
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
<script nonce="<?= csp_nonce() ?>">
function startDmWithSelected() {
    var v = document.getElementById('dmNewUser').value;
    if (!v) { pkAlert('Pick a person first.'); return; }
    location.href = '/message_thread.php?with=' + encodeURIComponent(v);
}
</script>
</body>
</html>
