/* Google Cast sender for the Tournament Timer (timer.php).
 *
 * Loaded only when an admin has set site_settings.cast_app_id and the page has
 * a game to send (TB_CAST_KEY). Google's sender SDK, loaded right after this
 * file, calls window.__onGCastApiAvailable when it is ready; on a browser
 * without Cast (Safari, Firefox, anything on iOS) it reports unavailable and
 * the button simply never appears.
 *
 * What goes to the TV is the game's remote_key and nothing else, over the
 * custom namespace cast_receiver.php listens on. That is the same key the QR
 * cell prints: it buys viewing, never control. */
(function () {
    'use strict';
    var NS = 'urn:x-cast:poker.gamenight.timer';
    var btn = document.getElementById('tbCastBtn');

    function sendKey(session) {
        if (!session || typeof TB_CAST_KEY === 'undefined' || !TB_CAST_KEY) return;
        session.sendMessage(NS, { key: TB_CAST_KEY }).catch(function () {});
    }

    window.__onGCastApiAvailable = function (isAvailable) {
        if (!isAvailable || !btn || typeof TB_CAST_APP_ID === 'undefined') return;
        var cf = cast.framework;
        var ctx = cf.CastContext.getInstance();
        ctx.setOptions({
            receiverApplicationId: TB_CAST_APP_ID,
            // Re-joins a session this origin started, so reloading the timer
            // on the laptop does not drop the TV.
            autoJoinPolicy: chrome.cast.AutoJoinPolicy.ORIGIN_SCOPED
        });

        function syncButton() {
            var st = ctx.getCastState();
            btn.hidden = (st === cf.CastState.NO_DEVICES_AVAILABLE);
            var on = (st === cf.CastState.CONNECTED);
            btn.classList.toggle('tb-casting', on);
            btn.title = on ? 'Casting. Click to stop or change the TV' : 'Cast to a TV';
            btn.setAttribute('aria-label', btn.title);
        }
        ctx.addEventListener(cf.CastContextEventType.CAST_STATE_CHANGED, syncButton);
        ctx.addEventListener(cf.CastContextEventType.SESSION_STATE_CHANGED, function (ev) {
            // A resumed session may be showing another game, so send on both.
            if (ev.sessionState === cf.SessionState.SESSION_STARTED ||
                ev.sessionState === cf.SessionState.SESSION_RESUMED) {
                sendKey(ctx.getCurrentSession());
            }
        });
        syncButton();

        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            // Chrome's own picker: choose a TV, or stop casting to the current one.
            ctx.requestSession().catch(function () {});
        });
    };
})();
