<?php
require_once __DIR__ . '/auth.php';

$current = require_login();
$db      = get_db();
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

                        $db->prepare('UPDATE users SET username = ?, email = ?, phone = ?, preferred_contact = ?, my_events_past_days = ?, phone_verified = CASE WHEN ? THEN 0 ELSE phone_verified END WHERE id = ?')
                           ->execute([$username, $email, $phone ?: null, $pref_contact, $past_days, $phoneChanged ? 1 : 0, $current['id']]);
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

        elseif ($action === 'update_password') {
            $current_pw  = $_POST['current_password'] ?? '';
            $new_pw      = $_POST['new_password'] ?? '';
            $confirm_pw  = $_POST['confirm_password'] ?? '';

            $row = $db->prepare('SELECT password_hash FROM users WHERE id = ?');
            $row->execute([$current['id']]);
            $hash = $row->fetchColumn();

            if (!password_verify($current_pw, $hash)) {
                $flash = ['type' => 'error', 'msg' => 'Current password is incorrect.'];
            } elseif (strlen($new_pw) < 12) {
                $flash = ['type' => 'error', 'msg' => 'New password must be at least 12 characters.'];
            } elseif ($new_pw !== $confirm_pw) {
                $flash = ['type' => 'error', 'msg' => 'New passwords do not match.'];
            } else {
                $new_hash = password_hash($new_pw, PASSWORD_BCRYPT);
                $db->prepare('UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?')
                   ->execute([$new_hash, $current['id']]);
                db_log_activity($current['id'], 'changed password');
                $flash = ['type' => 'success', 'msg' => 'Password updated.'];
            }
        }
    }

    $_SESSION['flash'] = $flash;
    header('Location: /settings.php');
    exit;
}

// Reload fresh user data after possible username change
$me = $db->prepare('SELECT username, email, phone, preferred_contact, my_events_past_days, my_events_future_days, phone_verified, role, created_at, last_login FROM users WHERE id = ?');
$me->execute([$current['id']]);
$me = $me->fetch();

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
                <div class="form-group">
                    <label for="phone">Phone</label>
                    <input type="tel" id="phone" name="phone"
                           autocomplete="tel"
                           value="<?= htmlspecialchars($me['phone'] ?? '') ?>">
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
                <button type="submit" class="btn btn-primary" style="width:100%">Save Profile</button>
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

</div>

<?php require __DIR__ . '/_footer.php'; ?>

</body>
</html>
