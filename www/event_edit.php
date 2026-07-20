<?php
/**
 * Standalone add/edit event page.
 *
 * Full-page version of the calendar's event editor modal (Phase 1: reachable by
 * direct URL only; the modal remains the calendar's default editor until Phase 2
 * flips the entry points). Uses the same field names/IDs and the same shared
 * save path (_event_save.php) as the modal, so behavior is identical — including
 * the RSVP/token preservation rules on invite re-saves.
 *
 * GET params:
 *   id=N            edit event N (requires can_manage_event)
 *   date=YYYY-MM-DD prefill start date for a new event
 *   occ=YYYY-MM-DD  manage a single occurrence's invites (recurring events)
 *   m=YYYY-MM       calendar month to return to after save/cancel
 *   wk=YYYY-MM-DD   calendar week (Sunday) to return to after save/cancel
 */
require_once __DIR__ . '/auth.php';

$current = require_login();
$db      = get_db();
$isAdmin = $current['role'] === 'admin';

if (get_setting('show_calendar', '1') !== '1') {
    http_response_code(403);
    exit('Calendar is disabled.');
}

$allowUserEvents = get_setting('allow_user_events', '0') === '1';
$canCreateEvents = $isAdmin || $allowUserEvents;
$allowMaybe      = get_setting('allow_maybe_rsvp', '1') === '1';

// ── Return-context + prefill params ─────────────────────────────────────────
$backM  = preg_match('/^\d{4}-\d{2}$/', $_GET['m'] ?? '')        ? $_GET['m']    : '';
$backWk = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['wk'] ?? '') ? $_GET['wk']   : '';
$prefillDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'] ?? '') ? $_GET['date'] : '';
$occDate     = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['occ']  ?? '') ? $_GET['occ']  : '';

session_start_safe();
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// ── POST: save through the shared path, then return to the calendar ─────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Invalid request token.'];
        header('Location: /calendar.php');
        exit;
    }
    $action = ($_POST['action'] ?? '') === 'edit' ? 'edit' : 'add';
    // Permission gates mirror calendar.php: creation needs the user-events setting
    // (or admin); editing needs can_manage_event for this specific event.
    if ($action === 'add' && !$canCreateEvents) {
        http_response_code(403); exit('Access denied.');
    }
    if ($action === 'edit') {
        $chkId = (int)($_POST['id'] ?? 0);
        if ($chkId <= 0 || !can_manage_event($db, $chkId, (int)$current['id'], $isAdmin)) {
            http_response_code(403); exit('You can only modify events you manage.');
        }
    }

    require_once __DIR__ . '/_event_save.php';
    $res = event_save_from_post($db, $current, $isAdmin, $allowMaybe);
    if (!$res['ok']) {
        $_SESSION['flash'] = ['type' => 'error', 'msg' => $res['error']];
        // Re-show the editor so nothing typed is lost server-side context-wise.
        $qs = $_POST['id'] ? ('?id=' . (int)$_POST['id']) : '';
        if ($backM)  $qs .= ($qs ? '&' : '?') . 'm=' . urlencode($_POST['month_param'] ?? '');
        header('Location: /event_edit.php' . $qs);
        exit;
    }
    if (($res['invites_sent'] ?? 0) > 0) {
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Event saved. ' . (int)$res['invites_sent'] . ' invitation(s) queued — delivery runs in the background (the event page shows progress).'];
    } else {
        $_SESSION['flash'] = ['type' => 'success', 'msg' => $action === 'add' ? 'Event added.' : 'Event updated.'];
    }

    // Land on the canonical event page after save: it has the roster and Send
    // Invitations, and unlike the calendar ?open= deep link it can't miss when
    // the viewer's timezone shifts the event onto a different calendar day.
    if (!empty($res['event_id'])) {
        header('Location: /event.php?id=' . (int)$res['event_id']);
        exit;
    }
    $back_wk = $_POST['wk_param'] ?? '';
    $back_m  = $_POST['month_param'] ?? '';
    if (!empty($back_wk)) {
        header('Location: /calendar.php?wk=' . urlencode($back_wk));
    } else {
        header('Location: /calendar.php' . ($back_m ? '?m=' . urlencode($back_m) : ''));
    }
    exit;
}

// ── GET: load the event (edit) or defaults (add) ─────────────────────────────
$eid   = (int)($_GET['id'] ?? 0);
$event = null;
$eventInvitesRows = [];
$pokerRow = null;
$isCopy = false;

if ($eid > 0) {
    if (!can_manage_event($db, $eid, (int)$current['id'], $isAdmin)) {
        http_response_code(403); exit('You can only edit events you manage.');
    }
    $row = $db->prepare('SELECT * FROM events WHERE id = ?');
    $row->execute([$eid]);
    $event = $row->fetch();
    if (!$event) { http_response_code(404); exit('Event not found.'); }

    // Viewer-tz `_input` fields so date/time inputs pre-fill in the host's tz
    // (same enrichment the calendar applies before embedding its events).
    $site_tz   = new DateTimeZone(get_setting('timezone', 'UTC'));
    $viewer_tz = new DateTimeZone(display_timezone((int)$current['id']));
    $event = event_display_times($event, $site_tz, $viewer_tz, $occDate ?: null);

    // Invites: occurrence-specific rows when managing one date, else base rows.
    if ($occDate) {
        $is = $db->prepare("SELECT username, phone, email, rsvp, event_role, approval_status, sort_order
                            FROM event_invites WHERE event_id = ? AND occurrence_date = ?
                            ORDER BY COALESCE(sort_order, 999999), username");
        $is->execute([$eid, $occDate]);
    } else {
        $is = $db->prepare("SELECT username, phone, email, rsvp, event_role, approval_status, sort_order
                            FROM event_invites WHERE event_id = ? AND occurrence_date IS NULL
                            ORDER BY COALESCE(sort_order, 999999), username");
        $is->execute([$eid]);
    }
    $eventInvitesRows = $is->fetchAll();

    $ps = $db->prepare('SELECT event_id, game_type, buyin_amount, num_tables, seats_per_table FROM poker_sessions WHERE event_id = ?');
    $ps->execute([$eid]);
    $pokerRow = $ps->fetch() ?: null;
} else {
    if (!$canCreateEvents) {
        http_response_code(403); exit('Access denied.');
    }

    // ── Duplicate mode (?copy=ID): prefill the ADD form from an existing event.
    // Copies title/description/location/times/color/poker/reminders/visibility
    // and the invite list — but not the date (host must pick) or any RSVPs.
    $copyId = (int)($_GET['copy'] ?? 0);
    if ($copyId > 0) {
        if (!can_manage_event($db, $copyId, (int)$current['id'], $isAdmin)) {
            http_response_code(403); exit('You can only duplicate events you manage.');
        }
        $row = $db->prepare('SELECT * FROM events WHERE id = ?');
        $row->execute([$copyId]);
        $src = $row->fetch();
        if (!$src) { http_response_code(404); exit('Event not found.'); }

        $site_tz   = new DateTimeZone(get_setting('timezone', 'UTC'));
        $viewer_tz = new DateTimeZone(display_timezone((int)$current['id']));
        $src = event_display_times($src, $site_tz, $viewer_tz, null);

        $isCopy = true;
        $event  = $src;
        $event['id'] = 0;
        $event['start_date'] = '';
        $event['start_date_input'] = '';
        $event['end_date'] = null;

        $is = $db->prepare("SELECT username, phone, email, event_role, approval_status, sort_order
                            FROM event_invites WHERE event_id = ? AND occurrence_date IS NULL
                            ORDER BY COALESCE(sort_order, 999999), username");
        $is->execute([$copyId]);
        foreach ($is->fetchAll() as $r) { $r['rsvp'] = null; $eventInvitesRows[] = $r; }

        $ps = $db->prepare('SELECT event_id, game_type, buyin_amount, num_tables, seats_per_table FROM poker_sessions WHERE event_id = ?');
        $ps->execute([$copyId]);
        $pokerRow = $ps->fetch() ?: null;
    }
}

// Editor option sources (same as calendar.php)
$myLeaguesForForm = user_leagues((int)$current['id']);
$reminder_presets_available = json_decode(get_setting('reminder_offsets_available', '[10080,4320,2880,1440,720,120,30]'), true) ?: [10080,4320,2880,1440,720,120,30];
$reminder_default_offsets   = json_decode(get_setting('default_reminder_offsets',    '[2880,720]'), true) ?: [2880,720];
// Admins preload the full user list; everyone else fetches a scoped list async.
$allUsers = $isAdmin ? $db->query('SELECT username, email, phone FROM users ORDER BY username')->fetchAll() : [];

$token     = csrf_token();
$site_name = get_setting('site_name', 'Game Night');

// Cancel / return link mirrors the post-save destination (without the auto-open).
$cancelUrl = '/calendar.php' . ($backWk ? '?wk=' . urlencode($backWk) : ($backM ? '?m=' . urlencode($backM) : ''));
if ($event && !$isCopy) {
    $cancelOpen = ($occDate ?: ($event['start_date'] ?? ''));
    if ($cancelOpen !== '') {
        $cancelUrl .= (strpos($cancelUrl, '?') === false ? '?' : '&') . 'open=' . (int)$eid . '&date=' . urlencode($cancelOpen);
    }
}
$pageHeading = $isCopy ? 'Duplicate Event' : ($event ? 'Edit Event' : 'Add Event');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageHeading) ?> &mdash; <?= htmlspecialchars($site_name) ?></title>
    <link rel="stylesheet" href="/style.css?v=<?= htmlspecialchars(APP_VERSION) ?>">
    <style>
        /* Page frame: the editor fills the viewport below the nav, with the invite
           panes scrolling internally — same layout the 95vh modal provided. */
        .evedit-wrap { max-width: 1280px; margin: 1rem auto; padding: 0 1rem; }
        .evedit-card { background:#fff; border:1.5px solid #e2e8f0; border-radius:12px; box-shadow:0 4px 18px rgba(15,23,42,.06); display:flex; flex-direction:column; min-height:0; height:calc(100vh - 170px); min-height:540px; overflow:hidden; }
        .evedit-head { display:flex; align-items:center; gap:.75rem; padding:.9rem 1.25rem; border-bottom:1px solid #e2e8f0; flex-shrink:0; }
        .evedit-head h1 { font-size:1.15rem; font-weight:700; margin:0; flex:1; }
        .evedit-card form { display:flex; flex-direction:column; flex:1; min-height:0; overflow-y:auto; }

        /* ── Editor styles (copied from calendar.php's editor modal so the page
              renders identically; Phase 2 retires the modal copy) ── */
        .edit-top-bar { display:flex;align-items:center;gap:.75rem;padding:.6rem 1.25rem;flex-wrap:wrap;flex-shrink:0;border-bottom:1px solid #e2e8f0;background:#f8fafc; }
        .edit-top-bar select, .edit-top-bar input[type="text"], .edit-top-bar input[type="date"], .edit-top-bar input[type="time"] {
            padding:.32rem .45rem;border:1.5px solid #e2e8f0;border-radius:6px;font-size:.82rem;background:#fff;color:#1e293b;
        }
        .edit-top-bar select:focus, .edit-top-bar input:focus { border-color:#2563eb;outline:none; }
        .edit-top-bar .edit-title-input { flex:1;min-width:140px; }
        .edit-top-bar label { font-size:.72rem;font-weight:600;color:#64748b;display:flex;flex-direction:column;gap:.1rem; }
        #eColorDot { width:38px;height:38px;border-radius:50%;cursor:pointer;border:3px solid transparent;flex-shrink:0;transition:border-color .15s,box-shadow .15s;position:relative; }
        #eColorDot:hover { border-color:#1e293b; }
        #eColorDot.open { box-shadow:0 0 0 3px rgba(37,99,235,.3);border-color:#2563eb; }
        #eColorPicker { position:absolute;top:calc(100% + 6px);left:0;background:#fff;border:1.5px solid #e2e8f0;border-radius:10px;padding:.6rem .75rem;display:none;gap:.5rem;z-index:9999;box-shadow:0 8px 24px rgba(0,0,0,.15); }
        #eColorPicker.open { display:flex; }
        #eColorPicker .color-swatch { width:26px;height:26px; }
        #eColorDotWrap { position:relative;flex-shrink:0; }
        .color-swatch { width:28px;height:28px;border-radius:50%;cursor:pointer;border:3px solid transparent; }
        .color-swatch.selected { border-color:#1e293b; }
        .edit-title-input { flex:1;min-width:140px;padding:.45rem .7rem;border:1.5px solid #e2e8f0;border-radius:7px;font-size:.95rem;font-weight:500; }
        .edit-title-input:focus { outline:none;border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.08); }
        .inv-name-text { flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis; }
        .mgr-toggle { display:inline-flex;align-items:center;gap:.25rem;margin-left:auto;cursor:pointer;flex-shrink:0;user-select:none; }
        .mgr-toggle .mgr-label { font-size:.62rem;font-weight:700;text-transform:uppercase;color:#7c3aed; }
        .pk-toggle-input { display:none; }
        .pk-toggle-slider { position:relative;width:36px;height:20px;background:#cbd5e1;border-radius:99px;transition:background .2s;flex-shrink:0; }
        .pk-toggle-slider::after { content:'';position:absolute;top:2px;left:2px;width:16px;height:16px;background:#fff;border-radius:50%;transition:transform .2s;box-shadow:0 1px 3px rgba(0,0,0,.2); }
        .pk-toggle-input:checked + .pk-toggle-slider { background:#22c55e; }
        .pk-toggle-input:checked + .pk-toggle-slider::after { transform:translateX(16px); }
        .pk-toggle-sm { position:relative;width:28px;height:16px;background:#cbd5e1;border-radius:99px;transition:background .2s;flex-shrink:0; }
        .pk-toggle-sm::after { content:'';position:absolute;top:2px;left:2px;width:12px;height:12px;background:#fff;border-radius:50%;transition:transform .2s;box-shadow:0 1px 2px rgba(0,0,0,.2); }
        .pk-toggle-input:checked + .pk-toggle-sm { background:#7c3aed; }
        .pk-toggle-input:checked + .pk-toggle-sm::after { transform:translateX(12px); }
        #eInvitedList li[data-iname].inv-dragging { opacity:.4;background:#dbeafe; }
        #eInvitedList { counter-reset: inv; }
        #eInvitedList li[data-iname] { counter-increment: inv; display:flex;align-items:center;gap:.4rem; }
        #eInvitedList li[data-iname] .inv-name-text::before { content: counter(inv) ". "; color:#94a3b8;font-weight:600; }
        .inv-rsvp-badge { font-size:.6rem;font-weight:700;padding:.1rem .35rem;border-radius:3px;text-transform:uppercase;letter-spacing:.03em;flex-shrink:0; }
        .inv-rsvp-yes { background:#dcfce7;color:#166534; }
        .inv-rsvp-no { background:#fee2e2;color:#991b1b; }
        .inv-rsvp-maybe { background:#fef9c3;color:#854d0e; }
        .inv-rsvp-waitlist { background:#eff6ff;color:#1e40af;border:1px solid #93c5fd; }
        .inv-contact-ctl { flex-shrink:0;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;gap:.15rem;padding:.05rem .3rem;border-radius:4px;line-height:1; }
        .inv-contact-ctl:hover { background:#e2e8f0; }
        .inv-contact-ctl .inv-ci { color:#16a34a;font-size:.95rem;line-height:1; }
        .inv-contact-ctl .inv-na { font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.03em;color:#991b1b;background:#fee2e2;border-radius:3px;padding:.05rem .3rem; }
        .inv-capacity-divider { padding:.2rem .6rem;font-size:.68rem;font-weight:700;color:#b45309;background:#fef3c7;border-radius:4px;text-align:center;cursor:default;margin:.15rem 0; }
        .inv-declined-divider { padding:.2rem .6rem;font-size:.68rem;font-weight:700;color:#64748b;background:#f1f5f9;border-radius:4px;text-align:center;cursor:pointer;margin:.15rem 0; }
        .inv-declined-divider:hover { background:#e2e8f0; }
        .inv-declined-item { opacity:.5;cursor:default !important; }
        .inv-declined-item .inv-rsvp-badge { display:inline-block !important; }
        .edit-invite-panel { display:grid;grid-template-columns:1fr auto 1fr;gap:.5rem;padding:0 1.25rem;flex:1;min-height:0; }
        .invite-arrows { display:flex;flex-direction:column;justify-content:center;gap:.4rem;padding:.25rem 0; }
        .inv-arrow-btn { width:32px;height:32px;border:1.5px solid #cbd5e1;border-radius:6px;background:#fff;color:#475569;font-size:1.1rem;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;line-height:1; }
        .inv-arrow-btn:hover { background:#eff6ff;border-color:#2563eb;color:#2563eb; }
        .arrow-mobile { display:none; }
        .invite-pane { display:flex;flex-direction:column;border:1.5px solid #e2e8f0;border-radius:8px;overflow:hidden;min-height:200px; }
        .invite-pane-header { background:#f8fafc;padding:.35rem .65rem;font-size:.7rem;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.05em;flex-shrink:0;border-bottom:1px solid #e2e8f0; }
        .inv-col-head { display:flex;align-items:center;gap:.4rem;padding:.25rem .6rem;font-size:.58rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#94a3b8;border-bottom:1px solid #eef2f7;flex-shrink:0; }
        .inv-col-head span:not(:first-child) { text-align:center; }
        .inv-col-contact { width:2.8rem;flex-shrink:0; }
        .inv-col-rsvp    { width:3.4rem;flex-shrink:0; }
        .inv-col-mgr     { width:3.4rem;flex-shrink:0; }
        #eInvitedList li[data-iname] .inv-col-rsvp,
        #eInvitedList li[data-iname] .inv-col-mgr { display:inline-flex;align-items:center;justify-content:center; }
        #eInvitedList li[data-iname] .inv-col-mgr .mgr-toggle { margin-left:0; }
        .invite-pane-search { width:100%;padding:.38rem .65rem;border:none;border-bottom:1.5px solid #e2e8f0;font-size:.85rem;box-sizing:border-box;flex-shrink:0; }
        .invite-pane-search:focus { outline:none;border-color:#2563eb; }
        .invite-pane-list { flex:1;overflow-y:auto;list-style:none;margin:0;padding:.2rem; }
        .invite-pane-list li { padding:.35rem .6rem;border-radius:5px;font-size:.875rem;cursor:pointer;user-select:none;white-space:nowrap;overflow:hidden;text-overflow:ellipsis; }
        .invite-pane-list li:hover { background:#f1f5f9; }
        .invite-pane-list li.inv-selected { background:#dbeafe !important;color:#1e40af; }
        .invite-pane-list li.dimmed { color:#94a3b8;cursor:default; }
        .invite-pane-list li.dimmed::before { content:'\2713\00a0';color:#16a34a;font-weight:700; }
        .invite-pane-list li.dimmed:hover { background:transparent; }
        .invite-pane-list li.custom-row { padding:.2rem .4rem;cursor:default; }
        .invite-pane-list li.custom-row:hover { background:transparent; }
        .inv-mem-tag { display:inline-block;font-size:.7rem;font-weight:700;text-transform:uppercase;padding:.05rem .4rem;border-radius:999px;margin-left:.4rem;vertical-align:middle; }
        .inv-mem-yes { background:#dcfce7;color:#166534; }
        .inv-mem-no  { background:#e2e8f0;color:#475569; }
        .custom-row-inner { display:flex;gap:.3rem;align-items:center;flex-wrap:wrap; }
        .custom-row-inner input { padding:.28rem .45rem;border:1.5px solid #e2e8f0;border-radius:5px;font-size:.8rem;min-width:0; }
        .custom-row-inner .cr-name    { flex:1.5;min-width:110px; }
        .custom-row-inner .cr-contact { flex:2.5;min-width:160px; }
        .custom-row-inner .cr-remove  { flex-shrink:0;padding:.2rem .4rem;border:1px solid #e2e8f0;border-radius:5px;background:#fff;cursor:pointer;color:#94a3b8;font-size:.85rem;line-height:1; }
        .custom-row-inner .cr-remove:hover { background:#fee2e2;color:#dc2626;border-color:#fca5a5; }
        #eInviteData { display:none; }
        .edit-toolbar { display:flex;align-items:center;gap:.6rem;padding:.4rem 1rem;flex-wrap:wrap;flex-shrink:0;border-top:1px solid #e2e8f0;background:#f8fafc; }
        .edit-toolbar .btn { font-size:.78rem;padding:.3rem .65rem; }
        .edit-desc-wrap { padding:0 1rem .5rem;flex-shrink:0; }
        .edit-desc-wrap textarea { width:100%;resize:vertical;min-height:80px;max-height:150px;padding:.5rem .7rem;border:1.5px solid #e2e8f0;border-radius:7px;font-size:.85rem;box-sizing:border-box;font-family:inherit; }
        .edit-desc-wrap textarea:focus { outline:none;border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.08); }
        .edit-desc-toggle { font-size:.82rem;color:#2563eb;cursor:pointer;padding:.3rem 1rem;flex-shrink:0; }
        .edit-desc-toggle:hover { text-decoration:underline; }
        .edit-poker-bar { display:flex;align-items:center;gap:.5rem;padding:.3rem 1rem;flex-wrap:wrap;flex-shrink:0;background:#f0f9ff;border-top:1px solid #bfdbfe;font-size:.78rem;color:#475569; }
        .edit-poker-bar label { display:flex;align-items:center;gap:.25rem; }
        .edit-poker-bar select, .edit-poker-bar input { padding:.25rem .35rem;border:1px solid #cbd5e1;border-radius:4px;font-size:.78rem;background:#fff;width:auto; }
        .edit-poker-bar input[type="number"] { width:60px; }
        .edit-notify-row { display:flex;align-items:center;gap:.4rem;font-size:.8rem;cursor:pointer;user-select:none;white-space:nowrap;color:#64748b; }

        /* Contact-edit popup (small modal retained on the page) */
        .modal-overlay { display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:200;align-items:center;justify-content:center;padding:1rem; }
        .modal-overlay.open { display:flex; }
        .modal { background:#fff;border-radius:12px;box-shadow:0 20px 60px rgba(0,0,0,.25);width:100%;max-width:480px;padding:1.75rem; }
        .modal-header { display:flex;align-items:center;margin-bottom:.5rem; }
        .modal-close { background:none;border:none;font-size:1rem;cursor:pointer;color:#94a3b8;padding:.25rem .45rem;border-radius:6px;margin-left:auto; }
        .modal-close:hover { background:#e2e8f0; }

        /* Guest-options dropdown rows: checkbox + bold title + muted explainer */
        .go-panel { min-width: 250px; max-width: 300px; }
        .go-row { display: flex; align-items: flex-start; gap: .5rem; padding: .32rem 0; cursor: pointer;
            font-size: .85rem; color: #334155; white-space: normal !important; }
        .go-row input[type=checkbox] { margin-top: .2rem; flex-shrink: 0; }
        .go-row span strong { display: block; font-size: .85rem; }
        .go-row span small { display: block; color: #94a3b8; font-size: .72rem; line-height: 1.3; }
        /* Explicit per-row remove — visible everywhere (double-click was invisible UX) */
        .inv-remove-x { flex-shrink:0;color:#cbd5e1;font-weight:700;cursor:pointer;padding:0 .3rem;font-size:1.05rem;line-height:1; }
        .inv-remove-x:hover { color:#dc2626; }
        .edit-actions-mobile { display:none; }
        @media (max-width: 1024px) {
            /* Phone flow: no arrow strip — tap a name to invite, tap again to remove.
               Action buttons leave the mid-page toolbar for a sticky bottom bar that
               sits AFTER the invite picker, so guests get picked before saving. */
            .invite-arrows { display:none !important; }
            .edit-toolbar .btn { display:none; }
            .invite-pane-list li.dimmed { cursor:pointer; }
            .invite-pane-list li.dimmed:hover { background:#f8fafc; }
            .edit-actions-mobile { display:flex;gap:.5rem;position:sticky;bottom:0;background:#fff;
                padding:.65rem .25rem;border-top:1.5px solid #e2e8f0;z-index:40; }
            .edit-actions-mobile .btn { flex:1;text-align:center; }
        }
            .evedit-wrap { margin:.5rem auto;padding:0 .5rem; }
            .evedit-card { height:auto;min-height:0; }
            .evedit-card form { overflow-y:visible; }
            .edit-top-bar { gap:.5rem;padding:.5rem .75rem; }
            .edit-top-bar .edit-title-input { flex:1 1 100%;min-width:0; }
            .edit-invite-panel { grid-template-columns:1fr;height:auto;padding:0 .75rem; }
            .invite-arrows { flex-direction:row;justify-content:center;padding:.25rem 0; }
            .arrow-desktop { display:none; }
            .arrow-mobile { display:inline; }
            .invite-pane { min-height:180px; }
            .invite-pane-list { max-height:260px; }
            .invite-pane-list li { padding:.5rem .75rem;font-size:.95rem; }
            .invite-pane input[type="text"] { min-height:44px;font-size:1rem; }
            #eAllUsersList li:not(.dimmed):not(.custom-row)::after { content:'+';float:right;color:#22c55e;font-weight:700;font-size:1.1rem; }
            #eInvitedList li[data-iname]::after { content:'\00d7';float:right;color:#dc2626;font-weight:700;font-size:1.1rem; }
            .edit-toolbar { gap:.4rem;padding:.4rem .75rem; }
            .edit-toolbar .btn { width:auto;min-height:38px;font-size:.85rem; }
            .edit-poker-bar { padding:.3rem .75rem; }
        }
    </style>
</head>
<body>

<?php $nav_active = 'calendar'; $nav_user = $current; require __DIR__ . '/_nav.php'; ?>

<div class="evedit-wrap">
    <?php if ($flash && !empty($flash['msg'])): ?>
        <div class="alert alert-<?= ($flash['type'] ?? '') === 'error' ? 'error' : 'success' ?>" style="margin-bottom:.75rem">
            <?= htmlspecialchars($flash['msg']) ?>
        </div>
    <?php endif; ?>

    <div class="evedit-card">
        <div class="evedit-head">
            <div id="eColorDotWrap" style="align-self:center">
                <div id="eColorDot" style="background:#2563eb" onclick="toggleColorPicker(event)" title="Pick the event's calendar color"></div>
                <div id="eColorPicker">
                    <?php foreach (['#2563eb','#16a34a','#dc2626','#d97706','#7c3aed','#0891b2','#db2777'] as $c): ?>
                        <div class="color-swatch" style="background:<?= $c ?>" data-color="<?= $c ?>" onclick="selectColor('<?= $c ?>')"></div>
                    <?php endforeach; ?>
                </div>
            </div>
            <h1><?= htmlspecialchars($pageHeading) ?><?= $occDate ? ' — ' . htmlspecialchars($occDate) . ' only' : '' ?></h1>
            <a href="<?= htmlspecialchars($cancelUrl) ?>" class="btn btn-outline" style="font-size:.8rem;text-decoration:none">Back to calendar</a>
        </div>
        <form method="post" action="/event_edit.php" id="editForm">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">
            <input type="hidden" name="action" id="eAction" value="<?= ($event && !$isCopy) ? 'edit' : 'add' ?>">
            <input type="hidden" name="id" id="eId" value="<?= ($event && !$isCopy) ? (int)$event['id'] : '' ?>">
            <input type="hidden" name="month_param" value="<?= htmlspecialchars($backM) ?>">
            <input type="hidden" name="wk_param" value="<?= htmlspecialchars($backWk) ?>">
            <input type="hidden" name="occurrence_date" id="eOccDate" value="<?= htmlspecialchars($occDate) ?>">
            <input type="hidden" name="end_date" id="eEndDate" value="">
            <input type="hidden" name="end_time" id="eEndTime" value="">
            <input type="hidden" name="color" id="eColor" value="#2563eb">
            <input type="hidden" name="send_after_save" id="eSendAfterSave" value="">

            <!-- ── Unified top bar: league + vis + color + title + date + time + duration ── -->
            <div class="edit-top-bar">
                <label>League
                    <select name="league_id" id="eLeagueId" onchange="onLeagueChange()">
                        <option value="0">None</option>
                        <?php foreach ($myLeaguesForForm as $_lg): ?>
                            <option value="<?= (int)$_lg['id'] ?>" data-default-visibility="<?= htmlspecialchars($_lg['default_visibility']) ?>"><?= htmlspecialchars($_lg['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Visibility
                    <select name="visibility" id="eVisibility">
                        <option value="invitees_only">Invitees only</option>
                        <option value="league" id="eVisLeagueOpt" disabled>League members only</option>
                        <?php if ($isAdmin): ?><option value="public">Public</option><?php endif; ?>
                    </select>
                </label>
                <input type="text" name="title" id="eTitle" class="edit-title-input" placeholder="Event title" required autocomplete="off">
                <label>Date <input type="date" name="start_date" id="eStartDate" required></label>
                <label>Time <input type="time" id="eTimeNative"><input type="hidden" name="start_time" id="eStartTime"></label>
                <label>Duration
                    <select id="eDuration">
                        <option value="">—</option>
                        <option value="0.5">30m</option><option value="1">1h</option><option value="1.5">1.5h</option>
                        <option value="2">2h</option><option value="3">3h</option><option value="4">4h</option>
                        <option value="5">5h</option><option value="6">6h</option><option value="8">8h</option>
                    </select>
                </label>
                <label style="flex:1;min-width:170px">Location
                    <input type="text" name="location" id="eLocation" placeholder="Venue or address (optional)" maxlength="200" autocomplete="off">
                </label>
            </div>

            <!-- ── Toolbar: toggles + actions ── -->
            <div class="edit-toolbar">
                <label class="edit-notify-row"><span>Poker</span><input type="checkbox" name="is_poker" id="eIsPoker" value="1" class="pk-toggle-input" onchange="togglePokerFields()"><span class="pk-toggle-slider"></span></label>
                <label class="edit-notify-row" title="Send reminders before the event"><span>Reminders</span><input type="checkbox" name="reminders_enabled" id="eRemindersEnabled" value="1" class="pk-toggle-input" onchange="toggleReminderFields()" checked><span class="pk-toggle-slider"></span></label>
                <!-- Occasional settings live in one dropdown; same element IDs, so
                     the show/hide logic (waitlist needs a capacity, max-guests is
                     non-poker-only) keeps working inside the panel. -->
                <div class="rem-dd" id="eGuestOptsDD">
                    <button type="button" class="rem-dd-btn" onclick="toggleGuestOptsDD(event)">
                        Guest options <span id="eGuestOptsBadge" style="display:none;background:#2563eb;color:#fff;border-radius:999px;font-size:.68rem;font-weight:700;padding:.05rem .38rem"></span> <span style="font-size:.7rem">&#9662;</span>
                    </button>
                    <div class="rem-dd-panel go-panel" id="eGuestOptsPanel">
                        <label class="go-row" id="eWaitlistLabel" style="display:none">
                            <input type="checkbox" name="waitlist_enabled" id="eWaitlistEnabled" value="1" onchange="updateCapacityLine();updateGuestOptsBadge()">
                            <span><strong>Waitlist</strong><small>When the event is full, extra guests queue up and get promoted automatically</small></span>
                        </label>
                        <label class="go-row">
                            <input type="checkbox" name="requires_approval" id="eRequiresApproval" value="1" onchange="updateGuestOptsBadge()">
                            <span><strong>Require approval</strong><small>Walk-in QR and self sign-ups wait for your OK</small></span>
                        </label>
                        <label class="go-row">
                            <input type="checkbox" name="hide_guest_list" id="eHideGuestList" value="1" onchange="updateGuestOptsBadge()">
                            <span><strong>Hide guest list</strong><small>Guests can't see who else is coming</small></span>
                        </label>
                        <label class="go-row" id="eMaxGuestsLabel" style="display:none">
                            <span><strong>Max guests</strong><small>Blank = unlimited; with Waitlist on, extras queue up</small></span>
                            <input type="number" name="max_guests" id="eMaxGuests" min="1" max="999" placeholder="&#8734;" style="width:58px;padding:.22rem .35rem;border:1.5px solid #e2e8f0;border-radius:6px;font-size:.82rem" oninput="togglePokerFields();updateGuestOptsBadge()">
                        </label>
                    </div>
                </div>
                <span class="edit-desc-toggle" id="eDescToggle" onclick="toggleDesc()">+ Description</span>
                <div style="flex:1"></div>
                <button type="submit" class="btn btn-primary" id="eSubmitBtn" onclick="document.getElementById('eSendAfterSave').value=''"><?= $event ? 'Save Changes' : 'Add Event' ?></button>
                <?php if (get_setting('notifications_enabled', '0') === '1'): ?>
                <button type="submit" class="btn btn-primary" id="eSubmitSendBtn" style="background:#16a34a;border-color:#16a34a" onclick="document.getElementById('eSendAfterSave').value='1'" title="Save the event and send invitations now">Save &amp; Send Invites</button>
                <?php endif; ?>
                <a href="<?= htmlspecialchars($cancelUrl) ?>" class="btn btn-outline" style="text-decoration:none">Cancel</a>
            </div>

            <!-- ── Poker settings bar (inline, hidden by default) ── -->
            <div class="edit-poker-bar" id="ePokerFields" style="display:none">
                <label>Type <select name="poker_game_type" id="ePokerGameType"><option value="tournament">Tournament</option><option value="cash">Cash</option></select></label>
                <label>Buy-in $ <input type="number" name="poker_buyin" id="ePokerBuyin" min="0" step="1" value="20"></label>
                <label>Tables <input type="number" name="poker_tables" id="ePokerTables" min="1" max="50" value="1" onchange="updateCapacityLine()" oninput="updateCapacityLine()"></label>
                <label>Seats <input type="number" name="poker_seats" id="ePokerSeats" min="2" max="12" value="8" onchange="updateCapacityLine()" oninput="updateCapacityLine()"></label>
                <label>Deadline <select name="rsvp_deadline_hours" id="eRsvpDeadline"><option value="">None</option><option value="24">24h</option><option value="48">48h</option><option value="72">72h</option></select></label>
                <span id="eCapacityHint" style="font-weight:700;color:#2563eb">8 seats</span>
            </div>

            <!-- ── Reminders bar: dropdown of multi-select presets (hidden when reminders off) ── -->
            <div class="edit-poker-bar" id="eReminderFields">
                <span style="font-weight:600;color:#475569">Send reminders:</span>
                <div class="rem-dd" id="eRemDD">
                    <button type="button" class="rem-dd-btn" onclick="toggleRemDD(event)">
                        <span id="eRemSummary">&mdash;</span> <span style="font-size:.7rem">&#9662;</span>
                    </button>
                    <div class="rem-dd-panel" id="eRemPanel">
                        <?php foreach ($reminder_presets_available as $__off):
                            $__off = (int)$__off;
                            $__checked = in_array($__off, $reminder_default_offsets, true) ? 'checked' : '';
                            $__label = $__off >= 10080 && $__off % 10080 === 0 ? ($__off/10080 . ' wk')
                                    : ($__off >= 1440 && $__off % 1440 === 0 ? ($__off/1440 . ' day')
                                    : ($__off >= 60   && $__off % 60   === 0 ? ($__off/60   . ' hr')
                                    : ($__off . ' min')));
                        ?>
                        <label>
                            <input type="checkbox" name="reminder_offsets[]" class="eReminderPreset" value="<?= $__off ?>" <?= $__checked ?> onchange="updateRemSummary()">
                            <?= htmlspecialchars($__label) ?> before
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <style>
            .rem-dd { position: relative; display: inline-block; }
            .rem-dd-btn { display: inline-flex; align-items: center; gap: .35rem; padding: .35rem .7rem;
                border: 1.5px solid #cbd5e1; border-radius: 7px; background: #fff; font: inherit;
                font-size: .85rem; color: #334155; cursor: pointer; }
            .rem-dd-btn:hover { border-color: #93c5fd; }
            .rem-dd-panel { display: none; position: absolute; top: calc(100% + 4px); left: 0; z-index: 50;
                background: #fff; border: 1.5px solid #cbd5e1; border-radius: 9px; padding: .45rem .7rem;
                box-shadow: 0 8px 24px rgba(0,0,0,.12); min-width: 150px; }
            .rem-dd.open .rem-dd-panel { display: block; }
            .rem-dd-panel label { display: flex; align-items: center; gap: .4rem; font-size: .85rem;
                font-weight: 500; color: #334155; padding: .18rem 0; white-space: nowrap; cursor: pointer; }
            </style>

            <!-- ── Description (collapsed by default) ── -->
            <div class="edit-desc-wrap" id="eDescWrap" style="display:none">
                <textarea name="description" id="eDesc" rows="3" placeholder="Event description (optional)"></textarea>
            </div>

            <!-- ── Dual-pane invite panel with arrow buttons ── -->
            <div class="edit-invite-panel">
                <!-- Left: all users -->
                <div class="invite-pane">
                    <div class="invite-pane-header">All Users</div>
                    <input type="text" id="eUserSearch" class="invite-pane-search"
                           placeholder="<?= $isAdmin ? 'Search name, email, phone&hellip;' : 'Search name&hellip;' ?>"
                           oninput="filterAllUsers(this.value)" autocomplete="off">
                    <label id="eHideNonMembersWrap" style="display:none;align-items:center;gap:.4rem;padding:.25rem .65rem .35rem;font-size:.75rem;color:#64748b;cursor:pointer">
                        <input type="checkbox" id="eHideNonMembers" class="pk-toggle-input" onchange="onHideNonMembersChange()">
                        <span class="pk-toggle-sm"></span>
                        <span>Hide non-members</span>
                    </label>
                    <ul class="invite-pane-list" id="eAllUsersList"></ul>
                </div>
                <!-- Center: arrow buttons (desktop: left/right, mobile: up/down) -->
                <div class="invite-arrows">
                    <button type="button" class="inv-arrow-btn" onclick="moveRight()" title="Add selected"><span class="arrow-desktop">&rsaquo;</span><span class="arrow-mobile">&darr;</span></button>
                    <button type="button" class="inv-arrow-btn" onclick="moveAllRight()" title="Add all visible"><span class="arrow-desktop">&raquo;</span><span class="arrow-mobile">&dArr;</span></button>
                    <button type="button" class="inv-arrow-btn" onclick="moveLeft()" title="Remove selected"><span class="arrow-desktop">&lsaquo;</span><span class="arrow-mobile">&uarr;</span></button>
                    <button type="button" class="inv-arrow-btn" onclick="moveAllLeft()" title="Remove all"><span class="arrow-desktop">&laquo;</span><span class="arrow-mobile">&uArr;</span></button>
                </div>
                <!-- Right: invited users -->
                <div class="invite-pane">
                    <div class="invite-pane-header" style="display:flex;align-items:center;gap:.5rem">
                        <span style="flex:1">Invited</span>
                        <button type="button" class="btn btn-outline" style="font-size:.72rem;padding:.18rem .55rem" title="Invite someone who isn't a user — just a name, with optional email/phone" onclick="addBlankInviteRow()">+ Add Name</button>
                    </div>
                    <div class="inv-col-head">
                        <span style="flex:1;min-width:0">Name</span>
                        <span class="inv-col-contact" title="Click the icon on a row to add/edit email &amp; phone">Contact</span>
                        <span class="inv-col-rsvp">RSVP</span>
                        <span class="inv-col-mgr"></span>
                    </div>
                    <ul class="invite-pane-list" id="eInvitedList"></ul>
                </div>
            </div>
            <!-- Hidden inputs synced from invite lists -->
            <div id="eInviteData"></div>

            <!-- Mobile action bar: sticky at the bottom, after the invite picker
                 (the toolbar buttons above are hidden on small screens) -->
            <div class="edit-actions-mobile">
                <button type="submit" class="btn btn-primary" onclick="document.getElementById('eSendAfterSave').value=''"><?= $event ? 'Save Changes' : 'Add Event' ?></button>
                <?php if (get_setting('notifications_enabled', '0') === '1'): ?>
                <button type="submit" class="btn" style="background:#16a34a;border-color:#16a34a;color:#fff" onclick="document.getElementById('eSendAfterSave').value='1'" title="Save the event and send invitations now">Save &amp; Send</button>
                <?php endif; ?>
                <a href="<?= htmlspecialchars($cancelUrl) ?>" class="btn btn-outline" style="text-decoration:none">Cancel</a>
            </div>
        </form>
        <?php if ($isAdmin && $event && !$isCopy): ?>
        <div style="padding:.4rem 1rem .6rem;flex-shrink:0">
            <button type="button" id="eRegenWalkinBtn"
                    style="width:100%;padding:.38rem;border:1.5px solid #cbd5e1;border-radius:7px;background:#fff;color:#64748b;font-size:.78rem;cursor:pointer;font-weight:600"
                    onclick="regenWalkinFromEdit()">
                Regenerate walk-up link
            </button>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ── Per-invitee contact editor (email / phone) ── -->
<div class="modal-overlay" id="invContactModal" onclick="if(event.target===this)closeContactEdit()">
    <div class="modal" style="max-width:340px">
        <div class="modal-header" style="justify-content:space-between">
            <h2 style="font-size:1rem;font-weight:700">Contact for <span id="invContactName"></span></h2>
            <button class="modal-close" type="button" onclick="closeContactEdit()">&#x2715;</button>
        </div>
        <label style="display:block;font-size:.8rem;color:#475569;margin:.4rem 0 .15rem">Email</label>
        <input type="email" id="invContactEmail" autocomplete="off" placeholder="name@example.com"
               style="width:100%;padding:.5rem .6rem;border:1.5px solid #e2e8f0;border-radius:7px;font-size:.9rem;box-sizing:border-box">
        <label style="display:block;font-size:.8rem;color:#475569;margin:.6rem 0 .15rem">Phone</label>
        <input type="tel" id="invContactPhone" autocomplete="off" placeholder="(555) 123-4567"
               style="width:100%;padding:.5rem .6rem;border:1.5px solid #e2e8f0;border-radius:7px;font-size:.9rem;box-sizing:border-box">
        <div style="display:flex;gap:.5rem;margin-top:1rem">
            <button type="button" class="btn btn-primary" style="flex:1" onclick="saveContactEdit()">Save</button>
            <button type="button" class="btn btn-outline" style="flex:1" onclick="closeContactEdit()">Cancel</button>
        </div>
    </div>
</div>

<!-- ── Save / Send progress overlay (shown while the editor POSTs + queues invites) ── -->
<div id="saveSendOverlay" style="display:none;position:fixed;inset:0;z-index:10000;background:rgba(15,23,42,.55);align-items:center;justify-content:center;padding:1rem">
    <div style="background:#fff;border-radius:14px;padding:1.75rem 2rem;max-width:360px;width:100%;text-align:center;box-shadow:0 16px 48px rgba(0,0,0,.3)">
        <div id="saveSendTitle" style="font-size:1.05rem;font-weight:700;color:#1e293b;margin-bottom:.4rem">Saving event&hellip;</div>
        <div id="saveSendMsg" style="font-size:.85rem;color:#64748b;margin-bottom:1.1rem">Please wait.</div>
        <div style="height:9px;background:#e2e8f0;border-radius:99px;overflow:hidden">
            <div id="saveSendBar" style="height:100%;width:8%;background:#16a34a;border-radius:99px;transition:width .35s ease"></div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/_footer.php'; ?>

<script>
// ── Server-provided state ─────────────────────────────────────────────────────
const CSRF              = <?= json_encode($token, JSON_HEX_TAG) ?>;
const IS_ADMIN          = <?= $isAdmin ? 'true' : 'false' ?>;
const CAN_CREATE_EVENTS = <?= $canCreateEvents ? 'true' : 'false' ?>;
const CURRENT_USER_ID   = <?= json_encode($current['id'] ?? null, JSON_HEX_TAG) ?>;
const EVENT             = <?= $event ? json_encode($event, JSON_HEX_TAG) : 'null' ?>;
const EVENT_INVITES     = <?= json_encode($eventInvitesRows, JSON_HEX_TAG) ?>;
const EVENT_POKER       = <?= $pokerRow ? json_encode($pokerRow, JSON_HEX_TAG) : 'null' ?>;
const PREFILL_DATE      = <?= json_encode($prefillDate, JSON_HEX_TAG) ?>;
const LAST_POKER_DEFAULT = <?= ((int)($current['last_poker_default'] ?? 1)) ? 'true' : 'false' ?>;
var ALL_USERS           = <?= json_encode(array_values($allUsers), JSON_HEX_TAG) ?>;
var currentEvent        = EVENT; // name kept for parity with calendar.php's editor JS

// ── Color picker ──────────────────────────────────────────────────────────────
function toggleColorPicker(e) {
    e.stopPropagation();
    const picker = document.getElementById('eColorPicker');
    const dot    = document.getElementById('eColorDot');
    const open   = picker.classList.toggle('open');
    dot.classList.toggle('open', open);
}
function closeColorPicker() {
    const picker = document.getElementById('eColorPicker');
    const dot    = document.getElementById('eColorDot');
    if (picker) picker.classList.remove('open');
    if (dot)    dot.classList.remove('open');
}
document.addEventListener('click', e => {
    const wrap = document.getElementById('eColorDotWrap');
    if (wrap && !wrap.contains(e.target)) closeColorPicker();
});
function selectColor(c) {
    const colorInput = document.getElementById('eColor');
    const dot        = document.getElementById('eColorDot');
    if (colorInput) colorInput.value = c;
    if (dot) dot.style.background = c;
    document.querySelectorAll('#eColorPicker .color-swatch').forEach(s =>
        s.classList.toggle('selected', s.dataset.color === c));
    closeColorPicker();
}

// ── All-users pane ────────────────────────────────────────────────────────────
function buildAllUsersList() {
    const ul = document.getElementById('eAllUsersList');
    ul.innerHTML = '';
    const lgSel = document.getElementById('eLeagueId');
    const leagueSelected = !!(lgSel && parseInt(lgSel.value, 10) > 0);
    const hideWrap = document.getElementById('eHideNonMembersWrap');
    if (hideWrap) hideWrap.style.display = leagueSelected ? 'flex' : 'none';
    if (!leagueSelected) {
        const hideCb = document.getElementById('eHideNonMembers');
        if (hideCb) hideCb.checked = false;
    }
    ALL_USERS.forEach(u => {
        const display = u.display_name || u.username;
        // For pending invitees the synthetic username is a phone number or "pending:NN".
        // Use the human display_name as the saved invite_username so the invited row
        // shows a real name and the saved invite carries the name (not a phone).
        const savedName = u.is_pending ? (u.display_name || u.username || '') : (u.username || '');
        const li = document.createElement('li');
        li.dataset.username = (u.username || '').toLowerCase();
        li.dataset.email    = (u.email    || '').toLowerCase();
        li.dataset.phone    = (u.phone    || '').replace(/\D/g,'');
        li.dataset.display  = (display    || '').toLowerCase();
        li.dataset.uname    = savedName;
        li.dataset.uemail   = u.email     || '';
        li.dataset.uphone   = u.phone     || '';
        li.dataset.member   = u.is_league_member ? '1' : '0';
        li.textContent = display;
        if (u.is_pending) {
            const tag = document.createElement('span');
            tag.textContent = ' (pending)';
            tag.style.cssText = 'color:#92400e;font-size:.75rem;margin-left:.25rem';
            li.appendChild(tag);
        }
        if (leagueSelected) {
            const memTag = document.createElement('span');
            memTag.className = 'inv-mem-tag ' + (u.is_league_member ? 'inv-mem-yes' : 'inv-mem-no');
            memTag.textContent = u.is_league_member ? 'Member' : 'Not a member';
            li.appendChild(memTag);
        }
        li.title = 'Click to select, or double-click to invite';
        li.addEventListener('click', function(e) {
            // Touch/small screens: single tap toggles invited state directly
            // (the arrow strip is hidden there).
            if (window.matchMedia('(max-width: 1024px)').matches) {
                if (this.classList.contains('dimmed')) {
                    if (this.dataset.uname) removeInvite(this.dataset.uname);
                } else if (this.dataset.uname) {
                    inviteUser(this.dataset.uname, this.dataset.uphone, this.dataset.uemail);
                }
                return;
            }
            if (this.classList.contains('dimmed')) return;
            handleListSelect(e, this, 'eAllUsersList');
        });
        // Double-click a name to add them straight to the invited list (same as ›).
        li.addEventListener('dblclick', function(e) {
            if (this.classList.contains('dimmed') || !this.dataset.uname) return;
            e.preventDefault();
            if (window.getSelection) { try { window.getSelection().removeAllRanges(); } catch (err) {} }
            inviteUser(this.dataset.uname, this.dataset.uphone, this.dataset.uemail);
            this.classList.remove('inv-selected');
        });
        ul.appendChild(li);
    });
    // Re-apply the already-invited dimming/checkmarks every time the list is (re)built.
    syncInviteState();
}

// Fetch the scoped contact list for the current league selection and rebuild the pane.
function refreshUserList() {
    var lgSel = document.getElementById('eLeagueId');
    var leagueId = lgSel ? (parseInt(lgSel.value, 10) || 0) : 0;
    fetch('/calendar_contacts_dl.php?league_id=' + leagueId, { credentials: 'same-origin' })
        .then(r => r.json())
        .then(j => {
            if (j && j.ok) {
                ALL_USERS = j.users || [];
                buildAllUsersList();
                var searchEl = document.getElementById('eUserSearch');
                filterAllUsers(searchEl ? searchEl.value : '');
            }
        })
        .catch(() => pkAlert('Request failed — could not load the contact list.'));
}

function filterAllUsers(q) {
    const raw    = (q || '').toLowerCase();
    const digits = raw.replace(/\D/g,'');
    const hideCb = document.getElementById('eHideNonMembers');
    const hideNonMembers = !!(hideCb && hideCb.checked);
    document.querySelectorAll('#eAllUsersList li:not(.custom-row)').forEach(li => {
        const textMatch = !raw ||
            (li.dataset.username && li.dataset.username.includes(raw)) ||
            (li.dataset.display  && li.dataset.display.includes(raw))  ||
            (li.dataset.email    && li.dataset.email.includes(raw))    ||
            (digits && li.dataset.phone && li.dataset.phone.includes(digits));
        const memberMatch = !hideNonMembers || li.dataset.member === '1';
        li.style.display = (textMatch && memberMatch) ? '' : 'none';
    });
}

function onHideNonMembersChange() {
    const searchEl = document.getElementById('eUserSearch');
    filterAllUsers(searchEl ? searchEl.value : '');
}

// ── Invited pane ──────────────────────────────────────────────────────────────
function inviteUser(username, phone, email, rsvp, role, approvalStatus) {
    // Skip if already invited
    const existing = Array.from(document.querySelectorAll('#eInvitedList li[data-iname]'))
        .map(li => li.dataset.iname.toLowerCase());
    if (existing.includes(username.toLowerCase())) return;

    const li = document.createElement('li');
    li.dataset.iname    = username;
    li.dataset.iphone   = phone  || '';
    li.dataset.iemail   = email  || '';
    li.dataset.irsvp    = rsvp   || '';
    li.dataset.irole    = role   || 'invitee';
    li.dataset.istatus  = approvalStatus || 'approved';

    const nameSpan = document.createElement('span');
    nameSpan.textContent = username;
    nameSpan.className = 'inv-name-text';
    li.appendChild(nameSpan);

    // Clickable contact indicator: shows an email/phone glyph for whatever is on file (or
    // "NA" when neither). Click opens a small popup to edit this invitee's email + phone.
    const contactCtl = document.createElement('span');
    contactCtl.className = 'inv-contact-ctl inv-col-contact';
    contactCtl.title = 'Click to edit email / phone';
    contactCtl.innerHTML = invContactCtlInner(li.dataset.iemail, li.dataset.iphone);
    contactCtl.addEventListener('click', function(e) { e.stopPropagation(); openContactEdit(li); });
    contactCtl.addEventListener('dblclick', function(e) { e.stopPropagation(); });
    li.appendChild(contactCtl);

    // RSVP status badge — in a fixed-width column so the header lines up. Empty when no reply.
    const rsvpSlot = document.createElement('span');
    rsvpSlot.className = 'inv-col-rsvp';
    var badge = document.createElement('span');
    badge.className = 'inv-rsvp-badge';
    if (rsvp === 'yes')        { badge.textContent = 'Yes';    badge.classList.add('inv-rsvp-yes');    rsvpSlot.appendChild(badge); }
    else if (rsvp === 'no')    { badge.textContent = 'No';     badge.classList.add('inv-rsvp-no');     rsvpSlot.appendChild(badge); }
    else if (rsvp === 'maybe') { badge.textContent = 'Maybe';  badge.classList.add('inv-rsvp-maybe');  rsvpSlot.appendChild(badge); }
    else if (approvalStatus === 'waitlisted') { badge.textContent = 'Waitlist'; badge.classList.add('inv-rsvp-waitlist'); rsvpSlot.appendChild(badge); }
    li.appendChild(rsvpSlot);

    // Manager toggle column (fixed width; toggle shown to admins, the event creator, and —
    // on a brand-new event — the host creating it). The empty column keeps rows aligned.
    const mgrSlot = document.createElement('span');
    mgrSlot.className = 'inv-col-mgr';
    const editingEvId = parseInt(document.getElementById('eId').value) || 0;
    const editingCreatedBy = currentEvent ? currentEvent.created_by : null;
    const canGrantManager = IS_ADMIN || (!editingEvId && CAN_CREATE_EVENTS)
                            || (CURRENT_USER_ID && editingCreatedBy == CURRENT_USER_ID);
    if (canGrantManager) {
        const tog = document.createElement('label');
        tog.className = 'mgr-toggle';
        tog.title = 'Grant manager access';
        tog.innerHTML = '<input type="checkbox" class="pk-toggle-input mgr-toggle-cb"' + (li.dataset.irole === 'manager' ? ' checked' : '') + '><span class="pk-toggle-slider pk-toggle-sm"></span><span class="mgr-label">Mgr</span>';
        tog.querySelector('.mgr-toggle-cb').addEventListener('change', function(e) {
            e.stopPropagation();
            li.dataset.irole = this.checked ? 'manager' : 'invitee';
        });
        tog.addEventListener('click', function(e) { e.stopPropagation(); });
        tog.addEventListener('dblclick', function(e) { e.stopPropagation(); });
        mgrSlot.appendChild(tog);
    }
    li.appendChild(mgrSlot);

    // Explicit remove control (works on touch, where double-click doesn't exist)
    const xBtn = document.createElement('span');
    xBtn.className = 'inv-remove-x';
    xBtn.textContent = '×';
    xBtn.title = 'Remove from invite list';
    xBtn.addEventListener('click', function(e) { e.stopPropagation(); removeInvite(li.dataset.iname); });
    li.appendChild(xBtn);

    li.title = 'Click to select, or double-click to remove';
    li.addEventListener('click', function(e) {
        if (e.target.closest('.mgr-toggle')) return;
        handleListSelect(e, this, 'eInvitedList');
    });
    // Double-click a name to remove them from the invited list (same as ‹).
    li.addEventListener('dblclick', function(e) {
        if (e.target.closest('.mgr-toggle')) return;
        e.preventDefault();
        if (window.getSelection) { try { window.getSelection().removeAllRanges(); } catch (err) {} }
        removeInvite(this.dataset.iname);
    });

    // Drag-and-drop for priority ordering (poker events)
    li.draggable = true;
    li.addEventListener('dragstart', function(e) {
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', '');
        this.classList.add('inv-dragging');
    });
    li.addEventListener('dragend', function() {
        this.classList.remove('inv-dragging');
        updateDividerLine();
    });

    document.getElementById('eInvitedList').appendChild(li);
    syncInviteState();
    updateDividerLine();
}

// ── Per-invitee contact icon + inline editor ─────────────────────────────────
function invContactCtlInner(email, phone) {
    var hasE = !!(email && String(email).trim());
    var hasP = !!(phone && String(phone).trim());
    if (!hasE && !hasP) return '<span class="inv-na">NA</span>';
    var s = '';
    if (hasE) s += '<span class="inv-ci" title="Has email">&#9993;</span>';
    if (hasP) s += '<span class="inv-ci" title="Has phone">&#9742;</span>';
    return s;
}
var _contactEditLi = null;
function openContactEdit(li) {
    _contactEditLi = li;
    document.getElementById('invContactName').textContent = li.dataset.iname || 'invitee';
    document.getElementById('invContactEmail').value = li.dataset.iemail || '';
    document.getElementById('invContactPhone').value = li.dataset.iphone || '';
    document.getElementById('invContactModal').classList.add('open');
    setTimeout(function(){ document.getElementById('invContactEmail').focus(); }, 30);
}
function closeContactEdit() {
    document.getElementById('invContactModal').classList.remove('open');
    _contactEditLi = null;
}
function saveContactEdit() {
    if (!_contactEditLi) { closeContactEdit(); return; }
    _contactEditLi.dataset.iemail = document.getElementById('invContactEmail').value.trim();
    _contactEditLi.dataset.iphone = document.getElementById('invContactPhone').value.trim();
    var ctl = _contactEditLi.querySelector('.inv-contact-ctl');
    if (ctl) ctl.innerHTML = invContactCtlInner(_contactEditLi.dataset.iemail, _contactEditLi.dataset.iphone);
    closeContactEdit();
}

function removeInvite(username) {
    const li = Array.from(document.querySelectorAll('#eInvitedList li[data-iname]'))
        .find(l => l.dataset.iname.toLowerCase() === username.toLowerCase());
    if (li) li.remove();
    syncInviteState();
    updateDividerLine();
}

function addBlankInviteRow() {
    const ul = document.getElementById('eInvitedList');
    const li = document.createElement('li');
    li.className = 'custom-row';
    li.innerHTML = '<div class="custom-row-inner">' +
        '<input type="text" class="cr-name"    placeholder="Name *">' +
        '<input type="text" class="cr-contact" placeholder="Email or phone" autocomplete="off">' +
        '<button type="button" class="cr-remove" onclick="this.closest(\'li\').remove()">&times;</button>' +
        '</div>';
    ul.appendChild(li);
    li.querySelector('.cr-name').focus();
}

function syncInviteState() {
    const invited = Array.from(document.querySelectorAll('#eInvitedList li[data-iname]'))
        .map(li => li.dataset.iname.toLowerCase());
    document.querySelectorAll('#eAllUsersList li').forEach(li => {
        const isDimmed = invited.includes((li.dataset.uname || '').toLowerCase());
        li.classList.toggle('dimmed', isDimmed);
        li.title = isDimmed ? 'Already invited' : 'Double-click to invite';
    });
}

// ── Multi-select + arrow button handlers ─────────────────────────────────────
var _lastClickedAll = null;
var _lastClickedInv = null;

function handleListSelect(e, li, listId) {
    var ul = document.getElementById(listId);
    var items = Array.from(ul.querySelectorAll('li:not(.dimmed):not(.custom-row):not(.inv-capacity-divider):not(.inv-declined-divider):not(.inv-declined-item)'));

    if (e.shiftKey && (listId === 'eAllUsersList' ? _lastClickedAll : _lastClickedInv)) {
        // Range select
        var last = listId === 'eAllUsersList' ? _lastClickedAll : _lastClickedInv;
        var startIdx = items.indexOf(last);
        var endIdx = items.indexOf(li);
        if (startIdx > -1 && endIdx > -1) {
            var lo = Math.min(startIdx, endIdx), hi = Math.max(startIdx, endIdx);
            for (var i = lo; i <= hi; i++) items[i].classList.add('inv-selected');
        }
    } else if (e.ctrlKey || e.metaKey) {
        // Toggle single
        li.classList.toggle('inv-selected');
    } else {
        // Clear others, select this one
        items.forEach(function(el) { el.classList.remove('inv-selected'); });
        li.classList.add('inv-selected');
    }
    if (listId === 'eAllUsersList') _lastClickedAll = li;
    else _lastClickedInv = li;
}

function moveRight() {
    var selected = document.querySelectorAll('#eAllUsersList li.inv-selected:not(.dimmed)');
    selected.forEach(function(li) {
        inviteUser(li.dataset.uname, li.dataset.uphone, li.dataset.uemail);
        li.classList.remove('inv-selected');
    });
}
function moveAllRight() {
    var visible = document.querySelectorAll('#eAllUsersList li:not(.dimmed):not([style*="display: none"]):not([style*="display:none"])');
    visible.forEach(function(li) {
        if (li.dataset.uname) inviteUser(li.dataset.uname, li.dataset.uphone, li.dataset.uemail);
    });
}
function moveLeft() {
    var selected = Array.from(document.querySelectorAll('#eInvitedList li.inv-selected[data-iname]'));
    selected.forEach(function(li) {
        removeInvite(li.dataset.iname);
    });
}
function moveAllLeft() {
    var all = Array.from(document.querySelectorAll('#eInvitedList li[data-iname]'));
    all.forEach(function(li) {
        removeInvite(li.dataset.iname);
    });
}

// ── Drag-and-drop reorder + capacity divider ────────────────────────────────
(function() {
    var ul = document.getElementById('eInvitedList');
    if (!ul) return;
    ul.addEventListener('dragover', function(e) {
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        var dragging = ul.querySelector('.inv-dragging');
        if (!dragging) return;
        var afterEl = getDragAfterElement(ul, e.clientY);
        if (afterEl) ul.insertBefore(dragging, afterEl);
        else ul.appendChild(dragging);
    });
    function getDragAfterElement(container, y) {
        var items = Array.from(container.querySelectorAll('li[data-iname]:not(.inv-dragging)'));
        var closest = null, closestOffset = Number.NEGATIVE_INFINITY;
        items.forEach(function(child) {
            var box = child.getBoundingClientRect();
            var offset = y - box.top - box.height / 2;
            if (offset < 0 && offset > closestOffset) { closestOffset = offset; closest = child; }
        });
        return closest;
    }
})();

function updateDividerLine() {
    var ul = document.getElementById('eInvitedList');
    if (!ul) return;

    // Remove old dividers only (not the LI items themselves)
    ul.querySelectorAll('.inv-capacity-divider, .inv-declined-divider').forEach(function(el) { el.remove(); });
    // Reset declined styling on all items so we can re-sort
    ul.querySelectorAll('.inv-declined-item').forEach(function(li) {
        li.classList.remove('inv-declined-item');
        li.draggable = true;
        li.style.display = '';
    });

    var cap = getPokerCapacity();

    // Separate active (non-declined) from declined
    var allItems = Array.from(ul.querySelectorAll('li[data-iname]'));
    var active = [];
    var declined = [];
    allItems.forEach(function(li) {
        if (li.dataset.irsvp === 'no') {
            declined.push(li);
        } else {
            active.push(li);
        }
    });

    // Re-append active items first (preserves their order), then declined
    active.forEach(function(li) { ul.appendChild(li); });

    // Insert capacity divider among active items
    if (cap > 0 && active.length > cap) {
        var divider = document.createElement('li');
        divider.className = 'inv-capacity-divider';
        divider.textContent = '--- Seat cutoff (' + cap + ' seats) --- waitlist below ---';
        divider.draggable = false;
        ul.insertBefore(divider, active[cap]);
    }

    // Add declined section at the bottom
    if (declined.length > 0) {
        var decDivider = document.createElement('li');
        decDivider.className = 'inv-declined-divider';
        decDivider.innerHTML = '&blacktriangledown; Declined (' + declined.length + ')';
        decDivider.draggable = false;
        decDivider.onclick = function() { toggleDeclined(); };
        ul.appendChild(decDivider);

        declined.forEach(function(li) {
            li.classList.add('inv-declined-item');
            li.draggable = false;
            ul.appendChild(li);
        });
    }
}

function toggleDeclined() {
    var items = document.querySelectorAll('#eInvitedList .inv-declined-item');
    var allHidden = items.length > 0 && items[0].style.display === 'none';
    items.forEach(function(li) { li.style.display = allHidden ? '' : 'none'; });
    var divider = document.querySelector('.inv-declined-divider');
    if (divider) {
        var count = items.length;
        divider.innerHTML = (allHidden ? '&blacktriangledown;' : '&blacktriangleright;') + ' Declined (' + count + ')';
    }
}

// ── Time picker helpers ──────────────────────────────────────────────────────
function setTimePicker(hhmm) {
    if (!hhmm) {
        const now = new Date();
        hhmm = String(now.getHours()).padStart(2,'0') + ':' + String(now.getMinutes()).padStart(2,'0');
    }
    document.getElementById('eTimeNative').value = hhmm;
}
function getTimePicker() {
    return document.getElementById('eTimeNative').value || '';
}

// Progress overlay shown during the editor's full-page POST.
function showSaveSendOverlay(sending) {
    const ov = document.getElementById('saveSendOverlay');
    if (!ov) return;
    document.getElementById('saveSendTitle').textContent = sending ? 'Saving & sending invitations…' : 'Saving event…';
    document.getElementById('saveSendMsg').textContent   = sending ? 'Saving the event and sending invitation emails.' : 'Saving your changes.';
    const bar = document.getElementById('saveSendBar');
    let pct = 8; bar.style.width = pct + '%';
    ov.style.display = 'flex';
    const iv = setInterval(function() {
        pct += Math.max(1, (92 - pct) * 0.12);
        if (pct >= 92) { pct = 92; clearInterval(iv); }
        bar.style.width = pct + '%';
    }, 180);
}

document.getElementById('editForm').addEventListener('submit', function(e) {
    // Show the saving/sending progress overlay (this fires only after HTML5 validation passes).
    const _sas = document.getElementById('eSendAfterSave');
    showSaveSendOverlay(!!(_sas && _sas.value === '1'));

    // Sync time picker → hidden input
    const st = getTimePicker();
    document.getElementById('eStartTime').value = st;

    // Calculate end_time from start_time + duration
    const dur = parseFloat(document.getElementById('eDuration').value) || 0;
    if (st && dur > 0) {
        const [h, m] = st.split(':').map(Number);
        const total  = h * 60 + m + Math.round(dur * 60);
        const eh = Math.floor(total / 60) % 24;
        const em = total % 60;
        document.getElementById('eEndTime').value = String(eh).padStart(2,'0') + ':' + String(em).padStart(2,'0');
    } else {
        document.getElementById('eEndTime').value = '';
    }

    // Build hidden invite inputs from both panes
    const container = document.getElementById('eInviteData');
    container.innerHTML = '';
    function addHidden(name, val) {
        const inp = document.createElement('input');
        inp.type = 'hidden'; inp.name = name; inp.value = val;
        container.appendChild(inp);
    }
    // Regular invited users (order in DOM = priority order)
    var sortIdx = 0;
    document.querySelectorAll('#eInvitedList li[data-iname]').forEach(li => {
        sortIdx++;
        addHidden('invite_username[]',   li.dataset.iname);
        addHidden('invite_phone[]',      li.dataset.iphone);
        addHidden('invite_email[]',      li.dataset.iemail);
        addHidden('invite_rsvp[]',       li.dataset.irsvp);
        addHidden('invite_role[]',       li.dataset.irole || 'invitee');
        addHidden('invite_sort_order[]', sortIdx);
    });
    // Custom rows — host-typed invitees (not registered users). Single contact field:
    // auto-detect email (contains '@') vs phone (everything else).
    document.querySelectorAll('#eInvitedList li.custom-row').forEach(li => {
        const uname   = li.querySelector('.cr-name').value.trim();
        const contact = (li.querySelector('.cr-contact') || {value:''}).value.trim();
        if (!uname) return;
        let email = '', phone = '';
        if (contact) {
            if (contact.indexOf('@') !== -1) email = contact;
            else phone = contact;
        }
        sortIdx++;
        addHidden('invite_username[]',   uname);
        addHidden('invite_phone[]',      phone);
        addHidden('invite_email[]',      email);
        addHidden('invite_rsvp[]',       '');
        addHidden('invite_role[]',       'invitee');
        addHidden('invite_sort_order[]', sortIdx);
    });

    // Hold the actual navigation back a beat so the progress bar is visible.
    // form.submit() does NOT re-fire this 'submit' handler, so there's no loop.
    e.preventDefault();
    const _form = this;
    setTimeout(function() { _form.submit(); }, 1300);
});

function togglePokerFields() {
    var show = document.getElementById('eIsPoker').checked;
    document.getElementById('ePokerFields').style.display = show ? '' : 'none';
    // Max-guests cap applies to non-poker events (poker capacity = seats × tables).
    // The waitlist toggle shows for poker always, or for non-poker once a cap is set.
    var mg = document.getElementById('eMaxGuests');
    var mgVal = mg ? parseInt(mg.value, 10) || 0 : 0;
    if (mg) document.getElementById('eMaxGuestsLabel').style.display = show ? 'none' : '';
    document.getElementById('eWaitlistLabel').style.display = (show || mgVal > 0) ? '' : 'none';
    if (show) updateCapacityLine();
    else updateDividerLine(); // clear divider when poker is off
    updateGuestOptsBadge(); // runs post-populate on edits, keeps the count honest
}

function toggleReminderFields() {
    var el = document.getElementById('eRemindersEnabled');
    var bar = document.getElementById('eReminderFields');
    if (!el || !bar) return;
    bar.style.display = el.checked ? '' : 'none';
}

// Apply a list of offset values (array of ints) to the reminder checkboxes.
function applyReminderOffsets(offsets) {
    var boxes = document.querySelectorAll('.eReminderPreset');
    if (offsets === null) { updateRemSummary(); return; } // keep the site-default pre-checked state
    var set = {};
    offsets.forEach(function(o) { set[parseInt(o,10)] = true; });
    boxes.forEach(function(b) { b.checked = !!set[parseInt(b.value,10)]; });
    updateRemSummary();
}

// Reminder dropdown: button shows a summary of the checked presets.
function toggleRemDD(e) {
    if (e) e.stopPropagation();
    document.getElementById('eRemDD').classList.toggle('open');
}

// Guest-options dropdown: badge shows how many options are active.
function toggleGuestOptsDD(e) {
    if (e) e.stopPropagation();
    document.getElementById('eGuestOptsDD').classList.toggle('open');
}
function updateGuestOptsBadge() {
    var n = 0;
    ['eWaitlistEnabled', 'eRequiresApproval', 'eHideGuestList'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el && el.checked) n++;
    });
    var mg = document.getElementById('eMaxGuests');
    if (mg && (parseInt(mg.value, 10) || 0) > 0) n++;
    var badge = document.getElementById('eGuestOptsBadge');
    if (badge) {
        badge.textContent = n;
        badge.style.display = n > 0 ? '' : 'none';
    }
}
function updateRemSummary() {
    var labels = [];
    document.querySelectorAll('#eRemPanel label').forEach(function(l) {
        var cb = l.querySelector('.eReminderPreset');
        if (cb && cb.checked) labels.push(l.textContent.trim().replace(/\s+before$/, ''));
    });
    document.getElementById('eRemSummary').textContent = labels.length ? labels.join(', ') : 'None';
}
document.addEventListener('click', function(e) {
    ['eRemDD', 'eGuestOptsDD'].forEach(function(id) {
        var dd = document.getElementById(id);
        if (dd && dd.classList.contains('open') && !dd.contains(e.target)) dd.classList.remove('open');
    });
});
document.addEventListener('DOMContentLoaded', function() { updateRemSummary(); updateGuestOptsBadge(); });
function toggleDesc() {
    var wrap = document.getElementById('eDescWrap');
    var tog  = document.getElementById('eDescToggle');
    if (wrap.style.display === 'none') {
        wrap.style.display = '';
        tog.textContent = '- Hide description';
        document.getElementById('eDesc').focus();
    } else {
        wrap.style.display = 'none';
        tog.textContent = '+ Description';
    }
}
function updateCapacityLine() {
    var tables = parseInt(document.getElementById('ePokerTables').value, 10) || 1;
    var seats  = parseInt(document.getElementById('ePokerSeats').value, 10) || 8;
    var cap    = tables * seats;
    document.getElementById('eCapacityHint').textContent = 'Capacity: ' + cap + ' seat' + (cap !== 1 ? 's' : '');
    if (typeof updateDividerLine === 'function') updateDividerLine();
}
function getPokerCapacity() {
    if (!document.getElementById('eIsPoker').checked) return 0;
    if (!document.getElementById('eWaitlistEnabled').checked) return 0;
    var tables = parseInt(document.getElementById('ePokerTables').value, 10) || 1;
    var seats  = parseInt(document.getElementById('ePokerSeats').value, 10) || 8;
    return tables * seats;
}

function onLeagueChange() {
    var lgSel  = document.getElementById('eLeagueId');
    var visSel = document.getElementById('eVisibility');
    var lgOpt  = document.getElementById('eVisLeagueOpt');
    if (!lgSel || !visSel || !lgOpt) return;
    var hasLeague = lgSel.value && lgSel.value !== '0';
    lgOpt.disabled = !hasLeague;
    if (hasLeague) {
        // If a league is picked, default visibility to league members only (matches the league's default)
        var opt = lgSel.options[lgSel.selectedIndex];
        var defVis = opt ? (opt.getAttribute('data-default-visibility') || 'league') : 'league';
        visSel.value = defVis;
    } else {
        // No league selected — fall back to invitees_only if the current selection requires a league
        if (visSel.value === 'league') visSel.value = 'invitees_only';
    }
    // Scope the invite picker to the newly-selected league (or personal network when 0).
    if (typeof refreshUserList === 'function') refreshUserList();
    // Re-fetch remembered poker defaults scoped to the new league (new events only).
    if (_isNewEventOpen) loadPokerDefaultsIntoEditor();
}

var _isNewEventOpen = false;

// Fetch the caller's last-used poker session defaults (scoped to the currently-selected league)
// and populate the editor's poker fields. Only used when creating a NEW event.
function loadPokerDefaultsIntoEditor() {
    var lgSel = document.getElementById('eLeagueId');
    var leagueId = (lgSel && lgSel.value && lgSel.value !== '0') ? parseInt(lgSel.value, 10) : 0;
    var qs = leagueId ? '?league_id=' + leagueId : '';
    fetch('/checkin_dl.php?action=get_session_defaults' + qs)
        .then(function(r) { return r.json(); })
        .then(function(j) {
            if (!j.ok || !j.defaults) return;
            var d = j.defaults;
            var gt = document.getElementById('ePokerGameType'); if (gt) gt.value = d.game_type || 'tournament';
            var by = document.getElementById('ePokerBuyin');    if (by) by.value = Math.round((d.buyin_amount || 2000) / 100);
            var tb = document.getElementById('ePokerTables');   if (tb) tb.value = d.num_tables || 1;
            var st = document.getElementById('ePokerSeats');    if (st) st.value = d.seats_per_table || 8;
            if (typeof updateCapacityLine === 'function') updateCapacityLine();
        });
}

<?php if ($isAdmin && $event && !$isCopy): ?>
function regenWalkinFromEdit() {
    var btn = document.getElementById('eRegenWalkinBtn');
    btn.textContent = 'Regenerating…';
    btn.disabled = true;
    var fd = new FormData();
    fd.append('action', 'regenerate_walkin_token');
    fd.append('csrf_token', CSRF);
    fd.append('event_id', EVENT.id);
    fetch('/calendar.php', { method: 'POST', body: fd,
        headers: { 'X-Requested-With': 'XMLHttpRequest' } })
    .then(function(r) { return r.json(); })
    .then(function(j) {
        if (j.ok && j.walkin_token) {
            btn.textContent = 'Link regenerated!';
        } else {
            btn.textContent = 'Failed — try again';
        }
        setTimeout(function() { btn.textContent = 'Regenerate walk-up link'; btn.disabled = false; }, 2500);
    });
}
<?php endif; ?>

// ── Initialize the editor (page equivalent of openEditModal) ─────────────────
(function initEditor() {
    var ev = EVENT;
    document.getElementById('eTitle').value     = ev ? (ev.title || '') : '';
    document.getElementById('eStartDate').value = ev ? (ev.start_date_input || ev.start_date)
                                                     : (PREFILL_DATE || new Date().toLocaleDateString('en-CA'));
    setTimePicker(ev ? (ev.start_time_input || ev.start_time || '') : '');
    document.getElementById('eLocation').value  = ev ? (ev.location || '') : '';
    document.getElementById('eDesc').value      = ev ? (ev.description || '') : '';
    var hasDesc = ev && ev.description && ev.description.trim() !== '';
    document.getElementById('eDescWrap').style.display = hasDesc ? '' : 'none';
    document.getElementById('eDescToggle').textContent = hasDesc ? '- Hide description' : '+ Description';
    document.getElementById('eIsPoker').checked = ev ? !!parseInt(ev.is_poker) : LAST_POKER_DEFAULT;
    document.getElementById('eRequiresApproval').checked = ev ? !!parseInt(ev.requires_approval) : false;
    document.getElementById('eHideGuestList').checked = ev ? !!parseInt(ev.hide_guest_list) : false;
    // Pre-fill poker session fields
    var ps = EVENT_POKER;
    document.getElementById('ePokerGameType').value = ps ? ps.game_type : 'tournament';
    document.getElementById('ePokerBuyin').value    = ps ? Math.round(parseInt(ps.buyin_amount,10)/100) : '20';
    document.getElementById('ePokerTables').value   = ps ? ps.num_tables : '1';
    document.getElementById('ePokerSeats').value    = ps ? ps.seats_per_table : '8';
    document.getElementById('eRsvpDeadline').value  = (ev && ev.rsvp_deadline_hours) ? String(ev.rsvp_deadline_hours) : '';
    document.getElementById('eWaitlistEnabled').checked = ev ? !!(parseInt(ev.waitlist_enabled) || ev.waitlist_enabled === null) : false;
    document.getElementById('eMaxGuests').value = (ev && ev.max_guests) ? ev.max_guests : '';
    togglePokerFields();

    // Reminder config: on for new events; for edits, respect the stored toggle.
    var remEnabled = ev ? (parseInt(ev.reminders_enabled ?? 1) === 1) : true;
    document.getElementById('eRemindersEnabled').checked = remEnabled;
    if (ev && ev.reminder_offsets) {
        try {
            var parsed = JSON.parse(ev.reminder_offsets);
            if (Array.isArray(parsed)) applyReminderOffsets(parsed);
        } catch (e) { /* leave defaults */ }
    }
    toggleReminderFields();
    // Flag for onLeagueChange so it knows this is a fresh-event open (not an existing session)
    _isNewEventOpen = !ev;
    // League + visibility
    var lgSel  = document.getElementById('eLeagueId');
    var visSel = document.getElementById('eVisibility');
    if (lgSel && visSel) {
        lgSel.value  = (ev && ev.league_id)  ? String(ev.league_id)  : '0';
        visSel.value = (ev && ev.visibility) ? ev.visibility         : 'invitees_only';
        onLeagueChange();
        if (ev && ev.visibility) visSel.value = ev.visibility;
    }

    // Pre-fill duration from start_time/end_time diff (viewer-tz inputs).
    const dur = document.getElementById('eDuration');
    const _st_in = ev ? (ev.start_time_input || ev.start_time) : '';
    const _et_in = ev ? (ev.end_time_input   || ev.end_time)   : '';
    if (ev && _st_in && _et_in) {
        const [sh, sm] = _st_in.split(':').map(Number);
        const [eh, em] = _et_in.split(':').map(Number);
        const diff = (eh * 60 + em) - (sh * 60 + sm);
        dur.value = diff > 0 ? (diff / 60) : '';
    } else {
        dur.value = '';
    }

    selectColor((ev && ev.color) ? ev.color : '#2563eb');

    // Build panes: invited list from server rows, all-users list (admin preload or async).
    buildAllUsersList();
    document.getElementById('eInvitedList').innerHTML = '';
    EVENT_INVITES.forEach(function(inv) {
        inviteUser(inv.username, inv.phone || '', inv.email || '', inv.rsvp || '', inv.event_role || 'invitee', inv.approval_status || 'approved');
    });
    syncInviteState();
    filterAllUsers('');
    updateDividerLine();
    // Fetch the scoped contact list for the current league selection.
    refreshUserList();
    document.getElementById('eTitle').focus();
})();
</script>
</body>
</html>
