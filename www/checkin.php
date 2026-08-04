<?php
require_once __DIR__ . '/auth.php';

$current = require_login();
$db = get_db();
$isAdmin = $current['role'] === 'admin';

$event_id = (int)($_GET['event_id'] ?? 0);
if (!$event_id) { http_response_code(400); exit('Missing event_id'); }

// Verify event exists and user has access (creator, event-manager, league owner/manager, or site admin).
$evStmt = $db->prepare('SELECT * FROM events WHERE id = ?');
$evStmt->execute([$event_id]);
$event = $evStmt->fetch();
if (!$event) { http_response_code(404); exit('Event not found'); }
if (!can_manage_event($db, (int)$event_id, (int)$current['id'], $isAdmin)) {
    http_response_code(403); exit('Access denied');
}

$site_name = get_setting('site_name', 'Game Night');
$csrf = csrf_token();

// Walk-in autocomplete suggestions. Admins see every site username; non-admins see only
// usernames they would already have access to via the event editor's invite picker:
// the event's league roster (if any) plus their personal contacts.
if ($isAdmin) {
    $allUsernames = array_column(
        $db->query('SELECT username FROM users ORDER BY username')->fetchAll(),
        'username'
    );
} else {
    $allUsernames = [];
    $_seen = [];
    $_uid = (int)$current['id'];
    $_evLeague = (int)($event['league_id'] ?? 0);
    if ($_evLeague > 0) {
        $st = $db->prepare(
            'SELECT u.username FROM league_members lm
             JOIN users u ON u.id = lm.user_id
             WHERE lm.league_id = ?'
        );
        $st->execute([$_evLeague]);
        foreach ($st->fetchAll() as $r) {
            $key = strtolower($r['username']);
            if (!isset($_seen[$key])) { $_seen[$key] = true; $allUsernames[] = $r['username']; }
        }
    }
    $st = $db->prepare(
        'SELECT u.username FROM user_contacts c
         JOIN users u ON u.id = c.linked_user_id
         WHERE c.owner_user_id = ? AND c.linked_user_id IS NOT NULL'
    );
    $st->execute([$_uid]);
    foreach ($st->fetchAll() as $r) {
        $key = strtolower($r['username']);
        if (!isset($_seen[$key])) { $_seen[$key] = true; $allUsernames[] = $r['username']; }
    }
    sort($allUsernames, SORT_FLAG_CASE | SORT_STRING);
}

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
    <link rel="stylesheet" href="/style.css?v=<?= htmlspecialchars(APP_VERSION) ?>">
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
    .pk-help-btn{margin-left:auto;flex:0 0 auto;padding:.4rem .8rem;border-radius:6px;border:1.5px solid #2563eb;background:#eff6ff;color:#1d4ed8;font-weight:700;font-size:.8rem;cursor:pointer;line-height:1;min-height:auto}
    .pk-help-btn:hover{background:#dbeafe}
    .pk-help-content{text-align:left;max-height:70vh;overflow-y:auto}
    .pk-help-content h4{margin:.9rem 0 .25rem;font-size:.9rem;color:#0f172a}
    .pk-help-content h4:first-child{margin-top:0}
    .pk-help-content p{margin:0;font-size:.85rem;color:#475569;line-height:1.45}
    /* Shared "pill slider" segmented control — used by the player filter and the view switcher
       so the two read as matched grouped controls. A thumb slides under the active button. */
    .pk-seg{position:relative;display:inline-flex;border:1.5px solid #cbd5e1;border-radius:8px;background:#dde3ec;padding:3px;box-shadow:inset 0 1px 2px rgba(15,23,42,.14)}
    .pk-seg-thumb{position:absolute;top:3px;bottom:3px;left:0;width:0;border-radius:6px;background:var(--accent,#2563eb);box-shadow:0 1px 3px rgba(37,99,235,.5);z-index:0;transition:transform .2s cubic-bezier(.4,0,.2,1),width .2s cubic-bezier(.4,0,.2,1)}
    .pk-seg button{position:relative;z-index:1;border:none;background:transparent;cursor:pointer;padding:.34rem .75rem;font-size:.76rem;font-weight:600;color:#475569;white-space:nowrap;transition:color .15s}
    .pk-seg button:hover:not(.active){color:#1e293b}
    .pk-seg button.active{color:#fff}

    .pk-table-wrap{overflow-x:auto;border:1.5px solid var(--border,#e2e8f0);border-radius:8px;background:var(--surface,#fff)}
    .pk-table{width:100%;border-collapse:collapse;font-size:.85rem}
    .pk-table th{background:#f8fafc;padding:.5rem .6rem;text-align:left;font-size:.7rem;text-transform:uppercase;letter-spacing:.05em;color:#64748b;border-bottom:1.5px solid var(--border,#e2e8f0);white-space:nowrap;position:sticky;top:0;z-index:5}
    .pk-table td{padding:.4rem .6rem;border-bottom:1px solid #f1f5f9;vertical-align:middle}
    .pk-table tr:hover td{background:#f8fafc}
    .pk-table tr.elim td{opacity:.5;text-decoration:line-through}
    .pk-table tr.elim td:last-child{text-decoration:none;opacity:1}
    .pk-table tr.elim td:nth-last-child(2){text-decoration:none;opacity:1}
    .pk-table th.pk-sortable{cursor:pointer;user-select:none;white-space:nowrap}
    .pk-table th.pk-sortable:hover{background:rgba(0,0,0,.05)}
    .pk-table tr.winner td{background:#fffbeb}
    .pk-mobile-card.winner{background:#fffbeb;border-color:#fde68a}
    .pk-table tr.cashed-out td{opacity:.6}
    .pk-table tr.cashed-out td:nth-child(3){opacity:1}      /* name stays readable */
    .pk-table tr.cashed-out td:nth-child(4){opacity:1}      /* Cash In stays usable — add cash to re-enter */
    .pk-table tr.rsvp-no td{opacity:.45;text-decoration:line-through}
    .pk-table tr.rsvp-no td:nth-child(3){text-decoration:none;opacity:1}
    .pk-table tr.rsvp-no td:last-child{text-decoration:none;opacity:1}
    .pk-table .name-cell{font-weight:600;white-space:nowrap}
    .pk-table .walkin-badge{font-size:.95rem;margin-left:.3rem;vertical-align:middle;cursor:help;line-height:1}

    .pk-check{width:20px;height:20px;cursor:pointer;accent-color:var(--accent,#2563eb)}
    .pk-counter{display:inline-flex;align-items:center;gap:0;border:1.5px solid var(--border,#e2e8f0);border-radius:6px;overflow:hidden}
    .pk-counter button{width:26px;height:26px;border:none;background:#f8fafc;cursor:pointer;font-weight:700;font-size:.9rem;color:#64748b;display:flex;align-items:center;justify-content:center}
    .pk-counter button:hover{background:#e2e8f0}
    .pk-counter span{min-width:24px;text-align:center;font-weight:600;font-size:.85rem;padding:0 2px}
    .pk-tbl-input{width:42px;padding:.2rem .3rem;border:1.5px solid var(--border,#e2e8f0);border-radius:4px;text-align:center;font-size:.85rem}
    .pk-cash-input,.pk-co-input{width:70px;padding:.2rem .3rem;border:1.5px solid var(--border,#e2e8f0);border-radius:4px;text-align:center;font-size:.85rem;-moz-appearance:textfield;appearance:textfield}
    .pk-bust-btn{border:1px solid #fecaca;background:#fff;border-radius:4px;font-size:.9rem;line-height:1;padding:.15rem .3rem;cursor:pointer;margin-left:.2rem;flex:0 0 auto}
    .pk-bust-btn:hover{background:#fee2e2}
    .pk-cash-input::-webkit-outer-spin-button,.pk-cash-input::-webkit-inner-spin-button,.pk-co-input::-webkit-outer-spin-button,.pk-co-input::-webkit-inner-spin-button{-webkit-appearance:none;margin:0}

    .pk-act-btn{background:#f1f5f9;border:1px solid #e2e8f0;cursor:pointer;font-size:.75rem;font-weight:600;padding:.25rem .55rem;margin:.1rem .15rem .1rem 0;border-radius:5px;color:#475569;white-space:nowrap}
    .pk-act-btn:hover{background:#e2e8f0;color:#0f172a}
    .pk-act-btn.primary{background:#16a34a;border-color:#16a34a;color:#fff}
    .pk-act-btn.primary:hover{background:#15803d;color:#fff}
    .pk-act-btn.danger{background:#dc2626;border-color:#dc2626;color:#fff}
    .pk-act-btn.danger:hover{background:#b91c1c;color:#fff}
    .pk-cashout-cell{background:transparent;border:none;border-bottom:1px dashed #94a3b8;cursor:pointer;font-size:.85rem;font-weight:600;color:#0f172a;padding:.1rem .15rem}
    .pk-cashout-cell:hover{color:#2563eb;border-bottom-color:#2563eb}
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

    /* Full-screen Game Settings editor (timer-editor pattern: fixed header,
       tab strip, scrolling body; all panes stay mounted). */
    .pk-sv-overlay{position:fixed;inset:0;z-index:900;background:var(--surface,#fff);display:flex;flex-direction:column}
    .pk-sv-head{display:flex;justify-content:space-between;align-items:center;gap:.75rem;padding:.7rem 1.25rem;border-bottom:1.5px solid var(--border,#e2e8f0);flex-shrink:0}
    .pk-sv-title{font-size:1rem;font-weight:700;color:#1e293b}
    .pk-sv-title #svDirty{color:#f59e0b;font-size:.8rem;vertical-align:middle}
    .pk-sv-title #svSaved{color:#16a34a;font-size:.8rem;font-weight:600;margin-left:.4rem}
    .pk-sv-save{padding:.45rem 1.4rem;background:var(--accent,#2563eb);color:#fff;border:none;border-radius:6px;font-weight:600;font-size:.85rem;cursor:pointer}
    .pk-sv-close{padding:.45rem 1rem;background:transparent;color:#64748b;border:1.5px solid var(--border,#e2e8f0);border-radius:6px;font-weight:600;font-size:.85rem;cursor:pointer}
    .pk-sv-tabs{display:flex;gap:.25rem;padding:0 1.25rem;border-bottom:1.5px solid var(--border,#e2e8f0);flex-shrink:0}
    .pk-sv-tab{padding:.55rem .9rem;border:none;background:transparent;font-size:.85rem;font-weight:600;color:#64748b;cursor:pointer;border-bottom:2.5px solid transparent;margin-bottom:-1.5px}
    .pk-sv-tab.active{color:var(--accent,#2563eb);border-bottom-color:var(--accent,#2563eb)}
    .pk-sv-tab.disabled{opacity:.4;cursor:default}
    .pk-sv-badge{background:#fde68a;color:#92400e;border-radius:999px;font-size:.68rem;padding:.05rem .4rem;font-weight:700}
    .pk-sv-body{flex:1;overflow-y:auto;padding:1rem 1.25rem 2rem}
    .pk-sv-pane{display:none}
    .pk-sv-pane.active{display:block}
    @media (max-width:480px){.pk-subbox-body{grid-template-columns:1fr}}
    /* Left-aligned: fields take a compact fixed width and cluster left instead
       of stretching across the panel. */
    .pk-settings-grid{display:flex;flex-wrap:wrap;gap:.75rem}
    .pk-settings-grid>div{flex:0 1 190px;min-width:150px}
    .pk-settings-grid label{font-size:.8rem;font-weight:600;color:#475569;display:block;margin-bottom:.2rem}
    .pk-settings-grid input,.pk-settings-grid select{width:100%;padding:.4rem .6rem;border:1.5px solid var(--border,#e2e8f0);border-radius:6px;font-size:.85rem}
    .pk-payout-editor{margin-top:.75rem}
    .pk-payout-editor .row{display:flex;gap:.5rem;align-items:center;margin-bottom:.4rem}
    .pk-payout-editor input{width:70px;padding:.3rem .5rem;border:1.5px solid var(--border,#e2e8f0);border-radius:4px;font-size:.85rem;text-align:center}
    .pk-payout-editor button{font-size:.75rem;padding:.3rem .6rem;border-radius:4px;cursor:pointer;border:1.5px solid var(--border,#e2e8f0);background:#f8fafc}
    .pk-settings-save{margin-top:.75rem;padding:.5rem 1.5rem;background:var(--accent,#2563eb);color:#fff;border:none;border-radius:6px;font-weight:600;font-size:.85rem;cursor:pointer}
    .pk-settings-save:hover{opacity:.9}

    /* Settings sections + opt-in reward toggles (progressive disclosure) */
    .pk-cfg-section{margin-top:1rem;padding-top:.75rem;border-top:1px solid var(--border,#e2e8f0)}
    .pk-cfg-section:first-child{margin-top:0;padding-top:0;border-top:none}
    .pk-cfg-title{font-size:.72rem;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:#94a3b8;margin:0 0 .5rem}
    .pk-reward-chips{display:flex;gap:.5rem;flex-wrap:wrap}
    .pk-reward-chip{padding:.4rem .8rem;border-radius:999px;border:1.5px solid var(--border,#e2e8f0);background:transparent;font-size:.8rem;font-weight:600;color:#64748b;cursor:pointer}
    .pk-reward-chip.on{background:#eff6ff;border-color:#93c5fd;color:#1d4ed8}
    .pk-reward-body{display:none;margin-top:.75rem}
    .pk-reward-body.on{display:block}
    .pk-bounty-hint{font-size:.75rem;color:#0e7490;margin-top:.35rem}
    /* Rebuys / Add-ons sub-boxes: the Yes/No select is the group header and
       gates its own fields */
    .pk-subgrids{display:flex;gap:.75rem;flex-wrap:wrap}
    .pk-subbox{flex:0 1 320px;min-width:230px;border:1.5px solid var(--border,#e2e8f0);border-radius:8px;padding:.6rem .75rem}
    .pk-subbox-head{display:flex;justify-content:space-between;align-items:center;gap:.5rem}
    .pk-subbox-head span{font-size:.8rem;font-weight:700;color:#475569}
    .pk-subbox-check{display:flex;align-items:center;gap:.35rem;font-size:.8rem;font-weight:600;color:#475569;cursor:pointer;user-select:none}
    .pk-subbox-check input{width:1rem;height:1rem;accent-color:var(--accent,#2563eb);cursor:pointer}
    .pk-subbox-body{display:none;grid-template-columns:1fr 1fr;gap:.6rem;margin-top:.6rem}
    .pk-subbox-body.on{display:grid}
    .pk-subbox-body label{font-size:.8rem;font-weight:600;color:#475569;display:block;margin-bottom:.2rem}
    .pk-subbox-body input{width:100%;padding:.4rem .6rem;border:1.5px solid var(--border,#e2e8f0);border-radius:6px;font-size:.85rem}

    /* $ prefix inside dollar inputs — typing "1" when you meant "$20" is the
       kind of slip a visible unit prevents. */
    .pk-money-wrap{position:relative;display:block}
    .pk-money-wrap::before{content:'$';position:absolute;left:.55rem;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:.85rem;font-weight:700;pointer-events:none}
    .pk-money-wrap input{padding-left:1.35rem;width:100%;box-sizing:border-box}

    /* Payout editor reward columns hide until their feature chip is on
       (ticket targets the wrapper so its $ prefix hides too) */
    #cfgPayoutSection .payout-pts, #cfgPayoutSection .col-pts,
    #cfgPayoutSection .payout-ticket-wrap, #cfgPayoutSection .col-ticket,
    #cfgPayoutSection .payout-label, #cfgPayoutSection .col-label{display:none}
    #cfgPayoutSection.show-pts .payout-pts, #cfgPayoutSection.show-pts .col-pts{display:block}
    #cfgPayoutSection.show-ticket .payout-ticket-wrap, #cfgPayoutSection.show-ticket .col-ticket{display:block}
    #cfgPayoutSection.show-label .payout-label, #cfgPayoutSection.show-label .col-label{display:block}

    .pk-btn-view-toggle{background:transparent;color:var(--accent,#2563eb);border:1.5px solid var(--border,#e2e8f0);padding:.4rem .8rem;border-radius:6px;font-size:.8rem;font-weight:600;cursor:pointer}
    .pk-btn-view-toggle:hover{background:#f1f5f9}
    .pk-btn-green{background:#16a34a;color:#fff;border:1.5px solid #16a34a;padding:.4rem .8rem;border-radius:6px;font-size:.8rem;font-weight:600;cursor:pointer}
    .pk-btn-green:hover{background:#15803d}
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
    .pk-mobile-actions .primary{background:#16a34a;border-color:#16a34a;color:#fff}
    .pk-mobile-actions .danger{background:#dc2626;border-color:#dc2626;color:#fff}
    .pk-bulk-bar{display:flex;align-items:center;gap:.5rem;padding:.5rem .75rem;background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:8px;margin-bottom:.5rem;flex-wrap:wrap;transition:background .15s,border-color .15s}
    .pk-bulk-bar.active{background:#eff6ff;border-color:#bfdbfe}
    .pk-bulk-bar:not(.active) button{opacity:.4;pointer-events:none}
    .pk-bulk-bar .pk-bulk-count{font-size:.82rem;font-weight:700;color:#2563eb;min-width:5rem}
    .pk-bulk-bar button{font-size:.75rem;padding:.3rem .65rem;border-radius:5px;border:1.5px solid #e2e8f0;background:#fff;font-weight:600;cursor:pointer}
    .pk-bulk-bar button:hover{background:#f1f5f9}
    .pk-bulk-bar .danger{color:#ef4444;border-color:#fca5a5}
    .pk-bulk-bar .primary{color:#fff;background:#2563eb;border-color:#2563eb}
    .pk-row-select{width:18px;height:18px;cursor:pointer;accent-color:#2563eb}
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

    /* ── Activity log (in-view panel) ── */
    .pk-log-sub{font-size:.8rem;color:#64748b;margin:0 0 .6rem}
    .pk-log-list{border:1px solid var(--border,#e2e8f0);border-radius:8px;background:var(--surface,#fff);max-height:65vh;overflow:auto}
    .pk-log-empty{padding:1.25rem;text-align:center;color:#94a3b8;font-size:.9rem}
    .pk-log-row{display:flex;align-items:baseline;gap:.6rem;padding:.5rem .75rem;border-bottom:1px solid #f1f5f9;font-size:.85rem}
    .pk-log-row:last-child{border-bottom:none}
    .pk-log-time{flex:0 0 auto;color:#94a3b8;font-size:.72rem;font-variant-numeric:tabular-nums;white-space:nowrap;min-width:62px}
    .pk-log-tag{flex:0 0 auto;font-size:.62rem;font-weight:700;text-transform:uppercase;letter-spacing:.03em;padding:.12rem .4rem;border-radius:4px;white-space:nowrap}
    .pk-log-text{flex:1 1 auto;color:#334155;line-height:1.35}
    .pk-log-text b{color:#0f172a}
    .pk-log-by{color:#94a3b8;font-size:.72rem;white-space:nowrap}
    .pk-log-tag.t-buyin,.pk-log-tag.t-cashin{background:#dcfce7;color:#166534}
    .pk-log-tag.t-rebuy,.pk-log-tag.t-addon{background:#dbeafe;color:#1e40af}
    .pk-log-tag.t-cashout{background:#fef9c3;color:#854d0e}
    .pk-log-tag.t-add,.pk-log-tag.t-approve{background:#f1f5f9;color:#475569}
    .pk-log-tag.t-eliminate{background:#fee2e2;color:#991b1b}
    .pk-log-tag.t-uneliminate{background:#ede9fe;color:#5b21b6}
    .pk-log-tag.t-remove,.pk-log-tag.t-unbuyin{background:#f1f5f9;color:#64748b}
    .pk-log-tag.t-void{background:#fee2e2;color:#991b1b}

    /* ── Per-player ledger ── */
    .pk-ledger-btn{flex:0 0 auto;border:none;background:transparent;cursor:pointer;font-size:1rem;line-height:1;padding:.1rem .25rem;border-radius:4px;color:#64748b}
    .pk-ledger-btn:hover{background:#e2e8f0;color:#334155}
    .pk-ledger-list{max-height:60vh;overflow:auto;border:1px solid var(--border,#e2e8f0);border-radius:8px;margin-top:.25rem}
    .pk-ledger-empty{padding:1.1rem;text-align:center;color:#94a3b8;font-size:.88rem}
    .pk-ledger-row{display:flex;align-items:center;gap:.6rem;padding:.5rem .75rem;border-bottom:1px solid #f1f5f9;font-size:.85rem}
    .pk-ledger-row:last-child{border-bottom:none}
    .pk-ledger-row.voided{opacity:.6}
    .pk-ledger-row.voided .pk-ledger-detail{text-decoration:line-through}
    .pk-ledger-time{flex:0 0 auto;color:#94a3b8;font-size:.72rem;min-width:62px;font-variant-numeric:tabular-nums}
    .pk-ledger-amt{flex:0 0 auto;font-weight:700;font-variant-numeric:tabular-nums}
    .pk-ledger-amt.pos{color:#166534}.pk-ledger-amt.neg{color:#b91c1c}
    .pk-ledger-detail{flex:1 1 auto;color:#334155}
    .pk-ledger-detail small{color:#94a3b8;font-weight:400}
    .pk-ledger-edit{flex:0 0 auto;border:1px solid #93c5fd;background:#fff;color:#2563eb;border-radius:5px;font-size:.72rem;font-weight:600;padding:.2rem .5rem;cursor:pointer}
    .pk-ledger-edit:hover{background:#eff6ff}
    .pk-ledger-clear{flex:0 0 auto;border:1px solid #fca5a5;background:#fff;color:#dc2626;border-radius:5px;font-size:.72rem;font-weight:600;padding:.2rem .5rem;cursor:pointer}
    .pk-ledger-clear:hover{background:#fee2e2}
    .pk-ledger-void-tag{flex:0 0 auto;font-size:.66rem;font-weight:700;text-transform:uppercase;color:#991b1b;background:#fee2e2;border-radius:4px;padding:.1rem .4rem}

    /* ── Tap-friendly help bubble (? marker, hover OR focus/tap) ── */
    .pk-tip{display:inline-flex;align-items:center;justify-content:center;width:14px;height:14px;border-radius:50%;background:#cbd5e1;color:#fff;font-size:.62rem;font-weight:700;cursor:help;margin-left:.25rem;position:relative;vertical-align:middle;flex:0 0 auto}
    .pk-tip:hover,.pk-tip:focus{background:#2563eb;outline:none}
    .pk-tip::after{content:attr(data-tip);position:absolute;top:calc(100% + 6px);left:50%;transform:translateX(-50%);background:#0f172a;color:#fff;font-size:.72rem;font-weight:400;line-height:1.35;letter-spacing:0;text-transform:none;white-space:normal;width:200px;max-width:46vw;padding:.5rem .65rem;border-radius:6px;box-shadow:0 4px 14px rgba(0,0,0,.2);opacity:0;visibility:hidden;transition:opacity .12s;z-index:250;pointer-events:none}
    .pk-tip:hover::after,.pk-tip:focus::after{opacity:1;visibility:visible}

    /* ── Cash box reconciliation ── */
    .pk-cashbox-link{display:block;width:100%;margin-top:.7rem;background:#f8fafc;border:1px solid var(--border,#e2e8f0);border-radius:6px;padding:.45rem;font-size:.8rem;font-weight:600;color:#475569;cursor:pointer}
    .pk-cashbox-link:hover{background:#f1f5f9;color:#1e293b}
    .pk-cb-grid{display:flex;flex-direction:column;margin:.5rem 0}
    .pk-cb-row{display:flex;justify-content:space-between;align-items:center;padding:.5rem .15rem;font-size:.9rem;color:#334155;border-bottom:1px solid #f1f5f9}
    .pk-cb-row > span:last-child{font-weight:600;font-variant-numeric:tabular-nums}
    .pk-cb-input-row label{color:#334155;font-size:.9rem}
    .pk-cb-money{display:inline-flex;align-items:center;gap:.15rem;font-weight:600}
    .pk-cb-money input{width:92px;padding:.3rem .45rem;border:1.5px solid var(--border,#e2e8f0);border-radius:6px;text-align:right;font-size:.9rem;-moz-appearance:textfield;appearance:textfield}
    .pk-cb-money input::-webkit-outer-spin-button,.pk-cb-money input::-webkit-inner-spin-button{-webkit-appearance:none;margin:0}
    .pk-cb-sub{color:#64748b}
    .pk-cb-total{border-top:2px solid #e2e8f0;border-bottom:none;margin-top:.15rem;padding-top:.6rem;font-size:1rem}
    .pk-cb-os{font-weight:700}
    .pk-cb-os.even{color:#16a34a}
    .pk-cb-os.over{color:#b45309}
    .pk-cb-os.short{color:#dc2626}
    .pk-cb-tipbtn{margin-top:.6rem;width:100%;background:#fffbeb;border:1px solid #fde68a;color:#92400e;border-radius:6px;padding:.45rem;font-size:.82rem;font-weight:600;cursor:pointer}
    .pk-cb-tipbtn:hover{background:#fef3c7}

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
        .pk-seg button { min-height:40px;font-size:.85rem;padding:.4rem .7rem; }
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
<!-- Full-screen Game Settings editor lives OUTSIDE #app so dashboard
     re-renders and the 10s poll can never clobber an open editor. -->
<div id="settingsRoot"></div>

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

<!-- Save payout structure modal (opened from the settings editor, so it sits
     above the z-900 full-screen overlay) -->
<!-- League jackpot hit modal (funds display + per-recipient split) -->
<div class="pk-modal-overlay" id="jackpotModal" style="z-index:1000">
    <div class="pk-modal">
        <h3>💎 League Jackpot</h3>
        <div id="jpBalances" style="font-size:.9rem;color:#334155;margin-bottom:.75rem"></div>
        <label style="font-size:.85rem;font-weight:600;color:#475569;display:block;margin-bottom:.3rem">What hit?</label>
        <select id="jpType" style="width:100%;padding:.5rem;border:1.5px solid var(--border,#e2e8f0);border-radius:6px;font-size:1rem;box-sizing:border-box;margin-bottom:.6rem">
            <option value="badbeat">🃏 Bad Beat</option>
            <option value="royal">👑 Royal Flush</option>
            <option value="other">💎 Other jackpot</option>
        </select>
        <label style="font-size:.85rem;font-weight:600;color:#475569;display:block;margin-bottom:.3rem">Paid to</label>
        <div id="jpRecipients"></div>
        <button type="button" onclick="addJackpotRecipient()" style="font-size:.78rem;padding:.3rem .6rem;border-radius:4px;cursor:pointer;border:1.5px solid var(--border,#e2e8f0);background:#f8fafc;margin-top:.2rem">+ Add recipient</button>

        <div style="margin-top:.9rem;padding-top:.7rem;border-top:1.5px solid #e2e8f0">
            <div style="display:flex;justify-content:space-between;align-items:center">
                <span style="font-size:.85rem;font-weight:600;color:#475569">Fund history</span>
                <button type="button" onclick="jpToggleAdjust()" style="font-size:.72rem;padding:.25rem .55rem;border-radius:4px;cursor:pointer;border:1.5px solid var(--border,#e2e8f0);background:#f8fafc">± Adjust fund</button>
            </div>
            <div id="jpAdjustRow" style="display:none;margin-top:.5rem;gap:.4rem;align-items:center">
                <span class="pk-money-wrap" style="flex:0 0 90px"><input type="number" id="jpAdjAmount" step="0.01" placeholder="-5 or 5"></span>
                <input type="text" id="jpAdjNote" maxlength="120" placeholder="reason…" style="flex:1;padding:.45rem .5rem;border:1.5px solid var(--border,#e2e8f0);border-radius:6px;font-size:.85rem">
                <button type="button" class="pk-save" onclick="confirmJackpotAdjust()" style="padding:.4rem .7rem;border-radius:6px;border:none;background:#2563eb;color:#fff;font-size:.8rem;font-weight:600;cursor:pointer">Apply</button>
            </div>
            <div id="jpHistory" style="margin-top:.5rem;max-height:180px;overflow-y:auto;font-size:.78rem;color:#334155"></div>
        </div>

        <div class="pk-modal-actions">
            <button onclick="closeJackpotModal()">Cancel</button>
            <button class="pk-save" onclick="confirmJackpotHit()">Record Hit</button>
        </div>
    </div>
</div>

<!-- No click-outside dismiss: a mis-click must not eat a half-typed name. -->
<div class="pk-modal-overlay" id="saveStructModal" style="z-index:1000">
    <div class="pk-modal">
        <h3>Save Payout Structure</h3>
        <label style="font-size:.85rem;font-weight:600;color:#475569;display:block;margin-bottom:.3rem">Name</label>
        <input type="text" id="ssName" maxlength="60" placeholder="e.g. Friday night 4-way" style="width:100%;padding:.5rem;border:1.5px solid var(--border,#e2e8f0);border-radius:6px;font-size:1rem;box-sizing:border-box">
        <label style="font-size:.85rem;font-weight:600;color:#475569;display:block;margin:.75rem 0 .3rem">Save to</label>
        <select id="ssScope" style="width:100%;padding:.5rem;border:1.5px solid var(--border,#e2e8f0);border-radius:6px;font-size:1rem;box-sizing:border-box"></select>
        <div class="pk-modal-actions">
            <button onclick="closeSaveStruct()">Cancel</button>
            <button class="pk-save" onclick="confirmSaveStruct()">Save</button>
        </div>
    </div>
</div>

<!-- Cash-in adjust modal -->
<div class="pk-modal-overlay" id="cashAdjustModal" onclick="if(event.target===this)closeCashAdjust()">
    <div class="pk-modal">
        <h3 id="cashAdjustTitle">Add Money</h3>
        <label style="font-size:.85rem;font-weight:600;color:#475569;display:block;margin-bottom:.3rem">Amount ($)</label>
        <input type="number" id="cashAdjustAmount" step="0.01" min="0" style="width:100%;padding:.5rem;border:1.5px solid var(--border,#e2e8f0);border-radius:6px;font-size:1rem;box-sizing:border-box">
        <div class="pk-modal-actions">
            <button onclick="closeCashAdjust()">Cancel</button>
            <button class="pk-save" id="cashAdjustOk" onclick="applyCashAdjust()">Add</button>
        </div>
    </div>
</div>

<!-- Ledger modal: per-player money history + corrections -->
<div class="pk-modal-overlay" id="ledgerModal" onclick="if(event.target===this)closeLedger()">
    <div class="pk-modal pk-log-modal">
        <h3 id="ledgerTitle">Ledger</h3>
        <div class="pk-log-sub">Every buy-in, add-on and cash-out for this player. Tap <b>Edit</b> to fix a wrong amount in place, or <b>Clear</b> to reverse an entry.</div>
        <div class="pk-ledger-list" id="ledgerList"><div class="pk-ledger-empty">Loading&hellip;</div></div>
        <div class="pk-modal-actions">
            <button class="pk-save" onclick="closeLedger()">Close</button>
        </div>
    </div>
</div>

<!-- Cash box reconciliation modal (cash games) -->
<div class="pk-modal-overlay" id="cashBoxModal" onclick="if(event.target===this)closeCashBox()">
    <div class="pk-modal">
        <h3>&#129534; Cash Box</h3>
        <div class="pk-log-sub">Record tips and square the cash box at the end of the game.</div>
        <div class="pk-cb-grid">
            <div class="pk-cb-row"><span>Total bought in</span><span id="cbCashIn">$0</span></div>
            <div class="pk-cb-row"><span>Total cashed out</span><span id="cbCashOut">$0</span></div>
            <div class="pk-cb-row"><span>Still on table (owed)</span><span id="cbOnTable">$0</span></div>
            <div class="pk-cb-row pk-cb-input-row"><label for="cbTips">Tips (host)</label><span class="pk-cb-money">$<input type="number" inputmode="decimal" step="0.01" min="0" id="cbTips" oninput="cashBoxRecompute()" placeholder="0.00"></span></div>
            <div class="pk-cb-row pk-cb-sub"><span>Expected in box</span><span id="cbExpected">$0</span></div>
            <div class="pk-cb-row pk-cb-input-row"><label for="cbCounted">Counted in box</label><span class="pk-cb-money">$<input type="number" inputmode="decimal" step="0.01" min="0" id="cbCounted" oninput="cashBoxRecompute()" placeholder="count it"></span></div>
            <div class="pk-cb-row pk-cb-total"><span>Over / Short</span><span id="cbOverShort" class="pk-cb-os">&mdash;</span></div>
            <button type="button" id="cbTipSurplus" class="pk-cb-tipbtn" onclick="cashBoxTipSurplus()" style="display:none">Record the surplus as tips</button>
        </div>
        <div class="pk-modal-actions">
            <button onclick="closeCashBox()">Cancel</button>
            <button class="pk-save" onclick="saveCashBox()">Save</button>
        </div>
    </div>
</div>

<!-- Help modal -->
<div class="pk-modal-overlay" id="helpModal" onclick="if(event.target===this)closeHelp()">
    <div class="pk-modal">
        <h3>How this screen works</h3>
        <div class="pk-help-content">
            <h4>Buy In</h4>
            <p>Records a player's buy-in. It also checks them in and assigns them a seat automatically &mdash; there is no separate check-in step to do first.</p>
            <h4>Approve / Deny</h4>
            <p>These appear only for players who added themselves through the self-signup or walk-in QR code. <b>Approve</b> puts them on the roster so you can buy them in; <b>Deny</b> rejects them. If you add everyone yourself, you'll never see these buttons.</p>
            <h4>Rebuys &amp; Add-ons</h4>
            <p>Use the + / &minus; counters on a player's row to track each rebuy or add-on. The prize pool at the top right updates as you go.</p>
            <h4>Eliminate</h4>
            <p>Marks a player as knocked out. Their finishing place is filled in automatically by elimination order (9th, 8th … down to 1st), and if that place is in the money the prize owed shows next to their name. Eliminate players in the order they bust. Use <b>Undo</b> if you make a mistake.</p>
        </div>
        <div class="pk-modal-actions">
            <button class="pk-save" onclick="closeHelp()">Got it</button>
        </div>
    </div>
</div>

<!-- Eliminate confirmation modal -->
<div class="pk-modal-overlay" id="elimModal" onclick="if(event.target===this)closeElim()">
    <div class="pk-modal">
        <h3>Eliminate Player</h3>
        <p id="elimMsg" style="font-size:.9rem;color:#475569;line-height:1.45;margin:0"></p>
        <div class="pk-modal-actions">
            <button onclick="closeElim()">Cancel</button>
            <button class="pk-save" style="background:#dc2626" onclick="confirmElim()">Eliminate</button>
        </div>
    </div>
</div>

<!-- Winner / game-over modal -->
<div class="pk-modal-overlay" id="winnerModal" onclick="if(event.target===this)closeWinner()">
    <div class="pk-modal" style="text-align:center">
        <div style="font-size:2.75rem;line-height:1">🏆</div>
        <h3 id="winnerMsg" style="margin:.4rem 0 .25rem"></h3>
        <p id="winnerSub" style="font-size:.9rem;color:#475569;line-height:1.45;margin:0"></p>
        <div class="pk-modal-actions" style="justify-content:center">
            <button class="pk-save" onclick="closeWinner()">Nice!</button>
        </div>
    </div>
</div>

<?php require __DIR__ . '/_footer.php'; ?>

<script>
var CSRF = <?= json_encode($csrf, JSON_HEX_TAG) ?>;
var ALL_USERS = <?= json_encode($allUsernames, JSON_HEX_TAG) ?>;
var EVENT_ID = <?= $event_id ?>;
var EVENT_LEAGUE_ID = <?= (int)($event['league_id'] ?? 0) ?>;
var IS_ADMIN = <?= $isAdmin ? 'true' : 'false' ?>;
var SESSION = <?= $session ? json_encode($session, JSON_HEX_TAG) : 'null' ?>;
var PAYOUT_STRUCTURES = [];
var CURRENT_STRUCTURE_ID = 0;
var PLAYERS = [];
var PAYOUTS = [];
var POOL = {};
var FILTER = 'all';
var VIEW_MODE = 'list';
var SORT_KEY = '';   // '' = default (entry order); otherwise a column key
var SORT_DIR = 1;    // 1 = ascending, -1 = descending
var notesPlayerId = null;
var LOG = [];        // session activity log entries (newest-first)
var TICKETS = { incoming: [], outgoing: [] };  // entry tickets touching this session
var JACKPOTS = { league_id: null, balance: 0 };  // league jackpot fund (cents)

// Fallback shims: if a stale-cached pk-dialogs.js predates pkProgress, Save
// must still work (just without the animation). The deferred fresh script
// overwrites these with the real implementations.
if (!window.pkProgress)     window.pkProgress = function () { return { done: function () {} }; };
if (!window.pkProgressDone) window.pkProgressDone = function () {};

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

// The full reward entry (pct/points/ticket/prize) for a finishing place.
function rewardForPlace(place) {
    place = parseInt(place);
    if (!place) return null;
    for (var i = 0; i < PAYOUTS.length; i++) {
        if (parseInt(PAYOUTS[i].place) === place) return PAYOUTS[i];
    }
    return null;
}

// Compact reward chips (points / ticket / prize label / bounty count) shown
// next to a player's status. ONE helper shared by the desktop table and the
// mobile cards so the dual render paths can't drift.
function rewardChips(p) {
    var h = '';
    var pts = parseInt(p.points) || 0;
    if (pts > 0) h += ' <span style="color:#7c3aed;font-weight:700;font-size:.72rem" title="League points">+' + pts + ' pts</span>';
    var r = rewardForPlace(p.finish_position);
    if (r && parseInt(r.ticket_cents) > 0) h += ' <span style="color:#b45309;font-weight:700;font-size:.72rem" title="Entry ticket prize">🎟 ' + formatMoney(parseInt(r.ticket_cents)) + '</span>';
    if (r && r.prize_label) h += ' <span style="color:#475569;font-weight:600;font-size:.72rem" title="Prize">' + escHtml(r.prize_label) + '</span>';
    var kos = parseInt(p.bounties_won) || 0;
    if (kos > 0) h += ' <span style="color:#0e7490;font-weight:700;font-size:.72rem" title="Bounties collected' + (parseInt(p.bounty_cash) > 0 ? ' — ' + formatMoney(parseInt(p.bounty_cash)) : '') + '">🎯 x' + kos + '</span>';
    return h;
}

// Plain-text variant for the mobile status pill.
function rewardText(p) {
    var parts = [];
    var pts = parseInt(p.points) || 0;
    if (pts > 0) parts.push('+' + pts + ' pts');
    var r = rewardForPlace(p.finish_position);
    if (r && parseInt(r.ticket_cents) > 0) parts.push('🎟 ' + formatMoney(parseInt(r.ticket_cents)));
    if (r && r.prize_label) parts.push(escHtml(r.prize_label));
    var kos = parseInt(p.bounties_won) || 0;
    if (kos > 0) parts.push('🎯 x' + kos);
    return parts.length ? ' · ' + parts.join(' · ') : '';
}

function postAction(action, data, callback, onError) {
    var fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('action', action);
    for (var k in data) fd.append(k, data[k]);
    fetch('/checkin_dl.php', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(j) {
            if (!j.ok) {
                if (onError) onError(j); else pkAlert(j.error || 'Error');
                return;
            }
            callback(j);
            refreshLogIfOpen();
        })
        .catch(function(e) {
            console.error(e);
            if (onError) onError({ error: 'Request failed' }); else pkAlert('Request failed');
        });
}

function loadSession() {
    fetch('/checkin_dl.php?action=get_session&event_id=' + EVENT_ID)
        .then(function(r) { return r.json(); })
        .then(function(j) {
            if (!j.ok) { pkAlert(j.error || 'Error'); return; }
            if (!j.session) {
                renderSetup();
            } else {
                SESSION = j.session;
                PLAYERS = j.players;
                PAYOUTS = j.payouts;
                POOL = j.pool;
                LOG = j.log || [];
                TICKETS = j.tickets || { incoming: [], outgoing: [] };
                JACKPOTS = j.jackpots || JACKPOTS;
                WALKIN_SEEN = new Set(pendingPlayerIds(PLAYERS)); // baseline: no alerts for pre-existing pendings
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
    h += '<label>Buy-in Amount</label><div class="pk-money-wrap"><input type="number" id="s_buyin" value="20" step="1" min="0"></div>';
    h += '<div id="setupTourneyFields">';
    h += '<label>Rebuy Amount</label><div class="pk-money-wrap"><input type="number" id="s_rebuy" value="20" step="1" min="0"></div>';
    h += '<label>Add-on Amount</label><div class="pk-money-wrap"><input type="number" id="s_addon" value="10" step="1" min="0"></div>';
    h += '<label>Starting Chips</label><input type="number" id="s_chips" value="5000" step="1" min="1">';
    h += '<label>Add-on Chips</label><input type="number" id="s_addon_chips" value="5000" step="1" min="0">';
    h += '</div>';
    h += '<label>Number of Tables</label><input type="number" id="s_tables" value="1" step="1" min="1">';
    h += '<button type="submit" onclick="initSession()">Create Session &amp; Import Players</button>';
    h += '</div>';
    document.getElementById('app').innerHTML = h;
    // Prefill from remembered defaults (last-used for this league, else personal, else hardcoded).
    var qs = EVENT_LEAGUE_ID ? '?league_id=' + EVENT_LEAGUE_ID : '';
    fetch('/checkin_dl.php?action=get_session_defaults' + qs)
        .then(function(r) { return r.json(); })
        .then(function(j) {
            if (!j.ok || !j.defaults) return;
            var d = j.defaults;
            var by = document.getElementById('s_buyin'); if (by) by.value = Math.round((d.buyin_amount || 2000) / 100);
            var rb = document.getElementById('s_rebuy'); if (rb) rb.value = Math.round((d.rebuy_amount || 2000) / 100);
            var ad = document.getElementById('s_addon'); if (ad) ad.value = Math.round((d.addon_amount || 1000) / 100);
            var ch = document.getElementById('s_chips'); if (ch) ch.value = d.starting_chips || 5000;
            var ac = document.getElementById('s_addon_chips'); if (ac) ac.value = d.addon_chips || (d.starting_chips || 5000);
            var tb = document.getElementById('s_tables'); if (tb) tb.value = d.num_tables || 1;
            if (d.game_type === 'cash') setSetupType('cash');
        });
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
    var buyin = Math.max(0, Math.round(parseFloat(document.getElementById('s_buyin').value || 20))) * 100;
    var data = {
        event_id: EVENT_ID,
        buyin_amount: buyin,
        game_type: setupGameType,
        num_tables: parseInt(document.getElementById('s_tables').value || 1)
    };
    if (setupGameType === 'tournament') {
        data.rebuy_amount   = Math.max(0, Math.round(parseFloat(document.getElementById('s_rebuy').value || 20))) * 100;
        data.addon_amount   = Math.max(0, Math.round(parseFloat(document.getElementById('s_addon').value || 10))) * 100;
        data.starting_chips = parseInt(document.getElementById('s_chips').value || 5000);
        data.addon_chips    = parseInt((document.getElementById('s_addon_chips') || {}).value || data.starting_chips);
    } else {
        data.rebuy_amount = buyin;
        data.addon_amount = 0;
        data.starting_chips = 0;
        data.addon_chips = 0;
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
    h += '<a href="/calendar.php" class="pk-btn-back" title="Back" style="text-decoration:none" onclick="if(history.length>1){history.back();return false;}">&larr;</a>';
    h += '<h1>' + escHtml(<?= json_encode($event['title'], JSON_HEX_TAG) ?>) + ' <a href="/calendar.php"><span class="pk-act-label">Calendar</span></a></h1>';
    h += '<span class="pk-badge ' + typeClass + '">' + typeLabel + '</span>';
    h += '<div class="pk-actions">';
    h += '<button class="pk-btn-settings" onclick="openSettings(\'game\')" title="Settings">&#9881;<span class="pk-act-label"> Settings</span></button>';
    if (isTourney()) {
        h += '<a class="pk-btn-settings" href="/timer.php?event_id=' + <?= (int)$event['id'] ?> + '" style="text-decoration:none" title="Timer">&#9201;<span class="pk-act-label"> Timer</span></a>';
    }
    h += '<a class="pk-btn-settings" href="/walkin_display.php?event_id=' + <?= (int)$event['id'] ?> + '" target="_blank" style="text-decoration:none" title="QR Registration">&#128241;<span class="pk-act-label"> QR</span></a>';
    if (isTourney()) {
        h += '<button class="pk-btn-settings" onclick="openDealSplit()" title="Payout Calculator">&#128176;<span class="pk-act-label"> Payout</span></button>';
    } else {
        h += '<button class="pk-btn-settings" onclick="openCashBox()" title="Cash box: record tips and square the box">&#129534;<span class="pk-act-label"> Cash Box</span></button>';
    }
    // League jackpots: record a bad-beat / royal hit from the table.
    if (JACKPOTS.league_id) {
        h += '<button class="pk-btn-settings" onclick="openJackpotModal()" title="League jackpots: view funds and record a hit">💎<span class="pk-act-label"> Jackpot</span></button>';
    }
    // Game lifecycle lives in the header now, not buried in Settings.
    if (SESSION.status !== 'finished') {
        h += '<button class="pk-btn-green" onclick="pkConfirm(\'Mark this game as finished? This finalizes all stats and payouts.\').then(function(ok){if(ok)changeStatus(\'finished\')})" title="Finish the game and lock in payouts">&#10003;<span class="pk-act-label"> Finish</span></button>';
    } else {
        h += '<button class="pk-btn-settings" style="color:#d97706;border-color:#fcd34d" onclick="pkConfirm(\'Reopen this game?\').then(function(ok){if(ok)changeStatus(\'active\')})" title="Reopen the finished game">&#8634;<span class="pk-act-label"> Reopen</span></button>';
    }
    h += '</div>';
    if (isCash()) {
        h += '<div class="pk-pool" id="poolTotal"><small>Money In Play</small>' + formatMoney(POOL.total_cash_in) + '</div>';
    } else {
        h += '<div class="pk-pool" id="poolTotal"><small>Prize Pool</small>' + formatMoney(POOL.pool_total) + '</div>';
    }
    h += '</div>';

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
    h += '<div class="pk-seg pk-filter" id="filterSeg">';
    h += '<span class="pk-seg-thumb"></span>';
    h += '<button data-filter="all" class="' + (FILTER==='all'?'active':'') + '" onclick="setFilter(\'all\')">All</button>';
    if (isTourney()) h += '<button data-filter="rsvp_yes" class="' + (FILTER==='rsvp_yes'?'active':'') + '" onclick="setFilter(\'rsvp_yes\')">RSVP Yes</button>';
    if (isTourney()) {
        h += '<button data-filter="playing" class="' + (FILTER==='playing'?'active':'') + '" onclick="setFilter(\'playing\')">Playing</button>';
        h += '<button data-filter="eliminated" class="' + (FILTER==='eliminated'?'active':'') + '" onclick="setFilter(\'eliminated\')">Out</button>';
    } else {
        h += '<button data-filter="playing" class="' + (FILTER==='playing'?'active':'') + '" onclick="setFilter(\'playing\')">Active</button>';
        h += '<button data-filter="eliminated" class="' + (FILTER==='eliminated'?'active':'') + '" onclick="setFilter(\'eliminated\')">Out</button>';
    }
    h += '</div>';
    h += '<div class="pk-seg pk-view-seg" id="viewSeg">';
    h += '<span class="pk-seg-thumb"></span>';
    h += '<button data-view="list" class="' + (VIEW_MODE === 'list' ? 'active' : '') + '" onclick="setViewMode(\'list\')">&#9776; List</button>';
    h += '<button data-view="table" class="' + (VIEW_MODE === 'table' ? 'active' : '') + '" onclick="setViewMode(\'table\')">&#9638; Table</button>';
    h += '<button data-view="log" class="' + (VIEW_MODE === 'log' ? 'active' : '') + '" title="Activity log: buy-ins, cash-outs, adds and more" onclick="setViewMode(\'log\')">&#128203; Log</button>';
    h += '</div>';
    h += '<button class="pk-help-btn" title="How this screen works" aria-label="Help" onclick="openHelp()">? Help</button>';
    h += '<button class="pk-btn-view-toggle" onclick="balanceTables()">&#9878; Balance</button>';
    h += '<button id="addTableBtn" class="pk-btn-green" onclick="addTable()"' + (VIEW_MODE === 'table' ? '' : ' style="display:none"') + '>Add Table</button>';
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

    h += '<div id="viewContent">' + renderViewContent() + '</div>';
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
    positionAllSegThumbs(false);
    if (VIEW_MODE === 'log') renderLog();
}

// Renders the content area for the active view (list / table / log).
function renderViewContent() {
    if (VIEW_MODE === 'log') {
        return '<div class="pk-log-sub">Buy-ins, rebuys, cash-outs, adds &amp; more &mdash; newest first.</div>'
             + '<div class="pk-log-list" id="logList"><div class="pk-log-empty">Loading&hellip;</div></div>';
    }
    if (VIEW_MODE === 'table') return renderTableView();

    var h = '<div class="pk-bulk-bar" id="bulkBar">';
    h += '<span class="pk-bulk-count" id="bulkCount">0 selected</span>';
    if (isTourney()) {
        h += '<button class="primary" title="Record buy-in for the selected players (also checks them in and seats them)" onclick="bulkAction(\'toggle_buyin\')">Buy In</button>';
        h += '<button onclick="bulkAction(\'eliminate_player\')">Eliminate</button>';
    }
    h += '<button title="Approve the selected pending self-signups / walk-ins onto the roster" onclick="bulkAction(\'approve_player\')">Approve</button>';
    h += '<button class="danger" onclick="bulkRemoveConfirm()">Remove</button>';
    h += '<button onclick="clearSelection()">Clear</button>';
    h += '</div>';
    h += '<div class="pk-table-wrap"><table class="pk-table">';
    h += '<thead><tr id="playerHead">' + renderTableHeader() + '</tr></thead>';
    h += '<tbody id="playerBody">' + renderPlayerRows() + '</tbody></table></div>';
    h += '<div class="pk-mobile-list" id="mobileList">' + renderMobileCards() + '</div>';
    return h;
}

function sortableTh(label, key, extra) {
    var arrow = (SORT_KEY === key) ? ' <span style="font-size:.7em">' + (SORT_DIR === 1 ? '▲' : '▼') + '</span>' : '';
    return '<th class="pk-sortable" ' + (extra || '') + ' onclick="setSort(\'' + key + '\')">' + label + arrow + '</th>';
}

function renderTableHeader() {
    var h = '<th style="width:2rem"><span style="display:inline-flex;align-items:center"><input type="checkbox" id="selectAll" class="pk-row-select" onchange="toggleSelectAll(this.checked)">' + tip('Select one or more players to act on several at once (buy in, remove, etc.). You don\'t need this to enter a single player.') + '</span></th>';
    h += '<th>#</th>';
    h += sortableTh('Name', 'name');
    if (isTourney()) h += sortableTh('RSVP', 'rsvp');
    if (isTourney()) {
        h += sortableTh('$ ' + tip('Tick the box to record a buy-in. It also checks the player in and seats them. The 📒 ledger icon shows their buy-in / rebuy / add-on history and lets you edit or clear a mistake.'), 'buyin', 'title="Buy-in"');
        if (parseInt(SESSION.jackpot_amount) > 0) h += sortableTh('💎 ' + tip('Optional jackpot side entry (' + formatMoney(parseInt(SESSION.jackpot_amount)) + ', on top of the buy-in). Tick for each player who\'s in — entries feed the league jackpot at finish.'), 'jackpot', 'title="Jackpot entry"');
        if (parseInt(SESSION.rebuy_allowed)) h += sortableTh('Rebuys', 'rebuys');
        if (parseInt(SESSION.addon_allowed)) h += sortableTh('Add-ons', 'addons');
    } else {
        h += sortableTh('Cash In ' + tip('Type the total they bought in for and press Enter. Use + to add a top-up. The 📒 ledger icon shows every entry and lets you edit or clear a wrong one.'), 'totalin');
        h += sortableTh('Cash Out ' + tip('Type what they leave the table with and press the green check (or Enter). Clear the field to put them back in play.'), 'cashout');
        h += sortableTh('Profit', 'profit');
    }
    h += sortableTh('Table', 'table');
    h += sortableTh('Seat', 'seat');
    h += sortableTh('Status', 'status');
    h += '<th>Actions</th>';
    return h;
}

function setSort(key) {
    if (SORT_KEY === key) { SORT_DIR = -SORT_DIR; }
    else { SORT_KEY = key; SORT_DIR = 1; }
    var head = document.getElementById('playerHead');
    if (head) head.innerHTML = renderTableHeader();
    refreshUI();
}

function sortVal(p, key) {
    switch (key) {
        case 'name':    return (p.display_name || '').toLowerCase();
        case 'rsvp':    { var rank = { yes: 0, maybe: 1, no: 2 }; return (p.rsvp in rank) ? rank[p.rsvp] : 3; }
        case 'buyin':   return parseInt(p.bought_in) || 0;
        case 'rebuys':  return parseInt(p.rebuys) || 0;
        case 'addons':  return parseInt(p.addons) || 0;
        case 'table':   return parseInt(p.table_number) || 0;
        case 'seat':    return parseInt(p.seat_number) || 0;
        case 'totalin': return playerTotalIn(p);
        case 'cashout': return (p.cash_out === null || p.cash_out === undefined) ? -Infinity : parseInt(p.cash_out);
        case 'profit':  { var pr = playerProfit(p); return (pr === null) ? -Infinity : pr; }
        case 'status':
            if (p.approval_status === 'pending') return 0;
            if (isCash()) return (p.cash_out !== null && p.cash_out !== undefined) ? 3 : 1;
            if (parseInt(p.eliminated)) return 3 + (parseInt(p.finish_position) || 999) / 1000; // out: by place (1st first)
            if (parseInt(p.bought_in)) return 1;  // playing
            return 2;                              // not bought in yet
    }
    return 0;
}

// Apply the active column sort; returns a new array (entry order when no sort set).
function sortPlayers(arr) {
    if (!SORT_KEY) return arr;
    return arr.slice().sort(function(a, b) {
        var va = sortVal(a, SORT_KEY), vb = sortVal(b, SORT_KEY);
        if (va < vb) return -SORT_DIR;
        if (va > vb) return SORT_DIR;
        var na = (a.display_name || '').toLowerCase(), nb = (b.display_name || '').toLowerCase();
        return na < nb ? -1 : (na > nb ? 1 : 0);
    });
}

function renderStatsCompact() {
    var h = '';
    var s = '<span class="sep">|</span>';
    h += '<span>Players: <b>' + POOL.total_players + '</b></span>' + s;
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
    var filtered = sortPlayers(PLAYERS.filter(function(p) {
        if (FILTER === 'rsvp_yes') return p.rsvp === 'yes';
        if (isCash()) {
            if (FILTER === 'playing') return parseInt(p.bought_in) && (p.cash_out === null || p.cash_out === undefined);
            if (FILTER === 'eliminated') return p.cash_out !== null && p.cash_out !== undefined;
        } else {
            if (FILTER === 'playing') return !parseInt(p.eliminated) && parseInt(p.bought_in);
            if (FILTER === 'eliminated') return parseInt(p.eliminated);
        }
        return true;
    }));
    for (var i = 0; i < filtered.length; i++) {
        var p = filtered[i];
        num++;
        var isElim = parseInt(p.eliminated);
        var hasCashedOut = isCash() && p.cash_out !== null && p.cash_out !== undefined;
        var isWalkin = !p.user_id;
        var rsvp = p.rsvp || '';
        var isNo = rsvp === 'no';
        var isPending = (p.approval_status === 'pending');
        // Never disable the money controls for RSVP-no players: people who said
        // no and showed up anyway are normal, and a dead checkbox with no
        // explanation cost a real game two buy-ins (July 2026, pot short $40).
        // Buying such a player in corrects their RSVP server-side.
        var dis = '';
        var isWinner = !isElim && isTourney() && parseInt(p.finish_position) === 1;
        var rowClass = isPending ? 'pending-row' : (isElim ? 'elim' : (isWinner ? 'winner' : (hasCashedOut ? 'cashed-out' : (isNo ? 'rsvp-no' : ''))));
        h += '<tr class="' + rowClass + '" data-pid="' + p.id + '">';
        h += '<td><input type="checkbox" class="pk-row-select pk-player-cb" value="' + p.id + '" onchange="updateBulkBar()"></td>';
        h += '<td>' + num + '</td>';
        h += '<td class="name-cell">' + escHtml(p.display_name);
        if (isWalkin) h += '<span class="walkin-badge" title="Walk-in player" aria-label="Walk-in player">&#128694;</span>';
        if (p.notes) h += ' <span title="' + escHtml(p.notes) + '" style="cursor:help">&#128221;</span>';
        h += '</td>';

        // RSVP dropdown (tournaments only — not used in the cash-game flow)
        if (isTourney()) {
            h += '<td><select class="pk-rsvp-select" onchange="updateRsvp(' + p.id + ',this.value)" style="font-size:.75rem;padding:.15rem .3rem;border-radius:4px;border:1px solid #e2e8f0;background:' + rsvpBg(rsvp) + ';color:' + rsvpColor(rsvp) + ';font-weight:600">';
            h += '<option value=""' + (rsvp===''?' selected':'') + '>—</option>';
            h += '<option value="yes"' + (rsvp==='yes'?' selected':'') + '>Yes</option>';
            h += '<option value="no"' + (rsvp==='no'?' selected':'') + '>No</option>';
            h += '<option value="maybe"' + (rsvp==='maybe'?' selected':'') + '>Maybe</option>';
            h += '</select></td>';
        }

        if (isPending) {
            h += '<td colspan="' + (isTourney() ? (1 + (parseInt(SESSION.jackpot_amount)>0?1:0) + (parseInt(SESSION.rebuy_allowed)?1:0) + (parseInt(SESSION.addon_allowed)?1:0)) : 3) + '" style="text-align:center;color:#d97706;font-size:.8rem;font-style:italic">Awaiting approval</td>';
        } else {
        if (isTourney()) {
            h += '<td><div style="display:inline-flex;align-items:center;gap:.15rem"><input type="checkbox" class="pk-check" title="Record this player\'s buy-in. Checks them in and assigns a seat automatically — no separate check-in step needed." ' + (parseInt(p.bought_in) ? 'checked' : '') + dis + ' onchange="toggleBuyin(' + p.id + ')">' + ledgerBtn(p.id) + '</div></td>';
            if (parseInt(SESSION.jackpot_amount) > 0) {
                h += '<td><input type="checkbox" class="pk-check" style="accent-color:#7c3aed" title="Jackpot side entry (' + formatMoney(parseInt(SESSION.jackpot_amount)) + ')" ' + (parseInt(p.jackpot_in) ? 'checked' : '') + dis + ' onchange="toggleJackpot(' + p.id + ')"></td>';
            }
            if (parseInt(SESSION.rebuy_allowed)) {
                h += '<td><div class="pk-counter"><button onclick="updateRebuys(' + p.id + ',-1)"' + dis + '>-</button><span>' + p.rebuys + '</span><button onclick="updateRebuys(' + p.id + ',1)"' + dis + '>+</button></div></td>';
            }
            if (parseInt(SESSION.addon_allowed)) {
                var aoCount = parseInt(p.addons) || 0;
                h += '<td style="width:6.5rem;min-width:6.5rem"><div style="display:flex;align-items:center;gap:.3rem;width:100%">'
                   + '<button class="pk-addon-btn" onclick="addAddon(' + p.id + ')"' + dis + ' style="font-size:.75rem;padding:.2rem .5rem;border-radius:4px;border:1px solid #c4b5fd;background:#f5f3ff;color:#6d28d9;cursor:pointer;font-weight:600;flex:0 0 auto">+ Add-on</button>'
                   + '<span onclick="' + (aoCount > 0 ? 'removeAddon(' + p.id + ')' : '') + '" title="' + (aoCount > 0 ? 'Click to remove last add-on' : '') + '" style="display:inline-flex;align-items:center;justify-content:center;min-width:1.3rem;height:1.3rem;padding:0 .35rem;border-radius:10px;font-size:.72rem;font-weight:700;flex:0 0 auto;' + (aoCount > 0 ? 'background:#7c3aed;color:#fff;cursor:pointer' : 'background:transparent;color:transparent;pointer-events:none') + '">' + (aoCount > 0 ? aoCount : '0') + '</span>'
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
                h += '<td><div class="pk-counter"><input type="number" inputmode="decimal" step="0.01" min="0" class="pk-cash-input" data-pid="' + p.id + '" value="' + (cashIn/100) + '" onchange="setCashIn(' + p.id + ',this.value)" onkeydown="if(event.key===\'Enter\'){event.preventDefault();setCashIn(' + p.id + ',this.value);focusNextCashInput(this);}" style="border:none;min-width:60px"><button onclick="adjustMoney(' + p.id + ',1)">+</button>' + ledgerBtn(p.id) + '</div></td>';
                if (parseInt(p.bought_in)) {
                    // Inline cash-out field (mirrors Cash In). Green check commits for mouse users;
                    // Enter also commits. Clearing the field reverts the player to still-playing.
                    var coVal = hasCashedOut ? (parseInt(p.cash_out) / 100) : '';
                    h += '<td><div class="pk-counter">'
                       + '<input type="number" inputmode="decimal" step="0.01" min="0" class="pk-co-input" value="' + coVal + '" placeholder="0.00" onkeydown="if(event.key===\'Enter\'){event.preventDefault();commitCashOut(' + p.id + ',this.value);}" style="border:none;min-width:60px">'
                       + '<button title="Record cash-out" style="color:#16a34a;font-weight:700" onclick="commitCashOut(' + p.id + ', this.previousElementSibling.value)">✓</button>'
                       + (hasCashedOut ? '' : '<button class="pk-bust-btn" title="Busted: out with $0. Frees their seat. Add cash to bring them back in." onclick="bustOut(' + p.id + ')">&#128165;</button>')
                       + '</div></td>';
                    if (hasCashedOut) {
                        var prof = parseInt(p.cash_out) - cashIn;
                        var cls = prof > 0 ? 'pk-profit-pos' : (prof < 0 ? 'pk-profit-neg' : 'pk-profit-zero');
                        h += '<td><span class="' + cls + '">' + formatProfit(prof) + '</span></td>';
                    } else {
                        h += '<td><span style="color:#94a3b8">—</span></td>';
                    }
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
                var elAmt = parseInt(p.payout) || payoutForPlace(p.finish_position);
                h += '<td><span style="color:#ef4444;font-weight:600">' + ordinalLabel(p.finish_position) + '</span>'
                   + (elAmt > 0 ? ' <span style="color:#16a34a;font-weight:700;font-size:.78rem" title="Prize owed">' + formatMoney(elAmt) + '</span>' : '')
                   + rewardChips(p)
                   + '</td>';
            } else if (isWinner) {
                var wAmt = parseInt(p.payout) || payoutForPlace(1);
                h += '<td><span style="color:#b8860b;font-weight:700">🏆 1st</span>'
                   + (wAmt > 0 ? ' <span style="color:#16a34a;font-weight:700;font-size:.78rem" title="Prize">' + formatMoney(wAmt) + '</span>' : '')
                   + rewardChips(p)
                   + '</td>';
            } else if (parseInt(p.bought_in)) {
                h += '<td><span style="color:#22c55e;font-weight:600">Playing</span>' + rewardChips(p) + '</td>';
            } else {
                h += '<td><span style="color:#94a3b8">—</span></td>';
            }
        } else {
            if (hasCashedOut) {
                var busted = parseInt(p.cash_out) === 0;
                h += '<td><span style="color:' + (busted ? '#dc2626' : '#64748b') + ';font-weight:600">' + (busted ? 'Busted' : 'Cashed Out') + '</span></td>';
            } else if (parseInt(p.bought_in)) {
                h += '<td><span style="color:#22c55e;font-weight:600">Playing</span></td>';
            } else {
                h += '<td><span style="color:#94a3b8">—</span></td>';
            }
        }

        // Actions
        h += '<td style="white-space:nowrap">';
        if (isPending) {
            h += '<button class="pk-act-btn primary" title="This player joined via self-signup / walk-in QR. Approve to add them to the roster so they can buy in." onclick="approvePlayer(' + p.id + ')">Approve</button>';
            h += '<button class="pk-act-btn danger" title="Reject this join request and remove them from the list." onclick="denyPlayer(' + p.id + ')">Deny</button>';
        } else {
            if (!isNo) {
                if (isTourney()) {
                    if (!isElim && parseInt(p.bought_in) && !isWinner) {
                        h += '<button class="pk-act-btn primary" onclick="eliminatePlayer(' + p.id + ')">Eliminate</button>';
                    }
                    if (isElim) {
                        h += '<button class="pk-act-btn" onclick="uneliminate(' + p.id + ')">Undo</button>';
                    }
                } else {
                    // Cash games: cashing out lives in the Cash Out column. Undo lives in
                    // that dialog. Keep Undo Elim for any player eliminated before that rule.
                    if (isElim) {
                        h += '<button class="pk-act-btn" onclick="uneliminate(' + p.id + ')">Undo Elim</button>';
                    }
                }
            }
            h += '<button class="pk-act-btn" onclick="openNotes(' + p.id + ')">Notes</button>';
            h += '<button class="pk-act-btn danger" onclick="removePlayerConfirm(' + p.id + ')">Remove</button>';
        }
        h += '</td>';
        h += '</tr>';
    }
    if (filtered.length === 0) {
        var cols = isTourney()
            ? 7 + (parseInt(SESSION.jackpot_amount)>0?1:0) + (parseInt(SESSION.rebuy_allowed)?1:0) + (parseInt(SESSION.addon_allowed)?1:0) + (parseInt(SESSION.num_tables)>1?1:0)
            : 8 + (parseInt(SESSION.num_tables)>1?1:0);
        h += '<tr><td colspan="' + cols + '" style="text-align:center;padding:2rem;color:#94a3b8">No players</td></tr>';
    }
    return h;
}

function renderMobileCards() {
    var h = '';
    var filtered = sortPlayers(PLAYERS.filter(function(p) {
        if (FILTER === 'rsvp_yes') return p.rsvp === 'yes';
        if (isCash()) {
            if (FILTER === 'playing') return parseInt(p.bought_in) && (p.cash_out === null || p.cash_out === undefined);
            if (FILTER === 'eliminated') return p.cash_out !== null && p.cash_out !== undefined;
        } else {
            if (FILTER === 'playing') return !parseInt(p.eliminated) && parseInt(p.bought_in);
            if (FILTER === 'eliminated') return parseInt(p.eliminated);
        }
        return true;
    }));
    for (var i = 0; i < filtered.length; i++) {
        var p = filtered[i];
        var isElim = parseInt(p.eliminated);
        var hasCashedOut = isCash() && p.cash_out !== null && p.cash_out !== undefined;
        var isNo = p.rsvp === 'no';
        var isWinner = !isElim && isTourney() && parseInt(p.finish_position) === 1;
        var cardClass = isElim ? 'elim' : (isWinner ? 'winner' : (hasCashedOut ? 'cashed-out' : (isNo ? 'rsvp-no' : '')));

        // Status text and color
        var isPending = (p.approval_status === 'pending');
        var statusText = '\u2014', statusColor = '#94a3b8', statusBg = '#f1f5f9';
        if (isPending) {
            statusText = 'Pending'; statusColor = '#d97706'; statusBg = '#fefce8';
        } else if (isTourney()) {
            if (isElim) { var moAmt = parseInt(p.payout) || payoutForPlace(p.finish_position); statusText = ordinalLabel(p.finish_position) + (moAmt > 0 ? ' · ' + formatMoney(moAmt) : '') + rewardText(p); statusColor = '#ef4444'; statusBg = '#fef2f2'; }
            else if (isWinner) { var woAmt = parseInt(p.payout) || payoutForPlace(1); statusText = '🏆 1st' + (woAmt > 0 ? ' · ' + formatMoney(woAmt) : '') + rewardText(p); statusColor = '#b8860b'; statusBg = '#fffbeb'; }
            else if (parseInt(p.bought_in)) { statusText = 'Playing' + rewardText(p); statusColor = '#16a34a'; statusBg = '#f0fdf4'; }
        } else {
            if (hasCashedOut) { var mBusted = parseInt(p.cash_out) === 0; statusText = mBusted ? 'Busted' : 'Cashed Out'; statusColor = mBusted ? '#dc2626' : '#64748b'; statusBg = mBusted ? '#fef2f2' : '#f1f5f9'; }
            else if (parseInt(p.bought_in)) { statusText = 'Playing'; statusColor = '#16a34a'; statusBg = '#f0fdf4'; }
        }

        h += '<div class="pk-mobile-card ' + cardClass + '" data-pid="' + p.id + '">';
        h += '<div class="pk-mobile-summary" onclick="toggleMobileExpand(' + p.id + ')">';
        var seatInfo = p.seat_number ? 'T' + (p.table_number || '?') + ' #' + p.seat_number : '';
        h += '<span class="pk-mobile-name">' + escHtml(p.display_name) + (seatInfo ? ' <span style="color:#94a3b8;font-size:.72rem;font-weight:600">' + seatInfo + '</span>' : '') + '</span>';
        if (isPending) {
            // Approve/deny buttons directly on the summary row instead of a status badge
            h += '<span onclick="event.stopPropagation()" style="display:flex;align-items:center;gap:.35rem;margin-left:auto;flex-shrink:0">';
            h += '<button title="This player joined via self-signup / walk-in QR. Approve to add them to the roster so they can buy in." onclick="approvePlayer(' + p.id + ')" style="font-size:.72rem;padding:.25rem .6rem;border-radius:5px;border:0;background:#16a34a;color:#fff;font-weight:700;cursor:pointer">Approve</button>';
            h += '<button title="Reject this join request and remove them from the list." onclick="denyPlayer(' + p.id + ')" style="font-size:.72rem;padding:.25rem .6rem;border-radius:5px;border:0;background:#dc2626;color:#fff;font-weight:700;cursor:pointer">Deny</button>';
            h += '</span>';
        } else {
            // Buy-in checkbox on the summary row (not inside expand)
            if (!isNo && isTourney()) {
                h += '<span onclick="event.stopPropagation()" style="display:flex;align-items:center;gap:.6rem;margin-left:auto;margin-right:.5rem;flex-shrink:0">';
                h += '<label title="Record this player\'s buy-in. Checks them in and assigns a seat automatically — no separate check-in step needed." style="display:flex;align-items:center;gap:.2rem;font-size:.65rem;color:#64748b;font-weight:700;cursor:pointer;padding:.25rem 0;-webkit-tap-highlight-color:transparent">'
                   + '<input type="checkbox" class="pk-check" ' + (parseInt(p.bought_in)?'checked':'') + ' onchange="toggleBuyin(' + p.id + ')" style="width:22px;height:22px;accent-color:#7c3aed"> Buy In</label>';
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
                if (parseInt(SESSION.jackpot_amount) > 0) {
                    h += '<div class="pk-mobile-row">';
                    h += '<label>💎 Jackpot entry (' + formatMoney(parseInt(SESSION.jackpot_amount)) + ')</label>'
                       + '<input type="checkbox" class="pk-check" style="width:22px;height:22px;accent-color:#7c3aed" ' + (parseInt(p.jackpot_in) ? 'checked' : '') + ' onchange="toggleJackpot(' + p.id + ')">';
                    h += '</div>';
                }
                if (parseInt(SESSION.rebuy_allowed)) {
                    h += '<div class="pk-mobile-row">';
                    h += '<label>Rebuys</label><div class="pk-counter"><button onclick="updateRebuys(' + p.id + ',-1)">-</button><span>' + p.rebuys + '</span><button onclick="updateRebuys(' + p.id + ',1)">+</button></div>';
                    h += '</div>';
                }
                if (parseInt(SESSION.addon_allowed)) {
                    var mAoCount = parseInt(p.addons) || 0;
                    h += '<div class="pk-mobile-row">';
                    h += '<label>Add-ons</label><div style="display:flex;align-items:center;gap:.3rem">'
                       + '<button onclick="addAddon(' + p.id + ')" style="font-size:.85rem;padding:.35rem .7rem;border-radius:4px;border:1px solid #c4b5fd;background:#f5f3ff;color:#6d28d9;cursor:pointer;font-weight:600">+ Add-on</button>'
                       + (mAoCount > 0 ? '<span onclick="removeAddon(' + p.id + ')" title="Tap to remove last" style="display:inline-flex;align-items:center;justify-content:center;min-width:1.5rem;height:1.5rem;padding:0 .45rem;border-radius:12px;background:#7c3aed;color:#fff;font-size:.8rem;font-weight:700;cursor:pointer">' + mAoCount + '</span>' : '')
                       + '</div>';
                    h += '</div>';
                }
            } else {
                var cashIn = parseInt(p.cash_in) || 0;
                h += '<div class="pk-mobile-row">';
                h += '<label>Cash In</label><div class="pk-counter"><input type="number" inputmode="decimal" step="0.01" min="0" class="pk-cash-input" value="' + (cashIn/100) + '" onchange="setCashIn(' + p.id + ',this.value)" style="border:none;min-width:50px"><button onclick="adjustMoney(' + p.id + ',1)">+</button>' + ledgerBtn(p.id) + '</div>';
                h += '</div>';
                if (parseInt(p.bought_in)) {
                    var coVal = hasCashedOut ? (parseInt(p.cash_out)/100) : '';
                    h += '<div class="pk-mobile-row">';
                    h += '<label>Cash Out</label><div class="pk-counter"><input type="number" inputmode="decimal" step="0.01" min="0" class="pk-co-input" value="' + coVal + '" placeholder="0.00" onkeydown="if(event.key===\'Enter\'){event.preventDefault();commitCashOut(' + p.id + ',this.value);}" style="border:none;min-width:50px"><button title="Record cash-out" style="color:#16a34a;font-weight:700" onclick="commitCashOut(' + p.id + ', this.previousElementSibling.value)">✓</button>' + (hasCashedOut ? '' : '<button class="pk-bust-btn" title="Busted: out with $0. Frees their seat. Add cash to bring them back in." onclick="bustOut(' + p.id + ')">&#128165;</button>') + '</div>';
                    h += '</div>';
                }
                if (hasCashedOut) {
                    var prof = parseInt(p.cash_out) - cashIn;
                    h += '<div class="pk-mobile-row"><label>Profit</label><span class="' + (prof>0?'pk-profit-pos':prof<0?'pk-profit-neg':'pk-profit-zero') + '">' + formatProfit(prof) + '</span></div>';
                }
            }

            if (parseInt(SESSION.num_tables) > 1) {
                h += '<div class="pk-mobile-row">';
                h += '<label>Table</label><input type="number" class="pk-tbl-input" value="' + (p.table_number||'') + '" min="1" max="' + SESSION.num_tables + '" onchange="setTable(' + p.id + ',this.value)" style="width:50px">';
                h += '</div>';
            }

            // RSVP (tournaments only \u2014 not used in the cash-game flow)
            if (isTourney()) {
            h += '<div class="pk-mobile-row">';
            h += '<label>RSVP</label><select onchange="updateRsvp(' + p.id + ',this.value)" style="font-size:.8rem;padding:.25rem .4rem;border-radius:4px;border:1px solid #e2e8f0">';
            var rsvp = p.rsvp || '';
            h += '<option value=""' + (rsvp===''?' selected':'') + '>\u2014</option>';
            h += '<option value="yes"' + (rsvp==='yes'?' selected':'') + '>Yes</option>';
            h += '<option value="no"' + (rsvp==='no'?' selected':'') + '>No</option>';
            h += '<option value="maybe"' + (rsvp==='maybe'?' selected':'') + '>Maybe</option>';
            h += '</select></div>';
            }
        }

        // Action buttons
        h += '<div class="pk-mobile-actions">';
        if (!isNo) {
            if (isTourney()) {
                if (!isElim && parseInt(p.bought_in) && !isWinner) h += '<button class="primary" onclick="eliminatePlayer(' + p.id + ')">Eliminate</button>';
                if (isElim) h += '<button onclick="uneliminate(' + p.id + ')">Undo Elim</button>';
            } else {
                // Cash games: cashing out is inline in the Cash Out row above.
                // Keep Undo Elim for any player eliminated before that rule.
                if (isElim) h += '<button onclick="uneliminate(' + p.id + ')">Undo Elim</button>';
            }
        }
        h += '<button onclick="openNotes(' + p.id + ')">Notes</button>';
        h += '<button class="danger" onclick="removePlayerConfirm(' + p.id + ')">Remove</button>';
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
        var tips = parseInt(SESSION.tips) || 0;
        if (tips > 0) h += '<div class="pk-pool-row"><span>Tips</span><span>' + formatMoney(tips) + '</span></div>';
        h += '<button class="pk-cashbox-link" onclick="openCashBox()">&#129534; Reconcile cash box</button>';
    } else {
        h += '<h3>Prize Pool</h3>';
        h += '<div class="pk-pool-row"><span>Buy-ins (' + POOL.total_buyins + ' &times; ' + formatMoney(parseInt(SESSION.buyin_amount)) + ')</span><span>' + formatMoney(POOL.buyin_total) + '</span></div>';
        h += '<div class="pk-pool-row"><span>Rebuys (' + POOL.total_rebuys + ' &times; ' + formatMoney(parseInt(SESSION.rebuy_amount)) + ')</span><span>' + formatMoney(POOL.rebuy_total) + '</span></div>';
        h += '<div class="pk-pool-row"><span>Add-ons (' + POOL.total_addons + ' &times; ' + formatMoney(parseInt(SESSION.addon_amount)) + ')</span><span>' + formatMoney(POOL.addon_total) + '</span></div>';
        // Net-pool adjustments (bounties / entry tickets) — only shown when in play.
        var bw = parseInt(POOL.bounty_withheld) || 0;
        var tw = parseInt(POOL.ticket_withheld) || 0;
        var ti = parseInt(POOL.ticket_in) || 0;
        if (bw > 0) h += '<div class="pk-pool-row"><span>&minus; Bounties (' + POOL.total_buyins + ' &times; ' + formatMoney(parseInt(SESSION.bounty_amount) || 0) + ')</span><span style="color:#0e7490">&minus;' + formatMoney(bw) + '</span></div>';
        if (tw > 0) h += '<div class="pk-pool-row"><span>&minus; Ticket prizes</span><span style="color:#b45309">&minus;' + formatMoney(tw) + '</span></div>';
        if (ti > 0) h += '<div class="pk-pool-row"><span>+ Ticket seat surplus</span><span style="color:#16a34a">+' + formatMoney(ti) + '</span></div>';
        h += '<div class="pk-pool-row total"><span>' + ((bw || tw || ti) ? 'Prize Pool (net)' : 'Total') + '</span><span>' + formatMoney(POOL.pool_total) + '</span></div>';
        // Jackpot side money: optional entries on top of the buy-in, shown for
        // the cash count but never part of the prize pool.
        if (parseInt(SESSION.jackpot_amount) > 0) {
            var je = parseInt(POOL.jackpot_entries) || 0;
            h += '<div class="pk-pool-row"><span style="color:#7c3aed">💎 Jackpot entries (' + je + ' &times; ' + formatMoney(parseInt(SESSION.jackpot_amount)) + ', side money)</span><span style="color:#7c3aed">' + formatMoney(parseInt(POOL.jackpot_collected) || 0) + '</span></div>';
        }
        // Unclaimed bounty tracker: KOs recorded without an eliminator leave
        // bounty cash on the table — surface the shortfall.
        if (bw > 0) {
            var claimed = 0;
            (PLAYERS || []).forEach(function(p) { claimed += parseInt(p.bounty_cash) || 0; });
            var unclaimed = bw - claimed;
            if (unclaimed > 0) h += '<div class="pk-pool-row"><span style="color:#d97706">Unclaimed bounties</span><span style="color:#d97706">' + formatMoney(unclaimed) + '</span></div>';
        }
    }
    return h;
}

function renderPayoutCard() {
    var h = '<h3>Payouts</h3>';
    // Pot-integrity check: a player with rebuys/add-ons but NO buy-in means the
    // pool is understated — exactly the anomaly that put a real game's payout
    // math $40 off. Surface it where the host looks at payout time.
    var anomalies = (PLAYERS || []).filter(function(p) {
        return !parseInt(p.bought_in)
            && (parseInt(p.rebuys) > 0 || parseInt(p.addons) > 0 || parseInt(p.checked_in));
    });
    if (anomalies.length) {
        h += '<div style="background:#fef2f2;border:1px solid #fecaca;border-radius:6px;padding:.45rem .6rem;margin-bottom:.6rem;font-size:.78rem;color:#991b1b;font-weight:600">'
           + '&#9888; No buy-in recorded for: ' + anomalies.map(function(p) { return escHtml(p.display_name); }).join(', ')
           + ' — but they have rebuys/add-ons or are checked in. The pool may be short.'
           + '</div>';
    }
    // Ticket prizes configured but no target event set: they can't be issued.
    var anyTicket = PAYOUTS.some(function(p) { return parseInt(p.ticket_cents) > 0; });
    if (anyTicket && !parseInt(SESSION.ticket_target_event_id)) {
        h += '<div style="background:#fffbeb;border:1px solid #fde68a;border-radius:6px;padding:.45rem .6rem;margin-bottom:.6rem;font-size:.78rem;color:#92400e;font-weight:600">&#9888; Ticket prizes need a target event — <a href="#" onclick="openSettings(\'payouts\');return false" style="color:#92400e">set one in Settings</a>.</div>';
    }
    var totalPct = 0;
    for (var i = 0; i < PAYOUTS.length; i++) {
        var pay = PAYOUTS[i];
        var pct = parseFloat(pay.percentage);
        totalPct += pct;
        var amt = Math.round(POOL.pool_total * pct / 100);
        var placeLabel = pay.place == 1 ? '1st' : pay.place == 2 ? '2nd' : pay.place == 3 ? '3rd' : pay.place + 'th';
        var extras = '';
        if (parseInt(pay.points) > 0) extras += ' <span style="color:#7c3aed;font-size:.75rem;font-weight:700">+' + parseInt(pay.points) + ' pts</span>';
        if (parseInt(pay.ticket_cents) > 0) extras += ' <span style="color:#b45309;font-size:.75rem;font-weight:700">🎟 ' + formatMoney(parseInt(pay.ticket_cents)) + '</span>';
        if (pay.prize_label) extras += ' <span style="color:#475569;font-size:.75rem;font-weight:600">' + escHtml(pay.prize_label) + '</span>';
        h += '<div class="pk-payout-row"><span class="pk-payout-place">' + placeLabel + (pct > 0 ? ' (' + pct + '%)' : '') + '</span><span>' + (pct > 0 ? '<span style="font-weight:600;color:#22c55e">' + formatMoney(amt) + '</span>' : '') + extras + '</span></div>';
    }
    // Bounty summary line when in play.
    if (isTourney() && (parseInt(SESSION.bounty_amount) > 0 || parseInt(SESSION.bounty_points) > 0)) {
        var bParts = [];
        if (parseInt(SESSION.bounty_amount) > 0) bParts.push(formatMoney(parseInt(SESSION.bounty_amount)) + ' cash');
        if (parseInt(SESSION.bounty_points) > 0) bParts.push(parseInt(SESSION.bounty_points) + ' pts');
        h += '<div class="pk-payout-row"><span class="pk-payout-place">🎯 Per knockout</span><span style="color:#0e7490;font-weight:600;font-size:.8rem">' + bParts.join(' + ') + '</span></div>';
    }
    h += '<div style="margin-top:.5rem;text-align:center"><button class="pk-act-btn" onclick="openSettings(\'payouts\')" style="font-size:.8rem">Edit in Settings</button></div>';
    return h;
}

// Which opt-in reward features are visible in the settings UI. Derived from the
// session/structure on load (a feature with data is on) and flipped by chips.
var REWARDS_UI = { bounty: false, pts: false, ticket: false, label: false, jackpot: false };
function initRewardsUI() {
    REWARDS_UI.bounty  = (parseInt(SESSION.bounty_amount) > 0 || parseInt(SESSION.bounty_points) > 0);
    REWARDS_UI.pts     = PAYOUTS.some(function(p) { return parseInt(p.points) > 0; });
    REWARDS_UI.ticket  = parseInt(SESSION.ticket_target_event_id) > 0 || PAYOUTS.some(function(p) { return parseInt(p.ticket_cents) > 0; });
    REWARDS_UI.label   = PAYOUTS.some(function(p) { return !!p.prize_label; });
    REWARDS_UI.jackpot = parseInt(SESSION.jackpot_amount) > 0;
}

// ─── Full-screen Game Settings editor ─────────────────────
// Lives in #settingsRoot (outside #app). Three tabs; all panes stay mounted
// (CSS show/hide) so saveSettings() and friends always find their elements.
var SETTINGS_OPEN = false;
var SETTINGS_TAB = 'game';
var SETTINGS_DIRTY = false;

function openSettings(tab) {
    SETTINGS_TAB = tab || 'game';
    SETTINGS_OPEN = true;
    SETTINGS_DIRTY = false;
    renderSettingsView();
    document.body.style.overflow = 'hidden';
    try { history.pushState({ settings: SETTINGS_TAB }, ''); } catch (e) {}
    if (isTourney()) {
        // First open fetches the saved-structure list; later opens must still
        // re-render it into the freshly-built (empty) select.
        if (!PAYOUT_STRUCTURES.length) loadPayoutStructures();
        else renderPayoutStructureSelect();
        populateTicketTargetSelect();
        updateBountyHint();
    }
}

async function closeSettings(skipHistory) {
    if (!SETTINGS_OPEN) return;
    if (SETTINGS_DIRTY) {
        var ok = await pkConfirm('Discard unsaved settings changes?', { okLabel: 'Discard', danger: true });
        if (!ok) {
            // Back-button path already popped the state; restore it so the next
            // Back still routes through this confirm.
            if (skipHistory) { try { history.pushState({ settings: SETTINGS_TAB }, ''); } catch (e) {} }
            return;
        }
    }
    SETTINGS_OPEN = false;
    SETTINGS_DIRTY = false;
    document.getElementById('settingsRoot').innerHTML = '';
    document.body.style.overflow = '';
    if (!skipHistory) { try { history.back(); } catch (e) {} }
}
window.addEventListener('popstate', function() { if (SETTINGS_OPEN) closeSettings(true); });
document.addEventListener('keydown', function(e) { if (e.key === 'Escape' && SETTINGS_OPEN) closeSettings(); });

function setSettingsTab(tab) {
    SETTINGS_TAB = tab;
    document.querySelectorAll('.pk-sv-tab').forEach(function(t) {
        t.classList.toggle('active', t.getAttribute('data-tab') === tab);
    });
    document.querySelectorAll('.pk-sv-pane').forEach(function(p) {
        p.classList.toggle('active', p.getAttribute('data-pane') === tab);
    });
}

function markSettingsDirty() {
    if (!SETTINGS_OPEN || SETTINGS_DIRTY) return;
    SETTINGS_DIRTY = true;
    var dot = document.getElementById('svDirty');
    if (dot) dot.style.display = '';
    var saved = document.getElementById('svSaved');
    if (saved) saved.style.display = 'none';
}

// Re-render the open editor in place (after Save / structure load / ticket
// actions), preserving the active tab and open state.
function refreshSettingsView() {
    if (!SETTINGS_OPEN) return;
    renderSettingsView();
    if (isTourney()) {
        renderPayoutStructureSelect();
        populateTicketTargetSelect();
        updateBountyHint();
    }
}

function renderSettingsView() {
    initRewardsUI();
    var hasTickets = TICKETS.outgoing && TICKETS.outgoing.length > 0;
    if (SETTINGS_TAB === 'tickets' && !hasTickets) SETTINGS_TAB = 'game';
    if (isCash() && SETTINGS_TAB !== 'game') SETTINGS_TAB = 'game';

    var h = '<div class="pk-sv-overlay">';
    // Fixed header: title + dirty dot + Save/Close (timer editor pattern).
    h += '<div class="pk-sv-head">';
    h += '<span class="pk-sv-title">&#9881; Game Settings <span id="svDirty" style="display:' + (SETTINGS_DIRTY ? '' : 'none') + '" title="Unsaved changes">&#9679;</span><span id="svSaved" style="display:none">Saved &#10003;</span></span>';
    h += '<div style="display:flex;gap:.5rem">';
    h += '<button class="pk-sv-save" onclick="saveSettings()">Save</button>';
    h += '<button class="pk-sv-close" onclick="closeSettings()">Close</button>';
    h += '</div></div>';

    // Tab strip
    h += '<div class="pk-sv-tabs">';
    h += '<button class="pk-sv-tab' + (SETTINGS_TAB === 'game' ? ' active' : '') + '" data-tab="game" onclick="setSettingsTab(\'game\')">Game</button>';
    h += '<button class="pk-sv-tab' + (SETTINGS_TAB === 'payouts' ? ' active' : '') + (isCash() ? ' disabled' : '') + '" data-tab="payouts" ' + (isCash() ? 'disabled title="Cash games have no payout structure"' : 'onclick="setSettingsTab(\'payouts\')"') + '>Payouts &amp; Rewards</button>';
    if (hasTickets && !isCash()) {
        h += '<button class="pk-sv-tab' + (SETTINGS_TAB === 'tickets' ? ' active' : '') + '" data-tab="tickets" onclick="setSettingsTab(\'tickets\')">Tickets <span class="pk-sv-badge">' + TICKETS.outgoing.length + '</span></button>';
    }
    h += '</div>';

    // Scrolling body: all panes mounted, active one visible.
    h += '<div class="pk-sv-body">';
    h += '<div class="pk-sv-pane' + (SETTINGS_TAB === 'game' ? ' active' : '') + '" data-pane="game">' + renderGamePane() + '</div>';
    h += '<div class="pk-sv-pane' + (SETTINGS_TAB === 'payouts' ? ' active' : '') + '" data-pane="payouts">' + renderPayoutsPane() + '</div>';
    h += '<div class="pk-sv-pane' + (SETTINGS_TAB === 'tickets' ? ' active' : '') + '" data-pane="tickets">' + renderTicketsPanel() + '</div>';
    h += '</div>';
    h += '</div>';

    var root = document.getElementById('settingsRoot');
    root.innerHTML = h;
    // Delegated dirty tracking: any input/change inside the editor.
    if (!root._dirtyBound) {
        root.addEventListener('input', markSettingsDirty);
        root.addEventListener('change', markSettingsDirty);
        root._dirtyBound = true;
    }
}

function renderGamePane() {
    var h = '';

    // ── Game ──
    h += '<div class="pk-cfg-section"><div class="pk-cfg-title">Game</div>';
    h += '<div class="pk-settings-grid">';
    h += '<div><label>Game Type</label><select id="cfg_game_type" onchange="previewGameType(this.value)"><option value="tournament"' + (isTourney()?' selected':'') + '>Tournament</option><option value="cash"' + (isCash()?' selected':'') + '>Cash Game</option></select></div>';
    h += '<div><label>Buy-in</label><div class="pk-money-wrap"><input type="number" id="cfg_buyin" value="' + Math.round(parseInt(SESSION.buyin_amount)/100) + '" step="1" min="0" oninput="updateBountyHint()"></div></div>';
    h += '</div></div>';

    // ── Chips, rebuys & add-ons (tournament only). Rebuys and Add-ons are
    // grouped boxes whose Yes/No header gates their own fields. ──
    h += '<div class="pk-cfg-section" id="cfgTourneyFields" style="' + (isCash()?'display:none':'') + '"><div class="pk-cfg-title">Chips, Rebuys &amp; Add-ons</div>';
    h += '<div class="pk-settings-grid" style="margin-bottom:.75rem">';
    h += '<div><label>Starting Chips</label><input type="number" id="cfg_chips" value="' + SESSION.starting_chips + '" min="1"></div>';
    h += '</div>';
    h += '<div class="pk-subgrids">';
    h += '<div class="pk-subbox">';
    h += '<div class="pk-subbox-head"><span>&#8635; Rebuys</span><label class="pk-subbox-check"><input type="checkbox" id="cfg_rebuy_allowed"' + (parseInt(SESSION.rebuy_allowed)?' checked':'') + ' onchange="toggleSubBox(\'rebuy\', this.checked ? 1 : 0)"> Allowed</label></div>';
    h += '<div class="pk-subbox-body' + (parseInt(SESSION.rebuy_allowed)?' on':'') + '" id="subbox_rebuy">';
    h += '<div><label>Rebuy</label><div class="pk-money-wrap"><input type="number" id="cfg_rebuy" value="' + Math.round(parseInt(SESSION.rebuy_amount)/100) + '" step="1" min="0"></div></div>';
    h += '<div><label>Max (0=unlimited)</label><input type="number" id="cfg_max_rebuys" value="' + SESSION.max_rebuys + '" min="0"></div>';
    h += '</div></div>';
    h += '<div class="pk-subbox">';
    h += '<div class="pk-subbox-head"><span>&#10133; Add-ons</span><label class="pk-subbox-check"><input type="checkbox" id="cfg_addon_allowed"' + (parseInt(SESSION.addon_allowed)?' checked':'') + ' onchange="toggleSubBox(\'addon\', this.checked ? 1 : 0)"> Allowed</label></div>';
    h += '<div class="pk-subbox-body' + (parseInt(SESSION.addon_allowed)?' on':'') + '" id="subbox_addon">';
    h += '<div><label>Add-on</label><div class="pk-money-wrap"><input type="number" id="cfg_addon" value="' + Math.round(parseInt(SESSION.addon_amount)/100) + '" step="1" min="0"></div></div>';
    h += '<div><label>Add-on Chips</label><input type="number" id="cfg_addon_chips" value="' + (parseInt(SESSION.addon_chips) || parseInt(SESSION.starting_chips) || 0) + '" min="0" title="Chips granted per add-on taken"></div>';
    h += '</div></div>';
    h += '</div></div>';

    // ── Tables ──
    h += '<div class="pk-cfg-section"><div class="pk-cfg-title">Tables</div>';
    h += '<div class="pk-settings-grid">';
    h += '<div><label>Number of Tables</label><input type="number" id="cfg_tables" value="' + SESSION.num_tables + '" min="1"></div>';
    h += '<div><label>Seats per Table</label><input type="number" id="cfg_seats_per_table" value="' + (SESSION.seats_per_table || 8) + '" min="2" max="20"></div>';
    h += '</div>';
    h += '<label class="pk-subbox-check" style="margin-top:.6rem;display:inline-flex"><input type="checkbox" id="cfg_auto_assign"' + (parseInt(SESSION.auto_assign_tables) ? ' checked' : '') + '> Auto-assign tables</label>';
    h += '</div>';
    return h;
}

function renderPayoutsPane() {
    var h = '';

    // ── Reward preset: saves/loads EVERYTHING on this tab (cash split,
    // points, ticket prizes, prize labels, bounty, jackpot entry) ──
    h += '<div class="pk-cfg-section"><div class="pk-cfg-title">Reward Preset</div>';
    h += '<div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap">';
    h += '<select id="payoutStructureSelect" onchange="onPayoutStructureChange()" style="flex:0 1 300px;min-width:160px;padding:.3rem .5rem;border:1.5px solid var(--border,#e2e8f0);border-radius:4px;font-size:.85rem"></select>';
    h += '<button onclick="loadPayoutStructure()" title="Apply the selected preset: payout split, points, ticket prizes, prize labels, bounty and jackpot entry">Load</button>';
    h += '<button onclick="savePayoutStructureAs()" title="Save everything on this tab as a named preset">Save As…</button>';
    h += '<button id="btnDelPayoutStructure" onclick="deletePayoutStructure()" style="display:none;color:#ef4444" title="Delete selected preset">Delete</button>';
    h += '<button id="btnDefPayoutStructure" onclick="setDefaultPayoutStructure()" style="display:none" title="Set as default (admin)">Set Default</button>';
    h += '</div>';
    h += '<div style="font-size:.75rem;color:#94a3b8;margin-top:.3rem">A preset stores everything on this tab — payout split, points, ticket prizes, prizes, bounty and jackpot entry. (The satellite target event stays per-game.)</div>';
    h += '</div>';

    // ── Bonus rewards: opt-in toggles keep a plain game plain ──
    h += '<div class="pk-cfg-section" id="cfgRewardsSection" style="' + (isCash()?'display:none':'') + '"><div class="pk-cfg-title">Bonus Rewards <span style="font-weight:400;text-transform:none;letter-spacing:0">— optional, tap to enable</span></div>';
    h += '<div class="pk-reward-chips">';
    h += '<button type="button" class="pk-reward-chip' + (REWARDS_UI.bounty ? ' on' : '') + '" id="chip_bounty" onclick="toggleReward(\'bounty\')">🎯 Bounties</button>';
    h += '<button type="button" class="pk-reward-chip' + (REWARDS_UI.pts ? ' on' : '') + '" id="chip_pts" onclick="toggleReward(\'pts\')">🏆 League points</button>';
    h += '<button type="button" class="pk-reward-chip' + (REWARDS_UI.ticket ? ' on' : '') + '" id="chip_ticket" onclick="toggleReward(\'ticket\')">🎟 Satellite seat</button>';
    h += '<button type="button" class="pk-reward-chip' + (REWARDS_UI.label ? ' on' : '') + '" id="chip_label" onclick="toggleReward(\'label\')">🎁 Prizes</button>';
    if (EVENT_LEAGUE_ID) {
        h += '<button type="button" class="pk-reward-chip' + (REWARDS_UI.jackpot ? ' on' : '') + '" id="chip_jackpot" onclick="toggleReward(\'jackpot\')">💎 Jackpots</button>';
    } else {
        h += '<button type="button" class="pk-reward-chip" disabled style="opacity:.4;cursor:default" title="Jackpots are league funds — this event has no league">💎 Jackpots</button>';
    }
    h += '</div>';
    // Bounty fields
    h += '<div class="pk-reward-body' + (REWARDS_UI.bounty ? ' on' : '') + '" id="rewardBody_bounty">';
    h += '<div class="pk-settings-grid">';
    h += '<div><label>Bounty per buy-in</label><div class="pk-money-wrap"><input type="number" id="cfg_bounty" value="' + Math.round((parseInt(SESSION.bounty_amount) || 0)/100) + '" step="1" min="0" oninput="updateBountyHint()"></div></div>';
    h += '<div><label>Bounty points per KO</label><input type="number" id="cfg_bounty_points" value="' + (parseInt(SESSION.bounty_points) || 0) + '" step="1" min="0"></div>';
    h += '</div>';
    h += '<div class="pk-bounty-hint" id="bountyHint"></div>';
    h += '</div>';
    // Points hint
    h += '<div class="pk-reward-body' + (REWARDS_UI.pts ? ' on' : '') + '" id="rewardBody_pts">';
    h += '<div style="font-size:.78rem;color:#64748b">Set points per place in the payout structure below. Points feed the league leaderboard and season championship.</div>';
    h += '</div>';
    // Ticket target
    h += '<div class="pk-reward-body' + (REWARDS_UI.ticket ? ' on' : '') + '" id="rewardBody_ticket">';
    h += '<div class="pk-settings-grid">';
    h += '<div><label>Winner\'s seat is good for</label><select id="cfg_ticket_target"><option value="0">— pick a target event —</option></select></div>';
    h += '</div>';
    h += '<div style="font-size:.78rem;color:#64748b;margin-top:.35rem">Set the Ticket $ per place below — that value is held out of this pool and funds the seat at the target game.</div>';
    h += '</div>';
    // Prize label hint
    h += '<div class="pk-reward-body' + (REWARDS_UI.label ? ' on' : '') + '" id="rewardBody_label">';
    h += '<div style="font-size:.78rem;color:#64748b">Add a prize per place below — trophy, bottle, bragging rights. Display only, no money math.</div>';
    h += '</div>';
    // Jackpot contribution (single league fund)
    h += '<div class="pk-reward-body' + (REWARDS_UI.jackpot ? ' on' : '') + '" id="rewardBody_jackpot">';
    h += '<div class="pk-settings-grid">';
    h += '<div><label>Jackpot entry ($)</label><div class="pk-money-wrap"><input type="number" id="cfg_jackpot" value="' + Math.round((parseInt(SESSION.jackpot_amount) || 0)/100) + '" step="1" min="0"></div></div>';
    h += '</div>';
    h += '<div style="font-size:.78rem;color:#64748b;margin-top:.35rem">An <b>optional</b> side entry collected on top of the buy-in (never pool money). Tick the 💎 box next to each player who\'s in; their entries feed the league\'s progressive jackpot when the game finishes. Pays out on a bad beat or royal flush. Current fund: 💎 <b>' + formatMoney(JACKPOTS.balance) + '</b>. Record a hit from the 💎 button on the game screen.</div>';
    h += '</div>';
    h += '</div>';

    // Payout editor (tournament only). Reward columns show only when their
    // feature chip is on (show-pts / show-ticket / show-label classes).
    var payoutCls = (REWARDS_UI.pts ? ' show-pts' : '') + (REWARDS_UI.ticket ? ' show-ticket' : '') + (REWARDS_UI.label ? ' show-label' : '');
    h += '<div class="pk-payout-editor pk-cfg-section' + payoutCls + '" id="cfgPayoutSection" style="' + (isCash()?'display:none':'') + '">';
    h += '<div class="pk-cfg-title">Payout Structure</div>';
    h += '<div style="display:flex;gap:.35rem;font-size:.68rem;color:#94a3b8;font-weight:700;text-transform:uppercase;letter-spacing:.03em;margin-bottom:.15rem"><span style="width:40px"></span><span style="flex:1;min-width:56px;max-width:140px">Cash %</span><span class="col-pts" style="flex:1;min-width:48px;max-width:140px">Pts</span><span class="col-ticket" style="flex:1;min-width:56px;max-width:140px">Ticket $</span><span class="col-label" style="flex:2;min-width:80px">Prize</span><span style="width:18px"></span></div>';
    h += '<div id="payoutRows">';
    for (var i = 0; i < PAYOUTS.length; i++) {
        h += payoutRowHtml(PAYOUTS[i].place, PAYOUTS[i].percentage, PAYOUTS[i].points, PAYOUTS[i].ticket_cents, PAYOUTS[i].prize_label);
    }
    h += '</div>';
    h += '<div style="display:flex;gap:.5rem;margin-top:.3rem;flex-wrap:wrap">';
    h += '<button onclick="addPayoutRow()">+ Add Place</button>';
    h += '<button onclick="autoSplitPayouts()">Auto Split</button>';
    h += '</div>';
    h += '<div id="payoutSum" style="margin-top:.3rem;font-size:.8rem;color:#64748b"></div>';
    h += '</div>';
    return h;
}

function payoutRowHtml(place, pct, points, ticket_cents, prize_label) {
    var lbl = prize_label ? String(prize_label).replace(/"/g, '&quot;').replace(/</g, '&lt;') : '';
    return '<div class="row" style="display:flex;gap:.35rem;align-items:center;flex-wrap:wrap;margin-bottom:.25rem">'
         + '<label style="font-size:.8rem;width:40px;flex-shrink:0">' + place + getOrdinal(place) + '</label>'
         + '<input type="number" class="payout-pct" value="' + (pct || 0) + '" step="0.1" min="0" max="100" data-place="' + place + '" oninput="updatePayoutSum()" title="Cash % of the pool" style="flex:1;min-width:56px;max-width:140px">'
         + '<input type="number" class="payout-pts" value="' + (parseInt(points) || 0) + '" step="1" min="0" data-place="' + place + '" title="League points for this place" style="flex:1;min-width:48px;max-width:140px">'
         + '<span class="pk-money-wrap payout-ticket-wrap" style="flex:1;min-width:56px;max-width:140px"><input type="number" class="payout-ticket" value="' + (ticket_cents ? Math.round(parseInt(ticket_cents)/100) : 0) + '" step="1" min="0" data-place="' + place + '" title="Entry ticket value ($) to the target event"></span>'
         + '<input type="text" class="payout-label" value="' + lbl + '" maxlength="60" placeholder="prize…" data-place="' + place + '" title="Custom prize (trophy, bottle, …)" style="flex:2;min-width:80px">'
         + '<button onclick="this.parentNode.remove();updatePayoutSum();markSettingsDirty()" style="color:#ef4444;background:transparent;border:none;cursor:pointer;font-size:1rem;flex-shrink:0">&times;</button></div>';
}

function addPayoutRow() {
    // Append a new row then reset all rows to the standard structure for the new count.
    var rows = document.querySelectorAll('#payoutRows .row');
    var nextPlace = rows.length + 1;
    var div = document.createElement('div');
    div.innerHTML = payoutRowHtml(nextPlace, 0, 0, 0, '');
    document.getElementById('payoutRows').appendChild(div.firstChild);
    autoSplitPayouts();  // only rewrites the % inputs; pts/ticket/prize are kept
    markSettingsDirty();
}

function autoSplitPayouts() {
    var inputs = document.querySelectorAll('.payout-pct');
    var count = inputs.length;
    if (count === 0) return;
    // Standard weighted tournament payout structures. Each row totals 100%.
    var structures = {
        1:  [100],
        2:  [65, 35],
        3:  [50, 30, 20],
        4:  [40, 25, 20, 15],
        5:  [38, 22, 17, 13, 10],
        6:  [33, 22, 16, 12, 10, 7],
        7:  [30, 20, 15, 12, 10, 8, 5],
        8:  [28, 18, 14, 12, 10, 8, 6, 4],
        9:  [26, 17, 13, 11, 10, 8, 6, 5, 4],
        10: [25, 16, 12, 10, 9, 8, 7, 6, 4, 3]
    };
    var pcts = structures[count];
    if (!pcts) {
        // For >10 places, anchor top 3 at [22,14,10] (=46) and split the remaining 54 across the rest.
        pcts = [22, 14, 10];
        var remaining = 54;
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

// "9th", "3rd" etc. from a possibly-string finish_position; '?' if unknown.
function ordinalLabel(n) {
    n = parseInt(n);
    if (!n || n < 1) return '?';
    return n + getOrdinal(n);
}

// Prize owed for a finishing place, computed live from the current pool + payout
// structure (same formula the payout card uses). 0 if the place isn't in the money.
function payoutForPlace(place) {
    place = parseInt(place);
    if (!place) return 0;
    for (var i = 0; i < PAYOUTS.length; i++) {
        if (parseInt(PAYOUTS[i].place) === place) {
            return Math.round((POOL.pool_total || 0) * parseFloat(PAYOUTS[i].percentage) / 100);
        }
    }
    return 0;
}

function previewGameType(val) {
    var hide = val === 'cash';
    ['cfgTourneyFields', 'cfgPayoutSection', 'ticketsPanel', 'cfgRewardsSection'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el) el.style.display = hide ? 'none' : '';
    });
}

// ─── Reward feature toggles (progressive disclosure) ──────
// Turning a feature ON reveals its fields/columns; turning it OFF clears the
// values too — the chip is the single switch for "this game uses X".
function toggleReward(key) {
    REWARDS_UI[key] = !REWARDS_UI[key];
    markSettingsDirty();
    var on = REWARDS_UI[key];
    var chip = document.getElementById('chip_' + key);
    if (chip) chip.classList.toggle('on', on);
    var body = document.getElementById('rewardBody_' + key);
    if (body) body.classList.toggle('on', on);
    var editor = document.getElementById('cfgPayoutSection');
    if (editor && (key === 'pts' || key === 'ticket' || key === 'label')) editor.classList.toggle('show-' + key, on);

    if (!on) {
        if (key === 'bounty') {
            var b = document.getElementById('cfg_bounty'); if (b) b.value = 0;
            var bp = document.getElementById('cfg_bounty_points'); if (bp) bp.value = 0;
        }
        if (key === 'jackpot') {
            var jb = document.getElementById('cfg_jackpot'); if (jb) jb.value = 0;
        }
        if (key === 'pts') document.querySelectorAll('#payoutRows .payout-pts').forEach(function(i) { i.value = 0; });
        if (key === 'ticket') {
            var t = document.getElementById('cfg_ticket_target'); if (t) t.value = '0';
            document.querySelectorAll('#payoutRows .payout-ticket').forEach(function(i) { i.value = 0; });
        }
        if (key === 'label') document.querySelectorAll('#payoutRows .payout-label').forEach(function(i) { i.value = ''; });
    } else {
        if (key === 'ticket') populateTicketTargetSelect();
        if (key === 'bounty') updateBountyHint();
    }
}

// ─── League jackpot hit recording ─────────────────────────
function openJackpotModal() {
    var b = document.getElementById('jpBalances');
    b.innerHTML = 'Fund: <b>' + formatMoney(JACKPOTS.balance) + '</b>';
    document.getElementById('jpRecipients').innerHTML = '';
    addJackpotRecipient();
    document.getElementById('jpAdjustRow').style.display = 'none';
    loadJackpotHistory();
    document.getElementById('jackpotModal').classList.add('open');
}
function closeJackpotModal() {
    document.getElementById('jackpotModal').classList.remove('open');
}

// ── Fund history (view + void, same correction model as the game ledger) ──
function loadJackpotHistory() {
    var box = document.getElementById('jpHistory');
    box.innerHTML = '<span style="color:#94a3b8">Loading…</span>';
    fetch('/checkin_dl.php?action=jackpot_log&session_id=' + SESSION.id)
        .then(function(r) { return r.json(); })
        .then(function(j) {
            if (!j.ok) { box.textContent = j.error || 'Error'; return; }
            if (typeof j.balance === 'number') {
                JACKPOTS.balance = j.balance;
                document.getElementById('jpBalances').innerHTML = 'Fund: <b>' + formatMoney(j.balance) + '</b>';
            }
            if (!j.entries.length) { box.innerHTML = '<span style="color:#94a3b8">No activity yet.</span>'; return; }
            var h = '';
            j.entries.forEach(function(e) {
                var amt = parseInt(e.amount);
                var icon = e.event_type === 'hit' ? '💥' : (e.event_type === 'adjust' ? '±' : '➕');
                var struck = parseInt(e.voided) ? 'text-decoration:line-through;opacity:.5;' : '';
                h += '<div style="display:flex;gap:.4rem;align-items:center;padding:.2rem 0;border-bottom:1px solid #f1f5f9">'
                   + '<span style="' + struck + 'flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="' + escHtml(e.detail || '') + '">'
                   + icon + ' ' + (e.player_name ? escHtml(e.player_name) + ' — ' : '') + escHtml((e.detail || '').substring(0, 60))
                   + ' <span style="color:#94a3b8">' + (e.created_at || '').substring(0, 10) + '</span></span>'
                   + '<span style="' + struck + 'font-weight:700;color:' + (amt >= 0 ? '#16a34a' : '#dc2626') + '">' + (amt >= 0 ? '+' : '−') + formatMoney(Math.abs(amt)) + '</span>'
                   + (parseInt(e.voided) ? '' : '<button onclick="voidJackpotEntry(' + e.id + ')" title="Void this entry (reverses its effect on the fund)" style="color:#ef4444;background:transparent;border:none;cursor:pointer;font-size:.9rem">&times;</button>')
                   + '</div>';
            });
            box.innerHTML = h;
        })
        .catch(function() { box.textContent = 'Failed to load history.'; });
}

async function voidJackpotEntry(entryId) {
    if (!(await pkConfirm('Void this jackpot entry? Its effect on the fund is reversed; the row stays visible struck-through.', { okLabel: 'Void', danger: true }))) return;
    postAction('void_jackpot_entry', { session_id: SESSION.id, entry_id: entryId }, function(j) {
        JACKPOTS.balance = j.balance;
        loadJackpotHistory();
    });
}

function jpToggleAdjust() {
    var row = document.getElementById('jpAdjustRow');
    row.style.display = row.style.display === 'none' ? 'flex' : 'none';
}

function confirmJackpotAdjust() {
    var amt = parseFloat((document.getElementById('jpAdjAmount') || {}).value || 0);
    if (!amt) { pkAlert('Enter a non-zero amount (negative to remove money).'); return; }
    postAction('adjust_jackpot', {
        session_id: SESSION.id,
        amount: amt,
        note: (document.getElementById('jpAdjNote') || {}).value || ''
    }, function(j) {
        JACKPOTS.balance = j.balance;
        document.getElementById('jpAdjAmount').value = '';
        document.getElementById('jpAdjNote').value = '';
        jpToggleAdjust();
        loadJackpotHistory();
    });
}
function addJackpotRecipient() {
    var wrap = document.getElementById('jpRecipients');
    var row = document.createElement('div');
    row.className = 'jp-recip-row';
    row.style.cssText = 'display:flex;gap:.4rem;align-items:center;margin-bottom:.35rem';
    var opts = '<option value="">— player —</option>';
    (PLAYERS || []).filter(function(p) { return !parseInt(p.removed); }).forEach(function(p) {
        opts += '<option value="' + escHtml(p.display_name) + '">' + escHtml(p.display_name) + '</option>';
    });
    row.innerHTML = '<select class="jp-name" style="flex:2;padding:.45rem .5rem;border:1.5px solid var(--border,#e2e8f0);border-radius:6px;font-size:.9rem">' + opts + '</select>'
        + '<span class="pk-money-wrap" style="flex:1;min-width:80px"><input type="number" class="jp-amount" step="0.01" min="0" placeholder="0"></span>'
        + '<button type="button" onclick="this.parentNode.remove()" style="color:#ef4444;background:transparent;border:none;cursor:pointer;font-size:1rem">&times;</button>';
    wrap.appendChild(row);
}
function confirmJackpotHit() {
    var data = { session_id: SESSION.id, jackpot_type: document.getElementById('jpType').value };
    var fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('action', 'record_jackpot_hit');
    fd.append('session_id', SESSION.id);
    fd.append('jackpot_type', data.jackpot_type);
    var any = false;
    document.querySelectorAll('#jpRecipients .jp-recip-row').forEach(function(row) {
        var name = (row.querySelector('.jp-name') || {}).value || '';
        var amt = parseFloat((row.querySelector('.jp-amount') || {}).value || 0);
        if (name && amt > 0) {
            fd.append('names[]', name);
            fd.append('amounts[]', amt);
            any = true;
        }
    });
    if (!any) { pkAlert('Pick at least one player and amount.'); return; }
    fetch('/checkin_dl.php', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(j) {
            if (!j.ok) { pkAlert(j.error || 'Error'); return; }
            JACKPOTS = j.jackpots || JACKPOTS;
            closeJackpotModal();
            pkAlert('Jackpot hit recorded. Remaining fund: ' + formatMoney(JACKPOTS.balance), { title: '💎 Jackpot' });
            refreshLogIfOpen();
        });
}

// Rebuys / Add-ons sub-box: the Allowed select shows or hides its fields.
function toggleSubBox(key, val) {
    var body = document.getElementById('subbox_' + key);
    if (body) body.classList.toggle('on', String(val) === '1');
}

// Live "each buy-in splits into pool + bounty" explainer under the bounty fields.
function updateBountyHint() {
    var el = document.getElementById('bountyHint');
    if (!el) return;
    var buyin = Math.max(0, Math.round(parseFloat((document.getElementById('cfg_buyin') || {}).value || 0)));
    var bounty = Math.max(0, Math.round(parseFloat((document.getElementById('cfg_bounty') || {}).value || 0)));
    if (bounty <= 0) { el.textContent = ''; return; }
    if (bounty >= buyin) {
        el.innerHTML = '<span style="color:#dc2626">Bounty must be less than the buy-in — it comes out of it.</span>';
        return;
    }
    el.textContent = 'Each $' + buyin + ' buy-in = $' + (buyin - bounty) + ' to the prize pool + $' + bounty + ' bounty on that player\'s head. Knock someone out, collect their bounty.';
}

// ─── Entry tickets (host view) ────────────────────────────
// Awarded tickets from THIS game: holder, value, status, target, with
// re-target / convert-to-cash actions for orphaned or unwanted tickets.
function renderTicketsPanel() {
    var out = (TICKETS && TICKETS.outgoing) || [];
    if (!out.length || isCash()) return '';
    var h = '<div id="ticketsPanel" style="margin-top:.75rem;padding-top:.6rem;border-top:1.5px solid #e2e8f0">';
    h += '<h3 style="margin:0 0 .5rem;font-size:.9rem">🎟 Entry Tickets Awarded</h3>';
    out.forEach(function(t) {
        var status = t.status;
        var target = t.target_title
            ? escHtml(t.target_title) + ' (' + (t.target_date || '') + ')'
            : '<span style="color:#dc2626;font-weight:700">⚠ target event cancelled</span>';
        h += '<div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;font-size:.82rem;padding:.3rem 0;border-bottom:1px solid #f1f5f9">';
        h += '<span style="font-weight:600">' + escHtml(t.display_name) + '</span>';
        h += '<span style="color:#b45309;font-weight:700">' + formatMoney(parseInt(t.value_cents)) + '</span>';
        h += '<span>&rarr; ' + target + '</span>';
        if (status === 'issued') {
            h += '<span style="margin-left:auto;display:flex;gap:.35rem">';
            h += '<button class="pk-act-btn" onclick="retargetTicket(' + t.id + ')">Re-target</button>';
            h += '<button class="pk-act-btn" onclick="convertTicket(' + t.id + ')">Convert to cash</button>';
            h += '</span>';
        } else {
            h += '<span style="margin-left:auto;color:' + (status === 'redeemed' ? '#16a34a' : '#64748b') + ';font-weight:700;font-size:.75rem;text-transform:uppercase">' + status + '</span>';
        }
        h += '</div>';
    });
    h += '</div>';
    return h;
}

var TARGET_EVENTS = null;
function loadTargetEvents(cb) {
    if (TARGET_EVENTS !== null) { cb(TARGET_EVENTS); return; }
    fetch('/checkin_dl.php?action=list_target_events&exclude_event_id=' + EVENT_ID)
        .then(function(r) { return r.json(); })
        .then(function(j) { TARGET_EVENTS = (j.ok && j.events) || []; cb(TARGET_EVENTS); })
        .catch(function() { cb([]); });
}

function populateTicketTargetSelect() {
    var sel = document.getElementById('cfg_ticket_target');
    if (!sel) return;
    loadTargetEvents(function(events) {
        var cur = parseInt(SESSION.ticket_target_event_id) || 0;
        sel.innerHTML = '<option value="0">— none —</option>';
        var haveCur = false;
        events.forEach(function(e) {
            var opt = document.createElement('option');
            opt.value = e.id;
            opt.textContent = e.title + ' (' + e.start_date + ')';
            if (parseInt(e.id) === cur) { opt.selected = true; haveCur = true; }
            sel.appendChild(opt);
        });
        if (cur && !haveCur) {
            // Current target isn't in the manageable list (edge) — keep it selectable.
            var opt = document.createElement('option');
            opt.value = cur; opt.textContent = 'Event #' + cur; opt.selected = true;
            sel.appendChild(opt);
        }
    });
}

async function retargetTicket(tid) {
    loadTargetEvents(async function(events) {
        if (!events.length) { pkAlert('No other poker events you manage to re-target to.'); return; }
        var lines = events.map(function(e, i) { return (i + 1) + ': ' + e.title + ' (' + e.start_date + ')'; });
        var picked = await pkPrompt('Re-target this ticket to:<br><br>' + lines.join('<br>') + '<br><br>Enter 1-' + events.length + ':');
        if (picked === null) return;
        var n = parseInt(picked, 10);
        if (!(n >= 1 && n <= events.length)) return;
        postAction('resolve_ticket', { ticket_id: tid, op: 'retarget', new_target_event_id: events[n - 1].id }, function(j) {
            TICKETS.outgoing = j.tickets || TICKETS.outgoing;
            if (j.pool) POOL = j.pool;
            if (j.players) PLAYERS = j.players;
            renderDashboard();
            refreshSettingsView();
        });
    });
}

async function convertTicket(tid) {
    if (!(await pkConfirm('Convert this ticket to a cash prize? The value is added to the holder\'s winnings from this game.'))) return;
    postAction('resolve_ticket', { ticket_id: tid, op: 'convert' }, function(j) {
        TICKETS.outgoing = j.tickets || TICKETS.outgoing;
        if (j.pool) POOL = j.pool;
        if (j.players) PLAYERS = j.players;
        renderDashboard();
        refreshSettingsView();
    });
}

// ─── ACTIONS ───────────────────────────────────────────
// (Settings open/close lives with the full-screen editor: openSettings/closeSettings.)

// ─── Payout structure UI ──────────────────────────────────
function loadPayoutStructures() {
    fetch('/checkin_dl.php?action=list_payout_structures')
        .then(function(r) { return r.json(); })
        .then(function(j) {
            if (!j.ok) return;
            PAYOUT_STRUCTURES = j.structures || [];
            renderPayoutStructureSelect();
        });
}

function renderPayoutStructureSelect() {
    var sel = document.getElementById('payoutStructureSelect');
    if (!sel) return;
    sel.innerHTML = '';
    var defaults = [], globals = [], personal = [];
    var leagueGroups = {};
    PAYOUT_STRUCTURES.forEach(function(s) {
        s._isDefault = parseInt(s.is_default);
        s._isGlobal  = parseInt(s.is_global);
        s._leagueId  = s.league_id ? parseInt(s.league_id) : 0;
        if (s._isDefault) defaults.push(s);
        else if (s._isGlobal) globals.push(s);
        else if (s._leagueId) {
            if (!leagueGroups[s._leagueId]) leagueGroups[s._leagueId] = { name: s.league_name || 'League', items: [] };
            leagueGroups[s._leagueId].items.push(s);
        } else personal.push(s);
    });
    // Leading blank so "unsaved custom" is a valid state
    var blank = document.createElement('option');
    blank.value = ''; blank.textContent = '— Custom (unsaved) —';
    sel.appendChild(blank);
    function addGroup(label, items) {
        if (!items.length) return;
        var grp = document.createElement('optgroup');
        grp.label = label;
        items.forEach(function(s) {
            var opt = document.createElement('option');
            opt.value = s.id;
            opt.textContent = s.name;
            opt.dataset.isDefault = s._isDefault;
            opt.dataset.isGlobal  = s._isGlobal;
            opt.dataset.leagueId  = s._leagueId || 0;
            opt.dataset.createdBy = s.created_by;
            grp.appendChild(opt);
        });
        sel.appendChild(grp);
    }
    addGroup('Default', defaults);
    addGroup('Global', globals);
    Object.keys(leagueGroups).forEach(function(lid) {
        addGroup('League: ' + leagueGroups[lid].name, leagueGroups[lid].items);
    });
    addGroup('My Structures', personal);
    if (CURRENT_STRUCTURE_ID) sel.value = String(CURRENT_STRUCTURE_ID);
    updatePayoutStructureButtons();
}

function updatePayoutStructureButtons() {
    var sel = document.getElementById('payoutStructureSelect');
    var delBtn = document.getElementById('btnDelPayoutStructure');
    var defBtn = document.getElementById('btnDefPayoutStructure');
    if (!sel) return;
    var opt = sel.options[sel.selectedIndex];
    if (!opt || !opt.value) {
        if (delBtn) delBtn.style.display = 'none';
        if (defBtn) defBtn.style.display = 'none';
        return;
    }
    var isDef  = opt.dataset.isDefault === '1';
    var isGlob = opt.dataset.isGlobal  === '1';
    if (defBtn) defBtn.style.display = (IS_ADMIN && !isDef) ? '' : 'none';
    if (delBtn) {
        if (isDef) delBtn.style.display = 'none';
        else if (isGlob) delBtn.style.display = IS_ADMIN ? '' : 'none';
        else delBtn.style.display = '';
    }
}

function onPayoutStructureChange() {
    updatePayoutStructureButtons();
}

function loadPayoutStructure() {
    var sel = document.getElementById('payoutStructureSelect');
    if (!sel || !sel.value) { pkAlert('Pick a structure first.'); return; }
    var sid = sel.value;
    var fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('action', 'load_payout_structure');
    fd.append('session_id', SESSION.id);
    fd.append('structure_id', sid);
    fetch('/checkin_dl.php', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(j) {
            if (!j.ok) { pkAlert(j.error || 'Error'); return; }
            PAYOUTS = j.payouts;
            POOL = j.pool || POOL;
            if (j.session) SESSION = j.session;  // recipe presets update bounty/jackpot too
            CURRENT_STRUCTURE_ID = parseInt(sid);
            // Redraw: the editor re-renders in place (keeps tab + open state),
            // and the dashboard refreshes underneath it.
            renderDashboard();
            refreshSettingsView();
        });
}

function savePayoutStructureAs() {
    // Gather current rows (a recipe-only freeroll with zero rows is fine —
    // the backend rejects saves where nothing at all is set).
    var inputs = document.querySelectorAll('.payout-pct');
    var total = 0;
    for (var i = 0; i < inputs.length; i++) total += parseFloat(inputs[i].value || 0);
    if (total > 100.001) { pkAlert('Total is ' + total.toFixed(1) + '% — cannot exceed 100%.'); return; }

    // Fetch leagues the user can save to
    fetch('/checkin_dl.php?action=get_payout_user_leagues')
        .then(function(r) { return r.json(); })
        .then(function(j) {
            var leagues = (j && j.ok) ? (j.leagues || []) : [];
            _continueSavePayoutStructureAs(leagues, inputs);
        })
        .catch(function() { _continueSavePayoutStructureAs([], inputs); });
}
// Open the save dialog: name field + a proper scope dropdown (Personal /
// Global for admins / each league the caller manages).
function _continueSavePayoutStructureAs(leagues, inputs) {
    var scope = document.getElementById('ssScope');
    scope.innerHTML = '';
    var opt = document.createElement('option');
    opt.value = 'p'; opt.textContent = 'Personal (only you)';
    scope.appendChild(opt);
    if (IS_ADMIN) {
        opt = document.createElement('option');
        opt.value = 'g'; opt.textContent = 'Global (all users)';
        scope.appendChild(opt);
    }
    leagues.forEach(function(l) {
        var o = document.createElement('option');
        o.value = 'l' + l.id; o.textContent = 'League — ' + l.name;
        scope.appendChild(o);
    });
    document.getElementById('ssName').value = '';
    document.getElementById('saveStructModal').classList.add('open');
    setTimeout(function() { document.getElementById('ssName').focus(); }, 30);
}

function closeSaveStruct() {
    document.getElementById('saveStructModal').classList.remove('open');
}

function confirmSaveStruct() {
    var name = (document.getElementById('ssName').value || '').trim();
    if (!name) { document.getElementById('ssName').focus(); return; }
    var scopeVal = document.getElementById('ssScope').value || 'p';
    var is_global = scopeVal === 'g' ? 1 : 0;
    var league_id = scopeVal.charAt(0) === 'l' ? parseInt(scopeVal.slice(1)) : 0;
    closeSaveStruct();

    var fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('action', 'save_payout_structure');
    fd.append('name', name);
    if (is_global) fd.append('is_global', '1');
    if (league_id) fd.append('league_id', league_id);
    // Session-level reward recipe (bounty / jackpot entry) rides with the preset.
    fd.append('bounty_amount', Math.max(0, Math.round(parseFloat((document.getElementById('cfg_bounty') || {}).value || 0))) * 100);
    fd.append('bounty_points', parseInt((document.getElementById('cfg_bounty_points') || {}).value || 0));
    fd.append('jackpot_amount', Math.max(0, Math.round(parseFloat((document.getElementById('cfg_jackpot') || {}).value || 0))) * 100);
    // Carry all four reward dimensions; the backend keeps rows where ANY is set.
    document.querySelectorAll('#payoutRows .row').forEach(function(row) {
        var pctEl = row.querySelector('.payout-pct');
        if (!pctEl) return;
        var place = parseInt(pctEl.dataset.place);
        if (!(place > 0)) return;
        fd.append('places[]', place);
        fd.append('percentages[]', parseFloat(pctEl.value || 0));
        fd.append('points[]', (row.querySelector('.payout-pts') || {}).value || 0);
        fd.append('tickets[]', (row.querySelector('.payout-ticket') || {}).value || 0);
        fd.append('labels[]', (row.querySelector('.payout-label') || {}).value || '');
    });
    fetch('/checkin_dl.php', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(j) {
            if (!j.ok) { pkAlert(j.error || 'Error'); return; }
            CURRENT_STRUCTURE_ID = parseInt(j.structure_id);
            loadPayoutStructures();
        });
}

async function deletePayoutStructure() {
    var sel = document.getElementById('payoutStructureSelect');
    if (!sel || !sel.value) return;
    if (!(await pkConfirm('Delete this payout structure?'))) return;
    var fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('action', 'delete_payout_structure');
    fd.append('structure_id', sel.value);
    fetch('/checkin_dl.php', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(j) {
            if (!j.ok) { pkAlert(j.error || 'Error'); return; }
            if (parseInt(sel.value) === CURRENT_STRUCTURE_ID) CURRENT_STRUCTURE_ID = 0;
            loadPayoutStructures();
        });
}

async function setDefaultPayoutStructure() {
    var sel = document.getElementById('payoutStructureSelect');
    if (!sel || !sel.value) return;
    if (!(await pkConfirm('Set this structure as the site default?'))) return;
    var fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('action', 'set_default_payout_structure');
    fd.append('structure_id', sel.value);
    fetch('/checkin_dl.php', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(j) {
            if (!j.ok) { pkAlert(j.error || 'Error'); return; }
            loadPayoutStructures();
        });
}

function changeStatus(status) {
    postAction('update_status', { session_id: SESSION.id, status: status }, function(j) {
        SESSION.status = j.status;
        renderDashboard();
    });
}

function toggleBuyin(pid) {
    // Buying in (not un-buying): offer to apply a matching entry ticket won at
    // a satellite that targeted this event.
    var player = PLAYERS.find(function(p) { return parseInt(p.id) === pid; });
    var buyingIn = player && !parseInt(player.bought_in);
    var ticket = null;
    if (buyingIn && TICKETS.incoming && TICKETS.incoming.length) {
        ticket = TICKETS.incoming.find(function(t) {
            if (t.user_id && player.user_id) return parseInt(t.user_id) === parseInt(player.user_id);
            return String(t.display_name || '').toLowerCase() === String(player.display_name || '').toLowerCase();
        }) || null;
    }
    var doPost = function(ticketId) {
        var data = { player_id: pid };
        if (ticketId) data.ticket_id = ticketId;
        postAction('toggle_buyin', data, function(j) {
            updatePlayer(j.player);
            POOL = j.pool;
            if (ticketId) loadSession();  // refresh TICKETS + log after redemption
            else refreshUI();
        });
    };
    if (ticket) {
        var val = parseInt(ticket.value_cents);
        var buyin = parseInt(SESSION.buyin_amount);
        var msg = '<b>' + escHtml(player.display_name) + '</b> holds a <b>' + formatMoney(val) + ' entry ticket</b> for this event. Apply it?';
        if (buyin > val)      msg += '<br><br>Collect the remaining <b>' + formatMoney(buyin - val) + '</b> in cash.';
        else if (val > buyin) msg += '<br><br>The extra <b>' + formatMoney(val - buyin) + '</b> joins this game\'s prize pool.';
        pkConfirm(msg, { title: 'Entry Ticket', okLabel: 'Apply Ticket' }).then(function(ok) {
            doPost(ok ? ticket.id : 0);
        });
        return;
    }
    doPost(0);
}

function toggleJackpot(pid) {
    postAction('toggle_jackpot', { player_id: pid }, function(j) {
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

function addAddon(pid) {
    updateAddons(pid, 1);
}
function removeAddon(pid) {
    updateAddons(pid, -1);
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

// Switch between the list / table / log views. Swaps only the content area so
// the toolbar persists and the segmented-control thumb can slide.
function setViewMode(mode) {
    if (mode === VIEW_MODE) return;
    VIEW_MODE = mode;
    var vc = document.getElementById('viewContent');
    if (vc) vc.innerHTML = renderViewContent();
    var seg = document.getElementById('viewSeg');
    if (seg) {
        var btns = seg.querySelectorAll('button');
        for (var i = 0; i < btns.length; i++) {
            btns[i].classList.toggle('active', btns[i].getAttribute('data-view') === mode);
        }
    }
    positionSegThumb('viewSeg', true);
    var addBtn = document.getElementById('addTableBtn');
    if (addBtn) addBtn.style.display = (mode === 'table') ? '' : 'none';
    if (mode === 'log') { renderLog(); fetchLog(); }
    else { updateBulkBar(); }
}

// Position a segmented control's sliding thumb under its active button. Pass
// animate=false to snap without a transition (used on full re-renders). Shared by
// the player filter (#filterSeg) and the view switcher (#viewSeg).
function positionSegThumb(segId, animate) {
    var seg = document.getElementById(segId);
    if (!seg) return;
    var thumb = seg.querySelector('.pk-seg-thumb');
    var active = seg.querySelector('button.active');
    if (!thumb || !active) return;
    if (!animate) {
        thumb.style.transition = 'none';
        thumb.style.width = active.offsetWidth + 'px';
        thumb.style.transform = 'translateX(' + active.offsetLeft + 'px)';
        void thumb.offsetWidth; // force reflow so the next change can transition
        thumb.style.transition = '';
    } else {
        thumb.style.width = active.offsetWidth + 'px';
        thumb.style.transform = 'translateX(' + active.offsetLeft + 'px)';
    }
}

// Reposition both toolbar sliders (e.g. after a full dashboard re-render or resize).
function positionAllSegThumbs(animate) {
    positionSegThumb('filterSeg', animate);
    positionSegThumb('viewSeg', animate);
}

function movePlayer(pid, newTable) {
    if (!newTable) return;
    if (newTable === 'unassign') {
        // Pull them off the table (frees the seat); they drop to the Unassigned group.
        postAction('set_table', { player_id: pid, table_number: '' }, function(j) {
            loadSession();
        });
        return;
    }
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
        if (parseInt(p.removed) || parseInt(p.eliminated) || !parseInt(p.bought_in)) return;
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
            return parseInt(p.table_number) === parseInt(t) && !parseInt(p.removed) && !parseInt(p.eliminated) && parseInt(p.bought_in);
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
            pkAlert(msg);
        } else {
            pkAlert('Tables are already balanced.');
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

async function breakUpTable(tableNum) {
    if (!(await pkConfirm('Break up Table ' + tableNum + '? All players will be distributed to the other tables.'))) return;
    postAction('break_up_table', { session_id: SESSION.id, table_number: tableNum }, function(j) {
        PLAYERS = j.players;
        SESSION = j.session;
        if (j.moves && j.moves.length > 0) {
            var msg = j.moves.length + ' player(s) moved:\n';
            for (var i = 0; i < j.moves.length; i++) {
                var m = j.moves[i];
                msg += m.display_name + ': Table ' + (m.old_table || '?') + ' \u2192 ' + m.new_table + '\n';
            }
            pkAlert(msg);
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
           + (numTables > 1 ? '<button class="pk-act-btn danger" onclick="breakUpTable(' + t + ')" style="font-size:.7rem;flex-shrink:0" title="Break up this table and distribute players to other tables">Break Up</button>' : '')
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
            if (isElim) {
                h += '<button class="pk-act-btn" onclick="uneliminate(' + p.id + ')" title="Undo eliminate">Undo</button>';
            } else if (parseInt(p.finish_position) === 1) {
                h += '<span title="Winner" style="color:#b8860b;font-weight:700">\ud83c\udfc6</span>';
            } else {
                if (!isCash()) {
                    h += '<button class="pk-act-btn" onclick="eliminatePlayer(' + p.id + ')" title="Eliminate" style="color:#ef4444;font-weight:700">&#10005;</button>';
                }
                h += '<select class="pk-tv-move" onchange="movePlayer(' + p.id + ', this.value)">';
                h += '<option value="">Move\u2026</option>';
                for (var mt = 1; mt <= numTables; mt++) {
                    if (mt !== t) h += '<option value="' + mt + '">Table ' + mt + ' (' + tables[mt].length + ')</option>';
                }
                h += '<option value="unassign">&#8212; Remove from table</option>';
                h += '</select>';
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

var elimPid = null;

function eliminatePlayer(pid) {
    var player = PLAYERS.find(function(p) { return parseInt(p.id) === pid; });
    if (player && !parseInt(player.bought_in)) {
        pkAlert('This player has not bought in yet. Buy them in before eliminating.');
        return;
    }
    // Place = number still in (including this player). Backend re-derives it authoritatively.
    var place = PLAYERS.filter(function(p) { return !parseInt(p.eliminated) && parseInt(p.bought_in); }).length;
    var amt = payoutForPlace(place);
    var msg = 'Eliminate <b>' + escHtml(player ? player.display_name : 'this player') + '</b> in <b>' + place + getOrdinal(place) + ' place</b>?';
    if (amt > 0) msg += '<br><br>They finish in the money and are owed <b>' + formatMoney(amt) + '</b>.';
    // Bounty games: optional (skippable) picker for who scored the knockout.
    if (isTourney() && (parseInt(SESSION.bounty_amount) > 0 || parseInt(SESSION.bounty_points) > 0)) {
        var others = PLAYERS.filter(function(p) {
            return !parseInt(p.eliminated) && parseInt(p.bought_in) && !parseInt(p.removed) && parseInt(p.id) !== pid;
        });
        if (others.length) {
            msg += '<br><br><label style="font-size:.8rem;font-weight:600;color:#0e7490">🎯 Knocked out by</label><br>'
                 + '<select id="elimBy" style="margin-top:.25rem;padding:.35rem .5rem;border:1.5px solid #e2e8f0;border-radius:6px;width:100%;font-size:.85rem">'
                 + '<option value="0">— not recorded —</option>'
                 + others.map(function(p) { return '<option value="' + p.id + '">' + escHtml(p.display_name) + '</option>'; }).join('')
                 + '</select>';
        }
    }
    elimPid = pid;
    document.getElementById('elimMsg').innerHTML = msg;
    document.getElementById('elimModal').classList.add('open');
}

function closeElim() {
    document.getElementById('elimModal').classList.remove('open');
    elimPid = null;
}

// Remove confirmations use the shared pkConfirm (pk-dialogs.js).
function removePlayerConfirm(pid) {
    var p = PLAYERS.find(function(p) { return parseInt(p.id) === pid; });
    pkConfirm('Remove <b>' + escHtml(p ? p.display_name : 'this player') + '</b> from the event?',
        { title: 'Remove Player', okLabel: 'Remove', danger: true })
        .then(function(ok) { if (ok) removePlayer(pid); });
}

function bulkRemoveConfirm() {
    var n = document.querySelectorAll('.pk-player-cb:checked').length;
    if (!n) return;
    pkConfirm('Remove ' + n + ' selected player' + (n === 1 ? '' : 's') + '?',
        { title: 'Remove Players', okLabel: 'Remove', danger: true })
        .then(function(ok) { if (ok) bulkAction('remove_player'); });
}

function showWinner(w) {
    var amt = (w && parseInt(w.payout)) || payoutForPlace(1);
    document.getElementById('winnerMsg').textContent = (w && w.display_name ? w.display_name : 'Winner') + ' wins!';
    document.getElementById('winnerSub').innerHTML = '1st place' + (amt > 0 ? ' &middot; ' + formatMoney(amt) : '') + '<br>The game is now finished.';
    document.getElementById('winnerModal').classList.add('open');
}

function closeWinner() {
    document.getElementById('winnerModal').classList.remove('open');
}

function confirmElim() {
    if (!elimPid) return;
    var pid = elimPid;
    var elimBy = parseInt((document.getElementById('elimBy') || {}).value || 0);
    closeElim();
    postAction('eliminate_player', { player_id: pid, finish_position: 0, eliminated_by: elimBy }, function(j) {
        updatePlayer(j.player);
        POOL = j.pool;
        if (j.winner) {
            // Last player standing was auto-crowned and the game finished.
            updatePlayer(j.winner);
            if (j.status) SESSION.status = j.status;
            renderDashboard();
            showWinner(j.winner);
        } else {
            refreshUI();
        }
    });
}

function uneliminate(pid) {
    postAction('uneliminate_player', { player_id: pid }, function(j) {
        // If the field re-opened (winner un-crowned, game reopened) resync everything.
        if (j.reopened) { loadSession(); return; }
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

// Cash game: adjust money in (+ to add, - to subtract) via an in-app dialog.
var cashAdjustPid = null;
var cashAdjustDir = 1;
function adjustMoney(pid, direction) {
    cashAdjustPid = pid;
    cashAdjustDir = direction;
    var p = PLAYERS.find(function(p) { return parseInt(p.id) === pid; });
    var name = p ? p.display_name : 'player';
    document.getElementById('cashAdjustTitle').textContent = (direction < 0 ? 'Remove Money — ' : 'Add Money — ') + name;
    var ok = document.getElementById('cashAdjustOk');
    ok.textContent = direction < 0 ? 'Remove' : 'Add';
    ok.style.background = direction < 0 ? '#dc2626' : '';
    // Default an add to the configured buy-in amount when set, else $20.
    var def = (direction > 0 && parseInt(SESSION.buyin_amount) > 0) ? (parseInt(SESSION.buyin_amount) / 100) : 20;
    var inp = document.getElementById('cashAdjustAmount');
    inp.value = def;
    inp.onkeydown = function(e) { if (e.key === 'Enter') applyCashAdjust(); };
    document.getElementById('cashAdjustModal').classList.add('open');
    inp.focus();
    inp.select();
}

function closeCashAdjust() {
    document.getElementById('cashAdjustModal').classList.remove('open');
    cashAdjustPid = null;
}

function applyCashAdjust() {
    if (!cashAdjustPid) return;
    var amt = parseFloat(document.getElementById('cashAdjustAmount').value || 0);
    if (isNaN(amt) || amt <= 0) return; // leave dialog open to correct
    var cents = Math.round(amt * 100);
    var pid = cashAdjustPid, dir = cashAdjustDir;
    closeCashAdjust();
    if (dir < 0) {
        var p = PLAYERS.find(function(p) { return parseInt(p.id) === pid; });
        var newVal = Math.max(0, (parseInt(p.cash_in) || 0) - cents);
        postAction('set_cashin', { player_id: pid, amount: newVal }, function(j) {
            updatePlayer(j.player); POOL = j.pool; refreshUI();
        });
    } else {
        postAction('add_cashin', { player_id: pid, amount: cents }, function(j) {
            updatePlayer(j.player); POOL = j.pool; refreshUI();
        });
    }
}

// Cash game: cash out
// Inline cash-out commit (desktop Cash Out column). Empty value reverts to playing.
function commitCashOut(pid, val) {
    var s = (val == null ? '' : String(val)).trim();
    var p = PLAYERS.find(function(p) { return parseInt(p.id) === pid; });
    var wasCashed = p && p.cash_out !== null && p.cash_out !== undefined;
    if (s === '') {
        if (wasCashed) undoCashout(pid);
        return;
    }
    var amt = Math.round(parseFloat(s) * 100);
    if (isNaN(amt) || amt < 0) return;
    postAction('set_cashout', { player_id: pid, cash_out: amt }, function(j) {
        updatePlayer(j.player); POOL = j.pool; refreshUI();
    });
}

function undoCashout(pid) {
    postAction('set_cashout', { player_id: pid, cash_out: '' }, function(j) {
        updatePlayer(j.player);
        POOL = j.pool;
        refreshUI();
    });
}

// Cash game: bust out = left the table with $0. Frees their seat; can re-enter later.
function bustOut(pid) {
    pkConfirm('Bust this player out? Records a $0 cash-out and frees their seat. Add cash to their Cash In later to bring them back into the game.', {okLabel:'Bust Out', danger:true}).then(function(ok){
        if (ok) commitCashOut(pid, 0);
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
    var failures = []; // {name, error} — a bulk run must never fail silently

    function nameOf(pid) {
        var p = (PLAYERS || []).find(function(x) { return parseInt(x.id) === pid; });
        return p ? p.display_name : ('#' + pid);
    }

    function processNext() {
        if (completed >= total) {
            loadSession(); // refresh from server after all done
            if (failures.length) {
                pkAlert(
                    (total - failures.length) + ' of ' + total + ' completed.<br><br>Failed:<br>'
                    + failures.map(function(f) { return '&bull; ' + escHtml(f.name) + ' — ' + escHtml(f.error); }).join('<br>'),
                    { title: 'Some players were skipped' }
                );
            } else if (typeof walkinToast === 'function') {
                walkinToast(total + ' player' + (total === 1 ? '' : 's') + ' updated');
            }
            return;
        }
        var pid = selected[completed];
        var params = { player_id: pid };
        if (action === 'add_walkin') params.session_id = SESSION.id;
        if (action === 'eliminate_player') params.finish_position = 0;
        // Bulk Buy In means "make sure they're in" — never toggle someone OFF
        // who was already bought in individually.
        if (action === 'toggle_buyin') params.set = 1;

        var fd = new FormData();
        fd.append('csrf_token', CSRF);
        fd.append('action', action);
        for (var k in params) fd.append(k, params[k]);

        fetch('/checkin_dl.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(j) {
                if (!j || !j.ok) failures.push({ name: nameOf(pid), error: (j && j.error) || 'Unknown error' });
                completed++;
                processNext();
            })
            .catch(function() {
                failures.push({ name: nameOf(pid), error: 'Network error' });
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
    if (!name) { pkAlert('Enter a name'); return; }
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

async function denyPlayer(pid) {
    if (!(await pkConfirm('Deny this player?'))) return;
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
    pkProgress('Saving settings…', 'Saving the game configuration and payout structure.');
    var data = {
        session_id: SESSION.id,
        buyin_amount: Math.max(0, Math.round(parseFloat(document.getElementById('cfg_buyin').value || 0))) * 100,
        game_type: document.getElementById('cfg_game_type').value,
        num_tables: parseInt(document.getElementById('cfg_tables').value || 1),
        seats_per_table: parseInt(document.getElementById('cfg_seats_per_table').value || 9),
        auto_assign_tables: (document.getElementById('cfg_auto_assign') || {}).checked ? 1 : 0,
    };
    if (document.getElementById('cfg_game_type').value === 'tournament') {
        data.rebuy_amount = Math.max(0, Math.round(parseFloat((document.getElementById('cfg_rebuy') || {}).value || 0))) * 100;
        data.addon_amount = Math.max(0, Math.round(parseFloat((document.getElementById('cfg_addon') || {}).value || 0))) * 100;
        data.starting_chips = parseInt((document.getElementById('cfg_chips') || {}).value || 5000);
        data.addon_chips = parseInt((document.getElementById('cfg_addon_chips') || {}).value || data.starting_chips);
        data.rebuy_allowed = (document.getElementById('cfg_rebuy_allowed') || {}).checked ? 1 : 0;
        data.max_rebuys = parseInt((document.getElementById('cfg_max_rebuys') || {}).value || 0);
        data.addon_allowed = (document.getElementById('cfg_addon_allowed') || {}).checked ? 1 : 0;
        data.bounty_amount = Math.max(0, Math.round(parseFloat((document.getElementById('cfg_bounty') || {}).value || 0))) * 100;
        data.bounty_points = parseInt((document.getElementById('cfg_bounty_points') || {}).value || 0);
        data.ticket_target_event_id = parseInt((document.getElementById('cfg_ticket_target') || {}).value || 0);
        data.jackpot_amount = Math.max(0, Math.round(parseFloat((document.getElementById('cfg_jackpot') || {}).value || 0))) * 100;
    } else {
        data.rebuy_amount = data.buyin_amount;
        data.addon_amount = 0;
        data.starting_chips = 0;
        data.addon_chips = 0;
        data.rebuy_allowed = 1;
        data.max_rebuys = 0;
        data.addon_allowed = 0;
    }
    postAction('update_config', data, function(j) {
        SESSION = j.session;
        POOL = j.pool;
        PAYOUTS = j.payouts || PAYOUTS;
        if (j.players) PLAYERS = j.players;
        // Save payouts too (tournament only) — all four reward dimensions ride
        // in parallel arrays keyed by row order.
        var rows = document.querySelectorAll('#payoutRows .row');
        if (rows.length > 0 && SESSION.game_type === 'tournament') {
            var places = [], pcts = [], pts = [], tickets = [], labels = [], pctSum = 0;
            rows.forEach(function(row) {
                var pctEl = row.querySelector('.payout-pct');
                if (!pctEl) return;
                places.push(pctEl.getAttribute('data-place'));
                pcts.push(pctEl.value);
                pctSum += parseFloat(pctEl.value || 0);
                pts.push((row.querySelector('.payout-pts') || {}).value || 0);
                tickets.push((row.querySelector('.payout-ticket') || {}).value || 0);
                labels.push((row.querySelector('.payout-label') || {}).value || '');
            });
            if (pctSum > 100) {
                pkProgressDone();
                pkAlert('Payout percentages total ' + pctSum.toFixed(1) + '% — cannot exceed 100%.');
                return;
            }
            var fd = new FormData();
            fd.append('csrf_token', CSRF);
            fd.append('action', 'update_payouts');
            fd.append('session_id', SESSION.id);
            for (var i = 0; i < places.length; i++) {
                fd.append('places[]', places[i]);
                fd.append('percentages[]', pcts[i]);
                fd.append('points[]', pts[i]);
                fd.append('tickets[]', tickets[i]);
                fd.append('labels[]', labels[i]);
            }
            fetch('/checkin_dl.php', { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(j2) {
                    if (!j2.ok) { pkProgressDone(); pkAlert(j2.error || 'Error saving payouts'); return; }
                    PAYOUTS = j2.payouts;
                    POOL = j2.pool;
                    settingsSaved();
                })
                .catch(function() { pkProgressDone(); pkAlert('Request failed'); });
        } else {
            settingsSaved();
        }
    }, function(errJ) {
        // update_config failed: drop the overlay so the error is readable.
        pkProgressDone();
        pkAlert(errJ.error || 'Error');
    });
}

// Post-save: refresh the dashboard and close the editor — Save means done.
function settingsSaved() {
    SETTINGS_DIRTY = false;
    renderDashboard();
    pkProgressDone();
    closeSettings();
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

function openHelp() {
    document.getElementById('helpModal').classList.add('open');
}

function closeHelp() {
    document.getElementById('helpModal').classList.remove('open');
}

// ─── Per-player ledger (money history + corrections) ───
var LEDGER_PID = null;

function ledgerBtn(pid) {
    return '<button type="button" class="pk-ledger-btn" title="Money history &amp; corrections" onclick="openLedger(' + pid + ')">&#128210;</button>';
}

// Small tap-friendly help marker; data-tip is shown on hover or focus/tap.
function tip(text) {
    return '<span class="pk-tip" tabindex="0" role="note" data-tip="' + escHtml(text) + '" onclick="event.stopPropagation()">?</span>';
}

// Render a log/ledger timestamp in the VIEWER's local timezone (so a host in a
// different timezone than the site sees their own clock). `ts` is a UTC ISO8601
// string; falls back to the server-rendered site-tz string if absent/unparseable.
function fmtLocalTime(ts, fallback) {
    if (!ts) return fallback || '';
    var d = new Date(ts);
    if (isNaN(d.getTime())) return fallback || ts;
    return d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
}

function openLedger(pid) {
    LEDGER_PID = pid;
    var p = PLAYERS.filter(function(x){ return x.id == pid; })[0];
    document.getElementById('ledgerTitle').textContent = 'Ledger — ' + (p ? p.display_name : '');
    document.getElementById('ledgerList').innerHTML = '<div class="pk-ledger-empty">Loading&hellip;</div>';
    document.getElementById('ledgerModal').classList.add('open');
    fetch('/checkin_dl.php?action=get_ledger&player_id=' + pid)
        .then(function(r){ return r.json(); })
        .then(function(j){ if (j.ok) renderLedger(j.ledger); else pkAlert(j.error || 'Could not load ledger'); })
        .catch(function(e){ console.error(e); });
}

function closeLedger() {
    LEDGER_PID = null;
    document.getElementById('ledgerModal').classList.remove('open');
}

// ─── Cash box reconciliation (cash games) ───
function openCashBox() {
    document.getElementById('cbTips').value = (parseInt(SESSION.tips) || 0) ? ((parseInt(SESSION.tips)) / 100) : '';
    document.getElementById('cbCounted').value = (SESSION.cash_counted !== null && SESSION.cash_counted !== undefined && SESSION.cash_counted !== '') ? (parseInt(SESSION.cash_counted) / 100) : '';
    cashBoxRecompute();
    document.getElementById('cashBoxModal').classList.add('open');
}

function closeCashBox() {
    document.getElementById('cashBoxModal').classList.remove('open');
}

function cashBoxOnTable() { return (parseInt(POOL.total_cash_in) || 0) - (parseInt(POOL.total_cash_out) || 0); }
function cashBoxCents(id) { var v = document.getElementById(id).value; return v === '' ? null : Math.round((parseFloat(v) || 0) * 100); }

function cashBoxRecompute() {
    var onTable = cashBoxOnTable();
    var tips = cashBoxCents('cbTips') || 0;
    var expected = onTable + tips;
    document.getElementById('cbCashIn').textContent = formatMoney(parseInt(POOL.total_cash_in) || 0);
    document.getElementById('cbCashOut').textContent = formatMoney(parseInt(POOL.total_cash_out) || 0);
    document.getElementById('cbOnTable').textContent = formatMoney(onTable);
    document.getElementById('cbExpected').textContent = formatMoney(expected);

    var counted = cashBoxCents('cbCounted');
    var os = document.getElementById('cbOverShort');
    var tipBtn = document.getElementById('cbTipSurplus');
    if (counted === null) {
        os.textContent = '—'; os.className = 'pk-cb-os';
        tipBtn.style.display = 'none';
        return;
    }
    var diff = counted - expected;
    if (diff === 0) { os.textContent = 'Even ✓'; os.className = 'pk-cb-os even'; }
    else if (diff > 0) { os.textContent = 'Over ' + formatMoney(diff); os.className = 'pk-cb-os over'; }
    else { os.textContent = 'Short ' + formatMoney(-diff); os.className = 'pk-cb-os short'; }
    // Offer to absorb a surplus as tips so the box squares.
    tipBtn.style.display = diff > 0 ? '' : 'none';
}

function cashBoxTipSurplus() {
    var counted = cashBoxCents('cbCounted');
    if (counted === null) return;
    var newTips = counted - cashBoxOnTable();      // tips that make expected == counted
    if (newTips < 0) newTips = 0;
    document.getElementById('cbTips').value = newTips / 100;
    cashBoxRecompute();
}

function saveCashBox() {
    var tips = cashBoxCents('cbTips') || 0;
    var countedC = cashBoxCents('cbCounted');
    postAction('set_cash_reconcile', { session_id: SESSION.id, tips: tips, counted: countedC === null ? '' : countedC }, function(j) {
        if (j.session) SESSION = j.session;
        renderDashboard();
        closeCashBox();
    });
}

function renderLedger(entries) {
    var el = document.getElementById('ledgerList');
    if (!el) return;
    if (!entries || !entries.length) {
        el.innerHTML = '<div class="pk-ledger-empty">No money entries yet.</div>';
        return;
    }
    var h = '';
    for (var i = 0; i < entries.length; i++) {
        var e = entries[i];
        var voided = parseInt(e.voided) === 1;
        var amt = (e.amount === null || e.amount === undefined) ? null : parseInt(e.amount);
        var amtHtml = '';
        if (amt !== null && amt !== 0) {
            amtHtml = '<span class="pk-ledger-amt ' + (amt > 0 ? 'pos' : 'neg') + '">' + (amt > 0 ? '+' : '-') + formatMoney(Math.abs(amt)) + '</span>';
        }
        h += '<div class="pk-ledger-row' + (voided ? ' voided' : '') + '">';
        h += '<span class="pk-ledger-time">' + escHtml(fmtLocalTime(e.time_ts, e.time)) + '</span>';
        h += amtHtml;
        h += '<span class="pk-ledger-detail">' + escHtml(e.detail || '') + ' <small>by ' + escHtml(e.actor || '') + '</small></span>';
        if (voided) {
            h += '<span class="pk-ledger-void-tag">Cleared</span>';
        } else {
            // Edit corrects a typo in place (e.g. 189 -> 180) without re-entry; only
            // money totals (cash in/out) carry an editable dollar amount.
            if ((e.event_type === 'cashin' || e.event_type === 'cashout') && amt !== null && amt > 0) {
                h += '<button class="pk-ledger-edit" onclick="editLedgerEntry(' + e.id + ',' + amt + ')">Edit</button>';
            }
            h += '<button class="pk-ledger-clear" onclick="voidLedgerEntry(' + e.id + ')">Clear</button>';
        }
        h += '</div>';
    }
    el.innerHTML = h;
}

function voidLedgerEntry(entryId) {
    pkConfirm('Clear this entry? Its amount will be reversed from the player\'s totals.', {okLabel:'Clear', danger:true}).then(function(ok){
        if (!ok) return;
        postAction('void_ledger_entry', { entry_id: entryId }, function(j){
            if (j.player) updatePlayer(j.player);
            if (j.pool) POOL = j.pool;
            if (j.ledger) renderLedger(j.ledger);
            refreshUI();
        });
    });
}

// Correct a wrong amount in place — keeps the entry in its original sequence
// position instead of forcing a clear + re-entry that lands at the bottom.
function editLedgerEntry(entryId, currentCents) {
    pkPrompt('Correct this amount to:', {
        'default': (currentCents / 100),
        inputType: 'number',
        okLabel: 'Save'
    }).then(function(val){
        if (val === null) return;
        var dollars = parseFloat(val);
        if (isNaN(dollars) || dollars <= 0) { pkAlert('Enter a dollar amount greater than zero.'); return; }
        postAction('edit_ledger_entry', { entry_id: entryId, new_amount: dollars }, function(j){
            if (j.player) updatePlayer(j.player);
            if (j.pool) POOL = j.pool;
            if (j.ledger) renderLedger(j.ledger);
            refreshUI();
        });
    });
}

// ─── Activity log ──────────────────────────────────────
function logTagLabel(t) {
    var m = { buyin:'Buy In', unbuyin:'Un-Buy', rebuy:'Rebuy', addon:'Add-on',
              cashin:'Cash In', cashout:'Cash Out', add:'Add', approve:'Approve',
              eliminate:'Out', uneliminate:'Back In', remove:'Remove', void:'Cleared', edit:'Edited' };
    return m[t] || t;
}

function renderLog() {
    var el = document.getElementById('logList');
    if (!el) return;
    if (!LOG || !LOG.length) {
        el.innerHTML = '<div class="pk-log-empty">No activity yet.</div>';
        return;
    }
    var h = '';
    for (var i = 0; i < LOG.length; i++) {
        var e = LOG[i];
        var by = e.actor ? '<span class="pk-log-by">by ' + escHtml(e.actor) + '</span>' : '';
        var vd = parseInt(e.voided) === 1 ? ' style="opacity:.55;text-decoration:line-through"' : '';
        h += '<div class="pk-log-row">'
           + '<span class="pk-log-time">' + escHtml(fmtLocalTime(e.time_ts, e.time)) + '</span>'
           + '<span class="pk-log-tag t-' + escHtml(e.event_type) + '">' + escHtml(logTagLabel(e.event_type)) + '</span>'
           + '<span class="pk-log-text"' + vd + '><b>' + escHtml(e.player_name || '') + '</b> ' + escHtml(e.detail || '') + ' ' + by + '</span>'
           + '</div>';
    }
    el.innerHTML = h;
}

function fetchLog() {
    fetch('/checkin_dl.php?action=get_log&event_id=' + EVENT_ID)
        .then(function(r) { return r.json(); })
        .then(function(j) { if (j.ok) { LOG = j.log || []; renderLog(); } })
        .catch(function(e) { console.error(e); });
}

function refreshLogIfOpen() { if (VIEW_MODE === 'log') fetchLog(); }

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
    positionSegThumb('filterSeg', true);
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
    renderPendingBanner();
}

// "Approve all" banner for a rush of QR sign-ups (shows at 2+ pending).
function renderPendingBanner() {
    var pend = (PLAYERS || []).filter(function(p) { return (p.approval_status || 'approved') === 'pending'; });
    var b = document.getElementById('pendingBanner');
    if (pend.length < 2) { if (b) b.remove(); return; }
    if (!b) {
        var anchor = document.getElementById('statsRow') || document.getElementById('statsCompact');
        if (!anchor) return;
        b = document.createElement('div');
        b.id = 'pendingBanner';
        anchor.insertAdjacentElement('afterend', b);
    }
    b.style.cssText = 'display:flex;align-items:center;gap:.6rem;flex-wrap:wrap;background:#fefce8;border:1px solid #fde68a;border-radius:8px;padding:.55rem .7rem;margin:.6rem 0';
    b.innerHTML = '<span style="flex:1;min-width:0;font-size:.85rem;color:#92400e;font-weight:600">&#9203; ' + pend.length + ' walk-ins awaiting approval</span>'
        + '<button class="pk-act-btn primary" id="approveAllBtn" onclick="approveAllPending()">Approve all</button>';
}

async function approveAllPending() {
    var pend = (PLAYERS || []).filter(function(p) { return (p.approval_status || 'approved') === 'pending'; });
    if (!pend.length) return;
    if (!(await pkConfirm('Approve all ' + pend.length + ' pending walk-ins? Each gets a notification with their table and seat.'))) return;
    var btn = document.getElementById('approveAllBtn');
    if (btn) { btn.disabled = true; btn.textContent = 'Approving…'; }
    var done = 0;
    (function next() {
        if (done >= pend.length) { walkinToast(done + ' player' + (done === 1 ? '' : 's') + ' approved'); return; }
        var p = pend[done++];
        postAction('approve_player', { player_id: p.id }, function(j) {
            PLAYERS = j.players;
            POOL = j.pool;
            refreshUI();
            next();
        });
    })();
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

// Keep both toolbar sliders aligned if the toolbar reflows on resize.
window.addEventListener('resize', function() { positionAllSegThumbs(false); });

// Auto-refresh every 10 seconds
// Pool stats update silently; the roster re-renders whenever the server content
// differs from local state (rebuys, cash-outs, eliminations, seat moves from
// another device — not just player-count changes). Skipped while the host is
// typing in a roster field so the re-render can't steal focus mid-edit.
function rosterEditInProgress() {
    var ae = document.activeElement;
    if (!ae) return false;
    var tag = (ae.tagName || '').toLowerCase();
    if (tag !== 'input' && tag !== 'textarea' && tag !== 'select') return false;
    return !!(ae.closest && ae.closest('#playerBody, #mobileList, .pk-table-grid, #payoutCard, #poolCard, #statsRow, #settingsRoot'));
}

// ─── Walk-in arrival alerts ────────────────────────────────
// The poll used to add QR self-registrations to the roster silently; at a busy
// table the host never noticed. Track known pending ids and chirp + toast when
// a new one appears.
var WALKIN_SEEN = null; // Set of pending player ids; null until first data

function pendingPlayerIds(list) {
    return (list || [])
        .filter(function(p) { return (p.approval_status || 'approved') === 'pending'; })
        .map(function(p) { return parseInt(p.id); });
}

function walkinToast(msg) {
    var t = document.createElement('div');
    t.textContent = msg;
    t.style.cssText = 'position:fixed;bottom:1.25rem;left:50%;transform:translateX(-50%);z-index:99999;'
        + 'background:#1e293b;color:#fff;padding:.6rem 1.1rem;border-radius:999px;font-size:.9rem;font-weight:600;'
        + 'box-shadow:0 6px 24px rgba(0,0,0,.3);cursor:pointer;max-width:90vw';
    t.onclick = function() { t.remove(); };
    document.body.appendChild(t);
    setTimeout(function() { t.remove(); }, 8000);
}

function walkinChirp() {
    try {
        var ctx = window._pkAC || (window._pkAC = new (window.AudioContext || window.webkitAudioContext)());
        if (ctx.state === 'suspended') { ctx.resume().catch(function() {}); }
        var o = ctx.createOscillator(), g = ctx.createGain();
        o.connect(g); g.connect(ctx.destination);
        o.frequency.setValueAtTime(880, ctx.currentTime);
        o.frequency.setValueAtTime(1175, ctx.currentTime + 0.12);
        g.gain.setValueAtTime(0.001, ctx.currentTime);
        g.gain.exponentialRampToValueAtTime(0.18, ctx.currentTime + 0.02);
        g.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.35);
        o.start(); o.stop(ctx.currentTime + 0.4);
    } catch (e) { /* audio blocked until first user gesture — toast still shows */ }
}

function checkWalkinArrivals(players) {
    var ids = pendingPlayerIds(players);
    if (WALKIN_SEEN === null) { WALKIN_SEEN = new Set(ids); return; }
    var fresh = ids.filter(function(id) { return !WALKIN_SEEN.has(id); });
    WALKIN_SEEN = new Set(ids);
    if (!fresh.length) return;
    var names = (players || [])
        .filter(function(p) { return fresh.indexOf(parseInt(p.id)) !== -1; })
        .map(function(p) { return p.display_name; });
    walkinToast('🚶 ' + names.join(', ') + ' just checked in via QR — awaiting approval');
    walkinChirp();
}

setInterval(function() {
    if (!SESSION) return;
    fetch('/checkin_dl.php?action=get_session&event_id=' + EVENT_ID)
        .then(function(r) { return r.json(); })
        .then(function(j) {
            if (!j.ok || !j.session) return;
            POOL = j.pool;
            checkWalkinArrivals(j.players); // alert even when the render below is skipped
            if (j.log) { LOG = j.log; if (VIEW_MODE === 'log') renderLog(); }
            var poolEl = document.getElementById('poolTotal');
            if (poolEl) {
                if (SESSION.game_type === 'cash') {
                    poolEl.innerHTML = '<small>Money In Play</small>' + formatMoney(POOL.total_cash_in);
                } else {
                    poolEl.innerHTML = '<small>Prize Pool</small>' + formatMoney(POOL.pool_total);
                }
            }
            var changed = JSON.stringify(j.players) !== JSON.stringify(PLAYERS)
                       || JSON.stringify(j.payouts) !== JSON.stringify(PAYOUTS)
                       || JSON.stringify(j.session) !== JSON.stringify(SESSION);
            if (changed && !rosterEditInProgress()) {
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
    if (remaining.length < 2) { pkAlert('Need at least 2 active players for a deal split.'); return; }

    var modal = document.getElementById('dealSplitModal');
    var body = document.getElementById('dealSplitBody');
    var poolTotal = POOL.pool_total;

    // Build chip entry form
    var h = '<div style="margin-bottom:1rem">';
    h += '<p style="font-size:.85rem;color:#64748b;margin-bottom:.75rem">Enter each remaining player\'s chip count, then choose a split method.</p>';
    h += '<div style="font-weight:600;margin-bottom:.5rem">Prize Pool: ' + formatMoney(poolTotal) + ' &mdash; ' + remaining.length + ' players remaining</div>';
    if (parseInt(SESSION.bounty_amount) > 0 || PAYOUTS.some(function(p) { return parseInt(p.ticket_cents) > 0; })) {
        h += '<div style="font-size:.75rem;color:#94a3b8;margin-bottom:.5rem">Chop math uses the cash prize pool only — bounties and ticket prizes are excluded.</div>';
    }
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
        pkAlert('Enter chip counts for all remaining players.');
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
