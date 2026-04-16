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
    <?php $nav_active = ''; require __DIR__ . '/_nav.php'; ?>
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

$error             = '';
$registered_email  = '';
$registered_method = 'email';
$registered_phone  = '';
$registered_uid    = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    session_start_safe();
    if (!csrf_verify()) {
        $error = 'Invalid request. Please try again.';
    } else {
        // Rate limit: max 5 registration attempts per IP per hour
        $db = get_db();
        $ip = function_exists('get_client_ip') ? get_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '');
        $rlStmt = $db->prepare("SELECT COUNT(*) FROM activity_log WHERE ip = ? AND action LIKE 'register_attempt%' AND created_at > datetime('now', '-1 hour')");
        $rlStmt->execute([$ip]);
        if ((int)$rlStmt->fetchColumn() >= 5) {
            $error = 'Too many registration attempts. Please try again in an hour.';
        } else {
            db_log_anon_activity('register_attempt');

            $username      = trim($_POST['username'] ?? '');
            $email         = trim($_POST['email'] ?? '');
            $phone         = trim($_POST['phone'] ?? '');
            $password      = $_POST['password'] ?? '';
            $password2     = $_POST['password2'] ?? '';
            $verify_method = in_array($_POST['verify_method'] ?? '', ['email', 'sms', 'whatsapp'], true)
                             ? $_POST['verify_method'] : 'email';

            if ($password !== $password2) {
                $error = 'Passwords do not match.';
            } elseif (in_array($verify_method, ['sms', 'whatsapp'], true) && empty($_POST['sms_consent'])) {
                $error = 'You must agree to receive messages via ' . ucfirst($verify_method) . ' to continue.';
            } else {
                $result = register_user($username, $email, $password, $phone, $verify_method);
                if ($result === null) {
                    $registered_email  = strtolower(trim($email));
                    $registered_method = $verify_method;
                    $registered_phone  = $phone;
                    // For SMS/WhatsApp, look up the new user ID for code entry
                    if ($verify_method !== 'email') {
                        $uidStmt = get_db()->prepare('SELECT id FROM users WHERE LOWER(email) = ?');
                        $uidStmt->execute([strtolower(trim($email))]);
                        $registered_uid = (int)$uidStmt->fetchColumn();
                        $_SESSION['verify_user_id'] = $registered_uid;
                        $_SESSION['verify_method']  = $verify_method;
                    }
                } else {
                    $error = $result;
                }
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

<?php $nav_active = ''; require __DIR__ . '/_nav.php'; ?>

<div class="card-wrap">
    <div class="card">
        <?php if ($registered_email && $registered_method === 'email'): ?>
        <h2>Check Your Email</h2>
        <p class="subtitle">Account created!</p>
        <div class="alert alert-success">
            We've sent a verification link to <strong><?= htmlspecialchars($registered_email) ?></strong>.<br>
            Click the link in the email to activate your account.
        </div>
        <p style="text-align:center;margin-top:1.25rem;font-size:.875rem;color:#64748b">
            Didn't get it? <a href="/resend_verification.php?email=<?= urlencode($registered_email) ?>">Resend verification email</a>
        </p>

        <?php elseif ($registered_email && in_array($registered_method, ['sms', 'whatsapp'], true)): ?>
        <h2>Enter Verification Code</h2>
        <p class="subtitle">Account created!</p>
        <div class="alert alert-success">
            We sent a 6-digit code to <?php
                $masked = $registered_phone ? preg_replace('/(\d{3})\d{4}(\d{3,4})/', '$1****$2', $registered_phone) : 'your phone';
                echo '<strong>' . htmlspecialchars($masked) . '</strong>';
            ?> via <strong><?= ucfirst($registered_method) ?></strong>.
        </div>
        <form method="post" action="/verify_phone.php" style="margin-top:1rem">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">
            <div class="form-group" style="text-align:center">
                <input type="text" name="code" placeholder="6-digit code" maxlength="6" pattern="\d{6}"
                       inputmode="numeric" autocomplete="one-time-code" required autofocus
                       style="width:180px;font-size:1.5rem;text-align:center;letter-spacing:.3em;padding:.6rem;border:2px solid #e2e8f0;border-radius:10px">
            </div>
            <button type="submit" class="btn btn-primary" style="width:100%">Verify</button>
        </form>
        <p style="text-align:center;margin-top:1rem;font-size:.875rem;color:#64748b">
            Didn't get it? <a href="/resend_verification.php?email=<?= urlencode($registered_email) ?>">Resend code</a>
        </p>

        <?php else: ?>
        <?php $_reg_banner = get_setting('header_banner_path', ''); if ($_reg_banner): ?>
        <div style="text-align:center;margin-bottom:.75rem">
            <a href="/"><img src="<?= htmlspecialchars($_reg_banner) ?>" alt="<?= htmlspecialchars($site_name) ?>" style="max-height:60px;width:auto"></a>
        </div>
        <?php endif; ?>
        <h2>Create Account</h2>
        <p class="subtitle">Join <?= htmlspecialchars($site_name) ?>.</p>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="post" action="/register.php" novalidate onsubmit="return validateRegister()">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">

            <div class="form-group">
                <label for="email">Email</label>
                <input type="email" id="email" name="email"
                       value="<?= htmlspecialchars($_POST['email'] ?? $_GET['email'] ?? '') ?>"
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
                <label for="phone">Phone <span id="phoneOptional" style="color:#94a3b8;font-weight:400">(optional)</span></label>
                <input type="tel" id="phone" name="phone"
                       value="<?= htmlspecialchars($_POST['phone'] ?? $_GET['phone'] ?? '') ?>"
                       autocomplete="tel">
            </div>

            <div class="form-group">
                <label>Verify &amp; receive notifications via</label>
                <div style="display:flex;gap:0;border:1.5px solid #e2e8f0;border-radius:8px;overflow:hidden;margin-top:.25rem">
                    <?php
                    $vm = $_POST['verify_method'] ?? 'email';
                    foreach (['email' => 'Email', 'sms' => 'SMS', 'whatsapp' => 'WhatsApp'] as $val => $label):
                    ?>
                    <label style="flex:1;text-align:center;padding:.55rem;cursor:pointer;font-size:.85rem;font-weight:600;transition:background .12s,color .12s;<?= $val !== 'email' ? 'border-left:1.5px solid #e2e8f0;' : '' ?>" id="vmlabel_<?= $val ?>">
                        <input type="radio" name="verify_method" value="<?= $val ?>" style="display:none" onchange="updateVerifyMethod()"<?= $vm === $val ? ' checked' : '' ?>>
                        <?= $label ?>
                    </label>
                    <?php endforeach; ?>
                </div>
                <p class="hint" id="verifyHint">We'll send a verification link to your email.</p>
                <div id="smsConsent" style="display:none;margin-top:.5rem;padding:.75rem;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;font-size:.8rem;line-height:1.5;color:#475569;display:flex;align-items:flex-start;gap:.5rem">
                    <input type="checkbox" id="sms_consent" name="sms_consent" value="1" style="flex-shrink:0;width:16px;height:16px;margin-top:2px"<?= !empty($_POST['sms_consent']) ? ' checked' : '' ?>>
                    <span>I agree to receive event-related messages (invites, reminders, RSVP updates) via <span id="consentMethod">SMS</span>. Message frequency varies. Message and data rates may apply. Reply STOP to unsubscribe, HELP for help. <a href="/privacy.php" target="_blank">Privacy Policy</a>.</span>
                </div>
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
                <p class="hint">At least 12 characters.</p>
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

        <p style="text-align:center;margin-top:1rem;font-size:.8125rem;color:#94a3b8;line-height:1.5">
            By creating an account you agree to our
            <a href="/terms.php">Terms &amp; Conditions</a>
            and <a href="/privacy.php">Privacy Policy</a>.
        </p>

        <p style="text-align:center;margin-top:.75rem;font-size:.875rem;color:#64748b">
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

function validateRegister() {
    var method = (document.querySelector('input[name="verify_method"]:checked') || {}).value || 'email';
    if (method !== 'email') {
        var phone = document.getElementById('phone');
        if (phone && phone.value.trim() === '') { alert('Phone number is required for ' + method.toUpperCase() + ' verification.'); phone.focus(); return false; }
        var cb = document.getElementById('sms_consent');
        if (cb && !cb.checked) { alert('You must agree to receive messages to continue.'); return false; }
    }
    return true;
}

// Verification method toggle
function updateVerifyMethod() {
    var sel = document.querySelector('input[name="verify_method"]:checked');
    var method = sel ? sel.value : 'email';
    var phoneInput = document.getElementById('phone');
    var phoneOpt = document.getElementById('phoneOptional');
    var hint = document.getElementById('verifyHint');
    var hints = {
        email: "We'll send a verification link to your email.",
        sms: "We'll text a 6-digit code to your phone.",
        whatsapp: "We'll send a 6-digit code via WhatsApp."
    };
    if (hint) hint.textContent = hints[method] || '';
    // Phone required for SMS/WhatsApp
    if (method !== 'email') {
        if (phoneInput) phoneInput.required = true;
        if (phoneOpt) phoneOpt.textContent = '(required)';
    } else {
        if (phoneInput) phoneInput.required = false;
        if (phoneOpt) phoneOpt.textContent = '(optional)';
    }
    // SMS/WhatsApp consent notice + checkbox
    var consent = document.getElementById('smsConsent');
    var consentMethod = document.getElementById('consentMethod');
    var consentCb = document.getElementById('sms_consent');
    if (consent) consent.style.display = (method !== 'email') ? 'flex' : 'none';
    if (consentMethod) consentMethod.textContent = method === 'whatsapp' ? 'WhatsApp' : 'SMS';
    if (consentCb) consentCb.required = (method !== 'email');
    // Visual toggle
    ['email', 'sms', 'whatsapp'].forEach(function(v) {
        var lbl = document.getElementById('vmlabel_' + v);
        if (lbl) {
            lbl.style.background = (v === method) ? '#2563eb' : '';
            lbl.style.color = (v === method) ? '#fff' : '#334155';
        }
    });
}
updateVerifyMethod();
</script>
</body>
</html>
