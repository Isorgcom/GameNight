<?php
// Pull DB_PATH from the app config (same source as db.php)
if (file_exists('/var/config/config.php')) {
    require_once '/var/config/config.php';
}
if (!defined('DB_PATH')) {
    define('DB_PATH', '/var/db/app.db');
}

function adminer_object() {
    class AdminerAutoLogin extends Adminer {
        public function login($login, $password) {
            return true;
        }
        public function credentials() {
            return [DB_PATH, '', ''];
        }
        public function driver() {
            return 'sqlite';
        }
        public function permanentLogin($create = false) {
            return 'gamenight-phpadmin-key';
        }
        public function database() {
            return DB_PATH;
        }
        // Replace the login form with a self-submitting auto-login form
        public function loginForm() {
            $db  = htmlspecialchars(DB_PATH);
            echo "<form method='post' id='autologin'>
<input type='hidden' name='auth[driver]' value='sqlite'>
<input type='hidden' name='auth[server]' value='$db'>
<input type='hidden' name='auth[username]' value=''>
<input type='hidden' name='auth[password]' value=''>
<input type='hidden' name='auth[db]' value='$db'>
</form>
<script>document.getElementById('autologin').submit();</script>";
        }
    }
    return new AdminerAutoLogin();
}

require __DIR__ . '/adminer-src.php';
