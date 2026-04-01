<?php
require_once __DIR__ . '/auth.php';
require_login();
header('Location: /admin_settings.php?tab=users');
exit;
