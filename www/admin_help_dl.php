<?php
/**
 * Admin help-bubble CRUD (POST → JSON).
 *
 * Manages the `help_bubbles` rows that drive the in-app "ghost bubble" hints.
 * Page shell lives in admin_help.php; the user-facing dismiss endpoint is
 * help_dl.php. Tip bodies are stored as plain text (escaped on display).
 */
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json');

$current = require_login();
if (($current['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Access denied.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid request token.']);
    exit;
}

$db     = get_db();
$action = $_POST['action'] ?? '';
$screen = trim($_POST['screen'] ?? '');

function help_tips_json(PDO $db, string $screen): array {
    $stmt = $db->prepare(
        'SELECT id, screen_key, title, body, anchor_selector, bubble_index, always_show, sort_order, enabled
         FROM help_bubbles WHERE screen_key = ? ORDER BY sort_order, id'
    );
    $stmt->execute([$screen]);
    return $stmt->fetchAll();
}

// Every action is screen-scoped and the screen must be a known one.
if (!array_key_exists($screen, HELP_SCREENS)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Unknown screen.']);
    exit;
}

if ($action === 'list') {
    echo json_encode(['ok' => true, 'tips' => help_tips_json($db, $screen)]);
    exit;
}

if ($action === 'create') {
    $title  = trim($_POST['title'] ?? '');
    $body   = trim($_POST['body'] ?? '');
    $anchor = trim($_POST['anchor_selector'] ?? '');
    $order  = (int)($_POST['sort_order'] ?? 0);
    $bidx   = (isset($_POST['bubble_index']) && $_POST['bubble_index'] !== '') ? (int)$_POST['bubble_index'] : null;
    $pinned = !empty($_POST['always_show']) ? 1 : 0;
    if ($body === '') {
        echo json_encode(['ok' => false, 'error' => 'Tip text is required.']);
        exit;
    }
    // Default new tips to the end of the list when no order given.
    if (!isset($_POST['sort_order']) || $_POST['sort_order'] === '') {
        $maxStmt = $db->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM help_bubbles WHERE screen_key = ?');
        $maxStmt->execute([$screen]);
        $order = (int)$maxStmt->fetchColumn();
    }
    $db->prepare('INSERT INTO help_bubbles (screen_key, title, body, anchor_selector, bubble_index, always_show, sort_order, enabled) VALUES (?, ?, ?, ?, ?, ?, ?, 1)')
        ->execute([$screen, $title !== '' ? $title : null, $body, $anchor !== '' ? $anchor : null, $bidx, $pinned, $order]);
    db_log_activity((int)$current['id'], "added help tip for screen=$screen");
    echo json_encode(['ok' => true, 'tips' => help_tips_json($db, $screen)]);
    exit;
}

if ($action === 'update') {
    $id     = (int)($_POST['id'] ?? 0);
    $title  = trim($_POST['title'] ?? '');
    $body   = trim($_POST['body'] ?? '');
    $anchor = trim($_POST['anchor_selector'] ?? '');
    $order  = (int)($_POST['sort_order'] ?? 0);
    $bidx   = (isset($_POST['bubble_index']) && $_POST['bubble_index'] !== '') ? (int)$_POST['bubble_index'] : null;
    $pinned = !empty($_POST['always_show']) ? 1 : 0;
    if ($id <= 0 || $body === '') {
        echo json_encode(['ok' => false, 'error' => 'Tip text is required.']);
        exit;
    }
    $db->prepare('UPDATE help_bubbles SET title = ?, body = ?, anchor_selector = ?, bubble_index = ?, always_show = ?, sort_order = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND screen_key = ?')
        ->execute([$title !== '' ? $title : null, $body, $anchor !== '' ? $anchor : null, $bidx, $pinned, $order, $id, $screen]);
    db_log_activity((int)$current['id'], "edited help tip id=$id");
    echo json_encode(['ok' => true, 'tips' => help_tips_json($db, $screen)]);
    exit;
}

if ($action === 'toggle') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        $db->prepare('UPDATE help_bubbles SET enabled = CASE WHEN enabled = 1 THEN 0 ELSE 1 END, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND screen_key = ?')
            ->execute([$id, $screen]);
    }
    echo json_encode(['ok' => true, 'tips' => help_tips_json($db, $screen)]);
    exit;
}

if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        $db->prepare('DELETE FROM help_bubbles WHERE id = ? AND screen_key = ?')->execute([$id, $screen]);
        db_log_activity((int)$current['id'], "deleted help tip id=$id");
    }
    echo json_encode(['ok' => true, 'tips' => help_tips_json($db, $screen)]);
    exit;
}

if ($action === 'reorder') {
    // ids = comma-separated tip id order
    $ids = array_filter(array_map('intval', explode(',', $_POST['ids'] ?? '')));
    $pos = 1;
    $upd = $db->prepare('UPDATE help_bubbles SET sort_order = ? WHERE id = ? AND screen_key = ?');
    foreach ($ids as $id) {
        $upd->execute([$pos++, $id, $screen]);
    }
    echo json_encode(['ok' => true, 'tips' => help_tips_json($db, $screen)]);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Unknown action.']);
