<?php
/**
 * Send to TV, the TV side. Open once in a TV's browser and bookmark it; it
 * shows a short code and a QR code, and switches to a game's timer as soon
 * as a host sends one from tv_send.php. No login: the page holds no rights,
 * and what it is eventually sent is the view-only display a QR scan opens.
 * See _tv_pair.php for why the poll uses a secret and not the code.
 *
 * The timer loads INSIDE this page rather than replacing it. A browser only
 * goes fullscreen on a press, never on a page load or from a timer callback,
 * so the one press that can happen is OK on the remote while the code is
 * showing; a navigation would then throw that away the moment the game
 * arrived. Framed, the screen stays full. For the same reason an expired code
 * is renewed in place (tv_dl.php?action=new), never by a reload.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_tv_pair.php';

$db = get_db();
$site_name = get_setting('site_name', 'Game Night');
// Past this address's hourly mint cap (see _tv_pair.php) the page shows a
// wait notice in place of a code and asks again every minute.
$pair = tv_pair_mint_limited($db) ? null : tv_pair_new($db);
$base = rtrim(get_site_url(), '/');
$send_base = $base . '/tv/send?c=';
$send_label = preg_replace('#^https?://#', '', $base) . '/tv/send';
header('Cache-Control: no-store');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title>TV &mdash; <?= htmlspecialchars($site_name, ENT_QUOTES | ENT_SUBSTITUTE) ?></title>
    <style>
        html, body { margin: 0; height: 100%; background: #0f172a; color: #e2e8f0;
                     font-family: system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; overflow: hidden; }
        .tv { height: 100%; display: flex; align-items: center; justify-content: center;
              gap: 6vw; padding: 4vh 5vw; box-sizing: border-box; }
        .tv[hidden] { display: none; }
        .tv-text { max-width: 46vw; }
        .tv-site { font-size: clamp(1rem, 2.4vw, 2.2rem); font-weight: 800; color: #94a3b8; margin: 0 0 1.5vh; }
        .tv-head { font-size: clamp(1.4rem, 4vw, 3.6rem); font-weight: 800; margin: 0 0 2vh; line-height: 1.15; }
        .tv-code { font-size: clamp(3rem, 11.5vw, 11rem); font-weight: 800; letter-spacing: .12em;
                   font-variant-numeric: tabular-nums; color: #fbbf24; margin: 0 0 2vh; line-height: 1; }
        .tv-how { font-size: clamp(1rem, 2vw, 1.9rem); color: #cbd5e1; margin: 0 0 1.2vh; line-height: 1.35; }
        .tv-how b { color: #fff; }
        .tv-fs { color: #e2e8f0; margin-top: 1.6vh; }
        .tv-fs[hidden] { display: none; }
        .tv-small { font-size: clamp(.8rem, 1.4vw, 1.3rem); color: #94a3b8; margin-top: 2vh; }
        .tv-qr { background: #fff; border-radius: 16px; padding: 2vh; line-height: 0; }
        .tv-qr img { width: min(34vw, 62vh); height: auto; image-rendering: pixelated; }
        #tvFrame { position: fixed; inset: 0; width: 100%; height: 100%; border: 0; background: #000; }
        #tvFrame[hidden] { display: none; }
        @media (max-aspect-ratio: 1/1) {
            .tv { flex-direction: column; text-align: center; }
            .tv-text { max-width: none; }
            .tv-qr img { width: min(60vw, 40vh); }
        }
    </style>
</head>
<body>
<main class="tv" id="tvMain">
    <div class="tv-text">
        <p class="tv-site"><?= htmlspecialchars($site_name, ENT_QUOTES | ENT_SUBSTITUTE) ?></p>
        <h1 class="tv-head">Show a game's timer on this TV</h1>
        <div class="tv-code" id="tvCode" aria-label="Code"><?= $pair ? htmlspecialchars($pair['code'], ENT_QUOTES | ENT_SUBSTITUTE) : '' ?></div>
        <p class="tv-how" id="tvWait" <?= $pair ? 'hidden' : '' ?>><b>Too many codes were asked for from this address.</b> The page will try again shortly.</p>
        <p class="tv-how"><b>Scan the code</b> with the phone you run the game from, and pick the game.</p>
        <p class="tv-how">Or open <b><?= htmlspecialchars($send_label, ENT_QUOTES | ENT_SUBSTITUTE) ?></b> and enter the code.</p>
        <p class="tv-how tv-fs" id="tvFs" hidden>&#9974; <b>Press OK</b> on the remote to fill the screen.</p>
        <p class="tv-small">Bookmark this page on the TV. Next game, open it and do the same. The code changes every <?= (int)TV_CODE_MINUTES ?> minutes.</p>
    </div>
    <div class="tv-qr"><img id="tvQr" alt="QR code that opens the page for sending a game to this TV"></div>
</main>
<iframe id="tvFrame" hidden allow="autoplay; fullscreen; screen-wake-lock" title="Tournament Timer"></iframe>
<script src="/vendor/qrcode.min.js"></script>
<script nonce="<?= csp_nonce() ?>">
(function () {
    var SECRET = <?= js_json($pair ? $pair['secret'] : '') ?>;
    var SEND_BASE = <?= js_json($send_base) ?>;
    var root = document.documentElement;
    var main = document.getElementById('tvMain');
    var codeEl = document.getElementById('tvCode');
    var qrEl = document.getElementById('tvQr');
    var frame = document.getElementById('tvFrame');
    var fsLine = document.getElementById('tvFs');
    var waitEl = document.getElementById('tvWait');

    function drawQr(code) {
        try {
            var qr = qrcode(0, 'M');
            qr.addData(SEND_BASE + code);
            qr.make();
            qrEl.src = qr.createDataURL(8, 2);
        } catch (e) {}
    }
    if (codeEl.textContent.trim()) drawQr(codeEl.textContent.trim());

    // ── Full screen: one press of OK, while the code is up ───────────────
    // Offered only where the API exists (a TV browser without it has its own
    // menu for this) and only enters, never leaves: a second press is
    // harmless, and the remote's Back key is how a TV browser leaves.
    var canFs = !!(root.requestFullscreen || root.webkitRequestFullscreen);
    function isFs() { return !!(document.fullscreenElement || document.webkitFullscreenElement); }
    function syncFsLine() { fsLine.hidden = !canFs || isFs(); }
    function goFullscreen() {
        if (!canFs || isFs()) return;
        try {
            var p = (root.requestFullscreen || root.webkitRequestFullscreen).call(root);
            if (p && typeof p.catch === 'function') p.catch(function () {});
        } catch (e) {}
    }
    document.addEventListener('fullscreenchange', syncFsLine);
    document.addEventListener('webkitfullscreenchange', syncFsLine);
    syncFsLine();
    document.addEventListener('keydown', function (e) {
        // OK on a remote arrives as Enter (some as Select); Space for a keyboard.
        if (e.key === 'Enter' || e.key === 'Select' || e.key === ' ' || e.keyCode === 13 || e.keyCode === 32) {
            goFullscreen(); keepAwake();
        }
    });
    document.addEventListener('click', function () { goFullscreen(); keepAwake(); });

    // The TV should not doze off while the code waits; the timer holds its own
    // lock once it is up. Best effort: not every TV browser has the API.
    function keepAwake() {
        try { if (navigator.wakeLock) navigator.wakeLock.request('screen').catch(function () {}); } catch (e) {}
    }
    keepAwake();
    document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'visible') keepAwake(); });

    // ── The game arrives ─────────────────────────────────────────────────
    function show(url) {
        frame.src = url;
        frame.hidden = false;
        main.hidden = true;
        // Remote keys go to the timer from here on (it wakes its buttons on any key).
        try { frame.focus(); } catch (e) {}
    }

    // A fresh code, in place: a reload would end full screen.
    function renew() {
        fetch('/tv_dl.php?action=new', { cache: 'no-store' })
            .then(function (r) {
                // Over this address's hourly cap: say so, ask again in a minute.
                if (r.status === 429) { waitEl.hidden = false; throw new Error('limited'); }
                return r.json();
            })
            .then(function (j) {
                if (!j || typeof j.code !== 'string' || typeof j.secret !== 'string') throw new Error('bad');
                SECRET = j.secret;
                codeEl.textContent = j.code;
                waitEl.hidden = true;
                drawQr(j.code);
                setTimeout(poll, 2500);
            })
            .catch(function (e) { setTimeout(renew, e && e.message === 'limited' ? 60000 : 5000); });   // keep the old code up and try again
    }

    function poll() {
        fetch('/tv_dl.php?action=poll&s=' + SECRET, { cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                // Only a same-origin path is ever followed.
                if (j && typeof j.url === 'string' && j.url.charAt(0) === '/' && j.url.charAt(1) !== '/') {
                    show(j.url);
                    return;
                }
                if (j && j.expired) { renew(); return; }
                setTimeout(poll, 2500);
            })
            .catch(function () { setTimeout(poll, 5000); });   // network blip: keep waiting
    }
    // No code yet (the page loaded over the cap): try for one in a minute.
    if (SECRET) setTimeout(poll, 2500); else setTimeout(renew, 60000);
})();
</script>
</body>
</html>
