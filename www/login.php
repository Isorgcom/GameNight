<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/totp.php';

// Already logged in
if (current_user()) { header('Location: /'); exit; }

session_start_safe();

$error    = '';
$redirect = $_POST['redirect'] ?? $_GET['redirect'] ?? '';
// Only allow local paths to prevent open-redirect attacks
if ($redirect === '' || !str_starts_with($redirect, '/') || str_starts_with($redirect, '//')) {
    $redirect = '/';
}

// Brute-force cap for the second factor (per account, across IPs, 15 min).
function _login_mfa_locked(int $uid): bool {
    $s = get_db()->prepare("SELECT COUNT(*) FROM activity_log WHERE action = ? AND created_at > datetime('now', '-15 minutes')");
    $s->execute(['failed_mfa: ' . $uid]);
    return (int)$s->fetchColumn() >= 8;
}

// "Use a different account" — clear a pending second-factor and start over.
if (($_GET['reset'] ?? '') === '1') {
    unset($_SESSION['mfa_user_id'], $_SESSION['mfa_method'], $_SESSION['mfa_remember'], $_SESSION['mfa_redirect'], $_SESSION['mfa_identifier']);
    header('Location: /login.php'); exit;
}

// Resend an SMS code while on the second-factor step.
if (($_GET['resend'] ?? '') === '1' && !empty($_SESSION['mfa_user_id']) && ($_SESSION['mfa_method'] ?? '') === 'sms') {
    $pu = (int)$_SESSION['mfa_user_id'];
    $ph = get_db()->prepare('SELECT phone FROM users WHERE id = ?'); $ph->execute([$pu]);
    if ($phone = $ph->fetchColumn()) send_mfa_sms_code($pu, $phone);
    header('Location: /login.php'); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error = 'Invalid request. Please try again.';
    } else {
        $pendingUid = (int)($_SESSION['mfa_user_id'] ?? 0);
        $code       = trim($_POST['code'] ?? '');
        $recovery   = trim($_POST['recovery_code'] ?? '');

        if ($pendingUid && ($code !== '' || $recovery !== '')) {
            // ── Second-factor step: verify the code (or a recovery code) ──
            $method = $_SESSION['mfa_method'] ?? 'totp';
            if (_login_mfa_locked($pendingUid)) {
                $error = 'Too many incorrect attempts. Please wait a few minutes and try again.';
            } else {
                $ur = get_db()->prepare('SELECT mfa_totp_secret FROM users WHERE id = ?');
                $ur->execute([$pendingUid]);
                $secretEnc = (string)($ur->fetchColumn() ?: '');
                $ok = false;
                if ($recovery !== '') {
                    $ok = mfa_consume_recovery_code($pendingUid, $recovery);
                    if (!$ok) $error = 'That recovery code is not valid (or was already used).';
                } else {
                    $clean = preg_replace('/\D/', '', $code);
                    if (preg_match('/^\d{6}$/', $clean)) {
                        if ($method === 'totp') {
                            $secret = decrypt_value($secretEnc);
                            $ok = $secret !== '' && totp_verify($secret, $clean);
                        } else {
                            $r = verify_mfa_sms_code($pendingUid, $clean);
                            $ok = ($r === 'ok');
                            if (!$ok) $error = ($r === 'expired') ? 'That code expired. Tap "Resend code" below.'
                                            : (($r === 'exhausted') ? 'Too many attempts. Tap "Resend code" below.' : '');
                        }
                    }
                }
                if ($ok) {
                    $remember = !empty($_SESSION['mfa_remember']);
                    $dest     = $_SESSION['mfa_redirect'] ?? '/';
                    if (!str_starts_with($dest, '/') || str_starts_with($dest, '//')) $dest = '/';
                    unset($_SESSION['mfa_user_id'], $_SESSION['mfa_method'], $_SESSION['mfa_remember'], $_SESSION['mfa_redirect'], $_SESSION['mfa_identifier']);
                    complete_login($pendingUid);
                    if ($remember) issue_remember_token($pendingUid);
                    $fresh = current_user();
                    if (!empty($fresh['must_change_password'])) { header('Location: /settings.php?must_change=1'); exit; }
                    header('Location: ' . $dest); exit;
                } else {
                    db_log_anon_activity('failed_mfa: ' . $pendingUid, 'critical');
                    if ($error === '') $error = 'Incorrect code. Please try again.';
                }
            }
        } else {
            // ── Credential step: identifier + password ──
            $identifier = trim($_POST['identifier'] ?? $_POST['email'] ?? '');
            $password   = $_POST['password'] ?? '';
            if ($identifier === '' || $password === '') {
                $error = 'Enter your email, username, or phone, plus your password.';
            } else {
                $result = attempt_login($identifier, $password);
                if ($result === true) {
                    $u = current_user();
                    if (!empty($_POST['remember_me']) && $u) issue_remember_token((int)$u['id']);
                    if (!empty($u['must_change_password'])) { header('Location: /settings.php?must_change=1'); exit; }
                    header('Location: ' . $redirect); exit;
                } elseif ($result === 'mfa_required') {
                    // Password OK; the account has a second factor. Stay on this page and
                    // reveal the code field under the password (attempt_login already set
                    // $_SESSION['mfa_user_id']/['mfa_method']). This keeps username+password+
                    // code on one login form so password managers can fill the code.
                    $_SESSION['mfa_remember']   = !empty($_POST['remember_me']);
                    $_SESSION['mfa_redirect']   = $redirect;
                    $_SESSION['mfa_identifier'] = $identifier;
                    if (($_SESSION['mfa_method'] ?? '') === 'sms') {
                        $pu = (int)$_SESSION['mfa_user_id'];
                        $ph = get_db()->prepare('SELECT phone FROM users WHERE id = ?'); $ph->execute([$pu]);
                        if ($phone = $ph->fetchColumn()) send_mfa_sms_code($pu, $phone);
                    }
                } elseif ($result === 'unverified') {
                    $q = strpos($identifier, '@') !== false ? 'email=' . urlencode($identifier) : 'phone=' . urlencode($identifier);
                    $error = 'Please verify your account before signing in. <a href="/resend_verification.php?' . $q . '">Resend verification</a>';
                } elseif ($result === 'rate_limited') {
                    $error = 'Too many failed login attempts. Please try again in 15 minutes.';
                } else {
                    $error = 'Invalid login.';
                }
            }
        }
    }
}

// Whenever a second factor is pending, render the code-under-password step.
$mfaStep       = !empty($_SESSION['mfa_user_id']);
$mfaMethod     = $_SESSION['mfa_method'] ?? 'totp';
$mfaIdentifier = $_SESSION['mfa_identifier'] ?? ($_POST['identifier'] ?? $_POST['email'] ?? '');

$token = csrf_token();
$site_name = get_setting('site_name', 'Game Night');
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

<?php $nav_active = ''; require __DIR__ . '/_nav.php'; ?>

<div class="card-wrap">
    <div class="card">
        <?php $_login_banner = get_setting('header_banner_path', ''); if ($_login_banner): ?>
        <div style="text-align:center;margin-bottom:.75rem">
            <a href="/"><img src="<?= htmlspecialchars($_login_banner) ?>" alt="<?= htmlspecialchars($site_name) ?>" style="max-height:60px;width:auto"></a>
        </div>
        <?php endif; ?>
        <h2>Sign In</h2>
        <p class="subtitle"><?= $mfaStep ? 'Enter your two-factor code to finish signing in.' : 'Enter your credentials to access the dashboard.' ?></p>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= $error ?></div>
        <?php endif; ?>

        <form method="post" action="/login.php" novalidate>
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">
            <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect) ?>">

            <div class="form-group">
                <label for="identifier">Email, username, or phone</label>
                <input type="text" id="identifier" name="identifier"
                       value="<?= htmlspecialchars($mfaStep ? $mfaIdentifier : ($_POST['identifier'] ?? $_POST['email'] ?? '')) ?>"
                       autocomplete="username" <?= $mfaStep ? 'readonly' : 'autofocus' ?> required>
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <div style="position:relative; display:block;">
                    <input type="password" id="password" name="password"
                           autocomplete="current-password" <?= $mfaStep ? '' : 'required' ?>
                           style="width:100%; padding-right:2.5rem;">
                    <button type="button" aria-label="Show password"
                            style="position:absolute; right:8px; top:50%; transform:translateY(-50%); background:none; border:none; cursor:pointer; padding:4px; color:#94a3b8; display:flex; align-items:center; -webkit-tap-highlight-color:transparent;">
                        <svg class="eye-show" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="pointer-events:none; display:block;"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        <svg class="eye-hide" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="pointer-events:none; display:none;"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                    </button>
                </div>
            </div>

            <?php if ($mfaStep): ?>
            <!-- Second factor on the SAME login form (username + password + one-time-code),
                 so password managers (1Password) recognize the page and fill the code. -->
            <div class="form-group">
                <label for="code"><?= $mfaMethod === 'sms' ? 'Texted code' : 'Authenticator code' ?></label>
                <input type="text" id="code" name="code" inputmode="numeric" pattern="[0-9]*" maxlength="6"
                       autocomplete="one-time-code" autofocus required placeholder="000000"
                       style="width:100%;text-align:center;letter-spacing:.25em;font-size:1.2rem">
                <?php if ($mfaMethod === 'sms'): ?>
                <p class="hint" style="margin-top:.35rem">Didn't get it? <a href="/login.php?resend=1">Resend code</a></p>
                <?php endif; ?>
            </div>
            <details style="margin-bottom:.5rem">
                <summary style="cursor:pointer;font-size:.8rem;color:#94a3b8">Lost your device? Use a recovery code</summary>
                <div class="form-group" style="margin-top:.5rem">
                    <input type="text" name="recovery_code" placeholder="xxxxx-xxxxx"
                           autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false"
                           style="width:100%;text-align:center;letter-spacing:.1em">
                </div>
            </details>
            <?php endif; ?>

            <div style="display:flex;align-items:center;justify-content:space-between;margin-top:.75rem">
                <label style="display:flex;align-items:center;gap:.4rem;font-size:.85rem;color:#64748b;cursor:pointer">
                    <input type="checkbox" name="remember_me" value="1"<?= !empty($_SESSION['mfa_remember']) ? ' checked' : '' ?>> Remember me
                </label>
                <?php if ($mfaStep): ?>
                    <a href="/login.php?reset=1" style="font-size:.8rem;color:#64748b">Use a different account</a>
                <?php else: ?>
                    <a href="/forgot_password.php" style="font-size:.8rem;color:#64748b">Forgot password?</a>
                <?php endif; ?>
            </div>

            <button type="submit" class="btn btn-primary" style="width:100%;margin-top:.75rem">
                <?= $mfaStep ? 'Verify &amp; sign in' : 'Sign In' ?>
            </button>
        </form>

        <?php if (!$mfaStep && get_setting('allow_registration', '1') === '1'): ?>
        <p style="text-align:center;margin-top:1.25rem;font-size:.875rem;color:#64748b">
            Don't have an account? <a href="/register.php">Sign up</a>
        </p>
        <?php endif; ?>

    </div>
</div>

<script>
document.querySelectorAll('button[aria-label="Show password"], button[aria-label="Hide password"]').forEach(function(btn) {
    function toggle(e) {
        e.preventDefault();
        var input = btn.parentElement.querySelector('input');
        var show  = input.type === 'password';
        input.type = show ? 'text' : 'password';
        btn.querySelector('.eye-show').style.display = show ? 'none' : 'block';
        btn.querySelector('.eye-hide').style.display = show ? 'block' : 'none';
        btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    }
    btn.addEventListener('click', toggle);
    btn.addEventListener('touchend', toggle);
});
</script>
</body>
</html>
