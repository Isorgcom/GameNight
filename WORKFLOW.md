# GameNight Workflow & Page Reference

A developer-facing map of every page in the GameNight app: where it lives, who can reach it, what it does, and how users get to it. Companion to `DOCS.md` (which is the end-user/admin guide).

App version at time of writing: **1.1.0**
All page files live in `www/`. Data endpoints (`*_dl.php`) are listed but not detailed here — they exist only to serve AJAX from the page that owns them.

---

## Dev → Git → Live Release Flow

Changes ride through three environments before users see them:

1. **Edit in `/home/bryce/Claude/GameNight`** — the source-of-truth working copy that pushes to GitHub.
2. **Mirror to `/home/bryce/Claude/GameNight-dev`** — every file touched in step 1 is copied to the same relative path in the dev clone, which runs the `gamenight-dev` container at **http://localhost:8080**. The mirror is per-edit, not a bulk rsync, so dev's local experiments and downloaded vendor files stay intact. Excluded from mirroring: `docker-compose.yml`, `config/config.php`, `db/`, `uploads/`, `vendor/`, `phpadmin/`, `.git/`.
3. **Verify locally** at http://localhost:8080. PHP/static edits are picked up live via the bind-mount; if a rebuild is needed, run `docker compose up -d --build` in `GameNight-dev`.
4. **Push to git** *only after* the change is confirmed in dev: `git add` + `git commit` + `git push` from `GameNight`.
5. **Deploy to live** by SSHing to `root@gamenight.poker` and running `git pull` (followed by `docker compose down && up -d --build` if the change requires a rebuild). Never scp from Windows — CRLF line endings corrupt PHP files.

---

## Access Legend

| Tag | Meaning |
|---|---|
| **PUB** | Public — no login required |
| **USER** | Any logged-in user |
| **ADMIN** | Admin role only |
| **TOKEN** | Public, but gated by a one-time / signed token (RSVP, walkin QR, email verify, password reset, remote timer) |
| **REDIR** | Legacy URL — 30x redirect to its current home |

---

## Navigation Map (`www/_nav.php`)

**Main nav (desktop):**
- Home → `/index.php` — always
- Calendar → `/calendar.php` — if `show_calendar=1`
- My Events → `/my_events.php` — logged-in only
- Posts → `/admin_posts.php` — admin only
- Site Settings → `/admin_settings.php` — admin only

**Mobile / dropdown menu adds:**
- Tournament Timer → `/timer_layouts.php` (the layout editor; the display itself is `/timer.php`)
- Tournament Timer Classic → `/timer_classic.php`
- Sign Up → `/register.php` (if registration enabled)
- Log In / Log Out
- My Settings → `/settings.php`

---

## 1. Authentication & Account Pages

### `www/index.php` — Home  · **PUB**
The site landing page. Shows the current week's events and a scrollable feed of posts with comment threads.
- View this week's events (cards by date)
- Read pinned + recent posts
- Comment on posts (logged-in)
- Timeline / month sidebar filter (logged-in)
- Infinite scroll loads more posts
- **AJAX:** `posts_chunk.php`
- **Linked from:** brand logo, default landing page

### `www/login.php` — Sign In  · **PUB**
- Email + password
- "Remember me" (30-day session)
- Rate-limited after failed attempts
- Detects unverified email and surfaces resend link
- Honors `?redirect=` for post-login routing
- **AJAX:** `auth_dl.php`
- **Linked from:** nav, `forgot_password.php`, `register.php`

### `www/register.php` — Sign Up  · **PUB**
Disabled (403) when `allow_registration=0`.
- Username, email, optional phone (with SMS consent), password (12+ chars)
- Sends verification email
- Rate-limited per IP
- Links to `terms.php` / `privacy.php`
- **AJAX:** `register_dl.php`
- **Linked from:** nav, `login.php`

### `www/verify_email.php` — Email Verification  · **TOKEN**
Validates the 1-hour token from the registration email and marks the user verified.
- **Linked from:** verification email

### `www/resend_verification.php` — Resend Verification  · **PUB**
Form to re-send the verification email. Always shows success (does not reveal whether the address exists).
- **Linked from:** `login.php` error path, `register.php` success screen

### `www/forgot_password.php` — Password Reset Request  · **PUB**
Email input → emails a reset token (1-hour expiry).
- **Linked from:** `login.php`

### `www/reset_password.php` — Set New Password  · **TOKEN**
Token-gated new password form.
- **Linked from:** password reset email

### `www/logout.php` — Sign Out  · **USER**
Destroys the session and redirects home.
- **Linked from:** nav dropdown

### `www/connect.php` — Sign in to a Connected App  · **USER**
The sign-in bridge for a connected app (FinalTable). Arrives as `?app=<slug>&return=<url>&state=<nonce>`; a guest is sent through `login.php?redirect=` (verification and MFA included) and back. Shows one card, "Continue to <app> as <you>?", then POSTs a 120-second ES256 identity token to `<return>#gn_token=...&state=...`. Unknown or disabled app → 404 page; a return URL that is not the app's registered origin → 400 page; never a redirect on failure.
- **Helpers:** `_sso.php` (keys, signing, return-URL validation)
- **Linked from:** the connected app's own "Sign in with GameNight" button

---

## 2. User Pages (Logged-in)

### `www/dashboard.php` — Dashboard Redirect  · **USER**
Thin redirect to `admin_settings.php?tab=dashboard` (legacy entry point).

### `www/settings.php` — My Settings  · **USER**
Self-service profile management.
- Username / email / phone
- Preferred contact (email / SMS / WhatsApp / none)
- Past-days range for "My Events"
- Phone verification by SMS code (requires Surge SMS)
- Change password
- Handles forced-password-change flow from login
- **Linked from:** nav dropdown

### `www/user_edit.php` — Edit User  · **ADMIN**
Admin tool to manage any account.
- Username / email / phone / role
- Force email verification, force password change
- Past/future days settings, notes
- Delete user (blocks deleting the last admin)
- Per-user activity log
- **Linked from:** `admin_settings.php?tab=users`

### `www/my_events.php` — My Events  · **USER**
Personal event list — invited or created.
- Upcoming + past events (configurable past-days range)
- RSVP status badges
- Quick RSVP buttons
- Create/edit/delete (if allowed or user is manager)
- **AJAX:** `calendar_dl.php`
- **Linked from:** nav

### `www/calendar.php` — Calendar  · **USER** (if `show_calendar=1`)
Full month calendar + event editor.
- Month/year navigation
- Create events (admin or `allow_user_events`)
- RSVP form, invitee picker, manager assignment
- Recurring events with per-occurrence RSVPs
- Event color, poker flag, all-day toggle
- Generate walkin token / link
- Pre-populate from `?open=ID` or `?date=YYYY-MM-DD`
- **AJAX:** `calendar_dl.php`, `event_invites_dl.php`
- **Linked from:** nav, `my_events.php`, deep links from email

---

## 3. Event / Poker Workflow Pages

### `www/checkin.php` — Tournament Check-In  · **USER** (admin / creator / manager)
Run-of-show UI for the host on game night.
- Player list with add / remove
- Buy-in tracking, chip-pool calc
- Walkin badges for QR-registered players
- Eliminate / cash-out actions
- Start session → creates `poker_sessions` row
- Links out to `timer.php` and `walkin_display.php`
- **AJAX:** `checkin_dl.php`
- **Linked from:** poker event card on `calendar.php`, back-link from `timer.php`

### `www/walkin.php` — Walk-In Registration  · **TOKEN**
Public form reached by scanning the table QR code. No login.
- Token tied to a specific event
- Display name + email + phone, auto-generates username
- Existing user → RSVP; new → guest signup
- IP rate-limited
- **AJAX:** `checkin_dl.php`
- **Linked from:** QR code shown by `walkin_display.php`

### `www/walkin_display.php` — Walk-In QR Display  · **USER** (admin / creator / manager)
Full-screen QR code for an iPad at the registration table.
- Generates token if missing
- Stripped chrome (no nav/footer), landscape-friendly
- **Linked from:** `checkin.php`, `calendar.php` event options

### `www/timer.php` — Tournament Timer  · **PUB / USER / TOKEN**
The layout-engine display, and the doorway every timer link comes through. Was
`timer_beta.php` until v1.1.0, when it took the Tournament Timer name and this
address; the old path 301s here.
- Event-linked (`?event_id=X`) — that game's live board
- Cast/second screen (`?key=X`) — the QR a display shows; viewing only
- Sample mode (no parameters) — judge a layout without a game
- Embed (`?embed=1`) — the editor's live preview, same-origin framed only
- **Dispatch:** forwards to `timer_classic.php` for `?view=remote` (a Classic
  cast link), `?classic=1`, and any game whose Setup switch says Classic or
  that has no timer row yet. Classic forwards back only in the opposite case,
  so the two cannot bounce a request between them.
- **AJAX:** `timer_dl.php` (state), `timer_layouts_dl.php` (layouts)
- **Linked from:** nav, `checkin.php`, `calendar.php`, `table_manager.php`

### `www/timer_classic.php` — Tournament Timer Classic  · **PUB / USER / TOKEN**
The original clock, at `www/timer.php` until v1.1.0. Owns creating a game's
`timer_state` row, which is why a game with no row is sent here first.
- Standalone (`/timer_classic.php`) — guest practice timer
- Event-linked (`?event_id=X`) — synced with a real game
- Remote viewer (`?view=remote&key=X`) — read-only spectator link
- Blind levels with presets, pause/play/reset
- Chip pool, prize pool, payout structure
- Player count + elimination tracking
- **AJAX:** `timer_dl.php`
- **Linked from:** nav, `checkin.php`, and `timer.php`'s dispatch

### `www/timer_layouts.php` — Tournament Timer: Layouts  · **USER**
The layout editor (was `timer_beta_edit.php`). Body in
`_timer_layouts_editor.php`, behaviour in `timer_layouts.js`, live preview an
iframe of `/timer.php?embed=1`.
- **AJAX:** `timer_layouts_dl.php`
- **Linked from:** nav ("Tournament Timer"), `help-timer.php`, the layout
  picker's Edit button

### `www/timer_beta.php`, `timer_beta_edit.php`, `timer_beta_dl.php` — old addresses  · **REDIR**
301 to `/timer.php` and `/timer_layouts.php`; the endpoint 307s to
`/timer_layouts_dl.php` so a POST stays a POST. They exist so QR codes, cast
links and bookmarks printed before v1.1.0 keep working.

### `www/rsvp.php` — One-Click RSVP  · **TOKEN**
Email-link RSVP, no login.
- `?token=X&r=yes|no|maybe`
- Updates RSVP, can be flipped on the same screen
- Notifies event creator (email/SMS)
- Optionally prompts for account creation
- **Linked from:** invitation email / SMS

### `www/comment.php` — Comment Handler  · **USER**
Form/AJAX endpoint for comment CRUD on posts and events. Not a page you "visit" — it's POSTed to from `index.php` and `calendar.php`.
- Add (≤2000 chars, sanitized)
- Edit / delete (owner or admin)
- Bulk delete (admin)
- CSRF protected, returns JSON for AJAX

---

## 4. Admin Pages

### `www/admin_posts.php` — Posts  · **ADMIN**
Manage site announcements / blog posts.
- Create with title + rich body, schedule publish time
- Pin / unpin
- Hide / unhide
- View comments per post
- HTML sanitized on save
- **Linked from:** nav (admin)

### `www/admin_settings.php` — Site Settings  · **ADMIN**
The single big admin console — `?tab=NAME`. Most legacy admin URLs redirect here.

| Tab | Purpose |
|---|---|
| `dashboard` | Event/user/system stats |
| `general` | Site name, timezone, registration toggle, user-events toggle, maybe-RSVP toggle |
| `appearance` | Banners, nav colors, accent color |
| `users` | User directory, search, CSV export, links to `user_edit.php` |
| `events` | Event management |
| `email` | SMTP config + test send (also reachable via `SMTPTesting.php`) |
| `sms` | SMS provider (Surge / Twilio / Plivo / Telnyx / Vonage), keys, verification toggle |
| `whatsapp` | WhatsApp provider config |
| `logs` | Activity log viewer with filters |
| `backup` | DB backup download / restore, version display |

- **AJAX:** `admin_settings_dl.php`, `auth_dl.php` (backup)
- **Linked from:** nav (admin)

### `www/admin_sso_apps.php` — Connected Apps  · **ADMIN**
Registers the external apps that may sign people in with their account here (see `connect.php`): slug (the token audience), name, base URL (the only origin a token is ever returned to), enable / disable / remove. Shows the public signing key, the key id and a ready-to-paste `.env` snippet for FinalTable, and can regenerate the keypair (every app must then be re-paired). The public key is also served at `GET /api/v1/sso`, no API key needed. Also holds each app's **game key** (`sso_apps.api_key`, encrypted with `encrypt_value()`, rendered only as set / not set) with a **Test** that probes the app through `finaltable_probe()`; the key is what `finaltable_dl.php` sends to make and drive a game there.
- **Linked from:** Site Settings tab strip (`_admin_tabs.php`)

### `www/users.php` — Legacy Users  · **REDIR**
Redirects to `admin_settings.php?tab=users`.

### `www/logs.php` — Legacy Logs  · **REDIR**
Redirects to `admin_settings.php?tab=logs`.

### `www/sms_log.php` — SMS Log  · **ADMIN**
Paginated SMS history (50/page) — timestamp, phone, body, raw provider payload. Has a Clear button.

### `www/SMTPTesting.php` — SMTP Test  · **ADMIN**
Configure SMTP credentials and send a test email. Saves to `site_settings`.

### `www/SMSTesting.php` — Legacy SMS Test  · **REDIR**
Redirects to `admin_settings.php?tab=sms`.

---

## 5. Public Info Pages

### `www/terms.php` — Terms & Conditions  · **PUB**
Static policy page (last-updated date is dynamic). Linked from `register.php`.

### `www/privacy.php` — Privacy Policy  · **PUB**
Static policy page. Linked from `register.php` and `terms.php`.

---

## 6. Utility / Internal Pages

### `www/s.php` — Short URL Redirect  · **PUB**
`/s.php?code=ABC` → 301 to the target stored in `short_links`. Falls back to home if missing. Used for shortened email/SMS links.

### `www/posts_chunk.php` — Posts Infinite Scroll  · **PUB**
HTML-fragment AJAX endpoint for `index.php` infinite scroll. Returns post cards (with comments) by `?offset=`, `?limit=`, `?month=YYYY-MM`. Empty body = end of feed.

### `www/upload.php` — File Upload  · **USER**
POST handler for image uploads (banners, post images, etc.). Stores under `uploads/`.

### `www/cron.php` — Scheduled Tasks  · **PUB** (intended for cron)
Sends RSVP reminders, cleans expired tokens, processes scheduled posts. Run from a cron job or container timer — not opened by users.

### `www/wa_webhook.php` — WhatsApp Webhook  · **PUB** (signed by provider)
Receives WhatsApp inbound messages.

### `www/sms_webhook.php` — SMS Webhook  · **PUB** (signed by provider)
Receives SMS replies (handles RSVP-by-text replies routed through `cron.php`/notification flow).

### `www/finaltable_webhook.php` — FinalTable Webhook  · **PUB** (HMAC-signed, per game)
Receives a FinalTable server's reports about a game made from here (`tournament.started` / `level` / `paused` / `resumed` / `heartbeat`, `player.eliminated` / `reentered`, `tournament.completed` / `cancelled`). Looks the game up by `game.id` in `finaltable_games`, checks the millisecond timestamp (5 min) and the `sha256=` HMAC over `<ts>.<body>` with that game's secret, dedupes on `finaltable_deliveries (game_id, delivery_id)` (the UNIQUE row is the lock), then `finaltable_apply_event()` writes the game row and the session's `poker_players`. 404 for an unknown game, 401 for a bad signature, 500 (with the dedupe row removed) when applying fails. Never includes `auth.php`.

### `www/favicon.php` — Favicon  · **PUB**
Dynamic favicon serving.

---

## 7. Data Endpoints (`*_dl.php`)

These are POST-only AJAX backends — they have no HTML view of their own. Listed here so you know which page owns each one.

| Endpoint | Owned by |
|---|---|
| `auth_dl.php` | `login.php`, `register.php`, `admin_settings.php` (backup) |
| `register_dl.php` | `register.php` |
| `calendar_dl.php` | `calendar.php`, `my_events.php` |
| `event_invites_dl.php` | `calendar.php` |
| `checkin_dl.php` | `checkin.php`, `walkin.php` |
| `timer_dl.php` | `timer.php`, `timer_classic.php` |
| `timer_layouts_dl.php` | `timer.php`, `timer_layouts.php` |
| `admin_settings_dl.php` | `admin_settings.php` |
| `finaltable_dl.php` | `event.php` (the *Played online* panel): `setup`, `start`, `pause`, `resume`, `cancel`, `remove` (POST, managers), `state` (GET, anyone who can see the event). `setup` also takes `bots` (0-40) from an admin, with no control in the UI, for trying a game out. |

---

## 8. Shared Includes (not pages)

- `www/_nav.php` — top navigation partial
- `www/_footer.php` — footer partial
- `www/_poker_helpers.php` — chip-pool / payout math used by checkin & timer
- `www/_finaltable.php` — the FinalTable client (`finaltable_request()`, bearer key, 8 s, no redirects), the roster and setup preview the event page and `finaltable_dl.php` share, and `finaltable_apply_event()` for the receiver
- `www/auth.php` — `require_login()`, `current_user()`, security headers, CSRF
- `www/db.php` — schema, migrations, all DB helpers
- `www/mail.php` — PHPMailer wrapper
- `www/sms.php` — multi-provider SMS abstraction
- `www/version.php` — `APP_VERSION` constant

---

## 9. Quick Workflow Cheatsheet

**New user signs up:**
`register.php` → email → `verify_email.php` → `login.php` → `index.php`

**Host runs a poker night:**
`calendar.php` (create event, mark as poker) → `walkin_display.php` (QR on iPad) → guests use `walkin.php` → host uses `checkin.php` → `timer.php` runs the clock (or `timer_classic.php`, per that game's Setup switch) → optional cast link `timer.php?key=…`, or `timer_classic.php?view=remote&key=…` for spectators.

**Invitee RSVPs from email:**
Email link → `rsvp.php?token=…&r=yes` → done (creator notified).

**Admin tunes the site:**
Nav → `admin_settings.php` → pick a tab. Posts/announcements live separately in `admin_posts.php`.

**Password reset:**
`login.php` → `forgot_password.php` → email → `reset_password.php` → `login.php`.
