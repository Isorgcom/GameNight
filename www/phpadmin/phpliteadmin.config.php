<?php
// Defense in depth: enforce GameNight admin auth here too. phpliteadmin.php
// require()s this config before it touches the database, and this runs inside
// PHP regardless of the webserver — so access no longer depends SOLELY on the
// .htaccess `auto_prepend_file` gate, which silently fails under PHP-FPM or if
// AllowOverride stops honoring php_value (which would otherwise expose the full
// DB console with no auth). The normal path (prepended _gate.php) already
// passed an admin here, so this is a no-op for legitimate admins.
require_once __DIR__ . '/../auth.php';
session_start_safe();
$__pla_user = current_user();
if (!$__pla_user || ($__pla_user['role'] ?? '') !== 'admin') {
    header('Location: /login.php?redirect=' . urlencode($_SERVER['REQUEST_URI'] ?? '/phpadmin/'));
    exit;
}

// Disable pla-ng's own login — GameNight admin auth gate handles access control
$password = '';

// Pull DB_PATH from the app config (same source as db.php)
if (file_exists('/var/config/config.php')) {
    require_once '/var/config/config.php';
}
if (!defined('DB_PATH')) {
    define('DB_PATH', '/var/db/app.db');
}

// Point directly at the app database
$directory = false;
$databases = [
    ['path' => DB_PATH, 'name' => 'Game Night DB'],
];
