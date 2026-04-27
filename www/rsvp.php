<?php
/**
 * Tokenized RSVP endpoint — allows users to RSVP via email/SMS link without logging in.
 *
 * Usage: /rsvp.php?token=XXX&r=yes|no|maybe
 *
 * GET renders a confirmation form. POST applies the RSVP. This split exists because
 * SMS provider URL safety scanners and link-preview crawlers hit every URL in a
 * message body within seconds of delivery — a 1-click GET-that-writes lets those
 * crawlers silently flip an invitee's RSVP before the human ever opens the message.
 * Confirmation-on-POST keeps the link safe from background fetches.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/version.php';

$token      = trim($_REQUEST['token'] ?? '');
$rsvp       = strtolower(trim($_REQUEST['r'] ?? ''));
$allowMaybe = get_setting('allow_maybe_rsvp', '1') === '1';
$valid       = array_merge(['yes', 'no'], $allowMaybe ? ['maybe'] : []);

$site_name = get_setting('site_name', 'Game Night');

if ($token === '' || !in_array($rsvp, $valid, true)) {
    http_response_code(400);
    show_page('Invalid Link', 'This RSVP link is invalid or incomplete.', 'error');
    exit;
}

$db   = get_db();
$stmt = $db->prepare('SELECT ei.id, ei.event_id, ei.username, ei.rsvp, ei.approval_status, ei.rsvp_token_flips, e.title, e.start_date, e.start_time
                       FROM event_invites ei
                       JOIN events e ON e.id = ei.event_id
                       WHERE ei.rsvp_token = ?');
$stmt->execute([$token]);
$invite = $stmt->fetch();

if (!$invite) {
    show_page('Link Expired', 'This RSVP link is no longer valid. The event may have been updated.', 'error');
    exit;
}

// Reject RSVPs for invites that haven't been approved yet (or were denied).
if (($invite['approval_status'] ?? 'approved') !== 'approved') {
    show_page('Awaiting Approval',
        'Your spot for <strong>' . htmlspecialchars($invite['title']) . '</strong> is waiting for the host to approve. You will receive another notification when you have been approved.',
        'error');
    exit;
}

$flipsSoFar   = (int)($invite['rsvp_token_flips'] ?? 0);
$rsvp_changed = ($invite['rsvp'] ?? '') !== $rsvp;
$label        = ucfirst($rsvp);
$date_str     = $invite['start_date'] . ($invite['start_time'] ? ' at ' . date('g:i A', strtotime($invite['start_time'])) : '');

// Look up whether the invitee has a registered account (used by both branches).
$userStmt = $db->prepare('SELECT id FROM users WHERE LOWER(username) = LOWER(?)');
$userStmt->execute([$invite['username']]);
$userRow = $userStmt->fetch();

// ── GET: render confirmation form (no state change) ──────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if ($rsvp_changed && $flipsSoFar >= MAX_RSVP_TOKEN_FLIPS) {
        show_page('Link Exhausted',
            'This RSVP link has been used too many times. Please <a href="/login.php">sign in</a> to change your RSVP for <strong>' . htmlspecialchars($invite['title']) . '</strong>.',
            'error');
        exit;
    }

    $csrf      = csrf_token();
    $alreadySet = !$rsvp_changed;
    $heading   = $alreadySet ? 'Confirm RSVP: ' . $label : 'Confirm Your RSVP';
    $body      = '<p>Confirm your RSVP for <strong>' . htmlspecialchars($invite['title']) . '</strong> on ' . htmlspecialchars($date_str) . ' as <strong>' . $label . '</strong>?</p>';
    $btnColor  = $rsvp === 'yes' ? '#16a34a' : ($rsvp === 'no' ? '#dc2626' : '#d97706');
    $body     .= '<form method="post" action="/rsvp.php" style="margin-top:1.5rem">'
              . '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrf) . '">'
              . '<input type="hidden" name="token" value="' . htmlspecialchars($token) . '">'
              . '<input type="hidden" name="r" value="' . htmlspecialchars($rsvp) . '">'
              . '<button type="submit" style="display:inline-block;padding:.7rem 2rem;border:none;border-radius:6px;background:' . $btnColor . ';color:#fff;font-weight:600;font-size:1rem;cursor:pointer">Confirm ' . $label . '</button>'
              . '</form>'
              . '<p style="margin-top:1rem"><a href="/" style="color:#64748b;text-decoration:none;font-size:.875rem">Cancel</a></p>';
    show_page($heading, $body, 'success');
    exit;
}

// ── POST: apply the RSVP ─────────────────────────────────────────────────────
// csrf_verify() reads $_SESSION but doesn't start the session itself, so we
// must do it explicitly here (the GET branch starts it via csrf_token()).
session_start_safe();
if (!csrf_verify()) {
    http_response_code(400);
    show_page('Session Expired', 'Please tap the link again to confirm your RSVP.', 'error');
    exit;
}

if ($rsvp_changed && $flipsSoFar >= MAX_RSVP_TOKEN_FLIPS) {
    show_page('Link Exhausted',
        'This RSVP link has been used too many times. Please <a href="/login.php">sign in</a> to change your RSVP for <strong>' . htmlspecialchars($invite['title']) . '</strong>.',
        'error');
    exit;
}

// Update the RSVP + bump the flip counter only when the value actually changed.
if ($rsvp_changed) {
    $db->prepare('UPDATE event_invites SET rsvp = ?, rsvp_token_flips = rsvp_token_flips + 1 WHERE id = ?')
       ->execute([$rsvp, $invite['id']]);
} else {
    $db->prepare('UPDATE event_invites SET rsvp = ? WHERE id = ?')
       ->execute([$rsvp, $invite['id']]);
}

// Auto-promote waitlisted invitee if someone declined
if ($rsvp === 'no') {
    maybe_promote_waitlisted($db, (int)$invite['event_id']);
}

// Log every flip via tokenized link, even for pending (non-account) invitees,
// so future audits can see who clicked what. user_id=0 is the convention used
// elsewhere in activity_log for unattributable rows (e.g. walkin_rsvp).
if ($rsvp_changed) {
    if ($userRow) {
        $db->prepare('INSERT INTO activity_log (user_id, action, ip) VALUES (?, ?, ?)')
           ->execute([$userRow['id'], "Email RSVP $rsvp for event id: " . $invite['event_id'], $_SERVER['REMOTE_ADDR'] ?? '']);
    } else {
        $db->prepare('INSERT INTO activity_log (user_id, action, ip) VALUES (?, ?, ?)')
           ->execute([0, "Email RSVP $rsvp for event id: " . $invite['event_id'] . " (pending invitee: " . $invite['username'] . ", invite_id: " . $invite['id'] . ")", $_SERVER['REMOTE_ADDR'] ?? '']);
    }

    $creatorStmt = $db->prepare('SELECT u.username FROM events e JOIN users u ON u.id=e.created_by WHERE e.id=?');
    $creatorStmt->execute([$invite['event_id']]);
    $creator = $creatorStmt->fetch();
    if ($creator && strtolower($creator['username']) !== strtolower($invite['username'])) {
        require_once __DIR__ . '/_notifications.php';
        queue_event_notification($db, (int)$invite['event_id'], $creator['username'], 'rsvp_to_creator', null, [
            'rsvp'               => $rsvp,
            'responder_username' => $invite['username'],
            'responder_display'  => $invite['username'],
        ]);
    }
}

$title = "RSVP: $label";
$msg   = 'Your RSVP for <strong>' . htmlspecialchars($invite['title']) . '</strong> on ' . htmlspecialchars($date_str) . ' has been set to <strong>' . $label . '</strong>.';

// Build change-mind links
$base = get_site_url() . '/rsvp.php?token=' . urlencode($token);
$others = array_diff($valid, [$rsvp]);
$links = '';
foreach ($others as $alt) {
    $links .= '<a href="' . htmlspecialchars($base . '&r=' . $alt) . '" style="display:inline-block;margin:.25rem .4rem;padding:.5rem 1.2rem;border-radius:6px;text-decoration:none;font-weight:600;font-size:.9rem;'
            . ($alt === 'yes' ? 'background:#16a34a;color:#fff' : ($alt === 'no' ? 'background:#dc2626;color:#fff' : 'background:#d97706;color:#fff'))
            . '">' . ucfirst($alt) . '</a>';
}
$msg .= '<p style="margin-top:1.5rem;color:#64748b">Changed your mind? ' . $links . '</p>';

// Build event redirect URL for login/register
$month_str = substr($invite['start_date'], 0, 7);
$event_redirect = '/calendar.php?m=' . urlencode($month_str) . '&open=' . $invite['event_id'] . '&date=' . urlencode($invite['start_date']);

// Check if invitee has an account
$has_account = (bool)$userRow;
$allow_reg   = get_setting('allow_registration', '1') === '1';

$auth_links  = '<div style="margin-top:1.5rem;padding-top:1.25rem;border-top:1px solid #e2e8f0">';
if ($has_account) {
    $auth_links .= '<p style="color:#64748b;font-size:.875rem;margin-bottom:.75rem">View the full event details and comments:</p>'
                 . '<a href="/login.php?redirect=' . urlencode($event_redirect) . '" style="display:inline-block;padding:.5rem 1.5rem;border-radius:6px;text-decoration:none;font-weight:600;background:#2563eb;color:#fff;font-size:.9rem">Log in to ' . htmlspecialchars($site_name) . '</a>';
} else {
    $auth_links .= '<p style="color:#64748b;font-size:.875rem;margin-bottom:.75rem">Create an account to view event details, comment, and manage your RSVPs:</p>'
                 . '<a href="/login.php?redirect=' . urlencode($event_redirect) . '" style="display:inline-block;margin:.25rem .3rem;padding:.5rem 1.5rem;border-radius:6px;text-decoration:none;font-weight:600;background:#2563eb;color:#fff;font-size:.9rem">Log in</a>';
    if ($allow_reg) {
        $auth_links .= '<a href="/register.php?redirect=' . urlencode($event_redirect) . '" style="display:inline-block;margin:.25rem .3rem;padding:.5rem 1.5rem;border-radius:6px;text-decoration:none;font-weight:600;border:2px solid #2563eb;color:#2563eb;background:#fff;font-size:.9rem">Create Account</a>';
    }
}
$auth_links .= '</div>';
$msg .= $auth_links;

show_page($title, $msg, 'success');

// ── Render a simple branded page ────────────────────────────────────────────
function show_page(string $title, string $body, string $type): void {
    global $site_name;
    $color = $type === 'success' ? '#16a34a' : '#dc2626';
    $bg    = $type === 'success' ? '#f0fdf4' : '#fef2f2';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title) ?> — <?= htmlspecialchars($site_name) ?></title>
    <link rel="stylesheet" href="/style.css">
</head>
<body style="display:flex;align-items:center;justify-content:center;min-height:100vh;padding:1rem">
    <div style="max-width:480px;width:100%;text-align:center">
        <div style="background:<?= $bg ?>;border:2px solid <?= $color ?>;border-radius:12px;padding:2rem 1.5rem;margin-bottom:1.5rem">
            <h1 style="font-size:1.5rem;color:<?= $color ?>;margin:0 0 .75rem"><?= htmlspecialchars($title) ?></h1>
            <div style="font-size:1rem;color:#334155;line-height:1.6"><?= $body ?></div>
        </div>
        <a href="/" style="color:#2563eb;text-decoration:none;font-size:.9rem">Go to <?= htmlspecialchars($site_name) ?></a>
    </div>
</body>
</html>
<?php } ?>
