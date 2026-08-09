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
            break;
        }
        case 'help':
            t.parentElement.classList.toggle('open');
            break;
        case 'collapse':
            if (typeof toggleNavCollapse === 'function') toggleNavCollapse();
            break;
    }
});
