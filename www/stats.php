<?php
require_once __DIR__ . '/auth.php';

$current   = require_login();
$db        = get_db();
$site_name = get_setting('site_name', 'Game Night');

// ── Leaderboard: all players with at least 1 finished game ──────────────
// First get per-game scores: (field_size - finish) / (field_size - 1) * 100
// Then aggregate per user
$stmt = $db->prepare("
    SELECT
        g.player_key,
        g.display_name,
        g.user_id,
        COUNT(*) as games,
        SUM(CASE WHEN g.finish_position = 1 THEN 1 ELSE 0 END) as wins,
        MIN(g.finish_position) as best_finish,
        ROUND(AVG(g.finish_position), 1) as avg_finish,
        ROUND(AVG(g.score), 1) as avg_score,
        SUM(g.score) as total_score
    FROM (
        SELECT
            pp.user_id,
            COALESCE(u.username, pp.display_name) as display_name,
            COALESCE(CAST(pp.user_id AS TEXT), 'g_' || LOWER(pp.display_name)) as player_key,
            COALESCE(pp.finish_position, pc.field_size) as finish_position,
            pp.session_id,
            pc.field_size,
            CASE WHEN pc.field_size > 1
                THEN ROUND(CAST(pc.field_size - COALESCE(pp.finish_position, pc.field_size) AS REAL) / pc.field_size * 80 + 20, 1)
                ELSE 100
            END as score
        FROM poker_players pp
        JOIN poker_sessions ps ON ps.id = pp.session_id
        LEFT JOIN users u ON u.id = pp.user_id
        JOIN (
            SELECT session_id, COUNT(*) as field_size
            FROM poker_players
            WHERE bought_in = 1 AND removed = 0
            GROUP BY session_id
        ) pc ON pc.session_id = pp.session_id
        WHERE pp.bought_in = 1 AND pp.removed = 0 AND pp.user_id IS NOT NULL
          AND ps.status = 'finished' AND ps.game_type = 'tournament'
    ) g
    GROUP BY g.player_key
    ORDER BY avg_score DESC, wins DESC, games ASC
");
$stmt->execute();
$leaderboard = $stmt->fetchAll();

// Find current user's stats
$myStats = null;
$myKey = (string)$current['id'];
foreach ($leaderboard as $row) {
    if ($row['player_key'] === $myKey) {
        $myStats = $row;
        break;
    }
}

function ordinal($n) {
    $n = (int)$n;
    if ($n <= 0) return '—';
    $s = ['th','st','nd','rd'];
    $v = $n % 100;
    return $n . ($s[($v - 20) % 10] ?? $s[$v] ?? $s[0]);
}

function fmtMoney($cents) {
    return '$' . number_format(abs((int)$cents) / 100, 2);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stats — <?= htmlspecialchars($site_name) ?></title>
    <link rel="stylesheet" href="/style.css">
    <style>
        .stats-wrap { max-width: 900px; margin: 1.5rem auto; padding: 0 1rem; }
        .stats-header { margin-bottom: 1.5rem; }
        .stats-header h1 { font-size: 1.5rem; font-weight: 700; margin: 0 0 .25rem; }
        .stats-header p { color: #64748b; font-size: .9rem; margin: 0; }

        .my-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: .5rem;
            margin-bottom: 2rem;
            background: #fff;
            border: 1.5px solid #e2e8f0;
            border-radius: 10px;
            padding: 1rem;
        }
        .stat-item {
            text-align: center;
            padding: .5rem .25rem;
        }
        .stat-value {
            font-size: 1.4rem;
            font-weight: 800;
            color: #1e293b;
            line-height: 1.2;
        }
        .stat-label {
            font-size: .7rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .04em;
            color: #94a3b8;
            margin-top: .15rem;
        }
        .stat-positive { color: #16a34a; }
        .stat-negative { color: #dc2626; }
        .stat-gold { color: #f59e0b; }

        .lb-table {
            width: 100%;
            border-collapse: collapse;
            background: #fff;
            border: 1.5px solid #e2e8f0;
            border-radius: 10px;
            overflow: hidden;
        }
        .lb-table th {
            background: #f8fafc;
            font-size: .72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .04em;
            color: #64748b;
            padding: .5rem .6rem;
            text-align: left;
            border-bottom: 1.5px solid #e2e8f0;
        }
        .lb-table td {
            padding: .5rem .6rem;
            font-size: .85rem;
            border-bottom: 1px solid #f1f5f9;
        }
        .lb-table tr:last-child td { border-bottom: none; }
        .lb-table tr.is-me { background: #eff6ff; }
        .lb-rank { font-weight: 700; color: #94a3b8; width: 2rem; text-align: center; }
        .lb-rank-1 { color: #f59e0b; }
        .lb-rank-2 { color: #94a3b8; }
        .lb-rank-3 { color: #b45309; }
        .lb-name { font-weight: 600; }
        .lb-profit { font-weight: 700; }

        .no-stats {
            text-align: center;
            padding: 3rem 1rem;
            color: #94a3b8;
        }
        .no-stats .icon { font-size: 3rem; margin-bottom: .75rem; }

        @media (max-width: 640px) {
            .stats-wrap { padding: 0 .5rem; margin: .75rem auto; }
            .my-stats { grid-template-columns: repeat(3, 1fr); gap: .35rem; padding: .6rem; }
            .stat-value { font-size: 1.1rem; }
            .stat-label { font-size: .6rem; }
            .lb-table th, .lb-table td { padding: .35rem .4rem; font-size: .75rem; }
            .lb-table .lb-hide-mobile { display: none; }
        }
    </style>
</head>
<body>

<?php $nav_active = 'stats'; $nav_user = $current; require __DIR__ . '/_nav.php'; ?>

<div class="stats-wrap">
    <div class="stats-header">
        <h1>Player Stats</h1>
        <p>Lifetime poker statistics from finished games.</p>
    </div>

    <?php if (empty($leaderboard)): ?>
    <div class="no-stats">
        <div class="icon">&#128200;</div>
        <p>No finished games yet. Stats will appear after your first completed tournament or cash game.</p>
    </div>

    <?php else: ?>

    <?php if ($myStats): ?>
    <div class="my-stats">
        <?php
        $games    = (int)$myStats['games'];
        $wins     = (int)$myStats['wins'];
        $losses   = $games - $wins;
        $winPct   = $games > 0 ? round($wins / $games * 100) : 0;
        ?>
        <div class="stat-item">
            <div class="stat-value"><?= $games ?></div>
            <div class="stat-label">Games</div>
        </div>
        <div class="stat-item">
            <div class="stat-value stat-gold"><?= $wins ?></div>
            <div class="stat-label">Wins</div>
        </div>
        <div class="stat-item">
            <div class="stat-value stat-negative"><?= $losses ?></div>
            <div class="stat-label">Losses</div>
        </div>
        <div class="stat-item">
            <div class="stat-value"><?= $winPct ?>%</div>
            <div class="stat-label">Win Rate</div>
        </div>
        <div class="stat-item">
            <div class="stat-value"><?= ordinal($myStats['best_finish']) ?></div>
            <div class="stat-label">Best Finish</div>
        </div>
        <div class="stat-item">
            <div class="stat-value"><?= $myStats['avg_finish'] ?></div>
            <div class="stat-label">Avg Finish</div>
        </div>
        <div class="stat-item">
            <div class="stat-value stat-gold"><?= $myStats['avg_score'] ?></div>
            <div class="stat-label">Avg Score</div>
        </div>
    </div>
    <?php endif; ?>

    <h2 style="font-size:1.1rem;font-weight:700;margin-bottom:.75rem">Leaderboard</h2>
    <table class="lb-table">
        <thead>
            <tr>
                <th>#</th>
                <th>Player</th>
                <th>Games</th>
                <th>Wins</th>
                <th>Losses</th>
                <th>Win%</th>
                <th>Score</th>
                <th class="lb-hide-mobile">Best</th>
                <th class="lb-hide-mobile">Avg</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($leaderboard as $i => $row):
            $rank    = $i + 1;
            $games   = (int)$row['games'];
            $wins    = (int)$row['wins'];
            $losses  = $games - $wins;
            $winPct  = $games > 0 ? round($wins / $games * 100) : 0;
            $isMe    = $row['player_key'] === $myKey;
            $rankCls = $rank <= 3 ? ' lb-rank-' . $rank : '';
        ?>
            <tr class="<?= $isMe ? 'is-me' : '' ?>">
                <td class="lb-rank<?= $rankCls ?>"><?= $rank ?></td>
                <td class="lb-name"><?= htmlspecialchars($row['display_name']) ?></td>
                <td><?= $games ?></td>
                <td class="stat-gold"><?= $wins ?></td>
                <td class="stat-negative"><?= $losses ?></td>
                <td><?= $winPct ?>%</td>
                <td class="stat-gold" style="font-weight:700"><?= $row['avg_score'] ?></td>
                <td class="lb-hide-mobile"><?= ordinal($row['best_finish']) ?></td>
                <td class="lb-hide-mobile"><?= $row['avg_finish'] ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <?php endif; ?>
</div>

<?php require __DIR__ . '/_footer.php'; ?>
</body>
</html>
