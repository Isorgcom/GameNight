<?php
require_once __DIR__ . '/auth.php';

if (current_user()) { header('Location: /'); exit; }

$site_name = get_setting('site_name', 'Game Night');
$db        = get_db();
$token_raw = trim($_POST['reset_token'] ?? $_GET['token'] ?? '');
$token_hash = $token_raw !== '' ? hash('sha256', $token_raw) : '';
$flash     = '';
$success   = false;

// Validate token
$reset = null;
if ($token_hash) {
    $stmt = $db->prepare("
        SELECT r.*, u.username, u.email
        FROM password_resets r
        JOIN users u ON u.id = r.user_id
        WHERE r.token_hash = ? AND r.used = 0 AND r.expires_at > datetime('now')
    ");
    $stmt->execute([$token_hash]);
    $reset = $stmt->fetch();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    session_start_safe();
    if (!csrf_verify()) {
        $flash = 'Invalid request. Please try again.';
    } elseif (!$reset) {
        $flash = 'This reset link is invalid or has expired.';
    } else {
        $new_pw  = $_POST['new_password']     ?? '';
        $conf_pw = $_POST['confirm_password'] ?? '';

        if (strlen($new_pw) < 12) {
            $flash = 'Password must be at least 12 characters.';
        } elseif ($new_pw !== $conf_pw) {
            $flash = 'Passwords do not match.';
        } else {
            $hash = password_hash($new_pw, PASSWORD_BCRYPT);
            $db->prepare('UPDATE users SET password_hash=?, must_change_password=0 WHERE id=?')
               ->execute([$hash, $reset['user_id']]);
            $db->prepare('UPDATE password_resets SET used=1 WHERE id=?')
               ->execute([$reset['id']]);
            db_log_activity($reset['user_id'], 'reset password via email link');
            $success = true;
        }
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
        <h2>Reset Password</h2>

        <?php if ($success): ?>
            <div class="alert alert-success">Password updated successfully.</div>
            <p style="text-align:center;margin-top:1.25rem;font-size:.875rem">
                <a href="/login.php" class="btn btn-primary" style="display:inline-block">Sign In</a>
            </p>

        <?php elseif (!$reset): ?>
            <div class="alert alert-error">
                This reset link is invalid or has expired. Reset links are valid for 1 hour.
            </div>
            <p style="text-align:center;margin-top:1.25rem;font-size:.875rem;color:#64748b">
                <a href="/forgot_password.php">Request a new link</a>
            </p>

        <?php else: ?>
            <p class="subtitle">Setting a new password for <strong><?= htmlspecialchars($reset['username']) ?></strong>.</p>

            <?php if ($flash): ?>
                <div class="alert alert-error"><?= htmlspecialchars($flash) ?></div>
            <?php endif; ?>

            <form method="post" action="/reset_password.php" novalidate>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">
                <input type="hidden" name="reset_token" value="<?= htmlspecialchars($token_raw) ?>">
                <div class="form-group">
                    <label for="new_password">New Password</label>
                    <input type="password" id="new_password" name="new_password"
                           autocomplete="new-password" required minlength="12">
                    <p class="hint">At least 12 characters.</p>
                </div>
                <div class="form-group">
                    <label for="confirm_password">Confirm Password</label>
                    <input type="password" id="confirm_password" name="confirm_password"
                           autocomplete="new-password" required minlength="12">
                </div>
                <button type="submit" class="btn btn-primary" style="width:100%;margin-top:.5rem">
                    Set New Password
                </button>
            </form>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
