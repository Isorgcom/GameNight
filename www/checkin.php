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

// Leagues this host may bind the event to, for the League select in Setup.
// Same rule the server enforces in update_config: owner/manager of that league,
// or site admin. The event's CURRENT league is always included even without a
// role on it, so a co-host can see where the game sits (and re-save) without
// being able to move it somewhere new.
$assignable_leagues = [];
$_curLeague = (int)($event['league_id'] ?? 0);
foreach (user_leagues((int)$current['id']) as $_lg) {
    if (in_array($_lg['role'], ['owner', 'manager'], true)) {
        $assignable_leagues[(int)$_lg['id']] = $_lg['name'];
    }
}
if ($isAdmin) {
    foreach ($db->query('SELECT id, name FROM leagues ORDER BY LOWER(name)') as $_lg) {
        $assignable_leagues[(int)$_lg['id']] = $_lg['name'];
    }
}
// Anyone holding a role on a league can also unbind the event (set None), so the
// picker is offered whenever they have at least one — even if that one is the
// league the event is already on.
$_canMoveLeague = $isAdmin || count($assignable_leagues) > 0;
// The current league is always listed, even without a role on it, so a co-host
// can see where the game sits. Marked so it doesn't read as a free choice.
if ($_curLeague && !isset($assignable_leagues[$_curLeague])) {
    $_lgName = $db->prepare('SELECT name FROM leagues WHERE id = ?');
    $_lgName->execute([$_curLeague]);
    $assignable_leagues[$_curLeague] = ($_lgName->fetchColumn() ?: 'League #' . $_curLeague) . ' (current)';
}

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

// Timer flags for the Setup → Timer tab: whether the Timer button opens the
// Tournament Timer (layout) display, and which layout it is bound to.
$use_beta_timer = 0;
$event_layout_id = null;
$event_layout_key = null;
$is_tournament_session = $session && (($session['game_type'] ?? '') === 'tournament');
if ($session) {
    try {
        $ubq = $db->prepare('SELECT use_beta, layout_id, layout_builtin FROM timer_state WHERE session_id = ?');
        $ubq->execute([(int)$session['id']]);
        $ubrow = $ubq->fetch();
        $use_beta_timer = (int)($ubrow['use_beta'] ?? 0);
        $event_layout_id = (int)($ubrow['layout_id'] ?? 0) ?: null;
        $event_layout_key = ($ubrow['layout_builtin'] ?? null) ?: null;
    } catch (Exception $e) {}
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Game — <?= htmlspecialchars($event['title']) ?> — <?= htmlspecialchars($site_name) ?></title>
    <link rel="stylesheet" href="/style.css?v=<?= htmlspecialchars(APP_VERSION . '.' . (@filemtime(__DIR__ . '/style.css') ?: 0)) ?>">
    <link rel="stylesheet" href="/event_setup.css?v=<?= htmlspecialchars(APP_VERSION . '.' . (@filemtime(__DIR__ . '/event_setup.css') ?: 0)) ?>">
    <script src="/event_blinds.js?v=<?= htmlspecialchars(APP_VERSION . '.' . (@filemtime(__DIR__ . '/event_blinds.js') ?: 0)) ?>" defer></script>
    <?php if ($is_tournament_session): ?>
    <link rel="stylesheet" href="/timer_layouts.css?v=<?= htmlspecialchars(APP_VERSION . '.' . (@filemtime(__DIR__ . '/timer_layouts.css') ?: 0)) ?>">
    <?php endif; ?>
    <style>
    .pk-wrap{padding:0 1rem 2rem;max-width:100%}
    /* Sticks BELOW the site nav, which is also sticky at top:0 but with a
       higher z-index — pinned at the same offset the nav simply painted over
       this bar, hiding the event title and the Timer / QR / Finish controls
       the moment the page scrolled. --pk-nav-h is measured live (the nav
       collapses on narrow screens and grows when its links row opens). */
    .pk-header{background:var(--dark,#0f172a);color:#fff;padding:.75rem 1.5rem;display:flex;align-items:center;gap:1.5rem;flex-wrap:wrap;position:sticky;top:var(--pk-nav-h,0px);z-index:50}
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
    /* Grid items default to min-width:auto, so the 1fr column refuses to shrink
       below the player table's min-content width and pushes the whole page into
       a horizontal scroll between ~768px and 1000px. .pk-sidebar already sets
       min-width:0; the content column was the one that was missed. With it, the
       column tracks the viewport and the table scrolls inside .pk-table-wrap's
       own overflow-x:auto, which is what that container exists for. */
    .pk-grid > div{min-width:0}
    /* The Payouts view keeps the sidebar exactly where it sits on every other
       screen — moving the Prize Pool card into the content area made it jump.
       Only the sidebar's condensed Payouts card hides, because the view shows
       that same ladder in full. */
    body.view-payouts #payoutCard{display:none}
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
    /* Toolbar groups. Each is one flex item of .pk-toolbar, so the row can only
       break BETWEEN them, never through the middle of a control cluster.
       .pk-tb-controls still wraps internally, but only once a phone genuinely
       cannot fit filters + Setup + views on one line. */
    .pk-tb-group{display:flex;align-items:center;gap:.5rem;min-width:0}
    .pk-tb-controls{flex-wrap:wrap}
    /* Per-view controls, now part of the page rather than the toolbar. */
    .pk-filter-bar{display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;margin-bottom:.6rem}
    /* No margin-left:auto here any more. In a wrapping flex row it pins the
       button to the right end of whatever line it lands on, so it drifted as the
       window resized; the one remaining caller was already cancelling it inline. */
    .pk-help-btn{flex:0 0 auto;padding:.4rem .8rem;border-radius:6px;border:1.5px solid #2563eb;background:#eff6ff;color:#1d4ed8;font-weight:700;font-size:.8rem;cursor:pointer;line-height:1;min-height:auto}
    .pk-help-btn:hover{background:#dbeafe}
    .pk-help-content{text-align:left;max-height:70vh;overflow-y:auto}
    .pk-help-content h4.pk-cfg-title{margin:.9rem 0 .2rem}
    .pk-help-content h5{margin:.9rem 0 .25rem;font-size:.9rem;color:#0f172a}
    .pk-help-content h5:first-child{margin-top:0}
    .pk-help-content p{margin:0;font-size:.85rem;color:#475569;line-height:1.45}
    /* .pk-seg / .pk-seg-thumb / the slide-in keyframes now live in style.css,
       with the behaviour in pk-seg.js — see CLAUDE.md "UI Conventions". Only
       page-specific tweaks to the control belong here. */

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
    .pk-sort-btn{background:none;border:0;padding:0 .1rem;margin:0;min-height:24px;font:inherit;color:inherit;text-transform:inherit;letter-spacing:inherit;cursor:pointer;white-space:nowrap}
    .pk-table th.pk-sortable{padding-top:.3rem;padding-bottom:.3rem}
    /* Keyboard focus rings on the console's own controls (the segmented
       strips get theirs in style.css). */
    .pk-sort-btn:focus-visible,.pk-act-btn:focus-visible,.pk-mobile-more:focus-visible,.pk-counter button:focus-visible,
    .pk-ledger-btn:focus-visible,.pk-addon-btn:focus-visible,.pk-addon-count:focus-visible,.pk-bust-btn:focus-visible,
    .pk-modal-actions button:focus-visible,.pk-actions button:focus-visible,.pk-actions a:focus-visible,.pk-btn-back:focus-visible,
    .pk-toolbar .pk-btn-add:focus-visible,.pk-toolbar .pk-btn-setup:focus-visible,.pk-preset-menu button:focus-visible,
    .pk-preset-morebtn:focus-visible,.pk-sv-save:focus-visible,.pk-sv-close:focus-visible,.pk-reward-chip:focus-visible,
    .pk-mobile-actions button:focus-visible,.pk-bulk-bar button:focus-visible,.pk-btn-green:focus-visible,.pk-btn-view-toggle:focus-visible{outline:2px solid #2563eb;outline-offset:2px}
    .pk-actions button:focus-visible,.pk-actions a:focus-visible,.pk-btn-back:focus-visible,.pk-sv-save:focus-visible,.pk-sv-close:focus-visible{outline-color:#93c5fd}
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
    .pk-bust-btn{border:1px solid #fecaca;background:#fff;border-radius:4px;font-size:.9rem;line-height:1;padding:.15rem .3rem;cursor:pointer;margin-left:.2rem;flex:0 0 auto;min-width:24px;min-height:24px}
    .pk-bust-btn:hover{background:#fee2e2}
    .pk-cash-input::-webkit-outer-spin-button,.pk-cash-input::-webkit-inner-spin-button,.pk-co-input::-webkit-outer-spin-button,.pk-co-input::-webkit-inner-spin-button{-webkit-appearance:none;margin:0}

    .pk-act-btn{background:#f1f5f9;border:1px solid #e2e8f0;cursor:pointer;font-size:.75rem;font-weight:600;padding:.25rem .55rem;margin:.1rem .15rem .1rem 0;border-radius:5px;color:#475569;white-space:nowrap}
    .pk-act-btn:hover{background:#e2e8f0;color:#0f172a}
    .pk-act-btn.primary{background:#15803d;border-color:#15803d;color:#fff}
    .pk-act-btn.primary:hover{background:#166534;color:#fff}
    .pk-act-btn.danger{background:#dc2626;border-color:#dc2626;color:#fff}
    .pk-act-btn.danger:hover{background:#b91c1c;color:#fff}
    .pk-cashout-cell{background:transparent;border:none;border-bottom:1px dashed #94a3b8;cursor:pointer;font-size:.85rem;font-weight:600;color:#0f172a;padding:.1rem .15rem}
    .pk-cashout-cell:hover{color:#2563eb;border-bottom-color:#2563eb}
    .pk-profit-pos{color:#15803d;font-weight:600}
    .pk-profit-neg{color:#dc2626;font-weight:600}
    .pk-profit-zero{color:#64748b;font-weight:600}

    .pk-sidebar{display:flex;flex-direction:column;gap:.75rem;min-width:0}
    .pk-card{background:var(--surface,#fff);border:1.5px solid var(--border,#e2e8f0);border-radius:8px;padding:.75rem;min-width:0;box-sizing:border-box;word-break:break-word}
    .pk-card h3{margin:0 0 .6rem;font-size:.85rem;text-transform:uppercase;letter-spacing:.04em;color:#64748b}
    .pk-pool-row{display:flex;justify-content:space-between;padding:.2rem 0;font-size:.8rem;gap:.25rem}
    .pk-pool-row.total{font-weight:700;font-size:.95rem;border-top:2px solid var(--border,#e2e8f0);margin-top:.3rem;padding-top:.4rem;color:#15803d}
    .pk-payout-row{display:flex;justify-content:space-between;padding:.15rem 0;font-size:.8rem;gap:.25rem}
    .pk-payout-place{font-weight:600}

    /* Payouts view — the full-width read-only counterpart to the sidebar card. */
    /* Setup — deliberately NOT a segment. Outlined in the accent colour so it
       reads as a distinct, consequential action beside the view strip, and
       filled when it's the active view. */
    /* Selectors carry .pk-toolbar to outrank `.pk-toolbar button`, which sets a
       transparent border and would otherwise erase the outline. */
    .pk-toolbar .pk-btn-setup{display:inline-flex;align-items:center;gap:.1rem;border:1.5px solid var(--accent,#2563eb);background:#fff;color:var(--accent,#2563eb);
        border-radius:8px;padding:.4rem .85rem;font-size:.8rem;font-weight:700;cursor:pointer;white-space:nowrap;
        box-shadow:0 1px 2px rgba(15,23,42,.08);transition:background .15s,color .15s}
    .pk-toolbar .pk-btn-setup:hover{background:#eff6ff}
    .pk-toolbar .pk-btn-setup.active{background:var(--accent,#2563eb);color:#fff;box-shadow:0 1px 3px rgba(37,99,235,.5)}

    /* "This game isn't set up yet" prompt — the loudest signal, shown only
       while it's true and gone the moment the game is configured. Amber, not
       blue: in blue it read as an informational tip and disappeared into the
       page. This is the app's warning palette, matching the ticket-target
       warning and the seat-cutoff divider. */
    .pk-setup-cta{display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;margin:0 0 .6rem;padding:.7rem .9rem;
        background:#fffbeb;border:1.5px solid #f59e0b;border-left-width:5px;border-radius:8px}
    .pk-setup-cta-txt{flex:1 1 240px;min-width:0;font-size:.82rem;color:#92400e;line-height:1.4}
    .pk-setup-cta-txt b{display:block;font-size:.9rem;color:#b45309}
    .pk-setup-cta button{flex:0 0 auto;border:none;background:#b45309;color:#fff;border-radius:6px;
        padding:.45rem 1rem;font-size:.82rem;font-weight:700;cursor:pointer}
    .pk-setup-cta button:hover{background:#92400e}

    /* View/pane transitions live in style.css — see pk-seg.js. */
    .pk-pv-sub{font-size:.8rem;color:#64748b;margin:0 0 .6rem}
    /* Cards stack in the content column; the sidebar keeps its own cards in
       the position they hold on every other view. */
    #viewContent > .pk-card + .pk-card{margin-top:.75rem}
    .pk-pv-table{width:100%;font-size:.82rem}
    .pk-pv-table th{text-align:left;font-size:.68rem;text-transform:uppercase;letter-spacing:.03em;color:#64748b;padding:.3rem .4rem;border-bottom:1px solid var(--border,#e2e8f0)}
    .pk-pv-table td{padding:.35rem .4rem;border-bottom:1px solid #f1f5f9}
    .pk-pv-foot{margin-top:.75rem;text-align:center}
    /* Five segments don't fit a phone with labels. Collapse to icons at the
       same breakpoint where the header buttons lose theirs, so the two rows
       degrade together. Titles keep the tooltips. Setup collapses with them and
       matches their metrics — its outline still sets it apart from the pills,
       so it stays findable without spending a label's worth of width. */
    @media(max-width:768px){
        .pk-view-seg .pk-seg-label, .pk-btn-setup .pk-seg-label{display:none}
        .pk-view-seg button{padding:.4rem .6rem;font-size:1rem}
        .pk-toolbar .pk-btn-setup{padding:.4rem .6rem;font-size:1rem}
    }

    /* Full-screen Game Settings editor (timer-editor pattern: fixed header,
       tab strip, scrolling body; all panes stay mounted). */
    /* Settings renders inside the content area like the other views, so the
       page keeps its header, stats row and toolbar. */
    /* The editor is a LAYER over the console, not another card in it. White on
       white with a 1px hairline read as more page content: the eye could not
       tell where the console stopped and the editor began. Three cues now say
       "panel": a dark chrome header (the same navy as the page header), real
       elevation, and a gap above so it stops butting into the toolbar. */
    .pk-sv-inline{display:flex;flex-direction:column;background:var(--surface,#fff);border:1.5px solid #cbd5e1;border-radius:12px;overflow:hidden;margin-top:.9rem;box-shadow:0 14px 34px rgba(15,23,42,.16),0 2px 6px rgba(15,23,42,.08)}
    .pk-sv-head{display:flex;justify-content:space-between;align-items:center;gap:.75rem;padding:.75rem 1.25rem;background:var(--dark,#0f172a);flex-shrink:0}
    .pk-sv-title{font-size:1rem;font-weight:700;color:#fff}
    .pk-sv-title #svDirty{color:#f59e0b;font-size:.8rem;vertical-align:middle}
    .pk-sv-title #svSaved{color:#16a34a;font-size:.8rem;font-weight:600;margin-left:.4rem}
    .pk-sv-save{padding:.45rem 1.4rem;background:var(--accent,#2563eb);color:#fff;border:none;border-radius:6px;font-weight:600;font-size:.85rem;cursor:pointer}
    .pk-sv-close{padding:.45rem 1rem;background:transparent;color:#cbd5e1;border:1.5px solid #475569;border-radius:6px;font-weight:600;font-size:.85rem;cursor:pointer}
    .pk-sv-close:hover{background:#1e293b;color:#fff}
    /* Setup's tabs are the same segmented control as the toolbar sliders, thumb
       and all. They switch between panes exactly like the view strip switches
       between views, so an underlined-tab idiom here was a third way of saying
       the same thing. Buttons keep .pk-sv-tab so the gating code that enables
       and disables them by data-tab is unchanged. */
    .pk-sv-tabs{display:flex;padding:.6rem 1.25rem;background:#f1f5f9;border-bottom:1.5px solid var(--border,#e2e8f0);flex-shrink:0}
    .pk-sv-seg{max-width:100%;flex-wrap:wrap}
    .pk-sv-seg .pk-sv-tab{padding:.42rem .9rem;font-size:.82rem}
    /* Disabled (cash game has no payout structure) must not look selectable, and
       must not light up on hover the way a live segment does. */
    .pk-sv-seg .pk-sv-tab.disabled,
    .pk-sv-seg .pk-sv-tab:disabled{opacity:.4;cursor:default}
    .pk-sv-seg .pk-sv-tab.disabled:hover,
    .pk-sv-seg .pk-sv-tab:disabled:hover{color:#475569}
    /* On the active segment the thumb is behind the label, so the amber badge
       needs to hold up against the accent fill rather than the grey track. */
    .pk-sv-seg .pk-sv-tab.active .pk-sv-badge{background:#fff;color:#b45309}
    .pk-sv-badge{background:#fde68a;color:#92400e;border-radius:999px;font-size:.68rem;padding:.05rem .4rem;font-weight:700}
    /* Was flex:1 inside a full-height fixed overlay; inline it grows with its
       content and the page scrolls, like every other view. */
    .pk-sv-body{padding:1rem 1.25rem 1.5rem}
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
    /* Each Setup section is a card with a grey title band, so Game, Chips &
       Rebuys and Tables read as three things rather than one run of fields. */
    .pk-cfg-section{margin-top:.9rem;padding:0 .9rem .9rem;border:1.5px solid var(--border,#e2e8f0);border-radius:10px;background:var(--surface,#fff)}
    .pk-cfg-section:first-child{margin-top:0}
    .pk-cfg-section>.pk-cfg-title{margin:0 -.9rem .85rem;padding:.5rem .9rem;background:#f1f5f9;border-bottom:1.5px solid var(--border,#e2e8f0);border-radius:8px 8px 0 0}
    /* Load / Save As / Delete / Set Default carried no class at all, so they
       rendered at the browser default size and stayed there on tablets, where
       every other control in this console grows to a 44px touch target. */
    /* Provenance line: what this game was set up from, and whether it still
       matches. Amber for MODIFIED because amber means "warning" house-wide. */
    .pk-preset-from{display:flex;align-items:center;gap:.4rem;font-size:.8rem;margin:0 0 .4rem;min-height:1.2rem}
    .pk-preset-lbl{color:#64748b;font-weight:600}
    .pk-preset-name{color:#0f172a;font-weight:700}
    .pk-preset-none{color:#64748b;font-style:italic}
    .pk-preset-mod{background:#fffbeb;color:#92400e;border:1px solid #f59e0b;border-radius:999px;font-size:.66rem;font-weight:800;letter-spacing:.04em;padding:.05rem .45rem}
    .pk-preset-update{border-color:#f59e0b !important;color:#92400e !important;background:#fffbeb !important}
    .pk-preset-update:hover{background:#fef3c7 !important}
    .pk-preset-bar{display:flex;gap:.5rem;align-items:center;flex-wrap:wrap}
    .pk-preset-head{display:flex;align-items:center;gap:.6rem;flex-wrap:wrap;padding-bottom:.4rem}
    .pk-preset-head .pk-cfg-title,.pk-preset-head .pk-preset-from{margin:0}
    .pk-preset-toggle{padding:.25rem .65rem;border:1.5px solid var(--border,#e2e8f0);border-radius:999px;background:#fff;font-size:.74rem;font-weight:600;color:#475569;cursor:pointer}
    .pk-preset-toggle:hover{background:#f1f5f9}
    #presetBody{margin-top:.5rem}
    .pk-blinds-hint{margin:0 0 .75rem;padding:.5rem .75rem;background:#eff6ff;border:1px solid #bfdbfe;border-radius:6px;color:#1e40af;font-size:.82rem;line-height:1.45}
    .pk-preset-more{position:relative}
    .pk-preset-morebtn{padding:.4rem .6rem;border:1.5px solid var(--border,#e2e8f0);border-radius:6px;background:#fff;font-size:.9rem;line-height:1;color:#64748b;cursor:pointer}
    .pk-preset-morebtn:hover{background:#f1f5f9}
    .pk-preset-menu{display:none;position:absolute;right:0;top:calc(100% + .3rem);z-index:60;background:#fff;border:1.5px solid #e2e8f0;border-radius:9px;box-shadow:0 10px 28px rgba(15,23,42,.18);padding:.3rem;min-width:180px}
    .pk-preset-menu.open{display:block}
    .pk-preset-menu button{display:block;width:100%;text-align:left;padding:.45rem .6rem;border:0;background:none;font-size:.82rem;color:#334155;border-radius:6px;cursor:pointer}
    .pk-preset-menu button:hover{background:#f1f5f9}
    .pk-preset-menu button.danger{color:#b91c1c}
    .pk-preset-menu button.danger:hover{background:#fef2f2}
    .pk-preset-menu button:disabled{opacity:.45;cursor:default}
    .pk-preset-menu button:disabled:hover{background:none}
    .pk-preset-menu-hint{padding:.45rem .6rem;font-size:.78rem;color:#64748b;line-height:1.35;max-width:230px}
    /* Chip set editor: a colour and a value per row, kept narrow so it sits
       beside the other Game fields rather than pushing them down. */
    .pk-chipset{margin-top:.9rem}
    .pk-chip-rows{display:flex;flex-wrap:wrap;gap:.4rem;margin-bottom:.5rem}
    .pk-chip-row{display:flex;align-items:center;gap:.25rem;border:1.5px solid var(--border,#e2e8f0);border-radius:8px;padding:.2rem .3rem;background:#fff}
    .pk-chip-colour{width:30px;height:30px;padding:0;border:1px solid #cbd5e1;border-radius:50%;background:none;cursor:pointer}
    .pk-chip-val{width:78px;padding:.3rem .35rem;border:1.5px solid var(--border,#e2e8f0);border-radius:6px;font-size:.85rem;text-align:right}
    .pk-chip-none{font-size:.78rem;color:#64748b;font-style:italic}
    /* Chip image: the button IS the preview, so a set with photos reads as the
       tray it represents rather than as a list of filenames. */
    .pk-chip-img{width:30px;height:30px;padding:0;border:1px dashed #cbd5e1;border-radius:50%;background:#f8fafc no-repeat center/cover;
                 cursor:pointer;font-size:.62rem;color:#64748b;line-height:1;display:flex;align-items:center;justify-content:center}
    .pk-chip-img.has-img{border-style:solid;border-color:#94a3b8;color:transparent}
    .pk-chip-img:hover{border-color:#2563eb}
    .pk-chip-actions{display:flex;gap:.4rem;flex-wrap:wrap}
    .pk-preset-bar button{padding:.4rem .8rem;border:1.5px solid var(--border,#e2e8f0);border-radius:6px;background:#fff;font-size:.8rem;font-weight:600;color:#334155;cursor:pointer}
    .pk-preset-bar button:hover{background:#f1f5f9}
    .pk-cfg-title{font-size:.72rem;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:#64748b;margin:0 0 .5rem}
    .pk-reward-chips{display:flex;gap:.5rem;flex-wrap:wrap}
    .pk-reward-chip{padding:.4rem .8rem;border-radius:999px;border:1.5px solid var(--border,#e2e8f0);background:transparent;font-size:.8rem;font-weight:600;color:#64748b;cursor:pointer}
    /* On means this reward is switched on for the game, so it reads green like
       every other "this is active" control here (.pk-btn-green, the mobile
       .primary action). Blue was the information colour and made an armed
       bounty or points scheme look like a hint about one. */
    .pk-reward-chip.on{background:#15803d;border-color:#15803d;color:#fff}
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
    .pk-subbox-body>div{display:flex;flex-direction:column;justify-content:flex-end}
    .pk-subbox-body label{font-size:.8rem;font-weight:600;color:#475569;display:block;margin-bottom:.2rem}
    .pk-subbox-body input{width:100%;padding:.4rem .6rem;border:1.5px solid var(--border,#e2e8f0);border-radius:6px;font-size:.85rem}
    .pk-subbox-body select{width:100%;padding:.4rem .6rem;border:1.5px solid var(--border,#e2e8f0);border-radius:6px;font-size:.85rem;background:#fff}
    .pk-subbox-wide{flex:1 1 100%;background:#f8fafc}
    .pk-subbox-wide .pk-subbox-body{grid-template-columns:minmax(150px,220px) minmax(150px,220px)}

    /* $ prefix inside dollar inputs — typing "1" when you meant "$20" is the
       kind of slip a visible unit prevents. */
    .pk-money-wrap{position:relative;display:block}
    .pk-money-wrap::before{content:'$';position:absolute;left:.55rem;top:50%;transform:translateY(-50%);color:#64748b;font-size:.85rem;font-weight:700;pointer-events:none}
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
    .pk-btn-green{background:#15803d;color:#fff;border:1.5px solid #15803d;padding:.4rem .8rem;border-radius:6px;font-size:.8rem;font-weight:600;cursor:pointer}
    .pk-btn-green:hover{background:#166534}
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
    .pk-tv-move{font-size:.75rem;padding:.2rem .4rem;border:1px solid #e2e8f0;border-radius:4px;background:#fff;cursor:pointer;min-height:24px}

    /* Compact stats bar (mobile only) */
    .pk-stats-compact{display:none;padding:.4rem .75rem;background:var(--surface,#fff);border-bottom:1.5px solid var(--border,#e2e8f0);font-size:.78rem;color:#475569;gap:.3rem;flex-wrap:wrap;align-items:center}
    .pk-stats-compact span{white-space:nowrap}
    .pk-stats-compact .sep{color:#cbd5e1}
    .pk-stats-compact b{color:var(--accent,#2563eb);font-weight:700}
    .pk-stats-compact .pool-val{color:#15803d;font-weight:700}

    /* Mobile player cards */
    .pk-mobile-card{background:var(--surface,#fff);border:1.5px solid var(--border,#e2e8f0);border-radius:8px;margin-bottom:.5rem;overflow:hidden}
    .pk-mobile-card.elim{opacity:.5}
    .pk-mobile-card.cashed-out{opacity:.7}
    .pk-mobile-card.rsvp-no{opacity:.45}
    .pk-declined-row td,div.pk-declined-row{text-align:center;padding:.6rem;color:#64748b;font-size:.85rem}
    .pk-table tr.pk-declined-row:hover td{background:transparent}
    .pk-declined-btn{background:none;border:none;color:#1d4ed8;font:inherit;font-weight:600;cursor:pointer;padding:.2rem .4rem;min-height:24px;text-decoration:underline}
    .pk-mobile-summary{display:flex;justify-content:space-between;align-items:center;padding:.65rem .8rem;cursor:pointer;-webkit-tap-highlight-color:transparent}
    .pk-mobile-summary:active{background:#f1f5f9}
    .pk-mobile-card .pk-check{width:22px;height:22px}
    .pk-mobile-name{font-weight:600;font-size:.9rem}
    .pk-mobile-status{font-size:.7rem;font-weight:600;padding:.15rem .4rem;border-radius:4px;min-width:4.5rem;text-align:center;flex-shrink:0}
    /* The chevron is the keyboard's way into a card and the first visible hint
       that the row opens at all: a tap anywhere on the row still works. */
    .pk-mobile-more{flex:0 0 auto;width:32px;height:32px;margin-left:.4rem;border:1px solid #cbd5e1;border-radius:6px;background:#fff;color:#475569;font-size:.8rem;line-height:1;cursor:pointer;display:inline-flex;align-items:center;justify-content:center}
    .pk-mobile-more:active{background:#e2e8f0}
    .pk-addon-count{display:inline-flex;align-items:center;justify-content:center;min-width:1.3rem;min-height:24px;height:1.3rem;padding:0 .4rem;border:none;border-radius:10px;background:#7c3aed;color:#fff;font-size:.75rem;font-weight:700;cursor:pointer;flex-shrink:0}
    .pk-addon-count:hover{background:#6d28d9}
    .pk-rebuy-closed{opacity:.45}
    .pk-rebuy-closed-tag{display:inline-block;font-size:.68rem;font-weight:600;color:#92400e;background:#fffbeb;border:1px solid #f59e0b;border-radius:4px;padding:0 .3rem;margin-left:.25rem;vertical-align:middle}
    .pk-break-addon{display:none;justify-content:center;margin:.1rem 0 .6rem}
    .pk-break-addon button{font-size:.85rem;padding:.5rem .9rem;min-height:36px;border-radius:6px;border:1px solid #c4b5fd;background:#f5f3ff;color:#6d28d9;font-weight:600;cursor:pointer}
    .pk-mobile-expand{display:none;padding:.5rem .8rem;border-top:1px solid #f1f5f9;background:#f8fafc}
    .pk-mobile-expand.open{display:block}
    .pk-mobile-row{display:flex;align-items:center;justify-content:space-between;padding:.35rem 0;gap:.5rem;flex-wrap:wrap}
    .pk-mobile-row label{font-size:.75rem;color:#64748b;font-weight:600;min-width:70px}
    .pk-mobile-actions{display:flex;gap:.4rem;flex-wrap:wrap;padding-top:.4rem;border-top:1px solid #e2e8f0;margin-top:.3rem}
    .pk-mobile-actions button{padding:.35rem .7rem;border-radius:6px;font-size:.78rem;font-weight:600;cursor:pointer;border:1.5px solid var(--border,#e2e8f0);background:#fff}
    .pk-mobile-actions button:active{background:#e2e8f0}
    .pk-mobile-actions .primary{background:#15803d;border-color:#15803d;color:#fff}
    .pk-mobile-actions .danger{background:#dc2626;border-color:#dc2626;color:#fff}
    .pk-bulk-bar{display:flex;align-items:center;gap:.5rem;padding:.5rem .75rem;background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:8px;margin-bottom:.5rem;flex-wrap:wrap;transition:background .15s,border-color .15s}
    .pk-bulk-bar.active{background:#eff6ff;border-color:#bfdbfe}
    .pk-bulk-bar:not(.active) button{opacity:.4;pointer-events:none}
    .pk-bulk-bar .pk-bulk-count{font-size:.82rem;font-weight:700;color:#2563eb;min-width:5rem}
    .pk-bulk-bar button{font-size:.75rem;padding:.3rem .65rem;border-radius:5px;border:1.5px solid #e2e8f0;background:#fff;font-weight:600;cursor:pointer}
    .pk-bulk-bar button:hover{background:#f1f5f9}
    .pk-bulk-bar .danger{color:#dc2626;border-color:#fca5a5}
    .pk-bulk-bar .primary{color:#fff;background:#2563eb;border-color:#2563eb}
    .pk-row-select{width:20px;height:20px;cursor:pointer;accent-color:#2563eb}
    .walkin-autocomplete{position:relative}
    .walkin-dropdown{position:absolute;top:100%;left:0;right:0;background:#fff;border:1.5px solid #e2e8f0;border-top:none;border-radius:0 0 8px 8px;box-shadow:0 4px 12px rgba(0,0,0,.1);z-index:100;max-height:200px;overflow-y:auto;display:none}
    .walkin-dropdown.open{display:block}
    .walkin-dropdown-item{padding:.5rem .75rem;cursor:pointer;font-size:.85rem;color:#334155}
    .walkin-dropdown-item:hover,.walkin-dropdown-item.active{background:#eff6ff;color:#2563eb}
    .walkin-dropdown-item .walkin-hint{font-size:.7rem;color:#64748b;margin-left:.3rem}
    @media(max-width:768px){
        .pk-table-wrap{display:none}
        .pk-bulk-bar{display:none}
        .pk-break-addon{display:flex}
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
    .pk-log-empty{padding:1.25rem;text-align:center;color:#64748b;font-size:.9rem}
    .pk-log-row{display:flex;align-items:baseline;gap:.6rem;padding:.5rem .75rem;border-bottom:1px solid #f1f5f9;font-size:.85rem}
    .pk-log-row:last-child{border-bottom:none}
    .pk-log-time{flex:0 0 auto;color:#64748b;font-size:.72rem;font-variant-numeric:tabular-nums;white-space:nowrap;min-width:62px}
    .pk-log-tag{flex:0 0 auto;font-size:.62rem;font-weight:700;text-transform:uppercase;letter-spacing:.03em;padding:.12rem .4rem;border-radius:4px;white-space:nowrap}
    .pk-log-text{flex:1 1 auto;color:#334155;line-height:1.35}
    .pk-log-text b{color:#0f172a}
    .pk-log-by{color:#64748b;font-size:.72rem;white-space:nowrap}
    .pk-log-tag.t-buyin,.pk-log-tag.t-cashin{background:#dcfce7;color:#166534}
    .pk-log-tag.t-rebuy,.pk-log-tag.t-addon{background:#dbeafe;color:#1e40af}
    .pk-log-tag.t-cashout{background:#fef9c3;color:#854d0e}
    .pk-log-tag.t-add,.pk-log-tag.t-approve{background:#f1f5f9;color:#475569}
    .pk-log-tag.t-eliminate{background:#fee2e2;color:#991b1b}
    .pk-log-tag.t-uneliminate{background:#ede9fe;color:#5b21b6}
    .pk-log-tag.t-remove,.pk-log-tag.t-unbuyin{background:#f1f5f9;color:#64748b}
    .pk-log-tag.t-void{background:#fee2e2;color:#991b1b}
    .pk-log-tag.t-edit{background:#e0f2fe;color:#075985}
    .pk-log-tag.t-bounty{background:#cffafe;color:#155e75}
    .pk-log-tag.t-jackpot{background:#ede9fe;color:#5b21b6}
    .pk-log-tag.t-ticket_issue,.pk-log-tag.t-ticket_redeem{background:#fef3c7;color:#92400e}
    .pk-log-tag.t-ticket_void{background:#f1f5f9;color:#64748b}
    /* Cleared rows: same treatment as the ledger, via a class so the row can
       still carry an actions block that is NOT struck through. */
    .pk-log-row.voided{opacity:.55}
    .pk-log-row.voided .pk-log-text{text-decoration:line-through}
    /* Buttons centre themselves against a baseline-aligned row. */
    .pk-log-actions{flex:0 0 auto;display:flex;gap:.3rem;align-self:center}

    /* ── Per-player ledger ── */
    .pk-ledger-btn{flex:0 0 auto;border:none;background:transparent;cursor:pointer;font-size:1rem;line-height:1;padding:.1rem .25rem;border-radius:4px;color:#64748b;min-width:24px;min-height:24px}
    .pk-ledger-btn:hover{background:#e2e8f0;color:#334155}
    .pk-ledger-list{max-height:60vh;overflow:auto;border:1px solid var(--border,#e2e8f0);border-radius:8px;margin-top:.25rem}
    .pk-ledger-empty{padding:1.1rem;text-align:center;color:#64748b;font-size:.88rem}
    .pk-ledger-row{display:flex;align-items:center;gap:.6rem;padding:.5rem .75rem;border-bottom:1px solid #f1f5f9;font-size:.85rem}
    .pk-ledger-row:last-child{border-bottom:none}
    .pk-ledger-row.voided{opacity:.6}
    .pk-ledger-row.voided .pk-ledger-detail{text-decoration:line-through}
    .pk-ledger-time{flex:0 0 auto;color:#64748b;font-size:.72rem;min-width:62px;font-variant-numeric:tabular-nums}
    .pk-ledger-amt{flex:0 0 auto;font-weight:700;font-variant-numeric:tabular-nums}
    .pk-ledger-amt.pos{color:#166534}.pk-ledger-amt.neg{color:#b91c1c}
    .pk-ledger-detail{flex:1 1 auto;color:#334155}
    .pk-ledger-detail small{color:#64748b;font-weight:400}
    .pk-ledger-edit{flex:0 0 auto;border:1px solid #93c5fd;background:#fff;color:#2563eb;border-radius:5px;font-size:.72rem;font-weight:600;padding:.2rem .5rem;cursor:pointer;min-height:24px}
    .pk-ledger-edit:hover{background:#eff6ff}
    .pk-ledger-clear{flex:0 0 auto;border:1px solid #fca5a5;background:#fff;color:#dc2626;border-radius:5px;font-size:.72rem;font-weight:600;padding:.2rem .5rem;cursor:pointer;min-height:24px}
    .pk-ledger-clear:hover{background:#fee2e2}
    .pk-ledger-void-tag{flex:0 0 auto;font-size:.66rem;font-weight:700;text-transform:uppercase;color:#991b1b;background:#fee2e2;border-radius:4px;padding:.1rem .4rem}

    /* ── Tap-friendly help bubble (? marker, hover OR focus/tap) ── */
    .pk-tip{display:inline-flex;align-items:center;justify-content:center;width:18px;height:18px;border-radius:50%;background:#64748b;color:#fff;font-size:.68rem;font-weight:700;cursor:help;margin-left:.25rem;position:relative;vertical-align:middle;flex:0 0 auto}
    /* The disc stays 18px; an invisible ring around it makes the target 24px. */
    .pk-tip::before{content:'';position:absolute;inset:-3px;border-radius:50%}
    .pk-tip:hover,.pk-tip:focus{background:#2563eb;outline:none}
    .pk-tip:focus-visible{outline:2px solid #2563eb;outline-offset:2px}
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
    .pk-cb-os.even{color:#15803d}
    .pk-cb-os.over{color:#b45309}
    .pk-cb-os.short{color:#dc2626}
    .pk-cb-tipbtn{margin-top:.6rem;width:100%;background:#fffbeb;border:1px solid #fde68a;color:#92400e;border-radius:6px;padding:.45rem;font-size:.82rem;font-weight:600;cursor:pointer}
    .pk-cb-tipbtn:hover{background:#fef3c7}

    .pk-inline-summary{display:none}

    /* Finger-sized row controls on a touch screen, whatever the viewport. */
    @media (pointer: coarse) {
        .pk-tip{width:24px;height:24px;font-size:.78rem}
        .pk-ledger-btn,.pk-bust-btn,.pk-addon-btn,.pk-addon-count,.pk-tv-move,.pk-ledger-edit,.pk-ledger-clear,
        .pk-payout-editor .row button,.jp-recip-row button,.pk-help-btn,.pk-preset-morebtn{min-width:36px;min-height:36px}
        .pk-row-select,.pk-check,.pk-mobile-card .pk-check{width:28px;height:28px}
        .pk-counter button{width:36px;height:36px}
        .pk-mobile-more{width:44px;height:44px}
        .pk-chip-colour,.pk-chip-img{width:36px;height:36px}
        .pk-chip-row .es-mini{min-width:36px;min-height:36px}
    }

    /* ── Mobile/tablet touch optimization ── */
    @media (max-width: 1024px) {
        .pk-sidebar{display:none}
        .pk-inline-summary{display:flex;gap:.6rem;flex-wrap:wrap;align-items:center;padding:.4rem .75rem;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;margin-bottom:.5rem;font-size:.8rem;color:#334155}
        .pk-stats { padding:.75rem 1rem; }
        /* Was flex:1 1 calc(50% - .5rem), a 2-up grid clearly meant for phones —
           but phones never reach it, because at <=768px .pk-stats is replaced
           wholesale by the compact one-line bar. So it only ever fired on
           tablets, where it stacked four 490px cards into two rows and made the
           header 179px tall against 96px on desktop. Sharing one row instead
           gives that height back and still fills the width. */
        .pk-stat { min-width:0;flex:1 1 0; }
        .pk-grid { padding:.75rem 1rem; }
        .pk-toolbar { gap:.4rem; }
        .pk-toolbar input[type=text] { width:100%;font-size:1rem;min-height:44px; }
        .pk-toolbar button { min-height:44px;font-size:.85rem; }
        .pk-actions button, .pk-actions a { min-height:44px;font-size:.85rem;padding:.5rem .8rem; }
        .pk-seg button { min-height:40px;font-size:.85rem;padding:.4rem .7rem; }
        .pk-preset-bar button { min-height:44px;font-size:.85rem;padding:.5rem .9rem; }
        /* The select carries its padding and font-size inline, which a rule here
           cannot outrank — min-height is untouched there, so it is what keeps the
           row's controls the same height. */
        .pk-preset-bar select { min-height:44px; }
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

<?php /* One-time Tournament Timer ask. Three answers, which is one more than
         pkConfirm offers, so it uses this page's own modal pattern: yes and
         "keep classic" are final and stored; "Not now" is not an answer at
         all — it leaves the account unanswered and only quietens the ask in
         this browser for a while. */ ?>
<div class="pk-modal-overlay" id="betaTimerModal">
    <div class="pk-modal" role="dialog" aria-modal="true" aria-labelledby="betaTimerTitle">
        <h3 id="betaTimerTitle">&#9201; Try the Tournament Timer</h3>
        <p id="betaBannerMsg" style="font-size:.9rem;color:#475569;line-height:1.5;margin:.4rem 0 0">
            Designable layouts, break screens, phone views for players who scan the QR code, and sounds.
            Switch this game over to it and use it for new games too?
        </p>
        <p style="font-size:.8rem;color:#64748b;line-height:1.45;margin:.6rem 0 0">
            Any game can still go back to Classic in Setup, and you can change your mind any time in Settings.
        </p>
        <div class="pk-modal-actions">
            <button type="button" data-act="betaTimerLater">Not now</button>
            <button type="button" data-act="betaTimerAnswer" data-a1="0">Keep Classic</button>
            <button type="button" class="pk-save pk-beta-yes" data-act="betaTimerAnswer" data-a1="1">Use the Tournament Timer</button>
        </div>
    </div>
</div>

<!-- Notes modal -->
<div class="pk-modal-overlay" id="notesModal">
    <div class="pk-modal" role="dialog" aria-modal="true" aria-labelledby="notesTitle">
        <h3 id="notesTitle">Player Notes</h3>
        <textarea id="notesText" aria-label="Notes" placeholder="Notes about this player..."></textarea>
        <div class="pk-modal-actions">
            <button data-act="closeNotes">Cancel</button>
            <button class="pk-save" data-act="saveNotes">Save</button>
        </div>
    </div>
</div>

<!-- Save payout structure modal (opened from the settings editor, so it sits
     above the z-900 full-screen overlay) -->
<!-- League jackpot hit modal (funds display + per-recipient split) -->
<div class="pk-modal-overlay" id="jackpotModal" style="z-index:1000">
    <div class="pk-modal" role="dialog" aria-modal="true" aria-labelledby="jackpotTitle">
        <h3 id="jackpotTitle">💎 League Jackpot</h3>
        <div id="jpBalances" style="font-size:.9rem;color:#334155;margin-bottom:.75rem"></div>
        <label for="jpType" style="font-size:.85rem;font-weight:600;color:#475569;display:block;margin-bottom:.3rem">What hit?</label>
        <select id="jpType" style="width:100%;padding:.5rem;border:1.5px solid var(--border,#e2e8f0);border-radius:6px;font-size:1rem;box-sizing:border-box;margin-bottom:.6rem">
            <option value="badbeat">🃏 Bad Beat</option>
            <option value="royal">👑 Royal Flush</option>
            <option value="other">💎 Other jackpot</option>
        </select>
        <div id="jpPaidTo" style="font-size:.85rem;font-weight:600;color:#475569;display:block;margin-bottom:.3rem">Paid to</div>
        <div id="jpRecipients"></div>
        <button type="button" data-act="addJackpotRecipient" style="font-size:.78rem;padding:.3rem .6rem;border-radius:4px;cursor:pointer;border:1.5px solid var(--border,#e2e8f0);background:#f8fafc;margin-top:.2rem">+ Add recipient</button>

        <div style="margin-top:.9rem;padding-top:.7rem;border-top:1.5px solid #e2e8f0">
            <div style="display:flex;justify-content:space-between;align-items:center">
                <span style="font-size:.85rem;font-weight:600;color:#475569">Fund history</span>
                <button type="button" data-act="jpToggleAdjust" style="font-size:.72rem;padding:.25rem .55rem;border-radius:4px;cursor:pointer;border:1.5px solid var(--border,#e2e8f0);background:#f8fafc">± Adjust fund</button>
            </div>
            <div id="jpAdjustRow" style="display:none;margin-top:.5rem;gap:.4rem;align-items:center">
                <span class="pk-money-wrap" style="flex:0 0 90px"><input type="number" id="jpAdjAmount" step="0.01" placeholder="-5 or 5" aria-label="Adjustment amount"></span>
                <input type="text" id="jpAdjNote" maxlength="120" placeholder="reason…" aria-label="Reason for the adjustment" style="flex:1;padding:.45rem .5rem;border:1.5px solid var(--border,#e2e8f0);border-radius:6px;font-size:.85rem">
                <button type="button" class="pk-save" data-act="confirmJackpotAdjust" style="padding:.4rem .7rem;border-radius:6px;border:none;background:#2563eb;color:#fff;font-size:.8rem;font-weight:600;cursor:pointer">Apply</button>
            </div>
            <div id="jpHistory" style="margin-top:.5rem;max-height:180px;overflow-y:auto;scrollbar-gutter:stable;padding-right:.75rem;font-size:.78rem;color:#334155"></div>
        </div>

        <div class="pk-modal-actions">
            <button data-act="closeJackpotModal">Cancel</button>
            <button class="pk-save" data-act="confirmJackpotHit">Record Hit</button>
        </div>
    </div>
</div>

<!-- No click-outside dismiss: a mis-click must not eat a half-typed name. -->
<div class="pk-modal-overlay" id="saveStructModal" style="z-index:1000">
    <div class="pk-modal" role="dialog" aria-modal="true" aria-labelledby="saveStructTitle">
        <h3 id="saveStructTitle">Save Payout Structure</h3>
        <label for="ssName" style="font-size:.85rem;font-weight:600;color:#475569;display:block;margin-bottom:.3rem">Name</label>
        <input type="text" id="ssName" maxlength="60" placeholder="e.g. Friday night 4-way" style="width:100%;padding:.5rem;border:1.5px solid var(--border,#e2e8f0);border-radius:6px;font-size:1rem;box-sizing:border-box">
        <label for="ssScope" style="font-size:.85rem;font-weight:600;color:#475569;display:block;margin:.75rem 0 .3rem">Save to</label>
        <select id="ssScope" style="width:100%;padding:.5rem;border:1.5px solid var(--border,#e2e8f0);border-radius:6px;font-size:1rem;box-sizing:border-box"></select>
        <div class="pk-modal-actions">
            <button data-act="closeSaveStruct">Cancel</button>
            <button class="pk-save" data-act="confirmSaveStruct">Save</button>
        </div>
    </div>
</div>

<!-- Cash-in adjust modal -->
<div class="pk-modal-overlay" id="cashAdjustModal" data-act-self="closeCashAdjust">
    <div class="pk-modal" role="dialog" aria-modal="true" aria-labelledby="cashAdjustTitle">
        <h3 id="cashAdjustTitle">Add Money</h3>
        <label for="cashAdjustAmount" style="font-size:.85rem;font-weight:600;color:#475569;display:block;margin-bottom:.3rem">Amount ($)</label>
        <input type="number" id="cashAdjustAmount" step="0.01" min="0" style="width:100%;padding:.5rem;border:1.5px solid var(--border,#e2e8f0);border-radius:6px;font-size:1rem;box-sizing:border-box">
        <div class="pk-modal-actions">
            <button data-act="closeCashAdjust">Cancel</button>
            <button class="pk-save" id="cashAdjustOk" data-act="applyCashAdjust">Add</button>
        </div>
    </div>
</div>

<!-- Ledger modal: per-player money history + corrections -->
<div class="pk-modal-overlay" id="ledgerModal" data-act-self="closeLedger">
    <div class="pk-modal pk-log-modal" role="dialog" aria-modal="true" aria-labelledby="ledgerTitle">
        <h3 id="ledgerTitle">Ledger</h3>
        <div class="pk-log-sub">Every buy-in, add-on and cash-out for this player. Tap <b>Edit</b> to fix a wrong amount in place, or <b>Clear</b> to reverse an entry.</div>
        <div class="pk-ledger-list" id="ledgerList"><div class="pk-ledger-empty">Loading&hellip;</div></div>
        <div class="pk-modal-actions">
            <button class="pk-save" data-act="closeLedger">Close</button>
        </div>
    </div>
</div>

<!-- Cash box reconciliation modal (cash games) -->
<div class="pk-modal-overlay" id="cashBoxModal" data-act-self="closeCashBox">
    <div class="pk-modal" role="dialog" aria-modal="true" aria-labelledby="cashBoxTitle">
        <h3 id="cashBoxTitle">&#129534; Cash Box</h3>
        <div class="pk-log-sub">Record tips and square the cash box at the end of the game.</div>
        <div class="pk-cb-grid">
            <div class="pk-cb-row"><span>Total bought in</span><span id="cbCashIn">$0</span></div>
            <div class="pk-cb-row"><span>Total cashed out</span><span id="cbCashOut">$0</span></div>
            <div class="pk-cb-row"><span>Still on table (owed)</span><span id="cbOnTable">$0</span></div>
            <div class="pk-cb-row pk-cb-input-row"><label for="cbTips">Tips (host)</label><span class="pk-cb-money">$<input type="number" inputmode="decimal" step="0.01" min="0" id="cbTips" data-act-input="cashBoxRecompute" placeholder="0.00"></span></div>
            <div class="pk-cb-row pk-cb-sub"><span>Expected in box</span><span id="cbExpected">$0</span></div>
            <div class="pk-cb-row pk-cb-input-row"><label for="cbCounted">Counted in box</label><span class="pk-cb-money">$<input type="number" inputmode="decimal" step="0.01" min="0" id="cbCounted" data-act-input="cashBoxRecompute" placeholder="count it"></span></div>
            <div class="pk-cb-row pk-cb-total"><span>Over / Short</span><span id="cbOverShort" class="pk-cb-os">&mdash;</span></div>
            <button type="button" id="cbTipSurplus" class="pk-cb-tipbtn" data-act="cashBoxTipSurplus" style="display:none">Record the surplus as tips</button>
        </div>
        <div class="pk-modal-actions">
            <button data-act="closeCashBox">Cancel</button>
            <button class="pk-save" data-act="saveCashBox">Save</button>
        </div>
    </div>
</div>

<!-- Help modal -->
<div class="pk-modal-overlay" id="helpModal" data-act-self="closeHelp">
    <div class="pk-modal" role="dialog" aria-modal="true" aria-labelledby="helpTitle">
        <h3 id="helpTitle">How this screen works</h3>
        <div class="pk-help-content" tabindex="-1">
            <?php /* Ordered the way a night goes. The .help-tourney and .help-cash
                     groups are shown or hidden by openHelp() for the game type,
                     so a cash host is not reading about eliminations. */ ?>
            <h4 class="pk-cfg-title" style="margin:0 0 .2rem">Before the game</h4>
            <h5>Setup</h5>
            <p>Do this first. <b>&#9881; Setup</b> holds the buy-in and the tables and seats; for a tournament also the starting chips, rebuy and add-on rules, the payout structure, any bounties, tickets or jackpot, and the <b>Blinds</b> and <b>Timer</b> tabs for the clock. Until it is saved, buy-ins and prizes will not add up.</p>
            <h5>Who is on the list</h5>
            <p>Everyone who answered Yes to the invitation is already here. For anyone who turns up unannounced, type their name in the <b>Walk-in name</b> box and press <b>+ Add</b>.</p>
            <h5>Approve / Deny</h5>
            <p>These appear only for players who put themselves on the list through the QR code or self-signup. <b>Approve</b> puts them on the roster so you can buy them in; <b>Deny</b> turns them away. If you add everyone yourself, you will never see these buttons.</p>
            <h4 class="pk-cfg-title" style="margin:.9rem 0 .2rem">During the game</h4>
            <div class="help-tourney">
            <h5>Buy In</h5>
            <p>Tick the box when a player pays. It also checks them in and gives them a seat; there is no separate check-in step. The &#128210; beside the box is their ledger: every buy-in, rebuy and add-on, with <b>Edit</b> and <b>Clear</b> for mistakes.</p>
            <h5>Rebuys &amp; Add-ons</h5>
            <p>When the game allows them, the + / &minus; counters on a player's row track each one. The prize pool at the top right follows. If the entry fee covers rebuys or add-ons (Setup &rarr; <b>Free with the buy-in</b>), a player's first ones are recorded at $0 and only those beyond count toward the pool. <b>Free rebuy and free add-on: One stack</b> means a player gets one or the other: a rebuy before the break, or the add-on at the break; a player who took the rebuy pays for the add-on. <b>No rebuys after level</b> closes rebuys once the clock is past that level: the + still works but asks first, and the ledger says the rebuy came after the cutoff. At the break, <b>Add-on for everyone still in</b> (above the list on a phone; on a desktop select the players and press <b>Add-on</b> in the bar) records one for each player who has none yet.</p>
            <h5>Eliminate</h5>
            <p>Marks a player out. Their finishing place is filled in by elimination order (9th, 8th &hellip; down to 1st), and if that place is in the money the prize owed shows next to their name, so eliminate players in the order they bust. <b>Undo</b> puts them back. The last player standing wins, and that finishes the game.</p>
            </div>
            <div class="help-cash">
            <h5>Cash In / Cash Out</h5>
            <p>Type what a player buys in for and press Enter; <b>+</b> adds a top-up. When they leave, type what they take with them and press the green check; clear the field to put them back in play. <b>Profit</b> does the arithmetic. The &#128210; beside a name is their ledger, with <b>Edit</b> and <b>Clear</b> for mistakes.</p>
            <h5>Cash Box</h5>
            <p>At the end, <b>Cash Box</b> in the header records tips and squares the box against what came in and went out.</p>
            </div>
            <h5>Remove</h5>
            <p>Takes someone off the list who is not playing: a no-show, a duplicate. It is not an elimination; a player who busted gets <b>Eliminate</b>.</p>
            <h5>Notes</h5>
            <p>A note on a player for tonight, for you and your managers.</p>
            <h5>Several at once</h5>
            <p>Tick the boxes at the left of the rows and the bar above the list acts on all of them: Buy In, Eliminate, Approve, Remove.</p>
            <h5>Filters and sorting</h5>
            <p><b>All</b>, <b>RSVP Yes</b>, <b>Playing</b> and <b>Out</b> narrow the list; click a column heading to sort by it. Guests who said No are folded under a <b>said no</b> line at the foot of All and are not counted as players; <b>Show</b> brings them back, and one who turns up and buys in counts like anyone.</p>
            <h4 class="pk-cfg-title" style="margin:.9rem 0 .2rem">The views</h4>
            <h5>List, Table, Log<span class="help-tourney">, Payouts, Chop</span></h5>
            <p><b>List</b> is this roster. <b>Table</b> shows who sits where: <b>Move&hellip;</b> moves a player, <b>Add Table</b> when the room outgrows one, <b>Balance</b> evens the tables out, <b>Break Up</b> closes a table and spreads its players. <b>Log</b> is every money and roster change, newest first, with Edit and Clear.<span class="help-tourney"> <b>Payouts</b> is the prize ladder and who is owed what as the places fill. <b>Chop</b> is the deal calculator for when the last players agree to split, by chip counts.</span></p>
            <h4 class="pk-cfg-title" style="margin:.9rem 0 .2rem">Also in the header</h4>
            <div class="help-tourney">
            <h5>Timer</h5>
            <p>The tournament clock for the big screen. Its own guide is under Help.</p>
            </div>
            <h5>Table Mgr</h5>
            <p>A phone-sized console for this same game: buy-ins, knockouts and the clock within one thumb, for walking the room.</p>
            <h5>QR</h5>
            <p>A page to leave on a tablet at the door. Guests scan it to put themselves on the list, and arrive here as <b>Approve / Deny</b>.</p>
            <h5>Finish</h5>
            <p>Locks in the results<span class="help-tourney">: the standings and payouts, and any entry tickets go out</span><span class="help-cash">: every player's cash-out</span>. It warns first if something is not ready. <b>Reopen</b> puts the game back if something was wrong.</p>
            <h5>From the keyboard</h5>
            <p>Tab reaches every control. The arrow keys move along the view strip, the filters and Setup's tabs; Enter opens a card's &#9662; on a phone; Escape closes a dialog, a menu or Setup; the column headings sort on Enter; and the row menu in Setup &rarr; Blinds opens with Shift+F10.</p>
        </div>
        <div class="pk-modal-actions">
            <button class="pk-save" data-act="closeHelp">Got it</button>
        </div>
    </div>
</div>

<!-- Eliminate confirmation modal -->
<div class="pk-modal-overlay" id="elimModal" data-act-self="closeElim">
    <div class="pk-modal" role="dialog" aria-modal="true" aria-labelledby="elimTitle">
        <h3 id="elimTitle">Eliminate Player</h3>
        <p id="elimMsg" style="font-size:.9rem;color:#475569;line-height:1.45;margin:0"></p>
        <div class="pk-modal-actions">
            <button data-act="closeElim">Cancel</button>
            <button class="pk-save" style="background:#dc2626" data-act="confirmElim">Eliminate</button>
        </div>
    </div>
</div>

<!-- Winner / game-over modal -->
<div class="pk-modal-overlay" id="winnerModal" data-act-self="closeWinner">
    <div class="pk-modal" role="dialog" aria-modal="true" aria-labelledby="winnerMsg" style="text-align:center">
        <div style="font-size:2.75rem;line-height:1">🏆</div>
        <h3 id="winnerMsg" style="margin:.4rem 0 .25rem"></h3>
        <p id="winnerSub" style="font-size:.9rem;color:#475569;line-height:1.45;margin:0"></p>
        <div class="pk-modal-actions" style="justify-content:center">
            <button class="pk-save" data-act="closeWinner">Nice!</button>
        </div>
    </div>
</div>

<?php if ($is_tournament_session): ?>
<!-- Setup → Timer: the layout chooser + full layout editor, embedded. Rendered
     ONCE out here (not inside a settings pane) because the editor's preview
     iframe would reload on every pane re-render — panes are rebuilt via
     innerHTML and moving an iframe in the DOM reloads it. syncDisplayHome()
     reveals this block only while the Timer tab is active. -->
<div id="ckDisplayHome" hidden>
    <?php require __DIR__ . '/_timer_layouts_editor.php'; ?>
</div>
<script nonce="<?= csp_nonce() ?>">
var ES_CSRF = <?= json_encode($csrf) ?>;
var ES_EVENT_ID = <?= (int)$event_id ?>;
var ES_EVENT_NAME = <?= json_encode((string)$event['title']) ?>;
var ES_LAYOUT_ID = <?= json_encode($event_layout_id) ?>;
var ES_LAYOUT_KEY = <?= json_encode($event_layout_key) ?>;
</script>
<?php endif; ?>

<?php require __DIR__ . '/_footer.php'; ?>

<script nonce="<?= csp_nonce() ?>">
var CSRF = <?= json_encode($csrf, JSON_HEX_TAG) ?>;
var USE_BETA_TIMER = <?= (int)$use_beta_timer ?>;
<?php /* One-time Tournament Timer ask: any tournament game whose setup has been
         saved, while the user has never answered (users.beta_timer IS NULL).
         Not before setup: a host opening a brand-new game met this on top of
         the "set up game" prompt, two first-time asks at once, and the timer
         question has no meaning until there is a game to time. The copy
         adapts — a game already on the Tournament Timer asks about making it
         the DEFAULT, since "switch this game" would be incoherent there. Any
         answer is stored; it is never asked twice. */ ?>
var BETA_TIMER_ASK = <?= ($is_tournament_session
                          && !empty($session['setup_saved'])
                          && array_key_exists('beta_timer', $current) && $current['beta_timer'] === null) ? 1 : 0 ?>;
// "Not now" is remembered per browser rather than as an answer: the host has
// not chosen, so the server keeps them in the unanswered state and the banner
// simply stops pestering for a while. Any real answer clears the key.
var BETA_LATER_KEY = 'beta_timer_later';
var BETA_LATER_DAYS = 30;
function betaAskedRecently() {
    try {
        var t = parseInt(localStorage.getItem(BETA_LATER_KEY) || '0', 10);
        return t && (Date.now() - t) < BETA_LATER_DAYS * 86400000;
    } catch (e) { return false; }
}
if (BETA_TIMER_ASK && !betaAskedRecently()) {
    window.addEventListener('load', function () {
        var el = document.getElementById('betaTimerModal');
        if (!el) return;
        // A game already on the new timer is being asked about the DEFAULT,
        // not about switching, so the copy has to say something different.
        if (USE_BETA_TIMER) {
            var m = document.getElementById('betaBannerMsg');
            if (m) m.textContent = 'This game already uses it. Make it your default so new games start there too?';
            var yes = el.querySelector('.pk-beta-yes');
            if (yes) yes.textContent = 'Make it my default';
        }
        openModal('betaTimerModal', '.pk-beta-yes');
    });
}
// Both answers are final: stored server-side, never asked again.
function betaTimerAnswer(value) {
    var yes = String(value) === '1';
    closeModal('betaTimerModal');
    try { localStorage.removeItem(BETA_LATER_KEY); } catch (e) {}
    var body = new URLSearchParams();
    body.set('action', 'beta_timer_pref'); body.set('csrf_token', CSRF);
    body.set('event_id', EVENT_ID); body.set('value', yes ? '1' : '0');
    fetch('/event_setup_dl.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: String(body) })
        .then(function (r) { return r.json(); })
        // Reload only when the answer changed what this page shows.
        .then(function (j) { if (j && j.ok && yes && !USE_BETA_TIMER) location.reload(); })
        .catch(function () {});
}
function betaTimerLater() {
    closeModal('betaTimerModal');
    try { localStorage.setItem(BETA_LATER_KEY, String(Date.now())); } catch (e) {}
}

// Keep --pk-nav-h in step with the sticky site nav so .pk-header parks under
// it instead of behind it. Observed rather than hard-coded: the nav is 42px
// collapsed, taller once its links row is open.
(function () {
    var nav = document.querySelector('nav');
    if (!nav) return;
    function sync() {
        document.documentElement.style.setProperty('--pk-nav-h', Math.round(nav.getBoundingClientRect().height) + 'px');
    }
    sync();
    if (window.ResizeObserver) new ResizeObserver(sync).observe(nav);
    else window.addEventListener('resize', sync);
})();
// This page installs its own delegated dispatcher below. Tell the shared
// pk-dispatch.js (loaded from _footer.php) to stand down, or every control
// fires twice — a rebuy would add 2 and a buy-in would toggle on then off.
window.PK_DISPATCH_LOCAL = 1;

// ── Declarative handler dispatch (CSP step 2, see SECURITY.md) ────────────
// Inline on* attributes cannot be authorised by a nonce, so controls carry a
// data-act* attribute naming the function to call and these delegated listeners
// invoke it. Delegation on document is required rather than tidier: almost
// everything in this console is re-rendered from JS, so per-element binding
// would be lost on every repaint.
//   data-act        click  -> fn()          (bubbles, so it works on children)
//   data-act-self   click  -> fn()  ONLY when the click is on the element
//                                    itself, the modal-backdrop pattern that
//                                    used to be if(event.target===this)
//   data-act-input  input  -> fn()
// Arguments ride in data-a1..data-a4. They arrive as strings, so a value that
// looks numeric or boolean is coerced back: the inline form passed
// toggleBuyin(12) as a NUMBER, and reproducing that exactly is the point.
// A leading @ marks a value read from the element or the event at call time —
// the inline forms these replaced said this.value, this.checked and so on.
// ── Named replacements for former inline expressions (CSP step 2) ─────────
// Each of these was an inline lambda or a DOM mutation written straight into an
// on* attribute. A nonce cannot authorise those, so they get names.
function finishGameConfirm() {
    // Finish is final for the standings, so the dialog says what it is about
    // to lock in and flags the two things a first-time host does not see
    // coming: players still in (no place, so their places pay nobody) and a
    // prize pool with no payout structure. It used to say only "finalizes all
    // stats and payouts" and then finish without a word.
    var inPlay = (PLAYERS || []).filter(function(p) { return !parseInt(p.removed) && parseInt(p.bought_in); });
    var names = function(list) {
        var n = list.map(function(p) { return '<b>' + escHtml(p.display_name) + '</b>'; });
        if (n.length > 4) n = n.slice(0, 3).concat(['and ' + (n.length - 3) + ' more']);
        return n.join(', ');
    };
    var who = function(n) { return n === 1 ? '1 player is' : n + ' players are'; };
    var warn = [], note = '', lead = '', summary = '';
    if (isTourney()) {
        var placed   = inPlay.filter(function(p) { return parseInt(p.finish_position) > 0; });
        var stillIn  = inPlay.filter(function(p) { return !parseInt(p.eliminated) && !(parseInt(p.finish_position) > 0); });
        var hasFirst = placed.some(function(p) { return parseInt(p.finish_position) === 1; });
        var pool = parseInt(POOL.pool_total) || 0;
        var places = placed.length, paid = 0;
        placed.forEach(function(p) { paid += payoutForPlace(p.finish_position); });
        if (!inPlay.length) {
            warn.push('Nobody has bought in, so there are no standings to record.');
        } else if (stillIn.length === 1 && !hasFirst) {
            // The server records 1st for a lone survivor, as the final elimination would have.
            note = names(stillIn) + ' is the last player standing and takes 1st place.';
            places += 1;
            paid += payoutForPlace(1);
        } else if (stillIn.length) {
            warn.push(who(stillIn.length) + ' still in: ' + names(stillIn) + '. They get no place, so '
                + (hasFirst ? '' : 'no winner is recorded and ') + 'whatever their places pay goes to nobody. '
                + 'Eliminate players as they bust; the last one standing finishes the game by itself.');
        }
        if (inPlay.length && pool > 0 && !PAYOUTS.length) {
            warn.push('No payout structure is set, so the ' + formatMoney(pool) + ' prize pool pays nobody. '
                + 'Set one in Setup &rarr; Payouts first if money is on the table.');
        }
        var tickets = parseInt(SESSION.ticket_target_event_id) > 0 && PAYOUTS.some(function(r) { return parseInt(r.ticket_cents) > 0; });
        lead = 'This locks in the standings: ';
        summary = places + (places === 1 ? ' place' : ' places') + ' and ' + formatMoney(paid) + ' in payouts'
            + (tickets ? ', and the entry tickets go out' : '');
    } else {
        var seated = inPlay.filter(function(p) { return p.cash_out === null || p.cash_out === undefined; });
        if (!inPlay.length) {
            warn.push('Nobody has bought in, so there is nothing to record.');
        } else if (seated.length) {
            warn.push(who(seated.length) + ' still seated: ' + names(seated) + '. Until they cash out, their result is '
                + 'recorded as losing the whole buy-in (cashed out $0). Cash Out is in the List view.');
        }
        lead = 'This locks in every player\'s result: ';
        summary = formatMoney(POOL.total_cash_in || 0) + ' in and ' + formatMoney(POOL.total_cash_out || 0) + ' out across '
            + inPlay.length + (inPlay.length === 1 ? ' player' : ' players');
    }
    var msg = '';
    warn.forEach(function(w) {
        msg += '<p style="margin:0 0 .6rem;padding:.5rem .7rem;background:#fffbeb;border-left:3px solid #f59e0b;color:#92400e;border-radius:4px">&#9888; ' + w + '</p>';
    });
    if (note) msg += '<p style="margin:0 0 .6rem">' + note + '</p>';
    msg += '<p style="margin:0">' + lead + summary + '. <b>Reopen</b> puts it back if something is wrong.</p>';
    pkConfirm(msg, { title: 'Finish the game?', okLabel: warn.length ? 'Finish anyway' : 'Finish', danger: warn.length > 0 })
        .then(function (ok) {
            if (!ok) return;
            postAction('update_status', { session_id: SESSION.id, status: 'finished' }, function(j) {
                SESSION.status = j.status;
                renderDashboard();
                loadSession();   // the winnings, and 1st for a lone survivor, were written server-side
                walkinToast('Game finished: ' + summary + '. Reopen puts it back.');
            });
        });
}
function reopenGameConfirm() {
    pkConfirm('Reopen this game?').then(function (ok) { if (ok) changeStatus('active'); });
}
async function goBackOrHome(ev) {
    // Was: if(history.length>1){history.back();return false;}
    var href = (ev.target && ev.target.closest && ev.target.closest('a') || {}).href || '/calendar.php';
    if (SETTINGS_OPEN && SETTINGS_DIRTY) {
        ev.preventDefault();
        if (!(await pkConfirm('Discard unsaved settings changes?', { okLabel: 'Discard', danger: true }))) return;
        SETTINGS_DIRTY = false;   // so the unload prompt does not ask a second time
        if (history.length > 1) history.back(); else location.href = href;
        return;
    }
    if (history.length > 1) { ev.preventDefault(); history.back(); }
}
function openPayoutsSetup(ev) {
    if (ev) ev.preventDefault();
    openSettings('payouts');
}
function removeSelfParent() { this.parentNode.remove(); }
function removePayoutRow() {
    this.parentNode.remove();
    updatePayoutSum();
    markSettingsDirty();
}
function closeBalanceModal() {
    var m = document.getElementById('balanceModal');
    if (m) m.remove();
    restoreFocus(MODAL_OPENER.balanceModal);
    MODAL_OPENER.balanceModal = null;
}
function cashInOnEnter(ev, pid) {
    if (ev.key !== 'Enter') return;
    ev.preventDefault();
    setCashIn(pid, this.value);
    focusNextCashInput(this);
}
function cashOutOnEnter(ev, pid) {
    if (ev.key !== 'Enter') return;
    ev.preventDefault();
    commitCashOut(pid, this.value);
}

function _token(tok, el, ev) {
    switch (tok) {
        case '@value':      return el.value;
        case '@checked':    return el.checked;
        case '@checked01':  return el.checked ? 1 : 0;
        case '@prevValue':  return el.previousElementSibling ? el.previousElementSibling.value : '';
        case '@dataU':      return el.dataset.u;
        case '@event':      return ev;
        case '@self':       return el;
        default:            return tok;
    }
}
// Arguments are namespaced PER EVENT. One element can carry several data-act*
// attributes (the walk-in box has both input and keydown), and a shared data-aN
// namespace means a duplicate attribute — HTML keeps the first, so the second
// handler silently receives the wrong arguments. Click keeps the bare data-aN
// form because it is the common case; every other event prefixes its own.
function _args(el, ev, pfx) {
    var out = [];
    for (var i = 1; i <= 4; i++) {
        var v = el.getAttribute('data-' + (pfx || '') + 'a' + i);
        if (v === null) break;
        if (v.charAt(0) === '@')     out.push(_token(v, el, ev));
        else if (v === 'true')       out.push(true);
        else if (v === 'false')      out.push(false);
        else if (v !== '' && !isNaN(v)) out.push(Number(v));
        else out.push(v);
    }
    return out;
}
function _dispatch(name, el, ev, pfx) {
    var fn = window[name];
    if (typeof fn === 'function') return fn.apply(el || null, el ? _args(el, ev, pfx) : []);
    console.warn('[checkin] no handler named', name);   // a dead control is silent otherwise
}
document.addEventListener('click', function (e) {
    // Backdrop first: it must not fire when a child was clicked.
    if (e.target instanceof Element && e.target.hasAttribute('data-act-self')) {
        _dispatch(e.target.getAttribute('data-act-self'), e.target, e);
        return;
    }
    if (!e.target.closest) return;
    // data-stop replaces an inline stopPropagation(), which sat ON the element
    // and kept the click from reaching an ANCESTOR's handler. Calling
    // stopPropagation() from here would be too late: the event has already
    // reached document. Treat it as a boundary instead — find the nearest
    // data-act OR data-stop, and if the boundary is nearer, dispatch nothing.
    var t = e.target.closest('[data-act], [data-stop]');
    if (!t || t.hasAttribute('data-stop')) return;
    _dispatch(t.getAttribute('data-act'), t, e);
});
document.addEventListener('change', function (e) {
    var t = e.target.closest ? e.target.closest('[data-act-change]') : null;
    if (t) _dispatch(t.getAttribute('data-act-change'), t, e, 'change-');
});
document.addEventListener('keydown', function (e) {
    var t = e.target.closest ? e.target.closest('[data-act-keydown]') : null;
    if (t) _dispatch(t.getAttribute('data-act-keydown'), t, e, 'keydown-');
});
document.addEventListener('mousedown', function (e) {
    var t = e.target.closest ? e.target.closest('[data-act-mousedown]') : null;
    if (t) _dispatch(t.getAttribute('data-act-mousedown'), t, e, 'mousedown-');
});
document.addEventListener('input', function (e) {
    var t = e.target.closest ? e.target.closest('[data-act-input]') : null;
    if (t) _dispatch(t.getAttribute('data-act-input'), t, e, 'input-');
});
var ALL_USERS = <?= json_encode($allUsernames, JSON_HEX_TAG) ?>;
var EVENT_ID = <?= $event_id ?>;
var EVENT_LEAGUE_ID = <?= (int)($event['league_id'] ?? 0) ?>;
var ASSIGNABLE_LEAGUES = <?= json_encode(array_map(
        function ($id, $name) { return ['id' => (int)$id, 'name' => $name]; },
        array_keys($assignable_leagues), array_values($assignable_leagues)
    ), JSON_HEX_TAG) ?>;
// A co-host with no league role anywhere can see the binding but must not be
// offered a change — the server refuses it, so don't present it as possible.
var CAN_MOVE_LEAGUE = <?= $_canMoveLeague ? 'true' : 'false' ?>;
var IS_ADMIN = <?= $isAdmin ? 'true' : 'false' ?>;
// Needed to tell "my preset" from "someone else's" without a round-trip; the
// preset menu greys out what the server would refuse, and says why.
var CURRENT_USER_ID = <?= (int)$current['id'] ?>;
var SESSION = <?= $session ? json_encode($session, JSON_HEX_TAG) : 'null' ?>;
var PAYOUT_STRUCTURES = [];
var CURRENT_STRUCTURE_ID = 0;
var PLAYERS = [];
var PAYOUTS = [];
var POOL = {};
var FILTER = 'all';
// Declined invitees (RSVP No, not bought in) are folded out of the All list
// behind a "said no" line; this is that line's Show / Hide.
var SHOW_DECLINED = false;
var VIEW_MODE = 'list';
var SORT_KEY = '';   // '' = default (entry order); otherwise a column key
var SORT_DIR = 1;    // 1 = ascending, -1 = descending
var notesPlayerId = null;
var LOG = [];        // session activity log entries (newest-first)
var TICKETS = { incoming: [], outgoing: [] };  // entry tickets touching this session
var TIMER_LEVEL = 0;   // the clock's blind level, from get_session (0 = no timer yet)
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
                TIMER_LEVEL = parseInt(j.timer_level) || 0;
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
    h += '<label id="s_type_lbl">Game Type</label>';
    h += '<div class="pk-type-toggle" id="typeToggle" role="group" aria-labelledby="s_type_lbl">';
    h += '<button type="button" class="t-tournament active" aria-pressed="true" data-act="setSetupType" data-a1="tournament">Tournament</button>';
    h += '<button type="button" class="t-cash" aria-pressed="false" data-act="setSetupType" data-a1="cash">Cash Game</button>';
    h += '</div>';
    h += '<label for="s_buyin">Buy-in Amount</label><div class="pk-money-wrap"><input type="number" id="s_buyin" value="20" step="1" min="0"></div>';
    h += '<div id="setupTourneyFields">';
    h += '<label for="s_rebuy">Rebuy Amount</label><div class="pk-money-wrap"><input type="number" id="s_rebuy" value="20" step="1" min="0"></div>';
    h += '<label for="s_addon">Add-on Amount</label><div class="pk-money-wrap"><input type="number" id="s_addon" value="10" step="1" min="0"></div>';
    h += '<label for="s_chips">Starting Chips</label><input type="number" id="s_chips" value="5000" step="1" min="1">';
    h += '<label for="s_addon_chips">Add-on Chips</label><input type="number" id="s_addon_chips" value="5000" step="1" min="0">';
    h += '</div>';
    h += '<label for="s_tables">Number of Tables</label><input type="number" id="s_tables" value="1" step="1" min="1">';
    h += '<button type="submit" data-act="initSession">Create Session &amp; Import Players</button>';
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
    btns.forEach(function(b) { b.classList.remove('active'); b.setAttribute('aria-pressed', 'false'); });
    var cur = document.querySelector('#typeToggle .t-' + type);
    cur.classList.add('active'); cur.setAttribute('aria-pressed', 'true');
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
    // Switching a tournament to a cash game in Settings removes the Payouts
    // segment — don't leave the user parked on a view that no longer exists.
    if ((VIEW_MODE === 'payouts' || VIEW_MODE === 'chop') && isCash()) VIEW_MODE = 'list';
    document.body.classList.toggle('view-payouts', VIEW_MODE === 'payouts');
    var h = '';

    // Header
    h += '<div class="pk-header">';
    h += '<a href="/calendar.php" class="pk-btn-back" title="Back" aria-label="Back" style="text-decoration:none" data-act="goBackOrHome" data-a1="@event">&larr;</a>';
    h += '<h1>' + escHtml(<?= json_encode($event['title'], JSON_HEX_TAG) ?>) + ' <a href="/calendar.php"><span class="pk-act-label">Calendar</span></a></h1>';
    h += '<span class="pk-badge ' + typeClass + '">' + typeLabel + '</span>';
    h += '<div class="pk-actions">';
    // Settings moved into the view switcher below — a duplicate entry point here
    // would defeat the point of grouping it with the other things hosts open.
    //
    // Help is about the whole screen, not the player list, so it belongs with
    // the other page-level actions. It used to sit in the toolbar with
    // margin-left:auto, which in a wrapping flex row pins it to the right end of
    // whichever line it happens to land on — so it moved as the window resized.
    // First in the group keeps it clear of Finish and steady when the
    // conditional Timer / Cash Box / Jackpot buttons come and go.
    h += '<button class="pk-btn-settings" title="How this screen works" aria-label="Help" data-act="openHelp">?<span class="pk-act-label"> Help</span></button>';
    if (isTourney()) {
        h += '<a class="pk-btn-settings" id="timerLink" href="' + (USE_BETA_TIMER ? '/timer.php' : '/timer.php') + '?event_id=' + <?= (int)$event['id'] ?> + '" style="text-decoration:none" title="Timer" aria-label="Timer">&#9201;<span class="pk-act-label"> Timer</span></a>';
    }
    // Not gated on the game type: the Table Manager runs cash games too, and a
    // cash host is the one most likely to be standing at the table with a phone.
    h += '<a class="pk-btn-settings" href="/table_manager.php?event_id=' + <?= (int)$event['id'] ?> + '" style="text-decoration:none" title="Table Manager: phone-first table console" aria-label="Table Manager">&#128242;<span class="pk-act-label"> Table Mgr</span></a>';
    h += '<a class="pk-btn-settings" href="/walkin_display.php?event_id=' + <?= (int)$event['id'] ?> + '" target="_blank" style="text-decoration:none" title="QR Registration" aria-label="QR registration">&#128241;<span class="pk-act-label"> QR</span></a>';
    if (isTourney()) {
        // The deal-split calculator moved into the view switcher as "Chop".
    } else {
        h += '<button class="pk-btn-settings" data-act="openCashBox" title="Cash box: record tips and square the box" aria-label="Cash Box">&#129534;<span class="pk-act-label"> Cash Box</span></button>';
    }
    // League jackpots: record a bad-beat / royal hit from the table.
    if (JACKPOTS.league_id) {
        h += '<button class="pk-btn-settings" data-act="openJackpotModal" title="League jackpots: view funds and record a hit" aria-label="Jackpot">💎<span class="pk-act-label"> Jackpot</span></button>';
    }
    // Game lifecycle lives in the header now, not buried in Settings.
    if (SESSION.status !== 'finished') {
        h += '<button class="pk-btn-green" data-act="finishGameConfirm" title="Finish the game and lock in payouts" aria-label="Finish">&#10003;<span class="pk-act-label"> Finish</span></button>';
    } else {
        h += '<button class="pk-btn-settings" style="color:#d97706;border-color:#fcd34d" data-act="reopenGameConfirm" title="Reopen the finished game" aria-label="Reopen">&#8634;<span class="pk-act-label"> Reopen</span></button>';
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
    // Two groups, so the row breaks where it means something. Left flat, the
    // toolbar wrapped wherever it happened to run out — usually between Setup
    // and the view strip, orphaning the views on their own line under a
    // half-empty first row. Grouping makes the only break point the one that
    // reads as deliberate: "add a player" above, "what am I looking at" below.
    h += '<div class="pk-toolbar">';
    h += '<div class="pk-tb-group pk-tb-add">';
    h += '<div class="walkin-autocomplete">';
    h += '<input type="text" id="walkinName" placeholder="Walk-in name..." aria-label="Walk-in name" autocomplete="off" role="combobox" aria-autocomplete="list" aria-haspopup="listbox" aria-expanded="false" aria-controls="walkinDropdown" data-act-input="walkinSuggest" data-input-a1="@value" data-act-keydown="walkinKeydown" data-keydown-a1="@event">';
    h += '<div class="walkin-dropdown" id="walkinDropdown" role="listbox" aria-label="Matching players"></div>';
    h += '</div>';
    h += '<button class="pk-btn-add" data-act="addWalkin">+ Add</button>';
    h += '</div>';
    h += '<div class="pk-tb-group pk-tb-controls">';
    // The player filter used to live here. It belongs to the page, not the
    // toolbar: it only applies to List and Table, so in the toolbar it appeared
    // and vanished and shoved Setup and the view strip sideways every time you
    // changed view. It now renders at the top of the views that use it, inside
    // #viewContent, so it slides in with them and the toolbar never moves.
    // Setup sits between the two sliders. It is neither a filter nor a view, and
    // an outlined button of a different shape between two grey pills is already
    // hard to skim past — which is the whole point, since "Settings" read as
    // optional preferences when it holds the buy-in, chips, rebuys and the
    // whole payout structure. No dividers: the shape does the separating.
    h += '<button class="pk-btn-setup' + (VIEW_MODE === 'settings' ? ' active' : '') + '" id="setupBtn" title="Buy-in, chips, rebuys, payouts, rewards and blinds" aria-label="Setup" data-act="setViewMode" data-a1="settings">&#9881;<span class="pk-seg-label"> Setup</span></button>';
    // The strip holds VIEWS — things you look at. Six equal segments made Setup
    // read as a sixth view and it got lost, hence the button above. Labels
    // collapse to icons on phones, Setup's along with them.
    h += '<div class="pk-seg pk-view-seg" id="viewSeg" data-seg-role="tablist" aria-label="Views">';
    h += '<span class="pk-seg-thumb"></span>';
    h += '<button data-view="list" class="' + (VIEW_MODE === 'list' ? 'active' : '') + '" title="Player list" aria-label="List" aria-controls="viewContent" data-act="setViewMode" data-a1="list">&#9776;<span class="pk-seg-label"> List</span></button>';
    h += '<button data-view="table" class="' + (VIEW_MODE === 'table' ? 'active' : '') + '" title="Table &amp; seat layout" aria-label="Table" aria-controls="viewContent" data-act="setViewMode" data-a1="table">&#9638;<span class="pk-seg-label"> Table</span></button>';
    h += '<button data-view="log" class="' + (VIEW_MODE === 'log' ? 'active' : '') + '" title="Activity log: buy-ins, cash-outs, adds and more" aria-label="Log" aria-controls="viewContent" data-act="setViewMode" data-a1="log">&#128203;<span class="pk-seg-label"> Log</span></button>';
    if (isTourney()) {
        h += '<button data-view="payouts" class="' + (VIEW_MODE === 'payouts' ? 'active' : '') + '" title="Prize ladder, pool breakdown and rewards" aria-label="Payouts" aria-controls="viewContent" data-act="setViewMode" data-a1="payouts">&#128176;<span class="pk-seg-label"> Payouts</span></button>';
        h += '<button data-view="chop" class="' + (VIEW_MODE === 'chop' ? 'active' : '') + '" title="Deal split calculator: ICM, standard or chip chop" aria-label="Chop" aria-controls="viewContent" data-act="setViewMode" data-a1="chop">&#129535;<span class="pk-seg-label"> Chop</span></button>';
    }
    h += '</div>';
    h += '</div>';  // .pk-tb-controls
    // Balance and Add Table act on the seating chart, so they render inside the
    // Table view (see renderFilterBar) rather than here. In the toolbar they
    // were the last thing making it change size between views.
    h += '</div>';

    // Inline pool/payout summary for mobile/tablet (compact bar above player list)
    h += '<div class="pk-inline-summary" id="inlineSummary">' + renderInlineSummary() + '</div>';

    h += '<div id="setupCta">' + renderSetupCta() + '</div>';

    h += '<div id="viewContent" role="tabpanel">' + renderViewContent() + '</div>';
    h += '</div>';

    // Right: sidebar
    h += '<div class="pk-sidebar">';
    h += '<div class="pk-card" id="poolCard">' + renderPoolCard() + '</div>';
    if (isTourney()) {
        h += '<div class="pk-card" id="payoutCard">' + renderPayoutCard() + '</div>';
    }
    h += '</div>';

    h += '</div>'; // pk-grid

    var fs = focusSnapshot();
    document.getElementById('app').innerHTML = h;
    positionAllSegThumbs(false);
    if (VIEW_MODE === 'log') renderLog();
    focusRestore(fs);
}

// Renders the content area for the active view (list / table / log).
// Per-view controls, rendered into the page rather than the toolbar: the player
// filter (only List and Table read FILTER) and, in Table view, the two seating
// actions. Putting them here means they slide in with the view they belong to,
// and the toolbar above stays exactly the same size and shape on every screen.
// Deliberately no margin-left:auto on the actions — in a wrapping flex row that
// pins to the end of whichever line they land on, which is what made the old
// Help button drift.
function renderFilterBar() {
    if (!filterApplies(VIEW_MODE)) return '';
    var h = '<div class="pk-filter-bar"><div class="pk-seg pk-filter" id="filterSeg" aria-label="Show">';
    h += '<span class="pk-seg-thumb"></span>';
    h += '<button data-filter="all" class="' + (FILTER==='all'?'active':'') + '" data-act="setFilter" data-a1="all">All</button>';
    if (isTourney()) h += '<button data-filter="rsvp_yes" class="' + (FILTER==='rsvp_yes'?'active':'') + '" data-act="setFilter" data-a1="rsvp_yes">RSVP Yes</button>';
    if (isTourney()) {
        h += '<button data-filter="playing" class="' + (FILTER==='playing'?'active':'') + '" data-act="setFilter" data-a1="playing">Playing</button>';
        h += '<button data-filter="eliminated" class="' + (FILTER==='eliminated'?'active':'') + '" data-act="setFilter" data-a1="eliminated">Out</button>';
    } else {
        h += '<button data-filter="playing" class="' + (FILTER==='playing'?'active':'') + '" data-act="setFilter" data-a1="playing">Active</button>';
        h += '<button data-filter="eliminated" class="' + (FILTER==='eliminated'?'active':'') + '" data-act="setFilter" data-a1="eliminated">Out</button>';
    }
    h += '</div>';
    if (VIEW_MODE === 'table') {
        // Muted, not hidden, on a one-table game: a host should still find
        // out what it is for, and pressing it explains instead of opening a
        // dialog about dealer buttons with nothing to move.
        var oneTable = parseInt(SESSION.num_tables) <= 1;
        h += '<button id="balanceBtn" class="pk-btn-view-toggle"' + (oneTable ? ' style="opacity:.55"' : '')
           + ' title="' + (oneTable ? 'Evens out the players across tables. This game has one table, so there is nothing to balance yet.' : 'Even out the number of players at each table') + '"'
           + ' data-act="balanceTables">&#9878; Balance</button>';
        h += '<button id="addTableBtn" class="pk-btn-green" data-act="addTable">Add Table</button>';
    }
    h += '</div>';
    return h;
}

function renderViewContent() {
    if (VIEW_MODE === 'settings') return renderSettingsView();
    if (VIEW_MODE === 'payouts') return renderPayoutsView();
    if (VIEW_MODE === 'chop') return renderChopView();
    if (VIEW_MODE === 'log') {
        return '<div class="pk-log-sub">Buy-ins, rebuys, cash-outs, adds &amp; more &mdash; newest first, last 200. '
             + '<b>Edit</b> corrects a wrong amount in place; <b>Clear</b> reverses an entry from the player\'s totals. '
             + 'A player\'s full history is in their ledger (&#128210; next to their name).</div>'
             + '<div class="pk-log-list" id="logList"><div class="pk-log-empty">Loading&hellip;</div></div>';
    }
    if (VIEW_MODE === 'table') return renderFilterBar() + renderTableView();

    var h = renderFilterBar();
    h += '<div class="pk-bulk-bar" id="bulkBar">';
    h += '<span class="pk-bulk-count" id="bulkCount">0 selected</span>';
    if (isTourney()) {
        h += '<button class="primary" title="Record buy-in for the selected players (also checks them in and seats them)" data-act="bulkAction" data-a1="toggle_buyin">Buy In</button>';
        h += '<button data-act="bulkAction" data-a1="eliminate_player">Eliminate</button>';
        if (parseInt(SESSION.addon_allowed)) h += '<button title="Record one add-on for each selected player still in the game; anyone who already has one is skipped" data-act="bulkAddons" data-a1="selected">Add-on</button>';
    }
    h += '<button title="Approve the selected pending self-signups / walk-ins onto the roster" data-act="bulkAction" data-a1="approve_player">Approve</button>';
    h += '<button class="danger" data-act="bulkRemoveConfirm">Remove</button>';
    h += '<button data-act="clearSelection">Clear</button>';
    h += '</div>';
    h += '<div class="pk-table-wrap"><table class="pk-table">';
    h += '<thead><tr id="playerHead">' + renderTableHeader() + '</tr></thead>';
    h += '<tbody id="playerBody">' + renderPlayerRows() + '</tbody></table></div>';
    h += '<div class="pk-mobile-list" id="mobileList">' + renderBreakAddonBar() + renderMobileCards() + '</div>';
    return h;
}

function sortableTh(label, key, extra) {
    var arrow = (SORT_KEY === key) ? ' <span style="font-size:.7em">' + (SORT_DIR === 1 ? '▲' : '▼') + '</span>' : '';
    var sort = (SORT_KEY === key) ? (SORT_DIR === 1 ? 'ascending' : 'descending') : 'none';
    // The label is a button so the keyboard can sort; it carries no data-act
    // of its own, so a click or Enter dispatches once through the th. The ?
    // tip (focusable in its own right) sits beside the button, not inside it.
    var at = label.indexOf('<span class="pk-tip"');
    var text = at >= 0 ? label.slice(0, at) : label, tipHtml = at >= 0 ? label.slice(at) : '';
    return '<th scope="col" class="pk-sortable" aria-sort="' + sort + '" ' + (extra || '') + ' data-act="setSort" data-a1="' + key + '">'
         + '<button type="button" class="pk-sort-btn">' + text.trim() + arrow + '</button>' + tipHtml + '</th>';
}

function renderTableHeader() {
    var h = '<th scope="col" style="width:2rem"><span style="display:inline-flex;align-items:center"><input type="checkbox" id="selectAll" class="pk-row-select" aria-label="Select all players" data-act-change="toggleSelectAll" data-change-a1="@checked">' + tip('Select one or more players to act on several at once (buy in, remove, etc.). You don\'t need this to enter a single player.') + '</span></th>';
    h += '<th scope="col">#</th>';
    h += sortableTh('Name', 'name');
    if (isTourney()) h += sortableTh('RSVP', 'rsvp');
    if (isTourney()) {
        // Named, not "$": a first-time host read the bare sign over a checkbox as
        // "paid?" and looked for a Buy In control elsewhere. Matches the bulk
        // bar's button and the phone card's label.
        h += sortableTh('Buy In ' + tip('Tick the box to record a buy-in. It also checks the player in and seats them. The 📒 ledger icon shows their buy-in / rebuy / add-on history and lets you edit or clear a mistake.'), 'buyin');
        if (parseInt(SESSION.jackpot_amount) > 0 && parseInt(SESSION.jackpot_optional)) h += sortableTh('💎 ' + tip('Optional jackpot side entry (' + formatMoney(parseInt(SESSION.jackpot_amount)) + ', on top of the buy-in). Tick for each player who\'s in — entries feed the league jackpot at finish.'), 'jackpot', 'title="Jackpot entry"');
        if (parseInt(SESSION.bounty_amount) > 0 && parseInt(SESSION.bounty_optional)) h += sortableTh('🎯 ' + tip('Optional bounty side pot (' + formatMoney(parseInt(SESSION.bounty_amount)) + ', on top of the buy-in). Tick for each player who\'s in — only pool members carry and collect bounties.'), 'bountyin', 'title="Bounty side pot"');
        if (parseInt(SESSION.rebuy_allowed)) h += sortableTh('Rebuys' + rebuysIncludedTip(), 'rebuys');
        if (parseInt(SESSION.addon_allowed)) h += sortableTh('Add-ons' + addonsIncludedTip(), 'addons');
    } else {
        h += sortableTh('Cash In ' + tip('Type the total they bought in for and press Enter. Use + to add a top-up. The 📒 ledger icon shows every entry and lets you edit or clear a wrong one.'), 'totalin');
        h += sortableTh('Cash Out ' + tip('Type what they leave the table with and press the green check (or Enter). Clear the field to put them back in play.'), 'cashout');
        h += sortableTh('Profit', 'profit');
    }
    h += sortableTh('Table', 'table');
    h += sortableTh('Seat', 'seat');
    h += sortableTh('Status', 'status');
    h += '<th scope="col">Actions</th>';
    return h;
}

function setSort(key) {
    if (SORT_KEY === key) { SORT_DIR = -SORT_DIR; }
    else { SORT_KEY = key; SORT_DIR = 1; }
    var head = document.getElementById('playerHead');
    if (head) head.innerHTML = renderTableHeader();
    refreshUI();
    // The header was rebuilt under the keyboard: land on the same column's button.
    var th = document.querySelector('th[data-act="setSort"][data-a1="' + key + '"] .pk-sort-btn');
    if (th) th.focus({ preventScroll: true });
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
    var paidRebuys = Math.max(0, (parseInt(p.rebuys) || 0) - (parseInt(SESSION.rebuys_included) || 0));
    return (parseInt(p.bought_in) * buyinAmt) + (paidRebuys * rebuyAmt);
}

function playerProfit(p) {
    if (p.cash_out === null || p.cash_out === undefined) return null;
    return parseInt(p.cash_out) - playerTotalIn(p);
}

// The one filter predicate for the player list. renderPlayerRows(),
// renderMobileCards() and addWalkin() all read it, so "would this player show
// right now?" has a single answer — addWalkin() needs it to notice that the
// person it just added would be filtered straight back out.
// renderTableView() deliberately keeps its own copy: the seating chart has
// never branched on cash vs tournament for "playing".
function isDeclined(p) { return p.rsvp === 'no' && !parseInt(p.bought_in); }
function passesFilter(p) {
    if (FILTER === 'rsvp_yes') return p.rsvp === 'yes';
    if (FILTER === 'all' && !SHOW_DECLINED && isDeclined(p)) return false;
    if (isCash()) {
        if (FILTER === 'playing') return parseInt(p.bought_in) && (p.cash_out === null || p.cash_out === undefined);
        if (FILTER === 'eliminated') return p.cash_out !== null && p.cash_out !== undefined;
    } else {
        if (FILTER === 'playing') return !parseInt(p.eliminated) && parseInt(p.bought_in);
        if (FILTER === 'eliminated') return parseInt(p.eliminated);
    }
    return true;
}

// "6 said no · Show": a host who has to scroll past (or Remove) everyone who
// declined is looking at the wrong list, but a No who turns up anyway must
// stay one tap away. Only on the All view; the other views never show them.
function declinedLine() {
    if (FILTER !== 'all') return '';
    var n = PLAYERS.filter(isDeclined).length;
    if (!n) return '';
    return '<span class="pk-declined-line">' + n + ' said no'
         + ' <button type="button" class="pk-declined-btn" data-act="toggleDeclined" aria-expanded="' + (SHOW_DECLINED ? 'true' : 'false') + '">'
         + (SHOW_DECLINED ? 'Hide' : 'Show') + '</button></span>';
}
function toggleDeclined() {
    SHOW_DECLINED = !SHOW_DECLINED;
    refreshUI();
}
function listColspan() {
    return isTourney()
        ? 7 + ((parseInt(SESSION.jackpot_amount)>0&&parseInt(SESSION.jackpot_optional))?1:0) + ((parseInt(SESSION.bounty_amount)>0&&parseInt(SESSION.bounty_optional))?1:0) + (parseInt(SESSION.rebuy_allowed)?1:0) + (parseInt(SESSION.addon_allowed)?1:0) + (parseInt(SESSION.num_tables)>1?1:0)
        : 8 + (parseInt(SESSION.num_tables)>1?1:0);
}

function renderPlayerRows() {
    var h = '';
    var num = 0;
    var filtered = sortPlayers(PLAYERS.filter(passesFilter));
    for (var i = 0; i < filtered.length; i++) {
        var p = filtered[i];
        var nm = escHtml(p.display_name);   // every control in the row names the player
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
        h += '<td><input type="checkbox" class="pk-row-select pk-player-cb" value="' + p.id + '" aria-label="Select ' + nm + '" data-act-change="updateBulkBar"></td>';
        h += '<td>' + num + '</td>';
        h += '<td class="name-cell">' + escHtml(p.display_name);
        if (isWalkin) h += '<span class="walkin-badge" title="Walk-in player" aria-label="Walk-in player">&#128694;</span>';
        if (p.notes) h += ' <span title="' + escHtml(p.notes) + '" style="cursor:help">&#128221;</span>';
        h += '</td>';

        // RSVP dropdown (tournaments only — not used in the cash-game flow)
        if (isTourney()) {
            h += '<td><select class="pk-rsvp-select" aria-label="RSVP for ' + nm + '" data-act-change="updateRsvp" data-change-a1="' + p.id + '" data-change-a2="@value" style="font-size:.75rem;padding:.15rem .3rem;border-radius:4px;border:1px solid #e2e8f0;background:' + rsvpBg(rsvp) + ';color:' + rsvpColor(rsvp) + ';font-weight:600">';
            h += '<option value=""' + (rsvp===''?' selected':'') + '>—</option>';
            h += '<option value="yes"' + (rsvp==='yes'?' selected':'') + '>Yes</option>';
            h += '<option value="no"' + (rsvp==='no'?' selected':'') + '>No</option>';
            h += '<option value="maybe"' + (rsvp==='maybe'?' selected':'') + '>Maybe</option>';
            h += '</select></td>';
        }

        if (isPending) {
            h += '<td colspan="' + (isTourney() ? (1 + ((parseInt(SESSION.jackpot_amount)>0&&parseInt(SESSION.jackpot_optional))?1:0) + ((parseInt(SESSION.bounty_amount)>0&&parseInt(SESSION.bounty_optional))?1:0) + (parseInt(SESSION.rebuy_allowed)?1:0) + (parseInt(SESSION.addon_allowed)?1:0)) : 3) + '" style="text-align:center;color:#b45309;font-size:.8rem;font-style:italic">Awaiting approval</td>';
        } else {
        if (isTourney()) {
            h += '<td><div style="display:inline-flex;align-items:center;gap:.15rem"><input type="checkbox" class="pk-check" aria-label="Buy in for ' + nm + '" title="Record this player\'s buy-in. Checks them in and assigns a seat automatically — no separate check-in step needed." ' + (parseInt(p.bought_in) ? 'checked' : '') + dis + ' data-act-change="toggleBuyin" data-change-a1="' + p.id + '">' + ledgerBtn(p.id, nm) + '</div></td>';
            if (parseInt(SESSION.jackpot_amount) > 0 && parseInt(SESSION.jackpot_optional)) {
                h += '<td><input type="checkbox" class="pk-check" style="accent-color:#7c3aed" aria-label="Jackpot entry for ' + nm + '" title="Jackpot side entry (' + formatMoney(parseInt(SESSION.jackpot_amount)) + ')" ' + (parseInt(p.jackpot_in) ? 'checked' : '') + dis + ' data-act-change="toggleJackpot" data-change-a1="' + p.id + '"></td>';
            }
            if (parseInt(SESSION.bounty_amount) > 0 && parseInt(SESSION.bounty_optional)) {
                h += '<td><input type="checkbox" class="pk-check" style="accent-color:#0e7490" aria-label="Bounty for ' + nm + '" title="Bounty side pot (' + formatMoney(parseInt(SESSION.bounty_amount)) + ')" ' + (parseInt(p.bounty_in) ? 'checked' : '') + dis + ' data-act-change="toggleBounty" data-change-a1="' + p.id + '"></td>';
            }
            if (parseInt(SESSION.rebuy_allowed)) {
                h += '<td><div class="pk-counter"><button aria-label="Remove a rebuy for ' + nm + '" data-act="updateRebuys" data-a1="' + p.id + '" data-a2="-1"' + dis + '>-</button><span aria-label="Rebuys">' + p.rebuys + '</span><button aria-label="Add a rebuy for ' + nm + '"' + rebuyPlusAttrs() + ' data-act="updateRebuys" data-a1="' + p.id + '" data-a2="1"' + dis + '>+</button></div></td>';
            }
            if (parseInt(SESSION.addon_allowed)) {
                var aoCount = parseInt(p.addons) || 0;
                h += '<td style="width:6.5rem;min-width:6.5rem"><div style="display:flex;align-items:center;gap:.3rem;width:100%">'
                   + '<button class="pk-addon-btn" aria-label="Add an add-on for ' + nm + '" data-act="addAddon" data-a1="' + p.id + '"' + dis + ' style="font-size:.75rem;padding:.2rem .5rem;border-radius:4px;border:1px solid #c4b5fd;background:#f5f3ff;color:#6d28d9;cursor:pointer;font-weight:600;flex:0 0 auto;min-height:24px">+ Add-on</button>'
                   + (aoCount > 0
                        ? '<button type="button" class="pk-addon-count" aria-label="Remove the last add-on for ' + nm + ' (' + aoCount + ' taken)" title="Click to remove last add-on" data-act="removeAddon" data-a1="' + p.id + '"' + dis + '>' + aoCount + '</button>'
                        : '<span aria-hidden="true" style="display:inline-block;min-width:1.3rem;flex:0 0 auto"></span>')
                   + '</div></td>';
            }
        } else {
            // Cash game: total in (with add money button), cash out, profit
            var cashIn = parseInt(p.cash_in) || 0;
            if (isNo) {
                h += '<td><span style="color:#64748b">' + formatMoney(cashIn) + '</span></td>';
                h += '<td><span style="color:#64748b">—</span></td>';
                h += '<td><span style="color:#64748b">—</span></td>';
            } else {
                h += '<td><div class="pk-counter"><input type="number" inputmode="decimal" step="0.01" min="0" class="pk-cash-input" aria-label="Cash in for ' + nm + '" data-pid="' + p.id + '" value="' + (cashIn/100) + '" data-act-change="setCashIn" data-change-a1="' + p.id + '" data-change-a2="@value" data-act-keydown="cashInOnEnter" data-keydown-a1="@event" data-keydown-a2="' + p.id + '" style="border:none;min-width:60px"><button aria-label="Add money for ' + nm + '" data-act="adjustMoney" data-a1="' + p.id + '" data-a2="1">+</button>' + ledgerBtn(p.id, nm) + '</div></td>';
                if (parseInt(p.bought_in)) {
                    // Inline cash-out field (mirrors Cash In). Green check commits for mouse users;
                    // Enter also commits. Clearing the field reverts the player to still-playing.
                    var coVal = hasCashedOut ? (parseInt(p.cash_out) / 100) : '';
                    h += '<td><div class="pk-counter">'
                       + '<input type="number" inputmode="decimal" step="0.01" min="0" class="pk-co-input" aria-label="Cash out for ' + nm + '" value="' + coVal + '" placeholder="0.00" data-act-keydown="cashOutOnEnter" data-keydown-a1="@event" data-keydown-a2="' + p.id + '" style="border:none;min-width:60px">'
                       + '<button title="Record cash-out" aria-label="Record cash-out for ' + nm + '" style="color:#15803d;font-weight:700" data-act="commitCashOut" data-a1="' + p.id + '" data-a2="@prevValue">✓</button>'
                       + (hasCashedOut ? '' : '<button class="pk-bust-btn" title="Busted: out with $0. Frees their seat. Add cash to bring them back in." aria-label="' + nm + ' busted" data-act="bustOut" data-a1="' + p.id + '">&#128165;</button>')
                       + '</div></td>';
                    if (hasCashedOut) {
                        var prof = parseInt(p.cash_out) - cashIn;
                        var cls = prof > 0 ? 'pk-profit-pos' : (prof < 0 ? 'pk-profit-neg' : 'pk-profit-zero');
                        h += '<td><span class="' + cls + '">' + formatProfit(prof) + '</span></td>';
                    } else {
                        h += '<td><span style="color:#64748b">—</span></td>';
                    }
                } else {
                    h += '<td><span style="color:#64748b">—</span></td>';
                    h += '<td><span style="color:#64748b">—</span></td>';
                }
            }
        }
        } // close isPending else

        h += '<td><input type="number" class="pk-tbl-input" aria-label="Table for ' + nm + '" value="' + (p.table_number || '') + '" min="1" max="' + SESSION.num_tables + '" data-act-change="setTable" data-change-a1="' + p.id + '" data-change-a2="@value" style="width:3rem"></td>';
        h += '<td style="text-align:center;color:#64748b;font-size:.8rem;font-weight:600">' + (p.seat_number || '—') + '</td>';

        // Status
        if (isPending) {
            h += '<td><span style="color:#b45309;font-weight:600;background:#fefce8;padding:.1rem .4rem;border-radius:4px;font-size:.75rem;border:1px solid #fde68a">Pending</span></td>';
        } else if (isTourney()) {
            if (isElim) {
                var elAmt = parseInt(p.payout) || payoutForPlace(p.finish_position);
                h += '<td><span style="color:#dc2626;font-weight:600">' + ordinalLabel(p.finish_position) + '</span>'
                   + (elAmt > 0 ? ' <span style="color:#15803d;font-weight:700;font-size:.78rem" title="Prize owed">' + formatMoney(elAmt) + '</span>' : '')
                   + rewardChips(p)
                   + '</td>';
            } else if (isWinner) {
                var wAmt = parseInt(p.payout) || payoutForPlace(1);
                h += '<td><span style="color:#a16207;font-weight:700">🏆 1st</span>'
                   + (wAmt > 0 ? ' <span style="color:#15803d;font-weight:700;font-size:.78rem" title="Prize">' + formatMoney(wAmt) + '</span>' : '')
                   + rewardChips(p)
                   + '</td>';
            } else if (parseInt(p.bought_in)) {
                h += '<td><span style="color:#15803d;font-weight:600">Playing</span>' + rewardChips(p) + '</td>';
            } else {
                h += '<td><span style="color:#64748b">—</span></td>';
            }
        } else {
            if (hasCashedOut) {
                var busted = parseInt(p.cash_out) === 0;
                h += '<td><span style="color:' + (busted ? '#dc2626' : '#64748b') + ';font-weight:600">' + (busted ? 'Busted' : 'Cashed Out') + '</span></td>';
            } else if (parseInt(p.bought_in)) {
                h += '<td><span style="color:#15803d;font-weight:600">Playing</span></td>';
            } else {
                h += '<td><span style="color:#64748b">—</span></td>';
            }
        }

        // Actions
        h += '<td style="white-space:nowrap">';
        if (isPending) {
            h += '<button class="pk-act-btn primary" title="This player joined via self-signup / walk-in QR. Approve to add them to the roster so they can buy in." aria-label="Approve ' + nm + '" data-act="approvePlayer" data-a1="' + p.id + '">Approve</button>';
            h += '<button class="pk-act-btn danger" title="Reject this join request and remove them from the list." aria-label="Deny ' + nm + '" data-act="denyPlayer" data-a1="' + p.id + '">Deny</button>';
        } else {
            if (!isNo) {
                if (isTourney()) {
                    if (!isElim && parseInt(p.bought_in) && !isWinner) {
                        h += '<button class="pk-act-btn primary" aria-label="Eliminate ' + nm + '" data-act="eliminatePlayer" data-a1="' + p.id + '">Eliminate</button>';
                    }
                    if (isElim) {
                        h += '<button class="pk-act-btn" aria-label="Undo the elimination of ' + nm + '" data-act="uneliminate" data-a1="' + p.id + '">Undo</button>';
                    }
                } else {
                    // Cash games: cashing out lives in the Cash Out column. Undo lives in
                    // that dialog. Keep Undo Elim for any player eliminated before that rule.
                    if (isElim) {
                        h += '<button class="pk-act-btn" aria-label="Undo the elimination of ' + nm + '" data-act="uneliminate" data-a1="' + p.id + '">Undo Elim</button>';
                    }
                }
            }
            h += '<button class="pk-act-btn" aria-label="Notes for ' + nm + '" data-act="openNotes" data-a1="' + p.id + '">Notes</button>';
            h += '<button class="pk-act-btn danger" aria-label="Remove ' + nm + '" data-act="removePlayerConfirm" data-a1="' + p.id + '">Remove</button>';
        }
        h += '</td>';
        h += '</tr>';
    }
    if (filtered.length === 0) {
        h += '<tr><td colspan="' + listColspan() + '" style="text-align:center;padding:2rem;color:#64748b">No players</td></tr>';
    }
    var dl = declinedLine();
    if (dl) h += '<tr class="pk-declined-row"><td colspan="' + listColspan() + '">' + dl + '</td></tr>';
    return h;
}

function renderMobileCards() {
    var h = '';
    var filtered = sortPlayers(PLAYERS.filter(passesFilter));
    for (var i = 0; i < filtered.length; i++) {
        var p = filtered[i];
        var nm = escHtml(p.display_name);   // every control in the row names the player
        var isElim = parseInt(p.eliminated);
        var hasCashedOut = isCash() && p.cash_out !== null && p.cash_out !== undefined;
        var isNo = p.rsvp === 'no';
        var isWinner = !isElim && isTourney() && parseInt(p.finish_position) === 1;
        var cardClass = isElim ? 'elim' : (isWinner ? 'winner' : (hasCashedOut ? 'cashed-out' : (isNo ? 'rsvp-no' : '')));

        // Status text and color
        var isPending = (p.approval_status === 'pending');
        var statusText = '\u2014', statusColor = '#64748b', statusBg = '#f1f5f9';
        if (isPending) {
            statusText = 'Pending'; statusColor = '#b45309'; statusBg = '#fefce8';
        } else if (isTourney()) {
            if (isElim) { var moAmt = parseInt(p.payout) || payoutForPlace(p.finish_position); statusText = ordinalLabel(p.finish_position) + (moAmt > 0 ? ' · ' + formatMoney(moAmt) : '') + rewardText(p); statusColor = '#ef4444'; statusBg = '#fef2f2'; }
            else if (isWinner) { var woAmt = parseInt(p.payout) || payoutForPlace(1); statusText = '🏆 1st' + (woAmt > 0 ? ' · ' + formatMoney(woAmt) : '') + rewardText(p); statusColor = '#a16207'; statusBg = '#fffbeb'; }
            else if (parseInt(p.bought_in)) { statusText = 'Playing' + rewardText(p); statusColor = '#15803d'; statusBg = '#f0fdf4'; }
        } else {
            if (hasCashedOut) { var mBusted = parseInt(p.cash_out) === 0; statusText = mBusted ? 'Busted' : 'Cashed Out'; statusColor = mBusted ? '#dc2626' : '#64748b'; statusBg = mBusted ? '#fef2f2' : '#f1f5f9'; }
            else if (parseInt(p.bought_in)) { statusText = 'Playing'; statusColor = '#15803d'; statusBg = '#f0fdf4'; }
        }

        h += '<div class="pk-mobile-card ' + cardClass + '" role="group" aria-label="' + nm + '" data-pid="' + p.id + '">';
        h += '<div class="pk-mobile-summary" data-act="toggleMobileExpand" data-a1="' + p.id + '">';
        var seatInfo = p.seat_number ? 'T' + (p.table_number || '?') + ' #' + p.seat_number : '';
        h += '<span class="pk-mobile-name">' + escHtml(p.display_name) + (seatInfo ? ' <span style="color:#64748b;font-size:.72rem;font-weight:600">' + seatInfo + '</span>' : '') + '</span>';
        if (isPending) {
            // Approve/deny buttons directly on the summary row instead of a status badge
            h += '<span data-stop="1" style="display:flex;align-items:center;gap:.35rem;margin-left:auto;flex-shrink:0">';
            h += '<button title="This player joined via self-signup / walk-in QR. Approve to add them to the roster so they can buy in." aria-label="Approve ' + nm + '" data-act="approvePlayer" data-a1="' + p.id + '" style="font-size:.72rem;padding:.25rem .6rem;border-radius:5px;border:0;background:#15803d;color:#fff;font-weight:700;cursor:pointer">Approve</button>';
            h += '<button title="Reject this join request and remove them from the list." aria-label="Deny ' + nm + '" data-act="denyPlayer" data-a1="' + p.id + '" style="font-size:.72rem;padding:.25rem .6rem;border-radius:5px;border:0;background:#dc2626;color:#fff;font-weight:700;cursor:pointer">Deny</button>';
            h += '</span>';
        } else {
            // Buy-in checkbox on the summary row (not inside expand)
            if (!isNo && isTourney()) {
                h += '<span data-stop="1" style="display:flex;align-items:center;gap:.6rem;margin-left:auto;margin-right:.5rem;flex-shrink:0">';
                h += '<label title="Record this player\'s buy-in. Checks them in and assigns a seat automatically — no separate check-in step needed." style="display:flex;align-items:center;gap:.2rem;font-size:.65rem;color:#64748b;font-weight:700;cursor:pointer;padding:.25rem 0;-webkit-tap-highlight-color:transparent">'
                   + '<input type="checkbox" class="pk-check" aria-label="Buy in for ' + nm + '" ' + (parseInt(p.bought_in)?'checked':'') + ' data-act-change="toggleBuyin" data-change-a1="' + p.id + '" style="accent-color:#7c3aed"> Buy In</label>';
                h += '</span>';
            }
            h += '<span class="pk-mobile-status" style="color:' + statusColor + ';background:' + statusBg + '">' + statusText + '</span>';
        }
        // The chevron is the keyboard's (and the eye's) way into the card; the
        // whole row still opens on a tap. data-stop keeps the row handler from
        // firing a second time underneath it.
        h += '<span data-stop="1" style="display:inline-flex;flex-shrink:0"><button type="button" class="pk-mobile-more" aria-expanded="false" aria-controls="mexp_' + p.id + '" aria-label="More for ' + nm + '" data-act="toggleMobileExpand" data-a1="' + p.id + '">&#9662;</button></span>';
        h += '</div>';

        // Expandable panel
        h += '<div class="pk-mobile-expand" id="mexp_' + p.id + '">';
        if (isPending) {
            h += '<div class="pk-mobile-row" style="justify-content:center;gap:.5rem;padding:.5rem 0">';
            h += '<button class="pk-act-btn" style="background:#15803d;color:#fff;font-weight:600;padding:.4rem 1rem" aria-label="Approve ' + nm + '" data-act="approvePlayer" data-a1="' + p.id + '">Approve</button>';
            h += '<button class="pk-act-btn danger" style="padding:.4rem 1rem" aria-label="Deny ' + nm + '" data-act="denyPlayer" data-a1="' + p.id + '">Deny</button>';
            h += '</div>';
        } else if (!isNo) {
            if (isTourney()) {
                if (parseInt(SESSION.jackpot_amount) > 0 && parseInt(SESSION.jackpot_optional)) {
                    h += '<div class="pk-mobile-row">';
                    h += '<label for="mjp_' + p.id + '">💎 Jackpot entry (' + formatMoney(parseInt(SESSION.jackpot_amount)) + ')</label>'
                       + '<input type="checkbox" class="pk-check" id="mjp_' + p.id + '" style="accent-color:#7c3aed" ' + (parseInt(p.jackpot_in) ? 'checked' : '') + ' data-act-change="toggleJackpot" data-change-a1="' + p.id + '">';
                    h += '</div>';
                }
                if (parseInt(SESSION.bounty_amount) > 0 && parseInt(SESSION.bounty_optional)) {
                    h += '<div class="pk-mobile-row">';
                    h += '<label for="mbty_' + p.id + '">🎯 Bounty side pot (' + formatMoney(parseInt(SESSION.bounty_amount)) + ')</label>'
                       + '<input type="checkbox" class="pk-check" id="mbty_' + p.id + '" style="accent-color:#0e7490" ' + (parseInt(p.bounty_in) ? 'checked' : '') + ' data-act-change="toggleBounty" data-change-a1="' + p.id + '">';
                    h += '</div>';
                }
                if (parseInt(SESSION.rebuy_allowed)) {
                    h += '<div class="pk-mobile-row">';
                    var mInc = parseInt(SESSION.rebuys_included) || 0;
                    h += '<label>Rebuys' + (mInc ? ' <span style="font-weight:400;color:#64748b">(' + mInc + ' free)</span>' : '') + (rebuysClosed() ? ' <span class="pk-rebuy-closed-tag">closed after level ' + parseInt(SESSION.rebuy_cutoff_level) + '</span>' : '') + '</label><div class="pk-counter"><button aria-label="Remove a rebuy for ' + nm + '" data-act="updateRebuys" data-a1="' + p.id + '" data-a2="-1">-</button><span aria-label="Rebuys">' + p.rebuys + '</span><button aria-label="Add a rebuy for ' + nm + '"' + rebuyPlusAttrs() + ' data-act="updateRebuys" data-a1="' + p.id + '" data-a2="1">+</button></div>';
                    h += '</div>';
                }
                if (parseInt(SESSION.addon_allowed)) {
                    var mAoCount = parseInt(p.addons) || 0;
                    h += '<div class="pk-mobile-row">';
                    var mAoInc = parseInt(SESSION.addons_included) || 0;
                    var mAoNote = parseInt(SESSION.addons_share_rebuys) === 1 ? (parseInt(SESSION.rebuys_included) ? 'free unless rebuy used' : '') : (mAoInc ? mAoInc + ' free' : '');
                    h += '<label>Add-ons' + (mAoNote ? ' <span style="font-weight:400;color:#64748b">(' + mAoNote + ')</span>' : '') + '</label><div style="display:flex;align-items:center;gap:.3rem">'
                       + '<button class="pk-addon-btn" aria-label="Add an add-on for ' + nm + '" data-act="addAddon" data-a1="' + p.id + '" style="font-size:.85rem;padding:.35rem .7rem;border-radius:4px;border:1px solid #c4b5fd;background:#f5f3ff;color:#6d28d9;cursor:pointer;font-weight:600">+ Add-on</button>'
                       + (mAoCount > 0 ? '<button type="button" class="pk-addon-count" aria-label="Remove the last add-on for ' + nm + ' (' + mAoCount + ' taken)" title="Tap to remove last" data-act="removeAddon" data-a1="' + p.id + '" style="min-width:1.5rem;height:1.5rem;padding:0 .45rem;border-radius:12px;font-size:.8rem">' + mAoCount + '</button>' : '')
                       + '</div>';
                    h += '</div>';
                }
            } else {
                var cashIn = parseInt(p.cash_in) || 0;
                h += '<div class="pk-mobile-row">';
                h += '<label for="mcash_' + p.id + '">Cash In</label><div class="pk-counter"><input type="number" inputmode="decimal" step="0.01" min="0" class="pk-cash-input" id="mcash_' + p.id + '" value="' + (cashIn/100) + '" data-act-change="setCashIn" data-change-a1="' + p.id + '" data-change-a2="@value" style="border:none;min-width:50px"><button aria-label="Add money for ' + nm + '" data-act="adjustMoney" data-a1="' + p.id + '" data-a2="1">+</button>' + ledgerBtn(p.id, nm) + '</div>';
                h += '</div>';
                if (parseInt(p.bought_in)) {
                    var coVal = hasCashedOut ? (parseInt(p.cash_out)/100) : '';
                    h += '<div class="pk-mobile-row">';
                    h += '<label for="mco_' + p.id + '">Cash Out</label><div class="pk-counter"><input type="number" inputmode="decimal" step="0.01" min="0" class="pk-co-input" id="mco_' + p.id + '" value="' + coVal + '" placeholder="0.00" data-act-keydown="cashOutOnEnter" data-keydown-a1="@event" data-keydown-a2="' + p.id + '" style="border:none;min-width:50px"><button title="Record cash-out" aria-label="Record cash-out for ' + nm + '" style="color:#15803d;font-weight:700" data-act="commitCashOut" data-a1="' + p.id + '" data-a2="@prevValue">✓</button>' + (hasCashedOut ? '' : '<button class="pk-bust-btn" title="Busted: out with $0. Frees their seat. Add cash to bring them back in." aria-label="' + nm + ' busted" data-act="bustOut" data-a1="' + p.id + '">&#128165;</button>') + '</div>';
                    h += '</div>';
                }
                if (hasCashedOut) {
                    var prof = parseInt(p.cash_out) - cashIn;
                    h += '<div class="pk-mobile-row"><label>Profit</label><span class="' + (prof>0?'pk-profit-pos':prof<0?'pk-profit-neg':'pk-profit-zero') + '">' + formatProfit(prof) + '</span></div>';
                }
            }

            if (parseInt(SESSION.num_tables) > 1) {
                h += '<div class="pk-mobile-row">';
                h += '<label for="mtbl_' + p.id + '">Table</label><input type="number" class="pk-tbl-input" id="mtbl_' + p.id + '" value="' + (p.table_number||'') + '" min="1" max="' + SESSION.num_tables + '" data-act-change="setTable" data-change-a1="' + p.id + '" data-change-a2="@value" style="width:50px">';
                h += '</div>';
            }

            // RSVP (tournaments only \u2014 not used in the cash-game flow)
            if (isTourney()) {
            h += '<div class="pk-mobile-row">';
            h += '<label for="mrsvp_' + p.id + '">RSVP</label><select id="mrsvp_' + p.id + '" data-act-change="updateRsvp" data-change-a1="' + p.id + '" data-change-a2="@value" style="font-size:.8rem;padding:.25rem .4rem;border-radius:4px;border:1px solid #e2e8f0">';
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
                if (!isElim && parseInt(p.bought_in) && !isWinner) h += '<button class="primary" aria-label="Eliminate ' + nm + '" data-act="eliminatePlayer" data-a1="' + p.id + '">Eliminate</button>';
                if (isElim) h += '<button aria-label="Undo the elimination of ' + nm + '" data-act="uneliminate" data-a1="' + p.id + '">Undo Elim</button>';
            } else {
                // Cash games: cashing out is inline in the Cash Out row above.
                // Keep Undo Elim for any player eliminated before that rule.
                if (isElim) h += '<button aria-label="Undo the elimination of ' + nm + '" data-act="uneliminate" data-a1="' + p.id + '">Undo Elim</button>';
            }
        }
        h += '<button aria-label="Notes for ' + nm + '" data-act="openNotes" data-a1="' + p.id + '">Notes</button>';
        h += '<button class="danger" aria-label="Remove ' + nm + '" data-act="removePlayerConfirm" data-a1="' + p.id + '">Remove</button>';
        h += '</div>';

        h += '</div>'; // pk-mobile-expand
        h += '</div>'; // pk-mobile-card
    }
    if (filtered.length === 0) {
        h += '<div style="text-align:center;padding:2rem;color:#64748b">No players</div>';
    }
    var dl = declinedLine();
    if (dl) h += '<div class="pk-declined-row">' + dl + '</div>';
    return h;
}

// The card's chevron mirrors its panel: aria-expanded for the screen reader,
// \u25BE / \u25B4 for everyone else.
function syncMobileMore(pid) {
    var el = document.getElementById('mexp_' + pid);
    var btn = document.querySelector('.pk-mobile-more[aria-controls="mexp_' + pid + '"]');
    if (!el || !btn) return;
    var open = el.classList.contains('open');
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    btn.textContent = open ? '\u25B4' : '\u25BE';
}

function toggleMobileExpand(pid) {
    var el = document.getElementById('mexp_' + pid);
    if (el) { el.classList.toggle('open'); syncMobileMore(pid); }
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
        h += '<button class="pk-cashbox-link" data-act="openCashBox">&#129534; Reconcile cash box</button>';
    } else {
        h += '<h3>Prize Pool</h3>';
        h += '<div class="pk-pool-row"><span>Buy-ins (' + POOL.total_buyins + ' &times; ' + formatMoney(parseInt(SESSION.buyin_amount)) + ')</span><span>' + formatMoney(POOL.buyin_total) + '</span></div>';
        // Rebuy/add-on lines only when the feature is enabled — or when entries
        // were already recorded, so history never hides after a rule change.
        if (parseInt(SESSION.rebuy_allowed) || parseInt(POOL.total_rebuys) > 0) {
            // Rebuys that came with the buy-in were paid for at the door: the
            // line counts only the ones bought beyond them, and says so.
            var freeRb = parseInt(POOL.free_rebuys) || 0;
            var paidRb = (POOL.paid_rebuys !== undefined) ? parseInt(POOL.paid_rebuys) : parseInt(POOL.total_rebuys);
            h += '<div class="pk-pool-row"><span>Rebuys (' + paidRb + ' &times; ' + formatMoney(parseInt(SESSION.rebuy_amount)) + (freeRb ? ', ' + freeRb + ' included' : '') + ')</span><span>' + formatMoney(POOL.rebuy_total) + '</span></div>';
        }
        if (parseInt(SESSION.addon_allowed) || parseInt(POOL.total_addons) > 0) {
            var freeAo = parseInt(POOL.free_addons) || 0;
            var paidAo = (POOL.paid_addons !== undefined) ? parseInt(POOL.paid_addons) : parseInt(POOL.total_addons);
            h += '<div class="pk-pool-row"><span>Add-ons (' + paidAo + ' &times; ' + formatMoney(parseInt(SESSION.addon_amount)) + (freeAo ? ', ' + freeAo + ' included' : '') + ')</span><span>' + formatMoney(POOL.addon_total) + '</span></div>';
        }
        // Net-pool adjustments (bounties / entry tickets) — only shown when in play.
        var bw = parseInt(POOL.bounty_withheld) || 0;
        var tw = parseInt(POOL.ticket_withheld) || 0;
        var ti = parseInt(POOL.ticket_in) || 0;
        if (bw > 0) h += '<div class="pk-pool-row"><span>&minus; Bounties (' + POOL.total_buyins + ' &times; ' + formatMoney(parseInt(SESSION.bounty_amount) || 0) + ')</span><span style="color:#0e7490">&minus;' + formatMoney(bw) + '</span></div>';
        var jw = parseInt(POOL.jackpot_withheld) || 0;
        if (jw > 0) h += '<div class="pk-pool-row"><span>&minus; 💎 Jackpot (' + POOL.total_buyins + ' &times; ' + formatMoney(parseInt(SESSION.jackpot_amount) || 0) + ')</span><span style="color:#7c3aed">&minus;' + formatMoney(jw) + '</span></div>';
        if (tw > 0) h += '<div class="pk-pool-row"><span>&minus; Ticket prizes</span><span style="color:#b45309">&minus;' + formatMoney(tw) + '</span></div>';
        if (ti > 0) h += '<div class="pk-pool-row"><span>+ Ticket seat surplus</span><span style="color:#15803d">+' + formatMoney(ti) + '</span></div>';
        h += '<div class="pk-pool-row total"><span>' + ((bw || jw || tw || ti) ? 'Prize Pool (net)' : 'Total') + '</span><span>' + formatMoney(POOL.pool_total) + '</span></div>';
        // Optional-mode side money: collected on top of the buy-in, shown for
        // the cash count but never part of the prize pool.
        if (parseInt(SESSION.jackpot_amount) > 0 && parseInt(SESSION.jackpot_optional)) {
            var je = parseInt(POOL.jackpot_entries) || 0;
            h += '<div class="pk-pool-row"><span style="color:#7c3aed">💎 Jackpot entries (' + je + ' &times; ' + formatMoney(parseInt(SESSION.jackpot_amount)) + ', side money)</span><span style="color:#7c3aed">' + formatMoney(parseInt(POOL.jackpot_collected) || 0) + '</span></div>';
        }
        if (parseInt(SESSION.bounty_amount) > 0 && parseInt(SESSION.bounty_optional)) {
            var be = parseInt(POOL.bounty_entries) || 0;
            h += '<div class="pk-pool-row"><span style="color:#0e7490">🎯 Bounty pool (' + be + ' &times; ' + formatMoney(parseInt(SESSION.bounty_amount)) + ', side money)</span><span style="color:#0e7490">' + formatMoney(parseInt(POOL.bounty_collected) || 0) + '</span></div>';
        }
        // Unclaimed bounty tracker: KOs recorded without an eliminator leave
        // bounty cash on the table — surface the shortfall.
        if (bw > 0) {
            var claimed = 0;
            (PLAYERS || []).forEach(function(p) { claimed += parseInt(p.bounty_cash) || 0; });
            var unclaimed = bw - claimed;
            if (unclaimed > 0) h += '<div class="pk-pool-row"><span style="color:#b45309">Unclaimed bounties</span><span style="color:#b45309">' + formatMoney(unclaimed) + '</span></div>';
        }
    }
    return h;
}

// The compact pool/payout bar shown below 1024px, where the sidebar is hidden.
// Extracted so refreshUI() can repaint it: it was rendered once and then went
// stale, so on a phone the payout figures stopped matching the pool header
// after any buy-in until something forced a full dashboard re-render.
function renderInlineSummary() {
    var h = '';
    if (isCash()) {
        h += '<span>In Play: <b>' + formatMoney(POOL.total_cash_in) + '</b></span>';
        h += '<span>On Table: <b>' + formatMoney(POOL.total_cash_in - POOL.total_cash_out) + '</b></span>';
    } else {
        h += '<span>Pool: <b style="color:#15803d">' + formatMoney(POOL.pool_total) + '</b></span>';
        for (var pi = 0; pi < PAYOUTS.length && pi < 3; pi++) {
            var pct = parseFloat(PAYOUTS[pi].percentage);
            var amt = Math.round(POOL.pool_total * pct / 100);
            var pl = PAYOUTS[pi].place == 1 ? '1st' : PAYOUTS[pi].place == 2 ? '2nd' : PAYOUTS[pi].place == 3 ? '3rd' : PAYOUTS[pi].place + 'th';
            h += '<span>' + pl + ': <b>' + formatMoney(amt) + '</b></span>';
        }
    }
    return h;
}

// "This game isn't set up yet" prompt. A button in a toolbar can only shout so
// loud; a host who has never opened Setup gets told directly, in the content
// area, and it retires for good once they have been.
//
// The gate is the session's setup_saved flag, NOT a look at the numbers. A $0
// buy-in is a legitimate freeroll and an empty payout list is legitimate for a
// points-only game, so value-sniffing keeps nagging games that are configured
// exactly as intended. Server-side, update_config and load_payout_structure
// both set the flag, so saving Setup or applying a preset clears this.
function renderSetupCta() {
    if (!SESSION || SESSION.status === 'finished') return '';
    if (parseInt(SESSION.setup_saved)) return '';
    // Don't nag while they're already in the editor doing it.
    if (SETTINGS_OPEN || VIEW_MODE === 'settings') return '';
    var h = '<div class="pk-setup-cta">';
    h += '<div class="pk-setup-cta-txt"><b>&#9888; This game isn\'t set up yet</b>';
    h += 'Set the ' + (isTourney() ? 'buy-in, chips, rebuys, payouts and rewards' : 'buy-in and table rules')
       + ' before players start buying in, so the money and prizes add up.';
    if (isTourney() && !(PAYOUTS || []).length) h += ' No payout structure is set yet.';
    h += '</div>';
    h += '<button data-act="setViewMode" data-a1="settings">Set up game</button>';
    h += '</div>';
    return h;
}

// Payout warnings, shared by the sidebar card and the Payouts view so the two
// can't drift. Worth noting the sidebar is display:none below 1024px, so until
// the Payouts view existed these were invisible on phones and tablets.
function payoutWarningsHtml() {
    var h = '';
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
        h += '<div style="background:#fffbeb;border:1px solid #fde68a;border-radius:6px;padding:.45rem .6rem;margin-bottom:.6rem;font-size:.78rem;color:#92400e;font-weight:600">&#9888; Ticket prizes need a target event — <a href="#" data-act="openPayoutsSetup" data-a1="@event" style="color:#92400e">set one in Setup</a>.</div>';
    }
    return h;
}

function renderPayoutCard() {
    var h = '<h3>Payouts</h3>';
    h += payoutWarningsHtml();
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
        h += '<div class="pk-payout-row"><span class="pk-payout-place">' + placeLabel + (pct > 0 ? ' (' + pct + '%)' : '') + '</span><span>' + (pct > 0 ? '<span style="font-weight:600;color:#15803d">' + formatMoney(amt) + '</span>' : '') + extras + '</span></div>';
    }
    // Bounty summary line when in play.
    if (isTourney() && (parseInt(SESSION.bounty_amount) > 0 || parseInt(SESSION.bounty_points) > 0)) {
        var bParts = [];
        if (parseInt(SESSION.bounty_amount) > 0) bParts.push(formatMoney(parseInt(SESSION.bounty_amount)) + ' cash');
        if (parseInt(SESSION.bounty_points) > 0) bParts.push(parseInt(SESSION.bounty_points) + ' pts');
        h += '<div class="pk-payout-row"><span class="pk-payout-place">🎯 Per knockout</span><span style="color:#0e7490;font-weight:600;font-size:.8rem">' + bParts.join(' + ') + '</span></div>';
    }
    h += '<div style="margin-top:.5rem;text-align:center"><button class="pk-act-btn" data-act="openSettings" data-a1="payouts" style="font-size:.8rem">Edit in Setup</button></div>';
    return h;
}

// ─── Payouts view ──────────────────────────────────────
// The full-width read-only counterpart to the sidebar card: the prize ladder
// with every reward column, the pool breakdown, bounty/jackpot state, and the
// final results once the game is over. Editing still lives in Settings.
function renderPayoutsView() {
    if (isCash()) {
        return '<div class="pk-card"><h3>Payouts</h3><div style="color:#64748b;font-size:.85rem">'
             + 'Cash games have no payout structure — money is settled per player in the List view.</div></div>';
    }
    var h = '<div class="pk-pv-sub">Where the money goes. Live from the current pool — figures move as buy-ins, rebuys and add-ons land.</div>';
    h += payoutWarningsHtml();

    // Single column. The pool breakdown deliberately is NOT repeated here — the
    // sidebar's Prize Pool card stays put in the same spot it occupies on every
    // other view, rather than moving into the content area for this one screen.
    h += '<div class="pk-card"><h3>Prize Ladder</h3>';
    if (!PAYOUTS || !PAYOUTS.length) {
        h += '<div style="color:#64748b;font-size:.85rem">No payout places set yet. Add them in Setup &rsaquo; Payouts &amp; Rewards.</div>';
    } else {
        var anyPts    = PAYOUTS.some(function(p) { return parseInt(p.points) > 0; });
        var anyTicket = PAYOUTS.some(function(p) { return parseInt(p.ticket_cents) > 0; });
        var anyLabel  = PAYOUTS.some(function(p) { return !!p.prize_label; });
        var finished  = SESSION.status === 'finished';
        h += '<table class="pk-table pk-pv-table"><thead><tr>'
           + '<th scope="col">Place</th><th scope="col">%</th><th scope="col">Prize</th>'
           + (anyPts ? '<th scope="col">Pts</th>' : '')
           + (anyTicket ? '<th scope="col">&#127903; Ticket</th>' : '')
           + (anyLabel ? '<th scope="col">Prize</th>' : '')
           + '<th scope="col">Winner</th></tr></thead><tbody>';
        var sumPct = 0, sumAmt = 0;
        for (var i = 0; i < PAYOUTS.length; i++) {
            var pay = PAYOUTS[i];
            var pct = parseFloat(pay.percentage) || 0;
            var amt = Math.round(POOL.pool_total * pct / 100);
            sumPct += pct; sumAmt += amt;
            // Who finished in this place, and what they were actually credited.
            var win = (PLAYERS || []).filter(function(p) { return parseInt(p.finish_position) === parseInt(pay.place); })[0];
            var winHtml = '&mdash;';
            if (win) {
                var stored = parseInt(win.payout) || 0;
                winHtml = '<b>' + escHtml(win.display_name) + '</b>';
                // Stored winnings are frozen at finish time; the computed prize
                // tracks the live pool. If they disagree the game was edited
                // after finishing — show both rather than quietly picking one.
                if (finished && stored !== amt) {
                    winHtml += ' <span style="color:#b45309;font-weight:700" title="Recorded winnings differ from the current pool — the game was edited after it finished. Reopen and re-finish to re-sync.">&#9888; ' + formatMoney(stored) + '</span>';
                } else if (stored > 0) {
                    winHtml += ' <span style="color:#15803d;font-weight:600">' + formatMoney(stored) + '</span>';
                }
            }
            h += '<tr><td><b>' + ordinalLabel(pay.place) + '</b></td>'
               + '<td>' + (pct > 0 ? pct + '%' : '&mdash;') + '</td>'
               + '<td style="font-weight:600;color:#15803d">' + (pct > 0 ? formatMoney(amt) : '&mdash;') + '</td>'
               + (anyPts ? '<td>' + (parseInt(pay.points) > 0 ? '<span style="color:#7c3aed;font-weight:700">+' + parseInt(pay.points) + '</span>' : '&mdash;') + '</td>' : '')
               + (anyTicket ? '<td>' + (parseInt(pay.ticket_cents) > 0 ? '<span style="color:#b45309;font-weight:700">' + formatMoney(parseInt(pay.ticket_cents)) + '</span>' : '&mdash;') + '</td>' : '')
               + (anyLabel ? '<td>' + (pay.prize_label ? escHtml(pay.prize_label) : '&mdash;') + '</td>' : '')
               + '<td>' + winHtml + '</td></tr>';
        }
        var span = 3 + (anyPts?1:0) + (anyTicket?1:0) + (anyLabel?1:0);
        h += '<tr style="border-top:2px solid #e2e8f0;font-weight:700">'
           + '<td>Total</td><td>' + (Math.round(sumPct * 10) / 10) + '%</td><td>' + formatMoney(sumAmt) + '</td>'
           + '<td colspan="' + (span - 2) + '"></td></tr>';
        h += '</tbody></table>';
        if (sumPct < 99.999) {
            h += '<div style="margin-top:.5rem;font-size:.78rem;color:#b45309;font-weight:600">&#9888; Unallocated: '
               + (Math.round((100 - sumPct) * 10) / 10) + '% &middot; ' + formatMoney(POOL.pool_total - sumAmt) + ' of the pool is not assigned to any place.</div>';
        } else if (sumPct > 100.001) {
            h += '<div style="margin-top:.5rem;font-size:.78rem;color:#991b1b;font-weight:700">&#9888; Payouts total ' + (Math.round(sumPct * 10) / 10) + '% — more than the pool holds.</div>';
        }
    }
    h += '</div>';   // /prize ladder card

    // ── Final results (finished games only) ──
    if (SESSION.status === 'finished') {
        var placed = (PLAYERS || []).filter(function(p) { return parseInt(p.finish_position) > 0; })
                                    .sort(function(a, b) { return parseInt(a.finish_position) - parseInt(b.finish_position); });
        if (placed.length) {
            h += '<div class="pk-card"><h3>Final Results</h3>';
            placed.forEach(function(p) {
                var paid = parseInt(p.payout) || 0;
                var bc   = parseInt(p.bounty_cash) || 0;
                h += '<div class="pk-payout-row"><span class="pk-payout-place">' + ordinalLabel(p.finish_position) + ' ' + escHtml(p.display_name) + '</span>'
                   + '<span>' + (paid > 0 ? '<span style="font-weight:600;color:#15803d">' + formatMoney(paid) + '</span>' : '')
                   + (bc > 0 ? ' <span style="color:#0e7490;font-size:.75rem;font-weight:700">+' + formatMoney(bc) + ' KO</span>' : '')
                   + '<span style="color:#64748b;font-size:.75rem">' + rewardText(p) + '</span></span></div>';
            });
            h += '</div>';
        }
    }
    h += bountyJackpotHtml();
    h += '<div class="pk-pv-foot"><button class="pk-act-btn" data-act="openSettings" data-a1="payouts">Edit payouts &amp; rewards in Setup</button></div>';
    return h;
}

// Bounty + jackpot state, rendered only when either is configured.
function bountyJackpotHtml() {
    var bAmt = parseInt(SESSION.bounty_amount) || 0;
    var bPts = parseInt(SESSION.bounty_points) || 0;
    var jAmt = parseInt(SESSION.jackpot_amount) || 0;
    if (!bAmt && !bPts && !jAmt) return '';
    var h = '<div class="pk-card"><h3>Bounties &amp; Jackpot</h3>';
    if (bAmt > 0 || bPts > 0) {
        var per = [];
        if (bAmt > 0) per.push(formatMoney(bAmt) + ' cash');
        if (bPts > 0) per.push(bPts + ' pts');
        h += '<div class="pk-pool-row"><span>&#127919; Per knockout</span><span style="font-weight:600;color:#0e7490">' + per.join(' + ') + '</span></div>';
        h += '<div class="pk-pool-row"><span style="color:#64748b;font-size:.78rem">' + (parseInt(SESSION.bounty_optional) ? 'Optional side pot' : 'Baked into the buy-in') + '</span><span></span></div>';
        if (bAmt > 0) {
            var collected = parseInt(SESSION.bounty_optional) ? (parseInt(POOL.bounty_collected) || 0) : (parseInt(POOL.bounty_withheld) || 0);
            var claimed = 0;
            (PLAYERS || []).forEach(function(p) { claimed += parseInt(p.bounty_cash) || 0; });
            h += '<div class="pk-pool-row"><span>Collected</span><span>' + formatMoney(collected) + '</span></div>';
            h += '<div class="pk-pool-row"><span>Claimed</span><span>' + formatMoney(claimed) + '</span></div>';
            if (collected - claimed > 0) {
                h += '<div class="pk-pool-row"><span style="color:#b45309">Still on the table</span><span style="color:#b45309">' + formatMoney(collected - claimed) + '</span></div>';
            }
        }
        var koPlayers = (PLAYERS || []).filter(function(p) { return (parseInt(p.bounties_won) || 0) > 0; })
                                       .sort(function(a, b) { return (parseInt(b.bounties_won) || 0) - (parseInt(a.bounties_won) || 0); });
        if (koPlayers.length) {
            h += '<div style="margin-top:.4rem;border-top:1px solid #f1f5f9;padding-top:.4rem">';
            koPlayers.forEach(function(p) {
                h += '<div class="pk-pool-row"><span>' + escHtml(p.display_name) + ' <span style="color:#0e7490;font-weight:700">&#127919; &times;' + parseInt(p.bounties_won) + '</span></span>'
                   + '<span>' + ((parseInt(p.bounty_cash) || 0) > 0 ? formatMoney(parseInt(p.bounty_cash)) : '') + '</span></div>';
            });
            h += '</div>';
        }
    }
    if (jAmt > 0) {
        h += '<div style="margin-top:.5rem;border-top:1px solid #f1f5f9;padding-top:.5rem">';
        h += '<div class="pk-pool-row"><span>&#128142; Jackpot entry</span><span style="font-weight:600;color:#7c3aed">' + formatMoney(jAmt) + '</span></div>';
        h += '<div class="pk-pool-row"><span style="color:#64748b;font-size:.78rem">' + (parseInt(SESSION.jackpot_optional) ? 'Optional per player' : 'Baked into the buy-in') + '</span><span></span></div>';
        var jc = parseInt(SESSION.jackpot_optional) ? (parseInt(POOL.jackpot_collected) || 0) : (parseInt(POOL.jackpot_withheld) || 0);
        h += '<div class="pk-pool-row"><span>This game contributes</span><span>' + formatMoney(jc) + '</span></div>';
        if (JACKPOTS && JACKPOTS.league_id) {
            h += '<div class="pk-pool-row"><span>League fund</span><span style="font-weight:600;color:#7c3aed">' + formatMoney(parseInt(JACKPOTS.balance) || 0) + '</span></div>';
        }
        h += '</div>';
    }
    h += '</div>';
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

// Settings is a VIEW, not an overlay: it renders into the content area like
// List / Table / Log / Payouts, so the page keeps its header, stats row and
// toolbar. VIEW_MODE_PREV is where Close returns to.
var VIEW_MODE_PREV = 'list';

function openSettings(tab) {
    SETTINGS_TAB = tab || 'game';
    if (VIEW_MODE !== 'settings') VIEW_MODE_PREV = VIEW_MODE;
    SETTINGS_OPEN = true;
    SETTINGS_DIRTY = false;
    setViewMode('settings', true);   // force: SETTINGS_TAB may have changed
    // The preset list is populated for BOTH game types: the bar is rendered
    // (hidden) on a cash session so switching the dropdown to Tournament can
    // reveal a select that already has its options.
    // First open fetches the saved-structure list; later opens must still
    // re-render it into the freshly-built (empty) select.
    if (!PAYOUT_STRUCTURES.length) loadPayoutStructures();
    else renderPayoutStructureSelect();
    refreshPresetState();
    loadChipSet();
    renderChipRows();
    if (isTourney()) {
        populateTicketTargetSelect();
        updateBountyHint();
    }
}

async function closeSettings() {
    if (!SETTINGS_OPEN) return;
    if (SETTINGS_DIRTY) {
        var ok = await pkConfirm('Discard unsaved settings changes?', { okLabel: 'Discard', danger: true });
        if (!ok) return;   // stay put — the editor is still on screen
    }
    SETTINGS_OPEN = false;
    SETTINGS_DIRTY = false;
    setViewMode(VIEW_MODE_PREV === 'settings' ? 'list' : VIEW_MODE_PREV);
}
// Escape closes Setup only when nothing nearer is open: a dialog, a menu, the
// walk-in list or the embedded layout editor take it first (each marks the
// event defaultPrevented, and the DOM check covers the ones that run later).
document.addEventListener('keydown', function(e) {
    if (e.key !== 'Escape' || !SETTINGS_OPEN || e.defaultPrevented) return;
    if (e.target && e.target.closest && e.target.closest('#ckDisplayHome')) return;
    if (somethingElseOpen()) return;
    closeSettings();
});
function somethingElseOpen() {
    if (document.querySelector('.pk-dialog.open, .pk-modal-overlay.open, #presetMenu.open, .walkin-dropdown.open')) return true;
    var em = document.querySelector('.es-menu');
    return !!(em && getComputedStyle(em).display !== 'none');
}

// ─── Page modals: focus in, Tab kept inside, Escape closes, focus returned ───
// Every one of the console's own modals opens and closes through these two, so
// the keyboard story is one story. The pk-dialogs overlay (.pk-dialog) looks
// after itself and sits above them.
var MODAL_OPENER = {};
var MODAL_CLOSERS = { notesModal: 'closeNotes', jackpotModal: 'closeJackpotModal', saveStructModal: 'closeSaveStruct',
    cashAdjustModal: 'closeCashAdjust', ledgerModal: 'closeLedger', cashBoxModal: 'closeCashBox', helpModal: 'closeHelp',
    elimModal: 'closeElim', winnerModal: 'closeWinner', betaTimerModal: 'betaTimerLater', balanceModal: 'closeBalanceModal' };
function modalFocusables(m) {
    return Array.prototype.filter.call(m.querySelectorAll('a[href], button, input, select, textarea, [tabindex]'), function (el) {
        return !el.disabled && el.tabIndex >= 0 && el.offsetParent !== null;
    });
}
function openModal(id, focusSel) {
    var m = document.getElementById(id);
    if (!m) return;
    MODAL_OPENER[id] = document.activeElement;
    m.classList.add('open');
    var target = (focusSel && m.querySelector(focusSel)) || modalFocusables(m)[0];
    if (target) setTimeout(function () { try { target.focus(); if (target.tagName === 'INPUT' && target.select) target.select(); } catch (e) {} }, 30);
}
function closeModal(id) {
    var m = document.getElementById(id);
    if (m) m.classList.remove('open');
    restoreFocus(MODAL_OPENER[id]);
    MODAL_OPENER[id] = null;
}
// Focus goes back to the control that opened the modal; when the roster was
// re-rendered meanwhile that element is detached, so its successor is found
// by the dispatch attributes it carried.
function restoreFocus(el) {
    if (!el || el === document.body) return;
    var t = document.contains(el) ? el : findSuccessor(el);
    if (t && t.focus) { try { t.focus({ preventScroll: true }); } catch (e) {} }
}
function findSuccessor(el) {
    if (!el.getAttribute) return null;
    var act = el.getAttribute('data-act') || el.getAttribute('data-act-change');
    var a1 = el.getAttribute('data-a1') || el.getAttribute('data-change-a1');
    var a2 = el.getAttribute('data-a2') || el.getAttribute('data-change-a2');
    if (act) {
        // a2 too: a row's rebuy − and + share act and a1 and differ only there.
        var hit = document.querySelector('[data-act="' + act + '"]' + (a1 ? '[data-a1="' + a1 + '"]' : '') + (a2 ? '[data-a2="' + a2 + '"]' : ''))
               || document.querySelector('[data-act-change="' + act + '"]' + (a1 ? '[data-change-a1="' + a1 + '"]' : '') + (a2 ? '[data-change-a2="' + a2 + '"]' : ''));
        if (hit) return hit;
    }
    if (el.id) return document.getElementById(el.id);
    return null;
}
function topPageModal() {
    if (document.querySelector('.pk-dialog.open')) return null;   // pk-dialogs handles its own keys
    var opens = document.querySelectorAll('.pk-modal-overlay.open:not(.pk-dialog)');
    return opens.length ? opens[opens.length - 1] : null;
}
document.addEventListener('keydown', function (e) {
    var open = topPageModal();
    if (!open) return;
    if (e.key === 'Escape') {
        e.preventDefault();
        var fn = window[MODAL_CLOSERS[open.id]];
        if (typeof fn === 'function') fn(); else closeModal(open.id);
    } else if (e.key === 'Tab') {
        var f = modalFocusables(open);
        if (!f.length) { e.preventDefault(); return; }
        var at = f.indexOf(document.activeElement);
        if (e.shiftKey && at <= 0) { e.preventDefault(); f[f.length - 1].focus(); }
        else if (!e.shiftKey && (at === -1 || at === f.length - 1)) { e.preventDefault(); f[0].focus(); }
    }
});
// Leaving the page with unsaved Setup changes gets the browser's own prompt;
// the in-page exits (Close, another view, the back arrow) ask through pkConfirm.
window.addEventListener('beforeunload', function (e) {
    if (SETTINGS_OPEN && SETTINGS_DIRTY) { e.preventDefault(); e.returnValue = ''; }
});

function setSettingsTab(tab) {
    var prev = SETTINGS_TAB;
    SETTINGS_TAB = tab;
    document.querySelectorAll('.pk-sv-tab').forEach(function(t) {
        t.classList.toggle('active', t.getAttribute('data-tab') === tab);
    });
    document.querySelectorAll('.pk-sv-pane').forEach(function(p) {
        p.classList.toggle('active', p.getAttribute('data-pane') === tab);
    });
    positionSegThumb('settingsSeg', true);   // animate: the thumb is moving
    // Slide the newly-shown pane in from the side the thumb travelled, exactly
    // as the views do. Skipped when the tab did not actually change, so a
    // re-render or a redundant click doesn't replay the animation.
    if (prev !== tab) {
        slideViewIn(document.querySelector('.pk-sv-pane[data-pane="' + tab + '"]'),
                    segTravelDirection('settingsSeg', 'data-tab', prev, tab));
    }
    syncDisplayHome();
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
    // The pane is rebuilt from the SAVED session, so an unsaved game-type choice
    // would be thrown away — and with it the sections that choice reveals. That
    // is what made the preset bar vanish the moment a preset was loaded on a
    // game still saved as cash: load re-renders, the dropdown snapped back, and
    // the bar you had just used hid itself.
    var pendingType = (document.getElementById('cfg_game_type') || {}).value || '';
    var vc = document.getElementById('viewContent');
    if (vc) vc.innerHTML = renderSettingsView();
    var typeSel = document.getElementById('cfg_game_type');
    if (typeSel && pendingType && pendingType !== typeSel.value) {
        typeSel.value = pendingType;
        previewGameType(pendingType);
    }
    renderPayoutStructureSelect();
    // The panes were just rebuilt from HTML, so the chip editor is an empty
    // container again. Redrawn from CHIP_SET rather than reloaded from the
    // session, so an edit the host has not saved yet survives the refresh.
    renderChipRows();
    if (isTourney()) {
        populateTicketTargetSelect();
        updateBountyHint();
    }
    positionSegThumb('settingsSeg', false);   // the strip was just rebuilt
    mountBlindsPane();
    syncDisplayHome();
}

// Setup → Timer: which display the Timer button opens for this game.
function renderTimerPane() {
    var h = '<div class="pk-cfg-section" style="border-top:none;padding-top:0;margin-top:0">';
    h += '<div class="pk-cfg-title">Tournament timer</div>';
    h += '<label class="es-toggle" style="margin:.4rem 0"><input type="checkbox" id="ckUseBeta"' + (USE_BETA_TIMER ? ' checked' : '') + ' data-act-change="toggleBetaTimer"> Use the Tournament Timer (off: Classic)</label>';
    h += '<p class="es-note" style="margin:.2rem 0 .8rem">On, the Timer button (and any link to this game\'s timer) opens the Tournament Timer: designable layouts, multi-screen rotation, break screens. Off, it opens Tournament Timer Classic, the original clock. Switch any time.</p>';
    h += '<p class="es-note">Pick which layout this game\'s display shows, and build or tweak layouts, right below. ' +
         '<a href="/help-timer.php" target="_blank">Timer guide</a> covers layouts, casting and conditions.</p>';
    h += '</div>';

    return h;
}

// Chip set: denominations and their colours, drawn on the display as a legend
// so players can see what each colour is worth at colour-up. Its OWN tab next
// to Timer — it rode along at the bottom of the Timer pane, below the layout
// editor, where it was effectively unreachable. Values only; the layout
// decides WHERE the legend goes, never what is in it.
function renderChipsPane() {
    var h = '<div class="pk-cfg-section" style="border-top:none;padding-top:0;margin-top:0">';
    h += '<div class="pk-cfg-title">Chip set</div>';
    h += '<p class="es-note" style="margin:.2rem 0 .6rem">Drawn on the display as coloured discs with their values, wherever the layout puts its chip legend. Leave it empty and no legend is shown.</p>';
    h += '<div class="pk-chipset" id="cfgChipSet">';
    h += '<div class="pk-chip-rows" id="chipRows"></div>';
    h += '<div class="pk-chip-actions">';
    h += '<button type="button" class="es-mini" data-act="addChipRow">+ Chip</button>';
    h += '<button type="button" class="es-mini" data-act="fillStandardChips" title="25 / 100 / 500 / 1,000 / 5,000 in the usual colours">Standard set</button>';
    h += '<button type="button" class="es-mini" data-act="clearChipSet">Clear</button>';
    h += '</div></div>';
    h += '</div>';
    return h;
}

// The layout chooser + editor (#ckDisplayHome) is rendered once outside the
// settings panes (the preview iframe would reload on every pane re-render)
// and shown only while Setup → Timer is active.
function syncDisplayHome() {
    var home = document.getElementById('ckDisplayHome');
    if (!home) return;
    var show = SETTINGS_OPEN && VIEW_MODE === 'settings' && SETTINGS_TAB === 'timer';
    if (show === !home.hidden) return;
    home.hidden = !show;
    if (show) {
        // Freshly revealed: the strip and preview were laid out at zero size.
        slideViewIn(home, 1);
        window.dispatchEvent(new Event('resize'));
    }
}

function toggleBetaTimer() {
    var cb = document.getElementById('ckUseBeta');
    var on = cb && cb.checked ? 1 : 0;
    var body = new URLSearchParams();
    body.set('action', 'set_beta'); body.set('csrf_token', CSRF);
    body.set('event_id', EVENT_ID); body.set('on', on);
    fetch('/event_setup_dl.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: String(body) })
        .then(function (r) { return r.json(); })
        .then(function (j) {
            if (!j.ok) { if (cb) cb.checked = !on; (window.pkAlert || alert)(j.error || 'Could not switch'); return; }
            USE_BETA_TIMER = on;
            var a = document.getElementById('timerLink');
            if (a) a.href = (on ? '/timer.php' : '/timer.php') + '?event_id=' + EVENT_ID;
        })
        .catch(function () { if (cb) cb.checked = !on; (window.pkAlert || alert)('Network error'); });
}

// Setup → Blinds: the pane is a mounted component (event_blinds.js), shared
// with the standalone event_blinds.php page. Re-renders wipe the pane's DOM,
// so it remounts each time; unsaved grid edits survive on pkBlindsEditor._state.
function mountBlindsPane() {
    var box = document.getElementById('ckBlindsPane');
    if (!box || isCash() || !window.pkBlindsEditor) return;
    var m = function (d) {
        pkBlindsEditor.mount(box, {
            eventId: EVENT_ID, csrf: CSRF, reuseState: true, embedded: true,
            levels: d.levels || [], currentLevel: d.current_level || 0,
            isLocal: !!d.is_local, timerRunning: !!d.is_running,
            useBeta: !!d.use_beta,
            links: { display: '/event_display.php?event_id=' + EVENT_ID }
        });
    };
    // Already mounted once this page-load: remount from kept state, no refetch.
    if (pkBlindsEditor._state && pkBlindsEditor._state.eventId === EVENT_ID) { m({}); return; }
    var body = new URLSearchParams();
    body.set('action', 'get_blinds'); body.set('csrf_token', CSRF); body.set('event_id', EVENT_ID);
    fetch('/event_setup_dl.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: String(body) })
        .then(function (r) { return r.json(); })
        .then(function (j) { if (j && j.ok) m(j); })
        .catch(function () {});
}

function renderSettingsView() {
    initRewardsUI();
    var hasTickets = TICKETS.outgoing && TICKETS.outgoing.length > 0;
    if (SETTINGS_TAB === 'tickets' && !hasTickets) SETTINGS_TAB = 'game';
    if (isCash() && SETTINGS_TAB !== 'game') SETTINGS_TAB = 'game';

    var h = '<div class="pk-sv-inline">';
    // Editor header: title + dirty dot + Save/Close.
    h += '<div class="pk-sv-head">';
    h += '<span class="pk-sv-title">&#9881; Game Setup <span id="svDirty" style="display:' + (SETTINGS_DIRTY ? '' : 'none') + '" title="Unsaved changes">&#9679;</span><span id="svSaved" style="display:none">Saved &#10003;</span></span>';
    h += '<div style="display:flex;gap:.5rem">';
    h += '<button class="pk-sv-save" data-act="saveSettings">Save game</button>';
    h += '<button class="pk-sv-close" data-act="closeSettings">Close</button>';
    h += '</div></div>';

    // Tab strip — a segmented control with a sliding thumb, same as the toolbar.
    h += '<div class="pk-sv-tabs">';
    h += '<div class="pk-seg pk-sv-seg" id="settingsSeg" data-seg-role="tablist" aria-label="Setup sections">';
    h += '<span class="pk-seg-thumb"></span>';
    h += '<button class="pk-sv-tab' + (SETTINGS_TAB === 'game' ? ' active' : '') + '" id="svTab_game" aria-controls="svPane_game" data-tab="game" data-act="setSettingsTab" data-a1="game">Game</button>';
    h += '<button class="pk-sv-tab' + (SETTINGS_TAB === 'payouts' ? ' active' : '') + (isCash() ? ' disabled' : '') + '" id="svTab_payouts" aria-controls="svPane_payouts" data-tab="payouts" ' + (isCash() ? 'disabled title="Cash games have no payout structure"' : 'data-act="setSettingsTab" data-a1="payouts"') + '>Payouts &amp; Rewards</button>';
    // Rendered for BOTH game types and shown or hidden live by
    // previewGameType(). Building them only for a saved tournament is what made
    // the strip lag the dropdown: switching to Cash left them standing, and
    // switching to Tournament could not reveal them because they did not exist.
    // (Their panes were always rendered — only these buttons were gated.)
    var tourneyTabHidden = isCash() ? ' style="display:none"' : '';
    h += '<button class="pk-sv-tab' + (SETTINGS_TAB === 'blinds' ? ' active' : '') + '" id="svTab_blinds" aria-controls="svPane_blinds" data-tab="blinds" data-act="setSettingsTab" data-a1="blinds"' + tourneyTabHidden + '>Blinds</button>';
    h += '<button class="pk-sv-tab' + (SETTINGS_TAB === 'timer' ? ' active' : '') + '" id="svTab_timer" aria-controls="svPane_timer" data-tab="timer" data-act="setSettingsTab" data-a1="timer"' + tourneyTabHidden + '>Timer</button>';
    h += '<button class="pk-sv-tab' + (SETTINGS_TAB === 'chips' ? ' active' : '') + '" id="svTab_chips" aria-controls="svPane_chips" data-tab="chips" data-act="setSettingsTab" data-a1="chips"' + tourneyTabHidden + '>Chip set</button>';
    if (hasTickets && !isCash()) {
        h += '<button class="pk-sv-tab' + (SETTINGS_TAB === 'tickets' ? ' active' : '') + '" id="svTab_tickets" aria-controls="svPane_tickets" data-tab="tickets" data-act="setSettingsTab" data-a1="tickets">Tickets <span class="pk-sv-badge">' + TICKETS.outgoing.length + '</span></button>';
    }
    h += '</div>';
    h += '</div>';

    // Scrolling body: all panes mounted, active one visible. The preset bar
    // sits ABOVE the panes because a preset restores BOTH tabs.
    h += '<div class="pk-sv-body">';
    // Rendered for BOTH game types and hidden by previewGameType() when cash, so
    // switching the dropdown to Tournament reveals it immediately. It used to be
    // omitted from the markup entirely on a cash session, which meant presets
    // could not be reached until the game type had been saved and the editor
    // reopened — the same defect the Payouts tab had.
    {
        // One quiet line by default: the title, where this game's setup came
        // from, and a disclosure for the controls. The select and its buttons
        // used to sit open at the top of every tab, so the first thing a new
        // host met in Setup was preset machinery they had no use for yet, with
        // an amber Save preset beside the blue Save game. Once opened it stays
        // open on this browser (localStorage), so a host who lives by presets
        // is not clicking it every week.
        var presetOpen = presetBarOpen();
        h += '<div class="pk-cfg-section" id="cfgPresetSection" style="border-top:none;padding-top:0;margin-top:0' + (isCash() ? ';display:none' : '') + '">';
        h += '<div class="pk-preset-head"><div class="pk-cfg-title" style="display:flex;align-items:center;gap:.45rem">Game Preset'
           + '<button class="pk-help-btn" style="padding:.15rem .5rem;font-size:.7rem;min-width:24px;min-height:24px" title="What game presets do" aria-label="Game preset help" data-act="showPresetHelp">?</button></div>';
        // Provenance line: which preset this game was set up from, and whether
        // it still matches. Filled by refreshPresetState() from the server —
        // it must survive a reload, so it cannot live in a JS variable.
        h += '<div class="pk-preset-from" id="presetFrom"></div>';
        h += '<button type="button" class="pk-preset-toggle" id="presetToggle" data-act="togglePresetBar" aria-controls="presetBody" aria-expanded="' + (presetOpen ? 'true' : 'false') + '">' + (presetOpen ? 'Hide presets &#9652;' : 'Show presets &#9662;') + '</button>';
        h += '</div>';
        h += '<div id="presetBody"' + (presetOpen ? '' : ' hidden') + '>';
        h += '<div class="pk-preset-bar">';
        h += '<select id="payoutStructureSelect" aria-label="Game preset" data-act-change="onPayoutStructureChange" style="flex:0 1 300px;min-width:160px;padding:.3rem .5rem;border:1.5px solid var(--border,#e2e8f0);border-radius:4px;font-size:.85rem"></select>';
        h += '<button data-act="loadPayoutStructure" title="Apply the selected preset to this game: game setup, payout split, points, ticket prizes, prizes, bounty and jackpot entry, blind schedule and timer settings">Load</button>';
        h += '<button id="btnUpdPayoutStructure" data-act="updatePayoutStructure">Save preset</button>';
        h += '<button data-act="savePayoutStructureAs" title="Save everything in this editor as a NEW named preset">Save as…</button>';
        // Destructive / admin actions live behind the ⋯ menu: they applied to
        // whatever was selected in the dropdown, which reads as if they applied
        // to the game, and they crowded the two buttons that matter.
        h += '<div class="pk-preset-more" data-act-keydown="presetMenuKeydown" data-keydown-a1="@event"><button type="button" class="pk-preset-morebtn" data-act="togglePresetMenu" title="More preset actions" aria-label="More preset actions" aria-haspopup="menu" aria-expanded="false" aria-controls="presetMenu">&#8943;</button>';
        h += '<div class="pk-preset-menu" id="presetMenu" role="menu" aria-label="More preset actions">';
        h += '<div class="pk-preset-menu-hint" id="presetMenuHint" style="display:none"></div>';
        h += '<button type="button" role="menuitem" id="btnDefPayoutStructure" data-act="setDefaultPayoutStructure">Set as site default</button>';
        h += '<button type="button" role="menuitem" id="btnDelPayoutStructure" class="danger" data-act="deletePayoutStructure">Delete preset</button>';
        h += '</div></div>';
        h += '</div>';   // .pk-preset-bar
        h += '<div style="font-size:.75rem;color:#64748b;margin-top:.3rem">A preset stores the whole editor — game setup (buy-in, chips, rebuys, tables), payouts &amp; rewards, blind schedule and timer settings. (The satellite target event stays per-game.)</div>';
        h += '</div>';   // #presetBody
        h += '</div>';   // #cfgPresetSection
    }
    h += '<div class="pk-sv-pane' + (SETTINGS_TAB === 'game' ? ' active' : '') + '" id="svPane_game" role="tabpanel" aria-labelledby="svTab_game" data-pane="game">' + renderGamePane() + '</div>';
    h += '<div class="pk-sv-pane' + (SETTINGS_TAB === 'payouts' ? ' active' : '') + '" id="svPane_payouts" role="tabpanel" aria-labelledby="svTab_payouts" data-pane="payouts">' + renderPayoutsPane() + '</div>';
    // The blinds pane is a mounted component (event_blinds.js), not an HTML
    // string — mountBlindsPane() fills this container after every render.
    h += '<div class="pk-sv-pane' + (SETTINGS_TAB === 'blinds' ? ' active' : '') + '" id="svPane_blinds" role="tabpanel" aria-labelledby="svTab_blinds" data-pane="blinds"><div class="pk-blinds-hint">Reusing a schedule from an earlier game? Pick it from the <b>Saved blind schedules</b> list under Status, click <b>Load into table</b>, then <b>Save game</b>. The Game Preset above is a different thing: it carries a whole game setup.</div><div id="ckBlindsPane"><div class="pk-log-empty">Loading&hellip;</div></div></div>';
    h += '<div class="pk-sv-pane' + (SETTINGS_TAB === 'timer' ? ' active' : '') + '" id="svPane_timer" role="tabpanel" aria-labelledby="svTab_timer" data-pane="timer">' + renderTimerPane() + '</div>';
    h += '<div class="pk-sv-pane' + (SETTINGS_TAB === 'chips' ? ' active' : '') + '" id="svPane_chips" role="tabpanel" aria-labelledby="svTab_chips" data-pane="chips">' + renderChipsPane() + '</div>';
    h += '<div class="pk-sv-pane' + (SETTINGS_TAB === 'tickets' ? ' active' : '') + '" id="svPane_tickets" role="tabpanel"' + (hasTickets && !isCash() ? ' aria-labelledby="svTab_tickets"' : '') + ' data-pane="tickets">' + renderTicketsPanel() + '</div>';
    h += '</div>';
    h += '</div>';

    return h;
}

// Delegated dirty tracking. Bound once at document level because the editor is
// re-rendered into #viewContent (a fresh element each time), so binding to the
// container itself would be lost on every repaint.
document.addEventListener('input', _settingsDirtyDelegate);
document.addEventListener('change', _settingsDirtyDelegate);
function _settingsDirtyDelegate(e) {
    if (!SETTINGS_OPEN) return;
    if (e.target && e.target.closest && e.target.closest('.pk-sv-inline')) markSettingsDirty();
}

function renderGamePane() {
    var h = '';

    // ── Game ──
    h += '<div class="pk-cfg-section"><div class="pk-cfg-title">Game</div>';
    h += '<div class="pk-settings-grid">';
    // Game Type first: it decides what the rest of this editor even contains —
    // picking Cash strips the Blinds, Timer and Payouts tabs. Buy-in next, so
    // the two settings that define the game sit together; League last, since it
    // rebinds the event rather than configuring it.
    h += '<div><label for="cfg_game_type">Game Type</label><select id="cfg_game_type" data-act-change="previewGameType" data-change-a1="@value"><option value="tournament"' + (isTourney()?' selected':'') + '>Tournament</option><option value="cash"' + (isCash()?' selected':'') + '>Cash Game</option></select></div>';
    h += '<div><label for="cfg_buyin">Buy-in</label><div class="pk-money-wrap"><input type="number" id="cfg_buyin" value="' + Math.round(parseInt(SESSION.buyin_amount)/100) + '" step="1" min="0" data-act-input="updateBountyHint"></div></div>';
    // League is here rather than in the event editor because jackpots are league
    // funds and payout presets are scoped to a league, so hitting "this event
    // must belong to a league" used to mean leaving Setup and coming back.
    // Saved with everything else by Save, and applied before the jackpot check
    // server-side so a league and a jackpot can be set in one go.
    h += '<div><label' + (CAN_MOVE_LEAGUE ? ' for="cfg_league_id"' : '') + '>League ' + tip('Jackpot funds and saved payout presets belong to a league. Changing this moves the event.') + '</label>';
    if (CAN_MOVE_LEAGUE) {
        h += '<select id="cfg_league_id" data-act-change="markSettingsDirty">';
        h += '<option value="0"' + (!EVENT_LEAGUE_ID ? ' selected' : '') + '>&mdash; None &mdash;</option>';
        for (var li = 0; li < ASSIGNABLE_LEAGUES.length; li++) {
            var lg = ASSIGNABLE_LEAGUES[li];
            h += '<option value="' + lg.id + '"' + (parseInt(EVENT_LEAGUE_ID) === lg.id ? ' selected' : '') + '>' + escHtml(lg.name) + '</option>';
        }
        h += '</select>';
    } else {
        // No league role anywhere: show the binding, don't offer to change it.
        var curName = '';
        for (var lj = 0; lj < ASSIGNABLE_LEAGUES.length; lj++) {
            if (ASSIGNABLE_LEAGUES[lj].id === parseInt(EVENT_LEAGUE_ID)) curName = ASSIGNABLE_LEAGUES[lj].name;
        }
        h += '<div style="padding:.4rem 0;font-size:.85rem;color:#64748b">' + (curName ? escHtml(curName) : 'None')
           + '<br><span style="font-size:.72rem">Only a league owner or manager can change this.</span></div>';
    }
    h += '</div>';
    h += '</div></div>';

    // ── Chips, rebuys & add-ons (tournament only). Rebuys and Add-ons are
    // grouped boxes whose Yes/No header gates their own fields. ──
    h += '<div class="pk-cfg-section" id="cfgTourneyFields" style="' + (isCash()?'display:none':'') + '"><div class="pk-cfg-title" style="display:flex;align-items:center;gap:.5rem">Chips, Rebuys &amp; Add-ons'
       + '<button class="pk-help-btn" style="padding:.15rem .5rem;font-size:.7rem;min-width:24px;min-height:24px" title="How chips, rebuys, add-ons and the free stacks work" aria-label="Chips, rebuys and add-ons help" data-act="showChipsHelp">?</button></div>';
    // Four boxes: what a buy-in gets (Chips), what a rebuy and an add-on cost
    // (Rebuys, Add-ons, each gated by its Allowed), and what the entry fee
    // already covers (Free with the buy-in, full width, the cross-cutting rule).
    var aoShared = parseInt(SESSION.addons_share_rebuys) === 1;
    h += '<div class="pk-subgrids">';
    h += '<div class="pk-subbox" id="subbox_chips" style="flex:0 1 180px;min-width:160px">';
    h += '<div class="pk-subbox-head"><span>&#9921; Chips</span></div>';
    h += '<div class="pk-subbox-body on" style="grid-template-columns:1fr">';
    h += '<div><label for="cfg_chips">Starting Chips</label><input type="number" id="cfg_chips" value="' + SESSION.starting_chips + '" min="1" title="The stack every buy-in gets"></div>';
    h += '</div></div>';
    h += '<div class="pk-subbox">';
    h += '<div class="pk-subbox-head"><span>&#8635; Rebuys</span><label class="pk-subbox-check"><input type="checkbox" id="cfg_rebuy_allowed" aria-label="Rebuys allowed"' + (parseInt(SESSION.rebuy_allowed)?' checked':'') + ' data-act-change="toggleSubBox" data-change-a1="rebuy" data-change-a2="@checked01"> Allowed</label></div>';
    h += '<div class="pk-subbox-body' + (parseInt(SESSION.rebuy_allowed)?' on':'') + '" id="subbox_rebuy">';
    h += '<div><label for="cfg_rebuy">Rebuy</label><div class="pk-money-wrap"><input type="number" id="cfg_rebuy" value="' + Math.round(parseInt(SESSION.rebuy_amount)/100) + '" step="1" min="0"></div></div>';
    h += '<div><label for="cfg_max_rebuys">Max (0=unlimited)</label><input type="number" id="cfg_max_rebuys" value="' + SESSION.max_rebuys + '" min="0" title="Rebuys per player, free ones included"></div>';
    h += '<div><label for="cfg_rebuy_cutoff">Close after level</label><input type="number" id="cfg_rebuy_cutoff" value="' + (parseInt(SESSION.rebuy_cutoff_level) || 0) + '" min="0" title="The clock\'s level; the break right after it still counts. 0 = rebuys all game. Past it, the + on a row asks before recording one."></div>';
    h += '</div></div>';
    h += '<div class="pk-subbox">';
    h += '<div class="pk-subbox-head"><span>&#10133; Add-ons</span><label class="pk-subbox-check"><input type="checkbox" id="cfg_addon_allowed" aria-label="Add-ons allowed"' + (parseInt(SESSION.addon_allowed)?' checked':'') + ' data-act-change="toggleSubBox" data-change-a1="addon" data-change-a2="@checked01"> Allowed</label></div>';
    h += '<div class="pk-subbox-body' + (parseInt(SESSION.addon_allowed)?' on':'') + '" id="subbox_addon">';
    h += '<div><label for="cfg_addon">Add-on</label><div class="pk-money-wrap"><input type="number" id="cfg_addon" value="' + Math.round(parseInt(SESSION.addon_amount)/100) + '" step="1" min="0"></div></div>';
    h += '<div><label for="cfg_addon_chips">Add-on Chips</label><input type="number" id="cfg_addon_chips" value="' + (parseInt(SESSION.addon_chips) || parseInt(SESSION.starting_chips) || 0) + '" min="0" title="Chips granted per add-on taken"></div>';
    h += '</div></div>';
    // What the entry fee already covers. The counts are recorded at $0 at the
    // table; the choice below says whether the free rebuy and the free add-on
    // are two stacks or one (Bernard's game: a rebuy before the break, or the
    // add-on at the break). The choice only matters once a free rebuy exists.
    h += '<div class="pk-subbox pk-subbox-wide" id="subbox_free">';
    h += '<div class="pk-subbox-head"><span>&#127873; Free with the buy-in <small style="font-weight:400;color:#64748b">&middot; what the entry fee already covers</small></span></div>';
    h += '<div class="pk-subbox-body on">';
    h += '<div><label for="cfg_rebuys_included">Free rebuys</label><input type="number" id="cfg_rebuys_included" value="' + (parseInt(SESSION.rebuys_included) || 0) + '" min="0" title="Rebuys per player the entry fee already covers. Recorded at $0; any beyond cost the Rebuy amount." data-act-input="syncFreeStackRow"></div>';
    h += '<div id="cfg_addons_included_wrap"' + (aoShared ? ' style="display:none"' : '') + '><label for="cfg_addons_included">Free add-ons</label><input type="number" id="cfg_addons_included" value="' + (parseInt(SESSION.addons_included) || 0) + '" min="0" title="Add-ons per player the entry fee already covers. Recorded at $0; any beyond cost the Add-on amount."></div>';
    h += '<div id="addonFreeNote"' + (aoShared ? '' : ' style="display:none"') + '><label>Free add-ons</label><div style="font-size:.85rem;color:#64748b;min-height:30px;display:flex;align-items:center" title="A player who used their free rebuy pays the Add-on amount">Free unless the rebuy was used</div></div>';
    h += '<div id="freeStackRow" style="grid-column:1/-1' + (parseInt(SESSION.rebuys_included) > 0 ? '' : ';display:none') + '">';
    h += '<label for="cfg_free_stack">Free rebuy and free add-on</label><select id="cfg_free_stack" data-act-change="toggleAddonShare" data-change-a1="@value">'
       + '<option value="0"' + (aoShared ? '' : ' selected') + '>Separate: a player may take both</option>'
       + '<option value="1"' + (aoShared ? ' selected' : '') + '>One stack: a rebuy before the break, or the add-on at the break</option></select>'
       + '<div style="font-size:.78rem;color:#64748b;margin-top:.35rem">One stack: the entry fee covers a rebuy if the player busts before the break, or the add-on at the break. A player who took the rebuy pays for the add-on.</div>';
    h += '</div>';
    h += '</div></div>';
    h += '</div>';   // .pk-subgrids
    h += '</div>';   // .pk-cfg-section

    // ── Tables ──
    h += '<div class="pk-cfg-section"><div class="pk-cfg-title">Tables</div>';
    h += '<div class="pk-settings-grid">';
    h += '<div><label for="cfg_tables">Number of Tables</label><input type="number" id="cfg_tables" value="' + SESSION.num_tables + '" min="1"></div>';
    h += '<div><label for="cfg_seats_per_table">Seats per Table</label><input type="number" id="cfg_seats_per_table" value="' + (SESSION.seats_per_table || 8) + '" min="2" max="20"></div>';
    h += '</div>';
    h += '<label class="pk-subbox-check" style="margin-top:.6rem;display:inline-flex"><input type="checkbox" id="cfg_auto_assign"' + (parseInt(SESSION.auto_assign_tables) ? ' checked' : '') + '> Auto-assign tables</label>';
    h += '</div>';
    return h;
}

function renderPayoutsPane() {
    var h = '';

    // ── Bonus rewards: opt-in toggles keep a plain game plain ──
    h += '<div class="pk-cfg-section" id="cfgRewardsSection" style="' + (isCash()?'display:none':'') + '"><div class="pk-cfg-title">Bonus Rewards <span style="font-weight:400;text-transform:none;letter-spacing:0">— optional, tap to enable</span></div>';
    h += '<div class="pk-reward-chips">';
    h += '<button type="button" class="pk-reward-chip' + (REWARDS_UI.bounty ? ' on' : '') + '" aria-pressed="' + (REWARDS_UI.bounty ? 'true' : 'false') + '" id="chip_bounty" data-act="toggleReward" data-a1="bounty">🎯 Bounties</button>';
    h += '<button type="button" class="pk-reward-chip' + (REWARDS_UI.pts ? ' on' : '') + '" aria-pressed="' + (REWARDS_UI.pts ? 'true' : 'false') + '" id="chip_pts" data-act="toggleReward" data-a1="pts">🏆 League points</button>';
    h += '<button type="button" class="pk-reward-chip' + (REWARDS_UI.ticket ? ' on' : '') + '" aria-pressed="' + (REWARDS_UI.ticket ? 'true' : 'false') + '" id="chip_ticket" data-act="toggleReward" data-a1="ticket">🎟 Satellite seat</button>';
    h += '<button type="button" class="pk-reward-chip' + (REWARDS_UI.label ? ' on' : '') + '" aria-pressed="' + (REWARDS_UI.label ? 'true' : 'false') + '" id="chip_label" data-act="toggleReward" data-a1="label">🎁 Prizes</button>';
    if (EVENT_LEAGUE_ID) {
        h += '<button type="button" class="pk-reward-chip' + (REWARDS_UI.jackpot ? ' on' : '') + '" aria-pressed="' + (REWARDS_UI.jackpot ? 'true' : 'false') + '" id="chip_jackpot" data-act="toggleReward" data-a1="jackpot">💎 Jackpots</button>';
    } else {
        h += '<button type="button" class="pk-reward-chip" disabled style="opacity:.4;cursor:default" title="Jackpots are league funds — this event has no league">💎 Jackpots</button>';
    }
    h += '</div>';
    // Bounty fields
    h += '<div class="pk-reward-body' + (REWARDS_UI.bounty ? ' on' : '') + '" id="rewardBody_bounty">';
    h += '<div class="pk-settings-grid">';
    h += '<div><label for="cfg_bounty">Bounty ($ each)</label><div class="pk-money-wrap"><input type="number" id="cfg_bounty" value="' + Math.round((parseInt(SESSION.bounty_amount) || 0)/100) + '" step="1" min="0" data-act-input="updateBountyHint"></div></div>';
    h += '<div><label for="cfg_bounty_mode">Collected as</label><select id="cfg_bounty_mode" data-act-change="updateBountyHint"><option value="0"' + (!parseInt(SESSION.bounty_optional) ? ' selected' : '') + '>Baked into buy-in (everyone)</option><option value="1"' + (parseInt(SESSION.bounty_optional) ? ' selected' : '') + '>Optional add-on (per player)</option></select></div>';
    h += '<div><label for="cfg_bounty_points">Bounty points per KO</label><input type="number" id="cfg_bounty_points" value="' + (parseInt(SESSION.bounty_points) || 0) + '" step="1" min="0"></div>';
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
    h += '<div><label for="cfg_ticket_target">Winner\'s seat is good for</label><select id="cfg_ticket_target"><option value="0">— pick a target event —</option></select></div>';
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
    h += '<div><label for="cfg_jackpot">Jackpot entry ($)</label><div class="pk-money-wrap"><input type="number" id="cfg_jackpot" value="' + Math.round((parseInt(SESSION.jackpot_amount) || 0)/100) + '" step="1" min="0"></div></div>';
    h += '<div><label for="cfg_jackpot_mode">Collected as</label><select id="cfg_jackpot_mode"><option value="0"' + (!parseInt(SESSION.jackpot_optional) ? ' selected' : '') + '>Baked into buy-in (everyone)</option><option value="1"' + (parseInt(SESSION.jackpot_optional) ? ' selected' : '') + '>Optional add-on (per player)</option></select></div>';
    h += '</div>';
    h += '<div style="font-size:.78rem;color:#64748b;margin-top:.35rem"><b>Optional</b>: tick the 💎 box next to each player who\'s in (on top of the buy-in). <b>Baked in</b>: every buy-in contributes and the amount is withheld from the prize pool. Entries feed the league\'s progressive jackpot at finish; pays out on a bad beat or royal flush. Current fund: 💎 <b>' + formatMoney(JACKPOTS.balance) + '</b>. Record a hit from the 💎 button on the game screen.</div>';
    h += '</div>';
    h += '</div>';

    // Payout editor (tournament only). Reward columns show only when their
    // feature chip is on (show-pts / show-ticket / show-label classes).
    var payoutCls = (REWARDS_UI.pts ? ' show-pts' : '') + (REWARDS_UI.ticket ? ' show-ticket' : '') + (REWARDS_UI.label ? ' show-label' : '');
    h += '<div class="pk-payout-editor pk-cfg-section' + payoutCls + '" id="cfgPayoutSection" style="' + (isCash()?'display:none':'') + '">';
    h += '<div class="pk-cfg-title">Payout Structure</div>';
    h += '<div aria-hidden="true" style="display:flex;gap:.35rem;font-size:.68rem;color:#64748b;font-weight:700;text-transform:uppercase;letter-spacing:.03em;margin-bottom:.15rem"><span style="width:40px"></span><span style="flex:1;min-width:56px;max-width:140px">Cash %</span><span class="col-pts" style="flex:1;min-width:48px;max-width:140px">Pts</span><span class="col-ticket" style="flex:1;min-width:56px;max-width:140px">Ticket $</span><span class="col-label" style="flex:2;min-width:80px">Prize</span><span style="width:18px"></span></div>';
    h += '<div id="payoutRows">';
    for (var i = 0; i < PAYOUTS.length; i++) {
        h += payoutRowHtml(PAYOUTS[i].place, PAYOUTS[i].percentage, PAYOUTS[i].points, PAYOUTS[i].ticket_cents, PAYOUTS[i].prize_label);
    }
    h += '</div>';
    h += '<div style="display:flex;gap:.5rem;margin-top:.3rem;flex-wrap:wrap">';
    h += '<button data-act="addPayoutRow">+ Add Place</button>';
    h += '<button data-act="autoSplitPayouts">Auto Split</button>';
    h += '</div>';
    h += '<div id="payoutSum" style="margin-top:.3rem;font-size:.8rem;color:#64748b"></div>';
    h += '</div>';
    return h;
}

function payoutRowHtml(place, pct, points, ticket_cents, prize_label) {
    var lbl = prize_label ? String(prize_label).replace(/"/g, '&quot;').replace(/</g, '&lt;') : '';
    var ord = place + getOrdinal(place);   // each input is named by its place: the column heads are decoration
    return '<div class="row" style="display:flex;gap:.35rem;align-items:center;flex-wrap:wrap;margin-bottom:.25rem">'
         + '<span style="font-size:.8rem;width:40px;flex-shrink:0">' + ord + '</span>'
         + '<input type="number" class="payout-pct" aria-label="' + ord + ' place: cash %" value="' + (pct || 0) + '" step="0.1" min="0" max="100" data-place="' + place + '" data-act-input="updatePayoutSum" title="Cash % of the pool" style="flex:1;min-width:56px;max-width:140px">'
         + '<input type="number" class="payout-pts" aria-label="' + ord + ' place: league points" value="' + (parseInt(points) || 0) + '" step="1" min="0" data-place="' + place + '" title="League points for this place" style="flex:1;min-width:48px;max-width:140px">'
         + '<span class="pk-money-wrap payout-ticket-wrap" style="flex:1;min-width:56px;max-width:140px"><input type="number" class="payout-ticket" aria-label="' + ord + ' place: ticket $" value="' + (ticket_cents ? Math.round(parseInt(ticket_cents)/100) : 0) + '" step="1" min="0" data-place="' + place + '" title="Entry ticket value ($) to the target event"></span>'
         + '<input type="text" class="payout-label" aria-label="' + ord + ' place: prize" value="' + lbl + '" maxlength="60" placeholder="prize…" data-place="' + place + '" title="Custom prize (trophy, bottle, …)" style="flex:2;min-width:80px">'
         + '<button type="button" aria-label="Remove ' + ord + ' place" data-act="removePayoutRow" style="color:#dc2626;background:transparent;border:none;cursor:pointer;font-size:1rem;flex-shrink:0;min-width:24px;min-height:24px">&times;</button></div>';
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
        el.style.color = sum > 100 ? '#dc2626' : sum === 100 ? '#15803d' : '#64748b';
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
    ['cfgTourneyFields', 'cfgPayoutSection', 'ticketsPanel', 'cfgRewardsSection', 'cfgPresetSection'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el) el.style.display = hide ? 'none' : '';
    });
    // The Payouts & Rewards and Tickets tabs are gated on game type, which is
    // read from the SAVED session when the editor renders. Without this, picking
    // Tournament left both tabs dead until you saved and reopened — you had to
    // save a game type before you could configure the payouts for it.
    var payTab = document.querySelector('.pk-sv-tab[data-tab="payouts"]');
    if (payTab) {
        payTab.classList.toggle('disabled', hide);
        if (hide) {
            payTab.setAttribute('disabled', 'disabled');
            payTab.setAttribute('title', 'Cash games have no payout structure');
            payTab.onclick = null;
            // Don't strand the user on a tab that just became unreachable.
            if (SETTINGS_TAB === 'payouts') setSettingsTab('game');
        } else {
            payTab.removeAttribute('disabled');
            payTab.removeAttribute('title');
            payTab.onclick = function() { setSettingsTab('payouts'); };
        }
    }
    // Tickets only exists when the game has already awarded some; hide rather
    // than enable, since switching to cash makes it meaningless either way.
    var tickTab = document.querySelector('.pk-sv-tab[data-tab="tickets"]');
    if (tickTab) {
        tickTab.style.display = hide ? 'none' : '';
        if (hide && SETTINGS_TAB === 'tickets') setSettingsTab('game');
    }
    // Blinds and Timer follow the dropdown immediately. They stay DISABLED
    // until the game type has actually been saved, because event_setup_dl
    // refuses any session that is not already a saved tournament — revealing
    // them enabled would hand the host two panes that can only fail.
    var savedTourney = !isCash();
    ['blinds', 'timer'].forEach(function (name) {
        var t = document.querySelector('.pk-sv-tab[data-tab="' + name + '"]');
        if (!t) return;
        t.style.display = hide ? 'none' : '';
        var needsSave = !hide && !savedTourney;
        t.classList.toggle('disabled', needsSave);
        if (needsSave) {
            t.setAttribute('disabled', 'disabled');
            t.setAttribute('title', 'Save the game as a tournament first — the blind schedule and timer display need a saved tournament.');
        } else {
            t.removeAttribute('disabled');
            t.removeAttribute('title');
        }
        if ((hide || needsSave) && SETTINGS_TAB === name) setSettingsTab('game');
    });
    // Chip set follows the dropdown too, but is never gated on a SAVED
    // tournament: it rides along in the normal settings save (chip_set), not
    // event_setup_dl, so it works before the game type has been committed.
    var chipTab = document.querySelector('.pk-sv-tab[data-tab="chips"]');
    if (chipTab) {
        chipTab.style.display = hide ? 'none' : '';
        if (hide && SETTINGS_TAB === 'chips') setSettingsTab('game');
    }
    // Showing or hiding a segment changes the strip's widths, so the thumb has
    // to be re-measured even when the active tab itself did not change.
    positionSegThumb('settingsSeg', true);
}

// ── Chip set editor ─────────────────────────────────────────────────────────
// Held here rather than read back off the DOM at save time so a row can be
// added, removed and reordered without the values shifting under it.
var CHIP_SET = [];
// Casino-standard denominations and the colours that go with them, which is
// what most home sets ship as.
var STANDARD_CHIPS = [
    { v: 25,   c: '#ffffff' }, { v: 100,  c: '#ef4444' }, { v: 500,  c: '#22c55e' },
    { v: 1000, c: '#2563eb' }, { v: 5000, c: '#0f172a' }
];
var CHIP_COLOURS = ['#ffffff', '#ef4444', '#22c55e', '#2563eb', '#0f172a',
                    '#f59e0b', '#a855f7', '#ec4899', '#94a3b8', '#7c2d12'];

function loadChipSet() {
    var raw = SESSION && SESSION.chip_set;
    try { CHIP_SET = raw ? (typeof raw === 'string' ? JSON.parse(raw) : raw) : []; }
    catch (e) { CHIP_SET = []; }
    if (!Array.isArray(CHIP_SET)) CHIP_SET = [];
}

function renderChipRows() {
    var box = document.getElementById('chipRows');
    if (!box) return;
    box.textContent = '';
    if (!CHIP_SET.length) {
        var none = document.createElement('div');
        none.className = 'pk-chip-none';
        none.textContent = 'No chip set — the timer will not show a legend.';
        box.appendChild(none);
        return;
    }
    CHIP_SET.forEach(function (chip, i) {
        var row = document.createElement('div');
        row.className = 'pk-chip-row';
        // Every control on the row names the chip it belongs to.
        var cn = 'chip ' + ((+chip.v || 0) > 0 ? chip.v : '#' + (i + 1));

        var sw = document.createElement('input');
        sw.type = 'color'; sw.value = chip.c || '#ffffff'; sw.className = 'pk-chip-colour';
        sw.title = 'Chip colour'; sw.setAttribute('aria-label', 'Colour of ' + cn);
        sw.addEventListener('change', function () { CHIP_SET[i].c = sw.value; markSettingsDirty(); });
        row.appendChild(sw);

        // Image: a photo of the real chip. Doubles as its own preview, and sits
        // beside the colour rather than replacing it — the colour is what shows
        // if the file is ever deleted.
        var img = document.createElement('button');
        img.type = 'button'; img.className = 'pk-chip-img' + (chip.img ? ' has-img' : '');
        img.textContent = chip.img ? '' : 'IMG';
        img.title = chip.img ? 'Change this chip\'s image' : 'Use a photo of the real chip';
        img.setAttribute('aria-label', (chip.img ? 'Change the image of ' : 'Use a photo for ') + cn);
        if (chip.img) img.style.backgroundImage = 'url("' + chip.img + '")';
        img.addEventListener('click', function () { pickChipImage(i); });
        row.appendChild(img);

        if (chip.img) {
            var clr = document.createElement('button');
            clr.type = 'button'; clr.className = 'es-mini'; clr.textContent = '⌫';
            clr.title = 'Drop the image and go back to the colour'; clr.setAttribute('aria-label', 'Drop the image of ' + cn);
            clr.addEventListener('click', function () {
                delete CHIP_SET[i].img; renderChipRows(); markSettingsDirty();
            });
            row.appendChild(clr);
        }

        var val = document.createElement('input');
        val.type = 'number'; val.min = '0'; val.step = 'any'; val.inputMode = 'decimal';
        val.value = chip.v; val.className = 'pk-chip-val'; val.setAttribute('aria-label', 'Value of ' + cn);
        val.addEventListener('input', function () { CHIP_SET[i].v = parseFloat(val.value) || 0; markSettingsDirty(); });
        row.appendChild(val);

        var rm = document.createElement('button');
        rm.type = 'button'; rm.className = 'es-mini es-mini-danger'; rm.textContent = '×';
        rm.title = 'Remove this chip'; rm.setAttribute('aria-label', 'Remove ' + cn);
        rm.addEventListener('click', function () { CHIP_SET.splice(i, 1); renderChipRows(); markSettingsDirty(); });
        row.appendChild(rm);

        box.appendChild(row);
    });
}

// One hidden input reused by every row: a picker per chip would put a dozen
// file inputs in the DOM for a control only one of which can be open at a time.
// No accept filter, deliberately — iOS greys out anything it has no registered
// type for, and the server checks the bytes rather than the name anyway.
var _chipImgInput = null, _chipImgFor = -1;
function pickChipImage(i) {
    if (!_chipImgInput) {
        _chipImgInput = document.createElement('input');
        _chipImgInput.type = 'file';
        _chipImgInput.hidden = true;
        _chipImgInput.addEventListener('change', uploadChipImage);
        document.body.appendChild(_chipImgInput);
    }
    _chipImgFor = i;
    _chipImgInput.value = '';
    _chipImgInput.click();
}

function uploadChipImage() {
    var file = _chipImgInput.files && _chipImgInput.files[0];
    var i = _chipImgFor;
    if (!file || !CHIP_SET[i]) return;
    if (file.size > 8 * 1024 * 1024) { pkAlert('That image is too large (max 8 MB).'); return; }
    var fd = new FormData();
    fd.append('action', 'upload_image');
    fd.append('csrf_token', CSRF);
    fd.append('image', file);
    // Shares the timer-layout image folder and its daily cap: same kind of file,
    // same sweepable place, one validated path prefix instead of two.
    fetch('/timer_layouts_dl.php', { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (j) {
            if (!j || !j.url) { pkAlert((j && j.error) || 'Could not upload that image.'); return; }
            CHIP_SET[i].img = j.url;
            renderChipRows();
            markSettingsDirty();
        })
        .catch(function () { pkAlert('Upload failed.'); });
}

function addChipRow() {
    // Next unused colour, and roughly the next denomination up, so adding a
    // chip is one click rather than two fields.
    var used = CHIP_SET.map(function (c) { return c.c; });
    var col = CHIP_COLOURS.filter(function (c) { return used.indexOf(c) === -1; })[0] || '#ffffff';
    var last = CHIP_SET.length ? CHIP_SET[CHIP_SET.length - 1].v : 0;
    CHIP_SET.push({ v: last ? last * 5 : 25, c: col });
    renderChipRows();
    markSettingsDirty();
}
function fillStandardChips() { CHIP_SET = STANDARD_CHIPS.map(function (c) { return { v: c.v, c: c.c }; }); renderChipRows(); markSettingsDirty(); }
function clearChipSet() { CHIP_SET = []; renderChipRows(); markSettingsDirty(); }

// ─── Reward feature toggles (progressive disclosure) ──────
// Turning a feature ON reveals its fields/columns; turning it OFF clears the
// values too — the chip is the single switch for "this game uses X".
function toggleReward(key) {
    // Switching a reward off wipes its amounts, so it asks when there are any.
    if (REWARDS_UI[key] && rewardHasValues(key)) {
        var names = { bounty: 'bounties', pts: 'league points', ticket: 'the satellite seat', label: 'prizes', jackpot: 'the jackpot entry' };
        pkConfirm('Turn off ' + (names[key] || key) + '? The amounts entered for it are cleared.', { okLabel: 'Turn off', danger: true })
            .then(function(ok) { if (ok) applyToggleReward(key); });
        return;
    }
    applyToggleReward(key);
}
function rewardHasValues(key) {
    var v = function(id) { var el = document.getElementById(id); return el ? (parseFloat(el.value) || 0) : 0; };
    var any = function(sel) {
        return Array.prototype.some.call(document.querySelectorAll(sel), function(i) {
            return key === 'label' ? !!String(i.value || '').trim() : (parseFloat(i.value) || 0) > 0;
        });
    };
    if (key === 'bounty') return v('cfg_bounty') > 0 || v('cfg_bounty_points') > 0;
    if (key === 'jackpot') return v('cfg_jackpot') > 0;
    if (key === 'pts') return any('#payoutRows .payout-pts');
    if (key === 'ticket') return v('cfg_ticket_target') > 0 || any('#payoutRows .payout-ticket');
    if (key === 'label') return any('#payoutRows .payout-label');
    return false;
}
function applyToggleReward(key) {
    REWARDS_UI[key] = !REWARDS_UI[key];
    markSettingsDirty();
    var on = REWARDS_UI[key];
    var chip = document.getElementById('chip_' + key);
    if (chip) { chip.classList.toggle('on', on); chip.setAttribute('aria-pressed', on ? 'true' : 'false'); }
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
// The league fund only receives this game's jackpot money when the game is
// FINISHED — the contribution is written once, guarded against re-finishing, in
// pk_apply_tournament_payouts(). Mid-game the balance alone therefore reads as
// "nothing collected" even with a table full of paid-up entries, which is
// exactly wrong on the screen used to pay a jackpot out. Show what this game has
// taken and what the fund becomes, clearly marked as not banked yet.
function jackpotPendingCents() {
    if (!SESSION) return 0;
    var jAmt = parseInt(SESSION.jackpot_amount) || 0;
    if (jAmt <= 0) return 0;
    var collected = parseInt(SESSION.jackpot_optional)
        ? (parseInt(POOL.jackpot_collected) || 0)
        : (parseInt(POOL.jackpot_withheld) || 0);
    // Subtract what is already in the fund for this game. Finishing banks all of
    // it; recording a hit mid-game banks it early so the payout can draw on it.
    // Without this the banked money would be shown as pending as well.
    return Math.max(0, collected - (parseInt(JACKPOTS.contributed) || 0));
}

function jackpotBalanceHtml() {
    var bal = parseInt(JACKPOTS.balance) || 0;
    var h = 'Fund: <b>' + formatMoney(bal) + '</b>';
    var pending = jackpotPendingCents();
    if (pending > 0) {
        var optional = parseInt(SESSION.jackpot_optional);
        var n = optional ? (parseInt(POOL.jackpot_entries) || 0) : (parseInt(POOL.total_buyins) || 0);
        var unit = optional ? (n === 1 ? 'entry' : 'entries') : (n === 1 ? 'buy-in' : 'buy-ins');
        h += '<div style="margin-top:.35rem;font-size:.8rem;color:#7c3aed;font-weight:600">'
           + '+ ' + formatMoney(pending) + ' collected this game '
           + '<span style="color:#64748b;font-weight:400">(' + n + ' ' + unit + ' &times; '
           + formatMoney(parseInt(SESSION.jackpot_amount)) + ')</span></div>'
           + '<div style="font-size:.75rem;color:#64748b">Added to the fund when the game is finished &mdash; '
           + 'total after finish <b>' + formatMoney(bal + pending) + '</b></div>';
    }
    return h;
}

function openJackpotModal() {
    var b = document.getElementById('jpBalances');
    b.innerHTML = jackpotBalanceHtml();
    document.getElementById('jpRecipients').innerHTML = '';
    addJackpotRecipient();
    document.getElementById('jpAdjustRow').style.display = 'none';
    loadJackpotHistory();
    openModal('jackpotModal', '#jpType');
}
function closeJackpotModal() {
    closeModal('jackpotModal');
}

// ── Fund history (view + void, same correction model as the game ledger) ──
function loadJackpotHistory() {
    var box = document.getElementById('jpHistory');
    box.innerHTML = '<span style="color:#64748b">Loading…</span>';
    fetch('/checkin_dl.php?action=jackpot_log&session_id=' + SESSION.id)
        .then(function(r) { return r.json(); })
        .then(function(j) {
            if (!j.ok) { box.textContent = j.error || 'Error'; return; }
            if (typeof j.balance === 'number') {
                JACKPOTS.balance = j.balance;
                if (typeof j.contributed === 'number') JACKPOTS.contributed = j.contributed;
                document.getElementById('jpBalances').innerHTML = jackpotBalanceHtml();
            }
            if (!j.entries.length) { box.innerHTML = '<span style="color:#64748b">No activity yet.</span>'; return; }
            var h = '';
            j.entries.forEach(function(e) {
                var amt = parseInt(e.amount);
                var icon = e.event_type === 'hit' ? '💥' : (e.event_type === 'adjust' ? '±' : '➕');
                var struck = parseInt(e.voided) ? 'text-decoration:line-through;opacity:.5;' : '';
                h += '<div style="display:flex;gap:.4rem;align-items:center;padding:.2rem 0;border-bottom:1px solid #f1f5f9">'
                   + '<span style="' + struck + 'flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="' + escHtml(e.detail || '') + '">'
                   + icon + ' ' + (e.player_name ? escHtml(e.player_name) + ' — ' : '') + escHtml((e.detail || '').substring(0, 60))
                   + ' <span style="color:#64748b">' + (e.created_at || '').substring(0, 10) + '</span></span>'
                   + '<span style="' + struck + 'font-weight:700;color:' + (amt >= 0 ? '#15803d' : '#dc2626') + '">' + (amt >= 0 ? '+' : '−') + formatMoney(Math.abs(amt)) + '</span>'
                   + (parseInt(e.voided) ? '' : '<button type="button" data-act="voidJackpotEntry" data-a1="' + e.id + '" title="Void this entry (reverses its effect on the fund)" aria-label="Void this entry" style="color:#dc2626;background:transparent;border:none;cursor:pointer;font-size:.9rem;min-width:24px;min-height:24px">&times;</button>')
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
    row.innerHTML = '<select class="jp-name" aria-label="Paid to" style="flex:2;padding:.45rem .5rem;border:1.5px solid var(--border,#e2e8f0);border-radius:6px;font-size:.9rem">' + opts + '</select>'
        + '<span class="pk-money-wrap" style="flex:1;min-width:80px"><input type="number" class="jp-amount" aria-label="Amount paid" step="0.01" min="0" placeholder="0"></span>'
        + '<button type="button" aria-label="Remove this recipient" data-act="removeSelfParent" style="color:#dc2626;background:transparent;border:none;cursor:pointer;font-size:1rem;min-width:24px;min-height:24px">&times;</button>';
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

// Live explainer under the bounty fields, mode-aware.
function updateBountyHint() {
    var el = document.getElementById('bountyHint');
    if (!el) return;
    var buyin = Math.max(0, Math.round(parseFloat((document.getElementById('cfg_buyin') || {}).value || 0)));
    var bounty = Math.max(0, Math.round(parseFloat((document.getElementById('cfg_bounty') || {}).value || 0)));
    var optional = svModeVal('cfg_bounty_mode', '0') === '1';
    if (bounty <= 0) { el.textContent = ''; return; }
    if (optional) {
        el.textContent = 'Optional side pot: tick the 🎯 box for each player who\'s in — they pay $' + bounty + ' on top of the buy-in, carry a $' + bounty + ' bounty, and only pool members can collect. The prize pool is untouched.';
        return;
    }
    if (bounty >= buyin) {
        el.innerHTML = '<span style="color:#dc2626">A baked-in bounty must be less than the buy-in — it comes out of it.</span>';
        return;
    }
    el.textContent = 'Each $' + buyin + ' buy-in = $' + (buyin - bounty) + ' to the prize pool + $' + bounty + ' bounty on that player\'s head. Knock someone out, collect their bounty.';
}

// Read a mode select's value with a fallback for unmounted panes.
function svModeVal(id, def) {
    var el = document.getElementById(id);
    return el ? el.value : def;
}

// Mode selects change which opt-in columns exist — but only after Save; the
// roster reads SESSION. This just nudges the hint copy live.
function refreshOptInColumns() { updateBountyHint(); }

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
            h += '<button class="pk-act-btn" data-act="retargetTicket" data-a1="' + t.id + '">Re-target</button>';
            h += '<button class="pk-act-btn" data-act="convertTicket" data-a1="' + t.id + '">Convert to cash</button>';
            h += '</span>';
        } else {
            h += '<span style="margin-left:auto;color:' + (status === 'redeemed' ? '#15803d' : '#64748b') + ';font-weight:700;font-size:.75rem;text-transform:uppercase">' + status + '</span>';
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
        var lines = events.map(function(e, i) { return (i + 1) + ': ' + escHtml(e.title) + ' (' + escHtml(e.start_date) + ')'; });
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

function showChipsHelp() {
    pkAlert(
        '<div class="pk-help-content">'
        + '<h4>Chips</h4>'
        + '<p><b>Starting Chips</b> is the stack every buy-in gets.</p>'
        + '<h4>Rebuys</h4>'
        + '<p>A rebuy is a fresh stack for a player who busts. <b>Rebuy</b> is what it costs, <b>Max</b> caps how many one player may take (0 = no cap), and <b>Close after level</b> stops rebuys once the clock is past that level; the break right after it still counts. Past the close, the + on a row asks before recording one.</p>'
        + '<h4>Add-ons</h4>'
        + '<p>An add-on is an extra stack bought at the break, usually by everyone still in. <b>Add-on</b> is what it costs and <b>Add-on Chips</b> what it gives. At the break, <b>Add-on for everyone still in</b> records them all in one tap.</p>'
        + '<h4>Free with the buy-in</h4>'
        + '<p>Some games fold a rebuy or an add-on into the entry fee: everyone pays one price at the door. Enter how many <b>free rebuys</b> and <b>free add-ons</b> that price covers; those are recorded at $0 and the prize pool counts only the ones bought beyond them.</p>'
        + '<p><b>Free rebuy and free add-on</b>: <i>Separate</i> means a player may take both. <i>One stack</i> means the fee covers one or the other: a rebuy if they bust before the break, or the add-on at the break. A player who took the rebuy pays the add-on price.</p>'
        + '<h4>Example</h4>'
        + '<p>$30 buy-in that includes one stack, taken as a rebuy or at the break: Rebuy $20, Free rebuys 1, Close after level 4 (the last level before the break), Add-on $10, One stack. A player who never busts takes the add-on free; one who rebought pays $10 for it; a second rebuy is $20.</p>'
        + '</div>',
        { title: 'Chips, rebuys and add-ons', okLabel: 'Got it' }
    );
}

function showPresetHelp() {
    pkAlert(
        '<div class="pk-help-content">'
        + '<h4>What a preset stores</h4>'
        + '<p>A snapshot of everything in this editor: the Game tab (buy-in, rebuys, add-ons, chips, tables), the Payouts &amp; Rewards tab (payout split, points, ticket prize values, prize labels, and the bounty and jackpot setup including baked-in vs. optional), the Blinds tab (the full level schedule), and the Timer tab (the Classic switch and this game\'s display layout). The satellite target event is the one thing not stored; that stays per-game.</p>'
        + '<h4>Load</h4>'
        + '<p>Applies the selected preset to <b>this game</b> right away, replacing the current setup on both tabs. There is no separate save step after loading.</p>'
        + '<h4>Save As&#8230;</h4>'
        + '<p>Saves the editor as a named preset so you can reuse the same setup for future games, e.g. "Friday $20 bounty" or "Free roll".</p>'
        + '<h4>Delete</h4>'
        + '<p><b>Delete</b> removes the selected preset. Games that used it are not affected.</p>'
        + '</div>',
        { title: 'Game presets', okLabel: 'Got it' }
    );
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
    renderPresetState();
}

// Both menu items act on the SELECTED preset, and for most people most of the
// time neither applies — a non-admin looking at the site default can do
// neither. Hiding them left the ⋯ opening a 12px empty box, which reads as a
// broken control. Nothing is hidden now: an item the server would refuse is
// shown disabled WITH THE REASON, and "no preset selected" gets a line saying
// so, so the menu always answers the question it was opened to ask.
function updatePayoutStructureButtons() {
    var sel = document.getElementById('payoutStructureSelect');
    var delBtn = document.getElementById('btnDelPayoutStructure');
    var defBtn = document.getElementById('btnDefPayoutStructure');
    var hint   = document.getElementById('presetMenuHint');
    if (!sel) return;

    function state(btn, allowed, reason) {
        if (!btn) return;
        btn.style.display = '';
        btn.disabled = !allowed;
        btn.classList.toggle('disabled', !allowed);
        if (reason) btn.title = reason; else btn.removeAttribute('title');
    }

    var opt = sel.options[sel.selectedIndex];
    if (!opt || !opt.value) {
        if (hint) {
            hint.style.display = '';
            hint.textContent = 'Choose a preset in the list first — these act on the selected preset.';
        }
        if (defBtn) defBtn.style.display = 'none';
        if (delBtn) delBtn.style.display = 'none';
        return;
    }
    if (hint) hint.style.display = 'none';

    var isDef    = opt.dataset.isDefault === '1';
    var isGlob   = opt.dataset.isGlobal  === '1';
    var leagueId = parseInt(opt.dataset.leagueId || 0) || 0;
    var mine     = parseInt(opt.dataset.createdBy || 0) === CURRENT_USER_ID;
    // ASSIGNABLE_LEAGUES is exactly the set this user owns or manages, which is
    // the same test delete_payout_structure applies server-side.
    var canLeague = IS_ADMIN || ASSIGNABLE_LEAGUES.some(function (l) { return l.id === leagueId; });

    // Site default is admin-only for EVERY preset, so a non-admin gets no
    // version of this action worth seeing — hide it rather than show a
    // permanently dead row. Delete is different: it IS available to them on
    // their own presets, so that one stays visible and explains itself.
    if (!defBtn) { /* nothing to do */ }
    else if (!IS_ADMIN) defBtn.style.display = 'none';
    else state(defBtn, !isDef, isDef ? 'This is already the site default.' : '');

    if (isDef)               state(delBtn, false, 'The site default cannot be deleted.');
    else if (isGlob)         state(delBtn, IS_ADMIN, IS_ADMIN ? '' : 'Only site admins can delete a global preset.');
    else if (leagueId)       state(delBtn, canLeague, canLeague ? '' : 'Only an owner or manager of that league can delete this preset.');
    else if (!mine)          state(delBtn, IS_ADMIN, IS_ADMIN ? '' : 'This preset belongs to another user.');
    else                     state(delBtn, true, '');
}

function onPayoutStructureChange() {
    updatePayoutStructureButtons();
}

// ── Preset provenance ────────────────────────────────────────────────────────
// PRESET_STATE mirrors poker_sessions.preset_structure_id and is refreshed from
// the server, never inferred locally: drift is a comparison against the preset
// as it exists now, which only the server can make.
var PRESET_STATE = null;

function refreshPresetState() {
    if (!SESSION || !SESSION.id) return;
    fetch('/checkin_dl.php?action=preset_state&session_id=' + SESSION.id)
        .then(function(r) { return r.json(); })
        .then(function(j) {
            if (!j || !j.ok) return;
            PRESET_STATE = j.preset || null;
            if (PRESET_STATE) CURRENT_STRUCTURE_ID = PRESET_STATE.id;
            renderPresetState();
        })
        .catch(function() {});
}

function renderPresetState() {
    var line = document.getElementById('presetFrom');
    var upd  = document.getElementById('btnUpdPayoutStructure');
    if (!line) return;
    line.textContent = '';
    if (!PRESET_STATE) {
        // Said plainly rather than left blank: "no origin" is a real state, and
        // it is the honest answer for a game whose setup was typed by hand.
        line.appendChild(svEl('span', 'pk-preset-none', 'Not from a preset'));
        setPresetSaveState();
        return;
    }
    line.appendChild(svEl('span', 'pk-preset-lbl', 'From:'));
    line.appendChild(svEl('span', 'pk-preset-name', PRESET_STATE.name));
    if (PRESET_STATE.modified) {
        var tag = svEl('span', 'pk-preset-mod', 'MODIFIED');
        tag.title = 'This game no longer matches the preset it was set up from.';
        line.appendChild(tag);
    }
    setPresetSaveState();
}

/* "Save preset" writes this game back over the preset it came from. It stays
 * VISIBLE at all times so the pair reads as Save / Save as…, and explains via
 * its tooltip why it is unavailable — hiding it made the bar's shape change
 * under the host every time they touched something. */
function setPresetSaveState() {
    var upd = document.getElementById('btnUpdPayoutStructure');
    if (!upd) return;
    var allowed = !!(PRESET_STATE && PRESET_STATE.modified && PRESET_STATE.can_update);
    upd.disabled = !allowed;
    upd.classList.toggle('disabled', !allowed);
    // Amber only when pressing it would write over a preset: the warning
    // colour on a button that could not be pressed read as the thing to click.
    upd.classList.toggle('pk-preset-update', allowed);
    upd.title = !PRESET_STATE ? 'This game was not set up from a preset — use Save as… to make one.'
        : !PRESET_STATE.can_update ? 'You cannot change "' + PRESET_STATE.name + '" — use Save as… to make your own copy.'
        : !PRESET_STATE.modified ? 'This game already matches "' + PRESET_STATE.name + '".'
        : 'Write this game\'s setup back over "' + PRESET_STATE.name + '".';
}

function svEl(tag, cls, text) {
    var e = document.createElement(tag);
    if (cls) e.className = cls;
    if (text !== undefined) e.textContent = text;
    return e;
}

var PRESET_OPEN_KEY = 'pk_preset_bar_open';
function presetBarOpen() {
    try { return localStorage.getItem(PRESET_OPEN_KEY) === '1'; } catch (e) { return false; }
}
function togglePresetBar() {
    var body = document.getElementById('presetBody');
    var btn  = document.getElementById('presetToggle');
    if (!body || !btn) return;
    var open = body.hidden;                      // about to open
    body.hidden = !open;
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    btn.innerHTML = open ? 'Hide presets &#9652;' : 'Show presets &#9662;';
    try { localStorage.setItem(PRESET_OPEN_KEY, open ? '1' : '0'); } catch (e) {}
}

function togglePresetMenu() {
    var m = document.getElementById('presetMenu');
    if (!m) return;
    if (m.classList.contains('open')) { closePresetMenu(true); return; }
    m.classList.add('open');
    var btn = document.querySelector('.pk-preset-morebtn');
    if (btn) btn.setAttribute('aria-expanded', 'true');
    // Reuse the existing eligibility rules for the items now inside the menu.
    updatePayoutStructureButtons();
    // Focus lands on the first live item; a click outside, or focus leaving
    // the menu and its button, closes it.
    // Looked up inside the timeout: the eligibility pass above may have swapped
    // the items' state, and a stale reference would focus nothing.
    setTimeout(function () { var first = presetMenuItems(m)[0]; if (first && m.classList.contains('open')) first.focus(); }, 0);
    m._close = function (e) { if (m.contains(e.target) || (btn && btn.contains(e.target))) return; closePresetMenu(false); };
    m._fo = function (e) { var to = e.relatedTarget; if (to && (m.contains(to) || (btn && btn.contains(to)))) return; if (to) closePresetMenu(false); };
    setTimeout(function () { document.addEventListener('mousedown', m._close); m.parentNode.addEventListener('focusout', m._fo); }, 0);
}
function closePresetMenu(refocus) {
    var m = document.getElementById('presetMenu');
    if (!m) return;
    m.classList.remove('open');
    var btn = document.querySelector('.pk-preset-morebtn');
    if (btn) btn.setAttribute('aria-expanded', 'false');
    if (m._close) { document.removeEventListener('mousedown', m._close); m._close = null; }
    if (m._fo && m.parentNode) { m.parentNode.removeEventListener('focusout', m._fo); m._fo = null; }
    if (refocus && btn) btn.focus();
}
// Keys inside the ⋯ menu: arrows walk the live items, Home / End jump, Escape
// closes and returns to the button, Tab closes and moves on.
// The items the keyboard can land on: the eligibility pass hides a button
// outright (display:none) when it does not apply, as well as disabling it.
function presetMenuItems(m) {
    return Array.prototype.filter.call(m.querySelectorAll('button'), function (b) { return !b.disabled && b.offsetParent !== null; });
}
function presetMenuKeydown(e) {
    var m = document.getElementById('presetMenu');
    if (!m || !m.classList.contains('open')) return;
    var items = presetMenuItems(m);
    var at = items.indexOf(document.activeElement), to = null;   // -1 while the ⋯ button has focus
    if (e.key === 'ArrowDown') to = (at + 1) % items.length;
    else if (e.key === 'ArrowUp') to = (at - 1 + items.length) % items.length;
    else if (e.key === 'Home') to = 0;
    else if (e.key === 'End') to = items.length - 1;
    else if (e.key === 'Escape') { e.preventDefault(); closePresetMenu(true); return; }
    else if (e.key === 'Tab') { closePresetMenu(false); return; }
    if (to === null || !items.length) return;
    e.preventDefault();
    items[to].focus();
}

async function loadPayoutStructure() {
    var sel = document.getElementById('payoutStructureSelect');
    if (!sel || !sel.value) { pkAlert('Pick a structure first.'); return; }
    var sid = sel.value;
    // Loading rebuilds every pane from the server, so whatever is typed and
    // not yet saved goes with it: say so first.
    if (SETTINGS_DIRTY && !(await pkConfirm('Load the preset and drop your unsaved changes?', { okLabel: 'Load', danger: true }))) return;
    pkProgress('Loading preset…', 'Applying the game setup and rewards to this game.');
    var fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('action', 'load_payout_structure');
    fd.append('session_id', SESSION.id);
    fd.append('structure_id', sid);
    fetch('/checkin_dl.php', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(j) {
            pkProgressDone();
            if (!j.ok) { pkAlert(j.error || 'Error'); return; }
            // Legacy preset (pre-v0.2089): it carried no blind schedule and this
            // game's timer has none either. Warn here, in Setup, rather than
            // letting the host discover 0/0 blinds mid-game.
            if (j.no_blinds) {
                pkAlert('This preset was saved before blind schedules traveled with presets, so it brought no blinds along.\n\n'
                      + 'Open the Blinds tab and pick a schedule before game time — without one the timer has nothing to count through. '
                      + 'Saving the preset again afterwards (Update preset) stores the schedule in it for good.');
            }
            PAYOUTS = j.payouts;
            POOL = j.pool || POOL;
            if (j.session) SESSION = j.session;  // recipe presets update bounty/jackpot too
            CURRENT_STRUCTURE_ID = parseInt(sid);
            refreshPresetState();
            // The preset may have replaced the blind schedule and timer flags:
            // drop the pane's cached grid (it refetches on remount) and
            // retarget the Timer button to whatever the preset restored.
            if (window.pkBlindsEditor) pkBlindsEditor._state = null;
            if (j.session) { loadChipSet(); renderChipRows(); }
            if (j.use_beta !== undefined) {
                USE_BETA_TIMER = j.use_beta ? 1 : 0;
                var tl = document.getElementById('timerLink');
                if (tl) tl.href = (USE_BETA_TIMER ? '/timer.php' : '/timer.php') + '?event_id=' + EVENT_ID;
            }
            // Capture an unsaved game-type choice BEFORE the redraw. Both
            // renderDashboard() and refreshSettingsView() rebuild the pane from
            // the SAVED session, so picking Tournament on a game still saved as
            // cash and then loading a preset used to snap the dropdown back and
            // hide the preset bar you had just used.
            var pendingType = (document.getElementById('cfg_game_type') || {}).value || '';
            // Redraw: the editor re-renders in place (keeps tab + open state),
            // and the dashboard refreshes underneath it.
            renderDashboard();
            refreshSettingsView();
            var typeSel = document.getElementById('cfg_game_type');
            if (typeSel && pendingType && pendingType !== typeSel.value) {
                typeSel.value = pendingType;
                previewGameType(pendingType);
            }
        })
        .catch(function() { pkProgressDone(); pkAlert('Request failed'); });
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
    openModal('saveStructModal', '#ssName');
}

function closeSaveStruct() {
    closeModal('saveStructModal');
}

function confirmSaveStruct() {
    var name = (document.getElementById('ssName').value || '').trim();
    if (!name) { document.getElementById('ssName').focus(); return; }
    var scopeVal = document.getElementById('ssScope').value || 'p';
    var is_global = scopeVal === 'g' ? 1 : 0;
    var league_id = scopeVal.charAt(0) === 'l' ? parseInt(scopeVal.slice(1)) : 0;
    closeSaveStruct();

    var fd = buildPresetFormData();
    fd.append('name', name);
    if (is_global) fd.append('is_global', '1');
    if (league_id) fd.append('league_id', league_id);
    fetch('/checkin_dl.php', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(j) {
            if (!j.ok) { pkAlert(j.error || 'Error'); return; }
            CURRENT_STRUCTURE_ID = parseInt(j.structure_id);
            loadPayoutStructures();
            refreshPresetState();
        });
}

// Everything a preset stores, gathered from the live editor. Shared by
// "Save As…" (new row) and "Update preset" (overwrite the origin), so the two
// can never drift into capturing different things.
function buildPresetFormData() {
    var fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('action', 'save_payout_structure');
    // Session-level reward recipe (bounty / jackpot entry) rides with the preset.
    fd.append('bounty_amount', Math.max(0, Math.round(parseFloat((document.getElementById('cfg_bounty') || {}).value || 0))) * 100);
    fd.append('bounty_points', parseInt((document.getElementById('cfg_bounty_points') || {}).value || 0));
    fd.append('jackpot_amount', Math.max(0, Math.round(parseFloat((document.getElementById('cfg_jackpot') || {}).value || 0))) * 100);
    // Game-tab config rides too: a preset restores the whole editor.
    fd.append('gc_buyin_amount', Math.max(0, Math.round(parseFloat((document.getElementById('cfg_buyin') || {}).value || 0))) * 100);
    fd.append('gc_rebuy_amount', Math.max(0, Math.round(parseFloat((document.getElementById('cfg_rebuy') || {}).value || 0))) * 100);
    fd.append('gc_addon_amount', Math.max(0, Math.round(parseFloat((document.getElementById('cfg_addon') || {}).value || 0))) * 100);
    fd.append('gc_starting_chips', parseInt((document.getElementById('cfg_chips') || {}).value || 0));
    fd.append('gc_addon_chips', parseInt((document.getElementById('cfg_addon_chips') || {}).value || 0));
    fd.append('gc_rebuy_allowed', (document.getElementById('cfg_rebuy_allowed') || {}).checked ? 1 : 0);
    fd.append('gc_max_rebuys', parseInt((document.getElementById('cfg_max_rebuys') || {}).value || 0));
    fd.append('gc_rebuys_included', Math.max(0, parseInt((document.getElementById('cfg_rebuys_included') || {}).value || 0)));
    fd.append('gc_rebuy_cutoff_level', Math.max(0, parseInt((document.getElementById('cfg_rebuy_cutoff') || {}).value || 0)));
    fd.append('gc_addons_included', Math.max(0, parseInt((document.getElementById('cfg_addons_included') || {}).value || 0)));
    fd.append('gc_addons_share_rebuys', (document.getElementById('cfg_free_stack') || {}).value === '1' ? 1 : 0);
    fd.append('gc_addon_allowed', (document.getElementById('cfg_addon_allowed') || {}).checked ? 1 : 0);
    fd.append('gc_num_tables', parseInt((document.getElementById('cfg_tables') || {}).value || 1));
    fd.append('gc_seats_per_table', parseInt((document.getElementById('cfg_seats_per_table') || {}).value || 8));
    fd.append('gc_auto_assign_tables', (document.getElementById('cfg_auto_assign') || {}).checked ? 1 : 0);
    fd.append('gc_bounty_optional', svModeVal('cfg_bounty_mode', '0') === '1' ? 1 : 0);
    fd.append('gc_jackpot_optional', svModeVal('cfg_jackpot_mode', '1') === '1' ? 1 : 0);
    // Blind schedule + timer settings ride too. The server snapshots them from
    // this session (session_id); when the Blinds pane has a grid loaded, send
    // it explicitly so unsaved edits are captured — what you see is what saves.
    fd.append('session_id', SESSION.id);
    if (window.pkBlindsEditor && pkBlindsEditor._state && pkBlindsEditor._state.eventId === EVENT_ID && pkBlindsEditor._state.levels.length) {
        fd.append('blind_levels', JSON.stringify(pkBlindsEditor._state.levels));
    }
    // What-you-see-is-what-you-save, like the blind grid above: the editor's
    // current chip set, not the last one written to the session.
    fd.append('chip_set', JSON.stringify(CHIP_SET.filter(function (c) { return (+c.v || 0) > 0; })));
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
    return fd;
}

// Write the editor back over the preset this game came from. Only offered when
// the bar says MODIFIED and the caller may write to that preset.
async function updatePayoutStructure() {
    if (!PRESET_STATE || !PRESET_STATE.id) return;
    if (!(await pkConfirm('Update "' + PRESET_STATE.name + '" with this game\'s current setup?\n\nEvery future game loading this preset gets these settings. Games already set up from it are not touched.',
                          { okLabel: 'Update preset' }))) return;
    var fd = buildPresetFormData();
    fd.append('structure_id', PRESET_STATE.id);
    fetch('/checkin_dl.php', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(j) {
            if (!j.ok) { pkAlert(j.error || 'Error'); return; }
            loadPayoutStructures();
            refreshPresetState();
        })
        .catch(function() { pkAlert('Request failed'); });
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
            refreshPresetState();
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
    if (player && !buyingIn) {
        // Un-ticking clears the buy-in AND the seat; a slip here cost a real game
        // two buy-ins once, so it asks.
        pkConfirm('Clear <b>' + escHtml(player.display_name) + '</b>\'s buy-in? Their seat is freed.', { okLabel: 'Clear buy-in', danger: true })
            .then(function(ok) { if (ok) doPost(0); else refreshUI(); });
        return;
    }
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

function toggleBounty(pid) {
    postAction('toggle_bounty', { player_id: pid }, function(j) {
        updatePlayer(j.player);
        POOL = j.pool;
        refreshUI();
    });
}

function updateRebuys(pid, delta) {
    var p = (PLAYERS || []).find(function(x) { return x.id == pid; });
    var cutoff = parseInt(SESSION.rebuy_cutoff_level) || 0;
    var askPastCutoff = function(level) {
        return pkConfirm('Rebuys closed after level ' + cutoff + '; the clock is on level ' + level + '. Record one for <b>' + escHtml(p ? p.display_name : 'this player') + '</b> anyway?', { okLabel: 'Record anyway', danger: true });
    };
    var doPost = function(force) {
        var data = { player_id: pid, delta: delta };
        if (force) data.force = 1;
        postAction('update_rebuys', data, function(j) {
            // Re-entry changes more than one row (eliminator's banked bounty,
            // recomputed payouts, possibly a reopened game) — full resync.
            if (j.reentered || j.reopened) { loadSession(); return; }
            updatePlayer(j.player);
            POOL = j.pool;
            refreshUI();
        }, function(j) {
            // The game has closed rebuys and the console had not seen the clock
            // get there yet: ask, then record on purpose.
            if (j.closed && !force) {
                TIMER_LEVEL = parseInt(j.level) || TIMER_LEVEL;
                askPastCutoff(j.level).then(function(ok) { if (ok) doPost(true); else refreshUI(); });
                return;
            }
            pkAlert(j.error || 'Error');
        });
    };
    var start = function() {
        if (delta > 0 && rebuysClosed()) {
            askPastCutoff(TIMER_LEVEL).then(function(ok) { if (ok) doPost(true); });
            return;
        }
        doPost(false);
    };
    if (p && delta > 0 && parseInt(p.eliminated)) {
        var bountyNote = '';
        if (parseInt(SESSION.bounty_amount) > 0) {
            bountyNote = parseInt(SESSION.bounty_optional)
                ? ' Their old bounty chip stays with whoever collected it — they can buy a new one.'
                : ' Any bounty already collected on them stays collected.';
        }
        pkConfirm('Rebuy and re-enter ' + escHtml(p.display_name) + '? They come back into the game with a fresh stack.' + bountyNote, { okLabel: 'Re-enter' })
            .then(function(ok) { if (ok) start(); });
        return;
    }
    start();
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

// ── The break add-on, in bulk ──
// Which of these players can take an add-on now: still in and without one
// (one per player, so a repeat never doubles up), already holding one, or not
// in the game at all.
function addonEligible(ids) {
    var r = { eligible: [], taken: [], out: [] };
    ids.forEach(function(id) {
        var p = (PLAYERS || []).find(function(x) { return parseInt(x.id) === parseInt(id); });
        if (!p) return;
        var playing = parseInt(p.bought_in) && !parseInt(p.eliminated) && p.approval_status !== 'pending';
        if (!playing) r.out.push(p);
        else if ((parseInt(p.addons) || 0) > 0) r.taken.push(p);
        else r.eligible.push(p);
    });
    return r;
}
// scope 'selected' = the bulk bar's ticked rows (desktop); 'playing' = everyone
// still in (the phone's one button). Asks with the count and who is skipped.
async function bulkAddons(scope) {
    var ids = scope === 'selected'
        ? Array.from(document.querySelectorAll('.pk-player-cb:checked')).map(function(cb) { return parseInt(cb.value); })
        : (PLAYERS || []).map(function(p) { return parseInt(p.id); });
    if (!ids.length) return;
    var e = addonEligible(ids);
    var n = e.eligible.length;
    if (!n) {
        pkAlert(e.taken.length ? 'Everyone ' + (scope === 'selected' ? 'selected' : 'still in') + ' already has an add-on.' : 'Nobody ' + (scope === 'selected' ? 'selected' : '') + ' is in the game.');
        return;
    }
    var msg = 'Record an add-on for <b>' + n + '</b> player' + (n === 1 ? '' : 's') + (scope === 'playing' ? ' still in' : '') + '?';
    var notes = [];
    if (e.taken.length) notes.push(e.taken.length + ' already ' + (e.taken.length === 1 ? 'has' : 'have') + ' one and ' + (e.taken.length === 1 ? 'is' : 'are') + ' skipped');
    if (scope === 'selected' && e.out.length) notes.push(e.out.length + ' not in the game, skipped');
    if (notes.length) msg += '<br><br>' + notes.join('; ') + '.';
    msg += '<br><br>' + addonCostLine(e.eligible, formatMoney);
    if (!(await pkConfirm(msg, { title: 'Add-on', okLabel: 'Record' }))) return;
    postAction('bulk_addons', { session_id: SESSION.id, player_ids: e.eligible.map(function(p) { return p.id; }).join(',') }, function(j) {
        loadSession();
        walkinToast('Add-on recorded for ' + j.recorded + ' player' + (j.recorded === 1 ? '' : 's'));
    });
}
// The phone has no bulk bar, so the list leads with one button when there is
// anyone to give an add-on to. Hidden on wider screens (CSS), where the bulk
// bar's Add-on does the same for the selected rows.
function renderBreakAddonBar() {
    if (!isTourney() || !parseInt(SESSION.addon_allowed)) return '';
    var n = addonEligible((PLAYERS || []).map(function(p) { return p.id; })).eligible.length;
    if (!n) return '';
    return '<div class="pk-break-addon"><button type="button" title="One add-on for each player still in who has none yet" data-act="bulkAddons" data-a1="playing">+ Add-on for everyone still in (' + n + ')</button></div>';
}

function setTable(pid, val) {
    postAction('set_table', { player_id: pid, table_number: val }, function(j) {
        updatePlayer(j.player);
    });
}

// Switch between the list / table / log views. Swaps only the content area so
// the toolbar persists and the segmented-control thumb can slide.
// Move the thumb + .active onto a segment. `which` is a real VIEW_MODE, or
// 'settings' for the pseudo-segment that opens the overlay.
function markViewSegActive(which) {
    // Setup lives outside the strip, so when it's the active view no segment is
    // active and the thumb hides — otherwise two things would look selected.
    var setupBtn = document.getElementById('setupBtn');
    if (setupBtn) setupBtn.classList.toggle('active', which === 'settings');
    var seg = document.getElementById('viewSeg');
    if (!seg) return;
    seg.classList.toggle('thumb-off', which === 'settings');
    var btns = seg.querySelectorAll('button');
    for (var i = 0; i < btns.length; i++) {
        btns[i].classList.toggle('active', btns[i].getAttribute('data-view') === which);
    }
    if (which !== 'settings') positionSegThumb('viewSeg', true);
}

function setViewMode(mode, force) {
    // Entering Settings from a segment click goes through openSettings so the
    // structure/ticket selects get populated; openSettings calls back with
    // force=true once its state is set.
    if (mode === 'settings' && !force) { openSettings('game'); return; }
    // Cash games have no payout structure — the segment isn't rendered, but a
    // stale handler or a game-type change shouldn't be able to strand the user.
    if ((mode === 'payouts' || mode === 'chop') && isCash()) mode = 'list';
    if (mode !== 'settings' && VIEW_MODE === 'settings' && SETTINGS_OPEN) {
        // Leaving Settings by clicking another segment routes through the
        // discard confirm rather than silently dropping unsaved edits.
        VIEW_MODE_PREV = mode;
        closeSettings();
        return;
    }
    if (mode === VIEW_MODE && !force) return;
    var fromMode = VIEW_MODE;
    VIEW_MODE = mode;
    document.body.classList.toggle('view-payouts', mode === 'payouts');
    var vc = document.getElementById('viewContent');
    if (vc) {
        vc.innerHTML = renderViewContent();
        slideViewIn(vc, segDirection(fromMode, mode));
    }
    markViewSegActive(mode);
    // The unconfigured prompt hides inside the editor and comes back on exit.
    var cta = document.getElementById('setupCta');
    if (cta) cta.innerHTML = renderSetupCta();
    // Freshly-rendered controls start with an unpositioned thumb. No animation:
    // these are brand-new elements, not ones that moved. Balance and Add Table
    // are rendered inside the filter bar, so they need no toggling here.
    if (filterApplies(mode)) positionSegThumb('filterSeg', false);
    if (mode === 'settings') { positionSegThumb('settingsSeg', false); mountBlindsPane(); }
    syncDisplayHome();
    if (mode === 'log') { renderLog(); fetchLog(); }
    else if (mode !== 'payouts') { updateBulkBar(); }
}

// segTravelDirection() / slideViewIn() / positionSegThumb() / positionAllSegThumbs()
// live in pk-seg.js, loaded for every page by _footer.php. Only the page's own
// rule about where Setup sits belongs here.
function segDirection(fromMode, toMode) {
    // Setup isn't a segment, but it leads the toolbar, so treat it as living
    // before the first button — enter from the left, leave to the right.
    if (toMode === 'settings') return -1;
    if (fromMode === 'settings') return 1;
    return segTravelDirection('viewSeg', 'data-view', fromMode, toMode);
}

// Views that actually read FILTER. Keep in step with renderPlayerRows(),
// renderMobileCards() and renderTableView() if a new view starts filtering.
function filterApplies(mode) {
    return mode === 'list' || mode === 'table';
}

function movePlayer(pid, newTable) {
    if (!newTable) return;
    if (newTable === 'unassign') {
        // Pull them off the table (frees the seat); they drop to the Unassigned group.
        var p = PLAYERS.find(function(x) { return parseInt(x.id) === pid; });
        pkConfirm('Take <b>' + escHtml(p ? p.display_name : 'this player') + '</b> off the table? Their seat is freed and they move to Unassigned.', { okLabel: 'Take off the table' }).then(function(ok) {
            if (!ok) { refreshUI(); return; }   // the select goes back to Move…
            postAction('set_table', { player_id: pid, table_number: '' }, function(j) {
                loadSession();
            });
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
    if (numTables <= 1) {
        pkAlert('Balancing moves players between tables so every table has about the same number. '
            + 'This game has one table, so there is nothing to balance. When the room outgrows it, '
            + '<b>Add Table</b> here or set the number of tables in Setup, and Balance will spread the players out.',
            { title: 'One table, nothing to balance' });
        return;
    }
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
    html += '<p style="margin:0 0 .75rem;color:#64748b;font-size:.85rem">Pick who has the dealer <strong>Button</strong> at each table. The button, small blind and big blind stay where they are; anyone else may be moved to even the tables out.</p>';
    for (var t = 1; t <= numTables; t++) {
        var players = byTable[t];
        if (players.length === 0) continue;
        html += '<div style="margin-bottom:.75rem">';
        html += '<label for="balance_btn_t' + t + '" style="font-weight:700;font-size:.85rem;display:block;margin-bottom:.25rem">Table ' + t + ' — Button:</label>';
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
    // The shared overlay class, so it looks like every other dialog here and
    // the help tour steps aside for it (help-bubble.js watches that class).
    var overlay = document.createElement('div');
    overlay.id = 'balanceModal';
    overlay.className = 'pk-modal-overlay open';
    overlay.innerHTML = '<div role="dialog" aria-modal="true" aria-labelledby="balanceTitle" style="background:#fff;border-radius:10px;padding:1.5rem;max-width:400px;width:90%;box-shadow:0 8px 30px rgba(0,0,0,.2)">'
        + '<h3 id="balanceTitle" style="margin:0 0 .75rem;font-size:1rem">Balance Tables</h3>'
        + html
        + '<div style="display:flex;gap:.5rem;margin-top:1rem;justify-content:flex-end">'
        + '<button data-act="closeBalanceModal" style="padding:.4rem 1rem;border:1.5px solid #e2e8f0;border-radius:6px;background:#fff;cursor:pointer;font-size:.85rem">Cancel</button>'
        + '<button data-act="executeBalance" style="padding:.4rem 1rem;border:none;border-radius:6px;background:#2563eb;color:#fff;cursor:pointer;font-weight:600;font-size:.85rem">Balance</button>'
        + '</div></div>';
    document.body.appendChild(overlay);
    openModal('balanceModal', 'select');   // already open; this records the opener and moves focus in
}

// One move per line. The dialog renders HTML, so the "\n" these used to
// join with collapsed into a space and every move ran together.
function movesHtml(moves) {
    var msg = moves.length + (moves.length === 1 ? ' player moved:' : ' players moved:') + '<br>';
    for (var i = 0; i < moves.length; i++) {
        var m = moves[i];
        msg += escHtml(m.display_name) + ': Table ' + (m.old_table || '?') + ' → ' + m.new_table + '<br>';
    }
    return msg;
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
            pkAlert(movesHtml(j.moves), { title: 'Tables balanced' });
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
            pkAlert(movesHtml(j.moves), { title: 'Table ' + tableNum + ' broken up' });
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
           + (numTables > 1 ? '<button class="pk-act-btn danger" data-act="breakUpTable" data-a1="' + t + '" style="font-size:.7rem;flex-shrink:0" title="Break up this table and distribute players to other tables" aria-label="Break up table ' + t + '">Break Up</button>' : '')
           + '</div>';
        h += '<div class="pk-table-card-body">';
        players.sort(function(a, b) { return (parseInt(a.seat_number) || 99) - (parseInt(b.seat_number) || 99); });
        for (var j = 0; j < players.length; j++) {
            var p = players[j];
            var isElim = parseInt(p.eliminated);
            var seatTag = p.seat_number ? '<span style="color:#64748b;font-size:.72rem;font-weight:700;min-width:1.4rem;display:inline-block">' + p.seat_number + '</span> ' : '';
            h += '<div class="pk-tv-player' + (isElim ? ' elim' : '') + '">';
            h += '<span class="pk-tv-name">' + seatTag + escHtml(p.display_name) + '</span>';
            h += '<span class="pk-tv-actions">';
            if (isElim) {
                h += '<button class="pk-act-btn" data-act="uneliminate" data-a1="' + p.id + '" title="Undo eliminate" aria-label="Undo the elimination of ' + escHtml(p.display_name) + '">Undo</button>';
            } else if (parseInt(p.finish_position) === 1) {
                h += '<span title="Winner" aria-label="Winner" style="color:#a16207;font-weight:700">\ud83c\udfc6</span>';
            } else {
                if (!isCash()) {
                    h += '<button class="pk-act-btn" data-act="eliminatePlayer" data-a1="' + p.id + '" title="Eliminate" aria-label="Eliminate ' + escHtml(p.display_name) + '" style="color:#dc2626;font-weight:700">&#10005;</button>';
                }
                h += '<select class="pk-tv-move" aria-label="Move ' + escHtml(p.display_name) + '" data-act-change="movePlayer" data-change-a1="' + p.id + '" data-change-a2="@value">';
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
        if (players.length === 0) h += '<div style="color:#64748b;text-align:center;padding:1rem">No players</div>';
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
            h += '<select class="pk-tv-move" aria-label="Assign ' + escHtml(p.display_name) + ' to a table" data-act-change="movePlayer" data-change-a1="' + p.id + '" data-change-a2="@value">';
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
    openModal('elimModal', '.pk-modal-actions .pk-save');
}

function closeElim() {
    closeModal('elimModal');
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
    openModal('winnerModal', '.pk-save');
}

function closeWinner() {
    closeModal('winnerModal');
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
    openModal('cashAdjustModal', '#cashAdjustAmount');
}

function closeCashAdjust() {
    closeModal('cashAdjustModal');
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
    var p = PLAYERS.find(function(x) { return parseInt(x.id) === pid; });
    pkConfirm('Put <b>' + escHtml(p ? p.display_name : 'this player') + '</b> back in play? Their cash-out is cleared.', { okLabel: 'Put back in play' }).then(function(ok) {
        if (!ok) { refreshUI(); return; }   // the field gets its value back
        postAction('set_cashout', { player_id: pid, cash_out: '' }, function(j) {
            updatePlayer(j.player);
            POOL = j.pool;
            refreshUI();
        });
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

async function bulkAction(action) {
    var selected = Array.from(document.querySelectorAll('.pk-player-cb:checked')).map(function(cb) { return parseInt(cb.value); });
    if (selected.length === 0) return;
    if (action === 'eliminate_player' && !(await pkConfirm('Eliminate ' + selected.length + ' selected player' + (selected.length === 1 ? '' : 's') + '?', { okLabel: 'Eliminate', danger: true }))) return;

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
    if (val.length < 1) { walkinCloseDD(); return; }

    // Exclude users already in the session
    var existing = PLAYERS.map(function(p) { return p.display_name.toLowerCase(); });
    var matches = ALL_USERS.filter(function(u) {
        return u.toLowerCase().indexOf(val) !== -1 && existing.indexOf(u.toLowerCase()) === -1;
    }).slice(0, 6);

    if (matches.length === 0) { walkinCloseDD(); return; }

    dd.innerHTML = matches.map(function(u, i) {
        // Name rides in a data attribute rather than inside the inline handler's
        // JS string: attribute values are HTML-decoded before JS parses them, so
        // an escaped quote would come back and break out of the literal.
        return '<div class="walkin-dropdown-item" role="option" id="wkopt_' + i + '" aria-selected="false" data-u="' + escHtml(u) + '" data-act-mousedown="walkinPick" data-mousedown-a1="@dataU">' + escHtml(u) + '</div>';
    }).join('');
    dd.classList.add('open');
    var inp = document.getElementById('walkinName');
    if (inp) { inp.setAttribute('aria-expanded', 'true'); inp.removeAttribute('aria-activedescendant'); }
    _walkinIdx = -1;
}
// The listbox's open / closed state and the highlighted option are mirrored on
// the combobox for the screen reader.
function walkinCloseDD() {
    var dd = document.getElementById('walkinDropdown');
    if (dd) { dd.classList.remove('open'); dd.innerHTML = ''; }
    var inp = document.getElementById('walkinName');
    if (inp) { inp.setAttribute('aria-expanded', 'false'); inp.removeAttribute('aria-activedescendant'); }
    _walkinIdx = -1;
}
function walkinHighlight(items) {
    var inp = document.getElementById('walkinName');
    items.forEach(function(el, i) {
        var on = i === _walkinIdx;
        el.classList.toggle('active', on);
        el.setAttribute('aria-selected', on ? 'true' : 'false');
        if (on && inp) inp.setAttribute('aria-activedescendant', el.id);
    });
    if (_walkinIdx < 0 && inp) inp.removeAttribute('aria-activedescendant');
}

function walkinPick(name) {
    var input = document.getElementById('walkinName');
    input.value = name;
    walkinCloseDD();
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
        walkinHighlight(items);
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        _walkinIdx = Math.max(_walkinIdx - 1, 0);
        walkinHighlight(items);
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
        e.preventDefault();   // taken here: Setup's Escape must not fire too
        walkinCloseDD();
    }
}

// Close dropdown when clicking outside
document.addEventListener('click', function(e) {
    if (!e.target.closest('.walkin-autocomplete')) {
        var dd = document.getElementById('walkinDropdown');
        if (dd && dd.classList.contains('open')) walkinCloseDD();
    }
});

function addWalkin() {
    var name = document.getElementById('walkinName').value.trim();
    if (!name) { pkAlert('Enter a name'); return; }
    walkinCloseDD();
    postAction('add_walkin', { session_id: SESSION.id, name: name }, function(j) {
        // The endpoint returns the whole roster, so a re-activated player is
        // replaced and a new one appended without the client reasoning about it.
        PLAYERS = j.players;
        POOL = j.pool;
        document.getElementById('walkinName').value = '';
        // A brand-new walk-in has no buy-in yet, so under "Playing" they are
        // filtered straight back out and the host sees nothing happen. Adding
        // someone is an explicit request to see them, so the filter yields.
        var added = PLAYERS.filter(function(p) { return parseInt(p.id) === parseInt(j.player_id); })[0];
        var cleared = false;
        if (added && !passesFilter(added)) { FILTER = 'all'; cleared = true; }
        refreshUI();
        if (cleared) {
            // The filter bar lives inside #viewContent and refreshUI() repaints
            // only the rows, so its active segment is updated here. On a view
            // with no filter bar this finds nothing and positionSegThumb() bails.
            document.querySelectorAll('.pk-filter button').forEach(function(btn) {
                btn.classList.toggle('active', btn.getAttribute('data-filter') === 'all');
            });
            positionSegThumb('filterSeg', true);
            walkinToast(added.display_name + ' added — filter cleared to show them');
        }
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
    var p = PLAYERS.find(function(x) { return parseInt(x.id) === pid; });
    if (!(await pkConfirm('Deny <b>' + escHtml(p ? p.display_name : 'this player') + '</b>? They come off the list.', { okLabel: 'Deny', danger: true }))) return;
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
    // The payout rows are read and checked BEFORE anything is posted: a total
    // over 100% used to be caught only after update_config had already saved,
    // which left the game half-updated behind an error.
    var places = [], pcts = [], pts = [], tickets = [], labels = [], pctSum = 0;
    document.querySelectorAll('#payoutRows .row').forEach(function(row) {
        var pctEl = row.querySelector('.payout-pct');
        if (!pctEl) return;
        places.push(pctEl.getAttribute('data-place'));
        pcts.push(pctEl.value);
        pctSum += parseFloat(pctEl.value || 0);
        pts.push((row.querySelector('.payout-pts') || {}).value || 0);
        tickets.push((row.querySelector('.payout-ticket') || {}).value || 0);
        labels.push((row.querySelector('.payout-label') || {}).value || '');
    });
    if (places.length > 0 && document.getElementById('cfg_game_type').value === 'tournament' && pctSum > 100) {
        pkAlert('Payout percentages total ' + pctSum.toFixed(1) + '% — cannot exceed 100%. Nothing was saved.');
        return;
    }
    pkProgress('Saving settings…', 'Saving the game configuration and payout structure.');
    var data = {
        session_id: SESSION.id,
        buyin_amount: Math.max(0, Math.round(parseFloat(document.getElementById('cfg_buyin').value || 0))) * 100,
        game_type: document.getElementById('cfg_game_type').value,
        num_tables: parseInt(document.getElementById('cfg_tables').value || 1),
        seats_per_table: parseInt(document.getElementById('cfg_seats_per_table').value || 9),
        auto_assign_tables: (document.getElementById('cfg_auto_assign') || {}).checked ? 1 : 0,
    };
    // Only sent when the host can actually change it; the server re-checks the
    // league role regardless and refuses a move it doesn't like.
    var lgSel = document.getElementById('cfg_league_id');
    if (lgSel) data.league_id = parseInt(lgSel.value || 0);
    if (document.getElementById('cfg_game_type').value === 'tournament') {
        data.rebuy_amount = Math.max(0, Math.round(parseFloat((document.getElementById('cfg_rebuy') || {}).value || 0))) * 100;
        data.addon_amount = Math.max(0, Math.round(parseFloat((document.getElementById('cfg_addon') || {}).value || 0))) * 100;
        data.starting_chips = parseInt((document.getElementById('cfg_chips') || {}).value || 5000);
        data.addon_chips = parseInt((document.getElementById('cfg_addon_chips') || {}).value || data.starting_chips);
        data.rebuy_allowed = (document.getElementById('cfg_rebuy_allowed') || {}).checked ? 1 : 0;
        data.max_rebuys = parseInt((document.getElementById('cfg_max_rebuys') || {}).value || 0);
        data.rebuys_included = Math.max(0, parseInt((document.getElementById('cfg_rebuys_included') || {}).value || 0));
        data.rebuy_cutoff_level = Math.max(0, parseInt((document.getElementById('cfg_rebuy_cutoff') || {}).value || 0));
        data.addon_allowed = (document.getElementById('cfg_addon_allowed') || {}).checked ? 1 : 0;
        data.addons_included = Math.max(0, parseInt((document.getElementById('cfg_addons_included') || {}).value || 0));
        data.addons_share_rebuys = ((document.getElementById('cfg_free_stack') || {}).value === '1' && data.rebuys_included > 0) ? 1 : 0;
        data.bounty_amount = Math.max(0, Math.round(parseFloat((document.getElementById('cfg_bounty') || {}).value || 0))) * 100;
        data.bounty_points = parseInt((document.getElementById('cfg_bounty_points') || {}).value || 0);
        data.ticket_target_event_id = parseInt((document.getElementById('cfg_ticket_target') || {}).value || 0);
        data.jackpot_amount = Math.max(0, Math.round(parseFloat((document.getElementById('cfg_jackpot') || {}).value || 0))) * 100;
        data.bounty_optional = svModeVal('cfg_bounty_mode', '0') === '1' ? 1 : 0;
        data.jackpot_optional = svModeVal('cfg_jackpot_mode', '1') === '1' ? 1 : 0;
        // Sent as JSON, and only for a tournament — a cash game has no legend.
        data.chip_set = JSON.stringify(CHIP_SET.filter(function (c) { return (+c.v || 0) > 0; }));
    } else {
        data.rebuy_amount = data.buyin_amount;
        data.addon_amount = 0;
        data.starting_chips = 0;
        data.addon_chips = 0;
        data.rebuy_allowed = 1;
        data.max_rebuys = 0;
        data.rebuys_included = 0;
        data.rebuy_cutoff_level = 0;
        data.addon_allowed = 0;
        data.addons_included = 0;
        data.addons_share_rebuys = 0;
    }
    postAction('update_config', data, function(j) {
        SESSION = j.session;
        POOL = j.pool;
        PAYOUTS = j.payouts || PAYOUTS;
        // The league may have moved: the jackpot fund and the saved payout
        // presets are both scoped to it, so pick up the server's view rather
        // than keep showing the old league's figures.
        if (j.jackpots) JACKPOTS = j.jackpots;
        if (typeof j.league_id !== 'undefined') EVENT_LEAGUE_ID = parseInt(j.league_id) || 0;
        if (j.players) PLAYERS = j.players;
        // Save payouts too (tournament only) — all four reward dimensions ride
        // in parallel arrays keyed by row order.
        if (places.length > 0 && SESSION.game_type === 'tournament') {
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
                    saveBlindsWithSettings();
                })
                .catch(function() { pkProgressDone(); pkAlert('Request failed'); });
        } else {
            saveBlindsWithSettings();
        }
    }, function(errJ) {
        // update_config failed: drop the overlay so the error is readable.
        pkProgressDone();
        pkAlert(errJ.error || 'Error');
    });
}

// The Blinds pane is part of this editor, so "Save game" commits it too. It
// used not to: a host could edit the schedule, press Save, be told "Saved ✓"
// and lose every blind change, because the grid had its own separate button.
// A blinds failure must not be swallowed — the rest of the save has already
// landed, so say what did not.
function saveBlindsWithSettings() {
    var ed = window.pkBlindsEditor;
    if (!ed || typeof ed.save !== 'function' || isCash()
        || !ed._state || ed._state.eventId !== EVENT_ID || !ed._state.dirty) {
        settingsSaved();
        return;
    }
    ed.save().then(function (j) {
        if (j && !j.ok) {
            pkProgressDone();
            pkAlert('The game was saved, but the blind schedule was not: ' + (j.error || 'unknown error'));
            return;
        }
        settingsSaved();
    });
}

// Post-save: STAY in the editor. Saving is a checkpoint, not an exit — setting
// a game up is several passes over several tabs, and being thrown back to the
// player list after each one meant re-opening Setup and re-finding the tab.
// The editor is left standing (and clean), so the confirmation has to be
// visible: the button says so for a moment. Closing Setup re-renders the view
// behind it, so nothing stale survives the trip out.
function settingsSaved() {
    SETTINGS_DIRTY = false;
    // The amber dot meant "unsaved"; it must go out with the save, and the
    // green tick takes its place for a moment.
    var dot = document.getElementById('svDirty'); if (dot) dot.style.display = 'none';
    var tick = document.getElementById('svSaved');
    if (tick) { tick.style.display = ''; setTimeout(function () { var t2 = document.getElementById('svSaved'); if (t2) t2.style.display = 'none'; }, 1600); }
    // A save is exactly what makes a game diverge from (or fall back in line
    // with) the preset it came from, so recompute the provenance line.
    refreshPresetState();
    // Game type may have changed with this save, which decides whether the
    // tournament-only tabs are live — they gate on the SAVED session.
    previewGameType(document.getElementById('cfg_game_type')
                    ? document.getElementById('cfg_game_type').value
                    : (SESSION.game_type || 'tournament'));
    pkProgressDone();
    var btn = document.querySelector('.pk-sv-save');
    if (btn && !btn._flashing) {
        btn._flashing = true;
        var was = btn.textContent;
        btn.textContent = 'Saved ✓';
        setTimeout(function () { btn.textContent = was; btn._flashing = false; }, 1600);
    }
}

function openNotes(pid) {
    notesPlayerId = pid;
    var p = PLAYERS.find(function(x) { return parseInt(x.id) === pid; });
    document.getElementById('notesText').value = (p && p.notes) ? p.notes : '';
    openModal('notesModal', '#notesText');
}

function closeNotes() {
    closeModal('notesModal');
    notesPlayerId = null;
}

function openHelp() {
    // The text is written for both game types; show the half that applies.
    var cash = isCash();
    document.querySelectorAll('#helpModal .help-tourney').forEach(function (el) { el.style.display = cash ? 'none' : ''; });
    document.querySelectorAll('#helpModal .help-cash').forEach(function (el) { el.style.display = cash ? '' : 'none'; });
    var m = document.getElementById('helpModal');
    m.querySelector('.pk-help-content').scrollTop = 0;
    openModal('helpModal', '.pk-help-content');   // reading starts at the top
}

function closeHelp() {
    closeModal('helpModal');
}

// ─── Per-player ledger (money history + corrections) ───
var LEDGER_PID = null;

function ledgerBtn(pid, nm) {
    return '<button type="button" class="pk-ledger-btn" title="Money history &amp; corrections" aria-label="Money history' + (nm ? ' for ' + nm : '') + '" data-act="openLedger" data-a1="' + pid + '">&#128210;</button>';
}

// Small tap-friendly help marker; data-tip is shown on hover or focus/tap.
// The Rebuys / Add-ons heading's ? when the buy-in includes some: the host
// reading a player's count needs to know which of them cost anything.
function rebuysIncludedTip() {
    var n = parseInt(SESSION.rebuys_included) || 0;
    var c = parseInt(SESSION.rebuy_cutoff_level) || 0;
    var parts = [];
    if (n) parts.push('Each player\'s first ' + n + ' rebuy' + (n > 1 ? 's are' : ' is') + ' free with the buy-in; the rest are ' + formatMoney(parseInt(SESSION.rebuy_amount)) + ' each.');
    if (c) parts.push(rebuysClosed() ? 'Rebuys closed after level ' + c + '; the clock is on level ' + TIMER_LEVEL + '.' : 'Rebuys close after level ' + c + '.');
    return parts.length ? tip(parts.join(' ')) : '';
}
// The rebuy cutoff, as the console last saw the clock (TIMER_LEVEL rides on
// every get_session poll). The + still works when closed: the host confirms.
function rebuysClosed() {
    var c = parseInt(SESSION.rebuy_cutoff_level) || 0;
    return c > 0 && TIMER_LEVEL > c;
}
function rebuyPlusAttrs() {
    return rebuysClosed() ? ' class="pk-rebuy-closed" title="Rebuys closed after level ' + parseInt(SESSION.rebuy_cutoff_level) + ' (the clock is on level ' + TIMER_LEVEL + ')"' : '';
}
function toggleAddonShare(val) {
    var one = String(val) === '1';
    var w = document.getElementById('cfg_addons_included_wrap'), note = document.getElementById('addonFreeNote');
    if (w) w.style.display = one ? 'none' : '';
    if (note) note.style.display = one ? '' : 'none';
    markSettingsDirty();
}
// The one-stack choice only means something once the buy-in covers a rebuy.
function syncFreeStackRow() {
    var n = parseInt((document.getElementById('cfg_rebuys_included') || {}).value) || 0;
    var row = document.getElementById('freeStackRow');
    if (row) row.style.display = n > 0 ? '' : 'none';
    var s = document.getElementById('cfg_free_stack');
    if (n <= 0 && s && s.value === '1') { s.value = '0'; toggleAddonShare('0'); }
}
var escHtmlAny = function (s) { return escHtml(s); };
// Rebuy OR add-on: a player's next add-on is free while the buy-in's allowance
// lasts (pk_addon_allowance on the server; rebuys use it first).
function addonFreeFor(p) {
    var shared = parseInt(SESSION.addons_share_rebuys) === 1;
    var n = shared ? Math.max(0, (parseInt(SESSION.rebuys_included) || 0) - (parseInt(p.rebuys) || 0)) : (parseInt(SESSION.addons_included) || 0);
    return (parseInt(p.addons) || 0) < n;
}
// The confirm's money line for a set of add-ons about to be recorded.
function addonCostLine(list, money) {
    var free = list.filter(addonFreeFor), paid = list.filter(function (p) { return !addonFreeFor(p); });
    var each = money(parseInt(SESSION.addon_amount)) + ' each';
    if (!paid.length) return 'All free with the buy-in; nothing to collect.';
    if (!free.length) return each + '.';
    var shown = paid.slice(0, 3).map(function (p) { return escHtmlAny(p.display_name); });
    var names = paid.length > 3 ? shown.join(', ') + ' and ' + (paid.length - 3) + ' more' : (shown.length > 1 ? shown.slice(0, -1).join(', ') + ' and ' + shown[shown.length - 1] : shown[0]);
    return 'Free for ' + free.length + ' (came with the buy-in). ' + names + ' used their free rebuy and pay ' + each + '.';
}
function addonsIncludedTip() {
    if (parseInt(SESSION.addons_share_rebuys) === 1 && parseInt(SESSION.rebuys_included)) {
        return tip('One free stack per player, a rebuy or the add-on: the add-on is free unless they used their free rebuy, then it is ' + formatMoney(parseInt(SESSION.addon_amount)) + '.');
    }
    var n = parseInt(SESSION.addons_included) || 0;
    if (!n) return '';
    return tip('Each player\'s first ' + n + ' add-on' + (n > 1 ? 's are' : ' is') + ' free with the buy-in; the rest are ' + formatMoney(parseInt(SESSION.addon_amount)) + ' each.');
}

function tip(text) {
    return '<span class="pk-tip" tabindex="0" role="note" aria-label="' + escHtml(text) + '" data-tip="' + escHtml(text) + '" data-stop="1">?</span>';
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
    openModal('ledgerModal');
    fetch('/checkin_dl.php?action=get_ledger&player_id=' + pid)
        .then(function(r){ return r.json(); })
        .then(function(j){ if (j.ok) renderLedger(j.ledger); else pkAlert(j.error || 'Could not load ledger'); })
        .catch(function(e){ console.error(e); });
}

function closeLedger() {
    LEDGER_PID = null;
    closeModal('ledgerModal');
}

// ─── Cash box reconciliation (cash games) ───
function openCashBox() {
    document.getElementById('cbTips').value = (parseInt(SESSION.tips) || 0) ? ((parseInt(SESSION.tips)) / 100) : '';
    document.getElementById('cbCounted').value = (SESSION.cash_counted !== null && SESSION.cash_counted !== undefined && SESSION.cash_counted !== '') ? (parseInt(SESSION.cash_counted) / 100) : '';
    cashBoxRecompute();
    openModal('cashBoxModal', '#cbTips');
}

function closeCashBox() {
    closeModal('cashBoxModal');
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
                h += '<button class="pk-ledger-edit" data-act="editLedgerEntry" data-a1="' + e.id + '" data-a2="' + amt + '">Edit</button>';
            }
            if (ledgerRowClearable(e, amt)) {
                h += '<button class="pk-ledger-clear" data-act="voidLedgerEntry" data-a1="' + e.id + '">Clear</button>';
            }
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
            // These two actions now fire from the Log view as well, where the
            // ledger modal is closed and j.ledger belongs to whichever player
            // the log row named — #ledgerList is always in the DOM, so repaint
            // it only when it is genuinely open for that same player.
            if (j.ledger && LEDGER_PID && j.player && parseInt(LEDGER_PID) === parseInt(j.player.id)) renderLedger(j.ledger);
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
            // These two actions now fire from the Log view as well, where the
            // ledger modal is closed and j.ledger belongs to whichever player
            // the log row named — #ledgerList is always in the DOM, so repaint
            // it only when it is genuinely open for that same player.
            if (j.ledger && LEDGER_PID && j.player && parseInt(LEDGER_PID) === parseInt(j.player.id)) renderLedger(j.ledger);
            refreshUI();
        });
    });
}

// ─── Activity log ──────────────────────────────────────
function logTagLabel(t) {
    var m = { buyin:'Buy In', unbuyin:'Un-Buy', rebuy:'Rebuy', addon:'Add-on',
              cashin:'Cash In', cashout:'Cash Out', add:'Add', approve:'Approve',
              eliminate:'Out', uneliminate:'Back In', remove:'Remove', void:'Cleared', edit:'Edited',
              bounty:'Bounty', jackpot:'Jackpot',
              ticket_issue:'Ticket', ticket_redeem:'Ticket In', ticket_void:'Ticket Void' };
    return m[t] || t;
}

// The log and the per-player ledger are the SAME table (poker_session_log);
// get_player_ledger() is just this list filtered by player and by these five
// types. Only they carry money, so only they can be cleared or corrected —
// clearing e.g. a bounty row would strike the sentence and leave the money.
// Mirrors PK_LEDGER_TYPES in _poker_helpers.php.
var PK_LEDGER_TYPES = ['buyin', 'cashin', 'rebuy', 'addon', 'cashout'];
function isMoneyLogRow(e) {
    return PK_LEDGER_TYPES.indexOf(e.event_type) >= 0 && parseInt(e.player_id) > 0;
}

// A rebuy/add-on REMOVAL is logged as -amount, and void_ledger_entry reads that
// sign to know which way to correct the count. When the rebuy price is $0 the
// sign is lost (-0 === 0) and clearing would decrement again instead of
// restoring, so the row offers no Clear. Guards both the log and the ledger.
function ledgerRowClearable(e, amt) {
    // A rebuy or add-on row undoes cleanly whatever it carries: the server
    // reads the direction from the sign, and a $0 row (a rebuy that came with
    // the buy-in) from its wording.
    return true;
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
        var voided = parseInt(e.voided) === 1;
        var amt = (e.amount === null || e.amount === undefined) ? null : parseInt(e.amount);
        // Same amount chip as the ledger — the log carried the value in its
        // payload but never showed it, and an editable amount has to be visible.
        var amtHtml = '';
        if (amt !== null && amt !== 0) {
            amtHtml = '<span class="pk-ledger-amt ' + (amt > 0 ? 'pos' : 'neg') + '">' + (amt > 0 ? '+' : '-') + formatMoney(Math.abs(amt)) + '</span>';
        }
        var actions = '';
        if (isMoneyLogRow(e)) {
            if (voided) {
                actions = '<span class="pk-ledger-void-tag">Cleared</span>';
            } else {
                var btns = '';
                if ((e.event_type === 'cashin' || e.event_type === 'cashout') && amt !== null && amt > 0) {
                    btns += '<button class="pk-ledger-edit" data-act="editLedgerEntry" data-a1="' + e.id + '" data-a2="' + amt + '">Edit</button>';
                }
                if (ledgerRowClearable(e, amt)) {
                    btns += '<button class="pk-ledger-clear" data-act="voidLedgerEntry" data-a1="' + e.id + '">Clear</button>';
                }
                if (btns) actions = '<span class="pk-log-actions">' + btns + '</span>';
            }
        }
        h += '<div class="pk-log-row' + (voided ? ' voided' : '') + '">'
           + '<span class="pk-log-time">' + escHtml(fmtLocalTime(e.time_ts, e.time)) + '</span>'
           + '<span class="pk-log-tag t-' + escHtml(e.event_type) + '">' + escHtml(logTagLabel(e.event_type)) + '</span>'
           + amtHtml
           + '<span class="pk-log-text"><b>' + escHtml(e.player_name || '') + '</b> ' + escHtml(e.detail || '') + ' ' + by + '</span>'
           + actions
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

// A repaint replaces every node in the roster, so whatever had focus is gone
// with it: remember it first and land on its successor afterwards. Without
// this every Buy In, rebuy or Eliminate from the keyboard dropped focus to the
// top of the page, and the 10 s poll did the same under a parked cursor.
function focusSnapshot() {
    var ae = document.activeElement;
    if (!ae || ae === document.body || !ae.closest || !ae.closest('#app')) return null;
    return { el: ae, id: ae.id || '', label: ae.getAttribute('aria-label') || '',
             act: ae.getAttribute('data-act') || ae.getAttribute('data-act-change') || '',
             a1: ae.getAttribute('data-a1') || ae.getAttribute('data-change-a1') || '',
             pid: (ae.closest('[data-pid]') || { getAttribute: function () { return ''; } }).getAttribute('data-pid') || '' };
}
function focusRestore(snap) {
    if (!snap || document.contains(snap.el)) return;   // still there: nothing to do
    var t = null;
    if (snap.id) t = document.getElementById(snap.id);
    if (!t && snap.act) t = findSuccessor(snap.el);
    if (!t && snap.label) t = document.querySelector('[aria-label="' + snap.label.replace(/\\/g, '\\\\').replace(/"/g, '\\"') + '"]');
    if (!t && snap.pid) t = document.querySelector('[data-pid="' + snap.pid + '"] button, [data-pid="' + snap.pid + '"] input, [data-pid="' + snap.pid + '"] select');
    if (!t && snap.act) t = document.querySelector('[data-act="' + snap.act + '"], [data-act-change="' + snap.act + '"]');   // the row is gone: the same control on another
    if (!t) t = document.getElementById('walkinName');
    if (t && t.focus) { try { t.focus({ preventScroll: true }); } catch (e) {} }
}

function refreshUI() {
    var fs = focusSnapshot();
    refreshUIInner();
    focusRestore(fs);
}
function refreshUIInner() {
    // The Settings editor holds unsaved form state, so the 10s poll must never
    // repaint it — this is the reason it used to live outside #app entirely.
    // Everything below #viewContent (stats, pool header, cards) still updates.
    // Settings holds unsaved form state and Chop holds typed chip counts, so
    // the 10s poll must not repaint either. Everything outside the content
    // area still updates.
    if (VIEW_MODE === 'settings' || VIEW_MODE === 'chop') {
        refreshUIChrome();
        return;
    }
    if (VIEW_MODE === 'payouts') {
        // Read-only view built entirely from PAYOUTS/POOL/PLAYERS — repaint it
        // wholesale so the ladder and pool track buy-ins as they land.
        var pv = document.getElementById('viewContent');
        if (pv) pv.innerHTML = renderPayoutsView();
    } else if (VIEW_MODE === 'table') {
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
        if (mobileList) mobileList.innerHTML = renderBreakAddonBar() + renderMobileCards();
        // Restore expanded state
        expandedIds.forEach(function(pid) {
            var el = document.getElementById('mexp_' + pid);
            if (el) { el.classList.add('open'); syncMobileMore(pid); }
        });
    }
    refreshUIChrome();
}

// Everything outside the content area: stats, pool header, sidebar cards and
// the mobile summary bar. Split out so the Settings view can keep these live
// without its own form being repainted underneath the host.
function refreshUIChrome() {
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
    // The mobile stand-in for those two cards — it was never refreshed, so its
    // figures drifted from the pool header on every buy-in.
    var inlineSum = document.getElementById('inlineSummary');
    if (inlineSum) inlineSum.innerHTML = renderInlineSummary();
    var cta = document.getElementById('setupCta');
    if (cta) cta.innerHTML = renderSetupCta();
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
        b.setAttribute('role', 'status');
        anchor.insertAdjacentElement('afterend', b);
    }
    b.style.cssText = 'display:flex;align-items:center;gap:.6rem;flex-wrap:wrap;background:#fefce8;border:1px solid #fde68a;border-radius:8px;padding:.55rem .7rem;margin:.6rem 0';
    b.innerHTML = '<span style="flex:1;min-width:0;font-size:.85rem;color:#92400e;font-weight:600">&#9203; ' + pend.length + ' walk-ins awaiting approval</span>'
        + '<button class="pk-act-btn primary" id="approveAllBtn" data-act="approveAllPending">Approve all</button>';
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

// Escape for interpolation into an HTML string. The text-node round-trip only
// escapes & < >, which is safe in a text position but NOT inside a quoted
// attribute — a name containing a double quote could close the attribute and
// add an event handler. Quotes are escaped explicitly so the same helper is
// safe in both contexts; text positions render identically because the parser
// decodes the entities anyway.
// NOTE: still not safe for building a JS string literal inside an attribute
// (e.g. data-act="f" data-a1="'...'") — the parser HTML-decodes before JS parses, so the
// quote comes back. Pass those through a data-* attribute instead.
function escHtml(s) {
    if (!s) return '';
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(s));
    return div.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

// ─── INIT ──────────────────────────────────────────────
loadSession();

// Keep both toolbar sliders aligned if the toolbar reflows on resize.

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
    return !!(ae.closest && ae.closest('#playerBody, #mobileList, .pk-table-grid, #payoutCard, #poolCard, #statsRow, .pk-sv-inline'));
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

// One live region for the page's passing notices: a screen reader hears each
// message as it lands, and everyone else sees the same pill as before. The
// element stays in the tree (faded out when idle) so the announcement fires.
var _toastEl = null, _toastTimer = null;
function walkinToast(msg) {
    if (!_toastEl) {
        _toastEl = document.createElement('div');
        _toastEl.id = 'walkinToast';
        _toastEl.className = 'pk-walkin-toast';
        _toastEl.setAttribute('role', 'status');
        _toastEl.setAttribute('aria-live', 'polite');
        _toastEl.style.cssText = 'position:fixed;bottom:1.25rem;left:50%;transform:translateX(-50%);z-index:99999;'
            + 'background:#1e293b;color:#fff;padding:.6rem 1.1rem;border-radius:999px;font-size:.9rem;font-weight:600;'
            + 'box-shadow:0 6px 24px rgba(0,0,0,.3);cursor:pointer;max-width:90vw;opacity:0;pointer-events:none;transition:opacity .15s';
        _toastEl.onclick = function() { hideToast(); };
        document.body.appendChild(_toastEl);
    }
    _toastEl.textContent = msg;
    _toastEl.style.opacity = '1';
    _toastEl.style.pointerEvents = '';
    if (_toastTimer) clearTimeout(_toastTimer);
    _toastTimer = setTimeout(hideToast, 8000);
}
function hideToast() {
    if (_toastTimer) { clearTimeout(_toastTimer); _toastTimer = null; }
    if (!_toastEl) return;
    _toastEl.style.opacity = '0';
    _toastEl.style.pointerEvents = 'none';
    _toastEl.textContent = '';
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
            var lvl = parseInt(j.timer_level) || 0;
            var levelMoved = lvl !== TIMER_LEVEL && (parseInt(j.session.rebuy_cutoff_level) || 0) > 0;
            TIMER_LEVEL = lvl;
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
            if ((changed || levelMoved) && !rosterEditInProgress()) {
                SESSION = j.session;
                PLAYERS = j.players;
                PAYOUTS = j.payouts;
                refreshUI();
            }
        })
        .catch(function() {});
}, 10000);

// ─── DEAL SPLIT MODAL ────────────────────────────────────
// Chop: the deal-split calculator, rendered as a view beside Payouts rather
// than a modal behind a header button. The calc helpers below are unchanged —
// they address .deal-chips / #dealRows / #dealResult, which this still emits.
function renderChopView() {
    if (isCash()) {
        return '<div class="pk-card"><h3>Chop</h3><div style="color:#64748b;font-size:.85rem">'
             + 'Chopping applies to tournaments — a cash game is settled per player in the List view.</div></div>';
    }
    var remaining = PLAYERS.filter(function(p) { return !parseInt(p.eliminated) && parseInt(p.bought_in); });
    if (remaining.length < 2) {
        return '<div class="pk-card"><h3>Chop</h3><div style="color:#64748b;font-size:.85rem">'
             + 'A chop needs at least 2 players still in. ' + remaining.length + ' currently playing.</div></div>';
    }
    var poolTotal = POOL.pool_total;

    // Build chip entry form
    var h = '<div class="pk-card">';
    h += '<h3>Chop &mdash; Deal Split Calculator</h3>';
    h += '<div style="margin-bottom:1rem">';
    h += '<p style="font-size:.85rem;color:#64748b;margin-bottom:.75rem">Enter each remaining player\'s chip count, then choose a split method.</p>';
    h += '<div style="font-weight:600;margin-bottom:.5rem">Prize Pool: ' + formatMoney(poolTotal) + ' &mdash; ' + remaining.length + ' players remaining</div>';
    if (parseInt(SESSION.bounty_amount) > 0 || PAYOUTS.some(function(p) { return parseInt(p.ticket_cents) > 0; })) {
        h += '<div style="font-size:.75rem;color:#64748b;margin-bottom:.5rem">Chop math uses the cash prize pool only — bounties and ticket prizes are excluded.</div>';
    }
    h += '</div>';

    h += '<table style="width:100%;border-collapse:collapse;font-size:.9rem;margin-bottom:1rem">';
    h += '<thead><tr style="border-bottom:2px solid #e2e8f0"><th scope="col" style="text-align:left;padding:.4rem">Player</th><th scope="col" style="text-align:right;padding:.4rem;width:120px">Chips</th><th scope="col" style="text-align:right;padding:.4rem;width:100px">Payout</th></tr></thead>';
    h += '<tbody id="dealRows">';
    for (var i = 0; i < remaining.length; i++) {
        h += '<tr data-player-id="' + remaining[i].id + '" style="border-bottom:1px solid #f1f5f9">';
        h += '<td style="padding:.4rem">' + escHtml(remaining[i].display_name) + '</td>';
        h += '<td style="padding:.4rem"><input type="number" class="deal-chips" aria-label="Chips for ' + escHtml(remaining[i].display_name) + '" min="0" step="1" value="" placeholder="0" style="width:100%;padding:.3rem .5rem;border:1.5px solid #e2e8f0;border-radius:4px;text-align:right;font-size:.9rem" data-act-input="recalcDeal"></td>';
        h += '<td style="padding:.4rem;text-align:right;font-weight:600" class="deal-payout">-</td>';
        h += '</tr>';
    }
    h += '</tbody></table>';

    h += '<div style="display:flex;gap:.5rem;margin-bottom:1rem;flex-wrap:wrap">';
    h += '<button class="btn btn-primary" data-act="calcDeal" data-a1="icm" id="btnICM">ICM Split</button>';
    h += '<button class="btn btn-outline" data-act="calcDeal" data-a1="standard">Standard Split</button>';
    h += '<button class="btn btn-outline" data-act="calcDeal" data-a1="chip_chop">Chip Chop</button>';
    h += '</div>';

    h += '<div id="dealResult" style="display:none;background:#f0fdf4;border:1.5px solid #86efac;border-radius:8px;padding:1rem;margin-bottom:1rem"></div>';
    h += '</div>';
    return h;
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
        resultHtml += '<div style="display:flex;justify-content:space-between;padding:.2rem 0"><span>' + escHtml(name) + '</span><span style="font-weight:600;color:#15803d">' + formatMoney(amt) + '</span></div>';
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

<!-- The deal-split calculator is the "Chop" view now (renderChopView), not a modal. -->

</body>
</html>
