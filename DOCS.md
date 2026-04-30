# Game Night Documentation

A complete guide to setting up, configuring, and using Game Night — your self-hosted platform for organizing game nights, poker tournaments, and group events.

---

## Table of Contents

- [Quick Start](#quick-start)
- [Deployment](#deployment)
- [First-Time Setup](#first-time-setup)
- [Admin Guide](#admin-guide)
  - [Dashboard](#dashboard)
  - [General Settings](#general-settings)
  - [Appearance](#appearance)
  - [Users](#users)
  - [Events](#events)
  - [Email Configuration](#email-configuration)
  - [SMS Configuration](#sms-configuration)
  - [WhatsApp Configuration](#whatsapp-configuration)
  - [Activity Logs](#activity-logs)
- [Calendar & Events](#calendar--events)
  - [Creating Events](#creating-events)
  - [Recurring Events](#recurring-events)
  - [Inviting People](#inviting-people)
  - [Event Managers](#event-managers)
  - [RSVP System](#rsvp-system)
- [Poker Game Management](#poker-game-management)
  - [Setting Up a Game](#setting-up-a-poker-game)
  - [Tournament Mode](#tournament-mode)
  - [Cash Game Mode](#cash-game-mode)
  - [Managing Players](#managing-players)
- [Posts & Announcements](#posts--announcements)
- [Comments](#comments)
- [User Guide](#user-guide)
  - [Registration & Login](#registration--login)
  - [Profile Settings](#profile-settings)
  - [My Events](#my-events)
  - [RSVP from Email/SMS](#rsvp-from-emailsms)
- [Notifications](#notifications)
  - [How Notifications Work](#how-notifications-work)
  - [Reminder Schedule](#reminder-schedule)
  - [SMS RSVP Replies](#sms-rsvp-replies)
- [Cron Setup](#cron-setup)
- [API for Sister Sites](#api-for-sister-sites)
  - [Scopes](#scopes)
  - [Issuing a Key](#issuing-a-key)
  - [Authentication](#api-authentication)
  - [Endpoints](#api-endpoints)
  - [Response Shape](#api-response-shape)
  - [Errors](#api-errors)
  - [Caching](#api-caching)
  - [Examples](#api-examples)
  - [Revoking a Key](#api-revoking-a-key)
- [Security](#security)
- [Troubleshooting](#troubleshooting)

---

## Quick Start

```bash
# Clone and start
git clone https://github.com/Isorgcom/GameNight.git
cd GameNight
cp config/config.example.php config/config.php
docker compose up -d --build
```

Open your browser to the server's address. Log in with:
- **Username:** `admin@localhost`
- **Password:** `admin`

You'll be prompted to change your password immediately.

---

## Deployment

### Requirements

- Docker and Docker Compose
- A server with ports 80/443 available (or a reverse proxy like Nginx Proxy Manager)

### Docker Compose

```bash
# Start the application
docker compose up -d --build

# Stop
docker compose down

# Update after pulling new code
docker compose down && docker compose up -d --build
```

### Important Directories

| Path | Purpose |
|------|---------|
| `www/` | Web root (served by Apache) |
| `config/config.php` | Database path configuration (gitignored) |
| `db/` | SQLite database storage |
| `www/uploads/` | Uploaded images and banners |

### File Permissions

The `db/` and `www/uploads/` directories must be writable by the web server (`www-data`). If you get HTTP 500 errors on a fresh deploy, check ownership:

```bash
chown -R www-data:www-data db/ www/uploads/
```

### Reverse Proxy

The container connects to the `npm_default` Docker network for use with Nginx Proxy Manager. Configure your proxy host to point to the container.

---

## First-Time Setup

After your first login as `admin`:

1. **Change your password** — You'll be forced to do this on first login.
2. **Set your site name** — Go to Admin > General and give your site a name.
3. **Set the timezone** — Important for event times to display correctly.
4. **Upload a banner** — Go to Admin > Appearance to add your logo and header image.
5. **Enable notifications** — Admin > General > "Enable Notifications" toggle. Off by default.
6. **Configure email** — Admin > Email tab. Set up SMTP so invite emails and reminders work.
7. **Invite your friends** — Have them register, or create accounts in Admin > Users.
8. **Create your first event** — Open the Calendar, click "+", and set up a game night.
9. **Delete the welcome post** — The default post on the home page can be removed or unpinned once you're ready.

---

## Admin Guide

Access admin features at **Admin** in the navigation bar (admin users only).

### Dashboard

Overview of your site: total users, total events, total posts, and recent activity.

### General Settings

| Setting | Default | Description |
|---------|---------|-------------|
| Site Name | Game Night | Displayed in the nav bar and emails |
| Site URL | (blank) | Used in notification links. Set this to your public URL |
| Timezone | UTC | All event times are displayed in this timezone |
| Allow Registration | On | When off, the signup page is disabled |
| Allow User Events | Off | When on, non-admin users can create and manage their own events |
| Show Upcoming Events | On | Show/hide the upcoming events section on the landing page |
| Enable Calendar | On | Show/hide the calendar page and nav link |
| Allow Maybe RSVP | On | When off, only Yes/No RSVP options are available |
| Enable Notifications | Off | Master switch for all email/SMS/WhatsApp notifications |

### Appearance

- **Header Banner** — Upload a wide image displayed across the top of every page. Adjust the display height with the slider.
- **Page Banner (Logo)** — Upload a smaller logo shown in the navigation bar.

Supported formats: JPEG, PNG. Max size: 4 MB.

### Users

- **View all users** in an inline-editable grid. Change username, email, phone, role, preferred contact, and notes directly in the table.
- **Create users** manually with a username, email, and password.
- **Import users** from a CSV file. Imported users get a temporary password and must change it on first login.
- **Export users** to CSV.
- **Bulk actions** — Select multiple users to change their role or delete them.
- **Edit a user** — Click the edit icon to open a full edit page with all fields.
- The last admin account cannot be demoted or deleted (safety guard).

### Events

View and manage all events in the system. Inline-edit titles, dates, and times. Delete events you no longer need.

### Email Configuration

Configure SMTP to enable email notifications:

| Field | Example |
|-------|---------|
| SMTP Host | smtp.gmail.com |
| SMTP Port | 587 |
| SMTP User | you@gmail.com |
| SMTP Password | your-app-password |
| From Address | noreply@yourdomain.com |
| From Name | Game Night |
| Encryption | TLS |

Use the **Send Test Email** button to verify your configuration. Test emails bypass the global notification toggle so you can test even when notifications are off.

### SMS Configuration

Choose from four SMS providers:

- **Twilio** — Account SID, Auth Token, From Number
- **Plivo** — Auth ID, Auth Token, From Number
- **Telnyx** — API Key, From Number
- **Vonage (Nexmo)** — API Key, API Secret, From Number

Configure your provider credentials and use **Send Test SMS** to verify.

**URL Shortener:** Enable is.gd link shortening to keep SMS messages concise.

### WhatsApp Configuration

Set up the Meta Business API for WhatsApp notifications:

- Phone Number ID
- Access Token
- Verify Token (for webhook verification)

Configure the webhook URL in your Meta Business dashboard to point to `https://yoursite.com/wa_webhook.php`.

### Activity Logs

View a chronological log of all actions: logins, event changes, RSVP updates, admin actions, and more. Use **Clear Logs** to wipe the history.

---

## Calendar & Events

The calendar is the heart of Game Night. It defaults to **Week view** with a toggle to switch to Month view.

### Creating Events

1. Click the **+** button on any calendar date, or the **+ Add Event** button.
2. Fill in the details:
   - **Title** (required)
   - **Date** (required)
   - **Start Time** — Defaults to the current time. Uses native time picker on all devices.
   - **Duration** — Auto-calculates end time.
   - **Color** — Pick from 7 colors to categorize events.
   - **Description** (optional)
   - **Poker Game** toggle — Enables the poker check-in dashboard for this event.
   - **Don't Notify** toggle — Suppress invite notifications for this save.
3. Add invitees (see below).
4. Click **Add Event**.

### Recurring Events

When creating or editing an event, set a recurrence pattern:

- **Daily** — Every day
- **Weekly** — Same day each week
- **Monthly** — Same date each month
- **Yearly** — Same date each year

Set an optional end date for the recurrence. Individual occurrences can be deleted without affecting the series.

### Inviting People

The edit modal has a dual-pane invite system:

- **Left pane:** All registered users. Search by name, email, or phone.
- **Right pane:** Invited users for this event.

On desktop, double-click to move users between panes. On mobile/tablet, single-tap works.

You can also add **custom invitees** (people without accounts) by clicking "+ Custom Invitee" and entering a username and email.

### Event Managers

Admins and event creators can grant **Manager** access to invited users:

- Toggle the purple **Mgr** switch next to any invitee's name.
- Managers can edit the event, manage invites, see contact details, and access the poker check-in page.
- Managers cannot create new events or grant manager access to others.

### RSVP System

Invitees can respond to events in several ways:

- **From the calendar** — Click an event and use the RSVP dropdown.
- **From email/SMS** — Click the one-click RSVP link (no login required).
- **From the event view** — Use the RSVP panel.
- **Self-signup** — Logged-in users can join events they weren't originally invited to.

RSVP options: **Yes**, **No**, and optionally **Maybe** (configurable in admin settings).

Event creators are notified when someone changes their RSVP.

---

## Poker Game Management

Toggle **Poker Game** on any event to unlock the check-in dashboard at `/checkin.php`.

### Setting Up a Poker Game

1. Create an event with the **Poker Game** toggle on.
2. Click **Manage Game** from the event view.
3. Choose your game type: **Tournament** or **Cash Game**.
4. Configure the session:
   - Buy-in amount
   - Rebuy amount and whether rebuys are allowed
   - Add-on amount and whether add-ons are allowed
   - Starting chips (tournament)
   - Number of tables
5. Click **Create Session**.

### Tournament Mode

- **Check-in** players as they arrive.
- **Buy-in** tracks who has paid.
- **Rebuys and Add-ons** — Increment/decrement counters per player.
- **Table Assignment** — Assign players to numbered tables.
- **Elimination** — Mark players as eliminated (records finish position).
- **Payouts** — Configure percentage-based payout structure (e.g., 1st: 50%, 2nd: 30%, 3rd: 20%). Payout amounts auto-calculate from the total pool.

### Cash Game Mode

- **Cash In** — Track how much each player brings to the table. Add or subtract amounts.
- **Cash Out** — Record what each player leaves with.
- **Profit/Loss** — Auto-calculated per player (cash out minus cash in).

### Managing Players

- All event invitees auto-appear in the player list with their RSVP status.
- **Walk-ins** — Add players who weren't on the invite list.
- **Notes** — Add per-player notes (e.g., "owes $20 from last time").
- **Filters** — Filter the list by status: All, RSVP Yes, Checked In, Playing, Eliminated.
- **RSVP sync** — Changing RSVP on the check-in page syncs back to the event.

### Game Settings

Click **Settings** during an active game to adjust:
- Game type, buy-in/rebuy/addon amounts
- Rebuy and add-on toggles
- Max rebuys
- Starting chips
- Number of tables
- Payout structure (add/remove places, adjust percentages)

---

## Posts & Announcements

Posts appear on the landing page as a news feed / bulletin board.

### Creating Posts (Admin)

1. Go to **Admin > Posts** or click **New Post**.
2. Enter a title and use the rich text editor for content.
3. Upload images directly in the editor (drag & drop or toolbar button).
4. Optionally set a custom date/time.
5. Publish the post.

### Post Features

- **Pin** — Pinned posts stay at the top of the feed.
- **Hide** — Hidden posts are invisible to users but not deleted.
- **Edit** — Change title, content, date, or pin status.
- **Delete** — Permanently removes the post and its uploaded images.
- **Bulk Delete** — Select multiple posts and delete them at once.

---

## Comments

Both posts and events support comments.

- Any logged-in user can leave a comment.
- Users can edit or delete their own comments.
- Admins can edit or delete any comment and use bulk delete.
- Comments are plain text (max 2,000 characters).

---

## User Guide

### Registration & Login

1. Go to the site and click **Sign Up**.
2. Choose a username (3-30 characters, letters/numbers/underscores).
3. Enter your email, optional phone number, and a password (8+ characters).
4. Check your email for a verification link. Click it to activate your account.
5. Log in with your email and password.

### Profile Settings

Go to **Settings** (gear icon or nav menu) to:

- Update your username, email, or phone number.
- Change your password.
- Set your **preferred contact method**: Email, SMS, WhatsApp, Both (email + SMS), or None.

### My Events

The **My Events** page shows all events you're invited to or created, split into Upcoming and Past. Each event shows your RSVP status and quick links to the calendar view.

### RSVP from Email/SMS

When you're invited to an event, you'll receive a notification via your preferred contact method. The message includes a one-click RSVP link that works without logging in. After responding, you can change your mind using the alternate response buttons on the confirmation page.

---

## Notifications

### How Notifications Work

Notifications are sent via the user's preferred contact method (email, SMS, WhatsApp, or both). The admin must enable notifications globally in **Admin > General > Enable Notifications**.

Notification triggers:
- **Event invite** — When you're added to an event.
- **RSVP change** — Event creator is notified when someone RSVPs.
- **Reminders** — Automated reminders before events (requires cron setup).
- **Password reset** — Email with reset link.
- **Email verification** — Confirmation link on registration.

### Reminder Schedule

When cron is configured, automatic reminders are sent:
- **2 days before** the event
- **12 hours before** the event

Each reminder is sent only once per user per event occurrence (tracked in the database).

### SMS RSVP Replies

If you have SMS configured, users can reply to invitation texts with **YES**, **NO**, or **MAYBE** to update their RSVP. The system matches the reply to the user's phone number and updates their most recent pending invite.

---

## Cron Setup

Automated reminders require a cron job that calls the reminder endpoint.

1. In **Admin > General**, find or generate your **Cron Token**.
2. Set up a cron job on your server:

```bash
# Run every 15 minutes
*/15 * * * * curl -s "https://yoursite.com/cron.php?token=YOUR_CRON_TOKEN" > /dev/null 2>&1
```

Or use the Docker container's built-in timer if configured in `docker-compose.yml`.

---

## API for Sister Sites

GameNight exposes a small JSON API at `/api/v1/` so a separate website (for example, a poker league's main marketing site) can pull league data, or push new users into a league, without copy-pasting. Each API key is bound to **one league** at issuance and is restricted to that league's data — keys cannot read or write across leagues.

The API was designed for a single trusted server-to-server consumer model (one shared key in the consumer's config). It is not an OAuth provider and does not have per-end-user tokens.

### Scopes

Every key carries a scope:

- **`read`** (default) — GET endpoints only.
- **`read,write`** — In addition to read, allows write endpoints such as `POST /users`.

Old keys minted before the scope system shipped are migrated to `read`, so they cannot exercise write endpoints until they are re-minted with write access.

### Issuing a Key

1. As the **owner** of a league, navigate to that league's page (`/league.php?id=N`) and click the **API** tab. Site admins can also access this tab on any league. Managers cannot issue keys — issuing a key exposes the league's data outside the platform, which is an owner-level decision.
2. Type a label that describes who's getting the key (for example `westside-poker sister site`).
3. Pick a scope: **Read-only** (default) or **Read + write (create users)**. Hand out the smallest scope that does the job — a read-only key that leaks cannot create accounts in your league.
4. Click **Mint key**.
5. The plaintext key is displayed exactly once in a green box. Copy it now and store it in the consumer's server config. The key is hashed (SHA-256) at rest; once you leave the page you cannot recover the plaintext.
6. The key appears in the table below the form with scope, status, created date, and last-used date. You can revoke it at any time.

Site admins can see every key across every league via **Admin Settings → API Keys**. That page is read-only and lets admins revoke any key for abuse response, but admins cannot mint keys on behalf of league owners.

### API Authentication

Pass the key in the `Authorization` header:

```bash
curl -H 'Authorization: Bearer YOUR_KEY' https://your-site.com/api/v1/league
```

If your client cannot set headers, you can pass the key as a `?key=` query parameter instead. This works the same way but the key may show up in server logs and referer headers, so the header approach is preferred:

```bash
curl 'https://your-site.com/api/v1/league?key=YOUR_KEY'
```

Either way, the request must be over HTTPS in production.

### API Endpoints

The base path `/api/v1` (no trailing slash needed) returns a discovery document describing the available endpoints — useful for human exploration; no key required. All other endpoints require a key.

#### `GET /api/v1/league`

League summary.

```json
{
  "ok": true,
  "data": {
    "id": 6,
    "name": "Kipling Poker",
    "description": "Friendly home game, every other Saturday.",
    "member_count": 13,
    "created_at": "2026-04-17 02:36:29"
  }
}
```

#### `GET /api/v1/members`

Roster. Personal contact info (emails, phones) is **never** returned.

```json
{
  "ok": true,
  "data": [
    { "display_name": "Bryce", "role": "owner",   "pending": false, "joined_at": "2026-04-17 02:36:29" },
    { "display_name": "brad",  "role": "manager", "pending": false, "joined_at": "2026-04-17 13:43:25" },
    { "display_name": "Crystal", "role": "member", "pending": true,  "joined_at": "2026-04-25 23:06:29" }
  ]
}
```

`pending: true` means the person was invited by email or phone but has not yet created an account. The display name is their `username` if they have an account, otherwise the contact name on the invite.

#### `GET /api/v1/events?from=YYYY-MM-DD&to=YYYY-MM-DD`

> **Breaking change in v0.19208.** This endpoint used to return `start_date` / `start_time` / `end_date` / `end_time` as local-time strings in the league's display timezone. It now returns ISO-8601 UTC instants in `start_at` / `end_at`. Sister sites no longer need to know the league's timezone to display events correctly.

Events for the league within a date window. RSVP counts only include approved invites.

| Query param | Default | Notes |
|---|---|---|
| `from` | today (in the league's timezone) | Inclusive |
| `to` | `from + 90 days` | Inclusive; window is capped at 366 days |

```json
{
  "ok": true,
  "data": {
    "from": "2026-04-28",
    "to": "2026-07-27",
    "count": 2,
    "events": [
      {
        "id": 67,
        "title": "Kipling poker 17th",
        "description": "",
        "start_at": "2026-05-17T20:00:00Z",
        "end_at":   "2026-05-18T02:00:00Z",
        "color": "#2563eb",
        "is_poker": true,
        "rsvp_yes_count": 5,
        "rsvp_no_count": 1,
        "rsvp_maybe_count": 0,
        "created_at": "2026-04-26T20:06:26Z"
      }
    ]
  }
}
```

`start_at` and `end_at` are ISO-8601 UTC instants ending in `Z`. **All-day events** (events scheduled with a date but no time) return a date-only string (`"2026-05-17"`) in the same field instead of a full instant — this is how callers can tell the two apart. `end_at` is `null` for events without a configured end. `created_at` is also UTC.

#### `GET /api/v1/posts?limit=20&offset=0`

League posts (announcements / news). Excludes hidden posts, drafts, future-scheduled posts, and the league's rules post. Sorted by pinned, then created_at descending.

| Query param | Default | Notes |
|---|---|---|
| `limit` | 20 | Max 50 |
| `offset` | 0 | For pagination |

```json
{
  "ok": true,
  "data": {
    "total": 12,
    "limit": 20,
    "offset": 0,
    "count": 2,
    "posts": [
      {
        "id": 7,
        "title": "90min Turbo Blinds",
        "content_html": "<h1>8-Player 90-Minute Tournament</h1>...",
        "author_display_name": "Bryce",
        "created_at": "2026-04-27 20:39:53",
        "share_url": "https://your-site.com/post_public.php?token=5de463f570b59b21da4d67c1351fb4ad"
      }
    ]
  }
}
```

`content_html` is sanitized HTML (the same pipeline used when posts render in the UI). Posts that have a public share link include `share_url`; posts without sharing enabled omit that field.

#### `GET /api/v1/rules`

The league's rules post. The rules post is a special post (one per league at most) that lives behind a dedicated UI button in-app and is excluded from `/api/v1/posts`; this endpoint is the way to read it.

```json
{
  "ok": true,
  "data": {
    "rules": {
      "id": 42,
      "title": "House Rules",
      "content_html": "<h2>Buy-in</h2><p>...</p>",
      "author_display_name": "Bryce",
      "created_at": "2025-11-12 03:14:00"
    }
  }
}
```

When the league has not configured a rules post yet, `rules` is `null`:

```json
{ "ok": true, "data": { "rules": null } }
```

`content_html` is sanitized HTML, same pipeline as `/posts`. Hidden rules posts are treated as absent.

#### `POST /api/v1/users`

**Requires the `write` scope.** Creates a user and adds them to the key's league. Mirrors the walk-in registration flow: a soft account is created with `must_change_password=1` and `email_verified=0`, and (unless suppressed) a verification email or SMS is sent so the new user can later set a password and sign in.

The endpoint is **idempotent on email/phone** — replaying the same request body returns the existing `user_id`, ensures league membership, and skips the verification send. Sister sites can retry safely without creating duplicate accounts.

**Request body** (JSON):

| Field | Type | Notes |
|---|---|---|
| `display_name` | string, required | Used to derive a username when `username` is omitted. |
| `email` | string, optional | At least one of `email` or `phone` is required. |
| `phone` | string, optional | Normalized to `XXX-XXX-XXXX` for US numbers; international numbers stored as entered. |
| `username` | string, optional | 3–30 chars, letters/numbers/underscores. If omitted, derived from `display_name` with a numeric suffix on collision. |
| `verification_method` | string, optional | One of `email`, `sms`, `whatsapp`, `none`. One-shot — used only at signup. Default: `email` if email provided, else `sms`. Use `none` if your site handles onboarding itself. |
| `preferred_contact` | string, optional | One of `email`, `sms`, `whatsapp`, `both`, `none`. Sets the user's ongoing notification channel (the same setting they'd pick on `/settings.php`). Default: matches `verification_method`. **Ignored on existing-user replays** so a leaked write key cannot mute or re-route real accounts. |

`verification_method` is consulted only at signup; `preferred_contact` is what the system reads every time it sends a notification afterwards. They can differ — e.g. verify by SMS but prefer email going forward.

**Successful response** (HTTP 200):

```json
{
  "ok": true,
  "data": {
    "user_id": 245,
    "username": "API_Test",
    "created": true,
    "league_member_added": true,
    "verification_sent": true,
    "preferred_contact": "email",
    "preferred_contact_updated": true
  }
}
```

- `created` is `true` when a new user row was inserted, `false` when an existing user with that email or phone was found.
- `league_member_added` is `true` when the user was newly added to this key's league, `false` when they were already a member.
- `verification_sent` is `false` for existing-user replays and when `verification_method=none`.
- `preferred_contact` echoes the resolved value (caller-supplied or default). For existing-user replays, it's the user's current stored preference.
- `preferred_contact_updated` is `true` only when a new user was created. Always `false` on replays — preferences on existing accounts are intentionally not overwritten.

**Error responses:**

| HTTP code | Meaning |
|---|---|
| `400` | Invalid request body (missing `display_name`, no email or phone, malformed values, unknown `verification_method` or `preferred_contact`). |
| `401` | Missing, malformed, or revoked API key. |
| `403` | API key lacks the `write` scope. |
| `409` | `username_taken` (when caller passed an explicit `username` that's in use) or `contact_taken` (UNIQUE constraint race on email or phone). |
| `429` | Rate limit exceeded — 60 successful creations per hour per key. |

**Examples:**

```bash
# Create with email
curl -X POST -H 'Authorization: Bearer YOUR_WRITE_KEY' \
     -H 'Content-Type: application/json' \
     -d '{"display_name":"Alice","email":"alice@example.com"}' \
     https://your-site.com/api/v1/users

# Create with phone (sends SMS code)
curl -X POST -H 'Authorization: Bearer YOUR_WRITE_KEY' \
     -H 'Content-Type: application/json' \
     -d '{"display_name":"Bob","phone":"281-555-1234","verification_method":"sms"}' \
     https://your-site.com/api/v1/users

# Suppress verification — your site handles onboarding
curl -X POST -H 'Authorization: Bearer YOUR_WRITE_KEY' \
     -H 'Content-Type: application/json' \
     -d '{"display_name":"Carol","email":"carol@example.com","verification_method":"none"}' \
     https://your-site.com/api/v1/users
```

#### `POST /api/v1/events`

**Requires the `write` scope.** Creates an event in the API key's league. Visibility is forced to `'league'`; `league_id` is implicit. The event's creator is set to the league owner so the event has a real manager. A walk-in token is generated immediately and returned as `walkin_url` so sister sites can show a QR right away. Side effects mirror the in-app calendar form: optional poker_sessions row, invitee inserts (always approved), beyond-capacity poker invitees marked waitlisted, reminder notifications queued.

**Request body** (JSON):

| Field | Type | Notes |
|---|---|---|
| `title` | string, required | Max 200 chars. |
| `start_at` | string, required | ISO-8601 UTC instant (`"2026-05-17T20:00:00Z"`) **or** a date-only string (`"2026-05-17"`) for all-day events. |
| `end_at` | string, optional | Same format as `start_at`. |
| `description` | string, optional | Plain text. |
| `color` | hex string, optional | One of `#2563eb`, `#16a34a`, `#dc2626`, `#d97706`, `#7c3aed`, `#0891b2`, `#db2777`. Default `#2563eb`. |
| `is_poker` | boolean, optional | Default `false`. When `true`, a `poker_sessions` row is auto-created and the waitlist applies. |
| `requires_approval` | boolean, optional | Default `false`. Gates self-signups via walk-in / RSVP. Does **not** affect API-supplied `invitees` (those are always approved). |
| `rsvp_deadline_hours` | integer, optional | Hours before `start_at` when RSVPs lock. |
| `waitlist_enabled` | boolean, optional | Default `true`. Only meaningful when `is_poker=true`. |
| `reminders_enabled` | boolean, optional | Default `true`. |
| `reminder_offsets` | array of integers, optional | Minutes before `start_at` for each reminder send. Defaults to the site default (typically `[2880, 720]` = 48h and 12h). |
| `poker_buyin` | number, optional | Dollars (e.g. `20.00`). Used only when `is_poker=true`. |
| `poker_tables` | integer, optional | Default 1. |
| `poker_seats` | integer, optional | Default 8. Capacity = `poker_tables * poker_seats`. |
| `poker_game_type` | string, optional | `'tournament'` or `'cash'`. Default `'tournament'`. |
| `invitees` | array, optional | Each entry: `{user_id: int, manager?: bool}`. Each `user_id` must already be a member of this league (call `POST /users` first to create + add them). All inserted with `approval_status='approved'`. Capped at 200. |

**Successful response** (HTTP 200):

```json
{
  "ok": true,
  "data": {
    "event_id": 67,
    "title": "Kipling poker 17th",
    "start_at": "2026-05-17T20:00:00Z",
    "end_at": "2026-05-18T02:00:00Z",
    "league_id": 6,
    "visibility": "league",
    "is_poker": true,
    "walkin_url": "https://your-site.com/walkin.php?event_id=67&token=ABCD1234...",
    "invitees_added": 2,
    "created_at": "2026-04-30T17:23:11Z"
  }
}
```

- `walkin_url` is the public registration link (the same URL the in-app QR code generates). You can show it as a QR on a check-in screen, share it in your event description, or print it.
- `invitees_added` counts the rows actually inserted. If `is_poker=true` and `waitlist_enabled=true`, invitees beyond `poker_tables * poker_seats` are inserted with `approval_status='waitlisted'` (still counted in `invitees_added`).

**Error responses:**

| HTTP code | Meaning |
|---|---|
| `400` | Invalid request body. Examples: missing `title`, unparseable `start_at`, unknown `color` / `recurrence`, `recurrence_end` missing when recurrence is set, invitee user_id not in this league. |
| `401` | Missing, malformed, or revoked API key. |
| `403` | API key lacks the `write` scope. |
| `404` | The league bound to the key was deleted. |
| `405` | Method not allowed. |
| `429` | Rate limit exceeded — 60 successful event creations per hour per key. |

**Examples:**

```bash
# Single-evening poker night
curl -X POST -H 'Authorization: Bearer YOUR_WRITE_KEY' \
     -H 'Content-Type: application/json' \
     -d '{
       "title": "Friday $20 NLH",
       "start_at": "2026-05-22T23:00:00Z",
       "end_at":   "2026-05-23T03:00:00Z",
       "is_poker": true,
       "poker_buyin": 20,
       "poker_tables": 2,
       "poker_seats": 8
     }' \
     https://your-site.com/api/v1/events

# All-day calendar marker, no poker
curl -X POST -H 'Authorization: Bearer YOUR_WRITE_KEY' \
     -H 'Content-Type: application/json' \
     -d '{"title":"League holiday","start_at":"2026-12-25"}' \
     https://your-site.com/api/v1/events

# With invitees (must already be league members; POST /users first if not)
curl -X POST -H 'Authorization: Bearer YOUR_WRITE_KEY' \
     -H 'Content-Type: application/json' \
     -d '{
       "title": "Members-only tourney",
       "start_at": "2026-06-01T00:00:00Z",
       "is_poker": true,
       "invitees": [
         {"user_id": 12},
         {"user_id": 34, "manager": true}
       ]
     }' \
     https://your-site.com/api/v1/events
```

### API Response Shape

Every response uses the same envelope:

- **Success**: `{"ok": true, "data": ...}` — HTTP 200.
- **Error**: `{"ok": false, "error": "human-readable message"}` — HTTP 400/401/403/404/405/409/429.

This matches the shape used by every internal `_dl.php` endpoint, so if you've integrated against any of those before, the parser is the same.

### API Errors

| HTTP code | Meaning |
|---|---|
| `400` | Bad parameter or invalid request body. Examples: `from` after `to`; window over 366 days; non-hex characters in the key; missing `display_name` on `POST /users`. |
| `401` | Missing, malformed, or revoked API key. |
| `403` | API key lacks the scope required by the endpoint (e.g. a read-only key calling `POST /users`). |
| `404` | The league bound to the key was deleted (key is dead, mint a new one for a different league). |
| `405` | Method not allowed for this endpoint. |
| `409` | Conflict on `POST /users` — `username_taken` or `contact_taken`. |
| `429` | Per-key write rate limit exceeded (60 successful user creations per hour). |

### API Caching

Successful responses include `Cache-Control: public, max-age=60`. Consumers should cache for at least one minute. The data does not change every second; hammering the API with one request per page view is wasteful.

CORS is allowed from any origin (`Access-Control-Allow-Origin: *`), so a JavaScript client running in a browser can call the API directly. Note: putting the key in browser-side JS exposes it to anyone who views your page source. Do that only if you accept the consequences of revocation when (not if) the key leaks.

There is no hard rate limit yet, but every call is logged with the key id, IP, path, and status. Abusive patterns will result in the key being revoked.

### API Examples

**PHP (server-side, recommended):**

```php
$key = 'YOUR_64_CHAR_HEX_KEY';
$ctx = stream_context_create([
    'http' => [
        'header' => "Authorization: Bearer $key\r\n",
        'timeout' => 5,
    ],
]);
$resp = file_get_contents('https://your-site.com/api/v1/events', false, $ctx);
$data = json_decode($resp, true);
if ($data['ok'] ?? false) {
    foreach ($data['data']['events'] as $event) {
        echo htmlspecialchars($event['title']) . "<br>";
    }
}
```

**JavaScript (browser, only if the key can be public):**

```javascript
fetch('https://your-site.com/api/v1/posts?limit=5', {
    headers: { 'Authorization': 'Bearer YOUR_KEY' }
})
.then(r => r.json())
.then(({ ok, data, error }) => {
    if (!ok) { console.error(error); return; }
    data.posts.forEach(p => console.log(p.title));
});
```

**curl one-liners** (handy for testing):

```bash
curl -H 'Authorization: Bearer YOUR_KEY' https://your-site.com/api/v1/league
curl -H 'Authorization: Bearer YOUR_KEY' https://your-site.com/api/v1/members
curl -H 'Authorization: Bearer YOUR_KEY' 'https://your-site.com/api/v1/events?from=2026-01-01&to=2026-12-31'
curl -H 'Authorization: Bearer YOUR_KEY' 'https://your-site.com/api/v1/posts?limit=5'
curl -H 'Authorization: Bearer YOUR_KEY' https://your-site.com/api/v1/rules
```

### API Revoking a Key

If a key leaks, click **Revoke** on its row in the league's API tab. Consumers using that key start getting `401` responses immediately. The revocation is permanent (soft-delete: the row stays in the database with `revoked_at` set, so audit logs continue to make sense).

To rotate a key: mint a new key with a different label, update the consumer to use the new key, then revoke the old one. Keys do not expire on their own — rotate them on whatever cadence makes sense for your operation (annual is reasonable for a low-traffic sister site).

---

## Security

Game Night includes several security measures:

- **Password hashing** — All passwords stored with bcrypt.
- **CSRF protection** — Every form includes a CSRF token.
- **Prepared statements** — All database queries use PDO prepared statements (no SQL injection).
- **HTML sanitization** — Post content is sanitized to prevent XSS.
- **Security headers** — CSP, X-Frame-Options, X-Content-Type-Options, and more.
- **Session security** — HTTPOnly cookies, SameSite=Lax, session regeneration on login.
- **Last admin protection** — The last admin account cannot be demoted or deleted.
- **File upload validation** — MIME type checking on all uploads.

---

## Troubleshooting

### HTTP 500 on fresh deploy
Check file permissions on the `db/` directory. It must be writable by `www-data`:
```bash
chown -R www-data:www-data db/ www/uploads/
```

### Emails not sending
1. Verify **Enable Notifications** is on in Admin > General.
2. Check your SMTP credentials in Admin > Email.
3. Use **Send Test Email** to diagnose. Test emails bypass the notification toggle.
4. Check the Activity Log for error messages.

### SMS not sending
1. Verify your SMS provider credentials in Admin > SMS.
2. Use **Send Test SMS** to diagnose.
3. Ensure phone numbers are in the correct format (the system normalizes to E.164).

### Calendar not showing
Check Admin > General > **Enable Calendar** is toggled on.

### Users can't create events
Enable **Allow Users to Create Events** in Admin > General.

### Forgot admin password
If you've lost access to the admin account, you can reset the database:
```bash
# Warning: this deletes all data
rm db/gamenight.db
# Restart the app — it will recreate the database with default admin/admin
docker compose restart
```

### Event times showing wrong
Check your timezone setting in Admin > General. All times are stored in UTC and converted for display using the configured timezone.
