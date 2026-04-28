<?php
/**
 * GET /api/v1/  — discovery endpoint.
 *
 * No authentication required. Returns a JSON document describing the
 * available endpoints, expected auth, and a link to full docs. Designed
 * to be the first thing a developer reads when exploring the API.
 */

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../_response.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    api_fail('Method not allowed', 405);
}

$base = rtrim(get_site_url(), '/') . '/api/v1';

api_ok([
    'name'           => 'GameNight Public API',
    'version'        => 'v1',
    'description'    => 'Read-only access to a single league\'s data: events, posts, members. Each API key is bound to one league.',
    'documentation'  => rtrim(get_site_url(), '/') . '/DOCS.md',
    'authentication' => [
        'type'     => 'bearer',
        'header'   => 'Authorization: Bearer <key>',
        'fallback' => '?key=<key> query parameter (use only when headers are not available; HTTPS required either way)',
        'how_to_get_a_key' => 'League owners can mint keys from their league\'s API tab in the GameNight UI.',
    ],
    'response_shape' => [
        'success' => '{"ok": true, "data": ...}',
        'error'   => '{"ok": false, "error": "human-readable message"}',
    ],
    'endpoints' => [
        [
            'method'      => 'GET',
            'path'        => $base . '/league',
            'description' => 'League summary: id, name, description, member_count, created_at.',
        ],
        [
            'method'      => 'GET',
            'path'        => $base . '/members',
            'description' => 'Roster: display_name, role, pending, joined_at. Personal contact info (emails, phones) is never returned.',
        ],
        [
            'method'      => 'GET',
            'path'        => $base . '/events',
            'description' => 'Events with RSVP yes/no/maybe counts.',
            'query'       => [
                'from' => 'YYYY-MM-DD (optional, default: today)',
                'to'   => 'YYYY-MM-DD (optional, default: from + 90 days, max span 366 days)',
            ],
        ],
        [
            'method'      => 'GET',
            'path'        => $base . '/posts',
            'description' => 'League posts (sanitized HTML body). Excludes hidden, draft, and the league rules post.',
            'query'       => [
                'limit'  => 'integer 1-50 (optional, default 20)',
                'offset' => 'integer >= 0 (optional, default 0)',
            ],
            'notes' => 'Posts that have a public share link include a share_url field pointing to /post_public.php.',
        ],
    ],
    'caching'    => 'Successful responses include Cache-Control: public, max-age=60. Cache for at least one minute on the consumer side.',
    'cors'       => 'Access-Control-Allow-Origin: * — browser-side calls from any domain are allowed.',
    'errors' => [
        '400' => 'Bad parameter (e.g. from > to, window > 366 days, malformed key format).',
        '401' => 'Missing, malformed, or revoked API key.',
        '404' => 'The league bound to the key was deleted.',
        '405' => 'Non-GET method.',
    ],
]);
