# Security Notes

Working notes for this codebase: the checks to run before shipping, and the one
known hardening gap that has not been closed yet. Kept in the repo so neither
gets lost between sessions.

Last reviewed: 2026-08-09 (v0.2076).

---

## Pre-push sweeps

Run both before any push that touches more than one PHP file, and again against
the production container after a deploy. Each takes about two seconds. There is
no `php` binary on the build host, so they run inside a container.

### 1. Parse errors, every page

```bash
docker exec gamenight-dev sh -c 'find /var/www/html -name "*.php" | while read f; do php -l "$f" 2>&1 | grep -v "^No syntax errors"; done'
```

A PHP parse error takes down the **whole file**, not the branch containing it, so
the page returns an empty 500 on every request. This exists because v0.2064
rewrote the `style.css` cache-buster across all 54 pages that link one, and in
`timer.php` that link sat inside a PHP string where the short-echo tag is not
evaluated and its quotes closed the string. The timer was a white page for the
entire release. Any pattern-based edit across many files will eventually hit a
context where the pattern is wrong.

Related trap: never write the PHP closing tag inside a `//` comment. It ends PHP
mode and reintroduces the same class of fatal.

### 2. Known-bad escaping patterns

```bash
grep -rnE "addslashes\(htmlspecialchars\(|on[a-z]+=\"[^\"]*<\?=[[:space:]]*json_encode|value=\"'\+[a-zA-Z_]" www/ --include=*.php \
  | grep -v "escAttr(\|escHtml("
```

The trailing filter matters: the third pattern also matches correctly-escaped
code such as `value="'+escAttr(col)+'"`, and a check that reports clean code as a
problem quickly gets ignored. On a clean tree the whole command prints nothing.

Anything it prints is a real bug, not a style preference. In order, it flags:

| Pattern | Why it is wrong |
|---|---|
| `addslashes(htmlspecialchars($v))` | `htmlspecialchars` turns `'` into `&#039;` first, so `addslashes` finds no quote to escape and is a no-op. The browser decodes the entity back to a quote *before* compiling an inline handler, so the JS string still breaks. |
| `on…="… <?= json_encode($v) ?> …"` | `json_encode()` emits its own `"` delimiters, which terminate the surrounding attribute. Everything after is parsed as further attributes on the element. |
| `value="'+x+'"` in JS-built markup | An unescaped value closes the attribute and injects markup into the DOM. |

All three shipped to production and were fixed in v0.2070 and v0.2071. The first
two also caused a visible functional bug: the admin delete confirmation silently
did nothing for any event whose title contained an apostrophe.

**Correct forms:**

- PHP, attribute context: `htmlspecialchars(json_encode($v), ENT_QUOTES)`
- JS in `timer.php`, attribute context: `escAttr($v)`
- JS, text context: `escHtml($v)`

`escHtml()` escapes `<`, `>` and `&` but **not quotes**, so it is safe for text
nodes and unsafe for attributes. That distinction is the whole reason `escAttr()`
exists.

---

## Known gap: CSP allows `script-src 'unsafe-inline'`

`auth.php` sets:

```
script-src 'self' 'unsafe-inline'
```

`'self'` stops an attacker loading script from another origin. `'unsafe-inline'`
additionally permits script written inside the HTML, covering both `<script>`
blocks and `onclick="…"` attributes. Because the browser cannot distinguish the app's own inline script from an
injected one, **the CSP originally provided no XSS mitigation at all** — every
XSS issue found in the v0.2070 review would have executed without friction.

As of v0.2072 that is partly closed: injected `<script>` elements are blocked by
the nonce (see step 1 below). Injected `on*` attributes still execute, so the
gap is narrowed, not shut.

### Why it is still there

It is load-bearing. As of 2026-08-09:

| | Count |
|---|---|
| Inline `on*` handler attributes | 244 (was 401 by a complete count; `_nav.php`/`_post_card.php` v0.2073, `checkin.php` v0.2075, `timer.php` v0.2076) |
| Inline `<script>` blocks | 64, all nonced as of v0.2072 |
| External `.js` files | 6 |

`checkin.php` has 165 inline handlers and `timer.php` 154. Removing the directive
today breaks the application completely.

### Why a nonce is only half a fix

A nonce authorizes a `<script>` block. **There is no syntax to nonce an `on*`
attribute** — inline event handlers cannot be authorized by nonce at all. They
have to become `addEventListener` with their data carried in `data-` attributes.

Note the trap: adding a nonce to `script-src` makes a browser **ignore
`'unsafe-inline'` entirely**, which blocks event handler attributes too. Done
naively that breaks every button on the site. The split is what makes step 1
shippable on its own:

| Directive | Purpose |
|---|---|
| `script-src 'self' 'unsafe-inline'` | fallback for browsers without CSP3 granularity (Firefox); behaviour there is unchanged |
| `script-src-elem 'self' 'nonce-…'` | script **elements** must carry the nonce, so an injected `<script>` is refused |
| `script-src-attr 'unsafe-inline'` | event handler **attributes** stay allowed until they are converted |

Most of this codebase's handlers are built inside JS strings at runtime, e.g.

```js
h += '<button onclick="toggleBuyin(' + p.id + ')">';
```

which is exactly the pattern that has to move to event delegation.

### Agreed path, each step independently shippable

1. ~~**Add a per-request nonce** via the three-directive split above.~~ **Done in
   v0.2072.** `auth.php` mints a per-request `CSP_NONCE`; `csp_nonce()` stamps it
   on all 64 inline blocks across 33 files. External `<script src="/...">` needs
   no nonce, it is covered by `'self'`. `cast_receiver.php` is excluded: it is
   standalone, sends no CSP header, and must not call `csp_nonce()`.
   **What this buys:** an injected `<script>` element is now refused by the
   browser. **What it does not:** an injected `on*` attribute still runs, since
   `script-src-attr` still allows them. That is what step 2 closes.
2. **Convert handlers file by file**, smallest first, to settle the delegation
   pattern before touching `checkin.php` (165) and `timer.php` (154).
   **Started in v0.2073:** `_nav.php` (7) and `_post_card.php` (9) are at zero.
   The established pattern:
   - Tag the control with a `data-*` attribute carrying whatever the old handler
     passed as arguments (`data-nav="dropdown"`, `data-pc="toggle-comments"
     data-post="12"`).
   - Dispatch from a **delegated listener on `document`**, not a per-element
     binding. Delegation is required, not stylistic: `posts_chunk.php` injects
     cards by AJAX after load, and per-element listeners would miss them.
   - Prefer an existing **external** `.js` file as the destination (`nav.js`).
     External scripts are covered by `'self'` and need no nonce. Otherwise put
     the listener in the page's nonced block, next to the functions it calls.
   - For `onsubmit="return pkConfirmForm(...)"` use the generic
     `data-confirm="…" data-confirm-ok="…" data-confirm-danger="1"` handled by
     one delegated `submit` listener. `pkConfirmForm()` calls `formEl.submit()`,
     which does not re-fire the submit event, so it cannot recurse.
   - **If the destination asset is not cache-busted, bust it in the same change.**
     Moving behaviour into a `.js` file turns a stale cached copy from an
     out-of-date file into a *broken feature*: the new markup emits `data-*`
     attributes the old file has never heard of, and the control silently does
     nothing. This shipped as v0.2073 and was fixed in v0.2074 — `nav.js` was the
     only local script tag without a `?v=` and the entire nav went dead for
     anyone holding a cached copy. Every local script tag now carries
     `?v=<?= APP_VERSION . '.' . filemtime(...) ?>`; keep it that way. The same
     trap hit `style.css` in v0.2062.
   - **Namespace argument attributes per event.** One element can carry two
     handlers (the walk-in box has both `input` and `keydown`). A shared
     `data-a1..a4` namespace produces a *duplicate attribute*, HTML keeps the
     first, and the second handler silently receives the wrong arguments. Click
     uses the bare `data-aN`; every other event prefixes its own
     (`data-keydown-a1`). This shipped in v0.2073's pattern and broke
     Enter-to-add-walk-in plus Enter in cash-in fields; fixed in v0.2075.
   - **Test the actual gesture, not just the dispatch.** A sweep that reads the
     same attributes the dispatcher reads will agree with a bug rather than
     catch it. The collision above survived 315 passing dispatch assertions and
     was found by a human pressing Enter. Add one real-gesture test per
     interaction class (type-and-Enter, click, select-change).
   - **Delegate in the CAPTURE phase.** An ancestor that calls
     `stopPropagation()` makes a bubble-phase listener on `document` blind to
     everything beneath it. `timer.php`'s control tray does exactly that, so the
     play, sound and level buttons dispatched nothing until the listeners moved
     to capture. Inline handlers were immune because they run *at the target*;
     capture restores that ordering.
   - **Count with a complete `on[a-z]+=` pattern.** An enumerated list of event
     names will miss some: `ondragstart`/`ondragover`/`ondrop`/`ondragend` were
     absent from the first sweep, so `timer.php` was reported as 154 rather than
     157 and drag-to-reorder would have been left inline.
   - **Capture a before/after baseline of the live DOM.** Snapshot every element
     carrying a handler, convert, snapshot again, and diff on (element, event).
     That is what proves nothing was dropped; a passing dispatch sweep only
     proves that what remains works.
   - When testing a conversion, make sure the fixture actually exists. A check
     that skips because no post card rendered will report a pass for work it
     never verified.
   - Verify with the **old** asset forced back in (Playwright `page.route()` can
     serve the previous file) to prove the cache-buster is what fixes it, rather
     than assuming.
3. **Drop `'unsafe-inline'`** only once the count reaches zero, after running
   `Content-Security-Policy-Report-Only` for a while to catch stragglers.

Until then, the sweeps above are the compensating control. They are the
difference between catching a regression in two seconds and catching it in a
security review months later.

---

## What the v0.2070 / v0.2071 review changed

Recorded because roughly half the fixes are structural (future code inherits
them) and half are conventions someone has to follow.

**Structural, no discipline required:**

- `sanitize_html()` now walks the subtree of an element it is about to unwrap, so
  every caller gets correct sanitization.
- Event authority resolves through `event_invites.user_id` rather than a username
  string match.
- `username_format_error()` in `auth.php` is the single username rule, applied by
  registration, the API and the profile editor.
- `pk_theme_sanitize_props()` constrains stored theme JSON on every write path.

**Conventions that must be followed by new code:**

- Attribute escaping (see the table above).
- `escAttr()` for JS-built attributes in the timer.
- Scope filters and copy-on-edit on any new theme or preset action in
  `timer_dl.php`, matching what `get_theme` / `get_presets` / `delete_theme`
  already do.
- State-changing endpoints are POST plus `csrf_verify()`; a GET may render a
  confirmation but must not write. See `rsvp.php`, `verify_email.php`, `poll.php`
  and `join_league.php`.
