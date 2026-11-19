// Close dropdown when clicking outside (mouse users)
document.addEventListener('click', function(e) {
    if (!e.target.closest('.nav-dropdown-wrap')) {
        document.querySelectorAll('.nav-dropdown').forEach(function(d) {
            d.style.display = 'none';
        });
    }
});

// ── Nav event delegation ─────────────────────────────────────────────────────
// _nav.php carries no inline on* handlers; it tags controls with data-nav and
// they are dispatched here. This file is external, so it is covered by
// script-src 'self' and needs no nonce. Step 2 of the CSP work in SECURITY.md.
//
// Ordering note: this listener must run BEFORE the close-on-outside-click
// handler above would otherwise undo it. It does not, because that one only
// acts when the click is outside .nav-dropdown-wrap, and every control here is
// inside one — except the collapse button, which owns no dropdown.
document.addEventListener('click', function (e) {
    var t = e.target.closest('[data-nav]');
    if (!t) return;
    switch (t.dataset.nav) {
        case 'dropdown': {
            // Toggle this control's own dropdown, the next sibling element.
            var d = t.nextElementSibling;
            if (d) d.style.display = (d.style.display === 'block') ? 'none' : 'block';
            navSyncExpanded();
            break;
        }
        case 'help':
            t.parentElement.classList.toggle('open');
            t.setAttribute('aria-expanded', t.parentElement.classList.contains('open') ? 'true' : 'false');
            break;
        case 'collapse':
            if (typeof toggleNavCollapse === 'function') toggleNavCollapse();
            break;
        case 'theme':
            navToggleTheme(t);
            break;
    }
});

// ── Disclosure state for assistive tech ─────────────────────────────────────
// Each dropdown trigger reports whether its menu is open, and Escape closes an
// open menu and puts focus back on its trigger (WAI-ARIA disclosure pattern).
// Runs after the click handlers above, including the close-on-outside one.
function navSyncExpanded() {
    document.querySelectorAll('[data-nav="dropdown"]').forEach(function (b) {
        var d = b.nextElementSibling;
        b.setAttribute('aria-expanded', d && d.style.display === 'block' ? 'true' : 'false');
    });
}
document.querySelectorAll('[data-nav="help"]').forEach(function (b) {
    b.setAttribute('aria-expanded', b.parentElement.classList.contains('open') ? 'true' : 'false');
});
navSyncExpanded();
document.addEventListener('click', navSyncExpanded);
document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    var open = Array.prototype.filter.call(document.querySelectorAll('[data-nav="dropdown"]'), function (b) {
        var d = b.nextElementSibling;
        return d && d.style.display === 'block';
    });
    if (!open.length) return;
    open.forEach(function (b) { b.nextElementSibling.style.display = 'none'; });
    navSyncExpanded();
    var back = open.find(function (b) { return b.nextElementSibling.contains(document.activeElement); }) || open[0];
    back.focus();
});

// ── Light / dark switch (v1.34.0) ───────────────────────────────────────────
// <html data-theme> is the choice; without it the page follows the device.
// A click flips whatever is showing now and saves it: to the account when the
// button carries a CSRF token (signed in), to a year-long cookie otherwise,
// which _nav.php reads back for a signed-out visitor.
function navIsDark() {
    var a = document.documentElement.getAttribute('data-theme');
    if (a) return a === 'dark';
    return !!(window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
}
function navThemeLabels() {
    var l = navIsDark() ? 'Switch to light mode' : 'Switch to dark mode';
    document.querySelectorAll('[data-nav="theme"]').forEach(function (b) {
        b.setAttribute('aria-label', l); b.title = l;
    });
}
function navToggleTheme(btn) {
    if (document.documentElement.hasAttribute('data-theme-lock')) return;
    var next = navIsDark() ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', next);
    navThemeLabels();
    if (typeof pkAnnounce === 'function') pkAnnounce(next === 'dark' ? 'Dark mode' : 'Light mode');
    var tok = btn.getAttribute('data-csrf');
    if (tok) {
        var fd = new FormData();
        fd.append('csrf_token', tok); fd.append('theme', next);
        fetch('/theme_dl.php', { method: 'POST', body: fd, credentials: 'same-origin' }).catch(function () {});
    } else {
        document.cookie = 'gn_theme=' + next + '; path=/; max-age=31536000; samesite=lax' + (location.protocol === 'https:' ? '; secure' : '');
    }
}
navThemeLabels();
if (window.matchMedia) {
    var _navMq = window.matchMedia('(prefers-color-scheme: dark)');
    if (_navMq.addEventListener) _navMq.addEventListener('change', navThemeLabels);
}

