<?php
/**
 * Table Manager — phone-first tournament director console.
 *
 * The check-in console squeezed through a CSS breakpoint is not a phone tool:
 * eliminate hides behind an expand, there is no timer control at all, no wake
 * lock, and its CSRF token is baked once at render so a page left open past
 * the session GC goes silently read-only. This page is the table-side answer:
 * a sticky clock/blinds header with the timer command row behind a tap, a
 * one-thumb roster (Buy In / KO / Re-enter as state-matched primaries), a
 * bottom action sheet for everything else, and a liveness layer that refreshes
 * its CSRF token from every timer poll (timer_dl.php's pattern) so it never
 * decays. Design doc: TABLE_MANAGER.md.
 *
 * Deliberately standalone: no _nav.php (the header IS the chrome) and no
 * _footer.php — the footer injects the site manifest (start_url '/', which
 * would make Add to Home Screen launch the home page instead of this one)
 * and floats the push-prompt card exactly where the action sheet lives.
 * Shared assets (pk-seg, pk-dialogs, pk-dispatch) load explicitly instead,
 * the walkin_display.php pattern.
 *
 * Both game types are supported. A tournament gets the clock, KO/re-entry and
 * the payout ladder; a cash game gets the money-on-table readout, cash in /
 * cash out / bust and the cash-box reconciliation sheet. Session CREATION still
 * 302s to the console, and so does Finish game — it mints entry tickets and its
 * reversal can be blocked, a decision for the payout ladder, not a thumb
 * reaching for KO.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/_poker_helpers.php';

$current = require_login();
$db      = get_db();
$isAdmin = ($current['role'] ?? '') === 'admin';

$event_id = (int)($_GET['event_id'] ?? 0);
if ($event_id <= 0) { http_response_code(400); exit('Missing event_id'); }

$evq = $db->prepare('SELECT id, title FROM events WHERE id = ?');
$evq->execute([$event_id]);
$event = $evq->fetch();
if (!$event) { http_response_code(404); exit('Event not found'); }

if (!can_manage_event($db, $event_id, (int)$current['id'], $isAdmin)) {
    http_response_code(403); exit('Access denied');
}

// Read-only bootstrap. No session yet → the console owns creation; send the
// host there rather than half-rendering. Both game types render from here.
$sq = $db->prepare('SELECT * FROM poker_sessions WHERE event_id = ?');
$sq->execute([$event_id]);
$session = $sq->fetch();
if (!$session) {
    header('Location: /checkin.php?event_id=' . $event_id);
    exit;
}
$is_cash = ($session['game_type'] ?? '') === 'cash';

// Timer row may legitimately not exist yet. Do NOT provision it here:
// pk_ensure_timer_row() inserts without a blind preset, and timer.php's own
// provisioning (which seeds the site default schedule) must stay the one
// creator. Absent row = header renders "Timer not set up" + a link.
$tq = $db->prepare('SELECT id FROM timer_state WHERE session_id = ?');
$tq->execute([(int)$session['id']]);
$has_timer = (bool)$tq->fetchColumn();

$csrf      = csrf_token();
$site_name = get_setting('site_name', 'Game Night');
$title     = (string)$event['title'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <!-- Chrome-free from the home screen; iPhone Safari has no Fullscreen API,
         so Add to Home Screen is the way this page fills the display. -->
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Table Mgr">
    <meta name="theme-color" content="#0f172a">
    <!-- Home-screen icon for anyone who adds this page by hand; without it iOS
         uses a screenshot of the page. -->
    <link rel="apple-touch-icon" href="/img/app-icon-192.png">
    <title>Table Manager &ndash; <?= htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE) ?></title>
    <link rel="stylesheet" href="/style.css?v=<?= htmlspecialchars(APP_VERSION . '.' . (@filemtime(__DIR__ . '/style.css') ?: 0)) ?>">
    <style>
    /* Phone-first. The desktop gets the same single column, centered. */
    html, body { background:#f1f5f9; }
    body { margin:0; font-family:inherit; }
    .gd-wrap { max-width:560px; margin:0 auto; padding-bottom:84px; }

    /* ── Sticky header: the clock is the page's anchor ── */
    .gd-head { position:sticky; top:0; z-index:50; background:#0f172a; color:#e2e8f0;
        padding:max(.5rem, env(safe-area-inset-top)) .9rem .55rem; cursor:pointer;
        -webkit-tap-highlight-color:transparent; }
    .gd-head-top { display:flex; align-items:baseline; gap:.6rem; }
    .gd-level { font-size:.72rem; font-weight:800; letter-spacing:.08em; text-transform:uppercase; color:#94a3b8; white-space:nowrap; }
    .gd-clock { font-size:2.35rem; font-weight:800; font-variant-numeric:tabular-nums; line-height:1; margin-left:auto; }
    .gd-clock.paused { color:#f87171; }
    .gd-head-blinds { display:flex; align-items:baseline; gap:.55rem; margin-top:.15rem; }
    .gd-blinds { font-size:1.15rem; font-weight:700; }
    .gd-next { font-size:.8rem; color:#94a3b8; margin-left:auto; }
    .gd-stats { font-size:.74rem; color:#94a3b8; margin-top:.3rem; display:flex; gap:.8rem; flex-wrap:wrap; }
    .gd-stats b { color:#e2e8f0; font-weight:700; }
    .gd-title-line { font-size:.68rem; color:#64748b; display:flex; gap:.5rem; align-items:center; margin-bottom:.15rem; }
    .gd-title-line .gd-back { color:#94a3b8; text-decoration:none; font-size:1rem; padding:.55rem .7rem; margin:-.55rem 0 -.55rem -.7rem; }
    .gd-title-line span { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; min-width:0; }

    /* Timer command row: hidden until a header tap. 52px targets. */
    .gd-controls { display:none; gap:.45rem; margin-top:.55rem; }
    .gd-controls.open { display:flex; }
    .gd-controls button { flex:1; min-height:52px; border:none; border-radius:10px;
        background:#1e293b; color:#e2e8f0; font-size:1.15rem; cursor:pointer;
        -webkit-tap-highlight-color:transparent; }
    .gd-controls button:active { background:#334155; }
    .gd-controls button.gd-play { background:#2563eb; }
    .gd-no-timer { margin-top:.4rem; font-size:.8rem; color:#fbbf24; }
    .gd-no-timer a { color:#fbbf24; }

    /* ── Strips under the header ── */
    .gd-strip { padding:.55rem .9rem; font-size:.85rem; display:flex; align-items:center; gap:.6rem; }
    .gd-net-stale { background:#fffbeb; color:#92400e; border-bottom:1.5px solid #f59e0b; }
    .gd-net-dead  { background:#fee2e2; color:#991b1b; border-bottom:1.5px solid #dc2626; cursor:pointer; }
    .gd-setup { background:#fffbeb; color:#92400e; border-bottom:1.5px solid #f59e0b; justify-content:space-between; }
    .gd-winner { background:#dcfce7; color:#166534; border-bottom:1.5px solid #16a34a; font-weight:700; }
    /* Cash games end with a reconciliation, not a champion — slate, not green,
       so the finished strip does not read as "someone won". */
    .gd-done { background:#f1f5f9; color:#334155; border-bottom:1.5px solid #cbd5e1; font-weight:700; cursor:pointer; }
    .gd-strip .gd-strip-btn { border:none; border-radius:8px; padding:.45rem .9rem; font-weight:700;
        background:#d97706; color:#fff; cursor:pointer; min-height:44px; }

    /* Pending walk-ins: always visible, never behind a filter. */
    .gd-pending { background:#fffbeb; border-bottom:1.5px solid #f59e0b; padding:.3rem .9rem .45rem; }
    .gd-pending-title { font-size:.68rem; font-weight:800; text-transform:uppercase; letter-spacing:.06em; color:#92400e; margin:.2rem 0; }
    .gd-pending-row { display:flex; align-items:center; gap:.5rem; padding:.25rem 0; }
    .gd-pending-row .gd-pname { flex:1; min-width:0; font-weight:600; color:#1e293b; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .gd-pending-row button { border:none; border-radius:8px; min-height:44px; padding:0 .85rem; font-weight:700; cursor:pointer; }
    .gd-pending-row .gd-ok { background:#16a34a; color:#fff; }
    .gd-pending-row .gd-no { background:#fff; color:#991b1b; border:1.5px solid #fca5a5; }

    /* ── Search + view switcher ── */
    .gd-toolbar { padding:.6rem .9rem .5rem; display:flex; flex-direction:column; gap:.5rem; }
    .gd-search { width:100%; box-sizing:border-box; padding:.55rem .8rem; font-size:1rem;
        border:1.5px solid #e2e8f0; border-radius:10px; background:#fff; }
    #gdViewSeg { width:100%; }
    #gdViewSeg button { flex:1; min-height:44px; }

    /* ── Roster rows ── */
    #gdView { padding:0 .9rem; overflow:hidden; }
    .gd-row { display:flex; align-items:center; gap:.6rem; background:#fff;
        border:1.5px solid #e2e8f0; border-radius:12px; padding:.55rem .7rem; margin-bottom:.5rem;
        cursor:pointer; -webkit-tap-highlight-color:transparent; }
    .gd-row:active { background:#f8fafc; }
    .gd-row.gd-out { opacity:.62; }
    .gd-row.gd-flash { animation:gdFlash 1.6s ease-out; }
    @keyframes gdFlash { 0% { background:#dbeafe; } 100% { background:#fff; } }
    .gd-row-main { flex:1; min-width:0; }
    .gd-row-name { font-weight:700; color:#1e293b; font-size:.95rem;
        overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .gd-row-meta { font-size:.72rem; color:#94a3b8; margin-top:.1rem; display:flex; gap:.45rem; align-items:center; }
    .gd-badge { font-size:.62rem; font-weight:800; text-transform:uppercase; letter-spacing:.04em;
        padding:.1rem .4rem; border-radius:4px; }
    .gd-badge.playing { background:#dcfce7; color:#166534; }
    .gd-badge.out     { background:#fee2e2; color:#991b1b; }
    .gd-badge.idle    { background:#f1f5f9; color:#64748b; }
    .gd-badge.champ   { background:#fef3c7; color:#92400e; }
    /* Cash-out is a normal exit; busting for $0 keeps the red `out` badge. */
    .gd-badge.cashed  { background:#e0f2fe; color:#075985; }
    .gd-primary { border:none; border-radius:10px; min-width:74px; min-height:44px;
        font-weight:800; font-size:.85rem; cursor:pointer; flex-shrink:0;
        -webkit-tap-highlight-color:transparent; }
    .gd-primary:active { filter:brightness(.92); }
    .gd-primary.buyin   { background:#16a34a; color:#fff; }
    .gd-primary.ko      { background:#dc2626; color:#fff; }
    .gd-primary.reenter { background:#2563eb; color:#fff; }
    .gd-primary.undo    { background:#f1f5f9; color:#334155; border:1.5px solid #cbd5e1; }
    /* Cash-out is not an elimination — cyan (the console's CASH badge colour),
       never the KO red, or the host learns to fear the primary button. */
    .gd-primary.cashout { background:#0891b2; color:#fff; min-width:84px; }
    .gd-empty { text-align:center; color:#94a3b8; font-size:.85rem; padding:1.6rem 0; }

    /* ── Seats view ── */
    .gd-table-card { background:#fff; border:1.5px solid #e2e8f0; border-radius:12px; margin-bottom:.6rem; overflow:hidden; }
    .gd-table-head { display:flex; align-items:center; gap:.5rem; padding:.5rem .7rem;
        background:#f8fafc; border-bottom:1px solid #eef2f7; font-weight:800; font-size:.8rem;
        text-transform:uppercase; letter-spacing:.05em; color:#475569; }
    .gd-table-head .gd-count { margin-left:auto; font-weight:600; color:#94a3b8; text-transform:none; letter-spacing:0; }
    .gd-seat-row { display:flex; align-items:center; gap:.55rem; padding:.45rem .7rem; border-bottom:1px solid #f8fafc; }
    .gd-seat-num { width:1.6rem; height:1.6rem; border-radius:50%; background:#eff6ff; color:#1e40af;
        font-weight:800; font-size:.72rem; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
    .gd-seat-name { flex:1; min-width:0; font-size:.9rem; font-weight:600; color:#1e293b;
        overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .gd-move-chip { border:1.5px solid #cbd5e1; background:#fff; color:#334155; border-radius:8px;
        min-height:44px; padding:0 .8rem; font-weight:700; font-size:.75rem; cursor:pointer; }
    .gd-rebalance { width:100%; border:none; border-radius:10px; min-height:46px; font-weight:800;
        background:#0f172a; color:#fff; cursor:pointer; margin:.1rem 0 .8rem; }

    /* ── Fixed footer: add walk-in (+ cash box on a cash game) ── */
    .gd-foot { position:fixed; left:0; right:0; bottom:0; z-index:40;
        padding:.6rem .9rem max(.85rem, env(safe-area-inset-bottom));
        background:linear-gradient(to top, #f1f5f9 65%, rgba(241,245,249,0)); pointer-events:none; }
    .gd-foot-row { display:flex; gap:.5rem; max-width:560px; margin:0 auto; }
    .gd-foot button { pointer-events:auto; flex:1; border:none; border-radius:12px;
        min-height:50px; font-weight:800; font-size:.95rem;
        background:#2563eb; color:#fff; cursor:pointer; box-shadow:0 6px 18px rgba(37,99,235,.35); }
    .gd-foot button.gd-foot-alt { flex:0 0 auto; padding:0 1.1rem; background:#0f172a;
        box-shadow:0 6px 18px rgba(15,23,42,.3); }

    /* ── Bottom action sheet ── */
    .gd-sheet-back { position:fixed; inset:0; background:rgba(15,23,42,.45); z-index:90;
        opacity:0; pointer-events:none; transition:opacity .2s; }
    .gd-sheet-back.open { opacity:1; pointer-events:auto; }
    .gd-sheet { position:fixed; left:0; right:0; bottom:0; z-index:91; background:#fff;
        border-radius:16px 16px 0 0; box-shadow:0 -8px 30px rgba(0,0,0,.25);
        transform:translateY(105%); transition:transform .26s cubic-bezier(.4,0,.2,1);
        max-width:560px; margin:0 auto;
        padding:.4rem .9rem max(1rem, env(safe-area-inset-bottom));
        max-height:78dvh; overflow-y:auto; }
    .gd-sheet.open { transform:translateY(0); }
    .gd-sheet-grip { width:38px; height:4px; border-radius:2px; background:#cbd5e1; margin:.3rem auto .5rem; }
    .gd-sheet-name { font-weight:800; font-size:1.05rem; color:#1e293b; }
    .gd-sheet-sub { font-size:.75rem; color:#94a3b8; margin-bottom:.6rem; }
    .gd-sheet-grid { display:flex; flex-direction:column; gap:.45rem; }
    .gd-sheet-row { display:flex; align-items:center; gap:.6rem; }
    .gd-sheet-row .gd-lbl { flex:1; font-weight:600; font-size:.9rem; color:#334155; }
    .gd-sheet-row .gd-sub { font-size:.72rem; color:#94a3b8; font-weight:400; display:block; }
    .gd-counter { display:flex; align-items:center; gap:.15rem; }
    .gd-counter button { width:48px; height:48px; border:1.5px solid #cbd5e1; background:#fff;
        border-radius:10px; font-size:1.3rem; font-weight:700; color:#334155; cursor:pointer; }
    .gd-counter .gd-cnt { min-width:2rem; text-align:center; font-weight:800; font-size:1.05rem; }
    .gd-sheet-btn { border:1.5px solid #cbd5e1; background:#fff; color:#334155; border-radius:10px;
        min-height:48px; padding:0 .9rem; font-weight:700; font-size:.88rem; cursor:pointer;
        -webkit-tap-highlight-color:transparent; }
    .gd-sheet-btn:active { background:#f1f5f9; }
    .gd-sheet-btn.danger { color:#991b1b; border-color:#fca5a5; }
    .gd-sheet-btn.wide { width:100%; }
    .gd-sheet-btn.go { background:#16a34a; border-color:#16a34a; color:#fff; }
    /* Cash box: read-out rows and the two typed fields. */
    .gd-cb-val { font-weight:800; font-variant-numeric:tabular-nums; color:#1e293b; }
    .gd-cb-val.even  { color:#16a34a; }
    .gd-cb-val.over  { color:#0891b2; }
    .gd-cb-val.short { color:#dc2626; }
    .gd-cb-input { width:120px; box-sizing:border-box; padding:.5rem .55rem; font-size:1rem;
        text-align:right; border:1.5px solid #cbd5e1; border-radius:10px; background:#fff;
        -moz-appearance:textfield; appearance:textfield; }
    .gd-cb-input::-webkit-outer-spin-button, .gd-cb-input::-webkit-inner-spin-button { -webkit-appearance:none; margin:0; }
    .gd-cb-rule { border-top:1px solid #e2e8f0; margin:.25rem 0; }
    .gd-sheet-close { width:100%; border:none; background:#f1f5f9; color:#334155; border-radius:10px;
        min-height:46px; font-weight:800; margin-top:.6rem; cursor:pointer; }

    /* ── Snackbar (post-KO undo) ── */
    .gd-snack { position:fixed; left:50%; transform:translate(-50%, 200%); bottom:calc(66px + env(safe-area-inset-bottom));
        z-index:95; background:#1e293b; color:#e2e8f0; border-radius:12px; padding:.6rem .5rem .6rem .95rem;
        display:flex; align-items:center; gap:.7rem; box-shadow:0 8px 24px rgba(0,0,0,.35);
        transition:transform .25s cubic-bezier(.4,0,.2,1), visibility .25s; visibility:hidden;
        max-width:min(92vw, 480px); white-space:nowrap; }
    .gd-snack.open { transform:translate(-50%, 0); visibility:visible; }
    .gd-snack .gd-snack-txt { overflow:hidden; text-overflow:ellipsis; font-size:.88rem; }
    .gd-snack button { border:none; background:#2563eb; color:#fff; border-radius:8px;
        min-height:44px; padding:0 1rem; font-weight:800; cursor:pointer; flex-shrink:0; }

    /* Wake-lock nudge (touch devices without the API, e.g. iOS over HTTP) */
    .gd-wake { position:fixed; left:50%; transform:translateX(-50%); top:30%; z-index:70;
        background:rgba(15,23,42,.85); color:#e2e8f0; font-size:.8rem; border-radius:10px;
        padding:.55rem .9rem; pointer-events:none; }

    @media (prefers-reduced-motion: reduce) {
        .gd-sheet, .gd-sheet-back, .gd-snack { transition:none; }
        .gd-row.gd-flash { animation:none; }
    }
    </style>
</head>
<body>
<div class="gd-wrap">

    <header class="gd-head" id="gdHead" data-act="gdToggleControls">
        <div class="gd-title-line">
            <a class="gd-back" href="/checkin.php?event_id=<?= (int)$event_id ?>" data-stop="1" title="Back to the check-in console">&#8592;</a>
            <span><?= htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE) ?></span>
        </div>
        <div class="gd-head-top">
            <span class="gd-level" id="gdLevel">&mdash;</span>
            <span class="gd-clock" id="gdClock">--:--</span>
        </div>
        <div class="gd-head-blinds">
            <span class="gd-blinds" id="gdBlinds">&mdash;</span>
            <span class="gd-next" id="gdNext"></span>
        </div>
        <div class="gd-stats" id="gdStats"></div>
        <?php /* A cash game has no blind clock to starve, so the nudge would be
                 noise on every poll — the header shows money on the table instead. */ ?>
        <?php if (!$has_timer && !$is_cash): ?>
        <div class="gd-no-timer" data-stop="1">Timer not set up &mdash;
            <a href="/timer.php?event_id=<?= (int)$event_id ?>">open the timer once</a> to enable clock controls.</div>
        <?php endif; ?>
        <div class="gd-controls" id="gdControls">
            <button type="button" data-act="gdCmd" data-a1="skip_prev" title="Previous level">&#9198;</button>
            <button type="button" class="gd-play" id="gdPlayBtn" data-act="gdCmd" data-a1="toggle_play" title="Play / pause">&#9654;</button>
            <button type="button" data-act="gdCmd" data-a1="skip_next" title="Next level">&#9197;</button>
            <button type="button" data-act="gdCmd" data-a1="sub_time" title="Subtract a minute">&minus;1m</button>
            <button type="button" data-act="gdCmd" data-a1="add_time" title="Add a minute">+1m</button>
            <button type="button" data-act="gdCmd" data-a1="undo" title="Undo the last timer action">&#8630;</button>
        </div>
    </header>

    <div id="gdNet" style="display:none"></div>
    <div id="gdLifecycle"></div>
    <div id="gdPending"></div>

    <div class="gd-toolbar">
        <input type="search" class="gd-search" id="gdSearch" placeholder="Search players&hellip;"
               autocomplete="off" data-act-input="gdSearch" data-input-a1="@value">
        <div class="pk-seg" id="gdViewSeg">
            <span class="pk-seg-thumb"></span>
            <button type="button" data-view="playing" class="active" data-act="gdSetView" data-a1="playing">Playing</button>
            <button type="button" data-view="all" data-act="gdSetView" data-a1="all">All</button>
            <button type="button" data-view="out" data-act="gdSetView" data-a1="out"><?= $is_cash ? 'Cashed' : 'Out' ?></button>
            <button type="button" data-view="seats" data-act="gdSetView" data-a1="seats">Seats</button>
        </div>
    </div>

    <div id="gdView"></div>

</div>

<div class="gd-foot">
    <div class="gd-foot-row">
        <button type="button" data-act="gdAddWalkin">+ Walk-in</button>
        <?php if ($is_cash): ?>
        <button type="button" class="gd-foot-alt" data-act="gdCashBox" title="Cash box: tips and the counted total">&#129534; Box</button>
        <?php endif; ?>
    </div>
</div>

<!-- One reused action sheet, repopulated per player via textContent. -->
<div class="gd-sheet-back" id="gdSheetBack" data-act-self="gdCloseSheet"></div>
<div class="gd-sheet" id="gdSheet" role="dialog" aria-modal="true">
    <div class="gd-sheet-grip"></div>
    <div class="gd-sheet-name" id="gdSheetName"></div>
    <div class="gd-sheet-sub" id="gdSheetSub"></div>
    <div class="gd-sheet-grid" id="gdSheetGrid"></div>
    <button type="button" class="gd-sheet-close" data-act="gdCloseSheet">Close</button>
</div>

<div class="gd-snack" id="gdSnack">
    <span class="gd-snack-txt" id="gdSnackTxt"></span>
    <button type="button" id="gdSnackBtn" data-act="gdSnackAction">Undo</button>
</div>

<script nonce="<?= csp_nonce() ?>">
// Server-known facts only; everything live arrives with the first polls.
window.GD = {
    eventId:   <?= (int)$event_id ?>,
    sessionId: <?= (int)$session['id'] ?>,
    status:    <?= json_encode((string)$session['status']) ?>,
    // Server-known and stable for the life of the page: every render branch
    // reads this, so none of them can be wrong before the first roster poll.
    gameType:  <?= json_encode((string)($session['game_type'] ?? 'tournament')) ?>,
    csrf:      <?= json_encode($csrf) ?>,
    hasTimer:  <?= $has_timer ? 'true' : 'false' ?>,
    assetV:    <?= (int)(@filemtime(__DIR__ . '/table_manager.js') ?: 0) ?>
};
</script>
<script src="/pk-seg.js?v=<?= htmlspecialchars(APP_VERSION . '.' . (@filemtime(__DIR__ . '/pk-seg.js') ?: 0)) ?>" defer></script>
<script src="/pk-dialogs.js?v=<?= htmlspecialchars(APP_VERSION . '.' . (@filemtime(__DIR__ . '/pk-dialogs.js') ?: 0)) ?>" defer></script>
<script src="/pk-dispatch.js?v=<?= htmlspecialchars(APP_VERSION . '.' . (@filemtime(__DIR__ . '/pk-dispatch.js') ?: 0)) ?>" defer></script>
<script src="/vendor/nosleep.min.js" defer></script>
<script src="/table_manager.js?v=<?= htmlspecialchars(APP_VERSION . '.' . (@filemtime(__DIR__ . '/table_manager.js') ?: 0)) ?>" defer></script>
</body>
</html>
