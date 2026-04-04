# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Running the App

```bash
# Start (recommended)
docker compose up -d --build

# Update after git pull
docker compose down && docker compose up -d --build
```

First-time setup: copy `config/config.example.php` to `config/config.php`. The database schema is auto-created on first request. Default login: `admin@localhost` / `admin`.

There are no tests and no build step — this is a runtime PHP application.

## Stack

- **Backend**: PHP 8.x + Apache (mod_rewrite)
- **Database**: SQLite via PDO — file path from `DB_PATH` in `config/config.php`
- **Frontend**: Server-rendered HTML, vanilla JS, single stylesheet (`www/style.css`)
- **Email**: PHPMailer (downloaded at container start by `docker-entrypoint.sh`)
- **Rich text**: Jodit + Quill editors (also downloaded at container start)
- **SMS**: Twilio, Plivo, Telnyx, or Vonage (configured via admin UI)

## Architecture

**Monolithic server-rendered PHP.** Each feature is a `.php` page with a corresponding `_dl.php` data endpoint that handles AJAX POST requests and returns JSON.

| File pattern | Purpose |
|---|---|
| `www/*.php` | Full-page HTML views |
| `www/*_dl.php` | AJAX data endpoints (POST → JSON) |
| `www/db.php` | SQLite schema, migrations, and all DB helpers |
| `www/auth.php` | Session management, `require_login()`, security headers |
| `www/mail.php` | PHPMailer wrapper |
| `www/sms.php` | SMS provider abstraction |
| `www/upload.php` | File upload handler; stores files under `uploads/` |
| `www/_nav.php` | Shared navigation partial (included in page views) |
| `www/_footer.php` | Shared footer partial |
| `www/cron.php` | Scheduled tasks (called by cron/container timer) |
| `config/config.php` | Only required config: `DB_PATH` (gitignored) |

**Key DB helpers** (all in `db.php`):
```php
get_db()                  // Singleton PDO instance
get_setting($key)         // Read from site_settings table
set_setting($key, $val)   // Write to site_settings table
db_log_activity(...)      // Audit log
db_init()                 // Auto-creates schema + runs migrations on first call
sanitize_html($html)      // Strip disallowed tags before storing rich text
normalize_phone($phone)   // Normalize to E.164 format
build_event_by_date(...)  // Expand recurring events into date-keyed array
```

**Auth helpers** (in `auth.php`):
```php
require_login()           // Redirects to /login.php if unauthenticated
current_user()            // Returns user row array or null
$user['role']             // 'admin' or 'user'
```

## Database Migrations

Schema and migrations live entirely in `db_init()` inside `www/db.php`. New columns are added with `try/catch` around `ALTER TABLE` so they are safe to run against existing databases. No external migration tool is used.

## Security Conventions

- All forms use `csrf_token()` / `csrf_verify()` — always include CSRF tokens in new forms
- All DB queries use PDO prepared statements — never interpolate user input into SQL
- Security headers (CSP, X-Frame-Options, etc.) are set in `auth.php` and applied globally
- RSVP tokens allow one-click RSVP without login (stored in `event_invites.rsvp_token`)

## Dates & Times

- All dates stored in UTC
- Displayed in the site-configured timezone via `date_default_timezone_set()` applied at page load
- Timezone is stored in `site_settings` and managed through the admin panel

## Deployment Notes

- Production server: see memory/reference for IP — SSH access via key auth
- `www/` is the web root; `config/` and `db/` must stay outside it
- The container connects to the `npm_default` Docker network for Nginx Proxy Manager
- `db/` and `uploads/` directories must be owned by `www-data`
- HTTP 500 on fresh deploy usually means wrong ownership on `db/`

## Version

Tracked manually in `www/version.php`. Bump the version number when committing significant changes.
