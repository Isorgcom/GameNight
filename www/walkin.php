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
    $stmt = $db->prepare('SELECT id, title, start_date, start_time, end_time, walkin_token FROM events WHERE id = ?');
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
    return (int)$count->fetchColumn() >= 5;
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

if (!$invalid && $_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!csrf_verify()) {
        $error = 'Invalid request. Please refresh and try again.';
    } elseif (walkin_rate_limited($db)) {
        $error = 'Too many registration attempts from this device. Please try again in an hour.';
    } else {
        // Collect + sanitize inputs
        $display_name = trim($_POST['display_name'] ?? '');
        $email        = strtolower(trim($_POST['email'] ?? ''));
        $phone        = trim($_POST['phone'] ?? '');

        // Validate display name → username: allow letters, numbers, spaces, underscores; spaces → underscores
        $username_raw = preg_replace('/\s+/', '_', $display_name);
        $username_raw = preg_replace('/[^a-zA-Z0-9_]/', '', $username_raw);

        if ($display_name === '') {
            $error = 'Display name is required.';
        } elseif (strlen($username_raw) < 3 || strlen($username_raw) > 30) {
            $error = 'Display name must produce a username between 3 and 30 characters (letters, numbers, spaces, underscores).';
        } elseif ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'A valid email address is required.';
        } else {
            walkin_record_attempt($db);

            // Normalize phone if provided
            $phone_normalized = ($phone !== '') ? normalize_phone($phone) : '';

            // Look up by email
            $stmt = $db->prepare('SELECT id, username, email_verified FROM users WHERE LOWER(email) = ?');
            $stmt->execute([$email]);
            $existing = $stmt->fetch();

            if ($existing) {
                // Existing user — RSVP them to this event
                $uid      = (int)$existing['id'];
                $username = $existing['username'];

                // Check if already invited (base row, no occurrence_date)
                $chk = $db->prepare('SELECT id FROM event_invites WHERE event_id = ? AND LOWER(username) = LOWER(?) AND occurrence_date IS NULL');
                $chk->execute([$event_id, $username]);
                if ($chk->fetch()) {
                    // Update RSVP to yes
                    $db->prepare("UPDATE event_invites SET rsvp = 'yes' WHERE event_id = ? AND LOWER(username) = LOWER(?) AND occurrence_date IS NULL")
                       ->execute([$event_id, $username]);
                } else {
                    // Insert new invite row. Walk-in is a 'self' signup — approval gate fires if requires_approval=1.
                    $walkin_approval = invite_approval_status($event_id, 'self');
                    $db->prepare('INSERT INTO event_invites (event_id, username, email, rsvp, approval_status) VALUES (?, ?, ?, ?, ?)')
                       ->execute([$event_id, $username, $email, 'yes', $walkin_approval]);
                }
                db_log_anon_activity("walkin_rsvp: existing user $username for event $event_id");
                // Remember for next walk-up (30 days)
                setcookie('walkin_name', $display_name, time() + 86400 * 30, '/', '', true, true);
                setcookie('walkin_email', $email, time() + 86400 * 30, '/', '', true, true);
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
                    $db->prepare('INSERT INTO users (username, password_hash, email, phone, role, email_verified, must_change_password) VALUES (?, ?, ?, ?, ?, 0, 0)')
                       ->execute([$final_username, '', $email, $phone_normalized !== '' ? $phone_normalized : null, 'user']);
                    $new_id = (int)$db->lastInsertId();

                    // Invite to event. Walk-in is a 'self' signup — approval gate fires if requires_approval=1.
                    $new_walkin_approval = invite_approval_status($event_id, 'self');
                    $db->prepare('INSERT INTO event_invites (event_id, username, email, rsvp, approval_status) VALUES (?, ?, ?, ?, ?)')
                       ->execute([$event_id, $final_username, $email, 'yes', $new_walkin_approval]);

                    db_log_anon_activity("walkin_new_user: $final_username for event $event_id");

                    // Send verification email so they can set a password
                    send_verification_email($new_id, $email, $final_username);

                    // Remember for next walk-up (30 days)
                    setcookie('walkin_name', $display_name, time() + 86400 * 30, '/', '', true, true);
                    setcookie('walkin_email', $email, time() + 86400 * 30, '/', '', true, true);

                    $success = "You're registered for <strong>" . htmlspecialchars($event['title']) . "</strong>! Check your email to verify your account and set a password.";

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
                        }
                    }
                }
            }
        }
    }
}

// Read remembered values from cookie
$remembered_name  = $_COOKIE['walkin_name'] ?? '';
$remembered_email = $_COOKIE['walkin_email'] ?? '';

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
        <?php if ($assigned_table !== null): ?>
        <div style="margin-top:1rem;padding:1.2rem;background:#eff6ff;border:2px solid #3b82f6;border-radius:10px;text-align:center">
            <div style="font-size:.85rem;color:#3b82f6;font-weight:600;text-transform:uppercase;letter-spacing:.05em">Your Table</div>
            <div style="font-size:2.5rem;font-weight:800;color:#1e40af;line-height:1.2">Table <?= (int)$assigned_table ?></div>
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

            <div class="form-group">
                <label for="wi_email">Email address <span style="color:#ef4444">*</span></label>
                <input type="email" id="wi_email" name="email" required
                       autocomplete="email" placeholder="you@example.com"
                       value="<?= htmlspecialchars($_POST['email'] ?? $remembered_email) ?>"
                       style="width:100%">
            </div>

            <div class="form-group">
                <label for="wi_phone">Phone number <span style="color:#94a3b8;font-weight:400">(optional)</span></label>
                <input type="tel" id="wi_phone" name="phone"
                       autocomplete="tel" placeholder="+1 555 000 0000"
                       value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>"
                       style="width:100%">
            </div>

            <button type="submit" class="btn btn-primary" style="width:100%;margin-top:.5rem;padding:.7rem">
                Register for this event
            </button>
        </form>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
