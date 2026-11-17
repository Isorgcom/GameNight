<?php
/**
 * Service layer: league standings and player history.
 *
 * The one copy of the "one row per player per finished game" query. Money is
 * integer cents throughout:
 *   tournament: invested = buy-in + paid rebuys + paid add-ons, winnings = payout + bounty cash
 *   cash game:  invested = cash in,                             winnings = cash out
 * Placement figures (finish, score, wins, in the money) are tournament-only.
 * A player with an account is keyed by user id; a typed-in guest by
 * 'g_<lowercase name>'.
 */

require_once __DIR__ . '/common.php';

const GN_STANDINGS_MIN_GAMES = 3;

/** The per-game subquery shared by standings and player history. Alias-free column list. */
function gn_stats_game_rows_sql(string $extra_where): string {
    return "
        SELECT
            pp.user_id,
            e.id AS event_id, e.title, e.start_date,
            COALESCE(u.username, pp.display_name) AS display_name,
            lm.known_as,
            COALESCE(CAST(pp.user_id AS TEXT), 'g_' || LOWER(pp.display_name)) AS player_key,
            ps.game_type,
            pp.rebuys, pp.addons,
            pc.field_size,
            CASE WHEN ps.game_type = 'tournament' THEN COALESCE(pp.finish_position, pc.field_size) END AS finish_position,
            CASE WHEN ps.game_type = 'tournament'
                 THEN pp.bought_in * ps.buyin_amount + MAX(0, pp.rebuys - COALESCE(ps.rebuys_included, 0)) * COALESCE(ps.rebuy_amount, 0) + MAX(0, pp.addons - CASE WHEN COALESCE(ps.addons_share_rebuys, 0) = 1 THEN MAX(0, COALESCE(ps.rebuys_included, 0) - pp.rebuys) ELSE COALESCE(ps.addons_included, 0) END) * COALESCE(ps.addon_amount, 0)
                 ELSE COALESCE(pp.cash_in, 0) END AS invested,
            CASE WHEN ps.game_type = 'tournament' THEN COALESCE(pp.payout, 0) + COALESCE(pp.bounty_cash, 0)
                 ELSE COALESCE(pp.cash_out, 0) END AS winnings,
            COALESCE(pp.points, 0) AS points,
            COALESCE(pp.bounties_won, 0) AS bounties_won,
            CASE WHEN ps.game_type = 'tournament' AND pc.field_size > 1
                THEN ROUND(CAST(pc.field_size - COALESCE(pp.finish_position, pc.field_size) AS REAL) / pc.field_size * 80 + 20, 1)
                WHEN ps.game_type = 'tournament' THEN 100
            END AS score
        FROM poker_players pp
        JOIN poker_sessions ps ON ps.id = pp.session_id
        JOIN events e ON e.id = ps.event_id
        LEFT JOIN users u ON u.id = pp.user_id
        LEFT JOIN league_members lm ON lm.league_id = e.league_id AND lm.user_id = pp.user_id
        JOIN (
            SELECT session_id, COUNT(*) AS field_size
            FROM poker_players WHERE bought_in = 1 AND removed = 0
            GROUP BY session_id
        ) pc ON pc.session_id = pp.session_id
        WHERE pp.bought_in = 1 AND pp.removed = 0
          AND ps.status = 'finished'
          AND e.league_id = ?
          $extra_where";
}

/**
 * How each ranking orders the table. Players under the minimum number of games
 * are listed but sort after everyone who qualifies, so a one-game wonder
 * cannot top a twenty-game regular.
 */
const GN_STANDINGS_ORDER = [
    'score'  => 'qualified DESC, avg_score IS NULL, avg_score DESC, net DESC, games ASC',
    'net'    => 'qualified DESC, net DESC, avg_score DESC, games ASC',
    'wins'   => 'qualified DESC, wins DESC, avg_score DESC, games ASC',
    'points' => 'qualified DESC, points DESC, wins DESC, avg_score DESC, games ASC',
];

/**
 * The standings table as raw rows, one per player, already ranked. No
 * permission check: the league's stats tab, the public teaser and
 * gn_league_standings() each decide who may look before calling this.
 * $from / $to are Y-m-d or null. Columns: player_key, display_name, known_as,
 * user_id, games, t_games, wins, itm, best_finish, avg_finish, avg_score,
 * invested, winnings, net, points, qualified.
 */
function gn_standings_rows(PDO $db, int $league_id, ?string $from, ?string $to, string $rank = 'score'): array {
    $order = GN_STANDINGS_ORDER[$rank] ?? GN_STANDINGS_ORDER['score'];
    $where = ''; $params = [$league_id];
    if ($from) { $where .= ' AND e.start_date >= ?'; $params[] = $from; }
    if ($to)   { $where .= ' AND e.start_date <= ?'; $params[] = $to; }
    $min = GN_STANDINGS_MIN_GAMES;
    $st = $db->prepare("
        SELECT g.player_key, g.display_name, g.known_as, g.user_id,
               COUNT(*) AS games,
               SUM(CASE WHEN g.game_type = 'tournament' THEN 1 ELSE 0 END) AS t_games,
               SUM(CASE WHEN g.game_type = 'tournament' AND g.finish_position = 1 THEN 1 ELSE 0 END) AS wins,
               SUM(CASE WHEN g.game_type = 'tournament' AND g.winnings > 0 THEN 1 ELSE 0 END) AS itm,
               MIN(CASE WHEN g.game_type = 'tournament' THEN g.finish_position END) AS best_finish,
               ROUND(AVG(CASE WHEN g.game_type = 'tournament' THEN g.finish_position END), 1) AS avg_finish,
               ROUND(AVG(CASE WHEN g.game_type = 'tournament' THEN g.score END), 1) AS avg_score,
               SUM(g.invested) AS invested,
               SUM(g.winnings) AS winnings,
               SUM(g.winnings - g.invested) AS net,
               SUM(g.points) AS points,
               (COUNT(*) >= $min) AS qualified
        FROM (" . gn_stats_game_rows_sql($where) . ") g
        GROUP BY g.player_key
        ORDER BY $order");
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * One player's finished games in a league as raw rows, newest first. No
 * permission check (see gn_standings_rows). $player_key is a user id or
 * 'g_<lowercase name>'; anything else returns null.
 */
function gn_player_game_rows(PDO $db, int $league_id, string $player_key, ?string $from, ?string $to): ?array {
    if (preg_match('/^\d{1,12}$/', $player_key)) {
        $who = 'pp.user_id = ?'; $who_param = (int)$player_key;
    } elseif (str_starts_with($player_key, 'g_') && strlen($player_key) > 2 && strlen($player_key) <= 120) {
        $who = 'pp.user_id IS NULL AND LOWER(pp.display_name) = ?'; $who_param = substr($player_key, 2);
    } else {
        return null;
    }
    $where = " AND $who"; $params = [$league_id, $who_param];
    if ($from) { $where .= ' AND e.start_date >= ?'; $params[] = $from; }
    if ($to)   { $where .= ' AND e.start_date <= ?'; $params[] = $to; }
    $st = $db->prepare(gn_stats_game_rows_sql($where) . ' ORDER BY e.start_date DESC, e.id DESC');
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Resolve a range into [from, to] dates (Y-m-d or null), in the site timezone.
 * $args: range = '7'|'30'|'90'|'365'|'ytd'|'all'|'custom' (default 'all'),
 *        from / to (for custom), season_id (wins over range).
 * Returns ['from', 'to', 'range', 'season'] or a failure array.
 */
function gn_stats_window(PDO $db, int $league_id, array $args): array {
    $tz    = new DateTimeZone(get_setting('timezone', 'UTC'));
    $today = new DateTime('now', $tz);
    $from = $to = null; $season = null;
    $range = (string)($args['range'] ?? 'all');

    if (!empty($args['season_id'])) {
        $st = $db->prepare('SELECT id, name, start_date, end_date FROM league_seasons WHERE id = ? AND league_id = ?');
        $st->execute([(int)$args['season_id'], $league_id]);
        $season = $st->fetch(PDO::FETCH_ASSOC);
        if (!$season) return gn_err('season_not_found', 'No such season in this league.', 404);
        $from = (string)$season['start_date']; $to = (string)$season['end_date'];
        $range = 'season';
    } elseif ($range === 'custom') {
        foreach (['from', 'to'] as $k) {
            $v = trim((string)($args[$k] ?? ''));
            if ($v !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
                return gn_err('bad_date', "\"$k\" must be YYYY-MM-DD.", 400);
            }
        }
        $from = trim((string)($args['from'] ?? '')) ?: null;
        $to   = trim((string)($args['to'] ?? '')) ?: null;
    } elseif ($range === 'ytd') {
        $from = $today->format('Y-01-01'); $to = $today->format('Y-m-d');
    } elseif (in_array($range, ['7', '30', '90', '365'], true)) {
        $from = (clone $today)->modify('-' . (int)$range . ' days')->format('Y-m-d');
        $to   = $today->format('Y-m-d');
    } elseif ($range !== 'all') {
        return gn_err('bad_range', 'range must be 7, 30, 90, 365, ytd, all or custom.', 400);
    }
    return ['from' => $from, 'to' => $to, 'range' => $range, 'season' => $season ?: null];
}

/**
 * League standings. Members of the league (and site admins) only.
 * $args: range / from / to / season_id (see gn_stats_window), rank = score|net|wins|points.
 */
function gn_league_standings(PDO $db, array $actor, int $league_id, array $args = []): array {
    if (gn_league_standing($actor, $league_id) === null) {
        return gn_err('league_not_found', 'No such league.', 404);
    }
    $w = gn_stats_window($db, $league_id, $args);
    if (isset($w['ok'])) return $w;

    $rank = (string)($args['rank'] ?? 'score');
    if (!isset(GN_STANDINGS_ORDER[$rank])) return gn_err('bad_rank', 'rank must be score, net, wins or points.', 400);

    $where = ''; $params = [$league_id];
    if ($w['from']) { $where .= ' AND e.start_date >= ?'; $params[] = $w['from']; }
    if ($w['to'])   { $where .= ' AND e.start_date <= ?'; $params[] = $w['to']; }

    $rows = gn_standings_rows($db, $league_id, $w['from'], $w['to'], $rank);

    $gc = $db->prepare("SELECT COUNT(*) FROM poker_sessions ps JOIN events e ON e.id = ps.event_id
                        WHERE ps.status = 'finished' AND e.league_id = ? $where");
    $gc->execute($params);

    return gn_ok([
        'league_id' => $league_id,
        'range'     => $w['range'],
        'from'      => $w['from'],
        'to'        => $w['to'],
        'season'    => $w['season'],
        'rank'      => $rank,
        'min_games_to_qualify' => $min,
        'games_counted' => (int)$gc->fetchColumn(),
        'rows'      => $rows,   // raw rows; gn_standings_shape() makes the public form
    ]);
}

/** Public form of one standings row (position is 1-based in the chosen ranking). */
function gn_standings_shape(array $r, int $position): array {
    $name  = (string)$r['display_name'];
    $known = (string)($r['known_as'] ?? '');
    return [
        'position'     => $position,
        'player_key'   => (string)$r['player_key'],
        'user_id'      => $r['user_id'] !== null ? (int)$r['user_id'] : null,
        'name'         => $name,
        'known_as'     => ($known !== '' && strcasecmp($known, $name) !== 0) ? $known : null,
        'games'        => (int)$r['games'],
        'tournaments'  => (int)$r['t_games'],
        'wins'         => (int)$r['wins'],
        'in_the_money' => (int)$r['itm'],
        'best_finish'  => $r['best_finish'] !== null ? (int)$r['best_finish'] : null,
        'avg_finish'   => $r['avg_finish'] !== null ? (float)$r['avg_finish'] : null,
        'avg_score'    => $r['avg_score'] !== null ? (float)$r['avg_score'] : null,
        'points'       => (int)$r['points'],
        'invested_cents' => (int)$r['invested'],
        'winnings_cents' => (int)$r['winnings'],
        'net_cents'      => (int)$r['net'],
        'qualified'    => (int)$r['qualified'] === 1,
    ];
}

/**
 * One player's finished games in a league, newest first, with totals.
 * $player_key is a user id ("42") or a guest key ("g_dave smith").
 */
function gn_player_history(PDO $db, array $actor, int $league_id, string $player_key, array $args = []): array {
    if (gn_league_standing($actor, $league_id) === null) {
        return gn_err('league_not_found', 'No such league.', 404);
    }
    $w = gn_stats_window($db, $league_id, $args);
    if (isset($w['ok'])) return $w;
    $games = gn_player_game_rows($db, $league_id, $player_key, $w['from'], $w['to']);
    if ($games === null) return gn_err('bad_player_key', 'A player key is a user id or g_<name>.', 400);

    $tot = ['games' => 0, 'tournaments' => 0, 'wins' => 0, 'in_the_money' => 0,
            'invested_cents' => 0, 'winnings_cents' => 0, 'points' => 0];
    $score_sum = 0.0;
    $out = [];
    foreach ($games as $g) {
        $is_t = $g['game_type'] === 'tournament';
        $tot['games']++;
        $tot['invested_cents'] += (int)$g['invested'];
        $tot['winnings_cents'] += (int)$g['winnings'];
        $tot['points']         += (int)$g['points'];
        if ($is_t) {
            $tot['tournaments']++;
            if ((int)$g['finish_position'] === 1) $tot['wins']++;
            if ((int)$g['winnings'] > 0) $tot['in_the_money']++;
            $score_sum += (float)$g['score'];
        }
        $out[] = [
            'event_id'   => (int)$g['event_id'],
            'title'      => (string)$g['title'],
            'date'       => (string)$g['start_date'],
            'game_type'  => (string)$g['game_type'],
            'finish_position' => $is_t ? (int)$g['finish_position'] : null,
            'field_size' => (int)$g['field_size'],
            'score'      => $is_t ? (float)$g['score'] : null,
            'rebuys'     => (int)$g['rebuys'],
            'addons'     => (int)$g['addons'],
            'bounties_won' => (int)$g['bounties_won'],
            'points'     => (int)$g['points'],
            'invested_cents' => (int)$g['invested'],
            'winnings_cents' => (int)$g['winnings'],
            'net_cents'      => (int)$g['winnings'] - (int)$g['invested'],
        ];
    }
    $tot['net_cents'] = $tot['winnings_cents'] - $tot['invested_cents'];
    $tot['roi_percent'] = $tot['invested_cents'] > 0 ? (int)round($tot['net_cents'] / $tot['invested_cents'] * 100) : 0;
    $tot['avg_score'] = $tot['tournaments'] > 0 ? round($score_sum / $tot['tournaments'], 1) : null;

    $name = $games ? (string)$games[0]['display_name'] : (str_starts_with($player_key, 'g_') ? substr($player_key, 2) : null);
    if ($name === null) {
        $u = $db->prepare('SELECT username FROM users WHERE id = ?');
        $u->execute([(int)$player_key]);
        $name = $u->fetchColumn() ?: null;
        // Only a member of THIS league is nameable by id; anyone else is "no such player".
        if ($name === null || league_role($league_id, (int)$player_key) === null) {
            return gn_err('player_not_found', 'No such player in this league.', 404);
        }
    }
    $known = $games ? (string)($games[0]['known_as'] ?? '') : '';
    return gn_ok([
        'league_id'  => $league_id,
        'player_key' => $player_key,
        'name'       => $name,
        'known_as'   => ($known !== '' && strcasecmp($known, (string)$name) !== 0) ? $known : null,
        'is_guest'   => str_starts_with($player_key, 'g_'),
        'range' => $w['range'], 'from' => $w['from'], 'to' => $w['to'], 'season' => $w['season'],
        'totals' => $tot,
        'games'  => $out,
    ]);
}
