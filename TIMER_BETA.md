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
- **Remaining:** shared named styles.

## Testing

`~/qa-headless/beta_check.js` (display), `beta_editor_check.js` (editor),
`beta_variants_check.js` (variants), `beta_screens_check.js` (break screens),
`beta_elements_check.js` (elements + picker), `beta_export_check.js`
(export/import round-trip), `beta_images_check.js` (image upload + refs),
`beta_imgport_check.js` (cross-install image embedding), `beta_cycling_check.js`
(screen cycling), `beta_shot.js`
(screenshots). Run against dev; the dev test login is JamesTest.

The break state in sample/preview mode derives from the LEVEL, not a flag:
`setState({level:7})` (the sample schedule's break level) is how a script or
the editor's chip enters break; setting `isBreak` directly gets overwritten
by `refreshDerived()`.

QA-script gotcha: Playwright's `setInputFiles` with the SAME file path twice
does not re-fire the input's `change` event — use a distinct path per import.
(The real UI is unaffected: the Import button clears `input.value` first.)
