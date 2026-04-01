<?php
// ============================================================
// EXAMPLE configuration — copy to config.php and fill in values.
// config.php is gitignored; this file is safe to commit.
// ============================================================

// ── Database ─────────────────────────────────────────────────
define('DB_PATH', '/var/db/app.db');

// ── SMTP / Email ─────────────────────────────────────────────
// When these constants are defined, mail settings in the admin
// UI become read-only. Remove or comment them out to manage
// email settings through Site Settings → Email instead.
//
// define('SMTP_HOST',       'smtp.example.com');
// define('SMTP_PORT',       587);
// define('SMTP_ENCRYPTION', 'tls');   // 'tls', 'ssl', or 'none'
// define('SMTP_USER',       'you@example.com');
// define('SMTP_PASS',       'your-password');
// define('SMTP_FROM',       'no-reply@example.com');
// define('SMTP_FROM_NAME',  'My Site');
