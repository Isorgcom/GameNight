<?php
// Pull DB_PATH from the app config (same source as db.php)
if (file_exists('/var/config/config.php')) {
    require_once '/var/config/config.php';
}
if (!defined('DB_PATH')) {
    define('DB_PATH', '/var/db/app.db');
}

// Adminer auto-login: bypass login form and connect directly to the SQLite DB
function adminer_object() {
    class AdminerAutoLogin extends Adminer {
        public function login($login, $password) {
            return true;
        }
        public function credentials() {
            return ['', DB_PATH, '', ''];
        }
        public function database() {
            return DB_PATH;
        }
    }
    return new AdminerAutoLogin();
}

// Load the downloaded Adminer source
require __DIR__ . '/adminer-src.php';
