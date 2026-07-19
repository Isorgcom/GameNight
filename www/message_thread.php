<?php
/**
 * Direct-message thread with one other user. Opening the page marks their
 * unsent-to-me messages read (and clears the matching bell rows); a light
 * 30s poll picks up replies while the page is open. Sending goes through
 * messages_dl.php, which enforces scope + rate caps.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_dm.php';

$current = require_login();
$db  = get_db();
$uid = (int)$current['id'];
$site_name = get_setting('site_name', 'Game Night');
$csrf = csrf_token();

$other_id = (int)($_GET['user'] ?? 0);
$s = $db->prepare('SELECT id, username, role FROM users WHERE id = ?');
$s->execute([$other_id]);
$other = $s->fetch();
if ($other && $other_id === $uid) {
    header('Location: /messages.php');
    exit;
}
if (!$other) {
    http_response_code(404);
    exit('Unknown user.');
}

$conv = dm_conversation_for($db, $uid, $other_id);
$canSend = dm_can_send($db, $current, $other);
if (!$conv && !$canSend) {
    http_response_code(403);
    exit('You can only message people you share a league, event, or contact link with.');
}

$messages = [];
if ($conv) {
    $mark = dm_my_watermark($conv, $uid);
    $q = $db->prepare('SELECT id, sender_id, body, created_at FROM dm_messages
                       WHERE conversation_id = ? AND id > ? ORDER BY id');
    $q->execute([(int)$conv['id'], $mark]);
    $messages = $q->fetchAll();

    // Presence heartbeat + mark incoming read + clear this sender's bell rows.
    dm_touch_seen($db, $conv, $uid);
    $db->prepare('UPDATE dm_messages SET read_at = CURRENT_TIMESTAMP
                  WHERE conversation_id = ? AND sender_id <> ? AND read_at IS NULL')
       ->execute([(int)$conv['id'], $uid]);
    $db->prepare("UPDATE user_notifications SET is_read = 1
                  WHERE user_id = ? AND notify_type = 'dm' AND link = ? AND is_read = 0")
       ->execute([$uid, '/message_thread.php?user=' . $other_id]);
}

$viewer_tz = new DateTimeZone(display_timezone($uid));
$utc_tz    = new DateTimeZone('UTC');
$lastId = $messages ? (int)end($messages)['id'] : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($other['username']) ?> &ndash; Messages &ndash; <?= htmlspecialchars($site_name) ?></title>
    <link rel="stylesheet" href="/style.css?v=<?= htmlspecialchars(APP_VERSION) ?>">
    <style>
        .dt-wrap { max-width: 680px; margin: 1.25rem auto 2rem; padding: 0 1rem; }
        .dt-head { display:flex; align-items:center; gap:.6rem; margin-bottom:1rem; }
        .dt-head h1 { font-size:1.3rem; font-weight:800; color:#1e293b; margin:0; flex:1; }
        .dt-msgs { display:flex; flex-direction:column; gap:.45rem; margin-bottom:1rem; }
        .dt-bubble { max-width:78%; padding:.5rem .8rem; border-radius:12px; font-size:.9rem; white-space:pre-wrap; word-wrap:break-word; }
        .dt-bubble.theirs { background:#f1f5f9; color:#1e293b; align-self:flex-start; border-bottom-left-radius:4px; }
        .dt-bubble.mine { background:#2563eb; color:#fff; align-self:flex-end; border-bottom-right-radius:4px; }
        .dt-bubble a { color:inherit; text-decoration:underline; word-break:break-all; }
        .dt-when { font-size:.65rem; opacity:.65; margin-top:.2rem; }
        .dt-form { display:flex; gap:.5rem; align-items:flex-end; }
        .dt-form textarea { flex:1; min-height:64px; padding:.5rem .7rem; border:1.5px solid #e2e8f0; border-radius:10px; font-size:.9rem; font-family:inherit; resize:vertical; }
    </style>
</head>
<body>
<?php $nav_active = 'messages'; require __DIR__ . '/_nav.php'; ?>
<div class="dt-wrap">
    <div class="dt-head">
        <a href="/messages.php" class="btn btn-outline" style="font-size:.8rem;padding:.3rem .6rem">&laquo; Messages</a>
        <h1><?= htmlspecialchars($other['username']) ?></h1>
        <?php if ($conv && $messages): ?>
        <button class="btn btn-outline" type="button" style="font-size:.8rem;padding:.3rem .6rem" onclick="clearConvo()">Delete conversation</button>
        <?php endif; ?>
    </div>

    <div class="dt-msgs" id="dtMsgs">
        <?php if (!$messages): ?>
        <p id="dtEmpty" style="color:#64748b">No messages yet — say hello below.</p>
        <?php else: foreach ($messages as $m):
            $mine = (int)$m['sender_id'] === $uid;
            try { $when = (new DateTime((string)$m['created_at'], $utc_tz))->setTimezone($viewer_tz)->format('M j g:ia'); }
            catch (Throwable $e) { $when = ''; }
        ?>
        <div class="dt-bubble <?= $mine ? 'mine' : 'theirs' ?>"><?= dm_linkify($m['body']) ?><div class="dt-when"><?= htmlspecialchars($when) ?></div></div>
        <?php endforeach; endif; ?>
    </div>

    <div class="dt-form">
        <textarea id="dtBody" maxlength="4000" placeholder="Write a message&hellip;"></textarea>
        <button class="btn" type="button" id="dtSend" onclick="sendMsg(this)">Send</button>
    </div>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
<script>
var CSRF = <?= json_encode($csrf) ?>;
var OTHER = <?= (int)$other_id ?>;
var LAST_ID = <?= $lastId ?>;

function post(data) {
    var fd = new FormData();
    fd.append('csrf_token', CSRF);
    for (var k in data) fd.append(k, data[k]);
    return fetch('/messages_dl.php', { method: 'POST', body: fd, credentials: 'same-origin' }).then(function(r) { return r.json(); });
}

// Escape-then-linkify; keep in sync with dm_linkify() in _dm.php.
function linkify(text) {
    var esc = String(text).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                          .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    return esc.replace(/(?:https?:\/\/|www\.)[^\s<]*[^\s<.,!?;:)\]'"]/gi, function (url) {
        var href = /^http/i.test(url) ? url : 'https://' + url;
        return '<a href="' + href + '" target="_blank" rel="noopener noreferrer">' + url + '</a>';
    });
}

function appendBubble(m) {
    var empty = document.getElementById('dtEmpty');
    if (empty) empty.remove();
    var d = document.createElement('div');
    d.className = 'dt-bubble ' + (m.mine ? 'mine' : 'theirs');
    d.innerHTML = linkify(m.body);
    var w = document.createElement('div');
    w.className = 'dt-when';
    w.textContent = m.when || '';
    d.appendChild(w);
    document.getElementById('dtMsgs').appendChild(d);
    d.scrollIntoView({ block: 'nearest' });
}

function sendMsg(btn) {
    var ta = document.getElementById('dtBody');
    var body = ta.value.trim();
    if (!body) return;
    var p = post({ action: 'send', user_id: OTHER, body: body }).then(function(j) {
        if (!j.ok) { pkAlert(j.error || 'Send failed.'); return; }
        ta.value = '';
        appendBubble({ mine: true, body: body, when: 'now' });
        if (j.id) LAST_ID = Math.max(LAST_ID, j.id);
    });
    if (typeof pkBusy === 'function') pkBusy(btn, p);
}

function clearConvo() {
    pkConfirm('Delete this conversation from your view? The other person keeps their copy.').then(function(yes) {
        if (!yes) return;
        post({ action: 'clear', user_id: OTHER }).then(function(j) {
            if (j.ok) location.href = '/messages.php';
            else pkAlert(j.error || 'Failed.');
        });
    });
}

document.getElementById('dtBody').addEventListener('keydown', function(e) {
    if (e.key === 'Enter' && !e.shiftKey && window.innerWidth > 700) {
        e.preventDefault();
        sendMsg(document.getElementById('dtSend'));
    }
});

// Live updates: quick poll while the tab is visible, idle when hidden.
setInterval(function() {
    if (document.hidden) return;
    post({ action: 'since', user_id: OTHER, after_id: LAST_ID }).then(function(j) {
        if (!j.ok || !j.messages) return;
        var incoming = false;
        j.messages.forEach(function(m) {
            if (m.id <= LAST_ID) return;
            LAST_ID = m.id;
            if (!m.mine) { appendBubble(m); incoming = true; }
        });
        if (incoming && typeof window.gnChime === 'function') window.gnChime();
    }).catch(function() {});
}, 4000);

window.scrollTo(0, document.body.scrollHeight);
</script>
</body>
</html>
