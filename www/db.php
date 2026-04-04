<?php
require_once __DIR__ . '/version.php';
// Load credentials from config file stored outside the web root
if (file_exists('/var/config/config.php')) {
    require_once '/var/config/config.php';
}
if (!defined('DB_PATH')) {
    define('DB_PATH', '/var/db/app.db'); // fallback for local dev
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

    // Add preferred_contact column if it doesn't exist yet
    try { $pdo->exec("ALTER TABLE users ADD COLUMN preferred_contact TEXT NOT NULL DEFAULT 'email'"); } catch (Exception $e) {}

    // Severity level for log entries (info, warning, critical)
    try { $pdo->exec("ALTER TABLE activity_log ADD COLUMN severity TEXT NOT NULL DEFAULT 'info'"); } catch (Exception $e) {}

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
}

function get_setting(string $key, string $default = ''): string {
    static $cache = [];
    if (!isset($cache[$key])) {
        $stmt = get_db()->prepare('SELECT value FROM site_settings WHERE key = ?');
        $stmt->execute([$key]);
        $row = $stmt->fetchColumn();
        $cache[$key] = $row !== false ? $row : $default;
    }
    return $cache[$key];
}

function set_setting(string $key, string $value): void {
    get_db()->prepare('INSERT INTO site_settings (key, value) VALUES (?, ?)
        ON CONFLICT(key) DO UPDATE SET value = excluded.value')
        ->execute([$key, $value]);
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
    $stmt = get_db()->prepare(
        'INSERT INTO activity_log (user_id, action, ip, severity) VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([$user_id, $action, get_client_ip(), $severity]);
}

function db_log_anon_activity(string $action, string $severity = 'info'): void {
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
