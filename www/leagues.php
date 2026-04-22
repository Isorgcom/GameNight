<?php
require_once __DIR__ . '/auth.php';

$current   = require_login();
$db        = get_db();
$site_name = get_setting('site_name', 'Game Night');
$uid       = (int)$current['id'];
$tab       = $_GET['tab'] ?? 'my';
if (!in_array($tab, ['my', 'browse', 'requests'], true)) $tab = 'my';

// My Leagues: leagues I'm a member of
$myStmt = $db->prepare(
    "SELECT l.*, lm.role,
            (SELECT COUNT(*) FROM league_members WHERE league_id = l.id) AS member_count
     FROM league_members lm
     JOIN leagues l ON l.id = lm.league_id
     WHERE lm.user_id = ?
     ORDER BY LOWER(l.name)"
);
$myStmt->execute([$uid]);
$myLeagues = $myStmt->fetchAll();

// Browse: non-hidden leagues I'm NOT in
$brStmt = $db->prepare(
    "SELECT l.*, (SELECT COUNT(*) FROM league_members WHERE league_id = l.id) AS member_count,
            (SELECT COUNT(*) FROM league_join_requests WHERE league_id = l.id AND user_id = ? AND status = 'pending') AS has_request
     FROM leagues l
     WHERE l.is_hidden = 0
       AND l.id NOT IN (SELECT league_id FROM league_members WHERE user_id = ?)
     ORDER BY LOWER(l.name)"
);
$brStmt->execute([$uid, $uid]);
$browseLeagues = $brStmt->fetchAll();

// My Requests: pending join requests I've filed
$rqStmt = $db->prepare(
    "SELECT r.*, l.name AS league_name, l.description AS league_description
     FROM league_join_requests r
     JOIN leagues l ON l.id = r.league_id
     WHERE r.user_id = ? AND r.status = 'pending'
     ORDER BY r.requested_at DESC"
);
$rqStmt->execute([$uid]);
$myRequests = $rqStmt->fetchAll();

$csrf = csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Leagues — <?= htmlspecialchars($site_name) ?></title>
    <link rel="stylesheet" href="/style.css">
    <style>
        .lg-wrap { max-width: 960px; margin: 1.5rem auto; padding: 0 1rem; }
        .lg-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1rem; gap: 1rem; flex-wrap: wrap; }
        .lg-header h1 { font-size: 1.5rem; font-weight: 700; margin: 0; }
        .lg-tabs { display: flex; gap: .25rem; border-bottom: 1.5px solid #e2e8f0; margin-bottom: 1rem; overflow-x: auto; }
        .lg-tab { padding: .6rem 1rem; font-weight: 600; color: #64748b; border-bottom: 2.5px solid transparent; text-decoration: none; white-space: nowrap; }
        .lg-tab.active { color: #2563eb; border-bottom-color: #2563eb; }
        .lg-card { background: #fff; border: 1.5px solid #e2e8f0; border-radius: 10px; padding: 1rem 1.2rem; margin-bottom: .75rem; display: flex; justify-content: space-between; align-items: center; gap: 1rem; flex-wrap: wrap; }
        .lg-card .lg-title { font-size: 1.05rem; font-weight: 700; color: #1e293b; margin: 0 0 .25rem; }
        .lg-card .lg-desc { font-size: .85rem; color: #64748b; margin: 0; }
        .lg-card .lg-meta { font-size: .75rem; color: #94a3b8; margin-top: .4rem; }
        .lg-role { display: inline-block; font-size: .7rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; padding: .15rem .5rem; border-radius: 999px; margin-left: .4rem; vertical-align: middle; }
        .lg-role-owner   { background: #fef3c7; color: #92400e; }
        .lg-role-manager { background: #dbeafe; color: #1e40af; }
        .lg-role-member  { background: #e2e8f0; color: #475569; }
        .lg-btn { background: #2563eb; color: #fff; border: none; border-radius: 6px; padding: .45rem .9rem; font-size: .85rem; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-block; }
        .lg-btn:hover { background: #1d4ed8; }
        .lg-btn-ghost { background: transparent; color: #475569; border: 1.5px solid #cbd5e1; }
        .lg-btn-ghost:hover { background: #f1f5f9; color: #1e293b; }
        .lg-empty { text-align: center; padding: 2.5rem 1rem; color: #94a3b8; }
        .lg-create-bar { margin-bottom: 1rem; }
        .lg-modal-bg { position: fixed; inset: 0; background: rgba(15,23,42,.55); z-index: 100; display: none; align-items: center; justify-content: center; padding: 1rem; }
        .lg-modal-bg.open { display: flex; }
        .lg-modal { background: #fff; border-radius: 10px; padding: 1.25rem; max-width: 420px; width: 100%; }
        .lg-modal h3 { margin: 0 0 .75rem; font-size: 1.1rem; }
        .lg-modal textarea { width: 100%; min-height: 80px; border: 1.5px solid #cbd5e1; border-radius: 6px; padding: .5rem; font: inherit; resize: vertical; }
        .lg-modal .lg-row { display: flex; gap: .5rem; justify-content: flex-end; margin-top: .75rem; }
    </style>
</head>
<body>

<?php $nav_active = 'leagues'; $nav_user = $current; require __DIR__ . '/_nav.php'; ?>

<div class="lg-wrap">
    <div class="lg-header">
        <h1>Leagues</h1>
        <a class="lg-btn" href="/league_edit.php">+ Create League</a>
    </div>

    <div class="lg-tabs">
        <a class="lg-tab<?= $tab === 'my'       ? ' active' : '' ?>" href="/leagues.php?tab=my">My Leagues (<?= count($myLeagues) ?>)</a>
        <a class="lg-tab<?= $tab === 'browse'   ? ' active' : '' ?>" href="/leagues.php?tab=browse">Browse (<?= count($browseLeagues) ?>)</a>
        <a class="lg-tab<?= $tab === 'requests' ? ' active' : '' ?>" href="/leagues.php?tab=requests">My Requests (<?= count($myRequests) ?>)</a>
    </div>

    <?php if ($tab === 'my'): ?>
        <?php if (empty($myLeagues)): ?>
            <div class="lg-empty">You're not in any leagues yet. <a href="/leagues.php?tab=browse">Browse leagues</a> or <a href="/league_edit.php">create one</a>.</div>
        <?php else: foreach ($myLeagues as $l): ?>
            <div class="lg-card">
                <div style="flex:1;min-width:200px">
                    <div class="lg-title">
                        <a href="/league.php?id=<?= (int)$l['id'] ?>" style="color:inherit;text-decoration:none"><?= htmlspecialchars($l['name']) ?></a>
                        <span class="lg-role lg-role-<?= htmlspecialchars($l['role']) ?>"><?= htmlspecialchars($l['role']) ?></span>
                    </div>
                    <?php if ($l['description']): ?><p class="lg-desc"><?= nl2br(htmlspecialchars($l['description'])) ?></p><?php endif; ?>
                    <div class="lg-meta"><?= (int)$l['member_count'] ?> member<?= $l['member_count'] == 1 ? '' : 's' ?></div>
                </div>
                <a class="lg-btn lg-btn-ghost" href="/league.php?id=<?= (int)$l['id'] ?>">View</a>
            </div>
        <?php endforeach; endif; ?>

    <?php elseif ($tab === 'browse'): ?>
        <?php if (empty($browseLeagues)): ?>
            <div class="lg-empty">No leagues to browse. Why not <a href="/league_edit.php">start your own</a>?</div>
        <?php else: ?>
        <div style="margin-bottom:.75rem">
            <input type="search" id="lgSearch" placeholder="Search leagues by name or description&hellip;" autocomplete="off"
                   oninput="filterBrowse(this.value)"
                   style="width:100%;padding:.55rem .75rem;border:1.5px solid #cbd5e1;border-radius:6px;font:inherit">
        </div>
        <div id="lgNoResults" class="lg-empty" style="display:none">No leagues match your search.</div>
        <div id="lgBrowseList">
        <?php foreach ($browseLeagues as $l): ?>
            <div class="lg-card lg-browse-item"
                 data-name="<?= htmlspecialchars(strtolower($l['name'])) ?>"
                 data-desc="<?= htmlspecialchars(strtolower($l['description'] ?? '')) ?>">
                <div style="flex:1;min-width:200px">
                    <div class="lg-title"><a href="/league.php?id=<?= (int)$l['id'] ?>" style="color:inherit;text-decoration:none"><?= htmlspecialchars($l['name']) ?></a></div>
                    <?php if ($l['description']): ?><p class="lg-desc"><?= nl2br(htmlspecialchars($l['description'])) ?></p><?php endif; ?>
                    <div class="lg-meta"><?= (int)$l['member_count'] ?> member<?= $l['member_count'] == 1 ? '' : 's' ?> &middot; <?= $l['approval_mode'] === 'auto' ? 'Open join' : 'Requires approval' ?></div>
                </div>
                <?php if ((int)$l['has_request'] > 0): ?>
                    <span class="lg-btn lg-btn-ghost" style="cursor:default">Request pending</span>
                <?php elseif ($l['approval_mode'] === 'auto'): ?>
                    <button class="lg-btn" onclick="joinLeague(<?= (int)$l['id'] ?>)">Join</button>
                <?php else: ?>
                    <button class="lg-btn" onclick="openRequestModal(<?= (int)$l['id'] ?>, <?= htmlspecialchars(json_encode($l['name']), ENT_QUOTES) ?>)">Request to Join</button>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        </div>
        <?php endif; ?>

    <?php elseif ($tab === 'requests'): ?>
        <?php if (empty($myRequests)): ?>
            <div class="lg-empty">No pending requests.</div>
        <?php else: foreach ($myRequests as $r): ?>
            <div class="lg-card">
                <div style="flex:1;min-width:200px">
                    <div class="lg-title"><a href="/league.php?id=<?= (int)$r['league_id'] ?>" style="color:inherit;text-decoration:none"><?= htmlspecialchars($r['league_name']) ?></a></div>
                    <?php if ($r['message']): ?><p class="lg-desc"><em>Your message:</em> <?= htmlspecialchars($r['message']) ?></p><?php endif; ?>
                    <div class="lg-meta">Requested <?= htmlspecialchars($r['requested_at']) ?></div>
                </div>
                <button class="lg-btn lg-btn-ghost" onclick="cancelRequest(<?= (int)$r['league_id'] ?>)">Cancel</button>
            </div>
        <?php endforeach; endif; ?>

    <?php endif; ?>
</div>

<!-- Join-request modal -->
<div class="lg-modal-bg" id="rqModal">
    <div class="lg-modal">
        <h3>Request to join <span id="rqName"></span></h3>
        <p style="font-size:.85rem;color:#64748b;margin:.25rem 0 .5rem">Optional message to the league owner:</p>
        <textarea id="rqMsg" placeholder="Hi, I'd like to join!"></textarea>
        <div class="lg-row">
            <button class="lg-btn lg-btn-ghost" onclick="closeRequestModal()">Cancel</button>
            <button class="lg-btn" onclick="submitRequest()">Send Request</button>
        </div>
    </div>
</div>

<script>
var CSRF = <?= json_encode($csrf) ?>;
var _rqLeagueId = null;

function post(data) {
    var fd = new FormData();
    fd.append('csrf_token', CSRF);
    for (var k in data) fd.append(k, data[k]);
    return fetch('/leagues_dl.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function(r) { return r.json(); });
}
function joinLeague(id) {
    post({ action: 'request_join', league_id: id, message: '' }).then(function(j) {
        if (j.ok) location.reload(); else alert(j.error || 'Failed');
    });
}
function openRequestModal(id, name) {
    _rqLeagueId = id;
    document.getElementById('rqName').textContent = name;
    document.getElementById('rqMsg').value = '';
    document.getElementById('rqModal').classList.add('open');
}
function closeRequestModal() {
    document.getElementById('rqModal').classList.remove('open');
    _rqLeagueId = null;
}
function submitRequest() {
    if (!_rqLeagueId) return;
    var msg = document.getElementById('rqMsg').value;
    post({ action: 'request_join', league_id: _rqLeagueId, message: msg }).then(function(j) {
        if (j.ok) location.reload(); else alert(j.error || 'Failed');
    });
}
function cancelRequest(leagueId) {
    if (!confirm('Cancel this request?')) return;
    post({ action: 'cancel_request', league_id: leagueId }).then(function(j) {
        if (j.ok) location.reload(); else alert(j.error || 'Failed');
    });
}
function filterBrowse(q) {
    q = (q || '').trim().toLowerCase();
    var items = document.querySelectorAll('.lg-browse-item');
    var shown = 0;
    items.forEach(function(el) {
        var match = q === '' || el.dataset.name.indexOf(q) !== -1 || el.dataset.desc.indexOf(q) !== -1;
        el.style.display = match ? '' : 'none';
        if (match) shown++;
    });
    document.getElementById('lgNoResults').style.display = (shown === 0 && items.length > 0) ? 'block' : 'none';
}
</script>

<?php require __DIR__ . '/_footer.php'; ?>
</body>
</html>
