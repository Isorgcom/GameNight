<?php
/**
 * Per-user personal post hiding (home feed only).
 * Actions: hide / unhide. JSON responses. A user can only hide posts they can see.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_posts.php';

header('Content-Type: application/json');

$current = current_user();
if (!$current) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not authenticated']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}
if (!csrf_verify()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

$db      = get_db();
$uid     = (int)$current['id'];
$isAdmin = ($current['role'] ?? '') === 'admin';
$action  = $_POST['action'] ?? '';
$post_id = (int)($_POST['post_id'] ?? 0);

if ($post_id <= 0) {
    echo json_encode(['ok' => false, 'error' => 'post_id required']);
    exit;
}

if ($action === 'hide') {
    // Only allow hiding a post the user is actually allowed to see.
    $stmt = $db->prepare('SELECT id, league_id, hidden, is_rules_post, created_at FROM posts WHERE id = ?');
    $stmt->execute([$post_id]);
    $post = $stmt->fetch();
    if (!$post || !post_is_visible_to($db, $post, $uid, $isAdmin)) {
        echo json_encode(['ok' => false, 'error' => 'Post not found']);
        exit;
    }
    $db->prepare('INSERT OR IGNORE INTO user_post_hidden (user_id, post_id) VALUES (?, ?)')
       ->execute([$uid, $post_id]);
    echo json_encode(['ok' => true, 'hidden' => 1]);
    exit;
}

if ($action === 'unhide') {
    $db->prepare('DELETE FROM user_post_hidden WHERE user_id = ? AND post_id = ?')
       ->execute([$uid, $post_id]);
    echo json_encode(['ok' => true, 'hidden' => 0]);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Unknown action']);
