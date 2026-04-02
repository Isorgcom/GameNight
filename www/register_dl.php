<?php
require_once __DIR__ . '/auth.php';

// Already logged in
if (current_user()) {
    header('Location: /');
    exit;
}

// Registration disabled
if (get_setting('allow_registration', '1') !== '1') {
    http_response_code(403);
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
    <nav><div class="nav-top"><a class="brand" href="/"><?= htmlspecialchars($site_name) ?></a></div></nav>
    <div class="card-wrap">
        <div class="card" style="text-align:center">
            <h2>Registration Closed</h2>
            <p class="subtitle">New account registration is not currently available.</p>
            <a href="/login.php" class="btn btn-primary" style="margin-top:1rem;display:inline-block">Back to Sign In</a>
        </div>
    </div>
    </body>
    </html>
    <?php
    exit;
}

$error            = '';
$registered_email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    session_start_safe();
    if (!csrf_verify()) {
        $error = 'Invalid request. Please try again.';
    } else {
        $username  = trim($_POST['username'] ?? '');
        $email     = trim($_POST['email'] ?? '');
        $phone     = trim($_POST['phone'] ?? '');
        $password  = $_POST['password'] ?? '';
        $password2 = $_POST['password2'] ?? '';

        if ($password !== $password2) {
            $error = 'Passwords do not match.';
        } else {
            $error = register_user($username, $email, $password, $phone) ?? '';
            if ($error === '') {
                $registered_email = $email;
            }
        }
    }
}

$token     = csrf_token();
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

<nav>
    <div class="nav-top">
        <a class="brand" href="/"><?= htmlspecialchars($site_name) ?></a>
    </div>
</nav>

<div class="card-wrap">
    <div class="card">
        <?php if ($registered_email): ?>
        <h2>Check Your Email</h2>
        <p class="subtitle">Account created!</p>
        <div class="alert alert-success">
            We've sent a verification link to <strong><?= htmlspecialchars($registered_email) ?></strong>.<br>
            Click the link in the email to activate your account.
        </div>
        <p style="text-align:center;margin-top:1.25rem;font-size:.875rem;color:#64748b">
            Didn't get it? <a href="/resend_verification.php?email=<?= urlencode($registered_email) ?>">Resend verification email</a>
        </p>
        <?php else: ?>
        <h2>Create Account</h2>
        <p class="subtitle">Join <?= htmlspecialchars($site_name) ?>.</p>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="post" action="/register.php" novalidate>
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">

            <div class="form-group">
                <label for="email">Email</label>
                <input type="email" id="email" name="email"
                       value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                       autocomplete="email" autofocus required>
                <p class="hint">Used to sign in. Never shown publicly.</p>
            </div>

            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username"
                       value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                       autocomplete="username" required
                       pattern="[a-zA-Z0-9_]{3,30}" maxlength="30">
                <p class="hint">Your display name — 3-30 characters, letters, numbers, underscores.</p>
            </div>

            <div class="form-group">
                <label for="phone">Phone <span style="color:#94a3b8;font-weight:400">(optional)</span></label>
                <input type="tel" id="phone" name="phone"
                       value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>"
                       autocomplete="tel">
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <div style="position:relative; display:block;">
                    <input type="password" id="password" name="password"
                           autocomplete="new-password" required minlength="8"
                           style="width:100%; padding-right:2.5rem;">
                    <button type="button" aria-label="Show password"
                            style="position:absolute; right:8px; top:50%; transform:translateY(-50%); background:none; border:none; cursor:pointer; padding:4px; color:#94a3b8; display:flex; align-items:center; -webkit-tap-highlight-color:transparent;">
                        <svg class="eye-show" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="pointer-events:none; display:block;"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        <svg class="eye-hide" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="pointer-events:none; display:none;"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                    </button>
                </div>
                <p class="hint">At least 8 characters.</p>
            </div>

            <div class="form-group">
                <label for="password2">Confirm Password</label>
                <div style="position:relative; display:block;">
                    <input type="password" id="password2" name="password2"
                           autocomplete="new-password" required minlength="8"
                           style="width:100%; padding-right:2.5rem;">
                    <button type="button" aria-label="Show password"
                            style="position:absolute; right:8px; top:50%; transform:translateY(-50%); background:none; border:none; cursor:pointer; padding:4px; color:#94a3b8; display:flex; align-items:center; -webkit-tap-highlight-color:transparent;">
                        <svg class="eye-show" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="pointer-events:none; display:block;"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        <svg class="eye-hide" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="pointer-events:none; display:none;"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                    </button>
                </div>
            </div>

            <button type="submit" class="btn btn-primary" style="width:100%;margin-top:.5rem">
                Create Account
            </button>
        </form>

        <p style="text-align:center;margin-top:1.25rem;font-size:.875rem;color:#64748b">
            Already have an account? <a href="/login.php">Sign in</a>
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
