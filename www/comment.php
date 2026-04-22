<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_posts.php';

session_start_safe();

$user = current_user();
if (!$user) {
    header('Location: /login.php');
    exit;
}

// Validate redirect — only allow relative paths
$redirect = $_POST['redirect'] ?? '/';
if (!preg_match('#^/[^/\\\\]*#', $redirect)) $redirect = '/';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
    header('Location: ' . $redirect);
    exit;
}

$db     = get_db();
$action = $_POST['action'] ?? '';

if ($action === 'add') {
    $type       = in_array($_POST['type'] ?? '', ['post', 'event']) ? $_POST['type'] : null;
    $content_id = (int)($_POST['content_id'] ?? 0);
    $body       = mb_substr(strip_tags(trim($_POST['body'] ?? '')), 0, 2000);

    // Visibility check: commenting on a league post requires league membership.
    // Global admin posts (league_id IS NULL) remain open to all logged-in users.
    if ($type === 'post' && $content_id > 0) {
        $pStmt = $db->prepare('SELECT league_id, hidden, is_rules_post, created_at FROM posts WHERE id = ?');
        $pStmt->execute([$content_id]);
        $pRow = $pStmt->fetch();
        if (!$pRow || !post_is_visible_to($db, $pRow, (int)$user['id'], $user['role'] === 'admin')) {
            http_response_code(403);
            header('Location: ' . $redirect);
            exit;
        }
    }

    if ($type && $content_id > 0 && $body !== '') {
        $db->prepare('INSERT INTO comments (type, content_id, user_id, body) VALUES (?, ?, ?, ?)')
           ->execute([$type, $content_id, $user['id'], $body]);
        $new_id = (int)$db->lastInsertId(); // capture before db_log_activity overwrites it
        db_log_activity($user['id'], "commented on $type id $content_id");

        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
            $row    = $db->prepare('SELECT c.id, c.user_id, c.body, c.created_at, u.username FROM comments c JOIN users u ON u.id=c.user_id WHERE c.id=?');
            $row->execute([$new_id]);
            header('Content-Type: application/json');
            echo json_encode(['ok' => true, 'comment' => $row->fetch()]);
            exit;
        }
    }
} elseif ($action === 'edit') {
    $comment_id = (int)($_POST['comment_id'] ?? 0);
    $body       = mb_substr(strip_tags(trim($_POST['body'] ?? '')), 0, 2000);
    $saved      = false;

    if ($comment_id > 0 && $body !== '') {
        $stmt = $db->prepare('SELECT user_id FROM comments WHERE id = ?');
        $stmt->execute([$comment_id]);
        $row = $stmt->fetch();
        if ($row && ($row['user_id'] == $user['id'] || $user['role'] === 'admin')) {
            $db->prepare('UPDATE comments SET body = ? WHERE id = ?')->execute([$body, $comment_id]);
            db_log_activity($user['id'], "edited comment $comment_id");
            $saved = true;
        }
    }
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
        header('Content-Type: application/json');
        echo json_encode(['ok' => $saved, 'body' => $saved ? $body : '']);
        exit;
    }
} elseif ($action === 'delete') {
    $comment_id = (int)($_POST['comment_id'] ?? 0);
    $deleted    = false;
    if ($comment_id > 0) {
        $stmt = $db->prepare('SELECT user_id FROM comments WHERE id = ?');
        $stmt->execute([$comment_id]);
        $row = $stmt->fetch();
        if ($row && ($row['user_id'] == $user['id'] || $user['role'] === 'admin')) {
            $db->prepare('DELETE FROM comments WHERE id = ?')->execute([$comment_id]);
            db_log_activity($user['id'], "deleted comment $comment_id");
            $deleted = true;
        }
    }
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
        header('Content-Type: application/json');
        echo json_encode(['ok' => $deleted]);
        exit;
    }
} elseif ($action === 'bulk_delete') {
    if ($user['role'] === 'admin') {
        $raw = json_decode($_POST['comment_ids'] ?? '[]', true);
        if (is_array($raw) && !empty($raw)) {
            $ids = array_values(array_filter(array_map('intval', $raw), fn($id) => $id > 0));
            if (!empty($ids)) {
                $ph = implode(',', array_fill(0, count($ids), '?'));
                $db->prepare("DELETE FROM comments WHERE id IN ($ph)")->execute($ids);
                db_log_activity($user['id'], "bulk deleted " . count($ids) . " comment(s)");
            }
        }
    }
}

header('Location: ' . $redirect);
exit;
