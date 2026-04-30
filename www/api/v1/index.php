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
            'description' => 'Events with RSVP yes/no/maybe counts. Times are returned as ISO-8601 UTC instants (start_at, end_at). All-day events return start_at/end_at as a date-only "YYYY-MM-DD" string. Breaking change in v0.19208: replaces the old start_date/start_time/end_date/end_time fields.',
            'query'       => [
                'from' => 'YYYY-MM-DD (optional, default: today, in the league\'s timezone)',
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
        [
            'method'      => 'POST',
            'path'        => $base . '/events',
            'description' => 'Create an event in the key\'s league. Visibility is forced to "league" and league_id is implicit. Walk-in token is generated eagerly and returned as walkin_url. Side effects mirror the calendar UI: optional poker session, invitees auto-approved, beyond-capacity poker invitees marked waitlisted, reminders queued.',
            'scope'       => 'write',
            'body'        => [
                'title'              => 'string, required (max 200 chars)',
                'start_at'           => 'string, required. ISO-8601 UTC instant ("2026-05-17T20:00:00Z") or date-only ("2026-05-17") for all-day events.',
                'end_at'             => 'string, optional. Same format as start_at.',
                'description'        => 'string, optional',
                'color'              => 'hex string, optional. One of #2563eb, #16a34a, #dc2626, #d97706, #7c3aed, #0891b2, #db2777. Default #2563eb.',
                'is_poker'           => 'boolean, optional (default false). When true, a poker_sessions row is created and waitlist applies.',
                'requires_approval'  => 'boolean, optional (default false). Gates self-signups via walk-in/RSVP.',
                'rsvp_deadline_hours'=> 'integer, optional. Hours before start_at when RSVPs lock.',
                'waitlist_enabled'   => 'boolean, optional (default true). Only meaningful when is_poker=true.',
                'reminders_enabled'  => 'boolean, optional (default true)',
                'reminder_offsets'   => 'array of positive minutes, optional. Defaults to the site default (typically [2880, 720]).',
                'poker_buyin'        => 'number, optional (dollars). Used only when is_poker=true.',
                'poker_tables'       => 'integer, optional, default 1.',
                'poker_seats'        => 'integer, optional, default 8.',
                'poker_game_type'    => "'tournament' | 'cash' (default 'tournament')",
                'invitees'           => 'array of {user_id: int, manager?: bool}. Each user_id must already be a member of the league (call POST /users first if needed). All inserted with approval_status="approved". Capped at 200.',
            ],
            'response'    => '{event_id, title, start_at, end_at, league_id, visibility, is_poker, walkin_url, invitees_added, created_at}',
            'rate_limit'  => '60 successful creations per hour per key (429 when exceeded)',
        ],
        [
            'method'      => 'DELETE',
            'path'        => $base . '/events/{id}',
            'description' => "Hard-delete an event in the key's league. Cascades to event_invites, event_exceptions, pending_notifications (already-sent rows), event_notifications_sent, comments on the event, and poker_sessions (with poker_players / poker_payouts via FK cascade). Future events queue cancel_event notifications to invitees before the row is destroyed; past events delete silently. Wrapped in a transaction — a partial failure rolls back. Returns 404 (event_not_found) for events outside this league so the API doesn't leak existence.",
            'scope'       => 'write',
            'response'    => '{event_id, title, deleted, notifications_queued}',
            'rate_limit'  => '60 successful deletes per hour per key (429 when exceeded)',
        ],
    ],
    'caching'    => 'Successful responses include Cache-Control: public, max-age=60. Cache for at least one minute on the consumer side.',
    'cors'       => 'Access-Control-Allow-Origin: * — browser-side calls from any domain are allowed.',
    'errors' => [
        '400' => 'Bad parameter or invalid request body.',
        '401' => 'Missing, malformed, or revoked API key.',
        '403' => 'API key lacks the required scope for this endpoint.',
        '404' => 'Resource not found (deleted league, or event outside this key\'s league on DELETE /events/{id}).',
        '405' => 'Method not allowed for this endpoint.',
        '409' => 'Conflict (e.g. username_taken, contact_taken on POST /users).',
        '429' => 'Per-key rate limit exceeded (write endpoints).',
    ],
]);
