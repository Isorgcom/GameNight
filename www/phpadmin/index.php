<?php
// Gate: require GameNight admin session
require_once __DIR__ . '/../auth.php';
session_start_safe();
$user = current_user();
if (!$user || $user['role'] !== 'admin') {
    header('Location: /login.php');
    exit;
}

// Hand off to phpLiteAdmin
header('Location: /phpadmin/phpliteadmin.php');
exit;
