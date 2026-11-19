/*
 * pk-modal.js: dialog behaviour for the page-level .modal-overlay modals.
 *
 * Each page opens and closes its own modal however it always has (a class, an
 * inline display); this file watches for that and adds what a dialog needs
 * without touching the page's code:
 *
 *   - role="dialog", aria-modal and a name taken from the modal's first heading
 *   - focus moves into the modal when it opens, and Tab stays inside it
 *   - Escape closes it, via its own close button or its backdrop handler
 *   - focus goes back to whatever opened it when it closes
 *
 * The pk-dialogs.js popups and the checkin.php overlays manage this themselves
 * and are not .modal-overlay, so they are left alone. Loaded by _footer.php.
 */
(function () {
    'use strict';

    var FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), ' +
        'select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"]), [contenteditable="true"]';
    var seq = 0;
    var openStack = [];   // overlays currently open, newest last
    var opener = new WeakMap();

    function isOpen(ov) {
        return ov.isConnected && getComputedStyle(ov).display !== 'none' && getComputedStyle(ov).visibility !== 'hidden';
    }

    function visibleFocusables(root) {
        return Array.prototype.filter.call(root.querySelectorAll(FOCUSABLE), function (el) {
            return el.offsetParent !== null || el.getClientRects().length > 0;
        });
    }

    function label(ov) {
        var box = ov.querySelector('.modal') || ov;
        // A modal that already declares itself a dialog runs its own focus and
        // keys (event_edit.php's contact popup); only label the rest.
        if (box.getAttribute('role') === 'dialog') { ov.__pkSelf = true; return; }
        box.setAttribute('role', 'dialog');
        if (!box.hasAttribute('aria-modal')) box.setAttribute('aria-modal', 'true');
        if (!box.hasAttribute('aria-labelledby') && !box.hasAttribute('aria-label')) {
            var h = box.querySelector('h1, h2, h3');
            if (h) {
                if (!h.id) h.id = 'pkModalTitle' + (++seq);
                box.setAttribute('aria-labelledby', h.id);
            }
        }
    }

    function onOpen(ov) {
        if (ov.__pkSelf || openStack.indexOf(ov) !== -1) return;
        openStack.push(ov);
        var prev = document.activeElement;
        if (prev && prev !== document.body && !ov.contains(prev)) opener.set(ov, prev);
        // Let the page finish its own open (it may focus a field itself).
        setTimeout(function () {
            if (!isOpen(ov) || ov.contains(document.activeElement)) return;
            var box = ov.querySelector('.modal') || ov;
            var f = visibleFocusables(box);
            // Prefer the first field over the close button in the header.
            var first = f.find(function (el) { return /^(INPUT|SELECT|TEXTAREA)$/.test(el.tagName) || el.isContentEditable; }) || f[0];
            if (first) { try { first.focus({ preventScroll: true }); } catch (e) { first.focus(); } }
            else { box.setAttribute('tabindex', '-1'); box.focus(); }
        }, 40);
    }

    function onClose(ov) {
        var i = openStack.indexOf(ov);
        if (i === -1) return;
        openStack.splice(i, 1);
        var back = opener.get(ov);
        opener.delete(ov);
        if (back && back.isConnected && (ov.contains(document.activeElement) || document.activeElement === document.body)) {
            try { back.focus({ preventScroll: true }); } catch (e) { back.focus(); }
        }
    }

    function check(ov) { if (isOpen(ov)) onOpen(ov); else onClose(ov); }

    function watch(ov) {
        if (ov.__pkModal) return;
        ov.__pkModal = true;
        label(ov);
        new MutationObserver(function () { check(ov); })
            .observe(ov, { attributes: true, attributeFilter: ['class', 'style', 'hidden'] });
        check(ov);
    }

    function closeTop(ov) {
        var btn = ov.querySelector('.modal-close, [data-act^="close"]');
        if (btn) { btn.click(); return; }
        // Backdrop handlers (data-act-self / overlayClick) close on a click
        // whose target is the overlay itself.
        ov.click();
    }

    document.addEventListener('keydown', function (e) {
        var ov = openStack[openStack.length - 1];
        if (!ov) return;
        if (!isOpen(ov)) { onClose(ov); return; }
        if (e.defaultPrevented) return;
        // A pk-dialogs popup raised over the modal handles its own keys.
        var pk = document.querySelector('.pk-modal-overlay.pk-dialog.open');
        if (pk && pk !== ov) return;
        if (e.key === 'Escape') {
            e.preventDefault();
            closeTop(ov);
        } else if (e.key === 'Tab') {
            var f = visibleFocusables(ov.querySelector('.modal') || ov);
            if (!f.length) { e.preventDefault(); return; }
            var first = f[0], last = f[f.length - 1];
            if (!ov.contains(document.activeElement)) { e.preventDefault(); first.focus(); }
            else if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
            else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
        }
    });

    function scan() { document.querySelectorAll('.modal-overlay').forEach(watch); }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', scan);
    else scan();
})();
