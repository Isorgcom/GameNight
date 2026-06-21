<?php
require_once __DIR__ . '/version.php';
// Load credentials from config file stored outside the web root
if (file_exists('/var/config/config.php')) {
    require_once '/var/config/config.php';
}
if (!defined('DB_PATH')) {
    define('DB_PATH', '/var/db/app.db'); // fallback for local dev
}

// ── Encryption key for sensitive settings (auto-generated if missing) ────────
// Rate-limit constants
if (!defined('MAX_INVITEES_PER_EVENT'))    define('MAX_INVITEES_PER_EVENT', 200);
if (!defined('MAX_NOTIFICATIONS_PER_DAY')) define('MAX_NOTIFICATIONS_PER_DAY', 20);
if (!defined('DRAIN_PAUSE_ON_429_MINUTES')) define('DRAIN_PAUSE_ON_429_MINUTES', 15);
// Registration / walk-in attempts per IP per hour (accommodates typos during signup while
// still blocking brute-force / scraping; bumped from 5 → 20 in 0.18001).
if (!defined('MAX_REGISTRATION_ATTEMPTS_PER_HOUR')) define('MAX_REGISTRATION_ATTEMPTS_PER_HOUR', 20);
// Minimum password length used by every registration / password-change / reset flow.
if (!defined('MIN_PASSWORD_LENGTH')) define('MIN_PASSWORD_LENGTH', 8);
// Security rate limits (v0.19015)
if (!defined('MAX_COMMENTS_PER_HOUR'))                    define('MAX_COMMENTS_PER_HOUR', 20);
if (!defined('MAX_VERIFY_CODE_ATTEMPTS_PER_DAY'))         define('MAX_VERIFY_CODE_ATTEMPTS_PER_DAY', 20);
if (!defined('MAX_LOGIN_FAILURES_PER_USER_PER_HOUR'))     define('MAX_LOGIN_FAILURES_PER_USER_PER_HOUR', 5);
if (!defined('MAX_RSVP_TOKEN_FLIPS'))                     define('MAX_RSVP_TOKEN_FLIPS', 10);

if (!defined('APP_SECRET')) {
    $secretFile = dirname(DB_PATH) . '/.app_secret';
    if (file_exists($secretFile)) {
        define('APP_SECRET', trim(file_get_contents($secretFile)));
    } else {
        $generated = bin2hex(random_bytes(32));
        @file_put_contents($secretFile, $generated);
        @chmod($secretFile, 0600);
        define('APP_SECRET', $generated);
    }
}

function encrypt_value(string $plaintext): string {
    $key = hash('sha256', APP_SECRET, true);
    $iv = random_bytes(16);
    $encrypted = openssl_encrypt($plaintext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    return 'enc:' . base64_encode($iv . $encrypted);
}

function decrypt_value(string $stored): string {
    if (!str_starts_with($stored, 'enc:')) return $stored; // plaintext (not yet encrypted)
    $key = hash('sha256', APP_SECRET, true);
    $data = base64_decode(substr($stored, 4));
    if ($data === false || strlen($data) < 17) return '';
    $iv = substr($data, 0, 16);
    $encrypted = substr($data, 16);
    $decrypted = openssl_decrypt($encrypted, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    return $decrypted !== false ? $decrypted : '';
}

function get_db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dir = dirname(DB_PATH);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $pdo = new PDO('sqlite:' . DB_PATH);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA journal_mode=WAL');
        // Wait up to 5s on lock contention before returning SQLITE_BUSY. Without this,
        // concurrent writers (web request + forked cron_drain.php + scheduled cron tick)
        // race and immediately fail with "database is locked", silently dropping notifications.
        $pdo->exec('PRAGMA busy_timeout = 5000');
        // Enforce FK constraints. SQLite defaults to OFF for backwards compat, which made
        // every ON DELETE CASCADE in the schema decorative — orphans accumulated across
        // poker_sessions, blind_preset_levels, timer_state, etc. db_init() runs a one-shot
        // cleanup the first time it sees this PRAGMA enabled.
        $pdo->exec('PRAGMA foreign_keys = ON');
        db_init($pdo);
        // Apply stored timezone immediately so all date() calls use it
        $tz = $pdo->query("SELECT value FROM site_settings WHERE key='timezone'")->fetchColumn();
        if ($tz && in_array($tz, DateTimeZone::listIdentifiers())) {
            date_default_timezone_set($tz);
        }
    }
    return $pdo;
}

function db_init(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id                  INTEGER PRIMARY KEY AUTOINCREMENT,
            username            TEXT    UNIQUE NOT NULL,
            password_hash       TEXT    NOT NULL,
            email               TEXT,
            role                TEXT    NOT NULL DEFAULT 'user',
            created_at          DATETIME DEFAULT CURRENT_TIMESTAMP,
            last_login          DATETIME,
            must_change_password INTEGER NOT NULL DEFAULT 0
        );

        CREATE TABLE IF NOT EXISTS activity_log (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id    INTEGER NOT NULL,
            action     TEXT    NOT NULL,
            ip         TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS site_settings (
            key   TEXT PRIMARY KEY,
            value TEXT NOT NULL
        );

        CREATE TABLE IF NOT EXISTS posts (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            title      TEXT    NOT NULL,
            content    TEXT    NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS events (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            title       TEXT    NOT NULL,
            description TEXT,
            start_date  TEXT    NOT NULL,
            end_date    TEXT,
            start_time  TEXT,
            end_time    TEXT,
            color       TEXT    NOT NULL DEFAULT '#2563eb',
            created_by  INTEGER NOT NULL,
            created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (created_by) REFERENCES users(id)
        );

        CREATE TABLE IF NOT EXISTS comments (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            type       TEXT    NOT NULL,
            content_id INTEGER NOT NULL,
            user_id    INTEGER NOT NULL,
            body       TEXT    NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id)
        );

        CREATE INDEX IF NOT EXISTS idx_comments_lookup ON comments(type, content_id);

        CREATE TABLE IF NOT EXISTS event_messages (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            event_id        INTEGER NOT NULL,
            occurrence_date TEXT,
            token           TEXT    NOT NULL UNIQUE,
            subject         TEXT    NOT NULL,
            body_html       TEXT    NOT NULL,
            audience        TEXT    NOT NULL DEFAULT 'yes',
            created_by      INTEGER,
            created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (event_id) REFERENCES events(id)
        );

        CREATE INDEX IF NOT EXISTS idx_event_messages_event ON event_messages(event_id);

        CREATE TABLE IF NOT EXISTS event_exceptions (
            id       INTEGER PRIMARY KEY AUTOINCREMENT,
            event_id INTEGER NOT NULL,
            date     TEXT    NOT NULL,
            UNIQUE(event_id, date),
            FOREIGN KEY (event_id) REFERENCES events(id)
        );

        CREATE TABLE IF NOT EXISTS event_invites (
            id       INTEGER PRIMARY KEY AUTOINCREMENT,
            event_id INTEGER NOT NULL,
            username TEXT    NOT NULL,
            phone    TEXT,
            email    TEXT,
            rsvp     TEXT,
            FOREIGN KEY (event_id) REFERENCES events(id)
        );
    ");

    // Add must_change_password column if it doesn't exist yet (safe on existing DBs)
    try { $pdo->exec("ALTER TABLE users ADD COLUMN must_change_password INTEGER NOT NULL DEFAULT 0"); } catch (Exception $e) {}

    // Add pinned column to posts if it doesn't exist yet (safe on existing DBs)
    try { $pdo->exec("ALTER TABLE posts ADD COLUMN pinned INTEGER NOT NULL DEFAULT 0"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE posts ADD COLUMN hidden INTEGER NOT NULL DEFAULT 0"); } catch (Exception $e) {}
    // League-scoped posts: league_id IS NULL = global admin post (current behavior preserved).
    try { $pdo->exec("ALTER TABLE posts ADD COLUMN league_id INTEGER"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE posts ADD COLUMN author_id INTEGER"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE posts ADD COLUMN is_rules_post INTEGER NOT NULL DEFAULT 0"); } catch (Exception $e) {}
    try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_posts_league ON posts(league_id, created_at)"); } catch (Exception $e) {}
    // At most one rules post per league. Partial index so global posts (league_id IS NULL) are unaffected.
    try { $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_posts_one_rules_per_league ON posts(league_id, is_rules_post) WHERE is_rules_post = 1"); } catch (Exception $e) {}
    // Public share-link token for league posts. Token-bearer can view a single post without league membership.
    try { $pdo->exec("ALTER TABLE posts ADD COLUMN share_token TEXT"); } catch (Exception $e) {}
    try { $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_posts_share_token ON posts(share_token) WHERE share_token IS NOT NULL"); } catch (Exception $e) {}

    // Add phone to users if it doesn't exist yet
    try { $pdo->exec("ALTER TABLE users ADD COLUMN phone TEXT"); } catch (Exception $e) {}

    // Add rsvp to event_invites if it doesn't exist yet
    try { $pdo->exec("ALTER TABLE event_invites ADD COLUMN rsvp TEXT"); } catch (Exception $e) {}

    // Add rsvp_token to event_invites for email RSVP without login
    try { $pdo->exec("ALTER TABLE event_invites ADD COLUMN rsvp_token TEXT"); } catch (Exception $e) {}

    // Add event_role to event_invites for per-event manager permissions
    try { $pdo->exec("ALTER TABLE event_invites ADD COLUMN event_role TEXT NOT NULL DEFAULT 'invitee'"); } catch (Exception $e) {}

    // Add occurrence_date to event_invites for per-occurrence invite tracking
    try { $pdo->exec("ALTER TABLE event_invites ADD COLUMN occurrence_date TEXT"); } catch (Exception $e) {}

    // Per-event host approval gate for self-signups and walk-ins
    try { $pdo->exec("ALTER TABLE events ADD COLUMN requires_approval INTEGER NOT NULL DEFAULT 0"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE event_invites ADD COLUMN approval_status TEXT NOT NULL DEFAULT 'approved'"); } catch (Exception $e) {}

    // Unique index on lowercase email (login identifier) — safe on existing DBs
    try { $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_users_email ON users(LOWER(email)) WHERE email IS NOT NULL"); } catch (Exception $e) {}
    // Phone uniqueness (partial index so NULL phones don't collide). Added 0.19000 to support
    // phone-only signup paths; duplicate-phone rows in existing data will cause CREATE INDEX
    // to fail harmlessly — the try/catch keeps us moving.
    try { $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_users_phone ON users(phone) WHERE phone IS NOT NULL"); } catch (Exception $e) {}

    // Email verification
    try { $pdo->exec("ALTER TABLE users ADD COLUMN email_verified INTEGER NOT NULL DEFAULT 0"); } catch (Exception $e) {}
    // Mark all existing users as already verified (they pre-date this feature)
    try { $pdo->exec("UPDATE users SET email_verified=1 WHERE email_verified=0 AND created_at < '2026-04-01'"); } catch (Exception $e) {}
    try { $pdo->exec("CREATE TABLE IF NOT EXISTS email_verifications (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id    INTEGER NOT NULL,
        token_hash TEXT    NOT NULL UNIQUE,
        expires_at DATETIME NOT NULL,
        used       INTEGER NOT NULL DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id)
    )"); } catch (Exception $e) {}

    // Password reset tokens
    try { $pdo->exec("CREATE TABLE IF NOT EXISTS password_resets (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id    INTEGER NOT NULL,
        token_hash TEXT    NOT NULL UNIQUE,
        expires_at DATETIME NOT NULL,
        used       INTEGER NOT NULL DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id)
    )"); } catch (Exception $e) {}

    // Persistent "Remember me" auth tokens (30-day auto-login)
    try { $pdo->exec("CREATE TABLE IF NOT EXISTS remember_tokens (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id      INTEGER NOT NULL,
        token_hash   TEXT    NOT NULL UNIQUE,
        expires_at   DATETIME NOT NULL,
        created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
        last_used_at DATETIME,
        user_agent   TEXT,
        ip           TEXT,
        FOREIGN KEY (user_id) REFERENCES users(id)
    )"); } catch (Exception $e) {}

    // SMS log table
    try { $pdo->exec("CREATE TABLE IF NOT EXISTS sms_log (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        direction  TEXT    NOT NULL DEFAULT 'outbound',
        phone      TEXT    NOT NULL,
        body       TEXT    NOT NULL,
        provider   TEXT,
        status     TEXT,
        error      TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )"); } catch (Exception $e) {}

    // Add raw API response to SMS log for debugging
    try { $pdo->exec("ALTER TABLE sms_log ADD COLUMN raw_response TEXT"); } catch (Exception $e) {}

    // Persistent retry queue for emails whose inline retries were exhausted on a
    // transient SMTP failure. Drained by cron.php (process_email_retry_queue).
    try { $pdo->exec("CREATE TABLE IF NOT EXISTS email_retry_queue (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        to_address      TEXT    NOT NULL,
        to_name         TEXT    NOT NULL DEFAULT '',
        subject         TEXT    NOT NULL,
        body            TEXT    NOT NULL,
        attempts        INTEGER NOT NULL DEFAULT 0,
        last_error      TEXT,
        next_attempt_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        created_at      DATETIME DEFAULT CURRENT_TIMESTAMP
    )"); } catch (Exception $e) {}
    try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_email_retry_due ON email_retry_queue(next_attempt_at)"); } catch (Exception $e) {}

    try { $pdo->exec("CREATE TABLE IF NOT EXISTS short_links (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        code       TEXT UNIQUE NOT NULL,
        target_url TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )"); } catch (Exception $e) {}

    try { $pdo->exec("CREATE TABLE IF NOT EXISTS sms_pending_rsvp (
        user_id    INTEGER PRIMARY KEY,
        rsvp_value TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )"); } catch (Exception $e) {}

    // Pending admin/host SMS command awaiting a CONFIRM reply (cancel/msg/remind).
    // One in-flight command per user; INSERT OR REPLACE overwrites any prior pending one.
    try { $pdo->exec("CREATE TABLE IF NOT EXISTS sms_pending_admin (
        user_id    INTEGER PRIMARY KEY,
        command    TEXT NOT NULL,
        event_id   INTEGER NOT NULL,
        payload    TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )"); } catch (Exception $e) {}

    // Add preferred_contact column if it doesn't exist yet
    try { $pdo->exec("ALTER TABLE users ADD COLUMN preferred_contact TEXT NOT NULL DEFAULT 'email'"); } catch (Exception $e) {}

    // Severity level for log entries (info, warning, critical)
    try { $pdo->exec("ALTER TABLE activity_log ADD COLUMN severity TEXT NOT NULL DEFAULT 'info'"); } catch (Exception $e) {}

    // Admin notes field for users
    try { $pdo->exec("ALTER TABLE users ADD COLUMN notes TEXT"); } catch (Exception $e) {}

    // Per-user My Events time range preferences
    try { $pdo->exec("ALTER TABLE users ADD COLUMN my_events_past_days INTEGER NOT NULL DEFAULT 30"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE users ADD COLUMN last_poker_default INTEGER NOT NULL DEFAULT 1"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE users ADD COLUMN phone_verified INTEGER NOT NULL DEFAULT 0"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE users ADD COLUMN my_events_future_days INTEGER NOT NULL DEFAULT 7"); } catch (Exception $e) {}
    // Update existing users from old default of 90 to new default of 7
    try { $pdo->exec("UPDATE users SET my_events_future_days = 7 WHERE my_events_future_days = 90"); } catch (Exception $e) {}

    // Event notification deduplication for cron reminders
    try { $pdo->exec("CREATE TABLE IF NOT EXISTS event_notifications_sent (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        event_id INTEGER NOT NULL,
        occurrence_date TEXT NOT NULL,
        user_identifier TEXT NOT NULL,
        notification_type TEXT NOT NULL,
        sent_at TEXT NOT NULL,
        UNIQUE(event_id, occurrence_date, user_identifier, notification_type)
    )"); } catch (Exception $e) {}

    // Poker game night tables
    try { $pdo->exec("CREATE TABLE IF NOT EXISTS poker_sessions (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        event_id        INTEGER NOT NULL UNIQUE,
        buyin_amount    INTEGER NOT NULL DEFAULT 2000,
        rebuy_amount    INTEGER NOT NULL DEFAULT 2000,
        addon_amount    INTEGER NOT NULL DEFAULT 1000,
        rebuy_allowed   INTEGER NOT NULL DEFAULT 1,
        addon_allowed   INTEGER NOT NULL DEFAULT 1,
        max_rebuys      INTEGER NOT NULL DEFAULT 0,
        starting_chips  INTEGER NOT NULL DEFAULT 5000,
        num_tables      INTEGER NOT NULL DEFAULT 1,
        status          TEXT NOT NULL DEFAULT 'setup',
        notes           TEXT,
        created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
    )"); } catch (Exception $e) {}

    try { $pdo->exec("CREATE TABLE IF NOT EXISTS poker_players (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        session_id      INTEGER NOT NULL,
        user_id         INTEGER,
        display_name    TEXT NOT NULL,
        checked_in      INTEGER NOT NULL DEFAULT 0,
        bought_in       INTEGER NOT NULL DEFAULT 0,
        rebuys          INTEGER NOT NULL DEFAULT 0,
        addons          INTEGER NOT NULL DEFAULT 0,
        table_number    INTEGER,
        seat_number     INTEGER,
        eliminated      INTEGER NOT NULL DEFAULT 0,
        finish_position INTEGER,
        payout          INTEGER NOT NULL DEFAULT 0,
        notes           TEXT,
        created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (session_id) REFERENCES poker_sessions(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(id)
    )"); } catch (Exception $e) {}

    try { $pdo->exec("CREATE TABLE IF NOT EXISTS poker_payouts (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        session_id   INTEGER NOT NULL,
        place        INTEGER NOT NULL,
        percentage   REAL NOT NULL,
        UNIQUE(session_id, place),
        FOREIGN KEY (session_id) REFERENCES poker_sessions(id) ON DELETE CASCADE
    )"); } catch (Exception $e) {}

    // Poker schema migrations
    try { $pdo->exec("ALTER TABLE events ADD COLUMN is_poker INTEGER NOT NULL DEFAULT 0"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE poker_sessions ADD COLUMN game_type TEXT NOT NULL DEFAULT 'tournament'"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE poker_players ADD COLUMN cash_out INTEGER"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE poker_players ADD COLUMN cash_in INTEGER NOT NULL DEFAULT 0"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE poker_players ADD COLUMN rsvp TEXT DEFAULT NULL"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE poker_players ADD COLUMN removed INTEGER NOT NULL DEFAULT 0"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE poker_sessions ADD COLUMN auto_assign_tables INTEGER NOT NULL DEFAULT 1"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE poker_sessions ADD COLUMN seats_per_table INTEGER NOT NULL DEFAULT 8"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE poker_sessions ADD COLUMN addon_chips INTEGER NOT NULL DEFAULT 0"); } catch (Exception $e) {}
    // Default addon_chips to starting_chips where still zero (first run after this migration)
    try { $pdo->exec("UPDATE poker_sessions SET addon_chips = starting_chips WHERE addon_chips = 0"); } catch (Exception $e) {}

    // One-shot: convert poker_players.addons from cents to a count.
    // Old semantics: each non-zero row held dollars (cents) of add-on taken.
    // New semantics: integer count of add-ons taken.
    try {
        if (get_setting('addons_migrated_to_count') !== '1') {
            $pdo->exec("UPDATE poker_players
                SET addons = CASE
                    WHEN addons = 0 THEN 0
                    ELSE MAX(1, addons / (SELECT CASE WHEN addon_amount > 0 THEN addon_amount ELSE 1 END FROM poker_sessions WHERE id = session_id))
                END
                WHERE addons > 0");
            set_setting('addons_migrated_to_count', '1');
        }
    } catch (Exception $e) {}

    // Per-user (optionally per-league) remembered poker session defaults.
    // A new event created by the same user auto-pre-fills from their last save.
    try { $pdo->exec("CREATE TABLE IF NOT EXISTS user_session_defaults (
        id                  INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id             INTEGER NOT NULL,
        league_id           INTEGER,
        game_type           TEXT    DEFAULT 'tournament',
        buyin_amount        INTEGER DEFAULT 2000,
        rebuy_amount        INTEGER DEFAULT 2000,
        addon_amount        INTEGER DEFAULT 1000,
        starting_chips      INTEGER DEFAULT 5000,
        addon_chips         INTEGER DEFAULT 5000,
        rebuy_allowed       INTEGER DEFAULT 1,
        addon_allowed       INTEGER DEFAULT 1,
        max_rebuys          INTEGER DEFAULT 0,
        num_tables          INTEGER DEFAULT 1,
        seats_per_table     INTEGER DEFAULT 8,
        auto_assign_tables  INTEGER DEFAULT 1,
        updated_at          DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id)  REFERENCES users(id)   ON DELETE CASCADE,
        FOREIGN KEY (league_id) REFERENCES leagues(id) ON DELETE CASCADE
    )"); } catch (Exception $e) {}
    // SQLite treats NULLs as distinct in UNIQUE constraints, which breaks personal-scope upserts.
    // Two partial unique indexes handle the two cases cleanly.
    try { $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_usd_user_league ON user_session_defaults(user_id, league_id) WHERE league_id IS NOT NULL"); } catch (Exception $e) {}
    try { $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_usd_user_personal ON user_session_defaults(user_id) WHERE league_id IS NULL"); } catch (Exception $e) {}

    // Blind structure presets for poker timer
    try { $pdo->exec("CREATE TABLE IF NOT EXISTS blind_presets (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        name        TEXT NOT NULL,
        created_by  INTEGER NOT NULL DEFAULT 0,
        is_default  INTEGER NOT NULL DEFAULT 0,
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
    )"); } catch (Exception $e) {}

    // Personal vs global preset visibility (admin can create global presets visible to all users)
    try { $pdo->exec("ALTER TABLE blind_presets ADD COLUMN is_global INTEGER NOT NULL DEFAULT 0"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE blind_presets ADD COLUMN league_id INTEGER"); } catch (Exception $e) {}

    try { $pdo->exec("CREATE TABLE IF NOT EXISTS blind_preset_levels (
        id               INTEGER PRIMARY KEY AUTOINCREMENT,
        preset_id        INTEGER NOT NULL,
        level_number     INTEGER NOT NULL,
        small_blind      INTEGER NOT NULL,
        big_blind        INTEGER NOT NULL,
        ante             INTEGER NOT NULL DEFAULT 0,
        duration_minutes INTEGER NOT NULL DEFAULT 15,
        is_break         INTEGER NOT NULL DEFAULT 0,
        UNIQUE(preset_id, level_number),
        FOREIGN KEY (preset_id) REFERENCES blind_presets(id) ON DELETE CASCADE
    )"); } catch (Exception $e) {}

    // Payout structure presets (tournament payouts) — scoped like blind_presets
    try { $pdo->exec("CREATE TABLE IF NOT EXISTS payout_structures (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        name        TEXT NOT NULL,
        created_by  INTEGER NOT NULL DEFAULT 0,
        is_default  INTEGER NOT NULL DEFAULT 0,
        is_global   INTEGER NOT NULL DEFAULT 0,
        league_id   INTEGER,
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
    )"); } catch (Exception $e) {}

    try { $pdo->exec("CREATE TABLE IF NOT EXISTS payout_structure_places (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        structure_id  INTEGER NOT NULL,
        place         INTEGER NOT NULL,
        percentage    REAL NOT NULL,
        UNIQUE(structure_id, place),
        FOREIGN KEY (structure_id) REFERENCES payout_structures(id) ON DELETE CASCADE
    )"); } catch (Exception $e) {}

    // Seed a default payout structure (50/30/20) if none exists
    try {
        $has = (int)$pdo->query("SELECT COUNT(*) FROM payout_structures")->fetchColumn();
        if ($has === 0) {
            $pdo->exec("INSERT INTO payout_structures (name, created_by, is_default, is_global) VALUES ('Standard (50/30/20)', 0, 1, 1)");
            $sid = (int)$pdo->lastInsertId();
            $pdo->exec("INSERT INTO payout_structure_places (structure_id, place, percentage) VALUES ($sid, 1, 50.0), ($sid, 2, 30.0), ($sid, 3, 20.0)");
        }
    } catch (Exception $e) {}

    try { $pdo->exec("CREATE TABLE IF NOT EXISTS timer_state (
        id                     INTEGER PRIMARY KEY AUTOINCREMENT,
        session_id             INTEGER NOT NULL UNIQUE,
        preset_id              INTEGER,
        current_level          INTEGER NOT NULL DEFAULT 1,
        time_remaining_seconds INTEGER NOT NULL DEFAULT 900,
        is_running             INTEGER NOT NULL DEFAULT 0,
        remote_key             TEXT,
        started_at             DATETIME,
        updated_at             DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (session_id) REFERENCES poker_sessions(id) ON DELETE CASCADE,
        FOREIGN KEY (preset_id) REFERENCES blind_presets(id) ON DELETE SET NULL
    )"); } catch (Exception $e) {}

    try { $pdo->exec("ALTER TABLE timer_state ADD COLUMN commanded_at DATETIME"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE timer_state ADD COLUMN user_id INTEGER"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE timer_state ADD COLUMN warning_seconds INTEGER NOT NULL DEFAULT 60"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE timer_state ADD COLUMN alarm_sound TEXT"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE timer_state ADD COLUMN start_sound TEXT"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE timer_state ADD COLUMN warning_sound TEXT"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE timer_state ADD COLUMN theme_id INTEGER"); } catch (Exception $e) {}

    // Timer themes (visual customization of the timer screen). Scope mirrors blind_presets:
    // personal / league / global / default. Properties stored as a JSON blob so the schema
    // can evolve (new themable props) without ALTERs.
    try { $pdo->exec("CREATE TABLE IF NOT EXISTS timer_themes (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        name        TEXT NOT NULL,
        created_by  INTEGER NOT NULL DEFAULT 0,
        is_default  INTEGER NOT NULL DEFAULT 0,
        is_global   INTEGER NOT NULL DEFAULT 0,
        league_id   INTEGER,
        properties  TEXT NOT NULL DEFAULT '{}',
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
    )"); } catch (Exception $e) {}

    try {
        $has = (int)$pdo->query("SELECT COUNT(*) FROM timer_themes")->fetchColumn();
        if ($has === 0) {
            // Default theme shipped with fresh installs. Snapshot of the curated
            // layout — green/teal gradient background, panels positioned for a
            // tournament table. Edit in the timer's theme editor and re-seed if you
            // want a different look out of the box.
            $defaultPropsJson = <<<'JSON'
{
  "background": {
    "type": "gradient",
    "color": "#0f172a",
    "gradient": { "from": "#008000", "to": "#004040", "angle": 130 },
    "image_url": ""
  },
  "elements": {
    "event_name":    { "visible": true,  "color": "#ffffff", "scale": 1,    "pos": { "x": 50,    "y": 6.4 } },
    "player_count":  { "visible": true,  "color": "#94a3b8", "scale": 1,    "pos": { "x": 5.6,   "y": 35.1 } },
    "pool_total":    { "visible": true,  "color": "#94a3b8", "scale": 1,    "pos": { "x": 5.6,   "y": 31.7 } },
    "level_label":   { "visible": true,  "color": "#94a3b8", "scale": 1,    "pos": { "x": 50,    "y": 23.4 } },
    "blinds":        { "visible": true,  "color": "#ffffff", "scale": 1,    "pos": { "x": 50,    "y": 33.9 } },
    "clock":         { "visible": true,  "color_green": "#22c55e", "color_yellow": "#fbbf24", "color_red": "#ef4444",
                       "scale": 1, "warning_seconds": 120, "critical_seconds": 30, "pos": { "x": 50, "y": 57.9 } },
    "paused_label":  { "visible": true,  "color": "#ff0000", "scale": 6,    "pos": { "x": 50,    "y": 57.9 },
                       "letter_spacing": "wider", "font": "serif", "bold": true, "italic": true, "uppercase": false },
    "next_level":    { "visible": true,  "color": "#94a3b8", "scale": 1,    "pos": { "x": 50,    "y": 82.1 } },
    "avg_stack":     { "visible": true,  "color": "#94a3b8", "scale": 1.1,  "pos": { "x": 4.5,   "y": 8.3 } },
    "payouts":       { "visible": true,  "color": "#94a3b8", "scale": 1.55, "pos": { "x": 90.8,  "y": 13.7 } },
    "qr":            { "visible": true,                       "scale": 1.1, "pos": { "x": 93.8,  "y": 91.7 } },
    "image":         { "visible": false, "url": "",           "scale": 1,    "pos": { "x": 50,    "y": 50 } },
    "rebuys":        { "visible": true,  "color": "#94a3b8", "scale": 1,    "pos": { "x": 4.5,   "y": 28.3 } },
    "chips_in_play": { "visible": true,  "color": "#94a3b8", "scale": 1,    "pos": { "x": 5.9,   "y": 24.9 } },
    "next_break":    { "visible": true,  "color": "#94a3b8", "scale": 1,    "pos": { "x": 7.5,   "y": 21.9 } },
    "streaming":     { "visible": false, "scale": 1,         "url": "",    "pos": { "x": 15.9,  "y": 88.0 } }
  },
  "tray": { "bg_color": "#1e293b", "button_color": "#e2e8f0", "accent_color": "#2563eb" }
}
JSON;
            $stmt = $pdo->prepare("INSERT INTO timer_themes (name, created_by, is_default, is_global, properties) VALUES ('Default', 0, 1, 1, ?)");
            $stmt->execute([$defaultPropsJson]);
        }
    } catch (Exception $e) {}

    // Walk-up QR registration token per event
    try { $pdo->exec("ALTER TABLE events ADD COLUMN walkin_token TEXT"); } catch (Exception $e) {}

    // Rate-limit table for walk-up registration submissions
    try { $pdo->exec("CREATE TABLE IF NOT EXISTS walkin_attempts (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        ip         TEXT    NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )"); } catch (Exception $e) {}

    // Phone/WhatsApp verification codes for registration
    try { $pdo->exec("CREATE TABLE IF NOT EXISTS phone_verifications (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id    INTEGER NOT NULL,
        code_hash  TEXT    NOT NULL,
        method     TEXT    NOT NULL DEFAULT 'sms',
        expires_at DATETIME NOT NULL,
        used       INTEGER NOT NULL DEFAULT 0,
        attempts   INTEGER NOT NULL DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id)
    )"); } catch (Exception $e) {}

    // Track which verification method the user chose at registration
    try { $pdo->exec("ALTER TABLE users ADD COLUMN verification_method TEXT NOT NULL DEFAULT 'email'"); } catch (Exception $e) {}

    // Subscription tier scaffolding. Values: 'Free', 'Personal', 'League', 'OriginalSupporters'.
    // tier_source examples: 'manual', 'stripe', 'comp', 'os_backfill'. NULL on Free defaults.
    // tier_granted_by = admin user id when set via the admin UI, NULL for self-paid.
    // tier_expires_at = NULL means never expires (Free, OS, manual grants).
    try { $pdo->exec("ALTER TABLE users ADD COLUMN tier TEXT NOT NULL DEFAULT 'Free'"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE users ADD COLUMN tier_expires_at DATETIME"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE users ADD COLUMN tier_source TEXT"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE users ADD COLUMN tier_granted_by INTEGER"); } catch (Exception $e) {}

    // Per-user timezone preference. NULL = follow site timezone (the default).
    try { $pdo->exec("ALTER TABLE users ADD COLUMN timezone TEXT"); } catch (Exception $e) {}

    // ─── Opt-in Multi-Factor Authentication (per user) ─────────────────────────
    // mfa_method: 'totp' (authenticator app) or 'sms'. mfa_totp_secret holds the
    // base32 TOTP secret, stored ENCRYPTED via encrypt_value() (decrypt on read).
    try { $pdo->exec("ALTER TABLE users ADD COLUMN mfa_enabled INTEGER NOT NULL DEFAULT 0"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE users ADD COLUMN mfa_method TEXT"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE users ADD COLUMN mfa_totp_secret TEXT"); } catch (Exception $e) {}
    // Whether the user has dismissed (or acted on) the "enable two-factor" dashboard
    // banner. 0 = still show the nudge (any MFA-less user); set to 1 on dismiss or enable.
    try { $pdo->exec("ALTER TABLE users ADD COLUMN mfa_offer_dismissed INTEGER NOT NULL DEFAULT 0"); } catch (Exception $e) {}

    // Single-use MFA recovery codes (shown once at enable time; sha256-hashed at rest).
    try { $pdo->exec("CREATE TABLE IF NOT EXISTS mfa_recovery_codes (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id    INTEGER NOT NULL,
        code_hash  TEXT    NOT NULL,
        used       INTEGER NOT NULL DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id)
    )"); } catch (Exception $e) {}
    try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_mfa_recovery_user ON mfa_recovery_codes(user_id)"); } catch (Exception $e) {}

    // ─── Leagues ───────────────────────────────────────────────
    try { $pdo->exec("CREATE TABLE IF NOT EXISTS leagues (
        id                 INTEGER PRIMARY KEY AUTOINCREMENT,
        name               TEXT    NOT NULL,
        description        TEXT,
        owner_id           INTEGER NOT NULL,
        default_visibility TEXT    NOT NULL DEFAULT 'league',
        approval_mode      TEXT    NOT NULL DEFAULT 'manual',
        is_hidden          INTEGER NOT NULL DEFAULT 0,
        invite_code        TEXT    UNIQUE,
        created_at         DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (owner_id) REFERENCES users(id)
    )"); } catch (Exception $e) {}
    try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_leagues_owner  ON leagues(owner_id)"); } catch (Exception $e) {}
    try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_leagues_hidden ON leagues(is_hidden)"); } catch (Exception $e) {}

    try { $pdo->exec("CREATE TABLE IF NOT EXISTS league_members (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        league_id  INTEGER NOT NULL,
        user_id    INTEGER NOT NULL,
        role       TEXT    NOT NULL DEFAULT 'member',
        joined_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE (league_id, user_id),
        FOREIGN KEY (league_id) REFERENCES leagues(id),
        FOREIGN KEY (user_id)   REFERENCES users(id)
    )"); } catch (Exception $e) {}
    try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_league_members_user   ON league_members(user_id)"); } catch (Exception $e) {}
    try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_league_members_league ON league_members(league_id)"); } catch (Exception $e) {}

    try { $pdo->exec("CREATE TABLE IF NOT EXISTS league_join_requests (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        league_id    INTEGER NOT NULL,
        user_id      INTEGER NOT NULL,
        message      TEXT,
        status       TEXT    NOT NULL DEFAULT 'pending',
        requested_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        decided_at   DATETIME,
        decided_by   INTEGER,
        UNIQUE (league_id, user_id, status),
        FOREIGN KEY (league_id) REFERENCES leagues(id),
        FOREIGN KEY (user_id)   REFERENCES users(id)
    )"); } catch (Exception $e) {}
    try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_join_requests_league ON league_join_requests(league_id, status)"); } catch (Exception $e) {}
    try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_join_requests_user   ON league_join_requests(user_id, status)"); } catch (Exception $e) {}

    // ─── Public read-only API keys (one key = one bound league_id) ────────────
    try { $pdo->exec("CREATE TABLE IF NOT EXISTS api_keys (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        key_hash     TEXT    NOT NULL UNIQUE,
        label        TEXT    NOT NULL,
        league_id    INTEGER NOT NULL,
        created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
        last_used_at DATETIME,
        revoked_at   DATETIME,
        FOREIGN KEY (league_id) REFERENCES leagues(id)
    )"); } catch (Exception $e) {}
    try { $pdo->exec("CREATE TABLE IF NOT EXISTS api_request_log (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        key_id     INTEGER,
        ip         TEXT,
        method     TEXT,
        path       TEXT,
        status     INTEGER,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )"); } catch (Exception $e) {}
    try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_api_log_key_time ON api_request_log(key_id, created_at)"); } catch (Exception $e) {}
    // Per-key permission scopes. Comma-separated; existing keys default to 'read' so
    // adding write endpoints (e.g. POST /api/v1/users) cannot be exercised by an old
    // sister-site key without an explicit re-mint.
    try { $pdo->exec("ALTER TABLE api_keys ADD COLUMN scopes TEXT NOT NULL DEFAULT 'read'"); } catch (Exception $e) {}
    // One-shot: prune any pre-existing revoked api_keys rows. Revoke now hard-deletes,
    // so legacy soft-revoked rows would just sit invisible forever. Guarded by a
    // site_setting flag so it runs once and then no-ops on every db_init thereafter.
    try {
        if (get_setting('api_keys_revoked_pruned', '') !== '1') {
            $pdo->exec('DELETE FROM api_keys WHERE revoked_at IS NOT NULL');
            set_setting('api_keys_revoked_pruned', '1');
        }
    } catch (Exception $e) {}

    // One-shot: clean FK orphans accumulated while SQLite FK enforcement was off.
    // From this commit on, get_db() sets PRAGMA foreign_keys = ON, but existing
    // orphans need to be cleared once. Order matters: clean leaves before parents
    // so cascades don't fight us.
    try {
        if (get_setting('fk_orphan_cleanup_v1', '') !== '1') {
            // Dead blind preset levels (114 on prod at deploy time)
            $pdo->exec('DELETE FROM blind_preset_levels WHERE preset_id NOT IN (SELECT id FROM blind_presets)');
            // timer_state with dead preset → null out (FK declares ON DELETE SET NULL anyway)
            $pdo->exec('UPDATE timer_state SET preset_id = NULL WHERE preset_id IS NOT NULL AND preset_id NOT IN (SELECT id FROM blind_presets)');
            // timer_state with dead session → delete (FK declares ON DELETE CASCADE)
            $pdo->exec('DELETE FROM timer_state WHERE session_id NOT IN (SELECT id FROM poker_sessions)');
            // Dead poker_sessions → delete (cascades into players/payouts/timer_state)
            $pdo->exec('DELETE FROM poker_sessions WHERE event_id NOT IN (SELECT id FROM events)');
            // poker_players pointing at deleted users → null the FK, keep history (display_name + removed)
            $pdo->exec('UPDATE poker_players SET user_id = NULL, removed = 1 WHERE user_id IS NOT NULL AND user_id NOT IN (SELECT id FROM users)');
            // activity_log: NOT NULL FK to users; only option is delete (rows would be 90d-pruned anyway)
            $pdo->exec('DELETE FROM activity_log WHERE user_id NOT IN (SELECT id FROM users)');
            // user_session_defaults pointing at dead users or leagues
            $pdo->exec('DELETE FROM user_session_defaults WHERE user_id NOT IN (SELECT id FROM users) OR league_id NOT IN (SELECT id FROM leagues)');
            set_setting('fk_orphan_cleanup_v1', '1');
        }
    } catch (Exception $e) {
        error_log('fk_orphan_cleanup_v1 failed: ' . $e->getMessage());
    }

    // Drop pending_notifications.event_id FK. cancel_event rows are queued for
    // events that are about to be deleted; the drain handles missing events via
    // the payload fallback (title/start_date). With FK enforcement now ON, the
    // FK prevents this design from working and 500s any event delete that has
    // un-drained queued notifications (the common case under load).
    try {
        $fkRows = $pdo->query("PRAGMA foreign_key_list(pending_notifications)")->fetchAll();
        if (!empty($fkRows)) {
            $pdo->exec("BEGIN");
            $pdo->exec("ALTER TABLE pending_notifications RENAME TO pending_notifications_old");
            $pdo->exec("CREATE TABLE pending_notifications (
                id              INTEGER PRIMARY KEY AUTOINCREMENT,
                event_id        INTEGER NOT NULL,
                username        TEXT    NOT NULL,
                notify_type     TEXT    NOT NULL DEFAULT 'invite',
                created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
                attempted_at    DATETIME,
                attempts        INTEGER NOT NULL DEFAULT 0,
                scheduled_for   DATETIME,
                payload         TEXT,
                occurrence_date TEXT
            )");
            $pdo->exec("INSERT INTO pending_notifications
                           (id, event_id, username, notify_type, created_at,
                            attempted_at, attempts, scheduled_for, payload, occurrence_date)
                        SELECT id, event_id, username, notify_type, created_at,
                               attempted_at, COALESCE(attempts, 0), scheduled_for, payload, occurrence_date
                        FROM pending_notifications_old");
            $pdo->exec("DROP TABLE pending_notifications_old");
            // Indexes are dropped along with the table; re-create them.
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_pending_notifications_unsent ON pending_notifications(attempted_at) WHERE attempted_at IS NULL");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_pending_notifications_scheduled ON pending_notifications(scheduled_for) WHERE attempted_at IS NULL");
            $pdo->exec("COMMIT");
        }
    } catch (Exception $e) {
        try { $pdo->exec("ROLLBACK"); } catch (Exception $e2) {}
        error_log('pending_notifications FK migration failed: ' . $e->getMessage());
    }

    // Drop the FK on timer_state.session_id. Standalone timer (no event_id) inserts
    // rows with a negative sentinel session_id (-user_id for logged-in users, or
    // -crc32(php_session_id) for guests) so a single user/visitor gets one persistent
    // timer without needing a real poker_sessions row. With FK enforcement now ON,
    // those inserts fail and /timer.php 500s for every standalone visit. Keep the
    // preset_id FK (positive real IDs with ON DELETE SET NULL — that one's fine).
    try {
        $fkRows = $pdo->query("PRAGMA foreign_key_list(timer_state)")->fetchAll();
        $hasSessionFk = false;
        foreach ($fkRows as $fk) {
            if (($fk['table'] ?? '') === 'poker_sessions') { $hasSessionFk = true; break; }
        }
        if ($hasSessionFk) {
            $pdo->exec("BEGIN");
            $pdo->exec("ALTER TABLE timer_state RENAME TO timer_state_old");
            $pdo->exec("CREATE TABLE timer_state (
                id                     INTEGER PRIMARY KEY AUTOINCREMENT,
                session_id             INTEGER NOT NULL UNIQUE,
                preset_id              INTEGER,
                current_level          INTEGER NOT NULL DEFAULT 1,
                time_remaining_seconds INTEGER NOT NULL DEFAULT 900,
                is_running             INTEGER NOT NULL DEFAULT 0,
                remote_key             TEXT,
                started_at             DATETIME,
                updated_at             DATETIME DEFAULT CURRENT_TIMESTAMP,
                commanded_at           DATETIME,
                user_id                INTEGER,
                warning_seconds        INTEGER NOT NULL DEFAULT 60,
                alarm_sound            TEXT,
                start_sound            TEXT,
                warning_sound          TEXT,
                FOREIGN KEY (preset_id) REFERENCES blind_presets(id) ON DELETE SET NULL
            )");
            $pdo->exec("INSERT INTO timer_state (id, session_id, preset_id, current_level,
                            time_remaining_seconds, is_running, remote_key, started_at,
                            updated_at, commanded_at, user_id, warning_seconds,
                            alarm_sound, start_sound, warning_sound)
                        SELECT id, session_id, preset_id, current_level,
                               time_remaining_seconds, is_running, remote_key, started_at,
                               updated_at, commanded_at, user_id,
                               COALESCE(warning_seconds, 60),
                               alarm_sound, start_sound, warning_sound
                        FROM timer_state_old");
            $pdo->exec("DROP TABLE timer_state_old");
            $pdo->exec("COMMIT");
        }
    } catch (Exception $e) {
        try { $pdo->exec("ROLLBACK"); } catch (Exception $e2) {}
        error_log('timer_state FK migration failed: ' . $e->getMessage());
    }

    // Drop the FK on activity_log.user_id. Anonymous events (register_attempt,
    // failed_login, password_reset_request, walkin_rsvp, API calls, ...) write
    // user_id=0 as a "no real user" sentinel; with FK enforcement now ON, those
    // inserts fail and crash the request. The admin log viewer already treats
    // user_id=0 as anonymous via LEFT JOIN ... AND a.user_id != 0.
    try {
        $fkRows = $pdo->query("PRAGMA foreign_key_list(activity_log)")->fetchAll();
        if (!empty($fkRows)) {
            $pdo->exec("BEGIN");
            $pdo->exec("ALTER TABLE activity_log RENAME TO activity_log_old");
            $pdo->exec("CREATE TABLE activity_log (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id    INTEGER NOT NULL,
                action     TEXT    NOT NULL,
                ip         TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                severity   TEXT    NOT NULL DEFAULT 'info'
            )");
            $pdo->exec("INSERT INTO activity_log (id, user_id, action, ip, created_at, severity)
                        SELECT id, user_id, action, ip, created_at,
                               COALESCE(severity, 'info')
                        FROM activity_log_old");
            $pdo->exec("DROP TABLE activity_log_old");
            $pdo->exec("COMMIT");
        }
    } catch (Exception $e) {
        try { $pdo->exec("ROLLBACK"); } catch (Exception $e2) {}
        error_log('activity_log FK migration failed: ' . $e->getMessage());
    }

    // Event visibility + league linkage
    try { $pdo->exec("ALTER TABLE events ADD COLUMN league_id  INTEGER"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE events ADD COLUMN visibility TEXT NOT NULL DEFAULT 'invitees_only'"); } catch (Exception $e) {}
    // Any pre-existing events were created under the old "everything public" model — keep them public.
    try { $pdo->exec("UPDATE events SET visibility='public' WHERE visibility IS NULL OR visibility=''"); } catch (Exception $e) {}
    // Leagues no longer support public default_visibility — coerce any stragglers to 'league'.
    try { $pdo->exec("UPDATE leagues SET default_visibility='league' WHERE default_visibility <> 'league'"); } catch (Exception $e) {}

    // ─── League pending contacts ───────────────────────────────────────────
    // Allow league_members rows that represent a pending contact (no user account yet).
    try { $pdo->exec("ALTER TABLE league_members ADD COLUMN contact_name  TEXT"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE league_members ADD COLUMN contact_email TEXT"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE league_members ADD COLUMN contact_phone TEXT"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE league_members ADD COLUMN invited_by    INTEGER"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE league_members ADD COLUMN invited_at    DATETIME"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE league_members ADD COLUMN invite_token  TEXT"); } catch (Exception $e) {}

    // Relax user_id NOT NULL if still present (SQLite can't drop NOT NULL directly).
    try {
        $info = $pdo->query("PRAGMA table_info(league_members)")->fetchAll();
        $needs_rebuild = false;
        foreach ($info as $col) {
            if ($col['name'] === 'user_id' && (int)$col['notnull'] === 1) { $needs_rebuild = true; break; }
        }
        if ($needs_rebuild) {
            $pdo->exec("BEGIN");
            $pdo->exec("ALTER TABLE league_members RENAME TO league_members_old");
            $pdo->exec("CREATE TABLE league_members (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                league_id INTEGER NOT NULL,
                user_id INTEGER,
                role TEXT NOT NULL DEFAULT 'member',
                joined_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                contact_name  TEXT,
                contact_email TEXT,
                contact_phone TEXT,
                invited_by    INTEGER,
                invited_at    DATETIME,
                invite_token  TEXT,
                FOREIGN KEY (league_id) REFERENCES leagues(id),
                FOREIGN KEY (user_id)   REFERENCES users(id)
            )");
            $pdo->exec("INSERT INTO league_members
                        (id, league_id, user_id, role, joined_at, contact_name, contact_email, contact_phone, invited_by, invited_at, invite_token)
                        SELECT id, league_id, user_id, role, joined_at, contact_name, contact_email, contact_phone, invited_by, invited_at, invite_token
                        FROM league_members_old");
            $pdo->exec("DROP TABLE league_members_old");
            $pdo->exec("COMMIT");
        }
    } catch (Exception $e) {
        try { $pdo->exec("ROLLBACK"); } catch (Exception $e2) {}
    }

    // (Re-)create indexes. The prior non-unique user/league indexes are fine.
    try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_league_members_user   ON league_members(user_id)"); } catch (Exception $e) {}
    try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_league_members_league ON league_members(league_id)"); } catch (Exception $e) {}
    // Replace old non-conditional UNIQUE(league_id, user_id) with one that ignores NULL user_id.
    try { $pdo->exec("DROP INDEX IF EXISTS sqlite_autoindex_league_members_1"); } catch (Exception $e) {}
    try { $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS uq_league_members_user ON league_members(league_id, user_id) WHERE user_id IS NOT NULL"); } catch (Exception $e) {}
    try { $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS uq_league_members_contact_email ON league_members(league_id, LOWER(contact_email)) WHERE user_id IS NULL AND contact_email IS NOT NULL"); } catch (Exception $e) {}
    try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_league_members_invite_token ON league_members(invite_token) WHERE invite_token IS NOT NULL"); } catch (Exception $e) {}

    // Deduplicate event_invites (keep the row with the lowest sort_order or lowest id)
    try {
        $pdo->exec("DELETE FROM event_invites WHERE id NOT IN (
            SELECT MIN(id) FROM event_invites GROUP BY event_id, LOWER(username), COALESCE(occurrence_date, '')
        )");
    } catch (Exception $e) {}
    // Unique index on (event_id, username, occurrence_date) to prevent future duplicates
    try { $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS uq_event_invites_user ON event_invites(event_id, LOWER(username), COALESCE(occurrence_date, ''))"); } catch (Exception $e) {}

    // Enforce case-insensitive uniqueness on users.username at the DB layer.
    // The inline UNIQUE on the column is byte-sensitive, so "Jeremy" and "jeremy"
    // could coexist even though every app-layer lookup treats them as the same user.
    try { $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS uq_users_username_nocase ON users (username COLLATE NOCASE)"); } catch (Exception $e) {}

    // Calendar's main month/week query filters by start_date and end_date. Without
    // these indexes the planner full-scans events on every page load.
    try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_events_start_date ON events(start_date)"); } catch (Exception $e) {}
    try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_events_end_date   ON events(end_date) WHERE end_date IS NOT NULL"); } catch (Exception $e) {}

    // Per-event waitlist toggle (default ON for backwards compat)
    try { $pdo->exec("ALTER TABLE events ADD COLUMN waitlist_enabled INTEGER NOT NULL DEFAULT 1"); } catch (Exception $e) {}

    // ─── Per-user personal contacts (Issue #14) ────────────────────────
    try { $pdo->exec("CREATE TABLE IF NOT EXISTS user_contacts (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        owner_user_id   INTEGER NOT NULL,
        linked_user_id  INTEGER,
        contact_name    TEXT    NOT NULL,
        contact_email   TEXT,
        contact_phone   TEXT,
        notes           TEXT,
        created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (owner_user_id)  REFERENCES users(id),
        FOREIGN KEY (linked_user_id) REFERENCES users(id)
    )"); } catch (Exception $e) {}
    try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_user_contacts_owner  ON user_contacts(owner_user_id)"); } catch (Exception $e) {}
    try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_user_contacts_linked ON user_contacts(linked_user_id) WHERE linked_user_id IS NOT NULL"); } catch (Exception $e) {}
    try { $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS uq_user_contacts_email ON user_contacts(owner_user_id, LOWER(contact_email)) WHERE contact_email IS NOT NULL AND contact_email <> ''"); } catch (Exception $e) {}

    // Pending notifications queue (invite emails sent async by cron, not inline on save)
    try { $pdo->exec("CREATE TABLE IF NOT EXISTS pending_notifications (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        event_id     INTEGER NOT NULL,
        username     TEXT    NOT NULL,
        notify_type  TEXT    NOT NULL DEFAULT 'invite',
        created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
        attempted_at DATETIME,
        attempts     INTEGER NOT NULL DEFAULT 0,
        FOREIGN KEY (event_id) REFERENCES events(id)
    )"); } catch (Exception $e) {}
    try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_pending_notifications_unsent ON pending_notifications(attempted_at) WHERE attempted_at IS NULL"); } catch (Exception $e) {}

    // Unified queue columns: scheduled_for (NULL = send ASAP), payload (JSON for type-specific data),
    // occurrence_date (for per-occurrence reminders/cancellations on recurring events).
    try { $pdo->exec("ALTER TABLE pending_notifications ADD COLUMN scheduled_for DATETIME"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE pending_notifications ADD COLUMN payload TEXT"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE pending_notifications ADD COLUMN occurrence_date TEXT"); } catch (Exception $e) {}
    try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_pending_notifications_scheduled ON pending_notifications(scheduled_for) WHERE attempted_at IS NULL"); } catch (Exception $e) {}

    // ─── In-app help bubbles ("ghost bubble" hints) ─────────────────────
    // Admin-authored tips shown as a small floating/anchored chat bubble on
    // selected screens. One row per tip; screen_key is the page basename.
    try { $pdo->exec("CREATE TABLE IF NOT EXISTS help_bubbles (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        screen_key      TEXT    NOT NULL,
        title           TEXT,
        body            TEXT    NOT NULL,
        anchor_selector TEXT,
        sort_order      INTEGER NOT NULL DEFAULT 0,
        enabled         INTEGER NOT NULL DEFAULT 1,
        created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP
    )"); } catch (Exception $e) {}
    try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_help_bubbles_screen ON help_bubbles(screen_key, sort_order, id)"); } catch (Exception $e) {}

    // One row = "this user has dismissed help for this screen" (hidden until reset).
    try { $pdo->exec("CREATE TABLE IF NOT EXISTS user_help_dismissed (
        user_id      INTEGER NOT NULL,
        screen_key   TEXT    NOT NULL,
        dismissed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id, screen_key),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )"); } catch (Exception $e) {}
    // Optional step/group index: tips sharing the same bubble_index appear together
    // as one step. NULL = the tip is its own step (default, backward-compatible).
    try { $pdo->exec("ALTER TABLE help_bubbles ADD COLUMN bubble_index INTEGER"); } catch (Exception $e) {}

    // "Always show" pin: a pinned bubble reappears every visit even after the
    // user dismisses the screen's help with the X (X only closes it for that
    // page view). Pinned bubbles are never written to the dismissal table.
    try { $pdo->exec("ALTER TABLE help_bubbles ADD COLUMN always_show INTEGER NOT NULL DEFAULT 0"); } catch (Exception $e) {}

    // Per-BUBBLE dismissal (replaces the per-screen user_help_dismissed table,
    // which is kept but no longer read). Tracking by bubble id means tips added
    // after a user dismissed a screen still auto-show for them.
    try { $pdo->exec("CREATE TABLE IF NOT EXISTS user_help_bubble_dismissed (
        user_id      INTEGER NOT NULL,
        bubble_id    INTEGER NOT NULL,
        dismissed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id, bubble_id),
        FOREIGN KEY (user_id)   REFERENCES users(id)        ON DELETE CASCADE,
        FOREIGN KEY (bubble_id) REFERENCES help_bubbles(id) ON DELETE CASCADE
    )"); } catch (Exception $e) {}

    // One-shot: convert old screen-level dismissals to per-bubble rows so prior
    // dismissals keep meaning "dismissed what existed at the time".
    try {
        if (get_setting('help_dismissals_migrated', '') !== '1') {
            $pdo->exec("INSERT OR IGNORE INTO user_help_bubble_dismissed (user_id, bubble_id)
                        SELECT d.user_id, b.id FROM user_help_dismissed d
                        JOIN help_bubbles b ON b.screen_key = d.screen_key");
            set_setting('help_dismissals_migrated', '1');
        }
    } catch (Exception $e) {}

    // ─── Event polls (host → Yes/Maybe invitees; counts shown, voters never) ──
    // Votes are stored linked to a recipient (enables change-vote, double-vote
    // prevention, and turnout display) but the UI only ever shows counts.
    try { $pdo->exec("CREATE TABLE IF NOT EXISTS event_polls (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        event_id   INTEGER NOT NULL,
        title      TEXT    NOT NULL,
        status     TEXT    NOT NULL DEFAULT 'open',
        created_by INTEGER NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        closed_at  DATETIME,
        FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
    )"); } catch (Exception $e) {}
    try { $pdo->exec("CREATE TABLE IF NOT EXISTS poll_questions (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        poll_id    INTEGER NOT NULL,
        question   TEXT    NOT NULL,
        sort_order INTEGER NOT NULL DEFAULT 0,
        FOREIGN KEY (poll_id) REFERENCES event_polls(id) ON DELETE CASCADE
    )"); } catch (Exception $e) {}
    try { $pdo->exec("CREATE TABLE IF NOT EXISTS poll_options (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        question_id INTEGER NOT NULL,
        label       TEXT    NOT NULL,
        sort_order  INTEGER NOT NULL DEFAULT 0,
        FOREIGN KEY (question_id) REFERENCES poll_questions(id) ON DELETE CASCADE
    )"); } catch (Exception $e) {}
    // One row per invited respondent; `token` powers the no-login answer page.
    // username matches event_invites.username (covers pending contacts too).
    try { $pdo->exec("CREATE TABLE IF NOT EXISTS poll_recipients (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        poll_id      INTEGER NOT NULL,
        username     TEXT    NOT NULL,
        token        TEXT    NOT NULL UNIQUE,
        notified_at  DATETIME,
        submit_count INTEGER NOT NULL DEFAULT 0,
        UNIQUE (poll_id, username),
        FOREIGN KEY (poll_id) REFERENCES event_polls(id) ON DELETE CASCADE
    )"); } catch (Exception $e) {}
    try { $pdo->exec("CREATE TABLE IF NOT EXISTS poll_answers (
        question_id  INTEGER NOT NULL,
        recipient_id INTEGER NOT NULL,
        option_id    INTEGER NOT NULL,
        answered_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (question_id, recipient_id),
        FOREIGN KEY (question_id)  REFERENCES poll_questions(id)  ON DELETE CASCADE,
        FOREIGN KEY (recipient_id) REFERENCES poll_recipients(id) ON DELETE CASCADE,
        FOREIGN KEY (option_id)    REFERENCES poll_options(id)    ON DELETE CASCADE
    )"); } catch (Exception $e) {}
    // Stateful reply-by-text poll conversation, keyed by phone (10 digits) so it
    // also works for pending contacts with no users row. question_idx = the
    // question the next numeric reply answers. Expires after 48h.
    try { $pdo->exec("CREATE TABLE IF NOT EXISTS sms_pending_poll (
        phone        TEXT PRIMARY KEY,
        recipient_id INTEGER NOT NULL,
        question_idx INTEGER NOT NULL DEFAULT 0,
        created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (recipient_id) REFERENCES poll_recipients(id) ON DELETE CASCADE
    )"); } catch (Exception $e) {}

    // One-shot: clean up pending_notifications + event_notifications_sent rows that point
    // to events already deleted. Prior versions did not cascade event deletes into these
    // tables, so older databases accumulate orphans.
    try {
        if (get_setting('orphan_notifications_cleaned', '') !== '1') {
            $pdo->exec("DELETE FROM pending_notifications    WHERE event_id NOT IN (SELECT id FROM events)");
            $pdo->exec("DELETE FROM event_notifications_sent WHERE event_id NOT IN (SELECT id FROM events)");
            set_setting('orphan_notifications_cleaned', '1');
        }
    } catch (Exception $e) {}

    // Per-event reminder configuration
    try { $pdo->exec("ALTER TABLE events ADD COLUMN reminders_enabled INTEGER NOT NULL DEFAULT 1"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE events ADD COLUMN reminder_offsets TEXT"); } catch (Exception $e) {}  // JSON array of minutes; NULL = use site default
    try { $pdo->exec("ALTER TABLE events ADD COLUMN reminders_queued INTEGER NOT NULL DEFAULT 0"); } catch (Exception $e) {}

    // Seed default reminder offsets if unset (preserve current 2-day + 12-hour behavior)
    if (get_setting('default_reminder_offsets', '') === '') {
        set_setting('default_reminder_offsets', '[2880,720]');
    }
    if (get_setting('reminder_offsets_available', '') === '') {
        // 1w, 3d, 2d, 1d, 12h, 2h, 30min
        set_setting('reminder_offsets_available', '[10080,4320,2880,1440,720,120,30]');
    }

    // ─── Priority invite ordering + RSVP deadline ───────────────────────
    try { $pdo->exec("ALTER TABLE event_invites ADD COLUMN sort_order INTEGER"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE events ADD COLUMN rsvp_deadline_hours INTEGER"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE events ADD COLUMN rsvp_deadline_processed INTEGER NOT NULL DEFAULT 0"); } catch (Exception $e) {}
    // Security (v0.19015): cap how many times an `rsvp_token` can flip the RSVP value so a stolen
    // or shoulder-surfed QR/email link can't be replayed indefinitely.
    try { $pdo->exec("ALTER TABLE event_invites ADD COLUMN rsvp_token_flips INTEGER NOT NULL DEFAULT 0"); } catch (Exception $e) {}

    // Seed default blind structure if none exists
    $presetCount = $pdo->query('SELECT COUNT(*) FROM blind_presets WHERE is_default = 1')->fetchColumn();
    if ((int)$presetCount === 0) {
        $pdo->prepare('INSERT INTO blind_presets (name, created_by, is_default) VALUES (?, 0, 1)')
            ->execute(['Standard Tournament']);
        $defaultPresetId = (int)$pdo->lastInsertId();
        $lvlIns = $pdo->prepare('INSERT INTO blind_preset_levels (preset_id, level_number, small_blind, big_blind, ante, duration_minutes, is_break) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $defaultLevels = [
            [1,  25,    50,    0,    15, 0],
            [2,  50,    100,   0,    15, 0],
            [3,  75,    150,   0,    15, 0],
            [4,  100,   200,   0,    15, 0],
            [5,  150,   300,   25,   15, 0],
            [6,  0,     0,     0,    10, 1],  // Break
            [7,  200,   400,   50,   15, 0],
            [8,  300,   600,   75,   15, 0],
            [9,  400,   800,   100,  15, 0],
            [10, 0,     0,     0,    10, 1],  // Break
            [11, 500,   1000,  100,  12, 0],
            [12, 600,   1200,  200,  12, 0],
            [13, 800,   1600,  200,  12, 0],
            [14, 1000,  2000,  300,  10, 0],
            [15, 1500,  3000,  400,  10, 0],
            [16, 2000,  4000,  500,  10, 0],
            [17, 3000,  6000,  1000, 10, 0],
            [18, 4000,  8000,  1000, 10, 0],
            [19, 5000,  10000, 2000, 10, 0],
            [20, 10000, 20000, 3000, 10, 0],
        ];
        foreach ($defaultLevels as $lv) {
            $lvlIns->execute([$defaultPresetId, $lv[0], $lv[1], $lv[2], $lv[3], $lv[4], $lv[5]]);
        }
    }

    // Seed default site_settings on a fresh DB (INSERT OR IGNORE — never overwrites existing values)
    $ins = $pdo->prepare('INSERT OR IGNORE INTO site_settings (key, value) VALUES (?, ?)');

    if (defined('DEFAULT_SETTINGS') && is_array(DEFAULT_SETTINGS)) {
        foreach (DEFAULT_SETTINGS as $k => $v) {
            $ins->execute([$k, $v]);
        }
    }

    // Auto-seed banner paths if the files shipped with the repo are present
    foreach ([
        'banner_path'        => '/uploads/banner.png',
        'header_banner_path' => '/uploads/header_banner.png',
    ] as $key => $path) {
        if (file_exists(__DIR__ . $path)) {
            $ins->execute([$key, $path]);
        }
    }

    // Seed a default admin if no users exist
    $count = $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    if ((int)$count === 0) {
        $hash = password_hash('admin', PASSWORD_BCRYPT);
        $pdo->prepare(
            "INSERT INTO users (username, password_hash, email, role, must_change_password, email_verified) VALUES (?, ?, ?, 'admin', 1, 1)"
        )->execute(['admin', $hash, 'admin@localhost']);
    }

    // Seed a welcome post once on first install (never re-create if user deleted it)
    $welcomeSeeded = $pdo->query("SELECT COUNT(*) FROM site_settings WHERE key='welcome_post_seeded'")->fetchColumn();
    if ((int)$welcomeSeeded === 0) {
        $pdo->prepare("INSERT INTO site_settings (key, value) VALUES ('welcome_post_seeded', '1')")->execute();
        $postCount = $pdo->query('SELECT COUNT(*) FROM posts')->fetchColumn();
    if ((int)$postCount === 0) {
        $welcomeContent = '<img src="/uploads/header_banner.png" alt="Welcome to Game Night" style="width:100%;border-radius:8px;margin-bottom:1rem">'
            . '<p style="font-size:1.1rem">Hey there, welcome to <strong>Game Night</strong>! You\'ve just set up your very own hub for organizing game nights, poker tournaments, and get-togethers with friends. This is your home base &mdash; let\'s show you around.</p>'
            . '<h3>What Can You Do Here?</h3>'
            . '<ul>'
            . '<li><strong>Plan Events</strong> &mdash; Create game nights, set the date and time, and invite your crew. Everyone gets notified and can RSVP so you know who\'s showing up.</li>'
            . '<li><strong>Run Poker Games</strong> &mdash; Got a poker night? Toggle "Poker Game" on any event to unlock the full check-in dashboard. Track buy-ins, rebuys, eliminations, payouts &mdash; the works. Supports both tournaments and cash games.</li>'
            . '<li><strong>RSVP Tracking</strong> &mdash; No more "wait, are you coming?" texts. Invitees can RSVP Yes, No, or Maybe right from their notification or the calendar.</li>'
            . '<li><strong>Post Updates</strong> &mdash; Use posts (like this one!) to share news, house rules, trash talk, or anything else with your group.</li>'
            . '<li><strong>Customize Everything</strong> &mdash; Head to <em>Admin &gt; Settings</em> to change your site name, upload a logo, pick your colors, set your timezone, and configure email/SMS notifications.</li>'
            . '</ul>'
            . '<h3>Getting Started</h3>'
            . '<ol>'
            . '<li><strong>Change your password</strong> &mdash; You\'re logged in as <code>admin</code> with the default password. Change it now (seriously).</li>'
            . '<li><strong>Invite your friends</strong> &mdash; Have them sign up, or create their accounts in Admin &gt; Users.</li>'
            . '<li><strong>Create your first event</strong> &mdash; Hit the Calendar, tap the <strong>+</strong> button, and set up your next game night.</li>'
            . '<li><strong>Make it yours</strong> &mdash; Upload your own banner, pick a site name, and delete this post when you\'re ready.</li>'
            . '</ol>'
            . '<p style="margin-top:1.5rem;padding:1rem;background:#f0fdf4;border-radius:8px;border:1px solid #bbf7d0">'
            . 'This post is pinned to the top so new visitors see it first. When you\'re ready to roll, just delete it or unpin it and start posting your own updates. Have fun out there!</p>';
        $pdo->prepare('INSERT INTO posts (title, content, pinned) VALUES (?, ?, 1)')
            ->execute(['Welcome to Game Night!', $welcomeContent]);
    }
    }
}

// Settings that contain secrets — automatically encrypted at rest
define('ENCRYPTED_SETTINGS', [
    'smtp_pass', 'smtp_password',
    'sms_token', 'sms_webhook_secret',
    'wa_token',
    'shortio_api_key',
]);

$_settings_cache = [];

function get_setting(string $key, string $default = ''): string {
    global $_settings_cache;
    if (!isset($_settings_cache[$key])) {
        $stmt = get_db()->prepare('SELECT value FROM site_settings WHERE key = ?');
        $stmt->execute([$key]);
        $row = $stmt->fetchColumn();
        $val = $row !== false ? $row : $default;
        // Decrypt sensitive settings
        if (in_array($key, ENCRYPTED_SETTINGS, true) && $val !== '' && $val !== $default) {
            $val = decrypt_value($val);
        }
        $_settings_cache[$key] = $val;
    }
    return $_settings_cache[$key];
}

function set_setting(string $key, string $value): void {
    global $_settings_cache;
    // Encrypt sensitive settings before storing
    $store = $value;
    if (in_array($key, ENCRYPTED_SETTINGS, true) && $value !== '') {
        $store = encrypt_value($value);
    }
    get_db()->prepare('INSERT INTO site_settings (key, value) VALUES (?, ?)
        ON CONFLICT(key) DO UPDATE SET value = excluded.value')
        ->execute([$key, $store]);
    $_settings_cache[$key] = $value; // cache the decrypted value
}

// ─── In-app help bubbles ────────────────────────────────────────────────
// Curated whitelist of screens the admin can attach help tips to. Keys are the
// page basename (basename($_SERVER['SCRIPT_NAME'], '.php')); values are labels
// for the admin dropdown. Both admin_help.php and _footer.php read this.
define('HELP_SCREENS', [
    'index'      => 'Home / Dashboard',
    'calendar'   => 'Calendar',
    'event_edit' => 'Event editor (add/edit event)',
    'my_events'  => 'My Events',
    'event'      => 'Event page',
    'leagues'    => 'Leagues list',
    'league'     => 'League page',
    'contacts'   => 'Contacts',
    'checkin'    => 'Check-in',
    'walkin'     => 'Walk-in registration',
    'stats'      => 'Stats',
    'timer'      => 'Tournament Timer',
    'settings'   => 'My Settings',
]);

/** Enabled help tips for a screen, in display order. */
function help_bubbles_for_screen(string $screen): array {
    if ($screen === '' || !array_key_exists($screen, HELP_SCREENS)) return [];
    $stmt = get_db()->prepare(
        'SELECT id, screen_key, title, body, anchor_selector, bubble_index, always_show, sort_order, enabled
         FROM help_bubbles WHERE screen_key = ? AND enabled = 1
         ORDER BY sort_order, id'
    );
    $stmt->execute([$screen]);
    return $stmt->fetchAll();
}

/**
 * Enabled tips this user should still be shown on a screen: pinned ones
 * (always_show) plus any the user hasn't individually dismissed. Empty array
 * means "everything dismissed" — the footer then offers only the "?" pill.
 */
function help_fresh_bubbles_for_screen(int $userId, string $screen): array {
    if ($screen === '' || !array_key_exists($screen, HELP_SCREENS)) return [];
    $stmt = get_db()->prepare(
        'SELECT b.id, b.screen_key, b.title, b.body, b.anchor_selector, b.bubble_index, b.always_show, b.sort_order, b.enabled
         FROM help_bubbles b
         LEFT JOIN user_help_bubble_dismissed d ON d.bubble_id = b.id AND d.user_id = ?
         WHERE b.screen_key = ? AND b.enabled = 1 AND (b.always_show = 1 OR d.bubble_id IS NULL)
         ORDER BY b.sort_order, b.id'
    );
    $stmt->execute([$userId, $screen]);
    return $stmt->fetchAll();
}

/**
 * Record the user's X on a screen's help: dismisses each non-pinned enabled
 * bubble individually (idempotent). Pinned (always_show) bubbles are never
 * recorded so they reappear next visit; bubbles added later have no row and
 * therefore auto-show too.
 */
function help_dismiss_screen(int $userId, string $screen): void {
    get_db()->prepare(
        'INSERT OR IGNORE INTO user_help_bubble_dismissed (user_id, bubble_id)
         SELECT ?, id FROM help_bubbles WHERE screen_key = ? AND enabled = 1 AND always_show = 0'
    )->execute([$userId, $screen]);
}

/** Clear all of a user's dismissals so every screen's help shows again. */
function help_reset_user(int $userId): void {
    get_db()->prepare('DELETE FROM user_help_bubble_dismissed WHERE user_id = ?')->execute([$userId]);
    get_db()->prepare('DELETE FROM user_help_dismissed WHERE user_id = ?')->execute([$userId]);
}

/**
 * Admin-configurable allowlist of extra hosts the tournament-timer streaming
 * panel may embed (on top of the built-in YouTube/Twitch/Vimeo/Kick/Prime set).
 * Stored in the `stream_allowed_hosts` setting as a free-form list; this parses
 * and STRICTLY validates it so the output is safe to splice into a CSP frame-src
 * directive and into the client-side embed allowlist.
 *
 * Each entry may be a bare hostname (sub.example.com) or a single-level wildcard
 * (*.example.com). Pasted full URLs are tolerated (scheme/path are stripped).
 * Anything containing CSP-significant characters (spaces, ; , : ' " /) is rejected.
 * Returns a de-duplicated, lowercased list of host patterns.
 */
function stream_allowed_hosts(): array {
    $raw = get_setting('stream_allowed_hosts', '');
    if ($raw === '') return [];
    $out = [];
    foreach (preg_split('/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) as $tok) {
        $h = strtolower(trim($tok));
        $h = preg_replace('#^https?://#', '', $h); // tolerate a pasted URL
        $h = preg_replace('#/.*$#', '', $h);        // drop any path
        // Bare hostname or "*." + hostname; no ports, no other punctuation.
        if (preg_match('/^(\*\.)?([a-z0-9-]+\.)+[a-z]{2,}$/', $h)) {
            $out[$h] = true;
        }
    }
    return array_keys($out);
}

/**
 * Emit SEO + social-share meta tags into a page <head>. Pages keep their own
 * <title>; this adds the description, canonical link, Open Graph, and Twitter
 * card tags so search snippets and shared-link previews look right.
 * $path is the request path relative to the site root ('' for the homepage,
 * 'help-hosts.php' for a page, etc.).
 */
function render_seo_meta(string $title, string $description, string $path = ''): void {
    $site   = get_site_url();
    $url    = $site . '/' . ltrim($path, '/');
    $name   = get_setting('site_name', 'Game Night');
    $img    = get_setting('header_banner_path', '');
    if ($img === '') $img = get_setting('banner_path', '');
    $imgAbs = $img !== '' ? $site . $img : '';
    $e = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES);
    echo "\n    <meta name=\"description\" content=\"" . $e($description) . "\">";
    echo "\n    <link rel=\"canonical\" href=\"" . $e($url) . "\">";
    echo "\n    <meta property=\"og:type\" content=\"website\">";
    echo "\n    <meta property=\"og:site_name\" content=\"" . $e($name) . "\">";
    echo "\n    <meta property=\"og:title\" content=\"" . $e($title) . "\">";
    echo "\n    <meta property=\"og:description\" content=\"" . $e($description) . "\">";
    echo "\n    <meta property=\"og:url\" content=\"" . $e($url) . "\">";
    if ($imgAbs !== '') echo "\n    <meta property=\"og:image\" content=\"" . $e($imgAbs) . "\">";
    echo "\n    <meta name=\"twitter:card\" content=\"" . ($imgAbs !== '' ? 'summary_large_image' : 'summary') . "\">";
    echo "\n    <meta name=\"twitter:title\" content=\"" . $e($title) . "\">";
    echo "\n    <meta name=\"twitter:description\" content=\"" . $e($description) . "\">";
    if ($imgAbs !== '') echo "\n    <meta name=\"twitter:image\" content=\"" . $e($imgAbs) . "\">";
}

/**
 * Fetch the latest published APP_VERSION from the public GitHub repo.
 * Returns the version string (e.g. "0.19301") or null on any failure —
 * no exceptions escape, so callers can treat null as "couldn't check".
 */
function fetch_remote_version(): ?string {
    if (!defined('UPDATE_SOURCE_URL') || UPDATE_SOURCE_URL === '') return null;
    $ch = curl_init(UPDATE_SOURCE_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_USERAGENT      => 'GameNight-update-check',
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    // curl_close() intentionally omitted — a no-op (and deprecated) on PHP 8+.
    if ($body === false || $code !== 200) return null;
    if (!preg_match("/define\\(\\s*'APP_VERSION'\\s*,\\s*'([^']+)'\\s*\\)/", $body, $m)) return null;
    $v = trim($m[1]);
    return $v !== '' ? $v : null;
}

/**
 * Refresh the cached latest_version from GitHub, at most once per 24h
 * (pass $force = true to bypass the gate, e.g. an admin "Check now").
 * On a failed fetch the previously cached value is left untouched, so a
 * GitHub blip never clears a known-good value.
 *
 * Returns true if a version was successfully fetched this call. With
 * $force = true a false return means the fetch failed; without $force a
 * false return may also mean the 24h gate skipped the check.
 */
function run_update_check(bool $force = false): bool {
    if (!$force) {
        $last = (int)get_setting('latest_version_checked_at', '0');
        if (time() - $last < 86400) return false;
    }
    $remote = fetch_remote_version();
    // Always record that we attempted a check; only overwrite the version on success.
    set_setting('latest_version_checked_at', (string)time());
    if ($remote !== null) {
        set_setting('latest_version', $remote);
        return true;
    }
    return false;
}

/**
 * True iff a successfully-fetched latest_version is strictly newer than the
 * running APP_VERSION. Cheap (cached settings read) — safe to call per-request.
 */
function update_available(): bool {
    $latest = get_setting('latest_version', '');
    if ($latest === '') return false;
    return version_compare(APP_VERSION, $latest, '<');
}

/**
 * Named timezone options shown to both admins (site-wide setting) and users (personal pref).
 * Keys are human-readable labels; values are IANA identifiers. Order is by UTC offset.
 */
function get_timezone_options(): array {
    static $opts = null;
    if ($opts === null) {
        $opts = [
            'UTC-12:00 — International Date Line West'          => 'Etc/GMT+12',
            'UTC-11:00 — American Samoa'                        => 'Pacific/Pago_Pago',
            'UTC-10:00 — Hawaii'                                => 'Pacific/Honolulu',
            'UTC-09:30 — Marquesas Islands'                     => 'Pacific/Marquesas',
            'UTC-09:00 — Alaska'                                => 'America/Anchorage',
            'UTC-08:00 — Pacific Time (US & Canada)'            => 'America/Los_Angeles',
            'UTC-07:00 — Mountain Time (US & Canada)'           => 'America/Denver',
            'UTC-07:00 — Arizona (no DST)'                      => 'America/Phoenix',
            'UTC-06:00 — Central Time (US & Canada)'            => 'America/Chicago',
            'UTC-05:00 — Eastern Time (US & Canada)'            => 'America/New_York',
            'UTC-04:00 — Atlantic Time (Canada)'                => 'America/Halifax',
            'UTC-03:30 — Newfoundland'                          => 'America/St_Johns',
            'UTC-03:00 — Buenos Aires'                          => 'America/Argentina/Buenos_Aires',
            'UTC-03:00 — Sao Paulo'                             => 'America/Sao_Paulo',
            'UTC-02:00 — Mid-Atlantic'                          => 'Etc/GMT+2',
            'UTC-01:00 — Azores'                                => 'Atlantic/Azores',
            'UTC+00:00 — London, Dublin, Lisbon'                => 'Europe/London',
            'UTC+00:00 — Reykjavik (no DST)'                    => 'Atlantic/Reykjavik',
            'UTC+01:00 — Paris, Berlin, Rome, Madrid'           => 'Europe/Paris',
            'UTC+02:00 — Helsinki, Cairo, Johannesburg'         => 'Europe/Helsinki',
            'UTC+03:00 — Moscow, Nairobi'                       => 'Europe/Moscow',
            'UTC+03:00 — Baghdad'                               => 'Asia/Baghdad',
            'UTC+03:30 — Tehran'                                => 'Asia/Tehran',
            'UTC+04:00 — Dubai, Abu Dhabi'                      => 'Asia/Dubai',
            'UTC+04:00 — Baku'                                  => 'Asia/Baku',
            'UTC+04:30 — Kabul'                                 => 'Asia/Kabul',
            'UTC+05:00 — Karachi, Islamabad'                    => 'Asia/Karachi',
            'UTC+05:30 — Mumbai, Kolkata, New Delhi'            => 'Asia/Kolkata',
            'UTC+05:45 — Kathmandu'                             => 'Asia/Kathmandu',
            'UTC+06:00 — Dhaka, Almaty'                         => 'Asia/Dhaka',
            'UTC+06:30 — Yangon'                                => 'Asia/Yangon',
            'UTC+07:00 — Bangkok, Hanoi, Jakarta'               => 'Asia/Bangkok',
            'UTC+08:00 — Beijing, Singapore, Hong Kong'         => 'Asia/Shanghai',
            'UTC+08:00 — Perth'                                 => 'Australia/Perth',
            'UTC+08:45 — Eucla'                                 => 'Australia/Eucla',
            'UTC+09:00 — Tokyo, Osaka'                          => 'Asia/Tokyo',
            'UTC+09:00 — Seoul'                                 => 'Asia/Seoul',
            'UTC+09:30 — Darwin (no DST)'                       => 'Australia/Darwin',
            'UTC+09:30 — Adelaide'                              => 'Australia/Adelaide',
            'UTC+10:00 — Sydney, Melbourne'                     => 'Australia/Sydney',
            'UTC+10:00 — Brisbane (no DST)'                     => 'Australia/Brisbane',
            'UTC+10:30 — Lord Howe Island'                      => 'Australia/Lord_Howe',
            'UTC+11:00 — Solomon Islands, New Caledonia'        => 'Pacific/Guadalcanal',
            'UTC+12:00 — Auckland, Wellington'                  => 'Pacific/Auckland',
            'UTC+12:00 — Fiji'                                  => 'Pacific/Fiji',
            'UTC+12:45 — Chatham Islands'                       => 'Pacific/Chatham',
            'UTC+13:00 — Tonga'                                 => 'Pacific/Tongatapu',
            'UTC+13:00 — Samoa'                                 => 'Pacific/Apia',
            'UTC+14:00 — Line Islands (Kiribati)'               => 'Pacific/Kiritimati',
        ];
    }
    return $opts;
}

/**
 * Returns the IANA timezone to use for display purposes.
 * - If $user_id is passed and that user has a personal timezone set, returns it.
 * - Else if the current session is logged in and that user has a personal timezone, returns it.
 * - Otherwise falls back to the site-wide timezone from site_settings.
 *
 * Pass an explicit $user_id when rendering content addressed to a specific recipient
 * (e.g. notification bodies in cron context), where the viewer differs from the user.
 */
/**
 * Enrich an event row with `start_time_display` / `end_time_display` formatted in
 * the viewer's timezone. Event times are stored as wall-clock strings interpreted
 * in the site's timezone; this helper parses them in site tz, converts to viewer
 * tz, and formats as "g:ia" to match the calendar's JS fmt12().
 *
 * Pass $occ_date for recurring-event occurrences so the displayed date matches
 * the actual occurrence (not the master event's start_date).
 */
function event_display_times(array $ev, DateTimeZone $site_tz, DateTimeZone $viewer_tz, ?string $occ_date = null): array {
    $ev['start_time_display'] = '';
    $ev['end_time_display']   = '';
    // `_input` fields are the same instants formatted as Y-m-d / H:i so the edit form's
    // <input type="date"> and <input type="time"> pre-fill in the viewer's tz.
    // Defaults match the raw fields so the JS can fall through when no time is set.
    $ev['start_date_input']   = $ev['start_date'] ?? null;
    $ev['end_date_input']     = $ev['end_date']   ?? null;
    $ev['start_time_input']   = !empty($ev['start_time']) ? substr($ev['start_time'], 0, 5) : null;
    $ev['end_time_input']     = !empty($ev['end_time'])   ? substr($ev['end_time'],   0, 5) : null;

    $base_date = $occ_date ?: ($ev['start_date'] ?? null);
    if ($base_date && !empty($ev['start_time'])) {
        $dt = new DateTime($base_date . ' ' . $ev['start_time'], $site_tz);
        $dt->setTimezone($viewer_tz);
        $ev['start_time_display'] = $dt->format('g:ia');
        $ev['start_time_input']   = $dt->format('H:i');
        $ev['start_date_input']   = $dt->format('Y-m-d');
    }
    if ($base_date && !empty($ev['end_time'])) {
        $end_base = $ev['end_date'] ?: $base_date;
        $dt = new DateTime($end_base . ' ' . $ev['end_time'], $site_tz);
        $dt->setTimezone($viewer_tz);
        $ev['end_time_display'] = $dt->format('g:ia');
        $ev['end_time_input']   = $dt->format('H:i');
        $ev['end_date_input']   = $dt->format('Y-m-d');
    }
    return $ev;
}

/**
 * Convert a (date, time) pair submitted by the form (in the viewer's tz) into the
 * site-tz wall-clock equivalent for storage. Returns ['date' => 'Y-m-d', 'time' => 'H:i'].
 * If $time is null or empty, returns the date untouched (all-day events have no tz).
 */
function form_datetime_to_site_tz(string $date, ?string $time, DateTimeZone $viewer_tz, DateTimeZone $site_tz): array {
    if ($time === null || $time === '') {
        return ['date' => $date, 'time' => null];
    }
    $dt = new DateTime($date . ' ' . $time, $viewer_tz);
    $dt->setTimezone($site_tz);
    return ['date' => $dt->format('Y-m-d'), 'time' => $dt->format('H:i')];
}

function display_timezone(?int $user_id = null): string {
    static $cache = [];
    $site_tz = get_setting('timezone', 'UTC');
    $uid = $user_id ?? ($_SESSION['user_id'] ?? null);
    if (!$uid) return $site_tz;
    if (!array_key_exists($uid, $cache)) {
        $stmt = get_db()->prepare('SELECT timezone FROM users WHERE id = ?');
        $stmt->execute([$uid]);
        $tz = (string)$stmt->fetchColumn();
        $cache[$uid] = ($tz !== '' && in_array($tz, DateTimeZone::listIdentifiers(), true)) ? $tz : null;
    }
    return $cache[$uid] ?? $site_tz;
}

/**
 * Re-anchor every timed event's stored wall-clock from $oldTz to $newTz so each
 * event keeps its real absolute instant when the site timezone is changed.
 *
 * Event times are stored as wall-clock in the site timezone, so naively changing
 * the site tz would silently shift every event's displayed time (and desync the
 * UTC reminder schedule). By converting the stored wall-clock through old->new,
 * the absolute instant is preserved: no viewer's displayed time changes, and
 * reminders (stored in UTC) stay valid. All-day events (no start_time) are
 * date-only and tz-agnostic, so they are left untouched. Returns events updated.
 *
 * Call this BEFORE persisting the new timezone setting. Safe to nest inside an
 * existing transaction; rolls back its own work and returns 0 on failure.
 */
function rebase_event_times_for_tz_change(PDO $db, string $oldTz, string $newTz): int {
    if ($oldTz === $newTz || $oldTz === '' || $newTz === '') return 0;
    $known = DateTimeZone::listIdentifiers();
    if (!in_array($oldTz, $known, true) || !in_array($newTz, $known, true)) return 0;

    $from = new DateTimeZone($oldTz);
    $to   = new DateTimeZone($newTz);

    $rows = $db->query(
        "SELECT id, start_date, start_time, end_date, end_time
           FROM events
          WHERE start_time IS NOT NULL AND start_time <> ''"
    )->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return 0;

    $upd = $db->prepare('UPDATE events SET start_date=?, start_time=?, end_date=?, end_time=? WHERE id=?');
    $ownTxn = !$db->inTransaction();
    if ($ownTxn) $db->beginTransaction();
    try {
        $n = 0;
        foreach ($rows as $r) {
            $startDt = new DateTime($r['start_date'] . ' ' . $r['start_time'], $from);
            $startDt->setTimezone($to);
            $newSd = $startDt->format('Y-m-d');
            $newSt = $startDt->format('H:i');

            $newEd = null; $newEt = null;
            if (!empty($r['end_time'])) {
                // End instant uses the explicit end_date if set, else the ORIGINAL start_date.
                $endBase = $r['end_date'] ?: $r['start_date'];
                $endDt = new DateTime($endBase . ' ' . $r['end_time'], $from);
                $endDt->setTimezone($to);
                $newEt = $endDt->format('H:i');
                $endDate = $endDt->format('Y-m-d');
                // Mirror the editor convention: only store end_date when it differs from start.
                $newEd = ($endDate !== $newSd) ? $endDate : null;
            } elseif (!empty($r['end_date'])) {
                // Multi-day span without an end time: dates are tz-agnostic, keep as-is.
                $newEd = $r['end_date'];
            }

            $upd->execute([$newSd, $newSt, $newEd, $newEt, $r['id']]);
            $n++;
        }
        if ($ownTxn) $db->commit();
        return $n;
    } catch (Throwable $e) {
        if ($ownTxn && $db->inTransaction()) $db->rollBack();
        error_log('[GameNight] rebase_event_times_for_tz_change failed: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Returns the approval_status a new event_invites row should be created with,
 * given the source of the signup:
 *   - 'creator' — added by the creator/manager via the editor (always 'approved')
 *   - 'self'    — user self-signed-up via the sign-up button or walk-in QR
 *
 * Self-signups are 'pending' only if the event has requires_approval=1, otherwise
 * 'approved' (preserving current behavior for events without the gate enabled).
 */
function invite_approval_status(int $event_id, string $source): string {
    if ($source === 'creator') return 'approved';
    $stmt = get_db()->prepare('SELECT requires_approval FROM events WHERE id = ?');
    $stmt->execute([$event_id]);
    return ((int)$stmt->fetchColumn() === 1) ? 'pending' : 'approved';
}

/**
 * Notify the event creator that a new pending signup is waiting for approval.
 * Used by walk-in and self-signup paths. Quietly does nothing if notifications
 * are globally disabled. Caller must have already loaded auth.php (which defines
 * send_notification()) — this function does not include it, because doing so
 * would redeclare conflict with auth_dl.php in contexts like walkin.php.
 */
function notify_creator_of_pending(int $event_id, string $signup_username): void {
    if (get_setting('notifications_enabled', '0') !== '1') return;
    if (!function_exists('send_notification')) return;
    $db = get_db();
    $stmt = $db->prepare('SELECT e.title, e.start_date, u.username, u.email, u.phone, u.preferred_contact
                          FROM events e JOIN users u ON u.id = e.created_by WHERE e.id = ?');
    $stmt->execute([$event_id]);
    $row = $stmt->fetch();
    if (!$row) return;
    $month  = substr($row['start_date'], 0, 7);
    $url    = get_site_url() . '/calendar.php?m=' . urlencode($month) . '&open=' . $event_id . '&date=' . urlencode($row['start_date']);
    $smsBody = "$signup_username is waiting for approval to join \"{$row['title']}\" on {$row['start_date']}. Review: $url";
    $htmlBody = '<p><strong>' . htmlspecialchars($signup_username) . '</strong> is waiting for your approval to join '
              . '<em>' . htmlspecialchars($row['title']) . '</em> on ' . htmlspecialchars($row['start_date']) . '.</p>'
              . '<p style="margin-top:1.5rem"><a href="' . htmlspecialchars($url) . '" style="background:#2563eb;color:#fff;padding:.5rem 1.2rem;border-radius:6px;text-decoration:none;font-weight:600">Review Pending Signups</a></p>';
    send_notification($row['username'], $row['email'] ?? '', $row['phone'] ?? '',
        $row['preferred_contact'] ?? 'email',
        'New signup waiting for approval: ' . $row['title'],
        $smsBody, $htmlBody);
}

/**
 * Cascade-delete a league and all its dependent rows:
 *   events -> (poker_sessions -> poker_players/payouts/timer_state), event_invites,
 *   event_exceptions, event_notifications_sent, comments; then league_join_requests,
 *   league_members, leagues.
 * Used by the league-owner delete UI AND by the user-delete cleanup when a league
 * would otherwise become orphaned.
 */
function delete_league_cascade(PDO $db, int $league_id): void {
    $evIds = $db->prepare('SELECT id FROM events WHERE league_id = ?');
    $evIds->execute([$league_id]);
    $evIds = array_map('intval', array_column($evIds->fetchAll(), 'id'));

    if (!empty($evIds)) {
        $placeholders = implode(',', array_fill(0, count($evIds), '?'));

        // Poker-session cascade
        $sessIds = $db->prepare("SELECT id FROM poker_sessions WHERE event_id IN ($placeholders)");
        $sessIds->execute($evIds);
        $sessIds = array_map('intval', array_column($sessIds->fetchAll(), 'id'));
        if (!empty($sessIds)) {
            $sp = implode(',', array_fill(0, count($sessIds), '?'));
            $db->prepare("DELETE FROM poker_players  WHERE session_id IN ($sp)")->execute($sessIds);
            $db->prepare("DELETE FROM poker_payouts  WHERE session_id IN ($sp)")->execute($sessIds);
            $db->prepare("DELETE FROM timer_state    WHERE session_id IN ($sp)")->execute($sessIds);
            $db->prepare("DELETE FROM poker_sessions WHERE id         IN ($sp)")->execute($sessIds);
        }

        $db->prepare("DELETE FROM event_invites    WHERE event_id IN ($placeholders)")->execute($evIds);
        $db->prepare("DELETE FROM event_exceptions WHERE event_id IN ($placeholders)")->execute($evIds);
        try { $db->prepare("DELETE FROM event_notifications_sent WHERE event_id IN ($placeholders)")->execute($evIds); } catch (Throwable $e) {}
        try { $db->prepare("DELETE FROM pending_notifications    WHERE event_id IN ($placeholders)")->execute($evIds); } catch (Throwable $e) {}
        $db->prepare("DELETE FROM comments WHERE type='event' AND content_id IN ($placeholders)")->execute($evIds);
        $db->prepare("DELETE FROM events   WHERE id IN ($placeholders)")->execute($evIds);
    }

    // League-scoped posts and their comments
    try {
        $postIds = $db->prepare('SELECT id FROM posts WHERE league_id = ?');
        $postIds->execute([$league_id]);
        $postIds = array_map('intval', array_column($postIds->fetchAll(), 'id'));
        if (!empty($postIds)) {
            $pp = implode(',', array_fill(0, count($postIds), '?'));
            $db->prepare("DELETE FROM comments WHERE type='post' AND content_id IN ($pp)")->execute($postIds);
            $db->prepare("DELETE FROM posts WHERE id IN ($pp)")->execute($postIds);
        }
    } catch (Throwable $e) {}

    $db->prepare('DELETE FROM league_join_requests WHERE league_id = ?')->execute([$league_id]);
    $db->prepare('DELETE FROM league_members       WHERE league_id = ?')->execute([$league_id]);
    $db->prepare('DELETE FROM leagues              WHERE id = ?')        ->execute([$league_id]);
}

/**
 * Fully delete a user and all associated data (invites, comments, tokens, etc.).
 * Poker players are soft-removed (removed=1) to preserve game history.
 *
 * League ownership: if the user owns any leagues, ownership auto-transfers to the
 * longest-tenured manager (or oldest member if no managers). If no one else is
 * in the league, the league is cascade-deleted.
 */
function delete_user_account(int $user_id): void {
    $db = get_db();
    $un = $db->prepare('SELECT username FROM users WHERE id = ?');
    $un->execute([$user_id]);
    $username = $un->fetchColumn();

    // ── League ownership transfer / cascade delete for orphaned leagues ──
    $ownedStmt = $db->prepare('SELECT id FROM leagues WHERE owner_id = ?');
    $ownedStmt->execute([$user_id]);
    foreach ($ownedStmt->fetchAll() as $ownedRow) {
        $league_id = (int)$ownedRow['id'];
        // Find a successor: prefer manager, fall back to any member, excluding the deleted user
        $succ = $db->prepare(
            "SELECT user_id FROM league_members
             WHERE league_id = ? AND user_id <> ? AND user_id IS NOT NULL
             ORDER BY CASE role WHEN 'manager' THEN 0 ELSE 1 END, joined_at ASC
             LIMIT 1"
        );
        $succ->execute([$league_id, $user_id]);
        $new_owner = $succ->fetchColumn();
        if ($new_owner) {
            $db->prepare('UPDATE leagues SET owner_id = ? WHERE id = ?')->execute([(int)$new_owner, $league_id]);
            $db->prepare("UPDATE league_members SET role = 'owner' WHERE league_id = ? AND user_id = ?")
               ->execute([$league_id, (int)$new_owner]);
        } else {
            // Nobody else to own it — cascade-delete the whole league
            delete_league_cascade($db, $league_id);
        }
    }

    // Remove remaining league memberships (for leagues where user was member/manager, not owner)
    $db->prepare('DELETE FROM league_members WHERE user_id = ?')->execute([$user_id]);
    $db->prepare('DELETE FROM league_join_requests WHERE user_id = ?')->execute([$user_id]);

    // Personal contacts owned by this user → delete; contacts linked to this user → unlink
    try { $db->prepare('DELETE FROM user_contacts WHERE owner_user_id = ?')->execute([$user_id]); } catch (Exception $e) {}
    try { $db->prepare('UPDATE user_contacts SET linked_user_id = NULL WHERE linked_user_id = ?')->execute([$user_id]); } catch (Exception $e) {}

    if ($username) {
        $db->prepare('DELETE FROM event_invites WHERE LOWER(username) = LOWER(?)')->execute([$username]);
        // Pending queued invite notifications that targeted this username
        try { $db->prepare('DELETE FROM pending_notifications WHERE LOWER(username) = LOWER(?)')->execute([$username]); } catch (Exception $e) {}
        // Notification dedup log keyed on username — clear so re-adding the name as a custom invitee fires fresh SMS/email.
        try { $db->prepare('DELETE FROM event_notifications_sent WHERE LOWER(user_identifier) = LOWER(?)')->execute([$username]); } catch (Exception $e) {}
    }
    // NULL the user_id while preserving roster history (display_name + removed flag).
    // user_id stayed pointing at a now-dead user before; with FK enforcement on, that
    // would block the user delete.
    $db->prepare('UPDATE poker_players SET user_id = NULL, removed = 1 WHERE user_id = ?')->execute([$user_id]);
    // activity_log.user_id is NOT NULL with a FK; delete this user's audit rows (the 90d
    // cron prune would erase them eventually anyway).
    try { $db->prepare('DELETE FROM activity_log WHERE user_id = ?')->execute([$user_id]); } catch (Exception $e) {}
    // Reassign events created by this user to a fallback admin so the FK stays valid and
    // shared event history is preserved. If no other admin exists, the delete is refused.
    try {
        $hasEvents = $db->prepare('SELECT COUNT(*) FROM events WHERE created_by = ?');
        $hasEvents->execute([$user_id]);
        if ((int)$hasEvents->fetchColumn() > 0) {
            $fallback = $db->prepare("SELECT id FROM users WHERE role='admin' AND id <> ? ORDER BY id LIMIT 1");
            $fallback->execute([$user_id]);
            $fallback_id = $fallback->fetchColumn();
            if (!$fallback_id) {
                throw new RuntimeException('Cannot delete user: they own events and there is no other admin to reassign to.');
            }
            $db->prepare('UPDATE events SET created_by = ? WHERE created_by = ?')->execute([(int)$fallback_id, $user_id]);
        }
    } catch (RuntimeException $e) { throw $e; } catch (Exception $e) {}
    // Preserve league/global posts authored by this user; null out the author pointer instead of deleting.
    try { $db->prepare('UPDATE posts SET author_id = NULL WHERE author_id = ?')->execute([$user_id]); } catch (Exception $e) {}
    $db->prepare('DELETE FROM comments WHERE user_id = ?')->execute([$user_id]);
    $db->prepare('DELETE FROM password_resets WHERE user_id = ?')->execute([$user_id]);
    $db->prepare('DELETE FROM remember_tokens WHERE user_id = ?')->execute([$user_id]);
    $db->prepare('DELETE FROM email_verifications WHERE user_id = ?')->execute([$user_id]);
    $db->prepare('DELETE FROM phone_verifications WHERE user_id = ?')->execute([$user_id]);
    try { $db->prepare('DELETE FROM mfa_recovery_codes WHERE user_id = ?')->execute([$user_id]); } catch (Exception $e) {}
    try { $db->prepare('DELETE FROM sms_pending_rsvp WHERE user_id = ?')->execute([$user_id]); } catch (Exception $e) {}
    $db->prepare('DELETE FROM users WHERE id = ?')->execute([$user_id]);
}

function get_site_url(): string {
    $url = get_setting('site_url');
    if ($url !== '') return rtrim($url, '/');
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
           || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        ? 'https' : 'http';
    return $scheme . '://' . $host;
}

/**
 * Canonicalize a US phone number for storage and lookup.
 *
 * Accepts any format the user types:
 *   xxxxxxxxxx
 *   xxx-xxx-xxxx
 *   (xxx) xxx-xxxx
 *   xxx.xxx.xxxx
 *   +1 (xxx) xxx-xxxx
 *   1-xxx-xxx-xxxx
 *
 * Returns the canonical "XXX-XXX-XXXX" string used everywhere in the DB
 * (users.phone, event_invites.phone, league_members.contact_phone,
 * user_contacts.contact_phone). Anything that isn't a 10-digit NANP number
 * (or 11-digit with a leading 1) is returned unchanged — international
 * numbers are stored as typed.
 *
 * SMS provider calls use a separate helper, sms_normalize_phone(), which
 * converts the stored "XXX-XXX-XXXX" back to E.164 ("+1XXXXXXXXXX").
 */
function normalize_phone(string $phone): string {
    $phone  = trim($phone);
    $digits = preg_replace('/\D/', '', $phone);
    // Strip leading country code 1
    if (strlen($digits) === 11 && $digits[0] === '1') {
        $digits = substr($digits, 1);
    }
    if (strlen($digits) === 10) {
        return substr($digits, 0, 3) . '-' . substr($digits, 3, 3) . '-' . substr($digits, 6, 4);
    }
    return $phone; // unrecognized format — store as entered
}

// Resolve a typed username to the registered user's chosen case, so invitee
// names render the way the user spelled their own name when they registered.
// Falls back to the trimmed input for ad-hoc invitees who never registered.
function canonical_username(string $typed): string {
    $typed = trim($typed);
    if ($typed === '') return '';
    $stmt = get_db()->prepare('SELECT username FROM users WHERE LOWER(username) = LOWER(?) LIMIT 1');
    $stmt->execute([$typed]);
    $row = $stmt->fetchColumn();
    return $row !== false ? $row : $typed;
}

/**
 * Resolve an invitee's email/phone from a user's saved contacts (user_contacts).
 *
 * Used to back-fill invites that were saved with no contact info (e.g. a contact added
 * to an event before its email/phone was on file). Matches the invite's username against
 * the contact's name OR a linked registered user's username, preferring rows that
 * actually carry an email. Returns ['email'=>'', 'phone'=>''] when nothing matches.
 */
function invitee_contact_from_contacts(PDO $db, int $ownerId, string $username): array {
    $username = trim($username);
    if ($ownerId <= 0 || $username === '') return ['email' => '', 'phone' => ''];
    $stmt = $db->prepare(
        "SELECT c.contact_email AS email, c.contact_phone AS phone
         FROM user_contacts c
         LEFT JOIN users u ON u.id = c.linked_user_id
         WHERE c.owner_user_id = ?
           AND (LOWER(c.contact_name) = LOWER(?) OR LOWER(u.username) = LOWER(?))
         ORDER BY (c.contact_email IS NOT NULL AND c.contact_email <> '') DESC, c.id
         LIMIT 1"
    );
    $stmt->execute([$ownerId, $username, $username]);
    $r = $stmt->fetch();
    return ['email' => $r['email'] ?? '', 'phone' => $r['phone'] ?? ''];
}

function get_client_ip(): string {
    // X-Real-IP is set by the nginx reverse proxy
    if (!empty($_SERVER['HTTP_X_REAL_IP'])
        && filter_var($_SERVER['HTTP_X_REAL_IP'], FILTER_VALIDATE_IP)
    ) {
        return $_SERVER['HTTP_X_REAL_IP'];
    }
    // Fallback: first IP in X-Forwarded-For
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ip = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
        if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
    }
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

/**
 * Sanitize HTML from the WYSIWYG editor.
 * Allows safe formatting tags and attributes; strips scripts,
 * event handlers, and dangerous URL schemes.
 */
function sanitize_html(string $html): string {
    if (trim($html) === '') return '';

    $allowed_tags = [
        'p', 'br', 'hr', 'div', 'span',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'strong', 'em', 'u', 's', 'b', 'i',
        'ul', 'ol', 'li',
        'blockquote', 'pre', 'code',
        'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'caption',
        'a', 'img',
        'iframe',
    ];

    // Per-tag allowed attributes (in addition to global ones)
    $tag_attrs = [
        'a'      => ['href', 'title', 'target', 'rel'],
        'img'    => ['src', 'alt', 'width', 'height', 'title'],
        'td'     => ['colspan', 'rowspan'],
        'th'     => ['colspan', 'rowspan', 'scope'],
        'iframe' => ['src', 'width', 'height', 'title', 'allow', 'allowfullscreen', 'frameborder', 'loading', 'referrerpolicy'],
    ];
    $global_attrs = ['class', 'style', 'id'];
    $safe_schemes = ['http', 'https', 'mailto'];

    // iframe sources are restricted to known-safe embed providers. Anything else
    // gets the iframe tag itself unwrapped (text content kept, frame discarded).
    // Hosts are matched on the URL host being equal to or a subdomain of one of these.
    $iframe_allowed_hosts = [
        'youtube.com', 'youtube-nocookie.com',
        'vimeo.com', 'player.vimeo.com',
        'open.spotify.com',
        'twitch.tv',
        'google.com',  // for /maps/embed; path is checked below
    ];

    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<html><head><meta charset="UTF-8"></head><body>' . $html . '</body></html>');
    libxml_clear_errors();

    // True if $url's host is an allowed iframe provider AND, for google.com,
    // the path is /maps/embed (so we don't allow arbitrary google subpages).
    $iframe_src_ok = function (string $url) use ($iframe_allowed_hosts): bool {
        $url = trim($url);
        if ($url === '') return false;
        $parts = parse_url($url);
        if (!$parts || empty($parts['scheme']) || empty($parts['host'])) return false;
        if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) return false;
        $host = strtolower($parts['host']);
        $path = $parts['path'] ?? '';
        foreach ($iframe_allowed_hosts as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.' . $allowed)) {
                // Restrict google.com to the maps embed path; other google subpaths are not safe.
                if ($allowed === 'google.com' && strpos($path, '/maps/embed') !== 0) return false;
                return true;
            }
        }
        return false;
    };

    $walk = function (DOMNode $node) use (
        &$walk, $allowed_tags, $tag_attrs, $global_attrs, $safe_schemes, $iframe_src_ok
    ): void {
        $to_remove = [];
        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_COMMENT_NODE) {
                $to_remove[] = [$child, false];
                continue;
            }
            if ($child->nodeType !== XML_ELEMENT_NODE) continue;

            $tag = strtolower($child->nodeName);

            if (!in_array($tag, $allowed_tags, true)) {
                $to_remove[] = [$child, true]; // unwrap: keep text, drop tag
                continue;
            }

            // Iframes: must point at a known-safe embed provider, or the whole tag is unwrapped.
            if ($tag === 'iframe') {
                $src = $child->getAttribute('src');
                if (!$iframe_src_ok($src)) {
                    $to_remove[] = [$child, true];
                    continue;
                }
            }

            // Strip disallowed attributes
            $drop_attrs = [];
            foreach ($child->attributes as $attr) {
                $name    = strtolower($attr->name);
                $allowed = array_merge($global_attrs, $tag_attrs[$tag] ?? []);
                if (!in_array($name, $allowed, true)) {
                    $drop_attrs[] = $name;
                    continue;
                }
                // Validate URL attributes
                if (in_array($name, ['href', 'src'], true)) {
                    $val    = trim($attr->value);
                    $scheme = strtolower(strtok($val, ':'));
                    // Only allow raster data URIs (not SVG, which can embed JS)
                    $safeDataUri = preg_match(
                        '#^data:image/(jpeg|png|gif|webp);base64,[a-zA-Z0-9+/=]+$#',
                        $val
                    );
                    $safe   = in_array($scheme, $safe_schemes, true)
                           || str_starts_with($val, '/')
                           || str_starts_with($val, '#')
                           || $safeDataUri;
                    if (!$safe) $drop_attrs[] = $name;
                }
                // Strip dangerous CSS in style attribute
                if ($name === 'style') {
                    $style = preg_replace(
                        '/expression\s*\(|javascript\s*:|behavior\s*:|vbscript\s*:|-moz-binding/i',
                        '',
                        $attr->value
                    );
                    $child->setAttribute('style', $style);
                }
                // Force external links to open safely
                if ($name === 'target') {
                    $child->setAttribute('target', '_blank');
                    $rel = $child->getAttribute('rel');
                    if (strpos($rel, 'noopener') === false) {
                        $child->setAttribute('rel', trim($rel . ' noopener noreferrer'));
                    }
                }
            }
            foreach ($drop_attrs as $a) $child->removeAttribute($a);

            $walk($child);
        }

        foreach ($to_remove as [$child, $unwrap]) {
            if ($unwrap) {
                while ($child->firstChild) {
                    $node->insertBefore($child->firstChild, $child);
                }
            }
            if ($child->parentNode) $child->parentNode->removeChild($child);
        }
    };

    $body = $doc->getElementsByTagName('body')->item(0);
    if (!$body) return '';
    $walk($body);

    $out = '';
    foreach ($body->childNodes as $child) {
        $out .= $doc->saveHTML($child);
    }
    return $out;
}

function db_log_activity(int $user_id, string $action, string $severity = 'info'): void {
    // Strip control characters to prevent log injection
    $action = preg_replace('/[\x00-\x1F\x7F]/', '', $action);
    $stmt = get_db()->prepare(
        'INSERT INTO activity_log (user_id, action, ip, severity) VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([$user_id, $action, get_client_ip(), $severity]);
}

function db_log_anon_activity(string $action, string $severity = 'info'): void {
    $action = preg_replace('/[\x00-\x1F\x7F]/', '', $action);
    $stmt = get_db()->prepare(
        'INSERT INTO activity_log (user_id, action, ip, severity) VALUES (0, ?, ?, ?)'
    );
    $stmt->execute([$action, get_client_ip(), $severity]);
}

/**
 * Expand a list of events into a by-date map within [rangeStart, rangeEnd].
 * Each entry is array_merged with ['occurrence_start' => YYYY-MM-DD].
 */
function build_event_by_date(array $events, string $rangeStart, string $rangeEnd, DateTimeZone $tz, array $exceptions = []): array {
    $byDate = [];
    foreach ($events as $ev) {
        $startDt = new DateTime($ev['start_date'], $tz);
        $endDt   = $ev['end_date'] ? new DateTime($ev['end_date'], $tz) : clone $startDt;
        $cur = clone $startDt;
        while ($cur <= $endDt) {
            $k = $cur->format('Y-m-d');
            if ($k >= $rangeStart && $k <= $rangeEnd) {
                $byDate[$k][] = array_merge($ev, ['occurrence_start' => $ev['start_date']]);
            }
            $cur->modify('+1 day');
        }
    }
    return $byDate;
}

/**
 * Stub kept for compatibility — recurrence was removed; always returns [].
 */
function load_exceptions(PDO $db, array $events): array {
    return [];
}

/**
 * Get the effective invite list for a specific occurrence.
 * Base rows (occurrence_date IS NULL) are merged with occurrence-specific
 * override rows (which take precedence).
 * Returns array of rows each with: username, rsvp (and optionally email, phone, preferred_contact).
 */
function get_occurrence_invitees(PDO $db, int $event_id, string $occurrence_date, bool $with_contact = true): array {
    $cols = $with_contact ? 'ei.username, ei.rsvp, u.email, u.phone, u.preferred_contact' : 'ei.username, ei.rsvp';
    $join = $with_contact ? 'JOIN users u ON LOWER(u.username) = LOWER(ei.username)' : '';

    // NOTE: the original (calendar_dl.php) version also filtered base rows on a
    // `valid_from <= occurrence_date` condition, but that column never existed in
    // the schema — the query threw "no such column" on every call, which is why
    // occurrence-cancellation notifications never went out. All base invitees count.
    $base = $db->prepare("SELECT $cols FROM event_invites ei $join
                          WHERE ei.event_id = ? AND ei.occurrence_date IS NULL");
    $base->execute([$event_id]);
    $invitees = [];
    foreach ($base->fetchAll() as $row) {
        $invitees[strtolower($row['username'])] = $row;
    }
    $occ = $db->prepare("SELECT $cols FROM event_invites ei $join
                         WHERE ei.event_id = ? AND ei.occurrence_date = ?");
    $occ->execute([$event_id, $occurrence_date]);
    foreach ($occ->fetchAll() as $row) {
        $invitees[strtolower($row['username'])] = $row; // override
    }
    return array_values($invitees);
}

/**
 * Find the next occurrence date of a recurring event on or after $from_date.
 */
function get_next_occurrence(array $ev, array $skip, string $from_date): ?string {
    if (($ev['recurrence'] ?? 'none') === 'none') {
        return $ev['start_date'] >= $from_date ? $ev['start_date'] : null;
    }
    $tz     = new DateTimeZone(get_setting('timezone', 'UTC'));
    $cur    = new DateTime($ev['start_date'], $tz);
    $recEnd = $ev['recurrence_end'] ?? null;
    $limit  = 500;
    while ($limit-- > 0) {
        if ($recEnd && $cur->format('Y-m-d') > $recEnd) break;
        $occDate = $cur->format('Y-m-d');
        if (!empty($ev['cancelled_from']) && $occDate >= $ev['cancelled_from']) break;
        if ($occDate >= $from_date && !in_array($occDate, $skip, true)) return $occDate;
        switch ($ev['recurrence']) {
            case 'daily':   $cur->modify('+1 day');   break;
            case 'weekly':  $cur->modify('+1 week');  break;
            case 'monthly': $cur->modify('+1 month'); break;
            case 'yearly':  $cur->modify('+1 year');  break;
            default: break 2;
        }
    }
    return null;
}


/**
 * Build a SQL fragment that restricts an events query to rows visible to the given viewer.
 *
 * Usage:
 *   $vis = event_visibility_sql('e', $user['id'] ?? null);
 *   $sql = "SELECT ... FROM events e WHERE start_date >= ? AND {$vis['sql']}";
 *   $stmt->execute(array_merge([$start], $vis['params']));
 *
 * Visibility rules:
 *  - Admins see everything.
 *  - Guests (user_id=null) see only 'public' events.
 *  - Logged-in users see: public + events they created + league events for leagues they're in
 *    + events where they are an explicit invitee (matched by username).
 */
function event_visibility_sql(string $alias = 'e', ?int $user_id = null): array {
    // Security: $alias is interpolated into SQL. Reject anything that isn't a
    // plain SQL identifier so a future caller can't pass user input through.
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $alias)) {
        throw new InvalidArgumentException('event_visibility_sql: invalid alias');
    }
    if ($user_id !== null) {
        $stmt = get_db()->prepare('SELECT role FROM users WHERE id = ?');
        $stmt->execute([$user_id]);
        if (($stmt->fetchColumn() ?: '') === 'admin') {
            return ['sql' => '1=1', 'params' => []];
        }
    }
    if ($user_id === null) {
        return ['sql' => "{$alias}.visibility = 'public'", 'params' => []];
    }
    $sql = "(
        {$alias}.visibility = 'public'
        OR {$alias}.created_by = ?
        OR ({$alias}.visibility = 'league' AND {$alias}.league_id IN (
               SELECT league_id FROM league_members WHERE user_id = ?
           ))
        OR EXISTS (
               SELECT 1 FROM event_invites ei
               JOIN users u ON LOWER(u.username) = LOWER(ei.username)
               WHERE ei.event_id = {$alias}.id AND u.id = ?
           )
    )";
    return ['sql' => $sql, 'params' => [$user_id, $user_id, $user_id]];
}

/**
 * Return leagues the given user is a member of, with their role.
 * Used by the event editor dropdown and UI checks.
 */
function user_leagues(int $user_id): array {
    $stmt = get_db()->prepare(
        'SELECT l.id, l.name, l.description, l.default_visibility, l.approval_mode, l.is_hidden, lm.role
         FROM league_members lm
         JOIN leagues l ON l.id = lm.league_id
         WHERE lm.user_id = ?
         ORDER BY LOWER(l.name)'
    );
    $stmt->execute([$user_id]);
    return $stmt->fetchAll();
}

/**
 * Check a user's role within a single league. Returns 'owner', 'manager', 'member', or null.
 */
/**
 * After a priority invitee declines, check if there's a waitlisted person to promote.
 * Only applies to poker events with sort_order-based priority.
 */
function maybe_promote_waitlisted(PDO $db, int $event_id): void {
    // Get the event + poker session to compute capacity
    $ev = $db->prepare('SELECT e.id, e.waitlist_enabled, ps.seats_per_table, ps.num_tables
                        FROM events e
                        LEFT JOIN poker_sessions ps ON ps.event_id = e.id
                        WHERE e.id = ? AND e.is_poker = 1');
    $ev->execute([$event_id]);
    $row = $ev->fetch();
    if (!$row || !$row['seats_per_table']) return;
    if (!(int)($row['waitlist_enabled'] ?? 1)) return; // waitlist disabled for this event

    $capacity = (int)$row['seats_per_table'] * (int)$row['num_tables'];
    if ($capacity <= 0) return;

    // Count approved invitees who haven't declined
    $approved = $db->prepare(
        "SELECT COUNT(*) FROM event_invites
         WHERE event_id = ? AND occurrence_date IS NULL
           AND approval_status = 'approved' AND (rsvp IS NULL OR rsvp != 'no')"
    );
    $approved->execute([$event_id]);
    $currentFilled = (int)$approved->fetchColumn();

    if ($currentFilled >= $capacity) return; // no open seats

    $openSeats = $capacity - $currentFilled;

    // Promote the top N waitlisted invitees
    $waitlist = $db->prepare(
        "SELECT id, username, email, phone FROM event_invites
         WHERE event_id = ? AND occurrence_date IS NULL AND approval_status = 'waitlisted'
         ORDER BY sort_order ASC
         LIMIT ?"
    );
    $waitlist->execute([$event_id, $openSeats]);
    $promoted = $waitlist->fetchAll();

    if (empty($promoted)) return;

    $upd = $db->prepare("UPDATE event_invites SET approval_status = 'approved' WHERE id = ?");
    $has_notifications = function_exists('queue_event_notification');
    if (!$has_notifications) {
        @require_once __DIR__ . '/_notifications.php';
        $has_notifications = function_exists('queue_event_notification');
    }
    foreach ($promoted as $p) {
        $upd->execute([(int)$p['id']]);
        if ($has_notifications) {
            queue_event_notification($db, $event_id, $p['username'], 'waitlist_promoted');
        }
    }

    // Re-compact sort_order so the edit view stays consistent
    recompact_sort_order($db, $event_id);
}

/**
 * Re-number sort_order for all invites on an event so that:
 *   1. Approved non-declined come first (by their current sort_order)
 *   2. Waitlisted come next
 *   3. Declined (rsvp='no') come last
 * This keeps the edit view's divider line and declined section consistent
 * after promotions or RSVP changes.
 */
function recompact_sort_order(PDO $db, int $event_id): void {
    $rows = $db->prepare(
        "SELECT id FROM event_invites
         WHERE event_id = ? AND occurrence_date IS NULL
         ORDER BY
            CASE WHEN rsvp = 'no' THEN 2
                 WHEN approval_status = 'waitlisted' THEN 1
                 ELSE 0 END,
            COALESCE(sort_order, 999999)"
    );
    $rows->execute([$event_id]);
    $upd = $db->prepare('UPDATE event_invites SET sort_order = ? WHERE id = ?');
    $i = 0;
    foreach ($rows->fetchAll() as $r) {
        $i++;
        $upd->execute([$i, (int)$r['id']]);
    }
}

/**
 * Fire-and-forget: kick off the notification-queue drain in a background process.
 * Returns immediately — the PHP web response isn't blocked by SMTP/SMS API calls.
 * Safe to call even if shell_exec is disabled (silent no-op); the 5-min cron is the safety net.
 */
function drain_queue_async(): void {
    if (!function_exists('shell_exec')) return;
    $token = get_setting('cron_token', '');
    if ($token === '') return;
    $php    = PHP_BINARY ?: '/usr/local/bin/php';
    $script = __DIR__ . '/cron_drain.php';
    // The trailing '&' backgrounds the process; redirect stdout/stderr so PHP doesn't wait
    @shell_exec(sprintf('%s %s %s > /dev/null 2>&1 &',
        escapeshellarg($php),
        escapeshellarg($script),
        escapeshellarg($token)
    ));
}

/**
 * Add a person to a user's personal contacts if not already present.
 * Called when a user invites someone to an event — organic contact list growth.
 */
function auto_add_contact(PDO $db, int $owner_user_id, string $name, string $email, string $phone): void {
    $name  = trim($name);
    $email = strtolower(trim($email));
    // Normalize the phone to E.164 so dedup and storage use one canonical form;
    // without this, "555-1234" and "(555) 1234" were treated as different people.
    $phone = trim($phone) !== '' ? normalize_phone(trim($phone)) : '';
    if ($name === '') return;
    if ($email === '' && $phone === '') return;
    // Skip if a contact already exists by email...
    if ($email !== '') {
        $chk = $db->prepare('SELECT 1 FROM user_contacts WHERE owner_user_id = ? AND LOWER(contact_email) = ? LIMIT 1');
        $chk->execute([$owner_user_id, $email]);
        if ($chk->fetchColumn()) return;
    }
    // ...or by phone. Phone-only contacts had NO dedup check before, so inviting
    // the same phone-only person to multiple events created a new row every time.
    if ($phone !== '') {
        $chk = $db->prepare('SELECT 1 FROM user_contacts WHERE owner_user_id = ? AND contact_phone = ? LIMIT 1');
        $chk->execute([$owner_user_id, $phone]);
        if ($chk->fetchColumn()) return;
    }
    // Resolve linked_user_id
    $linked = null;
    if ($email !== '') {
        $u = $db->prepare('SELECT id FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1');
        $u->execute([$email]);
        $linked = $u->fetchColumn() ?: null;
    }
    if (!$linked && $phone !== '') {
        $u = $db->prepare('SELECT id FROM users WHERE phone = ? LIMIT 1');
        $u->execute([$phone]);
        $linked = $u->fetchColumn() ?: null;
    }
    if (!$linked) {
        // Try by username (when inviting an existing user by their name)
        $u = $db->prepare('SELECT id FROM users WHERE LOWER(username) = LOWER(?) LIMIT 1');
        $u->execute([$name]);
        $linked = $u->fetchColumn() ?: null;
    }
    try {
        $db->prepare('INSERT INTO user_contacts (owner_user_id, linked_user_id, contact_name, contact_email, contact_phone) VALUES (?, ?, ?, ?, ?)')
           ->execute([$owner_user_id, $linked ?: null, $name, $email ?: null, $phone ?: null]);
    } catch (Exception $e) { /* duplicate or other constraint — fine */ }
}

function auto_add_to_league(PDO $db, int $event_id, int $user_id): void {
    if ($user_id <= 0) return;
    $ev = $db->prepare('SELECT league_id FROM events WHERE id = ?');
    $ev->execute([$event_id]);
    $lid = $ev->fetchColumn();
    if (!$lid) return;
    $db->prepare(
        "INSERT OR IGNORE INTO league_members (league_id, user_id, role, joined_at)
         VALUES (?, ?, 'member', CURRENT_TIMESTAMP)"
    )->execute([(int)$lid, $user_id]);
}

/**
 * Auto-add a custom invitee to a league as a pending contact (or as a real member if
 * their email/phone already matches a registered user). Called from the event save path
 * so inviting someone to a league event surfaces them on the league Members tab.
 *
 *  - No-op if league_id <= 0, name is empty, or both email and phone are empty.
 *  - If the contact resolves to a registered user not yet in the league, insert a
 *    regular member row (user_id set).
 *  - Otherwise insert a pending row (user_id NULL) with an invite_token so the
 *    existing claim logic in register_user() can link them on signup.
 *  - Duplicates short-circuit via pre-check plus the partial UNIQUE index
 *    (league_id, LOWER(contact_email)) WHERE user_id IS NULL.
 */
function auto_add_pending_to_league(PDO $db, int $league_id, string $name, string $email, string $phone, int $invited_by): void {
    if ($league_id <= 0) return;
    $name  = trim($name);
    $email = strtolower(trim($email));
    $phone = $phone !== '' ? normalize_phone(trim($phone)) : '';
    if ($name === '') return;

    // Linked-user path: email, phone, then username fallback (matches auto_add_contact).
    $uid = 0;
    if ($email !== '') {
        $u = $db->prepare('SELECT id FROM users WHERE LOWER(email) = ? LIMIT 1');
        $u->execute([$email]);
        $uid = (int)($u->fetchColumn() ?: 0);
    }
    if ($uid === 0 && $phone !== '') {
        $u = $db->prepare('SELECT id FROM users WHERE phone = ? LIMIT 1');
        $u->execute([$phone]);
        $uid = (int)($u->fetchColumn() ?: 0);
    }
    if ($uid === 0) {
        $u = $db->prepare('SELECT id FROM users WHERE LOWER(username) = LOWER(?) LIMIT 1');
        $u->execute([$name]);
        $uid = (int)($u->fetchColumn() ?: 0);
    }

    // Pending-contact rows need email or phone to dedup and to claim on signup.
    if ($uid === 0 && $email === '' && $phone === '') return;
    if ($uid > 0) {
        $lm = $db->prepare('SELECT 1 FROM league_members WHERE league_id = ? AND user_id = ? LIMIT 1');
        $lm->execute([$league_id, $uid]);
        if ($lm->fetchColumn()) return; // already a member
        try {
            $db->prepare("INSERT OR IGNORE INTO league_members
                            (league_id, user_id, role, joined_at, invited_by, invited_at)
                          VALUES (?, ?, 'member', CURRENT_TIMESTAMP, ?, CURRENT_TIMESTAMP)")
               ->execute([$league_id, $uid, $invited_by]);
        } catch (Exception $e) { /* dupe — fine */ }
        return;
    }

    // Pending-contact path: dedup by email then phone.
    if ($email !== '') {
        $dup = $db->prepare("SELECT 1 FROM league_members
                              WHERE league_id = ? AND user_id IS NULL AND LOWER(contact_email) = ? LIMIT 1");
        $dup->execute([$league_id, $email]);
        if ($dup->fetchColumn()) return;
    }
    if ($phone !== '') {
        $dup = $db->prepare("SELECT 1 FROM league_members
                              WHERE league_id = ? AND user_id IS NULL AND contact_phone = ? LIMIT 1");
        $dup->execute([$league_id, $phone]);
        if ($dup->fetchColumn()) return;
    }

    $token = bin2hex(random_bytes(16));
    try {
        $db->prepare("INSERT INTO league_members
                        (league_id, user_id, role, contact_name, contact_email, contact_phone,
                         invited_by, invited_at, invite_token)
                      VALUES (?, NULL, 'member', ?, ?, ?, ?, CURRENT_TIMESTAMP, ?)")
           ->execute([$league_id, $name, $email ?: null, $phone ?: null, $invited_by, $token]);
    } catch (Exception $e) { /* unique-index dupe — fine */ }
}

function league_role(int $league_id, int $user_id): ?string {
    $stmt = get_db()->prepare('SELECT role FROM league_members WHERE league_id = ? AND user_id = ?');
    $stmt->execute([$league_id, $user_id]);
    $r = $stmt->fetchColumn();
    return $r !== false ? $r : null;
}

// ── Subscription tier helpers ─────────────────────────────────────
const TIER_RANK = ['Free' => 0, 'Personal' => 1, 'League' => 2, 'OriginalSupporters' => 3];
const TIER_VALID = ['Free', 'Personal', 'League', 'OriginalSupporters'];
const TIER_LABELS = [
    'Free'               => 'Free',
    'Personal'           => 'Personal',
    'League'             => 'League',
    'OriginalSupporters' => 'Original Supporters',
];

function tier_rank(?string $tier): int {
    return TIER_RANK[$tier ?? 'Free'] ?? 0;
}

function tier_at_least($user_or_tier, string $required): bool {
    $tier = is_array($user_or_tier) ? ($user_or_tier['tier'] ?? 'Free') : ($user_or_tier ?? 'Free');
    // OriginalSupporters is honorary — normalized to League for gating, so OS users
    // pass every Personal/League gate. To gate something to OS-ONLY, compare the raw
    // tier directly (e.g. $user['tier'] === 'OriginalSupporters'); tier_at_least with
    // 'OriginalSupporters' as $required will return false even for OS users.
    if ($tier === 'OriginalSupporters') $tier = 'League';
    return tier_rank($tier) >= tier_rank($required);
}

/**
 * Single authoritative check for "can this user manage this event?"
 * Returns true if the user is:
 *   - a site admin,
 *   - the event creator (events.created_by),
 *   - an explicit per-event manager (event_invites.event_role='manager'), or
 *   - an owner or manager of the league that owns the event (leagues.league_id, league_members.role).
 *
 * Replaces the scattered copies of this logic in calendar.php, calendar_dl.php,
 * checkin.php, checkin_dl.php::is_owner_or_manager(), and _poker_helpers.php.
 */
function can_manage_event(PDO $db, int $event_id, int $user_id, bool $is_admin = false): bool {
    if ($is_admin) return true;
    if ($user_id <= 0 || $event_id <= 0) return false;

    $stmt = $db->prepare("
        SELECT e.created_by, e.league_id,
               (SELECT 1 FROM event_invites ei
                 JOIN users u ON LOWER(u.username) = LOWER(ei.username)
                 WHERE ei.event_id = e.id AND u.id = ? AND ei.event_role = 'manager' LIMIT 1) AS is_event_mgr,
               (SELECT lm.role FROM league_members lm
                 WHERE lm.league_id = e.league_id AND lm.user_id = ? LIMIT 1) AS league_role
        FROM events e WHERE e.id = ?
    ");
    $stmt->execute([$user_id, $user_id, $event_id]);
    $row = $stmt->fetch();
    if (!$row) return false;

    if ((int)$row['created_by'] === $user_id) return true;
    if (!empty($row['is_event_mgr'])) return true;
    if (in_array($row['league_role'] ?? '', ['owner', 'manager'], true)) return true;
    return false;
}

// Returns the event id of the user's most recent in-progress (status='active')
// poker game they can manage, or 0. Used to point the nav "Timer" link at the
// live game so its prize pool / players sync, instead of an unlinked timer.
// Live "is the site being used right now" snapshot for the admin Activity tab.
// All cheap indexed COUNTs; each query is guarded so a missing table never 500s.
// Shared by admin_settings.php (initial render) and admin_settings_dl.php (poll).
function admin_activity_snapshot(PDO $db): array {
    $out = [
        'timers_running' => 0, 'timers' => [],
        'active_games' => 0, 'still_playing' => 0,
        'active_users_1h' => 0, 'api_calls_1h' => 0, 'notif_queue' => 0,
        'events_today' => 0, 'rsvps_2h' => 0, 'sms_1h' => 0,
        'recent' => [], 'as_of' => '',
    ];
    $q = function (string $sql) use ($db) {
        try { return (int)$db->query($sql)->fetchColumn(); } catch (Throwable $e) { return 0; }
    };
    $out['timers_running']  = $q("SELECT COUNT(*) FROM timer_state WHERE is_running=1 AND updated_at > datetime('now','-2 minutes')");
    $out['active_games']    = $q("SELECT COUNT(*) FROM poker_sessions WHERE status='active'");
    $out['still_playing']   = $q("SELECT COUNT(*) FROM poker_players WHERE eliminated=0 AND bought_in=1 AND removed=0 AND session_id IN (SELECT id FROM poker_sessions WHERE status='active')");
    $out['active_users_1h'] = $q("SELECT COUNT(DISTINCT user_id) FROM activity_log WHERE user_id>0 AND created_at > datetime('now','-1 hour')");
    $out['api_calls_1h']    = $q("SELECT COUNT(*) FROM api_request_log WHERE created_at > datetime('now','-1 hour')");
    $out['notif_queue']     = $q("SELECT COUNT(*) FROM pending_notifications WHERE attempted_at IS NULL");
    $out['events_today']    = $q("SELECT COUNT(*) FROM events WHERE created_at > datetime('now','-1 day')");
    $out['rsvps_2h']        = $q("SELECT COUNT(*) FROM event_invites WHERE created_at > datetime('now','-2 hours')");
    $out['sms_1h']          = $q("SELECT COUNT(*) FROM sms_log WHERE created_at > datetime('now','-1 hour')");

    try {
        $st = $db->query("SELECT ts.current_level, e.title FROM timer_state ts
                          LEFT JOIN poker_sessions ps ON ps.id = ts.session_id
                          LEFT JOIN events e ON e.id = ps.event_id
                          WHERE ts.is_running=1 AND ts.updated_at > datetime('now','-2 minutes')
                          ORDER BY ts.updated_at DESC LIMIT 10");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out['timers'][] = ['title' => ($r['title'] ?: 'Standalone timer'), 'level' => (int)$r['current_level']];
        }
    } catch (Throwable $e) {}

    try {
        $tz  = new DateTimeZone(display_timezone());
        $utc = new DateTimeZone('UTC');
        $st = $db->query("SELECT al.created_at, al.action, u.username FROM activity_log al
                          LEFT JOIN users u ON u.id = al.user_id AND al.user_id > 0
                          ORDER BY al.id DESC LIMIT 15");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            try { $t = (new DateTime((string)$r['created_at'], $utc))->setTimezone($tz)->format('g:i A'); }
            catch (Throwable $e) { $t = (string)$r['created_at']; }
            $out['recent'][] = ['time' => $t, 'user' => ($r['username'] ?: 'system'), 'action' => (string)$r['action']];
        }
    } catch (Throwable $e) {}

    try { $out['as_of'] = (new DateTime('now', new DateTimeZone(display_timezone())))->format('g:i:s A'); } catch (Throwable $e) {}
    return $out;
}

function user_active_poker_event_id(PDO $db, int $user_id, bool $is_admin = false): int {
    if ($user_id <= 0 && !$is_admin) return 0;
    if ($is_admin) {
        $stmt = $db->prepare("SELECT e.id FROM events e JOIN poker_sessions ps ON ps.event_id = e.id
                              WHERE ps.status = 'active' ORDER BY e.start_date DESC, e.id DESC LIMIT 1");
        $stmt->execute();
    } else {
        $stmt = $db->prepare("SELECT e.id FROM events e JOIN poker_sessions ps ON ps.event_id = e.id
            WHERE ps.status = 'active' AND (
                e.created_by = ?
                OR EXISTS (SELECT 1 FROM event_invites ei JOIN users u ON LOWER(u.username) = LOWER(ei.username)
                           WHERE ei.event_id = e.id AND u.id = ? AND ei.event_role = 'manager')
                OR EXISTS (SELECT 1 FROM league_members lm
                           WHERE lm.league_id = e.league_id AND lm.user_id = ? AND lm.role IN ('owner','manager'))
            ) ORDER BY e.start_date DESC, e.id DESC LIMIT 1");
        $stmt->execute([$user_id, $user_id, $user_id]);
    }
    $r = $stmt->fetchColumn();
    return $r ? (int)$r : 0;
}
