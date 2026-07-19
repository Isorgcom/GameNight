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

$action   = $_POST['action'] ?? '';
$other_id = (int)($_POST['user_id'] ?? 0);

$other = null;
if ($other_id > 0) {
    $s = $db->prepare('SELECT id, username, role FROM users WHERE id = ?');
    $s->execute([$other_id]);
    $other = $s->fetch() ?: null;
}

switch ($action) {

    case 'send':
        if (!$other || $other_id === $uid) fail('Unknown recipient.');
        $body = trim((string)($_POST['body'] ?? ''));
        if ($body === '') fail('Message is empty.');
        if (mb_strlen($body) > 4000) fail('Message is too long (4000 character max).');
        if (!dm_can_send($db, $current, $other)) fail('You can only message people you share a league, event, or contact link with.', 403);

        // Rate caps
        $s = $db->prepare("SELECT COUNT(*) FROM dm_messages WHERE sender_id = ? AND created_at > datetime('now','-1 hour')");
        $s->execute([$uid]);
        if ((int)$s->fetchColumn() >= MAX_DM_MESSAGES_PER_HOUR) fail('Slow down — you have hit the hourly message limit.', 429);

        $conv = dm_conversation_for($db, $uid, $other_id);
        $isNew = !$conv;
        if ($isNew) {
            $s = $db->prepare("SELECT COUNT(*) FROM dm_conversations
                               WHERE created_at > datetime('now','-1 day')
                                 AND ((user_a_id = :me AND (SELECT sender_id FROM dm_messages m WHERE m.conversation_id = dm_conversations.id ORDER BY m.id LIMIT 1) = :me)
                                   OR (user_b_id = :me AND (SELECT sender_id FROM dm_messages m WHERE m.conversation_id = dm_conversations.id ORDER BY m.id LIMIT 1) = :me))");
            $s->execute([':me' => $uid]);
            if ((int)$s->fetchColumn() >= MAX_DM_NEW_CONVERSATIONS_PER_DAY) fail('You have started too many new conversations today.', 429);
            $conv = dm_get_or_create_conversation($db, $uid, $other_id);
        }

        $db->prepare('INSERT INTO dm_messages (conversation_id, sender_id, body) VALUES (?, ?, ?)')
           ->execute([(int)$conv['id'], $uid, $body]);
        $msg_id = (int)$db->lastInsertId();
        $db->prepare('UPDATE dm_conversations SET last_message_at = CURRENT_TIMESTAMP WHERE id = ?')
           ->execute([(int)$conv['id']]);

        dm_notify($db, $conv, $current, $other, $body);
        ok(['id' => $msg_id]);

    case 'clear':
        if (!$other) fail('Unknown conversation.');
        $conv = dm_conversation_for($db, $uid, $other_id);
        if (!$conv) fail('Unknown conversation.');
        $s = $db->prepare('SELECT COALESCE(MAX(id),0) FROM dm_messages WHERE conversation_id = ?');
        $s->execute([(int)$conv['id']]);
        $maxId = (int)$s->fetchColumn();
        $col = (int)$conv['user_a_id'] === $uid ? 'a_cleared_before_id' : 'b_cleared_before_id';
        $db->prepare("UPDATE dm_conversations SET $col = ? WHERE id = ?")
           ->execute([$maxId, (int)$conv['id']]);
        ok();

    case 'since':
        // Poll for messages newer than a given id in one thread; marks them
        // read and doubles as the presence heartbeat (suppresses alerts while
        // the recipient is watching the thread).
        if (!$other) fail('Unknown conversation.');
        $conv = dm_conversation_for($db, $uid, $other_id);
        if (!$conv) ok(['messages' => []]);
        dm_touch_seen($db, $conv, $uid);
        $after = (int)($_POST['after_id'] ?? 0);
        $mark  = max($after, dm_my_watermark($conv, $uid));
        $s = $db->prepare('SELECT id, sender_id, body, created_at FROM dm_messages
                           WHERE conversation_id = ? AND id > ? ORDER BY id');
        $s->execute([(int)$conv['id'], $mark]);
        $rows = $s->fetchAll();
        if ($rows) {
            $db->prepare('UPDATE dm_messages SET read_at = CURRENT_TIMESTAMP
                          WHERE conversation_id = ? AND sender_id <> ? AND read_at IS NULL')
               ->execute([(int)$conv['id'], $uid]);
        }
        $viewer_tz = new DateTimeZone(display_timezone($uid));
        $out = [];
        foreach ($rows as $r) {
            $when = new DateTime($r['created_at'], new DateTimeZone('UTC'));
            $when->setTimezone($viewer_tz);
            $out[] = [
                'id'   => (int)$r['id'],
                'mine' => (int)$r['sender_id'] === $uid,
                'body' => (string)$r['body'],
                'when' => $when->format('M j g:ia'),
            ];
        }
        ok(['messages' => $out]);

    case 'list_status':
        // Cheap change-detector for the Messages list page: highest message id
        // across my conversations + my unread count. The page reloads when
        // either moves.
        $s = $db->prepare('SELECT COALESCE(MAX(m.id),0) FROM dm_messages m
                           JOIN dm_conversations c ON c.id = m.conversation_id
                           WHERE c.user_a_id = ? OR c.user_b_id = ?');
        $s->execute([$uid, $uid]);
        ok(['max_id' => (int)$s->fetchColumn(), 'unread' => dm_unread_count($db, $uid)]);

    default:
        fail('Unknown action.');
}
