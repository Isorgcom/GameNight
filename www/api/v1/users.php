<?php
/**
 * POST /api/v1/users
 *
 * Lets a sister site (or any consumer holding a write-scoped API key) create a
 * GameNight user and add them to the key's bound league. Mirrors the new-user
 * branch of walkin.php: soft account, no password yet, must_change_password=1,
 * optional verification email/SMS so the user can later set a password.
 *
 * Idempotent on email/phone — replaying the same body returns the existing
 * user_id and ensures league membership without duplicating accounts or sending
 * a second verification.
 */

require_once __DIR__ . '/../_auth.php';
require_once __DIR__ . '/../../auth.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// CORS preflight: respond before authenticating so browser-side callers work.
if ($method === 'OPTIONS') {
    api_send_headers(0);
    http_response_code(204);
    exit;
}
if ($method !== 'POST') {
    api_log_request(null, 405);
    api_fail('Method not allowed', 405);
}

$key = api_authenticate();
api_require_scope($key, 'write');

$db        = get_db();
$key_id    = (int)$key['id'];
$league_id = (int)$key['league_id'];

// ── Per-key rate limit: 60 successful creations per hour ─────────────────────
// Reuses api_request_log instead of a new table — rows are already written by
// every endpoint, so we can count successful POSTs to /users in the last hour.
$rl = $db->prepare(
    "SELECT COUNT(*) FROM api_request_log
      WHERE key_id = ?
        AND status = 200
        AND method = 'POST'
        AND path LIKE '%/api/v1/users%'
        AND created_at > datetime('now','-1 hour')"
);
$rl->execute([$key_id]);
if ((int)$rl->fetchColumn() >= 60) {
    api_log_request($key_id, 429);
    api_fail('Rate limit exceeded: 60 user creations per hour per key', 429);
}

// ── Parse + validate JSON body ───────────────────────────────────────────────
$raw  = file_get_contents('php://input');
$body = json_decode($raw ?: '', true);
if (!is_array($body)) {
    api_log_request($key_id, 400);
    api_fail('Request body must be valid JSON', 400);
}

$display_name = trim((string)($body['display_name'] ?? ''));
$email        = strtolower(trim((string)($body['email'] ?? '')));
$phone_in     = trim((string)($body['phone'] ?? ''));
$username_in  = trim((string)($body['username'] ?? ''));
$verify_in    = strtolower(trim((string)($body['verification_method'] ?? '')));

if ($display_name === '') {
    api_log_request($key_id, 400);
    api_fail('display_name is required', 400);
}

$phone_normalized = ($phone_in !== '') ? normalize_phone($phone_in) : '';
$phone_digits     = preg_replace('/\D/', '', $phone_normalized);
$has_email = ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL));
$has_phone = ($phone_normalized !== '' && strlen($phone_digits) >= 7 && strlen($phone_digits) <= 15);

if ($email !== '' && !$has_email) {
    api_log_request($key_id, 400);
    api_fail('Invalid email address', 400);
}
if ($phone_in !== '' && !$has_phone) {
    api_log_request($key_id, 400);
    api_fail('Invalid phone number', 400);
}
if (!$has_email && !$has_phone) {
    api_log_request($key_id, 400);
    api_fail('Must include a valid email or phone', 400);
}

// Default verification method follows whichever contact was provided. 'none'
// lets the caller suppress sending if they handle onboarding themselves.
$valid_methods = ['email', 'sms', 'whatsapp', 'none'];
if ($verify_in === '') {
    $verify_in = $has_email ? 'email' : 'sms';
}
if (!in_array($verify_in, $valid_methods, true)) {
    api_log_request($key_id, 400);
    api_fail('verification_method must be one of: email, sms, whatsapp, none', 400);
}
if ($verify_in === 'email' && !$has_email) {
    api_log_request($key_id, 400);
    api_fail('verification_method=email requires an email address', 400);
}
if (($verify_in === 'sms' || $verify_in === 'whatsapp') && !$has_phone) {
    api_log_request($key_id, 400);
    api_fail("verification_method=$verify_in requires a phone number", 400);
}

// ── Idempotent existing-user lookup ──────────────────────────────────────────
// Look up by whichever contact was provided. Email wins when both are present —
// matches walkin.php and avoids the surprise of routing to a different user
// just because they share a phone with someone.
$existing = null;
if ($has_email) {
    $stmt = $db->prepare('SELECT id, username FROM users WHERE LOWER(email) = ?');
    $stmt->execute([$email]);
    $existing = $stmt->fetch();
}
if (!$existing && $has_phone) {
    $stmt = $db->prepare('SELECT id, username FROM users WHERE phone = ?');
    $stmt->execute([$phone_normalized]);
    $existing = $stmt->fetch();
}

if ($existing) {
    $uid      = (int)$existing['id'];
    $username = (string)$existing['username'];

    $ins = $db->prepare(
        "INSERT OR IGNORE INTO league_members (league_id, user_id, role, joined_at)
         VALUES (?, ?, 'member', CURRENT_TIMESTAMP)"
    );
    $ins->execute([$league_id, $uid]);
    $member_added = ($ins->rowCount() > 0);

    db_log_anon_activity("api_create_user: existing $username (id=$uid) via key=$key_id league=$league_id" . ($member_added ? ' (added to league)' : ''));
    api_log_request($key_id, 200);
    api_ok([
        'user_id'             => $uid,
        'username'            => $username,
        'created'             => false,
        'league_member_added' => $member_added,
        'verification_sent'   => false,
    ], 0);
}

// ── New-user creation ────────────────────────────────────────────────────────
// Username: explicit if caller provided one, else derived from display_name.
$base_username = $username_in !== ''
    ? $username_in
    : preg_replace('/[^a-zA-Z0-9_]/', '', preg_replace('/\s+/', '_', $display_name));

if ($username_in !== '') {
    if (!preg_match('/^[a-zA-Z0-9_]{3,30}$/', $username_in)) {
        api_log_request($key_id, 400);
        api_fail('username must be 3-30 chars, letters/numbers/underscores only', 400);
    }
    $chk = $db->prepare('SELECT 1 FROM users WHERE LOWER(username) = LOWER(?)');
    $chk->execute([$username_in]);
    if ($chk->fetchColumn()) {
        api_log_request($key_id, 409);
        api_fail('username_taken', 409);
    }
    $final_username = $username_in;
} else {
    if (strlen((string)$base_username) < 3 || strlen((string)$base_username) > 30) {
        api_log_request($key_id, 400);
        api_fail('display_name must produce a username of 3-30 chars (letters, numbers, spaces, underscores)', 400);
    }
    $final_username = $base_username;
    $suffix = 2;
    while (true) {
        $u = $db->prepare('SELECT 1 FROM users WHERE LOWER(username) = LOWER(?)');
        $u->execute([$final_username]);
        if (!$u->fetchColumn()) break;
        $final_username = $base_username . $suffix;
        $suffix++;
        if ($suffix > 999) {
            api_log_request($key_id, 409);
            api_fail('Could not generate a unique username from display_name; pass an explicit username', 409);
        }
    }
}

// preferred_contact + verification_method follow walkin.php conventions.
$preferred = $verify_in === 'none'
    ? ($has_email ? 'email' : 'sms')
    : $verify_in;
$method    = $verify_in === 'none'
    ? ($has_email ? 'email' : 'sms')
    : $verify_in;

try {
    $db->prepare(
        'INSERT INTO users (username, password_hash, email, phone, role, email_verified, phone_verified, must_change_password, preferred_contact, verification_method)
         VALUES (?, ?, ?, ?, ?, 0, 0, 1, ?, ?)'
    )->execute([
        $final_username,
        '',
        $has_email ? $email : null,
        $has_phone ? $phone_normalized : null,
        'user',
        $preferred,
        $method,
    ]);
} catch (Exception $e) {
    // UNIQUE-constraint race on email or phone. We already de-duped above, so
    // a hit here means a parallel write — return 409 rather than 500.
    api_log_request($key_id, 409);
    api_fail('contact_taken', 409);
}
$new_id = (int)$db->lastInsertId();

$ins = $db->prepare(
    "INSERT OR IGNORE INTO league_members (league_id, user_id, role, joined_at)
     VALUES (?, ?, 'member', CURRENT_TIMESTAMP)"
);
$ins->execute([$league_id, $new_id]);
$member_added = ($ins->rowCount() > 0);

// Verification send is best-effort — failure must not roll back the account.
$verification_sent = false;
if ($verify_in !== 'none') {
    try {
        if ($verify_in === 'email') {
            send_verification_email($new_id, $email, $final_username);
        } else {
            send_verification_code($new_id, $phone_normalized, $verify_in);
        }
        $verification_sent = true;
    } catch (Exception $e) {
        // swallow — user is created and in the league; sender can retry later
    }
}

db_log_anon_activity("api_create_user: new $final_username (id=$new_id) via key=$key_id league=$league_id");
api_log_request($key_id, 200);
api_ok([
    'user_id'             => $new_id,
    'username'            => $final_username,
    'created'             => true,
    'league_member_added' => $member_added,
    'verification_sent'   => $verification_sent,
], 0);
