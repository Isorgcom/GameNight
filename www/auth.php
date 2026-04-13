<?php
require_once __DIR__ . '/db.php';

// ── Security headers (sent on every request) ──────────────────────────────────
header_remove('X-Powered-By');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
// CSP: allow inline scripts/styles (required by Jodit editor), block everything else external
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
// HSTS: enforce HTTPS for 1 year (only sent when already on HTTPS)
if ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

// ── Mobile detection (available to all pages) ────────────────────────────────
$_is_mobile = (bool) preg_match('/Mobile|Android|iPhone|iPad|iPod|CriOS|FxiOS/i', $_SERVER['HTTP_USER_AGENT'] ?? '');

function _is_https(): bool {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

function session_start_safe(): void {
    if (session_status() === PHP_SESSION_NONE) {
        // 8-hour server-side session lifetime so idle users within a browser
        // session don't get kicked out by PHP's default 24-minute GC.
        ini_set('session.gc_maxlifetime', '28800');
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => _is_https(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

// ── Persistent "Remember me" auth tokens ─────────────────────────────────────
// Two-layer auth: short PHP session for the active browser, plus an optional
// 30-day signed cookie that silently re-establishes the session after idle
// periods or browser restarts. Rotated on every use for theft detection.

const REMEMBER_COOKIE   = 'gn_remember';
const REMEMBER_LIFETIME = 2592000; // 30 days in seconds

function issue_remember_token(int $user_id): void {
    $raw     = bin2hex(random_bytes(32));
    $hash    = hash('sha256', $raw);
    $expires = date('Y-m-d H:i:s', time() + REMEMBER_LIFETIME);
    $ua      = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
    $ip      = $_SERVER['REMOTE_ADDR'] ?? '';

    $db = get_db();
    $db->prepare('INSERT INTO remember_tokens (user_id, token_hash, expires_at, user_agent, ip) VALUES (?, ?, ?, ?, ?)')
       ->execute([$user_id, $hash, $expires, $ua, $ip]);
    $row_id = (int)$db->lastInsertId();

    setcookie(REMEMBER_COOKIE, $row_id . ':' . $raw, [
        'expires'  => time() + REMEMBER_LIFETIME,
        'path'     => '/',
        'secure'   => _is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    $_COOKIE[REMEMBER_COOKIE] = $row_id . ':' . $raw;
}

function consume_remember_cookie(): ?int {
    $cookie = $_COOKIE[REMEMBER_COOKIE] ?? '';
    if ($cookie === '' || !str_contains($cookie, ':')) return null;

    [$id_part, $raw] = explode(':', $cookie, 2);
    $id = (int)$id_part;
    if ($id <= 0 || $raw === '') { clear_remember_cookie(); return null; }

    $db = get_db();
    $stmt = $db->prepare('SELECT id, user_id, token_hash, expires_at FROM remember_tokens WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) { clear_remember_cookie(); return null; }

    // Expiry check
    if (strtotime($row['expires_at']) < time()) {
        $db->prepare('DELETE FROM remember_tokens WHERE id = ?')->execute([$id]);
        clear_remember_cookie();
        return null;
    }

    // Constant-time hash compare
    if (!hash_equals($row['token_hash'], hash('sha256', $raw))) {
        // Possible theft — burn this row defensively.
        $db->prepare('DELETE FROM remember_tokens WHERE id = ?')->execute([$id]);
        clear_remember_cookie();
        return null;
    }

    $user_id = (int)$row['user_id'];

    // Rotate: delete the used token and mint a fresh one in its place.
    $db->prepare('DELETE FROM remember_tokens WHERE id = ?')->execute([$id]);
    issue_remember_token($user_id);

    return $user_id;
}

function clear_remember_cookie(): void {
    $cookie = $_COOKIE[REMEMBER_COOKIE] ?? '';
    if ($cookie !== '' && str_contains($cookie, ':')) {
        [$id_part, ] = explode(':', $cookie, 2);
        $id = (int)$id_part;
        if ($id > 0) {
            try {
                get_db()->prepare('DELETE FROM remember_tokens WHERE id = ?')->execute([$id]);
            } catch (Exception $e) {}
        }
    }
    setcookie(REMEMBER_COOKIE, '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'secure'   => _is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    unset($_COOKIE[REMEMBER_COOKIE]);
}

function current_user(): ?array {
    session_start_safe();
    $id = $_SESSION['user_id'] ?? null;

    // No active session — try to restore via "Remember me" cookie.
    if ($id === null) {
        $remembered = consume_remember_cookie();
        if ($remembered !== null) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = $remembered;
            $id = $remembered;
        }
    }
    if ($id === null) return null;

    $stmt = get_db()->prepare('SELECT id, username, email, role, last_login, must_change_password, my_events_past_days, my_events_future_days FROM users WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function require_login(): array {
    $user = current_user();
    if ($user === null) {
        header('Location: /login.php');
        exit;
    }
    // Force password change before accessing anything else
    if (!empty($user['must_change_password'])) {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        if (!str_starts_with($uri, '/settings.php') && !str_starts_with($uri, '/logout.php')) {
            header('Location: /settings.php?must_change=1');
            exit;
        }
    }
    return $user;
}

function login_rate_limited(): bool {
    $ip = function_exists('get_client_ip') ? get_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '');
    $db = get_db();
    $stmt = $db->prepare("SELECT COUNT(*) FROM activity_log WHERE ip = ? AND action LIKE 'failed_login:%' AND created_at > datetime('now', '-15 minutes')");
    $stmt->execute([$ip]);
    return (int)$stmt->fetchColumn() >= 5;
}

function attempt_login(string $email, string $password): bool|string {
    // Brute force protection: 5 failed attempts per IP per 15 minutes
    if (login_rate_limited()) {
        return 'rate_limited';
    }

    $stmt = get_db()->prepare('SELECT id, password_hash, email_verified FROM users WHERE LOWER(email) = ?');
    $stmt->execute([strtolower(trim($email))]);
    $row = $stmt->fetch();

    // Constant-time: always run password_verify even if user not found
    $hash = $row ? $row['password_hash'] : '$2y$10$dummyhashtopreventtimingattacks000000000000000000000';
    if (!$row || !password_verify($password, $hash)) {
        db_log_anon_activity('failed_login: ' . strtolower(trim($email)), 'critical');
        return false;
    }
    if (!(int)$row['email_verified']) {
        return 'unverified';
    }
    session_start_safe();
    session_regenerate_id(true);
    $_SESSION['user_id'] = $row['id'];

    $db = get_db();
    $db->prepare('UPDATE users SET last_login = CURRENT_TIMESTAMP WHERE id = ?')
       ->execute([$row['id']]);
    db_log_activity($row['id'], 'login');
    return true;
}

function logout(): void {
    session_start_safe();
    $id = $_SESSION['user_id'] ?? null;
    if ($id) db_log_activity($id, 'logout');
    clear_remember_cookie();
    $_SESSION = [];
    session_destroy();
}

function csrf_token(): string {
    session_start_safe();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Register a new user. Returns null on success or an error string on failure.
 * New users are created with email_verified=0 and must verify before logging in.
 * $verify_method: 'email' (default), 'sms', or 'whatsapp'
 */
function register_user(string $username, string $email, string $password, string $phone = '', string $verify_method = 'email'): ?string {
    $username = trim($username);
    $email    = strtolower(trim($email));
    $phone    = $phone !== '' ? normalize_phone(trim($phone)) : '';
    $verify_method = in_array($verify_method, ['email', 'sms', 'whatsapp'], true) ? $verify_method : 'email';

    if ($username === '' || $password === '') {
        return 'Username and password are required.';
    }
    if (!preg_match('/^[a-zA-Z0-9_]{3,30}$/', $username)) {
        return 'Username must be 3-30 characters (letters, numbers, underscores).';
    }
    if (strlen($password) < 12) {
        return 'Password must be at least 12 characters.';
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 'A valid email address is required.';
    }
    if (in_array($verify_method, ['sms', 'whatsapp'], true) && $phone === '') {
        return 'Phone number is required for ' . ucfirst($verify_method) . ' verification.';
    }

    $db = get_db();

    // Check email uniqueness (case-insensitive)
    $stmt = $db->prepare('SELECT id FROM users WHERE LOWER(email) = ?');
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        return 'That email address is already registered.';
    }

    // Check username uniqueness (case-insensitive)
    $stmt = $db->prepare('SELECT id FROM users WHERE LOWER(username) = LOWER(?)');
    $stmt->execute([$username]);
    if ($stmt->fetch()) {
        return 'That username is already taken.';
    }

    $preferred = $verify_method === 'email' ? 'email' : $verify_method;
    $hash = password_hash($password, PASSWORD_BCRYPT);
    $db->prepare('INSERT INTO users (username, password_hash, email, phone, role, email_verified, preferred_contact, verification_method) VALUES (?, ?, ?, ?, ?, 0, ?, ?)')
       ->execute([$username, $hash, $email !== '' ? $email : null, $phone !== '' ? $phone : null, 'user', $preferred, $verify_method]);

    $id = (int)$db->lastInsertId();
    db_log_activity($id, "registered (verify via $verify_method)");

    // Send verification based on chosen method
    if ($verify_method === 'email') {
        send_verification_email($id, $email, $username);
    } else {
        send_verification_code($id, $phone, $verify_method);
    }

    return null;
}

/**
 * Send a 6-digit verification code via SMS or WhatsApp.
 */
function send_verification_code(int $user_id, string $phone, string $method): void {
    $db   = get_db();
    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $hash = hash('sha256', $code);
    $exp  = date('Y-m-d H:i:s', strtotime('+10 minutes'));

    // Invalidate previous unused codes
    $db->prepare('UPDATE phone_verifications SET used=1 WHERE user_id=? AND used=0')->execute([$user_id]);
    $db->prepare('INSERT INTO phone_verifications (user_id, code_hash, method, expires_at) VALUES (?, ?, ?, ?)')
       ->execute([$user_id, $hash, $method, $exp]);

    $site = get_setting('site_name', 'Game Night');
    $msg  = "Your $site verification code is: $code\nThis code expires in 10 minutes.";

    require_once __DIR__ . '/sms.php';
    if ($method === 'whatsapp') {
        send_whatsapp($phone, $msg);
    } else {
        send_sms($phone, $msg);
    }
}

/**
 * Verify a 6-digit code for a user. Returns 'ok', 'expired', 'incorrect', or 'exhausted'.
 */
function verify_code(int $user_id, string $code): string {
    $db = get_db();

    // Find the latest unused code for this user
    $stmt = $db->prepare('SELECT id, code_hash, expires_at, attempts FROM phone_verifications WHERE user_id=? AND used=0 ORDER BY id DESC LIMIT 1');
    $stmt->execute([$user_id]);
    $row = $stmt->fetch();

    if (!$row) return 'expired';

    // Check expiry
    if (strtotime($row['expires_at']) < time()) {
        $db->prepare('UPDATE phone_verifications SET used=1 WHERE id=?')->execute([$row['id']]);
        return 'expired';
    }

    // Check attempt limit
    if ((int)$row['attempts'] >= 5) {
        $db->prepare('UPDATE phone_verifications SET used=1 WHERE id=?')->execute([$row['id']]);
        return 'exhausted';
    }

    // Increment attempts
    $db->prepare('UPDATE phone_verifications SET attempts = attempts + 1 WHERE id=?')->execute([$row['id']]);

    // Check code
    if (!hash_equals($row['code_hash'], hash('sha256', $code))) {
        return ((int)$row['attempts'] + 1 >= 5) ? 'exhausted' : 'incorrect';
    }

    // Success — mark code used and verify the user
    $db->prepare('UPDATE phone_verifications SET used=1 WHERE id=?')->execute([$row['id']]);
    $db->prepare('UPDATE users SET email_verified=1, phone_verified=1 WHERE id=?')->execute([$user_id]);
    db_log_activity($user_id, 'phone verified');

    return 'ok';
}

function send_verification_email(int $user_id, string $email, string $username): void {
    $db    = get_db();
    $token = bin2hex(random_bytes(32));
    $hash  = hash('sha256', $token);
    $exp   = date('Y-m-d H:i:s', strtotime('+24 hours'));

    // Invalidate any previous unused tokens
    $db->prepare('UPDATE email_verifications SET used=1 WHERE user_id=? AND used=0')
       ->execute([$user_id]);
    $db->prepare('INSERT INTO email_verifications (user_id, token_hash, expires_at) VALUES (?, ?, ?)')
       ->execute([$user_id, $hash, $exp]);

    $site  = get_setting('site_name', 'Game Night');
    $url   = get_site_url() . '/verify_email.php?token=' . $token;

    require_once __DIR__ . '/mail.php';
    $html = '<p>Hi ' . htmlspecialchars($username) . ',</p>'
          . '<p>Thanks for signing up for ' . htmlspecialchars($site) . '! Please verify your email address to activate your account.</p>'
          . '<p><a href="' . $url . '" style="background:#2563eb;color:#fff;padding:.5rem 1.2rem;border-radius:6px;text-decoration:none;font-weight:600">Verify Email Address</a></p>'
          . '<p style="color:#64748b;font-size:.875rem">This link expires in 24 hours.</p>';
    send_email($email, $username, 'Verify your ' . $site . ' email address', $html);
}

function csrf_verify(): bool {
    $token = $_POST['csrf_token'] ?? '';
    return hash_equals($_SESSION['csrf_token'] ?? '', $token);
}

/**
 * Send a notification via the user's preferred contact method.
 * Routes to email, SMS, or both depending on preference.
 */
function send_notification(string $username, string $email, string $phone, string $preferred_contact, string $subject, string $smsBody, string $htmlBody): void {
    if (get_setting('notifications_enabled', '0') !== '1') return;
    $doEmail    = in_array($preferred_contact, ['email', 'both'], true) && $email !== '';
    $doSms      = in_array($preferred_contact, ['sms',   'both'], true) && $phone !== '';
    $doWhatsApp = in_array($preferred_contact, ['whatsapp'], true) && $phone !== '';

    if ($doEmail) {
        require_once __DIR__ . '/mail.php';
        send_email($email, $username, $subject, $htmlBody);
    }
    if ($doSms) {
        require_once __DIR__ . '/sms.php';
        send_sms($phone, $smsBody);
    }
    if ($doWhatsApp) {
        require_once __DIR__ . '/sms.php';
        send_whatsapp($phone, $smsBody);
    }
}

/**
 * Send an event invite notification via the user's preferred contact method.
 */
function send_invite_notification(string $username, string $email, string $phone, string $preferred_contact, string $event_title, string $event_start, int $event_id = 0): void {
    if (get_setting('notifications_enabled', '0') !== '1') return;
    require_once __DIR__ . '/sms.php';
    $site  = get_setting('site_name', 'Game Night');
    $month = substr($event_start, 0, 7);
    $url   = get_site_url() . '/calendar.php'
           . ($event_id > 0 ? '?m=' . urlencode($month) . '&open=' . $event_id . '&date=' . urlencode($event_start) : '');
    if (get_setting('url_shortener_enabled') === '1') {
        $url = shorten_url($url);
    }

    $smsBody = "You've been invited to \"$event_title\" on $event_start. Reply YES, NO, or MAYBE to RSVP. View: $url";

    $htmlBody = '<p>Hi ' . htmlspecialchars($username) . ',</p>'
              . '<p>You have been invited to <strong>' . htmlspecialchars($event_title) . '</strong> on ' . htmlspecialchars($event_start) . '.</p>'
              . '<p style="margin-top:1.5rem"><a href="' . htmlspecialchars($url) . '" style="background:#2563eb;color:#fff;padding:.5rem 1.2rem;border-radius:6px;text-decoration:none;font-weight:600">View Event &amp; RSVP</a></p>'
              . '<p style="color:#64748b;font-size:.875rem">You can update your RSVP after signing in.</p>';

    send_notification($username, $email, $phone, $preferred_contact,
        "You're invited: " . $event_title . ' (' . $event_start . ')',
        $smsBody, $htmlBody);
}
