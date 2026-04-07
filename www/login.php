<?php
require_once __DIR__ . '/auth.php';

// Already logged in
if (current_user()) {
    header('Location: /');
    exit;
}

$error    = '';
$redirect = $_POST['redirect'] ?? $_GET['redirect'] ?? '';
// Only allow local paths to prevent open-redirect attacks
if ($redirect === '' || !str_starts_with($redirect, '/') || str_starts_with($redirect, '//')) {
    $redirect = '/';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    session_start_safe();
    if (!csrf_verify()) {
        $error = 'Invalid request. Please try again.';
    } else {
        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($email === '' || $password === '') {
            $error = 'Email and password are required.';
        } else {
            $result = attempt_login($email, $password);
            if ($result === true) {
                // Extend session if "Remember me" checked (30 days)
                if (!empty($_POST['remember_me'])) {
                    $params = session_get_cookie_params();
                    setcookie(session_name(), session_id(), time() + 86400 * 30, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
                }
                $u = current_user();
                if (!empty($u['must_change_password'])) {
                    header('Location: /settings.php?must_change=1');
                    exit;
                }
                header('Location: ' . $redirect);
                exit;
            } elseif ($result === 'unverified') {
                $error = 'Please verify your email address before signing in. <a href="/resend_verification.php?email=' . urlencode($email) . '">Resend verification email</a>';
            } elseif ($result === 'rate_limited') {
                $error = 'Too many failed login attempts. Please try again in 15 minutes.';
            } else {
                $error = 'Invalid email or password.';
            }
        }
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
        <h2>Sign In</h2>
        <p class="subtitle">Enter your credentials to access the dashboard.</p>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= $error ?></div>
        <?php endif; ?>

        <form method="post" action="/login.php" novalidate>
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">
            <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect) ?>">

            <div class="form-group">
                <label for="email">Email</label>
                <input type="email" id="email" name="email"
                       value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                       autocomplete="email" autofocus required>
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
                    <input type="checkbox" name="remember_me" value="1"> Remember me
                </label>
                <a href="/forgot_password.php" style="font-size:.8rem;color:#64748b">Forgot password?</a>
            </div>

            <button type="submit" class="btn btn-primary" style="width:100%;margin-top:.75rem">
                Sign In
            </button>
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
