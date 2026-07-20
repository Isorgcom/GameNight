<?php
/**
 * Message thread — 1:1 (?user=N, created lazily on first send) or group
 * (?conv=N). Opening marks messages read + clears matching bell rows; a 4s
 * poll appends incoming bubbles (with chime) and doubles as the presence
 * heartbeat that suppresses alerts. Sending goes through messages_dl.php.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_dm.php';

$current = require_login();
$db  = get_db();
$uid = (int)$current['id'];
$site_name = get_setting('site_name', 'Game Night');
$csrf = csrf_token();

$conv  = null;
$other = null;

$event_id = (int)($_GET['event'] ?? 0);
$conv_id  = (int)($_GET['conv'] ?? 0);
if ($event_id > 0) {
    // Event chat entry: visible event + roster membership (synced on the spot).
    $vis = event_visibility_sql('e', $uid);
    $eq = $db->prepare("SELECT e.* FROM events e WHERE e.id = ? AND {$vis['sql']}");
    $eq->execute(array_merge([$event_id], $vis['params']));
    $event = $eq->fetch();
    if (!$event) {
        http_response_code(404);
        exit('Event not found.');
    }
    $conv = dm_event_chat_get_or_create($db, $event);
    dm_sync_event_chat($db, $conv);
    if (!dm_participant($db, (int)$conv['id'], $uid)) {
        http_response_code(403);
        exit('The event chat is for the host and guests who RSVPed yes. RSVP on the event page to join.');
    }
    $conv = dm_conversation($db, (int)$conv['id']); // fresh title post-sync
} elseif ($conv_id > 0) {
    $conv = dm_conversation($db, $conv_id);
    if ($conv && !empty($conv['event_id'])) dm_sync_event_chat($db, $conv);
    if (!$conv || !dm_participant($db, $conv_id, $uid)) {
        http_response_code(404);
        exit('Unknown conversation.');
    }
} else {
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
    if (!$conv && !dm_can_send_pair($db, $current, $other)) {
        http_response_code(403);
        exit('You can only message people you share a league, event, or contact link with.');
    }
}

$isGroup = $conv && !empty($conv['is_group']);
$isEventChat = $conv && !empty($conv['event_id']) && dm_event_chat_event_exists($db, (int)$conv['event_id']);
$title   = $conv ? dm_conversation_title($db, $conv, $uid) : $other['username'];
$members = $conv ? dm_participants($db, (int)$conv['id']) : [];

$messages = [];
if ($conv) {
    $me = dm_participant($db, (int)$conv['id'], $uid);
    $mark = max((int)($me['cleared_before_id'] ?? 0), 0);
    $q = $db->prepare('SELECT m.id, m.sender_id, m.body, m.created_at, u.username
                       FROM dm_messages m JOIN users u ON u.id = m.sender_id
                       WHERE m.conversation_id = ? AND m.id > ? ORDER BY m.id');
    $q->execute([(int)$conv['id'], $mark]);
    $messages = $q->fetchAll();

    dm_touch_seen($db, (int)$conv['id'], $uid);
    dm_mark_read($db, (int)$conv['id'], $uid);
    // Clear this conversation's bell rows so badges drop.
    $links = [dm_thread_link($conv, 0)];
    if (!$isGroup) {
        foreach ($members as $p) {
            if ((int)$p['user_id'] !== $uid) $links[] = '/message_thread.php?user=' . (int)$p['user_id'];
        }
    } else {
        $links = ['/message_thread.php?conv=' . (int)$conv['id']];
    }
    $in = implode(',', array_fill(0, count($links), '?'));
    $db->prepare("UPDATE user_notifications SET is_read = 1
                  WHERE user_id = ? AND notify_type = 'dm' AND is_read = 0 AND link IN ($in)")
       ->execute(array_merge([$uid], $links));
}

// People I could add (eligible minus current members). Works for groups,
// event chats (extras are marked manual_add so the roster sync keeps them),
// and existing 1:1 threads — adding to a 1:1 converts it into a group.
$addable = [];
$myPart  = $conv ? dm_participant($db, (int)$conv['id'], $uid) : null;
if ($conv && ($isEventChat || count($members) < MAX_DM_GROUP_MEMBERS)) {
    $addable = dm_eligible_recipients($db, $current);
    foreach ($members as $p) unset($addable[(int)$p['user_id']]);
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
    <title><?= htmlspecialchars($title) ?> &ndash; Messages &ndash; <?= htmlspecialchars($site_name) ?></title>
    <link rel="stylesheet" href="/style.css?v=<?= htmlspecialchars(APP_VERSION) ?>">
    <style>
        .dt-wrap { max-width: 680px; margin: 1.25rem auto 2rem; padding: 0 1rem; }
        .dt-head { display:flex; align-items:center; gap:.6rem; margin-bottom:.35rem; flex-wrap:wrap; }
        .dt-head h1 { font-size:1.3rem; font-weight:800; color:#1e293b; margin:0; flex:1; min-width:0; }
        .dt-members { font-size:.78rem; color:#94a3b8; margin:0 0 .9rem; }
        .dt-msgs { display:flex; flex-direction:column; gap:.45rem; margin-bottom:1rem; }
        .dt-bubble { max-width:78%; padding:.5rem .8rem; border-radius:12px; font-size:.9rem; white-space:pre-wrap; word-wrap:break-word; }
        .dt-bubble.theirs { background:#f1f5f9; color:#1e293b; align-self:flex-start; border-bottom-left-radius:4px; }
        .dt-bubble.mine { background:#2563eb; color:#fff; align-self:flex-end; border-bottom-right-radius:4px; }
        .dt-bubble a { color:inherit; text-decoration:underline; word-break:break-all; }
        .dt-sender { font-size:.68rem; font-weight:700; color:#7c3aed; margin-bottom:.15rem; }
        .dt-when { font-size:.65rem; opacity:.65; margin-top:.2rem; }
        .dt-form { display:flex; gap:.5rem; align-items:flex-end; }
        .dt-form textarea { flex:1; min-height:64px; padding:.5rem .7rem; border:1.5px solid #e2e8f0; border-radius:10px; font-size:.9rem; font-family:inherit; resize:vertical; }
        .dt-add-card { display:none; background:#faf5ff; border:1.5px solid #ddd6fe; border-radius:10px; padding:.6rem .8rem; margin-bottom:.9rem; }
        .dt-add-card.open { display:flex; gap:.5rem; flex-wrap:wrap; align-items:center; }
        .dt-add-card select { flex:1; min-width:180px; padding:.4rem .55rem; border:1.5px solid #ddd6fe; border-radius:8px; font-size:.88rem; }
    </style>
</head>
<body>
<?php $nav_active = 'messages'; require __DIR__ . '/_nav.php'; ?>
<div class="dt-wrap">
    <div class="dt-head">
        <a href="/messages.php" class="btn btn-outline" style="font-size:.8rem;padding:.3rem .6rem">&laquo; Messages</a>
        <h1><?= htmlspecialchars($title) ?></h1>
        <?php if ($addable): ?>
        <button class="btn btn-outline" type="button" style="font-size:.8rem;padding:.3rem .6rem" onclick="document.getElementById('dtAdd').classList.toggle('open')">Add people</button>
        <?php endif; ?>
        <?php if ($isEventChat): ?>
            <a class="btn btn-outline" style="font-size:.8rem;padding:.3rem .6rem;text-decoration:none" href="/event.php?id=<?= (int)$conv['event_id'] ?>">View event</a>
            <?php if (!empty($myPart['manual_add'])): ?>
            <button class="btn btn-outline" type="button" style="font-size:.8rem;padding:.3rem .6rem;color:#dc2626;border-color:#fecaca" onclick="leaveGroup()">Leave chat</button>
            <?php endif; ?>
        <?php elseif ($isGroup): ?>
            <button class="btn btn-outline" type="button" style="font-size:.8rem;padding:.3rem .6rem;color:#dc2626;border-color:#fecaca" onclick="leaveGroup()">Leave group</button>
        <?php elseif ($conv && $messages): ?>
            <button class="btn btn-outline" type="button" style="font-size:.8rem;padding:.3rem .6rem" onclick="clearConvo()">Delete conversation</button>
        <?php endif; ?>
    </div>
    <?php if ($isGroup): ?>
    <p class="dt-members"><?= count($members) ?> members: <?= htmlspecialchars(implode(', ', array_map(fn($p) => $p['username'], $members))) ?><?= $isEventChat ? ' &middot; membership follows the event roster (host + everyone going)' : '' ?></p>
    <?php endif; ?>
    <?php if ($addable): ?>
    <div class="dt-add-card" id="dtAdd">
        <select id="dtAddUser">
            <option value="">Add to <?= $isGroup ? 'group' : 'conversation' ?>&hellip;</option>
            <?php foreach ($addable as $aid => $aname): ?>
            <option value="<?= (int)$aid ?>"><?= htmlspecialchars($aname) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="btn" type="button" onclick="addMember(this)">Add</button>
        <?php if ($isEventChat): ?>
        <span style="flex-basis:100%;font-size:.72rem;color:#7c3aed">Adds them to this chat only — it does not invite them to the event.</span>
        <?php elseif (!$isGroup): ?>
        <span style="flex-basis:100%;font-size:.72rem;color:#7c3aed">Adding someone turns this into a group chat — they'll only see messages from now on.</span>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="dt-msgs" id="dtMsgs">
        <?php if (!$messages): ?>
        <p id="dtEmpty" style="color:#64748b">No messages yet — say hello below.</p>
        <?php else: foreach ($messages as $m):
            $mine = (int)$m['sender_id'] === $uid;
            try { $when = (new DateTime((string)$m['created_at'], $utc_tz))->setTimezone($viewer_tz)->format('M j g:ia'); }
            catch (Throwable $e) { $when = ''; }
        ?>
        <div class="dt-bubble <?= $mine ? 'mine' : 'theirs' ?>"><?php if ($isGroup && !$mine): ?><div class="dt-sender"><?= htmlspecialchars($m['username']) ?></div><?php endif; ?><?= dm_linkify($m['body']) ?><div class="dt-when"><?= htmlspecialchars($when) ?></div></div>
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
var CONV = <?= $conv ? (int)$conv['id'] : 0 ?>;
var OTHER = <?= $other ? (int)$other['id'] : 0 ?>;
var IS_GROUP = <?= $isGroup ? 'true' : 'false' ?>;
var LAST_ID = <?= $lastId ?>;

function post(data) {
    var fd = new FormData();
    fd.append('csrf_token', CSRF);
    if (CONV) fd.append('conv_id', CONV);
    else fd.append('user_id', OTHER);
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
    var html = '';
    if (IS_GROUP && !m.mine && m.sender) {
        var senderEsc = String(m.sender).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        html += '<div class="dt-sender">' + senderEsc + '</div>';
    }
    html += linkify(m.body);
    d.innerHTML = html;
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
    var p = post({ action: 'send', body: body }).then(function(j) {
        if (!j.ok) { pkAlert(j.error || 'Send failed.'); return; }
        ta.value = '';
        appendBubble({ mine: true, body: body, when: 'now' });
        if (j.id) LAST_ID = Math.max(LAST_ID, j.id);
        if (j.conv_id && !CONV) CONV = j.conv_id;
    });
    if (typeof pkBusy === 'function') pkBusy(btn, p);
}

function clearConvo() {
    pkConfirm('Delete this conversation from your view? The other person keeps their copy.').then(function(yes) {
        if (!yes) return;
        post({ action: 'clear' }).then(function(j) {
            if (j.ok) location.href = '/messages.php';
            else pkAlert(j.error || 'Failed.');
        });
    });
}

function leaveGroup() {
    pkConfirm('Leave this group? You will stop receiving its messages.').then(function(yes) {
        if (!yes) return;
        post({ action: 'leave' }).then(function(j) {
            if (j.ok) location.href = '/messages.php';
            else pkAlert(j.error || 'Failed.');
        });
    });
}

function addMember(btn) {
    var v = document.getElementById('dtAddUser').value;
    if (!v) { pkAlert('Pick a person first.'); return; }
    var p = post({ action: 'add_members', user_ids: v }).then(function(j) {
        if (!j.ok) { pkAlert(j.error || 'Failed.'); return; }
        // Reload by conversation id: a 1:1 that just became a group is no
        // longer reachable via its old ?user= address.
        location.href = '/message_thread.php?conv=' + (j.conv_id || CONV);
    });
    if (typeof pkBusy === 'function') pkBusy(btn, p);
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
    if (!CONV && !OTHER) return;
    post({ action: 'since', after_id: LAST_ID }).then(function(j) {
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
