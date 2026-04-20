<?php
/**
 * Shared poker helper functions used by checkin_dl.php and timer_dl.php.
 */

// Verify event ownership (owner, manager, or admin)
function verify_event_access($db, $event_id, $current, $isAdmin) {
    $stmt = $db->prepare('SELECT created_by FROM events WHERE id = ?');
    $stmt->execute([$event_id]);
    $event = $stmt->fetch();
    if (!$event) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Event not found']);
        exit;
    }
    if (!$isAdmin && (int)$event['created_by'] !== (int)$current['id']) {
        $mgrStmt = $db->prepare("SELECT 1 FROM event_invites WHERE event_id=? AND LOWER(username)=LOWER(?) AND event_role='manager' LIMIT 1");
        $mgrStmt->execute([$event_id, $current['username']]);
        if (!$mgrStmt->fetch()) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Access denied']);
            exit;
        }
    }
}

// Check if user has event access without exiting (returns true/false)
function check_event_access($db, $event_id, $current, $isAdmin) {
    $stmt = $db->prepare('SELECT created_by FROM events WHERE id = ?');
    $stmt->execute([$event_id]);
    $event = $stmt->fetch();
    if (!$event) return false;
    if ($isAdmin) return true;
    if ((int)$event['created_by'] === (int)$current['id']) return true;
    $mgrStmt = $db->prepare("SELECT 1 FROM event_invites WHERE event_id=? AND LOWER(username)=LOWER(?) AND event_role='manager' LIMIT 1");
    $mgrStmt->execute([$event_id, $current['username']]);
    return (bool)$mgrStmt->fetch();
}

// Verify session access via player_id
function get_session_from_player($db, $player_id) {
    $stmt = $db->prepare('SELECT ps.* FROM poker_players pp JOIN poker_sessions ps ON pp.session_id = ps.id WHERE pp.id = ?');
    $stmt->execute([$player_id]);
    return $stmt->fetch();
}

// Calculate pool stats for a session
function calc_pool($db, $session_id) {
    $sess = $db->prepare('SELECT buyin_amount, rebuy_amount, addon_amount, game_type FROM poker_sessions WHERE id = ?');
    $sess->execute([$session_id]);
    $s = $sess->fetch();

    $stats = $db->prepare('SELECT
        COUNT(*) as total_players,
        SUM(bought_in) as bought_in,
        SUM(CASE WHEN eliminated = 0 AND bought_in = 1 THEN 1 ELSE 0 END) as still_playing,
        SUM(eliminated) as eliminated,
        SUM(bought_in) as total_buyins,
        SUM(rebuys) as total_rebuys,
        SUM(addons) as total_addons,
        SUM(CASE WHEN cash_out IS NOT NULL THEN 1 ELSE 0 END) as cashed_out,
        SUM(COALESCE(cash_out, 0)) as total_cash_out,
        SUM(COALESCE(cash_in, 0)) as total_cash_in
    FROM poker_players WHERE session_id = ? AND removed = 0');
    $stats->execute([$session_id]);
    $r = $stats->fetch();

    if ($s['game_type'] === 'cash') {
        $pool_total = (int)$r['total_cash_in'];
        $buyin_total = $pool_total;
        $rebuy_total = 0;
        $addon_total = 0;
    } else {
        $buyin_total  = (int)$r['total_buyins'] * (int)$s['buyin_amount'];
        $rebuy_total  = (int)$r['total_rebuys'] * (int)$s['rebuy_amount'];
        // addons stores cents directly (per-player custom amount), not a count
        $addon_total  = (int)$r['total_addons'];
        $pool_total   = $buyin_total + $rebuy_total + $addon_total;
    }

    return [
        'total_players'  => (int)$r['total_players'],
        'bought_in'      => (int)$r['bought_in'],
        'still_playing'  => (int)$r['still_playing'],
        'eliminated'     => (int)$r['eliminated'],
        'total_buyins'   => (int)$r['total_buyins'],
        'total_rebuys'   => (int)$r['total_rebuys'],
        'total_addons'   => (int)$r['total_addons'],
        'buyin_total'    => $buyin_total,
        'rebuy_total'    => $rebuy_total,
        'addon_total'    => $addon_total,
        'pool_total'     => $pool_total,
        'cashed_out'     => (int)$r['cashed_out'],
        'total_cash_out' => (int)$r['total_cash_out'],
        'total_cash_in'  => (int)$r['total_cash_in'],
    ];
}

// Sync invitees from event_invites into poker_players
function sync_invitees($db, $session_id, $event_id) {
    // Include removed players so they don't get re-added
    $existing = $db->prepare('SELECT LOWER(display_name) as dn FROM poker_players WHERE session_id = ?');
    $existing->execute([$session_id]);
    $existingNames = array_column($existing->fetchAll(), 'dn');

    // Sync approved AND pending invitees into poker_players so the host can see
    // pending players in checkin.php and approve/deny them. Denied rows stay hidden.
    $invites = $db->prepare("SELECT ei.username, ei.rsvp, u.id as user_id FROM event_invites ei LEFT JOIN users u ON LOWER(ei.username) = LOWER(u.username) WHERE ei.event_id = ? AND ei.approval_status IN ('approved', 'pending') GROUP BY LOWER(ei.username)");
    $invites->execute([$event_id]);

    $pIns = $db->prepare('INSERT INTO poker_players (session_id, user_id, display_name, rsvp) VALUES (?, ?, ?, ?)');
    $pUpd = $db->prepare('UPDATE poker_players SET rsvp = ? WHERE session_id = ? AND LOWER(display_name) = LOWER(?)');

    // Also prepare a statement to un-remove players who re-RSVP (e.g., were removed then RSVPed again)
    $pUnremove = $db->prepare('UPDATE poker_players SET removed = 0, rsvp = ? WHERE session_id = ? AND LOWER(display_name) = LOWER(?) AND removed = 1');

    $invitedNames = [];
    foreach ($invites->fetchAll() as $inv) {
        $invitedNames[] = strtolower($inv['username']);
        if (!in_array(strtolower($inv['username']), $existingNames)) {
            $pIns->execute([$session_id, $inv['user_id'], $inv['username'], $inv['rsvp']]);
        } else {
            $pUpd->execute([$inv['rsvp'], $session_id, $inv['username']]);
            // If a removed player RSVPs again, bring them back
            if ($inv['rsvp'] === 'yes') {
                $pUnremove->execute([$inv['rsvp'], $session_id, $inv['username']]);
            }
        }
    }

    // Soft-remove poker_players whose invite was deleted or denied (no longer in event_invites).
    // Only affects non-removed players to avoid flipping already-removed rows.
    $activePs = $db->prepare('SELECT id, LOWER(display_name) as dn FROM poker_players WHERE session_id = ? AND removed = 0');
    $activePs->execute([$session_id]);
    $pRemove = $db->prepare('UPDATE poker_players SET removed = 1 WHERE id = ?');
    foreach ($activePs->fetchAll() as $ap) {
        if (!in_array($ap['dn'], $invitedNames)) {
            $pRemove->execute([$ap['id']]);
        }
    }
}

// Get all players for a session (excludes removed players), with approval_status from event_invites
function get_players($db, $session_id) {
    $stmt = $db->prepare("SELECT pp.*, COALESCE(ei.approval_status, 'approved') as approval_status
        FROM poker_players pp
        LEFT JOIN poker_sessions ps ON ps.id = pp.session_id
        LEFT JOIN event_invites ei ON ei.event_id = ps.event_id AND LOWER(ei.username) = LOWER(pp.display_name) AND ei.occurrence_date IS NULL
        WHERE pp.session_id = ? AND pp.removed = 0
        ORDER BY pp.eliminated ASC, LOWER(pp.display_name) ASC");
    $stmt->execute([$session_id]);
    return $stmt->fetchAll();
}

// Pick a random open seat at a table. If the table is over-capacity, add one more seat.
function pick_random_seat(PDO $db, int $session_id, int $table_number): int {
    $sess = $db->prepare('SELECT seats_per_table FROM poker_sessions WHERE id = ?');
    $sess->execute([$session_id]);
    $seats_per_table = (int)($sess->fetchColumn() ?: 8);

    $occupied = $db->prepare('SELECT seat_number FROM poker_players WHERE session_id = ? AND table_number = ? AND removed = 0 AND seat_number IS NOT NULL');
    $occupied->execute([$session_id, $table_number]);
    $taken = array_map('intval', $occupied->fetchAll(PDO::FETCH_COLUMN));

    $all_seats = range(1, $seats_per_table);
    $open = array_values(array_diff($all_seats, $taken));

    if (empty($open)) {
        // Over-sitting: add one more seat beyond current max
        return max($seats_per_table, empty($taken) ? 0 : max($taken)) + 1;
    }
    return $open[array_rand($open)];
}

// Auto-assign a player to the table with fewest active players
function auto_assign_table($db, $session_id, $player_id): ?int {
    $sess = $db->prepare('SELECT num_tables, auto_assign_tables, seats_per_table FROM poker_sessions WHERE id = ?');
    $sess->execute([$session_id]);
    $s = $sess->fetch();
    if (!$s || !(int)$s['auto_assign_tables']) return null;

    // Single table: just assign to table 1
    if ((int)$s['num_tables'] <= 1) {
        $cur = $db->prepare('SELECT table_number FROM poker_players WHERE id = ?');
        $cur->execute([$player_id]);
        $row = $cur->fetch();
        if ($row && $row['table_number'] !== null) return (int)$row['table_number'];
        $seat = pick_random_seat($db, $session_id, 1);
        $db->prepare('UPDATE poker_players SET table_number = 1, seat_number = ? WHERE id = ?')->execute([$seat, $player_id]);
        return 1;
    }

    // Check if player already has a table
    $cur = $db->prepare('SELECT table_number FROM poker_players WHERE id = ?');
    $cur->execute([$player_id]);
    $row = $cur->fetch();
    if ($row && $row['table_number'] !== null) return (int)$row['table_number'];

    $num = (int)$s['num_tables'];
    $maxSeats = (int)($s['seats_per_table'] ?: 8);

    // Count active players per table
    $counts = $db->prepare('SELECT table_number, COUNT(*) as cnt FROM poker_players WHERE session_id = ? AND removed = 0 AND eliminated = 0 AND table_number IS NOT NULL GROUP BY table_number');
    $counts->execute([$session_id]);
    $map = [];
    for ($t = 1; $t <= $num; $t++) $map[$t] = 0;
    foreach ($counts->fetchAll() as $r) {
        $tn = (int)$r['table_number'];
        if ($tn >= 1 && $tn <= $num) $map[$tn] = (int)$r['cnt'];
    }

    // Find table with fewest players that isn't full
    $minTable = null;
    $minCount = PHP_INT_MAX;
    for ($t = 1; $t <= $num; $t++) {
        if ($map[$t] < $maxSeats && $map[$t] < $minCount) {
            $minCount = $map[$t];
            $minTable = $t;
        }
    }

    // All tables full — no assignment
    if ($minTable === null) return null;

    // Random open seat at that table
    $seat = pick_random_seat($db, $session_id, $minTable);
    $db->prepare('UPDATE poker_players SET table_number = ?, seat_number = ? WHERE id = ?')->execute([$minTable, $seat, $player_id]);
    return $minTable;
}

// Rebalance active players across tables — only move when difference > 1
// Protected players (Button, SB, BB) are never moved from their table
function rebalance_tables($db, $session_id, array $protected_ids = []): array {
    $sess = $db->prepare('SELECT num_tables, seats_per_table FROM poker_sessions WHERE id = ?');
    $sess->execute([$session_id]);
    $s = $sess->fetch();
    if (!$s) return [];

    // Single table: assign all unassigned players to table 1 with random seats
    if ((int)$s['num_tables'] <= 1) {
        $moves = [];
        $unassigned = $db->prepare('SELECT id, display_name FROM poker_players WHERE session_id = ? AND removed = 0 AND eliminated = 0 AND bought_in = 1 AND table_number IS NULL');
        $unassigned->execute([$session_id]);
        foreach ($unassigned->fetchAll() as $p) {
            $seat = pick_random_seat($db, $session_id, 1);
            $db->prepare('UPDATE poker_players SET table_number = 1, seat_number = ? WHERE id = ?')->execute([$seat, $p['id']]);
            $moves[] = ['player_id' => (int)$p['id'], 'display_name' => $p['display_name'], 'old_table' => null, 'new_table' => 1];
        }
        return $moves;
    }

    $num = (int)$s['num_tables'];

    $players = $db->prepare('SELECT id, display_name, table_number, seat_number FROM poker_players WHERE session_id = ? AND removed = 0 AND eliminated = 0 AND bought_in = 1 ORDER BY table_number, seat_number, id');
    $players->execute([$session_id]);
    $all = $players->fetchAll();

    $totalPlayers = count($all);
    if ($totalPlayers === 0) return [];

    // Group players by table, separating protected and movable
    $byTable = [];
    $unassigned = [];
    for ($t = 1; $t <= $num; $t++) $byTable[$t] = [];
    foreach ($all as $p) {
        $tn = ($p['table_number'] !== null && $p['table_number'] !== '') ? (int)$p['table_number'] : null;
        if ($tn !== null && $tn >= 1 && $tn <= $num) {
            $byTable[$tn][] = $p;
        } else {
            $unassigned[] = $p;
        }
    }

    // Assign unassigned players to the smallest table
    foreach ($unassigned as $p) {
        $minT = 1; $minC = count($byTable[1]);
        for ($t = 2; $t <= $num; $t++) {
            if (count($byTable[$t]) < $minC) { $minC = count($byTable[$t]); $minT = $t; }
        }
        $byTable[$minT][] = $p;
    }

    // Balance: move from biggest to smallest while difference > 1
    // Only move non-protected players, starting from behind the button (end of array)
    $maxIter = $totalPlayers * 2; // safety limit
    $iter = 0;
    $changed = true;
    while ($changed && $iter < $maxIter) {
        $changed = false;
        $iter++;
        // Find biggest and smallest tables
        $maxT = 1; $minT = 1;
        for ($t = 1; $t <= $num; $t++) {
            if (count($byTable[$t]) > count($byTable[$maxT])) $maxT = $t;
            if (count($byTable[$t]) < count($byTable[$minT])) $minT = $t;
        }
        if (count($byTable[$maxT]) - count($byTable[$minT]) <= 1) break;

        // Find a movable (non-protected) player from the biggest table
        // Search from end of array (behind the button)
        $movedOne = false;
        for ($i = count($byTable[$maxT]) - 1; $i >= 0; $i--) {
            if (!in_array((int)$byTable[$maxT][$i]['id'], $protected_ids, true)) {
                $p = $byTable[$maxT][$i];
                array_splice($byTable[$maxT], $i, 1);
                $byTable[$minT][] = $p;
                $movedOne = true;
                $changed = true;
                break;
            }
        }
        // If all players at this table are protected, stop
        if (!$movedOne) break;
    }

    // Write back with random seat assignment and track moves
    $moves = [];
    $update = $db->prepare('UPDATE poker_players SET table_number = ?, seat_number = ? WHERE id = ?');
    foreach ($byTable as $t => $tPlayers) {
        foreach ($tPlayers as $p) {
            $oldTable = ($p['table_number'] !== null && $p['table_number'] !== '') ? (int)$p['table_number'] : null;
            $seat = pick_random_seat($db, $session_id, $t);
            $update->execute([$t, $seat, $p['id']]);
            if ($oldTable === null || $oldTable !== $t) {
                $moves[] = ['player_id' => (int)$p['id'], 'display_name' => $p['display_name'], 'old_table' => $oldTable, 'new_table' => $t];
            }
        }
    }

    return $moves;
}

// Get payouts for a session
function get_payouts($db, $session_id) {
    $stmt = $db->prepare('SELECT * FROM poker_payouts WHERE session_id = ? ORDER BY place ASC');
    $stmt->execute([$session_id]);
    return $stmt->fetchAll();
}
