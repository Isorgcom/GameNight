<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_poker_helpers.php';

header('Content-Type: application/json');

$db = get_db();
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// ─── Helper: compute live timer state (handles countdown + auto-advance) ──
function compute_live_state($db, $timer) {
    $remaining = (int)$timer['time_remaining_seconds'];
    $level = (int)$timer['current_level'];
    $running = (int)$timer['is_running'];
    $session_id = (int)$timer['session_id'];

    if ($running && $timer['updated_at']) {
        // updated_at is stored as UTC via SQLite datetime('now') — force UTC parsing
        $elapsed = time() - strtotime($timer['updated_at'] . ' UTC');
        $remaining -= $elapsed;

        // Auto-advance levels if time ran out
        if ($remaining <= 0 && $timer['preset_id']) {
            $levels = $db->prepare('SELECT * FROM blind_preset_levels WHERE preset_id = ? ORDER BY level_number');
            $levels->execute([$timer['preset_id']]);
            $allLevels = [];
            foreach ($levels->fetchAll(PDO::FETCH_ASSOC) as $lv) {
                $allLevels[(int)$lv['level_number']] = $lv;
            }

            // Advance through levels until we consume all elapsed time
            while ($remaining <= 0) {
                $nextLevel = $level + 1;
                if (!isset($allLevels[$nextLevel])) {
                    // No more levels — stop timer
                    $running = 0;
                    $remaining = 0;
                    break;
                }
                $level = $nextLevel;
                $remaining += (int)$allLevels[$level]['duration_minutes'] * 60;
            }

            // Persist the auto-advanced state (clamp to 24h max)
            $db->prepare("UPDATE timer_state SET current_level = ?, time_remaining_seconds = ?, is_running = ?, updated_at = datetime('now') WHERE id = ?")
                ->execute([$level, max(0, min(86400, $remaining)), $running, (int)$timer['id']]);
        }
    }

    return [
        'current_level' => $level,
        'time_remaining_seconds' => max(0, $remaining),
        'is_running' => $running,
    ];
}

// ─── Helper: resolve timer from key or session_id ─────────
function resolve_timer($db, $key = null, $session_id = null) {
    if ($key) {
        $ts = $db->prepare('SELECT * FROM timer_state WHERE remote_key = ?');
        $ts->execute([$key]);
    } elseif ($session_id) {
        $ts = $db->prepare('SELECT * FROM timer_state WHERE session_id = ?');
        $ts->execute([$session_id]);
    } else {
        return false;
    }
    return $ts->fetch();
}

// Resolve timer from POST params (session_id or key) and verify access
function resolve_timer_from_post($db, $current, $isAdmin) {
    $session_id = !empty($_POST['session_id']) ? (int)$_POST['session_id'] : null;
    $key = $_POST['key'] ?? null;
    $timer = resolve_timer($db, $key, $session_id);
    if (!$timer) return null;

    // Verify access: event-linked timers check event access, standalone timers check ownership
    if ((int)$timer['session_id'] > 0) {
        $sess = $db->prepare('SELECT event_id FROM poker_sessions WHERE id = ?');
        $sess->execute([$timer['session_id']]);
        $s = $sess->fetch();
        if ($s) verify_event_access($db, (int)$s['event_id'], $current, $isAdmin);
    } else {
        // Standalone timer (session_id <= 0)
        $timer_uid = (int)($timer['user_id'] ?? 0);
        if ($timer_uid === 0) {
            // Guest timer — allow access (verified by session-based session_id)
        } elseif (!$current || (!$isAdmin && $timer_uid !== (int)$current['id'])) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Access denied']);
            exit;
        }
    }
    return $timer;
}

// ─── GET: get_state ───────────────────────────────────────
if ($action === 'get_state') {
    $timer = null;
    if (!empty($_GET['key'])) {
        $timer = resolve_timer($db, $_GET['key']);
    } elseif (!empty($_GET['session_id'])) {
        $current = current_user();
        if (!$current) { http_response_code(401); echo json_encode(['ok' => false]); exit; }
        $timer = resolve_timer($db, null, (int)$_GET['session_id']);
    }

    if (!$timer) {
        echo json_encode(['ok' => false, 'error' => 'Timer not found']);
        exit;
    }

    $session_id = (int)$timer['session_id'];
    $session = null;
    $pool = null;

    $payouts = [];
    $game_type = null;

    if ($session_id > 0) {
        $sess = $db->prepare('SELECT ps.*, e.title as event_title, e.id as event_id FROM poker_sessions ps JOIN events e ON ps.event_id = e.id WHERE ps.id = ?');
        $sess->execute([$session_id]);
        $session = $sess->fetch();
        $pool = calc_pool($db, $session_id);
        if ($session) {
            $game_type = $session['game_type'] ?? null;
            if ($game_type === 'tournament') {
                $payouts = get_payouts($db, $session_id);
            }
        }
    }

    $levels = [];
    if ($timer['preset_id']) {
        $lvl = $db->prepare('SELECT * FROM blind_preset_levels WHERE preset_id = ? ORDER BY level_number');
        $lvl->execute([$timer['preset_id']]);
        $levels = $lvl->fetchAll(PDO::FETCH_ASSOC);
    }

    $live = compute_live_state($db, $timer);

    $can_control = false;
    $current = current_user();
    $timer_uid = (int)($timer['user_id'] ?? 0);
    if ($timer_uid === 0 && $session_id !== null && $session_id <= 0) {
        // Guest timer — anyone can control
        $can_control = true;
    } elseif ($current) {
        $isAdmin = $current['role'] === 'admin';
        if ($session) {
            $can_control = check_event_access($db, (int)$session['event_id'], $current, $isAdmin);
        } elseif ($session_id !== null && $session_id <= 0) {
            $can_control = $isAdmin || $timer_uid === (int)$current['id'];
        }
    }

    echo json_encode([
        'ok' => true,
        'timer' => $live,
        'levels' => $levels,
        'pool' => $pool,
        'payouts' => $payouts,
        'game_type' => $game_type,
        'event_title' => $session ? $session['event_title'] : '',
        'session_status' => $session ? $session['status'] : '',
        'can_control' => $can_control,
        'csrf_token' => $current ? csrf_token() : null,
        'sounds' => [
            'warning_seconds' => (int)($timer['warning_seconds'] ?? 60),
            'alarm_sound' => $timer['alarm_sound'] ?? null,
            'start_sound' => $timer['start_sound'] ?? null,
            'warning_sound' => $timer['warning_sound'] ?? null,
        ],
    ]);
    exit;
}

// ─── Authentication ───────────────────────────────────────
$current = current_user();
$isAdmin = $current ? $current['role'] === 'admin' : false;

// Guest-allowed actions (command, update_levels on guest timers)
$guest_allowed_actions = ['command', 'update_levels'];
if (!$current && !in_array($action, $guest_allowed_actions, true)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not authenticated. Create an account to use this feature.']);
    exit;
}

// ─── POST actions require CSRF ────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'CSRF token invalid']);
        exit;
    }
}

// ─── POST: command (unified control for host AND remote) ──
if ($action === 'command') {
    $cmd = $_POST['cmd'] ?? '';
    $timer = resolve_timer_from_post($db, $current, $isAdmin);
    if (!$timer) {
        echo json_encode(['ok' => false, 'error' => 'Timer not found']);
        exit;
    }
    $session_id = $timer['session_id'] ? (int)$timer['session_id'] : null;

    // Get live state (accounts for elapsed time)
    $live = compute_live_state($db, $timer);
    $remaining = $live['time_remaining_seconds'];
    $level = $live['current_level'];
    $running = $live['is_running'];

    // Load levels
    $levelMap = [];
    if ($timer['preset_id']) {
        $lq = $db->prepare('SELECT * FROM blind_preset_levels WHERE preset_id = ? ORDER BY level_number');
        $lq->execute([$timer['preset_id']]);
        foreach ($lq->fetchAll(PDO::FETCH_ASSOC) as $lv) {
            $levelMap[(int)$lv['level_number']] = $lv;
        }
    }

    switch ($cmd) {
        case 'toggle_play':
            $running = $running ? 0 : 1;
            break;
        case 'skip_next':
            if (isset($levelMap[$level + 1])) {
                $level++;
                $remaining = (int)$levelMap[$level]['duration_minutes'] * 60;
            }
            break;
        case 'skip_prev':
            if ($level > 1 && isset($levelMap[$level - 1])) {
                $level--;
                $remaining = (int)$levelMap[$level]['duration_minutes'] * 60;
            }
            break;
        case 'add_time':
            $remaining += 60;
            break;
        case 'sub_time':
            $remaining = max(0, $remaining - 60);
            break;
        case 'reset_level':
            if (isset($levelMap[$level])) {
                $remaining = (int)$levelMap[$level]['duration_minutes'] * 60;
            }
            break;
        case 'reset_timer':
            $level = 1;
            $running = 0;
            if (isset($levelMap[1])) {
                $remaining = (int)$levelMap[1]['duration_minutes'] * 60;
            } else {
                $remaining = 900;
            }
            break;
        default:
            echo json_encode(['ok' => false, 'error' => 'Unknown command']);
            exit;
    }

    // Safety clamp: never store more than 24 hours
    $remaining = max(0, min(86400, $remaining));
    $db->prepare("UPDATE timer_state SET is_running = ?, current_level = ?, time_remaining_seconds = ?, updated_at = datetime('now') WHERE id = ?")
        ->execute([$running, $level, $remaining, $timer['id']]);

    echo json_encode(['ok' => true]);
    exit;
}

// ─── POST: init_timer ─────────────────────────────────────
if ($action === 'init_timer') {
    $session_id = (int)($_POST['session_id'] ?? 0);
    $sess = $db->prepare('SELECT event_id FROM poker_sessions WHERE id = ?');
    $sess->execute([$session_id]);
    $s = $sess->fetch();
    if (!$s) {
        echo json_encode(['ok' => false, 'error' => 'Session not found']);
        exit;
    }
    verify_event_access($db, (int)$s['event_id'], $current, $isAdmin);

    $existing = $db->prepare('SELECT id FROM timer_state WHERE session_id = ?');
    $existing->execute([$session_id]);
    if ($existing->fetch()) {
        echo json_encode(['ok' => true, 'msg' => 'Timer already exists']);
        exit;
    }

    $preset = $db->prepare('SELECT id FROM blind_presets WHERE is_default = 1 LIMIT 1');
    $preset->execute();
    $defaultPreset = $preset->fetch();
    $preset_id = $defaultPreset ? (int)$defaultPreset['id'] : null;

    $duration = 900;
    if ($preset_id) {
        $lvl = $db->prepare('SELECT duration_minutes FROM blind_preset_levels WHERE preset_id = ? AND level_number = 1');
        $lvl->execute([$preset_id]);
        $firstLvl = $lvl->fetch();
        if ($firstLvl) $duration = (int)$firstLvl['duration_minutes'] * 60;
    }

    $remote_key = bin2hex(random_bytes(8));
    $db->prepare("INSERT INTO timer_state (session_id, preset_id, current_level, time_remaining_seconds, is_running, remote_key, updated_at) VALUES (?, ?, 1, ?, 0, ?, datetime('now'))")
        ->execute([$session_id, $preset_id, $duration, $remote_key]);

    echo json_encode(['ok' => true, 'remote_key' => $remote_key]);
    exit;
}

// ─── GET: get_presets ─────────────────────────────────────
if ($action === 'get_presets') {
    $stmt = $db->prepare(
        'SELECT bp.id, bp.name, bp.is_default, bp.is_global, bp.created_by, bp.league_id, l.name AS league_name
         FROM blind_presets bp
         LEFT JOIN leagues l ON l.id = bp.league_id
         WHERE bp.is_default = 1
            OR bp.is_global  = 1
            OR bp.created_by = ?
            OR bp.league_id IN (SELECT league_id FROM league_members WHERE user_id = ?)
         ORDER BY bp.is_default DESC, bp.is_global DESC, LOWER(bp.name)'
    );
    $stmt->execute([$current['id'], $current['id']]);
    echo json_encode(['ok' => true, 'presets' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

// ─── GET: get_user_leagues ────────────────────────────────
// Returns leagues the current user can save presets to (owner or manager).
if ($action === 'get_user_leagues') {
    $stmt = $db->prepare(
        "SELECT l.id, l.name FROM league_members lm
         JOIN leagues l ON l.id = lm.league_id
         WHERE lm.user_id = ? AND lm.role IN ('owner', 'manager')
         ORDER BY LOWER(l.name)"
    );
    $stmt->execute([$current['id']]);
    echo json_encode(['ok' => true, 'leagues' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

// ─── POST: load_preset ────────────────────────────────────
if ($action === 'load_preset') {
    $timer = resolve_timer_from_post($db, $current, $isAdmin);
    if (!$timer) { echo json_encode(['ok' => false, 'error' => 'Timer not found']); exit; }
    $preset_id = (int)($_POST['preset_id'] ?? 0);

    $p = $db->prepare('SELECT id FROM blind_presets WHERE id = ?');
    $p->execute([$preset_id]);
    if (!$p->fetch()) {
        echo json_encode(['ok' => false, 'error' => 'Preset not found']);
        exit;
    }

    $lvl = $db->prepare('SELECT duration_minutes FROM blind_preset_levels WHERE preset_id = ? AND level_number = 1');
    $lvl->execute([$preset_id]);
    $firstLvl = $lvl->fetch();
    $duration = $firstLvl ? (int)$firstLvl['duration_minutes'] * 60 : 900;

    $db->prepare("UPDATE timer_state SET preset_id = ?, current_level = 1, time_remaining_seconds = ?, is_running = 0, updated_at = datetime('now') WHERE id = ?")
        ->execute([$preset_id, $duration, $timer['id']]);

    echo json_encode(['ok' => true]);
    exit;
}

// ─── POST: save_preset ────────────────────────────────────
if ($action === 'save_preset') {
    $name = trim($_POST['name'] ?? '');
    $levels = json_decode($_POST['levels'] ?? '[]', true);
    $is_global = !empty($_POST['is_global']) ? 1 : 0;
    $req_league_id = (int)($_POST['league_id'] ?? 0) ?: null;

    if (!$name || empty($levels)) {
        echo json_encode(['ok' => false, 'error' => 'Name and levels required']);
        exit;
    }
    // Only admins can save global presets.
    if ($is_global && !$isAdmin) {
        echo json_encode(['ok' => false, 'error' => 'Only admins can save global presets']);
        exit;
    }
    // League scoping: caller must be owner/manager of the league (or admin).
    $league_id = null;
    if ($req_league_id) {
        $role = league_role($req_league_id, (int)$current['id']);
        if (!$isAdmin && !in_array($role, ['owner', 'manager'], true)) {
            echo json_encode(['ok' => false, 'error' => 'You must be an owner or manager of that league.']);
            exit;
        }
        $league_id = $req_league_id;
        $is_global = 0; // league presets are not global
    }

    $db->prepare('INSERT INTO blind_presets (name, created_by, is_global, league_id) VALUES (?, ?, ?, ?)')
       ->execute([$name, $current['id'], $is_global, $league_id]);
    $pid = (int)$db->lastInsertId();

    $ins = $db->prepare('INSERT INTO blind_preset_levels (preset_id, level_number, small_blind, big_blind, ante, duration_minutes, is_break) VALUES (?, ?, ?, ?, ?, ?, ?)');
    foreach ($levels as $lv) {
        $ins->execute([$pid, (int)$lv['level_number'], (int)($lv['small_blind'] ?? 0), (int)($lv['big_blind'] ?? 0), (int)($lv['ante'] ?? 0), (int)($lv['duration_minutes'] ?? 15), (int)($lv['is_break'] ?? 0)]);
    }

    echo json_encode(['ok' => true, 'preset_id' => $pid]);
    exit;
}

// ─── POST: delete_preset ──────────────────────────────────
if ($action === 'delete_preset') {
    $preset_id = (int)($_POST['preset_id'] ?? 0);
    $p = $db->prepare('SELECT * FROM blind_presets WHERE id = ?');
    $p->execute([$preset_id]);
    $preset = $p->fetch();
    if (!$preset) { echo json_encode(['ok' => false, 'error' => 'Not found']); exit; }
    if ((int)$preset['is_default']) { echo json_encode(['ok' => false, 'error' => 'Cannot delete default']); exit; }
    // Global presets can only be deleted by admins.
    if ((int)($preset['is_global'] ?? 0) && !$isAdmin) { echo json_encode(['ok' => false, 'error' => 'Only admins can delete global presets']); exit; }
    // League presets: owner/manager of that league (or admin) can delete.
    $preset_league_id = (int)($preset['league_id'] ?? 0);
    if ($preset_league_id > 0) {
        $role = league_role($preset_league_id, (int)$current['id']);
        if (!$isAdmin && !in_array($role, ['owner', 'manager'], true)) {
            echo json_encode(['ok' => false, 'error' => 'Only league owners or managers can delete this preset.']);
            exit;
        }
    } elseif ((int)$preset['created_by'] !== (int)$current['id'] && !$isAdmin) {
        echo json_encode(['ok' => false, 'error' => 'Access denied']);
        exit;
    }
    $db->prepare('DELETE FROM blind_presets WHERE id = ?')->execute([$preset_id]);
    echo json_encode(['ok' => true]);
    exit;
}

// ─── POST: set_default_preset (admin only) ────────────────
if ($action === 'set_default_preset') {
    if (!$isAdmin) { echo json_encode(['ok' => false, 'error' => 'Admin only']); exit; }
    $preset_id = (int)($_POST['preset_id'] ?? 0);
    $p = $db->prepare('SELECT id FROM blind_presets WHERE id = ?');
    $p->execute([$preset_id]);
    if (!$p->fetch()) { echo json_encode(['ok' => false, 'error' => 'Preset not found']); exit; }
    // Swap default: clear old, set new (also mark new as global so it stays visible if default moves again).
    $db->prepare('UPDATE blind_presets SET is_default = 0 WHERE is_default = 1')->execute();
    $db->prepare('UPDATE blind_presets SET is_default = 1, is_global = 1 WHERE id = ?')->execute([$preset_id]);
    echo json_encode(['ok' => true]);
    exit;
}

// ─── POST: update_levels ──────────────────────────────────
if ($action === 'update_levels') {
    $timer = resolve_timer_from_post($db, $current, $isAdmin);
    if (!$timer) { echo json_encode(['ok' => false, 'error' => 'Timer not found']); exit; }
    $levels = json_decode($_POST['levels'] ?? '[]', true);

    if (!$timer['preset_id']) {
        echo json_encode(['ok' => false, 'error' => 'No preset loaded']);
        exit;
    }

    $preset_id = (int)$timer['preset_id'];
    $created_copy = false;

    $pc = $db->prepare('SELECT is_default, is_global, created_by, league_id FROM blind_presets WHERE id = ?');
    $pc->execute([$preset_id]);
    $presetRow = $pc->fetch();

    if ($presetRow) {
        $is_protected = (int)($presetRow['is_default'] ?? 0) || (int)($presetRow['is_global'] ?? 0);
        $preset_league_id = (int)($presetRow['league_id'] ?? 0);
        $can_edit_league  = false;
        if ($preset_league_id > 0) {
            $role = league_role($preset_league_id, (int)$current['id']);
            $can_edit_league = in_array($role, ['owner', 'manager'], true);
        }

        // Admin can edit anything. League owner/manager can edit their league's preset.
        // Everyone else (including regular members of the league) gets a personal copy.
        if ($is_protected && !$isAdmin) {
            $db->prepare('INSERT INTO blind_presets (name, created_by) VALUES (?, ?)')->execute(['Custom', $current['id']]);
            $preset_id = (int)$db->lastInsertId();
            $db->prepare("UPDATE timer_state SET preset_id = ?, updated_at = datetime('now') WHERE id = ?")->execute([$preset_id, $timer['id']]);
            $created_copy = true;
        } elseif ($preset_league_id > 0 && !$isAdmin && !$can_edit_league) {
            $db->prepare('INSERT INTO blind_presets (name, created_by) VALUES (?, ?)')->execute(['Custom', $current['id']]);
            $preset_id = (int)$db->lastInsertId();
            $db->prepare("UPDATE timer_state SET preset_id = ?, updated_at = datetime('now') WHERE id = ?")->execute([$preset_id, $timer['id']]);
            $created_copy = true;
        }
    }

    $db->prepare('DELETE FROM blind_preset_levels WHERE preset_id = ?')->execute([$preset_id]);
    $ins = $db->prepare('INSERT INTO blind_preset_levels (preset_id, level_number, small_blind, big_blind, ante, duration_minutes, is_break) VALUES (?, ?, ?, ?, ?, ?, ?)');
    foreach ($levels as $lv) {
        $ins->execute([$preset_id, (int)$lv['level_number'], (int)($lv['small_blind'] ?? 0), (int)($lv['big_blind'] ?? 0), (int)($lv['ante'] ?? 0), (int)($lv['duration_minutes'] ?? 15), (int)($lv['is_break'] ?? 0)]);
    }

    echo json_encode(['ok' => true, 'preset_id' => $preset_id, 'created_copy' => $created_copy]);
    exit;
}

// ─── POST: update_sounds ──────────────────────────────────
if ($action === 'update_sounds') {
    $timer = resolve_timer_from_post($db, $current, $isAdmin);
    if (!$timer) { echo json_encode(['ok' => false, 'error' => 'Timer not found']); exit; }

    $warning_seconds = isset($_POST['warning_seconds']) ? max(0, (int)$_POST['warning_seconds']) : 60;
    $alarm_sound = $_POST['alarm_sound'] ?? null;
    $start_sound = $_POST['start_sound'] ?? null;
    $warning_sound = $_POST['warning_sound'] ?? null;

    $db->prepare("UPDATE timer_state SET warning_seconds = ?, alarm_sound = ?, start_sound = ?, warning_sound = ? WHERE id = ?")
        ->execute([$warning_seconds, $alarm_sound, $start_sound, $warning_sound, $timer['id']]);

    echo json_encode(['ok' => true]);
    exit;
}

// ─── POST: upload_sound ───────────────────────────────────
if ($action === 'upload_sound') {
    $timer = resolve_timer_from_post($db, $current, $isAdmin);
    if (!$timer) { echo json_encode(['ok' => false, 'error' => 'Timer not found']); exit; }

    $file = $_FILES['sound'] ?? null;
    if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['ok' => false, 'error' => 'No file uploaded']);
        exit;
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    $allowed = [
        'audio/mpeg' => 'mp3',
        'audio/mp4' => 'm4a',
        'audio/x-m4a' => 'm4a',
        'audio/wav' => 'wav',
        'audio/x-wav' => 'wav',
        'audio/ogg' => 'ogg',
        'audio/webm' => 'webm',
        'audio/aac' => 'aac',
    ];
    if (!isset($allowed[$mime])) {
        echo json_encode(['ok' => false, 'error' => 'Only MP3, M4A, WAV, OGG, WebM, AAC audio files allowed']);
        exit;
    }
    if ($file['size'] > 5 * 1024 * 1024) {
        echo json_encode(['ok' => false, 'error' => 'File too large (max 5 MB)']);
        exit;
    }

    $uploadDir = __DIR__ . '/uploads/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
    $name = 'alarm_' . bin2hex(random_bytes(8)) . '.' . $allowed[$mime];
    if (!move_uploaded_file($file['tmp_name'], $uploadDir . $name)) {
        echo json_encode(['ok' => false, 'error' => 'Failed to save file']);
        exit;
    }

    echo json_encode(['ok' => true, 'url' => '/uploads/' . $name]);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Unknown action']);
