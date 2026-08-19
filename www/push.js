// Shared Web Push client helpers: used by the settings.php "Browser
// Notifications" card and the footer opt-in prompt. External file (CSP:
// script-src 'self'), loaded WITHOUT defer from _footer.php because page
// scripts below the footer call into it immediately.
window.gnPush = (function () {
    'use strict';

    function supported() {
        return ('serviceWorker' in navigator) && ('PushManager' in window) && ('Notification' in window);
    }

    function api(csrf, action, extra) {
        var fd = new FormData();
        fd.append('csrf_token', csrf);
        fd.append('action', action);
        Object.keys(extra || {}).forEach(function (k) { fd.append(k, extra[k]); });
        return fetch('/push_dl.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); });
    }

    function b64ToU8(s) {
        var pad = '='.repeat((4 - s.length % 4) % 4);
        var raw = atob((s + pad).replace(/-/g, '+').replace(/_/g, '/'));
        var a = new Uint8Array(raw.length);
        for (var i = 0; i < raw.length; i++) a[i] = raw.charCodeAt(i);
        return a;
    }

    function deviceLabel() {
        var ua = navigator.userAgent;
        var os = /Android/.test(ua) ? 'Android' : /iPhone|iPad/.test(ua) ? 'iOS'
               : /Windows/.test(ua) ? 'Windows' : /Mac/.test(ua) ? 'Mac' : /Linux/.test(ua) ? 'Linux' : 'Device';
        var br = /Edg\//.test(ua) ? 'Edge' : /Firefox\//.test(ua) ? 'Firefox'
               : /Chrome\//.test(ua) ? 'Chrome' : /Safari\//.test(ua) ? 'Safari' : 'Browser';
        return os + ' ' + br;
    }

    function getSubscription() {
        if (!supported()) return Promise.resolve(null);
        return navigator.serviceWorker.ready
            .then(function (reg) { return reg.pushManager.getSubscription(); });
    }

    // Full enable flow: permission prompt → subscribe → store server-side.
    // Must be called from a user gesture. Resolves on success, rejects with
    // an Error whose message is user-presentable.
    function enable(csrf) {
        if (!supported()) return Promise.reject(new Error('Not supported in this browser'));
        return api(csrf, 'status').then(function (st) {
            if (!st.ok) throw new Error(st.error || 'Could not fetch server key');
            return Notification.requestPermission().then(function (perm) {
                if (perm !== 'granted') throw new Error('Permission not granted');
                return navigator.serviceWorker.ready;
            }).then(function (reg) {
                return reg.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey: b64ToU8(st.pubkey)
                });
            });
        }).then(function (sub) {
            var j = sub.toJSON();
            return api(csrf, 'subscribe', {
                endpoint: sub.endpoint, p256dh: j.keys.p256dh, auth: j.keys.auth, label: deviceLabel()
            }).then(function (r) {
                if (!r.ok) throw new Error(r.error || 'Could not save subscription');
            });
        });
    }

    function disable(csrf) {
        return getSubscription().then(function (sub) {
            if (!sub) return null;
            var ep = sub.endpoint;
            return sub.unsubscribe().then(function () { return api(csrf, 'unsubscribe', { endpoint: ep }); });
        });
    }

    return { supported: supported, api: api, b64ToU8: b64ToU8, deviceLabel: deviceLabel,
             getSubscription: getSubscription, enable: enable, disable: disable };
})();
