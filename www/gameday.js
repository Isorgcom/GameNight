/**
 * Game Day — behaviour for gameday.php (phone-first tournament director).
 *
 * Architecture (see GAMEDAY.md):
 *  - Two pollers: clock  = timer_dl.php get_state   every 3s  (anchors, levels,
 *    pool, payouts, can_control, and a FRESH CSRF TOKEN every response);
 *    roster = checkin_dl.php get_session&slim=1     every 10s (players, config).
 *    Both stop while the tab is hidden and re-fire on visibilitychange.
 *  - The clock is DERIVED from ends_at_ms/server_now_ms anchors (timer_beta.js
 *    engine), never assigned from a poll — stutter is impossible, not smoothed.
 *  - GD.csrf is never trusted, only refreshed; gdPost retries exactly once on a
 *    403 after forcing a re-poll. This page must not decay overnight the way
 *    the console does.
 *  - Rendering is DOM building + textContent ONLY — no HTML string
 *    concatenation anywhere. The single escaping site is gdEsc(), used only
 *    for pk* dialog messages (pk-dialogs assigns innerHTML).
 *  - Rows are PATCHED via a Map(playerId -> els), never wholesale re-created:
 *    scroll position and the row under the host's thumb survive every poll.
 *
 * Every global handler is gd-prefixed so a double-dispatch sweep failure names
 * its page. All handlers are defined unconditionally: a control that renders
 * only sometimes still needs its function to always exist.
 */
(function () {
'use strict';

/* ── State ─────────────────────────────────────────────────────────────── */

var GD = window.GD;                    // server-injected config (gameday.php)
var SESSION = null;                    // session config row (roster poll)
var PLAYERS = [];                      // roster (roster poll / action merges)
var PAYOUTS = [];                      // payout ladder rows
var POOL    = null;                    // calc_pool output (both polls carry it)
var TICKETS = { incoming: [], outgoing: [] };
var LEVELS  = [];                      // blind schedule (clock poll)
var VIEW    = (GD.status === 'setup') ? 'all' : 'playing';
var SEARCH  = '';
var SHEET_PID = null;                  // player the bottom sheet is showing
var CAN_CONTROL = true;                // page is manager-gated; refined per poll

// Clock state (anchor protocol — see liveRemaining()).
var T = { level: 0, running: false, remaining: 0, fetchedAt: 0, synced: false,
          anchorEndsAt: null, anchorRemainingMs: null };

// Liveness bookkeeping.
var lastClockOkAt = 0;
var lastRosterOkAt = 0;
var netDead = false;                   // double-403 → "session expired"
var clockTimer = null, rosterTimer = null;
var clockInFlight = false, rosterInFlight = false;
var WALKIN_SEEN = null;                // Set of pending ids; null until first data

var el = function (id) { return document.getElementById(id); };

// CSS cannot downgrade an explicit behavior:'smooth' — honour the OS setting.
function gdScrollBehavior() {
    return (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches)
        ? 'auto' : 'smooth';
}

/* ── Formatting helpers ────────────────────────────────────────────────── */

// The page's ONE escaping helper, used ONLY for pk* dialog message strings
// (pk-dialogs assigns innerHTML). Text-node round-trip plus explicit quote
// escaping, same contract as checkin.php's escHtml. Never used in the render
// path — everything there is textContent.
function gdEsc(s) {
    if (!s) return '';
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(s));
    return div.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

function gdMoney(cents) {
    var d = (parseInt(cents) || 0) / 100;
    return '$' + (d % 1 === 0 ? d.toLocaleString() : d.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
}

function gdOrdinal(n) {
    var s = ['th', 'st', 'nd', 'rd'], v = n % 100;
    return n + (s[(v - 20) % 10] || s[v] || s[0]);
}

function fmtClock(sec) {
    sec = Math.max(0, Math.ceil(sec));
    var h = Math.floor(sec / 3600), m = Math.floor((sec % 3600) / 60), s = sec % 60;
    var mm = (h ? String(m).padStart(2, '0') : String(m));
    return (h ? h + ':' + mm : mm) + ':' + String(s).padStart(2, '0');
}

function fmtChips(n) { return (parseInt(n) || 0).toLocaleString(); }

function blindsText(lv) {
    if (!lv) return '—';
    if (parseInt(lv.is_break)) return 'BREAK';
    var t = fmtChips(lv.small_blind) + ' / ' + fmtChips(lv.big_blind);
    if (parseInt(lv.ante) > 0) t += ' (a' + fmtChips(lv.ante) + ')';
    return t;
}

function findLevel(n) {
    for (var i = 0; i < LEVELS.length; i++) if ((LEVELS[i].level_number | 0) === n) return LEVELS[i];
    return null;
}

function payoutForPlace(place) {
    if (!POOL) return 0;
    var row = PAYOUTS.find(function (p) { return parseInt(p.place) === place; });
    if (!row) return 0;
    return Math.round((parseInt(POOL.pool_total) || 0) * (parseFloat(row.percentage) || 0) / 100);
}

function playerById(pid) {
    pid = parseInt(pid);
    return PLAYERS.find(function (p) { return parseInt(p.id) === pid; }) || null;
}

/* ── Clock engine (ported from timer_beta.js — Cristian offset + anchor) ── */

var clockSamples = [];
function noteClockSample(serverNowMs, requestedAt, receivedAt) {
    if (!serverNowMs) return;
    var rtt = receivedAt - requestedAt;
    var offset = (serverNowMs + rtt / 2) - receivedAt;
    clockSamples.push({ rtt: rtt, offset: offset });
    if (clockSamples.length > 8) clockSamples.shift();
}
function clockOffset() {
    if (!clockSamples.length) return 0;
    var byRtt = clockSamples.slice().sort(function (a, b) { return a.rtt - b.rtt; });
    var take = byRtt.slice(0, Math.min(3, byRtt.length))
                    .map(function (x) { return x.offset; })
                    .sort(function (a, b) { return a - b; });
    return take[(take.length - 1) >> 1];
}
function serverNow() { return Date.now() + clockOffset(); }

function liveRemaining() {
    if (T.anchorEndsAt !== null) return (T.anchorEndsAt - serverNow()) / 1000;
    if (T.anchorRemainingMs !== null) return T.anchorRemainingMs / 1000;
    // Legacy fallback for a server without anchors.
    return T.running ? T.remaining - (Date.now() - T.fetchedAt) / 1000 : T.remaining;
}

var RESYNC_TOLERANCE = 2.5;
function applyTimerSync(t, requestedAt) {
    var lvl = t.current_level | 0;
    var rem = t.time_remaining_seconds | 0;
    var run = !!(t.is_running | 0);
    if (t.ends_at_ms || (t.remaining_ms !== null && t.remaining_ms !== undefined)) {
        T.level = lvl; T.running = run; T.remaining = rem;
        T.fetchedAt = Date.now(); T.synced = true;
        T.anchorEndsAt = t.ends_at_ms ? +t.ends_at_ms : null;
        T.anchorRemainingMs = (t.remaining_ms === null || t.remaining_ms === undefined) ? null : +t.remaining_ms;
        return;
    }
    T.anchorEndsAt = null; T.anchorRemainingMs = null;
    var drift = Math.abs(rem - liveRemaining());
    var structural = !T.synced || lvl !== T.level || run !== T.running || !run;
    if (!structural && drift <= RESYNC_TOLERANCE) return;
    T.level = lvl; T.remaining = rem; T.running = run;
    T.fetchedAt = requestedAt + (Date.now() - requestedAt) / 2;
    T.synced = true;
}

/* ── Networking: pollers, CSRF refresh, failure banner ─────────────────── */

function gdNoteFail() { renderNet(); }

function pollClock() {
    if (clockInFlight) return;
    clockInFlight = true;
    var requestedAt = Date.now();
    // Polls even when GD.hasTimer is false: the host may set the timer up from
    // another device mid-game, and a frozen flag would leave this page blind
    // to it forever. The error response on a timer-less session is ~60 bytes.
    var ac = ('AbortController' in window) ? new AbortController() : null;
    var kill = ac ? setTimeout(function () { ac.abort(); }, 10000) : null;
    fetch('/timer_dl.php?action=get_state&session_id=' + GD.sessionId,
          ac ? { credentials: 'same-origin', signal: ac.signal } : { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) {
            clockInFlight = false;
            if (kill) clearTimeout(kill);
            if (!j || !j.ok) {
                // No timer row yet (or it vanished): the roster half still
                // works; note the miss only when a timer was expected.
                if (GD.hasTimer) gdNoteFail(); else renderHeader();
                return;
            }
            if (!GD.hasTimer) { GD.hasTimer = true; renderHeader(); }
            noteClockSample(j.server_now_ms, requestedAt, Date.now());
            applyTimerSync(j.timer || {}, requestedAt);
            LEVELS = j.levels || [];
            if (j.pool) POOL = j.pool;
            if (j.payouts) PAYOUTS = j.payouts;
            if (typeof j.can_control !== 'undefined') CAN_CONTROL = !!j.can_control;
            // The whole reason this page exists: the token never goes stale.
            if (j.csrf_token) { GD.csrf = j.csrf_token; netDead = false; }
            if (j.session_status && j.session_status !== GD.status) {
                GD.status = j.session_status;
                renderLifecycle(); renderRoster();
            }
            lastClockOkAt = Date.now();
            renderHeader(); renderNet();
        })
        .catch(function () { clockInFlight = false; if (kill) clearTimeout(kill); gdNoteFail(); });
}

// A forced resync while a poll is in flight must not be dropped — the
// in-flight response may predate the mutation that asked for the resync.
var rosterAgain = false;
function pollRoster() {
    if (rosterInFlight) { rosterAgain = true; return; }
    rosterInFlight = true;
    var ac = ('AbortController' in window) ? new AbortController() : null;
    var kill = ac ? setTimeout(function () { ac.abort(); }, 10000) : null;
    // slim=1 drops the 200-row log from the payload (79% of it); a server
    // without the flag simply ignores it.
    fetch('/checkin_dl.php?action=get_session&event_id=' + GD.eventId + '&slim=1',
          ac ? { credentials: 'same-origin', signal: ac.signal } : { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) {
            rosterInFlight = false;
            if (kill) clearTimeout(kill);
            if (rosterAgain) { rosterAgain = false; pollRoster(); return; }
            if (!j || !j.ok || !j.session) { gdNoteFail(); return; }
            SESSION = j.session;
            PAYOUTS = j.payouts || PAYOUTS;
            if (j.pool) POOL = j.pool;
            TICKETS = j.tickets || TICKETS;
            if (j.csrf_token) { GD.csrf = j.csrf_token; netDead = false; }   // with the slim delta
            if (j.asset_v && GD.assetV && j.asset_v !== GD.assetV && !window._gdReloading) {
                window._gdReloading = true;
                setTimeout(function () { location.reload(); }, 500);
            }
            if (j.session.status && j.session.status !== GD.status) {
                GD.status = j.session.status;
                renderLifecycle();
            }
            checkWalkinArrivals(j.players || []);
            PLAYERS = j.players || [];
            lastRosterOkAt = Date.now();
            renderPending(); renderRoster(); renderHeader(); renderNet();
            if (SHEET_PID !== null) renderSheet();   // an open sheet tracks the world
        })
        .catch(function () { rosterInFlight = false; if (kill) clearTimeout(kill);
                             if (rosterAgain) { rosterAgain = false; pollRoster(); return; }
                             gdNoteFail(); });
}

// setTimeout self-rescheduling: a slow response can never stack requests.
function scheduleClock()  { clearTimeout(clockTimer);  clockTimer  = setTimeout(function () { pollClock();  scheduleClock();  }, 3000); }
function scheduleRoster() { clearTimeout(rosterTimer); rosterTimer = setTimeout(function () { pollRoster(); scheduleRoster(); }, 10000); }

document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'visible') {
        wakeLockAcquired = false; requestWakeLock();
        pollClock(); pollRoster();               // resync NOW, not next tick
        scheduleClock(); scheduleRoster();
    } else {
        clearTimeout(clockTimer); clearTimeout(rosterTimer);
    }
});

/**
 * All mutations go through here. Retries exactly once on a 403 (stale CSRF)
 * after forcing a clock poll to fetch a fresh token — closing the window
 * between polls. A second 403 flips the red banner instead of looping.
 */
function gdPost(url, fields, retried) {
    var fd = new FormData();
    fd.append('csrf_token', GD.csrf);
    Object.keys(fields).forEach(function (k) {
        if (Array.isArray(fields[k])) fields[k].forEach(function (v) { fd.append(k + '[]', v); });
        else fd.append(k, fields[k]);
    });
    return fetch(url, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) {
        if (r.status === 403 && !retried) {
            return new Promise(function (resolve) {
                var requestedAt = Date.now();
                // Refresh from whichever channel can actually answer: a
                // timer-less session gets {ok:false} from get_state before the
                // token line, which would make this retry a guaranteed replay
                // of the same stale token.
                var refreshUrl = GD.hasTimer
                    ? '/timer_dl.php?action=get_state&session_id=' + GD.sessionId
                    : '/checkin_dl.php?action=get_session&event_id=' + GD.eventId + '&slim=1';
                fetch(refreshUrl, { credentials: 'same-origin' })
                    .then(function (rr) { return rr.json(); })
                    .then(function (j) {
                        if (j && j.csrf_token) GD.csrf = j.csrf_token;
                        if (j && j.server_now_ms) noteClockSample(j.server_now_ms, requestedAt, Date.now());
                        resolve(gdPost(url, fields, true));
                    })
                    .catch(function () { resolve(gdPost(url, fields, true)); });
            });
        }
        if (r.status === 403 && retried) { netDead = true; renderNet(); }
        return r.json();
    });
}

// Convenience: checkin_dl action post + standard error surfacing.
function gdAction(action, fields) {
    fields = fields || {};
    fields.action = action;
    return gdPost('/checkin_dl.php', fields).then(function (j) {
        if (!j || !j.ok) {
            var e = new Error((j && j.error) || 'Request failed');
            e.handled = false;
            throw e;
        }
        return j;
    });
}

function gdErr(e) {
    gdNoteFail();
    pkAlert(gdEsc(e && e.message || 'Request failed'));
}

/* ── Merge rules (design doc §6.8) ─────────────────────────────────────── */

function mergePlayer(p) {
    if (!p) return;
    var pid = parseInt(p.id);
    var i = PLAYERS.findIndex(function (x) { return parseInt(x.id) === pid; });
    if (i >= 0) PLAYERS[i] = p; else PLAYERS.push(p);
    renderPending(); renderRoster(); renderHeader();
}
function takePlayers(list) {
    if (!list) return;
    checkWalkinArrivals(list);
    PLAYERS = list;
    renderPending(); renderRoster(); renderHeader();
}
function takePool(pool) { if (pool) { POOL = pool; renderHeader(); } }

/* ── Net / stale banner ────────────────────────────────────────────────── */

function renderNet() {
    var n = el('gdNet');
    if (!n) return;
    var base = GD.hasTimer ? lastClockOkAt : lastRosterOkAt;
    if (netDead) {
        n.className = 'gd-strip gd-net-dead';
        n.textContent = 'Session expired — tap to reload';
        n.dataset.act = 'gdReload';
        n.style.display = '';
        return;
    }
    var age = base ? (Date.now() - base) : 0;
    var limit = GD.hasTimer ? 12000 : 25000;    // roster-only pages poll slower
    if (base && age > limit) {
        n.className = 'gd-strip gd-net-stale';
        n.textContent = 'Reconnecting… last update ' + Math.round(age / 1000) + 's ago';
        delete n.dataset.act;
        n.style.display = '';
    } else {
        n.style.display = 'none';
    }
}
window.gdReload = function () { location.reload(); };

/* ── Wake lock (ported from timer.js §7.7) ─────────────────────────────── */

var wakeBanner = null;
var wakeLock = null;
var wakeLockAcquired = false;
var noSleep = null, noSleepEnabled = false;
try { if (typeof NoSleep !== 'undefined') noSleep = new NoSleep(); } catch (e) {}

function hideWakeBanner() {
    if (!wakeBanner) return;
    wakeBanner.remove(); wakeBanner = null;
}
function requestWakeLock() {
    if (!('wakeLock' in navigator) || wakeLockAcquired) return;
    navigator.wakeLock.request('screen').then(function (wl) {
        wakeLock = wl; wakeLockAcquired = true; hideWakeBanner();
        wl.addEventListener('release', function () { wakeLock = null; wakeLockAcquired = false; });
    }).catch(function () {});
}
function acquireWakeFromGesture() {
    requestWakeLock();
    // Prime the walk-in chirp's AudioContext inside the same gesture window.
    try {
        var ctx = window._pkAC || (window._pkAC = new (window.AudioContext || window.webkitAudioContext)());
        if (ctx.state === 'suspended') ctx.resume().catch(function () {});
    } catch (e) {}
    if (noSleep && !noSleepEnabled) {
        var p;
        try { p = noSleep.enable(); } catch (e) { return; }
        if (p && typeof p.then === 'function') {
            p.then(function () { noSleepEnabled = true; hideWakeBanner(); }).catch(function () {});
        } else { noSleepEnabled = true; hideWakeBanner(); }
    }
}
function initWakeLock() {
    if (('ontouchstart' in window) || navigator.maxTouchPoints > 0) {
        wakeBanner = document.createElement('div');
        wakeBanner.className = 'gd-wake';
        wakeBanner.textContent = 'Tap anywhere to keep this screen on';
        document.body.appendChild(wakeBanner);
        setTimeout(hideWakeBanner, 6000);
    }
    requestWakeLock();
    document.addEventListener('click', acquireWakeFromGesture, true);
    document.addEventListener('touchend', acquireWakeFromGesture, true);
}

/* ── Sticky header ─────────────────────────────────────────────────────── */

window.gdToggleControls = function () {
    if (!CAN_CONTROL || !GD.hasTimer) return;
    el('gdControls').classList.toggle('open');
};

window.gdCmd = function (cmd) {
    if (!GD.hasTimer) { pkAlert('The timer is not set up yet — open the timer page once to create it.'); return; }
    gdPost('/timer_dl.php', { action: 'command', cmd: cmd, session_id: GD.sessionId }).then(function (j) {
        if (!j || !j.ok) pkAlert(gdEsc((j && j.error) || 'Timer command failed'));
        pollClock();                                // command returns {ok} only
    }).catch(function () { gdNoteFail(); });
};

function renderHeader() {
    var lvl = findLevel(T.level);
    el('gdLevel').textContent = GD.hasTimer
        ? (lvl && parseInt(lvl.is_break) ? 'ON BREAK' : 'Level ' + (T.level || '—'))
        : 'No timer';
    el('gdBlinds').textContent = blindsText(lvl);
    var nxt = null;
    for (var i = 0; i < LEVELS.length; i++) {
        if ((LEVELS[i].level_number | 0) > T.level) { nxt = LEVELS[i]; break; }
    }
    el('gdNext').textContent = nxt ? '→ ' + blindsText(nxt) : '';

    // Stats line — all sourced from the clock poll's pool/payouts so it stays
    // live even when the roster poll is wedged.
    var stats = el('gdStats');
    stats.textContent = '';
    function stat(label, value) {
        var s = document.createElement('span');
        var b = document.createElement('b');
        b.textContent = value;
        s.appendChild(b);
        s.appendChild(document.createTextNode(' ' + label));
        stats.appendChild(s);
    }
    if (POOL) {
        stat('left', (POOL.still_playing || 0) + '/' + (POOL.total_players || 0));
        if (parseInt(POOL.chips_in_play) > 0 && parseInt(POOL.still_playing) > 0) {
            stat('avg', fmtChips(Math.round(POOL.chips_in_play / POOL.still_playing)));
        }
        if (parseInt(POOL.pool_total) > 0) {
            stat('pool', gdMoney(POOL.pool_total));
            for (var pl = 1; pl <= 3; pl++) {
                var row = PAYOUTS.find(function (p) { return parseInt(p.place) === pl; });
                if (!row) continue;
                var amt = payoutForPlace(pl);
                if (amt > 0) stat(gdOrdinal(pl), gdMoney(amt));
                else if (row.prize_label) stat(gdOrdinal(pl), String(row.prize_label));
            }
        }
    }
    var play = el('gdPlayBtn');
    if (play) play.innerHTML = T.running ? '&#9208;' : '&#9654;';
}

// Local tick: derived display only — one textContent write, four times a
// second, so the flip lands within 250ms of the true second boundary.
setInterval(function () {
    var c = el('gdClock');
    if (!c) return;
    if (!GD.hasTimer || !T.synced) { c.textContent = '--:--'; return; }
    c.textContent = fmtClock(liveRemaining());
    c.classList.toggle('paused', !T.running);
    renderNet();
}, 250);

/* ── Lifecycle strips ──────────────────────────────────────────────────── */

window.gdStartGame = function () {
    gdAction('update_status', { session_id: GD.sessionId, status: 'active' }).then(function (j) {
        GD.status = j.status || 'active';           // update_status returns {status} only
        renderLifecycle(); renderRoster(); pollClock();
    }).catch(gdErr);
};

function renderLifecycle() {
    var box = el('gdLifecycle');
    box.textContent = '';
    if (GD.status === 'setup') {
        var s = document.createElement('div');
        s.className = 'gd-strip gd-setup';
        var t = document.createElement('span');
        t.textContent = 'Game not started';
        var b = document.createElement('button');
        b.type = 'button'; b.className = 'gd-strip-btn'; b.textContent = 'Start game';
        b.dataset.act = 'gdStartGame';
        s.appendChild(t); s.appendChild(b);
        box.appendChild(s);
    } else if (GD.status === 'finished') {
        var w = document.createElement('div');
        w.className = 'gd-strip gd-winner';
        var champ = PLAYERS.find(function (p) { return parseInt(p.finish_position) === 1; });
        var amt = payoutForPlace(1);
        w.textContent = '🏆 ' + (champ ? champ.display_name : 'Winner') + ' wins'
                      + (amt > 0 ? ' — ' + gdMoney(amt) : '')
                      + ' · finish & payouts on the console';
        w.style.cursor = 'pointer';
        w.dataset.act = 'gdOpenConsole';
        box.appendChild(w);
    }
}
window.gdOpenConsole = function () { location.href = '/checkin.php?event_id=' + GD.eventId; };

/* ── Pending walk-ins strip + arrival detection ────────────────────────── */

function pendingPlayers() {
    return PLAYERS.filter(function (p) {
        return (p.approval_status || 'approved') === 'pending' && !parseInt(p.removed);
    });
}
function pendingIds(list) {
    return (list || [])
        .filter(function (p) { return (p.approval_status || 'approved') === 'pending' && !parseInt(p.removed); })
        .map(function (p) { return parseInt(p.id); });
}

function walkinToast(msg) {
    var t = document.createElement('div');
    t.textContent = msg;
    t.style.cssText = 'position:fixed;bottom:calc(70px + env(safe-area-inset-bottom));left:50%;transform:translateX(-50%);z-index:99999;'
        + 'background:#1e293b;color:#fff;padding:.6rem 1.1rem;border-radius:999px;font-size:.9rem;font-weight:600;'
        + 'box-shadow:0 6px 24px rgba(0,0,0,.3);cursor:pointer;max-width:90vw';
    t.onclick = function () {
        t.remove();
        window.scrollTo({ top: 0, behavior: gdScrollBehavior() });   // the strip lives at the top
    };
    document.body.appendChild(t);
    setTimeout(function () { t.remove(); }, 8000);
}
function walkinChirp() {
    try {
        var ctx = window._pkAC || (window._pkAC = new (window.AudioContext || window.webkitAudioContext)());
        if (ctx.state === 'suspended') { ctx.resume().catch(function () {}); }
        var o = ctx.createOscillator(), g = ctx.createGain();
        o.connect(g); g.connect(ctx.destination);
        o.frequency.setValueAtTime(880, ctx.currentTime);
        o.frequency.setValueAtTime(1175, ctx.currentTime + 0.12);
        g.gain.setValueAtTime(0.001, ctx.currentTime);
        g.gain.exponentialRampToValueAtTime(0.18, ctx.currentTime + 0.02);
        g.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.35);
        o.start(); o.stop(ctx.currentTime + 0.4);
    } catch (e) { /* audio blocked until first gesture — the toast still shows */ }
}
function checkWalkinArrivals(list) {
    var ids = pendingIds(list);
    if (WALKIN_SEEN === null) { WALKIN_SEEN = new Set(ids); return; }
    var fresh = ids.filter(function (id) { return !WALKIN_SEEN.has(id); });
    ids.forEach(function (id) { WALKIN_SEEN.add(id); });
    if (!fresh.length) return;
    var names = fresh.map(function (id) {
        var p = (list || []).find(function (x) { return parseInt(x.id) === id; });
        return p ? p.display_name : 'Someone';
    });
    walkinToast(names.join(', ') + ' signed up — waiting for approval');
    walkinChirp();
}

window.gdApprove = function (pid) {
    gdAction('approve_player', { player_id: pid }).then(function (j) {
        takePlayers(j.players); takePool(j.pool);
    }).catch(gdErr);
};
window.gdDeny = function (pid) {
    var p = playerById(pid);
    pkConfirm('Deny ' + gdEsc(p ? p.display_name : 'this player') + '?', { okLabel: 'Deny' }).then(function (ok) {
        if (!ok) return;
        gdAction('deny_player', { player_id: pid }).then(function (j) {
            takePlayers(j.players); takePool(j.pool);
        }).catch(gdErr);
    });
};

function renderPending() {
    var box = el('gdPending');
    var pend = pendingPlayers();
    box.textContent = '';
    if (!pend.length) { positionSegSoon(); return; }
    var wrap = document.createElement('div');
    wrap.className = 'gd-pending';
    var h = document.createElement('div');
    h.className = 'gd-pending-title';
    h.textContent = pend.length + ' waiting for approval';
    wrap.appendChild(h);
    pend.forEach(function (p) {
        var row = document.createElement('div');
        row.className = 'gd-pending-row';
        var name = document.createElement('span');
        name.className = 'gd-pname'; name.textContent = p.display_name;
        var ok = document.createElement('button');
        ok.type = 'button'; ok.className = 'gd-ok'; ok.textContent = 'Approve';
        ok.dataset.act = 'gdApprove'; ok.dataset.a1 = String(parseInt(p.id));
        var no = document.createElement('button');
        no.type = 'button'; no.className = 'gd-no'; no.textContent = 'Deny';
        no.dataset.act = 'gdDeny'; no.dataset.a1 = String(parseInt(p.id));
        row.appendChild(name); row.appendChild(ok); row.appendChild(no);
        wrap.appendChild(row);
    });
    box.appendChild(wrap);
    positionSegSoon();
}

// Strips appearing/disappearing move the seg buttons — re-measure the thumb.
function positionSegSoon() {
    if (typeof positionAllSegThumbs === 'function') {
        requestAnimationFrame(function () { positionAllSegThumbs(false); });
    }
}

/* ── Roster rendering: build + patch, never wholesale ──────────────────── */

var rowEls = new Map();     // playerId -> {root, name, meta, badge, primary}

function playerState(p) {
    if (parseInt(p.finish_position) === 1 && !parseInt(p.eliminated)) return 'champ';
    if (parseInt(p.eliminated)) return 'out';
    if (parseInt(p.bought_in)) return 'playing';
    return 'idle';
}

function seatLabel(p) {
    if (p.table_number === null || p.table_number === undefined || p.table_number === '') return '';
    var t = 'T' + p.table_number;
    if (p.seat_number !== null && p.seat_number !== undefined && p.seat_number !== '') t += ' #' + p.seat_number;
    return t;
}

function primaryFor(p) {
    var st = playerState(p);
    if (st === 'champ') return null;
    if (st === 'idle') return { label: 'Buy In', cls: 'buyin', act: 'gdBuyIn' };
    if (st === 'playing') return { label: 'KO', cls: 'ko', act: 'gdKO' };
    // Eliminated: re-entry when the game allows it and isn't finished; the
    // 10s undo snackbar handles the "that was a mistake" case for the LAST KO.
    if (SESSION && parseInt(SESSION.rebuy_allowed) && GD.status !== 'finished') {
        return { label: 'Re-enter', cls: 'reenter', act: 'gdReenter' };
    }
    return { label: 'Undo', cls: 'undo', act: 'gdUndoElim' };
}

function buildRow(p) {
    var pid = parseInt(p.id);
    var root = document.createElement('div');
    root.className = 'gd-row';
    root.dataset.act = 'gdOpenSheet';
    root.dataset.a1 = String(pid);

    var main = document.createElement('div');
    main.className = 'gd-row-main';
    var name = document.createElement('div');
    name.className = 'gd-row-name';
    var meta = document.createElement('div');
    meta.className = 'gd-row-meta';
    var badge = document.createElement('span');
    badge.className = 'gd-badge';
    var seat = document.createElement('span');
    var extras = document.createElement('span');
    meta.appendChild(badge); meta.appendChild(seat); meta.appendChild(extras);
    main.appendChild(name); main.appendChild(meta);

    var primary = document.createElement('button');
    primary.type = 'button';
    primary.className = 'gd-primary';

    root.appendChild(main); root.appendChild(primary);
    var els = { root: root, name: name, badge: badge, seat: seat, extras: extras, primary: primary };
    rowEls.set(pid, els);
    return els;
}

function patchRow(els, p) {
    var pid = parseInt(p.id);
    var st = playerState(p);
    els.name.textContent = p.display_name;
    els.root.classList.toggle('gd-out', st === 'out');
    els.badge.className = 'gd-badge ' + st;
    els.badge.textContent = st === 'playing' ? 'Playing'
                          : st === 'out' ? (parseInt(p.finish_position) ? gdOrdinal(parseInt(p.finish_position)) : 'Out')
                          : st === 'champ' ? '🏆 1st' : 'Not in';
    els.seat.textContent = seatLabel(p);
    var bits = [];
    if (parseInt(p.rebuys) > 0) bits.push(parseInt(p.rebuys) + '× rebuy');
    if (parseInt(p.addons) > 0) bits.push(parseInt(p.addons) + '× add-on');
    if (parseInt(p.bounties_won) > 0) bits.push('🎯 ' + parseInt(p.bounties_won));
    els.extras.textContent = bits.join(' · ');

    var prim = primaryFor(p);
    if (prim) {
        els.primary.style.display = '';
        els.primary.className = 'gd-primary ' + prim.cls;
        els.primary.textContent = prim.label;
        els.primary.dataset.act = prim.act;
        els.primary.dataset.a1 = String(pid);
    } else {
        els.primary.style.display = 'none';
    }
}

function visiblePlayers() {
    var q = SEARCH.toLowerCase();
    var base = PLAYERS.filter(function (p) {
        if (parseInt(p.removed)) return false;
        if ((p.approval_status || 'approved') === 'pending') return false;   // strip owns them
        if (q && String(p.display_name || '').toLowerCase().indexOf(q) === -1) return false;
        if (VIEW === 'playing') return parseInt(p.bought_in) && !parseInt(p.eliminated);
        if (VIEW === 'out') return !!parseInt(p.eliminated);
        return true;
    });
    base.sort(function (a, b) {
        var ain = (parseInt(a.bought_in) && !parseInt(a.eliminated)) ? 0 : 1;
        var bin = (parseInt(b.bought_in) && !parseInt(b.eliminated)) ? 0 : 1;
        if (ain !== bin) return ain - bin;
        var at = parseInt(a.table_number) || 99, bt = parseInt(b.table_number) || 99;
        if (at !== bt) return at - bt;
        var as = parseInt(a.seat_number) || 99, bs = parseInt(b.seat_number) || 99;
        if (as !== bs) return as - bs;
        return String(a.display_name).localeCompare(String(b.display_name));
    });
    return base;
}

function renderRoster() {
    var view = el('gdView');
    if (VIEW === 'seats') { renderSeats(view); return; }
    // Leaving the seats view: its DOM is disposable, rows are not.
    if (view.dataset.mode !== 'list') { view.textContent = ''; view.dataset.mode = 'list'; }

    var list = visiblePlayers();
    var seen = new Set();
    list.forEach(function (p, i) {
        var pid = parseInt(p.id);
        seen.add(pid);
        var els = rowEls.get(pid) || buildRow(p);
        patchRow(els, p);
        // Single-pass reorder; a no-op when the row is already in place.
        if (view.children[i] !== els.root) view.insertBefore(els.root, view.children[i] || null);
    });
    // Remove rows that left the view (still cached in rowEls for their return).
    Array.from(view.children).forEach(function (child) {
        var keep = list.some(function (p) { return rowEls.get(parseInt(p.id)) && rowEls.get(parseInt(p.id)).root === child; });
        if (!keep) child.remove();
    });
    // Truly departed players (removed) drop out of the cache too.
    rowEls.forEach(function (els, pid) {
        if (!playerById(pid)) { els.root.remove(); rowEls.delete(pid); }
    });
    if (!list.length) {
        var empty = document.createElement('div');
        empty.className = 'gd-empty';
        empty.textContent = SEARCH ? 'No players match.' : 'Nobody here yet.';
        view.appendChild(empty);
    } else {
        var stray = view.querySelector('.gd-empty');
        if (stray) stray.remove();
    }
}

/* ── Seats view ────────────────────────────────────────────────────────── */

function renderSeats(view) {
    view.textContent = '';
    view.dataset.mode = 'seats';

    var q = SEARCH.toLowerCase();
    var live = PLAYERS.filter(function (p) {
        return parseInt(p.bought_in) && !parseInt(p.eliminated) && !parseInt(p.removed)
            && (p.approval_status || 'approved') !== 'pending'
            && (!q || String(p.display_name || '').toLowerCase().indexOf(q) !== -1);
    });

    if ((parseInt(SESSION && SESSION.num_tables) || 1) > 1 || live.some(function (p) { return !p.table_number; })) {
        var reb = document.createElement('button');
        reb.type = 'button'; reb.className = 'gd-rebalance';
        reb.textContent = '⚖ Rebalance tables';
        reb.dataset.act = 'gdRebalance';
        view.appendChild(reb);
    }

    var byTable = new Map();
    live.forEach(function (p) {
        var key = p.table_number ? parseInt(p.table_number) : 0;
        if (!byTable.has(key)) byTable.set(key, []);
        byTable.get(key).push(p);
    });
    var keys = Array.from(byTable.keys()).sort(function (a, b) { return (a || 99) - (b || 99); });

    keys.forEach(function (key) {
        var group = byTable.get(key);
        group.sort(function (a, b) { return (parseInt(a.seat_number) || 99) - (parseInt(b.seat_number) || 99); });
        var card = document.createElement('div');
        card.className = 'gd-table-card';
        var head = document.createElement('div');
        head.className = 'gd-table-head';
        head.textContent = key ? 'Table ' + key : 'Unassigned';
        var count = document.createElement('span');
        count.className = 'gd-count';
        count.textContent = group.length + (key ? '/' + (parseInt(SESSION && SESSION.seats_per_table) || 9) : '');
        head.appendChild(count);
        card.appendChild(head);
        group.forEach(function (p) {
            var row = document.createElement('div');
            row.className = 'gd-seat-row';
            var num = document.createElement('span');
            num.className = 'gd-seat-num';
            num.textContent = p.seat_number ? String(p.seat_number) : '—';
            var nm = document.createElement('span');
            nm.className = 'gd-seat-name'; nm.textContent = p.display_name;
            row.appendChild(num); row.appendChild(nm);
            // A single-table game has nowhere to move anyone: the picker would
            // be an empty select whose OK silently does nothing.
            if ((parseInt(SESSION && SESSION.num_tables) || 1) > 1) {
                var mv = document.createElement('button');
                mv.type = 'button'; mv.className = 'gd-move-chip'; mv.textContent = 'Move';
                mv.dataset.act = 'gdMoveTable'; mv.dataset.a1 = String(parseInt(p.id));
                row.appendChild(mv);
            }
            card.appendChild(row);
        });
        view.appendChild(card);
    });
    if (!keys.length) {
        var empty = document.createElement('div');
        empty.className = 'gd-empty';
        empty.textContent = 'Nobody is seated yet.';
        view.appendChild(empty);
    }
}

window.gdRebalance = function () {
    var tables = parseInt(SESSION && SESSION.num_tables) || 1;
    var n = PLAYERS.filter(function (p) { return parseInt(p.bought_in) && !parseInt(p.eliminated) && !parseInt(p.removed); }).length;
    pkConfirm('Rebalance ' + n + ' players across ' + tables + ' table' + (tables === 1 ? '' : 's')
              + '? Everyone gets a new random seat, including players who stay at their table.',
              { okLabel: 'Rebalance' }).then(function (ok) {
        if (!ok) return;
        gdAction('rebalance_tables', { session_id: GD.sessionId, protected_ids: '[]' }).then(function (j) {
            takePlayers(j.players);
            var moves = j.moves || [];
            if (!moves.length) { pkAlert('No moves needed — tables are already balanced.'); return; }
            var lines = moves.map(function (m) {
                return gdEsc(m.display_name) + '  ' + (m.old_table ? 'T' + m.old_table : 'unseated') + ' → T' + m.new_table;
            });
            pkAlert(lines.join('<br>'), { title: 'Players moved' });
        }).catch(gdErr);
    });
};

window.gdMoveTable = function (pid) {
    var p = playerById(pid);
    if (!p) return;
    var tables = parseInt(SESSION && SESSION.num_tables) || 1;
    var opts = '';
    for (var t = 1; t <= tables; t++) {
        if (parseInt(p.table_number) === t) continue;
        opts += '<option value="' + t + '">Table ' + t + '</option>';
    }
    pkConfirm('Move <b>' + gdEsc(p.display_name) + '</b> to:<br>'
              + '<select id="gdMoveSel" style="margin-top:.4rem;padding:.45rem .5rem;border:1.5px solid #e2e8f0;border-radius:8px;width:100%;font-size:1rem">'
              + opts + '</select>',
              { title: 'Move player', okLabel: 'Move' }).then(function (ok) {
        var sel = document.getElementById('gdMoveSel');
        var target = sel ? parseInt(sel.value) : 0;
        if (!ok || !target) return;
        gdAction('move_player_table', { player_id: pid, new_table: target }).then(function (j) {
            if (j.players) takePlayers(j.players); else mergePlayer(j.player);
            if (SHEET_PID !== null) renderSheet();
        }).catch(gdErr);
    });
};

window.gdUnassign = function (pid) {
    // set_table is used ONLY here, and always with an explicit '' — a missing
    // key would coerce to table 0 (checkin_dl.php has no default on it).
    gdCloseSheet();
    gdAction('set_table', { player_id: pid, table_number: '' }).then(function (j) {
        mergePlayer(j.player);                       // {player} only, no pool
    }).catch(gdErr);
};

/* ── View switcher + search ────────────────────────────────────────────── */

window.gdSetView = function (v) {
    if (v === VIEW) return;                          // no replayed animation
    var dir = (typeof segTravelDirection === 'function')
        ? segTravelDirection('gdViewSeg', 'data-view', VIEW, v) : 1;
    VIEW = v;
    document.querySelectorAll('#gdViewSeg button').forEach(function (b) {
        b.classList.toggle('active', b.dataset.view === v);
    });
    if (typeof positionSegThumb === 'function') positionSegThumb('gdViewSeg', true);
    renderRoster();
    if (typeof slideViewIn === 'function') slideViewIn(el('gdView'), dir);
};

var searchDeb = null;
window.gdSearch = function (val) {
    clearTimeout(searchDeb);
    searchDeb = setTimeout(function () {
        SEARCH = String(val || '').trim();
        renderRoster();
    }, 120);
};

/* ── Primary actions ───────────────────────────────────────────────────── */

window.gdBuyIn = function (pid) {
    var p = playerById(pid);
    if (!p) return;
    // Entry-ticket redemption: skipping this records a cash buy-in nobody paid
    // while the winner keeps a live ticket — the money bug the console fixed.
    var ticket = null;
    if (!parseInt(p.bought_in) && TICKETS.incoming && TICKETS.incoming.length) {
        ticket = TICKETS.incoming.find(function (t) {
            // Mirror checkin_dl's rule exactly: an owned ticket matches by
            // user id ONLY. Offering it to a same-named account-less walk-in
            // would confirm a redemption the server then ignores — recording a
            // cash buy-in nobody paid while the ticket stays live.
            if (t.user_id) return p.user_id && parseInt(t.user_id) === parseInt(p.user_id);
            return String(t.display_name || '').toLowerCase() === String(p.display_name || '').toLowerCase();
        }) || null;
    }
    var doPost = function (ticketId) {
        // set=1 = the server's idempotent "ensure bought in" mode: a double-tap
        // must never land as buy-in + un-buy. gdUndoBuyin is the deliberate
        // toggle-off path and stays a bare toggle.
        var fields = { player_id: pid, set: 1 };
        if (ticketId) fields.ticket_id = ticketId;
        gdAction('toggle_buyin', fields).then(function (j) {
            mergePlayer(j.player); takePool(j.pool);
            if (ticketId) pollRoster();              // tickets + log changed too
        }).catch(gdErr);
    };
    if (ticket) {
        var val = parseInt(ticket.value_cents), buyin = parseInt(SESSION ? SESSION.buyin_amount : 0);
        var msg = '<b>' + gdEsc(p.display_name) + '</b> holds a <b>' + gdMoney(val) + ' entry ticket</b> for this event. Apply it?';
        if (buyin > val) msg += '<br><br>Collect the remaining <b>' + gdMoney(buyin - val) + '</b> in cash.';
        else if (val > buyin) msg += '<br><br>The extra <b>' + gdMoney(val - buyin) + '</b> joins this game’s prize pool.';
        pkConfirm(msg, { title: 'Entry Ticket', okLabel: 'Apply Ticket' }).then(function (ok) {
            doPost(ok ? ticket.id : 0);
        });
        return;
    }
    doPost(0);
};

window.gdUndoBuyin = function (pid) {
    var p = playerById(pid);
    gdCloseSheet();
    pkConfirm('Undo <b>' + gdEsc(p ? p.display_name : '') + '</b>’s buy-in? Their seat is released'
              + ' and any applied entry ticket is returned.', { okLabel: 'Undo buy-in' }).then(function (ok) {
        if (!ok) return;
        gdAction('toggle_buyin', { player_id: pid }).then(function (j) {
            mergePlayer(j.player); takePool(j.pool);
        }).catch(gdErr);
    });
};

window.gdKO = function (pid) {
    var p = playerById(pid);
    if (!p) return;
    if (!parseInt(p.bought_in)) {
        pkAlert('This player has not bought in yet. Buy them in before eliminating.');
        return;
    }
    // Display only — the POST sends finish_position 0 and the server derives
    // the authoritative place (two racing devices must not both claim 4th).
    var place = PLAYERS.filter(function (x) { return !parseInt(x.eliminated) && parseInt(x.bought_in) && !parseInt(x.removed); }).length;
    var amt = payoutForPlace(place);
    var msg = 'Eliminate <b>' + gdEsc(p.display_name) + '</b> in <b>' + gdOrdinal(place) + ' place</b>?';
    if (amt > 0) msg += '<br><br>They finish in the money and are owed <b>' + gdMoney(amt) + '</b>.';
    var hasBounty = SESSION && (parseInt(SESSION.bounty_amount) > 0 || parseInt(SESSION.bounty_points) > 0);
    if (hasBounty) {
        var others = PLAYERS.filter(function (x) {
            return !parseInt(x.eliminated) && parseInt(x.bought_in) && !parseInt(x.removed) && parseInt(x.id) !== parseInt(pid);
        });
        if (others.length) {
            msg += '<br><br><label style="font-size:.8rem;font-weight:600;color:#0e7490">🎯 Knocked out by</label><br>'
                 + '<select id="gdElimBy" style="margin-top:.3rem;padding:.45rem .5rem;border:1.5px solid #e2e8f0;border-radius:8px;width:100%;font-size:1rem">'
                 + '<option value="0">— not recorded —</option>'
                 + others.map(function (x) { return '<option value="' + parseInt(x.id) + '">' + gdEsc(x.display_name) + '</option>'; }).join('')
                 + '</select>';
        }
    }
    pkConfirm(msg, { title: 'Eliminate', okLabel: 'Eliminate', danger: true }).then(function (ok) {
        // pk-dialogs resolves after closing but the nodes survive — read now.
        var sel = document.getElementById('gdElimBy');
        var by = sel ? parseInt(sel.value) || 0 : 0;
        if (!ok) return;
        gdAction('eliminate_player', { player_id: pid, finish_position: 0, eliminated_by: by }).then(function (j) {
            mergePlayer(j.player);
            takePool(j.pool);
            if (j.winner) {
                mergePlayer(j.winner);
                GD.status = j.status || 'finished';
                renderLifecycle();
                pollClock();
            }
            showSnack((p.display_name) + ' knocked out'
                      + (j.player && parseInt(j.player.finish_position) ? ' — ' + gdOrdinal(parseInt(j.player.finish_position)) : ''),
                      'Undo', function () { gdUndoElim(pid); });
        }).catch(gdErr);
    });
};

window.gdUndoElim = function (pid) {
    hideSnack();
    gdAction('uneliminate_player', { player_id: pid }).then(function (j) {
        if (j.reopened) {
            // The winner was un-crowned and issued tickets voided — resync all.
            GD.status = 'active';
            renderLifecycle();
            pollRoster(); pollClock();
        } else {
            mergePlayer(j.player); takePool(j.pool);
        }
    }).catch(gdErr);
};

window.gdReenter = function (pid) {
    var p = playerById(pid);
    if (!p) return;
    var bountyNote = '';
    if (SESSION && parseInt(SESSION.bounty_amount) > 0) {
        bountyNote = parseInt(SESSION.bounty_optional)
            ? ' Their old bounty chip stays with whoever collected it — they can buy a new one.'
            : ' Any bounty already collected on them stays collected.';
    }
    pkConfirm('Rebuy and re-enter ' + gdEsc(p.display_name) + '? They come back into the game with a fresh stack.'
              + bountyNote, { okLabel: 'Re-enter' }).then(function (ok) {
        if (!ok) return;
        gdAction('update_rebuys', { player_id: pid, delta: 1 }).then(function (j) {
            if (j.reentered || j.reopened) {
                // Bounties banked, payouts and finish positions all moved.
                if (j.status) { GD.status = j.status; renderLifecycle(); }
                pollRoster(); pollClock();
            } else {
                mergePlayer(j.player); takePool(j.pool);
            }
        }).catch(gdErr);
    });
};

/* ── Post-KO undo snackbar ─────────────────────────────────────────────── */

var snackCb = null, snackTimer = null;
function showSnack(text, btnLabel, cb) {
    el('gdSnackTxt').textContent = text;
    el('gdSnackBtn').textContent = btnLabel;
    snackCb = cb;
    el('gdSnack').classList.add('open');
    clearTimeout(snackTimer);
    snackTimer = setTimeout(hideSnack, 10000);
}
function hideSnack() {
    el('gdSnack').classList.remove('open');
    snackCb = null;
    clearTimeout(snackTimer);
}
window.gdSnackAction = function () {
    var cb = snackCb;
    hideSnack();
    if (cb) cb();
};

/* ── Bottom action sheet ───────────────────────────────────────────────── */

window.gdOpenSheet = function (pid) {
    var p = playerById(pid);
    if (!p) return;
    if ((p.approval_status || 'approved') === 'pending') return;   // strip owns them
    SHEET_PID = parseInt(pid);
    renderSheet();
    el('gdSheetBack').classList.add('open');
    el('gdSheet').classList.add('open');
};
window.gdCloseSheet = function () {
    SHEET_PID = null;
    el('gdSheetBack').classList.remove('open');
    el('gdSheet').classList.remove('open');
};

function sheetRow(labelText, subText, control) {
    var row = document.createElement('div');
    row.className = 'gd-sheet-row';
    var lbl = document.createElement('span');
    lbl.className = 'gd-lbl';
    lbl.appendChild(document.createTextNode(labelText));
    if (subText) {
        var sub = document.createElement('span');
        sub.className = 'gd-sub'; sub.textContent = subText;
        lbl.appendChild(sub);
    }
    row.appendChild(lbl);
    row.appendChild(control);
    return row;
}
function counter(count, minusAct, plusAct, pid) {
    var box = document.createElement('div');
    box.className = 'gd-counter';
    var minus = document.createElement('button');
    minus.type = 'button'; minus.textContent = '−';
    minus.dataset.act = minusAct; minus.dataset.a1 = String(pid); minus.dataset.a2 = '-1';
    var cnt = document.createElement('span');
    cnt.className = 'gd-cnt'; cnt.textContent = String(count);
    var plus = document.createElement('button');
    plus.type = 'button'; plus.textContent = '+';
    plus.dataset.act = plusAct; plus.dataset.a1 = String(pid); plus.dataset.a2 = '1';
    box.appendChild(minus); box.appendChild(cnt); box.appendChild(plus);
    return box;
}
function sheetBtn(label, act, pid, danger) {
    var b = document.createElement('button');
    b.type = 'button';
    b.className = 'gd-sheet-btn wide' + (danger ? ' danger' : '');
    b.textContent = label;
    b.dataset.act = act; b.dataset.a1 = String(pid);
    return b;
}

function renderSheet() {
    var p = SHEET_PID !== null ? playerById(SHEET_PID) : null;
    if (!p) { gdCloseSheet(); return; }
    var pid = parseInt(p.id);
    el('gdSheetName').textContent = p.display_name;
    var subBits = [seatLabel(p) || 'No seat'];
    if (parseInt(p.bought_in)) subBits.push('bought in');
    if (parseInt(p.eliminated)) subBits.push('out' + (parseInt(p.finish_position) ? ' · ' + gdOrdinal(parseInt(p.finish_position)) : ''));
    el('gdSheetSub').textContent = subBits.join(' · ');

    var grid = el('gdSheetGrid');
    grid.textContent = '';
    var live = parseInt(p.bought_in) && !parseInt(p.eliminated);

    if (SESSION && parseInt(SESSION.rebuy_allowed) && parseInt(p.bought_in)) {
        grid.appendChild(sheetRow('Rebuys', gdMoney(SESSION.rebuy_amount) + ' each',
            counter(parseInt(p.rebuys) || 0, 'gdRebuy', 'gdRebuy', pid)));
    }
    if (SESSION && parseInt(SESSION.addon_allowed) && parseInt(p.bought_in)) {
        grid.appendChild(sheetRow('Add-ons', gdMoney(SESSION.addon_amount) + ' each',
            counter(parseInt(p.addons) || 0, 'gdAddon', 'gdAddon', pid)));
    }
    if (SESSION && parseInt(SESSION.bounty_amount) > 0 && parseInt(SESSION.bounty_optional) && parseInt(p.bought_in)) {
        var bBtn = document.createElement('button');
        bBtn.type = 'button'; bBtn.className = 'gd-sheet-btn';
        bBtn.textContent = parseInt(p.bounty_in) ? 'In ✓' : 'Not in';
        bBtn.dataset.act = 'gdBountyToggle'; bBtn.dataset.a1 = String(pid);
        grid.appendChild(sheetRow('🎯 Bounty side pot', gdMoney(SESSION.bounty_amount), bBtn));
    }
    if (SESSION && parseInt(SESSION.jackpot_amount) > 0 && parseInt(SESSION.jackpot_optional) && parseInt(p.bought_in)) {
        var jBtn = document.createElement('button');
        jBtn.type = 'button'; jBtn.className = 'gd-sheet-btn';
        jBtn.textContent = parseInt(p.jackpot_in) ? 'In ✓' : 'Not in';
        jBtn.dataset.act = 'gdJackpotToggle'; jBtn.dataset.a1 = String(pid);
        grid.appendChild(sheetRow('💎 Jackpot entry', gdMoney(SESSION.jackpot_amount), jBtn));
    }
    if (live && (parseInt(SESSION && SESSION.num_tables) || 1) > 1) {
        grid.appendChild(sheetBtn('Move to another table…', 'gdMoveTable', pid));
    }
    if (live && p.table_number) {
        grid.appendChild(sheetBtn('Unassign seat', 'gdUnassign', pid));
    }
    if (parseInt(p.eliminated)) {
        grid.appendChild(sheetBtn('Undo elimination', 'gdUndoElim', pid));
    }
    grid.appendChild(sheetBtn('Notes…', 'gdNotes', pid));
    grid.appendChild(sheetBtn('Money ledger', 'gdLedger', pid));
    if (parseInt(p.bought_in)) {
        grid.appendChild(sheetBtn('Undo buy-in', 'gdUndoBuyin', pid, true));
    }
    grid.appendChild(sheetBtn('Remove from game', 'gdRemove', pid, true));
}

window.gdRebuy = function (pid, delta) {
    delta = parseInt(delta) || 1;
    var p = playerById(pid);
    if (p && delta > 0 && parseInt(p.eliminated)) { gdCloseSheet(); gdReenter(pid); return; }
    gdAction('update_rebuys', { player_id: pid, delta: delta }).then(function (j) {
        if (j.reentered || j.reopened) {
            if (j.status) { GD.status = j.status; renderLifecycle(); }
            pollRoster(); pollClock();
        } else {
            mergePlayer(j.player); takePool(j.pool);
        }
        if (SHEET_PID !== null) renderSheet();
    }).catch(gdErr);
};
window.gdAddon = function (pid, delta) {
    gdAction('update_addons', { player_id: pid, delta: parseInt(delta) || 1 }).then(function (j) {
        mergePlayer(j.player); takePool(j.pool);
        if (SHEET_PID !== null) renderSheet();
    }).catch(gdErr);
};
window.gdBountyToggle = function (pid) {
    gdAction('toggle_bounty', { player_id: pid }).then(function (j) {
        mergePlayer(j.player); takePool(j.pool);
        if (SHEET_PID !== null) renderSheet();
    }).catch(gdErr);
};
window.gdJackpotToggle = function (pid) {
    gdAction('toggle_jackpot', { player_id: pid }).then(function (j) {
        mergePlayer(j.player); takePool(j.pool);
        if (SHEET_PID !== null) renderSheet();
    }).catch(gdErr);
};
window.gdNotes = function (pid) {
    var p = playerById(pid);
    gdCloseSheet();
    pkPrompt('Notes for ' + gdEsc(p ? p.display_name : ''), { 'default': (p && p.notes) || '', okLabel: 'Save' }).then(function (v) {
        if (v === null || v === undefined) return;
        gdAction('update_notes', { player_id: pid, notes: v }).then(function (j) {
            mergePlayer(j.player);
        }).catch(gdErr);
    });
};
window.gdLedger = function (pid) {
    var p = playerById(pid);
    gdCloseSheet();
    fetch('/checkin_dl.php?action=get_ledger&player_id=' + parseInt(pid), { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) {
            if (!j || !j.ok) { pkAlert(gdEsc((j && j.error) || 'Could not load the ledger')); return; }
            var rows = (j.ledger || []).map(function (e) {
                var line = (e.time || '') + ' · ' + (e.detail || e.event_type || '');
                if (e.voided) line = '(cleared) ' + line;
                return gdEsc(line);
            });
            pkAlert(rows.length ? rows.join('<br>') : 'No money entries yet.',
                    { title: gdEsc((p ? p.display_name : '') + ' — ledger') });
        })
        .catch(function () { gdNoteFail(); pkAlert('Could not load the ledger'); });
};
window.gdRemove = function (pid) {
    var p = playerById(pid);
    gdCloseSheet();
    pkConfirm('Remove <b>' + gdEsc(p ? p.display_name : '') + '</b> from the game and the invite list?',
              { okLabel: 'Remove', danger: true }).then(function (ok) {
        if (!ok) return;
        gdAction('remove_player', { player_id: pid }).then(function (j) {
            // Response is {pool} only — delete the row ourselves.
            var i = PLAYERS.findIndex(function (x) { return parseInt(x.id) === parseInt(pid); });
            if (i >= 0) PLAYERS.splice(i, 1);
            var els = rowEls.get(parseInt(pid));
            if (els) { els.root.remove(); rowEls.delete(parseInt(pid)); }
            takePool(j.pool);
            renderPending(); renderRoster();
        }).catch(gdErr);
    });
};

/* ── Add walk-in ───────────────────────────────────────────────────────── */

window.gdAddWalkin = function () {
    pkPrompt('Walk-in’s name', { okLabel: 'Add' }).then(function (name) {
        name = String(name || '').trim();
        if (!name) return;
        gdAction('add_walkin', { session_id: GD.sessionId, name: name }).then(function (j) {
            takePlayers(j.players); takePool(j.pool);
            // A fresh walk-in is not bought in, so 3 of the 4 views hide them —
            // an add that shows nothing reads as a failure and gets retried.
            var els = rowEls.get(parseInt(j.player_id));
            if (!els) { gdSetView('all'); els = rowEls.get(parseInt(j.player_id)); }
            if (els) {
                els.root.scrollIntoView({ block: 'center', behavior: gdScrollBehavior() });
                els.root.classList.remove('gd-flash');
                void els.root.offsetWidth;           // restart the animation
                els.root.classList.add('gd-flash');
            }
        }).catch(gdErr);
    });
};

/* Read-only state accessor for the QA suites — the app state is deliberately
 * closure-scoped, and the tests must not be able to mutate it by accident. */
window.gdDebug = function () {
    return { players: PLAYERS, pool: POOL, session: SESSION, view: VIEW,
             timer: T, csrf: GD.csrf, tickets: TICKETS };
};

/* ── Boot ──────────────────────────────────────────────────────────────── */

document.querySelectorAll('#gdViewSeg button').forEach(function (b) {
    b.classList.toggle('active', b.dataset.view === VIEW);
});
renderLifecycle();
initWakeLock();
// Baseline the freshness clocks at boot: without this, a page whose very
// first poll never succeeds has base=0 and the stale banner can never speak.
lastClockOkAt = Date.now(); lastRosterOkAt = Date.now();
pollClock();
pollRoster();
scheduleClock();
scheduleRoster();
// Thumb positioning waits for the first roster paint (a hidden control
// measures zero); pk-seg's resize hook keeps it right afterwards.
requestAnimationFrame(function () {
    if (typeof positionSegThumb === 'function') positionSegThumb('gdViewSeg', false);
});

})();
