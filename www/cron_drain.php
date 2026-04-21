<?php
/**
 * cron_drain.php — Fast drain of the pending_notifications queue.
 *
 * Invoked immediately after an enqueue via shell_exec(... &) so notifications
 * deliver in seconds instead of waiting up to 5 min for the next cron.php tick.
 * Also reachable via HTTP for the regular cron to call as a fallback.
 *
 * Handles every notify_type via dispatch_queued_notification(). Honors
 * scheduled_for (NULL = send ASAP; a future timestamp skips the row).
 *
 * Protected by the same cron_token as cron.php. CLI calls pass the token
 * as argv[1]; HTTP calls use ?token=.
 *
 * Safe to run concurrently with cron.php — the drain UPDATEs attempted_at
 * before sending, so duplicate invocations see 0 claimed rows.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/mail.php';
require_once __DIR__ . '/_notifications.php';

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

// Pick up unsent rows that are either unscheduled or due.
$pending = $db->prepare(
    "SELECT id, event_id, username, notify_type, occurrence_date, payload
     FROM pending_notifications
     WHERE attempted_at IS NULL
       AND attempts < 3
       AND (scheduled_for IS NULL OR scheduled_for <= CURRENT_TIMESTAMP)
     ORDER BY COALESCE(scheduled_for, created_at), id
     LIMIT 100"
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

    try {
        if (dispatch_queued_notification($db, $qrow)) {
            // Success — reset attempts so history rows don't block the 3-attempt cap
            $db->prepare("UPDATE pending_notifications SET attempts = 0 WHERE id = ?")
               ->execute([(int)$qrow['id']]);
            $sent++;
        } else {
            $db->prepare("UPDATE pending_notifications SET attempted_at = NULL WHERE id = ?")
               ->execute([(int)$qrow['id']]);
            $failed++;
        }
    } catch (Throwable $e) {
        // Release the claim so cron can retry it
        $db->prepare("UPDATE pending_notifications SET attempted_at = NULL WHERE id = ?")
           ->execute([(int)$qrow['id']]);
        $failed++;
    }
}

echo "Drain: $sent sent, $failed failed.\n";
