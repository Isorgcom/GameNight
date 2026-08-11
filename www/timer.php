<?php
/* ============================================================================
 * TABLE OF CONTENTS — www/timer.php (~5,300 lines, current as of v0.19313)
 * ============================================================================
 * Line numbers drift after edits; §N.M tags stay stable. Ctrl+F any §N.M tag
 * (e.g. "§7.5") to jump straight to that section's banner.
 *
 *  §1    PHP head — auth, mode detect (remote/event/standalone), DB queries
 *  §2    <head> — meta, fonts, themeStyle, window.TIMER_THEME, inline <style>
 *  §3    Inline <style> (~986 lines)
 *          §3.1  :root CSS variables
 *          §3.2  .timer-body / .timer-container / .timer-display
 *          §3.x  .timer-clock, .timer-tray, layout-edit chrome,
 *                modal overlays, preset gallery, player panel, QR, stream,
 *                @media responsive blocks, @keyframes
 *  §4    Body markup
 *          §4.2  layout-edit pill + inspector + snap guides
 *          §4.5  main timer container (info bar, display, tray)
 *          §4.7  player panel (gated)
 *          §4.8  QR / image / streaming embeds
 *  §5    Modal overlays (~9 modals)
 *          §5.1  #levelsOverlay        blind-structure editor
 *          §5.2  #closeConfirmOverlay  discard / keep editing dialog
 *          §5.3  #savePresetOverlay    save blind preset as
 *          §5.4  #genOverlay           blind-structure generator
 *          §5.5  #themeOverlay         theme library
 *          §5.6  #presetOverlay        theme preset gallery (v0.19310)
 *          §5.7  #saveThemeOverlay     save theme as
 *          §5.8  #confirmSaveOverlay   theme save confirm
 *          §5.9  #soundOverlay         sound settings
 *  §6    Vendor <script nonce="<?= csp_nonce() ?>">s — qrcode.min.js, nosleep.min.js
 *  §7    Inline <script nonce="<?= csp_nonce() ?>"> (~3,600 lines)
 *          §7.1   Config from PHP (CSRF, TIMER, LEVELS, SOUNDS, IS_REMOTE, ...)
 *          §7.2   Formatters (fmtTime, fmtMoney, fmtChips, fmtBreakClock, ...)
 *          §7.3   Render (renderAll, renderClock, renderPlayBtn, appendTimerId)
 *          §7.4   Commands (sendCommand + togglePlay/skipLevel/adjustTime/...)
 *          §7.5   pollState (server sync, 2 s interval)
 *          §7.6   Local tick (smooth 100 ms countdown between polls)
 *          §7.7   Wake lock (NoSleep + Wake Lock API + banner)
 *          §7.8   Sound alert (Web Audio, presets + custom uploads)
 *          §7.9   Sound settings modal (upload, save, preview)
 *          §7.10  Levels editor (open/close, render levels table)
 *          §7.11  Drag & drop reorder (HTML5 drag + touch up/down buttons)
 *          §7.12  Insert / add / remove / save levels
 *          §7.13  Draft autosave (localStorage, dirty flag, restore on open)
 *          §7.14  Blind generator + blind-preset CRUD
 *          §7.15  Theme editor (applyTheme, library, preset gallery, save, import/export)
 *          §7.16  Layout-edit mode (enter/exit, drag/snap, multi-select, guides)
 *          §7.17  Inspector panel (per-element + page-level property controls)
 *          §7.17.1 Objects panel (list all elements incl. hidden; visibility
 *                   toggle, select, and layer/z-index drag-reorder)
 *          §7.18  Panel drag helper (pill + inspector, shared makePanelDraggable)
 *          §7.19  Init (window.load, intervals, audio unlock, QR generation)
 *          §7.20  Player panel (toggle, fetch, render, ppXxx checkin wrappers)
 *          §7.21  Blind structure CSV export / import
 *  §8    Closing </script></body></html>
 * ============================================================================
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_poker_helpers.php';
require_once __DIR__ . '/_timer_theme.php';

$db = get_db();
$site_name = get_setting('site_name', 'Game Night');

$is_remote = false;
$is_guest = false;
$is_standalone = false;       // timer opened with no event link (no pool/players)
$linkable_events = [];        // events the host can link this standalone timer to
$is_display = isset($_GET['display']) && $_GET['display'] === '1';
$can_control = false;
$session = null;
$event = null;
$timer = null;
$levels = [];
$pool = [];
$payouts = [];
$game_type = null;
$remote_key = '';
$csrf = '';

// ─── §1.1  Remote viewer/controller mode ────────────────────────
if (isset($_GET['view']) && $_GET['view'] === 'remote' && !empty($_GET['key'])) {
    $is_remote = true;
    $remote_key = $_GET['key'];

    $ts = $db->prepare('SELECT * FROM timer_state WHERE remote_key = ?');
    $ts->execute([$remote_key]);
    $timer = $ts->fetch();
    if (!$timer) {
        // This markup is a PHP string, not template output, so the cache-buster
        // has to be concatenated. A short-echo tag here is never evaluated, and
        // its quotes close the string, which made the whole file unparseable.
        // (Do not name the closing tag in a comment either: it ends PHP mode.)
        echo '<!DOCTYPE html><html><head><title>Invalid Link</title><link rel="stylesheet" href="/style.css?v='
           . htmlspecialchars(APP_VERSION . '.' . (@filemtime(__DIR__ . '/style.css') ?: 0))
           . '"></head><body style="display:flex;align-items:center;justify-content:center;min-height:100vh;background:#0f172a;color:#fff"><div class="card" style="text-align:center"><h2>Invalid Timer Link</h2><p>This timer link is no longer valid.</p></div></body></html>';
        exit;
    }

    $session_id = (int)$timer['session_id'];
    $sess = $db->prepare('SELECT ps.*, e.title as event_title, e.id as event_id FROM poker_sessions ps JOIN events e ON ps.event_id = e.id WHERE ps.id = ?');
    $sess->execute([$session_id]);
    $session = $sess->fetch();

    if ($timer['preset_id']) {
        $lvl = $db->prepare('SELECT * FROM blind_preset_levels WHERE preset_id = ? ORDER BY level_number');
        $lvl->execute([$timer['preset_id']]);
        $levels = $lvl->fetchAll(PDO::FETCH_ASSOC);
    }

    $pool = calc_pool($db, $session_id);
    $game_type = $session['game_type'] ?? null;
    $payouts = ($game_type === 'tournament') ? get_payouts($db, $session_id) : [];

    // Load event for remote access
    if ($session) {
        $evStmt = $db->prepare('SELECT * FROM events WHERE id = ?');
        $evStmt->execute([(int)$session['event_id']]);
        $event = $evStmt->fetch();
    }

    // Check if logged-in user can control. The remote key alone is view-only;
    // control requires manage rights on the event (host/manager/admin) so a
    // guest scanning the table QR can't pause or skip levels.
    $current = current_user();
    if ($current) {
        $isAdmin = $current['role'] === 'admin';
        $can_control = $session && can_manage_event($db, (int)$session['event_id'], (int)$current['id'], $isAdmin);
        $csrf = csrf_token();
    }

// ─── §1.2  Host mode ────────────────────────────────────────────
} else {
    $current = current_user();
    $isAdmin = $current ? $current['role'] === 'admin' : false;
    $is_guest = !$current;

    $event_id = (int)($_GET['event_id'] ?? 0);

    if ($event_id) {
        // Event-linked timer requires login
        if (!$current) { header('Location: /login.php?redirect=' . urlencode($_SERVER['REQUEST_URI'])); exit; }
        verify_event_access($db, $event_id, $current, $isAdmin);

        $ev = $db->prepare('SELECT * FROM events WHERE id = ?');
        $ev->execute([$event_id]);
        $event = $ev->fetch();

        $sess = $db->prepare('SELECT * FROM poker_sessions WHERE event_id = ?');
        $sess->execute([$event_id]);
        $session = $sess->fetch();

        if (!$session) {
            header('Location: /checkin.php?event_id=' . $event_id);
            exit;
        }

        // Initialize timer if needed
        $ts = $db->prepare('SELECT * FROM timer_state WHERE session_id = ?');
        $ts->execute([$session['id']]);
        $timer = $ts->fetch();

        if (!$timer) {
            $preset = $db->prepare('SELECT id FROM blind_presets WHERE is_default = 1 LIMIT 1');
            $preset->execute();
            $defaultPreset = $preset->fetch();
            $preset_id = $defaultPreset ? (int)$defaultPreset['id'] : null;

            $duration = 900;
            if ($preset_id) {
                $flvl = $db->prepare('SELECT duration_minutes FROM blind_preset_levels WHERE preset_id = ? AND level_number = 1');
                $flvl->execute([$preset_id]);
                $fl = $flvl->fetch();
                if ($fl) $duration = (int)$fl['duration_minutes'] * 60;
            }

            $remote_key = bin2hex(random_bytes(8));
            $db->prepare("INSERT INTO timer_state (session_id, preset_id, current_level, time_remaining_seconds, is_running, remote_key, updated_at) VALUES (?, ?, 1, ?, 0, ?, datetime('now'))")
                ->execute([$session['id'], $preset_id, $duration, $remote_key]);

            $ts->execute([$session['id']]);
            $timer = $ts->fetch();
        }

        $pool = calc_pool($db, (int)$session['id']);
        $game_type = $session['game_type'] ?? null;
        $payouts = ($game_type === 'tournament') ? get_payouts($db, (int)$session['id']) : [];
        $session['event_title'] = $event['title'];

    } else {
        // Standalone timer — works for logged-in users AND guests
        session_start_safe();
        if ($current) {
            $standalone_sid = -1 * (int)$current['id'];
        } else {
            // Guest: use session-based ID (negative, large to avoid collision with user IDs)
            $standalone_sid = -1 * abs(crc32(session_id()));
        }

        $ts = $db->prepare('SELECT * FROM timer_state WHERE session_id = ?');
        $ts->execute([$standalone_sid]);
        $timer = $ts->fetch();

        if (!$timer) {
            $preset = $db->prepare('SELECT id FROM blind_presets WHERE is_default = 1 LIMIT 1');
            $preset->execute();
            $defaultPreset = $preset->fetch();
            $preset_id = $defaultPreset ? (int)$defaultPreset['id'] : null;

            $duration = 900;
            if ($preset_id) {
                $flvl = $db->prepare('SELECT duration_minutes FROM blind_preset_levels WHERE preset_id = ? AND level_number = 1');
                $flvl->execute([$preset_id]);
                $fl = $flvl->fetch();
                if ($fl) $duration = (int)$fl['duration_minutes'] * 60;
            }

            $remote_key = bin2hex(random_bytes(8));
            $user_id = $current ? (int)$current['id'] : 0;
            $db->prepare("INSERT INTO timer_state (session_id, preset_id, current_level, time_remaining_seconds, is_running, remote_key, user_id, updated_at) VALUES (?, ?, 1, ?, 0, ?, ?, datetime('now'))")
                ->execute([$standalone_sid, $preset_id, $duration, $remote_key, $user_id]);

            $ts->execute([$standalone_sid]);
            $timer = $ts->fetch();
        }

        $session = null;
        $event = null;
        $pool = null;
        $payouts = [];
        $game_type = null;
        $is_standalone = true;
        // Offer to link this timer to one of the host's poker games so its
        // prize pool / players sync (this is the "not linked" recovery path).
        if ($current) {
            $linkable_events = user_poker_events($db, (int)$current['id'], $isAdmin);
        }
    }

    $remote_key = $timer['remote_key'];

    if ($timer['preset_id']) {
        $lvl = $db->prepare('SELECT * FROM blind_preset_levels WHERE preset_id = ? ORDER BY level_number');
        $lvl->execute([$timer['preset_id']]);
        $levels = $lvl->fetchAll(PDO::FETCH_ASSOC);
    }

    // Event-linked timers: control requires manage rights (host/manager/admin);
    // invitees who open the timer page get a view-only screen. Standalone
    // timers are controlled by whoever owns them (enforced above by lookup).
    if ($event) {
        $can_control = can_manage_event($db, (int)$event['id'], (int)$current['id'], $isAdmin);
    } else {
        $can_control = true;
    }
    $csrf = csrf_token();
    $is_guest = !$current;
}

// Compute corrected remaining time. updated_at is stored as SQLite
// datetime('now') (UTC, no tz suffix); strtotime() would otherwise re-parse
// it in the configured local timezone and the initial PHP render would be
// off by the local UTC offset (e.g. ~300 min ahead in America/Chicago)
// until the first JS poll corrects it.
$remaining = (int)($timer['time_remaining_seconds'] ?? 0);
if ((int)($timer['is_running'] ?? 0) && !empty($timer['updated_at'])) {
    $elapsed = time() - strtotime($timer['updated_at'] . ' UTC');
    $remaining = max(0, $remaining - $elapsed);
}

// Resolve active theme for first-paint CSS variables + JS state.
$themeId   = (int)($timer['theme_id'] ?? 0) ?: null;
$themeProps = timer_resolve_theme($db, $themeId);
$themeCss   = timer_theme_css_vars($themeProps);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <!-- iPhone Safari has no Fullscreen API (iPad does; the Full button is hidden
         on iPhone for that reason). Add to Home Screen is the only way to lose
         the browser chrome there, and these are what make it launch chrome-free
         and edge-to-edge rather than in a plain Safari window. -->
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Poker Timer">
    <title>Poker Timer &mdash; <?= htmlspecialchars($site_name) ?></title>
    <link rel="icon" href="/favicon.php">
    <link rel="stylesheet" href="/style.css?v=<?= htmlspecialchars(APP_VERSION . '.' . (@filemtime(__DIR__ . '/style.css') ?: 0)) ?>">
    <link rel="stylesheet" href="/vendor/fonts/fonts.css">
    <script nonce="<?= csp_nonce() ?>">window.TIMER_THEME = <?= json_encode($themeProps, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>; window.TIMER_THEME_ID = <?= $themeId ? (int)$themeId : 'null' ?>;</script>
    <style id="themeStyle"><?= $themeCss ?></style>
    <link rel="stylesheet" href="/timer.css?v=<?= htmlspecialchars(APP_VERSION . '.' . (@filemtime(__DIR__ . '/timer.css') ?: 0)) ?>">
</head>
<body class="timer-body<?= $is_display ? ' display-mode' : '' ?>">

<?php if (!$is_remote && !$is_guest): ?>
<!-- Center guides (visible while in layout-edit mode). -->
<div class="center-guide-v" id="centerGuideV"></div>
<div class="center-guide-h" id="centerGuideH"></div>
<div class="align-guide-v" id="alignGuideV"></div>
<div class="align-guide-h" id="alignGuideH"></div>
<div class="snap-hint">Hold <b>Shift</b> to disable snap &nbsp;·&nbsp; <b>Ctrl</b>/<b>Cmd</b>+click to multi-select &amp; drag together</div>

<!-- Floating control while in free-form layout edit mode (draggable). -->
<div class="layout-edit-pill" id="layoutEditPill">
    <span class="pill-handle" id="pillHandle" title="Drag to move toolbar">&#9776;</span>
    <button type="button" data-act="openObjectsPanel" title="Show / hide / select objects">&#128203; Objects</button>
    <button type="button" data-act="openThemes" title="Load / save themes">&#128218; Library</button>
    <button class="btn-done" type="button" data-act="exitLayoutEdit" data-a1="true">&#10003; Save</button>
    <button type="button" data-act="resetPositions" title="Snap elements back to default positions">&#8635; Reset</button>
    <button type="button" id="snapToggleBtn" data-act="toggleSnap" title="Toggle snap (Shift = momentary off on a keyboard)">&#129522; Snap</button>
    <span class="pill-sep"></span>
    <button type="button" data-act="exitLayoutEdit" data-a1="false">&times; Close</button>
</div>

<!-- Inspector for the selected element (draggable). -->
<div class="layout-inspector" id="layoutInspector">
    <div class="layout-inspector-header" id="inspectorHeader">
        <h4 id="inspectorTitle">Element</h4>
        <button class="layout-inspector-close" type="button" data-act="closeInspector" title="Close">&times;</button>
    </div>
    <div class="layout-inspector-body" id="inspectorBody"></div>
</div>

<!-- Objects panel — list of every theme element, including hidden ones (only way to see + un-hide them). -->
<div class="layout-objects-panel" id="layoutObjectsPanel">
    <div class="layout-objects-header" id="objectsHeader">
        <h4>Objects</h4>
        <button class="layout-inspector-close" type="button" data-act="closeObjectsPanel" title="Close">&times;</button>
    </div>
    <div class="layout-objects-body" id="objectsBody"></div>
</div>
<?php endif; ?>

<!-- Wake lock status (auto-hides) -->
<div id="wakeBanner" style="position:fixed;bottom:0;left:0;right:0;background:#1e293b;color:#fbbf24;text-align:center;padding:6px;font-size:0.8rem;z-index:999;border-top:1px solid #334155;transition:opacity 0.5s;pointer-events:none">
    Tap anywhere to keep screen on
</div>

<?php if (!$is_remote): ?>
<?php if ($event): ?>
<a class="timer-back" href="/checkin.php?event_id=<?= (int)$event['id'] ?>">&larr; Back to Check-in</a>
<?php else: ?>
<a class="timer-back" href="/">&larr; Home</a>
<?php endif; ?>
<?php endif; ?>

<?php if ($is_standalone && !empty($linkable_events)): ?>
<div class="timer-link-banner" id="timerLinkBanner">
    <span class="tlb-text">&#9888; This timer isn't linked to an event, so the prize pool and players won't show.</span>
    <span class="tlb-controls">
        <select id="timerLinkSelect" class="tlb-select" aria-label="Link timer to event">
            <option value="">Link to an event&hellip;</option>
            <?php foreach ($linkable_events as $le): ?>
            <option value="<?= (int)$le['id'] ?>"><?= htmlspecialchars($le['title']) ?><?= ($le['session_status'] ?? '') === 'active' ? ' (live)' : '' ?> &mdash; <?= htmlspecialchars($le['start_date']) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="button" class="tlb-btn" data-act="linkTimerToEvent">Link</button>
    </span>
</div>
<?php endif; ?>

<div class="timer-container">
    <!-- Average stack display (tournaments only) -->
    <div class="timer-avgstack" id="avgStackWrap" style="display:none">
        <div class="timer-avgstack-title">Avg Stack</div>
        <div><b id="avgStackValue">-</b></div>
    </div>

    <!-- Payout display (tournaments only) -->
    <div class="timer-payouts" id="payoutsWrap" style="display:none">
        <div class="timer-payouts-title">Payouts</div>
        <div id="payoutsBody"></div>
    </div>

    <!-- Info bar -->
    <div class="timer-info-bar">
        <span class="timer-event-name" id="eventName"><?= htmlspecialchars($session['event_title'] ?? 'Tournament Timer') ?></span>
        <span class="timer-stat" id="playerWrap">Players: <b id="playerCount"><?= (int)($pool['still_playing'] ?? 0) ?>/<?= (int)($pool['bought_in'] ?? 0) ?></b></span>
        <span class="timer-stat" id="poolWrap">Pool: <b id="poolTotal">$<?= number_format(($pool['pool_total'] ?? 0) / 100, 2) ?></b></span>
        <span class="timer-stat" id="rebuysWrap" style="display:none">Reentries: <b id="rebuysCount">0</b></span>
        <span class="timer-stat" id="chipsInPlayWrap" style="display:none">Chips: <b id="chipsInPlayVal">0</b></span>
        <span class="timer-stat" id="nextBreakWrap" style="display:none">Next break: <b id="nextBreakClock">--:--</b></span>
        <span class="timer-stat" id="endsAtWrap" style="display:none">Ends: <b id="endsAtVal">--</b></span>
    </div>

    <!-- Main display -->
    <div class="timer-display">
        <div class="timer-level-label" id="levelLabel">Level 1</div>
        <!-- The inner span is what fitBlinds() measures: an inline-block hugs its
             text exactly, where the block would always report the full width and
             a Range over a bare text node reports the line box, not the glyphs. -->
        <div class="timer-blinds" id="blinds"><span id="blindsInner">-</span></div>
        <div class="timer-ante" id="ante"></div>
        <div class="timer-clock timer-green" id="timerClock">00:00</div>
        <div class="timer-paused-label" id="pausedLabel"><span id="pausedInner"></span></div>
        <div class="timer-next" id="nextLevel"><span id="nextInner"></span></div>
    </div>

    <!-- Primary controls (always visible) -->
    <!-- Controls tray (floating toolbar on all screens) -->
    <div class="timer-tray" id="timerTray">
        <div class="timer-tray-grid">
            <?php if ($can_control): ?>
            <button data-act="skipLevel" data-a1="-1" title="Previous level">&#9198;<span class="tray-label">Prev</span></button>
            <button class="btn-play" id="btnPlay" data-act="togglePlay">&#9654;<span class="tray-label">Start</span></button>
            <button data-act="skipLevel" data-a1="1" title="Next level">&#9197;<span class="tray-label">Next</span></button>
            <span class="timer-tray-sep"></span>
            <span class="timer-min-group">
                <button data-act="adjustTime" data-a1="-60" title="Remove 1 minute">&#9660;</button>
                <span class="timer-min-label">Min</span>
                <button data-act="adjustTime" data-a1="60" title="Add 1 minute">&#9650;</button>
            </span>
            <span class="timer-reset-group">
                <button data-act="resetLevel" title="Reset level">&#8635;<span class="tray-label">Level</span></button>
                <button data-act="resetTimer" title="Reset timer" class="btn-danger">&#10226;<span class="tray-label" style="color:#ef4444">Timer</span></button>
            </span>
            <button data-act="sendCommand" data-a1="'undo'" title="Undo the last timer action (skip, time change, reset, play/pause)">&#8630;<span class="tray-label">Undo</span></button>
            <span class="timer-tray-sep"></span>
            <?php endif; ?>
            <button id="btnSound" data-act="toggleSound" title="Toggle sound">&#128276;<span class="tray-label">Sound</span></button>
            <button id="btnFullscreen" data-act="goFullscreen" title="Fullscreen">&#9974;<span class="tray-label">Full</span></button>
            <?php if (!$is_display): ?>
            <button data-act="openDisplayMode" title="Open TV display in new tab">&#128250;<span class="tray-label">TV</span></button>
            <?php endif; ?>
            <?php if (!$is_remote): ?>
            <span class="timer-tray-sep"></span>
            <button data-act="openLevels" title="Blind structure">&#128203;<span class="tray-label">Levels</span></button>
            <?php if (!$is_guest): ?>
            <button data-act="enterLayoutEdit" title="Customize theme &amp; layout">&#127912;<span class="tray-label">Theme</span></button>
            <button data-act="openSoundSettings" title="Sound settings">&#9881;<span class="tray-label">Sounds</span></button>
            <?php endif; ?>
            <?php endif; ?>
            <?php if ($can_control && $event && $session): ?>
            <span class="timer-tray-sep"></span>
            <button id="ppTrayBtn" data-act="togglePlayerPanel" title="Players">&#128101;<span class="tray-label">Players</span></button>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Swipe hint indicators (mobile only) -->
<div class="swipe-hint-bottom"></div>
<?php if ($can_control && $event && $session): ?>
<div class="swipe-hint-right"></div>
<?php endif; ?>

<?php if ($can_control && $event && $session): ?>
<!-- Player management slide-out panel -->
<div class="player-panel-overlay" id="playerPanelOverlay" data-act="togglePlayerPanel" style="display:none"></div>
<div class="player-panel" id="playerPanel">
    <div class="player-panel-header">
        <span>Players</span>
        <button data-act="togglePlayerPanel" style="background:none;border:none;color:#94a3b8;font-size:1.3rem;cursor:pointer">&times;</button>
    </div>
    <div class="player-panel-body" id="playerPanelBody">
        <div style="text-align:center;padding:2rem;color:#64748b">Loading...</div>
    </div>
</div>
<?php endif; ?>

<?php if (!$is_remote && !$is_guest): ?>
<!-- QR code for remote viewer -->
<div class="timer-qr" id="qrWrap" title="Scan to view timer on your phone"></div>
<?php endif; ?>

<!-- Themable user image + streaming video iframe (positioned + resized in edit mode).
     Rendered for remote viewers too — the remote screen is the display you'd cast a
     stream/image onto, so these must exist there for renderAll() to populate them. -->
<img class="timer-image" id="themeImage" alt="" style="display:none">
<div class="timer-stream" id="streamingWrap" style="display:none">
    <iframe id="streamingFrame" frameborder="0"
            allow="autoplay; encrypted-media; picture-in-picture; fullscreen"
            allowfullscreen></iframe>
</div>

<?php if (!$is_remote): ?>
<!-- Levels editor overlay -->
<div class="timer-levels-overlay" id="levelsOverlay" data-act-self="closeLevels">
    <div class="timer-levels-panel" style="position:relative">
        <button data-act="closeLevels" style="position:absolute;top:0.75rem;right:0.75rem;z-index:7;background:none;border:none;color:#94a3b8;font-size:1.5rem;cursor:pointer;line-height:1;padding:0.25rem">&times;</button>
        <div class="timer-editor-head">
            <div class="timer-editor-titlebar">
                <h3>Blind Structure</h3>
                <button id="btnSaveLevels" class="btn-save" data-act="saveLevels">Save</button>
                <button data-act="openGenerator" title="Build a full structure from a few settings">&#9881; Generate</button>
                <button data-act="addLevel" data-a1="false">+ Add Level</button>
                <button data-act="addLevel" data-a1="true">+ Add Break</button>
                <button class="btn-close-panel" data-act="closeLevels">Close</button>
            </div>
            <?php if (!$is_guest): ?>
            <div class="timer-preset-bar">
                <select id="presetSelect" data-act-change="updatePresetButtons"><option value="">Loading...</option></select>
                <button data-act="loadPreset">Load</button>
                <button data-act="savePresetAs">Save As...</button>
                <button id="btnDeletePreset" data-act="deletePreset">Delete</button>
                <button id="btnSetDefault" data-act="setAsDefault" style="display:none">Set Default</button>
                <button data-act="exportLevels">Export</button>
                <button data-act="clickFileInput" data-a1="importFile">Import</button>
                <input type="file" id="importFile" accept=".csv" style="display:none" data-act-change="importLevels" data-change-a1="@self">
            </div>
            <?php else: ?>
            <div class="timer-preset-bar" style="justify-content:center">
                <span style="color:#94a3b8;font-size:.8rem"><a href="/register.php" style="color:#60a5fa">Create an account</a> to save presets, export/import blinds</span>
            </div>
            <?php endif; ?>
        </div>
        <div class="timer-levels-scroll">
            <table class="timer-levels-table">
                <thead><tr><th style="width:3rem">#</th><th>SB</th><th>BB</th><th>Ante</th><th>Min</th><th>Type</th><th></th></tr></thead>
                <tbody id="levelsBody"></tbody>
            </table>
        </div>
    </div>
</div>

<!-- Unsaved-changes confirmation (closing the levels editor) -->
<div class="timer-levels-overlay" id="closeConfirmOverlay" data-act-self="closeCloseConfirm">
    <div class="timer-levels-panel" style="max-width:420px;position:relative">
        <button data-act="closeCloseConfirm" type="button"
                style="position:absolute;top:0.75rem;right:0.75rem;background:none;border:none;color:#94a3b8;font-size:1.5rem;cursor:pointer;line-height:1;padding:0.25rem">&times;</button>
        <h3>Unsaved changes</h3>
        <p style="color:#cbd5e1;font-size:.9rem;margin:0 0 1rem">You have unsaved changes to the blind structure. Discard them, or keep editing?</p>
        <div class="timer-level-btns">
            <button type="button" data-act="discardLevelsAndClose" style="background:#dc2626;border-color:#dc2626;color:#fff">Discard</button>
            <button class="btn-save" type="button" data-act="closeCloseConfirm">Keep editing</button>
        </div>
    </div>
</div>

<!-- Save Preset As modal -->
<div class="timer-levels-overlay" id="savePresetOverlay" data-act-self="closeSavePresetModal">
    <div class="timer-levels-panel" style="max-width:440px;position:relative">
        <button data-act="closeSavePresetModal" type="button"
                style="position:absolute;top:0.75rem;right:0.75rem;background:none;border:none;color:#94a3b8;font-size:1.5rem;cursor:pointer;line-height:1;padding:0.25rem">&times;</button>
        <h3>Save Preset As</h3>
        <div style="display:flex;flex-direction:column;gap:1rem;margin-bottom:1rem">
            <label style="font-size:.85rem;color:#cbd5e1">
                Preset name
                <input type="text" id="savePresetName" autocomplete="off"
                       style="display:block;width:100%;margin-top:.3rem;padding:.5rem .65rem;border:1.5px solid #334155;border-radius:6px;background:#0f172a;color:#e2e8f0;font-size:.95rem"
                       data-act-keydown="savePresetOnEnter" data-keydown-a1="@event">
            </label>
            <label style="font-size:.85rem;color:#cbd5e1">
                Save to
                <select id="savePresetScope"
                        style="display:block;width:100%;margin-top:.3rem;padding:.5rem .65rem;border:1.5px solid #334155;border-radius:6px;background:#0f172a;color:#e2e8f0;font-size:.95rem"></select>
            </label>
        </div>
        <div class="timer-level-btns">
            <button class="btn-save" type="button" data-act="confirmSavePresetAs">Save</button>
            <button class="btn-close-panel" type="button" data-act="closeSavePresetModal">Cancel</button>
        </div>
    </div>
</div>

<!-- Structure generator modal — build a full blind schedule from a few inputs -->
<div class="timer-levels-overlay" id="genOverlay" data-act-self="closeGenerator">
    <div class="timer-levels-panel" style="max-width:460px;position:relative">
        <button data-act="closeGenerator" type="button"
                style="position:absolute;top:0.75rem;right:0.75rem;background:none;border:none;color:#94a3b8;font-size:1.5rem;cursor:pointer;line-height:1;padding:0.25rem">&times;</button>
        <h3>Generate Structure</h3>
        <p style="font-size:.8rem;color:#94a3b8;margin:0 0 1rem">Builds a full blind schedule you can then fine-tune. Big blind is always twice the small blind.</p>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem 1rem;margin-bottom:1rem;font-size:.85rem;color:#cbd5e1">
            <label>Starting small blind
                <input type="number" id="genStartSB" value="25" min="1"
                       style="display:block;width:100%;margin-top:.3rem;padding:.5rem .65rem;border:1.5px solid #334155;border-radius:6px;background:#0f172a;color:#e2e8f0;font-size:.95rem"></label>
            <label>Number of levels
                <input type="number" id="genCount" value="15" min="1" max="60"
                       style="display:block;width:100%;margin-top:.3rem;padding:.5rem .65rem;border:1.5px solid #334155;border-radius:6px;background:#0f172a;color:#e2e8f0;font-size:.95rem"></label>
            <label>Minutes per level
                <input type="number" id="genDuration" value="20" min="1"
                       style="display:block;width:100%;margin-top:.3rem;padding:.5rem .65rem;border:1.5px solid #334155;border-radius:6px;background:#0f172a;color:#e2e8f0;font-size:.95rem"></label>
            <label>Antes from level <span style="color:#64748b">(0 = none)</span>
                <input type="number" id="genAnteFrom" value="0" min="0"
                       style="display:block;width:100%;margin-top:.3rem;padding:.5rem .65rem;border:1.5px solid #334155;border-radius:6px;background:#0f172a;color:#e2e8f0;font-size:.95rem"></label>
            <label>Break every N levels <span style="color:#64748b">(0 = none)</span>
                <input type="number" id="genBreakEvery" value="0" min="0"
                       style="display:block;width:100%;margin-top:.3rem;padding:.5rem .65rem;border:1.5px solid #334155;border-radius:6px;background:#0f172a;color:#e2e8f0;font-size:.95rem"></label>
            <label>Break length (min)
                <input type="number" id="genBreakLen" value="10" min="1"
                       style="display:block;width:100%;margin-top:.3rem;padding:.5rem .65rem;border:1.5px solid #334155;border-radius:6px;background:#0f172a;color:#e2e8f0;font-size:.95rem"></label>
        </div>
        <div class="timer-level-btns">
            <button class="btn-save" type="button" data-act="confirmGenerate">Generate</button>
            <button class="btn-close-panel" type="button" data-act="closeGenerator">Cancel</button>
        </div>
    </div>
</div>

<?php if (!$is_guest): ?>
<!-- Theme library modal — pick / load / save-as / delete / set-default a saved theme. -->
<div class="timer-levels-overlay" id="themeOverlay" data-act-self="closeThemes">
    <div class="timer-levels-panel" style="max-width:520px;position:relative">
        <button data-act="closeThemes" type="button"
                style="position:absolute;top:0.75rem;right:0.75rem;background:none;border:none;color:#94a3b8;font-size:1.5rem;cursor:pointer;line-height:1;padding:0.25rem">&times;</button>
        <h3>Theme Library</h3>
        <p style="font-size:.8rem;color:#94a3b8;margin:0 0 .75rem">Pick a saved theme, save your current edits as a new one, or set the default.</p>

        <div class="timer-preset-bar">
            <select id="themeSelect" data-act-change="updateThemeButtons"><option value="">Loading...</option></select>
            <button data-act="loadTheme">Load</button>
            <button data-act="saveThemeAs">Save As...</button>
            <button id="btnDeleteTheme" data-act="deleteTheme">Delete</button>
            <button id="btnSetDefaultTheme" data-act="setAsDefaultTheme" style="display:none">Set Default</button>
            <button data-act="exportTheme" title="Download selected theme as a JSON file">Export</button>
            <button data-act="clickFileInput" data-a1="themeImportFile" title="Load a theme JSON file from another install">Import</button>
            <input type="file" id="themeImportFile" accept=".json,application/json" style="display:none" data-act-change="importTheme" data-change-a1="@self">
            <button data-act="openPresets" title="Browse built-in preset themes">Presets&hellip;</button>
        </div>

        <div class="timer-level-btns" style="margin-top:1rem">
            <button class="btn-close-panel" data-act="closeThemes">Close</button>
        </div>
    </div>
</div>

<!-- Preset theme gallery — built-in .gnt.json presets with live mini-previews. -->
<div class="timer-levels-overlay" id="presetOverlay" data-act-self="closePresets">
    <div class="timer-levels-panel" style="max-width:760px;position:relative">
        <button data-act="closePresets" type="button"
                style="position:absolute;top:0.75rem;right:0.75rem;background:none;border:none;color:#94a3b8;font-size:1.5rem;cursor:pointer;line-height:1;padding:0.25rem">&times;</button>
        <h3>Preset Themes</h3>
        <p style="font-size:.8rem;color:#94a3b8;margin:0 0 .75rem">Browse built-in presets. Loading one saves it as your own editable theme.</p>
        <div id="presetAdminBar" style="display:none;margin-bottom:.5rem">
            <button data-act="clickFileInput" data-a1="presetUploadFile" title="Upload a .gnt.json preset to the shared library">&#8593; Upload preset</button>
            <input type="file" id="presetUploadFile" accept=".json,application/json" style="display:none" data-act-change="uploadPreset" data-change-a1="@self">
        </div>
        <div class="preset-gallery-grid" id="presetGrid"><p style="color:#94a3b8">Loading&hellip;</p></div>
        <div class="timer-level-btns" style="margin-top:.5rem">
            <button class="btn-close-panel" data-act="closePresets">Close</button>
        </div>
    </div>
</div>

<!-- Save Theme As modal -->
<div class="timer-levels-overlay" id="saveThemeOverlay" data-act-self="closeSaveThemeModal">
    <div class="timer-levels-panel" style="max-width:440px;position:relative">
        <button data-act="closeSaveThemeModal" type="button"
                style="position:absolute;top:0.75rem;right:0.75rem;background:none;border:none;color:#94a3b8;font-size:1.5rem;cursor:pointer;line-height:1;padding:0.25rem">&times;</button>
        <h3>Save Theme As</h3>
        <div style="display:flex;flex-direction:column;gap:1rem;margin-bottom:1rem">
            <label style="font-size:.85rem;color:#cbd5e1">
                Theme name
                <input type="text" id="saveThemeName" autocomplete="off"
                       style="display:block;width:100%;margin-top:.3rem;padding:.5rem .65rem;border:1.5px solid #334155;border-radius:6px;background:#0f172a;color:#e2e8f0;font-size:.95rem"
                       data-act-keydown="saveThemeOnEnter" data-keydown-a1="@event">
            </label>
            <label style="font-size:.85rem;color:#cbd5e1">
                Save to
                <select id="saveThemeScope"
                        style="display:block;width:100%;margin-top:.3rem;padding:.5rem .65rem;border:1.5px solid #334155;border-radius:6px;background:#0f172a;color:#e2e8f0;font-size:.95rem"></select>
            </label>
        </div>
        <div class="timer-level-btns">
            <button class="btn-save" type="button" data-act="confirmSaveThemeAs">Save</button>
            <button class="btn-close-panel" type="button" data-act="closeSaveThemeModal">Cancel</button>
        </div>
    </div>
</div>

<!-- Confirm Save modal — overwrite current theme or branch to Save As New -->
<div class="timer-levels-overlay" id="confirmSaveOverlay" data-act-self="closeConfirmSave">
    <div class="timer-levels-panel" style="max-width:420px;position:relative">
        <button data-act="closeConfirmSave" type="button"
                style="position:absolute;top:0.75rem;right:0.75rem;background:none;border:none;color:#94a3b8;font-size:1.5rem;cursor:pointer;line-height:1;padding:0.25rem">&times;</button>
        <h3>Save Theme</h3>
        <p style="color:#cbd5e1;font-size:.9rem;margin:0 0 .5rem">Saving to: <b id="confirmSaveName" style="color:#fff">My Theme</b></p>
        <p id="confirmSaveWarn" style="display:none;color:#fbbf24;font-size:.8rem;margin:0 0 1rem">
            This theme is protected &mdash; saving will create a personal copy.
        </p>
        <div class="timer-level-btns">
            <button class="btn-save" type="button" data-act="confirmSaveOverwrite">&#128190; Save</button>
            <button type="button" data-act="confirmSaveAsNew">&#128221; Save As New&hellip;</button>
            <button class="btn-close-panel" type="button" data-act="closeConfirmSave">Cancel</button>
        </div>
    </div>
</div>
<?php endif; ?>
<?php endif; ?>

<?php if (!$is_remote): ?>
<!-- Sound settings overlay -->
<div class="timer-levels-overlay" id="soundOverlay" data-act-self="closeSoundSettings">
    <div class="timer-levels-panel" style="max-width:500px">
        <h3>Sound Settings</h3>

        <div style="margin-bottom:1.2rem">
            <label style="display:block;margin-bottom:0.4rem;color:#94a3b8;font-size:0.85rem">Warning Alert (seconds before level ends)</label>
            <select id="warningSeconds" style="background:#0f172a;color:#e2e8f0;border:1px solid #334155;border-radius:6px;padding:0.4rem 0.6rem;font-size:0.9rem;width:100%">
                <option value="0">Off</option>
                <option value="30">30 seconds</option>
                <option value="60">60 seconds</option>
                <option value="120">2 minutes</option>
                <option value="300">5 minutes</option>
            </select>
        </div>

        <div style="margin-bottom:1.2rem">
            <label style="display:block;margin-bottom:0.4rem;color:#94a3b8;font-size:0.85rem">End Level Sound</label>
            <div style="display:flex;gap:0.5rem;align-items:center;flex-wrap:wrap">
                <select id="alarmSoundSelect" style="background:#0f172a;color:#e2e8f0;border:1px solid #334155;border-radius:6px;padding:0.4rem 0.6rem;font-size:0.9rem;flex:1">
                    <option value="">Default (5 beeps, 3 sec)</option>
                    <option value="preset:descending">3 Descending Beeps</option>
                    <option value="preset:buzzer">Buzzer</option>
                    <option value="preset:chime">Chime (ascending)</option>
                    <option value="preset:casino">Casino Bell</option>
                    <option value="preset:horn">Air Horn</option>
                    <option value="preset:countdown">Countdown (3-2-1-GO)</option>
                    <option value="preset:double">Double Beep</option>
</select>
                <button data-act="previewSound" data-a1="'end'" style="background:#334155;color:#e2e8f0;border:1px solid #475569;border-radius:6px;padding:0.4rem 0.8rem;cursor:pointer;font-size:0.85rem">&#9654; Test</button>
            </div>
            <div style="margin-top:0.5rem">
                <label style="display:inline-block;background:#334155;color:#e2e8f0;border:1px solid #475569;border-radius:6px;padding:0.4rem 0.8rem;cursor:pointer;font-size:0.85rem">
                    Upload Custom...
                    <input type="file" id="alarmUpload" accept="audio/*" style="display:none" data-act-change="uploadSound" data-change-a1="'alarm'">
                </label>
                <span id="alarmUploadStatus" style="color:#94a3b8;font-size:0.8rem;margin-left:0.5rem"></span>
            </div>
        </div>

        <div style="margin-bottom:1.2rem">
            <label style="display:block;margin-bottom:0.4rem;color:#94a3b8;font-size:0.85rem">Start Level Sound</label>
            <div style="display:flex;gap:0.5rem;align-items:center;flex-wrap:wrap">
                <select id="startSoundSelect" style="background:#0f172a;color:#e2e8f0;border:1px solid #334155;border-radius:6px;padding:0.4rem 0.6rem;font-size:0.9rem;flex:1">
                    <option value="">Default (1 long tone)</option>
                    <option value="preset:buzzer">Buzzer</option>
                    <option value="preset:chime">Chime (ascending)</option>
                    <option value="preset:casino">Casino Bell</option>
                    <option value="preset:horn">Air Horn</option>
                    <option value="preset:countdown">Countdown (3-2-1-GO)</option>
                    <option value="preset:double">Double Beep</option>
</select>
                <button data-act="previewSound" data-a1="'start'" style="background:#334155;color:#e2e8f0;border:1px solid #475569;border-radius:6px;padding:0.4rem 0.8rem;cursor:pointer;font-size:0.85rem">&#9654; Test</button>
            </div>
            <div style="margin-top:0.5rem">
                <label style="display:inline-block;background:#334155;color:#e2e8f0;border:1px solid #475569;border-radius:6px;padding:0.4rem 0.8rem;cursor:pointer;font-size:0.85rem">
                    Upload Custom...
                    <input type="file" id="startUpload" accept="audio/*" style="display:none" data-act-change="uploadSound" data-change-a1="'start'">
                </label>
                <span id="startUploadStatus" style="color:#94a3b8;font-size:0.8rem;margin-left:0.5rem"></span>
            </div>
        </div>

        <div style="margin-bottom:1.2rem">
            <label style="display:block;margin-bottom:0.4rem;color:#94a3b8;font-size:0.85rem">Warning Sound</label>
            <div style="display:flex;gap:0.5rem;align-items:center;flex-wrap:wrap">
                <select id="warningSoundSelect" style="background:#0f172a;color:#e2e8f0;border:1px solid #334155;border-radius:6px;padding:0.4rem 0.6rem;font-size:0.9rem;flex:1">
                    <option value="">Default (5 quick beeps)</option>
                    <option value="preset:tick">Tick-Tick</option>
                    <option value="preset:pulse">Pulse (heartbeat)</option>
                    <option value="preset:chirp">Chirp</option>
                    <option value="preset:gentle">Gentle Tone</option>
                </select>
                <button data-act="previewSound" data-a1="'warning'" style="background:#334155;color:#e2e8f0;border:1px solid #475569;border-radius:6px;padding:0.4rem 0.8rem;cursor:pointer;font-size:0.85rem">&#9654; Test</button>
            </div>
            <div style="margin-top:0.5rem">
                <label style="display:inline-block;background:#334155;color:#e2e8f0;border:1px solid #475569;border-radius:6px;padding:0.4rem 0.8rem;cursor:pointer;font-size:0.85rem">
                    Upload Custom...
                    <input type="file" id="warningUpload" accept="audio/*" style="display:none" data-act-change="uploadSound" data-change-a1="'warning'">
                </label>
                <span id="warningUploadStatus" style="color:#94a3b8;font-size:0.8rem;margin-left:0.5rem"></span>
            </div>
        </div>

        <label style="display:flex;align-items:center;gap:.5rem;font-size:.85rem;color:#cbd5e1;margin-top:1rem;cursor:pointer">
            <input type="checkbox" id="muteStreamCheckbox" data-act-change="onMuteStreamToggle" data-change-a1="@checked">
            Mute streaming video while alarms play
            <span style="color:#94a3b8;font-size:.72rem">&nbsp;(YouTube &amp; Vimeo only)</span>
        </label>

        <div class="timer-level-btns">
            <button class="btn-save" data-act="saveSoundSettings">Save</button>
            <button class="btn-close-panel" data-act="closeSoundSettings">Close</button>
        </div>
    </div>
</div>
<?php endif; ?>

<script src="/vendor/qrcode.min.js"></script>
<script src="/vendor/nosleep.min.js"></script>
<script nonce="<?= csp_nonce() ?>">
// ─── §7.1  Config from PHP ──────────────────────────────────────
var IS_REMOTE = <?= json_encode($is_remote) ?>;
var IS_GUEST = <?= json_encode($is_guest) ?>;
var IS_ADMIN = <?= json_encode($isAdmin) ?>;
var CAN_CONTROL = <?= json_encode($can_control) ?>;
var SESSION_ID = <?= json_encode($session ? (int)$session['id'] : null) ?>;
var REMOTE_KEY = <?= json_encode($remote_key) ?>;
// Admin-configured extra stream hosts (must mirror the CSP frame-src allowlist in auth.php).
var EXTRA_STREAM_HOSTS = <?= json_encode(stream_allowed_hosts()) ?>;
var CSRF = <?= json_encode($csrf) ?>;
// Read by the theme and preset lists in timer.js to decide what is editable.
var CURRENT_USER_ID = <?= json_encode((int)($current['id'] ?? 0)) ?>;
// This page installs its own delegated dispatcher below. Tell the shared
// pk-dispatch.js (loaded from _footer.php) to stand down, or every control
// fires twice — a rebuy would add 2 and a buy-in would toggle on then off.
window.PK_DISPATCH_LOCAL = 1;

// ── Declarative handler dispatch (CSP step 2, see SECURITY.md) ────────────
// Same idiom as checkin.php. Inline on* attributes cannot be authorised by a
// nonce, so controls carry a data-act* attribute naming the function and these
// delegated listeners invoke it. Delegation on document is required, not
// tidier: the timer re-renders its panels and the layout inspector constantly,
// so per-element binding would be lost on every repaint.
//
//   data-act            click       -> fn()
//   data-act-<evt>      that event  -> fn()      (change, input, keydown,
//                                                 dragstart, dragover, drop,
//                                                 dragend)
//   data-act-self       click, but ONLY when the click is on the element itself
//                       (the modal-backdrop pattern, was if(event.target===this))
//   data-stop           swallow the click so an ancestor action does not fire
//
// Arguments live in data-a1..a4 for click and data-<evt>-a1..a4 for every other
// event. They are namespaced per event because one element can carry two
// handlers, and a shared namespace emits a duplicate attribute — HTML keeps the
// first, so the second handler silently gets the wrong arguments. That bug cost
// a broken Enter key in checkin.php (v0.2075); it is designed out here.
// ── Named replacements for former inline expressions (CSP step 2) ─────────
function savePresetOnEnter(ev) {
    if (ev.key !== 'Enter') return;
    ev.preventDefault();
    confirmSavePresetAs();
}
function saveThemeOnEnter(ev) {
    if (ev.key !== 'Enter') return;
    ev.preventDefault();
    confirmSaveThemeAs();
}
// The objects-list row buttons sit inside a row that has its own click action,
// so each swallowed the click before doing its own job.
function objectsRowEye(ev, key)   { ev.stopPropagation(); onObjectsRowEye(key); }
function objectsRowUp(ev, key)    { ev.stopPropagation(); moveObjectLayer(key, -1); }
function objectsRowDown(ev, key)  { ev.stopPropagation(); moveObjectLayer(key, 1); }
function toggleElementAndRefresh(key) {
    toggleElementVisibility(key);
    renderInspector(key);
}
function flipClockRadialDirection(dir) {
    onClockRadialOpt('radial_direction', dir === 'ccw' ? 'cw' : 'ccw');
    renderInspector('clock');
}
function pageGradientAngle() {
    onPageBgChange('gangle', this.value);
    var lbl = document.getElementById('page_gang_lbl');
    if (lbl) lbl.textContent = this.value + '\u00B0';
}
function streamUrlChanged() {
    onStreamUrlChange(this.value);
    renderInspector('page');
}
function streamUrlCleared() {
    onStreamUrlChange('');
    renderInspector('page');
}

function clickFileInput(id) {
    var el = document.getElementById(id);
    if (el) el.click();
}
function clockRadialSegments() {
    onClockRadialOpt('radial_segments', Math.max(2, Math.min(60, parseInt(this.value, 10) || 12)));
}
function clockRadialThickness() {
    onClockRadialOpt('radial_thickness', parseFloat(this.value));
}

function _tokenT(tok, el, ev) {
    switch (tok) {
        case '@value':     return el.value;
        case '@checked':   return el.checked;
        case '@checked01': return el.checked ? 1 : 0;
        case '@event':     return ev;
        case '@self':      return el;
        default:           return tok;
    }
}
function _argsT(el, ev, pfx) {
    var out = [];
    for (var i = 1; i <= 4; i++) {
        var v = el.getAttribute('data-' + (pfx || '') + 'a' + i);
        if (v === null) break;
        if (v.charAt(0) === '@')        out.push(_tokenT(v, el, ev));
        else if (v === 'true')          out.push(true);
        else if (v === 'false')         out.push(false);
        else if (v !== '' && !isNaN(v)) out.push(Number(v));
        else out.push(v);
    }
    return out;
}
function _dispatchT(name, el, ev, pfx) {
    var fn = window[name];
    if (typeof fn === 'function') return fn.apply(el || null, _argsT(el, ev, pfx));
    console.warn('[timer] no handler named', name);   // a dead control is silent otherwise
}
// CAPTURE phase, deliberately. The control tray stops click propagation so a
// click inside it does not trigger the close-on-outside-click handler
// (see tray.addEventListener('click', ...) further down), which means a
// bubble-phase listener on document never sees the play, sound or level
// buttons at all. Inline handlers were unaffected because they ran AT the
// target. Capturing restores that ordering without changing tray behaviour.
document.addEventListener('click', function (e) {
    if (e.target instanceof Element && e.target.hasAttribute('data-act-self')) {
        _dispatchT(e.target.getAttribute('data-act-self'), e.target, e, '');
        return;
    }
    if (!e.target.closest) return;
    var t = e.target.closest('[data-act], [data-stop]');
    if (!t || t.hasAttribute('data-stop')) return;
    _dispatchT(t.getAttribute('data-act'), t, e, '');
}, true);
['change', 'input', 'keydown', 'dragstart', 'dragover', 'drop', 'dragend'].forEach(function (evt) {
    document.addEventListener(evt, function (e) {
        var t = e.target.closest ? e.target.closest('[data-act-' + evt + ']') : null;
        if (t) _dispatchT(t.getAttribute('data-act-' + evt), t, e, evt + '-');
    }, true);
});
var POLL_INTERVAL = 2000; // everyone polls server every 2s
// Touch/mobile detection — used to skip rendering the streaming iframe on phones/tablets,
// because cross-origin iframes capture taps that would otherwise re-acquire the wake lock.
// Same heuristic the wake-lock banner uses (line ~1802).
var IS_TOUCH_DEVICE = ('ontouchstart' in window) || (navigator.maxTouchPoints || 0) > 0;

var TIMER = {
    current_level: <?= (int)($timer['current_level'] ?? 1) ?>,
    time_remaining_seconds: <?= $remaining ?>,
    is_running: <?= (int)($timer['is_running'] ?? 0) ?>
};
var LEVELS = <?= json_encode($levels) ?>;
var POOL = <?= json_encode($pool) ?>;
var soundEnabled = true;
var localInterval = null;
var lastSyncTime = Date.now();
var audioCtx = null;
var CURRENT_PRESET_ID = <?= json_encode($timer['preset_id'] ? (int)$timer['preset_id'] : null) ?>;
var PAYOUTS = <?= json_encode($payouts) ?>;
var GAME_TYPE = <?= json_encode($game_type) ?>;
var EVENT_ID = <?= json_encode($event ? (int)$event['id'] : null) ?>;
var POKER_SESSION_ID = <?= json_encode($session ? (int)$session['id'] : null) ?>;
var SOUNDS = {
    warning_seconds: <?= (int)($timer['warning_seconds'] ?? 60) ?>,
    alarm_sound: <?= json_encode($timer['alarm_sound'] ?? null) ?>,
    start_sound: <?= json_encode($timer['start_sound'] ?? null) ?>,
    warning_sound: <?= json_encode($timer['warning_sound'] ?? null) ?>
};
</script>
<script src="/timer.js?v=<?= htmlspecialchars(APP_VERSION . '.' . (@filemtime(__DIR__ . '/timer.js') ?: 0)) ?>"></script>
<script src="/pk-dialogs.js?v=<?= @filemtime(__DIR__ . '/pk-dialogs.js') ?>" defer></script>
</body>
</html>
