<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_poker_helpers.php';

$db = get_db();
$site_name = get_setting('site_name', 'Game Night');

$is_remote = false;
$is_guest = false;
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

// ─── Remote viewer/controller mode ────────────────────────
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

// ─── Host mode ────────────────────────────────────────────
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

// Compute corrected remaining time
$remaining = (int)($timer['time_remaining_seconds'] ?? 0);
if ((int)($timer['is_running'] ?? 0) && !empty($timer['updated_at'])) {
    $elapsed = time() - strtotime($timer['updated_at']);
    $remaining = max(0, $remaining - $elapsed);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Poker Timer &mdash; <?= htmlspecialchars($site_name) ?></title>
    <link rel="icon" href="/favicon.php">
    <link rel="stylesheet" href="/style.css">
    <style>
        html { height: 100%; }
        .timer-body {
            background: #0f172a;
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
            font-size: clamp(1rem, 2.5vw, 1.5rem) !important;
            opacity: 1 !important;
            color: #fff;
        }
        .timer-stat { color: #94a3b8; }
        .timer-stat b { color: #e2e8f0; font-size: 110%; }

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
            font-size: clamp(0.9rem, 3vw, 2.5rem);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.15em;
            color: #94a3b8;
        }
        .timer-blinds {
            font-size: clamp(1.5rem, 8vw, 8rem);
            font-weight: 800;
            color: #fff;
            line-height: 1.1;
            font-variant-numeric: tabular-nums;
        }
        .timer-ante {
            font-size: clamp(0.8rem, 2vw, 2rem);
            color: #64748b;
            font-weight: 500;
        }
        .timer-clock {
            font-size: min(25vw, 35vh);
            font-weight: 800;
            font-variant-numeric: tabular-nums;
            line-height: 1;
            margin: 0;
            transition: color 0.3s;
        }
        .timer-green { color: #22c55e; }
        .timer-yellow { color: #fbbf24; }
        .timer-red { color: #ef4444; animation: pulse 1s ease-in-out infinite; }
        .timer-paused-label {
            font-size: clamp(0.8rem, 2vw, 1.8rem);
            color: #fbbf24;
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
            font-size: clamp(1.1rem, 2.5vw, 1.8rem);
            color: #64748b;
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
        .timer-tray-handle {
            width: 100%;
            max-width: 900px;
            padding: 0.3rem 0;
            cursor: pointer;
            display: flex;
            justify-content: center;
            -webkit-tap-highlight-color: transparent;
        }
        .timer-tray-grip {
            width: 40px;
            height: 4px;
            background: #475569;
            border-radius: 2px;
        }
        .timer-tray {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease;
            width: 100%;
            max-width: 900px;
        }
        .timer-tray.open {
            max-height: 200px;
        }
        .timer-tray-grid {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.4rem;
            flex-wrap: wrap;
            padding: 0.3rem 0 0.5rem;
        }
        @media (min-width: 769px) {
            .timer-tray { max-height: 200px; }
            .timer-tray-handle { display: none; }
        }
        .timer-primary-controls button,
        .timer-tray-grid button,
        .timer-controls button {
            background: #1e293b;
            color: #e2e8f0;
            border: 1px solid #334155;
            border-radius: 8px;
            padding: 0.6rem 1.2rem;
            font-size: clamp(0.8rem, 1.5vw, 1rem);
            cursor: pointer;
            transition: background 0.15s, border-color 0.15s;
            white-space: nowrap;
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
        .timer-min-label {
            padding: 0 0.5rem;
            color: #94a3b8;
            font-size: clamp(0.75rem, 1.3vw, 0.9rem);
            font-weight: 600;
            user-select: none;
        }

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
            color: #94a3b8;
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
        .timer-levels-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
        }
        .timer-levels-table th {
            text-align: left;
            padding: 0.4rem;
            border-bottom: 1px solid #334155;
            color: #94a3b8;
            font-weight: 600;
        }
        .timer-levels-table td {
            padding: 0.35rem 0.4rem;
            border-bottom: 1px solid #1e293b;
        }
        .timer-levels-table tr.is-break td { color: #fbbf24; font-style: italic; }
        .timer-levels-table tr.current-level td { background: rgba(34,197,94,0.15); }
        .timer-levels-table input[type="number"] {
            background: #0f172a;
            color: #e2e8f0;
            border: 1px solid #334155;
            border-radius: 4px;
            padding: 0.25rem 0.4rem;
            width: 70px;
            font-size: 0.85rem;
        }
        .timer-levels-table .lvl-actions button {
            background: none;
            border: none;
            color: #ef4444;
            cursor: pointer;
            font-size: 1.1rem;
            padding: 0.2rem;
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
            background: #2563eb;
            border-color: #2563eb;
            color: #fff;
        }
        .timer-level-btns button.btn-save:hover { background: #1d4ed8; }
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
            .timer-blinds { font-size: clamp(1.8rem, 8vw, 5rem); }
            .timer-clock { font-size: min(22vw, 30vh); }
            .timer-level-label { font-size: clamp(0.9rem, 2.5vw, 1.5rem); }
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
            .timer-level-label { font-size: 1rem; }
            .timer-blinds { font-size: clamp(1.5rem, 6vw, 3rem); }
            .timer-ante { font-size: 0.85rem; }
            .timer-clock { font-size: min(20vw, 25vh); }
            .timer-paused-label { font-size: 0.9rem; min-height: 1.2em; }
            .timer-next { font-size: 0.8rem; }
            .timer-primary-controls, .timer-tray-grid { padding: 0.2rem 0; gap: 0.25rem; }
            .timer-primary-controls button, .timer-tray-grid button, .timer-controls button { padding: 0.3rem 0.5rem; font-size: 0.7rem; }
            .timer-primary-controls button.btn-play, .timer-controls button.btn-play { padding: 0.3rem 0.8rem; }
        }

        /* Player management panel */
        .player-panel-overlay{position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.5);z-index:200}
        .player-panel{position:fixed;top:0;right:-320px;width:300px;max-width:85vw;height:100%;background:#1e293b;z-index:201;transition:right .25s ease;display:flex;flex-direction:column}
        .player-panel.open{right:0}
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
    </style>
</head>
<body class="timer-body">

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

<div class="timer-container">
    <!-- Payout display (tournaments only) -->
    <div class="timer-payouts" id="payoutsWrap" style="display:none">
        <div class="timer-payouts-title">Payouts</div>
        <div id="payoutsBody"></div>
    </div>

    <!-- Info bar -->
    <div class="timer-info-bar">
        <span class="timer-event-name" id="eventName"><?= htmlspecialchars($session['event_title'] ?? 'Tournament Timer') ?></span>
        <?php if ($pool && ($pool['bought_in'] ?? 0) > 0): ?>
        <span class="timer-stat" id="playerWrap">Players: <b id="playerCount"><?= (int)($pool['still_playing'] ?? 0) ?>/<?= (int)($pool['bought_in'] ?? 0) ?></b></span>
        <span class="timer-stat" id="poolWrap">Pool: <b id="poolTotal">$<?= number_format(($pool['pool_total'] ?? 0) / 100, 2) ?></b></span>
        <?php endif; ?>
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
    <div class="timer-primary-controls" id="controls" style="<?= $can_control ? '' : 'display:none' ?>">
        <button onclick="skipLevel(-1)" title="Previous Level">&#9198; Prev</button>
        <button class="btn-play" id="btnPlay" onclick="togglePlay()">&#9654; Start</button>
        <button onclick="skipLevel(1)" title="Next Level">Next &#9197;</button>
    </div>

    <!-- Tray handle -->
    <div class="timer-tray-handle" onclick="toggleTray()">
        <div class="timer-tray-grip"></div>
    </div>

    <!-- Secondary controls tray -->
    <div class="timer-tray" id="timerTray">
        <div class="timer-tray-grid">
            <span class="timer-min-group" style="<?= $can_control ? '' : 'display:none' ?>">
                <button onclick="adjustTime(-60)" title="Subtract 1 minute">&#9660;</button>
                <span class="timer-min-label">Min</span>
                <button onclick="adjustTime(60)" title="Add 1 minute">&#9650;</button>
            </span>
            <span class="timer-reset-group" style="<?= $can_control ? '' : 'display:none' ?>">
                <button onclick="resetLevel()" title="Reset current level clock">&#8635; Level</button>
                <button onclick="resetTimer()" title="Reset entire timer to level 1" style="color:#ef4444">&#8635; Timer</button>
            </span>
            <button id="btnSound" onclick="toggleSound()">&#128276; Sound: On</button>
            <button id="btnFullscreen" onclick="goFullscreen()">&#9974; Fullscreen</button>
            <?php if (!$is_remote): ?>
            <button onclick="openLevels()">&#128203; Levels</button>
            <?php if (!$is_guest): ?>
            <button onclick="openSoundSettings()">&#9881; Sounds</button>
            <?php endif; ?>
            <?php endif; ?>
            <?php if ($can_control && $event && $session): ?>
            <button onclick="togglePlayerPanel()">&#128101; Players</button>
            <?php endif; ?>
        </div>
    </div>
</div>

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

<?php if (!$is_remote): ?>
<!-- Levels editor overlay -->
<div class="timer-levels-overlay" id="levelsOverlay" onclick="if(event.target===this)closeLevels()">
    <div class="timer-levels-panel" style="position:relative">
        <button onclick="closeLevels()" style="position:absolute;top:0.75rem;right:0.75rem;background:none;border:none;color:#94a3b8;font-size:1.5rem;cursor:pointer;line-height:1;padding:0.25rem">&times;</button>
        <h3>Blind Structure</h3>
        <?php if (!$is_guest): ?>
        <div class="timer-preset-bar">
            <select id="presetSelect" onchange="updatePresetButtons()"><option value="">Loading...</option></select>
            <button onclick="loadPreset()">Load</button>
            <button onclick="savePresetAs()">Save As...</button>
            <button id="btnDeletePreset" onclick="deletePreset()">Delete</button>
            <button id="btnSetDefault" onclick="setAsDefault()" style="display:none">Set Default</button>
            <button onclick="exportLevels()">Export</button>
            <button onclick="document.getElementById('importFile').click()">Import</button>
            <input type="file" id="importFile" accept=".json" style="display:none" onchange="importLevels(this)">
        </div>
        <?php else: ?>
        <div class="timer-preset-bar" style="justify-content:center">
            <span style="color:#94a3b8;font-size:.8rem"><a href="/register.php" style="color:#60a5fa">Create an account</a> to save presets, export/import blinds</span>
        </div>
        <?php endif; ?>
        <table class="timer-levels-table">
            <thead><tr><th style="width:3rem">#</th><th>SB</th><th>BB</th><th>Ante</th><th>Min</th><th>Type</th><th></th></tr></thead>
            <tbody id="levelsBody"></tbody>
        </table>
        <div class="timer-level-btns">
            <button onclick="addLevel(false)">+ Add Level</button>
            <button onclick="addLevel(true)">+ Add Break</button>
            <button class="btn-save" onclick="saveLevels()">Save Changes</button>
            <button class="btn-close-panel" onclick="closeLevels()">Close</button>
        </div>
    </div>
</div>
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
            <label style="display:block;margin-bottom:0.4rem;color:#94a3b8;font-size:0.85rem">End/Start Level Sound (custom replaces both)</label>
            <div style="display:flex;gap:0.5rem;align-items:center;flex-wrap:wrap">
                <select id="alarmSoundSelect" style="background:#0f172a;color:#e2e8f0;border:1px solid #334155;border-radius:6px;padding:0.4rem 0.6rem;font-size:0.9rem;flex:1">
                    <option value="">Default (3 beeps + long tone)</option>
                </select>
                <button onclick="previewSound('end')" style="background:#334155;color:#e2e8f0;border:1px solid #475569;border-radius:6px;padding:0.4rem 0.8rem;cursor:pointer;font-size:0.85rem">&#9654; End</button>
                <button onclick="previewSound('start')" style="background:#334155;color:#e2e8f0;border:1px solid #475569;border-radius:6px;padding:0.4rem 0.8rem;cursor:pointer;font-size:0.85rem">&#9654; Start</button>
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
            <label style="display:block;margin-bottom:0.4rem;color:#94a3b8;font-size:0.85rem">Warning Sound</label>
            <div style="display:flex;gap:0.5rem;align-items:center;flex-wrap:wrap">
                <select id="warningSoundSelect" style="background:#0f172a;color:#e2e8f0;border:1px solid #334155;border-radius:6px;padding:0.4rem 0.6rem;font-size:0.9rem;flex:1">
                    <option value="">Default Beep</option>
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
// ─── Config from PHP ──────────────────────────────────────
var IS_REMOTE = <?= json_encode($is_remote) ?>;
var IS_GUEST = <?= json_encode($is_guest) ?>;
var IS_ADMIN = <?= json_encode($isAdmin) ?>;
var CAN_CONTROL = <?= json_encode($can_control) ?>;
var SESSION_ID = <?= json_encode($session ? (int)$session['id'] : null) ?>;
var REMOTE_KEY = <?= json_encode($remote_key) ?>;
var CSRF = <?= json_encode($csrf) ?>;
var POLL_INTERVAL = 2000; // everyone polls server every 2s

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
    warning_sound: <?= json_encode($timer['warning_sound'] ?? null) ?>
};
var warningFired = false;
var endTimerFired = false;

// ─── Formatting helpers ───────────────────────────────────
function fmtTime(secs) {
    secs = Math.max(0, Math.floor(secs));
    var m = String(Math.floor(secs / 60)).padStart(2, '0');
    var s = String(secs % 60).padStart(2, '0');
    return m + ':' + s;
}
function fmtMoney(cents) {
    return '$' + (cents / 100).toFixed(2);
}
function fmtChips(n) {
    if (n >= 1000000) return (n / 1000000).toFixed(1).replace(/\.0$/, '') + 'M';
    if (n >= 1000) return (n / 1000).toFixed(0) + 'K';
    return String(n);
}

// ─── Get current level data ──────────────────────────────
function getLevelData(num) {
    for (var i = 0; i < LEVELS.length; i++) {
        if (parseInt(LEVELS[i].level_number) === num) return LEVELS[i];
    }
    return null;
}

// ─── Render ───────────────────────────────────────────────
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
            el('blinds').textContent = fmtChips(parseInt(lv.small_blind)) + ' / ' + fmtChips(parseInt(lv.big_blind));
            el('ante').textContent = parseInt(lv.ante) > 0 ? 'Ante: ' + fmtChips(parseInt(lv.ante)) : '';
        }
    }

    // Next level preview
    var nextLv = getLevelData(TIMER.current_level + 1);
    if (nextLv) {
        if (parseInt(nextLv.is_break)) {
            el('nextLevel').textContent = 'Next: Break';
        } else {
            var txt = 'Next: ' + fmtChips(parseInt(nextLv.small_blind)) + ' / ' + fmtChips(parseInt(nextLv.big_blind));
            if (parseInt(nextLv.ante) > 0) txt += ' (Ante ' + fmtChips(parseInt(nextLv.ante)) + ')';
            el('nextLevel').textContent = txt;
        }
    } else {
        el('nextLevel').textContent = 'Final Level';
    }

    renderClock();
    renderPlayBtn();

    // Stats
    if (POOL) {
        var pc = el('playerCount'), pt = el('poolTotal');
        if (pc) pc.textContent = (POOL.still_playing || 0) + '/' + (POOL.bought_in || 0);
        if (pt) pt.textContent = fmtMoney(POOL.pool_total || 0);
        // Show/hide player and pool if players joined mid-game
        var pw = el('playerWrap'), plw = el('poolWrap');
        if (pw) pw.style.display = (POOL.bought_in > 0) ? '' : 'none';
        if (plw) plw.style.display = (POOL.bought_in > 0) ? '' : 'none';
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
            payWrap.style.display = 'none';
        }
    }

    // Paused label
    el('pausedLabel').textContent = TIMER.is_running ? '' : 'PAUSED';
}

function renderClock() {
    var el = document.getElementById('timerClock');
    var secs = Math.max(0, TIMER.time_remaining_seconds);
    el.textContent = fmtTime(secs);
    el.className = 'timer-clock';
    if (secs <= 30) el.classList.add('timer-red');
    else if (secs <= 120) el.classList.add('timer-yellow');
    else el.classList.add('timer-green');
}

function renderPlayBtn() {
    var btn = document.getElementById('btnPlay');
    if (!btn) return;
    if (TIMER.is_running) {
        btn.innerHTML = '&#9646;&#9646; Pause';
        btn.classList.add('is-running');
    } else {
        btn.innerHTML = '&#9654; Start';
        btn.classList.remove('is-running');
    }
}

// Helper: append session or key identifier to FormData
function appendTimerId(fd) {
    if (SESSION_ID) fd.append('session_id', SESSION_ID);
    else fd.append('key', REMOTE_KEY);
}

// ─── Send command to server API ───────────────────────────
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

// ─── Poll server (everyone does this — server is master) ──
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
        renderAll();
    }).catch(function() {});
}

// ─── Local tick (smooth display between polls) ────────────
function startLocalTick() {
    if (localInterval) return;
    localInterval = setInterval(function() {
        if (!TIMER.is_running) return;
        TIMER.time_remaining_seconds--;

        // Warning alert
        if (SOUNDS.warning_seconds > 0 && !warningFired && TIMER.time_remaining_seconds === SOUNDS.warning_seconds) {
            warningFired = true;
            playWarning();
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
            pollState();
        }
        renderClock();
    }, 1000);
}

// ─── Controls (all send commands to server) ───────────────
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
    if (btn) btn.innerHTML = soundEnabled ? '&#128276; Sound: On' : '&#128263; Sound: Off';
}

function goFullscreen() {
    var el = document.documentElement;
    if (el.requestFullscreen) el.requestFullscreen();
    else if (el.webkitRequestFullscreen) el.webkitRequestFullscreen();
}

// ─── Wake Lock (prevent screen sleep) ─────────────────────
var wakeBanner = document.getElementById('wakeBanner');
var wakeLock = null;
var wakeLockAcquired = false;

async function requestWakeLock() {
    if (!('wakeLock' in navigator) || wakeLockAcquired) return;
    try {
        wakeLock = await navigator.wakeLock.request('screen');
        wakeLockAcquired = true;
        // Hide banner on success
        if (wakeBanner) { wakeBanner.style.opacity = '0'; setTimeout(function() { wakeBanner.remove(); }, 600); }
        wakeLock.addEventListener('release', function() { wakeLock = null; wakeLockAcquired = false; });
    } catch(e) {}
}

// Hide banner on desktop (no need)
if (!('ontouchstart' in window) && navigator.maxTouchPoints === 0) {
    if (wakeBanner) wakeBanner.remove();
}

// Try on load
requestWakeLock();
// Acquire on user interaction (required by iOS Safari)
document.addEventListener('click', function() { requestWakeLock(); }, true);
document.addEventListener('touchend', function() { requestWakeLock(); }, true);
// Re-acquire when tab becomes visible and immediately resync timer state
document.addEventListener('visibilitychange', function() {
    if (document.visibilityState === 'visible') {
        wakeLockAcquired = false;
        requestWakeLock();
        pollState(); // resync immediately — Android may have throttled intervals while hidden
    }
});

// ─── Sound alert ──────────────────────────────────────────
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

// End Timer: 3 beeps over 3 seconds (one per second, descending pitch)
function playEndTimer() {
    if (!soundEnabled) return;
    if (SOUNDS.alarm_sound) { playCustomSound(SOUNDS.alarm_sound); return; }
    try {
        var ctx = ensureAudioCtx();
        [0, 1, 2].forEach(function(i) {
            var osc = ctx.createOscillator();
            var gain = ctx.createGain();
            osc.type = 'sine';
            osc.frequency.value = 880 - (i * 110); // 880, 770, 660
            gain.gain.value = 0.35;
            osc.connect(gain);
            gain.connect(ctx.destination);
            osc.start(ctx.currentTime + i);
            osc.stop(ctx.currentTime + i + 0.4);
        });
    } catch(e) {}
}

// Start Timer: 1 long beep (1 second, higher pitch)
function playStartTimer() {
    if (!soundEnabled) return;
    try {
        var ctx = ensureAudioCtx();
        var osc = ctx.createOscillator();
        var gain = ctx.createGain();
        osc.type = 'sine';
        osc.frequency.value = 1000;
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
    if (SOUNDS.warning_sound) { playCustomSound(SOUNDS.warning_sound); return; }
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

// ─── Sound settings ──────────────────────────────────────
function openSoundSettings() {
    var sel = document.getElementById('warningSeconds');
    if (sel) sel.value = String(SOUNDS.warning_seconds);
    // Set current selections
    setSelectValue('alarmSoundSelect', SOUNDS.alarm_sound || '');
    setSelectValue('warningSoundSelect', SOUNDS.warning_sound || '');
    document.getElementById('soundOverlay').classList.add('open');
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
    var input = document.getElementById(type === 'alarm' ? 'alarmUpload' : 'warningUpload');
    var status = document.getElementById(type === 'alarm' ? 'alarmUploadStatus' : 'warningUploadStatus');
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
                var selId = type === 'alarm' ? 'alarmSoundSelect' : 'warningSoundSelect';
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
    SOUNDS.warning_sound = document.getElementById('warningSoundSelect').value || null;

    var fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('action', 'update_sounds');
    appendTimerId(fd);
    fd.append('warning_seconds', SOUNDS.warning_seconds);
    fd.append('alarm_sound', SOUNDS.alarm_sound || '');
    fd.append('warning_sound', SOUNDS.warning_sound || '');
    fetch('/timer_dl.php', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(j) {
            if (j.ok) closeSoundSettings();
            else alert(j.error || 'Error saving');
        });
}

function previewSound(type) {
    if (type === 'end') {
        var url = document.getElementById('alarmSoundSelect').value;
        if (url) playCustomSound(url); else playEndTimer();
    } else if (type === 'start') {
        var url = document.getElementById('alarmSoundSelect').value;
        if (url) playCustomSound(url); else playStartTimer();
    } else {
        var url = document.getElementById('warningSoundSelect').value;
        if (url) playCustomSound(url); else playWarning();
    }
}

// ─── Levels editor ────────────────────────────────────────
function openLevels() {
    loadPresetList();
    levelsCollected = true; // skip collecting from stale/empty DOM
    renderLevelsTable();
    document.getElementById('levelsOverlay').classList.add('open');
}
function closeLevels() {
    document.getElementById('levelsOverlay').classList.remove('open');
    document.getElementById('levelsBody').innerHTML = ''; // clear stale inputs
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
        h += '<td><input type="number" value="' + (brk ? 0 : lv.small_blind) + '" data-idx="' + i + '" data-field="small_blind"' + (brk ? ' disabled' : '') + '></td>';
        h += '<td><input type="number" value="' + (brk ? 0 : lv.big_blind) + '" data-idx="' + i + '" data-field="big_blind"' + (brk ? ' disabled' : '') + '></td>';
        h += '<td><input type="number" value="' + (brk ? 0 : lv.ante) + '" data-idx="' + i + '" data-field="ante"' + (brk ? ' disabled' : '') + '></td>';
        h += '<td><input type="number" value="' + lv.duration_minutes + '" data-idx="' + i + '" data-field="duration_minutes" style="width:55px"></td>';
        h += '<td>' + (brk ? 'BREAK' : 'Play') + '</td>';
        h += '<td class="lvl-actions">';
        h += '<button onclick="insertLevel(' + i + ', false)" title="Insert level here" style="color:#22c55e;font-size:0.9rem">+</button>';
        h += '<button onclick="insertLevel(' + i + ', true)" title="Insert break here" style="color:#fbbf24;font-size:0.9rem">&#9202;</button>';
        h += '<button onclick="removeLevel(' + i + ')" title="Remove">&times;</button>';
        h += '</td>';
        h += '</tr>';
    }
    tb.innerHTML = h;
}

// ─── Drag and drop reorder ───────────────────────────────
function onDragStart(e) {
    var row = e.currentTarget.closest('tr');
    dragSrcIdx = parseInt(row.dataset.idx);
    row.style.opacity = '0.4';
    e.dataTransfer.effectAllowed = 'move';
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
    renderLevelsTable();
    dragSrcIdx = null;
}
function onDragEnd() {
    dragSrcIdx = null;
    var rows = document.querySelectorAll('#levelsBody tr');
    rows.forEach(function(r) { r.style.opacity = ''; r.style.borderTop = ''; r.style.borderBottom = ''; });
}

// ─── Insert level at position ────────────────────────────
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
    renderLevelsTable();
}

function removeLevel(idx) {
    collectLevelsFromTable(); levelsCollected = true;
    LEVELS.splice(idx, 1);
    renumberLevels();
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
                renderAll();
                var btn = document.querySelector('.timer-level-btns .btn-save');
                if (btn) {
                    var label = j.created_copy ? 'Saved as personal copy!' : 'Saved!';
                    btn.textContent = label;
                    btn.style.background = '#16a34a';
                    setTimeout(function() { btn.textContent = 'Save Changes'; btn.style.background = ''; }, 2500);
                }
            } else {
                alert(j.error || 'Error saving levels');
            }
        });
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
            // Split presets into groups: default, global, personal
            var defaults = [], globals = [], personal = [];
            j.presets.forEach(function(p) {
                p._isDefault = parseInt(p.is_default);
                p._isGlobal  = parseInt(p.is_global);
                if (p._isDefault) defaults.push(p);
                else if (p._isGlobal) globals.push(p);
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
                    opt.dataset.createdBy = p.created_by;
                    grp.appendChild(opt);
                });
                sel.appendChild(grp);
            }
            addGroup('Default', defaults);
            addGroup('Global Presets', globals);
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

function savePresetAs() {
    var name = prompt('Preset name:');
    if (!name) return;
    var is_global = 0;
    if (IS_ADMIN) {
        is_global = confirm('Save as a Global preset (visible to all users)?\n\nOK = Global\nCancel = Personal (only you)') ? 1 : 0;
    }
    collectLevelsFromTable();
    for (var i = 0; i < LEVELS.length; i++) LEVELS[i].level_number = i + 1;
    var fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('action', 'save_preset');
    fd.append('name', name);
    fd.append('is_global', is_global);
    fd.append('levels', JSON.stringify(LEVELS));
    fetch('/timer_dl.php', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(j) {
            if (j.ok) {
                alert('Preset saved' + (is_global ? ' (global)!' : '!'));
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

// ─── Init ─────────────────────────────────────────────────
renderAll();
startLocalTick(); // smooth second-by-second display between polls
setInterval(pollState, POLL_INTERVAL); // everyone polls server — server is master

// Open tray by default on desktop
if (window.innerWidth > 768) {
    var tray = document.getElementById('timerTray');
    if (tray) tray.classList.add('open');
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
        var modules = qr.getModuleCount();
        var canvas = document.createElement('canvas');
        canvas.width = size;
        canvas.height = size;
        var ctx = canvas.getContext('2d');
        var cellSize = size / modules;
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, size, size);
        ctx.fillStyle = '#000000';
        for (var r = 0; r < modules; r++) {
            for (var c = 0; c < modules; c++) {
                if (qr.isDark(r, c)) {
                    ctx.fillRect(c * cellSize, r * cellSize, cellSize + 0.5, cellSize + 0.5);
                }
            }
        }
        qrWrap.appendChild(canvas);

        qrWrap.style.cursor = 'pointer';
        qrWrap.addEventListener('click', function() {
            navigator.clipboard.writeText(remoteUrl).then(function() {
                qrWrap.title = 'Link copied!';
                setTimeout(function() { qrWrap.title = 'Scan to view timer on your phone'; }, 2000);
            });
        });
    }
}

// ─── Player Panel ────────────────────────────────────────────
var PP_PLAYERS = [];
var PP_SESSION = null;
var PP_OPEN = false;

function togglePlayerPanel() {
    var panel = document.getElementById('playerPanel');
    var overlay = document.getElementById('playerPanelOverlay');
    if (!panel) return;
    PP_OPEN = !PP_OPEN;
    panel.classList.toggle('open', PP_OPEN);
    if (overlay) overlay.style.display = PP_OPEN ? '' : 'none';
    if (PP_OPEN) fetchPlayers();
}

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
                        h += '<div class="pp-counter"><button onclick="ppRebuy(' + p.id + ',-1)">-</button><span>' + (p.rebuys||0) + '</span><button onclick="ppRebuy(' + p.id + ',1)">+</button></div>';
                    }
                    if (PP_SESSION && parseInt(PP_SESSION.addon_allowed)) {
                        h += '<div class="pp-counter"><button onclick="ppAddon(' + p.id + ',-1)">-</button><span>' + (p.addons||0) + '</span><button onclick="ppAddon(' + p.id + ',1)">+</button></div>';
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
function ppAddon(pid, d) { ppPost('update_addons', { player_id: pid, delta: d }); }
function ppEliminate(pid) {
    var playing = PP_PLAYERS.filter(function(p) { return !parseInt(p.eliminated) && parseInt(p.bought_in); }).length;
    var pos = prompt('Finish position? (suggested: ' + playing + ')', playing);
    if (pos === null) return;
    ppPost('eliminate_player', { player_id: pid, finish_position: parseInt(pos) || playing });
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

// ─── Export/Import Blind Structures ──────────────────────────
function exportLevels() {
    collectLevelsFromTable();
    var presetName = document.getElementById('presetSelect');
    var name = presetName ? (presetName.options[presetName.selectedIndex]?.text || 'custom') : 'custom';
    var data = { name: name, levels: LEVELS };
    var blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
    var a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'blinds_' + name.replace(/[^a-zA-Z0-9]/g, '_') + '.json';
    a.click();
    URL.revokeObjectURL(a.href);
}

function importLevels(input) {
    var file = input.files[0];
    if (!file) return;
    var reader = new FileReader();
    reader.onload = function(e) {
        try {
            var data = JSON.parse(e.target.result);
            if (!data.levels || !Array.isArray(data.levels) || data.levels.length === 0) {
                alert('Invalid file: no levels found.');
                return;
            }
            LEVELS = data.levels.map(function(l, i) {
                return {
                    level_number: i + 1,
                    small_blind: parseInt(l.small_blind) || 0,
                    big_blind: parseInt(l.big_blind) || 0,
                    ante: parseInt(l.ante) || 0,
                    duration_minutes: parseInt(l.duration_minutes) || 15,
                    is_break: parseInt(l.is_break) || 0
                };
            });
            levelsCollected = true;
            renderLevelsTable();
            alert('Imported ' + LEVELS.length + ' levels from "' + (data.name || 'file') + '". Click Save Changes to apply.');
        } catch (ex) {
            alert('Invalid JSON file.');
        }
    };
    reader.readAsText(file);
    input.value = '';
}
</script>
</body>
</html>
