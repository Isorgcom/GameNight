/**
 * pk-a2hs.js — the "Add to Home Screen" card. Loaded by _footer.php for every
 * logged-in user, so the whole site installs as one app (the site manifest's
 * start_url is '/'). Deliberately NOT on the timer or Table Manager display
 * pages: the app is the thing to install, not one game's screen.
 *
 * Why a card and not an install: iOS and iPadOS have no install API at all. The
 * iPad DOES have element fullscreen, but Safari paints its own close ✕ in the
 * top-left of a fullscreen page and no page can remove it. The only chrome-free,
 * ✕-free way to run the site on an iPad is a home-screen web app, and the only
 * way to make one is the user tapping Share → Add to Home Screen. So on iOS the
 * card says exactly that, shows the share glyph, and knows where that button
 * lives on this device. Chromium (Android, desktop) has a real prompt:
 * beforeinstallprompt is captured and the card's button calls prompt(). On iOS
 * the installed app is also the only place push notifications can be enabled.
 *
 * Config, set before this script loads (all optional):
 *   window.PK_A2HS = {
 *     key:          'app',              // scopes the dismissal in localStorage
 *     name:         'Game Night',       // what gets added, in the title
 *     why:          'It opens like an app …',
 *     offsetBottom: 0,                  // px to clear a page's own fixed footer
 *     avoid:        '#pushPromptCard',  // wait while this element is showing
 *     minViews:     2                   // page loads before the first offer
 *   };
 *
 * The footer asks for the second page view (a first visit is for the page the
 * person came for) and waits while the push card is up — two cards on one
 * screen is a pop-up, not an offer.
 *
 * Never shows: in standalone/fullscreen display mode (nothing left to gain),
 * inside an iframe, on a fine-pointer device (a TV or a laptop is not putting
 * anything on a home screen), or after "Don't show again". "Not now" snoozes a
 * week; an ignored card that hides itself snoozes a day so nobody is nagged
 * every page load. show(true), used by the Install button in My Settings,
 * overrides all of that except standalone and the iframe.
 *
 * Self-contained on purpose: injects its own CSS (the pk-dialogs.js pattern,
 * so it works on pages that never load style.css), binds its own listeners (no
 * data-act, so the dispatch sweeps have nothing to count), and every string
 * lands via textContent. The one innerHTML is a constant SVG glyph.
 */
(function () {
    'use strict';

    var cfg = Object.assign({
        key: 'page',
        name: 'this page',
        why: 'It opens full screen with no browser bars, and it remembers where you were.',
        offsetBottom: 0,
        avoid: null,
        minViews: 1
    }, window.PK_A2HS || {});

    var STORE = 'pk_a2hs.' + String(cfg.key).replace(/[^a-z0-9_-]/gi, '');
    var DAY = 86400000;

    // Page views under this key. The site-wide card waits for the second one:
    // a first visit is for the page the person came for, not for an offer.
    var views = 0;
    try {
        views = (parseInt(localStorage.getItem(STORE + '.views') || '0', 10) || 0) + 1;
        localStorage.setItem(STORE + '.views', String(views));
    } catch (e) { views = 1; }

    // Another card holding the floor (the push prompt): wait for it to leave.
    function blocked() {
        if (!cfg.avoid) return false;
        var e = document.querySelector(cfg.avoid);
        return !!(e && e.offsetParent !== null && getComputedStyle(e).display !== 'none');
    }

    /* ── Platform ─────────────────────────────────────────────────────────── */
    var ua = navigator.userAgent || '';
    // iPadOS 13+ reports itself as a Mac; the touch points give it away.
    var isIPad  = /iPad/.test(ua) || (/Macintosh/.test(ua) && navigator.maxTouchPoints > 1);
    var isIOS   = isIPad || /iPhone|iPod/.test(ua);
    var coarse  = !!(window.matchMedia && window.matchMedia('(pointer: coarse)').matches);
    var framed  = (function () { try { return window.top !== window.self; } catch (e) { return true; } })();

    function standalone() {
        if (navigator.standalone) return true;
        if (!window.matchMedia) return false;
        return window.matchMedia('(display-mode: standalone)').matches
            || window.matchMedia('(display-mode: fullscreen)').matches
            || window.matchMedia('(display-mode: minimal-ui)').matches;
    }

    /* ── Dismissal memory ─────────────────────────────────────────────────── */
    function readState() {
        try { return JSON.parse(localStorage.getItem(STORE) || 'null') || {}; } catch (e) { return {}; }
    }
    function writeState(s) { try { localStorage.setItem(STORE, JSON.stringify(s)); } catch (e) {} }
    function dismissed() {
        var s = readState();
        if (s.never) return true;
        return !!(s.until && Date.now() < s.until);
    }
    function snooze(days) { writeState({ until: Date.now() + days * DAY }); }

    /* ── Card ─────────────────────────────────────────────────────────────── */
    var card = null, deferredPrompt = null, hideTimer = null;

    function injectCss() {
        if (document.getElementById('pk-a2hs-css')) return;
        var s = document.createElement('style');
        s.id = 'pk-a2hs-css';
        s.textContent =
            '.pk-a2hs{position:fixed;left:max(1rem,env(safe-area-inset-left));' +
                'bottom:calc(max(1rem,env(safe-area-inset-bottom)) + var(--pk-a2hs-offset,0px));z-index:300;' +
                'max-width:360px;background:#fff;color:#1e293b;border:1.5px solid #bfdbfe;border-left:4px solid #2563eb;' +
                'border-radius:10px;box-shadow:0 10px 30px rgba(0,0,0,.28);padding:.85rem 1rem;' +
                'font:14px/1.45 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;' +
                'opacity:0;transform:translateY(8px);transition:opacity .25s,transform .25s}' +
            '.pk-a2hs.open{opacity:1;transform:none}' +
            '.pk-a2hs-title{font-weight:700;font-size:.95rem;margin-bottom:.25rem}' +
            '.pk-a2hs-why{font-size:.82rem;color:#64748b;margin-bottom:.6rem}' +
            '.pk-a2hs-steps{margin:0 0 .7rem;padding-left:1.25rem;font-size:.84rem;color:#334155}' +
            '.pk-a2hs-steps li{margin:.2rem 0}' +
            '.pk-a2hs-steps b{color:#1e293b}' +
            '.pk-a2hs-glyph{display:inline-block;vertical-align:-.15em;width:1.05em;height:1.05em;color:#2563eb}' +
            '.pk-a2hs-actions{display:flex;gap:.5rem;align-items:center;flex-wrap:wrap}' +
            '.pk-a2hs-actions button{min-height:44px;border-radius:8px;font-weight:600;font-size:.84rem;cursor:pointer;' +
                '-webkit-tap-highlight-color:transparent}' +
            '.pk-a2hs-primary{background:#2563eb;color:#fff;border:1.5px solid #2563eb;padding:0 1rem}' +
            '.pk-a2hs-later{background:#fff;color:#1e293b;border:1.5px solid #cbd5e1;padding:0 .9rem}' +
            '.pk-a2hs-never{background:none;border:none;color:#94a3b8;text-decoration:underline;font-weight:500;padding:0 .2rem}' +
            '@media (max-width:480px){.pk-a2hs{left:.6rem;right:.6rem;max-width:none}}' +
            '@media (prefers-reduced-motion:reduce){.pk-a2hs{transition:none;transform:none}}';
        document.head.appendChild(s);
    }

    // The iOS share glyph: a box with an arrow rising out of it. Constant markup,
    // no interpolation, so innerHTML is safe here and nowhere else in this file.
    var SHARE_SVG = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">' +
        '<path fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" ' +
        'd="M12 3v12M8 7l4-4 4 4M5 11v8a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-8"/></svg>';

    function el(tag, cls, text) {
        var e = document.createElement(tag);
        if (cls) e.className = cls;
        if (text !== undefined) e.textContent = text;
        return e;
    }

    function build() {
        if (card) return;
        injectCss();
        card = el('div', 'pk-a2hs');
        card.setAttribute('role', 'dialog');
        card.setAttribute('aria-label', 'Add to Home Screen');
        card.style.setProperty('--pk-a2hs-offset', (parseInt(cfg.offsetBottom, 10) || 0) + 'px');

        card.appendChild(el('div', 'pk-a2hs-title', 'Put ' + cfg.name + ' on your Home Screen'));
        card.appendChild(el('div', 'pk-a2hs-why', cfg.why));

        if (isIOS) {
            var steps = el('ol', 'pk-a2hs-steps');
            var s1 = el('li');
            s1.appendChild(document.createTextNode('Tap '));
            var glyph = el('span', 'pk-a2hs-glyph');
            glyph.innerHTML = SHARE_SVG;
            s1.appendChild(glyph);
            s1.appendChild(document.createTextNode(' '));
            s1.appendChild(el('b', null, 'Share'));
            s1.appendChild(document.createTextNode(isIPad
                ? ' in the toolbar at the top of the screen.'
                : ' (in Safari it is at the bottom of the screen).'));
            var s2 = el('li');
            s2.appendChild(document.createTextNode('Choose '));
            s2.appendChild(el('b', null, 'Add to Home Screen'));
            s2.appendChild(document.createTextNode('.'));
            var s3 = el('li');
            s3.appendChild(document.createTextNode('Tap '));
            s3.appendChild(el('b', null, 'Add'));
            s3.appendChild(document.createTextNode(', then open it from the new icon.'));
            steps.appendChild(s1); steps.appendChild(s2); steps.appendChild(s3);
            card.appendChild(steps);
        }

        var actions = el('div', 'pk-a2hs-actions');
        var primary = el('button', 'pk-a2hs-primary', isIOS ? 'Got it' : 'Add to Home Screen');
        primary.type = 'button';
        var later = el('button', 'pk-a2hs-later', 'Not now');
        later.type = 'button';
        var never = el('button', 'pk-a2hs-never', "Don't show again");
        never.type = 'button';
        actions.appendChild(primary); actions.appendChild(later); actions.appendChild(never);
        card.appendChild(actions);

        primary.addEventListener('click', function (e) {
            e.stopPropagation();
            if (isIOS || !deferredPrompt) { snooze(7); hide(); return; }
            var p = deferredPrompt; deferredPrompt = null;
            hide();
            p.prompt();
            p.userChoice.then(function (c) {
                if (c && c.outcome === 'accepted') writeState({ never: 1 }); else snooze(7);
            }).catch(function () { snooze(7); });
        });
        later.addEventListener('click', function (e) { e.stopPropagation(); snooze(7); hide(); });
        never.addEventListener('click', function (e) { e.stopPropagation(); writeState({ never: 1 }); hide(); });
        // A tap on the card itself must not wake the page's idle controls or
        // start its auto-fullscreen: the card is chrome, not the display.
        card.addEventListener('pointerdown', function (e) { e.stopPropagation(); });
        card.addEventListener('click', function (e) { e.stopPropagation(); });

        document.body.appendChild(card);
    }

    function hide() {
        clearTimeout(hideTimer);
        if (card) card.classList.remove('open');
    }

    /* Show the card. `force` is for an explicit user action (they tapped a
     * fullscreen button that cannot deliver on this device) and overrides a
     * dismissal; the automatic path never does. Returns true when shown. */
    var retries = 0;
    function show(force) {
        if (framed || standalone()) return false;
        if (!force && (dismissed() || !coarse || views < (parseInt(cfg.minViews, 10) || 1))) return false;
        if (!isIOS && !deferredPrompt) return false;      // nothing actionable here
        if (!force && blocked()) {
            // Come back once the other card is gone; give up after a few minutes.
            if (retries++ < 12) setTimeout(function () { show(false); }, 15000);
            return false;
        }
        build();
        // Two frames so the transition runs from the initial state.
        requestAnimationFrame(function () { requestAnimationFrame(function () { card.classList.add('open'); }); });
        clearTimeout(hideTimer);
        hideTimer = setTimeout(function () { snooze(1); hide(); }, 45000);
        return true;
    }

    /* ── Wiring ───────────────────────────────────────────────────────────── */
    window.addEventListener('beforeinstallprompt', function (e) {
        e.preventDefault();           // we decide when, via the card
        deferredPrompt = e;
        show(false);
    });
    window.addEventListener('appinstalled', function () { writeState({ never: 1 }); hide(); });

    // iOS has no event to wait for: give the display a few seconds to settle,
    // then offer. Anything already dismissed or already standalone stays quiet.
    if (isIOS) setTimeout(function () { show(false); }, 6000);

    window.pkA2HS = { show: show, hide: hide, isIOS: isIOS, isIPad: isIPad, standalone: standalone };
})();
