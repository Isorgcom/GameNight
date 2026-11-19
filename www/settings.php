<?php
require_once __DIR__ . '/auth.php';

$current = require_login();
$db      = get_db();

// _delete_user_account is defined in db.php
$flash   = ['type' => '', 'msg' => ''];
// The page's sections, in the order the switcher shows them.
const ST_PANES = ['profile' => 'Profile', 'preferences' => 'Preferences', 'notifications' => 'Notifications', 'security' => 'Security', 'account' => 'Account'];

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

        if ($action === 'set_avatar') {
            // Path comes from upload.php (validated to its exact output shape).
            $path = trim($_POST['avatar_path'] ?? '');
            // Legacy flat path OR the namespaced avatars/u<id>_… that upload.php now returns.
            if (preg_match('#^/uploads/(avatars/u\d+_)?[a-f0-9]{32}\.(jpg|png|gif|webp)\z#', $path)) {
                $db->prepare('UPDATE users SET avatar_path = ? WHERE id = ?')->execute([$path, $current['id']]);
                db_log_activity($current['id'], 'set profile photo');
                $flash = ['type' => 'success', 'msg' => 'Profile photo updated.'];
            } else {
                $flash = ['type' => 'error', 'msg' => 'That image could not be used.'];
            }
        }
        elseif ($action === 'remove_avatar') {
            $db->prepare('UPDATE users SET avatar_path = NULL WHERE id = ?')->execute([$current['id']]);
            db_log_activity($current['id'], 'removed profile photo');
            $flash = ['type' => 'success', 'msg' => 'Profile photo removed.'];
        }
        elseif ($action === 'update_profile') {
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

            // Same rule as registration and the API. This path used to accept any
            // non-empty string, which mattered because event authority is resolved
            // by matching users.username against event_invites.username: a username
            // containing a space, an @ or a colon can be aimed at an invite row for
            // someone who has no account (see can_manage_event() in db.php).
            if ($username === '') {
                $flash = ['type' => 'error', 'msg' => 'Username cannot be empty.'];
            } elseif ($err = username_format_error($username)) {
                $flash = ['type' => 'error', 'msg' => $err];
            } elseif ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $flash = ['type' => 'error', 'msg' => 'A valid email address is required.'];
            } else {
                $cur = $db->prepare('SELECT username, email, phone, mfa_method FROM users WHERE id = ?');
                $cur->execute([$current['id']]);
                $cur = $cur->fetch() ?: [];
                $curEmail        = strtolower((string)($cur['email'] ?? ''));
                $curPhone        = (string)($cur['phone'] ?? '');
                $usernameChanged = $username !== (string)($cur['username'] ?? '');
                $emailChanged    = $email !== $curEmail;
                $phoneChanged    = $phone !== $curPhone;
                // Who you are (the name) and where you can be reached and recovered
                // (email, phone) change only with the current password: an open
                // session is not proof enough, and a stolen one must not be able to
                // re-home the account. Preferences save as before.
                $pwCheck = ($usernameChanged || $emailChanged || $phoneChanged)
                    ? confirm_current_password((int)$current['id'], (string)($_POST['current_password'] ?? ''))
                    : 'ok';
                $emailTaken = false;
                if ($emailChanged) {
                    $chk = $db->prepare('SELECT id FROM users WHERE LOWER(email)=? AND id != ?');
                    $chk->execute([$email, $current['id']]);
                    $emailTaken = (bool)$chk->fetch();
                }
                $phoneTaken = false;
                if ($phoneChanged && $phone !== '') {
                    $chk = $db->prepare('SELECT id FROM users WHERE phone = ? AND id != ?');
                    $chk->execute([$phone, $current['id']]);
                    $phoneTaken = (bool)$chk->fetch();
                }
                if ($pwCheck !== 'ok') {
                    $flash = ['type' => 'error', 'msg' => password_confirm_message($pwCheck)];
                } elseif ($emailTaken) {
                    $flash = ['type' => 'error', 'msg' => 'That email is already in use.'];
                } elseif ($phoneTaken) {
                    $flash = ['type' => 'error', 'msg' => 'That phone number is already in use.'];
                } else {
                    try {
                        // Saving the profile answers the one-time Tournament Timer
                        // ask too (the select always posts a value), which is
                        // fine: the setting has been seen.
                        $beta_timer = (int)($_POST['beta_timer'] ?? 0) === 1 ? 1 : 0;
                        $theme_in   = $_POST['theme'] ?? '';
                        $theme      = in_array($theme_in, ['light', 'dark'], true) ? $theme_in : null;   // null = follow the device
                        // The email is NOT written here: a new address takes effect only
                        // when the confirmation link sent to it is used (verify_email.php),
                        // so an account can never be pointed at an address its holder
                        // does not control. A changed phone starts out unverified.
                        $db->prepare('UPDATE users SET username = ?, phone = ?, preferred_contact = ?, my_events_past_days = ?, timezone = ?, beta_timer = ?, theme = ?, phone_verified = CASE WHEN ? THEN 0 ELSE phone_verified END WHERE id = ?')
                           ->execute([$username, $phone ?: null, $pref_contact, $past_days, $timezone !== '' ? $timezone : null, $beta_timer, $theme, $phoneChanged ? 1 : 0, $current['id']]);
                        // The roster name on this account's own invite rows follows a
                        // rename: identity is the id, this is what hosts see.
                        if ($usernameChanged) {
                            $db->prepare('UPDATE event_invites SET username = ? WHERE user_id = ?')->execute([$username, $current['id']]);
                            try { $db->prepare('UPDATE pending_notifications SET username = ? WHERE user_id = ? AND attempted_at IS NULL')->execute([$username, $current['id']]); } catch (Exception $e) {}
                        }
                        $msgs = ['Profile updated.'];
                        if ($phoneChanged) {
                            // A code already sent belongs to the OLD number.
                            unset($_SESSION['phone_verify_id'], $_SESSION['phone_verify_for']);
                            if ($phone !== '' && ($cur['mfa_method'] ?? '') === 'sms') {
                                $msgs[] = 'Your sign-in codes go by text: verify the new number now, or you will need a recovery code next time you sign in.';
                            }
                            send_account_notice((int)$current['id'], 'Your phone number was changed', 'The phone number on your account was ' . ($phone !== '' ? 'changed to ' . $phone : 'removed') . '.');
                        }
                        if ($emailChanged) {
                            $db->prepare('UPDATE users SET pending_email = ? WHERE id = ?')->execute([$email, $current['id']]);
                            $mailed = send_verification_email((int)$current['id'], $email, $username, $email);
                            if ($curEmail !== '') {
                                send_account_notice((int)$current['id'], 'Email change requested', 'A request was made to change the email address on your account from ' . $curEmail . ' to ' . $email . '. It takes effect only when the link sent to the new address is used.', $curEmail);
                            }
                            $msgs[] = $mailed
                                ? 'A confirmation link was sent to ' . $email . '; your email changes when you use it.'
                                : 'The confirmation link to ' . $email . ' could not be sent right now (too many confirmation emails recently). The change stays pending; cancel it below and try again later.';
                        }
                        db_log_activity($current['id'], 'updated profile');
                        $flash = ['type' => 'success', 'msg' => implode(' ', $msgs)];
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
                    $_SESSION['phone_verify_id']  = $result['id'];
                    $_SESSION['phone_verify_for'] = (string)$phoneVal;   // the code verifies this number only
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
            // The code was texted to one number and verifies that number only: a
            // profile edit in between must not let it mark a different one.
            $nowPhone = $db->prepare('SELECT phone FROM users WHERE id = ?');
            $nowPhone->execute([$current['id']]);
            if ($verifyId && (string)($_SESSION['phone_verify_for'] ?? '') !== (string)($nowPhone->fetchColumn() ?: '')) {
                unset($_SESSION['phone_verify_id'], $_SESSION['phone_verify_for']);
                $verifyId = '';
            }
            if (!$verifyId) {
                $flash = ['type' => 'error', 'msg' => 'No pending verification. Click Verify to send a new code.'];
            } elseif (!preg_match('/^\d{6}$/', $code)) {
                $flash = ['type' => 'error', 'msg' => 'Enter the 6-digit code from your phone.'];
            } else {
                $result = surge_check_verification($verifyId, $code);
                if ($result === 'ok') {
                    $db->prepare('UPDATE users SET phone_verified = 1 WHERE id = ?')->execute([$current['id']]);
                    claim_verified_contact((int)$current['id'], '', (string)($_SESSION['phone_verify_for'] ?? ''));
                    unset($_SESSION['phone_verify_id'], $_SESSION['phone_verify_for']);
                    db_log_activity($current['id'], 'verified phone number');
                    $flash = ['type' => 'success', 'msg' => 'Phone number verified!'];
                } elseif ($result === 'incorrect') {
                    $flash = ['type' => 'error', 'msg' => 'Incorrect code. Please try again.'];
                } elseif ($result === 'exhausted') {
                    unset($_SESSION['phone_verify_id'], $_SESSION['phone_verify_for']);
                    $flash = ['type' => 'error', 'msg' => 'Too many attempts. Click Verify to send a new code.'];
                } elseif ($result === 'expired') {
                    unset($_SESSION['phone_verify_id'], $_SESSION['phone_verify_for']);
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
            $cats = ['invites', 'reminders', 'changes', 'comments', 'status', 'messages', 'rsvp_replies', 'dms', 'tickets', 'rewards'];
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

            $pwCheck = confirm_current_password((int)$current['id'], $current_pw);
            if ($pwCheck !== 'ok') {
                $flash = ['type' => 'error', 'msg' => password_confirm_message($pwCheck)];
            } elseif (strlen($new_pw) < MIN_PASSWORD_LENGTH) {
                $flash = ['type' => 'error', 'msg' => 'New password must be at least ' . MIN_PASSWORD_LENGTH . ' characters.'];
            } elseif ($new_pw !== $confirm_pw) {
                $flash = ['type' => 'error', 'msg' => 'New passwords do not match.'];
            } else {
                $new_hash = password_hash($new_pw, PASSWORD_BCRYPT);
                $db->prepare('UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?')
                   ->execute([$new_hash, $current['id']]);
                // Every OTHER session and every remember-me token ends; this one stays.
                invalidate_other_sessions((int)$current['id'], true);
                send_account_notice((int)$current['id'], 'Your password was changed', 'The password on your account was just changed from Settings, and every other signed-in device was signed out.');
                db_log_activity($current['id'], 'changed password');
                $flash = ['type' => 'success', 'msg' => 'Password updated.'];
            }
        }

        // ── Multi-Factor Authentication ──────────────────────────────────────
        // Enable/enroll and recovery-code display live on the dedicated
        // full-page flow (mfa_setup.php). Only disabling is handled here.
        elseif ($action === 'mfa_disable') {
            $pwCheck = confirm_current_password((int)$current['id'], (string)($_POST['current_password'] ?? ''));
            if ($pwCheck !== 'ok') {
                $flash = ['type' => 'error', 'msg' => password_confirm_message($pwCheck) . ' Two-factor authentication was not changed.'];
            } else {
                $db->prepare("UPDATE users SET mfa_enabled = 0, mfa_method = NULL, mfa_totp_secret = NULL WHERE id = ?")
                   ->execute([$current['id']]);
                $db->prepare('DELETE FROM mfa_recovery_codes WHERE user_id = ?')->execute([$current['id']]);
                unset($_SESSION['mfa_setup'], $_SESSION['mfa_new_codes']);
                db_log_activity($current['id'], 'disabled MFA');
                send_account_notice((int)$current['id'], 'Two-factor authentication was turned off', 'Two-factor authentication was turned off on your account from Settings.');
                $flash = ['type' => 'success', 'msg' => 'Two-factor authentication is off.'];
            }
        }

        // ── Personal API tokens ──────────────────────────────────────────────
        // A token is a way into the account from another program, so minting
        // one asks for the password, exactly like turning off two-factor. The
        // plaintext is shown once, on the page that follows; only its hash is kept.
        elseif ($action === 'api_token_create') {
            require_once __DIR__ . '/api/_auth.php';
            $label   = trim(preg_replace('/[\x00-\x1F\x7F]/', '', (string)($_POST['label'] ?? '')));
            $scopes  = api_token_clean_scopes((array)($_POST['scopes'] ?? []));
            $pwCheck = confirm_current_password((int)$current['id'], (string)($_POST['current_password'] ?? ''));
            $have    = (int)$db->query('SELECT COUNT(*) FROM api_tokens WHERE client_id IS NULL AND user_id = ' . (int)$current['id'])->fetchColumn();
            if ($pwCheck !== 'ok') {
                $flash = ['type' => 'error', 'msg' => password_confirm_message($pwCheck) . ' No token was created.'];
            } elseif ($label === '' || mb_strlen($label) > 80) {
                $flash = ['type' => 'error', 'msg' => 'Give the token a name of up to 80 characters, so you can tell later what it is for.'];
            } elseif ($have >= 10) {
                $flash = ['type' => 'error', 'msg' => 'You already have 10 tokens. Revoke one you no longer use first.'];
            } else {
                [$plain, $hash] = api_token_generate();
                $db->prepare('INSERT INTO api_tokens (user_id, token_hash, label, scopes) VALUES (?, ?, ?, ?)')
                   ->execute([$current['id'], $hash, $label, implode(',', $scopes)]);
                db_log_activity($current['id'], "created API token '$label' (scopes: " . implode(',', $scopes) . ')');
                send_account_notice((int)$current['id'], 'An API token was created', "An API token named \"$label\" was created on your account from Settings. It lets another program act as you. If this was not you, change your password, which also revokes every token.");
                $_SESSION['new_api_token'] = ['token' => $plain, 'label' => $label];
                $flash = ['type' => 'success', 'msg' => 'Token created. Copy it now: it is shown only once.'];
            }
        }

        elseif ($action === 'oauth_disconnect') {
            $cpk = (int)($_POST['client_pk'] ?? 0);
            $nm  = $db->prepare('SELECT label FROM api_tokens WHERE user_id = ? AND client_id = ? LIMIT 1');
            $nm->execute([$current['id'], $cpk]);
            $label = $nm->fetchColumn();
            if ($label === false) {
                $flash = ['type' => 'error', 'msg' => 'That app is no longer connected.'];
            } else {
                $db->prepare('DELETE FROM api_tokens WHERE user_id = ? AND client_id = ?')->execute([$current['id'], $cpk]);
                db_log_activity($current['id'], "disconnected app '$label'");
                $flash = ['type' => 'success', 'msg' => $label . ' is disconnected. It can no longer act as you.'];
            }
        }

        elseif ($action === 'api_token_revoke') {
            $tid = (int)($_POST['token_id'] ?? 0);
            $row = $db->prepare('SELECT label FROM api_tokens WHERE id = ? AND user_id = ?');
            $row->execute([$tid, $current['id']]);
            $label = $row->fetchColumn();
            if ($label === false) {
                $flash = ['type' => 'error', 'msg' => 'That token no longer exists.'];
            } else {
                $db->prepare('DELETE FROM api_tokens WHERE id = ? AND user_id = ?')->execute([$tid, $current['id']]);
                db_log_activity($current['id'], "revoked API token '$label'");
                $flash = ['type' => 'success', 'msg' => 'Token revoked. Anything still using it stops working now.'];
            }
        }

        elseif ($action === 'cancel_email_change') {
            $db->prepare('UPDATE users SET pending_email = NULL WHERE id = ?')->execute([$current['id']]);
            $db->prepare('UPDATE email_verifications SET used = 1 WHERE user_id = ? AND used = 0 AND new_email IS NOT NULL')->execute([$current['id']]);
            db_log_activity($current['id'], 'cancelled email change');
            $flash = ['type' => 'success', 'msg' => 'The email change was cancelled.'];
        }

        elseif ($action === 'reset_help') {
            help_reset_user($current['id']);
            db_log_activity($current['id'], 'reset help tips');
            $flash = ['type' => 'success', 'msg' => 'Help tips will show again on every screen.'];
        }

        elseif ($action === 'delete_account') {
            $confirm = strtoupper(trim($_POST['confirm_delete'] ?? ''));
            // The typed word guards against a slip; the password guards against
            // someone else at an open session.
            $pwCheck = $confirm === 'DELETE' ? confirm_current_password((int)$current['id'], (string)($_POST['current_password'] ?? '')) : 'wrong';
            if ($confirm !== 'DELETE') {
                $flash = ['type' => 'error', 'msg' => 'You must type DELETE to confirm account deletion.'];
            } elseif ($pwCheck !== 'ok') {
                $flash = ['type' => 'error', 'msg' => password_confirm_message($pwCheck) . ' The account was not deleted.'];
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

    // Come back on the section the form was in: each form carries st_pane.
    if (isset(ST_PANES[(string)($_POST['st_pane'] ?? '')])) $_SESSION['st_pane'] = (string)$_POST['st_pane'];
    $_SESSION['flash'] = $flash;
    header('Location: /settings.php');
    exit;
}

// Reload fresh user data after possible username change
$me = $db->prepare('SELECT username, email, pending_email, phone, preferred_contact, my_events_past_days, my_events_future_days, phone_verified, role, created_at, last_login, timezone, mfa_enabled, mfa_method, notify_prefs, beta_timer, theme FROM users WHERE id = ?');
$me->execute([$current['id']]);
$me = $me->fetch();

// MFA status for the settings card (enrollment itself happens on mfa_setup.php).
$mfa_on            = (int)($me['mfa_enabled'] ?? 0) === 1;
$mfa_recovery_left = $mfa_on ? mfa_recovery_codes_remaining($current['id']) : 0;

// Leagues this account belongs to, each linked to its page.
$lq = $db->prepare(
    'SELECT l.id, l.name, l.is_hidden, lm.role, lm.joined_at
     FROM league_members lm
     JOIN leagues l ON l.id = lm.league_id
     WHERE lm.user_id = ?
     ORDER BY LOWER(l.name)'
);
$lq->execute([$current['id']]);
$my_leagues = $lq->fetchAll();

// Personal API tokens: the list, and the one just minted (shown once).
require_once __DIR__ . '/api/_auth.php';
$tq = $db->prepare('SELECT t.id, t.label, t.scopes, t.created_at, t.last_used_at,
                            (SELECT COUNT(*) FROM api_request_log r WHERE r.token_id = t.id) AS requests
                       FROM api_tokens t WHERE t.user_id = ? AND t.client_id IS NULL ORDER BY t.id DESC');
// Stored UTC to the viewer's own timezone.
$st_when = fn(?string $utc, string $fmt) => $utc ? (new DateTime($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format($fmt) : '';
$tq->execute([$current['id']]);
$api_tokens    = $tq->fetchAll();
// Apps connected by signing in (OAuth): one row per app.
$cq = $db->prepare('SELECT t.id, t.label, t.scopes, t.created_at, t.last_used_at, t.client_id AS client_pk, c.client_id AS client_ref, c.kind,
                           (SELECT COUNT(*) FROM api_request_log r WHERE r.token_id = t.id) AS requests
                      FROM api_tokens t JOIN oauth_clients c ON c.id = t.client_id
                     WHERE t.user_id = ? ORDER BY t.id DESC');
$cq->execute([$current['id']]);
$connected_apps = $cq->fetchAll();
// Each token's most recent requests, for its "Recent activity" list. The path
// stored is the route pattern (/api/v2/events/{id}), never the raw address.
$token_activity = [];
$aq = $db->prepare('SELECT method, path, status, ip, created_at FROM api_request_log WHERE token_id = ? ORDER BY id DESC LIMIT 25');
foreach (array_merge($api_tokens, $connected_apps) as $_t) {
    $aq->execute([(int)$_t['id']]);
    $token_activity[(int)$_t['id']] = $aq->fetchAll();
}
// What a status code means to the person reading the list.
$st_status = fn(int $c) => match (true) {
    $c >= 200 && $c < 300 => 'OK',
    $c === 401 => 'Refused: token not accepted',
    $c === 403 => 'Refused: not allowed',
    $c === 404 => 'Not found',
    $c === 405 => 'Wrong method',
    $c === 409 => 'Refused: conflict',
    $c === 429 => 'Refused: too many requests',
    $c >= 500 => 'Server error',
    default => 'Bad request',
};
$new_api_token = $_SESSION['new_api_token'] ?? null;
unset($_SESSION['new_api_token']);

// The section to open, decided here so the first paint is already right: the
// one a form was just saved from, or Security when the password must change
// or a token was just created.
$st_pane = (string)($_SESSION['st_pane'] ?? 'profile');
unset($_SESSION['st_pane']);
if (!isset(ST_PANES[$st_pane])) $st_pane = 'profile';
if (!empty($_GET['must_change']) || $new_api_token) $st_pane = 'security';
// Attributes for a section: a tab panel, hidden unless it is the open one.
// hidden="until-found" keeps a closed section findable with the browser's
// find-in-page; where that is not supported it behaves as plain hidden.
$st_panel = fn(string $p) => 'class="st-pane" id="st-' . $p . '" data-pane="' . $p . '" role="tabpanel" aria-labelledby="st-tab-' . $p . '"' . ($p === $st_pane ? '' : ' hidden="until-found"');

$token = csrf_token();
$site_name = get_setting('site_name', 'Game Night');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($site_name) ?></title>
    <link rel="stylesheet" href="/style.css?v=<?= htmlspecialchars(APP_VERSION . '.' . (@filemtime(__DIR__ . '/style.css') ?: 0)) ?>">
    <style>
        /* Settings: one column of cards, switched by the segmented control.
           The open section is decided on the server, so the first paint is right. */
        .st-wrap { max-width: 820px; container: settings / inline-size; }
        .st-head { display: flex; align-items: center; gap: 1rem; margin-bottom: 1.25rem; }
        .st-head h1 { font-size: 1.35rem; line-height: 1.2; margin: 0; text-wrap: balance; }
        .st-head p { margin: .2rem 0 0; color: var(--muted); font-size: .88rem; overflow-wrap: anywhere; }
        .st-wrap h2 { text-wrap: balance; }
        .st-wrap p { text-wrap: pretty; }

        /* The section switcher. It scrolls sideways if five buttons cannot fit,
           and each end fades while there is more beyond it. */
        @property --st-fade-start { syntax: "<length>"; inherits: false; initial-value: 0px; }
        @property --st-fade-end   { syntax: "<length>"; inherits: false; initial-value: 0px; }
        .st-nav { overflow-x: auto; margin-bottom: 1.25rem; scrollbar-width: none; -webkit-overflow-scrolling: touch; scroll-padding-inline: 2rem;
            --st-fade: 2rem;
            mask:
                linear-gradient(black, black) no-clip subtract,
                linear-gradient(to right, black, transparent) 0 0 / var(--st-fade-start) 100% no-repeat no-clip,
                linear-gradient(to right, transparent, black) 100% 0 / var(--st-fade-end) 100% no-repeat no-clip;
            transition: --st-fade-start .2s, --st-fade-end .2s; }
        .st-nav::-webkit-scrollbar { display: none; }
        @supports (animation-timeline: scroll()) {
            .st-nav { animation: st-fade-in linear both, st-fade-out linear both; animation-timeline: scroll(self inline);
                animation-range: 0 var(--st-fade), calc(100% - var(--st-fade)) 100%; }
        }
        @keyframes st-fade-in  { to   { --st-fade-start: var(--st-fade); } }
        @keyframes st-fade-out { from { --st-fade-end:   var(--st-fade); } }
        /* Where scroll-driven animations are missing, script marks the ends. */
        .st-nav.more-start { --st-fade-start: var(--st-fade); }
        .st-nav.more-end   { --st-fade-end:   var(--st-fade); }
        .st-nav .pk-seg { display: flex; width: max-content; min-width: 100%; }
        .st-nav .pk-seg button { flex: 1 0 auto; min-block-size: 40px; padding: .5rem .9rem; font-size: .86rem; }

        .st-pane { display: flex; flex-direction: column; gap: 1.25rem; }
        /* A plainly hidden section (no until-found support) is removed; one
           hidden until found is left to the browser, which hides its contents. */
        .st-pane[hidden]:not([hidden="until-found"]) { display: none; }
        .st-wrap .card { max-width: 100%; margin: 0; padding: 1.5rem; }
        .st-wrap .card h2 { font-size: 1.1rem; margin-bottom: .25rem; }
        .st-wrap .card .subtitle { margin-bottom: 1.1rem; }
        .st-two { display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem; align-items: start; }
        /* Fields run in ONE column, a comfortable line long, so the eye goes straight down. */
        .st-fields { max-inline-size: 34rem; }
        .st-wrap .form-group { margin-block-end: 1.1rem; }
        .st-wrap .form-group label { margin-block-end: .3rem; }
        .st-select, .st-wrap .form-group input:not([type=checkbox]) { font: inherit; font-size: max(1rem, 1em); min-block-size: 44px; box-sizing: border-box; }
        .st-select { inline-size: 100%; padding: .5rem .75rem; border: 1.5px solid var(--border); border-radius: 8px; background: var(--bg-ffffff, #ffffff); }
        .st-wrap :is(input, select):focus-visible { outline: 3px solid #1d4ed8; outline-offset: 2px; }
        .st-wrap .hint { font-size: .8rem; line-height: 1.45; color: var(--muted); margin-top: .3rem; }
        .st-actions { display: flex; justify-content: flex-start; gap: .6rem; flex-wrap: wrap; margin-top: .75rem; }
        .st-actions .btn { min-inline-size: 11rem; min-block-size: 44px; }
        .st-photo { display: flex; align-items: center; gap: 1rem; margin-bottom: 1.25rem; padding-bottom: 1.25rem; border-bottom: 1px solid var(--bd-f1f5f9, #f1f5f9); }
        .st-fieldset { border: 0; padding: 0; margin: .25rem 0 0; min-inline-size: 0; }
        .st-fieldset legend { font-size: .85rem; font-weight: 600; padding: 0; }
        /* Rows: a label on the left, a control or value on the right. */
        .st-rows { display: flex; flex-direction: column; }
        .st-row { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: .7rem 0; border-bottom: 1px solid var(--bd-f1f5f9, #f1f5f9); min-block-size: 48px; }
        .st-rows > .st-row:last-child { border-bottom: none; }
        .st-row-main { min-inline-size: 0; }
        .st-row-title { display: block; font-size: .92rem; font-weight: 600; color: var(--fg-1e293b, #1e293b); overflow-wrap: break-word; }
        a.st-row-title { color: var(--accent); overflow-wrap: anywhere; }
        .st-row-sub { display: block; font-size: .8rem; color: var(--muted); margin-top: .1rem; }
        .st-row-val { flex: none; font-size: .9rem; color: var(--fg-334155, #334155); text-align: end; }
        label.st-row { cursor: pointer; }
        /* A checkbox drawn as a switch. It stays a real checkbox: it submits,
           takes Space, and is announced as a switch. */
        .st-switch { appearance: none; -webkit-appearance: none; flex: none; inline-size: 44px; block-size: 26px; margin: 0; border-radius: 13px; background: #94a3b8; position: relative; cursor: pointer; transition: background .15s; accent-color: var(--accent); }
        .st-switch::after { content: ""; position: absolute; inset-block-start: 3px; inset-inline-start: 3px; inline-size: 20px; block-size: 20px; border-radius: 50%; background: var(--bg-ffffff, #ffffff); box-shadow: 0 1px 2px rgba(15,23,42,.3); transition: translate .15s; }
        .st-switch:checked { background: var(--accent); }
        .st-switch:checked::after { translate: 18px 0; }
        .st-switch:focus-visible { outline: 3px solid #1d4ed8; outline-offset: 3px; }
        .st-switch:disabled { opacity: .55; cursor: default; }
        /* A token: its row, then a disclosure with what it has been doing. */
        .st-token { border-bottom: 1px solid var(--bd-f1f5f9, #f1f5f9); }
        .st-token:last-child { border-bottom: none; }
        .st-token > .st-row { border-bottom: none; }
        .st-activity { margin: 0 0 .75rem; }
        .st-activity summary { cursor: pointer; font-size: .85rem; font-weight: 600; color: var(--accent); min-block-size: 32px; display: flex; align-items: center; gap: .4rem; inline-size: fit-content; list-style: none; }
        .st-activity summary::-webkit-details-marker { display: none; }
        .st-activity summary::before { content: ""; inline-size: .45rem; block-size: .45rem; border-inline-end: 2px solid currentColor; border-block-end: 2px solid currentColor; rotate: -45deg; transition: rotate .15s; }
        .st-activity[open] summary::before { rotate: 45deg; }
        .st-activity summary:focus-visible { outline: 3px solid #1d4ed8; outline-offset: 2px; border-radius: 4px; }
        .st-activity-list { list-style: none; margin: .4rem 0 0; padding: 0; border: 1px solid var(--border); border-radius: 8px; max-block-size: 22rem; overflow-y: auto; }
        .st-activity-list li { display: grid; grid-template-columns: 9.5rem minmax(0, 1fr) auto; gap: .15rem .9rem; align-items: baseline; padding: .5rem .75rem; border-bottom: 1px solid var(--bd-f1f5f9, #f1f5f9); font-size: .82rem; }
        .st-activity-list li:last-child { border-bottom: none; }
        .st-activity-list time { color: var(--muted); font-variant-numeric: tabular-nums; }
        .st-activity-list code { font-size: .8rem; overflow-wrap: anywhere; }
        .st-activity-status { color: var(--fg-166534, #166534); font-weight: 600; text-align: end; }
        .st-activity-status.is-refused { color: var(--fg-991b1b, #991b1b); }
        .st-activity-ip { grid-column: 2 / -1; color: var(--muted); font-size: .76rem; }
        .st-note { font-size: .8rem; color: var(--muted); margin: .75rem 0 0; }
        .st-token-new { background: var(--bg-fffbeb, #fffbeb); border: 1.5px solid #f59e0b; border-radius: 8px; padding: .85rem 1rem; margin-bottom: 1rem; }
        .st-token-new code { display: block; overflow-wrap: anywhere; background: var(--bg-ffffff, #ffffff); border: 1px solid var(--bd-fde68a, #fde68a); border-radius: 6px; padding: .5rem .6rem; font-size: .8rem; }
        .st-danger { border-color: var(--bd-fca5a5, #fca5a5) !important; }
        .st-danger h2 { color: var(--fg-dc2626, #dc2626); }
        .btn-danger { background: #dc2626; color: #fff; }
        .btn-danger-outline { background: transparent; border: 1.5px solid var(--bd-fca5a5, #fca5a5); color: var(--fg-dc2626, #dc2626); }

        /* Layout answers to the width of the settings column, not the window. */
        @container settings (inline-size < 44rem) {
            .st-two { grid-template-columns: 1fr; }
            .st-fields { max-inline-size: none; }
            .st-actions .btn { inline-size: 100%; min-block-size: 48px; }
            /* The site flattens cards on small screens; here they stay cards,
               so the sections of a long page read as separate things. */
            .st-wrap .card { padding: 1.1rem 1rem; border: 1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow); }
            .st-wrap .form-group label { font-size: .85rem; }
            .st-wrap .form-group input:not([type=checkbox]), .st-select { padding: .55rem .75rem; }
            .st-nav .pk-seg button { padding: .5rem .3rem; font-size: .76rem; }
            /* The activity list stacks: when, then what, then how it went. */
            .st-activity-list li { grid-template-columns: minmax(0, 1fr) auto; }
            .st-activity-list time { grid-column: 1 / -1; }
            .st-activity-ip { grid-column: 1 / -1; }
            .st-head h1 { font-size: 1.2rem; }
        }
        @media (max-width: 720px) {
            .st-wrap { padding: 0 .75rem !important; margin-top: 1rem !important; }
        }
        /* A finger is less exact than a mouse pointer, whatever the screen size. */
        @media (pointer: coarse) {
            .st-wrap :is(.btn, .st-select, .form-group input:not([type=checkbox])) { min-block-size: 48px; }
            .st-nav .pk-seg button { min-block-size: 44px; }
            .st-row { min-block-size: 52px; }
            .st-activity summary { min-block-size: 44px; }
        }
        /* Forced colours drop backgrounds, which is all the sliding thumb and
           the switch are drawn with: mark the open section with a border, and
           hand the switch back to the browser. */
        @media (forced-colors: active) {
            #stSeg button[aria-selected="true"]::after { content: ""; position: absolute; inset: auto 0 0; border-block-end: 3px solid Highlight; }
            .st-switch { appearance: auto; -webkit-appearance: auto; inline-size: auto; block-size: auto; }
            .st-switch::after { display: none; }
        }
        @media (prefers-reduced-motion: reduce) {
            .st-switch, .st-switch::after, .st-nav, .st-activity summary::before { transition: none; }
        }
    </style>
    <!-- Without script the switcher cannot work, so every section shows. -->
    <noscript><style>.st-nav { display: none; } .st-pane[hidden] { display: flex !important; content-visibility: visible !important; }</style></noscript>
</head>
<body>

<?php $nav_active = 'settings'; $nav_user = $me; require __DIR__ . '/_nav.php'; ?>
</nav>

<div role="main" class="dash-wrap st-wrap" id="stWrap">

    <div class="st-head">
        <?= avatar_html($current['username'], $current['avatar_path'] ?? null, 56) ?>
        <div style="min-width:0">
            <h1><?= htmlspecialchars($me['username'], ENT_QUOTES | ENT_SUBSTITUTE) ?></h1>
            <p><?= htmlspecialchars($me['email'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE) ?><?= $me['role'] === 'admin' ? ' &middot; <span class="badge badge-admin">admin</span>' : '' ?></p>
        </div>
    </div>

    <?php if (!empty($_GET['must_change'])): ?>
        <div class="alert alert-error" style="margin-bottom:1.25rem">
            You are using the default password. You must change it before continuing.
        </div>
    <?php endif; ?>

    <?php if ($flash['msg']): ?>
        <div class="alert alert-<?= $flash['type'] === 'error' ? 'error' : 'success' ?>" style="margin-bottom:1.25rem">
            <?= htmlspecialchars($flash['msg']) ?>
        </div>
    <?php endif; ?>

    <!-- Section switcher: the house segmented control (pk-seg.js). -->
    <div class="st-nav">
        <div class="pk-seg" id="stSeg" data-seg-role="tablist" aria-label="Settings sections">
            <span class="pk-seg-thumb"></span>
            <?php foreach (ST_PANES as $_p => $_label): ?>
            <button type="button" id="st-tab-<?= $_p ?>" data-pane="<?= $_p ?>" data-act="stTab" role="tab" aria-selected="<?= $_p === $st_pane ? 'true' : 'false' ?>"<?= $_p === $st_pane ? ' class="active"' : '' ?>><?= $_label ?></button>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- ═══ Profile ═══ -->
    <section <?= $st_panel('profile') ?>>
        <div class="card">
            <h2 id="st-h-profile">Profile</h2>
            <p class="subtitle">Your name, photo and how you sign in.</p>

            <!-- Profile photo -->
            <div class="st-photo">
                <span id="stAvatarPreview"><?= avatar_html($current['username'], $current['avatar_path'] ?? null, 64) ?></span>
                <div>
                    <div style="font-size:.85rem;font-weight:600;color:var(--fg-475569, #475569);margin-bottom:.35rem">Profile photo</div>
                    <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center">
                        <button type="button" class="btn btn-outline" style="font-size:.82rem" data-click-file="stAvatarFile">Upload photo</button>
                        <input type="file" id="stAvatarFile" accept="image/*" style="display:none">
                        <?php if (!empty($current['avatar_path'])): ?>
                        <form method="post" action="/settings.php" style="margin:0" data-confirm="Remove your profile photo?" data-confirm-ok="Remove" data-confirm-danger="1">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>"><input type="hidden" name="st_pane" value="profile">
                            <input type="hidden" name="action" value="remove_avatar">
                            <button type="submit" class="btn btn-outline" style="font-size:.82rem;color:var(--fg-dc2626, #dc2626);border-color:var(--bd-fecaca, #fecaca)">Remove</button>
                        </form>
                        <?php endif; ?>
                    </div>
                    <div style="font-size:.75rem;color:var(--fg-64748b, #64748b);margin-top:.3rem">JPEG/PNG/GIF/WebP. Big pictures are shrunk to 256&nbsp;px when you upload them. No photo shows your initial in a colored circle.</div>
                </div>
            </div>
            <form id="stAvatarForm" method="post" action="/settings.php" style="display:none">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>"><input type="hidden" name="st_pane" value="profile">
                <input type="hidden" name="action" value="set_avatar">
                <input type="hidden" name="avatar_path" id="stAvatarPath">
            </form>
            <script nonce="<?= csp_nonce() ?>">
            // An avatar is never drawn bigger than 64px, and a connected app draws
            // eight at once at a poker table, so the camera original is shrunk to a
            // 256px square here rather than stored and served whole. Square because
            // every place that draws one crops to a circle anyway. If the browser
            // cannot do it the original goes as before and upload.php decides.
            function shrinkAvatar(file) {
                if (!window.createImageBitmap || !document.createElement('canvas').toBlob) {
                    return Promise.resolve(file);
                }
                return createImageBitmap(file, { imageOrientation: 'from-image' })
                    .then(function (bmp) {
                        var side = Math.min(bmp.width, bmp.height);
                        var out = Math.min(256, side);
                        var c = document.createElement('canvas');
                        c.width = out; c.height = out;
                        var ctx = c.getContext('2d');
                        ctx.imageSmoothingQuality = 'high';
                        ctx.drawImage(bmp, (bmp.width - side) / 2, (bmp.height - side) / 2,
                            side, side, 0, 0, out, out);
                        bmp.close && bmp.close();
                        return new Promise(function (resolve) {
                            c.toBlob(function (blob) {
                                resolve(blob && blob.size < file.size ? blob : file);
                            }, 'image/webp', 0.82);
                        });
                    })
                    .catch(function () { return file; });
            }
            document.getElementById('stAvatarFile').addEventListener('change', function () {
                var f = this.files && this.files[0];
                if (!f) return;
                var self = this;
                shrinkAvatar(f).then(function (img) {
                var fd = new FormData();
                fd.append('csrf_token', <?= js_json($token) ?>);
                fd.append('image', img, img.name || 'avatar.webp');
                fd.append('feature', 'avatars');
                fetch('/upload.php', { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (j) {
                        if (!j.url) { pkAlert(j.error || 'Upload failed.'); self.value = ''; return; }
                        document.getElementById('stAvatarPath').value = j.url;
                        document.getElementById('stAvatarForm').submit();
                    })
                    .catch(function () { pkAlert('Upload failed.'); self.value = ''; });
                });
            });
            </script>


            <!-- The one profile form. The Preferences and Notifications panes hold
                 more of its fields, tied to it by the form="stProfileForm" attribute. -->
            <form method="post" action="/settings.php" id="stProfileForm" aria-labelledby="st-h-profile">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>"><input type="hidden" name="st_pane" value="profile">
                <input type="hidden" name="action" value="update_profile">
                <div class="st-fields">
                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username"
                           autocomplete="username" autocorrect="off" autocapitalize="none" spellcheck="false" required
                           value="<?= htmlspecialchars($me['username']) ?>">
                </div>
                <div class="form-group">
                    <label for="email">Email <span style="color:var(--fg-64748b, #64748b);font-size:.8rem;font-weight:400">(used to sign in)</span></label>
                    <input type="email" id="email" name="email"
                           autocomplete="email" autocorrect="off" autocapitalize="none" spellcheck="false" required
                           value="<?= htmlspecialchars($me['email'] ?? '') ?>">
                    <?php if (!empty($me['pending_email'])): ?>
                    <p class="hint" style="margin-top:.3rem">Pending: <strong><?= htmlspecialchars($me['pending_email']) ?></strong>. A confirmation link was sent there; your email changes when you use it.
                        <button type="submit" form="emailCancelForm" style="background:none;border:none;color:var(--fg-2563eb, #2563eb);cursor:pointer;font:inherit;padding:0;text-decoration:underline">Cancel the change</button></p>
                    <?php else: ?>
                    <p class="hint" style="margin-top:.3rem">A new address takes effect once you confirm it from a link sent there.</p>
                    <?php endif; ?>
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
                            <span style="white-space:nowrap;color:var(--fg-16a34a, #16a34a);font-weight:600;font-size:.85rem">&#10003; Verified</span>
                        <?php elseif ($_hasPhone && !$_phonePend): ?>
                            <!-- submits the separate phSendForm via the HTML form= attribute (not the profile form) -->
                            <button type="submit" form="phSendForm" class="btn" style="white-space:nowrap">Verify number</button>
                        <?php endif; ?>
                    </div>
                    <?php if ($_hasPhone && !$_phoneOk && $_phonePend): ?>
                    <div style="display:flex;gap:.5rem;align-items:center;margin-top:.45rem">
                        <input type="text" name="verify_code" form="phVerifyForm" inputmode="numeric" maxlength="6" pattern="\d{6}" required
                               autocomplete="one-time-code" placeholder="000000"
                               style="flex:1;min-width:0;padding:.45rem .6rem;border:1.5px solid var(--bd-e2e8f0, #e2e8f0);border-radius:7px;letter-spacing:.2em;text-align:center">
                        <button type="submit" form="phVerifyForm" class="btn btn-primary" style="white-space:nowrap">Verify</button>
                        <button type="submit" form="phSendForm" class="btn" style="white-space:nowrap">Resend</button>
                    </div>
                    <p class="hint" style="margin-top:.3rem">Enter the 6-digit code we texted to <?= htmlspecialchars($me['phone']) ?>.</p>
                    <?php elseif ($_hasPhone && !$_phoneOk): ?>
                    <p class="hint" style="margin-top:.3rem">Verify your number to use SMS for two-factor sign-in. If you just changed it, click Save Profile first.</p>
                    <?php endif; ?>
                    <p style="margin-top:.4rem;font-size:.75rem;line-height:1.4;color:var(--fg-64748b, #64748b)">By providing your phone number, you consent to receive event-related SMS messages (invites, reminders, RSVP updates). Message frequency varies. Message and data rates may apply. Reply STOP to unsubscribe, HELP for help. <a href="/privacy.php" target="_blank">Privacy Policy</a>.</p>
                </div>
                <div class="form-group">
                    <label for="profile_current_password">Current password <span style="color:var(--fg-64748b, #64748b);font-size:.8rem;font-weight:400">(needed to change your username, email or phone)</span></label>
                    <input type="password" id="profile_current_password" name="current_password" autocomplete="current-password">
                </div>
                </div>
                <div class="st-actions"><button type="submit" class="btn btn-primary">Save Profile</button></div>
            </form>

            <!-- Phone-verification and email-change helper forms: kept OUTSIDE the profile
                 form (forms can't nest). The visible buttons + code input live next to the
                 phone and email fields above and target these via the HTML form="..." attribute. -->
            <form id="emailCancelForm" method="post" action="/settings.php" style="display:none">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>"><input type="hidden" name="st_pane" value="profile">
                <input type="hidden" name="action" value="cancel_email_change">
            </form>
            <form id="phSendForm" method="post" action="/settings.php" style="display:none">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>"><input type="hidden" name="st_pane" value="profile">
                <input type="hidden" name="action" value="send_phone_code">
            </form>
            <form id="phVerifyForm" method="post" action="/settings.php" style="display:none">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>"><input type="hidden" name="st_pane" value="profile">
                <input type="hidden" name="action" value="verify_phone_code">
            </form>
        </div>
    </section>

    <!-- ═══ Preferences ═══ -->
    <section <?= $st_panel('preferences') ?>>
        <div class="card">
            <h2>Preferences</h2>
            <p class="subtitle">How the site looks and behaves for you.</p>
            <div class="st-fields">
                <div class="form-group">
                    <label for="theme">Appearance</label>
                    <select id="theme" name="theme" class="st-select" form="stProfileForm">
                        <option value="">Match this device</option>
                        <option value="light"<?= ($me['theme'] ?? '') === 'light' ? ' selected' : '' ?>>Light</option>
                        <option value="dark"<?= ($me['theme'] ?? '') === 'dark' ? ' selected' : '' ?>>Dark</option>
                    </select>
                    <p class="hint">"Match this device" follows your phone or computer's dark mode setting. The timer and TV screens keep their own look.</p>
                </div>
                <div class="form-group">
                    <label for="timezone">Timezone</label>
                    <select id="timezone" name="timezone" class="st-select" form="stProfileForm">
                        <option value="">Use site default (<?= htmlspecialchars(get_setting('timezone', 'UTC')) ?>)</option>
                        <?php foreach (get_timezone_options() as $tz_label => $tz_id): ?>
                        <option value="<?= htmlspecialchars($tz_id) ?>"<?= ($me['timezone'] ?? '') === $tz_id ? ' selected' : '' ?>><?= htmlspecialchars($tz_label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="hint">Event times and the footer clock will display in this timezone. Notifications sent to you will also use it.</p>
                </div>
                <div class="form-group">
                    <label for="my_events_past_days">My Events — Past Range</label>
                    <select id="my_events_past_days" name="my_events_past_days" class="st-select" form="stProfileForm">
                        <?php foreach ([7=>'7 days',14=>'14 days',30=>'30 days',60=>'60 days',90=>'90 days',180=>'6 months',365=>'1 year'] as $v=>$l): ?>
                        <option value="<?= $v ?>"<?= (int)($me['my_events_past_days'] ?? 30) === $v ? ' selected' : '' ?>><?= $l ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="hint">How far back past events appear on the My Events page.</p>
                </div>
                <div class="form-group">
                    <label for="beta_timer">Tournament timer</label>
                    <select id="beta_timer" name="beta_timer" class="st-select" form="stProfileForm">
                        <option value="0">Tournament Timer Classic</option>
                        <option value="1"<?= (int)($me['beta_timer'] ?? 1) === 1 ? ' selected' : '' ?>>Tournament Timer (designable layouts)</option>
                    </select>
                    <p class="hint">New games you set up will use this timer display. Each game can still switch in its Setup, and this doesn't change games you've already configured.</p>
                </div>
            </div>
            <div class="st-actions"><button type="submit" form="stProfileForm" name="st_pane" value="preferences" class="btn btn-primary">Save Preferences</button></div>
        </div>

        <div class="card">
            <h2>Help Tips</h2>
            <p class="subtitle">Bring back the in-app help bubbles you've dismissed. They'll show again on every screen that has tips.</p>
            <form method="post" action="/settings.php">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>"><input type="hidden" name="st_pane" value="preferences">
                <input type="hidden" name="action" value="reset_help">
                <div class="st-actions" style="margin-top:0"><button type="submit" class="btn btn-outline">Show all help tips again</button></div>
            </form>
        </div>
    </section>

    <!-- ═══ Notifications ═══ -->
    <section <?= $st_panel('notifications') ?>>
        <div class="card">
            <h2>How to reach you</h2>
            <p class="subtitle">Where invitations, reminders and updates are sent.</p>
                <div class="form-group">
                    <label for="preferred_contact">Preferred Contact Method</label>
                    <select id="preferred_contact" name="preferred_contact" class="st-select" form="stProfileForm">
                        <option value="email"<?= ($me['preferred_contact'] ?? 'email') === 'email' ? ' selected' : '' ?>>Email</option>
                        <option value="sms"<?= ($me['preferred_contact'] ?? '') === 'sms'   ? ' selected' : '' ?>>SMS (text message)</option>
                        <option value="whatsapp"<?= ($me['preferred_contact'] ?? '') === 'whatsapp' ? ' selected' : '' ?>>WhatsApp</option>
                        <option value="both"<?= ($me['preferred_contact'] ?? '') === 'both'  ? ' selected' : '' ?>>Email &amp; SMS</option>
                        <option value="none"<?= ($me['preferred_contact'] ?? '') === 'none'  ? ' selected' : '' ?>>None (do not notify me)</option>
                    </select>
                    <p class="hint">How you want to be notified when invited to an event.</p>
                </div>
            <div class="st-actions"><button type="submit" form="stProfileForm" name="st_pane" value="notifications" class="btn btn-primary">Save contact method</button></div>
        </div>

        <div class="card">
            <h2 id="st-h-notify">What to send</h2>
            <p class="subtitle">Choose which notifications reach you by email or text. Everything still appears in your <a href="/notifications.php">in-app inbox</a>.</p>
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
                'dms'          => ['Direct messages', 'When another user sends you a private message'],
                'tickets'      => ['Support tickets', 'Replies on your support tickets (admins: new tickets)'],
                'rewards'      => ['Rewards', 'Entry tickets and prizes you win at games'],
            ];
            // Security alerts go to admins only (security_alert() in auth.php).
            if (($me['role'] ?? '') === 'admin') {
                $_np_rows['security'] = ['Security alerts (admins)', 'Sign-up abuse and sign-in lockouts: the honeypot, rate limits, withheld verification mail'];
            }
            ?>
            <form method="post" action="/settings.php" aria-labelledby="st-h-notify">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>"><input type="hidden" name="st_pane" value="notifications">
                <input type="hidden" name="action" value="notify_prefs">
                <div class="st-rows" role="group" aria-labelledby="st-h-notify">
                    <?php foreach ($_np_rows as $_c => [$_l, $_d]): ?>
                    <label class="st-row">
                        <span class="st-row-main">
                            <span class="st-row-title"><?= $_l ?></span>
                            <span class="st-row-sub"><?= $_d ?></span>
                        </span>
                        <input type="checkbox" switch role="switch" class="st-switch" name="np_<?= $_c ?>" value="1" <?= $_np_on($_c) ? 'checked' : '' ?>>
                    </label>
                    <?php endforeach; ?>
                </div>
                <div class="st-actions"><button type="submit" class="btn btn-primary">Save Notifications</button></div>
            </form>
        </div>

        <div class="st-two">
            <div class="card">
                <h2>On this device</h2>
                <p class="subtitle">Get notifications on this device even when the site isn't open. Each device you enable gets its own subscription.</p>
            <p id="pushStatus" style="font-size:.85rem;color:var(--fg-64748b, #64748b);margin-bottom:.8rem">Checking this browser&hellip;</p>
            <div style="display:flex;gap:.6rem;flex-wrap:wrap">
                <button type="button" class="btn btn-primary" id="pushEnableBtn" data-act="pushEnable" style="display:none">Enable on this device</button>
                <button type="button" class="btn btn-outline" id="pushDisableBtn" data-act="pushDisable" style="display:none">Disable on this device</button>
                <button type="button" class="btn btn-outline" id="pushTestBtn" data-act="pushTest" style="display:none">Send test notification</button>
            </div>
            <p style="font-size:.75rem;color:var(--fg-64748b, #64748b);margin-top:.7rem">iPhone/iPad: add this site to your Home Screen first (Share &rarr; Add to Home Screen), then enable from the installed app.</p>
            </div>

            <!-- Install as an app: the permanent home for the Add to Home Screen
                 card, for anyone who dismissed it or never saw it (a laptop). -->
            <div class="card">
                <h2>Install as an App</h2>
                <p class="subtitle">Put <?= htmlspecialchars($site_name, ENT_QUOTES | ENT_SUBSTITUTE) ?> on your Home Screen. It opens full screen like an app, and on iPhone and iPad it is what makes notifications possible.</p>
                <div style="display:flex;gap:.6rem;flex-wrap:wrap">
                    <button type="button" class="btn btn-primary" data-act="a2hsInstallNow">Add to Home Screen</button>
                </div>
                <p class="st-note">iPhone/iPad: Safari, then Share &rarr; Add to Home Screen. Android: Chrome offers an Install button. Computers: Chrome and Edge show an install icon in the address bar.</p>
            </div>
        </div>
    </section>

    <!-- ═══ Security ═══ -->
    <section <?= $st_panel('security') ?>>
        <div class="st-two">
            <div class="card">
                <h2 id="st-h-password">Password</h2>
                <p class="subtitle" id="st-pw-help">Minimum <?= (int)MIN_PASSWORD_LENGTH ?> characters. Changing it signs out every other device and revokes every API token.</p>
            <form method="post" action="/settings.php" aria-labelledby="st-h-password">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>"><input type="hidden" name="st_pane" value="security">
                <input type="hidden" name="action" value="update_password">
                <div class="form-group">
                    <label for="current_password">Current Password</label>
                    <input type="password" id="current_password" name="current_password"
                           autocomplete="current-password" required>
                </div>
                <div class="form-group">
                    <label for="new_password">New Password</label>
                    <input type="password" id="new_password" name="new_password"
                           autocomplete="new-password" minlength="<?= (int)MIN_PASSWORD_LENGTH ?>" aria-describedby="st-pw-help" required>
                </div>
                <div class="form-group">
                    <label for="confirm_password">Confirm New Password</label>
                    <input type="password" id="confirm_password" name="confirm_password"
                           autocomplete="new-password" required>
                </div>
                <div class="st-actions"><button type="submit" class="btn btn-primary">Update Password</button></div>
            </form>
            </div>

            <div class="card">
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
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>"><input type="hidden" name="st_pane" value="security">
                    <input type="hidden" name="action" value="regen">
                    <div class="form-group">
                        <label for="mfa_regen_pw">Enter your password to regenerate recovery codes</label>
                        <input type="password" id="mfa_regen_pw" name="current_password"
                               autocomplete="current-password" required>
                    </div>
                    <div class="st-actions"><button type="submit" class="btn btn-outline">Regenerate Recovery Codes</button></div>
                </form>
                <form method="post" action="/settings.php">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>"><input type="hidden" name="st_pane" value="security">
                    <input type="hidden" name="action" value="mfa_disable">
                    <div class="form-group">
                        <label for="mfa_disable_pw">Enter your password to turn off two-factor</label>
                        <input type="password" id="mfa_disable_pw" name="current_password"
                               autocomplete="current-password" required>
                    </div>
                    <div class="st-actions"><button type="submit" class="btn btn-danger">Disable Two-Factor</button></div>
                </form>

            <?php else: ?>
                <!-- Off: enrollment happens on the dedicated setup page -->
                <p style="color:var(--fg-64748b, #64748b);margin-bottom:1rem">Currently <strong>off</strong>. Protect your account with an authenticator app or texted codes.</p>
                <div class="st-actions"><a href="/mfa_setup.php" class="btn btn-primary" style="text-align:center;text-decoration:none">Set up two-factor authentication</a></div>
            <?php endif; ?>
            </div>
        </div>

        <!-- Apps connected by signing in -->
        <div class="card" id="connected-apps">
            <h2 id="st-h-apps">Connected Apps</h2>
            <p class="subtitle">Apps you have signed in to with this account, such as Claude. Each can act as you, within what you allowed.</p>
            <?php if (!$connected_apps): ?>
                <p class="st-note" style="margin-top:0">Nothing is connected. To connect Claude, add this address as a custom connector in Claude (Customize, then Connectors, then Add custom connector) and sign in when it asks:</p>
                <code style="display:block;overflow-wrap:anywhere;background:var(--bg-f1f5f9, #f1f5f9);border-radius:6px;padding:.5rem .6rem;font-size:.8rem;margin-top:.5rem"><?= htmlspecialchars(rtrim(get_site_url(), '/') . '/mcp', ENT_QUOTES | ENT_SUBSTITUTE) ?></code>
            <?php else: ?>
                <div class="st-rows">
                <?php foreach ($connected_apps as $t): $_acts = $token_activity[(int)$t['id']] ?? []; ?>
                  <div class="st-token">
                    <div class="st-row">
                        <div class="st-row-main">
                            <span class="st-row-title"><?= htmlspecialchars($t['label'], ENT_QUOTES | ENT_SUBSTITUTE) ?></span>
                            <span class="st-row-sub">Can: <?= htmlspecialchars(implode(', ', api_token_clean_scopes((string)$t['scopes'])), ENT_QUOTES | ENT_SUBSTITUTE) ?></span>
                            <span class="st-row-sub">
                                Connected <?= htmlspecialchars($st_when((string)$t['created_at'], 'M j, Y'), ENT_QUOTES | ENT_SUBSTITUTE) ?>
                                &middot; <?= $t['last_used_at'] ? 'last used ' . htmlspecialchars($st_when((string)$t['last_used_at'], 'M j, Y g:i A'), ENT_QUOTES | ENT_SUBSTITUTE) : 'never used' ?>
                                &middot; <?= (int)$t['requests'] ?> request<?= (int)$t['requests'] === 1 ? '' : 's' ?> in the last 30 days
                            </span>
                        </div>
                        <form method="post" action="/settings.php" style="margin:0;flex:none">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>"><input type="hidden" name="st_pane" value="security">
                            <input type="hidden" name="action" value="oauth_disconnect">
                            <input type="hidden" name="client_pk" value="<?= (int)$t['client_pk'] ?>">
                            <button type="submit" class="btn btn-sm btn-danger-outline">Disconnect</button>
                        </form>
                    </div>
                    <?php if ($_acts): ?>
                    <details class="st-activity">
                        <summary>Recent activity</summary>
                        <ol class="st-activity-list">
                        <?php foreach ($_acts as $_a): $_code = (int)$_a['status']; ?>
                            <li>
                                <time datetime="<?= htmlspecialchars(str_replace(' ', 'T', (string)$_a['created_at']) . 'Z', ENT_QUOTES | ENT_SUBSTITUTE) ?>"><?= htmlspecialchars($st_when((string)$_a['created_at'], 'M j, g:i:s A'), ENT_QUOTES | ENT_SUBSTITUTE) ?></time>
                                <code><?= htmlspecialchars('Claude: ' . (preg_replace('#^/mcp/(tools/)?#', '', (string)$_a['path']) ?: 'connect'), ENT_QUOTES | ENT_SUBSTITUTE) ?></code>
                                <span class="st-activity-status<?= $_code >= 400 ? ' is-refused' : '' ?>"><?= $_code ?> <?= htmlspecialchars($st_status($_code), ENT_QUOTES | ENT_SUBSTITUTE) ?></span>
                                <span class="st-activity-ip">from <?= htmlspecialchars((string)$_a['ip'], ENT_QUOTES | ENT_SUBSTITUTE) ?></span>
                            </li>
                        <?php endforeach; ?>
                        </ol>
                    </details>
                    <?php endif; ?>
                  </div>
                <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Personal API tokens -->
        <div class="card" id="api-tokens">
            <h2 id="st-h-tokens">API Tokens</h2>
            <p class="subtitle">A token lets another program, such as an AI assistant, work with your events and games as you. It can do only what you allow below, and never more than you can do yourself.</p>

            <?php if ($new_api_token): ?>
                <div class="st-token-new">
                    <p style="margin:0 0 .5rem;color:var(--fg-92400e, #92400e);font-weight:600">Copy this token now. It will not be shown again.</p>
                    <code id="stNewToken"><?= htmlspecialchars($new_api_token['token'], ENT_QUOTES | ENT_SUBSTITUTE) ?></code>
                    <button type="button" class="btn btn-outline" data-act="stCopyToken" style="margin-top:.6rem">Copy</button>
                </div>
            <?php endif; ?>

            <?php if ($api_tokens): ?>
                <div class="st-rows" style="margin-bottom:1.25rem">
                <?php foreach ($api_tokens as $t): $_acts = $token_activity[(int)$t['id']] ?? []; ?>
                  <div class="st-token">
                    <div class="st-row">
                        <div class="st-row-main">
                            <span class="st-row-title"><?= htmlspecialchars($t['label'], ENT_QUOTES | ENT_SUBSTITUTE) ?></span>
                            <span class="st-row-sub">Can: <?= htmlspecialchars(implode(', ', api_token_clean_scopes((string)$t['scopes'])), ENT_QUOTES | ENT_SUBSTITUTE) ?></span>
                            <span class="st-row-sub">
                                Created <?= htmlspecialchars($st_when((string)$t['created_at'], 'M j, Y'), ENT_QUOTES | ENT_SUBSTITUTE) ?>
                                &middot; <?= $t['last_used_at'] ? 'last used ' . htmlspecialchars($st_when((string)$t['last_used_at'], 'M j, Y g:i A'), ENT_QUOTES | ENT_SUBSTITUTE) : 'never used' ?>
                                &middot; <?= (int)$t['requests'] ?> request<?= (int)$t['requests'] === 1 ? '' : 's' ?> in the last 30 days
                            </span>
                        </div>
                        <form method="post" action="/settings.php" style="margin:0;flex:none">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>"><input type="hidden" name="st_pane" value="security">
                            <input type="hidden" name="action" value="api_token_revoke">
                            <input type="hidden" name="token_id" value="<?= (int)$t['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-danger-outline">Revoke</button>
                        </form>
                    </div>
                    <?php if ($_acts): ?>
                    <details class="st-activity">
                        <summary>Recent activity</summary>
                        <ol class="st-activity-list">
                        <?php foreach ($_acts as $_a): $_code = (int)$_a['status']; ?>
                            <li>
                                <time datetime="<?= htmlspecialchars(str_replace(' ', 'T', (string)$_a['created_at']) . 'Z', ENT_QUOTES | ENT_SUBSTITUTE) ?>"><?= htmlspecialchars($st_when((string)$_a['created_at'], 'M j, g:i:s A'), ENT_QUOTES | ENT_SUBSTITUTE) ?></time>
                                <code><?= htmlspecialchars(str_starts_with((string)$_a['path'], '/mcp')
                                    ? 'Claude: ' . (preg_replace('#^/mcp/(tools/)?#', '', (string)$_a['path']) ?: 'connect')
                                    : $_a['method'] . ' ' . (preg_replace('#^/api/v2#', '', (string)$_a['path']) ?: '/'), ENT_QUOTES | ENT_SUBSTITUTE) ?></code>
                                <span class="st-activity-status<?= $_code >= 400 ? ' is-refused' : '' ?>"><?= $_code ?> <?= htmlspecialchars($st_status($_code), ENT_QUOTES | ENT_SUBSTITUTE) ?></span>
                                <span class="st-activity-ip">from <?= htmlspecialchars((string)$_a['ip'], ENT_QUOTES | ENT_SUBSTITUTE) ?></span>
                            </li>
                        <?php endforeach; ?>
                        </ol>
                        <p class="st-note" style="margin-top:.5rem">The latest <?= count($_acts) ?> of <?= (int)$t['requests'] ?> in the last 30 days, newest first. Requests are kept for 30 days.</p>
                    </details>
                    <?php endif; ?>
                  </div>
                <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form method="post" action="/settings.php" aria-labelledby="st-h-tokens">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>"><input type="hidden" name="st_pane" value="security">
                <input type="hidden" name="action" value="api_token_create">
                <div class="st-fields">
                    <div class="form-group">
                        <label for="api_token_label">Name for a new token</label>
                        <input type="text" id="api_token_label" name="label" maxlength="80" autocomplete="off" aria-describedby="st-token-name-help" required>
                        <p class="hint" id="st-token-name-help">Something that tells you later what it is for, such as "Claude on my laptop".</p>
                    </div>
                    <div class="form-group">
                        <label for="api_token_pw">Your password</label>
                        <input type="password" id="api_token_pw" name="current_password" autocomplete="current-password" required>
                    </div>
                </div>
                <fieldset class="st-fieldset">
                <legend>What it may do</legend>
                <div class="st-rows">
                    <?php foreach (API_TOKEN_SCOPES as $sc => $desc): ?>
                    <label class="st-row">
                        <span class="st-row-main"><span class="st-row-title" style="font-weight:500"><?= htmlspecialchars($desc) ?></span></span>
                        <input type="checkbox" switch role="switch" class="st-switch" name="scopes[]" value="<?= htmlspecialchars($sc) ?>" <?= $sc === 'read' ? 'checked disabled' : '' ?>>
                    </label>
                    <?php endforeach; ?>
                </div>
                </fieldset>
                <div class="st-actions"><button type="submit" class="btn btn-primary">Create Token</button></div>
            </form>
            <p class="st-note">The jackpot permission is only needed to put a jackpot amount on a game. <a href="https://github.com/Isorgcom/GameNight/blob/main/DOCS.md#personal-api-v2" target="_blank" rel="noopener">How to use a token</a></p>

            <details class="st-activity" style="margin-top:1rem">
                <summary>Use it with Claude</summary>
                <div style="margin-top:.5rem;font-size:.88rem;line-height:1.55">
                    <p style="margin:0 0 .5rem">Most people do not need a token for Claude: add this site as a connector in Claude and sign in when it asks (see Connected Apps above). A token is for a program that cannot sign in that way. With one, point it at:</p>
                    <code id="stMcpUrl" style="display:block;overflow-wrap:anywhere;background:var(--bg-f1f5f9, #f1f5f9);border-radius:6px;padding:.5rem .6rem;font-size:.8rem"><?= htmlspecialchars(rtrim(get_site_url(), '/') . '/mcp', ENT_QUOTES | ENT_SUBSTITUTE) ?></code>
                    <p style="margin:.75rem 0 .35rem">In Claude Code, with a token in place of <code>TOKEN</code> (leave off <code>--header</code> and everything after it to sign in instead):</p>
                    <code style="display:block;overflow-wrap:anywhere;background:var(--bg-f1f5f9, #f1f5f9);border-radius:6px;padding:.5rem .6rem;font-size:.8rem">claude mcp add --transport http gamenight <?= htmlspecialchars(rtrim(get_site_url(), '/') . '/mcp', ENT_QUOTES | ENT_SUBSTITUTE) ?> --header "Authorization: Bearer TOKEN"</code>
                    <p class="st-note">Claude can then do whatever the token allows, and nothing more. Give it only the permissions you want it to have. <a href="https://github.com/Isorgcom/GameNight/blob/main/DOCS.md#connecting-claude-mcp" target="_blank" rel="noopener">More about connecting Claude</a></p>
                </div>
            </details>
        </div>
    </section>

    <!-- ═══ Account ═══ -->
    <section <?= $st_panel('account') ?>>
        <div class="card">
            <h2>Account</h2>
            <div class="st-rows" style="margin-top:.5rem">
                <div class="st-row"><span class="st-row-title">Role</span><span class="st-row-val"><span class="badge badge-<?= $me['role'] === 'admin' ? 'admin' : 'user' ?>"><?= htmlspecialchars($me['role']) ?></span></span></div>
                <div class="st-row"><span class="st-row-title">Member since</span><span class="st-row-val"><?= htmlspecialchars($me['created_at'] ? date('M j, Y', strtotime($me['created_at'] . ' UTC')) : '') ?></span></div>
                <div class="st-row"><span class="st-row-title">Last sign-in</span><span class="st-row-val"><?= htmlspecialchars(!empty($me['last_login']) ? date('M j, Y g:i A', strtotime($me['last_login'] . ' UTC')) : 'Never') ?></span></div>
            </div>
        </div>

        <div class="card">
            <h2>My Leagues (<?= count($my_leagues) ?>)</h2>
            <?php if (!$my_leagues): ?>
                <p class="st-note" style="margin-top:.5rem">You're not in a league yet. <a href="/leagues.php">Browse leagues</a></p>
            <?php else: ?>
            <div class="st-rows" style="margin-top:.5rem">
            <?php foreach ($my_leagues as $l): ?>
                <div class="st-row">
                    <span class="st-row-main">
                        <a class="st-row-title" href="/league.php?id=<?= (int)$l['id'] ?>"><?= htmlspecialchars($l['name']) ?></a>
                        <span class="st-row-sub"><?= (int)$l['is_hidden'] ? 'Hidden league &middot; ' : '' ?><?= htmlspecialchars($l['joined_at'] ? 'Joined ' . date('M j, Y', strtotime($l['joined_at'] . ' UTC')) : '') ?></span>
                    </span>
                    <span class="st-row-val"><span class="badge badge-<?= $l['role'] === 'member' ? 'user' : 'admin' ?>"><?= htmlspecialchars(ucfirst($l['role'])) ?></span></span>
                </div>
            <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <div class="card st-danger">
            <h2 id="st-h-delete">Delete Account</h2>
            <p class="subtitle">Permanently delete your account and all associated data. This cannot be undone.</p>
            <form method="post" action="/settings.php" data-act-submit="confirmDeleteTyped" aria-labelledby="st-h-delete">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>"><input type="hidden" name="st_pane" value="account">
                <input type="hidden" name="action" value="delete_account">
                <div class="st-fields">
                    <div class="form-group">
                        <label for="confirm_delete">Type <strong style="color:var(--fg-dc2626, #dc2626)">DELETE</strong> to confirm</label>
                        <!-- text-transform only UPPERCASES the display; keep the submitted value in sync
                             (oninput) so typing "delete" doesn't silently submit lowercase and fail. -->
                        <input type="text" id="confirm_delete" name="confirm_delete" required
                               autocomplete="off" autocorrect="off" spellcheck="false"
                               data-uppercase="1"
                               style="border-color:var(--bd-fca5a5, #fca5a5);text-transform:uppercase">
                    </div>
                    <div class="form-group">
                        <label for="delete_current_password">Your password</label>
                        <input type="password" id="delete_current_password" name="current_password" required
                               autocomplete="current-password" style="border-color:var(--bd-fca5a5, #fca5a5)">
                    </div>
                </div>
                <div class="st-actions"><button type="submit" class="btn btn-danger">Permanently Delete My Account</button></div>
            </form>
        </div>
    </section>

</div>

<?php require __DIR__ . '/_footer.php'; ?>
<script src="/_phone_input.js?v=<?= htmlspecialchars(APP_VERSION . '.' . (@filemtime(__DIR__ . '/_phone_input.js') ?: 0)) ?>"></script>
<script nonce="<?= csp_nonce() ?>">initPhoneAutoFormat();</script>
<script nonce="<?= csp_nonce() ?>">
// ── Section switcher ────────────────────────────────────────────────────────
// The server already rendered the open section; this only switches. A closed
// section is hidden="until-found", so the browser's find-in-page can still
// reach into it (it fires beforematch, handled below). Sections can be linked
// to: /settings.php#security.
var ST_PANES = <?= js_json(array_keys(ST_PANES)) ?>;
var ST_ALIAS = { 'api-tokens': 'security' };
var stCurrent = <?= js_json($st_pane) ?>;
function stShow(name, animate) {
    if (ST_PANES.indexOf(name) < 0 || name === stCurrent) return;
    var from = stCurrent; stCurrent = name;
    var btns = document.querySelectorAll('#stSeg button');
    for (var i = 0; i < btns.length; i++) btns[i].classList.toggle('active', btns[i].getAttribute('data-pane') === name);
    var shown = null;
    for (var j = 0; j < ST_PANES.length; j++) {
        var pane = document.getElementById('st-' + ST_PANES[j]);
        if (ST_PANES[j] === name) { pane.hidden = false; shown = pane; }
        else pane.hidden = 'until-found';   // plain hidden where until-found is not supported
    }
    positionSegThumb('stSeg', !!animate);   // also sets aria-selected from .active
    // A section that was closed measured its own controls at zero width.
    positionAllSegThumbs(false);
    if (animate && shown) slideViewIn(shown, segTravelDirection('stSeg', 'data-pane', from, name));
    var active = document.querySelector('#stSeg button.active');
    // container: 'nearest' scrolls the strip only, never the page.
    if (active && active.scrollIntoView) active.scrollIntoView({ block: 'nearest', inline: 'nearest', container: 'nearest' });
    try { history.replaceState(null, '', '#' + name); } catch (e) {}
}
function stTab() { stShow(this.getAttribute('data-pane'), true); }
(function () {
    function fromHash(animate) {
        var h = (location.hash || '').replace('#', '');
        var to = ST_ALIAS[h] || (ST_PANES.indexOf(h) >= 0 ? h : null);
        if (!to) return;
        stShow(to, animate);
        var el = ST_ALIAS[h] ? document.getElementById(h) : null;
        if (el && el.scrollIntoView) el.scrollIntoView({ block: 'start' });
    }
    positionSegThumb('stSeg', false);
    fromHash(false);
    <?php if ($new_api_token): ?>
    var card = document.getElementById('api-tokens');
    if (card && card.scrollIntoView) card.scrollIntoView({ block: 'start' });
    <?php endif; ?>
    // A link to another section followed while the page is already open.
    window.addEventListener('hashchange', function () { fromHash(true); });
    // Find-in-page matched text inside a closed section: open that section.
    document.getElementById('stWrap').addEventListener('beforematch', function (e) {
        var pane = e.target.closest ? e.target.closest('.st-pane') : null;
        if (pane) stShow(pane.getAttribute('data-pane'), false);
    });
    // Edge fades for the switcher where scroll-driven animations are missing.
    var nav = document.querySelector('.st-nav'), seg = document.getElementById('stSeg');
    if (nav && seg && window.IntersectionObserver && !(window.CSS && CSS.supports && CSS.supports('animation-timeline', 'scroll()'))) {
        var tabs = seg.querySelectorAll('button'), first = tabs[0], last = tabs[tabs.length - 1];
        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (en) { nav.classList.toggle(en.target === first ? 'more-start' : 'more-end', en.intersectionRatio < 1); });
        }, { root: nav, threshold: 1, rootMargin: '1px' });
        io.observe(first); io.observe(last);
    }
})();
function confirmDeleteTyped() {
    return document.getElementById('confirm_delete').value.trim().toUpperCase() === 'DELETE';
}
// Copy the token that was just created (shown once).
function stCopyToken() {
    var el = document.getElementById('stNewToken'), btn = this;
    if (!el) return;
    pkCopy(el.textContent.trim()).then(function (ok) {
        if (btn && btn.nodeType === 1) btn.textContent = ok ? 'Copied' : 'Select and copy it by hand';
    });
}
</script>
<script nonce="<?= csp_nonce() ?>">
// ── Browser (Web Push) notifications card ───────────────────────────────────
// Buttons dispatch via data-act (pk-dispatch); no inline handlers (CSP).
// Shared plumbing (permission/subscribe flow, API calls) lives in push.js,
// loaded without defer by _footer.php above.
(function () {
    var CSRF = <?= js_json(csrf_token()) ?>;
    var statusEl  = document.getElementById('pushStatus');
    var enableBtn = document.getElementById('pushEnableBtn');
    var disableBtn= document.getElementById('pushDisableBtn');
    var testBtn   = document.getElementById('pushTestBtn');

    function refresh() {
        if (!gnPush.supported()) {
            statusEl.textContent = 'This browser does not support push notifications.'
                + (/iPhone|iPad/.test(navigator.userAgent) ? ' On iPhone/iPad, add the site to your Home Screen first.' : '');
            return;
        }
        Promise.all([gnPush.getSubscription(), gnPush.api(CSRF, 'status')]).then(function (res) {
            var sub = res[0], st = res[1];
            var devices = (st && st.ok) ? st.devices : 0;
            var here = !!sub;
            statusEl.textContent = (here ? 'Enabled on this device.' : 'Not enabled on this device.')
                + ' ' + devices + ' device' + (devices === 1 ? '' : 's') + ' enabled on your account.';
            enableBtn.style.display  = here ? 'none' : '';
            disableBtn.style.display = here ? '' : 'none';
            testBtn.style.display    = devices > 0 ? '' : 'none';
            if (Notification.permission === 'denied') {
                statusEl.textContent = 'Notifications are blocked for this site in your browser settings.';
                enableBtn.style.display = 'none';
            }
        }).catch(function () { statusEl.textContent = 'Could not check notification status.'; });
    }

    // Install as an App. show(true) forces the card past its own snooze and
    // device rules; false back means this browser has nothing to offer (no
    // install prompt captured, not iOS), so say what does work instead.
    window.a2hsInstallNow = function () {
        if (window.pkA2HS && pkA2HS.standalone()) {
            pkAlert('You are already using the installed app.');
            return;
        }
        if (window.pkA2HS && pkA2HS.show(true)) return;
        pkAlert('This browser does not offer an install here.<br><br>On an iPhone or iPad, open this page in Safari and use Share &rarr; Add to Home Screen. '
              + 'On Android, use Chrome. On a computer, Chrome and Edge show an install icon at the right end of the address bar.');
    };

    window.pushEnable = function () {
        enableBtn.disabled = true;
        gnPush.enable(CSRF).catch(function (e) {
            pkAlert('Could not enable notifications: ' + e.message);
        }).finally(function () { enableBtn.disabled = false; refresh(); });
    };

    window.pushDisable = function () {
        disableBtn.disabled = true;
        gnPush.disable(CSRF)
            .catch(function () {})
            .finally(function () { disableBtn.disabled = false; refresh(); });
    };

    window.pushTest = function () {
        testBtn.disabled = true;
        gnPush.api(CSRF, 'test').then(function (r) {
            if (!r.ok) pkAlert(r.error || 'Test failed');
        }).finally(function () { testBtn.disabled = false; });
    };

    refresh();
})();
</script>
</body>
</html>
