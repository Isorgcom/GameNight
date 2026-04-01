# Game Night

A self-hosted PHP web application for organizing game night events. Members can register, RSVP to events on a shared calendar, read posts/announcements, and manage their profiles. Admins get a full dashboard for managing users, events, posts, and site settings.

## Features

- **User accounts** — registration with email verification, login, forgot/reset password
- **Calendar** — create and RSVP to events, view upcoming events
- **Posts** — rich-text announcements with comment support
- **Admin panel** — manage users, posts, events, and all site settings
- **Email** — transactional mail via SMTP (SendGrid or any provider)
- **SMS** — optional notifications via Twilio
- **Branding** — custom banner/header images, nav colors, site name
- **SQLite** — zero-config database, stored outside the web root

## Stack

- PHP 8.x + Apache
- SQLite (via PDO)
- [PHPMailer](https://github.com/PHPMailer/PHPMailer)
- [Jodit](https://xdsoft.net/jodit/) / [Quill](https://quilljs.com/) rich-text editors

## Setup

### 1. Configuration

Copy the example config and fill in your values:

```bash
cp config/config.example.php config/config.php
```

Edit `config/config.php` — set your SMTP credentials, Twilio keys, and any default site settings. This file is gitignored and should never be committed.

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

## Branding

Place your banner images in `uploads/` at the repo root:

| File | Used for |
|---|---|
| `uploads/banner.png` | Small banner / favicon area |
| `uploads/header_banner.png` | Top-of-page header image |

These are committed to the repo so branding deploys with the code.

## Gitignored — do not commit

| Path | Reason |
|---|---|
| `config/config.php` | Contains SMTP/Twilio credentials |
| `.claude/` | Local Claude Code settings |
| `db/` | SQLite database |
| `www/uploads/` | Runtime user uploads |
| `www/_backups/` | Local backup snapshots |
| `www/vendor/` | PHP dependencies |

## License

See [LICENSE](LICENSE).
