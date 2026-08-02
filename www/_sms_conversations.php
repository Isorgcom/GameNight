<?php
/**
 * SMS/WhatsApp conversation support: attribute inbound replies on the shared
 * number to the right event + person, notify the hosts, and keep a sticky
 * phone -> event binding so follow-up texts land in the same conversation.
 * Conversations themselves live in sms_log (keyed by phone_digits); the host
 * UI is sms_conversations.php / sms_conversations_dl.php.
 */

/** Digits-only 10-digit conversation key, or '' if not a usable phone. */
function sms_conv_digits(string $phone): string {
    $d = preg_replace('/\D/', '', $phone);
    if (strlen($d) === 11 && $d[0] === '1') $d = substr($d, 1);
    return strlen($d) === 10 ? $d : '';
}

/** Today (Y-m-d) in the site timezone, matching sms_admin_manageable_events(). */
function _sms_conv_today(): string {
    $tz = get_setting('timezone', 'UTC');
    return (new DateTime('now', new DateTimeZone($tz)))->format('Y-m-d');
}

/**
 * Layered attribution of an inbound message to an event + person.
 * Returns ['event_id' => ?int, 'username' => ?string, 'via' => string]:
 *   1. 'recent_outbound' — most recent outbound to this phone with event
 *      context within 72h (a reply to a reminder answers that reminder);
 *   2. 'binding'         — sticky sms_conversation_bind row, if its event
 *      hasn't passed (stale rows are deleted on miss);
 *   3. 'single_event'    — the sender maps to exactly one upcoming event via
 *      users phone match or event_invites.phone (digit-compared in PHP since
 *      SQLite has no regex; also yields unregistered invitees' display name);
 *   4. 'none'            — logged unattributed, surfaced in the admin bucket.
 */
function sms_conv_attribute(PDO $db, string $digits, ?array $user): array {
    $none = ['event_id' => null, 'username' => $user['username'] ?? null, 'via' => 'none'];
    if ($digits === '') return $none;

    // Layer 1: recent outbound with event context.
    $q = $db->prepare("SELECT event_id, username FROM sms_log
                       WHERE phone_digits = ? AND direction = 'outbound' AND event_id IS NOT NULL
                         AND created_at > datetime('now', '-72 hours')
                       ORDER BY id DESC LIMIT 1");
    $q->execute([$digits]);
    if ($row = $q->fetch()) {
        return ['event_id' => (int)$row['event_id'],
                'username' => $user['username'] ?? ($row['username'] ?: null),
                'via' => 'recent_outbound'];
    }

    $today = _sms_conv_today();

    // Layer 2: sticky binding, valid only while the event hasn't passed.
    $q = $db->prepare('SELECT b.event_id, b.username, e.start_date
                       FROM sms_conversation_bind b LEFT JOIN events e ON e.id = b.event_id
                       WHERE b.phone_digits = ?');
    $q->execute([$digits]);
    if ($row = $q->fetch()) {
        if ($row['start_date'] !== null && $row['start_date'] >= $today) {
            return ['event_id' => (int)$row['event_id'],
                    'username' => $user['username'] ?? ($row['username'] ?: null),
                    'via' => 'binding'];
        }
        $db->prepare('DELETE FROM sms_conversation_bind WHERE phone_digits = ?')->execute([$digits]);
    }

    // Layer 3: sender is tied to exactly one upcoming event.
    $eventIds = [];
    $username = $user['username'] ?? null;
    if ($user) {
        $q = $db->prepare('SELECT DISTINCT ei.event_id FROM event_invites ei
                           JOIN events e ON e.id = ei.event_id
                           WHERE LOWER(ei.username) = LOWER(?) AND e.start_date >= ?');
        $q->execute([$user['username'], $today]);
        $eventIds = $q->fetchAll(PDO::FETCH_COLUMN);
    } else {
        $q = $db->prepare("SELECT ei.event_id, ei.username, ei.phone FROM event_invites ei
                           JOIN events e ON e.id = ei.event_id
                           WHERE ei.phone IS NOT NULL AND ei.phone != '' AND e.start_date >= ?");
        $q->execute([$today]);
        foreach ($q->fetchAll() as $inv) {
            if (sms_conv_digits((string)$inv['phone']) === $digits) {
                $eventIds[(int)$inv['event_id']] = true;
                $username = $username ?? ($inv['username'] ?: null);
            }
        }
        $eventIds = array_keys($eventIds);
    }
    if (count(array_unique($eventIds)) === 1) {
        return ['event_id' => (int)reset($eventIds), 'username' => $username, 'via' => 'single_event'];
    }

    $none['username'] = $username;
    return $none;
}

/**
 * Tag a just-logged inbound sms_log row with its attribution and refresh the
 * sticky binding. No-op when unattributed.
 */
function sms_conv_tag_inbound(PDO $db, int $log_id, string $digits, array $attr): void {
    if (empty($attr['event_id'])) return;
    try {
        $db->prepare('UPDATE sms_log SET event_id = ?, username = ? WHERE id = ?')
           ->execute([$attr['event_id'], $attr['username'], $log_id]);
        sms_conv_bind($db, $digits, (int)$attr['event_id'], $attr['username']);
    } catch (Exception $e) { /* attribution is best-effort */ }
}

/** Upsert the sticky phone -> event binding. */
function sms_conv_bind(PDO $db, string $digits, int $event_id, ?string $username): void {
    if ($digits === '' || $event_id <= 0) return;
    $db->prepare('INSERT OR REPLACE INTO sms_conversation_bind (phone_digits, event_id, username, updated_at)
                  VALUES (?, ?, ?, CURRENT_TIMESTAMP)')
       ->execute([$digits, $event_id, $username]);
}

/**
 * In-app notify the event's hosts (creator + per-event managers, the
 * queue_rsvp_reply_notifications recipient set) that a text reply arrived.
 * Skips the sender when a host texts their own event.
 */
function sms_conv_notify_hosts(PDO $db, int $event_id, string $display, string $body, string $digits): void {
    require_once __DIR__ . '/_notifications.php';  // notify_user_direct()
    $t = $db->prepare('SELECT title FROM events WHERE id = ?');
    $t->execute([$event_id]);
    $title = (string)($t->fetchColumn() ?: 'your event');

    $recips = [];
    $c = $db->prepare('SELECT u.id, u.username FROM events e JOIN users u ON u.id = e.created_by WHERE e.id = ?');
    $c->execute([$event_id]);
    if ($cu = $c->fetch()) $recips[strtolower($cu['username'])] = (int)$cu['id'];

    $m = $db->prepare("SELECT DISTINCT u.id, u.username FROM event_invites ei
                       JOIN users u ON LOWER(u.username) = LOWER(ei.username)
                       WHERE ei.event_id = ? AND ei.event_role = 'manager'");
    $m->execute([$event_id]);
    foreach ($m->fetchAll() as $mu) $recips[strtolower($mu['username'])] = (int)$mu['id'];

    unset($recips[strtolower($display)]);

    $link    = '/sms_conversations.php?event=' . $event_id . '&phone=' . $digits;
    $subject = "Text reply from $display";
    $excerpt = mb_substr($body, 0, 500);
    $smsBody = "$display replied by text about \"$title\": " . get_site_url() . $link;
    foreach ($recips as $uid) {
        notify_user_direct($db, $uid, 'sms_reply', $subject, $excerpt, $link, $smsBody);
    }
}

/**
 * Should we auto-ack this attributed free text? Only the first message of a
 * conversation session gets "Got it" - repeat acks read as spam, so anything
 * further within 12 hours is captured silently (the host is still notified).
 * Detected via the logged ack itself, which every provider path records.
 */
function sms_conv_should_ack(PDO $db, string $digits): bool {
    $q = $db->prepare("SELECT COUNT(*) FROM sms_log
                       WHERE phone_digits = ? AND direction = 'outbound'
                         AND body LIKE 'Got it%'
                         AND created_at > datetime('now', '-12 hours')");
    $q->execute([$digits]);
    return (int)$q->fetchColumn() === 0;
}

/**
 * Channel for host replies: whatever the sender last texted in on.
 * 'whatsapp' if the latest inbound row for these digits came via WhatsApp.
 */
function sms_conv_channel(PDO $db, string $digits): string {
    $q = $db->prepare("SELECT provider FROM sms_log
                       WHERE phone_digits = ? AND direction = 'inbound'
                       ORDER BY id DESC LIMIT 1");
    $q->execute([$digits]);
    return $q->fetchColumn() === 'whatsapp' ? 'whatsapp' : 'sms';
}
