<?php
require_once __DIR__ . '/auth.php';

$current = require_login();
$db      = get_db();

// _delete_user_account is defined in db.php
$flash   = ['type' => '', 'msg' => ''];

session_start_safe();
if (!empty($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => 'Invalid request token. Please try again.'];
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'update_profile') {
            $username = trim($_POST['username'] ?? '');
            $email    = strtolower(trim($_POST['email'] ?? ''));
            $phone    = trim($_POST['phone'] ?? '');
            $phone    = $phone !== '' ? normalize_phone($phone) : '';
            $pref_contact = in_array($_POST['preferred_contact'] ?? '', ['email', 'sms', 'whatsapp', 'both', 'none'])
                            ? $_POST['preferred_contact'] : 'email';
            $past_days = in_array((int)($_POST['my_events_past_days'] ?? 30), [7,14,30,60,90,180,365]) ? (int)$_POST['my_events_past_days'] : 30;
            $tz_in     = trim($_POST['timezone'] ?? '');
            $valid_tzs = array_values(get_timezone_options());
            $timezone  = ($tz_in !== '' && in_array($tz_in, $valid_tzs, true)) ? $tz_in : '';

            if ($username === '') {
                $flash = ['type' => 'error', 'msg' => 'Username cannot be empty.'];
            } elseif ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $flash = ['type' => 'error', 'msg' => 'A valid email address is required.'];
            } else {
                $chk = $db->prepare('SELECT id FROM users WHERE LOWER(email)=? AND id != ?');
                $chk->execute([$email, $current['id']]);
                if ($chk->fetch()) {
                    $flash = ['type' => 'error', 'msg' => 'That email is already in use.'];
                } else {
                    try {
                        // Reset phone_verified if phone number changed
                        $oldPhone = $db->prepare('SELECT phone FROM users WHERE id = ?');
                        $oldPhone->execute([$current['id']]);
                        $phoneChanged = ($oldPhone->fetchColumn() ?? '') !== ($phone ?: null);

                        $db->prepare('UPDATE users SET username = ?, email = ?, phone = ?, preferred_contact = ?, my_events_past_days = ?, timezone = ?, phone_verified = CASE WHEN ? THEN 0 ELSE phone_verified END WHERE id = ?')
                           ->execute([$username, $email, $phone ?: null, $pref_contact, $past_days, $timezone !== '' ? $timezone : null, $phoneChanged ? 1 : 0, $current['id']]);
                        db_log_activity($current['id'], 'updated profile');
                        $flash = ['type' => 'success', 'msg' => 'Profile updated.'];
                    } catch (PDOException $e) {
                        $flash = ['type' => 'error', 'msg' => 'That username is already taken.'];
                    }
                }
            }
        }

        elseif ($action === 'send_phone_code') {
            require_once __DIR__ . '/sms.php';
            $phone = $db->prepare('SELECT phone FROM users WHERE id = ?');
            $phone->execute([$current['id']]);
            $phoneVal = $phone->fetchColumn();
            if (!$phoneVal) {
                $flash = ['type' => 'error', 'msg' => 'Save a phone number first.'];
            } elseif (get_setting('sms_provider') !== 'surge') {
                $flash = ['type' => 'error', 'msg' => 'Phone verification requires Surge SMS provider.'];
            } else {
                $result = surge_send_verification($phoneVal);
                if (!empty($result['id'])) {
                    $_SESSION['phone_verify_id'] = $result['id'];
                    $flash = ['type' => 'success', 'msg' => 'Verification code sent! Check your phone.'];
                } else {
                    $flash = ['type' => 'error', 'msg' => 'Failed to send code: ' . ($result['error'] ?? 'Unknown error')];
                }
            }
        }

        elseif ($action === 'verify_phone_code') {
            require_once __DIR__ . '/sms.php';
            $code = trim($_POST['verify_code'] ?? '');
            $verifyId = $_SESSION['phone_verify_id'] ?? '';
            if (!$verifyId) {
                $flash = ['type' => 'error', 'msg' => 'No pending verification. Click Verify to send a new code.'];
            } elseif (!preg_match('/^\d{6}$/', $code)) {
                $flash = ['type' => 'error', 'msg' => 'Enter the 6-digit code from your phone.'];
            } else {
                $result = surge_check_verification($verifyId, $code);
                if ($result === 'ok') {
                    $db->prepare('UPDATE users SET phone_verified = 1 WHERE id = ?')->execute([$current['id']]);
                    unset($_SESSION['phone_verify_id']);
                    db_log_activity($current['id'], 'verified phone number');
                    $flash = ['type' => 'success', 'msg' => 'Phone number verified!'];
                } elseif ($result === 'incorrect') {
                    $flash = ['type' => 'error', 'msg' => 'Incorrect code. Please try again.'];
                } elseif ($result === 'exhausted') {
                    unset($_SESSION['phone_verify_id']);
                    $flash = ['type' => 'error', 'msg' => 'Too many attempts. Click Verify to send a new code.'];
                } elseif ($result === 'expired') {
                    unset($_SESSION['phone_verify_id']);
                    $flash = ['type' => 'error', 'msg' => 'Code expired. Click Verify to send a new code.'];
                } else {
                    $flash = ['type' => 'error', 'msg' => 'Verification failed: ' . $result];
                }
            }
        }

        elseif ($action === 'notify_prefs') {
            // Per-category outbound notification toggles. Only known categories are
            // stored; a missing key means enabled, so the JSON stays minimal. The
            // in-app inbox always records everything regardless of these.
            $cats = ['invites', 'reminders', 'changes', 'comments', 'status', 'messages', 'rsvp_replies'];
            $prefs = [];
            foreach ($cats as $c) {
                if (empty($_POST['np_' . $c])) $prefs[$c] = 0; // store only the mutes
            }
            $db->prepare('UPDATE users SET notify_prefs = ? WHERE id = ?')
               ->execute([$prefs ? json_encode($prefs) : null, $current['id']]);
            db_log_activity($current['id'], 'updated notification preferences');
            $flash = ['type' => 'success', 'msg' => 'Notification preferences saved.'];
        }

        elseif ($action === 'update_password') {
            $current_pw  = $_POST['current_password'] ?? '';
            $new_pw      = $_POST['new_password'] ?? '';
            $confirm_pw  = $_POST['confirm_password'] ?? '';

            $row = $db->prepare('SELECT password_hash FROM users WHERE id = ?');
            $row->execute([$current['id']]);
            $hash = $row->fetchColumn();

            if (!password_verify($current_pw, $hash)) {
                $flash = ['type' => 'error', 'msg' => 'Current password is incorrect.'];
            } elseif (strlen($new_pw) < MIN_PASSWORD_LENGTH) {
                $flash = ['type' => 'error', 'msg' => 'New password must be at least ' . MIN_PASSWORD_LENGTH . ' characters.'];
            } elseif ($new_pw !== $confirm_pw) {
                $flash = ['type' => 'error', 'msg' => 'New passwords do not match.'];
            } else {
                $new_hash = password_hash($new_pw, PASSWORD_BCRYPT);
                $db->prepare('UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?')
                   ->execute([$new_hash, $current['id']]);
                // Security: invalidate every remember-me cookie for this user so a stolen
                // token can't keep authenticating after a password rotation.
                try { $db->prepare('DELETE FROM remember_tokens WHERE user_id = ?')->execute([$current['id']]); } catch (Exception $e) {}
                db_log_activity($current['id'], 'changed password');
                $flash = ['type' => 'success', 'msg' => 'Password updated.'];
            }
        }

        // ── Multi-Factor Authentication ──────────────────────────────────────
        // Enable/enroll and recovery-code display live on the dedicated
        // full-page flow (mfa_setup.php). Only disabling is handled here.
        elseif ($action === 'mfa_disable') {
            $pw  = $_POST['current_password'] ?? '';
            $row = $db->prepare('SELECT password_hash FROM users WHERE id = ?');
            $row->execute([$current['id']]);
            $hash = $row->fetchColumn();
            if (!password_verify($pw, $hash)) {
                $flash = ['type' => 'error', 'msg' => 'Password is incorrect. Two-factor authentication was not changed.'];
            } else {
                $db->prepare("UPDATE users SET mfa_enabled = 0, mfa_method = NULL, mfa_totp_secret = NULL WHERE id = ?")
                   ->execute([$current['id']]);
                $db->prepare('DELETE FROM mfa_recovery_codes WHERE user_id = ?')->execute([$current['id']]);
                unset($_SESSION['mfa_setup'], $_SESSION['mfa_new_codes']);
                db_log_activity($current['id'], 'disabled MFA');
                $flash = ['type' => 'success', 'msg' => 'Two-factor authentication is off.'];
            }
        }

        elseif ($action === 'reset_help') {
            help_reset_user($current['id']);
            db_log_activity($current['id'], 'reset help tips');
            $flash = ['type' => 'success', 'msg' => 'Help tips will show again on every screen.'];
        }

        elseif ($action === 'delete_account') {
            $confirm = strtoupper(trim($_POST['confirm_delete'] ?? ''));
            if ($confirm !== 'DELETE') {
                $flash = ['type' => 'error', 'msg' => 'You must type DELETE to confirm account deletion.'];
            } elseif ($current['role'] === 'admin') {
                // Check if this is the last admin
                $adminCount = (int)$db->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn();
                if ($adminCount <= 1) {
                    $flash = ['type' => 'error', 'msg' => 'Cannot delete the last admin account.'];
                } else {
                    delete_user_account($current['id']);
                    logout();
                    header('Location: /login.php');
                    exit;
                }
            } else {
                delete_user_account($current['id']);
                logout();
                header('Location: /login.php');
                exit;
            }
        }
    }

    $_SESSION['flash'] = $flash;
    header('Location: /settings.php');
    exit;
}

// Reload fresh user data after possible username change
$me = $db->prepare('SELECT username, email, phone, preferred_contact, my_events_past_days, my_events_future_days, phone_verified, role, created_at, last_login, timezone, mfa_enabled, mfa_method, notify_prefs FROM users WHERE id = ?');
$me->execute([$current['id']]);
$me = $me->fetch();

// MFA status for the settings card (enrollment itself happens on mfa_setup.php).
$mfa_on            = (int)($me['mfa_enabled'] ?? 0) === 1;
$mfa_recovery_left = $mfa_on ? mfa_recovery_codes_remaining($current['id']) : 0;

$token = csrf_token();
$site_name = get_setting('site_name', 'Game Night');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($site_name) ?></title>
    <link rel="stylesheet" href="/style.css?v=<?= htmlspecialchars(APP_VERSION) ?>">
    <style>
        .settings-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; }
        @media (max-width: 640px) { .settings-grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>

<?php $nav_active = 'settings'; $nav_user = $me; require __DIR__ . '/_nav.php'; ?>
</nav>

<div class="dash-wrap">

    <div class="dash-header">
        <h1>Account Settings</h1>
        <p>Update your profile and password.</p>
    </div>

    <?php if (!empty($_GET['must_change'])): ?>
        <div class="alert alert-error" style="margin-bottom:1.5rem">
            You are using the default password. You must change it before continuing.
        </div>
    <?php endif; ?>

    <?php if ($flash['msg']): ?>
        <div class="alert alert-<?= $flash['type'] === 'error' ? 'error' : 'success' ?>" style="margin-bottom:1.5rem">
            <?= htmlspecialchars($flash['msg']) ?>
        </div>
    <?php endif; ?>

    <div class="settings-grid">

        <!-- Profile -->
        <div class="card" style="max-width:100%">
            <h2>Profile</h2>
            <p class="subtitle">Update your name and email address.</p>
            <form method="post" action="/settings.php">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">
                <input type="hidden" name="action" value="update_profile">
                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username"
                           autocomplete="username" required
                           value="<?= htmlspecialchars($me['username']) ?>">
                </div>
                <div class="form-group">
                    <label for="email">Email <span style="color:#94a3b8;font-size:.8rem;font-weight:400">(used to sign in)</span></label>
                    <input type="email" id="email" name="email"
                           autocomplete="email" required
                           value="<?= htmlspecialchars($me['email'] ?? '') ?>">
                </div>
                <?php
                    $_hasPhone   = !empty($me['phone']);
                    $_phoneOk    = (int)($me['phone_verified'] ?? 0) === 1;
                    $_phonePend  = !empty($_SESSION['phone_verify_id']);
                ?>
                <div class="form-group">
                    <label for="phone">Phone</label>
                    <div style="display:flex;gap:.5rem;align-items:center">
                        <input type="tel" id="phone" name="phone" autocomplete="tel"
                               value="<?= htmlspecialchars($me['phone'] ?? '') ?>" style="flex:1;min-width:0">
                        <?php if ($_hasPhone && $_phoneOk): ?>
                            <span style="white-space:nowrap;color:#16a34a;font-weight:600;font-size:.85rem">&#10003; Verified</span>
                        <?php elseif ($_hasPhone && !$_phonePend): ?>
                            <!-- submits the separate phSendForm via the HTML form= attribute (not the profile form) -->
                            <button type="submit" form="phSendForm" class="btn" style="white-space:nowrap">Verify number</button>
                        <?php endif; ?>
                    </div>
                    <?php if ($_hasPhone && !$_phoneOk && $_phonePend): ?>
                    <div style="display:flex;gap:.5rem;align-items:center;margin-top:.45rem">
                        <input type="text" name="verify_code" form="phVerifyForm" inputmode="numeric" maxlength="6" pattern="\d{6}" required
                               autocomplete="one-time-code" placeholder="000000"
                               style="flex:1;min-width:0;padding:.45rem .6rem;border:1.5px solid #e2e8f0;border-radius:7px;letter-spacing:.2em;text-align:center">
                        <button type="submit" form="phVerifyForm" class="btn btn-primary" style="white-space:nowrap">Verify</button>
                        <button type="submit" form="phSendForm" class="btn" style="white-space:nowrap">Resend</button>
                    </div>
                    <p class="hint" style="margin-top:.3rem">Enter the 6-digit code we texted to <?= htmlspecialchars($me['phone']) ?>.</p>
                    <?php elseif ($_hasPhone && !$_phoneOk): ?>
                    <p class="hint" style="margin-top:.3rem">Verify your number to use SMS for two-factor sign-in. If you just changed it, click Save Profile first.</p>
                    <?php endif; ?>
                    <p style="margin-top:.4rem;font-size:.75rem;line-height:1.4;color:#64748b">By providing your phone number, you consent to receive event-related SMS messages (invites, reminders, RSVP updates). Message frequency varies. Message and data rates may apply. Reply STOP to unsubscribe, HELP for help. <a href="/privacy.php" target="_blank">Privacy Policy</a>.</p>
                </div>
                <div class="form-group">
                    <label for="preferred_contact">Preferred Contact Method</label>
                    <select id="preferred_contact" name="preferred_contact" style="width:100%;padding:.5rem .75rem;border:1.5px solid #e2e8f0;border-radius:8px;font-size:.95rem;background:#fff">
                        <option value="email"<?= ($me['preferred_contact'] ?? 'email') === 'email' ? ' selected' : '' ?>>Email</option>
                        <option value="sms"<?= ($me['preferred_contact'] ?? '') === 'sms'   ? ' selected' : '' ?>>SMS (text message)</option>
                        <option value="whatsapp"<?= ($me['preferred_contact'] ?? '') === 'whatsapp' ? ' selected' : '' ?>>WhatsApp</option>
                        <option value="both"<?= ($me['preferred_contact'] ?? '') === 'both'  ? ' selected' : '' ?>>Email &amp; SMS</option>
                        <option value="none"<?= ($me['preferred_contact'] ?? '') === 'none'  ? ' selected' : '' ?>>None (do not notify me)</option>
                    </select>
                    <p class="hint">How you want to be notified when invited to an event.</p>
                </div>
                <div class="form-group">
                    <label for="my_events_past_days">My Events — Past Range</label>
                    <select id="my_events_past_days" name="my_events_past_days" style="width:100%;padding:.5rem .75rem;border:1.5px solid #e2e8f0;border-radius:8px;font-size:.95rem;background:#fff">
                        <?php foreach ([7=>'7 days',14=>'14 days',30=>'30 days',60=>'60 days',90=>'90 days',180=>'6 months',365=>'1 year'] as $v=>$l): ?>
                        <option value="<?= $v ?>"<?= (int)($me['my_events_past_days'] ?? 30) === $v ? ' selected' : '' ?>><?= $l ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="hint">How far back past events appear on the My Events page.</p>
                </div>
                <div class="form-group">
                    <label for="timezone">Timezone</label>
                    <select id="timezone" name="timezone" style="width:100%;padding:.5rem .75rem;border:1.5px solid #e2e8f0;border-radius:8px;font-size:.95rem;background:#fff">
                        <option value="">Use site default (<?= htmlspecialchars(get_setting('timezone', 'UTC')) ?>)</option>
                        <?php foreach (get_timezone_options() as $tz_label => $tz_id): ?>
                        <option value="<?= htmlspecialchars($tz_id) ?>"<?= ($me['timezone'] ?? '') === $tz_id ? ' selected' : '' ?>><?= htmlspecialchars($tz_label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="hint">Event times and the footer clock will display in this timezone. Notifications sent to you will also use it.</p>
                </div>
                <button type="submit" class="btn btn-primary" style="width:100%">Save Profile</button>
            </form>

            <!-- Phone-verification helper forms: kept OUTSIDE the profile form (forms can't
                 nest). The visible Verify/Resend buttons + code input live next to the phone
                 field above and target these via the HTML form="..." attribute. -->
            <form id="phSendForm" method="post" action="/settings.php" style="display:none">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">
                <input type="hidden" name="action" value="send_phone_code">
            </form>
            <form id="phVerifyForm" method="post" action="/settings.php" style="display:none">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">
                <input type="hidden" name="action" value="verify_phone_code">
            </form>
        </div>

        <!-- Right column: Change Password with Two-Factor grouped beneath it -->
        <div style="display:flex;flex-direction:column;gap:1.5rem">

        <!-- Notification preferences -->
        <div class="card" style="max-width:100%">
            <h2>Notifications</h2>
            <p class="subtitle">Choose which notifications reach you by email/text. Everything still appears in your <a href="/notifications.php">in-app inbox</a>.</p>
            <?php
            $_np = json_decode((string)($me['notify_prefs'] ?? ''), true);
            if (!is_array($_np)) $_np = [];
            $_np_on = fn(string $c) => !array_key_exists($c, $_np) || (int)$_np[$c] === 1;
            $_np_rows = [
                'invites'      => ['Invitations & RSVP nudges', 'Event invites and "still deciding?" follow-ups'],
                'reminders'    => ['Event reminders', 'Scheduled reminders before events you\'re invited to'],
                'changes'      => ['Event changes', 'Updates and cancellations for your events'],
                'comments'     => ['Comments', 'When someone comments on an event you\'re part of'],
                'status'       => ['Approvals & waitlist', 'Approved to play, seat opened up, moved to waitlist'],
                'messages'     => ['Host messages & polls', 'Notes and polls hosts send to attendees'],
                'rsvp_replies' => ['RSVP replies (hosts)', 'When guests reply to events you manage'],
            ];
            ?>
            <form method="post" action="/settings.php">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">
                <input type="hidden" name="action" value="notify_prefs">
                <div style="display:flex;flex-direction:column;gap:.55rem;margin-bottom:1rem">
                    <?php foreach ($_np_rows as $_c => [$_l, $_d]): ?>
                    <label style="display:flex;align-items:flex-start;gap:.6rem;cursor:pointer">
                        <input type="checkbox" name="np_<?= $_c ?>" value="1" <?= $_np_on($_c) ? 'checked' : '' ?> style="margin-top:.2rem">
                        <span style="min-width:0">
                            <span style="font-size:.9rem;font-weight:600;color:#1e293b"><?= $_l ?></span><br>
                            <span style="font-size:.78rem;color:#94a3b8"><?= $_d ?></span>
                        </span>
                    </label>
                    <?php endforeach; ?>
                </div>
                <button type="submit" class="btn btn-primary" style="width:100%">Save Notification Preferences</button>
            </form>
        </div>

        <!-- Change password -->
        <div class="card" style="max-width:100%">
            <h2>Change Password</h2>
            <p class="subtitle">Minimum 6 characters.</p>
            <form method="post" action="/settings.php">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">
                <input type="hidden" name="action" value="update_password">
                <div class="form-group">
                    <label for="current_password">Current Password</label>
                    <input type="password" id="current_password" name="current_password"
                           autocomplete="current-password" required>
                </div>
                <div class="form-group">
                    <label for="new_password">New Password</label>
                    <input type="password" id="new_password" name="new_password"
                           autocomplete="new-password" required>
                </div>
                <div class="form-group">
                    <label for="confirm_password">Confirm New Password</label>
                    <input type="password" id="confirm_password" name="confirm_password"
                           autocomplete="new-password" required>
                </div>
                <button type="submit" class="btn btn-primary" style="width:100%">Update Password</button>
            </form>
        </div>

        <!-- Two-Factor Authentication -->
        <div class="card" style="max-width:100%">
            <h2>Two-Factor Authentication</h2>
            <p class="subtitle">Add a second step at sign-in using an authenticator app or a texted code.</p>

            <?php if ($mfa_on): ?>
                <!-- Enabled -->
                <p style="margin-bottom:1rem">
                    <span class="badge badge-admin">On</span>
                    Method: <strong><?= $me['mfa_method'] === 'sms' ? 'Text message (SMS)' : 'Authenticator app' ?></strong>
                    &middot; <?= (int)$mfa_recovery_left ?> recovery code<?= $mfa_recovery_left === 1 ? '' : 's' ?> left
                </p>
                <form method="post" action="/mfa_setup.php" style="margin-bottom:.75rem">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">
                    <input type="hidden" name="action" value="regen">
                    <button type="submit" class="btn" style="width:100%">Regenerate Recovery Codes</button>
                </form>
                <form method="post" action="/settings.php">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">
                    <input type="hidden" name="action" value="mfa_disable">
                    <div class="form-group">
                        <label for="mfa_disable_pw">Enter your password to turn off two-factor</label>
                        <input type="password" id="mfa_disable_pw" name="current_password"
                               autocomplete="current-password" required>
                    </div>
                    <button type="submit" class="btn" style="width:100%;background:#dc2626;color:#fff;border:none;font-weight:600">Disable Two-Factor</button>
                </form>

            <?php else: ?>
                <!-- Off: enrollment happens on the dedicated setup page -->
                <p style="color:#64748b;margin-bottom:1rem">Currently <strong>off</strong>. Protect your account with an authenticator app or texted codes.</p>
                <a href="/mfa_setup.php" class="btn btn-primary" style="width:100%;display:block;text-align:center;text-decoration:none">Set up two-factor authentication</a>
            <?php endif; ?>
        </div>

        </div><!-- /right column -->

    </div>

    <!-- Account info (read-only) -->
    <div class="table-card" style="margin-top:1.5rem;max-width:540px">
        <h3>Account Info</h3>
        <table>
            <tbody>
                <tr><td style="color:#64748b;width:140px">Role</td><td><span class="badge badge-<?= $me['role'] === 'admin' ? 'admin' : 'user' ?>"><?= htmlspecialchars($me['role']) ?></span></td></tr>
                <tr><td style="color:#64748b">Member since</td><td><?= htmlspecialchars($me['created_at']) ?></td></tr>
                <tr><td style="color:#64748b">Last login</td><td><?= htmlspecialchars($me['last_login'] ?? 'Never') ?></td></tr>
            </tbody>
        </table>
    </div>

    <!-- Help tips -->
    <div class="card" style="max-width:540px;margin-top:1.5rem">
        <h2>Help Tips</h2>
        <p class="subtitle" style="color:#64748b">Bring back the in-app help bubbles you've dismissed. They'll show again on every screen that has tips.</p>
        <form method="post" action="/settings.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">
            <input type="hidden" name="action" value="reset_help">
            <button type="submit" class="btn" style="width:100%">Show all help tips again</button>
        </form>
    </div>

    <!-- Delete account -->
    <div class="card" style="max-width:540px;margin-top:1.5rem;border-color:#fca5a5">
        <h2 style="color:#dc2626">Delete Account</h2>
        <p class="subtitle" style="color:#64748b">Permanently delete your account and all associated data. This cannot be undone.</p>
        <form method="post" action="/settings.php" onsubmit="return document.getElementById('confirm_delete').value.trim().toUpperCase() === 'DELETE'">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">
            <input type="hidden" name="action" value="delete_account">
            <div class="form-group">
                <label for="confirm_delete">Type <strong style="color:#dc2626">DELETE</strong> to confirm</label>
                <!-- text-transform only UPPERCASES the display; keep the submitted value in sync
                     (oninput) so typing "delete" doesn't silently submit lowercase and fail. -->
                <input type="text" id="confirm_delete" name="confirm_delete" required
                       autocomplete="off" placeholder="DELETE"
                       oninput="this.value=this.value.toUpperCase()"
                       style="border-color:#fca5a5;text-transform:uppercase">
            </div>
            <button type="submit" class="btn" style="width:100%;background:#dc2626;color:#fff;border:none;font-weight:600;padding:.6rem">
                Permanently Delete My Account
            </button>
        </form>
    </div>

</div>

<?php require __DIR__ . '/_footer.php'; ?>
<script src="/_phone_input.js"></script>
<script>initPhoneAutoFormat();</script>
</body>
</html>
