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
- **Cycling**: a screen may carry `cycle` (whole seconds, 2–3600). When the
  first *matching* screen has one, the display rotates through every matching
  screen that also has one, each shown for its own dwell. A no-cycle screen
  ordered first (Break) still wins outright, interrupting any rotation while
  its condition matches; the rotation resumes when it stops matching. One
  matching cycle screen alone never rotates. Rotation state lives in the
  renderer (`pickScreen()`), is reset on layout load, and the editor preview
  is exempt because it pins screens via `forceScreen`. Editor control:
  "Rotate after (seconds)" in the screen management area.
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

Element names match `[a-zA-Z][a-zA-Z0-9]*`. Plus any layout-defined custom
elements (see roadmap).

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

## Per-event pages (tournaments only)

`event_blinds.php?event_id=N` and `event_display.php?event_id=N` — standalone
pages gated by `can_manage_event()`, swapped by the shared strip in
`_event_setup_strip.php` (pk-seg house style; cross-page slide direction via
sessionStorage in `event_setup.js`). Linked from the event page's More menu
and the check-in toolbar (tournaments only).

- **Blind editor**: spreadsheet grid + generator + preset bar. `event_blinds.js` is
  a mountable component (`pkBlindsEditor.mount(container, opts)`): the
  standalone page auto-mounts it into `#esBlindsRoot`, and the check-in
  console mounts the SAME component as a "Blinds" tab pane inside the Game
  Setup editor (slides in like the other panes; unsaved grid edits survive
  settings re-renders via `pkBlindsEditor._state`). Data via
  `event_setup_dl.php`. Schedules are
  **copy-on-write**: `save_blinds` writes a preset row owned by the session
  (`blind_presets.session_id`), never a library preset. Loading a preset only
  fills the grid; "Save to library" publishes through timer_dl's
  `save_preset`. Session-local rows are excluded from `get_presets` /
  `load_preset` / `delete_preset`, and the old timer's `update_levels` now
  marks ITS copies session-local too (no more "Custom" library pollution).
  Saving clamps `current_level` and refreshes the clock only pre-start.

  The grid itself is always-live: every cell is an input, there is no
  click-to-edit mode, and typing patches only the derived cells (start times,
  the status card) so a full rebuild never steals focus mid-keystroke.
  Structure is edited through a right-click row menu (insert round/break
  above/below, duplicate, break toggle, move up/down/top/bottom, fill duration
  down, delete) which acts on the whole selection — click a row to select,
  ctrl-click to add, shift-click for a range. Rows reorder by dragging the ⠿
  grip or with Alt+↑/↓; Enter walks down a column and Ctrl+D copies the cell
  above. Touch gets the same menu on long-press (HTML5 drag is mouse-only, so
  the menu's move items are the touch reorder path).

  Cells size to their content, not to the column: a full-bleed `<input>` per
  cell both looked mostly empty and forced the table to a 660px floor, which
  is what pushed Start Time off a tablet's edge. Capping the inputs (6em, 4.5em
  for Duration) dropped the floor to 540px, which is why the rail can still sit
  beside the grid on a landscape tablet. Small Blind gets the widest numeric
  column because its HEADER, not its values, is the longest string in the row.

  Responsive behaviour keys off a **container query**, not the viewport:
  `mount()` stamps `.es-blinds-root` on its target (`container-type:
  inline-size`) and the rail stacks above the grid below 800 **container**
  px. Viewport queries are wrong here because the check-in Setup pane shares
  the screen with the payouts sidebar — an iPad in landscape is a 1180px
  window holding an 882px editor, and a `@media (max-width: 1000px)` rule
  never fired, so the rail stayed beside the grid and Start Time fell off the
  right edge into an inner scrollbar. Two more touch-specific rules matter: rows carry `user-select: none` (inputs opt back
  in), because iOS raises its Copy / Look Up callout on a long-press over
  selectable text and `-webkit-touch-callout` alone does not stop it; and
  `lpGuard` swallows dismissals for 800ms after a long-press menu opens, since
  iOS replays the finger as mousedown/mouseup/click on the row — outside the
  menu — which would close it the instant it appeared.

  Undo/redo (buttons at the top of the Controls rail, Ctrl+Z / Ctrl+Shift+Z /
  Ctrl+Y) keeps 60 whole-schedule JSON snapshots on `S.undo` — the grid is
  small enough that per-field deltas would buy nothing. Typing is coalesced
  per **cell visit**, not per keystroke: `focus` records `editBase`, and
  `flushCell()` banks it only if the value actually changed, so one undo steps
  back over "150" rather than three. Every structural op calls `pushUndo()`,
  which flushes any pending cell edit first — otherwise the typing and the
  operation collapse into a single step. Ctrl+Z is intercepted inside the grid
  inputs on purpose: the model updates on every keystroke, so the browser's
  native text undo would desync it. Outside the grid (the generator fields,
  anything else on the check-in console) the native behaviour is left alone,
  and the handler no-ops entirely when the editor isn't on screen.

  Three things the implementation depends on: the row `mousedown` selection
  handler must ignore `e.button !== 0`, or a right-click collapses a multi-row
  selection before its own menu opens; only the grip sets `tr.draggable` (a
  permanently draggable row makes text selection inside its inputs
  impossible); and the context menu is a single body-level element shared by
  all mounts, since the check-in console remounts the editor on every settings
  re-render and a per-mount menu would leak a detached `<div>` each time
  (`pkBlindsEditor._teardown` drops the previous mount's document listeners).
- **Use BETA timer switch** (its own Setup → Timer tab in the check-in
  console, sliding in like the other panes; tournament only): stores
  `timer_state.use_beta` via `set_beta`. When on, the check-in Timer button
  points at `timer_beta.php?event_id=N` (retargeted live by
  `toggleBetaTimer()`) and `timer.php?event_id=N` REDIRECTS to the BETA
  display — `?classic=1` is the escape hatch, and the BETA corner-bar "Timer"
  link carries it so the pages never bounce between each other. The tab also
  links to the per-event layout page and a display preview.
- **Timer Display**: the full layout editor (shared partial
  `_timer_beta_editor.php`, also used by timer_beta_edit.php). Binding lives
  IN the editor header: with event context (`ES_EVENT_ID`/`ES_LAYOUT_ID`/
  `ES_CSRF` globals), timer_beta_edit.js grows a "Use for this event" toggle
  on the loaded layout (stores `timer_state.layout_id` via `set_layout`;
  click again to unbind) and marks the bound layout "• this event" in the
  Load list. The editor is also embedded in check-in's Setup → Timer tab —
  rendered ONCE outside the panes (`#ckDisplayHome`, revealed by
  `syncDisplayHome()`) because pane re-renders rebuild innerHTML and moving
  an iframe reloads it.
  `timer_beta.php?event_id=N` first-paints the bound layout (server-injected
  `TB_EVENT_LAYOUT_ID`) and follows changes live — `get_state` returns
  `layout_id`, and the display refetches on change. Precedence: `?layout=`
  URL param > event binding > localStorage > classic; a manual pick at the
  display opts out of following until reload.

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
- **Custom elements (done):** layout-level `customElements: {name: value}` map,
  user-defined `<name>` = plain text (letters/digits names, values ≤500 chars,
  ≤30 per layout, no HTML). Resolved after the built-ins (a built-in name always
  wins), shown in the element picker, and travel with save/export. Edited from
  the Custom elements panel under Screen background.
- **Custom images (done):** background image per screen (`bg.image` + `bg.imageFit`
  cover/contain) and image cells (`cell.image` renders an `<img>`, `imageFit`
  contain/cover). Uploaded via a self-contained `upload_image` action in timer_beta_dl.php
  (byte-level MIME check + getimagesize decode, 8MB cap, shared per-user daily
  limit, CSRF) that writes to `/uploads/timer_layouts/` — its own folder,
  separate from every other upload, so BETA stays deletable and upload.php stays
  single-purpose. Refs restricted by both client and server to
  `/uploads/timer_layouts/[A-Za-z0-9._-]` paths —
  external URLs, data URIs, `javascript:` and traversal all rejected. Images
  render as real `<img>`/`background-image` (never innerHTML); CSP `img-src
  'self'` already covers them.
- **Cross-install image embedding (done):** export fetches each referenced
  `/uploads/timer_layouts/` file and embeds it as a base64 data URI in an
  `images` map beside the layout (`{gnTimerLayout:1, name, layout, images}`),
  so the file carries its own images to another install. Import decodes each
  entry (client-side shape check: `data:image/png|jpeg|gif|webp;base64` only,
  ≤8MB, ≤20 images) and re-uploads the bytes through the same `upload_image`
  action — byte-level MIME check, getimagesize, size and daily caps all
  re-applied server-side — then rewrites the layout's refs to the fresh local
  URLs. A data URI never lands in the layout document itself, so
  `pk_layout_sanitize`'s local-path-only rule is unchanged and remains the
  trust boundary. An embedded image that fails to upload has its ref dropped
  (never a broken ref); a ref with no embedded bytes (older export) is left
  alone for same-install round-trips. Import file cap raised 4MB → 64MB to
  make room for embedded images.
- **Screen cycling (done):** per-screen `cycle` dwell rotates matching screens
  (see Screens, conditions, variants above). Sanitizer clamps to whole
  seconds in [2, 3600] so a hostile value can never strobe the display.
- **Shared named styles (done):** layout-level `styles: {name: {visual
  props}}` map; a cell opts in with `style:"name"` and inherits
  size/fit/color/bg/bold/pad/align/opacity/spacing, with the cell's own props
  winning. Text, `when`, variants and images never come from a shared style,
  and variants still merge over the (shared-resolved) base. Names are
  identifier-safe ≤32 chars, ≤20 per layout; `pk_lo_styles()` reuses the cell
  prop validators so a style can't carry anything a cell couldn't, and
  `pk_lo_prune_style_refs()` drops refs to undefined names on save. Renderer:
  `withSharedStyle()` merges at build time in `buildNode()`; an unknown ref
  is a harmless no-op client-side. Editor: "Shared styles" panel on the
  Screen inspector (above Custom elements), "Shared style" dropdown on each
  cell. Styles ride with save/export/import automatically.
- **Remaining:** feature-complete — the promotion / fold-into-main-timer
  decision is what's left.

## Testing

`~/qa-headless/beta_check.js` (display), `beta_editor_check.js` (editor),
`beta_variants_check.js` (variants), `beta_screens_check.js` (break screens),
`beta_elements_check.js` (elements + picker), `beta_export_check.js`
(export/import round-trip), `beta_images_check.js` (image upload + refs),
`beta_imgport_check.js` (cross-install image embedding), `beta_cycling_check.js`
(screen cycling), `beta_sharedstyles_check.js` (shared styles),
`beta_eventpages_check.js` (per-event blinds + display pages),
`beta_blindgrid_check.js` (grid cells, row menu, drag/keyboard reorder, undo),
`beta_blindgrid_ipad_check.js` (tablet fit + long-press), `beta_shot.js`
(screenshots). Run against dev; the dev test login is JamesTest.

The break state in sample/preview mode derives from the LEVEL, not a flag:
`setState({level:7})` (the sample schedule's break level) is how a script or
the editor's chip enters break; setting `isBreak` directly gets overwritten
by `refreshDerived()`.

QA-script gotcha: Playwright's `setInputFiles` with the SAME file path twice
does not re-fire the input's `change` event — use a distinct path per import.
(The real UI is unaffected: the Import button clears `input.value` first.)
