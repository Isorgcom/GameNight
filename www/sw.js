// GameNight service worker: Web Push receiver only (no offline caching).
// Payload is JSON {title, body, link} produced by webpush_notify_user().
// Keep this file at the web root so its scope covers the whole site.

self.addEventListener('install', function () { self.skipWaiting(); });
self.addEventListener('activate', function (e) { e.waitUntil(self.clients.claim()); });

self.addEventListener('push', function (e) {
    var data = { title: 'Game Night', body: '', link: '/' };
    try {
        var j = e.data ? e.data.json() : null;
        if (j) {
            data.title = j.title || data.title;
            data.body  = j.body  || '';
            data.link  = j.link  || '/';
        }
    } catch (err) { /* non-JSON payload: show the generic notification */ }
    e.waitUntil(self.registration.showNotification(data.title, {
        body: data.body,
        icon: '/favicon.php',
        badge: '/img/push-badge.png',
        data: { link: data.link },
        // Collapse repeated pushes for the same link into one notification.
        tag: 'gn-' + data.link
    }));
});

self.addEventListener('notificationclick', function (e) {
    e.notification.close();
    var link = (e.notification.data && e.notification.data.link) || '/';
    e.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (list) {
        // Focus an existing tab already on the target page, else reuse any
        // open tab, else open a new one.
        for (var i = 0; i < list.length; i++) {
            if (list[i].url.indexOf(link) !== -1 && 'focus' in list[i]) return list[i].focus();
        }
        for (var j = 0; j < list.length; j++) {
            if ('navigate' in list[j] && 'focus' in list[j]) {
                return list[j].navigate(link).then(function (c) { return c ? c.focus() : null; });
            }
        }
        return self.clients.openWindow(link);
    }));
});
