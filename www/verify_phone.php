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
        <?php
        // If the user arrived here from a walk-in flow, re-surface the seat assignment
        // that was shown on the walk-in success page (it would otherwise be lost).
        $wi_table = null;
        $wi_seat  = null;
        $wi_player_id = (int)($_SESSION['walkin_player_id'] ?? 0);
        $wi_event_id  = (int)($_SESSION['walkin_event_id'] ?? 0);
        if ($wi_player_id > 0) {
            // Include removed players: the walk-in row may have been soft-removed by
            // sync_invitees() if the user's event_invite was regenerated in the interim.
            // We still want to show the seat they were told to take.
            $pp = get_db()->prepare('SELECT table_number, seat_number FROM poker_players WHERE id = ?');
            $pp->execute([$wi_player_id]);
            if ($pr = $pp->fetch()) {
                $wi_table = $pr['table_number'] ?: null;
                $wi_seat  = $pr['seat_number']  ?: null;
            }
        }
        // Fallback: if player_id wasn't stashed but event_id was, look up by the
        // just-verified user_id + event via poker_sessions. Handles cases where
        // session data partially drifted between walk-in and verify.
        if ($wi_table === null && $wi_event_id > 0 && $user_id > 0) {
            // Try by user_id first (walk-ins that ran sync_invitees correctly).
            $fb = get_db()->prepare(
                'SELECT pp.table_number, pp.seat_number FROM poker_players pp
                 JOIN poker_sessions ps ON ps.id = pp.session_id
                 WHERE ps.event_id = ? AND pp.user_id = ?
                 ORDER BY pp.id DESC LIMIT 1'
            );
            $fb->execute([$wi_event_id, $user_id]);
            if ($fbr = $fb->fetch()) {
                $wi_table = $fbr['table_number'] ?: null;
                $wi_seat  = $fbr['seat_number']  ?: null;
            }
            // Last resort: match by username (display_name) for rows where user_id
            // wasn't populated by sync_invitees (e.g., race between users INSERT and
            // the invitee JOIN). Requires knowing this session's user.
            if ($wi_table === null) {
                $un = get_db()->prepare('SELECT username FROM users WHERE id = ?');
                $un->execute([$user_id]);
                $uname = $un->fetchColumn();
                if ($uname) {
                    $fb2 = get_db()->prepare(
                        'SELECT pp.table_number, pp.seat_number FROM poker_players pp
                         JOIN poker_sessions ps ON ps.id = pp.session_id
                         WHERE ps.event_id = ? AND LOWER(pp.display_name) = LOWER(?)
                         ORDER BY pp.id DESC LIMIT 1'
                    );
                    $fb2->execute([$wi_event_id, $uname]);
                    if ($fbr2 = $fb2->fetch()) {
                        $wi_table = $fbr2['table_number'] ?: null;
                        $wi_seat  = $fbr2['seat_number']  ?: null;
                    }
                }
            }
        }
        // Clear the walk-in context so a page refresh doesn't keep re-rendering stale info.
        unset($_SESSION['walkin_event_id'], $_SESSION['walkin_player_id']);
        ?>
        <?php if ($wi_table !== null): ?>
        <div style="margin-top:1rem;padding:1.2rem;background:#eff6ff;border:2px solid #3b82f6;border-radius:10px;text-align:center">
            <div style="font-size:.85rem;color:#3b82f6;font-weight:600;text-transform:uppercase;letter-spacing:.05em">Your Seat</div>
            <div style="font-size:2.25rem;font-weight:800;color:#1e40af;line-height:1.2">
                Table <?= (int)$wi_table ?><?php if ($wi_seat): ?> &middot; Seat <?= (int)$wi_seat ?><?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
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
