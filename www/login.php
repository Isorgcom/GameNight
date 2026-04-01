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
                header('Location: ' . $redirect);
                exit;
            } elseif ($result === 'unverified') {
                $error = 'Please verify your email address before signing in. <a href="/resend_verification.php?email=' . urlencode($email) . '">Resend verification email</a>';
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

<nav>
    <div class="nav-top">
        <a class="brand" href="/"><?= htmlspecialchars($site_name) ?></a>
    </div>
</nav>

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
                <input type="password" id="password" name="password"
                       autocomplete="current-password" required>
            </div>

            <button type="submit" class="btn btn-primary" style="width:100%;margin-top:.5rem">
                Sign In
            </button>
            <p style="text-align:right;margin-top:.6rem;font-size:.8rem">
                <a href="/forgot_password.php" style="color:#64748b">Forgot password?</a>
            </p>
        </form>

        <?php if (get_setting('allow_registration', '1') === '1'): ?>
        <p style="text-align:center;margin-top:1.25rem;font-size:.875rem;color:#64748b">
            Don't have an account? <a href="/register.php">Sign up</a>
        </p>
        <?php endif; ?>

    </div>
</div>

</body>
</html>
