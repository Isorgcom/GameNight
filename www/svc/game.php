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

// ═════════════════════════════════════════════════════════════════════════════
// Write side: running a game.
//
// The rules of a game night (what a rebuy does to a knocked-out player, who is
// crowned when the last opponent goes, what finishing a game pays out and
// banks) live in the check-in console's endpoint, checkin_dl.php, and have
// been corrected against real games for a long time. They are NOT copied here.
// gn_game_act() checks who is asking and what they are asking about, then
// runs the console's own action in this process, as that person, and reports
// what the console answered. One copy of the rules; the site and the API
// cannot drift apart.
// ═════════════════════════════════════════════════════════════════════════════

/**
 * Run one action of a console endpoint (checkin_dl.php, timer_dl.php) in this
 * process as $actor, then hand its decoded JSON answer and HTTP status to
 * $done. The endpoint prints its answer and exits, so $done is called from a
 * shutdown function and this function does not return to its caller.
 * $done must send the response itself.
 */
function gn_legacy_bridge(PDO $db, array $actor, string $script, array $post, callable $done): void {
    $u = $db->prepare('SELECT id, username, email, role, last_login, must_change_password, my_events_past_days, my_events_future_days, timezone, last_poker_default, mfa_enabled, mfa_method, mfa_offer_dismissed, avatar_path, beta_timer, push_prompt_dismissed, session_epoch, pending_email FROM users WHERE id = ?');
    $u->execute([(int)$actor['id']]);
    $user = $u->fetch(PDO::FETCH_ASSOC);
    if (!$user) { $done(['ok' => false, 'error' => 'No such account.'], 401); return; }

    $base  = ob_get_level();
    $fired = false;
    $finish = function () use (&$fired, $base, $done) {
        if ($fired) return;
        $fired = true;
        $raw = '';
        while (ob_get_level() > $base) $raw = (string)ob_get_clean() . $raw;
        gn_act_as(null);
        $code = (int)http_response_code();
        http_response_code(200);
        $answer = json_decode($raw, true);
        if (!is_array($answer)) {
            $err = error_get_last();
            $busy = $err && stripos((string)$err['message'], 'database is locked') !== false;
            if ($err && !$busy) error_log('gn_legacy_bridge: ' . $err['message'] . ' @ ' . $err['file'] . ':' . $err['line']);
            $answer = ['ok' => false, 'error' => $busy ? 'busy' : 'The game console did not answer.', '_bridge' => $busy ? 'busy' : 'failed'];
            $code = $busy ? 503 : 500;
        }
        $done($answer, $code ?: 200);
    };
    register_shutdown_function($finish);

    gn_act_as($user);
    $_POST = $post; $_GET = []; $_REQUEST = $post;
    $_SERVER['REQUEST_METHOD'] = 'POST';
    ob_start();
    require __DIR__ . '/../' . $script;     // prints its JSON answer and exits
    $finish();                              // only reached if it did not exit
}

/** A player of this event's game by id, or null. Removed players count only when $with_removed. */
function gn_game_player(PDO $db, int $session_id, int $player_id, bool $with_removed = false): ?array {
    $st = $db->prepare('SELECT * FROM poker_players WHERE id = ? AND session_id = ?' . ($with_removed ? '' : ' AND removed = 0'));
    $st->execute([$player_id, $session_id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** What to tell the caller after an action: the player it touched (if any), the pool and the game's status. */
function gn_game_after(PDO $db, int $event_id, ?int $player_id): array {
    $s = gn_game_session($db, $event_id);
    if (!$s) return ['event_id' => $event_id, 'game_status' => null];
    $type = (string)($s['game_type'] ?? 'tournament');
    $out  = ['event_id' => $event_id, 'game_status' => (string)$s['status']];
    if ($player_id) {
        foreach (get_players($db, (int)$s['id']) as $p) {
            if ((int)$p['id'] === $player_id) { $out['player'] = gn_player_shape($p, $type); break; }
        }
    }
    $out['pool'] = gn_pool_shape(calc_pool($db, (int)$s['id']), $type);
    return $out;
}

/** The actions gn_game_act() knows. */
const GN_GAME_ACTS = ['setup', 'payouts', 'start', 'finish', 'reopen', 'walk_in', 'buy_in', 'undo_buy_in', 'rebuy', 'undo_rebuy',
    'add_on', 'undo_add_on', 'bulk_add_on', 'eliminate', 'reinstate', 'cash_in', 'cash_out', 'move', 'unseat', 'notes',
    'remove', 'rebalance', 'break_up_table', 'void_entry', 'clock'];

/**
 * Do one thing in a game, as the host. Returns a failure array when the
 * request is refused before the console is asked. Otherwise the console's
 * action runs and $finish is called with the result (a service result array);
 * in that case this function does not return.
 */
function gn_game_act(PDO $db, array $actor, int $event_id, string $act, array $a, callable $finish): array {
    $ev = gn_event_guard($db, $actor, $event_id);
    if (isset($ev['ok'])) return $ev;
    if ((int)$ev['is_poker'] !== 1) return gn_err('not_a_poker_event', 'This event is not a poker game.', 409);
    if (!in_array($act, GN_GAME_ACTS, true)) return gn_err('bad_action', 'Unknown game action.', 400);

    $s = gn_game_session($db, $event_id);
    if (!$s && $act !== 'setup') return gn_err('game_not_set_up', 'This event has no game set up yet. Set it up first.', 404);
    $sid  = $s ? (int)$s['id'] : 0;
    $type = $s ? (string)($s['game_type'] ?? 'tournament') : 'tournament';

    // The player the action is about, checked to be in THIS game before the
    // console is asked: its own check only knows the player exists somewhere.
    $pid = 0; $player = null;
    $needs_player = ['buy_in', 'undo_buy_in', 'rebuy', 'undo_rebuy', 'add_on', 'undo_add_on', 'eliminate', 'reinstate',
                     'cash_in', 'cash_out', 'move', 'unseat', 'notes', 'remove'];
    if (in_array($act, $needs_player, true)) {
        $pid = (int)($a['player_id'] ?? 0);
        $player = $pid > 0 ? gn_game_player($db, $sid, $pid) : null;
        if (!$player) return gn_err('player_not_found', 'No such player in this game.', 404);
    }
    $only = function (array $allowed) use ($a): ?array {
        $bad = array_diff(array_keys($a), array_merge($allowed, ['player_id']));
        return $bad ? gn_err('unknown_field', 'Unknown field: ' . implode(', ', $bad) . '.', 400) : null;
    };
    $cents = function (string $k, int $max = 100000000) use ($a): ?array {
        return (isset($a[$k]) && (!is_int($a[$k]) || $a[$k] < 0 || $a[$k] > $max)) ? gn_err('bad_field', "\"$k\" must be a whole number of cents.", 400) : null;
    };
    $tournament_only = fn() => $type !== 'tournament' ? gn_err('not_a_tournament', 'That only applies to a tournament.', 409) : null;
    $cash_only       = fn() => $type !== 'cash' ? gn_err('not_a_cash_game', 'That only applies to a cash game.', 409) : null;

    $script = 'checkin_dl.php'; $post = []; $extra = fn(array $l) => [];
    switch ($act) {
        case 'setup': {
            $map = ['game_type' => 'game_type', 'buyin_cents' => 'buyin_amount', 'rebuy_cents' => 'rebuy_amount', 'addon_cents' => 'addon_amount',
                    'starting_chips' => 'starting_chips', 'addon_chips' => 'addon_chips', 'tables' => 'num_tables', 'seats_per_table' => 'seats_per_table',
                    'rebuy_allowed' => 'rebuy_allowed', 'addon_allowed' => 'addon_allowed', 'max_rebuys' => 'max_rebuys',
                    'rebuys_included' => 'rebuys_included', 'addons_included' => 'addons_included', 'addons_share_rebuys' => 'addons_share_rebuys',
                    'rebuy_cutoff_level' => 'rebuy_cutoff_level', 'auto_assign_tables' => 'auto_assign_tables', 'payout_round_cents' => 'payout_round',
                    'bounty_cents' => 'bounty_amount', 'bounty_points' => 'bounty_points', 'bounty_optional' => 'bounty_optional',
                    'jackpot_cents' => 'jackpot_amount', 'jackpot_optional' => 'jackpot_optional'];
            if ($e = $only(array_keys($map))) return $e;
            if (!$a) return gn_err('nothing_to_change', 'Send at least one setting to change.', 400);
            foreach ($a as $k => $v) {
                if ($k === 'game_type') { if (!in_array($v, ['tournament', 'cash'], true)) return gn_err('bad_field', 'game_type must be tournament or cash.', 400); }
                elseif (is_bool($v)) $v = $v ? 1 : 0;
                elseif (!is_int($v) || $v < 0 || $v > 100000000) return gn_err('bad_field', "\"$k\" must be true, false or a whole number that is not negative.", 400);
                $post[$map[$k]] = $v;
            }
            if (isset($a['payout_round_cents']) && !in_array($a['payout_round_cents'], PK_PAYOUT_ROUND_STEPS, true)) return gn_err('bad_field', 'payout_round_cents must be 0, 500, 1000 or 2000.', 400);
            if (isset($a['tables']) && ($a['tables'] < 1 || $a['tables'] > 50)) return gn_err('bad_field', 'tables must be from 1 to 50.', 400);
            if (isset($a['seats_per_table']) && ($a['seats_per_table'] < 2 || $a['seats_per_table'] > 12)) return gn_err('bad_field', 'seats_per_table must be from 2 to 12.', 400);
            if (($a['jackpot_cents'] ?? 0) > 0 && !pk_can_manage_league_money($db, (int)($ev['league_id'] ?? 0) ?: null, (int)$actor['id'], (bool)$actor['is_admin'])) {
                return gn_err('not_league_manager', 'A jackpot is the league\'s money: only its owner or a manager can set one.', 403);
            }
            if (isset($post['jackpot_amount']) && $post['jackpot_amount'] > 0 && !gn_actor_has_scope($actor, 'money')) {
                return gn_err('missing_scope', 'Setting a jackpot needs the "money" permission on the token.', 403);
            }
            if (!$s) {
                // No game yet: create it with what was sent (the console fills the rest from defaults).
                $post += ['action' => 'init_session', 'event_id' => $event_id];
                $created = true;
            } else {
                $post += ['action' => 'update_config', 'session_id' => $sid];
            }
            $extra = fn(array $l) => ['session' => ($ns = gn_game_session($db, $event_id)) ? gn_session_shape($ns) : null];
            break;
        }
        case 'payouts': {
            if ($e = $tournament_only()) return $e;
            if ($e = $only(['places'])) return $e;
            $places = $a['places'] ?? null;
            if (!is_array($places) || !array_is_list($places) || !$places || count($places) > 50) return gn_err('bad_field', '"places" must be a list of 1 to 50 places.', 400);
            $post = ['action' => 'update_payouts', 'session_id' => $sid, 'places' => [], 'percentages' => [], 'points' => [], 'tickets' => [], 'labels' => []];
            $seen = [];
            foreach ($places as $i => $p) {
                if (!is_array($p)) return gn_err('bad_field', "places[$i] must be an object.", 400);
                $bad = array_diff(array_keys($p), ['place', 'percentage', 'points', 'ticket_cents', 'label']);
                if ($bad) return gn_err('unknown_field', "Unknown field in places[$i]: " . implode(', ', $bad) . '.', 400);
                $pl = $p['place'] ?? null; $pct = $p['percentage'] ?? 0;
                if (!is_int($pl) || $pl < 1 || $pl > 100 || isset($seen[$pl])) return gn_err('bad_field', "places[$i].place must be a place number, each used once.", 400);
                if ((!is_int($pct) && !is_float($pct)) || $pct < 0 || $pct > 100) return gn_err('bad_field', "places[$i].percentage must be from 0 to 100.", 400);
                foreach (['points', 'ticket_cents'] as $k) if (isset($p[$k]) && (!is_int($p[$k]) || $p[$k] < 0)) return gn_err('bad_field', "places[$i].$k must be a whole number that is not negative.", 400);
                $seen[$pl] = true;
                $post['places'][] = $pl; $post['percentages'][] = $pct; $post['points'][] = (int)($p['points'] ?? 0);
                $post['tickets'][] = ((int)($p['ticket_cents'] ?? 0)) / 100;       // the console's field is dollars
                $post['labels'][] = (string)($p['label'] ?? '');
            }
            $extra = function (array $l) use ($db, $sid) { $pool = calc_pool($db, $sid); return ['payouts' => gn_payouts_shape(get_payouts($db, $sid), $pool)]; };
            break;
        }
        case 'start':
            if ($s['status'] === 'active') return gn_ok(gn_game_after($db, $event_id, null) + ['changed' => false]);
            if ($s['status'] === 'finished') return gn_err('game_finished', 'This game is finished. Reopen it to keep playing.', 409);
            $post = ['action' => 'update_status', 'session_id' => $sid, 'status' => 'active'];
            break;
        case 'finish':
            if ($s['status'] === 'finished') return gn_ok(gn_game_after($db, $event_id, null) + ['changed' => false]);
            if (($a['confirm'] ?? null) !== true) {
                return gn_err('confirmation_required', 'Finishing the game records the final results and payouts, issues any entry tickets, banks jackpot money to the league fund and tells the winners. It can usually be reopened, but not once a ticket has been used or jackpot money paid out. Send {"confirm": true} to finish.', 409);
            }
            $post = ['action' => 'update_status', 'session_id' => $sid, 'status' => 'finished'];
            break;
        case 'reopen':
            if ($s['status'] !== 'finished') return gn_err('game_not_finished', 'Only a finished game can be reopened.', 409);
            $post = ['action' => 'update_status', 'session_id' => $sid, 'status' => 'active'];
            break;
        case 'walk_in': {
            if ($e = $only(['name'])) return $e;
            $name = trim((string)($a['name'] ?? ''));
            if ($name === '' || mb_strlen($name) > 60) return gn_err('bad_field', 'A walk-in needs a name of up to 60 characters.', 400);
            $post = ['action' => 'add_walkin', 'session_id' => $sid, 'name' => $name];
            $extra = function (array $l) use ($db, $event_id) { return isset($l['player_id']) ? gn_game_after($db, $event_id, (int)$l['player_id']) : []; };
            break;
        }
        case 'buy_in':
            if ((int)$player['bought_in'] === 1) return gn_ok(gn_game_after($db, $event_id, $pid) + ['changed' => false]);
            $post = ['action' => 'toggle_buyin', 'player_id' => $pid, 'set' => 1];
            break;
        case 'undo_buy_in':
            if ((int)$player['bought_in'] !== 1) return gn_ok(gn_game_after($db, $event_id, $pid) + ['changed' => false]);
            $post = ['action' => 'toggle_buyin', 'player_id' => $pid];
            break;
        case 'rebuy': case 'undo_rebuy': {
            if ($e = $tournament_only()) return $e;
            if ($e = $only(['after_cutoff'])) return $e;
            if ($act === 'undo_rebuy' && (int)$player['rebuys'] < 1) return gn_err('nothing_to_undo', 'This player has no rebuy to undo.', 409);
            if ($act === 'rebuy' && (int)$player['bought_in'] !== 1) return gn_err('not_bought_in', 'This player has not bought in.', 409);
            if ($act === 'rebuy' && (int)$s['max_rebuys'] > 0 && (int)$player['rebuys'] >= (int)$s['max_rebuys']) return gn_err('rebuy_limit', 'This player has had every rebuy the game allows (' . (int)$s['max_rebuys'] . ').', 409);
            // One call is one rebuy: the console writes one ledger entry however large the step.
            $post = ['action' => 'update_rebuys', 'player_id' => $pid, 'delta' => $act === 'rebuy' ? 1 : -1];
            if (($a['after_cutoff'] ?? false) === true) $post['force'] = 1;
            $extra = fn(array $l) => ['reentered' => !empty($l['reentered']), 'reopened' => !empty($l['reopened'])];
            break;
        }
        case 'add_on': case 'undo_add_on':
            if ($e = $tournament_only()) return $e;
            if ($act === 'undo_add_on' && (int)$player['addons'] < 1) return gn_err('nothing_to_undo', 'This player has no add-on to undo.', 409);
            if ($act === 'add_on' && (int)$player['bought_in'] !== 1) return gn_err('not_bought_in', 'This player has not bought in.', 409);
            $post = ['action' => 'update_addons', 'player_id' => $pid, 'delta' => $act === 'add_on' ? 1 : -1];
            break;
        case 'bulk_add_on': {
            if ($e = $tournament_only()) return $e;
            if ($e = $only(['player_ids'])) return $e;
            $ids = $a['player_ids'] ?? null;
            if (!is_array($ids) || !$ids || count($ids) > 200) return gn_err('bad_field', '"player_ids" must be a list of players.', 400);
            foreach ($ids as $i) if (!is_int($i) || !gn_game_player($db, $sid, $i)) return gn_err('player_not_found', 'No such player in this game: ' . (is_scalar($i) ? $i : '?') . '.', 404);
            $post = ['action' => 'bulk_addons', 'session_id' => $sid, 'player_ids' => implode(',', $ids)];
            $extra = fn(array $l) => ['recorded' => (int)($l['recorded'] ?? 0), 'skipped' => array_values((array)($l['skipped'] ?? []))];
            break;
        }
        case 'eliminate': {
            if ($e = $tournament_only()) return $e;
            if ($e = $only(['by_player_id', 'finish_position'])) return $e;
            if ((int)$player['bought_in'] !== 1) return gn_err('not_bought_in', 'This player has not bought in.', 409);
            if ((int)$player['eliminated'] === 1) return gn_err('already_out', 'This player is already out. Reinstate them first to change the order.', 409);
            $post = ['action' => 'eliminate_player', 'player_id' => $pid];
            if (isset($a['by_player_id'])) {
                $by = is_int($a['by_player_id']) ? gn_game_player($db, $sid, $a['by_player_id']) : null;
                if (!$by || (int)$by['id'] === $pid) return gn_err('player_not_found', 'by_player_id must be another player in this game.', 404);
                $post['eliminated_by'] = (int)$by['id'];
            }
            if (isset($a['finish_position'])) {
                if (!is_int($a['finish_position']) || $a['finish_position'] < 1 || $a['finish_position'] > 500) return gn_err('bad_field', 'finish_position must be a place number.', 400);
                $post['finish_position'] = $a['finish_position'];
            }
            // The console hands back its raw row for the winner; the API gives the same shape as any player.
            $extra = fn(array $l) => ['winner' => is_array($l['winner'] ?? null)
                ? gn_player_shape($l['winner'] + ['known_as' => null, 'approval_status' => 'approved'], 'tournament') : null];
            break;
        }
        case 'reinstate':
            if ($e = $tournament_only()) return $e;
            if ((int)$player['eliminated'] !== 1) return gn_err('not_out', 'This player is not out.', 409);
            $post = ['action' => 'uneliminate_player', 'player_id' => $pid];
            $extra = fn(array $l) => ['reopened' => !empty($l['reopened'])];
            break;
        case 'cash_in':
            if ($e = $cash_only()) return $e;
            if ($e = $only(['amount_cents'])) return $e;
            if (!is_int($a['amount_cents'] ?? null) || $a['amount_cents'] <= 0 || $a['amount_cents'] > 100000000) return gn_err('bad_field', 'amount_cents must be a whole number of cents above zero.', 400);
            $post = ['action' => 'add_cashin', 'player_id' => $pid, 'amount' => $a['amount_cents']];
            break;
        case 'cash_out':
            if ($e = $cash_only()) return $e;
            if ($e = $only(['amount_cents'])) return $e;
            if (!array_key_exists('amount_cents', $a)) return gn_err('bad_field', 'Send amount_cents, or null to clear the cash-out.', 400);
            if ($a['amount_cents'] !== null && $e = $cents('amount_cents')) return $e;
            $post = ['action' => 'set_cashout', 'player_id' => $pid, 'cash_out' => $a['amount_cents'] === null ? '' : $a['amount_cents']];
            break;
        case 'move':
            if ($e = $only(['table'])) return $e;
            if (!is_int($a['table'] ?? null) || $a['table'] < 1 || $a['table'] > (int)$s['num_tables']) return gn_err('bad_field', 'table must be a table of this game (1 to ' . (int)$s['num_tables'] . ').', 400);
            $post = (int)$player['table_number'] > 0
                ? ['action' => 'move_player_table', 'player_id' => $pid, 'new_table' => $a['table']]
                : ['action' => 'set_table', 'player_id' => $pid, 'table_number' => $a['table']];
            break;
        case 'unseat':
            $post = ['action' => 'set_table', 'player_id' => $pid, 'table_number' => ''];
            break;
        case 'notes':
            if ($e = $only(['notes'])) return $e;
            if (!is_string($a['notes'] ?? null) || mb_strlen($a['notes']) > 500) return gn_err('bad_field', 'notes must be text of up to 500 characters.', 400);
            $post = ['action' => 'update_notes', 'player_id' => $pid, 'notes' => $a['notes']];
            break;
        case 'remove':
            if ((int)$player['bought_in'] === 1) return gn_err('player_has_money_in', 'This player has bought in. Undo the buy-in (and any rebuys or add-ons) before removing them.', 409);
            $post = ['action' => 'remove_player', 'player_id' => $pid];
            $extra = fn(array $l) => ['removed' => true];
            $pid = 0;
            break;
        case 'rebalance':
            $post = ['action' => 'rebalance_tables', 'session_id' => $sid, 'protected_ids' => '[]'];
            $extra = fn(array $l) => ['moves' => gn_moves_shape((array)($l['moves'] ?? []))];
            break;
        case 'break_up_table':
            if ($e = $only(['table'])) return $e;
            if (!is_int($a['table'] ?? null) || $a['table'] < 1 || $a['table'] > (int)$s['num_tables']) return gn_err('bad_field', 'table must be a table of this game.', 400);
            if ((int)$s['num_tables'] < 2) return gn_err('last_table', 'The last table cannot be broken up.', 409);
            $post = ['action' => 'break_up_table', 'session_id' => $sid, 'table_number' => $a['table']];
            $extra = fn(array $l) => ['tables' => (int)(gn_game_session($db, $event_id)['num_tables'] ?? 0), 'moves' => gn_moves_shape((array)($l['moves'] ?? []))];
            break;
        case 'void_entry': {
            $eid = (int)($a['entry_id'] ?? 0);
            $en = $db->prepare('SELECT id, player_id, voided FROM poker_session_log WHERE id = ? AND session_id = ?');
            $en->execute([$eid, $sid]);
            $row = $en->fetch(PDO::FETCH_ASSOC);
            if (!$row) return gn_err('entry_not_found', 'No such ledger entry in this game.', 404);
            if ((int)$row['voided'] === 1) return gn_err('already_voided', 'That entry is already voided.', 409);
            $post = ['action' => 'void_ledger_entry', 'entry_id' => $eid];
            $pid = (int)($row['player_id'] ?? 0);
            break;
        }
        case 'clock': {
            if ($e = $tournament_only()) return $e;
            if ($e = $only(['command', 'confirm'])) return $e;
            $cmds = ['play' => 'toggle_play', 'pause' => 'toggle_play', 'next' => 'skip_next', 'previous' => 'skip_prev', 'add_minute' => 'add_time',
                     'subtract_minute' => 'sub_time', 'reset_level' => 'reset_level', 'reset' => 'reset_timer', 'undo' => 'undo'];
            $c = (string)($a['command'] ?? '');
            if (!isset($cmds[$c])) return gn_err('bad_field', 'command must be one of: ' . implode(', ', array_keys($cmds)) . '.', 400);
            if ($c === 'reset' && ($a['confirm'] ?? null) !== true) return gn_err('confirmation_required', 'Resetting the clock puts it back to level 1, paused, for every screen showing it. Send {"confirm": true} to reset.', 409);
            // The first command makes the clock, as opening the timer does.
            $timer = pk_ensure_timer_row($db, $sid, pk_user_beta_pref($db, (int)$actor['id']));
            $running = (int)compute_live_state($db, $timer)['is_running'] === 1;
            // play and pause say what they mean; the console only has a toggle.
            if (($c === 'play' && $running) || ($c === 'pause' && !$running)) {
                $blinds = gn_blinds_shape($db, $sid);
                return gn_ok(['event_id' => $event_id, 'changed' => false, 'clock' => gn_clock_shape($db, $sid, $blinds)]);
            }
            $script = 'timer_dl.php';
            $post = ['action' => 'command', 'cmd' => $cmds[$c], 'session_id' => $sid];
            $extra = function (array $l) use ($db, $sid) { return ['clock' => gn_clock_shape($db, $sid, gn_blinds_shape($db, $sid))]; };
            $pid = -1;      // a clock answer carries the clock, not the pool
            break;
        }
    }

    $done = function (array $legacy, int $code) use ($db, $event_id, $pid, $extra, $finish, $act) {
        if (empty($legacy['ok'])) {
            if (($legacy['_bridge'] ?? '') === 'busy') { header('Retry-After: 3'); $finish(gn_err('busy', 'The site is busy sending notifications. Nothing was changed; try again in a few seconds.', 503)); return; }
            if (($legacy['_bridge'] ?? '') === 'failed') { $finish(gn_err('server_error', 'Something went wrong on the server.', 500)); return; }
            $err = gn_err(!empty($legacy['closed']) ? 'rebuys_closed' : 'game_refused', (string)($legacy['error'] ?? 'The game refused that.'), $code >= 400 && $code < 500 ? $code : 409);
            if (!empty($legacy['closed'])) $err['message'] .= ' Send {"after_cutoff": true} to record it anyway.';
            $finish($err); return;
        }
        $res = $pid === -1 ? ['event_id' => $event_id] : gn_game_after($db, $event_id, $pid > 0 ? $pid : null);
        $finish(gn_ok($extra($legacy) + $res + ['action' => $act, 'changed' => true]));
    };
    gn_legacy_bridge($db, $actor, $script, $post, $done);
    return gn_err('server_error', 'Something went wrong on the server.', 500);   // not reached
}

function gn_moves_shape(array $moves): array {
    return array_values(array_map(fn($m) => ['player_id' => (int)($m['player_id'] ?? 0), 'name' => (string)($m['display_name'] ?? ''),
        'from_table' => (int)($m['old_table'] ?? 0) ?: null, 'to_table' => (int)($m['new_table'] ?? 0) ?: null], $moves));
}

/**
 * Replace the blind schedule. $levels: a list of {small_blind, big_blind,
 * ante, minutes} or {is_break: true, minutes}. Written as this game's own
 * schedule (a library schedule it was using is left untouched), and a clock
 * that has not started picks up the new first level.
 */
function gn_game_blinds_set(PDO $db, array $actor, int $event_id, array $body): array {
    $ev = gn_event_guard($db, $actor, $event_id);
    if (isset($ev['ok'])) return $ev;
    $s = gn_game_session($db, $event_id);
    if (!$s) return gn_err('game_not_set_up', 'This event has no game set up yet.', 404);
    if (($s['game_type'] ?? 'tournament') !== 'tournament') return gn_err('not_a_tournament', 'Only a tournament has a blind schedule.', 409);
    $bad = array_diff(array_keys($body), ['levels']);
    if ($bad) return gn_err('unknown_field', 'Unknown field: ' . implode(', ', $bad) . '.', 400);
    $levels = $body['levels'] ?? null;
    if (!is_array($levels) || !array_is_list($levels) || !$levels || count($levels) > 200) return gn_err('bad_field', '"levels" must be a list of 1 to 200 levels.', 400);
    $in = [];
    foreach ($levels as $i => $lv) {
        if (!is_array($lv)) return gn_err('bad_field', "levels[$i] must be an object.", 400);
        $badk = array_diff(array_keys($lv), ['small_blind', 'big_blind', 'ante', 'minutes', 'is_break']);
        if ($badk) return gn_err('unknown_field', "Unknown field in levels[$i]: " . implode(', ', $badk) . '.', 400);
        $min = $lv['minutes'] ?? null;
        if (!is_int($min) || $min < 1 || $min > 999) return gn_err('bad_field', "levels[$i].minutes must be from 1 to 999.", 400);
        $break = ($lv['is_break'] ?? false) === true;
        foreach (['small_blind', 'big_blind', 'ante'] as $k) {
            if (isset($lv[$k]) && ((!is_int($lv[$k]) && !is_float($lv[$k])) || $lv[$k] < 0)) return gn_err('bad_field', "levels[$i].$k must be a number that is not negative.", 400);
        }
        if (!$break && (($lv['big_blind'] ?? 0) <= 0)) return gn_err('bad_field', "levels[$i] needs a big_blind, or is_break: true.", 400);
        $in[] = ['small_blind' => $break ? 0 : ($lv['small_blind'] ?? 0), 'big_blind' => $break ? 0 : $lv['big_blind'], 'ante' => $break ? 0 : ($lv['ante'] ?? 0),
                 'duration_minutes' => $min, 'is_break' => $break ? 1 : 0];
    }
    $clean = pk_clean_blind_levels($in);
    $res = pk_apply_event_blinds($db, (int)$s['id'], $event_id, $clean, (int)$actor['id']);
    db_log_activity((int)$actor['id'], "saved event blind schedule: event #$event_id (" . count($clean) . ' levels)');
    $blinds = gn_blinds_shape($db, (int)$s['id']);
    return gn_ok(['event_id' => $event_id, 'blinds' => $blinds, 'clock' => gn_clock_shape($db, (int)$s['id'], $blinds), 'made_own_copy' => !empty($res['created_copy'])]);
}
