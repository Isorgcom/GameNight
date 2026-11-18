<?php
/**
 * POST /oauth/token — swap a one-time code for tokens, or a refresh token for
 * new ones. Form-encoded, as OAuth has it. Public clients only: what proves
 * the caller is the app the person agreed to is the PKCE verifier (for a
 * code) or possession of the current refresh token.
 */
require_once __DIR__ . '/../_oauth.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { oauth_json(204, []); }
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') oauth_error('invalid_request', 'POST only.', 405);

$db    = get_db();
$grant = (string)($_POST['grant_type'] ?? '');
$cid   = (string)($_POST['client_id'] ?? '');
$res   = (string)($_POST['resource'] ?? '');
if (!oauth_resource_ok($res)) oauth_error('invalid_target', 'Tokens are issued only for ' . oauth_resource() . '.');

if ($grant === 'authorization_code') {
    $code     = (string)($_POST['code'] ?? '');
    $verifier = (string)($_POST['code_verifier'] ?? '');
    $redirect = (string)($_POST['redirect_uri'] ?? '');
    if ($code === '' || $cid === '') oauth_error('invalid_request', 'code and client_id are required.');
    if (!preg_match('/^[A-Za-z0-9._~-]{43,128}$/', $verifier)) oauth_error('invalid_request', 'code_verifier is missing or malformed.');

    $db->beginTransaction();
    $st = $db->prepare('SELECT c.*, cl.client_id AS cid, cl.client_name, cl.kind FROM oauth_codes c JOIN oauth_clients cl ON cl.id = c.client_pk WHERE c.code_hash = ?');
    $st->execute([hash('sha256', $code)]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) { $db->rollBack(); oauth_error('invalid_grant', 'That code is not valid.'); }
    if ($row['used_at'] !== null) {
        // A code presented twice was copied by someone. Whatever it bought is withdrawn.
        $db->prepare('DELETE FROM api_tokens WHERE user_id = ? AND client_id = ? AND created_at >= ?')->execute([(int)$row['user_id'], (int)$row['client_pk'], $row['used_at']]);
        $db->commit();
        oauth_error('invalid_grant', 'That code has already been used.');
    }
    $db->prepare('UPDATE oauth_codes SET used_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([(int)$row['id']]);
    $bad = strtotime($row['expires_at'] . ' UTC') < time() ? 'That code has expired.'
         : ($row['cid'] !== $cid ? 'That code was issued to a different client.'
         : ($row['redirect_uri'] !== $redirect ? 'redirect_uri does not match the one the code was issued for.'
         : (!hash_equals($row['code_challenge'], oauth_b64url(hash('sha256', $verifier, true))) ? 'code_verifier does not match.' : '')));
    if ($bad !== '') { $db->commit(); oauth_error('invalid_grant', $bad); }

    $client = ['client_name' => $row['client_name'], 'kind' => $row['kind'], 'client_id' => $row['cid']];
    $scopes = api_token_clean_scopes((string)$row['scopes']);
    // One connection per app per person: signing in again replaces the old one.
    $db->prepare('DELETE FROM api_tokens WHERE user_id = ? AND client_id = ?')->execute([(int)$row['user_id'], (int)$row['client_pk']]);
    $tokens = oauth_issue_tokens($db, (int)$row['user_id'], (int)$row['client_pk'], $scopes, oauth_client_label($client));
    $db->commit();
    oauth_json(200, $tokens);
}

if ($grant === 'refresh_token') {
    $rt = (string)($_POST['refresh_token'] ?? '');
    if (!preg_match('/^gnr_[a-f0-9]{64}$/', $rt)) oauth_error('invalid_grant', 'That refresh token is not valid.');
    $db->beginTransaction();
    $st = $db->prepare('SELECT t.*, cl.client_id AS cid FROM api_tokens t JOIN oauth_clients cl ON cl.id = t.client_id WHERE t.refresh_hash = ?');
    $st->execute([hash('sha256', $rt)]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    // An old refresh token (already rotated), a revoked connection and a made-up one all look the same.
    if (!$row || ($cid !== '' && $row['cid'] !== $cid)
        || ($row['refresh_expires_at'] && strtotime($row['refresh_expires_at'] . ' UTC') < time())) {
        $db->rollBack();
        oauth_error('invalid_grant', 'That refresh token is not valid. Sign in again.');
    }
    $tokens = oauth_issue_tokens($db, (int)$row['user_id'], (int)$row['client_id'], api_token_clean_scopes((string)$row['scopes']), (string)$row['label'], (int)$row['id']);
    $db->commit();
    oauth_json(200, $tokens);
}

oauth_error('unsupported_grant_type', 'grant_type must be authorization_code or refresh_token.');
