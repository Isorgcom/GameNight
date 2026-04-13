<?php
/**
 * Phone/WhatsApp verification — user enters 6-digit code sent via SMS or WhatsApp.
 */
require_once __DIR__ . '/auth.php';

session_start_safe();

$site_name = get_setting('site_name', 'Game Night');
$error     = '';
$success   = false;

$user_id = $_SESSION['verify_user_id'] ?? 0;
$method  = $_SESSION['verify_method'] ?? 'sms';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error = 'Invalid request. Please try again.';
    } elseif (!$user_id) {
        $error = 'Session expired. Please <a href="/register.php">register again</a>.';
    } else {
        $code = trim($_POST['code'] ?? '');
        if (!preg_match('/^\d{6}$/', $code)) {
            $error = 'Please enter a 6-digit code.';
        } else {
            $result = verify_code($user_id, $code);
            if ($result === 'ok') {
                $success = true;
                unset($_SESSION['verify_user_id'], $_SESSION['verify_method']);
            } elseif ($result === 'expired') {
                $error = 'Code has expired. <a href="/resend_verification.php">Resend a new code</a>.';
            } elseif ($result === 'exhausted') {
                $error = 'Too many incorrect attempts. <a href="/resend_verification.php">Resend a new code</a>.';
            } else {
                $error = 'Incorrect code. Please try again.';
            }
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
    <title>Verify Account — <?= htmlspecialchars($site_name) ?></title>
    <link rel="stylesheet" href="/style.css">
</head>
<body>

<?php $nav_active = ''; $nav_user = null; require __DIR__ . '/_nav.php'; ?>

<div class="card-wrap">
    <div class="card">
        <?php if ($success): ?>
        <h2>Account Verified!</h2>
        <div class="alert alert-success">
            Your account has been verified. You can now sign in.
        </div>
        <a href="/login.php" class="btn btn-primary" style="width:100%;margin-top:1rem;display:block;text-align:center;text-decoration:none">Sign In</a>

        <?php else: ?>
        <h2>Enter Verification Code</h2>
        <p class="subtitle">Enter the 6-digit code sent to your <?= $method === 'whatsapp' ? 'WhatsApp' : 'phone' ?>.</p>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= $error ?></div>
        <?php endif; ?>

        <form method="post" action="/verify_phone.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">
            <div class="form-group" style="text-align:center">
                <input type="text" name="code" placeholder="000000" maxlength="6" pattern="\d{6}"
                       inputmode="numeric" autocomplete="one-time-code" required autofocus
                       value="<?= htmlspecialchars($_POST['code'] ?? '') ?>"
                       style="width:180px;font-size:1.5rem;text-align:center;letter-spacing:.3em;padding:.6rem;border:2px solid #e2e8f0;border-radius:10px">
            </div>
            <button type="submit" class="btn btn-primary" style="width:100%">Verify</button>
        </form>

        <p style="text-align:center;margin-top:1rem;font-size:.875rem;color:#64748b">
            Didn't get it? <a href="/resend_verification.php">Resend code</a>
        </p>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
