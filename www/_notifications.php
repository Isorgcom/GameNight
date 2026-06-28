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
 * Returns true if the drain is currently paused due to a provider rate-limit hit.
 * Pause state is stored as an ISO-8601 UTC timestamp in site_settings.notification_drain_paused_until.
 */
function is_drain_paused(): bool {
    $until = get_setting('notification_drain_paused_until', '');
    if ($until === '') return false;
    $pauseUntil = strtotime($until);
    if ($pauseUntil === false) return false;
    if ($pauseUntil <= time()) {
        // Clear the stale pause so we don't keep parsing it every tick
        set_setting('notification_drain_paused_until', '');
        return false;
    }
    return true;
}

/**
 * Heuristic: does this exception message look like a provider rate-limit response?
 * Covers HTTP 429, common phrasings, and the "sms_log" entries our providers produce.
 */
function looks_like_rate_limit(string $msg): bool {
    $m = strtolower($msg);
    return (strpos($m, 'http 429') !== false
         || strpos($m, '429')        !== false
         || strpos($m, 'rate limit') !== false
         || strpos($m, 'too many')   !== false
         || strpos($m, 'throttl')    !== false);
}

/**
 * Record a rate-limit hit and pause drains for DRAIN_PAUSE_ON_429_MINUTES.
 */
function pause_drain_on_rate_limit(): void {
    $mins = defined('DRAIN_PAUSE_ON_429_MINUTES') ? DRAIN_PAUSE_ON_429_MINUTES : 15;
    $until = gmdate('Y-m-d H:i:s', time() + $mins * 60);
    set_setting('notification_drain_paused_until', $until);
    error_log("[GameNight] Provider rate limit detected; pausing drain until $until UTC");
}

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

    // Per-recipient daily cap (circuit breaker against accidental storms).
    // Reminders are exempt because they're pre-scheduled with their own dedup.
    // rsvp_to_creator is exempt too: its volume is naturally bounded by the
    // invitee count, and capping it would silently drop legitimate RSVP replies
    // to an owner/manager mid-event.
    if (!in_array($notify_type, ['reminder', 'rsvp_to_creator', 'event_message', 'event_poll'], true)) {
        $cap = defined('MAX_NOTIFICATIONS_PER_DAY') ? MAX_NOTIFICATIONS_PER_DAY : 20;
        $c = $db->prepare(
            "SELECT COUNT(*) FROM pending_notifications
             WHERE LOWER(username) = LOWER(?)
               AND notify_type NOT IN ('reminder', 'rsvp_to_creator', 'event_message', 'event_poll')
               AND created_at >= datetime('now', '-1 day')"
        );
        $c->execute([$username]);
        if ((int)$c->fetchColumn() >= $cap) {
            error_log("[GameNight] Per-recipient daily cap reached for $username (type=$notify_type); skipping enqueue");
            return;
        }
    }

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
    _schedule_drain_at_shutdown();
}

/**
 * Queue an "<responder> replied YES/NO/MAYBE" notification to everyone who
 * manages the event: the event owner (events.created_by) AND every per-event
 * manager (event_invites.event_role = 'manager'). The responder is never
 * notified about their own reply. Callers should only invoke this when the
 * RSVP actually changed. Shared by all RSVP write paths (rsvp.php, calendar.php,
 * sms_webhook.php, wa_webhook.php) so the recipient logic stays in one place.
 */
function queue_rsvp_reply_notifications(
    PDO $db,
    int $event_id,
    ?string $occurrence_date,
    string $responder_username,
    string $responder_display,
    string $rsvp
): void {
    if ($event_id <= 0 || $rsvp === '') return;

    // Collect recipients, keyed by lowercase username to dedupe owner-also-manager.
    $recips = [];
    $c = $db->prepare('SELECT u.username FROM events e JOIN users u ON u.id = e.created_by WHERE e.id = ?');
    $c->execute([$event_id]);
    if ($cu = $c->fetchColumn()) $recips[strtolower((string)$cu)] = (string)$cu;

    $m = $db->prepare("SELECT DISTINCT username FROM event_invites WHERE event_id = ? AND event_role = 'manager'");
    $m->execute([$event_id]);
    foreach ($m->fetchAll(PDO::FETCH_COLUMN) as $mu) {
        if ($mu !== null && $mu !== '') $recips[strtolower((string)$mu)] = (string)$mu;
    }

    // Never notify the responder about their own reply.
    unset($recips[strtolower($responder_username)]);

    foreach ($recips as $u) {
        queue_event_notification($db, $event_id, $u, 'rsvp_to_creator', $occurrence_date, [
            'rsvp'               => $rsvp,
            'responder_username' => $responder_username,
            'responder_display'  => $responder_display,
        ]);
    }
}

/**
 * Idempotently register a single drain to fire after the response is sent.
 * Without this, a fan-out (e.g. 13 cancellation invitees on event delete)
 * would shell_exec() one cron_drain.php per row, spawning that many
 * concurrent PHP+SMTP processes and noticeably slowing the server.
 */
function _schedule_drain_at_shutdown(): void {
    static $registered = false;
    if ($registered) return;
    $registered = true;
    register_shutdown_function('drain_queue_async');
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
 * Cancel an entire event: queue cancellation notices to all base invitees, then
 * delete the event and its dependent rows. Shared by the in-app delete action
 * (calendar_dl.php) and the SMS CANCEL command (sms_admin.php) so the
 * notify-then-delete sequence lives in one place. The title/date are carried in
 * each notification payload because the event row is gone before the drain runs.
 * Returns ['title' => ..., 'notified' => N], or null if the event doesn't exist.
 */
function cancel_event_with_notifications(PDO $db, int $event_id, int $actor_id): ?array {
    $row = $db->prepare('SELECT title, start_date FROM events WHERE id=?');
    $row->execute([$event_id]);
    $evt = $row->fetch();
    if (!$evt) return null;
    $t = $evt['title'] ?? (string)$event_id;

    $notified = 0;
    $invStmt = $db->prepare("SELECT username FROM event_invites WHERE event_id=? AND occurrence_date IS NULL");
    $invStmt->execute([$event_id]);
    foreach ($invStmt->fetchAll() as $inv) {
        queue_event_notification($db, $event_id, $inv['username'], 'cancel_event', null, [
            'title'      => $t,
            'start_date' => $evt['start_date'],
        ]);
        $notified++;
    }

    $db->prepare('DELETE FROM event_exceptions WHERE event_id=?')->execute([$event_id]);
    $db->prepare('DELETE FROM event_invites WHERE event_id=?')->execute([$event_id]);
    // event_messages has a FOREIGN KEY to events(id) without ON DELETE CASCADE,
    // so it must be cleared before the event row or the DELETE fails.
    $db->prepare('DELETE FROM event_messages WHERE event_id=?')->execute([$event_id]);
    $db->prepare('DELETE FROM pending_notifications WHERE event_id=? AND attempted_at IS NOT NULL')->execute([$event_id]);
    $db->prepare('DELETE FROM event_notifications_sent WHERE event_id=?')->execute([$event_id]);
    $db->prepare('DELETE FROM events WHERE id=?')->execute([$event_id]);
    db_log_activity($actor_id, "deleted event: $t");
    return ['title' => $t, 'notified' => $notified];
}

/**
 * Approve a single pending/waitlisted invitee on an event: flip approval_status,
 * sync into the poker roster + auto-assign a seat if the event has a poker
 * session, and queue the 'poker_approved' ("you're approved") notification.
 * Shared by the in-app approve_invite action (calendar.php) and the SMS APPROVE
 * command. Caller is responsible for the permission check. Returns
 * ['seat_info' => ' Table X, Seat Y.'] (empty string when not a poker event).
 */
function approve_event_invitee(PDO $db, int $event_id, string $target, int $actor_id): array {
    $db->prepare("UPDATE event_invites SET approval_status='approved' WHERE event_id=? AND LOWER(username)=LOWER(?)")
       ->execute([$event_id, $target]);
    db_log_activity($actor_id, "approve_invite for $target on event id: $event_id");

    $seatInfo = '';
    $ppRow = null;
    $psess = $db->prepare('SELECT id FROM poker_sessions WHERE event_id = ?');
    $psess->execute([$event_id]);
    $psRow = $psess->fetch();
    if ($psRow) {
        require_once __DIR__ . '/_poker_helpers.php';
        sync_invitees($db, $psRow['id'], $event_id);
        $ppStmt = $db->prepare('SELECT id, table_number, seat_number FROM poker_players WHERE session_id = ? AND LOWER(display_name) = LOWER(?) AND removed = 0');
        $ppStmt->execute([$psRow['id'], $target]);
        $ppRow = $ppStmt->fetch();
        if ($ppRow) {
            auto_assign_table($db, $psRow['id'], $ppRow['id']);
            $ppStmt->execute([$psRow['id'], $target]);
            $ppRow = $ppStmt->fetch();
            if ($ppRow && $ppRow['table_number'] && $ppRow['seat_number']) {
                $seatInfo = " Table {$ppRow['table_number']}, Seat {$ppRow['seat_number']}.";
            }
        }
    }

    $payload = [];
    if (!empty($ppRow) && $ppRow['table_number'] && $ppRow['seat_number']) {
        $payload['table'] = (int)$ppRow['table_number'];
        $payload['seat']  = (int)$ppRow['seat_number'];
    }
    queue_event_notification($db, $event_id, $target, 'poker_approved', null, $payload ?: null);
    return ['seat_info' => $seatInfo];
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
    $row_id      = (int)($row['id'] ?? 0);

    // Dedup across the whole queue: if this (event, occurrence, user, type+discriminator)
    // already has a sent marker, treat as handled. Prevents double-sends when a partial
    // provider failure (e.g. SMS fails for a 'both' user while email succeeded) causes
    // the row to be released and retried.
    $type_tag = $type;
    if ($type === 'reminder') {
        $type_tag = 'reminder_' . (int)($payload['offset_minutes'] ?? 0);
    } elseif ($type === 'rsvp_to_creator') {
        // Each queued RSVP reply is its own message — discriminate by the queue
        // row id so retries of THIS row still dedup, but distinct RSVPs (different
        // responders / later changes) are never collapsed into one.
        $type_tag = 'rsvp_to_creator_' . $row_id;
    } elseif ($type === 'event_message') {
        // Each host message send is distinct; discriminate per queued row so a
        // host can send multiple notes over time without them deduping together.
        $type_tag = 'event_message_' . $row_id;
    } elseif ($type === 'event_poll') {
        // Same rationale as event_message: each queued poll row is its own send.
        $type_tag = 'event_poll_' . $row_id;
    }
    $occ_key = $occ_date ?: '';
    $seenStmt = $db->prepare(
        "SELECT 1 FROM event_notifications_sent
         WHERE event_id=? AND occurrence_date=? AND user_identifier=? AND notification_type=?"
    );
    $seenStmt->execute([$event_id, $occ_key, strtolower($username), $type_tag]);
    if ($seenStmt->fetchColumn()) return true;

    $evStmt = $db->prepare('SELECT id, title, description, start_date, end_date, start_time, end_time, created_by FROM events WHERE id = ?');
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

    $uStmt = $db->prepare('SELECT id, username, email, phone, preferred_contact FROM users WHERE LOWER(username) = LOWER(?)');
    $uStmt->execute([$username]);
    $user = $uStmt->fetch();

    // Custom invitee fallback: the queued row references a `username` that isn't a
    // registered GameNight user. Deliver via the email / phone stored directly on the
    // event_invites row so hosts can invite people without accounts.
    if (!$user) {
        $ei = $db->prepare(
            "SELECT username, email, phone FROM event_invites
             WHERE event_id = ? AND LOWER(username) = LOWER(?)
             ORDER BY (occurrence_date IS NULL) DESC, id LIMIT 1"
        );
        $ei->execute([$event_id, $username]);
        $row = $ei->fetch();
        if (!$row) {
            return true; // no invite row at all — treat as handled, don't retry
        }
        $inv_email = $row['email'] ?? '';
        $inv_phone = $row['phone'] ?? '';
        // Back-fill from the event creator's saved contacts when the invite itself has no
        // contact info (e.g. the contact's email was added after the invite was created).
        // Persist it so the data self-heals and the editor shows it next time.
        if (empty($inv_email) && empty($inv_phone)) {
            $resolved = invitee_contact_from_contacts($db, (int)($event['created_by'] ?? 0), $username);
            if (!empty($resolved['email']) || !empty($resolved['phone'])) {
                $inv_email = $resolved['email'];
                $inv_phone = $resolved['phone'];
                $db->prepare('UPDATE event_invites SET email = ?, phone = ? WHERE event_id = ? AND LOWER(username) = LOWER(?)')
                   ->execute([$inv_email ?: null, $inv_phone ?: null, $event_id, $username]);
            }
        }
        if (empty($inv_email) && empty($inv_phone)) {
            return true; // nothing to send to — treat as handled, don't retry
        }
        $user = [
            'username'          => $row['username'],
            'email'             => $inv_email,
            'phone'             => $inv_phone,
            // Default channel: email if we have it, otherwise SMS (matches how phone-only
            // signup users are created in register_user()).
            'preferred_contact' => !empty($inv_email) ? 'email' : 'sms',
        ];
    }

    $site_url = get_site_url();
    $month    = substr($occ_date ?: $event['start_date'], 0, 7);
    $date_for_url = $occ_date ?: $event['start_date'];
    $url = $site_url . '/calendar.php?m=' . urlencode($month) . '&open=' . $event_id . '&date=' . urlencode($date_for_url);
    if (get_setting('url_shortener_enabled') === '1') {
        $url = shorten_url($url);
    }

    // Render event time in the recipient's timezone when they're a registered user
    // (each member sees their own clock, with a tz label). Custom invitees with no
    // account have no tz of their own, so they fall back to the event CREATOR's tz.
    // Event start_time is wall-clock in the site tz; combine there, then convert.
    $site_tz   = new DateTimeZone(get_setting('timezone', 'UTC'));
    $render_tz = new DateTimeZone(display_timezone(
        !empty($user['id'])
            ? (int)$user['id']
            : (!empty($event['created_by']) ? (int)$event['created_by'] : null)
    ));

    $title  = $event['title'];
    $start  = $occ_date ?: $event['start_date'];
    $pretty_time = '';
    if (!empty($event['start_time'])) {
        $dt = new DateTime($start . ' ' . $event['start_time'], $site_tz);
        $dt->setTimezone($render_tz);
        $pretty_time = $dt->format('g:i A T');
        // Date may roll over a day in extreme offsets (e.g. site UTC, recipient Auckland)
        $start = $dt->format('Y-m-d');
    }

    $subject = ''; $smsBody = ''; $htmlBody = ''; $waBody = null;

    switch ($type) {
        case 'invite':
            // One-click RSVP via rsvp_token (no login required). Falls back to the event link
            // if this invitee predates the token column or the row is missing.
            $tokStmt = $db->prepare("SELECT rsvp_token FROM event_invites
                WHERE event_id=? AND LOWER(username)=LOWER(?) AND occurrence_date IS NULL");
            $tokStmt->execute([$event_id, $username]);
            $rsvp_token = (string)($tokStmt->fetchColumn() ?: '');
            $allowMaybe = get_setting('allow_maybe_rsvp', '1') === '1';

            $subject = "You're invited: " . $title . ' (' . $start . ')';
            $rsvpButtons = '';
            if ($rsvp_token !== '') {
                $rsvp_base = $site_url . '/rsvp.php?token=' . urlencode($rsvp_token);
                $yes_url   = $rsvp_base . '&r=yes';
                $no_url    = $rsvp_base . '&r=no';
                $maybe_url = $rsvp_base . '&r=maybe';
                $smsBody = "You've been invited to \"$title\" on $start. RSVP:\nYES: $yes_url\nNO: $no_url"
                         . ($allowMaybe ? "\nMAYBE: $maybe_url" : "");
                $rsvpButtons = '<p style="margin-top:1.5rem">RSVP now:</p>'
                    . '<p>'
                    . '<a href="' . htmlspecialchars($yes_url) . '" style="display:inline-block;margin:.25rem .3rem;padding:.5rem 1.2rem;border-radius:6px;text-decoration:none;font-weight:600;background:#16a34a;color:#fff">Yes</a>'
                    . '<a href="' . htmlspecialchars($no_url) . '" style="display:inline-block;margin:.25rem .3rem;padding:.5rem 1.2rem;border-radius:6px;text-decoration:none;font-weight:600;background:#dc2626;color:#fff">No</a>'
                    . ($allowMaybe ? '<a href="' . htmlspecialchars($maybe_url) . '" style="display:inline-block;margin:.25rem .3rem;padding:.5rem 1.2rem;border-radius:6px;text-decoration:none;font-weight:600;background:#d97706;color:#fff">Maybe</a>' : '')
                    . '</p>';
            } else {
                $smsBody = "You've been invited to \"$title\" on $start. Reply YES, NO, or MAYBE to RSVP. View: $url";
            }
            $desc = $event['description'] ?? '';
            // The token-based public page lets invitees view details + RSVP without logging in.
            // Falls back to the login-gated calendar link for legacy invites that have no token.
            $event_link = $rsvp_token !== '' ? ($site_url . '/event.php?token=' . urlencode($rsvp_token)) : $url;
            $htmlBody = '<p>Hi ' . htmlspecialchars($user['username']) . ',</p>'
                      . '<p>You have been invited to <strong>' . htmlspecialchars($title) . '</strong> on ' . htmlspecialchars($start) . '.</p>'
                      . ($desc ? '<p>' . nl2br(htmlspecialchars($desc)) . '</p>' : '')
                      . $rsvpButtons
                      . '<p style="margin-top:1rem"><a href="' . htmlspecialchars($event_link) . '" style="display:inline-block;padding:.5rem 1.5rem;border-radius:6px;text-decoration:none;font-weight:600;background:#2563eb;color:#fff">Event Details</a></p>';
            break;

        case 'reminder':
            $offset = (int)($payload['offset_minutes'] ?? 0);
            $label  = _format_offset_label($offset);
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

        case 'event_message':
            // Host-authored note to attendees. SMS gets a short link only; email
            // gets the full rich body + link; WhatsApp gets full plain text + link.
            $msgId = (int)($payload['message_id'] ?? 0);
            $msg = null;
            if ($msgId > 0) {
                $ms = $db->prepare('SELECT m.subject, m.body_html, m.token, u.username AS sender
                                    FROM event_messages m LEFT JOIN users u ON u.id = m.created_by
                                    WHERE m.id = ?');
                $ms->execute([$msgId]);
                $msg = $ms->fetch();
            }
            if (!$msg) return true; // message deleted — treat as handled
            $site     = get_setting('site_name', 'Game Night');
            $sender   = ($msg['sender'] ?? '') !== '' ? $msg['sender'] : $site;  // fallback if author was deleted
            $viewUrl  = $site_url . '/event_message.php?token=' . urlencode($msg['token']);
            $shortUrl = (get_setting('url_shortener_enabled') === '1') ? shorten_url($viewUrl) : $viewUrl;
            $subject  = $msg['subject'];
            // Email: attribution line + full rich body + link.
            $htmlBody = '<p style="color:#64748b;font-size:.85rem;margin:0 0 .75rem">From <strong>'
                      . htmlspecialchars($sender) . '</strong> &middot; ' . htmlspecialchars($title) . '</p>'
                      . $msg['body_html']
                      . '<p style="margin-top:1.25rem"><a href="' . htmlspecialchars($viewUrl) . '" style="color:#2563eb">View this message in your browser</a></p>';
            // SMS: who + event + link only (link on its own line). Plain ASCII to stay GSM-7.
            $smsBody  = $site . ': new message from ' . $sender . ' about "' . $title . '".'
                      . "\n" . 'Tap to read: ' . $shortUrl;
            // WhatsApp: "From <sender> · <event>" header, then full message as plain text + link.
            $plain    = strip_tags(str_replace(['</p>', '<br>', '<br/>', '<br />', '</div>', '</li>'], "\n", $msg['body_html']));
            $plain    = trim(preg_replace('/\n{3,}/', "\n\n", html_entity_decode($plain, ENT_QUOTES)));
            $waBody   = 'From ' . $sender . ' ' . "\xC2\xB7" . ' ' . $title . "\n\n" . $plain . "\n\n" . $shortUrl;
            break;

        case 'event_poll':
            // Host poll for Yes/Maybe invitees. Email links to the tokenized
            // answer page; SMS/WhatsApp present Q1 for reply-by-number (state
            // seeded below) with the link as fallback.
            require_once __DIR__ . '/_polls.php';
            $pollId = (int)($payload['poll_id'] ?? 0);
            $recId  = (int)($payload['recipient_id'] ?? 0);
            $poll = $pollId > 0 ? poll_load($db, $pollId) : null;
            $recRow = null;
            if ($recId > 0) {
                $rq = $db->prepare('SELECT * FROM poll_recipients WHERE id = ? AND poll_id = ?');
                $rq->execute([$recId, $pollId]);
                $recRow = $rq->fetch();
            }
            if (!$poll || !$recRow || $poll['status'] !== 'open' || empty($poll['questions'])) {
                return true; // poll deleted/closed before delivery — treat as handled
            }
            $site    = get_setting('site_name', 'Game Night');
            $pollUrl = poll_link_for_recipient($recRow);
            $qCount  = count($poll['questions']);
            $subject = 'Poll: ' . $poll['title'] . ' - ' . $title;

            // Email: intro + question list + button to the answer page.
            $qList = '';
            foreach ($poll['questions'] as $q) {
                $qList .= '<li>' . htmlspecialchars($q['question']) . '</li>';
            }
            $htmlBody = '<p>You\'re invited to answer a quick poll for <strong>' . htmlspecialchars($title) . '</strong> on ' . htmlspecialchars($start) . ':</p>'
                      . '<p style="font-weight:700;margin:.75rem 0 .25rem">' . htmlspecialchars($poll['title']) . '</p>'
                      . '<ul style="color:#475569">' . $qList . '</ul>'
                      . '<p style="margin-top:1.25rem"><a href="' . htmlspecialchars($pollUrl) . '" style="display:inline-block;padding:.55rem 1.4rem;border-radius:7px;text-decoration:none;font-weight:600;background:#2563eb;color:#fff">Answer the poll</a></p>'
                      . '<p style="color:#94a3b8;font-size:.8rem">Answers show as counts only — nobody sees who picked what.</p>';

            // SMS/WhatsApp: poll header + Q1 with numbered options + link fallback.
            $smsBody = $site . ': Poll for "' . $title . '" - ' . $poll['title']
                     . ($qCount > 1 ? " ($qCount questions)" : '') . "\n"
                     . poll_question_text($poll, 0) . "\n"
                     . 'Or vote online: ' . $pollUrl;
            $waBody  = $smsBody;

            // Seed the reply-by-number conversation when this recipient can
            // actually text back (has a phone and an SMS/WhatsApp channel).
            $pc = $user['preferred_contact'] ?? 'email';
            if (!empty($user['phone']) && in_array($pc, ['sms', 'whatsapp', 'both'], true)) {
                poll_start_conversation($db, (string)$user['phone'], (int)$recRow['id']);
            }
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
        $subject, $smsBody, $htmlBody, $waBody
    );

    // Mark as sent IMMEDIATELY after send_notification returns, regardless of per-channel
    // errors. Rationale: if a user is on 'both' and SMS fails but email succeeded, we must
    // NOT re-send the email on the next retry. The dedup row prevents that re-delivery.
    $db->prepare(
        "INSERT OR IGNORE INTO event_notifications_sent (event_id, occurrence_date, user_identifier, notification_type, sent_at)
         VALUES (?, ?, ?, ?, ?)"
    )->execute([
        $event_id,
        $occ_key,
        strtolower($username),
        $type_tag,
        date('Y-m-d H:i:s'),
    ]);

    // Surface provider-level errors. Rate limits trigger a pause. Other errors are logged
    // (not thrown) now that we've recorded the dedup marker — retrying the whole row
    // would re-send the email that already succeeded.
    $err = get_last_notification_error();
    if ($err !== null) {
        error_log("[GameNight] Notification partial failure for event=$event_id user=$username type=$type: $err");
        if (looks_like_rate_limit($err)) {
            pause_drain_on_rate_limit();
        }
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
