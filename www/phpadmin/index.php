<?php
// Gate: require GameNight admin session
require_once __DIR__ . '/../auth.php';
session_start_safe();
$user = current_user();
if (!$user || $user['role'] !== 'admin') {
    header('Location: /login.php');
    exit;
}

// Auto-connect to the app SQLite DB on first load
if (!isset($_GET['sqlite']) && !isset($_GET['pgsql']) && !isset($_GET['mysql'])) {
    header('Location: /phpadmin/?sqlite=&db=/var/db/app.db');
    exit;
}

// Adminer customisation: skip Adminer's own login (already authed above)
function adminer_object() {
    class AdminerGate extends Adminer {
        function login($login, $password) { return true; }
        function name() { return 'Game Night DB'; }
    }
    return new AdminerGate();
}

include __DIR__ . '/adminer.php';
