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

$error    = '';
$redirect = $_POST['redirect'] ?? $_GET['redirect'] ?? '';
if ($redirect === '' || !str_starts_with($redirect, '/') || str_starts_with($redirect, '//')) {
    $redirect = '/';
}

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
                header('Location: ' . $redirect);
                exit;
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
        <h2>Create Account</h2>
        <p class="subtitle">Join <?= htmlspecialchars($site_name) ?>.</p>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="post" action="/register.php" novalidate>
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">
            <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect) ?>">

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
                <input type="password" id="password" name="password"
                       autocomplete="new-password" required minlength="8">
                <p class="hint">At least 8 characters.</p>
            </div>

            <div class="form-group">
                <label for="password2">Confirm Password</label>
                <input type="password" id="password2" name="password2"
                       autocomplete="new-password" required minlength="8">
            </div>

            <button type="submit" class="btn btn-primary" style="width:100%;margin-top:.5rem">
                Create Account
            </button>
        </form>

        <p style="text-align:center;margin-top:1.25rem;font-size:.875rem;color:#64748b">
            Already have an account? <a href="/login.php">Sign in</a>
        </p>
    </div>
</div>

</body>
</html>
