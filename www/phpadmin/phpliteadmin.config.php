<?php
// Disable phpLiteAdmin's own login — GameNight admin auth gate handles access control
$password = '';

// Pull DB_PATH from the app config (same source as db.php)
if (file_exists('/var/config/config.php')) {
    require_once '/var/config/config.php';
}
if (!defined('DB_PATH')) {
    define('DB_PATH', '/var/db/app.db'); // fallback matches db.php
}

// Point directly at the app database
$directory = false;
$databases = [
    ['path' => DB_PATH, 'name' => 'Game Night DB'],
];
