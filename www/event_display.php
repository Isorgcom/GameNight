<?php
/**
 * Per-event Timer Display page (tournaments only): pick which Timer BETA
 * layout THIS game's display shows (stored in timer_state.layout_id — the
 * live display follows the choice within a poll), plus the full layout
 * editor below for building or tweaking layouts without leaving the event.
 * Companion page: event_blinds.php, swapped via _event_setup_strip.php.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/_poker_helpers.php';

$current = require_login();
$isAdmin = $current['role'] === 'admin';
$db = get_db();
$site_name = get_setting('site_name', 'Game Night');

$event_id = (int)($_GET['event_id'] ?? 0);
$e = $db->prepare('SELECT id, title FROM events WHERE id = ?');
$e->execute([$event_id]);
$event = $e->fetch();
if (!$event) {
    http_response_code(404);
    $eventTitle = ''; $denyReason = 'missing';
    require __DIR__ . '/_event_denied.php';
    exit;
}
if (!can_manage_event($db, $event_id, (int)$current['id'], $isAdmin)) {
    http_response_code(403);
    $eventTitle = $event['title']; $denyReason = 'no_rights';
    require __DIR__ . '/_event_denied.php';
    exit;
}
$event_title = (string)$event['title'];

$s = $db->prepare('SELECT id, game_type FROM poker_sessions WHERE event_id = ?');
$s->execute([$event_id]);
$session = $s->fetch();
$is_tournament = $session && ($session['game_type'] ?? '') === 'tournament';

$layout_id = null;
$layout_key = null;
if ($is_tournament) {
    $t = $db->prepare('SELECT layout_id, layout_builtin FROM timer_state WHERE session_id = ?');
    $t->execute([(int)$session['id']]);
    $trow = $t->fetch();
    $layout_id = (int)($trow['layout_id'] ?? 0) ?: null;
    $layout_key = ($trow['layout_builtin'] ?? null) ?: null;
}
$csrf = csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Timer Display &mdash; <?= htmlspecialchars($event_title) ?> &mdash; <?= htmlspecialchars($site_name) ?></title>
    <link rel="stylesheet" href="/style.css?v=<?= htmlspecialchars(APP_VERSION . '.' . (@filemtime(__DIR__ . '/style.css') ?: 0)) ?>">
    <link rel="stylesheet" href="/event_setup.css?v=<?= htmlspecialchars(APP_VERSION . '.' . (@filemtime(__DIR__ . '/event_setup.css') ?: 0)) ?>">
    <link rel="stylesheet" href="/timer_beta_edit.css?v=<?= htmlspecialchars(APP_VERSION . '.' . (@filemtime(__DIR__ . '/timer_beta_edit.css') ?: 0)) ?>">
</head>
<body class="<?= $is_tournament ? 'tbe-body' : '' ?>">
<?php $nav_user = $current; require __DIR__ . '/_nav.php'; ?>

<div class="es-wrap es-wide">
    <?php $es_active = 'display'; require __DIR__ . '/_event_setup_strip.php'; ?>

<?php if (!$session): ?>
    <div class="es-card"><p class="es-note">This event has no game session yet &mdash; set the game up from the event page first.</p></div>
<?php elseif (!$is_tournament): ?>
    <div class="es-card"><p class="es-note">This is a cash game &mdash; blind schedules and the tournament timer apply to tournament games only.</p></div>
<?php endif; ?>
</div>

<?php if ($is_tournament): ?>
<?php /* The layout binding lives in the editor header ("Use for this event",
         driven by the ES_* globals below) — no separate chooser bar. */ ?>
<?php require __DIR__ . '/_timer_beta_editor.php'; ?>

<script nonce="<?= csp_nonce() ?>">
var ES_CSRF = <?= json_encode($csrf) ?>;
var ES_EVENT_ID = <?= (int)$event_id ?>;
var ES_LAYOUT_ID = <?= json_encode($layout_id) ?>;
var ES_LAYOUT_KEY = <?= json_encode($layout_key) ?>;
</script>
<?php endif; ?>
<script src="/event_setup.js?v=<?= htmlspecialchars(APP_VERSION . '.' . (@filemtime(__DIR__ . '/event_setup.js') ?: 0)) ?>" defer></script>

<?php require __DIR__ . '/_footer.php'; ?>
</body>
</html>
