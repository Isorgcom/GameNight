<?php
/**
 * Shared poker helper functions used by checkin_dl.php and timer_dl.php.
 */

// Columns that make up a user's remembered poker session defaults.
const USER_SESSION_DEFAULT_COLS = [
    'game_type', 'buyin_amount', 'rebuy_amount', 'addon_amount',
    'starting_chips', 'addon_chips', 'rebuy_allowed', 'addon_allowed',
    'max_rebuys', 'num_tables', 'seats_per_table', 'auto_assign_tables',
    'bounty_amount', 'bounty_points', 'jackpot_amount',
];

// Hardcoded fallback for first-time users.
function default_session_defaults(): array {
    return [
        'game_type'          => 'tournament',
        'buyin_amount'       => 2000,
        'rebuy_amount'       => 2000,
        'addon_amount'       => 1000,
        'starting_chips'     => 5000,
        'addon_chips'        => 5000,
        'rebuy_allowed'      => 1,
        'addon_allowed'      => 1,
        'max_rebuys'         => 0,
        'num_tables'         => 1,
        'seats_per_table'    => 8,
        'auto_assign_tables' => 1,
        'bounty_amount'      => 0,
        'bounty_points'      => 0,
        'jackpot_amount'     => 0,
    ];
}

// ─── League progressive jackpot (single fund; hit type is a label) ──
const PK_JACKPOT_HIT_TYPES = ['badbeat' => 'Bad Beat', 'royal' => 'Royal Flush', 'other' => 'Jackpot'];

// Fetch-or-create the league's jackpot fund row.
function pk_jackpot_fund($db, int $league_id): array {
    $q = $db->prepare("SELECT * FROM league_jackpots WHERE league_id = ? AND jackpot_type = 'main'");
    $q->execute([$league_id]);
    if ($row = $q->fetch()) return $row;
    $db->prepare("INSERT INTO league_jackpots (league_id, jackpot_type) VALUES (?, 'main')")->execute([$league_id]);
    $q->execute([$league_id]);
    return $q->fetch();
}

// Fund balance for a league (0 when never funded — no row created).
function pk_jackpot_balance($db, ?int $league_id): int {
    if (!$league_id) return 0;
    try {
        $q = $db->prepare("SELECT balance FROM league_jackpots WHERE league_id = ? AND jackpot_type = 'main'");
        $q->execute([$league_id]);
        $v = $q->fetchColumn();
        return $v === false ? 0 : (int)$v;
    } catch (Exception $e) { return 0; /* pre-migration DB */ }
}

// Upsert a user's last-used session defaults. league_id null = personal scope.
// Security: every column name interpolated into SQL is intersected with the
// USER_SESSION_DEFAULT_COLS whitelist so unknown keys in $data can never reach SQL.
function save_user_session_defaults($db, int $user_id, ?int $league_id, array $data): void {
    $row = [];
    foreach (USER_SESSION_DEFAULT_COLS as $c) {
        if (array_key_exists($c, $data)) $row[$c] = $data[$c];
    }
    if (!$row) return;

    // Defense in depth: only allow whitelisted column names to reach the SQL string.
    $safeCols = array_values(array_intersect(array_keys($row), USER_SESSION_DEFAULT_COLS));
    if (!$safeCols) return;
    $safeRow = [];
    foreach ($safeCols as $c) { $safeRow[$c] = $row[$c]; }

    if ($league_id === null) {
        $sel = $db->prepare('SELECT id FROM user_session_defaults WHERE user_id = ? AND league_id IS NULL');
        $sel->execute([$user_id]);
    } else {
        $sel = $db->prepare('SELECT id FROM user_session_defaults WHERE user_id = ? AND league_id = ?');
        $sel->execute([$user_id, $league_id]);
    }
    $existing = $sel->fetchColumn();

    if ($existing) {
        $set  = implode(',', array_map(fn($c) => "$c = ?", $safeCols));
        $vals = array_values($safeRow);
        $vals[] = (int)$existing;
        $db->prepare("UPDATE user_session_defaults SET $set, updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute($vals);
    } else {
        $cols = array_merge(['user_id', 'league_id'], $safeCols);
        $placeholders = implode(',', array_fill(0, count($cols), '?'));
        $colList = implode(',', $cols);
        $vals = array_merge([$user_id, $league_id], array_values($safeRow));
        $db->prepare("INSERT INTO user_session_defaults ($colList) VALUES ($placeholders)")->execute($vals);
    }
}

// Load a user's last-used defaults. League-scoped first, then personal, then hardcoded.
// $colList is built from the USER_SESSION_DEFAULT_COLS constant, never user input.
function load_user_session_defaults($db, int $user_id, ?int $league_id): array {
    $colList = implode(',', USER_SESSION_DEFAULT_COLS);
    if ($league_id !== null) {
        $q = $db->prepare("SELECT $colList FROM user_session_defaults WHERE user_id = ? AND league_id = ? LIMIT 1");
        $q->execute([$user_id, $league_id]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if ($row) return array_map('intval_or_string', $row);
    }
    $q = $db->prepare("SELECT $colList FROM user_session_defaults WHERE user_id = ? AND league_id IS NULL LIMIT 1");
    $q->execute([$user_id]);
    $row = $q->fetch(PDO::FETCH_ASSOC);
    if ($row) return array_map('intval_or_string', $row);
    return default_session_defaults();
}

// Cast numeric strings to int, leave text (game_type) alone.
function intval_or_string($v) {
    if (is_numeric($v)) return (int)$v;
    return $v;
}

// Verify event ownership (owner, manager, admin, or league owner/manager).
// Exits with 404/403 on failure. Thin wrapper around can_manage_event().
function verify_event_access($db, $event_id, $current, $isAdmin) {
    // 404 for missing event keeps the old contract.
    $stmt = $db->prepare('SELECT 1 FROM events WHERE id = ?');
    $stmt->execute([$event_id]);
    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Event not found']);
        exit;
    }
    if (!can_manage_event($db, (int)$event_id, (int)$current['id'], (bool)$isAdmin)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Access denied']);
        exit;
    }
}

// Check if user has event access without exiting (returns true/false).
function check_event_access($db, $event_id, $current, $isAdmin) {
    return can_manage_event($db, (int)$event_id, (int)$current['id'], (bool)$isAdmin);
}

// Poker events the user can manage (active games first, then most recent).
// Used to let a standalone timer be linked to an event so its data syncs.
function user_poker_events($db, $user_id, $is_admin = false) {
    $order = "ORDER BY CASE ps.status WHEN 'active' THEN 0 WHEN 'setup' THEN 1 ELSE 2 END, e.start_date DESC, e.id DESC LIMIT 50";
    if ($is_admin) {
        $stmt = $db->prepare("SELECT e.id, e.title, e.start_date, ps.status AS session_status
                              FROM events e JOIN poker_sessions ps ON ps.event_id = e.id $order");
        $stmt->execute();
    } else {
        if ((int)$user_id <= 0) return [];
        $stmt = $db->prepare("SELECT e.id, e.title, e.start_date, ps.status AS session_status
            FROM events e JOIN poker_sessions ps ON ps.event_id = e.id
            WHERE e.created_by = ?
               OR EXISTS (SELECT 1 FROM event_invites ei JOIN users u ON LOWER(u.username) = LOWER(ei.username)
                          WHERE ei.event_id = e.id AND u.id = ? AND ei.event_role = 'manager')
               OR EXISTS (SELECT 1 FROM league_members lm
                          WHERE lm.league_id = e.league_id AND lm.user_id = ? AND lm.role IN ('owner','manager'))
            $order");
        $stmt->execute([$user_id, $user_id, $user_id]);
    }
    return $stmt->fetchAll();
}

// Verify session access via player_id
function get_session_from_player($db, $player_id) {
    $stmt = $db->prepare('SELECT ps.* FROM poker_players pp JOIN poker_sessions ps ON pp.session_id = ps.id WHERE pp.id = ?');
    $stmt->execute([$player_id]);
    return $stmt->fetch();
}

// Calculate pool stats for a session
function calc_pool($db, $session_id) {
    $sess = $db->prepare('SELECT buyin_amount, rebuy_amount, addon_amount, starting_chips, addon_chips, game_type, bounty_amount, jackpot_amount FROM poker_sessions WHERE id = ?');
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
        SUM(COALESCE(cash_in, 0)) as total_cash_in,
        SUM(COALESCE(jackpot_in, 0)) as jackpot_entries
    FROM poker_players WHERE session_id = ? AND removed = 0');
    $stats->execute([$session_id]);
    $r = $stats->fetch();

    $bounty_withheld = 0;
    $ticket_withheld = 0;
    $ticket_in       = 0;
    if ($s['game_type'] === 'cash') {
        $pool_total = (int)$r['total_cash_in'];
        $buyin_total = $pool_total;
        $rebuy_total = 0;
        $addon_total = 0;
        $pool_gross  = $pool_total;
    } else {
        $buyin_total  = (int)$r['total_buyins'] * (int)$s['buyin_amount'];
        $rebuy_total  = (int)$r['total_rebuys'] * (int)$s['rebuy_amount'];
        $addon_total  = (int)$r['total_addons'] * (int)$s['addon_amount'];
        $pool_gross   = $buyin_total + $rebuy_total + $addon_total;

        // Net pool: bounties ride on initial buy-ins only (not rebuys), ticket
        // prize values are withheld for the target event, and tickets redeemed
        // INTO this session add any surplus over this game's buy-in (the buy-in
        // itself is already in pool_gross). Jackpot entries are OPTIONAL side
        // money collected on top of the buy-in — never part of the pool.
        $bounty_withheld = (int)$r['total_buyins'] * (int)($s['bounty_amount'] ?? 0);
        try {
            $tw = $db->prepare('SELECT COALESCE(SUM(ticket_cents), 0) FROM poker_payouts WHERE session_id = ?');
            $tw->execute([$session_id]);
            $ticket_withheld = (int)$tw->fetchColumn();
            $ti = $db->prepare("SELECT COALESCE(SUM(CASE WHEN value_cents > ? THEN value_cents - ? ELSE 0 END), 0)
                                FROM poker_entry_tickets WHERE redeemed_session_id = ? AND status = 'redeemed'");
            $ti->execute([(int)$s['buyin_amount'], (int)$s['buyin_amount'], $session_id]);
            $ticket_in = (int)$ti->fetchColumn();
        } catch (Exception $e) { /* pre-migration DB */ }

        $pool_total = $pool_gross - $bounty_withheld - $ticket_withheld + $ticket_in;
    }

    $jackpot_entries = (int)($r['jackpot_entries'] ?? 0);
    return [
        'pool_gross'        => $pool_gross,
        'bounty_withheld'   => $bounty_withheld,
        'jackpot_entries'   => $jackpot_entries,
        'jackpot_collected' => $jackpot_entries * (int)($s['jackpot_amount'] ?? 0),
        'ticket_withheld'   => $ticket_withheld,
        'ticket_in'         => $ticket_in,
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
        'starting_chips' => (int)($s['starting_chips'] ?? 0),
        'addon_chips'    => (int)($s['addon_chips'] ?? 0),
        'chips_in_play'  => ($s['game_type'] === 'tournament')
            ? ((int)$r['total_buyins'] + (int)$r['total_rebuys']) * (int)($s['starting_chips'] ?? 0)
              + (int)$r['total_addons'] * (int)($s['addon_chips'] ?? 0)
            : 0,
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

// Persist tournament winnings: recompute every player's payout (cents), points,
// and bounty tallies from the session's reward structure and current standings.
// Called whenever standings or the structure change so the poker_players columns
// always match what the screen shows — they're the durable record stats read.
// Idempotent; safe to re-run. No-op for cash games (cash_out is their record).
// NOT covered here: ticket issuance (not recompute-safe) — see pk_finish_session().
function pk_apply_tournament_payouts($db, $session_id) {
    $sess = $db->prepare('SELECT game_type, bounty_amount, bounty_points FROM poker_sessions WHERE id = ?');
    $sess->execute([$session_id]);
    $s = $sess->fetch();
    if (($s['game_type'] ?? '') !== 'tournament') return;
    $bountyAmt = (int)($s['bounty_amount'] ?? 0);
    $bountyPts = (int)($s['bounty_points'] ?? 0);

    $poolTotal = (int)(calc_pool($db, $session_id)['pool_total'] ?? 0);
    $pctByPlace = [];
    $ptsByPlace = [];
    foreach (get_payouts($db, $session_id) as $po) {
        $pctByPlace[(int)$po['place']] = (float)$po['percentage'];
        $ptsByPlace[(int)$po['place']] = (int)($po['points'] ?? 0);
    }

    // Tickets converted back to cash land in the holder's payout so the value
    // isn't lost when a target evaporates; survives every recompute.
    $converted = [];
    try {
        $cv = $db->prepare("SELECT player_id, COALESCE(SUM(value_cents), 0) AS v FROM poker_entry_tickets
                            WHERE source_session_id = ? AND status = 'converted' GROUP BY player_id");
        $cv->execute([$session_id]);
        foreach ($cv->fetchAll() as $row) $converted[(int)$row['player_id']] = (int)$row['v'];
    } catch (Exception $e) { /* pre-migration DB */ }

    // Knockout counts per eliminator.
    $koCount = [];
    $ko = $db->prepare('SELECT eliminated_by, COUNT(*) AS n FROM poker_players
                        WHERE session_id = ? AND removed = 0 AND eliminated = 1 AND eliminated_by IS NOT NULL
                        GROUP BY eliminated_by');
    $ko->execute([$session_id]);
    foreach ($ko->fetchAll() as $row) $koCount[(int)$row['eliminated_by']] = (int)$row['n'];

    $players = $db->prepare('SELECT id, finish_position, payout, points, bounties_won, bounty_cash, bought_in
                             FROM poker_players WHERE session_id = ? AND removed = 0');
    $players->execute([$session_id]);
    $upd = $db->prepare('UPDATE poker_players SET payout = ?, points = ?, bounties_won = ?, bounty_cash = ? WHERE id = ?');
    foreach ($players->fetchAll() as $p) {
        $pid = (int)$p['id'];
        $pos = (int)($p['finish_position'] ?? 0);
        $amt = ($pos > 0 && isset($pctByPlace[$pos])) ? (int)round($poolTotal * $pctByPlace[$pos] / 100) : 0;
        $amt += $converted[$pid] ?? 0;
        $kos = $koCount[$pid] ?? 0;
        // Winner keeps their own bounty: (buyins × bounty) = (buyins−1) KOs + winner's own.
        $bcash = $kos * $bountyAmt + (($pos === 1 && (int)$p['bought_in'] === 1) ? $bountyAmt : 0);
        $pts = (($pos > 0) ? ($ptsByPlace[$pos] ?? 0) : 0) + $kos * $bountyPts;
        if ((int)$p['payout'] !== $amt || (int)$p['points'] !== $pts
            || (int)$p['bounties_won'] !== $kos || (int)$p['bounty_cash'] !== $bcash) {
            $upd->execute([$amt, $pts, $kos, $bcash, $pid]);
        }
    }
}

// A ticket holder is automatically invited to the target event (RSVP yes,
// approved — the awarded seat IS the invitation). No-op if an invite for that
// name already exists. Contact info comes from users (registered) or the
// source event's invite row (guests) so reminders can reach them.
function pk_ticket_ensure_invite($db, int $target_event_id, ?int $user_id, string $display_name, ?int $source_event_id): void {
    if ($target_event_id <= 0 || $display_name === '') return;
    try {
        $chk = $db->prepare('SELECT id FROM event_invites WHERE event_id = ? AND LOWER(username) = LOWER(?) AND occurrence_date IS NULL');
        $chk->execute([$target_event_id, $display_name]);
        if ($chk->fetch()) return;

        $phone = null; $email = null; $username = $display_name;
        if ($user_id) {
            $u = $db->prepare('SELECT username, phone, email FROM users WHERE id = ?');
            $u->execute([$user_id]);
            if ($ur = $u->fetch()) { $username = $ur['username']; $phone = $ur['phone'] ?: null; $email = $ur['email'] ?: null; }
        } elseif ($source_event_id) {
            $s = $db->prepare('SELECT phone, email FROM event_invites WHERE event_id = ? AND LOWER(username) = LOWER(?) LIMIT 1');
            $s->execute([$source_event_id, $display_name]);
            if ($sr = $s->fetch()) { $phone = $sr['phone'] ?: null; $email = $sr['email'] ?: null; }
        }
        $db->prepare("INSERT INTO event_invites (event_id, username, phone, email, rsvp, event_role, approval_status)
                      VALUES (?, ?, ?, ?, 'yes', 'invitee', 'approved')")
           ->execute([$target_event_id, $username, $phone, $email]);
    } catch (Exception $e) { /* invite is best-effort; the ticket itself is the record */ }
}

// Finish hook shared by the manual Finish button and the last-elimination
// auto-finish: locks in the recompute, then issues entry tickets exactly once
// (guarded per (source_session, place) so a re-finish never double-issues).
function pk_finish_session($db, int $session_id, int $actor_id): void {
    pk_apply_tournament_payouts($db, $session_id);

    $sess = $db->prepare('SELECT ps.game_type, ps.ticket_target_event_id, ps.jackpot_amount,
                                 e.title AS src_title, e.league_id
                          FROM poker_sessions ps JOIN events e ON e.id = ps.event_id WHERE ps.id = ?');
    $sess->execute([$session_id]);
    $s = $sess->fetch();
    if (!$s || $s['game_type'] !== 'tournament') return;

    // Jackpot contribution: entries × price (optional side purchase per player,
    // collected on top of the buy-in), once per finish (guarded so a re-finish
    // never double-contributes).
    $per = (int)($s['jackpot_amount'] ?? 0);
    if (!empty($s['league_id']) && $per > 0) {
        $bq = $db->prepare('SELECT COALESCE(SUM(jackpot_in), 0) FROM poker_players WHERE session_id = ? AND removed = 0');
        $bq->execute([$session_id]);
        $buyins = (int)$bq->fetchColumn();
        if ($buyins > 0) {
            try {
                $fund = pk_jackpot_fund($db, (int)$s['league_id']);
                $dupe = $db->prepare("SELECT COUNT(*) FROM league_jackpot_log
                                      WHERE jackpot_id = ? AND session_id = ? AND event_type = 'contribution'");
                $dupe->execute([(int)$fund['id'], $session_id]);
                if ((int)$dupe->fetchColumn() === 0) {
                    $amt = $buyins * $per;
                    $db->prepare('INSERT INTO league_jackpot_log (jackpot_id, session_id, event_type, amount, detail, created_by)
                                  VALUES (?, ?, ?, ?, ?, ?)')
                       ->execute([(int)$fund['id'], $session_id, 'contribution', $amt,
                                  "$buyins entries × " . pk_money($per) . ' from "' . $s['src_title'] . '"', $actor_id]);
                    $db->prepare('UPDATE league_jackpots SET balance = balance + ? WHERE id = ?')->execute([$amt, (int)$fund['id']]);
                    pk_log($db, $session_id, $actor_id, 'jackpot', null, null, $amt,
                           'Jackpot contribution — ' . pk_money($amt) . " ($buyins entries × " . pk_money($per) . ')');
                }
            } catch (Exception $e) { /* pre-migration DB */ }
        }
    }
    $target = (int)($s['ticket_target_event_id'] ?? 0);
    if ($target <= 0) return;
    $tq = $db->prepare('SELECT id, title, start_date FROM events WHERE id = ?');
    $tq->execute([$target]);
    $tev = $tq->fetch();
    if (!$tev) return;  // target vanished before finish: nothing to issue against

    $places = $db->prepare('SELECT place, ticket_cents FROM poker_payouts WHERE session_id = ? AND ticket_cents > 0');
    $places->execute([$session_id]);
    foreach ($places->fetchAll() as $pl) {
        $place = (int)$pl['place'];
        $value = (int)$pl['ticket_cents'];
        $winner = $db->prepare('SELECT id, user_id, display_name FROM poker_players
                                WHERE session_id = ? AND removed = 0 AND finish_position = ? LIMIT 1');
        $winner->execute([$session_id, $place]);
        $w = $winner->fetch();
        if (!$w) continue;
        $dupe = $db->prepare("SELECT COUNT(*) FROM poker_entry_tickets
                              WHERE source_session_id = ? AND source_place = ? AND status != 'converted'");
        $dupe->execute([$session_id, $place]);
        if ((int)$dupe->fetchColumn() > 0) continue;

        $db->prepare('INSERT INTO poker_entry_tickets
                        (source_session_id, source_place, player_id, user_id, display_name, target_event_id, value_cents)
                      VALUES (?, ?, ?, ?, ?, ?, ?)')
           ->execute([$session_id, $place, (int)$w['id'], $w['user_id'] ?: null, (string)$w['display_name'], $target, $value]);
        pk_log($db, $session_id, $actor_id, 'ticket_issue', (int)$w['id'], (string)$w['display_name'], $value,
               'Entry ticket to "' . $tev['title'] . '" (' . $tev['start_date'] . ') — ' . pk_money($value) . ' for ' . pk_ordinal($place));

        // The seat comes with the guest list spot: auto-invite at the target.
        $srcEv = $db->prepare('SELECT event_id FROM poker_sessions WHERE id = ?');
        $srcEv->execute([$session_id]);
        pk_ticket_ensure_invite($db, $target, $w['user_id'] ? (int)$w['user_id'] : null,
                                (string)$w['display_name'], (int)$srcEv->fetchColumn());

        if (!empty($w['user_id'])) {
            require_once __DIR__ . '/_notifications.php';
            notify_user_direct($db, (int)$w['user_id'], 'reward_ticket',
                'You won a seat: ' . $tev['title'],
                'Your ' . pk_ordinal($place) . ' place finish in "' . $s['src_title'] . '" won a ' . pk_money($value)
                    . ' entry ticket to "' . $tev['title'] . '" on ' . $tev['start_date'] . '. Show the host at buy-in.',
                '/event.php?id=' . $target,
                'You won a ' . pk_money($value) . ' entry to "' . $tev['title'] . '" (' . $tev['start_date'] . ')! ' . get_site_url() . '/event.php?id=' . $target);
        }
    }
}

// Reopen hook: a finished game can only reopen if none of its tickets were
// redeemed at the target yet. Issued tickets are deleted (and re-issued on the
// next finish); converted ones survive so their cash stays in the recompute.
// Returns ['ok' => true] or ['ok' => false, 'error' => ...].
function pk_unfinish_session($db, int $session_id, int $actor_id): array {
    try {
        $q = $db->prepare("SELECT COUNT(*) FROM poker_entry_tickets WHERE source_session_id = ? AND status = 'redeemed'");
        $q->execute([$session_id]);
        if ((int)$q->fetchColumn() > 0) {
            return ['ok' => false, 'error' => 'A ticket from this game was already redeemed at its target event. Resolve it there before reopening.'];
        }
        // Reverse this session's jackpot contributions so a re-finish re-adds
        // them from the (possibly changed) final buy-in count. Guard first: if
        // a hit already paid those contributions out, the money is physically
        // gone and reversing would overdraw the fund — block the reopen.
        $jc = $db->prepare("SELECT l.id, l.jackpot_id, l.amount FROM league_jackpot_log l
                            WHERE l.session_id = ? AND l.event_type = 'contribution'");
        $jc->execute([$session_id]);
        $jrows = $jc->fetchAll();
        foreach ($jrows as $row) {
            $bal = $db->prepare('SELECT balance FROM league_jackpots WHERE id = ?');
            $bal->execute([(int)$row['jackpot_id']]);
            if ((int)$bal->fetchColumn() < (int)$row['amount']) {
                return ['ok' => false, 'error' => 'A jackpot hit already paid out this game\'s contributions — the fund would go negative. Resolve the jackpot before reopening.'];
            }
        }
        foreach ($jrows as $row) {
            $db->prepare('UPDATE league_jackpots SET balance = balance - ? WHERE id = ?')
               ->execute([(int)$row['amount'], (int)$row['jackpot_id']]);
            $db->prepare('DELETE FROM league_jackpot_log WHERE id = ?')->execute([(int)$row['id']]);
            pk_log($db, $session_id, $actor_id, 'jackpot', null, null, -(int)$row['amount'],
                   'Jackpot contribution reversed (game reopened) — ' . pk_money((int)$row['amount']));
        }
        $q = $db->prepare("SELECT id, player_id, display_name, value_cents FROM poker_entry_tickets
                           WHERE source_session_id = ? AND status = 'issued'");
        $q->execute([$session_id]);
        foreach ($q->fetchAll() as $t) {
            $db->prepare('DELETE FROM poker_entry_tickets WHERE id = ?')->execute([(int)$t['id']]);
            pk_log($db, $session_id, $actor_id, 'ticket_void', (int)$t['player_id'], (string)$t['display_name'],
                   -(int)$t['value_cents'], 'Entry ticket voided (game reopened) — ' . pk_money((int)$t['value_cents']));
        }
    } catch (Exception $e) { /* pre-migration DB */ }
    return ['ok' => true];
}

// ─── Per-session activity log ──────────────────────────────
// Format cents as a short dollar string, dropping ".00" on whole amounts
// (matches the front-end formatMoney() in checkin.php).
function pk_money($cents): string {
    $v = (int)$cents / 100;
    return '$' . ($v == (int)$v ? number_format($v, 0) : number_format($v, 2));
}

// Format an integer place as an ordinal (1 -> 1st, 2 -> 2nd, 3 -> 3rd …).
function pk_ordinal(int $n): string {
    $abs = abs($n) % 100;
    if ($abs >= 11 && $abs <= 13) return $n . 'th';
    switch (abs($n) % 10) {
        case 1: return $n . 'st';
        case 2: return $n . 'nd';
        case 3: return $n . 'rd';
        default: return $n . 'th';
    }
}

// Append one entry to a session's activity log. Append-only — never updated
// or deleted. $amount is in cents where relevant (else null); $detail is a
// pre-formatted human-readable sentence.
function pk_log($db, int $session_id, ?int $user_id, string $event_type, ?int $player_id, ?string $player_name, ?int $amount, string $detail): void {
    // Strip control chars to prevent log injection (same as db_log_activity).
    $detail = preg_replace('/[\x00-\x1F\x7F]/', '', $detail);
    $stmt = $db->prepare(
        'INSERT INTO poker_session_log (session_id, user_id, event_type, player_id, player_name, amount, detail)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$session_id, $user_id, $event_type, $player_id, $player_name, $amount, $detail]);
}

// Read a session's log newest-first, with created_at converted from UTC to the
// site timezone and formatted for display (mirrors admin_activity_snapshot).
function get_session_log($db, int $session_id, int $limit = 200): array {
    $stmt = $db->prepare(
        'SELECT psl.id, psl.event_type, psl.player_id, psl.player_name, psl.amount,
                psl.detail, psl.created_at, psl.voided, u.username AS actor
         FROM poker_session_log psl
         LEFT JOIN users u ON u.id = psl.user_id AND psl.user_id > 0
         WHERE psl.session_id = ? ORDER BY psl.id DESC LIMIT ?'
    );
    $stmt->execute([$session_id, $limit]);
    return _pk_log_decorate($stmt->fetchAll(PDO::FETCH_ASSOC));
}

// Money/buy-in entries for ONE player, for the per-player ledger modal. Newest-first,
// includes voided rows (shown struck-through). Only the entry types a manager can clear.
const PK_LEDGER_TYPES = ['buyin', 'cashin', 'rebuy', 'addon', 'cashout'];
function get_player_ledger($db, int $session_id, int $player_id): array {
    $ph = implode(',', array_fill(0, count(PK_LEDGER_TYPES), '?'));
    $stmt = $db->prepare(
        "SELECT psl.id, psl.event_type, psl.amount, psl.detail, psl.created_at, psl.voided,
                u.username AS actor
         FROM poker_session_log psl
         LEFT JOIN users u ON u.id = psl.user_id AND psl.user_id > 0
         WHERE psl.session_id = ? AND psl.player_id = ? AND psl.event_type IN ($ph)
         ORDER BY psl.id DESC"
    );
    $stmt->execute(array_merge([$session_id, $player_id], PK_LEDGER_TYPES));
    return _pk_log_decorate($stmt->fetchAll(PDO::FETCH_ASSOC));
}

// Shared: add a display `time` (UTC -> site tz) and a non-empty `actor` to log rows.
function _pk_log_decorate(array $rows): array {
    $utc = new DateTimeZone('UTC');
    try { $tz = new DateTimeZone(get_setting('timezone', 'UTC')); } catch (Throwable $e) { $tz = $utc; }
    $out = [];
    foreach ($rows as $r) {
        try {
            $dt = new DateTime((string)$r['created_at'], $utc);
            $time = (clone $dt)->setTimezone($tz)->format('g:i A'); // server-tz fallback
            $r['time_ts'] = $dt->format('c');                       // UTC ISO8601 for client-local rendering
        } catch (Throwable $e) {
            $time = (string)$r['created_at'];
            $r['time_ts'] = null;
        }
        $r['time'] = $time;
        $r['actor'] = $r['actor'] ?: 'system';
        $out[] = $r;
    }
    return $out;
}
