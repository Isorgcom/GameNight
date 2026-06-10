<?php
/**
 * Admin/host SMS command layer, invoked from sms_webhook.php.
 *
 * Lets site admins and event owners/managers run their events by text:
 *   ADMIN / HELP        list the events you manage (numbered) + this help
 *   WHO <#>             roster + headcount for event #
 *   COUNT <#>           headcount only for event #
 *   PENDING             events with pending/waitlisted requests + names
 *   APPROVE <#> <name>  approve a pending/waitlisted invitee (executes immediately)
 *   MSG <#> <message>   broadcast a message to event #'s guests   (needs CONFIRM)
 *   REMIND <#>          send a reminder to event #'s guests        (needs CONFIRM)
 *   CANCEL <#>          cancel event # and notify everyone         (needs CONFIRM)
 *   CONFIRM             confirm the pending MSG/REMIND/CANCEL
 *
 * Event numbers come from the texter's "manageable events" list (see
 * sms_admin_manageable_events) and are stable across consecutive texts because
 * the list is deterministically ordered. Authorization is always re-checked with
 * can_manage_event() per event. Replies deliberately omit the "Reply STOP" footer.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/sms.php';
require_once __DIR__ . '/_notifications.php';

/**
 * Reply to the texter without the carrier opt-out footer. respond_to_provider()
 * is defined in sms_webhook.php (always loaded before this runs).
 */
function sms_admin_reply(string $provider, string $message): void {
    http_response_code(200);
    respond_to_provider($provider, $message, false);
}

/**
 * Upcoming events the texter can manage, deterministically ordered so that the
 * displayed number N is stable between texts. Admins manage everything; hosts
 * are filtered by can_manage_event(). Cached per request.
 */
function sms_admin_manageable_events(PDO $db, array $user): array {
    static $cache = [];
    $uid = (int)$user['id'];
    if (isset($cache[$uid])) return $cache[$uid];

    $isAdmin = (($user['role'] ?? '') === 'admin');
    $tz = get_setting('timezone', 'UTC');
    $today = (new DateTime('now', new DateTimeZone($tz)))->format('Y-m-d');

    $stmt = $db->prepare("SELECT id, title, start_date, start_time
                          FROM events WHERE start_date >= ?
                          ORDER BY start_date ASC, id ASC LIMIT 40");
    $stmt->execute([$today]);
    $out = [];
    foreach ($stmt->fetchAll() as $ev) {
        if (can_manage_event($db, (int)$ev['id'], $uid, $isAdmin)) {
            $out[] = $ev;
            if (count($out) >= 15) break;
        }
    }
    return $cache[$uid] = $out;
}

/** True if the texter may use admin commands at all (site admin or manages >=1 event). */
function sms_admin_is_elevated(PDO $db, array $user): bool {
    if (($user['role'] ?? '') === 'admin') return true;
    return count(sms_admin_manageable_events($db, $user)) > 0;
}

/** Resolve a 1-based event number against the manageable list. */
function sms_admin_resolve_event(array $events, string $token): ?array {
    if (!preg_match('/^\d+$/', $token)) return null;
    $idx = (int)$token - 1;
    return ($idx >= 0 && $idx < count($events)) ? $events[$idx] : null;
}

/** Headcount for an event's base invitees, split by approval state + RSVP. */
function sms_admin_event_counts(PDO $db, int $eid): array {
    $c = ['yes' => 0, 'no' => 0, 'maybe' => 0, 'none' => 0, 'pending' => 0, 'waitlisted' => 0];
    $stmt = $db->prepare("SELECT rsvp, approval_status FROM event_invites
                          WHERE event_id = ? AND occurrence_date IS NULL");
    $stmt->execute([$eid]);
    foreach ($stmt->fetchAll() as $r) {
        $status = $r['approval_status'] ?? 'approved';
        if ($status === 'pending')    { $c['pending']++;    continue; }
        if ($status === 'waitlisted') { $c['waitlisted']++; continue; }
        if ($status !== 'approved')   continue; // denied
        $rv = $r['rsvp'] ?: 'none';
        if (!isset($c[$rv])) $rv = 'none';
        $c[$rv]++;
    }
    return $c;
}

/** Short "Mon D" label for an event date. */
function sms_admin_date(string $start_date): string {
    return date('M j', strtotime($start_date));
}

/** Render the numbered manageable-events list. */
function sms_admin_event_list_text(array $events): string {
    if (!$events) return "You don't manage any upcoming events.";
    $out = "Your events:\n";
    foreach ($events as $i => $ev) {
        $out .= ($i + 1) . ". {$ev['title']} (" . sms_admin_date($ev['start_date']) . ")\n";
    }
    return rtrim($out);
}

/** Admin help text + the manageable-events list. */
function sms_admin_help_text(array $events): string {
    $help = "Admin commands:\n"
          . "WHO # - who's coming\n"
          . "COUNT # - headcount\n"
          . "PENDING - approval requests\n"
          . "APPROVE # name - approve a guest\n"
          . "MSG # text - message guests\n"
          . "REMIND # - send a reminder\n"
          . "CANCEL # - cancel an event\n"
          . "(MSG/REMIND/CANCEL need a CONFIRM reply)\n\n";
    return $help . sms_admin_event_list_text($events);
}

/**
 * Broadcast a plain-text message to an event's invitees by reusing the in-app
 * event_messages path (see calendar.php send_event_message). Returns recipients.
 */
function sms_admin_broadcast(PDO $db, int $eid, string $subject, string $bodyText, string $audience, int $actorId): int {
    $rsvpFilter = $audience === 'yes' ? "AND rsvp = 'yes'"
                : ($audience === 'yes_maybe' ? "AND rsvp IN ('yes','maybe')" : '');
    $rows = $db->prepare("SELECT username FROM event_invites
                          WHERE event_id = ? AND occurrence_date IS NULL
                            AND approval_status = 'approved' $rsvpFilter");
    $rows->execute([$eid]);
    $targets = array_column($rows->fetchAll(), 'username');

    $occStmt = $db->prepare('SELECT start_date FROM events WHERE id = ?');
    $occStmt->execute([$eid]);
    $occ = $occStmt->fetchColumn() ?: null;

    $token = bin2hex(random_bytes(16));
    $body  = sanitize_html('<p>' . htmlspecialchars($bodyText) . '</p>');
    $db->prepare('INSERT INTO event_messages (event_id, occurrence_date, token, subject, body_html, audience, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)')
       ->execute([$eid, $occ, $token, $subject, $body, $audience, $actorId]);
    $msgId = (int)$db->lastInsertId();

    $sent = 0;
    foreach ($targets as $uname) {
        queue_event_notification($db, $eid, $uname, 'event_message', null, ['message_id' => $msgId]);
        $sent++;
    }
    return $sent;
}

/**
 * Main entry point. Returns true if the message was an admin command this user
 * is allowed to run (caller should stop). Returns false to let normal RSVP/HELP
 * handling proceed (non-admin verbs, or a non-elevated texter).
 */
function sms_handle_admin_command(PDO $db, array $user, string $body, string $provider, string $from): bool {
    $trimmed = trim($body);
    $parts   = preg_split('/\s+/', $trimmed, 2);
    $verb    = strtolower($parts[0] ?? '');
    $rest    = trim($parts[1] ?? '');

    $adminVerbs = ['admin', 'who', 'count', 'pending', 'approve', 'msg', 'remind', 'cancel', 'confirm'];
    $isHelp     = in_array(strtolower($trimmed), ['help', 'h', '?', 'commands'], true);

    if (!in_array($verb, $adminVerbs, true) && !$isHelp) return false; // not an admin verb
    if (!sms_admin_is_elevated($db, $user)) return false;              // fall through to normal flow

    $actorId = (int)$user['id'];

    // ── CONFIRM: execute the pending MSG/REMIND/CANCEL ──────────────────────
    if ($verb === 'confirm') {
        sms_admin_handle_confirm($db, $user, $provider);
        return true;
    }

    // ── ADMIN / HELP: list manageable events + command help ─────────────────
    if ($verb === 'admin' || $isHelp) {
        sms_admin_reply($provider, sms_admin_help_text(sms_admin_manageable_events($db, $user)));
        return true;
    }

    $events = sms_admin_manageable_events($db, $user);

    // ── PENDING: events with approval requests ──────────────────────────────
    if ($verb === 'pending') {
        $lines = [];
        foreach ($events as $i => $ev) {
            $q = $db->prepare("SELECT username FROM event_invites
                               WHERE event_id = ? AND occurrence_date IS NULL
                                 AND approval_status IN ('pending','waitlisted')
                               ORDER BY sort_order IS NULL, sort_order, username");
            $q->execute([$ev['id']]);
            $names = array_column($q->fetchAll(), 'username');
            if ($names) {
                $lines[] = ($i + 1) . ". {$ev['title']}: " . implode(', ', $names);
            }
        }
        $reply = $lines ? ("Pending requests:\n" . implode("\n", $lines) . "\nReply APPROVE # name")
                        : "No pending requests on your events.";
        sms_admin_reply($provider, $reply);
        return true;
    }

    // ── WHO / COUNT: headcount (+ roster for WHO) ───────────────────────────
    if ($verb === 'who' || $verb === 'count') {
        $num = strtok($rest, ' ') ?: '';
        $ev  = sms_admin_resolve_event($events, $num);
        if (!$ev) {
            sms_admin_reply($provider, "Which event?\n" . sms_admin_event_list_text($events));
            return true;
        }
        $c = sms_admin_event_counts($db, (int)$ev['id']);
        $reply  = "\"{$ev['title']}\" (" . sms_admin_date($ev['start_date']) . ")\n";
        $reply .= "Yes {$c['yes']} / No {$c['no']} / Maybe {$c['maybe']} / No reply {$c['none']}";
        if ($c['pending'] || $c['waitlisted']) {
            $reply .= "\nPending {$c['pending']}, Waitlist {$c['waitlisted']}";
        }
        if ($verb === 'who') {
            $reply .= "\n" . sms_admin_roster_text($db, (int)$ev['id']);
        }
        sms_admin_reply($provider, $reply);
        return true;
    }

    // ── APPROVE <#> <name>: execute immediately ─────────────────────────────
    if ($verb === 'approve') {
        $tok  = preg_split('/\s+/', $rest, 2);
        $num  = $tok[0] ?? '';
        $name = trim($tok[1] ?? '');
        $ev   = sms_admin_resolve_event($events, $num);
        if (!$ev || $name === '') {
            sms_admin_reply($provider, "Usage: APPROVE # name\n" . sms_admin_event_list_text($events));
            return true;
        }
        $find = $db->prepare("SELECT username, approval_status FROM event_invites
                              WHERE event_id = ? AND occurrence_date IS NULL
                                AND LOWER(username) = LOWER(?)");
        $find->execute([$ev['id'], $name]);
        $inv = $find->fetch();
        if (!$inv) {
            sms_admin_reply($provider, "No invitee named \"$name\" on \"{$ev['title']}\".");
            return true;
        }
        if (($inv['approval_status'] ?? 'approved') === 'approved') {
            sms_admin_reply($provider, "{$inv['username']} is already approved.");
            return true;
        }
        $res = approve_event_invitee($db, (int)$ev['id'], $inv['username'], $actorId);
        sms_admin_reply($provider, "Approved {$inv['username']} for \"{$ev['title']}\".{$res['seat_info']}");
        return true;
    }

    // ── MSG / REMIND / CANCEL: stage a pending action, ask for CONFIRM ───────
    if (in_array($verb, ['msg', 'remind', 'cancel'], true)) {
        $tok  = preg_split('/\s+/', $rest, 2);
        $num  = $tok[0] ?? '';
        $ev   = sms_admin_resolve_event($events, $num);
        if (!$ev) {
            sms_admin_reply($provider, "Which event?\n" . sms_admin_event_list_text($events));
            return true;
        }
        $eid = (int)$ev['id'];

        if ($verb === 'msg') {
            $text = trim($tok[1] ?? '');
            if ($text === '') {
                sms_admin_reply($provider, "Usage: MSG # your message");
                return true;
            }
            if (get_setting('notifications_enabled', '0') !== '1') {
                sms_admin_reply($provider, "Notifications are disabled site-wide; can't send.");
                return true;
            }
            $n = sms_admin_audience_count($db, $eid, 'yes');
            sms_admin_store_pending($db, $actorId, 'msg', $eid, ['text' => $text]);
            sms_admin_reply($provider, "Send to $n guest(s) of \"{$ev['title']}\":\n\"$text\"\nReply CONFIRM.");
            return true;
        }

        if ($verb === 'remind') {
            if (get_setting('notifications_enabled', '0') !== '1') {
                sms_admin_reply($provider, "Notifications are disabled site-wide; can't send.");
                return true;
            }
            $n = sms_admin_audience_count($db, $eid, 'all');
            sms_admin_store_pending($db, $actorId, 'remind', $eid, null);
            sms_admin_reply($provider, "Remind $n guest(s) of \"{$ev['title']}\" (" . sms_admin_date($ev['start_date']) . ")? Reply CONFIRM.");
            return true;
        }

        // cancel
        $n = sms_admin_audience_count($db, $eid, 'base');
        sms_admin_store_pending($db, $actorId, 'cancel', $eid, null);
        sms_admin_reply($provider, "Cancel \"{$ev['title']}\" (" . sms_admin_date($ev['start_date']) . ")? $n will be notified. Reply CONFIRM.");
        return true;
    }

    return true; // an admin verb we recognized but didn't otherwise handle
}

/** Count recipients for a given audience ('yes' | 'all' approved | 'base' all invitees). */
function sms_admin_audience_count(PDO $db, int $eid, string $audience): int {
    if ($audience === 'base') {
        $q = $db->prepare("SELECT COUNT(*) FROM event_invites WHERE event_id = ? AND occurrence_date IS NULL");
        $q->execute([$eid]);
        return (int)$q->fetchColumn();
    }
    $filter = $audience === 'yes' ? "AND rsvp = 'yes'" : '';
    $q = $db->prepare("SELECT COUNT(*) FROM event_invites
                       WHERE event_id = ? AND occurrence_date IS NULL
                         AND approval_status = 'approved' $filter");
    $q->execute([$eid]);
    return (int)$q->fetchColumn();
}

/** Roster names by RSVP state, kept compact for SMS. */
function sms_admin_roster_text(PDO $db, int $eid): string {
    $q = $db->prepare("SELECT username, rsvp FROM event_invites
                       WHERE event_id = ? AND occurrence_date IS NULL AND approval_status = 'approved'
                       ORDER BY username");
    $q->execute([$eid]);
    $buckets = ['yes' => [], 'maybe' => [], 'no' => [], 'none' => []];
    foreach ($q->fetchAll() as $r) {
        $rv = $r['rsvp'] ?: 'none';
        if (!isset($buckets[$rv])) $rv = 'none';
        $buckets[$rv][] = $r['username'];
    }
    $lines = [];
    if ($buckets['yes'])   $lines[] = 'Going: '   . implode(', ', $buckets['yes']);
    if ($buckets['maybe']) $lines[] = 'Maybe: '   . implode(', ', $buckets['maybe']);
    if ($buckets['no'])    $lines[] = 'No: '      . implode(', ', $buckets['no']);
    if ($buckets['none'])  $lines[] = 'No reply: '. implode(', ', $buckets['none']);
    return $lines ? implode("\n", $lines) : 'No invitees yet.';
}

/** Stage one pending admin action awaiting CONFIRM (one per user). */
function sms_admin_store_pending(PDO $db, int $userId, string $command, int $eid, ?array $payload): void {
    $db->prepare("INSERT OR REPLACE INTO sms_pending_admin (user_id, command, event_id, payload, created_at)
                  VALUES (?, ?, ?, ?, datetime('now'))")
       ->execute([$userId, $command, $eid, $payload !== null ? json_encode($payload) : null]);
}

/** Execute the pending action for this user after they reply CONFIRM. */
function sms_admin_handle_confirm(PDO $db, array $user, string $provider): void {
    // Expire stale confirmations (older than 10 minutes).
    $db->prepare("DELETE FROM sms_pending_admin WHERE created_at < datetime('now', '-10 minutes')")->execute();

    $actorId = (int)$user['id'];
    $row = $db->prepare('SELECT command, event_id, payload FROM sms_pending_admin WHERE user_id = ?');
    $row->execute([$actorId]);
    $pending = $row->fetch();
    if (!$pending) {
        sms_admin_reply($provider, "Nothing to confirm.");
        return;
    }
    $db->prepare('DELETE FROM sms_pending_admin WHERE user_id = ?')->execute([$actorId]);

    $eid     = (int)$pending['event_id'];
    $command = (string)$pending['command'];
    $isAdmin = (($user['role'] ?? '') === 'admin');

    // Defense in depth: re-verify the event still exists and is still manageable.
    $ev = $db->prepare('SELECT title, start_date FROM events WHERE id = ?');
    $ev->execute([$eid]);
    $evRow = $ev->fetch();
    if (!$evRow) {
        sms_admin_reply($provider, "That event no longer exists.");
        return;
    }
    if (!can_manage_event($db, $eid, $actorId, $isAdmin)) {
        sms_admin_reply($provider, "You can no longer manage that event.");
        return;
    }
    $title = $evRow['title'];

    if ($command === 'cancel') {
        $res = cancel_event_with_notifications($db, $eid, $actorId);
        $n = $res['notified'] ?? 0;
        db_log_activity($actorId, "cancelled event id: $eid via SMS");
        sms_admin_reply($provider, "Cancelled \"$title\". $n guest(s) notified.");
        return;
    }

    if ($command === 'msg') {
        $payload = $pending['payload'] ? json_decode($pending['payload'], true) : [];
        $text = trim($payload['text'] ?? '');
        if ($text === '') {
            sms_admin_reply($provider, "Message was empty; nothing sent.");
            return;
        }
        $sent = sms_admin_broadcast($db, $eid, "Update: $title", $text, 'yes', $actorId);
        db_log_activity($actorId, "sent event message to $sent guest(s) on event id: $eid via SMS");
        sms_admin_reply($provider, "Message sent to $sent guest(s) of \"$title\".");
        return;
    }

    if ($command === 'remind') {
        $date = sms_admin_date($evRow['start_date']);
        $bodyText = "Reminder: \"$title\" is on $date. Reply YES, NO, or MAYBE to update your RSVP.";
        $sent = sms_admin_broadcast($db, $eid, "Reminder: $title", $bodyText, 'all', $actorId);
        db_log_activity($actorId, "sent reminder to $sent guest(s) on event id: $eid via SMS");
        sms_admin_reply($provider, "Reminder sent to $sent guest(s) of \"$title\".");
        return;
    }

    sms_admin_reply($provider, "Nothing to confirm.");
}
