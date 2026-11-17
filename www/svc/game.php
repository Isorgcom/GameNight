<?php
/**
 * Service layer: the poker game. Read side (state, log, results, clock,
 * ledger). The write side moves here from checkin_dl.php action by action.
 *
 * Shapes returned here are the PUBLIC ones the API and MCP hand out: integer
 * cents with a _cents suffix, ISO timestamps, booleans as booleans. The
 * check-in console keeps reading the raw rows through checkin_dl.php.
 */

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/../_poker_helpers.php';

/** The session row for an event, or null when the game has never been set up. */
function gn_game_session(PDO $db, int $event_id): ?array {
    $st = $db->prepare('SELECT * FROM poker_sessions WHERE event_id = ?');
    $st->execute([$event_id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function gn_session_shape(array $s): array {
    return [
        'id'         => (int)$s['id'],
        'event_id'   => (int)$s['event_id'],
        'status'     => (string)$s['status'],            // setup | active | finished
        'game_type'  => (string)($s['game_type'] ?? 'tournament'),
        'buyin_cents'  => (int)$s['buyin_amount'],
        'rebuy_cents'  => (int)$s['rebuy_amount'],
        'addon_cents'  => (int)$s['addon_amount'],
        'rebuy_allowed' => (int)$s['rebuy_allowed'] === 1,
        'addon_allowed' => (int)$s['addon_allowed'] === 1,
        'max_rebuys'    => (int)$s['max_rebuys'],
        'rebuys_included'     => (int)($s['rebuys_included'] ?? 0),
        'addons_included'     => (int)($s['addons_included'] ?? 0),
        'addons_share_rebuys' => (int)($s['addons_share_rebuys'] ?? 0) === 1,
        'rebuy_cutoff_level'  => (int)($s['rebuy_cutoff_level'] ?? 0),
        'starting_chips' => (int)$s['starting_chips'],
        'addon_chips'    => (int)($s['addon_chips'] ?? 0),
        'tables'           => (int)$s['num_tables'],
        'seats_per_table'  => (int)($s['seats_per_table'] ?? 0),
        'auto_assign_tables' => (int)($s['auto_assign_tables'] ?? 0) === 1,
        'payout_round_cents' => pk_payout_round_clean($s['payout_round'] ?? 0),
        'bounty_cents'    => (int)($s['bounty_amount'] ?? 0),
        'bounty_points'   => (int)($s['bounty_points'] ?? 0),
        'bounty_optional' => (int)($s['bounty_optional'] ?? 0) === 1,
        'jackpot_cents'    => (int)($s['jackpot_amount'] ?? 0),
        'jackpot_optional' => (int)($s['jackpot_optional'] ?? 0) === 1,
        'setup_saved' => (int)($s['setup_saved'] ?? 0) === 1,
    ];
}

/** One word for where a player stands in the game. */
function gn_player_status(array $p, string $game_type): string {
    if ((int)$p['bought_in'] !== 1) return 'not_bought_in';
    if ($game_type !== 'tournament') return $p['cash_out'] !== null ? 'cashed_out' : 'playing';
    return (int)$p['eliminated'] === 1 ? 'eliminated' : 'playing';
}

function gn_player_shape(array $p, string $game_type): array {
    $name  = (string)$p['display_name'];
    $known = (string)($p['known_as'] ?? '');
    return [
        'id'       => (int)$p['id'],
        'user_id'  => $p['user_id'] !== null ? (int)$p['user_id'] : null,
        'name'     => $name,
        'known_as' => ($known !== '' && strcasecmp($known, $name) !== 0) ? $known : null,
        'status'   => gn_player_status($p, $game_type),
        'rsvp'     => $p['rsvp'] ?? null,
        'approval_status' => (string)($p['approval_status'] ?? 'approved'),
        'bought_in' => (int)$p['bought_in'] === 1,
        'rebuys'    => (int)$p['rebuys'],
        'addons'    => (int)$p['addons'],
        'table'     => $p['table_number'] ? (int)$p['table_number'] : null,
        'seat'      => $p['seat_number'] ? (int)$p['seat_number'] : null,
        'finish_position' => $p['finish_position'] ? (int)$p['finish_position'] : null,
        'eliminated_by_player_id' => !empty($p['eliminated_by']) ? (int)$p['eliminated_by'] : null,
        'payout_cents' => (int)($p['payout'] ?? 0),
        'points'       => (int)($p['points'] ?? 0),
        'bounties_won' => (int)($p['bounties_won'] ?? 0),
        'bounty_cash_cents' => (int)($p['bounty_cash'] ?? 0),
        'in_bounty'    => (int)($p['bounty_in'] ?? 0) === 1,
        'in_jackpot'   => (int)($p['jackpot_in'] ?? 0) === 1,
        'cash_in_cents'  => (int)($p['cash_in'] ?? 0),
        'cash_out_cents' => $p['cash_out'] !== null ? (int)$p['cash_out'] : null,
        'notes'    => (string)($p['notes'] ?? ''),
    ];
}

function gn_pool_shape(array $pool, string $game_type): array {
    $out = [
        'prize_pool_cents' => (int)$pool['pool_total'],
        'gross_cents'      => (int)$pool['pool_gross'],
        'players'       => (int)$pool['total_players'],
        'bought_in'     => (int)$pool['bought_in'],
        'still_playing' => (int)$pool['still_playing'],
    ];
    if ($game_type === 'tournament') {
        $out += [
            'eliminated'   => (int)$pool['eliminated'],
            'rebuys'       => (int)$pool['total_rebuys'],
            'paid_rebuys'  => (int)$pool['paid_rebuys'],
            'addons'       => (int)$pool['total_addons'],
            'paid_addons'  => (int)$pool['paid_addons'],
            'buyin_total_cents' => (int)$pool['buyin_total'],
            'rebuy_total_cents' => (int)$pool['rebuy_total'],
            'addon_total_cents' => (int)$pool['addon_total'],
            'bounty_withheld_cents'  => (int)$pool['bounty_withheld'],
            'jackpot_withheld_cents' => (int)$pool['jackpot_withheld'],
            'ticket_withheld_cents'  => (int)$pool['ticket_withheld'],
            'chips_in_play' => (int)$pool['chips_in_play'],
        ];
    } else {
        $out += [
            'cashed_out' => (int)$pool['cashed_out'],
            'total_cash_in_cents'  => (int)$pool['total_cash_in'],
            'total_cash_out_cents' => (int)$pool['total_cash_out'],
        ];
    }
    return $out;
}

/** The payout table with the dollar amount each place pays from the pool as it stands. */
function gn_payouts_shape(array $rows, array $pool): array {
    $amts = pk_payout_amounts((int)$pool['pool_total'], $rows, (int)$pool['payout_round']);
    $out = [];
    foreach ($rows as $r) {
        $place = (int)$r['place'];
        $out[] = [
            'place'        => $place,
            'percentage'   => (float)$r['percentage'],
            'amount_cents' => (int)($amts[$place] ?? 0),
            'points'       => (int)($r['points'] ?? 0),
            'ticket_cents' => (int)($r['ticket_cents'] ?? 0),
            'label'        => (string)($r['prize_label'] ?? ''),
        ];
    }
    return $out;
}

/** Blind schedule for a session, numbered as the display numbers it (a break takes a number). */
function gn_blinds_shape(PDO $db, int $session_id): array {
    $out = [];
    foreach (pk_session_blind_levels($db, $session_id, true) as $lv) {
        $out[] = [
            'level'       => (int)$lv['level_number'],
            'is_break'    => (int)$lv['is_break'] === 1,
            'small_blind' => $lv['small_blind'] + 0,
            'big_blind'   => $lv['big_blind'] + 0,
            'ante'        => $lv['ante'] + 0,
            'minutes'     => (int)$lv['duration_minutes'],
        ];
    }
    return $out;
}

/**
 * The tournament clock, or null when the game has no timer yet. Reading a
 * running clock that has rolled past the end of its level advances it, the
 * same catch-up a display poll does.
 */
function gn_clock_shape(PDO $db, int $session_id, ?array $levels = null): ?array {
    $t = $db->prepare('SELECT * FROM timer_state WHERE session_id = ?');
    $t->execute([$session_id]);
    $timer = $t->fetch(PDO::FETCH_ASSOC);
    if (!$timer) return null;
    $live   = compute_live_state($db, $timer);
    $levels = $levels ?? gn_blinds_shape($db, $session_id);
    $cur = $next = null;
    foreach ($levels as $lv) {
        if ($lv['level'] === (int)$live['current_level']) $cur = $lv;
        elseif ($cur !== null && $next === null) $next = $lv;
    }
    return [
        'running'           => (int)$live['is_running'] === 1,
        'level'             => (int)$live['current_level'],
        'seconds_remaining' => (int)$live['time_remaining_seconds'],
        'current'           => $cur,
        'next'              => $next,
        'levels_total'      => count($levels),
    ];
}

/**
 * Everything about a game in one read, for the host: the session, players,
 * pool, payouts with amounts, blinds and clock. Like opening the check-in
 * console, it first brings the roster level with the guest list (new invitees
 * added, RSVPs refreshed).
 * Returns game: null when the event has no game set up yet.
 */
function gn_game_state(PDO $db, array $actor, int $event_id): array {
    $ev = gn_event_guard($db, $actor, $event_id, true);
    if (isset($ev['ok'])) return $ev;
    if ((int)$ev['is_poker'] !== 1) return gn_err('not_a_poker_event', 'This event is not a poker game.', 409);

    $s = gn_game_session($db, $event_id);
    if (!$s) return gn_ok(['event_id' => $event_id, 'game' => null]);
    $sid  = (int)$s['id'];
    $type = (string)($s['game_type'] ?? 'tournament');

    if (empty($ev['cancelled_at'])) sync_invitees($db, $sid, $event_id);

    $pool    = calc_pool($db, $sid);
    $players = array_map(fn($p) => gn_player_shape($p, $type), get_players($db, $sid));
    $blinds  = $type === 'tournament' ? gn_blinds_shape($db, $sid) : [];
    $league_id = (int)($ev['league_id'] ?? 0) ?: null;

    $game = [
        'session' => gn_session_shape($s),
        'pool'    => gn_pool_shape($pool, $type),
        'players' => $players,
        'payouts' => $type === 'tournament' ? gn_payouts_shape(get_payouts($db, $sid), $pool) : [],
        'blinds'  => $blinds,
        'clock'   => $type === 'tournament' ? gn_clock_shape($db, $sid, $blinds) : null,
        'rebuy_level' => $type === 'tournament' ? pk_timer_blind_level($db, $sid) : 0,
    ];
    if ($league_id && pk_can_manage_league_money($db, $league_id, (int)$actor['id'], (bool)$actor['is_admin'])) {
        $game['jackpot'] = [
            'league_id' => $league_id,
            'fund_balance_cents' => (int)pk_jackpot_balance($db, $league_id),
            'banked_from_this_game_cents' => pk_jackpot_contributed($db, $sid),
            'collected_this_game_cents'   => (int)$pool['jackpot_collected'],
        ];
    }
    return gn_ok(['event_id' => $event_id, 'cancelled' => !empty($ev['cancelled_at']), 'game' => $game]);
}

/** The game's activity log, newest first (host only). */
function gn_game_log(PDO $db, array $actor, int $event_id, int $limit = 200): array {
    $ev = gn_event_guard($db, $actor, $event_id, true);
    if (isset($ev['ok'])) return $ev;
    $s = gn_game_session($db, $event_id);
    if (!$s) return gn_err('game_not_set_up', 'This event has no game set up yet.', 404);
    $rows = get_session_log($db, (int)$s['id'], max(1, min(500, $limit)));
    return gn_ok(['event_id' => $event_id, 'entries' => array_map('gn_log_shape', $rows)]);
}

function gn_log_shape(array $r): array {
    return [
        'id'        => (int)$r['id'],
        'type'      => (string)$r['event_type'],
        'player_id' => $r['player_id'] !== null ? (int)$r['player_id'] : null,
        'player'    => $r['player_name'] ?? null,
        'amount_cents' => $r['amount'] !== null ? (int)$r['amount'] : null,
        'detail'    => (string)($r['detail'] ?? ''),
        'voided'    => (int)($r['voided'] ?? 0) === 1,
        'by'        => $r['actor'] ?? null,
        'at'        => gn_iso($r['created_at'] ?? null),
    ];
}

/** One player's money entries (buy-in, rebuy, add-on, cash in/out), newest first (host only). */
function gn_player_ledger(PDO $db, array $actor, int $event_id, int $player_id): array {
    $ev = gn_event_guard($db, $actor, $event_id, true);
    if (isset($ev['ok'])) return $ev;
    $s = gn_game_session($db, $event_id);
    if (!$s) return gn_err('game_not_set_up', 'This event has no game set up yet.', 404);
    $p = $db->prepare('SELECT id, display_name FROM poker_players WHERE id = ? AND session_id = ?');
    $p->execute([$player_id, (int)$s['id']]);
    $pl = $p->fetch(PDO::FETCH_ASSOC);
    if (!$pl) return gn_err('player_not_found', 'No such player in this game.', 404);
    $rows = get_player_ledger($db, (int)$s['id'], $player_id);
    return gn_ok([
        'event_id' => $event_id, 'player_id' => $player_id, 'player' => (string)$pl['display_name'],
        'entries' => array_map(fn($r) => gn_log_shape($r + ['player_id' => $player_id, 'player_name' => $pl['display_name']]), $rows),
    ]);
}

/** The clock and blind schedule (host only). */
function gn_game_clock(PDO $db, array $actor, int $event_id): array {
    $ev = gn_event_guard($db, $actor, $event_id, true);
    if (isset($ev['ok'])) return $ev;
    $s = gn_game_session($db, $event_id);
    if (!$s) return gn_err('game_not_set_up', 'This event has no game set up yet.', 404);
    $blinds = gn_blinds_shape($db, (int)$s['id']);
    return gn_ok(['event_id' => $event_id, 'clock' => gn_clock_shape($db, (int)$s['id'], $blinds), 'blinds' => $blinds]);
}

/**
 * How a game came out: finishing order with what each player put in and took
 * home. Open to the host, and to members of the event's league (who already
 * see the same figures on the league's stats page). While the game is still
 * running it shows the standings so far.
 */
function gn_game_results(PDO $db, array $actor, int $event_id): array {
    $ev = gn_event_visible($db, $actor, $event_id);
    if (!$ev) return gn_err('event_not_found', 'No such event.', 404);
    $league_id = (int)($ev['league_id'] ?? 0);
    $is_host   = gn_can_manage_event($db, $actor, $event_id);
    if (!$is_host && !($league_id && gn_league_standing($actor, $league_id) !== null)) {
        return gn_err('results_not_available', 'Results are open to the host and to members of the event\'s league.', 403);
    }
    $s = gn_game_session($db, $event_id);
    if (!$s) return gn_err('game_not_set_up', 'This event has no game set up yet.', 404);
    $sid  = (int)$s['id'];
    $type = (string)($s['game_type'] ?? 'tournament');
    $pool = calc_pool($db, $sid);

    $rows = [];
    foreach (get_players($db, $sid) as $p) {
        if ((int)$p['bought_in'] !== 1) continue;
        if ($type === 'tournament') {
            $paid_rb = max(0, (int)$p['rebuys'] - (int)($s['rebuys_included'] ?? 0));
            $free_ao = (int)($s['addons_share_rebuys'] ?? 0) === 1
                ? max(0, (int)($s['rebuys_included'] ?? 0) - (int)$p['rebuys'])
                : (int)($s['addons_included'] ?? 0);
            $invested = (int)$s['buyin_amount'] + $paid_rb * (int)$s['rebuy_amount']
                      + max(0, (int)$p['addons'] - $free_ao) * (int)$s['addon_amount'];
            $winnings = (int)($p['payout'] ?? 0) + (int)($p['bounty_cash'] ?? 0);
        } else {
            $invested = (int)($p['cash_in'] ?? 0);
            $winnings = (int)($p['cash_out'] ?? 0);
        }
        $name = (string)$p['display_name']; $known = (string)($p['known_as'] ?? '');
        $rows[] = [
            'player_id' => (int)$p['id'],
            'user_id'   => $p['user_id'] !== null ? (int)$p['user_id'] : null,
            'name'      => $name,
            'known_as'  => ($known !== '' && strcasecmp($known, $name) !== 0) ? $known : null,
            'status'    => gn_player_status($p, $type),
            'finish_position' => $p['finish_position'] ? (int)$p['finish_position'] : null,
            'rebuys'    => (int)$p['rebuys'],
            'addons'    => (int)$p['addons'],
            'payout_cents'      => (int)($p['payout'] ?? 0),
            'bounties_won'      => (int)($p['bounties_won'] ?? 0),
            'bounty_cash_cents' => (int)($p['bounty_cash'] ?? 0),
            'points'    => (int)($p['points'] ?? 0),
            'invested_cents' => $invested,
            'winnings_cents' => $winnings,
            'net_cents'      => $winnings - $invested,
        ];
    }
    // Finishers in order, then those still in; a cash game by net result.
    usort($rows, function ($a, $b) use ($type) {
        if ($type !== 'tournament') return $b['net_cents'] <=> $a['net_cents'];
        $fa = $a['finish_position'] ?? 0; $fb = $b['finish_position'] ?? 0;
        if ($fa && $fb) return $fa <=> $fb;
        if ($fa !== $fb) return $fa ? ($fa === 1 ? -1 : 1) : ($fb === 1 ? 1 : -1);
        return strcasecmp($a['name'], $b['name']);
    });

    return gn_ok([
        'event_id'  => $event_id,
        'title'     => (string)$ev['title'],
        'date'      => (string)$ev['start_date'],
        'league_id' => $league_id ?: null,
        'game_type' => $type,
        'status'    => (string)$s['status'],
        'final'     => (string)$s['status'] === 'finished',
        'prize_pool_cents' => (int)$pool['pool_total'],
        'entries'   => (int)$pool['bought_in'],
        'payouts'   => $type === 'tournament' ? gn_payouts_shape(get_payouts($db, $sid), $pool) : [],
        'players'   => $rows,
    ]);
}
