/* pk-dialogs.js — in-app replacements for native alert()/confirm()/prompt().
 * Loaded on every page via _footer.php. Promise-based:
 *   pkAlert(message, opts?)            -> Promise (resolves when dismissed)
 *   pkConfirm(message, opts?)          -> Promise<boolean>
 *   pkPrompt(message, opts?)           -> Promise<string|null>
 *   pkConfirmForm(formEl, message, opts?)  for onsubmit="return ..."  (submits form on OK)
 *   pkConfirmGo(anchorEl, message, opts?)  for onclick="return ..."   (navigates on OK)
 *   pkBusy(btn, promise)               -> same promise; disables btn until it settles
 * opts: { title, okLabel, cancelLabel, danger, default, placeholder, inputType }
 * The first arg may be a string (message) or an options object.
 * Reuses the app's .pk-modal-overlay / .pk-modal / .pk-modal-actions / .pk-save styles.
 */
(function () {
    'use strict';

    var overlay, titleEl, msgEl, inputWrap, inputEl, cancelBtn, okBtn;
    var resolver = null;
    var mode = 'confirm';
    var lastFocus = null;

    function injectCss() {
        if (document.getElementById('pk-dialogs-css')) return;
        var s = document.createElement('style');
        s.id = 'pk-dialogs-css';
        // Self-contained so it works on standalone pages (timer/walk-in) and is
        // immune to a stale/missing style.css. High z-index beats page chrome.
        s.textContent =
            '.pk-dialog{position:fixed!important;inset:0!important;z-index:100000!important;' +
                'display:none;align-items:center;justify-content:center;background:rgba(0,0,0,.5)}' +
            '.pk-dialog.open{display:flex!important}' +
            '.pk-dialog .pk-modal{background:#fff;color:#0f172a;border-radius:12px;padding:1.5rem;' +
                'width:90%;max-width:400px;box-shadow:0 10px 40px rgba(0,0,0,.25);max-height:85vh;overflow:auto;text-align:left}' +
            '.pk-dialog .pk-modal h3{margin:0 0 .75rem;color:#0f172a}' +
            '.pk-dialog .pk-modal-actions{display:flex;gap:.5rem;justify-content:flex-end;margin-top:1rem}' +
            '.pk-dialog .pk-modal-actions button{padding:.5rem 1rem;border-radius:6px;font-size:.9rem;font-weight:600;' +
                'cursor:pointer;border:1.5px solid #e2e8f0;background:#fff;color:#0f172a}' +
            '.pk-dialog .pk-modal-actions .pk-save{background:#2563eb;color:#fff;border-color:transparent}';
        document.head.appendChild(s);
    }

    function build() {
        if (overlay) return;
        injectCss();
        overlay = document.createElement('div');
        overlay.className = 'pk-modal-overlay pk-dialog';
        overlay.innerHTML =
            '<div class="pk-modal" role="dialog" aria-modal="true">' +
                '<h3 class="pk-dialog-title"></h3>' +
                '<p class="pk-dialog-msg" style="font-size:.9rem;color:#475569;line-height:1.45;margin:0"></p>' +
                '<div class="pk-dialog-input" style="display:none;margin-top:.6rem">' +
                    '<input class="pk-dialog-field" style="width:100%;padding:.5rem;border:1.5px solid var(--border,#e2e8f0);border-radius:6px;font-size:1rem;box-sizing:border-box">' +
                '</div>' +
                '<div class="pk-modal-actions">' +
                    '<button type="button" class="pk-dialog-cancel">Cancel</button>' +
                    '<button type="button" class="pk-save pk-dialog-ok">OK</button>' +
                '</div>' +
            '</div>';
        document.body.appendChild(overlay);
        titleEl  = overlay.querySelector('.pk-dialog-title');
        msgEl    = overlay.querySelector('.pk-dialog-msg');
        inputWrap = overlay.querySelector('.pk-dialog-input');
        inputEl  = overlay.querySelector('.pk-dialog-field');
        cancelBtn = overlay.querySelector('.pk-dialog-cancel');
        okBtn    = overlay.querySelector('.pk-dialog-ok');

        cancelBtn.addEventListener('click', function () { dismiss(); });
        okBtn.addEventListener('click', function () { submit(); });
        overlay.addEventListener('click', function (e) { if (e.target === overlay) dismiss(); });
        inputEl.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); submit(); } });
        document.addEventListener('keydown', function (e) {
            if (!overlay.classList.contains('open')) return;
            if (e.key === 'Escape') { e.preventDefault(); dismiss(); }
            else if (e.key === 'Enter' && mode !== 'prompt') { e.preventDefault(); submit(); }
        });
    }

    function defaultTitle(m) { return m === 'alert' ? 'Notice' : (m === 'prompt' ? 'Enter a value' : 'Confirm'); }

    function settle(val) {
        overlay.classList.remove('open');
        var r = resolver; resolver = null;
        if (lastFocus && lastFocus.focus) { try { lastFocus.focus(); } catch (e) {} }
        if (r) r(val);
    }
    function dismiss() { settle(mode === 'prompt' ? null : (mode === 'confirm' ? false : undefined)); }
    function submit()  { settle(mode === 'prompt' ? inputEl.value : (mode === 'confirm' ? true : undefined)); }

    function open(o) {
        build();
        mode = o.mode;
        titleEl.innerHTML = (o.title != null ? o.title : defaultTitle(mode));
        titleEl.style.display = (o.title === '') ? 'none' : '';
        msgEl.innerHTML = o.message || '';
        msgEl.style.display = o.message ? '' : 'none';
        okBtn.textContent = o.okLabel || 'OK';
        okBtn.style.background = o.danger ? '#dc2626' : '';
        okBtn.style.borderColor = o.danger ? '#dc2626' : '';
        cancelBtn.textContent = o.cancelLabel || 'Cancel';
        cancelBtn.style.display = (mode === 'alert') ? 'none' : '';
        if (mode === 'prompt') {
            inputWrap.style.display = '';
            inputEl.type = o.inputType || 'text';
            inputEl.value = (o['default'] != null ? o['default'] : '');
            inputEl.placeholder = o.placeholder || '';
        } else {
            inputWrap.style.display = 'none';
        }
        lastFocus = document.activeElement;
        overlay.classList.add('open');
        var focusEl = (mode === 'prompt') ? inputEl : okBtn;
        setTimeout(function () { try { focusEl.focus(); if (mode === 'prompt') inputEl.select(); } catch (e) {} }, 30);
        return new Promise(function (res) { resolver = res; });
    }

    function normalize(arg, extra) {
        var o = (typeof arg === 'object' && arg !== null) ? arg : { message: arg };
        var out = {};
        var k;
        for (k in o) out[k] = o[k];
        if (extra) for (k in extra) if (!(k in out)) out[k] = extra[k];
        return out;
    }

    window.pkAlert   = function (message, opts) { var o = normalize(message, opts); o.mode = 'alert';   return open(o); };
    window.pkConfirm = function (message, opts) { var o = normalize(message, opts); o.mode = 'confirm'; return open(o); };
    window.pkPrompt  = function (message, opts) { var o = normalize(message, opts); o.mode = 'prompt';  return open(o); };

    window.pkConfirmForm = function (formEl, message, opts) {
        var o = normalize(message, opts);
        window.pkConfirm(o.message, o).then(function (ok) { if (ok) formEl.submit(); });
        return false;
    };
    window.pkConfirmGo = function (anchorEl, message, opts) {
        var o = normalize(message, opts);
        window.pkConfirm(o.message, o).then(function (ok) { if (ok && anchorEl.href) window.location.href = anchorEl.href; });
        return false;
    };

    // Disable a button while an async action is in flight, then restore it.
    // Prevents double-submits and gives slow-network feedback. Returns the same
    // promise so it composes: pkBusy(btn, fetch(...).then(...)).catch(...)
    window.pkBusy = function (btn, promise) {
        if (!btn || !promise || typeof promise.then !== 'function') return promise;
        var wasDisabled = btn.disabled;
        btn.disabled = true;
        btn.setAttribute('aria-busy', 'true');
        btn.style.opacity = '.6';
        var restore = function () {
            btn.disabled = wasDisabled;
            btn.removeAttribute('aria-busy');
            btn.style.opacity = '';
        };
        promise.then(restore, restore);
        return promise;
    };
})();
