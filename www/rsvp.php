<?php
/**
 * Tokenized RSVP endpoint — allows users to RSVP via email link without logging in.
 *
 * Usage: /rsvp.php?token=XXX&r=yes|no|maybe
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/version.php';

$token = trim($_GET['token'] ?? '');
$rsvp  = strtolower(trim($_GET['r'] ?? ''));
$valid = ['yes', 'no', 'maybe'];

$site_name = get_setting('site_name', 'Game Night');

if ($token === '' || !in_array($rsvp, $valid, true)) {
    http_response_code(400);
    show_page('Invalid Link', 'This RSVP link is invalid or incomplete.', 'error');
    exit;
}

$db   = get_db();
$stmt = $db->prepare('SELECT ei.id, ei.event_id, ei.username, ei.rsvp, e.title, e.start_date, e.start_time
                       FROM event_invites ei
                       JOIN events e ON e.id = ei.event_id
                       WHERE ei.rsvp_token = ?');
$stmt->execute([$token]);
$invite = $stmt->fetch();

if (!$invite) {
    show_page('Link Expired', 'This RSVP link is no longer valid. The event may have been updated.', 'error');
    exit;
}

// Update the RSVP
$db->prepare('UPDATE event_invites SET rsvp = ? WHERE id = ?')
   ->execute([$rsvp, $invite['id']]);

$label   = ucfirst($rsvp);
$date_str = $invite['start_date'] . ($invite['start_time'] ? ' at ' . date('g:i A', strtotime($invite['start_time'])) : '');

// Log activity
$userStmt = $db->prepare('SELECT id FROM users WHERE LOWER(username) = LOWER(?)');
$userStmt->execute([$invite['username']]);
$userRow = $userStmt->fetch();
if ($userRow) {
    $db->prepare('INSERT INTO activity_log (user_id, action, ip) VALUES (?, ?, ?)')
       ->execute([$userRow['id'], "Email RSVP $rsvp for event id: " . $invite['event_id'], $_SERVER['REMOTE_ADDR'] ?? '']);
}

// Notify event creator
$creatorStmt = $db->prepare('SELECT u.username, u.email, u.phone, u.preferred_contact FROM events e JOIN users u ON u.id=e.created_by WHERE e.id=?');
$creatorStmt->execute([$invite['event_id']]);
$creator = $creatorStmt->fetch();
if ($creator && strtolower($creator['username']) !== strtolower($invite['username'])) {
    require_once __DIR__ . '/auth_dl.php';
    $smsBody  = $invite['username'] . " RSVPed $label to \"{$invite['title']}\" on {$invite['start_date']}";
    $htmlBody = '<p><strong>' . htmlspecialchars($invite['username']) . '</strong> RSVPed <strong>' . $label . '</strong> to '
              . '<em>' . htmlspecialchars($invite['title']) . '</em> on ' . htmlspecialchars($invite['start_date']) . '.</p>';
    send_notification($creator['username'], $creator['email'] ?? '', $creator['phone'] ?? '',
        $creator['preferred_contact'] ?? 'email',
        $invite['username'] . " RSVPed $label: " . $invite['title'],
        $smsBody, $htmlBody);
}

$title = "RSVP: $label";
$msg   = 'Your RSVP for <strong>' . htmlspecialchars($invite['title']) . '</strong> on ' . htmlspecialchars($date_str) . ' has been set to <strong>' . $label . '</strong>.';

// Build change-mind links
$base = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/rsvp.php?token=' . urlencode($token);
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
