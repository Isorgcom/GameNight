<?php
/**
 * Send to TV, the TV side. Open once in a TV's browser and bookmark it; it
 * shows a short code and a QR code, and switches to a game's timer as soon
 * as a host sends one from tv_send.php. No login: the page holds no rights,
 * and what it is eventually sent is the view-only display a QR scan opens.
 * See _tv_pair.php for why the poll uses a secret and not the code.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_tv_pair.php';

$db = get_db();
$site_name = get_setting('site_name', 'Game Night');
$pair = tv_pair_new($db);
$base = rtrim(get_site_url(), '/');
$send_url = $base . '/tv/send?c=' . $pair['code'];
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
        .tv-text { max-width: 46vw; }
        .tv-site { font-size: clamp(1rem, 2.4vw, 2.2rem); font-weight: 800; color: #94a3b8; margin: 0 0 1.5vh; }
        .tv-head { font-size: clamp(1.4rem, 4vw, 3.6rem); font-weight: 800; margin: 0 0 3vh; line-height: 1.15; }
        .tv-code { font-size: clamp(3rem, 13vw, 12rem); font-weight: 800; letter-spacing: .12em;
                   font-variant-numeric: tabular-nums; color: #fbbf24; margin: 0 0 3vh; line-height: 1; }
        .tv-how { font-size: clamp(1rem, 2.2vw, 2rem); color: #cbd5e1; margin: 0 0 1.2vh; line-height: 1.35; }
        .tv-how b { color: #fff; }
        .tv-small { font-size: clamp(.8rem, 1.4vw, 1.3rem); color: #94a3b8; margin-top: 3vh; }
        .tv-qr { background: #fff; border-radius: 16px; padding: 2vh; line-height: 0; }
        .tv-qr img { width: min(34vw, 62vh); height: auto; image-rendering: pixelated; }
        @media (max-aspect-ratio: 1/1) {
            .tv { flex-direction: column; text-align: center; }
            .tv-text { max-width: none; }
            .tv-qr img { width: min(60vw, 40vh); }
        }
    </style>
</head>
<body>
<main class="tv">
    <div class="tv-text">
        <p class="tv-site"><?= htmlspecialchars($site_name, ENT_QUOTES | ENT_SUBSTITUTE) ?></p>
        <h1 class="tv-head">Show a game's timer on this TV</h1>
        <div class="tv-code" id="tvCode" aria-label="Code"><?= htmlspecialchars($pair['code'], ENT_QUOTES | ENT_SUBSTITUTE) ?></div>
        <p class="tv-how"><b>Scan the code</b> with the phone you run the game from, and pick the game.</p>
        <p class="tv-how">Or open <b><?= htmlspecialchars($send_label, ENT_QUOTES | ENT_SUBSTITUTE) ?></b> and enter the code.</p>
        <p class="tv-small">Bookmark this page on the TV. Next game, open it and do the same. The code changes every <?= (int)TV_CODE_MINUTES ?> minutes.</p>
    </div>
    <div class="tv-qr"><img id="tvQr" alt="QR code that opens the page for sending a game to this TV"></div>
</main>
<script src="/vendor/qrcode.min.js"></script>
<script nonce="<?= csp_nonce() ?>">
(function () {
    var SECRET = <?= json_encode($pair['secret']) ?>;
    var SEND_URL = <?= json_encode($send_url) ?>;
    try {
        var qr = qrcode(0, 'M');
        qr.addData(SEND_URL);
        qr.make();
        document.getElementById('tvQr').src = qr.createDataURL(8, 2);
    } catch (e) {}

    function poll() {
        fetch('/tv_dl.php?action=poll&s=' + SECRET, { cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                // Only a same-origin path is ever followed.
                if (j && typeof j.url === 'string' && j.url.charAt(0) === '/' && j.url.charAt(1) !== '/') {
                    location.replace(j.url);
                    return;
                }
                if (j && j.expired) { location.reload(); return; }
                setTimeout(poll, 2500);
            })
            .catch(function () { setTimeout(poll, 5000); });   // network blip: keep waiting
    }
    setTimeout(poll, 2500);
})();
</script>
</body>
</html>
