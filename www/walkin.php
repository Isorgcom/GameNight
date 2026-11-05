<?php
/**
 * Walk-up QR registration page.
 * Public (no login required). URL: /walkin.php?event_id=X&token=Y
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_poker_helpers.php';
require_once __DIR__ . '/_notifications.php';
require_once __DIR__ . '/_public_join.php';

session_start_safe();

$db         = get_db();
$event_id   = (int)($_GET['event_id'] ?? 0);
$token      = trim($_GET['token'] ?? '');
$site_name  = get_setting('site_name', 'Game Night');

// ── Validate event + token ────────────────────────────────────────────────────
$event = null;
if ($event_id > 0 && $token !== '') {
    $stmt = $db->prepare('SELECT id, title, start_date, start_time, end_time, walkin_token, visibility, created_by, cancelled_at FROM events WHERE id = ?');
    $stmt->execute([$event_id]);
    $row = $stmt->fetch();
    if ($row && hash_equals((string)$row['walkin_token'], $token)) {
        $event = $row;
    }
}

// A cancelled event takes no walk-ins: the QR at the door says so.
$walkinCancelled = $event !== null && !empty($event['cancelled_at']);
if ($walkinCancelled) $event = null;
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
// The same labels the RSVP page shows ("Saturday, October 17, 2026 · 2:00 PM
// CDT"), in the same zone: a signed-in viewer's own, otherwise the event
// creator's, since a walk-in is usually standing at the venue with no account.
// It used to print the raw stored date (2026-10-17). Entities are decoded
// because every call site escapes the result.
function fmt_event_display(array $ev): string {
    $cu  = function_exists('current_user') ? current_user() : null;
    $uid = !empty($cu['id']) ? (int)$cu['id'] : ((int)($ev['created_by'] ?? 0) ?: null);
    $lbl = event_public_time_labels((string)$ev['start_date'], $ev['start_time'] ?: null, $ev['end_time'] ?: null, $uid);
    $out = $lbl['date_lbl'];
    if ($lbl['time_lbl'] !== '') $out .= '  ·  ' . html_entity_decode($lbl['time_lbl'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
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
        $in = public_join_parse($_POST);
        if ($in['error'] !== '') {
            $error = $in['error'];
        } else {
            walkin_record_attempt($db);
            // The account-and-invite rules are shared with the invite link
            // (_public_join.php): an existing account is never confirmed by
            // someone typing its address, an approved row is never flipped.
            $res = public_join_event($db, $event, $in, 'walkin');
            if ($res['status'] === 'error') {
                $error = $res['error'];
            } else {
                $username = $res['username'];
                $approved = in_array($res['status'], ['approved', 'already'], true);
                if ($res["status"] === "already") auto_add_to_league($db, $event_id, (int)$res["user_id"]);

                // Remember for next walk-up (30 days)
                setcookie('walkin_name', $in['display_name'], time() + 86400 * 30, '/', '', true, true);
                setcookie('walkin_contact', $in['contact_raw'], time() + 86400 * 30, '/', '', true, true);

                if ($res['is_new_user']) {
                    $walkin_verify_ui    = $res['verify'];
                    $walkin_verify_phone = $in['phone'];
                    $walkin_verify_email = $in['email'];
                }

                if ($approved) {
                    $success = $res['is_new_user']
                        ? "You're registered for <strong>" . htmlspecialchars($event['title']) . "</strong>! Have fun."
                        : "Welcome back, " . htmlspecialchars($username) . "! You're registered for <strong>" . htmlspecialchars($event['title']) . "</strong>.";

                    // At the door: seat them now if a game is set up.
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
                                if (!$res['is_new_user']) {
                                    if (session_status() === PHP_SESSION_NONE) session_start_safe();
                                    $_SESSION['walkin_player_id'] = (int)$ppRow['id'];
                                }
                            }
                        }
                    }
                } else {
                    // Pending or denied: the waiting-list message either way (soft-deny).
                    $success = "You're on the waiting list for <strong>" . htmlspecialchars($event['title']) . "</strong>. The host will approve your registration shortly.";
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
    <link rel="stylesheet" href="/style.css?v=<?= htmlspecialchars(APP_VERSION . '.' . (@filemtime(__DIR__ . '/style.css') ?: 0)) ?>">
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
            <?php if (!empty($walkinCancelled)): ?>
            <h2>Event cancelled</h2>
            <p>This game was cancelled by the host, so there is nothing to sign up for.</p>
            <?php else: ?>
            <h2>Invalid Link</h2>
            <p>This registration link is invalid or has expired.</p>
            <?php endif; ?>
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
<script src="/_phone_input.js?v=<?= htmlspecialchars(APP_VERSION . '.' . (@filemtime(__DIR__ . '/_phone_input.js') ?: 0)) ?>"></script>
<script nonce="<?= csp_nonce() ?>">initPhoneAutoFormat();</script>
</body>
</html>
