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
    'description'    => 'Single-league API: read-only access to events, posts, members, and rules; write endpoints (e.g. user creation) require a key minted with the write scope. Each API key is bound to one league.',
    'documentation'  => 'https://github.com/Isorgcom/GameNight/blob/main/DOCS.md',
    'authentication' => [
        'type'     => 'bearer',
        'header'   => 'Authorization: Bearer <key>',
        'fallback' => '?key=<key> query parameter (use only when headers are not available; HTTPS required either way)',
        'how_to_get_a_key' => 'League owners can mint keys from their league\'s API tab in the GameNight UI.',
        'scopes'   => [
            'read'  => 'Default. GET endpoints only.',
            'write' => 'In addition to read, allows write endpoints such as POST /users.',
        ],
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
            'description' => 'League posts (sanitized HTML body). Excludes hidden, draft, and the league rules post. See /rules for the rules post.',
            'query'       => [
                'limit'  => 'integer 1-50 (optional, default 20)',
                'offset' => 'integer >= 0 (optional, default 0)',
            ],
            'notes' => 'Posts that have a public share link include a share_url field pointing to /post_public.php.',
        ],
        [
            'method'      => 'GET',
            'path'        => $base . '/rules',
            'description' => "The league's rules post (sanitized HTML body). Returns rules: null when the league has not configured a rules post.",
        ],
        [
            'method'      => 'POST',
            'path'        => $base . '/users',
            'description' => 'Create a user and add them to the key\'s league. Idempotent on email/phone — replaying with the same contact returns the existing user_id and ensures league membership without duplicating the account or resending verification. Requires the write scope.',
            'scope'       => 'write',
            'body'        => [
                'display_name'        => 'string, required',
                'email'               => 'string, optional (one of email/phone is required)',
                'phone'               => 'string, optional',
                'username'            => 'string, optional (3-30 chars, letters/numbers/underscores). Auto-derived from display_name when omitted.',
                'verification_method' => "'email' | 'sms' | 'whatsapp' | 'none' — one-shot at signup. Defaults to email if email provided, else sms.",
                'preferred_contact'   => "'email' | 'sms' | 'whatsapp' | 'both' | 'none' — ongoing notification channel. Defaults to verification_method. Ignored on existing-user replays so a write key cannot mute or re-route real accounts.",
            ],
            'response'    => '{user_id, username, created, league_member_added, verification_sent, preferred_contact, preferred_contact_updated}',
            'rate_limit'  => '60 successful creations per hour per key (429 when exceeded)',
        ],
    ],
    'caching'    => 'Successful responses include Cache-Control: public, max-age=60. Cache for at least one minute on the consumer side.',
    'cors'       => 'Access-Control-Allow-Origin: * — browser-side calls from any domain are allowed.',
    'errors' => [
        '400' => 'Bad parameter or invalid request body.',
        '401' => 'Missing, malformed, or revoked API key.',
        '403' => 'API key lacks the required scope for this endpoint.',
        '404' => 'The league bound to the key was deleted.',
        '405' => 'Method not allowed for this endpoint.',
        '409' => 'Conflict (e.g. username_taken, contact_taken on POST /users).',
        '429' => 'Per-key rate limit exceeded (write endpoints).',
    ],
]);
