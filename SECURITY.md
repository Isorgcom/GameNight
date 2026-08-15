# Security Notes

Working notes for this codebase: the checks to run before shipping, and the
history of the CSP gap that is now closed — kept because the reasoning is what
stops it being reopened, and because the conversion that closed it is still the
main source of regressions.

Last reviewed: 2026-08-10 (v0.2083).

---

## Pre-push sweeps

Run these before any push that touches more than one PHP file, and again against
the production container after a deploy. Each takes about two seconds. There is
no `php` binary on the build host, so anything invoking it runs in a container.

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

### 1a. Parse errors in PHP-GENERATED JavaScript

```bash
cd ~/qa-headless && node inline_js_sweep.js
```

Sweep 1 cannot see this. Most of the JavaScript in this app is emitted from
inside `.php` files — `checkin.php` alone renders about 3,500 lines of it — and
`php -l` is perfectly happy with PHP that prints broken JS. The failure is total
rather than local: one `SyntaxError` discards the entire `<script>` block, so
every function it declared is undefined and the whole console goes dead. There
is no partial degradation and no error on screen; the page simply renders and
nothing works.

The sweep fetches each rendered page, extracts every inline `<script>` (skipping
`src=` tags, which are real files a plain `node --check` already covers) and
parse-checks each one, so it reads exactly what the browser is asked to parse.
It walks 22 pages, logged out for the two that are reachable that way. **Add a
page to its `PAGES` list whenever a new one starts generating JS** — a page that
is not listed is not checked, and the sweep will still report all-clear.

It exists because an edit inserted a statement between an `if` and its `else`:

```js
if (!PAYOUT_STRUCTURES.length) loadPayoutStructures();
refreshPresetState();          // ← inserted here
else renderPayoutStructureSelect();
```

`php -l` passed. The file looked right in a diff. The check-in console was
completely non-functional, and the first symptom was an unrelated test failing
on `SESSION is not defined` — the global was never declared because the block
containing it never parsed.

Any insertion into generated JS deserves this sweep: the surrounding lines you
are editing may be PHP-interpolated, brace-matched across a `<?php ?>` boundary,
or (as above) one half of a control-flow statement that a diff does not show you.

### 1b. Re-run the browser suites for the pages AFFECTED

If a change touches a shared file — `_footer.php`, `_nav.php`, `auth.php`,
`db.php`, anything under `www/*.js` — the blast radius is every page that
includes it, not the file you edited. Run the full set in `~/qa-headless`, not
just the suite for the page you were working on.

This is not hypothetical: v0.2077 added `pk-dispatch.js` to `_footer.php` and
shipped, which made every control in the check-in console fire twice for a whole
release. The suite that detects it (`checkin_args.js`, reporting `calls: 2`) was
not run, because `checkin.php` had not been edited.

### 1c. Double-dispatch sweep

```bash
cd ~/qa-headless && node double_dispatch_sweep.js
```

Walks 37 pages and, for **every** `data-act*` control and generic behaviour,
fires one event and asserts the handler ran **exactly once**. It catches the
v0.2078 class of bug: two dispatchers on a page, a duplicated listener, or a
script included twice. It also flags a control whose named function does not
resolve, i.e. a dead control.

A control that fires twice is silent on anything idempotent and fatal on
anything that toggles, which is why it survived a whole release before a user hit
it on a phone. Run this after any change to a shared partial or to
`pk-dispatch.js`.

Note `checkin.php` legitimately loads the shared script *and* sets
`window.PK_DISPATCH_LOCAL`; the flag makes the shared one return early, and the
sweep reports it as standing down rather than as a fault.

**It needs an admin session, and it says so.** For its first several releases the
sweep ran as a plain user, so every admin page returned 403 and reported zero
controls — 296 of 558 controls silently unchecked, including five files that
later turned out to be broken. It now probes `admin_settings.php` first and fails
loudly if it is unreachable. Promote the dev test user, run, then put it back:

```bash
docker exec gamenight-dev php -r '(new PDO("sqlite:/var/db/app.db"))->exec("UPDATE users SET role=\"admin\" WHERE id=269");'
cd ~/qa-headless && node double_dispatch_sweep.js
docker exec gamenight-dev php -r '(new PDO("sqlite:/var/db/app.db"))->exec("UPDATE users SET role=\"user\" WHERE id=269");'
```

The generic behaviours need watching differently from `data-act*`: `data-confirm`
names no function, so there is nothing to count. The sweep stubs
`pkConfirmForm()` and asserts exactly one call per `data-confirm` form and
button. Without that it passed a release in which every delete on the home feed
raised **two** stacked dialogs.

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

**Correct forms — and note that "attribute context" is now two different things:**

| Context | Correct form |
|---|---|
| PHP, attribute holding **JS source** (`onclick="fn(…)"`) | `htmlspecialchars(json_encode($v), ENT_QUOTES \| ENT_SUBSTITUTE)` — **historical only, no such attributes remain** |
| PHP, attribute holding **data** (`data-a1`, `data-confirm`, `title`) | `htmlspecialchars($v, ENT_QUOTES \| ENT_SUBSTITUTE)`, or a cast such as `(int)$id` |
| JS in `timer.php`, attribute context | `escAttr($v)` |
| JS, text context | `escHtml($v)` |

**The `json_encode` rule is a trap now.** It was correct while attributes carried
JS source: `onclick="viewEvent(<?= json_encode($ev) ?>)"` needed a JS literal,
and the encoder produced one. After the handler conversion, `data-a*` attributes
carry *data* — `pk-dispatch.js` reads the attribute and passes the **string**
through, with no parse step. A `json_encode()`d object therefore arrives at the
handler as text.

This is not hypothetical. `calendar.php` followed the documented rule:

```php
data-act="viewEvent" data-a1="<?= htmlspecialchars(json_encode($ev)) ?>"
```

`viewEvent(ev)` read `ev.id` off a string, so **every event chip in the month
view navigated to `/event.php?id=undefined`** until v0.2083. Pass the scalars the
handler actually needs (`data-a1="<?= (int)$ev['id'] ?>"`), never an object.

`escHtml()` escapes `<`, `>` and `&` but **not quotes**, so it is safe for text
nodes and unsafe for attributes. That distinction is the whole reason `escAttr()`
exists.

**Always pair `ENT_QUOTES` with `ENT_SUBSTITUTE`.** On its own, `ENT_QUOTES`
makes `htmlspecialchars()` return an **empty string** when the input contains an
invalid UTF-8 byte. An event title carrying one produced an empty `data-confirm`,
so the delete confirmation silently did not appear (fixed across 8 files in
v0.2082). `html_entity_decode()` is unaffected and correctly does not use it.

### 3. Conversion defects in declarative markup

```bash
grep -rnE 'data-[a-z0-9-]+="[^"]*""|data-[a-z0-9-]+="[^"]*&lt;\?=|data-[a-z0-9-]+="[^"]*" \+ |data-[a-z0-9-]+="<\?= *htmlspecialchars\(json_encode' www/ --include=*.php
```

Prints nothing on a clean tree. Every hit is a real defect. Converting an inline
handler means retyping an attribute by hand, and the four ways that goes wrong
are all invisible to `php -l` and to a browser — the page renders, the control
just misbehaves.

| Pattern | What it means |
|---|---|
| `data-x="…""` | a stray duplicated closing quote; everything after it is parsed as a further attribute |
| `data-x="…&lt;?=` | a PHP tag that got HTML-escaped during conversion, so the literal `<?= … ?>` shows in the dialog |
| `data-x="…" + ` | a JS concatenation emitted inside a single-quoted JS string, so `' + i + '` lands in the markup verbatim |
| `data-x="<?= htmlspecialchars(json_encode(` | an object passed where the dispatcher will deliver a string (see the escaping table above) |

Measured, not assumed: against the tree as it shipped **before** v0.2082 this
prints **20 hits across 11 files** — the entire markup half of that batch, four
of its nine findings. On the fixed tree it prints nothing. It runs in well under
a second and needs no container and no browser, so there is no reason to skip it.

It does not catch the other five (a duplicated listener, a wrong query
parameter, a missing `data-stop` check, a JSON-quoted key, a missing
`ENT_SUBSTITUTE`) — those need sweep 1c or a browser. Use both.

**Word-boundary warning.** If you write an ad-hoc grep for handlers, anchor it.
`on[a-z]+="` matches `action=`, `position=` and `direction=`, and reported 130
inline handlers on a tree that has zero. Use `[[:space:]]on[a-z]+="` and discard
comment lines. A check that cries wolf gets ignored, which is worse than not
having it.

---

## CLOSED: CSP no longer allows inline script (v0.2079)

`auth.php` now sets:

```
script-src       'self' 'nonce-<per-request>'
script-src-elem  'self' 'nonce-<per-request>'
script-src-attr  'none'
```

An injected `<script>` is refused because it cannot carry the nonce, and an
injected `on*` attribute is refused because attribute handlers are disallowed
outright. **CSP is now a real second line of defence against XSS**, which it was
not for any of the findings in the v0.2070 review.

The nonce is carried on `script-src` as well as `script-src-elem` deliberately:
browsers without CSP3 granularity fall back to `script-src`, and a nonce there
makes `'unsafe-inline'` ignored, so they get the same guarantee rather than none.

### How it used to read, and why that was no protection at all

`'self'` stops an attacker loading script from another origin. `'unsafe-inline'`
additionally permits script written inside the HTML, covering both `<script>`
blocks and `onclick="…"` attributes. Because the browser cannot distinguish the app's own inline script from an
injected one, **the CSP originally provided no XSS mitigation at all** — every
XSS issue found in the v0.2070 review would have executed without friction.

v0.2072 closed half of it (injected `<script>` elements are refused by the
nonce) and v0.2079 closed the rest (`script-src-attr 'none'`, once the tree
reached zero inline handlers). The paragraphs below describe the intermediate
states because the reasoning is what keeps the fix from being undone, not
because any of it is still live.

### Why it took six releases to remove

`'unsafe-inline'` was load-bearing until the very end. The tree today:

| | Count |
|---|---|
| Inline `on*` handler attributes | **0** (was 401; converted across v0.2073-v0.2078) |
| Inline `<script>` blocks | 64, all nonced as of v0.2072 |
| External `.js` files | 7 (`pk-dispatch.js` added in v0.2077) |

At the start `checkin.php` alone had 165 inline handlers and `timer.php` 157, so
dropping the directive on day one would have broken the application completely.
That is why the path below was split into three independently shippable steps
rather than one flag change.

Verify the count with the anchored pattern, not a bare one:

```bash
grep -rEh '[[:space:]]on[a-z]+="' www/*.php www/*.js | grep -vE '^\s*(//|\*)'
```

### Why a nonce is only half a fix

A nonce authorizes a `<script>` block. **There is no syntax to nonce an `on*`
attribute** — inline event handlers cannot be authorized by nonce at all. They
have to become `addEventListener` with their data carried in `data-` attributes.

Note the trap: adding a nonce to `script-src` makes a browser **ignore
`'unsafe-inline'` entirely**, which blocks event handler attributes too. Done
naively that breaks every button on the site. The split is what makes step 1
shippable on its own:

The intermediate split that made step 1 shippable on its own — **superseded by
the policy at the top of this section, kept to explain the sequencing**:

| Directive (v0.2072-v0.2078, no longer in use) | Purpose |
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
   no nonce, it is covered by `'self'`. A standalone page that sends no CSP
   header of its own must not call `csp_nonce()`; `cast_receiver.php` was the
   only one and has since been deleted as dead code.
   **What this buys:** an injected `<script>` element is now refused by the
   browser. **What it does not:** an injected `on*` attribute still runs, since
   `script-src-attr` still allows them. That is what step 2 closes.
2. ~~**Convert handlers file by file.**~~ **DONE as of v0.2078** — the tree is at
   zero. The pattern below is kept because any new markup must follow it.
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
   - **Use the shared `www/pk-dispatch.js`** for any page that includes
     `_footer.php`. It is external, so it needs no nonce, and it carries generic
     declarative behaviours for the idioms that recur: `data-confirm` /
     `data-confirm-ok` / `data-confirm-danger` on a form, `data-href`,
     `data-toggle-class="id:class"`, `data-click-file="inputId"`,
     `data-select-all-on-focus`, `data-uppercase`. Reach for those before
     writing yet another near-identical named helper. `checkin.php` and
     `timer.php` carry their own copies from before it existed; leave them.
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
   - **A change to a shared partial changes every page that includes it, so run
     the suites for the pages AFFECTED, not the files EDITED.** v0.2077 added
     `pk-dispatch.js` to `_footer.php` and shipped; `checkin.php` includes that
     footer and already had its own dispatcher, so every control in the check-in
     console fired twice for a full release. Toggles are where this is fatal and
     silent: tapping a player card ran `classList.toggle('open')` twice so it
     opened and shut again, and ticking buy-in set it on then off. The dispatch
     sweep did detect it (`calls: 2` on 289 of 294 controls) — it simply was not
     run, because `checkin.php` had not been edited. The user found it on a phone
     before the suite did. If a change touches `_footer.php`, `_nav.php`,
     `auth.php`, `db.php` or any file under `www/*.js`, re-run everything.
   - **One dispatcher per page.** `checkin.php` and `timer.php` carry their own
     and set `window.PK_DISPATCH_LOCAL = 1`; `pk-dispatch.js` returns early when
     it sees that flag. Do not add a second dispatcher to a page without the
     same guard.
   - When testing a conversion, make sure the fixture actually exists. A check
     that skips because no post card rendered will report a pass for work it
     never verified.
   - Verify with the **old** asset forced back in (Playwright `page.route()` can
     serve the previous file) to prove the cache-buster is what fixes it, rather
     than assuming.
3. ~~**Drop `script-src-attr 'unsafe-inline'`.**~~ **DONE in v0.2079.** Verified
   before switching: a DOM scan across 36 pages found zero `on*` attributes in
   rendered markup (including JS-built) and zero unnonced inline `<script>`
   blocks. After switching, an injected `on*` handler and an injected
   nonce-less `<script>` are both confirmed blocked, while every nonced inline
   block still runs.

**Anything added from here must follow the conversion pattern below**, because
an inline handler will now simply not fire. `pk-dispatch.js` logs
`[pk-dispatch] no handler named X` when a name does not resolve, and the sweeps
in the pre-push section catch the rest.

The sweeps above are no longer a compensating control for an open gap — the gap
is shut. What they now protect is the *conversion*: 401 hand-edited attributes
produced nine defects that a parse check could not see, four of them still live
weeks later. They are the difference between catching that in two seconds and
catching it in a security review months later.

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
