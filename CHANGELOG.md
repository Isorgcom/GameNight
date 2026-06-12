# Changelog

All notable changes to GameNight are documented here.

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
