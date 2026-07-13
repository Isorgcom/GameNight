<?php
/**
 * Multi-Factor Authentication challenge.
 *
 * Reached after a correct password when the account has MFA enabled
 * (attempt_login() returns 'mfa_required' and stashes mfa_user_id/mfa_method).
 * The user enters a TOTP/SMS code OR a single-use recovery code; on success we
 * finalize the deferred login via complete_login() and honor the remember-me
 * intent carried over from login.php.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/totp.php';

session_start_safe();

// The two-factor step is now handled inline on the login page (the code field
// appears under the password). This standalone page is retired; send any
// pending second-factor session there so password managers can fill the code.
header('Location: /login.php');
exit;

$site_name = get_setting('site_name', 'Game Night');
$error     = '';

$user_id = (int)($_SESSION['mfa_user_id'] ?? 0);
if (!$user_id) {
    header('Location: /login.php');
    exit;
}

$u = get_db()->prepare('SELECT id, username, email, phone, mfa_method, mfa_totp_secret FROM users WHERE id = ?');
$u->execute([$user_id]);
$user = $u->fetch();
if (!$user) {
    unset($_SESSION['mfa_user_id'], $_SESSION['mfa_method'], $_SESSION['mfa_remember'], $_SESSION['mfa_redirect']);
    header('Location: /login.php');
    exit;
}
$method = $_SESSION['mfa_method'] ?? ($user['mfa_method'] ?: 'totp');

// Brute-force cap: too many failed challenges for this account (across IPs) in
// the last 15 minutes locks the screen briefly. Mirrors login_rate_limited().
function mfa_challenge_locked(int $user_id): bool {
    $stmt = get_db()->prepare(
        "SELECT COUNT(*) FROM activity_log WHERE action = ? AND created_at > datetime('now', '-15 minutes')"
    );
    $stmt->execute(['failed_mfa: ' . $user_id]);
    return (int)$stmt->fetchColumn() >= 8;
}
$locked = mfa_challenge_locked($user_id);

// Masked phone for the SMS prompt (e.g. ••• ••• 1234).
$phone_mask = '';
if (($user['phone'] ?? '') !== '') {
    $digits = preg_replace('/\D/', '', $user['phone']);
    $phone_mask = '••• ••• ' . substr($digits, -4);
}

// Send (or resend) an SMS code on GET for the SMS method. POST re-renders after
// a wrong code do NOT resend.
if ($method === 'sms' && $_SERVER['REQUEST_METHOD'] === 'GET' && !$locked && ($user['phone'] ?? '') !== '') {
    send_mfa_sms_code($user_id, $user['phone']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error = 'Invalid request. Please try again.';
    } elseif ($locked) {
        $error = 'Too many incorrect attempts. Please wait a few minutes and try again.';
    } else {
        // Two separate inputs: a clean numeric OTP field (so password managers /
        // iOS / Android reliably autofill it) and an optional recovery-code field.
        $recovery = trim($_POST['recovery_code'] ?? '');
        $clean    = preg_replace('/\D/', '', $_POST['code'] ?? '');
        $ok       = false;

        if ($recovery !== '') {
            $ok = mfa_consume_recovery_code($user_id, $recovery);
            if (!$ok) $error = 'That recovery code is not valid (or was already used).';
        } elseif (preg_match('/^\d{6}$/', $clean)) {
            // Factor code (TOTP or SMS).
            if ($method === 'totp') {
                $secret = decrypt_value($user['mfa_totp_secret'] ?? '');
                $ok = $secret !== '' && totp_verify_consume(get_db(), $user_id, $secret, $clean);
            } else { // sms
                $r = verify_mfa_sms_code($user_id, $clean);
                if ($r === 'ok') {
                    $ok = true;
                } elseif ($r === 'expired') {
                    $error = 'That code has expired. <a href="/mfa_challenge.php">Send a new code</a>.';
                } elseif ($r === 'exhausted') {
                    $error = 'Too many incorrect attempts. <a href="/mfa_challenge.php">Send a new code</a>.';
                }
            }
        }

        if ($ok) {
            $remember = !empty($_SESSION['mfa_remember']);
            $redirect = $_SESSION['mfa_redirect'] ?? '/';
            if (!str_starts_with($redirect, '/') || str_starts_with($redirect, '//')) {
                $redirect = '/';
            }
            unset($_SESSION['mfa_user_id'], $_SESSION['mfa_method'], $_SESSION['mfa_remember'], $_SESSION['mfa_redirect']);
            complete_login($user_id);
            if ($remember) issue_remember_token($user_id);

            $fresh = current_user();
            if (!empty($fresh['must_change_password'])) {
                header('Location: /settings.php?must_change=1');
                exit;
            }
            header('Location: ' . $redirect);
            exit;
        } elseif ($error === '') {
            db_log_anon_activity('failed_mfa: ' . $user_id, 'critical');
            $error = 'Incorrect code. Please try again.';
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
    <title>Two-Factor Verification — <?= htmlspecialchars($site_name) ?></title>
    <link rel="stylesheet" href="/style.css?v=<?= htmlspecialchars(APP_VERSION) ?>">
</head>
<body>

<?php $nav_active = ''; $nav_user = null; require __DIR__ . '/_nav.php'; ?>

<div class="card-wrap">
    <div class="card">
        <h2>Two-Factor Verification</h2>
        <?php if ($method === 'sms'): ?>
        <p class="subtitle">Enter the 6-digit code we texted to <?= htmlspecialchars($phone_mask) ?>.</p>
        <?php else: ?>
        <p class="subtitle">Enter the 6-digit code from your authenticator app.</p>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= $error ?></div>
        <?php endif; ?>

        <form method="post" action="/mfa_challenge.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">
            <!-- Visible, real account field: lets password managers (1Password) associate this
                 2FA page with the saved login so they offer its stored one-time code. iOS/1Password
                 ignore off-screen hints, so this is a genuine readonly username field. The handler
                 ignores any posted "username" (it keys off the session). -->
            <div class="form-group">
                <label for="mfa-acct">Account</label>
                <input type="text" id="mfa-acct" name="username" autocomplete="username" readonly
                       value="<?= htmlspecialchars(($user['email'] ?? '') !== '' ? $user['email'] : $user['username']) ?>"
                       style="width:100%;text-align:center;padding:.5rem .6rem;border:1.5px solid #e2e8f0;border-radius:8px;background:#f8fafc;color:#475569">
            </div>
            <div class="form-group" style="text-align:center">
                <!-- Dedicated 6-digit numeric field: maximizes one-time-code autofill
                     detection by 1Password / iOS / Android (clean numeric, maxlength 6). -->
                <input type="text" name="code" id="otp-code" placeholder="000000" maxlength="6"
                       inputmode="numeric" pattern="[0-9]*" autocomplete="one-time-code"
                       required autofocus aria-label="One-time code"
                       style="width:200px;font-size:1.5rem;text-align:center;letter-spacing:.25em;padding:.6rem;border:2px solid #e2e8f0;border-radius:10px">
            </div>
            <button type="submit" class="btn btn-primary" style="width:100%">Verify</button>
        </form>

        <?php if ($method === 'sms'): ?>
        <p style="text-align:center;margin-top:1rem;font-size:.875rem;color:#64748b">
            Didn't get it? <a href="/mfa_challenge.php">Resend code</a>
        </p>
        <?php endif; ?>

        <details style="margin-top:1rem">
            <summary style="cursor:pointer;text-align:center;font-size:.8rem;color:#94a3b8">Lost your device? Use a recovery code</summary>
            <form method="post" action="/mfa_challenge.php" style="margin-top:.6rem">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">
                <div class="form-group">
                    <input type="text" name="recovery_code" placeholder="xxxxx-xxxxx"
                           autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false"
                           aria-label="Recovery code"
                           style="width:100%;text-align:center;letter-spacing:.1em;padding:.55rem;border:1.5px solid #e2e8f0;border-radius:8px">
                </div>
                <button type="submit" class="btn" style="width:100%">Use recovery code</button>
            </form>
        </details>

        <p style="text-align:center;margin-top:.75rem;font-size:.8rem;color:#94a3b8">
            <a href="/login.php">Back to sign in</a>
        </p>
    </div>
</div>
</body>
</html>
