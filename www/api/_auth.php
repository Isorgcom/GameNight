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
        $db = get_db();
        $db->prepare(
            'INSERT INTO api_request_log (key_id, ip, method, path, status) VALUES (?, ?, ?, ?, ?)'
        )->execute([
            $key_id,
            $_SERVER['REMOTE_ADDR']    ?? '',
            $_SERVER['REQUEST_METHOD'] ?? 'GET',
            $_SERVER['REQUEST_URI']    ?? '',
            $status,
        ]);
    } catch (Exception $e) { /* logging is best-effort */ }
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
