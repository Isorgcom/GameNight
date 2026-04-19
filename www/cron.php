<?php
/**
 * cron.php — Reminder notification system for upcoming events.
 *
 * Sends 2-day and 12-hour reminders to invitees of upcoming events.
 * Deduplicates via event_notifications_sent table.
 *
 * Recommended cron schedule (every 5 minutes):
 *   Cron line: (slash-5) (star) (star) (star) (star)  curl -s "https://yourdomain.com/cron.php?token=YOUR_CRON_TOKEN" > /dev/null
 *
 * Protected by a secret token stored in site_settings (key: cron_token).
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/mail.php';

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
$in3days  = (clone $now)->modify('+3 days')->format('Y-m-d');

// ── Drain pending invite-notification queue ──────────────────────────────────
$queue_sent = 0;
$queue_failed = 0;
if (get_setting('notifications_enabled', '0') === '1') {
    $pending = $db->prepare(
        "SELECT id, event_id, username FROM pending_notifications
         WHERE attempted_at IS NULL AND attempts < 3
         ORDER BY id LIMIT 100"
    );
    $pending->execute();
    $pendingRows = $pending->fetchAll();
    foreach ($pendingRows as $qrow) {
        // Mark attempted first so a crash doesn't lead to re-sending
        $db->prepare("UPDATE pending_notifications SET attempted_at = CURRENT_TIMESTAMP, attempts = attempts + 1 WHERE id = ?")
           ->execute([(int)$qrow['id']]);
        $evStmt = $db->prepare('SELECT title, start_date FROM events WHERE id = ?');
        $evStmt->execute([(int)$qrow['event_id']]);
        $evRow = $evStmt->fetch();
        if (!$evRow) { $queue_failed++; continue; }
        $uStmt = $db->prepare('SELECT username, email, phone, preferred_contact FROM users WHERE LOWER(username) = LOWER(?)');
        $uStmt->execute([$qrow['username']]);
        $uRow = $uStmt->fetch();
        if (!$uRow) { $queue_failed++; continue; }
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
            // Success: clear attempted_at so the retry counter doesn't matter (row can stay for history)
            $db->prepare("UPDATE pending_notifications SET attempts = 0 WHERE id = ?")
               ->execute([(int)$qrow['id']]);
            $queue_sent++;
        } catch (Throwable $e) {
            // attempted_at remains set and attempts incremented — will retry on next cron run if attempts < 3
            $db->prepare("UPDATE pending_notifications SET attempted_at = NULL WHERE id = ?")
               ->execute([(int)$qrow['id']]);
            $queue_failed++;
        }
    }
}
if ($queue_sent > 0 || $queue_failed > 0) {
    echo "Queue: $queue_sent invite(s) sent, $queue_failed failed.\n";
}

// Prune old (successfully-sent) pending_notifications older than 7 days
try { $db->exec("DELETE FROM pending_notifications WHERE attempted_at < datetime('now', '-7 days') AND attempts < 3"); } catch (Exception $e) {}

// ── Load upcoming events ──────────────────────────────────────────────────────
$stmt = $db->prepare(
    "SELECT * FROM events WHERE
       start_date <= ? AND (end_date >= ? OR (end_date IS NULL AND start_date >= ?))
     ORDER BY start_date, start_time"
);
$stmt->execute([$in3days, $today, $today]);
$all_events = $stmt->fetchAll();

if (empty($all_events)) {
    exit("OK: 0 notifications sent.\n");
}

// ── Expand into a date-keyed array ────────────────────────────────────────────
$by_date = build_event_by_date($all_events, $today, $in3days, $local_tz);

// Collect unique (event_id, occurrence_date) pairs (avoid dupes from multi-day spans)
$seen        = [];
$occurrences = [];
foreach ($by_date as $date => $evs_on_date) {
    foreach ($evs_on_date as $ev) {
        $occ_date = $ev['occurrence_start'] ?? $date;
        $key      = $ev['id'] . ':' . $occ_date;
        if (isset($seen[$key])) continue;
        $seen[$key]    = true;
        $occurrences[] = ['event' => $ev, 'occ_date' => $occ_date];
    }
}

$sent_count = 0;
$site       = get_setting('site_name', 'Game Night');
$site_url   = get_site_url();

foreach ($occurrences as $occ) {
    $ev       = $occ['event'];
    $occ_date = $occ['occ_date'];

    // Calculate time until event start
    $start_time_str = $ev['start_time'] ?? '00:00:00';
    $start_dt       = new DateTime($occ_date . ' ' . $start_time_str, $local_tz);
    $diff_secs      = $start_dt->getTimestamp() - $now->getTimestamp();

    // Skip past events
    if ($diff_secs < 0) continue;

    // 2-day window: 47–49 hours
    $send_2day   = ($diff_secs >= 169200 && $diff_secs <= 176400);
    // 12-hour window: 11–13 hours
    $send_12hour = ($diff_secs >= 39600  && $diff_secs <= 46800);

    if (!$send_2day && !$send_12hour) continue;

    $notif_type = $send_2day ? '2day' : '12hour';
    $time_label = $send_2day ? '2 days' : '12 hours';

    // ── Get invitees for this event ──────────────────────────────────────────
    $inv_stmt = $db->prepare(
        "SELECT ei.username, u.email, u.phone, u.preferred_contact
         FROM event_invites ei
         JOIN users u ON LOWER(u.username) = LOWER(ei.username)
         WHERE ei.event_id = ? AND ei.approval_status = 'approved'"
    );
    $inv_stmt->execute([$ev['id']]);
    $invitees = [];
    foreach ($inv_stmt->fetchAll() as $row) {
        $invitees[strtolower($row['username'])] = $row;
    }

    if (empty($invitees)) continue;

    // Build event URL for this occurrence
    $month  = substr($occ_date, 0, 7);
    $ev_url = $site_url . '/calendar.php?m=' . urlencode($month)
            . '&open=' . $ev['id'] . '&date=' . urlencode($occ_date);

    foreach ($invitees as $uname => $inv) {
        // Check deduplication
        $chk = $db->prepare(
            "SELECT id FROM event_notifications_sent
             WHERE event_id=? AND occurrence_date=? AND user_identifier=? AND notification_type=?"
        );
        $chk->execute([$ev['id'], $occ_date, $uname, $notif_type]);
        if ($chk->fetch()) continue;

        // Build message
        $subject  = 'Reminder: ' . $ev['title'] . ' in ' . $time_label;
        $smsBody  = "Reminder: \"{$ev['title']}\" is in $time_label ($occ_date). RSVP: $ev_url";
        $htmlBody = '<p>This is a reminder that <strong>' . htmlspecialchars($ev['title']) . '</strong>'
                  . ' is coming up in <strong>' . $time_label . '</strong>'
                  . ' on <strong>' . htmlspecialchars($occ_date) . '</strong>.</p>';
        if ($ev['start_time']) {
            $htmlBody .= '<p style="color:#64748b;font-size:.9rem">Start time: '
                       . htmlspecialchars(date('g:i A', strtotime($ev['start_time']))) . '</p>';
        }
        if ($ev['description']) {
            $htmlBody .= '<p style="margin-top:.5rem">' . htmlspecialchars($ev['description']) . '</p>';
        }
        $htmlBody .= '<p style="margin-top:1.25rem">'
                   . '<a href="' . htmlspecialchars($ev_url) . '" style="background:#2563eb;color:#fff;padding:.5rem 1.2rem;border-radius:6px;text-decoration:none;font-weight:600">'
                   . 'View Event &amp; RSVP</a></p>';

        send_notification(
            $inv['username'] ?? $uname,
            $inv['email']    ?? '',
            $inv['phone']    ?? '',
            $inv['preferred_contact'] ?? 'email',
            $subject, $smsBody, $htmlBody
        );

        // Log so we don't send again
        $db->prepare(
            "INSERT OR IGNORE INTO event_notifications_sent
             (event_id, occurrence_date, user_identifier, notification_type, sent_at)
             VALUES (?, ?, ?, ?, ?)"
        )->execute([$ev['id'], $occ_date, $uname, $notif_type, date('Y-m-d H:i:s')]);

        $sent_count++;
    }
}

echo "OK: $sent_count notification" . ($sent_count !== 1 ? 's' : '') . " sent.\n";

// ── RSVP deadline processor: demote non-responders, promote waitlisters ─────
$deadline_processed = 0;
$local_tz = new DateTimeZone(get_setting('timezone', 'UTC'));
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

    $capacity = (int)$de['seats_per_table'] * (int)$de['num_tables'];
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
        // Demote: push to waitlist
        $db->prepare("UPDATE event_invites SET approval_status = 'waitlisted', sort_order = 9999 WHERE id = ?")
           ->execute([(int)$nr['id']]);
        $demoted[] = $nr;

        // Notify the demoted person
        $uStmt = $db->prepare('SELECT username, email, phone, preferred_contact FROM users WHERE LOWER(username) = LOWER(?)');
        $uStmt->execute([$nr['username']]);
        $uRow = $uStmt->fetch();
        if ($uRow) {
            send_notification(
                $uRow['username'], $uRow['email'] ?? '', $uRow['phone'] ?? '',
                $uRow['preferred_contact'] ?? 'email',
                'RSVP deadline passed — ' . $de['title'],
                'The RSVP deadline for "' . $de['title'] . '" has passed without a response. Your seat has been released to the waitlist.',
                '<p>The RSVP deadline for <strong>' . htmlspecialchars($de['title']) . '</strong> has passed.</p>'
                . '<p>Since you didn\'t respond, your seat has been released. You\'re now on the waitlist.</p>'
            );
        }
    }

    // Now promote waitlisters to fill the opened seats
    if (!empty($demoted)) {
        maybe_promote_waitlisted($db, $eid);
    }

    // Mark as processed
    $db->prepare('UPDATE events SET rsvp_deadline_processed = 1 WHERE id = ?')->execute([$eid]);
    $deadline_processed++;
}

if ($deadline_processed > 0) echo "Processed $deadline_processed RSVP deadline(s).\n";

// ── Database maintenance: prune stale data ──────────────────────────────────
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

if ($pruned > 0) echo "Pruned $pruned stale rows.\n";
