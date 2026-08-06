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

    // Entry tickets touching this session: incoming = issued tickets targeting
    // this event (redeemable at buy-in); outgoing = tickets this game awarded.
    $tin = $db->prepare("SELECT id, user_id, display_name, value_cents, source_session_id FROM poker_entry_tickets
                         WHERE target_event_id = ? AND status = 'issued'");
    $tin->execute([(int)$session['event_id']]);
    $tout = $db->prepare("SELECT t.*, e.title AS target_title, e.start_date AS target_date
                          FROM poker_entry_tickets t LEFT JOIN events e ON e.id = t.target_event_id
                          WHERE t.source_session_id = ? ORDER BY t.source_place, t.id");
    $tout->execute([(int)$session['id']]);

    $evLeague = $db->prepare('SELECT league_id FROM events WHERE id = ?');
    $evLeague->execute([(int)$session['event_id']]);
    $league_id = (int)$evLeague->fetchColumn() ?: null;

    echo json_encode([
        'ok'       => true,
        'session'  => $session,
        'players'  => get_players($db, $session['id']),
        'payouts'  => get_payouts($db, $session['id']),
        'pool'     => calc_pool($db, $session['id']),
        'log'      => get_session_log($db, (int)$session['id']),
        'tickets'  => ['incoming' => $tin->fetchAll(), 'outgoing' => $tout->fetchAll()],
        'jackpots' => ['league_id' => $league_id, 'balance' => pk_jackpot_balance($db, $league_id)],
    ]);
    exit;
}

// ─── GET: get_log ──────────────────────────────────────────
// Returns a session's append-only activity log, newest-first. Hosts/admins only.
if ($action === 'get_log') {
    $event_id = (int)($_GET['event_id'] ?? $_POST['event_id'] ?? 0);
    verify_event_access($db, $event_id, $current, $isAdmin);

    $stmt = $db->prepare('SELECT id FROM poker_sessions WHERE event_id = ?');
    $stmt->execute([$event_id]);
    $session_id = (int)($stmt->fetchColumn() ?: 0);
    echo json_encode(['ok' => true, 'log' => $session_id ? get_session_log($db, $session_id) : []]);
    exit;
}

// ─── get_ledger ────────────────────────────────────────────
// Per-player money/buy-in history for the ledger modal. Host/admin only.
if ($action === 'get_ledger') {
    $player_id = (int)($_GET['player_id'] ?? $_POST['player_id'] ?? 0);
    $session = get_session_from_player($db, $player_id);
    if (!$session) { echo json_encode(['ok' => false, 'error' => 'Player not found']); exit; }
    verify_event_access($db, $session['event_id'], $current, $isAdmin);
    echo json_encode(['ok' => true, 'ledger' => get_player_ledger($db, (int)$session['id'], $player_id)]);
    exit;
}

// ─── void_ledger_entry ─────────────────────────────────────
// Clear a bad money entry: keep the row (struck-through) and reverse its effect.
if ($action === 'void_ledger_entry') {
    // This mutating action is dispatched above the shared POST+CSRF gate below, so
    // it must enforce its own — otherwise a cross-site form could void ledger money
    // entries in a logged-in host's session.
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
        exit;
    }
    $entry_id = (int)($_POST['entry_id'] ?? 0);
    $erow = $db->prepare('SELECT * FROM poker_session_log WHERE id = ?');
    $erow->execute([$entry_id]);
    $entry = $erow->fetch();
    if (!$entry) { echo json_encode(['ok' => false, 'error' => 'Entry not found']); exit; }
    if ((int)$entry['voided'] === 1) { echo json_encode(['ok' => false, 'error' => 'Already cleared']); exit; }
    if (!in_array($entry['event_type'], PK_LEDGER_TYPES, true)) {
        echo json_encode(['ok' => false, 'error' => 'This entry cannot be cleared']); exit;
    }
    $player_id = (int)$entry['player_id'];
    $session = get_session_from_player($db, $player_id);
    if (!$session || (int)$session['id'] !== (int)$entry['session_id']) {
        echo json_encode(['ok' => false, 'error' => 'Player not found']); exit;
    }
    verify_event_access($db, $session['event_id'], $current, $isAdmin);

    $amt = (int)$entry['amount'];
    switch ($entry['event_type']) {
        case 'cashin':
            // cash_in is a running total of signed deltas; reverse this one.
            $db->prepare('UPDATE poker_players SET cash_in = MAX(0, COALESCE(cash_in,0) - ?) WHERE id = ?')
               ->execute([$amt, $player_id]);
            break;
        case 'cashout':
            $cur = $db->prepare('SELECT cash_out FROM poker_players WHERE id = ?');
            $cur->execute([$player_id]);
            $newCo = (int)($cur->fetchColumn() ?? 0) - $amt;
            if ($newCo > 0) {
                $db->prepare('UPDATE poker_players SET cash_out = ? WHERE id = ?')->execute([$newCo, $player_id]);
            } else {
                // Fully un-cashed-out: back in play, re-seat them.
                $db->prepare('UPDATE poker_players SET cash_out = NULL WHERE id = ?')->execute([$player_id]);
                auto_assign_table($db, (int)$session['id'], $player_id);
            }
            break;
        case 'buyin':
            // Tournament buy-in: undo it (also frees the seat).
            $db->prepare('UPDATE poker_players SET bought_in = 0, table_number = NULL, seat_number = NULL WHERE id = ?')
               ->execute([$player_id]);
            break;
        case 'rebuy':
            $db->prepare('UPDATE poker_players SET rebuys = MAX(0, rebuys - ?) WHERE id = ?')
               ->execute([$amt >= 0 ? 1 : -1, $player_id]);
            break;
        case 'addon':
            $db->prepare('UPDATE poker_players SET addons = MAX(0, addons - ?) WHERE id = ?')
               ->execute([$amt >= 0 ? 1 : -1, $player_id]);
            break;
    }

    $db->prepare('UPDATE poker_session_log SET voided = 1, voided_by = ?, voided_at = CURRENT_TIMESTAMP WHERE id = ?')
       ->execute([(int)$current['id'], $entry_id]);
    pk_log($db, (int)$session['id'], (int)$current['id'], 'void', $player_id, $entry['player_name'] ?? '', null,
           'Cleared: ' . (string)$entry['detail']);

    $p = $db->prepare('SELECT * FROM poker_players WHERE id = ?');
    $p->execute([$player_id]);
    echo json_encode([
        'ok'     => true,
        'player' => $p->fetch(),
        'pool'   => calc_pool($db, (int)$session['id']),
        'ledger' => get_player_ledger($db, (int)$session['id'], $player_id),
    ]);
    exit;
}

// ─── edit_ledger_entry ─────────────────────────────────────
// Correct a wrong money amount IN PLACE (e.g. a cash-in of $189 typed as $180),
// keeping the entry in its original sequence position. Only cash in/out entries
// carry an editable dollar amount; the player's running total is adjusted by the
// delta and an audit row records the before/after. Host/admin only.
if ($action === 'edit_ledger_entry') {
    // Dispatched above the shared POST+CSRF gate below — enforce its own so a
    // cross-site form cannot rewrite ledger dollar amounts in a host's session.
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
        exit;
    }
    $entry_id   = (int)($_POST['entry_id'] ?? 0);
    $new_amount = (int)round(floatval($_POST['new_amount'] ?? 0) * 100); // dollars -> cents
    if ($new_amount <= 0) { echo json_encode(['ok' => false, 'error' => 'Enter an amount greater than zero']); exit; }

    $erow = $db->prepare('SELECT * FROM poker_session_log WHERE id = ?');
    $erow->execute([$entry_id]);
    $entry = $erow->fetch();
    if (!$entry) { echo json_encode(['ok' => false, 'error' => 'Entry not found']); exit; }
    if ((int)$entry['voided'] === 1) { echo json_encode(['ok' => false, 'error' => 'Cleared entries cannot be edited']); exit; }
    if (!in_array($entry['event_type'], ['cashin', 'cashout'], true)) {
        echo json_encode(['ok' => false, 'error' => 'Only cash in/out amounts can be edited']); exit;
    }
    $old_amount = (int)$entry['amount'];
    if ($old_amount <= 0) { echo json_encode(['ok' => false, 'error' => 'This entry cannot be edited']); exit; }

    $player_id = (int)$entry['player_id'];
    $session = get_session_from_player($db, $player_id);
    if (!$session || (int)$session['id'] !== (int)$entry['session_id']) {
        echo json_encode(['ok' => false, 'error' => 'Player not found']); exit;
    }
    verify_event_access($db, $session['event_id'], $current, $isAdmin);

    $delta = $new_amount - $old_amount; // apply the correction to the running total
    if ($entry['event_type'] === 'cashin') {
        $db->prepare('UPDATE poker_players SET cash_in = MAX(0, COALESCE(cash_in,0) + ?) WHERE id = ?')
           ->execute([$delta, $player_id]);
        $newDetail = 'Cash in — ' . pk_money($new_amount) . ' (edited)';
    } else { // cashout
        $db->prepare('UPDATE poker_players SET cash_out = MAX(0, COALESCE(cash_out,0) + ?) WHERE id = ?')
           ->execute([$delta, $player_id]);
        $newDetail = 'Cashed out — ' . pk_money($new_amount) . ' (edited)';
    }
    $db->prepare('UPDATE poker_session_log SET amount = ?, detail = ? WHERE id = ?')
       ->execute([$new_amount, $newDetail, $entry_id]);
    pk_log($db, (int)$session['id'], (int)$current['id'], 'edit', $player_id, $entry['player_name'] ?? '', null,
           'Edited ' . ($entry['event_type'] === 'cashin' ? 'cash in' : 'cash out') .
           ': ' . pk_money($old_amount) . ' → ' . pk_money($new_amount));

    $p = $db->prepare('SELECT * FROM poker_players WHERE id = ?');
    $p->execute([$player_id]);
    echo json_encode([
        'ok'     => true,
        'player' => $p->fetch(),
        'pool'   => calc_pool($db, (int)$session['id']),
        'ledger' => get_player_ledger($db, (int)$session['id'], $player_id),
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
    $placesStmt = $db->prepare('SELECT place, percentage, points, ticket_cents, prize_label FROM payout_structure_places WHERE structure_id = ? ORDER BY place');
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

// ─── GET: list_target_events ───────────────────────────────
// Poker events the caller manages whose game hasn't finished — candidates for
// a satellite's ticket target (and for re-targeting an orphaned ticket).
if ($action === 'list_target_events') {
    $exclude = (int)($_GET['exclude_event_id'] ?? 0);
    $rows = array_values(array_filter(
        user_poker_events($db, (int)$current['id'], $isAdmin),
        fn($e) => ($e['session_status'] ?? '') !== 'finished' && (int)$e['id'] !== $exclude
    ));
    echo json_encode(['ok' => true, 'events' => $rows]);
    exit;
}

// ─── GET: jackpot_log ──────────────────────────────────────
// The league jackpot's ledger (newest first), for the 💎 modal's history view.
if ($action === 'jackpot_log') {
    $session_id = (int)($_GET['session_id'] ?? 0);
    $sess = $db->prepare('SELECT ps.*, e.league_id FROM poker_sessions ps JOIN events e ON e.id = ps.event_id WHERE ps.id = ?');
    $sess->execute([$session_id]);
    $s = $sess->fetch();
    if (!$s) { echo json_encode(['ok' => false, 'error' => 'Session not found']); exit; }
    if (!pk_can_manage_league_money($db, (int)($s['league_id'] ?? 0), (int)$current['id'], $isAdmin)) {
        http_response_code(403); echo json_encode(['ok' => false, 'error' => 'Access denied']); exit;
    }
    if (empty($s['league_id'])) { echo json_encode(['ok' => true, 'entries' => []]); exit; }
    // Read-only endpoint: use the non-creating balance reader. pk_jackpot_fund()
    // upserts, so this GET (no CSRF) could be induced to create a fund row and
    // make a "💎 Jackpot $0" badge appear on a league that never had one.
    $fundQ = $db->prepare("SELECT id, balance FROM league_jackpots WHERE league_id = ? AND jackpot_type = 'main'");
    $fundQ->execute([(int)$s['league_id']]);
    $fund = $fundQ->fetch();
    if (!$fund) { echo json_encode(['ok' => true, 'entries' => [], 'balance' => 0]); exit; }
    $q = $db->prepare('SELECT id, event_type, player_name, amount, detail, voided, created_at
                       FROM league_jackpot_log WHERE jackpot_id = ? ORDER BY id DESC LIMIT 100');
    $q->execute([(int)$fund['id']]);
    echo json_encode(['ok' => true, 'entries' => $q->fetchAll(),
                      'balance' => (int)$fund['balance']]);
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
        $payIns = $db->prepare('INSERT INTO poker_payouts (session_id, place, percentage, points, ticket_cents, prize_label) VALUES (?, ?, ?, ?, ?, ?)');
        $defRow = $db->query('SELECT id FROM payout_structures WHERE is_default = 1 LIMIT 1')->fetch();
        if ($defRow) {
            $sp = $db->prepare('SELECT place, percentage, points, ticket_cents, prize_label FROM payout_structure_places WHERE structure_id = ? ORDER BY place');
            $sp->execute([(int)$defRow['id']]);
            foreach ($sp->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $payIns->execute([$session_id, (int)$r['place'], (float)$r['percentage'],
                                  (int)($r['points'] ?? 0), (int)($r['ticket_cents'] ?? 0), $r['prize_label'] ?: null]);
            }
        } else {
            $payIns->execute([$session_id, 1, 50.0, 0, 0, null]);
            $payIns->execute([$session_id, 2, 30.0, 0, 0, null]);
            $payIns->execute([$session_id, 3, 20.0, 0, 0, null]);
        }
    }

    $sess = $db->prepare('SELECT * FROM poker_sessions WHERE id = ?');
    $sess->execute([$session_id]);

    db_log_activity((int)$current['id'], "created poker session id=$session_id for event id=$event_id ($game_type)");

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

    // Bounty + jackpot carve-outs come out of the buy-in, so together they can
    // never reach it (a $20 buy-in fully carved leaves no prize pool).
    $new_buyin  = (int)($_POST['buyin_amount'] ?? $s['buyin_amount']);
    $bounty_amt = max(0, (int)($_POST['bounty_amount'] ?? $s['bounty_amount'] ?? 0));
    $bounty_pts = max(0, (int)($_POST['bounty_points'] ?? $s['bounty_points'] ?? 0));
    // Collection modes: baked (carved/withheld from every buy-in) or optional
    // (per-player side purchase on top). Only BAKED money is validated against
    // the buy-in it comes out of.
    $jp_amount  = max(0, (int)($_POST['jackpot_amount'] ?? $s['jackpot_amount'] ?? 0));
    $bounty_opt = (int)($_POST['bounty_optional'] ?? $s['bounty_optional'] ?? 0) ? 1 : 0;
    $jp_opt     = (int)($_POST['jackpot_optional'] ?? $s['jackpot_optional'] ?? 1) ? 1 : 0;
    $baked = (!$bounty_opt ? $bounty_amt : 0) + (!$jp_opt ? $jp_amount : 0);
    if ($game_type === 'tournament' && $baked >= $new_buyin && $baked > 0) {
        echo json_encode(['ok' => false, 'error' => 'Baked-in bounty + jackpot must total less than the buy-in (they come out of it).']); exit;
    }
    if ($jp_amount > 0) {
        $lgq = $db->prepare('SELECT league_id FROM events WHERE id = ?');
        $lgq->execute([(int)$s['event_id']]);
        if (!(int)$lgq->fetchColumn()) {
            echo json_encode(['ok' => false, 'error' => 'Jackpots are league funds — this event must belong to a league first.']); exit;
        }
    }

    // Satellite target: another upcoming poker event the caller can manage.
    $target = array_key_exists('ticket_target_event_id', $_POST)
        ? (int)$_POST['ticket_target_event_id']
        : (int)($s['ticket_target_event_id'] ?? 0);
    if ($target > 0) {
        $tev = $db->prepare('SELECT id FROM events WHERE id = ? AND is_poker = 1 AND id != ?');
        $tev->execute([$target, (int)$s['event_id']]);
        if (!$tev->fetch() || !is_owner_or_manager($db, $target, $current, $isAdmin)) {
            echo json_encode(['ok' => false, 'error' => 'Ticket target must be another upcoming poker event you manage.']); exit;
        }
    } else {
        $target = 0;
    }

    $db->prepare('UPDATE poker_sessions SET buyin_amount=?, rebuy_amount=?, addon_amount=?, rebuy_allowed=?, addon_allowed=?, max_rebuys=?, starting_chips=?, addon_chips=?, num_tables=?, game_type=?, auto_assign_tables=?, seats_per_table=?, bounty_amount=?, bounty_points=?, ticket_target_event_id=?, jackpot_amount=?, bounty_optional=?, jackpot_optional=? WHERE id=?')->execute([
        $new_buyin,
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
        $bounty_amt,
        $bounty_pts,
        $target > 0 ? $target : null,
        $jp_amount,
        $bounty_opt,
        $jp_opt,
        $session_id,
    ]);

    // Bounty changes move the net pool, so re-derive winnings immediately.
    pk_apply_tournament_payouts($db, $session_id);

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
        'bounty_amount'      => (int)($srow['bounty_amount'] ?? 0),
        'bounty_points'      => (int)($srow['bounty_points'] ?? 0),
        'jackpot_amount'     => (int)($srow['jackpot_amount'] ?? 0),
        'bounty_optional'    => (int)($srow['bounty_optional'] ?? 0),
        'jackpot_optional'   => (int)($srow['jackpot_optional'] ?? 1),
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

    // Reopening a finished game: voids issued entry tickets, and is blocked
    // outright once a ticket has been redeemed at its target event.
    if ($s['status'] === 'finished' && $status !== 'finished') {
        $un = pk_unfinish_session($db, $session_id, (int)$current['id']);
        if (!$un['ok']) { echo json_encode(['ok' => false, 'error' => $un['error']]); exit; }
    }

    $db->prepare('UPDATE poker_sessions SET status = ? WHERE id = ?')->execute([$status, $session_id]);
    db_log_activity((int)$current['id'], "set poker session id=$session_id status=$status");

    // Manual finish: lock in winnings from the final standings + issue tickets.
    if ($status === 'finished') {
        pk_finish_session($db, $session_id, (int)$current['id']);
    }

    echo json_encode(['ok' => true, 'status' => $status]);
    exit;
}

// ─── toggle_buyin ──────────────────────────────────────────
if ($action === 'toggle_buyin') {
    $player_id = (int)($_POST['player_id'] ?? 0);
    // set=1 turns the toggle into "ensure bought in": already-in players are a
    // no-op success instead of being flipped OFF. Bulk Buy In uses this so
    // re-running it can never silently reverse someone's buy-in.
    $set_only  = !empty($_POST['set']);
    $session = get_session_from_player($db, $player_id);
    if (!$session) { echo json_encode(['ok' => false, 'error' => 'Player not found']); exit; }
    verify_event_access($db, $session['event_id'], $current, $isAdmin);

    $plName = $db->prepare('SELECT display_name FROM poker_players WHERE id = ?');
    $plName->execute([$player_id]);
    $plRow = $plName->fetch();
    $pname = $plRow['display_name'] ?? '';

    // Atomic toggle
    $db->beginTransaction();
    $pl = $db->prepare('SELECT bought_in FROM poker_players WHERE id = ?');
    $pl->execute([$player_id]);
    $cur = $pl->fetch();
    if ((int)$cur['bought_in'] === 0) {
        $db->prepare('UPDATE poker_players SET bought_in = 1, checked_in = 1 WHERE id = ?')->execute([$player_id]);
        $db->commit();

        // Reality outranks the roster state: the host taking this player's money
        // means they are approved and attending. A pending row is auto-approved
        // (host action = approval intent, same as update_rsvp), and an RSVP of
        // no/none is corrected to yes — previously a "no" RSVP hard-disabled the
        // buy-in checkbox and a real game's pot ran $40 short (July 2026).
        $inv = $db->prepare("SELECT approval_status, rsvp FROM event_invites WHERE event_id = ? AND LOWER(username) = LOWER(?) AND occurrence_date IS NULL");
        $inv->execute([$session['event_id'], $pname]);
        $invRow = $inv->fetch();
        if ($invRow) {
            if (($invRow['approval_status'] ?? 'approved') === 'pending') {
                $db->prepare("UPDATE event_invites SET approval_status = 'approved' WHERE event_id = ? AND LOWER(username) = LOWER(?) AND occurrence_date IS NULL")
                   ->execute([$session['event_id'], $pname]);
                pk_log($db, (int)$session['id'], (int)$current['id'], 'approve', $player_id, $pname, null, 'Auto-approved by buy-in');
            }
            if (($invRow['rsvp'] ?? '') !== 'yes') {
                $db->prepare("UPDATE event_invites SET rsvp = 'yes' WHERE event_id = ? AND LOWER(username) = LOWER(?) AND occurrence_date IS NULL")
                   ->execute([$session['event_id'], $pname]);
                $db->prepare('UPDATE poker_players SET rsvp = ? WHERE id = ?')->execute(['yes', $player_id]);
            }
        }

        auto_assign_table($db, $session['id'], $player_id);
        $amt = (int)$session['buyin_amount'];
        pk_log($db, (int)$session['id'], (int)$current['id'], 'buyin', $player_id, $pname, $amt, 'Bought in — ' . pk_money($amt));

        // Optional entry-ticket redemption: the host confirmed applying a won
        // seat. The buyin ledger row above still records the full buy-in (pool
        // math treats the holder as a normal entrant; calc_pool adds any surplus).
        $ticket_id = (int)($_POST['ticket_id'] ?? 0);
        if ($ticket_id > 0) {
            $tq = $db->prepare("SELECT t.*, u.id AS holder_uid FROM poker_entry_tickets t
                                LEFT JOIN users u ON u.id = t.user_id
                                WHERE t.id = ? AND t.status = 'issued' AND t.target_event_id = ?");
            $tq->execute([$ticket_id, (int)$session['event_id']]);
            $t = $tq->fetch();
            $pRow = $db->prepare('SELECT user_id, display_name FROM poker_players WHERE id = ?');
            $pRow->execute([$player_id]);
            $pr = $pRow->fetch();
            $matches = $t && (
                (!empty($t['user_id']) && (int)$t['user_id'] === (int)($pr['user_id'] ?? 0))
                || (empty($t['user_id']) && strtolower((string)$t['display_name']) === strtolower((string)($pr['display_name'] ?? '')))
            );
            if ($matches) {
                $db->prepare("UPDATE poker_entry_tickets SET status = 'redeemed', redeemed_session_id = ?, redeemed_player_id = ?, resolved_at = CURRENT_TIMESTAMP, resolved_by = ? WHERE id = ?")
                   ->execute([(int)$session['id'], $player_id, (int)$current['id'], $ticket_id]);
                $value  = (int)$t['value_cents'];
                $detail = 'Entry ticket applied — ' . pk_money($value);
                if ($amt > $value)      $detail .= ' (' . pk_money($amt - $value) . ' cash collected)';
                elseif ($value > $amt)  $detail .= ' (' . pk_money($value - $amt) . ' surplus to pool)';
                pk_log($db, (int)$session['id'], (int)$current['id'], 'ticket_redeem', $player_id, $pname, $value, $detail);
            }
        }
    } elseif ($set_only) {
        // Already bought in and the caller only wants to ensure that — no-op.
        $db->commit();
    } else {
        // Un-buying: clear bought_in and the table assignment
        $db->prepare('UPDATE poker_players SET bought_in = 0, checked_in = 0, table_number = NULL, seat_number = NULL WHERE id = ?')->execute([$player_id]);
        $db->commit();
        pk_log($db, (int)$session['id'], (int)$current['id'], 'unbuyin', $player_id, $pname, null, 'Buy-in reversed');

        // A ticket redeemed by this player in this session flips back to issued
        // so the un-buy is fully reversible.
        try {
            $rt = $db->prepare("SELECT id, display_name, value_cents FROM poker_entry_tickets
                                WHERE redeemed_session_id = ? AND redeemed_player_id = ? AND status = 'redeemed'");
            $rt->execute([(int)$session['id'], $player_id]);
            foreach ($rt->fetchAll() as $t) {
                $db->prepare("UPDATE poker_entry_tickets SET status = 'issued', redeemed_session_id = NULL, redeemed_player_id = NULL, resolved_at = NULL, resolved_by = NULL WHERE id = ?")
                   ->execute([(int)$t['id']]);
                pk_log($db, (int)$session['id'], (int)$current['id'], 'ticket_void', $player_id, $pname,
                       -(int)$t['value_cents'], 'Entry ticket un-applied (buy-in reversed) — ' . pk_money((int)$t['value_cents']));
            }
        } catch (Exception $e) { /* pre-migration DB */ }
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

    $pl = $db->prepare('SELECT rebuys, eliminated, eliminated_by, bounty_in, bounty_claimed, finish_position, display_name
                        FROM poker_players WHERE id = ?');
    $pl->execute([$player_id]);
    $plRow = $pl->fetch();
    $cur = (int)$plRow['rebuys'];
    $newVal = max(0, $cur + $delta);

    // Enforce max_rebuys if set
    if ((int)$session['max_rebuys'] > 0 && $newVal > (int)$session['max_rebuys']) {
        $newVal = (int)$session['max_rebuys'];
    }

    // Rebuy re-entry: a paid rebuy brings an eliminated player back into the
    // game. The recorded knockout is BANKED on the eliminator first (they keep
    // the bounty they collected), then the live elimination link is cleared.
    // The re-entering player's bounty chip resets: in optional mode they must
    // buy a new one (bounty_in → 0), in baked mode their head is marked
    // claimed so it can't pay a second time.
    $reentered = false;
    $reopened  = false;
    $newStatus = $session['status'] ?? null;
    if ($delta > 0 && (int)$plRow['eliminated'] === 1) {
        if ($newVal === $cur) {
            echo json_encode(['ok' => false, 'error' => 'No rebuys remaining — max is ' . (int)$session['max_rebuys'] . '.']); exit;
        }
        $bountyOpt = (int)($session['bounty_optional'] ?? 0) === 1;
        $bAmt      = (int)($session['bounty_amount'] ?? 0);
        $elimBy    = (int)($plRow['eliminated_by'] ?? 0);
        if ($elimBy > 0) {
            // Cash eligibility mirrors the recompute rules at KO time.
            $cashOk = false;
            if ($bAmt > 0) {
                if ($bountyOpt) {
                    $eb = $db->prepare('SELECT bounty_in FROM poker_players WHERE id = ?');
                    $eb->execute([$elimBy]);
                    $cashOk = (int)$plRow['bounty_in'] === 1 && (int)$eb->fetchColumn() === 1;
                } else {
                    $cashOk = (int)($plRow['bounty_claimed'] ?? 0) === 0;
                }
            }
            $db->prepare('UPDATE poker_players SET bounties_banked = bounties_banked + 1,
                          bounty_cash_banked = bounty_cash_banked + ? WHERE id = ?')
               ->execute([$cashOk ? 1 : 0, $elimBy]);
            if ($cashOk) {
                if ($bountyOpt) {
                    $db->prepare('UPDATE poker_players SET bounty_in = 0 WHERE id = ?')->execute([$player_id]);
                } else {
                    $db->prepare('UPDATE poker_players SET bounty_claimed = 1 WHERE id = ?')->execute([$player_id]);
                }
                $en = $db->prepare('SELECT display_name FROM poker_players WHERE id = ?');
                $en->execute([$elimBy]);
                pk_log($db, (int)$session['id'], (int)$current['id'], 'bounty', $elimBy,
                       (string)$en->fetchColumn(), null,
                       'Bounty kept — ' . (string)$plRow['display_name'] . ' re-entered with a rebuy');
            }
        }

        $db->prepare('UPDATE poker_players SET eliminated = 0, finish_position = NULL, eliminated_by = NULL WHERE id = ?')
           ->execute([$player_id]);

        // If the game had auto-finished (e.g. heads-up KO), the re-entry
        // reopens it — same guard-and-rollback as Undo Elim.
        if (($session['status'] ?? '') === 'finished') {
            $un = pk_unfinish_session($db, (int)$session['id'], (int)$current['id']);
            if (!$un['ok']) {
                $db->prepare('UPDATE poker_players SET eliminated = 1, eliminated_by = ?, finish_position = ?, bounty_in = ?, bounty_claimed = ? WHERE id = ?')
                   ->execute([$elimBy ?: null, $plRow['finish_position'] ?: null, (int)$plRow['bounty_in'], (int)($plRow['bounty_claimed'] ?? 0), $player_id]);
                if ($elimBy > 0) {
                    $db->prepare('UPDATE poker_players SET bounties_banked = MAX(0, bounties_banked - 1),
                                  bounty_cash_banked = MAX(0, bounty_cash_banked - ?) WHERE id = ?')
                       ->execute([!empty($cashOk) ? 1 : 0, $elimBy]);
                }
                echo json_encode(['ok' => false, 'error' => $un['error']]); exit;
            }
            $db->prepare('UPDATE poker_players SET finish_position = NULL WHERE session_id = ? AND removed = 0 AND eliminated = 0 AND finish_position IS NOT NULL')->execute([$session['id']]);
            $db->prepare("UPDATE poker_sessions SET status = 'active' WHERE id = ?")->execute([$session['id']]);
            $reopened = true;
            $newStatus = 'active';
        }

        auto_assign_table($db, $session['id'], $player_id);
        $reentered = true;
    }

    $db->prepare('UPDATE poker_players SET rebuys = ? WHERE id = ?')->execute([$newVal, $player_id]);

    $p = $db->prepare('SELECT * FROM poker_players WHERE id = ?');
    $p->execute([$player_id]);
    $prow = $p->fetch();
    if ($newVal !== $cur) {
        $amt = (int)$session['rebuy_amount'];
        $detail = ($newVal > $cur)
            ? 'Rebuy #' . $newVal . ' — ' . pk_money($amt) . ($reentered ? ' — re-entered the game' : '')
            : 'Rebuy removed (now ' . $newVal . ')';
        pk_log($db, (int)$session['id'], (int)$current['id'], 'rebuy', $player_id, $prow['display_name'] ?? '', ($newVal > $cur ? $amt : -$amt), $detail);
    }
    if ($reentered) {
        // Standings changed (their place cleared, KOs re-banked) — re-sync money.
        pk_apply_tournament_payouts($db, (int)$session['id']);
        $p->execute([$player_id]);
        $prow = $p->fetch();
    }
    echo json_encode([
        'ok'        => true,
        'player'    => $prow,
        'reentered' => $reentered,
        'reopened'  => $reopened,
        'status'    => $newStatus,
        'pool'      => calc_pool($db, $session['id']),
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
    $prow = $p->fetch();
    if ($newVal !== $cur) {
        $amt = (int)$session['addon_amount'];
        $detail = ($newVal > $cur)
            ? 'Add-on #' . $newVal . ' — ' . pk_money($amt)
            : 'Add-on removed (now ' . $newVal . ')';
        pk_log($db, (int)$session['id'], (int)$current['id'], 'addon', $player_id, $prow['display_name'] ?? '', ($newVal > $cur ? $amt : -$amt), $detail);
    }
    echo json_encode([
        'ok'     => true,
        'player' => $prow,
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

    // Re-eliminating an already-out player would recompute their place from
    // the wrong remaining-count and silently corrupt standings — use Undo Elim
    // first if the order needs fixing.
    $elimChk = $db->prepare('SELECT eliminated FROM poker_players WHERE id = ?');
    $elimChk->execute([$player_id]);
    if ((int)$elimChk->fetchColumn() === 1) {
        echo json_encode(['ok' => false, 'error' => 'Player is already eliminated. Undo their elimination first to change the order.']); exit;
    }

    // Auto-assign finishing place by elimination order when one isn't supplied:
    // the place is the number of players still in (including the one being knocked out).
    if ($finish_position <= 0) {
        $cnt = $db->prepare('SELECT COUNT(*) FROM poker_players WHERE session_id = ? AND removed = 0 AND eliminated = 0 AND bought_in = 1');
        $cnt->execute([$session['id']]);
        $finish_position = max(1, (int)$cnt->fetchColumn());
    }

    // Optional bounty credit: who scored the knockout. Skippable (0 = unrecorded).
    $eliminated_by = (int)($_POST['eliminated_by'] ?? 0);
    if ($eliminated_by > 0) {
        $ec = $db->prepare('SELECT id, display_name FROM poker_players
                            WHERE id = ? AND session_id = ? AND removed = 0 AND eliminated = 0 AND bought_in = 1 AND id != ?');
        $ec->execute([$eliminated_by, $session['id'], $player_id]);
        $eliminator = $ec->fetch();
        if (!$eliminator) { echo json_encode(['ok' => false, 'error' => 'Eliminator must be another active player.']); exit; }
    } else {
        $eliminator = null;
    }

    $db->prepare('UPDATE poker_players SET eliminated = 1, finish_position = ?, table_number = NULL, seat_number = NULL, eliminated_by = ? WHERE id = ?')
       ->execute([$finish_position, $eliminator ? (int)$eliminator['id'] : null, $player_id]);

    // Heads-up over: if exactly one player remains in, they win (1st place) and the
    // game finishes automatically.
    $winner = null;
    $winnerId = 0;
    $newStatus = $session['status'] ?? null;
    $remain = $db->prepare('SELECT id FROM poker_players WHERE session_id = ? AND removed = 0 AND eliminated = 0 AND bought_in = 1');
    $remain->execute([$session['id']]);
    $remainIds = $remain->fetchAll(PDO::FETCH_COLUMN);
    if (($session['game_type'] ?? '') === 'tournament' && count($remainIds) === 1) {
        $winnerId = (int)$remainIds[0];
        $db->prepare('UPDATE poker_players SET finish_position = 1 WHERE id = ?')->execute([$winnerId]);
        $db->prepare("UPDATE poker_sessions SET status = 'finished' WHERE id = ?")->execute([$session['id']]);
        $newStatus = 'finished';
    }

    // Record winnings so finished standings carry real money, not just places.
    // The auto-finish also issues entry tickets (pk_finish_session).
    if ($newStatus === 'finished' && ($session['status'] ?? '') !== 'finished') {
        pk_finish_session($db, (int)$session['id'], (int)$current['id']);
    } else {
        pk_apply_tournament_payouts($db, (int)$session['id']);
    }

    // Bounty ledger row for the eliminator (recompute already credited the cash).
    if ($eliminator && ((int)($session['bounty_amount'] ?? 0) > 0 || (int)($session['bounty_points'] ?? 0) > 0)) {
        $bAmt = (int)($session['bounty_amount'] ?? 0);
        $bPts = (int)($session['bounty_points'] ?? 0);
        $victim = $db->prepare('SELECT display_name FROM poker_players WHERE id = ?');
        $victim->execute([$player_id]);
        $detail = 'Bounty for KO of ' . (string)$victim->fetchColumn()
                . ($bAmt > 0 ? ' — ' . pk_money($bAmt) : '')
                . ($bPts > 0 ? " (+$bPts pts)" : '');
        pk_log($db, (int)$session['id'], (int)$current['id'], 'bounty',
               (int)$eliminator['id'], (string)$eliminator['display_name'], $bAmt > 0 ? $bAmt : null, $detail);
    }

    if ($winnerId) {
        $w = $db->prepare('SELECT * FROM poker_players WHERE id = ?');
        $w->execute([$winnerId]);
        $winner = $w->fetch();
    }

    $p = $db->prepare('SELECT * FROM poker_players WHERE id = ?');
    $p->execute([$player_id]);
    $prow = $p->fetch();
    pk_log($db, (int)$session['id'], (int)$current['id'], 'eliminate', $player_id, $prow['display_name'] ?? '', null, 'Eliminated (' . pk_ordinal($finish_position) . ')');
    if ($winner) {
        pk_log($db, (int)$session['id'], (int)$current['id'], 'eliminate', (int)$winner['id'], $winner['display_name'] ?? '', null, 'Won — 1st place');
    }
    echo json_encode([
        'ok'     => true,
        'player' => $prow,
        'winner' => $winner,
        'status' => $newStatus,
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

    // Capture pre-undo state: eliminator (for the compensating bounty log and
    // rollback) and finish position (rollback only).
    $before = $db->prepare('SELECT eliminated_by, display_name, finish_position FROM poker_players WHERE id = ?');
    $before->execute([$player_id]);
    $brow = $before->fetch();

    $db->prepare('UPDATE poker_players SET eliminated = 0, finish_position = NULL, eliminated_by = NULL WHERE id = ?')->execute([$player_id]);

    // If this puts more than one player back in, undo any auto-crowned winner and
    // reopen the game (it's no longer over). Reopening voids issued tickets and
    // is blocked once one was redeemed at its target.
    $reopened = false;
    $remain = $db->prepare('SELECT COUNT(*) FROM poker_players WHERE session_id = ? AND removed = 0 AND eliminated = 0 AND bought_in = 1');
    $remain->execute([$session['id']]);
    if ((int)$remain->fetchColumn() > 1) {
        if (($session['status'] ?? '') === 'finished') {
            $un = pk_unfinish_session($db, (int)$session['id'], (int)$current['id']);
            if (!$un['ok']) {
                // Roll the player state back so we don't half-reopen a locked game.
                $db->prepare('UPDATE poker_players SET eliminated = 1, eliminated_by = ?, finish_position = ? WHERE id = ?')
                   ->execute([$brow['eliminated_by'] ?: null, $brow['finish_position'] ?: null, $player_id]);
                echo json_encode(['ok' => false, 'error' => $un['error']]); exit;
            }
        }
        $db->prepare('UPDATE poker_players SET finish_position = NULL WHERE session_id = ? AND removed = 0 AND eliminated = 0 AND finish_position IS NOT NULL')->execute([$session['id']]);
        $reopenStmt = $db->prepare("UPDATE poker_sessions SET status = 'active' WHERE id = ? AND status = 'finished'");
        $reopenStmt->execute([$session['id']]);
        $reopened = $reopenStmt->rowCount() > 0;
    }

    // Compensating bounty log for the former eliminator (recompute below strips
    // their cash/points; this keeps the ledger narrative in step).
    if (!empty($brow['eliminated_by']) && ((int)($session['bounty_amount'] ?? 0) > 0 || (int)($session['bounty_points'] ?? 0) > 0)) {
        $en = $db->prepare('SELECT id, display_name FROM poker_players WHERE id = ?');
        $en->execute([(int)$brow['eliminated_by']]);
        if ($erow = $en->fetch()) {
            $bAmt = (int)($session['bounty_amount'] ?? 0);
            pk_log($db, (int)$session['id'], (int)$current['id'], 'bounty', (int)$erow['id'],
                   (string)$erow['display_name'], $bAmt > 0 ? -$bAmt : null,
                   'Bounty reversed — elimination of ' . (string)$brow['display_name'] . ' undone');
        }
    }

    // Re-sync stored winnings with the new standings (cleared positions go to $0).
    pk_apply_tournament_payouts($db, (int)$session['id']);

    $p = $db->prepare('SELECT * FROM poker_players WHERE id = ?');
    $p->execute([$player_id]);
    $prow = $p->fetch();
    pk_log($db, (int)$session['id'], (int)$current['id'], 'uneliminate', $player_id, $prow['display_name'] ?? '', null, 'Elimination undone — back in');
    echo json_encode([
        'ok'       => true,
        'player'   => $prow,
        'reopened' => $reopened,
        'pool'     => calc_pool($db, $session['id']),
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
           ->execute([$s['event_id'], $existingUser['username'] ?? trim($name), $existingUser['email'] ?? null]);
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

    db_log_activity((int)$current['id'], "added walk-in '$name' (player id=$newId) to poker session id=$session_id");
    pk_log($db, $session_id, (int)$current['id'], 'add', $newId, $name, null, 'Added to roster');

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
        pk_log($db, (int)$session['id'], (int)$current['id'], 'approve', $player_id, $pRow['display_name'], null, 'Approved onto roster');
    } else {
        // Deny: soft-remove from poker roster
        $db->prepare('UPDATE poker_players SET removed = 1 WHERE id = ?')->execute([$player_id]);
        pk_log($db, (int)$session['id'], (int)$current['id'], 'remove', $player_id, $pRow['display_name'], null, 'Denied / removed');
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

    db_log_activity((int)$current['id'], "removed player '" . ($player['display_name'] ?? '') . "' (player id=$player_id) from poker session id=" . (int)$session['id']);
    pk_log($db, (int)$session['id'], (int)$current['id'], 'remove', $player_id, $player['display_name'] ?? '', null, 'Removed from session');

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

    $places      = $_POST['places'] ?? [];
    $percentages = $_POST['percentages'] ?? [];
    $pointsArr   = $_POST['points'] ?? [];
    $ticketsArr  = $_POST['tickets'] ?? [];   // dollars from the form
    $labelsArr   = $_POST['labels'] ?? [];

    // Reject negatives BEFORE summing: only rows with a positive percentage are
    // stored below, so a negative row would be silently dropped after having
    // offset the total — "150 and -50" summed to 100, passed the cap, and left
    // a 150% first place paying more than the pot holds.
    $totalPct = 0;
    $anyTicket = false;
    for ($i = 0; $i < count($percentages); $i++) {
        $pctIn = (float)$percentages[$i];
        if ($pctIn < 0) {
            echo json_encode(['ok' => false, 'error' => 'Payout percentages cannot be negative.']);
            exit;
        }
        $totalPct += $pctIn;
    }
    foreach ($ticketsArr as $t) { if ((float)$t > 0) { $anyTicket = true; break; } }
    if ($totalPct > 100) {
        echo json_encode(['ok' => false, 'error' => 'Payout percentages cannot exceed 100%']);
        exit;
    }
    if ($anyTicket && empty($s['ticket_target_event_id'])) {
        echo json_encode(['ok' => false, 'error' => 'Set a ticket target event in Game Settings before adding ticket prizes.']);
        exit;
    }

    $db->prepare('DELETE FROM poker_payouts WHERE session_id = ?')->execute([$session_id]);
    $ins = $db->prepare('INSERT INTO poker_payouts (session_id, place, percentage, points, ticket_cents, prize_label) VALUES (?, ?, ?, ?, ?, ?)');
    for ($i = 0; $i < count($places); $i++) {
        $place  = (int)$places[$i];
        $pct    = (float)($percentages[$i] ?? 0);
        $pts    = max(0, (int)($pointsArr[$i] ?? 0));
        $ticket = (int)round(((float)($ticketsArr[$i] ?? 0)) * 100);
        $label  = trim((string)($labelsArr[$i] ?? ''));
        // Keep a place that pays out on ANY dimension, not just cash.
        if ($place > 0 && ($pct > 0 || $pts > 0 || $ticket > 0 || $label !== '')) {
            $ins->execute([$session_id, $place, $pct, $pts, $ticket, $label !== '' ? mb_substr($label, 0, 60) : null]);
        }
    }

    db_log_activity((int)$current['id'], "updated payout structure for poker session id=$session_id");

    // Structure changed — re-sync any already-recorded winnings to the new split.
    pk_apply_tournament_payouts($db, $session_id);

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

    // Add to existing cash_in, mark bought_in/checked_in, and clear any cash-out:
    // putting money back in re-activates a busted/cashed-out player (and re-seats them below).
    $db->prepare('UPDATE poker_players SET cash_in = COALESCE(cash_in, 0) + ?, bought_in = 1, checked_in = 1, cash_out = NULL WHERE id = ?')->execute([$amount, $player_id]);
    auto_assign_table($db, $session['id'], $player_id);

    $p = $db->prepare('SELECT * FROM poker_players WHERE id = ?');
    $p->execute([$player_id]);
    $prow = $p->fetch();
    pk_log($db, (int)$session['id'], (int)$current['id'], 'cashin', $player_id, $prow['display_name'] ?? '', $amount, 'Cash in — ' . pk_money($amount));
    echo json_encode([
        'ok'     => true,
        'player' => $prow,
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
    // Capture old total so the ledger entry stores a signed delta (reversible on void).
    $oldCi = $db->prepare('SELECT COALESCE(cash_in,0) FROM poker_players WHERE id = ?');
    $oldCi->execute([$player_id]);
    $oldCashIn = (int)$oldCi->fetchColumn();
    if ($amt > 0) {
        $db->prepare('UPDATE poker_players SET cash_in = ?, bought_in = 1, checked_in = 1 WHERE id = ?')->execute([$amt, $player_id]);
        auto_assign_table($db, $session['id'], $player_id);
    } else {
        $db->prepare('UPDATE poker_players SET cash_in = ? WHERE id = ?')->execute([$amt, $player_id]);
    }

    $p = $db->prepare('SELECT * FROM poker_players WHERE id = ?');
    $p->execute([$player_id]);
    $prow = $p->fetch();
    pk_log($db, (int)$session['id'], (int)$current['id'], 'cashin', $player_id, $prow['display_name'] ?? '', $amt - $oldCashIn, 'Cash in set to ' . pk_money($amt));
    echo json_encode([
        'ok'     => true,
        'player' => $prow,
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

    // Old cash-out (null = 0) so the ledger entry stores a signed delta (reversible on void).
    $oldCo = $db->prepare('SELECT cash_out FROM poker_players WHERE id = ?');
    $oldCo->execute([$player_id]);
    $oldCashOut = (int)($oldCo->fetchColumn() ?? 0);

    if ($cash_out !== null) {
        // Cashing out means leaving the table — free their seat for the next player.
        $db->prepare('UPDATE poker_players SET cash_out = ?, table_number = NULL, seat_number = NULL WHERE id = ?')
           ->execute([$cash_out, $player_id]);
    } else {
        // Cash-out cleared: they are back in play, so give them a seat again.
        $db->prepare('UPDATE poker_players SET cash_out = NULL WHERE id = ?')->execute([$player_id]);
        auto_assign_table($db, $session['id'], $player_id);
    }

    $p = $db->prepare('SELECT * FROM poker_players WHERE id = ?');
    $p->execute([$player_id]);
    $prow = $p->fetch();
    if ($cash_out === null) {
        pk_log($db, (int)$session['id'], (int)$current['id'], 'cashout', $player_id, $prow['display_name'] ?? '', (0 - $oldCashOut), 'Cash-out cleared');
    } else {
        pk_log($db, (int)$session['id'], (int)$current['id'], 'cashout', $player_id, $prow['display_name'] ?? '', ($cash_out - $oldCashOut), 'Cashed out — ' . pk_money($cash_out));
    }
    echo json_encode([
        'ok'     => true,
        'player' => $prow,
        'pool'   => calc_pool($db, $session['id']),
    ]);
    exit;
}

// ─── set_cash_reconcile ────────────────────────────────────
// Save cash-game reconciliation: host tips and the counted cash-box total.
if ($action === 'set_cash_reconcile') {
    $session_id = (int)($_POST['session_id'] ?? 0);
    $sess = $db->prepare('SELECT event_id FROM poker_sessions WHERE id = ?');
    $sess->execute([$session_id]);
    $s = $sess->fetch();
    if (!$s) { echo json_encode(['ok' => false, 'error' => 'Session not found']); exit; }
    verify_event_access($db, (int)$s['event_id'], $current, $isAdmin);

    $tips    = max(0, (int)($_POST['tips'] ?? 0));
    $counted = (isset($_POST['counted']) && $_POST['counted'] !== '') ? max(0, (int)$_POST['counted']) : null;
    $db->prepare('UPDATE poker_sessions SET tips = ?, cash_counted = ? WHERE id = ?')
       ->execute([$tips, $counted, $session_id]);

    $out = $db->prepare('SELECT * FROM poker_sessions WHERE id = ?');
    $out->execute([$session_id]);
    echo json_encode(['ok' => true, 'session' => $out->fetch()]);
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

    // Validate totals. Negatives are rejected before summing — same reason as
    // update_payouts: only positive rows persist, so a negative one would just
    // buy headroom for an over-100% row and bake it into a reusable preset.
    $total = 0.0;
    for ($i = 0; $i < count($percentages); $i++) {
        $pctIn = (float)$percentages[$i];
        if ($pctIn < 0) {
            echo json_encode(['ok' => false, 'error' => 'Payout percentages cannot be negative.']); exit;
        }
        $total += $pctIn;
    }
    if ($total > 100.0 + 0.001) {
        echo json_encode(['ok' => false, 'error' => 'Percentages total ' . number_format($total, 1) . '% — cannot exceed 100%']); exit;
    }

    // Session-level reward recipe rides along with the per-place rows.
    $st_bounty     = max(0, (int)($_POST['bounty_amount'] ?? 0));
    $st_bounty_pts = max(0, (int)($_POST['bounty_points'] ?? 0));
    $st_jackpot    = max(0, (int)($_POST['jackpot_amount'] ?? 0));

    // Game-tab config (buy-in, chips, rebuys/add-ons, tables) rides too, so a
    // preset restores the ENTIRE settings editor. Whitelisted keys only.
    $gc = [];
    foreach (['buyin_amount', 'rebuy_amount', 'addon_amount', 'starting_chips', 'addon_chips',
              'rebuy_allowed', 'max_rebuys', 'addon_allowed', 'num_tables', 'seats_per_table',
              'auto_assign_tables', 'bounty_optional', 'jackpot_optional'] as $k) {
        if (isset($_POST['gc_' . $k])) $gc[$k] = max(0, (int)$_POST['gc_' . $k]);
    }
    $game_config = $gc ? json_encode($gc) : null;

    $pointsArr  = $_POST['points'] ?? [];
    $ticketsArr = $_POST['tickets'] ?? [];
    $labelsArr  = $_POST['labels'] ?? [];
    $rows = [];
    for ($i = 0; $i < count($places); $i++) {
        $pl     = (int)$places[$i];
        $pct    = (float)($percentages[$i] ?? 0);
        $pts    = max(0, (int)($pointsArr[$i] ?? 0));
        $ticket = (int)round(((float)($ticketsArr[$i] ?? 0)) * 100);
        $label  = trim((string)($labelsArr[$i] ?? ''));
        if ($pl > 0 && ($pct > 0 || $pts > 0 || $ticket > 0 || $label !== '')) {
            $rows[] = [$pl, $pct, $pts, $ticket, $label !== '' ? mb_substr($label, 0, 60) : null];
        }
    }
    // An all-zero structure with no reward recipe and no game config is
    // unsaveable — loading it later would be an empty no-op.
    if (!$rows && $st_bounty === 0 && $st_bounty_pts === 0 && $st_jackpot === 0 && $game_config === null) {
        echo json_encode(['ok' => false, 'error' => 'Nothing to save — set at least one payout value, points, prize, bounty, or jackpot entry first.']); exit;
    }

    $db->prepare('INSERT INTO payout_structures (name, created_by, is_global, league_id, bounty_amount, bounty_points, jackpot_amount, game_config) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
       ->execute([$name, (int)$current['id'], $is_global, $league_id, $st_bounty, $st_bounty_pts, $st_jackpot, $game_config]);
    $sid = (int)$db->lastInsertId();

    $ins = $db->prepare('INSERT INTO payout_structure_places (structure_id, place, percentage, points, ticket_cents, prize_label) VALUES (?, ?, ?, ?, ?, ?)');
    foreach ($rows as $r) {
        $ins->execute([$sid, $r[0], $r[1], $r[2], $r[3], $r[4]]);
    }

    db_log_activity((int)$current['id'], "saved payout structure: $name (id=$sid" . ($league_id ? ", league id=$league_id" : ($is_global ? ", global" : "")) . ")");

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

    $stq = $db->prepare('SELECT * FROM payout_structures WHERE id = ?');
    $stq->execute([$structure_id]);
    $struct = $stq->fetch();
    if (!$struct) { echo json_encode(['ok' => false, 'error' => 'Structure not found']); exit; }

    $sp = $db->prepare('SELECT place, percentage, points, ticket_cents, prize_label FROM payout_structure_places WHERE structure_id = ? ORDER BY place');
    $sp->execute([$structure_id]);
    $rows = $sp->fetchAll(PDO::FETCH_ASSOC);
    $hasRecipe = $struct['bounty_amount'] !== null || $struct['bounty_points'] !== null || $struct['jackpot_amount'] !== null;
    if (!$rows && !$hasRecipe && empty($struct['game_config'])) {
        echo json_encode(['ok' => false, 'error' => 'This structure is empty (saved before reward recipes existed) — re-save it with values.']); exit;
    }

    // Game-tab config: applied FIRST so the recipe's bounty guard compares
    // against the preset's buy-in, not the old one. NULL = legacy preset.
    if (!empty($struct['game_config'])) {
        $gc = json_decode((string)$struct['game_config'], true) ?: [];
        $allowed = ['buyin_amount', 'rebuy_amount', 'addon_amount', 'starting_chips', 'addon_chips',
                    'rebuy_allowed', 'max_rebuys', 'addon_allowed', 'num_tables', 'seats_per_table',
                    'auto_assign_tables', 'bounty_optional', 'jackpot_optional'];
        $sets = []; $vals = [];
        foreach ($allowed as $k) {
            if (isset($gc[$k])) { $sets[] = "$k = ?"; $vals[] = max(0, (int)$gc[$k]); }
        }
        if ($sets) {
            $vals[] = $session_id;
            $db->prepare('UPDATE poker_sessions SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($vals);
            // Shrinking the table count displaces seated players, same as update_config.
            if (isset($gc['num_tables']) && (int)$gc['num_tables'] < (int)$s['num_tables']) {
                $db->prepare('UPDATE poker_players SET table_number = NULL, seat_number = NULL WHERE session_id = ? AND table_number > ?')
                   ->execute([$session_id, (int)$gc['num_tables']]);
                if ((int)$gc['num_tables'] > 1) rebalance_tables($db, $session_id);
                else $db->prepare('UPDATE poker_players SET table_number = NULL, seat_number = NULL WHERE session_id = ?')->execute([$session_id]);
            }
            // Refresh the row so downstream guards see the preset's buy-in.
            $sq = $db->prepare('SELECT ps.*, e.created_by FROM poker_sessions ps JOIN events e ON ps.event_id = e.id WHERE ps.id = ?');
            $sq->execute([$session_id]);
            $s = $sq->fetch();
        }
    }

    // Session-level recipe: bounty/jackpot ride with the preset (NULL = legacy
    // structure, leave the session's settings alone). Bounty is carved from
    // the buy-in, so a recipe that would swallow it is refused.
    if ($hasRecipe) {
        $nb  = max(0, (int)$struct['bounty_amount']);
        $nbp = max(0, (int)$struct['bounty_points']);
        $nj  = max(0, (int)$struct['jackpot_amount']);
        if ($nb > 0 && $nb >= (int)$s['buyin_amount']) {
            echo json_encode(['ok' => false, 'error' => 'This structure\'s bounty (' . pk_money($nb) . ') is not less than the buy-in — raise the buy-in first.']); exit;
        }
        $evL = $db->prepare('SELECT league_id FROM events WHERE id = ?');
        $evL->execute([(int)$s['event_id']]);
        if (!(int)$evL->fetchColumn()) $nj = 0;  // jackpots are league funds
        $db->prepare('UPDATE poker_sessions SET bounty_amount = ?, bounty_points = ?, jackpot_amount = ? WHERE id = ?')
           ->execute([$nb, $nbp, $nj, $session_id]);
    }

    // Re-validate the split on the way in. Presets are stored rows that may
    // pre-date the save-side checks (or have been written before they existed),
    // and this path used to apply whatever it found without looking.
    $loadPct = 0.0;
    foreach ($rows as $r) {
        $pctL = (float)$r['percentage'];
        if ($pctL < 0) {
            echo json_encode(['ok' => false, 'error' => 'This structure contains a negative payout percentage — edit and re-save it.']); exit;
        }
        $loadPct += $pctL;
    }
    if ($loadPct > 100.0 + 0.001) {
        echo json_encode(['ok' => false, 'error' => 'This structure\'s payouts total ' . number_format($loadPct, 1) . '% — edit and re-save it before loading.']); exit;
    }

    $db->prepare('DELETE FROM poker_payouts WHERE session_id = ?')->execute([$session_id]);
    $ins = $db->prepare('INSERT INTO poker_payouts (session_id, place, percentage, points, ticket_cents, prize_label) VALUES (?, ?, ?, ?, ?, ?)');
    foreach ($rows as $r) {
        $ins->execute([$session_id, (int)$r['place'], (float)$r['percentage'],
                       (int)($r['points'] ?? 0), (int)($r['ticket_cents'] ?? 0), $r['prize_label'] ?: null]);
    }

    db_log_activity((int)$current['id'], "loaded payout structure id=$structure_id into poker session id=$session_id");

    // Structure changed — re-sync recorded winnings/points to the new split.
    pk_apply_tournament_payouts($db, $session_id);

    $sess2 = $db->prepare('SELECT * FROM poker_sessions WHERE id = ?');
    $sess2->execute([$session_id]);

    echo json_encode([
        'ok'      => true,
        'session' => $sess2->fetch(),
        'payouts' => get_payouts($db, $session_id),
        'pool'    => calc_pool($db, $session_id),
    ]);
    exit;
}

// ─── POST: resolve_ticket ──────────────────────────────────
// Re-target an issued entry ticket to another event, or convert it to cash
// (value lands in the holder's source payout via the recompute). Permission:
// caller must manage the SOURCE event.
if ($action === 'resolve_ticket') {
    $ticket_id = (int)($_POST['ticket_id'] ?? 0);
    $op        = $_POST['op'] ?? '';
    $tq = $db->prepare('SELECT t.*, ps.event_id AS source_event_id FROM poker_entry_tickets t
                        JOIN poker_sessions ps ON ps.id = t.source_session_id WHERE t.id = ?');
    $tq->execute([$ticket_id]);
    $t = $tq->fetch();
    if (!$t) { echo json_encode(['ok' => false, 'error' => 'Ticket not found']); exit; }
    if (!is_owner_or_manager($db, (int)$t['source_event_id'], $current, $isAdmin)) {
        http_response_code(403); echo json_encode(['ok' => false, 'error' => 'Access denied']); exit;
    }
    if ($t['status'] !== 'issued') { echo json_encode(['ok' => false, 'error' => 'Only unredeemed tickets can be changed.']); exit; }
    require_once __DIR__ . '/_notifications.php';  // notify_user_direct()

    if ($op === 'retarget') {
        $new_target = (int)($_POST['new_target_event_id'] ?? 0);
        $tev = $db->prepare('SELECT id, title FROM events WHERE id = ? AND is_poker = 1 AND id != ?');
        $tev->execute([$new_target, (int)$t['source_event_id']]);
        $tevRow = $tev->fetch();
        if (!$tevRow || !is_owner_or_manager($db, $new_target, $current, $isAdmin)) {
            echo json_encode(['ok' => false, 'error' => 'New target must be another poker event you manage.']); exit;
        }
        $db->prepare('UPDATE poker_entry_tickets SET target_event_id = ? WHERE id = ?')->execute([$new_target, $ticket_id]);
        pk_log($db, (int)$t['source_session_id'], (int)$current['id'], 'ticket_issue', (int)$t['player_id'],
               (string)$t['display_name'], null, 'Entry ticket re-targeted to "' . $tevRow['title'] . '"');
        // The invitation follows the seat to its new target event.
        pk_ticket_ensure_invite($db, $new_target, $t['user_id'] ? (int)$t['user_id'] : null,
                                (string)$t['display_name'], (int)$t['source_event_id']);
        if (!empty($t['user_id'])) {
            notify_user_direct($db, (int)$t['user_id'], 'reward_ticket',
                'Your entry ticket moved: ' . $tevRow['title'],
                'Your ' . pk_money((int)$t['value_cents']) . ' entry ticket is now good for "' . $tevRow['title'] . '". Show the host at buy-in.',
                '/event.php?id=' . $new_target);
        }
    } elseif ($op === 'convert') {
        $db->prepare("UPDATE poker_entry_tickets SET status = 'converted', resolved_at = CURRENT_TIMESTAMP, resolved_by = ? WHERE id = ?")
           ->execute([(int)$current['id'], $ticket_id]);
        pk_log($db, (int)$t['source_session_id'], (int)$current['id'], 'ticket_void', (int)$t['player_id'],
               (string)$t['display_name'], (int)$t['value_cents'],
               'Entry ticket converted to cash — ' . pk_money((int)$t['value_cents']));
        // The recompute folds converted ticket values into the holder's payout.
        pk_apply_tournament_payouts($db, (int)$t['source_session_id']);
        if (!empty($t['user_id'])) {
            notify_user_direct($db, (int)$t['user_id'], 'reward_ticket',
                'Your entry ticket was converted to cash',
                'Your ' . pk_money((int)$t['value_cents']) . ' entry ticket was converted to a cash prize. Collect it from your host.',
                '/event.php?id=' . (int)$t['source_event_id']);
        }
    } else {
        echo json_encode(['ok' => false, 'error' => 'Unknown op']); exit;
    }

    $tout = $db->prepare("SELECT t.*, e.title AS target_title, e.start_date AS target_date
                          FROM poker_entry_tickets t LEFT JOIN events e ON e.id = t.target_event_id
                          WHERE t.source_session_id = ? ORDER BY t.source_place, t.id");
    $tout->execute([(int)$t['source_session_id']]);
    echo json_encode([
        'ok'      => true,
        'tickets' => $tout->fetchAll(),
        'pool'    => calc_pool($db, (int)$t['source_session_id']),
        'players' => get_players($db, (int)$t['source_session_id']),
    ]);
    exit;
}

// ─── POST: void_jackpot_entry ──────────────────────────────
// Reverse a jackpot ledger entry's balance effect and strike it through —
// entries are never deleted (same correction model as the game ledger).
if ($action === 'void_jackpot_entry') {
    $session_id = (int)($_POST['session_id'] ?? 0);
    $entry_id   = (int)($_POST['entry_id'] ?? 0);
    $sess = $db->prepare('SELECT ps.*, e.league_id FROM poker_sessions ps JOIN events e ON e.id = ps.event_id WHERE ps.id = ?');
    $sess->execute([$session_id]);
    $s = $sess->fetch();
    if (!$s || empty($s['league_id'])) { echo json_encode(['ok' => false, 'error' => 'Session/league not found']); exit; }
    // League money needs a league role — see pk_can_manage_league_money().
    if (!pk_can_manage_league_money($db, (int)$s['league_id'], (int)$current['id'], $isAdmin)) {
        http_response_code(403); echo json_encode(['ok' => false, 'error' => 'Only a league owner or manager can change the jackpot fund.']); exit;
    }
    $fund = pk_jackpot_fund($db, (int)$s['league_id']);
    $eq = $db->prepare('SELECT * FROM league_jackpot_log WHERE id = ? AND jackpot_id = ?');
    $eq->execute([$entry_id, (int)$fund['id']]);
    $entry = $eq->fetch();
    if (!$entry) { echo json_encode(['ok' => false, 'error' => 'Entry not found']); exit; }
    if ((int)$entry['voided']) { echo json_encode(['ok' => false, 'error' => 'Already voided']); exit; }
    $newBal = (int)$fund['balance'] - (int)$entry['amount'];
    if ($newBal < 0) {
        echo json_encode(['ok' => false, 'error' => 'Voiding this entry would make the fund negative (' . pk_money($newBal) . ') — that money was already paid out.']); exit;
    }
    $db->prepare('UPDATE league_jackpot_log SET voided = 1, voided_by = ?, voided_at = CURRENT_TIMESTAMP WHERE id = ?')
       ->execute([(int)$current['id'], $entry_id]);
    $db->prepare('UPDATE league_jackpots SET balance = ? WHERE id = ?')->execute([$newBal, (int)$fund['id']]);
    db_log_activity((int)$current['id'], 'voided jackpot ledger entry id=' . $entry_id . ' (' . pk_money((int)$entry['amount']) . ')');
    echo json_encode(['ok' => true, 'balance' => $newBal]);
    exit;
}

// ─── POST: adjust_jackpot ──────────────────────────────────
// Manual fund correction: signed dollar amount + note, logged as 'adjust'.
if ($action === 'adjust_jackpot') {
    $session_id = (int)($_POST['session_id'] ?? 0);
    $amount     = (int)round(((float)($_POST['amount'] ?? 0)) * 100);
    $note       = trim((string)($_POST['note'] ?? ''));
    if ($amount === 0) { echo json_encode(['ok' => false, 'error' => 'Enter a non-zero amount (negative to remove money).']); exit; }
    $sess = $db->prepare('SELECT ps.*, e.league_id FROM poker_sessions ps JOIN events e ON e.id = ps.event_id WHERE ps.id = ?');
    $sess->execute([$session_id]);
    $s = $sess->fetch();
    if (!$s || empty($s['league_id'])) { echo json_encode(['ok' => false, 'error' => 'Session/league not found']); exit; }
    // League money needs a league role — see pk_can_manage_league_money().
    if (!pk_can_manage_league_money($db, (int)$s['league_id'], (int)$current['id'], $isAdmin)) {
        http_response_code(403); echo json_encode(['ok' => false, 'error' => 'Only a league owner or manager can change the jackpot fund.']); exit;
    }
    $fund = pk_jackpot_fund($db, (int)$s['league_id']);
    $newBal = (int)$fund['balance'] + $amount;
    if ($newBal < 0) { echo json_encode(['ok' => false, 'error' => 'Fund cannot go negative (would be ' . pk_money($newBal) . ').']); exit; }
    $db->prepare('INSERT INTO league_jackpot_log (jackpot_id, session_id, event_type, amount, detail, created_by)
                  VALUES (?, ?, ?, ?, ?, ?)')
       ->execute([(int)$fund['id'], $session_id, 'adjust', $amount,
                  $note !== '' ? mb_substr($note, 0, 120) : 'Manual adjustment', (int)$current['id']]);
    $db->prepare('UPDATE league_jackpots SET balance = ? WHERE id = ?')->execute([$newBal, (int)$fund['id']]);
    db_log_activity((int)$current['id'], 'jackpot manual adjustment ' . pk_money($amount));
    echo json_encode(['ok' => true, 'balance' => $newBal]);
    exit;
}

// ─── POST: toggle_bounty ───────────────────────────────────
// Optional-mode bounty: flip a player's participation in the bounty side pot.
if ($action === 'toggle_bounty') {
    $player_id = (int)($_POST['player_id'] ?? 0);
    $session = get_session_from_player($db, $player_id);
    if (!$session) { echo json_encode(['ok' => false, 'error' => 'Player not found']); exit; }
    verify_event_access($db, $session['event_id'], $current, $isAdmin);
    $per = (int)($session['bounty_amount'] ?? 0);
    if ($per <= 0 || !(int)($session['bounty_optional'] ?? 0)) {
        echo json_encode(['ok' => false, 'error' => 'No optional bounty configured for this game.']); exit;
    }

    $p = $db->prepare('SELECT bounty_in, display_name FROM poker_players WHERE id = ?');
    $p->execute([$player_id]);
    $row = $p->fetch();
    $now = (int)$row['bounty_in'] ? 0 : 1;
    $db->prepare('UPDATE poker_players SET bounty_in = ? WHERE id = ?')->execute([$now, $player_id]);
    pk_log($db, (int)$session['id'], (int)$current['id'], 'bounty', $player_id, (string)$row['display_name'],
           $now ? $per : -$per, $now ? ('Bounty side pot entry — ' . pk_money($per)) : ('Bounty entry reversed — ' . pk_money($per)));
    pk_apply_tournament_payouts($db, (int)$session['id']);

    $pl = $db->prepare('SELECT * FROM poker_players WHERE id = ?');
    $pl->execute([$player_id]);
    echo json_encode(['ok' => true, 'player' => $pl->fetch(), 'pool' => calc_pool($db, (int)$session['id'])]);
    exit;
}

// ─── POST: toggle_jackpot ──────────────────────────────────
// Optional jackpot side entry: flip a player's participation. Money is
// collected on top of the buy-in (never pool money); the ledger row keeps the
// cash story straight.
if ($action === 'toggle_jackpot') {
    $player_id = (int)($_POST['player_id'] ?? 0);
    $session = get_session_from_player($db, $player_id);
    if (!$session) { echo json_encode(['ok' => false, 'error' => 'Player not found']); exit; }
    verify_event_access($db, $session['event_id'], $current, $isAdmin);
    $per = (int)($session['jackpot_amount'] ?? 0);
    if ($per <= 0) { echo json_encode(['ok' => false, 'error' => 'No jackpot entry configured for this game.']); exit; }

    $p = $db->prepare('SELECT jackpot_in, display_name FROM poker_players WHERE id = ?');
    $p->execute([$player_id]);
    $row = $p->fetch();
    $now = (int)$row['jackpot_in'] ? 0 : 1;
    $db->prepare('UPDATE poker_players SET jackpot_in = ? WHERE id = ?')->execute([$now, $player_id]);
    pk_log($db, (int)$session['id'], (int)$current['id'], 'jackpot', $player_id, (string)$row['display_name'],
           $now ? $per : -$per, $now ? ('Jackpot entry — ' . pk_money($per)) : ('Jackpot entry reversed — ' . pk_money($per)));

    $pl = $db->prepare('SELECT * FROM poker_players WHERE id = ?');
    $pl->execute([$player_id]);
    echo json_encode(['ok' => true, 'player' => $pl->fetch(), 'pool' => calc_pool($db, (int)$session['id'])]);
    exit;
}

// ─── POST: record_jackpot_hit ──────────────────────────────
// Bad beat / royal flush hit: pay one or more recipients from the league fund.
// Deducts the fund, logs one 'hit' row per recipient, and mirrors into the
// session log so the game's ledger tells the story too.
if ($action === 'record_jackpot_hit') {
    $session_id = (int)($_POST['session_id'] ?? 0);
    $jtype      = $_POST['jackpot_type'] ?? 'other';  // hit label only — one shared fund
    $names      = $_POST['names'] ?? [];
    $amounts    = $_POST['amounts'] ?? [];  // dollars
    if (!isset(PK_JACKPOT_HIT_TYPES[$jtype])) $jtype = 'other';

    $sess = $db->prepare('SELECT ps.*, e.league_id, e.title FROM poker_sessions ps JOIN events e ON e.id = ps.event_id WHERE ps.id = ?');
    $sess->execute([$session_id]);
    $s = $sess->fetch();
    if (!$s) { echo json_encode(['ok' => false, 'error' => 'Session not found']); exit; }
    if (empty($s['league_id'])) { echo json_encode(['ok' => false, 'error' => 'This event has no league (jackpots are league funds).']); exit; }
    // League money needs a league role — see pk_can_manage_league_money().
    if (!pk_can_manage_league_money($db, (int)$s['league_id'], (int)$current['id'], $isAdmin)) {
        http_response_code(403); echo json_encode(['ok' => false, 'error' => 'Only a league owner or manager can pay out the jackpot.']); exit;
    }

    $recips = [];
    $total = 0;
    for ($i = 0; $i < count($names); $i++) {
        $n = trim((string)$names[$i]);
        $a = (int)round(((float)($amounts[$i] ?? 0)) * 100);
        if ($n === '' || $a <= 0) continue;
        $recips[] = ['name' => $n, 'amount' => $a];
        $total += $a;
    }
    if (!$recips) { echo json_encode(['ok' => false, 'error' => 'Add at least one recipient with an amount.']); exit; }

    $fund = pk_jackpot_fund($db, (int)$s['league_id']);
    if ($total > (int)$fund['balance']) {
        echo json_encode(['ok' => false, 'error' => 'Payout ' . pk_money($total) . ' exceeds the fund (' . pk_money((int)$fund['balance']) . ').']); exit;
    }

    $label = PK_JACKPOT_HIT_TYPES[$jtype];
    foreach ($recips as $rc) {
        $db->prepare('INSERT INTO league_jackpot_log (jackpot_id, session_id, event_type, player_name, amount, detail, created_by)
                      VALUES (?, ?, ?, ?, ?, ?, ?)')
           ->execute([(int)$fund['id'], $session_id, 'hit', $rc['name'], -$rc['amount'],
                      "$label hit at \"{$s['title']}\"", (int)$current['id']]);
        pk_log($db, $session_id, (int)$current['id'], 'jackpot', null, $rc['name'], -$rc['amount'],
               "$label jackpot hit — " . pk_money($rc['amount']) . ' to ' . $rc['name']);
    }
    $db->prepare('UPDATE league_jackpots SET balance = balance - ? WHERE id = ?')->execute([$total, (int)$fund['id']]);
    db_log_activity((int)$current['id'], "$label jackpot hit: " . pk_money($total) . " paid (session id=$session_id)");

    echo json_encode(['ok' => true, 'jackpots' => ['league_id' => (int)$s['league_id'], 'balance' => pk_jackpot_balance($db, (int)$s['league_id'])]]);
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
    db_log_activity((int)$current['id'], "deleted payout structure: " . ($struct['name'] ?? '') . " (id=$structure_id)");
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
    db_log_activity((int)$current['id'], "set default payout structure id=$structure_id");
    echo json_encode(['ok' => true]);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Unknown action']);
