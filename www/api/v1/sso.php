<?php
/**
 * GET /api/v1/sso
 *
 * Public signing key for the sign-in bridge (connect.php), so an operator
 * pairing a connected app can fetch the key and the issuer from one place.
 * No API key: the public key is public, and the issuer is the site URL.
 * See _sso.php for the token format and admin_sso_apps.php for pairing.
 */

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../_sso.php';
require_once __DIR__ . '/../_response.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    api_fail('Method not allowed', 405);
}

$keys = sso_keys();
$site = rtrim(get_site_url(), '/');

api_ok([
    'issuer'      => $site,
    'connect_url' => $site . '/connect.php',
    'token'       => [
        'format'  => 'JWT, ES256 (P-256, raw R||S signature), 120 second lifetime, single use (jti)',
        'claims'  => ['iss', 'aud', 'sub', 'iat', 'exp', 'jti', 'name', 'tier', 'avatar_path'],
        'carried' => 'in the URL fragment of the return redirect: <return>#gn_token=<jwt>&state=<state>',
    ],
    'keys' => [[
        'kid' => $keys['kid'],
        'kty' => 'EC',
        'crv' => 'P-256',
        'alg' => 'ES256',
        'use' => 'sig',
        'pem' => $keys['public_pem'],
    ]],
], 300);
