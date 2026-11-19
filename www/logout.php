<?php
require_once __DIR__ . '/auth.php';

// Sign-out is a POST with a CSRF token. As a plain GET link, any other site
// could sign a visitor out by embedding /logout.php (the session cookie is
// SameSite=Lax, which still rides along on a top-level GET). A GET now shows a
// one-button confirmation instead, so an old bookmark or link still works.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    session_start_safe();
    // A stale or missing token (an old tab) gets the confirmation page rather
    // than a silent sign-out a forged POST could also trigger.
    if (!csrf_verify() && current_user()) {
        header('Location: /logout.php');
        exit;
    }
    logout();
    // Drop this site's cached pages and every cookie it set, so nothing from
    // the signed-in session is left in the browser. Not "storage": that would
    // also unregister the service worker and end push notifications on this
    // device, and local preferences (a collapsed nav) are not account data.
    // HTTPS only: browsers refuse the header on a plain-HTTP origin.
    if (_is_https()) header('Clear-Site-Data: "cache", "cookies"');
    header('Location: /');
    exit;
}

$user = current_user();
if (!$user) {
    header('Location: /');
    exit;
}
$site_name = get_setting('site_name', 'Game Night');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign out &mdash; <?= htmlspecialchars($site_name) ?></title>
    <meta name="robots" content="noindex">
    <link rel="stylesheet" href="/style.css?v=<?= htmlspecialchars(APP_VERSION . '.' . (@filemtime(__DIR__ . '/style.css') ?: 0)) ?>">
</head>
<body>
<?php $nav_active = ''; $nav_user = $user; require __DIR__ . '/_nav.php'; ?>
<div class="card-wrap" role="main">
    <div class="card" style="text-align:center">
        <h1 style="font-size:1.4rem;margin-bottom:.5rem">Sign out?</h1>
        <p class="subtitle">You are signed in as <?= htmlspecialchars($user['username'], ENT_QUOTES | ENT_SUBSTITUTE) ?>.</p>
        <form method="post" action="/logout.php" style="margin-top:1rem">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES | ENT_SUBSTITUTE) ?>">
            <button type="submit" class="btn btn-primary" style="width:100%">Sign out</button>
        </form>
        <p style="margin-top:1rem"><a href="/">Stay signed in</a></p>
    </div>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
</body>
</html>
