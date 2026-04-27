# Changelog

All notable changes to GameNight are documented here.

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
- **Pending invitees clicked from All Users now show their name on the Invited side.** Because the picker's synthetic username for phone-only pending invitees is the phone number, clicking one to invite them rendered the phone digits as the visible label. The picker now uses `display_name` as the saved invite username for pending rows, so invited entries read "Randy" instead of "8328074749" and the saved `invite_username` carries the human name.

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
- **Custom invitees now get added to the league Members tab reliably.** When a host added a "+Custom Invitee" to a league event, the invitee was added to the host's contacts but frequently did NOT show up on the League → Members tab, even when they were an existing registered user. Two gaps in `auto_add_pending_to_league()` in `db.php`: (1) the phone value from the combined "Email or phone" field was passed through raw (unnormalized), so `WHERE phone = ?` missed any `users.phone` stored in canonical `XXX-XXX-XXXX` form whenever the host typed a different format like `(281) 215-5889` or `2812155889`; (2) there was no username fallback, so a custom invitee typed as just a username (no contact info) never resolved to the real user. Function now runs `normalize_phone()` at its own boundary and falls back to `WHERE LOWER(username) = LOWER(?)` like `auto_add_contact()` already did.

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
- **Client-side phone auto-formatter.** Typing `8326422893` in any phone field now shows `(832) 642-2893` as you type. New shared script `www/_phone_input.js` binds to every `input[type="tel"]` and to combined "Email or phone" inputs tagged with `data-phone-contact`. Included on register, walk-in, contacts, league (add member), profile settings, user edit, and admin settings pages.
- **Server-side phone format docs.** Added a docblock to `normalize_phone()` in `db.php` documenting the canonical `XXX-XXX-XXXX` storage format and every input shape it accepts (`8326422893`, `832-642-2893`, `(832) 642-2893`, `832.642.2893`, `+1 (832) 642-2893`, `1-832-642-2893`). Defensive `trim()` added up front — callers already trim, but cheap belt-and-suspenders.

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
- **Phone registration rejected valid US numbers.** `normalize_phone()` formats a 10-digit US number as `XXX-XXX-XXXX` (with dashes), but the new phone validators introduced in v0.19000 only accepted raw digits (`^\+?\d{7,15}$`). A user typing `8326422893` saw "Invalid phone number." Replaced the strict digit-only regex with "strip non-digits, count 7–15". Same fix applied in `register_user()`, `find_user_by_identifier()`, and `walkin.php` so lookups, signups, and walk-ins all accept formatted phones.

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
