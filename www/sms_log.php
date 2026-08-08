<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/version.php';

$current = require_login();
$db      = get_db();
$isAdmin = $current['role'] === 'admin';

// Host scope: non-admins may view the log ONLY filtered to an event they manage
// (linked from the event roster's delivery status). Admins see everything.
$f_event = (int)($_GET['event'] ?? 0);
if (!$isAdmin) {
    if ($f_event <= 0 || !can_manage_event($db, $f_event, (int)$current['id'], false)) {
        http_response_code(403);
        exit('Access denied.');
    }
}

$site_name = get_setting('site_name', 'Game Night');
$token     = $_SESSION['csrf_token'] ?? ($_SESSION['csrf_token'] = bin2hex(random_bytes(32)));

// Handle clear-log action (admin only)
if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'sms_clear_log'
    && hash_equals($token, $_POST['csrf_token'] ?? '')) {
    $db->exec('DELETE FROM sms_log');
    db_log_activity($current['id'], 'cleared SMS log');
    $_SESSION['flash'] = ['type' => 'success', 'msg' => 'SMS log cleared.'];
    header('Location: /sms_log.php');
    exit;
}

// ── Filters ───────────────────────────────────────────────────────────────────
$f_status  = in_array($_GET['status'] ?? '', ['sent', 'failed', 'queued', 'received'], true) ? $_GET['status'] : '';
$f_channel = in_array($_GET['channel'] ?? '', ['email', 'sms', 'whatsapp'], true) ? $_GET['channel'] : '';
$f_q       = trim($_GET['q'] ?? '');
$f_from    = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : '';
$f_to      = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '')   ? $_GET['to']   : '';

$where  = [];
$params = [];
if ($f_event > 0)  { $where[] = 'event_id = ?';                        $params[] = $f_event; }
if ($f_status)     { $where[] = 'status = ?';                          $params[] = $f_status; }
if ($f_channel === 'email')    { $where[] = "provider = 'email'"; }
if ($f_channel === 'whatsapp') { $where[] = "provider = 'waha'"; }
if ($f_channel === 'sms')      { $where[] = "provider NOT IN ('email','waha') AND provider IS NOT NULL"; }
if ($f_q !== '')   { $where[] = '(phone LIKE ? OR username LIKE ?)';   $params[] = "%$f_q%"; $params[] = "%$f_q%"; }
if ($f_from)       { $where[] = "created_at >= ?";                     $params[] = $f_from . ' 00:00:00'; }
if ($f_to)         { $where[] = "created_at <= ?";                     $params[] = $f_to . ' 23:59:59'; }
$where_sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$cnt = $db->prepare("SELECT COUNT(*) FROM sms_log $where_sql");
$cnt->execute($params);
$smsLogCount = (int)$cnt->fetchColumn();

$smsLogPage  = max(1, (int)($_GET['page'] ?? 1));
$smsPerPage  = 50;
$smsOffset   = ($smsLogPage - 1) * $smsPerPage;
$smsLogPages = max(1, (int)ceil($smsLogCount / $smsPerPage));
$smsLogs     = $db->prepare("SELECT * FROM sms_log $where_sql ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?");
$smsLogs->execute(array_merge($params, [$smsPerPage, $smsOffset]));
$smsRows     = $smsLogs->fetchAll();

// Filter querystring (minus page) for pager links
$f_qs = http_build_query(array_filter([
    'event' => $f_event ?: null, 'status' => $f_status ?: null, 'channel' => $f_channel ?: null,
    'q' => $f_q !== '' ? $f_q : null, 'from' => $f_from ?: null, 'to' => $f_to ?: null,
]));
$f_event_title = '';
if ($f_event > 0) {
    $et = $db->prepare('SELECT title FROM events WHERE id = ?');
    $et->execute([$f_event]);
    $f_event_title = (string)($et->fetchColumn() ?: '');
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notification Log — <?= htmlspecialchars($site_name) ?></title>
    <link rel="stylesheet" href="/style.css?v=<?= htmlspecialchars(APP_VERSION . '.' . (@filemtime(__DIR__ . '/style.css') ?: 0)) ?>">
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
        <h2>Notification Log (<?= $smsLogCount ?>)<?= $f_event_title !== '' ? ' — ' . htmlspecialchars($f_event_title) : '' ?></h2>
        <div class="sms-log-actions">
            <?php if ($isAdmin && $smsLogCount > 0): ?>
            <form method="post" style="margin:0" onsubmit="return pkConfirmForm(this, 'Clear all SMS logs?', {danger:true})">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">
                <input type="hidden" name="action" value="sms_clear_log">
                <button type="submit" class="btn btn-outline" style="font-size:.8rem;padding:.35rem .75rem;color:#dc2626;border-color:#fca5a5">Clear Log</button>
            </form>
            <?php endif; ?>
            <a href="/sms_conversations.php<?= $f_event > 0 ? '?event=' . $f_event : '' ?>" class="btn btn-outline" style="font-size:.8rem;padding:.35rem .75rem">Conversations</a>
            <?php if ($isAdmin): ?>
            <a href="/admin_settings.php?tab=sms" class="btn btn-outline" style="font-size:.8rem;padding:.35rem .75rem">Back to SMS Settings</a>
            <?php elseif ($f_event > 0): ?>
            <a href="/event.php?id=<?= $f_event ?>" class="btn btn-outline" style="font-size:.8rem;padding:.35rem .75rem">Back to event</a>
            <?php endif; ?>
        </div>
    </div>

    <form method="get" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;margin-bottom:1rem;font-size:.83rem">
        <?php if ($f_event > 0): ?><input type="hidden" name="event" value="<?= $f_event ?>"><?php endif; ?>
        <select name="status" style="padding:.35rem .5rem;border:1.5px solid #e2e8f0;border-radius:6px">
            <option value="">Any status</option>
            <?php foreach (['sent', 'failed', 'queued', 'received'] as $st): ?>
            <option value="<?= $st ?>" <?= $f_status === $st ? 'selected' : '' ?>><?= ucfirst($st) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="channel" style="padding:.35rem .5rem;border:1.5px solid #e2e8f0;border-radius:6px">
            <option value="">Any channel</option>
            <option value="email"    <?= $f_channel === 'email'    ? 'selected' : '' ?>>Email</option>
            <option value="sms"      <?= $f_channel === 'sms'      ? 'selected' : '' ?>>SMS</option>
            <option value="whatsapp" <?= $f_channel === 'whatsapp' ? 'selected' : '' ?>>WhatsApp</option>
        </select>
        <input type="text" name="q" value="<?= htmlspecialchars($f_q) ?>" placeholder="Recipient (name, email, phone)"
               style="padding:.35rem .5rem;border:1.5px solid #e2e8f0;border-radius:6px;min-width:200px">
        <input type="date" name="from" value="<?= htmlspecialchars($f_from) ?>" style="padding:.32rem .5rem;border:1.5px solid #e2e8f0;border-radius:6px">
        <span style="color:#94a3b8">&rarr;</span>
        <input type="date" name="to" value="<?= htmlspecialchars($f_to) ?>" style="padding:.32rem .5rem;border:1.5px solid #e2e8f0;border-radius:6px">
        <button type="submit" class="btn btn-primary" style="font-size:.8rem;padding:.4rem .8rem">Filter</button>
        <?php if ($f_status || $f_channel || $f_q !== '' || $f_from || $f_to): ?>
        <a href="/sms_log.php<?= $f_event > 0 ? '?event=' . $f_event : '' ?>" style="font-size:.8rem;color:#64748b">Reset</a>
        <?php endif; ?>
    </form>

    <?php if (empty($smsRows)): ?>
        <p style="color:#94a3b8">No notifications match<?= $where ? ' these filters' : '' ?>.</p>
    <?php else: ?>
    <div class="table-card" style="overflow:visible">
        <div class="sms-log-table-wrap">
            <table class="sms-log-table">
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Dir</th>
                        <th>To</th>
                        <th>Recipient</th>
                        <th>Event</th>
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
                        <td class="col-phone"><?= htmlspecialchars($log['username'] ?? '') ?: '<span style="color:#cbd5e1">&mdash;</span>' ?></td>
                        <td class="col-phone">
                            <?php if (!empty($log['event_id'])): ?>
                                <a href="/event.php?id=<?= (int)$log['event_id'] ?>" style="color:#2563eb;text-decoration:none">#<?= (int)$log['event_id'] ?></a>
                            <?php else: ?>
                                <span style="color:#cbd5e1">&mdash;</span>
                            <?php endif; ?>
                        </td>
                        <td class="col-msg" title="<?= htmlspecialchars($log['body']) ?>"><?= htmlspecialchars($log['body']) ?></td>
                        <td><?= htmlspecialchars($log['provider'] ?? '') ?></td>
                        <td>
                            <?php if ($log['status'] === 'sent' || $log['status'] === 'received'): ?>
                                <span style="color:#16a34a;font-weight:600"><?= htmlspecialchars($log['status']) ?></span>
                            <?php elseif ($log['status'] === 'queued'): ?>
                                <span style="color:#d97706;font-weight:600" title="Pending retry: <?= htmlspecialchars($log['error'] ?? '') ?>"><?= htmlspecialchars($log['status']) ?></span>
                            <?php else: ?>
                                <span style="color:#dc2626;font-weight:600" title="<?= htmlspecialchars($log['error'] ?? '') ?>"><?= htmlspecialchars($log['status']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="col-raw">
                            <?php if (!empty($log['raw_response'])): ?>
                                <button type="button"
                                        data-raw="<?= htmlspecialchars($log['raw_response'], ENT_QUOTES) ?>"
                                        onclick="var b=this;pkCopy(this.dataset.raw).then(function(ok){ b.textContent=ok?'Copied!':'Copy failed'; setTimeout(function(){ b.textContent='Copy'; },1500); });"
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

    <?php if ($smsLogPages > 1): $pagerBase = '?' . ($f_qs !== '' ? $f_qs . '&' : ''); ?>
    <div class="pager">
        <?php if ($smsLogPage > 1): ?>
        <a href="<?= htmlspecialchars($pagerBase) ?>page=<?= $smsLogPage - 1 ?>" class="btn btn-outline" style="font-size:.8rem;padding:.3rem .6rem">&laquo; Prev</a>
        <?php endif; ?>
        <span>Page <?= $smsLogPage ?> of <?= $smsLogPages ?></span>
        <?php if ($smsLogPage < $smsLogPages): ?>
        <a href="<?= htmlspecialchars($pagerBase) ?>page=<?= $smsLogPage + 1 ?>" class="btn btn-outline" style="font-size:.8rem;padding:.3rem .6rem">Next &raquo;</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/_footer.php'; ?>
</body>
</html>
