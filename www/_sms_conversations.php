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
    $none = ['event_id' => null, 'username' => $user['username'] ?? null, 'via' => 'none', 'candidates' => []];
    if ($digits === '') return $none;

    // Layer 1: recent outbound with event context. The EXISTS guard matters:
    // a reply to a reminder for a since-deleted event must not attribute to a
    // dead event id (hosts can't be resolved, so nobody would be notified).
    $q = $db->prepare("SELECT event_id, username FROM sms_log
                       WHERE phone_digits = ? AND direction = 'outbound' AND event_id IS NOT NULL
                         AND EXISTS (SELECT 1 FROM events e WHERE e.id = sms_log.event_id)
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
    [$eventIds, $invUsername] = sms_conv_candidate_events($db, $digits, $user);
    $username = $user['username'] ?? $invUsername;
    if (count($eventIds) === 1) {
        return ['event_id' => (int)reset($eventIds), 'username' => $username, 'via' => 'single_event'];
    }

    // Ambiguous or no match: expose the candidate events so the webhook can ask
    // the sender which one they mean (sms_conv_offer_choices).
    $none['username']   = $username;
    $none['candidates'] = $eventIds;
    return $none;
}

/**
 * The sender's candidate upcoming events, matched via users (registered) or
 * event_invites.phone (unregistered). Returns [event_id list, invite display
 * name or null]. Shared by attribution layer 3 and the WHICH/SWITCH commands.
 */
function sms_conv_candidate_events(PDO $db, string $digits, ?array $user): array {
    $today    = _sms_conv_today();
    $eventIds = [];
    $username = null;
    if ($user) {
        $q = $db->prepare('SELECT DISTINCT ei.event_id FROM event_invites ei
                           JOIN events e ON e.id = ei.event_id
                           WHERE LOWER(ei.username) = LOWER(?) AND e.start_date >= ?');
        $q->execute([$user['username'], $today]);
        $eventIds = $q->fetchAll(PDO::FETCH_COLUMN);
    } elseif ($digits !== '') {
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
    return [array_values(array_unique(array_map('intval', $eventIds))), $username];
}

/**
 * WHICH command: tell the sender where their texts currently go.
 */
function sms_conv_which_text(PDO $db, string $digits, ?array $user): string {
    $attr = sms_conv_attribute($db, $digits, $user);
    [$candidates] = sms_conv_candidate_events($db, $digits, $user);
    if (!empty($attr['event_id'])) {
        $q = $db->prepare('SELECT title, start_date FROM events WHERE id = ?');
        $q->execute([$attr['event_id']]);
        if ($ev = $q->fetch()) {
            $when  = date('M j', strtotime((string)$ev['start_date']));
            $extra = count($candidates) > 1 ? ' Reply SWITCH to pick a different event.' : '';
            return 'Your texts go to the host of "' . $ev['title'] . '" (' . $when . ').' . $extra;
        }
    }
    if (count($candidates) >= 2) return "Your texts aren't linked to an event yet. Reply SWITCH to pick one.";
    return "Your texts aren't linked to an event yet.";
}

/**
 * SWITCH command: let the sender re-point their conversation. With one
 * candidate event, binds immediately; with several, asks via the numbered
 * chooser (empty held body = nothing to deliver on pick, just re-bind).
 */
function sms_conv_switch_text(PDO $db, string $digits, ?array $user): string {
    [$candidates, $invUsername] = sms_conv_candidate_events($db, $digits, $user);
    $username = $user['username'] ?? $invUsername;
    if (count($candidates) === 0) return "You don't have any upcoming events to link your texts to.";
    if (count($candidates) === 1) {
        $event_id = (int)$candidates[0];
        $q = $db->prepare('SELECT title, start_date FROM events WHERE id = ?');
        $q->execute([$event_id]);
        $ev = $q->fetch();
        sms_conv_bind($db, $digits, $event_id, $username);
        $when = $ev ? date('M j', strtotime((string)$ev['start_date'])) : '';
        return 'Your texts now go to the host of "' . ($ev['title'] ?? 'your event') . '"' . ($when ? " ($when)" : '') . '.';
    }
    $ask = sms_conv_offer_choices($db, $digits, '', $candidates,
        'Which event should your texts go to?');
    return $ask ?? "Sorry, we couldn't load your events. Try again in a bit.";
}

/**
 * Ask the sender which event their message is about (ambiguous attribution).
 * Stores the original message phone-keyed so a numeric reply can deliver it.
 * Returns the prompt text, or null if a usable prompt can't be built.
 */
function sms_conv_offer_choices(PDO $db, string $digits, string $body, array $event_ids,
                                string $question = 'Which event is your message about?',
                                ?int $log_id = null): ?string {
    $event_ids = array_slice(array_values($event_ids), 0, 5);
    if ($digits === '' || count($event_ids) < 2) return null;
    $in = implode(',', array_fill(0, count($event_ids), '?'));
    $q  = $db->prepare("SELECT id, title, start_date FROM events WHERE id IN ($in)
                        ORDER BY start_date ASC, id ASC");
    $q->execute($event_ids);
    $events = $q->fetchAll();
    if (count($events) < 2) return null;

    $opts = [];
    $parts = [];
    foreach ($events as $i => $ev) {
        $opts[]  = (int)$ev['id'];
        $when    = date('M j', strtotime((string)$ev['start_date']));
        $parts[] = ($i + 1) . ' for ' . $ev['title'] . " ($when)";
    }
    $db->prepare('INSERT OR REPLACE INTO sms_pending_conv (phone_digits, body, options, log_id, created_at)
                  VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)')
       ->execute([$digits, $body, json_encode($opts), $log_id]);
    return $question . ' Reply ' . implode(', ', $parts) . '.';
}

/**
 * Consume a numeric reply to a pending which-event question (30-min TTL).
 * On a valid pick: retroactively attribute the sender's recent unattributed
 * messages, bind the conversation, notify the hosts with the held message, and
 * return the confirmation text. Returns null when this isn't a choice reply,
 * so the normal command/RSVP handling proceeds untouched.
 */
function sms_conv_handle_choice(PDO $db, string $digits, string $body, ?array $user): ?string {
    if ($digits === '' || !preg_match('/^\d{1,2}$/', trim($body))) return null;
    $q = $db->prepare("SELECT body, options, log_id FROM sms_pending_conv
                       WHERE phone_digits = ? AND created_at > datetime('now', '-30 minutes')");
    $q->execute([$digits]);
    $row = $q->fetch();
    if (!$row) return null;
    $opts = json_decode((string)$row['options'], true) ?: [];
    $idx  = (int)trim($body) - 1;
    if (!isset($opts[$idx])) return null;  // out of range: not answering us

    $event_id = (int)$opts[$idx];
    $db->prepare('DELETE FROM sms_pending_conv WHERE phone_digits = ?')->execute([$digits]);
    $t = $db->prepare('SELECT title FROM events WHERE id = ?');
    $t->execute([$event_id]);
    $title = $t->fetchColumn();
    if ($title === false) return 'Sorry, that event is no longer available.';

    // Display name: registered user, else the invite name on the chosen event.
    $username = $user['username'] ?? null;
    if ($username === null) {
        $iq = $db->prepare("SELECT username, phone FROM event_invites
                            WHERE event_id = ? AND phone IS NOT NULL AND phone != ''");
        $iq->execute([$event_id]);
        foreach ($iq->fetchAll() as $inv) {
            if (sms_conv_digits((string)$inv['phone']) === $digits) { $username = $inv['username'] ?: null; break; }
        }
    }

    $db->prepare("UPDATE sms_log SET event_id = ?, username = ?
                  WHERE phone_digits = ? AND direction = 'inbound' AND event_id IS NULL
                    AND created_at > datetime('now', '-24 hours')")
       ->execute([$event_id, $username, $digits]);
    sms_conv_bind($db, $digits, $event_id, $username);
    if ((string)$row['body'] === '') {
        // SWITCH pick: nothing held to deliver, just re-point the conversation.
        return 'Your texts now go to the host of "' . $title . '".';
    }
    sms_conv_mark($db, $row['log_id'] !== null ? (int)$row['log_id'] : null);
    $display = $username ?: (substr($digits, 0, 3) . '-' . substr($digits, 3, 3) . '-' . substr($digits, 6, 4));
    sms_conv_notify_hosts($db, $event_id, $display, (string)$row['body'], $digits);
    return 'Got it - passed your message along to the host of "' . $title . '".';
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

/**
 * Mark a logged message as real conversation so it shows in the host's
 * conversation view (commands/RSVPs/automated sends stay unmarked).
 */
function sms_conv_mark(PDO $db, ?int $log_id): void {
    if (!$log_id) return;
    try {
        $db->prepare('UPDATE sms_log SET is_conversation = 1 WHERE id = ?')->execute([$log_id]);
    } catch (Exception $e) { /* best-effort */ }
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
    // Email needs its own body - notify_user_direct sends htmlBody as the email
    // and an empty one makes the send fail with "Message body empty".
    $htmlBody = '<p><strong>' . htmlspecialchars($display) . '</strong> replied by text about "'
              . htmlspecialchars($title) . '":</p>'
              . '<blockquote style="border-left:3px solid #cbd5e1;margin:.5rem 0;padding:.25rem .75rem;color:#334155">'
              . nl2br(htmlspecialchars($excerpt)) . '</blockquote>'
              . '<p><a href="' . htmlspecialchars(get_site_url() . $link) . '">Open the conversation</a></p>';
    foreach ($recips as $uid) {
        notify_user_direct($db, $uid, 'sms_reply', $subject, $excerpt, $link, $smsBody, $htmlBody);
    }
}

/**
 * Bare YES/NO/MAYBE from a phone-only invitee (no account): the invite text
 * tells every recipient to reply, so guests must be able to RSVP by SMS.
 * Applies to the soonest upcoming approved invite matching the phone; the
 * confirmation names the event so a multi-invite guest can spot a wrong
 * target. Returns the reply text, or null when not an RSVP / nothing to answer.
 */
function sms_guest_rsvp(PDO $db, string $digits, string $body): ?string {
    $map = ['yes' => 'yes', 'y' => 'yes', 'going' => 'yes', 'attend' => 'yes',
            'no' => 'no', 'n' => 'no', 'not going' => 'no', 'decline' => 'no',
            'maybe' => 'maybe', 'm' => 'maybe', 'unsure' => 'maybe'];
    $rsvp = $map[strtolower(trim($body))] ?? null;
    if ($rsvp === null || $digits === '') return null;

    $q = $db->prepare("SELECT ei.id, ei.event_id, ei.username, ei.phone, e.title, e.start_date
                       FROM event_invites ei JOIN events e ON e.id = ei.event_id
                       WHERE ei.phone IS NOT NULL AND ei.phone != '' AND e.start_date >= ?
                         AND ei.approval_status = 'approved' AND ei.occurrence_date IS NULL
                       ORDER BY e.start_date ASC, e.id ASC");
    $q->execute([_sms_conv_today()]);
    $inv = null;
    foreach ($q->fetchAll() as $row) {
        if (sms_conv_digits((string)$row['phone']) === $digits) { $inv = $row; break; }
    }
    if (!$inv) return null;

    $db->prepare('UPDATE event_invites SET rsvp = ? WHERE id = ?')->execute([$rsvp, (int)$inv['id']]);
    if ($rsvp === 'no') maybe_promote_waitlisted($db, (int)$inv['event_id']);
    require_once __DIR__ . '/_notifications.php';
    queue_rsvp_reply_notifications($db, (int)$inv['event_id'], null,
                                   (string)$inv['username'], (string)$inv['username'], $rsvp);
    return 'Got it! Your RSVP for "' . $inv['title'] . '" on ' . $inv['start_date'] . ' is now: ' . ucfirst($rsvp) . '.';
}

/**
 * Does this registered user still have an unanswered upcoming invite? A bare
 * RSVP keyword must then RSVP — never divert to conversation (an invitee who
 * was just told "Reply YES" and did exactly that expects an RSVP).
 */
function sms_unanswered_invite(PDO $db, array $user): bool {
    $q = $db->prepare("SELECT COUNT(*) FROM event_invites ei JOIN events e ON e.id = ei.event_id
                       WHERE LOWER(ei.username) = LOWER(?) AND e.start_date >= ?
                         AND ei.approval_status = 'approved'
                         AND (ei.rsvp IS NULL OR ei.rsvp = '')");
    $q->execute([$user['username'], _sms_conv_today()]);
    return (int)$q->fetchColumn() > 0;
}

/**
 * Is this phone mid-conversation? True when the latest conversation row
 * (either direction, last 12 hours) is newer than the latest outbound that
 * solicited an RSVP (invite/nudge/reminder - matched by their 'RSVP' wording
 * plus event context; conversation machinery never carries both). Used to
 * keep a bare "no" answering the host from flipping the sender's RSVP,
 * while "yes" right after a fresh invite/reminder still RSVPs.
 */
function sms_conv_active(PDO $db, string $digits): bool {
    if ($digits === '') return false;
    $q = $db->prepare("SELECT MAX(id) FROM sms_log
                       WHERE phone_digits = ? AND is_conversation = 1
                         AND created_at > datetime('now', '-12 hours')");
    $q->execute([$digits]);
    $conv = (int)$q->fetchColumn();
    if (!$conv) return false;
    $q = $db->prepare("SELECT MAX(id) FROM sms_log
                       WHERE phone_digits = ? AND direction = 'outbound'
                         AND is_conversation = 0 AND event_id IS NOT NULL
                         AND body LIKE '%RSVP%'");
    $q->execute([$digits]);
    return $conv > (int)$q->fetchColumn();
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
