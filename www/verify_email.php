<?php
require_once __DIR__ . '/auth.php';

if (current_user()) { header('Location: /'); exit; }

$site_name  = get_setting('site_name', 'Game Night');
$db         = get_db();
// Token may arrive on GET (link click) or POST (the confirm button below).
$token_raw  = trim($_REQUEST['token'] ?? '');
$token_hash = $token_raw !== '' ? hash('sha256', $token_raw) : '';
$success    = false;
$error      = '';
$confirm    = false;   // GET with a token: show a button, do NOT consume the token

if ($token_hash === '') {
    $error = 'No verification token provided.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Only an actual button press (POST) consumes the token. Email security
    // scanners / link-preview bots issue GET requests and would otherwise burn
    // the one-time token (and silently flip email_verified) before the human
    // ever clicked — making the real click look "expired". Same confirm-on-POST
    // hardening rsvp.php uses against link crawlers.
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
    // GET with a token: render the confirm button without touching the DB.
    $confirm = true;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($site_name) ?></title>
    <link rel="stylesheet" href="/style.css?v=<?= htmlspecialchars(APP_VERSION) ?>">
</head>
<body>
<nav><div class="nav-top"><a class="brand" href="/"><?= htmlspecialchars($site_name) ?></a></div></nav>
<div class="card-wrap">
    <div class="card" style="text-align:center">
        <?php if ($success): ?>
            <h2>Email Verified!</h2>
            <p class="subtitle">Your account is now active.</p>
            <a href="/login.php" class="btn btn-primary" style="display:inline-block;margin-top:1rem">Sign In</a>
        <?php elseif ($confirm): ?>
            <h2>Verify your email</h2>
            <p class="subtitle">Click below to confirm your email address and activate your account.</p>
            <form method="post" action="/verify_email.php" style="margin-top:1rem">
                <input type="hidden" name="token" value="<?= htmlspecialchars($token_raw) ?>">
                <button type="submit" class="btn btn-primary" style="display:inline-block">Verify Email Address</button>
            </form>
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
