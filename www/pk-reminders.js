/* Reminder rows (v1.20.0). A host sets each reminder as a number and a unit:
 * "2 days before", "8 hours before", "30 minutes before". Every
 *
 *   <div class="pk-rem-list" data-name="reminder_offsets[]"
 *        data-offsets="2880,720" data-max="6"></div>
 *
 * on the page gets one row per offset (amount, unit, ×), a "+ Add reminder"
 * button and a hint shown when no row is left. Each row carries a hidden
 * input under data-name holding its value in MINUTES, so the plain form
 * posts the same array the old preset checklist did and the server side
 * (reminder_offsets_clean() in db.php) is unchanged in shape. The event
 * editor (event_edit.php, the Send reminders panel) and the admin's site
 * default (admin_settings.php) share this file.
 *
 * Delegated listeners on the list, no data-act controls, so nothing here
 * goes through the dispatcher. The list fires "pk-rem-change" (bubbles)
 * after any edit, add or remove; a page listens for its summary and its
 * dirty marker. pkReminders.set() may be called before DOMContentLoaded
 * (event_edit.php applies the event's own list at parse time): it builds
 * the controls on first touch, and the DOMContentLoaded pass then leaves a
 * list that was set explicitly alone, even an empty one.
 */
(function () {
    'use strict';
    // Largest first: a stored value is shown in the largest unit that divides
    // it exactly (10080 → weeks, 1440 → days, 60 → hours, else minutes).
    var UNITS = [
        { m: 10080, name: 'weeks',   short: 'wk'  },
        { m: 1440,  name: 'days',    short: 'day' },
        { m: 60,    name: 'hours',   short: 'hr'  },
        { m: 1,     name: 'minutes', short: 'min' }
    ];
    var MAX_MIN = 40320; // 28 days, the server's cap
    var ADD_DEFAULT = 60; // a fresh row reads "1 hours before"

    function unitFor(minutes) {
        for (var i = 0; i < UNITS.length; i++) {
            if (minutes >= UNITS[i].m && minutes % UNITS[i].m === 0) return UNITS[i];
        }
        return UNITS[UNITS.length - 1];
    }
    // "2 days", "8 hr", "30 min", "1 wk": short units, only "day" pluralised.
    function label(minutes) {
        var u = unitFor(minutes), n = minutes / u.m;
        return n + ' ' + (u.short === 'day' && n !== 1 ? 'days' : u.short);
    }
    // data-offsets is a comma list of minutes ("2880,720"): an attribute
    // carries data, never JSON (SECURITY.md, the declarative-markup sweep).
    function parseOffsets(raw) {
        return String(raw || '').split(',').map(function (s) { return parseInt(s, 10); }).filter(function (n) { return n > 0; });
    }
    function cleanList(offsets, max) {
        var seen = {}, out = [];
        (offsets || []).forEach(function (x) {
            var n = parseInt(x, 10);
            if (n > 0 && n <= MAX_MIN && !seen[n]) { seen[n] = true; out.push(n); }
        });
        out.sort(function (a, b) { return b - a; });
        return out.slice(0, max);
    }
    function rowsOf(list) { return Array.prototype.slice.call(list.querySelectorAll('.pk-rem-row')); }
    function maxOf(list) { return parseInt(list.getAttribute('data-max'), 10) || 6; }
    function addBtn(list) { return list.querySelector('.pk-rem-add'); }

    function makeRow(list, minutes) {
        var u = unitFor(minutes);
        var row = document.createElement('div'); row.className = 'pk-rem-row';
        var n = document.createElement('input');
        n.type = 'number'; n.className = 'pk-rem-n'; n.min = '1'; n.step = '1';
        n.max = String(Math.floor(MAX_MIN / u.m));
        n.setAttribute('inputmode', 'numeric'); n.setAttribute('autocomplete', 'off');
        n.value = String(minutes / u.m);
        var sel = document.createElement('select'); sel.className = 'pk-rem-u';
        for (var i = UNITS.length - 1; i >= 0; i--) {
            var o = document.createElement('option');
            o.value = String(UNITS[i].m); o.textContent = UNITS[i].name;
            if (UNITS[i].m === u.m) o.selected = true;
            sel.appendChild(o);
        }
        var before = document.createElement('span'); before.className = 'pk-rem-before'; before.textContent = 'before';
        var x = document.createElement('button'); x.type = 'button'; x.className = 'pk-rem-x';
        x.textContent = '×'; x.setAttribute('aria-label', 'Remove this reminder');
        var hid = document.createElement('input'); hid.type = 'hidden';
        hid.name = list.getAttribute('data-name'); hid.value = String(minutes);
        row.appendChild(n); row.appendChild(sel); row.appendChild(before); row.appendChild(x); row.appendChild(hid);
        return row;
    }

    // The hidden input follows amount × unit. A blank or zero amount disables
    // it so the server never sees 0; an amount over the 28-day cap is sent
    // clamped and, once the field is left (clamp = true), written back.
    function syncRow(row, clamp) {
        var n = row.querySelector('.pk-rem-n'), sel = row.querySelector('.pk-rem-u'), hid = row.querySelector('input[type=hidden]');
        var unit = parseInt(sel.value, 10) || 1;
        var cap = Math.floor(MAX_MIN / unit);
        n.max = String(cap);
        var amount = parseInt(n.value, 10);
        if (!(amount > 0)) { hid.disabled = true; hid.value = ''; return 0; }
        if (amount > cap) { amount = cap; if (clamp) n.value = String(cap); }
        hid.disabled = false; hid.value = String(amount * unit);
        return amount * unit;
    }
    function refresh(list) {
        var rows = rowsOf(list), max = maxOf(list);
        rows.forEach(function (row, i) {
            row.querySelector('.pk-rem-n').setAttribute('aria-label', 'Reminder ' + (i + 1) + ': amount');
            row.querySelector('.pk-rem-u').setAttribute('aria-label', 'Reminder ' + (i + 1) + ': unit');
        });
        var add = addBtn(list), empty = list.querySelector('.pk-rem-empty');
        if (add) {
            add.disabled = rows.length >= max;
            add.title = rows.length >= max ? 'Up to ' + max + ' reminders' : '';
        }
        if (empty) empty.hidden = rows.length > 0;
    }
    function fire(list) {
        var ev;
        try { ev = new CustomEvent('pk-rem-change', { bubbles: true }); }
        catch (e) { ev = document.createEvent('Event'); ev.initEvent('pk-rem-change', true, false); }
        list.dispatchEvent(ev);
    }

    // The controls and listeners, once per list; the rows come from set().
    function ensure(list) {
        if (list._pkRem) return; list._pkRem = true;
        var add = document.createElement('button'); add.type = 'button'; add.className = 'pk-rem-add';
        add.textContent = '+ Add reminder';
        var empty = document.createElement('p'); empty.className = 'pk-rem-empty'; empty.hidden = true;
        empty.textContent = list.getAttribute('data-empty') || 'No reminders will be sent. Add one, or turn Reminders off.';
        list.appendChild(add); list.appendChild(empty);

        list.addEventListener('input', function (e) {
            var row = e.target.closest('.pk-rem-row'); if (!row) return;
            syncRow(row, false); fire(list);
        });
        list.addEventListener('change', function (e) {
            var row = e.target.closest('.pk-rem-row'); if (!row) return;
            syncRow(row, true); fire(list);
        });
        list.addEventListener('click', function (e) {
            var t = e.target;
            if (t.closest('.pk-rem-x')) {
                // Focus moves to "+ Add reminder" BEFORE the row goes: a focused
                // button removed from the page drops focus on the body, and the
                // editor's dropdown closes on focus leaving it. The row itself
                // goes once this click has finished bubbling: the editor's
                // close-on-outside-click handler on the document tests whether
                // the dropdown contains the click's target, and a target already
                // detached reads as outside.
                var a = addBtn(list); if (a) { a.disabled = false; a.focus(); }
                var row = t.closest('.pk-rem-row');
                setTimeout(function () {
                    if (row && row.parentNode) row.parentNode.removeChild(row);
                    refresh(list); fire(list);
                }, 0);
            } else if (t.closest('.pk-rem-add')) {
                if (rowsOf(list).length >= maxOf(list)) return;
                var r = makeRow(list, ADD_DEFAULT);
                list.insertBefore(r, addBtn(list));
                // Focus the new amount BEFORE refresh() may disable the add
                // button at the cap: disabling the focused button drops focus
                // on the body, and the editor's dropdown closes on that.
                var n = r.querySelector('.pk-rem-n'); if (n) { n.focus(); n.select(); }
                refresh(list); fire(list);
            }
        });
    }
    // Replace the rows with this list (minutes); the page owns the summary.
    function set(list, offsets) {
        ensure(list); list._pkRemSet = true;
        rowsOf(list).forEach(function (r) { r.parentNode.removeChild(r); });
        var add = addBtn(list);
        cleanList(offsets, maxOf(list)).forEach(function (m) { list.insertBefore(makeRow(list, m), add); });
        refresh(list);
    }
    // The rows as minutes: unique, largest first, blank rows left out.
    function get(list) {
        return cleanList(rowsOf(list).map(function (row) { return syncRow(row, false); }), maxOf(list));
    }
    function summary(offsets) {
        var a = cleanList(offsets, 99);
        return a.length ? a.map(label).join(', ') : 'None';
    }
    function init(list) {
        ensure(list);
        if (!list._pkRemSet) set(list, parseOffsets(list.getAttribute('data-offsets')));
    }
    function initAll() { document.querySelectorAll('.pk-rem-list[data-name]').forEach(init); }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initAll); else initAll();

    window.pkReminders = { init: init, set: set, get: get, summary: summary, label: label };
})();
