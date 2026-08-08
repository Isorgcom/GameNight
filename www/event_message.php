<?php
/**
 * Token-only view of a host-authored event message ("final details").
 *
 * Linked from the SMS/email/WhatsApp notification so recipients (who may not have
 * an account) can read the full note in a browser. The unguessable token in
 * event_messages.token is the access grant — no login required.
 */
require_once __DIR__ . '/auth.php';

$site_name = get_setting('site_name', 'Game Night');
$token     = trim($_GET['token'] ?? '');
$msg = null;

if ($token !== '') {
    $stmt = get_db()->prepare(
        'SELECT m.subject, m.body_html, m.occurrence_date, m.created_at,
                e.title AS event_title, e.start_date, e.start_time
         FROM event_messages m JOIN events e ON e.id = m.event_id
         WHERE m.token = ?'
    );
    $stmt->execute([$token]);
    $msg = $stmt->fetch();
}

$event_date = $msg ? ($msg['occurrence_date'] ?: $msg['start_date']) : '';
$pretty_date = $event_date ? date('l, F j, Y', strtotime($event_date)) : '';
$pretty_time = (!empty($msg['start_time'])) ? date('g:i A', strtotime($msg['start_time'])) : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow">
    <title><?= htmlspecialchars($msg ? $msg['subject'] : 'Message') ?> — <?= htmlspecialchars($site_name) ?></title>
    <link rel="stylesheet" href="/style.css?v=<?= htmlspecialchars(APP_VERSION . '.' . (@filemtime(__DIR__ . '/style.css') ?: 0)) ?>">
</head>
<body>
<nav><div class="nav-top"><a class="brand" href="/"><?= htmlspecialchars($site_name) ?></a></div></nav>

<div class="card-wrap">
    <div class="card" style="max-width:640px">
        <?php if (!$msg): ?>
            <h2>Message Not Found</h2>
            <div class="alert alert-error">This link is invalid or the message is no longer available.</div>
        <?php else: ?>
            <p class="subtitle" style="margin-bottom:.25rem">
                <?= htmlspecialchars($msg['event_title']) ?><?php if ($pretty_date): ?> &middot; <?= htmlspecialchars($pretty_date) ?><?php if ($pretty_time): ?> at <?= htmlspecialchars($pretty_time) ?><?php endif; ?><?php endif; ?>
            </p>
            <h2 style="margin-top:0"><?= htmlspecialchars($msg['subject']) ?></h2>
            <div class="event-message-body" style="margin-top:1rem;line-height:1.6">
                <?= $msg['body_html'] /* already sanitized via sanitize_html() at store time */ ?>
            </div>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
