<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/totp.php';

// Already logged in
if (current_user()) { header('Location: /'); exit; }

session_start_safe();

$error    = '';
$notice   = '';
$needCode = false;   // emphasize the code field (account has 2FA, awaiting code)
$redirect = $_POST['redirect'] ?? $_GET['redirect'] ?? '';
if ($redirect === '' || !str_starts_with($redirect, '/') || str_starts_with($redirect, '//')) {
    $redirect = '/';
}

// Per-account second-factor brute-force cap (across IPs, 15 min).
function _login_mfa_locked(int $uid): bool {
    $s = get_db()->prepare("SELECT COUNT(*) FROM activity_log WHERE action = ? AND created_at > datetime('now', '-15 minutes')");
    $s->execute(['failed_mfa: ' . $uid]);
    return (int)$s->fetchColumn() >= 8;
}

// Verify a submitted second factor for a user. Returns true on success; sets $error on failure.
function _login_verify_2fa(int $uid, string $method, string $code, string $recovery, ?string &$error): bool {
    if (_login_mfa_locked($uid)) { $error = 'Too many incorrect attempts. Please wait a few minutes and try again.'; return false; }
    if ($recovery !== '') {
        if (mfa_consume_recovery_code($uid, $recovery)) return true;
        db_log_anon_activity('failed_mfa: ' . $uid, 'critical');
        $error = 'That recovery code is not valid (or was already used).';
        return false;
    }
    $clean = preg_replace('/\D/', '', $code);
    if (preg_match('/^\d{6}$/', $clean)) {
        if ($method === 'totp') {
            $ur = get_db()->prepare('SELECT mfa_totp_secret FROM users WHERE id = ?'); $ur->execute([$uid]);
            $secret = decrypt_value((string)($ur->fetchColumn() ?: ''));
            if ($secret !== '' && totp_verify($secret, $clean)) return true;
        } else {
            $r = verify_mfa_sms_code($uid, $clean);
            if ($r === 'ok') return true;
            if ($r === 'expired')   { $error = 'That code expired. Tap "Resend code".'; db_log_anon_activity('failed_mfa: ' . $uid, 'critical'); return false; }
            if ($r === 'exhausted') { $error = 'Too many attempts. Tap "Resend code".'; db_log_anon_activity('failed_mfa: ' . $uid, 'critical'); return false; }
        }
    }
    db_log_anon_activity('failed_mfa: ' . $uid, 'critical');
    $error = 'Incorrect code. Please try again.';
    return false;
}

function _login_finish(int $uid, bool $remember, string $redirect): void {
    unset($_SESSION['mfa_user_id'], $_SESSION['mfa_method'], $_SESSION['mfa_remember'], $_SESSION['mfa_redirect']);
    complete_login($uid);
    if ($remember) issue_remember_token($uid);
    $fresh = current_user();
    if (!empty($fresh['must_change_password'])) { header('Location: /settings.php?must_change=1'); exit; }
    header('Location: ' . $redirect); exit;
}

// "Back to sign in": abandon the pending 2FA step and return to the clean first screen.
if (($_GET['reset'] ?? '') === '1') {
    unset($_SESSION['mfa_user_id'], $_SESSION['mfa_method'], $_SESSION['mfa_remember'], $_SESSION['mfa_redirect']);
    header('Location: /login.php'); exit;
}

// Resend an SMS code (link shown on the code prompt for SMS accounts).
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
        $identifier = trim($_POST['identifier'] ?? $_POST['email'] ?? '');
        $password   = $_POST['password'] ?? '';
        $code       = trim($_POST['code'] ?? '');
        $recovery   = trim($_POST['recovery_code'] ?? '');
        $remember   = !empty($_POST['remember_me']);
        $pendingUid = (int)($_SESSION['mfa_user_id'] ?? 0);

        if ($pendingUid && ($code !== '' || $recovery !== '')) {
            // Code submitted from the 2FA step (screen 2). The username/password are no
            // longer posted, so remember-me + redirect come from the session stashed at
            // the prompt step (falling back to anything posted this round).
            $method   = $_SESSION['mfa_method'] ?? 'totp';
            $remember = $remember || !empty($_SESSION['mfa_remember']);
            $dest     = $_SESSION['mfa_redirect'] ?? $redirect;
            if (_login_verify_2fa($pendingUid, $method, $code, $recovery, $error)) {
                _login_finish($pendingUid, $remember, $dest);
            } else { $needCode = true; }
        } elseif ($identifier === '' || $password === '') {
            $error = 'Enter your email, username, or phone, plus your password.';
        } else {
            $result = attempt_login($identifier, $password);
            if ($result === true) {
                $u = current_user();
                if ($remember && $u) issue_remember_token((int)$u['id']);
                if (!empty($u['must_change_password'])) { header('Location: /settings.php?must_change=1'); exit; }
                header('Location: ' . $redirect); exit;
            } elseif ($result === 'mfa_required') {
                // attempt_login set $_SESSION['mfa_user_id']/['mfa_method']. If a code was
                // filled alongside the login (1Password single-shot), verify it now;
                // otherwise prompt for it (the code field is already on the form).
                $uid    = (int)$_SESSION['mfa_user_id'];
                $method = $_SESSION['mfa_method'] ?? 'totp';
                if ($code !== '' || $recovery !== '') {
                    if (_login_verify_2fa($uid, $method, $code, $recovery, $error)) {
                        _login_finish($uid, $remember, $redirect);
                    } else { $needCode = true; }
                } else {
                    // Correct password, account has 2FA: advance to the code step
                    // (screen 2). Stash remember-me + redirect so they survive the
                    // second submit, which no longer carries the password.
                    $_SESSION['mfa_remember'] = $remember;
                    $_SESSION['mfa_redirect'] = $redirect;
                    if ($method === 'sms') {
                        $ph = get_db()->prepare('SELECT phone FROM users WHERE id = ?'); $ph->execute([$uid]);
                        if ($phone = $ph->fetchColumn()) send_mfa_sms_code($uid, $phone);
                        $notice = 'We texted you a code. Enter it below to finish signing in.';
                    } else {
                        $notice = 'Enter the code from your authenticator app to finish signing in.';
                    }
                    $needCode = true;
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

// A refresh/return while a code is pending keeps the prompt.
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && !empty($_SESSION['mfa_user_id'])) {
    $needCode = true;
    if (($_SESSION['mfa_method'] ?? 'totp') === 'sms') $notice = $notice ?: 'Enter the code we texted you to finish signing in.';
}

$mfaMethod = $_SESSION['mfa_method'] ?? 'totp';

// For the "Signing in as <account>" line on the code step: prefer what was just typed,
// otherwise look it up from the pending account (e.g. after a refresh).
$pendingDisplay = '';
if ($needCode) {
    $pendingDisplay = trim($_POST['identifier'] ?? $_POST['email'] ?? '');
    if ($pendingDisplay === '' && !empty($_SESSION['mfa_user_id'])) {
        $pd = get_db()->prepare('SELECT email, username FROM users WHERE id = ?');
        $pd->execute([(int)$_SESSION['mfa_user_id']]);
        if ($row = $pd->fetch()) $pendingDisplay = $row['email'] ?: $row['username'];
    }
}

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
        <?php if ($needCode): ?>
        <h2>Two-factor authentication</h2>
        <p class="subtitle">
            <?= $mfaMethod === 'sms' ? 'Enter the code we texted you' : 'Enter the code from your authenticator app' ?><?php if ($pendingDisplay !== ''): ?> to sign in as <strong><?= htmlspecialchars($pendingDisplay) ?></strong><?php endif; ?>.
        </p>
        <?php else: ?>
        <h2>Sign In</h2>
        <p class="subtitle">Enter your credentials to access the dashboard.</p>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= $error ?></div>
        <?php elseif ($notice): ?>
            <div class="alert alert-success"><?= htmlspecialchars($notice) ?></div>
        <?php endif; ?>

        <form method="post" action="/login.php" novalidate>
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">
            <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect) ?>">

        <?php if ($needCode): ?>
            <!-- Step 2: two-factor code. Reached only after a correct password on a 2FA
                 account; the verify path keys off the pending session, so there are no
                 username/password fields here. -->
            <div class="form-group">
                <label for="code">Two-factor code</label>
                <input type="text" id="code" name="code" inputmode="numeric" pattern="[0-9]*" maxlength="6"
                       autocomplete="one-time-code" placeholder="000000" autofocus
                       style="text-align:center;letter-spacing:.25em;font-size:1.25rem">
                <?php if ($mfaMethod === 'sms'): ?>
                <p class="hint" style="margin-top:.35rem">Didn't get it? <a href="/login.php?resend=1">Resend code</a></p>
                <?php endif; ?>
            </div>
            <details style="margin-bottom:.5rem">
                <summary style="cursor:pointer;font-size:.8rem;color:#94a3b8">Lost your authenticator? Use a recovery code</summary>
                <div class="form-group" style="margin-top:.5rem">
                    <input type="text" name="recovery_code" placeholder="xxxxx-xxxxx"
                           autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false"
                           style="text-align:center;letter-spacing:.1em">
                </div>
            </details>

            <button type="submit" class="btn btn-primary" style="width:100%;margin-top:.25rem">Verify &amp; sign in</button>
            <p style="text-align:center;margin-top:.85rem;font-size:.8rem">
                <a href="/login.php?reset=1" style="color:#64748b">Back to sign in</a>
            </p>
        <?php else: ?>
            <div class="form-group">
                <label for="identifier">Email, username, or phone</label>
                <input type="text" id="identifier" name="identifier"
                       value="<?= htmlspecialchars($_POST['identifier'] ?? $_POST['email'] ?? '') ?>"
                       autocomplete="username" autofocus required>
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <div style="position:relative; display:block;">
                    <input type="password" id="password" name="password"
                           autocomplete="current-password" required
                           style="width:100%; padding-right:2.5rem;">
                    <button type="button" aria-label="Show password"
                            style="position:absolute; right:8px; top:50%; transform:translateY(-50%); background:none; border:none; cursor:pointer; padding:4px; color:#94a3b8; display:flex; align-items:center; -webkit-tap-highlight-color:transparent;">
                        <svg class="eye-show" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="pointer-events:none; display:block;"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        <svg class="eye-hide" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="pointer-events:none; display:none;"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                    </button>
                </div>
            </div>

            <div style="display:flex;align-items:center;justify-content:space-between;margin-top:.75rem">
                <label style="display:flex;align-items:center;gap:.4rem;font-size:.85rem;color:#64748b;cursor:pointer">
                    <input type="checkbox" name="remember_me" value="1"<?= !empty($_POST['remember_me']) ? ' checked' : '' ?>> Remember me
                </label>
                <a href="/forgot_password.php" style="font-size:.8rem;color:#64748b">Forgot password?</a>
            </div>

            <button type="submit" class="btn btn-primary" style="width:100%;margin-top:.75rem">
                Sign In
            </button>
        <?php endif; ?>
        </form>

        <?php if (get_setting('allow_registration', '1') === '1'): ?>
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
