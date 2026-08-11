# Timer BETA — TD-style layout engine

A rebuild of the tournament clock modelled on The Tournament Director's layout
system, kept fully separate from the existing timer. Delete
`timer_beta.{php,css,js}` and the feature is gone.

Reference material: a copy of the TD 3.7 install at
`/home/bryce/smb/Files/temp/TD/` (NOT in this repo, and its assets must never
be). The definitive spec is `userguide.html` chapter 13 ("Layout Tab") in that
folder; the site blocks remote fetches, the local copy is identical. The
`.tlo` files in `templates/` are readable JS object literals of the built-in
layouts; `lib/tokens.xml` is the machine-readable token list (145 tokens).

## Model

A **layout** is a JSON tree. Phase A schema (`timer_beta.js` header has the
authoritative field list):

- node := `{ row: [...] }` | `{ col: [...] }` | `{ cell: {...} }`
- containers: `weight` (flex-grow; unweighted = content-sized), `gap`, `pad`,
  `bg`, `border`, `justify`
- cells: `text` with `<tokens>` and newlines, `size` (vh units) or `fit: true`,
  `color`, `bg`, `bold`, `pad`, `align`, `when`, `clockColors`

Rendering is nested flexbox, so overlap and off-screen are impossible by
construction. That property is the whole reason this engine exists; the
current timer's point-anchor themes can do both and needed a runtime clamp
(v0.2085 branch).

**Invariants, do not relax:**
- Every authored string lands via `textContent`; tokens become
  `<span data-tok>`. Nothing user-authored is ever innerHTML'd.
- Display-only until promotion is decided: state comes from
  `timer_dl.php?action=get_state` and nothing else. No POST, no CSRF token on
  the page.
- Unknown tokens render visibly as `⟨name⟩`, never vanish (editor relies on
  this to flag typos).
- Empty cells hide themselves so background bands don't paint bare.
- TD allows user tokens whose values are raw HTML. **We never will.** Plain
  text through the same textContent path only.

## TD concepts and how they map (userguide ch. 13)

| TD term | Meaning | Our status |
|---|---|---|
| Screen | tree of Rows/Columns of Cells | Phase A: one screen (`root`) |
| Cell + Property Set | a cell holds MULTIPLE property sets, each with Conditions; TD continuously evaluates state and shows the best match, first match wins | Phase A has the degenerate `when:` enum; Phase B schema: `props: [{style…, when…}]` list with single-style shorthand kept |
| Conditions | ANDed clauses; numeric ones accept < <= = >= > !=; Round accepts all/even/odd; "In Countdown" ordered before "Before Game" because first-match | Phase C |
| Screen Set | screens cycling each for N seconds; the SET is chosen by conditions (break screens, pre-game countdown) | Phase C |
| Toolbox / CellRef | cells are shared definitions; screens hold references | Phase B editor |
| Global Property Set | named shared styles (CSS-class-alike) | Phase B/C |
| Token attributes | `<chips size="30" columns="10" values="none">`, `<round offset="1">` | adopt when a token needs it |
| User token overrides | user-defined tokens, plain text or HTML | plain text ONLY |
| Banner Set | cycling images in a cell | maybe; uploads-scoped URLs only |
| Auto-size screen | batch font-shrink until it fits, re-run per conditions | not needed: our `fit` is live per-cell |
| Optimal Size + Scaling | layouts authored at a fixed resolution, scaled | not needed: vh-relative sizing |

## Tokens (Phase A registry, ~23)

eventName level clock gameName nextGameName smallBlind bigBlind ante blinds
nextBlinds players entries rebuys pot chipCount avgStack buyinLine currentTime
elapsedTime nextBreak prizes prizeList

Known gaps: `buyinLine` is sample-only (get_state doesn't return buy-in
config); `gameName` is a fixed string (no per-level game field exists);
`elapsedTime` is blind-schedule time, not wall time (no session start
timestamp in get_state).

## Phases

- **A (done):** renderer + four built-ins (td_classic, black_green,
  minimalist, two_column) authored from TD's screenshots/.tlo structure, no TD
  assets. Sample mode without an event; live mode polls get_state every 2s.
- **B:** editor page (full page, house style): live preview = the real
  renderer, click-to-select, tree panel + inspector, token picker with sample
  values, save/duplicate. Storage: new `timer_layouts` table mirroring
  timer_themes' scope columns (own/league/global), server-side
  `pk_layout_sanitize()` (whitelist node/style keys, clamp numbers, colour
  regex, depth/node caps), CRUD in a new `timer_beta_dl.php` so timer_dl.php
  stays untouched.
- **C:** property-set conditions, screen sets with cycling (break screen),
  global property sets, import/export.
- **D:** promotion decision (controls on the beta page, or fold the engine
  into timer.php as v2 themes). User's call after living with it.

## Testing

`~/qa-headless/beta_check.js` (13 assertions: ticking, all four layouts
zero-spill, persistence, live-mode data, 403/404, CSP) and `beta_shot.js`
(screenshots). Run against dev as usual; the dev test login is JamesTest.
