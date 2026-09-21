<?php
/**
 * FinalTable webhook receiver — the door a FinalTable server reports through.
 *
 * Every game made from here carries a per-game secret and this address. Each
 * delivery is a signed POST (HMAC-SHA256 over "<timestamp>.<body>", headers
 * X-FinalTable-Timestamp / -Signature / -Delivery / -Event) telling this side
 * the clock started, a level turned, somebody busted or re-entered, the host
 * paused, the game ended - or, every five minutes, that it is still there.
 *
 * Fail closed: the game is looked up from the body (the secret is per game),
 * the timestamp must be fresh, the signature must match, and only then is
 * anything read. A delivery seen before is answered 200 and dropped; one this
 * side fails to apply is answered 500 so FinalTable tries again.
 *
 * Deliberately never includes auth.php: no session, no CSRF, no CSP - the
 * caller is a server, not a browser (the sms_webhook.php shape).
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/_poker_helpers.php';
require_once __DIR__ . '/_finaltable.php';

header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo '{"ok":false,"error":"POST only"}'; exit; }
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 65536) { http_response_code(413); echo '{"ok":false,"error":"Too large"}'; exit; }

// php://input can be read once; everything below works from this string.
$body = file_get_contents('php://input');
if ($body === false || $body === '' || strlen($body) > 65536) { http_response_code(400); echo '{"ok":false,"error":"No body"}'; exit; }

$ts  = (string)($_SERVER['HTTP_X_FINALTABLE_TIMESTAMP'] ?? '');
$sig = (string)($_SERVER['HTTP_X_FINALTABLE_SIGNATURE'] ?? '');
// The timestamp is milliseconds since the epoch. Five minutes of skew either
// way; a retry carries a fresh one, so a delayed retry still passes.
if (!preg_match('/^\d{10,16}$/', $ts) || abs(time() - intdiv((int)$ts, 1000)) > 300) {
    http_response_code(401); echo '{"ok":false,"error":"Stale or missing timestamp"}'; exit;
}
if (!preg_match('/^sha256=[0-9a-f]{64}$/', $sig)) {
    http_response_code(401); echo '{"ok":false,"error":"Missing signature"}'; exit;
}

$d = json_decode($body, true);
if (!is_array($d)) { http_response_code(400); echo '{"ok":false,"error":"Not JSON"}'; exit; }
$gameId = (string)($d['game']['id'] ?? '');
$extId  = (string)($d['game']['external_id'] ?? '');
if ($gameId === '' || strlen($gameId) > 64) { http_response_code(400); echo '{"ok":false,"error":"No game id"}'; exit; }

$db = get_db();
$g = $db->prepare('SELECT * FROM finaltable_games WHERE game_id = ?');
$g->execute([$gameId]);
$game = $g->fetch();
if (!$game || ($extId !== '' && $extId !== (string)(int)$game['event_id'])) {
    // Nothing here by that id: the event was deleted, or the table was set up
    // again and this is a straggler for the old game. FinalTable retries for
    // a day, then gives up and says so in its own log. Honest, and cheap.
    http_response_code(404); echo '{"ok":false,"error":"No game by that id here."}'; exit;
}

// The secret is per game, so the lookup had to come first; nothing above
// trusted the body for anything but which row to check against.
$verified = false;
$secret = decrypt_value((string)$game['webhook_secret']);
if ($secret !== '') {
    $verified = hash_equals('sha256=' . hash_hmac('sha256', $ts . '.' . $body, $secret), $sig);
}
if (!$verified) {
    db_log_anon_activity("finaltable webhook refused: bad signature game=$gameId", 'warning');
    http_response_code(401); echo '{"ok":false,"error":"Bad signature"}'; exit;
}

$event      = (string)($d['event'] ?? '');
$deliveryId = $d['delivery_id'] ?? null;   // null on a heartbeat: tried once, never retried, nothing to recognise twice
if ($deliveryId !== null) {
    try {
        $db->prepare('INSERT INTO finaltable_deliveries (game_id, delivery_id, event) VALUES (?, ?, ?)')
           ->execute([$gameId, (int)$deliveryId, $event]);
    } catch (PDOException $e) {
        // The UNIQUE index is the lock: this delivery was handled already.
        echo '{"ok":true,"duplicate":true}'; exit;
    }
}

try {
    finaltable_apply_event($db, $game, $event, $d);
} catch (Throwable $e) {
    error_log('[GameNight] finaltable apply failed game=' . $gameId . ' event=' . $event . ': ' . $e->getMessage());
    if ($deliveryId !== null) {
        try { $db->prepare('DELETE FROM finaltable_deliveries WHERE game_id = ? AND delivery_id = ?')->execute([$gameId, (int)$deliveryId]); } catch (Throwable $e2) {}
    }
    http_response_code(500); echo '{"ok":false,"error":"Could not apply; try again"}'; exit;
}
echo '{"ok":true}';
