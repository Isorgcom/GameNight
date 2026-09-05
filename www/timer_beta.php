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
$isAdmin = $current && $current['role'] === 'admin';

// ─── Key mode: a second screen, opened by scanning the display's QR ─────────
// Authorised by possessing the unguessable remote_key, exactly as
// timer_dl.php's ?key path and timer.php's remote view already are. The key
// buys VIEWING only: get_state computes can_control from the logged-in user,
// and every command re-checks can_manage_event server-side (see
// resolve_timer_from_post), so scanning while logged in as the host gives you
// the controls and scanning as anyone else gives you a viewer.
$remote_key = isset($_GET['key']) ? trim((string)$_GET['key']) : '';
$key_timer = null;
if ($remote_key !== '') {
    $kq = $db->prepare('SELECT id, session_id FROM timer_state WHERE remote_key = ?');
    $kq->execute([$remote_key]);
    $key_timer = $kq->fetch();
    if (!$key_timer) {
        http_response_code(404);
        exit('<!doctype html><meta charset="utf-8"><title>Invalid link</title><body style="background:#0f172a;color:#e2e8f0;font-family:sans-serif;text-align:center;padding-top:4rem"><h1>That timer link is not valid</h1><p>Ask the host to show the QR code again.</p></body>');
    }
}
if (!$current && !$key_timer) { header('Location: /login.php?redirect=' . urlencode($_SERVER['REQUEST_URI'])); exit; }
$site_name = get_setting('site_name', 'Game Night');

$event_id = (int)($_GET['event_id'] ?? 0);
$session_id = 0;
$event_title = '';
// Embed mode: the layout editor loads this page in an iframe and drives the
// renderer through window.TBPreview. Sample state, no polling, no corner bar.
$is_embed = isset($_GET['embed']) && $_GET['embed'] === '1';
if ($is_embed) { $event_id = 0; csp_allow_same_origin_framing(); }

// A key names the session directly; no event id and no event-access check.
if ($key_timer) {
    $session_id = (int)($key_timer['session_id'] ?? 0);
    if ($session_id) {
        $eq = $db->prepare('SELECT e.id, e.title FROM poker_sessions ps JOIN events e ON e.id = ps.event_id WHERE ps.id = ?');
        $eq->execute([$session_id]);
        if ($erow = $eq->fetch()) { $event_id = (int)$erow['id']; $event_title = (string)$erow['title']; }
    }
} elseif ($event_id) {
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
// The remote_key a QR cell encodes so another screen can join. Same key the
// classic timer's remote QR uses; it authorises viewing, never control.
$cast_key = '';
if ($session_id) {
    $ck = $db->prepare('SELECT remote_key FROM timer_state WHERE session_id = ?');
    $ck->execute([$session_id]);
    $cast_key = (string)($ck->fetchColumn() ?: '');
}

// The event's chosen layout (event_display.php stores it on the timer row).
// Injected so the first paint uses it; get_state carries it thereafter so the
// display follows a change without a reload.
$event_layout_id = null;
$event_layout_key = null;
if ($session_id) {
    $lq = $db->prepare('SELECT layout_id, layout_builtin FROM timer_state WHERE session_id = ?');
    $lq->execute([$session_id]);
    $lrow = $lq->fetch();
    $event_layout_id = (int)($lrow['layout_id'] ?? 0) ?: null;
    $event_layout_key = ($lrow['layout_builtin'] ?? null) ?: null;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <?php /* iOS has no element fullscreen — Safari implements it for video and
             nothing else — so the only way to a chrome-free display on an iPad
             is Add to Home Screen, which needs these to launch standalone. The
             saved icon keeps the URL it was added from, key and all, so a
             dedicated display device reopens THIS timer straight into it. */ ?>
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Timer">
    <?php /* The icon iOS puts on the home screen when someone adds this page
             by hand; without it Safari uses a screenshot of the page. */ ?>
    <link rel="apple-touch-icon" href="/img/app-icon-192.png">
    <title>Timer BETA &mdash; <?= htmlspecialchars($site_name) ?></title>
    <link rel="stylesheet" href="/fonts.css?v=<?= htmlspecialchars(APP_VERSION . '.' . (@filemtime(__DIR__ . '/fonts.css') ?: 0)) ?>">
    <link rel="stylesheet" href="/timer_beta.css?v=<?= htmlspecialchars(APP_VERSION . '.' . (@filemtime(__DIR__ . '/timer_beta.css') ?: 0)) ?>">
</head>
<body>

<div id="tbRoot" aria-live="off"></div>

<?php /* Sample/library mode only. A scanned screen never gets the bar (the
         picker fetches a list the viewer has no rights to, and a cast display
         should not offer a stranger a dropdown), and a LIVE game doesn't
         either: the layout is chosen in the game's Setup, and the running
         board is a clean TV face — no picker, no BETA badge, no chrome. */ ?>
<?php if (!$is_embed && $remote_key === '' && !$session_id): ?>
<div id="tbBar">
    <span class="tb-badge">BETA</span>
    <select id="tbLayoutSelect" title="Layout"></select>
    <span class="tb-sample" title="No game linked — showing sample data">sample</span>
</div>
<?php endif; ?>

<?php if (!$is_embed): ?>
<!-- Fullscreen, for EVERY viewer. The tray's copy of this only appears once
     get_state confirms control rights, so a screen opened by scanning the QR
     code — which is the whole point of the QR cell, and is usually a device
     with no rights at all — had no way to go fullscreen. Auto-hides like the
     tray, and removes itself entirely when there is no browser chrome to
     escape (already fullscreen, or launched from the home screen). -->
<button type="button" id="tbFsBtn" data-act="fullscreen" title="Fullscreen" aria-label="Fullscreen">&#9974;</button>
<?php /* Speaker toggle: trigger sounds and announcements obey it. A key-opened
         screen is someone's phone and starts muted; the choice persists per
         display. Fades with the tray like the fullscreen button. */ ?>
<button type="button" id="tbSndBtn" title="Sounds on/off" aria-label="Sounds on or off">&#128266;</button>
<div id="tbFsHint" hidden></div>
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
// The key this screen was opened with (if any), and the one a QR cell should
// encode. Read from the timer row rather than echoed back from the URL, so a
// screen opened by event_id can still show a QR — and so nothing user-supplied
// is ever reflected into the page.
var TB_KEY      = <?= json_encode($remote_key !== '' ? $remote_key : null) ?>;
// The build stamp this page booted with; get_state carries the current one.
var TB_ASSET_V  = <?= (int) (@filemtime(__DIR__ . '/timer_beta.js') ?: 0) ?>;
var TB_CAST_KEY = <?= json_encode($cast_key ?: null) ?>;
var TB_EVENT_TITLE = <?= json_encode($event_title) ?>;
var TB_EMBED       = <?= json_encode($is_embed) ?>;
var TB_EVENT_LAYOUT_ID = <?= json_encode($event_layout_id) ?>;
// Admin-configured extra stream hosts for video cells (mirrors the CSP
// frame-src allowlist built in auth.php, same as the classic timer's page).
var TB_STREAM_HOSTS = <?= json_encode(stream_allowed_hosts()) ?>;
var TB_EVENT_LAYOUT_KEY = <?= json_encode($event_layout_key) ?>;
</script>
<?php if (!$is_embed): ?>
<?php /* Keep-awake, same pair the classic timer uses: navigator.wakeLock where
         it exists, and NoSleep.js (a hidden silent video) for iPhone Safari,
         which has no Wake Lock API over plain HTTP at all. Skipped in embed
         mode — the editor preview is an iframe, not a screen anyone watches. */ ?>
<div id="tbWakeBanner">Tap anywhere to keep this screen awake</div>
<script src="/vendor/nosleep.min.js"></script>
<?php endif; ?>
<script src="/vendor/qrcode.min.js"></script>
<!-- hls.js before the renderer and WITHOUT defer, for the same reason pk-seg.js
     is: buildCell() calls attachHls() as soon as the layout fetch resolves, and
     a deferred library would be a race it loses intermittently. Vendored, so
     this is script-src 'self'. Absent (download failed) the renderer falls back
     to a native src, which still plays on Safari and iOS. -->
<script src="/vendor/hls.min.js?v=<?= htmlspecialchars(APP_VERSION . '.' . (@filemtime(__DIR__ . '/vendor/hls.min.js') ?: 0)) ?>"></script>
<script src="/timer_beta.js?v=<?= htmlspecialchars(APP_VERSION . '.' . (@filemtime(__DIR__ . '/timer_beta.js') ?: 0)) ?>"></script>
</body>
</html>
