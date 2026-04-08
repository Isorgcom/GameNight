<?php
require_once __DIR__ . '/auth_dl.php';

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

        // Rate limit: max 3 resend requests per IP per hour
        $ip = function_exists('get_client_ip') ? get_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '');
        $rlStmt = $db->prepare("SELECT COUNT(*) FROM activity_log WHERE ip = ? AND action LIKE 'resend_verification%' AND created_at > datetime('now', '-1 hour')");
        $rlStmt->execute([$ip]);
        if ((int)$rlStmt->fetchColumn() < 3) {
            db_log_anon_activity('resend_verification: ' . $email);
            $stmt = $db->prepare('SELECT id, username, email_verified FROM users WHERE LOWER(email)=?');
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user && !(int)$user['email_verified']) {
                send_verification_email($user['id'], $email, $user['username']);
            }
        }
        $sent = true; // always show success
    }
}

$token        = csrf_token();
$prefill_email = htmlspecialchars($_GET['email'] ?? $_POST['email'] ?? '');
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
            <div class="alert alert-success">
                If that email has an unverified account, a new link has been sent.
            </div>
            <p style="text-align:center;margin-top:1.25rem;font-size:.875rem;color:#64748b">
                <a href="/login.php">&larr; Back to Sign In</a>
            </p>
        <?php else: ?>
            <p class="subtitle">Enter your email to receive a new verification link.</p>
            <?php if ($error): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <form method="post" action="/resend_verification.php" novalidate>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">
                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" autofocus required
                           autocomplete="email" value="<?= $prefill_email ?>">
                </div>
                <button type="submit" class="btn btn-primary" style="width:100%;margin-top:.5rem">
                    Send Verification Email
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
