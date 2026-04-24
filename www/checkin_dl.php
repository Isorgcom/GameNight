<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_poker_helpers.php';

header('Content-Type: application/json');

$current = current_user();
if (!$current) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not authenticated']);
    exit;
}

$db = get_db();
$isAdmin = $current['role'] === 'admin';

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// Helper: check if current user is owner or manager of an event.
// Thin wrapper around can_manage_event() in db.php so this signature stays
// compatible with the many inline callers in this file.
function is_owner_or_manager($db, $event_id, $current, $isAdmin): bool {
    return can_manage_event($db, (int)$event_id, (int)$current['id'], (bool)$isAdmin);
}

// ─── ACTIONS ───────────────────────────────────────────────

if ($action === 'get_session') {
    $event_id = (int)($_GET['event_id'] ?? $_POST['event_id'] ?? 0);
    verify_event_access($db, $event_id, $current, $isAdmin);

    $stmt = $db->prepare('SELECT * FROM poker_sessions WHERE event_id = ?');
    $stmt->execute([$event_id]);
    $session = $stmt->fetch();

    if (!$session) {
        echo json_encode(['ok' => true, 'session' => null]);
        exit;
    }

    // Auto-sync: add any new invitees and update RSVP statuses
    sync_invitees($db, $session['id'], $session['event_id']);

    echo json_encode([
        'ok'      => true,
        'session' => $session,
        'players' => get_players($db, $session['id']),
        'payouts' => get_payouts($db, $session['id']),
        'pool'    => calc_pool($db, $session['id']),
    ]);
    exit;
}

// ─── GET: list_payout_structures ───────────────────────────
// Returns all payout structures visible to the current user
// (default, global, personal, and league presets for leagues the user is in).
if ($action === 'list_payout_structures') {
    $stmt = $db->prepare(
        'SELECT ps.id, ps.name, ps.is_default, ps.is_global, ps.created_by, ps.league_id, l.name AS league_name
         FROM payout_structures ps
         LEFT JOIN leagues l ON l.id = ps.league_id
         WHERE ps.is_default = 1
            OR ps.is_global  = 1
            OR ps.created_by = ?
            OR ps.league_id IN (SELECT league_id FROM league_members WHERE user_id = ?)
         ORDER BY ps.is_default DESC, ps.is_global DESC, LOWER(ps.name)'
    );
    $stmt->execute([$current['id'], $current['id']]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    // Attach places for each structure so the UI can preview / auto-load
    $placesStmt = $db->prepare('SELECT place, percentage FROM payout_structure_places WHERE structure_id = ? ORDER BY place');
    foreach ($rows as &$r) {
        $placesStmt->execute([(int)$r['id']]);
        $r['places'] = $placesStmt->fetchAll(PDO::FETCH_ASSOC);
    }
    echo json_encode(['ok' => true, 'structures' => $rows]);
    exit;
}

// ─── GET: get_session_defaults ─────────────────────────────
// Returns the caller's last-used poker session config. Accepts optional league_id.
// Lookup: (user, league_id) -> (user, NULL) -> hardcoded defaults.
if ($action === 'get_session_defaults') {
    $league_id = isset($_GET['league_id']) && $_GET['league_id'] !== '' ? (int)$_GET['league_id'] : null;
    if ($league_id !== null && $league_id <= 0) $league_id = null;
    $defaults = load_user_session_defaults($db, (int)$current['id'], $league_id);
    echo json_encode(['ok' => true, 'defaults' => $defaults]);
    exit;
}

// ─── GET: get_payout_user_leagues ──────────────────────────
// Leagues the current user can save payout structures to (owner/manager).
if ($action === 'get_payout_user_leagues') {
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

// All remaining actions require POST + CSRF
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}
if (!csrf_verify()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

// ─── init_session ──────────────────────────────────────────
if ($action === 'init_session') {
    $event_id = (int)($_POST['event_id'] ?? 0);
    verify_event_access($db, $event_id, $current, $isAdmin);

    // Check if already exists
    $chk = $db->prepare('SELECT id FROM poker_sessions WHERE event_id = ?');
    $chk->execute([$event_id]);
    if ($chk->fetch()) {
        echo json_encode(['ok' => false, 'error' => 'Session already exists for this event']);
        exit;
    }

    $buyin     = (int)($_POST['buyin_amount']  ?? 2000);
    $rebuy     = (int)($_POST['rebuy_amount']  ?? 2000);
    $addon     = (int)($_POST['addon_amount']  ?? 1000);
    $chips     = (int)($_POST['starting_chips'] ?? 5000);
    $tables    = (int)($_POST['num_tables']    ?? 1);
    $game_type = in_array($_POST['game_type'] ?? '', ['tournament', 'cash']) ? $_POST['game_type'] : 'tournament';

    $addon_chips = (int)($_POST['addon_chips'] ?? $chips);
    $ins = $db->prepare('INSERT INTO poker_sessions (event_id, buyin_amount, rebuy_amount, addon_amount, starting_chips, addon_chips, num_tables, game_type, seats_per_table) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 8)');
    $ins->execute([$event_id, $buyin, $rebuy, $addon, $chips, $addon_chips, $tables, $game_type]);
    $session_id = (int)$db->lastInsertId();

    // Remember these as the creator's last-used defaults (scoped to the event's league if any).
    $evRow = $db->prepare('SELECT league_id FROM events WHERE id = ?');
    $evRow->execute([$event_id]);
    $ev_league_id = $evRow->fetchColumn();
    save_user_session_defaults($db, (int)$current['id'], $ev_league_id ? (int)$ev_league_id : null, [
        'game_type'       => $game_type,
        'buyin_amount'    => $buyin,
        'rebuy_amount'    => $rebuy,
        'addon_amount'    => $addon,
        'starting_chips'  => $chips,
        'addon_chips'     => $addon_chips,
        'num_tables'      => $tables,
    ]);

    // Import all invitees with their RSVP status
    $invites = $db->prepare("SELECT ei.username, ei.rsvp, u.id as user_id FROM event_invites ei LEFT JOIN users u ON LOWER(ei.username) = LOWER(u.username) WHERE ei.event_id = ? GROUP BY LOWER(ei.username)");
    $invites->execute([$event_id]);
    $pIns = $db->prepare('INSERT INTO poker_players (session_id, user_id, display_name, rsvp) VALUES (?, ?, ?, ?)');
    foreach ($invites->fetchAll() as $inv) {
        $pIns->execute([$session_id, $inv['user_id'], $inv['username'], $inv['rsvp']]);
    }

    // Default payout structure (tournament only): use the seeded default if present, else 50/30/20.
    if ($game_type === 'tournament') {
        $payIns = $db->prepare('INSERT INTO poker_payouts (session_id, place, percentage) VALUES (?, ?, ?)');
        $defRow = $db->query('SELECT id FROM payout_structures WHERE is_default = 1 LIMIT 1')->fetch();
        if ($defRow) {
            $sp = $db->prepare('SELECT place, percentage FROM payout_structure_places WHERE structure_id = ? ORDER BY place');
            $sp->execute([(int)$defRow['id']]);
            foreach ($sp->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $payIns->execute([$session_id, (int)$r['place'], (float)$r['percentage']]);
            }
        } else {
            $payIns->execute([$session_id, 1, 50.0]);
            $payIns->execute([$session_id, 2, 30.0]);
            $payIns->execute([$session_id, 3, 20.0]);
        }
    }

    $sess = $db->prepare('SELECT * FROM poker_sessions WHERE id = ?');
    $sess->execute([$session_id]);

    echo json_encode([
        'ok'      => true,
        'session' => $sess->fetch(),
        'players' => get_players($db, $session_id),
        'payouts' => get_payouts($db, $session_id),
        'pool'    => calc_pool($db, $session_id),
    ]);
    exit;
}

// ─── update_config ─────────────────────────────────────────
if ($action === 'update_config') {
    $session_id = (int)($_POST['session_id'] ?? 0);
    $sess = $db->prepare('SELECT ps.*, e.created_by FROM poker_sessions ps JOIN events e ON ps.event_id = e.id WHERE ps.id = ?');
    $sess->execute([$session_id]);
    $s = $sess->fetch();
    if (!$s) { echo json_encode(['ok' => false, 'error' => 'Session not found']); exit; }
    if (!is_owner_or_manager($db, $s['event_id'], $current, $isAdmin)) {
        http_response_code(403); echo json_encode(['ok' => false, 'error' => 'Access denied']); exit;
    }

    $game_type = in_array($_POST['game_type'] ?? '', ['tournament', 'cash']) ? $_POST['game_type'] : $s['game_type'];
    $new_num_tables = (int)($_POST['num_tables'] ?? $s['num_tables']);
    $db->prepare('UPDATE poker_sessions SET buyin_amount=?, rebuy_amount=?, addon_amount=?, rebuy_allowed=?, addon_allowed=?, max_rebuys=?, starting_chips=?, addon_chips=?, num_tables=?, game_type=?, auto_assign_tables=?, seats_per_table=? WHERE id=?')->execute([
        (int)($_POST['buyin_amount'] ?? $s['buyin_amount']),
        (int)($_POST['rebuy_amount'] ?? $s['rebuy_amount']),
        (int)($_POST['addon_amount'] ?? $s['addon_amount']),
        (int)($_POST['rebuy_allowed'] ?? $s['rebuy_allowed']),
        (int)($_POST['addon_allowed'] ?? $s['addon_allowed']),
        (int)($_POST['max_rebuys'] ?? $s['max_rebuys']),
        (int)($_POST['starting_chips'] ?? $s['starting_chips']),
        (int)($_POST['addon_chips'] ?? $s['addon_chips'] ?? $s['starting_chips']),
        $new_num_tables,
        $game_type,
        (int)($_POST['auto_assign_tables'] ?? $s['auto_assign_tables'] ?? 1),
        (int)($_POST['seats_per_table'] ?? $s['seats_per_table'] ?? 9),
        $session_id,
    ]);

    // When tables are reduced, rebalance displaced players across remaining tables
    if ($new_num_tables < (int)$s['num_tables']) {
        $db->prepare('UPDATE poker_players SET table_number = NULL, seat_number = NULL WHERE session_id = ? AND table_number > ?')
           ->execute([$session_id, $new_num_tables]);
        if ($new_num_tables > 1) {
            rebalance_tables($db, $session_id);
        } else {
            // Single table: clear all table assignments
            $db->prepare('UPDATE poker_players SET table_number = NULL, seat_number = NULL WHERE session_id = ?')
               ->execute([$session_id]);
        }
    }

    $sess2 = $db->prepare('SELECT * FROM poker_sessions WHERE id = ?');
    $sess2->execute([$session_id]);
    $srow = $sess2->fetch();

    // Remember as the current user's last-used defaults (scoped to the event's league if any).
    $evRow = $db->prepare('SELECT league_id FROM events WHERE id = ?');
    $evRow->execute([(int)$s['event_id']]);
    $ev_league_id = $evRow->fetchColumn();
    save_user_session_defaults($db, (int)$current['id'], $ev_league_id ? (int)$ev_league_id : null, [
        'game_type'          => $srow['game_type'],
        'buyin_amount'       => (int)$srow['buyin_amount'],
        'rebuy_amount'       => (int)$srow['rebuy_amount'],
        'addon_amount'       => (int)$srow['addon_amount'],
        'starting_chips'     => (int)$srow['starting_chips'],
        'addon_chips'        => (int)$srow['addon_chips'],
        'rebuy_allowed'      => (int)$srow['rebuy_allowed'],
        'addon_allowed'      => (int)$srow['addon_allowed'],
        'max_rebuys'         => (int)$srow['max_rebuys'],
        'num_tables'         => (int)$srow['num_tables'],
        'seats_per_table'    => (int)$srow['seats_per_table'],
        'auto_assign_tables' => (int)$srow['auto_assign_tables'],
    ]);

    echo json_encode([
        'ok'      => true,
        'session' => $srow,
        'players' => get_players($db, $session_id),
        'pool'    => calc_pool($db, $session_id),
        'payouts' => get_payouts($db, $session_id),
    ]);
    exit;
}

// ─── update_status ─────────────────────────────────────────
if ($action === 'update_status') {
    $session_id = (int)($_POST['session_id'] ?? 0);
    $status = $_POST['status'] ?? '';
    if (!in_array($status, ['setup', 'active', 'finished'])) {
        echo json_encode(['ok' => false, 'error' => 'Invalid status']); exit;
    }

    $sess = $db->prepare('SELECT ps.*, e.created_by FROM poker_sessions ps JOIN events e ON ps.event_id = e.id WHERE ps.id = ?');
    $sess->execute([$session_id]);
    $s = $sess->fetch();
    if (!$s) { echo json_encode(['ok' => false, 'error' => 'Session not found']); exit; }
    if (!is_owner_or_manager($db, $s['event_id'], $current, $isAdmin)) {
        http_response_code(403); echo json_encode(['ok' => false, 'error' => 'Access denied']); exit;
    }

    $db->prepare('UPDATE poker_sessions SET status = ? WHERE id = ?')->execute([$status, $session_id]);
    echo json_encode(['ok' => true, 'status' => $status]);
    exit;
}

// ─── toggle_buyin ──────────────────────────────────────────
if ($action === 'toggle_buyin') {
    $player_id = (int)($_POST['player_id'] ?? 0);
    $session = get_session_from_player($db, $player_id);
    if (!$session) { echo json_encode(['ok' => false, 'error' => 'Player not found']); exit; }
    verify_event_access($db, $session['event_id'], $current, $isAdmin);

    // Block buy-in for pending players
    $plName = $db->prepare('SELECT display_name FROM poker_players WHERE id = ?');
    $plName->execute([$player_id]);
    $plRow = $plName->fetch();
    if ($plRow) {
        $apSt = $db->prepare("SELECT approval_status FROM event_invites WHERE event_id = ? AND LOWER(username) = LOWER(?) AND occurrence_date IS NULL");
        $apSt->execute([$session['event_id'], $plRow['display_name']]);
        if (($apSt->fetchColumn() ?: 'approved') === 'pending') {
            echo json_encode(['ok' => false, 'error' => 'Player must be approved before buying in.']);
            exit;
        }
    }

    // Atomic toggle
    $db->beginTransaction();
    $pl = $db->prepare('SELECT bought_in FROM poker_players WHERE id = ?');
    $pl->execute([$player_id]);
    $cur = $pl->fetch();
    if ((int)$cur['bought_in'] === 0) {
        $db->prepare('UPDATE poker_players SET bought_in = 1, checked_in = 1 WHERE id = ?')->execute([$player_id]);
        $db->commit();
        auto_assign_table($db, $session['id'], $player_id);
    } else {
        // Un-buying: clear bought_in and the table assignment
        $db->prepare('UPDATE poker_players SET bought_in = 0, checked_in = 0, table_number = NULL, seat_number = NULL WHERE id = ?')->execute([$player_id]);
        $db->commit();
    }

    $p = $db->prepare('SELECT * FROM poker_players WHERE id = ?');
    $p->execute([$player_id]);
    echo json_encode([
        'ok'     => true,
        'player' => $p->fetch(),
        'pool'   => calc_pool($db, $session['id']),
    ]);
    exit;
}

// ─── update_rebuys ─────────────────────────────────────────
if ($action === 'update_rebuys') {
    $player_id = (int)($_POST['player_id'] ?? 0);
    $delta = (int)($_POST['delta'] ?? 0);
    $session = get_session_from_player($db, $player_id);
    if (!$session) { echo json_encode(['ok' => false, 'error' => 'Player not found']); exit; }
    verify_event_access($db, $session['event_id'], $current, $isAdmin);

    if (!(int)$session['rebuy_allowed']) {
        echo json_encode(['ok' => false, 'error' => 'Rebuys not allowed']); exit;
    }

    $pl = $db->prepare('SELECT rebuys FROM poker_players WHERE id = ?');
    $pl->execute([$player_id]);
    $cur = (int)$pl->fetch()['rebuys'];
    $newVal = max(0, $cur + $delta);

    // Enforce max_rebuys if set
    if ((int)$session['max_rebuys'] > 0 && $newVal > (int)$session['max_rebuys']) {
        $newVal = (int)$session['max_rebuys'];
    }

    $db->prepare('UPDATE poker_players SET rebuys = ? WHERE id = ?')->execute([$newVal, $player_id]);

    $p = $db->prepare('SELECT * FROM poker_players WHERE id = ?');
    $p->execute([$player_id]);
    echo json_encode([
        'ok'     => true,
        'player' => $p->fetch(),
        'pool'   => calc_pool($db, $session['id']),
    ]);
    exit;
}

// ─── update_addons ─────────────────────────────────────────
if ($action === 'update_addons') {
    $player_id = (int)($_POST['player_id'] ?? 0);
    $delta = (int)($_POST['delta'] ?? 0);
    $session = get_session_from_player($db, $player_id);
    if (!$session) { echo json_encode(['ok' => false, 'error' => 'Player not found']); exit; }
    verify_event_access($db, $session['event_id'], $current, $isAdmin);

    if (!(int)$session['addon_allowed']) {
        echo json_encode(['ok' => false, 'error' => 'Add-ons not allowed']); exit;
    }

    $pl = $db->prepare('SELECT addons FROM poker_players WHERE id = ?');
    $pl->execute([$player_id]);
    $cur = (int)$pl->fetch()['addons'];
    $newVal = max(0, $cur + $delta);

    $db->prepare('UPDATE poker_players SET addons = ? WHERE id = ?')->execute([$newVal, $player_id]);

    $p = $db->prepare('SELECT * FROM poker_players WHERE id = ?');
    $p->execute([$player_id]);
    echo json_encode([
        'ok'     => true,
        'player' => $p->fetch(),
        'pool'   => calc_pool($db, $session['id']),
    ]);
    exit;
}

// ─── set_table ─────────────────────────────────────────────
if ($action === 'set_table') {
    $player_id = (int)($_POST['player_id'] ?? 0);
    $table_number = $_POST['table_number'] !== '' ? (int)$_POST['table_number'] : null;
    $session = get_session_from_player($db, $player_id);
    if (!$session) { echo json_encode(['ok' => false, 'error' => 'Player not found']); exit; }
    verify_event_access($db, $session['event_id'], $current, $isAdmin);

    // Assign a random open seat at the target table (or clear seat if unassigning)
    $seat = ($table_number !== null) ? pick_random_seat($db, $session['id'], $table_number) : null;
    $db->prepare('UPDATE poker_players SET table_number = ?, seat_number = ? WHERE id = ?')->execute([$table_number, $seat, $player_id]);

    $p = $db->prepare('SELECT * FROM poker_players WHERE id = ?');
    $p->execute([$player_id]);
    echo json_encode(['ok' => true, 'player' => $p->fetch()]);
    exit;
}

// ─── eliminate_player ──────────────────────────────────────
if ($action === 'eliminate_player') {
    $player_id = (int)($_POST['player_id'] ?? 0);
    $finish_position = (int)($_POST['finish_position'] ?? 0);
    $session = get_session_from_player($db, $player_id);
    if (!$session) { echo json_encode(['ok' => false, 'error' => 'Player not found']); exit; }
    verify_event_access($db, $session['event_id'], $current, $isAdmin);

    $db->prepare('UPDATE poker_players SET eliminated = 1, finish_position = ?, table_number = NULL, seat_number = NULL WHERE id = ?')->execute([$finish_position, $player_id]);

    $p = $db->prepare('SELECT * FROM poker_players WHERE id = ?');
    $p->execute([$player_id]);
    echo json_encode([
        'ok'     => true,
        'player' => $p->fetch(),
        'pool'   => calc_pool($db, $session['id']),
    ]);
    exit;
}

// ─── uneliminate_player ────────────────────────────────────
if ($action === 'uneliminate_player') {
    $player_id = (int)($_POST['player_id'] ?? 0);
    $session = get_session_from_player($db, $player_id);
    if (!$session) { echo json_encode(['ok' => false, 'error' => 'Player not found']); exit; }
    verify_event_access($db, $session['event_id'], $current, $isAdmin);

    $db->prepare('UPDATE poker_players SET eliminated = 0, finish_position = NULL WHERE id = ?')->execute([$player_id]);

    $p = $db->prepare('SELECT * FROM poker_players WHERE id = ?');
    $p->execute([$player_id]);
    echo json_encode([
        'ok'     => true,
        'player' => $p->fetch(),
        'pool'   => calc_pool($db, $session['id']),
    ]);
    exit;
}

// ─── add_walkin ────────────────────────────────────────────
if ($action === 'add_walkin') {
    $session_id = (int)($_POST['session_id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    if ($name === '') { echo json_encode(['ok' => false, 'error' => 'Name required']); exit; }

    $sess = $db->prepare('SELECT ps.*, e.created_by FROM poker_sessions ps JOIN events e ON ps.event_id = e.id WHERE ps.id = ?');
    $sess->execute([$session_id]);
    $s = $sess->fetch();
    if (!$s) { echo json_encode(['ok' => false, 'error' => 'Session not found']); exit; }
    if (!is_owner_or_manager($db, $s['event_id'], $current, $isAdmin)) {
        http_response_code(403); echo json_encode(['ok' => false, 'error' => 'Access denied']); exit;
    }

    // Check if a user with this username exists
    $userChk = $db->prepare('SELECT id, username, email FROM users WHERE LOWER(username) = LOWER(?)');
    $userChk->execute([$name]);
    $existingUser = $userChk->fetch();
    $user_id = $existingUser ? (int)$existingUser['id'] : null;

    // Ensure an event_invites row exists (host-added = auto-approved)
    $eiChk = $db->prepare('SELECT id FROM event_invites WHERE event_id = ? AND LOWER(username) = LOWER(?) AND occurrence_date IS NULL');
    $eiChk->execute([$s['event_id'], $name]);
    if (!$eiChk->fetch()) {
        $db->prepare("INSERT INTO event_invites (event_id, username, email, rsvp, approval_status) VALUES (?, ?, ?, 'yes', 'approved')")
           ->execute([$s['event_id'], strtolower($name), $existingUser['email'] ?? null]);
    } else {
        // If they were pending/denied, approve them since the host is adding them manually.
        $db->prepare("UPDATE event_invites SET rsvp = 'yes', approval_status = 'approved' WHERE event_id = ? AND LOWER(username) = LOWER(?) AND occurrence_date IS NULL")
           ->execute([$s['event_id'], $name]);
    }

    // Check if player already exists in this session (including removed)
    $existingPlayer = $db->prepare('SELECT id, removed FROM poker_players WHERE session_id = ? AND LOWER(display_name) = LOWER(?)');
    $existingPlayer->execute([$session_id, $name]);
    $epRow = $existingPlayer->fetch();

    if ($epRow) {
        // Re-activate if removed, otherwise already exists
        if ((int)$epRow['removed']) {
            $db->prepare('UPDATE poker_players SET removed = 0, checked_in = 1 WHERE id = ?')->execute([$epRow['id']]);
            auto_assign_table($db, $session_id, $epRow['id']);
        }
        $newId = (int)$epRow['id'];
    } else {
        // Use the correct-case username if it's an existing user account
        $displayName = $existingUser ? $existingUser['username'] ?? $name : $name;
        $db->prepare('INSERT INTO poker_players (session_id, user_id, display_name, checked_in) VALUES (?, ?, ?, 1)')->execute([$session_id, $user_id, $displayName]);
        $newId = (int)$db->lastInsertId();
        auto_assign_table($db, $session_id, $newId);
    }

    if ($user_id) auto_add_to_league($db, (int)$s['event_id'], (int)$user_id);

    $p = $db->prepare('SELECT * FROM poker_players WHERE id = ?');
    $p->execute([$newId]);
    echo json_encode([
        'ok'     => true,
        'player' => $p->fetch(),
        'pool'   => calc_pool($db, $session_id),
    ]);
    exit;
}

// ─── approve_player / deny_player ──────────────────────────
if (in_array($action, ['approve_player', 'deny_player'], true)) {
    $player_id = (int)($_POST['player_id'] ?? 0);
    $session = get_session_from_player($db, $player_id);
    if (!$session) { echo json_encode(['ok' => false, 'error' => 'Player not found']); exit; }
    verify_event_access($db, $session['event_id'], $current, $isAdmin);

    $pl = $db->prepare('SELECT display_name FROM poker_players WHERE id = ?');
    $pl->execute([$player_id]);
    $pRow = $pl->fetch();
    if (!$pRow) { echo json_encode(['ok' => false, 'error' => 'Player not found']); exit; }

    $newStatus = ($action === 'approve_player') ? 'approved' : 'denied';
    $db->prepare("UPDATE event_invites SET approval_status = ? WHERE event_id = ? AND LOWER(username) = LOWER(?) AND occurrence_date IS NULL")
       ->execute([$newStatus, $session['event_id'], $pRow['display_name']]);

    if ($newStatus === 'approved') {
        // Assign table/seat if not already assigned
        $assigned_table = auto_assign_table($db, $session['id'], $player_id);

        // Re-fetch player to get table/seat for the notification
        $updated = $db->prepare('SELECT table_number, seat_number FROM poker_players WHERE id = ?');
        $updated->execute([$player_id]);
        $updatedRow = $updated->fetch();
        $tableNum = $updatedRow ? $updatedRow['table_number'] : null;
        $seatNum  = $updatedRow ? $updatedRow['seat_number'] : null;

        // Queue the approval notification (table/seat in payload)
        require_once __DIR__ . '/_notifications.php';
        $payload = [];
        if ($tableNum && $seatNum) {
            $payload['table'] = (int)$tableNum;
            $payload['seat']  = (int)$seatNum;
        }
        queue_event_notification($db, (int)$session['event_id'], $pRow['display_name'], 'poker_approved', null, $payload ?: null);
    } else {
        // Deny: soft-remove from poker roster
        $db->prepare('UPDATE poker_players SET removed = 1 WHERE id = ?')->execute([$player_id]);
    }

    echo json_encode([
        'ok'      => true,
        'status'  => $newStatus,
        'players' => get_players($db, $session['id']),
        'pool'    => calc_pool($db, $session['id']),
    ]);
    exit;
}

// ─── remove_player ─────────────────────────────────────────
if ($action === 'remove_player') {
    $player_id = (int)($_POST['player_id'] ?? 0);
    $session = get_session_from_player($db, $player_id);
    if (!$session) { echo json_encode(['ok' => false, 'error' => 'Player not found']); exit; }
    verify_event_access($db, $session['event_id'], $current, $isAdmin);

    // Get player name before removing
    $pl = $db->prepare('SELECT display_name FROM poker_players WHERE id = ?');
    $pl->execute([$player_id]);
    $player = $pl->fetch();

    // Soft-delete from poker session
    $db->prepare('UPDATE poker_players SET removed = 1 WHERE id = ?')->execute([$player_id]);

    // Also remove from event invites
    if ($player) {
        $db->prepare('DELETE FROM event_invites WHERE event_id = ? AND LOWER(username) = LOWER(?)')
           ->execute([$session['event_id'], $player['display_name']]);
    }

    echo json_encode([
        'ok'   => true,
        'pool' => calc_pool($db, $session['id']),
    ]);
    exit;
}

// ─── update_payouts ────────────────────────────────────────
if ($action === 'update_payouts') {
    $session_id = (int)($_POST['session_id'] ?? 0);
    $sess = $db->prepare('SELECT ps.*, e.created_by FROM poker_sessions ps JOIN events e ON ps.event_id = e.id WHERE ps.id = ?');
    $sess->execute([$session_id]);
    $s = $sess->fetch();
    if (!$s) { echo json_encode(['ok' => false, 'error' => 'Session not found']); exit; }
    if (!is_owner_or_manager($db, $s['event_id'], $current, $isAdmin)) {
        http_response_code(403); echo json_encode(['ok' => false, 'error' => 'Access denied']); exit;
    }

    $places = $_POST['places'] ?? [];
    $percentages = $_POST['percentages'] ?? [];

    $totalPct = 0;
    for ($i = 0; $i < count($percentages); $i++) $totalPct += (float)$percentages[$i];
    if ($totalPct > 100) {
        echo json_encode(['ok' => false, 'error' => 'Payout percentages cannot exceed 100%']);
        exit;
    }

    $db->prepare('DELETE FROM poker_payouts WHERE session_id = ?')->execute([$session_id]);
    $ins = $db->prepare('INSERT INTO poker_payouts (session_id, place, percentage) VALUES (?, ?, ?)');
    for ($i = 0; $i < count($places); $i++) {
        $place = (int)$places[$i];
        $pct = (float)$percentages[$i];
        if ($place > 0 && $pct > 0) {
            $ins->execute([$session_id, $place, $pct]);
        }
    }

    echo json_encode([
        'ok'      => true,
        'payouts' => get_payouts($db, $session_id),
        'pool'    => calc_pool($db, $session_id),
    ]);
    exit;
}

// ─── set_player_payout ─────────────────────────────────────
if ($action === 'set_player_payout') {
    $player_id = (int)($_POST['player_id'] ?? 0);
    $payout = (int)($_POST['payout'] ?? 0);
    $session = get_session_from_player($db, $player_id);
    if (!$session) { echo json_encode(['ok' => false, 'error' => 'Player not found']); exit; }
    verify_event_access($db, $session['event_id'], $current, $isAdmin);

    $db->prepare('UPDATE poker_players SET payout = ? WHERE id = ?')->execute([$payout, $player_id]);

    $p = $db->prepare('SELECT * FROM poker_players WHERE id = ?');
    $p->execute([$player_id]);
    echo json_encode(['ok' => true, 'player' => $p->fetch()]);
    exit;
}

// ─── update_notes ──────────────────────────────────────────
if ($action === 'update_notes') {
    $player_id = (int)($_POST['player_id'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');
    $session = get_session_from_player($db, $player_id);
    if (!$session) { echo json_encode(['ok' => false, 'error' => 'Player not found']); exit; }
    verify_event_access($db, $session['event_id'], $current, $isAdmin);

    $db->prepare('UPDATE poker_players SET notes = ? WHERE id = ?')->execute([$notes, $player_id]);

    $p = $db->prepare('SELECT * FROM poker_players WHERE id = ?');
    $p->execute([$player_id]);
    echo json_encode(['ok' => true, 'player' => $p->fetch()]);
    exit;
}

// ─── update_rsvp ───────────────────────────────────────────
if ($action === 'update_rsvp') {
    $player_id = (int)($_POST['player_id'] ?? 0);
    $rsvp = in_array($_POST['rsvp'] ?? '', ['yes', 'no', 'maybe', '']) ? $_POST['rsvp'] : null;
    if ($rsvp === '') $rsvp = null;
    $session = get_session_from_player($db, $player_id);
    if (!$session) { echo json_encode(['ok' => false, 'error' => 'Player not found']); exit; }
    verify_event_access($db, $session['event_id'], $current, $isAdmin);

    // Update poker_players rsvp
    $db->prepare('UPDATE poker_players SET rsvp = ? WHERE id = ?')->execute([$rsvp, $player_id]);

    // Also update event_invites to keep in sync. Host action implicitly approves any pending row.
    $pl = $db->prepare('SELECT display_name FROM poker_players WHERE id = ?');
    $pl->execute([$player_id]);
    $pRow = $pl->fetch();
    if ($pRow) {
        $db->prepare("UPDATE event_invites SET rsvp = ?, approval_status = 'approved' WHERE event_id = ? AND LOWER(username) = LOWER(?) AND occurrence_date IS NULL")
           ->execute([$rsvp, $session['event_id'], $pRow['display_name']]);
    }

    $p = $db->prepare('SELECT * FROM poker_players WHERE id = ?');
    $p->execute([$player_id]);
    echo json_encode([
        'ok'     => true,
        'player' => $p->fetch(),
        'pool'   => calc_pool($db, $session['id']),
    ]);
    exit;
}

// ─── add_cashin ────────────────────────────────────────────
if ($action === 'add_cashin') {
    $player_id = (int)($_POST['player_id'] ?? 0);
    $amount = (int)($_POST['amount'] ?? 0);
    if ($amount <= 0) { echo json_encode(['ok' => false, 'error' => 'Amount must be positive']); exit; }
    $session = get_session_from_player($db, $player_id);
    if (!$session) { echo json_encode(['ok' => false, 'error' => 'Player not found']); exit; }
    verify_event_access($db, $session['event_id'], $current, $isAdmin);

    // Add to existing cash_in, also mark as bought_in and checked_in
    $db->prepare('UPDATE poker_players SET cash_in = COALESCE(cash_in, 0) + ?, bought_in = 1, checked_in = 1 WHERE id = ?')->execute([$amount, $player_id]);
    auto_assign_table($db, $session['id'], $player_id);

    $p = $db->prepare('SELECT * FROM poker_players WHERE id = ?');
    $p->execute([$player_id]);
    echo json_encode([
        'ok'     => true,
        'player' => $p->fetch(),
        'pool'   => calc_pool($db, $session['id']),
    ]);
    exit;
}

// ─── set_cashin (override total) ───────────────────────────
if ($action === 'set_cashin') {
    $player_id = (int)($_POST['player_id'] ?? 0);
    $amount = (int)($_POST['amount'] ?? 0);
    $session = get_session_from_player($db, $player_id);
    if (!$session) { echo json_encode(['ok' => false, 'error' => 'Player not found']); exit; }
    verify_event_access($db, $session['event_id'], $current, $isAdmin);

    $amt = max(0, $amount);
    if ($amt > 0) {
        $db->prepare('UPDATE poker_players SET cash_in = ?, bought_in = 1, checked_in = 1 WHERE id = ?')->execute([$amt, $player_id]);
        auto_assign_table($db, $session['id'], $player_id);
    } else {
        $db->prepare('UPDATE poker_players SET cash_in = ? WHERE id = ?')->execute([$amt, $player_id]);
    }

    $p = $db->prepare('SELECT * FROM poker_players WHERE id = ?');
    $p->execute([$player_id]);
    echo json_encode([
        'ok'     => true,
        'player' => $p->fetch(),
        'pool'   => calc_pool($db, $session['id']),
    ]);
    exit;
}

// ─── set_cashout ───────────────────────────────────────────
if ($action === 'set_cashout') {
    $player_id = (int)($_POST['player_id'] ?? 0);
    $cash_out = $_POST['cash_out'] !== '' ? (int)$_POST['cash_out'] : null;
    $session = get_session_from_player($db, $player_id);
    if (!$session) { echo json_encode(['ok' => false, 'error' => 'Player not found']); exit; }
    verify_event_access($db, $session['event_id'], $current, $isAdmin);

    // Validate cashout doesn't exceed money remaining on the table
    if ($cash_out !== null) {
        $pool = calc_pool($db, $session['id']);
        $old = $db->prepare('SELECT cash_out FROM poker_players WHERE id = ?');
        $old->execute([$player_id]);
        $old_cashout = (int)($old->fetchColumn() ?? 0);
        $remaining = $pool['pool_total'] - $pool['total_cash_out'] + $old_cashout;
        if ($cash_out > $remaining) {
            echo json_encode(['ok' => false, 'error' => 'Cash-out exceeds money remaining on the table ($' . number_format($remaining / 100, 2) . ')']);
            exit;
        }
    }

    $db->prepare('UPDATE poker_players SET cash_out = ? WHERE id = ?')->execute([$cash_out, $player_id]);

    $p = $db->prepare('SELECT * FROM poker_players WHERE id = ?');
    $p->execute([$player_id]);
    echo json_encode([
        'ok'     => true,
        'player' => $p->fetch(),
        'pool'   => calc_pool($db, $session['id']),
    ]);
    exit;
}

// ─── move_player_table ─────────────────────────────────────
if ($action === 'move_player_table') {
    $player_id = (int)($_POST['player_id'] ?? 0);
    $new_table = (int)($_POST['new_table'] ?? 0);
    $session = get_session_from_player($db, $player_id);
    if (!$session) { echo json_encode(['ok' => false, 'error' => 'Player not found']); exit; }
    verify_event_access($db, $session['event_id'], $current, $isAdmin);

    if ($new_table < 1 || $new_table > (int)$session['num_tables']) {
        echo json_encode(['ok' => false, 'error' => 'Invalid table number']); exit;
    }

    // Random open seat at target table
    $seat = pick_random_seat($db, $session['id'], $new_table);
    $db->prepare('UPDATE poker_players SET table_number = ?, seat_number = ? WHERE id = ?')->execute([$new_table, $seat, $player_id]);

    $p = $db->prepare('SELECT * FROM poker_players WHERE id = ?');
    $p->execute([$player_id]);
    echo json_encode([
        'ok'      => true,
        'player'  => $p->fetch(),
        'players' => get_players($db, $session['id']),
    ]);
    exit;
}

// ─── break_up_table ────────────────────────────────────────
if ($action === 'break_up_table') {
    $session_id = (int)($_POST['session_id'] ?? 0);
    $table_number = (int)($_POST['table_number'] ?? 0);
    $sess = $db->prepare('SELECT ps.*, e.created_by FROM poker_sessions ps JOIN events e ON ps.event_id = e.id WHERE ps.id = ?');
    $sess->execute([$session_id]);
    $s = $sess->fetch();
    if (!$s) { echo json_encode(['ok' => false, 'error' => 'Session not found']); exit; }
    if (!is_owner_or_manager($db, $s['event_id'], $current, $isAdmin)) {
        http_response_code(403); echo json_encode(['ok' => false, 'error' => 'Access denied']); exit;
    }

    $num_tables = (int)$s['num_tables'];
    if ($table_number < 1 || $table_number > $num_tables || $num_tables <= 1) {
        echo json_encode(['ok' => false, 'error' => 'Invalid table']); exit;
    }

    // Unassign all players from the broken-up table
    $db->prepare('UPDATE poker_players SET table_number = NULL, seat_number = NULL WHERE session_id = ? AND table_number = ?')
       ->execute([$session_id, $table_number]);

    // Reduce table count by 1
    $new_num = $num_tables - 1;
    $db->prepare('UPDATE poker_sessions SET num_tables = ? WHERE id = ?')->execute([$new_num, $session_id]);

    // Renumber tables above the removed one down by 1
    if ($table_number < $num_tables) {
        for ($t = $table_number + 1; $t <= $num_tables; $t++) {
            $db->prepare('UPDATE poker_players SET table_number = ? WHERE session_id = ? AND table_number = ?')
               ->execute([$t - 1, $session_id, $t]);
        }
    }

    // Distribute displaced players into the remaining tables
    $moves = [];
    if ($new_num === 1) {
        // Only 1 table left — assign all unassigned players to random seats at table 1
        $unassigned = $db->prepare('SELECT id, display_name FROM poker_players WHERE session_id = ? AND removed = 0 AND eliminated = 0 AND table_number IS NULL');
        $unassigned->execute([$session_id]);
        foreach ($unassigned->fetchAll() as $p) {
            $seat = pick_random_seat($db, $session_id, 1);
            $db->prepare('UPDATE poker_players SET table_number = 1, seat_number = ? WHERE id = ?')->execute([$seat, $p['id']]);
            $moves[] = ['player_id' => (int)$p['id'], 'display_name' => $p['display_name'], 'old_table' => $table_number, 'new_table' => 1];
        }
    } else {
        $moves = rebalance_tables($db, $session_id);
    }

    $sess2 = $db->prepare('SELECT * FROM poker_sessions WHERE id = ?');
    $sess2->execute([$session_id]);

    echo json_encode([
        'ok'      => true,
        'session' => $sess2->fetch(),
        'players' => get_players($db, $session_id),
        'moves'   => $moves,
    ]);
    exit;
}

// ─── rebalance_tables ──────────────────────────────────────
if ($action === 'rebalance_tables') {
    $session_id = (int)($_POST['session_id'] ?? 0);
    $sess = $db->prepare('SELECT ps.*, e.created_by FROM poker_sessions ps JOIN events e ON ps.event_id = e.id WHERE ps.id = ?');
    $sess->execute([$session_id]);
    $s = $sess->fetch();
    if (!$s) { echo json_encode(['ok' => false, 'error' => 'Session not found']); exit; }
    if (!is_owner_or_manager($db, $s['event_id'], $current, $isAdmin)) {
        http_response_code(403); echo json_encode(['ok' => false, 'error' => 'Access denied']); exit;
    }

    $protected = json_decode($_POST['protected_ids'] ?? '[]', true);
    if (!is_array($protected)) $protected = [];
    $protected = array_map('intval', $protected);

    $moves = rebalance_tables($db, $session_id, $protected);
    echo json_encode([
        'ok'      => true,
        'players' => get_players($db, $session_id),
        'moves'   => $moves,
    ]);
    exit;
}

// ─── POST: save_payout_structure ───────────────────────────
// Body: name, places[] (place), percentages[] (pct), optional league_id, optional is_global (admin)
if ($action === 'save_payout_structure') {
    $name = trim($_POST['name'] ?? '');
    $places = $_POST['places'] ?? [];
    $percentages = $_POST['percentages'] ?? [];
    $is_global = !empty($_POST['is_global']) ? 1 : 0;
    $req_league_id = (int)($_POST['league_id'] ?? 0) ?: null;

    if ($name === '' || !is_array($places) || count($places) === 0) {
        echo json_encode(['ok' => false, 'error' => 'Name and at least one place required']); exit;
    }
    if ($is_global && !$isAdmin) {
        echo json_encode(['ok' => false, 'error' => 'Only admins can save global structures']); exit;
    }

    $league_id = null;
    if ($req_league_id) {
        $role = league_role($req_league_id, (int)$current['id']);
        if (!$isAdmin && !in_array($role, ['owner', 'manager'], true)) {
            echo json_encode(['ok' => false, 'error' => 'You must be an owner or manager of that league.']); exit;
        }
        $league_id = $req_league_id;
        $is_global = 0; // league structures are not global
    }

    // Validate totals
    $total = 0.0;
    for ($i = 0; $i < count($percentages); $i++) $total += (float)$percentages[$i];
    if ($total > 100.0 + 0.001) {
        echo json_encode(['ok' => false, 'error' => 'Percentages total ' . number_format($total, 1) . '% — cannot exceed 100%']); exit;
    }

    $db->prepare('INSERT INTO payout_structures (name, created_by, is_global, league_id) VALUES (?, ?, ?, ?)')
       ->execute([$name, (int)$current['id'], $is_global, $league_id]);
    $sid = (int)$db->lastInsertId();

    $ins = $db->prepare('INSERT INTO payout_structure_places (structure_id, place, percentage) VALUES (?, ?, ?)');
    for ($i = 0; $i < count($places); $i++) {
        $pl = (int)$places[$i];
        $pct = (float)($percentages[$i] ?? 0);
        if ($pl > 0 && $pct > 0) {
            $ins->execute([$sid, $pl, $pct]);
        }
    }

    echo json_encode(['ok' => true, 'structure_id' => $sid]);
    exit;
}

// ─── POST: load_payout_structure ───────────────────────────
// Applies a structure's places to the session's poker_payouts.
if ($action === 'load_payout_structure') {
    $session_id = (int)($_POST['session_id'] ?? 0);
    $structure_id = (int)($_POST['structure_id'] ?? 0);
    $sess = $db->prepare('SELECT ps.*, e.created_by FROM poker_sessions ps JOIN events e ON ps.event_id = e.id WHERE ps.id = ?');
    $sess->execute([$session_id]);
    $s = $sess->fetch();
    if (!$s) { echo json_encode(['ok' => false, 'error' => 'Session not found']); exit; }
    if (!is_owner_or_manager($db, $s['event_id'], $current, $isAdmin)) {
        http_response_code(403); echo json_encode(['ok' => false, 'error' => 'Access denied']); exit;
    }

    $sp = $db->prepare('SELECT place, percentage FROM payout_structure_places WHERE structure_id = ? ORDER BY place');
    $sp->execute([$structure_id]);
    $rows = $sp->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) { echo json_encode(['ok' => false, 'error' => 'Structure not found or empty']); exit; }

    $db->prepare('DELETE FROM poker_payouts WHERE session_id = ?')->execute([$session_id]);
    $ins = $db->prepare('INSERT INTO poker_payouts (session_id, place, percentage) VALUES (?, ?, ?)');
    foreach ($rows as $r) {
        $ins->execute([$session_id, (int)$r['place'], (float)$r['percentage']]);
    }

    echo json_encode([
        'ok'      => true,
        'payouts' => get_payouts($db, $session_id),
        'pool'    => calc_pool($db, $session_id),
    ]);
    exit;
}

// ─── POST: delete_payout_structure ─────────────────────────
if ($action === 'delete_payout_structure') {
    $structure_id = (int)($_POST['structure_id'] ?? 0);
    $p = $db->prepare('SELECT * FROM payout_structures WHERE id = ?');
    $p->execute([$structure_id]);
    $struct = $p->fetch();
    if (!$struct) { echo json_encode(['ok' => false, 'error' => 'Not found']); exit; }
    if ((int)$struct['is_default']) { echo json_encode(['ok' => false, 'error' => 'Cannot delete default']); exit; }
    if ((int)($struct['is_global'] ?? 0) && !$isAdmin) {
        echo json_encode(['ok' => false, 'error' => 'Only admins can delete global structures']); exit;
    }
    $preset_league_id = (int)($struct['league_id'] ?? 0);
    if ($preset_league_id > 0) {
        $role = league_role($preset_league_id, (int)$current['id']);
        if (!$isAdmin && !in_array($role, ['owner', 'manager'], true)) {
            echo json_encode(['ok' => false, 'error' => 'Only league owners or managers can delete this structure.']); exit;
        }
    } elseif ((int)$struct['created_by'] !== (int)$current['id'] && !$isAdmin) {
        echo json_encode(['ok' => false, 'error' => 'Not allowed']); exit;
    }

    $db->prepare('DELETE FROM payout_structures WHERE id = ?')->execute([$structure_id]);
    echo json_encode(['ok' => true]);
    exit;
}

// ─── POST: set_default_payout_structure (admin only) ───────
if ($action === 'set_default_payout_structure') {
    if (!$isAdmin) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'Admin only']); exit; }
    $structure_id = (int)($_POST['structure_id'] ?? 0);
    $p = $db->prepare('SELECT id FROM payout_structures WHERE id = ?');
    $p->execute([$structure_id]);
    if (!$p->fetch()) { echo json_encode(['ok' => false, 'error' => 'Structure not found']); exit; }

    $db->prepare('UPDATE payout_structures SET is_default = 0 WHERE is_default = 1')->execute();
    $db->prepare('UPDATE payout_structures SET is_default = 1, is_global = 1 WHERE id = ?')->execute([$structure_id]);
    echo json_encode(['ok' => true]);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Unknown action']);
