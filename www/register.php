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
        // Rate limit: registration attempts per IP per hour (MAX_REGISTRATION_ATTEMPTS_PER_HOUR).
        $db = get_db();
        $ip = function_exists('get_client_ip') ? get_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '');
        $rlStmt = $db->prepare("SELECT COUNT(*) FROM activity_log WHERE ip = ? AND action LIKE 'register_attempt%' AND created_at > datetime('now', '-1 hour')");
        $rlStmt->execute([$ip]);
        if ((int)$rlStmt->fetchColumn() >= MAX_REGISTRATION_ATTEMPTS_PER_HOUR) {
            $error = 'Too many registration attempts. Please try again in an hour.';
        } else {
            db_log_anon_activity('register_attempt');

            $username  = trim($_POST['username'] ?? '');
            $contact   = trim($_POST['contact'] ?? '');
            $password  = $_POST['password'] ?? '';
            $password2 = $_POST['password2'] ?? '';

            // Auto-detect: contains '@' → email, otherwise phone.
            $email = '';
            $phone = '';
            if ($contact !== '') {
                if (strpos($contact, '@') !== false) {
                    $email = strtolower($contact);
                } else {
                    $phone = normalize_phone($contact);
                }
            }
            $verify_method = $email !== '' ? 'email' : 'sms';

            if ($password !== $password2) {
                $error = 'Passwords do not match.';
            } elseif ($contact === '') {
                $error = 'Enter an email address or phone number.';
            } else {
                $result = register_user($username, $email, $password, $phone, $verify_method);
                if ($result === null) {
                    $registered_email  = $email;
                    $registered_method = $verify_method;
                    $registered_phone  = $phone;
                    // For SMS/WhatsApp, look up the new user ID for code entry
                    if ($verify_method !== 'email') {
                        $uidStmt = get_db()->prepare('SELECT id FROM users WHERE phone = ?');
                        $uidStmt->execute([$phone]);
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
        <?php
        $__resend_query = $registered_email !== ''
            ? ('email=' . urlencode($registered_email))
            : ($registered_phone !== '' ? ('phone=' . urlencode($registered_phone)) : '');
        ?>
        <?php if ($registered_email !== '' && $registered_method === 'email'): ?>
        <h2>Check Your Email</h2>
        <p class="subtitle">Account created!</p>
        <div class="alert alert-success">
            We've sent a verification link to <strong><?= htmlspecialchars($registered_email) ?></strong>.<br>
            Click the link in the email to activate your account.
        </div>
        <p style="text-align:center;margin-top:1.25rem;font-size:.875rem;color:#64748b">
            Didn't get it? <a href="/resend_verification.php?<?= $__resend_query ?>">Resend verification email</a>
        </p>

        <?php elseif ($registered_phone !== '' && in_array($registered_method, ['sms', 'whatsapp'], true)): ?>
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
            Didn't get it? <a href="/resend_verification.php?<?= $__resend_query ?>">Resend code</a>
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
                <label for="username">Username</label>
                <input type="text" id="username" name="username"
                       value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                       autocomplete="username" autofocus required
                       pattern="[a-zA-Z0-9_]{3,30}" maxlength="30">
                <p class="hint">Your display name — 3-30 characters, letters, numbers, underscores.</p>
            </div>

            <div class="form-group">
                <label for="contact">Email or phone</label>
                <input type="text" id="contact" name="contact" data-phone-contact="1"
                       value="<?= htmlspecialchars($_POST['contact'] ?? $_GET['email'] ?? $_GET['phone'] ?? '') ?>"
                       autocomplete="email" required
                       oninput="updateContactHint()">
                <p class="hint" id="contactHint">We'll send a verification link by email, or a 6-digit code by text.</p>
                <div id="smsConsent" style="display:none;margin-top:.5rem;padding:.75rem;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;font-size:.8rem;line-height:1.5;color:#475569;align-items:flex-start;gap:.5rem">
                    <span>&#9888; By registering with a phone number you agree to receive event-related messages (invites, reminders, RSVP updates) via text. Message and data rates may apply. Reply STOP to unsubscribe, HELP for help. <a href="/privacy.php" target="_blank">Privacy Policy</a>.</span>
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
                <p class="hint">At least <?= MIN_PASSWORD_LENGTH ?> characters.</p>
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
    var contact = document.getElementById('contact');
    var val = contact ? contact.value.trim() : '';
    if (val === '') { alert('Enter an email address or phone number.'); contact && contact.focus(); return false; }
    if (val.indexOf('@') !== -1) {
        // Email
        if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(val)) { alert('That does not look like a valid email address.'); contact.focus(); return false; }
    } else {
        // Phone: require at least 7 digits after stripping non-digits
        if ((val.replace(/\D/g, '')).length < 7) { alert('That does not look like a valid phone number.'); contact.focus(); return false; }
    }
    return true;
}

// Update the hint + consent box based on whether the contact looks like email or phone.
function updateContactHint() {
    var contact = document.getElementById('contact');
    var hint    = document.getElementById('contactHint');
    var consent = document.getElementById('smsConsent');
    var val = contact ? contact.value.trim() : '';
    if (val === '') {
        if (hint) hint.textContent = "We'll send a verification link by email, or a 6-digit code by text.";
        if (consent) consent.style.display = 'none';
        return;
    }
    if (val.indexOf('@') !== -1) {
        if (hint) hint.textContent = "We'll email a verification link to confirm this address.";
        if (consent) consent.style.display = 'none';
    } else {
        if (hint) hint.textContent = "We'll text a 6-digit code to confirm this number.";
        if (consent) consent.style.display = 'flex';
    }
}
updateContactHint();
</script>
<script src="/_phone_input.js"></script>
<script>initPhoneAutoFormat();</script>
</body>
</html>
