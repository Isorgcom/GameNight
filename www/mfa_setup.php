<?php
/**
 * Two-Factor Authentication setup — full-page enrollment flow.
 *
 * Reached from Settings → Two-Factor Authentication. Owns the whole enable
 * lifecycle: choose method → enroll (TOTP QR / SMS code) → confirm → show
 * one-time recovery codes. Disabling 2FA stays on settings.php.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/totp.php';

$current = require_login();
$db      = get_db();
session_start_safe();

$me = $db->prepare('SELECT username, email, phone, phone_verified, mfa_enabled, mfa_method FROM users WHERE id = ?');
$me->execute([$current['id']]);
$me = $me->fetch();

$mfa_on        = (int)($me['mfa_enabled'] ?? 0) === 1;
$sms_available = !empty($me['phone']) && (int)($me['phone_verified'] ?? 0) === 1 && get_setting('sms_provider', '') !== '';

$error      = '';
$show_codes = null;   // array of plaintext recovery codes to display once

// Cancel an in-progress enrollment.
if (isset($_GET['cancel'])) {
    unset($_SESSION['mfa_setup']);
    header('Location: /settings.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error = 'Invalid request. Please try again.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'choose_totp') {
            $_SESSION['mfa_setup'] = ['method' => 'totp', 'secret' => totp_generate_secret()];
        }

        elseif ($action === 'choose_sms' || $action === 'resend_sms') {
            if (!$sms_available) {
                $error = 'Add and verify a phone number on your profile before using SMS two-factor.';
            } elseif ($action === 'resend_sms' && mfa_sms_resend_rate_limited((int)$current['id'])) {
                // Throttle resends (SMS toll/flooding); the initial choose_sms send is not capped.
                $_SESSION['mfa_setup'] = ['method' => 'sms'];
                $error = 'Please wait a bit before requesting another code.';
            } else {
                send_mfa_sms_code($current['id'], $me['phone']);
                if ($action === 'resend_sms') db_log_activity((int)$current['id'], 'mfa_sms_resend');
                $_SESSION['mfa_setup'] = ['method' => 'sms'];
            }
        }

        elseif ($action === 'confirm_totp') {
            $setup = $_SESSION['mfa_setup'] ?? null;
            $code  = preg_replace('/\s/', '', $_POST['code'] ?? '');
            if (!$setup || ($setup['method'] ?? '') !== 'totp' || empty($setup['secret'])) {
                $error = 'Setup expired. Please start again.';
            } elseif (!totp_verify($setup['secret'], $code)) {
                $error = 'That code did not match. Check your app and try again.';
            } else {
                $db->prepare("UPDATE users SET mfa_enabled = 1, mfa_method = 'totp', mfa_totp_secret = ?, mfa_offer_dismissed = 1 WHERE id = ?")
                   ->execute([encrypt_value($setup['secret']), $current['id']]);
                unset($_SESSION['mfa_setup']);
                $show_codes = mfa_generate_recovery_codes($current['id']);
                $mfa_on = true;
                db_log_activity($current['id'], 'enabled MFA (authenticator app)');
            }
        }

        elseif ($action === 'confirm_sms') {
            $setup = $_SESSION['mfa_setup'] ?? null;
            $code  = preg_replace('/\s/', '', $_POST['code'] ?? '');
            if (!$setup || ($setup['method'] ?? '') !== 'sms') {
                $error = 'Setup expired. Please start again.';
            } else {
                $res = verify_mfa_sms_code($current['id'], $code);
                if ($res === 'ok') {
                    $db->prepare("UPDATE users SET mfa_enabled = 1, mfa_method = 'sms', mfa_totp_secret = NULL, mfa_offer_dismissed = 1 WHERE id = ?")
                       ->execute([$current['id']]);
                    unset($_SESSION['mfa_setup']);
                    $show_codes = mfa_generate_recovery_codes($current['id']);
                    $mfa_on = true;
                    db_log_activity($current['id'], 'enabled MFA (SMS)');
                } elseif ($res === 'expired') {
                    $error = 'That code expired. Send a new one below.';
                } elseif ($res === 'exhausted') {
                    $error = 'Too many attempts. Send a new code below.';
                } else {
                    $error = 'Incorrect code. Please try again.';
                }
            }
        }

        elseif ($action === 'regen') {
            if ($mfa_on) {
                $show_codes = mfa_generate_recovery_codes($current['id']);
                db_log_activity($current['id'], 'regenerated MFA recovery codes');
            } else {
                $error = 'Two-factor authentication is not enabled.';
            }
        }
    }
}

// Decide which step to render.
$setup     = $_SESSION['mfa_setup'] ?? null;
$totp_uri  = ($setup && ($setup['method'] ?? '') === 'totp')
    ? totp_provisioning_uri($setup['secret'], $me['email'] ?: $me['username'], get_setting('site_name', 'Game Night'))
    : '';

// Landing here with 2FA already on and nothing in flight → back to settings.
if (!$show_codes && !$setup && $mfa_on) {
    header('Location: /settings.php');
    exit;
}

// Masked phone for the SMS step.
$phone_mask = '';
if (($me['phone'] ?? '') !== '') {
    $d = preg_replace('/\D/', '', $me['phone']);
    $phone_mask = '••• ••• ' . substr($d, -4);
}

$token     = csrf_token();
$site_name = get_setting('site_name', 'Game Night');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Two-Factor Setup — <?= htmlspecialchars($site_name) ?></title>
    <link rel="stylesheet" href="/style.css?v=<?= htmlspecialchars(APP_VERSION . '.' . (@filemtime(__DIR__ . '/style.css') ?: 0)) ?>">
</head>
<body>

<?php $nav_active = 'settings'; $nav_user = $me; require __DIR__ . '/_nav.php'; ?>

<div class="card-wrap">
    <div class="card" style="max-width:480px">

        <?php if ($error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if ($show_codes): ?>
            <!-- Step 3: recovery codes (shown once) -->
            <h2>Save your recovery codes</h2>
            <p class="subtitle">Two-factor authentication is on. Each code below works once if you lose access to your second factor. They will <strong>not</strong> be shown again, so store them somewhere safe now.</p>
            <div style="margin:1rem 0;display:grid;grid-template-columns:1fr 1fr;gap:.5rem 1rem;font-family:monospace;font-size:1.1rem;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:1.1rem">
                <?php foreach ($show_codes as $rc): ?>
                <span><?= htmlspecialchars($rc) ?></span>
                <?php endforeach; ?>
            </div>
            <a href="/settings.php" class="btn btn-primary" style="width:100%;display:block;text-align:center;text-decoration:none">I've saved my codes — Done</a>

        <?php elseif ($setup && ($setup['method'] ?? '') === 'totp'): ?>
            <!-- Step 2a: authenticator enrollment -->
            <h2>Set up authenticator app</h2>
            <p class="subtitle">1. Scan this QR code with Google Authenticator, Authy, 1Password, or any TOTP app.</p>
            <div id="mfaQr" style="display:flex;justify-content:center;margin:1rem 0"></div>
            <p style="font-size:.85rem;color:#64748b">Can't scan? Enter this key manually:<br>
                <code style="font-size:1.05rem;word-break:break-all"><?= htmlspecialchars($setup['secret']) ?></code>
            </p>
            <form method="post" action="/mfa_setup.php" style="margin-top:1rem">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">
                <input type="hidden" name="action" value="confirm_totp">
                <div class="form-group">
                    <label for="code">2. Enter the 6-digit code from your app</label>
                    <input type="text" id="code" name="code" inputmode="numeric"
                           autocomplete="one-time-code" maxlength="6" pattern="\d{6}" required autofocus
                           placeholder="000000" style="letter-spacing:.3em;font-size:1.3rem;text-align:center">
                </div>
                <button type="submit" class="btn btn-primary" style="width:100%">Confirm &amp; Enable</button>
            </form>
            <p style="text-align:center;margin-top:1rem;font-size:.875rem"><a href="/mfa_setup.php?cancel=1">Cancel</a></p>

        <?php elseif ($setup && ($setup['method'] ?? '') === 'sms'): ?>
            <!-- Step 2b: SMS enrollment -->
            <h2>Set up SMS codes</h2>
            <p class="subtitle">Enter the 6-digit code we texted to <?= htmlspecialchars($phone_mask) ?>.</p>
            <form method="post" action="/mfa_setup.php">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">
                <input type="hidden" name="action" value="confirm_sms">
                <div class="form-group">
                    <input type="text" id="code" name="code" inputmode="numeric"
                           autocomplete="one-time-code" maxlength="6" pattern="\d{6}" required autofocus
                           placeholder="000000" style="letter-spacing:.3em;font-size:1.3rem;text-align:center">
                </div>
                <button type="submit" class="btn btn-primary" style="width:100%">Confirm &amp; Enable</button>
            </form>
            <form method="post" action="/mfa_setup.php" style="text-align:center;margin-top:1rem">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">
                <input type="hidden" name="action" value="resend_sms">
                <button type="submit" class="btn-link" style="background:none;border:none;color:#2563eb;cursor:pointer;font-size:.875rem">Resend code</button>
            </form>
            <p style="text-align:center;margin-top:.5rem;font-size:.875rem"><a href="/mfa_setup.php?cancel=1">Cancel</a></p>

        <?php else: ?>
            <!-- Step 1: choose a method -->
            <h2>Set up two-factor authentication</h2>
            <p class="subtitle">Add a second step at sign-in. Choose how you want to receive your codes.</p>

            <form method="post" action="/mfa_setup.php" style="margin-bottom:.75rem">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">
                <input type="hidden" name="action" value="choose_totp">
                <button type="submit" class="btn btn-primary" style="width:100%">Authenticator app</button>
                <p class="hint" style="margin-top:.3rem">Use a TOTP app like Google Authenticator, Authy, or 1Password. Works without a phone signal.</p>
            </form>

            <?php if ($sms_available): ?>
            <form method="post" action="/mfa_setup.php">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">
                <input type="hidden" name="action" value="choose_sms">
                <button type="submit" class="btn" style="width:100%">Text message (SMS)</button>
                <p class="hint" style="margin-top:.3rem">We'll text a 6-digit code to <?= htmlspecialchars($phone_mask) ?> each time you sign in.</p>
            </form>
            <?php else: ?>
            <p class="hint" style="margin-top:.4rem">To use SMS codes, add and verify a phone number on your <a href="/settings.php">profile</a> first.</p>
            <?php endif; ?>

            <p style="text-align:center;margin-top:1.25rem;font-size:.875rem"><a href="/settings.php">&larr; Back to Settings</a></p>
        <?php endif; ?>

    </div>
</div>

<?php if ($totp_uri): ?>
<script src="/vendor/qrcode.min.js" defer></script>
<script nonce="<?= csp_nonce() ?>">
// qrcode.min.js is deferred, so draw on DOMContentLoaded (deferred scripts
// are guaranteed to have run by then).
document.addEventListener('DOMContentLoaded', function () {
    var wrap = document.getElementById('mfaQr');
    if (!wrap || typeof qrcode === 'undefined') return;
    var qr = qrcode(0, 'M');
    qr.addData(<?= json_encode($totp_uri) ?>);
    qr.make();
    var img = document.createElement('img');
    img.src = qr.createDataURL(6, 4);
    img.width = 220; img.height = 220;
    img.style.imageRendering = 'pixelated';
    img.alt = 'Authenticator QR code';
    wrap.appendChild(img);
});
</script>
<?php endif; ?>
</body>
</html>
