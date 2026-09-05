# Timer BETA — layout engine

A rebuild of the tournament clock as a configurable layout engine, kept fully
separate from the existing timer. Delete `timer_beta.{php,css,js}`,
`timer_beta_edit.{php,css,js}` and `timer_beta_dl.php` and the feature is gone.

## Model

A **layout** is a JSON tree. `timer_beta.js`'s header has the authoritative
field list.

- node := `{ row: [...] }` | `{ col: [...] }` | `{ cell: {...} }`
- layout: `aspect` (optional) locks the shape the layout was authored at
- boxes (cells AND containers): `bgImage` + `bgImageFit` (stretch | cover |
  contain, default stretch) — the box's OWN background image. Plate art that
  moves and resizes with the box, so a value can never misalign with the
  artwork behind it. One full-screen picture with plates painted in forces the
  layout to land cells on pixels it cannot see; per-box art removes the
  registration problem instead of tuning it (this is also how Tournament
  Director models it — per-cell BackgroundImage). Same `/uploads/` +
  `/img/timer_beta/` source rules as every other image; exports embed them;
  never write the `background` SHORTHAND anywhere near these (it resets
  background-image — applyEmphasis uses backgroundColor for exactly that
  reason).
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

### Condition expressions

A `when` may be a string expression anywhere a condition goes (screen, cell,
variant): `bigBlind > 10000`, `playersLeft <= 9 and not onBreak`,
`(round >= 6 or entries > 20) and running`. Grammar (recursive descent in
`timer_beta.js`, mirrored as a pure validator in `timer_beta_dl.php` — parsed,
never eval'd):

```
expr    := or
or      := and (("or"|"||") and)*
and     := not (("and"|"&&") not)*
not     := ("not"|"!") not | cmp
cmp     := operand (("<"|"<="|">"|">="|"="|"=="|"!="|"<>") operand)?
operand := number | identifier | "(" expr ")"
```

**The registry is the foundation.** `COND_VALUES` maps lowercase names to
functions returning the RAW number from `S` (never a formatted string — a
`"10,000"` compares as text). Adding a comparable = one line there + the name
in `pk_lo_cond_expr()`'s whitelist + `COND_NAMES` for the hint line. Grammar,
editor validation and the help page all read the registry.

Numbers: round/level, smallBlind, bigBlind, ante, playersLeft, playersTotal,
entries, buyIns, rebuys, addOns, eliminated, chipCount, avgStack, prizePool
(dollars), tables, seats, minutesLeft. Booleans (riding the WHEN predicates):
running, paused, onBreak, preGame, gameOver, hasAnte, hasRebuys.

Device class: mobile, tablet, desktop (`pc` resolves as a spoken alias but
stays out of the hint line). Per DEVICE, not per game — with casting, every
scanner runs the same layout on a different class of screen, so a layout can
hide its chip legend on phones or draw the QR cell only on the TV. The signal
is the primary pointer (`pointer: coarse`), NOT touch — a touch laptop has a
fine primary pointer and calls itself a PC, which is what its user would say.
Phone/tablet split at a 600px smaller-viewport-side, which rotation cannot
change.

Rules that keep it honest:

- **Identifiers are case-insensitive** — same decision as element lookup.
- **A parse error or unknown name makes the condition FALSE, never silently
  true.** The old clause matcher ignored clauses it did not know, which is how
  a mistyped clause "worked" by matching everything. A failed compile is
  cached as failed; the string will not get righter.
- **State DERIVES from the schedule.** `refreshDerived()` recomputes sb/bb/
  ante/isBreak from `S.levels` at `S.level` — tests (and TBPreview drivers)
  must set `levels`, not sb/bb directly, or the assertion runs against a state
  the display can never be in.
- The editor validates through `TBPreview.validateCondition()` — the
  renderer's own parser — so the tick the author sees and the truth the
  display computes can never disagree. `TBPreview.conditionNames()` feeds the
  hint line.
- The PHP validator caps length (200) and paren depth (10), whitelists every
  token, and stores the string verbatim only when it parses; a bad expression
  is stripped at save where the author can still see it, not stored to fail
  silently later.

### States, clauses, screens (the original layer)

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

## Elements (~47)

**Game:** eventName gameName nextGameName level levelOrBreak clock elapsedTime
currentTime startTime nextBreak roundsToBreak roundsTotal

**Blinds:** smallBlind bigBlind ante blinds nextBlinds nextSmallBlind
nextBigBlind nextAnte

**Players:** players playersLeft playersTotal entries buyIns rebuys addOns
eliminated cashedOut

**Chips:** chipCount avgStack avgStackBB startChips addOnChips

**Money:** pot prizePool bountyPool jackpotPool buyInFee rebuyFee addOnFee
buyinLine prizes prizeList prizesStacked

**Room:** tables seats

Element names match `[a-zA-Z][a-zA-Z0-9]*`. Plus any layout-defined custom
elements (see roadmap).

**Per-element styling** (`cell.elStyles`): a map of element name to
`{color, bold, scale}`, so one element inside a cell's line renders apart from
the rest — the canonical case is `<blinds.ante>` bold and orange inside the
blinds line. Every element is already its own span; the style lands at
span-build, so a variant swapping the text keeps it. Keys match the element
the author MEANT: case-insensitive, like element lookup itself.
`scale` is an em multiplier, deliberately relative — capCell() shrinks an
overflowing line by font-size, and a styled element must shrink WITH its line.
Editor: select a cell, "Element styles" in the inspector, offered only for
elements present in the cell's text. Sanitizer caps at 10 entries per cell,
identifier keys, style-string colours.

**Lookup is case-insensitive** — a name that renders as ⟨blinds.small⟩ over
nothing but capitalisation is a bad afternoon. `rebuildElementIndex()` in
`timer_beta.js`.

The setup figures (fees, chips per buy-in, table plan, start time) ride in
`get_state`'s `game` block, named explicitly rather than handing the whole
session row to every screen — a display is public to anyone with the key.

`avgStackBB` is average stack in big blinds ("38 BB"); `levelOrBreak` is a
single "Level 5"/"Break" label. The editor's element picker shows each
element's LIVE value and sources its list from the renderer
(`TBPreview.elementNames` / `elementValues`), so the two can't drift.

Known data gaps: `buyinLine` is sample-only (get_state doesn't return buy-in
config); `gameName` is a fixed string (no per-level game field exists);
`elapsedTime` is blind-schedule time, not wall time (no session start
timestamp in get_state).

## Built-in layouts

classic, black_green, minimalist, two_column — pure CSS — and **pcf**, cut the
per-box way: the felt is the only full-screen image, and every plate is its own
transparent PNG carried by its box (`bgImage`), so plates move, resize and
reflow WITH their boxes. No aspect lock, no registration, no geometry constants
to keep in step — on a 21:9 screen the layout fills the width instead of
letterboxing, and the plates simply stretch with their boxes. Tabs ("Blinds",
"On Break") and the "Chips:" label are baked into their plates' pixels: flex
boxes cannot overlap, but a plate's own art can hold its tab. Art ships in
`www/img/timer_beta/` (why `safeImageSrc()`/`pk_lo_img()` allow that prefix); a
new built-in's key must also be in BOTH binding whitelists (`event_setup_dl`'s
`set_layout` and the preset-load path in `checkin_dl`). Regenerated by the pcf2
art builder in the session scratchpad; the paused-red clock variant rides the
clock cell. An empty cell with a `bgImage` stays VISIBLE by engine rule — the
plate is the content (the PCF logo cell is exactly that; never use an
opacity-0 spacer for a plated box, opacity hides the plate too).

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

## Text must not escape its box

**A declared cell `size` is a maximum, not a promise.** Blinds double every
round: a cell sized for "75 / 150" holds "2,000,000 / 4,000,000" by round 19,
and on a layout drawn around artwork the overflow walks straight across the
painted buttons. The engine enforces containment centrally: text wraps inside
its box where wrapping fits, and `capCell()` shrinks the INNER when even the
wrapped text cannot fit — then undoes itself when the text shortens (the
declared size stays on the cell element; the inner resets to inherit before
each measure, which is what makes growth possible). Re-measured only when the
rendered text changes SHAPE (same digit-normalisation as the fit cells, so a
ticking clock never re-measures) and on resize via `fitAll()`. No layout has to
opt in, which is the point — the author sized the cell against the values they
could see, and round 19 was never one of them.

## Numbers must not wobble

Every numeric readout is `font-variant-numeric: tabular-nums` (`.tb-cell-inner`
in `timer_beta.css`). Several system fonts — SF Pro on macOS and iOS most
notably — default to PROPORTIONAL figures, so a clock ticking 03:54 → 03:53
physically changes width. That shuffles the text horizontally, and on a fit
cell whose limiting dimension is WIDTH rather than height it also makes
`fitCell()` compute a different font size every second: the clock visibly
pumps. It reproduces on Safari and not in headless Chromium, whose default
font already has equal-width digits, so a passing local suite proves nothing
here.

`fitCell()` is additionally digit-blind about WHEN to re-fit: the cache key is
the rendered text with every digit normalised to `0`, so a tick never
re-measures while a real shape change (9:59 → 10:00, a break label appearing)
still does. Belt and braces — the normalisation holds up even in a font with
no tabular figures, and the existing 0.92 safety factor absorbs the couple of
percent that digit widths can then vary by.

`timer.css` had already pinned tnum on its clock. The BETA renderer was the one
that missed it.

## Right-click menus in the editor

Both surfaces that show the layout tree — the **preview** and the **structure
tree** — carry the same node menu: add cell / row / column, duplicate, move up
/ down, delete.

Two properties are load-bearing:

- **It acts on the node you POINTED AT, not the selection.** The handler
  `select()`s first and then runs the ordinary operation, so the toolbar
  buttons and the menu can never drift into two implementations of "duplicate".
  `dupNode()` / `removeNode()` / `move()` and the `newCellNode()` family exist
  for exactly that reason.
- **The labels say where the node will land.** `insertNode()` puts a node
  INSIDE a container but AFTER a cell, so the menu reads "Add cell inside" on a
  row/column and "Add cell after" on a cell rather than making you find out.

Moves that would fall off the end are disabled with the reason, and edits go
through `pushUndo()` like any other, so Ctrl+Z takes them back. The menu also
carries its own **Undo last change**, disabled when there is no history.

Beyond structure it exposes the node's whole property set, so most editing
never needs the inspector:

| Text cell | Image / QR cell | Container | Screen (root) |
|---|---|---|---|
| Bold, Fit text to box, Clock colours | Image fit, Replace image… | Justify | Screen background |
| Align, Text size, Colour, Letter spacing | Remove image / Remove QR | Gap, Padding | (colours + image + fit) |
| Use an image instead… / Use a QR code instead | | Background, Border | |
| Shared style (when the layout defines any) | | | |
| Background, Padding, Opacity, Border | Background, Padding, Opacity, Border | | |
| Size in parent | Size in parent | Size in parent | |

A cell is text, an image or a QR, and **the menu offers only what applies to the
mode it is in** — the inspector hides the text fields for an image, and a menu
that offered them would be worse, since a menu implies the setting does
something. Each mode has a way back to text. Box properties (background,
padding, opacity, border) apply in every mode and are always offered.

The **screen's** background belongs to the screen rather than to any node, so it
is offered where you would look for it: on the screen's own right-click menu.

**The vocabulary mirrors `pk_layout_sanitize()` exactly** — the same enums for
`align` and `justify`, the same numeric ranges. A menu that writes a value the
server silently strips is worse than no menu.

Two details: a tick IS the current value, so the menu doubles as a readout of
what the node already has; and submenus flip to the left of the parent row when
there is no room on the right, which is the common case since the menu often
opens near the right edge of the preview.

Hold menu rows **by reference, never by index**. The disable-the-impossible-move
logic originally used `btns[4]`/`btns[5]`, which was correct for a seven-row
menu and silently wrong once it grew to thirty.

The preview is an **iframe**, which is the only fiddly part. The `contextmenu`
listener lives on `frame.contentDocument` (same-origin, which `timer_beta.php`
opts into for embed mode) and is wired in `boot()` after the renderer exists;
the menu itself renders in the PARENT so the iframe cannot clip it, which means
the coordinates have to be translated by the frame's bounding rect. Dismissal
handlers are document-level in the parent for the same reason.

Note `closest('[data-path]')` returns the INNERMOST node under the pointer,
which is what you want: the root's box covers the whole screen, so a click
anywhere would otherwise select the root.

## Aspect lock

**A layout drawn around background artwork has to keep the shape it was drawn at.** Its artwork is
drawn for those proportions: buttons, panels and a logo in particular places.
Let the screen fill and the picture is cropped to cover while the text spreads
over the whole viewport, so the two drift apart the moment the ratio differs.
On a 21:9 monitor a 16:10 design loses a third of its height and nothing sits
in the button drawn for it.

`layout.aspect` makes `#tbRoot` a stage of exactly that ratio, centred, with the
rest of the screen black. The stage is a full `100vh` tall and as wide as the
ratio asks, so every `vh` inside still means what the author meant; when that is
wider than the window the whole stage is scaled down by a transform rather than
re-measured, so text, artwork and spacing shrink together and stay in register.
`Screen shape` in the screen's right-click menu sets or clears it.

`imageFit: 'stretch'` is the other way out: it distorts the picture, but the
picture then covers exactly the area the layout does, so anything drawn into it
stays put on a screen the design was never made for.

**Panel colours.** A layout can carry background artwork that already draws its
panels while its cells also set a background colour of their own. Painting them
buries the art; dropping them loses panels a layout with no artwork needs. The
screen's right-click menu → **Panel colours** switches the whole layout between
the two, and remembers the colours for the session so it flips back.

## Direct manipulation in the preview

Drag a boundary to resize; drag a box to move it. Both run inside the iframe
while the layout data and the undo stack stay in the editor — the renderer is
the shipping display and must not learn about editing.

**Where the handles live.** An overlay is injected into the iframe's `<body>`,
deliberately NOT into `#tbRoot`: `buildScreen()` empties `#tbRoot` on every
re-render and would take the handles with it. Handles are re-measured after
every `refresh()` (inside `requestAnimationFrame`, or the rects are the previous
layout's) and on window resize, since the preview is sized by the editor's own
layout.

**Resize** adjusts only the two neighbours either side of the boundary and holds
their combined weight constant, so dragging one boundary cannot disturb the rest
of the container. A neighbour with no `weight` is content-sized and has no ratio
to adjust yet, so both are first given weights matching what is already on
screen — the first pixel of the drag then moves the boundary and nothing else.
Feedback is applied straight to the DOM's `flex-grow` during the drag and
committed to `LAYOUT` on release; re-rendering the whole tree per `pointermove`
would be slower and jumpier. `pushUndo()` fires once, at drag start.

**Move** starts only after the pointer travels `DRAG_SLOP` (5px), so a press
that does not move is still a click and selection keeps working. The drop
target is the deepest container under the pointer and the index comes from
comparing the pointer against each child's midpoint **along that container's
axis** — a row inserts left/right, a column above/below. Three things it has to
get right:

- Resize handles are hidden for the duration, or `elementFromPoint()` returns a
  handle instead of the layout.
- A container may not be dropped into itself or a descendant (`contPath ===
  from || contPath.startsWith(from + '.')`), which would detach the subtree.
- When a node moves within the same list from earlier to later, the target index
  is decremented after the removal.

`userSelect` is disabled on the iframe body during a move and the selection
cleared afterwards; dragging across text otherwise highlights it and leaves the
highlight behind.

**Keyboard shortcuts must be bound to BOTH documents.** Clicking anywhere in
the preview makes the parent's `document.activeElement` the `<iframe>`, so every
subsequent keystroke is delivered to the iframe's document. `Ctrl+Z` was bound
only to the parent, which meant it worked until you touched the preview and was
dead from then on — after every edit it was most needed for. `editorKeydown` is
now attached to the parent document and, in `wirePreviewKeys()`, to the iframe's.
A headless test will NOT catch this by itself: synthetic drags on a resize
handle call `preventDefault()`, which suppresses the focus change, so the
regression test has to click the preview normally first.

## Chip legend

`{ cell: { chips: true } }` renders the game's chip denominations as coloured
discs with their values — the thing everyone asks at colour-up.

**The layout says WHERE, the game says WHAT.** `chips` is a flag, not content:
denominations live on `poker_sessions.chip_set` (JSON `[{"v":25,"c":"#ffffff"},…]`),
are edited in check-in Setup → **Timer**, beside the layout chooser rather
than on the Game tab (it is a thing the display shows, so that is where it gets
looked for), ride along with a game preset
(`payout_structures.chip_set`), and reach the display through `get_state`'s
`chips`. A layout file can never carry the values, for the same reason the QR
target is an enum.

A chip may also carry `img`, a photo of the real thing, so the legend matches
what is on the table. The colour is kept alongside it rather than replaced: it is
what the legend falls back to if the file is ever deleted, so the display
degrades to what it always looked like instead of a row of empty rings. Images
go through the timer-layout upload folder and its daily cap (same kind of file,
one sweepable place, one validated path prefix), and BOTH ends check the path:
`pk_clean_chip_set()` drops anything outside `/uploads/timer_layouts/`, and
`safeImageSrc()` refuses to draw it. An external URL, a data URI or anything with
a scheme is never stored and never rendered.

`pk_clean_chip_set()` validates: at most 12 chips, values to 2dp (money as well
as chips), colours as `#rrggbb`, and **sorted ascending** — a legend that jumps
around is harder to read than none, and every physical chip tray is ordered that
way.

Three things worth remembering:

- **Draw on the update path, not just on build.** A host can change the set
  mid-game; keyed on the set's contents, `drawChipCells()` returns immediately
  when nothing moved.
- **Only one place hides empty cells.** A chips cell draws nodes rather than
  text, so `updateAll()`'s auto-hide had to learn about it (`!inner.firstChild`)
  — the text test calls it empty always and the `isImage` exemption calls it
  full always. Setting `display` in the draw function as well meant whichever
  ran last won, and the cell never hid.
- **Discs can be sized apart from the text.** `chipSize` (vh) overrides the
  default `1.15em`, for a legend whose numbers should stay at body size while
  the discs fill the band they sit in.

## The image library

Every image pick in the editor (screen backgrounds, box images, image cells)
funnels through one `uploadImage()` chokepoint, which now opens a LIBRARY
first: the user's own uploads (filenames carry the uploader's id, so the
`list_images` endpoint lists only `u<uid>_*`), then the shipped `/img/
timer_beta` art — PCF's felt and plates are deliberately reusable. Upload is
the explicit last resort; re-uploading identical artwork for every layout was
burning the daily upload cap. Nobody is shown another user's uploads: a
guessed URL would serve (static files), but the picker does not browse other
people's libraries for them.

## Namespaced names

The vocabulary is dotted and grouped so related names sort together:
`blinds.small`, `blinds.big`, `blinds.next`, `players.left`,
`players.lastOut`, `money.pot`, `clock.seconds`, `table.count`… One map each
side — `ELEMENT_NS` (elements) and the ns block after `COND_VALUES`
(conditions) in `timer_beta.js`, mirrored in `pk_lo_cond_expr`'s whitelist —
defines dotted → registry key; the flat registry keys are internal
implementation names, and everything user-facing presents only the dotted
set. `<clock>` stays
undotted. States, events and device classes stay flat (`running`,
`levelChange`, `playerEliminated`, `mobile`). Element tokens, the expression
lexer (JS + PHP) and elStyles keys all accept dots.

## Triggers — sounds, takeovers, flashes, announcements

`layout.triggers` (up to 20) fire when their condition BECOMES true — edge,
not state. Each display arms a trigger only after its first evaluation, so a
screen that joins late never fires for a condition that has been true for an
hour; it fires on false→true, re-arms on true→false, and honours `cooldown`
(min seconds between fires, clamped 0..3600) and `once` (never again until
the layout reloads).

```json
{ "when": "levelChange",
  "do": [ { "sound": "preset:chime" },
          { "takeover": "Alert", "seconds": 8 },
          { "flash": "screen" },
          { "announce": "Blinds up: <blinds>" } ],
  "cooldown": 30, "once": true }
```

- **`when`** is the same condition language as everywhere else. Three values
  exist mostly for triggers: `secondsLeft` (`secondsLeft <= 60 and running`
  is the one-minute warning, re-arming naturally at each level),
  `levelChange` / `levelup` (true for exactly one update tick when the level
  number moves, either direction — snapshotted once per tick in
  `condTickUpdate()`, never computed on read, because variants evaluate a
  condition many times per paint), and `playerEliminated` / `playerOut`
  (one-tick edge when the eliminated COUNT goes UP — an elimination undo
  moves it down and stays silent). Pair the latter with the
  `<lastEliminated>` / `<lastEliminatedPlace>` elements (most recent
  knockout's name and ordinal place, from `last_eliminated` in `get_state`:
  lowest `finish_position` among the eliminated): announce
  "`<lastEliminated> has been eliminated`" speaks the actual name.
- **`sound`** is `preset:<key>` (Web Audio synth from `TB_PRESETS` — buzzer,
  chime, casino, horn, countdown, double, descending, five3s, tick, pulse,
  chirp, gentle; zero files, travel everywhere) or an uploaded file
  `/uploads/timer_sounds/u<uid>_<hash>.<ext>` vetted by `safeSoundSrc()`.
- **`takeover`** forces a named screen for 1..120 s then releases back to
  `pickScreen()`. One timeout, latest wins — takeovers never stack. The
  sanitizer verifies the name against the layout's actual screens at save.
- **`flash`** pulses `#tbRoot` with the `.tb-flash` amber inset (clears after
  ~1.8 s by timer, because reduced-motion never fires `animationend`).
- **`announce`** speaks via `speechSynthesis`, with `<elements>` resolved at
  fire time; silently skipped where unsupported.

**Audio policy:** host/event displays sound by default; a `?key=` scanned
display is someone's phone and starts MUTED. The speaker button (corner bar,
same idle-fade as fullscreen) toggles per display, persisted in
`localStorage.tb_sound_on`. TTS obeys the same toggle.

**Engine invariants** (`evalTriggers()` in `timer_beta.js`): re-entrancy
guarded — a takeover rebuilds the screen, whose `updateAll()` would land back
in the loop before `wasTrue` is written and fire the same trigger forever;
a throwing action must not strand the guard flag. `resetTriggers()` runs on
every layout swap and cancels any pending takeover.

**Sounds are first-class media** like images: `upload_sound` (finfo-sniffed
mp3/m4a/wav/ogg/webm/aac, 5 MB, `u<uid>_` provenance names, own
`uploads/timer_sounds/` dir, shared daily cap), `list_sounds` library picker
with ▶ preview, export embeds audio data URIs, import re-uploads and remaps
(a sound whose bytes fail to import falls back to `preset:chime` rather than
silently losing the action), and lifecycle GC sweeps unreferenced files at
layout update/delete (`pk_lo_sound_names` / `pk_lo_gc_sounds`) — classic's
`alarm_*` files leak forever; these don't. Do NOT copy classic's
`update_sounds` (stores raw, unvalidated) or its flat upload path.

The PCF builtin ships three: `levelChange` → chime; `secondsLeft <= 60 and
running` → tick + flash; final table → casino + flash (once).

## Seat map — the final table

`{ cell: { seats: true, table?: N } }` draws every still-in player at their
assigned seat around an ellipse: avatar (photo when they have one, initials on
the same hue the rest of the site computes — avatarHue matches avatar_hue() in
db.php byte for byte), name, seat badge; empty seats stay as dim rings so the
table reads short-handed. Pair with `when: 'playersLeft <= 10 and playersLeft
> 1'` and the display flips to it BY ITSELF when the field shrinks (the lower
bound keeps pre-game's 0 from matching). PCF ships the screen.

The data contract: `get_state` sends `players` — still-in only (`removed=0 AND
bought_in=1 AND eliminated=0`, calc_pool's exact predicate), name + table +
seat + avatar path, capped at 30. **This deliberately widens the `?key`
channel**: a wall display exists to show who is at which seat, so the key now
buys still-in names and avatars — and nothing else. Avatar paths are checked
at BOTH ends against `/uploads/avatars/` only.

Rules that came from the data model: `table` pins a table, default is the
busiest (at an actual final table, THE table). Seat numbers may exceed
seats_per_table (pick_random_seat over-sits a full table) — the ellipse
stretches. Two players on one seat (no DB constraint) both render, nudged —
the display must never lose a player. No per-player chip stacks: there is no
such column anywhere; stacks are a future feature needing schema plus a host
entry UI.

## QR cell — a second screen

**The editor preview draws a real, scannable sample code.** Sample and embed
modes have no session, so there is no honest timer link to encode — but the
dashed stub that used to stand in read as a broken cell (and on a multi-screen
layout the editor used to open on the Break screen, where the QR does not even
exist; every load path now opens on the catch-all via `mainScreenIndex()`).
The sample encodes `https://youtu.be/dQw4w9WgXcQ` — deliberately absurd, so a
screenshot of the editor can never pass for a live link, and anyone who scans
the preview has been rickrolled, which is its own documentation. The dashed
placeholder remains only for the one case with truly nothing to draw: the QR
library failing to load.

`{ cell: { qr: 'display' } }` renders a QR another screen scans to join this
display. Requires `/vendor/qrcode.min.js`, which `timer_beta.php` loads; CSP
already permits `data:` images.

**`qr` is an ENUM naming a target, never a URL.** A layout is a shareable
document, so an author-supplied payload would be a phishing primitive aimed at
a wall of screens. The sanitizer whitelists the target; the RENDERER builds the
URL from the session's own `remote_key`, so nothing typed into a layout ever
reaches a scanner.

The route is `timer_beta.php?key=<remote_key>`, mirroring what the classic
timer has always done with `timer.php?view=remote&key=` — the key authorises
**viewing**, and what the scanning device may DO is decided by who is logged in
on it, not by the QR:

- `get_state` computes `can_control` from the session, so a host scanning it
  gets the controls and a stranger gets a viewer;
- `resolve_timer_from_post()` re-checks `can_manage_event()` on every command,
  so possessing the key never confers control. `beta_qr_check.js` fires a
  `start` from a key-only device and asserts the 403.

Both screens derive from the same anchor, so they count in step with no
cross-screen messaging.

**Editor**: the cell inspector offers "Use a QR code instead" beside "Use an
image instead", and once converted shows what the code does plus "Remove QR
(back to text)". There is deliberately **no URL field** — the target is an enum
and the URL is the renderer's to build.

Three implementation notes worth keeping:

- A QR cell must set `isImage` on its record. The text pass writes
  `inner.textContent`, which deletes child nodes — the `<img>` vanished on the
  first tick, leaving a correctly-classed but empty cell. The same flag also
  exempts it from the empty-cell auto-hide.
- Sample and embed modes have no session and therefore no honest code; the cell
  draws a dashed `QR` placeholder so the editor can still see its footprint,
  rather than a stale or invented code.
- That placeholder must also `display: none` the `<img>` and clear its `alt`. A
  src-less image renders as a broken-image glyph plus its alt text, which filled
  the editor preview with a white block.

## Video cell — the classic timer's stream panel, as a cell

`{ cell: { video: '<pasted URL>' } }` renders a streaming embed filling the
box — the classic timer's "stream a video" option carried over. The layout
stores the **raw pasted URL**; the renderer builds the actual embed through
`normalizeStreamUrl()` (ported verbatim from `timer.js`, so both timers accept
the same links): YouTube in every spelling (watch / youtu.be / embed / live /
shorts / tv.), Twitch channels (`parent=` is filled from `location.hostname`,
so the same layout works on localhost and prod), Vimeo, Kick, a best-effort
Prime pass-through, plus the admin-allowlisted hosts from Settings → General
(injected as `TB_STREAM_HOSTS`).

Unlike the QR target (an enum), a video URL is author content in a shareable
document. Three fences keep that safe, and each would hold alone:

- `pk_lo_stream_url()` in the sanitizer drops any URL whose host isn't on the
  recognised list at save;
- the renderer's `normalizeStreamUrl()` returns `''` for unknown hosts at
  paint, and the iframe only ever loads its output — an unrecognised URL draws
  the dashed `▶ video` placeholder (same idea as `tb-qr-empty`), never an
  embed;
- the global CSP `frame-src` (auth.php) lists the same hosts, so the browser
  refuses anything that somehow slipped both.

Keep the three lists in step: `normalizeStreamUrl` (timer_beta.js),
`pk_lo_stream_url` (timer_beta_dl.php), and the CSP frame-src + admin extras
(auth.php / `stream_allowed_hosts()`).

Implementation notes, all inherited from the QR cell's lessons:

- A video cell sets `isImage` on its record — the text pass would otherwise
  wipe the iframe on the first tick, and the empty-cell auto-hide would hide
  it. `capCell()` skips it for the same reason.
- The iframe gets `pointer-events: none` in embed mode only, so a click in the
  editor preview selects the cell instead of vanishing into the player.
  On a live display the player keeps its controls.
- Trigger sounds duck the stream: `tbPlaySound()` calls
  `tbMuteStreamForAlarm(5000)`, which postMessages mute/unmute to YouTube and
  Vimeo embeds (the only hosts with a message API — Twitch/Kick/Prime keep
  playing, same as the classic timer). It honours the classic timer's
  `gn.muteStreamDuringAlarms` localStorage toggle, default on, so a device's
  choice there carries over here.

**Editor**: "Use a video stream instead" beside the other conversions sets
`video: ''` — the cell shows the placeholder until a link is pasted into the
inspector's Stream URL field, which validates through
`TBPreview.normalizeStreamUrl` (the renderer's own function, same contract as
`validateCondition`) and warns in red when a link won't survive Save. An empty
or unrecognised URL is dropped by the sanitizer on Save and the cell reverts
to text.

## What a scanned screen may read

A screen opened with `?key=` must render **the layout the host chose**, and it
had two ways to fall back to a built-in instead. `timer_beta_dl.php` refused an
anonymous caller outright, and `get_layout` only returns layouts the CALLER owns
(or a global, or one of their leagues') — so a guest who scanned it, signed in or
not, was never going to be handed the host's private layout.

`get_layout` therefore accepts a `key` **before** the auth gate. It ignores the
requested id and answers with the layout that key's timer is currently bound to,
carrying the layout alone: no owner, no scope, no `editable` flag. The key
already authorises viewing that timer, and this is what that timer is showing, so
it exposes nothing further. It cannot be pointed at another row.

Two smaller things fell out of the same bug:

- **The first paint must not depend on the picker.** A saved layout used to be
  applied inside the `get_layouts` callback, so the render only happened once the
  dropdown's list arrived. A scanned screen has no dropdown and gets a 401 for
  the list, so nothing ever applied. `applyPick(pick)` now runs at boot and the
  callback only reflects the choice in the control.
- **The corner bar is not rendered for a key screen at all.** A display cast to
  a TV should not offer a stranger a layout dropdown or a link to the classic
  timer, and everything behind it 401s for them anyway. Guard every use of the
  picker: it is legitimately absent, and populating a control that is not there
  threw on the first `appendChild` and took the whole display down with it.

## Fullscreen on a scanned screen

**The button belongs to the viewer, not the controller.** The control tray only
appears once `get_state` says this viewer can drive the game, and the screen most
likely to want fullscreen is the one someone just scanned the QR code onto, which
usually cannot. `#tbFsBtn` is rendered for every non-embed viewer and fades with
the tray, so it is there when it is needed and invisible the rest of the time.

**The iPhone has no element Fullscreen API** (Safari implements it for `<video>`
and nothing else), so `requestFullscreen()` is simply absent there. That is
feature-detected, never sniffed. **The iPad does have it**, and that turns out
to be the worse case: a fullscreen page on iPadOS gets Safari's own close ✕
painted in the top-left corner, which fades and returns on every touch and
which no page can remove. Either way the real answer is the same: **Share → Add
to Home Screen**, which needs `apple-mobile-web-app-capable` (plus the
status-bar style) on the page to launch chrome-free. The saved icon keeps the
URL it was added from, key and all, so a dedicated display device reopens that
same game straight into a full screen.

**The Add to Home Screen card (`pk-a2hs.js`, v0.2123) lives in `_footer.php`,
not here.** It offers the whole site as one app (the site manifest's start_url
is `/`) to logged-in users on touch devices, and My Settings has an "Install as
an App" button. The display pages deliberately do not carry it: the app is the
thing to install, not one game's screen, and a display someone scanned onto
should not be pitched anything. What they do carry is `apple-touch-icon`, so a
timer added to a home screen by hand gets the app icon rather than a screenshot
of the page.

`navigator.standalone` / `display-mode: standalone` removes the button
altogether: there is no browser chrome left to escape.

## Keeping the screen awake

A tournament clock is watched, not touched, so the phone or tablet showing it
dims and sleeps mid-level. `timer_beta.php` carries the same pair `timer.js`
has always used, and for the same reason:

- `navigator.wakeLock` — the real API, tried on load and again on first tap.
- **NoSleep.js** (`/vendor/nosleep.min.js`, a hidden silent video) — because
  iOS Safari has no Wake Lock API **over plain HTTP**, which is exactly how a
  second screen opens from the QR code on a LAN. Without it the QR feature
  works and then the screen goes dark.

Both need a genuine user gesture on iOS, so both are attempted inside the first
`click`/`touchend`; `#tbWakeBanner` says so and removes itself as soon as either
takes. It never appears on a desktop (no touch support = nothing to prompt) and
neither the banner nor the vendor script is emitted in embed mode — the editor
preview is an iframe, not a screen anyone watches.

`visibilitychange` re-requests the lock and calls `poll()` immediately: a hidden
tab has throttled intervals, and with the anchor the returning screen shows the
correct time at once rather than counting up from a stale value.

## Displays follow deploys

A timer display sits open for hours polling DATA, but it never re-fetches
CODE — so no fix ever reaches a screen that is already on the wall, and "still
broken" after a deploy usually means a stale tab, not a bad fix. `get_state`
carries `asset_v` (the `filemtime` of `timer_beta.js`); the page compares it to
the stamp it booted with (`TB_ASSET_V`) and reloads itself once when they
differ. Verified by touching the file under an open display: it reloads within
one poll and comes back on the fresh code. Screens opened before this feature
existed still need one last manual reload.

## Clock sync

`get_state` is polled every 2s and the display interpolates between polls
(`liveRemaining()` = last value minus wall-clock elapsed). Naively assigning
the polled value each time made the countdown **non-monotonic**: it stepped
backwards and forwards by a second or two, e.g. 1:18:30 → 1:18:27 → 1:18:29.
Two causes, both unavoidable at the source:

- `compute_live_state()` works in whole seconds (`time() - strtotime(...)`), so
  its answer is quantised depending on where in the second the request lands.
- The reply takes a variable few tens of milliseconds, and the client used to
  credit the value to its ARRIVAL, folding the whole latency into the reading.

### The anchor

The fix is to stop sending the value and send the **deadline**. `get_state`
returns `timer.ends_at_ms` (epoch ms) while running, `timer.remaining_ms` while
paused — exactly one is non-null — plus a top-level `server_now_ms`. The
display derives the countdown itself, so a poll returning the same anchor moves
nothing. The stutter is not smoothed, it is impossible.

`ends_at` is invariant between commands, which is the whole point:

    ends_at = now + remaining
            = now + (stored_remaining - (now - updated_at))
            = updated_at + stored_remaining

The request time cancels. **`compute_live_state()` must therefore read the
clock exactly once** (`$now = time()`) — it used to call `time()` twice, and
two screens polling either side of a second boundary latched deadlines a second
apart and then held them forever. That defect was invisible to every
single-screen test and was caught only by comparing three screens.

No schema change and no command path was touched: `updated_at +
time_remaining_seconds` already WAS an anchor, just a coarse one, so it is
derived at read time. Storing epoch ms would remove the residual sub-second
quantisation, but the quantisation is shared by every screen and so costs
nothing in agreement.

### Clock skew

Deriving from `ends_at` on the screen's own clock would show a wrong time
forever on any screen whose clock is off, and screens would disagree with each
other — precisely what a shared anchor is meant to prevent. So each screen
estimates its offset from `server_now_ms` (Cristian's algorithm):

    rtt    = received - requested
    offset = (server_now_ms + rtt/2) - received

taking the **median of the three fastest** round trips — the least-uncertain
samples, with a single unlucky asymmetric trip voted out. Use `Number()`, never
`|0`: epoch ms is ~1.79e12 and a bitwise OR truncates to 32 bits, which turned
the server's clock into near-zero and the countdown into a 496307-hour number.

Residual skew between screens is the spread in their offset ESTIMATES, tens of
milliseconds, so two screens show a different second only while their estimates
straddle a boundary. That is the physical floor without a shared tick source;
`beta_clocksync_check.js` asserts screens are never more than a second apart
and usually identical, and samples on a non-harmonic interval because sampling
at exactly 1000ms lands in the same phase every time and reports that sliver as
either always or never.

### Legacy path

`applyTimerSync()` still carries the pre-anchor behaviour for a server that
sends no anchor: resync only when the server disagrees by more than
`RESYNC_TOLERANCE` (2.5s) or something structural changed — level, start/stop,
pause, first poll — stamping the sample at the round-trip midpoint. Below the
tolerance the two cannot meaningfully disagree; above it the difference is a
real correction (someone adjusted the clock, or the tab was asleep) that must
be obeyed. Both halves are asserted.

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
  ctrl-click to add, shift-click for a range. There are deliberately no
  "+ Round" / "+ Break" buttons in the rail: the menu inserts relative to where
  you are, which a rail button cannot do. The one case the menu cannot serve is
  an empty grid — no row to right-click — so the table renders an empty state
  offering the first round, a break, or the generator. Delete every row without
  it and the editor is a dead end. Rows reorder by dragging the ⠿
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

  Long-press works over a **cell** as well as the row label, because a desktop
  right-click inside a cell already opens the row menu and the two platforms
  disagreeing is a bug, not a platform convention. The field is made
  unselectable only for the duration of the press and restored on
  touchend/move/cancel: a blanket `user-select: none` on an input risks taking
  the caret with it on older iOS. Cells also carry `inputmode="numeric"` and
  `pattern="[0-9]*"` — every value here is a whole number, and `type="number"`
  alone still yields a full keyboard on iPad — while keeping `type="number"`,
  which the desktop min/max clamping and arrow-key stepping are built on. On a
  coarse pointer, focusing a cell selects its contents so one tap replaces the
  value instead of dropping a caret mid-number.

  **Blinds are money, not just chips.** The classic timer has always stored
  them as floats (`timer_dl` casts `(float)`, `timer.js` reads `parseFloat`), so
  a .25/.50 home game works there; the grid's `(int)` casts were a regression
  against that and silently turned 0.25 into 0 on every copy-on-write save.
  Money cells now parse as floats rounded to 2dp, carry `step="any"` and
  `inputmode="decimal"` (a *numeric* keypad has no decimal point, making .25
  untypable on iOS), and the "big blind is still double" test uses a tolerance
  because float equality never holds by luck. Duration stays whole minutes.
  The generator's ladder extends two decades below 1 so a fractional structure
  can be generated, but rungs **at or above 1 stay whole numbers** — those are
  chips, and half a chip does not exist. That distinction is load-bearing:
  rounding the whole ladder to 2dp turned the 37.5 rung into 37.50 instead of
  38 and changed every generated tournament structure. `fmtChips()` in
  `timer_beta.js` also had to gain the fraction-digit branch the classic timer
  already had, or a .50 blind rendered as "0.5".

  Note the emulated-iPad suite runs in **Chromium, not Safari**: it can prove
  the gesture wiring and that the CSS ships, but `-webkit-touch-callout` is not
  even exposed to `getComputedStyle` there. iOS-specific behaviour needs a
  device to confirm.

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
  (`{gnTimerLayout:1, name, layout}`, `.gntimer.json`), with any referenced
  images embedded as data URIs and re-uploaded on the way in. Import
  JSON.parse's the file (never evals),
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
`beta_blindgrid_ipad_check.js` (tablet fit + long-press),
`beta_triggers_check.js` (edge semantics, cooldown/once, takeover, flash,
announce, audio policy, sanitizer round trip, sound upload), `beta_shot.js`
(screenshots). Run against dev; the dev test login is JamesTest.

The break state in sample/preview mode derives from the LEVEL, not a flag:
`setState({level:7})` (the sample schedule's break level) is how a script or
the editor's chip enters break; setting `isBreak` directly gets overwritten
by `refreshDerived()`.

QA-script gotcha: Playwright's `setInputFiles` with the SAME file path twice
does not re-fire the input's `change` event — use a distinct path per import.
(The real UI is unaffected: the Import button clears `input.value` first.)
