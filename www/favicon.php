<?php
require_once __DIR__ . '/db.php';
$path = get_setting('banner_path', '');
if ($path === '') {
    http_response_code(404);
    exit;
}
// Redirect to the actual uploaded icon file
header('Cache-Control: public, max-age=86400');
header('Location: ' . $path);
exit;
