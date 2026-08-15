<?php
/**
 * Timer BETA layout editor (phase B).
 *
 * The preview pane is the real display page (timer_beta.php?embed=1) in an
 * iframe, so what you see is exactly what a TV gets — same renderer, same
 * fit-to-box, same vh sizing, driven through window.TBPreview (same origin).
 *
 * The editor never touches timer state; it only reads/writes timer_layouts
 * through timer_beta_dl.php, which sanitizes every save server-side.
 *
 * The editor body itself lives in _timer_beta_editor.php, shared with
 * event_display.php (the per-event Timer Display page).
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_login();
$db = get_db();
$current = current_user();
$site_name = get_setting('site_name', 'Game Night');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Timer BETA Layout Editor &mdash; <?= htmlspecialchars($site_name) ?></title>
    <link rel="stylesheet" href="/style.css?v=<?= htmlspecialchars(APP_VERSION . '.' . (@filemtime(__DIR__ . '/style.css') ?: 0)) ?>">
    <link rel="stylesheet" href="/timer_beta_edit.css?v=<?= htmlspecialchars(APP_VERSION . '.' . (@filemtime(__DIR__ . '/timer_beta_edit.css') ?: 0)) ?>">
</head>
<body class="tbe-body">
<?php $nav_active = 'timer-beta'; $nav_user = $current; require __DIR__ . '/_nav.php'; ?>

<?php require __DIR__ . '/_timer_beta_editor.php'; ?>

<?php require __DIR__ . '/_footer.php'; ?>
</body>
</html>
