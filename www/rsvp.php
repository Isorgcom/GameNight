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
$stmt = $db->prepare('SELECT ei.id, ei.event_id, ei.username, ei.rsvp, ei.approval_status, ei.rsvp_token_flips, e.title, e.description, e.start_date, e.start_time, e.end_time
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

    // Event details so the invitee can read what they're responding to before committing.
    $pretty_date = date('l, F j, Y', strtotime($invite['start_date']));
    $pretty_time = '';
    if (!empty($invite['start_time'])) {
        $pretty_time = date('g:i A', strtotime($invite['start_time']));
        if (!empty($invite['end_time'])) $pretty_time .= ' &ndash; ' . date('g:i A', strtotime($invite['end_time']));
    }

    // Who's coming — approved base invitees only (display names, no contact info).
    $attStmt = $db->prepare("SELECT username, rsvp FROM event_invites
                             WHERE event_id = ? AND occurrence_date IS NULL AND approval_status = 'approved'
                             ORDER BY COALESCE(sort_order, 999999), username");
    $attStmt->execute([(int)$invite['event_id']]);
    $going = []; $maybeList = [];
    foreach ($attStmt->fetchAll() as $a) {
        $r = strtolower((string)($a['rsvp'] ?? ''));
        if ($r === 'yes')   $going[]     = $a['username'];
        if ($r === 'maybe') $maybeList[] = $a['username'];
    }
    $names_block = function(string $lbl, array $names, string $color): string {
        if (empty($names)) return '';
        $out  = '<div style="margin-top:.85rem"><div style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:' . $color . ';margin-bottom:.3rem">'
              . htmlspecialchars($lbl) . ' (' . count($names) . ')</div><div style="display:flex;flex-wrap:wrap;gap:.3rem;justify-content:center">';
        foreach ($names as $n) {
            $out .= '<span style="font-size:.82rem;color:#334155;background:#f1f5f9;border-radius:999px;padding:.18rem .65rem">' . htmlspecialchars($n) . '</span>';
        }
        return $out . '</div></div>';
    };

    $body  = '<div style="text-align:left;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:1rem 1.1rem;margin-bottom:1.25rem">';
    $body .= '<div style="font-size:1.05rem;font-weight:800;color:#1e293b;margin-bottom:.3rem">' . htmlspecialchars($invite['title']) . '</div>';
    $body .= '<div style="font-size:.9rem;color:#475569">' . htmlspecialchars($pretty_date) . '</div>';
    if ($pretty_time !== '') $body .= '<div style="font-size:.9rem;color:#475569">' . $pretty_time . '</div>';
    if (!empty($invite['description'])) {
        $body .= '<div style="margin-top:.7rem;font-size:.9rem;color:#334155;line-height:1.5">' . nl2br(htmlspecialchars($invite['description'])) . '</div>';
    }
    $body .= $names_block('Going', $going, '#16a34a');
    $body .= $names_block('Maybe', $maybeList, '#d97706');
    $body .= '</div>';

    $body     .= '<p>RSVP for this event as <strong>' . $label . '</strong>?</p>';
    $btnColor  = $rsvp === 'yes' ? '#16a34a' : ($rsvp === 'no' ? '#dc2626' : '#d97706');
    $body     .= '<form method="post" action="/rsvp.php" style="margin-top:1.5rem">'
              . '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrf) . '">'
              . '<input type="hidden" name="token" value="' . htmlspecialchars($token) . '">'
              . '<input type="hidden" name="r" value="' . htmlspecialchars($rsvp) . '">'
              . '<button type="submit" style="display:inline-block;padding:.7rem 2rem;border:none;border-radius:6px;background:' . $btnColor . ';color:#fff;font-weight:600;font-size:1rem;cursor:pointer">Confirm ' . $label . '</button>'
              . '</form>'
              . '<p style="margin-top:1rem"><a href="/event.php?token=' . urlencode($token) . '" style="color:#64748b;text-decoration:none;font-size:.875rem">Cancel</a></p>';
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

// Land the invitee on the public event page (no login required). It shows the event
// details, who's coming, and lets them change their RSVP. The `just` param surfaces a
// one-line "your RSVP is set" confirmation banner there.
header('Location: /event.php?token=' . urlencode($token) . '&just=' . urlencode($rsvp));
exit;

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
