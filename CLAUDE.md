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

## Dev → Git → Live Flow

There are two local clones of this repo:

| Path | Container | URL | Role |
|---|---|---|---|
| `/home/bryce/Claude/GameNight` (this repo) | `gamenight` | local | **Edit here.** Source of truth for git. |
| `/home/bryce/Claude/GameNight-dev` | `gamenight-dev` | http://localhost:8080 | **Test target.** Mirrors edits from GameNight before they ship. |

**Per-edit sync rule:** every time you Edit/Write a file under this repo, immediately mirror it to the same relative path in `/home/bryce/Claude/GameNight-dev/`. Mirror only the files you touched — do **not** bulk-rsync, because `GameNight-dev` legitimately differs in dev-only paths: `docker-compose.yml` (dev container name + port), `config/config.php` (gitignored local config), `db/` and `uploads/` (runtime state), and `vendor/` / `www/phpadmin/phpliteadmin.php` (downloaded by `docker-entrypoint.sh`). Never mirror those paths or anything under `.git/`.

If `GameNight` and `GameNight-dev` diverge on tracked source files, that almost always means one side hasn't pulled from origin — fix it with `git pull` rather than copying files around. The per-edit mirror is for **uncommitted** edits in transit, not for reconciling missed commits.

**Push gate:** do not `git push` to origin until the user has tested at http://localhost:8080 and explicitly confirmed. The full sequence is:

1. Edit in `GameNight` → mirror to `GameNight-dev` (same edit, same path).
2. If the dev container is down or the change is server-side (PHP), restart it: `cd /home/bryce/Claude/GameNight-dev && docker compose up -d --build`. For pure PHP/static edits the bind-mount picks them up live — no rebuild needed.
3. User verifies at http://localhost:8080.
4. On confirmation: `git add` + `git commit` + `git push` from `GameNight`.
5. Tag the release on `main` (see **Release Tags**) and push the tag.
6. Deploy to live: SSH to the production server and `git pull`; never scp from Windows (CRLF).

## Version

Tracked manually in `www/version.php`. **Bump exactly once per `git push`** — immediately before the commit that ships a change. Do not bump during in-dev troubleshooting iterations against the local `gamenight-dev` container; the version is a release marker for shipped commits, not a build counter for intermediate fix attempts.

## Release Tags

Every version bump that reaches `main` gets an annotated tag on the commit that ships it, pushed to origin:

```bash
git tag -a v0.2057 <commit> -m "v0.2057 — short summary of the release"
git push origin v0.2057
```

Tag name matches `APP_VERSION` with a `v` prefix (`0.2057` → `v0.2057`). Do this as part of the push routine, not later — tags are how "what exactly was running when this broke?" gets answered, and how a rollback point is found. Tag the squash commit on `main`, never a branch tip.

(Tags before `v0.0155` are historical and irregular, and `gamenight-standalone-*` belongs to the tournament-timer fork, not this release line.)

## Branches

Feature/fix work happens on a branch off `main` (`GameNight-<Topic>`), is dev-tested, then **squash-merged** to `main` so `main` keeps one commit per release.

Because the merge squashes, the branch's individual commits exist nowhere else — and this repo does not use pull requests, so there is no PR page preserving them. Before deleting a merged branch, archive it:

```bash
git tag -a archive/<topic> origin/GameNight-<Topic> -m "Archived tip of GameNight-<Topic> — squash-merged as <sha> / vX.Y"
git push origin archive/<topic>
# verify the tag is on the REMOTE before deleting anything
git ls-remote --tags origin | grep archive/<topic>
git push origin --delete GameNight-<Topic>
```

Never delete a branch until the user has confirmed testing is done — merging it "for more testing" is a promotion, not sign-off. Restore an archived branch any time with `git checkout -b <name> archive/<topic>`.

## Changelog

`CHANGELOG.md` updates ride in the same commit as the code that introduced them — never as a follow-up. When you bump `www/version.php` for a push, also add a new `## [vX.Y] — YYYY-MM-DD` block at the top of `CHANGELOG.md` (above the most recent existing entry), grouped under **Security / Added / Changed / Fixed / Infrastructure** as applicable. Match the long-form prose style of existing entries: lead with a bolded one-line summary, then 2–6 sentences explaining the *why*, the affected files/identifiers, and any operator notes. Stage `CHANGELOG.md` with the code files and `www/version.php` in the same `git add`.
