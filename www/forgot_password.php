<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/mail.php';
require_once __DIR__ . '/sms.php';

if (current_user()) { header('Location: /'); exit; }

$site_name = get_setting('site_name', 'Game Night');
$sent      = false;
$error     = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    session_start_safe();
    if (!csrf_verify()) {
        $error = 'Invalid request. Please try again.';
    } else {
        $identifier = trim($_POST['identifier'] ?? $_POST['email'] ?? '');
        $db    = get_db();

        // Rate limit: max 3 password reset requests per IP per hour
        $ip = function_exists('get_client_ip') ? get_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '');
        $rlStmt = $db->prepare("SELECT COUNT(*) FROM activity_log WHERE ip = ? AND action LIKE 'password_reset_request%' AND created_at > datetime('now', '-1 hour')");
        $rlStmt->execute([$ip]);
        if ((int)$rlStmt->fetchColumn() >= 3) {
            // Honest rate-limit notice. Keyed on IP/device, so it doesn't reveal
            // whether any particular account exists — only that this device has
            // asked too many times — which keeps the account-enumeration defense
            // (the generic success message below) intact.
            $error = 'Too many password reset requests from this device. Please wait up to an hour and try again.';
        } else {
        db_log_anon_activity('password_reset_request: ' . $identifier);

        $user = find_user_by_identifier($identifier);

        if ($user) {
            // Invalidate any existing unused tokens for this user
            $db->prepare('UPDATE password_resets SET used=1 WHERE user_id=? AND used=0')
               ->execute([$user['id']]);

            // Generate token
            $token      = bin2hex(random_bytes(32));
            $token_hash = hash('sha256', $token);
            $expires    = gmdate('Y-m-d H:i:s', strtotime('+1 hour'));

            $reset_url = get_site_url() . '/reset_password.php?token=' . $token;
            $method    = $user['verification_method'] ?? ($user['preferred_contact'] ?? 'email');
            // A reset goes only to a channel the account has proven (v1.37.0): a
            // phone changed in Settings is unverified until its code is entered,
            // and texting a reset there would hand the account to whoever typed
            // it. A phone account with an unproven phone falls back to email.
            $phone_ok = !empty($user['phone']) && (int)($user['phone_verified'] ?? 0) === 1;
            if ($method !== 'email' && !$phone_ok) $method = 'email';
            $channel = ($method === 'email') ? (!empty($user['email']) ? 'email' : '') : ($method === 'whatsapp' ? 'whatsapp' : 'sms');

            if ($channel !== '') {
                $db->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at, channel) VALUES (?, ?, ?, ?)')
                   ->execute([$user['id'], $token_hash, $expires, $channel]);
            }

            if ($channel === 'email') {
                $html = '<p>Hi ' . htmlspecialchars($user['username']) . ',</p>'
                      . '<p>Someone requested a password reset for your ' . htmlspecialchars($site_name) . ' account.</p>'
                      . '<p><a href="' . $reset_url . '" style="background:#2563eb;color:#fff;padding:.5rem 1.2rem;border-radius:6px;text-decoration:none;font-weight:600">Reset My Password</a></p>'
                      . '<p style="color:#64748b;font-size:.875rem">This link expires in 1 hour. If you did not request this, you can ignore this email.</p>';
                send_email($user['email'], $user['username'], 'Reset your ' . $site_name . ' password', $html);
            } elseif ($channel !== '') {
                // Phone-based reset, to the proven phone. The link is never put
                // through the URL shortener (v1.37.0): that would hand a working
                // reset token to the shortening service and keep it in short_links.
                $body  = $site_name . ': Reset your password (expires 1h): ' . $reset_url;
                if ($channel === 'whatsapp') {
                    send_whatsapp($user['phone'], $body);
                } else {
                    send_sms($user['phone'], $body);
                }
            }
        }

        // Always show success — don't reveal whether user exists
        $sent = true;
        } // end rate limit else
    }
}

$token = csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($site_name) ?></title>
    <link rel="stylesheet" href="/style.css?v=<?= htmlspecialchars(APP_VERSION . '.' . (@filemtime(__DIR__ . '/style.css') ?: 0)) ?>">
</head>
<body>
<nav><div class="nav-top"><a class="brand" href="/"><?= htmlspecialchars($site_name) ?></a></div></nav>
<div class="card-wrap">
    <div class="card">
        <h2>Forgot Password</h2>

        <?php if ($sent): ?>
            <div class="alert alert-success">
                If that account exists, a reset link has been sent. Check your email or texts.
            </div>
            <p style="text-align:center;margin-top:1.25rem;font-size:.875rem;color:#64748b">
                <a href="/login.php">&larr; Back to Sign In</a>
            </p>
        <?php else: ?>
            <p class="subtitle">Enter your email or phone and we'll send you a reset link.</p>

            <?php if ($error): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="post" action="/forgot_password.php" novalidate>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">
                <div class="form-group">
                    <label for="identifier">Email or phone</label>
                    <input type="text" id="identifier" name="identifier" autofocus required
                           autocomplete="username"
                           value="<?= htmlspecialchars($_POST['identifier'] ?? $_POST['email'] ?? '') ?>">
                </div>
                <button type="submit" class="btn btn-primary" style="width:100%;margin-top:.5rem">
                    Send Reset Link
                </button>
            </form>

            <p style="text-align:center;margin-top:1.25rem;font-size:.875rem;color:#64748b">
                <a href="/login.php">&larr; Back to Sign In</a>
            </p>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
