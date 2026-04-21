<?php
/**
 * Unified notification queue helpers. All event-related outbound messages
 * flow through pending_notifications with a notify_type tag and JSON payload.
 *
 * Types:
 *   invite                 — classic invite link (payload: {} — uses event title/date from row)
 *   reminder               — pre-event reminder  (payload: {offset_minutes: int})
 *   cancel_event           — whole event cancelled
 *   cancel_occurrence      — single occurrence cancelled
 *   event_updated          — event details changed
 *   rsvp_to_creator        — creator gets notified of an RSVP (payload: {rsvp, responder_username, responder_display})
 *   waitlist_promoted      — waitlisted invitee moved up
 *   rsvp_deadline_demoted  — non-responder demoted after deadline
 *   poker_approved         — pending poker player approved (payload: {table: int?, seat: int?})
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/sms.php';

/**
 * Queue a single event-related notification. Fires off a background drain so
 * the row typically sends within a few seconds without blocking the HTTP response.
 *
 * @param string|null $occurrence_date  YYYY-MM-DD for per-occurrence types
 * @param array|null  $payload          Type-specific data (stored as JSON)
 * @param string|null $scheduled_for    UTC "YYYY-MM-DD HH:MM:SS"; NULL = send ASAP
 */
function queue_event_notification(
    PDO $db,
    int $event_id,
    string $username,
    string $notify_type,
    ?string $occurrence_date = null,
    ?array $payload = null,
    ?string $scheduled_for = null
): void {
    if ($username === '' || $event_id <= 0) return;
    $db->prepare(
        "INSERT INTO pending_notifications
            (event_id, username, notify_type, occurrence_date, payload, scheduled_for)
         VALUES (?, ?, ?, ?, ?, ?)"
    )->execute([
        $event_id,
        $username,
        $notify_type,
        $occurrence_date,
        $payload !== null ? json_encode($payload) : null,
        $scheduled_for,
    ]);
    drain_queue_async();
}

/**
 * Queue reminders for one event (or one occurrence of a recurring event).
 * Reads reminder_offsets from the event (or site default) and inserts one row
 * per approved invitee per offset, each with scheduled_for = start - offset.
 * Skips offsets whose scheduled_for is already in the past.
 * Dedup against event_notifications_sent so re-queuing is safe.
 */
function queue_reminders_for_event(PDO $db, int $event_id, ?string $occurrence_date = null): int {
    $ev = $db->prepare('SELECT id, start_date, start_time, reminders_enabled, reminder_offsets FROM events WHERE id = ?');
    $ev->execute([$event_id]);
    $event = $ev->fetch();
    if (!$event) return 0;
    if ((int)$event['reminders_enabled'] !== 1) return 0;

    $offsets = [];
    if (!empty($event['reminder_offsets'])) {
        $decoded = json_decode($event['reminder_offsets'], true);
        if (is_array($decoded)) $offsets = array_map('intval', $decoded);
    }
    if (!$offsets) {
        $siteDefault = get_setting('default_reminder_offsets', '[2880,720]');
        $decoded = json_decode($siteDefault, true);
        if (is_array($decoded)) $offsets = array_map('intval', $decoded);
    }
    if (!$offsets) return 0;

    $tz = new DateTimeZone(get_setting('timezone', 'UTC'));
    $date = $occurrence_date ?: $event['start_date'];
    $time = $event['start_time'] ?: '00:00:00';
    $start = new DateTime($date . ' ' . $time, $tz);
    $start->setTimezone(new DateTimeZone('UTC'));
    $now = new DateTime('now', new DateTimeZone('UTC'));

    $inv = $db->prepare(
        "SELECT ei.username FROM event_invites ei
         JOIN users u ON LOWER(u.username) = LOWER(ei.username)
         WHERE ei.event_id = ? AND ei.approval_status = 'approved'"
    );
    $inv->execute([$event_id]);
    $invitees = array_column($inv->fetchAll(), 'username');
    if (!$invitees) return 0;

    $queued = 0;
    foreach ($offsets as $offset_minutes) {
        $when = (clone $start)->modify("-{$offset_minutes} minutes");
        if ($when <= $now) continue; // past-due, skip

        $scheduled = $when->format('Y-m-d H:i:s');
        $type_tag = 'reminder_' . $offset_minutes;
        foreach ($invitees as $uname) {
            // Dedup: if an event_notifications_sent row already exists for this (event, occ, user, type), skip.
            $seen = $db->prepare(
                "SELECT 1 FROM event_notifications_sent
                 WHERE event_id=? AND occurrence_date=? AND user_identifier=? AND notification_type=?"
            );
            $seen->execute([$event_id, $occurrence_date ?: $event['start_date'], strtolower($uname), $type_tag]);
            if ($seen->fetchColumn()) continue;

            // Also skip if already queued (row exists with same event/occ/user/offset + not yet sent or attempts < 3)
            $dup = $db->prepare(
                "SELECT 1 FROM pending_notifications
                 WHERE event_id=? AND LOWER(username)=LOWER(?) AND notify_type='reminder'
                   AND (occurrence_date IS ? OR occurrence_date = ?)
                   AND payload = ?"
            );
            $payload_json = json_encode(['offset_minutes' => $offset_minutes]);
            $dup->execute([$event_id, $uname, $occurrence_date, $occurrence_date, $payload_json]);
            if ($dup->fetchColumn()) continue;

            queue_event_notification(
                $db, $event_id, $uname, 'reminder',
                $occurrence_date,
                ['offset_minutes' => $offset_minutes],
                $scheduled
            );
            $queued++;
        }
    }
    return $queued;
}

/**
 * Remove queued reminders for an event (unsent rows only). Called when
 * reminders_enabled flips off or reminder_offsets change.
 */
function clear_pending_reminders(PDO $db, int $event_id, ?string $occurrence_date = null): void {
    if ($occurrence_date === null) {
        $db->prepare("DELETE FROM pending_notifications
                      WHERE event_id = ? AND notify_type = 'reminder' AND attempts = 0")
           ->execute([$event_id]);
    } else {
        $db->prepare("DELETE FROM pending_notifications
                      WHERE event_id = ? AND occurrence_date = ? AND notify_type = 'reminder' AND attempts = 0")
           ->execute([$event_id, $occurrence_date]);
    }
}

/**
 * Dispatch one queued row. Looks up the event + recipient, builds the right
 * subject/sms/html for the notify_type, calls send_notification.
 * Returns true on success, false if the row should be retried.
 */
function dispatch_queued_notification(PDO $db, array $row): bool {
    $event_id    = (int)$row['event_id'];
    $username    = (string)$row['username'];
    $type        = (string)$row['notify_type'];
    $occ_date    = $row['occurrence_date'] ?? null;
    $payload     = !empty($row['payload']) ? (json_decode($row['payload'], true) ?: []) : [];

    $evStmt = $db->prepare('SELECT id, title, description, start_date, end_date, start_time, end_time FROM events WHERE id = ?');
    $evStmt->execute([$event_id]);
    $event = $evStmt->fetch();
    // For types like cancel_event where the event may already be deleted,
    // the original title/start_date are in the payload.
    if (!$event) {
        if (isset($payload['title']) && isset($payload['start_date'])) {
            $event = [
                'id'          => $event_id,
                'title'       => $payload['title'],
                'description' => null,
                'start_date'  => $payload['start_date'],
                'end_date'    => null,
                'start_time'  => null,
                'end_time'    => null,
            ];
        } else {
            return true; // event gone and no payload fallback; treat as handled
        }
    }

    $uStmt = $db->prepare('SELECT username, email, phone, preferred_contact FROM users WHERE LOWER(username) = LOWER(?)');
    $uStmt->execute([$username]);
    $user = $uStmt->fetch();
    if (!$user) return true; // user gone; clear

    $site_url = get_site_url();
    $month    = substr($occ_date ?: $event['start_date'], 0, 7);
    $date_for_url = $occ_date ?: $event['start_date'];
    $url = $site_url . '/calendar.php?m=' . urlencode($month) . '&open=' . $event_id . '&date=' . urlencode($date_for_url);
    if (get_setting('url_shortener_enabled') === '1') {
        $url = shorten_url($url);
    }

    $title  = $event['title'];
    $start  = $occ_date ?: $event['start_date'];
    $pretty_time = $event['start_time'] ? date('g:i A', strtotime($event['start_time'])) : '';

    $subject = ''; $smsBody = ''; $htmlBody = '';

    switch ($type) {
        case 'invite':
            $subject = "You're invited: " . $title . ' (' . $start . ')';
            $smsBody = "You've been invited to \"$title\" on $start. Reply YES, NO, or MAYBE to RSVP. View: $url";
            $htmlBody = '<p>Hi ' . htmlspecialchars($user['username']) . ',</p>'
                      . '<p>You have been invited to <strong>' . htmlspecialchars($title) . '</strong> on ' . htmlspecialchars($start) . '.</p>'
                      . '<p style="margin-top:1.5rem"><a href="' . htmlspecialchars($url) . '" style="background:#2563eb;color:#fff;padding:.5rem 1.2rem;border-radius:6px;text-decoration:none;font-weight:600">View Event &amp; RSVP</a></p>';
            break;

        case 'reminder':
            $offset = (int)($payload['offset_minutes'] ?? 0);
            $label  = _format_offset_label($offset);
            // Dedup: if already sent, skip
            $type_tag = 'reminder_' . $offset;
            $seen = $db->prepare("SELECT 1 FROM event_notifications_sent
                WHERE event_id=? AND occurrence_date=? AND user_identifier=? AND notification_type=?");
            $seen->execute([$event_id, $occ_date ?: $event['start_date'], strtolower($username), $type_tag]);
            if ($seen->fetchColumn()) return true;

            $subject  = "Reminder: $title in $label";
            $smsBody  = "Reminder: \"$title\" is in $label ($start). RSVP: $url";
            $htmlBody = '<p>This is a reminder that <strong>' . htmlspecialchars($title) . '</strong>'
                      . ' is coming up in <strong>' . $label . '</strong>'
                      . ' on <strong>' . htmlspecialchars($start) . '</strong>.</p>';
            if ($pretty_time) $htmlBody .= '<p style="color:#64748b;font-size:.9rem">Start time: ' . htmlspecialchars($pretty_time) . '</p>';
            $htmlBody .= '<p style="margin-top:1.25rem"><a href="' . htmlspecialchars($url) . '" style="background:#2563eb;color:#fff;padding:.5rem 1.2rem;border-radius:6px;text-decoration:none;font-weight:600">View Event &amp; RSVP</a></p>';
            break;

        case 'cancel_event':
            $subject = 'Cancelled: ' . $title;
            $smsBody = "\"$title\" on $start has been cancelled.";
            $htmlBody = '<p>The event <strong>' . htmlspecialchars($title) . '</strong> scheduled for ' . htmlspecialchars($start) . ' has been cancelled.</p>';
            break;

        case 'cancel_occurrence':
            $subject = 'Cancelled: ' . $title . ' on ' . $start;
            $smsBody = "The $start occurrence of \"$title\" has been cancelled.";
            $htmlBody = '<p>The <strong>' . htmlspecialchars($start) . '</strong> occurrence of <strong>' . htmlspecialchars($title) . '</strong> has been cancelled. Other dates are unaffected.</p>';
            break;

        case 'event_updated':
            $subject = 'Updated: ' . $title;
            $smsBody = "\"$title\" on $start has been updated. View: $url";
            $htmlBody = '<p>Details for <strong>' . htmlspecialchars($title) . '</strong> on ' . htmlspecialchars($start) . ' have been updated.</p>'
                      . '<p style="margin-top:1rem"><a href="' . htmlspecialchars($url) . '">View the latest details</a></p>';
            break;

        case 'rsvp_to_creator':
            $rsvp      = strtoupper((string)($payload['rsvp'] ?? ''));
            $responder = (string)($payload['responder_display'] ?? $payload['responder_username'] ?? 'Someone');
            $subject = "$responder replied $rsvp to \"$title\"";
            $smsBody = "$responder replied $rsvp to \"$title\" on $start.";
            $htmlBody = '<p><strong>' . htmlspecialchars($responder) . '</strong> replied <strong>' . htmlspecialchars($rsvp) . '</strong> to <strong>' . htmlspecialchars($title) . '</strong> on ' . htmlspecialchars($start) . '.</p>';
            break;

        case 'waitlist_promoted':
            $subject = "A seat opened up: $title";
            $smsBody = "A seat opened up for \"$title\" on $start. You're in! View: $url";
            $htmlBody = '<p>Good news — a seat has opened up for <strong>' . htmlspecialchars($title) . '</strong> on ' . htmlspecialchars($start) . '. You are now approved.</p>'
                      . '<p style="margin-top:1rem"><a href="' . htmlspecialchars($url) . '">View event</a></p>';
            break;

        case 'rsvp_deadline_demoted':
            $subject = "Moved to waitlist: $title";
            $smsBody = "You didn't RSVP by the deadline for \"$title\" — you've been moved to the waitlist.";
            $htmlBody = '<p>The RSVP deadline for <strong>' . htmlspecialchars($title) . '</strong> passed without a response, so you have been moved to the waitlist. You can still RSVP if a seat opens up.</p>';
            break;

        case 'poker_approved':
            $table = $payload['table'] ?? null;
            $seat  = $payload['seat'] ?? null;
            $seatLabel = ($table && $seat) ? " Table $table, Seat $seat." : '';
            $subject = "Approved for $title";
            $smsBody = "You're approved for \"$title\" on $start.$seatLabel";
            $htmlBody = '<p>You have been approved for <strong>' . htmlspecialchars($title) . '</strong> on ' . htmlspecialchars($start) . '.' . htmlspecialchars($seatLabel) . '</p>';
            break;

        default:
            return true; // unknown type — clear the row silently
    }

    send_notification(
        $user['username'], $user['email'] ?? '', $user['phone'] ?? '',
        $user['preferred_contact'] ?? 'email',
        $subject, $smsBody, $htmlBody
    );

    // Mark dedup rows for reminder types so repeated queuing doesn't re-send.
    if ($type === 'reminder') {
        $offset = (int)($payload['offset_minutes'] ?? 0);
        $db->prepare(
            "INSERT OR IGNORE INTO event_notifications_sent (event_id, occurrence_date, user_identifier, notification_type, sent_at)
             VALUES (?, ?, ?, ?, ?)"
        )->execute([
            $event_id,
            $occ_date ?: $event['start_date'],
            strtolower($username),
            'reminder_' . $offset,
            date('Y-m-d H:i:s'),
        ]);
    }

    return true;
}

/**
 * Pretty label for a reminder offset in minutes.
 */
function _format_offset_label(int $minutes): string {
    if ($minutes >= 10080 && $minutes % 10080 === 0) {
        $n = $minutes / 10080;
        return $n === 1 ? '1 week' : "$n weeks";
    }
    if ($minutes >= 1440 && $minutes % 1440 === 0) {
        $n = $minutes / 1440;
        return $n === 1 ? '1 day' : "$n days";
    }
    if ($minutes >= 60 && $minutes % 60 === 0) {
        $n = $minutes / 60;
        return $n === 1 ? '1 hour' : "$n hours";
    }
    return "$minutes min";
}
