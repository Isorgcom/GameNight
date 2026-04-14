# Game Night

A self-hosted PHP web application for organizing game night events with full poker tournament management. Members can register, RSVP to events on a shared calendar, read posts/announcements, and manage their profiles. Admins get a full dashboard for managing users, events, posts, and site settings.

## Features

- **User accounts** — registration with email verification, login with remember me, forgot/reset password, brute force protection
- **Calendar** — create and RSVP to events, view upcoming events; optionally allow registered users to create and manage their own events
- **Posts** — rich-text announcements with comment support
- **Poker tournament management** — full check-in dashboard for tournaments and cash games with buy-ins, rebuys, add-ons, eliminations, and prize pool tracking
- **Table management** — auto-assign players to tables, table view with move/balance controls, break up tables, seats-per-table limits, button/blind protection during rebalancing
- **Tournament timer** — full-screen blind level timer with remote viewer (QR code), remote control for managers, customizable blind structures with presets, configurable sounds, wake lock for mobile devices
- **Payout calculator** — ICM (Malmuth-Harville), Standard, and Chip Chop split methods for end-of-tournament deal making
- **Prize payout display** — live payout structure on the timer screen, updates dynamically as the pool changes
- **Walk-up QR registration** — iPad/tablet display page with QR code for walk-up player registration, shows table assignment on success
- **Player stats & leaderboard** — per-player lifetime stats (games, wins, win rate, best/avg finish, weighted score) and a leaderboard across all users, filterable by date range (presets or custom from/to)
- **Admin panel** — manage users (with account settings like email verification, password reset, notification preferences), posts, events, and all site settings
- **Email** — transactional mail via SMTP (SendGrid or any provider)
- **SMS** — multi-provider notifications with two-way RSVP (see [SMS](#sms) below)
- **WhatsApp** — event notifications via Meta WhatsApp Cloud API with two-way RSVP
- **One-click RSVP** — invitees can RSVP directly from email links without logging in
- **Branding** — custom banner/header images, nav colors, site name
- **Security** — CSRF protection, rate limiting, credential encryption at rest, secure session cookies, CSP headers, HSTS
- **SQLite** — zero-config database, stored outside the web root

## Stack

- PHP 8.x + Apache
- SQLite (via PDO)
- [PHPMailer](https://github.com/PHPMailer/PHPMailer)
- [Jodit](https://xdsoft.net/jodit/) / [Quill](https://quilljs.com/) rich-text editors
- [qrcode-generator](https://github.com/kazuhikoarase/qrcode-generator) — QR codes for remote timer/registration
- Vanilla JS — no frontend framework dependencies

## Docker Install (recommended)

This is the recommended way to run Game Night on a fresh server. These instructions assume you already have Docker and Nginx Proxy Manager running.

### Prerequisites

- Docker + Docker Compose installed on the server
- Nginx Proxy Manager running with its network named `npm_default`

If you need to set up Docker and Nginx Proxy Manager first, run `server-prep.sh` as root.

---

### 1. Clone the repository

Clone into whatever directory you prefer, for example:

```bash
git clone https://github.com/Isorgcom/GameNight.git ~/docker/GameNight
cd ~/docker/GameNight
```

All subsequent steps use your current directory (`cd` into the repo first), so the location doesn't matter.

### 2. Create the config file

```bash
cp config/config.example.php config/config.php
```

`config.php` is gitignored. Edit it to set `DB_PATH` if you need a non-default database location. All email and SMS settings are configured through the admin panel (Site Settings → Email / SMS).

### 3. Fix directory permissions

The `db/` directory is gitignored and won't exist after a fresh clone. Create it and set ownership so `www-data` (the Apache user inside the container) can write to it:

```bash
mkdir -p db uploads
chown -R www-data:www-data db/ uploads/
```

> **Important:** Do this step after every fresh clone. If the `db/` directory is owned by root, Apache cannot write the SQLite database and the site will return HTTP 500.

### 4. Build and start the container

```bash
docker compose up -d --build
```

### 5. Connect to Nginx Proxy Manager

Open your Nginx Proxy Manager admin UI and go to **Proxy Hosts → Add Proxy Host**, then set:

- **Domain Names:** your domain (e.g. `gamenight.example.com`)
- **Scheme:** `http`
- **Forward Hostname/IP:** `gamenight` ← the container name
- **Forward Port:** `80`
- Enable **"Block Common Exploits"**
- On the **SSL** tab, request a Let's Encrypt certificate

The `gamenight` container and Nginx Proxy Manager are both on the `npm_default` Docker network, so NPM can reach the container by name regardless of what ports NPM is exposed on.

### 6. First login

The database schema is created automatically on the first request. Log in with:

- **Email:** `admin@localhost`
- **Password:** `admin`

You will be redirected to set a new password before accessing the site.

---

### Updating

To pull new code and rebuild:

```bash
cd /root/docker/GameNight
git pull
docker compose down
docker compose up -d --build
```

### Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| HTTP 500 on every page | `db/` owned by root | `chown -R www-data:www-data db/` |
| HTTP 500 — `Invalid command 'RewriteEngine'` | Old image missing `mod_rewrite` | Rebuild: `docker compose up -d --build` |
| NPM can't reach container | Container not on `npm_default` network | Check `docker-compose.yml` has `npm_default` external network |

---

## Manual Setup (without Docker)

### 1. Configuration

Copy the example config and fill in your values:

```bash
cp config/config.example.php config/config.php
```

Edit `config/config.php` — set `DB_PATH` if you need a non-default database location. This file is gitignored and should never be committed. Email (SMTP) and SMS settings are managed through the admin panel after first login.

### 2. Web root

Point your web server (Apache/Nginx) at the `www/` directory. The `config/` and `db/` directories must live **outside** the web root.

Expected directory layout on the server:

```
/var/config/config.php   ← credentials (outside web root)
/var/db/app.db           ← SQLite database (outside web root)
/var/www/html/           ← contents of www/
/var/www/html/uploads/   ← runtime user uploads (writable by www-data)
```

### 3. Permissions

The web server user (`www-data`) needs write access to:

- `/var/db/` — SQLite database
- `/var/www/html/uploads/` — user-uploaded files

### 4. First run

The database schema is created automatically on the first request. Log in with the default admin credentials set in `config.php` and update them immediately via the admin panel.

## SMS

Game Night supports SMS notifications through multiple providers. Configure your provider in **Admin Settings > Communication > SMS**.

### Supported Providers

| Provider | Send Cost | Receive Cost | Number Cost | Notes |
|---|---|---|---|---|
| **Twilio** | ~$0.0079/msg | ~$0.0075/msg | $1.15/mo | Most popular, official SDK |
| **Vonage (Nexmo)** | ~$0.0068/msg | ~$0.0050/msg | $1.00/mo | Mature API |
| **Plivo** | ~$0.0050/msg | Free inbound | $0.80/mo | Cheapest for two-way |
| **Telnyx** | ~$0.0040/msg | ~$0.0020/msg | $1.00/mo | Cheapest at volume |

### What SMS Does

- **Event invites** — users are notified via their preferred method (email, SMS, or both) when invited to an event
- **RSVP confirmations** — event creators are notified when someone RSVPs
- **Event changes** — existing invitees are notified when an event is updated (when "notify invitees" is checked)
- **Two-way RSVP** — users can reply YES, NO, or MAYBE to an SMS to update their RSVP

### User Preferences

Users choose their notification method in **My Settings > Preferred Contact Method**:
- **Email** — email only
- **SMS** — text message only
- **Email & SMS** — both
- **None** — no notifications

Admins can override a user's preference from the user edit page.

## User-Created Events

By default only admins can create events. To let registered users create their own events, enable **"Allow users to create events"** in **Admin Settings > General**.

When enabled:

- Registered users see the **+ Add Event** button and can create events, invite other users, and set RSVP statuses
- Users can only **edit and delete their own events** — they cannot modify events created by others or by admins
- Other users' **phone numbers and emails are hidden** from the invite picker — only usernames are shown
- Users can still provide an email for custom (non-registered) invitees so notifications are sent
- Contact info is auto-filled from user profiles on the server side, so invite notifications still work

Admins retain full control over all events regardless of this setting.

### Two-Way SMS Setup

To enable inbound RSVP replies, configure your provider's inbound webhook URL to:

```
https://yourdomain.com/sms_webhook.php
```

When a user replies to an SMS notification with YES, NO, or MAYBE, the webhook:
1. Looks up the user by phone number
2. Finds their nearest upcoming event invite
3. Updates the RSVP
4. Sends a confirmation reply
5. Notifies the event creator

## Branding

Place your banner images in `uploads/` at the repo root:

| File | Used for |
|---|---|
| `uploads/banner.png` | Small banner / favicon area |
| `uploads/header_banner.png` | Top-of-page header image |

These are committed to the repo so branding deploys with the code.

## License

See [LICENSE](LICENSE).
