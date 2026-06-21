# Game Night

A self-hosted PHP web application for organizing game night events with full poker tournament and cash-game management. Members can register, RSVP to events on a shared calendar, join leagues, read posts/announcements, vote in event polls, and manage their profiles. Admins get a full dashboard for managing users, events, posts, leagues, and site settings.

## Features

- **User accounts** — registration with email verification, login with remember me, forgot/reset password, brute force protection
- **Two-factor authentication** — per-user opt-in 2FA via authenticator app (TOTP) or SMS, with one-time recovery codes
- **Calendar** — create and RSVP to events, view upcoming events; optionally allow registered users to create and manage their own events
- **Leagues** — persistent member communities with owner/manager/member roles, league-scoped posts and stats, member invites and join requests (with optional approval), and per-league API keys (see [Leagues](#leagues) below)
- **Posts** — rich-text announcements with comment support
- **Event polls** — managers create multi-question polls sent to guests who RSVP'd Yes or Maybe; guests vote via tokenized email/SMS/WhatsApp links and results stay anonymous (counts only)
- **Poker tournament & cash game management** — full check-in dashboard for both formats: buy-ins, rebuys, add-ons, eliminations and prize-pool tracking for tournaments; cash-in/cash-out with money-in-play and on-table tracking for cash games; plus a per-session activity log recording every buy-in, cash-out, add, rebuy, and elimination with who did it and when
- **Table management** — auto-assign players to tables, table view with move/balance controls, break up tables, seats-per-table limits, button/blind protection during rebalancing
- **Tournament timer** — full-screen blind level timer with remote viewer (QR code), remote control for managers, customizable blind structures with presets, configurable sounds, wake lock for mobile devices, and Chromecast cast-to-TV
- **Payout calculator** — ICM (Malmuth-Harville), Standard, and Chip Chop split methods for end-of-tournament deal making
- **Prize payout display** — live payout structure on the timer screen, updates dynamically as the pool changes
- **Walk-up QR registration** — iPad/tablet display page with QR code for walk-up player registration, shows table assignment on success
- **Player stats & leaderboard** — per-player lifetime stats (games, wins, win rate, best/avg finish, weighted score) and a leaderboard across all users, filterable by date range (presets or custom from/to)
- **Contacts** — personal address book for non-registered invitees, auto-imported from event invites, with CSV import/export
- **REST API** — league-scoped read/write JSON API (v1) authenticated with API keys, exposing league info, members, events, and posts (see [Leagues](#leagues) below)
- **Admin panel** — manage users (with account settings like email verification, password reset, notification preferences), posts, events, leagues, and all site settings, plus a live site-activity snapshot
- **Email** — transactional mail via SMTP (SendGrid or any provider)
- **SMS** — multi-provider notifications with two-way RSVP (see [SMS](#sms) below)
- **WhatsApp** — event notifications via Meta WhatsApp Cloud API with two-way RSVP
- **Queued notifications** — event notifications dispatched via email/SMS with a fast background drain and provider rate-limit protection
- **One-click RSVP** — invitees can RSVP directly from email links without logging in
- **Branding** — custom banner/header images, nav colors, site name
- **Security** — CSRF protection, optional two-factor authentication, rate limiting, credential encryption at rest, secure session cookies, CSP headers, HSTS
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
chown -R www-data:www-data db/ uploads/ www/timer_themes/
```

> **Important:** Do this step after every fresh clone. If the `db/` directory is owned by root, Apache cannot write the SQLite database and the site will return HTTP 500.

> **Note:** `www/timer_themes/` ships committed with the built-in timer theme presets, so it already exists after a clone. The `chown` above is only needed if admins will **upload** new theme presets from the browser; loading the built-in presets works read-only without it.

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

### Release flow (maintainer notes)

Local edits go through a staging container before they reach `main` or the live site:

1. Edit in the primary local clone (`~/Claude/GameNight`).
2. Mirror each touched file to the dev clone (`~/Claude/GameNight-dev`), which runs the `gamenight-dev` container at <http://localhost:8080>. Mirror per-file — never bulk-rsync — so dev's local experiments, downloaded `vendor/`, `phpadmin/`, `config/config.php`, and `db/` stay untouched.
3. Verify the change at <http://localhost:8080>. PHP/static edits update live via the bind-mount; if a rebuild is needed, run `docker compose up -d --build` inside `GameNight-dev`.
4. Only after the dev verification passes: commit and `git push` from the primary clone.
5. SSH to the production server and run the `git pull` / rebuild block above.

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
- `/var/www/html/timer_themes/` — only if admins will upload timer theme presets from the browser (the built-in presets load read-only without it)

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

## Leagues

Leagues are persistent member communities that sit alongside the shared calendar. Each league has **owner / manager / member** roles, its own posts and rules, and tracks tournament stats and attendance for its members.

- **Joining** — browse public leagues and request to join (owners/managers can require approval), or accept an emailed/SMS invite link. Pending contacts are auto-upgraded to full members when they sign up.
- **Members** — owners and managers manage the roster, promote managers, and export membership as CSV.
- **Posts & rules** — league-scoped announcements with comments, plus a pinned rules post.

### REST API

Each league can expose a versioned, read-only-by-default JSON API (write access is opt-in per key). League owners mint API keys from the league's **API** tab; site admins can audit and revoke any key from the admin panel.

- **Base path:** `/api/v1/` (the index endpoint lists available routes)
- **Auth:** API key as a bearer token (`Authorization: Bearer <key>`) or `?key=` query parameter
- **Scopes:** `read` or `read,write`, with per-key rate limits on mutations
- **Exposes:** league summary, members (linked users and pending contacts), events with RSVP counts, posts, and rules

This is what lets a sister site embed a league's upcoming events, roster, or posts.

## Branding

Place your banner images in `uploads/` at the repo root:

| File | Used for |
|---|---|
| `uploads/banner.png` | Small banner / favicon area |
| `uploads/header_banner.png` | Top-of-page header image |

These are committed to the repo so branding deploys with the code.

## License

See [LICENSE](LICENSE).
