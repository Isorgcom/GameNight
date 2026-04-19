<?php
/**
 * cron_drain.php — Fast drain of the pending_notifications queue only.
 *
 * Invoked immediately after event saves via shell_exec(... &) so invite
 * notifications deliver in seconds instead of waiting up to 5 min for the
 * next cron.php tick. Also reachable via HTTP for the regular cron to call
 * as a fallback, but the 5-min cron already drains the queue directly so
 * this file is primarily the fire-and-forget path.
 *
 * Protected by the same cron_token as cron.php. CLI calls pass the token
 * as argv[1]; HTTP calls use ?token=.
 *
 * Exits quietly after draining. Safe to run concurrently with cron.php
 * because the drain UPDATEs attempted_at before sending, so duplicate
 * invocations see 0 unsent rows.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/mail.php';

$cron_token = get_setting('cron_token', '');
$provided = $_GET['token'] ?? ($argv[1] ?? '');
if ($cron_token === '' || $provided === '' || !hash_equals($cron_token, $provided)) {
    http_response_code(403);
    exit('Forbidden');
}

if (get_setting('notifications_enabled', '0') !== '1') {
    exit("OK: notifications disabled.\n");
}

$db = get_db();

$pending = $db->prepare(
    "SELECT id, event_id, username FROM pending_notifications
     WHERE attempted_at IS NULL AND attempts < 3
     ORDER BY id LIMIT 100"
);
$pending->execute();
$rows = $pending->fetchAll();

$sent = 0;
$failed = 0;
foreach ($rows as $qrow) {
    // Claim the row first — prevents concurrent drains sending twice
    $db->prepare("UPDATE pending_notifications SET attempted_at = CURRENT_TIMESTAMP, attempts = attempts + 1 WHERE id = ? AND attempted_at IS NULL")
       ->execute([(int)$qrow['id']]);

    // Only proceed if our UPDATE actually set attempted_at (i.e., we claimed it)
    $check = $db->prepare("SELECT attempts FROM pending_notifications WHERE id = ? AND attempted_at IS NOT NULL");
    $check->execute([(int)$qrow['id']]);
    if (!$check->fetchColumn()) continue;

    $evStmt = $db->prepare('SELECT title, start_date FROM events WHERE id = ?');
    $evStmt->execute([(int)$qrow['event_id']]);
    $evRow = $evStmt->fetch();
    if (!$evRow) { $failed++; continue; }

    $uStmt = $db->prepare('SELECT username, email, phone, preferred_contact FROM users WHERE LOWER(username) = LOWER(?)');
    $uStmt->execute([$qrow['username']]);
    $uRow = $uStmt->fetch();
    if (!$uRow) { $failed++; continue; }

    try {
        send_invite_notification(
            $uRow['username'],
            $uRow['email'] ?? '',
            $uRow['phone'] ?? '',
            $uRow['preferred_contact'] ?? 'email',
            $evRow['title'],
            $evRow['start_date'],
            (int)$qrow['event_id']
        );
        // Success — reset attempts so history rows don't block the 3-attempt cap
        $db->prepare("UPDATE pending_notifications SET attempts = 0 WHERE id = ?")
           ->execute([(int)$qrow['id']]);
        $sent++;
    } catch (Throwable $e) {
        // Release the claim so cron can retry it
        $db->prepare("UPDATE pending_notifications SET attempted_at = NULL WHERE id = ?")
           ->execute([(int)$qrow['id']]);
        $failed++;
    }
}

echo "Drain: $sent sent, $failed failed.\n";
