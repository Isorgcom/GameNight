<?php
/**
 * Service layer: reaching the guests. Invitations, nudges and host messages.
 *
 * Everything here causes email or texts to real people, so it sits behind its
 * own token permission ("messages") and every function refuses when the site
 * has notifications switched off. The event page (calendar.php) calls the
 * same functions, so a limit set here holds for the site and the API alike.
 */

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/../_notifications.php';

/** A failure when the site is not sending notifications at all, else null. */
function gn_notifications_off(): ?array {
    return get_setting('notifications_enabled', '0') === '1' ? null
        : gn_err('notifications_off', 'Notifications are currently disabled site-wide.', 409);
}

/**
 * Send invitations to every approved guest who has not been sent one. Safe to
 * repeat: only people still without one are reached.
 */
function gn_invitations_send(PDO $db, array $actor, int $event_id): array {
    $ev = gn_event_guard($db, $actor, $event_id);
    if (isset($ev['ok'])) return $ev;
    if ($off = gn_notifications_off()) return $off;
    $rows = $db->prepare(
        "SELECT ei.username, ei.user_id FROM event_invites ei
         WHERE ei.event_id = ? AND ei.occurrence_date IS NULL AND ei.approval_status = 'approved'
           AND NOT EXISTS (
               SELECT 1 FROM event_notifications_sent ens
               WHERE ens.event_id = ei.event_id AND ens.notification_type = 'invite'
                 AND ens.occurrence_date = '' AND ens.user_identifier = LOWER(ei.username)
           )");
    $rows->execute([$event_id]);
    $q = $db->prepare("INSERT INTO pending_notifications (event_id, username, user_id, notify_type) VALUES (?, ?, ?, 'invite')");
    $sent = 0;
    foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $t) {
        $q->execute([$event_id, $t['username'], $t['user_id'] !== null ? (int)$t['user_id'] : null]);
        $sent++;
    }
    if ($sent > 0) {
        db_log_activity((int)$actor['id'], "sent invites to $sent invitee(s) on event id: $event_id");
        drain_queue_async();
    }
    return gn_ok(['event_id' => $event_id, 'sent' => $sent]);
}

/** Send one guest their invitation again, by name on the list. */
function gn_invite_resend(PDO $db, array $actor, int $event_id, string $username): array {
    $ev = gn_event_guard($db, $actor, $event_id);
    if (isset($ev['ok'])) return $ev;
    $chk = $db->prepare('SELECT username, user_id FROM event_invites WHERE event_id = ? AND LOWER(username) = LOWER(?) AND occurrence_date IS NULL');
    $chk->execute([$event_id, $username]);
    $row = $chk->fetch(PDO::FETCH_ASSOC);
    if (!$row) return gn_err('invite_not_found', 'Invitee not found on this event.', 404);
    if ($off = gn_notifications_off()) return $off;
    // Clear the "already invited" marker so the send actually goes out.
    $db->prepare("DELETE FROM event_notifications_sent WHERE event_id = ? AND occurrence_date = '' AND user_identifier = ? AND notification_type = 'invite'")
       ->execute([$event_id, strtolower($username)]);
    // Drop rows that ran out of retries, so an old failure does not shadow this send.
    $db->prepare("DELETE FROM pending_notifications WHERE event_id = ? AND LOWER(username) = LOWER(?) AND notify_type IN ('invite','rsvp_nudge') AND attempted_at IS NULL AND attempts >= 3")
       ->execute([$event_id, $username]);
    $db->prepare("INSERT INTO pending_notifications (event_id, username, user_id, notify_type) VALUES (?, ?, ?, 'invite')")
       ->execute([$event_id, $username, $row['user_id'] !== null ? (int)$row['user_id'] : null]);
    db_log_activity((int)$actor['id'], "resent invite to $username on event id: $event_id");
    drain_queue_async();
    return gn_ok(['event_id' => $event_id, 'name' => (string)$row['username'], 'sent' => 1]);
}

/**
 * Nudge approved guests who were invited and have not answered. At most once
 * a day per guest, so repeating it the same day reaches nobody new.
 */
function gn_guests_nudge(PDO $db, array $actor, int $event_id): array {
    $ev = gn_event_guard($db, $actor, $event_id);
    if (isset($ev['ok'])) return $ev;
    if ($off = gn_notifications_off()) return $off;
    $today = date('Y-m-d');
    $rows = $db->prepare(
        "SELECT ei.username FROM event_invites ei
         WHERE ei.event_id = ? AND ei.occurrence_date IS NULL AND ei.approval_status = 'approved'
           AND ei.rsvp IS NULL
           AND EXISTS (
               SELECT 1 FROM event_notifications_sent ens
               WHERE ens.event_id = ei.event_id AND ens.notification_type = 'invite'
                 AND ens.occurrence_date = '' AND ens.user_identifier = LOWER(ei.username))
           AND NOT EXISTS (
               SELECT 1 FROM event_notifications_sent ens2
               WHERE ens2.event_id = ei.event_id AND ens2.notification_type = ?
                 AND ens2.occurrence_date = '' AND ens2.user_identifier = LOWER(ei.username))");
    $rows->execute([$event_id, 'rsvp_nudge_' . $today]);
    $sent = 0;
    foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $r) {
        queue_event_notification($db, $event_id, $r['username'], 'rsvp_nudge', null, ['day' => $today]);
        $sent++;
    }
    if ($sent > 0) {
        db_log_activity((int)$actor['id'], "nudged $sent non-responder(s) on event id: $event_id");
        drain_queue_async();
    }
    return gn_ok(['event_id' => $event_id, 'sent' => $sent]);
}

/** How many host messages one event may send in 24 hours (site setting host_message_daily_cap, default 3; 0 = no limit). */
function gn_host_message_cap(): int {
    return max(0, (int)get_setting('host_message_daily_cap', '3'));
}

/**
 * A failure when this event has used up its host messages for the day, else
 * null. A host message goes to every guest at once and is exempt from the
 * per-person daily notification limit, so this is the only brake on it.
 */
function gn_host_message_limit(PDO $db, int $event_id): ?array {
    $cap = gn_host_message_cap();
    if ($cap <= 0) return null;
    $c = $db->prepare("SELECT COUNT(*) FROM activity_log WHERE action LIKE ? AND created_at > datetime('now', '-1 day')");
    $c->execute(['sent event message %on event id: ' . $event_id]);
    if ((int)$c->fetchColumn() < $cap) return null;
    return gn_err('host_message_limit', "This event has already sent $cap messages to its guests in the last 24 hours. Try again tomorrow.", 429);
}

/**
 * Send a message from the host to the guests: emailed or texted to each, and
 * kept on the event. $args: subject, body (HTML, sanitised here), audience
 * (yes | yes_maybe | all, default yes), and for the site's own form an
 * already-stored PDF: token, attachment_name, attachment_file.
 */
function gn_host_message_send(PDO $db, array $actor, int $event_id, array $args): array {
    $ev = gn_event_guard($db, $actor, $event_id);
    if (isset($ev['ok'])) return $ev;
    if ($off = gn_notifications_off()) return $off;
    $subject  = trim((string)($args['subject'] ?? ''));
    $audience = (string)($args['audience'] ?? 'yes');
    if (!in_array($audience, ['yes', 'yes_maybe', 'all'], true)) return gn_err('bad_field', 'audience must be yes, yes_maybe or all.', 400);
    $body = sanitize_html((string)($args['body'] ?? ''));
    if ($subject === '' || trim(strip_tags($body)) === '') return gn_err('bad_field', 'A subject and a message are required.', 400);
    if (mb_strlen($subject) > 200) return gn_err('bad_field', 'The subject is longer than 200 characters.', 400);
    if ($lim = gn_host_message_limit($db, $event_id)) return $lim;

    $occ = (string)($args['occurrence_date'] ?? '');
    $occ = preg_match('/^\d{4}-\d{2}-\d{2}$/', $occ) ? $occ : ((string)$ev['start_date'] ?: null);
    $filter = $audience === 'yes' ? "AND rsvp = 'yes'" : ($audience === 'yes_maybe' ? "AND rsvp IN ('yes','maybe')" : '');
    $rows = $db->prepare("SELECT username FROM event_invites
                          WHERE event_id = ? AND occurrence_date IS NULL AND approval_status = 'approved' $filter");
    $rows->execute([$event_id]);
    $targets = $rows->fetchAll(PDO::FETCH_COLUMN);

    $token = preg_match('/^[a-f0-9]{32}$/', (string)($args['token'] ?? '')) ? (string)$args['token'] : bin2hex(random_bytes(16));
    $db->prepare('INSERT INTO event_messages (event_id, occurrence_date, token, subject, body_html, audience, created_by, attachment_name, attachment_file) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
       ->execute([$event_id, $occ, $token, $subject, $body, $audience, (int)$actor['id'], $args['attachment_name'] ?? null, $args['attachment_file'] ?? null]);
    $msg_id = (int)$db->lastInsertId();
    $sent = 0;
    foreach ($targets as $uname) {
        // queue_event_notification() schedules one shutdown drain itself; a
        // second drain here would race it.
        queue_event_notification($db, $event_id, (string)$uname, 'event_message', null, ['message_id' => $msg_id]);
        $sent++;
    }
    db_log_activity((int)$actor['id'], "sent event message ($audience) to $sent guest(s) on event id: $event_id");
    return gn_ok(['event_id' => $event_id, 'message_id' => $msg_id, 'audience' => $audience, 'sent' => $sent,
                  'url' => rtrim(get_site_url(), '/') . '/event_message.php?token=' . $token]);
}

/** The messages a host has sent for an event, newest first (host only). */
function gn_host_messages_list(PDO $db, array $actor, int $event_id): array {
    $ev = gn_event_guard($db, $actor, $event_id, true);
    if (isset($ev['ok'])) return $ev;
    $st = $db->prepare('SELECT m.id, m.subject, m.body_html, m.audience, m.created_at, m.attachment_name, u.username AS sender
                        FROM event_messages m LEFT JOIN users u ON u.id = m.created_by
                        WHERE m.event_id = ? ORDER BY m.id DESC');
    $st->execute([$event_id]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $m) {
        $out[] = ['message_id' => (int)$m['id'], 'subject' => (string)$m['subject'],
                  'text' => trim(html_entity_decode(strip_tags(preg_replace(['#<br\s*/?>\r?\n?#i', '#</p>\s*#i'], "\n", (string)$m['body_html'])), ENT_QUOTES | ENT_HTML5)),
                  'audience' => (string)$m['audience'], 'sent_by' => $m['sender'] ?: null,
                  'has_attachment' => !empty($m['attachment_name']), 'sent_at' => gn_iso($m['created_at'])];
    }
    $cap = gn_host_message_cap();
    return gn_ok(['event_id' => $event_id, 'count' => count($out), 'daily_limit' => $cap ?: null, 'messages' => $out]);
}
