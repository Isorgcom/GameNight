/* finaltable.js — the event page's FinalTable panel.
 *
 * Loaded by event.php when the event is played online, after a nonced config
 * block sets window.FT {eventId, csrf, canManage, appName, status, pollMs}.
 * Polls finaltable_dl.php?action=state while the game is registering or
 * running (visible tab only), renders the status line, the manager controls
 * and the seated entrants, and drives the table through the same endpoint.
 * Every control is a data-act attribute dispatched by pk-dispatch.js to the
 * window.ft* handlers below; nothing here is inline.
 */
(function () {
    'use strict';
    var C = window.FT;
    if (!C) return;
    var timer = null;

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function el(id) { return document.getElementById(id); }
    function fd(action, extra) {
        var f = new FormData();
        f.append('csrf_token', C.csrf);
        f.append('action', action);
        f.append('event_id', C.eventId);
        for (var k in (extra || {})) f.append(k, extra[k]);
        return f;
    }
    function post(action, extra) {
        return fetch('/finaltable_dl.php', { method: 'POST', body: fd(action, extra), credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json(); });
    }
    function mmss(sec) {
        sec = Math.max(0, parseInt(sec, 10) || 0);
        var m = Math.floor(sec / 60), s = sec % 60;
        return m + ':' + (s < 10 ? '0' : '') + s;
    }
    function localTime(ms) {
        try { return new Date(ms).toLocaleString([], { weekday: 'short', hour: 'numeric', minute: '2-digit' }); } catch (e) { return ''; }
    }
    function toast(msg) { if (typeof evToast === 'function') evToast(msg); }

    // The one line that says how the game is going. `live` is FinalTable's
    // GET answer (fresh or cached); `ls` is the last webhook this side kept.
    function statusLine(g, live, err) {
        var ls = g.last_status || {};
        var parts = [];
        if (g.status === 'registering') {
            var at = live && live.startsAt ? localTime(live.startsAt) : '';
            parts.push('Registering' + (at ? ' &mdash; starts ' + esc(at) : ''));
            if (live && live.entrants) parts.push(live.entrants.length + ' seated');
        } else if (g.status === 'running') {
            var lvl = live && live.level ? live.level : (ls.level || 0);
            var bl = ls.blinds || null;
            parts.push('Level ' + lvl + (ls.on_break ? ' (break)' : ''));
            if (bl) parts.push(bl.sb + '/' + bl.bb + (bl.ante ? ' (' + bl.ante + ')' : ''));
            if (live && live.nextLevelIn != null && !(live.paused)) parts.push('next level in ' + mmss(live.nextLevelIn));
            var left = live ? live.remaining : ls.remaining;
            if (left != null) parts.push(left + ' left');
            if (live && live.paused) parts.push('<b>Paused</b>');
            else if (live && live.awayHeld) parts.push('<b>The room is empty</b> &mdash; the field is being held');
        } else if (g.status === 'finished') {
            var w = ls.winner && ls.winner.name ? ls.winner.name : '';
            parts.push('Finished' + (w ? ' &mdash; <b>' + esc(w) + '</b> won' : ''));
        } else if (g.status === 'cancelled') {
            parts.push('Called off' + (g.reason ? ': ' + esc(g.reason) : ''));
        }
        var s = parts.join(' &middot; ');
        if (err) s += '<div class="ft-note" style="color:#b45309">' + esc(err) + '</div>';
        return s;
    }

    function renderControls(g, live) {
        if (!C.canManage) return;
        var start = el('ftBtnStart'), pause = el('ftBtnPause'), resume = el('ftBtnResume');
        if (!start) return;
        var reg = g.status === 'registering', run = g.status === 'running';
        var paused = !!(live && live.paused);
        start.style.display  = reg ? '' : 'none';
        pause.style.display  = run && !paused ? '' : 'none';
        resume.style.display = run && paused ? '' : 'none';

        var box = el('ftEntrants');
        if (!box) return;
        var ents = live && live.entrants ? live.entrants : [];
        if (!ents.length) { box.innerHTML = ''; return; }
        var h = '';
        ents.forEach(function (e) {
            var out = e.place != null;
            var uid = (typeof e.uid === 'string' && e.uid.indexOf('gn_') === 0) ? e.uid.slice(3) : '';
            h += '<div class="ft-ent">'
               + '<span class="n' + (out ? ' out' : '') + '">' + esc(e.name) + (e.isHost ? ' <span style="font-weight:400;color:#94a3b8">(host)</span>' : '') + '</span>'
               + (out ? '<span class="c">' + ordinal(e.place) + '</span>'
                      : '<span class="c">' + (e.chips != null ? e.chips + ' chips' : '') + (e.table ? ' &middot; table ' + e.table : '') + '</span>')
               + (e.connected ? '' : '<span style="font-size:.72rem;color:#94a3b8">away</span>')
               + (run && !out && !e.isHost && uid ? '<button type="button" class="ft-mini" data-act="ftRemove" data-a1="' + esc(uid) + '" data-a2="' + esc(e.name) + '">Take out of play</button>' : '')
               + '</div>';
        });
        box.innerHTML = h;
    }
    function ordinal(n) {
        n = parseInt(n, 10) || 0;
        var s = ['th', 'st', 'nd', 'rd'], v = n % 100;
        return n + (s[(v - 20) % 10] || s[v] || s[0]);
    }

    function render(j) {
        if (!j || !j.ok || !j.game) return;
        var g = j.game;
        var was = C.status;
        C.status = g.status;
        var st = el('ftStatus');
        if (st) st.innerHTML = statusLine(g, j.live, j.live_error);
        renderControls(g, j.live);
        // An ending changes the whole panel (links go, the places arrive):
        // the server renders that, so take the page again.
        if (was !== g.status && (g.status === 'finished' || g.status === 'cancelled')) {
            if (timer) clearInterval(timer);
            setTimeout(function () { location.reload(); }, 800);
        }
    }

    function poll() {
        if (document.hidden) return;
        if (C.status !== 'registering' && C.status !== 'running') return;
        fetch('/finaltable_dl.php?action=state&event_id=' + encodeURIComponent(C.eventId), { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(render)
            .catch(function () {});
    }

    // ── Controls (data-act handlers) ─────────────────────────────────────
    window.ftSetup = function (btn) {
        pkBusy(btn, post('setup').then(function (j) {
            if (!j.ok) { pkAlert(esc(j.error || 'Could not set up the table.'), { title: esc(C.appName) + ' said' }); return; }
            location.reload();
        }).catch(function () { pkAlert('Network error.'); }));
    };
    window.ftControl = function (action, btn) {
        pkBusy(btn, post(action).then(function (j) {
            if (!j.ok) { pkAlert(esc(j.error || 'Refused.'), { title: esc(C.appName) + ' said' }); return; }
            render({ ok: true, game: j.game, live: j.live, live_error: null });
        }).catch(function () { pkAlert('Network error.'); }));
    };
    window.ftCancel = async function (btn) {
        var ok = await pkConfirm('Call the game off at ' + esc(C.appName) + '? Everyone at the table is sent back to the lobby, and the places so far are kept.', { okLabel: 'Cancel the game', danger: true });
        if (!ok) return;
        pkBusy(btn, post('cancel').then(function (j) {
            if (!j.ok) { pkAlert(esc(j.error || 'Refused.'), { title: esc(C.appName) + ' said' }); return; }
            location.reload();
        }).catch(function () { pkAlert('Network error.'); }));
    };
    window.ftRemove = async function (userId, name) {
        var ok = await pkConfirm('Take ' + esc(name) + ' out of play? Their seat goes and they are told.', { okLabel: 'Take out', danger: true });
        if (!ok) return;
        post('remove', { user_id: userId }).then(function (j) {
            if (!j.ok) { pkAlert(esc(j.error || 'Refused.'), { title: esc(C.appName) + ' said' }); return; }
            toast(j.remove && j.remove.queued ? name + ' goes out after this hand' : name + ' is out');
            poll();
        }).catch(function () { pkAlert('Network error.'); });
    };
    window.ftCopy = function (url) {
        pkCopy(url).then(function (ok) {
            if (ok) toast('Link copied');
            else pkAlert(esc(url), { title: 'Copy this link' });
        });
    };

    document.addEventListener('visibilitychange', function () { if (!document.hidden) poll(); });
    timer = setInterval(poll, C.pollMs || 15000);
    poll();
})();
