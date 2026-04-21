<?php
/**
 * cron.php — Periodic background work.
 *
 * 1. Queue reminders for upcoming events that haven't had reminders enqueued yet.
 * 2. Drain pending_notifications (safety net — fire-and-forget drains already
 *    spawn on enqueue).
 * 3. RSVP deadline processor — demote non-responders, promote waitlisters.
 * 4. Prune stale auxiliary tables.
 *
 * Recommended cron schedule (every 5 minutes):
 *   */5 * * * *  curl -s "https://yourdomain.com/cron.php?token=YOUR_CRON_TOKEN" > /dev/null
 *
 * Protected by cron_token in site_settings.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/mail.php';
require_once __DIR__ . '/_notifications.php';

// ── Token protection ──────────────────────────────────────────────────────────
$cron_token = get_setting('cron_token', '');
$provided   = $_GET['token'] ?? '';
if ($cron_token === '' || $provided === '' || !hash_equals($cron_token, $provided)) {
    http_response_code(403);
    exit('Forbidden');
}

$db       = get_db();
$local_tz = new DateTimeZone(get_setting('timezone', 'UTC'));
$now      = new DateTime('now', $local_tz);
$today    = $now->format('Y-m-d');
// Queue reminders for events up to 2 weeks out (covers the 1-week preset).
$in2weeks = (clone $now)->modify('+14 days')->format('Y-m-d');

$notifications_on = (get_setting('notifications_enabled', '0') === '1');

// ── 1. Queue reminders for upcoming events (idempotent: queue_reminders_for_event dedups internally) ──
$queued_count = 0;
if ($notifications_on) {
    $upcoming = $db->prepare(
        "SELECT id FROM events
         WHERE reminders_enabled = 1
           AND start_date >= ? AND start_date <= ?"
    );
    $upcoming->execute([$today, $in2weeks]);
    foreach ($upcoming->fetchAll() as $ev) {
        $queued_count += queue_reminders_for_event($db, (int)$ev['id'], null);
        $db->prepare('UPDATE events SET reminders_queued = 1 WHERE id = ?')->execute([(int)$ev['id']]);
    }
}
if ($queued_count > 0) {
    echo "Queued $queued_count reminder row(s).\n";
}

// ── 2. Drain the notification queue (safety net for retries) ─────────────────
$queue_sent = 0;
$queue_failed = 0;
$drain_paused = is_drain_paused();
if ($drain_paused) echo "Drain paused (provider rate limit).\n";
if ($notifications_on && !$drain_paused) {
    $pending = $db->prepare(
        "SELECT id, event_id, username, notify_type, occurrence_date, payload
         FROM pending_notifications
         WHERE attempted_at IS NULL
           AND attempts < 3
           AND (scheduled_for IS NULL OR scheduled_for <= CURRENT_TIMESTAMP)
         ORDER BY COALESCE(scheduled_for, created_at), id LIMIT 100"
    );
    $pending->execute();
    foreach ($pending->fetchAll() as $qrow) {
        $db->prepare("UPDATE pending_notifications SET attempted_at = CURRENT_TIMESTAMP, attempts = attempts + 1 WHERE id = ? AND attempted_at IS NULL")
           ->execute([(int)$qrow['id']]);
        $check = $db->prepare("SELECT attempts FROM pending_notifications WHERE id = ? AND attempted_at IS NOT NULL");
        $check->execute([(int)$qrow['id']]);
        if (!$check->fetchColumn()) continue;

        try {
            if (dispatch_queued_notification($db, $qrow)) {
                $db->prepare("UPDATE pending_notifications SET attempts = 0 WHERE id = ?")->execute([(int)$qrow['id']]);
                $queue_sent++;
            } else {
                $db->prepare("UPDATE pending_notifications SET attempted_at = NULL WHERE id = ?")->execute([(int)$qrow['id']]);
                $queue_failed++;
            }
        } catch (Throwable $e) {
            $db->prepare("UPDATE pending_notifications SET attempted_at = NULL WHERE id = ?")->execute([(int)$qrow['id']]);
            $queue_failed++;
            if (looks_like_rate_limit($e->getMessage())) {
                pause_drain_on_rate_limit();
                break;
            }
        }
    }
}
if ($queue_sent > 0 || $queue_failed > 0) {
    echo "Queue: $queue_sent sent, $queue_failed failed.\n";
}

// Prune old pending_notifications older than 7 days (either sent successfully or maxed out retries)
try { $db->exec("DELETE FROM pending_notifications WHERE attempted_at < datetime('now', '-7 days')"); } catch (Exception $e) {}

// ── 3. RSVP deadline processor: demote non-responders, promote waitlisters ─────
$deadline_processed = 0;
$now_local = new DateTime('now', $local_tz);

$deadlineEvents = $db->prepare(
    "SELECT e.id, e.title, e.start_date, e.start_time, e.rsvp_deadline_hours,
            ps.seats_per_table, ps.num_tables
     FROM events e
     JOIN poker_sessions ps ON ps.event_id = e.id
     WHERE e.is_poker = 1
       AND e.rsvp_deadline_hours IS NOT NULL
       AND e.rsvp_deadline_hours > 0
       AND e.rsvp_deadline_processed = 0
       AND e.start_date >= ?"
);
$deadlineEvents->execute([$now_local->format('Y-m-d')]);

foreach ($deadlineEvents->fetchAll() as $de) {
    $startTime = $de['start_time'] ?: '23:59';
    $eventStart = new DateTime($de['start_date'] . ' ' . $startTime, $local_tz);
    $deadline = (clone $eventStart)->modify('-' . (int)$de['rsvp_deadline_hours'] . ' hours');

    if ($now_local < $deadline) continue; // not past deadline yet

    $eid = (int)$de['id'];

    // Find priority invitees who never responded
    $noResponse = $db->prepare(
        "SELECT id, username FROM event_invites
         WHERE event_id = ? AND occurrence_date IS NULL
           AND approval_status = 'approved' AND rsvp IS NULL AND sort_order IS NOT NULL
         ORDER BY sort_order ASC"
    );
    $noResponse->execute([$eid]);
    $demoted = [];
    foreach ($noResponse->fetchAll() as $nr) {
        $db->prepare("UPDATE event_invites SET approval_status = 'waitlisted', sort_order = 9999 WHERE id = ?")
           ->execute([(int)$nr['id']]);
        $demoted[] = $nr;
        queue_event_notification($db, $eid, $nr['username'], 'rsvp_deadline_demoted');
    }

    if (!empty($demoted)) {
        maybe_promote_waitlisted($db, $eid);
    }

    $db->prepare('UPDATE events SET rsvp_deadline_processed = 1 WHERE id = ?')->execute([$eid]);
    $deadline_processed++;
}

if ($deadline_processed > 0) echo "Processed $deadline_processed RSVP deadline(s).\n";

// ── 4. Database maintenance: prune stale data ──────────────────────────────────
$pruned = 0;

// Tokens: delete used or expired (>24h old)
$pruned += $db->exec("DELETE FROM password_resets WHERE used = 1 OR expires_at < datetime('now', '-1 day')");
$pruned += $db->exec("DELETE FROM email_verifications WHERE used = 1 OR expires_at < datetime('now', '-1 day')");
$pruned += $db->exec("DELETE FROM phone_verifications WHERE used = 1 OR expires_at < datetime('now', '-1 day')");

// Notification dedup: older than 30 days
$pruned += $db->exec("DELETE FROM event_notifications_sent WHERE created_at < datetime('now', '-30 days')");

// Logs: older than 90 days
$pruned += $db->exec("DELETE FROM sms_log WHERE created_at < datetime('now', '-90 days')");
$pruned += $db->exec("DELETE FROM activity_log WHERE created_at < datetime('now', '-90 days')");

// Short links: older than 90 days
$pruned += $db->exec("DELETE FROM short_links WHERE created_at < datetime('now', '-90 days')");

echo "OK: done.\n";
