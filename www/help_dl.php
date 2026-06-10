<?php
/**
 * Help-bubble user endpoint (POST → JSON).
 *
 * Only one action: a logged-in user dismissing the help bubble for a screen.
 * Resetting dismissals lives on /settings.php. Admin tip CRUD lives on
 * /admin_help_dl.php.
 */
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json');

$current = current_user();
if (!$current) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not signed in.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid request.']);
    exit;
}

$action = $_POST['action'] ?? '';
$screen = trim($_POST['screen'] ?? '');

if ($action === 'dismiss' && array_key_exists($screen, HELP_SCREENS)) {
    help_dismiss_screen((int)$current['id'], $screen);
    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Unknown action.']);
