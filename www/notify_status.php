<?php
/**
 * Lightweight read-only poll target for the site-wide badge updater in
 * _footer.php: current unread bell + DM counts for the logged-in user.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_dm.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');

$current = current_user();
if (!$current) {
    http_response_code(401);
    echo json_encode(['ok' => false]);
    exit;
}
$db  = get_db();
$uid = (int)$current['id'];

$bell = 0;
try {
    $q = $db->prepare('SELECT COUNT(*) FROM user_notifications WHERE user_id = ? AND is_read = 0');
    $q->execute([$uid]);
    $bell = (int)$q->fetchColumn();
} catch (Throwable $e) {}

$dm = 0;
try { $dm = dm_unread_count($db, $uid); } catch (Throwable $e) {}

echo json_encode(['ok' => true, 'bell' => $bell, 'dm' => $dm]);
