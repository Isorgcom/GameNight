<?php
/**
 * Message history for one event, for its hosts: every invitation, reminder,
 * message and notice sent, who it reached and how, what people replied, and
 * what is still scheduled. Two views on the segmented control: a Timeline of
 * send runs and replies, and one thread per guest. Read-only; the data comes
 * from _event_history.php.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/version.php';
require_once __DIR__ . '/_event_history.php';

$current = require_login();
$db      = get_db();
$isAdmin = $current['role'] === 'admin';

$eid = (int)($_GET['id'] ?? 0);
if ($eid <= 0 || !can_manage_event($db, $eid, (int)$current['id'], $isAdmin)) {
    http_response_code(403);
    exit('Access denied.');
}
$data = eh_load($db, $eid);
if (!$data['event']) { http_response_code(404); exit('Event not found.'); }
$event   = $data['event'];
$statusF = (($_GET['status'] ?? '') === 'failed') ? 'failed' : '';

$items   = eh_items($data);
$entries = eh_runs($items, $data);
$threads = eh_threads($items, $data);

$hostNoticeCount = 0; $failedCount = 0; $pending = []; $sent = [];
foreach ($entries as $e) {
    if ($e['host_notice']) $hostNoticeCount++;
    if ($e['failed']) $failedCount++;
    if ($e['kind'] === 'scheduled' || $e['kind'] === 'sending') $pending[] = $e; else $sent[] = $e;
}
$site_name = get_setting('site_name', 'Game Night');
$backUrl   = '/event.php?id=' . $eid;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Message history &mdash; <?= htmlspecialchars($event['title']) ?> &mdash; <?= htmlspecialchars($site_name) ?></title>
    <link rel="stylesheet" href="/style.css?v=<?= htmlspecialchars(APP_VERSION . '.' . (@filemtime(__DIR__ . '/style.css') ?: 0)) ?>">
    <style>
        .eh-wrap { max-width:860px; margin:1.5rem auto; padding:0 1rem; }
        .eh-head { display:flex; align-items:center; gap:.5rem .75rem; flex-wrap:wrap; margin-bottom:.4rem; }
        .eh-head h1 { font-size:1.4rem; font-weight:700; margin:0; flex:1 1 auto; min-width:0; overflow-wrap:anywhere; }
        .eh-head-links { display:flex; gap:.5rem; flex-wrap:wrap; }
        .eh-head-links .btn { font-size:.8rem; text-decoration:none; }
        .eh-sub { color:#64748b; font-size:.88rem; margin:0 0 1rem; line-height:1.55; }
        .eh-cancelled { background:#fffbeb; border:1px solid #f59e0b; color:#92400e; border-radius:8px; padding:.5rem .75rem; font-size:.85rem; font-weight:600; margin-bottom:1rem; }
        .eh-bar { display:flex; align-items:center; gap:.6rem 1.25rem; flex-wrap:wrap; margin-bottom:1rem; }
        .eh-bar-right { display:flex; align-items:center; gap:.6rem 1rem; flex-wrap:wrap; }
        .eh-toggle { font-size:.8rem; color:#475569; display:inline-flex; align-items:center; gap:.35rem; cursor:pointer; user-select:none; }
        .eh-toggle input { width:16px; height:16px; margin:0; accent-color:#2563eb; }
        .eh-divider { font-size:.72rem; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:#64748b; margin:1.1rem 0 .5rem; }
        .eh-run, .eh-entry, .eh-thread { background:#fff; border:1.5px solid #e2e8f0; border-radius:10px; margin-bottom:.6rem; }
        .eh-run > summary { list-style:none; cursor:pointer; padding:.6rem .85rem; display:flex; align-items:center; gap:.3rem .6rem; flex-wrap:wrap; }
        .eh-run > summary::-webkit-details-marker { display:none; }
        .eh-run > summary::before { content:'\25B8'; color:#64748b; font-size:.85rem; width:.8rem; transition:transform .2s cubic-bezier(.4,0,.2,1); }
        .eh-run[open] > summary::before { transform:rotate(90deg); }
        .eh-run > summary:focus-visible { outline:2px solid #1d4ed8; outline-offset:-2px; border-radius:8px; }
        .eh-when { font-size:.78rem; color:#64748b; white-space:nowrap; min-width:10rem; }
        .eh-title { font-weight:600; font-size:.9rem; color:#1e293b; flex:1 1 auto; min-width:0; overflow-wrap:anywhere; }
        .eh-chips { display:flex; gap:.35rem; flex-wrap:wrap; align-items:center; }
        .eh-chip { font-size:.7rem; font-weight:600; padding:.12rem .45rem; border-radius:5px; background:#f1f5f9; color:#475569; white-space:nowrap; }
        .eh-chip.ch.sms { background:#eff6ff; color:#1e40af; }
        .eh-chip.ch.email { background:#f5f3ff; color:#5b21b6; }
        .eh-chip.ch.whatsapp { background:#ecfdf5; color:#065f46; }
        .eh-chip.fail { background:#fef2f2; color:#b91c1c; }
        .eh-chip.wait { background:#fffbeb; color:#92400e; }
        .eh-chip.none { background:#f1f5f9; color:#64748b; }
        .eh-rows { list-style:none; margin:0; padding:.15rem .85rem .55rem; border-top:1px solid #f1f5f9; }
        .eh-row { padding:.45rem 0; border-bottom:1px solid #f1f5f9; font-size:.85rem; }
        .eh-row:last-child { border-bottom:0; }
        .eh-row-main { display:flex; align-items:center; gap:.5rem; flex-wrap:wrap; }
        .eh-name { font-weight:600; color:#1e293b; }
        .eh-what { color:#334155; }
        .eh-row-sub { display:flex; gap:.35rem .6rem; flex-wrap:wrap; font-size:.76rem; color:#64748b; margin-top:.15rem; align-items:center; }
        .eh-addr { overflow-wrap:anywhere; }
        .eh-ch { font-size:.66rem; font-weight:700; padding:.08rem .4rem; border-radius:4px; background:#eff6ff; color:#1e40af; letter-spacing:.02em; }
        .eh-ch.email { background:#f5f3ff; color:#5b21b6; }
        .eh-ch.whatsapp { background:#ecfdf5; color:#065f46; }
        .eh-st { font-size:.7rem; font-weight:700; padding:.1rem .45rem; border-radius:5px; white-space:nowrap; }
        .eh-st.ok { background:#f0fdf4; color:#15803d; }
        .eh-st.fail { background:#fef2f2; color:#b91c1c; }
        .eh-st.wait { background:#fffbeb; color:#92400e; }
        .eh-st.none { background:#f1f5f9; color:#64748b; }
        .eh-st.sched { background:#f8fafc; color:#64748b; border:1px dashed #cbd5e1; }
        .eh-st.send { background:#eff6ff; color:#1e40af; }
        .eh-body { font-size:.85rem; color:#334155; margin-top:.3rem; line-height:1.45; overflow-wrap:anywhere; }
        .eh-subject { color:#64748b; font-size:.78rem; }
        .eh-err { font-size:.76rem; color:#b91c1c; margin-top:.2rem; overflow-wrap:anywhere; }
        .eh-note { font-size:.76rem; color:#64748b; margin-top:.2rem; }
        .eh-entry { padding:.6rem .85rem; }
        .eh-entry-head { display:flex; align-items:center; gap:.3rem .6rem; flex-wrap:wrap; }
        .eh-inbound { border-left:4px solid #16a34a; }
        .eh-outconv { border-left:4px solid #2563eb; }
        .eh-scheduled { border-style:dashed; border-color:#cbd5e1; background:#f8fafc; }
        .eh-scheduled .eh-title { color:#475569; }
        .eh-sending { border-color:#bfdbfe; background:#eff6ff; }
        .eh-names { display:flex; flex-wrap:wrap; gap:.3rem .9rem; font-size:.82rem; color:#334155; padding-top:.5rem; }
        .eh-thread-head { display:flex; align-items:center; gap:.4rem .5rem; flex-wrap:wrap; padding:.6rem .85rem; }
        .eh-thread-name { font-weight:700; font-size:.95rem; color:#1e293b; }
        .eh-tag { font-size:.68rem; font-weight:600; padding:.1rem .4rem; border-radius:5px; background:#f1f5f9; color:#64748b; white-space:nowrap; }
        .eh-gone .eh-thread-name { color:#475569; }
        .eh-rsvp { font-size:.72rem; font-weight:700; padding:.12rem .55rem; border-radius:999px; }
        .eh-rsvp.rsvp-yes { background:#f0fdf4; color:#15803d; }
        .eh-rsvp.rsvp-no { background:#fef2f2; color:#b91c1c; }
        .eh-rsvp.rsvp-maybe { background:#fffbeb; color:#b45309; }
        .eh-rsvp.rsvp-none { background:#f1f5f9; color:#64748b; }
        .eh-none { padding:0 .85rem .7rem; font-size:.8rem; color:#64748b; }
        .eh-empty { color:#64748b; font-size:.9rem; margin:.5rem 0; }
        body:not(.eh-show-hosts) [data-host="1"] { display:none; }
        .eh-failed-only .eh-run[data-failed="0"], .eh-failed-only .eh-entry[data-failed="0"],
        .eh-failed-only .eh-thread[data-failed="0"], .eh-failed-only .eh-divider { display:none; }
        .eh-failed-only .eh-row[data-status="sent"], .eh-failed-only .eh-row[data-status="none"],
        .eh-failed-only .eh-row[data-status="scheduled"], .eh-failed-only .eh-row[data-status="received"] { opacity:.45; }
        @media (max-width:640px) {
            .eh-head h1 { font-size:1.15rem; }
            .eh-when { min-width:0; flex-basis:100%; }
            .eh-run > summary { position:relative; padding-left:1.7rem; }
            .eh-run > summary::before { position:absolute; left:.7rem; top:.62rem; }
            .eh-long { display:none; }
        }
    </style>
</head>
<body>

<?php $nav_active = 'calendar'; $nav_user = $current; require __DIR__ . '/_nav.php'; ?>

<div class="eh-wrap">
    <div class="eh-head">
        <h1>Message history &mdash; <?= htmlspecialchars($event['title']) ?></h1>
        <div class="eh-head-links">
            <a href="/sms_conversations.php?event=<?= $eid ?>" class="btn btn-outline">Text conversations</a>
            <?php if ($isAdmin): ?><a href="/sms_log.php?event=<?= $eid ?>" class="btn btn-outline">Raw log</a><?php endif; ?>
            <a href="<?= htmlspecialchars($backUrl) ?>" class="btn btn-outline">Back to event</a>
        </div>
    </div>
    <p class="eh-sub">Every invitation, reminder, message and notice this event sent, who it reached and how, what people replied, and what is still to go out. Times are in your timezone. The log keeps 90 days.</p>
    <?php if (!empty($event['cancelled_at'])): ?>
    <div class="eh-cancelled">This event was cancelled on <?= htmlspecialchars(eh_local($event['cancelled_at'], 'D M j, g:i A')) ?>.</div>
    <?php endif; ?>

    <div class="eh-bar">
        <div class="pk-seg" id="ehSeg" role="tablist" aria-label="View" data-seg-role="tablist">
            <span class="pk-seg-thumb"></span>
            <button type="button" data-view="timeline" class="active" data-act="ehSetView" data-a1="timeline" data-a2="true">Timeline</button>
            <button type="button" data-view="guests" data-act="ehSetView" data-a1="guests" data-a2="true">By guest</button>
        </div>
        <div class="eh-bar-right">
            <div class="pk-seg" id="ehStatusSeg" aria-label="Show">
                <span class="pk-seg-thumb"></span>
                <button type="button" data-status="" class="active" data-act="ehSetStatus" data-a1="" data-a2="true">All</button>
                <button type="button" data-status="failed" data-act="ehSetStatus" data-a1="failed" data-a2="true">Failed<span class="eh-long"> only</span> (<?= $failedCount ?>)</button>
            </div>
            <?php if ($hostNoticeCount > 0): ?>
            <label class="eh-toggle"><input type="checkbox" id="ehHostsCb" data-act-change="ehToggleHosts" data-change-a1="@checked"> Show notices to hosts (<?= $hostNoticeCount ?>)</label>
            <?php endif; ?>
        </div>
    </div>

    <section class="eh-view" data-view="timeline">
        <?php if (!$entries): ?>
        <p class="eh-empty">Nothing has been sent for this event yet.</p>
        <?php else: ?>
            <?php if ($pending): ?>
            <div class="eh-divider">Still to go out</div>
            <?php foreach ($pending as $e) echo eh_entry_html($e); ?>
            <?php if ($sent): ?><div class="eh-divider">Sent and received</div><?php endif; ?>
            <?php endif; ?>
            <?php foreach ($sent as $e) echo eh_entry_html($e); ?>
        <?php endif; ?>
        <p class="eh-empty eh-empty-filter" hidden>Nothing failed.</p>
    </section>

    <section class="eh-view" data-view="guests" hidden>
        <?php
        $lastRank = null;
        foreach ($threads as $t):
            $rank = $t['host_only'] ? 2 : ($t['on_list'] ? 0 : 1);
            if ($rank !== $lastRank) {
                if ($rank === 1) echo '<div class="eh-divider">No longer on the list</div>';
                if ($rank === 2) echo '<div class="eh-divider" data-host="1">Hosts</div>';
                $lastRank = $rank;
            }
            echo eh_thread_html($t);
        endforeach;
        if (!$threads): ?>
        <p class="eh-empty">Nobody is on the guest list yet.</p>
        <?php endif; ?>
        <p class="eh-empty eh-empty-filter" hidden>Nothing failed.</p>
    </section>
</div>

<?php require __DIR__ . '/_footer.php'; ?>
<script nonce="<?= csp_nonce() ?>">
(function () {
    var VIEW_KEY = 'evhist.view', HOSTS_KEY = 'evhist.hosts';
    var START_STATUS = <?= js_json($statusF) ?>;

    function pane(v) { return document.querySelector('.eh-view[data-view="' + v + '"]'); }

    // Failed-only hides everything that did not fail and opens what is left;
    // host notices stay hidden unless the toggle is on.
    function applyFilter() {
        var failedOnly = document.body.classList.contains('eh-failed-only');
        var showHosts  = document.body.classList.contains('eh-show-hosts');
        document.querySelectorAll('.eh-view').forEach(function (p) {
            var any = false;
            p.querySelectorAll('.eh-run, .eh-entry, .eh-thread').forEach(function (el) {
                var vis = !failedOnly || el.getAttribute('data-failed') === '1';
                if (vis && el.getAttribute('data-host') === '1' && !showHosts) vis = false;
                if (vis) any = true;
                if (failedOnly && vis && el.tagName === 'DETAILS') el.open = true;
            });
            var empty = p.querySelector('.eh-empty-filter');
            if (empty) empty.hidden = any || !failedOnly;
        });
    }

    window.ehSetView = function (v, animate) {
        var seg = document.getElementById('ehSeg');
        var curBtn = seg.querySelector('button.active');
        var cur = curBtn ? curBtn.getAttribute('data-view') : '';
        if (animate && v === cur) return;
        seg.querySelectorAll('button').forEach(function (b) { b.classList.toggle('active', b.getAttribute('data-view') === v); });
        document.querySelectorAll('.eh-view').forEach(function (p) { p.hidden = p.getAttribute('data-view') !== v; });
        positionSegThumb('ehSeg', !!animate);
        if (animate && cur) slideViewIn(pane(v), segTravelDirection('ehSeg', 'data-view', cur, v));
        try { localStorage.setItem(VIEW_KEY, v); } catch (e) {}
    };

    window.ehSetStatus = function (s, animate) {
        var seg = document.getElementById('ehStatusSeg');
        var curBtn = seg.querySelector('button.active');
        var cur = curBtn ? curBtn.getAttribute('data-status') : '';
        if (animate && s === cur) return;
        seg.querySelectorAll('button').forEach(function (b) { b.classList.toggle('active', b.getAttribute('data-status') === s); });
        document.body.classList.toggle('eh-failed-only', s === 'failed');
        positionSegThumb('ehStatusSeg', !!animate);
        try {
            var u = new URL(location.href);
            if (s === 'failed') u.searchParams.set('status', 'failed'); else u.searchParams.delete('status');
            history.replaceState(null, '', u.toString());
        } catch (e) {}
        applyFilter();
    };

    window.ehToggleHosts = function (on) {
        document.body.classList.toggle('eh-show-hosts', !!on);
        try { localStorage.setItem(HOSTS_KEY, on ? '1' : '0'); } catch (e) {}
        applyFilter();
    };

    var v = 'timeline';
    try { v = localStorage.getItem(VIEW_KEY) || 'timeline'; } catch (e) {}
    if (v !== 'timeline' && v !== 'guests') v = 'timeline';
    var hosts = false;
    try { hosts = localStorage.getItem(HOSTS_KEY) === '1'; } catch (e) {}
    var cb = document.getElementById('ehHostsCb');
    if (cb) cb.checked = hosts;
    document.body.classList.toggle('eh-show-hosts', hosts);
    ehSetView(v, false);
    ehSetStatus(START_STATUS, false);
})();
</script>
</body>
</html>
