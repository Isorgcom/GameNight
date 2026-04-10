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
    try { $pdo->exec("ALTER TABLE poker_sessions ADD COLUMN seats_per_table INTEGER NOT NULL DEFAULT 9"); } catch (Exception $e) {}

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
    try { $pdo->exec("ALTER TABLE timer_state ADD COLUMN warning_sound TEXT"); } catch (Exception $e) {}

    // Walk-up QR registration token per event
    try { $pdo->exec("ALTER TABLE events ADD COLUMN walkin_token TEXT"); } catch (Exception $e) {}

    // Rate-limit table for walk-up registration submissions
    try { $pdo->exec("CREATE TABLE IF NOT EXISTS walkin_attempts (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        ip         TEXT    NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )"); } catch (Exception $e) {}

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

    // Seed a welcome post if no posts exist
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

// Settings that contain secrets — automatically encrypted at rest
define('ENCRYPTED_SETTINGS', [
    'smtp_pass', 'smtp_password',
    'sms_token', 'sms_webhook_secret',
    'wa_token',
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
