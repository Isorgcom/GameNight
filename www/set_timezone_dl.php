<?php
/*
 * set_timezone_dl.php — silent, one-time timezone backfill from the browser.
 *
 * Called by a small script in _footer.php for logged-in users who have no
 * timezone set yet. It stores the browser-detected IANA zone, but ONLY when
 * users.timezone is still empty, so an explicit choice made in Settings is
 * never overwritten.
 */
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json');

$user = current_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'auth']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'bad_request']);
    exit;
}

$tz = trim($_POST['timezone'] ?? '');
if ($tz === '' || !in_array($tz, DateTimeZone::listIdentifiers(), true)) {
    echo json_encode(['ok' => false, 'error' => 'invalid_timezone']);
    exit;
}

// Backfill only — never clobber an existing/explicit timezone.
$db   = get_db();
$stmt = $db->prepare("UPDATE users SET timezone = ? WHERE id = ? AND (timezone IS NULL OR timezone = '')");
$stmt->execute([$tz, (int)$user['id']]);
$updated = $stmt->rowCount();

if ($updated > 0) {
    db_log_activity((int)$user['id'], "timezone auto-set from browser ($tz)");
}

echo json_encode(['ok' => true, 'updated' => $updated]);
