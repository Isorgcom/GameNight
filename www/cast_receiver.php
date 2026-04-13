<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>GameNight Timer</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            background: #0f172a;
            color: #fff;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            overflow: hidden;
        }
        #status { color: #64748b; font-size: 2rem; }
        .tv-container {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            width: 100%;
            height: 100vh;
            padding: 2rem;
        }
        .tv-level {
            font-size: 3rem;
            font-weight: 700;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            margin-bottom: 0.5rem;
        }
        .tv-blinds {
            font-size: 8rem;
            font-weight: 800;
            line-height: 1.1;
            margin-bottom: 0.5rem;
        }
        .tv-clock {
            font-size: 18rem;
            font-weight: 800;
            font-variant-numeric: tabular-nums;
            line-height: 1;
            margin: 1rem 0;
            transition: color 0.3s;
        }
        .tv-clock.paused { color: #f59e0b; }
        .tv-clock.warning { color: #ef4444; }
        .tv-paused-label {
            font-size: 2.5rem;
            font-weight: 700;
            color: #f59e0b;
            min-height: 3rem;
        }
        .tv-next {
            font-size: 3rem;
            font-weight: 600;
            color: #94a3b8;
            margin-top: 1rem;
        }
        .tv-info-bar {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            display: flex;
            justify-content: center;
            gap: 3rem;
            padding: 1rem 2rem;
            font-size: 1.8rem;
            color: #64748b;
            font-weight: 600;
        }
        .tv-info-bar span { white-space: nowrap; }
        .tv-info-val { color: #e2e8f0; }
    </style>
</head>
<body>
    <div id="status">Waiting for timer...</div>
    <div class="tv-container" id="display" style="display:none">
        <div class="tv-info-bar" id="infoBar"></div>
        <div class="tv-level" id="levelLabel">Level 1</div>
        <div class="tv-blinds" id="blinds">-</div>
        <div class="tv-clock" id="clock">00:00</div>
        <div class="tv-paused-label" id="pausedLabel"></div>
        <div class="tv-next" id="nextLevel"></div>
    </div>

    <script src="//www.gstatic.com/cast/sdk/libs/caf_receiver/v3/cast_receiver_framework.js"></script>
    <script>
    var REMOTE_KEY = null;
    var SITE_URL = '';
    var LEVELS = [];
    var TIMER = { current_level: 1, time_remaining_seconds: 0, is_running: 0 };
    var POLL_INTERVAL = 2000;

    // ── Cast Receiver Setup ──────────────────────────────────
    var context = cast.framework.CastReceiverContext.getInstance();
    var NAMESPACE = 'urn:x-cast:com.gamenight.timer';

    context.addCustomMessageListener(NAMESPACE, function(event) {
        var data = event.data;
        if (data.key) {
            REMOTE_KEY = data.key;
            SITE_URL = data.siteUrl || '';
            document.getElementById('status').style.display = 'none';
            document.getElementById('display').style.display = '';
            pollState();
            setInterval(pollState, POLL_INTERVAL);
        }
    });

    context.start();

    // ── Timer Display Logic ──────────────────────────────────
    function pollState() {
        if (!REMOTE_KEY) return;
        var url = SITE_URL + '/timer_dl.php?action=get_state&key=' + encodeURIComponent(REMOTE_KEY);
        fetch(url)
            .then(function(r) { return r.json(); })
            .then(function(j) {
                if (!j.ok) return;
                TIMER.current_level = parseInt(j.current_level) || 1;
                TIMER.time_remaining_seconds = parseInt(j.time_remaining_seconds) || 0;
                TIMER.is_running = parseInt(j.is_running) || 0;
                if (j.levels) LEVELS = j.levels;
                if (j.session) {
                    renderInfoBar(j.session, j.pool || {});
                }
                renderDisplay();
            })
            .catch(function() {});
    }

    function getLevelData(num) {
        for (var i = 0; i < LEVELS.length; i++) {
            if (parseInt(LEVELS[i].level_number) === num) return LEVELS[i];
        }
        return null;
    }

    function fmtChips(v) {
        if (v >= 1000000) return (v / 1000000) + 'M';
        if (v >= 1000) return (v / 1000) + 'K';
        return String(v);
    }

    function fmtTime(sec) {
        sec = Math.max(0, sec);
        var m = Math.floor(sec / 60);
        var s = sec % 60;
        return (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
    }

    function renderDisplay() {
        var lv = getLevelData(TIMER.current_level);
        if (!lv) return;

        if (parseInt(lv.is_break)) {
            document.getElementById('levelLabel').textContent = 'BREAK';
            document.getElementById('blinds').textContent = 'Break Time';
        } else {
            var playNum = 0;
            for (var i = 0; i < LEVELS.length; i++) {
                if (!parseInt(LEVELS[i].is_break)) playNum++;
                if (parseInt(LEVELS[i].level_number) === TIMER.current_level) break;
            }
            document.getElementById('levelLabel').textContent = 'Level ' + playNum;

            var blindsHtml = fmtChips(parseInt(lv.small_blind)) + ' / ' + fmtChips(parseInt(lv.big_blind));
            if (parseInt(lv.ante) > 0) {
                blindsHtml += ' / <span style="position:relative;display:inline-block">' + fmtChips(parseInt(lv.ante))
                    + '<span style="position:absolute;left:50%;transform:translateX(-50%);bottom:-0.5em;font-size:0.3em;color:#f59e0b;font-weight:700">ANTE</span></span>';
            }
            document.getElementById('blinds').innerHTML = blindsHtml;
        }

        var clock = document.getElementById('clock');
        clock.textContent = fmtTime(TIMER.time_remaining_seconds);
        clock.className = 'tv-clock';
        if (!TIMER.is_running) clock.classList.add('paused');
        if (TIMER.time_remaining_seconds <= 60 && TIMER.is_running) clock.classList.add('warning');

        document.getElementById('pausedLabel').textContent = TIMER.is_running ? '' : 'PAUSED';

        // Next level
        var nextLv = getLevelData(TIMER.current_level + 1);
        if (nextLv) {
            if (parseInt(nextLv.is_break)) {
                document.getElementById('nextLevel').innerHTML = 'Next: Break';
            } else {
                var nextHtml = 'Next: ' + fmtChips(parseInt(nextLv.small_blind)) + ' / ' + fmtChips(parseInt(nextLv.big_blind));
                if (parseInt(nextLv.ante) > 0) {
                    nextHtml += ' / <span style="position:relative;display:inline-block">' + fmtChips(parseInt(nextLv.ante))
                        + '<span style="position:absolute;left:50%;transform:translateX(-50%);bottom:-0.6em;font-size:0.4em;color:#f59e0b;font-weight:700">ANTE</span></span>';
                }
                document.getElementById('nextLevel').innerHTML = nextHtml;
            }
        } else {
            document.getElementById('nextLevel').textContent = 'Final Level';
        }
    }

    function renderInfoBar(session, pool) {
        var h = '';
        if (pool.total_players !== undefined) h += '<span>Players: <span class="tv-info-val">' + pool.total_players + '</span></span>';
        if (pool.still_playing !== undefined) h += '<span>Playing: <span class="tv-info-val">' + pool.still_playing + '</span></span>';
        if (pool.eliminated !== undefined && parseInt(pool.eliminated) > 0) h += '<span>Out: <span class="tv-info-val">' + pool.eliminated + '</span></span>';
        if (pool.pool_total !== undefined) h += '<span>Pool: <span class="tv-info-val">$' + (parseInt(pool.pool_total) / 100).toFixed(2) + '</span></span>';
        document.getElementById('infoBar').innerHTML = h;
    }

    // Local tick for smooth countdown between polls
    setInterval(function() {
        if (TIMER.is_running && TIMER.time_remaining_seconds > 0) {
            TIMER.time_remaining_seconds--;
            var clock = document.getElementById('clock');
            if (clock) {
                clock.textContent = fmtTime(TIMER.time_remaining_seconds);
                clock.className = 'tv-clock';
                if (TIMER.time_remaining_seconds <= 60) clock.classList.add('warning');
            }
        }
    }, 1000);
    </script>
</body>
</html>
