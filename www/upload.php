<?php
require_once __DIR__ . '/auth.php';

$current = require_login();
if ($current['role'] !== 'admin') {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Access denied.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}

if (!csrf_verify()) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid request token.']);
    exit;
}

// Accept both our direct upload format ($_FILES['image']) and
// Jodit's default array format ($_FILES['files'][0])
$file = null;
if (!empty($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
    $file = $_FILES['image'];
} elseif (!empty($_FILES['files']['tmp_name'])) {
    $names = $_FILES['files']['tmp_name'];
    $idx   = is_array($names) ? array_key_first($names) : null;
    if ($idx !== null && $_FILES['files']['error'][$idx] === UPLOAD_ERR_OK) {
        $file = [
            'name'     => $_FILES['files']['name'][$idx],
            'tmp_name' => $_FILES['files']['tmp_name'][$idx],
            'error'    => $_FILES['files']['error'][$idx],
            'size'     => $_FILES['files']['size'][$idx],
        ];
    }
}

if (!$file) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'No file uploaded.']);
    exit;
}

// Validate MIME by reading the actual file bytes (not trusting the browser)
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime  = $finfo->file($file['tmp_name']);
$exts  = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/gif'  => 'gif',
    'image/webp' => 'webp',
];
if (!isset($exts[$mime])) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Only JPEG, PNG, GIF, and WebP images are allowed.']);
    exit;
}

// 8 MB limit
if ($file['size'] > 8 * 1024 * 1024) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'File too large (max 8 MB).']);
    exit;
}

$uploadDir = __DIR__ . '/uploads/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$name = bin2hex(random_bytes(16)) . '.' . $exts[$mime];
$dest = $uploadDir . $name;

if (!move_uploaded_file($file['tmp_name'], $dest)) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Failed to save file.']);
    exit;
}

db_log_activity($current['id'], "uploaded image: $name");

header('Content-Type: application/json');
echo json_encode(['url' => '/uploads/' . $name]);
