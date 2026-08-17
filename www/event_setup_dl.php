<?php
/**
 * Data endpoint for the per-event tournament setup pages
 * (event_blinds.php + event_display.php). POST-only, JSON out.
 *
 * Blind schedules are copy-on-write: save_blinds never touches a library
 * preset. If this game's timer already points at its own session-local copy
 * (blind_presets.session_id = this session) the copy is updated in place;
 * otherwise a new session-local copy is created and the timer repointed.
 * Library presets are read through timer_dl.php (get_presets) and written
 * through its save_preset — this file only ever writes session-local rows.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/_poker_helpers.php';

header('Content-Type: application/json');
$current = current_user();
if (!$current) { http_response_code(401); echo json_encode(['ok' => false, 'error' => 'Not authenticated']); exit; }
$isAdmin = $current['role'] === 'admin';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok' => false, 'error' => 'POST only']); exit; }
if (!csrf_verify()) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'CSRF token invalid']); exit; }

$db = get_db();
$action = $_POST['action'] ?? '';
$event_id = (int)($_POST['event_id'] ?? 0);

// Both actions manage a game, so both need manage rights (exits on failure).
verify_event_access($db, $event_id, $current, $isAdmin);

$s = $db->prepare('SELECT id, game_type, status FROM poker_sessions WHERE event_id = ?');
$s->execute([$event_id]);
$session = $s->fetch();
if (!$session) { echo json_encode(['ok' => false, 'error' => 'This event has no game session']); exit; }
if (($session['game_type'] ?? '') !== 'tournament') { echo json_encode(['ok' => false, 'error' => 'Only tournaments have blind schedules and timer displays']); exit; }
$session_id = (int)$session['id'];

$t = $db->prepare('SELECT * FROM timer_state WHERE session_id = ?');
$t->execute([$session_id]);
$timer = $t->fetch();

// Timer-row creation and the copy-on-write schedule writer live in
// _poker_helpers.php (pk_ensure_timer_row / pk_apply_event_blinds), shared
// with checkin_dl.php's game presets.

// Current schedule + timer facts, for mounting the blind editor dynamically
// (the check-in Setup pane). Falls back to the site default preset's levels
// as a starting grid, same as event_blinds.php does server-side.
if ($action === 'get_blinds') {
    $preset_id = $timer ? (int)$timer['preset_id'] : 0;
    $is_local = false;
    if (!$preset_id) {
        $d = $db->prepare('SELECT id FROM blind_presets WHERE is_default = 1 LIMIT 1');
        $d->execute();
        $preset_id = (int)($d->fetchColumn() ?: 0);
    } else {
        $pq = $db->prepare('SELECT session_id FROM blind_presets WHERE id = ?');
        $pq->execute([$preset_id]);
        $is_local = (int)($pq->fetchColumn() ?: 0) === $session_id;
    }
    $levels = [];
    if ($preset_id) {
        $lq = $db->prepare('SELECT level_number, small_blind, big_blind, ante, duration_minutes, is_break FROM blind_preset_levels WHERE preset_id = ? ORDER BY level_number');
        $lq->execute([$preset_id]);
        $levels = $lq->fetchAll(PDO::FETCH_ASSOC);
    }
    echo json_encode(['ok' => true, 'levels' => $levels, 'is_local' => $is_local,
        'current_level' => $timer ? (int)$timer['current_level'] : 0,
        'is_running' => $timer ? (int)$timer['is_running'] : 0,
        'use_beta' => $timer ? (int)($timer['use_beta'] ?? 0) : 0]);
    exit;
}

// Switch this game's Timer button between the classic timer and the BETA
// layout display. timer.php honours it with a redirect (?classic=1 escapes).
// One-time Timer BETA ask from the check-in console. Stores the user's answer
// either way (so it is never asked again), and on yes flips THIS game's
// display too, so the choice takes effect right where it was made. Future
// games follow the stored preference when their timer row is created.
if ($action === 'beta_timer_pref') {
    $val = (int)($_POST['value'] ?? 0) === 1 ? 1 : 0;
    $db->prepare('UPDATE users SET beta_timer = ? WHERE id = ?')->execute([$val, (int)$current['id']]);
    if ($val === 1) {
        if (!$timer) $timer = pk_ensure_timer_row($db, $session_id, 1);
        $db->prepare("UPDATE timer_state SET use_beta = 1, updated_at = datetime('now') WHERE id = ?")
           ->execute([(int)$timer['id']]);
    }
    db_log_activity($current['id'], 'answered BETA timer opt-in: ' . ($val ? 'yes' : 'no'));
    echo json_encode(['ok' => true, 'beta_timer' => $val]);
    exit;
}

if ($action === 'set_beta') {
    $on = !empty($_POST['on']) ? 1 : 0;
    if (!$timer) $timer = pk_ensure_timer_row($db, $session_id, $on);
    $db->prepare("UPDATE timer_state SET use_beta = ?, updated_at = datetime('now') WHERE id = ?")
       ->execute([$on, (int)$timer['id']]);
    db_log_activity($current['id'], "set BETA timer " . ($on ? 'on' : 'off') . ": event #$event_id");
    echo json_encode(['ok' => true, 'use_beta' => $on]);
    exit;
}

if ($action === 'save_blinds') {
    $levels = json_decode($_POST['levels'] ?? '[]', true);
    $clean = pk_clean_blind_levels($levels);
    if (!$clean) { echo json_encode(['ok' => false, 'error' => 'At least one level is required']); exit; }

    $res = pk_apply_event_blinds($db, $session_id, $event_id, $clean, (int)$current['id']);

    db_log_activity($current['id'], "saved event blind schedule: event #$event_id (" . count($clean) . ' levels)');
    echo json_encode(['ok' => true, 'preset_id' => $res['preset_id'], 'levels' => $clean, 'created_copy' => $res['created_copy']]);
    exit;
}

// Read a library preset's levels WITHOUT applying them — the blind editor
// loads them into the grid, and nothing changes until the host saves (which
// writes the event's own copy). Library visibility scope, same as get_presets.
if ($action === 'get_preset_levels') {
    $preset_id = (int)($_POST['preset_id'] ?? 0);
    $p = $db->prepare('SELECT id, name FROM blind_presets WHERE id = ? AND session_id IS NULL AND (is_default = 1
            OR is_global = 1 OR created_by = ?
            OR league_id IN (SELECT league_id FROM league_members WHERE user_id = ?))');
    $p->execute([$preset_id, (int)$current['id'], (int)$current['id']]);
    $row = $p->fetch();
    if (!$row) { echo json_encode(['ok' => false, 'error' => 'Preset not found']); exit; }
    $lvl = $db->prepare('SELECT level_number, small_blind, big_blind, ante, duration_minutes, is_break FROM blind_preset_levels WHERE preset_id = ? ORDER BY level_number');
    $lvl->execute([$preset_id]);
    echo json_encode(['ok' => true, 'name' => $row['name'], 'levels' => $lvl->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

if ($action === 'set_layout') {
    $layout_id = (int)($_POST['layout_id'] ?? 0);
    // Built-ins aren't library rows; binding one stores its key instead.
    $builtin = trim((string)($_POST['builtin'] ?? ''));
    if ($builtin !== '' && !pk_is_timer_builtin($builtin)) {
        echo json_encode(['ok' => false, 'error' => 'Unknown built-in layout']); exit;
    }
    if ($builtin !== '') $layout_id = 0;
    if ($layout_id) {
        // Same visibility rule as timer_beta_dl.php's get_layout.
        $q = $db->prepare('SELECT id FROM timer_layouts WHERE id = ? AND (is_global = 1 OR created_by = ?
                OR league_id IN (SELECT league_id FROM league_members WHERE user_id = ?))');
        $q->execute([$layout_id, (int)$current['id'], (int)$current['id']]);
        if (!$q->fetch()) { echo json_encode(['ok' => false, 'error' => 'Layout not found']); exit; }
    }
    if (!$timer) $timer = pk_ensure_timer_row($db, $session_id, pk_user_beta_pref($db, (int)$current['id']));
    $db->prepare("UPDATE timer_state SET layout_id = ?, layout_builtin = ?, updated_at = datetime('now') WHERE id = ?")
       ->execute([$layout_id ?: null, $builtin !== '' ? $builtin : null, (int)$timer['id']]);
    db_log_activity($current['id'], "set event timer layout: event #$event_id → " . ($builtin !== '' ? $builtin : ($layout_id ?: 'none')));
    echo json_encode(['ok' => true]);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Unknown action']);
