<?php
/**
 * Send to TV: the TV's poll. GET ?action=poll&s=<secret>.
 *
 * Answers by the SECRET only tv.php's own browser holds, never by the code
 * on the screen (see _tv_pair.php). One-shot: the row is deleted the moment
 * its link is handed over.
 *
 * GET ?action=new mints a fresh code for a TV whose code ran out, so the page
 * can swap it in place: a reload would drop the full screen a press of OK
 * bought. It costs what loading /tv costs, which needs no login either, and
 * shares its per-address hourly cap (429 past it; the page keeps the old
 * code up and asks again later).
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_tv_pair.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');

$db = get_db();
$action = $_GET['action'] ?? '';
if ($action === 'new') {
    if (tv_pair_mint_limited($db)) { http_response_code(429); echo json_encode(['ok' => false, 'limited' => true]); exit; }
    echo json_encode(['ok' => true] + tv_pair_new($db));
    exit;
}
if ($action !== 'poll') { http_response_code(400); echo json_encode(['ok' => false]); exit; }

$secret = (string)($_GET['s'] ?? '');
if (!preg_match('/^[0-9a-f]{32}$/', $secret)) { http_response_code(400); echo json_encode(['ok' => false]); exit; }

$q = $db->prepare("SELECT id, target, expires_at < datetime('now') AS expired FROM tv_pairings WHERE secret = ?");
$q->execute([$secret]);
$row = $q->fetch();
if (!$row || (!$row['target'] && $row['expired'])) {
    echo json_encode(['ok' => true, 'expired' => true]);
    exit;
}
if ($row['target']) {
    $db->prepare('DELETE FROM tv_pairings WHERE id = ?')->execute([(int)$row['id']]);
    echo json_encode(['ok' => true, 'url' => $row['target']]);
    exit;
}
echo json_encode(['ok' => true, 'waiting' => true]);
