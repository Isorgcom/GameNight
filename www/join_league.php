<?php
/**
 * Shareable league invite handler.
 * URL: /join_league.php?code=XYZ
 *
 * - Not logged in → redirects to /login.php with a return URL so the invite completes after auth.
 * - Logged in + auto-approval league → adds the user as a member immediately.
 * - Logged in + manual-approval league → creates a pending join request.
 * - Already a member → sends to the league page.
 * - Invalid / hidden-without-access code → friendly error page.
 */
require_once __DIR__ . '/auth.php';

$db   = get_db();
$site = get_setting('site_name', 'Game Night');
$code = trim($_GET['code'] ?? '');

$league = null;
if ($code !== '') {
    $s = $db->prepare('SELECT * FROM leagues WHERE invite_code = ? LIMIT 1');
    $s->execute([$code]);
    $league = $s->fetch() ?: null;
}

$current = current_user();

// Not found
if (!$league) {
    http_response_code(404);
    $message_title = 'Invite not found';
    $message_body  = 'This invite link is invalid or has been regenerated. Ask the league owner for a fresh link.';
    render_page($site, $message_title, $message_body);
    exit;
}

// Not logged in → bounce through login with a return path that brings them back here.
if (!$current) {
    $ret = '/join_league.php?code=' . urlencode($code);
    header('Location: /login.php?redirect=' . urlencode($ret));
    exit;
}

$uid = (int)$current['id'];
$league_id = (int)$league['id'];

// Already a member
if (league_role($league_id, $uid) !== null) {
    header('Location: /league.php?id=' . $league_id);
    exit;
}

// Handle the join based on approval mode.
if ($league['approval_mode'] === 'auto') {
    $db->prepare('INSERT INTO league_members (league_id, user_id, role, invited_by, invited_at) VALUES (?, ?, ?, NULL, CURRENT_TIMESTAMP)')
        ->execute([$league_id, $uid, 'member']);

    // FYI to owner
    $stmt = $db->prepare('SELECT username, email, phone, preferred_contact FROM users WHERE id = ?');
    $stmt->execute([(int)$league['owner_id']]);
    if ($ownerRow = $stmt->fetch()) {
        send_notification(
            $ownerRow['username'] ?? '',
            $ownerRow['email']    ?? '',
            $ownerRow['phone']    ?? '',
            $ownerRow['preferred_contact'] ?? 'email',
            'New member joined ' . $league['name'],
            $current['username'] . ' joined your league "' . $league['name'] . '" via an invite link.',
            '<p><strong>' . htmlspecialchars($current['username']) . '</strong> joined your league <strong>' . htmlspecialchars($league['name']) . '</strong> via an invite link.</p>'
        );
    }

    header('Location: /league.php?id=' . $league_id);
    exit;
}

// Manual: create a pending join request if one doesn't already exist.
$chk = $db->prepare("SELECT 1 FROM league_join_requests WHERE league_id = ? AND user_id = ? AND status = 'pending'");
$chk->execute([$league_id, $uid]);
if (!$chk->fetchColumn()) {
    try {
        $db->prepare(
            "INSERT INTO league_join_requests (league_id, user_id, message, status) VALUES (?, ?, ?, 'pending')"
        )->execute([$league_id, $uid, 'Requested via invite link.']);
    } catch (Throwable $e) { /* duplicate → fine */ }

    // Notify owner + managers
    $reviewUrl = get_site_url() . '/league.php?id=' . $league_id . '&tab=requests';
    if (get_setting('url_shortener_enabled') === '1') { $reviewUrl = shorten_url($reviewUrl); }
    $approvers = $db->prepare("SELECT user_id FROM league_members WHERE league_id = ? AND role IN ('owner','manager') AND user_id IS NOT NULL");
    $approvers->execute([$league_id]);
    foreach ($approvers->fetchAll() as $a) {
        $u = $db->prepare('SELECT username, email, phone, preferred_contact FROM users WHERE id = ?');
        $u->execute([(int)$a['user_id']]);
        if ($row = $u->fetch()) {
            send_notification(
                $row['username'] ?? '',
                $row['email']    ?? '',
                $row['phone']    ?? '',
                $row['preferred_contact'] ?? 'email',
                'New join request for ' . $league['name'],
                $current['username'] . ' requested to join "' . $league['name'] . '" via invite link. Review: ' . $reviewUrl,
                '<p><strong>' . htmlspecialchars($current['username']) . '</strong> requested to join <strong>' . htmlspecialchars($league['name']) . '</strong> via an invite link.</p>'
                . '<p><a href="' . htmlspecialchars($reviewUrl) . '">Review request</a></p>'
            );
        }
    }
}

render_page($site,
    'Request sent',
    'Your request to join <strong>' . htmlspecialchars($league['name']) . '</strong> has been sent. You\'ll be notified when the league owner approves it.',
    '/leagues.php?tab=requests',
    'View my requests'
);
exit;

function render_page(string $site, string $title, string $body_html, string $action_url = '/', string $action_label = 'Home'): void { ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($title) ?> — <?= htmlspecialchars($site) ?></title>
    <link rel="stylesheet" href="/style.css">
    <style>
        .jl-wrap { max-width: 520px; margin: 3rem auto; padding: 0 1rem; }
        .jl-card { background: #fff; border: 1.5px solid #e2e8f0; border-radius: 10px; padding: 1.75rem; text-align: center; }
        .jl-card h1 { margin: 0 0 .75rem; font-size: 1.3rem; }
        .jl-card p  { color: #475569; margin: 0 0 1rem; }
        .jl-btn { background: #2563eb; color: #fff; padding: .55rem 1.2rem; border-radius: 6px; text-decoration: none; font-weight: 600; display: inline-block; }
    </style>
</head>
<body>
<div class="jl-wrap">
    <div class="jl-card">
        <h1><?= htmlspecialchars($title) ?></h1>
        <p><?= $body_html ?></p>
        <a class="jl-btn" href="<?= htmlspecialchars($action_url) ?>"><?= htmlspecialchars($action_label) ?></a>
    </div>
</div>
</body>
</html>
<?php }
