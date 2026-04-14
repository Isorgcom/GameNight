<?php
/**
 * cron.php — Reminder notification system for upcoming events.
 *
 * Sends 2-day and 12-hour reminders to invitees of upcoming events.
 * Deduplicates via event_notifications_sent table.
 *
 * Recommended cron schedule (every 30 minutes):
 *   Cron line: (slash-30) (star) (star) (star) (star)  curl -s "https://yourdomain.com/cron.php?token=YOUR_CRON_TOKEN" > /dev/null
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
