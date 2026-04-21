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
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id)
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

    // Add preferred_contact column if it doesn't exist yet
    try { $pdo->exec("ALTER TABLE users ADD COLUMN preferred_contact TEXT NOT NULL DEFAULT 'email'"); } catch (Exception $e) {}

    // Severity level for log entries (info, warning, critical)
    try { $pdo->exec("ALTER TABLE activity_log ADD COLUMN severity TEXT NOT NULL DEFAULT 'info'"); } catch (Exception $e) {}

    // Admin notes field for users
    try { $pdo->exec("ALTER TABLE users ADD COLUMN notes TEXT"); } catch (Exception $e) {}

    // Per-user My Events time range preferences
    try { $pdo->exec("ALTER TABLE users ADD COLUMN my_events_past_days INTEGER NOT NULL DEFAULT 30"); } catch (Exception $e) {}
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
    }
    $db->prepare('UPDATE poker_players SET removed = 1 WHERE user_id = ?')->execute([$user_id]);
    $db->prepare('DELETE FROM comments WHERE user_id = ?')->execute([$user_id]);
    $db->prepare('DELETE FROM password_resets WHERE user_id = ?')->execute([$user_id]);
    $db->prepare('DELETE FROM remember_tokens WHERE user_id = ?')->execute([$user_id]);
    $db->prepare('DELETE FROM email_verifications WHERE user_id = ?')->execute([$user_id]);
    $db->prepare('DELETE FROM phone_verifications WHERE user_id = ?')->execute([$user_id]);
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

function normalize_phone(string $phone): string {
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
    ];

    // Per-tag allowed attributes (in addition to global ones)
    $tag_attrs = [
        'a'   => ['href', 'title', 'target', 'rel'],
        'img' => ['src', 'alt', 'width', 'height', 'title'],
        'td'  => ['colspan', 'rowspan'],
        'th'  => ['colspan', 'rowspan', 'scope'],
    ];
    $global_attrs = ['class', 'style', 'id'];
    $safe_schemes = ['http', 'https', 'mailto'];

    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<html><head><meta charset="UTF-8"></head><body>' . $html . '</body></html>');
    libxml_clear_errors();

    $walk = function (DOMNode $node) use (
        &$walk, $allowed_tags, $tag_attrs, $global_attrs, $safe_schemes
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
    $phone = trim($phone);
    if ($name === '') return;
    if ($email === '' && $phone === '') return;
    // Skip if a contact already exists by email
    if ($email !== '') {
        $chk = $db->prepare('SELECT 1 FROM user_contacts WHERE owner_user_id = ? AND LOWER(contact_email) = ? LIMIT 1');
        $chk->execute([$owner_user_id, $email]);
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

function league_role(int $league_id, int $user_id): ?string {
    $stmt = get_db()->prepare('SELECT role FROM league_members WHERE league_id = ? AND user_id = ?');
    $stmt->execute([$league_id, $user_id]);
    $r = $stmt->fetchColumn();
    return $r !== false ? $r : null;
}
