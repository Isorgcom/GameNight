# Changelog

All notable changes to GameNight are documented here.

---

## [v0.2120] - 2026-08-27

### Fixed

- **A rejected stream URL no longer destroys the video cell.** `pk_lo_cell()`
  set `video` only when the URL survived validation, so a bad paste left the
  cell with no `video` key at all: it stopped being a video cell and came back
  as text. The author lost the cell, not just the URL. A rejected URL now stores
  an empty string, keeping it a video cell that draws its placeholder and still
  offers the Stream URL field to correct.

- **The editor and the server disagreed about `http://` links.**
  `mediaStreamUrl()` accepted an `http` URL and silently rewrote it to `https`,
  so the editor showed a green tick while `pk_lo_stream_url()` rejected the very
  same string on save. Combined with the bug above, pasting an `http` link
  looked accepted and then removed the cell. The rewrite was wishful in any
  case: a host serving plain `http` generally has no `https` listener, so the
  upgraded URL could not have played either. The client now requires `https`,
  exactly as the server does, and `diagnose()` explains why.

  Covered by `qa-headless/stream_url_agree_check.js`, which runs the same five
  URLs through the shipped client function and the shipped server function and
  asserts both reach the same verdict.

---

## [v0.2119] - 2026-08-27

### Added

- **Trigger warm-up: an alarm can now fade the stream down before it sounds.**
  Previously the duck and the sound started together, so the alarm's first
  moment landed while the stream was still at full volume — the "sudden" effect
  the ducking was supposed to prevent. A sound action gains a **warm-up in
  seconds** (`warmup`, 0-10, half-second steps, set in the trigger row next to
  the sound). With one set, the stream ramps down over that period *first* and
  the sound waits for the bottom of the ramp, arriving into audio that has
  already made room. The duck window is extended by the same amount so the hold
  still covers the sound rather than being eaten by the ramp. Left at 0, the
  behaviour is exactly as before.

- **Layout-level control over how the stream's audio behaves around an alarm.**
  A `stream` block on the layout sets `fadeOut` and `fadeIn` (50-10000ms),
  `hold` (500-60000ms, how long the duck lasts, previously a hard-coded 5s), and
  `duckTo` (0-1). That last one is the interesting knob: ducking *to* a level
  rather than to silence keeps the stream present under the alarm, where a full
  drop can read as a dropout. These are layout-level rather than per-trigger
  because they describe the room's sound, not any one event; every alarm in a
  layout should duck the same way. Each key falls back to the previous hard-coded
  default when absent, so existing layouts are unchanged.

  The four settings sit **in the video stream cell's own settings**, directly
  under the Stream URL, as a plain sentence: *while an alarm plays, this stream*
  drops to / fading down over / staying down for / coming back over. They belong
  with the stream they govern rather than in a right-click menu or a separate
  panel, so nobody has to hunt for a setting attached to the thing already in
  front of them. Choosing a default deletes the key rather than writing it, so a
  layout only carries what was actually changed, and the saved layout still
  accepts any value in range for exact figures set by hand.

### Fixed

- **A single-screen layout lost its whole-layout settings the moment it was
  opened in the editor.** `normalizeLayout()` rebuilt the document from a
  hand-written key list (`v`, `aspect`, `screens`), so anything not named there
  was silently dropped: triggers, custom elements, shared styles and now the
  stream audio settings. The new panels would have appeared to save and then
  reverted. It now carries every whole-layout key across, and still does not
  materialise absent ones as nulls.

- **The QA sound hook fired before the sound was audible.** It sat at the top of
  `tbPlaySound()` and returned early, so with a warm-up it reported the sound as
  instant and hid the very ordering the feature creates. It now fires in
  `tbPlaySoundNow()`, at the moment the sound is actually heard.

  Verified in Chromium against a live stream: with a 2s warm-up the sound fired
  at 2002ms, the stream was already at the configured 0.20 duck level when it
  did, and it got there through 34 intermediate volume steps rather than a cut.

---

## [v0.2118] - 2026-08-27

### Fixed

- **Devices poisoned by the pre-v0.2117 mute bug now heal themselves.** That
  bug wrote an autoplay-forced mute into `localStorage` as though the host had
  chosen it. v0.2117 stopped new writes, but it could not undo the ones already
  on people's machines, and the gesture unmute deliberately respects a stored
  preference, so an affected browser had no way back: it opened muted, refused
  to unmute on interaction, and looked exactly like the bug was never fixed.
  Clearing site data was the only escape, which is not a thing to ask of anyone.

  Both keys are now versioned (`gn.tbStreamMuted2`, `gn.tbStreamVolume2`) and
  the originals are deleted on sight, so every stale value retires on the next
  load without anyone touching browser settings.

  The volume key is included because it fails the same way and is harder to
  spot: a level captured while a fade was in flight could be stored as `0`,
  which looks identical to a muted stream but is not fixed by unmuting. A
  stored `0` is now treated as unset and reads back as full volume, on the
  grounds that nobody deliberately sets a stream to silent-but-unmuted.

  Covered by `qa-headless/mute_migrate_check.js`, which seeds both poisoned
  values and asserts they are ignored and removed.

---

## [v0.2117] - 2026-08-27

### Fixed

- **A stream no longer starts muted every time, and stays that way.** Two
  faults compounded. A page that has not been interacted with has no user
  activation, so a browser refuses to autoplay WITH SOUND and `tbTryPlay()`
  falls back to muted, which is correct and unavoidable. But that forced mute
  fired `volumechange`, and the preference listener recorded it as *the host's
  choice*, so `gn.tbStreamMuted` was set to true on the very first load and
  every later load started silent on purpose. The same class of bug as the
  alarm-ordering one fixed in v0.2115, in a second place.

  The guard is the `autoMuted` marker on the element rather than a flag,
  because `volumechange` fires asynchronously: a flag set and cleared around the
  assignment is already false again by the time the listener runs. The marker
  survives until it is deliberately cleared, which is exactly the window needed.

- **The first interaction now gives the sound back.** Autoplay policy only
  blocks audio *before* a page has been interacted with; afterwards, unmuting is
  allowed. A timer display always gets touched, so `tbArmUnmuteOnGesture()`
  binds one `pointerdown` / `touchend` / `keydown` listener that unmutes any
  video the browser forced quiet, turning "always starts muted" into "muted for
  the first few seconds". Only videos we muted are touched, and only when the
  stored preference actually asks for sound, so a stream the host deliberately
  silenced stays silent.

  Covered by `qa-headless/unmute_logic.js`, which lifts the real listener body
  out of `timer_beta.js` rather than mirroring it, so the test cannot drift from
  what ships. The mirrored version of that test passed while the shipped code
  was still broken, which is why it now reads the source.

---

## [v0.2116] - 2026-08-27

### Fixed

- **HLS video played in Chrome and Edge but showed nothing in Firefox.**
  `worker-src` was `'self'`, and hls.js runs its demuxer in a Worker created
  from a `blob:` URL. Chromium tolerates that; Firefox enforces the directive
  and refuses the worker outright, so hls.js never demuxed a byte and the cell
  sat at `readyState 0` with no error on the element, the only clue being a CSP
  violation in the console. The directive is now `'self' blob:`, which concedes
  little: `script-src` carries a nonce, so an attacker cannot run the script
  that would be needed to construct a blob worker in the first place.

  Confirmed by driving both engines against the same page and stream:
  Firefox paused at `readyState 0` with the violation logged, Chromium playing
  at `currentTime 8.86` with `readyState 4` and a clean console.

---

## [v0.2115] - 2026-08-26

### Changed

- **The level alarm now ducks a stream's audio instead of cutting it.** Muting a
  live stream dead for five seconds and snapping it back is more jarring than
  the alarm it is making room for. `tbFadeVolume()` ramps the volume with a
  raised-cosine ease, 250ms out and 600ms back in: out quickly enough to clear
  the way, back slowly enough not to startle a room. The asymmetry is
  deliberate, since a duck that returns as abruptly as it left draws more
  attention than the duck. An in-flight ramp is cancelled before a new one
  starts, so overlapping alarms cannot leave two animation loops fighting over
  one volume.

  Both the mute state and the *level* are captured before ducking. A host who
  muted the stream deliberately is never unmuted by the fade back in, and a host
  who turned it down to sit under table talk returns to that level rather than
  to full volume. Provider embeds (YouTube, Vimeo) keep the old hard duck: their
  volume cannot be read back over `postMessage`, so ramping blind could hand the
  room a louder stream than the host chose.

- **A stream's volume is remembered, not just whether it was muted.**
  `gn.tbStreamVolume` joins `gn.tbStreamMuted`, so a level set on a device
  survives a screen rebuild and a page reload.

### Fixed

- **The alarm's own fade can no longer be mistaken for the host adjusting the
  volume.** `STREAM_MUTED_BY_ALARM` was cleared as soon as `tbStreamMute(false)`
  returned, but the fade back in runs for another 600ms after that, and every
  step of it fires `volumechange`. The preference listener would have stored a
  half-faded level as the host's setting, so the stream came back quieter after
  every alarm until it was effectively silent. The flag is now held until the
  ramp actually finishes.

  Covered by `qa-headless/hls_fade_check.js`, which drives Chromium against a
  live HLS stream through the real trigger path and asserts the volume passes
  through intermediate values in both directions, returns to the host's 0.4
  rather than to 1.0, leaves the stored preference untouched, and never unmutes
  a stream the host had deliberately muted.

---

## [v0.2114] - 2026-08-26

### Fixed

- **A video cell no longer restarts, and no longer loses its sound, every time
  the display changes screens.** `buildScreen()` wipes the tree and rebuilds it
  on every screen change (cycling, a break screen, a trigger takeover), which
  meant a brand new `<video>`, a brand new hls.js and a brand new manifest fetch
  each time. On a cycling layout that reads as the stream reloading itself every
  few minutes, and any unmute the host had done went with the discarded element.
  Media elements are now cached by source and re-parented into the rebuilt tree
  rather than recreated; `sweepVideoCache()` runs after the tree exists and
  disposes only the players the new screen genuinely dropped, resuming any that
  were paused by being detached.

- **A stream starts with sound.** The `<video>` was hard-coded `muted` on the
  theory that autoplay demands it. Autoplay with sound is in fact granted once
  the page has user activation, which a display that has been clicked always
  has, so `tbTryPlay()` now asks for sound first and falls back to muted only if
  the browser actually refuses. The host's choice is remembered per device in
  `gn.tbStreamMuted`, mirroring the existing `gn.muteStreamDuringAlarms`
  convention, so it survives both a screen rebuild and a page reload.

- **The level alarm no longer trains the display to stay silent.**
  `tbMuteStreamForAlarm()` set `STREAM_MUTED_BY_ALARM` *after* calling
  `tbStreamMute(true)`. Muting a real media element fires `volumechange`, so the
  new preference listener saw the flag still clear and recorded the alarm's own
  ducking as "the host wants this muted" — one level change and every later load
  would have started silent. The flag is now set before the call.

  Verified in a browser this time, not just structurally:
  `qa-headless/hls_persist_check.js` drives Chromium against a live HLS stream
  and asserts the stream actually plays, that the same element survives two
  rebuilds, that playback and unmute survive with it, and that a stored unmute
  is honoured on reload. Confirmed to fail on v0.2113 with exactly the three
  symptoms above before passing on this build.

---

## [v0.2113] - 2026-08-26

### Added

- **A Timer BETA video cell can now play a live stream directly, with no
  approval from an admin.** Paste any https URL ending in `.m3u8` (HLS, what a
  restreamer publishes) or `.mp4` into a video cell and it plays in a real
  `<video>` element rather than an embed iframe. This is the path for IPTV, a
  ball game, or anything a host restreams from their own hardware. Deliberately
  not gated: `pk_lo_stream_url()` accepts a direct media URL from any https
  host, because the existing allow-list only ever needed to govern *framing*,
  and a host choosing the source for their own game should not be a support
  request. Nothing is given away by that, since the URL only becomes a `<video>`
  src and CSP `media-src` already permitted any https origin. The allow-list in
  Settings still governs iframe embeds, where framing an arbitrary site is a
  genuine hazard.

- **hls.js, vendored, so HLS works outside Safari.** Safari and iOS play
  `.m3u8` natively; every other browser needs Media Source Extensions.
  `docker-entrypoint.sh` fetches hls.js 1.5.17 into `vendor/` alongside Jodit
  and the rest, so it is served from our own origin and satisfies
  `script-src 'self'` with no CSP exception. It loads on `timer_beta.php`
  without `defer`, for the same reason `pk-seg.js` does: `buildCell()` calls
  `attachHls()` the moment the layout fetch resolves, and a deferred library
  loses that race intermittently. Playback is tuned for live
  (`liveSyncDurationCount: 3`, `maxLiveSyncPlaybackRate: 1.5`) so a display sits
  near the live edge and catches up by playing slightly fast rather than
  drifting further behind all evening. Fatal errors recover through hls.js's own
  `startLoad()` and `recoverMediaError()`, because a display runs unattended for
  hours and a network blip must not end the night.

- **Host guide step 9, "Put a video on the display."** Covers the single
  requirement (a public https URL ending in `.m3u8` or `.mp4`), the two
  realistic ways to get one (a source that already provides it, or a restreamer
  behind Cloudflare Tunnel or Tailscale Funnel), why `http` and LAN addresses
  can never work, and the fact that every viewer pulls from wherever the stream
  lives, so one TV beats a dozen phones.

### Security

- **`connect-src` now exists in the CSP.** Without the directive the policy fell
  back to `default-src 'self'`, which would have broken HLS everywhere except
  Safari: a native `<video>` fetches segments under `media-src`, but hls.js
  pulls them over XHR, which is `connect-src`. Chrome and Firefox would have
  refused every segment while Safari played fine, a split that reads as a
  browser bug rather than a policy. Set to `'self' https:` rather than a host
  list so hosts need no approval, which concedes little in practice:
  `media-src` was already `'https:'`, so a page able to exfiltrate over
  `connect-src` could equally have used a media URL. The real protections here,
  the `script-src` nonce and `script-src-attr 'none'`, are untouched.

### Fixed

- **The layout editor now says what is actually wrong with a rejected link.**
  "Not a recognised streaming link" was equally true of an `http://` URL, a
  missing `.m3u8`, a LAN address and a typo, and it sent hosts to an admin for
  something they could fix themselves. A new `diagnose()` in
  `timer_beta_edit.js` names the real problem in each case. The editor was also
  validating with `normalizeStreamUrl()` alone, which only knows embed
  providers, so it would have shown a red box on a perfectly good `.m3u8` that
  the server saved happily; `mediaStreamUrl()` is now exposed through
  `TBPreview` and the editor checks both routes.

- **The level alarm can mute a real media element.** `tbStreamMute()` only knew
  how to `postMessage` a YouTube or Vimeo iframe, so a `<video>` cell would have
  kept playing over the alarm. It now mutes the element directly, remembering
  the prior state so the alarm's unmute cannot switch on audio a host had
  deliberately muted.

### Infrastructure

- **Deploying this needs a container restart, not just a `git pull`.** `www/` is
  a bind mount and `docker-entrypoint.sh` only runs at container start, so a
  pull alone will not fetch `vendor/hls.min.js`. Without it HLS silently fails
  in Chrome and Firefox while working in Safari. Use
  `docker compose down && docker compose up -d --build`.

---

## [v0.2112] - 2026-08-26

### Fixed

- **Loading a legacy Setup preset no longer leaves a tournament silently
  schedule-less.** A preset saved before v0.2089 predates blind schedules
  traveling with presets (`blind_levels` NULL = legacy), and loading one
  restored payouts and game config while leaving the timer with no schedule at
  all — the host found out at the table, when the board read 0/0 and the clock
  stopped dead at the end of level 1. Three guards now stand, each at the
  moment its audience can act: `load_payout_structure` answers
  `no_blinds: true` when the loaded structure brought no schedule and the
  game's timer still has none (cash games excluded — no blind clock to
  starve); Setup surfaces that as an alert on load, pointing at the Blinds tab
  and noting that **Update preset** afterwards stores the schedule in the
  preset for good; and the BETA display renders '&mdash;' for blinds instead
  of a confident-looking 0/0 on a live game with an empty schedule, with an
  amber banner — visible only to a viewer who can control the game — saying
  what is wrong and where to fix it. Guests and cast screens see the quiet
  dashes; sample mode and the editor preview are untouched. Verified by
  `qa-headless/noblinds_check.js` plus load-path round-trips both ways.

---

## [v0.2111] - 2026-08-26

### Added

- **Timer BETA: video stream cell — the classic timer's stream option, carried
  over as a layout cell.** A cell may now hold `video: '<pasted URL>'` and
  renders a streaming embed filling its flex box; in the editor, "Use a video
  stream instead" sits beside the image / QR / chip-legend / seat-map
  conversions (inspector and right-click menu both), with a Stream URL field
  that validates through the renderer's own `normalizeStreamUrl` — ported
  verbatim from `timer.js`, so both timers accept exactly the same links:
  YouTube in every spelling (watch, youtu.be, embed, live, shorts, tv.),
  Twitch channels (`parent=` filled from `location.hostname`, so one layout
  works on dev and prod), Vimeo, Kick, a best-effort Prime pass-through, and
  the admin "Allowed video stream hosts" setting (injected as
  `TB_STREAM_HOSTS`). A link that will not survive Save warns in red as it is
  typed.

  Unlike the QR target (an enum), a video URL is author content in a shareable
  document, so three fences guard it and each would hold alone:
  `pk_lo_stream_url()` drops unrecognised hosts at save, the renderer only
  ever loads `normalizeStreamUrl()`'s output (unknown hosts draw a dashed
  `▶ video` placeholder, never an iframe), and the global CSP `frame-src`
  lists the same hosts as the browser-enforced third copy. Video cells ride
  the `isImage` flag so the text pass, empty-cell auto-hide and `capCell()`
  leave the iframe alone, and the iframe is `pointer-events: none` in embed
  mode only, so an editor-preview click selects the cell instead of vanishing
  into the player. Trigger sounds duck the stream: `tbPlaySound()` postMessages
  mute/unmute to YouTube and Vimeo embeds for a 5s window, honouring the
  classic timer's `gn.muteStreamDuringAlarms` toggle so a device's choice
  there carries over. Verified by a 12-point Playwright check
  (`qa-headless/video_cell_check.js`): normalized-embed render, raw URL never
  reaching the DOM, placeholder, conversion flow and save-side dropping.

---

## [v0.2110] - 2026-08-25

### Fixed

- **The site icon no longer sends Safari through a scheme-downgrading redirect
  chain.** `.htaccess` routed `/favicon.ico` to `/favicon.php` with `R=302`, an
  *external* redirect. Apache runs behind Nginx Proxy Manager on plain HTTP, so
  mod_rewrite built that `Location:` as an absolute `http://` URL — every page
  load walked `https -> http -> https -> /uploads/banner.png`, three hops and a
  downgrade, on a site that sends HSTS telling clients never to speak `http` to
  it. Apple's networking daemon retried the chain on a backoff loop: the access
  log shows bursts of 20-48 plain-HTTP `/favicon.php` requests from a single
  client in one or two seconds, 101 of 110 of them from iOS 26.6, after which
  the affected device stopped reaching the site at all. One member's sessions
  ended in exactly that burst on three separate days (Aug 14, 20 and 24) and he
  could not load the home page afterwards.

  The rewrite is now internal (`[L]`), so the request never leaves HTTPS, and
  `favicon.php` reads the icon out of `uploads/` and streams it instead of
  redirecting to it — the path is confined to the uploads directory with a
  `realpath()` prefix check, non-image types are forced to
  `application/octet-stream`, and a `banner_path` pointing at a missing file
  still falls back to the old relative redirect. `/favicon.ico` is now a single
  `200 image/png` with zero redirects, down from two redirects and a scheme
  downgrade.

---

## [v0.2109] - 2026-08-22

### Added

- **Timer BETA: font options on text cells and shared styles, with ten bundled
  display faces.** Every text cell gains a **Font** setting, offered in the
  Inspector, the preview right-click menu, and on shared-styles cards (a shared
  style can give a whole design its typeface in one place). Eight choices are
  web-safe system stacks (Serif, Monospace, Condensed, Wide, Heavy, Impact,
  Script, Comic); ten more ship with the site as latin woff2 subsets in
  `www/fonts/` (~187 KB, all SIL OFL, credits in `www/fonts/LICENSE.md`):
  Bebas Neue, Oswald, Anton and Orbitron for scoreboard/poster work, **Digital
  clock** (DSEG7, a true 7-segment face for `<clock>` and blinds cells), the
  casino serifs Cinzel and Playfair Display, plus Rye, Monoton and Lobster.
  `fonts.css` declares them with `font-display: swap` and every key carries a
  same-shape fallback stack, so a display never blocks on a font. Self-hosted
  on purpose: the closed CSP's `default-src 'self'` already covers same-origin
  fonts, so no policy change and no third-party request from any screen. A
  layout stores a whitelisted **key**, never a raw `font-family` string - the
  same contract as image paths and QR targets - enforced server-side by
  `PK_LO_FONTS` in `timer_beta_dl.php` on cells and shared styles alike, and
  the editor's picker reads the engine's own list via `TBPreview.fonts` so it
  can never offer a face the renderer won't paint. Fonts are base-only like
  size and align, so a conditional variant can never swap faces and reflow the
  layout mid-game. The picker previews each option in its own face (the editor
  pages load `fonts.css` too), and `help-timer.php` documents the feature.
  Operator note: QA gained `beta_font_check.js` (picker, preview application,
  woff2 activation, sanitizer round-trip), and `beta_ctxmenu_check.js` was
  repaired - it had been aiming at hardcoded tree indexes from the Classic-boot
  era and now finds a real text cell dynamically.

## [v0.2108] - 2026-08-22

### Fixed

- **The Timer Layouts page now opens on the Default Layout, without a flash of
  Classic on the way in.** The standalone layout editor (the hamburger menu's
  Timer Layouts, `timer_beta_edit.php`) deliberately booted on Classic as a
  plain canvas, even though `timer_beta.js` documents the Default Layout as
  "the layout to copy when starting a design" and it is what an unconfigured
  display actually shows. `boot()` in `timer_beta_edit.js` now opens on
  `PV.defaultKey` whenever no event binding decides otherwise (Classic remains
  only as a fallback if that key ever goes missing), and the name box seeds as
  "Default Layout (mine)" to match the other boot paths. Fixing that exposed a
  second cosmetic bug: the embedded preview (`timer_beta.php?embed=1`) painted
  `renderLayout('classic')` as a placeholder before the editor's first
  `setLayout()` push. That seed was invisible while both sides agreed on
  Classic, but became a brief wrong-design flash once they stopped agreeing.
  Embed mode now paints nothing until the editor pushes the real layout; the
  update loop tolerates the layoutless window because every consumer of
  `CURRENT_LAYOUT` guards on it and the cell arrays start empty. Event-context
  editing on `event_display.php` is unchanged. Verified headless against dev
  with a new `beta_defaultboot_check.js` (boots on the Default Layout's
  catch-all screen; the embed page stays blank and error-free with no editor
  attached; the plain display page still seeds itself) plus the existing
  `beta_editor_check.js` suite, 9/9 on both.

## [v0.2107] - 2026-08-21

### Added

- **Table Manager: a phone-first tournament director console**
  (`/table_manager.php`, linked as 📲 Table Mgr from the check-in header and
  event.php's host row). The manager at a live table sees the clock, blinds
  and next blinds in a sticky header — with play/pause, level skip, ±1 minute
  and undo behind a tap — and runs the whole roster one-thumbed: Buy In / KO /
  Re-enter as state-matched row primaries (a knockout is two taps, with a
  10-second Undo snackbar that also reverses a mistaken heads-up KO that
  auto-finished the game), a bottom action sheet for rebuys, add-ons,
  bounty/jackpot entry, table moves, notes, the money ledger and removal, a
  permanent amber strip for pending walk-in approvals with arrival toast and
  chirp, a Seats view grouped by table with one-tap rebalance, and entry-ticket
  redemption honoured at buy-in. The page is a 20 KB standalone (no nav, no
  footer — its own Add-to-Home-Screen identity, "Table Mgr"), built entirely
  with DOM nodes and `textContent` (no HTML-string rendering), driven by two
  polls: `timer_dl.php get_state` every 3 s (drift-free `ends_at_ms` anchor
  clock, ported from Timer BETA) and `checkin_dl.php get_session&slim=1` every
  10 s. Zero new mutation endpoints — every action rides the existing
  CSRF-gated `checkin_dl.php`/`timer_dl.php` actions, `can_manage_event()`
  gates the page. Design doc: `TABLE_MANAGER.md`.

### Changed

- **The console's overnight-decay failure is designed out of the new page.**
  `checkin.php` embeds its CSRF token once at render, so a console left open
  past the session GC goes silently read-only. Table Manager refreshes its
  token from every poll and retries a 403 write exactly once after a forced
  refresh; it also holds a screen wake lock (NoSleep fallback for iPhone over
  HTTP), stops polling while hidden and resyncs on return, shows an amber
  "Reconnecting…" / red "Session expired" strip instead of swallowing fetch
  errors, and reloads itself when a new build ships. To support this,
  `checkin_dl.php get_session` gains three additive fields — `csrf_token` and
  `asset_v` on every response, and a `slim=1` flag that drops the 200-row log
  (31.7 KB → 8 KB measured). `checkin.php` never sends the flag and is
  untouched by all three.

### Added

- **Browser push notifications.** Everything that lands in the in-app inbox
  (invites, reminders, RSVP replies, comments, cancellations, host messages,
  DMs) can now also arrive as an OS notification, even with the site closed.
  The sender (`www/webpush.php`) is self-contained PHP on OpenSSL primitives,
  no composer: VAPID keys (RFC 8292, generated once into `site_settings`),
  ES256 JWTs, and aes128gcm payload encryption (RFC 8291). Subscriptions live
  in the new `push_subscriptions` table, one row per browser/device, pruned
  automatically when the push service answers 404/410. The service worker
  (`www/sw.js`) shows the notification and focuses or opens the target page on
  click; repeated pushes for the same link collapse into one. A PWA manifest
  (`www/manifest.php` + generated icons) covers Android installs and is the
  prerequisite for iPhone push (home-screen install required by Safari). Push
  fan-out rides the three `user_notifications` insert sites and is try/catch'd
  throughout — a push failure can never block email/SMS delivery.
- **Users are asked, not defaulted.** A dismissible card (footer, bottom-right)
  offers browser notifications with the same three-answer pattern as the timer
  opt-in: **Turn on** runs the permission+subscribe flow in place, **Not now**
  snoozes the ask for 30 days in that browser (`localStorage`), **No thanks**
  is permanent and server-side (`users.push_prompt_dismissed`, the
  `mfa_offer_dismissed` pattern). The card only appears where enabling could
  succeed: push supported, permission not denied, device not already
  subscribed. A "Browser Notifications" card in My Settings
  (enable/disable per device, device count, send-test button) manages it
  after the fact, driven by the new `www/push_dl.php` endpoint and shared
  client plumbing in `www/push.js`.

### Security

- CSP gains `worker-src 'self'` for the service worker; no other policy
  changes. The opt-in card dispatches through `data-act` like every other
  control — the double-dispatch sweep now also asserts its three handlers
  exist on every page that renders the footer.

### Changed

- **Timer Layouts and Help join the horizontal menu bar.** Both already
  existed in the hamburger menu, but the primary desktop navigation never
  surfaced them. The bar now shows a **Timer Layouts** link (with the BETA
  tag, logged-in users only, active-state on `timer-beta` pages) after
  Calendar, and a **Help &#9662;** drop-down at the end mirroring the
  hamburger's group — Host Guide, Guest Guide, Timer Guide, plus Support for
  logged-in users. The drop-down reuses the existing `data-nav="dropdown"`
  delegation in `nav.js` (open/close and outside-click handling come for
  free); the button is styled to match the bar's links and its menu opens
  left-aligned under the button, since the shared `.nav-dropdown` is
  right-aligned for the avatar/hamburger corner (`www/_nav.php`).

### Added

- **The landing page hero gains a screenshot carousel: six real app screens,
  then all six timer layouts in TV and phone views.** The strip is a
  scroll-snap carousel showing exactly one image per slide — each `figure`
  is the full strip width (`flex:0 0 100%`) with `scroll-snap-align:center`
  and `scroll-snap-stop:always`, so a swipe, trackpad fling, or chevron click
  always settles centered on one shot; the chevron buttons (`lpGalNav`, via
  `pk-dispatch`) make it navigable with a mouse. Images keep a fixed
  320px/210px height with `object-fit:contain` so wide TV shots letterbox
  instead of distorting on narrow screens. The opening six slides show the
  app in action — event editor, event page with RSVPs, month calendar,
  check-in console, table draw, and the payouts editor — shot headlessly on
  dev against a seeded dummy tournament; the remaining 24 are the themed
  timer layouts. All assets are repo-local because CSP `img-src` is `'self'`
  (`www/_landing.php`, `www/img/landing/*.jpg`).

### Changed

- **The timer opt-in ask gains a third answer: "Not now".** It was a two-button
  confirm, so closing it meant answering it. The ask is now a popup with three
  choices, using the check-in console's own modal pattern rather than
  `pkConfirm` (which offers only two): **Use the new timer** and **Keep
  classic** are final and stored server-side, while **Not now** is deliberately
  *not* an answer — the account stays unanswered and the popup simply goes
  quiet in that browser for 30 days, via a `localStorage` timestamp that any
  real answer clears. Copy still adapts for a game already on the new timer
  ("Make it my default"). Three hosts answered the previous version; their
  answers stand and they are not asked again (`www/checkin.php`).

- **The README describes the application as it is today.** It was last revised
  on 21 June, roughly 45 releases ago, and had drifted from stale into
  misleading: no mention of the designable timer displays, the Game Setup
  editor, per-event blind schedules, multi-reward payouts (points, entry
  tickets, prize labels, bounties, jackpots), direct messages and event chat,
  support tickets, or the in-app guides. Those are now in the feature list, the
  security line says the CSP is closed rather than merely present, and SMS
  mentions host/guest conversations. The maintainer release flow stopped at
  "commit and push"; it now covers the pre-push checks, the version bump and
  changelog riding in the same commit, branch-and-PR for feature work, tagging
  the release, and that production needs only a `git pull` because `www/` is
  bind-mounted. Install, SMS, Leagues, API and troubleshooting were already
  accurate and are unchanged.

---

## [v0.2102] - 2026-08-18

### Changed

- **Hiding a post from your feed is now findable.** The feature already existed
  and worked end to end — the `user_post_hidden` table, the endpoint, the feed
  filter and the "Show hidden posts" view — but its only control was an
  unlabelled grey `···` in the corner of a card, with a transparent background
  and border on white. Nobody looks there for "stop showing me this", so it
  read as missing. Each card now carries a labelled **👁 Hide** button at the
  right end of its action row, opposite the comments count (**👁 Unhide** in
  the hidden view), and the kebab is styled as an actual button — background,
  border, hover and focus ring — while keeping Edit and Delete. Both controls
  share the same handlers and endpoint; nothing about the data model changed.
  The new button is marked `data-pc="stop"` so pressing it doesn't also expand
  the comments thread it sits in (`www/_post_card.php`, `www/index.php`).

- **The Host Guide documents the Setup editor, which it had never covered.**
  The guide still described the pre-Setup flow, telling hosts to click a
  "Levels" button for blinds. A new step 8 walks the editor and each tab (Game,
  Payouts & Rewards, Blinds, Timer, Chip set), notes that the tournament-only
  tabs hide for cash and that Blinds and Timer stay locked until the game is
  saved as a tournament, and explains that **Save game** commits every tab at
  once — blind schedule included — and now leaves you on the same tab with a
  "Saved ✓" confirmation, with Close or Escape as the way out. A screenshot and
  a note about presets storing the whole editor round it out
  (`www/help-hosts.php`, `www/img/help/setup-editor.png`).

- **The Timer Guide covers the opt-in and the binding switch.** It now explains
  the one-time "which timer?" question and the Settings default (asked once,
  remembered, applies to new games while each game's own switch still wins),
  describes binding a layout with the **Use this layout for _your event_**
  yes/no switch including the note that names an already-bound layout, states
  that a game with nothing chosen shows Default Layout — which is also what the
  editor opens on, so editing matches the TV — and points at the chip set's own
  tab (`www/help-timer.php`).

---

## [v0.2101] - 2026-08-17

### Changed

- **"Save game" no longer closes the Setup editor.** Saving is a checkpoint,
  not an exit: setting a game up is several passes across Game, Payouts,
  Blinds, Timer and Chip set, and being returned to the player list after each
  save meant reopening Setup and finding the tab again. The editor now stays
  put on the same tab, and since it is left standing the confirmation has to be
  visible — the Save button reads "Saved ✓" for a moment. Two things keep the
  standing editor truthful: the saved session is re-read so the preset
  provenance line recomputes, and the tournament-only tabs re-evaluate their
  gating, so a save that changes the game from cash to tournament brings Blinds
  and Timer to life immediately instead of on the next reopen. Leaving is
  unchanged: the view behind is rebuilt on exit, and a genuinely unsaved edit
  still prompts before discarding (`www/checkin.php`).

---

## [v0.2100] - 2026-08-17

### Added

- **A per-user opt-in to the new timer, with a one-time ask.** Settings gains a
  "Tournament timer" choice (Classic or New timer layouts), stored as
  `users.beta_timer` — a deliberately nullable column where NULL means "never
  answered". The first time a host opens the check-in console for a tournament
  they are asked once, and the answer is stored either way so it never asks
  again. Saying yes switches that game immediately and opts them in globally:
  the preference seeds `use_beta` wherever a timer row is *created*, so future
  games start on the new display while each game's own Setup switch still wins
  afterwards and already-configured games are untouched. All five timer-row
  creation paths now pass the acting host's preference (`pk_ensure_timer_row()`
  gained a parameter; `pk_user_beta_pref()` is the lookup). The ask fires on
  games already using the new timer too, with copy that asks about making it
  the default rather than switching this game. `current_user()` had to select
  the new column, or the "never answered" state could never be seen.

- **Chip set is its own tab in the game Setup editor.** It was appended to the
  bottom of the Timer pane, below the entire layout editor, which made it
  effectively unreachable. It now sits next to Timer as a peer of Blinds. It
  follows the game-type dropdown like Blinds and Timer (hidden for cash) but
  is deliberately *not* gated on a saved tournament the way those two are:
  they need one because `event_setup_dl` refuses anything else, while the chip
  set rides along in the ordinary settings save (`www/checkin.php`).

### Changed

- **The event-binding control is a named yes/no switch, not a buried button.**
  Binding a layout to a game used to be a button wedged between Import and
  Delete, dressed exactly like Export and filed among the file operations —
  which is not where anyone looks for "put this on the TV". The editor header
  now carries an information bar asking one question about the layout on
  screen: **Use this layout for _\<event name\>_**, answered by a yes/no switch
  (a real checkbox behind a styled track, so keyboard focus, the focus ring and
  the label association all survive; reduced motion and coarse pointers are
  handled). When a different layout is bound, a quiet note names it, so
  switching on reads as a replacement. The event name is newly injected by both
  pages that embed the editor, so it names the right game in either.

- **The editor toolbar is two rows by design.** It was one `space-between` row
  with wrap, so ordinary screens got two lines by accident while ultrawides got
  a single line with the controls flung to the far right — the same page
  presenting two different toolbars. Title on one line, controls beneath, at
  every width.

- **"Showcase (feature tour)" is now "Default Layout", and leads the list.**
  It is what an unconfigured display shows, so it reads better as the default
  and sorts first in both the display picker and the editor's Load menu (the
  pickers iterate insertion order). Its internal key stays `showcase`: that is
  what `timer_state.layout_builtin` stores, and renaming it would orphan every
  existing binding.

### Fixed

- **The editor opened on Classic for a game whose display shows the Default
  Layout.** v0.2093 scoped the new default to displays only and left the
  editor's blank canvas on Classic. Defensible for the standalone library
  editor, but wrong inside an event, where the editor is showing *that game's
  display* and the binding bar directly above it says so — the canvas
  contradicted both the bar and the TV. In event context with nothing bound the
  editor now opens on the engine's default layout; a game bound to a built-in
  still opens on that one; the standalone library editor still starts on
  Classic (`www/timer_beta_edit.js`, `TBPreview.defaultKey`).

---

## [v0.2099] - 2026-08-16

### Changed

- **The daily upload allowance is 100, up from 20.** A timer-layout import
  re-uploads every embedded image and burns one slot each — an artwork-heavy
  layout carries around ten, so 20 let two imports exhaust a user's whole day,
  and the second one failed with a blank "1 embedded image couldn't be
  imported". The constant keeps its `!defined()` guard, so an install can
  still set its own value in `config.php`; admins remain exempt. The
  surrounding safeguards are unchanged: byte-sniffed MIME, must decode as a
  real image, 8MB per file, and cron sweeps orphans after 24 hours
  (`www/db.php`).

### Fixed

- **Import failures now say why.** When an embedded image or sound failed to
  re-upload during a layout import, the alert reported only a count — the
  server's actual reason ("Daily upload limit reached — try again tomorrow")
  was received and thrown away, which turned a self-explanatory situation
  into a mystery. Both re-upload paths now carry the server's first error
  string into the message (`www/timer_beta_edit.js`).

---

## [v0.2098] - 2026-08-16

### Changed

- **The Timer Guide documents Padding and its preview overlay.** "Building a
  layout" gains a paragraph on what padding is (the CSS shorthand forms, the
  vh/vw units and why they scale with the display) and on the green-bands
  overlay v0.2097 added, with a new screenshot of the PCF Blinds plate showing
  the padding clearing its painted tab — the most common reason to pad at all.
  The Artwork section cross-references it where baked-in labels come up
  (`www/help-timer.php`, `www/img/help/timer-padding.png`).

---

## [v0.2097] - 2026-08-16

### Added

- **The Padding field explains itself, and the preview shows it.** Padding is
  the least self-explanatory field in the layout editor: CSS shorthand, vh/vw
  units, and its main use here — keeping text clear of areas painted into a
  box's artwork (a label tab, a mascot) — is not guessable. The field (on
  cells and on rows/columns) now carries a tooltip spelling out the one/two/
  four-value forms, the units, and that classic use. While the field has
  focus, the preview overlays the box's padding as green bands with a dashed
  outline around the content area, devtools-style, re-applying the typed
  value tentatively on every keystroke so the bands move as you type. Leaving
  the field removes the overlay and restores the box's committed padding, so
  an abandoned edit leaves nothing behind. The "?" reference popover gained a
  matching Padding row (`www/timer_beta_edit.js`). Prompted by the Griff's
  points-bar overlap: the padding needed to clear a baked-in label, and there
  was nothing telling anyone that, or showing where the padding actually sat.

---

## [v0.2096] - 2026-08-16

### Fixed

- **A points league's prize elements rendered blank on a live game.** The Timer
  BETA engine built each place's prize line from exactly two sources: the
  `prize_label` field, or a dollar amount computed as a percentage of the money
  pool. A free points league has no pool, and its points live in
  `poker_payouts.points`, which the engine never read — so every place produced
  an empty label, `<prizes.line>` came out empty, and the auto-hide dropped the
  prize bar entirely. Each place is now composed from every reward type Payout
  2.0 can grant, joined when a place carries several: the pool cut (when a real
  pool exists), points as "100 pts", entry tickets as "🎟 $25", and the prize
  label ("1st: 100 pts · Bar tab"). The dollar amount is one part among equals
  rather than the gatekeeper. Places also use the shared ordinal helper, so
  21st no longer renders as "21th" (`www/timer_beta.js`). Verified against a
  live session with a points-only structure, not just the sample preview.

---

## [v0.2095] - 2026-08-16

### Fixed

- **A border set on a cell never rendered.** The engine painted a `border` on a
  row or column (`applyBox`) and painted a cell's `bg` every tick
  (`applyEmphasis`), but a `border` stored inside a cell spec was painted by
  nothing — even though the server sanitizer has always preserved it. It only
  worked when an author happened to put the border on the node wrapper instead,
  which is exactly the kind of accident that made one box in a design show its
  frame while its siblings stayed flat. `buildCell` now applies `spec.border`,
  static on purpose: border is not a variant property, so the per-tick emphasis
  painter never touches it. Layouts that already carry cell borders (they were
  stored all along) simply start showing them (`www/timer_beta.js`).

### Added

- **A Border field in the editor.** The property was sanitizer-legal but
  unreachable: neither the cell Inspector nor the row/column Inspector offered
  it, so the only way to get a border was hand-editing JSON. Both Inspectors now
  carry a Border text field next to Background (`www/timer_beta_edit.js`).

---

## [v0.2094] - 2026-08-16

### Changed

- **The PCF layout's bottom bar shows the payouts instead of the chip legend.**
  The built-in "PCF Poker Chip Forum" board ended with a chip-denomination
  legend; that bar now carries `<prizes.line>` ("1st: $525  2nd: $315  3rd:
  $210"). The artwork had to change with it: the old plate has the word
  "Chips:" baked into its pixels, so the cell was repointed at
  `pcf2-bar-plain.png`, the identical bar without the label, and its left
  padding dropped from 11vw to a symmetric 3vw since that gap existed only to
  clear the painted word. A game with no payout structure renders the cell
  empty, and the engine's auto-hide drops the bar rather than showing a blank
  plate. Only the built-in is affected; a saved copy someone made of it keeps
  whatever it already had.

---

## [v0.2093] - 2026-08-15

### Changed

- **Showcase is the default layout for a display that has never chosen one.**
  A timer opened with no `?layout=`, no layout bound to its game, and nothing
  remembered on that device used to fall back to Classic; it now shows
  "Showcase (feature tour)", which demonstrates what the engine can do rather
  than the plainest board it ships with. Nothing that has made a choice is
  touched: a game with a bound layout keeps it, and a device that has picked
  before keeps that. The key is now a single `DEFAULT_LAYOUT` constant in
  `www/timer_beta.js`, where two fallback sites used to name it separately.
  Scoped to displays on purpose: the editor's blank canvas still opens on
  Classic, a plain thing to build ON rather than a finished design carrying
  three screens and four triggers, and the editor preview's throwaway first
  paint still seeds from Classic so a default layout's triggers are never armed
  only to be discarded.

### Fixed

- **The layout editor opened on the wrong screen with a multi-screen default.**
  Boot selected screen index 0, but conditional screens sort FIRST in the array
  so that a catch-all cannot win the evaluation scan ahead of them — meaning
  index 0 is a conditional screen, and the editor opened on Showcase's Phone
  view instead of Main. It never showed before because Classic has a single
  screen. Boot now uses `mainScreenIndex()`, which the load path has always
  used (`www/timer_beta_edit.js`).

---

## [v0.2092] - 2026-08-15

### Added

- **A Showcase layout that demonstrates what the timer engine can do.** New
  built-in "Showcase (feature tour)" in `www/timer_beta.js`, meant as both a
  ready-to-use board and the layout to copy when starting a design. It carries
  three screens that exercise the engine's three switching ideas at once: a
  catch-all **Main** (event and game name, big clock, share QR, a prominent
  blinds panel over a next level / next break strip, then players, average
  stack, entries and prize pool), a **Break** screen that takes over on its own,
  and a **Phone** screen for whoever scans the code. Along the way it uses a
  share QR, per-cell conditions (the ante lines exist only in an ante round), a
  paused-clock variant, and four triggers, including the one-minute warning
  before the blinds move. The Phone screen is listed first on purpose: screens
  are scanned in order and the first match wins, so a phone keeps its simple
  view during a break and the break is announced there by a conditional cell.
  No chip legend and no seat map, since both render nothing until a host has
  entered a chip set or seated players. The fallback for a display that has
  never chosen a layout is still Classic.

### Fixed

- **Binding a newly added built-in layout to a game answered "unknown builtin
  layout".** The list of valid built-in keys was hardcoded separately in
  `event_setup_dl.php` and `checkin_dl.php`, apart from `LAYOUTS` in
  `timer_beta.js`, and both copies were missed when Showcase was added. There is
  now one list, `pk_timer_builtin_keys()` in `_poker_helpers.php`, which both
  endpoints already include and now call. Adding a built-in still means editing
  the JavaScript and that list together, which the comment there says plainly.

- **The blind editor listed Ante before the blinds.** The Rounds grid ran Level,
  Duration, Ante, Small Blind, Big Blind, so the ladder read wrong at a glance.
  It is now Small Blind, Big Blind, Ante, matching the classic timer's own grid
  and every printed structure sheet (`www/event_blinds.js`). Cells are addressed
  by `data-col` rather than position, so nothing else moved with them.

- **The generator produced antes at half their intended size.** It set the ante
  equal to the *small* blind; a big-blind ante, which is how tournaments run it
  now, equals the big blind. Generating with antes from level 3 now yields
  75/150 ante 150 rather than ante 75. Inserting a round had the same flaw from
  the other direction: it copied the previous round's ante verbatim while
  computing fresh blinds, leaving the new row an ante a rung below its own big
  blind, so it now inherits only *whether* the level antes. Existing saved
  schedules are left exactly as they are: two live presets carry the old
  pattern, both privately owned, and half-a-big-blind is a legitimate structure
  that cannot be told apart from the old output with certainty.

---

## [v0.2091] - 2026-08-15

### Infrastructure

- **Cron now sweeps abandoned timer uploads.** A timer image or sound only counts
  as referenced once the layout using it is *saved*, so a file uploaded and then
  abandoned (a background swapped before saving, a sound replaced by another) was
  invisible to the delete-time GC in `timer_beta_dl.php`, which only sweeps names
  the deleted layout referenced. Those files sat on disk forever. A new section
  in `www/cron.php` removes anything in `uploads/timer_layouts/` or
  `uploads/timer_sounds/` that is older than 24 hours and referenced nowhere.

  Because it unlinks real files it is deliberately conservative, and two details
  are load-bearing. **Chip photos live in the same folder but are referenced from
  `poker_sessions.chip_set` and `payout_structures.chip_set`** (see
  `pk_clean_chip_set()`), not from `timer_layouts`, so a layouts-only reference
  set would have deleted every host's chip images; all three columns are
  consulted, and a new column holding one of these paths must be added there.
  **Matching is by basename**, because `json_encode()` escapes the slashes inside
  a stored layout (`\/uploads\/…`) and full-path matching therefore matches
  nothing. `activity_log` is not treated as a reference: it records an upload, it
  does not use the file. Files under 24 hours old are never touched so an edit in
  progress is safe, only the two folders are scanned with a basename pattern that
  cannot express a path, symlinks are skipped, and any database error aborts the
  run before a single unlink so a failed query can never read as "nothing is
  referenced, delete everything".

---

## [v0.2090] - 2026-08-15

### Added

- **Admins can create and edit site-wide timer layouts from the editor.** The
  server has always allowed it (`pk_lo_may_modify()` returns true for admins, and
  `save_layout` accepts `is_global` from them), and `_timer_beta_editor.php` has
  always injected `TBE_IS_ADMIN`, but no control was ever attached to it, so the
  capability was unreachable. Admins now get a **Site layout** button in the
  editor toolbar that shows the loaded layout's real scope, toggles it, and
  applies immediately on an already-saved layout. A site layout appears in every
  host's Load list marked "(site)". Non-admins do not see the button, and the
  server independently ignores an `is_global` they try to force.

### Fixed

- **An ordinary save silently stripped a layout's scope.** The editor sends only
  name, layout and id, but `save_layout` read a missing `is_global` /
  `league_id` as zero and wrote that back, so saving a site-wide layout demoted
  it to a personal one and saving a league layout detached it from its league.
  The league case is the damaging one: with `league_id` cleared,
  `pk_lo_may_modify()` falls back to the creator, so the league's other owners
  and managers quietly lose the ability to edit it. Both fields are now preserved
  on update unless the request explicitly carries them, so a client that knows
  nothing about scope can no longer strip it (`www/timer_beta_dl.php`).

---

## [v0.2089] - 2026-08-15

### Added

- **Timer BETA: the tournament display is now a layout engine, not a fixed
  screen.** The classic timer paints one hard-coded arrangement, so every league
  that wanted its own board was out of luck. A layout is now a JSON tree of rows,
  columns and cells stored in the new `timer_layouts` table, with a full visual
  editor at `/timer_beta_edit.php` (`www/timer_beta.{php,css,js}`,
  `www/timer_beta_edit.{php,css,js}`, `www/timer_beta_dl.php`,
  `www/_timer_beta_editor.php`). Cells hold live `<element>` placeholders, an
  uploaded image, a QR code, a chip legend or the final-table seat map; a layout
  carries multiple screens, each with a condition deciding when it takes over.
  The display page stays strictly read-only: it polls `get_state` and never
  posts, all authored text is written with `textContent`, and every style string
  passes a server-side allowlist. The design doc is `TIMER_BETA.md` in the repo
  root and the user-facing guide is the new `www/help-timer.php`. The classic
  timer is untouched and still the default; a game opts in by binding a layout.

- **Conditions, screens and per-cell variants.** A screen or a cell can carry an
  expression (`players.left <= 10 and running`, `blinds.big > 10000`), evaluated
  live on the viewing device. Screens are scanned in order and the first match
  shows, with the unconditioned catch-all last, so a Break or Final Table screen
  takes over on its own. A cell can also declare variants that only restyle it
  (the clock turning red when paused) rather than hiding it. Two or more screens
  can share a rotation (`cycle` dwell seconds) and the display cycles through
  whichever currently match. Expressions are validated through the renderer's own
  parser, so what the author sees in the editor and what the display computes can
  never disagree, and the same grammar is re-validated server-side on save.

- **Device classes, so one layout serves the TV and the phones that scan it.**
  `mobile`, `tablet` and `desktop` are conditions like any other, resolved per
  viewing device rather than per layout, which matters because the QR code means
  a wall display and a pocket phone run the same design. Cells, rows, columns and
  whole screens can each be gated on them: hiding a container releases its space
  and the siblings absorb it. The editor gained a TV / Tablet / Phone preview
  toggle that reshapes the preview frame and forces those conditions
  (`TBPreview.device`), so a phone-only screen can be built and checked from a
  desktop.

- **Triggers: sounds, takeovers, flashes and spoken lines.** A trigger fires once
  each time its condition becomes true (edge-fired, with optional cooldown or
  once-only), and can play a sound, flash the screen, switch to another screen
  for a few seconds, or speak a line through the browser. Sounds are first-class
  media: twelve built-in presets synthesised in the browser plus uploads of your
  own (`upload_sound`, stored under `uploads/timer_sounds/`), and exports embed
  the audio so a layout carries its sounds to another install. Screens opened by
  scanning the QR code start muted on purpose, since a room full of phones
  chiming on every level change is nobody's idea of a good night; a speaker
  button toggles it per device.

- **The final-table seat map.** A cell can render the remaining players at their
  assigned seats around a racetrack table, with avatars, names and seat numbers,
  auto-picking the busiest table or pinned to a specific one. Paired with a
  `players.left <= 10` screen condition it gives a real final-table view.
  Eliminations feed it: `timer_dl.php`'s `get_state` now reports
  `last_eliminated`, which drives a `playerEliminated` trigger and the
  `players.lastOut` / `players.lastOutPlace` elements, so the board can announce
  who just busted and in what place. An undo of an elimination stays silent.

- **Per-event Blind and Display setup pages.** Blind schedules and the display
  binding used to live inside the check-in console; they are now their own pages
  (`www/event_blinds.{php,js}`, `www/event_display.php`, swapped through
  `www/_event_setup_strip.php`) with a spreadsheet-style blind grid, undo/redo,
  fractional blinds, preset provenance and tablet sizing. The Display page
  embeds the layout editor directly and binds the result to that game.

### Changed

- **The element and condition vocabulary is namespaced.** Names were a flat pile
  (`smallBlind`, `bigBlind`, `blinds`, `playersLeft`) that could not be sorted or
  scanned, so everything moved to dotted families: `blinds.small`, `blinds.big`,
  `blinds.ante`, `players.left`, `players.entries`, `chips.avg`, `money.pot`,
  `clock.seconds` and so on. The pickers now group by family, the help page and
  design doc were rewritten against the new names, and the three help screenshots
  were retaken. The old flat spellings survive only as internal engine keys, and
  the Tournament Director alias layer that predated them is gone entirely.

- **The layout editor is a three-pane workbench.** Above 1280px the preview,
  Structure tree and Inspector each get their own column and their own scroll, so
  selecting a cell shows its settings beside the tree instead of below its scroll
  box. The toolbar pins under the site nav rather than sliding beneath it, the
  page width cap is lifted on wide monitors, and the default screen leads the tab
  strip even though the stored evaluation order still keeps the catch-all last.
  "+ Screen" opens a template gallery (Break, Final table, Phone, Game over,
  Blank) instead of always creating a Break screen, and the editor embedded in an
  event's Display settings boots on the layout that event is actually using.

- **A live game's display shows nothing but the game.** The corner bar carrying
  the BETA badge, the layout picker and the classic-timer link now renders only
  in sample mode. On a real game the layout comes from the game's Setup, so a
  board cast to a TV no longer offers a passer-by a dropdown.

### Fixed

- **Text no longer walks out of its box after a phone rotation.** iOS settles the
  viewport, and every `vh`-based font size with it, some hundreds of milliseconds
  *after* the resize event fires, so the engine's single next-frame re-fit
  measured stale geometry and kept the landscape sizing in portrait. The fit pass
  now reruns as the flip settles (next frame, 180ms, 500ms, plus
  `orientationchange`), and any capped cell still overflowing its box re-caps on
  the next engine tick. That second half matters because the previous re-fit only
  triggered when the text changed *shape*, which a ticking clock never does.

- **Declared text sizes are a maximum, not a promise.** A sized cell whose
  content outgrew its box (blinds at 2,000,000 / 4,000,000 by round 19) used to
  paint straight over the artwork; the inner element now shrinks to fit and grows
  back when the text shortens.

### Security

- **The layout document is treated as hostile input end to end.**
  `pk_layout_sanitize()` in `www/timer_beta_dl.php` rebuilds every saved layout
  from scratch against an allowlist rather than filtering the submitted one:
  style strings reject `url(`, `expression`, `javascript`, `@import`, quotes,
  semicolons and braces; image and sound references must match a closed
  same-origin path with no separator in the name; condition expressions must
  re-tokenise to exactly the submitted source under a whitelisted identifier set;
  trigger actions are whitelisted by key. QR targets are resolved by the renderer
  from the game's own key and are never author-controlled, so a shared layout
  cannot aim someone's scanner anywhere.
- **`SECURITY.md` documents six pre-push sweeps** (PHP parse errors, parse errors
  in PHP-*generated* JavaScript, browser suites, the double-dispatch sweep,
  known-bad escaping, and the declarative-markup check), each of which caught a
  real shipped defect. `cast_receiver.php` was deleted: it never worked, nothing
  could launch it, and it was live attack surface for a feature that did not
  exist.

---

## [v0.2088] - 2026-08-13

### Fixed

- **Week view: clicking an event opened `event.php?id=[object Object]`.** v0.2083
  fixed this for the calendar chips the server renders as markup, but the week
  view builds its timed chips in JavaScript, and those still handed the whole
  event object to `viewEvent()`, which concatenates its first argument straight
  into the URL. The same mistake sat in the auto-open path that runs when the
  calendar is asked to open an event directly. Both now pass the id and the
  occurrence date as scalars (`www/calendar.php`, `renderDayCol()` and the
  `$__autoEdit` branch). Month cells and week all-day chips also carried the
  event's `start_date` rather than the date of the cell they were drawn in, so a
  multi-day or recurring event sent you back to the wrong day; they now use the
  cell's own date. Removes a dead `allDayEvs` read in `renderDayCol()` too: all-day
  events are drawn in the server-rendered row above the grid, not in the column.

---

## [v0.2087] - 2026-08-12

### Changed
- **Uploads are namespaced by feature instead of one flat folder.** Every image used to land in a single `/uploads/` directory as an anonymous `bin2hex().ext` file, mixing avatars, post images, and ticket screenshots with no way to tell them apart, attribute them, or clean them up. `upload.php` now takes a whitelisted `feature` parameter (`avatars`, `posts`, `tickets`) — never free-form, so it cannot traverse — that routes uploads into `/uploads/<feature>/` and names them owner-keyed as `u<id>_<random>.ext`, so a file's purpose and owner are visible from its path. A request with no (or an unknown) feature keeps the historical flat `/uploads/<hash>` behaviour, so anything that does not opt in is unchanged. The avatar (`settings.php`), post-image (`admin_posts.php`, `league.php`), and ticket-screenshot (`support.php`, `support_ticket.php`) callers were repointed to their folders; the site banner keeps its own separate fixed-name handler.

### Security
- **Uploads now require a real, decodable image.** `upload.php` adds a `getimagesize()` check on top of the existing byte-level MIME sniff, so a file that merely reports an image MIME but does not decode as one is rejected.
- **Fixed three path validators that were pinned to the old flat upload shape.** `set_avatar` (settings.php), `clean_screenshot` (support_dl.php), and `avatar_html` rendering (db.php) each matched only `^/uploads/<32hex>.ext$` and would have silently rejected the new namespaced paths; they now accept both the legacy flat and the namespaced `/uploads/<feature>/u<id>_…` forms. `sanitize_html` already allowed relative image paths, so post images needed no change. Verified end to end on dev: avatar, post image, and ticket screenshot all upload, store, and render from their namespaced folders, with legacy flat paths still served.

## [v0.2086] - 2026-08-12

### Added
- **Keyboard shortcuts to run the tournament timer.** The timer already toggled start/stop on the spacebar; it now also skips levels with the arrow keys: Right advances to the next level, Left steps back to the previous one. All three are gated by a new shared `timerControlArmed()` (which the clock double-tap now also uses): the shortcut fires only when the user can control this timer and is not in the layout editor, and never while typing in an input, textarea, select or contenteditable. The spacebar handler previously fired regardless of control state or edit mode; it now respects the same guard. The server re-checks manage rights on every command either way, so this only affects which keypresses reach the wire.

## [v0.2085] - 2026-08-11

### Fixed
- **Opening a game timer you don't host printed raw JSON at the browser.** `timer.php` guarded the event path with `verify_event_access()`, which answers `{"ok":false,"error":"Access denied"}` with a 403. That is correct for the twenty-odd `*_dl.php` endpoints it was written for, and wrong for a page: `timer.php` was its only page-level caller, so the visitor got a bare JSON blob with no explanation and no way back. The page now checks the same authority via `check_event_access()` and renders `_event_denied.php`, modelled on the existing `_league_denied.php`, which says who can run a timer and offers a standalone timer or My Games as the way out. The 404-before-403 contract for a missing event is preserved and gets its own wording, so a deleted game is not reported as a permissions problem. Authorization is unchanged: same function, same 403, readable answer.
- **The timer's controls were unusable-sized on a tablet, and its own hint covered them.** The control tray applied its touch treatment behind a `@media (max-width: 768px)` query, but a tablet is a touch device that is *wider* than that, so an iPad got desktop sizing: `btnPlay` measured 52x40 against the 44px minimum every touch guideline asks for, and 15 controls were under it. Sizing now keys off `@media (pointer: coarse) and (min-width: 769px)`, which asks about the pointer rather than inferring it from width; the phone layout below 769px is deliberately untouched, since 13 controls at 44px would wrap to three rows on a 390px screen. Separately the wake-lock hint (`#wakeBanner`) was pinned to `bottom: 0` at `z-index: 999` and painted over the bottom 33px of the 57px tray, hiding every button label. It now sits above the tray, offset by a `--tray-h` custom property that `syncTrayHeight()` measures rather than assumes, because the tray wraps to a second row on narrow screens. Its styling moved from an inline `style` attribute into `timer.css`.
- **Theme-positioned elements could render off the edge of the screen.** A theme stores one point per element (`x%`, `y%`) and anchors the element's *centre* there, with no knowledge of how wide that element renders; text sized off the viewport height therefore spills past an edge the stored percentage never accounted for. On a 1080x810 tablet the QR block hung 10px below and 6px right of the screen, and the left-hand stat column ran off the left edge, truncating its labels to `break:`, `eentries:` and `Pool:`. `clampPositioned()` now keeps the rendered rectangle inside the viewport without touching stored theme data: the authored value is kept on the node as `data-pos-ax`/`data-pos-ay` and re-derived each time, so a correction never becomes the input to the next one. `#themeImage` and `#streamingWrap` are exempt, since bleeding off an edge is a legitimate look for the decorative layers, and the clamp stands down inside the layout editor so dragging stays free. This is a holding measure; step 3 replaces the point model with boxes.

### Infrastructure
- **Split `timer.php` into a page, a stylesheet and a script.** The file had grown to 6,418 lines holding roughly 1,190 of CSS and 4,280 of JavaScript across 214 functions, which made every timer change a large-diff, hard-to-review edit and is the main reason the previous layout-engine attempt was shelved rather than landed. The CSS block contained no PHP at all and moved verbatim to `www/timer.css`. The JavaScript contained PHP in only 25 places, all of them values rather than logic, so the `§7.1 Config from PHP` block stays in the page as a nonced inline script exporting the same globals (`IS_REMOTE`, `LEVELS`, `POOL`, `TIMER`, `SOUNDS` and so on) and the remaining 4,279 lines moved to `www/timer.js`. Two deep uses of the acting user's id now read a new `CURRENT_USER_ID` global instead of interpolating PHP mid-function. `timer.php` is 955 lines. Both assets carry the standard `?v=APP_VERSION.filemtime` cache-buster, and being external they are covered by `script-src 'self'` and need no nonce. Load order is unchanged: `timer.js` is not deferred and sits exactly where the inline block did, after the markup, so the DOM is parsed and the vendor scripts have run before it executes. `window.PK_DISPATCH_LOCAL` now gets set slightly earlier, in the config block, which is strictly safer. Verified by capturing the computed geometry and key styles of every visible element (position, size, font size, colour, display, position, z-index, background) across three viewports and both the standalone and event-linked timer, before and after: **135 element measurements, zero differences**. Suites: `timer_args` 954 control dispatches, `blinds_fit` 48, `clock_doubletap` 10, `timer_gestures` 10, `helpers_defined` 10, the double-dispatch sweep 557 controls and `csp_nonce` 29, all passing. This is step 1 of the timer redesign and deliberately changes nothing a user can see.

## [v0.2084] - 2026-08-10

### Fixed
- **Edit did nothing on Manage Posts, and there was no way to view a post.** Clicking Edit navigated to `/admin_posts.php?edit=N` and left the page looking inert. The cause was a load-order race introduced when Jodit moved to a local `defer`red script: `editor` is created inside a `DOMContentLoaded` listener, but the `?edit=N` deep link called `openModal()` inline at parse time, so `editor` was still `null`. `openModal()` threw on `editor.value` and aborted three lines before `postModal.classList.add('open')` — no modal, and the only symptom was one console error nobody was looking at. The auto-open call now runs in its own `DOMContentLoaded` listener; listeners fire in registration order and the editor init registers first, so the editor is guaranteed to exist. Checked the other three pages that load Jodit deferred: `calendar.php` and `league.php` have no parse-time call, and `event.php` builds its editor lazily in `_emEnsureEditor()`, so none share the defect.
- **Added a View action to Manage Posts.** The actions column offered only Edit, Pin and Delete, so there was no route from the admin table to the post itself. Each row now links to the post's feed anchor (`/#post-<id>`). Hidden posts get a greyed, non-clickable placeholder with an explanatory tooltip instead of a dead link, because `posts_feed_sql_for_user()` filters `p.hidden = 0` unconditionally — a hidden post is absent from the feed for everyone, admins included. Both states verified against fixtures.

## [v0.2083] - 2026-08-10

### Fixed
- **Every event chip on the calendar navigated to `/event.php?id=undefined`.** The handler conversion tagged the month-grid and week all-day chips with `data-act="viewEvent" data-a1="<?= htmlspecialchars(json_encode($ev)) ?>"`, following the escaping rule documented at the time. That rule was written for attributes carrying JS source, where `json_encode()` correctly produces a literal. `pk-dispatch.js` reads a `data-a*` value and passes it through as a **string** with no parse step, so `viewEvent(ev)` received JSON text and `ev.id` was `undefined`. Both call sites in `calendar.php` now pass the two scalars the handler actually uses (`data-a1` the integer id, `data-a2` the start date) and `viewEvent(id, date)` takes them directly. Verified in both the month and week views against real fixtures. The 146-line `viewEventLegacy()` beside it was removed: it had been unreachable since `event.php` replaced the in-page modal, and it was the only writer of `currentEvent`, which was therefore already permanently `null` — the removal is behaviour-neutral and recoverable from the v0.2082 tree.

### Infrastructure
- **Added a static pre-push check for conversion defects in declarative markup.** Retyping 401 inline handlers as `data-*` attributes produced a class of defect that neither `php -l` nor a rendered page can see: a stray duplicated closing quote, a PHP tag that got HTML-escaped, a JS concatenation emitted verbatim into markup, and an object passed where the dispatcher delivers a string. One `grep` catches all four. Measured against the tree as it shipped before v0.2082 it prints 20 hits across 11 files — the entire markup half of that batch — and nothing on a clean tree. It is now sweep 3 in `SECURITY.md`, alongside a warning to anchor any ad-hoc handler grep with `[[:space:]]`, since a bare `on[a-z]+=` matches `action=` and `position=` and reported 130 handlers on a tree that has zero.
- **Corrected the documentation that caused the calendar bug, and the parts that had gone stale.** `CLAUDE.md` and `SECURITY.md` both gave `htmlspecialchars(json_encode($v), ENT_QUOTES)` as *the* correct attribute form; that is now split into attribute-as-code (historical, none remain) and attribute-as-data (current), with the `ENT_SUBSTITUTE` requirement stated. `SECURITY.md` also still claimed `checkin.php` had 165 inline handlers, that removing `script-src-attr 'unsafe-inline'` would break the application, and that injected `on*` attributes still execute — all untrue since v0.2079, and directly contradicted by that section's own `CLOSED` heading. The double-dispatch sweep's admin-session requirement and its `data-confirm` counting are documented alongside it.

## [v0.2082] - 2026-08-10

### Fixed
- **Cleaned up the defects the inline-handler conversion left behind.** Converting 401 inline `on*` attributes to declarative `data-act` markup across v0.2074-0.2079 broke a handful of controls in ways that were invisible to a lint pass, and the second security review surfaced them. `index.php` kept its own document-level `submit` listener for `data-confirm` while also receiving the shared `pk-dispatch.js`, so deleting a post or comment raised two stacked confirm dialogs — the same double-dispatch class that v0.2078 fixed for `checkin.php`; the duplicate listener is gone and the shared dispatcher owns the behaviour. The Start button on `messages.php` navigated to `message_thread.php?with=` while that page reads `?user=`. `data-href` in `pk-dispatch.js` ignored `data-stop`, so the Message button inside a contacts row navigated to the row's edit page instead; it now honours the boundary exactly as `data-act` does. `event_polls.php` had a PHP tag HTML-escaped into a confirm message and a JS concatenation emitted literally into the + Option button, `leagues.php` passed a `json_encode()` result into an attribute so the modal showed quoted text, and `timer.php` handed `loadPresetTheme`/`deletePresetTheme` a JSON-quoted preset key. Sixteen attributes across ten files carried a stray duplicated closing quote such as `data-confirm-danger="1""`.
- **A confirmation dialog could silently disappear on invalid UTF-8.** The converted `data-confirm` attributes build their text with `htmlspecialchars(..., ENT_QUOTES)`, which returns an empty string when the input contains an invalid UTF-8 byte — so an event or username carrying one produced an empty `data-confirm` and the delete confirmation just did not appear. All such call sites now pass `ENT_QUOTES | ENT_SUBSTITUTE`. This is a client-side guard only; the underlying POST remains CSRF-protected and server-authorised either way.

### Infrastructure
- **The double-dispatch sweep now covers the generic behaviours and checks its own coverage.** `double_dispatch_sweep.js` only counted named `data-act*` functions, which is why it passed a release with the `index.php` double-confirm in it — `data-confirm` names no function, so there was nothing to count. It now stubs `pkConfirmForm()` and asserts exactly one call per `data-confirm` form and button, and flags any `data-*` attribute still containing an unresolved `<?= ?>` or a stray JS concatenation. Separately, the sweep had been running as a non-admin the whole time, so every admin page returned 403 and reported zero controls; it now fails loudly if the admin pages are unreachable and prints the promote/demote commands. Real coverage went from 262 to 558 controls.

## [v0.2081] - 2026-08-10

### Security
- **The event-authorization fix from v0.2070 was being undone on every request.** That release moved event visibility and co-host authority off the free-text `event_invites.username` and onto a new `user_id` column, and added a backfill to populate it from the existing username match. The backfill was written as one-time but was not guarded, and `db_init()` runs on **every** request, so it kept re-materialising the very link that had just been removed from `can_manage_event()` and `event_visibility_sql()`. An attacker who registered a username matching an unlinked invite had their account id written into that row on their next page load, inheriting the invite's private-event visibility and, on a co-host row, full host control. Verified end to end before and after the fix. The backfill is now one-shot behind a `site_settings` flag, matching the `api_keys` prune pattern already in `db_init()`. Because it was load-bearing — several insert paths never populated `user_id` at all and relied on it — all eight `INSERT INTO event_invites` sites now resolve the link at write time via a new `resolve_invite_user_id()` helper, or use the acting user's id for self-signup. That is the correct semantics: linking is the host's decision about someone who already has an account, never something a stranger can trigger later by claiming a name. Production audit at the time of the fix: 178 unlinked invite rows, 62 with claimable usernames, **zero co-host**, so the reachable impact was private-event disclosure rather than host takeover; and of the 25 links the unguarded backfill had created retroactively, all were ordinary invitees registering hours after their invite, with no sign of exploitation.

## [v0.2080] - 2026-08-10

### Fixed
- **The rich-text editor stopped making two blocked cross-origin requests on every page that carries one.** Jodit fetches `js-beautify` and ACE from cdnjs when it initialises, not on demand, and `script-src 'self'` has refused both since long before the CSP work — so the source-view highlighting and HTML beautifying were never actually functioning here, they were just failing noisily. `beautifyHTML: false` and `sourceEditor: 'area'` are now set on all four editors (`admin_posts.php`, `calendar.php`, `event.php`, `league.php`), which stops the requests and the console errors. Source view still opens, as a plain textarea without syntax highlighting. Hosting both libraries locally would restore those features and remains an option; disabling them was chosen because the app has run without them for the life of the CSP and neither is load-bearing.

## [v0.2079] - 2026-08-10

### Security
- **CSP now blocks injected script outright, closing the gap the v0.2070 review opened with.** `script-src` and `script-src-elem` carry the per-request nonce and `script-src-attr` is `'none'`, so an injected `<script>` is refused because it cannot carry the nonce, and an injected `on*` attribute is refused because attribute handlers are disallowed. Until now the policy allowed `'unsafe-inline'`, which meant the browser could not tell the app's own inline code from an attacker's and every XSS finding in that review would have executed unimpeded. The nonce is carried on `script-src` as well as `script-src-elem` deliberately: browsers without CSP3 granularity fall back to `script-src`, and a nonce there makes `'unsafe-inline'` ignored, so they get the same guarantee rather than none. This was only possible because all 401 inline handlers became delegated listeners over v0.2073-v0.2078. Pre-flight before switching: a DOM scan across 36 pages found zero `on*` attributes in rendered markup, including JS-built, and zero unnonced inline `<script>` blocks. After switching: an injected handler and an injected nonce-less script are both confirmed blocked, every nonced inline block still runs, and 431 controls across 37 pages still fire exactly once. Anything added from here must use the `data-act*` pattern in `SECURITY.md`, because an inline handler will now simply not fire.

## [v0.2078] - 2026-08-10

### Fixed
- **Every control in the check-in console fired twice, so nothing that toggles appeared to work.** v0.2077 added the shared `www/pk-dispatch.js` to `_footer.php`, and `checkin.php` both includes that footer and installs its own delegated dispatcher, so each `data-act*` control was dispatched by both. The damage was invisible on anything idempotent and total on anything that toggles: tapping a player card ran `classList.toggle('open')` twice so it opened and shut again and never expanded, and ticking buy-in set it on and then straight back off. Reported from an iPhone against the live site. `checkin.php` and `timer.php` now declare `window.PK_DISPATCH_LOCAL = 1` and the shared dispatcher returns early when it sees it. `timer.php` has no footer so it was never double-dispatching, but it is flagged for the same reason. Caught by the existing dispatch sweep reporting `calls: 2` on 289 of 294 controls, which is the check that exists precisely because a mis-wired control is silent.

### Security
- **The last 68 inline event handlers are gone; the tree is at zero.** `admin_settings.php` (15), `league.php` (11), `admin_settings_dl.php` (8), `calendar.php` (7) and thirteen other files converted, completing step 2 of the CSP work in `SECURITY.md`. These were the bespoke cases: multi-statement bodies, PHP-interpolated confirmation messages and DOM mutations, each replaced by a named helper defined beside the function it calls. New shared behaviours cover the repeated shapes: `data-set-value="inputId:value"`, `data-remove-closest="selector"` with an optional `data-then`, and `data-confirm` on a button that submits the form it belongs to. One `onmouseover`/`onmouseout` pair became a CSS `:hover` rule, where it belonged all along. Verified with a before/after snapshot of every interactive element across 19 pages: none lost interactivity, and every new helper resolves on the page that uses it. With the count at zero, `script-src-attr 'unsafe-inline'` can now be dropped, which is the remaining step.

## [v0.2077] - 2026-08-09

### Security
- **Shared handler dispatch, and 169 more inline handlers removed across 24 files.** New `www/pk-dispatch.js`, loaded from `_footer.php`, provides the same delegated dispatch that `checkin.php` and `timer.php` carry internally, so ordinary pages no longer need their own copy. It is an external file, so it is covered by `script-src 'self'` and needs no nonce, and it is cache-busted because it carries behaviour rather than styling — a stale copy of a behaviour file is a broken feature, as v0.2073 demonstrated. It also provides generic declarative behaviours for the idioms that recurred dozens of times: `data-confirm` / `data-confirm-ok` / `data-confirm-danger` on a form, `data-href`, `data-toggle-class="id:class"`, `data-click-file="inputId"`, `data-select-all-on-focus` and `data-uppercase`. Listeners run in the capture phase throughout, because an ancestor calling `stopPropagation()` makes a bubble-phase listener on `document` blind to everything beneath it. `walkin_display.php` and `register.php` load the script directly, having no footer. Tree-wide the count is 401 down to 68; the remainder are multi-statement or PHP-interpolated bodies in admin and editor screens, each needing an individually written helper, and `script-src-attr 'unsafe-inline'` stays until they are done.

## [v0.2076] - 2026-08-09

### Security
- **The tournament timer has no inline event handlers left.** All 157 `on*` attributes in `www/timer.php` are gone, replaced by `data-act*` attributes dispatched from delegated listeners, matching the idiom `checkin.php` uses. Arguments are namespaced per event from the outset (`data-keydown-a1` and so on) so the duplicate-attribute collision fixed in v0.2075 cannot recur, and element- or event-derived values use `@value`, `@checked`, `@checked01`, `@event` and `@self` tokens resolved at call time. Former inline expressions became named functions: `savePresetOnEnter`, `saveThemeOnEnter`, `objectsRowEye`, `objectsRowUp`, `objectsRowDown`, `toggleElementAndRefresh`, `flipClockRadialDirection`, `pageGradientAngle`, `streamUrlChanged`, `streamUrlCleared`, `clickFileInput`, `clockRadialSegments`, `clockRadialThickness`. Tree-wide the count is 401 down to 244, with the remaining handlers spread across smaller pages. This is step 2 of the CSP work in `SECURITY.md`; the nonce from v0.2072 already blocks injected `<script>` elements, and dropping `script-src-attr 'unsafe-inline'` becomes possible once the count reaches zero.
- **Timer delegation runs in the capture phase.** The control tray stops click propagation so that clicking it does not trigger the close-on-outside-click handler, which left a bubble-phase listener on `document` unable to see the play, sound and level buttons at all. Inline handlers were unaffected because they run at the target; capturing restores that ordering without altering tray behaviour, and guards against any other ancestor doing the same. Verified against a before/after snapshot of every handler binding in the live DOM: 167 bindings before, 167 after, none lost or gained, plus 924 dispatch checks across seven panels and nine event types confirming each control receives exactly the arguments its attributes describe.

## [v0.2075] - 2026-08-09

### Security
- **The check-in console has no inline event handlers left.** All 157 `on*` attributes in `www/checkin.php` are gone, replaced by `data-act*` attributes dispatched from delegated listeners on `document`. Delegation is required rather than tidier: nearly everything in this console is re-rendered from JS on each poll, so per-element binding would be lost on every repaint. Arguments ride in `data-a*` attributes and are coerced back to their original types, since the inline form passed `toggleBuyin(12)` as a number; element- and event-derived values use `@value`, `@checked`, `@checked01`, `@prevValue`, `@dataU` and `@event` tokens resolved at call time. Former inline lambdas became named functions (`finishGameConfirm`, `reopenGameConfirm`, `cashInOnEnter`, `cashOutOnEnter`, `removePayoutRow`, `goBackOrHome` and others). Tree-wide the count is 579 down to 399; `timer.php` (154) is the remaining file. The dispatcher warns to the console when a name does not resolve, because a dead control is otherwise silent.

### Fixed
- **Enter no longer failed when adding a walk-in, and the same fault affected cash-in fields.** Argument attributes shared one `data-a1..a4` namespace, so an element carrying two handlers emitted a duplicate attribute; HTML keeps the first, so the second handler received the wrong arguments. The walk-in box carries both `input` and `keydown`, so `walkinKeydown` was handed the input's text instead of the event and `e.key` was undefined. The cash-in field had the identical collision, giving `cashInOnEnter` `(pid, value)` where it expects `(event, pid)`. Argument attributes are now namespaced per event (`data-keydown-a1`, `data-change-a1`, and so on), with click keeping the bare form. Verified by typing a name and pressing Enter, and on a cash game for both the cash-in and cash-out fields; a collision guard now fails the test suite outright if any element reuses an argument slot.

## [v0.2074] - 2026-08-09

### Fixed
- **The nav stopped responding after v0.2073 for anyone with a cached `nav.js`.** v0.2073 moved the nav's click handling out of inline `onclick` attributes and into `nav.js`, but that was the one local script tag in the tree served without a cache-buster, so browsers kept the previous copy. The markup emitted `data-nav` attributes that the stale file knew nothing about and every nav control went dead: account menu, hamburger, Help toggle and the collapse arrow. `_nav.php` now versions it as `?v=APP_VERSION.mtime`, the same form `pk-seg.js`, `pk-dialogs.js` and `help-bubble.js` already used. `_phone_input.js` had the identical gap across 9 pages and is versioned too; no local script tag is left unbusted. This is the same failure mode as the stale-stylesheet fix in v0.2062: moving behaviour into an asset makes a stale copy a broken feature rather than merely an out-of-date one.

## [v0.2073] - 2026-08-09

### Security
- **Inline event handlers removed from the nav and the post card, the first step toward dropping `script-src-attr 'unsafe-inline'`.** Inline `on*` attributes cannot be authorized by a CSP nonce, so they have to go before that directive can be tightened. `_nav.php` (7 handlers) and `_post_card.php` (9) are now at zero: controls carry `data-nav` / `data-pc` attributes and are dispatched by delegated listeners on `document`. Nav dispatch lives in the existing external `nav.js`, which needs no nonce since it is covered by `'self'`; post-card dispatch sits in `index.php` beside the functions it calls. Delegation rather than per-element binding is a requirement here, not a preference: `posts_chunk.php` injects further cards by AJAX after load. Form confirmations move to a reusable `data-confirm` / `data-confirm-ok` / `data-confirm-danger` trio handled by one `submit` listener that calls the existing `pkConfirmForm()`. Tree-wide count is 579 down to 564; `checkin.php` (165) and `timer.php` (154) remain, and the established pattern is written up in `SECURITY.md`.

## [v0.2072] - 2026-08-09

### Security
- **Inline scripts now carry a per-request CSP nonce, so an injected `<script>` is refused by the browser.** `auth.php` mints `CSP_NONCE` once per request and `csp_nonce()` stamps it on all 64 inline blocks across 33 files. External `<script src="/...">` needs no nonce, it is covered by `'self'`; `cast_receiver.php` is deliberately excluded, being standalone with no CSP header of its own. The header now carries three script directives rather than one, and the split is the whole trick: adding a nonce to `script-src` makes a browser ignore `'unsafe-inline'` **including for `on*` attributes**, which would break every button in the app, so `script-src-elem` takes the nonce while `script-src-attr` keeps `'unsafe-inline'` until the inline handlers are converted, and a plain `script-src` remains as the fallback for browsers without CSP3 granularity (behaviour there is unchanged). Scope, stated plainly: this blocks injected script **elements**, not injected `on*` **attributes**, so it narrows the gap rather than closing it. Converting the remaining 579 inline handlers to `addEventListener` is the next step, tracked in `SECURITY.md`. Verified across 20 pages with zero CSP violations, inline scripts confirmed executing (not merely present), inline handlers confirmed still firing, and the nonce confirmed to change per request.

## [v0.2071] - 2026-08-09

### Security
- **Timer theme values are escaped and validated.** The layout inspector interpolated theme strings straight into HTML attributes, and theme properties were stored as whatever JSON was posted. Two layers now: a new `escAttr()` in `www/timer.php` covers all 21 interpolations across the element, page and tray inspectors, plus the theme picker's name; and `pk_theme_sanitize_props()` in `www/timer_dl.php` runs on every write path, requiring colour-named fields to be a hex value, an `rgb()`/`rgba()` expression or a CSS keyword, and stripping angle brackets and quotes from every stored string. Note the existing `escHtml()` handles `<`, `>` and `&` but not quotes, so it was never suitable for attribute context; that is what `escAttr()` is for, and new attribute interpolation should use it. Legitimate themes are unaffected: 3- and 6-digit hex, colour keywords, `rgba()`, numeric scales, stream URLs and nested gradient objects all round-trip unchanged.

## [v0.2070] - 2026-08-09

### Security
- **Event authorization now hangs off an account id rather than a username string.** `event_invites` gains a `user_id` column, backfilled once from the existing username match, and both `can_manage_event()` and `event_visibility_sql()` join on it. An invite row that does not correspond to a real account no longer confers visibility or co-host authority. Verified access-preserving before shipping: every existing (user, event, role) pair was compared under the old and new logic, with none lost and none gained. Note the deliberate behaviour change: an invite typed as a plain name no longer starts granting access if someone later registers under that name, so a host who wants a real co-host adds them once they have an account.
- **Hardened HTML sanitization.** `sanitize_html()` did not descend into elements it was about to unwrap, so their contents were emitted without the tag allowlist, attribute stripping or URL scheme checks being applied. It now sanitizes those subtrees before unwrapping. Allowed formatting, lists, links and whitelisted embeds are unaffected.
- **Corrected escaping in admin confirmation dialogs.** Five `onsubmit` handlers across `user_edit.php`, `admin_settings.php` and `admin_settings_dl.php` interpolated a username or event title into an HTML attribute using `json_encode()` alone or `addslashes(htmlspecialchars(...))`, neither of which is correct for that context. All now use the `htmlspecialchars(json_encode($v), ENT_QUOTES)` form already used elsewhere. This also fixes a live bug: the delete confirmation was silently broken for any event whose title contains an apostrophe, so the prompt never appeared.
- **Username format is enforced on every path that sets one.** The rule lives in one `username_format_error()` helper in `auth.php`; the self-service profile editor in `settings.php` previously checked only that the value was non-empty, while registration and the API enforced the documented charset.
- **Timer themes and blind presets are scoped to their owner.** `load_theme` and `load_preset` now apply the same visibility filter `get_theme` and `get_presets` already used, and `update_theme` and `update_levels` copy-on-edit when the target belongs to someone else, matching the ownership rule their delete counterparts already enforced.
- **Joining a league requires a confirmed POST.** `join_league.php` performed the membership insert on a bare GET with no CSRF token, so a single top-level navigation could add someone to a league without their agreement. The link now renders a confirmation page naming the league and stating that its owner will be able to see the member's email address and phone number; the write happens on POST with a verified token, the same split `rsvp.php`, `verify_email.php` and `poll.php` already use.

## [v0.2069] - 2026-08-08

### Fixed
- **The Next line was missing entirely on an iPhone in landscape.** `.timer-display` centres its children and clips (`overflow: hidden`), so anything that does not fit is silently cut off the ends, and the Next line, being last, is what disappears. iOS Safari in landscape leaves less usable height than the CSS assumed and nothing in the layout noticed. New `fitDisplayHeight()` in `www/timer.php` measures the stack against the height that actually exists and shrinks it to fit, writing `--timer-vfit` into all 22 display font-size declarations across the base rules, the three responsive blocks and `display-mode`. Two measurement details matter: it sums the in-flow children rather than reading `scrollHeight`, because centred content with hidden overflow reports no overflow however far it spills; and it clamps against `visualViewport.height`, because `clientHeight` is only as honest as the `100dvh` behind it, and if that over-reports the box measures fine while its bottom sits under the browser chrome. Re-fits on render, resize, fullscreen change, `orientationchange` (delayed, since iOS reports the new viewport late) and `visualViewport` resize. Positioned children are skipped: a theme pulls those out to `position: fixed`, so they neither contribute to the stack nor should shrink with it.
- **Blinds wrapped onto a second line at deep levels and shoved the clock down the screen.** `20,000 / 40,000 / 5,000` did not fit, and dropping the `2K` abbreviation in v0.2067 made it reachable sooner. `fitLine()` shrinks the line to fit instead, via a `--*-fit` factor that multiplies with the theme's `--*-scale` rather than replacing it, so a themed timer keeps the size its theme asked for until the text genuinely will not fit. Applied to the blinds, the Next line and the PAUSED label, and to both headline lines on `cast_receiver.php`, which is more exposed still: fixed `8rem`/`3rem` sizes with no `clamp()`. Measurement is against the parent's content box using an inline-block inner span, because the line div is a flex item that stretches to its own content (so its own `clientWidth` can never report the overflow) and centred text defeats `scrollWidth` entirely.
- **Decorative text was swallowing taps meant for the clock.** The level label, blinds, ante, PAUSED and Next line are `pointer-events: none` outside the layout editor. A theme can position any of them, and PAUSED sits across the clock by default, which blocked the new double-tap gesture. The editor restores pointer events, where they have to be draggable.
- **Long tournament names overflowed the info bar** on a phone. `.timer-event-name` truncates with an ellipsis rather than shrinking: a 54-character name scaled to fit a 358px bar lands near 10px and stops being readable, and this is identity rather than something read at a glance. `min-width: 0` is required for a flex item to shrink below its content width at all, without which the ellipsis never engages.
- **The cast clock overflowed narrow viewports.** A flat `18rem` ran 141px past the edge at 820px wide; now `min(18rem, 20vw)`, which keeps the intended size on a 1280x720 cast target.
- **Safe-area insets were only honoured by the control tray.** `viewport-fit=cover` is set, so on an iPhone the layout runs under the home indicator and, in landscape, under the notch. `.timer-container` now applies `env(safe-area-inset-*)`, restated in each of the three rules that set its padding with the shorthand, since a shorthand resets them — including the `max-height: 500px` block, which is exactly the iPhone-landscape case.

### Added
- **Double-tap the clock to start or pause.** The tray button stays the primary control; this is for a host with a tablet propped up at the table, where hitting a small button is the awkward part. Touch gets its own detector rather than relying on `dblclick`, which is unreliable across mobile browsers and, where it fires, can arrive after the synthetic-click delay and read as lag. `touch-action: manipulation` stops the browser claiming the gesture for double-tap-to-zoom. Guarded on `CAN_CONTROL`, so a remote viewer can never control the game, and on layout-edit mode, where the clock is a draggable object rather than a button.
- **Add to Home Screen support on iPhone.** iPhone Safari has no Fullscreen API (iPad does, which is why the Full button is already hidden on iPhone), so a chrome-free display there means adding the timer to the home screen. `apple-mobile-web-app-capable`, `apple-mobile-web-app-status-bar-style: black-translucent` and a title make it launch chrome-free and edge-to-edge instead of in a plain Safari window.

## [v0.2068] - 2026-08-08

### Changed
- **The cast display's blinds match the timer's.** v0.2067 stopped abbreviating blinds in `www/timer.php`, but `www/cast_receiver.php` carries its own `fmtChips()` and kept rendering `2K` and `2.5K` — on the screen furthest from the reader, where the abbreviation is least defensible. Its copy now formats identically: the whole amount with `en-US` thousand separators, two decimals preserved for fractional home stakes. Those are the only two chip formatters in the tree; a grep for the abbreviating pattern across `www/` returns nothing else. Worth remembering that the timer has two display surfaces and a change to one is not a change to both.

## [v0.2067] - 2026-08-08

### Changed
- **Blinds show the whole amount with thousand separators, not an abbreviation.** The timer rendered 2000 as `2K` and 2500 as `2.5K`, and at a glance across a room `2.5K` and `25K` differ by a single glyph, which is a bad trade for the width it saved on the one number everyone at the table is reading. `fmtChips()` in `www/timer.php` now returns `2,000` and `2,500`, and `100,000` once the levels get deep. The grouping is pinned to `en-US` rather than the visitor's locale: a timer on a TV is a shared display, and a browser set to a European locale would render `2.000`, which reads as two at that font size. Fractional blinds for home stakes keep their two decimals with no float dust, the behaviour added in v0.2035, and values under a thousand are unchanged. The function is used only for the current and next level's blinds and antes, so nothing else in the timer moved. Note the standalone tournament-timer fork carries its own copy of this function and does not inherit the change.

## [v0.2066] - 2026-08-08

### Fixed
- **The timer was a white page on every request.** `www/timer.php` had a fatal parse error, so Apache returned a 500 with an empty body for every hit, including from the check-in console's Timer button. The cause was the v0.2064 stylesheet cache-buster sweep: it rewrote the `style.css` link on all 54 pages that carry one, and in `timer.php` one of those links lives inside a PHP string rather than in template output, in the invalid-remote-link branch at line 91. A short-echo tag is not evaluated inside a string literal, and its quotes terminated the string, which made the whole file unparseable and took down every code path in it, not just that branch. The markup is now concatenated. Note this shipped in v0.2064 and was live from that deploy until now; the file has been unparseable that whole time, which is why the timer failed rather than degraded. A `php -l` sweep across `www/*.php` catches this class of breakage in seconds and is worth running before any push that touches many files at once; the other 53 pages were checked this way and are clean.

## [v0.2065] - 2026-08-08

### Fixed
- **A walk-in you had just added could be missing from the player list.** With a player filter active, adding someone and then switching to List showed no sign of them, and only a page refresh brought them back — because the refresh reset the filter, not because the data was wrong. Two separate causes. `add_walkin` in `checkin_dl.php` responded with a bare `SELECT * FROM poker_players` row, which carries no `event_invites` join, so the client's copy had `rsvp` NULL and no `approval_status` and the RSVP-Yes filter excluded a player the host had just marked yes; it now returns `get_players()` output as `players` plus the new `player_id`, the same shape `approve_player` and every other roster mutation uses, and `addWalkin()` assigns the roster wholesale instead of splicing a single row in. Separately, a brand-new walk-in has no buy-in, so the Playing filter legitimately excluded them with nothing on screen to say so; adding someone is an explicit request to see them, so on a successful add the filter now yields to `all`, the filter bar's active segment and thumb are updated, and the toast says so. The RSVP-Yes filter is deliberately left alone, since a walk-in genuinely passes it now.
- **A walk-in's RSVP never reached the roster row.** `add_walkin` set `rsvp = 'yes'` on the `event_invites` row but left `poker_players.rsvp` NULL, and the roster row is what the console renders. The RSVP column read blank for up to ten seconds and any RSVP-based filtering was wrong in that window, until the next `get_session` ran `sync_invitees` and quietly corrected it. The add now writes both.
- **The Game Preset buttons were tiny on tablets.** Load, Save As…, Delete and Set Default carried no class at all, so they rendered at the browser default and never picked up the touch sizing every other control in the check-in console gets below 1024px. They now share a `.pk-preset-bar` style and reach a 44px target on tablet against 30px on desktop, alongside the existing `.pk-toolbar button` and `.pk-actions button` rules. The preset select gets a matching `min-height` so the row lines up; its padding and font-size are inline and cannot be outranked from the stylesheet, but `min-height` is untouched there.

### Changed
- **Bonus Reward chips are filled green when switched on**, matching the Finish button and the mobile primary action. They were tinted blue, the app's information colour, which made an armed bounty or points scheme read as a hint *about* bounties rather than a switch that is on. Filled-versus-outlined also separates on from off at a glance better than two shades of one colour did.
- **The player-list filter predicate lives in one place.** `renderPlayerRows()` and `renderMobileCards()` held byte-identical copies and `addWalkin()` needed the same answer to know whether the person it just added would be filtered out, so all three now call `passesFilter()`. `renderTableView()` deliberately keeps its own copy: the seating chart has never branched on cash versus tournament for "playing", and changing that would be a behaviour change rather than a consolidation.

## [v0.2064] - 2026-08-08

### Added
- **The event's league can be set from Game Setup.** Jackpot funds and saved payout presets both belong to a league, so configuring a jackpot on an event with no league hit "Jackpots are league funds — this event must belong to a league first" and the only way forward was to leave Setup, edit the event, and come back. A League field now sits left of Game Type and saves with everything else. Server-side it is applied *before* the jackpot validation, so a league and a jackpot can be set in a single save. Authorization mirrors the event editor exactly: binding an event to a league takes owner or manager of **that** league, or site admin — membership alone is not enough. The event's current league is always shown, marked, even to a co-host with no role on it. A game that has already banked or paid jackpot money cannot change league: that money is held in one league's fund, and moving the event would leave the two apart.

### Changed
- **Setup's tabs are the segmented control with the sliding thumb**, matching the toolbar's view and filter strips, and the pane slides in from the side the thumb travelled. An underlined-tab strip was a third idiom for the same gesture. Disabled tabs (a cash game has no payout structure) are dimmed and do not light up on hover, and the thumb is re-measured whenever a segment is shown, hidden, enabled or disabled — the widths move even when the selection does not.
- **The segmented control is now shared, not page-local.** Styles moved to `style.css` and behaviour to a new `pk-seg.js` that `_footer.php` loads on every page, so any page can use the pattern with no per-page wiring. `positionAllSegThumbs()` scans every `.pk-seg[id]` rather than three hardcoded ids, and the `resize` re-measure moved into the shared file. `pk-seg.js` is deliberately not deferred: a page may put its own inline `<script>` after the footer, as `checkin.php` does, and must see the functions already defined. Documented in CLAUDE.md as the preferred switcher for future work, with the rules that keep it from breaking.
- **The "this game isn't set up yet" prompt is amber, not blue.** In the informational palette it read as a tip and disappeared into the page; it now uses the app's warning colours with a heavier left bar and a warning glyph, matching the ticket-target warning and the seat cutoff divider.

### Fixed
- **The Game Preset bar was unreachable on a cash game.** It was rendered only when the *saved* game type was tournament, and `previewGameType()` did not touch it — so choosing Tournament in the dropdown revealed the tournament fields and the Payouts tab but not the presets, and they could not be reached until the game type had been saved and the editor reopened. Same defect the Payouts tab had in v0.2060. The section is now always rendered and hidden by game type, and the preset list is populated for both types so a revealed select is not empty.
- **Loading a preset threw away an unsaved game-type choice.** `loadPayoutStructure()` redraws through `renderDashboard()` and `refreshSettingsView()`, both of which rebuild the pane from the saved session — so picking Tournament on a game still saved as cash and then loading a preset snapped the dropdown back to Cash and hid everything that choice had revealed, including the preset bar just used. The pending choice is now captured before the redraw and re-applied after.
- **Stylesheet edits between releases served stale CSS.** `style.css` was cache-busted on `APP_VERSION` alone, so any change to it between version bumps kept browsers on their cached copy. It now carries the file's mtime as well, the same guard `pk-dialogs.js` already used, across all 54 pages that link it. This surfaced when shared rules moved out of a page's inline `<style>` into `style.css`: the rules were gone from the page and not yet in the cached stylesheet, so the segmented control lost its styling and animation entirely.

---

## [v0.2063] - 2026-08-08

### Fixed
- **The jackpot screen showed nothing of the money collected for the current game.** Jackpot entries were contributed to the league fund only when a game was *finished*, so mid-game the modal read `Fund: $0.00` with a table full of paid-up entries behind it. It now shows what this game has taken and what the fund becomes: `+ $7.00 collected this game (7 entries × $1.00) — added to the fund when the game is finished, total after finish $7.00`. The line is absent once that money is banked, so nothing is ever counted twice; `jackpots.contributed` rides along with the balance in the session load, the jackpot log and the hit response so the client can tell the difference.
- **A jackpot could not be paid out mid-game.** `record_jackpot_hit` capped the payout at the banked fund balance, which excluded everything the current game had collected — so a bad beat, which by its nature happens at the table mid-game, was refused with "Payout $X exceeds the fund ($0.00)" and could not be settled until the game was finished. New `pk_jackpot_sync_contribution()` banks a session's collected entries on demand, and the hit handler calls it before the balance check.
- The contribution is computed as the **delta** between what a session has collected and what it has already contributed, so it is safe to call repeatedly: an early bank at hit time followed by a finish tops up only the entries that arrived in between, and a re-finish adds nothing. `pk_finish_session()` now delegates to the same helper instead of carrying its own dupe-guarded copy, so both paths share one rule. The sync only ever moves upward — if entries are withdrawn after a hit has drawn on them, that money really did leave the box and stays contributed. Reopening a game whose contributions have already been paid out remains blocked by the existing guard, which now matters more often.

---

## [v0.2062] - 2026-08-08

### Changed
- **The check-in toolbar no longer moves.** Setup sits between the two sliders, and the player filter, Balance and Add Table moved out of the toolbar and into the views that use them, so the row keeps the same height and the same button positions on every view at every width. Previously switching from List to Log shifted every control sideways, and Table view made the whole bar taller. The remaining controls are grouped into `.pk-tb-add` (walk-in field and + Add) and `.pk-tb-controls` (filters, Setup, views), each a single flex item, so when the row does have to wrap it can only break between the two groups. Left flat it wrapped wherever it ran out — usually between Setup and the view strip, orphaning the views on their own line under a half-empty first row. Setup now collapses to a bare gear on phones alongside the view icons.
- **The player filter is part of the page, not the toolbar.** Only `renderPlayerRows()`, `renderMobileCards()` and `renderTableView()` read `FILTER`; Log, Payouts and Chop ignore it. It renders at the top of List and Table now, slides in with them, and is simply absent elsewhere — which returns those three views to a single toolbar row at 1100px and roughly 250px of width on every screen. Balance and Add Table joined it for the same reason: they act on the seating chart, so they belong to the Table view.
- **Help is anchored in the page header** with Timer, QR, Jackpot and Finish, first in the group so it stays clear of the primary action and steady as the conditional buttons come and go. It explains the whole screen rather than the player list, so that is where it belongs.

### Fixed
- **Help drifted around the toolbar as the window resized.** `.pk-help-btn` carried `margin-left:auto`, which in a wrapping flex row does not pin to the right of the toolbar — it pins to the right end of whichever line the button lands on, so it jumped between rows on every reflow and dragged its neighbours with it. The declaration is gone from the class; the one other caller was already cancelling it inline.
- **The stat cards were bigger on a tablet than on a desktop.** The `max-width:1024px` block set `.pk-stat { flex: 1 1 calc(50% - .5rem) }`, a two-up grid meant for phones — but phones never reach it, because at 768px and below `.pk-stats` is replaced wholesale by the compact one-line bar. It only ever fired on tablets, where four cards stacked into two rows and made the header 179px tall against 96px on desktop. They share one row now, which returns 83px of vertical space on the screens with least to spare.
- **The page scrolled sideways between roughly 768px and 1000px.** Grid items default to `min-width:auto`, so the `1fr` content column refused to shrink below the player table's min-content width of about 970px and pushed the whole page into a horizontal scroll. `.pk-sidebar` already set `min-width:0`; the content column was simply missed. With it, the column tracks the viewport and the table scrolls inside `.pk-table-wrap`, which has had `overflow-x:auto` all along and never got the chance to use it.
- **Enter in the event editor's invite panel saved the event.** A form with submit buttons implicitly submits on Enter from any text input, so typing a guest's name and pressing Enter — the natural thing to do — clicked Save Changes. Enter now moves from the name field to the contact field, opens the next blank row from the contact field so a guest list can be typed straight through, and does nothing in the user-search box, which filters as you type. Every other field is unchanged and still submits.
- **Hand-typed guests were missing from the invite numbering, and from the list order that numbering describes.** The CSS counter incremented only on `li[data-iname]`, so a typed guest showed no number and did not advance the count. Worse, the submit handler ran two passes — registered invitees, then typed rows — so a typed guest dropped to the end of the list on save wherever the host had placed them. That order is priority order, which with a capacity cap decides who is seated and who is waitlisted. Both kinds of row now count, and the save walks the list once in document order.
- **The waitlist cutoff did not appear until the event was saved and reopened.** `updateDividerLine()` counted only registered invitees, so hand-typed guests never pushed anyone past the seat limit — they only started counting once a save turned them into real invite rows. Typed rows are counted now (blank ones deliberately are not, matching the save's own guard), and the cutoff recomputes as you type, when a row is removed, and when capacity changes. Fixing it also removed a re-append of every row that silently shuffled hand-typed guests to the top of the list on any refresh.
- **Copy buttons failed silently outside a secure context.** `navigator.clipboard` is undefined on plain http that is not localhost, so `navigator.clipboard.writeText(...)` threw a synchronous `TypeError` rather than returning a rejected promise — meaning a trailing `.catch()` never ran. The button did nothing at all: no toast, no fallback, and the previous clipboard contents left in place to be pasted. A new `pkCopy(text)` in `pk-dialogs.js` uses the Clipboard API when it is genuinely available, falls back to a hidden textarea and `execCommand('copy')`, and resolves with whether the copy actually happened. Applied to all ten call sites: `event.php`, `timer.php`, `walkin_display.php`, `calendar.php` (both), `league.php` (three), `league_public.php`, `sms_log.php` and `admin_settings.php`. Four of those had a partial guard that checked for `navigator.clipboard` but not the secure context, and reported success the moment `writeText()` was called, so a denied clipboard still flashed "Copied!". Live is served over HTTPS and was never affected; this bites on plain-HTTP deployments and LAN addresses.

---

## [v0.2061] - 2026-08-08

### Changed
- **Game setup is no longer a sixth segment in the view switcher.** Settings had been folded into the List / Table / Log strip, where six equal segments made the most consequential control on the page read as just another thing to look at, and it got lost. The strip now holds views only — List, Table, Log, Payouts, Chop — and setup moved out past a divider as its own outlined button, a different shape carrying different weight. When it is active the segment thumb hides and no segment is highlighted, so nothing looks doubly selected, and it enters from the right and leaves to the left to match where it physically sits. On phones the view segments still collapse to bare icons, but Setup deliberately keeps its label: a lone gear is exactly the ambiguity this change exists to remove.
- **"Settings" is now "Setup" everywhere it faces a host** — the button, the editor header, the "Edit in Setup" links on the payout card and Payouts view, and the empty-payouts hint. The old name reads as optional preferences; what it actually holds is the buy-in, chips, rebuys, payout structure and rewards, which is to say the difference between a game whose money adds up and one whose money doesn't. Code identifiers (`openSettings`, `VIEW_MODE === 'settings'`, `SETTINGS_OPEN`) are untouched. The ? Help panel gained a matching Setup entry, placed first because that is the order the work happens in.

### Added
- **A prompt on games that have not been set up yet.** A toolbar button can only be so loud, so a session whose host has never opened Setup now says so directly above the player list, with a button that opens the editor. It hides while the editor is open, never appears on a finished game, and retires permanently once setup has been saved.
- `poker_sessions.setup_saved` records whether a host has been through the editor. The migration backfills every existing session to `1`, so no game already on a server starts prompting. `update_config` and `load_payout_structure` both set it — applying a preset counts as setting the game up.

### Fixed
- **The setup prompt kept firing on games that were already configured.** It originally decided by inspecting the numbers: no buy-in amount, or no payout structure, meant not set up. That is wrong in two ways that compound. A **$0 buy-in is a legitimate freeroll** — there are saved presets and finished tournaments that run at zero — so a freeroll host would have been told their game was unconfigured forever with no way to stop it. And several presets predate `game_config`, carrying only the payout split, so loading one onto a game created with a blank buy-in leaves the buy-in at zero and the prompt stayed up even though the host had just deliberately configured the game. Both disappear once the prompt keys off `setup_saved` instead of guessing from values; the missing-payout-structure note survives as a detail line, but no longer decides whether the prompt appears at all.

---

## [v0.2060] - 2026-08-08

### Fixed
- **The Payouts & Rewards tab stayed disabled until you saved the game type.** Setting up a new tournament meant choosing Tournament, saving, and then reopening Settings before the payout structure could be configured at all. The tab's disabled state is derived from `isCash()`, which reads the *saved* session, while `previewGameType()` — the dropdown's change handler — only showed and hid sections inside the panes and never touched the tab strip. It now updates both gated tabs live: Payouts enables and disables with the pending choice, and Tickets shows and hides with it. Switching back to Cash while sitting on Payouts returns you to the Game tab rather than leaving you on a dead tab with no panel showing.

---

## [v0.2059] - 2026-08-07

### Added
- **Money entries can be corrected and reversed straight from the Log.** The Log is where a host notices a wrong buy-in, but it was read-only — fixing one meant knowing whose row it was and opening that player's ledger. Money rows now carry the same **Edit** and **Clear** actions the ledger has, and show the amount they were already carrying in their payload. This is not a new mechanism: the ledger and the Log are the same table, `get_player_ledger()` being `poker_session_log` filtered by player and by the five money types, so it reuses `void_ledger_entry` and `edit_ledger_entry` unchanged — both already validate the event type, cross-check the entry's session against the player's, and re-run `verify_event_access`. Actions appear only on money rows; bounty, jackpot, ticket, elimination and approval rows stay read-only because Clear has no defined behaviour for them and would strike the sentence while leaving the money untouched. `logTagLabel()` and the tag colours also gained the five types that were rendering as raw unstyled strings.
- **Payouts is now a view, not a tab buried in Settings.** `renderPayoutsView()` gives the prize ladder a full-width table with Pts / Ticket / Prize columns emitted only when a place uses them, each place's winner and what they were actually credited, a total row, and an unallocated-percentage warning. Beside it sit the pool breakdown (reusing `renderPoolCard()` verbatim, so gross → withholdings → net can't drift from the sidebar), a Bounties & Jackpot card covering per-KO value, collection mode, collected versus claimed, who holds knockouts and the league fund, and — once a game is finished — the final results with stored winnings and reward chips. Editing still lives in Settings. Where stored winnings disagree with the live computed prize on a finished game, both are shown with a warning marker rather than quietly picking one; that only happens when a game is edited after finishing, and hiding it would be worse than surfacing it.

### Changed
- **Settings, Payouts and Chop join the List / Table / Log switcher, and all of them render as views.** One six-segment control replaces the header gear and the header's "Payout" button, both of which are gone — duplicate entry points would have defeated the point of grouping them. Crucially Settings is no longer a full-screen overlay: it renders in the content area like the others, so the page keeps its header, stats row, toolbar and sidebar instead of being replaced wholesale. That overlay existed to keep the 10-second poll from wiping unsaved form state, so `refreshUI()` now splits out `refreshUIChrome()` and runs only that in Settings and Chop, never repainting the content area while either holds unsaved input. `rosterEditInProgress()` watches the editor, dirty tracking moved to a document-level delegate (the editor is a fresh element on every repaint), and `settingsSaved()` restores the previous view before rebuilding the dashboard so the rebuild doesn't paint the editor being left. Leaving Settings by clicking another segment now routes through the same discard confirm as Close. The sidebar hides while the Payouts view is up, without which the pool and payout cards printed twice on one screen. Cash games get four segments, with coercion in both `renderDashboard()` and `setViewMode()` so changing game type can't strand a host on a view that no longer exists. Labels collapse to icons below 768px, matching where the header buttons already lose theirs.
- **The deal split calculator is now the Chop view.** It was a header button labelled "Payout" sitting beside QR, which read as a second payouts control and hid the calculator behind a modal. `renderChopView()` emits the markup the modal used to build, so the ICM, standard and chip-chop maths are untouched. The "needs at least 2 players" case is a message in the view rather than an alert fired by a button that then did nothing.
- The pot-integrity and ticket-target warnings moved into a shared `payoutWarningsHtml()` used by both the sidebar card and the new view. That closes a real gap: the sidebar is `display:none` below 1024px, so those two warnings were invisible on phones and tablets, while the view is on the toolbar at every width.

### Fixed
- **The compact pool/payout bar on phones and tablets went stale.** It is rendered once and was never touched again, so after any buy-in its figures drifted from the pool header directly above it (observed reading "Pool: $30" against a $45 header). Below 1024px the sidebar is hidden, so that bar *is* the pool and payout readout on mobile. Extracted to `renderInlineSummary()` and repainted in `refreshUI()` alongside the cards it stands in for. Pre-existing, surfaced while testing the new view at 390px.
- **Clear is suppressed on rebuy and add-on rows with a zero amount.** A removal is logged as `-amount`, and `void_ledger_entry` reads that sign to know which way to correct the count — so at a $0 rebuy price the direction is lost (`-0 === 0`) and clearing would decrement again instead of restoring. Pre-existing in the ledger; guarded now in both surfaces because the Log makes it far easier to reach.

---

## [v0.2058] - 2026-08-07

### Fixed
- **"Message guests" was unusable — the message box was covered by a giant black X.** `event.php` loaded `vendor/jodit/jodit.min.js` but never its stylesheet, so the editor initialised completely unstyled: the typing area collapsed to a single 26px line and the toolbar's SVG icons rendered at natural size, one of them spilling across the modal as the black X hosts were seeing. Reported from live. The stylesheet was lost in the v0.2038 port of this composer out of the calendar popup — `calendar.php`, `league.php` and `admin_posts.php` all load both files, and only `event.php` was missing it, so the feature has been broken for every host since that release. Adds the `<link>` beside the existing `<script>` inside the same `$canManage` block. Verified on dev: typing area 26px → 219px, toolbar icons 0×0 → 14×14, and a typed message round-trips to clean HTML.

---

## [v0.2057] - 2026-08-06

Security pass over everything shipped since v0.2048 (the SMS conversations feature and Payout 2.0), reviewed across four dimensions — injection, authorization, money integrity, and the untrusted inbound boundary — and landed from the `GameNight-SecFix` branch. Every finding below was reproduced before the fix and re-tested after.

**Operator notes.** The inbound SMS webhook now *fails closed*: it rejects any request it cannot authenticate, where before an unset secret or a non-Surge provider silently meant "accept everything". Live runs Surge with a signing secret configured, so inbound keeps working — but **send one test text after deploying to confirm it**, and check the new banner on the SMS settings tab, which states plainly whether inbound can be authenticated. Separately, any host who uses the `CANCEL` / `MSG` / `REMIND` text commands must now have a verified phone number (Settings › Profile › Verify); read-only commands are unaffected.

### Security
- **Stored XSS in every quoted-attribute sink that used `escHtml()`.** The helper round-tripped text through a text node, which escapes `&`, `<`, `>` and *not* quotes — safe in a text position, unsafe the moment its output lands inside `title="…"` or `value="…"`, where a crafted display name or ledger note could close the attribute and add an event handler. `escHtml()` now escapes quotes too, closing the jackpot recipient picker, the jackpot ledger tooltip, the roster notes tooltip and the tip helper at once; text positions are unaffected since the parser decodes the entities anyway. The walk-in autocomplete needed a different fix — its name was interpolated into an inline handler's JS string literal, and attribute values are HTML-decoded *before* JavaScript parses them, so an escaped quote comes back; the name now travels in a `data-` attribute. Three call sites that interpolated straight into `pkConfirm`/`pkPrompt`/`pkAlert` (all `innerHTML` sinks) gained the missing escaping. Injection required host privilege — `add_walkin` applies no character filter — and fired on other hosts and admins.
- **League jackpot money was reachable with event-level authority.** `record_jackpot_hit`, `adjust_jackpot`, `void_jackpot_entry` and the ledger read gated on `can_manage_event()`, which grants on `events.created_by` and per-event manager invites — so anyone who put an event on a league's calendar could drain, forge or void the league's shared fund. New `pk_can_manage_league_money()` requires an actual league owner/manager role, matching the model where leagues are the host tier. Attaching an event to a league now also requires owner/manager rather than plain membership, since that binding was the escalation's entry point; editing an event already on a league preserves its binding so a co-host cannot silently unlink it.
- **Host conversation replies could be aimed at any phone number.** `sms_conversations_dl.php` `action=send` validated the event but never that the number had anything to do with it, so a host of any event could text an arbitrary number from the shared site number — and the `sms_conv_bind()` that follows re-pointed that phone's conversation at their event, capturing the victim's next reply out of the real host's thread. The number must now already have messaged that event or be on its guest list, matched through `sms_conv_digits()` so stored formats compare the way the webhook compares them.
- **The inbound SMS webhook accepted unauthenticated requests.** The endpoint is public and takes the sender's identity from a POST field, so a forged request could impersonate any member or host — RSVP changes, conversation injection, and the `CANCEL` / `MSG` host commands. Verification existed only for Surge and was skipped entirely when its signing secret was blank; the other four providers fell straight through to full processing. A request must now prove itself with either a valid provider signature or a new shared webhook token that works with any provider (`?token=` or an `X-Webhook-Token` header, compared with `hash_equals`) — the same pattern `cron.php` and `wa_webhook.php` already use. The token is a new per-provider setting, encrypted at rest.
- **Inbound had no rate limit, and needed no forgery to abuse.** Every inbound message could trigger an auto-reply plus a notification to every host by email and SMS, with no ceiling — one number texting in a loop cost money on both legs and risked the shared 10DLC number's reputation. Past 25 inbound in an hour from one number the message is still logged (the host's conversation view misses nothing) but processing stops and nothing is sent back. Bodies are capped at 1600 characters and raw payloads at 16 KB before storage; both were previously persisted verbatim against a 22 MB request limit.
- **Destructive SMS commands now require a verified phone number.** `CANCEL` deletes an event and notifies every invitee; `MSG` and `REMIND` reach every guest as an official update. All three rested on "this number matches a user row", and a number is self-asserted until verified. They now check `users.phone_verified` — a column that already gated MFA but that the SMS command layer never consulted — at staging *and* again inside `CONFIRM`, since changing a number in Settings resets the flag.
- **Payout percentages could exceed 100% via a negative row.** `update_payouts` summed every submitted percentage including negatives but stored only positive rows, so `150` and `-50` totalled 100, passed the cap, and left a 150% first place paying more than the pot holds. `save_payout_structure` had the same hole and baked it into reusable presets, and `load_payout_structure` validated nothing at all. Negatives are rejected before summing on both write paths, and the load path re-checks the split it is applying.

### Fixed
- **Converting an entry ticket to cash and re-finishing the game minted a second ticket.** The duplicate guard in `pk_finish_session()` excluded `converted` rows, while `pk_apply_tournament_payouts()` permanently folds converted ticket values into the holder's payout and `calc_pool()` withholds the value only once. Finish → convert → reopen → finish therefore left the winner holding both the cash credit and a fresh live ticket, creating the ticket's value on every cycle. A place now issues its ticket once regardless of what became of it; reopening still deletes the `issued` rows, so legitimate re-issue after a reopen is unchanged.
- **Un-buying a player stranded their redeemed entry ticket.** The un-buy released the ticket back to `issued` and cleared the redemption link entirely, and nothing re-attached it — bulk Buy In never sends a ticket id, so re-buying that player left them seated with the pot counting a cash buy-in nobody paid while the holder kept a live ticket to spend elsewhere. The release now keeps `redeemed_session_id`/`redeemed_player_id` as a breadcrumb and a subsequent buy-in re-applies that ticket, logging it; a ticket re-targeted or converted in the meantime is correctly ignored. `pk_unfinish_session()` additionally blocks a reopen on a released-but-linked ticket, not just a redeemed one — otherwise un-buying at the target silently unlocked the source game's reopen, which deletes issued tickets and would destroy a seat about to be restored.
- **Reading the jackpot ledger created a fund row.** The `jackpot_log` GET called the upserting `pk_jackpot_fund()`, so a CSRF-able read could bring a "💎 Jackpot $0" badge into being on a league that never had one. It now uses a non-creating read.

---

## [v0.2056] - 2026-08-05

Payout 2.0: the complete multi-reward tournament payout system, developed and dev-tested on the `GameNight-Payout2.0` branch (35 commits) and landed as a single release.

### Added
- **Tournament payouts can now award more than cash: league points, entry tickets, and prize labels per finishing place.** `payout_structure_places` and `poker_payouts` gain `points`, `ticket_cents`, and `prize_label` columns; the payout editor shows PTS / TICKET $ / PRIZE columns toggled by reward chips. League standings rank by points (`league.php`, with per-game detail in `league_player.php`), winnings include bounty cash, and the season champion is decided by points with the old average-score fallback.
- **Knockout bounties, baked in or optional.** A per-session bounty (`bounty_amount`/`bounty_points`) pays the eliminator cash and/or points per knockout, recorded via the eliminate dialog's "knocked out by" picker. Two collection modes (`bounty_optional`): baked into the buy-in (everyone carries a bounty, funded by withholding from the pool, winner keeps their own) or an optional side pot (per-player 🎯 opt-in, side money that never touches the pool; only opted-in heads carry money and only opted-in players collect). Bounty points per KO are universal in both modes.
- **Rebuying an eliminated player re-enters them into the game.** Clicking + on Rebuys for a knocked-out player (rebuys remaining) clears their elimination, reseats them, and reopens an auto-finished game. The eliminator permanently keeps the bounty they collected (banked in new `bounties_banked`/`bounty_cash_banked` columns), and the re-entering player's bounty chip resets: optional mode clears their opt-in so they can buy a new chip (consumed chips still count in side-pot totals), baked mode marks their head claimed (`bounty_claimed`) so it can't pay out twice. Bounty money stays exactly balanced through any number of re-entries.
- **Funded satellite entry tickets.** A place can award a ticket to a target event (`ticket_target_event_id`): its value is withheld from the pool, issued at game finish (`poker_entry_tickets`), and the winner is auto-invited to the target event (RSVP yes, approved). At the target, the host redeems the ticket toward the buy-in, with any surplus over the buy-in flowing into that game's pool; tickets can be re-targeted to another event or converted to cash winnings. Reopening a game voids its issued tickets and is blocked once one has been redeemed.
- **League progressive jackpot.** A single per-league fund (`league_jackpots`) grows from per-game contributions: baked into the buy-in or an optional per-player 💎 entry (`jackpot_optional`, default). Recording a hit (bad beat, royal flush, or other) pays out any split across selected players; every movement lives in an append-only ledger (`league_jackpot_log`) with strike-through voiding and signed manual adjustments, guarded against overdrawing the fund. The fund balance shows as a badge in the league header on every tab.
- **Full-screen tabbed Game Settings editor.** The inline settings panel is replaced by a fixed overlay with Game | Payouts & Rewards | Tickets tabs, dirty-state tracking with discard confirmation, Esc/browser-Back close, and Save that closes the editor. Finish/Reopen moved out of settings into the page header. All panes stay mounted so the 10-second poll and unsaved values survive tab switches.
- **Game presets capture the entire editor.** Saving a preset stores the payout places plus the bounty/jackpot recipe and the whole Game tab (`payout_structures.game_config` JSON); loading applies all of it to the current game immediately. The picker sits above the tabs as "Game Preset" with a help dialog explaining store/load/save-as/delete.
- **Shared animated save overlay.** `pkProgress()`/`pkProgressDone()` in `pk-dialogs.js` (minimum 700ms display) replace ad-hoc save spinners; used by settings save, event edit, and preset loading. `_footer.php` cache-busts `pk-dialogs.js` by file mtime.

### Changed
- **Money inputs show a $ prefix** across the settings editor, so a "1" in Ticket $ reads as one dollar, not a mystery unit.
- **Pool math is explicit about withholding**: net pool = gross (buy-ins + rebuys + add-ons) minus baked bounty and jackpot withholding (initial buy-ins only) minus ticket prize values, plus redeemed-ticket surplus; the pool card itemizes each line including optional-mode side money.
- **Eliminating an already-eliminated player is rejected** ("Undo their elimination first") instead of silently corrupting finishing places, and hitting max rebuys returns a clear error instead of clamping.
- Ticket awards notify through a new **reward_ticket** notification category under Rewards.

---

## [v0.2055] - 2026-08-04

### Fixed
- **A phone-only invitee's "Yes" now actually RSVPs instead of being forwarded to the host as a chat message.** SMS keyword RSVP had always been registered-users-only, but v0.2054's invite text tells every recipient "Reply YES, NO or MAYBE to RSVP" — so a guest with no account (observed live: Peggy B on event 155) replied Yes one minute after her invite, the conversation layer captured it as free text, told her "we passed your message along to your host", notified the host by SMS, and left her RSVP blank. New `sms_guest_rsvp()` in the unregistered branch of both webhooks matches the sender's phone against upcoming approved invites (soonest first), sets the RSVP, promotes the waitlist on a "no", notifies the hosts through the normal RSVP-reply path, and confirms with the event named. Runs before conversation capture.
- **Registered users with an unanswered invite can no longer have a bare YES/NO/MAYBE diverted to conversation.** The v0.2054 mid-conversation guard now also requires that the sender has no upcoming approved invite awaiting an answer (`sms_unanswered_invite()`): someone who was just asked to RSVP and typed exactly that gets an RSVP, and the "need a ride?" → "no" chat protection still applies once their invites are answered.

---

## [v0.2054] - 2026-08-02

Final round of live-testing refinements for the SMS conversations feature (v0.2049-v0.2053); shipped as three version-less pushes during the hold and consolidated here.

### Fixed
- **A bare "no" answering the host no longer flips the guest's RSVP.** When the host asked something conversational ("need a ride?"), the guest's one-word answer hit the RSVP parser and changed their attendance. A bare YES/NO/MAYBE now routes to the conversation when the phone's latest conversation activity (12-hour window) is newer than the latest RSVP-soliciting outbound (invite, nudge, or reminder - identified by their "RSVP" wording plus event context via `sms_conv_active()`), so a reply right after a fresh invite still RSVPs normally. Explicit forms always change the RSVP: `1 YES` / `ALL NO` by number, plus a new `RSVP YES` / `RSVP NO` / `RSVP MAYBE` override for mid-chat changes - listed in all three help texts, and taught contextually by the divert ack ("To change your RSVP instead, reply RSVP NO."). Applied to both the SMS and WhatsApp webhooks.
- **The conversation view shows only real conversation, not command chatter and automated sends.** The thread previously listed every `sms_log` row for the phone+event: reminders, RSVP confirmations, STATUS/WHICH exchanges, acks. A new `is_conversation` flag is set exactly where a message is known to be conversational - attributed free text in the webhooks, messages delivered via the which-event chooser (whose pending row now carries the original `log_id`), host composer replies, and admin-assigned rows - and the list/thread/poll queries filter on it. A one-shot backfill classified existing attributed inbound by excluding command vocabulary; historical outbound could not be classified retroactively and stays out of threads. Everything remains visible in the full Notification Log.

### Changed
- **Invite, nudge, and reminder texts send one link instead of three RSVP URLs.** `_rsvp_links_sms()` appended per-answer `rsvp.php` links for YES/NO/MAYBE, making every invite text long and link-heavy. It now sends "Reply YES, NO or MAYBE to RSVP - any other reply goes to your host." plus a single token-authenticated `event.php` view link (no login needed; URL shortener applies). The instruction doubles as discovery for the conversation feature.

---

## [v0.2053] - 2026-08-02

### Fixed
- **Texting HELP never reached GameNight - Surge answers the reserved keyword itself.** The live log showed inbound "Help" rows through July 28 and none after: the Surge platform now intercepts HELP (a CTIA reserved keyword, like STOP) and sends its own compliance auto-reply, so the webhook never fires and the sender only sees Surge's one-liner. The reliable path in is a non-reserved alias: MENU and INFO join COMMANDS as HELP synonyms in both webhooks and the admin command layer, and the opt-out footer appended to outbound SMS now advertises "COMMANDS for options" instead of "HELP for commands" so guests are steered to a keyword that actually reaches the app. Operator note: Surge's HELP auto-response text can be customized in the Surge dashboard - pointing it at "Text COMMANDS for the full list" closes the loop for people who text HELP anyway.
- **Host-notification emails about text replies failed with "Message body empty".** `sms_conv_notify_hosts()` passed only an SMS body to `notify_user_direct()`, whose email channel sends the HTML body - which was empty, so every `sms_reply` notification to a host whose preferred contact is email errored out (visible in the log as provider='email' failures). The notification now builds a proper HTML body with the sender, the quoted message excerpt, and an "Open the conversation" link.

---

## [v0.2052] - 2026-08-02

### Fixed
- **HELP now actually lists the whole vocabulary, for hosts too.** Hosts and admins never saw the guest help text: `sms_handle_admin_command()` intercepts HELP for elevated users and answers with `sms_admin_help_text()`, which listed only the admin verbs, so WHICH/SWITCH (v0.2051) and every guest command were invisible to exactly the people testing them. The admin help now appends a compact guest-commands block (RSVP keywords and the `2 YES` / `ALL YES` number forms, EVENTS/STATUS, WHICH/SWITCH, STOP/START) plus the line "Any other text is passed to that event's host." The guest help in both webhooks gains the two things it never mentioned: the RSVP-by-number forms, and the free-text-goes-to-your-host behavior, which is the most important thing a texter can learn from HELP. Files: `sms_admin.php` (`sms_admin_help_text`), `sms_webhook.php` (`$helpText`), `wa_webhook.php` (HELP branch).

---

## [v0.2051] - 2026-08-02

### Added
- **WHICH and SWITCH text commands: see and change where your texts go.** With every conversation riding one shared number, a sender had no way to tell which event's host their messages were reaching. Texting **WHICH** (or WHERE) now answers with the currently attributed event, e.g. `Your texts go to the host of "Test Poker Night" (Aug 2).`, appending a SWITCH hint when the sender has more than one upcoming event. Texting **SWITCH** (or CHANGE) re-points the conversation: with one candidate event it binds immediately, with several it reuses the numbered which-event chooser from v0.2050 with an empty held message, so the numeric pick just updates the sticky binding and confirms, without pinging the host. Both commands work for registered users and phone-only invitees (matched via `event_invites.phone`), on both the SMS and WhatsApp webhooks, and are listed in the HELP text. Implementation extracted the candidate-events lookup from attribution layer 3 into `sms_conv_candidate_events()` so the commands and attribution share one definition of "the sender's upcoming events".

---

## [v0.2050] - 2026-08-02

### Fixed
- **A text reply about a deleted event no longer vanishes into a dead conversation.** The recent-outbound attribution layer didn't check that the referenced event still exists, so a reply to a reminder for a since-cancelled event was tagged with a dead event id: the host lookup joined a missing `events` row and notified nobody, while the sender still heard "Got it - passed along to your host". `sms_conv_attribute()` now guards layer 1 with an `EXISTS` check (the sticky-binding layer already self-healed), so attribution falls through to the sender's remaining live events, and a stale binding pointing at a deleted event is replaced on their next message.

### Added
- **Ambiguous texts now ask the sender which event they mean.** When a message can't be placed (no recent outbound, no binding, and the sender is invited to two or more upcoming events), the webhook used to reply with the generic help text and drop the message into the admin bucket. It now answers "Which event is your message about? Reply 1 for X (date), 2 for Y (date)" (up to five options), holding the original message in the new phone-keyed `sms_pending_conv` table (30-minute TTL, follows the `sms_pending_poll` pattern). A numeric reply delivers the held message to the chosen event's hosts, retroactively attributes the sender's recent unattributed messages, and sets the sticky binding so follow-ups land in the same conversation. The choice check runs before the RSVP number flows so the digit can't be misread as an RSVP selection, and a number that doesn't match a live question passes through to normal handling untouched. Applies to both the SMS and WhatsApp webhooks; `cron.php` sweeps expired questions.

---

## [v0.2049] - 2026-08-02

### Added
- **Text replies from guests now land in a host-visible, two-way conversation.** GameNight sends every SMS/WhatsApp from one shared number across all events and hosts, so an inbound "running late, save my seat" had nowhere to go: it fell through the RSVP parser to a help-text auto-reply and was lost, logged with no event or sender attribution. Every inbound message is now attributed by layers: the most recent outbound to that phone with event context within 72 hours wins (a reply to a reminder answers that reminder), then a sticky phone-to-event binding in the new `sms_conversation_bind` table, then a sender who maps to exactly one upcoming event via `users` or `event_invites.phone` (which also covers unregistered invitees and recovers their display name). Attributed rows are tagged in `sms_log` via a new digits-only `phone_digits` key (backfilled one-shot; email rows excluded), the event's hosts (creator + managers) get an in-app `sms_reply` notification mutable under the existing Messages preference, and the texter gets a single "Got it - passed along to your host" ack; only the first message of a 12-hour session is acked, follow-ups are captured silently so the auto-reply never feels like spam. Command vocabulary (YES/NO/HELP/STOP/EVENTS/host commands) is untouched; the hooks only replace the two terminal fallbacks. New host UI at `sms_conversations.php` (linked from the event page and the Notification Log): a per-event conversation list, a chat-bubble thread with failed sends shown inline, and a reply composer that sends back over whatever channel the guest last used (SMS or WhatsApp), rate-limited to 30 messages/hour per number, gated by `can_manage_event()`. Site admins additionally get an unassigned bucket for texts that couldn't be matched (e.g. a sender invited to two upcoming events), with a claim-to-event action. Conversations are part of the notification log and share its 90-day retention.

### Fixed
- **Twilio/Plivo webhook replies were never logged.** `respond_to_provider()` answers those two providers by echoing TwiML into the HTTP response rather than calling `send_sms()`, so the outbound half of every webhook exchange (RSVP confirmations, help text, admin-command replies) was invisible in the Notification Log. Both TwiML branches now write an outbound `sms_log` row, picking up event/recipient context automatically when set.

---

## [v0.2048] - 2026-08-02

### Fixed
- **The timer no longer rounds four-digit blinds down to a whole "K".** `fmtChips()` in `www/timer.php` abbreviated every value from 1,000 up with `toFixed(0)`, so a 1200/2400 level displayed as "1K/2K" — visibly wrong to everyone at the table for the whole level. Values that are exact at one decimal now keep it (1200 → 1.2K, 2400 → 2.4K) while clean thousands stay short (4000 → 4K), and anything that would still round wrong at one decimal (e.g. a 1250 blind) is shown unabbreviated instead of approximated. The millions branch already carried its decimal and is unchanged. The same fix was ported to the standalone tournament-timer fork.

---

## [v0.2047] - 2026-07-31

### Changed
- **The calendar options are a collapsible panel instead of a jump link.** "Subscribe to calendar" in the league header row was an `#lp-cal` anchor that scrolled the visitor to a block sitting below the events list, which read as the page lurching downward for no obvious reason. The panel now sits directly beneath the header row and starts collapsed; the link toggles it open in place with a caret that flips direction, and the handler returns false so the browser never navigates or writes a hash into the URL. The link carries `aria-expanded` / `aria-controls` so assistive tech announces a disclosure control rather than a link. Panel contents (Subscribe via `webcal://`, one-time Download .ics, and the copyable https URL for Google Calendar) are unchanged.

---

## [v0.2046] - 2026-07-31

### Fixed
- **"Subscribe to calendar" now actually subscribes instead of importing once.** The public league page linked straight to `https://…/league/<slug>.ics`, and iOS/iPadOS treats an `.ics` served over plain https as a document to import: it offers "Add All", copies the events in once, and keeps no connection to the URL. The feed was never static (`league_ics.php` regenerates it from the database on every request), but the link protocol was wrong. The Subscribe action now uses `webcal://`, the scheme Apple and Outlook recognize as "subscribe and keep polling", and the calendar block on the page splits into three clearly-labelled paths: **Subscribe** (webcal, stays current), **Download .ics** (https, one-time snapshot, previous behavior), and a copyable plain https URL for Google Calendar, which prefers a pasted link under *Other calendars → From URL* over a `webcal://` click. Copy wording states plainly which option updates and which does not; the header-row link now scrolls to that block rather than firing the import.
- **Subscribed calendars were left to guess their refresh rate.** The feed carried no refresh hint, so clients chose their own interval and some default to daily or weekly, which makes a subscribed league schedule look stale. It now emits `REFRESH-INTERVAL;VALUE=DURATION:PT4H` (RFC 7986) alongside the older `X-PUBLISHED-TTL:PT4H` that Apple and Outlook honor, and sends `Cache-Control: no-cache, must-revalidate` so no intermediate proxy can serve a subscriber an outdated copy. Note that calendar subscriptions are pull-based regardless: a newly added game appears at the client's next refresh, not instantly.

---

## [v0.2045] - 2026-07-31

### Added
- **League banner images can fit instead of crop.** The public page hero and directory card rendered the banner with `object-fit: cover`, which scales an image up until it fills the box and crops whatever overflows — fine for a wide banner, bad for a logo or any tall image, which lost its top and bottom. A new "Image sizing" dropdown sits under the banner upload in the league Settings tab with two options: *Fill the space (crops edges)*, the previous behavior and still the default so no existing league changes appearance, and *Fit whole image (no cropping)*, which scales the whole image down to fit and letterboxes the remainder against a neutral background without enlarging a small logo past its natural size. New `leagues.banner_fit` column (`'cover'` | `'contain'`, defaults to `'cover'`); the value is validated against that pair on save and coerced to `'cover'` otherwise, so it can never reach the stylesheet as arbitrary text. The setting saves on change through a new owner-gated `league_banner_fit` action and the settings-panel preview updates immediately to match what the public page will render. Applies to both the hero on `/league/<slug>` and the cards in the `/league` directory. Note this is a display fit only, not a server-side resize of the stored file: the container image ships without GD or Imagick, so shrinking the actual upload would require a Dockerfile change.

---

## [v0.2044] - 2026-07-30

### Added
- **Public league landing pages at `/league/<slug>`.** A league owner can now switch on a public page from the league's Settings tab and share a clean URL like `gamenight.poker/league/snowman` with anyone — no account, no login. The page shows the league's banner, name, description, member count, join mode, upcoming events, publicly shared posts, and a top-5 leaderboard teaser, plus OpenGraph tags so the link unfurls properly in group chats and social feeds. It is strictly opt-in (new `leagues.public_page`, defaults off) and hidden leagues can never enable it; hiding a league also switches its public page back off. New `leagues.slug` column (unique, `[a-z0-9-]`, auto-generated from the league name by `league_slugify()` in `db.php`, backfilled for existing leagues on first request) with a reserved-word guard and an owner-editable field. Routing is two `.htaccess` rewrites into the new `www/league_public.php`; the slug charset excludes dots and slashes so it can never shadow a real file or the `FilesMatch`-denied internals.
- **A public league directory at `/league`.** Bare `/league` lists every league that has opted into a public page, sorted by name, with banner, description snippet, member count, upcoming-event count, and join mode on each card. Also added to `sitemap.php` alongside a dynamic entry per public league.
- **Public league calendar feed (`www/league_ics.php`).** `/league/<slug>.ics` is a subscribable multi-event ICS covering the last 30 days plus everything upcoming, so anyone can follow a league's schedule in Google/Apple Calendar without joining. Emits only `SUMMARY`, `DTSTART`/`DTEND`, `LOCATION` (venue name), and a URL back to the public page — never `DESCRIPTION`, since event descriptions can reference members. Start/end handling and RFC 5545 folding are kept in sync with the single-event `ics.php`.
- **League banner images.** Owners can upload a banner (new `leagues.banner_path`) shown as the public page's hero and as the `og:image` in link previews. The destination filename is fully server-generated (`league_banner_<id>.<ext>`) from a finfo-sniffed MIME type, 4 MB cap, JPEG/PNG/GIF/WebP only.
- **Join from the public page.** Members see "Open league", pending requesters see a status pill, and logged-in non-members join (or request to join) through the existing CSRF-gated `request_join` action, so `approval_mode` still governs. Logged-out visitors are sent to login or registration and returned to `/league/<slug>?join=1`, which completes the join on arrival; `register.php` now threads a validated `redirect` parameter through to its sign-in links using the same rule as `login.php`.
- **Separate venue name and address on events.** The single free-text Location field became two: **Venue name** (e.g. "Mike's Garage", 120 chars) and **Address** (street address, 200 chars, new `events.venue_name` column). This is what makes public pages safe for home games — the public landing page and public ICS feed publish the venue name only, while the address stays with members and invitees, who continue to see "Venue · Address · Open in Maps" on the event page and a combined `LOCATION` in their private ICS and invite/reminder notifications. `venue_name` is also readable and writable through the REST API.

### Changed
- **A game night with no end time now stays in "Upcoming" for 4 hours after it starts**, in both the league events tab and My Events, instead of dropping into "Past" the moment its start time passes. This matches the 3-hour assumption the ICS export already made for open-ended events; an event with an explicit end time is unaffected.

### Fixed
- **The REST API's `location` field was always empty.** Both event GET projections in `api/v1/events.php` referenced `location` while neither `SELECT` actually fetched the column, so the field documented since v0.2026 silently returned `""` to every consumer. Both queries now select it (and `venue_name`). Operator note for anyone consuming this API publicly: `location` holds the street address and will begin returning real values after this deploy.
- **Guests could reach a calendar query built for a logged-in viewer.** `calendar.php` called `event_visibility_sql('events', (int)$current['id'])` at two call sites without a null guard; `$current` can be null there, and `(int)null` produced a `user_id = 0` lookup that happened to match nothing rather than by design. Both now pass `$current ? (int)$current['id'] : null`, the correct nullable pattern used in `index.php`.

### Security
- **Public league surfaces no longer publish `invitees_only` events (MEDIUM, found in review before release).** The public page and ICS feed intentionally bypass `event_visibility_sql()` — its guest branch allows only `visibility='public'`, which would render every league calendar empty — and relied on a comment asserting that league events are always `visibility='league'`. That is not an invariant: any league *member*, not just the owner, can attach an event to a league while marking it "Invitees only" in the event editor. Such an event's title, date, time, and venue name would have been served to anonymous visitors and submitted to search engines, overriding a privacy choice made by a different user than the owner who opted the league in. Both queries now filter `visibility IN ('league','public')`. Verified with a probe event: excluded from the public page and feed, still visible to members.
- **Public projections are allowlisted by construction.** Every query behind `league_public.php` and `league_ics.php` names its columns explicitly and never uses `SELECT *`, so `invite_code`, `owner_id`, member emails/phones, event descriptions, street addresses, and attendee/RSVP data cannot reach an unauthenticated visitor. Unknown slugs, non-public leagues, and hidden leagues all return an identical 404 so the route is not an existence oracle, and the hidden-league rule is enforced at three independent layers (the toggle refuses, hiding zeroes the flag, and both endpoints filter `is_hidden = 0` regardless).

---

## [v0.2043] - 2026-07-29

### Changed
- **Contacts page shows row numbers and a prominent total.** User feedback: with ~30 contacts entered there was "no numbers" on the page — the existing count did exist but was small grey text at the far end of the toolbar, and it went unnoticed. Following the numbered-list pattern from the event invite panel, every row in the contacts table now carries a sequence number in a new leading `#` column, and the total moved into the page heading ("My Contacts (32)"). The redundant toolbar count was removed. `www/contacts.php` only; no schema or endpoint changes.

---

## [v0.2042] - 2026-07-28

### Fixed
- **WhatsApp moved to the GOWS engine after a 31-hour outage.** On 2026-07-27 WhatsApp began rejecting the NOWEB/Baileys engine's login handshake — first as a drop-and-reconnect flap every few minutes, then as outright login refusal where even a fresh QR registration failed (a known, unfixed breakage across the Baileys ecosystem). `docker-compose.yml` now sets `WHATSAPP_DEFAULT_ENGINE=GOWS` (whatsmeow/Go), which linked and worked immediately from the same host. Operator note: an engine switch starts with an empty session store — the session must be created via `POST /api/sessions` (the per-session `start` endpoint 404s) and re-linked by QR, which was done during the incident.
- **Inbound WhatsApp parsing now understands GOWS payloads.** `wa_webhook.php` resolved LID-format senders (`…@lid`) only via NOWEB's `_data.key.remoteJidAlt`, so under GOWS the phone number came up empty and every inbound message (RSVPs, HELP, admin commands) was silently discarded before even being logged. The handler now also reads GOWS's `_data.Info.SenderAlt`.
- **The WAHA watchdog can no longer be defeated by a flapping session.** During the outage's first 30 hours the session reconnected for ~3 minutes between drops, so the watchdog's 5-minute probes almost always read WORKING, the consecutive-fail streak reset every time, and no admin alert was ever sent. `waha_watchdog()` in `sms.php` now also counts bad probes in a rolling 6-hour window (alerting at 4, even non-consecutive), auto-restarts sessions stuck in STARTING for 3+ checks (previously only FAILED), adds a STARTING-specific hint to the alert email, and only declares recovery — clearing the alert dedup and flap counter — after 6 consecutive WORKING probes, so a flap can't re-trigger the alert email on every cycle. New site_settings keys: `waha_ok_streak`, `waha_flap_count`, `waha_flap_window_start`.

---

## [v0.2041] - 2026-07-22

### Added
- **User avatars + a redesigned account area in the nav.** The username text in the nav became a circular avatar — an uploaded profile photo, or the user's first initial on a color deterministically derived from their name (the same person always gets the same color; the PHP and JS color hashes are kept in sync so server- and client-rendered avatars match exactly). Clicking the avatar opens a personal menu: name header, Notifications and Messages (each with its own unread count), My Events, Contacts, My Settings, and Sign out — all moved out of the hamburger, which now holds only site navigation (Home/Leagues/Calendar/Timer/admin/Help). The standalone notification bell is gone; a single red counter in the avatar's corner shows notifications + messages combined, updated live by the existing `_footer.php` poller. Opening one nav menu closes the other, and clicking outside closes both. On desktop the top links row slimmed to Home/Leagues/Calendar.
- **Profile photo upload.** A photo section in Settings > Profile lets users upload (reusing the shared image uploader — content-sniffed, 8 MB cap) or remove a photo; no photo falls back to the colored initial. New nullable `users.avatar_path` column; `set_avatar`/`remove_avatar` actions validate the stored path against exactly what `upload.php` issues.
- **Avatars rolled out across the app.** The shared `avatar_html()` (PHP) / `gnAvatarHtml()` (avatar.js) helper replaced the old flat-grey initial circles in post, league, and event comments, added avatars to the messages list (the other person's photo, or a colored group circle) and group message-thread bubbles (sender avatar, live-appended ones included via a new `avatar_path` field in the `since` payload), and to event rosters ("who's coming"). The dense manager invite grid stays text-only, and 1:1 message bubbles omit per-message avatars, by design.

### Changed
- **Avatar colors are dark enough for readable white initials.** The generated background is `hsl(hue, 60%, 30%)`, which clears the WCAG AA 4.5:1 contrast bar for white text at every hue (the previous lighter value washed out some colors). Uploaded photos with transparency now honor their alpha channel (the grey fallback fill behind avatar images was removed).
- **The collapsed nav bar grew from 32px to 42px** so the 34px avatar seats cleanly inside it instead of overflowing top and bottom when the nav is collapsed.

---

## [v0.2040] - 2026-07-22

### Fixed
- **The event editor's invite panes are side-by-side again on desktop** (regression from the v0.2038 mobile pass). A stray `}` in the `event_edit.php` stylesheet closed the `@media (max-width:1024px)` block early, so the mobile "stack the panes" rule (`.edit-invite-panel { grid-template-columns:1fr }`) leaked out of the media query and applied at every width — leaving All Users and Invited stacked on top of each other even on large screens. The brace is restored, so the two panes (with the arrow column between them) render side-by-side at ≥1024px and stack only on small screens, where the tap-to-invite + sticky-save flow stays.

---

## [v0.2039] - 2026-07-21

### Security
- **Fixed a private-event access + approval-bypass IDOR (HIGH, live-reproduced).** The `update_rsvp` handler in `www/calendar.php` is on the self-service allowlist but, unlike `self_signup`, did no visibility or existing-invite check. Sending an `occurrence_date` reached an insert branch that created a brand-new `event_invites` row for the caller with `approval_status` hardcoded to `approved` — on ANY event id. A logged-in user could POST `update_rsvp` against a private/league event they weren't invited to and couldn't see, self-inserting an approved seat that both revealed the event (via `event_visibility_sql`'s invite-EXISTS clause) and skipped host approval. Fix: a self RSVP now requires an existing base invite for that user (RSVP changes your answer; joining is `self_signup`, which stays visibility-gated); manager on-behalf RSVPs are unaffected (already gated to admin/owner). Verified: the exploit now 403s with zero rows inserted and the event stays 404, while legitimate base and per-occurrence RSVPs still work.
- **Closed an event-chat confidentiality leak (MEDIUM).** Two compounding gaps in the group-messaging add path: (1) any event-chat participant — including a plain yes-RSVP guest — could add an unrelated outsider, who was flagged `manual_add` and never removed by the roster sync; (2) newly-added group/event-chat members (both manual adds and RSVP-driven joins) inherited `cleared_before_id = 0`, so they could read the entire pre-join message backlog. Fixes in `www/messages_dl.php` and `www/_dm.php`: event-chat adds now require `can_manage_event()` (regular user-made groups still let any member add, as intended), and every newly-added member/joiner starts with a cleared watermark at the current tail — matching the existing 1:1→group conversion behavior — so nobody sees messages sent before they joined.
- **Hardening (LOW, defense-in-depth).** Added `www/uploads/.htaccess` disabling the PHP handler (`engine off` / `RemoveHandler` / `Require all denied` for script extensions) so user-uploaded files can never execute even if a non-image extension ever landed — verified a probe `.php` in `uploads/` returns 403. `csrf_verify()` now rejects a token-less session instead of matching two empty strings. `support_dl.php`'s screenshot-path regex uses `\z` so a trailing newline can't enter a stored path. `messages_dl.php` returns a uniform 404 (not 403) for any conversation you're not in, so event-chat conversation IDs can't be enumerated by status-code difference. The contacts CSV export prefixes cells starting with `= + - @` (and tab/CR) with a quote to neutralize spreadsheet formula injection. `event.php`'s JS `escH()` now also escapes single quotes. `admin_tickets.php`'s status filter uses a bound placeholder instead of `->quote()` interpolation. (A full four-dimension audit — access control, SQL injection, XSS, CSRF/upload — otherwise found the messaging/tickets/contacts/event surface clean: prepared statements throughout, escape-before-linkify in both PHP and JS DM paths, `javascript:`/`data:` never linkified, allowlist HTML sanitizer at store time, content-sniffed uploads with random filenames.)

### Added
- **Support ticket system.** A `/support.php` page (linked under Help → Support) where any user opens a ticket with a subject, description, and optional screenshot (uploaded with live preview), plus a "My tickets" list with open/resolved chips. Threads live on `support_ticket.php` with admin replies highlighted and tagged, screenshot thumbnails, and a reply box; a user reply on a resolved ticket reopens it. Admins get a Support tab (`admin_tickets.php`) with the full queue (open first, open-count header, All/Open/Resolved filters) and an Open/Resolved toggle on each thread. New tickets and user replies alert every admin (email carries the content, SMS link-only); admin replies alert the ticket owner link-only — all through the existing `notify_user_direct()` plumbing and the "Support tickets" mute category already in Settings. New `tickets` / `ticket_messages` tables; rate caps of 5 tickets/day and 20 replies/hour; screenshot paths validated against exactly what `upload.php` issues.
- **Image uploads now work for all logged-in users** (`www/upload.php`), fixing a latent bug where league managers' Jodit image uploads in posts silently 403'd under the old admin-only gate. Content-sniffed MIME, 8 MB cap, and random server-generated filenames are unchanged; non-admins get a 20-uploads/day cap counted from the activity log.

---

## [v0.2038] - 2026-07-20

### Added
- **Group messaging.** Conversations moved from a pair-per-row schema to a conversations + participants model (`dm_participants` carries each member's read pointer, cleared watermark, presence heartbeat, and alert stamp; one-shot migration rebuilds `dm_conversations` with FKs disabled — DROP TABLE would otherwise cascade-wipe `dm_messages` — and converts existing 1:1 threads' read history into per-member pointers). "New group" on the Messages page picks 2+ people (same scope rules as 1:1, 20-member cap, optional name); group threads show sender names on bubbles, a member list, "Add people" (any member may add anyone *they* could DM), and "Leave group" (last one out deletes it). New members join read-at-tail. Bell/chime fan out per member (watchers skipped, rapid messages collapse), and the cron unseen-sweep alerts each member individually ("You have 3 unread messages in <group>", link-only SMS, 30-min floor). Adding a third person to a 1:1 converts it to a group: the newcomer cannot see the prior private history and the pair is released for a fresh 1:1.
- **Event chat.** Every event page offers "💬 Event chat" to the host, managers, and approved yes-RSVPs — a group conversation bound to the event (`dm_conversations.event_id`, one per event). Membership follows the roster automatically and syncs on every open/send: RSVP yes joins (read-at-tail), no leaves, back to yes rejoins with history; waitlisted/pending stay out; managers persist without an RSVP; manually-added extras (marked `manual_add`) survive the sync and get their own "Leave chat". The chat is titled after the event (renames follow), tagged "Event" in the Messages list, and degrades to a normal group if the event is deleted. Host broadcasts ("Message guests") are unchanged — they still reach account-less invitees.

### Changed
- **The calendar popup is retired — one event view everywhere** (user-reported pathing confusion: the popup and `event.php` showed different information). Calendar clicks now navigate to the canonical event page, and every `calendar.php?open=` deep link (notification emails/texts, My Events, league pages, home feed, post-login redirects) server-redirects there, with `&edit=1` going straight to the editor. To make that possible, `event.php` absorbed full popup parity: the live manager roster panel (4s refresh via `event_invites_dl.php`) with per-invite delivery badges, Send Invitations / Send reminder banners, per-person Send/Resend/Retry, Pending Approval with Approve/Deny, Waitlisted and Declined sections, on-behalf RSVP dropdowns; the Message-guests composer (Jodit) with history + delete; Copy link; Delete; the admin walk-up QR (via `walkin_display.php`); waitlist position; and comment edit/delete (own/admin). The popup code remains dormant in calendar.php for a future cleanup pass.
- **Mobile pass over the event editor** (user screenshot showed the real flow problem: save buttons sat mid-form above the invite picker). On small screens the Add/Save/Cancel buttons move to a sticky bottom bar placed after the picker; the arrow-button dual-pane becomes tap-to-invite (tap a name to add, tap its ✓ row to remove); invited rows gained an explicit × everywhere (replacing invisible double-click); "+ Custom Invitee" became "+ Add Name" living in the Invited pane header; the color dot moved to the page header beside the title; "Send reminders" collapsed into a summary-button dropdown; and Waitlist/Approval/Hide-guests/Max-guests folded into one "Guest options" dropdown with plain-english explainers and an active-count badge (Poker and Reminders stay as toggles).
- **Event page mobile cleanup.** The six stacked manager buttons collapsed to Edit + Manage Game + a "More" menu (Duplicate, Polls, Copy link, QR, and Delete isolated at the bottom). "My RSVP" grew from a tiny dropdown into three big color-coded Yes/Maybe/No buttons (tap the active one to clear). "Also remind me" became a stateful toggle: the closed control summarizes what's scheduled ("3 hr, 1 day" or "None"), scheduled times show "✓ set" and picking one again removes it (new `self_reminder_remove` action, added to the self-service permission allowlist), sent reminders lock as "— sent". The My Events "Past" section became an obvious button-style bar with a Show/Hide pill (a user didn't know it expanded).

---

## [v0.2037] - 2026-07-19

### Changed
- **A registered user's own contact preference now always wins over the owner's per-contact invite channel** (user request, closing the loop on v0.2036's `invite_via`). Delivery: `guest_invite_channel()` in `www/_notifications.php` now joins the linked account and returns that user's `preferred_contact` — including `both`, `whatsapp`, and `none` (opted out, nothing sends) — with fallbacks when the invite row lacks the needed address (prefers-text-but-no-phone falls back to email and vice versa); this covers invites created under a contact's address-book name rather than their username, which previously took the guest path and used the owner's choice. UI: the contacts list's "Invite via" column shows the linked account's actual setting with a "(theirs)" marker, and `contact_edit.php` replaces the Email/Text dropdown with a read-only "(their setting)" field plus an explanation for linked contacts — while quietly preserving the owner's stored `invite_via` on save so it resumes if the account is ever deleted. Owner choice still governs contacts without accounts, unchanged.

---

## [v0.2036] - 2026-07-19

### Added
- **Private messages.** Users can now DM each other: a Messages page (nav link with live unread badge) lists conversations, and a thread page shows chat bubbles with Enter-to-send, clickable links (escape-first linkify in both the PHP render and the live-append path; `<script>`/`<img onerror>` probes verified inert), and a per-side "Delete conversation" that hides history for you without touching the other person's copy. Who can start a conversation is scope-checked in `_dm.php` (`dm_can_initiate`): shared league, shared event (including host↔guest via `events.created_by`, since hosts have no invite row to their own event), a linked contact in your address book, or either party being an admin — and replying inside an existing conversation is always allowed. New tables `dm_conversations` (pair-per-row, per-side cleared watermarks and alert/presence stamps) and `dm_messages`; rate caps (60 msgs/hour, 10 new conversations/day, 4000 chars) with friendly errors.
- **Notification behavior tuned for chat: always audible in-app, never spammy outbound.** Every incoming message rings a soft two-note WebAudio chime and updates badges — the thread page appends bubbles within ~4s, the Messages list self-refreshes, and a site-wide 15s poller in `_footer.php` (backed by new `notify_status.php`) updates the nav bell and Messages badges in place on any page (audio unlocks on first click per browser autoplay rules; the original sync-check-after-async-resume bug that silenced it is fixed). Email/SMS/WhatsApp alerts go ONLY through a cron sweep (`dm_alert_drain`) for messages still **unread 10+ minutes after arriving** — read it on the site and no external alert ever fires. One link-only alert per conversation side (never message content in SMS, no reply-by-text since the webhook parses replies as RSVPs), a 30-minute floor between alerts, never re-alerting the same messages, and a new "Direct messages" mute in Settings (plus a "Support tickets" category wired for the upcoming ticket system). Shared plumbing: `notify_user_direct()` in `_notifications.php` for non-event notifications.
- **Per-contact invite channel** (user-reported: "if there is an email and a phone number, it invites to the email"). Contacts gained an `invite_via` setting (Email or Text) shown in the list and editable on the new edit page; the notification dispatcher honors it for guest invitees via `guest_invite_channel()`, with sane fallbacks (email if present, else SMS) — and once a contact registers, their own account preference takes over.

### Changed
- **Contacts page redesigned** (user feedback: the Save/Message/× button pile was "very messy"). The list is now a clean read-only table — status, name, email, phone, invite-via, notes, and a single Message button in its own column — and clicking any row opens a dedicated `contact_edit.php` page with all fields, one explicit Save, Cancel, and Delete. The invisible per-cell autosave grid is gone entirely.

### Fixed
- **Contact phones no longer vanish** (user-reported: phone numbers "stored" but were gone after leaving the page). Root cause: the old grid saved a cell only on blur, and navigating away killed the save request mid-flight. The new edit page saves the whole row in one validated request (`save_row` in `contacts_dl.php`), and remaining background fetches use `keepalive`. This also fixes the follow-on report that removing an email errored "must have a phone number or email" despite a phone being on file — the phone had simply never persisted; clearing one channel with the other present now works (clearing both is still refused).
- **Message button no longer offered for un-messageable contacts** (user-reported "Unknown user"): your own contact card (self-linked via your own email in your address book) no longer shows Message, contacts whose linked account was deleted fall back to Pending (cron now heals such stale `linked_user_id` rows), and hand-typing your own thread URL redirects to the Messages page instead of erroring.

---

## [v0.2035] - 2026-07-18

### Fixed
- **The tournament timer accepts fractional blinds** (user-reported: low-stakes structures like .25/.50 were rejected). The blind editor's SB/BB/Ante inputs gained `step="any"`, the editor's collect path and every render path (main timer display, next-level preview, the remote view, and the Chromecast receiver in `cast_receiver.php`) parse blinds with `parseFloat` instead of `parseInt` (which silently truncated .25 to 0), the level/preset save paths in `timer_dl.php` cast blinds to float (SQLite stores them natively, no migration), CSV import round-trips decimals, and the structure generator seeds from fractional levels without collapsing to its 100-chip fallback. Fractional values render poker-style with two decimals ("0.25 / 0.50"); whole-number and K/M chip formatting is unchanged, and level durations deliberately remain whole minutes.

---

## [v0.2034] - 2026-07-18

### Added
- **Notification center.** Members finally get control over, and a record of, what the app sends them. Three pieces: (1) an **in-app inbox** — new `user_notifications` table written by the dispatcher for every notification to a registered recipient, a 🔔 bell with an unread badge in the nav (desktop + mobile menu), and a `/notifications.php` history page (newest first, unread highlighted, rows link to their event, opening marks all read; pruned at 90 days by cron); (2) **per-type preferences** — a "Notifications" card in My Settings with seven category toggles (invitations/nudges, reminders, event changes, comments, approvals/waitlist, host messages/polls, RSVP replies for hosts) stored as `users.notify_prefs` JSON; muting a category stops email/SMS/WhatsApp for it while the inbox still records everything, so history stays complete and WhatsApp users gain a fallback when the session is down; (3) **member-set reminders** — an "Also remind me" picker (1h/3h/12h/1 day before) on the event page's RSVP area, queuing a personal reminder through the standard queue and `reminder_<offset>` dedup so it can never double a matching host reminder, with past times refused.

### Fixed
- **Check-in could silently lose buy-ins — a real game's pot ran $40 short** (user-reported after a live tournament; two RSVP-no players who showed up anyway had disabled buy-in checkboxes with no explanation, were skipped, and later accrued add-ons the pool never counted). Three-part fix in `www/checkin.php` / `checkin_dl.php`: (1) money controls are never disabled — buying in an RSVP-no player works and corrects their RSVP to yes, and buying in a pending walk-in auto-approves them (host taking money is approval intent; logged as "Auto-approved by buy-in", still queues the seat notification); (2) **bulk Buy In now means "ensure bought in"** (`set=1` on `toggle_buyin`) so re-running it can never toggle an already-entered player off, and bulk actions report their results — any skipped player appears in a "Some players were skipped" dialog with the reason instead of vanishing silently; (3) a **pot-integrity warning** on the Payouts card names any player with rebuys/add-ons or a check-in but no recorded buy-in ("the pool may be short") — the tripwire that would have caught this at payout time.

---

## [v0.2033] - 2026-07-15

### Fixed
- **Waitlist notifications now carry working no-login links** — the follow-up sweep after v0.2032's reminder fix, auditing every outbound message for the same bug class. Two more cases in `www/_notifications.php`: `waitlist_promoted` ("a seat opened up, you're in!") linked to the login-gated event page even though waitlisted players can be walk-ins with no account, and `rsvp_deadline_demoted` ("you can still RSVP") shipped an RSVP call-to-action with **no link at all**. Both now use the recipient's tokenized event page (via the v0.2032 helpers), which shows waitlisted invitees their honest status and takes an RSVP without login. Also audited and deliberately unchanged: comment notifications stay login-gated because comments are an account-only feature and the token page can't display them. With this, every message that asks a guest to act includes a working, no-login way to act.

---

## [v0.2032] - 2026-07-15

### Fixed
- **RSVPing from a reminder no longer requires logging in** (user-reported: invites worked without login, reminders didn't). The notification builder (`www/_notifications.php`) had two generations of link code: invites and nudges used the recipient's `rsvp_token` for one-click no-login links, while reminders and "event updated" messages still used the shared event URL — which, since v0.2026's canonical event page, is login-gated. Reminders now carry the same one-click Yes/No/Maybe links as invites (SMS and email), with the View Event button pointing at the recipient's token page; "event updated" messages got the tokenized view link too. Legacy invitees without a token keep the previous behavior. The token lookup and button/link building were extracted into shared helpers (`_invitee_rsvp_token()`, `_rsvp_buttons_html()`, `_rsvp_links_sms()`) now used by all four message types, so future notification types can't reintroduce the bug by forgetting the token. Verified end-to-end logged out on dev: reminder YES link → confirm screen (no login) → RSVP recorded.

---

## [v0.2031] - 2026-07-15

### Added
- **Walk-in arrival alerts on the check-in screen.** QR self-registrations used to slide into the roster silently on the next poll — at a busy table the host never noticed. The check-in page (`www/checkin.php`) now tracks known pending player ids and, when a new one appears, shows a bottom-center toast naming the arrival ("🚶 Wendy just checked in via QR — awaiting approval") and plays a short two-tone chirp (WebAudio oscillator, no asset; degrades to toast-only until the browser unlocks audio). Alerts fire even while the host is typing in a roster field (the focus guard only skips the re-render, not the alert) and never fire for pendings that pre-date opening the page.
- **Approve all.** With 2+ walk-ins waiting, an amber banner appears under the stats row with an Approve all button: one confirm, then sequential approvals — each rides the existing `poker_approved` flow, so every guest still gets their table/seat notification. Banner clears itself when the pending queue empties; single pendings keep the per-row Approve/Deny.

### Changed
- The audit item "the walk-in guest never learns their seat" turned out to be already solved: approval auto-assigns a table/seat and the `poker_approved` notification includes it. Verified rather than rebuilt.

---

## [v0.2030] - 2026-07-15

### Added
- **WhatsApp (WAHA) session watchdog.** Root-caused the multi-day WhatsApp outage: WAHA never reconnects a FAILED session ("do not reconnect the session"), the `start` endpoint rejects FAILED sessions with 422 "already started" (so both the admin Start button and any recovery loop spun uselessly for days), and nothing alerted anyone. New `waha_watchdog()` in `www/sms.php`, run from cron every 5 minutes: probes the session and stores status + timestamp; **auto-restarts FAILED sessions** via the correct `POST /api/sessions/{s}/restart` call, rate-limited to once per 10 minutes (transient connection drops now self-heal); and **emails every admin** when the session is non-WORKING for 2+ consecutive checks (~10 min), deduped to once per 24h, with status-specific instructions (SCAN_QR_CODE → re-scan steps; FAILED → auto-restart is running, re-link if it persists; UNREACHABLE → check the container). It deliberately does not restart SCAN_QR_CODE (needs a human) or STOPPED (may be a deliberate stop), is self-arming (only alerts/restarts after the session has been seen WORKING once, so installs without WhatsApp stay silent), resets its counters and logs recovery when the session comes back, and can be disabled with site setting `waha_watchdog = 0`.
- **WhatsApp status card on the admin Activity tab.** Shows the watchdog's last recorded session status (green WORKING / red otherwise) with "checked Xm ago", fed from stored state so the 30-second snapshot poll never blocks on a WAHA probe. Hidden until the first probe.

### Fixed
- **The WhatsApp Start button can now recover a FAILED session.** `admin_settings_dl.php`'s `waha_start` probes the session first and issues `/restart` for FAILED/STARTING sessions instead of the legacy `start` call that returned 422 forever.

### Infrastructure
- **Incident notes (2026-07-15):** production WhatsApp had been down for days — WhatsApp had revoked the linked device server-side, so every login was rejected in ~1.5s regardless of restarts. Recovery: WAHA image updated 2026.3.4 → 2026.7.1 (ruling out a client-version rejection; session data backed up to `/root/waha_data_backup_20260715.tgz` first), then logout + fresh QR re-link. The watchdog exists so the *silent* part of this failure mode can't recur.

---

## [v0.2029] - 2026-07-15

### Added
- **Per-invitee delivery status on the event roster.** Hosts previously saw only a "sent" flag that really meant "queued" — a bounced email looked identical to success. The invites panel now shows each invitee's actual outcome, live on the modal's poll: green ✓ (handed to the provider), blue "⏳ sending" (queued or parked in the email retry queue), or red "✗ failed" with the provider's error on hover; a failed invitee's button becomes **Retry**, which also clears the dead queue row so the fresh attempt isn't shadowed by the old failure. Powered by new `event_id`/`username` correlation columns on `sms_log` and `email_retry_queue` (migrations in `db.php`), populated via a dispatch-scoped context (`notif_log_context()` in `db.php`, set around the send in `_notifications.php`, consumed by `sms.php`'s `sms_log()` and `mail.php`'s `_log_email()`/retry enqueue, and restored when a parked email retries later). Sends outside the dispatcher log with NULLs exactly as before; notifications older than this release show no badge.
- **Filterable notification log, now host-accessible.** `sms_log.php` gained a filter row (status, channel email/SMS/WhatsApp, recipient search across name/email/phone, date range), Recipient and Event columns (event links to the event page), and filter-preserving pagination. Non-admin event managers can now view the log scoped to an event they manage (`?event=N` — enforced via `can_manage_event()`; anything else is 403, and Clear Log stays admin-only). The roster's "N invitations could not be sent" banner links straight to the event's failed-only view.
- **Delivery health signals on the admin Activity tab.** A "Failed · 24h" stat card (red when nonzero), a red banner when notification sending is rate-limit paused (previously only visible in cron stdout via `notification_drain_paused_until`), and an amber banner counting queue rows that exhausted their 3 retries, linking to the failed-log view. All included in `admin_activity_snapshot()` and refreshed by the tab's 30-second poll.

### Fixed
- **The event modal's "immediate first poll" actually fires now.** v0.2027 added an instant `pollRsvps()` call on modal open, but `viewEvent()` started the poll before adding the modal's `open` class, so the guard silently discarded it and fresh roster/queue data still waited a full 4-second tick. Polling now starts after the modal opens, so RSVP, queue, and delivery state appear immediately.

---

## [v0.2028] - 2026-07-14

### Added
- **League stats overhaul: cash games, guests, and money.** The leaderboard (`league.php` stats tab) previously counted only finished tournaments played by registered users and knew nothing about money. It now aggregates every finished game: cash games contribute buy-in vs cash-out, tournaments contribute buy-ins/rebuys/add-ons vs the payouts recorded since v0.2024, and guests/walk-ins are included via the `g_<name>` player key (tagged "guest"). New columns: Net (signed, colored) and ROI, plus ITM% (in-the-money tournament finishes); placement stats stay tournament-only and show em-dashes for cash-only players. A **3-game minimum** now gates ranking — under-threshold players list greyed and unranked so a one-game winner can't top a 20-game regular — and a **Rank by: Score / Net $ / Wins** toggle picks the ordering. The "My Stats" card gained Net, ROI, and ITM tiles.
- **Per-player game history.** New `league_player.php` — click any leaderboard name for that player's summary tiles (games, wins, ITM%, net, ROI, avg score) and a game-by-game table: date, event (linked to the event page), type, finish/field size, money in/out, net, and score. Respects the leaderboard's active date range or season, works for guests, and is access-gated like the stats tab (members-only for hidden leagues).
- **Seasons.** New `league_seasons` table (league_id, name, start/end dates; migration in `db.php`). League owners/managers get a "Manage seasons" panel on the stats tab (add with name + date window, delete with confirm — deleting only removes the window, never game data). Seasons appear as an optgroup in the Range dropdown; selecting one filters standings to its window and shows a season banner. Once the end date passes, the banner crowns the **champion**: the top qualified player by average score (the league's standard ranking, regardless of the current rank-by toggle), with wins and net shown; a completed season where nobody hit the game minimum says so instead.

### Infrastructure
- **Historical payout backfill.** Tournaments finished before v0.2024 never had per-player payouts stored, so their money stats would read $0. At deploy, `pk_apply_tournament_payouts()` is run once over every finished tournament session to recompute payouts from each session's payout structure and final standings.

---

## [v0.2027] - 2026-07-14

### Added
- **Tournament timer: estimated finish time.** A new "Ends: ≈ 11:40 PM" stat in the info bar, computed client-side from the remaining structure (current level's remainder plus every later level's duration, breaks included — `computeTotalRemainingSeconds()` in `timer.php`). Shown only while the clock runs, since a paused estimate drifts. Registered as a first-class theme element (`ends_at`): recolorable, rescalable, repositionable, and hideable in the theme editor like the other stats; existing saved themes are unaffected (all hooks are guarded).
- **Tournament timer: break wall clock.** During a break with the clock running, the big center line now reads "Until 9:15 PM" (viewer's local time) instead of the static "Break Time", which returns when paused. No countdown math from across the room.
- **Tournament timer: undo.** A ↺ Undo button in the host controls reverses the last timer action — skip, ±time, reset level/timer, or play/pause. One level deep: each mutating command snapshots the pre-command live state into a new `timer_state.prev_state` JSON column (migration in `db.php`), and `undo` in `timer_dl.php` restores and consumes it (no redo ping-pong); snapshots expire after 10 minutes. Command errors on the timer now surface as pk-dialogs instead of dying in the console, so a second undo visibly says "Nothing to undo."

### Fixed
- **Post-save now lands on the event page, fixing the cross-timezone auto-open miss.** Saving an event (editor page or calendar modal) previously redirected to `calendar.php?open=ID&date=<site-tz date>`; for a user whose personal timezone shifts the event onto a different viewer-tz calendar day, the auto-open silently found nothing (the v0.2026 known issue). Both save paths (`event_edit.php`, `calendar.php`) now redirect to the canonical `event.php?id=` page — which has the roster, Send Invitations banner, and delivery-status line — and the event page renders the "Event saved" flash. Verified headless against the exact previously-broken flow (UTC viewer, site tz America/Chicago, a 00:30 start time).

---

## [v0.2026] - 2026-07-14

### Added
- **Event location field with maps link.** Events finally have a structured venue/address (`events.location`, 200 chars): a Location box in the editor's top bar (`event_edit.php`, saved via `_event_save.php`), shown with a 📍 and a no-API-key "Open in Maps" Google link in the calendar modal, the public invite page, and the new event page, and included in invite and reminder messages ("Where: …" in SMS, a linked line in email). The REST API (`api/v1/events.php`) accepts and returns `location` on create/update/list/get for the Bayou Burro site.
- **Add-to-calendar: .ics download + Google Calendar link.** New `www/ics.php` emits a spec-correct VEVENT (site-tz wall clock converted to UTC, RFC 5545 line folding that never splits a UTF-8 character, all-day support, 3-hour default duration when no end time) or 302s to a prefilled Google Calendar template with `&google=1`. Auth is two-mode: `?token=<rsvp_token>` serves invitees with no login (same trust model as event.php), `?id=` uses the calendar's own `event_visibility_sql` view rule for logged-in users. Links appear in the calendar modal, the public invite page, invite emails, and the new event page.
- **Duplicate event.** A Duplicate button in the event modal (and on the event page) opens `event_edit.php?copy=<id>` — the ADD form prefilled with the source's title, description, location, times, color, poker config, reminder settings, visibility, and full invite list, with all RSVPs cleared and the date blank so the host must pick one. Manage-gated on the source event; saving creates a brand-new event with fresh RSVP tokens. Kills the re-type-everything ritual for recurring game nights.
- **Nudge non-responders.** When invites went out but people haven't answered, the modal roster shows "⏰ N invited, no response yet" with a Send reminder button. The new `nudge_nonresponders` action (calendar.php) queues an `rsvp_nudge` notification — a friendly "Still deciding?" with the same one-click tokenized RSVP buttons as the invite — deduped to one per recipient per day via an `rsvp_nudge_<date>` marker so re-clicks are harmless and the host can chase remaining stragglers on a later day. Nudges ride the live delivery-status line (its queue counts now include both types).
- **Guest count and capacity for non-poker events.** New optional `events.max_guests` cap with a "Max guests" editor field (non-poker only; setting it reveals the Waitlist toggle). The modal meta line now shows "N going" (or "N/cap going") for every non-poker event — previously only poker events had any headcount. The public invite page shows a going/maybe count even when `hide_guest_list` hides the roster. `maybe_promote_waitlisted()` (db.php) was generalized: capacity comes from seats×tables for poker and `max_guests` otherwise, and `_event_save.php`'s save-time over-capacity waitlisting now applies to both kinds.
- **Canonical event page.** `event.php?id=<id>` is now a real server-rendered page for logged-in users (the token-based invitee page is unchanged): title, league badge, viewer-tz date/time, location, add-to-calendar, description, live "going" count, RSVP dropdown (or Sign up / Leave, wired to the existing calendar.php actions), who's-coming lists (headcount-only when the guest list is hidden), comments with a plain-form post that redirects back, and manager shortcuts (Edit / Duplicate / Manage Game / Polls / open-in-calendar). The modal's copy-link button and the central notification URL builder (`_notifications.php`) now point here instead of the fragile `calendar.php?m=&open=&date=` deep link, and the page handles its own login redirect so emailed links survive. Invite management deliberately stays in the calendar modal and editor.

### Fixed
- **Notification links survive month/date drift.** All event notification links previously deep-linked into the calendar with month + date parameters that could miss (and always dumped comment-notification readers on the calendar to re-find the event). They now land on the canonical event page.

### Known issue (pre-existing, unchanged)
- For a user whose personal timezone differs from the site timezone, the post-save redirect's auto-open can silently miss (site-tz date in the URL vs viewer-tz day bucketing in the calendar grid). Surfaced during cross-timezone testing of this release; the canonical event page is immune, so a future fix can simply redirect there after save.

---

## [v0.2025] - 2026-07-13

### Added
- **Live invite delivery status in the event modal.** After "Send Invitations" or "Save & Send", messages go into `pending_notifications` and drain in the background — but nothing told the host that, which invited "do I need to press it again?" double-sends. `www/event_invites_dl.php` now returns per-event invite queue counts (pending / dispatched / failed, derived from `attempted_at`/`attempts` state), and the calendar modal's invites panel — which already polls every 4 seconds — renders a status line from them: blue "N invitations queued — sending now… (updates automatically)" while draining, green "All queued invitations were sent" once this modal session watched a queue drain, and red "N could not be sent after several retries" pointing admins at the Notification Log when a row exhausts its 3 attempts. The Send button's confirmation bar and the post-save flash messages now say "queued — delivery runs in the background" instead of the misleading "sent", the panel state appears instantly on click (optimistic, corrected by the next poll), and the modal now fires its first RSVP poll immediately on open instead of 4 seconds later.
- **`pkBusy(btn, promise)` helper in `www/pk-dialogs.js`.** Disables a button (with `aria-busy` and dimming) while an async action is in flight and restores it when the promise settles — double-submit protection plus slow-network feedback. Adopted on the event modal's comment Post, Sign up to attend, and Leave this event buttons; the Send Invitations and Send message buttons already had hand-rolled equivalents.

### Fixed
- **User-initiated AJAX failures are no longer silent.** Ten `.catch(){}` blocks swallowed network errors with zero feedback: comment post/edit/delete, member RSVP change, host on-behalf RSVP, event sign-up, and leave-event in `www/calendar.php`; the contact-list refresh in `www/event_edit.php`; and the timer's player-panel actions in `www/timer.php` (`ppPost`, which also ignored `ok:false` server responses — those now surface the server's error text). Each now shows a specific pkAlert. The 8 remaining silent catches are deliberate: background polls (check-in, calendar invites, timer state, admin activity snapshot, cast receiver) and autoplay/wake-lock guards, where an alert per dropped poll would be noise.
- **Pinch-zoom works again on the timer and walk-in QR pages.** `www/timer.php` and `www/walkin_display.php` shipped `maximum-scale=1.0, user-scalable=no`, blocking zoom for low-vision users. Both now use a standard viewport (timer keeps `viewport-fit=cover`); iOS input auto-zoom stays prevented by the existing mobile `font-size:1rem` input rule in `style.css`.
- **Stale CSS after deploys.** All 44 `style.css` references now carry `?v=<APP_VERSION>` (the pattern the JS files already used), so browsers pick up new styles on the release that follows this one without a hard refresh.

### Changed
- **Vendor scripts no longer block first paint.** `vendor/jodit/jodit.min.js` (calendar, league, admin_posts) and `vendor/qrcode.min.js` (calendar, mfa_setup, walkin_display) now load with `defer`. Three pages invoked them at parse time and had their init moved into `DOMContentLoaded` handlers: the admin Posts editor setup (`admin_posts.php`, with `editor` hoisted), the MFA-setup QR draw, the walk-in display's first `renderQR()`, and league.php's edit-mode post editor. `timer.php`'s qrcode/nosleep tags were deliberately left alone: they already sit after the main markup, and deferring NoSleep would silently disable its wake-lock fallback behind the `typeof` guard.
- **Bigger touch targets.** The comment delete control is now a centered 36px hit area instead of a bare 0-padding glyph, the mobile nav hamburger has a 40px minimum, and `.btn-sm` gets a 38px min-height on mobile (`www/style.css`).

---

## [v0.2024] - 2026-07-13

### Security
- **Timer control now requires manage rights on the event; the remote link and QR are view-only.** Previously any logged-in invitee who opened `timer.php?event_id=` got full control buttons, and the `?view=remote&key=` link granted control to any logged-in user with event access — so a table full of guests scanning the display QR could pause the clock or skip blind levels. Control (play/pause, skip, add/sub time, resets, plus every mutating action routed through `resolve_timer_from_post()` in `www/timer_dl.php`: presets, levels, sounds, themes) is now gated on `can_manage_event()` — creator, per-event manager, league owner/manager, or site admin. Viewing via the key is unchanged, guest standalone timers are unchanged, and hosts keep full control on both the main page and the remote view.

### Fixed
- **Check-in roster now syncs across devices.** The 10-second auto-refresh in `www/checkin.php` only re-rendered when the player *count* changed, so a rebuy, cash-out, elimination, or seat move recorded on a second device (or by a co-host) never appeared on the first screen. The poll now diffs the full players/payouts/session payload against local state and re-renders on any change. A new `rosterEditInProgress()` guard skips the re-render while the host is actively typing in a roster/payout field so the update can't steal focus mid-edit; the change lands on the next poll after blur.
- **Tournament winnings are actually recorded.** `poker_players.payout` existed but nothing ever wrote it — elimination showed "owed $X" on screen while storing $0, so there was no durable record of who won what. New `pk_apply_tournament_payouts()` in `www/_poker_helpers.php` recomputes every player's payout (in cents, from the session's percentage structure and current pool, keyed by `finish_position`) and is invoked from `www/checkin_dl.php` on elimination, un-elimination (cleared positions revert to $0), payout-structure edits, auto-finish (last player standing), and manual finish via `update_status`. The check-in UI (`www/checkin.php` desktop rows, mobile cards, winner modal) now prefers the recorded amount over the client-side projection.
- **League invite pages render correctly on phones.** `www/join_league.php` and `www/league_invite.php` — the two pages people most often open from a shared link on a phone — had no `<meta viewport>` tag and rendered at desktop scale. Both now carry the standard viewport meta.

### Added
- **Cron heartbeat with real status on the admin Cron tab.** The Cron tab's green "Scheduled tasks are active" only checked that a `cron_token` string existed — if the container's background scheduler died, reminders silently stopped with no admin-visible signal. `www/cron.php` now writes a `last_cron_run_ts` heartbeat (via `set_setting`) at the start of every run, before any work, and the Cron tab in `www/admin_settings.php` shows a status banner at the very top of the tab: green with the last-run time when fresh, amber if cron has never run, red with the age when the last run is more than 15 minutes old (expected cadence is 5 minutes).

### Changed
- **Dead recurrence machinery removed.** Recurrence was dropped from the schema and editor long ago, but its scaffolding lingered and looked alive. Removed: `get_next_occurrence()` (no callers), the `load_exceptions()` stub and its unused `$exceptions` parameter on `build_event_by_date()` in `www/db.php`, `get_occurrence_invitees()`, the `delete_occurrence` handlers in both `www/calendar.php` and `www/calendar_dl.php` (the triggering form was permanently hidden by JS), the never-called `cancel_series`/`uncancel_series` handlers in `calendar_dl.php` (which wrote to the long-removed `events.cancelled_from` column and would have fataled if ever invoked), the hidden delete-occurrence form, and the always-empty `vRecurr` line in the event modal. The `event_exceptions` table and `event_invites.occurrence_date` column remain for legacy rows and delete cascades still clear them; `event_edit.php`'s unlinked `occ=` parameter degrades safely and was left for a future cleanup.

---

## [v0.2023] - 2026-07-11

### Added
- **Event comments now notify the event circle.** Posting a comment on an event previously saved silently — nobody found out until they next opened the event page. Now `www/comment.php` queues an `event_comment` notification (new type in `www/_notifications.php`) to the event owner, every per-event manager, and approved invitees who RSVP'd yes or maybe — never the commenter themselves, and only registered users (custom invitees without accounts have no page to read the thread on). Delivery rides the existing `pending_notifications` queue, so it respects each recipient's preferred channel (email/SMS/WhatsApp), the site-wide `notifications_enabled` switch, and the per-recipient daily cap; the sender side is already bounded by the hourly comment cap. Email gets the full comment as a quoted block plus a View-event button, SMS gets a 120-char snippet + link, WhatsApp gets the full text. The dispatcher re-fetches the comment at send time, so a comment deleted before the queue drains is silently dropped, and dedup is per comment per recipient (`event_comment_<comment_id>`). Comments on posts remain silent by design.

---

## [v0.2022] - 2026-07-03

### Added
- **5h option in the event Duration dropdown.** The Duration picker in `www/event_edit.php` skipped from 4h straight to 6h, so a host running a five-hour event couldn't select it. Added `<option value="5">5h</option>` between 4h and 6h. Duration is client-side only (the submit handler computes `end_time` from `start_time` + the selected hours, and the edit-prefill matches the option from the start/end diff), so 5h works for both creating and editing with no server-side change.

---

## [v0.2021] - 2026-07-02

### Fixed
- **Admin/host text commands (ADMIN, PENDING, APPROVE, WHO, MSG, REMIND, CANCEL, CONFIRM) now work over WhatsApp, not just SMS.** Event owners/managers and site admins could run their events by text on SMS, but the WhatsApp inbound handler (`www/wa_webhook.php`) never invoked the admin-command layer, so those users only ever saw the basic RSVP/EVENTS/HELP replies on WhatsApp. `wa_webhook.php` now loads `sms_admin.php` and dispatches `sms_handle_admin_command(..., 'whatsapp', ...)` right after the user lookup (and the lookup now selects `role`, which the elevation check needs) — mirroring `sms_webhook.php`. Non-elevated users and non-admin verbs fall through to the normal handling. To make replies deliver on the right channel, `respond_to_provider()` was moved out of `sms_webhook.php` into the shared `www/sms.php` (both webhooks and `sms_admin.php` already depend on it) and given a `whatsapp` case that sends via `send_whatsapp()`. No behavior change for SMS.

---

## [v0.2020] - 2026-07-02

### Fixed
- **WhatsApp/SMS `EVENTS` and `STATUS` commands always replied "you don't have any upcoming events."** In `www/wa_webhook.php` the `$today` date (used to filter out past events) was defined further down the file, *after* the `EVENTS`/`STATUS` command handler that references it. So that handler ran with `$today` unset (null), and the query `... AND e.start_date >= NULL` matched zero rows for every user — even those with upcoming invites. Moved the `$tz`/`$today` definition up above the command handlers (right after the RSVP keyword map) so all three queries that filter on it (`EVENTS`/`STATUS`, the approved-invites fetch, and the pending count) use a valid date. Verified against live data: a user with an approved upcoming invite now correctly gets the numbered event list back.

---

## [v0.2019] - 2026-07-02

### Fixed
- **WhatsApp notifications no longer fire into a dead session or get silently dropped on failure.** After a WAHA WhatsApp session flapped into a `FAILED` state (a corrupted NOWEB app-state sync), outbound sends were rejected by WAHA with HTTP 422 (`Session status is not as expected... expected WORKING`) or 500, yet the notification queue still marked each as delivered and never retried, so those notifications were lost even after the account was reconnected. Three changes harden this path: (1) `send_whatsapp()` in `www/sms.php` now pre-checks the session via a new `waha_require_working_session()` helper (result cached ~15s so a bulk drain probes once, not per recipient) and returns a clear `WhatsApp session not connected (status: …)` error instead of firing into a dead session; (2) `dispatch_queued_notification()` in `www/_notifications.php` now treats a WhatsApp-only failure as retryable — it skips the `event_notifications_sent` dedup marker and returns `false` so `cron_drain` re-attempts the row (capped at 3 attempts) once the session recovers. This is scoped strictly to `preferred_contact = 'whatsapp'` users, for whom WhatsApp is the only channel attempted, so no already-succeeded email/SMS is ever re-sent. (3) `www/admin_settings_dl.php` no longer registers a redundant per-session WAHA webhook that omitted the security token and was rejected 403 by `wa_webhook.php` on every inbound message; inbound replies continue to arrive via the WAHA-level `WHATSAPP_HOOK_URL` webhook, which carries the required token.

---

## [v0.2018] - 2026-07-02

### Added
- **Event entries in the admin activity log are now clickable, jumping straight to the event.** In Site Settings → Logs, an admin reviewing the audit trail previously saw event actions as inert text (e.g. `edited event id: 42`). The Logs tab (`www/admin_settings.php`) now turns any `event id: N` reference into a link to that event's editor (`/event_edit.php?id=N`), covering edits, RSVPs, invites, sign-ups, and occurrence/series changes. To make freshly created events clickable too, the create log line in `www/_event_save.php` was changed from `created event: <title>` (which recorded no id) to `created event id: N (<title>)`, so it carries the id the linkifier keys on. Only events that still exist are linked: the render collects the ids referenced on the visible page and runs a single `SELECT id FROM events WHERE id IN (...)`, so a log row for a since-deleted event stays plain text rather than pointing at a 404. Action text is HTML-escaped before the anchor is inserted, so no log content can inject markup. Note: pre-existing `created event: <title>` rows already in the log remain plain (their id was never recorded), and deleted-event rows stay plain by design.

---

## [v0.2017] - 2026-07-01

### Fixed
- **Calendar no longer serves a stale, wrong-timezone render from cache.** The calendar renders every event time in the viewer's timezone, so a page held in the browser's HTTP cache or back/forward (bfcache) could show times computed under a previous timezone — e.g. briefly displaying an event at the wrong hour right after a user changed their profile timezone, until a manual reload. `www/calendar.php` now sends `Cache-Control: no-store, no-cache, must-revalidate` (plus `Pragma: no-cache`); `no-store` also opts the page out of bfcache in modern browsers, so the calendar always re-renders fresh against the current timezone.

---

## [v0.2016] - 2026-07-01

### Fixed
- **Calendar now places events on the correct day for the viewer's timezone (fixed events showing on two days).** A user reported that a 9:00 PM–midnight event appeared on both the 16th and the 17th. Events are stored as wall-clock in the site timezone (America/Chicago), but `build_event_by_date()` in `www/db.php` bucketed each event into calendar day cells using its raw stored `start_date`/`end_date` strings — ignoring `start_time`/`end_time` and doing no timezone conversion (its viewer-tz argument was inert on a bare `Y-m-d`). So a near-midnight event whose stored `end_date` had rolled to the next day (e.g. `21:00`→`00:00`, `end_date = start_date + 1`) was drawn on both consecutive days, even though in the viewer's own timezone it falls entirely on one day. `build_event_by_date()` now interprets each timed event's start/end in the site timezone, converts to the viewer timezone, and buckets by the day(s) it actually occupies there — treating an end at exactly `00:00` as ending on the prior day (matching the crossed-midnight handling already in `event_public_time_labels()`). All-day events keep their raw date-range behavior. This one helper feeds the calendar month view (`calendar.php`), the week view, and the landing-page Upcoming Events strip (`index.php`), so all three are corrected together. A viewer east of the site tz will still correctly see a late-night event span two days when it genuinely crosses midnight in their zone.

---

## [v0.2015] - 2026-07-01

### Added
- **"+ Add Event" button on the landing page's Upcoming Events card.** Creating an event previously required navigating to the full Calendar page; the dashboard's Upcoming Events card now carries the same affordance, so users can start a new event from the landing page. The button sits in the card header next to the "Full Calendar →" link (`www/index.php`), styled as the compact `btn btn-primary btn-sm` and linking to `event_edit.php` in new-event mode. It is shown only to users who may create events, reusing the same gate as the calendar (`$canCreateEvents = $isAdmin || ($user && allow_user_events)`), and `event_edit.php` still enforces that permission server-side. Also scoped the card header's text-link CSS to `a:not(.btn)` so the link styling no longer recolored the button's white label to blue (which made the text invisible until hover).

---

## [v0.2014] - 2026-07-01

### Security
- **Security hardening sweep — closed the remaining low-severity findings from the full-codebase audit.** A batch of defense-in-depth and abuse-resistance fixes, each verified end-to-end on the dev server:
  - **TOTP codes are now one-time-use per time step (`www/totp.php`, `www/login.php`, `www/mfa_challenge.php`, `www/db.php`).** A valid 6-digit code previously stayed usable for its whole ~60–90s window, so an intercepted code could be replayed. A new `totp_verify_consume()` records the highest accepted step in a new `users.mfa_totp_last_step` column and rejects any code whose step was already used; both authentication paths (login 2FA and the MFA challenge) now consume codes this way.
  - **"Remember me" tokens can no longer be deleted by unauthenticated attackers (`www/auth.php`).** `clear_remember_cookie()` deleted the token row by the cookie's sequential, guessable id — so anyone could force-log-out arbitrary remembered users by iterating ids with a bogus secret. It now clears only the client cookie; server-side invalidation happens where the actor is authenticated (`logout()` now deletes the token scoped by `user_id`, matching the password/profile-change flows).
  - **MFA SMS-code resends are throttled (`www/auth.php`, `www/login.php`, `www/mfa_setup.php`).** The login and MFA-setup resend links had no rate limit; someone who knew a victim's password could spam their phone. New `mfa_sms_resend_rate_limited()` caps resends at 3 per user per 15 minutes.
  - **Registration resend confirmations are uniform (`www/resend_verification.php`).** The phone path returned a distinct "code sent via SMS" message plus an "Enter Code" button, revealing that a number belonged to a registered account; it now shows the same generic confirmation for any input.
  - **League content access tightened (`www/leagues_dl.php`, `www/_posts.php`).** `request_join` now rejects hidden leagues (they're joinable only by invite), and a post author can edit/delete their own post only while still a member of its league (a demoted/removed manager could otherwise keep altering league content by post id).
  - **API request logging no longer stores the query string (`www/api/_auth.php`).** Storing only the path keeps the `?key=` auth-fallback token out of `api_request_log` and prevents padding the URI (e.g. `?z=/api/v1/posts/`) to evade the per-endpoint rate-limit counters.
  - **`s.php` short-link redirects are constrained to the site's own origin**, so the shortener can't become an open-redirect/phishing hop if an attacker-influenced value ever reaches `short_links.target_url`.
  - **The WhatsApp webhook now accepts its token from an `X-Webhook-Token` header** (preferred; keeps the shared secret out of URLs/access logs), falling back to `?token=` for the current WAHA config. *Operator note: migrate WAHA to send the header so the token stops appearing in logs.*
  - **The league flash message is now HTML-escaped at render (`www/league.php`)**, closing a latent stored-XSS sink, and the dead **`www/auth_dl.php`** — a stale duplicate login path with no MFA or rate limiting — was deleted.

---

## [v0.2013] - 2026-07-01

### Security
- **Registration no longer confirms whether an email or phone already has an account (enumeration fix).** `register_user()` in `www/auth.php` returned distinct messages — "That email address is already registered." / "That phone number is already registered." — letting anyone probe the user base for a given contact (the 20/IP/hour limit is easily rotated). It now returns a `REGISTER_EXISTS_SENTINEL` for an already-registered email/phone, and both registration pages (`www/register.php`, `www/register_dl.php`) treat it exactly like a real signup: they render the same "Check Your Email" / "Enter Verification Code" screen, create no account, and set no verification session (so the real owner keeps their account and an attacker never receives the code/link). A brand-new signup and a duplicate-contact signup are now indistinguishable in the response. Username collisions still return a clear "already taken" message — the user must be told to pick another handle, and usernames are already visible to league members — but that path is only reachable once the email/phone is confirmed free, so it cannot be used to probe contacts.
- **phpLiteAdmin console now enforces admin auth inside its own load path (defense in depth).** Access to the bundled SQLite console (`www/phpadmin/`) previously depended entirely on an `.htaccess` `auto_prepend_file` gate, which silently fails under PHP-FPM or if `AllowOverride` stops honoring `php_value` — exposing the full database console (users, password hashes, SMTP creds) with no authentication. `www/phpadmin/phpliteadmin.config.php`, which `phpliteadmin.php` `require()`s before it touches the database, now performs the same GameNight admin-session check and redirects non-admins to login. This runs in PHP regardless of the webserver configuration, so access no longer hinges on a single `.htaccess` directive; the check is a no-op for the normal prepended-gate path where an admin is already established.

---

## [v0.2012] - 2026-07-01

### Security
- **League content is now members-only for visible leagues too (broken access control fix).** `www/league.php` only enforced membership for *hidden* leagues; for any non-hidden league, an authenticated non-member could open `?id=…&tab=posts|members|stats` and read the full post bodies, comment threads, member roster (names + roles), and the stats leaderboard — contradicting the members-only model enforced everywhere else (the post feed hides league posts from non-members, `comment.php` blocks non-member comments, and public reading otherwise requires a `share_token`). A non-member now sees a "Members only" preview with a pointer to join, and the Posts/Members/Events/Stats/Rules tabs and their queries render only for members and admins (`$isMember = $myRole !== null || $isAdmin`).
- **`update_rsvp` can no longer self-insert an invite into an event the user can't see (IDOR fix).** In `www/calendar_dl.php`, the per-occurrence RSVP path inserted a fresh `event_invites` row (defaulting `approval_status='approved'`) without ever checking that the caller could see or join the target event, so any logged-in user could POST an arbitrary `event_id` + `occurrence_date` and add themselves — appearing on the roster and player counts — of a private or league event they were never invited to. `update_rsvp` now applies the same `event_visibility_sql()` gate that `self_signup` already used, returning `403` for events the user cannot see.
- **The public API no longer discloses or force-joins accounts across leagues.** `POST /api/v1/users` (`www/api/v1/users.php`) looked up existing accounts against the global `users` table with no league filter, so a league-scoped write key could probe any email/phone platform-wide — the response returned another league's `user_id` and `username` (an enumeration oracle) and silently `INSERT`ed that account into the key's league, which also opened a channel to spam the victim's real email/SMS via event invites. The endpoint now treats a contact as "existing" only when the account is already a member of the key's league; a cross-league match returns a generic `409` that discloses nothing and adds no membership.

---

## [v0.2011] - 2026-07-01

### Security
- **Timer state can no longer be read across events by session id (IDOR fix).** The `get_state` action in `www/timer_dl.php` served the timer, blind levels, and pool/payout figures (player counts and dollar amounts) to any logged-in user who passed a `session_id`, checking only that a session existed — never that the caller could access its event. Session ids are sequential, so a user could enumerate them and read other events' live timer data. The `session_id` path now resolves the session's event and requires `check_event_access()`, returning `403` otherwise; the `?key` remote-display path is unchanged because possession of the unguessable `remote_key` is itself the authorization.
- **API keys are no longer written to the request log in plaintext.** `api_log_request()` in `www/api/_auth.php` stored the raw `REQUEST_URI` in `api_request_log.path`; for clients using the `?key=<token>` query-parameter auth fallback, that put live, replayable keys in the log in cleartext, defeating the SHA-256 hashing used everywhere else. The logger now redacts the value to `key=REDACTED` before insert, so a log/backup read can no longer recover working keys.
- **Login timing no longer reveals whether an account exists.** `attempt_login()` in `www/auth.php` compared a missing user's password against a hardcoded dummy bcrypt string that was malformed (59 chars, algorithm "unknown"), so `password_verify()` short-circuited instead of doing real work — measured on the dev container as a ~4x faster response for nonexistent accounts, a username-enumeration oracle that defeated the mitigation's intent. The not-found path now performs one equivalent `password_hash()` at the same default cost (result discarded), so found and not-found logins take the same time (measured ratio 1.01) and the fix self-tracks any future cost change.

---

## [v0.2010] - 2026-07-01

### Security
- **Timer theme values are now sanitized before they reach the page, closing a stored XSS.** The tournament-timer theme renderer built an inline `:root { ... }` `<style>` block (`www/_timer_theme.php`) from theme JSON while only stripping newlines, then emitted it raw at `www/timer.php`. Because any logged-in user can save a personal theme, an attacker could store a color/gradient/background value like `#fff} </style><script>…</script>` that broke out of the style block and executed — and the site CSP allows `'unsafe-inline'` scripts (needed by the Jodit editor), so the injected script ran. The audience includes the **unauthenticated** `?view=remote&key=…` display link, making it an account-takeover vector for anyone (co-hosts, admins, cast displays) who opened the attacker's shared timer. Two new helpers now guard every emitted value: `timer_css_scrub()` strips CSS/HTML breakout characters (`< > { } ; :` quotes, slashes) from all color/tray fields and gradient stops while preserving valid `#hex`/`rgb()`/`rgba()`/`hsl()`/named colors, and `timer_css_safe_image_url()` pins background images to a relative `^/?uploads/…` path so the `url('…')` sink cannot be escaped. The compound `--timer-bg` variable is assembled from already-sanitized parts. Verified end-to-end on the dev server: breakout payloads render inert while legitimate rgba/gradient/named/uploaded-image themes are unchanged.
- **Two poker-ledger money actions now enforce CSRF.** `void_ledger_entry` and `edit_ledger_entry` in `www/checkin_dl.php` were dispatched *above* the file's shared `POST + csrf_verify()` gate, so unlike every other mutating action they carried no CSRF protection (their only guard, `verify_event_access()`, stops IDOR but not CSRF). A cross-site auto-submitting form could therefore void a buy-in/rebuy/cash-in/cash-out or rewrite a cash-in/out dollar amount in a logged-in host's session (`poker_session_log.id` is sequential and enumerable). Each handler now performs its own `REQUEST_METHOD === 'POST'` + `csrf_verify()` check up front, matching the existing gate; the frontend already sends the token, so legitimate use is unaffected. Verified on dev: forged requests without a token return `403`, requests with a valid token pass through to the handler.

---

## [v0.2009] - 2026-06-29

### Changed
- **Invite notifications now include the event start time, not just the date.** SMS, email, and WhatsApp invites previously told recipients only the date (e.g. "on 2026-06-27"); they now read "on Sat, Jun 27 at 6:00 PM PDT" with a timezone label so invitees know exactly when the game starts without clicking through. The time follows the same per-recipient rule as the rest of the app: a registered recipient who has set a personal timezone sees the event in their own zone, while recipients with no personal timezone — and custom invitees who have no account — see it in the event creator's timezone. All-day events (no start time) still show the date only. The invite builder already computed a tz-aware time but never placed it in the message; this wires it in and closes a gap where a registered recipient without a personal timezone fell back to the site default instead of the creator's zone (`www/_notifications.php`, reusing `display_timezone()` from `www/db.php`).

---

## [v0.2008] - 2026-06-28

### Fixed
- **Event times on invite links and RSVP pages now show in a sensible, labeled timezone.** The public invite page (`event.php`) and the tokenized RSVP page (`rsvp.php`) previously rendered an event's stored time with no timezone conversion at all, so a host in one zone and an invitee in another saw the same raw number with no label, which caused real confusion (a host who set a game for 6:00 PM had invitees seeing 8:00 PM on the link). These pages now resolve the display timezone deliberately: a **logged-in** viewer sees the event in **their own** timezone, while a **logged-out** invite/RSVP-link viewer (who has no account timezone) sees it in the **event creator's** timezone. Either way the time now carries a timezone label (e.g. `6:00 PM PDT`) so it is never ambiguous. Backed by a new shared helper `event_public_time_labels()` in `www/db.php`.
- **Invite and reminder notifications pick the right timezone per recipient.** In `www/_notifications.php`, emails/texts to a **registered** invitee render the time in **that user's** timezone (each member sees their own clock), while messages to a **custom invitee** with no account fall back to the **event creator's** timezone instead of the bare site default. Times remain stored as wall-clock in the site timezone; only the rendering changed. The in-app calendar and league pages are unchanged and continue to show each logged-in user their own timezone.

---

## [v0.2007] - 2026-06-27

### Added
- **Existing users' timezones are now backfilled automatically from their browser.** v0.2006 captured timezone at signup, but everyone who registered earlier still had `users.timezone` NULL and saw times in the site-default zone (America/Chicago). A small fire-and-forget script in the shared footer (`www/_footer.php`) now detects the browser zone via `Intl.DateTimeFormat().resolvedOptions().timeZone` for any logged-in user without a timezone and POSTs it to a new CSRF-protected, auth-gated endpoint (`www/set_timezone_dl.php`). The endpoint fills `users.timezone` only when it is still empty (`UPDATE ... WHERE timezone IS NULL OR timezone = ''`), so an explicit choice made in Settings is never overwritten, and it validates the zone against `DateTimeZone::listIdentifiers()`. The footer block only renders while the timezone is unset, so it self-terminates after one successful backfill and the user sees correct local times from their next page load onward (the auto-set is recorded in the activity log).

---

## [v0.2006] - 2026-06-27

### Added
- **New-account signup now captures the user's timezone automatically.** The registration form (`www/register.php`) gained a Timezone dropdown that is pre-selected from the browser's own zone via `Intl.DateTimeFormat().resolvedOptions().timeZone`, with a hidden raw-IANA field so zones outside our curated list are still stored. The chosen zone is validated (curated list, then `DateTimeZone::listIdentifiers()` fallback) and written to `users.timezone` through a new trailing `$timezone` argument on `register_user()` (`www/auth.php`). Previously `users.timezone` was left NULL until a user dug into Settings, so event times and the footer clock rendered in the site-default zone (America/Chicago) rather than theirs. Because per-user timezone is already wired to override the site default on every page (`current_user()` in `auth.php`), new users now see correct local times from their first login. Existing users are unaffected and can still set their zone in Settings.
- **Phone signups can choose SMS or WhatsApp at registration.** A "Notify me by" choice on the signup form lets phone-based registrants pick WhatsApp instead of SMS for their verification code and event notifications; email signups stay on email. The chosen channel sets both `verification_method` and `preferred_contact`. This also fixed a pre-existing hard override in `register_user()` that forced every phone signup back to `sms` regardless of the requested method.

### Fixed
- **Mobile layout of the new "Notify me by" radio group.** The global `.form-group input { width:100% }` rule was stretching the radio buttons to full width so they overlapped their labels on phones; the radios are now constrained to their natural size.

---

## [v0.2005] - 2026-06-23

### Added
- **Ledger entries can now be edited in place, not only cleared.** The per-player ledger gained an **Edit** button next to Clear on cash-in and cash-out entries. Tapping it prompts for the corrected dollar amount and fixes the entry **where it sits** — the player's running total is adjusted by the delta, and an audit row ("Edited cash in: $189 → $180") is appended to the activity log for a tamper-evident trail. This replaces the previous clear-and-re-enter workaround, which dropped the corrected amount at the bottom of the sequence and made the history hard to read. Only money entries carry an editable amount, so other rows still offer Clear only (new host-gated `edit_ledger_entry` endpoint in `www/checkin_dl.php`; `editLedgerEntry()` + Edit button in `www/checkin.php`). Driven by host feedback that fixing a fat-fingered amount should not require re-typing the whole entry.
- **Per-event "Hide guests" toggle.** A new toggle in the event editor (next to Approval) hides the "who's coming" guest list (Going / Maybe / Invited / Can't make it) on both the public event page and the tokenized RSVP confirmation page, for hosts who want to keep the attendee list private. New `events.hide_guest_list` column (auto-migrated), saved through `_event_save.php`, surfaced in `event_edit.php`, and gated in both `event.php` and `rsvp.php`. Answers a standing host request to optionally show or hide attendees after someone opens an invite.

### Fixed
- **Activity log and ledger times now follow the viewer's own clock.** These timestamps were rendered server-side in the site-wide timezone (America/Chicago), so a host in another timezone saw times shifted by the offset — a Pacific host's 10:45 PM showed as 12:45 AM. Because the site timezone is a fixed storage anchor that must not change, the log/ledger rows now carry a UTC timestamp (`time_ts`) and are formatted in the browser's local timezone via a new `fmtLocalTime()` helper, with the server-rendered site-tz string kept as a fallback. Storage is unchanged; only the live operational log/ledger display is localized (`www/_poker_helpers.php`, `www/checkin.php`).
- **Ledger button is more discoverable.** The 📒 ledger modal now opens with a subtitle that names both actions ("Tap **Edit** to fix a wrong amount in place, or **Clear** to reverse an entry"), and the Cash In / buy-in column help bubbles now call out the 📒 icon and mention editing as well as clearing (`www/checkin.php`).

---

## [v0.2004] - 2026-06-22

### Added
- **Cash-game "Cash Box" reconciliation, with host tips.** A new 🧾 Cash Box control on the check-in screen (header button + a "Reconcile cash box" link in the Money Summary card) opens a reconciliation panel that squares the physical cash box at the end of a cash game. It shows the auto-computed totals (bought in, cashed out, still on table = cash-in − cash-out), an editable **Tips (host)** field, the resulting **Expected in box**, an editable **Counted in box**, and a color-coded **Over / Short** readout (Even ✓ / Over / Short). When the count comes up over, a one-click **"Record the surplus as tips"** button absorbs the difference so the box squares to even. Tips and the counted amount persist per session (new `tips` / `cash_counted` columns on `poker_sessions`, saved via a host-gated `set_cash_reconcile` action), and recorded tips show as a line in the Money Summary card. Built from host feedback about ending a night with more cash in the box (tips) than the app expected, with no place to record or square it (`www/db.php`, `www/checkin_dl.php`, `www/checkin.php`).

---

## [v0.2003] - 2026-06-22

### Added
- **Per-player ledger with one-tap corrections on the check-in screen.** Each player row now has a ledger icon (right of the Cash In **+**) that opens a per-player money history: every buy-in, add-on, rebuy, cash-in and cash-out with amount, time, and who entered it. A manager can **Clear** a bad entry, which **voids** it (kept struck-through for a tamper-evident trail) and **reverses** its amount on the player's total and the pool — so a fat-fingered "$189 instead of $180" is fixed by clearing the line instead of re-typing. New `voided/voided_by/voided_at` columns on `poker_session_log`; `set_cashin`/`set_cashout` now record a signed delta so any entry is cleanly reversible; new host-gated `get_ledger` / `void_ledger_entry` endpoints (`www/checkin_dl.php`, `www/_poker_helpers.php`). Driven by host feedback that the existing correction path was undiscoverable.
- **Tap-friendly help bubbles** on the check-in column headers (the select checkbox, Cash In, Cash Out, tournament buy-in) — small `?` markers that show guidance on hover or tap, so the fields explain themselves on a laptop or a phone (`www/checkin.php`).

### Changed
- **Cash-game check-in is leaner and more cash-aware.** The **RSVP column and "RSVP Yes" filter are hidden in cash games** (RSVP is a pre-event signal, irrelevant to walk-up cash play), freeing room for the money columns. **Cashing a player out now frees their seat** (like elimination does in tournaments), and undoing a cash-out re-seats them. The walk-in marker changed from a yellow "WALK-IN" badge to a 🚶 icon with a tooltip.
- **Cash-game bust / re-enter flow.** A player who busts out is recorded as a **$0 cash-out** via a 💥 button inside the Cash Out cell: they show status **"Busted"**, their row greys out, and their seat frees. There is no separate re-enter button — **adding cash with the + tool reactivates them and gives them a seat again** (the Name and Cash In columns stay full-brightness on greyed rows so the path is obvious). The cash filter's last tab is now **"Out."**
- **Table view: "Remove from table" option.** The seated-player **Move…** dropdown gained a "Remove from table" choice that unassigns the player (frees the seat) and drops them into the Unassigned group to be re-seated later (`www/checkin.php`).

---

## [v0.2002] - 2026-06-21

### Fixed
- **Email that fails on a provider daily-send/rate cap is now retried instead of silently dropped.** Outbound mail through an SMTP relay (e.g. Google Workspace's `smtp-relay.gmail.com`) can hit a "Daily SMTP relay limit exceeded" / quota / rate-limit rejection during invite bursts; these are temporary but were classified as permanent, so the verification or invitation email was lost. `www/mail.php` now recognizes quota/rate errors (`_smtp_error_is_quota()`, folded into a new `_smtp_error_is_retryable()`) and parks them in the existing `email_retry_queue` with a **stretched backoff** (`_email_queue_backoff_minutes()` gains an `isQuota` mode: retries at roughly 1h, 5h, 13h, 25h, 49h, 73h) so attempts land after the provider's rolling 24-hour window resets rather than burning every attempt while it is still exhausted. Connection-blip retries are unchanged, and genuinely permanent errors (bad recipient, auth failure) are still dropped without pointless retries. The queue is drained by the existing `cron.php` job. This does not raise any sending limit; it just stops messages from being lost when you brush one. (Operators sending high volume should still move off a Workspace relay to a transactional provider such as Brevo or Amazon SES.)

  **Reference — Google Workspace sending limits** (the cap behind this fix): the SMTP relay service (`smtp-relay.gmail.com`) is documented at **10,000 messages/day per domain** with a ~**3 messages/second** rate limit; authenticated Gmail SMTP (`smtp.gmail.com`) is ~**2,000/day** per Workspace user (~1,500 external), or ~500/day for a free `@gmail.com`. In practice the effective cap is much lower for new/low-volume domains because Google **ramps the limit up over days/weeks** based on reputation, and it enforces a **rolling 24-hour window** (not a midnight reset) — which is why `noreply@gamenight.poker` was bouncing invite bursts around ~100–150/day and recovering partway through the day. For comparison, Brevo's free tier is a flat **300/day** with no ramp. (Google's published figures change over time and vary by plan/admin settings.)

---

## [v0.2001] - 2026-06-21

### Changed
- **Rewrote the seeded "Welcome to Game Night!" first post to welcome new members rather than walk an admin through install.** The default post created on a fresh install (in `db_init()` in `www/db.php`, guarded by the `welcome_post_seeded` flag so it never re-creates) now gives a friendly tour of what a member can do, with links to the real pages: Calendar, My Events, Leagues, Stats & Leaderboard, Contacts, My Settings (notifications + two-factor), the Tournament Timer, and the Guest/Host guides. It keeps the header-banner image at the top and stays pinned. This only affects brand-new installs; existing sites keep whatever their post #1 already says.

---

## [v0.2000] - 2026-06-21

### Added
- **Users can personally hide a post from their home feed.** Each post card now has a kebab (⋯) menu with a **Hide** action (for any logged-in user); hiding collapses the post in place to a "Post hidden · Undo" stub and, after reload, drops it from the home feed and its timeline month counts. A **"Show hidden posts"** toggle on the feed switches to a view of just your hidden posts, each with an **Unhide** control, with **"Back to feed"** to return. The hide is strictly personal (it never affects what anyone else sees) and is distinct from the existing admin/league `posts.hidden` flag that hides a post for everyone. Hiding applies to the home feed only; a post hidden there still appears on its league's own Posts tab. New `user_post_hidden` table (per-user/per-post, modeled on `user_help_bubble_dismissed`); the single `posts_feed_sql_for_user()` helper in `www/_posts.php` gained an `exclude`/`only` hidden-mode parameter so the feed query, its count, the timeline, and the infinite-scroll chunk all stay in sync. Hide/unhide go through a new login + CSRF-guarded `www/post_hide_dl.php` (which also verifies the post is visible to the user via `post_is_visible_to()`).

### Changed
- **Extracted the post-card markup into a shared `www/_post_card.php` partial.** The card (meta, actions, and comments section) was duplicated between `www/index.php` and the infinite-scroll `www/posts_chunk.php`; both now include one partial, removing the drift risk and giving the new kebab menu a single home. The kebab also absorbs the existing Edit/Delete controls for users who already had them.

---

## [v0.1999] - 2026-06-21

### Changed
- **Check-in toolbar: player filter and view switcher are now matched "pill slider" controls.** The player filter (All / RSVP Yes / Active / Cashed Out) got the same sliding-thumb treatment as the List / Table / Log switcher, and both now share one `.pk-seg` style so they read as a pair of grouped sliders. A blue thumb glides under the active button, positioned in JS from the active button's geometry (`positionSegThumb()` / `positionAllSegThumbs()` in `www/checkin.php`, generalized from the earlier view-only helper), animated on click and snapped on full re-renders and resize. `setFilter()` slides its thumb in place via the existing `refreshUI()` path, so no full re-render is needed. To stand out against the white page, the track is a recessed cool-slate well (`#dde3ec` fill, `#cbd5e1` border, inset shadow) with the thumb raised above it.
- **"Add Table" is now a green, Table-view-only button.** The old always-visible "Tables: N +" toolbar button was renamed to a solid green **Add Table** button (new `.pk-btn-green` style) and is shown only while the Table view is active; `setViewMode()` toggles its visibility as you switch views (the toolbar persists across switches so the slider thumb can animate). The table count remains visible in the Table view itself (`www/checkin.php`).
- **"Break Up" table button is now solid red.** In the Table view, each table card's Break Up control changed from red link-style text to a solid red button using the existing `.pk-act-btn.danger` style, matching the other destructive actions (`www/checkin.php`).

---

## [v0.1998] - 2026-06-21

### Added
- **Check-in screen now has an Activity Log view.** Hosts can see exactly who entered and every dollar in or out during a live poker session, for both tournament and cash games. A new persisted, append-only `poker_session_log` table records the full timeline — players added/approved, buy-ins (and reversals), rebuys, add-ons, cash-in, cash-out, eliminations (with finishing place), the auto-crowned winner, and removals — each entry stamped with the dollar amount where relevant, the actor (the host who performed it, or `system`), and the time in the site timezone. Two helpers in `www/_poker_helpers.php` do the work: `pk_log()` writes entries (control-char-stripped like `db_log_activity`), and `get_session_log()` reads them newest-first with the actor joined from `users` and timestamps converted UTC→site-tz (mirroring `admin_activity_snapshot`). Eleven action handlers in `www/checkin_dl.php` now log their effects, a new `get_log` action returns the feed, and `get_session` carries it for the initial paint — all behind the existing host/admin `verify_event_access` gate. The log is read-only by design: corrections are made through the normal controls (e.g. decrement a rebuy), which themselves get logged, so the record never diverges from session state.
- **List / Table / Log unified into one sliding view switcher.** The check-in toolbar's List/Table segmented control gained a third **Log** segment, with a blue indicator that slides under the active view. Switching is surgical — only the content area (`#viewContent`) re-renders while the toolbar and sidebar persist — so the log lives on the main screen rather than in a popup. The thumb is positioned in JS from the active button's geometry (`positionViewThumb()` in `www/checkin.php`), animated on click and snapped on full re-renders; the log also refreshes live on each logged action and on the 10-second session poll.

---

## [v0.1997] - 2026-06-21

### Added
- **Admin "Activity" tab — live site-usage snapshot.** A new tab under Site Settings answers "is the site being used right now" at a glance, auto-refreshing every 30 seconds: live tournament timers running, active poker games (and players still in), active users in the last hour, sister-site API calls in the last hour, and the notification-queue depth, plus a feed of the last 15 logged actions. It polls a new admin-gated, CSRF-checked `activity_snapshot` JSON action in `www/admin_settings_dl.php`; both the initial server-render and the poll share one helper, `admin_activity_snapshot()` in `www/db.php` (all cheap guarded COUNTs, no schema changes). Polling pauses when the tab is hidden, with a manual "Refresh now" button. Note on honesty: true per-connection counts aren't trackable with PHP file-sessions, so "active users (1h)" is an activity-based estimate (distinct `activity_log` users) and "live timers" reflects tournament clocks that polled within the last 2 minutes — both labeled as such in the UI (`www/_admin_tabs.php`, `www/admin_settings.php`).

### Fixed
- **`calendar_dl.php` no longer 500s on a direct GET.** The file is a POST-only action endpoint (delete/cancel-series/remove-invitee/walk-in-token actions), but it still contained a legacy GET "calendar view" — a duplicate of `calendar.php` left over from before recurrence was removed — whose query referenced the long-deleted `recurrence` column and threw an uncaught `PDOException` on any GET. A direct GET now redirects to `/calendar.php` instead. POST handling is unchanged.

### Removed
- **Deleted ~1,940 lines of dead legacy calendar-render code from `calendar_dl.php`** (everything after the POST handlers / the new GET redirect). It was an unreachable, broken duplicate of `calendar.php`; the file drops from 2,271 to 334 lines. No behavior change beyond the GET fix above.

---

## [v0.1995] - 2026-06-21

### Changed
- **Admin pages now use in-app dialogs instead of native browser pop-ups (Batch C) — completing the app-wide migration.** The remaining ~24 native `alert()`/`confirm()`/`prompt()` calls across `admin_settings.php`, `admin_settings_dl.php`, `admin_posts.php`, `admin_help.php`, `admin_api_keys.php`, and `sms_log.php` were converted to the shared `pk-dialogs.js` helpers (clear-logs / delete-users / delete-event / restore-backup / remove-banner-icon / delete-post / delete-API-key forms via `pkConfirmForm`; the WhatsApp reset/stop, post discard/bulk-delete, and tip-delete handlers via `async` + `await pkConfirm`). Verified with headless Chromium (zero JS errors, handlers intact) and `node --check`. A repo-wide sweep confirms no native `alert`/`confirm`/`prompt` calls remain anywhere outside third-party libraries. This finishes the migration begun in v0.1990 (shared utility) across Batches A (timer/calendar/league), B (other user-facing pages), and C (admin).

---

## [v0.1994] - 2026-06-21

### Changed
- **Most remaining user-facing pages now use in-app dialogs instead of native browser pop-ups (Batch B).** ~52 native `alert()`/`confirm()`/`prompt()` calls were converted to the shared `pk-dialogs.js` helpers across `contacts.php`, `leagues.php`, `index.php`, `event_polls.php`, `register.php`, `posts_chunk.php`, `user_edit.php`, `walkin_display.php`, and the rest of `checkin.php` (Finish/Reopen game, deny player, break table, payout-structure save/delete/default including the "Save as" scope chooser, etc.). Confirm-gated handlers were made `async`; delete/confirm forms use `pkConfirmForm`. The two standalone pages that don't include the shared footer (`register.php`, `walkin_display.php`) load `pk-dialogs.js` directly (cache-busted by mtime). Verified with headless Chromium (pk-dialogs present, all handlers intact, zero JS errors) and `node --check` on every converted file. Only admin pages remain (Batch C).

---

## [v0.1993] - 2026-06-21

### Changed
- **League pages now use in-app dialogs instead of native browser pop-ups.** All 25 native `alert()`/`confirm()` calls in `www/league.php` were converted to the shared `pk-dialogs.js` helpers: the seven confirm-gated forms (delete post, delete API key, set/unset rules, public-link generate/disable) via `pkConfirmForm`, and the `act` / `removeMember` / `leaveLeague` / `regen` handlers via `async` + `await pkConfirm`. Verified with headless Chromium (HTTP 200, all handlers intact, centered overlay, zero JS errors) and `node --check`. Completes Batch A of the migration (timer v0.1991, calendar v0.1992, league v0.1993).

---

## [v0.1992] - 2026-06-21

### Changed
- **Calendar now uses in-app dialogs instead of native browser pop-ups.** All ~35 native `alert()`/`confirm()`/`prompt()` calls across `www/calendar.php` and `www/calendar_dl.php` were converted to the shared `pk-dialogs.js` helpers: delete-event and delete-occurrence forms (via `pkConfirmForm`), remove-self, delete host message, delete comment and bulk-delete comments, remove invitee, and the cancel-series flow (a date `pkPrompt` chained into a `pkConfirm`). Confirmation-gated handlers were made `async`. Verified with headless Chromium on calendar.php (centered overlay, resolves, zero JS errors) and `node --check` of the converted JavaScript. Continues the app-wide migration (v0.1990–v0.1991).

---

## [v0.1991] - 2026-06-21

### Changed
- **Tournament timer now uses in-app dialogs instead of native browser pop-ups.** All ~42 native `alert()`/`confirm()`/`prompt()` calls on the timer were converted to the shared `pk-dialogs.js` helpers: reset timer, generate/replace blind structure, restore unsaved draft, set-as-default / delete preset, delete preset file, delete theme, the player-panel cash in/out prompts, and every error/status alert. The enclosing handlers were made `async` so they `await` the dialog. Verified with a headless DOM unit test of the dialog utility (19 checks) and a syntax check of the rendered timer JavaScript (zero native dialogs remain). Part of the app-wide migration that began in v0.1990.

### Fixed
- **Shared dialog utility is now self-contained and renders correctly on standalone pages.** `pk-dialogs.js` injects its own CSS (fixed full-screen overlay, centered card, high `z-index`, explicit dark text) instead of relying on `style.css`. This fixes the timer (a standalone page that doesn't include the shared footer/`style.css` cascade in the same way), where the first converted dialog appeared mis-positioned in the corner and would not dismiss if a stale `style.css` was cached. The timer's script tag is also cache-busted via file mtime.

---

## [v0.1990] - 2026-06-20

### Added
- **Shared in-app dialog utility (`pk-dialogs.js`).** Groundwork for replacing the browser's native `alert()`/`confirm()`/`prompt()` app-wide (native dialogs are inconsistent and, after repeated use, the browser can suppress them and silently swallow the action). The new utility provides Promise-based `pkAlert`, `pkConfirm`, and `pkPrompt`, plus `pkConfirmForm`/`pkConfirmGo` helpers for `onsubmit`/`onclick="return confirm()"` cases. It self-injects a single reusable modal, handles Enter/Esc/click-outside/focus, and reuses the existing `.pk-modal` styling. It is loaded on every page via `_footer.php` (like `help-bubble.js`), and the base modal CSS was promoted from `checkin.php` into `style.css` so it applies site-wide. `checkin.php`'s Remove confirmations were migrated to the shared helper (removing its local duplicate). Subsequent releases will convert the remaining ~170 native dialog call sites in batches.

---

## [v0.1989] - 2026-06-20

### Changed
- **Mobile cash-out is now an inline field too, matching desktop and Cash In.** On phone-width cards, a bought-in player's expanded view now shows a Cash Out row with a number input and a green check (✓) to record the cash-out (Enter also commits; clearing the field then committing reverts the player to still-playing). The mobile Cash Out / Undo Cash Out action buttons were removed as redundant. With no remaining caller on desktop or mobile, the cash-out popup dialog was removed entirely (its markup and the `openCashout`/`saveCashout`/`closeCashout`/`undoCashoutFromModal` handlers); cash-out now flows only through the inline `commitCashOut` path. Cash In and Cash Out are now consistent, popup-free inline fields across both layouts (`www/checkin.php`).

---

## [v0.1988] - 2026-06-20

### Changed
- **Cash Out is now an inline field on the desktop check-in table, matching Cash In, instead of a button that opens a popup.** Feedback was that popping a dialog to cash a player out felt harsh. The Cash Out column now shows a number input with a green check (✓) button alongside it (where Cash In has a +): type the amount and press Enter or click the check to record the cash-out (the check gives mouse users an obvious commit affordance). Editing re-commits, and clearing the field then committing reverts the player to still-playing (replacing the old "Undo Cash-out"). The remaining cash on the table is still visible in the stats bar. A separate input class keeps Cash In's Enter-to-next-player behaviour from jumping into the Cash Out field. Mobile cards still use the tap-to-open dialog (`www/checkin.php`).
- **Cash In field polish.** Removed the "−" button from the Cash In counter (corrections are made by typing the exact total, and the styled +/Add Money dialog remains for adds), tagged the Cash In and Cash Out fields as numeric inputs (`type=number`, `inputmode=decimal`) so phones show a numeric keypad, and hid the desktop number-spinner arrows so the fields stay clean (`www/checkin.php`).

---

## [v0.1987] - 2026-06-20

### Changed
- **Renamed the cash-game "Total In" column to "Cash In" and replaced its native +/- prompts with a styled dialog.** The desktop header now reads "Cash In" (mobile already used that label). The fast inline entry is unchanged: type an amount and press Enter to set it and jump to the next player, which keeps bulk start-of-game and rebuy entry quick. The +/- buttons, which previously fired a native browser `prompt()` (the same kind of dialog the browser can suppress after repeated use), now open an in-app "Add Money" / "Remove Money" dialog prefilled with the configured buy-in amount; the add/subtract behaviour (add to total, subtract floored at $0) is unchanged (`www/checkin.php`).

---

## [v0.1986] - 2026-06-20

### Changed
- **Cashing out a player is now done in the Cash Out column, where hosts look for it.** Feedback from a real game showed hosts did not realize that the "Cash Out" button (which lived in the Actions column) was how you cash a player out, because the "Cash Out" column itself only displayed an amount or a dash, while Total In is edited directly in its own column. The cash-out control now lives in the Cash Out column: a bought-in player who hasn't cashed out shows a green "Cash Out" button there, and once cashed out the column shows the amount with a dashed underline that can be tapped to edit it. "Undo cash-out" moved into the cash-out dialog (shown only when editing an existing cash-out), and the Actions column now shows just Notes / Remove. Desktop check-in table only; mobile cards are unchanged (`www/checkin.php`).

---

## [v0.1985] - 2026-06-20

### Changed
- **Check-in row actions are now clearly tappable, color-coded buttons.** The per-player actions used a borderless transparent style (`.pk-act-btn`) that read like plain text links, so hosts did not realize they could tap them (the cash-game "Cash Out" action was the worst offender). They are now solid buttons colored by purpose: primary actions (Cash Out, Eliminate, Approve) in green, destructive actions (Remove, Deny) in red, and neutral actions (Notes, Undo) in gray. The same color roles were applied to the mobile expand-panel buttons (`www/checkin.php`, CSS only).
- **Cash-out dialog shows the cash remaining on the table.** The Cash Out modal now displays a "Cash on table" line (total buy-ins minus what has already been cashed out, adding back the player's own prior cash-out when re-cashing), so a host can see how much is left to distribute as they cash each player out. It reuses the value the dialog already computed to cap the entry (`www/checkin.php`).

### Fixed
- **Cash games no longer offer elimination or compute finishing places/prizes.** Cash players leave by cashing out, but the check-in screen still showed an "Eliminate" button in cash mode, which put players into a broken eliminated state and could trigger the tournament heads-up auto-win/auto-finish on a cash game. Elimination is removed from cash mode across the desktop table, mobile cards, and the seating view; an "Undo Elim" button is kept so any player eliminated under the old behavior can be restored. The heads-up auto-win in `eliminate_player` is now gated to `game_type = 'tournament'` (`www/checkin.php`, `www/checkin_dl.php`).
- **Removing a player uses an in-app dialog instead of a native browser confirm.** The Remove action (per-player and bulk) previously used a native `confirm()`, which after repeated use lets the browser suppress further dialogs and silently swallow the action. It now uses a styled in-app confirmation modal, matching the Eliminate dialog, via a new reusable `pkConfirm()` helper (`www/checkin.php`).

---

## [v0.1984] - 2026-06-20

### Added
- **Tournament timer can now sync prize pool and players across devices.** The timer only shows live financials when it is opened linked to an event (`timer.php?event_id=…`); opened with no parameters it runs standalone with no session and a null pool, which is why a timer launched on a second device (e.g. an iPad) showed `$0.00` and never updated even on refresh — the poll endpoint only computes pool for a positive session id (`timer_dl.php`). Two changes close that gap. First, the nav "Tournament Timer" link now points at the user's in-progress game when one is running, via a new `user_active_poker_event_id()` helper in `www/db.php`, so opening the timer from the menu lands on the event-linked timer that syncs automatically. Second, a standalone timer opened by a host who can manage poker games now shows a "this timer isn't linked to an event" banner with a dropdown of their games (active games listed first), built on a new `user_poker_events()` helper in `www/_poker_helpers.php`; picking one reloads the timer linked to that event (`www/timer.php`). Once linked, the laptop's buy-ins, rebuys, and eliminations appear on the timer (and on players' QR screens) within the existing ~2-second poll. The banner is hidden in cast/display mode and for guests/remote viewers.

---

## [v0.1983] - 2026-06-20

### Added
- **Tournament finishing places and prize-owed now recorded automatically.** Eliminating a player on the check-in screen (`www/checkin.php`, `www/checkin_dl.php`) no longer prompts the host to type a finishing position. The backend `eliminate_player` action derives the place from elimination order (the number of players still in, including the one being knocked out), so busting out with nine left records 9th, the next 8th, and so on. For places that are in the money, the prize owed is shown next to the player, computed live from the current prize pool and the configured payout structure (the same formula the payout card uses), so it always stays consistent. Finishing place feeds league standings (`league.php`), so getting it right matters beyond display.
- **Heads-up auto-win.** When an elimination leaves exactly one player in, that player is automatically crowned the winner (`finish_position = 1`) and the session is set to `finished`; a celebratory modal announces the winner and 1st-place prize. Undoing the runner-up's elimination un-crowns the winner and reopens the game. The winner is shown with a gold 1st-place badge (never struck through) and has no Eliminate button anywhere, preventing accidental knock-out of the champion.
- **Sortable columns on the check-in table.** Click any column header (Name, RSVP, buy-in, Rebuys, Add-ons, Table, Seat, Status, and the cash-game Total In / Cash Out / Profit) to sort; click again to reverse, with a ▲/▼ arrow on the active column. Sorting applies to both the desktop table and the mobile card list and composes with the existing filters. It is a view preference and is not persisted across reloads.
- **In-screen help.** A "? Help" button in the check-in toolbar opens a modal explaining Buy In, Approve/Deny, Rebuys & Add-ons, and Eliminate. Works on touch (iPad) where hover tooltips do not. Buy In, Approve, and Deny also gained hover tooltips on desktop.

### Changed
- **Removed the dead "Check In" button.** The bulk "Check In" control posted a `toggle_checkin` action that had no server handler, so it did nothing. Check-in already happens automatically as a side effect of recording a buy-in, so the button was removed to end the confusion.

### Fixed
- **Eliminate no longer uses a native browser dialog.** The confirmation was a native `confirm()`, which after repeated use triggers the browser's "prevent this page from creating additional dialogs" option; once suppressed, `confirm()` silently returns false and eliminations stopped registering. It is now an in-page modal, so any number of eliminations can be recorded back-to-back.
- **Eliminated players' Status is no longer struck through.** The eliminated-row styling struck through every cell except the last; the Status column (which now carries the place and prize owed) is exempted so it stays readable.

---

## [v0.1982] - 2026-06-19

### Security
- **Closed open redirects in the comment and league action endpoints.** Three endpoints echoed a caller-supplied `redirect` value into `header('Location: ...)` without restricting it to a local path, so an authenticated user could be bounced off-site (e.g. to a phishing page) by a crafted form. `www/comment.php` used an unanchored pattern (`#^/[^/\\]*#`) whose trailing `*` matched protocol-relative `//evil.com` by consuming zero characters after the slash; it now requires the leading `/` to be followed by a non-`/`, non-`\` character (`#^/(?![/\\])#`). `www/league_posts_dl.php` and `www/league_api_keys_dl.php` passed the `redirect` field through verbatim; both now reject any value that isn't a local relative path (no `//` or `/\` protocol-relative forms, no absolute URLs), while preserving the empty-string case that selects JSON response mode. `www/login.php`'s post-login redirect guard was also hardened to reject the same `/\` protocol-relative form (browsers normalize `/\` to `//`). Exploitation always required a valid session and CSRF token, so impact was limited to open-redirect (header injection is blocked by PHP's newline stripping).

### Fixed
- **Stopped maxed-out notification rows from accumulating forever.** `cron.php` pruned `pending_notifications` with `WHERE attempted_at < datetime('now','-7 days')`, but a failed drain resets `attempted_at` to `NULL`. A row that exhausts its 3 retries ends with `attempts = 3` and `attempted_at = NULL`, so it was never re-claimed (the claim query requires `attempts < 3`) and never matched the prune (a `NULL` is never `<` a date) — these dead rows lived indefinitely, only ever cleared if their event was later deleted. `cron.php` now also runs `DELETE FROM pending_notifications WHERE attempts >= 3 AND created_at < datetime('now','-7 days')` to age out exhausted rows by their creation time.

---

## [v0.1981] - 2026-06-19

### Security
- **Closed an IDOR that let any logged-in user join (and thereby reveal) private events.** The `self_signup` action in `www/calendar.php` and `www/calendar_dl.php` inserted an `event_invites` row for whatever `event_id` was POSTed with no check that the event was visible to the requester. Because `event_visibility_sql()` grants visibility to anyone holding an invite row, a user could POST `action=self_signup&event_id=<any id>` to add themselves to an `invitees_only` or league event they were never invited to and unlock its details, attendee list, and comments. Both handlers now gate the signup on the existing `event_visibility_sql()` rule (public, a league the user belongs to, an event they created, one they were already invited to, or admin) and return 403 otherwise.
- **Made the Surge inbound-SMS webhook fail closed.** `www/sms_webhook.php` only ran HMAC verification when both a secret was configured *and* a `Surge-Signature` header was present (`$secret !== '' && $sigHeader !== ''`), so an attacker could bypass verification entirely by simply omitting the header and forge inbound SMS, RSVPs, and host/admin command-layer actions (CANCEL, broadcasts, invitee approvals). Verification now triggers whenever a secret is configured, and a missing, stale (>5 min), or invalid timestamp/signature is a hard 403. Operator note: the other four providers (Twilio/Plivo/Telnyx/Vonage) still have no inbound webhook authentication and remain forgeable; add per-provider signature verification if any of them is made the live provider.

### Fixed
- **"Resend verification" returned a 500 on every submission.** `www/resend_verification.php` pulled in `auth_dl.php` — a stale April fork of `auth.php` — but the page calls `find_user_by_identifier()` and `send_verification_code()`, which are defined only in the current `auth.php`, so every POST died with an undefined-function fatal and no user could ever request a new verification link or code. The page now requires `auth.php`. This also pulls in `auth.php`'s `gmdate()`-based `send_verification_email()`, fixing the stale copy's local-time expiry timestamp that was silently shrinking the 24-hour email-verification window to ~18 hours for anyone in a timezone behind UTC.

---

## [v0.1980] - 2026-06-17

### Added
- **Persistent retry queue for failed emails.** Investigation of the "failed SMTP" entries in the notification log showed they were not a misconfiguration: the vast majority of sends succeed, but Google's SMTP relay intermittently drops the STARTTLS handshake ("Could not connect to SMTP host ... Called QUIT without being connected"), and when a cron reminder batch happened to hit one of these blips every recipient in that run failed at once with no retry, silently never receiving the mail. `send_email()` (`www/mail.php`) now retries transient connection/handshake failures up to 3 times inline with a short backoff, and if those are exhausted while the error is still transient the message is parked in a new `email_retry_queue` table (`www/db.php`) instead of being dropped. `cron.php` drains the queue every run (section 2b, ungated by `notifications_enabled` so password-reset and verification mail are covered too), re-attempting each due row on an escalating backoff of 5m / 15m / 30m / 1h / 2h / 4h before giving up after 6 attempts. Permanent failures (authentication, rejected recipient) are never queued. Draining uses an atomic claim (bump `attempts` and push `next_attempt_at` out before sending) so overlapping cron runs cannot double-send. The two interactive "send test email" buttons (`www/SMTPTesting.php`, `www/admin_settings_dl.php`) opt out of queueing via a new `$queueOnFailure` flag, since a background retry would be misleading for an on-demand test.

### Changed
- **Notification log shows a `queued` status in amber.** When a send is parked for background retry it is logged as `queued` rather than `failed`, and `www/sms_log.php` renders that state in amber with a "Pending retry" tooltip so it no longer reads as a hard failure while delivery is still pending.

---

## [v0.1979] - 2026-06-14

### Changed
- **Week view opens to the evening instead of the morning.** The weekly calendar used to auto-scroll to 8 AM (or the current hour for this week), so evening events sat below the fold and users had to scroll down every visit. It now scrolls so the earliest timed event of the visible week sits near the top, and on weeks with no timed events it lands on ~5 PM, so the evening is always front and center (poker is an evening pastime). Same rule applies to every week including the current one. In `www/calendar.php` `initWeekView()`.

### Fixed
- **Week grid placed events at the wrong hour for users with a non-default timezone.** Event blocks in the week view were positioned by the raw `start_time` (the site timezone) while their printed time labels used the viewer's timezone, so anyone whose personal timezone differed from the site's saw a block sit at a different hour than its label said (and the red "now" line used the browser clock, a third reference). The grid now positions events by the viewer-timezone time (`start_time_input`) so the block, its label, and the hour gutter all agree, and the "now" line is computed from a viewer-timezone `WK_NOW_MIN` value. Site-timezone users are unaffected (`www/calendar.php`).

---

## [v0.1978] - 2026-06-14

### Fixed
- **"Delete my account" silently did nothing if you typed the confirmation in lowercase.** The confirm field on `www/settings.php` is styled `text-transform:uppercase`, which only changes the *display* — the value submitted stayed whatever case the user typed. So typing "delete" showed "DELETE" in the box but the form's own `onsubmit` guard (`value === 'DELETE'`) returned false and quietly blocked submission, so nothing happened. The field now auto-uppercases its value as you type (`oninput`), and both the JS guard and the server-side check (`delete_account` action) compare case-insensitively, so "delete", "Delete", or "DELETE" all work. The account-deletion logic itself was fine (verified against a real account in a rolled-back transaction).

---

## [v0.1977] - 2026-06-14

### Fixed
- **Email verification links appeared "expired" the moment a user clicked them.** `verify_email.php` consumed the one-time token on a plain GET (marking it used and flipping `email_verified` immediately on fetch). Email security scanners and link-preview bots (Outlook SafeLinks, Gmail, antivirus) request every link in a message within seconds of delivery, so the bot burned the token before the recipient clicked, and the human's click then hit an already-used token and showed "invalid or has expired" (the account was, confusingly, already verified by the bot's fetch). The page now uses the same confirm-on-POST hardening as `rsvp.php`: a GET renders a "Verify Email Address" button and touches nothing; only the button's POST consumes the token. Also fixed a related timezone bug in `send_verification_email()` (`www/auth.php`): the 24-hour expiry was written with `date()` in the site's local timezone but compared against SQLite's UTC `datetime('now')`, silently shortening the window by the tz offset; it now uses `gmdate()` so both sides are UTC.

---

## [v0.1976] - 2026-06-14

### Fixed
- **Duplicate contacts piled up for phone-only people.** `auto_add_contact()` (which silently saves invitees to your Contacts when you invite them to an event, import a league, etc.) only checked for an existing duplicate when the contact had an email. Phone-only contacts had no dedup check at all, so inviting the same phone-only person to multiple events created a brand-new contact row every time (one user had 8 copies of several people; 101 rows collapsed to ~48 real contacts). The partial unique index on `user_contacts` also only covers rows that have an email, so it never caught these. `auto_add_contact()` in `www/db.php` now also dedups by phone, and normalizes the phone to E.164 before both the duplicate check and the insert so format differences ("555-1234" vs "(555) 1234") no longer slip through. A one-time cleanup was run against the live and dev databases to collapse each existing duplicate group to a single row (keeping the copy with a linked account/email, else the oldest); contacts that share the same linked account were also merged. No schema change.

---

## [v0.1975] - 2026-06-14

### Added
- **League: import people straight from your Contacts.** The Members tab (owner/manager/admin) gains an "Import from Contacts" button beside the CSV import that opens an in-page panel: a searchable, multi-select checkbox list of your saved contacts. Contacts already in the league are greyed out and disabled (matched by linked account, email, or phone), as are contacts with no email/phone. Selecting people and hitting "Import selected" adds each one with the exact same behavior as the single Add button: contacts that match an existing account join as members (with an "Added to {league}" notice), the rest become pending members and receive a league invite, all gated by the site/per-user notification settings. A status line reports how many were added, invited, and skipped. The single-add and bulk-import paths now share one implementation (`league_add_one_contact()` in `www/leagues_dl.php`, called by the existing `add_contact` action and the new `import_contacts` action); UI in `www/league.php`. Only the caller's own contacts are importable, and re-importing dedups with no duplicate rows. No schema changes.

---

## [v0.1974] - 2026-06-12

### Fixed
- **Email subjects/bodies with non-ASCII characters rendered as mojibake (e.g. "â€”").** Reported via a poll email whose subject contained an em dash. Root cause: `send_email()` in `www/mail.php` never set PHPMailer's charset, so it defaulted to ISO-8859-1 while the app feeds it UTF-8 strings; ANY accented name, smart quote, or emoji in an event title would garble the same way in every notification email, not just polls. PHPMailer is now configured with `CharSet = 'UTF-8'`. The poll email subject separator was also changed from an em dash to a plain ASCII hyphen ("Poll: {title} - {event}") in `www/_notifications.php`, keeping generated strings charset-proof in even legacy mail clients.

---

## [v0.1973] - 2026-06-12

### Fixed
- **Event popup: the Delete button no longer sizes differently from its neighbors.** The action row (`.ev-view-actions` in `www/calendar.php`) is a flex container where Manage Game/Polls/Edit are direct children but Delete sits inside a `<form>`, so when the new Polls button tightened the row, the form-wrapped Delete shrank on a different schedule than its siblings. The forms are now `display: contents` (transparent to flex layout), every button flexes equally with centered labels, and the row wraps instead of squeezing when space runs out (e.g. the admin view's fifth QR button). Also fixed in passing: the hidden delete-occurrence form carried two `style` attributes, so its `display:none` was parser-ignored — harmless only because the form has no visible content; it's now a single attribute.

---

## [v0.1972] - 2026-06-12

### Added
- **Polls can be deleted.** (Follow-up commit under the polls version hold.) Each poll on the manager page has a confirmed Delete action; removal cascades through questions, options, recipients, answers, and any in-flight reply-by-text conversations, and recipients' answer links stop working. Queued-but-unsent poll notifications self-clean on dispatch.
- **Event polls: hosts can poll their Yes/Maybe guests, answerable from email, SMS, WhatsApp, or the web.** A new **Polls** button on the event view (managers only, `can_manage_event()`) opens `www/event_polls.php`, where a host creates a multi-question, single-choice poll that is sent to approved base invitees who RSVP'd yes or maybe. Results are **anonymous by design**: votes are stored per recipient internally (so double-votes are blocked, answers can be changed until close, and the host sees who has/hasn't responded) but no UI ever shows who picked what — only counts and percentages. Delivery rides the unified notification queue as a new `event_poll` type (`www/_notifications.php`, exempt from the daily cap like `event_message`): email carries a button to a personal no-login answer page (`www/poll.php`, tokenized like rsvp.php with the same GET-form/POST-write crawler protection; counts are hidden until the visitor votes, then shown with a "your answer" marker and a change-answers option); SMS and WhatsApp present question 1 with numbered options for **reply-by-text answering** plus the link as fallback. The reply conversation (new `sms_pending_poll` table keyed by phone, 48-hour expiry, invalid answers re-ask) is layered into `www/sms_webhook.php` and `www/wa_webhook.php` after every existing RSVP/admin flow so current texting behavior is unchanged — and it also hooks the unknown-number branch, so phone-only invitees with no account can vote entirely by text. The manager page shows live count bars, turnout, Close/Reopen, and a "Send to new respondents" action that picks up guests who flipped to Yes/Maybe after the original send. Schema: five new tables (`event_polls`, `poll_questions`, `poll_options`, `poll_recipients`, `poll_answers`) plus `sms_pending_poll`, all cascade-deleting with the event; shared helpers live in the new `www/_polls.php`. Verified end-to-end on dev including the full SMS conversation via simulated Twilio webhooks, vote changing, resend targeting, close behavior, and cascade cleanup.

---

## [v0.1971] - 2026-06-12

### Added
- **Timer: attention bounce for the player-management slide-out.** Hosts often didn't discover the player panel that slides in from the right edge. About 2.5 seconds after the timer loads (only when the panel exists — host controlling an event-linked timer with a poker session, the same `$can_control && $event && $session` gate as the panel markup), the panel edge now bounces out three times with decaying amplitude while the Players tray button wiggles in sync (`pp-peek` / `pp-nudge` keyframes in `www/timer.php`). Fires once per page load, skips if the panel is already open, is suppressed in display mode and under `prefers-reduced-motion`, and opening the panel mid-peek cancels the animation cleanly.
- **Timer: auto-fullscreen on first interaction.** Browsers only honor `requestFullscreen()` inside a user gesture, so a true on-load fullscreen is impossible; instead the timer now promotes itself to fullscreen on the first tap, click, or keypress anywhere on the page (once per load, via the existing `goFullscreen()`). Pressing Esc afterwards is respected — it never re-forces. Skipped where the Fullscreen API is unavailable (iPhone Safari, which already hides the Full button) and failures in embedded contexts are swallowed.

### Fixed
- **Timer: white bars at the top and bottom on iPad.** Gradient and image theme backgrounds are background-IMAGEs; CSS fills the canvas beyond the root element's box (iPad overscroll and safe-area gutters) with the background-COLOR only, which the themes never set — so those slivers rendered white. The earlier v0.19311 fix painted `html` with the theme background but inherited the same gap. Both render paths now append a solid base color to the background shorthand (gradients use their own "to" color so letterboxed areas blend with the gradient's edge; images use the theme's base color): `timer_theme_background_css()` in `www/_timer_theme.php` for the server-side first paint and `applyTheme()` in `www/timer.php` for live theme switches. Also added `viewport-fit=cover` to the timer's viewport meta so iPad/iPhone safe areas are part of the painted page, pairing with the tray's existing `safe-area-inset-bottom` padding.

---

## [v0.1970] - 2026-06-12

### Changed
- **The standalone editor page is now the calendar's event editor (Phase 2); the edit modal is retired.** Every entry point navigates to `/event_edit.php` with the correct return context: the "+ Add Event" button, the per-day "+" buttons, the edit pencils on month and week chips (including the JS-rendered week view), the event view's Edit button, My Events' "New Event"/"Edit" links (now direct instead of bouncing through the calendar), and the `?new=1` / `?event=N&edit=1` deep links. A new `EDITOR_CTX` (`m=YYYY-MM` or `wk=YYYY-MM-DD`, matching the active view) rides on those links so saves and cancels land back on the month or week you came from. The modal's HTML and ~750 lines of editor JS were deleted from `www/calendar.php` (now ~970 lines lighter); `regenerateWalkinToken()` was kept since the walk-up QR popup uses it independently. The editor's now-orphaned CSS rules remain temporarily (several classes are shared with the live event-view modal) and will be tidied separately. The `event_edit` screen was added to `HELP_SCREENS` so help bubbles can be authored for the editor page.
- **Removed the dead add/edit save path from `www/calendar_dl.php` (~310 lines).** Nothing in the app posted `action=add/edit` to that endpoint (the modal always posted to calendar.php), and the path had drifted dangerously: its edit branch re-inserted invites without preserving `rsvp_token` (the exact class of bug v0.1960 fixed in the live path) and referenced `valid_from` and `recurrence` columns that do not exist in the schema, so it would have fataled if anything had ever reached it.

### Fixed
- **"Remove this occurrence" was fatally broken in two stacked ways; occurrence-cancellation notifications had never been sent.** First, `www/calendar.php`'s `delete_occurrence` handler called `get_occurrence_invitees()`, which was defined only in `calendar_dl.php` — a file nothing includes — so the request 500'd after writing the exception row (the occurrence vanished but the host saw an error and nobody was notified). Second, the function itself filtered base invites on a `valid_from` column that has never existed in `event_invites`, so even `calendar_dl.php`'s own copy threw "no such column" on every call. Both `get_occurrence_invitees()` and `get_next_occurrence()` now live in `www/db.php` (available everywhere), with the phantom-column filter removed. Verified end-to-end on dev: removing an occurrence now completes cleanly, writes the `event_exceptions` row, and queues `cancel_occurrence` notifications for yes/maybe invitees for the first time.

---

## [v0.1969] - 2026-06-11

### Added
- **Standalone event editor page (Phase 1 of retiring the calendar's edit modal).** New `www/event_edit.php` provides the full add/edit event editor as a dedicated page: `?date=YYYY-MM-DD` prefills a new event, `?id=N` edits an existing one (gated by `can_manage_event()`), `?occ=YYYY-MM-DD` manages a single occurrence's invites, and `m=`/`wk=` carry the calendar view to return to. It uses the same field names/IDs and the complete invite picker (dual panes with search, league scoping via `calendar_contacts_dl.php`, drag-reorder with poker capacity divider, custom invitees, per-invitee contact popup, manager toggles), and lands back on the calendar with the event auto-opened after save. In this phase the page is reachable by direct URL only; the calendar's modal remains the default editor until Phase 2 flips the entry points and deletes the modal.

### Changed
- **Event save logic extracted into a single shared implementation.** The add/edit handler that previously lived inline in `www/calendar.php` (validation, viewer-tz to site-tz conversion, event insert/update, poker session upsert, the RSVP/token-preserving invite replacement from v0.1960, contact/league auto-add, waitlist marking, reminder queueing, and Save & Send queueing) is now `event_save_from_post()` in the new `www/_event_save.php`, called by both the calendar modal's POST path and the new editor page — so the subtle invite-preservation rules exist exactly once. calendar.php shrank by ~380 lines with no behavior change; verified on dev by re-running the v0.1960 race (RSVP arriving mid-edit survives a stale-snapshot save, tokens preserved, new invitees get fresh tokens) through **both** save paths. Discovery recorded for Phase 2: `calendar_dl.php` contains a second, divergent add/edit save path that nothing in the app posts to (dead code — its edit branch still carries the pre-v0.1960 RSVP-clobber pattern); it will be deleted, not consolidated.

---

## [v0.1968] - 2026-06-11

### Added
- **Help-bubble dismissals are now tracked per bubble, so newly added tips reach everyone; plus an "Always show" pin.** Previously the X recorded a whole-screen dismissal (`user_help_dismissed`: user + screen), which meant a user who had dismissed a screen's help would never see any tip added to that screen afterwards. Dismissal now records the individual bubbles that existed at the time in a new `user_help_bubble_dismissed` table (user_id + bubble_id, cascading on user/bubble deletion); a tip added later has no row, so it auto-shows on the user's next visit, presenting only the new tip(s). A one-shot migration converts existing screen-level rows to per-bubble rows (guarded by the `help_dismissals_migrated` setting), so prior dismissals keep meaning "dismissed what existed then"; the old table remains but is no longer read. The Help Tips editor (`www/admin_help.php`/`admin_help_dl.php`) gained an **Always show** checkbox (new `always_show` column): a pinned bubble is never recorded as dismissed, so it reappears every visit and the X only closes it for that page view; the tip list shows a "Pinned" badge. The footer (`www/_footer.php`) now ships the user's *fresh* tips (pinned + undismissed via `help_fresh_bubbles_for_screen()` in `www/db.php`) when any exist, otherwise the full set behind the "?" pill, which replays the complete tour; `help_dismiss_screen()` writes per-bubble rows excluding pinned tips, and the Settings reset clears both tables. `help-bubble.js` is unchanged; the server decides what is fresh. Side effect worth knowing: re-enabling a hidden tip re-surfaces it only for users who never dismissed it.

---

## [v0.1967] - 2026-06-11

### Added
- **Help bubbles can now anchor to elements inside popups and modals (visibility-aware anchoring).** Previously a tip anchored to an element inside a closed modal (e.g. the calendar's Add Event editor, `#editModal`) mis-positioned at the top-left on page load, because bubbles rendered once at load while the target was hidden with a zero-size rect. `www/help-bubble.js` now resolves anchors by visibility: a selector matching a visible element anchors immediately; a selector matching nothing falls back to the corner as before; and a selector matching a **hidden** element makes the tip wait, appearing anchored the moment the element becomes visible (its modal opens) and hiding again when it disappears. If waiting would leave the current tour step with no bubbles at all, the waiting tips show in the corner meanwhile and upgrade to anchored when the popup opens, so a step is never blank and the Back/Next controls never vanish. Implementation: a lightweight 350ms visibility watcher runs only while the tour is on screen and the page has anchored tips (stopped on close/dismiss), with a capture-phase click listener for a fast re-check since modals open from clicks; the watcher also re-pins anchored bubbles through layout shifts. Help bubbles (z-index 9000) render above the site's modal overlays (z-index 200). The admin Help Tips explainer (`www/admin_help.php`) documents the new popup behavior. This also fixes hidden-nav anchors (e.g. desktop-only nav links on mobile) which previously mis-anchored instead of waiting.

---

## [v0.1966] - 2026-06-11

### Changed
- **Help-bubble tour: Next on the last step now ends the tour instead of wrapping to step 1.** The step navigation in `www/help-bubble.js` previously cycled modulo, so clicking Next past the final step rolled back to the first. It now performs a soft close: the bubbles hide and the "?" pill appears, but **no dismissal is recorded**, so the tour auto-plays again from step 1 on the next page load (and the pill restarts it at step 1). Only the X performs the permanent, server-persisted dismissal (`help_dl.php`), exactly as before. Back is now disabled (greyed) on the first step rather than wrapping backward. Single-tip screens are unaffected.
- **Help bubbles restyled dark to stop disappearing into white pages.** The bubble was white-on-white against most screens. It now uses the app's dark-slate palette (`#1e293b` surface, `#334155` border, light text, white title, stronger shadow) matching the nav bar, with translucent Back/Next pills and a matching anchored-pointer tail (`www/style.css`). The accent left edge and accent-blue "?" pill are unchanged.

---

## [v0.1965] - 2026-06-11

### Fixed
- **Timer: loading (or deleting) a blind-level preset failed with "Unknown preset".** When the built-in theme preset library shipped (v0.19310), its gallery script in `www/timer.php` declared `loadPreset(key)` and `deletePreset(key)` — the same names as the long-standing blind-structure preset functions earlier in the same inline script. A later JavaScript function declaration silently replaces an earlier one, so the levels panel's Load/Delete buttons were actually invoking the theme-gallery handlers with an undefined key; the server then posted `preset_key=undefined` to `apply_preset_theme`/`delete_preset_theme`, which correctly rejected it with "Unknown preset". The gallery functions are now `loadPresetTheme()`/`deletePresetTheme()` (callers in `renderPresetCard()` updated, with a comment warning about the name collision), restoring the shadowed blind-preset functions unchanged. Server-side code was never at fault, and the standalone tournament-timer fork is unaffected (it predates the theme gallery).

---

## [v0.1964] - 2026-06-11

### Added
- **Help bubbles can group into multi-bubble steps via a step index.** Each help tip gained an optional **step index** (new nullable `bubble_index` column on `help_bubbles`, migrated with the standard `try/catch ALTER TABLE`). Tips that share the same index number now appear on screen **at the same time** as one step, and Back/Next move between index groups with the counter reading "Step X of N" — so a single step can highlight several places at once (e.g. a corner intro bubble plus an anchored bubble pointing at a button). A tip with no index stays its own step, so every existing tip behaves exactly as before. Non-anchored ("corner") bubbles in the same step stack in a new bottom-right `.help-stack` container instead of overlapping; anchored bubbles sit at their elements. The admin Help Tips portal (`www/admin_help.php`) gained a "Step index" field, shows the index in the tip list, and feeds it through the live preview; `www/admin_help_dl.php` stores it on create/update; `www/_footer.php` emits it as `idx` in the inlined tips JSON; and `www/help-bubble.js` was reworked to group tips into steps and render all bubbles of the current step together. Every bubble in a multi-step tour carries its own Back/Next controls.

### Changed
- **Help bubble carousel uses "Back"/"Next" text buttons pinned to the edges.** The previous circular `‹` / `›` arrow glyphs were replaced with labelled **Back** (far left) and **Next** (far right) pill buttons, with the step counter centered between them (`www/help-bubble.js`, `www/style.css`). Clearer affordance, same navigation behavior.

---

## [v0.1963] - 2026-06-10

### Added
- **In-app help bubbles ("ghost bubble" hints).** Signed-in users now see small, non-focus-stealing rounded chat bubbles with contextual tips on selected screens, authored entirely from an admin portal. A new **Help Tips** tab on Site Settings (`www/admin_help.php`, backed by the `www/admin_help_dl.php` JSON CRUD endpoint) lets an admin pick a screen and write any number of tips for it, reorder/hide/delete them, and use a live "Show example" preview that renders the real bubble component in-page. Screens are a curated whitelist (`HELP_SCREENS` in `www/db.php`, keyed by page basename) and the portal shows the URL each screen maps to. Tips are stored in a new `help_bubbles` table (title, body, optional anchor selector, sort order, enabled); each tip can either float in the bottom-right corner or **anchor** to a page element by CSS selector, with a pointer tail and automatic, safe fallback to the corner when the selector matches nothing. On every page the shared footer (`www/_footer.php`) inlines that screen's enabled tips as JSON (no extra round-trip) and loads `www/help-bubble.js`, which renders a single carousel bubble ("N of M" with Prev/Next), a close button, and a "?" re-open pill. Bubbles auto-show on each visit until the user dismisses that screen; dismissal is recorded per user in a new `user_help_dismissed` table via `www/help_dl.php`, and users can bring every dismissed bubble back from a new "Help Tips" card on their own Settings page (`www/settings.php`, `reset_help` action). New helpers in `www/db.php`: `help_bubbles_for_screen()`, `help_screen_dismissed()`, `help_dismiss_screen()`, `help_reset_user()`. Styling lives in `www/style.css`.

### Changed
- **Site Settings tab bar extracted into a shared partial and shown across all admin sub-pages.** The tab strip that previously lived inline in `www/admin_settings.php` is now `www/_admin_tabs.php`, included by the settings page, the API Keys audit page (`www/admin_api_keys.php`), and the new Help Tips page so the tabs stay visible and the active tab is highlighted no matter which page you're on. The `.tabs`/`.tab-btn` styles moved into `www/style.css` (single source of truth) and now wrap to a second row on narrow desktops instead of overflowing the page; all three pages share the same `.dash-wrap` container so the bar lines up at identical width and padding. The former "Dashboard" tab is relabeled **Site Settings** (same destination) and the redundant "Site Settings" page heading was removed, since the tab now names the section.

---

## [v0.1962] - 2026-06-09

### Added
- **Admin & host event management over SMS.** Site admins and event owners/managers can now run their events by text, not just RSVP to them. A new command layer (`www/sms_admin.php`, invoked from `www/sms_webhook.php` right after the inbound phone-to-user lookup) recognizes elevated commands and gates them behind `can_manage_event()` per event, so a host only ever acts on events they actually manage while a site admin sees them all. Commands: `ADMIN`/`HELP` lists your manageable upcoming events (numbered) plus the command syntax; `WHO #` shows the roster split by RSVP, `COUNT #` the headcount only (both include pending/waitlist tallies); `PENDING` lists outstanding approval/waitlist requests by event with names; `APPROVE # name` approves a pending or waitlisted guest immediately; `MSG # text` broadcasts a free-text message to an event's guests; `REMIND #` sends an on-demand reminder to all approved guests; and `CANCEL #` cancels the event and notifies everyone. The three fan-out/destructive commands (`MSG`, `REMIND`, `CANCEL`) are two-step: they reply with a plain-language summary ("Cancel \"Poker Night\" (Jun 12)? 9 will be notified. Reply CONFIRM.") and only execute when the sender texts `CONFIRM` within 10 minutes, tracked in a new `sms_pending_admin` table (one in-flight action per user, auto-expired) and re-checked for permission at execution time. Event numbers come from a deterministically ordered "manageable events" list so a number stays stable between texts, the same numbered-selection pattern the existing RSVP flow uses. Non-elevated texters who send an admin verb fall through to the normal RSVP/HELP handling with no behavior change and no information leak. Per request, these admin command replies deliberately omit the carrier "Reply STOP to unsubscribe" footer that user-facing messages carry. `MSG`/`REMIND` reuse the existing in-app broadcast path (an `event_messages` row plus queued `event_message` notifications, which deliver via each recipient's preferred channel), and every state change is written to the audit log with a "via SMS" suffix.

### Changed
- **Event cancel and invite-approval logic centralized into shared helpers.** The notify-then-delete sequence behind deleting an event and the status-flip-plus-poker-sync-plus-notify sequence behind approving a pending invitee were previously inline in `www/calendar_dl.php` (delete) and `www/calendar.php` (approve). They are now `cancel_event_with_notifications()` and `approve_event_invitee()` in `www/_notifications.php`, called by both the in-app handlers and the new SMS commands so the behavior stays identical across entry points. `send_sms()` in `www/sms.php` gained an optional `$append_optout` parameter (default true, so every existing caller is unchanged); `respond_to_provider()` in the webhook threads it through so only admin command replies suppress the footer.

### Fixed
- **Deleting an event that had ever sent a broadcast message failed with a foreign-key error.** `event_messages` carries a `FOREIGN KEY (event_id) REFERENCES events(id)` without `ON DELETE CASCADE`, and the event-delete sequence cleared `event_invites`, `event_exceptions`, and notification rows but never `event_messages` — so with foreign-key enforcement on, the final `DELETE FROM events` raised "FOREIGN KEY constraint failed" (an HTTP 500 with the invites already gone but the event left behind). The shared `cancel_event_with_notifications()` now deletes the event's `event_messages` rows before the event itself, fixing both the in-app delete and the new SMS `CANCEL`. Surfaced while testing SMS cancel on the dev instance against an event that had been messaged.

---

## [v0.1961] - 2026-06-09

### Fixed
- **Event editor: the "already invited" marker now appears for pending phone-only/email-only contacts.** When moving a person from the left "all users" pane into the invited pane, their left-pane row is supposed to grey out with a green checkmark (the `.dimmed` state) so you can see they are already invited. This worked for registered users but not for pending (non-registered) contacts invited by phone or email. `syncInviteState()` in `www/calendar.php` matched each invited row's name (`data-iname`) against the left row's `data-username`, which for a pending contact is its lookup key (the phone or email, e.g. `646-457-8862`) rather than its display name — so the comparison never matched and the row was never dimmed. It now matches against the left row's `data-uname` (the display name, the same value used as the invited row's name), lowercased to match. Registered users are unaffected (their `uname` and `username` are identical); pending contacts now get the marker too. Purely visual — invites and duplicate-prevention were already working.

---

## [v0.1960] - 2026-06-09

### Fixed
- **Editing an event no longer wipes invitees' RSVPs.** An invitee who RSVP'd (via their one-click link or SMS) after a host opened the event editor would have their answer silently cleared the next time the host saved the event. Root cause: the invite-save in `www/calendar.php` rebuilds the invite rows and took each `rsvp` value from the editor's `invite_rsvp[]` field, which is only a hidden snapshot loaded when the editor opened (there is no RSVP control in the editor), so it overwrote any RSVP that arrived in the meantime. It already preserved each invitee's `rsvp_token`/`rsvp_token_flips` across the save (to keep emailed links alive) but not the RSVP itself. The save now preserves the stored `rsvp` for invitees who were already on the event the same way the token is preserved; only genuinely new invitees take the (normally blank) submitted value. The parallel AJAX save handler `www/calendar_dl.php` had the same class of bug and additionally regenerated tokens on every save (breaking already-sent one-click links); it was hardened to snapshot and preserve `rsvp`, `rsvp_token`, and `rsvp_token_flips` as well. Verified on the dev instance by reproducing the exact race (record a YES, then re-save the editor with a stale blank form plus a new invitee) and confirming the YES and token survive. A one-time reconcile restored RSVPs that had already been wiped, sourced from the `activity_log` audit trail (which was never affected).

---

## [v0.1959] - 2026-06-09

### Fixed
- **Phone-only contacts were silently missing from the event invite picker.** A user could not invite a personal contact (e.g. "Shannon") to an event when that contact had been saved with a phone number but no email and was not yet a registered user. In `www/calendar_contacts_dl.php` the personal-contacts query derived each candidate's dedup key as `COALESCE(u.username, LOWER(c.contact_email))`; for an unlinked, email-less contact both terms are NULL, so the key was empty and the shared `_add_seen()` helper (which drops rows with an empty key) silently removed the contact from the list. Because the personal-contacts set is built once and reused, this affected **both** the no-league picker and the league-event picker, not just league events. The query now uses the same fallback chain already used for pending league contacts: `COALESCE(u.username, NULLIF(LOWER(c.contact_email), ''), NULLIF(c.contact_phone, ''), 'contact:' || c.id)`, so a contact always gets a non-empty key (email, else phone, else a synthetic per-row id). Linked and email contacts are unaffected, and a contact who is also a pending league member still dedupes correctly because both sources now emit the same phone-based key. Verified end-to-end on the dev instance with a reproduced phone-only contact appearing in both picker paths, tagged "Not a member" and invitable.

---

## [v0.1958] - 2026-06-09

### Added
- **Admin "Reports" tab: user growth, verification, and event engagement at a glance.** Previously the only way to see signup trends or verification/tier breakdowns was to SSH into production and run ad-hoc SQLite queries; the admin Dashboard showed just four raw counters. A new **Reports** tab in `www/admin_settings.php` (registered in the `$tab` whitelist, with a tab button next to Dashboard and a shortcut button in the Dashboard quick-links row) surfaces these metrics in the browser. It renders: a stat-card grid (total users, new in 7 days, active in 7 days / 24 hours via `users.last_login`, email-verified, phone-verified, MFA-enabled, paid-tier counts); a **signups-per-day** trend for the last 21 days drawn as lightweight inline CSS bars (zero-filled in PHP so empty days show as blank bars, UTC-aligned to match how SQLite groups `created_at`, no JS charting library added); a **newest-users** table (15 most recent with email/phone verified badges, role, tier, joined and last-login dates); and an **event-engagement** card (events total, events in 7 days, upcoming, invites total, yes-RSVPs). All report queries are gated behind `if ($tab === 'reports')` so they add no cost to other tab loads, reuse the page's existing admin-only guard and the shared `.stats` / `.stat-card` / `.card` / `.table-card` styles, cast counts with `(int)`, and escape dynamic output with `htmlspecialchars()`. Verified end-to-end on the dev instance (authenticated render, no PHP warnings, counts matched direct SQLite).

---

## [v0.1957] - 2026-06-09

### Added
- **Audit-log coverage for contacts, league membership/roles, and poker session lifecycle — previously-blind feature areas now leave a trail.** An audit found that whole feature areas wrote to the database with no `activity_log` entry, so admin "Logs" showed nothing for them. This release adds 31 logging points through the existing `db_log_activity()` / `db_log_anon_activity()` helpers (info severity, IP auto-captured): **`www/contacts_dl.php`** logs add/update/delete/CSV-import of a user's contacts (the `update` row records the field name and contact id only, never the value, since contact email/phone are PII); **`www/leagues_dl.php`** logs create, update, join (auto + request), cancel, approve/deny request, remove member, invite (linked + pending), role change, resend invite, leave, promote/demote, **transfer ownership**, and regenerate invite code; **`www/join_league.php`** logs auto-join and join-request via invite link (with a "via invite link" suffix to distinguish the entry point, and a guard so re-visiting the link does not duplicate the row); and **`www/checkin_dl.php`** logs the poker **session lifecycle** — init, status change, walk-in add, player remove, payout edits, and payout-structure save/load/delete/set-default. High-volume poker micro-actions (buy-in toggles, rebuys, add-ons, eliminations, cash-ins, table moves) are deliberately left unlogged so they do not bury meaningful events in the 50-row-per-page log. All paths were verified end-to-end on the dev instance.

### Changed
- **RSVP-via-link logging now routes through the shared helper instead of a raw INSERT.** `www/rsvp.php` previously wrote its "Email RSVP" audit rows with a direct `INSERT INTO activity_log` using `$_SERVER['REMOTE_ADDR']`. It now calls `db_log_activity()` (matching invitee) / `db_log_anon_activity()` (pending, account-less invitee, `user_id=0`), preserving the exact action text. Side benefit: the IP is now recorded via `get_client_ip()` — the same proxy-aware (`X-Real-IP` / `X-Forwarded-For`) source every other audit row uses, instead of the raw remote address — and the action text gets control-character stripping.

---

## [v0.1956] - 2026-06-08

### Changed
- **Login is two-step again: the two-factor code field no longer shows for accounts without 2FA.** v0.1954 put the code field permanently on the login form (so 1Password could fill username + password + code in one shot on iPad), but that meant every visitor saw a "Two-factor code" field even though most accounts have no 2FA. `www/login.php` now renders two states: a clean first screen (email/username/phone + password + remember-me) and, only after a correct password on a 2FA account, a dedicated "Two-factor authentication" screen with the code field, a "Signing in as <account>" line, the recovery-code option, an SMS "Resend code" link, and a "Back to sign in" link. The second factor is therefore shown only to 2FA accounts and only after the right password (`attempt_login()` still returns `mfa_required` only post-password, so no account enumeration). Remember-me and the redirect target are stashed in the session at the prompt step so they survive the password-less code submit; a new `?reset=1` handler clears the pending step. Trade-off: the code is no longer auto-filled on iPad for 2FA accounts (the field doesn't exist on screen 1), so those users type or paste it on screen 2 — an accepted exchange for a clean form for the majority.

---

## [v0.1955] - 2026-06-07

### Fixed
- **Event view card: action buttons could be pushed off-screen on iPad.** Opening an event with a lot of content (long description, many invitees, RSVP block) in the `#viewModal` card on `www/calendar.php` could push the Manage Game / Edit / QR / Delete buttons below the bottom edge with nothing to scroll, because the top block was a non-shrinking flex child inside a `max-height:88vh; overflow:hidden` modal. The card now uses a **pinned header + a single scrollable body** (`#vScrollBody`): everything under the title (event info, invitees, the action buttons, and comments) scrolls together, so the buttons are always reachable; the scroll resets to the top on open and posting a comment still jumps to the newest one. Additionally, the card now takes over **full-screen on iPad in both orientations** (it already did so in portrait via the `≤1024px` breakpoint; a `pointer:coarse` + `1025–1366px` query now covers landscape, where the iPad is wider than 1024px and previously fell back to the cramped centered card). The modal sizing was moved to id-scoped CSS so the orientation overrides win on specificity.

---

## [v0.1954] - 2026-06-07

### Changed
- **Two-factor code field is now always visible on the login form (1Password autofill on iPad).** The inline two-step flow (v0.1952/v0.1953) only revealed the code field *after* the password was submitted, but iOS/1Password only fill fields that are actually visible at the moment autofill is triggered, so on the iPad 1Password filled username + password but left the (not-yet-rendered) code blank, forcing a manual copy-paste of the TOTP. The login form now carries the **code field from the first render**, under the password and labeled "Two-factor code (only if 2FA is on)" with `autocomplete="one-time-code"`, so a password manager can fill username + password + code in a single action. `www/login.php` verifies the code inline when it's submitted alongside the credentials (single submit), and still falls back to the prompt-then-enter two-step for users who type it manually (the password-less second submit is verified against the pending `$_SESSION['mfa_user_id']`). TOTP, SMS (with "Resend code"), and recovery codes are all handled; accounts without 2FA simply leave the field blank. The per-account brute-force cap (8 fails / 15 min) is preserved and `www/mfa_challenge.php` stays retired.

---

## [v0.1953] - 2026-06-07

### Fixed
- **Login (inline 2FA step): username was uneditable and scrolled off-screen on iPad.** On the second-factor step the username field was `readonly` (so it couldn't be tapped/typed) and the code field had `autofocus`, which on iOS pops the keyboard and scrolls the username above the top of the screen. Removed `readonly` from the username (it stays prefilled but is now interactive) and dropped `autofocus` on the code field so the form loads at the top with all fields reachable (`www/login.php`).

---

## [v0.1952] - 2026-06-07

### Changed
- **Two-factor code prompt moved inline onto the login page (was a standalone page).** Password managers (1Password on iPad) couldn't reliably fill the code on the separate `mfa_challenge.php` page because it wasn't recognizable as the login. Now, when an account has MFA, submitting the password re-renders the **login page** with the **code field appearing under the password** (username + password + one-time-code on one form), the layout 1Password recognizes and fills. `www/login.php` now handles both steps: credentials, then (if `attempt_login()` returns `mfa_required`) the inline code step, verifying TOTP/SMS or a recovery code and completing login with the same remember-me/redirect handling. SMS gets a "Resend code" link; a "Use a different account" link clears the pending step. `www/mfa_challenge.php` is retired (redirects to `/login.php`).

### Fixed
- (carried) Earlier 1Password recognition attempts on the standalone page (hidden then visible username field) are superseded by the inline layout above.

### Fixed
- **Two-factor code prompt now exposes a visible account field so 1Password recognizes it (iPad).** The v0.1950 off-screen username hint wasn't enough. iOS/1Password ignore hidden/off-screen fields when deciding what a page is. Replaced it with a visible, read-only **Account** field (`autocomplete="username"`, the account email) above the code input on `www/mfa_challenge.php`, which is the reliable signal for password managers to associate the page with the saved login and offer its stored one-time code. The field is read-only and ignored on submit; the code field keeps `autocomplete="one-time-code"`.

### Fixed
- **Password managers (1Password) can now recognize the two-factor code prompt for autofill.** The MFA challenge page (`www/mfa_challenge.php`) had only the code input and no account field, so 1Password (notably on iPad/iOS) couldn't associate the page with the saved gamenight.poker login and wouldn't offer its stored one-time code. Added an off-screen, read-only `username` field (`autocomplete="username"`, valued with the account's email) as the first field in the challenge form so managers link the page to the right login item. it's `position`-hidden (not `display:none`, which managers skip), `tabindex="-1"`/`aria-hidden`, and ignored on submit (the handler keys off the session). The code field keeps `autocomplete="one-time-code"`. (Note: 1Password still only offers a code if that login item actually has a one-time password/TOTP saved on it.)

### Changed
- **Host "Message guests" notifications now show who sent it and which event.** The SMS was previously just `"<Event>": <link>` with no sender or context. It now reads as two lines: `<Site>: new message from <Sender> about "<Event>".` then `Tap to read: <short link>` (plain ASCII to stay a single SMS segment). The sender is the message's author (`event_messages.created_by`, falling back to the site name if that account is gone). The same `From <Sender> · <Event>` attribution is prepended to the WhatsApp text and the email body for consistency. Implemented in the `event_message` case of `dispatch_queued_notification()` in `www/_notifications.php` (query now joins the author; no schema/UI changes).

### Fixed
- **Host-message history disappeared after a reload / second send.** The event detail panel's "Messages from the host" list would show right after the first send but then vanish once the page reloaded or another message was sent. `www/calendar.php` emitted the message data with `json_encode($ev_messages, JSON_HEX_TAG | JSON_FORCE_OBJECT)`, and `JSON_FORCE_OBJECT` applies **recursively**, so each event's array of messages was serialized as an object (`{"0":…,"1":…}`) instead of an array. In `renderInvitesPanel()` that made `msgs.length` `undefined` (so the list was skipped) and the compose success handler's `eventMessages[eid].push(...)` throw (swallowed by its try/catch). Fixed by encoding as `json_encode((object)$ev_messages, JSON_HEX_TAG)` — the top level stays an object (incl. empty `{}`) while each event's message list stays a real array — plus a defensive `Array.isArray()` guard before the client-side push. (`eventComments` was already encoded correctly, which is why comments never had this issue.)

### Added
- **"Reset & re-link" for the WhatsApp (WAHA) session in admin settings.** The WhatsApp Connection panel (Site Settings → WhatsApp) could show status / start / stop / QR, but had no way to recover a session whose WhatsApp link was revoked (logged out): plain **Start** just reconnects to the now-dead stored credentials and lands back on `FAILED`, never reaching the QR-scan state, so re-linking previously required SSH + the WAHA API. A new **Reset & re-link** button (and a `waha_logout` AJAX action in `www/admin_settings_dl.php`) logs the session out to clear the stale credentials, restarts it so it drops into `SCAN_QR_CODE`, and the existing QR + status polling then guides re-linking from the browser. The action is admin-only and CSRF-protected, with a confirm dialog noting it unlinks the current account and that WhatsApp won't send until re-linked. Implemented in `www/admin_settings.php` (button + `wahaReset()` JS) and `www/admin_settings_dl.php` (handler using the modern `/api/sessions/{name}/logout` + `/start` endpoints).

### Changed
- **Phone "Verify" control moved next to the phone field on Settings.** On the My Settings Profile card (`www/settings.php`), the phone-verification UI (Verify-number button, the 6-digit code entry, or the "✓ Verified" indicator) previously rendered at the bottom of the card, after Save Profile, disconnected from the phone field. It now sits **inline with the phone number**. Because the phone `<input>` lives inside the `update_profile` form and HTML forms can't nest, the verify actions are kept as hidden sibling forms (`#phSendForm` / `#phVerifyForm`) and the visible controls are associated with them via the HTML `form="…"` attribute, so they submit independently and the code input is never swept into a profile save. No server-side changes (the `send_phone_code` / `verify_phone_code` handlers are unchanged).

### Added
- **Hosts can message their going guests ("final details").** A new **Message guests** action on an event (owner/manager only, in the invite panel) opens a rich-text composer (Jodit) with a **subject**, an **audience** selector (Going / Going & Maybe / All invited), and a body. The note is delivered through the existing notification queue by each guest's preferred channel, with channel-appropriate content: **email** gets the full formatted message plus a "view in browser" link, **WhatsApp** gets the full message as plain text plus the link, and **SMS** gets a short link only (so a long address isn't crammed into a text). Each send is stored in a new `event_messages` table (`www/db.php`) and is readable at a tokenized, login-free page `www/event_message.php?token=…` (the SMS/email link target). Sent notes also appear as a read-only **"Messages from the host"** history in the event panel, audience-gated so an owner/manager sees every message while a guest sees only those addressed to them. Owners/managers can **delete** a message (kills its view link; already-delivered emails/texts can't be unsent). New files: `www/event_message.php`. Touches `www/calendar.php` (compose modal + `send_event_message`/`delete_event_message` handlers + history render), `www/_notifications.php` (`event_message` dispatch case), and `www/auth.php` (`send_notification()` gained an optional separate WhatsApp body).

### Fixed
- **Duplicate notification delivery under concurrent queue drains.** The queue drain's row-claim in `www/cron.php` and `www/cron_drain.php` updated `attempted_at` and then *verified the claim with a `SELECT ... WHERE attempted_at IS NOT NULL`* — which is true for a losing concurrent drain too, so two drains could both dispatch the same row (recipient gets it twice; only one dedup marker written since the marker is recorded after sending). The claim is now truly atomic: it proceeds only when the `UPDATE ... WHERE attempted_at IS NULL` actually changed a row (`rowCount() >= 1`). This protects every notification type (invites, reminders, RSVP replies, host messages). The new `send_event_message` path also no longer spawns a redundant second drain (`queue_event_notification()` already schedules one), which is what reliably exposed the race.
- **AJAX actions on a long-open calendar tab showed "Network error" instead of a real message.** When a page's CSRF token went stale, `www/calendar.php` answered AJAX POSTs with a 302 redirect to HTML, which the `fetch()` couldn't parse. it now returns JSON (`Your session expired. Please reload…`) for XHR, and the permission gate does likewise. The host-message composer also no longer mislabels a *successful* send as an error: the success path is acknowledged first and the in-place history refresh is best-effort.
- **Event owners couldn't see the host-message history.** The history's visibility gate keyed off the manager list, which is built from per-event-manager invites and league roles but does **not** include the event's creator (a creator's authority comes from `events.created_by`, not a manager invite). The gate now treats the creator as a manager, matching `can_manage_event()` and the client's `_calCanManage`, so owners see their event's full message history.

---

## [v0.1944] - 2026-06-07

### Fixed
- **RSVP replies weren't reaching event owners or managers.** Two compounding, channel-agnostic bugs in the `rsvp_to_creator` notification path (so email, SMS, and WhatsApp were all affected):
  1. **Owners got only the first RSVP per event.** `dispatch_queued_notification()` in `www/_notifications.php` dedups sends via `event_notifications_sent` keyed on `(event_id, occurrence, user, type_tag)`, but only `reminder` added a discriminator. `rsvp_to_creator` used the bare type string, so the first RSVP to an event wrote a marker for the creator and **every later RSVP** (any responder, any change) matched it and was silently dropped. Fixed by discriminating the tag per queued row (`rsvp_to_creator_<row_id>`): retries of the same row still dedup, but distinct RSVPs are never collapsed.
  2. **Managers were never notified.** All four RSVP write paths queued only to `events.created_by`. A new shared helper `queue_rsvp_reply_notifications()` in `www/_notifications.php` now fans out to the event owner **and** every per-event manager (`event_invites.event_role = 'manager'`), excluding the responder. It replaces the creator-only logic in `www/rsvp.php` (token RSVP), `www/calendar.php` (logged-in RSVP), `www/sms_webhook.php` (`notify_creator_of_rsvp()`), and `www/wa_webhook.php` (`_wa_notify_creator()`), each still gated on an actual RSVP change.
- **RSVP replies exempted from the 20/day per-recipient cap.** `queue_event_notification()` now treats `rsvp_to_creator` like `reminder` (uncapped and uncounted), so an active event's replies are no longer silently throttled for a busy owner/manager once delivery works.

---

## [v0.1943] - 2026-06-06

### Added
- **Dashboard nudge to turn on two-factor authentication.** Any logged-in user who hasn't enabled MFA now sees a dismissible banner at the top of the dashboard (`www/index.php`) inviting them to set it up, with a **Set up** link to `mfa_setup.php` and a one-time **Dismiss**. It's backed by a new `users.mfa_offer_dismissed` column (auto-migrated in `www/db.php`, default `0` so every MFA-less user is nudged once). The flag is set to `1` when the user dismisses the banner (a CSRF-protected self-POST handled at the top of `index.php`) or when they enable MFA (`www/mfa_setup.php`), so it never reappears, even if they later disable MFA. `current_user()` in `www/auth.php` now selects the flag. The banner shows only while `mfa_enabled = 0 AND mfa_offer_dismissed = 0`.

---

## [v0.1942] - 2026-06-06

### Fixed
- **Password managers (1Password etc.) didn't autofill the MFA code on mobile.** The two-factor challenge in `www/mfa_challenge.php` used a single `type="text"` field with `maxlength="20"` that doubled as both the 6-digit code input and the recovery-code input. Although it carried `autocomplete="one-time-code"`, the long, free-text, dual-purpose field lowered password managers' / iOS / Android confidence that it was a one-time-code field, so saved OTPs weren't offered on mobile. The primary field is now a dedicated numeric one-time-code input (`maxlength="6"`, `inputmode="numeric"`, `pattern="[0-9]*"`, `autocomplete="one-time-code"`, `aria-label="One-time code"`), and recovery codes moved to a separate `recovery_code` field behind a "Lost your device?" disclosure (with autocapitalize/autocorrect/spellcheck off). The POST handler now reads the two inputs independently. (Note: 1Password only offers a code when the saved gamenight.poker login item actually has a one-time password/TOTP stored on it.)

---

## [v0.1941] - 2026-06-05

### Fixed
- **Existing users couldn't verify a phone number, which made SMS two-factor unreachable.** `www/settings.php` had the Surge-based `send_phone_code` / `verify_phone_code` POST handlers but rendered **no UI** to trigger them, so a user who added a phone to an existing (email-verified) account had no way to verify it. `phone_verified` stayed `0`, and since the SMS option in the two-factor setup is gated on a verified phone, it never appeared. Added a phone-verification block to the Profile card (it can't live inside the profile `<form>`, so it's a sibling): a **Verify phone number** button when an unverified phone is on file, a 6-digit code entry with a **Resend** link while a verification is pending, and a green "Phone number verified" indicator once done. Wired to the existing handlers (Surge `/verifications` API), so it works wherever the Surge SMS provider is configured. Save the number first if it was just changed, since the handler reads the stored phone.

---

## [v0.194] - 2026-06-05

### Added
- **Opt-in two-factor authentication (TOTP authenticator app or SMS), with recovery codes.** Each user can now turn on a second sign-in step from **Settings → Two-Factor Authentication**. Enrollment lives on a dedicated full page, `www/mfa_setup.php`: choose a method, then either scan a QR code into an authenticator app (Google Authenticator / Authy / 1Password / etc.) and confirm a code, or — when a verified phone and an SMS provider are configured — receive a texted code and confirm it. On enabling, ten single-use recovery codes are shown once. TOTP is implemented from scratch (RFC 6238) in a dependency-free `www/totp.php` (base32, HMAC-SHA1, ±1 step skew); the enrollment QR is rendered client-side with the already-bundled `qrcode-generator` library (the same `createDataURL()` → `<img>` path as the walk-in QR). The secret is stored encrypted at rest via the existing `encrypt_value()`/`decrypt_value()` helpers.
- **MFA challenge at login.** `attempt_login()` in `www/auth.php` now returns `'mfa_required'` for MFA-enabled accounts instead of completing the session; `www/login.php` carries the remember-me intent and redirect into a new challenge screen, `www/mfa_challenge.php`, which accepts a TOTP/SMS code **or** a recovery code and finalizes the deferred login via the extracted `complete_login()` helper. A brute-force cap (8 failures / 15 min, logged as `failed_mfa`) protects the screen. Ticking **Remember me** issues the existing 30-day trusted-device token, which silently restores the session on that browser without re-prompting (the `consume_remember_cookie()` path is unchanged and correctly bypasses MFA).
- **Admin “Reset Two-Factor” recovery.** A user who loses both their authenticator/phone and all recovery codes has no self-service way back in (by design — email is never a bypass). An admin can now clear it from `www/user_edit.php`: the Reset Password card gained a Two-Factor section (and the Account Info table a Two-Factor row) with a confirm-gated **Reset Two-Factor** button that disables MFA and deletes the user’s recovery + pending SMS-MFA rows. The override is audit-logged at `critical` severity. (One residual edge case: if the locked-out user is the only admin, recovery still needs direct DB access.)

### Changed
- **Settings page layout: Two-Factor is grouped under Change Password.** In `www/settings.php`, the Change Password and Two-Factor Authentication cards now share the right-hand column (stacked), with Profile in the left column; on mobile they stack in order. The 2FA card itself only shows status plus a link into the full-page setup flow, Regenerate Recovery Codes, and a password-gated Disable.

### Database
- **New MFA schema (auto-migrated).** `db_init()` in `www/db.php` adds `users.mfa_enabled`, `users.mfa_method`, and `users.mfa_totp_secret` (via the existing try/catch `ALTER TABLE` pattern) and creates the `mfa_recovery_codes` table (single-use, sha256-hashed). `delete_user_account()` now also clears `mfa_recovery_codes`. SMS MFA reuses the `phone_verifications` table tagged with `method='mfa'`, kept separate from signup verification and without flipping account-verification flags.

### Fixed
- **Forgot-password no longer fakes “sent” when rate-limited.** Previously, once a device hit the 3-resets-per-hour cap, `www/forgot_password.php` showed the success message while silently sending nothing, which read as a broken email pipeline. It now shows an honest "Too many password reset requests from this device. Please wait up to an hour and try again." Because the limit is keyed on IP/device (not on a specific account), this does not reveal whether any given account exists — the generic success message that protects against account enumeration is unchanged.

---

## [v0.19328] - 2026-06-05

### Fixed
- **Invites to imported contacts could be left with no email/phone, so they couldn't be sent.** An invite captures the invitee's email/phone at the moment they're added. If a contact was added to an event before its email was on file (e.g. the contact was imported name-only, then emails were added later), the `event_invites` row kept its empty contact and nothing ever reconciled it against the host's saved contacts — the editor showed "NA" and `dispatch_queued_notification()` in `www/_notifications.php` silently gave up (`return true`) because the row had no address. A new shared helper `invitee_contact_from_contacts()` in `www/db.php` resolves an invitee's email/phone from the event creator's `user_contacts` (matched by contact name or a linked user's username). It's applied in two places: (1) the send path back-fills a contactless invitee from the creator's contacts and **persists** it to `event_invites` so the data self-heals after the first send; (2) `www/calendar.php` back-fills the editor's invite data on load so the host sees the real contact (envelope/phone icon instead of NA), and re-saving keeps it. Creating a new event and adding contacts from the picker already carried emails and is unchanged.

---

## [v0.19327] - 2026-06-04

### Added
- **Per-invitee contact icons with inline editing in the event editor.** Each row in the editor's "Invited" list now shows a clickable contact indicator next to the name: an envelope (&#9993;) when an email is on file, a phone (&#9742;) when a phone is on file, both when both, or a red **NA** when neither. Clicking it opens a small popup with **Email** and **Phone** fields pre-filled from that invitee; saving writes the values back to the row and they persist on save (the form already submits `invite_email[]`/`invite_phone[]`, and `save_invites()` stores them). This gives hosts a direct way to fix invitees who can't be notified (e.g. a custom-typed guest added with no contact). Implemented in `www/calendar.php` (`inviteUser()`, `invContactCtlInner()`, `openContactEdit()`/`saveContactEdit()`, and the `#invContactModal` markup).
- **Column header + fixed columns for the Invited list.** The editor's Invited list gained a header row labeling **Name / Contact / RSVP**, and the trailing controls (contact icon, RSVP badge, Mgr toggle) now sit in fixed-width columns so the header lines up with every row. The Mgr column has no header label since the toggle is self-labeled.
- **"Can't be notified" warning in the event view.** `renderInvitesPanel()` in `www/calendar.php` now flags invitees with no email and no phone: they're excluded from the "Invitations not sent to N" count (they could never be reached) and called out in a separate red warning, plus a small "no contact" tag on their row. Driven by a server-computed `no_contact` boolean added to the invite data in `www/calendar.php` and `www/event_invites_dl.php`.

### Changed
- **Invitee contact details (email/phone) are no longer stripped from the calendar's invite data.** Previously phone/email were removed from the per-event invite JSON for privacy; they're now kept so the editor can show and edit each invitee's contact inline. Affects the page-load data in `www/calendar.php` and the poll endpoint `www/event_invites_dl.php`.
- **Public event page: the no-reply group is now labeled "Invited" instead of "Pending".** In `www/event.php`, approved invitees who haven't responded yet show under an **Invited** heading (alongside Going / Maybe / Can't make it), which reads more naturally than "Pending".

---

## [v0.19326] - 2026-06-04

### Fixed
- **Already-invited contacts showed no "invited" checkmark when editing an event.** In the event editor (`www/calendar.php`), opening an existing event loads its invitees and calls `syncInviteState()` to dim/checkmark the matching names in the left contact list. But the editor then kicks off `refreshUserList()`, an async fetch that rebuilds the left list via `buildAllUsersList()` when it returns — wiping the dimming and never re-applying it, so editors opened with no checkmarks until the host manually removed and re-added someone (which re-triggered the sync). Creating a new event was unaffected because its checkmarks are applied per-add, after the list already exists. `buildAllUsersList()` now calls `syncInviteState()` at the end, so the invited-state styling is re-applied on every (re)build of the contact list, including async refreshes and league-filter changes.

---

## [v0.19325] - 2026-06-04

### Fixed
- **QR codes rendered as a solid black square in some browsers.** Every QR code in the app (walk-up registration modal in `www/calendar.php`, the "Open on separate screen" display `www/walkin_display.php`, the duplicate copy in `www/calendar_dl.php`, and the remote-timer code in `www/timer.php`) was drawn by hand onto an HTML `<canvas>` — looping the module matrix and painting each dark cell with `fillRect`. That manual canvas path produced a fully black bitmap in some browsers while the QR data itself was valid, so scanning was impossible. All four now render via the qrcode-generator library's own `createDataURL()` into an `<img>` (with `image-rendering: pixelated` to stay crisp when scaled), which is the library's supported output path. No change to the encoded URLs or the surrounding modal/click behavior.
- **Editing an event dropped you back on the bare calendar instead of the event.** The post-save redirect in `www/calendar.php` only appended the auto-open suffix (`&open=<id>&date=<date>`) for the `add` action, so creating an event reopened its detail view but editing one landed on the month grid with nothing open — the host lost the "Send Invitations" prompt and RSVP roster they were working from. The redirect now emits the suffix for `edit` as well, reusing the existing generic `?open=ID&date=DATE` auto-open path, and picks the edited occurrence's date when a single occurrence was managed (otherwise the event's start date), so the page lands back on the event the host just saved.

---

## [v0.19324] - 2026-06-04

### Fixed
- **RSVP links broke the moment a host edited and re-saved an event.** Saving an event runs `save_invites()` in `www/calendar.php`, which deletes every `event_invites` row for the scope and re-inserts them — and each re-insert minted a brand-new `rsvp_token` via `bin2hex(random_bytes(16))`. Any RSVP or event link already emailed/texted therefore pointed at a token that no longer existed, and the recipient hit "This RSVP link is no longer valid. The event may have been updated." (the tokens have no time-based expiry, so the reported "links expire after ~5 minutes" was really "the host re-saved the event ~5 minutes after sending"). `save_invites()` now captures each existing invitee's `rsvp_token` and `rsvp_token_flips` keyed by lowercase username before the delete, and reuses them on re-insert; only genuinely new invitees get a fresh token. Applies to both the base (`occurrence_date IS NULL`) and occurrence-specific branches.
- **The "Invitations not sent to N" banner didn't update after sending an invite individually.** In `www/calendar.php`, the per-invitee resend handler only repainted its own button to "Sent ✓" and never refreshed the panel, so the unsent count stayed stale (e.g. read "6 still needed" after several one-off sends). It now calls `pollRsvps()` on success — the same refresh the bulk "Send Invitations" path already used — so the count recomputes and the banner clears at zero.

### Added
- **"Save & Send Invites" button inside the event editor.** Previously invites could only be dispatched from the post-save banner. A green **Save & Send Invites** button in the editor toolbar (`www/calendar.php`, shown only when notifications are enabled) sets a `send_after_save` flag; after the save, the `add`/`edit` handler runs the same logic as the explicit `send_invites` action (approved base invitees with no existing `invite` marker) and dispatches in one step. The existing post-save prompt remains for later/partial sends.
- **Progress overlay while the editor saves.** Clicking either save button now shows a centered popup with an animated completion bar ("Saving & sending invitations…" or "Saving event…") so the full-page save+reload no longer just blinks the window shut. Navigation is deliberately held ~1.3s so the bar is visible (the save itself is near-instant); `form.submit()` bypasses the handler so there's no resubmit loop.
- **Clearer invitee picker in the editor.** Already-invited contacts in the left list now show a green ✓ with legible muted text (was a near-invisible gray), and the right "Invited" list auto-numbers `1. 2. 3.` via a CSS counter that reflows on add/remove — both in `www/calendar.php`.
- **The tokenized RSVP confirm screen now shows event details.** `www/rsvp.php`'s GET confirmation step previously rendered just a bare Confirm button; it now displays the event title, date/time, description, and who's going/maybe above the button so invitees can see what they're responding to. The **Cancel** link returns to the event details page (`/event.php?token=…`) instead of the site root.
- **Event details page additions.** The public token-based event page (`www/event.php`) gained a **Pending** group in the "Who's coming" list for approved invitees who haven't responded yet (alongside Going / Maybe / Can't make it), plus a **"Done · Go to <site>"** exit link at the bottom of the card.

---

## [v0.19323] - 2026-06-03

### Fixed
- **The "Mgr" toggle was missing when adding invitees to a brand-new event.** In `www/calendar.php`, `inviteUser()` decided whether to render the per-invitee manager toggle from `currentEvent.created_by`, which is null while a new event is still being created. A non-admin host therefore failed the `created_by == current_user` check and never saw the toggle until the event was saved and reopened (admins were unaffected because the check short-circuits on `IS_ADMIN`). `canGrantManager` now also allows it in add-mode (`!editingEvId && CAN_CREATE_EVENTS`), since on a new event the host is the creator-to-be. The chosen role already persisted via `invite_role[]` → the `add` handler's `$save_invites` INSERT.
- **No "Send Invitations" option appeared for an event whose only invitee is the host.** `renderInvitesPanel()` excluded the current user from both the Send Invitations banner's unsent count and the per-invitee Send/Resend button, so a host who created an event and added themselves (e.g. to test delivery) saw no way to send the invite. Both self-exclusions were removed. The backend already supported this: the `send_invites` action queues all approved un-sent invitees with no self exclusion, and `dispatch_queued_notification()` delivers to the resolved user with no creator skip. The banner now counts the host's own un-sent row, so it can read "Invitations not sent to 1 person" for a self-only event, and clicking Send emails the host their own invite.

---

## [v0.19322] - 2026-06-03

### Fixed
- **"Send Invitations" (and Resend/Approve/Deny) failed with "Network error" for non-admin event hosts.** The calendar POST handler in `www/calendar.php` has a top-level permission gate that, for non-admins, only granted manager access to `edit`/`delete`/`delete_occurrence` (keyed on the `id` field). The per-event management actions added/used by the invite flow (`send_invites`, `resend_invite`, `approve_invite`, `deny_invite`) are keyed on `event_id` and weren't recognized, so a non-admin event creator or manager hit `exit('Access denied.')` with a plain-text 403. Because the Send Invitations button expects a JSON reply, that non-JSON 403 surfaced in the UI as "Network error. Please try again." (admins were unaffected, since the whole gate is skipped for them). The gate now also computes the manager check from `event_id` for those four actions; each handler still independently re-checks `can_manage_event()`, so this only widens access to legitimate event managers. This regressed when v0.19318 moved invite sending out of the auto-on-save path (which rode through the already-allowed `add`/`edit` actions) into the dedicated `send_invites` action.

---

## [v0.19321] - 2026-06-03

### Changed
- **Creating an event now opens it straight to the "Send Invitations" prompt.** Since v0.19318 invites no longer go out automatically on save, but the only place to dispatch them was the Send Invitations banner inside an event's detail view, which a host had to open manually. After clicking **Add Event** the modal simply closed and nothing surfaced the prompt, so it looked like invites had silently gone nowhere. The post-save redirect in `www/calendar.php` now appends `&open=<new_event_id>&date=<start_date>` for the `add` action, reusing the existing `?open=ID&date=DATE` auto-open path (the same one email/landing links use) to drop the host directly into the new event's detail view with the **⚠ Invitations not sent — [ Send Invitations ]** banner front and center. Editing an existing event is unchanged.

---

## [v0.19320] - 2026-06-02

### Added
- **Double-click to add or remove invitees in the event editor's invite panel.** Moving people between the "all users" list and the invited list previously required selecting a name and clicking the `›` / `‹` arrow (or drag). In the Add/Edit Event screen (`www/calendar.php`), double-clicking a name in the left contact list now adds them straight to the invited list, and double-clicking a name in the invited list removes them. Both reuse the existing `inviteUser()` / `removeInvite()` paths, so dedup, the RSVP badge, the per-invitee manager toggle, and the dimming of already-invited names behave exactly as with the arrow buttons. Double-clicks on a dimmed (already-invited) contact and on the in-row manager toggle are ignored, and the stray text selection a double-click would otherwise create is cleared. The contact tooltips were updated to mention the shortcut.

---

## [v0.19319] - 2026-06-02

### Fixed
- **"Add Event" / "Edit" buttons stopped opening the editor after v0.19318.** Removing the Mute toggle in v0.19318 left a dangling `document.getElementById('eSuppressNotify').checked = false;` in `openEditModal()` (`www/calendar.php`). With the element gone the lookup returned `null`, so reading `.checked` threw a `TypeError` that aborted the function before the modal could open — clicking **Add Event** (and the **Edit** pencil) did nothing. Removed the orphaned line.

---

## [v0.19318] - 2026-06-02

### Changed
- **Event invites no longer send automatically on save — the host now sends them explicitly.** Hosts reported that creating an event immediately blasted invite emails/SMS to everyone added, leaving no chance to review first. The auto-queue block in `www/calendar.php` (which fired on every `add`/`edit` unless the opt-out "Mute" toggle was set) has been removed, along with the now-redundant Mute toggle. Invites are instead dispatched by an explicit control: the event detail view shows a **⚠ Invitations not sent to N people — [ Send Invitations ]** banner (visible to event managers when site notifications are enabled), backed by a new `send_invites` POST action. That action queues an `invite` notification for every approved base invitee with no existing dedup marker in `event_notifications_sent`, then kicks `drain_queue_async()`; unlike `resend_invite` it does **not** clear existing markers, so re-clicking only reaches people who were never notified (e.g. invitees added in a later edit). The per-invitee button now reads **Send** vs **Resend** based on whether that person has been notified. To drive the UI, both the page bootstrap (`$ev_invites`) and the live poll endpoint (`www/event_invites_dl.php`) now include a per-invitee `sent` flag derived from `event_notifications_sent`.

### Added
- **Login-free public event page for email/SMS RSVP recipients (`www/event.php`).** Clicking Yes/Maybe/No in an invite already recorded the RSVP without a login, but the confirmation page then only offered Log in / Create Account links to actually see the event, and the invite email's "Event Details" button pointed at the login-gated calendar — a real roadblock for casual players. New `event.php?token=<rsvp_token>` is a public, token-based read-only event page (modeled on `walkin.php`): it shows the event title, date/time, description, the recipient's current RSVP with Yes/Maybe/No buttons, and a **who's coming** list (display names only — never emails or phone numbers). After a recipient confirms an RSVP, `www/rsvp.php` now redirects to `event.php?token=...&just=<value>` (which surfaces a "✓ Your RSVP is set to …" banner) instead of the old login prompt, and the invite "Event Details" button in `www/_notifications.php` points to the same public page. Invites created before the `rsvp_token` column existed have no token and gracefully fall back to the previous login-gated link.

---

## [v0.19317] - 2026-05-30

### Added
- **The Poker toggle on the event editor now remembers each host's last choice.** Hosts who mostly run plain invite-only events had to switch the **Poker** toggle off every single time, because the new-event form (`www/calendar.php`) hard-coded the `is_poker` checkbox to default **on** for everyone. The default is now sticky and per-user: whatever you picked the last time you *created* an event becomes the default for your next new event, globally across all leagues (editing an existing event does not change it). A new `users.last_poker_default` column (added via the standard `try/catch ALTER TABLE` in `db_init()`, default `1` so behavior is unchanged until your first create) stores the value; both event-create paths (`www/calendar.php` and the parallel `www/calendar_dl.php` `add` handler) write the chosen `is_poker` back to the creating user after insert, and `openEditModal()` seeds the checkbox from it for new events.

### Fixed
- **Per-user settings selected by `current_user()` now include `last_poker_default`.** `current_user()` in `www/auth.php` builds the user row from an explicit column list rather than `SELECT *`, so the new sticky-poker preference was being written to the database but never read back — the editor always fell through to its default-on fallback. Added the column to the `current_user()` SELECT so the saved preference actually takes effect on the next page load.

---

## [v0.19316] - 2026-05-29

### Changed
- **TOC entry for the Objects panel in www/timer.php (no behavior change).** Follow-up to the v0.19315 layer control and v0.19313 internal TOC. The Objects panel (added in v0.19314) had a plain banner comment with no `§N.M` tag and no Table-of-Contents line, so the TOC jumped straight from `§7.17 Inspector` to `§7.18 Panel drag` with the panel's code unlisted between them. Added a `§7.17.1 Objects panel` entry to the TOC and tagged the in-file banner accordingly, noting it now covers element visibility, selection, and the v0.19315 layer/z-index drag-reorder. Comment-only.

---

## [v0.19315] - 2026-05-29

### Added
- **Per-element layer (stacking order) control in the timer layout editor.** When you free-position elements they can overlap, but every positioned element was pinned at the same `z-index: 20` (with image and stream hardcoded to `z-index: 4`, deliberately behind the text), so which element sat on top was decided purely by DOM order with no way to change it. The v0.19314 **Objects** panel (`#layoutObjectsPanel` in `www/timer.php`) is now also the layer control: rows are sorted so the top of the list is the front of the canvas, and each row gained a **☰ grip** for drag-reorder plus **▲/▼** buttons to nudge an element forward or backward. Reordering is implemented with **Pointer Events** (`pointerdown`/`pointermove`/`pointerup` with pointer capture) rather than HTML5 drag-and-drop, so it works on iPad/touch — the same reason the v0.19306 blind-level editor uses ▲/▼ buttons — with the buttons as a reliable, always-available fallback. A new optional `z_index` field is written into `elements.<key>`; because it lives inside the element object it rides through save / load / export / import automatically with no DB migration, no `timer_dl.php` change, and no change to `_timer_theme.php` defaults. The field is **unset by default**, so `applyTheme()` writes no inline z-index and existing themes plus the shipped `.gnt.json` presets are visually unchanged until something is actually restacked. The first reorder seeds an explicit `z_index` on every element from the current visual order; assigned values are clamped to `1..16`, which stays safely below the control tray (`z25`), the floating edit pill (`z40`), and the modals (`z200+`), so "bring to front" can never cover the editor chrome. Because the inline `z_index` overrides the stylesheet, image and stream are now fully reorderable too (e.g. you can float a logo image on top of the clock) rather than being permanently pinned behind the text layer. New JS: `DEFAULT_LAYER_ORDER`, `effectiveZ()`, `objectsSortedMetas()`, `assignZFromPanelOrder()`, `moveObjectLayer()`, and the `attachObjectsDrag()` / `onGripPointer*` pointer-drag handlers; `applyTheme()` now writes each element's `z-index` inside its existing free-form-position loop.

---

## [v0.19314] - 2026-05-29

### Added
- **Objects panel for the timer layout editor, and pluggable clock display variants.** Two related changes to the tournament timer's layout editor (`www/timer.php`, with default fields in `www/_timer_theme.php`). First, hidden display objects no longer ghost onto the canvas the moment you enter edit mode. Previously every one of the 16 themable elements (clock, blinds, payouts, QR, image, stream, etc.) was force-shown at 35% opacity in edit mode so you could find and un-hide it, which cluttered the screen with elements you had deliberately turned off. Now hidden objects stay genuinely hidden on the canvas, and a new **📋 Objects** button in the floating edit pill opens a left-side panel (`#layoutObjectsPanel`) listing all 16 elements with a visibility eye and click-to-select. Selecting a hidden element from the list ghosts it on the canvas at 45% only while it is selected (so you can drag-position it) and returns it to fully hidden when you select something else. The eye toggles in the panel, the inspector, and on-canvas all stay in sync; multi-select via Ctrl/Cmd-click still group-drags. The visibility logic in `applyTheme()` was changed from "ghost all hidden elements in edit mode" to "ghost a hidden element only while it is in the selection set."
- **Pluggable widget-variant system, with the clock as the first user.** `renderClock()` was refactored from a hardcoded text renderer into a thin dispatcher over a new `window.TIMER_RENDERERS` registry keyed by element and variant, so future display objects can ship alternative renderers without per-object special-casing. The clock now offers three styles selectable from the inspector's new **Style** dropdown: `text` (the original MM:SS, still the default), `radial-ring` (an SVG ring that depletes as the level elapses with the time in the center), and `radial-checks` (the same ring split into N tick segments that check off one by one). Radial variants expose **Thickness**, **Direction** (clockwise / counter-clockwise), and (for checks) **Segments** controls; the SVG is built once and mutated per tick to avoid flicker, inherits the existing green/yellow/red threshold colors and pulse animation via `currentColor`, and scales with the same ±size buttons as text mode. Four optional fields were added to the clock element schema (`variant`, `radial_segments`, `radial_thickness`, `radial_direction`), all defaulted in `timer_theme_defaults()` so existing themes and the built-in `.gnt.json` presets resolve to the text clock with zero migration. Because these fields live inside `elements.clock`, they ride along in theme export/import automatically.

---

## [v0.19313] - 2026-05-28

### Changed
- **Internal navigation aid for www/timer.php (no behavior change).** The file is ~5,300 lines and structurally healthy under the codebase's intentional monolithic convention (inline `<style>` and `<script>` per page; no `css/`/`js/` directories; no build step), but it had become slow to scroll through. A Table of Contents now sits at the top of the file inside a PHP comment block (invisible to clients) mapping every major section to a stable `§N.M` tag: `§1` PHP head, `§2` `<head>`, `§3` inline CSS, `§4` body markup, `§5.1–§5.9` modal overlays by element id, `§6` vendor scripts, `§7.1–§7.21` inline JS sections (config, formatters, render, commands, pollState, local tick, wake lock, sound, levels editor, drag/drop, draft autosave, blind generator, theme editor, layout-edit, inspector, panel drag, init, player panel, CSV export/import), and `§8` closing tags. The 31 existing `// ─── Section ───` banner comments throughout the file are now prefixed with their matching `§N.M` tag, so Ctrl+F-ing any tag from the TOC jumps straight to its section. The TOC's line numbers are explicitly marked "current as of v0.19313" because they drift after edits; the `§N.M` tags themselves stay stable.

---

## [v0.19312] - 2026-05-28

### Fixed
- **Layout-edit pill "Cancel" renamed to "Close," and no more old-theme flash on exit.** The floating layout-edit pill's right-most button (`#layoutEditPill` in `www/timer.php`) used to read `× Cancel` with red `btn-danger` styling and, if you had loaded a different theme via the Theme Library or the v0.19310 Presets gallery while in edit mode, clicking it would flash the previous theme on screen for ~1 second before `pollState()` rebounded to the server's authoritative theme. Root cause: `enterLayoutEdit()` deep-clones `window.TIMER_THEME` into `LAYOUT_EDIT_SNAPSHOT` at edit-mode entry, but neither `loadTheme()` nor `loadPreset()` refreshed that snapshot when the user deliberately loaded a different theme mid-edit; `exitLayoutEdit(false)` then briefly restored the stale pre-load properties via `applyTheme()` until the next 2-second poll cycle re-fetched the real theme. Both loaders now refresh `LAYOUT_EDIT_SNAPSHOT` to the just-loaded properties when `LAYOUT_EDIT_ON` is true, so Close exits cleanly to whatever theme is actually loaded. The button is relabeled `× Close` with default neutral styling (the red `btn-danger` class was dropped) since after this fix it truthfully closes the edit session without destroying a loaded theme; per-element layout drags made in the current session are still reverted, matching prior behavior. The dead `populateThemeEditor(j.properties)` call at the end of `loadTheme()` (a pre-existing latent ReferenceError that broke the promise chain silently) was removed in the same edit.

---

## [v0.19311] - 2026-05-28

### Fixed
- **Touch-friendly snap toggle for the timer layout editor.** Dragging an element in layout-edit mode snaps to viewport center lines and to other elements' edges/centers (yellow + cyan guides), and the only way to disable snap for fine positioning was holding **Shift**, which iPad users could never do (no keyboard). The floating edit toolbar `#layoutEditPill` in `www/timer.php` now includes a 🧲 **Snap** toggle button between Reset and the separator. Tapping flips a new `SNAP_ENABLED` JS flag (default on, persisted across edit sessions in `localStorage` under `timer_snap_enabled`), and the snap-disable check at the drag handler now reads `!SNAP_ENABLED || ev2.shiftKey`, so the on-screen toggle and the Shift override compose cleanly. New helpers `toggleSnap()`, `setSnapButtonUI()`, and `updateSnapHint()` keep the button's active/muted state and the user-visible `.snap-hint` text in sync; on touch devices the hint drops the Shift mention entirely and advertises the on-screen button instead. The Shift code path itself is unchanged, so a Bluetooth keyboard paired to an iPad still works.
- **No more white edges on iPad when displaying a PC-designed theme.** The theme background (`var(--timer-bg)`) was painted only on `.timer-body`, while the `<html>` element had `height:100%` with no background, so iOS revealed bare `<html>` (browser white) during the dynamic Safari toolbar transition, rubber-band overscroll, and at safe-area gutters. The `html` rule in `www/timer.php` now also paints `background: var(--timer-bg)`, reusing the same CSS variable already defined on `:root` so the server-rendered first paint and the JS `applyTheme()` runtime update both flow through one source of truth. `overscroll-behavior: none` is also set on `html` and `body` to suppress iOS document-level rubber-band reveal entirely; inner scrolling regions (the blind-levels editor, player panel, preset gallery) are unaffected because the property applies only per element and they each have their own scroll containers. No positioning rework: a 16:9-designed theme still scales its viewport-percentage element positions on a 4:3 iPad, but it no longer leaks white between the elements and the screen edge.

---

## [v0.19310] - 2026-05-27

### Added
- **Built-in preset theme library for the tournament timer.** Curated timer themes shipped as `.gnt.json` files in `www/timer_themes/` are now browsable in a visual gallery and loadable in one click, instead of every user having to download and re-import a theme file. A new **Presets…** button in the timer's Theme Library opens a card grid (`#presetOverlay` in `www/timer.php`) where each card renders a self-contained mini-preview built from the theme's own colors and background; the preview uses a dedicated `renderPresetCard()` that only writes inline styles and never touches the live `:root`/DOM the way `applyTheme()` does. Loading a preset calls the new `apply_preset_theme` action in `www/timer_dl.php`, which reads the file server-side, materializes it as the user's own editable `timer_themes` row (find-or-create keyed on user + theme name, so repeated loads reset to the preset baseline rather than piling up duplicate copies), and points `timer_state.theme_id` at it so the choice persists across reloads and reaches remote/embedded displays. Admins additionally get **Upload** and **Delete** controls in the gallery, backed by admin-only `upload_preset_theme` / `delete_preset_theme` actions; uploaded files are validated against the export envelope (`format` + `properties.elements`) and re-encoded to a clean shape before being written, and a shared `timer_preset_path()` helper guards every file access against path traversal (basename + charset allowlist + realpath-prefix check). Six built-in themes ship to start: Classic Dark, Default BLue-green, Casino Red & Gold, Emerald Felt, High Contrast, and Midnight Purple. No database migration is needed; the existing `timer_themes` table is reused.

### Infrastructure
- **Operator note for the preset library.** The deployed `www/timer_themes/` directory must be owned by `www-data` (the same requirement as `db/` and `uploads/`) for the admin Upload feature to work; otherwise an upload fails with a write-permission error. Admin-uploaded presets are written to the running instance's filesystem and are **not** version-controlled, so to ship a preset to every deploy its `.gnt.json` must be committed to the repo. The preset files are served from under the web root and are therefore publicly fetchable at `/timer_themes/<name>.gnt.json`; this is acceptable because a theme contains only colors, scales, and layout (no sensitive data).

---

## [v0.19309] - 2026-05-24

### Added
- **Admin-configurable allowlist of extra video stream hosts for the tournament timer.** The timer's streaming panel only embeds a fixed set of providers (YouTube, Twitch, Vimeo, Kick, Prime Video); any other host was silently rejected by `normalizeStreamUrl()` and would also have been blocked by the CSP `frame-src`. A new **Settings → General → Tournament Timer → "Allowed video stream hosts"** field lets an admin permit additional hosts (e.g. a self-hosted stream). Entries may be bare hostnames or single-level wildcards (`*.example.com`, which conveniently also covers an auth-proxy redirect on a sibling subdomain). A shared `stream_allowed_hosts()` helper in `www/db.php` strictly validates the list (rejecting anything with CSP-significant characters) and feeds two places that must stay in sync: the CSP `frame-src` directive built in `www/auth.php` (wrapped in try/catch so a fresh, un-initialised DB can't break the header) and a client-side `EXTRA_STREAM_HOSTS` allowlist in `www/timer.php`, where `normalizeStreamUrl()` now passes matching URLs through (forced to `https` to avoid mixed-content blocking). Hosts must serve over https and permit being framed. Leaving the field blank preserves the previous built-in-only behavior.

---

## [v0.19308] - 2026-05-24

### Fixed
- **Embedded video stream now appears on remote timer displays.** A remote viewer (`timer.php?view=remote&key=…`, the screen you cast to a TV) showed no streaming video at all. The streaming `<iframe>` (`#streamingWrap`) and the themable image (`#themeImage`) were wrapped in a `<?php if (!$is_remote): ?>` block in `www/timer.php`, so the elements were never emitted on a remote page; `renderAll()` then found `getElementById('streamingWrap')` null and silently bailed, even though the server was sending the stream URL in the remote state payload and the CSP `frame-src` already allowed the embed. Both elements now render for remote views too. Additionally, the long-standing touch-device skip (`IS_TOUCH_DEVICE`, added so cross-origin iframes don't swallow the taps that re-acquire the screen wake lock on phones/tablets) was relaxed for remote views with `|| IS_REMOTE`, since a remote display's purpose is to show the stream and the iframe is a small positioned panel rather than full-screen. Host (control) view behavior is unchanged.

---

## [v0.19307] - 2026-05-23

### Fixed
- **Blind levels no longer scroll under the editor's sticky header.** The sticky header introduced in v0.19306 lived *inside* the scrolling panel and pinned the column-header row at a JS-measured offset (`syncStickyOffsets()` / `--levels-head-h`); whenever that measurement ran short (the preset bar wraps on narrow widths, fonts load late, `offsetHeight` reads stale) a band of level rows scrolled into the gap and showed through beneath the toolbar. `www/timer.php` now lays the editor panel out as a flex column scoped to `#levelsOverlay`: the header is a static, non-scrolling child and the table moved into a dedicated `.timer-levels-scroll` wrapper that owns the only scroll region, so the `# / SB / BB / Ante / Min / Type` row pins reliably at its top with no peek-through. A `min-height: 0` on that wrapper fixes the flexbox trap that had let the whole column-header row scroll away with the data rows. The now-unnecessary `syncStickyOffsets()` function, its `openLevels()` call, and the `resize` listener were removed. The layout change is scoped so the other modals sharing `.timer-levels-panel` are untouched.

### Changed
- **Closing the blind-structure editor with unsaved edits now uses an in-app dialog.** The native browser "Close anyway?" `confirm()` was replaced with a styled modal (`closeConfirmOverlay`, modeled on the existing Save-Theme dialog) offering **Discard** or **Keep editing**. Discard clears the in-memory edits and wipes the `localStorage` restore-draft via `discardLevelsDraft()`, then calls `pollState()` so the live structure reverts to the last-saved version immediately; Keep editing (and the dialog's × / backdrop) returns to the editor with edits intact. New helpers `doCloseLevels()`, `closeCloseConfirm()`, and `discardLevelsAndClose()` in `www/timer.php`.
- **Relabeled the editor's "Save Changes" button to "Save"** in `www/timer.php`, along with its dynamic states ("Save •" while there are unsaved edits, the post-save reset) and the import-confirmation prompt.

---

## [v0.19306] - 2026-05-23

### Added
- **Blind-structure generator for the tournament timer.** A new **⚙ Generate** button in the Blind Structure editor (`www/timer.php`) builds a full schedule from a few inputs (starting small blind, number of levels, minutes per level, optional break-every-N-levels and break length, and optional big-blind antes from a chosen level) instead of entering every level by hand. The progression is a classic chip-friendly small-blind ladder (25/50/75/100/150/200 and up) scaled to the chosen start and rounded to sensible increments, with the big blind equal to twice the small blind. New JS: `openGenerator()`, `confirmGenerate()`, `generateBlindProgression()`, and `roundNiceBlind()`. Generated levels populate the in-memory structure and still need an explicit Save.
- **Touch-friendly blind-level reordering.** Levels could previously only be reordered by HTML5 drag-and-drop, which iOS/iPadOS Safari does not fire, so on an iPad there was no way to move a level up or down. Each row now has up/down (▲/▼) buttons (`moveLevel()`) that work on any device, animated with a FLIP transition and a brief highlight on the moved row. The desktop drag path is unchanged, except the dragged ghost is now the whole row (see below).
- **Crash-safe editing of blind structures.** Edits used to live only in an in-memory array until "Save Changes" was clicked, so closing the editor, navigating away, or an iPad discarding a backgrounded Safari tab silently lost the work. The editor now mirrors in-progress edits to `localStorage` (debounced) and offers to restore them when reopened, shows an unsaved-changes marker on the Save button, confirms before closing with pending edits, and warns on page unload. New helpers: `markLevelsDirty()`, `saveLevelsDraft()`, `maybeRestoreLevelsDraft()`, `discardLevelsDraft()`, and `updateSaveBtnState()`.

### Changed
- **Reworked the Blind Structure editor layout.** Save Changes and the action buttons (Generate, Add Level, Add Break, Close) moved from the bottom of the editor into a smaller sticky header at the top, pinned together with the preset menu (Load, Save As, Delete, Export, Import) so the primary actions stay reachable without scrolling a long structure. The table's column-header row now sticks directly beneath that header, with its offset measured from the live header height in `syncStickyOffsets()` and recomputed on resize, so scrolling no longer tucks the data rows or the preset menu behind the controls. Dragging a level to reorder now ghosts the whole row as the drag image and dims the original row as a placeholder until it is dropped.

---

## [v0.19305] - 2026-05-22

### Fixed
- **Deleting an event from week view now keeps you on that week.** Follow-up to the v0.19304 navigation fix: the per-event Delete and per-occurrence delete forms in `www/calendar.php` carried only `month_param`, so a delete submitted from week view fell through to the month-anchored redirect and dropped you back on the *current* week rather than the one you were viewing. Both delete forms now also emit the `wk_param` hidden field (the same one the add/edit form uses), which the existing week-aware redirect handler already consumes. Deletes submitted from week view return to `?wk=<originating week>`; deletes from month view are unchanged. No handler logic changed. Verified on the dev instance: a week-view delete redirects to `?wk=2026-05-17` and a month-view delete to `?m=2026-05`.

---

## [v0.19304] - 2026-05-22

### Fixed
- **Week and month view no longer lose your place after saving an event.** Adding or editing an event from week view used to redirect back to the current week regardless of where you started. The event form in `www/calendar.php` now carries a `wk_param` hidden field alongside `month_param`, and the add/edit POST handler is week-aware: it returns you to the originating week in week view (computing the Sunday of a newly added event's start date so the redirect lands on the week that contains it), while month-view behavior is unchanged. The handler also derives the back-navigation month from the visible week when no `?m=` is present in the URL. Contributed by @jmgriffith (#18). Review caught a follow-on bug in that derivation: it was unconditional and overwrote an explicit `?m=` from the URL, which broke the auto-open guard a few lines down so that deep-links to an event outside the current week's month (invite and notification emails, RSVP links, `my_events.php`, and the league "Open" buttons) redirected to themselves until the browser aborted with ERR_TOO_MANY_REDIRECTS. The derivation is now gated on `$mParam === null`, so explicit-month deep-links keep the month they requested and open the event after a single redirect. Verified on the dev instance: the previously looping deep-link now returns 200 with one redirect and opens the event modal.

---

## [v0.19303] - 2026-05-21

### Changed
- **Landing page got product visuals and a few fixes.** The marketing landing page (`www/_landing.php`) was text-and-icons only; it now leads with a framed screenshot of a live tournament timer under the hero, and adds a "See it in action" band of three captioned screenshots (schedule an event, check players in, track RSVPs) reusing the assets in `www/img/help/`. Structure and accessibility fixes: added a section heading (`<h2>` "Everything you need to run game night") above the feature grid so the outline no longer jumps from H1 to H3, marked the decorative emoji icons `aria-hidden="true"`, and refreshed a stale "ten-minute read" line on the host-guide card. Bug fix: the closing call-to-action used to render an empty heading with no button when open registration is disabled; it now falls back to a Sign In button. The new visuals' CSS is inlined in the partial to avoid the un-versioned `style.css` cache. Also refreshed `timer-running.png` (used by both the landing hero and the Host Guide) with a populated example showing players, prize pool, payouts, and a running clock.

---

## [v0.19302] - 2026-05-21

### Added
- **Search-engine and social-share metadata (SEO).** The public surface can now be found by search engines and previewed cleanly when links are shared. Added `www/robots.txt`, which allows crawling of public pages, disallows login-gated or no-content endpoints (`/api/`, `/admin_settings.php`, `/admin_posts.php`, `/settings.php`, `/checkin.php`, `/walkin.php`, `/timer.php`, `/cron.php`, the password and verification flows, and `/s/` short links), and points crawlers to the sitemap. Added a generated `www/sitemap.php` served at `/sitemap.xml` via an `.htaccess` rewrite, listing the public pages (homepage, Host and Guest guides, register, login, terms, privacy) with absolute URLs built from `get_site_url()`. A new `render_seo_meta($title, $description, $path)` helper in `db.php` emits a meta description, a canonical link, Open Graph tags (type, site_name, title, description, url, image), and Twitter card tags; `index.php` and both help guides now call it. The Open Graph and Twitter image uses the configured header banner, falling back to the site banner; pages keep their own `<title>`. Only the public marketing pages are indexed, never login-gated content. Operator note: after deploying, verify the domain in Google Search Console (and Bing Webmaster Tools), submit `sitemap.xml`, and request indexing of the homepage.

---

## [v0.19301] — 2026-05-21

### Added
- **Admins are notified when a newer version is available.** A small amber dot now appears on the **Site Settings** nav link (admins only) whenever the running `APP_VERSION` is older than the latest version published to the public GitHub repo, so an operator knows their install has fallen behind `main` and should `git pull`. Mechanism: `www/cron.php` runs a once-per-24h `run_update_check()` (gated with a `latest_version_checked_at` timestamp, the same pattern as the weekly VACUUM) that `curl`s the raw `www/version.php` from `https://raw.githubusercontent.com/Isorgcom/GameNight/main/...`, regexes out `APP_VERSION`, and caches it in `site_settings.latest_version` — no per-request network calls and no auth (the repo is public, and there are no GitHub releases/tags to key off). New helpers in `db.php`: `fetch_remote_version()` (returns the remote version or null, swallowing all errors so a GitHub blip never breaks cron or clears a known-good value), `run_update_check(bool $force)` (returns whether a fetch succeeded), and `update_available()` (a cached `version_compare(APP_VERSION, latest, '<')`). The dot is gated behind `$_nu['role'] === 'admin' && update_available()` in `_nav.php` and rendered in both the dropdown and desktop nav, with the available version in its `title`; its CSS is inlined in the nav partial (consistent with the un-versioned `style.css` cache caveat). The admin dashboard's existing **Version** stat card (`admin_settings.php`) now shows "Update available: vX · changelog" when behind, plus a **Check now** button that forces an immediate re-check and reports the result via the standard flash/`alert` path. New version-source constants `UPDATE_SOURCE_URL` and `CHANGELOG_URL` live in `www/version.php`. Admin-only and in-app only — no emails, no banner, no footer change.

---

## [v0.19300] — 2026-05-21

### Added
- **Full, illustrated Host Guide walkthrough.** The host help page (`www/help-hosts.php`) was expanded from a breezy six-step overview into a detailed, click-by-click guide covering the whole lifecycle: set up a league → add a roster → create the event → invite guests → adjust the event's settings → track RSVPs → start the game. Each step now names the actual on-screen fields and buttons (e.g. the **Add Event** dialog's Title/Date/Visibility fields, the **Poker / Waitlist / Mute / Approval / Reminders** toggles and the poker config row, the **All Users / Invited** invite picker, the **Start Poker Session** form, and the **Blind Structure** editor and timer controls). Steps 1 and 2 are marked **optional** and **optional-but-recommended** respectively, step 4 notes that players can also be added later during check-in, and step 7 calls out that **payouts are not loaded by default** (the Payouts card starts empty until a split is configured). The guide is illustrated with nine annotated screenshots under `www/img/help/` (`leagues-create`, `contacts-add`, `event-create`, `event-invite`, `event-settings`, `event-rsvps`, `checkin-start`, `blind-structure`, `timer-running`); `www/img/help/README.md` documents the expected captures. A parallel Markdown reference for the same content lives at `docs/hosting-how-to.md`.

### Changed
- **Host Guide and Guest Guide are now grouped under an expandable "Help" submenu in the nav.** In the hamburger dropdown (`www/_nav.php`), the two standalone guide links collapse under a single **Help ▸** toggle that expands on click and auto-expands when you're already on a help page (`$nav_active === 'help'`). The submenu's CSS is inlined in the nav partial's existing `<style>` block rather than added to `style.css`, because `style.css` is linked without a cache-busting query string — inlining it ensures returning visitors get the styled menu immediately instead of new markup against a stale cached stylesheet. Also corrected the RSVP-tracking step's wording from the non-existent "Guests tab" to the actual **Invites** list label.

---

## [v0.19252] — 2026-05-20

### Changed
- **Changing the site timezone now re-anchors every existing event so their real times are preserved.** Event `start_time`/`end_time` are stored as wall-clock in the site timezone and converted to each viewer's timezone on display (`event_display_times()`), with the save path converting the host's input from their tz into the site tz. This design is correct only while the site timezone stays fixed: previously, changing it in Admin → Settings silently reinterpreted every stored wall-clock in the new zone, shifting every event's displayed time for every viewer (and desyncing the UTC reminder schedule). This bit a real install when the admin changed the home zone while debugging — a 7:30pm event jumped to 12:30pm/6:30am as the anchor moved. Fix: new `rebase_event_times_for_tz_change($db, $oldTz, $newTz)` in `db.php` converts every timed event's stored wall-clock from the old anchor to the new one (preserving the absolute instant) inside a transaction; both `general`-action handlers (`admin_settings_dl.php` and `admin_settings.php`) call it before persisting the new `timezone` setting and log the change with the count of events re-anchored. Because the absolute instant is preserved, no viewer's displayed time changes and UTC-scheduled reminders stay valid, so the timezone setting is now safe to change. All-day events (no `start_time`) are date-only and tz-agnostic and are left untouched. Chosen over a full UTC-storage refactor (which would touch every time read/write path and need a data migration) because it makes the failure mode impossible with one helper plus the single settings-save path. No migration runs against existing data; the guard only fires on future timezone changes. The Admin Settings timezone field now documents the behavior.

---

## [v0.19251] - 2026-05-19

### Fixed
- **Admin-created users and event invitees rendered lowercase across the UI.** Display names in the nav greeting, welcome and notification emails, league standings, comment bylines, and event invitee lists showed as lowercase whenever the account was created via Admin Settings (or invited by an admin), while users who self-registered via `register.php` kept their case. Root cause: `username` does double duty as both a display string and a case-insensitive join key in this codebase (all reads use `LOWER(u.username) = LOWER(ei.username)`, and the `event_invites` dedup index is on `LOWER(username)`); admin write paths plus every `event_invites` insert site were calling `strtolower()` while `register.php` and `walkin.php` were not. Fix has three parts. (1) New `canonical_username($typed)` helper in `db.php` looks up the typed name via `LOWER()` and returns the registered user's chosen case, falling back to the trimmed input for ad-hoc invitees who never registered. Used at every `event_invites` write site (`calendar.php:125,563`, `calendar_dl.php:70,290`, `api/v1/events.php:397,1003`, `checkin_dl.php:480`), so however an admin types your name on an invite form, it stores as the case you registered with. (2) Self-signup paths that already had `$current['username']` in hand (already canonical) just unwrap the `strtolower()` wrap: `calendar.php:617,629`, `calendar_dl.php:531,566,577`. (3) Admin add-user form, AJAX endpoint, and CSV import in `admin_settings.php:191,347` and `admin_settings_dl.php:147` drop `strtolower()` from the username before insert; email columns keep theirs. New `CREATE UNIQUE INDEX uq_users_username_nocase ON users (username COLLATE NOCASE)` in `db_init()` closes a latent SQL-level gap where the byte-sensitive `users.username UNIQUE` constraint allowed "Jeremy" and "jeremy" to coexist as separate rows even though every app-layer lookup treated them as the same person. Existing lowercased rows are not migrated; affected users can re-save their name in `/settings` to fix display going forward (the form already preserves case on save). Reported by @jmgriffith on PR #17.

---

## [v0.19250] — 2026-05-18

### Fixed
- **Tournament timer briefly showed an inflated remaining time on page refresh.** Refreshing `/timer.php` while a level was running would paint a remaining-time value that was off by the configured local UTC offset (e.g. ~300 min ahead on an `America/Chicago` install) for ~1 second, then snap to the correct value once the first `pollState()` response arrived. Root cause: the initial server-render in `timer.php` called `strtotime($timer['updated_at'])` without a timezone suffix. `timer_state.updated_at` is written by SQLite `datetime('now')` (UTC, no tz marker), and `strtotime()` re-parses bare timestamps in the configured PHP timezone — so `time() - strtotime(...)` returned a large negative elapsed, which was then subtracted from the stored remaining and inflated it. The poll path in `timer_dl.php:compute_live_state()` already appended `' UTC'` and produced the correct value, which is why the display flashed and then self-corrected. Fix: append `' UTC'` to the same call in `timer.php:184` so the first PHP-rendered `TIMER.time_remaining_seconds` matches what `compute_live_state()` would return, and add a comment explaining the trap so a future copy-paste doesn't regress it. No DB changes; no operator action needed beyond the usual pull/rebuild.

---

## [v0.19249] — 2026-05-18

### Added
- **Streaming video auto-mutes during timer alarms (with 3s pre/post padding).** When the start-of-level chime, warning beeps, or end-of-level alarm fires, the streaming-video iframe is muted via `postMessage` for the duration of the alarm, then un-muted automatically. Prevents the stream's audio from drowning out the alert in a tournament room. The mute window is padded to start **3 seconds before** the alarm and continue **3 seconds after**, so the alert isn't fighting the stream's reverb at the moment it kicks in or the moment it ends. For the warning and end-of-level alarms the pre-mute is scheduled in `startLocalTick` at `time_remaining_seconds === warning_seconds+3` and `time_remaining_seconds === 6` respectively; the start-of-level chime is user-triggered so it gets post-padding only (4 s total). When the alarm itself fires inside the pre-mute window, `muteStreamForAlarm` refreshes the unmute timer reentrantly. Works for **YouTube** (the no-cookie embed URL now includes `enablejsapi=1` so its IFrame API accepts commands) and **Vimeo** (Player.js postMessage with `setMuted`). Twitch, Kick, and Prime Video don't expose a parent-controllable mute on the raw iframe, so they gracefully no-op; the first time an alarm fires against one of those streams the console logs a one-time hint naming the provider. A new **"Mute streaming video while alarms play"** checkbox in the Sound Settings dialog toggles the behaviour; the preference is per-device (`localStorage gn.muteStreamDuringAlarms`, defaults on) because each viewer's stream mute is local-only by nature. The hook lives inside `playStartTimer`, `playWarning`, and `playEndTimer`, so the sound-settings Test buttons also exercise the mute path. A `STREAM_MUTED_BY_ALARM` flag tracks whether we own the mute so we never un-mute a stream the user manually muted via the embed's own controls.

---

## [v0.19248] — 2026-05-18

### Added
- **Theme export / import.** Two new buttons in the Theme Library: **Export** downloads the currently-selected theme as a small `<name>.gnt.json` envelope (`{format:"gamenight-timer-theme", version:1, name, properties}`); **Import** parses one back, validates the envelope, and routes the payload through the existing Save-As modal so the user picks the scope (Personal / League / Global). The imported theme lands in the library without hijacking the active timer's theme. Added a side-effect-free `get_theme` action in `timer_dl.php` for the export round-trip — the existing `load_theme` updates `timer_state.theme_id` as a side effect, which would have repointed the active timer mid-export. Read access is scoped the same as `get_themes` (default / global / mine / my-league themes), so users can only export themes they're allowed to see.

---

## [v0.19247] — 2026-05-17

### Added
- **Four new tournament-timer panels.** Reentry counter, chips-in-play, next-break countdown, and a streaming video iframe — all wired through the same theme/layout-editor system added in v0.19246. Reentries and chips-in-play piggy-back on data the check-in dashboard already tracks (`poker_players.rebuys`, `poker_sessions.starting_chips/rebuy_amount/addon_chips`); a single `chips_in_play` field was added to `calc_pool()` in `_poker_helpers.php` so the server is the source of truth and the cash-mode short-circuit lives in one place. Next-break countdown is pure client-side: walks `LEVELS` forward from the current level, sums `duration_minutes × 60` for each non-break level until `is_break === 1`. All four panels auto-hide for cash games (`poker_sessions.game_type = 'cash'`).
- **Streaming video panel — multi-provider.** CSP `frame-src` expanded to allow embeds from YouTube (`www.youtube.com`, `www.youtube-nocookie.com`), Twitch (`player.twitch.tv`, `www.twitch.tv`), Vimeo (`player.vimeo.com`), Kick (`player.kick.com`, `kick.com`), and Prime Video (`www.primevideo.com`, `atv-ps.primevideo.com` — best-effort; Amazon's `X-Frame-Options` usually blocks). A client-side `normalizeStreamUrl()` helper extracts the embed URL from a user-pasted watch URL for each provider, including the `tv.youtube.com/watch/<id>` pattern. Twitch's `parent=` requirement is satisfied from `location.hostname` so the embed works in both dev (`localhost`) and prod (`gamenight.poker`) without any settings. The stream URL is stored on `timer_themes.properties.elements.streaming.url`; theme-editor inspector has a URL field plus a Prime-Video warning. Iframe auto-hides on touch devices (`('ontouchstart' in window) || navigator.maxTouchPoints > 0`) because cross-origin iframes capture taps that would otherwise re-acquire the wake lock — admins can still configure the URL on their phone; it shows on desktop/TV viewers.
- **Self-hosted Google Fonts for the timer.** Inter (400/700), Bebas Neue, Orbitron (400/700), and Press Start 2P, downloaded from `fonts.bunny.net` (privacy-friendly Google Fonts mirror) into `vendor/fonts/` by `docker-entrypoint.sh` on first container start. Local `@font-face` declarations in `vendor/fonts/fonts.css` keep everything on the same origin — no CSP changes needed. The timer's theme inspector gained per-element Font dropdown, Letter-spacing dropdown (Normal / Tight / Wide / Wider), and Bold / Italic / Uppercase toggle buttons for every text element.
- **Smart-alignment snap guides.** When dragging a panel in layout-edit mode, the dragged element snaps to align with any **other** element's center *or edge* (9 candidate alignments per axis: center↔center, edge↔edge in 4 combinations, edge↔center in 4 combinations) and shows a cyan guide line at the shared coordinate. The existing yellow viewport-center snap still wins ties. Geometry for every other positioned element is snapshotted at drag-start (with `getBoundingClientRect` once) so mousemove stays cheap. **Hold Shift** while dragging to bypass all snapping for fine adjustments; an on-screen pill at the bottom of edit mode reminds users about both modifiers.
- **Multi-select group drag.** **Ctrl/Cmd-click** any element in layout-edit mode to toggle it in/out of the selection set; a plain click still replaces the selection with a single element. Dragging any selected element moves the whole group by the same delta. Snap math runs against the primary (originally-clicked) element only; the rest follow rigidly. The inspector switches to a summary view ("N elements selected — drag any to move them together") when more than one is selected. Group members are excluded from each other's snap targets so a group doesn't try to snap to itself.
- **NoSleep.js wake-lock fallback.** The existing `navigator.wakeLock` path is unavailable to iPhone Safari over plain HTTP (no secure context), so the screen would sleep on phones accessing the timer via LAN IP at a tournament. `vendor/nosleep.min.js` (loaded but previously unused) is now instantiated and called alongside `navigator.wakeLock` from inside the first user-gesture handler. The hidden silent video uses a `data:video/...` URL, so CSP `media-src` was extended to `'self' https: data:`. Banner hides when either mechanism succeeds.
- **CSP refactor.** `auth.php` rebuilds the `Content-Security-Policy` header from a PHP array literal rather than one long string so directives are easier to scan and edit. Same value plus the new `frame-src`, `media-src`, and font-host additions to `img-src` (`*.ytimg.com`, `*.twitch.tv`, `*.jtvnw.net` for video thumbnails).
- **New default theme shipped with fresh installs.** `db.php`'s timer-themes seed now ships a green/teal gradient with a fully positioned layout including the new panels and a Bebas-style paused label. Only runs when `timer_themes` is empty (`SELECT COUNT(*) = 0`), so existing installs are untouched.

### Changed
- **Per-element scale handling generalized.** A new generic CSS rule `.timer-positioned[data-has-scale] { transform: translate(-50%, -50%) scale(var(--el-scale, 1)); }` lets `applyTheme` set scale on free-positioned widgets that previously had a scale slider but no plumbing — `player_count`, `pool_total`, `avg_stack`, `payouts`, `rebuys`, `chips_in_play`, `next_break`. ID-specific rules (qr, image, streaming) outrank the attribute selector so their custom scale handling is preserved. Also picked up the long-missing `pool_total` color application.
- **Live-poll guard tightened.** When live-editing a theme, the 2-second `pollState` no longer clobbers in-progress drag positions. The guard now requires `!LAYOUT_EDIT_ON` in addition to the existing `themeOpen` check — without this, the poll fetched the server's stale snapshot, saw the client-side `properties` JSON had diverged (because the user was actively editing), and re-applied the server version every poll, wiping local edits. Remote viewers still pick up theme changes within 2s because they're never in edit mode.
- **Edit-mode pill default position.** The Library / Save / Reset / Cancel pill in layout-edit mode now defaults to the centre of the upper-left quadrant (`top:25%; left:25%; transform: translate(-50%, -50%)`) instead of top-centre, so it doesn't obscure the centre clock during typical edits. Still drag-movable.
- **Scale cap raised.** `+/-` button and scroll-wheel scaling on individual elements now allows up to 600 % (was 300 %) — needed for projector / very-large-TV setups.

### Fixed
- **First-time Theme button left elements un-interactive.** After a fresh page load, clicking the paintbrush to enter edit mode left panels click-through until the user cancelled and re-entered. Root cause: an earlier widening of the pollState theme-watch guard from `theme.id` to `theme.properties` (so streaming-URL changes propagate to remote viewers) lacked an `!LAYOUT_EDIT_ON` gate, so the same poll cycle wiped local pos state mid-edit. See *Live-poll guard tightened* above.
- **Inspector click-through on B / I / AA toggle buttons.** The font-style toggle buttons used to rebuild the inspector body inside their `onclick` handler, which detached the clicked element from the DOM before the click event bubbled to the body-level "click empty bg → select page" handler. `ev.target.closest('.layout-inspector')` then returned null on a detached node, so the body handler thought the click was on the background and shifted selection. Two fixes: the toggle buttons now mutate their own `.is-active` class in place instead of rebuilding (no detach), and the body handler now walks `event.composedPath()` so detached targets still resolve against the skip list for future changes.
- **Stream element had no way to enter a URL.** The element defaulted to `visible:false`, leaving nothing on the canvas to click to bring up its inspector. Added a Stream URL field to the **Page** inspector (top-level paintbrush view) so it's discoverable without first finding the hidden element. The empty stream wrapper also renders a diagonal-striped placeholder in edit mode with a "Stream — click to add URL" label, and the iframe gets `pointer-events: none` in edit mode so clicks pass through to the draggable wrapper.
- **Stream element couldn't be moved.** CSS specificity collision: `.timer-stream` (single class) was overriding `.timer-positioned`'s `top`/`left` because both have equal specificity and `.timer-stream` came later in the stylesheet. Switched the positioning rule to `#streamingWrap.timer-positioned` (ID + class outranks single class) so the element honours `--pos-x`/`--pos-y` regardless of declaration order.

### Infrastructure
- **Two-clone dev-mirror flow documented.** The primary working copy stays at `~/Claude/GameNight`. The staging copy at `~/Claude/GameNight-dev` runs the `gamenight-dev` container at `http://localhost:8080`. Every Edit/Write to the primary is mirrored per-file to the same path in dev so changes can be verified locally before they hit origin or production. Documented in `CLAUDE.md`, `WORKFLOW.md`, and `README.md`. Per-file (never bulk rsync) — dev legitimately differs in `docker-compose.yml`, `config.php`, `db/`, `uploads/`, `vendor/`, and `phpadmin/`.
- **Version bumps happen once per push.** `www/version.php` is no longer bumped during in-dev troubleshooting iterations — only immediately before the commit that ships a change. Avoids misleading "release every fix attempt" commit history.
- **CHANGELOG.md updates land in the same commit as the change.** Future feature/fix commits include the corresponding `CHANGELOG.md` entry in the same commit, not as a follow-up.

---

## [v0.19226] — 2026-05-07

### Security
- **WhatsApp webhook now token-gated.** `wa_webhook.php` previously accepted any POST shaped like `{event:"message", payload:{...}}`. The endpoint is reachable externally via NPM (the gamenight container is on the `npm_default` network), so an unauthenticated attacker could forge inbound "WhatsApp" messages and trigger the same write paths as a real reply: flip RSVPs, run STOP/START opt-out, advance the waitlist via forged "no" replies. Fixed by adding a token gate at the top of the file mirroring `cron.php`'s pattern. The token lives in a new gitignored `.env` file alongside `docker-compose.yml`; both `gamenight` and `waha` containers receive it as the `WAHA_WEBHOOK_TOKEN` env var, and waha's `WHATSAPP_HOOK_URL` interpolates it as a `?token=` query string. **Operator note:** create `.env` with `WAHA_WEBHOOK_TOKEN=$(openssl rand -hex 32)` before `docker compose up -d`, otherwise the gate fails closed and inbound WhatsApp replies stop working until the token is set on both sides.

---

## [v0.19225] — 2026-05-07

### Added
- **WordPress & API card on the landing page.** New 12th feature card on `/index.php`'s logged-out splash, advertising both the official **GameNight League** WordPress plugin (https://github.com/Isorgcom/gamenight-league-wp) and the public REST API at `/api/v1/` for non-WordPress consumers. Read-scope keys for display, write-scope keys for sign-up minting. No code path changed — copy only.

---

## [v0.19224] — 2026-05-07

### Security
- **phpLiteAdmin gated behind admin auth.** Direct hits on `/phpadmin/phpliteadmin.php` previously returned the pla-ng login UI to any unauthenticated visitor — and pla-ng's own auth was disabled (`$password = ''` in `phpliteadmin.config.php`) on the assumption that the `/phpadmin/index.php` redirect was the gate. The redirect only protected `/phpadmin/`, not the file directly, so the SQLite admin tool was effectively reachable without login. Fixed by adding `www/phpadmin/.htaccess` with a `php_value auto_prepend_file` directive that runs `_gate.php` before any PHP request in the directory; the gate redirects non-admins to `/login.php` with a return URL. The same `.htaccess` adds `<Files>` deny rules on `phpliteadmin.config.php` and `_gate.php` so neither is fetchable directly. Works because the base image runs PHP as mod_php; would need a different approach under PHP-FPM.

---

## [v0.19223] — 2026-05-07

### Added
- **My Events: "+ New Event" button.** Top-right of the page header on `/my_events.php`, next to the Past range selector. Links to `/calendar.php?new=1`, which now auto-opens the Add Event modal on load via a small `URLSearchParams` check at the bottom of `calendar.php`. One click from My Events to a fresh event editor instead of the old two-step (navigate to calendar, then click "+ Add Event").

---

## [v0.19222] — 2026-05-07

### Changed
- **My Events: Past section is now collapsible.** The "Past — N" header on `/my_events.php` became a `<details>` disclosure with a small caret that rotates open. Past events start collapsed so the page lands on Upcoming. The "Past:" range selector at the top of the page still controls how far back the list goes. Mirrors the same pattern we use on the league events tab.

---

## [v0.19221] — 2026-05-07

### Added
- **Subscription tier scaffolding (no gating yet).** Adds the substrate for paid tiers without actually paywalling anything yet — gating decisions land per-feature in subsequent commits. Tiers are **Free** (rank 0, default), **Personal** (rank 1), **League** (rank 2), and **Original Supporters** (honorary, shares effective rank with League). Schema: four new columns on `users` — `tier TEXT NOT NULL DEFAULT 'Free'`, `tier_expires_at DATETIME` (nullable; NULL = never expires), `tier_source TEXT` (`'manual'` for admin grants; reserved for `'stripe'`/`'comp'`/`'os_backfill'` later), `tier_granted_by INTEGER` (admin user id when set via the UI). Existing rows default to `Free`. Helper functions `tier_rank()` and `tier_at_least($user_or_tier, 'Personal')` in `db.php` will own all future feature gates — Original Supporters is normalized to League rank inside the helper, so OS users automatically get any League-or-below privilege without scattering string comparisons. Admin Users grid (`/admin_settings.php#users`) gets a sortable **Tier** column with an inline 4-option dropdown; changes route through the existing `update_user` action, stamp `tier_source='manual'` and `tier_granted_by=<admin_id>`, and write to `activity_log` via `db_log_activity`. Manual grants do **not** set `tier_expires_at` — that field is reserved for billing integration. User CSV import/export format is unchanged this pass (no `tier` column added to the CSV; existing exports remain interchangeable). Original Supporters is hand-picked, not auto-backfilled — promote those users individually from the Tier dropdown.

---

## [v0.19220] — 2026-05-06

### Changed
- **Tournament timer "Save As" is now a single dialog.** The blind-structure preset Save As flow used to fire two sequential native browser prompts, the first for the preset name and a second free-text prompt where you had to read a numbered list ("0: Personal, G: Global, 1: League — Foo...") and type the matching code. Now both fields live in one overlay modal — a real text input for the name and a real `<select>` dropdown for "Save to" with the same options (Personal / Global if admin / one row per league you manage). Enter in the name field submits; click-outside, Cancel, and the X all dismiss without saving. Backend POST to `/timer_dl.php?action=save_preset` is unchanged — same `name`, `is_global`, `league_id` fields go over the wire.

---

## [v0.19219] — 2026-05-06

### Added
- **Event editor user picker: "Hide non-members" toggle.** Pairs with the v0.19218 membership tag. When the event has a league selected, a small slider toggle ("Hide non-members") appears under the All Users search box. Flipping it on hides every row whose `is_league_member` flag is 0, so an admin can collapse the full-user dump down to just the league roster without typing a search. Toggle is hidden (and forced off) when no league is selected, since the badge itself is also hidden in that case. Plays nicely with the search box: filters compose (text match AND member match). Re-fetching on a league change rebuilds the list, but the toggle's checked state is preserved unless the league is cleared.

---

## [v0.19218] — 2026-05-06

### Added
- **Event editor user picker: league-membership tag.** When editing an event with a league selected, every row in the All Users list now shows a small **Member** (green) or **Not a member** (gray) pill next to the name. For admins (who see the entire users table) this makes it obvious which invitees fall inside the league boundary. For non-admins, the picker is mostly league members already, but personal contacts merged in are tagged so it's clear which of your contacts are also in the league. Tag is hidden when the event has no league selected. Implementation: `calendar_contacts_dl.php` precomputes `league_members.user_id` once per request and emits `is_league_member` (0/1) on every row; `buildAllUsersList()` reads `eLeagueId` and only appends the badge when a league is picked. The dropdown's existing change handler already calls `refreshUserList()`, so swapping leagues re-fetches and re-tags automatically. No schema changes.

---

## [v0.19217] — 2026-05-06

### Changed
- **League page Events tab: split upcoming/past, oldest-first, range filter.** The Events tab on `/league.php?id=<N>&tab=events` no longer mixes future and past events together. Upcoming events now sort soonest-first (was newest-first) so the next event is on top. Past events live in their own collapsed section below upcoming, with a "Past:" range selector (7d / 14d / 30d / 60d / 90d / 6mo / 1yr; default 30d) so leagues with long histories don't blow up the page. Range is a URL param (`?past_days=`), not persisted per-user. The split logic mirrors `my_events.php` so both pages agree on what counts as "past" (compares end-of-event datetime in the site timezone, not just `start_date`).

---

## [v0.19216] — 2026-05-05

### Changed
- **Admin Site Settings → Users grid: column headers are now sortable.** Clickable headers on `#` (id), Username, Email, Phone, Role, Notification, and Last Login. Clicking a column toggles ASC/DESC; the active column shows an up/down triangle. Default sort stays id ASC, so the grid looks identical until you click something. Mirrors the existing pattern on the Events grid (`ev_sort_link`). Server-side sort via `?us=col&ud=asc|desc` query params with whitelist + column-name map (no SQL injection surface). Notes column stays non-sortable (free-text). Bulk-select selections clear on re-sort because the page reloads — desired behavior, since selecting after a re-sort would be confusing about what's actually selected. CSV export and import are unaffected.

---

## [v0.19215] — 2026-05-04

### Added
- **`PATCH /api/v1/pending-contacts/{member_id}` lets sister sites edit a pending-contact row.** Body accepts `display_name`, `email`, and `phone` (any subset, at least one required). Email is validated and lowercased; phone is normalized via `normalize_phone()`. Email uniqueness is checked within the league before the write to give a clean `400 email_already_pending` rather than a partial-unique-index 500. The "must keep at least one of email/phone" guard from the in-app form is enforced here too. Idempotent on no-op edits (`fields_changed: []`, no DB write, token preserved).
- **`DELETE /api/v1/pending-contacts/{member_id}` hard-deletes a pending row.** Silent — pending contacts have no account, no preferred-contact channel, and the address might be the reason for the delete in the first place. Registered rows cannot be touched even if you pass their `member_id` (same `404 pending_contact_not_found` response, no info leak about row type).
- **`GET /api/v1/members` enriched with `member_id`, `invited_at`, `invited_by_username`.** Pure addition — existing fields unchanged. `member_id` (the `league_members.id` PK) is the only stable identifier for pending rows (since they have `user_id: null`); both new endpoints address rows by it. `invited_at` and `invited_by_username` give the WP-admin "Edit member" UX enough context to render an attribution line.

### Security
- Both new endpoints refuse to operate on registered (`user_id IS NOT NULL`) rows. PATCH returns `400 not_a_pending_contact`; DELETE collapses to `404 pending_contact_not_found` via the WHERE clause's `user_id IS NULL` filter. Use `PATCH /members/{user_id}` and `DELETE /members/{user_id}` for registered members. This keeps the API explicit about the very different blast radius of editing a per-league label vs. editing a real account's login identifiers.
- When `email` or `phone` changes on PATCH, the row's `invite_token` is regenerated automatically. The old invite link dies; the new token comes back in the response (`invite_token` field, included **only** when regeneration happened — old tokens are never echoed). Sister sites that want to re-deliver the invite can pull the new token from the response.

### Plumbing
- New `.htaccess` rewrite for `/api/v1/pending-contacts/{member_id}`.
- New file `www/api/v1/pending_contacts.php` with multi-method dispatch.

---

## [v0.19214] — 2026-05-04

### Added
- **`DELETE /api/v1/members/{user_id}` removes a user from the bound league.** Drops the `league_members` row plus any pending `league_join_requests` for that user/league, mirroring the in-app `remove_member` action. The user account stays intact across the rest of the system — their RSVPs, event-manager roles, authored posts, and memberships in other leagues are untouched. The removed user is notified via their preferred channel ("Removed from {league_name}"); a failed notification does not roll back the removal. Wrapped in a transaction. Per-key rate limit 60/hour.

### Security
- Owner removal is rejected with `400 cannot_remove_owner`, mirroring the existing `cannot_demote_owner` guard on `PATCH /members`. The in-app `transfer_ownership` flow remains the only way to change a league owner.
- The "managers can't remove other managers" guard from the in-app UI does **not** apply via the API — write keys are owner-equivalent (only owners can mint them), so an API-driven removal acts on the owner's behalf. Same precedent as `POST /events` setting `created_by` to the owner.

---

## [v0.19213] — 2026-05-02

### Added
- **`POST /api/v1/posts` lets sister sites publish announcements into a league.** Body accepts `title`, `content` (sanitized HTML, same pipeline as the in-app editor), optional `pinned`, `hidden`, and `published_at` (ISO-8601 UTC instant — future values produce scheduled posts that the existing `GET /posts` filter naturally hides until publish time). Author is set to the league owner so the post has a real attribution. Per-key rate limit 60/hour.
- **`PATCH /api/v1/posts/{id}` partial-updates an existing post.** Editable fields: `title`, `content`, `pinned`, `hidden`. Empty body or all-fields-unchanged returns `400 no_fields_to_update`. Response includes `fields_changed` so callers can confirm what landed.
- **`DELETE /api/v1/posts/{id}` hard-deletes a post.** Cascades to comments where `type='post' AND content_id=post_id`. Wrapped in a transaction; partial failures roll back. Response includes `comments_deleted`.

### Security
- All three endpoints are gated by the `write` scope and league-scoped via the API key. Posts in other leagues return `404 post_not_found` (no info leak). Locked fields rejected with explicit 400: `is_rules_post`, `share_token`, and `make_public` cannot be set via the API — promoting a post to rules and minting public share tokens stay UI-only operations. PATCH adds `published_at` to the locked list (retroactive publish-date edits create a confusing audit story).

### Plumbing
- Three time helpers (`api_parse_inbound_at`, `api_local_to_utc_iso`, `api_db_utc_to_iso`) extracted from `events.php` into a shared `www/api/_time.php`. They were always API-wide, not events-specific. `events.php` and `posts.php` both `require_once` the new file. `_time.php` is added to the .htaccess partial-blocklist so it can't be hit directly.

---

## [v0.19212] — 2026-05-01

### Added
- **`PATCH /api/v1/events/{id}/invites/{user_id}` lets sister sites change an invitee's RSVP or event role.** Body accepts `rsvp` (`'yes'`, `'no'`, `'maybe'`, or `null` to clear) and/or `event_role` (`'invitee'` or `'manager'`). At least one is required; bare `null` rsvp is meaningful (clears the response). The 1-hour-before-start cutoff that applies to non-admin RSVPs in the UI does NOT apply via the API — the key acts as the league owner, and admins bypass the cutoff in the UI too. When `rsvp` becomes `"no"` on a poker event, the waitlist is recomputed and any promotions are reported in `promoted_from_waitlist`. **No notifications fire** — matches what the UI currently does (the `rsvp_to_creator` template exists but is never queued from `calendar_dl.php`). Per-key rate limit 60/hour.
- **`GET /api/v1/posts/{id}` fetches a single post by id.** Symmetric with `GET /events/{id}` — sister sites that have just an id (e.g. stored after embedding a post) no longer have to walk the list. Same visibility filters as `GET /posts`: hidden, future-scheduled, and the rules post all return `404 post_not_found`. Use `GET /rules` for the rules post specifically. Read scope sufficient.
- **`GET /api/v1/members/{user_id}` returns a single league-member by user_id.** Same shape as a list-item; useful when a sister site has just an id and doesn't want to walk the roster.
- **`PATCH /api/v1/members/{user_id}` lets sister sites promote/demote a league member's role.** Body: `{league_role: 'member' | 'manager'}`. Idempotent (no-op + `role_changed: false` when the role already matches). `'owner'` is rejected with `400 cannot_set_owner_via_api` to prevent privilege escalation, and demoting the current owner is rejected with `400 cannot_demote_owner` (use the in-app `transfer_ownership` flow instead). Pending contacts (member rows without a registered account) return `404 member_not_found` — call `POST /users` first. New `.htaccess` rewrite routes `/api/v1/members/{user_id}` to the members handler. Per-key rate limit 60/hour.

### Plumbing
- New `.htaccess` rewrites for `/api/v1/posts/{id}` and `/api/v1/members/{user_id}`.
- `posts.php` and `members.php` become multi-method handlers, mirroring the pattern used by `events.php`.

---

## [v0.19211] — 2026-05-01

### Added
- **`GET /api/v1/events/{id}` returns a single event by id.** Same shape as a list-item from `GET /events` plus `league_id` and `visibility` for symmetry with the POST response. Sister sites that have just an event id (e.g. stored after `POST /events`) no longer have to pull a date window and filter client-side. Read scope is enough.
- **`GET /api/v1/events/{id}/invites` returns the invitee list with RSVP state.** Each row: `{user_id, display_name, rsvp, approval_status, event_role}`. `user_id` is `null` for custom invitees added by email/phone without a registered account. Sort order matches the calendar UI (`COALESCE(sort_order, 999999), username`). Per-occurrence override rows from the legacy recurrence feature are filtered out — only base invites surface. PII (`email`, `phone`, `rsvp_token`) is never returned.
- **`DELETE /api/v1/events/{id}/invites/{user_id}` removes a single invitee.** Symmetric counterpart to `POST /events/{id}/invites`. Mirrors the calendar UI's `remove_invitee` action: for future events, queues a `cancel_event` notification to the removed user before the row is deleted; past events delete silently. Returns 404 `invitee_not_found` if the user isn't currently invited (so retries are safe). Wrapped in a transaction. Per-key rate limit 60/hour. New `.htaccess` rewrite routes `/api/v1/events/{id}/invites/{user_id}` to the events handler.

---

## [v0.19210] — 2026-04-30

### Added
- **`PATCH /api/v1/events/{id}` lets sister sites edit an event without a delete-and-recreate.** Accepts a partial JSON body containing any subset of the POST /events fields except `invitees` (use the new sub-resource for that) and league/visibility (immutable). When `start_at` moves and the event is in the future, queues an `event_updated` notification to all approved base invitees so plans don't get silently broken. The reminder queue rebuilds automatically when timing or reminder fields change, mirroring `calendar_dl.php`'s edit behavior. Poker session settings sync the same way: toggling `is_poker=false` deletes the session and chained tables, toggling on creates a fresh row, and `poker_buyin` / `poker_tables` / `poker_seats` / `poker_game_type` updates flow through. Per-key rate limit 60/hour. Response includes `fields_changed` so callers can confirm exactly what landed.
- **`POST /api/v1/events/{id}/invites` lets sister sites add invitees after the event is already created.** Body: `{invitees: [{user_id, manager?}, ...]}`. Each user_id must already be a member of the league (call `POST /users` first to create them). Idempotent: anyone already invited is skipped silently and reported in `skipped: [user_id...]`. New rows always land `approval_status='approved'`, matching the calendar UI's creator-added behavior. Poker events with `waitlist_enabled=true` recompute the waitlist after insert so beyond-capacity additions are correctly marked `waitlisted` (and skip the invite notification). New `.htaccess` rewrite routes `/api/v1/events/{id}/invites` to the events handler.
- **`GET /api/v1/members` now returns `user_id` on each row.** `null` for pending contacts (invitees who haven't created accounts), an integer for registered members. Sister sites that lose track of a user_id can now recover it without a write call. Personal contact info stays hidden as before.

### Changed
- **CORS `Access-Control-Allow-Methods` widens to `GET, POST, PATCH, DELETE, OPTIONS`.** Browser callers can now use PATCH from JavaScript without a preflight failure.

### Security
- Both new write endpoints are gated by the `write` scope and league-scoped via the API key. Events outside the bound league return 404 `event_not_found` rather than 403 — same info-leak protection as DELETE /events/{id}. Existing invitees' manager flags are never modified by `POST /events/{id}/invites` even when the request explicitly says `manager: true`; promoting/demoting attendees is a separate operation that isn't yet exposed.

---

## [v0.19209] — 2026-04-30

### Added
- **`DELETE /api/v1/events/{id}` lets sister sites delete events they created via the API (or any event in their league).** Mirrors the calendar UI's delete handler exactly: future events queue `cancel_event` notifications to all base invitees before the row is destroyed, past events delete silently, and the cascade clears `comments`, `event_exceptions`, `event_invites`, sent rows in `pending_notifications`, `event_notifications_sent`, and the event itself (`poker_sessions` and the chained `poker_players` / `poker_payouts` drop via existing FK cascade). Wrapped in a transaction so partial failures roll back cleanly — a small upgrade over the UI's no-transaction path. Returns `{event_id, title, deleted, notifications_queued}`. Per-key rate limit: 60 deletes per hour. New `.htaccess` rewrite rule routes `/api/v1/events/{id}` to `events.php?id={id}`; same pattern unlocks future per-resource endpoints (PATCH, etc.) without further infrastructure work.

### Security
- Event deletion is gated by the `write` scope and is league-scoped via the API key. An event in a different league returns 404 `event_not_found` rather than 403 — the API does not confirm the existence of resources outside the key's league.

---

## [v0.19208] — 2026-04-30

### Added
- **`POST /api/v1/events` lets sister sites create league events.** Requires the `write` scope. Body accepts `title`, `start_at`, optional `end_at`, plus pass-through fields the calendar form already supports: description, color, is_poker, requires_approval, recurrence + recurrence_end, rsvp_deadline_hours, waitlist_enabled, reminders_enabled, reminder_offsets, poker_buyin / poker_tables / poker_seats / poker_game_type, and an optional `invitees` array of `{user_id, manager?}`. Each invitee must already be a member of the league (call `POST /users` first to create them). Side effects mirror the in-app calendar form exactly: `created_by` is set to the league owner so the event has a real manager, `visibility` is forced to `'league'`, a poker_sessions row is auto-created when `is_poker=true`, beyond-capacity poker invitees are marked waitlisted, reminder notifications are queued, and a walk-in token is generated eagerly so the response can return a ready-to-use `walkin_url`. Per-key rate limit: 60 creations per hour. All audit-logged via `db_log_anon_activity`.

### Changed
- **Breaking: `GET /api/v1/events` now returns ISO-8601 UTC instants.** The previous response shape used `start_date` / `start_time` / `end_date` / `end_time` strings in the league's display timezone — sister sites had to know that timezone out-of-band to render correctly. Replaced with `start_at` and `end_at` ISO-8601 UTC strings (e.g. `"2026-05-17T20:00:00Z"`). All-day events return a date-only string (`"2026-05-17"`) in the same fields. **Migration**: any consumer reading the old fields needs to switch to `start_at` / `end_at` in this release. The new POST endpoint accepts the same `start_at` / `end_at` shape so sister sites can round-trip events without timezone math.

### Security
- Event creation is gated by the `write` scope (added in v0.19206) and is league-scoped via the API key — a key bound to one league cannot create events in another. Visibility cannot be set to `'public'` via the API; that remains an admin-only UI privilege.

---

## [v0.19207] — 2026-04-30

### Added
- **`POST /api/v1/users` accepts `preferred_contact`.** New optional body field that sets the user's ongoing notification channel — the same setting users pick on `/settings.php`. Accepts `email`, `sms`, `whatsapp`, `both`, or `none`. When omitted it falls back to `verification_method`, preserving the prior behavior. The two fields are independent now: a sister site can verify a user by SMS at signup but set their preferred ongoing channel to `email` (or `both`, or mute them entirely with `none`). The response payload gains `preferred_contact` (the resolved value) and `preferred_contact_updated` (true only when a new user was created) so callers can tell whether their requested value took effect.

### Security
- **Existing-user replays cannot change notification preferences.** When `POST /api/v1/users` matches an existing account by email or phone, the endpoint still ensures league membership but explicitly ignores any `preferred_contact` in the body. A leaked write key cannot silently mute a user, re-route their notifications to a channel they don't watch, or unsubscribe them. The response always returns `preferred_contact_updated: false` on replays so the caller can see the no-op.

---

## [v0.19206] — 2026-04-30

### Added
- **Public API: `POST /api/v1/users` lets sister sites create users in a league.** Until now `/api/v1` was read-only; sister sites that wanted to onboard a user had to send them through the QR walk-in or a manual sign-up. The new endpoint accepts a JSON body (`display_name`, `email` and/or `phone`, optional `username`, optional `verification_method` of `email` / `sms` / `whatsapp` / `none`) and creates a soft account that mirrors the walk-in flow: empty `password_hash`, `must_change_password=1`, `email_verified=0`, with a verification email or SMS sent so the user can later set a password and sign in. The new user is automatically added to the league bound to the API key. The endpoint is **idempotent on email/phone** — replaying with the same contact returns the existing `user_id`, ensures league membership, and skips the verification send, so sister sites can retry safely. Per-key rate limit of 60 successful creations per hour using the existing `api_request_log`. Audit trail flows through `db_log_anon_activity` (`api_create_user: ...`).

### Security
- **API keys now have a `scopes` column.** Every existing key is migrated to `scopes='read'` (default), so the new write endpoint cannot be exercised with an older key. League owners minting a key can choose "Read-only" (default) or "Read + write (create users)" from the API tab. The keys table on the league page now shows a Scope column with a styled badge so you can see at a glance which keys carry write access. `api_require_scope($key, 'write')` enforces the gate; missing scope returns 403. The discovery endpoint at `/api/v1` documents both scopes and the new endpoint shape.

---

## [v0.19205] — 2026-04-29

### Added
- **Public API: `GET /api/v1/rules` exposes the league rules post.** Sister sites can now fetch a league's rules alongside the rest of its public-facing content. The existing `/api/v1/posts` endpoint deliberately excludes the rules post (it has its own UI button and lifecycle in-app), so consumers had no way to read it. The new endpoint returns the rules post (id, title, sanitized `content_html`, author display name, created_at) bound to the API key's league, or `rules: null` when the league has not configured rules yet — consumers can render "no rules set" without branching on HTTP status. Hidden rules posts are treated as absent. Same auth, response shape, caching, CORS, and request logging as the rest of `/api/v1`. Discovery endpoint at `/api/v1` advertises the new path. No `.htaccess` change needed; the existing single-segment rewrite handles it.

---

## [v0.19204] — 2026-04-29

### Changed
- **New-post form on the league page is collapsed by default.** The form was eating a chunk of vertical space at the top of the post list every time anyone with author permission visited the page, even though most visits aren't to write anything. Replaced with a single "+ New post" button that expands the form on click; a "Close" / "Cancel" button collapses it back. Edit mode (clicking Edit on an existing post or rules post) still opens the form expanded automatically. The Jodit rich-text editor is initialized lazily the first time the form opens, which avoids the height/toolbar glitches that happen when Jodit is initialized inside a `display:none` container.

---

## [v0.19203] — 2026-04-29

### Changed
- **League page now lands on Posts and labels that tab with the league's name.** The Posts tab is the most-recent and most-interesting view (announcements, recaps, schedule changes), so it's now the first tab and the default landing tab when you visit `/league.php?id=N` without a `?tab=` parameter. The tab itself is labeled with the league's actual name (e.g. "Kipling Poker") so the league page reads like the league's homepage. Existing URLs that explicitly set `?tab=members` continue to work; only the visible label and default change. Two confirmation dialogs and one help string that referenced "Posts tab" / "Posts feed" were reworded to generic phrasing ("main tab" / "main feed") so they stay correct regardless of league name. The New-post form remains gated to owners, managers, and site admins via the existing `$canPost` check; plain members were already unable to author posts.

---

## [v0.19202] — 2026-04-29

### Added
- **Iframe embeds in posts (YouTube, Vimeo, Spotify, Twitch, Google Maps).** `sanitize_html()` now accepts `<iframe>` tags but only when their `src` host matches a strict allowlist: `youtube.com`, `youtube-nocookie.com`, `vimeo.com`, `player.vimeo.com`, `open.spotify.com`, `twitch.tv`, plus `google.com` restricted to `/maps/embed`. Iframes from any other source get the tag unwrapped, same way other disallowed tags are handled. Allowed iframe attributes: `src, width, height, title, allow, allowfullscreen, frameborder, loading, referrerpolicy`. Both post editors (admin and league) now show a "source" toggle in the toolbar so authors can paste the embed snippet directly into the HTML view; existing CSS in `index.php` already scales iframes responsively. Pasting an iframe from any other host still has the snippet survive as plain text inside the post body.

---

## [v0.19201] — 2026-04-28

### Added
- **API documentation, in-app and external.** The League → API tab now shows a Quick Reference card below the keys table covering authentication, every endpoint with its query parameters, error codes, caching/CORS behavior, and what to do when a key leaks — so league owners can answer most consumer questions without leaving the page. Added a full "API for Sister Sites" section to DOCS.md with end-to-end examples in PHP, JavaScript, and curl, plus example response payloads for all four endpoints. New `GET /api/v1/` discovery endpoint (no auth required) returns a JSON document listing the available endpoints, auth instructions, response shape, and error codes — useful for human exploration and for anyone hitting the base URL trying to figure out what's there.

---

## [v0.19200] — 2026-04-28

### Added
- **Public read-only API at `/api/v1/` for sister sites and other trusted consumers.** Built so a separate website (e.g. a poker league's main marketing site) can pull league info, member roster, events, and posts from GameNight without copy-pasting. Each API key is bound to one league at issuance time and authorizes read-only access to that league's data; the consumer cannot read across leagues even if it tries to pass a different league_id in the request. Four endpoints: `GET /api/v1/league` (name, description, member count), `GET /api/v1/members` (display name + role + pending flag, no emails or phones by design), `GET /api/v1/events?from=&to=` (with RSVP yes/no/maybe counts; window default today→+90 days, capped at 366), `GET /api/v1/posts?limit=&offset=` (sanitized HTML body, share_url when public-link sharing is on for that post). All responses use the existing `{ok:true,data:...}` / `{ok:false,error:...}` shape. Keys are SHA-256 hashed at rest, generated as 64-char hex via `random_bytes(32)`, sent as `Authorization: Bearer <key>` (or `?key=` fallback). Every request is logged to a new `api_request_log` table for audit + future rate-limit work. Self-service: **league owners** mint and manage their own keys from a new "API" tab on the league page (`league.php?tab=api`); managers cannot mint keys because issuing one exposes the roster to an external system, which is an owner-level decision. Site admins get a cross-league audit page at `/admin_api_keys.php` to see every key across every league and revoke anything in case of abuse — but admins are not the bottleneck for issuance. New `api_keys` and `api_request_log` tables; new `league_api_keys_dl.php` POST endpoint with `create` and `revoke` actions following the existing `league_posts_dl.php` style; new `RewriteRule` in `.htaccess` so URLs are clean (`/api/v1/league` rather than `/api/v1/league.php`); deny rule blocks direct access to the `_auth.php` / `_response.php` partials.

---

## [v0.19103] — 2026-04-28

### Fixed
- **Per-container memory limits to stop WAHA from OOM-killing the host.** Server hung overnight after the kernel OOM-killer fired seven times in succession on chromium processes (`oom_score_adj:300`, ~80 MB anon-rss each) on a 458 MB host. Even though WAHA is configured for the NOWEB engine, the container had accumulated chromium processes over its 10-day uptime. Added `mem_limit: 384m` to the waha service (steady-state with a loaded session is ~165 MB) and `mem_limit: 192m` to gamenight (steady-state ~14 MB). When either container hits its ceiling, the kernel now kills the offending process inside the container instead of randomly across the host. Also pinned the WAHA dashboard/swagger credentials so they survive container restart instead of being regenerated on every start.

---

## [v0.19102] — 2026-04-27

### Fixed
- **Declined invitees now appear in their own subsection on the event panel.** Previously anyone with `rsvp='no'` was filtered out of the invite list entirely (`calendar.php` line 2140), which made it look like the user had been removed from the event. They were always still in `event_invites`, just hidden from the view. The panel now renders a separate "Declined" subsection (faded, struck-through usernames, red label) below Waitlisted. Managers can flip the RSVP back to Yes/Maybe via the same dropdown used in the main list, so it's still trivial to recover someone who hit No by mistake.

---

## [v0.19101] — 2026-04-27

### Fixed
- **Hidden-league deny page now keeps the site nav and explains why.** When a non-member opened a hidden league's URL directly, the page returned a bare `Not allowed` text response with no header, footer, or back-link, which felt like a broken page. The deny path now renders a full page with the standard nav + footer and a friendly explanation: the league is set to hidden, members-only, can't be joined directly, and the user should ask an owner/manager for an invite. Two CTAs at the bottom (Browse leagues / Go home) give a clear way out. The 403 status code is preserved.

---

## [v0.19100] — 2026-04-27

### Added
- **Public share links for league posts.** League owners, managers, and site admins can now mark an individual league post as publicly readable via a generated link. The post stays hidden from every feed (homepage, league pages, search) for non-members, but anyone holding the URL can open `/post_public.php?token=...` and read it without logging in. Comments are visible read-only to non-members; only logged-in league members and admins see the comment form. Three new actions on `/league_posts_dl.php`: `share_enable` (mints a token, idempotent), `share_regen` (rotates the token, invalidating the previous link), and `share_disable` (clears the token, killing the link). New `posts.share_token` column with a unique partial index. The public page sets `meta robots noindex,nofollow` so search engines won't index the URL. UI lives next to the existing Pin / Hide / Set-as-rules controls on the league Posts tab — Make public, Copy URL, Regenerate, Disable. A Public-link badge appears on shared posts so members can see which posts are exposed. When the URL shortener (`url_shortener_enabled`) is on, the displayed share URL is run through the existing `shorten_url()` helper so users see a short.io link instead of the long token URL.

---

## [v0.19027] — 2026-04-27

### Added
- **"Resend" button next to invitees who haven't RSVPed.** Visible to event managers (admin / event creator / event manager) on the event detail view. Clicking it deletes the invitee's row in `event_notifications_sent` (the dedup table that prevents duplicate sends) and queues a fresh `pending_notifications` invite, then kicks off the queue drain so the SMS/email goes out within seconds. Useful when an invitee says they didn't get the original message — no more SSH and no more delete-and-re-add workarounds. Hidden for invitees who already responded (yes/no/maybe) and for the host themselves. New `resend_invite` action in `calendar.php`'s POST handler, gated by `can_manage_event()`. Activity log records each resend.

---

## [v0.19026] — 2026-04-27

### Fixed
- **Tokenized RSVP links no longer flip RSVPs on GET — confirmation required.** Investigating Paul on event 67 ("Kipling poker 17th") turned up `rsvp_token_flips=4` despite the host only seeing one or two real replies. Docker access logs showed three GETs to all three RSVP options (yes/no/maybe) within the same second, five seconds after the SMS was delivered — classic SMS provider URL safety scanner / link-preview crawler behavior. Because `rsvp.php` wrote the RSVP on GET, the crawler effectively flipped Paul's response three times before he ever opened the message; his stored `yes` was correct only by luck of the last hit being a YES tap. The fix splits `rsvp.php` into a GET branch (renders a "Confirm Your RSVP" page with a form) and a POST branch (the existing flip logic, now CSRF-protected). Existing short links and SMS templates are unchanged — they still work, they just take one extra Confirm tap. Crawlers that only fetch GETs leave invite state untouched.
- **Activity log now records pending-invitee RSVP flips.** Previously `rsvp.php` only wrote to `activity_log` if the invitee had a registered user account, so phone-only pending invitees (the majority of league rosters) left no audit trail. New flips for non-account invitees now log with `user_id=0` and the username + invite_id encoded in the action text.

---

## [v0.19025] — 2026-04-27

### Fixed
- **Pending league members (phone-only) now appear in the event editor's invite picker.** Two related bugs: (1) the picker's pending-league SQL used `LOWER(contact_email) AS username`, which came back NULL for invitees added with phone only — the dedup helper then silently dropped them. SQL now falls back to phone, then to a synthetic `pending:NN` key. (2) Admins were never running through the pending-league branch at all; their early-return only loaded site users. Admins now also get pending league invitees when a league is selected, just like non-admins do.
- **Pending invitees clicked from All Users now show their name on the Invited side.** Because the picker's synthetic username for phone-only pending invitees is the phone number, clicking one to invite them rendered the phone digits as the visible label. The picker now uses `display_name` as the saved invite username for pending rows, so invited entries read "Randy" instead of "xxxxxxxxxx" and the saved `invite_username` carries the human name.

---

## [v0.19023] — 2026-04-26

### Performance
- **Tuned Apache prefork MPM for the 458 MB VPS.** Default config allowed up to 150 worker processes; with PHP `memory_limit=128M`, that's a worst-case ~19 GB RAM footprint on a host with 458 MB. A traffic spike could OOM the host and kill all six containers running on it. New config caps `MaxRequestWorkers` at 25 (worst case ~3 GB, comfortably absorbed by swap if it ever lands there) and adds `MaxConnectionsPerChild=500` so workers recycle and release memory periodically. Configured in the Dockerfile via `/etc/apache2/conf-available/mpm-tuning.conf` so it survives rebuilds.
- **Indexed `events.start_date` and `events.end_date`.** The calendar's main month/week query (`event_visibility_sql`) was full-scanning the events table on every page load. With three events that's nothing, but it would have dominated page load at any meaningful scale. Indexes added via `db_init()` so they get created automatically on next request for any existing deployment.

---

## [v0.19022] — 2026-04-25

### Fixed
- **Walk-in autocomplete on the Manage Game screen leaked every site username to non-admins.** When a non-admin user (e.g. a league owner) opened a poker session's Manage Game page and typed in the "Add player" field, the autocomplete dropdown showed every username on the site, including users from other leagues and the site admin. The walk-in screen now applies the same scoping as the event editor's invite picker: admins still see all usernames, but non-admins only see members of the event's league plus their own personal contacts. Reported by williamwestmo, who was seeing `admin`, `brad`, and others he had no relationship to.

---

## [v0.19021] — 2026-04-25

### Changed
- **Removed the "Self-Hosted & Yours" feature card from the public landing page.** The card pitched Docker self-hosting as a feature, which is off-message for the SaaS landing page where most visitors will be signing up for the hosted service. Self-hosting is still fully supported and documented in the README; just not surfaced as a marketing point on the homepage.

---

## [v0.19020] — 2026-04-24

### Added
- **Edit button on the League Rules tab.** Previously, editing a league's Rules post required unsetting the rules flag, editing the post in the Posts tab, and re-flagging it — because the Posts tab feed filters rules posts out, the rules post was otherwise unreachable. New **Edit** button sits next to "Unset rules flag," visible to anyone `user_can_edit_post()` allows (admin, original author, league owner/manager). Clicking it opens the existing Jodit editor pre-filled with the rules title and content; the form round-trips back to the Rules tab on Save or Cancel via a `$backTab` flag derived from `is_rules_post`. No new endpoint or schema change — the existing `update` action in `league_posts_dl.php` handles rules posts identically to regular posts.

---

## [v0.19019] — 2026-04-24

### Changed
- **Manage Game back arrow now goes to the previous page.** The `&larr;` arrow in the `checkin.php` header used to hardcode `/calendar.php` as the destination, which was annoying when you landed on Manage Game from My Events, a league page, or a deep-link. Now it calls `history.back()` on click, falling back to `/calendar.php` only if there's no history (e.g., the tab was opened fresh).

---

## [v0.19018] — 2026-04-24

### Fixed
- **Re-adding a deleted user as a custom invitee now fires a fresh SMS/email.** `event_notifications_sent` is the dedup log that prevents re-saving an event from re-spamming invitees. Rows are keyed by `(event_id, occurrence_date, LOWER(user_identifier), notification_type)` where `user_identifier` is the username string. When a user account was deleted, `delete_user_account()` already cleared their `event_invites` and `pending_notifications` rows but left the dedup log untouched — so if the host later added the same name back as a custom invitee, the enqueue short-circuit at `calendar_dl.php:82` saw a "sent" row from before the delete and skipped the notification. `delete_user_account()` now also runs `DELETE FROM event_notifications_sent WHERE LOWER(user_identifier) = LOWER(?)`.

---

## [v0.19017] — 2026-04-24

### Fixed
- **Custom invitees now get added to the league Members tab reliably.** When a host added a "+Custom Invitee" to a league event, the invitee was added to the host's contacts but frequently did NOT show up on the League → Members tab, even when they were an existing registered user. Two gaps in `auto_add_pending_to_league()` in `db.php`: (1) the phone value from the combined "Email or phone" field was passed through raw (unnormalized), so `WHERE phone = ?` missed any `users.phone` stored in canonical `XXX-XXX-XXXX` form whenever the host typed a different format like `(xxx) xxx-xxxx` or `xxxxxxxxxx`; (2) there was no username fallback, so a custom invitee typed as just a username (no contact info) never resolved to the real user. Function now runs `normalize_phone()` at its own boundary and falls back to `WHERE LOWER(username) = LOWER(?)` like `auto_add_contact()` already did.

---

## [v0.19016] — 2026-04-23

### Changed
- **Walk-in seat assignment survives phone verification.** When a walk-in user entered their SMS code, `verify_phone.php` replaced the seat tile ("Table X · Seat Y") with a generic "Account Verified" page — users had to go hunt for where to sit. Now `walkin.php` stashes the player id in the session alongside `verify_user_id`, and `verify_phone.php` re-renders the same blue seat tile above the Sign In button on success. Session keys are cleared after render so a refresh doesn't show stale info. Tile is skipped cleanly for non-walk-in verify flows (normal registration, resend).

---

## [v0.19015] — 2026-04-23

### Security — OWASP Top-10 audit patches

**High severity**
- **Remember-me tokens invalidated on every password change.** Previously, changing your password via settings, resetting via email/SMS link, or having an admin reset it left old `remember_tokens` rows intact, so a stolen persistent-auth cookie continued to work. Every password-change path now runs `DELETE FROM remember_tokens WHERE user_id = ?` immediately after the hash update. Covers `settings.php`, `reset_password.php`, `user_edit.php`.
- **Session regenerated after password reset.** `reset_password.php` now calls `session_regenerate_id(true)` and clears `$_SESSION` after a successful reset, so any session ID an attacker may have had is retired. User is forced to log in fresh with the new password.

**Medium severity**
- **RSVP token flip cap.** The one-click RSVP links in invite emails could be replayed indefinitely — anyone who captured the link could flip the RSVP back and forth forever, triggering notifications. New `event_invites.rsvp_token_flips` counter, capped at `MAX_RSVP_TOKEN_FLIPS = 10`. Past the cap, the link shows "Link Exhausted — sign in to change your RSVP."
- **24-hour cumulative verification-code cap.** `verify_code()` previously capped attempts at 5 per row, but a user could resend-and-burn another 5 indefinitely. New 24-hour cumulative cap (`MAX_VERIFY_CODE_ATTEMPTS_PER_DAY = 20`) summed across all recent codes for that user.
- **Per-identifier login rate limit.** The existing per-IP cap (5 failed logins per IP per 15 min) doesn't stop a distributed botnet from credential-stuffing a single known user from many IPs. New `MAX_LOGIN_FAILURES_PER_USER_PER_HOUR = 5` cap, scoped to the specific email / username / phone that failed, counted across all IPs.
- **Comment rate limit.** `comment.php` had no throttle; any logged-in user could spam thousands of comments. New `MAX_COMMENTS_PER_HOUR = 20` per user.
- **Walk-in hijack of existing users closed.** A visitor scanning the QR code could type a victim's email/phone and silently mark them as checked-in (if they had an approved invite) or auto-RSVP them (if the event was open). Now the walk-in form never flips an already-approved invite and always creates a `pending` row for any existing-user walk-in, regardless of the event's `requires_approval` setting. Host sees the pending row and can approve if legitimate.

**Low severity**
- **Atomic reset-token consumption.** `reset_password.php` now marks the token used via `UPDATE ... WHERE id=? AND used=0` and checks `rowCount()` before proceeding, closing a tight race window where two concurrent requests could both succeed with the same token.

### Schema
- Added `event_invites.rsvp_token_flips INTEGER NOT NULL DEFAULT 0` (try/catch migration).
- Added four new constants to `db.php`: `MAX_COMMENTS_PER_HOUR`, `MAX_VERIFY_CODE_ATTEMPTS_PER_DAY`, `MAX_LOGIN_FAILURES_PER_USER_PER_HOUR`, `MAX_RSVP_TOKEN_FLIPS`.

### Not changed (false positives verified)
- `password_verify('', '')` returns **false** in PHP 8.x — empty password against empty hash does NOT authenticate. Walk-in users with empty `password_hash` cannot log in; they must go through the forgot-password flow. No fix needed.
- `current_user()` already handles deleted-user sessions correctly via `fetch() ?: null`. No `deleted` column needed.
- `invite_role` POST param is safe because the whole `save_invites` handler is gated by `can_manage_event()` upstream.

---

## [v0.19013] — 2026-04-23

### Added
- **Edit button on My Events rows.** Any event the user can manage (creator, per-event manager, league owner/manager, or admin — via `can_manage_event()`) now has a blue "Edit" button next to the green "Manage Game" button. Clicking it deep-links to `/calendar.php?open=ID&date=…&edit=1`, which auto-opens the event editor modal instead of the view-only modal. Appears on both upcoming and past event rows. Complements the Manage Game button which stays poker-only.

### Implementation
- `www/my_events.php` — two new conditional anchor tags keyed on the existing `$manageable[...]` lookup.
- `www/calendar.php` — when the auto-open query includes `edit=1`, call `openEditModal()` instead of `viewEvent()`. No change to the access check (`can_manage_event()` still runs on submit).

---

## [v0.19012] — 2026-04-23

### Changed
- **Walk-in success screen now shows the assigned seat.** `auto_assign_table()` already picks a seat and writes it to `poker_players.seat_number`, but the walk-in success tile only showed the table. The tile now reads `Table X · Seat Y` (with the label switched from "Your Table" to "Your Seat"). Falls back to `Table X` alone if seat is null, so events without seat assignment are unchanged. Both the existing-user and new-user walk-in branches read the seat back after `auto_assign_table()`.

---

## [v0.19011] — 2026-04-23

### Changed — walk-in verification model
- **Walk-in registration now uses soft "verify-after-the-fact."** Previously walk-ins went through two extremes: verification blocking entry (pre-v0.19009) or no verification at all (v0.19009). Neither handled the "typo your email and create a dead account" problem. Now a walk-in new-user insert creates an unverified account but immediately sends the verification email (for email signups) or SMS 6-digit code (for phone signups), and the success screen surfaces:
  - **Phone path:** an inline 6-digit input with a Verify button plus a "Skip for now" link. Submits to `/verify_phone.php` which already reads `$_SESSION['verify_user_id']`.
  - **Email path:** a "Check your inbox" note with the destination email. The email includes the existing reset-password link so they can set a real password.
- Users are registered for the event regardless of whether they verify — verification is about unlocking future login recovery, not gating event access.
- `must_change_password = 1` stays so they must set a password on first login.
- Existing walk-in accounts created under v0.19009 are unaffected (already flagged verified).

---

## [v0.19010] — 2026-04-23

### Added
- **Client-side phone auto-formatter.** Typing `xxxxxxxxxx` in any phone field now shows `(xxx) xxx-xxxx` as you type. New shared script `www/_phone_input.js` binds to every `input[type="tel"]` and to combined "Email or phone" inputs tagged with `data-phone-contact`. Included on register, walk-in, contacts, league (add member), profile settings, user edit, and admin settings pages.
- **Server-side phone format docs.** Added a docblock to `normalize_phone()` in `db.php` documenting the canonical `XXX-XXX-XXXX` storage format and every input shape it accepts (`xxxxxxxxxx`, `xxx-xxx-xxxx`, `(xxx) xxx-xxxx`, `xxx.xxx.xxxx`, `+1 (xxx) xxx-xxxx`, `1-xxx-xxx-xxxx`). Defensive `trim()` added up front — callers already trim, but cheap belt-and-suspenders.

### Storage note (no change)
- All phone numbers are stored in the canonical `XXX-XXX-XXXX` form in `users.phone`, `event_invites.phone`, `league_members.contact_phone`, and `user_contacts.contact_phone`. Every `WHERE phone = ?` lookup in the codebase compares against a `normalize_phone()`-d value, so regardless of input format, a phone-based login / lookup / dedup check succeeds.

---

## [v0.19009] — 2026-04-23

### Changed
- **Walk-in registration no longer sends a verification code or email.** The QR code token already proves the user is physically at the event, so confirming their phone/email exists is pointless friction. New walk-in accounts are now created with `email_verified=1` and `phone_verified=1` immediately. No SMS code, no email verification link, no session-dance through `/verify_phone.php`. The success screen just says "You're registered — have fun." `must_change_password=1` remains so the user is forced to set a password via `/settings.php?must_change=1` the first time they try to sign in later; they can also reset via the normal forgot-password flow (which sends via SMS or email now that v0.19000 is in place).

### Reverted
- Reverted v0.19008's phone-verify link and session-stashing on the walk-in success page — that fix addressed the wrong layer. The real fix is to not send the code at all.

---

## [v0.19008] — 2026-04-23

### Fixed
- **Phone-only walk-in users had no way to enter their SMS verification code.** `walkin.php` sent a 6-digit code via `send_verification_code()` but didn't stash `$_SESSION['verify_user_id']` / `$_SESSION['verify_method']`, so `/verify_phone.php` showed "Session expired". The success message also read "Check your email" regardless of channel. Fix: session vars set immediately after `send_verification_code()`; the success screen now shows a "tap here to enter it" link to `/verify_phone.php` for the phone branch while the email branch keeps its existing "check your inbox" copy. Applies to both the approved and waiting-list success paths.
- **Walk-in accounts could never set a password.** Previously walk-ins were created with `password_hash = ''` and `must_change_password = 0`. Email walk-ins got around this because the verification email delivered a reset-password link, but phone walk-ins had no equivalent. Flipped to `must_change_password = 1` so the existing "must change" gate in `attempt_login()` (auth.php:157) redirects users to `/settings.php?must_change=1` on first sign-in regardless of channel.

---

## [v0.19007] — 2026-04-23

### Changed
- **Walk-in (QR-code) form collapsed to a single "Email or phone" field**, matching the main `/register.php` pattern from v0.19000. Backend auto-detects email vs. phone based on whether the value contains `@`; all downstream validation, normalization, verification dispatch, and `auto_add_to_league()` calls are unchanged. The remember-cookie renamed `walkin_email` → `walkin_contact` with a fallback to the old cookie so returning users still get pre-fill. Fewer taps at the door when a line of guests is scanning the QR code.

### Confirmed (no change needed)
- Walk-in registration already accepted email OR phone (v0.19000). Walk-in accounts created for league events are already auto-added to the league via `auto_add_to_league()` in both the existing-user (walkin.php:146) and new-user (walkin.php:221) paths. No backend change required for the "put them into the league" ask.

---

## [v0.19006] — 2026-04-22

### Added
- **Custom invitees on league events auto-join the league.** When a host saves an event attached to a league and adds a custom invitee (typed name + email or phone), that person is now upserted into `league_members` on save. If their email / phone matches an existing registered user, they're added as a regular member; otherwise a pending contact row is created with an `invite_token`. The league's Members tab surfaces them immediately with the "Pending" badge, and the existing claim logic in `register_user()` links them automatically when they sign up. Dedup is handled by a pre-check plus the partial unique index on `(league_id, LOWER(contact_email)) WHERE user_id IS NULL`.

### Implementation
- `www/db.php` — new helper `auto_add_pending_to_league(PDO, league_id, name, email, phone, invited_by)` next to `auto_add_to_league()`.
- `www/calendar.php` — event add + edit save paths call the helper after `auto_add_contact` when `$league_id` is set.
- `www/calendar_dl.php` — same two call sites in the mobile/recurring event paths.

---

## [v0.19005] — 2026-04-22

### Changed
- **Custom invitee row consolidated into a single "Email or phone" field.** Matches the registration form pattern introduced in v0.19000: one input, auto-detect on `@`. The submit-time collector splits the value into `invite_email` / `invite_phone` before posting so the existing backend + v0.19004 dispatch fallback continue to work unchanged.

---

## [v0.19004] — 2026-04-22

### Added
- **Custom invitees now accept phone numbers on the calendar event editor.** The "+ Custom" button on `calendar.php`'s event modal adds a row with three inputs — Name, Email, Phone — so a host can invite someone by email only, phone only, or both. The mobile variant in `calendar_dl.php` already had all three inputs.

### Fixed
- **Custom invitees actually get invited now.** `dispatch_queued_notification()` used to look up every queued invite by `users.username` — and when the invitee wasn't a registered user (which is exactly the case for typed-in custom invitees), it silently returned success and the invite was dropped. Added a fallback that reads the `email` / `phone` directly from the `event_invites` row and delivers via the appropriate channel (`send_email` / `send_sms`, SMS default for phone-only). Email invitees now receive the existing YES/NO/MAYBE buttons; phone invitees get the RSVP URL. Applies to every queued event notification type, not just invites.

---

## [v0.19003] — 2026-04-22

### Changed — My Events screen
- **League tag on each event row.** Events belonging to a league now show a small blue pill with the league name, clickable through to the league page. Uses the same visual style as the landing-feed `.league-badge`. Non-league events render unchanged.
- **"Manage Game" button for all authorized managers.** The green Manage Game button is no longer creator-only — it now appears for anyone `can_manage_event()` accepts (site admin, creator, per-event manager, or league owner/manager). Still poker-only per scope; non-poker events get no button.

### Implementation
- `www/my_events.php` — `LEFT JOIN leagues` added to the events query, `league_name` surfaced per row, `$manageable` lookup precomputed with `can_manage_event()` (from `db.php`) so the button condition is one array check per row.

---

## [v0.19002] — 2026-04-22

### Changed
- **League managers can now manage every event in their league.** Previously, league owners and managers could edit basic event fields (title, date, invitees) from the calendar but couldn't approve pending players, start the timer, adjust blinds/payouts, or run table assignment for the same event — the calendar and poker paths used different permission checks. All event-management code now routes through a single `can_manage_event()` helper in `db.php` that returns true if the user is a site admin, the event creator, an explicit per-event manager (`event_invites.event_role='manager'`), or an owner/manager of the league the event belongs to. Affects: `calendar.php`, `calendar_dl.php`, `checkin.php`, `checkin_dl.php` (`is_owner_or_manager`), and `_poker_helpers.php` (`verify_event_access` / `check_event_access`).
- **Edit pencil icon visibility.** The calendar's edit affordance on event chips now shows up for league managers on every event in their league (the underlying POST already accepted the change; only the UI was hiding it). Implemented by extending `$managedEventIds` to include league-owned events.

---

## [v0.19001] — 2026-04-22

### Fixed
- **Phone registration rejected valid US numbers.** `normalize_phone()` formats a 10-digit US number as `XXX-XXX-XXXX` (with dashes), but the new phone validators introduced in v0.19000 only accepted raw digits (`^\+?\d{7,15}$`). A user typing `xxxxxxxxxx` saw "Invalid phone number." Replaced the strict digit-only regex with "strip non-digits, count 7–15". Same fix applied in `register_user()`, `find_user_by_identifier()`, and `walkin.php` so lookups, signups, and walk-ins all accept formatted phones.

---

## [v0.19000] — 2026-04-22

### Added — register / login with email OR phone
- **Register with just a phone number.** New users can sign up with either an email address OR a phone number (at least one; both still allowed). The registration form now has a single combined "Email or phone" field that auto-detects based on whether the input contains `@`. Phone-only signups get a 6-digit SMS code; email signups get the existing verification link.
- **Login accepts email, username, or phone.** The login form's first field is now labeled "Email, username, or phone" and resolves the identifier against any of those three columns. New helper `find_user_by_identifier()` in `auth.php` centralizes the lookup (used by login, forgot-password, and resend-verification).
- **Forgot-password works for phone-only users.** If the recovered account has `verification_method = 'sms'` / `'whatsapp'`, the reset link is sent via SMS or WhatsApp (auto-shortened by `shorten_url()` when enabled) instead of email.
- **Walk-in registration accepts either contact.** Walk-ins can give an email OR a phone. A phone-only walk-in account gets an SMS verification code; email walk-ins keep the existing email-link flow.
- **Verification gate is channel-aware.** The login path now checks `email_verified` for email signups and `phone_verified` for SMS/WhatsApp signups — a phone-only user no longer has to set up an email to unlock their account.

### Schema
- Added partial unique index `idx_users_phone` on `users(phone) WHERE phone IS NOT NULL` so phone-only signups reject duplicates. Wrapped in try/catch so any existing duplicate-phone rows fail quietly without blocking `db_init`.

### Changed
- Login and forgot-password inputs switched from `type="email"` → `type="text"` so browsers don't reject phone-number input. `autocomplete="username"` keeps the credential manager happy.
- Resend-verification page accepts email OR phone and delivers via the user's registered channel.

### Files touched
- `www/auth.php` — new `find_user_by_identifier()`; `register_user()` accepts email-or-phone; `attempt_login()` uses the three-way lookup and verifies against the correct channel.
- `www/db.php` — phone-uniqueness partial index.
- `www/register.php`, `www/register_dl.php`, `www/login.php`, `www/forgot_password.php`, `www/resend_verification.php`, `www/walkin.php` — updated forms and backend to consume `identifier` / `contact` fields.

---

## [v0.18001] — 2026-04-22

### Changed
- **Minimum password length dropped from 12 → 8.** All eleven call sites (register, auth, auth_dl, admin_settings, admin_settings_dl, reset_password, settings, user_edit, register_dl, plus client-side `minlength` hints) now read from a single `MIN_PASSWORD_LENGTH` constant in `db.php`. Error messages, `minlength` attributes, and "At least N characters" hints all stay in sync with the constant.
- **Registration rate limit raised from 5 → 20 per IP per hour** via the new `MAX_REGISTRATION_ATTEMPTS_PER_HOUR` constant. Same limit now applies to the walk-in form. Typos and retries during signup no longer hit the cap as fast. Brute-force / scraping protection is still in place — 20/hour is still far below abuse levels.
- **Calendar view shows a league identifier** on each event chip. A compact 5-letter tag derived from the league name renders as a semi-transparent pill inside the event chip (month grid, week all-day row, and week timed chips), with the full league name on hover. Non-league events render unchanged. Accompanying SQL change: the calendar's event queries now LEFT JOIN `leagues` to pull the league name.

---

## [v0.18000] — 2026-04-22

### Fixed
- **Posts-tab edit buttons were hidden.** The league.php Posts-tab query omitted `p.league_id` from its SELECT, which made `user_can_edit_post()` evaluate `$post['league_id']` as 0 and always return false — hiding the Edit / Delete / Set as rules buttons for owners and managers. Added `p.league_id` to the SELECT.

### Changed — GUI polish
- **League names are links on `/leagues.php`.** On all three tabs (My Leagues, Browse, My Requests), clicking a league name now routes to `/league.php?id=…` (same as the existing "View" button). Color stays inherited so the visual layout is unchanged.
- **Post-action buttons are visually consistent.** On both the landing-page feed and the league Posts tab, the Edit / Delete / Set as rules buttons now share the same min-width (72px), centered inline-flex alignment, and padding so the `<a>` Edit link matches the `<button>` forms instead of being noticeably smaller.
- **Leave league button is now clearly a danger action.** Previously a tiny ghost-style button on the league header that blended in. Now a red-outlined button with a ❌ prefix, bolder font, and a hover that fills red — hard to miss but still secondary.

### Added — league posts + League Rules button
- **League-scoped posts.** Owners and managers can now write posts for their league from a new **Posts** tab on `league.php`. Content uses the same Jodit rich-text editor that admins already use for global posts (image uploads via `/upload.php` work unchanged). League posts appear on the home-page feed (`index.php`) mixed chronologically with admin global posts, each tagged with a small clickable league badge. Non-members cannot see league posts; the visibility filter lives in the new `www/_posts.php` helper (`posts_feed_sql_for_user`).
- **League Rules button.** Any league post can be flagged as the league's rules post. A prominent 📜 **League Rules** button appears in the league header when a rules post exists; it links to a dedicated `?tab=rules` view of that post. Rules-flagged posts are excluded from the Posts feed and the home feed so the chronological stream stays clean. Enforced at exactly one rules post per league via a partial unique index on `posts(league_id, is_rules_post)`.
- **Admin scope picker.** `admin_posts.php` now has an optional "Post scope" dropdown so admins can author on behalf of a specific league when needed. Blank = global post (default behavior preserved).
- **Comment visibility.** `comment.php` guards league-post comment submissions with `post_is_visible_to()` so non-members can't post comments even via direct request.

### Schema
- `posts.league_id` (NULL = global admin post — preserves current behavior for all existing posts), `posts.author_id` (nullified on user delete, not cascaded), `posts.is_rules_post` (partial unique index enforces one-per-league).
- Cascade delete: deleting a league removes its posts and their comments.

### New files
- `www/_posts.php` — feed visibility helpers and per-row edit/view permission checks.
- `www/league_posts_dl.php` — CSRF-guarded, role-checked write endpoint for league post actions (create / update / delete / set_rules / clear_rules / toggle_pin / toggle_hide). Supports both JSON and redirect responses.

---

## [v0.17004] — 2026-04-22

### Changed
- **Updated the standard payout preset table.** Adjusted the 4+ place splits so adding places produces the expected flattening curve: 3 → 50/30/20, 4 → 40/25/20/15, 5 → 38/22/17/13/10, 6 → 33/22/16/12/10/7, 7 → 30/20/15/12/10/8/5, and similarly flatter curves through 10 places. "+ Add Place" and "Auto Split" both apply these presets.

### Reverted
- Reverted the v0.17003 proportional-shrink behavior of "+ Add Place"; hosts want the preset applied so the structure matches common tournament conventions.

---

## [v0.17002] — 2026-04-22

### Added
- **Queued invite emails now include one-click RSVP buttons.** When invites moved to the notification queue in v0.16000, the email body lost the per-invitee YES / NO / MAYBE (if allowed) buttons that the inline sender had. `dispatch_queued_notification()` for `invite` rows now looks up `event_invites.rsvp_token` and renders the same YES/NO/MAYBE buttons the inline sender used, falling back to a plain event link if no token exists. SMS/WhatsApp bodies include the direct RSVP URLs too.

### Fixed
- **Enqueue-time dedup for invites.** Both invite enqueue sites (`calendar.php`, `calendar_dl.php`) now check `event_notifications_sent` before inserting into `pending_notifications` and skip anyone already sent. Previously a re-edit that deleted and re-inserted the same invitee rows could enqueue a second invite, which the drain would then deliver. Combined with the dispatch-time dedup added in v0.17001, invites are now idempotent at both enqueue and dispatch.

---

## [v0.17001] — 2026-04-22

### Fixed
- **Duplicate invite emails.** A single invite could be delivered up to 3 times. Root cause: `dispatch_queued_notification()` threw an exception whenever `send_notification()` surfaced any provider error (including non-fatal secondary-channel failures for users on `preferred_contact = 'both'`), which released the queue row for retry after the email had already been sent successfully. Each retry re-sent the email until `attempts` hit the 3-cap. Fix: write the `event_notifications_sent` dedup marker for every notify_type (not just reminders) immediately after `send_notification()` returns, and check it at the top of `dispatch_queued_notification()`. Partial failures are now logged rather than thrown, since the email that already went out cannot be un-sent.

---

## [v0.17000] — 2026-04-21

### Added — rate-limit protections
- **Per-event invite cap.** Event save refuses to insert if invitees exceed `MAX_INVITEES_PER_EVENT` (200). Prevents a single event from spawning thousands of queue rows.
- **Drain pause on provider rate-limit.** When an SMTP/SMS/WhatsApp provider returns a 429-like error during a send, the entire notification drain pauses for `DRAIN_PAUSE_ON_429_MINUTES` (15 min) via the new `notification_drain_paused_until` site setting. Both `cron.php` and `cron_drain.php` honor the pause. Protects the provider account from escalating rate-limit penalties.
- **Per-recipient daily cap.** `queue_event_notification()` rejects inserts if the recipient has `MAX_NOTIFICATIONS_PER_DAY` (20) queued-or-sent rows in the last 24 hours (reminders exempt — they're pre-scheduled with their own dedup). Accidental storms (saving the same event 50 times, firing multiple cancellations) stop at the cap.

### Changed
- `send_notification()` now captures provider errors via `$GLOBALS['_last_notification_error']` and `get_last_notification_error()`. Inline callers are unchanged (they still ignore errors); the queue drain reads the error to detect rate-limit hits and retry rows.

### Fixed
- **Orphaned notification history.** Per-event delete paths (`calendar.php`, `calendar_dl.php`, `admin_settings.php`) now clean up already-sent `pending_notifications` rows and `event_notifications_sent` dedup rows when the event is deleted. Previously these tables accumulated orphans because SQLite FKs are not enforced and no explicit cascade was run. A one-shot migration (`orphan_notifications_cleaned` setting) purges any existing orphans on first DB init after upgrade.

---

## [v0.16001] — 2026-04-21

### Fixed / hardened
- **SQL injection defense-in-depth pass.** No exploitable vulnerabilities were found; three "currently-safe-but-fragile" patterns were hardened so a future refactor can't accidentally introduce one.
  - `leagues_dl.php`: three `->query("… WHERE league_id = " . $league_id)` calls converted to prepared statements. Previously safe because of an `(int)` cast upstream, but this was the last remaining string-concat-into-SQL pattern in the codebase.
  - `_poker_helpers.php` `save_user_session_defaults()`: now intersects every column name against the `USER_SESSION_DEFAULT_COLS` whitelist before interpolating into the SQL string, so unknown keys in `$data` can't reach SQL even if a caller forgets to pre-filter.
  - `db.php` `event_visibility_sql()`: validates the `$alias` argument matches `[A-Za-z_][A-Za-z0-9_]*` and throws on anything else. All current callers pass a literal, so behavior is unchanged; the guard stops a future caller from forwarding user input.

---

## [v0.16000] — 2026-04-21

### Changed
- **Unified notification queue.** All event-related outbound notifications now flow through `pending_notifications`: reminders, cancellations, RSVP-to-creator, waitlist promotions, RSVP-deadline demotions, poker approvals, and the existing invites. Previously only invites were queued; everything else fired inline and could hang the HTTP request on slow SMTP/SMS APIs. New columns: `scheduled_for` (send time), `payload` (JSON for type-specific data), `occurrence_date` (per-occurrence recurring events).
- **Instant drain on enqueue.** Every queue insert now spawns `cron_drain.php` in the background so notifications deliver in seconds instead of waiting up to 5 min for the next cron tick. The 5-min cron still runs as a retry safety net.
- **Configurable reminders per event.** Event creators pick any combination of preset offsets — 1 week / 3 days / 2 days / 1 day / 12 hr / 2 hr / 30 min — or toggle reminders off entirely for a specific event. Per-event `reminders_enabled` and `reminder_offsets` columns on the events table. Site-wide default offsets set by admin (Site Settings → Notifications → Event Reminders).

### Added
- **Admin site default for reminders.** Multi-select checkboxes in Site Settings pick which offsets are pre-checked for new events. Default is 2 days + 12 hours (matches the previous hardcoded behavior).
- **`_notifications.php`** — central `queue_event_notification`, `queue_reminders_for_event`, `clear_pending_reminders`, `dispatch_queued_notification` API. Replaces scattered inline body-building across calendar, webhook, rsvp, checkin, and db files.

### Fixed
- **Short-notice events.** A 1-hour-out event no longer queues a 2-day reminder that immediately fires on drain. Offsets whose scheduled time is already in the past are dropped at queue time.

### Migration
- New columns auto-added via `ALTER TABLE`. Existing events default to `reminders_enabled = 1` with `reminder_offsets = NULL` (use site default). First cron run after upgrade back-queues reminders for upcoming events into `pending_notifications` with future `scheduled_for` timestamps.

### Out of scope (intentional)
- League-scope notifications (join requests, role changes, member invites) still fire inline — the league feature is still settling; this will be revisited in a future pass.
- Password reset, email/phone verification codes, admin test sends, and WhatsApp bot command replies stay inline (they're transactional, not broadcast notifications).

---

## [v0.15000] — 2026-04-20

### Changed
- **Whole-dollar money inputs.** Dropped cents from every money input in the poker flow: event editor buy-in, initial session setup form (buy-in / rebuy / add-on), and live check-in settings panel (buy-in / rebuy / add-on). Inputs step by $1 instead of $0.01. Values still stored as cents internally; display output gains nothing extra but loses the stray `.00`s.

### Added
- **Remembered session defaults per user (and per league).** The poker config fields (game type, buy-in, rebuy, add-on, starting chips, add-on chips, rebuys allowed, max rebuys, add-ons allowed, tables, seats, auto-assign) now remember whatever the host used last. When creating a new event, the fields pre-fill from the host's last-used values. Scoping: league-scoped first (so a host can run different configs for different leagues), then personal fallback, then hardcoded defaults for first-timers. Remembered values update on every session save — both initial create and live config edits.
- **Add-on Chips in initial setup.** The initial session setup form now also exposes the Add-on Chips field (previously only in the live settings panel).
- New table `user_session_defaults` keyed `(user_id, league_id)` with cascade delete on both user and league deletion.

---

## [v0.14000] — 2026-04-20

### Changed
- **Add-ons rebuilt (tournament-only).** Add-ons now grant chips in addition to adding dollars to the pool, and the count-vs-cents confusion in `poker_players.addons` is fixed. New `addon_chips` column on `poker_sessions` (defaults to `starting_chips`) lets hosts configure exactly how many chips one add-on is worth, since real tournaments often discount add-on stacks. The Manage Game add-on column replaces the confusing checkbox-plus-dollar-field combo with a single "+ Add-on" button and a small count badge; tap the badge to remove the last add-on. Pool math now multiplies count by session `addon_amount` instead of summing cents-per-player. Avg Stack on the timer now includes add-on chips in the total chips-in-play calculation.

### Removed
- **Cash game add-on concept.** Cash-game sessions never had a coherent add-on flow (`cash_in` already tracked every dollar), so add-ons are now formally tournament-only. Cash game UI unchanged.

### Migration
- One-shot migration converts existing `poker_players.addons` values from cents to counts by dividing by the session's `addon_amount`. Guarded by `addons_migrated_to_count` setting so it only runs once. New `addon_chips` column defaults to `starting_chips` for all existing sessions.

---

## [v0.13000] — 2026-04-20

### Added
- **Average stack on timer.** Tournament timer now shows the current average chip stack in a glass panel on the top-left, top-aligned with the Payouts panel on the right. Value updates live as players buy in, rebuy, or get eliminated. Computed as `(total_buyins + total_rebuys) × starting_chips ÷ still_playing`. Hidden for cash games and while no one has bought in.

---

## [v0.12000] — 2026-04-20

### Added
- **Reusable payout structures (#9).** Tournament payouts are no longer locked to a hardcoded 50/30/20 — hosts can now save named payout structures and reuse them across sessions. Scoped like blind presets: Personal, League (visible to all league members, editable by owners/managers), and Global (admin-curated). The settings panel's Payout Structure section now includes a grouped dropdown plus Save As / Load / Delete / Set Default buttons. A default "Standard (50/30/20)" structure is seeded on first run, and new sessions apply the current default instead of the legacy hardcoded values. New tables: `payout_structures`, `payout_structure_places`.

---

## [v0.11000] — 2026-04-20

### Added
- **League-scoped blind presets.** League owners and managers can save blind structures that automatically appear in the timer preset dropdown for every member of that league — no more bloating the global list. New `league_id` column on `blind_presets`. The timer's Save Preset flow now offers a scope picker: Personal, Global (admin), or any league the user owns/manages. Delete and edit permissions are gated on league role. The preset dropdown groups entries under their league name ("League: PCF Test League") alongside Default, Global Presets, and My Presets.

---

## [v0.10000] — 2026-04-20

### Added
- **Personal contacts (#14).** Each user now has a private address book at `/contacts.php`. Strict isolation — users never see another user's personal contacts. New `user_contacts` table, spreadsheet-style UI with inline editing, CSV import/export, Add/Delete, and a "Pending" vs "Linked" status badge.
- **Auto-link on signup.** When a pending contact signs up with a matching email or phone, the `linked_user_id` fills in automatically (same pattern as league pending contacts).
- **Auto-add on invite.** Inviting someone to an event automatically saves them to the inviter's personal contacts (skipped if a matching contact already exists).
- **Nav link.** New "Contacts" entry between My Events and admin links in both desktop and mobile nav.

### Changed
- **Non-league event invite picker** now shows personal contacts ONLY (replaces the old implicit "network" of shared-league members + past invitees).
- **League event invite picker** now shows the league roster MERGED with the creator's personal contacts, deduped.
- **Account delete cascade** now also removes the user's personal contacts and unlinks any contacts that pointed to that user.

---

## [v0.09000] — 2026-04-20

### Removed
- **Check-in column on Manage Game (#10).** The per-player "Checked In" checkbox column was redundant with the Buy-In column — buying a player in now implicitly admits them. Removed the checkbox column, the "Checked In" stat tile, the "In:" compact stat, the "Checked In" status badge, the mobile CI checkbox, the `toggle_checkin` backend action, and switched table-assignment filters to use `bought_in` instead. The DB column stays for backwards compatibility but is no longer surfaced or relied on.

---

## [v0.08900] — 2026-04-20

### Fixed
- **Edit-to-view navigation (#16).** When editing an event opened from the view modal, closing the edit window now returns to the view modal instead of dropping back to the calendar. Opening edit directly still closes normally.

---

## [v0.08800] — 2026-04-20

### Fixed
- **Timer player slideout sort.** The panel was grouping players by RSVP status (yes/null/no) which created two visible alphabetical clusters. Simplified the sort to a single continuous list: non-eliminated players alphabetically, then eliminated players at the bottom.

---

## [v0.08701] — 2026-04-19

### Fixed
- **Ghost league memberships on user delete.** Admin-deleting a user now also removes their `league_members` rows, `league_join_requests`, and any queued `pending_notifications` targeting their username. Previously these rows were orphaned and showed up as empty slots on league rosters.
- **League owner delete cascade.** If the deleted user owns leagues, ownership auto-transfers to the longest-tenured manager (or oldest member if no managers). If no other members exist, the league is cascade-deleted. Extracted the cascade logic into a shared `delete_league_cascade()` helper so both the owner-delete button and the user-delete path use the same code.

---

## [v0.08700] — 2026-04-19

### Added
- **Fire-and-forget queue drain on save.** After an event save queues invite notifications, a background PHP process is spawned via `shell_exec(... &)` to drain the queue immediately. Small invite lists now deliver in seconds. The 5-min cron still runs as a safety net for retries and any rows the background spawn missed.
- **New `cron_drain.php`** — token-protected, CLI- or HTTP-callable endpoint that only drains the notification queue (no reminders, no maintenance).

### Changed
- **Cron interval 30 min → 5 min.** The built-in Docker scheduler now ticks every 5 minutes instead of every 30. Cost is negligible (cheap no-op when queue is empty) and it tightens the safety-net delay.
- **Waitlist default OFF** for new events. Hosts opt in per event. Existing events keep their stored setting.
- **Mobile arrows.** The invite-pane arrow buttons show up/down glyphs on mobile (↓ ⇓ ↑ ⇑) instead of the desktop left/right chevrons, matching the stacked pane layout on narrow screens.

---

## [v0.08600] — 2026-04-18

### Fixed
- **Event save hang on large invite lists.** Invite notifications are now queued in a `pending_notifications` table and sent asynchronously by cron instead of blocking the form POST with serial SMTP/SMS/shortener API calls. Saving an event with 200 invitees now returns instantly; the queue drains at up to 100 notifications per cron run (every 30 min), with a 3-attempt retry cap.

---

## [v0.08500] — 2026-04-18

### Added
- **Per-event waitlist toggle.** New "Waitlist" toggle in the event editor (visible when Poker is on). When disabled, all invitees are approved regardless of seat capacity — no divider, no waitlisting. Default is ON. Toggling off approves all existing waitlisted invitees.
- **Short.io URL shortener.** Replaced the built-in `/s/<code>` shortener with Short.io API integration. Admin settings now have Short.io API Key (encrypted at rest) and Domain fields. Local cache prevents duplicate API calls.
- **League badge in event view.** Event view modal shows the league name as a blue pill badge before the event title.
- **Donation banner.** Admin-configurable donation banner on the home page (above posts) with a footer link. Set URL and custom message in Site Settings > General.

### Changed
- **Event editor: full-screen modal.** Expanded to 95vw x 95vh. Top bar merges league, visibility, color, title, date, time, duration. Toggles + Save/Cancel in a compact toolbar. Poker settings inline. Description collapsible. Invite panes fill all remaining vertical space.
- **RSVP badges in invite editor.** Each invitee shows a colored badge (Yes/No/Maybe/Waitlist) when editing an event. Declined users are separated into a collapsible "Declined" section.
- **Landing page refreshed.** 12 feature cards covering leagues, rosters, scoped events, stats, privacy, and self-hosted pitch.
- **Nav reorder.** Leagues moved right after Home in both desktop and mobile nav.

### Fixed
- **Invite list scrambling.** The RSVP poll endpoint (`event_invites_dl.php`) was ordering by username instead of sort_order, scrambling the priority invite list every poll cycle. Now orders by sort_order and includes sort_order + event_role in the response.
- **Sort order recompaction.** `recompact_sort_order()` runs after every promote to keep approved, waitlisted, and declined invitees in consistent order across view and edit.
- **League auto-populate removed.** Creating a league event no longer force-adds all league members — only explicitly selected invitees are added.
- **Creator excluded from auto-populate.** Event creators are no longer added to their own invite list.
- **Auto-promote on all RSVP paths.** SMS and WhatsApp webhook RSVP "No" replies now trigger waitlist auto-promote (previously only calendar UI and email token did).
- **Buy-in field.** Dropped cents — whole dollars only.

---

## [v0.08400] — 2026-04-18

### Added
- **Inline poker game settings.** When creating/editing an event, toggling "Poker Game" on expands game type, buy-in, tables, seats-per-table, and RSVP deadline fields directly in the event editor. A `poker_sessions` row is auto-created on save — no more separate setup step on the checkin page.
- **Priority invite list with drag-and-drop.** The invited-users pane is now drag-sortable. For poker events, a red dashed capacity divider line marks the seat cutoff. Invitees above the line are priority (immediate invite); invitees below are waitlisted.
- **Waitlist system.** New `approval_status='waitlisted'` for invitees beyond seat capacity. Waitlisted users are blocked from RSVPing and see a "Waitlisted" badge on My Events and a "You're on the waitlist (position #N)" notice in the event view.
- **Auto-promote on decline.** When a priority invitee RSVPs "No" (via calendar, email token, or SMS/WhatsApp), the top waitlisted invitee is automatically promoted and notified ("A seat opened up").
- **RSVP deadline processor.** Cron job processes poker events past their configurable deadline (24/48/72h before start). Non-responding priority invitees are demoted to the waitlist and notified; waitlisters auto-promote to fill the gaps.
- **Seat count in event view.** Poker events show "X/Y seats filled" in the event view modal metadata.

### Fixed
- **Duplicate event_invites.** Added a unique index on `(event_id, username, occurrence_date)` and cleaned up existing duplicates caused by the league auto-populate path.

---

## [v0.08301] — 2026-04-16

### Fixed
- **Walk-in QR registrants now auto-join the league.** When a user registers via walk-in QR for a league event, they are automatically added to that league's roster. Applies to both existing users and new signups, and also to host-added walk-ins via the check-in panel. Duplicate-safe via `INSERT OR IGNORE`.

---

## [v0.08300] — 2026-04-16

### Changed
- **Stats are now league-scoped.** The standalone `/stats.php` page is gone. Stats (leaderboard, My Stats panel, date-range picker) are now a **Stats tab** inside each league page. Only finished tournament games within that league are counted — no cross-league stat contamination.
- **Nav bar** no longer shows a global "Stats" link. Bookmarks to `/stats.php` redirect to the user's first league stats tab.

---

## [v0.08100] — 2026-04-16

### Added
- **Per-league rosters.** League owners and managers can now add members directly via the Members tab — by name + email/phone. If the email matches an existing user they're added instantly; otherwise a pending contact is saved and a one-click invite link is sent. When the invitee signs up with the matching email/phone, the pending row auto-links to their new account.
- **Resend invite.** Pending contacts show a "Resend invite" button that regenerates the token and re-sends the invite notification.
- **Scoped event-invite picker.** The event editor's "All Users" pane is now scoped to the selected league's roster (members + pending contacts) when a league is picked. For non-league events the picker shows the creator's "network" — people in leagues they're in plus people they've previously invited — no longer the full site user list.

### Changed
- **`league_members.user_id` is now nullable** to support pending contacts. Unique constraints were reworked to allow multiple pending rows per league while still preventing duplicate linked memberships and duplicate pending emails.
- **Pending contacts cannot hold roles.** Promote/demote actions now refuse to target rows without a linked user.

---

## [v0.08000] — 2026-04-16

### Added
- **Leagues.** Users can create and join named leagues, with many-to-many membership. League owners set a description, default event visibility, approval mode (manual/auto), and can hide the league from the public browse directory.
- **Owner / Manager / Member roles.** Owner can promote members to managers (who approve membership changes) and transfer ownership. Managers can approve/deny join requests and remove members but cannot edit league settings, promote others, or delete.
- **Request-to-join with approval flow.** Manual-approval leagues send a notification to owner + managers; requester is notified on approval/denial. Auto-approval leagues let anyone join instantly.
- **Leagues admin UI.** New `/leagues.php` directory (My Leagues / Browse / My Requests tabs) and `/league.php?id=X` single-league view with Members, Events, Requests, and Settings tabs.

### Changed
- **Event visibility is now scoped.** Every event has one of three visibility modes: `public` (everyone can see), `league` (league members only), or `invitees_only` (only the creator and explicit invitees). Default for new events is `invitees_only`. League events can be created with `visibility='league'`, which auto-populates the invite list with current league members so existing reminder cron keeps working.
- **Calendar, Home, and My Events** all now filter events through a central `event_visibility_sql()` helper — non-admins only see events they created, were invited to, or can see via league membership.
- **Walk-up QR registration** is now restricted to public events only. Private and league events cannot generate a walk-in QR code.

---

## [v0.07302] — 2026-04-14

### Removed
- **Timer winner overlay.** Removed the last-player-standing winner animation and its server-side detection. The feature was unreliable in practice and is not coming back — the existing Finish Game button on the player panel is the canonical way to end a tournament.

---

## [v0.07300] — 2026-04-14

### Added
- **Date range filter on Player Stats.** Preset dropdown (7d / 30d / 90d / 1yr / YTD / All time) plus a Custom option with from/to date pickers. Filters both the personal summary and the leaderboard by `events.start_date`, using the site timezone.

---

## [v0.07200] — 2026-04-14

### Added
- **Database maintenance cron.** Automatic pruning of stale data: expired tokens (24h), notification dedup (30d), logs + short links (90d). Runs every 30 minutes via the built-in scheduler.
- **Built-in background scheduler.** Docker container auto-generates a cron token on first start and runs `cron.php` every 30 minutes in a background loop. Zero manual setup.
- **Scheduled Tasks admin tab.** New tab in Site Settings with full documentation: what runs, why the token exists, Docker vs manual setup instructions.
- **Unified `delete_user_account()`.** All 6 user-delete paths now use a single function in `db.php` that cleans up: invites, poker players, comments, tokens, resets, pending RSVPs.

### Fixed
- **Orphan comments.** Deleting a post or event now also deletes its comments.
- **User delete gaps.** Comments, password resets, and sms_pending_rsvp are now cleaned up on user deletion.

---

## [v0.07100] — 2026-04-14

### Added
- **Player Stats page.** New `/stats.php` with personal stats card (games, wins, losses, win rate, best finish, avg finish, weighted score) and a leaderboard table ranked by avg score. Accessible via "Stats" nav link for logged-in users.
- **Weighted scoring.** Tournament placement scored by `(field_size - finish) / field_size × 80 + 20`. Everyone who plays earns at least 20 points. Winning a bigger field scores higher than winning a small one.
- **Finish Game button.** Check-in settings panel now has a "Finish Game" button to mark sessions complete (with Reopen option). Only finished tournaments count toward stats.

### Changed
- **Stats: tournaments only.** Cash games excluded from stats. Only registered users shown (walk-in guests excluded from leaderboard but still count toward field size for scoring).

---

## [v0.07000] — 2026-04-13

### Added
- **Winner overlay.** When only 1 player remains in a tournament, the timer auto-pauses and a full-screen overlay shows: bouncing trophy, "WINNER", player name, and 1st place payout. Dismissable with Close button. Only triggers once per session.

---

## [v0.06900] — 2026-04-13

### Added
- **Swipe gestures for timer.** Swipe left from right edge opens player panel, swipe right closes it. Swipe up from bottom edge shows toolbar, swipe down hides it. Visual hint indicators (subtle grey pills) on touch devices. Tap-to-toggle removed for bottom toolbar.
- **Compact mobile check-in header.** Action buttons (Settings, Timer, QR, Payout) are icon-only on mobile with tooltips. Single-row layout.

### Fixed
- **Timer timezone bug.** SQLite `datetime('now')` stores UTC but PHP parsed it in the site timezone, causing ~5 hours of phantom elapsed time. Timer would jump to 314:59 on start. Fixed by appending UTC to strtotime.
- **Payouts not updating on buyin change.** `update_config` now returns fresh payouts in the response so the payout card reflects the new pool immediately.
- **Timer safety clamp.** `time_remaining_seconds` capped at 86400 (24h) to prevent runaway values.

### Changed
- **Swipe hints on all touch devices.** Uses `pointer: coarse` detection instead of screen width — tablets now see swipe hints.

---

## [v0.06800] — 2026-04-13

### Added
- **Multi-method registration verification.** Users choose Email, SMS, or WhatsApp at signup. SMS/WhatsApp sends a 6-digit code (10 min expiry, 5 attempt limit). Sets `preferred_contact` based on choice. New `phone_verifications` table and `verification_method` column on users.
- **SMS/WhatsApp consent checkbox.** Required when SMS or WhatsApp verification is selected. Backend + JS enforcement.
- **Email notification logging.** All `send_email()` calls now logged to `sms_log` table with provider='email' for unified notification history.
- **Delete account.** Users can delete their own account from My Settings by typing DELETE. Cleans up invites, poker players, tokens. Last admin protection.
- **Branding on login/register.** Header banner displayed at top of login and register cards, clickable to home page.

### Changed
- **Tighter mobile card layout.** Removed vertical centering, reduced all padding/margins/font sizes on mobile for login, register, and settings pages.

---

## [v0.06700] — 2026-04-13

### Added
- **TV Display Mode.** New `?display=1` parameter on the remote timer link creates a TV-optimized view: no controls, no toolbar, giant fonts (blinds up to 12rem, clock up to 45vh), pure black background. Accessible via the new 📺 TV button in the timer toolbar. Opens in a new tab — send to a TV browser, Chromecast tab cast, or AirPlay.
- **Cast receiver page.** `cast_receiver.php` ready for future Chromecast native casting (receiver registered, sender code removed pending test device setup).

---

## [v0.06600] — 2026-04-12

### Added
- **WhatsApp commands match SMS.** WhatsApp webhook now supports all SMS commands: EVENTS/STATUS (list upcoming events with RSVP status), START (re-enable notifications), STOP, HELP, direct format ("1 yes", "all no"), and multi-event numbered list selection.

### Fixed
- **Timezone-aware event queries in webhooks.** Both SMS and WhatsApp webhooks now use the configured timezone for "today" instead of UTC. Events dated today no longer disappear early when UTC rolls past midnight.
- **WhatsApp NOWEB LID phone extraction.** NOWEB engine uses LID format for sender ID — webhook now extracts the real phone from `remoteJidAlt`.
- **WhatsApp duplicate webhook dedup.** WAHA fires duplicate webhooks — now deduped via DB lock on event ID. Group messages and outbound echoes filtered out.
- **Cancellation notifications skip past events.** Deleting past events no longer sends cancellation notifications.
- **Phone verification UI removed.** Removed the verified/unverified badges and SMS verification flow from user settings. Phone field retained for WhatsApp/SMS routing.
- **Preferred contact 'both' now saves correctly** in user settings.

---

## [v0.06500] — 2026-04-11

### Changed
- **Ante displayed inline with blinds.** Timer now shows small / big / ante all on one line with a gold "ANTE" label centered under the ante value. Next level preview uses the same format.
- **Larger blinds and next level text.** Current blinds bumped to max 10rem, next level to 2.5rem with bolder weight and brighter color.

---

## [v0.06400] — 2026-04-11

### Added
- **Timer sound presets.** End, start, and warning sounds each have their own dropdown with built-in beep options: Buzzer, Chime, Casino Bell, Air Horn, Countdown, Double Beep, 3 Descending Beeps (end/start); Tick-Tick, Pulse, Chirp, Gentle Tone (warning). All generated via Web Audio API — no files needed.
- **Separate end/start level sounds.** End level and start level sounds now have independent dropdowns, uploads, and preview buttons. New `start_sound` column in timer_state.
- **WAHA NOWEB engine.** Switched from WEBJS (Chromium-based, ~150 MB) to NOWEB (WebSocket, ~80 MB) for lower resource usage.

### Changed
- **Default end level sound** is now 5 beeps over 3 seconds (880 Hz). Old default (3 descending beeps) moved to a preset option.
- **Start level tone** frequency changed from 1000 Hz to 880 Hz to match.
- **Ante display** more visible — amber/gold color, bold, larger font.
- **Timer eliminate** no longer prompts for finish position — auto-assigns next available.

---

## [v0.06300] — 2026-04-11

### Changed
- **Timer mobile: unified floating toolbar.** Mobile now uses the same floating glass toolbar as desktop. All controls (prev, start/pause, next, min+/-, resets, sound, fullscreen, levels, sounds, players) in one bar. Tap timer display to show/hide. Auto-hides after 4 seconds. Removed separate Prev/Start/Next row and grip-handle tray. Play button highlighted green/red on mobile.
- **Spacebar hotkey.** Pressing spacebar toggles play/pause on the timer (desktop).

---

## [v0.06200] — 2026-04-11

### Changed
- **Timer desktop controls: floating glass toolbar.** All controls (prev, start/pause, next, min+/-, level reset, timer reset, sound, fullscreen, levels, sounds, players) consolidated into a single-row floating toolbar pinned to the bottom center. Frosted glass effect with backdrop blur. Icon + small label per button, grouped with thin dividers. Auto-hides after 3 seconds of mouse inactivity, reappears instantly on mouse move. Mobile tray behavior unchanged.

---

## [v0.06100] — 2026-04-11

### Added
- **Walk-in autocomplete search.** The walk-in input in checkin.php now live-searches existing usernames as you type. Matches case-insensitively, excludes players already in the session, uses correct-case username from the DB. Click or Enter to select.
- **Multi-select and bulk actions.** Desktop list view has per-row checkboxes with select-all, and a bulk action bar for: Check In, Buy In, Eliminate, Approve, Remove. Bar is always visible, dimmed when nothing selected.
- **Table count on button.** The "+ Table" button now shows the current table count (e.g., "Tables: 2 +").
- **Segmented view toggle.** List/Table view switcher is now a joined two-button segment control with active/inactive states.
- **Toolbar visual separator.** Thin divider line between walk-in controls and filter/view controls.

### Fixed
- **New sessions default to 8 seats.** `init_session` now explicitly sets `seats_per_table = 8`.
- **Walk-in duplicate players.** Re-adding a removed player re-activates them instead of creating a duplicate. Uses correct-case username from user account.
- **Walk-in case mismatch.** Typing "bryce" now correctly selects "Bryce" when Enter is pressed.

---

## [v0.06000] — 2026-04-11

### Added
- **Self-hosted WhatsApp via WAHA.** Replaced Meta WhatsApp Business API with WAHA (WhatsApp HTTP API), a self-hosted Docker container. No more Meta Business verification, API keys, templates, or monthly fees. Admin scans a QR code from the WhatsApp tab in Site Settings to link a WhatsApp account. Messages sent via REST calls to the local WAHA container.
- **WAHA Docker service.** New `waha` service in docker-compose.yml with session persistence volume. Runs alongside the gamenight container on the internal Docker network.
- **WhatsApp admin tab redesigned.** New connection panel with Start/Stop session, live QR code display (auto-refreshes every 15s), connection status indicator, and step-by-step scan instructions. Test send panel retained.
- **Inbound WhatsApp RSVP via WAHA webhooks.** wa_webhook.php updated to parse WAHA's simpler webhook format. All RSVP keyword processing unchanged.

### Removed
- Meta WhatsApp Business API integration (Phone Number ID, Access Token, Verify Token, Templates, Template Language fields). Replaced entirely by WAHA.

---

## [v0.05602] — 2026-04-11

### Changed
- **Add-on stores dollar amount instead of count.** Add-ons now store cents directly per player. Check-in and timer player panel show a checkbox + editable dollar field. Checking the box populates with the default add-on amount; the field is editable for custom amounts. Pool calc uses the stored amount directly.
- **Timer player panel rebuy/add-on labels.** Rebuys show "RE" label, add-ons show "AO" label for identification on mobile.
- **Mobile check-in cards: check-in/buy-in on summary row.** CI and BI checkboxes are now on the card header (always visible) with 22px tap targets. Expanding the card shows rebuys, add-ons, and other actions.
- **Mobile expand stays open.** Toggling settings no longer collapses the expanded player card.
- **Fixed-width status badges.** Status tags use consistent width to prevent layout shift.
- **Pending players show approve/deny on card.** Mobile cards for pending players show Approve and Deny buttons directly on the summary row instead of a "Pending" badge.

---

## [v0.05601] — 2026-04-10

### Fixed
- **Welcome post keeps coming back after deletion.** The seed welcome post was re-created on every page load when no posts existed. Now tracked via a `welcome_post_seeded` flag in site_settings — once seeded (or deleted), it never returns.

---

## [v0.05600] — 2026-04-10

### Added
- **SaaS-style marketing landing page.** New toggleable landing page for non-logged-in visitors showcasing all GameNight features: event scheduling, RSVP management, tournament tools, walk-in registration, host approval, announcements, multi-table management, and smart notifications. Controlled via Admin Settings → General → "Show Landing Page" toggle. Landing page content lives in a separate `_landing.php` partial.
- **SaaS mode hides nav and calendar for guests.** When landing page mode is on, non-logged-in visitors see no navigation bar (just the landing page with built-in Sign In / Get Started buttons). Direct access to `/calendar.php` redirects guests to the landing page. Logged-in users are unaffected.

---

## [v0.05505] — 2026-04-10

### Added
- **Waiting list notification to walk-in user.** When a QR walk-in is put on the waiting list (approval required), the walk-in user now receives an SMS/email confirmation: "You're on the waiting list for Event. The host will approve your registration shortly." Existing users get notified via their preferred contact; new users get an email.

---

## [v0.05504] — 2026-04-10

### Added
- **Approve/deny in check-in page.** Pending players now show a yellow "Pending" badge and Approve/Deny buttons in checkin.php (list view, table view, and mobile cards). Check-in and buy-in controls are disabled until the player is approved.
- **Table and seat info in approval notifications.** SMS and email approval notifications now include the player's assigned table and seat number for poker events.

### Fixed
- **QR walk-ins not appearing in check-in.** Pending invitees are now synced into the poker roster so the host can see and approve them from checkin.php.
- **Removed invitees staying in check-in.** Players removed from the calendar event are now soft-removed from the poker roster on the next sync.
- **Check-in/buy-in bypassing approval gate.** Backend now rejects check-in and buy-in actions for pending players.
- **Manual +Add Walk-in now creates event_invites row.** Host-added walk-ins are auto-approved and properly tracked in event_invites.

---

## [v0.05503] — 2026-04-10

### Added
- **Random seat assignment.** Players get a random open seat (1 through seats_per_table) when checking in, buying in, walking in, or being moved to a table. Over-capacity tables auto-expand with an extra seat. New `pick_random_seat()` helper replaces all sequential assignment.
- **Seat and table columns in check-in list view.** Table (editable) and Seat columns now always visible. Table view shows seat number before player name, sorted by seat. Mobile cards show table and seat info.

### Fixed
- **Removed players reappearing on re-RSVP.** Players who were removed from a poker session and later RSVP yes again now correctly reappear in the check-in roster.

### Changed
- **Default seats per table changed from 9 to 8.** New sessions default to 8 seats. Existing sessions unchanged.

---

## [v0.05502] — 2026-04-10

### Changed
- **Blind structure export/import switched to CSV.** Export now produces a `.csv` file with columns: Level, Small Blind, Big Blind, Ante, Minutes, Type. Import reads CSV (auto-skips header row). JSON format dropped.

---

## [v0.05501] — 2026-04-10

### Fixed
- **Blind structure export empty.** Exported JSON only contained the preset name, missing all blind levels. `collectLevelsFromTable()` updates the global `LEVELS` array in place but returns `undefined` — the export was using the return value instead of `LEVELS`.

---

## [v0.05500] — 2026-04-10

### Added
- **Personal vs global blind presets.** Admins can save blind presets as "Global" (visible to all users) or "Personal" (private). Regular users always save personal presets. The preset dropdown is now organized into three `<optgroup>` sections: Default, Global Presets, and My Presets. Admins can edit the default preset in place (non-admins get a personal copy), create new global presets, and promote any preset to be the new default via a "Set Default" button. Delete is blocked on the default preset, and restricted to admins for global presets.

---

## [v0.05400] — 2026-04-09

### Added
- **Per-event "Require host approval" toggle.** New event editor switch that gates self-initiated signups (walk-in QR registrations and the public Sign Up button) into a pending queue the host can approve or deny. Creator/manager invites continue to auto-approve. Pending signups don't get reminders, don't appear in the poker player roster, can't RSVP via email/SMS/WhatsApp, and don't get assigned a poker table until approved. Hosts get notified via their preferred contact when a new request arrives, and a Pending Approval section appears in the event view with Approve/Deny buttons. Denied users get a soft-deny (silent waiting-list response on retry, no rejection notice). Toggling approval off auto-approves any remaining pending rows.

---

## [v0.05301] — 2026-04-09

### Fixed
- **"Remember me" actually works now.** Previously the checkbox only extended the session cookie, but PHP's server-side session would still get garbage-collected after ~24 min of idle, and browser restarts logged users out regardless. Now issues a proper 30-day persistent auth token (hashed in DB, rotated on every use for theft detection) that silently re-establishes the session across idle periods and browser restarts. Cleared on sign-out.
- **Idle session timeout.** Raised server-side session lifetime to 8 hours so logged-in users sitting idle on a page no longer get kicked out when they return.

---

## [v0.05300] — 2026-04-08

### Added
- **Database backup & restore.** New "Backup" tab in Admin Settings. Download a full SQLite database backup as a timestamped `.db` file. Restore from a previously downloaded backup with validation (checks for valid SQLite with users table). Auto-saves current database before restore as a safety copy. All actions logged.

---

## [v0.05200] — 2026-04-08

### Added
- **Guest timer access.** The tournament timer no longer requires login. Guests can use the timer with full playback controls (start/stop, skip levels, ±min, reset) and edit blind levels in-session. Nothing persists after the browser session ends.
- **Blind structure export.** Logged-in users can export the current blind structure as a JSON file from the levels editor.
- **Blind structure import.** Logged-in users can import a JSON blind structure file, review the levels, and save.
- **Timer in nav for all visitors.** The "Tournament Timer" link now appears in the hamburger menu for non-logged-in users alongside Login/Sign Up.

### Changed
- **Guest restrictions.** Guests see a prompt to create an account when trying to save presets, export/import blinds, or use custom sounds. QR remote sharing and player panel are hidden for guests.

### Fixed
- **Guest timer controls hidden.** The poll response was setting `can_control = false` for guest timers because the user wasn't authenticated. Now guest timers (`user_id = 0`) always return `can_control = true`.

---

## [v0.05100] — 2026-04-08

### Changed
- **Clean money display.** Cash game amounts show `$20` instead of `$20.00`. Cents only shown when non-zero (e.g. `$20.50`). Applied to pool totals, cash-in/out, profit, and compact stats bar.
- **Compact mobile stats bar.** On mobile, 6 large stat boxes replaced with a single inline bar: `Players: 12 | In: 10 | Playing: 8 | Pool: $200`.
- **Scrollable mobile player list.** Player cards on mobile now scroll independently within the viewport instead of pushing the page infinitely.
- **Sidebar hidden on mobile.** Pool Summary and Payout cards no longer appear below the player list on mobile (info already in compact stats).

### Fixed
- **Banner flash on page load.** The site banner image briefly flashed at full size before CSS loaded. Fixed with inline size constraints and early CSS.

---

## [v0.05000] — 2026-04-08

### Added
- **Mobile check-in cards.** On screens ≤768px, the check-in player table is replaced with stacked player cards. Tap a card to expand and access all controls (check-in, buy-in, rebuys, add-ons, table, RSVP, eliminate, notes, remove). Desktop layout unchanged.
- **Timer player management panel.** Slide-out panel on the timer page for hosts and managers. Manage rebuys, add-ons, eliminations, and buy-ins without leaving the timer screen. Available for both host and remote managers.
- **Timer swipe-up controls tray.** Primary controls (Prev, Play, Next) always visible. Secondary controls (±Min, Reset, Sound, Fullscreen, Levels, Sounds, Players) in a slide-up tray — tap the handle bar to reveal. Desktop shows all controls by default.

### Fixed
- **Event managers access denied.** Managers could not add walk-ins, edit settings, update payouts, break up tables, or rebalance tables — only the event creator and admins could. Added `is_owner_or_manager()` helper and applied to all 6 affected check-in actions.
- **Timer Players button missing on remote.** Remote managers couldn't see the Players button because `$event` wasn't loaded in the remote viewer code path.
- **QR code overlapping controls on mobile.** QR code now hidden on screens ≤500px to prevent overlap with timer buttons.
- **Fullscreen button hidden on iOS.** iPhones don't support the Fullscreen API — button is now hidden on iOS devices.
- **Event edit notification spam removed.** Editing an event no longer sends "Event updated" notifications to all existing invitees. Only new invitees get notified. Use the explicit "Notify invitees" checkbox for update notifications.

---

## [v0.04700] — 2026-04-08

### Added
- **Phone number verification via Surge.** Users can verify their phone number from the Settings page using Surge's verification API. A 6-digit SMS code is sent and verified in-app. Phone field shows green "Verified" or orange "Unverified" badge. Verification resets automatically when the phone number is changed. Only available when Surge is the configured SMS provider.

---

## [v0.04600] — 2026-04-08

### Added
- **Self-hosted URL shortener.** Replaced TinyURL dependency with a built-in shortener. Short URLs like `https://yourdomain.com/s/abc123` are stored in the database and redirect via 301 — no preview pages, no third-party dependencies, no rate limits. Reuses existing codes for the same target URL.

### Removed
- **TinyURL API dependency.** Third-party URL shorteners were unreliable (is.gd blocked by Cloudflare, TinyURL showing preview pages). The self-hosted shortener replaces all external shortener calls.

---

## [v0.04500] — 2026-04-08

### Added
- **SMS HELP command.** Text HELP (or H, ?, COMMANDS) to see all available SMS commands.
- **SMS EVENTS/STATUS command.** Text EVENTS (or LIST, E, STATUS, S) to see upcoming events with RSVP status.
- **SMS STOP/START commands.** Text STOP to opt out of SMS notifications (switches to email-only). Text START to re-enable SMS.
- **SMS multi-event RSVP.** When a user has multiple upcoming event invites, replying YES/NO/MAYBE shows a numbered list. Reply with a number to select, or ALL to update all events.
- **SMS direct "N RSVP" format.** Reply "1 yes", "2 no", "3 maybe", or "all yes" to RSVP to a specific event by number in a single message, skipping the two-step flow.
- **SMS opt-out compliance.** All outbound SMS messages now append "Reply STOP to unsubscribe, HELP for commands." for carrier compliance.
- **Event deletion notifications.** Deleting an event now notifies all invitees via their preferred contact method (SMS/email/both) before deletion. Previously invitees received no notification.
- **Occurrence deletion notifications.** Removing a single occurrence from a recurring event now notifies RSVPed invitees in `calendar.php` (was already working in `calendar_dl.php`).
- **SMS invite reply hint.** Invite SMS now includes "Reply YES, NO, or MAYBE to RSVP" so users know they can reply directly.
- **SMS providers marked as untested.** Twilio, Plivo, Telnyx, and Vonage labeled "(untested)" in provider dropdown since only Surge has been verified.

---

## [v0.04400] — 2026-04-08

### Added
- **Surge SMS provider.** Added Surge (surge.app) as an SMS provider option alongside Twilio, Plivo, Telnyx, and Vonage. Supports sending, receiving (webhook), and HMAC signature verification via `Surge-Signature` header. Includes webhook signing secret field with encrypted storage.
- **Surge webhook signature verification.** Inbound Surge webhooks are verified using HMAC-SHA256 with a 5-minute timestamp window to prevent forged requests.

### Fixed
- **SMS credentials not saving.** The SMS credentials form rendered hidden input fields for all providers with duplicate `name` attributes. The browser submitted the last (empty) field, overwriting entered values. Fixed by adding `disabled` attribute to hidden provider fields.
- **Event notifications email-only.** Creating or editing events in `calendar.php` only sent email notifications, ignoring the user's preferred contact method (SMS, WhatsApp, both). Now routes through `send_invite_notification()` and `send_notification()` which respect user preferences.
- **Event invite URL missing date parameter.** SMS/email invite links were missing `&date=` causing the calendar to open on the month view instead of directly to the event. Fixed in both `calendar.php` invite and update notification URLs.
- **URL shortener broken.** is.gd was blocking server-side requests with Cloudflare. Switched to TinyURL API which works reliably from servers.
- **Curl error handling in SMS providers.** All SMS provider functions (Twilio, Plivo, Telnyx, Vonage, Surge) now catch and report curl connection errors (SSL, DNS, timeout) instead of failing silently.
- **Dead `sms_auth_token` removed from encrypted settings.** Cleaned up unused entry in `ENCRYPTED_SETTINGS`.

---

## [v0.04301] — 2026-04-08

### Fixed
- **Single table auto-assign.** Players in a 1-table game are now assigned to table 1 instead of showing as unassigned in table view. Balance Tables also works with a single table.
- **Eliminate without buy-in blocked.** Attempting to eliminate a player who hasn't bought in now shows a warning instead of setting finish position 0.

---

## [v0.04300] — 2026-04-08

### Security
- **Event invites IDOR fixed.** The event invites endpoint now verifies the user is the event owner, a manager, or an admin before returning invite data. Previously any logged-in user could view any event's invite list.
- **JSON XSS prevention.** All `json_encode()` calls embedded in `<script>` tags now use `JSON_HEX_TAG` flag to prevent `</script>` breakout attacks.
- **Vonage GET parameter injection blocked.** SMS webhook no longer accepts GET parameters for Vonage provider, preventing URL-based CSRF-like attacks via image tags or links.
- **Event action ownership checks hardened.** `cancel_series`, `uncancel_series`, and `remove_invitee` calendar actions now require event ownership or manager role (defense-in-depth).
- **Phone number enumeration prevented.** SMS and WhatsApp webhooks now return a generic "Thanks for your message" for unrecognized phone numbers instead of revealing registration status.
- **Race condition protection.** Check-in and buy-in toggle operations wrapped in database transactions to prevent concurrent double-toggle.
- **Log injection prevention.** Activity log functions now strip control characters (newlines, tabs, null bytes) from action strings to prevent log forging.
- **Admin help text escaped.** SMS provider help text in admin settings now properly HTML-escaped.

---

## [v0.04200] — 2026-04-07

### Security
- **Rate limiting on password reset.** Max 3 requests per IP per hour. Silently drops excess requests without revealing rate limiting to attackers.
- **Rate limiting on email verification resend.** Max 3 requests per IP per hour. Prevents email spam attacks.
- **Rate limiting on registration.** Max 5 registration attempts per IP per hour.
- **Cron token empty-string bypass fixed.** Empty cron_token or empty provided token now both rejected, preventing unauthenticated cron execution.
- **Password policy consistency.** Registration now requires 12 characters minimum, matching password reset and settings (was 8).
- **Walk-in cookies HttpOnly.** New user walk-in cookies now set `httponly=true`, preventing JavaScript access. Previously only existing user path was protected.
- **Walk-in rate limit corrected.** Fixed from 20 to 5 attempts per IP per hour (code didn't match documented limit).
- **CSP form-action directive.** Added `form-action 'self'` to Content-Security-Policy to prevent form hijacking.
- **Password reset token moved to POST.** Reset token now submitted via hidden form field instead of URL query string, removing exposure from browser history, server logs, and referrer headers.
- **MIME detection modernized.** Replaced deprecated `mime_content_type()` with `finfo(FILEINFO_MIME_TYPE)` in banner upload handlers.
- **Walk-in token entropy increased.** Increased from 128-bit (16 bytes) to 256-bit (32 bytes), matching CSRF and email verification token strength.

---

## [v0.04100] — 2026-04-07

### Added
- **Admin user account settings.** User edit page now includes Email Verified toggle, Must Change Password toggle, My Events Past Days, and My Events Future Days fields under a new "Account Settings" section.
- **Email verification status in account info.** User edit page Account Info table shows verified/unverified status with color indicator.

### Fixed
- **Cash game manual cash-in status.** Manually entering a Total In value and pressing Enter now correctly marks the player as bought in and checked in, matching the + button behavior.
- **Cash-in Enter key advances focus.** Pressing Enter on a cash-in field saves the value and moves focus to the next player's input for quick entry.

---

## [v0.04000] — 2026-04-07

### Added
- **Table management system.** Full table management for poker tournaments and cash games with auto-assignment, table view, and rebalancing.
- **Auto-assign tables.** Players are automatically assigned to the table with fewest players when checked in, bought in, or added as walk-in. Respects seats-per-table limit. Configurable on/off in game settings.
- **Seats per table setting.** Configurable max seats per table (default 9). Used by auto-assign and balance logic to cap table sizes.
- **Table View mode.** Toggle between list view and table view in check-in dashboard. Table view shows players grouped in cards per table with player counts and seat capacity (e.g., 7/9).
- **Move players between tables.** "Move to..." dropdown per player in table view to move individual players to another table.
- **Balance Tables with button protection.** Modal to select the Button player at each table before balancing. Button, Small Blind, and Big Blind are protected and never moved. Only rebalances when table sizes differ by more than 1.
- **Break Up Table.** Button on each table card to eliminate a table — distributes its players to remaining tables, reduces table count, and renumbers remaining tables.
- **Add Table.** "+ Table" button to add a new empty table on the fly.
- **Walk-up table assignment.** QR walk-up registration now shows "Your Table: Table X" on the success screen when a poker session exists and auto-assign is enabled.
- **Eliminate in cash games.** Cash game players can now be eliminated (marked out) without being removed from the event, useful for table balancing.
- **Eliminate in table view.** Red ✕ button per player in table view to eliminate, with Undo option for eliminated players.

### Fixed
- **Walk-in Enter key.** Walk-in name field now submits on Enter key press.
- **Filter buttons not highlighting.** All/RSVP Yes/Playing/Out filter buttons now visually update immediately when clicked without requiring a page refresh.
- **Filter works in table view.** Filters now apply in table view mode, not just list view.
- **Table view auto-refresh.** Table view now updates in real time via polling, same as list view.
- **Table count display.** Fixed fencepost error in table view player count.
- **Table rebalance after reducing tables.** When num_tables is decreased in settings, displaced players are automatically rebalanced across remaining tables.
- **Break up to 1 table.** Breaking up a table when only 2 exist now correctly assigns all players to the remaining table.
- **Eliminated players excluded from rebalance.** Eliminated players are no longer picked up during table break-up or rebalancing.

---

## [v0.03500] — 2026-04-07

### Added
- **Payout Calculator (ICM, Standard, Chip Chop).** New "Payout Calc" button on tournament check-in page opens a modal with chip count entry for remaining players. Three split methods: ICM (Malmuth-Harville model), Standard (weighted payout structure), and Chip Chop (proportional to chip stacks).
- **Weighted auto-split for payout structure.** Auto Split button in settings now uses standard tournament weighting (e.g., 3 places = 50/30/20) instead of equal split. Configurable for 1-10+ places.
- **Login brute force protection.** 5 failed login attempts per IP per 15 minutes. Shows "Too many failed attempts" message. Constant-time password verification prevents timing attacks.
- **Credential encryption at rest.** SMTP passwords, SMS tokens, and WhatsApp tokens are AES-256-CBC encrypted in the database. Auto-generated encryption key stored in `/var/db/.app_secret`. Backward-compatible with existing plaintext values.
- **HSTS header.** `Strict-Transport-Security` sent when accessed over HTTPS.

### Fixed
- **Session cookie secure flag.** Now dynamically set based on `X-Forwarded-Proto` header so cookies are secure when behind a reverse proxy with HTTPS.
- **Walk-in cookies now HttpOnly.** Prevents XSS access to remembered name/email cookies.

### Removed
- **Start Game / End Game buttons** from check-in page. The status lifecycle (setup/active/finished) was confusing and didn't affect functionality.

---

## [v0.03401] — 2026-04-07

### Added
- **Prize payout display on timer.** Tournament timers show the payout structure (1st, 2nd, 3rd, etc.) in the upper-right corner with dollar amounts calculated from the live pool. Updates dynamically as rebuys/add-ons change the pool. Hidden for cash games and standalone timers.

---

## [v0.03400] — 2026-04-07

### Added
- **Standalone QR registration display page** (`walkin_display.php`). Full-screen dark-themed page showing the walk-up QR code, event name, date, and "Scan to register" instructions. Designed for an iPad or tablet at a registration table. Includes copy link, regenerate QR, fullscreen, and wake lock.
- **"Open on separate screen" button** in calendar QR modal. Opens the standalone QR display in a new window/tab for use on a separate device.
- **QR Registration button on check-in page.** Opens the standalone QR display for the current event directly from the poker check-in dashboard.
- **Check-in auto-refresh.** Player list and pool stats poll every 10 seconds. New walk-up registrations appear automatically without manual page refresh.
- **Remember me on login.** Checkbox on the login page extends the session cookie to 30 days.
- **Walk-up form remembers returning users.** Name and email saved in a 30-day cookie after registration. Auto-fills on next QR scan.
- **SMS consent language (Telnyx compliance).** Registration and settings pages show opt-in checkbox/text for SMS messages with frequency, data rates, STOP/HELP, and Privacy Policy link.
- **Privacy Policy: SMS section.** New Section 3 covers SMS opt-in, message types, frequency, data rates, opt-out (STOP), help (HELP), and Telnyx as provider.

### Fixed
- **HTTPS URLs behind proxy.** `get_site_url()` now checks `X-Forwarded-Proto` header so all generated URLs (QR codes, email verification, walkin links) use `https://` when behind Nginx Proxy Manager.
- **Walk-up rate limiter using proxy IP.** Changed from `$_SERVER['REMOTE_ADDR']` to `get_client_ip()` so each visitor gets their own rate limit, not shared across all users behind the proxy. Limit raised to 20/hour.
- **Removed players re-appearing on check-in.** Players are now soft-deleted (`removed=1`) instead of hard-deleted. `sync_invitees` skips removed players. `get_players` and `calc_pool` exclude them.
- **Remove player also removes from event.** Removing a player from the check-in page now also deletes their `event_invites` row, fully removing them from the event.

### Database
- New column `poker_players.removed INTEGER NOT NULL DEFAULT 0` — soft-delete flag for removed players.

---

## [v0.03300] — 2026-04-07

### Added
- **Walk-up QR registration.** Admins can now generate a QR code for any event via the "📱 QR" button in the event view modal. Walk-up attendees scan the code on their phone, fill out a short form (name, email, optional phone), and are registered. If the email matches an existing account they are RSVPed Yes; otherwise a soft account is created, they are RSVPed Yes, and a verification email is sent so they can set a password later.
- **Walk-up registration page (`/walkin.php`).** Public, no login required. Validates the per-event secret token, shows event details at the top, rate-limits to 5 submissions per IP per hour, and handles duplicate-username collisions by appending a numeric suffix.
- **Walk-up token regeneration.** Admins can invalidate the current walk-up link from the event edit modal with "Regenerate walk-up link." A new token is generated instantly via AJAX.
- **Copy link in QR modal.** The QR modal includes the full URL and a "Copy link" button for sharing digitally.

### Database
- New column `events.walkin_token TEXT` — per-event secret token for the walk-up registration URL.
- New table `walkin_attempts` — IP-based rate limiting for walk-up registration form submissions.

---

## [v0.03200] — 2026-04-06

### Added
- **Recurring event cancellation.** New `cancelled_from` column on events. Admin edit modal shows "Cancel future occurrences" button (prompts for effective date) and "Uncancel series" button. All base invitees receive a cancellation notification. Occurrence expansion stops at the cancellation date.
- **Cancellation notification when skipping an occurrence.** When an admin skips a specific occurrence (deletes it from the calendar), invitees who RSVPed Yes or Maybe for that date automatically receive a cancellation email/SMS/WhatsApp.
- **Series cancellation without deletion.** Cancelling future occurrences marks the series as cancelled from a date forward; it does not delete the event or past occurrences. History is preserved.
- **Cron reminder system.** New token-protected endpoint (`/cron.php`) sends 2-day-ahead and 12-hour-ahead reminders for upcoming event occurrences. `CRON_TOKEN` is configurable in Admin → Settings → Email tab with a Generate button and ready-to-copy cron command. Reminders are deduplicated via a new `event_notifications_sent` table — no double-sends.
- **Mid-series invite management.** New invitees added to a recurring event receive `valid_from = today` so they are not retroactively included in past occurrences. Each invitee row in the edit modal has a "✕ All" button that removes them from all future occurrences and sends a removal notification.
- **RSVP cutoff.** Non-admin users cannot change their RSVP within 1 hour of the event start time. The RSVP select is disabled and a "RSVP is locked — event starts soon" message is shown. Admins are exempt. Cutoff enforced server-side (`{ok:false, locked:true}`) and client-side.
- **Per-occurrence RSVP overrides.** When a user RSVPs for a specific occurrence of a recurring event, an occurrence-specific row is stored. That override takes precedence over the base row, allowing per-date RSVP tracking without affecting the rest of the series.

### Fixed
- **Timer remote viewer frozen on Android.** QR-scan visitors are unauthenticated. The polling path using `?session_id=` requires login and was returning `{ok:false}` silently, freezing the display after initial PHP render. Remote viewers now always poll via the public `?key=` endpoint (no auth required), regardless of whether `SESSION_ID` is set.
- **Timer resync after Android tab backgrounding.** Added an immediate `pollState()` call on `visibilitychange → visible` so the timer resyncs as soon as the user returns to the tab after Android throttled or suspended it.
- **Cron function availability.** `build_event_by_date()` and `load_exceptions()` were defined only in `calendar_dl.php` but called from `cron.php`, causing fatal errors at runtime. Both functions moved to `db.php` which is already included by all consumers.

### Database
- New column `events.cancelled_from TEXT` — date from which future occurrences are suppressed.
- New column `event_invites.valid_from TEXT` — occurrence date from which a mid-series invitee is included (NULL = from series start).
- New table `event_notifications_sent` — deduplicates cron reminders: `(event_id, occurrence_date, user_identifier, notification_type)` UNIQUE constraint.

---

## [v0.03101] — 2026-04-06

### Fixed
- **Level editor: stale values when switching presets.** Loading preset A then B then A again showed B's empty fields in A's rows. `collectLevelsFromTable()` was reading old DOM inputs back into the freshly loaded `LEVELS` array. Now skips DOM collection when loading a preset or reopening the panel, and clears the table body on close.

---

## [v0.03100] — 2026-04-06

### Added
- **Standalone timer mode.** Tournament Timer accessible from the hamburger menu for all logged-in users — no event required. Player count and pool are hidden when not linked to an event.
- **Drag-and-drop level reorder.** Blind structure editor rows can be dragged by the handle to rearrange order.
- **Insert level/break buttons.** Each row in the level editor has + (insert level) and clock (insert break) buttons to add entries at any position.
- **Reset Timer control.** New "Reset Timer" button (red) resets the entire timer back to Level 1 with confirmation dialog. Separate from "Reset Level" which only resets the current level's clock.
- **Grouped time adjust control.** Replaced separate +1 min / -1 min buttons with a compact `▼ Min ▲` group.
- **Sound settings panel.** Configurable warning alert timing (off/30s/60s/2min/5min), custom sound uploads for level change and warning (MP3, M4A, WAV, OGG, max 5 MB), and test buttons for each sound.
- **Split level change sounds.** End timer (3 descending beeps over 3 seconds), start timer (1-second long tone), and warning (5 quick beeps) — each distinct.
- **Audio unlock for mobile.** Silent buffer played on first user interaction to unlock AudioContext on iOS/Android so timer sounds work on remote viewers.
- **Fullscreen button for all users.** Moved from host-only to the always-visible toolbar so remote viewers on iPads/tablets can go fullscreen.
- **Tournament Timer link in hamburger menu.** All logged-in users can access the standalone timer from the navigation dropdown.

### Fixed
- **Remote controls not appearing.** Standalone timers returned `can_control: false` because access check required an event. Now checks timer ownership for standalone timers.
- **Remote controls disappearing on click.** Poll was overwriting `can_control` to false for standalone timers.
- **Level editor: delete removed wrong level.** `collectLevelsFromTable()` was called twice (once explicitly, once inside `renderLevelsTable`), corrupting array indices after splice.
- **Level editor: poll overwrote local edits.** Server poll no longer updates `LEVELS` while the editor panel is open.
- **Load preset button not working.** Loading a preset now fetches levels directly instead of relying on poll (which was blocked by the panel-open guard).
- **Preset dropdown resetting to default.** Editor now tracks `CURRENT_PRESET_ID` and selects the active preset when the dropdown is rebuilt.
- **Save Changes closing the panel.** Now shows a green "Saved!" confirmation for 2 seconds instead of closing.
- **X close button added** to the level editor panel header.
- **Input fields not selectable.** Moved `draggable` attribute from the table row to only the drag handle cell so number inputs can be clicked/selected normally.

### Changed
- **Larger timer clock.** Clock font uses `min(25vw, 35vh)` with no hard cap — scales to fill available space on any screen size.
- **Larger "Next" level text.** Bumped from `clamp(0.9rem, 2vw, 1.4rem)` to `clamp(1.1rem, 2.5vw, 1.8rem)`.

---

## [v0.03000] — 2026-04-05

### Added
- **Poker tournament timer.** Full-screen blind level timer (`/timer.php`) optimized for TV, projector, and mobile displays. Dark theme with large countdown clock, blind levels (SB/BB/ante), next level preview, live player count, and prize pool.
- **Remote viewer via QR code.** Host screen shows a scannable QR code in the bottom-right corner. Anyone can scan it to view the timer on their phone — no login required.
- **Remote control for managers.** Logged-in event managers and admins get play/pause, skip level, and time adjust controls on the remote viewer page.
- **Server-as-master architecture.** All clients (host and remote) poll the server for state. All controls send commands to a unified server API — no race conditions between host and remote.
- **Blind level editor.** Edit blind structure inline (SB, BB, ante, duration per level). Add/remove levels and breaks. Save and load custom named presets.
- **Default blind structure.** 20-level "Standard Tournament" preset seeded on first run (5,000 starting chips, 15-minute levels with two breaks).
- **Three-tone sound system.** End timer: 3 descending beeps over 3 seconds before level ends. Start timer: 1-second long tone when new level begins. Warning: 5 quick beeps at configurable time (30s, 60s, 2min, or 5min before level end).
- **Custom sound uploads.** Upload MP3, M4A, WAV, OGG, or WebM files (max 5 MB) for level change and warning sounds via the Sounds settings panel.
- **Wake Lock.** Screen stays on for mobile viewers using the Wake Lock API, activated on first tap.
- **Per-user sound mute.** Sound on/off toggle visible to all users (host and remote) so each device can independently mute.
- **Timer button on check-in page.** "Timer" link added to the poker check-in dashboard actions bar (tournaments only).

### Changed
- **Shared poker helpers.** Extracted `verify_event_access()`, `calc_pool()`, `sync_invitees()`, `get_players()`, and `get_payouts()` into `_poker_helpers.php` — shared by `checkin_dl.php` and `timer_dl.php`.
- **New vendor libraries.** `qrcode-generator` (QR codes) and `NoSleep.js` (screen wake) downloaded at container startup via `docker-entrypoint.sh`.

### Database
- New tables: `blind_presets`, `blind_preset_levels`, `timer_state`.
- New columns on `timer_state`: `commanded_at`, `warning_seconds`, `alarm_sound`, `warning_sound`.

---

## [v0.02109] — 2026-04-05

### Fixed
- **My Events time-aware sorting.** Events that ended today now correctly appear in "Past" instead of "Upcoming". Past events sorted by event date, not creation order.
- **My Events range filter.** Per-user "Past range" setting on My Events page and Account Settings. All future events always show in upcoming.
- **Calendar month view navigation.** Prev/next month and "Today" buttons now stay in month view instead of reverting to week view.
- **Calendar redirect after add.** Creating an event for a different month now navigates to that month so you can see it.
- **Cashout Enter key.** Pressing Enter in the cashout modal now submits the form. Input auto-focused on open.
- **Cashout cap at table money.** Cashout validated against money remaining on the table, client-side and server-side.
- **Calendar crash on clean install.** Added missing `occurrence_date` column migration for `event_invites`.

---

## [v0.02103] — 2026-04-05

### Fixed
- **Cashout cap at table money.** Cashout amount is now validated against money remaining on the table, both client-side and server-side. Prevents impossible accounting from over-cashing out.

---

## [v0.02102] — 2026-04-05

### Fixed
- **Cashout Enter key.** Pressing Enter in the cashout modal now submits the form. Input is auto-focused and selected when the modal opens.

---

## [v0.02101] — 2026-04-05

### Fixed
- **Calendar crash on clean install.** Adding the missing `occurrence_date` column migration for `event_invites` — creating an event on a fresh database caused calendar.php to fail with a SQL error.

---

## [v0.02100] — 2026-04-05

### Added
- **Documentation guide (DOCS.md).** Comprehensive user and admin documentation covering deployment, first-time setup, all admin settings, calendar/events, poker game management, posts, comments, notifications, cron setup, security, and troubleshooting.

---

## [v0.02000] — 2026-04-05

### Added
- **Welcome post on first deploy.** New installs now show a pinned "Welcome to Game Night!" post on the landing page with the header banner image, a tour of features (events, poker, RSVP, posts, settings), and a getting-started guide. The post is only seeded when the posts table is empty.

---

## [v0.01900] — 2026-04-05

### Added
- **Global notifications toggle.** New "Enable Notifications" setting in Admin > General. Defaults to off for new installs — admin must explicitly enable. When off, all email, SMS, and WhatsApp notifications are suppressed (invites, reminders, updates). Test messages from Email/SMS tabs still work.

### Changed
- **Calendar defaults to Week view.** Calendar now loads in week view by default. View toggle reordered to "Week | Month".
- **Sliding toggles in General settings.** All yes/no settings on the General tab now use sliding toggle switches instead of plain checkboxes.

---

## [v0.01800] — 2026-04-05

### Added
- **Per-event manager role.** Admins and event creators can grant invited users "Manager" access via a toggle in the invite pane. Managers can edit the event, manage invites, see contact details, and access the poker check-in page — without needing admin privileges.
- **Native time picker.** Replaced the 3-dropdown time selector (hour/minute/AM-PM) with a single `<input type="time">` on all devices. Triggers the native OS spinner on mobile and tablet.
- **Auto-fill current time.** New events default the time field to the current time instead of leaving it blank.

### Changed
- **iPad/tablet support.** All mobile touch optimizations now activate at 1024px (was 640px), covering iPads and tablets.
- **Touch-friendly calendar buttons.** Edit pencil and "+" add buttons are now always visible on touch devices (were hover-only and invisible on mobile).
- **Single-tap invite on mobile/tablet.** Invite and remove users with one tap (was double-click). Green "+" and red "x" indicators show on available and invited users.
- **Larger touch targets site-wide.** Buttons, inputs, selects, and checkboxes enlarged on poker check-in, admin settings, and my events pages. Input fonts bumped to 16px to prevent iOS auto-zoom.

---

## [v0.01700] — 2026-04-05

### Added
- **Mobile GUI overhaul.** Mobile devices now get an optimized experience with full-screen content, full-screen modal takeovers, and a collapsed nav bar by default.
- **Mobile detection in auth.php.** `$_is_mobile` flag is now available globally to all pages for conditional rendering.
- **Banner as nav collapse toggle.** The site logo (banner.png) replaces the ▲ arrow as the collapse/expand button in the nav bar. Header banner scales down to fit the collapsed bar.

### Changed
- **Full-screen modals on mobile.** All modals (calendar events, admin settings, posts, poker check-in) now take over the entire screen on mobile instead of floating as popout cards. Solid white background, no overlay bleed-through.
- **Edge-to-edge content on mobile.** Removed horizontal padding from all content wrappers (`.dash-wrap`, `.hero`, `.features`, `.page-layout`) at the 640px breakpoint so content fills the full screen width.
- **Edit event form mobile layout.** Header fields (color, title, date, time, duration) now wrap properly on small screens with larger touch targets (44px minimum). Invite panes stack vertically. Action buttons are full-width.
- **Nav bar positioning.** Collapse toggle moved to far left in both expanded and collapsed states. Nav bar padding reduced for tighter layout.

---

## [v0.01600] — 2026-04-05

### Added
- **Poker game check-in/management screen.** New full-screen dashboard (`/checkin.php`) for event creators and admins to manage poker game nights. Accessible via "Manage Game" button on poker events.
- **Tournament mode.** Track player check-ins, fixed buy-ins, rebuys, add-ons, table assignments, eliminations with finish positions, and percentage-based payout structure.
- **Cash game mode.** Flexible per-player buy-in amounts (add/subtract/edit directly), cash-out tracking, and automatic profit/loss calculation per player.
- **RSVP integration on check-in screen.** All event invitees are shown with their RSVP status. RSVP can be edited directly from the check-in page and syncs back to the event. RSVP=No rows are struck through with controls disabled.
- **Walk-in player support.** Add players not on the original invite list directly from the check-in screen.
- **Per-player notes.** Add notes to any player via a modal dialog.
- **Game lifecycle management.** Sessions progress through Setup → Active → Finished with status controls in the header.
- **Poker Game toggle on events.** Sliding yes/no toggle on event create/edit form (defaults to on). "Manage Game" button only appears on events marked as poker games.
- **Collapsible navigation bar.** Click the ▲ button to collapse the nav to just the hamburger menu, maximizing screen space. State persists across pages via localStorage.
- **RSVP Yes filter.** Filter button on check-in screen to show only players who RSVP'd yes.
- **Game settings panel.** Configure buy-in/rebuy/add-on amounts, rebuys allowed, max rebuys, add-ons allowed, starting chips, number of tables, and payout structure. Switch between tournament and cash game types.

### Fixed
- **Payout percentages can no longer exceed 100%.** Client-side and server-side validation blocks saving if payout structure totals over 100%.

### Changed
- **Sliding toggle switches replace checkboxes.** "Poker Game" and "Don't Notify" on the event form now use sliding yes/no toggles instead of plain checkboxes.

---

## [v0.01567] — 2026-04-04

### Fixed
- **Event creators could not open their own events.** `vDeleteId` was accessed without a null guard, crashing `viewEvent()` when the viewer was the event owner. Null guards added throughout.
- **RSVP owner dropdowns not showing on first open.** `renderInvitesPanel` was called before `window._calCanManage` was set, so static badges rendered instead of dropdowns on first open.
- **`ALLOW_MAYBE` undefined for non-owner users.** Moved to a global constant so `renderInvitesPanel` can use it for all users.
- **Color picker click listener crashed for non-creator users.** `eColorDotWrap` only exists in the edit modal; added null check to prevent TypeError on every click for users without event creation rights.

---

## [v0.01566] — 2026-04-04

### Added
- **Admins and event owners can edit invitee RSVP status.** The invite list in the event view modal now shows inline RSVP dropdowns (instead of static badges) for admins and the event creator. Changes save instantly. Regular invitees still see static badges.

### Changed
- **"Notify by email" checkbox inverted to "Don't Notify".** Notifications now send by default when creating or editing events. Check "Don't Notify" to suppress all emails. Editing an event now also notifies existing invitees by default (previously required opt-in).
- **Live RSVP refresh extended to all users.** The 4-second auto-refresh of the invite list in the event view modal previously only ran for admins; it now runs for all users including guests.

---

## [v0.01565] — 2026-04-04

### Changed
- **Revamped Add/Edit Event modal.** New layout with a header row (color circle with floating swatch picker, title, date defaulting to today, time dropdowns, duration dropdown), a dual-pane invite panel (All Users / Invited with double-click to add/remove), and a bottom row with description textarea on the left and Custom Invitee + Save/Cancel buttons on the right. Time entry replaced with Hour/Min/AM-PM dropdowns; duration replaced with presets (15 min – 8 hrs).

---

## [v0.01564] — 2026-04-04

### Fixed
- **Password reset links always appeared expired.** Token expiry was stored using PHP's local timezone (`date()`) but compared against SQLite's `datetime('now')` which is UTC, causing every reset link to fail immediately. Fixed by using `gmdate()` so the expiry is stored in UTC.

---

## [v0.01558] — 2026-04-04

### Added
- **Live RSVP updates for admins.** When an admin has an event modal open, the invite list now automatically refreshes every 4 seconds via a background poll (`event_invites_dl.php`). RSVP status changes made by any user are reflected in the admin's view without a page reload. Polling starts when the modal opens and stops when it closes.

---

## [v0.01557] — 2026-04-04

### Added
- **Event edit link in admin Events grid.** Each row in the Site Settings → Events spreadsheet now has a ▶ button that opens the event's calendar modal in a new tab, letting admins view and edit the full event details.

---

## [v0.01556] — 2026-04-03

### Removed
- **Recurring events.** Recurrence fields (`recurrence`, `recurrence_end`),
  the Recurrence dropdown in the create/edit modal, the per-occurrence invite
  scope toggle, the "Delete this date" occurrence button, and the "Recurring"
  badge on My Events have all been removed. All event queries now use a simple
  date-overlap filter. `build_event_by_date` simplified to single-pass;
  `load_exceptions` stubbed out. Admin Manage Events grid drops the Recurrence
  and Recur End columns.

---

## [v0.01555] — 2026-04-03

### Added
- **Manage Events tab in Site Settings.** Admins can now view and edit all
  events from a full-width spreadsheet-style grid under Site Settings → Events.
  Every cell is directly editable — title, dates, times, and recurrence — with
  changes saving automatically via AJAX and a "Saved" toast confirming each
  update. Recurrence is a dropdown select; all other fields are inline
  text/date/time inputs. The grid horizontally scrolls and breaks out of the
  960 px container so no columns are clipped. A "Manage Events" shortcut button
  was also added to the admin dashboard.

---

## [v0.01554] — 2026-04-03

### Added
- **Database Admin tool.** pla-ng (phpLiteAdmin fork, PHP 8 compatible) is now
  available at `/phpadmin/`. Access is gated behind the GameNight admin session —
  non-admins are redirected to login. The tool is downloaded at container startup
  by `docker-entrypoint.sh` and is not stored in the repo. A "Database Admin"
  button was added to the admin dashboard for quick access.

---

## [v0.01553] — 2026-04-03

### Fixed
- **Calendar 500 error — cannot redeclare `build_event_by_date`.** A prior commit
  moved `build_event_by_date` and `load_exceptions` into `db.php` but did not
  remove them from `calendar.php`. PHP fataled on the duplicate declaration for
  every calendar page request. Removed the duplicate definitions from `calendar.php`
  and `calendar_dl.php`; canonical home is now `db.php`.

---

## [v0.01552] — 2026-04-03

### Added
- **My Events page.** Logged-in users can view all events they are involved in
  (invited to or created) from a dedicated page, split into Upcoming and Past
  sections. Each card shows RSVP status, date/time, and a direct calendar link.
  "My Events" appears in both the desktop nav bar and the mobile hamburger menu.
- **Per-occurrence invites and RSVP for recurring events.** Each occurrence of a
  repeating event can now have its own invite list and RSVP statuses, independent
  of other occurrences in the series.
- **Maybe RSVP toggle.** Admins can enable or disable the "Maybe" response option
  site-wide from Site Settings → General. When disabled, Maybe is removed from RSVP
  buttons, invite emails, calendar dropdowns, and the one-click RSVP endpoint.
- **Failed login logging.** Failed login attempts are recorded in the activity log
  with `critical` severity and displayed in red in the admin Logs tab.
- **ROADMAP.md** added to the repository documenting planned milestones through v0.1.

### Fixed
- **Recurring event edit modal — save button clipped.** The edit modal uses
  `overflow:hidden` and `max-height:92vh` to stay within the viewport, but the
  `<form>` element was a plain block rather than a flex container. This meant
  `flex:1` on the body and `flex-shrink:0` on the footer had no effect — when a
  recurring event's extra scope UI added enough height, the footer with the Save
  button was pushed below the clipped edge and became unreachable. Fixed by making
  the form a flex column so the footer is always pinned at the bottom.
- **Upcoming events strip overflowing the page width.** The 7-column week grid used
  `grid-template-columns: repeat(7, 1fr)` but grid items default to
  `min-width: auto`, so the browser sized each column to the longest event title
  rather than the available 1fr share. Adding `min-width: 0` to `.wk-cell` lets
  the existing `text-overflow: ellipsis` take effect and keeps the strip within
  the page.
- Maybe RSVP option was missing from invite notification emails; now included when
  the Maybe toggle is enabled.

---

## [v0.015] — 2026-04-03

### Added
- **My Events page.** Logged-in users can now see all events they are involved in
  (invited to or created) from a single page. Events are split into Upcoming
  (chronological) and Past (reverse chronological) sections. Each card shows the
  RSVP status, Organizer/Recurring badges, date/time, and a direct link to the
  event on the calendar.
- "My Events" nav link added to both the desktop nav bar and the mobile hamburger
  dropdown for all logged-in users.

### Added (v0.0153)
- Fixed guests being unable to expand post comments — the `toggleComments()` JS
  function was inside a logged-in-only `<?php if ($user): ?>` block.

### Added (v0.0152)
- **Maybe RSVP toggle.** Admins can enable or disable the "Maybe" response option
  sitewide from Site Settings → General. When disabled, Maybe is removed from RSVP
  buttons, invite emails, calendar dropdowns, and the tokenized RSVP endpoint.

### Added (v0.0151)
- **Failed login logging.** Failed login attempts are now recorded in the activity
  log with severity `critical` and displayed in red in the admin Logs tab.
- `severity` column added to `activity_log` (defaults to `info`).
- Anonymous activity logging support (`db_log_anon_activity`) for events with no
  authenticated user.

---

## [v0.015] — 2026-04-03

### Added
- **Email verification for new signups.** New users must click a verification link
  sent to their email before they can log in. The verification token expires after
  24 hours and can be resent from the login page or the post-registration screen.
- Registration page now shows a "Check Your Email" confirmation screen instead of
  immediately logging in after signup.
- Unverified users who try to log in see a clear message and a one-click resend link.

### Changed
- `auth.php` fully promoted to the verification-aware implementation (`auth_dl.php`):
  all 24 pages now share the new login/register/notification logic automatically.
- Existing accounts (created before 2026-04-01) are auto-marked as verified — no
  action required from existing users or admins.
- Mobile nav bar (Home, Calendar, etc.) is now hidden on screens ≤ 768 px. All
  navigation links are accessible through the hamburger dropdown instead, keeping
  the header clean on phones and tablets.

### Fixed
- Hamburger menu was unresponsive on mobile due to `overflow: hidden` on the nav
  container clipping the absolutely-positioned dropdown, making it open but invisible.
- Touch event bubbling caused the dropdown to open and immediately close on a single
  tap. Fixed by stopping propagation in the toggle handler.
- Replaced unreliable `DOMContentLoaded` + external-JS approach with a direct inline
  `onclick` on the button, eliminating all script-load timing issues.

---

## [v0.014] — 2026-03

### Added
- **User-created events.** Admins can now grant regular users the ability to create
  and manage their own events via a toggle in Site Settings.
- Event owners can view and edit RSVP statuses for their own invitees directly from
  the edit modal — previously only admins could do this.
- Email field shown for non-admin custom invitee rows so manually-added guests
  receive notifications.

### Fixed
- JS permission checks for user-created events were incorrectly gated on `isAdmin`
  instead of the `canCreateEvents` flag, silently breaking the feature for non-admins.
- Long event titles on the calendar caused horizontal overflow on mobile.
- Email Event Details link no longer passes through the URL shortener, which was
  breaking session state and preventing users from opening the correct event.
- Event Details button in invite emails corrected to `inline-block` so it renders
  at the right width across email clients.
- Multiple mobile header banner height fixes: banner is now capped consistently
  across portrait and landscape orientations, preventing nav overflow.

---

## [v0.013] — 2026-02

### Added
- **One-click RSVP from email.** Invited users can accept or decline directly from
  the invite email without logging in first.
- New invitees added to an existing event are automatically notified by their
  preferred contact method (email, SMS, or WhatsApp).
- Login and Sign Up links added to the RSVP confirmation page for guests who want
  to create an account.

### Changed
- Host is no longer notified when an invitee's RSVP is unchanged (reduces noise for
  all notification channels).
- Event Details link in invite emails redesigned as a full-width blue button for
  better tap targets on mobile.

---

## [v0.012] — 2026-01

### Added
- **Multi-provider SMS system** with support for Twilio, Vonage, and Plivo. Providers
  are configurable from Site Settings without touching code.
- **Two-way SMS RSVP** — invitees can reply YES/NO to accept or decline events by text.
- **WhatsApp messaging** via Meta Cloud API (alpha). Invite notifications can be routed
  to WhatsApp in addition to email and SMS.
- **URL shortener** for outbound SMS links, using is.gd (free, no API key required).
- **Password show/hide toggle** on login and registration pages, with iOS Safari fix.
- **SMS log** — admin page showing all outbound messages, raw API responses, and a
  one-click copy button. Log can be cleared from the settings page.
- **Privacy Policy and Terms & Conditions** pages added, with links in the footer.
- Inbound SMS webhook URL shown in SMS settings for easy provider configuration.

### Changed
- Admin settings reorganized: Email, SMS, and WhatsApp grouped under a single
  Communication tab.
- SMS log moved to a dedicated full-width page rather than embedded in settings.

### Fixed
- SMS log Raw column copy button was being clipped by the table.
- Outbound delivery receipt webhooks from SMS providers are now ignored (were
  flooding the log with noise).

---

## [v0.011] — 2025-12

### Added
- **Login to join** prompt for unauthenticated users viewing an event — Sign In and
  Sign Up buttons shown inline in the event view modal.
- **Auto-open event modal** after login redirect — users land directly on the event
  they were trying to view, not the home page.
- Sign Up button added next to Login in event view for guests who don't have an account.
- `register.php` now accepts a `redirect` parameter so users return to the right place
  after creating an account.

### Changed
- RSVP section moved above the Invites list in the event view modal so users see
  their own status first.
- RSVP UX overhauled: status auto-saves on selection change, status badge shown per
  invitee row, cleaner layout.
- Invites list is now scrollable showing ~5 users at a time.
- App version shown in the site footer.
- Nav banner and header now appear on the login and registration pages.

### Fixed
- Login redirect URL now correctly preserves the event open/date query parameters
  so the right event auto-opens after authentication.

---

## [v0.010] — 2025-11

### Added
- **App versioning** — version number defined in `version.php` and displayed in the
  footer.
- **Header banner** — admins can upload a wide banner image that appears in the nav
  bar, with configurable height (up to 200 px).
- **Desktop edit event modal** redesigned as a two-column layout with a searchable,
  scrollable invite checklist and per-invitee notification toggle.
- SMTP diagnostics tool added to the admin Email settings tab.

### Fixed
- SMTP settings key mismatch that prevented email from being saved correctly.
- Forced password-change flow now triggers correctly on first admin login.
- First-login credentials updated in documentation (`admin@localhost` / `admin`).
