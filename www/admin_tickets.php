<?php
/**
 * Admin support queue: every ticket, open first, filterable by status.
 * Threads open in support_ticket.php (same page users see).
 */
require_once __DIR__ . '/auth.php';

$current = require_login();
if ($current['role'] !== 'admin') {
    http_response_code(403);
    exit('Admins only.');
}
$db = get_db();
$site_name = get_setting('site_name', 'Game Night');

$filter = in_array($_GET['status'] ?? '', ['open', 'resolved'], true) ? $_GET['status'] : '';
$where  = $filter !== '' ? 'WHERE t.status = ?' : '';
$rq = $db->prepare("SELECT t.*, u.username AS owner_name,
                           (SELECT COUNT(*) FROM ticket_messages m WHERE m.ticket_id = t.id) AS msg_count
                    FROM tickets t JOIN users u ON u.id = t.user_id
                    $where
                    ORDER BY CASE t.status WHEN 'open' THEN 0 ELSE 1 END, t.updated_at DESC");
$rq->execute($filter !== '' ? [$filter] : []);
$rows = $rq->fetchAll();
$openCount = (int)$db->query("SELECT COUNT(*) FROM tickets WHERE status = 'open'")->fetchColumn();

$viewer_tz = new DateTimeZone(display_timezone((int)$current['id']));
$utc_tz    = new DateTimeZone('UTC');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Support Tickets &ndash; <?= htmlspecialchars($site_name) ?></title>
    <link rel="stylesheet" href="/style.css?v=<?= htmlspecialchars(APP_VERSION . '.' . (@filemtime(__DIR__ . '/style.css') ?: 0)) ?>">
    <style>
        .at-wrap { max-width: 900px; margin: 1.25rem auto 2rem; padding: 0 1rem; }
        .at-table { width: 100%; border-collapse: collapse; font-size: .875rem; background: #fff; border: 1.5px solid #e2e8f0; border-radius: 10px; overflow: hidden; }
        .at-table th { background: #f1f5f9; color: #475569; font-weight: 600; font-size: .72rem; text-transform: uppercase; letter-spacing: .04em; padding: .55rem .75rem; text-align: left; border-bottom: 2px solid #e2e8f0; }
        .at-table td { padding: .55rem .75rem; border-bottom: 1px solid #e2e8f0; color: #334155; }
        .at-table tr:hover td { background: #f8fafc; cursor: pointer; }
        .sp-chip { font-size: .66rem; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; border-radius: 5px; padding: .08rem .4rem; }
        .sp-open { color: #92400e; background: #fef3c7; border: 1px solid #fde68a; }
        .sp-resolved { color: #166534; background: #dcfce7; border: 1px solid #bbf7d0; }
        .at-filter { display: inline-flex; gap: .4rem; margin: .6rem 0 .9rem; }
        .at-filter a { font-size: .8rem; padding: .25rem .7rem; border-radius: 999px; border: 1.5px solid #cbd5e1; text-decoration: none; color: #475569; }
        .at-filter a.on { background: #2563eb; border-color: #2563eb; color: #fff; }
    </style>
</head>
<body>
<?php $nav_active = 'site-settings'; $nav_user = $current; require __DIR__ . '/_nav.php'; ?>
<div class="at-wrap">
    <?php $admin_tab = 'tickets'; require __DIR__ . '/_admin_tabs.php'; ?>
    <h1 style="font-size:1.3rem;font-weight:800;color:#1e293b;margin:.75rem 0 .2rem">Support Tickets</h1>
    <p style="color:#64748b;font-size:.88rem;margin:0"><?= $openCount ?> open</p>
    <div class="at-filter">
        <a href="/admin_tickets.php" class="<?= $filter === '' ? 'on' : '' ?>">All</a>
        <a href="/admin_tickets.php?status=open" class="<?= $filter === 'open' ? 'on' : '' ?>">Open</a>
        <a href="/admin_tickets.php?status=resolved" class="<?= $filter === 'resolved' ? 'on' : '' ?>">Resolved</a>
    </div>

    <?php if (!$rows): ?>
    <p style="color:#94a3b8">No tickets<?= $filter !== '' ? " with status \"$filter\"" : '' ?>.</p>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table class="at-table">
        <thead><tr><th>#</th><th>Status</th><th>From</th><th>Subject</th><th>Msgs</th><th>Updated</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $t):
            try { $when = (new DateTime((string)$t['updated_at'], $utc_tz))->setTimezone($viewer_tz)->format('M j, g:i A'); }
            catch (Throwable $e) { $when = ''; }
        ?>
        <tr onclick="location.href='/support_ticket.php?id=<?= (int)$t['id'] ?>'">
            <td><?= (int)$t['id'] ?></td>
            <td><span class="sp-chip <?= $t['status'] === 'open' ? 'sp-open' : 'sp-resolved' ?>"><?= htmlspecialchars($t['status']) ?></span></td>
            <td><?= htmlspecialchars($t['owner_name']) ?></td>
            <td style="font-weight:600;color:#1e293b"><?= htmlspecialchars($t['subject']) ?></td>
            <td><?= (int)$t['msg_count'] ?></td>
            <td style="white-space:nowrap;color:#94a3b8"><?= htmlspecialchars($when) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
</body>
</html>
