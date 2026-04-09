# GameNight Workflow & Page Reference

A developer-facing map of every page in the GameNight app: where it lives, who can reach it, what it does, and how users get to it. Companion to `DOCS.md` (which is the end-user/admin guide).

App version at time of writing: **0.05300**
All page files live in `www/`. Data endpoints (`*_dl.php`) are listed but not detailed here — they exist only to serve AJAX from the page that owns them.

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
- Tournament Timer → `/timer.php`
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
Three modes:
- Standalone (`/timer.php`) — guest practice timer
- Event-linked (`?event_id=X`) — synced with a real game
- Remote viewer (`?view=remote&key=X`) — read-only spectator link
- Blind levels with presets, pause/play/reset
- Chip pool, prize pool, payout structure
- Player count + elimination tracking
- Mobile-responsive
- **AJAX:** `timer_dl.php`
- **Linked from:** nav (mobile), `checkin.php`, `calendar.php`

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
| `timer_dl.php` | `timer.php` |
| `admin_settings_dl.php` | `admin_settings.php` |

---

## 8. Shared Includes (not pages)

- `www/_nav.php` — top navigation partial
- `www/_footer.php` — footer partial
- `www/_poker_helpers.php` — chip-pool / payout math used by checkin & timer
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
`calendar.php` (create event, mark as poker) → `walkin_display.php` (QR on iPad) → guests use `walkin.php` → host uses `checkin.php` → `timer.php` runs the clock → optional remote `timer.php?view=remote&key=…` for spectators.

**Invitee RSVPs from email:**
Email link → `rsvp.php?token=…&r=yes` → done (creator notified).

**Admin tunes the site:**
Nav → `admin_settings.php` → pick a tab. Posts/announcements live separately in `admin_posts.php`.

**Password reset:**
`login.php` → `forgot_password.php` → email → `reset_password.php` → `login.php`.
