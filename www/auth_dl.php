<?php
require_once __DIR__ . '/db.php';

// ── Security headers (sent on every request) ──────────────────────────────────
header_remove('X-Powered-By');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
// CSP: allow inline scripts/styles (required by Quill editor), block everything else external
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; object-src 'none'; base-uri 'self'; frame-ancestors 'none'");

function session_start_safe(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => false,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

function current_user(): ?array {
    session_start_safe();
    $id = $_SESSION['user_id'] ?? null;
    if ($id === null) return null;

    $stmt = get_db()->prepare('SELECT id, username, email, role, last_login, must_change_password FROM users WHERE id = ?');
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

function attempt_login(string $email, string $password): bool|string {
    $stmt = get_db()->prepare('SELECT id, password_hash, email_verified FROM users WHERE LOWER(email) = ?');
    $stmt->execute([strtolower(trim($email))]);
    $row = $stmt->fetch();

    if (!$row || !password_verify($password, $row['password_hash'])) {
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
 */
function register_user(string $username, string $email, string $password, string $phone = ''): ?string {
    $username = trim($username);
    $email    = strtolower(trim($email));
    $phone    = $phone !== '' ? normalize_phone(trim($phone)) : '';

    if ($username === '' || $password === '') {
        return 'Username and password are required.';
    }
    if (!preg_match('/^[a-zA-Z0-9_]{3,30}$/', $username)) {
        return 'Username must be 3-30 characters (letters, numbers, underscores).';
    }
    if (strlen($password) < 8) {
        return 'Password must be at least 8 characters.';
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 'A valid email address is required.';
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

    $hash = password_hash($password, PASSWORD_BCRYPT);
    $db->prepare('INSERT INTO users (username, password_hash, email, phone, role, email_verified) VALUES (?, ?, ?, ?, ?, 0)')
       ->execute([$username, $hash, $email !== '' ? $email : null, $phone !== '' ? $phone : null, 'user']);

    $id = (int)$db->lastInsertId();
    db_log_activity($id, 'registered');

    // Send verification email
    send_verification_email($id, $email, $username);

    return null;
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
    $url   = 'https://' . $_SERVER['HTTP_HOST'] . '/verify_email.php?token=' . $token;

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
    require_once __DIR__ . '/sms.php';
    $site  = get_setting('site_name', 'Game Night');
    $month = substr($event_start, 0, 7);
    $url   = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/calendar.php'
           . ($event_id > 0 ? '?m=' . urlencode($month) . '&open=' . $event_id . '&date=' . urlencode($event_start) : '');
    if (get_setting('url_shortener_enabled') === '1') {
        $url = shorten_url($url);
    }

    $smsBody = "You've been invited to \"$event_title\" on $event_start. View it at: $url";

    $htmlBody = '<p>Hi ' . htmlspecialchars($username) . ',</p>'
              . '<p>You have been invited to <strong>' . htmlspecialchars($event_title) . '</strong> on ' . htmlspecialchars($event_start) . '.</p>'
              . '<p style="margin-top:1.5rem"><a href="' . htmlspecialchars($url) . '" style="background:#2563eb;color:#fff;padding:.5rem 1.2rem;border-radius:6px;text-decoration:none;font-weight:600">View Event &amp; RSVP</a></p>'
              . '<p style="color:#64748b;font-size:.875rem">You can update your RSVP after signing in.</p>';

    send_notification($username, $email, $phone, $preferred_contact,
        "You're invited: " . $event_title . ' (' . $event_start . ')',
        $smsBody, $htmlBody);
}
