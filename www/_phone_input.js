/**
 * Shared client-side phone auto-formatter.
 *
 * Formats US 10-digit numbers as "(XXX) XXX-XXXX" as the user types. Purely cosmetic —
 * the server canonicalizes to "XXX-XXX-XXXX" on submit via normalize_phone() in db.php,
 * so it doesn't matter what format gets POSTed.
 *
 * Usage:
 *   <script src="/_phone_input.js"></script>
 *   <script>initPhoneAutoFormat();</script>
 *
 * Binds to:
 *   - every input[type="tel"] (always formats)
 *   - every input[data-phone-contact] (combined "Email or phone" fields — only formats
 *     when the value has no "@" and ≥4 digits)
 *
 * International numbers (anything whose digit-strip isn't 10 or 11-with-leading-1) fall
 * through to the user's raw value.
 */
(function(){
    function formatAsUSPhone(raw) {
        const d = (raw || '').replace(/\D/g, '');
        // Drop a leading 1 country code if the user typed 11 digits starting with 1.
        const digits = (d.length === 11 && d[0] === '1') ? d.slice(1) : d;
        if (digits.length === 0) return '';
        if (digits.length < 4)  return digits;
        if (digits.length < 7)  return '(' + digits.slice(0, 3) + ') ' + digits.slice(3);
        // 7–10 digits → full (XXX) XXX-XXXX. More than 10 → truncate silently; lets
        // users clean up accidental extra digits by hitting backspace once.
        const ten = digits.slice(0, 10);
        return '(' + ten.slice(0, 3) + ') ' + ten.slice(3, 6) + '-' + ten.slice(6, 10);
    }

    function onPhoneInput(e) {
        const el = e.target;
        const before = el.value;
        const formatted = formatAsUSPhone(before);
        if (formatted === before) return;
        el.value = formatted;
        // Best-effort caret: park at the end of the current input so typing keeps working.
        try { el.setSelectionRange(formatted.length, formatted.length); } catch (_) {}
    }

    // Combined "Email or phone" fields: skip when the user appears to be typing an email,
    // and only kick in once they've typed ≥4 digits so short strings ("555") aren't
    // prematurely punctuated.
    function onContactInput(e) {
        const v = e.target.value || '';
        if (v.indexOf('@') !== -1) return;
        const digits = v.replace(/\D/g, '');
        if (digits.length < 4) return;
        onPhoneInput(e);
    }

    window.initPhoneAutoFormat = function(rootSelector) {
        const root = (rootSelector ? document.querySelector(rootSelector) : document) || document;
        root.querySelectorAll('input[type="tel"]').forEach(function(el) {
            if (el.dataset.phoneFormatBound === '1') return;
            el.dataset.phoneFormatBound = '1';
            el.addEventListener('input', onPhoneInput);
            if (el.value) el.value = formatAsUSPhone(el.value);
        });
        root.querySelectorAll('input[data-phone-contact]').forEach(function(el) {
            if (el.dataset.phoneFormatBound === '1') return;
            el.dataset.phoneFormatBound = '1';
            el.addEventListener('input', onContactInput);
        });
    };
})();
