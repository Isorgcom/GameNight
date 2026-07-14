<?php
/**
 * Per-player game history within a league.
 * URL: /league_player.php?id=<league_id>&pk=<player_key>[&range=…&from=…&to=…]
 *
 * player_key matches the leaderboard's convention: a numeric user id for
 * registered players, or "g_<lowercase display name>" for guests/walk-ins.
 * Access mirrors the league stats tab: members (or admins) only for hidden
 * leagues; money figures are cents, formatted like the leaderboard.
 */
require_once __DIR__ . '/auth.php';

$current = require_login();
$db      = get_db();
$uid     = (int)$current['id'];
$isAdmin = $current['role'] === 'admin';

$league_id = (int)($_GET['id'] ?? 0);
$pk        = trim($_GET['pk'] ?? '');

$L = $db->prepare('SELECT * FROM leagues WHERE id = ?');
$L->execute([$league_id]);
$league = $L->fetch();
if (!$league || $pk === '') { http_response_code(404); exit('Not found'); }

$myRole = league_role($league_id, $uid);
if ((int)$league['is_hidden'] === 1 && !$isAdmin && $myRole === null) {
    http_response_code(403);
    exit('This league is private.');
}

// ── Date range (same options as the stats tab) ────────────────────────────────
$allowed_ranges = ['7', '30', '90', '365', 'ytd', 'all', 'custom'];
$range   = in_array($_GET['range'] ?? 'all', $allowed_ranges, true) ? ($_GET['range'] ?? 'all') : 'all';
$from_in = trim($_GET['from'] ?? '');
$to_in   = trim($_GET['to']   ?? '');
$tz      = new DateTimeZone(get_setting('timezone', 'UTC'));
$today   = new DateTime('now', $tz);
$from_date = null; $to_date = null;
if ($range === 'custom') {
    $from_date = DateTime::createFromFormat('Y-m-d', $from_in, $tz) ?: null;
    $to_date   = DateTime::createFromFormat('Y-m-d', $to_in,   $tz) ?: null;
} elseif ($range === 'ytd') {
    $from_date = new DateTime($today->format('Y-01-01'), $tz);
    $to_date   = $today;
} elseif ($range !== 'all') {
    $from_date = (clone $today)->modify('-' . (int)$range . ' days');
    $to_date   = $today;
}

// ── Player match: numeric pk → user id, g_<name> → guest display name ─────────
if (str_starts_with($pk, 'g_')) {
    $who_sql    = 'pp.user_id IS NULL AND LOWER(pp.display_name) = ?';
    $who_params = [substr($pk, 2)];
} elseif (ctype_digit($pk)) {
    $who_sql    = 'pp.user_id = ?';
    $who_params = [(int)$pk];
} else {
    http_response_code(400); exit('Bad player key');
}

$where_date = '';
$params = array_merge([$league_id], $who_params);
if ($from_date) { $where_date .= ' AND e.start_date >= ?'; $params[] = $from_date->format('Y-m-d'); }
if ($to_date)   { $where_date .= ' AND e.start_date <= ?'; $params[] = $to_date->format('Y-m-d'); }

$stmt = $db->prepare("
    SELECT
        e.id as event_id, e.title, e.start_date,
        ps.game_type,
        COALESCE(u.username, pp.display_name) as display_name,
        pp.rebuys, pp.addons,
        CASE WHEN ps.game_type = 'tournament' THEN COALESCE(pp.finish_position, pc.field_size) END as finish_position,
        pc.field_size,
        CASE WHEN ps.game_type = 'tournament'
             THEN pp.bought_in * ps.buyin_amount + pp.rebuys * COALESCE(ps.rebuy_amount, 0) + pp.addons * COALESCE(ps.addon_amount, 0)
             ELSE COALESCE(pp.cash_in, 0) END as invested,
        CASE WHEN ps.game_type = 'tournament' THEN COALESCE(pp.payout, 0)
             ELSE COALESCE(pp.cash_out, 0) END as winnings,
        CASE WHEN ps.game_type = 'tournament' AND pc.field_size > 1
            THEN ROUND(CAST(pc.field_size - COALESCE(pp.finish_position, pc.field_size) AS REAL) / pc.field_size * 80 + 20, 1)
            WHEN ps.game_type = 'tournament' THEN 100
        END as score
    FROM poker_players pp
    JOIN poker_sessions ps ON ps.id = pp.session_id
    JOIN events e ON e.id = ps.event_id
    LEFT JOIN users u ON u.id = pp.user_id
    JOIN (
        SELECT session_id, COUNT(*) as field_size
        FROM poker_players WHERE bought_in = 1 AND removed = 0
        GROUP BY session_id
    ) pc ON pc.session_id = pp.session_id
    WHERE pp.bought_in = 1 AND pp.removed = 0
      AND ps.status = 'finished'
      AND e.league_id = ?
      AND $who_sql
      $where_date
    ORDER BY e.start_date DESC, e.id DESC
");
$stmt->execute($params);
$games = $stmt->fetchAll();

$player_name = $games ? $games[0]['display_name'] : ($who_params[0] ?? 'Player');
if (!$games && str_starts_with($pk, 'g_')) $player_name = substr($pk, 2);
$is_guest = str_starts_with($pk, 'g_');

// Aggregates
$tot = ['games' => 0, 't' => 0, 'wins' => 0, 'itm' => 0, 'invested' => 0, 'winnings' => 0, 'scoreSum' => 0.0];
foreach ($games as $g) {
    $tot['games']++;
    $tot['invested'] += (int)$g['invested'];
    $tot['winnings'] += (int)$g['winnings'];
    if ($g['game_type'] === 'tournament') {
        $tot['t']++;
        if ((int)$g['finish_position'] === 1) $tot['wins']++;
        if ((int)$g['winnings'] > 0) $tot['itm']++;
        $tot['scoreSum'] += (float)$g['score'];
    }
}
$net = $tot['winnings'] - $tot['invested'];
$roi = $tot['invested'] > 0 ? round($net / $tot['invested'] * 100) : 0;

function lp_money(int $cents): string {
    $v = abs($cents) / 100;
    $s = '$' . ($v == (int)$v ? number_format($v, 0) : number_format($v, 2));
    return ($cents < 0 ? '-' : ($cents > 0 ? '+' : '')) . $s;
}
function lp_ordinal($n): string {
    $n = (int)$n;
    if ($n <= 0) return '—';
    $abs = $n % 100;
    if ($abs >= 11 && $abs <= 13) return $n . 'th';
    return $n . (['th','st','nd','rd'][$n % 10] ?? 'th');
}
$backUrl = '/league.php?id=' . $league_id . '&tab=stats&range=' . urlencode($range)
         . ($from_in !== '' ? '&from=' . urlencode($from_in) : '')
         . ($to_in   !== '' ? '&to='   . urlencode($to_in)   : '');
$site_name = get_setting('site_name', 'Game Night');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($player_name) ?> &ndash; <?= htmlspecialchars($league['name']) ?> stats &ndash; <?= htmlspecialchars($site_name) ?></title>
    <link rel="stylesheet" href="/style.css?v=<?= htmlspecialchars(APP_VERSION) ?>">
    <style>
        .lp-wrap { max-width: 760px; margin: 1.25rem auto 2rem; padding: 0 1rem; }
        .lp-card { background:#fff; border:1.5px solid #e2e8f0; border-radius:12px; padding:1.5rem; }
        .lp-tiles { display:grid; grid-template-columns:repeat(auto-fit, minmax(92px, 1fr)); gap:.6rem; margin:1rem 0 1.25rem; }
        .lp-tile { background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:.6rem .4rem; text-align:center; }
        .lp-tile .v { font-size:1.15rem; font-weight:800; color:#1e293b; }
        .lp-tile .l { font-size:.68rem; font-weight:600; color:#94a3b8; text-transform:uppercase; letter-spacing:.04em; margin-top:.1rem; }
        .lp-table { width:100%; border-collapse:collapse; font-size:.85rem; }
        .lp-table th { text-align:left; font-size:.7rem; text-transform:uppercase; letter-spacing:.05em; color:#94a3b8; padding:.4rem .5rem; border-bottom:2px solid #e2e8f0; }
        .lp-table td { padding:.45rem .5rem; border-bottom:1px solid #f1f5f9; color:#334155; }
        .lp-pos { font-weight:700; }
        @media (max-width: 640px) { .lp-hide-m { display:none; } }
    </style>
</head>
<body>
<?php require __DIR__ . '/_nav.php'; ?>
<div class="lp-wrap">
    <a href="<?= htmlspecialchars($backUrl) ?>" style="font-size:.85rem;color:#2563eb;text-decoration:none">&larr; <?= htmlspecialchars($league['name']) ?> leaderboard</a>
    <div class="lp-card" style="margin-top:.6rem">
        <h1 style="font-size:1.35rem;font-weight:800;color:#1e293b;margin:0">
            <?= htmlspecialchars($player_name) ?>
            <?php if ($is_guest): ?><span style="font-size:.7rem;color:#94a3b8;font-weight:600">guest</span><?php endif; ?>
        </h1>
        <div style="font-size:.82rem;color:#64748b;margin-top:.2rem">
            <?= htmlspecialchars($league['name']) ?> ·
            <?= $range === 'all' ? 'All time' : ($range === 'ytd' ? 'This year' : ($range === 'custom' ? 'Custom range' : "Last $range days")) ?>
        </div>

        <?php if (!$games): ?>
        <p style="color:#64748b;margin-top:1.25rem">No finished games for this player in this range.</p>
        <?php else: ?>
        <div class="lp-tiles">
            <div class="lp-tile"><div class="v"><?= $tot['games'] ?></div><div class="l">Games</div></div>
            <div class="lp-tile"><div class="v" style="color:#d4a017"><?= $tot['wins'] ?></div><div class="l">Wins</div></div>
            <div class="lp-tile"><div class="v"><?= $tot['t'] > 0 ? round($tot['itm'] / $tot['t'] * 100) . '%' : '—' ?></div><div class="l">In the $</div></div>
            <div class="lp-tile"><div class="v" style="color:<?= $net >= 0 ? '#16a34a' : '#dc2626' ?>"><?= lp_money($net) ?></div><div class="l">Net</div></div>
            <div class="lp-tile"><div class="v" style="color:<?= $roi >= 0 ? '#16a34a' : '#dc2626' ?>"><?= $roi ?>%</div><div class="l">ROI</div></div>
            <div class="lp-tile"><div class="v"><?= $tot['t'] > 0 ? round($tot['scoreSum'] / $tot['t'], 1) : '—' ?></div><div class="l">Avg Score</div></div>
        </div>

        <table class="lp-table">
            <thead><tr>
                <th>Date</th><th>Event</th><th class="lp-hide-m">Type</th><th>Finish</th>
                <th class="lp-hide-m">In</th><th class="lp-hide-m">Out</th><th>Net</th><th class="lp-hide-m">Score</th>
            </tr></thead>
            <tbody>
            <?php foreach ($games as $g):
                $gnet = (int)$g['winnings'] - (int)$g['invested'];
                $isT  = $g['game_type'] === 'tournament';
            ?>
                <tr>
                    <td style="white-space:nowrap"><?= htmlspecialchars($g['start_date']) ?></td>
                    <td><a href="/event.php?id=<?= (int)$g['event_id'] ?>" style="color:#2563eb;text-decoration:none"><?= htmlspecialchars($g['title']) ?></a></td>
                    <td class="lp-hide-m"><?= $isT ? 'Tourney' : 'Cash' ?></td>
                    <td class="lp-pos" style="<?= $isT && (int)$g['finish_position'] === 1 ? 'color:#d4a017' : '' ?>">
                        <?= $isT ? lp_ordinal($g['finish_position']) . ' <span style="color:#94a3b8;font-weight:400">/ ' . (int)$g['field_size'] . '</span>' : '—' ?>
                    </td>
                    <td class="lp-hide-m">$<?= number_format((int)$g['invested'] / 100, ((int)$g['invested'] % 100) ? 2 : 0) ?></td>
                    <td class="lp-hide-m">$<?= number_format((int)$g['winnings'] / 100, ((int)$g['winnings'] % 100) ? 2 : 0) ?></td>
                    <td style="font-weight:700;color:<?= $gnet > 0 ? '#16a34a' : ($gnet < 0 ? '#dc2626' : '#64748b') ?>"><?= lp_money($gnet) ?></td>
                    <td class="lp-hide-m"><?= $isT ? $g['score'] : '—' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p style="font-size:.72rem;color:#94a3b8;margin-top:.6rem">Finished games only. Tournament winnings use recorded payouts; older games may predate payout tracking.</p>
        <?php endif; ?>
    </div>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
</body>
</html>
