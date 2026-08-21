
# Game Day — phone-first tournament director console

**Branch:** `GameNight-MobileGameManager` (off `main` @ `ffb7b01`, v0.2106)
**New page:** `/gameday.php?event_id=N`

---

## 0. Corrections to the brief — verify these before you build

Everything in the brief checked out except the following. Each one changes a design decision.

| # | Brief says | Code says | Consequence |
|---|---|---|---|
| **0.1** | "New page … will include `_footer.php`" | `timer.php`, `timer_beta.php` and `walkin_display.php` are all standalone and do **not** include `_footer.php`; `walkin_display.php:252-253` loads `pk-dialogs.js` + `pk-dispatch.js` by hand | **Do not include `_footer.php`.** It injects `<link rel="manifest" href="/manifest.php">` (`_footer.php:92-98`), and `manifest.php` hardcodes `start_url: '/'` — so Add to Home Screen from `/gameday.php` would launch the **home page**, destroying the chrome-free PWA requirement. It also drops a `position:fixed;right:1rem;bottom:1rem` push-prompt card (`_footer.php:~190`) straight on top of the bottom sheet, adds a 15 s `notify_status.php` poll, and pulls in the help-bubble machinery. Follow `walkin_display.php`: load `pk-seg.js`, `pk-dialogs.js`, `pk-dispatch.js` explicitly. You still get the **shared** dispatcher, which is what the convention actually requires. |
| **0.2** | "Rebalance button showing the moves list … **before**/after applying" | `rebalance_tables()` (`_poker_helpers.php:586-681`) **writes as it goes** — there is no dry-run mode | No "preview then apply". Do: `pkConfirm` first (generic warning), POST, then show the returned `moves` in a `pkAlert`. Also note it calls `pick_random_seat()` for **every** player, so *seat numbers change for everyone* even where the table did not — say so in the dialog. |
| **0.3** | "`rebalance_tables` returns moves array" | returns `{ok, players, moves}` (`checkin_dl.php:1706-1712`) | Better than stated: take `players` wholesale, no reload needed. |
| **0.4** | "per-player Move (`set_table`)" | `set_table` (`checkin_dl.php:990-1006`) reads `$_POST['table_number']` with **no `??` default** and no range check; a missing key gives `null !== ''` → `(int)null` → **table 0**. `move_player_table` (`:1603-1626`) validates `1..num_tables` and returns `{player, players}` | Use **`move_player_table`** for moves between tables. Use `set_table` only for "Unassign", and then always send `table_number=''` explicitly. |
| **0.5** | "classic `timer.js:552-678` is the simpler [clock]" | It is simpler, but it does **not** use the anchor — `startLocalTick()` (`timer.js:632`) decrements a local counter and `pollState()` assigns `time_remaining_seconds` from each poll | **Copy `timer_beta.js`**, not `timer.js`. Specifically `noteClockSample`/`clockOffset`/`serverNow`/`liveRemaining` (`timer_beta.js:393-427`) and `applyTimerSync` (`:1958-1991`). At a 3 s cadence the classic path visibly stutters; the anchor path makes stutter *impossible*, which is the entire reason `ends_at_ms` exists. |
| **0.6** | "Eliminated → **Re-enter** or Undo" (Re-enter listed first) | A wrong heads-up KO **auto-finishes the game and issues entry tickets** (`checkin_dl.php:1053-1066` → `pk_finish_session`) | The mistake path is the time-critical one. Design: a 10 s **post-KO snackbar with a one-tap Undo**, then `Re-enter` as the row primary for older eliminations, with both always in the sheet. Details in §7.4. |
| **0.7** | `timer_dl` command "returns … state" (implied) | `action=command` returns **`{ok:true}` and nothing else** (`timer_dl.php:434`) | Every control-row tap must be followed by an immediate `get_state()`, exactly as `timer.js:562` does. |
| **0.8** | "zero or near-zero new endpoint code" | Measured against dev (event 237): `get_session` = **28,883 bytes**, of which **`log` alone is 22,890 (79 %)** — and Game Day never renders the log. `get_state` = 8,002 bytes | Recommend a **3-line additive delta** to `checkin_dl.php` (§4.3). Zero-backend fallback documented. |

### 0.9 Working-tree hazard — read before you touch anything

`/home/bryce/Claude/GameNight-dev` is **not** a clean mirror of `main`. It carries ~761 uncommitted lines of the chip-bonus feature in `www/checkin.php`, `www/checkin_dl.php`, `www/db.php`, `www/_poker_helpers.php` — the tip of `origin/GameNight-Payout-RSVPBonus` (`5caca21`), applied to the working tree. Its DB has the matching `bonus_chips` / `bonus_ids` columns.

- **Never bulk-mirror those four files** from `GameNight` to `GameNight-dev`. Mirroring `checkin.php` or `checkin_dl.php` wholesale reverts the dev container mid-test. (Recoverable from origin, but you will lose an afternoon working out why.)
- `gameday.php` and `gameday.js` are **new** files — mirror them freely.
- For the `checkin.php` header-link edit and the optional `checkin_dl.php` delta, apply the same hunk **by hand** on the dev side.
- `event.php` is untouched in dev — safe to mirror.
- Merge-conflict note: `GameNight-Payout-RSVPBonus` inserts `'bonuses' => …` into the same `get_session` response array we would touch. Append your new keys **after `'jackpots'`** so the hunks are as far apart as the array allows.

### 0.10 Aside, out of scope
`walkin_display.php:32-36` still gates on `LOWER(username) = LOWER(?)` against `event_invites` — the exact join `CLAUDE.md:75` forbids. Not this feature's job; worth a separate ticket.

---

## 1. File list

**New**

| File | Size est. | Notes |
|---|---|---|
| `www/gameday.php` | ~450 lines | Gates + bootstrap + inline `<style>` + static skeleton + nonced config block. No `_nav.php`, no `_footer.php`. |
| `www/gameday.js` | ~900 lines | All behaviour. External → covered by `script-src 'self'`, no nonce, checked directly by `node --check`. Cache-buster mandatory. |

**Why external JS, not a giant inline block:** `SECURITY.md §1a` exists because one `SyntaxError` in PHP-generated JS discards the whole `<script>` and every function in it — and `php -l` cannot see it. An external file is checked by `node --check` for free, caches across the reloads a 4-hour session will accumulate, and is diffable. Only a ~12-line nonced config block stays in the page (the `timer_beta.php:171-185` pattern).

**Why inline `<style>`, not `gameday.css`:** the CSS is page-only and ~7 KB; `notifications.php:47-61` and `walkin_display.php` both do exactly this. One fewer asset is one fewer cache-buster to forget — and a forgotten cache-buster on CSS cost the stylesheet in v0.2062.

**Edited (links only)**

| File | Line | Change |
|---|---|---|
| `www/checkin.php` | 1326 | Add a `Game Day` anchor beside the existing `#timerLink` in the JS-built `.pk-header` |
| `www/event.php` | 249 | Add a `Game Day` button beside `Manage Game` in the `$canManage` host action row |
| `www/version.php` + `CHANGELOG.md` | — | Final commit of the branch, per `CLAUDE.md` |

**Optional (§4.3)**: `www/checkin_dl.php` — 3 additive lines in `get_session`.

**QA (in `~/qa-headless`)**: new `gameday_check.js`; add `/gameday.php?event_id=237` to `PAGES` in `inline_js_sweep.js:23` **and** `double_dispatch_sweep.js:13`.

---

## 2. `gameday.php` — PHP top section

Model: `walkin_display.php` shape, `checkin.php:1-18` gate, `timer.php:311-320` head.

```
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_poker_helpers.php';

$current = require_login();
$db      = get_db();
$isAdmin = $current['role'] === 'admin';

$event_id = (int)($_GET['event_id'] ?? 0);
  → 400 if missing
  → SELECT * FROM events WHERE id = ?   → 404 if absent
  → can_manage_event($db, $event_id, (int)$current['id'], $isAdmin)  → 403
```

`can_manage_event()` (`db.php:3235`) is the single gate — do not re-derive it. `checkin.php:16` is the precedent.

Then, **read-only** bootstrap:

```
SELECT * FROM poker_sessions WHERE event_id = ?     → $session
  if (!$session)  → 302 to /checkin.php?event_id=N   (session creation stays on the console)
  if ($session['game_type'] !== 'tournament') → 302 to /checkin.php?event_id=N   (v1 is tournaments only)

SELECT * FROM timer_state WHERE session_id = ?      → $timer   (may be false)
$csrf = csrf_token();
$site_name = get_setting('site_name', 'Game Night');
```

### 2.1 Do NOT call `pk_ensure_timer_row()`

Tempting, but wrong. `pk_ensure_timer_row()` (`_poker_helpers.php:240-250`) inserts with `preset_id = NULL, time_remaining_seconds = 900`. `timer.php:186-208` provisions the *same row* with the site default blind preset and its level-1 duration. If Game Day wins the race, the host later opens `/timer.php` and finds a timer with **no blind schedule at all**. Instead:

- `$timer === false` → render the header in a "Timer not set up" state with a single button to `/timer.php?event_id=N` (which provisions correctly), and set `window.GD.sessionId` anyway so the roster half of the page works fully.
- This is also why the CSRF-refresh channel must not depend on the timer row (§4.3).

### 2.2 Server-side vs client-side data

| Data | Where | Why |
|---|---|---|
| event id, session id, session status, game type, `csrf`, event title, `can_control`, asset stamp | **Server**, into the nonced config block | First paint needs no round-trip; these are the values a fetch cannot supply before it lands |
| Session config (`buyin_amount`, `rebuy_allowed`, `addon_allowed`, `num_tables`, `bounty_*`, `jackpot_*`) | **Client**, first `get_session` | Live-editable from the console on another device; a server snapshot would go stale within seconds |
| Roster, pool, payouts, tickets | **Client**, first `get_session` | Same |
| Levels, clock anchors | **Client**, first `get_state` | Anchors are meaningless without `server_now_ms` from the same response |

Result: the PHP render is a static skeleton plus ~12 lines of config. Target page weight **under 25 KB** against `checkin.php`'s measured 302 KB.

---

## 3. `<head>` and asset loading

```html
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Game Day">
<title>Game Day — {event title} — {site name}</title>
<link rel="icon" href="/favicon.php">
<link rel="stylesheet" href="/style.css?v=<?= htmlspecialchars(APP_VERSION.'.'.(@filemtime(__DIR__.'/style.css')?:0)) ?>">
<style> …page CSS… </style>
```

`viewport-fit=cover` + the three meta tags reproduce `timer.php:311-320`. **No `<link rel="manifest">`** — see §0.1: without one, iOS Add to Home Screen uses the *current URL* as the start URL, which is what makes a per-event Game Day icon possible.

Scripts, at end of `<body>`, **in this order**:

```html
<script src="/pk-seg.js?v=…"></script>                      <!-- NOT deferred (house rule) -->
<script src="/vendor/nosleep.min.js"></script>
<script nonce="<?= csp_nonce() ?>">window.GD = { … };</script>
<script src="/pk-dialogs.js?v=…" defer></script>
<script src="/pk-dispatch.js?v=…" defer></script>
<script src="/gameday.js?v=<?= htmlspecialchars(APP_VERSION.'.'.(@filemtime(__DIR__.'/gameday.js')?:0)) ?>" defer></script>
```

Deferred scripts run in document order, so `gameday.js` sees `pkConfirm` and the dispatcher already defined; `pk-seg.js` is non-deferred and already executed.

Config block (JS-source context, so `json_encode` is correct here and **only** here — see `SECURITY.md`'s escaping table):

```php
window.GD = {
  eventId:   <?= (int)$event_id ?>,
  sessionId: <?= (int)$session['id'] ?>,
  csrf:      <?= json_encode($csrf, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>,
  title:     <?= json_encode($event['title'], JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>,
  status:    <?= json_encode($session['status']) ?>,
  hasTimer:  <?= $timer ? 'true' : 'false' ?>,
  assetV:    <?= (int)(@filemtime(__DIR__.'/gameday.js') ?: 0) ?>
};
```

**Do not** set `window.PK_DISPATCH_LOCAL`.

---

## 4. Liveness architecture

### 4.1 Two pollers, not one

Consolidating would mean pulling the 29 KB roster every 3 s. Keep them separate — different payloads, different cadences, different failure meanings.

| Poller | Endpoint | Visible | Hidden | Payload | Supplies |
|---|---|---|---|---|---|
| **Clock** | `GET /timer_dl.php?action=get_state&session_id=N` | **3 s** | stopped | 8.0 KB | `timer.ends_at_ms`/`remaining_ms`, `server_now_ms`, `levels`, `pool`, `payouts`, `can_control`, `session_status`, **`csrf_token`** |
| **Roster** | `GET /checkin_dl.php?action=get_session&event_id=N&slim=1` | **10 s** | stopped | 6.0 KB slim / 28.9 KB not | `session` config, `players`, `payouts`, `pool`, `tickets` |

The anchor protocol is what lets the clock poll drop from `timer.js`'s 2 s to 3 s with no visible cost: between polls the clock is *derived* from `ends_at_ms`, not told. 3 s is only the reaction latency to another device pausing.

Bandwidth: 8/3 + 6/10 ≈ **3.3 KB/s ≈ 12 MB/hour** with the slim flag; ≈ 4.6 KB/s without. Over a 4-hour tournament that is the difference between 47 MB and 66 MB on a phone.

Both use `setTimeout` self-rescheduling (never `setInterval`) so a slow response cannot stack requests, and both hold an in-flight guard.

### 4.2 The header stats come from the *clock* poll

`get_state` already returns `pool` (identical `calc_pool()` output, 558 bytes) and `payouts`. So players-left / entrants / avg stack / prize pool refresh every 3 s and stay correct even if the roster poll is wedged. Derived exactly as `timer_beta.js:2026-2033`:

- players left / entrants → `pool.still_playing` / `pool.total_players`
- entries → `pool.total_buyins`
- avg stack → `Math.round(pool.chips_in_play / pool.still_playing)` (guard divide-by-zero → `–`). This also picks up chip bonuses automatically if `GameNight-Payout-RSVPBonus` lands, since `chips_in_play` already includes them there.
- prize pool → `pool.pool_total`; top-3 payouts → `Math.round(pool_total * payouts[i].percentage / 100)`, same formula as `checkin.php:2764`.

### 4.3 CSRF — the whole point of the page

The failure mode is real and verified: `csrf_token()` (`auth.php:417-423`) mints a **new** token whenever the session has none; `current_user()` (`auth.php:207-219`) silently re-establishes a session from the remember cookie with `session_regenerate_id(true)` after the 8-hour GC (`auth.php:101`). GET polls keep returning 200; every POST 403s. `checkin.php` bakes `CSRF` once at line 880 and never refreshes it — a silently read-only console.

**Rule for this page: `GD.csrf` is never trusted, only refreshed.**

1. Every `get_state` response carries a fresh `csrf_token` (`timer_dl.php:311`). On each poll: `if (j.csrf_token) GD.csrf = j.csrf_token;`
2. **`postAction()` retries once on 403.** On `res.status === 403` (or `j.error` matching `/csrf/i`), await one immediate `pollClock()`, then replay the POST with the refreshed token. Exactly once — a second 403 surfaces the banner. This closes the window *between* polls, which item 1 alone does not.
3. **The timer row may not exist** (§2.1), and then `get_state` returns `{ok:false,'Timer not found'}` and no token. Two ways out:

   **Recommended — 3 additive lines in `checkin_dl.php` `get_session` (append after `'jackpots'`):**
   ```php
   'csrf_token' => csrf_token(),
   'asset_v'    => (int)(@filemtime(__DIR__ . '/gameday.js') ?: 0),
   ```
   and change one line:
   ```php
   'log' => empty($_GET['slim']) ? get_session_log($db, (int)$session['id']) : [],
   ```
   All three are additive and invisible to `checkin.php`, which never sends `slim`. Returning a CSRF token on a login-gated, `verify_event_access`-gated, same-origin GET is already precedent (`timer_dl.php:311`), and CORS blocks cross-origin reads. This makes the refresh channel independent of the timer row **and** cuts the roster poll by 79 %.

   **Zero-backend fallback:** roster poll at 15 s without `slim`, and when there is no timer row the page shows a persistent amber "Timer not set up — set it up to enable live controls" strip; writes still work until the session GC, then the stale banner fires and offers reload.

### 4.4 Wake lock

Port `timer.js:772-840` verbatim (§7.7), including the NoSleep fallback and the banner:

- `navigator.wakeLock.request('screen')` on load, in a try/catch.
- `NoSleep` (loaded from `/vendor/nosleep.min.js`) engaged from a real gesture — `document.addEventListener('click' | 'touchend', acquire, true)` — because iPhone Safari over plain HTTP has no Wake Lock API at all, which is exactly the LAN-dev case.
- `#gdWakeBanner` "Tap anywhere to keep this screen on", removed on desktop (`!('ontouchstart' in window) && navigator.maxTouchPoints === 0`) and hidden on first successful acquire.
- On `visibilitychange → visible`: reset `wakeLockAcquired = false`, re-request, and **immediately** `pollClock()` + `pollRoster()` (Android throttles timers while hidden). This is the same block as `timer.js:830-840`.

### 4.5 Stale / offline banner

A single `#gdNet` strip pinned directly under the sticky header, three states:

| State | Trigger | Text |
|---|---|---|
| hidden | last clock poll ok within 12 s | — |
| **stale** (amber `#fffbeb`/`#f59e0b`/`#92400e`) | `Date.now() - lastClockOkAt > 12000`, or any fetch rejects | "Reconnecting… last update {n}s ago" |
| **blocked** (red) | a POST 403s twice after a CSRF refresh, or `get_state` returns `ok:false` twice | "Session expired — tap to reload" (tap → `location.reload()`) |

Amber for degraded, red for broken. This is the direct answer to "fetch errors swallowed silently". Every `.catch()` in the file routes to `gdNoteFail()` — none is empty.

### 4.6 Self-reload on new build

`timer_beta.js:2015-2020`: compare `GD.assetV` against the `asset_v` in each poll; if they differ, set a one-shot guard and `location.reload()` after 500 ms. Ships with the §4.3 backend delta; skip it in the zero-backend variant. A console left open across a deploy otherwise never gets the fix.

---

## 5. Render approach — DOM building, no HTML strings

**Rule: nothing on this page is built by string concatenation.** No `escHtml` in the render path at all — user text reaches the DOM only via `textContent`, and only integers reach `data-*` attributes.

### 5.1 Row patching, not re-render

Keep `var rowEls = new Map();  // playerId -> {root, name, meta, badge, primary}`.

`renderRoster()`:
1. Compute `visible` = filter + search + sort.
2. For each id in `visible`: `rowEls.get(id)` or `buildRow(p)`; then `patchRow(el, p)` — `el.name.textContent = p.display_name`, `el.meta.textContent = seatLabel(p)`, `el.badge.textContent/className`, `el.primary.textContent` + `dataset.act`, `el.root.className`.
3. Reorder with a single pass of `list.insertBefore(el.root, list.children[i])` — a no-op when already in place.
4. Remove rows whose id left `visible`; drop them from the Map.

Why this and not `innerHTML =`: the roster repaints every 10 s. Wholesale replacement discards scroll position, kills the row the host's thumb is already on, and re-creates elements 40 times an hour for nothing. Patching also makes the "don't repaint while a field is focused" guard (`checkin.php:5124-5130`) unnecessary — the only editable field on the page is the search box, and it is never inside the list.

### 5.2 The one escaping exception: `pk*` dialog text

`pk-dialogs.js:157` sets `msgEl.innerHTML = o.message`, and `:155` does the same for the title. Names in a dialog therefore need escaping. Carry a **single** private helper at the top of `gameday.js`, the same implementation as `checkin.php:5106` (text-node round-trip **plus** explicit `"` and `'` escaping, so it is attribute-safe as well), used **only** for `pkConfirm`/`pkAlert`/`pkPrompt` message and title strings. Nowhere else. Grep-able: `gdEsc(`.

Two parser gotchas with `pkConfirm`:
- `msgEl` is a `<p>`. Only **phrasing content** survives — `<span>`, `<label>`, `<select>`, `<option>`, `<br>`, `<b>` are fine; a `<div>` gets auto-closed by the parser and your markup lands outside the dialog. `checkin.php:4131-4137` already respects this.
- `settle()` (`pk-dialogs.js:143-147`) removes `.open` **then** resolves, so an embedded `<select>` is still in the DOM inside `.then()`. That is what makes the bounty picker work (§7.2), and it is exactly what `confirmElim()` relies on.

### 5.3 Naming

Every global in `gameday.js` is prefixed `gd` (`gdKO`, `gdBuyIn`, `gdOpenSheet`, `gdCmd`, …). Not for collision-avoidance — there is none — but because `double_dispatch_sweep.js` prints handler names, and a prefixed name tells you instantly which page a failure came from.

---

## 6. UI sections, endpoints, and merge strategy

### 6.0 Layout (top → bottom)

```
┌ sticky header (tap to expand controls) ────────────┐
│  LEVEL 5              12:43                        │
│  200 / 400 (a25)   →  300 / 600                    │
│  9/11 left · avg 12,222 · $275 · 1st $137          │
│  ▸ [ ⏮ ] [ ▶︎/⏸ ] [ ⏭ ] [ −1m ] [ +1m ] [ ↶ ]      │  ← hidden until header tap
└────────────────────────────────────────────────────┘
  [ net / stale strip ]                                 (conditional)
  [ amber: 2 waiting to be approved  ▸ ]                (conditional)
  [ amber: Game not started · Start game ]              (status=setup only)
  [ search ]   ( Playing | All | Out | Seats )           ← ONE pk-seg
┌ #gdView ───────────────────────────────────────────┐
│  roster rows, or seats view                        │
└────────────────────────────────────────────────────┘
  [ + Walk-in ]  fixed footer button
```

**One switcher, not two.** The brief wanted a Playing/All/Out/Pending filter *and* a Seats view. Two stacked `pk-seg` strips on a 360 px phone is a wasted 90 px. Collapse to a single 4-segment `pk-seg#gdViewSeg` — **Playing · All · Out · Seats** — and give Pending its own permanent amber strip instead of a filter segment. Rationale worth writing in the comment: *a pending player you have to switch views to see is a pending player you miss.* This keeps exactly one switcher idiom on the page, per `CLAUDE.md`'s UI convention.

`pk-seg` wiring:
- Markup contract exactly as documented: `<div class="pk-seg" id="gdViewSeg"><span class="pk-seg-thumb"></span><button data-view="playing" class="active" data-act="gdSetView" data-a1="playing">Playing</button>…`
- `gdSetView(v)`: bail if `v === VIEW` (no replayed animation); `dir = segTravelDirection('gdViewSeg','data-view',VIEW,v)`; set `VIEW`; toggle `.active`; `positionSegThumb('gdViewSeg', true)`; render; `slideViewIn(document.getElementById('gdView'), dir)`.
- First paint: `positionSegThumb('gdViewSeg', false)` inside a `requestAnimationFrame` **after** the first roster render (a hidden control measures zero and `positionSegThumb` bails).
- Re-measure with `positionAllSegThumbs(false)` whenever the pending strip or setup strip appears/disappears — those change the strip's available width.

### 6.1 Sticky timer header

Content per §4.2 plus, from `get_state`: `levels` → current level row and `level+1` row for "next blinds". Break rows (`is_break`) render as `BREAK` / `On Break`, mirroring `timer_beta.js`'s `ELEMENTS.blinds`.

Clock: local `setInterval(tick, 250)` → `fmtClock(liveRemaining())`. `liveRemaining()` = `(anchorEndsAt - serverNow())/1000` when running, `anchorRemainingMs/1000` when paused (`timer_beta.js:422-428`). 250 ms rather than 1000 ms so the displayed second flips within a quarter-second of the true boundary — free, since it's one `textContent` write.

Control row, hidden behind a header tap (`data-act="gdToggleControls"` on the header):

| Button | Call |
|---|---|
| ⏮ prev | `gdCmd('skip_prev')` |
| ▶︎/⏸ | `gdCmd('toggle_play')` |
| ⏭ next | `gdCmd('skip_next')` |
| −1m | `gdCmd('sub_time')` |
| +1m | `gdCmd('add_time')` |
| ↶ undo | `gdCmd('undo')` |

```
gdCmd(cmd) → POST /timer_dl.php {action:'command', cmd, session_id, csrf_token}
             → response is {ok:true} ONLY  → always pollClock() immediately
             → !ok → pkAlert(j.error)   ("Nothing to undo." is a real, expected answer)
```

All six carry `data-act="gdCmd" data-a1="<verb>"` — one handler, six controls, all visible to the dispatch sweep. Hide the whole control row when `can_control === false`; **`gdCmd` must still be defined unconditionally** (the double-dispatch sweep asserts `typeof window[name] === 'function'` for every rendered control, and a control that only sometimes renders still needs its handler always defined).

Undo has a 10-minute expiry and is one-deep (`timer_dl.php:415-425`) — label it `↶` with `title="Undo the last timer action"`, don't promise more.

### 6.2 Roster

Per-row: name, `T2 #4`, status badge, one primary button.

| Player state | Primary | Endpoint |
|---|---|---|
| `bought_in = 0` | **Buy In** (green) | `toggle_buyin` (+ ticket prompt, §9.3) |
| `bought_in = 1, eliminated = 0` | **KO** (red) | `eliminate_player` (§7) |
| `eliminated = 1`, rebuys allowed, status ≠ finished | **Re-enter** | `update_rebuys` delta `+1` |
| `eliminated = 1`, otherwise | **Undo** | `uneliminate_player` |
| `finish_position = 1`, not eliminated | 🏆 badge, no primary | — |

Search: plain `input`, `data-act-input="gdSearch" data-input-a1="@value"`, case-insensitive substring on `display_name`, debounced 120 ms. Never inside the scrolling list.

Sort: still-in first, then by table/seat, then name — so the thumb-reachable top of the list is the live field.

### 6.3 Bottom action sheet (row body tap)

`row.dataset.act = 'gdOpenSheet'; row.dataset.a1 = String(p.id)`. The primary button carries its own `data-act`, so `closest('[data-act]')` resolves to the button first — no `data-stop` needed. (Use `data-stop` only where a child has *no* action of its own.)

Sheet is one reused DOM node, repopulated per open via `textContent`; `translateY` in/out; backdrop tap closes (`data-act-self="gdCloseSheet"`); `padding-bottom: max(1rem, env(safe-area-inset-bottom))`.

| Control | Gate | Endpoint | Response merge |
|---|---|---|---|
| Rebuy − / n / + | `session.rebuy_allowed` | `update_rebuys {player_id, delta}` | `{player, reentered, reopened, status, pool}` — if `reentered \|\| reopened` → **full `loadRoster()`** (the eliminator's banked bounty and every payout changed); else `mergePlayer` + `POOL = j.pool` |
| Add-on − / n / + | `session.addon_allowed` | `update_addons {player_id, delta}` | `{player, pool}` → merge |
| Bounty chip | `bounty_amount > 0 && bounty_optional` | `toggle_bounty {player_id}` | `{player, pool}` → merge |
| Jackpot entry | `jackpot_amount > 0 && jackpot_optional` | `toggle_jackpot {player_id}` | `{player, pool}` → merge |
| Move to table… | `num_tables > 1` | **`move_player_table {player_id, new_table}`** (see §0.4) | `{player, players}` → take `players` |
| Unassign seat | `table_number != null` | `set_table {player_id, table_number:''}` | `{player}` only — **no pool** → merge player, leave `POOL` |
| Notes | always | `pkPrompt` → `update_notes {player_id, notes}` | `{player}` → merge |
| Ledger | always | `GET get_ledger&player_id=N` | render `ledger[]` read-only in a `pkAlert`-style panel; rows carry `time`, `time_ts`, `actor`, `amount`, `detail`, `voided` (`_poker_helpers.php:974-987`). **Read-only in v1** — voiding/editing entries stays on the console. |
| Undo buy-in | `bought_in = 1` | `toggle_buyin {player_id}` (no `set`) | `{player, pool}` → merge. Confirm first: it un-applies any redeemed entry ticket. |
| Re-enter / Undo KO | `eliminated = 1` | as §6.2 | as §6.2 |
| Remove | always | `remove_player {player_id}` | **`{pool}` only** — delete the row locally and drop it from `rowEls` |

`pkConfirm` before Remove and before Undo buy-in; nothing else in the sheet needs one.

### 6.4 Walk-in / pending strip

**Pending strip.** Rendered whenever any player has `approval_status === 'pending'` (from `get_players`' `COALESCE(ei.approval_status,'approved')`, `_poker_helpers.php:497-506`). Amber, pinned under the header, one row per pending player with inline **Approve** / **Deny**. Pending players do **not** appear in the main list — they are not on the roster yet.

- `approve_player` / `deny_player {player_id}` → both return `{status, players, pool}` → **take `players` wholesale**, no reload.

**Arrival detection.** Port `checkin.php:5133-5182` verbatim: a `WALKIN_SEEN` `Set` of pending ids, `null` until the first payload (so pre-existing pendings never chirp), diffed on every roster poll → `walkinToast()` + `walkinChirp()`. Two changes for phone:
- the toast is tappable and, on tap, switches focus to the pending strip;
- the chirp's `AudioContext` is primed on the first real gesture (the same unlock the wake lock already needs), so it is actually audible rather than blocked.

**Add walk-in.** Fixed footer button → `pkPrompt('Name')` → `add_walkin {session_id, name}` → returns **full `{players, player_id, pool}`** → take `players`, then scroll the new `player_id` into view and flash its row. No autocomplete in v1 (the console has it; a phone keyboard plus a 400-name dropdown is not the win it sounds like).

### 6.5 Seats view

Fourth segment. Groups `players` (still in, `bought_in && !eliminated`) by `table_number`, ordered by `seat_number`. Per table: a header card with the seat count, then rows with seat number, name, and a small **Move** chip → table picker sheet → `move_player_table`.

An "Unassigned" group collects `table_number == null` players.

**Rebalance** button in the Seats view header:

```
1. pkConfirm('Rebalance N players across M tables? Everyone is given a new
              random seat, including players who stay at their table.',
             { okLabel: 'Rebalance' })
2. POST rebalance_tables { session_id, protected_ids: JSON.stringify([]) }
3. take j.players wholesale
4. pkAlert listing j.moves:  "Dave Okafor  T1 → T2"  (old_table may be null → "unseated → T2")
   or "No moves needed — tables are already balanced." when moves is empty
```

`protected_ids` is sent as a JSON string (`checkin_dl.php:1701` does `json_decode`) — this is the one place a JSON string is correct, because it is a **POST body field**, not a `data-*` attribute. Ship it as `'[]'` in v1; the "protect the button" refinement can come later.

### 6.6 Pool strip

Third line of the sticky header, from the clock poll (§4.2): `$275 · 1st $137 · 2nd $82 · 3rd $55`. Places with `percentage = 0` but a `ticket_cents` or `prize_label` render the label instead of a dollar figure (a points league has no pool — `timer_beta.js:2052-2060` has the precedent for not letting the dollar amount gatekeep). Truncate to the first three places; the full ladder stays on the console.

### 6.7 Lifecycle

- **`status = 'setup'`** — full roster and buy-ins work. Amber strip: *"Game not started"* + **Start game** → `update_status {session_id, status:'active'}`. Returns **`{status}` only** → set `SESSION.status`, re-render, and fire one `pollClock()`.
- **`status = 'active'`** — the main case.
- **`status = 'finished'`** — reachable without leaving the page, because a heads-up KO auto-finishes (§9.1). Show a green winner banner (`🏆 {name} wins — $X`), hide the KO primaries, keep Re-enter available (it can legitimately reopen the game). Do **not** offer Reopen; that path can fail on redeemed tickets (`pk_unfinish_session`) and belongs on the console.
- **Finish game: deliberately not on this page**, and I'd keep it that way. `pk_finish_session()` issues entry tickets, locks payouts, and its reversal is *conditionally blocked* once a ticket is redeemed. That is a decision made at the end of the night with the payout ladder in front of you, not a button a thumb can find while reaching for KO. Add a plain link to `/checkin.php?event_id=N` labelled "Finish & payouts" instead.

### 6.8 Merge rules — the complete table

| Action | Response | Client does |
|---|---|---|
| `toggle_buyin` | `{player, pool}` | merge player, set POOL. **If a ticket was applied: full `loadRoster()`** (the ticket list and log changed) |
| `update_rebuys` | `{player, reentered, reopened, status, pool}` | `reentered\|\|reopened` → `loadRoster()` + `pollClock()`; else merge |
| `update_addons` | `{player, pool}` | merge |
| `toggle_bounty` / `toggle_jackpot` | `{player, pool}` | merge |
| `set_table` | `{player}` **no pool** | merge player only |
| `move_player_table` | `{player, players}` | take `players` |
| `eliminate_player` | `{player, winner, status, pool}` | merge player; if `winner` → merge winner, set `SESSION.status = j.status`, winner banner, `pollClock()` |
| `uneliminate_player` | `{player, reopened, pool}` | `reopened` → `loadRoster()` + `pollClock()`; else merge |
| `add_walkin` | `{players, player_id, pool}` | take `players`, highlight `player_id` |
| `approve_player`/`deny_player` | `{status, players, pool}` | take `players` |
| `remove_player` | `{pool}` **only** | delete row locally, set POOL |
| `update_notes` | `{player}` | merge |
| `rebalance_tables` | `{players, moves}` | take `players`, show `moves` |
| `update_status` | `{status}` **only** | set `SESSION.status`, re-render, `pollClock()` |
| `timer command` | `{ok}` **only** | `pollClock()` |

---

## 7. The eliminate flow

### 7.1 Guard

`gdKO(pid)`:
1. If `!p.bought_in` → `pkAlert('This player has not bought in yet. Buy them in before eliminating.')` and stop (mirrors `checkin.php:4118-4121`; the server would derive a place from the wrong count).
2. If `p.eliminated` → not reachable (the primary is Undo/Re-enter), but the server rejects it anyway (`checkin_dl.php:1017-1023`).

### 7.2 The confirm — same context as `elimModal`

Place = `PLAYERS.filter(p => !p.eliminated && p.bought_in).length` — computed client-side **for display only**; the POST always sends `finish_position: 0` and lets `checkin_dl.php:1026-1031` derive it authoritatively. Do not send a client-computed place; two devices racing would write two different places.

Message (phrasing content only, §5.2):

```
Eliminate <b>{name}</b> in <b>{place}{ordinal} place</b>?
[if payoutForPlace(place) > 0]
  They finish in the money and are owed <b>{money}</b>.
[if bounty_amount > 0 || bounty_points > 0, and other live players exist]
  <label>Knocked out by</label><br>
  <select id="gdElimBy">
    <option value="0">— not recorded —</option>
    …other live bought-in players…
  </select>
```

Names inside the `<option>` go through `gdEsc()` — this is the page's only escaping site.

`pkConfirm(msg, { title: 'Eliminate', okLabel: 'Eliminate', danger: true })`, then in `.then(ok => …)` read `document.getElementById('gdElimBy')` (still in the DOM — §5.2) before it is overwritten by the next dialog.

### 7.3 The POST

```
eliminate_player { player_id, finish_position: 0, eliminated_by: <selected|0> }
```
Server rejects an eliminator who is not another live bought-in player (`checkin_dl.php:1035-1041`) — surface that error rather than swallowing it.

On `{winner}`: merge both rows, set status, show the winner banner, `pollClock()`.

### 7.4 Post-KO undo snackbar

Immediately after a successful KO, show a bottom snackbar for **10 s**: *"{name} knocked out — 4th"* with an **Undo** button → `uneliminate_player`. One tap, no confirm, no scrolling, and it is right where the thumb already is. This is the answer to §0.6: it makes correcting the *most recent* KO — including a wrong heads-up KO that just auto-finished the game and issued tickets — a single tap, while leaving the row primary free to be Re-enter for older eliminations.

If `uneliminate_player` returns `{reopened:true}`, resync everything (`loadRoster()` + `pollClock()`) — the winner was un-crowned and issued tickets were voided. If the server refuses (a ticket was already redeemed at its target event), `pk_unfinish_session` rolls the player state back and returns `{ok:false, error}` — show it in a `pkAlert` and do nothing else. Do not optimistically strike the row before the response.

---

## 8. Tap counts

Screen is awake (wake lock held), page is open, roster visible. "Tap" = one deliberate finger contact.

| Action | Game Day | `checkin.php` on a phone today |
|---|---|---|
| **KO a player** (no bounty) | **2** — `KO` → `Eliminate` | 3 — expand card → `Eliminate` → confirm |
| **KO with bounty credit** | **3** — `KO` → pick eliminator → `Eliminate` | 4 |
| **Undo the last KO** | **1** — `Undo` in the snackbar (≤10 s) | 3 — expand → `Undo Elim` (+ scroll) |
| **Undo an older KO** | 2 — row → `Undo` in sheet | 3 |
| **Rebuy** | **2** — row → `+ Rebuy` | 2 — expand → `+` |
| **Re-enter an eliminated player** | 3 — `Re-enter` → confirm (2 if via sheet path already open) | 3 |
| **Buy a player in** | **1** — `Buy In` | 2 — expand → checkbox |
| **Buy in with entry ticket** | 2 — `Buy In` → `Apply Ticket` | 2 |
| **Add-on** | 2 — row → `+ Add-on` | 2 |
| **Approve a walk-in** | **1** — `Approve` in the pending strip | 1 (already inline) |
| **Add a walk-in by name** | 3 — `+ Walk-in` → type → `OK` | 2 + typing |
| **Pause / resume the clock** | **2** — header → `⏸` | not possible — leave the page |
| **Next level** | 2 — header → `⏭` | not possible |
| **+1 minute** | 2 — header → `+1m` | not possible |
| **Move a player to another table** | 3 — row → `Move` → table | 2 (number input) + keyboard |
| **Rebalance tables** | 3 — `Seats` → `Rebalance` → confirm | n/a on mobile (bulk bar is dead) |
| **See a player's money history** | 2 — row → `Ledger` | not reachable on the phone layout |

Targets met: KO ≤ 3 (achieved 2), rebuy ≤ 2 (achieved 2). The two that matter most and are impossible today — timer control and undo-the-last-KO — land at 2 and 1.

Touch targets: primary row buttons **44 × 44 px** minimum, sheet buttons 48 px tall, timer control row 52 px. `-webkit-tap-highlight-color: transparent` plus a real `:active` background, so a tap that lands has visible feedback (the console already does this at `checkin.php:469-470`).

---

## 9. Edge cases

### 9.1 Heads-up KO auto-finishes the game
`checkin_dl.php:1049-1066`: when a tournament drops to one live bought-in player, the server crowns them `finish_position = 1`, sets `status = 'finished'`, and runs `pk_finish_session()` — **which issues entry tickets**. Handle: `{winner}` present → merge both rows, `SESSION.status = j.status`, winner banner, `pollClock()`, and keep the undo snackbar up (that undo is now the most valuable button on the page). Never optimistically hide the KO'd row before the response — the winner must come from the server.

### 9.2 Re-entry reopens a finished game
`update_rebuys` with `delta > 0` on an eliminated player banks the eliminator's bounty, clears the elimination, and — if the session had auto-finished — calls `pk_unfinish_session()` and flips status back to `active` (`checkin_dl.php:1224-1244`). It can also **fail** (`{ok:false, error}`) when a ticket has been redeemed elsewhere, in which case the server has already rolled everything back. Client: `reentered || reopened` → full `loadRoster()` + `pollClock()`. Never merge a single row on this path — the eliminator's `bounties_banked`, every `finish_position`, and every stored `payout` moved.

Also: with `max_rebuys` set, a re-entry that would be clamped returns `{ok:false,'No rebuys remaining — max is N.'}`. Surface it.

Confirm text (port `checkin.php:3786-3794`), which correctly varies by bounty mode:
> *optional:* "Their old bounty chip stays with whoever collected it — they can buy a new one."
> *baked:* "Any bounty already collected on them stays collected."

### 9.3 Entry-ticket redemption at buy-in — **support it, do not punt**

Punting is a money bug, not a missing nicety. `toggle_buyin` without `ticket_id` takes the `else` branch (`checkin_dl.php:772-793`), which only re-applies a ticket previously *released from this same seat*. A first-time buy-in by a ticket holder therefore records a full cash buy-in into the pool that nobody paid, while the holder walks away with a live ticket. The console had this exact bug and it is called out in the comment.

Port `checkin.php:3722-3756` — roughly 25 lines:

```
gdBuyIn(pid):
  if (buying in && GD.tickets.incoming.length):
     match on user_id if both present, else case-insensitive display_name
     if matched:
       pkConfirm('<b>{name}</b> holds a <b>{value}</b> entry ticket for this event. Apply it?'
                 + (buyin > value ? 'Collect the remaining <b>{diff}</b> in cash.'
                 :  value > buyin ? 'The extra <b>{diff}</b> joins this game\'s prize pool.' : ''),
                 { title: 'Entry Ticket', okLabel: 'Apply Ticket' })
         → POST toggle_buyin { player_id, ticket_id: ok ? t.id : 0 }
         → on success with a ticket: full loadRoster() (tickets + log changed)
```

`tickets.incoming` comes free in `get_session` (32 bytes on the fixture — it costs nothing to keep).

### 9.4 Rebuys / add-ons disabled
`update_rebuys` returns `'Rebuys not allowed'` when `rebuy_allowed = 0`; `update_addons` returns `'Add-ons not allowed'` when `addon_allowed = 0`. The QA fixture (session 144) has `addon_allowed = 0, addon_amount = 0` — so the add-on controls **must** be gated on the session flags client-side, or the sheet ships with a button that always errors.

### 9.5 No timer row
§2.1 / §4.3. Header shows "Timer not set up" + a link to `/timer.php?event_id=N`. Roster half fully functional.

### 9.6 Cash games
`gameday.php` redirects `game_type !== 'tournament'` to `checkin.php`. The Game Day link in the console header renders **only** when `isTourney()` (same guard the existing `#timerLink` uses at `checkin.php:1325`).

### 9.7 Two devices
Every write is server-authoritative and every read re-derives from a shared anchor, so two phones converge within one poll. The only genuine race is two hosts KO-ing different players simultaneously — both succeed, places are derived server-side from the live count, and both devices see the same result within 10 s. Acceptable.

### 9.8 `viewport-fit=cover` / notch
Header: `padding-top: max(.5rem, env(safe-area-inset-top))`. Sheet and the walk-in footer: `padding-bottom: max(1rem, env(safe-area-inset-bottom))`. Precedent throughout `timer.css:78-80, 328, 794-796`.

### 9.9 `prefers-reduced-motion`
Handled centrally for `slideViewIn` (`style.css:772-774` sets `animation:none`, and the helper has a timeout fallback because `animationend` never fires). Your own sheet/snackbar transitions need their own `@media (prefers-reduced-motion: reduce)` block.

---

## 10. `checkin.php` and `event.php` — links only

**`checkin.php`**, inside `renderDashboard()`'s header, directly after the Timer link at line 1326, inside the existing `if (isTourney())`:

```js
h += '<a class="pk-btn-settings" href="/gameday.php?event_id=' + <?= (int)$event['id'] ?> + '"'
   + ' style="text-decoration:none" title="Game Day: phone-first table console">'
   + '&#127918;<span class="pk-act-label"> Game Day</span></a>';
```

`.pk-act-label` is already hidden under the phone media query, so this renders as a bare glyph exactly like its neighbours. **Nothing else in `checkin.php` changes** — no mobile rendering, no dispatcher, no CSS.

**`event.php`**, in the `$canManage` row at line 249, beside `Manage Game`:

```php
<?php if ((int)$ev['is_poker'] === 1): ?>
<a class="btn" style="background:#059669;color:#fff;text-decoration:none" href="/checkin.php?event_id=<?= $page_eid ?>">Manage Game</a>
<a class="btn" style="background:#0f172a;color:#fff;text-decoration:none" href="/gameday.php?event_id=<?= $page_eid ?>">Game Day</a>
<?php endif; ?>
```

Two plain anchors — no `data-act`, no new handler, nothing for the dispatch sweep to resolve. **No auto-redirect from anywhere**, per the brief.

---

## 11. Implementation order

Each step ends in a state you can load in a browser.

1. **`gameday.php` skeleton.** Gates, bootstrap, `<head>`, static markup, `window.GD`, script tags. `gameday.js` = a stub that logs `GD`. Confirm: 200 for JamesTest on event 237, 403 for a non-manager, 302 to `checkin.php` for a cash game, 404 for a bad event id.
2. **Clock poll + header + local tick.** `pollClock()`, `noteClockSample`/`clockOffset`/`serverNow`/`liveRemaining` ported from `timer_beta.js:393-428`, `applyTimerSync` from `:1958-1991`. Header renders level, clock, blinds, next blinds, and the §4.2 stats. Verify the clock does not stutter across a poll boundary.
3. **CSRF refresh + `gdPost()` wrapper.** Retry-once-on-403. This lands before any write exists, so every write inherits it.
4. **Wake lock + `visibilitychange` + stale banner.** All three from `timer.js:772-840`. Test by killing the container for 20 s.
5. **Roster poll + row build/patch + `pk-seg` view switcher.** Playing / All / Out / Seats, search, `slideViewIn`. Read-only at this point.
6. **Primary actions:** Buy In (with the ticket prompt, §9.3), KO (with the bounty picker, §7), Re-enter, Undo, plus the undo snackbar.
7. **Bottom sheet** with the full §6.3 control set.
8. **Pending strip + `WALKIN_SEEN` arrival detection + add-walk-in.**
9. **Seats view + rebalance.**
10. **Timer control row** behind the header tap; `can_control` gating.
11. **Setup/active/finished lifecycle strips**, winner banner.
12. **Links** in `checkin.php` and `event.php`.
13. **Optional `checkin_dl.php` 3-line delta** (§4.3) — last, so it is a single reviewable commit and easy to drop if the owner prefers zero backend change.
14. **Sweeps + QA suite.**
15. **`www/version.php` bump + `CHANGELOG.md` entry as the final commit**, per `CLAUDE.md`. Then `gh pr create` → `gh pr merge --squash` → annotated tag on `main`.

Steps 1-4 are the load-bearing infrastructure and should be reviewed before step 5 starts.

---

## 12. QA — `~/qa-headless/gameday_check.js`

Base `http://localhost:8080`, login `JamesTest` / `TempTest123!`. Note the existing suites default to `BASE = 'http://192.168.51.35:8080'` and `EVENT = 162`; use `PK_BASE` / `PK_EVENT` env vars where supported and set the constant explicitly otherwise.

**Fixtures (verified in the dev DB):**

| | |
|---|---|
| Main | event **237** / session **144**, `active`, tournament, 2 tables, 9 seats, 11 players all bought in, `rebuy_allowed=1`, `addon_allowed=0`, `bounty_amount=0`, `buyin=$25`. Timer row id 290, preset 35, 19 levels. Owner is JamesTest (269). |
| Setup lifecycle | event 232 / session 140, `setup`, 10 players — **check `created_by` first**; event 241 is owned by user 270, not JamesTest |
| Bounty | the only live bounty game is event 216 / session 126, **owned by user 1 and league-gated to a league JamesTest is not in** → JamesTest gets 403. **Create the bounty fixture instead:** set `bounty_amount` and `addon_allowed` on session 144 via `checkin.php` Setup (or `docker exec gamenight-dev php -r '…UPDATE poker_sessions SET bounty_amount=500, addon_allowed=1 WHERE id=144…'`), run the bounty and add-on tests, then restore. Note this in the script's header comment. |

**Checks:**

*Access*
1. `/gameday.php?event_id=237` as JamesTest → 200; page title contains "Game Day".
2. As a non-manager (or logged out) → 403 / redirect to `/login.php`.
3. `?event_id=999999` → 404. `?event_id=` missing → 400.
4. Cash game (event 234 / session 141) → 302 to `/checkin.php`.

*Page shape*
5. Zero `on*` attributes in the rendered DOM (including JS-built) — the same scan v0.2079 used before shutting `script-src-attr`.
6. Zero un-nonced inline `<script>` blocks.
7. **Payload guard:** rendered HTML under 30 KB.
8. `window.PK_DISPATCH_LOCAL` is `undefined`.

*Clock*
9. `GD` and the timer header populate within 3 s; `#gdClock` matches `/^\d{1,2}:\d{2}$/`.
10. **No stutter:** sample `#gdClock` every 200 ms for 8 s across at least two poll boundaries; assert the numeric value is monotonically non-increasing while running. This is the regression that `ends_at_ms` exists to prevent.
11. **Anchor is used, not the fallback:** `page.route()` the `get_state` response to strip `ends_at_ms`/`remaining_ms` and confirm the clock still runs (the legacy path) — then restore and confirm the anchor path is what normally runs.
12. Header tap reveals the control row; `⏸`/`▶︎` flips `timer_state.is_running` in the DB and the button label within one poll; `+1m` raises `time_remaining_seconds` by 60; `undo` restores it.
13. `can_control=false` (impersonate a non-manager with event access, or stub the response) → control row absent, **but `typeof window.gdCmd === 'function'` still true**.

*CSRF — the headline test*
14. Load the page, then `docker exec gamenight-dev php -r` to clear `csrf_token` from the session file (or clear the PHP session dir), wait one clock poll, then perform a write. **It must succeed.** Run the same sequence against `/checkin.php?event_id=237` and confirm it **fails** — that contrast is the whole justification for the page.
15. `page.route()` one `checkin_dl.php` POST to return HTTP 403 once: assert the client re-polls `get_state` and replays the POST exactly once (not zero, not a loop).

*Roster + actions*
16. All 11 players render; search "Sarah" narrows to 1; clearing restores 11.
17. `pk-seg`: clicking each of Playing / All / Out / Seats changes the view, moves the thumb (`#gdViewSeg .pk-seg-thumb` `transform` changes), and adds a `pk-view-in-*` class to `#gdView`. Clicking the already-active segment does **not** replay the animation.
18. **KO in 2 taps:** click a row's `KO`, click `Eliminate` in the dialog; assert the DB row has `eliminated=1` and a `finish_position`, and that the row badge updates without a reload.
19. **Undo snackbar:** appears after the KO, one tap restores `eliminated=0`; auto-dismisses after 10 s.
20. **Rebuy in 2 taps:** row → `+ Rebuy`; `poker_players.rebuys` +1 and `POOL.pool_total` rises by `rebuy_amount`.
21. **Buy In / Undo buy-in** round-trips; `table_number`/`seat_number` are assigned on buy-in and cleared on un-buy.
22. **Re-entry:** KO a player, then `Re-enter` from the sheet; assert `reentered:true` in the response and that the page performed a **full** roster reload (spy on `checkin_dl.php` requests).
23. **`remove_player` returns `{pool}` only** — assert the row disappears from the DOM anyway (this is the merge rule most likely to be got wrong).
24. **Bottom sheet** opens on row-body tap and does **not** open when the primary button is tapped.
25. Add-on controls are **absent** on session 144 (`addon_allowed=0`), and **present** after enabling it.

*Bounty (with the temporary fixture)*
26. KO dialog contains `#gdElimBy` with one option per other live player and a `— not recorded —` default.
27. Selecting an eliminator sets `poker_players.eliminated_by` and writes a `bounty` row into `poker_session_log`.
28. Option text is escaped: rename a player to `X<img src=x onerror=alert(1)>` and assert no dialog fires and the literal text renders.

*Walk-in / pending*
29. Insert a pending `event_invites` row + `poker_players` row out-of-band → within one roster poll the amber strip appears with the name and a toast fires.
30. `Approve` moves them into the list and assigns a seat; `Deny` removes them.
31. Add walk-in by name → row appears with `rsvp='yes'` and a seat.

*Seats*
32. Seats view groups by table; counts match the DB.
33. `Move` via `move_player_table` changes `table_number`; an out-of-range table is refused by the server.
34. `Rebalance` shows the confirm, then lists the moves; table counts differ by ≤ 1 afterwards.

*Auto-finish*
35. On a scratch session, KO down to heads-up then KO once more: assert `{winner}` in the response, `poker_sessions.status='finished'`, the winner banner renders, and the undo snackbar is present. Then Undo → `reopened:true` and status back to `active`.

*Liveness*
36. `visibilitychange` → hidden pauses both pollers (count requests over 15 s); → visible fires both immediately.
37. Block `timer_dl.php` via `page.route(...abort())` for 15 s → amber stale banner appears with an age; unblock → it clears.
38. Wake lock: assert `navigator.wakeLock.request` was called (stub and count) and that the banner is removed on desktop.

*Console hygiene*
39. Zero `pageerror` events and zero `[pk-dispatch] no handler named` console warnings across the whole run. `checkin_real.js:8-9` has the exact listener pattern.

---

## 13. Sweeps to run

Per `SECURITY.md`, this change touches more than one PHP file, so all six run — plus the affected-suite rule.

```bash
# 1  PHP parse errors, every page
docker exec gamenight-dev sh -c 'find /var/www/html -name "*.php" | while read f; do php -l "$f" 2>&1 | grep -v "^No syntax errors"; done'

# 1a PHP-generated JS  — ADD '/gameday.php?event_id=' + EVENT to PAGES first (inline_js_sweep.js:23)
cd ~/qa-headless && PK_BASE=http://localhost:8080 PK_EVENT=237 node inline_js_sweep.js

# 1a′ the external file the sweep cannot see
node --check /home/bryce/Claude/GameNight/www/gameday.js

# 1b affected browser suites — checkin.php and event.php were edited
cd ~/qa-headless && node checkin_args.js && node checkin_dispatch.js && node checkin_real.js
cd ~/qa-headless && node gameday_check.js

# 1c double-dispatch — ADD '/gameday.php?event_id=237' to PAGES (double_dispatch_sweep.js:13)
docker exec gamenight-dev php -r '(new PDO("sqlite:/var/db/app.db"))->exec("UPDATE users SET role=\"admin\" WHERE id=269");'
cd ~/qa-headless && node double_dispatch_sweep.js
docker exec gamenight-dev php -r '(new PDO("sqlite:/var/db/app.db"))->exec("UPDATE users SET role=\"user\"  WHERE id=269");'

# 2  known-bad escaping
grep -rnE "addslashes\(htmlspecialchars\(|on[a-z]+=\"[^\"]*<\?=[[:space:]]*json_encode|value=\"'\+[a-zA-Z_]" www/ --include=*.php | grep -v "escAttr(\|escHtml("

# 3  declarative-markup conversion defects
grep -rnE 'data-[a-z0-9-]+="[^"]*""|data-[a-z0-9-]+="[^"]*&lt;\?=|data-[a-z0-9-]+="[^"]*" \+ |data-[a-z0-9-]+="<\?= *htmlspecialchars\(json_encode' www/ --include=*.php

# anchored inline-handler count — must stay 0
grep -rEh '[[:space:]]on[a-z]+="' www/*.php www/*.js | grep -vE '^\s*(//|\*)'
```

**Sweep-specific notes for this change:**

- **The double-dispatch sweep is the important one here.** Every roster control is built by JS after a fetch. The sweep navigates with `waitUntil: 'networkidle'` and waits 350 ms; confirm it reports a **non-trivial control count** for `/gameday.php`, not zero. A sweep that finds nothing reports a pass for work it never checked — that is the failure `SECURITY.md` documents at length. Add an explicit assertion.
- Sheet and control-row handlers must be defined **unconditionally** even when their controls do not render (`gdCmd`, `gdAddon`, `gdBountyToggle`, `gdJackpotToggle`, `gdMove`, …). The sweep flags an unresolved name as a dead control.
- `gameday.js` is external, so `inline_js_sweep.js` will not check it. `node --check` it directly, every time.
- Sweeps 2 and 3 should print nothing new — this page builds no HTML strings and puts no user text in attributes. If either prints a `gameday` line, the DOM-building rule was broken somewhere.
- Because `checkin.php` and `event.php` were edited, `checkin_args.js` (`calls: 2` is the double-dispatch signature) must be re-run even though the edit is one anchor.
- Run everything again against production after deploy, per `SECURITY.md`.

---

## 14. Open risks

| Risk | Mitigation |
|---|---|
| Dev clone holds uncommitted chip-bonus work in the four files this feature also touches | §0.9. Hand-apply, never bulk-mirror. `git -C /home/bryce/Claude/GameNight-dev status` before every mirror. |
| `checkin_dl.php` `get_session` array conflicts with `GameNight-Payout-RSVPBonus` | Append new keys after `'jackpots'`; keep the delta to its own commit so it can be dropped |
| Owner may not want *any* backend change | The zero-backend fallback (§4.3) ships a working page at 4.6 KB/s with a timer-row dependency for CSRF; the 3-line delta removes both costs. Present both, let the owner pick before step 13. |
| 12 MB/hour on cellular | Cadences are two constants at the top of `gameday.js`. If it bites, drop the clock poll to 5 s — the anchor makes it invisible. |
| No help bubbles (no `_footer.php`) | Acceptable for v1; a `?` in the header linking to `help-hosts.php` costs one line if wanted |
| `add_walkin` has no autocomplete on this page | Deliberate. The console keeps it. Revisit if hosts ask. |

---

### Critical files for implementation

- `/home/bryce/Claude/GameNight/www/checkin_dl.php` — every roster mutation (`get_session` :28-70, `toggle_buyin` :691, `update_rebuys` :828, `eliminate_player` :1008, `add_walkin` :1182, `rebalance_tables` :1689) and the shared POST+CSRF gate at :372-382
- `/home/bryce/Claude/GameNight/www/timer_dl.php` — `get_state` :136-315 (anchors, `pool`, `can_control`, `csrf_token` at :311) and `command` :350-436
- `/home/bryce/Claude/GameNight/www/timer_beta.js` — clock-sync engine to port: `noteClockSample`/`clockOffset`/`serverNow`/`liveRemaining` :393-428, `applyTimerSync` :1958-1991, `poll` :1993-2060
- `/home/bryce/Claude/GameNight/www/checkin.php` — patterns to port and the one line to edit: header link :1326, `eliminatePlayer` :4115-4143, `toggleBuyin` ticket prompt :3722-3756, `WALKIN_SEEN` :5133-5182, `escHtml` :5106
- `/home/bryce/Claude/GameNight/www/timer.js` — wake-lock block to port verbatim, :772-840
- `/home/bryce/Claude/GameNight/www/walkin_display.php` — the standalone-page shape to follow (no `_nav.php`, no `_footer.php`, explicit `pk-dialogs.js` + `pk-dispatch.js` at :252-253)