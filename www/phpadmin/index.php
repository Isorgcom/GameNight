<?php
// Gate: require GameNight admin session
require_once __DIR__ . '/../auth.php';
session_start_safe();
$user = current_user();
if (!$user || $user['role'] !== 'admin') {
    header('Location: /login.php?redirect=' . urlencode('/phpadmin/'));
    exit;
}

// Hand off to pla-ng
header('Location: /phpadmin/phpliteadmin.php');
exit;
