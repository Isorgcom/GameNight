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
 *  §6    Vendor <script>s — qrcode.min.js, nosleep.min.js
 *  §7    Inline <script> (~3,600 lines)
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
        echo '<!DOCTYPE html><html><head><title>Invalid Link</title><link rel="stylesheet" href="/style.css"></head><body style="display:flex;align-items:center;justify-content:center;min-height:100vh;background:#0f172a;color:#fff"><div class="card" style="text-align:center"><h2>Invalid Timer Link</h2><p>This timer link is no longer valid.</p></div></body></html>';
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

    // Check if logged-in user can control
    $current = current_user();
    if ($current) {
        $isAdmin = $current['role'] === 'admin';
        $can_control = check_event_access($db, (int)$session['event_id'], $current, $isAdmin);
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

    $can_control = true;
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>Poker Timer &mdash; <?= htmlspecialchars($site_name) ?></title>
    <link rel="icon" href="/favicon.php">
    <link rel="stylesheet" href="/style.css">
    <link rel="stylesheet" href="/vendor/fonts/fonts.css">
    <script>window.TIMER_THEME = <?= json_encode($themeProps, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>; window.TIMER_THEME_ID = <?= $themeId ? (int)$themeId : 'null' ?>;</script>
    <style id="themeStyle"><?= $themeCss ?></style>
    <style>
        html {
            height: 100%;
            background: var(--timer-bg);   /* iPad: keeps overscroll/safe-area gutters from showing white */
            overscroll-behavior: none;
        }
        body { overscroll-behavior: none; }
        :root {
            --timer-bg: #0f172a;
            --timer-event-color: #fff;
            --timer-stat-color: #94a3b8;
            --timer-stat-strong: #e2e8f0;
            --timer-level-color: #94a3b8;
            --timer-blinds-color: #fff;
            --timer-ante-color: #f59e0b;
            --timer-clock-green: #22c55e;
            --timer-clock-yellow: #fbbf24;
            --timer-clock-red: #ef4444;
            --timer-paused-color: #fbbf24;
            --timer-next-color: #94a3b8;
            --timer-avgstack-color: #94a3b8;
            --timer-payouts-color: #94a3b8;
            --timer-tray-bg: rgba(15, 23, 42, 0.88);
            --timer-tray-border: rgba(71, 85, 105, 0.4);
            --timer-tray-button-bg: #1e293b;
            --timer-tray-button-color: #e2e8f0;
            --timer-accent: #2563eb;
            --timer-event-scale: 1;
            --timer-level-scale: 1;
            --timer-blinds-scale: 1;
            --timer-clock-scale: 1;
            --timer-next-scale: 1;
            --timer-paused-scale: 1;
        }
        .timer-body {
            background: var(--timer-bg);
            color: #e2e8f0;
            margin: 0;
            height: 100dvh;
            height: 100vh; /* fallback */
            display: flex;
            flex-direction: column;
            font-family: system-ui, -apple-system, sans-serif;
            overflow: hidden;
        }
        @supports (height: 100dvh) {
            .timer-body { height: 100dvh; }
        }
        .timer-body nav, .timer-body .nav-top, .timer-body .nav-links { display: none; }
        .timer-body footer { display: none; }

        .timer-container {
            flex: 1 1 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 0.5rem 1rem;
            position: relative;
            min-height: 0;
            overflow: hidden;
        }

        /* ── Info bar ── */
        .timer-info-bar {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 2rem;
            width: 100%;
            max-width: 1200px;
            padding: 0.5rem 1rem;
            flex-wrap: wrap;
            flex-shrink: 0;
        }
        .timer-info-bar > span, .timer-info-bar > a {
            font-size: clamp(0.85rem, 2vw, 1.2rem);
            opacity: 0.85;
        }
        .timer-event-name {
            font-weight: 700;
            font-size: calc(clamp(1rem, 2.5vw, 1.5rem) * var(--timer-event-scale)) !important;
            opacity: 1 !important;
            color: var(--timer-event-color);
        }
        .timer-stat { color: var(--timer-stat-color); }
        .timer-stat b { color: var(--timer-stat-strong); font-size: 110%; }

        /* ── Main display ── */
        .timer-display {
            text-align: center;
            flex: 1 1 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            width: 100%;
            min-height: 0;
            overflow: hidden;
        }
        .timer-level-label {
            font-size: calc(clamp(0.9rem, 3vw, 2.5rem) * var(--timer-level-scale));
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.15em;
            color: var(--timer-level-color);
        }
        .timer-blinds {
            font-size: calc(clamp(2rem, 10vw, 10rem) * var(--timer-blinds-scale));
            font-weight: 800;
            color: var(--timer-blinds-color);
            line-height: 1.1;
            font-variant-numeric: tabular-nums;
        }
        .timer-ante {
            font-size: clamp(1rem, 2.5vw, 2.2rem);
            color: var(--timer-ante-color);
            font-weight: 700;
        }
        .timer-clock {
            font-size: calc(min(25vw, 35vh) * var(--timer-clock-scale));
            font-weight: 800;
            font-variant-numeric: tabular-nums;
            line-height: 1;
            margin: 0;
            transition: color 0.3s;
        }
        .timer-green { color: var(--timer-clock-green); }
        .timer-yellow { color: var(--timer-clock-yellow); }
        .timer-red { color: var(--timer-clock-red); animation: pulse 1s ease-in-out infinite; }
        /* Radial clock variants: container becomes a square SVG host sized off the same font-size rule. */
        .timer-clock[data-variant="radial-ring"],
        .timer-clock[data-variant="radial-checks"] {
            width: 1.6em;
            height: 1.6em;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            line-height: 1;
        }
        .timer-clock[data-variant="radial-ring"] svg,
        .timer-clock[data-variant="radial-checks"] svg {
            width: 100%;
            height: 100%;
            display: block;
            overflow: visible;
        }
        .timer-clock[data-variant="radial-ring"] .clock-num,
        .timer-clock[data-variant="radial-checks"] .clock-num {
            font-size: 9.5px;
            font-weight: 800;
            font-variant-numeric: tabular-nums;
            fill: currentColor;
        }
        .timer-clock[data-variant="radial-ring"] circle.clock-ring-fg,
        .timer-clock[data-variant="radial-checks"] g.clock-check-fg path {
            transition: stroke-dashoffset 0.5s linear;
        }
        .timer-paused-label {
            font-size: calc(clamp(0.8rem, 2vw, 1.8rem) * var(--timer-paused-scale));
            color: var(--timer-paused-color);
            font-weight: 600;
            letter-spacing: 0.2em;
            text-transform: uppercase;
            min-height: 1.5em;
        }
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        .timer-next {
            font-size: calc(clamp(1.3rem, 3.5vw, 2.5rem) * var(--timer-next-scale));
            color: var(--timer-next-color);
            font-weight: 600;
        }

        /* ── Controls ── */
        .timer-primary-controls {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            padding: 0.4rem 0;
            width: 100%;
            max-width: 900px;
            flex: 0 0 auto;
        }
        /* ── Floating glass toolbar (all screens) ── */
        .timer-tray {
            position: fixed;
            bottom: 0;
            left: 50%;
            transform: translateX(-50%) translateY(0);
            width: auto;
            max-width: 95vw;
            z-index: 50;
            background: var(--timer-tray-bg);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border-radius: 14px 14px 0 0;
            padding: 0.4rem 0.75rem;
            border: 1px solid var(--timer-tray-border);
            border-bottom: none;
            transition: transform 0.3s ease, opacity 0.3s ease;
        }
        .timer-tray.tray-hidden {
            transform: translateX(-50%) translateY(100%);
            opacity: 0;
            pointer-events: none;
        }
        .timer-tray-grid {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.25rem;
            flex-wrap: wrap;
            padding: 0;
        }
        .timer-tray-sep {
            width: 1px;
            height: 1.5rem;
            background: rgba(148, 163, 184, 0.3);
            margin: 0 0.15rem;
            flex-shrink: 0;
        }
        @media (min-width: 769px) {
            .timer-tray { padding: 0.5rem 1rem; }
            .timer-tray-grid { flex-wrap: nowrap; }
        }
        @media (max-width: 768px) {
            .timer-tray { padding: 0.35rem 0.5rem; padding-bottom: max(0.35rem, env(safe-area-inset-bottom)); }
            .timer-tray-grid { gap: 0.2rem; }
            .timer-tray-grid button { padding: 0.3rem 0.45rem !important; font-size: 0.9rem !important; min-width: 2.2rem; }
            .tray-label { font-size: 0.48rem !important; }
            .timer-tray-grid .btn-play { background: #16a34a !important; border-color: #16a34a !important; color: #fff !important; padding: 0.3rem 0.7rem !important; }
            .timer-tray-grid .btn-play.is-running { background: #dc2626 !important; border-color: #dc2626 !important; }
        }
        .timer-primary-controls button,
        .timer-tray-grid button,
        .timer-controls button {
            background: var(--timer-tray-button-bg);
            color: var(--timer-tray-button-color);
            border: 1px solid #334155;
            border-radius: 8px;
            padding: 0.6rem 1.2rem;
            font-size: clamp(0.8rem, 1.5vw, 1rem);
            cursor: pointer;
            transition: background 0.15s, border-color 0.15s;
            white-space: nowrap;
        }
        .tray-label {
            display: none;
        }
        @media (min-width: 769px) {
            .timer-tray-grid button {
                padding: 0.35rem 0.6rem;
                font-size: 1rem;
                border-radius: 10px;
                min-width: 2.8rem;
                text-align: center;
                border-color: rgba(71, 85, 105, 0.5);
                display: inline-flex;
                flex-direction: column;
                align-items: center;
                gap: 0.1rem;
                line-height: 1;
            }
            .tray-label {
                display: block;
                font-size: 0.55rem;
                font-weight: 600;
                text-transform: uppercase;
                letter-spacing: 0.04em;
                color: #94a3b8;
            }
            .timer-tray-grid button:hover {
                background: #334155;
                border-color: #64748b;
            }
            .timer-tray-grid button:hover .tray-label { color: #e2e8f0; }
            .timer-tray-grid button.btn-danger { color: #ef4444; }
        }
        .timer-controls button:hover {
            background: #334155;
            border-color: #475569;
        }
        .timer-controls button.btn-play {
            background: #16a34a;
            border-color: #16a34a;
            color: #fff;
            font-weight: 700;
            padding: 0.6rem 2rem;
        }
        .timer-controls button.btn-play:hover { background: #15803d; }
        .timer-controls button.btn-play.is-running {
            background: #dc2626;
            border-color: #dc2626;
        }
        .timer-controls button.btn-play.is-running:hover { background: #b91c1c; }
        .timer-min-group, .timer-reset-group {
            display: inline-flex;
            align-items: center;
            gap: 0;
            border: 1px solid #334155;
            border-radius: 8px;
            overflow: hidden;
        }
        .timer-min-group button, .timer-reset-group button {
            border: none !important;
            border-radius: 0 !important;
            padding: 0.6rem 0.7rem;
        }
        .timer-min-group button:first-child { border-right: 1px solid #334155 !important; }
        .timer-min-group button:last-child { border-left: 1px solid #334155 !important; }
        .timer-reset-group button:first-child { border-right: 1px solid #334155 !important; }
        @media (min-width: 769px) {
            .timer-reset-group {
                display: inline-flex;
                border: none;
                gap: 0.25rem;
            }
            .timer-reset-group button {
                border: 1px solid rgba(71, 85, 105, 0.5) !important;
                border-radius: 10px !important;
                padding: 0.35rem 0.6rem !important;
                min-width: 2.8rem;
            }
        }
        .timer-min-label {
            padding: 0 0.5rem;
            color: #94a3b8;
            font-size: clamp(0.75rem, 1.3vw, 0.9rem);
            font-weight: 600;
            user-select: none;
        }

        /* ── "Link this timer to an event" banner (standalone mode) ── */
        .timer-link-banner {
            position: fixed; top: 0; left: 0; right: 0; z-index: 300;
            display: flex; flex-wrap: wrap; gap: .5rem .75rem;
            align-items: center; justify-content: center;
            background: #78350f; color: #fde68a;
            padding: .5rem .9rem; font-size: .85rem;
            box-shadow: 0 2px 8px rgba(0,0,0,.4);
        }
        .timer-link-banner .tlb-text { font-weight: 600; }
        .timer-link-banner .tlb-controls { display: flex; gap: .4rem; align-items: center; }
        .timer-link-banner .tlb-select {
            padding: .3rem .5rem; border-radius: 5px; border: 1px solid #b45309;
            background: #fffbeb; color: #0f172a; font-size: .85rem; max-width: 60vw;
        }
        .timer-link-banner .tlb-btn {
            padding: .3rem .8rem; border-radius: 5px; border: none;
            background: #f59e0b; color: #0f172a; font-weight: 700; cursor: pointer; font-size: .85rem;
        }
        .timer-link-banner .tlb-btn:hover { background: #fbbf24; }
        body.display-mode .timer-link-banner { display: none; }

        /* ── Back link ── */
        .timer-back {
            position: absolute;
            top: 1rem;
            left: 1rem;
            color: #64748b;
            text-decoration: none;
            font-size: 0.95rem;
            z-index: 10;
        }
        .timer-back:hover { color: #e2e8f0; }

        /* ── Payout display ── */
        .timer-payouts {
            position: absolute;
            top: 3rem;
            right: 0.75rem;
            background: rgba(30,41,59,0.85);
            border: 1px solid #334155;
            border-radius: 8px;
            padding: 0.5rem 0.75rem;
            font-size: clamp(0.7rem, 1.2vw, 0.9rem);
            line-height: 1.6;
            color: var(--timer-payouts-color);
            z-index: 10;
        }
        .timer-payouts-title {
            font-weight: 700;
            color: #e2e8f0;
            margin-bottom: 0.2rem;
            font-size: clamp(0.75rem, 1.3vw, 0.95rem);
        }
        .timer-payouts .payout-row b {
            color: #e2e8f0;
        }
        @media (max-width: 500px) {
            .timer-payouts { font-size: 0.65rem; padding: 0.35rem 0.5rem; }
        }

        /* Average stack display (left side, top-aligned with payouts) */
        .timer-avgstack {
            position: absolute;
            top: 3rem;
            left: 0.75rem;
            background: rgba(30,41,59,0.85);
            border: 1px solid #334155;
            border-radius: 8px;
            padding: 0.5rem 0.75rem;
            font-size: clamp(0.7rem, 1.2vw, 0.9rem);
            line-height: 1.6;
            color: var(--timer-avgstack-color);
            z-index: 10;
        }
        .timer-avgstack-title {
            font-weight: 700;
            color: #e2e8f0;
            margin-bottom: 0.2rem;
            font-size: clamp(0.75rem, 1.3vw, 0.95rem);
        }
        .timer-avgstack b { color: #e2e8f0; }
        @media (max-width: 500px) {
            .timer-avgstack { font-size: 0.65rem; padding: 0.35rem 0.5rem; }
        }

        /* ── QR code ── */
        .timer-qr {
            position: absolute;
            bottom: 1rem;
            right: 1rem;
            background: #fff;
            padding: 6px;
            border-radius: 8px;
            z-index: 10;
        }
        .timer-qr canvas { display: block; }

        /* ── Levels panel ── */
        .timer-levels-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.7);
            z-index: 200;
        }
        .timer-levels-overlay.open { display: flex; align-items: center; justify-content: center; }
        .timer-levels-panel {
            background: #1e293b;
            border-radius: 12px;
            padding: 1.5rem;
            width: 90%;
            max-width: 700px;
            max-height: 80vh;
            overflow-y: auto;
            color: #e2e8f0;
        }
        .timer-levels-panel h3 { margin: 0 0 1rem; font-size: 1.3rem; }
        /* #levelsOverlay turns the panel into a flex column: a static header that
           never scrolls, plus a single scrolling region (.timer-levels-scroll)
           below it. This makes it impossible for level rows to scroll under the
           header. Scoped so the other overlays sharing .timer-levels-panel are
           untouched. */
        #levelsOverlay .timer-levels-panel {
            display: flex;
            flex-direction: column;
            padding: 0;
            overflow: hidden; /* scrolling now belongs to .timer-levels-scroll */
        }
        /* Fixed editor header — holds the title, action buttons, AND preset menu.
           It sits outside the scroll region so the blind structure scrolls beneath
           nothing. */
        .timer-editor-head {
            flex: 0 0 auto;
            background: #1e293b;
            padding: 1.25rem 1.5rem 0.7rem 1.5rem; /* top clears the absolute × button */
            border-bottom: 1px solid #334155;
        }
        .timer-editor-titlebar {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.4rem;
            padding-right: 2.25rem; /* clear the × close button */
        }
        .timer-editor-titlebar h3 { margin: 0 0.35rem 0 0; font-size: 1.1rem; }
        .timer-editor-titlebar button {
            background: #334155;
            color: #e2e8f0;
            border: 1px solid #475569;
            border-radius: 6px;
            padding: 0.4rem 0.7rem;
            cursor: pointer;
            font-size: 0.8rem;
            white-space: nowrap;
        }
        .timer-editor-titlebar button:hover { background: #475569; }
        .timer-editor-titlebar .btn-save {
            background: var(--timer-accent);
            border-color: var(--timer-accent);
            color: #fff;
        }
        .timer-editor-titlebar .btn-save:hover { filter: brightness(0.9); }
        .timer-editor-titlebar .btn-close-panel {
            background: #64748b;
            border-color: #64748b;
            color: #fff;
        }
        .timer-editor-titlebar .btn-save.has-unsaved { box-shadow: 0 0 0 2px rgba(255,255,255,0.45); }
        /* Preset menu now lives inside the sticky header — trim its outer margin. */
        .timer-editor-head .timer-preset-bar { margin: 0.55rem 0 0; }
        .timer-preset-bar {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1rem;
            flex-wrap: wrap;
            align-items: center;
        }
        .timer-preset-bar select, .timer-preset-bar input {
            background: #0f172a;
            color: #e2e8f0;
            border: 1px solid #334155;
            border-radius: 6px;
            padding: 0.4rem 0.6rem;
            font-size: 0.9rem;
        }
        .timer-preset-bar button {
            background: #334155;
            color: #e2e8f0;
            border: 1px solid #475569;
            border-radius: 6px;
            padding: 0.4rem 0.8rem;
            font-size: 0.85rem;
            cursor: pointer;
        }
        .timer-preset-bar button:hover { background: #475569; }
        /* Preset theme gallery (built-in .gnt.json presets). */
        .preset-gallery-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            gap: 0.9rem;
            margin: 0.25rem 0 1rem;
            max-height: 60vh;
            overflow-y: auto;
        }
        .preset-card {
            background: #0f172a;
            border: 1px solid #334155;
            border-radius: 10px;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }
        .preset-card .preset-mock { position: relative; aspect-ratio: 16 / 9; overflow: hidden; }
        .preset-card .preset-foot {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.4rem;
            padding: 0.5rem 0.6rem;
        }
        .preset-card .preset-name {
            font-size: 0.85rem;
            color: #e2e8f0;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .preset-card .preset-foot button {
            background: #334155;
            color: #e2e8f0;
            border: 1px solid #475569;
            border-radius: 6px;
            padding: 0.25rem 0.6rem;
            font-size: 0.8rem;
            cursor: pointer;
        }
        .preset-card .preset-foot button:hover { background: #475569; }
        /* The only scrolling region in #levelsOverlay. Re-supplies the horizontal +
           bottom padding the panel lost when it went to padding:0. */
        .timer-levels-scroll {
            flex: 1 1 auto;
            min-height: 0;   /* let the flex item shrink so IT scrolls (not the panel) */
            overflow-y: auto;
            padding: 0 1.5rem 1.5rem; /* no top padding: the sticky th pins flush under the header */
        }
        .timer-levels-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
        }
        .timer-levels-table th {
            text-align: left;
            padding: 0.4rem;
            color: #94a3b8;
            font-weight: 600;
            position: sticky;
            top: 0; /* pins to the top of .timer-levels-scroll */
            z-index: 4;
            background: #1e293b;
            box-shadow: inset 0 -1px 0 #334155; /* underline survives sticky + border-collapse */
        }
        .timer-levels-table td {
            padding: 0.35rem 0.4rem;
            border-bottom: 1px solid #1e293b;
        }
        .timer-levels-table tr.is-break td { color: #fbbf24; font-style: italic; }
        .timer-levels-table tr.current-level td { background: rgba(34,197,94,0.15); }
        .timer-levels-table td { transition: background-color 0.45s ease; }
        .timer-levels-table tr.lvl-moved td { background: rgba(96,165,250,0.30); }
        .timer-levels-table tr.lvl-dragging td { opacity: 0.35; }
        .timer-levels-table input[type="number"] {
            background: #0f172a;
            color: #e2e8f0;
            border: 1px solid #334155;
            border-radius: 4px;
            padding: 0.25rem 0.4rem;
            width: 70px;
            font-size: 0.85rem;
        }
        .timer-levels-table .lvl-actions { white-space: nowrap; }
        .timer-levels-table .lvl-actions button {
            background: none;
            border: none;
            color: #ef4444;
            cursor: pointer;
            font-size: 1.1rem;
            padding: 0.2rem;
        }
        .timer-levels-table .lvl-actions button.lvl-move {
            font-size: 0.95rem;
            line-height: 1;
            padding: 0.3rem 0.25rem;
        }
        .timer-levels-table .lvl-actions button:disabled { opacity: 0.25; cursor: default; }
        .timer-level-btns button.btn-save.has-unsaved {
            box-shadow: 0 0 0 2px rgba(255,255,255,0.45);
        }
        .timer-level-btns {
            display: flex;
            gap: 0.5rem;
            margin-top: 0.75rem;
            flex-wrap: wrap;
        }
        .timer-level-btns button {
            background: #334155;
            color: #e2e8f0;
            border: 1px solid #475569;
            border-radius: 6px;
            padding: 0.5rem 1rem;
            cursor: pointer;
            font-size: 0.9rem;
        }
        .timer-level-btns button:hover { background: #475569; }
        .timer-level-btns button.btn-save {
            background: var(--timer-accent);
            border-color: var(--timer-accent);
            color: #fff;
        }
        .timer-level-btns button.btn-save:hover { filter: brightness(0.9); }
        .timer-level-btns button.btn-close-panel {
            background: #64748b;
            border-color: #64748b;
            color: #fff;
        }

        /* ── Responsive ── */
        @media (max-width: 900px) {
            .timer-info-bar { gap: 0.5rem; padding: 0.25rem 0.5rem; }
            .timer-primary-controls, .timer-tray-grid {
                gap: 0.3rem;
            }
            .timer-primary-controls button, .timer-tray-grid button, .timer-controls button {
                padding: 0.4rem 0.6rem;
                font-size: 0.75rem;
                border-radius: 6px;
            }
            .timer-primary-controls button.btn-play, .timer-controls button.btn-play {
                padding: 0.4rem 1rem;
            }
            .timer-blinds { font-size: calc(clamp(2rem, 9vw, 6rem) * var(--timer-blinds-scale)); }
            .timer-clock { font-size: calc(min(22vw, 30vh) * var(--timer-clock-scale)); }
            .timer-level-label { font-size: calc(clamp(0.9rem, 2.5vw, 1.5rem) * var(--timer-level-scale)); }
        }
        @media (max-width: 500px) {
            .timer-primary-controls button, .timer-tray-grid button, .timer-controls button {
                padding: 0.35rem 0.5rem;
                font-size: 0.7rem;
            }
            .timer-primary-controls button.btn-play, .timer-controls button.btn-play {
                padding: 0.35rem 0.8rem;
            }
            .timer-qr { display: none; }
        }
        /* Landscape phones: shrink everything to fit */
        @media (max-height: 500px) {
            .timer-container { padding: 0.25rem 0.5rem; }
            .timer-info-bar { padding: 0.15rem 0.5rem; gap: 1rem; }
            .timer-info-bar > span { font-size: 0.8rem; }
            .timer-level-label { font-size: calc(1rem * var(--timer-level-scale)); }
            .timer-blinds { font-size: calc(clamp(1.5rem, 6vw, 3rem) * var(--timer-blinds-scale)); }
            .timer-ante { font-size: 0.85rem; }
            .timer-clock { font-size: calc(min(20vw, 25vh) * var(--timer-clock-scale)); }
            .timer-paused-label { font-size: 0.9rem; min-height: 1.2em; }
            .timer-next { font-size: calc(1.1rem * var(--timer-next-scale)); }
            .timer-primary-controls, .timer-tray-grid { padding: 0.2rem 0; gap: 0.25rem; }
            .timer-primary-controls button, .timer-tray-grid button, .timer-controls button { padding: 0.3rem 0.5rem; font-size: 0.7rem; }
            .timer-primary-controls button.btn-play, .timer-controls button.btn-play { padding: 0.3rem 0.8rem; }
        }

        /* Player management panel */
        .player-panel-overlay{position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.5);z-index:200}
        .player-panel{position:fixed;top:0;right:-320px;width:300px;max-width:85vw;height:100%;background:#1e293b;z-index:201;transition:right .25s ease;display:flex;flex-direction:column}
        .player-panel.open{right:0}
        /* Attention "peek": shortly after load the panel edge bounces out twice so
           hosts discover the slide-out. Class is added once by JS and removed on
           animationend; suppressed for reduced-motion users. */
        @keyframes pp-peek{0%{right:-320px}14%{right:-244px}28%{right:-320px}42%{right:-252px}56%{right:-320px}70%{right:-264px}84%{right:-320px}100%{right:-320px}}
        .player-panel.pp-peek{animation:pp-peek 2.2s cubic-bezier(.36,.07,.19,.97)}
        @keyframes pp-nudge{0%,100%{transform:translateX(0)}15%,45%,75%{transform:translateX(-5px)}30%,60%,90%{transform:translateX(5px)}}
        .pp-nudge{animation:pp-nudge 2.2s ease-in-out}
        @media (prefers-reduced-motion: reduce){
            .player-panel.pp-peek,.pp-nudge{animation:none}
        }
        .player-panel-header{display:flex;justify-content:space-between;align-items:center;padding:.75rem 1rem;border-bottom:1px solid #334155;color:#f1f5f9;font-weight:700;font-size:.95rem;flex-shrink:0}
        .player-panel-body{flex:1;overflow-y:auto;padding:.5rem}
        .pp-card{background:#0f172a;border:1px solid #334155;border-radius:6px;padding:.5rem .65rem;margin-bottom:.4rem}
        .pp-card.elim{opacity:.4}
        .pp-name{font-weight:600;font-size:.85rem;color:#f1f5f9}
        .pp-status{font-size:.7rem;font-weight:600;margin-left:.4rem}
        .pp-actions{display:flex;gap:.3rem;flex-wrap:wrap;margin-top:.35rem}
        .pp-actions button{padding:.25rem .5rem;border-radius:4px;font-size:.7rem;font-weight:600;cursor:pointer;border:1px solid #475569;background:#1e293b;color:#94a3b8}
        .pp-actions button:active{background:#334155}
        .pp-actions .pp-elim{color:#ef4444;border-color:#7f1d1d}
        .pp-actions .pp-undo{color:#fbbf24;border-color:#78350f}
        .pp-counter{display:inline-flex;align-items:center;gap:0;border:1px solid #475569;border-radius:4px;overflow:hidden}
        .pp-counter button{width:22px;height:22px;border:none;background:#334155;color:#f1f5f9;cursor:pointer;font-weight:700;font-size:.8rem;display:flex;align-items:center;justify-content:center}
        .pp-counter button:active{background:#475569}
        .pp-counter span{min-width:18px;text-align:center;font-weight:600;font-size:.75rem;color:#f1f5f9;padding:0 2px}
        /* ── Swipe hint indicators ── */
        .swipe-hint-bottom, .swipe-hint-right {
            position: fixed;
            z-index: 40;
            pointer-events: none;
            opacity: 0.4;
            transition: opacity 0.3s;
        }
        .swipe-hint-bottom {
            bottom: 4px;
            left: 50%;
            transform: translateX(-50%);
            width: 36px;
            height: 4px;
            background: #475569;
            border-radius: 3px;
        }
        .swipe-hint-right {
            right: 3px;
            top: 50%;
            transform: translateY(-50%);
            width: 4px;
            height: 36px;
            background: #475569;
            border-radius: 3px;
        }
        @media (pointer: fine) {
            .swipe-hint-bottom, .swipe-hint-right { display: none; }
        }
        body.display-mode .swipe-hint-bottom,
        body.display-mode .swipe-hint-right { display: none; }

        /* ── TV Display Mode ── */
        body.display-mode .timer-tray,
        body.display-mode .timer-tray-handle,
        body.display-mode .timer-back,
        body.display-mode .timer-qr,
        body.display-mode .player-panel,
        body.display-mode .player-panel-overlay { display: none !important; }

        body.display-mode .timer-container { padding: 1rem 2rem; }
        body.display-mode .timer-level-label { font-size: calc(clamp(2rem, 4vw, 4rem) * var(--timer-level-scale)); }
        body.display-mode .timer-blinds { font-size: calc(clamp(3rem, 12vw, 12rem) * var(--timer-blinds-scale)); }
        body.display-mode .timer-clock { font-size: calc(min(30vw, 45vh) * var(--timer-clock-scale)); }
        body.display-mode .timer-next { font-size: calc(clamp(1.8rem, 4vw, 4rem) * var(--timer-next-scale)); }
        body.display-mode .timer-ante { font-size: clamp(1.5rem, 3vw, 3rem); }
        body.display-mode .timer-paused-label { font-size: clamp(2rem, 4vw, 3.5rem); }
        body.display-mode .timer-info-bar { font-size: clamp(1.2rem, 2.5vw, 2.2rem); padding: 0.75rem 2rem; gap: 2rem; }

        /* ── Free-form layout editing ── */
        /* Elements with an explicit theme position get pulled out of flow and pinned to the
           viewport. --pos-x/--pos-y are percentages of viewport width/height; the element's
           CENTER lands on that point (so the chosen anchor matches what the user sees while dragging). */
        .timer-positioned {
            position: fixed;
            left: var(--pos-x, 50%);
            top:  var(--pos-y, 50%);
            right: auto;
            bottom: auto;
            transform: translate(-50%, -50%);
            margin: 0;
            z-index: 20;
            white-space: nowrap;
        }
        /* QR keeps its current size unless theme overrides; transform stacks translate+scale. */
        #qrWrap.timer-positioned {
            transform: translate(-50%, -50%) scale(var(--timer-qr-scale, 1));
            transform-origin: center;
        }
        /* Themable image: defaults centered and constrained, scaled via transform. */
        .timer-image {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            max-width: 40vmin;
            max-height: 40vmin;
            object-fit: contain;
            z-index: 5;
            user-select: none;
            pointer-events: auto;
        }
        #themeImage.timer-positioned {
            transform: translate(-50%, -50%) scale(var(--timer-image-scale, 1));
            transform-origin: center;
            z-index: 4;  /* sit behind the text layer (z 20); above page bg */
        }
        /* Generic per-element scale for free-positioned widgets that don't have
           a custom #id.timer-positioned rule of their own. Element-scoped --el-scale
           is set by applyTheme based on theme.elements[key].scale. ID-specific rules
           (qr, image, streaming, etc.) override via higher specificity. */
        .timer-positioned[data-has-scale] {
            transform: translate(-50%, -50%) scale(var(--el-scale, 1));
            transform-origin: center;
        }
        /* Streaming video iframe (positioned + resized like themeImage). */
        .timer-stream {
            width: 30vw;
            aspect-ratio: 16 / 9;
            pointer-events: auto;
            background: #000;
            /* Positioning + transform are owned by #streamingWrap.timer-positioned below
               (higher specificity than the generic .timer-positioned rule). */
        }
        .timer-stream iframe { width: 100%; height: 100%; border: 0; display: block; }
        /* Empty placeholder shown in edit mode when no URL is set yet. */
        .timer-stream.is-empty {
            background: repeating-linear-gradient(45deg,#1e293b,#1e293b 10px,#0f172a 10px,#0f172a 20px);
            display: flex; align-items: center; justify-content: center;
            color: #cbd5e1; font-size: .8rem; text-align: center; padding: .5rem;
        }
        /* In edit mode, let clicks reach the wrapper instead of the iframe so the user
           can drag/select the panel. The iframe stays interactive in normal mode. */
        body.layout-edit .timer-stream iframe { pointer-events: none; }
        #streamingWrap.timer-positioned {
            position: fixed;
            left: var(--pos-x, 75%);
            top:  var(--pos-y, 25%);
            transform: translate(-50%, -50%) scale(var(--timer-stream-scale, 1));
            transform-origin: center;
            z-index: 4;
        }
        /* Info bar wraps when extra panels overflow narrow screens. */
        .timer-info-bar { flex-wrap: wrap; }
        /* Color variables for the three new info-bar stats. */
        #rebuysWrap       { color: var(--timer-rebuys-color, #94a3b8); }
        #chipsInPlayWrap  { color: var(--timer-chips-color, #94a3b8); }
        #nextBreakWrap    { color: var(--timer-nextbreak-color, #94a3b8); }
        /* Center guides shown only in layout-edit mode. Subtle by default; brighten when
           an element snaps to them mid-drag. */
        .center-guide-v, .center-guide-h {
            position: fixed;
            background: rgba(251, 191, 36, 0.18);
            pointer-events: none;
            z-index: 5;
            display: none;
        }
        body.layout-edit .center-guide-v,
        body.layout-edit .center-guide-h { display: block; }
        .center-guide-v { top: 0; bottom: 0; left: 50%; width: 1px; }
        .center-guide-h { left: 0; right: 0; top: 50%; height: 1px; }
        .center-guide-v.is-snapping,
        .center-guide-h.is-snapping {
            background: rgba(251, 191, 36, 0.95);
            box-shadow: 0 0 8px rgba(251, 191, 36, 0.6);
        }
        /* Smart-alignment guides — shown when the dragging element snaps to another
           element's center axis. Positioned dynamically by makeDragStart. */
        .align-guide-v, .align-guide-h {
            position: fixed;
            background: rgba(56, 189, 248, 0.95);
            box-shadow: 0 0 8px rgba(56, 189, 248, 0.6);
            pointer-events: none;
            z-index: 6;
            display: none;
        }
        .align-guide-v { top: 0; bottom: 0; width: 1px; }
        .align-guide-h { left: 0; right: 0; height: 1px; }
        .align-guide-v.is-snapping,
        .align-guide-h.is-snapping { display: block; }
        /* On-screen reminder about the Shift modifier (edit mode only). */
        .snap-hint {
            position: fixed;
            bottom: 0.6rem;
            left: 50%;
            transform: translateX(-50%);
            background: rgba(15, 23, 42, 0.85);
            color: #cbd5e1;
            border: 1px solid #334155;
            border-radius: 999px;
            padding: 0.25rem 0.7rem;
            font-size: 0.72rem;
            letter-spacing: 0.02em;
            z-index: 998;
            display: none;
            pointer-events: none;
            white-space: nowrap;
        }
        body.layout-edit .snap-hint { display: inline-block; }
        body.layout-edit { user-select: none; -webkit-user-select: none; }
        body.layout-edit .timer-positioned,
        body.layout-edit .layout-draggable {
            outline: 2px dashed #fbbf24;
            outline-offset: 4px;
            cursor: grab;
        }
        body.layout-edit .timer-positioned:active,
        body.layout-edit .layout-draggable:active { cursor: grabbing; }
        body.layout-edit .timer-tray,
        body.layout-edit .timer-back { display: none !important; }
        .layout-edit-pill {
            position: fixed;
            top: 25%;
            left: 25%;
            transform: translate(-50%, -50%);
            z-index: 999;
            background: rgba(15, 23, 42, 0.95);
            border: 1px solid #475569;
            border-radius: 999px;
            padding: 0.35rem 0.65rem;
            display: none;
            gap: 0.4rem;
            align-items: center;
            box-shadow: 0 6px 20px rgba(0,0,0,0.4);
        }
        body.layout-edit .layout-edit-pill { display: inline-flex; }
        .layout-edit-pill button {
            background: #1e293b;
            color: #e2e8f0;
            border: 1px solid #475569;
            border-radius: 999px;
            padding: 0.35rem 0.75rem;
            font-size: 0.85rem;
            cursor: pointer;
            white-space: nowrap;
        }
        .layout-edit-pill button:hover { background: #334155; }
        .layout-edit-pill button.btn-done { background: #2563eb; border-color: #2563eb; color: #fff; }
        .layout-edit-pill button#snapToggleBtn.snap-on  { background: #2563eb; border-color: #2563eb; color: #fff; }
        .layout-edit-pill button#snapToggleBtn.snap-off { background: #1e293b; border-color: #64748b; color: #94a3b8; }
        .layout-edit-pill button.btn-danger { color: #ef4444; }
        .layout-edit-pill .pill-handle {
            cursor: grab;
            color: #64748b;
            padding: 0.25rem 0.4rem;
            font-size: 1rem;
            user-select: none;
            line-height: 1;
        }
        .layout-edit-pill .pill-handle:active { cursor: grabbing; }
        .layout-edit-pill .pill-sep { width: 1px; align-self: stretch; background: #334155; margin: 0 0.15rem; }

        /* Per-element eye icon overlay (visible when in edit mode) */
        .layout-eye {
            position: absolute;
            top: -1.4rem;
            left: -0.2rem;
            background: rgba(15, 23, 42, 0.95);
            color: #fbbf24;
            border: 1px solid #fbbf24;
            border-radius: 999px;
            width: 1.5rem;
            height: 1.5rem;
            display: none;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 0.75rem;
            line-height: 1;
            z-index: 25;
            user-select: none;
            transition: transform 0.1s;
        }
        .layout-eye:hover { transform: scale(1.25); }
        .layout-eye.is-hidden { color: #64748b; border-color: #64748b; }
        body.layout-edit .timer-positioned .layout-eye { display: inline-flex; }
        /* Selected element gets a different (solid blue) outline. */
        body.layout-edit .timer-positioned.is-selected {
            outline: 3px solid #2563eb;
            outline-offset: 4px;
        }

        /* Inspector panel — properties for the currently selected element */
        .layout-inspector {
            position: fixed;
            top: 4.5rem;
            right: 1rem;
            z-index: 998;
            width: 260px;
            background: rgba(15, 23, 42, 0.97);
            border: 1px solid #475569;
            border-radius: 12px;
            color: #e2e8f0;
            display: none;
            flex-direction: column;
            box-shadow: 0 10px 30px rgba(0,0,0,0.5);
        }
        body.layout-edit .layout-inspector.is-open { display: flex; }
        .layout-inspector-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.5rem 0.75rem;
            background: #1e293b;
            border-bottom: 1px solid #334155;
            border-radius: 12px 12px 0 0;
            cursor: grab;
            user-select: none;
        }
        .layout-inspector-header:active { cursor: grabbing; }
        .layout-inspector-header h4 { margin: 0; font-size: 0.9rem; color: #fff; font-weight: 600; }
        .layout-inspector-close {
            background: none; border: none; color: #94a3b8; font-size: 1.2rem; cursor: pointer; padding: 0 0.25rem; line-height: 1;
        }
        .layout-inspector-body {
            padding: 0.75rem;
            display: flex;
            flex-direction: column;
            gap: 0.6rem;
            font-size: 0.85rem;
        }
        .layout-inspector-row { display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; }
        .layout-inspector-row label { color: #cbd5e1; }
        .layout-inspector-row input[type="color"] { width: 2rem; height: 2rem; padding: 0; border: 1px solid #334155; background: transparent; cursor: pointer; border-radius: 6px; }
        .layout-inspector-row .ins-btn {
            background: #334155; color: #e2e8f0; border: 1px solid #475569; border-radius: 6px;
            padding: 0.2rem 0.55rem; cursor: pointer; font-size: 0.85rem;
        }
        .layout-inspector-row .ins-btn:hover { background: #475569; }
        .layout-inspector-row .ins-btn.is-active { background: #2563eb; border-color: #2563eb; color: #fff; }
        .layout-inspector-row .ins-scale { min-width: 3rem; text-align: center; color: #94a3b8; font-size: 0.8rem; }

        /* Objects panel — left-edge floating list of every theme element (mirror of inspector). */
        .layout-objects-panel {
            position: fixed;
            top: 4.5rem;
            left: 1rem;
            z-index: 998;
            width: 220px;
            background: rgba(15, 23, 42, 0.97);
            border: 1px solid #475569;
            border-radius: 12px;
            color: #e2e8f0;
            display: none;
            flex-direction: column;
            box-shadow: 0 10px 30px rgba(0,0,0,0.5);
        }
        body.layout-edit .layout-objects-panel.is-open { display: flex; }
        .layout-objects-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.5rem 0.75rem;
            background: #1e293b;
            border-bottom: 1px solid #334155;
            border-radius: 12px 12px 0 0;
            cursor: grab;
            user-select: none;
        }
        .layout-objects-header:active { cursor: grabbing; }
        .layout-objects-header h4 { margin: 0; font-size: 0.9rem; color: #fff; font-weight: 600; }
        .layout-objects-body { padding: 0.4rem; max-height: 70vh; overflow-y: auto; }
        .layout-object-row {
            display: flex;
            align-items: center;
            gap: 0.45rem;
            padding: 0.3rem 0.45rem;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.85rem;
        }
        .layout-object-row:hover { background: #1e293b; }
        .layout-object-row.is-selected { background: #1e3a8a; }
        .layout-object-row .obj-eye {
            background: none;
            border: none;
            color: #fbbf24;
            cursor: pointer;
            padding: 0;
            width: 1.2rem;
            font-size: 0.95rem;
            line-height: 1;
        }
        .layout-object-row .obj-eye.is-hidden { color: #64748b; }
        .layout-object-row .obj-label { flex: 1; color: #e2e8f0; }
        .layout-object-row.is-hidden .obj-label { color: #64748b; font-style: italic; }
        /* Layer reorder controls: grip (pointer-drag) + ▲/▼ (touch-reliable fallback). */
        .layout-object-row .obj-grip {
            color: #64748b; cursor: grab; user-select: none; touch-action: none;
            font-size: 0.95rem; line-height: 1; padding: 0 0.1rem; width: 1rem; text-align: center;
        }
        .layout-object-row .obj-grip:active { cursor: grabbing; }
        .layout-object-row .obj-move {
            display: inline-flex; gap: 0.1rem;
        }
        .layout-object-row .obj-move button {
            background: none; border: none; color: #94a3b8; cursor: pointer;
            padding: 0 0.15rem; font-size: 0.8rem; line-height: 1;
        }
        .layout-object-row .obj-move button:hover:not(:disabled) { color: #e2e8f0; }
        .layout-object-row .obj-move button:disabled { color: #475569; cursor: default; }
        .layout-object-row.is-dragging {
            background: #1e3a8a; box-shadow: 0 4px 14px rgba(0,0,0,0.5); opacity: 0.95;
        }
        body.layout-edit .timer-positioned[data-ghost-selected="1"] { opacity: 0.45; }
    </style>
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
    <button type="button" onclick="openObjectsPanel()" title="Show / hide / select objects">&#128203; Objects</button>
    <button type="button" onclick="openThemes()" title="Load / save themes">&#128218; Library</button>
    <button class="btn-done" type="button" onclick="exitLayoutEdit(true)">&#10003; Save</button>
    <button type="button" onclick="resetPositions()" title="Snap elements back to default positions">&#8635; Reset</button>
    <button type="button" id="snapToggleBtn" onclick="toggleSnap()" title="Toggle snap (Shift = momentary off on a keyboard)">&#129522; Snap</button>
    <span class="pill-sep"></span>
    <button type="button" onclick="exitLayoutEdit(false)">&times; Close</button>
</div>

<!-- Inspector for the selected element (draggable). -->
<div class="layout-inspector" id="layoutInspector">
    <div class="layout-inspector-header" id="inspectorHeader">
        <h4 id="inspectorTitle">Element</h4>
        <button class="layout-inspector-close" type="button" onclick="closeInspector()" title="Close">&times;</button>
    </div>
    <div class="layout-inspector-body" id="inspectorBody"></div>
</div>

<!-- Objects panel — list of every theme element, including hidden ones (only way to see + un-hide them). -->
<div class="layout-objects-panel" id="layoutObjectsPanel">
    <div class="layout-objects-header" id="objectsHeader">
        <h4>Objects</h4>
        <button class="layout-inspector-close" type="button" onclick="closeObjectsPanel()" title="Close">&times;</button>
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
        <button type="button" class="tlb-btn" onclick="linkTimerToEvent()">Link</button>
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
    </div>

    <!-- Main display -->
    <div class="timer-display">
        <div class="timer-level-label" id="levelLabel">Level 1</div>
        <div class="timer-blinds" id="blinds">-</div>
        <div class="timer-ante" id="ante"></div>
        <div class="timer-clock timer-green" id="timerClock">00:00</div>
        <div class="timer-paused-label" id="pausedLabel"></div>
        <div class="timer-next" id="nextLevel"></div>
    </div>

    <!-- Primary controls (always visible) -->
    <!-- Controls tray (floating toolbar on all screens) -->
    <div class="timer-tray" id="timerTray">
        <div class="timer-tray-grid">
            <?php if ($can_control): ?>
            <button onclick="skipLevel(-1)" title="Previous level">&#9198;<span class="tray-label">Prev</span></button>
            <button class="btn-play" id="btnPlay" onclick="togglePlay()">&#9654;<span class="tray-label">Start</span></button>
            <button onclick="skipLevel(1)" title="Next level">&#9197;<span class="tray-label">Next</span></button>
            <span class="timer-tray-sep"></span>
            <span class="timer-min-group">
                <button onclick="adjustTime(-60)" title="Remove 1 minute">&#9660;</button>
                <span class="timer-min-label">Min</span>
                <button onclick="adjustTime(60)" title="Add 1 minute">&#9650;</button>
            </span>
            <span class="timer-reset-group">
                <button onclick="resetLevel()" title="Reset level">&#8635;<span class="tray-label">Level</span></button>
                <button onclick="resetTimer()" title="Reset timer" class="btn-danger">&#10226;<span class="tray-label" style="color:#ef4444">Timer</span></button>
            </span>
            <span class="timer-tray-sep"></span>
            <?php endif; ?>
            <button id="btnSound" onclick="toggleSound()" title="Toggle sound">&#128276;<span class="tray-label">Sound</span></button>
            <button id="btnFullscreen" onclick="goFullscreen()" title="Fullscreen">&#9974;<span class="tray-label">Full</span></button>
            <?php if (!$is_display): ?>
            <button onclick="openDisplayMode()" title="Open TV display in new tab">&#128250;<span class="tray-label">TV</span></button>
            <?php endif; ?>
            <?php if (!$is_remote): ?>
            <span class="timer-tray-sep"></span>
            <button onclick="openLevels()" title="Blind structure">&#128203;<span class="tray-label">Levels</span></button>
            <?php if (!$is_guest): ?>
            <button onclick="enterLayoutEdit()" title="Customize theme &amp; layout">&#127912;<span class="tray-label">Theme</span></button>
            <button onclick="openSoundSettings()" title="Sound settings">&#9881;<span class="tray-label">Sounds</span></button>
            <?php endif; ?>
            <?php endif; ?>
            <?php if ($can_control && $event && $session): ?>
            <span class="timer-tray-sep"></span>
            <button id="ppTrayBtn" onclick="togglePlayerPanel()" title="Players">&#128101;<span class="tray-label">Players</span></button>
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
<div class="player-panel-overlay" id="playerPanelOverlay" onclick="togglePlayerPanel()" style="display:none"></div>
<div class="player-panel" id="playerPanel">
    <div class="player-panel-header">
        <span>Players</span>
        <button onclick="togglePlayerPanel()" style="background:none;border:none;color:#94a3b8;font-size:1.3rem;cursor:pointer">&times;</button>
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
<div class="timer-levels-overlay" id="levelsOverlay" onclick="if(event.target===this)closeLevels()">
    <div class="timer-levels-panel" style="position:relative">
        <button onclick="closeLevels()" style="position:absolute;top:0.75rem;right:0.75rem;z-index:7;background:none;border:none;color:#94a3b8;font-size:1.5rem;cursor:pointer;line-height:1;padding:0.25rem">&times;</button>
        <div class="timer-editor-head">
            <div class="timer-editor-titlebar">
                <h3>Blind Structure</h3>
                <button id="btnSaveLevels" class="btn-save" onclick="saveLevels()">Save</button>
                <button onclick="openGenerator()" title="Build a full structure from a few settings">&#9881; Generate</button>
                <button onclick="addLevel(false)">+ Add Level</button>
                <button onclick="addLevel(true)">+ Add Break</button>
                <button class="btn-close-panel" onclick="closeLevels()">Close</button>
            </div>
            <?php if (!$is_guest): ?>
            <div class="timer-preset-bar">
                <select id="presetSelect" onchange="updatePresetButtons()"><option value="">Loading...</option></select>
                <button onclick="loadPreset()">Load</button>
                <button onclick="savePresetAs()">Save As...</button>
                <button id="btnDeletePreset" onclick="deletePreset()">Delete</button>
                <button id="btnSetDefault" onclick="setAsDefault()" style="display:none">Set Default</button>
                <button onclick="exportLevels()">Export</button>
                <button onclick="document.getElementById('importFile').click()">Import</button>
                <input type="file" id="importFile" accept=".csv" style="display:none" onchange="importLevels(this)">
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

<!-- Unsaved-changes confirm (closing the levels editor) -->
<div class="timer-levels-overlay" id="closeConfirmOverlay" onclick="if(event.target===this)closeCloseConfirm()">
    <div class="timer-levels-panel" style="max-width:420px;position:relative">
        <button onclick="closeCloseConfirm()" type="button"
                style="position:absolute;top:0.75rem;right:0.75rem;background:none;border:none;color:#94a3b8;font-size:1.5rem;cursor:pointer;line-height:1;padding:0.25rem">&times;</button>
        <h3>Unsaved changes</h3>
        <p style="color:#cbd5e1;font-size:.9rem;margin:0 0 1rem">You have unsaved changes to the blind structure. Discard them, or keep editing?</p>
        <div class="timer-level-btns">
            <button type="button" onclick="discardLevelsAndClose()" style="background:#dc2626;border-color:#dc2626;color:#fff">Discard</button>
            <button class="btn-save" type="button" onclick="closeCloseConfirm()">Keep editing</button>
        </div>
    </div>
</div>

<!-- Save Preset As modal -->
<div class="timer-levels-overlay" id="savePresetOverlay" onclick="if(event.target===this)closeSavePresetModal()">
    <div class="timer-levels-panel" style="max-width:440px;position:relative">
        <button onclick="closeSavePresetModal()" type="button"
                style="position:absolute;top:0.75rem;right:0.75rem;background:none;border:none;color:#94a3b8;font-size:1.5rem;cursor:pointer;line-height:1;padding:0.25rem">&times;</button>
        <h3>Save Preset As</h3>
        <div style="display:flex;flex-direction:column;gap:1rem;margin-bottom:1rem">
            <label style="font-size:.85rem;color:#cbd5e1">
                Preset name
                <input type="text" id="savePresetName" autocomplete="off"
                       style="display:block;width:100%;margin-top:.3rem;padding:.5rem .65rem;border:1.5px solid #334155;border-radius:6px;background:#0f172a;color:#e2e8f0;font-size:.95rem"
                       onkeydown="if(event.key==='Enter'){event.preventDefault();confirmSavePresetAs();}">
            </label>
            <label style="font-size:.85rem;color:#cbd5e1">
                Save to
                <select id="savePresetScope"
                        style="display:block;width:100%;margin-top:.3rem;padding:.5rem .65rem;border:1.5px solid #334155;border-radius:6px;background:#0f172a;color:#e2e8f0;font-size:.95rem"></select>
            </label>
        </div>
        <div class="timer-level-btns">
            <button class="btn-save" type="button" onclick="confirmSavePresetAs()">Save</button>
            <button class="btn-close-panel" type="button" onclick="closeSavePresetModal()">Cancel</button>
        </div>
    </div>
</div>

<!-- Structure generator modal — build a full blind schedule from a few inputs -->
<div class="timer-levels-overlay" id="genOverlay" onclick="if(event.target===this)closeGenerator()">
    <div class="timer-levels-panel" style="max-width:460px;position:relative">
        <button onclick="closeGenerator()" type="button"
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
            <button class="btn-save" type="button" onclick="confirmGenerate()">Generate</button>
            <button class="btn-close-panel" type="button" onclick="closeGenerator()">Cancel</button>
        </div>
    </div>
</div>

<?php if (!$is_guest): ?>
<!-- Theme library modal — pick / load / save-as / delete / set-default a saved theme. -->
<div class="timer-levels-overlay" id="themeOverlay" onclick="if(event.target===this)closeThemes()">
    <div class="timer-levels-panel" style="max-width:520px;position:relative">
        <button onclick="closeThemes()" type="button"
                style="position:absolute;top:0.75rem;right:0.75rem;background:none;border:none;color:#94a3b8;font-size:1.5rem;cursor:pointer;line-height:1;padding:0.25rem">&times;</button>
        <h3>Theme Library</h3>
        <p style="font-size:.8rem;color:#94a3b8;margin:0 0 .75rem">Pick a saved theme, save your current edits as a new one, or set the default.</p>

        <div class="timer-preset-bar">
            <select id="themeSelect" onchange="updateThemeButtons()"><option value="">Loading...</option></select>
            <button onclick="loadTheme()">Load</button>
            <button onclick="saveThemeAs()">Save As...</button>
            <button id="btnDeleteTheme" onclick="deleteTheme()">Delete</button>
            <button id="btnSetDefaultTheme" onclick="setAsDefaultTheme()" style="display:none">Set Default</button>
            <button onclick="exportTheme()" title="Download selected theme as a JSON file">Export</button>
            <button onclick="document.getElementById('themeImportFile').click()" title="Load a theme JSON file from another install">Import</button>
            <input type="file" id="themeImportFile" accept=".json,application/json" style="display:none" onchange="importTheme(this)">
            <button onclick="openPresets()" title="Browse built-in preset themes">Presets&hellip;</button>
        </div>

        <div class="timer-level-btns" style="margin-top:1rem">
            <button class="btn-close-panel" onclick="closeThemes()">Close</button>
        </div>
    </div>
</div>

<!-- Preset theme gallery — built-in .gnt.json presets with live mini-previews. -->
<div class="timer-levels-overlay" id="presetOverlay" onclick="if(event.target===this)closePresets()">
    <div class="timer-levels-panel" style="max-width:760px;position:relative">
        <button onclick="closePresets()" type="button"
                style="position:absolute;top:0.75rem;right:0.75rem;background:none;border:none;color:#94a3b8;font-size:1.5rem;cursor:pointer;line-height:1;padding:0.25rem">&times;</button>
        <h3>Preset Themes</h3>
        <p style="font-size:.8rem;color:#94a3b8;margin:0 0 .75rem">Browse built-in presets. Loading one saves it as your own editable theme.</p>
        <div id="presetAdminBar" style="display:none;margin-bottom:.5rem">
            <button onclick="document.getElementById('presetUploadFile').click()" title="Upload a .gnt.json preset to the shared library">&#8593; Upload preset</button>
            <input type="file" id="presetUploadFile" accept=".json,application/json" style="display:none" onchange="uploadPreset(this)">
        </div>
        <div class="preset-gallery-grid" id="presetGrid"><p style="color:#94a3b8">Loading&hellip;</p></div>
        <div class="timer-level-btns" style="margin-top:.5rem">
            <button class="btn-close-panel" onclick="closePresets()">Close</button>
        </div>
    </div>
</div>

<!-- Save Theme As modal -->
<div class="timer-levels-overlay" id="saveThemeOverlay" onclick="if(event.target===this)closeSaveThemeModal()">
    <div class="timer-levels-panel" style="max-width:440px;position:relative">
        <button onclick="closeSaveThemeModal()" type="button"
                style="position:absolute;top:0.75rem;right:0.75rem;background:none;border:none;color:#94a3b8;font-size:1.5rem;cursor:pointer;line-height:1;padding:0.25rem">&times;</button>
        <h3>Save Theme As</h3>
        <div style="display:flex;flex-direction:column;gap:1rem;margin-bottom:1rem">
            <label style="font-size:.85rem;color:#cbd5e1">
                Theme name
                <input type="text" id="saveThemeName" autocomplete="off"
                       style="display:block;width:100%;margin-top:.3rem;padding:.5rem .65rem;border:1.5px solid #334155;border-radius:6px;background:#0f172a;color:#e2e8f0;font-size:.95rem"
                       onkeydown="if(event.key==='Enter'){event.preventDefault();confirmSaveThemeAs();}">
            </label>
            <label style="font-size:.85rem;color:#cbd5e1">
                Save to
                <select id="saveThemeScope"
                        style="display:block;width:100%;margin-top:.3rem;padding:.5rem .65rem;border:1.5px solid #334155;border-radius:6px;background:#0f172a;color:#e2e8f0;font-size:.95rem"></select>
            </label>
        </div>
        <div class="timer-level-btns">
            <button class="btn-save" type="button" onclick="confirmSaveThemeAs()">Save</button>
            <button class="btn-close-panel" type="button" onclick="closeSaveThemeModal()">Cancel</button>
        </div>
    </div>
</div>

<!-- Confirm Save modal — overwrite current theme or branch to Save As New -->
<div class="timer-levels-overlay" id="confirmSaveOverlay" onclick="if(event.target===this)closeConfirmSave()">
    <div class="timer-levels-panel" style="max-width:420px;position:relative">
        <button onclick="closeConfirmSave()" type="button"
                style="position:absolute;top:0.75rem;right:0.75rem;background:none;border:none;color:#94a3b8;font-size:1.5rem;cursor:pointer;line-height:1;padding:0.25rem">&times;</button>
        <h3>Save Theme</h3>
        <p style="color:#cbd5e1;font-size:.9rem;margin:0 0 .5rem">Saving to: <b id="confirmSaveName" style="color:#fff">My Theme</b></p>
        <p id="confirmSaveWarn" style="display:none;color:#fbbf24;font-size:.8rem;margin:0 0 1rem">
            This theme is protected &mdash; saving will create a personal copy.
        </p>
        <div class="timer-level-btns">
            <button class="btn-save" type="button" onclick="confirmSaveOverwrite()">&#128190; Save</button>
            <button type="button" onclick="confirmSaveAsNew()">&#128221; Save As New&hellip;</button>
            <button class="btn-close-panel" type="button" onclick="closeConfirmSave()">Cancel</button>
        </div>
    </div>
</div>
<?php endif; ?>
<?php endif; ?>

<?php if (!$is_remote): ?>
<!-- Sound settings overlay -->
<div class="timer-levels-overlay" id="soundOverlay" onclick="if(event.target===this)closeSoundSettings()">
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
                <button onclick="previewSound('end')" style="background:#334155;color:#e2e8f0;border:1px solid #475569;border-radius:6px;padding:0.4rem 0.8rem;cursor:pointer;font-size:0.85rem">&#9654; Test</button>
            </div>
            <div style="margin-top:0.5rem">
                <label style="display:inline-block;background:#334155;color:#e2e8f0;border:1px solid #475569;border-radius:6px;padding:0.4rem 0.8rem;cursor:pointer;font-size:0.85rem">
                    Upload Custom...
                    <input type="file" id="alarmUpload" accept="audio/*" style="display:none" onchange="uploadSound('alarm')">
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
                <button onclick="previewSound('start')" style="background:#334155;color:#e2e8f0;border:1px solid #475569;border-radius:6px;padding:0.4rem 0.8rem;cursor:pointer;font-size:0.85rem">&#9654; Test</button>
            </div>
            <div style="margin-top:0.5rem">
                <label style="display:inline-block;background:#334155;color:#e2e8f0;border:1px solid #475569;border-radius:6px;padding:0.4rem 0.8rem;cursor:pointer;font-size:0.85rem">
                    Upload Custom...
                    <input type="file" id="startUpload" accept="audio/*" style="display:none" onchange="uploadSound('start')">
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
                <button onclick="previewSound('warning')" style="background:#334155;color:#e2e8f0;border:1px solid #475569;border-radius:6px;padding:0.4rem 0.8rem;cursor:pointer;font-size:0.85rem">&#9654; Test</button>
            </div>
            <div style="margin-top:0.5rem">
                <label style="display:inline-block;background:#334155;color:#e2e8f0;border:1px solid #475569;border-radius:6px;padding:0.4rem 0.8rem;cursor:pointer;font-size:0.85rem">
                    Upload Custom...
                    <input type="file" id="warningUpload" accept="audio/*" style="display:none" onchange="uploadSound('warning')">
                </label>
                <span id="warningUploadStatus" style="color:#94a3b8;font-size:0.8rem;margin-left:0.5rem"></span>
            </div>
        </div>

        <label style="display:flex;align-items:center;gap:.5rem;font-size:.85rem;color:#cbd5e1;margin-top:1rem;cursor:pointer">
            <input type="checkbox" id="muteStreamCheckbox" onchange="onMuteStreamToggle(this.checked)">
            Mute streaming video while alarms play
            <span style="color:#94a3b8;font-size:.72rem">&nbsp;(YouTube &amp; Vimeo only)</span>
        </label>

        <div class="timer-level-btns">
            <button class="btn-save" onclick="saveSoundSettings()">Save</button>
            <button class="btn-close-panel" onclick="closeSoundSettings()">Close</button>
        </div>
    </div>
</div>
<?php endif; ?>

<script src="/vendor/qrcode.min.js"></script>
<script src="/vendor/nosleep.min.js"></script>
<script>
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
var warningFired = false;
var endTimerFired = false;
var preMuteWarningFired = false;
var preMuteEndFired = false;

// ─── §7.2  Formatting helpers ───────────────────────────────────
function fmtTime(secs) {
    secs = Math.max(0, Math.floor(secs));
    var m = String(Math.floor(secs / 60)).padStart(2, '0');
    var s = String(secs % 60).padStart(2, '0');
    return m + ':' + s;
}
function fmtMoney(cents) {
    return '$' + (cents / 100).toFixed(2);
}
// Standalone "link this timer to an event" banner action.
function linkTimerToEvent() {
    var sel = document.getElementById('timerLinkSelect');
    if (!sel || !sel.value) return;
    window.location.href = '/timer.php?event_id=' + encodeURIComponent(sel.value);
}
function fmtChips(n) {
    if (n >= 1000000) return (n / 1000000).toFixed(1).replace(/\.0$/, '') + 'M';
    if (n >= 1000) return (n / 1000).toFixed(0) + 'K';
    return String(n);
}

// ─── §7.2.1  Get current level data ──────────────────────────────
function getLevelData(num) {
    for (var i = 0; i < LEVELS.length; i++) {
        if (parseInt(LEVELS[i].level_number) === num) return LEVELS[i];
    }
    return null;
}

// Seconds remaining until the next break level (current + full durations of
// intervening non-break levels). Returns null if no future break exists.
function computeNextBreakSeconds() {
    if (!LEVELS || !LEVELS.length) return null;
    var curIdx = -1;
    for (var i = 0; i < LEVELS.length; i++) {
        if (parseInt(LEVELS[i].level_number) === TIMER.current_level) { curIdx = i; break; }
    }
    if (curIdx < 0) return null;
    if (parseInt(LEVELS[curIdx].is_break)) return 0;  // currently on a break
    var total = Math.max(0, parseInt(TIMER.time_remaining_seconds) || 0);
    for (var j = curIdx + 1; j < LEVELS.length; j++) {
        if (parseInt(LEVELS[j].is_break)) return total;
        total += (parseInt(LEVELS[j].duration_minutes) || 0) * 60;
    }
    return null;
}

function fmtBreakClock(secs) {
    var h = Math.floor(secs / 3600);
    var m = Math.floor((secs % 3600) / 60);
    var s = secs % 60;
    var pad = function(n) { return (n < 10 ? '0' : '') + n; };
    return h > 0 ? (h + ':' + pad(m) + ':' + pad(s)) : (pad(m) + ':' + pad(s));
}

// ─── §7.3  Render ───────────────────────────────────────────────
function renderAll() {
    var lv = getLevelData(TIMER.current_level);
    var el = document.getElementById.bind(document);

    if (lv) {
        if (parseInt(lv.is_break)) {
            el('levelLabel').textContent = 'BREAK';
            el('blinds').textContent = 'Break Time';
            el('ante').textContent = '';
        } else {
            // Count play levels only
            var playNum = 0;
            for (var i = 0; i < LEVELS.length; i++) {
                if (!parseInt(LEVELS[i].is_break)) playNum++;
                if (parseInt(LEVELS[i].level_number) === TIMER.current_level) break;
            }
            el('levelLabel').textContent = 'Level ' + playNum;
            var blindsHtml = fmtChips(parseInt(lv.small_blind)) + ' / ' + fmtChips(parseInt(lv.big_blind));
            if (parseInt(lv.ante) > 0) {
                blindsHtml += ' / <span style="position:relative;display:inline-block">' + fmtChips(parseInt(lv.ante))
                    + '<span style="position:absolute;left:50%;transform:translateX(-50%);bottom:-0.6em;font-size:0.25em;color:#f59e0b;font-weight:700;letter-spacing:0.05em">ANTE</span></span>';
            }
            el('blinds').innerHTML = blindsHtml;
            el('ante').textContent = '';
        }
    }

    // Next level preview — same format as current blinds
    var nextLv = getLevelData(TIMER.current_level + 1);
    if (nextLv) {
        if (parseInt(nextLv.is_break)) {
            el('nextLevel').innerHTML = 'Next: Break';
        } else {
            var nextHtml = 'Next: ' + fmtChips(parseInt(nextLv.small_blind)) + ' / ' + fmtChips(parseInt(nextLv.big_blind));
            if (parseInt(nextLv.ante) > 0) {
                nextHtml += ' / <span style="position:relative;display:inline-block">' + fmtChips(parseInt(nextLv.ante))
                    + '<span style="position:absolute;left:50%;transform:translateX(-50%);bottom:-0.7em;font-size:0.45em;color:#f59e0b;font-weight:700;letter-spacing:0.05em">ANTE</span></span>';
            }
            el('nextLevel').innerHTML = nextHtml;
        }
    } else {
        el('nextLevel').innerHTML = 'Final Level';
    }

    renderClock();
    renderPlayBtn();

    // Stats
    // While in layout-edit mode, force-show all themable widgets even if their normal
    // display rules say "no data, hide me" — the user is positioning, not playing.
    var _inEdit = document.body.classList.contains('layout-edit');

    if (POOL) {
        var pc = el('playerCount'), pt = el('poolTotal');
        if (pc) pc.textContent = (POOL.still_playing || 0) + '/' + (POOL.bought_in || 0);
        if (pt) pt.textContent = fmtMoney(POOL.pool_total || 0);
    }
    // Pool + Players are always visible — theme.visible controls them if the user wants to hide.

    // Average stack (tournament only)
    var avgWrap = el('avgStackWrap');
    var avgVal  = el('avgStackValue');
    if (avgWrap && avgVal) {
        var stillPlaying = POOL ? (POOL.still_playing || 0) : 0;
        var chipsInPlay  = POOL ? (POOL.chips_in_play || 0) : 0;
        if (GAME_TYPE === 'tournament' && stillPlaying > 0 && chipsInPlay > 0) {
            var avg = Math.round(chipsInPlay / stillPlaying);
            avgVal.textContent = avg.toLocaleString();
            avgWrap.style.display = '';
        } else {
            avgWrap.style.display = _inEdit ? '' : 'none';
            if (_inEdit && !avgVal.textContent) avgVal.textContent = '-';
        }
    }

    // Reentries (tournament only) — total rebuys across the field
    var rbWrap = el('rebuysWrap'), rbVal = el('rebuysCount');
    if (rbWrap && rbVal) {
        if (GAME_TYPE === 'tournament' && POOL) {
            rbVal.textContent = (POOL.total_rebuys || 0);
            rbWrap.style.display = '';
        } else {
            rbWrap.style.display = _inEdit ? '' : 'none';
        }
    }

    // Chips in play (tournament only) — server-computed, single source of truth
    var cpWrap = el('chipsInPlayWrap'), cpVal = el('chipsInPlayVal');
    if (cpWrap && cpVal) {
        if (GAME_TYPE === 'tournament' && POOL && (POOL.chips_in_play || 0) > 0) {
            cpVal.textContent = (POOL.chips_in_play || 0).toLocaleString();
            cpWrap.style.display = '';
        } else {
            cpWrap.style.display = _inEdit ? '' : 'none';
            if (_inEdit && !cpVal.textContent) cpVal.textContent = '0';
        }
    }

    // Next break countdown (tournament only) — derived client-side from LEVELS
    var nbWrap = el('nextBreakWrap'), nbVal = el('nextBreakClock');
    if (nbWrap && nbVal) {
        var nbSecs = (GAME_TYPE === 'tournament') ? computeNextBreakSeconds() : null;
        if (nbSecs !== null) {
            nbVal.textContent = fmtBreakClock(Math.max(0, nbSecs));
            nbWrap.style.display = '';
        } else {
            nbWrap.style.display = _inEdit ? '' : 'none';
            if (_inEdit) nbVal.textContent = '--:--';
        }
    }

    // Payouts (tournament only)
    var payWrap = el('payoutsWrap');
    var payBody = el('payoutsBody');
    if (payWrap && payBody) {
        if (GAME_TYPE === 'tournament' && PAYOUTS && PAYOUTS.length > 0 && POOL && POOL.pool_total > 0) {
            var h = '';
            var ordinals = ['1st','2nd','3rd','4th','5th','6th','7th','8th','9th','10th'];
            for (var i = 0; i < PAYOUTS.length; i++) {
                var pct = parseFloat(PAYOUTS[i].percentage) || 0;
                var amt = Math.round(POOL.pool_total * pct / 100);
                h += '<div class="payout-row">' + (ordinals[i] || (i+1)+'th') + ': <b>' + fmtMoney(amt) + '</b> (' + pct + '%)</div>';
            }
            payBody.innerHTML = h;
            payWrap.style.display = '';
        } else {
            payWrap.style.display = _inEdit ? '' : 'none';
            if (_inEdit && !payBody.innerHTML.trim()) {
                payBody.innerHTML = '<div class="payout-row">1st: <b>$0.00</b> (50%)</div>'
                                  + '<div class="payout-row">2nd: <b>$0.00</b> (30%)</div>'
                                  + '<div class="payout-row">3rd: <b>$0.00</b> (20%)</div>';
            }
        }
    }

    // Paused label — show "PAUSED" placeholder while in edit mode so it can be themed.
    el('pausedLabel').textContent = (_inEdit || !TIMER.is_running) ? 'PAUSED' : '';
}

// Renderer registry — keyed by element key, then by variant name.
// Each renderer signature: function(node, secs, opts) where opts is the element's theme bag.
// Dispatch happens in the per-element render functions (renderClock, etc.).
window.TIMER_RENDERERS = window.TIMER_RENDERERS || {};
TIMER_RENDERERS.clock = {
    'text'          : renderClockText,
    'radial-ring'   : renderClockRadial,
    'radial-checks' : renderClockRadial
};

function renderClock() {
    var el = document.getElementById('timerClock');
    if (!el) return;
    var secs = Math.max(0, TIMER.time_remaining_seconds);
    var cTheme = (window.TIMER_THEME && window.TIMER_THEME.elements && window.TIMER_THEME.elements.clock) || {};
    var variant = cTheme.variant || 'text';

    // Threshold colour state stays in the dispatcher so every variant shares it (via currentColor).
    var critical = Math.max(1, parseInt(cTheme.critical_seconds, 10) || 30);
    var warning  = Math.max(critical + 1, parseInt(cTheme.warning_seconds, 10) || 120);
    el.classList.remove('timer-red','timer-yellow','timer-green');
    if (secs <= critical)      el.classList.add('timer-red');
    else if (secs <= warning)  el.classList.add('timer-yellow');
    else                       el.classList.add('timer-green');

    // Variant change: wipe inner DOM and the radial 'built' flag so the next renderer rebuilds cleanly.
    if (el.dataset.variant !== variant) {
        el.dataset.variant = variant;
        el.innerHTML = '';
        delete el.dataset.built;
        delete el.dataset.builtVariant;
        delete el.dataset.builtSegs;
    }
    var fn = (TIMER_RENDERERS.clock && TIMER_RENDERERS.clock[variant]) || TIMER_RENDERERS.clock.text;
    fn(el, secs, cTheme);
}

function renderClockText(node, secs) {
    var s = fmtTime(secs);
    if (node.textContent !== s) node.textContent = s;
}

// SVG arc-path helper used by the radial-checks variant.
// Returns a single arc "d" string for an arc on a circle centered (cx,cy) of radius r,
// sweeping from startAng to endAng (degrees, 0° = top, increases clockwise).
function describeArc(cx, cy, r, startAng, endAng) {
    function pt(a) {
        var rad = (a - 90) * Math.PI / 180;
        return { x: cx + r * Math.cos(rad), y: cy + r * Math.sin(rad) };
    }
    var p0 = pt(startAng), p1 = pt(endAng);
    var large = (endAng - startAng) <= 180 ? 0 : 1;
    return 'M ' + p0.x.toFixed(3) + ' ' + p0.y.toFixed(3)
         + ' A ' + r + ' ' + r + ' 0 ' + large + ' 1 ' + p1.x.toFixed(3) + ' ' + p1.y.toFixed(3);
}

// Radial clock renderer — used for both 'radial-ring' (no segments) and 'radial-checks' (N wedges).
// Builds SVG once, mutates attributes per tick to avoid flicker.
function renderClockRadial(node, secs, opts) {
    var variant = opts.variant || 'radial-ring';
    var checks  = (variant === 'radial-checks');
    var dir     = (opts.radial_direction === 'cw') ? 'cw' : 'ccw';
    var thick   = Math.max(0.02, Math.min(0.45, parseFloat(opts.radial_thickness) || 0.12));
    var segs    = Math.max(2, Math.min(60, parseInt(opts.radial_segments, 10) || 12));

    // Total level seconds — read from LEVELS for the active level, cache by level number.
    var lv = (typeof getLevelData === 'function') ? getLevelData(TIMER.current_level) : null;
    var totalSecs = lv ? (parseInt(lv.duration_minutes, 10) || 0) * 60 : 0;
    if (totalSecs <= 0) totalSecs = Math.max(secs, 1);  // fallback so the ring isn't blank pre-load
    var frac = (totalSecs - secs) / totalSecs;
    if (!isFinite(frac) || frac < 0) frac = 0;
    if (frac > 1) frac = 1;

    var R = 22;                                    // viewBox is 0 0 50 50
    var strokeW = (2 * R * thick).toFixed(2);
    var C = 2 * Math.PI * R;
    var built = node.dataset.built === '1';
    var builtVariant = node.dataset.builtVariant || '';
    var builtSegs = parseInt(node.dataset.builtSegs, 10) || 0;
    var needRebuild = !built || builtVariant !== variant || (checks && builtSegs !== segs);

    if (needRebuild) {
        var html = '<svg viewBox="0 0 50 50" preserveAspectRatio="xMidYMid meet" aria-hidden="true">';
        if (!checks) {
            html += '<circle class="clock-ring-bg" cx="25" cy="25" r="' + R + '" fill="none" '
                  + 'stroke="rgba(255,255,255,0.12)" stroke-width="' + strokeW + '"></circle>'
                  + '<circle class="clock-ring-fg" cx="25" cy="25" r="' + R + '" fill="none" '
                  + 'stroke="currentColor" stroke-width="' + strokeW + '" stroke-linecap="butt" '
                  + 'stroke-dasharray="' + C.toFixed(3) + '" stroke-dashoffset="0" '
                  + 'transform="rotate(-90 25 25)"></circle>';
        } else {
            var gap = Math.min(6, 360 / segs * 0.18);   // ~18% of each wedge as a gap, capped at 6°
            var wedge = 360 / segs - gap;
            html += '<g class="clock-check-bg">';
            for (var i = 0; i < segs; i++) {
                var a0 = i * (360 / segs) + gap / 2;
                var a1 = a0 + wedge;
                html += '<path d="' + describeArc(25, 25, R, a0, a1) + '" fill="none" '
                      + 'stroke="rgba(255,255,255,0.12)" stroke-width="' + strokeW + '" stroke-linecap="butt"></path>';
            }
            html += '</g><g class="clock-check-fg">';
            for (var j = 0; j < segs; j++) {
                var b0 = j * (360 / segs) + gap / 2;
                var b1 = b0 + wedge;
                html += '<path d="' + describeArc(25, 25, R, b0, b1) + '" fill="none" '
                      + 'stroke="currentColor" stroke-width="' + strokeW + '" stroke-linecap="butt" style="display:none"></path>';
            }
            html += '</g>';
        }
        html += '<text class="clock-num" x="25" y="25" text-anchor="middle" dominant-baseline="central">'
              + fmtTime(secs) + '</text></svg>';
        node.innerHTML = html;
        node.dataset.built = '1';
        node.dataset.builtVariant = variant;
        node.dataset.builtSegs = String(checks ? segs : 0);
    }

    // Per-tick mutations.
    if (!checks) {
        var fg = node.querySelector('.clock-ring-fg');
        if (fg) {
            // Default (ccw): ring "drains" — visible portion shrinks as frac→1.
            // For ccw we use negative offset; for cw we use positive offset.
            var offset = C * frac;
            if (dir === 'cw') offset = -offset;
            fg.setAttribute('stroke-dashoffset', offset.toFixed(3));
        }
    } else {
        var lit = Math.round(segs * frac);
        var fgs = node.querySelectorAll('.clock-check-fg path');
        for (var k = 0; k < fgs.length; k++) {
            // Direction: ccw lights from the right side counterclockwise; cw fills clockwise from top.
            var idx = (dir === 'cw') ? k : (segs - 1 - k);
            fgs[k].style.display = (idx < lit) ? '' : 'none';
        }
    }
    var num = node.querySelector('.clock-num');
    if (num) {
        var s = fmtTime(secs);
        if (num.textContent !== s) num.textContent = s;
    }
}

function renderPlayBtn() {
    var btn = document.getElementById('btnPlay');
    if (!btn) return;
    if (TIMER.is_running) {
        btn.innerHTML = '&#9646;&#9646;<span class="tray-label">Pause</span>';
        btn.classList.add('is-running');
    } else {
        btn.innerHTML = '&#9654;<span class="tray-label">Start</span>';
        btn.classList.remove('is-running');
    }
}

// Helper: append session or key identifier to FormData
function appendTimerId(fd) {
    if (SESSION_ID) fd.append('session_id', SESSION_ID);
    else fd.append('key', REMOTE_KEY);
}

// ─── §7.4  Send command to server API ───────────────────────────
function sendCommand(cmd) {
    var fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('action', 'command');
    fd.append('cmd', cmd);
    appendTimerId(fd);
    fetch('/timer_dl.php', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(j) {
            if (!j.ok) console.error('Command error:', j.error);
            // Immediately poll to get new state
            pollState();
        })
        .catch(function(e) { console.error('Command error:', e); });
}

// ─── §7.5  Poll server (everyone does this — server is master) ──
var prevLevel = TIMER.current_level;
function pollState() {
    var url;
    // Remote viewers are not authenticated — always use the public key endpoint.
    // session_id endpoint requires login and would return {ok:false} for QR-scan visitors.
    if (!IS_REMOTE && SESSION_ID) {
        url = '/timer_dl.php?action=get_state&session_id=' + SESSION_ID;
    } else {
        url = '/timer_dl.php?action=get_state&key=' + encodeURIComponent(REMOTE_KEY);
    }
    fetch(url).then(function(r) { return r.json(); }).then(function(j) {
        if (!j.ok) return;
        if (j.timer) {
            TIMER.current_level = j.timer.current_level;
            TIMER.time_remaining_seconds = j.timer.time_remaining_seconds;
            TIMER.is_running = !!j.timer.is_running;
            if (j.timer.current_level !== prevLevel) {
                playStartTimer();
                prevLevel = j.timer.current_level;
                warningFired = false;
                endTimerFired = false;
                preMuteWarningFired = false;
                preMuteEndFired = false;
            }
        }
        // Don't overwrite levels while the editor panel is open (user may be editing)
        var levelsOpen = document.getElementById('levelsOverlay') && document.getElementById('levelsOverlay').classList.contains('open');
        if (j.levels && !levelsOpen) LEVELS = j.levels;
        if (j.payouts) PAYOUTS = j.payouts;
        if (j.game_type) GAME_TYPE = j.game_type;
        if (j.sounds) {
            SOUNDS.warning_seconds = j.sounds.warning_seconds;
            SOUNDS.alarm_sound = j.sounds.alarm_sound;
            SOUNDS.warning_sound = j.sounds.warning_sound;
        }
        if (j.csrf_token) CSRF = j.csrf_token;
        if (j.can_control !== undefined) {
            CAN_CONTROL = j.can_control;
            var ctrl = document.getElementById('controls');
            if (ctrl) ctrl.style.display = CAN_CONTROL ? '' : 'none';
        }
        POOL = j.pool;
        // Theme: re-apply when the server version differs and we're not actively
        // editing locally. The LAYOUT_EDIT_ON gate is critical — without it, this
        // poll would clobber the user's in-progress edit (local pos values, panel
        // toggles, etc.) every 2s with the server's stale snapshot.
        // The themeOpen gate covers the library modal being open.
        if (j.theme && typeof applyTheme === 'function') {
            var themeOpen = document.getElementById('themeOverlay') && document.getElementById('themeOverlay').classList.contains('open');
            if (!themeOpen && !LAYOUT_EDIT_ON) {
                var newPropsStr = JSON.stringify(j.theme.properties || {});
                var idChanged = (j.theme.id !== window.TIMER_THEME_ID);
                var propsChanged = (newPropsStr !== window.TIMER_THEME_PROPS_JSON);
                if (idChanged || propsChanged) {
                    window.TIMER_THEME = j.theme.properties;
                    window.TIMER_THEME_ID = j.theme.id;
                    window.TIMER_THEME_PROPS_JSON = newPropsStr;
                    applyTheme(j.theme.properties);
                }
            }
        }
        renderAll();
    }).catch(function() {});
}

// ─── §7.6  Local tick (smooth display between polls) ────────────
function startLocalTick() {
    if (localInterval) return;
    localInterval = setInterval(function() {
        if (!TIMER.is_running) return;
        TIMER.time_remaining_seconds--;

        // Pre-mute stream 3 seconds before the warning beep, so the alarm cuts in cleanly.
        // The alarm's own muteStreamForAlarm call 3s later will refresh the unmute timer.
        if (SOUNDS.warning_seconds > 0 && !preMuteWarningFired && TIMER.time_remaining_seconds === SOUNDS.warning_seconds + 3) {
            preMuteWarningFired = true;
            muteStreamForAlarm(7000);  // 3s pre + ~1s warning + 3s post
        }

        // Warning alert
        if (SOUNDS.warning_seconds > 0 && !warningFired && TIMER.time_remaining_seconds === SOUNDS.warning_seconds) {
            warningFired = true;
            playWarning();
        }

        // Pre-mute stream 3 seconds before the end-of-level alarm (which itself fires
        // 3s before the level ends — so the pre-mute lands at remaining=6s).
        if (!preMuteEndFired && TIMER.time_remaining_seconds === 6) {
            preMuteEndFired = true;
            muteStreamForAlarm(9000);  // 3s pre + 3s end alarm + 3s post
        }

        // End timer: 3 beeps over 3 seconds before level ends
        if (!endTimerFired && TIMER.time_remaining_seconds === 3) {
            endTimerFired = true;
            playEndTimer();
        }

        if (TIMER.time_remaining_seconds <= 0) {
            TIMER.time_remaining_seconds = 0;
            warningFired = false;
            endTimerFired = false;
            preMuteWarningFired = false;
            preMuteEndFired = false;
            pollState();
        }
        renderClock();
    }, 1000);
}

// ─── §7.4.1  Controls (all send commands to server) ───────────────
function togglePlay() { sendCommand('toggle_play'); }
function toggleTray() {
    var tray = document.getElementById('timerTray');
    if (tray) tray.classList.toggle('open');
}
function skipLevel(dir) { sendCommand(dir > 0 ? 'skip_next' : 'skip_prev'); }
function adjustTime(delta) { sendCommand(delta > 0 ? 'add_time' : 'sub_time'); }
function resetLevel() { sendCommand('reset_level'); }
function resetTimer() { if (confirm('Reset entire timer to Level 1?')) sendCommand('reset_timer'); }

function toggleSound() {
    soundEnabled = !soundEnabled;
    var btn = document.getElementById('btnSound');
    if (btn) { btn.innerHTML = (soundEnabled ? '&#128276;' : '&#128263;') + '<span class="tray-label">Sound</span>'; btn.title = soundEnabled ? 'Sound on' : 'Sound off'; }
}

function goFullscreen() {
    var el = document.documentElement;
    if (el.requestFullscreen) el.requestFullscreen();
    else if (el.webkitRequestFullscreen) el.webkitRequestFullscreen();
}

// Auto-fullscreen: browsers only allow requestFullscreen from a user gesture,
// so a true on-load fullscreen is impossible. Instead, the FIRST interaction
// with the page (tap/click/keypress) promotes it to fullscreen — effectively
// automatic for a screen the host is about to touch anyway. Once per load;
// pressing Esc afterwards is respected (we never re-force). Skipped where the
// Fullscreen API is unavailable (e.g. iPhone Safari, which also hides the
// Full button below).
(function() {
    if (!document.documentElement.requestFullscreen && !document.documentElement.webkitRequestFullscreen) return;
    var fired = false;
    function autoFs() {
        if (fired) return;
        fired = true;
        document.removeEventListener('pointerdown', autoFs, true);
        document.removeEventListener('keydown', autoFs, true);
        if (document.fullscreenElement || document.webkitFullscreenElement) return;
        try { goFullscreen(); } catch (e) { /* embedded/denied: stay windowed */ }
    }
    document.addEventListener('pointerdown', autoFs, true);
    document.addEventListener('keydown', autoFs, true);
})();

// ─── §7.7  Wake Lock (prevent screen sleep) ─────────────────────
var wakeBanner = document.getElementById('wakeBanner');
var wakeLock = null;
var wakeLockAcquired = false;
// NoSleep.js fallback (hidden silent video) — needed for iPhone Safari over
// plain HTTP (LAN dev access) and any browser where navigator.wakeLock isn't
// available. Loaded via /vendor/nosleep.min.js. Instantiate lazily so missing
// vendor file doesn't throw.
var noSleep = null;
var noSleepEnabled = false;
try { if (typeof NoSleep !== 'undefined') noSleep = new NoSleep(); } catch(e) {}

function hideWakeBanner() {
    if (!wakeBanner) return;
    wakeBanner.style.opacity = '0';
    setTimeout(function() { if (wakeBanner) wakeBanner.remove(); wakeBanner = null; }, 600);
}

async function requestWakeLock() {
    if (!('wakeLock' in navigator) || wakeLockAcquired) return;
    try {
        wakeLock = await navigator.wakeLock.request('screen');
        wakeLockAcquired = true;
        hideWakeBanner();
        wakeLock.addEventListener('release', function() { wakeLock = null; wakeLockAcquired = false; });
    } catch(e) {}
}

// Touch-device gesture handler: tries the modern API and the NoSleep fallback
// in parallel, both inside the user-gesture window. iOS Safari over plain HTTP
// has no navigator.wakeLock at all, so NoSleep is the only mechanism that works
// for LAN dev access; on HTTPS production both engage and whichever sticks wins.
function acquireWakeFromGesture() {
    requestWakeLock();  // promise; gesture is captured at call time
    if (noSleep && !noSleepEnabled) {
        var p;
        try { p = noSleep.enable(); } catch(e) { return; }
        if (p && typeof p.then === 'function') {
            p.then(function() {
                noSleepEnabled = true;
                hideWakeBanner();
            }).catch(function() {});
        } else {
            // Older NoSleep builds return undefined synchronously.
            noSleepEnabled = true;
            hideWakeBanner();
        }
    }
}

// Hide banner on desktop (no need)
if (!('ontouchstart' in window) && navigator.maxTouchPoints === 0) {
    if (wakeBanner) wakeBanner.remove();
}

// Try the modern API on load (no NoSleep yet — that needs a real gesture).
requestWakeLock();
// Acquire on user interaction (required by iOS Safari for either mechanism).
document.addEventListener('click', acquireWakeFromGesture, true);
document.addEventListener('touchend', acquireWakeFromGesture, true);
// Re-acquire when tab becomes visible and immediately resync timer state.
document.addEventListener('visibilitychange', function() {
    if (document.visibilityState === 'visible') {
        wakeLockAcquired = false;
        requestWakeLock();
        // NoSleep needs a real gesture to re-enable, so we don't auto-restart it here.
        pollState(); // resync immediately — Android may have throttled intervals while hidden
    }
});

// ─── §7.8  Sound alert ──────────────────────────────────────────
// Unlock audio on first user interaction (required by iOS/Android)
var audioUnlocked = false;
function unlockAudio() {
    if (audioUnlocked) return;
    try {
        if (!audioCtx) audioCtx = new (window.AudioContext || window.webkitAudioContext)();
        if (audioCtx.state === 'suspended') audioCtx.resume();
        // Play a silent buffer to unlock
        var buf = audioCtx.createBuffer(1, 1, 22050);
        var src = audioCtx.createBufferSource();
        src.buffer = buf;
        src.connect(audioCtx.destination);
        src.start(0);
        audioUnlocked = true;
    } catch(e) {}
}
document.addEventListener('click', unlockAudio, true);
document.addEventListener('touchend', unlockAudio, true);

function ensureAudioCtx() {
    if (!audioCtx) audioCtx = new (window.AudioContext || window.webkitAudioContext)();
    if (audioCtx.state === 'suspended') audioCtx.resume();
    return audioCtx;
}

function playCustomSound(url) {
    try {
        var audio = new Audio(url);
        audio.volume = 0.8;
        audio.play().catch(function() {});
    } catch(e) {}
}

// End Timer: default is 5 beeps over 3 seconds
function playEndTimer() {
    if (!soundEnabled) return;
    muteStreamForAlarm(6000);  // 3s end alarm + 3s post-padding
    if (SOUNDS.alarm_sound) {
        if (SOUNDS.alarm_sound.indexOf('preset:') === 0) { playPresetEnd(SOUNDS.alarm_sound); return; }
        playCustomSound(SOUNDS.alarm_sound); return;
    }
    // Default: 5 evenly spaced beeps over 3 seconds (same as preset:five3s)
    try {
        var ctx = ensureAudioCtx();
        var t = ctx.currentTime;
        for (var i = 0; i < 5; i++) {
            var osc = ctx.createOscillator();
            var gain = ctx.createGain();
            osc.type = 'sine';
            osc.frequency.value = 880;
            gain.gain.value = 0.3;
            osc.connect(gain);
            gain.connect(ctx.destination);
            osc.start(t + i * 0.6);
            osc.stop(t + i * 0.6 + 0.35);
        }
    } catch(e) {}
}

// Start Timer: 1 long beep (1 second, higher pitch)
function playStartTimer() {
    if (!soundEnabled) return;
    muteStreamForAlarm(4000);  // 1s tone + 3s post-padding (no pre-padding — user-triggered)
    if (SOUNDS.start_sound) {
        if (SOUNDS.start_sound.indexOf('preset:') === 0) { playPresetEnd(SOUNDS.start_sound); return; }
        playCustomSound(SOUNDS.start_sound); return;
    }
    try {
        var ctx = ensureAudioCtx();
        var osc = ctx.createOscillator();
        var gain = ctx.createGain();
        osc.type = 'sine';
        osc.frequency.value = 880;
        gain.gain.value = 0.35;
        // Fade out at the end
        gain.gain.setValueAtTime(0.35, ctx.currentTime);
        gain.gain.linearRampToValueAtTime(0, ctx.currentTime + 1.0);
        osc.connect(gain);
        gain.connect(ctx.destination);
        osc.start(ctx.currentTime);
        osc.stop(ctx.currentTime + 1.0);
    } catch(e) {}
}

// Warning: 5 quick beeps
function playWarning() {
    if (!soundEnabled) return;
    muteStreamForAlarm(4000);  // ~1s of beeps + 3s post-padding
    if (SOUNDS.warning_sound) {
        if (SOUNDS.warning_sound.indexOf('preset:') === 0) { playPresetWarning(SOUNDS.warning_sound); return; }
        playCustomSound(SOUNDS.warning_sound); return;
    }
    try {
        var ctx = ensureAudioCtx();
        for (var i = 0; i < 5; i++) {
            var osc = ctx.createOscillator();
            var gain = ctx.createGain();
            osc.type = 'sine';
            osc.frequency.value = 660;
            gain.gain.value = 0.3;
            osc.connect(gain);
            gain.connect(ctx.destination);
            osc.start(ctx.currentTime + i * 0.2);
            osc.stop(ctx.currentTime + i * 0.2 + 0.1);
        }
    } catch(e) {}
}

// ─── §7.8.1  Preset sound patterns ───────────────────────────────
function playPresetEnd(key) {
    try {
        var ctx = ensureAudioCtx();
        var t = ctx.currentTime;
        switch (key) {
            case 'preset:buzzer':
                // Low harsh buzz
                var o = ctx.createOscillator(), g = ctx.createGain();
                o.type = 'square'; o.frequency.value = 180; g.gain.value = 0.3;
                o.connect(g); g.connect(ctx.destination);
                g.gain.setValueAtTime(0.3, t); g.gain.linearRampToValueAtTime(0, t + 1.2);
                o.start(t); o.stop(t + 1.2);
                break;
            case 'preset:chime':
                // 3 ascending bright tones (C5 E5 G5)
                [523, 659, 784].forEach(function(f, i) {
                    var o = ctx.createOscillator(), g = ctx.createGain();
                    o.type = 'sine'; o.frequency.value = f; g.gain.value = 0.3;
                    o.connect(g); g.connect(ctx.destination);
                    o.start(t + i * 0.35); o.stop(t + i * 0.35 + 0.3);
                });
                break;
            case 'preset:casino':
                // Quick ding-ding-ding (high bell tones)
                [1200, 1400, 1200, 1400, 1600].forEach(function(f, i) {
                    var o = ctx.createOscillator(), g = ctx.createGain();
                    o.type = 'sine'; o.frequency.value = f; g.gain.value = 0.25;
                    g.gain.setValueAtTime(0.25, t + i * 0.15);
                    g.gain.linearRampToValueAtTime(0, t + i * 0.15 + 0.12);
                    o.connect(g); g.connect(ctx.destination);
                    o.start(t + i * 0.15); o.stop(t + i * 0.15 + 0.15);
                });
                break;
            case 'preset:horn':
                // Rising sawtooth blast
                var o = ctx.createOscillator(), g = ctx.createGain();
                o.type = 'sawtooth'; o.frequency.setValueAtTime(200, t);
                o.frequency.linearRampToValueAtTime(600, t + 0.8);
                g.gain.value = 0.25;
                g.gain.setValueAtTime(0.25, t); g.gain.linearRampToValueAtTime(0, t + 1.0);
                o.connect(g); g.connect(ctx.destination);
                o.start(t); o.stop(t + 1.0);
                break;
            case 'preset:countdown':
                // 3-2-1-GO: 3 short pips then a long tone
                [0, 0.6, 1.2].forEach(function(delay) {
                    var o = ctx.createOscillator(), g = ctx.createGain();
                    o.type = 'sine'; o.frequency.value = 800; g.gain.value = 0.3;
                    o.connect(g); g.connect(ctx.destination);
                    o.start(t + delay); o.stop(t + delay + 0.15);
                });
                var oGo = ctx.createOscillator(), gGo = ctx.createGain();
                oGo.type = 'sine'; oGo.frequency.value = 1200; gGo.gain.value = 0.35;
                gGo.gain.setValueAtTime(0.35, t + 1.8);
                gGo.gain.linearRampToValueAtTime(0, t + 2.6);
                oGo.connect(gGo); gGo.connect(ctx.destination);
                oGo.start(t + 1.8); oGo.stop(t + 2.6);
                break;
            case 'preset:double':
                // Two firm beeps (tournament clock)
                [0, 0.4].forEach(function(delay) {
                    var o = ctx.createOscillator(), g = ctx.createGain();
                    o.type = 'square'; o.frequency.value = 700; g.gain.value = 0.25;
                    o.connect(g); g.connect(ctx.destination);
                    o.start(t + delay); o.stop(t + delay + 0.2);
                });
                break;
            case 'preset:descending':
                // 3 descending beeps (old default)
                [0, 1, 2].forEach(function(i) {
                    var o = ctx.createOscillator(), g = ctx.createGain();
                    o.type = 'sine'; o.frequency.value = 880 - (i * 110); g.gain.value = 0.35;
                    o.connect(g); g.connect(ctx.destination);
                    o.start(t + i); o.stop(t + i + 0.4);
                });
                break;
            case 'preset:five3s':
                // 5 evenly spaced beeps over 3 seconds
                for (var i = 0; i < 5; i++) {
                    var o = ctx.createOscillator(), g = ctx.createGain();
                    o.type = 'sine'; o.frequency.value = 880; g.gain.value = 0.3;
                    o.connect(g); g.connect(ctx.destination);
                    o.start(t + i * 0.6); o.stop(t + i * 0.6 + 0.35);
                }
                break;
        }
    } catch(e) {}
}

function playPresetWarning(key) {
    try {
        var ctx = ensureAudioCtx();
        var t = ctx.currentTime;
        switch (key) {
            case 'preset:tick':
                // Soft rapid clicks
                for (var i = 0; i < 8; i++) {
                    var o = ctx.createOscillator(), g = ctx.createGain();
                    o.type = 'sine'; o.frequency.value = 2000; g.gain.value = 0.15;
                    o.connect(g); g.connect(ctx.destination);
                    o.start(t + i * 0.12); o.stop(t + i * 0.12 + 0.02);
                }
                break;
            case 'preset:pulse':
                // Rhythmic low pulse (heartbeat)
                [0, 0.15, 0.6, 0.75].forEach(function(delay) {
                    var o = ctx.createOscillator(), g = ctx.createGain();
                    o.type = 'sine'; o.frequency.value = 80; g.gain.value = 0.3;
                    g.gain.setValueAtTime(0.3, t + delay);
                    g.gain.linearRampToValueAtTime(0, t + delay + 0.12);
                    o.connect(g); g.connect(ctx.destination);
                    o.start(t + delay); o.stop(t + delay + 0.15);
                });
                break;
            case 'preset:chirp':
                // Quick high-pitched chirps
                for (var i = 0; i < 4; i++) {
                    var o = ctx.createOscillator(), g = ctx.createGain();
                    o.type = 'sine'; o.frequency.setValueAtTime(1500, t + i * 0.25);
                    o.frequency.linearRampToValueAtTime(2500, t + i * 0.25 + 0.08);
                    g.gain.value = 0.2;
                    o.connect(g); g.connect(ctx.destination);
                    o.start(t + i * 0.25); o.stop(t + i * 0.25 + 0.1);
                }
                break;
            case 'preset:gentle':
                // Single soft sustained tone
                var o = ctx.createOscillator(), g = ctx.createGain();
                o.type = 'sine'; o.frequency.value = 440; g.gain.value = 0.2;
                g.gain.setValueAtTime(0, t);
                g.gain.linearRampToValueAtTime(0.2, t + 0.1);
                g.gain.linearRampToValueAtTime(0, t + 1.5);
                o.connect(g); g.connect(ctx.destination);
                o.start(t); o.stop(t + 1.5);
                break;
        }
    } catch(e) {}
}

// ─── §7.9  Sound settings ──────────────────────────────────────
function openSoundSettings() {
    var sel = document.getElementById('warningSeconds');
    if (sel) sel.value = String(SOUNDS.warning_seconds);
    // Set current selections
    setSelectValue('alarmSoundSelect', SOUNDS.alarm_sound || '');
    setSelectValue('startSoundSelect', SOUNDS.start_sound || '');
    setSelectValue('warningSoundSelect', SOUNDS.warning_sound || '');
    // Mute-stream-during-alarms toggle — localStorage-backed (per-device viewer pref).
    var cb = document.getElementById('muteStreamCheckbox');
    if (cb) {
        var v = null;
        try { v = localStorage.getItem('gn.muteStreamDuringAlarms'); } catch (e) {}
        cb.checked = (v === null) ? true : (v !== 'false');  // default ON
    }
    document.getElementById('soundOverlay').classList.add('open');
}

function onMuteStreamToggle(on) {
    try { localStorage.setItem('gn.muteStreamDuringAlarms', on ? 'true' : 'false'); } catch (e) {}
}
function closeSoundSettings() {
    document.getElementById('soundOverlay').classList.remove('open');
}
function setSelectValue(id, val) {
    var sel = document.getElementById(id);
    if (!sel) return;
    // Add custom option if not present
    if (val && !sel.querySelector('option[value="' + val + '"]')) {
        var opt = document.createElement('option');
        opt.value = val;
        opt.textContent = 'Custom: ' + val.split('/').pop();
        sel.appendChild(opt);
    }
    sel.value = val;
}

function uploadSound(type) {
    var inputId = type === 'alarm' ? 'alarmUpload' : (type === 'start' ? 'startUpload' : 'warningUpload');
    var statusId = type === 'alarm' ? 'alarmUploadStatus' : (type === 'start' ? 'startUploadStatus' : 'warningUploadStatus');
    var input = document.getElementById(inputId);
    var status = document.getElementById(statusId);
    if (!input.files[0]) return;
    status.textContent = 'Uploading...';
    var fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('action', 'upload_sound');
    appendTimerId(fd);
    fd.append('sound', input.files[0]);
    fetch('/timer_dl.php', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(j) {
            if (j.ok) {
                status.textContent = 'Uploaded!';
                status.style.color = '#22c55e';
                var selId = type === 'alarm' ? 'alarmSoundSelect' : (type === 'start' ? 'startSoundSelect' : 'warningSoundSelect');
                setSelectValue(selId, j.url);
                document.getElementById(selId).value = j.url;
            } else {
                status.textContent = j.error || 'Upload failed';
                status.style.color = '#ef4444';
            }
        })
        .catch(function() { status.textContent = 'Upload failed'; status.style.color = '#ef4444'; });
}

function saveSoundSettings() {
    SOUNDS.warning_seconds = parseInt(document.getElementById('warningSeconds').value) || 0;
    SOUNDS.alarm_sound = document.getElementById('alarmSoundSelect').value || null;
    SOUNDS.start_sound = document.getElementById('startSoundSelect').value || null;
    SOUNDS.warning_sound = document.getElementById('warningSoundSelect').value || null;

    var fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('action', 'update_sounds');
    appendTimerId(fd);
    fd.append('warning_seconds', SOUNDS.warning_seconds);
    fd.append('alarm_sound', SOUNDS.alarm_sound || '');
    fd.append('start_sound', SOUNDS.start_sound || '');
    fd.append('warning_sound', SOUNDS.warning_sound || '');
    fetch('/timer_dl.php', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(j) {
            if (j.ok) closeSoundSettings();
            else alert(j.error || 'Error saving');
        });
}

function previewSound(type) {
    ensureAudioCtx();
    if (type === 'end') {
        var val = document.getElementById('alarmSoundSelect').value;
        if (val && val.indexOf('preset:') === 0) { playPresetEnd(val); }
        else if (val) { playCustomSound(val); }
        else { /* play default end */ var old = SOUNDS.alarm_sound; SOUNDS.alarm_sound = null; playEndTimer(); SOUNDS.alarm_sound = old; }
    } else if (type === 'start') {
        var val = document.getElementById('startSoundSelect').value;
        if (val && val.indexOf('preset:') === 0) { playPresetEnd(val); }
        else if (val) { playCustomSound(val); }
        else { var old = SOUNDS.start_sound; SOUNDS.start_sound = null; playStartTimer(); SOUNDS.start_sound = old; }
    } else {
        var val = document.getElementById('warningSoundSelect').value;
        if (val && val.indexOf('preset:') === 0) { playPresetWarning(val); }
        else if (val) { playCustomSound(val); }
        else { var old = SOUNDS.warning_sound; SOUNDS.warning_sound = null; playWarning(); SOUNDS.warning_sound = old; }
    }
}

// ─── §7.10  Levels editor ────────────────────────────────────────
function openLevels() {
    loadPresetList();
    levelsCollected = true; // skip collecting from stale/empty DOM
    renderLevelsTable();
    document.getElementById('levelsOverlay').classList.add('open');
    updateSaveBtnState();
    maybeRestoreLevelsDraft(); // offer to recover edits lost to a reload/tab-discard
}
function closeLevels() {
    if (levelsDirty) { document.getElementById('closeConfirmOverlay').classList.add('open'); return; }
    doCloseLevels();
}
// Actually tear down the editor panel (no prompt).
function doCloseLevels() {
    document.getElementById('levelsOverlay').classList.remove('open');
    document.getElementById('levelsBody').innerHTML = ''; // clear stale inputs
}
// "Keep editing" — dismiss the prompt and stay in the editor.
function closeCloseConfirm() {
    document.getElementById('closeConfirmOverlay').classList.remove('open');
}
// "Discard" — throw away the in-memory edits AND the saved draft, then close.
// A poll immediately reloads LEVELS from the server (the last-saved structure),
// reverting anything the user typed.
function discardLevelsAndClose() {
    closeCloseConfirm();
    discardLevelsDraft(); // clears levelsDirty + wipes the localStorage restore-draft
    updateSaveBtnState();  // reset the Save button label
    doCloseLevels();
    pollState(); // editor now closed → LEVELS refreshes to saved state + renderAll()
}

var dragSrcIdx = null;

var levelsCollected = false;
function renderLevelsTable() {
    if (!levelsCollected) collectLevelsFromTable(); // preserve any in-progress edits
    levelsCollected = false;
    var tb = document.getElementById('levelsBody');
    var h = '';
    for (var i = 0; i < LEVELS.length; i++) {
        var lv = LEVELS[i];
        var brk = parseInt(lv.is_break);
        var cls = brk ? ' class="is-break"' : '';
        if (parseInt(lv.level_number) === TIMER.current_level) cls = ' class="current-level"';
        h += '<tr' + cls + ' data-idx="' + i + '" ondragover="onDragOver(event)" ondrop="onDrop(event)">';
        h += '<td draggable="true" ondragstart="onDragStart(event)" ondragend="onDragEnd()" style="cursor:grab;color:#64748b;user-select:none" title="Drag to reorder">&#9776; ' + (i + 1) + '</td>';
        h += '<td><input type="number" value="' + (brk ? 0 : lv.small_blind) + '" data-idx="' + i + '" data-field="small_blind" oninput="markLevelsDirty()"' + (brk ? ' disabled' : '') + '></td>';
        h += '<td><input type="number" value="' + (brk ? 0 : lv.big_blind) + '" data-idx="' + i + '" data-field="big_blind" oninput="markLevelsDirty()"' + (brk ? ' disabled' : '') + '></td>';
        h += '<td><input type="number" value="' + (brk ? 0 : lv.ante) + '" data-idx="' + i + '" data-field="ante" oninput="markLevelsDirty()"' + (brk ? ' disabled' : '') + '></td>';
        h += '<td><input type="number" value="' + lv.duration_minutes + '" data-idx="' + i + '" data-field="duration_minutes" oninput="markLevelsDirty()" style="width:55px"></td>';
        h += '<td>' + (brk ? 'BREAK' : 'Play') + '</td>';
        h += '<td class="lvl-actions">';
        h += '<button class="lvl-move" onclick="moveLevel(' + i + ', -1)" title="Move up" style="color:#94a3b8"' + (i === 0 ? ' disabled' : '') + '>&#9650;</button>';
        h += '<button class="lvl-move" onclick="moveLevel(' + i + ', 1)" title="Move down" style="color:#94a3b8"' + (i === LEVELS.length - 1 ? ' disabled' : '') + '>&#9660;</button>';
        h += '<button onclick="insertLevel(' + i + ', false)" title="Insert level here" style="color:#22c55e;font-size:0.9rem">+</button>';
        h += '<button onclick="insertLevel(' + i + ', true)" title="Insert break here" style="color:#fbbf24;font-size:0.9rem">&#9202;</button>';
        h += '<button onclick="removeLevel(' + i + ')" title="Remove">&times;</button>';
        h += '</td>';
        h += '</tr>';
    }
    tb.innerHTML = h;
}

// ─── §7.11  Drag and drop reorder ───────────────────────────────
function onDragStart(e) {
    var row = e.currentTarget.closest('tr');
    dragSrcIdx = parseInt(row.dataset.idx);
    e.dataTransfer.effectAllowed = 'move';
    // Use the whole level row as the drag image so the entire level floats as a
    // ghost under the cursor — by default it would be just the small handle cell.
    if (e.dataTransfer.setDragImage) {
        var rect = row.getBoundingClientRect();
        e.dataTransfer.setDragImage(row, e.clientX - rect.left, e.clientY - rect.top);
    }
    // Dim the original row as a gap, but only after the ghost has been captured
    // (a 0ms defer), so the floating copy stays crisp.
    setTimeout(function() { row.classList.add('lvl-dragging'); }, 0);
}
function onDragOver(e) {
    e.preventDefault();
    e.dataTransfer.dropEffect = 'move';
    var row = e.currentTarget.closest ? e.currentTarget.closest('tr') : e.currentTarget;
    var rows = document.querySelectorAll('#levelsBody tr');
    rows.forEach(function(r) { r.style.borderTop = ''; r.style.borderBottom = ''; });
    var targetIdx = parseInt(row.dataset.idx);
    if (targetIdx < dragSrcIdx) {
        row.style.borderTop = '2px solid #2563eb';
    } else {
        row.style.borderBottom = '2px solid #2563eb';
    }
}
function onDrop(e) {
    e.preventDefault();
    var row = e.currentTarget.closest ? e.currentTarget.closest('tr') : e.currentTarget;
    var targetIdx = parseInt(row.dataset.idx);
    if (dragSrcIdx === null || dragSrcIdx === targetIdx) return;
    collectLevelsFromTable(); levelsCollected = true;
    var item = LEVELS.splice(dragSrcIdx, 1)[0];
    LEVELS.splice(targetIdx, 0, item);
    renumberLevels();
    markLevelsDirty();
    renderLevelsTable();
    dragSrcIdx = null;
}

// ─── §7.11.1  Reorder via buttons (works on touch / iPad, unlike HTML5 drag) ───
// Animated with a FLIP transition: measure old row positions, swap + re-render,
// then transform each row back to where it was and let it slide into place.
function moveLevel(idx, dir) {
    collectLevelsFromTable(); levelsCollected = true;
    var j = idx + dir;
    if (j < 0 || j >= LEVELS.length) return;

    var body = document.getElementById('levelsBody');
    var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    // First: record current row tops (keyed by their current position)
    var oldTops = [];
    if (body && !reduce) {
        Array.prototype.forEach.call(body.children, function(r) { oldTops.push(r.getBoundingClientRect().top); });
    }

    var tmp = LEVELS[idx]; LEVELS[idx] = LEVELS[j]; LEVELS[j] = tmp;
    renumberLevels();
    markLevelsDirty();
    renderLevelsTable();

    if (!body || reduce) return;

    // The clicked row's content now lives at position j; everything else holds
    // its position except the two that swapped. Map new position -> old position.
    function oldPosOf(p) { return p === idx ? j : (p === j ? idx : p); }
    var newRows = Array.prototype.slice.call(body.children);

    // Invert: shift each row back to its pre-swap position with no transition
    newRows.forEach(function(r, p) {
        var delta = oldTops[oldPosOf(p)] - r.getBoundingClientRect().top;
        if (!delta) return;
        r.style.transition = 'none';
        r.style.transform = 'translateY(' + delta + 'px)';
    });

    // Play: next frame, animate the transforms away
    requestAnimationFrame(function() {
        newRows.forEach(function(r) {
            if (!r.style.transform) return;
            r.style.transition = 'transform 0.18s ease';
            r.style.transform = '';
            r.addEventListener('transitionend', function clear() {
                r.style.transition = ''; r.removeEventListener('transitionend', clear);
            });
        });
        var moved = newRows[j];
        if (moved) { moved.classList.add('lvl-moved'); setTimeout(function() { moved.classList.remove('lvl-moved'); }, 650); }
    });
}
function onDragEnd() {
    dragSrcIdx = null;
    var rows = document.querySelectorAll('#levelsBody tr');
    rows.forEach(function(r) { r.classList.remove('lvl-dragging'); r.style.borderTop = ''; r.style.borderBottom = ''; });
}

// ─── §7.12  Insert level at position ────────────────────────────
function insertLevel(beforeIdx, isBreak) {
    collectLevelsFromTable(); levelsCollected = true;
    var prevLv = beforeIdx > 0 ? LEVELS[beforeIdx - 1] : null;
    var newLv;
    if (isBreak) {
        newLv = { level_number: 0, small_blind: 0, big_blind: 0, ante: 0, duration_minutes: 10, is_break: 1 };
    } else {
        var sb = prevLv && !parseInt(prevLv.is_break) ? parseInt(prevLv.big_blind) : 100;
        newLv = { level_number: 0, small_blind: sb, big_blind: sb * 2, ante: 0, duration_minutes: 15, is_break: 0 };
    }
    LEVELS.splice(beforeIdx + 1, 0, newLv);
    renumberLevels();
    markLevelsDirty();
    renderLevelsTable();
}

function addLevel(isBreak) {
    collectLevelsFromTable(); levelsCollected = true;
    var lastLv = LEVELS.length > 0 ? LEVELS[LEVELS.length - 1] : null;
    var newLv;
    if (isBreak) {
        newLv = { level_number: 0, small_blind: 0, big_blind: 0, ante: 0, duration_minutes: 10, is_break: 1 };
    } else {
        var sb = lastLv && !parseInt(lastLv.is_break) ? parseInt(lastLv.big_blind) : 100;
        newLv = { level_number: 0, small_blind: sb, big_blind: sb * 2, ante: 0, duration_minutes: 15, is_break: 0 };
    }
    LEVELS.push(newLv);
    renumberLevels();
    markLevelsDirty();
    renderLevelsTable();
}

function removeLevel(idx) {
    collectLevelsFromTable(); levelsCollected = true;
    LEVELS.splice(idx, 1);
    renumberLevels();
    markLevelsDirty();
    renderLevelsTable();
}

function renumberLevels() {
    for (var i = 0; i < LEVELS.length; i++) LEVELS[i].level_number = i + 1;
}

function collectLevelsFromTable() {
    var inputs = document.querySelectorAll('.timer-levels-table input[data-idx]');
    inputs.forEach(function(inp) {
        var idx = parseInt(inp.dataset.idx);
        var field = inp.dataset.field;
        if (LEVELS[idx]) LEVELS[idx][field] = parseInt(inp.value) || 0;
    });
}

function saveLevels() {
    collectLevelsFromTable();
    // Renumber
    for (var i = 0; i < LEVELS.length; i++) LEVELS[i].level_number = i + 1;

    var fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('action', 'update_levels');
    appendTimerId(fd);
    fd.append('levels', JSON.stringify(LEVELS));
    fetch('/timer_dl.php', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(j) {
            if (j.ok) {
                if (j.preset_id) { CURRENT_PRESET_ID = j.preset_id; loadPresetList(); }
                discardLevelsDraft(); // edits are now persisted server-side
                renderAll();
                var btn = document.getElementById('btnSaveLevels');
                if (btn) {
                    var label = j.created_copy ? 'Saved as personal copy!' : 'Saved!';
                    btn.classList.remove('has-unsaved');
                    btn.textContent = label;
                    btn.style.background = '#16a34a';
                    setTimeout(function() { btn.textContent = 'Save'; btn.style.background = ''; }, 2500);
                }
            } else {
                alert(j.error || 'Error saving levels');
            }
        });
}

// ─── §7.13  Unsaved-changes tracking + local draft autosave ─────────────────
// Edits live only in the in-memory LEVELS array until "Save" hits the
// server. iPadOS aggressively discards backgrounded Safari tabs, so we mirror
// in-progress edits to localStorage and offer to restore them on return.
var levelsDirty = false;
var draftSaveTimer = null;
function levelsDraftKey() {
    return 'gnTimerLevelsDraft:' + (SESSION_ID ? ('s' + SESSION_ID) : ('k' + (REMOTE_KEY || 'x')));
}
function markLevelsDirty() {
    levelsDirty = true;
    updateSaveBtnState();
    if (draftSaveTimer) clearTimeout(draftSaveTimer);
    draftSaveTimer = setTimeout(saveLevelsDraft, 500); // debounce rapid typing
}
function saveLevelsDraft() {
    try {
        collectLevelsFromTable();
        localStorage.setItem(levelsDraftKey(), JSON.stringify({
            levels: LEVELS, ts: Date.now(), presetId: CURRENT_PRESET_ID
        }));
    } catch (e) { /* private mode / quota — non-fatal */ }
}
function discardLevelsDraft() {
    levelsDirty = false;
    if (draftSaveTimer) { clearTimeout(draftSaveTimer); draftSaveTimer = null; }
    try { localStorage.removeItem(levelsDraftKey()); } catch (e) {}
}
function updateSaveBtnState() {
    var btn = document.getElementById('btnSaveLevels');
    if (!btn) return;
    if (levelsDirty) {
        btn.classList.add('has-unsaved');
        if (btn.textContent.indexOf('Saved') === -1) btn.textContent = 'Save •';
    } else {
        btn.classList.remove('has-unsaved');
        if (btn.textContent.indexOf('Saved') === -1) btn.textContent = 'Save';
    }
}
function maybeRestoreLevelsDraft() {
    var raw;
    try { raw = localStorage.getItem(levelsDraftKey()); } catch (e) { return; }
    if (!raw) return;
    var d;
    try { d = JSON.parse(raw); } catch (e) { return; }
    if (!d || !Array.isArray(d.levels) || !d.levels.length) return;
    if (JSON.stringify(d.levels) === JSON.stringify(LEVELS)) { return; } // nothing new to restore
    var when = new Date(d.ts || Date.now());
    var t = when.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
    if (confirm('You have unsaved blind-structure edits from ' + t + ' that were never saved. Restore them?')) {
        LEVELS = d.levels;
        renumberLevels();
        markLevelsDirty();
        renderLevelsTable();
    } else {
        discardLevelsDraft();
    }
}

// ─── §7.14  Blind-structure generator ───────────────────────────────────────
// Classic chip-friendly small-blind progression (BB = 2*SB). Used as the
// shape; we scale it to the chosen starting blind and round to nice numbers.
var BASE_SB_PROGRESSION = [25,50,75,100,150,200,300,400,500,600,800,1000,1200,1500,2000,2500,3000,4000,5000,6000,8000,10000,12000,15000,20000,25000,30000,40000,50000,60000];
function roundNiceBlind(v) {
    var step;
    if (v < 100) step = 25;
    else if (v < 500) step = 50;
    else if (v < 2000) step = 100;
    else if (v < 5000) step = 250;
    else if (v < 10000) step = 500;
    else if (v < 50000) step = 1000;
    else step = 5000;
    return Math.max(step, Math.round(v / step) * step);
}
function generateBlindProgression(startSB, count) {
    var factor = startSB / BASE_SB_PROGRESSION[0];
    var arr = [];
    for (var i = 0; i < count; i++) {
        var v;
        if (i === 0) v = startSB;
        else if (i < BASE_SB_PROGRESSION.length) v = roundNiceBlind(BASE_SB_PROGRESSION[i] * factor);
        else v = roundNiceBlind(arr[i - 1] * 1.4);
        if (i > 0 && v <= arr[i - 1]) { // keep strictly increasing
            v = roundNiceBlind(arr[i - 1] * 1.3 + 1);
            if (v <= arr[i - 1]) v = arr[i - 1] + (arr[i - 1] >= 1000 ? 500 : (arr[i - 1] >= 100 ? 50 : 25));
        }
        arr.push(v);
    }
    return arr;
}
function openGenerator() { document.getElementById('genOverlay').classList.add('open'); }
function closeGenerator() { document.getElementById('genOverlay').classList.remove('open'); }
function gnGenVal(id, def) { var v = parseInt(document.getElementById(id).value); return isNaN(v) ? def : v; }
function confirmGenerate() {
    var startSB    = Math.max(1, gnGenVal('genStartSB', 25));
    var dur        = Math.max(1, gnGenVal('genDuration', 20));
    var count      = Math.max(1, Math.min(60, gnGenVal('genCount', 15)));
    var breakEvery = Math.max(0, gnGenVal('genBreakEvery', 0));
    var breakLen   = Math.max(1, gnGenVal('genBreakLen', 10));
    var anteFrom   = Math.max(0, gnGenVal('genAnteFrom', 0));

    if (LEVELS.length && !confirm('Replace the current ' + LEVELS.length + ' level(s) with a freshly generated structure?')) return;

    var blinds = generateBlindProgression(startSB, count);
    var out = [];
    for (var i = 0; i < count; i++) {
        var sb = blinds[i], bb = sb * 2;
        var ante = (anteFrom > 0 && (i + 1) >= anteFrom) ? bb : 0; // big-blind ante
        out.push({ level_number: 0, small_blind: sb, big_blind: bb, ante: ante, duration_minutes: dur, is_break: 0 });
        if (breakEvery > 0 && (i + 1) % breakEvery === 0 && i < count - 1) {
            out.push({ level_number: 0, small_blind: 0, big_blind: 0, ante: 0, duration_minutes: breakLen, is_break: 1 });
        }
    }
    LEVELS = out;
    renumberLevels();
    markLevelsDirty();
    renderLevelsTable();
    closeGenerator();
}

function setAsDefault() {
    var pid = document.getElementById('presetSelect').value;
    if (!pid) return;
    if (!confirm('Set this preset as the default for all users?')) return;
    var fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('action', 'set_default_preset');
    fd.append('preset_id', pid);
    fetch('/timer_dl.php', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(j) {
            if (j.ok) {
                alert('Default preset updated!');
                loadPresetList();
            } else {
                alert(j.error || 'Error setting default');
            }
        });
}

function loadPresetList() {
    fetch('/timer_dl.php?action=get_presets')
        .then(function(r) { return r.json(); })
        .then(function(j) {
            if (!j.ok) return;
            var sel = document.getElementById('presetSelect');
            sel.innerHTML = '';
            // Split presets into groups: default, global, league (one per league), personal
            var defaults = [], globals = [], personal = [];
            var leagueGroups = {}; // league_id -> {name, presets[]}
            j.presets.forEach(function(p) {
                p._isDefault = parseInt(p.is_default);
                p._isGlobal  = parseInt(p.is_global);
                p._leagueId  = p.league_id ? parseInt(p.league_id) : 0;
                if (p._isDefault) defaults.push(p);
                else if (p._isGlobal) globals.push(p);
                else if (p._leagueId) {
                    if (!leagueGroups[p._leagueId]) leagueGroups[p._leagueId] = { name: p.league_name || 'League', presets: [] };
                    leagueGroups[p._leagueId].presets.push(p);
                }
                else personal.push(p);
            });
            function addGroup(label, items) {
                if (!items.length) return;
                var grp = document.createElement('optgroup');
                grp.label = label;
                items.forEach(function(p) {
                    var opt = document.createElement('option');
                    opt.value = p.id;
                    opt.textContent = p.name;
                    opt.dataset.isDefault = p._isDefault;
                    opt.dataset.isGlobal  = p._isGlobal;
                    opt.dataset.leagueId  = p._leagueId || 0;
                    opt.dataset.createdBy = p.created_by;
                    grp.appendChild(opt);
                });
                sel.appendChild(grp);
            }
            addGroup('Default', defaults);
            addGroup('Global Presets', globals);
            Object.keys(leagueGroups).forEach(function(lid) {
                addGroup('League: ' + leagueGroups[lid].name, leagueGroups[lid].presets);
            });
            addGroup('My Presets', personal);
            // Select the currently active preset
            if (CURRENT_PRESET_ID) sel.value = String(CURRENT_PRESET_ID);
            updatePresetButtons();
        });
}

// Show/hide Set-as-Default and Delete buttons based on the selected preset
function updatePresetButtons() {
    var sel = document.getElementById('presetSelect');
    var opt = sel.options[sel.selectedIndex];
    var setDefaultBtn = document.getElementById('btnSetDefault');
    var deleteBtn     = document.getElementById('btnDeletePreset');
    if (!opt || !setDefaultBtn || !deleteBtn) return;
    var isDef  = opt.dataset.isDefault === '1';
    var isGlob = opt.dataset.isGlobal  === '1';
    // Set as Default: admin only, not on the already-default preset
    setDefaultBtn.style.display = (IS_ADMIN && !isDef) ? '' : 'none';
    // Delete: never on default; global only for admin; personal always visible
    if (isDef) { deleteBtn.style.display = 'none'; }
    else if (isGlob) { deleteBtn.style.display = IS_ADMIN ? '' : 'none'; }
    else { deleteBtn.style.display = ''; }
}

function loadPreset() {
    var pid = document.getElementById('presetSelect').value;
    if (!pid) return;
    var fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('action', 'load_preset');
    appendTimerId(fd);
    fd.append('preset_id', pid);
    fetch('/timer_dl.php', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(j) {
            if (j.ok) {
                // Fetch updated levels directly (bypass panel-open guard)
                var url;
                if (SESSION_ID) url = '/timer_dl.php?action=get_state&session_id=' + SESSION_ID;
                else url = '/timer_dl.php?action=get_state&key=' + encodeURIComponent(REMOTE_KEY);
                fetch(url).then(function(r) { return r.json(); }).then(function(s) {
                    if (s.ok && s.levels) {
                        LEVELS = s.levels;
                        CURRENT_PRESET_ID = pid;
                        levelsCollected = true; // skip collecting stale DOM values
                        renderLevelsTable();
                        document.getElementById('presetSelect').value = pid;
                    }
                });
            } else {
                alert(j.error || 'Error loading preset');
            }
        });
}

var _savePresetLeagues = [];

function savePresetAs() {
    // Fetch the user's manageable leagues, then open the merged-dialog modal
    var qs = new URLSearchParams();
    qs.append('action', 'get_user_leagues');
    if (SESSION_ID) qs.append('session_id', SESSION_ID);
    else if (REMOTE_KEY) qs.append('key', REMOTE_KEY);
    fetch('/timer_dl.php?' + qs.toString())
        .then(function(r) { return r.json(); })
        .then(function(j) {
            _savePresetLeagues = (j && j.ok) ? (j.leagues || []) : [];
            openSavePresetModal();
        })
        .catch(function() {
            _savePresetLeagues = [];
            openSavePresetModal();
        });
}

function openSavePresetModal() {
    var sel = document.getElementById('savePresetScope');
    sel.innerHTML = '';

    var optPersonal = document.createElement('option');
    optPersonal.value = 'personal';
    optPersonal.textContent = 'Personal (only you)';
    sel.appendChild(optPersonal);

    if (IS_ADMIN) {
        var optGlobal = document.createElement('option');
        optGlobal.value = 'global';
        optGlobal.textContent = 'Global (all users)';
        sel.appendChild(optGlobal);
    }
    _savePresetLeagues.forEach(function(l) {
        var opt = document.createElement('option');
        opt.value = 'league:' + l.id;
        opt.textContent = 'League — ' + l.name;
        sel.appendChild(opt);
    });

    document.getElementById('savePresetName').value = '';
    document.getElementById('savePresetOverlay').classList.add('open');
    setTimeout(function() { document.getElementById('savePresetName').focus(); }, 30);
}

function closeSavePresetModal() {
    document.getElementById('savePresetOverlay').classList.remove('open');
}

function confirmSavePresetAs() {
    var name = (document.getElementById('savePresetName').value || '').trim();
    if (!name) {
        alert('Please enter a preset name.');
        document.getElementById('savePresetName').focus();
        return;
    }
    var scopeVal = document.getElementById('savePresetScope').value;
    var is_global = 0;
    var league_id = 0;
    if (scopeVal === 'global') {
        is_global = 1;
    } else if (scopeVal && scopeVal.indexOf('league:') === 0) {
        league_id = parseInt(scopeVal.slice(7), 10) || 0;
    }
    // 'personal' leaves both at 0

    collectLevelsFromTable();
    for (var i = 0; i < LEVELS.length; i++) LEVELS[i].level_number = i + 1;
    var fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('action', 'save_preset');
    fd.append('name', name);
    fd.append('is_global', is_global);
    if (league_id) fd.append('league_id', league_id);
    fd.append('levels', JSON.stringify(LEVELS));
    fetch('/timer_dl.php', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(j) {
            if (j.ok) {
                var label = is_global ? ' (global)' : (league_id ? ' (league)' : '');
                closeSavePresetModal();
                alert('Preset saved' + label + '!');
                loadPresetList();
            } else {
                alert(j.error || 'Error saving preset');
            }
        });
}

function deletePreset() {
    var pid = document.getElementById('presetSelect').value;
    if (!pid) return;
    if (!confirm('Delete this preset?')) return;
    var fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('action', 'delete_preset');
    fd.append('preset_id', pid);
    fetch('/timer_dl.php', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(j) {
            if (j.ok) loadPresetList();
            else alert(j.error || 'Cannot delete');
        });
}

// ─── §7.15  Theme editor ─────────────────────────────────────────
var THEMES_CACHE = [];
var CURRENT_THEME_ID = window.TIMER_THEME_ID || null;

// Curated font options for text elements. Google Fonts are self-hosted under
// /vendor/fonts/ (see fonts.css + docker-entrypoint.sh). Stack always falls back
// to a sane system font in case the woff2 fails to load.
var FONT_OPTIONS = [
    { key: '',            label: 'Default',       stack: '' },
    { key: 'system',      label: 'System',        stack: 'system-ui, -apple-system, BlinkMacSystemFont, sans-serif' },
    { key: 'sans',        label: 'Sans',          stack: '"Helvetica Neue", Helvetica, Arial, sans-serif' },
    { key: 'serif',       label: 'Serif',         stack: 'Georgia, "Times New Roman", Times, serif' },
    { key: 'mono',        label: 'Monospace',     stack: 'ui-monospace, "SF Mono", Menlo, Consolas, monospace' },
    { key: 'inter',       label: 'Inter',         stack: '"Inter", system-ui, sans-serif' },
    { key: 'bebas',       label: 'Bebas Neue',    stack: '"Bebas Neue", "Helvetica Neue", sans-serif' },
    { key: 'orbitron',    label: 'Orbitron',      stack: '"Orbitron", "Helvetica Neue", sans-serif' },
    { key: 'press-start', label: 'Press Start 2P',stack: '"Press Start 2P", monospace' },
];
var LETTER_SPACING_OPTIONS = [
    { key: '',      label: 'Normal',  value: '' },
    { key: 'tight', label: 'Tight',   value: '-0.02em' },
    { key: 'wide',  label: 'Wide',    value: '0.05em' },
    { key: 'wider', label: 'Wider',   value: '0.15em' },
];
function fontStackFor(key) {
    for (var i = 0; i < FONT_OPTIONS.length; i++) if (FONT_OPTIONS[i].key === key) return FONT_OPTIONS[i].stack;
    return '';
}
function letterSpacingFor(key) {
    for (var i = 0; i < LETTER_SPACING_OPTIONS.length; i++) if (LETTER_SPACING_OPTIONS[i].key === key) return LETTER_SPACING_OPTIONS[i].value;
    return '';
}

var THEME_ELEMENTS = [
    { key:'event_name',    label:'Event name',    reorderable:false, hasClock:false },
    { key:'player_count',  label:'Player count',  reorderable:false, hasClock:false },
    { key:'pool_total',    label:'Prize pool',    reorderable:false, hasClock:false },
    { key:'level_label',   label:'Level label',   reorderable:true,  hasClock:false },
    { key:'blinds',        label:'Blinds',        reorderable:true,  hasClock:false },
    { key:'clock',         label:'Clock',         reorderable:true,  hasClock:true  },
    { key:'paused_label',  label:'Paused label',  reorderable:false, hasClock:false },
    { key:'next_level',    label:'Next level',    reorderable:true,  hasClock:false },
    { key:'avg_stack',     label:'Avg stack',     reorderable:false, hasClock:false },
    { key:'payouts',       label:'Payouts',       reorderable:false, hasClock:false },
    { key:'qr',            label:'QR code',       reorderable:false, hasClock:false, noColor:true },
    { key:'image',         label:'Image',         reorderable:false, hasClock:false, noColor:true, hasUpload:true },
    { key:'rebuys',        label:'Reentries',     reorderable:false, hasClock:false },
    { key:'chips_in_play', label:'Chips in play', reorderable:false, hasClock:false },
    { key:'next_break',    label:'Next break',    reorderable:false, hasClock:false },
    { key:'streaming',     label:'Stream',        reorderable:false, hasClock:false, noColor:true, hasStreamUrl:true },
];

// Element key → CSS selector (used to apply visibility + order).
var THEME_SELECTORS = {
    event_name:    '.timer-event-name',
    player_count:  '#playerWrap',
    pool_total:    '#poolWrap',
    level_label:   '.timer-level-label',
    blinds:        '.timer-blinds',
    clock:         '.timer-clock',
    paused_label:  '#pausedLabel',
    next_level:    '.timer-next',
    avg_stack:     '#avgStackWrap',
    payouts:       '#payoutsWrap',
    qr:            '#qrWrap',
    image:         '#themeImage',
    rebuys:        '#rebuysWrap',
    chips_in_play: '#chipsInPlayWrap',
    next_break:    '#nextBreakWrap',
    streaming:     '#streamingWrap',
};

// ─── Layer / stacking order ──────────────────────────────────────────────────
// Baseline back-to-front order, mirroring the stylesheet reality (image/stream
// at the back, clock most prominent on top). Used only to seed the Objects
// panel's initial order and the first reorder; once a user restacks, each
// element carries an explicit elements[key].z_index that overrides this.
var DEFAULT_LAYER_ORDER = [
    'streaming','image','qr','payouts','avg_stack','player_count','pool_total',
    'rebuys','chips_in_play','next_break','event_name','level_label','blinds',
    'next_level','paused_label','clock',
];
// Effective z for sorting: explicit z_index if set, else the baseline rank
// (0 = back … 15 = front) so the panel has a stable order before any reorder.
function effectiveZ(key) {
    var pe = (window.TIMER_THEME && window.TIMER_THEME.elements) ? window.TIMER_THEME.elements[key] : null;
    if (pe && typeof pe.z_index === 'number') return pe.z_index;
    var r = DEFAULT_LAYER_ORDER.indexOf(key);
    return r < 0 ? 0 : r;
}

// Normalize a user-pasted streaming URL into a safe embed URL.
// Returns '' for anything we don't recognize so the iframe stays blank rather
// than loading an arbitrary cross-origin page. Twitch needs a parent= param
// matching the embedding hostname — sourced from location.hostname so it works
// in both dev (localhost) and prod (gamenight.poker) without any settings.
function normalizeStreamUrl(raw) {
    if (!raw) return '';
    raw = String(raw).trim();
    var u;
    try { u = new URL(raw); } catch (e) { return ''; }
    if (u.protocol !== 'https:' && u.protocol !== 'http:') return '';
    var h = u.hostname.replace(/^www\./, '').toLowerCase();
    // YouTube — full watch URL, short youtu.be, embed/, live/, shorts/.
    // `?enablejsapi=1` lets the parent page postMessage mute/unmute commands —
    // used by the alarm-mute-stream feature.
    var YT = 'https://www.youtube-nocookie.com/embed/';
    var YT_PARAMS = '?enablejsapi=1';
    if (h === 'youtube.com' || h === 'm.youtube.com' || h === 'music.youtube.com') {
        var v = u.searchParams.get('v');
        if (v) return YT + encodeURIComponent(v) + YT_PARAMS;
        var m = u.pathname.match(/^\/(?:embed|live|shorts)\/([\w-]{6,})/);
        if (m) return YT + m[1] + YT_PARAMS;
    }
    if (h === 'youtu.be') {
        var id = u.pathname.replace(/^\//, '').split('/')[0];
        if (id) return YT + encodeURIComponent(id) + YT_PARAMS;
    }
    // YouTube TV — extract the ID from /watch/<id> and try as a regular YouTube embed.
    // Live TV / subscription-gated content won't actually play (YouTube returns "Video
    // unavailable" inside the iframe), but VOD that's also on plain YouTube will.
    if (h === 'tv.youtube.com') {
        var mtv = u.pathname.match(/^\/watch\/([\w-]{6,})/);
        if (mtv) return YT + mtv[1] + YT_PARAMS;
    }
    if (h === 'youtube-nocookie.com') {
        // Pass through but ensure enablejsapi is present so alarm-mute works.
        if (raw.indexOf('enablejsapi=') === -1) {
            return raw + (raw.indexOf('?') === -1 ? '?' : '&') + 'enablejsapi=1';
        }
        return raw;
    }
    // Twitch — first path segment is the channel name.
    if (h === 'twitch.tv') {
        var ch = u.pathname.replace(/^\//, '').split('/')[0];
        if (ch) return 'https://player.twitch.tv/?channel=' + encodeURIComponent(ch)
            + '&parent=' + encodeURIComponent(location.hostname || 'localhost');
    }
    if (h === 'player.twitch.tv') {
        // Already an embed — ensure parent matches the current host.
        u.searchParams.set('parent', location.hostname || 'localhost');
        return u.toString();
    }
    // Vimeo — public video URL like vimeo.com/123456789 or vimeo.com/channels/x/123.
    // Extract the numeric video ID (last numeric path segment) and use player.vimeo.com.
    if (h === 'vimeo.com') {
        var vparts = u.pathname.split('/').filter(Boolean);
        for (var vi = vparts.length - 1; vi >= 0; vi--) {
            if (/^\d{5,}$/.test(vparts[vi])) {
                return 'https://player.vimeo.com/video/' + vparts[vi];
            }
        }
    }
    if (h === 'player.vimeo.com') return raw;
    // Kick — channel URL like kick.com/<channel>. Embed lives at player.kick.com/<channel>.
    if (h === 'kick.com') {
        var kch = u.pathname.replace(/^\//, '').split('/')[0];
        if (kch) return 'https://player.kick.com/' + encodeURIComponent(kch);
    }
    if (h === 'player.kick.com') return raw;
    // Prime Video — best-effort pass-through. Amazon's X-Frame-Options usually
    // refuses iframe embedding for consumer URLs; the inspector warns the user.
    if (h === 'primevideo.com' || h === 'amazon.com' || h.endsWith('.amazon.com')) {
        return raw;
    }
    // Admin-allowlisted custom hosts (Settings → General → Allowed video stream hosts).
    // Forced to https because the page is https (an http embed would be mixed-content
    // blocked). Must stay in sync with the CSP frame-src built in auth.php.
    var rawHost = u.hostname.toLowerCase();
    for (var ei = 0; ei < EXTRA_STREAM_HOSTS.length; ei++) {
        var pat = String(EXTRA_STREAM_HOSTS[ei]).toLowerCase();
        var hit = (pat.indexOf('*.') === 0) ? rawHost.endsWith(pat.slice(1)) : (rawHost === pat);
        if (hit) { u.protocol = 'https:'; return u.toString(); }
    }
    // Unknown host — render nothing (safer than allowing arbitrary embeds).
    return '';
}

// ─── §7.15.1  Stream mute (postMessage to YouTube / Vimeo embeds) ──────────────
// Used by the alarm system so the streaming video doesn't drown out the alarm
// beep. YouTube needs `enablejsapi=1` in the embed URL (added by normalizeStreamUrl).
// Vimeo's Player.js postMessage works without any URL flag. Twitch / Kick / Prime
// have no public control surface from the parent page — graceful no-op.
var STREAM_MUTED_BY_ALARM = false;
var STREAM_UNMUTE_TIMER = null;
var STREAM_MUTE_WARNED = false;

function streamMute(on) {
    var frame = document.getElementById('streamingFrame');
    var src = frame && frame.getAttribute('src');
    if (!src) return;
    var win = frame.contentWindow;
    if (!win) return;
    var host;
    try { host = new URL(src).hostname.toLowerCase(); } catch (e) { return; }
    if (host.indexOf('youtube') !== -1) {
        // YouTube IFrame API: command is a JSON string posted to the embed window.
        try { win.postMessage(JSON.stringify({event:'command', func: on ? 'mute' : 'unMute', args: ''}), '*'); } catch (e) {}
    } else if (host === 'player.vimeo.com') {
        // Vimeo Player.js wire format: setMuted with a boolean value.
        try { win.postMessage(JSON.stringify({method: 'setMuted', value: !!on}), '*'); } catch (e) {}
    } else if (on && !STREAM_MUTE_WARNED) {
        // Log once so the operator knows why their Twitch/Kick/Prime stream isn't ducking.
        STREAM_MUTE_WARNED = true;
        console.info('[gn] Auto-mute during alarm not supported for ' + host + ' — alarm will overlap stream audio.');
    }
}

// Mute the stream now, schedule an unmute after `durationMs`. Honours the
// 'gn.muteStreamDuringAlarms' localStorage toggle (default on). Reentrant: a
// new alarm while still muted just refreshes the unmute timer.
function muteStreamForAlarm(durationMs) {
    try {
        if (localStorage.getItem('gn.muteStreamDuringAlarms') === 'false') return;
    } catch (e) {}
    streamMute(true);
    STREAM_MUTED_BY_ALARM = true;
    if (STREAM_UNMUTE_TIMER) clearTimeout(STREAM_UNMUTE_TIMER);
    STREAM_UNMUTE_TIMER = setTimeout(function() {
        if (STREAM_MUTED_BY_ALARM) {
            streamMute(false);
            STREAM_MUTED_BY_ALARM = false;
        }
        STREAM_UNMUTE_TIMER = null;
    }, durationMs);
}

// Map element key → list of CSS custom properties it controls.
function applyTheme(props) {
    if (!props) return;
    // The server-rendered #themeStyle inlines CSS with `display: none !important` for any
    // hidden elements at page-load time. Once JS is authoritative, clear it so our inline
    // styles (used for in-edit ghosting) aren't blocked by that !important rule.
    var themeStyle = document.getElementById('themeStyle');
    if (themeStyle && themeStyle.dataset.cleared !== '1') {
        themeStyle.textContent = '';
        themeStyle.dataset.cleared = '1';
    }
    var root = document.documentElement.style;
    var el = props.elements || {};
    var tray = props.tray || {};
    var bg = props.background || {};

    // Background
    var bgVal = '#0f172a';
    if (bg.type === 'gradient' && bg.gradient) {
        // Trailing solid color: gradients/images are background-IMAGEs, and the canvas
        // beyond the root box (iPad overscroll / safe-area gutters) fills with the
        // background-COLOR — without one those bars render white.
        bgVal = 'linear-gradient(' + (bg.gradient.angle||180) + 'deg, ' + (bg.gradient.from||'#0f172a') + ', ' + (bg.gradient.to||'#1e293b') + ') ' + (bg.gradient.to||'#1e293b');
    } else if (bg.type === 'image' && bg.image_url) {
        bgVal = "url('" + bg.image_url.replace(/'/g, '') + "') center/cover no-repeat " + (bg.color || '#0f172a');
    } else {
        bgVal = bg.color || '#0f172a';
    }
    root.setProperty('--timer-bg', bgVal);

    if (el.event_name)   { root.setProperty('--timer-event-color', el.event_name.color || '#fff');     root.setProperty('--timer-event-scale', String(el.event_name.scale || 1)); }
    if (el.player_count) root.setProperty('--timer-stat-color', el.player_count.color || '#94a3b8');
    if (el.level_label)  { root.setProperty('--timer-level-color', el.level_label.color || '#94a3b8'); root.setProperty('--timer-level-scale', String(el.level_label.scale || 1)); }
    if (el.blinds)       { root.setProperty('--timer-blinds-color', el.blinds.color || '#fff');        root.setProperty('--timer-blinds-scale', String(el.blinds.scale || 1)); }
    if (el.clock) {
        root.setProperty('--timer-clock-green', el.clock.color_green || '#22c55e');
        root.setProperty('--timer-clock-yellow', el.clock.color_yellow || '#fbbf24');
        root.setProperty('--timer-clock-red', el.clock.color_red || '#ef4444');
        root.setProperty('--timer-clock-scale', String(el.clock.scale || 1));
    }
    if (el.next_level)   { root.setProperty('--timer-next-color', el.next_level.color || '#94a3b8');   root.setProperty('--timer-next-scale', String(el.next_level.scale || 1)); }
    if (el.paused_label) { root.setProperty('--timer-paused-color', el.paused_label.color || '#fbbf24'); root.setProperty('--timer-paused-scale', String(el.paused_label.scale || 1)); }
    if (el.avg_stack)     root.setProperty('--timer-avgstack-color', el.avg_stack.color || '#94a3b8');
    if (el.payouts)       root.setProperty('--timer-payouts-color', el.payouts.color || '#94a3b8');
    if (el.rebuys)        root.setProperty('--timer-rebuys-color', el.rebuys.color || '#94a3b8');
    if (el.chips_in_play) root.setProperty('--timer-chips-color', el.chips_in_play.color || '#94a3b8');
    if (el.next_break)    root.setProperty('--timer-nextbreak-color', el.next_break.color || '#94a3b8');

    // Generic per-element scale (transform-based) for widgets that don't have their
    // own bespoke scale rule. The matching CSS selector `.timer-positioned[data-has-scale]`
    // reads --el-scale set on each node. Also applies the per-element color inline for
    // elements whose color wasn't already wired via a root-level CSS var (e.g. pool_total).
    var SCALABLE_INFO_KEYS = ['player_count','pool_total','avg_stack','payouts','rebuys','chips_in_play','next_break'];
    SCALABLE_INFO_KEYS.forEach(function(k) {
        var pe = el[k];
        if (!pe) return;
        var sel = THEME_SELECTORS[k];
        var n = sel && document.querySelector(sel);
        if (!n) return;
        n.style.setProperty('--el-scale', String(pe.scale || 1));
        n.dataset.hasScale = '1';
        // pool_total has no dedicated color CSS var — apply directly. Others already
        // pick up their color via the root-level vars set above, so we don't double-apply.
        if (k === 'pool_total' && pe.color) n.style.color = pe.color;
    });

    // Font controls — for every text element with a DOM node and a selector, apply
    // font-family/weight/style/letter-spacing/text-transform from the theme. Empty
    // strings clear any prior inline value so the element falls back to CSS defaults.
    THEME_ELEMENTS.forEach(function(meta) {
        if (meta.noColor) return;  // QR / image / streaming aren't text
        var pe = el[meta.key];
        if (!pe) return;
        var sel = THEME_SELECTORS[meta.key];
        var n = sel && document.querySelector(sel);
        if (!n) return;
        n.style.fontFamily     = fontStackFor(pe.font || '');
        n.style.fontWeight     = pe.bold ? '700' : '';
        n.style.fontStyle      = pe.italic ? 'italic' : '';
        n.style.letterSpacing  = letterSpacingFor(pe.letter_spacing || '');
        n.style.textTransform  = pe.uppercase ? 'uppercase' : '';
    });
    if (el.qr) {
        var qrNode = document.getElementById('qrWrap');
        if (qrNode) qrNode.style.setProperty('--timer-qr-scale', String(el.qr.scale || 1));
    }
    // One-time migration: legacy `background.image_url` becomes the new image element.
    if (bg && bg.image_url && bg.type === 'image' && !(el.image && el.image.url)) {
        el.image = el.image || {};
        el.image.url = bg.image_url;
        el.image.visible = true;
        el.image.scale = el.image.scale || 1;
        bg.image_url = '';
        bg.type = 'color';
        props.elements = el;
    }
    // Apply the image element (src + scale).
    var imgNode = document.getElementById('themeImage');
    if (imgNode) {
        if (el.image && el.image.url && el.image.visible !== false) {
            if (imgNode.getAttribute('src') !== el.image.url) imgNode.setAttribute('src', el.image.url);
            imgNode.style.display = '';
            imgNode.style.setProperty('--timer-image-scale', String(el.image.scale || 1));
        } else if (el.image && el.image.url && el.image.visible === false) {
            // Theme-hidden: keep src but let the standard visibility loop ghost/hide it.
            if (imgNode.getAttribute('src') !== el.image.url) imgNode.setAttribute('src', el.image.url);
            imgNode.style.display = '';
            imgNode.style.setProperty('--timer-image-scale', String(el.image.scale || 1));
        } else {
            imgNode.removeAttribute('src');
            imgNode.style.display = 'none';
        }
    }

    // Apply the streaming iframe (src + scale). Clear src on hide to stop audio.
    // In edit mode with no URL, render a placeholder so the user can find/drag the panel.
    var streamWrap  = document.getElementById('streamingWrap');
    var streamFrame = document.getElementById('streamingFrame');
    if (streamWrap && streamFrame) {
        var s = el.streaming || {};
        // Skip the iframe on touch devices: cross-origin iframes capture taps that would
        // otherwise re-acquire the wake lock, which makes "tap anywhere to keep screen on"
        // unreliable on phones/tablets. Exception: remote display views (?view=remote) are
        // dedicated screens whose whole purpose is to show the stream, and the iframe is a
        // positioned panel (not full-screen) so taps elsewhere still re-acquire the lock.
        var emb = (s.url && GAME_TYPE !== 'cash' && (!IS_TOUCH_DEVICE || IS_REMOTE)) ? normalizeStreamUrl(s.url) : '';
        var inEditNow = document.body.classList.contains('layout-edit');
        streamWrap.style.setProperty('--timer-stream-scale', String(s.scale || 1));
        if (emb) {
            if (streamFrame.getAttribute('src') !== emb) streamFrame.setAttribute('src', emb);
            streamFrame.style.display = '';
            streamWrap.classList.remove('is-empty');
            var ph = document.getElementById('streamingPlaceholder');
            if (ph) ph.remove();
            delete streamWrap.dataset.placeholderSet;
            streamWrap.style.display = (s.visible === false && !inEditNow) ? 'none' : '';
        } else {
            // No URL — clear iframe src so nothing autoplays.
            streamFrame.removeAttribute('src');
            if (inEditNow) {
                // Show a labeled placeholder inside the wrapper so the user can see and click it.
                streamFrame.style.display = 'none';
                streamWrap.classList.add('is-empty');
                if (!streamWrap.dataset.placeholderSet) {
                    var label = document.createElement('div');
                    label.id = 'streamingPlaceholder';
                    label.style.cssText = 'pointer-events:none;font-weight:600;line-height:1.3';
                    label.textContent = 'Stream — click to add a URL (Page panel)';
                    streamWrap.appendChild(label);
                    streamWrap.dataset.placeholderSet = '1';
                }
                streamWrap.style.display = '';
            } else {
                streamWrap.classList.remove('is-empty');
                streamWrap.style.display = 'none';
            }
        }
    }

    root.setProperty('--timer-tray-button-bg', tray.bg_color || '#1e293b');
    root.setProperty('--timer-tray-button-color', tray.button_color || '#e2e8f0');
    root.setProperty('--timer-accent', tray.accent_color || '#2563eb');

    // Visibility — hidden elements are truly hidden, even in edit mode. They only
    // ghost on canvas while currently selected (so the user can position them). This
    // moves the "where is my hidden element?" discovery into the Objects panel rather
    // than ghosting every hidden object on screen.
    var inEdit = document.body.classList.contains('layout-edit');
    var selSet = (typeof LAYOUT_SELECTION_SET !== 'undefined') ? LAYOUT_SELECTION_SET : null;
    for (var k in THEME_SELECTORS) {
        var node = document.querySelector(THEME_SELECTORS[k]);
        if (!node) continue;
        var visible = el[k] && el[k].visible !== false;
        var isSelected = inEdit && selSet && selSet.has && selSet.has(k);
        if (!visible) {
            node.dataset._themeHidden = '1';
            if (isSelected) {
                node.style.display = '';
                node.style.opacity = '0.45';
                node.dataset.ghostSelected = '1';
            } else {
                node.style.display = 'none';
                node.style.opacity = '';
                delete node.dataset.ghostSelected;
            }
        } else if (node.dataset._themeHidden === '1') {
            delete node.dataset._themeHidden;
            delete node.dataset.ghostSelected;
            node.style.display = '';
            node.style.opacity = '';
        }
    }

    // Order
    ['level_label','blinds','clock','next_level'].forEach(function(k){
        var node = document.querySelector(THEME_SELECTORS[k]);
        if (!node) return;
        var ord = (el[k] && el[k].order) ? parseInt(el[k].order,10) : 0;
        if (ord > 0) node.style.order = String(ord);
    });

    // Free-form positions: any element with elements[key].pos = {x,y} gets pulled out of
    // flow and pinned to (x%, y%) of the viewport, anchored at the element's center.
    for (var k2 in THEME_SELECTORS) {
        var node2 = document.querySelector(THEME_SELECTORS[k2]);
        if (!node2) continue;
        var pe = el[k2];
        var pos = (pe && pe.pos && typeof pe.pos.x === 'number' && typeof pe.pos.y === 'number') ? pe.pos : null;
        if (pos) {
            node2.classList.add('timer-positioned');
            node2.style.setProperty('--pos-x', pos.x + '%');
            node2.style.setProperty('--pos-y', pos.y + '%');
        } else {
            node2.classList.remove('timer-positioned');
            node2.style.removeProperty('--pos-x');
            node2.style.removeProperty('--pos-y');
        }
        // Per-element stacking. When z_index is set (after the user restacks via
        // the Objects panel) it overrides the stylesheet default — including the
        // hardcoded z4 on image/stream, so they become fully reorderable. Unset =
        // fall back to the stylesheet (zero change for untouched themes).
        var z = (pe && typeof pe.z_index === 'number') ? pe.z_index : null;
        if (z !== null) node2.style.zIndex = String(z);
        else            node2.style.zIndex = '';
    }

    // Variant / thickness changes from the inspector mutate the theme but don't change
    // the next tick's text — force a clock re-render so visual feedback is instant.
    if (typeof renderClock === 'function') renderClock();
}

// Build a deep-cloned theme payload from the current in-memory state. With the modal
// slimmed down to a pure library, all element/bg/tray edits flow through the in-place
// inspector (which mutates window.TIMER_THEME directly), so we can just return a copy.
function readThemeFromUI() {
    return JSON.parse(JSON.stringify(window.TIMER_THEME || {}));
}

function openThemes() {
    document.getElementById('themeOverlay').classList.add('open');
    fetchThemes();
}

function closeThemes() {
    document.getElementById('themeOverlay').classList.remove('open');
}

function fetchThemes() {
    fetch('/timer_dl.php?action=get_themes')
        .then(function(r){ return r.json(); })
        .then(function(j){
            if (!j.ok) return;
            THEMES_CACHE = j.themes || [];
            renderThemeSelect();
        });
}

function renderThemeSelect() {
    var sel = document.getElementById('themeSelect');
    if (!sel) return;
    var groups = { def:[], global:[], league:{}, mine:[] };
    THEMES_CACHE.forEach(function(t){
        if (t.is_default) groups.def.push(t);
        else if (t.is_global) groups.global.push(t);
        else if (t.league_id) {
            var k = t.league_name || ('League '+t.league_id);
            (groups.league[k] = groups.league[k] || []).push(t);
        } else groups.mine.push(t);
    });
    var html = '';
    function opt(t) { return '<option value="'+t.id+'"'+(t.id == CURRENT_THEME_ID ? ' selected' : '')+'>'+t.name+'</option>'; }
    if (groups.def.length)    html += '<optgroup label="Default">' + groups.def.map(opt).join('') + '</optgroup>';
    if (groups.global.length) html += '<optgroup label="Global">' + groups.global.map(opt).join('') + '</optgroup>';
    Object.keys(groups.league).forEach(function(k){
        html += '<optgroup label="League — '+k+'">' + groups.league[k].map(opt).join('') + '</optgroup>';
    });
    if (groups.mine.length)   html += '<optgroup label="My Themes">' + groups.mine.map(opt).join('') + '</optgroup>';
    sel.innerHTML = html;
    updateThemeButtons();
}

function updateThemeButtons() {
    var sel = document.getElementById('themeSelect');
    var tid = parseInt(sel.value || '0', 10);
    var t = THEMES_CACHE.find(function(x){ return x.id == tid; });
    var del = document.getElementById('btnDeleteTheme');
    var setDef = document.getElementById('btnSetDefaultTheme');
    if (!t) { del.disabled = true; setDef.style.display = 'none'; return; }
    var isMine = (t.created_by == <?= json_encode((int)($current['id'] ?? 0)) ?>);
    del.disabled = !!t.is_default || (!IS_ADMIN && !isMine);
    setDef.style.display = IS_ADMIN ? '' : 'none';
}

function loadTheme() {
    var tid = parseInt(document.getElementById('themeSelect').value || '0', 10);
    if (!tid) return;
    var fd = new FormData();
    fd.append('action','load_theme');
    fd.append('csrf_token', CSRF);
    appendTimerId(fd);
    fd.append('theme_id', tid);
    fetch('/timer_dl.php',{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(j){
        if (!j.ok) { alert(j.error||'Load failed'); return; }
        CURRENT_THEME_ID = tid;
        window.TIMER_THEME_ID = tid;
        window.TIMER_THEME = j.properties;
        applyTheme(j.properties);
        // If layout-edit is open, the Close-revert snapshot must track the freshly
        // loaded theme — otherwise Close would restore the pre-load ghost for ~2 s
        // until pollState() rebound to the server's authoritative theme.
        if (LAYOUT_EDIT_ON) {
            LAYOUT_EDIT_SNAPSHOT = JSON.parse(JSON.stringify(j.properties));
        }
    });
}

// ─── §7.15.2  Preset theme gallery (built-in .gnt.json presets) ───────────────────────
var PRESETS_CACHE = [];

function openPresets() {
    var bar = document.getElementById('presetAdminBar');
    if (bar) bar.style.display = IS_ADMIN ? '' : 'none';
    document.getElementById('presetOverlay').classList.add('open');
    fetchPresets();
}
function closePresets() {
    document.getElementById('presetOverlay').classList.remove('open');
}

function fetchPresets() {
    var grid = document.getElementById('presetGrid');
    fetch('/timer_dl.php?action=get_preset_themes')
        .then(function(r){ return r.json(); })
        .then(function(j){
            if (!j.ok) { grid.innerHTML = '<p style="color:#ef4444">Could not load presets.</p>'; return; }
            PRESETS_CACHE = j.presets || [];
            renderPresetGrid();
        })
        .catch(function(){ grid.innerHTML = '<p style="color:#ef4444">Could not load presets.</p>'; });
}

function renderPresetGrid() {
    var grid = document.getElementById('presetGrid');
    if (!PRESETS_CACHE.length) { grid.innerHTML = '<p style="color:#94a3b8">No presets available yet.</p>'; return; }
    grid.innerHTML = '';
    PRESETS_CACHE.forEach(function(p){ grid.appendChild(renderPresetCard(p)); });
}

// Self-contained mini-mockup built from the preset's properties. Inline styles ONLY —
// it must never touch :root or the live timer DOM (that is applyTheme's job).
function renderPresetCard(preset) {
    var props = preset.properties || {};
    var bg = props.background || {}, el = props.elements || {}, tray = props.tray || {};
    var bgVal;
    if (bg.type === 'gradient' && bg.gradient) {
        bgVal = 'linear-gradient(' + (bg.gradient.angle||180) + 'deg,' + (bg.gradient.from||'#0f172a') + ',' + (bg.gradient.to||'#1e293b') + ')';
    } else if (bg.type === 'image' && bg.image_url) {
        bgVal = "url('" + String(bg.image_url).replace(/['"\\]/g,'') + "') center/cover no-repeat";
    } else {
        bgVal = bg.color || '#0f172a';
    }
    function esc(s){ return String(s==null?'':s).replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];}); }
    var ev = el.event_name || {}, bl = el.blinds || {}, ck = el.clock || {};
    var keyJson = JSON.stringify(preset.key);

    var card = document.createElement('div');
    card.className = 'preset-card';
    card.innerHTML =
        '<div class="preset-mock" style="background:' + esc(bgVal) + '">' +
            '<div style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:.15rem;font-family:' + esc(fontStackFor(ev.font||'') || 'inherit') + '">' +
                '<div style="font-size:.55rem;color:' + esc(ev.color||'#fff') + '">Event Name</div>' +
                '<div style="font-size:1.5rem;font-weight:700;line-height:1;color:' + esc(ck.color_green||'#22c55e') + '">20:00</div>' +
                '<div style="font-size:.7rem;font-weight:600;color:' + esc(bl.color||'#fff') + '">100 / 200</div>' +
            '</div>' +
            '<div style="position:absolute;left:0;right:0;bottom:0;height:14%;background:' + esc(tray.bg_color||'#1e293b') + '"></div>' +
        '</div>' +
        '<div class="preset-foot">' +
            '<span class="preset-name" title="' + esc(preset.name) + '">' + esc(preset.name) + '</span>' +
            '<span style="display:flex;gap:.3rem;flex:0 0 auto">' +
                '<button onclick="loadPresetTheme(' + esc(keyJson) + ')">Load</button>' +
                (IS_ADMIN ? '<button onclick="deletePresetTheme(' + esc(keyJson) + ')" title="Delete preset file">&times;</button>' : '') +
            '</span>' +
        '</div>';
    return card;
}

// NOTE: named loadPresetTheme/deletePresetTheme (not loadPreset/deletePreset) —
// those names belong to the blind-structure preset panel above; a duplicate
// declaration here would shadow them and break the levels Load/Delete buttons.
function loadPresetTheme(key) {
    var fd = new FormData();
    fd.append('action','apply_preset_theme');
    fd.append('csrf_token', CSRF);
    appendTimerId(fd);
    fd.append('preset_key', key);
    fetch('/timer_dl.php',{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(j){
        if (!j.ok) { alert(j.error||'Load failed'); return; }
        CURRENT_THEME_ID = j.theme_id;
        window.TIMER_THEME_ID = j.theme_id;
        window.TIMER_THEME = j.properties;
        applyTheme(j.properties);
        // See loadTheme: keep the layout-edit revert snapshot in sync so Close
        // doesn't briefly restore the pre-load theme.
        if (LAYOUT_EDIT_ON) {
            LAYOUT_EDIT_SNAPSHOT = JSON.parse(JSON.stringify(j.properties));
        }
        fetchThemes();
        closePresets();
    });
}

function deletePresetTheme(key) {
    if (!confirm('Delete this preset file? Users who already loaded it keep their own copy.')) return;
    var fd = new FormData();
    fd.append('action','delete_preset_theme');
    fd.append('csrf_token', CSRF);
    fd.append('preset_key', key);
    fetch('/timer_dl.php',{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(j){
        if (!j.ok) { alert(j.error||'Delete failed'); return; }
        fetchPresets();
    });
}

function uploadPreset(input) {
    if (!input.files || !input.files[0]) return;
    var f = input.files[0];
    var reader = new FileReader();
    reader.onload = function(ev){
        var data;
        try { data = JSON.parse(ev.target.result); } catch(e){ alert('Not a valid JSON file.'); input.value=''; return; }
        if (!data || data.format !== 'gamenight-timer-theme' || !data.properties || typeof data.properties !== 'object'
            || !data.properties.elements || typeof data.properties.elements !== 'object') {
            alert('Not a GameNight timer-theme export.'); input.value=''; return;
        }
        var fd = new FormData();
        fd.append('action','upload_preset_theme');
        fd.append('csrf_token', CSRF);
        fd.append('file', f);
        fetch('/timer_dl.php',{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(j){
            if (!j.ok) { alert(j.error||'Upload failed'); return; }
            fetchPresets();
        });
        input.value = '';
    };
    reader.onerror = function(){ alert('Could not read file.'); input.value=''; };
    reader.readAsText(f);
}

function saveThemeAs() {
    var leagueScopes = [];
    fetch('/timer_dl.php?action=get_user_leagues').then(function(r){return r.json();}).then(function(j){
        if (j.ok && j.leagues) leagueScopes = j.leagues;
        var sel = document.getElementById('saveThemeScope');
        var html = '<option value="personal">Personal (only me)</option>';
        leagueScopes.forEach(function(l){ html += '<option value="league:'+l.id+'">League: '+l.name+'</option>'; });
        if (IS_ADMIN) html += '<option value="global">Global (all users)</option>';
        sel.innerHTML = html;
        document.getElementById('saveThemeName').value = '';
        document.getElementById('saveThemeOverlay').classList.add('open');
        setTimeout(function(){ document.getElementById('saveThemeName').focus(); }, 50);
    });
}

function closeSaveThemeModal() {
    document.getElementById('saveThemeOverlay').classList.remove('open');
}

// When an imported file is in flight we stash its parsed props here so the Save-As
// confirm flow uses them instead of the in-memory edit state. Cleared on success/cancel.
var PENDING_IMPORTED_PROPS = null;

function confirmSaveThemeAs() {
    var name = document.getElementById('saveThemeName').value.trim();
    if (!name) { alert('Name required'); return; }
    var scope = document.getElementById('saveThemeScope').value;
    var is_global = scope === 'global' ? 1 : 0;
    var league_id = scope.indexOf('league:') === 0 ? parseInt(scope.slice(7),10) : 0;
    var imported = !!PENDING_IMPORTED_PROPS;
    var props = imported ? PENDING_IMPORTED_PROPS : readThemeFromUI();
    var fd = new FormData();
    fd.append('action','save_theme');
    fd.append('csrf_token', CSRF);
    appendTimerId(fd);
    fd.append('name', name);
    fd.append('is_global', is_global);
    if (league_id) fd.append('league_id', league_id);
    fd.append('properties', JSON.stringify(props));
    fetch('/timer_dl.php',{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(j){
        if (!j.ok) { alert(j.error||'Save failed'); return; }
        // Only re-point the active session to the saved theme when it came from the
        // live editor — an import shouldn't hijack what's currently on screen.
        if (!imported) {
            CURRENT_THEME_ID = j.theme_id;
            window.TIMER_THEME_ID = j.theme_id;
        }
        PENDING_IMPORTED_PROPS = null;
        closeSaveThemeModal();
        fetchThemes();
    });
}

// Wrap closeSaveThemeModal so cancel also clears any pending import.
(function(){
    var orig = closeSaveThemeModal;
    closeSaveThemeModal = function() {
        PENDING_IMPORTED_PROPS = null;
        orig();
    };
})();

// Export: download the currently-selected theme (from the library dropdown) as a
// .gnt.json file. Wraps the properties in a small envelope with a format marker
// so importTheme can sanity-check uploads.
function exportTheme() {
    var tid = parseInt(document.getElementById('themeSelect').value || '0', 10);
    if (!tid) { alert('Pick a theme first.'); return; }
    var t = (THEMES_CACHE || []).find(function(x){ return x.id == tid; });
    if (!t) { alert('Theme not found in cache — try reopening the Library.'); return; }
    // The cached row may not include properties (depends on get_themes payload);
    // fetch the full row if missing.
    if (t.properties) {
        downloadThemeBlob(t.name, t.properties);
        return;
    }
    fetch('/timer_dl.php?action=get_theme&theme_id=' + tid).then(function(r){return r.json();}).then(function(j){
        if (!j.ok || !j.theme) { alert(j.error || 'Could not load theme'); return; }
        downloadThemeBlob(j.theme.name || t.name, j.theme.properties || {});
    });
}

function downloadThemeBlob(name, properties) {
    var envelope = {
        format: 'gamenight-timer-theme',
        version: 1,
        exported_at: new Date().toISOString(),
        name: name,
        properties: (typeof properties === 'string') ? JSON.parse(properties) : properties,
    };
    var blob = new Blob([JSON.stringify(envelope, null, 2)], { type: 'application/json' });
    var url = URL.createObjectURL(blob);
    var safe = (name || 'theme').replace(/[^A-Za-z0-9._-]+/g, '_').slice(0, 60) || 'theme';
    var a = document.createElement('a');
    a.href = url;
    a.download = safe + '.gnt.json';
    document.body.appendChild(a);
    a.click();
    setTimeout(function(){ document.body.removeChild(a); URL.revokeObjectURL(url); }, 0);
}

// Import: read a .gnt.json file, validate the envelope, stash properties in
// PENDING_IMPORTED_PROPS, then open the Save-As modal so the user can pick name + scope.
function importTheme(input) {
    if (!input.files || !input.files[0]) return;
    var f = input.files[0];
    var reader = new FileReader();
    reader.onload = function(ev) {
        var data;
        try { data = JSON.parse(ev.target.result); }
        catch (e) { alert('Not a valid JSON file.'); input.value = ''; return; }
        if (!data || data.format !== 'gamenight-timer-theme' || !data.properties || typeof data.properties !== 'object') {
            alert('Not a GameNight timer-theme export.');
            input.value = '';
            return;
        }
        var props = data.properties;
        if (!props.elements || typeof props.elements !== 'object') {
            alert('Theme file is missing the expected structure.');
            input.value = '';
            return;
        }
        PENDING_IMPORTED_PROPS = props;
        // Open Save-As; pre-fill name from envelope (suffixed so we don't collide).
        saveThemeAs();
        // Wait a tick for the modal to render, then prefill the name.
        setTimeout(function() {
            var nameEl = document.getElementById('saveThemeName');
            if (nameEl) {
                var base = (data.name || 'Imported Theme').replace(/\s+\(imported\)\s*$/i, '');
                nameEl.value = base + ' (imported)';
                nameEl.focus();
                nameEl.select();
            }
        }, 80);
        input.value = '';  // allow re-importing the same file later
    };
    reader.onerror = function() { alert('Could not read file.'); input.value = ''; };
    reader.readAsText(f);
}

function saveThemeChanges() {
    var props = readThemeFromUI();
    if (!CURRENT_THEME_ID) {
        // No theme loaded — prompt for Save As instead.
        saveThemeAs();
        return;
    }
    var fd = new FormData();
    fd.append('action','update_theme');
    fd.append('csrf_token', CSRF);
    appendTimerId(fd);
    fd.append('properties', JSON.stringify(props));
    fetch('/timer_dl.php',{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(j){
        if (!j.ok) { alert(j.error||'Save failed'); return; }
        CURRENT_THEME_ID = j.theme_id;
        window.TIMER_THEME_ID = j.theme_id;
        if (j.created_copy) {
            alert('That theme is protected. A personal copy was created — rename it via Save As if you like.');
        }
        fetchThemes();
    });
}

function deleteTheme() {
    var tid = parseInt(document.getElementById('themeSelect').value || '0', 10);
    if (!tid) return;
    if (!confirm('Delete this theme?')) return;
    var fd = new FormData();
    fd.append('action','delete_theme');
    fd.append('csrf_token', CSRF);
    fd.append('theme_id', tid);
    fetch('/timer_dl.php',{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(j){
        if (!j.ok) { alert(j.error||'Delete failed'); return; }
        if (tid === CURRENT_THEME_ID) {
            CURRENT_THEME_ID = null;
            window.TIMER_THEME_ID = null;
        }
        fetchThemes();
    });
}

function setAsDefaultTheme() {
    var tid = parseInt(document.getElementById('themeSelect').value || '0', 10);
    if (!tid) return;
    var fd = new FormData();
    fd.append('action','set_default_theme');
    fd.append('csrf_token', CSRF);
    fd.append('theme_id', tid);
    fetch('/timer_dl.php',{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(j){
        if (!j.ok) { alert(j.error||'Failed'); return; }
        fetchThemes();
    });
}

// ─── §7.16  Free-form layout edit mode (drag elements on the live timer) ─────
var LAYOUT_EDIT_ON = false;
var LAYOUT_EDIT_SNAPSHOT = null;  // theme JSON snapshot for Cancel
var LAYOUT_DRAG_HANDLERS = [];    // [{ node, handler }] for cleanup

// Fallback positions (% of viewport, element center) — used when capture fails (e.g.,
// element has 0x0 rect because content hasn't rendered yet or it's a JS-conditional widget).
// Without these, the un-positioned element stays in flex flow and gets overlapped by
// other elements that DID get positioned out of flow.
var LAYOUT_DEFAULT_POS = {
    event_name:    { x: 50, y: 4 },
    player_count:  { x: 35, y: 8 },
    pool_total:    { x: 65, y: 8 },
    level_label:   { x: 50, y: 22 },
    blinds:        { x: 50, y: 38 },
    clock:         { x: 50, y: 60 },
    paused_label:  { x: 50, y: 78 },
    next_level:    { x: 50, y: 88 },
    avg_stack:     { x: 8,  y: 14 },
    payouts:       { x: 92, y: 14 },
    qr:            { x: 94, y: 92 },
    image:         { x: 50, y: 50 },
    rebuys:        { x: 30, y: 12 },
    chips_in_play: { x: 50, y: 12 },
    next_break:    { x: 70, y: 12 },
    streaming:     { x: 75, y: 30 },
};

// ─── §7.16.1  Snap toggle (touch-friendly alternative to holding Shift) ──────────────
// Default ON. Persisted across edit sessions in localStorage so a user who
// turned snap off for fine positioning doesn't have to do it again next time.
// Shift still works as a momentary override (composed via OR with this flag).
var SNAP_ENABLED = (function(){
    try { var v = localStorage.getItem('timer_snap_enabled'); return v === null ? true : v === '1'; }
    catch(e) { return true; }
})();
function setSnapButtonUI() {
    var btn = document.getElementById('snapToggleBtn');
    if (!btn) return;
    btn.classList.toggle('snap-on',  SNAP_ENABLED);
    btn.classList.toggle('snap-off', !SNAP_ENABLED);
    btn.setAttribute('aria-pressed', SNAP_ENABLED ? 'true' : 'false');
}
function toggleSnap() {
    SNAP_ENABLED = !SNAP_ENABLED;
    try { localStorage.setItem('timer_snap_enabled', SNAP_ENABLED ? '1' : '0'); } catch(e) {}
    setSnapButtonUI();
}
// Rewrite the .snap-hint text based on input device. Touch users can't hold
// Shift, so we drop that mention and advertise the on-screen toggle instead.
function updateSnapHint() {
    var hint = document.querySelector('.snap-hint');
    if (!hint) return;
    if (IS_TOUCH_DEVICE) {
        hint.innerHTML = 'Tap <b>Snap</b> to toggle snapping &nbsp;·&nbsp; long-press an element + drag a second to multi-select';
    } else {
        hint.innerHTML = '<b>Snap</b> button toggles snapping &nbsp;·&nbsp; hold <b>Shift</b> for momentary off &nbsp;·&nbsp; <b>Ctrl</b>/<b>Cmd</b>+click to multi-select';
    }
}

function enterLayoutEdit() {
    if (LAYOUT_EDIT_ON) return;
    LAYOUT_EDIT_ON = true;
    LAYOUT_EDIT_SNAPSHOT = JSON.parse(JSON.stringify(window.TIMER_THEME || {}));
    closeThemes();
    document.body.classList.add('layout-edit');
    setSnapButtonUI();
    updateSnapHint();

    // Ensure on-screen content is up to date before measuring.
    renderAll();
    window.TIMER_THEME.elements = window.TIMER_THEME.elements || {};

    Object.keys(THEME_SELECTORS).forEach(function(key) {
        var node = document.querySelector(THEME_SELECTORS[key]);
        if (!node) return;
        var pe = window.TIMER_THEME.elements[key] = window.TIMER_THEME.elements[key] || {};
        // Validate any existing pos — drop stale/out-of-bounds values from a previous session.
        if (pe.pos && (
            typeof pe.pos.x !== 'number' || typeof pe.pos.y !== 'number' ||
            pe.pos.x < 0 || pe.pos.x > 100 || pe.pos.y < 0 || pe.pos.y > 100
        )) {
            delete pe.pos;
        }
        if (pe.pos) return;
        // Hidden elements without a pos: defer seeding until the user selects them from
        // the Objects panel. Otherwise they'd silently acquire a position they can't see.
        if (pe.visible === false) return;
        var rect = node.getBoundingClientRect();
        if (rect.width > 1 && rect.height > 1) {
            pe.pos = {
                x: ((rect.left + rect.width / 2) / window.innerWidth)  * 100,
                y: ((rect.top  + rect.height / 2) / window.innerHeight) * 100,
            };
        } else if (LAYOUT_DEFAULT_POS[key]) {
            // Fall back to a sensible default so the element doesn't get stuck in flex
            // flow under the other (now-positioned) siblings.
            pe.pos = { x: LAYOUT_DEFAULT_POS[key].x, y: LAYOUT_DEFAULT_POS[key].y };
        }
    });

    applyTheme(window.TIMER_THEME);
    attachAllDragHandlers();
    openObjectsPanel();
}

function exitLayoutEdit(keep) {
    if (!LAYOUT_EDIT_ON) return;
    LAYOUT_EDIT_ON = false;
    document.body.classList.remove('layout-edit');
    detachAllDragHandlers();
    deselectElement();
    removeAllEyeIcons();
    closeObjectsPanel();
    if (!keep && LAYOUT_EDIT_SNAPSHOT) {
        window.TIMER_THEME = LAYOUT_EDIT_SNAPSHOT;
    }
    LAYOUT_EDIT_SNAPSHOT = null;
    applyTheme(window.TIMER_THEME);
    // If user clicked Save: confirm before overwriting the current theme. Brand-new
    // themes (no CURRENT_THEME_ID) jump straight to Save As since there's nothing to overwrite.
    if (keep) {
        if (CURRENT_THEME_ID) {
            openConfirmSave();
        } else {
            saveThemeAs();
        }
    }
}

// ─── §7.15.3  Confirm-Save dialog (overwrite vs Save As New) ───────
function openConfirmSave() {
    var t = (THEMES_CACHE || []).find(function(x){ return x.id == CURRENT_THEME_ID; });
    var nameEl = document.getElementById('confirmSaveName');
    var warnEl = document.getElementById('confirmSaveWarn');
    if (nameEl) nameEl.textContent = t ? t.name : 'My Theme';
    // Warn if the target is protected and the user can't edit it directly.
    var protectedTheme = false;
    if (t) {
        var isMine = (t.created_by == <?= json_encode((int)($current['id'] ?? 0)) ?>);
        if ((t.is_default || t.is_global) && !IS_ADMIN) protectedTheme = true;
        else if (t.league_id && !IS_ADMIN && !isMine) protectedTheme = true;
    }
    if (warnEl) warnEl.style.display = protectedTheme ? '' : 'none';
    document.getElementById('confirmSaveOverlay').classList.add('open');
}

function closeConfirmSave() {
    document.getElementById('confirmSaveOverlay').classList.remove('open');
}

function confirmSaveOverwrite() {
    closeConfirmSave();
    saveThemeChanges();
}

function confirmSaveAsNew() {
    closeConfirmSave();
    saveThemeAs();
}

function resetPositions() {
    if (!window.TIMER_THEME || !window.TIMER_THEME.elements) return;
    Object.keys(window.TIMER_THEME.elements).forEach(function(k){
        delete window.TIMER_THEME.elements[k].pos;
    });
    applyTheme(window.TIMER_THEME);
    // After resetting, re-promote elements to dragging using their natural positions.
    detachAllDragHandlers();
    // Recompute pos values from current rendered positions, then re-attach.
    Object.keys(THEME_SELECTORS).forEach(function(key) {
        var node = document.querySelector(THEME_SELECTORS[key]);
        if (!node) return;
        var pe = window.TIMER_THEME.elements[key] = window.TIMER_THEME.elements[key] || {};
        if (pe.visible === false) return;
        var rect = node.getBoundingClientRect();
        if (rect.width === 0 && rect.height === 0) return;
        pe.pos = {
            x: ((rect.left + rect.width/2) / window.innerWidth) * 100,
            y: ((rect.top + rect.height/2) / window.innerHeight) * 100,
        };
    });
    applyTheme(window.TIMER_THEME);
    attachAllDragHandlers();
}

function attachAllDragHandlers() {
    Object.keys(THEME_SELECTORS).forEach(function(key) {
        var node = document.querySelector(THEME_SELECTORS[key]);
        if (!node) return;
        if (!node.classList.contains('timer-positioned')) return;
        // Eye icon for quick visibility toggle.
        attachEyeIcon(node, key);
        // Combined drag-OR-select handler. Movement above threshold = drag (reposition).
        // Movement at/below threshold + release = click (open inspector for this element).
        var handler = makeDragStart(node, key);
        var wheel   = makeWheelScale(key);
        node.addEventListener('mousedown', handler);
        node.addEventListener('touchstart', handler, { passive: false });
        node.addEventListener('wheel', wheel, { passive: false });
        LAYOUT_DRAG_HANDLERS.push({ node: node, handler: handler, wheel: wheel });
    });
}

function detachAllDragHandlers() {
    LAYOUT_DRAG_HANDLERS.forEach(function(h){
        h.node.removeEventListener('mousedown', h.handler);
        h.node.removeEventListener('touchstart', h.handler);
        if (h.wheel) h.node.removeEventListener('wheel', h.wheel);
    });
    LAYOUT_DRAG_HANDLERS = [];
}

// Mouse wheel over an element in edit mode adjusts its scale.
// Hold Shift for a finer step.
function makeWheelScale(key) {
    return function(ev) {
        ev.preventDefault();
        var step  = ev.shiftKey ? 0.02 : 0.05;
        var delta = ev.deltaY < 0 ? step : -step;
        window.TIMER_THEME.elements = window.TIMER_THEME.elements || {};
        var pe = window.TIMER_THEME.elements[key] = window.TIMER_THEME.elements[key] || {};
        var v = Math.max(0.3, Math.min(6.0, (pe.scale || 1) + delta));
        pe.scale = Math.round(v * 100) / 100;
        applyTheme(window.TIMER_THEME);
        // If the inspector is showing this element, refresh its size label.
        if (LAYOUT_SELECTED_KEY === key) {
            var lbl = document.getElementById('ins_scale_' + key);
            if (lbl) lbl.textContent = Math.round(pe.scale * 100) + '%';
        }
    };
}

function attachEyeIcon(node, key) {
    // Don't double-add.
    var existing = node.querySelector(':scope > .layout-eye');
    if (existing) return;
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'layout-eye';
    btn.dataset.key = key;
    var pe = (window.TIMER_THEME.elements || {})[key] || {};
    btn.innerHTML = pe.visible === false ? '&#128064;' : '&#128065;';  // closed/open eye
    if (pe.visible === false) btn.classList.add('is-hidden');
    btn.title = 'Toggle visibility';
    btn.addEventListener('mousedown', function(e){ e.stopPropagation(); });
    btn.addEventListener('touchstart', function(e){ e.stopPropagation(); }, { passive: true });
    btn.addEventListener('click', function(e){
        e.stopPropagation();
        e.preventDefault();
        toggleElementVisibility(key);
    });
    node.appendChild(btn);
}

function removeAllEyeIcons() {
    document.querySelectorAll('.layout-eye').forEach(function(b){ b.remove(); });
}

function toggleElementVisibility(key) {
    window.TIMER_THEME.elements = window.TIMER_THEME.elements || {};
    var pe = window.TIMER_THEME.elements[key] = window.TIMER_THEME.elements[key] || {};
    pe.visible = pe.visible === false;  // flip
    applyTheme(window.TIMER_THEME);
    // Refresh the eye icon glyph (open/closed) for this element.
    var node = document.querySelector(THEME_SELECTORS[key]);
    var eye = node && node.querySelector(':scope > .layout-eye');
    if (eye) {
        eye.innerHTML = pe.visible === false ? '&#128064;' : '&#128065;';
        eye.classList.toggle('is-hidden', pe.visible === false);
    }
    refreshHiddenInInspector();
    if (typeof renderObjectsPanel === 'function') renderObjectsPanel();
}

function makeDragStart(node, key) {
    return function start(ev) {
        if (ev.target.closest('.layout-eye')) return;  // eye icon owns its own clicks
        ev.preventDefault();
        ev.stopPropagation();

        // Ctrl/Cmd state captured at mousedown — defines selection semantics on
        // mouseup (toggle vs replace), and whether the element is part of a group drag.
        var modifierKey = !!(ev.ctrlKey || ev.metaKey);

        var pt = ev.touches ? ev.touches[0] : ev;
        var startX = pt.clientX, startY = pt.clientY;
        var rect = node.getBoundingClientRect();
        var offX = pt.clientX - (rect.left + rect.width / 2);
        var offY = pt.clientY - (rect.top  + rect.height / 2);
        // Dragging element's half-dimensions in % of viewport (stable during drag).
        var halfWdr = (rect.width  / window.innerWidth)  * 50;
        var halfHdr = (rect.height / window.innerHeight) * 50;
        var moved = false;
        var THRESH = 5;

        var SNAP_PCT       = 2;    // snap-to-center distance (% of viewport)
        var ALIGN_SNAP_PCT = 1.5;  // tighter — snap-to-other-element distance
        var guideV  = document.getElementById('centerGuideV');
        var guideH  = document.getElementById('centerGuideH');
        var alignV  = document.getElementById('alignGuideV');
        var alignH  = document.getElementById('alignGuideH');

        // Group-drag set: if this element is part of an existing multi-selection (and
        // it's not a Ctrl-click, which would be a toggle intent), move everything in the
        // selection together with the same delta. Otherwise drag this one alone.
        // Don't mutate the selection itself here — that happens on mouseup if !moved.
        var groupKeys;
        if (!modifierKey && LAYOUT_SELECTION_SET.has(key) && LAYOUT_SELECTION_SET.size > 1) {
            groupKeys = Array.from(LAYOUT_SELECTION_SET);
        } else {
            groupKeys = [key];
        }
        var groupStart = {};
        groupKeys.forEach(function(gk) {
            var ge = window.TIMER_THEME.elements && window.TIMER_THEME.elements[gk];
            if (ge && ge.pos && typeof ge.pos.x === 'number') {
                groupStart[gk] = { x: ge.pos.x, y: ge.pos.y };
            }
        });

        // Snapshot every other positioned element's center + half-dimensions so
        // snap math doesn't repeatedly hit the layout engine during mousemove.
        // Exclude the group itself (we shouldn't snap a group to one of its own members).
        var others = (window.TIMER_THEME && window.TIMER_THEME.elements) || {};
        var groupSet = {}; groupKeys.forEach(function(gk){ groupSet[gk] = 1; });
        var othersGeom = [];
        for (var ok in others) {
            if (groupSet[ok]) continue;
            var op = others[ok] && others[ok].pos;
            if (!op || typeof op.x !== 'number' || typeof op.y !== 'number') continue;
            var sel = THEME_SELECTORS[ok];
            if (!sel) continue;
            var otherNode = document.querySelector(sel);
            if (!otherNode) continue;
            var orect = otherNode.getBoundingClientRect();
            if (orect.width < 1 || orect.height < 1) continue;
            othersGeom.push({
                x: op.x, y: op.y,
                halfW: (orect.width  / window.innerWidth)  * 50,
                halfH: (orect.height / window.innerHeight) * 50,
            });
        }

        // For each other element produce 9 candidate snap targets per axis:
        // center↔center, edge↔edge (4 combos), and edge↔center (4 combos).
        // First hit within ALIGN_SNAP_PCT wins; guideAt is the shared coordinate
        // where the alignment line gets drawn.
        function snapAxis(cur, isX) {
            for (var i = 0; i < othersGeom.length; i++) {
                var o = othersGeom[i];
                var oc = isX ? o.x : o.y;
                var oh = isX ? o.halfW : o.halfH;
                var dh = isX ? halfWdr : halfHdr;
                var cands = [
                    [oc,             oc],            // center ↔ center
                    [oc - oh + dh,   oc - oh],       // dragging-left  ↔ other-left
                    [oc + oh + dh,   oc + oh],       // dragging-left  ↔ other-right
                    [oc - oh - dh,   oc - oh],       // dragging-right ↔ other-left
                    [oc + oh - dh,   oc + oh],       // dragging-right ↔ other-right
                    [oc + dh,        oc],            // dragging-left  ↔ other-center
                    [oc - dh,        oc],            // dragging-right ↔ other-center
                    [oc - oh,        oc - oh],       // dragging-center↔ other-left
                    [oc + oh,        oc + oh],       // dragging-center↔ other-right
                ];
                for (var c = 0; c < cands.length; c++) {
                    if (Math.abs(cur - cands[c][0]) < ALIGN_SNAP_PCT) {
                        return { snap: cands[c][0], guideAt: cands[c][1] };
                    }
                }
            }
            return null;
        }

        function onMove(ev2) {
            var p = ev2.touches ? ev2.touches[0] : ev2;
            if (!moved && (Math.abs(p.clientX - startX) > THRESH || Math.abs(p.clientY - startY) > THRESH)) {
                moved = true;
            }
            if (!moved) return;
            ev2.preventDefault();
            var cx = ((p.clientX - offX) / window.innerWidth)  * 100;
            var cy = ((p.clientY - offY) / window.innerHeight) * 100;

            // Shift bypasses all snapping for fine adjustments.
            var snapDisabled = !SNAP_ENABLED || !!ev2.shiftKey;

            var snapX = false, snapY = false;
            var alignedX = null, alignedY = null;

            if (!snapDisabled) {
                // Snap to viewport center lines (yellow guide). Wins over smart guides.
                snapX = Math.abs(cx - 50) < SNAP_PCT;
                snapY = Math.abs(cy - 50) < SNAP_PCT;
                if (snapX) cx = 50;
                if (snapY) cy = 50;

                // Smart edge/center snap to other elements (cyan guide).
                if (!snapX) {
                    var sx = snapAxis(cx, true);
                    if (sx) { cx = sx.snap; alignedX = sx.guideAt; }
                }
                if (!snapY) {
                    var sy = snapAxis(cy, false);
                    if (sy) { cy = sy.snap; alignedY = sy.guideAt; }
                }
            }

            if (guideV) guideV.classList.toggle('is-snapping', snapX);
            if (guideH) guideH.classList.toggle('is-snapping', snapY);
            if (alignV) {
                if (alignedX !== null) { alignV.style.left = alignedX + '%'; alignV.classList.add('is-snapping'); }
                else alignV.classList.remove('is-snapping');
            }
            if (alignH) {
                if (alignedY !== null) { alignH.style.top = alignedY + '%'; alignH.classList.add('is-snapping'); }
                else alignH.classList.remove('is-snapping');
            }

            cx = Math.max(2, Math.min(98, cx));
            cy = Math.max(2, Math.min(98, cy));

            // Apply the post-snap delta (from primary's starting position) to every
            // group member. For a solo drag this loop just runs once for `key`.
            var pStart = groupStart[key];
            var deltaX = pStart ? (cx - pStart.x) : 0;
            var deltaY = pStart ? (cy - pStart.y) : 0;
            window.TIMER_THEME.elements = window.TIMER_THEME.elements || {};
            for (var gi = 0; gi < groupKeys.length; gi++) {
                var gk = groupKeys[gi];
                var gs = groupStart[gk];
                if (!gs) continue;
                var gcx = Math.max(2, Math.min(98, gs.x + deltaX));
                var gcy = Math.max(2, Math.min(98, gs.y + deltaY));
                var gn = document.querySelector(THEME_SELECTORS[gk]);
                if (gn) {
                    gn.style.setProperty('--pos-x', gcx + '%');
                    gn.style.setProperty('--pos-y', gcy + '%');
                }
                window.TIMER_THEME.elements[gk] = window.TIMER_THEME.elements[gk] || {};
                window.TIMER_THEME.elements[gk].pos = { x: gcx, y: gcy };
            }
        }
        function onUp() {
            document.removeEventListener('mousemove', onMove);
            document.removeEventListener('mouseup', onUp);
            document.removeEventListener('touchmove', onMove);
            document.removeEventListener('touchend', onUp);
            document.removeEventListener('touchcancel', onUp);
            if (guideV) guideV.classList.remove('is-snapping');
            if (guideH) guideH.classList.remove('is-snapping');
            if (alignV) alignV.classList.remove('is-snapping');
            if (alignH) alignH.classList.remove('is-snapping');
            if (!moved) {
                // Treat as click. Ctrl/Cmd toggles multi-selection; plain click replaces.
                if (modifierKey) toggleSelectElement(key);
                else selectElement(key);
            }
        }
        document.addEventListener('mousemove', onMove);
        document.addEventListener('mouseup', onUp);
        document.addEventListener('touchmove', onMove, { passive: false });
        document.addEventListener('touchend', onUp);
        document.addEventListener('touchcancel', onUp);
    };
}

// ─── §7.17  Inspector (per-element properties panel) ─────────────
var LAYOUT_SELECTED_KEY = null;          // primary selection — drives the inspector
var LAYOUT_SELECTION_SET = new Set();    // all selected keys (always contains primary)

function updateSelectionVisuals() {
    document.querySelectorAll('.timer-positioned.is-selected').forEach(function(n){ n.classList.remove('is-selected'); });
    LAYOUT_SELECTION_SET.forEach(function(k) {
        var sel = THEME_SELECTORS[k];
        var n = sel && document.querySelector(sel);
        if (n) n.classList.add('is-selected');
    });
}

function selectElement(key) {
    // Plain click — replace selection with this single key.
    LAYOUT_SELECTION_SET.clear();
    if (key && THEME_SELECTORS[key]) LAYOUT_SELECTION_SET.add(key);
    LAYOUT_SELECTED_KEY = key;
    updateSelectionVisuals();
    renderInspector(key);
    var panel = document.getElementById('layoutInspector');
    if (panel) panel.classList.add('is-open');
    // Selection change can move a hidden object into / out of the ghost state.
    if (window.TIMER_THEME) applyTheme(window.TIMER_THEME);
    if (typeof renderObjectsPanel === 'function') renderObjectsPanel();
}

function toggleSelectElement(key) {
    // Ctrl/Cmd-click — add to or remove from multi-selection.
    if (!THEME_SELECTORS[key]) return;
    if (LAYOUT_SELECTION_SET.has(key)) {
        LAYOUT_SELECTION_SET.delete(key);
        if (LAYOUT_SELECTED_KEY === key) {
            // Promote any remaining selection to primary.
            LAYOUT_SELECTED_KEY = LAYOUT_SELECTION_SET.size > 0 ? LAYOUT_SELECTION_SET.values().next().value : null;
        }
    } else {
        LAYOUT_SELECTION_SET.add(key);
        if (!LAYOUT_SELECTED_KEY) LAYOUT_SELECTED_KEY = key;
    }
    updateSelectionVisuals();
    if (LAYOUT_SELECTION_SET.size === 0) {
        deselectElement();
    } else {
        renderInspector(LAYOUT_SELECTED_KEY);
        var panel = document.getElementById('layoutInspector');
        if (panel) panel.classList.add('is-open');
    }
    if (window.TIMER_THEME) applyTheme(window.TIMER_THEME);
    if (typeof renderObjectsPanel === 'function') renderObjectsPanel();
}

function deselectElement() {
    LAYOUT_SELECTED_KEY = null;
    LAYOUT_SELECTION_SET.clear();
    document.querySelectorAll('.timer-positioned.is-selected').forEach(function(n){ n.classList.remove('is-selected'); });
    var panel = document.getElementById('layoutInspector');
    if (panel) panel.classList.remove('is-open');
    if (window.TIMER_THEME) applyTheme(window.TIMER_THEME);
    if (typeof renderObjectsPanel === 'function') renderObjectsPanel();
}

function closeInspector() { deselectElement(); }

// ─── §7.17.1  Objects panel — list of all theme elements (incl. hidden), used ───
// to select / un-hide them since hidden objects no longer ghost on canvas, and
// to restack them (layer / z-index) via grip-drag or ▲/▼ buttons.
function openObjectsPanel() {
    renderObjectsPanel();
    var p = document.getElementById('layoutObjectsPanel');
    if (p) p.classList.add('is-open');
}
function closeObjectsPanel() {
    var p = document.getElementById('layoutObjectsPanel');
    if (p) p.classList.remove('is-open');
}

// Metas sorted front-to-back (top of the panel list = front of the canvas).
function objectsSortedMetas() {
    return THEME_ELEMENTS.slice().sort(function(a, b) {
        return effectiveZ(b.key) - effectiveZ(a.key);
    });
}

function renderObjectsPanel() {
    var body = document.getElementById('objectsBody');
    if (!body) return;
    var metas = objectsSortedMetas();
    var html = '';
    metas.forEach(function(meta, i) {
        var pe = (window.TIMER_THEME && window.TIMER_THEME.elements) ? (window.TIMER_THEME.elements[meta.key] || {}) : {};
        var hidden = (pe.visible === false);
        var selected = LAYOUT_SELECTION_SET && LAYOUT_SELECTION_SET.has && LAYOUT_SELECTION_SET.has(meta.key);
        var rowCls = 'layout-object-row' + (selected ? ' is-selected' : '') + (hidden ? ' is-hidden' : '');
        var eyeCls = 'obj-eye' + (hidden ? ' is-hidden' : '');
        var eyeGlyph = hidden ? '&#128064;' : '&#128065;';  // closed / open eye
        var safeKey = meta.key.replace(/'/g, "\\'");
        var upDis = (i === 0) ? ' disabled' : '';
        var dnDis = (i === metas.length - 1) ? ' disabled' : '';
        html += '<div class="' + rowCls + '" data-key="' + meta.key + '" onclick="onObjectsRowClick(event,\'' + safeKey + '\')">'
              +   '<span class="obj-grip" title="Drag to restack" onclick="event.stopPropagation()">&#9776;</span>'
              +   '<button type="button" class="' + eyeCls + '" '
              +           'onclick="event.stopPropagation();onObjectsRowEye(\'' + safeKey + '\')"'
              +           ' title="Toggle visibility">' + eyeGlyph + '</button>'
              +   '<span class="obj-label">' + meta.label + '</span>'
              +   '<span class="obj-move">'
              +     '<button type="button" title="Bring forward" onclick="event.stopPropagation();moveObjectLayer(\'' + safeKey + '\',-1)"' + upDis + '>&#9650;</button>'
              +     '<button type="button" title="Send backward" onclick="event.stopPropagation();moveObjectLayer(\'' + safeKey + '\',1)"' + dnDis + '>&#9660;</button>'
              +   '</span>'
              + '</div>';
    });
    body.innerHTML = html;
    attachObjectsDrag();
}

// Assign explicit z_index to every element from a top→bottom (front→back) key
// list: top row gets the highest value. Clamped into 1..N which stays well below
// the control tray (z25) / edit pill (z40) / modals, so "bring to front" never
// covers the editor chrome.
function assignZFromPanelOrder(orderedKeys) {
    if (!window.TIMER_THEME) return;
    window.TIMER_THEME.elements = window.TIMER_THEME.elements || {};
    var n = orderedKeys.length;
    orderedKeys.forEach(function(key, i) {
        var pe = window.TIMER_THEME.elements[key] = window.TIMER_THEME.elements[key] || {};
        pe.z_index = n - i;
    });
}

// ▲/▼ buttons: swap a row with its neighbor (dir -1 = toward front, +1 = back).
function moveObjectLayer(key, dir) {
    var order = objectsSortedMetas().map(function(m){ return m.key; });
    var idx = order.indexOf(key);
    if (idx < 0) return;
    var swap = idx + dir;
    if (swap < 0 || swap >= order.length) return;
    var tmp = order[idx]; order[idx] = order[swap]; order[swap] = tmp;
    assignZFromPanelOrder(order);
    applyTheme(window.TIMER_THEME);
    renderObjectsPanel();
}

// Pointer-based drag-reorder (works on mouse / touch / pen — NOT HTML5 DnD,
// which iPad Safari never fires; same lesson as the v0.19306 blind-level rows).
var OBJ_DRAG = null;
function attachObjectsDrag() {
    var body = document.getElementById('objectsBody');
    if (!body) return;
    var grips = body.querySelectorAll('.obj-grip');
    for (var i = 0; i < grips.length; i++) {
        grips[i].addEventListener('pointerdown', onGripPointerDown);
    }
}
function onGripPointerDown(ev) {
    ev.preventDefault();
    ev.stopPropagation();
    var row  = ev.target.closest('.layout-object-row');
    var body = document.getElementById('objectsBody');
    if (!row || !body) return;
    row.classList.add('is-dragging');
    try { ev.target.setPointerCapture(ev.pointerId); } catch (e) {}
    OBJ_DRAG = { row: row, body: body, grip: ev.target };
    ev.target.addEventListener('pointermove', onGripPointerMove);
    ev.target.addEventListener('pointerup', onGripPointerUp);
    ev.target.addEventListener('pointercancel', onGripPointerUp);
}
function onGripPointerMove(ev) {
    if (!OBJ_DRAG) return;
    ev.preventDefault();
    var body = OBJ_DRAG.body, row = OBJ_DRAG.row, y = ev.clientY;
    var sibs = body.querySelectorAll('.layout-object-row:not(.is-dragging)');
    var after = null;
    for (var i = 0; i < sibs.length; i++) {
        var rect = sibs[i].getBoundingClientRect();
        if (y < rect.top + rect.height / 2) { after = sibs[i]; break; }
    }
    if (after) body.insertBefore(row, after);
    else       body.appendChild(row);
}
function onGripPointerUp(ev) {
    if (!OBJ_DRAG) return;
    var grip = OBJ_DRAG.grip, row = OBJ_DRAG.row, body = OBJ_DRAG.body;
    row.classList.remove('is-dragging');
    grip.removeEventListener('pointermove', onGripPointerMove);
    grip.removeEventListener('pointerup', onGripPointerUp);
    grip.removeEventListener('pointercancel', onGripPointerUp);
    try { grip.releasePointerCapture(ev.pointerId); } catch (e) {}
    OBJ_DRAG = null;
    var keys = [];
    var rows = body.querySelectorAll('.layout-object-row');
    for (var i = 0; i < rows.length; i++) keys.push(rows[i].getAttribute('data-key'));
    assignZFromPanelOrder(keys);
    applyTheme(window.TIMER_THEME);
    renderObjectsPanel();
}

function onObjectsRowClick(ev, key) {
    if (!window.TIMER_THEME) return;
    window.TIMER_THEME.elements = window.TIMER_THEME.elements || {};
    var pe = window.TIMER_THEME.elements[key] = window.TIMER_THEME.elements[key] || {};
    // Seed a default position for hidden + positionless objects so the ghost lands somewhere visible
    // and the drag handler has coordinates to mutate.
    if (pe.visible === false && !pe.pos && LAYOUT_DEFAULT_POS[key]) {
        pe.pos = { x: LAYOUT_DEFAULT_POS[key].x, y: LAYOUT_DEFAULT_POS[key].y };
    }
    if (ev && (ev.ctrlKey || ev.metaKey)) toggleSelectElement(key);
    else                                   selectElement(key);
    // A newly-positioned hidden object needs drag handlers re-armed.
    detachAllDragHandlers();
    attachAllDragHandlers();
    applyTheme(window.TIMER_THEME);
    renderObjectsPanel();
}

function onObjectsRowEye(key) {
    toggleElementVisibility(key);
    applyTheme(window.TIMER_THEME);
    renderObjectsPanel();
}

function renderInspector(key) {
    var title = document.getElementById('inspectorTitle');
    var body  = document.getElementById('inspectorBody');
    if (!body) return;
    if (key === 'page') {
        if (title) title.textContent = 'Page';
        body.innerHTML = renderPageInspector();
        return;
    }
    // Multi-selection view — replaces per-element controls with a brief summary.
    // Drag any selected element to move them all together.
    if (LAYOUT_SELECTION_SET.size > 1) {
        if (title) title.textContent = LAYOUT_SELECTION_SET.size + ' elements';
        var labels = [];
        LAYOUT_SELECTION_SET.forEach(function(sk) {
            var m = THEME_ELEMENTS.find(function(e){ return e.key === sk; });
            if (m) labels.push(m.label);
        });
        body.innerHTML = ''
            + '<div style="color:#cbd5e1;font-size:.8rem;line-height:1.4">'
            +   '<div style="margin-bottom:.4rem">Drag any selected element to move them all together.</div>'
            +   '<div style="color:#94a3b8;font-size:.72rem">' + labels.join(', ') + '</div>'
            +   '<div style="margin-top:.6rem;color:#94a3b8;font-size:.72rem">Ctrl/Cmd-click an element to add or remove from the selection.</div>'
            + '</div>';
        return;
    }
    var meta = THEME_ELEMENTS.find(function(e){ return e.key === key; });
    if (!meta) return;
    var pe = (window.TIMER_THEME.elements || {})[key] || {};
    if (title) title.textContent = meta.label;

    var rows = [];

    // Visibility
    rows.push(''
        + '<div class="layout-inspector-row"><label>Visible</label>'
        + '<button type="button" class="ins-btn" onclick="toggleElementVisibility(\''+key+'\');renderInspector(\''+key+'\')">'
        + (pe.visible === false ? '&#128064; Show' : '&#128065; Hide')
        + '</button></div>');

    // Color(s) — skipped for elements that aren't text (e.g. QR code).
    if (!meta.noColor) {
        if (meta.hasClock) {
            var warnSec = parseInt(pe.warning_seconds, 10) || 120;
            var critSec = parseInt(pe.critical_seconds, 10) || 30;
            // Normal — color only, no threshold (everything above Warning is Normal).
            rows.push('<div class="layout-inspector-row"><label>Normal</label>'
                + '<input type="color" value="'+(pe.color_green||'#22c55e')+'" oninput="onInspectorColor(\'clock\',\'green\',this.value)"></div>');
            // Warning ≤ N sec
            rows.push('<div class="layout-inspector-row"><label>Warning &le;</label>'
                + '<span style="display:inline-flex;gap:.3rem;align-items:center">'
                + '<input type="number" min="1" max="86400" value="'+warnSec+'" '
                + 'style="width:4rem;background:#0f172a;color:#e2e8f0;border:1px solid #334155;border-radius:4px;padding:.15rem .3rem;font-size:.8rem" '
                + 'oninput="onClockThreshold(\'warning\',this.value)" title="Seconds remaining when clock switches to Warning color">'
                + '<span style="color:#94a3b8;font-size:.75rem">sec</span>'
                + '<input type="color" value="'+(pe.color_yellow||'#fbbf24')+'" oninput="onInspectorColor(\'clock\',\'yellow\',this.value)">'
                + '</span></div>');
            // Critical ≤ N sec
            rows.push('<div class="layout-inspector-row"><label>Critical &le;</label>'
                + '<span style="display:inline-flex;gap:.3rem;align-items:center">'
                + '<input type="number" min="1" max="86400" value="'+critSec+'" '
                + 'style="width:4rem;background:#0f172a;color:#e2e8f0;border:1px solid #334155;border-radius:4px;padding:.15rem .3rem;font-size:.8rem" '
                + 'oninput="onClockThreshold(\'critical\',this.value)" title="Seconds remaining when clock switches to Critical color (pulse)">'
                + '<span style="color:#94a3b8;font-size:.75rem">sec</span>'
                + '<input type="color" value="'+(pe.color_red||'#ef4444')+'" oninput="onInspectorColor(\'clock\',\'red\',this.value)">'
                + '</span></div>');

            // Clock variant — Text / Radial ring / Radial w/ checks
            var variant = pe.variant || 'text';
            rows.push('<div class="layout-inspector-row"><label>Style</label>'
                + '<select onchange="onClockVariant(this.value)" class="ins-btn" style="padding:.2rem .4rem;min-width:9rem">'
                +   '<option value="text"'          + (variant==='text'?' selected':'')          + '>Text</option>'
                +   '<option value="radial-ring"'   + (variant==='radial-ring'?' selected':'')   + '>Radial ring</option>'
                +   '<option value="radial-checks"' + (variant==='radial-checks'?' selected':'') + '>Radial w/ checks</option>'
                + '</select></div>');

            if (variant === 'radial-ring' || variant === 'radial-checks') {
                var thick = (pe.radial_thickness != null) ? parseFloat(pe.radial_thickness) : 0.12;
                rows.push('<div class="layout-inspector-row"><label>Thickness</label>'
                    + '<span style="display:inline-flex;align-items:center;gap:.3rem">'
                    + '<input type="range" min="0.04" max="0.30" step="0.01" value="'+thick+'" '
                    + 'oninput="onClockRadialOpt(\'radial_thickness\', parseFloat(this.value))" style="width:6rem">'
                    + '<span style="color:#94a3b8;font-size:.75rem" id="ins_thick_val">'+Math.round(thick*100)+'%</span>'
                    + '</span></div>');

                var dir = (pe.radial_direction === 'cw') ? 'cw' : 'ccw';
                rows.push('<div class="layout-inspector-row"><label>Direction</label>'
                    + '<button type="button" class="ins-btn" onclick="onClockRadialOpt(\'radial_direction\', \''
                    + (dir==='ccw'?'cw':'ccw') + '\');renderInspector(\'clock\')">'
                    + (dir==='ccw' ? 'Counter-clockwise' : 'Clockwise') + '</button></div>');

                if (variant === 'radial-checks') {
                    var segs = parseInt(pe.radial_segments, 10) || 12;
                    rows.push('<div class="layout-inspector-row"><label>Segments</label>'
                        + '<input type="number" min="2" max="60" value="'+segs+'" '
                        + 'style="width:4rem;background:#0f172a;color:#e2e8f0;border:1px solid #334155;border-radius:4px;padding:.15rem .3rem;font-size:.8rem" '
                        + 'oninput="onClockRadialOpt(\'radial_segments\', Math.max(2, Math.min(60, parseInt(this.value,10)||12)))"></div>');
                }
            }
        } else {
            var col = pe.color || '#94a3b8';
            rows.push('<div class="layout-inspector-row"><label>Color</label>'
                + '<input type="color" value="'+col+'" oninput="onInspectorColor(\''+key+'\',null,this.value)"></div>');
        }
    }

    // Size
    var sc = pe.scale || 1;
    rows.push(''
        + '<div class="layout-inspector-row"><label>Size</label>'
        + '<span style="display:inline-flex;align-items:center;gap:.3rem">'
        + '<button type="button" class="ins-btn" onclick="onInspectorScale(\''+key+'\',-0.1)">&minus;</button>'
        + '<span class="ins-scale" id="ins_scale_'+key+'">'+Math.round(sc*100)+'%</span>'
        + '<button type="button" class="ins-btn" onclick="onInspectorScale(\''+key+'\',0.1)">+</button>'
        + '</span></div>');

    // Reset position
    rows.push(''
        + '<div class="layout-inspector-row"><label>Position</label>'
        + '<button type="button" class="ins-btn" onclick="resetElementPosition(\''+key+'\')">&#8635; Reset</button></div>');

    // Font controls — text elements only (skipped for QR/Image/Stream which are noColor).
    if (!meta.noColor) {
        var fontKey = pe.font || '';
        var fontOpts = FONT_OPTIONS.map(function(f){
            var sel = (f.key === fontKey) ? ' selected' : '';
            return '<option value="'+f.key+'"'+sel+'>'+f.label+'</option>';
        }).join('');
        rows.push('<div class="layout-inspector-row"><label>Font</label>'
            + '<select onchange="onInspectorFont(\''+key+'\',this.value)" class="ins-btn" style="padding:.2rem .4rem;min-width:8.5rem">'
            + fontOpts + '</select></div>');

        var lsKey = pe.letter_spacing || '';
        var lsOpts = LETTER_SPACING_OPTIONS.map(function(s){
            var sel = (s.key === lsKey) ? ' selected' : '';
            return '<option value="'+s.key+'"'+sel+'>'+s.label+'</option>';
        }).join('');
        rows.push('<div class="layout-inspector-row"><label>Spacing</label>'
            + '<select onchange="onInspectorLetterSpacing(\''+key+'\',this.value)" class="ins-btn" style="padding:.2rem .4rem;min-width:6rem">'
            + lsOpts + '</select></div>');

        // Bold / Italic / Uppercase as inline toggle buttons.
        rows.push('<div class="layout-inspector-row"><label>Style</label>'
            + '<span style="display:inline-flex;gap:.25rem">'
            + '<button type="button" class="ins-btn'+(pe.bold?' is-active':'')+'" '
            + 'onclick="onInspectorTextToggle(\''+key+'\',\'bold\',this)" title="Bold" style="font-weight:700;min-width:1.8rem">B</button>'
            + '<button type="button" class="ins-btn'+(pe.italic?' is-active':'')+'" '
            + 'onclick="onInspectorTextToggle(\''+key+'\',\'italic\',this)" title="Italic" style="font-style:italic;min-width:1.8rem">I</button>'
            + '<button type="button" class="ins-btn'+(pe.uppercase?' is-active':'')+'" '
            + 'onclick="onInspectorTextToggle(\''+key+'\',\'uppercase\',this)" title="Uppercase" style="font-size:.7rem;letter-spacing:.05em;min-width:2.6rem">AA</button>'
            + '</span></div>');
    }

    // Upload / Remove for elements that carry an image URL (image element).
    if (meta.hasUpload) {
        rows.push(''
            + '<div class="layout-inspector-row"><label>Image</label>'
            + '<button type="button" class="ins-btn" onclick="document.getElementById(\'imageElUpload\').click()">'
            + (pe.url ? 'Replace&hellip;' : 'Upload&hellip;')
            + '</button></div>');
        rows.push('<input type="file" id="imageElUpload" accept="image/*" style="display:none" onchange="onImageElementUpload(this)">');
        if (pe.url) {
            rows.push(''
                + '<div class="layout-inspector-row"><label>&nbsp;</label>'
                + '<button type="button" class="ins-btn" style="background:#7f1d1d;border-color:#991b1b;color:#fff" onclick="onImageElementRemove()">Remove image</button></div>');
        }
    }

    // Streaming URL input — YouTube / Twitch / Prime Video.
    if (meta.hasStreamUrl) {
        var safeUrl = (pe.url || '').replace(/"/g, '&quot;');
        rows.push(''
            + '<div class="layout-inspector-row"><label>URL</label>'
            + '<input type="url" value="'+safeUrl+'" placeholder="YouTube / Twitch / Prime URL" '
            + 'style="flex:1;min-width:11rem;background:#0f172a;color:#e2e8f0;border:1px solid #334155;border-radius:4px;padding:.2rem .4rem;font-size:.8rem" '
            + 'onchange="onStreamUrlChange(this.value)"></div>');
        if (pe.url) {
            rows.push(''
                + '<div class="layout-inspector-row"><label>&nbsp;</label>'
                + '<button type="button" class="ins-btn" style="background:#7f1d1d;border-color:#991b1b;color:#fff" onclick="onStreamUrlChange(\'\')">Clear URL</button></div>');
            // Inline warning for hosts that commonly block iframe embedding.
            try {
                var h = (new URL(pe.url)).hostname.replace(/^www\./, '').toLowerCase();
                if (h === 'primevideo.com' || h.endsWith('.amazon.com') || h === 'amazon.com') {
                    rows.push('<div class="layout-inspector-row" style="color:#fbbf24;font-size:.75rem;line-height:1.3">'
                        + 'Prime Video usually blocks iframe embedding (X-Frame-Options). Test before relying on this.</div>');
                } else if (h === 'tv.youtube.com') {
                    rows.push('<div class="layout-inspector-row" style="color:#fbbf24;font-size:.75rem;line-height:1.3">'
                        + "YouTube TV live broadcasts are DRM-protected and won't embed. "
                        + "We'll try the video ID as a regular YouTube embed — works only for clips that exist on plain YouTube too.</div>");
                }
            } catch (e) {}
        }
    }

    body.innerHTML = rows.join('');
}

// Streaming URL handler — paired with the inspector input above. Persists into
// theme.elements.streaming and re-renders so the iframe picks up the change.
function onStreamUrlChange(val) {
    window.TIMER_THEME.elements = window.TIMER_THEME.elements || {};
    var pe = window.TIMER_THEME.elements.streaming = window.TIMER_THEME.elements.streaming || {};
    pe.url = (val || '').trim();
    pe.visible = !!pe.url;
    if (!pe.scale) pe.scale = 1;
    if (!pe.pos && pe.url) {
        pe.pos = { x: LAYOUT_DEFAULT_POS.streaming.x, y: LAYOUT_DEFAULT_POS.streaming.y };
    }
    applyTheme(window.TIMER_THEME);
    detachAllDragHandlers();
    attachAllDragHandlers();
    renderInspector('streaming');
}

// Upload handler used by the Image element's inspector and the Page inspector "Add image" button.
function onImageElementUpload(input) {
    if (!input.files || !input.files[0]) return;
    var fd = new FormData();
    fd.append('action', 'upload_theme_bg');
    fd.append('csrf_token', CSRF);
    fd.append('image', input.files[0]);
    fetch('/timer_dl.php', { method:'POST', body: fd })
        .then(function(r){ return r.json(); })
        .then(function(j){
            if (!j.ok) { alert(j.error || 'Upload failed'); return; }
            window.TIMER_THEME.elements = window.TIMER_THEME.elements || {};
            var pe = window.TIMER_THEME.elements.image = window.TIMER_THEME.elements.image || {};
            pe.url = j.url;
            pe.visible = true;
            if (!pe.scale) pe.scale = 1;
            if (!pe.pos)   pe.pos = { x: LAYOUT_DEFAULT_POS.image.x, y: LAYOUT_DEFAULT_POS.image.y };
            applyTheme(window.TIMER_THEME);
            // Re-attach drag handlers so the (potentially new) #themeImage node is interactive.
            detachAllDragHandlers();
            attachAllDragHandlers();
            selectElement('image');
        });
}

function onImageElementRemove() {
    if (!window.TIMER_THEME.elements || !window.TIMER_THEME.elements.image) return;
    window.TIMER_THEME.elements.image.url = '';
    window.TIMER_THEME.elements.image.visible = false;
    applyTheme(window.TIMER_THEME);
    detachAllDragHandlers();
    attachAllDragHandlers();
    // After removal, drop selection.
    deselectElement();
}

// Render the "Page" inspector: background type + colors + tray colors.
function renderPageInspector() {
    var bg = (window.TIMER_THEME.background) || {};
    var tray = (window.TIMER_THEME.tray) || {};
    var bgType = bg.type || 'color';
    var solidColor = bg.color || '#0f172a';
    var gFrom = (bg.gradient && bg.gradient.from) || '#0f172a';
    var gTo   = (bg.gradient && bg.gradient.to)   || '#1e293b';
    var gAng  = (bg.gradient && bg.gradient.angle) || 180;
    var imgUrl = bg.image_url || '';
    var trayBg     = tray.bg_color     || '#1e293b';
    var trayBtn    = tray.button_color || '#e2e8f0';
    var trayAccent = tray.accent_color || '#2563eb';

    function bgRow(val, label, hidden) {
        var sel = (bgType === val) ? 'checked' : '';
        var disp = hidden ? 'display:none' : '';
        return '<div class="layout-inspector-row" id="page_bg_row_'+val+'" style="'+disp+'">'
            + '<label><input type="radio" name="pageBgType" value="'+val+'" '+sel+' onchange="onPageBgType(this.value)"> '+label+'</label></div>';
    }

    var rows = [];
    rows.push('<div style="font-size:0.75rem;color:#94a3b8;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:0.25rem">Background</div>');
    rows.push('<div class="layout-inspector-row"><label>Type</label>'
        + '<select onchange="onPageBgType(this.value)" class="ins-btn" style="padding:0.2rem 0.4rem">'
        + '<option value="color"'    + (bgType==='color'?' selected':'')    + '>Solid</option>'
        + '<option value="gradient"' + (bgType==='gradient'?' selected':'') + '>Gradient</option>'
        + '</select></div>');

    // Solid color row
    rows.push('<div class="layout-inspector-row" id="page_solid_row" style="'+(bgType==='color'?'':'display:none')+'">'
        + '<label>Color</label>'
        + '<input type="color" value="'+solidColor+'" oninput="onPageBgChange(\'color\', this.value)"></div>');
    // Gradient rows
    rows.push('<div id="page_grad_block" style="'+(bgType==='gradient'?'':'display:none')+'">'
        + '<div class="layout-inspector-row"><label>From</label>'
        + '<input type="color" value="'+gFrom+'" oninput="onPageBgChange(\'gfrom\', this.value)"></div>'
        + '<div class="layout-inspector-row"><label>To</label>'
        + '<input type="color" value="'+gTo+'" oninput="onPageBgChange(\'gto\', this.value)"></div>'
        + '<div class="layout-inspector-row"><label>Angle <span id="page_gang_lbl" style="color:#94a3b8;font-size:0.75rem">'+gAng+'°</span></label>'
        + '<input type="range" min="0" max="360" value="'+gAng+'" style="width:7rem" oninput="onPageBgChange(\'gangle\', this.value);document.getElementById(\'page_gang_lbl\').textContent=this.value+\'°\'"></div>'
        + '</div>');

    rows.push('<div style="font-size:0.75rem;color:#94a3b8;text-transform:uppercase;letter-spacing:0.05em;margin:0.6rem 0 0.25rem">Toolbar</div>');
    rows.push('<div class="layout-inspector-row"><label>Button bg</label>'
        + '<input type="color" value="'+trayBg+'" oninput="onPageTrayChange(\'bg_color\', this.value)"></div>');
    rows.push('<div class="layout-inspector-row"><label>Button text</label>'
        + '<input type="color" value="'+trayBtn+'" oninput="onPageTrayChange(\'button_color\', this.value)"></div>');
    rows.push('<div class="layout-inspector-row"><label>Accent</label>'
        + '<input type="color" value="'+trayAccent+'" oninput="onPageTrayChange(\'accent_color\', this.value)"></div>');

    // Stream URL — placed in the Page inspector so it's discoverable without first
    // clicking the (initially hidden) Stream element on the canvas. Bootstraps the
    // element when set and selects it for further positioning.
    var streamEl = (window.TIMER_THEME.elements && window.TIMER_THEME.elements.streaming) || {};
    var streamUrl = (streamEl.url || '').replace(/"/g, '&quot;');
    rows.push('<div style="font-size:0.75rem;color:#94a3b8;text-transform:uppercase;letter-spacing:0.05em;margin:0.6rem 0 0.25rem">Stream</div>');
    rows.push('<div class="layout-inspector-row"><label>URL</label>'
        + '<input type="url" value="'+streamUrl+'" placeholder="YouTube / Twitch / Prime URL" '
        + 'style="flex:1;min-width:11rem;background:#0f172a;color:#e2e8f0;border:1px solid #334155;border-radius:4px;padding:.2rem .4rem;font-size:.8rem" '
        + 'onchange="onStreamUrlChange(this.value);renderInspector(\'page\')"></div>');
    if (streamEl.url) {
        rows.push('<div class="layout-inspector-row"><label>&nbsp;</label>'
            + '<button type="button" class="ins-btn" onclick="selectElement(\'streaming\')">Edit stream panel</button></div>');
        rows.push('<div class="layout-inspector-row"><label>&nbsp;</label>'
            + '<button type="button" class="ins-btn" style="background:#7f1d1d;border-color:#991b1b;color:#fff" '
            + 'onclick="onStreamUrlChange(\'\');renderInspector(\'page\')">Clear stream URL</button></div>');
        if (IS_TOUCH_DEVICE) {
            rows.push('<div class="layout-inspector-row" style="color:#94a3b8;font-size:.72rem;line-height:1.3">'
                + 'Hidden on this device: the stream iframe captures taps and would block the screen-wake handler. It will appear on desktop/TV viewers.</div>');
        }
        try {
            var sh = (new URL(streamEl.url)).hostname.replace(/^www\./, '').toLowerCase();
            if (sh === 'primevideo.com' || sh === 'amazon.com' || sh.endsWith('.amazon.com')) {
                rows.push('<div class="layout-inspector-row" style="color:#fbbf24;font-size:.75rem;line-height:1.3">'
                    + 'Prime Video usually blocks iframe embedding (X-Frame-Options). Test before relying on this.</div>');
            } else if (sh === 'tv.youtube.com') {
                rows.push('<div class="layout-inspector-row" style="color:#fbbf24;font-size:.75rem;line-height:1.3">'
                    + "YouTube TV live broadcasts are DRM-protected and won't embed. "
                    + "We'll try the video ID as a regular YouTube embed — works only for clips that exist on plain YouTube too.</div>");
            }
        } catch (e) {}
    }

    return rows.join('');
}

function onPageBgType(t) {
    window.TIMER_THEME.background = window.TIMER_THEME.background || {};
    window.TIMER_THEME.background.type = t;
    applyTheme(window.TIMER_THEME);
    // Toggle the sub-blocks without re-rendering everything (preserves user's color-picker focus).
    var solid = document.getElementById('page_solid_row');
    var grad  = document.getElementById('page_grad_block');
    var img   = document.getElementById('page_img_block');
    if (solid) solid.style.display = (t==='color')    ? '' : 'none';
    if (grad)  grad.style.display  = (t==='gradient') ? '' : 'none';
    if (img)   img.style.display   = (t==='image')    ? '' : 'none';
}

function onPageBgChange(field, val) {
    window.TIMER_THEME.background = window.TIMER_THEME.background || {};
    var bg = window.TIMER_THEME.background;
    if (field === 'color') bg.color = val;
    else if (field === 'gfrom' || field === 'gto' || field === 'gangle') {
        bg.gradient = bg.gradient || {};
        if (field === 'gfrom') bg.gradient.from = val;
        if (field === 'gto')   bg.gradient.to   = val;
        if (field === 'gangle') bg.gradient.angle = parseInt(val, 10);
    }
    applyTheme(window.TIMER_THEME);
}

function onPageBgUpload(input) {
    if (!input.files || !input.files[0]) return;
    var fd = new FormData();
    fd.append('action', 'upload_theme_bg');
    fd.append('csrf_token', CSRF);
    fd.append('image', input.files[0]);
    fetch('/timer_dl.php', { method:'POST', body: fd })
        .then(function(r){ return r.json(); })
        .then(function(j){
            if (!j.ok) { alert(j.error || 'Upload failed'); return; }
            window.TIMER_THEME.background = window.TIMER_THEME.background || {};
            window.TIMER_THEME.background.image_url = j.url;
            window.TIMER_THEME.background.type = 'image';
            applyTheme(window.TIMER_THEME);
            renderInspector('page');  // re-render to show filename + Remove button
        });
}

function onPageBgClear() {
    if (window.TIMER_THEME.background) {
        window.TIMER_THEME.background.image_url = '';
        window.TIMER_THEME.background.type = 'color';
    }
    applyTheme(window.TIMER_THEME);
    renderInspector('page');
}

function onPageTrayChange(field, val) {
    window.TIMER_THEME.tray = window.TIMER_THEME.tray || {};
    window.TIMER_THEME.tray[field] = val;
    applyTheme(window.TIMER_THEME);
}

function onInspectorColor(key, sub, val) {
    window.TIMER_THEME.elements = window.TIMER_THEME.elements || {};
    var pe = window.TIMER_THEME.elements[key] = window.TIMER_THEME.elements[key] || {};
    if (sub) pe['color_'+sub] = val;
    else pe.color = val;
    applyTheme(window.TIMER_THEME);
}

function onInspectorFont(key, val) {
    window.TIMER_THEME.elements = window.TIMER_THEME.elements || {};
    var pe = window.TIMER_THEME.elements[key] = window.TIMER_THEME.elements[key] || {};
    pe.font = val || '';
    applyTheme(window.TIMER_THEME);
}
function onInspectorLetterSpacing(key, val) {
    window.TIMER_THEME.elements = window.TIMER_THEME.elements || {};
    var pe = window.TIMER_THEME.elements[key] = window.TIMER_THEME.elements[key] || {};
    pe.letter_spacing = val || '';
    applyTheme(window.TIMER_THEME);
}
function onInspectorTextToggle(key, prop, btnEl) {
    window.TIMER_THEME.elements = window.TIMER_THEME.elements || {};
    var pe = window.TIMER_THEME.elements[key] = window.TIMER_THEME.elements[key] || {};
    pe[prop] = !pe[prop];
    applyTheme(window.TIMER_THEME);
    // Update the clicked button's active state in place instead of rebuilding the
    // inspector body — a rebuild detaches the button mid-event-dispatch, which
    // makes ev.target.closest('.layout-inspector') return null in the body click
    // handler and falsely triggers a 'page' selection (click-through).
    if (btnEl && btnEl.classList) btnEl.classList.toggle('is-active', !!pe[prop]);
}

// Editable thresholds for the Clock's Warning / Critical color bands.
function onClockThreshold(which, val) {
    window.TIMER_THEME.elements = window.TIMER_THEME.elements || {};
    var pe = window.TIMER_THEME.elements.clock = window.TIMER_THEME.elements.clock || {};
    var n = Math.max(1, Math.min(86400, parseInt(val, 10) || 0));
    if (which === 'warning') pe.warning_seconds = n;
    else if (which === 'critical') pe.critical_seconds = n;
    // No applyTheme needed — renderClock pulls from TIMER_THEME every tick.
}

// Clock variant switcher — Text / Radial ring / Radial w/ checks.
function onClockVariant(v) {
    if (v !== 'text' && v !== 'radial-ring' && v !== 'radial-checks') v = 'text';
    window.TIMER_THEME.elements = window.TIMER_THEME.elements || {};
    var pe = window.TIMER_THEME.elements.clock = window.TIMER_THEME.elements.clock || {};
    pe.variant = v;
    // Force a clean rebuild — clear inner content and the radial 'built' flags.
    var node = document.getElementById('timerClock');
    if (node) {
        node.innerHTML = '';
        delete node.dataset.variant;
        delete node.dataset.built;
        delete node.dataset.builtVariant;
        delete node.dataset.builtSegs;
    }
    if (typeof renderClock === 'function') renderClock();
    renderInspector('clock');
}

// Radial-specific option setter (thickness, direction, segments).
function onClockRadialOpt(field, val) {
    window.TIMER_THEME.elements = window.TIMER_THEME.elements || {};
    var pe = window.TIMER_THEME.elements.clock = window.TIMER_THEME.elements.clock || {};
    pe[field] = val;
    var node = document.getElementById('timerClock');
    // Segment count or thickness changes require a full rebuild (not just attribute mutation).
    if (field === 'radial_segments' || field === 'radial_thickness') {
        if (node) {
            node.innerHTML = '';
            delete node.dataset.built;
            delete node.dataset.builtVariant;
            delete node.dataset.builtSegs;
        }
    }
    if (typeof renderClock === 'function') renderClock();
    if (field === 'radial_thickness') {
        var lbl = document.getElementById('ins_thick_val');
        if (lbl) lbl.textContent = Math.round(val * 100) + '%';
    }
}

function onInspectorScale(key, delta) {
    window.TIMER_THEME.elements = window.TIMER_THEME.elements || {};
    var pe = window.TIMER_THEME.elements[key] = window.TIMER_THEME.elements[key] || {};
    var v = Math.max(0.3, Math.min(6.0, (pe.scale || 1) + delta));
    pe.scale = Math.round(v * 100) / 100;
    var lbl = document.getElementById('ins_scale_' + key);
    if (lbl) lbl.textContent = Math.round(pe.scale * 100) + '%';
    applyTheme(window.TIMER_THEME);
}

function resetElementPosition(key) {
    var pe = (window.TIMER_THEME.elements || {})[key];
    if (pe) delete pe.pos;
    // Re-capture from a sensible default so the element stays draggable.
    if (LAYOUT_DEFAULT_POS[key]) pe.pos = { x: LAYOUT_DEFAULT_POS[key].x, y: LAYOUT_DEFAULT_POS[key].y };
    applyTheme(window.TIMER_THEME);
}

function refreshHiddenInInspector() {
    // If the currently inspected element had its visibility flipped, the panel button
    // label needs updating. Just re-render.
    if (LAYOUT_SELECTED_KEY) renderInspector(LAYOUT_SELECTED_KEY);
}

// ─── §7.18  Generic drag-by-header helper for the pill & inspector panel ──
function makePanelDraggable(panel, handle) {
    if (!panel || !handle) return;
    function start(ev) {
        // Ignore drags that started on a button inside the handle (e.g. close).
        if (ev.target.tagName === 'BUTTON') return;
        ev.preventDefault();
        var pt = ev.touches ? ev.touches[0] : ev;
        var rect = panel.getBoundingClientRect();
        var offX = pt.clientX - rect.left;
        var offY = pt.clientY - rect.top;
        // Once dragged, clear the centering transform so left/top are exact.
        panel.style.transform = 'none';
        function onMove(ev2) {
            var p = ev2.touches ? ev2.touches[0] : ev2;
            var nx = Math.max(0, Math.min(window.innerWidth  - rect.width,  p.clientX - offX));
            var ny = Math.max(0, Math.min(window.innerHeight - rect.height, p.clientY - offY));
            panel.style.left = nx + 'px';
            panel.style.top  = ny + 'px';
            panel.style.right = 'auto';
        }
        function onUp() {
            document.removeEventListener('mousemove', onMove);
            document.removeEventListener('mouseup', onUp);
            document.removeEventListener('touchmove', onMove);
            document.removeEventListener('touchend', onUp);
        }
        document.addEventListener('mousemove', onMove);
        document.addEventListener('mouseup', onUp);
        document.addEventListener('touchmove', onMove, { passive: false });
        document.addEventListener('touchend', onUp);
    }
    handle.addEventListener('mousedown', start);
    handle.addEventListener('touchstart', start, { passive: false });
}

// Wire up the pill and inspector header as drag handles on first load.
(function() {
    var pill = document.getElementById('layoutEditPill');
    var pillHandle = document.getElementById('pillHandle');
    makePanelDraggable(pill, pillHandle);
    var insp = document.getElementById('layoutInspector');
    var inspHeader = document.getElementById('inspectorHeader');
    makePanelDraggable(insp, inspHeader);
    var objs = document.getElementById('layoutObjectsPanel');
    var objsHeader = document.getElementById('objectsHeader');
    if (objs && objsHeader) makePanelDraggable(objs, objsHeader);

    // Body-level click handler — selects the "page" pseudo-element when the user
    // clicks empty background space (in edit mode only). Walks composedPath instead
    // of ev.target.closest so detached targets (e.g. an inspector button whose
    // parent rebuilt mid-click) still resolve correctly against the skip list.
    var SKIP_CLASSES = ['timer-positioned','layout-edit-pill','layout-inspector','layout-objects-panel','timer-levels-overlay','layout-eye'];
    document.addEventListener('click', function(ev) {
        if (!LAYOUT_EDIT_ON) return;
        var path = (typeof ev.composedPath === 'function') ? ev.composedPath() : [];
        for (var i = 0; i < path.length; i++) {
            var n = path[i];
            if (!n || !n.classList) continue;
            for (var c = 0; c < SKIP_CLASSES.length; c++) {
                if (n.classList.contains(SKIP_CLASSES[c])) return;
            }
        }
        // Fallback for browsers without composedPath (none we target, but harmless).
        if (ev.target && ev.target.closest && ev.target.closest('.timer-positioned, .layout-edit-pill, .layout-inspector, .layout-objects-panel, .timer-levels-overlay, .layout-eye')) return;
        selectElement('page');
    });
})();

// Add `open` class behavior to theme overlay (mirrors levels overlay).
(function(){
    var style = document.createElement('style');
    style.textContent = '.timer-levels-overlay#themeOverlay.open, .timer-levels-overlay#saveThemeOverlay.open, .timer-levels-overlay#presetOverlay.open { display:flex; align-items:center; justify-content:center; }';
    document.head.appendChild(style);
})();

// Warn before leaving with unsaved blind-structure edits (a local draft is also
// kept, but this catches the common "navigate away and lose it" case).
window.addEventListener('beforeunload', function(e) {
    if (levelsDirty) { e.preventDefault(); e.returnValue = ''; }
});

// ─── §7.19  Init ─────────────────────────────────────────────────
if (window.TIMER_THEME) applyTheme(window.TIMER_THEME);
renderAll();
startLocalTick(); // smooth second-by-second display between polls
setInterval(pollState, POLL_INTERVAL); // everyone polls server — server is master

// Floating toolbar: auto-hide on all screens
var tray = document.getElementById('timerTray');
if (tray) {
    var _trayHideTimer = null;
    var _trayHideDelay = window.innerWidth > 768 ? 3000 : 4000;
    function showTray() {
        tray.classList.remove('tray-hidden');
        clearTimeout(_trayHideTimer);
        _trayHideTimer = setTimeout(function() { tray.classList.add('tray-hidden'); }, _trayHideDelay);
    }
    // Desktop: mouse move shows toolbar
    document.addEventListener('mousemove', showTray);
    // All: tray clicks keep it visible (don't auto-hide while interacting)
    tray.addEventListener('click', function(e) { e.stopPropagation(); showTray(); });
    showTray(); // start visible, then auto-hide

    // Swipe gesture: swipe up from bottom edge shows tray, swipe down hides it
    var _traySwipeStartX = 0, _traySwipeStartY = 0, _traySwipeTracking = false;
    var BOTTOM_EDGE = 40;
    var TRAY_MIN_SWIPE = 40;

    document.addEventListener('touchstart', function(e) {
        var t = e.touches[0];
        _traySwipeStartX = t.clientX;
        _traySwipeStartY = t.clientY;
        _traySwipeTracking = (t.clientY > window.innerHeight - BOTTOM_EDGE) || !tray.classList.contains('tray-hidden');
    }, { passive: true });

    document.addEventListener('touchend', function(e) {
        if (!_traySwipeTracking) return;
        _traySwipeTracking = false;
        var t = e.changedTouches[0];
        var dy = t.clientY - _traySwipeStartY;
        var dx = Math.abs(t.clientX - _traySwipeStartX);
        if (dx > Math.abs(dy)) return; // horizontal swipe, not vertical

        if (tray.classList.contains('tray-hidden') && dy < -TRAY_MIN_SWIPE && _traySwipeStartY > window.innerHeight - BOTTOM_EDGE) {
            // Swipe up from bottom edge → show
            showTray();
        } else if (!tray.classList.contains('tray-hidden') && dy > TRAY_MIN_SWIPE) {
            // Swipe down → hide
            tray.classList.add('tray-hidden');
            clearTimeout(_trayHideTimer);
        }
    }, { passive: true });
}

// Spacebar hotkey for start/stop (only when not typing in an input)
document.addEventListener('keydown', function(e) {
    if (e.code === 'Space' && !e.target.closest('input, textarea, select, [contenteditable]')) {
        e.preventDefault();
        togglePlay();
    }
});

// Open TV display mode in a new tab (for casting/TV browser)
function openDisplayMode() {
    var url = location.origin + '/timer.php?view=remote&key=' + encodeURIComponent(REMOTE_KEY) + '&display=1';
    window.open(url, '_blank');
}

// Hide fullscreen button on iOS (not supported)
if (/iPhone|iPad|iPod/.test(navigator.userAgent) && !document.fullscreenEnabled && !document.webkitFullscreenEnabled) {
    var fsBtn = document.getElementById('btnFullscreen');
    if (fsBtn) fsBtn.style.display = 'none';
}

if (!IS_REMOTE) {

    // Generate QR code using qrcode-generator library
    var qrWrap = document.getElementById('qrWrap');
    if (qrWrap && typeof qrcode !== 'undefined') {
        var remoteUrl = location.origin + '/timer.php?view=remote&key=' + REMOTE_KEY;
        var qr = qrcode(0, 'M');
        qr.addData(remoteUrl);
        qr.make();
        var size = 120;
        // Use the qrcode library's own image output. The previous hand-rolled canvas draw
        // rendered as a solid black square in some browsers, so render to an <img> instead.
        var img = document.createElement('img');
        img.src = qr.createDataURL(8, 4);
        img.width = size;
        img.height = size;
        img.style.imageRendering = 'pixelated';
        img.alt = 'QR code';
        qrWrap.appendChild(img);

        qrWrap.style.cursor = 'pointer';
        qrWrap.addEventListener('click', function() {
            navigator.clipboard.writeText(remoteUrl).then(function() {
                qrWrap.title = 'Link copied!';
                setTimeout(function() { qrWrap.title = 'Scan to view timer on your phone'; }, 2000);
            });
        });
    }
}

// ─── §7.20  Player Panel ────────────────────────────────────────────
var PP_PLAYERS = [];
var PP_SESSION = null;
var PP_OPEN = false;

function togglePlayerPanel() {
    var panel = document.getElementById('playerPanel');
    var overlay = document.getElementById('playerPanelOverlay');
    if (!panel) return;
    panel.classList.remove('pp-peek'); // opening mid-peek: let .open's right:0 win
    PP_OPEN = !PP_OPEN;
    panel.classList.toggle('open', PP_OPEN);
    if (overlay) overlay.style.display = PP_OPEN ? '' : 'none';
    if (PP_OPEN) fetchPlayers();
}

// Attention peek: ~2.5s after load, bounce the panel edge out twice (and wiggle
// the Players tray button) so hosts notice the slide-out exists. Fires once per
// page load; skipped if the panel is already open or in display mode. The panel
// markup only renders for hosts with a session, so this no-ops everywhere else.
setTimeout(function() {
    var panel = document.getElementById('playerPanel');
    if (!panel || PP_OPEN || document.body.classList.contains('display-mode')) return;
    var btn = document.getElementById('ppTrayBtn');
    panel.classList.add('pp-peek');
    if (btn) btn.classList.add('pp-nudge');
    panel.addEventListener('animationend', function() {
        panel.classList.remove('pp-peek');
        if (btn) btn.classList.remove('pp-nudge');
    }, { once: true });
}, 2500);

// Swipe gesture: swipe left from right edge opens player panel, swipe right closes it
(function() {
    var startX = 0, startY = 0, tracking = false;
    var EDGE_ZONE = 30;   // px from right edge to start a swipe-open
    var MIN_SWIPE = 50;   // minimum horizontal distance
    var MAX_DRIFT = 40;   // max vertical drift

    document.addEventListener('touchstart', function(e) {
        var t = e.touches[0];
        startX = t.clientX;
        startY = t.clientY;
        // Track if starting from right edge (to open) or if panel is open (to close)
        tracking = (startX > window.innerWidth - EDGE_ZONE) || PP_OPEN;
    }, { passive: true });

    document.addEventListener('touchend', function(e) {
        if (!tracking) return;
        tracking = false;
        var t = e.changedTouches[0];
        var dx = t.clientX - startX;
        var dy = Math.abs(t.clientY - startY);
        if (dy > MAX_DRIFT) return; // not a horizontal swipe

        if (!PP_OPEN && dx < -MIN_SWIPE && startX > window.innerWidth - EDGE_ZONE) {
            // Swipe left from right edge → open
            togglePlayerPanel();
        } else if (PP_OPEN && dx > MIN_SWIPE) {
            // Swipe right → close
            togglePlayerPanel();
        }
    }, { passive: true });
})();

function fetchPlayers() {
    if (!EVENT_ID) return;
    var fd = new FormData();
    fd.append('action', 'get_session');
    fd.append('event_id', EVENT_ID);
    fetch('/checkin_dl.php?action=get_session&event_id=' + EVENT_ID, { credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(j) {
            if (j.ok && j.players) {
                PP_PLAYERS = j.players;
                PP_SESSION = j.session || PP_SESSION;
                POOL = j.pool || POOL;
                renderPlayerPanel();
            }
        })
        .catch(function() {});
}

function renderPlayerPanel() {
    var body = document.getElementById('playerPanelBody');
    if (!body) return;
    var h = '';
    var isTourney = GAME_TYPE === 'tournament';
    var activePlayers = PP_PLAYERS.filter(function(p) { return !parseInt(p.removed); });

    for (var i = 0; i < activePlayers.length; i++) {
        var p = activePlayers[i];
        var isElim = parseInt(p.eliminated);
        var hasCashedOut = !isTourney && p.cash_out !== null && p.cash_out !== undefined;

        var statusText = '', statusColor = '#94a3b8';
        if (isTourney) {
            if (isElim) { statusText = ' #' + (p.finish_position || '?'); statusColor = '#ef4444'; }
            else if (parseInt(p.bought_in)) { statusText = ' Playing'; statusColor = '#22c55e'; }
        } else {
            if (hasCashedOut) { statusText = ' Out'; statusColor = '#64748b'; }
            else if (parseInt(p.bought_in)) { statusText = ' Playing'; statusColor = '#22c55e'; }
        }

        h += '<div class="pp-card' + (isElim ? ' elim' : '') + '">';
        h += '<span class="pp-name">' + escHtml(p.display_name) + '</span>';
        if (statusText) h += '<span class="pp-status" style="color:' + statusColor + '">' + statusText + '</span>';
        h += '<div class="pp-actions">';

        if (!isElim && !hasCashedOut) {
            if (isTourney) {
                if (parseInt(p.bought_in)) {
                    if (PP_SESSION && parseInt(PP_SESSION.rebuy_allowed)) {
                        h += '<div class="pp-counter"><span style="font-size:.55rem;color:#94a3b8;font-weight:700;letter-spacing:.03em;min-width:1.2rem">RE</span><button onclick="ppRebuy(' + p.id + ',-1)">-</button><span>' + (p.rebuys||0) + '</span><button onclick="ppRebuy(' + p.id + ',1)">+</button></div>';
                    }
                    if (PP_SESSION && parseInt(PP_SESSION.addon_allowed)) {
                        var aoCount = parseInt(p.addons || 0);
                        h += '<div class="pp-counter" style="gap:.25rem;align-items:center">'
                           + '<span style="font-size:.55rem;color:#94a3b8;font-weight:700;letter-spacing:.03em;min-width:1.2rem">AO</span>'
                           + '<button onclick="ppAddAddon(' + p.id + ')" style="font-size:.7rem;padding:.15rem .45rem;border-radius:3px;border:1px solid #c4b5fd;background:#f5f3ff;color:#6d28d9;cursor:pointer;font-weight:600">+</button>'
                           + (aoCount > 0 ? '<span onclick="ppRemoveAddon(' + p.id + ')" title="Tap to remove last" style="display:inline-flex;align-items:center;justify-content:center;min-width:1.1rem;height:1.1rem;padding:0 .3rem;border-radius:9px;background:#7c3aed;color:#fff;font-size:.65rem;font-weight:700;cursor:pointer">' + aoCount + '</span>' : '')
                           + '</div>';
                    }
                    h += '<button class="pp-elim" onclick="ppEliminate(' + p.id + ')">Elim</button>';
                } else {
                    h += '<button onclick="ppBuyin(' + p.id + ')">Buy In</button>';
                }
            } else {
                if (parseInt(p.bought_in)) {
                    h += '<button onclick="ppCashout(' + p.id + ')">Cash Out</button>';
                } else {
                    h += '<button onclick="ppCashin(' + p.id + ')">Cash In</button>';
                }
            }
        }
        if (isElim) h += '<button class="pp-undo" onclick="ppUnelim(' + p.id + ')">Undo</button>';
        if (hasCashedOut) h += '<button class="pp-undo" onclick="ppUndoCashout(' + p.id + ')">Undo</button>';
        h += '</div></div>';
    }
    if (activePlayers.length === 0) h = '<div style="text-align:center;padding:2rem;color:#64748b">No players</div>';
    body.innerHTML = h;
}

function ppPost(action, data, cb) {
    var fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('action', action);
    for (var k in data) fd.append(k, data[k]);
    fetch('/checkin_dl.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(j) { if (j.ok) { fetchPlayers(); if (cb) cb(j); } })
        .catch(function() {});
}

function ppBuyin(pid) { ppPost('toggle_buyin', { player_id: pid }); }
function ppRebuy(pid, d) { ppPost('update_rebuys', { player_id: pid, delta: d }); }
function ppAddAddon(pid) { ppPost('update_addons', { player_id: pid, delta: 1 }); }
function ppRemoveAddon(pid) {
    ppPost('update_addons', { player_id: pid, delta: -1 });
}
function ppEliminate(pid) {
    var playing = PP_PLAYERS.filter(function(p) { return !parseInt(p.eliminated) && parseInt(p.bought_in); }).length;
    ppPost('eliminate_player', { player_id: pid, finish_position: playing });
}
function ppUnelim(pid) { ppPost('uneliminate_player', { player_id: pid }); }
function ppCashin(pid) {
    var amt = prompt('Cash in amount ($):', '20');
    if (amt === null) return;
    ppPost('add_cashin', { player_id: pid, amount: Math.round(parseFloat(amt) * 100) });
}
function ppCashout(pid) {
    var amt = prompt('Cash out amount ($):');
    if (amt === null) return;
    ppPost('set_cashout', { player_id: pid, cash_out: Math.round(parseFloat(amt) * 100) });
}
function ppUndoCashout(pid) { ppPost('set_cashout', { player_id: pid, cash_out: '' }); }

function escHtml(s) {
    if (!s) return '';
    var d = document.createElement('div');
    d.appendChild(document.createTextNode(s));
    return d.innerHTML;
}

// ─── §7.21  Export/Import Blind Structures ──────────────────────────
function exportLevels() {
    collectLevelsFromTable();
    var presetName = document.getElementById('presetSelect');
    var name = presetName ? (presetName.options[presetName.selectedIndex]?.text || 'custom') : 'custom';
    // Export as CSV: header row + one row per level
    var csv = 'Level,Small Blind,Big Blind,Ante,Minutes,Type\n';
    LEVELS.forEach(function(l) {
        csv += l.level_number + ',' + l.small_blind + ',' + l.big_blind + ',' + (l.ante || 0) + ',' + l.duration_minutes + ',' + (parseInt(l.is_break) ? 'Break' : 'Play') + '\n';
    });
    var blob = new Blob([csv], { type: 'text/csv' });
    var a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'blinds_' + name.replace(/[^a-zA-Z0-9]/g, '_') + '.csv';
    a.click();
    URL.revokeObjectURL(a.href);
}

function importLevels(input) {
    var file = input.files[0];
    if (!file) return;
    var reader = new FileReader();
    reader.onload = function(e) {
        var text = e.target.result.trim();
        var lines = text.split('\n').filter(function(l) { return l.trim() !== ''; });
        // Skip header row if first column starts with a letter
        var start = 0;
        if (lines.length > 0 && /^[A-Za-z]/.test(lines[0].trim())) start = 1;
        var parsed = [];
        for (var i = start; i < lines.length; i++) {
            var cols = lines[i].split(',');
            if (cols.length < 5) continue;
            parsed.push({
                level_number: i - start + 1,
                small_blind: parseInt(cols[1]) || 0,
                big_blind: parseInt(cols[2]) || 0,
                ante: parseInt(cols[3]) || 0,
                duration_minutes: parseInt(cols[4]) || 15,
                is_break: (cols[5] || '').trim().toLowerCase() === 'break' ? 1 : 0
            });
        }
        if (parsed.length === 0) {
            alert('Invalid CSV: no levels found.');
            return;
        }
        LEVELS = parsed;
        levelsCollected = true;
        renderLevelsTable();
        alert('Imported ' + LEVELS.length + ' levels. Click Save to apply.');
    };
    reader.readAsText(file);
    input.value = '';
}
</script>
</body>
</html>
