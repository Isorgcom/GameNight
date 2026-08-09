/**
 * Shared declarative handler dispatch.
 *
 * Inline on* attributes cannot be authorised by a CSP nonce, so controls carry a
 * data-act* attribute naming the function and these delegated listeners invoke
 * it. See SECURITY.md for the conversion pattern and the wider CSP plan.
 *
 * Loaded from _footer.php on every page. It is an external file, so it is
 * covered by script-src 'self' and needs no nonce. checkin.php and timer.php
 * carry their own copies of this logic, added before this file existed; they are
 * deliberately left alone rather than re-plumbed while they work.
 *
 *   data-act            click       -> fn()
 *   data-act-<evt>      that event  -> fn()   (change, input, keydown, keyup,
 *                                              submit, dragstart, dragover,
 *                                              drop, dragend, mousedown)
 *   data-act-self       click, but ONLY when the click is on the element itself
 *                       (the modal-backdrop pattern, was if(event.target===this))
 *   data-stop           swallow the click so an ancestor action does not fire
 *
 * Arguments live in data-a1..a4 for click and data-<evt>-a1..a4 for every other
 * event. They are namespaced PER EVENT because one element can carry two
 * handlers, and a shared namespace emits a duplicate attribute; HTML keeps the
 * first, so the second handler silently receives the wrong arguments. That cost
 * a broken Enter key in checkin.php (v0.2075).
 *
 * Values are coerced back to their original types, because the inline form
 * passed doSomething(12) as a NUMBER. A leading @ reads from the element or the
 * event at call time, replacing this.value, this.checked, event and so on.
 */
(function () {
    'use strict';

    var EVENTS = ['change', 'input', 'keydown', 'keyup', 'submit',
                  'dragstart', 'dragover', 'drop', 'dragend', 'mousedown'];

    function token(tok, el, ev) {
        switch (tok) {
            case '@value':     return el.value;
            case '@checked':   return el.checked;
            case '@checked01': return el.checked ? 1 : 0;
            case '@prevValue': return el.previousElementSibling ? el.previousElementSibling.value : '';
            case '@event':     return ev;
            case '@self':      return el;
            default:           return tok;
        }
    }

    function args(el, ev, pfx) {
        var out = [];
        for (var i = 1; i <= 4; i++) {
            var v = el.getAttribute('data-' + (pfx || '') + 'a' + i);
            if (v === null) break;
            if (v.charAt(0) === '@')        out.push(token(v, el, ev));
            else if (v === 'true')          out.push(true);
            else if (v === 'false')         out.push(false);
            else if (v !== '' && !isNaN(v)) out.push(Number(v));
            else out.push(v);
        }
        return out;
    }

    function dispatch(name, el, ev, pfx) {
        var fn = window[name];
        if (typeof fn === 'function') return fn.apply(el || null, args(el, ev, pfx));
        // A dead control is otherwise completely silent, which is the whole
        // hazard of this refactor.
        if (window.console) console.warn('[pk-dispatch] no handler named', name);
    }

    // CAPTURE phase throughout. An ancestor that calls stopPropagation() makes a
    // bubble-phase listener on document blind to everything beneath it, and
    // several panels in this app do exactly that. Inline handlers were immune
    // because they run AT the target; capturing restores that ordering.
    document.addEventListener('click', function (e) {
        if (!(e.target instanceof Element)) return;
        if (e.target.hasAttribute('data-act-self')) {
            dispatch(e.target.getAttribute('data-act-self'), e.target, e, '');
            return;
        }
        var t = e.target.closest('[data-act], [data-stop]');
        if (!t || t.hasAttribute('data-stop')) return;
        dispatch(t.getAttribute('data-act'), t, e, '');
    }, true);

    // ── Generic declarative behaviours ───────────────────────────────────
    // These replace idioms that appeared many times over: a confirm-before-
    // submit, a navigation, a class toggle, a file-picker trigger. Naming a
    // function for each occurrence would have meant ~50 near-identical helpers.

    // data-confirm on a <form>: confirm, then submit. pkConfirmForm() submits via
    // formEl.submit(), which does not re-fire the submit event, so no recursion.
    document.addEventListener('submit', function (e) {
        var f = e.target;
        if (!f || !f.dataset || !f.dataset.confirm) return;
        e.preventDefault();
        if (typeof window.pkConfirmForm === 'function') {
            window.pkConfirmForm(f, f.dataset.confirm, {
                okLabel: f.dataset.confirmOk || 'OK',
                danger:  f.dataset.confirmDanger === '1'
            });
        }
    }, true);

    document.addEventListener('click', function (e) {
        if (!(e.target instanceof Element)) return;

        // data-href: a plain navigation that used to be location.href='...'
        var nav = e.target.closest('[data-href]');
        if (nav) { e.preventDefault(); window.location.href = nav.getAttribute('data-href'); return; }

        // data-toggle-class="targetId:className" (className defaults to "open")
        var tog = e.target.closest('[data-toggle-class]');
        if (tog) {
            var spec = tog.getAttribute('data-toggle-class').split(':');
            var el = document.getElementById(spec[0]);
            if (el) el.classList.toggle(spec[1] || 'open');
            return;
        }

        // data-click-file="inputId": trigger a hidden file input
        var cf = e.target.closest('[data-click-file]');
        if (cf) {
            var inp = document.getElementById(cf.getAttribute('data-click-file'));
            if (inp) inp.click();
            return;
        }
    }, true);

    // data-select-all-on-focus: was onclick="this.select()"
    document.addEventListener('focus', function (e) {
        if (e.target instanceof Element && e.target.hasAttribute('data-select-all-on-focus')
            && typeof e.target.select === 'function') e.target.select();
    }, true);

    // data-uppercase: was oninput="this.value=this.value.toUpperCase()"
    document.addEventListener('input', function (e) {
        if (e.target instanceof Element && e.target.hasAttribute('data-uppercase')) {
            e.target.value = e.target.value.toUpperCase();
        }
    }, true);

    EVENTS.forEach(function (evt) {
        document.addEventListener(evt, function (e) {
            if (!(e.target instanceof Element)) return;
            var t = e.target.closest('[data-act-' + evt + ']');
            if (t) dispatch(t.getAttribute('data-act-' + evt), t, e, evt + '-');
        }, true);
    });
})();
