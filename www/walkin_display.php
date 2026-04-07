<?php
/**
 * Standalone walk-up QR code display page.
 * Designed for an iPad/tablet at a registration table.
 * URL: /walkin_display.php?event_id=X
 * Requires login + event access (admin, creator, or manager).
 */
require_once __DIR__ . '/auth.php';

$current = require_login();
$db = get_db();
$site_name = get_setting('site_name', 'Game Night');
$isAdmin = $current['role'] === 'admin';

$event_id = (int)($_GET['event_id'] ?? 0);
if (!$event_id) {
    header('Location: /calendar.php');
    exit;
}

// Verify access (admin, creator, or manager)
$ev = $db->prepare('SELECT * FROM events WHERE id = ?');
$ev->execute([$event_id]);
$event = $ev->fetch();
if (!$event) {
    header('Location: /calendar.php');
    exit;
}

if (!$isAdmin && (int)$event['created_by'] !== (int)$current['id']) {
    $mgr = $db->prepare("SELECT 1 FROM event_invites WHERE event_id=? AND LOWER(username)=LOWER(?) AND event_role='manager' LIMIT 1");
    $mgr->execute([$event_id, $current['username']]);
    if (!$mgr->fetch()) {
        header('Location: /calendar.php');
        exit;
    }
}

// Generate walkin_token if not set
$walkin_token = $event['walkin_token'] ?? '';
if ($walkin_token === '') {
    $walkin_token = bin2hex(random_bytes(16));
    $db->prepare('UPDATE events SET walkin_token = ? WHERE id = ?')->execute([$walkin_token, $event_id]);
}

$walkin_url = get_site_url() . '/walkin.php?event_id=' . $event_id . '&token=' . $walkin_token;
$csrf = csrf_token();

// Format event date/time
$display_date = $event['start_date'];
if (!empty($event['start_time'])) {
    $t = DateTime::createFromFormat('H:i', $event['start_time']);
    if ($t) $display_date .= '  ·  ' . $t->format('g:i A');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Registration QR &mdash; <?= htmlspecialchars($event['title']) ?></title>
    <link rel="icon" href="/favicon.php">
    <link rel="stylesheet" href="/style.css">
    <style>
        html { height: 100%; }
        body {
            background: #0f172a;
            color: #e2e8f0;
            margin: 0;
            height: 100dvh;
            height: 100vh;
            display: flex;
            flex-direction: column;
            font-family: system-ui, -apple-system, sans-serif;
            overflow: hidden;
        }
        @supports (height: 100dvh) { body { height: 100dvh; } }
        body nav, body .nav-top, body .nav-links, body footer { display: none; }

        .qr-container {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            min-height: 0;
        }
        .qr-event-name {
            font-size: clamp(1.5rem, 4vw, 3rem);
            font-weight: 800;
            color: #fff;
            text-align: center;
            margin-bottom: 0.25rem;
        }
        .qr-event-date {
            font-size: clamp(0.9rem, 2vw, 1.3rem);
            color: #94a3b8;
            text-align: center;
            margin-bottom: 1.5rem;
        }
        .qr-code-wrap {
            background: #fff;
            padding: 16px;
            border-radius: 16px;
            display: inline-block;
            box-shadow: 0 8px 32px rgba(0,0,0,0.3);
        }
        .qr-code-wrap canvas { display: block; }
        .qr-instruction {
            font-size: clamp(1.2rem, 3vw, 2rem);
            color: #94a3b8;
            text-align: center;
            margin-top: 1.5rem;
            font-weight: 500;
        }
        .qr-url {
            font-size: clamp(0.6rem, 1.2vw, 0.8rem);
            color: #475569;
            text-align: center;
            margin-top: 0.75rem;
            word-break: break-all;
            max-width: 500px;
        }
        .qr-actions {
            display: flex;
            gap: 0.5rem;
            margin-top: 1rem;
            flex-wrap: wrap;
            justify-content: center;
        }
        .qr-actions button {
            background: #1e293b;
            color: #e2e8f0;
            border: 1px solid #334155;
            border-radius: 8px;
            padding: 0.5rem 1rem;
            font-size: 0.85rem;
            cursor: pointer;
            transition: background 0.15s;
        }
        .qr-actions button:hover { background: #334155; }
        .qr-back {
            position: absolute;
            top: 1rem;
            left: 1rem;
            color: #64748b;
            text-decoration: none;
            font-size: 0.95rem;
            z-index: 10;
        }
        .qr-back:hover { color: #e2e8f0; }
    </style>
</head>
<body>

<a class="qr-back" href="/calendar.php">&larr; Back</a>

<div class="qr-container">
    <div class="qr-event-name"><?= htmlspecialchars($event['title']) ?></div>
    <div class="qr-event-date"><?= htmlspecialchars($display_date) ?></div>

    <div class="qr-code-wrap" id="qrWrap"></div>

    <div class="qr-instruction">Scan to register for this event</div>
    <div class="qr-url" id="qrUrl"><?= htmlspecialchars($walkin_url) ?></div>

    <div class="qr-actions">
        <button onclick="copyLink()" id="copyBtn">Copy Link</button>
        <button onclick="regenToken()">Regenerate QR</button>
        <button onclick="goFullscreen()">Fullscreen</button>
    </div>
</div>

<script src="/vendor/qrcode.min.js"></script>
<script>
var EVENT_ID = <?= $event_id ?>;
var WALKIN_URL = <?= json_encode($walkin_url) ?>;
var CSRF = <?= json_encode($csrf) ?>;

function renderQR() {
    var wrap = document.getElementById('qrWrap');
    wrap.innerHTML = '';
    var qr = qrcode(0, 'M');
    qr.addData(WALKIN_URL);
    qr.make();
    var size = Math.min(window.innerWidth * 0.6, window.innerHeight * 0.4, 400);
    size = Math.max(200, Math.floor(size));
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
    wrap.appendChild(canvas);
}

function copyLink() {
    navigator.clipboard.writeText(WALKIN_URL).then(function() {
        var btn = document.getElementById('copyBtn');
        btn.textContent = 'Copied!';
        setTimeout(function() { btn.textContent = 'Copy Link'; }, 2000);
    });
}

function regenToken() {
    if (!confirm('Regenerate QR code? The old link will stop working.')) return;
    var fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('action', 'regenerate_walkin_token');
    fd.append('event_id', EVENT_ID);
    fetch('/calendar_dl.php', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(j) {
            if (j.ok && j.url) {
                WALKIN_URL = j.url;
                document.getElementById('qrUrl').textContent = WALKIN_URL;
                renderQR();
            }
        });
}

function goFullscreen() {
    var el = document.documentElement;
    if (el.requestFullscreen) el.requestFullscreen();
    else if (el.webkitRequestFullscreen) el.webkitRequestFullscreen();
}

// Wake Lock
var wakeLock = null;
async function requestWakeLock() {
    if (!('wakeLock' in navigator)) return;
    try {
        wakeLock = await navigator.wakeLock.request('screen');
        wakeLock.addEventListener('release', function() { wakeLock = null; });
    } catch(e) {}
}
document.addEventListener('click', function() { requestWakeLock(); }, true);
document.addEventListener('touchend', function() { requestWakeLock(); }, true);
document.addEventListener('visibilitychange', function() {
    if (document.visibilityState === 'visible') requestWakeLock();
});

// Resize QR on window resize
window.addEventListener('resize', renderQR);
renderQR();
</script>
</body>
</html>
