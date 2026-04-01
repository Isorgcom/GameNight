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

## Docker Install (recommended)

This is the recommended way to run Game Night on a fresh server. These instructions assume you already have Docker and Nginx Proxy Manager running.

### Prerequisites

- Docker + Docker Compose installed on the server
- Nginx Proxy Manager running with its network named `npm_default`

If you need to set up Docker and Nginx Proxy Manager first, run `server-prep.sh` as root.

---

### 1. Clone the repository

```bash
git clone https://github.com/Isorgcom/GameNight.git /root/docker/GameNight
cd /root/docker/GameNight
```

### 2. Create the config file

```bash
cp config/config.example.php config/config.php
```

`config.php` is gitignored. You can leave it as-is to configure email/SMS through the admin panel later, or fill in SMTP/Twilio credentials now.

### 3. Fix directory permissions

The database and uploads directories must be writable by `www-data` (the Apache user inside the container):

```bash
chown -R www-data:www-data /root/docker/GameNight/db/
chown -R www-data:www-data /root/docker/GameNight/uploads/
```

> **Important:** Do this step after every fresh clone. If the `db/` directory is owned by root, Apache cannot write the SQLite database and the site will return HTTP 500.

### 4. Build and start the container

```bash
docker compose up -d --build
```

### 5. Connect to Nginx Proxy Manager

1. Open Nginx Proxy Manager (default: `http://your-server:81`)
2. Go to **Proxy Hosts → Add Proxy Host**
3. Set:
   - **Domain Names:** your domain (e.g. `gamenight.example.com`)
   - **Scheme:** `http`
   - **Forward Hostname/IP:** `gamenight`  ← the container name
   - **Forward Port:** `80`
4. Enable **"Block Common Exploits"**
5. On the **SSL** tab, request a Let's Encrypt certificate

The `gamenight` container and Nginx Proxy Manager are both on the `npm_default` Docker network, so NPM can reach the container by name.

### 6. First login

The database schema is created automatically on the first request. Default admin credentials are set in `config.php` (`admin` / `changeme` unless you edited it). **Change your password immediately** via the admin panel.

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
