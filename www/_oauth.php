<?php
/**
 * OAuth 2.1 authorization server, for assistants that connect to /mcp.
 *
 * The flow: an app (Claude) finds /mcp, is told by its 401 where the sign-in
 * lives, sends the person's browser to /oauth/authorize, the person signs in
 * and says what the app may do, the app is handed a one-time code, swaps it at
 * /oauth/token for an access token (an hour) and a refresh token, and uses the
 * access token at /mcp. Authorization code + PKCE (S256) only; every client is
 * public (there are no client secrets); refresh tokens rotate on every use.
 *
 * An app is known in one of two ways:
 *   - Its client_id is an https URL of a small JSON document describing it
 *     (a Client ID Metadata Document). We fetch that document. Preferred.
 *   - It registered itself at /oauth/register and was given a random id
 *     (Dynamic Client Registration), for apps that cannot do the first.
 * Neither is vetted by anyone, so the consent page names the app as it names
 * itself AND shows where the browser will be sent, which is the part that
 * cannot be faked.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/api/_auth.php';   // API_TOKEN_SCOPES, api_token_clean_scopes()

const OAUTH_CODE_TTL          = 300;        // 5 minutes to swap a code
const OAUTH_ACCESS_TTL        = 3600;       // 1 hour
const OAUTH_REFRESH_TTL       = 5184000;    // 60 days without use
const OAUTH_ACCESS_PREFIX     = 'gna_';
const OAUTH_REFRESH_PREFIX    = 'gnr_';
/** What an app may be allowed through the sign-in. The jackpot permission is not offered this way. */
const OAUTH_SCOPES            = ['read', 'events', 'game', 'messages'];

function oauth_issuer(): string   { return rtrim(get_site_url(), '/'); }
function oauth_resource(): string { return oauth_issuer() . '/mcp'; }

/** Is this exactly the resource tokens are issued for? A trailing slash is the same resource. */
function oauth_resource_ok(string $r): bool {
    return $r === '' || rtrim($r, '/') === oauth_resource();
}

function oauth_json(int $http, array $body): void {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('Pragma: no-cache');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, MCP-Protocol-Version');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    http_response_code($http);
    if ($http !== 204) echo $body === [] ? '{}' : json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/** An RFC 6749 error from the token or registration endpoint. */
function oauth_error(string $error, string $description, int $http = 400): void {
    oauth_json($http, ['error' => $error, 'error_description' => $description]);
}

/** http://localhost or http://127.0.0.1 (any port): a program on the person's own machine. */
function oauth_is_loopback(string $uri): bool {
    $p = parse_url($uri);
    return $p && ($p['scheme'] ?? '') === 'http' && in_array(strtolower((string)($p['host'] ?? '')), ['localhost', '127.0.0.1', '[::1]'], true);
}

/** A redirect URI an app may register: https anywhere, or http on the loopback. No fragment, no credentials. */
function oauth_redirect_uri_valid(string $uri): bool {
    if ($uri === '' || strlen($uri) > 512 || preg_match('/[\x00-\x20\\\\]/', $uri)) return false;
    $p = parse_url($uri);
    if (!$p || empty($p['host']) || isset($p['fragment']) || isset($p['user']) || isset($p['pass'])) return false;
    if (!oauth_host_plain((string)$p['host'])) return false;
    if (($p['scheme'] ?? '') === 'https') return true;
    return oauth_is_loopback($uri);
}

/**
 * Is $uri one of the client's registered redirect URIs? An exact match,
 * except that a loopback address matches on any port: a desktop program
 * listens on whichever port is free when it starts.
 */
function oauth_redirect_uri_allowed(array $client, string $uri): bool {
    if (!oauth_redirect_uri_valid($uri)) return false;
    $registered = json_decode((string)$client['redirect_uris'], true) ?: [];
    if (in_array($uri, $registered, true)) return true;
    if (!oauth_is_loopback($uri)) return false;
    $u = parse_url($uri);
    foreach ($registered as $r) {
        if (!is_string($r) || !oauth_is_loopback($r)) continue;
        $p = parse_url($r);
        $same_host = in_array(strtolower((string)$p['host']), ['localhost', '127.0.0.1', '[::1]'], true);
        if ($same_host && ($p['path'] ?? '/') === ($u['path'] ?? '/') && ($p['query'] ?? '') === ($u['query'] ?? '')) return true;
    }
    return false;
}

/**
 * A host written in plain ASCII letters, digits, dots and hyphens (punycode
 * "xn--" names included), or a bracketed IPv6 loopback (v1.38.0). A name in
 * other scripts can look like a familiar site on the consent page ("gаmenight"
 * with a Cyrillic a), so the address an app returns to, and the address of its
 * client document, must be spelled the way the browser will really go.
 */
function oauth_host_plain(string $host): bool {
    return $host === '[::1]' || (bool)preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/i', $host);
}

/** May a server-side fetch go to this host? Only public addresses: never this network, this machine, or a metadata service. */
function oauth_public_ip(string $host): ?string {
    if (filter_var($host, FILTER_VALIDATE_IP)) return null;                    // a name, not an address literal
    $ips = @gethostbynamel($host);
    if (!$ips) return null;
    foreach ($ips as $ip) {
        // FILTER_FLAG_GLOBAL_RANGE (v1.38.0) also refuses shared address space
        // (100.64/10, which Tailscale uses), benchmarking (198.18/15), multicast
        // and the other non-global blocks the private/reserved flags let through.
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE | FILTER_FLAG_GLOBAL_RANGE)) return null;
    }
    return $ips[0];
}

/**
 * Fetch a Client ID Metadata Document. https only, to a public address that
 * is resolved once and pinned (so the name cannot answer differently for the
 * connection), no redirects, five seconds, 64 KB. Returns the decoded document
 * or null.
 */
function oauth_cimd_fetch(string $url): ?array {
    $p = parse_url($url);
    if (!$p || ($p['scheme'] ?? '') !== 'https' || empty($p['host']) || empty($p['path']) || $p['path'] === '/'
        || isset($p['fragment']) || isset($p['user']) || isset($p['pass']) || strlen($url) > 512
        || !oauth_host_plain((string)$p['host'])) return null;
    $port = (int)($p['port'] ?? 443);
    $ip = oauth_public_ip((string)$p['host']);
    if ($ip === null || !function_exists('curl_init')) return null;
    $body = '';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RESOLVE        => [$p['host'] . ':' . $port . ':' . $ip],
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        CURLOPT_USERAGENT      => 'GameNight-OAuth/1',
        CURLOPT_WRITEFUNCTION  => function ($ch, $chunk) use (&$body) {
            $body .= $chunk;
            return strlen($body) > 65536 ? 0 : strlen($chunk);      // 0 aborts the transfer
        },
    ]);
    $ok = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    // (No curl_close(): it has done nothing since PHP 8.0 and is deprecated in 8.5, which live runs.)
    if ($ok === false || $code !== 200) return null;
    $doc = json_decode($body, true);
    return is_array($doc) ? $doc : null;
}

/** Check a client document (fetched, or posted to /register) and return [name, redirect_uris] or an error string. */
function oauth_client_document($doc) {
    if (!is_array($doc)) return 'The client document is not a JSON object.';
    $uris = $doc['redirect_uris'] ?? null;
    if (!is_array($uris) || !$uris || count($uris) > 10) return 'redirect_uris must list 1 to 10 addresses.';
    foreach ($uris as $u) {
        if (!is_string($u) || !oauth_redirect_uri_valid($u)) return 'Each redirect URI must be https, or http on localhost.';
    }
    $method = $doc['token_endpoint_auth_method'] ?? 'none';
    if ($method !== 'none') return 'Only public clients are supported (token_endpoint_auth_method "none").';
    $name = trim(preg_replace('/[\x00-\x1F\x7F]/', '', (string)($doc['client_name'] ?? '')));
    return [mb_substr($name, 0, 80), array_values(array_unique($uris))];
}

/**
 * The client a client_id names, or null. A URL is a Client ID Metadata
 * Document: fetched, checked (its own client_id must be that exact URL) and
 * kept for a day. Anything else must be an id /oauth/register issued.
 */
function oauth_client(PDO $db, string $client_id): ?array {
    if ($client_id === '' || strlen($client_id) > 512) return null;
    $st = $db->prepare('SELECT * FROM oauth_clients WHERE client_id = ?');
    $st->execute([$client_id]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!str_starts_with($client_id, 'https://')) return ($row && $row['kind'] === 'dcr') ? $row : null;

    $fresh = $row && $row['fetched_at'] && strtotime($row['fetched_at'] . ' UTC') > time() - 86400;
    if ($fresh) return $row;
    // This runs before anyone has signed in, and each fetch can hold a web
    // worker for five seconds (v1.38.0): an address that failed in the last
    // 10 minutes is not fetched again, and one address may make 10 fetches
    // in 10 minutes.
    $key = 'oauth_cimd_fail: ' . hash('sha256', $client_id);
    $nf = $db->prepare("SELECT COUNT(*) FROM activity_log WHERE action = ? AND created_at > datetime('now', '-10 minutes')");
    $nf->execute([$key]);
    if ((int)$nf->fetchColumn() > 0) return $row;
    $rl = $db->prepare("SELECT COUNT(*) FROM activity_log WHERE action = 'oauth_cimd_fetch' AND ip = ? AND created_at > datetime('now', '-10 minutes')");
    $rl->execute([get_client_ip()]);
    if ((int)$rl->fetchColumn() >= 10) return $row;
    db_log_anon_activity('oauth_cimd_fetch');
    $doc = oauth_cimd_fetch($client_id);
    $ok  = $doc && ($doc['client_id'] ?? null) === $client_id ? oauth_client_document($doc) : null;
    if (!is_array($ok)) { db_log_anon_activity($key); return $row; }   // could not refresh: a copy we already trusted stays good, a new one is refused
    [$name, $uris] = $ok;
    if ($row) {
        $db->prepare('UPDATE oauth_clients SET client_name = ?, redirect_uris = ?, fetched_at = CURRENT_TIMESTAMP WHERE id = ?')
           ->execute([$name, json_encode($uris, JSON_UNESCAPED_SLASHES), (int)$row['id']]);
    } else {
        $db->prepare("INSERT INTO oauth_clients (client_id, kind, client_name, redirect_uris, created_ip, fetched_at) VALUES (?, 'cimd', ?, ?, ?, CURRENT_TIMESTAMP)")
           ->execute([$client_id, $name, json_encode($uris, JSON_UNESCAPED_SLASHES), get_client_ip()]);
    }
    $st->execute([$client_id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** What to call an app on the consent page and in Settings: its own name, else where it lives. */
function oauth_client_label(array $client): string {
    $name = trim((string)$client['client_name']);
    if ($name !== '') return $name;
    if ($client['kind'] === 'cimd') return (string)parse_url((string)$client['client_id'], PHP_URL_HOST);
    return 'An app';
}

/** Requested scopes, cut down to what the sign-in can grant, with "read" always in. Empty request means everything grantable. */
function oauth_scopes_requested(string $scope): array {
    $want = array_filter(preg_split('/\s+/', trim($scope)), 'strlen');
    $out  = ['read'];
    foreach (OAUTH_SCOPES as $s) {
        if ($s !== 'read' && (!$want || in_array($s, $want, true))) $out[] = $s;
    }
    return $out;
}

function oauth_b64url(string $bin): string { return rtrim(strtr(base64_encode($bin), '+/', '-_'), '='); }

/**
 * Give $token_row_id (or a new row) a fresh access token and refresh token.
 * Refreshing rewrites the SAME row, so a connection keeps one id: its activity
 * stays in one list and revoking it is one row.
 */
function oauth_issue_tokens(PDO $db, int $user_id, int $client_pk, array $scopes, string $label, ?int $token_row_id = null): array {
    $access  = OAUTH_ACCESS_PREFIX  . bin2hex(random_bytes(32));
    $refresh = OAUTH_REFRESH_PREFIX . bin2hex(random_bytes(32));
    $exp  = gmdate('Y-m-d H:i:s', time() + OAUTH_ACCESS_TTL);
    $rexp = gmdate('Y-m-d H:i:s', time() + OAUTH_REFRESH_TTL);
    if ($token_row_id) {
        // The refresh token being replaced is kept as prev_refresh_hash: if it is
        // ever presented again, someone else has a copy (v1.38.0, oauth/token.php).
        $db->prepare('UPDATE api_tokens SET prev_refresh_hash = refresh_hash, token_hash = ?, refresh_hash = ?, expires_at = ?, refresh_expires_at = ? WHERE id = ?')
           ->execute([hash('sha256', $access), hash('sha256', $refresh), $exp, $rexp, $token_row_id]);
    } else {
        $db->prepare('INSERT INTO api_tokens (user_id, token_hash, label, scopes, client_id, expires_at, refresh_hash, refresh_expires_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
           ->execute([$user_id, hash('sha256', $access), $label, implode(',', $scopes), $client_pk, $exp, hash('sha256', $refresh), $rexp]);
    }
    return ['access_token' => $access, 'token_type' => 'Bearer', 'expires_in' => OAUTH_ACCESS_TTL,
            'refresh_token' => $refresh, 'scope' => implode(' ', $scopes)];
}
