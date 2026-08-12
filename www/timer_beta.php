<?php
/**
 * Timer BETA — Tournament-Director-style layout engine, phase A.
 *
 * Deliberately isolated from the real timer: separate page, stylesheet and
 * script, and DISPLAY-ONLY. State comes from timer_dl.php?action=get_state,
 * the same read path the remote viewer uses, so this page has no write
 * surface at all. Deleting timer_beta.{php,css,js} removes the feature.
 *
 * A layout here is a JSON tree of rows/columns/cells rendered into nested
 * flexbox (see timer_beta.js). Four built-in layouts ship as starting points,
 * and the editor (timer_beta_edit.php) builds custom ones.
 *
 * With ?event_id=N it shows that game's live state (host rights required,
 * same gate as timer.php). Without one it runs on sample data so layouts
 * can be judged without a game in progress.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_poker_helpers.php';

$db = get_db();
$current = current_user();
if (!$current) { header('Location: /login.php?redirect=' . urlencode($_SERVER['REQUEST_URI'])); exit; }
$isAdmin = $current['role'] === 'admin';
$site_name = get_setting('site_name', 'Game Night');

$event_id = (int)($_GET['event_id'] ?? 0);
$session_id = 0;
$event_title = '';
// Embed mode: the layout editor loads this page in an iframe and drives the
// renderer through window.TBPreview. Sample state, no polling, no corner bar.
$is_embed = isset($_GET['embed']) && $_GET['embed'] === '1';
if ($is_embed) { $event_id = 0; csp_allow_same_origin_framing(); }

if ($event_id) {
    $t = $db->prepare('SELECT title FROM events WHERE id = ?');
    $t->execute([$event_id]);
    $event_title = $t->fetchColumn();
    // Minimal denial pages: BETA keeps zero dependencies on partials so it can
    // be deleted cleanly. Wording matches _event_denied.php on the redesign branch.
    if ($event_title === false) {
        http_response_code(404);
        exit('<!doctype html><meta charset="utf-8"><title>Game not found</title><body style="background:#0f172a;color:#e2e8f0;font-family:sans-serif;text-align:center;padding-top:4rem"><h1>That game doesn&#8217;t exist</h1><p><a style="color:#60a5fa" href="/timer_beta.php">Open Timer BETA with sample data</a></p></body>');
    }
    if (!check_event_access($db, $event_id, $current, $isAdmin)) {
        http_response_code(403);
        exit('<!doctype html><meta charset="utf-8"><title>Game not available</title><body style="background:#0f172a;color:#e2e8f0;font-family:sans-serif;text-align:center;padding-top:4rem"><h1>You can&#8217;t view this game&#8217;s timer</h1><p>Running or viewing a game timer takes host rights.</p><p><a style="color:#60a5fa" href="/timer_beta.php">Open Timer BETA with sample data</a></p></body>');
    }
    $s = $db->prepare('SELECT id FROM poker_sessions WHERE event_id = ?');
    $s->execute([$event_id]);
    $session_id = (int)($s->fetchColumn() ?: 0);
    $event_title = (string)$event_title;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Timer BETA &mdash; <?= htmlspecialchars($site_name) ?></title>
    <link rel="stylesheet" href="/timer_beta.css?v=<?= htmlspecialchars(APP_VERSION . '.' . (@filemtime(__DIR__ . '/timer_beta.css') ?: 0)) ?>">
</head>
<body>

<div id="tbRoot" aria-live="off"></div>

<?php if (!$is_embed): ?>
<div id="tbBar">
    <span class="tb-badge">BETA</span>
    <select id="tbLayoutSelect" title="Layout"></select>
    <?php if ($session_id): ?>
        <a href="/timer.php?event_id=<?= (int)$event_id ?>" title="Open the current timer">Timer</a>
    <?php else: ?>
        <span class="tb-sample" title="No game linked — showing sample data">sample</span>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($session_id && !$is_embed): ?>
<!-- Control tray: shown by timer_beta.js only once get_state confirms the
     viewer can control this game. Every button posts the same `command` the
     main timer uses; the server re-checks manage rights on each. -->
<div id="tbControls" hidden>
    <button type="button" data-cmd="skip_prev" title="Previous level (Left)" aria-label="Previous level">&#9198;</button>
    <button type="button" id="tbPlayBtn" class="tb-ctrl-play" data-cmd="toggle_play" title="Start / stop (Space)" aria-label="Start or stop">&#9654;</button>
    <button type="button" data-cmd="skip_next" title="Next level (Right)" aria-label="Next level">&#9197;</button>
    <span class="tb-ctrl-sep"></span>
    <button type="button" data-cmd="sub_time" title="Remove one minute" aria-label="Remove one minute">&#8722;1m</button>
    <button type="button" data-cmd="add_time" title="Add one minute" aria-label="Add one minute">&#43;1m</button>
    <span class="tb-ctrl-sep"></span>
    <button type="button" data-cmd="reset_level" title="Reset this level" aria-label="Reset level">&#8635;</button>
    <button type="button" data-cmd="undo" title="Undo last action" aria-label="Undo">&#8630;</button>
    <span class="tb-ctrl-sep"></span>
    <button type="button" data-act="fullscreen" title="Fullscreen" aria-label="Fullscreen">&#9974;</button>
</div>
<?php endif; ?>

<script nonce="<?= csp_nonce() ?>">
var TB_SESSION_ID  = <?= json_encode($session_id ?: null) ?>;
var TB_EVENT_TITLE = <?= json_encode($event_title) ?>;
var TB_EMBED       = <?= json_encode($is_embed) ?>;
</script>
<script src="/timer_beta.js?v=<?= htmlspecialchars(APP_VERSION . '.' . (@filemtime(__DIR__ . '/timer_beta.js') ?: 0)) ?>"></script>
</body>
</html>
