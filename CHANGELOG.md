# Changelog

All notable changes to GameNight are documented here.

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
