<?php
/* Shared footer partial — included by every full-page template. */
$_ftz  = new DateTimeZone(display_timezone());
$_fnow = new DateTime('now', $_ftz);
?>
<footer>
    &copy; <?= $_fnow->format('Y') ?> <?= htmlspecialchars($site_name) ?>
    &nbsp;&mdash;&nbsp; <?= $_fnow->format('F j, Y g:i A') ?>
    &nbsp;&mdash;&nbsp; v<?= htmlspecialchars(APP_VERSION) ?>
    &nbsp;&mdash;&nbsp;
    <a href="/privacy.php" style="color:inherit;opacity:.65;text-decoration:none">Privacy Policy</a>
    &nbsp;&middot;&nbsp;
    <a href="/terms.php" style="color:inherit;opacity:.65;text-decoration:none">Terms &amp; Conditions</a>
    <?php $_fdon = get_setting('donation_url', ''); if ($_fdon !== ''): ?>
    &nbsp;&middot;&nbsp;
    <a href="<?= htmlspecialchars($_fdon) ?>" target="_blank" rel="noopener" style="color:inherit;opacity:.65;text-decoration:none">&#10084; Support this site</a>
    <?php endif; ?>
</footer>
<?php
/* In-app help bubbles: only for logged-in users, only on screens that have
   enabled tips. Tips are inlined as JSON so there's no extra round-trip. */
$_hb_user = function_exists('current_user') ? current_user() : null;
if ($_hb_user) {
    $_hb_screen = basename($_SERVER['SCRIPT_NAME'] ?? '', '.php');
    // Fresh = pinned tips + tips this user hasn't individually dismissed.
    // Any fresh tips auto-show; otherwise ship the full set behind the "?" pill.
    $_hb_fresh   = help_fresh_bubbles_for_screen((int)$_hb_user['id'], $_hb_screen);
    $_hb_bubbles = $_hb_fresh ?: help_bubbles_for_screen($_hb_screen);
    if ($_hb_bubbles) {
        $_hb_tips = array_map(function ($b) {
            return [
                'id'              => (int)$b['id'],
                'title'           => $b['title'] ?? '',
                'body'            => $b['body'] ?? '',
                'anchor_selector' => $b['anchor_selector'] ?? '',
                'idx'             => isset($b['bubble_index']) && $b['bubble_index'] !== null ? (int)$b['bubble_index'] : null,
            ];
        }, $_hb_bubbles);
        $_hb_payload = [
            'screen'    => $_hb_screen,
            'tips'      => $_hb_tips,
            'dismissed' => !$_hb_fresh,
            'csrf'      => csrf_token(),
            'preview'   => false,
        ];
        ?>
        <script nonce="<?= csp_nonce() ?>">window.__help = <?= json_encode($_hb_payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
        <script src="/help-bubble.js?v=<?= htmlspecialchars(APP_VERSION) ?>" defer></script>
        <?php
    }
}

/* One-time timezone backfill: for a logged-in user who never set a timezone,
   detect the browser zone and store it. The endpoint only fills it when still
   empty, so an explicit Settings choice is never overwritten. Self-terminating —
   once stored, this block stops emitting on the next page load. */
if ($_hb_user && empty($_hb_user['timezone'])) {
    ?>
    <script nonce="<?= csp_nonce() ?>">
    (function () {
        try {
            var tz = Intl.DateTimeFormat().resolvedOptions().timeZone;
            if (!tz) return;
            fetch('/set_timezone_dl.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'csrf_token=' + encodeURIComponent(<?= json_encode(csrf_token(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>) +
                      '&timezone=' + encodeURIComponent(tz)
            });
        } catch (e) {}
    })();
    </script>
    <?php
}
?>
<?php /* Segmented control + slide engine. Deliberately NOT deferred: a page may
         put its own inline <script nonce="<?= csp_nonce() ?>"> after this footer (checkin.php does), and it
         must see these functions already defined. Tiny, and already at the end
         of the body, so blocking is a non-issue. */ ?>
<?php /* Both of those synchronous callers only exist for a logged-in user
         (checkin.php is login-gated; the push prompt below is $_hb_user-gated),
         so for a guest these two can defer and stop blocking the landing page's
         first paint. Same file, same cache entry; only the attribute changes. */ ?>
<script src="/pk-seg.js?v=<?= htmlspecialchars(APP_VERSION . '.' . (@filemtime(__DIR__ . '/pk-seg.js') ?: 0)) ?>"<?= $_hb_user ? '' : ' defer' ?>></script>
<?php /* Web Push helpers. No defer for a user: the opt-in prompt script below and
         settings.php's card script both call into it synchronously. */ ?>
<script src="/push.js?v=<?= htmlspecialchars(APP_VERSION . '.' . (@filemtime(__DIR__ . '/push.js') ?: 0)) ?>"<?= $_hb_user ? '' : ' defer' ?>></script>
<?php /* filemtime cache-buster: pk-dialogs.js changes must reach browsers even
         between version bumps (a stale copy silently breaks pk* callers). */ ?>
<script src="/pk-dialogs.js?v=<?= htmlspecialchars(APP_VERSION . '.' . (@filemtime(__DIR__ . '/pk-dialogs.js') ?: 0)) ?>" defer></script>
<!-- Shared declarative handler dispatch (see SECURITY.md). External, so it needs
     no CSP nonce; cache-busted because it carries behaviour, not just styling. -->
<script src="/pk-dispatch.js?v=<?= htmlspecialchars(APP_VERSION . '.' . (@filemtime(__DIR__ . '/pk-dispatch.js') ?: 0)) ?>" defer></script>
<?php /* Avatars only appear on logged-in screens; a guest has nothing to draw. */ ?>
<?php if ($_hb_user): ?>
<script src="/avatar.js?v=<?= htmlspecialchars(APP_VERSION) ?>" defer></script>
<?php endif; ?>
<script nonce="<?= csp_nonce() ?>">
// PWA manifest + service worker for Web Push. The manifest link is injected
// here rather than into every page's <head>; iOS reads it from the live DOM
// at add-to-home-screen time, so this is sufficient. Registering sw.js on
// every load is what propagates sw.js updates to already-subscribed devices,
// which is a logged-in concern: a guest (or a crawler) has no subscription to
// keep current, so the registration is skipped for them.
(function () {
    if (!document.querySelector('link[rel="manifest"]')) {
        var l = document.createElement('link');
        l.rel = 'manifest'; l.href = '/manifest.php';
        document.head.appendChild(l);
    }
    // The icon iOS puts on the home screen. Without it Safari uses a screenshot
    // of the page, which is what every install looked like before v0.2123.
    if (!document.querySelector('link[rel="apple-touch-icon"]')) {
        var a = document.createElement('link');
        a.rel = 'apple-touch-icon'; a.href = '/img/app-icon-192.png';
        document.head.appendChild(a);
    }
<?php if ($_hb_user): ?>
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('/sw.js').catch(function () {});
    }
<?php endif; ?>
})();
</script>
<?php if ($_hb_user): /* Live badge updater + chime: polls unread counts and
    updates the nav bell / Messages badges in place. Plays a short two-note
    chime when the total rises (browsers allow audio only after the user has
    interacted with the page — until then it updates silently). */ ?>
<script nonce="<?= csp_nonce() ?>">
(function () {
    var KEY = 'gn_notif_seen';
    function setBadge(cls, n) {
        document.querySelectorAll('.' + cls).forEach(function (el) {
            el.textContent = n > 99 ? '99+' : n;
            el.style.display = n > 0 ? '' : 'none';
        });
    }
    // Audio unlock: browsers keep AudioContext suspended until the user has
    // interacted with the page, and resume() is async — so we prime the
    // context on the first click/keypress/touch and play notes only once the
    // context reports running (waiting out the resume promise when needed).
    var audioCtx = null;
    function ensureCtx() {
        if (!audioCtx) {
            try { audioCtx = new (window.AudioContext || window.webkitAudioContext)(); } catch (e) { return null; }
        }
        if (audioCtx.state === 'suspended') {
            try { audioCtx.resume().catch(function () {}); } catch (e) {}
        }
        return audioCtx;
    }
    ['click', 'keydown', 'touchstart'].forEach(function (ev) {
        document.addEventListener(ev, function unlock() {
            document.removeEventListener(ev, unlock, true);
            ensureCtx();
        }, true);
    });
    function playNotes(ctx) {
        [[880, 0], [1174.66, 0.12]].forEach(function (note) {
            var o = ctx.createOscillator();
            var g = ctx.createGain();
            o.type = 'sine';
            o.frequency.value = note[0];
            g.gain.setValueAtTime(0.0001, ctx.currentTime + note[1]);
            g.gain.exponentialRampToValueAtTime(0.12, ctx.currentTime + note[1] + 0.02);
            g.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + note[1] + 0.35);
            o.connect(g); g.connect(ctx.destination);
            o.start(ctx.currentTime + note[1]);
            o.stop(ctx.currentTime + note[1] + 0.4);
        });
    }
    // Exposed globally so pages with their own live views (message_thread.php)
    // can ring the same chime for messages that arrive as in-page bubbles.
    window.gnChime = chime;
    function chime() {
        try {
            var ctx = ensureCtx();
            if (!ctx) return;
            if (ctx.state === 'running') { playNotes(ctx); return; }
            ctx.resume().then(function () {
                if (ctx.state === 'running') playNotes(ctx);
            }).catch(function () {});
        } catch (e) {}
    }
    function poll() {
        if (document.hidden) return;
        fetch('/notify_status.php', { credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (j) {
                if (!j || !j.ok) return;
                setBadge('js-bell-badge', j.bell);
                setBadge('js-dm-badge', j.dm);
                var total = j.bell + j.dm;
                setBadge('js-avatar-badge', total); // combined counter on the avatar

                var prev = parseInt(localStorage.getItem(KEY) || '-1', 10);
                if (prev >= 0 && total > prev) chime();
                localStorage.setItem(KEY, String(total));
            }).catch(function () {});
    }
    setInterval(poll, 15000);
    poll();
})();
</script>
<?php endif; ?>
<?php if ($_hb_user && empty($_hb_user['push_prompt_dismissed'])): /* Browser-
    notifications opt-in ask, same three-answer pattern as the timer opt-in:
    "Turn on" runs the permission+subscribe flow, "Not now" snoozes 30 days
    in this browser (localStorage), "No thanks" is permanent (server flag).
    Only shown when push is supported, permission isn't denied, and this
    device has no subscription yet. */ ?>
<div id="pushPromptCard" style="display:none;position:fixed;right:1rem;bottom:1rem;z-index:250;max-width:330px;background:#fff;border:1.5px solid #bfdbfe;border-left:4px solid #2563eb;border-radius:10px;box-shadow:0 10px 30px rgba(0,0,0,.18);padding:.85rem 1rem">
    <div id="pushPromptBody">
        <div style="font-weight:700;font-size:.9rem;color:#1e293b;margin-bottom:.25rem">&#128276; Get notified about your games?</div>
        <div style="font-size:.8rem;color:#64748b;margin-bottom:.7rem">Invites, reminders, RSVPs and messages as notifications on this device, even when the site is closed.</div>
        <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap">
            <button type="button" class="btn btn-primary" style="padding:.4rem .9rem;font-size:.8rem" data-act="pushPromptEnable">Turn on</button>
            <button type="button" class="btn btn-outline" style="padding:.4rem .9rem;font-size:.8rem" data-act="pushPromptLater">Not now</button>
            <button type="button" style="background:none;border:none;cursor:pointer;font-size:.75rem;color:#94a3b8;text-decoration:underline;padding:.4rem .2rem" data-act="pushPromptNever">No thanks</button>
        </div>
    </div>
</div>
<script nonce="<?= csp_nonce() ?>">
(function () {
    var CSRF = <?= json_encode(csrf_token()) ?>;
    var card = document.getElementById('pushPromptCard');
    function hide() { if (card) card.style.display = 'none'; }

    // Handlers are defined UNCONDITIONALLY: the data-act buttons exist in the
    // markup even when the guards below keep the card hidden, and a data-act
    // control naming a missing function is exactly what the double-dispatch
    // sweep flags (and it is right to).
    window.pushPromptEnable = function () {
        gnPush.enable(CSRF).then(function () {
            document.getElementById('pushPromptBody').innerHTML =
                '<div style="font-weight:700;font-size:.9rem;color:#166534">&#10003; Notifications on</div>'
                + '<div style="font-size:.8rem;color:#64748b">Manage devices any time in My Settings.</div>';
            setTimeout(hide, 4000);
        }).catch(function (e) {
            hide();
            if (e && e.message !== 'Permission not granted') {
                pkAlert('Could not enable notifications: ' + e.message);
            }
        });
    };
    window.pushPromptLater = function () {
        localStorage.setItem('gn_push_snooze', String(Date.now() + 30 * 86400000));
        hide();
    };
    window.pushPromptNever = function () {
        gnPush.api(CSRF, 'dismiss_prompt', {});
        hide();
    };

    // Show-logic guards: only surface the ask where enabling could succeed.
    if (!card || !window.gnPush || !gnPush.supported()) return;
    if (Notification.permission === 'denied') return;
    var snooze = parseInt(localStorage.getItem('gn_push_snooze') || '0', 10);
    if (snooze && Date.now() < snooze) return;
    gnPush.getSubscription().then(function (sub) {
        if (!sub) card.style.display = '';
    }).catch(function () {});
})();
</script>
<?php /* "Add to Home Screen" for the whole site (pk-a2hs.js): the manifest above
         starts at '/', so the icon opens the app. Logged-in users only, from
         their second page view, on touch devices, and it waits while the push
         card is up so there is never more than one card on screen. On iOS the
         installed app is also the only place push can be enabled at all. */ ?>
<script nonce="<?= csp_nonce() ?>">
window.PK_A2HS = { key: 'app', name: <?= json_encode(get_setting('site_name', 'Game Night')) ?>,
                   why: 'It opens like an app, full screen, and can send you notifications about your games.',
                   avoid: '#pushPromptCard', minViews: 2 };
</script>
<script src="/pk-a2hs.js?v=<?= htmlspecialchars(APP_VERSION . '.' . (@filemtime(__DIR__ . '/pk-a2hs.js') ?: 0)) ?>" defer></script>
<?php endif; ?>
