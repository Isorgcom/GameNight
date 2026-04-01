<?php
require_once __DIR__ . '/auth.php';

if (current_user()) { header('Location: /'); exit; }

$site_name  = get_setting('site_name', 'Game Night');
$db         = get_db();
$token_raw  = trim($_GET['token'] ?? '');
$token_hash = $token_raw !== '' ? hash('sha256', $token_raw) : '';
$success    = false;
$error      = '';

if ($token_hash) {
    $stmt = $db->prepare("
        SELECT v.*, u.email FROM email_verifications v
        JOIN users u ON u.id = v.user_id
        WHERE v.token_hash = ? AND v.used = 0 AND v.expires_at > datetime('now')
    ");
    $stmt->execute([$token_hash]);
    $row = $stmt->fetch();

    if ($row) {
        $db->prepare('UPDATE users SET email_verified=1 WHERE id=?')->execute([$row['user_id']]);
        $db->prepare('UPDATE email_verifications SET used=1 WHERE id=?')->execute([$row['id']]);
        db_log_activity($row['user_id'], 'verified email');
        $success = true;
    } else {
        $error = 'This verification link is invalid or has expired.';
    }
} else {
    $error = 'No verification token provided.';
}
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
    <div class="card" style="text-align:center">
        <?php if ($success): ?>
            <h2>Email Verified!</h2>
            <p class="subtitle">Your account is now active.</p>
            <a href="/login.php" class="btn btn-primary" style="display:inline-block;margin-top:1rem">Sign In</a>
        <?php else: ?>
            <h2>Verification Failed</h2>
            <div class="alert alert-error" style="text-align:left"><?= htmlspecialchars($error) ?></div>
            <p style="margin-top:1rem;font-size:.875rem;color:#64748b">
                <a href="/resend_verification.php">Request a new verification link</a>
            </p>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
