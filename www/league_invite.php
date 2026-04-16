<?php
/**
 * League invite landing page.
 * URL: /league_invite.php?token=XYZ
 *
 * Looks up a pending league_members row by invite_token. If the viewer is already
 * logged in, auto-link the pending row (if email/phone matches) and redirect.
 * Otherwise, bounce the visitor to /register.php with their contact info pre-filled
 * — the signup hook in auth.php will claim the pending row automatically.
 */
require_once __DIR__ . '/auth.php';

$db    = get_db();
$token = trim($_GET['token'] ?? '');
$site  = get_setting('site_name', 'Game Night');

$row = null;
if ($token !== '') {
    $s = $db->prepare(
        "SELECT lm.*, l.name AS league_name, l.description AS league_description
         FROM league_members lm
         JOIN leagues l ON l.id = lm.league_id
         WHERE lm.invite_token = ? AND lm.user_id IS NULL
         LIMIT 1"
    );
    $s->execute([$token]);
    $row = $s->fetch() ?: null;
}

$current = current_user();

if (!$row) {
    http_response_code(404);
    // Fall through to render a friendly error (below).
} else {
    // If a logged-in user lands here and their email/phone matches the pending row, link it.
    if ($current) {
        $email = strtolower(trim((string)($current['email'] ?? '')));
        $phone = trim((string)($current['phone'] ?? ''));
        $match = false;
        if ($email !== '' && !empty($row['contact_email']) && strcasecmp($email, (string)$row['contact_email']) === 0) $match = true;
        if (!$match && $phone !== '' && !empty($row['contact_phone']) && $phone === (string)$row['contact_phone']) $match = true;
        if ($match) {
            $db->prepare(
                "UPDATE league_members
                 SET user_id = ?, contact_name = NULL, contact_email = NULL, contact_phone = NULL, invite_token = NULL
                 WHERE id = ?"
            )->execute([(int)$current['id'], (int)$row['id']]);
            header('Location: /league.php?id=' . (int)$row['league_id']);
            exit;
        }
        // Logged in but identity doesn't match — show a friendly mismatch page.
        $mismatch = true;
    } else {
        // Not logged in: send them to register with email pre-filled. Signup hook handles the claim.
        $qs = http_build_query([
            'email' => $row['contact_email'] ?? '',
            'phone' => $row['contact_phone'] ?? '',
        ]);
        header('Location: /register.php?' . $qs);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Invitation — <?= htmlspecialchars($site) ?></title>
    <link rel="stylesheet" href="/style.css">
    <style>
        .li-wrap { max-width: 520px; margin: 3rem auto; padding: 0 1rem; }
        .li-card { background: #fff; border: 1.5px solid #e2e8f0; border-radius: 10px; padding: 1.5rem; text-align: center; }
        .li-card h1 { font-size: 1.35rem; margin: 0 0 .75rem; color: #1e293b; }
        .li-card p  { color: #64748b; margin: 0 0 1rem; font-size: .95rem; }
        .li-btn { background: #2563eb; color: #fff; padding: .55rem 1.1rem; border-radius: 6px; text-decoration: none; font-weight: 600; display: inline-block; }
    </style>
</head>
<body>
<div class="li-wrap">
    <div class="li-card">
        <?php if (!$row): ?>
            <h1>Invitation not found</h1>
            <p>This invite link is invalid, expired, or has already been accepted.</p>
            <a class="li-btn" href="/">Home</a>
        <?php elseif (!empty($mismatch)): ?>
            <h1>Account mismatch</h1>
            <p>This invitation was sent to <strong><?= htmlspecialchars($row['contact_email'] ?: $row['contact_phone']) ?></strong>, which doesn't match your current account.</p>
            <p><a href="/logout.php">Sign out</a> and use the invite link again, or ask the league owner to send a new invite.</p>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
