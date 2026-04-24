<?php
/**
 * Walk-up QR registration page.
 * Public (no login required). URL: /walkin.php?event_id=X&token=Y
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_poker_helpers.php';

session_start_safe();

$db         = get_db();
$event_id   = (int)($_GET['event_id'] ?? 0);
$token      = trim($_GET['token'] ?? '');
$site_name  = get_setting('site_name', 'Game Night');

// ── Validate event + token ────────────────────────────────────────────────────
$event = null;
if ($event_id > 0 && $token !== '') {
    $stmt = $db->prepare('SELECT id, title, start_date, start_time, end_time, walkin_token, visibility FROM events WHERE id = ?');
    $stmt->execute([$event_id]);
    $row = $stmt->fetch();
    if ($row && hash_equals((string)$row['walkin_token'], $token)) {
        $event = $row;
    }
}

$invalid = ($event === null);

// ── Rate limiting: max 5 submissions per IP per hour ──────────────────────────
function walkin_rate_limited(PDO $db): bool {
    $ip = get_client_ip();
    // Purge old entries first
    $db->prepare("DELETE FROM walkin_attempts WHERE created_at < datetime('now', '-1 hour')")->execute();
    $count = $db->prepare("SELECT COUNT(*) FROM walkin_attempts WHERE ip = ? AND created_at > datetime('now', '-1 hour')");
    $count->execute([$ip]);
    $cap = defined('MAX_REGISTRATION_ATTEMPTS_PER_HOUR') ? MAX_REGISTRATION_ATTEMPTS_PER_HOUR : 20;
    return (int)$count->fetchColumn() >= $cap;
}

function walkin_record_attempt(PDO $db): void {
    $ip = get_client_ip();
    $db->prepare('INSERT INTO walkin_attempts (ip) VALUES (?)')->execute([$ip]);
}

// ── Format display date/time ──────────────────────────────────────────────────
function fmt_event_display(array $ev): string {
    $out = $ev['start_date'];
    if (!empty($ev['start_time'])) {
        $t = DateTime::createFromFormat('H:i', $ev['start_time']);
        if ($t) $out .= '  ·  ' . $t->format('g:i A');
        if (!empty($ev['end_time'])) {
            $t2 = DateTime::createFromFormat('H:i', $ev['end_time']);
            if ($t2) $out .= ' – ' . $t2->format('g:i A');
        }
    }
    return $out;
}

// ── POST handler ──────────────────────────────────────────────────────────────
$error   = '';
$success = '';
$assigned_table = null;
$assigned_seat  = null;

if (!$invalid && $_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!csrf_verify()) {
        $error = 'Invalid request. Please refresh and try again.';
    } elseif (walkin_rate_limited($db)) {
        $error = 'Too many registration attempts from this device. Please try again in an hour.';
    } else {
        // Auto-detect the combined "Email or phone" input. Contains '@' → email, otherwise phone.
        // Left the legacy `email` / `phone` POST slots as a fallback so any old bookmark still works.
        $contact_raw = trim($_POST['contact'] ?? '');
        if ($contact_raw !== '' && ($_POST['email'] ?? '') === '' && ($_POST['phone'] ?? '') === '') {
            if (strpos($contact_raw, '@') !== false) {
                $_POST['email'] = $contact_raw;
            } else {
                $_POST['phone'] = $contact_raw;
            }
        }

        // Collect + sanitize inputs
        $display_name = trim($_POST['display_name'] ?? '');
        $email        = strtolower(trim($_POST['email'] ?? ''));
        $phone        = trim($_POST['phone'] ?? '');

        // Validate display name → username: allow letters, numbers, spaces, underscores; spaces → underscores
        $username_raw = preg_replace('/\s+/', '_', $display_name);
        $username_raw = preg_replace('/[^a-zA-Z0-9_]/', '', $username_raw);

        // Normalize phone early so we can use it for validation + lookup.
        $phone_normalized = ($phone !== '') ? normalize_phone($phone) : '';
        $__p_digits       = preg_replace('/\D/', '', $phone_normalized);
        $has_email = ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL));
        $has_phone = ($phone_normalized !== '' && strlen($__p_digits) >= 7 && strlen($__p_digits) <= 15);

        if ($display_name === '') {
            $error = 'Display name is required.';
        } elseif (strlen($username_raw) < 3 || strlen($username_raw) > 30) {
            $error = 'Display name must produce a username between 3 and 30 characters (letters, numbers, spaces, underscores).';
        } elseif (!$has_email && !$has_phone) {
            $error = 'Enter an email address or phone number.';
        } elseif ($email !== '' && !$has_email) {
            $error = 'Invalid email address.';
        } elseif ($phone !== '' && !$has_phone) {
            $error = 'Invalid phone number.';
        } else {
            walkin_record_attempt($db);

            // Look up by email if provided, else by phone.
            if ($has_email) {
                $stmt = $db->prepare('SELECT id, username, email_verified FROM users WHERE LOWER(email) = ?');
                $stmt->execute([$email]);
                $existing = $stmt->fetch();
            } else {
                $stmt = $db->prepare('SELECT id, username, email_verified FROM users WHERE phone = ?');
                $stmt->execute([$phone_normalized]);
                $existing = $stmt->fetch();
            }

            if ($existing) {
                // Existing user — RSVP them to this event
                $uid      = (int)$existing['id'];
                $username = $existing['username'];

                // Check if already invited (base row, no occurrence_date)
                $chk = $db->prepare('SELECT id, approval_status FROM event_invites WHERE event_id = ? AND LOWER(username) = LOWER(?) AND occurrence_date IS NULL');
                $chk->execute([$event_id, $username]);
                $existingInvite = $chk->fetch();
                $effective_approval = 'approved';
                $is_new_pending = false;

                if ($existingInvite) {
                    $existing_status = $existingInvite['approval_status'] ?? 'approved';
                    if ($existing_status === 'denied') {
                        // Soft-deny: silently absorb. Don't flip the row, don't reveal the denial.
                        // The walk-in success message below will say "waiting list" identical to a normal pending signup.
                        $effective_approval = 'denied';
                    } elseif ($existing_status === 'pending') {
                        // Already pending — same waiting-list message, no DB change.
                        $effective_approval = 'pending';
                    } else {
                        // Security: an already-approved invite is NOT flipped from the walk-in form.
                        // Anyone at the event could type the victim's email to mark them checked-in.
                        // If they're already on the list, nothing to do — just acknowledge.
                        $effective_approval = 'approved';
                    }
                } else {
                    // Security: an existing account being "walked in" by someone holding the QR code
                    // (not necessarily the user themselves) always requires host approval — even if
                    // the event is otherwise open. The attacker doesn't gain a confirmed RSVP; the
                    // host sees a pending row and can approve or deny. For brand-new walk-in users
                    // (the main `else` block far below) we still honor the event's requires_approval
                    // setting because those identifiers don't belong to anyone yet.
                    $db->prepare("INSERT INTO event_invites (event_id, username, email, rsvp, approval_status) VALUES (?, ?, ?, ?, 'pending')")
                       ->execute([$event_id, $username, $email]);
                    $effective_approval = 'pending';
                    $is_new_pending = true;
                }
                auto_add_to_league($db, $event_id, $uid);
                db_log_anon_activity("walkin_rsvp: existing user $username for event $event_id" . ($effective_approval !== 'approved' ? ' (waiting list)' : ''));

                // Remember for next walk-up (30 days)
                setcookie('walkin_name', $display_name, time() + 86400 * 30, '/', '', true, true);
                setcookie('walkin_contact', ($contact_raw !== '' ? $contact_raw : ($email ?: $phone)), time() + 86400 * 30, '/', '', true, true);

                if ($effective_approval === 'approved') {
                    $success = "Welcome back, " . htmlspecialchars($username) . "! You're registered for <strong>" . htmlspecialchars($event['title']) . "</strong>.";

                    // Auto-assign table if poker session exists
                    $psess = $db->prepare('SELECT id FROM poker_sessions WHERE event_id = ?');
                    $psess->execute([$event_id]);
                    $psRow = $psess->fetch();
                    if ($psRow) {
                        sync_invitees($db, $psRow['id'], $event_id);
                        $pp = $db->prepare('SELECT id FROM poker_players WHERE session_id = ? AND LOWER(display_name) = LOWER(?) AND removed = 0');
                        $pp->execute([$psRow['id'], $username]);
                        $ppRow = $pp->fetch();
                        if ($ppRow) {
                            $assigned_table = auto_assign_table($db, $psRow['id'], $ppRow['id']);
                            if ($assigned_table !== null) {
                                $seatStmt = $db->prepare('SELECT seat_number FROM poker_players WHERE id = ?');
                                $seatStmt->execute([$ppRow['id']]);
                                $assigned_seat = $seatStmt->fetchColumn() ?: null;
                                // Stash player id so verify_phone.php can re-surface the seat tile.
                                if (session_status() === PHP_SESSION_NONE) session_start_safe();
                                $_SESSION['walkin_player_id'] = (int)$ppRow['id'];
                            }
                        }
                    }
                } else {
                    // Pending or denied — show waiting-list message either way (soft-deny).
                    $success = "You're on the waiting list for <strong>" . htmlspecialchars($event['title']) . "</strong>. The host will approve your registration shortly.";
                }

                // Notify the event creator about a brand-new pending signup,
                // and send the walk-in user a confirmation that they're on the waiting list.
                if ($is_new_pending) {
                    notify_creator_of_pending($event_id, $username);
                    // Notify the walk-in user they're on the waiting list
                    $uNotify = $db->prepare('SELECT username, email, phone, preferred_contact FROM users WHERE id = ?');
                    $uNotify->execute([$uid]);
                    $uRow = $uNotify->fetch();
                    if ($uRow && function_exists('send_notification')) {
                        $smsBody  = "You're on the waiting list for \"{$event['title']}\" on {$event['start_date']}. The host will approve your registration shortly.";
                        $htmlBody = '<p>You are on the waiting list for <strong>' . htmlspecialchars($event['title']) . '</strong> on ' . htmlspecialchars($event['start_date']) . '.</p>'
                                  . '<p style="color:#64748b">The host will approve your registration shortly. You will receive another notification when approved.</p>';
                        send_notification($uRow['username'], $uRow['email'] ?? '', $uRow['phone'] ?? '',
                            $uRow['preferred_contact'] ?? 'email',
                            "Waiting list: " . $event['title'],
                            $smsBody, $htmlBody);
                    }
                }

            } else {
                // New user — create soft account
                $base_username = $username_raw;
                $final_username = $base_username;
                $suffix = 2;
                // Ensure username is unique
                while (true) {
                    $ucheck = $db->prepare('SELECT id FROM users WHERE LOWER(username) = LOWER(?)');
                    $ucheck->execute([$final_username]);
                    if (!$ucheck->fetch()) break;
                    $final_username = $base_username . $suffix;
                    $suffix++;
                    if ($suffix > 999) { $error = 'Could not generate a unique username. Please try a different name.'; break; }
                }

                if ($error === '') {
                    // Channel the user registered with: email if present, else SMS.
                    $walkin_method    = $has_email ? 'email' : 'sms';
                    $walkin_preferred = $walkin_method;
                    // Soft verification: insert the user as unverified, then fire the email link /
                    // SMS code below. The success screen surfaces a verify control but does NOT
                    // block the user from attending — they're already at the event. The verify
                    // step is about unlocking future login recovery, not gating event access.
                    $db->prepare('INSERT INTO users (username, password_hash, email, phone, role, email_verified, phone_verified, must_change_password, preferred_contact, verification_method) VALUES (?, ?, ?, ?, ?, 0, 0, 1, ?, ?)')
                       ->execute([$final_username, '', $has_email ? $email : null, $has_phone ? $phone_normalized : null, 'user', $walkin_preferred, $walkin_method]);
                    $new_id = (int)$db->lastInsertId();

                    // Invite to event. Walk-in is a 'self' signup — approval gate fires if requires_approval=1.
                    $new_walkin_approval = invite_approval_status($event_id, 'self');
                    $db->prepare('INSERT INTO event_invites (event_id, username, email, phone, rsvp, approval_status) VALUES (?, ?, ?, ?, ?, ?)')
                       ->execute([$event_id, $final_username, $has_email ? $email : null, $has_phone ? $phone_normalized : null, 'yes', $new_walkin_approval]);

                    auto_add_to_league($db, $event_id, $new_id);
                    db_log_anon_activity("walkin_new_user: $final_username for event $event_id" . ($new_walkin_approval === 'pending' ? ' (waiting list)' : ''));

                    // Soft verification send. Email path: link goes to reset_password.php so they
                    // can set a real password. Phone path: 6-digit code plus session-stash so the
                    // inline form on the success screen can POST to /verify_phone.php.
                    if ($walkin_method === 'email') {
                        send_verification_email($new_id, $email, $final_username);
                    } else {
                        send_verification_code($new_id, $phone_normalized, 'sms');
                        if (session_status() === PHP_SESSION_NONE) session_start_safe();
                        $_SESSION['verify_user_id'] = $new_id;
                        $_SESSION['verify_method']  = 'sms';
                        // Carry walk-in context so verify_phone.php can surface table/seat on success.
                        $_SESSION['walkin_event_id'] = (int)$event_id;
                    }

                    // Variables the render block uses to decide which verify UI to show.
                    $walkin_verify_ui       = ($walkin_method === 'sms') ? 'phone_inline' : 'email_note';
                    $walkin_verify_phone    = $has_phone ? $phone_normalized : '';
                    $walkin_verify_email    = $has_email ? $email : '';

                    // Remember for next walk-up (30 days)
                    setcookie('walkin_name', $display_name, time() + 86400 * 30, '/', '', true, true);
                    setcookie('walkin_contact', ($contact_raw !== '' ? $contact_raw : ($email ?: $phone)), time() + 86400 * 30, '/', '', true, true);

                    if ($new_walkin_approval === 'approved') {
                        $success = "You're registered for <strong>" . htmlspecialchars($event['title']) . "</strong>! Have fun.";

                        // Auto-assign table if poker session exists
                        $psess = $db->prepare('SELECT id FROM poker_sessions WHERE event_id = ?');
                        $psess->execute([$event_id]);
                        $psRow = $psess->fetch();
                        if ($psRow) {
                            sync_invitees($db, $psRow['id'], $event_id);
                            $pp = $db->prepare('SELECT id FROM poker_players WHERE session_id = ? AND LOWER(display_name) = LOWER(?) AND removed = 0');
                            $pp->execute([$psRow['id'], $final_username]);
                            $ppRow = $pp->fetch();
                            if ($ppRow) {
                                $assigned_table = auto_assign_table($db, $psRow['id'], $ppRow['id']);
                                if ($assigned_table !== null) {
                                    $seatStmt = $db->prepare('SELECT seat_number FROM poker_players WHERE id = ?');
                                    $seatStmt->execute([$ppRow['id']]);
                                    $assigned_seat = $seatStmt->fetchColumn() ?: null;
                                }
                            }
                        }
                    } else {
                        $success = "You're on the waiting list for <strong>" . htmlspecialchars($event['title']) . "</strong>. The host will approve your registration shortly.";
                        // Notify the host about the pending signup.
                        notify_creator_of_pending($event_id, $final_username);
                        // Notify the new user they're on the waiting list (email only — they just registered).
                        if (function_exists('send_notification')) {
                            $smsBody  = "You're on the waiting list for \"{$event['title']}\" on {$event['start_date']}. The host will approve your registration shortly.";
                            $htmlBody = '<p>You are on the waiting list for <strong>' . htmlspecialchars($event['title']) . '</strong> on ' . htmlspecialchars($event['start_date']) . '.</p>'
                                      . '<p style="color:#64748b">The host will approve your registration shortly. You will receive another notification when approved.</p>';
                            send_notification($final_username, $email, $phone_normalized,
                                'email', // new user — default to email since they just gave us their email
                                "Waiting list: " . $event['title'],
                                $smsBody, $htmlBody);
                        }
                    }
                }
            }
        }
    }
}

// Read remembered values from cookie
$remembered_name  = $_COOKIE['walkin_name'] ?? '';
// Backwards-compat: earlier versions stored email only; fall back to it if the new cookie isn't set yet.
$remembered_contact = $_COOKIE['walkin_contact'] ?? $_COOKIE['walkin_email'] ?? '';

$csrf_token = csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Register for Event – <?= htmlspecialchars($site_name) ?></title>
    <link rel="stylesheet" href="/style.css">
    <style>
        body { background: var(--bg, #f8fafc); }
        .walkin-wrap {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
        }
        .walkin-card {
            background: #fff;
            border-radius: 14px;
            box-shadow: 0 8px 32px rgba(0,0,0,.12);
            padding: 2rem 2rem 1.75rem;
            width: 100%;
            max-width: 420px;
        }
        .walkin-logo {
            text-align: center;
            margin-bottom: 1.25rem;
        }
        .walkin-logo img { max-height: 48px; }
        .walkin-site-name {
            text-align: center;
            font-size: .85rem;
            color: #64748b;
            margin-top: .25rem;
            margin-bottom: 1.25rem;
        }
        .walkin-event-box {
            background: #f1f5f9;
            border-radius: 8px;
            padding: .75rem 1rem;
            margin-bottom: 1.5rem;
        }
        .walkin-event-title {
            font-size: 1.1rem;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: .2rem;
        }
        .walkin-event-meta {
            font-size: .85rem;
            color: #64748b;
        }
        .walkin-success {
            background: #f0fdf4;
            border: 1.5px solid #86efac;
            border-radius: 8px;
            padding: 1rem 1.2rem;
            color: #166534;
            font-size: .95rem;
            line-height: 1.5;
            margin-bottom: 1rem;
        }
        .walkin-error {
            background: #fef2f2;
            border: 1.5px solid #fca5a5;
            border-radius: 8px;
            padding: .75rem 1rem;
            color: #991b1b;
            font-size: .875rem;
            margin-bottom: 1rem;
        }
        .walkin-invalid {
            text-align: center;
            color: #64748b;
            padding: 1.5rem 0 .5rem;
        }
        .walkin-invalid h2 { color: #1e293b; margin-bottom: .5rem; }
    </style>
</head>
<body>
<div class="walkin-wrap">
    <div class="walkin-card">
        <?php
        $logo = get_setting('logo_path', '');
        if ($logo): ?>
        <div class="walkin-logo"><img src="<?= htmlspecialchars($logo) ?>" alt="<?= htmlspecialchars($site_name) ?>"></div>
        <?php else: ?>
        <div class="walkin-site-name"><?= htmlspecialchars($site_name) ?></div>
        <?php endif; ?>

        <?php if ($invalid): ?>
        <div class="walkin-invalid">
            <h2>Invalid Link</h2>
            <p>This registration link is invalid or has expired.</p>
        </div>

        <?php elseif ($success !== ''): ?>
        <div class="walkin-event-box">
            <div class="walkin-event-title"><?= htmlspecialchars($event['title']) ?></div>
            <div class="walkin-event-meta"><?= htmlspecialchars(fmt_event_display($event)) ?></div>
        </div>
        <div class="walkin-success"><?= $success ?></div>

        <?php if (!empty($walkin_verify_ui) && $walkin_verify_ui === 'phone_inline'):
            $__pm = preg_replace('/(\d{3})\D*(\d{3})\D*(\d{4})/', '($1) $2-$3', $walkin_verify_phone);
        ?>
        <div class="walkin-verify-phone" style="margin-top:1.25rem;padding:1rem;background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:10px">
            <p style="margin:0 0 .6rem;font-size:.88rem;color:#334155;line-height:1.5">
                We texted a 6-digit code to <strong><?= htmlspecialchars($__pm ?: $walkin_verify_phone) ?></strong>.
                Enter it so you can sign in later. <em style="color:#64748b">You can skip — you're already in the event.</em>
            </p>
            <form method="post" action="/verify_phone.php" style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                <input type="text" name="code" inputmode="numeric" autocomplete="one-time-code"
                       maxlength="6" pattern="\d{6}" required placeholder="123456"
                       style="flex:1;min-width:130px;padding:.55rem .75rem;border:1.5px solid #cbd5e1;border-radius:6px;font-size:1.1rem;letter-spacing:.3em;text-align:center">
                <button type="submit" class="btn btn-primary" style="padding:.55rem 1.1rem">Verify</button>
            </form>
            <p style="margin:.65rem 0 0;font-size:.82rem">
                <a href="/" style="color:#64748b" title="Skip for now — you're still registered for the event, but you won't be able to reset your password later">Skip for now</a>
            </p>
        </div>
        <?php elseif (!empty($walkin_verify_ui) && $walkin_verify_ui === 'email_note'): ?>
        <div class="walkin-verify-email" style="margin-top:1.25rem;padding:1rem;background:#eff6ff;border:1.5px solid #93c5fd;border-radius:10px">
            <p style="margin:0;font-size:.88rem;color:#1e40af;line-height:1.5">
                &#128231; Check your inbox — we emailed a verification link to <strong><?= htmlspecialchars($walkin_verify_email) ?></strong>
                so you can set a password and sign in later. You're already in the event, so you can ignore it for now.
            </p>
        </div>
        <?php endif; ?>

        <?php if ($assigned_table !== null): ?>
        <div style="margin-top:1rem;padding:1.2rem;background:#eff6ff;border:2px solid #3b82f6;border-radius:10px;text-align:center">
            <div style="font-size:.85rem;color:#3b82f6;font-weight:600;text-transform:uppercase;letter-spacing:.05em">Your Seat</div>
            <div style="font-size:2.25rem;font-weight:800;color:#1e40af;line-height:1.2">
                Table <?= (int)$assigned_table ?><?php if ($assigned_seat): ?> &middot; Seat <?= (int)$assigned_seat ?><?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php else: ?>
        <div class="walkin-event-box">
            <div class="walkin-event-title"><?= htmlspecialchars($event['title']) ?></div>
            <div class="walkin-event-meta"><?= htmlspecialchars(fmt_event_display($event)) ?></div>
        </div>
        <h2 style="font-size:1.1rem;font-weight:700;margin-bottom:1.1rem;color:#1e293b">Register for this event</h2>

        <?php if ($error !== ''): ?>
        <div class="walkin-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="post" action="/walkin.php?event_id=<?= $event_id ?>&token=<?= urlencode($token) ?>">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">

            <div class="form-group">
                <label for="wi_name">Your name <span style="color:#ef4444">*</span></label>
                <input type="text" id="wi_name" name="display_name" required
                       maxlength="40" autocomplete="name" placeholder="Jane Smith"
                       value="<?= htmlspecialchars($_POST['display_name'] ?? $remembered_name) ?>"
                       style="width:100%">
            </div>

            <p style="font-size:.8rem;color:#64748b;margin:0 0 .6rem">Enter an email address or a phone number so we can confirm your registration.</p>

            <div class="form-group">
                <label for="wi_contact">Email or phone</label>
                <input type="text" id="wi_contact" name="contact" data-phone-contact="1" required
                       autocomplete="email" placeholder="you@example.com or 555-123-4567"
                       value="<?= htmlspecialchars($_POST['contact'] ?? $_POST['email'] ?? $_POST['phone'] ?? $remembered_contact) ?>"
                       style="width:100%">
            </div>

            <button type="submit" class="btn btn-primary" style="width:100%;margin-top:.5rem;padding:.7rem">
                Register for this event
            </button>
        </form>
        <?php endif; ?>
    </div>
</div>
<script src="/_phone_input.js"></script>
<script>initPhoneAutoFormat();</script>
</body>
</html>
