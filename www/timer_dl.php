<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_poker_helpers.php';
require_once __DIR__ . '/_timer_theme.php';

header('Content-Type: application/json');

$db = get_db();
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// ─── Helper: compute live timer state (handles countdown + auto-advance) ──
function compute_live_state($db, $timer) {
    $remaining = (int)$timer['time_remaining_seconds'];
    $level = (int)$timer['current_level'];
    $running = (int)$timer['is_running'];
    $session_id = (int)$timer['session_id'];

    // ONE reading of the clock for the whole request. Two calls to time() can
    // straddle a second boundary, and the anchor below is derived from it: two
    // screens polling either side of that boundary latched deadlines a full
    // second apart and then held them, so they disagreed forever.
    $now = time();

    if ($running && $timer['updated_at']) {
        // updated_at is stored as UTC via SQLite datetime('now') — force UTC parsing
        $elapsed = $now - strtotime($timer['updated_at'] . ' UTC');
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

    // ── Anchor ────────────────────────────────────────────────────────────
    // The countdown expressed as a CONSTANT, so a display can derive the time
    // itself instead of being told it again every poll. Being told is what made
    // the clock stutter: this function answers in whole seconds computed at
    // request time, so consecutive polls disagree by up to a second and the
    // display jumped backwards and forwards.
    //
    // ends_at is invariant between commands, which is the whole point:
    //   ends_at = time() + remaining
    //           = time() + (stored_remaining - (time() - updated_at))
    //           = updated_at + stored_remaining
    // The request time cancels out, so every poll — and every SCREEN — derives
    // the same instant. Auto-advance above rewrites the row and is therefore
    // picked up here automatically, since $remaining is already re-based.
    $ends_at_ms = ($running && $remaining > 0) ? ($now + $remaining) * 1000 : null;

    return [
        'current_level' => $level,
        'time_remaining_seconds' => max(0, $remaining),
        'is_running' => $running,
        // Running: count down to ends_at_ms. Paused: remaining_ms is the truth
        // and nothing moves. Exactly one of the two is ever non-null.
        'ends_at_ms'   => $ends_at_ms,
        'remaining_ms' => $running ? null : max(0, $remaining) * 1000,
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
        if ($s) {
            verify_event_access($db, (int)$s['event_id'], $current, $isAdmin);
            // Mutating a timer (commands, presets, sounds, themes) requires manage
            // rights on the event. Event access alone — an invitee, or anyone who
            // scanned the table QR while logged in — is view-only.
            if (!$current || !can_manage_event($db, (int)$s['event_id'], (int)$current['id'], $isAdmin)) {
                http_response_code(403);
                echo json_encode(['ok' => false, 'error' => 'Only the event host can control this timer']);
                exit;
            }
        }
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
    // The ?key path is authorized by possessing the unguessable remote_key. The
    // ?session_id path is IDOR-prone (sequential ids), so it additionally requires
    // event access — enforced below once the session's event is known.
    $enforce_event_access = false;
    if (!empty($_GET['key'])) {
        $timer = resolve_timer($db, $_GET['key']);
    } elseif (!empty($_GET['session_id'])) {
        $current = current_user();
        if (!$current) { http_response_code(401); echo json_encode(['ok' => false]); exit; }
        $timer = resolve_timer($db, null, (int)$_GET['session_id']);
        $enforce_event_access = true;
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
        $sess = $db->prepare('SELECT ps.*, e.title as event_title, e.id as event_id,
                                     e.start_date as ev_date, e.start_time as ev_time
                              FROM poker_sessions ps JOIN events e ON ps.event_id = e.id WHERE ps.id = ?');
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

    // IDOR guard for the ?session_id path: only return this event's timer/pool/
    // payout data to someone who may access the event (the ?key path is exempt —
    // the remote_key itself is the authorization).
    if ($enforce_event_access) {
        $cur = current_user();
        $isAdmin = $cur && $cur['role'] === 'admin';
        if (!$session || !check_event_access($db, (int)$session['event_id'], $cur, $isAdmin)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Forbidden']);
            exit;
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
            // View is granted by the key or event access; control requires manage
            // rights so the table QR can't be used to pause/skip the clock.
            $can_control = can_manage_event($db, (int)$session['event_id'], (int)$current['id'], $isAdmin);
        } elseif ($session_id !== null && $session_id <= 0) {
            $can_control = $isAdmin || $timer_uid === (int)$current['id'];
        }
    }

    $chip_set = [];
    if ($session_id > 0) {
        $cq = $db->prepare('SELECT chip_set FROM poker_sessions WHERE id = ?');
        $cq->execute([$session_id]);
        $chip_set = pk_clean_chip_set($cq->fetchColumn() ?: '');
    }

    $players = [];
    if ($session_id > 0 && $game_type === 'tournament') {
        // The exact "still in" predicate calc_pool uses. Walk-ins have
        // user_id NULL, so the join leaves their avatar null and the display
        // falls back to initials.
        $pq = $db->prepare('SELECT pp.display_name, pp.table_number, pp.seat_number, u.avatar_path
                            FROM poker_players pp LEFT JOIN users u ON u.id = pp.user_id
                            WHERE pp.session_id = ? AND pp.removed = 0 AND pp.bought_in = 1 AND pp.eliminated = 0
                            ORDER BY pp.table_number, pp.seat_number LIMIT 30');
        $pq->execute([$session_id]);
        foreach ($pq->fetchAll(PDO::FETCH_ASSOC) as $pr) {
            $av = (string)($pr['avatar_path'] ?? '');
            $players[] = [
                'name'   => (string)$pr['display_name'],
                'table'  => (int)$pr['table_number'],
                'seat'   => (int)$pr['seat_number'],
                'avatar' => preg_match('#^/uploads/avatars/[A-Za-z0-9._/-]{1,160}$#', $av) ? $av : null,
            ];
        }
    }

    // Most recent knockout: lowest finish_position among the eliminated
    // (places count down as players bust, so the last one out holds the
    // smallest number). Feeds the <lastEliminated> element and the
    // playerEliminated trigger edge.
    $lastElim = null;
    if ($session_id > 0 && $game_type === 'tournament') {
        $lq = $db->prepare('SELECT display_name, finish_position FROM poker_players
                            WHERE session_id = ? AND removed = 0 AND eliminated = 1
                            ORDER BY (finish_position IS NULL), finish_position ASC LIMIT 1');
        $lq->execute([$session_id]);
        if ($lr = $lq->fetch(PDO::FETCH_ASSOC)) {
            $lastElim = ['name' => (string)$lr['display_name'],
                         'place' => $lr['finish_position'] !== null ? (int)$lr['finish_position'] : null];
        }
    }

    $themeProps = timer_resolve_theme($db, (int)($timer['theme_id'] ?? 0) ?: null);

    echo json_encode([
        'ok' => true,
        'timer' => $live,
        // The server's own clock, so a display can work out how far its clock
        // is from this one. Without it, deriving from ends_at_ms would show a
        // wrong time forever on any screen whose clock is off — and screens
        // would disagree with each other, which is exactly what a shared
        // anchor is supposed to prevent.
        'server_now_ms' => (int) round(microtime(true) * 1000),
        // The display JS's current build stamp. A timer display sits open for
        // hours polling DATA but never re-fetches CODE, so a fix never reaches
        // an already-open screen; the client compares this against the stamp
        // it booted with and reloads itself when they differ.
        'asset_v' => (int) (@filemtime(__DIR__ . '/timer_beta.js') ?: 0),
        'levels' => $levels,
        // Chip denominations with their colours, for the <chips> legend. Empty
        // when the game has none, so the display hides the cell rather than
        // drawing an empty box.
        'chips' => $chip_set,
        // Still-in players with their seats, for the <seats> final-table cell.
        // This DELIBERATELY widens the ?key channel: a wall display exists to
        // show who is at which seat, so possession of the key now buys names
        // and avatars of players still IN the game — and nothing else. Only
        // still-in rows (never eliminated players, notes, payouts or contact
        // data), avatar paths only from our own avatars folder, capped.
        'players' => $players,
        'last_eliminated' => $lastElim,
        // Setup figures the display can show but cannot derive: the fees, the
        // chips each buy-in grants, the table plan and when the game starts.
        // Named explicitly rather than handing the whole session row to every
        // screen — a display is public to anyone with the key.
        'game' => $session ? [
            'buyin'       => (int)($session['buyin_amount'] ?? 0),
            'rebuy'       => (int)($session['rebuy_amount'] ?? 0),
            'addon'       => (int)($session['addon_amount'] ?? 0),
            'start_chips' => (int)($session['starting_chips'] ?? 0),
            'addon_chips' => (int)($session['addon_chips'] ?? 0),
            'tables'      => (int)($session['num_tables'] ?? 0),
            'seats'       => (int)($session['seats_per_table'] ?? 0),
            'start_date'  => $session['ev_date'] ?? null,
            'start_time'  => $session['ev_time'] ?? null,
        ] : null,
        'pool' => $pool,
        'payouts' => $payouts,
        'game_type' => $game_type,
        'event_title' => $session ? $session['event_title'] : '',
        'session_status' => $session ? $session['status'] : '',
        'can_control' => $can_control,
        'csrf_token' => $current ? csrf_token() : null,
        'layout_id' => (int)($timer['layout_id'] ?? 0) ?: null,
        'layout_builtin' => ($timer['layout_builtin'] ?? null) ?: null,
        'sounds' => [
            'warning_seconds' => (int)($timer['warning_seconds'] ?? 60),
            'alarm_sound' => $timer['alarm_sound'] ?? null,
            'start_sound' => $timer['start_sound'] ?? null,
            'warning_sound' => $timer['warning_sound'] ?? null,
        ],
        'theme' => [
            'id' => (int)($timer['theme_id'] ?? 0) ?: null,
            'properties' => $themeProps,
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

    // One-deep undo: snapshot the pre-command state; 'undo' restores it and
    // clears the snapshot (no redo ping-pong). Snapshots expire after 10 min.
    $prev_state_json = json_encode(['l' => $level, 'r' => $remaining, 'run' => $running, 'ts' => time()]);

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
        case 'undo':
            $prev = json_decode((string)($timer['prev_state'] ?? ''), true);
            if (!is_array($prev) || (time() - (int)($prev['ts'] ?? 0)) > 600) {
                echo json_encode(['ok' => false, 'error' => 'Nothing to undo.']);
                exit;
            }
            $level     = max(1, (int)($prev['l'] ?? 1));
            $remaining = max(0, (int)($prev['r'] ?? 0));
            $running   = (int)($prev['run'] ?? 0) ? 1 : 0;
            $prev_state_json = null; // consumed
            break;
        default:
            echo json_encode(['ok' => false, 'error' => 'Unknown command']);
            exit;
    }

    // Safety clamp: never store more than 24 hours
    $remaining = max(0, min(86400, $remaining));
    $db->prepare("UPDATE timer_state SET is_running = ?, current_level = ?, time_remaining_seconds = ?, prev_state = ?, updated_at = datetime('now') WHERE id = ?")
        ->execute([$running, $level, $remaining, $prev_state_json, $timer['id']]);

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
         WHERE bp.session_id IS NULL
           AND (bp.is_default = 1
            OR bp.is_global  = 1
            OR bp.created_by = ?
            OR bp.league_id IN (SELECT league_id FROM league_members WHERE user_id = ?))
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

    // Scoped exactly like get_presets() above, for the same reason as load_theme.
    $p = $db->prepare('SELECT id FROM blind_presets WHERE id = ? AND session_id IS NULL AND (is_default = 1
                OR is_global  = 1
                OR created_by = ?
                OR league_id IN (SELECT league_id FROM league_members WHERE user_id = ?))');
    $p->execute([$preset_id, (int)$current['id'], (int)$current['id']]);
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
        $ins->execute([$pid, (int)$lv['level_number'], (float)($lv['small_blind'] ?? 0), (float)($lv['big_blind'] ?? 0), (float)($lv['ante'] ?? 0), (int)($lv['duration_minutes'] ?? 15), (int)($lv['is_break'] ?? 0)]);
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
    // Session-local copies aren't library presets; they live and die with
    // their game and are edited from the event's blind editor.
    if (!empty($preset['session_id'])) { echo json_encode(['ok' => false, 'error' => 'Not found']); exit; }
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
        // Everyone else (including regular members of the league) gets a copy.
        // Copies are session-local (session_id set): the edit belongs to THIS
        // game, and the copy never appears in anyone's preset library.
        $needs_copy = ($is_protected && !$isAdmin)
            || ($preset_league_id > 0 && !$isAdmin && !$can_edit_league)
            || ((int)($presetRow['created_by'] ?? 0) !== (int)$current['id'] && !$isAdmin);
            // Someone else's personal preset also copies: falling through here
            // did not just overwrite it — the code below DELETEs every level row
            // and re-inserts, so it destroyed another user's saved structure.
            // delete_preset already required ownership; same rule for edits.
        if ($needs_copy) {
            $db->prepare('INSERT INTO blind_presets (name, created_by, session_id) VALUES (?, ?, ?)')
               ->execute(['Custom', $current['id'], (int)$timer['session_id'] ?: null]);
            $preset_id = (int)$db->lastInsertId();
            $db->prepare("UPDATE timer_state SET preset_id = ?, updated_at = datetime('now') WHERE id = ?")->execute([$preset_id, $timer['id']]);
            $created_copy = true;
        }
    }

    $db->prepare('DELETE FROM blind_preset_levels WHERE preset_id = ?')->execute([$preset_id]);
    $ins = $db->prepare('INSERT INTO blind_preset_levels (preset_id, level_number, small_blind, big_blind, ante, duration_minutes, is_break) VALUES (?, ?, ?, ?, ?, ?, ?)');
    foreach ($levels as $lv) {
        $ins->execute([$preset_id, (int)$lv['level_number'], (float)($lv['small_blind'] ?? 0), (float)($lv['big_blind'] ?? 0), (float)($lv['ante'] ?? 0), (int)($lv['duration_minutes'] ?? 15), (int)($lv['is_break'] ?? 0)]);
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

// ─── Theme system ─────────────────────────────────────────
// Mirrors the blind-preset save/load/scope model exactly. Themes are JSON blobs in
// `timer_themes.properties`; timer_state.theme_id points at the active row.

// ─── GET: get_themes ──────────────────────────────────────
if ($action === 'get_themes') {
    $stmt = $db->prepare(
        'SELECT tt.id, tt.name, tt.is_default, tt.is_global, tt.created_by, tt.league_id, l.name AS league_name
         FROM timer_themes tt
         LEFT JOIN leagues l ON l.id = tt.league_id
         WHERE tt.is_default = 1
            OR tt.is_global  = 1
            OR tt.created_by = ?
            OR tt.league_id IN (SELECT league_id FROM league_members WHERE user_id = ?)
         ORDER BY tt.is_default DESC, tt.is_global DESC, LOWER(tt.name)'
    );
    $stmt->execute([$current['id'], $current['id']]);
    echo json_encode(['ok' => true, 'themes' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

// ─── GET: get_theme (read-only fetch, no side effects on timer_state) ──
// Used by the theme-export download flow. Returns name + properties for a
// single theme that the user is allowed to read (default, global, theirs, or
// in a league they belong to).
if ($action === 'get_theme') {
    $theme_id = (int)($_GET['theme_id'] ?? 0);
    if ($theme_id <= 0) { echo json_encode(['ok' => false, 'error' => 'theme_id required']); exit; }
    $stmt = $db->prepare(
        'SELECT id, name, is_default, is_global, league_id, created_by, properties
         FROM timer_themes
         WHERE id = ?
           AND (is_default = 1
                OR is_global  = 1
                OR created_by = ?
                OR league_id IN (SELECT league_id FROM league_members WHERE user_id = ?))'
    );
    $stmt->execute([$theme_id, (int)$current['id'], (int)$current['id']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) { echo json_encode(['ok' => false, 'error' => 'Theme not found']); exit; }
    $row['properties'] = json_decode($row['properties'] ?? '{}', true) ?: [];
    echo json_encode(['ok' => true, 'theme' => $row]);
    exit;
}

// ─── POST: load_theme ─────────────────────────────────────
if ($action === 'load_theme') {
    $timer = resolve_timer_from_post($db, $current, $isAdmin);
    if (!$timer) { echo json_encode(['ok' => false, 'error' => 'Timer not found']); exit; }
    $theme_id = (int)($_POST['theme_id'] ?? 0);

    // Scoped exactly like get_theme() above. Without this any authenticated user
    // could point their own timer at any theme id and read it back — and, because
    // update_theme derives its target from timer_state.theme_id, then overwrite it.
    $t = $db->prepare('SELECT id, properties FROM timer_themes WHERE id = ? AND (is_default = 1
                OR is_global  = 1
                OR created_by = ?
                OR league_id IN (SELECT league_id FROM league_members WHERE user_id = ?))');
    $t->execute([$theme_id, (int)$current['id'], (int)$current['id']]);
    $themeRow = $t->fetch();
    if (!$themeRow) { echo json_encode(['ok' => false, 'error' => 'Theme not found']); exit; }

    $db->prepare("UPDATE timer_state SET theme_id = ?, updated_at = datetime('now') WHERE id = ?")
        ->execute([$theme_id, $timer['id']]);

    $props = json_decode($themeRow['properties'] ?? '{}', true) ?: [];
    $merged = array_replace_recursive(timer_theme_defaults(), $props);
    echo json_encode(['ok' => true, 'theme_id' => $theme_id, 'properties' => $merged]);
    exit;
}


/**
 * Strip anything from a theme blob that could break out of an HTML attribute
 * when the layout inspector renders it. The inspector escapes on output too,
 * but a colour field is never legitimately anything but a colour, so refusing
 * to store the junk is the cheaper guarantee. Applied on every write path.
 */
function pk_theme_sanitize_props($props) {
    if (!is_array($props)) return $props;
    foreach ($props as $k => $v) {
        if (is_array($v)) { $props[$k] = pk_theme_sanitize_props($v); continue; }
        if (!is_string($v)) continue;
        if (preg_match('/(^|_)colou?r$/i', (string)$k)) {
            // #rgb / #rrggbb / #rrggbbaa, rgb()/rgba(), or a bare CSS keyword.
            if (!preg_match('/^#[0-9a-fA-F]{3,8}$/', $v)
                && !preg_match('/^rgba?\(\s*[0-9.,\s%]+\)$/', $v)
                && !preg_match('/^[a-zA-Z]{3,20}$/', $v)) {
                unset($props[$k]);
                continue;
            }
        }
        // Nothing in a theme needs these, and every one of them is a way out of
        // an attribute or into a tag.
        $props[$k] = str_replace(['<', '>', '"', "'"], '', $props[$k] ?? $v);
    }
    return $props;
}

// ─── POST: save_theme (creates a new theme row) ───────────
if ($action === 'save_theme') {
    if (!$current) { echo json_encode(['ok' => false, 'error' => 'Login required']); exit; }
    $name = trim($_POST['name'] ?? '');
    $properties = $_POST['properties'] ?? '';
    $is_global = !empty($_POST['is_global']) ? 1 : 0;
    $req_league_id = (int)($_POST['league_id'] ?? 0) ?: null;

    $props = json_decode($properties, true);
    if (!$name || !is_array($props)) {
        echo json_encode(['ok' => false, 'error' => 'Name and properties required']);
        exit;
    }
    if ($is_global && !$isAdmin) {
        echo json_encode(['ok' => false, 'error' => 'Only admins can save global themes']);
        exit;
    }
    $league_id = null;
    if ($req_league_id) {
        $role = league_role($req_league_id, (int)$current['id']);
        if (!$isAdmin && !in_array($role, ['owner', 'manager'], true)) {
            echo json_encode(['ok' => false, 'error' => 'You must be an owner or manager of that league.']);
            exit;
        }
        $league_id = $req_league_id;
        $is_global = 0;
    }

    $db->prepare('INSERT INTO timer_themes (name, created_by, is_global, league_id, properties) VALUES (?, ?, ?, ?, ?)')
       ->execute([$name, $current['id'], $is_global, $league_id, json_encode(pk_theme_sanitize_props($props))]);
    $tid = (int)$db->lastInsertId();

    // Point the current timer at the newly-saved theme.
    $timer = resolve_timer_from_post($db, $current, $isAdmin);
    if ($timer) {
        $db->prepare("UPDATE timer_state SET theme_id = ?, updated_at = datetime('now') WHERE id = ?")
            ->execute([$tid, $timer['id']]);
    }

    echo json_encode(['ok' => true, 'theme_id' => $tid]);
    exit;
}

// ─── POST: update_theme (writes properties back to the loaded theme; copy-on-edit) ──
if ($action === 'update_theme') {
    if (!$current) { echo json_encode(['ok' => false, 'error' => 'Login required']); exit; }
    $timer = resolve_timer_from_post($db, $current, $isAdmin);
    if (!$timer) { echo json_encode(['ok' => false, 'error' => 'Timer not found']); exit; }
    $props = json_decode($_POST['properties'] ?? '', true);
    if (!is_array($props)) {
        echo json_encode(['ok' => false, 'error' => 'Properties required']);
        exit;
    }
    $theme_id = (int)($timer['theme_id'] ?? 0);
    if (!$theme_id) {
        echo json_encode(['ok' => false, 'error' => 'No theme loaded']);
        exit;
    }

    $tc = $db->prepare('SELECT is_default, is_global, created_by, league_id FROM timer_themes WHERE id = ?');
    $tc->execute([$theme_id]);
    $themeRow = $tc->fetch();
    if (!$themeRow) { echo json_encode(['ok' => false, 'error' => 'Theme not found']); exit; }

    $is_protected     = (int)($themeRow['is_default'] ?? 0) || (int)($themeRow['is_global'] ?? 0);
    $theme_league_id  = (int)($themeRow['league_id'] ?? 0);
    $can_edit_league  = false;
    if ($theme_league_id > 0) {
        $role = league_role($theme_league_id, (int)$current['id']);
        $can_edit_league = in_array($role, ['owner', 'manager'], true);
    }

    $created_copy = false;
    if ($is_protected && !$isAdmin) {
        $db->prepare('INSERT INTO timer_themes (name, created_by, properties) VALUES (?, ?, ?)')
           ->execute(['Custom', $current['id'], json_encode(pk_theme_sanitize_props($props))]);
        $theme_id = (int)$db->lastInsertId();
        $db->prepare("UPDATE timer_state SET theme_id = ?, updated_at = datetime('now') WHERE id = ?")
           ->execute([$theme_id, $timer['id']]);
        $created_copy = true;
    } elseif ($theme_league_id > 0 && !$isAdmin && !$can_edit_league) {
        $db->prepare('INSERT INTO timer_themes (name, created_by, properties) VALUES (?, ?, ?)')
           ->execute(['Custom', $current['id'], json_encode(pk_theme_sanitize_props($props))]);
        $theme_id = (int)$db->lastInsertId();
        $db->prepare("UPDATE timer_state SET theme_id = ?, updated_at = datetime('now') WHERE id = ?")
           ->execute([$theme_id, $timer['id']]);
        $created_copy = true;
    } elseif ((int)($themeRow['created_by'] ?? 0) !== (int)$current['id'] && !$isAdmin) {
        // Someone else's personal theme. The chain above covered protected and
        // league themes but let this case fall through to a bare UPDATE, so any
        // user could overwrite any other user's theme by id. Copy on edit, the
        // same as every other branch here, and as delete_theme already required.
        $db->prepare('INSERT INTO timer_themes (name, created_by, properties) VALUES (?, ?, ?)')
           ->execute(['Custom', $current['id'], json_encode(pk_theme_sanitize_props($props))]);
        $theme_id = (int)$db->lastInsertId();
        $db->prepare("UPDATE timer_state SET theme_id = ?, updated_at = datetime('now') WHERE id = ?")
           ->execute([$theme_id, $timer['id']]);
        $created_copy = true;
    } else {
        $db->prepare('UPDATE timer_themes SET properties = ? WHERE id = ?')
           ->execute([json_encode(pk_theme_sanitize_props($props)), $theme_id]);
    }

    echo json_encode(['ok' => true, 'theme_id' => $theme_id, 'created_copy' => $created_copy]);
    exit;
}

// ─── POST: delete_theme ───────────────────────────────────
if ($action === 'delete_theme') {
    if (!$current) { echo json_encode(['ok' => false, 'error' => 'Login required']); exit; }
    $theme_id = (int)($_POST['theme_id'] ?? 0);
    $t = $db->prepare('SELECT * FROM timer_themes WHERE id = ?');
    $t->execute([$theme_id]);
    $themeRow = $t->fetch();
    if (!$themeRow) { echo json_encode(['ok' => false, 'error' => 'Not found']); exit; }
    if ((int)$themeRow['is_default']) { echo json_encode(['ok' => false, 'error' => 'Cannot delete default']); exit; }
    if ((int)($themeRow['is_global'] ?? 0) && !$isAdmin) {
        echo json_encode(['ok' => false, 'error' => 'Only admins can delete global themes']); exit;
    }
    $theme_league_id = (int)($themeRow['league_id'] ?? 0);
    if ($theme_league_id > 0) {
        $role = league_role($theme_league_id, (int)$current['id']);
        if (!$isAdmin && !in_array($role, ['owner', 'manager'], true)) {
            echo json_encode(['ok' => false, 'error' => 'Only league owners or managers can delete this theme.']); exit;
        }
    } elseif ((int)$themeRow['created_by'] !== (int)$current['id'] && !$isAdmin) {
        echo json_encode(['ok' => false, 'error' => 'Access denied']); exit;
    }

    // If the deleted theme used an uploaded background image and no other theme references it,
    // unlink the file to keep uploads/ from accumulating dead assets.
    $props = json_decode($themeRow['properties'] ?? '{}', true) ?: [];
    $img = $props['background']['image_url'] ?? '';
    if ($img && strpos($img, '/uploads/timer_bg/') === 0) {
        $needle = '%' . str_replace(['%','_'], ['\\%','\\_'], $img) . '%';
        $st = $db->prepare("SELECT COUNT(*) FROM timer_themes WHERE id != ? AND properties LIKE ? ESCAPE '\\'");
        $st->execute([$theme_id, $needle]);
        if ((int)$st->fetchColumn() === 0) {
            $abs = __DIR__ . $img;
            if (is_file($abs) && strpos(realpath($abs) ?: '', __DIR__ . '/uploads/timer_bg/') === 0) {
                @unlink($abs);
            }
        }
    }

    $db->prepare('DELETE FROM timer_themes WHERE id = ?')->execute([$theme_id]);
    echo json_encode(['ok' => true]);
    exit;
}

// ─── POST: set_default_theme (admin only) ─────────────────
if ($action === 'set_default_theme') {
    if (!$isAdmin) { echo json_encode(['ok' => false, 'error' => 'Admin only']); exit; }
    $theme_id = (int)($_POST['theme_id'] ?? 0);
    $t = $db->prepare('SELECT id FROM timer_themes WHERE id = ?');
    $t->execute([$theme_id]);
    if (!$t->fetch()) { echo json_encode(['ok' => false, 'error' => 'Theme not found']); exit; }
    $db->prepare('UPDATE timer_themes SET is_default = 0 WHERE is_default = 1')->execute();
    $db->prepare('UPDATE timer_themes SET is_default = 1, is_global = 1 WHERE id = ?')->execute([$theme_id]);
    echo json_encode(['ok' => true]);
    exit;
}

// ─── POST: upload_theme_bg (background image upload) ──────
if ($action === 'upload_theme_bg') {
    if (!$current) { echo json_encode(['ok' => false, 'error' => 'Login required']); exit; }

    $file = $_FILES['image'] ?? null;
    if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['ok' => false, 'error' => 'No file uploaded']); exit;
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];
    if (!isset($allowed[$mime])) {
        echo json_encode(['ok' => false, 'error' => 'Only JPEG, PNG, WebP, or GIF images allowed']); exit;
    }
    if ($file['size'] > 8 * 1024 * 1024) {
        echo json_encode(['ok' => false, 'error' => 'File too large (max 8 MB)']); exit;
    }

    $dir = __DIR__ . '/uploads/timer_bg/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $name = 'bg_' . bin2hex(random_bytes(8)) . '.' . $allowed[$mime];
    if (!move_uploaded_file($file['tmp_name'], $dir . $name)) {
        echo json_encode(['ok' => false, 'error' => 'Failed to save file']); exit;
    }

    echo json_encode(['ok' => true, 'url' => '/uploads/timer_bg/' . $name]);
    exit;
}

// ─── Preset themes (built-in .gnt.json files in www/timer_themes/) ──────────
// Curated theme exports shipped on disk. Users browse them in a gallery and "load"
// one, which materializes it as the user's own editable timer_themes row (so it
// persists across reloads and shows on remote/embedded displays). Admins upload/delete
// the files. Same envelope shape as the export/import flow.
function timer_preset_dir(): string { return __DIR__ . '/timer_themes'; }

// Resolve a client-supplied preset key to a safe absolute path inside timer_themes/,
// or null if unsafe / missing. Triple guard: basename strips any path, the regex
// restricts the charset to *.gnt.json, and the realpath prefix check defeats
// traversal/symlink escapes.
function timer_preset_path(string $key): ?string {
    $base = basename($key);
    if ($base === '' || $base[0] === '.') return null;
    if (!preg_match('/^[A-Za-z0-9._ -]+\.gnt\.json$/', $base)) return null;
    $dir = realpath(timer_preset_dir());
    if ($dir === false) return null;
    $real = realpath($dir . '/' . $base);
    if ($real === false || !is_file($real)) return null;
    if (strpos($real, $dir . DIRECTORY_SEPARATOR) !== 0) return null;
    return $real;
}

// True only for genuine GameNight timer-theme exports with an elements map.
function timer_preset_valid_envelope($data): bool {
    return is_array($data)
        && ($data['format'] ?? '') === 'gamenight-timer-theme'
        && isset($data['properties']['elements'])
        && is_array($data['properties']['elements']);
}

// ─── GET: get_preset_themes ───────────────────────────────
if ($action === 'get_preset_themes') {
    if (!$current) { echo json_encode(['ok' => false, 'error' => 'Login required']); exit; }
    $dir = timer_preset_dir();
    $files = is_dir($dir) ? glob($dir . '/*.gnt.json') : [];
    if ($files === false) $files = [];
    natcasesort($files);
    $out = []; $skipped = [];
    foreach ($files as $f) {
        if (count($out) >= 200) break;
        $data = json_decode((string)@file_get_contents($f), true);
        if (!timer_preset_valid_envelope($data)) { $skipped[] = basename($f); continue; }
        $out[] = [
            'key'        => basename($f),
            'name'       => (string)($data['name'] ?? basename($f, '.gnt.json')),
            'properties' => array_replace_recursive(timer_theme_defaults(), $data['properties']),
        ];
    }
    $resp = ['ok' => true, 'presets' => array_values($out)];
    if ($isAdmin && $skipped) $resp['skipped'] = $skipped;
    echo json_encode($resp);
    exit;
}

// ─── POST: apply_preset_theme (load a preset, persist as the user's own theme) ──
if ($action === 'apply_preset_theme') {
    if (!$current) { echo json_encode(['ok' => false, 'error' => 'Login required']); exit; }
    $timer = resolve_timer_from_post($db, $current, $isAdmin);
    if (!$timer) { echo json_encode(['ok' => false, 'error' => 'Timer not found']); exit; }
    $path = timer_preset_path($_POST['preset_key'] ?? '');
    if (!$path) { echo json_encode(['ok' => false, 'error' => 'Unknown preset']); exit; }

    $data = json_decode((string)@file_get_contents($path), true);
    if (!timer_preset_valid_envelope($data)) {
        echo json_encode(['ok' => false, 'error' => 'Preset file is invalid']); exit;
    }
    $name      = trim((string)($data['name'] ?? basename($path, '.gnt.json'))) ?: 'Preset';
    $props     = $data['properties'];
    $propsJson = json_encode(pk_theme_sanitize_props($props));

    // Find-or-create one personal row per (user, preset name) so repeated loads are
    // idempotent (reset to the preset baseline) rather than piling up duplicate copies.
    $sel = $db->prepare('SELECT id FROM timer_themes WHERE created_by = ? AND name = ? ORDER BY id LIMIT 1');
    $sel->execute([(int)$current['id'], $name]);
    $existingId = $sel->fetchColumn();
    if ($existingId) {
        $theme_id = (int)$existingId;
        $db->prepare('UPDATE timer_themes SET properties = ? WHERE id = ?')->execute([$propsJson, $theme_id]);
    } else {
        $db->prepare('INSERT INTO timer_themes (name, created_by, is_global, league_id, properties) VALUES (?, ?, 0, NULL, ?)')
           ->execute([$name, (int)$current['id'], $propsJson]);
        $theme_id = (int)$db->lastInsertId();
    }

    $db->prepare("UPDATE timer_state SET theme_id = ?, updated_at = datetime('now') WHERE id = ?")
       ->execute([$theme_id, $timer['id']]);

    $merged = array_replace_recursive(timer_theme_defaults(), $props);
    echo json_encode(['ok' => true, 'theme_id' => $theme_id, 'properties' => $merged]);
    exit;
}

// ─── POST: upload_preset_theme (admin only; CSRF enforced globally above) ──
if ($action === 'upload_preset_theme') {
    if (!$isAdmin) { echo json_encode(['ok' => false, 'error' => 'Admin only']); exit; }

    $raw = null; $origName = '';
    if (!empty($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
        if ($_FILES['file']['size'] > 256 * 1024) { echo json_encode(['ok' => false, 'error' => 'File too large (max 256 KB)']); exit; }
        $raw = (string)file_get_contents($_FILES['file']['tmp_name']);
        $origName = (string)($_FILES['file']['name'] ?? '');
    } elseif (isset($_POST['json'])) {
        $raw = (string)$_POST['json'];
    }
    if ($raw === null || $raw === '') { echo json_encode(['ok' => false, 'error' => 'No theme supplied']); exit; }

    $data = json_decode($raw, true);
    if (!timer_preset_valid_envelope($data)) {
        echo json_encode(['ok' => false, 'error' => 'Not a valid GameNight timer-theme export']); exit;
    }

    // Derive a safe basename from the theme name (fall back to the uploaded filename).
    $base = (string)($data['name'] ?? '');
    if ($base === '' && $origName !== '') $base = preg_replace('/\.gnt\.json$|\.json$/i', '', $origName);
    $slug = preg_replace('/[^A-Za-z0-9._-]+/', '_', $base);
    $slug = trim((string)$slug, '._-');
    if ($slug === '') $slug = 'preset';
    $slug = substr($slug, 0, 60);

    $dir = timer_preset_dir();
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $fname  = $slug . '.gnt.json';
    $target = $dir . '/' . $fname;
    for ($i = 2; file_exists($target); $i++) { $fname = $slug . '-' . $i . '.gnt.json'; $target = $dir . '/' . $fname; }

    // Re-encode the validated envelope rather than trusting raw upload bytes, so the
    // file written into the web root is always a clean, known shape.
    $envelope = [
        'format'      => 'gamenight-timer-theme',
        'version'     => 1,
        'exported_at' => gmdate('c'),
        'name'        => (string)($data['name'] ?? $slug),
        'properties'  => $data['properties'],
    ];
    if (@file_put_contents($target, json_encode($envelope, JSON_PRETTY_PRINT)) === false) {
        echo json_encode(['ok' => false, 'error' => 'Failed to write preset']); exit;
    }
    db_log_activity((int)$current['id'], 'timer preset uploaded: ' . $fname);
    echo json_encode(['ok' => true, 'key' => $fname]);
    exit;
}

// ─── POST: delete_preset_theme (admin only) ───────────────
if ($action === 'delete_preset_theme') {
    if (!$isAdmin) { echo json_encode(['ok' => false, 'error' => 'Admin only']); exit; }
    $path = timer_preset_path($_POST['preset_key'] ?? '');
    if (!$path) { echo json_encode(['ok' => false, 'error' => 'Unknown preset']); exit; }
    if (!@unlink($path)) { echo json_encode(['ok' => false, 'error' => 'Delete failed']); exit; }
    db_log_activity((int)$current['id'], 'timer preset deleted: ' . basename($path));
    echo json_encode(['ok' => true]);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Unknown action']);
