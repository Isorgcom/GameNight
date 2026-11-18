<?php
/**
 * POST /oauth/register — an app introduces itself (RFC 7591), for apps that
 * cannot use a Client ID Metadata Document. It is given a random client id.
 * Nobody vouches for what it says about itself, so registering grants
 * nothing: the person still has to sign in and agree on the consent page,
 * which shows where their browser would be sent.
 */
require_once __DIR__ . '/../_oauth.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { oauth_json(204, []); }
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') oauth_error('invalid_request', 'POST only.', 405);

$db = get_db();
$ip = get_client_ip();
// A handful an hour from one address is plenty for a person adding a connector.
$n = $db->prepare("SELECT COUNT(*) FROM oauth_clients WHERE kind = 'dcr' AND created_ip = ? AND created_at > datetime('now', '-1 hour')");
$n->execute([$ip]);
if ((int)$n->fetchColumn() >= 20) { header('Retry-After: 600'); oauth_error('temporarily_unavailable', 'Too many registrations from this address. Try again later.', 429); }
// Registrations that were never used to sign anyone in do not pile up.
try {
    $db->exec("DELETE FROM oauth_clients WHERE kind = 'dcr' AND created_at < datetime('now', '-30 day')
               AND id NOT IN (SELECT client_id FROM api_tokens WHERE client_id IS NOT NULL)
               AND id NOT IN (SELECT client_pk FROM oauth_codes)");
} catch (Exception $e) {}

$raw = (string)file_get_contents('php://input', false, null, 0, 20000);
$doc = json_decode($raw, true);
$ok  = oauth_client_document($doc);
if (!is_array($ok)) oauth_error(str_contains((string)$ok, 'redirect') ? 'invalid_redirect_uri' : 'invalid_client_metadata', (string)$ok);
[$name, $uris] = $ok;

$client_id = 'gnc_' . bin2hex(random_bytes(16));
$db->prepare("INSERT INTO oauth_clients (client_id, kind, client_name, redirect_uris, created_ip) VALUES (?, 'dcr', ?, ?, ?)")
   ->execute([$client_id, $name, json_encode($uris, JSON_UNESCAPED_SLASHES), $ip]);
db_log_anon_activity("oauth client registered: '" . ($name !== '' ? $name : 'unnamed') . "' redirect=" . implode(' ', array_map(fn($u) => (string)parse_url($u, PHP_URL_HOST), $uris)));

oauth_json(201, [
    'client_id'                  => $client_id,
    'client_id_issued_at'        => time(),
    'client_name'                => $name,
    'redirect_uris'              => $uris,
    'token_endpoint_auth_method' => 'none',
    'grant_types'                => ['authorization_code', 'refresh_token'],
    'response_types'             => ['code'],
]);
