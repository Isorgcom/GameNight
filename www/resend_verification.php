<?php
require_once __DIR__ . '/auth_dl.php';

if (current_user()) { header('Location: /'); exit; }

$site_name = get_setting('site_name', 'Game Night');
$sent          = false;
$error         = '';
$resend_method = 'email';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    session_start_safe();
    if (!csrf_verify()) {
        $error = 'Invalid request. Please try again.';
    } else {
        $identifier = trim($_POST['identifier'] ?? $_POST['email'] ?? $_POST['phone'] ?? '');
        $db    = get_db();

        // Rate limit: max 3 resend requests per IP per hour
        $ip = function_exists('get_client_ip') ? get_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '');
        $rlStmt = $db->prepare("SELECT COUNT(*) FROM activity_log WHERE ip = ? AND action LIKE 'resend_verification%' AND created_at > datetime('now', '-1 hour')");
        $rlStmt->execute([$ip]);
        if ((int)$rlStmt->fetchColumn() < 3) {
            db_log_anon_activity('resend_verification: ' . $identifier);
            $user = find_user_by_identifier($identifier);

            if ($user) {
                $method = $user['verification_method'] ?? 'email';
                $emailVerified = (int)($user['email_verified'] ?? 0);
                $phoneVerified = (int)($user['phone_verified'] ?? 0);
                $needsVerify = ($method === 'email' && !$emailVerified)
                            || (in_array($method, ['sms', 'whatsapp'], true) && !$phoneVerified);

                if ($needsVerify) {
                    if (in_array($method, ['sms', 'whatsapp'], true) && ($user['phone'] ?? '') !== '') {
                        send_verification_code($user['id'], $user['phone'], $method);
                        // Store session vars so verify_phone.php can pick them up
                        session_start_safe();
                        $_SESSION['verify_user_id'] = (int)$user['id'];
                        $_SESSION['verify_method']  = $method;
                        $resend_method = $method;
                    } elseif (!empty($user['email'])) {
                        send_verification_email($user['id'], $user['email'], $user['username']);
                    }
                }
            }
        }
        $sent = true; // always show success
    }
}

$token        = csrf_token();
$prefill = htmlspecialchars($_GET['email'] ?? $_GET['phone'] ?? $_POST['identifier'] ?? $_POST['email'] ?? '');
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
        <h2>Resend Verification</h2>

        <?php if ($sent): ?>
            <?php if (in_array($resend_method, ['sms', 'whatsapp'], true)): ?>
            <div class="alert alert-success">
                A new 6-digit code has been sent via <?= ucfirst($resend_method) ?>.
            </div>
            <a href="/verify_phone.php" class="btn btn-primary" style="width:100%;margin-top:.75rem;display:block;text-align:center;text-decoration:none">Enter Code</a>
            <?php else: ?>
            <div class="alert alert-success">
                If that email has an unverified account, a new link has been sent.
            </div>
            <?php endif; ?>
            <p style="text-align:center;margin-top:1.25rem;font-size:.875rem;color:#64748b">
                <a href="/login.php">&larr; Back to Sign In</a>
            </p>
        <?php else: ?>
            <p class="subtitle">Enter your email or phone to receive a new verification.</p>
            <?php if ($error): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <form method="post" action="/resend_verification.php" novalidate>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">
                <div class="form-group">
                    <label for="identifier">Email or phone</label>
                    <input type="text" id="identifier" name="identifier" autofocus required
                           autocomplete="username" value="<?= $prefill ?>">
                </div>
                <button type="submit" class="btn btn-primary" style="width:100%;margin-top:.5rem">
                    Resend Verification
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
