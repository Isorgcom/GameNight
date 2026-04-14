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
$allUsernames = array_column($db->query('SELECT username FROM users ORDER BY username')->fetchAll(), 'username');

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

    .pk-grid{display:grid;grid-template-columns:1fr 280px;gap:1rem;padding:.75rem 1.5rem;width:100%;box-sizing:border-box}
    @media(max-width:1200px){.pk-grid{grid-template-columns:1fr 220px;gap:.75rem;padding:.75rem}}
    @media(max-width:1024px){.pk-grid{grid-template-columns:1fr;padding:.75rem}}

    .pk-toolbar{display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;margin-bottom:.75rem}
    .pk-toolbar input[type=text]{padding:.4rem .7rem;border:1.5px solid var(--border,#e2e8f0);border-radius:6px;font-size:.85rem;width:180px}
    .pk-toolbar button{padding:.4rem .8rem;border-radius:6px;font-size:.8rem;font-weight:600;cursor:pointer;border:1.5px solid transparent}
    .pk-btn-add{background:var(--accent,#2563eb);color:#fff}
    .pk-btn-add:hover{opacity:.9}
    .pk-btn-refresh{background:transparent;color:var(--accent,#2563eb);border-color:var(--border,#e2e8f0)}
    .pk-btn-refresh:hover{background:#f1f5f9}
    .pk-toolbar-sep{width:1px;height:1.5rem;background:#e2e8f0;margin:0 .25rem}
    .pk-filter{display:flex;gap:0;border:1.5px solid var(--border,#e2e8f0);border-radius:6px;overflow:hidden}
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

    .pk-sidebar{display:flex;flex-direction:column;gap:.75rem;min-width:0}
    .pk-card{background:var(--surface,#fff);border:1.5px solid var(--border,#e2e8f0);border-radius:8px;padding:.75rem;min-width:0;box-sizing:border-box;word-break:break-word}
    .pk-card h3{margin:0 0 .6rem;font-size:.85rem;text-transform:uppercase;letter-spacing:.04em;color:#64748b}
    .pk-pool-row{display:flex;justify-content:space-between;padding:.2rem 0;font-size:.8rem;gap:.25rem}
    .pk-pool-row.total{font-weight:700;font-size:.95rem;border-top:2px solid var(--border,#e2e8f0);margin-top:.3rem;padding-top:.4rem;color:#22c55e}
    .pk-payout-row{display:flex;justify-content:space-between;padding:.15rem 0;font-size:.8rem;gap:.25rem}
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

    .pk-btn-view-toggle{background:transparent;color:var(--accent,#2563eb);border:1.5px solid var(--border,#e2e8f0);padding:.4rem .8rem;border-radius:6px;font-size:.8rem;font-weight:600;cursor:pointer}
    .pk-btn-view-toggle:hover{background:#f1f5f9}
    .pk-table-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:1rem}
    .pk-table-card{background:var(--surface,#fff);border:1.5px solid var(--border,#e2e8f0);border-radius:8px;overflow:hidden}
    .pk-table-card-unassigned{border-color:#fbbf24}
    .pk-table-card-header{background:#f8fafc;padding:.6rem 1rem;border-bottom:1.5px solid var(--border,#e2e8f0);display:flex;justify-content:space-between;align-items:center}
    .pk-table-card-header h3{margin:0;font-size:.95rem;font-weight:700}
    .pk-table-card-header h3 span{font-weight:400;color:#64748b;font-size:.8rem}
    .pk-table-card-body{padding:.5rem}
    .pk-tv-player{display:flex;justify-content:space-between;align-items:center;padding:.4rem .5rem;border-radius:4px}
    .pk-tv-player:hover{background:#f1f5f9}
    .pk-tv-player.elim{opacity:.4;text-decoration:line-through}
    .pk-tv-name{font-weight:600;font-size:.85rem}
    .pk-tv-actions{display:flex;align-items:center;gap:.3rem}
    .pk-tv-move{font-size:.75rem;padding:.2rem .4rem;border:1px solid #e2e8f0;border-radius:4px;background:#fff;cursor:pointer}

    /* Compact stats bar (mobile only) */
    .pk-stats-compact{display:none;padding:.4rem .75rem;background:var(--surface,#fff);border-bottom:1.5px solid var(--border,#e2e8f0);font-size:.78rem;color:#475569;gap:.3rem;flex-wrap:wrap;align-items:center}
    .pk-stats-compact span{white-space:nowrap}
    .pk-stats-compact .sep{color:#cbd5e1}
    .pk-stats-compact b{color:var(--accent,#2563eb);font-weight:700}
    .pk-stats-compact .pool-val{color:#22c55e;font-weight:700}

    /* Mobile player cards */
    .pk-mobile-card{background:var(--surface,#fff);border:1.5px solid var(--border,#e2e8f0);border-radius:8px;margin-bottom:.5rem;overflow:hidden}
    .pk-mobile-card.elim{opacity:.5}
    .pk-mobile-card.cashed-out{opacity:.7}
    .pk-mobile-card.rsvp-no{opacity:.45}
    .pk-mobile-summary{display:flex;justify-content:space-between;align-items:center;padding:.65rem .8rem;cursor:pointer;-webkit-tap-highlight-color:transparent}
    .pk-mobile-summary:active{background:#f1f5f9}
    .pk-mobile-name{font-weight:600;font-size:.9rem}
    .pk-mobile-status{font-size:.7rem;font-weight:600;padding:.15rem .4rem;border-radius:4px;min-width:4.5rem;text-align:center;flex-shrink:0}
    .pk-mobile-expand{display:none;padding:.5rem .8rem;border-top:1px solid #f1f5f9;background:#f8fafc}
    .pk-mobile-expand.open{display:block}
    .pk-mobile-row{display:flex;align-items:center;justify-content:space-between;padding:.35rem 0;gap:.5rem;flex-wrap:wrap}
    .pk-mobile-row label{font-size:.75rem;color:#64748b;font-weight:600;min-width:70px}
    .pk-mobile-actions{display:flex;gap:.4rem;flex-wrap:wrap;padding-top:.4rem;border-top:1px solid #e2e8f0;margin-top:.3rem}
    .pk-mobile-actions button{padding:.35rem .7rem;border-radius:6px;font-size:.78rem;font-weight:600;cursor:pointer;border:1.5px solid var(--border,#e2e8f0);background:#fff}
    .pk-mobile-actions button:active{background:#e2e8f0}
    .pk-mobile-actions .danger{color:#ef4444;border-color:#fca5a5}
    .pk-bulk-bar{display:flex;align-items:center;gap:.5rem;padding:.5rem .75rem;background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:8px;margin-bottom:.5rem;flex-wrap:wrap;transition:background .15s,border-color .15s}
    .pk-bulk-bar.active{background:#eff6ff;border-color:#bfdbfe}
    .pk-bulk-bar:not(.active) button{opacity:.4;pointer-events:none}
    .pk-bulk-bar .pk-bulk-count{font-size:.82rem;font-weight:700;color:#2563eb;min-width:5rem}
    .pk-bulk-bar button{font-size:.75rem;padding:.3rem .65rem;border-radius:5px;border:1.5px solid #e2e8f0;background:#fff;font-weight:600;cursor:pointer}
    .pk-bulk-bar button:hover{background:#f1f5f9}
    .pk-bulk-bar .danger{color:#ef4444;border-color:#fca5a5}
    .pk-bulk-bar .primary{color:#fff;background:#2563eb;border-color:#2563eb}
    .pk-row-select{width:18px;height:18px;cursor:pointer;accent-color:#2563eb}
    .pk-view-seg{display:inline-flex;border-radius:6px;overflow:hidden;border:1.5px solid #e2e8f0}
    .pk-view-seg button{padding:.3rem .6rem;font-size:.78rem;font-weight:600;border:none;cursor:pointer;transition:background .12s,color .12s}
    .pk-view-seg .active{background:#2563eb;color:#fff}
    .pk-view-seg .inactive{background:#f1f5f9;color:#94a3b8}
    .walkin-autocomplete{position:relative}
    .walkin-dropdown{position:absolute;top:100%;left:0;right:0;background:#fff;border:1.5px solid #e2e8f0;border-top:none;border-radius:0 0 8px 8px;box-shadow:0 4px 12px rgba(0,0,0,.1);z-index:100;max-height:200px;overflow-y:auto;display:none}
    .walkin-dropdown.open{display:block}
    .walkin-dropdown-item{padding:.5rem .75rem;cursor:pointer;font-size:.85rem;color:#334155}
    .walkin-dropdown-item:hover,.walkin-dropdown-item.active{background:#eff6ff;color:#2563eb}
    .walkin-dropdown-item .walkin-hint{font-size:.7rem;color:#94a3b8;margin-left:.3rem}
    @media(max-width:768px){
        .pk-table-wrap{display:none}
        .pk-mobile-list{display:block;max-height:calc(100dvh - 210px);overflow-y:auto;-webkit-overflow-scrolling:touch}
        .pk-header{padding:.4rem .5rem;gap:.35rem}
        .pk-header h1{font-size:.85rem}
        .pk-header h1 a{font-size:.7rem}
        .pk-pool{font-size:1rem}
        .pk-pool small{font-size:.6rem}
        .pk-act-label{display:none}
        .pk-actions{gap:.25rem}
        .pk-actions button,.pk-actions a{padding:.3rem .45rem;font-size:1rem;min-width:0}
        .pk-badge{font-size:.6rem;padding:.1rem .3rem}
        .pk-stats{display:none}
        .pk-stats-compact{display:flex}
        .pk-sidebar{display:none}
        .pk-grid{padding:.5rem .75rem}
        .pk-toolbar{gap:.35rem}
        .pk-toolbar input[type=text]{width:100%;min-width:0}
        .pk-filter{margin-left:0}
        .pk-settings-panel{margin:.5rem .75rem}
    }
    @media(min-width:769px){
        .pk-mobile-list{display:none}
    }

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

    .pk-inline-summary{display:none}

    /* ── Mobile/tablet touch optimization ── */
    @media (max-width: 1024px) {
        .pk-sidebar{display:none}
        .pk-inline-summary{display:flex;gap:.6rem;flex-wrap:wrap;align-items:center;padding:.4rem .75rem;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;margin-bottom:.5rem;font-size:.8rem;color:#334155}
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
var CSRF = <?= json_encode($csrf, JSON_HEX_TAG) ?>;
var ALL_USERS = <?= json_encode($allUsernames, JSON_HEX_TAG) ?>;
var EVENT_ID = <?= $event_id ?>;
var SESSION = <?= $session ? json_encode($session, JSON_HEX_TAG) : 'null' ?>;
var PLAYERS = [];
var PAYOUTS = [];
var POOL = {};
var FILTER = 'all';
var VIEW_MODE = 'list';
var notesPlayerId = null;
var cashoutPlayerId = null;

function isCash() { return SESSION && SESSION.game_type === 'cash'; }
function isTourney() { return !SESSION || SESSION.game_type === 'tournament'; }

function formatMoney(cents) {
    var val = cents / 100;
    return '$' + (val % 1 === 0 ? val.toFixed(0) : val.toFixed(2));
}
function formatProfit(cents) {
    var val = Math.abs(cents) / 100;
    var str = val % 1 === 0 ? val.toFixed(0) : val.toFixed(2);
    if (cents > 0) return '+$' + str;
    if (cents < 0) return '-$' + str;
    return '$0';
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
    h += '<p>Configure the game for <strong>' + escHtml(<?= json_encode($event['title'], JSON_HEX_TAG) ?>) + '</strong></p>';
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
    h += '<h1>' + escHtml(<?= json_encode($event['title'], JSON_HEX_TAG) ?>) + ' <a href="/calendar.php"><span class="pk-act-label">Calendar</span></a></h1>';
    h += '<span class="pk-badge ' + typeClass + '">' + typeLabel + '</span>';
    h += '<div class="pk-actions">';
    h += '<button class="pk-btn-settings" onclick="toggleSettings()" title="Settings">&#9881;<span class="pk-act-label"> Settings</span></button>';
    if (isTourney()) {
        h += '<a class="pk-btn-settings" href="/timer.php?event_id=' + <?= (int)$event['id'] ?> + '" style="text-decoration:none" title="Timer">&#9201;<span class="pk-act-label"> Timer</span></a>';
    }
    h += '<a class="pk-btn-settings" href="/walkin_display.php?event_id=' + <?= (int)$event['id'] ?> + '" target="_blank" style="text-decoration:none" title="QR Registration">&#128241;<span class="pk-act-label"> QR</span></a>';
    if (isTourney()) {
        h += '<button class="pk-btn-settings" onclick="openDealSplit()" title="Payout Calculator">&#128176;<span class="pk-act-label"> Payout</span></button>';
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
    h += '<div class="pk-stats-compact" id="statsCompact">';
    h += renderStatsCompact();
    h += '</div>';

    // Grid
    h += '<div class="pk-grid">';

    // Left: player table
    h += '<div>';
    h += '<div class="pk-toolbar">';
    h += '<div class="walkin-autocomplete">';
    h += '<input type="text" id="walkinName" placeholder="Walk-in name..." autocomplete="off" oninput="walkinSuggest(this.value)" onkeydown="walkinKeydown(event)">';
    h += '<div class="walkin-dropdown" id="walkinDropdown"></div>';
    h += '</div>';
    h += '<button class="pk-btn-add" onclick="addWalkin()">+ Add</button>';
    h += '<div class="pk-toolbar-sep"></div>';
    h += '<div class="pk-filter">';
    h += '<button data-filter="all" class="' + (FILTER==='all'?'active':'') + '" onclick="setFilter(\'all\')">All</button>';
    h += '<button data-filter="rsvp_yes" class="' + (FILTER==='rsvp_yes'?'active':'') + '" onclick="setFilter(\'rsvp_yes\')">RSVP Yes</button>';
    if (isTourney()) {
        h += '<button data-filter="playing" class="' + (FILTER==='playing'?'active':'') + '" onclick="setFilter(\'playing\')">Playing</button>';
        h += '<button data-filter="eliminated" class="' + (FILTER==='eliminated'?'active':'') + '" onclick="setFilter(\'eliminated\')">Out</button>';
    } else {
        h += '<button data-filter="playing" class="' + (FILTER==='playing'?'active':'') + '" onclick="setFilter(\'playing\')">Active</button>';
        h += '<button data-filter="eliminated" class="' + (FILTER==='eliminated'?'active':'') + '" onclick="setFilter(\'eliminated\')">Cashed Out</button>';
    }
    h += '</div>';
    h += '<div class="pk-view-seg">';
    h += '<button class="' + (VIEW_MODE === 'list' ? 'active' : 'inactive') + '" onclick="if(VIEW_MODE!==\'list\'){toggleViewMode()}">&#9776; List</button>';
    h += '<button class="' + (VIEW_MODE === 'table' ? 'active' : 'inactive') + '" onclick="if(VIEW_MODE!==\'table\'){toggleViewMode()}">&#9638; Table</button>';
    h += '</div>';
    h += '<button class="pk-btn-view-toggle" onclick="balanceTables()">&#9878; Balance</button>';
    h += '<button class="pk-btn-view-toggle" onclick="addTable()">Tables: ' + (parseInt(SESSION.num_tables) || 1) + ' +</button>';
    h += '</div>';

    // Inline pool/payout summary for mobile/tablet (compact bar above player list)
    h += '<div class="pk-inline-summary">';
    if (isCash()) {
        h += '<span>In Play: <b>' + formatMoney(POOL.total_cash_in) + '</b></span>';
        h += '<span>On Table: <b>' + formatMoney(POOL.total_cash_in - POOL.total_cash_out) + '</b></span>';
    } else {
        h += '<span>Pool: <b style="color:#22c55e">' + formatMoney(POOL.pool_total) + '</b></span>';
        for (var pi = 0; pi < PAYOUTS.length && pi < 3; pi++) {
            var pct = parseFloat(PAYOUTS[pi].percentage);
            var amt = Math.round(POOL.pool_total * pct / 100);
            var pl = PAYOUTS[pi].place == 1 ? '1st' : PAYOUTS[pi].place == 2 ? '2nd' : PAYOUTS[pi].place == 3 ? '3rd' : PAYOUTS[pi].place + 'th';
            h += '<span>' + pl + ': <b>' + formatMoney(amt) + '</b></span>';
        }
    }
    h += '</div>';

    if (VIEW_MODE === 'table') {
        h += renderTableView();
    } else {
        h += '<div class="pk-bulk-bar" id="bulkBar">';
        h += '<span class="pk-bulk-count" id="bulkCount">0 selected</span>';
        h += '<button class="primary" onclick="bulkAction(\'toggle_checkin\')">Check In</button>';
        if (isTourney()) {
            h += '<button class="primary" onclick="bulkAction(\'toggle_buyin\')">Buy In</button>';
            h += '<button onclick="bulkAction(\'eliminate_player\')">Eliminate</button>';
        }
        h += '<button onclick="bulkAction(\'approve_player\')">Approve</button>';
        h += '<button class="danger" onclick="if(confirm(\'Remove selected players?\'))bulkAction(\'remove_player\')">Remove</button>';
        h += '<button onclick="clearSelection()">Clear</button>';
        h += '</div>';
        h += '<div class="pk-table-wrap"><table class="pk-table">';
        h += '<thead><tr>' + renderTableHeader() + '</tr></thead>';
        h += '<tbody id="playerBody">';
        h += renderPlayerRows();
        h += '</tbody></table></div>';
        h += '<div class="pk-mobile-list" id="mobileList">';
        h += renderMobileCards();
        h += '</div>';
    }
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
    var h = '<th style="width:2rem"><input type="checkbox" id="selectAll" class="pk-row-select" onchange="toggleSelectAll(this.checked)"></th>';
    h += '<th>#</th><th>Name</th><th>RSVP</th><th title="Checked In">&#10003;</th>';
    if (isTourney()) {
        h += '<th title="Buy-in">$</th>';
        if (parseInt(SESSION.rebuy_allowed)) h += '<th>Rebuys</th>';
        if (parseInt(SESSION.addon_allowed)) h += '<th>Add-ons</th>';
    } else {
        h += '<th>Total In</th><th>Cash Out</th><th>Profit</th>';
    }
    h += '<th>Table</th><th>Seat</th><th>Status</th><th>Actions</th>';
    return h;
}

function renderStatsCompact() {
    var h = '';
    var s = '<span class="sep">|</span>';
    h += '<span>Players: <b>' + POOL.total_players + '</b></span>' + s;
    h += '<span>In: <b>' + POOL.checked_in + '</b></span>' + s;
    if (isTourney()) {
        h += '<span>Playing: <b>' + POOL.still_playing + '</b></span>' + s;
        h += '<span>Out: <b>' + POOL.eliminated + '</b></span>' + s;
        h += '<span>Pool: <span class="pool-val">' + formatMoney(POOL.pool_total) + '</span></span>';
    } else {
        var active = POOL.bought_in - POOL.cashed_out;
        h += '<span>Active: <b>' + active + '</b></span>' + s;
        h += '<span>On Table: <span class="pool-val">' + formatMoney(POOL.total_cash_in - POOL.total_cash_out) + '</span></span>';
    }
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
        var isPending = (p.approval_status === 'pending');
        var dis = (isNo || isPending) ? ' disabled' : '';
        var rowClass = isPending ? 'pending-row' : (isElim ? 'elim' : (hasCashedOut ? 'cashed-out' : (isNo ? 'rsvp-no' : '')));
        h += '<tr class="' + rowClass + '" data-pid="' + p.id + '">';
        h += '<td><input type="checkbox" class="pk-row-select pk-player-cb" value="' + p.id + '" onchange="updateBulkBar()"></td>';
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

        if (isPending) {
            h += '<td colspan="' + (isTourney() ? (1 + 1 + (parseInt(SESSION.rebuy_allowed)?1:0) + (parseInt(SESSION.addon_allowed)?1:0)) : 4) + '" style="text-align:center;color:#d97706;font-size:.8rem;font-style:italic">Awaiting approval</td>';
        } else {
        h += '<td><input type="checkbox" class="pk-check" ' + (parseInt(p.checked_in) ? 'checked' : '') + dis + ' onchange="toggleCheckin(' + p.id + ')"></td>';

        if (isTourney()) {
            h += '<td><input type="checkbox" class="pk-check" ' + (parseInt(p.bought_in) ? 'checked' : '') + dis + ' onchange="toggleBuyin(' + p.id + ')"></td>';
            if (parseInt(SESSION.rebuy_allowed)) {
                h += '<td><div class="pk-counter"><button onclick="updateRebuys(' + p.id + ',-1)"' + dis + '>-</button><span>' + p.rebuys + '</span><button onclick="updateRebuys(' + p.id + ',1)"' + dis + '>+</button></div></td>';
            }
            if (parseInt(SESSION.addon_allowed)) {
                var aoAmt = parseInt(SESSION.addon_amount) || 0;
                var aoVal = parseInt(p.addons) || 0;
                var aoOn = aoVal > 0;
                h += '<td><div style="display:flex;align-items:center;gap:.25rem">'
                   + '<input type="checkbox" ' + (aoOn ? 'checked' : '') + dis + ' onchange="addonToggle(' + p.id + ', this.checked, ' + aoAmt + ')" style="width:15px;height:15px;cursor:pointer;accent-color:#7c3aed">'
                   + '<input type="text" id="aoField_' + p.id + '" value="' + (aoOn ? (aoVal/100).toFixed(2) : '') + '" inputmode="decimal" placeholder="$"' + dis + ' style="width:3.2rem;font-size:.78rem;text-align:center;border:1px solid #e2e8f0;border-radius:4px;padding:.15rem" onchange="addonSetAmt(' + p.id + ', this.value)">'
                   + '</div></td>';
            }
        } else {
            // Cash game: total in (with add money button), cash out, profit
            var cashIn = parseInt(p.cash_in) || 0;
            if (isNo) {
                h += '<td><span style="color:#94a3b8">' + formatMoney(cashIn) + '</span></td>';
                h += '<td><span style="color:#94a3b8">—</span></td>';
                h += '<td><span style="color:#94a3b8">—</span></td>';
            } else {
                h += '<td><div class="pk-counter"><button onclick="adjustMoney(' + p.id + ',-1)">-</button><input type="text" class="pk-cash-input" data-pid="' + p.id + '" value="' + (cashIn/100) + '" onchange="setCashIn(' + p.id + ',this.value)" onkeydown="if(event.key===\'Enter\'){event.preventDefault();setCashIn(' + p.id + ',this.value);focusNextCashInput(this);}" style="border:none;min-width:60px"><button onclick="adjustMoney(' + p.id + ',1)">+</button></div></td>';
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
        } // close isPending else

        h += '<td><input type="number" class="pk-tbl-input" value="' + (p.table_number || '') + '" min="1" max="' + SESSION.num_tables + '" onchange="setTable(' + p.id + ',this.value)" style="width:3rem"></td>';
        h += '<td style="text-align:center;color:#64748b;font-size:.8rem;font-weight:600">' + (p.seat_number || '—') + '</td>';

        // Status
        if (isPending) {
            h += '<td><span style="color:#d97706;font-weight:600;background:#fefce8;padding:.1rem .4rem;border-radius:4px;font-size:.75rem;border:1px solid #fde68a">Pending</span></td>';
        } else if (isTourney()) {
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
        if (isPending) {
            h += '<button class="pk-act-btn" style="background:#16a34a;color:#fff;font-weight:600" onclick="approvePlayer(' + p.id + ')">Approve</button>';
            h += '<button class="pk-act-btn danger" onclick="denyPlayer(' + p.id + ')">Deny</button>';
        } else {
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
                    if (!isElim && parseInt(p.bought_in)) {
                        h += '<button class="pk-act-btn" onclick="eliminatePlayer(' + p.id + ')">Eliminate</button>';
                    }
                    if (isElim) {
                        h += '<button class="pk-act-btn" onclick="uneliminate(' + p.id + ')">Undo Elim</button>';
                    }
                }
            }
            h += '<button class="pk-act-btn" onclick="openNotes(' + p.id + ')">Notes</button>';
            h += '<button class="pk-act-btn danger" onclick="if(confirm(\'Remove ' + escHtml(p.display_name) + ' from the event?\'))removePlayer(' + p.id + ')">Remove</button>';
        }
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

function renderMobileCards() {
    var h = '';
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
        var isElim = parseInt(p.eliminated);
        var hasCashedOut = isCash() && p.cash_out !== null && p.cash_out !== undefined;
        var isNo = p.rsvp === 'no';
        var cardClass = isElim ? 'elim' : (hasCashedOut ? 'cashed-out' : (isNo ? 'rsvp-no' : ''));

        // Status text and color
        var isPending = (p.approval_status === 'pending');
        var statusText = '\u2014', statusColor = '#94a3b8', statusBg = '#f1f5f9';
        if (isPending) {
            statusText = 'Pending'; statusColor = '#d97706'; statusBg = '#fefce8';
        } else if (isTourney()) {
            if (isElim) { statusText = '#' + (p.finish_position || '?'); statusColor = '#ef4444'; statusBg = '#fef2f2'; }
            else if (parseInt(p.bought_in)) { statusText = 'Playing'; statusColor = '#16a34a'; statusBg = '#f0fdf4'; }
            else if (parseInt(p.checked_in)) { statusText = 'Checked In'; statusColor = '#2563eb'; statusBg = '#eff6ff'; }
        } else {
            if (hasCashedOut) { statusText = 'Out'; statusColor = '#64748b'; statusBg = '#f1f5f9'; }
            else if (parseInt(p.bought_in)) { statusText = 'Playing'; statusColor = '#16a34a'; statusBg = '#f0fdf4'; }
            else if (parseInt(p.checked_in)) { statusText = 'Checked In'; statusColor = '#2563eb'; statusBg = '#eff6ff'; }
        }

        h += '<div class="pk-mobile-card ' + cardClass + '" data-pid="' + p.id + '">';
        h += '<div class="pk-mobile-summary" onclick="toggleMobileExpand(' + p.id + ')">';
        var seatInfo = p.seat_number ? 'T' + (p.table_number || '?') + ' #' + p.seat_number : '';
        h += '<span class="pk-mobile-name">' + escHtml(p.display_name) + (seatInfo ? ' <span style="color:#94a3b8;font-size:.72rem;font-weight:600">' + seatInfo + '</span>' : '') + '</span>';
        if (isPending) {
            // Approve/deny buttons directly on the summary row instead of a status badge
            h += '<span onclick="event.stopPropagation()" style="display:flex;align-items:center;gap:.35rem;margin-left:auto;flex-shrink:0">';
            h += '<button onclick="approvePlayer(' + p.id + ')" style="font-size:.72rem;padding:.25rem .6rem;border-radius:5px;border:0;background:#16a34a;color:#fff;font-weight:700;cursor:pointer">Approve</button>';
            h += '<button onclick="denyPlayer(' + p.id + ')" style="font-size:.72rem;padding:.25rem .6rem;border-radius:5px;border:0;background:#dc2626;color:#fff;font-weight:700;cursor:pointer">Deny</button>';
            h += '</span>';
        } else {
            // Check-in + buy-in checkboxes on the summary row (not inside expand)
            if (!isNo) {
                h += '<span onclick="event.stopPropagation()" style="display:flex;align-items:center;gap:.6rem;margin-left:auto;margin-right:.5rem;flex-shrink:0">';
                h += '<label style="display:flex;align-items:center;gap:.2rem;font-size:.65rem;color:#64748b;font-weight:700;cursor:pointer;padding:.25rem 0;-webkit-tap-highlight-color:transparent">'
                   + '<input type="checkbox" class="pk-check" ' + (parseInt(p.checked_in)?'checked':'') + ' onchange="toggleCheckin(' + p.id + ')" style="width:22px;height:22px;accent-color:#2563eb"> CI</label>';
                if (isTourney()) {
                    h += '<label style="display:flex;align-items:center;gap:.2rem;font-size:.65rem;color:#64748b;font-weight:700;cursor:pointer;padding:.25rem 0;-webkit-tap-highlight-color:transparent">'
                       + '<input type="checkbox" class="pk-check" ' + (parseInt(p.bought_in)?'checked':'') + ' onchange="toggleBuyin(' + p.id + ')" style="width:22px;height:22px;accent-color:#7c3aed"> BI</label>';
                }
                h += '</span>';
            }
            h += '<span class="pk-mobile-status" style="color:' + statusColor + ';background:' + statusBg + '">' + statusText + '</span>';
        }
        h += '</div>';

        // Expandable panel
        h += '<div class="pk-mobile-expand" id="mexp_' + p.id + '">';
        if (isPending) {
            h += '<div class="pk-mobile-row" style="justify-content:center;gap:.5rem;padding:.5rem 0">';
            h += '<button class="pk-act-btn" style="background:#16a34a;color:#fff;font-weight:600;padding:.4rem 1rem" onclick="approvePlayer(' + p.id + ')">Approve</button>';
            h += '<button class="pk-act-btn danger" style="padding:.4rem 1rem" onclick="denyPlayer(' + p.id + ')">Deny</button>';
            h += '</div>';
        } else if (!isNo) {
            if (isTourney()) {
                if (parseInt(SESSION.rebuy_allowed)) {
                    h += '<div class="pk-mobile-row">';
                    h += '<label>Rebuys</label><div class="pk-counter"><button onclick="updateRebuys(' + p.id + ',-1)">-</button><span>' + p.rebuys + '</span><button onclick="updateRebuys(' + p.id + ',1)">+</button></div>';
                    h += '</div>';
                }
                if (parseInt(SESSION.addon_allowed)) {
                    var mAoAmt = parseInt(SESSION.addon_amount) || 0;
                    var mAoVal = parseInt(p.addons) || 0;
                    var mAoOn = mAoVal > 0;
                    h += '<div class="pk-mobile-row">';
                    h += '<label>Add-on</label><div style="display:flex;align-items:center;gap:.3rem">'
                       + '<input type="checkbox" ' + (mAoOn ? 'checked' : '') + ' onchange="addonToggle(' + p.id + ', this.checked, ' + mAoAmt + ')" style="width:16px;height:16px;cursor:pointer;accent-color:#7c3aed">'
                       + '<input type="text" id="aoMField_' + p.id + '" value="' + (mAoOn ? (mAoVal/100).toFixed(2) : '') + '" inputmode="decimal" placeholder="$" style="width:3.5rem;font-size:.85rem;text-align:center;border:1px solid #e2e8f0;border-radius:4px;padding:.2rem" onchange="addonSetAmt(' + p.id + ', this.value)">'
                       + '</div>';
                    h += '</div>';
                }
            } else {
                var cashIn = parseInt(p.cash_in) || 0;
                h += '<div class="pk-mobile-row">';
                h += '<label>Cash In</label><div class="pk-counter"><button onclick="adjustMoney(' + p.id + ',-1)">-</button><input type="text" class="pk-cash-input" value="' + (cashIn/100) + '" onchange="setCashIn(' + p.id + ',this.value)" style="border:none;min-width:50px"><button onclick="adjustMoney(' + p.id + ',1)">+</button></div>';
                h += '</div>';
                if (hasCashedOut) {
                    var prof = parseInt(p.cash_out) - cashIn;
                    h += '<div class="pk-mobile-row"><label>Cash Out</label><span>' + formatMoney(parseInt(p.cash_out)) + '</span></div>';
                    h += '<div class="pk-mobile-row"><label>Profit</label><span class="' + (prof>0?'pk-profit-pos':prof<0?'pk-profit-neg':'pk-profit-zero') + '">' + formatProfit(prof) + '</span></div>';
                }
            }

            if (parseInt(SESSION.num_tables) > 1) {
                h += '<div class="pk-mobile-row">';
                h += '<label>Table</label><input type="number" class="pk-tbl-input" value="' + (p.table_number||'') + '" min="1" max="' + SESSION.num_tables + '" onchange="setTable(' + p.id + ',this.value)" style="width:50px">';
                h += '</div>';
            }

            // RSVP
            h += '<div class="pk-mobile-row">';
            h += '<label>RSVP</label><select onchange="updateRsvp(' + p.id + ',this.value)" style="font-size:.8rem;padding:.25rem .4rem;border-radius:4px;border:1px solid #e2e8f0">';
            var rsvp = p.rsvp || '';
            h += '<option value=""' + (rsvp===''?' selected':'') + '>\u2014</option>';
            h += '<option value="yes"' + (rsvp==='yes'?' selected':'') + '>Yes</option>';
            h += '<option value="no"' + (rsvp==='no'?' selected':'') + '>No</option>';
            h += '<option value="maybe"' + (rsvp==='maybe'?' selected':'') + '>Maybe</option>';
            h += '</select></div>';
        }

        // Action buttons
        h += '<div class="pk-mobile-actions">';
        if (!isNo) {
            if (isTourney()) {
                if (!isElim && parseInt(p.bought_in)) h += '<button onclick="eliminatePlayer(' + p.id + ')">Eliminate</button>';
                if (isElim) h += '<button onclick="uneliminate(' + p.id + ')">Undo Elim</button>';
            } else {
                if (parseInt(p.bought_in) && !hasCashedOut) h += '<button onclick="openCashout(' + p.id + ')">Cash Out</button>';
                if (hasCashedOut) h += '<button onclick="undoCashout(' + p.id + ')">Undo Cash Out</button>';
                if (!isElim && parseInt(p.bought_in)) h += '<button onclick="eliminatePlayer(' + p.id + ')">Eliminate</button>';
                if (isElim) h += '<button onclick="uneliminate(' + p.id + ')">Undo Elim</button>';
            }
        }
        h += '<button onclick="openNotes(' + p.id + ')">Notes</button>';
        h += '<button class="danger" onclick="if(confirm(\'Remove ' + escHtml(p.display_name) + '?\'))removePlayer(' + p.id + ')">Remove</button>';
        h += '</div>';

        h += '</div>'; // pk-mobile-expand
        h += '</div>'; // pk-mobile-card
    }
    if (filtered.length === 0) {
        h += '<div style="text-align:center;padding:2rem;color:#94a3b8">No players</div>';
    }
    return h;
}

function toggleMobileExpand(pid) {
    var el = document.getElementById('mexp_' + pid);
    if (el) el.classList.toggle('open');
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
    h += '<div><label>Seats per Table</label><input type="number" id="cfg_seats_per_table" value="' + (SESSION.seats_per_table || 8) + '" min="2" max="20"></div>';
    h += '<div><label>Auto-Assign Tables</label><select id="cfg_auto_assign"><option value="1"' + (parseInt(SESSION.auto_assign_tables) ? ' selected' : '') + '>Yes</option><option value="0"' + (!parseInt(SESSION.auto_assign_tables) ? ' selected' : '') + '>No</option></select></div>';
    h += '</div>';

    // Payout editor (tournament only)
    h += '<div class="pk-payout-editor" id="cfgPayoutSection" style="' + (isCash()?'display:none':'') + '">';
    h += '<h3 style="margin:.75rem 0 .5rem;font-size:.9rem">Payout Structure</h3>';
    h += '<div id="payoutRows">';
    for (var i = 0; i < PAYOUTS.length; i++) {
        h += payoutRowHtml(PAYOUTS[i].place, PAYOUTS[i].percentage);
    }
    h += '</div>';
    h += '<div style="display:flex;gap:.5rem;margin-top:.3rem;flex-wrap:wrap">';
    h += '<button onclick="addPayoutRow()">+ Add Place</button>';
    h += '<button onclick="autoSplitPayouts()">Auto Split</button>';
    h += '</div>';
    h += '<div id="payoutSum" style="margin-top:.3rem;font-size:.8rem;color:#64748b"></div>';
    h += '</div>';

    h += '<button class="pk-settings-save" onclick="saveSettings()">Save Settings</button>';
    h += '<div style="margin-top:1rem;padding-top:1rem;border-top:1.5px solid #e2e8f0;display:flex;gap:.5rem;flex-wrap:wrap">';
    if (SESSION.status !== 'finished') {
        h += '<button onclick="if(confirm(\'Mark this game as finished? This finalizes all stats and payouts.\'))changeStatus(\'finished\')" style="padding:.5rem 1rem;border-radius:6px;font-size:.85rem;font-weight:600;cursor:pointer;background:#16a34a;color:#fff;border:none">&#10003; Finish Game</button>';
    } else {
        h += '<span style="color:#16a34a;font-weight:600;font-size:.85rem">&#10003; Game Finished</span>';
        h += '<button onclick="if(confirm(\'Reopen this game?\'))changeStatus(\'active\')" style="padding:.5rem 1rem;border-radius:6px;font-size:.85rem;font-weight:600;cursor:pointer;background:#d97706;color:#fff;border:none">Reopen</button>';
    }
    h += '</div>';
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
    autoSplitPayouts();
}

function autoSplitPayouts() {
    var inputs = document.querySelectorAll('.payout-pct');
    var count = inputs.length;
    if (count === 0) return;
    // Standard weighted tournament payout structures
    var structures = {
        1: [100],
        2: [65, 35],
        3: [50, 30, 20],
        4: [40, 30, 20, 10],
        5: [35, 25, 20, 12, 8],
        6: [30, 22, 18, 13, 10, 7],
        7: [28, 20, 16, 13, 10, 8, 5],
        8: [25, 18, 14, 12, 10, 9, 7, 5],
        9: [24, 17, 13, 11, 10, 9, 7, 5, 4],
        10: [22, 16, 12, 10, 9, 8, 7, 6, 5, 5]
    };
    var pcts = structures[count];
    if (!pcts) {
        // For >10 places, give top 3 standard split then divide remainder
        pcts = [30, 20, 15];
        var remaining = 35;
        var extra = count - 3;
        for (var i = 0; i < extra; i++) {
            var share = Math.round((remaining / extra) * 10) / 10;
            pcts.push(share);
        }
    }
    for (var i = 0; i < count; i++) {
        inputs[i].value = (pcts[i] || 0).toFixed(1);
    }
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

function addonToggle(pid, checked, defaultAmt) {
    var p = PLAYERS.find(function(pl) { return parseInt(pl.id) === pid; });
    var current = p ? parseInt(p.addons) || 0 : 0;
    var target = checked ? defaultAmt : 0;
    var delta = target - current;
    var val = checked ? (target / 100).toFixed(2) : '';
    ['aoField_', 'aoMField_'].forEach(function(pfx) { var el = document.getElementById(pfx + pid); if (el) el.value = val; });
    if (delta !== 0) updateAddons(pid, delta);
}
function addonSetAmt(pid, val) {
    var cents = Math.round(parseFloat(val) * 100) || 0;
    if (cents < 0) cents = 0;
    var p = PLAYERS.find(function(pl) { return parseInt(pl.id) === pid; });
    var current = p ? parseInt(p.addons) || 0 : 0;
    var delta = cents - current;
    if (delta !== 0) updateAddons(pid, delta);
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

function toggleViewMode() {
    VIEW_MODE = VIEW_MODE === 'list' ? 'table' : 'list';
    renderDashboard();
}

function movePlayer(pid, newTable) {
    if (!newTable) return;
    postAction('move_player_table', { player_id: pid, new_table: newTable }, function(j) {
        PLAYERS = j.players;
        renderDashboard();
    });
}

function balanceTables() {
    var numTables = parseInt(SESSION.num_tables);
    // Group active players by table
    var byTable = {};
    for (var t = 1; t <= numTables; t++) byTable[t] = [];
    PLAYERS.forEach(function(p) {
        if (parseInt(p.removed) || parseInt(p.eliminated) || !parseInt(p.checked_in)) return;
        var tn = parseInt(p.table_number);
        if (tn >= 1 && tn <= numTables) byTable[tn].push(p);
    });

    // Build modal to select button player per table
    var html = '<div style="text-align:left;max-height:70vh;overflow-y:auto">';
    html += '<p style="margin:0 0 .75rem;color:#64748b;font-size:.85rem">Select the <strong>Button</strong> player at each table. The Button, Small Blind, and Big Blind will not be moved.</p>';
    for (var t = 1; t <= numTables; t++) {
        var players = byTable[t];
        if (players.length === 0) continue;
        html += '<div style="margin-bottom:.75rem">';
        html += '<label style="font-weight:700;font-size:.85rem;display:block;margin-bottom:.25rem">Table ' + t + ' — Button:</label>';
        html += '<select id="balance_btn_t' + t + '" style="width:100%;padding:.4rem .6rem;border:1.5px solid #e2e8f0;border-radius:6px;font-size:.85rem">';
        html += '<option value="">None (no protection)</option>';
        players.sort(function(a, b) { return (parseInt(a.seat_number) || 0) - (parseInt(b.seat_number) || 0); });
        for (var j = 0; j < players.length; j++) {
            var p = players[j];
            var seatLabel = p.seat_number ? ' (Seat ' + p.seat_number + ')' : '';
            html += '<option value="' + p.id + '">' + escHtml(p.display_name) + seatLabel + '</option>';
        }
        html += '</select></div>';
    }
    html += '</div>';

    // Show in a modal overlay
    var overlay = document.createElement('div');
    overlay.id = 'balanceModal';
    overlay.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.5);z-index:1000;display:flex;align-items:center;justify-content:center';
    overlay.innerHTML = '<div style="background:#fff;border-radius:10px;padding:1.5rem;max-width:400px;width:90%;box-shadow:0 8px 30px rgba(0,0,0,.2)">'
        + '<h3 style="margin:0 0 .75rem;font-size:1rem">Balance Tables</h3>'
        + html
        + '<div style="display:flex;gap:.5rem;margin-top:1rem;justify-content:flex-end">'
        + '<button onclick="document.getElementById(\'balanceModal\').remove()" style="padding:.4rem 1rem;border:1.5px solid #e2e8f0;border-radius:6px;background:#fff;cursor:pointer;font-size:.85rem">Cancel</button>'
        + '<button onclick="executeBalance()" style="padding:.4rem 1rem;border:none;border-radius:6px;background:#2563eb;color:#fff;cursor:pointer;font-weight:600;font-size:.85rem">Balance</button>'
        + '</div></div>';
    document.body.appendChild(overlay);
}

function executeBalance() {
    var numTables = parseInt(SESSION.num_tables);
    var buttonPlayers = {};
    for (var t = 1; t <= numTables; t++) {
        var sel = document.getElementById('balance_btn_t' + t);
        if (sel && sel.value) buttonPlayers[t] = parseInt(sel.value);
    }
    var modal = document.getElementById('balanceModal');
    if (modal) modal.remove();

    // Collect all protected player IDs (button + SB + BB per table)
    var protectedIds = [];
    for (var t in buttonPlayers) {
        var btnId = buttonPlayers[t];
        // Find button player's seat, then protect seat+1 (SB) and seat+2 (BB)
        var tablePlayers = PLAYERS.filter(function(p) {
            return parseInt(p.table_number) === parseInt(t) && !parseInt(p.removed) && !parseInt(p.eliminated) && parseInt(p.checked_in);
        }).sort(function(a, b) { return (parseInt(a.seat_number) || 0) - (parseInt(b.seat_number) || 0); });

        var btnIdx = -1;
        for (var i = 0; i < tablePlayers.length; i++) {
            if (parseInt(tablePlayers[i].id) === btnId) { btnIdx = i; break; }
        }
        if (btnIdx >= 0 && tablePlayers.length > 0) {
            var len = tablePlayers.length;
            protectedIds.push(parseInt(tablePlayers[btnIdx].id));
            if (len > 1) protectedIds.push(parseInt(tablePlayers[(btnIdx + 1) % len].id)); // SB
            if (len > 2) protectedIds.push(parseInt(tablePlayers[(btnIdx + 2) % len].id)); // BB
        }
    }

    postAction('rebalance_tables', { session_id: SESSION.id, protected_ids: JSON.stringify(protectedIds) }, function(j) {
        PLAYERS = j.players;
        if (j.moves && j.moves.length > 0) {
            var msg = j.moves.length + ' player(s) moved:\n';
            for (var i = 0; i < j.moves.length; i++) {
                var m = j.moves[i];
                msg += m.display_name + ': Table ' + (m.old_table || '?') + ' \u2192 ' + m.new_table + '\n';
            }
            alert(msg);
        } else {
            alert('Tables are already balanced.');
        }
        renderDashboard();
    });
}

function addTable() {
    var newCount = parseInt(SESSION.num_tables) + 1;
    postAction('update_config', { session_id: SESSION.id, num_tables: newCount }, function(j) {
        SESSION = j.session;
        POOL = j.pool;
        if (j.players) PLAYERS = j.players;
        renderDashboard();
    });
}

function breakUpTable(tableNum) {
    if (!confirm('Break up Table ' + tableNum + '? All players will be distributed to the other tables.')) return;
    postAction('break_up_table', { session_id: SESSION.id, table_number: tableNum }, function(j) {
        PLAYERS = j.players;
        SESSION = j.session;
        if (j.moves && j.moves.length > 0) {
            var msg = j.moves.length + ' player(s) moved:\n';
            for (var i = 0; i < j.moves.length; i++) {
                var m = j.moves[i];
                msg += m.display_name + ': Table ' + (m.old_table || '?') + ' \u2192 ' + m.new_table + '\n';
            }
            alert(msg);
        }
        renderDashboard();
    });
}

function renderTableView() {
    var numTables = parseInt(SESSION.num_tables);
    var tables = {};
    for (var t = 1; t <= numTables; t++) tables[t] = [];
    var unassigned = [];

    var activePlayers = PLAYERS.filter(function(p) { return !parseInt(p.removed); });
    // Apply current filter
    activePlayers = activePlayers.filter(function(p) {
        if (FILTER === 'rsvp_yes') return p.rsvp === 'yes';
        if (FILTER === 'playing') return parseInt(p.bought_in) && !parseInt(p.eliminated);
        if (FILTER === 'eliminated') return parseInt(p.eliminated);
        return true;
    });

    for (var i = 0; i < activePlayers.length; i++) {
        var p = activePlayers[i];
        var tn = parseInt(p.table_number);
        if (tn >= 1 && tn <= numTables) {
            tables[tn].push(p);
        } else {
            unassigned.push(p);
        }
    }

    var h = '<div class="pk-table-grid">';
    for (var t = 1; t <= numTables; t++) {
        var players = tables[t];
        var maxSeats = parseInt(SESSION.seats_per_table) || 9;
        h += '<div class="pk-table-card">';
        h += '<div class="pk-table-card-header"><h3>Table ' + t + ' <span>(' + players.length + '/' + maxSeats + ')</span></h3>'
           + (numTables > 1 ? '<button class="pk-act-btn" onclick="breakUpTable(' + t + ')" style="font-size:.7rem;color:#ef4444;flex-shrink:0" title="Break up this table and distribute players to other tables">Break Up</button>' : '')
           + '</div>';
        h += '<div class="pk-table-card-body">';
        players.sort(function(a, b) { return (parseInt(a.seat_number) || 99) - (parseInt(b.seat_number) || 99); });
        for (var j = 0; j < players.length; j++) {
            var p = players[j];
            var isElim = parseInt(p.eliminated);
            var seatTag = p.seat_number ? '<span style="color:#94a3b8;font-size:.72rem;font-weight:700;min-width:1.4rem;display:inline-block">' + p.seat_number + '</span> ' : '';
            h += '<div class="pk-tv-player' + (isElim ? ' elim' : '') + '">';
            h += '<span class="pk-tv-name">' + seatTag + escHtml(p.display_name) + '</span>';
            h += '<span class="pk-tv-actions">';
            if (!isElim) {
                h += '<button class="pk-act-btn" onclick="eliminatePlayer(' + p.id + ')" title="Eliminate" style="color:#ef4444;font-weight:700">&#10005;</button>';
                h += '<select class="pk-tv-move" onchange="movePlayer(' + p.id + ', this.value)">';
                h += '<option value="">Move\u2026</option>';
                for (var mt = 1; mt <= numTables; mt++) {
                    if (mt !== t) h += '<option value="' + mt + '">Table ' + mt + ' (' + tables[mt].length + ')</option>';
                }
                h += '</select>';
            } else {
                h += '<button class="pk-act-btn" onclick="uneliminate(' + p.id + ')" title="Undo eliminate">Undo</button>';
            }
            h += '</span>';
            h += '</div>';
        }
        if (players.length === 0) h += '<div style="color:#94a3b8;text-align:center;padding:1rem">No players</div>';
        h += '</div></div>';
    }

    if (unassigned.length > 0) {
        h += '<div class="pk-table-card pk-table-card-unassigned">';
        h += '<div class="pk-table-card-header" style="background:#fef9c3"><h3>Unassigned <span>(' + unassigned.length + ')</span></h3></div>';
        h += '<div class="pk-table-card-body">';
        for (var j = 0; j < unassigned.length; j++) {
            var p = unassigned[j];
            h += '<div class="pk-tv-player">';
            h += '<span class="pk-tv-name">' + escHtml(p.display_name) + '</span>';
            h += '<select class="pk-tv-move" onchange="movePlayer(' + p.id + ', this.value)">';
            h += '<option value="">Assign\u2026</option>';
            for (var mt = 1; mt <= numTables; mt++) h += '<option value="' + mt + '">Table ' + mt + ' (' + tables[mt].length + ')</option>';
            h += '</select>';
            h += '</div>';
        }
        h += '</div></div>';
    }

    h += '</div>';
    return h;
}

function eliminatePlayer(pid) {
    var player = PLAYERS.find(function(p) { return parseInt(p.id) === pid; });
    if (player && !parseInt(player.bought_in)) {
        alert('This player has not bought in yet. Buy them in before eliminating.');
        return;
    }
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
    var inp = document.getElementById('cashoutAmount');
    inp.value = (totalIn / 100);
    // Set max to money remaining on the table (add back this player's existing cashout if re-cashing)
    var oldCashout = (p && p.cash_out !== null && p.cash_out !== undefined) ? parseInt(p.cash_out) : 0;
    var remaining = (POOL.pool_total - POOL.total_cash_out + oldCashout);
    inp.max = (remaining / 100);
    document.getElementById('cashoutModal').classList.add('open');
    inp.focus();
    inp.select();
    inp.onkeydown = function(e) { if (e.key === 'Enter') saveCashout(); };
}

function closeCashout() {
    document.getElementById('cashoutModal').classList.remove('open');
    cashoutPlayerId = null;
}

function saveCashout() {
    if (!cashoutPlayerId) return;
    var amt = Math.round(parseFloat(document.getElementById('cashoutAmount').value || 0) * 100);
    var p = PLAYERS.find(function(p) { return parseInt(p.id) === cashoutPlayerId; });
    var oldCashout = (p && p.cash_out !== null && p.cash_out !== undefined) ? parseInt(p.cash_out) : 0;
    var remaining = POOL.pool_total - POOL.total_cash_out + oldCashout;
    if (amt > remaining) {
        alert('Cash-out ($' + (amt / 100) + ') exceeds money remaining on the table ($' + (remaining / 100) + ').');
        return;
    }
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

// ── Bulk select / actions ─────────────────────────────────
function toggleSelectAll(checked) {
    document.querySelectorAll('.pk-player-cb').forEach(function(cb) { cb.checked = checked; });
    updateBulkBar();
}

function updateBulkBar() {
    var selected = document.querySelectorAll('.pk-player-cb:checked');
    var bar = document.getElementById('bulkBar');
    var count = document.getElementById('bulkCount');
    if (!bar || !count) return;
    if (selected.length > 0) {
        bar.classList.add('active');
        count.textContent = selected.length + ' selected';
    } else {
        bar.classList.remove('active');
        count.textContent = '0 selected';
    }
    // Keep select-all in sync
    var all = document.querySelectorAll('.pk-player-cb');
    var selAll = document.getElementById('selectAll');
    if (selAll) selAll.checked = all.length > 0 && selected.length === all.length;
}

function clearSelection() {
    document.querySelectorAll('.pk-player-cb').forEach(function(cb) { cb.checked = false; });
    var selAll = document.getElementById('selectAll');
    if (selAll) selAll.checked = false;
    updateBulkBar();
}

function bulkAction(action) {
    var selected = Array.from(document.querySelectorAll('.pk-player-cb:checked')).map(function(cb) { return parseInt(cb.value); });
    if (selected.length === 0) return;

    var completed = 0;
    var total = selected.length;

    function processNext() {
        if (completed >= total) {
            // Refresh from server after all done
            loadSession();
            return;
        }
        var pid = selected[completed];
        var params = { player_id: pid };
        if (action === 'add_walkin') params.session_id = SESSION.id;
        if (action === 'eliminate_player') params.finish_position = 0;

        var fd = new FormData();
        fd.append('csrf_token', CSRF);
        fd.append('action', action);
        for (var k in params) fd.append(k, params[k]);

        fetch('/checkin_dl.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(j) {
                completed++;
                processNext();
            })
            .catch(function() {
                completed++;
                processNext();
            });
    }
    processNext();
}

var _walkinIdx = -1;

function walkinSuggest(val) {
    var dd = document.getElementById('walkinDropdown');
    if (!dd) return;
    val = val.trim().toLowerCase();
    if (val.length < 1) { dd.classList.remove('open'); dd.innerHTML = ''; _walkinIdx = -1; return; }

    // Exclude users already in the session
    var existing = PLAYERS.map(function(p) { return p.display_name.toLowerCase(); });
    var matches = ALL_USERS.filter(function(u) {
        return u.toLowerCase().indexOf(val) !== -1 && existing.indexOf(u.toLowerCase()) === -1;
    }).slice(0, 6);

    if (matches.length === 0) { dd.classList.remove('open'); dd.innerHTML = ''; _walkinIdx = -1; return; }

    dd.innerHTML = matches.map(function(u, i) {
        return '<div class="walkin-dropdown-item" onmousedown="walkinPick(\'' + escHtml(u) + '\')">' + escHtml(u) + '</div>';
    }).join('');
    dd.classList.add('open');
    _walkinIdx = -1;
}

function walkinPick(name) {
    var input = document.getElementById('walkinName');
    input.value = name;
    var dd = document.getElementById('walkinDropdown');
    if (dd) { dd.classList.remove('open'); dd.innerHTML = ''; }
    _walkinIdx = -1;
    addWalkin();
}

function walkinKeydown(e) {
    var dd = document.getElementById('walkinDropdown');
    if (!dd || !dd.classList.contains('open')) {
        if (e.key === 'Enter') { e.preventDefault(); addWalkin(); }
        return;
    }
    var items = dd.querySelectorAll('.walkin-dropdown-item');
    if (e.key === 'ArrowDown') {
        e.preventDefault();
        _walkinIdx = Math.min(_walkinIdx + 1, items.length - 1);
        items.forEach(function(el, i) { el.classList.toggle('active', i === _walkinIdx); });
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        _walkinIdx = Math.max(_walkinIdx - 1, 0);
        items.forEach(function(el, i) { el.classList.toggle('active', i === _walkinIdx); });
    } else if (e.key === 'Enter') {
        e.preventDefault();
        if (_walkinIdx >= 0 && items[_walkinIdx]) {
            walkinPick(items[_walkinIdx].textContent);
        } else if (items.length === 1) {
            // Auto-select the only match (handles case mismatch like "bryce" → "Bryce")
            walkinPick(items[0].textContent);
        } else {
            // Check for case-insensitive exact match in the dropdown
            var typed = document.getElementById('walkinName').value.trim().toLowerCase();
            var exactMatch = null;
            items.forEach(function(el) { if (el.textContent.toLowerCase() === typed) exactMatch = el.textContent; });
            if (exactMatch) { walkinPick(exactMatch); } else { addWalkin(); }
        }
    } else if (e.key === 'Escape') {
        dd.classList.remove('open');
        _walkinIdx = -1;
    }
}

// Close dropdown when clicking outside
document.addEventListener('click', function(e) {
    if (!e.target.closest('.walkin-autocomplete')) {
        var dd = document.getElementById('walkinDropdown');
        if (dd) { dd.classList.remove('open'); _walkinIdx = -1; }
    }
});

function addWalkin() {
    var name = document.getElementById('walkinName').value.trim();
    if (!name) { alert('Enter a name'); return; }
    var dd = document.getElementById('walkinDropdown');
    if (dd) { dd.classList.remove('open'); dd.innerHTML = ''; _walkinIdx = -1; }
    postAction('add_walkin', { session_id: SESSION.id, name: name }, function(j) {
        // Replace if already exists (re-activated), otherwise add
        var existing = PLAYERS.findIndex(function(p) { return parseInt(p.id) === parseInt(j.player.id); });
        if (existing >= 0) { PLAYERS[existing] = j.player; } else { PLAYERS.push(j.player); }
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

function approvePlayer(pid) {
    postAction('approve_player', { player_id: pid }, function(j) {
        PLAYERS = j.players;
        POOL = j.pool;
        refreshUI();
    });
}

function denyPlayer(pid) {
    if (!confirm('Deny this player?')) return;
    postAction('deny_player', { player_id: pid }, function(j) {
        PLAYERS = j.players;
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
        seats_per_table: parseInt(document.getElementById('cfg_seats_per_table').value || 9),
        auto_assign_tables: parseInt((document.getElementById('cfg_auto_assign') || {}).value || 1),
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
        PAYOUTS = j.payouts || PAYOUTS;
        if (j.players) PLAYERS = j.players;
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
    document.querySelectorAll('.pk-filter button').forEach(function(btn) {
        btn.classList.toggle('active', btn.getAttribute('data-filter') === f);
    });
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
    if (VIEW_MODE === 'table') {
        // Table view: re-render the table grid in place
        var grid = document.querySelector('.pk-table-grid');
        if (grid) {
            grid.outerHTML = renderTableView();
        }
    } else {
        var body = document.getElementById('playerBody');
        if (body) body.innerHTML = renderPlayerRows();
        // Save which mobile cards are expanded before re-render
        var expandedIds = [];
        document.querySelectorAll('.pk-mobile-expand.open').forEach(function(el) {
            var m = el.id.match(/^mexp_(\d+)$/);
            if (m) expandedIds.push(m[1]);
        });
        var mobileList = document.getElementById('mobileList');
        if (mobileList) mobileList.innerHTML = renderMobileCards();
        // Restore expanded state
        expandedIds.forEach(function(pid) {
            var el = document.getElementById('mexp_' + pid);
            if (el) el.classList.add('open');
        });
    }
    var stats = document.getElementById('statsRow');
    if (stats) stats.innerHTML = renderStats();
    var statsC = document.getElementById('statsCompact');
    if (statsC) statsC.innerHTML = renderStatsCompact();
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

function focusNextCashInput(el) {
    var inputs = Array.from(document.querySelectorAll('.pk-cash-input'));
    var idx = inputs.indexOf(el);
    if (idx >= 0 && idx < inputs.length - 1) {
        inputs[idx + 1].focus();
        inputs[idx + 1].select();
    }
}

function escHtml(s) {
    if (!s) return '';
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(s));
    return div.innerHTML;
}

// ─── INIT ──────────────────────────────────────────────
loadSession();

// Auto-refresh every 10 seconds
// Uses poll=1 to skip sync_invitees (prevents re-adding removed players)
// Pool stats update silently; player list refreshes only if count changes
setInterval(function() {
    if (!SESSION) return;
    fetch('/checkin_dl.php?action=get_session&event_id=' + EVENT_ID)
        .then(function(r) { return r.json(); })
        .then(function(j) {
            if (!j.ok || !j.session) return;
            POOL = j.pool;
            var poolEl = document.getElementById('poolTotal');
            if (poolEl) {
                if (SESSION.game_type === 'cash') {
                    poolEl.innerHTML = '<small>Money In Play</small>' + formatMoney(POOL.total_cash_in);
                } else {
                    poolEl.innerHTML = '<small>Prize Pool</small>' + formatMoney(POOL.pool_total);
                }
            }
            if (j.players.length !== PLAYERS.length) {
                SESSION = j.session;
                PLAYERS = j.players;
                PAYOUTS = j.payouts;
                refreshUI();
            }
        })
        .catch(function() {});
}, 10000);

// ─── DEAL SPLIT MODAL ────────────────────────────────────
function openDealSplit() {
    var remaining = PLAYERS.filter(function(p) { return !parseInt(p.eliminated) && parseInt(p.bought_in); });
    if (remaining.length < 2) { alert('Need at least 2 active players for a deal split.'); return; }

    var modal = document.getElementById('dealSplitModal');
    var body = document.getElementById('dealSplitBody');
    var poolTotal = POOL.pool_total;

    // Build chip entry form
    var h = '<div style="margin-bottom:1rem">';
    h += '<p style="font-size:.85rem;color:#64748b;margin-bottom:.75rem">Enter each remaining player\'s chip count, then choose a split method.</p>';
    h += '<div style="font-weight:600;margin-bottom:.5rem">Prize Pool: ' + formatMoney(poolTotal) + ' &mdash; ' + remaining.length + ' players remaining</div>';
    h += '</div>';

    h += '<table style="width:100%;border-collapse:collapse;font-size:.9rem;margin-bottom:1rem">';
    h += '<thead><tr style="border-bottom:2px solid #e2e8f0"><th style="text-align:left;padding:.4rem">Player</th><th style="text-align:right;padding:.4rem;width:120px">Chips</th><th style="text-align:right;padding:.4rem;width:100px">Payout</th></tr></thead>';
    h += '<tbody id="dealRows">';
    for (var i = 0; i < remaining.length; i++) {
        h += '<tr data-player-id="' + remaining[i].id + '" style="border-bottom:1px solid #f1f5f9">';
        h += '<td style="padding:.4rem">' + escHtml(remaining[i].display_name) + '</td>';
        h += '<td style="padding:.4rem"><input type="number" class="deal-chips" min="0" step="1" value="" placeholder="0" style="width:100%;padding:.3rem .5rem;border:1.5px solid #e2e8f0;border-radius:4px;text-align:right;font-size:.9rem" oninput="recalcDeal()"></td>';
        h += '<td style="padding:.4rem;text-align:right;font-weight:600" class="deal-payout">-</td>';
        h += '</tr>';
    }
    h += '</tbody></table>';

    h += '<div style="display:flex;gap:.5rem;margin-bottom:1rem;flex-wrap:wrap">';
    h += '<button class="btn btn-primary" onclick="calcDeal(\'icm\')" id="btnICM">ICM Split</button>';
    h += '<button class="btn btn-outline" onclick="calcDeal(\'standard\')">Standard Split</button>';
    h += '<button class="btn btn-outline" onclick="calcDeal(\'chip_chop\')">Chip Chop</button>';
    h += '</div>';

    h += '<div id="dealResult" style="display:none;background:#f0fdf4;border:1.5px solid #86efac;border-radius:8px;padding:1rem;margin-bottom:1rem"></div>';

    body.innerHTML = h;
    modal.style.display = 'flex';
}

function closeDealSplit() {
    document.getElementById('dealSplitModal').style.display = 'none';
}

function getChipInputs() {
    var inputs = document.querySelectorAll('.deal-chips');
    var chips = [];
    for (var i = 0; i < inputs.length; i++) {
        chips.push(parseInt(inputs[i].value) || 0);
    }
    return chips;
}

function recalcDeal() {
    // Clear payouts on chip change
    var cells = document.querySelectorAll('.deal-payout');
    for (var i = 0; i < cells.length; i++) cells[i].textContent = '-';
    document.getElementById('dealResult').style.display = 'none';
}

function calcDeal(method) {
    var chips = getChipInputs();
    var totalChips = 0;
    for (var i = 0; i < chips.length; i++) totalChips += chips[i];
    var poolTotal = POOL.pool_total;
    var numPlayers = chips.length;

    if (method !== 'standard' && totalChips === 0) {
        alert('Enter chip counts for all remaining players.');
        return;
    }

    var payouts = [];

    if (method === 'standard') {
        // Use current payout structure percentages
        // Sort players by chips (or original order if no chips)
        var indexed = chips.map(function(c, i) { return { idx: i, chips: c }; });
        indexed.sort(function(a, b) { return b.chips - a.chips; });
        for (var i = 0; i < numPlayers; i++) {
            var pct = (PAYOUTS[i] ? parseFloat(PAYOUTS[i].percentage) : 0);
            payouts[indexed[i].idx] = Math.round(poolTotal * pct / 100);
        }

    } else if (method === 'chip_chop') {
        // Simple: each player gets pool * (their chips / total chips)
        for (var i = 0; i < numPlayers; i++) {
            payouts[i] = Math.round(poolTotal * (chips[i] / totalChips));
        }

    } else if (method === 'icm') {
        // ICM calculation
        payouts = calcICM(chips, poolTotal, PAYOUTS);
    }

    // Display results
    var cells = document.querySelectorAll('.deal-payout');
    var resultHtml = '<div style="font-weight:700;margin-bottom:.5rem">Proposed Split (' + method.toUpperCase().replace('_', ' ') + ')</div>';
    var rows = document.querySelectorAll('#dealRows tr');
    var totalPayout = 0;
    for (var i = 0; i < numPlayers; i++) {
        var amt = payouts[i] || 0;
        totalPayout += amt;
        if (cells[i]) cells[i].textContent = formatMoney(amt);
        var name = rows[i] ? rows[i].querySelector('td').textContent : ('Player ' + (i+1));
        resultHtml += '<div style="display:flex;justify-content:space-between;padding:.2rem 0"><span>' + escHtml(name) + '</span><span style="font-weight:600;color:#22c55e">' + formatMoney(amt) + '</span></div>';
    }
    // Handle rounding remainder
    var diff = poolTotal - totalPayout;
    if (diff !== 0 && payouts.length > 0) {
        payouts[0] += diff;
        if (cells[0]) cells[0].textContent = formatMoney(payouts[0]);
    }
    resultHtml += '<div style="border-top:1px solid #86efac;margin-top:.4rem;padding-top:.4rem;font-weight:700;display:flex;justify-content:space-between"><span>Total</span><span>' + formatMoney(poolTotal) + '</span></div>';

    var resultEl = document.getElementById('dealResult');
    resultEl.innerHTML = resultHtml;
    resultEl.style.display = '';
}

// ICM (Independent Chip Model) calculation
// Uses the Malmuth-Harville method to compute equity for each player
function calcICM(chips, poolTotal, payoutStructure) {
    var n = chips.length;
    var totalChips = 0;
    for (var i = 0; i < n; i++) totalChips += chips[i];
    if (totalChips === 0) return chips.map(function() { return 0; });

    // Get payout amounts from structure
    var prizes = [];
    for (var i = 0; i < n; i++) {
        var pct = (payoutStructure[i] ? parseFloat(payoutStructure[i].percentage) : 0);
        prizes.push(poolTotal * pct / 100);
    }

    // Calculate ICM equity for each player
    var equity = new Array(n).fill(0);

    // Recursive probability calculation
    // prob(player i finishes in position p) using Malmuth-Harville
    function calcEquity(remaining, prizeIdx) {
        if (prizeIdx >= prizes.length || remaining.length === 0) return;
        var totalRemaining = 0;
        for (var i = 0; i < remaining.length; i++) totalRemaining += remaining[i].chips;
        if (totalRemaining === 0) return;

        for (var i = 0; i < remaining.length; i++) {
            var prob = remaining[i].chips / totalRemaining;
            equity[remaining[i].idx] += prob * prizes[prizeIdx];

            // Recurse with this player removed
            if (prizeIdx + 1 < prizes.length && remaining.length > 1) {
                var next = [];
                for (var j = 0; j < remaining.length; j++) {
                    if (j !== i) next.push(remaining[j]);
                }
                // Scale recursion by probability
                var savedEquity = equity.slice();
                calcEquity(next, prizeIdx + 1);
                // Weight the recursive result by this player's probability
                for (var j = 0; j < n; j++) {
                    var added = equity[j] - savedEquity[j];
                    equity[j] = savedEquity[j] + added * prob;
                }
            }
        }
    }

    var remaining = [];
    for (var i = 0; i < n; i++) remaining.push({ idx: i, chips: chips[i] });
    calcEquity(remaining, 0);

    return equity.map(function(e) { return Math.round(e); });
}
</script>

<!-- Deal Split Modal -->
<div id="dealSplitModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:200;align-items:center;justify-content:center;padding:1rem" onclick="if(event.target===this)closeDealSplit()">
    <div style="background:#fff;border-radius:12px;padding:1.5rem;width:100%;max-width:520px;max-height:85vh;overflow-y:auto;position:relative;box-shadow:0 8px 32px rgba(0,0,0,0.2)">
        <button onclick="closeDealSplit()" style="position:absolute;top:.75rem;right:.75rem;background:none;border:none;font-size:1.3rem;cursor:pointer;color:#64748b">&times;</button>
        <h2 style="font-size:1.1rem;font-weight:700;margin:0 0 1rem">Deal Split Calculator</h2>
        <div id="dealSplitBody"></div>
        <button class="btn" onclick="closeDealSplit()" style="width:100%;background:#f1f5f9;color:#475569;margin-top:.5rem">Close</button>
    </div>
</div>

</body>
</html>
