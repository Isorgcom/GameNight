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
 * Recommended cron schedule: every 5 minutes, fetch
 * https://yourdomain.com/cron.php?token=YOUR_CRON_TOKEN with curl.
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

// Heartbeat: record that cron actually ran so the admin Cron tab can show real
// status instead of just "a token exists". Written before any work so a mid-run
// failure still proves the scheduler is alive.
set_setting('last_cron_run_ts', (string)time());

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
        // Atomic claim: only the drain whose UPDATE flips attempted_at proceeds;
        // a concurrent drain matches zero rows (rowCount 0) and skips. Using a
        // SELECT for "attempted_at IS NOT NULL" would let both drains through.
        $claim = $db->prepare("UPDATE pending_notifications SET attempted_at = CURRENT_TIMESTAMP, attempts = attempts + 1 WHERE id = ? AND attempted_at IS NULL");
        $claim->execute([(int)$qrow['id']]);
        if ($claim->rowCount() < 1) continue;

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

// Prune old pending_notifications older than 7 days (sent successfully — attempted_at
// holds the last claim time and is not nulled on success).
try { $db->exec("DELETE FROM pending_notifications WHERE attempted_at < datetime('now', '-7 days')"); } catch (Exception $e) {}
// Prune dead rows that exhausted their retries. A failed drain resets attempted_at to
// NULL, so a maxed-out row (attempts >= 3) is never re-claimed (claim needs attempts < 3)
// AND never matched by the attempted_at prune above (NULL is never < a date) — without
// this it would live forever. Age it out by created_at instead.
try { $db->exec("DELETE FROM pending_notifications WHERE attempts >= 3 AND created_at < datetime('now', '-7 days')"); } catch (Exception $e) {}
// Safety-net: rows pointing at deleted events. delete_events() cascades, but events
// can also disappear via other paths, leaving zombie reminders/cancellations queued.
try { $db->exec("DELETE FROM pending_notifications WHERE event_id NOT IN (SELECT id FROM events)"); } catch (Exception $e) {}

// ── 2b. Drain the email retry queue ──────────────────────────────────────────
// Runs regardless of notifications_enabled: password resets, email verification,
// and invites all go through send_email and may have been parked here. Each due
// row is re-attempted on a backoff until it delivers or exhausts its attempts.
$email_q = process_email_retry_queue($db);
if ($email_q['sent'] || $email_q['failed'] || $email_q['requeued']) {
    echo "Email retry queue: {$email_q['sent']} sent, {$email_q['requeued']} requeued, {$email_q['failed']} gave up.\n";
}

// ── 2c. WhatsApp (WAHA) session watchdog ─────────────────────────────────────
// Probes the session, auto-restarts FAILED ones, and emails admins when it
// stays down (see waha_watchdog() in sms.php). Guarded: a WAHA hiccup must
// never break the rest of cron.
try {
    require_once __DIR__ . '/sms.php';
    $wd = waha_watchdog();
    if (($wd['action'] ?? '') !== '') {
        echo "WAHA watchdog: {$wd['status']} -> {$wd['action']}\n";
    }
} catch (\Throwable $e) {
    error_log('cron waha watchdog failed: ' . $e->getMessage());
}

// ── 2d. Unseen direct-message alerts ─────────────────────────────────────────
// DM sends only write the in-app bell; email/SMS goes out here, and only for
// messages still unread 10+ minutes later (see dm_alert_drain in _dm.php).
// Anyone who read the message on the site never gets an email or text.
if ($notifications_on && !$drain_paused) {
    try {
        require_once __DIR__ . '/sms.php';
        require_once __DIR__ . '/_dm.php';
        $dm_alerts = dm_alert_drain($db);
        if ($dm_alerts > 0) echo "DM alerts: $dm_alerts unseen-message alert(s) sent.\n";
    } catch (\Throwable $e) {
        error_log('cron dm alert drain failed: ' . $e->getMessage());
    }
}

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

// Notification dedup: older than 30 days, plus rows for deleted events.
// (Column is sent_at, not created_at — earlier version had this wrong and silently pruned nothing.)
$pruned += $db->exec("DELETE FROM event_notifications_sent WHERE sent_at < datetime('now', '-30 days')");
$pruned += $db->exec("DELETE FROM event_notifications_sent WHERE event_id NOT IN (SELECT id FROM events)");

// Logs: older than 90 days
$pruned += $db->exec("DELETE FROM sms_log WHERE created_at < datetime('now', '-90 days')");
// Sticky SMS conversation bindings: stale after 30 days or when the event is gone.
try { $pruned += $db->exec("DELETE FROM sms_conversation_bind WHERE updated_at < datetime('now', '-30 days') OR event_id NOT IN (SELECT id FROM events)"); } catch (Exception $e) {}
$pruned += $db->exec("DELETE FROM activity_log WHERE created_at < datetime('now', '-90 days')");
try { $pruned += $db->exec("DELETE FROM user_notifications WHERE created_at < datetime('now', '-90 days')"); } catch (Exception $e) {}

// API request log: older than 30 days. Dominant table by row count (~28k/day);
// rate limiting only needs the last minute, so 30d is purely forensic headroom.
$pruned += $db->exec("DELETE FROM api_request_log WHERE created_at < datetime('now', '-30 days')");
// Heal contact links pointing at deleted accounts — they'd otherwise render as
// "Linked" with a Message button that leads nowhere.
try { $db->exec("UPDATE user_contacts SET linked_user_id = NULL
                 WHERE linked_user_id IS NOT NULL AND linked_user_id NOT IN (SELECT id FROM users)"); } catch (Exception $e) {}

// Short links: older than 90 days
$pruned += $db->exec("DELETE FROM short_links WHERE created_at < datetime('now', '-90 days')");

// timer_state: drop event-linked rows whose poker_session is gone. Standalone
// rows use negative sentinel session_ids and must be preserved.
$pruned += $db->exec("DELETE FROM timer_state WHERE session_id > 0 AND session_id NOT IN (SELECT id FROM poker_sessions)");

// Weekly VACUUM so deleted pages actually return to the OS. SQLite VACUUM rewrites
// the whole file, so we gate it to once per week via a site_settings timestamp.
try {
    $last_vacuum = (int)get_setting('last_vacuum_ts', '0');
    if (time() - $last_vacuum > 7 * 86400) {
        $db->exec('VACUUM');
        set_setting('last_vacuum_ts', (string)time());
        echo "Vacuumed.\n";
    }
} catch (Exception $e) {
    error_log('cron vacuum failed: ' . $e->getMessage());
}

// Check GitHub for a newer release (at most once per 24h). Guarded so a network
// hang or error can never break the rest of cron. Admins see a nav dot when behind.
try {
    run_update_check();
} catch (\Throwable $e) {
    error_log('cron update check failed: ' . $e->getMessage());
}

echo "OK: done.\n";
