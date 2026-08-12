<?php
require_once __DIR__ . '/auth.php';

// Any logged-in user may upload (league managers' post images, ticket
// screenshots, help posts). MIME sniffing + the size cap below still apply,
// plus a per-user daily cap counted from the activity log.
$current = require_login();

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

// Must actually decode as an image, not merely start with the right magic bytes
// — rejects a crafted file that sniffs as an image type but is not a real one.
if (@getimagesize($file['tmp_name']) === false) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'That file is not a readable image.']);
    exit;
}

// 8 MB limit
if ($file['size'] > 8 * 1024 * 1024) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'File too large (max 8 MB).']);
    exit;
}

// Per-user daily cap (admins exempt): every successful upload logs
// "uploaded image: <name>", so the activity log is the counter.
if ($current['role'] !== 'admin') {
    $capQ = get_db()->prepare("SELECT COUNT(*) FROM activity_log
                               WHERE user_id = ? AND action LIKE 'uploaded image:%'
                                 AND created_at > datetime('now', '-1 day')");
    $capQ->execute([(int)$current['id']]);
    if ((int)$capQ->fetchColumn() >= MAX_UPLOADS_PER_DAY) {
        http_response_code(429);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Daily upload limit reached — try again tomorrow.']);
        exit;
    }
}

// Per-feature subfolder. Whitelisted (never free-form, so the param can't
// traverse), so each feature's images live apart instead of one flat dump:
// easier to reason about, sweep, and back up. An absent/unknown feature keeps
// the historical flat /uploads/ for backward compatibility.
$features = ['avatars', 'posts', 'tickets'];
$feature = (isset($_POST['feature']) && in_array($_POST['feature'], $features, true)) ? $_POST['feature'] : '';
$sub = $feature !== '' ? $feature . '/' : '';

$uploadDir = __DIR__ . '/uploads/' . $sub;
if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Storage unavailable.']);
    exit;
}

// Owner-keyed name (u<id>_<random>) inside a namespaced folder, so provenance
// is visible from the filename. The flat legacy path keeps its bare hash name.
$rand = bin2hex(random_bytes(16));
$name = ($feature !== '' ? 'u' . (int)$current['id'] . '_' : '') . $rand . '.' . $exts[$mime];
$dest = $uploadDir . $name;

if (!move_uploaded_file($file['tmp_name'], $dest)) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Failed to save file.']);
    exit;
}

db_log_activity($current['id'], "uploaded image: $sub$name");

header('Content-Type: application/json');
echo json_encode(['url' => '/uploads/' . $sub . $name]);
