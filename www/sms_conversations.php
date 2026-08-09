<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/version.php';
require_once __DIR__ . '/_sms_conversations.php';

$current = require_login();
$db      = get_db();
$isAdmin = $current['role'] === 'admin';

// Host scope, same gate as sms_log.php: non-admins may view ONLY an event they
// manage. Admins with no event see the unassigned bucket.
$f_event = (int)($_GET['event'] ?? 0);
if (!$isAdmin) {
    if ($f_event <= 0 || !can_manage_event($db, $f_event, (int)$current['id'], false)) {
        http_response_code(403);
        exit('Access denied.');
    }
}

$site_name = get_setting('site_name', 'Game Night');
$token     = csrf_token();
$f_phone   = preg_match('/^\d{10}$/', $_GET['phone'] ?? '') ? $_GET['phone'] : '';

$fmt_phone = static function (string $d): string {
    return substr($d, 0, 3) . '-' . substr($d, 3, 3) . '-' . substr($d, 6, 4);
};

$event_title = '';
if ($f_event > 0) {
    $et = $db->prepare('SELECT title FROM events WHERE id = ?');
    $et->execute([$f_event]);
    $event_title = (string)($et->fetchColumn() ?: '');
}

$mode = $f_event > 0 ? ($f_phone !== '' ? 'thread' : 'list') : 'unassigned';

if ($mode === 'thread') {
    $q = $db->prepare("SELECT * FROM sms_log
                       WHERE event_id = ? AND phone_digits = ? AND is_conversation = 1
                       ORDER BY created_at ASC, id ASC");
    $q->execute([$f_event, $f_phone]);
    $messages = $q->fetchAll();

    $display = '';
    foreach (array_reverse($messages) as $m) {
        if (!empty($m['username'])) { $display = $m['username']; break; }
    }
    if ($display === '') $display = $fmt_phone($f_phone);

    // Soft opt-out hint: STOP sets preferred_contact='email', indistinguishable
    // from the default, so warn rather than block (carriers enforce STOP anyway).
    $pq = $db->prepare("SELECT preferred_contact FROM users WHERE phone = ? OR phone = ?");
    $pq->execute([$fmt_phone($f_phone), $f_phone]);
    $pc = $pq->fetchColumn();
    $optout_hint = ($pc !== false && in_array((string)$pc, ['email', 'none'], true));

    $channel = sms_conv_channel($db, $f_phone);
    $last_id = $messages ? (int)end($messages)['id'] : 0;
} elseif ($mode === 'list') {
    $q = $db->prepare("SELECT phone_digits, MAX(id) AS last_id, MAX(created_at) AS last_at, COUNT(*) AS cnt
                       FROM sms_log
                       WHERE event_id = ? AND phone_digits IS NOT NULL AND is_conversation = 1
                       GROUP BY phone_digits ORDER BY last_at DESC");
    $q->execute([$f_event]);
    $convos = $q->fetchAll();
    $detail = $db->prepare('SELECT direction, body, provider, username FROM sms_log WHERE id = ?');
    $name_q = $db->prepare("SELECT username FROM sms_log
                            WHERE event_id = ? AND phone_digits = ? AND username IS NOT NULL AND username != ''
                            ORDER BY id DESC LIMIT 1");
    foreach ($convos as &$c) {
        $detail->execute([(int)$c['last_id']]);
        $c['last'] = $detail->fetch() ?: ['direction' => '', 'body' => '', 'provider' => '', 'username' => ''];
        $name_q->execute([$f_event, $c['phone_digits']]);
        $c['display'] = (string)($name_q->fetchColumn() ?: $fmt_phone($c['phone_digits']));
    }
    unset($c);
} else {
    // Admin unassigned bucket: inbound with no event attribution.
    $q = $db->query("SELECT phone_digits, MAX(id) AS last_id, MAX(created_at) AS last_at, COUNT(*) AS cnt
                     FROM sms_log
                     WHERE direction = 'inbound' AND event_id IS NULL AND phone_digits IS NOT NULL
                     GROUP BY phone_digits ORDER BY last_at DESC LIMIT 100");
    $unassigned = $q->fetchAll();
    $detail = $db->prepare('SELECT body FROM sms_log WHERE id = ?');
    foreach ($unassigned as &$u) {
        $detail->execute([(int)$u['last_id']]);
        $u['last_body'] = (string)($detail->fetchColumn() ?: '');
    }
    unset($u);

    $tz    = get_setting('timezone', 'UTC');
    $today = (new DateTime('now', new DateTimeZone($tz)))->format('Y-m-d');
    $eq = $db->prepare("SELECT id, title, start_date FROM events
                        WHERE start_date >= date(?, '-30 days')
                        ORDER BY start_date ASC, id ASC LIMIT 100");
    $eq->execute([$today]);
    $assign_events = $eq->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Conversations — <?= htmlspecialchars($site_name) ?></title>
    <link rel="stylesheet" href="/style.css?v=<?= htmlspecialchars(APP_VERSION . '.' . (@filemtime(__DIR__ . '/style.css') ?: 0)) ?>">
    <style>
        .conv-wrap { max-width:820px; margin:1.5rem auto; padding:0 1rem; }
        .conv-header { display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:.75rem; margin-bottom:1rem; }
        .conv-header h2 { margin:0; font-size:1.25rem; }
        .conv-list { list-style:none; margin:0; padding:0; }
        .conv-list li { border:1px solid #e2e8f0; border-radius:10px; margin-bottom:.6rem; background:#fff; }
        .conv-list a { display:flex; justify-content:space-between; gap:1rem; align-items:center; padding:.75rem 1rem; text-decoration:none; color:inherit; }
        .conv-list .conv-who { font-weight:600; white-space:nowrap; }
        .conv-list .conv-preview { color:#64748b; font-size:.85rem; flex:1; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; min-width:0; }
        .conv-list .conv-when { color:#94a3b8; font-size:.78rem; white-space:nowrap; }
        .conv-badge { display:inline-block; font-size:.68rem; padding:.1rem .45rem; border-radius:999px; background:#f1f5f9; color:#475569; vertical-align:middle; margin-left:.4rem; }
        .conv-thread { display:flex; flex-direction:column; gap:.5rem; padding:1rem 0; }
        .conv-bubble { max-width:75%; padding:.55rem .85rem; border-radius:14px; font-size:.92rem; line-height:1.35; white-space:pre-wrap; word-break:break-word; }
        .conv-in  { align-self:flex-start; background:#dcfce7; border-bottom-left-radius:4px; }
        .conv-out { align-self:flex-end; background:#dbeafe; border-bottom-right-radius:4px; }
        .conv-meta { font-size:.7rem; color:#94a3b8; margin-top:.25rem; }
        .conv-err { font-size:.75rem; color:#dc2626; margin-top:.2rem; }
        .conv-compose { display:flex; gap:.5rem; align-items:flex-end; margin-top:.75rem; }
        .conv-compose textarea { flex:1; min-height:60px; padding:.55rem .7rem; border:1.5px solid #e2e8f0; border-radius:10px; font:inherit; resize:vertical; }
        .conv-note { font-size:.75rem; color:#94a3b8; margin-top:.5rem; }
        .conv-warn { background:#fef9c3; border:1px solid #fde047; border-radius:8px; padding:.5rem .8rem; font-size:.82rem; color:#713f12; margin-bottom:.75rem; }
        .assign-table { width:100%; border-collapse:collapse; font-size:.85rem; }
        .assign-table th { text-align:left; padding:.5rem .75rem; background:#f1f5f9; font-size:.75rem; color:#64748b; text-transform:uppercase; }
        .assign-table td { padding:.5rem .75rem; border-top:1px solid #e2e8f0; vertical-align:middle; }
    </style>
</head>
<body>
<?php $nav_active = 'site-settings'; include __DIR__ . '/_nav.php'; ?>

<div class="conv-wrap">

<?php if ($mode === 'thread'): ?>
    <div class="conv-header">
        <h2><?= htmlspecialchars($display) ?>
            <span class="conv-badge"><?= $channel === 'whatsapp' ? 'WhatsApp' : 'SMS' ?></span>
        </h2>
        <a href="/sms_conversations.php?event=<?= $f_event ?>" class="btn btn-outline" style="font-size:.8rem;padding:.35rem .75rem">All conversations</a>
    </div>
    <p style="margin:-.5rem 0 .75rem;color:#64748b;font-size:.85rem">
        <?= htmlspecialchars($fmt_phone($f_phone)) ?> &middot; <?= htmlspecialchars($event_title) ?>
    </p>
    <?php if ($optout_hint): ?>
    <div class="conv-warn">This player's preferred channel is not texting — they may have opted out (STOP). A reply here may not reach them.</div>
    <?php endif; ?>

    <div class="conv-thread" id="convThread">
        <?php foreach ($messages as $m): ?>
        <div class="conv-bubble <?= $m['direction'] === 'inbound' ? 'conv-in' : 'conv-out' ?>" data-id="<?= (int)$m['id'] ?>">
            <?= htmlspecialchars($m['body']) ?>
            <div class="conv-meta"><?= htmlspecialchars($m['created_at']) ?> UTC</div>
            <?php if ($m['direction'] !== 'inbound' && $m['status'] === 'failed'): ?>
            <div class="conv-err">Failed: <?= htmlspecialchars($m['error'] ?? 'unknown error') ?></div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php if (empty($messages)): ?>
        <p style="color:#94a3b8">No messages yet.</p>
        <?php endif; ?>
    </div>

    <div class="conv-compose">
        <textarea id="convBody" maxlength="1000" placeholder="Reply to <?= htmlspecialchars($display) ?>…"></textarea>
        <button type="button" class="btn btn-primary" id="convSend">Send</button>
    </div>
    <div class="conv-err" id="convError" style="display:none"></div>
    <p class="conv-note">Sent as <?= $channel === 'whatsapp' ? 'WhatsApp' : 'SMS' ?> from the shared number. Texts over 160 characters go out as multiple segments. Conversations are part of the notification log and are kept for 90 days.</p>

    <script nonce="<?= csp_nonce() ?>">
    (function () {
        var eventId = <?= (int)$f_event ?>, phone = <?= json_encode($f_phone) ?>, lastId = <?= (int)$last_id ?>;
        var thread = document.getElementById('convThread');
        var errBox = document.getElementById('convError');
        thread.scrollTop = thread.scrollHeight;
        window.scrollTo(0, document.body.scrollHeight);

        function addBubble(m) {
            if (thread.querySelector('[data-id="' + m.id + '"]')) return;
            var b = document.createElement('div');
            b.className = 'conv-bubble ' + (m.direction === 'inbound' ? 'conv-in' : 'conv-out');
            b.dataset.id = m.id;
            b.textContent = m.body;
            var meta = document.createElement('div');
            meta.className = 'conv-meta';
            meta.textContent = m.created_at + ' UTC';
            b.appendChild(meta);
            if (m.direction !== 'inbound' && m.status === 'failed') {
                var err = document.createElement('div');
                err.className = 'conv-err';
                err.textContent = 'Failed: ' + (m.error || 'unknown error');
                b.appendChild(err);
            }
            thread.appendChild(b);
            if (m.id > lastId) lastId = m.id;
            window.scrollTo(0, document.body.scrollHeight);
        }

        function poll() {
            fetch('/sms_conversations_dl.php?action=thread&event=' + eventId + '&phone=' + phone + '&after=' + lastId)
                .then(function (r) { return r.json(); })
                .then(function (d) { if (d.ok) d.messages.forEach(addBubble); })
                .catch(function () {});
        }
        setInterval(poll, 15000);

        document.getElementById('convSend').addEventListener('click', function () {
            var ta = document.getElementById('convBody');
            var body = ta.value.trim();
            if (!body) return;
            this.disabled = true;
            errBox.style.display = 'none';
            var fd = new FormData();
            fd.append('csrf_token', <?= json_encode($token) ?>);
            fd.append('action', 'send');
            fd.append('event', eventId);
            fd.append('phone', phone);
            fd.append('body', body);
            var btn = this;
            fetch('/sms_conversations_dl.php', { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    btn.disabled = false;
                    if (d.ok) { ta.value = ''; addBubble(d.message); }
                    else { errBox.textContent = d.error || 'Send failed.'; errBox.style.display = 'block'; }
                })
                .catch(function () {
                    btn.disabled = false;
                    errBox.textContent = 'Network error.'; errBox.style.display = 'block';
                });
        });
    })();
    </script>

<?php elseif ($mode === 'list'): ?>
    <div class="conv-header">
        <h2>Conversations<?= $event_title !== '' ? ' — ' . htmlspecialchars($event_title) : '' ?></h2>
        <div style="display:flex;gap:.5rem">
            <a href="/sms_log.php?event=<?= $f_event ?>" class="btn btn-outline" style="font-size:.8rem;padding:.35rem .75rem">Notification log</a>
            <a href="/event.php?id=<?= $f_event ?>" class="btn btn-outline" style="font-size:.8rem;padding:.35rem .75rem">Back to event</a>
        </div>
    </div>
    <?php if (empty($convos)): ?>
    <p style="color:#94a3b8">No text conversations for this event yet. Free-text replies from your guests will show up here (commands, RSVPs, and automated messages stay in the notification log).</p>
    <?php else: ?>
    <ul class="conv-list">
        <?php foreach ($convos as $c): ?>
        <li>
            <a href="/sms_conversations.php?event=<?= $f_event ?>&phone=<?= htmlspecialchars($c['phone_digits']) ?>">
                <span class="conv-who"><?= htmlspecialchars($c['display']) ?>
                    <?php if (($c['last']['provider'] ?? '') === 'whatsapp' || ($c['last']['provider'] ?? '') === 'waha'): ?>
                    <span class="conv-badge">WhatsApp</span>
                    <?php endif; ?>
                </span>
                <span class="conv-preview"><?= $c['last']['direction'] === 'inbound' ? '&#x2B07;' : '&#x2B06;' ?> <?= htmlspecialchars(mb_substr((string)$c['last']['body'], 0, 80)) ?></span>
                <span class="conv-when"><?= htmlspecialchars($c['last_at']) ?></span>
            </a>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
    <p class="conv-note">Conversations are part of the notification log and are kept for 90 days.</p>

<?php else: ?>
    <div class="conv-header">
        <h2>Unassigned text replies</h2>
        <a href="/sms_log.php" class="btn btn-outline" style="font-size:.8rem;padding:.35rem .75rem">Notification log</a>
    </div>
    <p style="margin:-.5rem 0 1rem;color:#64748b;font-size:.85rem">
        Inbound texts that couldn't be matched to an event. Assign them so the host sees the conversation.
    </p>
    <?php if (empty($unassigned)): ?>
    <p style="color:#94a3b8">Nothing unassigned. All inbound texts were matched to an event.</p>
    <?php else: ?>
    <div class="table-card" style="overflow:visible">
        <table class="assign-table">
            <thead><tr><th>Phone</th><th>Last message</th><th>When</th><th>#</th><th>Assign to event</th></tr></thead>
            <tbody>
                <?php foreach ($unassigned as $u): ?>
                <tr data-phone="<?= htmlspecialchars($u['phone_digits']) ?>">
                    <td style="white-space:nowrap"><?= htmlspecialchars($fmt_phone($u['phone_digits'])) ?></td>
                    <td style="max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars(mb_substr($u['last_body'], 0, 100)) ?></td>
                    <td style="white-space:nowrap;color:#64748b"><?= htmlspecialchars($u['last_at']) ?></td>
                    <td><?= (int)$u['cnt'] ?></td>
                    <td style="white-space:nowrap">
                        <select class="assign-event" style="padding:.3rem .4rem;border:1.5px solid #e2e8f0;border-radius:6px;max-width:220px">
                            <option value="">Choose event…</option>
                            <?php foreach ($assign_events as $ev): ?>
                            <option value="<?= (int)$ev['id'] ?>"><?= htmlspecialchars($ev['start_date'] . ' — ' . $ev['title']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" class="btn btn-outline assign-btn" style="font-size:.78rem;padding:.3rem .6rem">Assign</button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <script nonce="<?= csp_nonce() ?>">
    document.querySelectorAll('.assign-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var row = btn.closest('tr');
            var eventId = row.querySelector('.assign-event').value;
            if (!eventId) return;
            btn.disabled = true;
            var fd = new FormData();
            fd.append('csrf_token', <?= json_encode($token) ?>);
            fd.append('action', 'assign');
            fd.append('phone', row.dataset.phone);
            fd.append('event', eventId);
            fetch('/sms_conversations_dl.php', { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (d.ok) location.reload();
                    else { btn.disabled = false; pkAlert(d.error || 'Assign failed.'); }
                })
                .catch(function () { btn.disabled = false; pkAlert('Network error.'); });
        });
    });
    </script>
    <?php endif; ?>
<?php endif; ?>

</div>
<?php include __DIR__ . '/_footer.php'; ?>
</body>
</html>
