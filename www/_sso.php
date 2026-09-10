<?php
/**
 * Sign-in bridge for connected apps (FinalTable is the first).
 *
 * A connected app never sees a password. It sends the browser to connect.php,
 * this site's own login (password, verification gate, MFA, lockouts) runs
 * unchanged, the user confirms once, and the browser is sent back carrying a
 * short-lived identity token signed here. The app verifies the signature with
 * the public key and nothing else crosses the boundary.
 *
 * The token is a compact JWT, ES256 over a P-256 keypair generated on first use
 * and kept in site_settings (sso_private_pem is in ENCRYPTED_SETTINGS, so it is
 * encrypted at rest under APP_SECRET). It is a separate key from the VAPID pair
 * in webpush.php on purpose: one key, one job. The signing primitives
 * (wp_b64url_encode, wp_der_sig_to_raw) are reused from there.
 *
 * Every relying party is a row in sso_apps. Its slug is the token audience and
 * its base_url is the only origin a token may be redirected to; both are
 * managed by a site admin on admin_sso_apps.php.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/webpush.php';

/** Seconds a token stays valid. One redirect hop, then it is spent. */
const SSO_TOKEN_TTL = 120;

/** Longest return URL accepted from a connected app. */
const SSO_MAX_RETURN_LEN = 512;

// ── Keys ────────────────────────────────────────────────────────────────────

/**
 * First 16 hex of the public PEM's SHA-256: the token header's `kid`, and the
 * id an operator compares against on the app's side. Hashed over the trimmed
 * PEM so a copy that lost or gained a trailing newline gets the same id.
 */
function sso_kid(string $public_pem): string {
    return substr(hash('sha256', trim($public_pem)), 0, 16);
}

/**
 * Returns ['private_pem', 'public_pem', 'kid']. Generates and persists the
 * pair on first call. The public side is the SPKI PEM straight from OpenSSL,
 * which is the form Node's crypto.createPublicKey() reads without ceremony.
 */
function sso_keys(): array {
    $pub  = get_setting('sso_public_pem', '');
    $priv = get_setting('sso_private_pem', '');
    if ($pub === '' || $priv === '') {
        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name'       => 'prime256v1',
        ]);
        if ($key === false) throw new RuntimeException('SSO keygen failed: ' . openssl_error_string());
        if (!openssl_pkey_export($key, $privPem)) {
            throw new RuntimeException('SSO key export failed: ' . openssl_error_string());
        }
        $det  = openssl_pkey_get_details($key);
        $pub  = (string)$det['key'];
        $priv = $privPem;
        set_setting('sso_public_pem', $pub);
        set_setting('sso_private_pem', $priv);
    }
    return ['private_pem' => $priv, 'public_pem' => $pub, 'kid' => sso_kid($pub)];
}

/** Discard the current pair and mint a new one. Every connected app must be re-paired. */
function sso_rotate_keys(): array {
    set_setting('sso_public_pem', '');
    set_setting('sso_private_pem', '');
    return sso_keys();
}

/** Compact ES256 JWT over $claims (caller supplies iss/aud/sub/iat/exp/jti). */
function sso_sign_token(array $claims): string {
    $keys = sso_keys();
    $head = wp_b64url_encode(json_encode(['typ' => 'JWT', 'alg' => 'ES256', 'kid' => $keys['kid']], JSON_UNESCAPED_SLASHES));
    $body = wp_b64url_encode(json_encode($claims, JSON_UNESCAPED_SLASHES));
    $signingInput = $head . '.' . $body;
    if (!openssl_sign($signingInput, $der, $keys['private_pem'], OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('SSO token sign failed: ' . openssl_error_string());
    }
    return $signingInput . '.' . wp_b64url_encode(wp_der_sig_to_raw($der));
}

// ── Connected apps ──────────────────────────────────────────────────────────

/** Any row for the slug, enabled or not; the caller decides what a disabled app means. */
function sso_app_by_slug(string $slug): ?array {
    if (!preg_match('/^[a-z0-9-]{2,32}$/', $slug)) return null;
    $st = get_db()->prepare('SELECT * FROM sso_apps WHERE slug = ?');
    $st->execute([$slug]);
    $row = $st->fetch();
    return $row ?: null;
}

/** scheme://host[:port] of the app's registered base_url. */
function sso_app_origin(array $app): string {
    $p = parse_url((string)$app['base_url']);
    if (!$p || empty($p['scheme']) || empty($p['host'])) return '';
    $o = strtolower($p['scheme']) . '://' . strtolower($p['host']);
    if (!empty($p['port'])) $o .= ':' . (int)$p['port'];
    return $o;
}

/**
 * Is $url a place this app may be sent back to? Origin-exact: scheme, host and
 * port must all equal the registered base_url (a prefix compare on the string
 * would let http://a.com match http://a.com.evil.com/), the path must sit
 * under the registered path, and there is no fragment or userinfo. This is
 * the one check standing between a forged link and a token landing on a
 * stranger's server, so it fails closed on anything it does not understand.
 */
function sso_validate_return(array $app, string $url): bool {
    if ($url === '' || strlen($url) > SSO_MAX_RETURN_LEN) return false;
    if (str_contains($url, '#') || str_contains($url, '@') || str_contains($url, '\\')) return false;
    $r = parse_url($url);
    $b = parse_url((string)$app['base_url']);
    if (!$r || !$b || empty($r['scheme']) || empty($r['host']) || empty($b['scheme']) || empty($b['host'])) return false;
    if (!in_array(strtolower($r['scheme']), ['http', 'https'], true)) return false;
    if (strtolower($r['scheme']) !== strtolower($b['scheme'])) return false;
    if (strtolower($r['host']) !== strtolower($b['host'])) return false;
    if (($r['port'] ?? null) !== ($b['port'] ?? null)) return false;
    if (isset($r['user']) || isset($r['pass'])) return false;
    $bp = rtrim((string)($b['path'] ?? ''), '/') . '/';
    $rp = (string)($r['path'] ?? '/');
    if ($rp === '') $rp = '/';
    if (!str_starts_with($rp, '/')) return false;
    if ($bp === '/') return true;
    return $rp === rtrim($bp, '/') || str_starts_with($rp, $bp);
}

/** The app's anti-CSRF nonce, echoed back untouched. */
function sso_validate_state(string $state): bool {
    return (bool)preg_match('/^[A-Za-z0-9_-]{16,128}$/', $state);
}

function sso_touch_app(int $id): void {
    get_db()->prepare('UPDATE sso_apps SET last_used_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$id]);
}

/**
 * $return plus a URL fragment. The token travels in the fragment so it never
 * reaches the app's access log or a Referer header; the app's page script
 * reads it and scrubs it. RFC 3986 encoding: a JWT is all unreserved
 * characters, so it passes through untouched either way.
 */
function sso_build_redirect(string $return, array $fragment): string {
    return $return . '#' . http_build_query($fragment, '', '&', PHP_QUERY_RFC3986);
}
