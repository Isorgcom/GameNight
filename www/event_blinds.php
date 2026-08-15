<?php
/**
 * Per-event blind level editor (tournaments only).
 *
 * Edits are copy-on-write: saving writes a schedule owned by THIS game
 * (blind_presets.session_id), never a library preset — loading a preset only
 * fills the grid, and "Save to library" is the explicit publish path. The
 * companion page is event_display.php; _event_setup_strip.php swaps between
 * them in the house segmented-control style.
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

// Current schedule: the timer's preset levels; falls back to the site default
// preset as a starting grid (shown, not yet saved to this event).
$levels = [];
$is_local = false;
$layout_id = null;
if ($is_tournament) {
    $t = $db->prepare('SELECT preset_id, current_level, is_running, layout_id, use_beta FROM timer_state WHERE session_id = ?');
    $t->execute([(int)$session['id']]);
    $timer = $t->fetch();
    $preset_id = $timer ? (int)$timer['preset_id'] : 0;
    $layout_id = $timer ? ((int)($timer['layout_id'] ?? 0) ?: null) : null;
    if (!$preset_id) {
        $d = $db->prepare('SELECT id FROM blind_presets WHERE is_default = 1 LIMIT 1');
        $d->execute();
        $preset_id = (int)($d->fetchColumn() ?: 0);
    } else {
        $pq = $db->prepare('SELECT session_id FROM blind_presets WHERE id = ?');
        $pq->execute([$preset_id]);
        $is_local = (int)($pq->fetchColumn() ?: 0) === (int)$session['id'];
    }
    if ($preset_id) {
        $lq = $db->prepare('SELECT level_number, small_blind, big_blind, ante, duration_minutes, is_break FROM blind_preset_levels WHERE preset_id = ? ORDER BY level_number');
        $lq->execute([$preset_id]);
        $levels = $lq->fetchAll(PDO::FETCH_ASSOC);
    }
    $current_level = $timer ? (int)$timer['current_level'] : 0;
    $timer_running = $timer ? (int)$timer['is_running'] : 0;
} else {
    $current_level = 0; $timer_running = 0;
}
$csrf = csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Blind Levels &mdash; <?= htmlspecialchars($event_title) ?> &mdash; <?= htmlspecialchars($site_name) ?></title>
    <link rel="stylesheet" href="/style.css?v=<?= htmlspecialchars(APP_VERSION . '.' . (@filemtime(__DIR__ . '/style.css') ?: 0)) ?>">
    <link rel="stylesheet" href="/event_setup.css?v=<?= htmlspecialchars(APP_VERSION . '.' . (@filemtime(__DIR__ . '/event_setup.css') ?: 0)) ?>">
</head>
<body>
<?php $nav_user = $current; require __DIR__ . '/_nav.php'; ?>

<div class="es-wrap">
    <?php $es_active = 'blinds'; require __DIR__ . '/_event_setup_strip.php'; ?>

<?php if (!$session): ?>
    <div class="es-card"><p class="es-note">This event has no game session yet &mdash; set the game up from the event page first.</p></div>
<?php elseif (!$is_tournament): ?>
    <div class="es-card"><p class="es-note">This is a cash game &mdash; blind schedules and the tournament timer apply to tournament games only.</p></div>
<?php else: ?>
    <!-- The editor itself is a mountable component (event_blinds.js) shared
         with the check-in console's Setup → Blinds pane. -->
    <div id="esBlindsRoot"></div>
<?php endif; ?>
</div>

<script nonce="<?= csp_nonce() ?>">
var ES_CSRF = <?= json_encode($csrf) ?>;
var ES_EVENT_ID = <?= (int)$event_id ?>;
var ES_LEVELS = <?= json_encode(array_map(function ($l) {
    // (float) for the money columns — see pk_clean_blind_levels().
    return ['small_blind' => (float)$l['small_blind'], 'big_blind' => (float)$l['big_blind'],
            'ante' => (float)$l['ante'], 'duration_minutes' => (int)$l['duration_minutes'],
            'is_break' => (int)$l['is_break']];
}, $levels)) ?>;
var ES_CURRENT_LEVEL = <?= (int)$current_level ?>;
var ES_IS_LOCAL = <?= json_encode((bool)$is_local) ?>;
var ES_TIMER_RUNNING = <?= json_encode((bool)$timer_running) ?>;
</script>
<script src="/event_setup.js?v=<?= htmlspecialchars(APP_VERSION . '.' . (@filemtime(__DIR__ . '/event_setup.js') ?: 0)) ?>" defer></script>
<?php if ($is_tournament): ?>
<script src="/event_blinds.js?v=<?= htmlspecialchars(APP_VERSION . '.' . (@filemtime(__DIR__ . '/event_blinds.js') ?: 0)) ?>" defer></script>
<?php endif; ?>

<?php require __DIR__ . '/_footer.php'; ?>
</body>
</html>
