# GameNight Manual Test Checklist

A structured bug-hunt checklist for the GameNight app. Work through an area at a time, check off each item, and note anything weird in the margin. Not every item has to pass — the goal is to find bugs, not certify correctness.

**How to use:** Copy this file into a working doc, walk through one section per sitting, and log issues against GitHub Discussion #7 (features) or a new issue for bugs.

**Legend:** ⚠️ = known fragile area, extra scrutiny recommended

---

## 0. Setup (do once before testing)

- [ ] Take a DB backup via Admin Settings → Backup → Download. Keep it safe.
- [ ] Note the current app version in `www/version.php`
- [ ] Have at least 3 test accounts ready: one admin, one normal user, one unverified
- [ ] Have a second browser / incognito window for cross-user tests
- [ ] Have a phone with SMS access for phone verification / SMS RSVP tests
- [ ] Confirm SMTP and SMS provider are configured (if testing notifications)

---

## 1. Auth & Accounts

### Registration
- [ ] Register with valid data — verification email arrives
- [ ] Register with username < 3 chars → rejected
- [ ] Register with username > 30 chars → rejected
- [ ] Register with special chars in username (`john@doe`, `john doe`) → rejected
- [ ] Register with duplicate email (case-insensitive: `Test@x.com` vs `test@x.com`) → rejected
- [ ] Register with duplicate username (case-insensitive) → rejected
- [ ] Register with password < 12 chars → rejected
- [ ] Register with invalid email format → rejected
- [ ] Register without phone → succeeds, phone stays null
- [ ] Register with SMS consent unchecked → phone still saved but opt-in respected
- [ ] Hit registration 6+ times from same IP within an hour → rate-limited (silently blocks)
- [ ] Unverified user tries to log in → blocked with "verify your email" message + resend link

### Email Verification
- [ ] Click verification link within 1 hour → account verified, can log in
- [ ] Click verification link after 1 hour → error, link expired
- [ ] Click used verification link twice → second click rejected
- [ ] Resend verification from `resend_verification.php` → new email arrives
- [ ] Resend verification 4+ times from same IP → rate-limited
- [ ] Resend for non-existent email → still shows "success" (no account enumeration)

### Login
- [ ] Valid credentials → logged in, redirected to home (or `?redirect=`)
- [ ] Wrong password → generic error, no leak about which field was wrong
- [ ] 6+ failed logins from one IP → rate-limited for 15 min
- [ ] Log in with `?redirect=/calendar.php` → lands on calendar after login
- [ ] Log in with `?redirect=//evil.com` → redirected to `/` instead (open-redirect protection)
- [ ] Log in with `?redirect=https://evil.com` → redirected to `/` instead
- [ ] `last_login` timestamp updates in DB after successful login

### ⚠️ Remember Me (v0.05301 fix)
- [ ] Log in **without** "Remember me" → close browser completely → reopen → should be logged out
- [ ] Log in **with** "Remember me" → close browser completely → reopen → should still be logged in
- [ ] With "Remember me" active, check DevTools → Cookies → `gn_remember` exists with 30-day expiry, HttpOnly, Secure (on HTTPS), SameSite=Lax
- [ ] Manually corrupt `gn_remember` cookie value in DevTools → refresh → redirected to login, cookie cleared
- [ ] Delete `PHPSESSID` cookie only (keep `gn_remember`) → refresh → silently re-authenticated, new `gn_remember` value appears (rotation)
- [ ] Log in **without** "Remember me" → sit idle 30+ min → refresh → still logged in (idle-GC fix)
- [ ] Log in with "Remember me" on device A → log in with "Remember me" on device B → both work independently
- [ ] Click "Sign out" → both `PHPSESSID` and `gn_remember` cookies are cleared, DB `remember_tokens` row for that device is deleted

### Password Reset
- [ ] Request reset with valid email → email arrives
- [ ] Request reset with non-existent email → still shows success (no enumeration)
- [ ] Click reset link within 1 hour → can set new password
- [ ] Click reset link after 1 hour → rejected
- [ ] Click used reset link twice → second click rejected
- [ ] New password < 12 chars → rejected
- [ ] 4+ reset requests from same IP → rate-limited

### Forced Password Change
- [ ] Admin sets `must_change_password=1` on a user → that user logs in → redirected to `/settings.php?must_change=1`
- [ ] That user tries to navigate to `/calendar.php` before changing → redirected back to settings
- [ ] That user CAN access `/logout.php`
- [ ] After changing password → flag cleared, normal navigation restored

### Permissions
- [ ] Normal user tries to access `/admin_settings.php` → 403 / redirect
- [ ] Normal user tries to access `/admin_posts.php` → blocked
- [ ] Normal user tries to access `/user_edit.php?id=OTHER` → blocked
- [ ] Normal user tries to POST to an admin-only `_dl.php` endpoint directly → blocked

---

## 2. User Profile / Settings

- [ ] Edit username → saves, new name shows in nav
- [ ] Edit email → email_verified flips to 0, must re-verify before next login?
- [ ] Edit phone → phone_verified flips to 0
- [ ] Change preferred contact (email / SMS / WhatsApp / none) → persists
- [ ] Notifications routed correctly based on preference (test with a real invite)
- [ ] Phone verification: request code → SMS arrives → enter code → marked verified ⚠️
- [ ] Phone verification with wrong code → rejected, allows retry
- [ ] Change password: wrong current password → rejected
- [ ] Change password: new ≠ confirm → rejected
- [ ] Change password: new < 12 chars → rejected
- [ ] Change password: success → session preserved, can keep browsing
- [ ] Edit past-days range (e.g., 90) → my_events.php reflects new range
- [ ] Edit past-days outside 7-365 range → rejected or clamped

---

## 3. Events & Calendar

### Create / Edit / Delete
- [ ] Admin creates event → appears on calendar
- [ ] Normal user creates event when `allow_user_events=0` → blocked
- [ ] Normal user creates event when `allow_user_events=1` → succeeds
- [ ] Create event with end_date before start_date → rejected
- [ ] Create event with start_time but no end_time → allowed (open-ended)
- [ ] Create all-day event (no times) → displays as all-day
- [ ] Create event in the past → allowed (for backfill)
- [ ] Edit event as creator → works
- [ ] Edit event as assigned manager → works
- [ ] Edit event as non-manager non-creator normal user → blocked
- [ ] Delete event → invitees notified via their preferred contact method
- [ ] Delete event with `suppress_notify=1` → no notifications sent
- [ ] Event color whitelist — try submitting `#ff00ff` → rejected, only the 7 presets allowed
- [ ] Toggle poker flag on event → poker-specific UI (checkin link) appears

### ⚠️ Recurring Events
- [ ] Create weekly recurring event → appears on calendar for each occurrence
- [ ] Edit a single occurrence → only that date changes (event_exceptions row created)
- [ ] Delete a single occurrence → only that date removed, rest remain
- [ ] Delete the base event → all occurrences gone, cleanup of exceptions
- [ ] RSVP to one occurrence → other occurrences not affected
- [ ] New invitee added mid-series → only gets notified about future occurrences

### Invitees & Managers
- [ ] Add existing user as invitee → auto-fills email/phone
- [ ] Add custom email/phone invitee → creates entry even without user account
- [ ] Assign invitee as manager → can now edit the event
- [ ] Remove manager → loses edit access
- [ ] Notification suppression checkbox on add → no email sent on add
- [ ] Default add → invitee receives invitation email/SMS

### RSVP
- [ ] RSVP yes/no from calendar UI → updates DB
- [ ] RSVP maybe when `allow_maybe_rsvp=0` → option hidden
- [ ] RSVP maybe when `allow_maybe_rsvp=1` → option available, saves
- [ ] RSVP via email link `/rsvp.php?token=X&r=yes` → flips RSVP, no login required
- [ ] RSVP via email link with bad token → error page
- [ ] RSVP via email link with expired/used token → error page
- [ ] Change RSVP from yes to no → creator gets notified
- [ ] Change RSVP from yes to yes (no actual change) → creator NOT notified
- [ ] RSVP via SMS reply (e.g., "YES") → webhook processes, DB updated ⚠️
- [ ] SMS reply "MAYBE" when maybe is disabled → falls back / rejected gracefully
- [ ] SMS reply "ALL yes" to multi-event prompt → all pending events RSVP'd ⚠️
- [ ] SMS reply "2 yes" to numbered list → only event #2 RSVP'd

### Calendar Navigation
- [ ] Month next/prev buttons work
- [ ] `?m=2026-05` pre-loads May 2026
- [ ] `?open=ID&date=2026-04-15` pre-opens a specific event
- [ ] Timezone: event starts at 7 PM in admin's timezone → displays as 7 PM for viewers in the same timezone
- [ ] `show_calendar=0` → Calendar link hidden in nav

### My Events
- [ ] my_events.php shows only upcoming + events within past_days range
- [ ] Changing past_days in settings updates my_events immediately
- [ ] RSVP badges display correct state
- [ ] Events created by user appear even if not invited

---

## 4. Posts & Comments

- [ ] Admin creates post → appears on home page
- [ ] Post scheduled for future → doesn't appear until `publish_at` passes (requires cron)
- [ ] Pin post → appears at top of feed
- [ ] Unpin post → returns to chronological order
- [ ] Hide post → disappears from feed, admin can still see it
- [ ] Rich text editor: paste HTML with `<script>` → sanitized out
- [ ] Rich text editor: paste inline styles → kept or stripped per sanitize rules
- [ ] Comment as logged-in user → appears under post
- [ ] Comment with > 2000 chars → truncated or rejected
- [ ] Edit own comment → works
- [ ] Edit someone else's comment as non-admin → blocked
- [ ] Delete own comment → works
- [ ] Delete another user's comment as admin → works
- [ ] Bulk delete comments as admin → works
- [ ] Infinite scroll on home: scroll down → more posts load
- [ ] Infinite scroll: reach end → no duplicate fetch, no error
- [ ] Month sidebar filter: click a month → feed filters

---

## 5. Poker — Check-in, Walk-in, Timer

### Walk-in QR
- [ ] Open `walkin_display.php?event_id=X` as host → QR displays, token generated
- [ ] Scan QR → `walkin.php` loads with valid token
- [ ] Walk-in form with valid data → success, player added
- [ ] Walk-in form with bad token → error
- [ ] Walk-in with existing user's email → matches existing user, creates RSVP
- [ ] Walk-in with new email → creates soft user account, sends verification email
- [ ] Walk-in with display name `Al` (< 3 chars after sanitization) → handles gracefully ⚠️
- [ ] Walk-in with 30+ char display name → truncates or handles
- [ ] Walk-in with emoji in name → handles
- [ ] Two walk-ins with same display name → username suffix (`john`, `john2`)
- [ ] 6+ walk-ins from same IP within an hour → rate-limited
- [ ] Walk-in with session active → auto-assigned to table, table # shown on confirmation
- [ ] Walk-in cookies pre-fill on next walk-in on same device

### Host Check-in Dashboard
- [ ] Open `checkin.php?event_id=X` as admin → setup screen if no session
- [ ] Non-creator/non-admin/non-manager tries to open → blocked
- [ ] Click "Create Session & Import Players" → poker_sessions row created, invitees imported as players
- [ ] Toggle check-in on a player → `checked_in=1`, table auto-assigned
- [ ] Toggle buy-in on a player → `checked_in=1`, `bought_in=1`, table assigned
- [ ] Toggle check-in off → `checked_in=0`
- [ ] Rebuy +: increments, respects `max_rebuys` if set
- [ ] Rebuy -: decrements, not below 0
- [ ] Add-on +/-: same behavior as rebuy
- [ ] `max_rebuys=2` → 3rd rebuy click → rejected
- [ ] Add walk-in manually from host UI → new row created, auto-assigned
- [ ] Remove player → soft-deleted (`removed=1`), disappears from list
- [ ] Move player to different table → seat reassigned
- [ ] Balance tables: reduce `num_tables` → players redistributed ⚠️
- [ ] Balance tables with odd player counts → edge cases handled (button/SB/BB protection)

### Tournament mode
- [ ] Eliminate player → `eliminated=1`, `finish_position` set
- [ ] Uneliminate → flag cleared
- [ ] Edit payout structure → places/percentages saved
- [ ] ICM calculator → produces sensible payouts that sum to pool ⚠️
- [ ] Chip Chop calculator → same
- [ ] Standard calculator → same

### Cash game mode
- [ ] Add cash-in → `cash_in` accumulates
- [ ] Set cash-in → overrides total
- [ ] Set cash-out for a player not bought-in → rejected
- [ ] Set cash-out greater than remaining pool → rejected or warns
- [ ] Profit displays correctly (`cash_out - cash_in`)

### Session lifecycle
- [ ] Status: setup → active → finished transitions work
- [ ] Return to checkin.php mid-game → state intact
- [ ] Two host tabs open at once, click buttons → no race conditions / no corrupted state

### Timer
- [ ] Standalone timer (no login, `/timer.php`) → works, persists in session only
- [ ] Close tab, reopen standalone timer → state lost (expected)
- [ ] Event-linked timer (`?event_id=X`) → loads saved state, persists in DB
- [ ] Remote viewer (`?view=remote&key=X`) → read-only, no controls
- [ ] Remote viewer with wrong key → blocked
- [ ] Play/pause blind level → `time_remaining_seconds` persists
- [ ] Skip level forward → `current_level` increments
- [ ] Skip level backward → `current_level` decrements, not below 1
- [ ] Reset → level 1, full time restored
- [ ] Preset selection → blinds load from `blind_presets`
- [ ] Export blinds as JSON → file downloads
- [ ] Import blinds JSON → levels replaced after validation
- [ ] Import malformed JSON → rejected cleanly, no state corruption
- [ ] Guest tries to save preset or export → prompted to log in
- [ ] Two timers open in two tabs on same event → stay in sync? Last-write-wins? ⚠️

---

## 6. Admin Settings

### General
- [ ] Change site_name → reflected in title, nav, emails
- [ ] Change timezone → events display in new timezone
- [ ] Toggle `allow_registration=0` → `/register.php` returns 403
- [ ] Toggle `allow_user_events=0` → users can no longer create events
- [ ] Toggle `allow_maybe_rsvp=0` → maybe option disappears everywhere
- [ ] Toggle `notifications_enabled=0` → no emails or SMS sent on invites/RSVPs
- [ ] Toggle `show_calendar=0` → Calendar nav link hidden

### Appearance
- [ ] Upload banner image → displays on home
- [ ] Upload file > 8 MB → rejected
- [ ] Upload `.exe` renamed to `.png` → rejected (MIME detection) ⚠️
- [ ] Change accent color → reflected site-wide

### Users
- [ ] Users list loads, paginates, searches
- [ ] CSV export → downloads with correct headers
- [ ] Add user form: username/email/password validation matches register.php
- [ ] Edit user (user_edit.php) → all fields save
- [ ] Demote last admin → blocked
- [ ] Delete last admin → blocked
- [ ] Delete user → user removed, comments/events handled gracefully (orphaned or reassigned?)
- [ ] Set force-email-verification flag → next login forces re-verify
- [ ] Set force-password-change flag → next login redirects to settings

### Email (SMTP)
- [ ] Save SMTP config → persisted (encrypted at rest)
- [ ] Send test email → arrives at configured address
- [ ] Send test with bad password → shows error
- [ ] Save with empty password → doesn't overwrite stored password (or does? verify intent)

### SMS
- [ ] Save SMS provider config → persisted (encrypted)
- [ ] Select each provider (Surge/Twilio/Plivo/Telnyx/Vonage) → form updates fields
- [ ] Phone verification toggle only available for Surge
- [ ] SMS log populates after outbound sends
- [ ] SMS log clear button works
- [ ] Inbound SMS webhook with valid signature → processed
- [ ] Inbound SMS webhook with invalid signature → rejected
- [ ] Vonage GET-based webhook attempt → blocked ⚠️

### WhatsApp
- [ ] Provider config saves and encrypts
- [ ] Outbound WhatsApp message sends

### Activity Logs
- [ ] Logs populate after each action (login, create event, etc.)
- [ ] Filter by user → only that user's actions
- [ ] Filter by action → only matching rows
- [ ] Pagination works
- [ ] Log entry with `\n` in action field → stored safely (no injection)

### Backup & Restore
- [ ] Download backup → valid SQLite file with same size as live DB
- [ ] Restore backup → current DB auto-backed up first, then replaced ⚠️
- [ ] Restore with non-SQLite file → rejected
- [ ] Restore with SQLite file missing `users` table → rejected
- [ ] After restore, log in with an account from the backup → works

---

## 7. Notifications & Reminders

- [ ] Invite created → invitee receives notification via preferred contact
- [ ] User with `preferred_contact=email` and no phone → only email sent
- [ ] User with `preferred_contact=sms` and no phone → nothing sent (not email)
- [ ] User with `preferred_contact=both` → both email and SMS
- [ ] `notifications_enabled=0` globally → no notifications sent at all
- [ ] Cron runs → 2-day and 12-hour reminders sent for upcoming events
- [ ] Cron runs twice in quick succession → reminders not duplicated (dedup via `event_notifications_sent`) ⚠️
- [ ] URL shortener enabled → emailed event links are short (`/s.php?code=X`)
- [ ] Short link redirects to correct target
- [ ] Non-existent short code → 404 or home redirect
- [ ] SMS messages include "Reply STOP to unsubscribe" footer
- [ ] Cron endpoint without token → blocked
- [ ] Cron endpoint with wrong token → blocked

---

## 8. Security & Misc

- [ ] All forms include a CSRF token input (inspect HTML source)
- [ ] Submit a form with missing/wrong CSRF token → rejected
- [ ] Response headers include CSP, X-Frame-Options, X-Content-Type-Options, Referrer-Policy, HSTS (on HTTPS)
- [ ] Try to embed site in an `<iframe>` → blocked (X-Frame-Options: DENY)
- [ ] File upload: `.jpg` file → accepted
- [ ] File upload: `.exe` renamed `.jpg` → rejected (finfo check) ⚠️
- [ ] File upload: > 8 MB → rejected
- [ ] File upload: stored with random hex name, not user-provided
- [ ] Activity log entry with `\r\nFAKE_ENTRY` in action → strips the newlines
- [ ] SQL injection probe in a search field (`' OR 1=1 --`) → no effect, prepared statements safe
- [ ] XSS probe in post body `<script>alert(1)</script>` → sanitized out
- [ ] XSS probe in event title → HTML-escaped on display
- [ ] Direct POST to `_dl.php` endpoint without login → blocked
- [ ] Direct POST to admin `_dl.php` endpoint as normal user → blocked
- [ ] IDOR probe: edit another user's event via `calendar_dl.php` with their event_id → blocked ⚠️
- [ ] IDOR probe: read another user's invite list → blocked
- [ ] Session cookie has `HttpOnly`, `Secure` (on HTTPS), `SameSite=Lax`
- [ ] `gn_remember` cookie has same flags
- [ ] `PHPSESSID` rotates on login (session_regenerate_id)

---

## 9. Smoke Test (quick sanity pass after any deploy)

These are the 10 critical flows. If any of these break, something serious is wrong.

1. [ ] Home page loads without errors
2. [ ] Login with an admin account works
3. [ ] Create a new event on the calendar
4. [ ] RSVP to that event as a different user
5. [ ] Create a post, see it on the home page
6. [ ] Open check-in for a poker event, click "Create Session"
7. [ ] Generate walk-in QR, load the walk-in page in another window
8. [ ] Open the timer, start it, pause it
9. [ ] Download a DB backup
10. [ ] Log out — both cookies cleared, forced back to login

---

## Bug Reporting Template

When you find something, log it with:

```
## Bug: [short title]

**Version:** v0.0xxxx
**Area:** (auth / calendar / poker / admin / etc.)
**Steps to reproduce:**
1.
2.
3.

**Expected:**
**Actual:**
**Severity:** (cosmetic / minor / major / blocker)
**Screenshot / console errors:**
```

---

## Notes

- The ⚠️ flag marks features the team already suspects are fragile. Spend extra time there.
- If you find a bug that blocks you from continuing, stop, log it, and move to a different section.
- Remember: the goal is to find bugs, not to make everything pass. A "failing" checklist is a successful bug hunt.
