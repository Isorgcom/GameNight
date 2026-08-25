<?php
require_once __DIR__ . '/db.php';
$path = get_setting('banner_path', '');
if ($path === '') {
    http_response_code(404);
    exit;
}
// Serve the uploaded icon inline rather than redirecting to it. Apache sits
// behind the proxy on plain HTTP, so mod_rewrite built the /favicon.ico ->
// /favicon.php hop as an absolute http:// Location: every Safari page load
// walked https -> http -> https before reaching the image, and WebKit's
// networking daemon retried that chain on a backoff loop. Reading the bytes
// here keeps the icon to a single same-scheme request.
$file = realpath(__DIR__ . $path);
$root = realpath(__DIR__ . '/uploads');
if ($file !== false && $root !== false
    && strncmp($file, $root . DIRECTORY_SEPARATOR, strlen($root) + 1) === 0
    && is_file($file) && is_readable($file)) {
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file);
    if (strncmp($mime, 'image/', 6) !== 0) $mime = 'application/octet-stream';
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($file));
    header('Cache-Control: public, max-age=86400');
    readfile($file);
    exit;
}
// banner_path points at a file that is gone: fall back to the relative
// redirect so a stale setting still resolves the way it always did.
header('Cache-Control: public, max-age=86400');
header('Location: ' . $path);
exit;
