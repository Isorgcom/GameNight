<?php
require_once __DIR__ . '/auth.php';

$current = require_login();
$db = get_db();
$isAdmin = $current['role'] === 'admin';

$event_id = (int)($_GET['event_id'] ?? 0);
if (!$event_id) { http_response_code(400); exit('Missing event_id'); }

// Verify event exists and user has access
$evStmt = $db->prepare('SELECT * FROM events WHERE id = ?');
$evStmt->execute([$event_id]);
$event = $evStmt->fetch();
if (!$event) { http_response_code(404); exit('Event not found'); }
if (!$isAdmin && (int)$event['created_by'] !== (int)$current['id']) {
    // Check if user is a manager of this event
    $mgrStmt = $db->prepare("SELECT 1 FROM event_invites WHERE event_id=? AND LOWER(username)=LOWER(?) AND event_role='manager' LIMIT 1");
    $mgrStmt->execute([$event_id, $current['username']]);
    if (!$mgrStmt->fetch()) {
        http_response_code(403); exit('Access denied');
    }
}

$site_name = get_setting('site_name', 'Game Night');
$csrf = csrf_token();

// Check if session already exists
$sessStmt = $db->prepare('SELECT * FROM poker_sessions WHERE event_id = ?');
$sessStmt->execute([$event_id]);
$session = $sessStmt->fetch();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Game — <?= htmlspecialchars($event['title']) ?> — <?= htmlspecialchars($site_name) ?></title>
    <link rel="stylesheet" href="/style.css">
    <style>
    .pk-wrap{padding:0 1rem 2rem;max-width:100%}
    .pk-header{background:var(--dark,#0f172a);color:#fff;padding:.75rem 1.5rem;display:flex;align-items:center;gap:1.5rem;flex-wrap:wrap;position:sticky;top:0;z-index:50}
    .pk-header h1{font-size:1.15rem;margin:0;font-weight:600}
    .pk-header h1 a{color:#94a3b8;text-decoration:none;font-weight:400;font-size:.85rem}
    .pk-header h1 a:hover{color:#fff}
    .pk-badge{display:inline-block;padding:.15rem .6rem;border-radius:99px;font-size:.75rem;font-weight:600;text-transform:uppercase;letter-spacing:.04em}
    .pk-badge-setup{background:#fbbf24;color:#78350f}
    .pk-badge-active{background:#22c55e;color:#052e16}
    .pk-badge-finished{background:#64748b;color:#f1f5f9}
    .pk-badge-tournament{background:#7c3aed;color:#fff}
    .pk-badge-cash{background:#0891b2;color:#fff}
    .pk-pool{font-size:1.5rem;font-weight:700;color:#22c55e;margin-left:auto;white-space:nowrap}
    .pk-pool small{font-size:.75rem;color:#94a3b8;font-weight:400;display:block}
    .pk-actions{display:flex;gap:.5rem;flex-wrap:wrap}
    .pk-actions button,.pk-actions a{padding:.4rem .8rem;border-radius:6px;font-size:.8rem;font-weight:600;cursor:pointer;border:1.5px solid transparent;text-decoration:none;display:inline-flex;align-items:center;gap:.3rem}
    .pk-btn-settings{background:transparent;color:#94a3b8;border-color:#475569}
    .pk-btn-settings:hover{background:#1e293b;color:#fff}
    .pk-btn-start{background:#22c55e;color:#052e16}
    .pk-btn-start:hover{background:#16a34a}
    .pk-btn-end{background:#ef4444;color:#fff}
    .pk-btn-end:hover{background:#dc2626}
    .pk-btn-back{background:transparent;color:#94a3b8;border-color:#475569}
    .pk-btn-back:hover{background:#1e293b;color:#fff}

    .pk-stats{display:flex;gap:.75rem;padding:.75rem 1.5rem;flex-wrap:wrap}
    .pk-stat{background:var(--surface,#fff);border:1.5px solid var(--border,#e2e8f0);border-radius:8px;padding:.5rem 1rem;min-width:120px;text-align:center}
    .pk-stat-label{font-size:.7rem;text-transform:uppercase;letter-spacing:.05em;color:#64748b;margin-bottom:.15rem}
    .pk-stat-value{font-size:1.3rem;font-weight:700;color:var(--accent,#2563eb)}

    .pk-grid{display:grid;grid-template-columns:1fr 300px;gap:1rem;padding:.75rem 1.5rem}
    @media(max-width:1024px){.pk-grid{grid-template-columns:1fr}}

    .pk-toolbar{display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;margin-bottom:.75rem}
    .pk-toolbar input[type=text]{padding:.4rem .7rem;border:1.5px solid var(--border,#e2e8f0);border-radius:6px;font-size:.85rem;width:180px}
    .pk-toolbar button{padding:.4rem .8rem;border-radius:6px;font-size:.8rem;font-weight:600;cursor:pointer;border:1.5px solid transparent}
    .pk-btn-add{background:var(--accent,#2563eb);color:#fff}
    .pk-btn-add:hover{opacity:.9}
    .pk-btn-refresh{background:transparent;color:var(--accent,#2563eb);border-color:var(--border,#e2e8f0)}
    .pk-btn-refresh:hover{background:#f1f5f9}
    .pk-filter{display:flex;gap:0;border:1.5px solid var(--border,#e2e8f0);border-radius:6px;overflow:hidden;margin-left:auto}
    .pk-filter button{border:none;background:transparent;padding:.35rem .7rem;font-size:.75rem;font-weight:600;cursor:pointer;color:#64748b}
    .pk-filter button.active{background:var(--accent,#2563eb);color:#fff}

    .pk-table-wrap{overflow-x:auto;border:1.5px solid var(--border,#e2e8f0);border-radius:8px;background:var(--surface,#fff)}
    .pk-table{width:100%;border-collapse:collapse;font-size:.85rem}
    .pk-table th{background:#f8fafc;padding:.5rem .6rem;text-align:left;font-size:.7rem;text-transform:uppercase;letter-spacing:.05em;color:#64748b;border-bottom:1.5px solid var(--border,#e2e8f0);white-space:nowrap;position:sticky;top:0;z-index:5}
    .pk-table td{padding:.4rem .6rem;border-bottom:1px solid #f1f5f9;vertical-align:middle}
    .pk-table tr:hover td{background:#f8fafc}
    .pk-table tr.elim td{opacity:.5;text-decoration:line-through}
    .pk-table tr.elim td:last-child{text-decoration:none;opacity:1}
    .pk-table tr.cashed-out td{opacity:.6}
    .pk-table tr.rsvp-no td{opacity:.45;text-decoration:line-through}
    .pk-table tr.rsvp-no td:nth-child(3){text-decoration:none;opacity:1}
    .pk-table tr.rsvp-no td:last-child{text-decoration:none;opacity:1}
    .pk-table .name-cell{font-weight:600;white-space:nowrap}
    .pk-table .walkin-badge{font-size:.6rem;background:#fbbf24;color:#78350f;padding:.1rem .35rem;border-radius:4px;margin-left:.3rem;font-weight:600;vertical-align:middle}

    .pk-check{width:20px;height:20px;cursor:pointer;accent-color:var(--accent,#2563eb)}
    .pk-counter{display:inline-flex;align-items:center;gap:0;border:1.5px solid var(--border,#e2e8f0);border-radius:6px;overflow:hidden}
    .pk-counter button{width:26px;height:26px;border:none;background:#f8fafc;cursor:pointer;font-weight:700;font-size:.9rem;color:#64748b;display:flex;align-items:center;justify-content:center}
    .pk-counter button:hover{background:#e2e8f0}
    .pk-counter span{min-width:24px;text-align:center;font-weight:600;font-size:.85rem;padding:0 2px}
    .pk-tbl-input{width:42px;padding:.2rem .3rem;border:1.5px solid var(--border,#e2e8f0);border-radius:4px;text-align:center;font-size:.85rem}
    .pk-cash-input{width:70px;padding:.2rem .3rem;border:1.5px solid var(--border,#e2e8f0);border-radius:4px;text-align:center;font-size:.85rem}

    .pk-act-btn{background:transparent;border:none;cursor:pointer;font-size:.75rem;padding:.2rem .4rem;border-radius:4px;color:#64748b;white-space:nowrap}
    .pk-act-btn:hover{background:#f1f5f9;color:#0f172a}
    .pk-act-btn.danger{color:#ef4444}
    .pk-act-btn.danger:hover{background:#fef2f2;color:#dc2626}
    .pk-profit-pos{color:#22c55e;font-weight:600}
    .pk-profit-neg{color:#ef4444;font-weight:600}
    .pk-profit-zero{color:#64748b;font-weight:600}

    .pk-sidebar{display:flex;flex-direction:column;gap:.75rem}
    .pk-card{background:var(--surface,#fff);border:1.5px solid var(--border,#e2e8f0);border-radius:8px;padding:1rem}
    .pk-card h3{margin:0 0 .6rem;font-size:.85rem;text-transform:uppercase;letter-spacing:.04em;color:#64748b}
    .pk-pool-row{display:flex;justify-content:space-between;padding:.25rem 0;font-size:.85rem}
    .pk-pool-row.total{font-weight:700;font-size:1.1rem;border-top:2px solid var(--border,#e2e8f0);margin-top:.3rem;padding-top:.5rem;color:#22c55e}
    .pk-payout-row{display:flex;justify-content:space-between;padding:.2rem 0;font-size:.85rem}
    .pk-payout-place{font-weight:600}

    .pk-settings-panel{display:none;background:var(--surface,#fff);border:1.5px solid var(--border,#e2e8f0);border-radius:8px;padding:1.25rem;margin:.75rem 1.5rem}
    .pk-settings-panel.open{display:block}
    .pk-settings-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:.75rem}
    .pk-settings-grid label{font-size:.8rem;font-weight:600;color:#475569;display:block;margin-bottom:.2rem}
    .pk-settings-grid input,.pk-settings-grid select{width:100%;padding:.4rem .6rem;border:1.5px solid var(--border,#e2e8f0);border-radius:6px;font-size:.85rem}
    .pk-payout-editor{margin-top:.75rem}
    .pk-payout-editor .row{display:flex;gap:.5rem;align-items:center;margin-bottom:.4rem}
    .pk-payout-editor input{width:70px;padding:.3rem .5rem;border:1.5px solid var(--border,#e2e8f0);border-radius:4px;font-size:.85rem;text-align:center}
    .pk-payout-editor button{font-size:.75rem;padding:.3rem .6rem;border-radius:4px;cursor:pointer;border:1.5px solid var(--border,#e2e8f0);background:#f8fafc}
    .pk-settings-save{margin-top:.75rem;padding:.5rem 1.5rem;background:var(--accent,#2563eb);color:#fff;border:none;border-radius:6px;font-weight:600;font-size:.85rem;cursor:pointer}
    .pk-settings-save:hover{opacity:.9}

    /* Setup screen */
    .pk-setup{max-width:500px;margin:3rem auto;background:var(--surface,#fff);border:1.5px solid var(--border,#e2e8f0);border-radius:12px;padding:2rem}
    .pk-setup h2{margin:0 0 .3rem;font-size:1.3rem}
    .pk-setup p{color:#64748b;margin:0 0 1.5rem;font-size:.9rem}
    .pk-setup label{font-size:.85rem;font-weight:600;color:#475569;display:block;margin-bottom:.2rem}
    .pk-setup input,.pk-setup select{width:100%;padding:.5rem .7rem;border:1.5px solid var(--border,#e2e8f0);border-radius:6px;font-size:.9rem;margin-bottom:.75rem;box-sizing:border-box}
    .pk-setup button[type=submit]{width:100%;padding:.65rem;background:var(--accent,#2563eb);color:#fff;border:none;border-radius:6px;font-weight:600;font-size:1rem;cursor:pointer}
    .pk-setup button[type=submit]:hover{opacity:.9}
    .pk-type-toggle{display:flex;gap:0;border:2px solid var(--border,#e2e8f0);border-radius:8px;overflow:hidden;margin-bottom:1rem}
    .pk-type-toggle button{flex:1;padding:.6rem;border:none;background:#f8fafc;font-size:.9rem;font-weight:600;cursor:pointer;color:#64748b;transition:all .15s}
    .pk-type-toggle button.active{color:#fff}
    .pk-type-toggle button.active.t-tournament{background:#7c3aed}
    .pk-type-toggle button.active.t-cash{background:#0891b2}

    /* Notes modal */
    .pk-modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:200;align-items:center;justify-content:center}
    .pk-modal-overlay.open{display:flex}
    .pk-modal{background:var(--surface,#fff);border-radius:12px;padding:1.5rem;width:90%;max-width:400px}
    .pk-modal h3{margin:0 0 .75rem}
    .pk-modal textarea{width:100%;height:100px;padding:.5rem;border:1.5px solid var(--border,#e2e8f0);border-radius:6px;font-size:.9rem;resize:vertical;box-sizing:border-box}
    .pk-modal-actions{display:flex;gap:.5rem;justify-content:flex-end;margin-top:.75rem}
    .pk-modal-actions button{padding:.4rem 1rem;border-radius:6px;font-size:.85rem;font-weight:600;cursor:pointer;border:1.5px solid var(--border,#e2e8f0)}
    .pk-modal-actions .pk-save{background:var(--accent,#2563eb);color:#fff;border-color:transparent}

    /* ── Mobile/tablet touch optimization ── */
    @media (max-width: 1024px) {
        .pk-stats { padding:.75rem 1rem; }
        .pk-stat { min-width:0;flex:1 1 calc(50% - .5rem); }
        .pk-grid { padding:.75rem 1rem; }
        .pk-toolbar { gap:.4rem; }
        .pk-toolbar input[type=text] { width:100%;font-size:1rem;min-height:44px; }
        .pk-toolbar button { min-height:44px;font-size:.85rem; }
        .pk-actions button, .pk-actions a { min-height:44px;font-size:.85rem;padding:.5rem .8rem; }
        .pk-filter button { min-height:40px;font-size:.85rem;padding:.4rem .7rem; }
        .pk-counter button { width:36px;height:36px;font-size:1rem; }
        .pk-counter span { min-width:28px;font-size:.95rem; }
        .pk-tbl-input { width:48px;padding:.4rem .3rem;font-size:1rem;min-height:36px; }
        .pk-cash-input { width:80px;padding:.4rem .3rem;font-size:1rem;min-height:36px; }
        .pk-act-btn { min-height:36px;font-size:.85rem;padding:.35rem .5rem; }
        .pk-check { width:24px;height:24px; }
        .pk-table { font-size:.9rem; }
        .pk-table th { font-size:.7rem;padding:.45rem .5rem; }
        .pk-table td { padding:.45rem .5rem; }
        .pk-settings-panel { margin:.75rem 1rem; }
        .pk-settings-grid input, .pk-settings-grid select { font-size:1rem;min-height:44px; }
        .pk-setup { margin:1.5rem 1rem;padding:1.5rem; }
        .pk-setup input, .pk-setup select { font-size:1rem;min-height:44px; }
        .pk-modal { width:95%;padding:1.25rem; }
        .pk-modal textarea { font-size:1rem; }
        .pk-modal-actions button { min-height:44px;font-size:.9rem; }
        .pk-payout-editor input { font-size:1rem;min-height:36px; }
        .pk-payout-editor button { min-height:36px;font-size:.85rem; }
        .pk-settings-save { min-height:44px;font-size:.95rem; }
        .pk-rsvp-select { font-size:1rem !important;padding:.35rem .5rem !important;min-height:36px; }
    }
    </style>
</head>
<body>

<?php $nav_active = ''; require __DIR__ . '/_nav.php'; ?>

<div id="app"></div>

<!-- Notes modal -->
<div class="pk-modal-overlay" id="notesModal">
    <div class="pk-modal">
        <h3>Player Notes</h3>
        <textarea id="notesText" placeholder="Notes about this player..."></textarea>
        <div class="pk-modal-actions">
            <button onclick="closeNotes()">Cancel</button>
            <button class="pk-save" onclick="saveNotes()">Save</button>
        </div>
    </div>
</div>

<!-- Cash-out modal -->
<div class="pk-modal-overlay" id="cashoutModal">
    <div class="pk-modal">
        <h3>Cash Out Player</h3>
        <label style="font-size:.85rem;font-weight:600;color:#475569;display:block;margin-bottom:.3rem">Cash-out amount ($)</label>
        <input type="number" id="cashoutAmount" step="0.01" min="0" style="width:100%;padding:.5rem;border:1.5px solid var(--border,#e2e8f0);border-radius:6px;font-size:1rem;box-sizing:border-box">
        <div class="pk-modal-actions">
            <button onclick="closeCashout()">Cancel</button>
            <button class="pk-save" onclick="saveCashout()">Cash Out</button>
        </div>
    </div>
</div>

<?php require __DIR__ . '/_footer.php'; ?>

<script>
var CSRF = <?= json_encode($csrf) ?>;
var EVENT_ID = <?= $event_id ?>;
var SESSION = <?= $session ? json_encode($session) : 'null' ?>;
var PLAYERS = [];
var PAYOUTS = [];
var POOL = {};
var FILTER = 'all';
var notesPlayerId = null;
var cashoutPlayerId = null;

function isCash() { return SESSION && SESSION.game_type === 'cash'; }
function isTourney() { return !SESSION || SESSION.game_type === 'tournament'; }

function formatMoney(cents) {
    return '$' + (cents / 100).toFixed(2);
}
function formatProfit(cents) {
    if (cents > 0) return '+$' + (cents / 100).toFixed(2);
    if (cents < 0) return '-$' + (Math.abs(cents) / 100).toFixed(2);
    return '$0.00';
}

function rsvpBg(r) {
    if (r === 'yes') return '#dcfce7';
    if (r === 'no') return '#fee2e2';
    if (r === 'maybe') return '#fef9c3';
    return '#f1f5f9';
}
function rsvpColor(r) {
    if (r === 'yes') return '#166534';
    if (r === 'no') return '#991b1b';
    if (r === 'maybe') return '#854d0e';
    return '#64748b';
}

function postAction(action, data, callback) {
    var fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('action', action);
    for (var k in data) fd.append(k, data[k]);
    fetch('/checkin_dl.php', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(j) {
            if (!j.ok) { alert(j.error || 'Error'); return; }
            callback(j);
        })
        .catch(function(e) { console.error(e); alert('Request failed'); });
}

function loadSession() {
    fetch('/checkin_dl.php?action=get_session&event_id=' + EVENT_ID)
        .then(function(r) { return r.json(); })
        .then(function(j) {
            if (!j.ok) { alert(j.error || 'Error'); return; }
            if (!j.session) {
                renderSetup();
            } else {
                SESSION = j.session;
                PLAYERS = j.players;
                PAYOUTS = j.payouts;
                POOL = j.pool;
                renderDashboard();
            }
        });
}

// ─── SETUP SCREEN ──────────────────────────────────────
var setupGameType = 'tournament';

function renderSetup() {
    var h = '<div class="pk-setup">';
    h += '<h2>Start Poker Session</h2>';
    h += '<p>Configure the game for <strong>' + escHtml(<?= json_encode($event['title']) ?>) + '</strong></p>';
    h += '<label>Game Type</label>';
    h += '<div class="pk-type-toggle" id="typeToggle">';
    h += '<button class="t-tournament active" onclick="setSetupType(\'tournament\')">Tournament</button>';
    h += '<button class="t-cash" onclick="setSetupType(\'cash\')">Cash Game</button>';
    h += '</div>';
    h += '<label>Buy-in Amount ($)</label><input type="number" id="s_buyin" value="20" step="0.01" min="0">';
    h += '<div id="setupTourneyFields">';
    h += '<label>Rebuy Amount ($)</label><input type="number" id="s_rebuy" value="20" step="0.01" min="0">';
    h += '<label>Add-on Amount ($)</label><input type="number" id="s_addon" value="10" step="0.01" min="0">';
    h += '<label>Starting Chips</label><input type="number" id="s_chips" value="5000" step="1" min="1">';
    h += '</div>';
    h += '<label>Number of Tables</label><input type="number" id="s_tables" value="1" step="1" min="1">';
    h += '<button type="submit" onclick="initSession()">Create Session &amp; Import Players</button>';
    h += '</div>';
    document.getElementById('app').innerHTML = h;
}

function setSetupType(type) {
    setupGameType = type;
    var btns = document.querySelectorAll('#typeToggle button');
    btns.forEach(function(b) { b.classList.remove('active'); });
    document.querySelector('#typeToggle .t-' + type).classList.add('active');
    var tf = document.getElementById('setupTourneyFields');
    if (tf) tf.style.display = type === 'cash' ? 'none' : '';
}

function initSession() {
    var buyin = Math.round(parseFloat(document.getElementById('s_buyin').value || 20) * 100);
    var data = {
        event_id: EVENT_ID,
        buyin_amount: buyin,
        game_type: setupGameType,
        num_tables: parseInt(document.getElementById('s_tables').value || 1)
    };
    if (setupGameType === 'tournament') {
        data.rebuy_amount = Math.round(parseFloat(document.getElementById('s_rebuy').value || 20) * 100);
        data.addon_amount = Math.round(parseFloat(document.getElementById('s_addon').value || 10) * 100);
        data.starting_chips = parseInt(document.getElementById('s_chips').value || 5000);
    } else {
        data.rebuy_amount = buyin; // rebuys cost same as buy-in for cash
        data.addon_amount = 0;
        data.starting_chips = 0;
    }
    postAction('init_session', data, function(j) {
        SESSION = j.session;
        PLAYERS = j.players;
        PAYOUTS = j.payouts;
        POOL = j.pool;
        renderDashboard();
        loadSession();
    });
}

// ─── DASHBOARD ─────────────────────────────────────────
function renderDashboard() {
    var statusClass = 'pk-badge-' + SESSION.status;
    var typeClass = 'pk-badge-' + SESSION.game_type;
    var typeLabel = isCash() ? 'CASH' : 'TOURNAMENT';
    var h = '';

    // Header
    h += '<div class="pk-header">';
    h += '<a href="/calendar.php" class="pk-btn-back" title="Back to Calendar" style="text-decoration:none">&larr;</a>';
    h += '<h1>' + escHtml(<?= json_encode($event['title']) ?>) + ' <a href="/calendar.php">Calendar</a></h1>';
    h += '<span class="pk-badge ' + typeClass + '">' + typeLabel + '</span>';
    h += '<span class="pk-badge ' + statusClass + '" id="statusBadge">' + SESSION.status.toUpperCase() + '</span>';
    h += '<div class="pk-actions">';
    h += '<button class="pk-btn-settings" onclick="toggleSettings()">&#9881; Settings</button>';
    if (SESSION.status === 'setup') {
        h += '<button class="pk-btn-start" onclick="changeStatus(\'active\')">&#9654; Start Game</button>';
    } else if (SESSION.status === 'active') {
        h += '<button class="pk-btn-end" onclick="if(confirm(\'End the game?\'))changeStatus(\'finished\')">&#9632; End Game</button>';
    } else {
        h += '<button class="pk-btn-start" onclick="changeStatus(\'active\')">&#9654; Reopen</button>';
    }
    h += '</div>';
    if (isCash()) {
        h += '<div class="pk-pool" id="poolTotal"><small>Money In Play</small>' + formatMoney(POOL.total_cash_in) + '</div>';
    } else {
        h += '<div class="pk-pool" id="poolTotal"><small>Prize Pool</small>' + formatMoney(POOL.pool_total) + '</div>';
    }
    h += '</div>';

    // Settings panel
    h += renderSettingsPanel();

    // Stats
    h += '<div class="pk-stats" id="statsRow">';
    h += renderStats();
    h += '</div>';

    // Grid
    h += '<div class="pk-grid">';

    // Left: player table
    h += '<div>';
    h += '<div class="pk-toolbar">';
    h += '<input type="text" id="walkinName" placeholder="Walk-in name...">';
    h += '<button class="pk-btn-add" onclick="addWalkin()">+ Add Walk-in</button>';
    h += '<div class="pk-filter">';
    h += '<button class="' + (FILTER==='all'?'active':'') + '" onclick="setFilter(\'all\')">All</button>';
    h += '<button class="' + (FILTER==='rsvp_yes'?'active':'') + '" onclick="setFilter(\'rsvp_yes\')">RSVP Yes</button>';
    if (isTourney()) {
        h += '<button class="' + (FILTER==='playing'?'active':'') + '" onclick="setFilter(\'playing\')">Playing</button>';
        h += '<button class="' + (FILTER==='eliminated'?'active':'') + '" onclick="setFilter(\'eliminated\')">Out</button>';
    } else {
        h += '<button class="' + (FILTER==='playing'?'active':'') + '" onclick="setFilter(\'playing\')">Active</button>';
        h += '<button class="' + (FILTER==='eliminated'?'active':'') + '" onclick="setFilter(\'eliminated\')">Cashed Out</button>';
    }
    h += '</div>';
    h += '</div>';
    h += '<div class="pk-table-wrap"><table class="pk-table">';
    h += '<thead><tr>' + renderTableHeader() + '</tr></thead>';
    h += '<tbody id="playerBody">';
    h += renderPlayerRows();
    h += '</tbody></table></div>';
    h += '</div>';

    // Right: sidebar
    h += '<div class="pk-sidebar">';
    h += '<div class="pk-card" id="poolCard">' + renderPoolCard() + '</div>';
    if (isTourney()) {
        h += '<div class="pk-card" id="payoutCard">' + renderPayoutCard() + '</div>';
    }
    h += '</div>';

    h += '</div>'; // pk-grid

    document.getElementById('app').innerHTML = h;
}

function renderTableHeader() {
    var h = '<th>#</th><th>Name</th><th>RSVP</th><th title="Checked In">&#10003;</th>';
    if (isTourney()) {
        h += '<th title="Buy-in">$</th>';
        if (parseInt(SESSION.rebuy_allowed)) h += '<th>Rebuys</th>';
        if (parseInt(SESSION.addon_allowed)) h += '<th>Add-ons</th>';
    } else {
        h += '<th>Total In</th><th>Cash Out</th><th>Profit</th>';
    }
    if (parseInt(SESSION.num_tables) > 1) h += '<th>Table</th>';
    h += '<th>Status</th><th>Actions</th>';
    return h;
}

function renderStats() {
    var h = '';
    h += '<div class="pk-stat"><div class="pk-stat-label">Players</div><div class="pk-stat-value">' + POOL.total_players + '</div></div>';
    h += '<div class="pk-stat"><div class="pk-stat-label">Checked In</div><div class="pk-stat-value">' + POOL.checked_in + '</div></div>';
    h += '<div class="pk-stat"><div class="pk-stat-label">Bought In</div><div class="pk-stat-value">' + POOL.bought_in + '</div></div>';
    if (isTourney()) {
        h += '<div class="pk-stat"><div class="pk-stat-label">Playing</div><div class="pk-stat-value">' + POOL.still_playing + '</div></div>';
        h += '<div class="pk-stat"><div class="pk-stat-label">Eliminated</div><div class="pk-stat-value">' + POOL.eliminated + '</div></div>';
    } else {
        var active = POOL.bought_in - POOL.cashed_out;
        h += '<div class="pk-stat"><div class="pk-stat-label">Active</div><div class="pk-stat-value">' + active + '</div></div>';
        h += '<div class="pk-stat"><div class="pk-stat-label">Cashed Out</div><div class="pk-stat-value">' + POOL.cashed_out + '</div></div>';
        var balance = POOL.total_cash_in - POOL.total_cash_out;
        h += '<div class="pk-stat"><div class="pk-stat-label">On Table</div><div class="pk-stat-value">' + formatMoney(balance) + '</div></div>';
    }
    return h;
}

function playerTotalIn(p) {
    if (isCash()) {
        return parseInt(p.cash_in) || 0;
    }
    var buyinAmt = parseInt(SESSION.buyin_amount);
    var rebuyAmt = parseInt(SESSION.rebuy_amount);
    return (parseInt(p.bought_in) * buyinAmt) + (parseInt(p.rebuys) * rebuyAmt);
}

function playerProfit(p) {
    if (p.cash_out === null || p.cash_out === undefined) return null;
    return parseInt(p.cash_out) - playerTotalIn(p);
}

function renderPlayerRows() {
    var h = '';
    var num = 0;
    var filtered = PLAYERS.filter(function(p) {
        if (FILTER === 'rsvp_yes') return p.rsvp === 'yes';
        if (isCash()) {
            if (FILTER === 'playing') return parseInt(p.bought_in) && (p.cash_out === null || p.cash_out === undefined);
            if (FILTER === 'eliminated') return p.cash_out !== null && p.cash_out !== undefined;
        } else {
            if (FILTER === 'playing') return !parseInt(p.eliminated) && parseInt(p.bought_in);
            if (FILTER === 'eliminated') return parseInt(p.eliminated);
        }
        return true;
    });
    for (var i = 0; i < filtered.length; i++) {
        var p = filtered[i];
        num++;
        var isElim = parseInt(p.eliminated);
        var hasCashedOut = isCash() && p.cash_out !== null && p.cash_out !== undefined;
        var isWalkin = !p.user_id;
        var rsvp = p.rsvp || '';
        var isNo = rsvp === 'no';
        var dis = isNo ? ' disabled' : '';
        var rowClass = isElim ? 'elim' : (hasCashedOut ? 'cashed-out' : (isNo ? 'rsvp-no' : ''));
        h += '<tr class="' + rowClass + '" data-pid="' + p.id + '">';
        h += '<td>' + num + '</td>';
        h += '<td class="name-cell">' + escHtml(p.display_name);
        if (isWalkin) h += '<span class="walkin-badge">WALK-IN</span>';
        if (p.notes) h += ' <span title="' + escHtml(p.notes) + '" style="cursor:help">&#128221;</span>';
        h += '</td>';

        // RSVP dropdown
        h += '<td><select class="pk-rsvp-select" onchange="updateRsvp(' + p.id + ',this.value)" style="font-size:.75rem;padding:.15rem .3rem;border-radius:4px;border:1px solid #e2e8f0;background:' + rsvpBg(rsvp) + ';color:' + rsvpColor(rsvp) + ';font-weight:600">';
        h += '<option value=""' + (rsvp===''?' selected':'') + '>—</option>';
        h += '<option value="yes"' + (rsvp==='yes'?' selected':'') + '>Yes</option>';
        h += '<option value="no"' + (rsvp==='no'?' selected':'') + '>No</option>';
        h += '<option value="maybe"' + (rsvp==='maybe'?' selected':'') + '>Maybe</option>';
        h += '</select></td>';

        h += '<td><input type="checkbox" class="pk-check" ' + (parseInt(p.checked_in) ? 'checked' : '') + dis + ' onchange="toggleCheckin(' + p.id + ')"></td>';

        if (isTourney()) {
            h += '<td><input type="checkbox" class="pk-check" ' + (parseInt(p.bought_in) ? 'checked' : '') + dis + ' onchange="toggleBuyin(' + p.id + ')"></td>';
            if (parseInt(SESSION.rebuy_allowed)) {
                h += '<td><div class="pk-counter"><button onclick="updateRebuys(' + p.id + ',-1)"' + dis + '>-</button><span>' + p.rebuys + '</span><button onclick="updateRebuys(' + p.id + ',1)"' + dis + '>+</button></div></td>';
            }
            if (parseInt(SESSION.addon_allowed)) {
                h += '<td><div class="pk-counter"><button onclick="updateAddons(' + p.id + ',-1)"' + dis + '>-</button><span>' + p.addons + '</span><button onclick="updateAddons(' + p.id + ',1)"' + dis + '>+</button></div></td>';
            }
        } else {
            // Cash game: total in (with add money button), cash out, profit
            var cashIn = parseInt(p.cash_in) || 0;
            if (isNo) {
                h += '<td><span style="color:#94a3b8">' + formatMoney(cashIn) + '</span></td>';
                h += '<td><span style="color:#94a3b8">—</span></td>';
                h += '<td><span style="color:#94a3b8">—</span></td>';
            } else {
                h += '<td><div class="pk-counter"><button onclick="adjustMoney(' + p.id + ',-1)">-</button><input type="text" class="pk-cash-input" value="' + (cashIn/100).toFixed(2) + '" onchange="setCashIn(' + p.id + ',this.value)" style="border:none;min-width:60px"><button onclick="adjustMoney(' + p.id + ',1)">+</button></div></td>';
                if (hasCashedOut) {
                    h += '<td>' + formatMoney(parseInt(p.cash_out)) + '</td>';
                    var prof = parseInt(p.cash_out) - cashIn;
                    var cls = prof > 0 ? 'pk-profit-pos' : (prof < 0 ? 'pk-profit-neg' : 'pk-profit-zero');
                    h += '<td><span class="' + cls + '">' + formatProfit(prof) + '</span></td>';
                } else {
                    h += '<td><span style="color:#94a3b8">—</span></td>';
                    h += '<td><span style="color:#94a3b8">—</span></td>';
                }
            }
        }

        if (parseInt(SESSION.num_tables) > 1) {
            h += '<td><input type="number" class="pk-tbl-input" value="' + (p.table_number || '') + '" min="1" max="' + SESSION.num_tables + '" onchange="setTable(' + p.id + ',this.value)"></td>';
        }

        // Status
        if (isTourney()) {
            if (isElim) {
                h += '<td><span style="color:#ef4444;font-weight:600">#' + (p.finish_position || '?') + '</span></td>';
            } else if (parseInt(p.bought_in)) {
                h += '<td><span style="color:#22c55e;font-weight:600">Playing</span></td>';
            } else {
                h += '<td><span style="color:#94a3b8">—</span></td>';
            }
        } else {
            if (hasCashedOut) {
                h += '<td><span style="color:#64748b;font-weight:600">Cashed Out</span></td>';
            } else if (parseInt(p.bought_in)) {
                h += '<td><span style="color:#22c55e;font-weight:600">Playing</span></td>';
            } else {
                h += '<td><span style="color:#94a3b8">—</span></td>';
            }
        }

        // Actions
        h += '<td style="white-space:nowrap">';
        if (!isNo) {
            if (isTourney()) {
                if (!isElim && parseInt(p.bought_in)) {
                    h += '<button class="pk-act-btn" onclick="eliminatePlayer(' + p.id + ')">Eliminate</button>';
                }
                if (isElim) {
                    h += '<button class="pk-act-btn" onclick="uneliminate(' + p.id + ')">Undo</button>';
                }
            } else {
                if (parseInt(p.bought_in) && !hasCashedOut) {
                    h += '<button class="pk-act-btn" onclick="openCashout(' + p.id + ')">Cash Out</button>';
                }
                if (hasCashedOut) {
                    h += '<button class="pk-act-btn" onclick="undoCashout(' + p.id + ')">Undo Cash Out</button>';
                }
            }
        }
        h += '<button class="pk-act-btn" onclick="openNotes(' + p.id + ')">Notes</button>';
        h += '<button class="pk-act-btn danger" onclick="if(confirm(\'Remove ' + escHtml(p.display_name) + '?\'))removePlayer(' + p.id + ')">Remove</button>';
        h += '</td>';
        h += '</tr>';
    }
    if (filtered.length === 0) {
        var cols = isTourney()
            ? 7 + (parseInt(SESSION.rebuy_allowed)?1:0) + (parseInt(SESSION.addon_allowed)?1:0) + (parseInt(SESSION.num_tables)>1?1:0)
            : 8 + (parseInt(SESSION.num_tables)>1?1:0);
        h += '<tr><td colspan="' + cols + '" style="text-align:center;padding:2rem;color:#94a3b8">No players</td></tr>';
    }
    return h;
}

function renderPoolCard() {
    var h = '';
    if (isCash()) {
        h += '<h3>Money Summary</h3>';
        h += '<div class="pk-pool-row"><span>Total Money In</span><span>' + formatMoney(POOL.total_cash_in) + '</span></div>';
        h += '<div class="pk-pool-row"><span>Total Cashed Out</span><span>' + formatMoney(POOL.total_cash_out) + '</span></div>';
        var onTable = POOL.total_cash_in - POOL.total_cash_out;
        h += '<div class="pk-pool-row total"><span>Still On Table</span><span>' + formatMoney(onTable) + '</span></div>';
    } else {
        h += '<h3>Prize Pool</h3>';
        h += '<div class="pk-pool-row"><span>Buy-ins (' + POOL.total_buyins + ' &times; ' + formatMoney(parseInt(SESSION.buyin_amount)) + ')</span><span>' + formatMoney(POOL.buyin_total) + '</span></div>';
        h += '<div class="pk-pool-row"><span>Rebuys (' + POOL.total_rebuys + ' &times; ' + formatMoney(parseInt(SESSION.rebuy_amount)) + ')</span><span>' + formatMoney(POOL.rebuy_total) + '</span></div>';
        h += '<div class="pk-pool-row"><span>Add-ons (' + POOL.total_addons + ' &times; ' + formatMoney(parseInt(SESSION.addon_amount)) + ')</span><span>' + formatMoney(POOL.addon_total) + '</span></div>';
        h += '<div class="pk-pool-row total"><span>Total</span><span>' + formatMoney(POOL.pool_total) + '</span></div>';
    }
    return h;
}

function renderPayoutCard() {
    var h = '<h3>Payouts</h3>';
    var totalPct = 0;
    for (var i = 0; i < PAYOUTS.length; i++) {
        var pay = PAYOUTS[i];
        var pct = parseFloat(pay.percentage);
        totalPct += pct;
        var amt = Math.round(POOL.pool_total * pct / 100);
        var placeLabel = pay.place == 1 ? '1st' : pay.place == 2 ? '2nd' : pay.place == 3 ? '3rd' : pay.place + 'th';
        h += '<div class="pk-payout-row"><span class="pk-payout-place">' + placeLabel + ' (' + pct + '%)</span><span style="font-weight:600;color:#22c55e">' + formatMoney(amt) + '</span></div>';
    }
    h += '<div style="margin-top:.5rem;text-align:center"><button class="pk-act-btn" onclick="toggleSettings()" style="font-size:.8rem">Edit in Settings</button></div>';
    return h;
}

function renderSettingsPanel() {
    var h = '<div class="pk-settings-panel" id="settingsPanel">';
    h += '<h3 style="margin:0 0 .75rem;font-size:1rem">Game Settings</h3>';
    h += '<div class="pk-settings-grid">';
    h += '<div><label>Game Type</label><select id="cfg_game_type" onchange="previewGameType(this.value)"><option value="tournament"' + (isTourney()?' selected':'') + '>Tournament</option><option value="cash"' + (isCash()?' selected':'') + '>Cash Game</option></select></div>';
    h += '<div><label>Buy-in ($)</label><input type="number" id="cfg_buyin" value="' + (parseInt(SESSION.buyin_amount)/100).toFixed(2) + '" step="0.01" min="0"></div>';
    h += '</div>'; // close pk-settings-grid
    h += '<div class="pk-settings-grid" id="cfgTourneyFields" style="margin-top:.75rem;' + (isCash()?'display:none':'') + '">';
    h += '<div><label>Rebuy ($)</label><input type="number" id="cfg_rebuy" value="' + (parseInt(SESSION.rebuy_amount)/100).toFixed(2) + '" step="0.01" min="0"></div>';
    h += '<div><label>Add-on ($)</label><input type="number" id="cfg_addon" value="' + (parseInt(SESSION.addon_amount)/100).toFixed(2) + '" step="0.01" min="0"></div>';
    h += '<div><label>Starting Chips</label><input type="number" id="cfg_chips" value="' + SESSION.starting_chips + '" min="1"></div>';
    h += '<div><label>Rebuys Allowed</label><select id="cfg_rebuy_allowed"><option value="1"' + (parseInt(SESSION.rebuy_allowed)?' selected':'') + '>Yes</option><option value="0"' + (!parseInt(SESSION.rebuy_allowed)?' selected':'') + '>No</option></select></div>';
    h += '<div><label>Max Rebuys (0=unlimited)</label><input type="number" id="cfg_max_rebuys" value="' + SESSION.max_rebuys + '" min="0"></div>';
    h += '<div><label>Add-ons Allowed</label><select id="cfg_addon_allowed"><option value="1"' + (parseInt(SESSION.addon_allowed)?' selected':'') + '>Yes</option><option value="0"' + (!parseInt(SESSION.addon_allowed)?' selected':'') + '>No</option></select></div>';
    h += '</div>';
    h += '<div class="pk-settings-grid" style="margin-top:.75rem">';
    h += '<div><label>Number of Tables</label><input type="number" id="cfg_tables" value="' + SESSION.num_tables + '" min="1"></div>';
    h += '</div>';

    // Payout editor (tournament only)
    h += '<div class="pk-payout-editor" id="cfgPayoutSection" style="' + (isCash()?'display:none':'') + '">';
    h += '<h3 style="margin:.75rem 0 .5rem;font-size:.9rem">Payout Structure</h3>';
    h += '<div id="payoutRows">';
    for (var i = 0; i < PAYOUTS.length; i++) {
        h += payoutRowHtml(PAYOUTS[i].place, PAYOUTS[i].percentage);
    }
    h += '</div>';
    h += '<button onclick="addPayoutRow()" style="margin-top:.3rem">+ Add Place</button>';
    h += '<div id="payoutSum" style="margin-top:.3rem;font-size:.8rem;color:#64748b"></div>';
    h += '</div>';

    h += '<button class="pk-settings-save" onclick="saveSettings()">Save Settings</button>';
    h += '</div>';
    return h;
}

function payoutRowHtml(place, pct) {
    return '<div class="row"><label style="font-size:.8rem;width:40px">' + place + getOrdinal(place) + '</label><input type="number" class="payout-pct" value="' + pct + '" step="0.1" min="0" max="100" data-place="' + place + '" oninput="updatePayoutSum()"> <span style="font-size:.8rem">%</span> <button onclick="this.parentNode.remove();updatePayoutSum()" style="color:#ef4444;background:transparent;border:none;cursor:pointer;font-size:1rem">&times;</button></div>';
}

function addPayoutRow() {
    var rows = document.querySelectorAll('#payoutRows .row');
    var nextPlace = rows.length + 1;
    var div = document.createElement('div');
    div.innerHTML = payoutRowHtml(nextPlace, 0);
    document.getElementById('payoutRows').appendChild(div.firstChild);
    updatePayoutSum();
}

function updatePayoutSum() {
    var inputs = document.querySelectorAll('.payout-pct');
    var sum = 0;
    for (var i = 0; i < inputs.length; i++) sum += parseFloat(inputs[i].value || 0);
    var el = document.getElementById('payoutSum');
    if (el) {
        el.textContent = 'Total: ' + sum.toFixed(1) + '%';
        el.style.color = sum > 100 ? '#ef4444' : sum === 100 ? '#22c55e' : '#64748b';
    }
}

function getOrdinal(n) {
    if (n === 1) return 'st';
    if (n === 2) return 'nd';
    if (n === 3) return 'rd';
    return 'th';
}

function previewGameType(val) {
    var tf = document.getElementById('cfgTourneyFields');
    var pf = document.getElementById('cfgPayoutSection');
    if (tf) tf.style.display = val === 'cash' ? 'none' : '';
    if (pf) pf.style.display = val === 'cash' ? 'none' : '';
}

// ─── ACTIONS ───────────────────────────────────────────
function toggleSettings() {
    var panel = document.getElementById('settingsPanel');
    if (panel) panel.classList.toggle('open');
}

function changeStatus(status) {
    postAction('update_status', { session_id: SESSION.id, status: status }, function(j) {
        SESSION.status = j.status;
        renderDashboard();
    });
}

function toggleCheckin(pid) {
    postAction('toggle_checkin', { player_id: pid }, function(j) {
        updatePlayer(j.player);
        POOL = j.pool;
        refreshUI();
    });
}

function toggleBuyin(pid) {
    postAction('toggle_buyin', { player_id: pid }, function(j) {
        updatePlayer(j.player);
        POOL = j.pool;
        refreshUI();
    });
}

function updateRebuys(pid, delta) {
    postAction('update_rebuys', { player_id: pid, delta: delta }, function(j) {
        updatePlayer(j.player);
        POOL = j.pool;
        refreshUI();
    });
}

function updateAddons(pid, delta) {
    postAction('update_addons', { player_id: pid, delta: delta }, function(j) {
        updatePlayer(j.player);
        POOL = j.pool;
        refreshUI();
    });
}

function setTable(pid, val) {
    postAction('set_table', { player_id: pid, table_number: val }, function(j) {
        updatePlayer(j.player);
    });
}

function eliminatePlayer(pid) {
    var playing = PLAYERS.filter(function(p) { return !parseInt(p.eliminated) && parseInt(p.bought_in); }).length;
    var pos = prompt('Finish position? (suggested: ' + playing + ')', playing);
    if (pos === null) return;
    pos = parseInt(pos);
    if (!pos || pos < 1) { alert('Invalid position'); return; }
    postAction('eliminate_player', { player_id: pid, finish_position: pos }, function(j) {
        updatePlayer(j.player);
        POOL = j.pool;
        refreshUI();
    });
}

function uneliminate(pid) {
    postAction('uneliminate_player', { player_id: pid }, function(j) {
        updatePlayer(j.player);
        POOL = j.pool;
        refreshUI();
    });
}

// Cash game: set exact cash in value
function setCashIn(pid, val) {
    var amt = parseFloat(val);
    if (isNaN(amt) || amt < 0) { refreshUI(); return; }
    var cents = Math.round(amt * 100);
    postAction('set_cashin', { player_id: pid, amount: cents }, function(j) {
        updatePlayer(j.player);
        POOL = j.pool;
        refreshUI();
    });
}

// Cash game: adjust money in (+ to add, - to subtract)
function adjustMoney(pid, direction) {
    var label = direction > 0 ? 'Amount to add ($):' : 'Amount to remove ($):';
    var amt = prompt(label, '20');
    if (amt === null) return;
    amt = parseFloat(amt);
    if (isNaN(amt) || amt <= 0) { alert('Enter a positive amount'); return; }
    var cents = Math.round(amt * 100);
    if (direction < 0) {
        // Subtract: get current cash_in and set new total
        var p = PLAYERS.find(function(p) { return parseInt(p.id) === pid; });
        var cur = parseInt(p.cash_in) || 0;
        var newVal = Math.max(0, cur - cents);
        postAction('set_cashin', { player_id: pid, amount: newVal }, function(j) {
            updatePlayer(j.player);
            POOL = j.pool;
            refreshUI();
        });
    } else {
        postAction('add_cashin', { player_id: pid, amount: cents }, function(j) {
            updatePlayer(j.player);
            POOL = j.pool;
            refreshUI();
        });
    }
}

// Cash game: cash out
function openCashout(pid) {
    cashoutPlayerId = pid;
    var p = PLAYERS.find(function(p) { return parseInt(p.id) === pid; });
    // Pre-fill with total in as a starting suggestion
    var totalIn = p ? playerTotalIn(p) : 0;
    document.getElementById('cashoutAmount').value = (totalIn / 100).toFixed(2);
    document.getElementById('cashoutModal').classList.add('open');
}

function closeCashout() {
    document.getElementById('cashoutModal').classList.remove('open');
    cashoutPlayerId = null;
}

function saveCashout() {
    if (!cashoutPlayerId) return;
    var amt = Math.round(parseFloat(document.getElementById('cashoutAmount').value || 0) * 100);
    postAction('set_cashout', { player_id: cashoutPlayerId, cash_out: amt }, function(j) {
        updatePlayer(j.player);
        POOL = j.pool;
        closeCashout();
        refreshUI();
    });
}

function undoCashout(pid) {
    postAction('set_cashout', { player_id: pid, cash_out: '' }, function(j) {
        updatePlayer(j.player);
        POOL = j.pool;
        refreshUI();
    });
}

function addWalkin() {
    var name = document.getElementById('walkinName').value.trim();
    if (!name) { alert('Enter a name'); return; }
    postAction('add_walkin', { session_id: SESSION.id, name: name }, function(j) {
        PLAYERS.push(j.player);
        POOL = j.pool;
        document.getElementById('walkinName').value = '';
        refreshUI();
    });
}

function removePlayer(pid) {
    postAction('remove_player', { player_id: pid }, function(j) {
        PLAYERS = PLAYERS.filter(function(p) { return parseInt(p.id) !== pid; });
        POOL = j.pool;
        refreshUI();
    });
}

function updateRsvp(pid, val) {
    postAction('update_rsvp', { player_id: pid, rsvp: val }, function(j) {
        updatePlayer(j.player);
        POOL = j.pool;
        refreshUI();
    });
}

function saveSettings() {
    var data = {
        session_id: SESSION.id,
        buyin_amount: Math.round(parseFloat(document.getElementById('cfg_buyin').value || 0) * 100),
        game_type: document.getElementById('cfg_game_type').value,
        num_tables: parseInt(document.getElementById('cfg_tables').value || 1),
    };
    if (document.getElementById('cfg_game_type').value === 'tournament') {
        data.rebuy_amount = Math.round(parseFloat((document.getElementById('cfg_rebuy') || {}).value || 0) * 100);
        data.addon_amount = Math.round(parseFloat((document.getElementById('cfg_addon') || {}).value || 0) * 100);
        data.starting_chips = parseInt((document.getElementById('cfg_chips') || {}).value || 5000);
        data.rebuy_allowed = (document.getElementById('cfg_rebuy_allowed') || {}).value || '1';
        data.max_rebuys = parseInt((document.getElementById('cfg_max_rebuys') || {}).value || 0);
        data.addon_allowed = (document.getElementById('cfg_addon_allowed') || {}).value || '1';
    } else {
        data.rebuy_amount = data.buyin_amount;
        data.addon_amount = 0;
        data.starting_chips = 0;
        data.rebuy_allowed = 1;
        data.max_rebuys = 0;
        data.addon_allowed = 0;
    }
    postAction('update_config', data, function(j) {
        SESSION = j.session;
        POOL = j.pool;
        // Save payouts too (tournament only)
        var inputs = document.querySelectorAll('.payout-pct');
        if (inputs.length > 0 && SESSION.game_type === 'tournament') {
            var places = [], pcts = [], pctSum = 0;
            for (var i = 0; i < inputs.length; i++) {
                places.push(inputs[i].getAttribute('data-place'));
                pcts.push(inputs[i].value);
                pctSum += parseFloat(inputs[i].value || 0);
            }
            if (pctSum > 100) {
                alert('Payout percentages total ' + pctSum.toFixed(1) + '% — cannot exceed 100%.');
                return;
            }
            var fd = new FormData();
            fd.append('csrf_token', CSRF);
            fd.append('action', 'update_payouts');
            fd.append('session_id', SESSION.id);
            for (var i = 0; i < places.length; i++) {
                fd.append('places[]', places[i]);
                fd.append('percentages[]', pcts[i]);
            }
            fetch('/checkin_dl.php', { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(j2) {
                    if (j2.ok) {
                        PAYOUTS = j2.payouts;
                        POOL = j2.pool;
                    }
                    renderDashboard();
                });
        } else {
            renderDashboard();
        }
    });
}

function openNotes(pid) {
    notesPlayerId = pid;
    var p = PLAYERS.find(function(x) { return parseInt(x.id) === pid; });
    document.getElementById('notesText').value = (p && p.notes) ? p.notes : '';
    document.getElementById('notesModal').classList.add('open');
}

function closeNotes() {
    document.getElementById('notesModal').classList.remove('open');
    notesPlayerId = null;
}

function saveNotes() {
    if (!notesPlayerId) return;
    postAction('update_notes', { player_id: notesPlayerId, notes: document.getElementById('notesText').value }, function(j) {
        updatePlayer(j.player);
        closeNotes();
        refreshUI();
    });
}

function setFilter(f) {
    FILTER = f;
    refreshUI();
}

// ─── HELPERS ───────────────────────────────────────────
function updatePlayer(updated) {
    for (var i = 0; i < PLAYERS.length; i++) {
        if (parseInt(PLAYERS[i].id) === parseInt(updated.id)) {
            PLAYERS[i] = updated;
            return;
        }
    }
}

function refreshUI() {
    var body = document.getElementById('playerBody');
    if (body) body.innerHTML = renderPlayerRows();
    var stats = document.getElementById('statsRow');
    if (stats) stats.innerHTML = renderStats();
    var poolEl = document.getElementById('poolTotal');
    if (poolEl) {
        if (isCash()) {
            poolEl.innerHTML = '<small>Money In Play</small>' + formatMoney(POOL.total_cash_in);
        } else {
            poolEl.innerHTML = '<small>Prize Pool</small>' + formatMoney(POOL.pool_total);
        }
    }
    var poolCard = document.getElementById('poolCard');
    if (poolCard) poolCard.innerHTML = renderPoolCard();
    var payoutCard = document.getElementById('payoutCard');
    if (payoutCard) payoutCard.innerHTML = renderPayoutCard();
}

function escHtml(s) {
    if (!s) return '';
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(s));
    return div.innerHTML;
}

// ─── INIT ──────────────────────────────────────────────
loadSession();
</script>
</body>
</html>
