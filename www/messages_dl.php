<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_dm.php';
require_once __DIR__ . '/_notifications.php';
header('Content-Type: application/json');

$current = require_login();
$db      = get_db();
$uid     = (int)$current['id'];

function fail(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}
function ok(array $extra = []): void {
    echo json_encode(array_merge(['ok' => true], $extra));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('POST required', 405);
if (!csrf_verify()) fail('Invalid CSRF token', 403);

$action = $_POST['action'] ?? '';

/**
 * Resolve the conversation this request addresses: conv_id directly (must be
 * a participant), or user_id for 1:1 (may not exist yet). Returns
 * [convRow|null, otherUserRow|null].
 */
function resolve_target(PDO $db, array $current, int $uid): array {
    $conv_id  = (int)($_POST['conv_id'] ?? 0);
    $other_id = (int)($_POST['user_id'] ?? 0);
    if ($conv_id > 0) {
        $conv = dm_conversation($db, $conv_id);
        if (!$conv) fail('Unknown conversation.', 404);
        // Event chats: refresh roster-driven membership before the access check,
        // so RSVP flips take effect on the next interaction.
        if (!empty($conv['event_id'])) dm_sync_event_chat($db, $conv);
        // Uniform 404 for any conversation you're not in (event chat or not) so
        // conversation IDs can't be enumerated by a 403-vs-404 difference. The
        // helpful "RSVP yes to join" message lives on message_thread.php?event=.
        if (!dm_participant($db, $conv_id, $uid)) fail('Unknown conversation.', 404);
        return [$conv, null];
    }
    if ($other_id > 0 && $other_id !== $uid) {
        $s = $db->prepare('SELECT id, username, role FROM users WHERE id = ?');
        $s->execute([$other_id]);
        $other = $s->fetch();
        if (!$other) fail('Unknown recipient.', 404);
        return [dm_conversation_for($db, $uid, $other_id), $other];
    }
    fail('Missing conversation.');
}

switch ($action) {

    case 'send': {
        [$conv, $other] = resolve_target($db, $current, $uid);
        $body = trim((string)($_POST['body'] ?? ''));
        if ($body === '') fail('Message is empty.');
        if (mb_strlen($body) > 4000) fail('Message is too long (4000 character max).');

        $s = $db->prepare("SELECT COUNT(*) FROM dm_messages WHERE sender_id = ? AND created_at > datetime('now','-1 hour')");
        $s->execute([$uid]);
        if ((int)$s->fetchColumn() >= MAX_DM_MESSAGES_PER_HOUR) fail('Slow down — you have hit the hourly message limit.', 429);

        if (!$conv) {
            // Fresh 1:1: scope + new-conversation cap
            if (!dm_can_send_pair($db, $current, $other)) {
                fail('You can only message people you share a league, event, or contact link with.', 403);
            }
            $s = $db->prepare("SELECT COUNT(*) FROM dm_conversations WHERE created_by = ? AND created_at > datetime('now','-1 day')");
            $s->execute([$uid]);
            if ((int)$s->fetchColumn() >= MAX_DM_NEW_CONVERSATIONS_PER_DAY) fail('You have started too many new conversations today.', 429);
            $conv = dm_get_or_create_conversation($db, $uid, (int)$other['id'], $uid);
        } elseif ($other !== null) {
            // Existing 1:1 addressed by user_id: ensure my participant row and
            // sendability (covers conversations the other side started).
            if (!dm_participant($db, (int)$conv['id'], $uid)) {
                if (!dm_can_send_pair($db, $current, $other)) fail('You can only message people you share a league, event, or contact link with.', 403);
                $conv = dm_get_or_create_conversation($db, $uid, (int)$other['id'], $uid);
            }
        }

        $db->prepare('INSERT INTO dm_messages (conversation_id, sender_id, body) VALUES (?, ?, ?)')
           ->execute([(int)$conv['id'], $uid, $body]);
        $msg_id = (int)$db->lastInsertId();
        $db->prepare('UPDATE dm_conversations SET last_message_at = CURRENT_TIMESTAMP WHERE id = ?')
           ->execute([(int)$conv['id']]);
        // My own read pointer follows my sends.
        $db->prepare('UPDATE dm_participants SET last_read_msg_id = ? WHERE conversation_id = ? AND user_id = ?')
           ->execute([$msg_id, (int)$conv['id'], $uid]);

        dm_notify($db, $conv, $current, $body);
        ok(['id' => $msg_id, 'conv_id' => (int)$conv['id']]);
    }

    case 'clear': {
        [$conv, $other] = resolve_target($db, $current, $uid);
        if (!$conv || !dm_participant($db, (int)$conv['id'], $uid)) fail('Unknown conversation.', 404);
        $s = $db->prepare('SELECT COALESCE(MAX(id),0) FROM dm_messages WHERE conversation_id = ?');
        $s->execute([(int)$conv['id']]);
        $db->prepare('UPDATE dm_participants SET cleared_before_id = ? WHERE conversation_id = ? AND user_id = ?')
           ->execute([(int)$s->fetchColumn(), (int)$conv['id'], $uid]);
        ok();
    }

    case 'leave': {
        [$conv, $other] = resolve_target($db, $current, $uid);
        if (!$conv || empty($conv['is_group'])) fail('Only group conversations can be left.');
        $me = dm_participant($db, (int)$conv['id'], $uid);
        if (!$me) fail('Unknown conversation.', 404);
        // Roster-driven members leave an event chat by changing their RSVP;
        // manually-added extras (not on the roster) may leave directly.
        if (!empty($conv['event_id']) && dm_event_chat_event_exists($db, (int)$conv['event_id']) && empty($me['manual_add'])) {
            fail('Event chat membership follows the event — change your RSVP to leave.');
        }
        $db->prepare('DELETE FROM dm_participants WHERE conversation_id = ? AND user_id = ?')
           ->execute([(int)$conv['id'], $uid]);
        // Last one out: remove the empty conversation (cascades messages).
        $s = $db->prepare('SELECT COUNT(*) FROM dm_participants WHERE conversation_id = ?');
        $s->execute([(int)$conv['id']]);
        if ((int)$s->fetchColumn() === 0) {
            $db->prepare('DELETE FROM dm_conversations WHERE id = ?')->execute([(int)$conv['id']]);
        }
        ok();
    }

    case 'create_group': {
        $title = trim((string)($_POST['title'] ?? ''));
        if (mb_strlen($title) > 60) fail('Group name is too long (60 character max).');
        $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)($_POST['user_ids'] ?? ''))), fn($v) => $v > 0 && $v !== $uid)));
        if (count($ids) < 2) fail('Pick at least two people for a group (message one person directly instead).');
        if (count($ids) + 1 > MAX_DM_GROUP_MEMBERS) fail('Groups are limited to ' . MAX_DM_GROUP_MEMBERS . ' members.');
        $s = $db->prepare("SELECT COUNT(*) FROM dm_conversations WHERE created_by = ? AND created_at > datetime('now','-1 day')");
        $s->execute([$uid]);
        if ((int)$s->fetchColumn() >= MAX_DM_NEW_CONVERSATIONS_PER_DAY) fail('You have started too many new conversations today.', 429);

        $uq = $db->prepare('SELECT id, username, role FROM users WHERE id = ?');
        foreach ($ids as $mid) {
            $uq->execute([$mid]);
            $m = $uq->fetch();
            if (!$m) fail('One of the selected users no longer exists.');
            if (!dm_can_initiate($db, $current, $m)) fail('You can only add people you share a league, event, or contact link with (' . $m['username'] . ').', 403);
        }
        $conv = dm_create_group($db, $uid, $ids, $title);
        ok(['conv_id' => (int)$conv['id']]);
    }

    case 'add_members': {
        [$conv, $other] = resolve_target($db, $current, $uid);
        if (!$conv) fail('Start the conversation before adding people.');
        if (!dm_participant($db, (int)$conv['id'], $uid)) fail('Unknown conversation.', 404);
        // Event-chat membership follows the event roster; only the host/managers
        // may add extras. (Regular groups let any member add — intended.)
        if (!empty($conv['event_id']) && dm_event_chat_event_exists($db, (int)$conv['event_id'])
            && !can_manage_event($db, (int)$conv['event_id'], $uid, $current['role'] === 'admin')) {
            fail('Only the event host can add people to an event chat.', 403);
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)($_POST['user_ids'] ?? ''))), fn($v) => $v > 0 && $v !== $uid)));
        if (!$ids) fail('Pick someone to add.');
        // Cap applies to user-made groups; event chats are bounded by their
        // (host-controlled) roster, so extras aren't capped.
        $count = count(dm_participants($db, (int)$conv['id']));
        if (empty($conv['event_id']) && $count + count($ids) > MAX_DM_GROUP_MEMBERS) fail('Groups are limited to ' . MAX_DM_GROUP_MEMBERS . ' members.');

        $uq = $db->prepare('SELECT id, username, role FROM users WHERE id = ?');
        $toAdd = [];
        foreach ($ids as $mid) {
            $uq->execute([$mid]);
            $m = $uq->fetch();
            if (!$m) fail('One of the selected users no longer exists.');
            if (!dm_can_initiate($db, $current, $m)) fail('You can only add people you share a league, event, or contact link with (' . $m['username'] . ').', 403);
            $toAdd[] = (int)$m['id'];
        }

        $tail = $db->prepare('SELECT COALESCE(MAX(id),0) FROM dm_messages WHERE conversation_id = ?');
        $tail->execute([(int)$conv['id']]);
        $tailId = (int)$tail->fetchColumn();

        $wasPair = empty($conv['is_group']);
        if ($wasPair) {
            // Adding a third person converts the 1:1 into a group. The old
            // pair_key is released so a fresh 1:1 between the two can start
            // later; the newcomer's cleared watermark hides the private pair
            // history — they only see messages from this point on.
            $db->prepare('UPDATE dm_conversations SET is_group = 1, pair_key = NULL WHERE id = ?')
               ->execute([(int)$conv['id']]);
        }
        // New members join read-at-tail with a cleared watermark, so they see
        // messages only from now on — never the pre-join backlog (matches the
        // 1:1->group conversion). manual_add=1 keeps them through roster sync.
        $ins = $db->prepare('INSERT OR IGNORE INTO dm_participants
                             (conversation_id, user_id, last_read_msg_id, cleared_before_id, manual_add)
                             VALUES (?, ?, ?, ?, 1)');
        foreach ($toAdd as $mid) {
            $ins->execute([(int)$conv['id'], $mid, $tailId, $tailId]);
        }
        ok(['conv_id' => (int)$conv['id']]);
    }

    case 'since': {
        // Poll for messages newer than a given id; marks them read and doubles
        // as the presence heartbeat.
        [$conv, $other] = resolve_target($db, $current, $uid);
        if (!$conv) ok(['messages' => []]);
        $me = dm_participant($db, (int)$conv['id'], $uid);
        if (!$me) fail('Unknown conversation.', 404);
        dm_touch_seen($db, (int)$conv['id'], $uid);
        $after = max((int)($_POST['after_id'] ?? 0), (int)$me['cleared_before_id']);
        $s = $db->prepare('SELECT m.id, m.sender_id, m.body, m.created_at, u.username
                           FROM dm_messages m JOIN users u ON u.id = m.sender_id
                           WHERE m.conversation_id = ? AND m.id > ? ORDER BY m.id');
        $s->execute([(int)$conv['id'], $after]);
        $rows = $s->fetchAll();
        if ($rows) dm_mark_read($db, (int)$conv['id'], $uid);
        $viewer_tz = new DateTimeZone(display_timezone($uid));
        $out = [];
        foreach ($rows as $r) {
            $when = new DateTime($r['created_at'], new DateTimeZone('UTC'));
            $when->setTimezone($viewer_tz);
            $out[] = [
                'id'     => (int)$r['id'],
                'mine'   => (int)$r['sender_id'] === $uid,
                'sender' => (string)$r['username'],
                'body'   => (string)$r['body'],
                'when'   => $when->format('M j g:ia'),
            ];
        }
        ok(['messages' => $out]);
    }

    case 'list_status': {
        // Cheap change-detector for the Messages list page.
        $s = $db->prepare('SELECT COALESCE(MAX(m.id),0) FROM dm_messages m
                           JOIN dm_participants p ON p.conversation_id = m.conversation_id
                           WHERE p.user_id = ?');
        $s->execute([$uid]);
        ok(['max_id' => (int)$s->fetchColumn(), 'unread' => dm_unread_count($db, $uid)]);
    }

    default:
        fail('Unknown action.');
}
