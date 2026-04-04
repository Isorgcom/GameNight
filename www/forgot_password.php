<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/mail.php';

if (current_user()) { header('Location: /'); exit; }

$site_name = get_setting('site_name', 'Game Night');
$sent      = false;
$error     = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    session_start_safe();
    if (!csrf_verify()) {
        $error = 'Invalid request. Please try again.';
    } else {
        $email = strtolower(trim($_POST['email'] ?? ''));
        $db    = get_db();

        $stmt = $db->prepare('SELECT id, username FROM users WHERE LOWER(email) = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user) {
            // Invalidate any existing unused tokens for this user
            $db->prepare('UPDATE password_resets SET used=1 WHERE user_id=? AND used=0')
               ->execute([$user['id']]);

            // Generate token
            $token      = bin2hex(random_bytes(32));
            $token_hash = hash('sha256', $token);
            $expires    = gmdate('Y-m-d H:i:s', strtotime('+1 hour'));

            $db->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, ?)')
               ->execute([$user['id'], $token_hash, $expires]);

            $reset_url = 'https://' . $_SERVER['HTTP_HOST'] . '/reset_password.php?token=' . $token;

            $html = '<p>Hi ' . htmlspecialchars($user['username']) . ',</p>'
                  . '<p>Someone requested a password reset for your ' . htmlspecialchars($site_name) . ' account.</p>'
                  . '<p><a href="' . $reset_url . '" style="background:#2563eb;color:#fff;padding:.5rem 1.2rem;border-radius:6px;text-decoration:none;font-weight:600">Reset My Password</a></p>'
                  . '<p style="color:#64748b;font-size:.875rem">This link expires in 1 hour. If you did not request this, you can ignore this email.</p>';

            send_email($email, $user['username'], 'Reset your ' . $site_name . ' password', $html);
        }

        // Always show success — don't reveal whether email exists
        $sent = true;
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
    <link rel="stylesheet" href="/style.css">
</head>
<body>
<nav><div class="nav-top"><a class="brand" href="/"><?= htmlspecialchars($site_name) ?></a></div></nav>
<div class="card-wrap">
    <div class="card">
        <h2>Forgot Password</h2>

        <?php if ($sent): ?>
            <div class="alert alert-success">
                If that email is registered, a reset link has been sent. Check your inbox.
            </div>
            <p style="text-align:center;margin-top:1.25rem;font-size:.875rem;color:#64748b">
                <a href="/login.php">&larr; Back to Sign In</a>
            </p>
        <?php else: ?>
            <p class="subtitle">Enter your email and we'll send you a reset link.</p>

            <?php if ($error): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="post" action="/forgot_password.php" novalidate>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">
                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" autofocus required
                           autocomplete="email"
                           value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
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
