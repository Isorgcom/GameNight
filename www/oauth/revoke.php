<?php
/**
 * POST /oauth/revoke — an app hands its token back (RFC 7009). Either the
 * access token or the refresh token ends the whole connection. Always 200:
 * the caller learns nothing about whether the token existed.
 */
require_once __DIR__ . '/../_oauth.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { oauth_json(204, []); }
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') oauth_error('invalid_request', 'POST only.', 405);

$t = (string)($_POST['token'] ?? '');
if (preg_match('/^gn[ar]_[a-f0-9]{64}$/', $t)) {
    $h = hash('sha256', $t);
    get_db()->prepare('DELETE FROM api_tokens WHERE client_id IS NOT NULL AND (token_hash = ? OR refresh_hash = ?)')->execute([$h, $h]);
}
oauth_json(200, []);
