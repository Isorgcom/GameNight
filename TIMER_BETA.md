# Timer BETA — layout engine

A rebuild of the tournament clock as a configurable layout engine, kept fully
separate from the existing timer. Delete `timer_beta.{php,css,js}`,
`timer_beta_edit.{php,css,js}` and `timer_beta_dl.php` and the feature is gone.

## Model

A **layout** is a JSON tree. `timer_beta.js`'s header has the authoritative
field list.

- node := `{ row: [...] }` | `{ col: [...] }` | `{ cell: {...} }`
- containers: `weight` (flex-grow; unweighted = content-sized), `gap`, `pad`,
  `bg`, `border`, `justify`
- cells: `text` with `<elements>` and newlines, `size` (vh units) or
  `fit: true`, `color`, `bg`, `bold`, `pad`, `align`, `when`, `clockColors`,
  `variants`

Rendering is nested flexbox, so overlap and off-screen are impossible by
construction. That property is the whole reason this engine exists; the
current timer's point-anchor themes can do both and needed a runtime clamp
(shipped v0.2085).

**Invariants, do not relax:**
- Every authored string lands via `textContent`; elements become
  `<span data-el>`. Nothing user-authored is ever innerHTML'd.
- State comes from `timer_dl.php?action=get_state`. The only writes are the
  keyboard controls (Space start/stop, Left/Right skip level), which POST the
  same `command` action the main timer uses, and only when `controlArmed()` —
  a live event session, the viewer can control it, a CSRF token is in hand
  (get_state returns both `can_control` and `csrf_token`), and NOT the editor
  preview iframe. The server re-checks manage rights on every command. Sample
  mode and embed mode never send anything.
- Unknown elements render visibly as `⟨name⟩`, never vanish (editor relies on
  this to flag typos).
- Empty cells hide themselves so background bands don't paint bare.
- No HTML in element/user text. Plain text through the textContent path only.

## Screens, conditions, variants

- **Screens**: a layout is `{screens:[{name,when,bg,root}]}` (a single-screen
  `{bg,root}` is accepted and normalized). Screens are checked top to bottom;
  the first whose condition matches shows, and a screen with no `when` is the
  default. A break screen (`when:'on_break'`) ordered before a catch-all Main
  auto-swaps in on break, live.
- **Conditions** (`when` on a cell or screen, or on a variant): a keyword
  string, or an object of clauses that AND together — `state`
  (running|paused|on_break|pre_game|game_over), `hasAnte`, `hasRebuys`, `round`
  (`">3"`, `"even"`, `"all"`, …).
- **Variants**: a cell's `variants: [{when, text?, color?, bg?, bold?,
  opacity?}]` give it conditional emphasis; the first matching variant merges
  over the base. Scoped to emphasis so a variant can never reflow the layout.
  Re-evaluated every tick.

## Elements (~27)

eventName level levelOrBreak clock gameName nextGameName smallBlind bigBlind
ante blinds nextBlinds players playersLeft playersTotal entries rebuys pot
chipCount avgStack avgStackBB buyinLine currentTime elapsedTime nextBreak
prizes prizeList

`avgStackBB` is average stack in big blinds ("38 BB"); `levelOrBreak` is a
single "Level 5"/"Break" label. The editor's element picker shows each
element's LIVE value and sources its list from the renderer
(`TBPreview.elementNames` / `elementValues`), so the two can't drift.

Known data gaps: `buyinLine` is sample-only (get_state doesn't return buy-in
config); `gameName` is a fixed string (no per-level game field exists);
`elapsedTime` is blind-schedule time, not wall time (no session start
timestamp in get_state).

## Files

- `timer_beta.php` — display page. `?event_id=N` shows a live game (host
  rights, same gate as timer.php); no event = sample data. `?embed=1` is the
  editor's preview iframe (opts into same-origin framing via
  `csp_allow_same_origin_framing()` in auth.php — that one page only).
- `timer_beta.js` — the renderer + engine + four built-in layouts + embed API
  (`window.TBPreview`).
- `timer_beta_edit.php` / `.js` / `.css` — the layout editor. Live preview is
  the real display page in the iframe; structure tree, inspector, element
  picker, per-state preview chips, screen tabs, undo.
- `timer_beta_dl.php` — layout CRUD. `pk_layout_sanitize()` is the trust
  boundary: whitelists node types, style keys and enums; clamps numbers;
  rejects `url()`/`expression()`/js in style strings; depth cap 8, node cap
  200, 6 screens, 12 variants, 128KB doc. Cell TEXT is permissive because the
  renderer only assigns it via textContent.
- `timer_layouts` table — own/league/global scope like `timer_themes`.

## Nav / reachability

"Timer Layouts (BETA)" sits under "Tournament Timer" in the hamburger site
menu (signed-in users). Reached via `/timer_beta_edit.php`. When BETA
graduates to being *the* timer, promote it to the always-visible desktop nav
row too, not just the mobile hamburger.

## Roadmap

- **A (done):** renderer + four built-ins. Sample mode + live polling.
- **B (done):** editor — tree, inspector, element picker, save/duplicate/scope,
  server-side sanitizer.
- **C (done):** conditions — per-cell variants and multi-screen layouts with
  break-screen auto-swap.
- **Promotion (started):** keyboard controls (Space/Left/Right) AND an
  on-screen control tray (#tbControls: prev/play/next, -1m/+1m, reset-level,
  undo, fullscreen) drive a live event-linked display for users with rights.
  The tray is server-rendered only on an event-linked non-embed page and
  revealed by syncControls() once get_state confirms can_control; the play
  button reflects the running state. It is solid when active and auto-hides
  completely after 3s of no pointer/touch/key activity (video-player style),
  reappearing on any interaction. The fold-into-main-timer decision remains.
- **Export/import (done):** a layout exports as one self-contained JSON file
  (`{gnTimerLayout:1, name, layout}`, `.gntimer.json`) — our own portable
  format, no TD format involved. Import JSON.parse's the file (never evals),
  loads it into the editor, and persists via save_layout so the server
  sanitizer is the trust boundary; a bare `{screens|root}` object is also
  accepted. Hostile styles are stripped, non-layout files import nothing.
- **Remaining:** custom elements (user-defined named text values); custom
  images (background per screen / image cells, uploads-scoped, embedded on
  export); cycling multiple screens per condition; shared named styles.

## Testing

`~/qa-headless/beta_check.js` (display), `beta_editor_check.js` (editor),
`beta_variants_check.js` (variants), `beta_screens_check.js` (break screens),
`beta_elements_check.js` (elements + picker), `beta_shot.js` (screenshots). Run
against dev; the dev test login is JamesTest.
