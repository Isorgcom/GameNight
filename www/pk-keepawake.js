/* pk-keepawake.js — a second way to keep an iPhone or iPad showing a timer on.
 *
 * The timer pages ask for navigator.wakeLock, and NoSleep.js (0.12) asks for
 * the very same API whenever it exists, so on an iPhone or iPad the one call is
 * all there is. It is not enough: on 2026-11-12 an iPad on Safari 26.6, in an
 * ordinary tab, opened the Tournament Timer and went dark 10 minutes later (the
 * access log shows its polling stop for 59 minutes). The same host had to set
 * Auto-Lock to Never the month before as well.
 *
 * A short silent video played inline is the older trick, and the one NoSleep
 * itself uses on a browser with no Wake Lock API; the clip is the one that
 * library ships (MIT, github.com/richtr/NoSleep.js). The timer screens play it
 * alongside the lock, from the first tap.
 *
 * Loaded by the two timer screens only. A playing video takes the device's
 * audio session, which can stop music running in another app; that is fine on
 * a screen propped up as the clock and not on the phone in the host's hand, so
 * the Table Manager does not load this.
 *
 *   pkKeepAwakeNeedsVideo()  true on an iPhone or iPad
 *   pkKeepAwakeVideo()       start it; call from inside a tap. Returns a
 *                            promise that resolves once it is playing.
 */
(function () {
    'use strict';
    var video = null, playing = false;

    function isIOS() {
        var ua = navigator.userAgent || '';
        // iPadOS reports itself as a Mac; the touch points give it away.
        return /iPad|iPhone|iPod/.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    }
    window.pkKeepAwakeNeedsVideo = function () { return isIOS(); };

    window.pkKeepAwakeVideo = function () {
        if (!window.pkKeepAwakeNeedsVideo()) return Promise.reject(new Error('not needed'));
        if (playing && video && !video.paused) return Promise.resolve();
        if (!video) {
            video = document.createElement('video');
            video.setAttribute('title', 'Game Night timer');
            video.setAttribute('playsinline', '');
            video.setAttribute('loop', '');
            video.src = '/keepawake.mp4';
            // In the page but out of sight: iOS will not count a detached or
            // display:none video as playing.
            video.style.cssText = 'position:fixed;left:0;bottom:0;width:1px;height:1px;opacity:.01;pointer-events:none';
            video.addEventListener('pause', function () { playing = false; });
            document.body.appendChild(video);
        }
        var p;
        try { p = video.play(); } catch (e) { return Promise.reject(e); }
        if (!p || typeof p.then !== 'function') { playing = true; return Promise.resolve(); }
        return p.then(function () { playing = true; });
    };

    // iOS pauses the clip when the app goes to the background. Coming back, try
    // to resume; if iOS wants a fresh tap for that, the pages' own tap listeners
    // call pkKeepAwakeVideo() again on the next touch.
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible' && video && video.paused) {
            try { var p = video.play(); if (p && p.then) p.then(function () { playing = true; }).catch(function () {}); } catch (e) {}
        }
    });
})();
