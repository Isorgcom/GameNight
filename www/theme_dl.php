<?php
// Saves the toolbar light/dark switch to the signed-in account (_nav.php,
// nav.js navToggleTheme). POST theme = light | dark | auto, with the CSRF
// token. "auto" (or anything else) clears it, so the page follows the device.
require_once __DIR__ . '/auth.php';
header('Content-Type: application/json');
$user = current_user();
if (!$user) { http_response_code(401); echo json_encode(['ok' => false, 'error' => 'Not signed in']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
    http_response_code(400); echo json_encode(['ok' => false, 'error' => 'Invalid request']); exit;
}
$t = $_POST['theme'] ?? '';
$theme = in_array($t, ['light', 'dark'], true) ? $t : null;
get_db()->prepare('UPDATE users SET theme = ? WHERE id = ?')->execute([$theme, (int)$user['id']]);
echo json_encode(['ok' => true, 'theme' => $theme ?? 'auto']);
