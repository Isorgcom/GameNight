<?php
/**
 * In-app notification inbox — the durable history behind the nav bell.
 * Rows are written by dispatch_queued_notification() for registered recipients
 * (regardless of outbound channel preferences) and pruned at 90 days by cron.
 * Opening the page renders unread rows highlighted, then marks everything read.
 */
require_once __DIR__ . '/auth.php';

$current = require_login();
$db  = get_db();
$uid = (int)$current['id'];
$site_name = get_setting('site_name', 'Game Night');

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 30;
$cnt = $db->prepare('SELECT COUNT(*) FROM user_notifications WHERE user_id = ?');
$cnt->execute([$uid]);
$total = (int)$cnt->fetchColumn();
$pages = max(1, (int)ceil($total / $perPage));

$rows = $db->prepare('SELECT * FROM user_notifications WHERE user_id = ? ORDER BY id DESC LIMIT ? OFFSET ?');
$rows->execute([$uid, $perPage, ($page - 1) * $perPage]);
$notifs = $rows->fetchAll();

// Render with unread state, THEN mark everything read (so the highlight shows once).
$db->prepare('UPDATE user_notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0')->execute([$uid]);

// Human labels for notify types
$typeLabels = [
    'invite' => 'Invitation', 'rsvp_nudge' => 'RSVP reminder', 'reminder' => 'Event reminder',
    'event_updated' => 'Event updated', 'cancel_event' => 'Cancelled', 'cancel_occurrence' => 'Cancelled',
    'event_comment' => 'Comment', 'event_message' => 'Host message', 'event_poll' => 'Poll',
    'waitlist_promoted' => 'Waitlist', 'rsvp_deadline_demoted' => 'Waitlist', 'poker_approved' => 'Approved',
    'rsvp_to_creator' => 'RSVP reply',
];
$viewer_tz = new DateTimeZone(display_timezone($uid));
$utc_tz    = new DateTimeZone('UTC');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications &ndash; <?= htmlspecialchars($site_name) ?></title>
    <link rel="stylesheet" href="/style.css?v=<?= htmlspecialchars(APP_VERSION) ?>">
    <style>
        .nf-wrap { max-width: 680px; margin: 1.25rem auto 2rem; padding: 0 1rem; }
        .nf-row { display:block; background:#fff; border:1.5px solid #e2e8f0; border-radius:10px; padding:.7rem .9rem; margin-bottom:.5rem; text-decoration:none; color:inherit; }
        .nf-row:hover { border-color:#93c5fd; }
        .nf-row.unread { background:#eff6ff; border-color:#bfdbfe; }
        .nf-top { display:flex; align-items:baseline; gap:.5rem; flex-wrap:wrap; }
        .nf-tag { font-size:.66rem; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:#2563eb; background:#eff6ff; border:1px solid #bfdbfe; border-radius:5px; padding:.08rem .4rem; }
        .nf-row.unread .nf-tag { background:#fff; }
        .nf-subject { font-weight:700; color:#1e293b; font-size:.92rem; flex:1; min-width:0; }
        .nf-when { font-size:.72rem; color:#94a3b8; white-space:nowrap; }
        .nf-body { font-size:.82rem; color:#64748b; margin-top:.25rem; overflow:hidden; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; }
        .pager { display:flex; justify-content:center; gap:.6rem; align-items:center; margin-top:1rem; font-size:.85rem; color:#64748b; }
    </style>
</head>
<body>
<?php $nav_active = 'notifications'; require __DIR__ . '/_nav.php'; ?>
<div class="nf-wrap">
    <h1 style="font-size:1.3rem;font-weight:800;color:#1e293b;margin:0 0 1rem">Notifications</h1>

    <?php if (!$notifs): ?>
    <p style="color:#64748b">Nothing here yet — invitations, reminders, and updates for your events will collect here.</p>
    <p style="color:#94a3b8;font-size:.82rem">Tip: you can choose which of these also reach you by email or text in <a href="/settings.php">My Settings</a>.</p>
    <?php else: ?>

    <?php foreach ($notifs as $n):
        try { $when = (new DateTime((string)$n['created_at'], $utc_tz))->setTimezone($viewer_tz)->format('M j, g:i A'); }
        catch (Throwable $e) { $when = (string)$n['created_at']; }
        $tag  = $typeLabels[$n['notify_type']] ?? 'Notification';
        $link = (string)($n['link'] ?? '');
        $safeLink = ($link !== '' && preg_match('#^/(?![/\\\\])#', $link)) ? $link : '#';
    ?>
    <a class="nf-row <?= (int)$n['is_read'] === 0 ? 'unread' : '' ?>" href="<?= htmlspecialchars($safeLink) ?>">
        <div class="nf-top">
            <span class="nf-tag"><?= htmlspecialchars($tag) ?></span>
            <span class="nf-subject"><?= htmlspecialchars($n['subject']) ?></span>
            <span class="nf-when"><?= htmlspecialchars($when) ?></span>
        </div>
        <?php if (!empty($n['body'])): ?>
        <div class="nf-body"><?= htmlspecialchars($n['body']) ?></div>
        <?php endif; ?>
    </a>
    <?php endforeach; ?>

    <?php if ($pages > 1): ?>
    <div class="pager">
        <?php if ($page > 1): ?><a class="btn btn-outline" style="font-size:.8rem;padding:.3rem .6rem" href="?page=<?= $page - 1 ?>">&laquo; Newer</a><?php endif; ?>
        <span>Page <?= $page ?> of <?= $pages ?></span>
        <?php if ($page < $pages): ?><a class="btn btn-outline" style="font-size:.8rem;padding:.3rem .6rem" href="?page=<?= $page + 1 ?>">Older &raquo;</a><?php endif; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
</body>
</html>
