<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/sms.php';
require_once __DIR__ . '/_sms_conversations.php';
header('Content-Type: application/json');

$current = require_login();
$db      = get_db();
$uid     = (int)$current['id'];
$isAdmin = $current['role'] === 'admin';

function fail(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}
function ok(array $extra = []): void {
    echo json_encode(array_merge(['ok' => true], $extra));
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// ── Thread poll (GET): messages after an id ──────────────────────────────────
if ($action === 'thread') {
    $event = (int)($_GET['event'] ?? 0);
    $phone = preg_match('/^\d{10}$/', $_GET['phone'] ?? '') ? $_GET['phone'] : '';
    $after = (int)($_GET['after'] ?? 0);
    if ($event <= 0 || $phone === '') fail('Bad request');
    if (!$isAdmin && !can_manage_event($db, $event, $uid, false)) fail('Access denied', 403);

    $q = $db->prepare("SELECT id, direction, body, status, error, created_at FROM sms_log
                       WHERE event_id = ? AND phone_digits = ? AND id > ?
                         AND is_conversation = 1
                       ORDER BY id ASC LIMIT 100");
    $q->execute([$event, $phone, $after]);
    $msgs = array_map(static fn ($m) => [
        'id' => (int)$m['id'], 'direction' => $m['direction'], 'body' => $m['body'],
        'status' => $m['status'], 'error' => $m['error'], 'created_at' => $m['created_at'],
    ], $q->fetchAll());
    ok(['messages' => $msgs]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('POST required', 405);
if (!csrf_verify()) fail('Invalid CSRF token', 403);

// ── Host reply: goes out over the sender's channel ───────────────────────────
if ($action === 'send') {
    $event = (int)($_POST['event'] ?? 0);
    $phone = preg_match('/^\d{10}$/', $_POST['phone'] ?? '') ? $_POST['phone'] : '';
    $body  = trim((string)($_POST['body'] ?? ''));
    if ($event <= 0 || $phone === '' || $body === '') fail('Bad request');
    if (mb_strlen($body) > 1000) fail('Message too long (1000 characters max).');
    if (!$isAdmin && !can_manage_event($db, $event, $uid, false)) fail('Access denied', 403);

    // Simple abuse guard: cap outbound to one phone at 30/hour.
    $rl = $db->prepare("SELECT COUNT(*) FROM sms_log
                        WHERE direction = 'outbound' AND phone_digits = ?
                          AND created_at > datetime('now', '-1 hour')");
    $rl->execute([$phone]);
    if ((int)$rl->fetchColumn() >= 30) fail('Rate limit reached for this number (30 messages/hour).', 429);

    // Recipient username for log attribution: latest known name on this thread.
    $nq = $db->prepare("SELECT username FROM sms_log
                        WHERE phone_digits = ? AND username IS NOT NULL AND username != ''
                        ORDER BY id DESC LIMIT 1");
    $nq->execute([$phone]);
    $recip_username = $nq->fetchColumn() ?: null;

    $before_id = (int)$db->query('SELECT COALESCE(MAX(id), 0) FROM sms_log')->fetchColumn();

    $channel = sms_conv_channel($db, $phone);
    notif_log_context($event, $recip_username ?: null);
    if ($channel === 'whatsapp') {
        $err = send_whatsapp($phone, $body);
    } else {
        // No opt-out footer on conversational replies (admin-reply precedent).
        $err = send_sms($phone, $body, false);
    }
    notif_log_context(null, null);

    // Some failures (missing config, invalid number) return before anything is
    // logged — only rows newer than $before_id are really this send.
    $mq = $db->prepare("SELECT id, direction, body, status, error, created_at FROM sms_log
                        WHERE phone_digits = ? AND direction = 'outbound' AND id > ?
                        ORDER BY id DESC LIMIT 1");
    $mq->execute([$phone, $before_id]);
    $m = $mq->fetch();
    if (!$m) fail($err ?? 'Send failed and was not logged.');

    sms_conv_mark($db, (int)$m['id']);
    sms_conv_bind($db, $phone, $event, $recip_username ?: null);
    db_log_activity($uid, "conversation reply to $phone for event id: $event");
    $msg = ['id' => (int)$m['id'], 'direction' => $m['direction'], 'body' => $m['body'],
            'status' => $m['status'], 'error' => $m['error'], 'created_at' => $m['created_at']];
    if ($err !== null) ok(['message' => $msg, 'warning' => $err]);
    ok(['message' => $msg]);
}

// ── Admin: claim unattributed inbound rows for an event ──────────────────────
if ($action === 'assign') {
    if (!$isAdmin) fail('Access denied', 403);
    $event = (int)($_POST['event'] ?? 0);
    $phone = preg_match('/^\d{10}$/', $_POST['phone'] ?? '') ? $_POST['phone'] : '';
    if ($event <= 0 || $phone === '') fail('Bad request');
    $eq = $db->prepare('SELECT COUNT(*) FROM events WHERE id = ?');
    $eq->execute([$event]);
    if (!(int)$eq->fetchColumn()) fail('No such event');

    $upd = $db->prepare("UPDATE sms_log SET event_id = ?, is_conversation = 1
                         WHERE phone_digits = ? AND event_id IS NULL AND direction = 'inbound'");
    $upd->execute([$event, $phone]);
    sms_conv_bind($db, $phone, $event, null);
    db_log_activity($uid, "assigned SMS conversation $phone to event id: $event");
    ok(['assigned' => $upd->rowCount()]);
}

fail('Unknown action');
