<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/version.php';

$current = require_login();
if ($current['role'] !== 'admin') {
    http_response_code(403);
    exit('Access denied.');
}

$db        = get_db();
$site_name = get_setting('site_name', 'Game Night');
$token     = $_SESSION['csrf_token'] ?? ($_SESSION['csrf_token'] = bin2hex(random_bytes(32)));

// Handle clear-log action
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'sms_clear_log'
    && hash_equals($token, $_POST['csrf_token'] ?? '')) {
    $db->exec('DELETE FROM sms_log');
    db_log_activity($current['id'], 'cleared SMS log');
    $_SESSION['flash'] = ['type' => 'success', 'msg' => 'SMS log cleared.'];
    header('Location: /sms_log.php');
    exit;
}

$smsLogCount = (int)$db->query('SELECT COUNT(*) FROM sms_log')->fetchColumn();
$smsLogPage  = max(1, (int)($_GET['page'] ?? 1));
$smsPerPage  = 50;
$smsOffset   = ($smsLogPage - 1) * $smsPerPage;
$smsLogPages = max(1, (int)ceil($smsLogCount / $smsPerPage));
$smsLogs     = $db->prepare('SELECT * FROM sms_log ORDER BY created_at DESC LIMIT ? OFFSET ?');
$smsLogs->execute([$smsPerPage, $smsOffset]);
$smsRows     = $smsLogs->fetchAll();

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SMS Log — <?= htmlspecialchars($site_name) ?></title>
    <link rel="stylesheet" href="/style.css">
    <style>
        .sms-log-wrap { max-width:1200px; margin:1.5rem auto; padding:0 1rem; }
        .sms-log-header { display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:.75rem; margin-bottom:1rem; }
        .sms-log-header h2 { margin:0; font-size:1.25rem; }
        .sms-log-actions { display:flex; gap:.5rem; align-items:center; }
        .sms-log-table-wrap { overflow-x:auto; -webkit-overflow-scrolling:touch; }
        .sms-log-table { width:100%; border-collapse:collapse; font-size:.85rem; }
        .sms-log-table th { background:#f1f5f9; text-align:left; padding:.6rem 1rem; font-size:.78rem; color:#64748b; text-transform:uppercase; letter-spacing:.04em; white-space:nowrap; position:sticky; top:0; z-index:1; }
        .sms-log-table td { padding:.55rem 1rem; border-top:1px solid #e2e8f0; }
        .sms-log-table tr:hover td { background:#f8fafc; }
        .sms-log-table .col-time { white-space:nowrap; color:#64748b; }
        .sms-log-table .col-phone { white-space:nowrap; }
        .sms-log-table .col-msg { max-width:400px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .sms-log-table .col-raw { white-space:nowrap; }
        .pager { display:flex; justify-content:center; gap:.5rem; margin-top:1rem; align-items:center; }
        .pager span { font-size:.85rem; color:#64748b; }
    </style>
</head>
<body>
<?php $nav_active = 'site-settings'; include __DIR__ . '/_nav.php'; ?>

<div class="sms-log-wrap">
    <?php if ($flash): ?>
    <div class="alert alert-<?= $flash['type'] ?>" style="margin-bottom:1rem"><?= htmlspecialchars($flash['msg']) ?></div>
    <?php endif; ?>

    <div class="sms-log-header">
        <h2>SMS Log (<?= $smsLogCount ?>)</h2>
        <div class="sms-log-actions">
            <?php if ($smsLogCount > 0): ?>
            <form method="post" style="margin:0" onsubmit="return confirm('Clear all SMS logs?')">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">
                <input type="hidden" name="action" value="sms_clear_log">
                <button type="submit" class="btn btn-outline" style="font-size:.8rem;padding:.35rem .75rem;color:#dc2626;border-color:#fca5a5">Clear Log</button>
            </form>
            <?php endif; ?>
            <a href="/admin_settings.php?tab=sms" class="btn btn-outline" style="font-size:.8rem;padding:.35rem .75rem">Back to SMS Settings</a>
        </div>
    </div>

    <?php if (empty($smsRows)): ?>
        <p style="color:#94a3b8">No SMS messages logged yet.</p>
    <?php else: ?>
    <div class="table-card" style="overflow:visible">
        <div class="sms-log-table-wrap">
            <table class="sms-log-table">
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Dir</th>
                        <th>Phone</th>
                        <th>Message</th>
                        <th>Provider</th>
                        <th>Status</th>
                        <th>Raw</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($smsRows as $log): ?>
                    <tr>
                        <td class="col-time"><?= htmlspecialchars($log['created_at']) ?></td>
                        <td>
                            <?php if ($log['direction'] === 'inbound'): ?>
                                <span style="color:#16a34a;font-weight:600" title="Inbound">&#x2B07;</span>
                            <?php else: ?>
                                <span style="color:#2563eb;font-weight:600" title="Outbound">&#x2B06;</span>
                            <?php endif; ?>
                        </td>
                        <td class="col-phone"><?= htmlspecialchars($log['phone']) ?></td>
                        <td class="col-msg" title="<?= htmlspecialchars($log['body']) ?>"><?= htmlspecialchars($log['body']) ?></td>
                        <td><?= htmlspecialchars($log['provider'] ?? '') ?></td>
                        <td>
                            <?php if ($log['status'] === 'sent' || $log['status'] === 'received'): ?>
                                <span style="color:#16a34a;font-weight:600"><?= htmlspecialchars($log['status']) ?></span>
                            <?php else: ?>
                                <span style="color:#dc2626;font-weight:600" title="<?= htmlspecialchars($log['error'] ?? '') ?>"><?= htmlspecialchars($log['status']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="col-raw">
                            <?php if (!empty($log['raw_response'])): ?>
                                <button type="button"
                                        data-raw="<?= htmlspecialchars($log['raw_response'], ENT_QUOTES) ?>"
                                        onclick="navigator.clipboard.writeText(this.dataset.raw).then(function(){ var b=event.target; b.textContent='Copied!'; setTimeout(function(){ b.textContent='Copy'; },1500); });"
                                        class="btn btn-outline btn-sm">Copy</button>
                            <?php else: ?>
                                <span style="color:#94a3b8">&mdash;</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($smsLogPages > 1): ?>
    <div class="pager">
        <?php if ($smsLogPage > 1): ?>
        <a href="?page=<?= $smsLogPage - 1 ?>" class="btn btn-outline" style="font-size:.8rem;padding:.3rem .6rem">&laquo; Prev</a>
        <?php endif; ?>
        <span>Page <?= $smsLogPage ?> of <?= $smsLogPages ?></span>
        <?php if ($smsLogPage < $smsLogPages): ?>
        <a href="?page=<?= $smsLogPage + 1 ?>" class="btn btn-outline" style="font-size:.8rem;padding:.3rem .6rem">Next &raquo;</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/_footer.php'; ?>
</body>
</html>
