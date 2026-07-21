<?php
/**
 * Direct-message helpers — participant model. A conversation is either a 1:1
 * pair (pair_key 'minId:maxId', UNIQUE) or a group (is_group=1, pair_key NULL,
 * optional title). All per-member state lives on dm_participants:
 *   cleared_before_id — soft delete: my views only show messages with a
 *                       higher id; other members keep everything
 *   last_read_msg_id  — my read pointer (replaces dm_messages.read_at)
 *   last_seen_at      — presence heartbeat (thread open / 4s poll)
 *   last_alert_at     — when the cron sweep last emailed/texted me
 *
 * Who can START a 1:1 (dm_can_initiate) / be ADDED to a group:
 *   - either party is an admin
 *   - they share a league (league_members)
 *   - they share an event (both have base event_invites rows), or one is the
 *     creator of an event the other is invited to
 *   - the recipient is in the initiator's contacts with a linked account
 * Sending inside an existing conversation is always allowed for participants.
 */

function dm_can_initiate(PDO $db, array $sender, array $recipient): bool {
    if (($sender['role'] ?? '') === 'admin' || ($recipient['role'] ?? '') === 'admin') return true;
    $sid = (int)$sender['id'];
    $rid = (int)$recipient['id'];

    // Shared league
    $q = $db->prepare('SELECT 1 FROM league_members a
                       JOIN league_members b ON b.league_id = a.league_id AND b.user_id = ?
                       WHERE a.user_id = ? LIMIT 1');
    $q->execute([$rid, $sid]);
    if ($q->fetchColumn()) return true;

    // Shared event (base invite rows are keyed by username, occurrence_date NULL)
    $q = $db->prepare('SELECT 1 FROM event_invites a
                       JOIN event_invites b ON b.event_id = a.event_id AND b.occurrence_date IS NULL
                       WHERE a.occurrence_date IS NULL
                         AND LOWER(a.username) = LOWER(?) AND LOWER(b.username) = LOWER(?) LIMIT 1');
    $q->execute([$sender['username'], $recipient['username']]);
    if ($q->fetchColumn()) return true;

    // Host <-> guest: one created an event the other is invited to
    $q = $db->prepare('SELECT 1 FROM events e
                       JOIN event_invites i ON i.event_id = e.id AND i.occurrence_date IS NULL
                       WHERE (e.created_by = ? AND LOWER(i.username) = LOWER(?))
                          OR (e.created_by = ? AND LOWER(i.username) = LOWER(?)) LIMIT 1');
    $q->execute([$sid, $recipient['username'], $rid, $sender['username']]);
    if ($q->fetchColumn()) return true;

    // Linked contact in the sender's address book
    $q = $db->prepare('SELECT 1 FROM user_contacts WHERE owner_user_id = ? AND linked_user_id = ? LIMIT 1');
    $q->execute([$sid, $rid]);
    return (bool)$q->fetchColumn();
}

/** Existing 1:1 conversation row for a pair, or null. */
function dm_conversation_for(PDO $db, int $x, int $y): ?array {
    $q = $db->prepare('SELECT * FROM dm_conversations WHERE pair_key = ?');
    $q->execute([min($x, $y) . ':' . max($x, $y)]);
    $row = $q->fetch();
    return $row ?: null;
}

/** Conversation row by id, or null. */
function dm_conversation(PDO $db, int $conv_id): ?array {
    $q = $db->prepare('SELECT * FROM dm_conversations WHERE id = ?');
    $q->execute([$conv_id]);
    $row = $q->fetch();
    return $row ?: null;
}

/** My participant row in a conversation, or null (= no access). */
function dm_participant(PDO $db, int $conv_id, int $uid): ?array {
    $q = $db->prepare('SELECT * FROM dm_participants WHERE conversation_id = ? AND user_id = ?');
    $q->execute([$conv_id, $uid]);
    $row = $q->fetch();
    return $row ?: null;
}

/** All members of a conversation with usernames, joined-order. */
function dm_participants(PDO $db, int $conv_id): array {
    $q = $db->prepare('SELECT p.*, u.username FROM dm_participants p
                       JOIN users u ON u.id = p.user_id
                       WHERE p.conversation_id = ? ORDER BY p.id');
    $q->execute([$conv_id]);
    return $q->fetchAll();
}

/** Display title: explicit title, or the other members' names from my seat. */
function dm_conversation_title(PDO $db, array $conv, int $uid): string {
    if (!empty($conv['title'])) return (string)$conv['title'];
    $names = [];
    foreach (dm_participants($db, (int)$conv['id']) as $p) {
        if ((int)$p['user_id'] !== $uid) $names[] = $p['username'];
    }
    if (!$names) return 'Conversation';
    return implode(', ', array_slice($names, 0, 4)) . (count($names) > 4 ? ' +' . (count($names) - 4) : '');
}

function dm_get_or_create_conversation(PDO $db, int $x, int $y, ?int $creator = null): array {
    $conv = dm_conversation_for($db, $x, $y);
    if (!$conv) {
        $db->prepare('INSERT OR IGNORE INTO dm_conversations (is_group, pair_key, created_by) VALUES (0, ?, ?)')
           ->execute([min($x, $y) . ':' . max($x, $y), $creator ?? $x]);
        $conv = dm_conversation_for($db, $x, $y);
    }
    foreach ([$x, $y] as $uid) {
        $db->prepare('INSERT OR IGNORE INTO dm_participants (conversation_id, user_id) VALUES (?, ?)')
           ->execute([(int)$conv['id'], $uid]);
    }
    return $conv;
}

/** Create a group with the creator + members (ids already scope-validated). */
function dm_create_group(PDO $db, int $creator_id, array $member_ids, string $title = ''): array {
    $db->prepare('INSERT INTO dm_conversations (is_group, title, created_by) VALUES (1, ?, ?)')
       ->execute([$title !== '' ? $title : null, $creator_id]);
    $conv_id = (int)$db->lastInsertId();
    $ins = $db->prepare('INSERT OR IGNORE INTO dm_participants (conversation_id, user_id) VALUES (?, ?)');
    $ins->execute([$conv_id, $creator_id]);
    foreach ($member_ids as $mid) {
        $ins->execute([$conv_id, (int)$mid]);
    }
    return dm_conversation($db, $conv_id);
}

/** Sending is allowed for participants; a fresh 1:1 additionally needs scope. */
function dm_can_send_pair(PDO $db, array $sender, array $recipient): bool {
    $conv = dm_conversation_for($db, (int)$sender['id'], (int)$recipient['id']);
    if ($conv && dm_participant($db, (int)$conv['id'], (int)$sender['id'])) {
        $q = $db->prepare('SELECT 1 FROM dm_messages WHERE conversation_id = ? LIMIT 1');
        $q->execute([(int)$conv['id']]);
        if ($q->fetchColumn()) return true;
    }
    return dm_can_initiate($db, $sender, $recipient);
}

/** Stamp my "viewing this thread" heartbeat (thread load + each poll). */
function dm_touch_seen(PDO $db, int $conv_id, int $uid): void {
    $db->prepare('UPDATE dm_participants SET last_seen_at = CURRENT_TIMESTAMP
                  WHERE conversation_id = ? AND user_id = ?')->execute([$conv_id, $uid]);
}

/** Advance my read pointer to the newest message in the conversation. */
function dm_mark_read(PDO $db, int $conv_id, int $uid): void {
    $db->prepare('UPDATE dm_participants
                  SET last_read_msg_id = (SELECT COALESCE(MAX(id), 0) FROM dm_messages WHERE conversation_id = ?)
                  WHERE conversation_id = ? AND user_id = ?')->execute([$conv_id, $conv_id, $uid]);
}

/** Unread messages to me across all conversations I'm in (watermark-aware). */
function dm_unread_count(PDO $db, int $uid): int {
    $q = $db->prepare('SELECT COUNT(*) FROM dm_messages m
                       JOIN dm_participants p ON p.conversation_id = m.conversation_id AND p.user_id = ?
                       WHERE m.sender_id <> ?
                         AND m.id > MAX(p.last_read_msg_id, p.cleared_before_id)');
    $q->execute([$uid, $uid]);
    return (int)$q->fetchColumn();
}

/**
 * Users the picker offers for a NEW conversation or group: everyone
 * dm_can_initiate would allow. Returns [id => username] sorted by username.
 */
function dm_eligible_recipients(PDO $db, array $user): array {
    $uid = (int)$user['id'];
    if (($user['role'] ?? '') === 'admin') {
        $rows = $db->query('SELECT id, username FROM users ORDER BY LOWER(username)')->fetchAll();
    } else {
        $q = $db->prepare("SELECT id, username FROM users WHERE id <> :me AND (
                role = 'admin'
                OR id IN (SELECT b.user_id FROM league_members a
                          JOIN league_members b ON b.league_id = a.league_id
                          WHERE a.user_id = :me)
                OR LOWER(username) IN (
                    SELECT LOWER(b.username) FROM event_invites a
                    JOIN event_invites b ON b.event_id = a.event_id AND b.occurrence_date IS NULL
                    WHERE a.occurrence_date IS NULL AND LOWER(a.username) = LOWER(:uname))
                OR LOWER(username) IN (
                    SELECT LOWER(i.username) FROM events e
                    JOIN event_invites i ON i.event_id = e.id AND i.occurrence_date IS NULL
                    WHERE e.created_by = :me)
                OR id IN (SELECT e.created_by FROM events e
                          JOIN event_invites i ON i.event_id = e.id AND i.occurrence_date IS NULL
                          WHERE LOWER(i.username) = LOWER(:uname))
                OR id IN (SELECT linked_user_id FROM user_contacts
                          WHERE owner_user_id = :me AND linked_user_id IS NOT NULL)
            ) ORDER BY LOWER(username)");
        $q->execute([':me' => $uid, ':uname' => $user['username']]);
        $rows = $q->fetchAll();
    }
    $out = [];
    foreach ($rows as $r) {
        if ((int)$r['id'] !== $uid) $out[(int)$r['id']] = $r['username'];
    }
    return $out;
}

/** Does the backing event for an event chat still exist? (Deleted events
 *  release the chat to behave like a normal group: leavable, no sync.) */
function dm_event_chat_event_exists(PDO $db, int $event_id): bool {
    $q = $db->prepare('SELECT 1 FROM events WHERE id = ?');
    $q->execute([$event_id]);
    return (bool)$q->fetchColumn();
}

/** The event chat conversation for an event, or null. */
function dm_event_chat(PDO $db, int $event_id): ?array {
    $q = $db->prepare('SELECT * FROM dm_conversations WHERE event_id = ?');
    $q->execute([$event_id]);
    $row = $q->fetch();
    return $row ?: null;
}

/** Get or create the event chat (group titled after the event, owned by its creator). */
function dm_event_chat_get_or_create(PDO $db, array $event): array {
    $conv = dm_event_chat($db, (int)$event['id']);
    if ($conv) return $conv;
    $db->prepare('INSERT INTO dm_conversations (is_group, title, created_by, event_id) VALUES (1, ?, ?, ?)')
       ->execute([(string)$event['title'], (int)$event['created_by'], (int)$event['id']]);
    $conv = dm_event_chat($db, (int)$event['id']);
    dm_sync_event_chat($db, $conv);
    return $conv;
}

/**
 * Sync event-chat membership from the roster: the event creator + registered
 * users with an approved base-invite RSVP of yes. Current members who can
 * manage the event (league managers, admins helping host) are kept even
 * without an RSVP; everyone else follows their RSVP — flip to no and you're
 * out, back to yes and you're in. New members start read-at-tail (no unread
 * backlog). Also refreshes the title from the event. If the event was
 * deleted, the chat is left alone and degrades to a normal group.
 */
function dm_sync_event_chat(PDO $db, array $conv): void {
    $event_id = (int)($conv['event_id'] ?? 0);
    if ($event_id <= 0) return;
    $eq = $db->prepare('SELECT id, title, created_by FROM events WHERE id = ?');
    $eq->execute([$event_id]);
    $event = $eq->fetch();
    if (!$event) return; // event deleted: freeze membership, act as normal group

    $conv_id = (int)$conv['id'];
    if ((string)$conv['title'] !== (string)$event['title']) {
        $db->prepare('UPDATE dm_conversations SET title = ? WHERE id = ?')
           ->execute([(string)$event['title'], $conv_id]);
    }

    // Desired: creator + approved yes-RSVP registered users (base rows).
    $desired = [(int)$event['created_by'] => true];
    $q = $db->prepare("SELECT u.id FROM event_invites i
                       JOIN users u ON LOWER(u.username) = LOWER(i.username)
                       WHERE i.event_id = ? AND i.occurrence_date IS NULL
                         AND LOWER(COALESCE(i.rsvp,'')) = 'yes'
                         AND COALESCE(i.approval_status,'approved') = 'approved'");
    $q->execute([$event_id]);
    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $uidRow) {
        $desired[(int)$uidRow] = true;
    }

    $tailQ = $db->prepare('SELECT COALESCE(MAX(id),0) FROM dm_messages WHERE conversation_id = ?');
    $tailQ->execute([$conv_id]);
    $tail = (int)$tailQ->fetchColumn();

    $currentIds = [];
    foreach (dm_participants($db, $conv_id) as $p) {
        $pid = (int)$p['user_id'];
        $currentIds[$pid] = true;
        if (isset($desired[$pid])) continue;
        if (!empty($p['manual_add'])) continue; // added by hand: sync leaves them be
        // Not on the roster: managers stay, everyone else leaves with their RSVP.
        $rq = $db->prepare('SELECT role FROM users WHERE id = ?');
        $rq->execute([$pid]);
        $role = (string)$rq->fetchColumn();
        if (can_manage_event($db, $event_id, $pid, $role === 'admin')) continue;
        $db->prepare('DELETE FROM dm_participants WHERE conversation_id = ? AND user_id = ?')
           ->execute([$conv_id, $pid]);
    }
    // New members join read-at-tail AND with a cleared watermark, so they see
    // the conversation only from their join forward — not the prior backlog.
    $ins = $db->prepare('INSERT OR IGNORE INTO dm_participants (conversation_id, user_id, last_read_msg_id, cleared_before_id) VALUES (?, ?, ?, ?)');
    foreach (array_keys($desired) as $uidNew) {
        if (!isset($currentIds[$uidNew])) $ins->execute([$conv_id, $uidNew, $tail, $tail]);
    }
}

/**
 * Render a plain-text DM body as safe HTML with clickable links: escape
 * EVERYTHING first, then wrap http(s)/www URLs in anchors. Trailing
 * punctuation stays outside the link. Mirrors linkify() in message_thread.php
 * (the live-append path) — keep the two in sync.
 */
function dm_linkify(string $text): string {
    $esc = htmlspecialchars($text);
    return preg_replace_callback(
        '#(?:https?://|www\.)[^\s<]*[^\s<.,!?;:)\]\'"]#i',
        function ($m) {
            $url  = $m[0];
            $href = stripos($url, 'http') === 0 ? $url : 'https://' . $url;
            return '<a href="' . $href . '" target="_blank" rel="noopener noreferrer">' . $url . '</a>';
        },
        $esc
    );
}

/** The bell/thread link for a conversation from a given recipient's seat. */
function dm_thread_link(array $conv, int $sender_id): string {
    return !empty($conv['is_group'])
        ? '/message_thread.php?conv=' . (int)$conv['id']
        : '/message_thread.php?user=' . $sender_id;
}

/**
 * In-app alert for a new DM, fanned out to every other participant. NO
 * email/SMS is sent here — outbound goes only through dm_alert_drain() (cron),
 * which fires solely for messages the member has NOT seen after 10 minutes.
 * Per recipient:
 *   - Presence: watching the thread in the last 20s -> nothing (live bubble
 *     with its own chime).
 *   - Bell collapse: while an unread 'dm' bell row for this conversation
 *     exists, bump it instead of stacking rows.
 */
function dm_notify(PDO $db, array $conv, array $sender, string $body): void {
    $conv_id   = (int)$conv['id'];
    $sender_id = (int)$sender['id'];
    $link      = dm_thread_link($conv, $sender_id);
    $isGroup   = !empty($conv['is_group']);
    $site_name = get_setting('site_name', 'GameNight');

    foreach (dm_participants($db, $conv_id) as $p) {
        $rid = (int)$p['user_id'];
        if ($rid === $sender_id) continue;
        $seenAt = $p['last_seen_at'] ?? null;
        if ($seenAt && (time() - strtotime($seenAt . ' UTC')) < 20) continue; // watching live

        $q = $db->prepare("SELECT id FROM user_notifications
                           WHERE user_id = ? AND notify_type = 'dm' AND link = ? AND is_read = 0
                           ORDER BY id DESC LIMIT 1");
        $q->execute([$rid, $link]);
        $existing = $q->fetch();
        if ($existing) {
            $db->prepare('UPDATE user_notifications SET created_at = CURRENT_TIMESTAMP WHERE id = ?')
               ->execute([(int)$existing['id']]);
            continue;
        }
        $subject = $isGroup
            ? 'New message in ' . dm_conversation_title($db, $conv, $rid) . ' on ' . $site_name
            : 'New message from ' . $sender['username'] . ' on ' . $site_name;
        $snippet = ($isGroup ? $sender['username'] . ': ' : '') . mb_substr(trim($body), 0, 120);
        try {
            $db->prepare("INSERT INTO user_notifications (user_id, event_id, notify_type, subject, body, link)
                          VALUES (?, NULL, 'dm', ?, ?, ?)")
               ->execute([$rid, $subject, $snippet, $link]);
        } catch (Throwable $e) { /* inbox is best-effort */ }
    }
}

/**
 * Cron sweep (every 5 min): email/SMS a DM alert ONLY for messages that have
 * sat unread for 10+ minutes — anyone who saw them on the site never gets an
 * external alert. Per participant:
 *   - at least one unread incoming message older than 10 min that arrived
 *     AFTER my last outbound alert (never re-alert the same messages), and
 *   - my last outbound alert is 30+ min old (floor between alerts).
 * One link-only alert per member per sweep; last_alert_at is stamped before
 * sending so a failure can't loop into spam. Returns alerts sent.
 */
function dm_alert_drain(PDO $db): int {
    if (get_setting('notifications_enabled', '0') !== '1') return 0;
    $rows = $db->query(
        "SELECT p.conversation_id, p.user_id AS recip_id, c.is_group, c.title,
                (SELECT COUNT(*) FROM dm_messages m WHERE m.conversation_id = p.conversation_id
                   AND m.sender_id <> p.user_id
                   AND m.id > MAX(p.last_read_msg_id, p.cleared_before_id)) AS unread_count,
                (SELECT m2.sender_id FROM dm_messages m2 WHERE m2.conversation_id = p.conversation_id
                   AND m2.sender_id <> p.user_id ORDER BY m2.id DESC LIMIT 1) AS last_sender_id
         FROM dm_participants p
         JOIN dm_conversations c ON c.id = p.conversation_id
         WHERE EXISTS (SELECT 1 FROM dm_messages m WHERE m.conversation_id = p.conversation_id
                         AND m.sender_id <> p.user_id
                         AND m.id > MAX(p.last_read_msg_id, p.cleared_before_id)
                         AND m.created_at < datetime('now', '-10 minutes')
                         AND m.created_at > COALESCE(p.last_alert_at, '1970-01-01'))
           AND (p.last_alert_at IS NULL OR p.last_alert_at < datetime('now', '-30 minutes'))"
    )->fetchAll();
    $sent = 0;
    foreach ($rows as $r) {
        $db->prepare('UPDATE dm_participants SET last_alert_at = CURRENT_TIMESTAMP
                      WHERE conversation_id = ? AND user_id = ?')
           ->execute([(int)$r['conversation_id'], (int)$r['recip_id']]);
        $s = $db->prepare('SELECT id, username FROM users WHERE id = ?');
        $s->execute([(int)$r['last_sender_id']]);
        $senderRow = $s->fetch();
        if (!$senderRow) continue;
        $conv = dm_conversation($db, (int)$r['conversation_id']);
        if (!$conv) continue;
        _dm_send_outbound($db, $conv, $senderRow, (int)$r['recip_id'], (int)$r['unread_count']);
        $sent++;
    }
    return $sent;
}

/**
 * Outbound (email/SMS/WhatsApp) leg of an unseen-DM alert. Link-only in
 * SMS/WhatsApp by design — the SMS webhook parses replies as RSVPs, so never
 * invite a text reply and never include message content.
 */
function _dm_send_outbound(PDO $db, array $conv, array $sender, int $recipient_id, int $unread_count): void {
    if (get_setting('notifications_enabled', '0') !== '1') return;
    $stmt = $db->prepare('SELECT username, email, phone, preferred_contact, notify_prefs FROM users WHERE id = ?');
    $stmt->execute([$recipient_id]);
    $u = $stmt->fetch();
    if (!$u || !user_notify_pref_enabled($u, 'dm')) return;
    $site_name = get_setting('site_name', 'GameNight');
    $threadUrl = get_site_url() . dm_thread_link($conv, (int)$sender['id']);
    $isGroup   = !empty($conv['is_group']);
    $where     = $isGroup ? ' in "' . dm_conversation_title($db, $conv, $recipient_id) . '"' : '';
    $subject   = 'New message' . ($isGroup ? $where : ' from ' . $sender['username']) . ' on ' . $site_name;
    $lead      = $unread_count > 1
               ? "You have $unread_count unread messages" . ($isGroup ? $where : " from {$sender['username']}") . " on $site_name"
               : ($isGroup ? "{$sender['username']} posted$where" : "{$sender['username']} sent you a message") . " on $site_name";
    $smsBody   = $lead . ': ' . shorten_url($threadUrl);
    $htmlBody  = '<p>' . htmlspecialchars($lead) . '.</p>'
               . '<p><a href="' . htmlspecialchars($threadUrl) . '" style="display:inline-block;padding:.5rem 1.2rem;border-radius:6px;text-decoration:none;font-weight:600;color:#fff;background:#2563eb">Read &amp; reply</a></p>';
    notif_log_context(null, $u['username']);
    send_notification($u['username'], $u['email'] ?? '', $u['phone'] ?? '',
                      $u['preferred_contact'] ?? 'email', $subject, $smsBody, $htmlBody);
    notif_log_context(null, null);
    $err = get_last_notification_error();
    if ($err !== null) {
        error_log("[GameNight] DM notification failure to user=$recipient_id: $err");
    }
}
