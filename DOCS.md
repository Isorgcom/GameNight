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
