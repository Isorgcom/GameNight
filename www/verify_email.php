<?php
require_once __DIR__ . '/auth.php';

$site_name  = get_setting('site_name', 'Game Night');
$db         = get_db();
$viewer     = current_user();
// Token may arrive on GET (link click) or POST (the confirm button below).
$token_raw  = trim($_REQUEST['token'] ?? '');
$token_hash = $token_raw !== '' ? hash('sha256', $token_raw) : '';
$success    = false;
$error      = '';
$confirm    = false;   // GET with a token: show a button, do NOT consume the token
$change     = null;    // the new address when the token confirms an email CHANGE
$needs_pw   = false;   // a sign-up with a password: verifying asks for it (v1.37.0)

// Reading the row consumes nothing. It tells a signup token (new_email NULL)
// from an email-change token issued by Settings, which the signed-in account
// holder is expected to open; a valid signup token still sends a signed-in
// viewer home, and a dead link shows its error to anyone.
$row = null;
if ($token_hash !== '') {
    $stmt = $db->prepare("
        SELECT v.*, u.email, u.password_hash FROM email_verifications v
        JOIN users u ON u.id = v.user_id
        WHERE v.token_hash = ? AND v.used = 0 AND v.expires_at > datetime('now')
    ");
    $stmt->execute([$token_hash]);
    $row = $stmt->fetch() ?: null;
    $change = ($row && !empty($row['new_email'])) ? (string)$row['new_email'] : null;
    // Opening the mail proves the inbox, not that its owner made the account:
    // anyone can sign up with someone else's address and wait for them to press
    // Verify, and the account (theirs, with the sign-up's password) would then
    // own the address and claim what was waiting for it. So an account created
    // with a password asks for it here. Accounts made at the door, by a share
    // link or by a league's API have no password and nobody can sign in to them
    // until a reset, so they verify as before.
    $needs_pw = $row && $change === null && (string)($row['password_hash'] ?? '') !== '';
}
if ($viewer && $change === null && ($token_hash === '' || $row)) { header('Location: /'); exit; }

if ($token_hash === '') {
    $error = 'No verification token provided.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Only an actual button press (POST) consumes the token. Email security
    // scanners / link-preview bots issue GET requests and would otherwise burn
    // the one-time token (and silently flip email_verified) before the human
    // ever clicked — making the real click look "expired". Same confirm-on-POST
    // hardening rsvp.php uses against link crawlers.
    if (!csrf_verify()) {
        $error = 'This page expired. Open the link from your email again.';
    } elseif (!$row) {
        $error = 'This verification link is invalid or has expired.';
    } elseif ($change !== null) {
        // An email change: applied now, and only while the account still has
        // this address pending (Settings can cancel it) and nobody took it
        // meanwhile. The token is spent either way.
        $db->prepare('UPDATE email_verifications SET used=1 WHERE id=?')->execute([$row['id']]);
        $taken = $db->prepare('SELECT id FROM users WHERE LOWER(email) = ? AND id != ?');
        $taken->execute([strtolower($change), $row['user_id']]);
        if ($taken->fetch()) {
            $error = 'That email address is already in use on another account.';
        } else {
            $upd = $db->prepare("UPDATE users SET email = ?, email_verified = 1, pending_email = NULL WHERE id = ? AND LOWER(COALESCE(pending_email, '')) = ?");
            $upd->execute([$change, $row['user_id'], strtolower($change)]);
            if ($upd->rowCount() === 1) {
                claim_verified_contact((int)$row['user_id'], $change);
                db_log_activity($row['user_id'], 'confirmed new email address');
                $success = true;
            } else {
                $error = 'This email change was cancelled or has already been applied.';
            }
        }
    } elseif ($needs_pw && !password_verify((string)($_POST['password'] ?? ''), (string)$row['password_hash'])) {
        // Five wrong passwords spend the link (the token is secret, so this only
        // stops someone holding the mail from guessing at length).
        db_log_activity($row['user_id'], 'verify_email_bad_password', 'warning');
        $bad = $db->prepare("SELECT COUNT(*) FROM activity_log WHERE user_id = ? AND action = 'verify_email_bad_password' AND created_at > datetime('now', '-1 day')");
        $bad->execute([$row['user_id']]);
        if ((int)$bad->fetchColumn() >= 5) {
            $db->prepare('UPDATE email_verifications SET used=1 WHERE id=?')->execute([$row['id']]);
            $error = 'Too many wrong passwords; this link no longer works. If you did not sign up, there is nothing to do. To use this address yourself, reset the password.';
        } else {
            $confirm = true;
            $error = 'That is not the password this account was created with.';
        }
    } else {
        $db->prepare('UPDATE users SET email_verified=1 WHERE id=?')->execute([$row['user_id']]);
        $db->prepare('UPDATE email_verifications SET used=1 WHERE id=?')->execute([$row['id']]);
        // The address is proven now: pending league and contact rows carrying it are claimed.
        claim_verified_contact((int)$row['user_id'], (string)($row['email'] ?? ''));
        db_log_activity($row['user_id'], 'verified email');
        $success = true;
    }
} else {
    // GET with a token: render the confirm button without touching the DB.
    $confirm = true;
}
$csrf = csrf_token();
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
    <div class="card" style="text-align:center">
        <?php if ($success && $change !== null): ?>
            <h2>Email Updated</h2>
            <p class="subtitle">Your account now uses <strong><?= htmlspecialchars($change) ?></strong>.</p>
            <a href="<?= $viewer ? '/settings.php' : '/login.php' ?>" class="btn btn-primary" style="display:inline-block;margin-top:1rem"><?= $viewer ? 'Back to Settings' : 'Sign In' ?></a>
        <?php elseif ($success): ?>
            <h2>Email Verified!</h2>
            <p class="subtitle">Your account is now active.</p>
            <a href="/login.php" class="btn btn-primary" style="display:inline-block;margin-top:1rem">Sign In</a>
        <?php elseif ($confirm): ?>
            <h2><?= $change !== null ? 'Confirm your new email address' : 'Verify your email' ?></h2>
            <p class="subtitle"><?= $change !== null
                ? 'Click below to make <strong>' . htmlspecialchars($change) . '</strong> the email address on your account.'
                : ($needs_pw ? 'Enter the password you chose when you signed up to confirm this address and activate the account.'
                             : 'Click below to confirm your email address and activate your account.') ?></p>
            <?php if ($error !== ''): ?><div class="alert alert-error" style="text-align:left"><?= htmlspecialchars($error) ?></div><?php endif; ?>
            <form method="post" action="/verify_email.php" style="margin-top:1rem">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="token" value="<?= htmlspecialchars($token_raw) ?>">
                <?php if ($needs_pw): ?>
                <div class="form-group" style="text-align:left">
                    <label for="vpw">Password</label>
                    <input type="password" id="vpw" name="password" required autocomplete="current-password">
                </div>
                <?php endif; ?>
                <button type="submit" class="btn btn-primary" style="display:inline-block"><?= $change !== null ? 'Confirm New Email Address' : 'Verify Email Address' ?></button>
            </form>
            <?php if ($needs_pw): ?>
            <p class="hint" style="margin-top:1rem;text-align:left">Did not sign up? Then someone else typed your address, and nothing happens unless the password is entered: you can ignore this email. To use this address yourself, <a href="/forgot_password.php">reset the password</a>; the reset is sent to this inbox and makes the account yours.</p>
            <?php endif; ?>
        <?php else: ?>
            <h2>Verification Failed</h2>
            <div class="alert alert-error" style="text-align:left"><?= htmlspecialchars($error) ?></div>
            <p style="margin-top:1rem;font-size:.875rem;color:var(--fg-64748b, #64748b)">
                <a href="/resend_verification.php">Request a new verification link</a>
            </p>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
