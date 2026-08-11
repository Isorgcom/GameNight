<?php
/**
 * Friendly "you cannot open this game" page, modelled on _league_denied.php.
 *
 * This exists because verify_event_access() in _poker_helpers.php answers with
 * `{"ok":false,"error":"Access denied"}` and a 403. That is right for the
 * *_dl.php endpoints it was written for, and wrong for a full page: timer.php
 * was its only page-level caller, so opening someone else's game timer in a
 * browser printed a raw JSON blob with no explanation and no way back.
 *
 * Caller must have already set:
 *   $current     — current user row (the page requires login before this)
 *   $site_name   — site name string
 *   $eventTitle  — event title, or '' when the caller would rather not say
 *   $denyReason  — 'no_rights' (403) or 'missing' (404)
 *
 * The caller sets the response code before requiring this file.
 */
$eventTitle = $eventTitle ?? '';
$denyReason = $denyReason ?? 'no_rights';
$isMissing  = ($denyReason === 'missing');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $isMissing ? 'Game not found' : 'Game not available' ?> &mdash; <?= htmlspecialchars($site_name) ?></title>
    <link rel="stylesheet" href="/style.css?v=<?= htmlspecialchars(APP_VERSION . '.' . (@filemtime(__DIR__ . '/style.css') ?: 0)) ?>">
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

<?php $nav_active = ''; $nav_user = $current; require __DIR__ . '/_nav.php'; ?>

<div class="deny-wrap">
    <div class="deny-card">
        <div class="deny-icon"><?= $isMissing ? '&#128269;' : '&#128274;' ?></div>
        <?php if ($isMissing): ?>
        <h1>That game doesn't exist</h1>
        <p>
            The game this timer link points at has been deleted, or the link has a
            typo in it. Nothing is wrong with your account.
        </p>
        <?php else: ?>
        <h1>You can't run this game's timer</h1>
        <p>
            <?php if ($eventTitle !== ''): ?>
                <strong><?= htmlspecialchars($eventTitle) ?></strong> is hosted by someone else.
            <?php else: ?>
                This game is hosted by someone else.
            <?php endif; ?>
            Running a timer takes host rights, which means being the game's host, one of
            its managers, or an owner or manager of the league it belongs to.
        </p>
        <p style="font-size:.875rem;color:#64748b">
            If you expected access, ask the host to add you as a manager on the game, or
            check you're signed in with the right account. Being invited to a game isn't
            the same as being able to run it.
        </p>
        <?php endif; ?>
        <div class="deny-actions">
            <a href="/timer.php" class="deny-btn deny-btn-primary">Open a standalone timer</a>
            <a href="/my_events.php" class="deny-btn deny-btn-ghost">My games</a>
        </div>
    </div>
</div>

<?php require __DIR__ . '/_footer.php'; ?>
</body>
</html>
