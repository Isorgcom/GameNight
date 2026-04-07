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

    $ins = $db->prepare('INSERT INTO poker_sessions (event_id, buyin_amount, rebuy_amount, addon_amount, starting_chips, num_tables, game_type) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $ins->execute([$event_id, $buyin, $rebuy, $addon, $chips, $tables, $game_type]);
    $session_id = (int)$db->lastInsertId();

    // Import all invitees with their RSVP status
    $invites = $db->prepare("SELECT ei.username, ei.rsvp, u.id as user_id FROM event_invites ei LEFT JOIN users u ON LOWER(ei.username) = LOWER(u.username) WHERE ei.event_id = ? GROUP BY LOWER(ei.username)");
    $invites->execute([$event_id]);
    $pIns = $db->prepare('INSERT INTO poker_players (session_id, user_id, display_name, rsvp) VALUES (?, ?, ?, ?)');
    foreach ($invites->fetchAll() as $inv) {
        $pIns->execute([$session_id, $inv['user_id'], $inv['username'], $inv['rsvp']]);
    }

    // Default payout structure: 50/30/20 (tournament only)
    if ($game_type === 'tournament') {
        $payIns = $db->prepare('INSERT INTO poker_payouts (session_id, place, percentage) VALUES (?, ?, ?)');
        $payIns->execute([$session_id, 1, 50.0]);
        $payIns->execute([$session_id, 2, 30.0]);
        $payIns->execute([$session_id, 3, 20.0]);
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
    if (!$isAdmin && (int)$s['created_by'] !== (int)$current['id']) {
        http_response_code(403); echo json_encode(['ok' => false, 'error' => 'Access denied']); exit;
    }

    $game_type = in_array($_POST['game_type'] ?? '', ['tournament', 'cash']) ? $_POST['game_type'] : $s['game_type'];
    $db->prepare('UPDATE poker_sessions SET buyin_amount=?, rebuy_amount=?, addon_amount=?, rebuy_allowed=?, addon_allowed=?, max_rebuys=?, starting_chips=?, num_tables=?, game_type=? WHERE id=?')->execute([
        (int)($_POST['buyin_amount'] ?? $s['buyin_amount']),
        (int)($_POST['rebuy_amount'] ?? $s['rebuy_amount']),
        (int)($_POST['addon_amount'] ?? $s['addon_amount']),
        (int)($_POST['rebuy_allowed'] ?? $s['rebuy_allowed']),
        (int)($_POST['addon_allowed'] ?? $s['addon_allowed']),
        (int)($_POST['max_rebuys'] ?? $s['max_rebuys']),
        (int)($_POST['starting_chips'] ?? $s['starting_chips']),
        (int)($_POST['num_tables'] ?? $s['num_tables']),
        $game_type,
        $session_id,
    ]);

    $sess2 = $db->prepare('SELECT * FROM poker_sessions WHERE id = ?');
    $sess2->execute([$session_id]);

    echo json_encode([
        'ok'      => true,
        'session' => $sess2->fetch(),
        'pool'    => calc_pool($db, $session_id),
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
    if (!$isAdmin && (int)$s['created_by'] !== (int)$current['id']) {
        http_response_code(403); echo json_encode(['ok' => false, 'error' => 'Access denied']); exit;
    }

    $db->prepare('UPDATE poker_sessions SET status = ? WHERE id = ?')->execute([$status, $session_id]);
    echo json_encode(['ok' => true, 'status' => $status]);
    exit;
}

// ─── toggle_checkin ────────────────────────────────────────
if ($action === 'toggle_checkin') {
    $player_id = (int)($_POST['player_id'] ?? 0);
    $session = get_session_from_player($db, $player_id);
    if (!$session) { echo json_encode(['ok' => false, 'error' => 'Player not found']); exit; }
    verify_event_access($db, $session['event_id'], $current, $isAdmin);

    $db->prepare('UPDATE poker_players SET checked_in = CASE WHEN checked_in = 0 THEN 1 ELSE 0 END WHERE id = ?')->execute([$player_id]);

    $p = $db->prepare('SELECT * FROM poker_players WHERE id = ?');
    $p->execute([$player_id]);
    echo json_encode([
        'ok'     => true,
        'player' => $p->fetch(),
        'pool'   => calc_pool($db, $session['id']),
    ]);
    exit;
}

// ─── toggle_buyin ──────────────────────────────────────────
if ($action === 'toggle_buyin') {
    $player_id = (int)($_POST['player_id'] ?? 0);
    $session = get_session_from_player($db, $player_id);
    if (!$session) { echo json_encode(['ok' => false, 'error' => 'Player not found']); exit; }
    verify_event_access($db, $session['event_id'], $current, $isAdmin);

    // If buying in, also check them in
    $pl = $db->prepare('SELECT bought_in FROM poker_players WHERE id = ?');
    $pl->execute([$player_id]);
    $cur = $pl->fetch();
    if ((int)$cur['bought_in'] === 0) {
        $db->prepare('UPDATE poker_players SET bought_in = 1, checked_in = 1 WHERE id = ?')->execute([$player_id]);
    } else {
        $db->prepare('UPDATE poker_players SET bought_in = 0 WHERE id = ?')->execute([$player_id]);
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

    $db->prepare('UPDATE poker_players SET table_number = ? WHERE id = ?')->execute([$table_number, $player_id]);

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

    $db->prepare('UPDATE poker_players SET eliminated = 1, finish_position = ? WHERE id = ?')->execute([$finish_position, $player_id]);

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
    if (!$isAdmin && (int)$s['created_by'] !== (int)$current['id']) {
        http_response_code(403); echo json_encode(['ok' => false, 'error' => 'Access denied']); exit;
    }

    // Check if a user with this username exists
    $userChk = $db->prepare('SELECT id FROM users WHERE LOWER(username) = LOWER(?)');
    $userChk->execute([$name]);
    $existingUser = $userChk->fetch();
    $user_id = $existingUser ? (int)$existingUser['id'] : null;

    $db->prepare('INSERT INTO poker_players (session_id, user_id, display_name, checked_in) VALUES (?, ?, ?, 1)')->execute([$session_id, $user_id, $name]);
    $newId = (int)$db->lastInsertId();

    $p = $db->prepare('SELECT * FROM poker_players WHERE id = ?');
    $p->execute([$newId]);
    echo json_encode([
        'ok'     => true,
        'player' => $p->fetch(),
        'pool'   => calc_pool($db, $session_id),
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
    if (!$isAdmin && (int)$s['created_by'] !== (int)$current['id']) {
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

    // Also update event_invites to keep in sync
    $pl = $db->prepare('SELECT display_name FROM poker_players WHERE id = ?');
    $pl->execute([$player_id]);
    $pRow = $pl->fetch();
    if ($pRow) {
        $db->prepare("UPDATE event_invites SET rsvp = ? WHERE event_id = ? AND LOWER(username) = LOWER(?) AND occurrence_date IS NULL")
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

    $db->prepare('UPDATE poker_players SET cash_in = ? WHERE id = ?')->execute([max(0, $amount), $player_id]);

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

echo json_encode(['ok' => false, 'error' => 'Unknown action']);
