<?php
/**
 * The two discovery documents an app reads before it can sign someone in.
 *   ?doc=resource  /.well-known/oauth-protected-resource[/mcp]   (RFC 9728)
 *       "the thing at /mcp is protected, and this is who issues its tokens"
 *   ?doc=server    /.well-known/oauth-authorization-server       (RFC 8414)
 *       "this is where to send the person, and where to swap the code"
 * Public, and the same for everyone.
 */
require_once __DIR__ . '/../_oauth.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { oauth_json(204, []); }
if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) oauth_error('invalid_request', 'GET only.', 405);

$iss = oauth_issuer();
if (($_GET['doc'] ?? '') === 'resource') {
    oauth_json(200, [
        'resource'                 => oauth_resource(),
        'authorization_servers'    => [$iss],
        'scopes_supported'         => OAUTH_SCOPES,
        'bearer_methods_supported' => ['header'],
        'resource_name'            => (string)get_setting('site_name', 'Game Night'),
        'resource_documentation'   => 'https://github.com/Isorgcom/GameNight/blob/main/DOCS.md#connecting-claude-mcp',
    ]);
}
oauth_json(200, [
    'issuer'                                         => $iss,
    'authorization_endpoint'                         => $iss . '/oauth/authorize',
    'token_endpoint'                                 => $iss . '/oauth/token',
    'registration_endpoint'                          => $iss . '/oauth/register',
    'revocation_endpoint'                            => $iss . '/oauth/revoke',
    'response_types_supported'                       => ['code'],
    'response_modes_supported'                       => ['query'],
    'grant_types_supported'                          => ['authorization_code', 'refresh_token'],
    'code_challenge_methods_supported'               => ['S256'],
    'token_endpoint_auth_methods_supported'          => ['none'],
    'revocation_endpoint_auth_methods_supported'     => ['none'],
    'scopes_supported'                               => OAUTH_SCOPES,
    'client_id_metadata_document_supported'          => true,
    'authorization_response_iss_parameter_supported' => true,
    'service_documentation'                          => 'https://github.com/Isorgcom/GameNight/blob/main/DOCS.md#connecting-claude-mcp',
]);
