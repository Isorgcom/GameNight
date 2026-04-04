<?php
// Pull DB_PATH from the app config (same source as db.php)
if (file_exists('/var/config/config.php')) {
    require_once '/var/config/config.php';
}
if (!defined('DB_PATH')) {
    define('DB_PATH', '/var/db/app.db');
}

// adminer_object() is called by Adminer after its own classes are loaded,
// so extending Adminer here is safe.
function adminer_object() {
    class AdminerAutoLogin extends Adminer {
        public function login($login, $password) {
            return true;
        }
        public function credentials() {
            // [server, username, password, database]
            return ['', DB_PATH, '', ''];
        }
        public function database() {
            return DB_PATH;
        }
    }
    return new AdminerAutoLogin();
}

// Load the downloaded Adminer source (calls adminer_object() internally)
require __DIR__ . '/adminer-src.php';
