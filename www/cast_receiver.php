<?php
/**
 * Google Cast receiver: the page a Chromecast loads when someone presses the
 * Cast button on the Tournament Timer.
 *
 * Registered in the Google Cast SDK Developer Console as a Custom Receiver
 * whose URL is https://<this site>/cast_receiver.php; the app id it is given
 * goes in site_settings.cast_app_id, which is what makes timer.php show the
 * button at all.
 *
 * It renders nothing of its own. The sender passes the game's remote_key over
 * the custom namespace below and this page frames /timer.php?key=…&cast=1,
 * the same view-only display a QR scan opens. The key buys viewing, never
 * control (see timer.php's key mode), so the receiver needs no login and holds
 * no rights. A frame rather than a navigation because the receiver context
 * must stay alive in THIS document: navigating away ends the Cast session.
 */
require_once __DIR__ . '/auth.php';
csp_allow_cast_sdk(true);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>GameNight Timer</title>
    <style>
        html, body { margin: 0; height: 100%; background: #0f172a; overflow: hidden; }
        #idle { position: fixed; inset: 0; display: flex; flex-direction: column; align-items: center;
                justify-content: center; color: #e2e8f0; font-family: system-ui, sans-serif; }
        #idle h1 { font-size: 3.5rem; margin: 0 0 .75rem; font-weight: 800; }
        #idle p { font-size: 1.6rem; margin: 0; color: #94a3b8; }
        #tv { position: fixed; inset: 0; width: 100%; height: 100%; border: 0; display: none; background: #000; }
    </style>
    <script src="https://www.gstatic.com/cast/sdk/libs/caf_receiver/v3/cast_receiver_framework.js"></script>
</head>
<body>
<div id="idle">
    <h1><?= htmlspecialchars(get_setting('site_name', 'Game Night'), ENT_QUOTES | ENT_SUBSTITUTE) ?></h1>
    <p id="idleMsg">Connecting to the game&hellip;</p>
</div>
<iframe id="tv" allow="autoplay; fullscreen" title="Tournament Timer"></iframe>
<script nonce="<?= csp_nonce() ?>">
(function () {
    var NS = 'urn:x-cast:poker.gamenight.timer';
    var frame = document.getElementById('tv');
    var idle = document.getElementById('idle');
    var shown = null;

    function show(key) {
        if (key === shown) return;
        shown = key;
        frame.src = '/timer.php?key=' + encodeURIComponent(key) + '&cast=1';
        frame.style.display = 'block';
        idle.style.display = 'none';
    }

    var ctx = cast.framework.CastReceiverContext.getInstance();
    ctx.addCustomMessageListener(NS, function (ev) {
        var d = ev.data;
        if (typeof d === 'string') { try { d = JSON.parse(d); } catch (e) { return; } }
        // Keys are bin2hex(random_bytes(8)); anything else is not ours.
        if (d && typeof d.key === 'string' && /^[0-9a-f]{16}$/.test(d.key)) show(d.key);
    });

    var opts = new cast.framework.CastReceiverOptions();
    // A timer runs for hours with no media playing; without this the device
    // decides the app is idle and closes it a few minutes in.
    opts.disableIdleTimeout = true;
    // No media pipeline: this receiver only frames a page.
    opts.skipPlayersLoad = true;
    opts.customNamespaces = {};
    opts.customNamespaces[NS] = cast.framework.system.MessageType.JSON;
    ctx.start(opts);
})();
</script>
</body>
</html>
