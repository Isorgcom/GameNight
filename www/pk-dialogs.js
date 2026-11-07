/* pk-dialogs.js — in-app replacements for native alert()/confirm()/prompt().
 * Loaded on every page via _footer.php. Promise-based:
 *   pkAlert(message, opts?)            -> Promise (resolves when dismissed)
 *   pkConfirm(message, opts?)          -> Promise<boolean>
 *   pkPrompt(message, opts?)           -> Promise<string|null>
 *   pkMoney(message, opts?)            -> Promise<cents|null>; a keypad instead of a
 *                                         text field: digits, bills that ADD to the
 *                                         amount, multiples of a buy-in that SET it.
 *                                         No <input>, so a phone never raises its own
 *                                         keyboard. opts: { title, okLabel (the verb;
 *                                         the amount is appended), default (cents, or
 *                                         null for empty), buyin (cents; 0 hides the
 *                                         multiples row), bills (dollars, default
 *                                         [5,10,20,50,100]; [] hides the row),
 *                                         allowZero }
 *   pkConfirmForm(formEl, message, opts?)  for onsubmit="return ..."  (submits form on OK)
 *   pkConfirmGo(anchorEl, message, opts?)  for onclick="return ..."   (navigates on OK)
 *   pkBusy(btn, promise)               -> same promise; disables btn until it settles
 *   pkCopy(text)                       -> Promise<boolean>; clipboard write that
 *                                         also works outside a secure context
 *   pkProgress(title?, msg?)           -> handle {done()} — full-screen "Saving…"
 *                                         card with an easing progress bar; call
 *                                         .done() (or pkProgressDone()) when the
 *                                         work finishes or fails
 * opts: { title, okLabel, cancelLabel, danger, default, placeholder, inputType }
 * A number prompt (inputType 'number') also carries inputmode="decimal", so a
 * phone shows its decimal pad rather than the full keyboard.
 * The first arg may be a string (message) or an options object.
 * Reuses the app's .pk-modal-overlay / .pk-modal / .pk-modal-actions / .pk-save styles.
 */
(function () {
    'use strict';

    var overlay, titleEl, msgEl, inputWrap, inputEl, cancelBtn, okBtn;
    var moneyWrap, moneyVal, moneyAmt, moneyMults, moneyBills;
    var resolver = null;
    var mode = 'confirm';
    var lastFocus = null;
    var openedAt = 0;   // the keydown that opened a dialog must not also answer it

    // ── Money keypad state (mode 'money') ──
    // mStr is the amount as typed ("45", "45.5", ""); mFresh means it came from
    // the default, a bill or a multiple, so the next digit REPLACES it and one
    // backspace clears it, the way a calculator treats a result.
    var mStr = '', mFresh = true, mOpts = {};

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
            '.pk-dialog .pk-modal-actions .pk-save{background:#2563eb;color:#fff;border-color:transparent}' +
            '.pk-dialog .pk-modal-actions .pk-save:disabled{opacity:.5;cursor:default}' +
            // A quick second tap on a key (+$20, +$20) must be a second tap, not a
            // double-tap zoom; manipulation keeps pinch-zoom and scrolling.
            '.pk-dialog .pk-modal button{touch-action:manipulation}' +
            // The money keypad. Keys 46px tall for a thumb; the lit multiple is the
            // accent; bills read green; no transitions, so nothing to reduce.
            '.pk-money{margin-top:.7rem}' +
            '.pk-money-amt{display:flex;align-items:baseline;gap:.25rem;border:1.5px solid #cbd5e1;border-radius:10px;' +
                'padding:.45rem .8rem;font-size:1.7rem;font-weight:800;font-variant-numeric:tabular-nums;color:#0f172a;' +
                'background:#f8fafc;min-height:2.6rem;box-sizing:border-box;overflow:hidden}' +
            '.pk-money-amt .pk-money-cur{color:#64748b;font-size:1.1rem;margin-right:auto}' +
            '.pk-money-amt .pk-money-val{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}' +
            '.pk-money-amt.empty .pk-money-val{color:#64748b;font-weight:600}' +
            '.pk-money-mults,.pk-money-bills{display:flex;gap:.35rem;margin-top:.5rem}' +
            '.pk-money-chip{flex:1 1 0;min-width:0;min-height:40px;border:1.5px solid #cbd5e1;border-radius:10px;background:#fff;' +
                'color:#334155;font-weight:700;font-size:.82rem;padding:0 .2rem;cursor:pointer;white-space:nowrap;overflow:hidden;' +
                'text-overflow:ellipsis;-webkit-tap-highlight-color:transparent}' +
            '.pk-money-chip.pk-money-bill{color:#15803d}' +
            '.pk-money-chip.on{background:#2563eb;border-color:#2563eb;color:#fff}' +
            '.pk-money-pad{display:grid;grid-template-columns:repeat(3,1fr);gap:.35rem;margin-top:.5rem}' +
            '.pk-money-key{min-height:46px;border:1.5px solid #cbd5e1;border-radius:10px;background:#fff;color:#334155;' +
                'font-size:1.25rem;font-weight:700;cursor:pointer;-webkit-tap-highlight-color:transparent}' +
            '.pk-money-key:active,.pk-money-chip:active{background:#f1f5f9}' +
            '.pk-money-chip.on:active{background:#1d4ed8}' +
            '.pk-money-key:focus-visible,.pk-money-chip:focus-visible{outline:2px solid #2563eb;outline-offset:1px}' +
            '.pk-dialog.pk-dialog-money .pk-modal-actions button{flex:1;min-height:46px;font-size:1rem}' +
            '@media (max-height:600px){.pk-money-key{min-height:40px;font-size:1.1rem}.pk-money-chip{min-height:36px}' +
                '.pk-money-amt{font-size:1.4rem;min-height:2.2rem}.pk-dialog.pk-dialog-money .pk-modal{padding:1rem}}' +
            '.pk-progress{position:fixed!important;inset:0!important;z-index:100001!important;display:none;' +
                'align-items:center;justify-content:center;background:rgba(15,23,42,.55);padding:1rem}' +
            '.pk-progress.open{display:flex!important}' +
            '.pk-progress-card{background:#fff;border-radius:14px;padding:1.75rem 2rem;max-width:360px;width:100%;' +
                'text-align:center;box-shadow:0 16px 48px rgba(0,0,0,.3)}' +
            '.pk-progress-title{font-size:1.05rem;font-weight:700;color:#1e293b;margin-bottom:.4rem}' +
            '.pk-progress-msg{font-size:.85rem;color:#64748b;margin-bottom:1.1rem}' +
            '.pk-progress-track{height:9px;background:#e2e8f0;border-radius:99px;overflow:hidden}' +
            '.pk-progress-bar{height:100%;width:8%;background:#16a34a;border-radius:99px;transition:width .35s ease}';
        document.head.appendChild(s);
    }

    // ── Full-screen progress card (the event editor's save animation, shared) ──
    // Shows immediately and stays up for a MINIMUM display time: a fast save
    // still plays a complete sweep-to-100% animation instead of a one-frame
    // flash, and a slow one animates until done() is called.
    var PROG_MIN_MS = 700;
    var progOverlay = null, progBar = null, progIv = null, progShownAt = 0;
    function _progFinish() {
        if (progIv) { clearInterval(progIv); progIv = null; }
        if (!progOverlay) return;
        if (progBar) progBar.style.width = '100%';
        setTimeout(function () { if (progOverlay) progOverlay.classList.remove('open'); }, 300);
    }
    function pkProgressDone() {
        if (!progOverlay || !progOverlay.classList.contains('open')) return;
        var elapsed = Date.now() - progShownAt;
        if (elapsed >= PROG_MIN_MS) { _progFinish(); }
        else { setTimeout(_progFinish, PROG_MIN_MS - elapsed); }
    }
    function pkProgress(title, msg) {
        injectCss();
        if (!progOverlay) {
            progOverlay = document.createElement('div');
            progOverlay.className = 'pk-progress';
            progOverlay.setAttribute('role', 'status');
            progOverlay.setAttribute('aria-live', 'polite');
            progOverlay.innerHTML =
                '<div class="pk-progress-card">' +
                    '<div class="pk-progress-title"></div>' +
                    '<div class="pk-progress-msg"></div>' +
                    '<div class="pk-progress-track"><div class="pk-progress-bar"></div></div>' +
                '</div>';
            document.body.appendChild(progOverlay);
            progBar = progOverlay.querySelector('.pk-progress-bar');
        }
        progOverlay.querySelector('.pk-progress-title').textContent = title || 'Saving…';
        progOverlay.querySelector('.pk-progress-msg').textContent = msg || 'Please wait.';
        if (progIv) { clearInterval(progIv); progIv = null; }
        progShownAt = Date.now();
        var pct = 8;
        progBar.style.width = pct + '%';
        progOverlay.classList.add('open');
        progIv = setInterval(function () {
            pct += Math.max(1, (92 - pct) * 0.12);
            if (pct >= 92) { pct = 92; clearInterval(progIv); progIv = null; }
            progBar.style.width = pct + '%';
        }, 180);
        return { done: pkProgressDone };
    }
    window.pkProgress = pkProgress;
    window.pkProgressDone = pkProgressDone;

    function build() {
        if (overlay) return;
        injectCss();
        overlay = document.createElement('div');
        overlay.className = 'pk-modal-overlay pk-dialog';
        overlay.innerHTML =
            '<div class="pk-modal" role="dialog" aria-modal="true" aria-labelledby="pkDialogTitle" aria-describedby="pkDialogMsg">' +
                '<h3 class="pk-dialog-title" id="pkDialogTitle"></h3>' +
                '<p class="pk-dialog-msg" id="pkDialogMsg" style="font-size:.9rem;color:#475569;line-height:1.45;margin:0"></p>' +
                '<div class="pk-dialog-input" style="display:none;margin-top:.6rem">' +
                    '<input class="pk-dialog-field" style="width:100%;padding:.5rem;border:1.5px solid var(--border,#e2e8f0);border-radius:6px;font-size:1rem;box-sizing:border-box">' +
                '</div>' +
                '<div class="pk-money" style="display:none">' +
                    '<div class="pk-money-amt" role="status" aria-live="polite" aria-label="Amount">' +
                        '<span class="pk-money-cur" aria-hidden="true">$</span><span class="pk-money-val">0</span>' +
                    '</div>' +
                    '<div class="pk-money-mults"></div>' +
                    '<div class="pk-money-bills"></div>' +
                    '<div class="pk-money-pad">' +
                        '<button type="button" class="pk-money-key" data-k="1">1</button>' +
                        '<button type="button" class="pk-money-key" data-k="2">2</button>' +
                        '<button type="button" class="pk-money-key" data-k="3">3</button>' +
                        '<button type="button" class="pk-money-key" data-k="4">4</button>' +
                        '<button type="button" class="pk-money-key" data-k="5">5</button>' +
                        '<button type="button" class="pk-money-key" data-k="6">6</button>' +
                        '<button type="button" class="pk-money-key" data-k="7">7</button>' +
                        '<button type="button" class="pk-money-key" data-k="8">8</button>' +
                        '<button type="button" class="pk-money-key" data-k="9">9</button>' +
                        '<button type="button" class="pk-money-key" data-k="." aria-label="Decimal point">.</button>' +
                        '<button type="button" class="pk-money-key" data-k="0">0</button>' +
                        '<button type="button" class="pk-money-key" data-k="bs" aria-label="Backspace">&#9003;</button>' +
                    '</div>' +
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
        moneyWrap  = overlay.querySelector('.pk-money');
        moneyAmt   = overlay.querySelector('.pk-money-amt');
        moneyVal   = overlay.querySelector('.pk-money-val');
        moneyMults = overlay.querySelector('.pk-money-mults');
        moneyBills = overlay.querySelector('.pk-money-bills');

        cancelBtn.addEventListener('click', function () { dismiss(); });
        okBtn.addEventListener('click', function () { submit(); });
        overlay.addEventListener('click', function (e) { if (e.target === overlay) dismiss(); });
        inputEl.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); submit(); } });
        // One delegated listener serves every key and chip (no inline handlers:
        // the CSP is closed).
        moneyWrap.addEventListener('click', function (e) {
            var b = e.target.closest ? e.target.closest('button[data-k]') : null;
            if (!b || !moneyWrap.contains(b)) return;
            moneyKey(b.getAttribute('data-k'));
        });
        document.addEventListener('keydown', function (e) {
            if (!overlay.classList.contains('open')) return;
            // A dialog raised from inside a keydown (Escape in Setup asks "Discard?")
            // sees that same event here, after the opener's listener ran. Skip it.
            if (e.timeStamp && e.timeStamp <= openedAt) return;
            if (e.key === 'Escape') { e.preventDefault(); dismiss(); }
            else if (mode === 'money' && /^[0-9.]$/.test(e.key) && !e.ctrlKey && !e.metaKey && !e.altKey) {
                // A physical keyboard types into the pad.
                e.preventDefault(); moneyKey(e.key);
            } else if (mode === 'money' && e.key === 'Backspace') {
                e.preventDefault(); moneyKey('bs');
            } else if (e.key === 'Enter' && mode !== 'prompt') {
                // Enter means the button that has focus: a focused Cancel
                // cancels. It used to confirm from anywhere, Cancel included.
                // A focused keypad key keeps its native click (Enter = tap it).
                if (mode === 'money' && document.activeElement && document.activeElement !== okBtn
                        && document.activeElement !== cancelBtn && moneyWrap.contains(document.activeElement)) return;
                e.preventDefault();
                if (document.activeElement === cancelBtn) dismiss(); else submit();
            } else if (e.key === 'Tab') {
                // Tab stays inside the dialog: input (prompts), Cancel, OK;
                // on the keypad, every key and chip first.
                var ring = [];
                if (mode === 'prompt') ring.push(inputEl);
                if (mode === 'money') {
                    Array.prototype.forEach.call(moneyWrap.querySelectorAll('button'), function (b) {
                        if (!b.disabled && b.offsetParent !== null) ring.push(b);
                    });
                }
                if (mode !== 'alert') ring.push(cancelBtn);
                if (!okBtn.disabled) ring.push(okBtn);
                var at = ring.indexOf(document.activeElement);
                if (e.shiftKey && at <= 0) { e.preventDefault(); ring[ring.length - 1].focus(); }
                else if (!e.shiftKey && (at === -1 || at === ring.length - 1)) { e.preventDefault(); ring[0].focus(); }
            }
        });
    }

    function defaultTitle(m) {
        return m === 'alert' ? 'Notice' : (m === 'prompt' ? 'Enter a value' : (m === 'money' ? 'Amount' : 'Confirm'));
    }

    function settle(val) {
        overlay.classList.remove('open');
        var r = resolver; resolver = null;
        if (lastFocus && lastFocus.focus) { try { lastFocus.focus(); } catch (e) {} }
        if (r) r(val);
    }
    function dismiss() {
        settle(mode === 'prompt' || mode === 'money' ? null : (mode === 'confirm' ? false : undefined));
    }
    function submit() {
        if (mode === 'money') {
            var c = moneyCents();
            if (c === null || (c === 0 && !mOpts.allowZero)) return;   // the button is disabled; Enter lands here too
            settle(c);
            return;
        }
        settle(mode === 'prompt' ? inputEl.value : (mode === 'confirm' ? true : undefined));
    }

    // ── The money keypad ─────────────────────────────────────────────────────
    function moneyFmt(cents) {
        var d = Math.floor(cents / 100), c = cents % 100;
        return '$' + d.toLocaleString() + (c ? '.' + (c < 10 ? '0' : '') + c : '');
    }
    // "45" / "45.5" / "" -> cents or null. The string only ever holds digits
    // and at most one point, so parseFloat is safe here.
    function moneyCents() {
        if (mStr === '' || mStr === '.') return null;
        var n = parseFloat(mStr);
        return isNaN(n) ? null : Math.round(n * 100);
    }
    function centsToStr(cents) {
        cents = Math.max(0, Math.round(cents));
        var d = Math.floor(cents / 100), c = cents % 100;
        return String(d) + (c ? '.' + (c < 10 ? '0' : '') + c : '');
    }
    function moneyKey(k) {
        if (k === 'bs') {
            if (mFresh) { mStr = ''; mFresh = false; }
            else mStr = mStr.slice(0, -1);
        } else if (k === '.') {
            if (mFresh) { mStr = '0.'; mFresh = false; }
            else if (mStr.indexOf('.') === -1) mStr = (mStr === '' ? '0' : mStr) + '.';
        } else if (/^[0-9]$/.test(k)) {
            if (mFresh) { mStr = k; mFresh = false; }
            else {
                var dot = mStr.indexOf('.');
                if (dot !== -1) { if (mStr.length - dot - 1 < 2) mStr += k; }
                else if (mStr === '0') mStr = k;
                else if (mStr.length < 7) mStr += k;
            }
        } else if (k.indexOf('bill:') === 0) {
            var cur = moneyCents() || 0;
            mStr = centsToStr(cur + (parseInt(k.slice(5), 10) || 0));
            mFresh = true;
        } else if (k.indexOf('mult:') === 0) {
            mStr = centsToStr(parseInt(k.slice(5), 10) || 0);
            mFresh = true;
        }
        syncMoney();
    }
    // The display with thousands separators, the lit multiple, the OK button's
    // label ("Add $40") and whether it may be pressed.
    function syncMoney() {
        var c = moneyCents();
        var shown = mStr;
        if (shown !== '') {
            var parts = shown.split('.');
            parts[0] = String(parseInt(parts[0] || '0', 10)).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
            shown = parts.join('.');
        }
        moneyVal.textContent = shown === '' ? '0' : shown;
        moneyAmt.classList.toggle('empty', shown === '');
        var canOk = c !== null && (c > 0 || !!mOpts.allowZero);
        okBtn.disabled = !canOk;
        okBtn.textContent = (mOpts.okLabel || 'OK') + (c !== null ? ' ' + moneyFmt(c) : '');
        Array.prototype.forEach.call(moneyMults.querySelectorAll('button'), function (b) {
            b.classList.toggle('on', c !== null && b.getAttribute('data-k') === 'mult:' + c);
            b.setAttribute('aria-pressed', b.classList.contains('on') ? 'true' : 'false');
        });
    }
    function moneyChip(k, text, label, extraClass) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'pk-money-chip' + (extraClass ? ' ' + extraClass : '');
        b.setAttribute('data-k', k);
        b.setAttribute('aria-label', label);
        b.textContent = text;
        return b;
    }
    function moneyOpen(o) {
        mOpts = o;
        var def = (o['default'] == null) ? null : Math.max(0, Math.round(parseInt(o['default'], 10) || 0));
        mStr = def === null ? '' : centsToStr(def);
        mFresh = true;
        moneyMults.innerHTML = '';
        moneyBills.innerHTML = '';
        var buyin = parseInt(o.buyin, 10) || 0;
        if (buyin > 0) {
            for (var n = 1; n <= 4; n++) {
                var v = buyin * n;
                moneyMults.appendChild(moneyChip('mult:' + v,
                    n === 1 ? moneyFmt(v) : n + '× ' + moneyFmt(v),
                    n === 1 ? 'The buy-in, ' + moneyFmt(v) : n + ' times the buy-in, ' + moneyFmt(v)));
            }
        }
        var bills = Array.isArray(o.bills) ? o.bills : [5, 10, 20, 50, 100];
        bills.forEach(function (dollars) {
            var cents = Math.round(parseFloat(dollars) * 100);
            if (!(cents > 0)) return;
            moneyBills.appendChild(moneyChip('bill:' + cents, '+' + moneyFmt(cents), 'Add ' + moneyFmt(cents), 'pk-money-bill'));
        });
        moneyMults.style.display = moneyMults.children.length ? '' : 'none';
        moneyBills.style.display = moneyBills.children.length ? '' : 'none';
        syncMoney();
    }

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
        okBtn.disabled = false;
        if (mode === 'prompt') {
            inputWrap.style.display = '';
            inputEl.type = o.inputType || 'text';
            // A phone shows its decimal pad for inputmode="decimal"; a bare
            // type="number" gets the full numbers-and-punctuation keyboard on iOS.
            if (o.inputType === 'number') { inputEl.inputMode = 'decimal'; inputEl.step = 'any'; }
            else { inputEl.inputMode = ''; inputEl.removeAttribute('step'); }
            inputEl.value = (o['default'] != null ? o['default'] : '');
            inputEl.placeholder = o.placeholder || '';
        } else {
            inputWrap.style.display = 'none';
        }
        if (mode === 'money') {
            moneyWrap.style.display = '';
            overlay.classList.add('pk-dialog-money');
            moneyOpen(o);
        } else {
            moneyWrap.style.display = 'none';
            overlay.classList.remove('pk-dialog-money');
        }
        lastFocus = document.activeElement;
        openedAt = (typeof performance !== 'undefined' && performance.now) ? performance.now() : Date.now();
        overlay.classList.add('open');
        var focusEl = (mode === 'prompt') ? inputEl : okBtn;
        // The exact buy-in is the common case, so OK takes focus and Enter adds
        // it; with nothing to confirm yet (Cash out opens empty) the first key does.
        if (mode === 'money' && okBtn.disabled) focusEl = moneyWrap.querySelector('.pk-money-key');
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
    window.pkMoney   = function (message, opts) { var o = normalize(message, opts); o.mode = 'money';   return open(o); };

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

    // ── pkCopy ───────────────────────────────────────────────────────────────
    // Copy text to the clipboard and report honestly whether it worked.
    //
    // navigator.clipboard is UNDEFINED outside a secure context — anything
    // served over plain http:// that isn't localhost, which includes the app on
    // a LAN address. So `navigator.clipboard.writeText(x)` throws a SYNCHRONOUS
    // TypeError there, not a rejected promise, and a trailing .catch() never
    // runs. Every copy button written that way failed in total silence, leaving
    // whatever was already on the clipboard for the user to paste.
    //
    // Falls back to a hidden textarea + execCommand('copy'), which is not
    // secure-context gated. That path must be called from a user gesture (all
    // callers are click handlers, so it is).
    function copyViaTextarea(text) {
        try {
            var ta = document.createElement('textarea');
            ta.value = text;
            ta.setAttribute('readonly', '');
            // Off-screen but focusable; display:none would not be selectable.
            ta.style.position = 'fixed';
            ta.style.top = '0';
            ta.style.left = '0';
            ta.style.width = '1px';
            ta.style.height = '1px';
            ta.style.padding = '0';
            ta.style.border = 'none';
            ta.style.opacity = '0';
            document.body.appendChild(ta);

            // Preserve whatever the user had selected on the page.
            var sel = document.getSelection();
            var prev = (sel && sel.rangeCount) ? sel.getRangeAt(0) : null;

            ta.select();
            ta.setSelectionRange(0, ta.value.length);   // iOS needs the range
            var ok = false;
            try { ok = document.execCommand('copy'); } catch (e) { ok = false; }

            document.body.removeChild(ta);
            if (prev && sel) { sel.removeAllRanges(); sel.addRange(prev); }
            return !!ok;
        } catch (e) {
            return false;
        }
    }

    window.pkCopy = function (text) {
        text = String(text == null ? '' : text);
        if (window.isSecureContext && navigator.clipboard && navigator.clipboard.writeText) {
            try {
                return navigator.clipboard.writeText(text).then(
                    function () { return true; },
                    function () { return copyViaTextarea(text); }   // denied permission
                );
            } catch (e) { /* fall through to the textarea path */ }
        }
        return Promise.resolve(copyViaTextarea(text));
    };
})();
