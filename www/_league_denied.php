<?php
/**
 * Friendly "you cannot view this league" page. Included from league.php (and
 * potentially other league pages) when the current user is denied access.
 *
 * Caller must have already set:
 *   $current     — current user row (we are always logged in here; require_login above)
 *   $site_name   — site name string
 *   $denyReason  — one of 'hidden_non_member' (currently the only case)
 *
 * The caller is expected to set http_response_code(403) before requiring this.
 */
$reason = $denyReason ?? 'hidden_non_member';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>League not available &mdash; <?= htmlspecialchars($site_name) ?></title>
    <link rel="stylesheet" href="/style.css">
    <style>
        .deny-wrap { max-width: 640px; margin: 2.5rem auto; padding: 0 1rem; }
        .deny-card { background: #fff; border: 1.5px solid #e2e8f0; border-radius: 12px; padding: 2rem 1.75rem; text-align: center; }
        .deny-icon { font-size: 2.5rem; margin-bottom: .5rem; }
        .deny-card h1 { font-size: 1.4rem; font-weight: 700; margin: 0 0 .75rem; color: #0f172a; }
        .deny-card p { color: #475569; line-height: 1.6; margin: 0 0 1rem; }
        .deny-actions { display: flex; gap: .6rem; justify-content: center; flex-wrap: wrap; margin-top: 1.25rem; }
        .deny-btn { display: inline-block; padding: .55rem 1.25rem; border-radius: 8px; font-weight: 600; font-size: .9rem; text-decoration: none; }
        .deny-btn-primary { background: #2563eb; color: #fff; }
        .deny-btn-primary:hover { background: #1d4ed8; }
        .deny-btn-ghost { background: #fff; color: #475569; border: 1.5px solid #cbd5e1; }
        .deny-btn-ghost:hover { background: #f8fafc; }
    </style>
</head>
<body>

<?php $nav_active = 'leagues'; $nav_user = $current; require __DIR__ . '/_nav.php'; ?>

<div class="deny-wrap">
    <div class="deny-card">
        <div class="deny-icon">&#128274;</div>
        <h1>This league is private</h1>
        <p>
            You don't have access to this league because it's set to <strong>hidden</strong>
            and you aren't a member. Hidden leagues don't appear in the public Leagues
            list and can't be joined directly &mdash; you need an invite from one of the
            league's owners or managers.
        </p>
        <p style="font-size:.875rem;color:#64748b">
            If you think you should have access, ask the league owner to add you, or
            check that you're signed in with the right account.
        </p>
        <div class="deny-actions">
            <a href="/leagues.php" class="deny-btn deny-btn-primary">Browse leagues</a>
            <a href="/" class="deny-btn deny-btn-ghost">Go home</a>
        </div>
    </div>
</div>

<?php require __DIR__ . '/_footer.php'; ?>
</body>
</html>
