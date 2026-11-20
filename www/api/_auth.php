<?php
/**
 * API key authentication for the public read-only API at /api/v1/*.
 *
 * Reads the bearer token from the Authorization header (preferred) or the
 * `key` query parameter (fallback for sister-site templates that can't set
 * headers easily). The plaintext key is never compared directly; it's hashed
 * with SHA-256 and matched against api_keys.key_hash. Revoked keys are
 * rejected. Every call (success or failure) is appended to api_request_log
 * so we have an audit trail and the data we'd need to add per-key rate
 * limiting later.
 *
 * On failure this function calls api_fail() and exits — callers do not need
 * to handle a null return value.
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/_response.php';

function api_log_request(?int $key_id, int $status): void {
    try {
        // Store the CANONICAL path, rebuilt from the script that answered and
        // the id / sub / invitee parameters the rewrite rules carry, never the
        // request URI. The per-endpoint rate limits count rows by `path`, and
        // the URI could be spelled around them: /api/v1/events.php?id=5 (the
        // direct file, which .htaccess allows), a trailing slash, or ?key=…
        // padding. Rebuilding also keeps the ?key=<token> fallback out of the
        // audit log (keys are stored as hashes; a logged plaintext key would
        // be replayable). index.php logs as /api/v1.
        $path = api_canonical_path();
        $db = get_db();
        $db->prepare(
            'INSERT INTO api_request_log (key_id, ip, method, path, status) VALUES (?, ?, ?, ?, ?)'
        )->execute([
            $key_id,
            $_SERVER['REMOTE_ADDR']    ?? '',
            $_SERVER['REQUEST_METHOD'] ?? 'GET',
            $path,
            $status,
        ]);
    } catch (Exception $e) { /* logging is best-effort */ }
}

/**
 * /api/v1/<endpoint>[/<id>[/<sub>[/<invitee>]]] for the running script, the
 * shape the rewrite rules in .htaccess map onto it (pending_contacts.php is
 * reached as /pending-contacts). Only numeric ids and a lowercase sub name
 * are carried; anything else is dropped rather than logged.
 */
function api_canonical_path(): string {
    $script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''), '.php');
    if ($script === '' || $script === 'index') return '/api/v1';
    $path = '/api/v1/' . str_replace('_', '-', $script);
    $id = (string)($_GET['id'] ?? '');
    if (preg_match('/^\d{1,12}$/', $id)) {
        $path .= '/' . $id;
        $sub = (string)($_GET['sub'] ?? '');
        if (preg_match('/^[a-z]{1,30}$/', $sub)) {
            $path .= '/' . $sub;
            $inv = (string)($_GET['invitee'] ?? '');
            if (preg_match('/^\d{1,12}$/', $inv)) $path .= '/' . $inv;
        }
    }
    return $path;
}

function api_extract_token(): string {
    // Prefer the Authorization header. getallheaders() may not exist under all SAPIs.
    $hdr = '';
    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            if (strcasecmp($k, 'Authorization') === 0) { $hdr = (string)$v; break; }
        }
    }
    if ($hdr === '' && isset($_SERVER['HTTP_AUTHORIZATION']))         $hdr = (string)$_SERVER['HTTP_AUTHORIZATION'];
    if ($hdr === '' && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) $hdr = (string)$_SERVER['REDIRECT_HTTP_AUTHORIZATION'];

    if ($hdr !== '' && stripos($hdr, 'Bearer ') === 0) {
        return trim(substr($hdr, 7));
    }
    // Fallback: ?key=… (still over HTTPS, just less clean).
    return trim((string)($_GET['key'] ?? ''));
}

/**
 * Validate the request and return the matching api_keys row.
 * Always exits on failure (401 JSON via api_fail()).
 *
 * @return array{id:int,label:string,league_id:int,key_hash:string,...}
 */
function api_authenticate(): array {
    $token = api_extract_token();
    if ($token === '' || !preg_match('/^[a-f0-9]{64}$/i', $token)) {
        api_log_request(null, 401);
        api_fail('Missing or malformed API key', 401);
    }

    $hash = hash('sha256', strtolower($token));
    $db   = get_db();
    $stmt = $db->prepare('SELECT * FROM api_keys WHERE key_hash = ? AND revoked_at IS NULL');
    $stmt->execute([$hash]);
    $row = $stmt->fetch();

    if (!$row) {
        api_log_request(null, 401);
        api_fail('Invalid or revoked API key', 401);
    }

    // Per-key rate limit. A buggy AI bot crawl on a sister site once hammered
    // /api/v1/events at ~3.5 req/s, which starved SQLite write-locks and silently
    // dropped notifications elsewhere on the server. Cap covers read+write paths.
    // Override with API_RATE_LIMIT_PER_MINUTE in config.php if needed.
    $rate_max = defined('API_RATE_LIMIT_PER_MINUTE') ? (int)API_RATE_LIMIT_PER_MINUTE : 120;
    if ($rate_max > 0) {
        try {
            $cutoff = gmdate('Y-m-d H:i:s', time() - 60);
            $cs = $db->prepare('SELECT COUNT(*) FROM api_request_log WHERE key_id = ? AND created_at >= ?');
            $cs->execute([(int)$row['id'], $cutoff]);
            $recent = (int)$cs->fetchColumn();
            if ($recent >= $rate_max) {
                api_log_request((int)$row['id'], 429);
                header('Retry-After: 30');
                api_fail("Rate limit exceeded ({$rate_max}/min). Slow down and try again shortly.", 429);
            }
        } catch (Exception $e) { /* non-fatal: never let a counter glitch lock out a real client */ }
    }

    // Record use. last_used_at also tells admins which keys are dormant.
    try {
        $db->prepare('UPDATE api_keys SET last_used_at = CURRENT_TIMESTAMP WHERE id = ?')
           ->execute([(int)$row['id']]);
    } catch (Exception $e) { /* non-fatal */ }

    return $row;
}

/**
 * Enforce that the API key carries the named scope. Existing keys default to
 * 'read' so write endpoints (e.g. POST /users) only work after a key is minted
 * (or re-minted) with read,write. Always exits on failure (403 JSON).
 */
function api_require_scope(array $key, string $needed): void {
    $raw = (string)($key['scopes'] ?? 'read');
    $scopes = array_filter(array_map('trim', explode(',', $raw)), 'strlen');
    if (!in_array($needed, $scopes, true)) {
        api_log_request((int)$key['id'], 403);
        api_fail('API key lacks required scope: ' . $needed, 403);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Personal tokens (API v2)
//
// A league key above belongs to a league and acts as its owner. A personal
// token belongs to a PERSON: it can do what that person can do on the site,
// narrowed by the token's scopes, and everything it does is recorded under
// their name. Format: "gnp_" + 64 hex. Stored as sha256 of the whole token.
// ─────────────────────────────────────────────────────────────────────────────

/** Every scope a token can carry, with what it allows, in the order Settings lists them. */
const API_TOKEN_SCOPES = [
    'read'     => 'Read your leagues, events, guest lists, contacts, games, results and standings',
    'events'   => 'Create and change events, guest lists, contacts, leagues and posts',
    'game'     => 'Set up and run poker games: buy-ins, rebuys, knockouts, seating, the clock',
    'messages' => 'Send invitations, reminders and messages to guests',
    'money'    => 'Put a jackpot amount on a game (the jackpot is the league\'s fund)',
];

const API_TOKEN_PREFIX = 'gnp_';

/** A new plaintext token and the hash to store for it. */
function api_token_generate(): array {
    $plain = API_TOKEN_PREFIX . bin2hex(random_bytes(32));
    return [$plain, hash('sha256', $plain)];
}

/** Keep only known scopes, in canonical order; 'read' is always present. */
function api_token_clean_scopes($scopes): array {
    if (is_string($scopes)) $scopes = explode(',', $scopes);
    $want = array_map('trim', is_array($scopes) ? $scopes : []);
    $out = [];
    foreach (array_keys(API_TOKEN_SCOPES) as $s) {
        if ($s === 'read' || in_array($s, $want, true)) $out[] = $s;
    }
    return $out;
}

/** Log one v2 request. $path is the ROUTE PATTERN (/api/v2/events/{id}), never the raw URI. */
function api_token_log(?int $token_id, int $status, string $path): void {
    try {
        // A request with no usable token costs anyone nothing to send, and each
        // one was a row (v1.38.0): keep at most one a minute per address and
        // route, which still shows that someone is knocking.
        if ($token_id === null && $status === 401) {
            $seen = get_db()->prepare("SELECT 1 FROM api_request_log WHERE token_id IS NULL AND status = 401 AND ip = ? AND path = ? AND created_at > datetime('now', '-1 minute') LIMIT 1");
            $seen->execute([$_SERVER['REMOTE_ADDR'] ?? '', $path]);
            if ($seen->fetchColumn()) return;
        }
        get_db()->prepare(
            'INSERT INTO api_request_log (key_id, token_id, ip, method, path, status) VALUES (NULL, ?, ?, ?, ?, ?)'
        )->execute([$token_id, $_SERVER['REMOTE_ADDR'] ?? '', $_SERVER['GN_ORIGINAL_METHOD'] ?? ($_SERVER['REQUEST_METHOD'] ?? 'GET'), $path, $status]);
    } catch (Exception $e) { /* logging is best-effort */ }
}

/**
 * Resolve the bearer token to an actor, or return a failure array
 * ['ok' => false, 'code', 'message', 'http']. Never exits: the v2 front
 * controller and the MCP endpoint each answer in their own format.
 *
 * Only the Authorization header is read. There is no ?key= fallback: a token
 * that can run a game does not belong in a URL, where it lands in logs and
 * browser history.
 *
 * $audience is who is asking: 'api' for /api/v2, 'mcp' for the MCP endpoint.
 * A personal token ("gnp_", minted by hand in Settings) is good for both. A
 * token issued through the OAuth sign-in ("gna_") was granted for the MCP
 * endpoint and is accepted there only: a token is good for the thing it was
 * asked for, never for its neighbours.
 */
function api_token_actor(string $audience = 'api'): array {
    $hdr = '';
    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            if (strcasecmp($k, 'Authorization') === 0) { $hdr = (string)$v; break; }
        }
    }
    if ($hdr === '' && isset($_SERVER['HTTP_AUTHORIZATION']))          $hdr = (string)$_SERVER['HTTP_AUTHORIZATION'];
    if ($hdr === '' && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) $hdr = (string)$_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    $token = stripos($hdr, 'Bearer ') === 0 ? trim(substr($hdr, 7)) : '';

    $fail = fn(string $code, string $msg, int $http) => ['ok' => false, 'code' => $code, 'message' => $msg, 'http' => $http];

    if ($token === '') {
        return $fail('missing_token', 'Send your personal token in the Authorization header: "Authorization: Bearer gnp_...".', 401);
    }
    if (preg_match('/^[a-f0-9]{64}$/i', $token)) {
        return $fail('league_key_not_accepted', 'That is a league API key. League keys work on /api/v1; /api/v2 takes a personal token, minted under Settings.', 401);
    }
    if (!preg_match('/^gn[pa]_[a-f0-9]{64}$/', $token)) {
        return $fail('invalid_token', 'That token is not valid.', 401);
    }

    $db = get_db();
    $st = $db->prepare(
        "SELECT t.id AS token_id, t.scopes, t.expires_at, t.client_id, u.id, u.username, u.role, u.email_verified
           FROM api_tokens t JOIN users u ON u.id = t.user_id
          WHERE t.token_hash = ?");
    $st->execute([hash('sha256', $token)]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return $fail('invalid_token', 'That token is not valid. It may have been revoked.', 401);
    if ($row['client_id'] !== null && $audience !== 'mcp') {
        return $fail('invalid_token', 'That token was issued for the MCP endpoint and is not accepted here. Mint a personal token under Settings for the API.', 401);
    }
    if (!empty($row['expires_at']) && strtotime($row['expires_at'] . ' UTC') < time()) {
        return $fail('token_expired', 'That token has expired.', 401);
    }

    $tid = (int)$row['token_id'];
    // The same ceiling as a league key, counted per token.
    $rate_max = defined('API_RATE_LIMIT_PER_MINUTE') ? (int)API_RATE_LIMIT_PER_MINUTE : 120;
    if ($rate_max > 0) {
        try {
            $cs = $db->prepare('SELECT COUNT(*) FROM api_request_log WHERE token_id = ? AND created_at >= ?');
            $cs->execute([$tid, gmdate('Y-m-d H:i:s', time() - 60)]);
            if ((int)$cs->fetchColumn() >= $rate_max) {
                header('Retry-After: 30');
                $f = $fail('rate_limited', "Rate limit exceeded ({$rate_max} requests a minute). Try again shortly.", 429);
                $f['token_id'] = $tid;
                return $f;
            }
        } catch (Exception $e) { /* a counter glitch never locks out a real client */ }
    }
    try { $db->prepare('UPDATE api_tokens SET last_used_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$tid]); } catch (Exception $e) {}

    return [
        'id'       => (int)$row['id'],
        'username' => (string)$row['username'],
        'is_admin' => $row['role'] === 'admin',
        'via'      => 'api',
        'token_id' => $tid,
        'scopes'   => api_token_clean_scopes((string)$row['scopes']),
    ];
}
