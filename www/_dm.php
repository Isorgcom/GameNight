<?php
/**
 * Direct-message helpers. Conversations are one row per user pair with
 * user_a_id < user_b_id; per-side "cleared" watermarks give soft delete
 * (my views only show messages with id > my watermark).
 *
 * Who can START a conversation (dm_can_initiate):
 *   - either party is an admin
 *   - they share a league (league_members)
 *   - they share an event (both have base event_invites rows), or one is the
 *     creator of an event the other is invited to (hosts have no invite row
 *     to their own event)
 *   - the recipient is in the sender's contacts with a linked account
 * Replying inside an existing conversation is always allowed (dm_can_send).
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

/** Existing conversation row for a pair, or null. */
function dm_conversation_for(PDO $db, int $x, int $y): ?array {
    $a = min($x, $y);
    $b = max($x, $y);
    $q = $db->prepare('SELECT * FROM dm_conversations WHERE user_a_id = ? AND user_b_id = ?');
    $q->execute([$a, $b]);
    $row = $q->fetch();
    return $row ?: null;
}

/** Sending is allowed inside any conversation that already has messages, or when scope permits a new one. */
function dm_can_send(PDO $db, array $sender, array $recipient): bool {
    $conv = dm_conversation_for($db, (int)$sender['id'], (int)$recipient['id']);
    if ($conv) {
        $q = $db->prepare('SELECT 1 FROM dm_messages WHERE conversation_id = ? LIMIT 1');
        $q->execute([(int)$conv['id']]);
        if ($q->fetchColumn()) return true;
    }
    return dm_can_initiate($db, $sender, $recipient);
}

function dm_get_or_create_conversation(PDO $db, int $x, int $y): array {
    $conv = dm_conversation_for($db, $x, $y);
    if ($conv) return $conv;
    $db->prepare('INSERT OR IGNORE INTO dm_conversations (user_a_id, user_b_id) VALUES (?, ?)')
       ->execute([min($x, $y), max($x, $y)]);
    return dm_conversation_for($db, $x, $y);
}

/** My side's cleared watermark for a conversation row. */
function dm_my_watermark(array $conv, int $uid): int {
    return (int)((int)$conv['user_a_id'] === $uid ? $conv['a_cleared_before_id'] : $conv['b_cleared_before_id']);
}

/** Unread messages to me across all conversations, respecting my cleared watermarks. */
function dm_unread_count(PDO $db, int $uid): int {
    $q = $db->prepare('SELECT COUNT(*) FROM dm_messages m
                       JOIN dm_conversations c ON c.id = m.conversation_id
                       WHERE m.sender_id <> ? AND m.read_at IS NULL
                         AND ( (c.user_a_id = ? AND m.id > c.a_cleared_before_id)
                            OR (c.user_b_id = ? AND m.id > c.b_cleared_before_id) )');
    $q->execute([$uid, $uid, $uid]);
    return (int)$q->fetchColumn();
}

/**
 * Users the picker offers for a NEW conversation: everyone dm_can_initiate
 * would allow. Returns [id => username] sorted by username.
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

/** Stamp my "viewing this thread" heartbeat (called on thread load + each poll). */
function dm_touch_seen(PDO $db, array $conv, int $uid): void {
    $col = (int)$conv['user_a_id'] === $uid ? 'a_last_seen_at' : 'b_last_seen_at';
    $db->prepare("UPDATE dm_conversations SET $col = CURRENT_TIMESTAMP WHERE id = ?")
       ->execute([(int)$conv['id']]);
}

/**
 * In-app alert for a new DM. NO email/SMS is sent here — outbound goes only
 * through dm_alert_drain() (cron), which fires solely for messages the user
 * has NOT seen after 10 minutes. In-app, every incoming message chimes (thread
 * page for open conversations, the _footer.php poller everywhere else).
 * Bell-row damping:
 *   - Presence: the thread page heartbeats a per-side last_seen_at (page load
 *     + 4s poll). A recipient watching this thread in the last 20s gets no
 *     bell row — the message lands as a live bubble (with its own chime).
 *   - Collapse: while an unread 'dm' bell row for this sender exists, bump it
 *     instead of stacking new rows.
 */
function dm_notify(PDO $db, array $conv, array $sender, array $recipient, string $body): void {
    $rid = (int)$recipient['id'];
    // Re-read the presence column (the caller's $conv may predate the
    // recipient's latest heartbeat).
    $q = $db->prepare('SELECT * FROM dm_conversations WHERE id = ?');
    $q->execute([(int)$conv['id']]);
    $conv = $q->fetch() ?: $conv;
    $seenAt = $conv[(int)$conv['user_a_id'] === $rid ? 'a_last_seen_at' : 'b_last_seen_at'] ?? null;
    if ($seenAt && (time() - strtotime($seenAt . ' UTC')) < 20) return; // watching live

    $link = '/message_thread.php?user=' . (int)$sender['id'];
    $q = $db->prepare("SELECT id FROM user_notifications
                       WHERE user_id = ? AND notify_type = 'dm' AND link = ? AND is_read = 0
                       ORDER BY id DESC LIMIT 1");
    $q->execute([$rid, $link]);
    $existing = $q->fetch();
    if ($existing) {
        $db->prepare('UPDATE user_notifications SET created_at = CURRENT_TIMESTAMP WHERE id = ?')
           ->execute([(int)$existing['id']]);
    } else {
        $subject = 'New message from ' . $sender['username'] . ' on ' . get_setting('site_name', 'GameNight');
        try {
            $db->prepare("INSERT INTO user_notifications (user_id, event_id, notify_type, subject, body, link)
                          VALUES (?, NULL, 'dm', ?, ?, ?)")
               ->execute([$rid, $subject, mb_substr(trim($body), 0, 120), $link]);
        } catch (Throwable $e) { /* inbox is best-effort */ }
    }
}

/**
 * Cron sweep (every 5 min): email/SMS a DM alert ONLY for messages that have
 * sat unread for 10+ minutes — anyone who saw the message on the site (thread,
 * badge, bell) never gets an email or text at all. Per conversation side:
 *   - at least one unread incoming message older than 10 min that arrived
 *     AFTER the last outbound alert (never re-alert the same messages), and
 *   - the last outbound alert is 30+ min old (floor between alerts).
 * One link-only alert per side per sweep; last_alert_at is stamped first so a
 * send failure can't loop into spam. Returns alerts sent.
 */
function dm_alert_drain(PDO $db): int {
    if (get_setting('notifications_enabled', '0') !== '1') return 0;
    $sent = 0;
    foreach ([['a', 'b'], ['b', 'a']] as [$me, $them]) {
        $rows = $db->query(
            "SELECT c.id AS conv_id, c.user_{$me}_id AS recip_id, c.user_{$them}_id AS sender_id,
                    (SELECT COUNT(*) FROM dm_messages m WHERE m.conversation_id = c.id
                       AND m.sender_id = c.user_{$them}_id AND m.read_at IS NULL
                       AND m.id > c.{$me}_cleared_before_id) AS unread_count
             FROM dm_conversations c
             WHERE EXISTS (SELECT 1 FROM dm_messages m WHERE m.conversation_id = c.id
                             AND m.sender_id = c.user_{$them}_id
                             AND m.read_at IS NULL
                             AND m.id > c.{$me}_cleared_before_id
                             AND m.created_at < datetime('now', '-10 minutes')
                             AND m.created_at > COALESCE(c.{$me}_last_alert_at, '1970-01-01'))
               AND (c.{$me}_last_alert_at IS NULL OR c.{$me}_last_alert_at < datetime('now', '-30 minutes'))"
        )->fetchAll();
        foreach ($rows as $r) {
            $db->prepare("UPDATE dm_conversations SET {$me}_last_alert_at = CURRENT_TIMESTAMP WHERE id = ?")
               ->execute([(int)$r['conv_id']]);
            $s = $db->prepare('SELECT id, username FROM users WHERE id = ?');
            $s->execute([(int)$r['sender_id']]);
            $senderRow = $s->fetch();
            if (!$senderRow) continue;
            _dm_send_outbound($db, $senderRow, (int)$r['recip_id'], (int)$r['unread_count']);
            $sent++;
        }
    }
    return $sent;
}

/**
 * Outbound (email/SMS/WhatsApp) leg of an unseen-DM alert. Link-only in
 * SMS/WhatsApp by design — the SMS webhook parses replies as RSVPs, so never
 * invite a text reply and never include message content.
 */
function _dm_send_outbound(PDO $db, array $sender, int $recipient_id, int $unread_count): void {
    if (get_setting('notifications_enabled', '0') !== '1') return;
    $stmt = $db->prepare('SELECT username, email, phone, preferred_contact, notify_prefs FROM users WHERE id = ?');
    $stmt->execute([$recipient_id]);
    $u = $stmt->fetch();
    if (!$u || !user_notify_pref_enabled($u, 'dm')) return;
    $site_name = get_setting('site_name', 'GameNight');
    $threadUrl = get_site_url() . '/message_thread.php?user=' . (int)$sender['id'];
    $subject   = 'New message from ' . $sender['username'] . ' on ' . $site_name;
    $lead      = $unread_count > 1
               ? "You have $unread_count unread messages from {$sender['username']} on $site_name"
               : "{$sender['username']} sent you a message on $site_name";
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
