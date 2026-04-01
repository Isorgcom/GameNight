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
            'samesite' => 'Strict',
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

function attempt_login(string $email, string $password): bool {
    $stmt = get_db()->prepare('SELECT id, password_hash FROM users WHERE LOWER(email) = ?');
    $stmt->execute([strtolower(trim($email))]);
    $row = $stmt->fetch();

    if ($row && password_verify($password, $row['password_hash'])) {
        session_start_safe();
        session_regenerate_id(true);
        $_SESSION['user_id'] = $row['id'];

        $db = get_db();
        $db->prepare('UPDATE users SET last_login = CURRENT_TIMESTAMP WHERE id = ?')
           ->execute([$row['id']]);
        db_log_activity($row['id'], 'login');
        return true;
    }
    return false;
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
    $db->prepare('INSERT INTO users (username, password_hash, email, phone, role) VALUES (?, ?, ?, ?, ?)')
       ->execute([$username, $hash, $email !== '' ? $email : null, $phone !== '' ? $phone : null, 'user']);

    $id = (int)$db->lastInsertId();
    session_start_safe();
    session_regenerate_id(true);
    $_SESSION['user_id'] = $id;
    $db->prepare('UPDATE users SET last_login = CURRENT_TIMESTAMP WHERE id = ?')->execute([$id]);
    db_log_activity($id, 'registered');
    return null;
}

function csrf_verify(): bool {
    $token = $_POST['csrf_token'] ?? '';
    return hash_equals($_SESSION['csrf_token'] ?? '', $token);
}
