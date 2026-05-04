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
            'description' => 'Roster: user_id, display_name, role, pending, joined_at. user_id is null for pending contacts (people invited but who haven\'t created accounts yet). Personal contact info (emails, phones) is never returned.',
        ],
        [
            'method'      => 'GET',
            'path'        => $base . '/members/{user_id}',
            'description' => 'Single league-member by user_id. Same shape as a list-item; pending: always false (pending contacts have no user_id and aren\'t addressable here). 404 member_not_found if the user_id isn\'t a member of this league.',
            'response'    => '{user_id, display_name, role, pending, joined_at}',
        ],
        [
            'method'      => 'PATCH',
            'path'        => $base . '/members/{user_id}',
            'description' => "Promote or demote a registered league member's role. Body: {league_role: 'member' | 'manager'}. Idempotent (no-op + role_changed:false if the role already matches). 'owner' cannot be set or demoted via the API — privilege transfer is UI-only. Pending contacts (member rows without an account) are not addressable; use POST /users to create the account first.",
            'scope'       => 'write',
            'body'        => [
                'league_role' => "'member' | 'manager' (required). 'owner' is rejected.",
            ],
            'response'    => '{league_id, user_id, league_role, role_changed}',
            'rate_limit'  => '60 successful updates per hour per key',
        ],
        [
            'method'      => 'DELETE',
            'path'        => $base . '/members/{user_id}',
            'description' => "Remove a user from the bound league. The user account stays intact across the rest of the system — their RSVPs, event-manager roles, authored posts, and other-league memberships are not touched. Owner cannot be removed via the API (use the in-app transfer ownership flow first). Notifies the removed user via their preferred channel; failure to notify does not roll back the removal.",
            'scope'       => 'write',
            'response'    => '{league_id, user_id, removed, notification_sent}',
            'rate_limit'  => '60 successful removals per hour per key',
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
            'path'        => $base . '/events/{id}',
            'description' => "Single event by id. Same shape as a GET /events list-item plus league_id and visibility. 404 event_not_found if the id doesn't exist or belongs to a different league.",
            'response'    => '{id, title, description, start_at, end_at, color, is_poker, league_id, visibility, rsvp_yes_count, rsvp_no_count, rsvp_maybe_count, created_at}',
        ],
        [
            'method'      => 'GET',
            'path'        => $base . '/events/{id}/invites',
            'description' => 'Invitee list for an event. Returns user_id (null for custom invitees added by email/phone without an account), display_name, rsvp (yes/no/maybe/null), approval_status (approved/pending/waitlisted/denied), event_role (invitee/manager). Sort matches the calendar UI. PII (email/phone) is never returned.',
            'response'    => '{event_id, count, invitees: [{user_id, display_name, rsvp, approval_status, event_role}, ...]}',
        ],
        [
            'method'      => 'DELETE',
            'path'        => $base . '/events/{id}/invites/{user_id}',
            'description' => "Remove a single invitee from an event. For future events, queues a cancel_event notification to the removed user (mirrors the calendar UI's remove_invitee behavior). Past events: silent. Returns 404 invitee_not_found if the user_id isn't currently invited.",
            'scope'       => 'write',
            'response'    => '{event_id, user_id, removed, notifications_queued}',
            'rate_limit'  => '60 successful removals per hour per key',
        ],
        [
            'method'      => 'PATCH',
            'path'        => $base . '/events/{id}/invites/{user_id}',
            'description' => "Update an invitee's rsvp ('yes'|'no'|'maybe'|null) or event_role ('invitee'|'manager'). At least one of the two is required. The 1-hour-before-start cutoff that applies to non-admin RSVPs in the UI does NOT apply via the API (the key acts as the league owner). When rsvp changes to 'no', the waitlist is recomputed and any promotions are reported in promoted_from_waitlist. No notifications are sent.",
            'scope'       => 'write',
            'body'        => [
                'rsvp'       => "'yes' | 'no' | 'maybe' | null (optional)",
                'event_role' => "'invitee' | 'manager' (optional)",
            ],
            'response'    => '{event_id, user_id, fields_changed, promoted_from_waitlist}',
            'rate_limit'  => '60 successful updates per hour per key',
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
            'path'        => $base . '/posts/{id}',
            'description' => "Single post by id. Same shape as a list-item. Same visibility filters as the list — hidden, future-scheduled, and the rules post all return 404 post_not_found. Use /rules for the rules post.",
            'response'    => '{id, title, content_html, author_display_name, created_at, share_url?}',
        ],
        [
            'method'      => 'POST',
            'path'        => $base . '/posts',
            'description' => "Create a post in the bound league. Author is set to the league owner. Content is sanitized via the same pipeline the in-app form uses (script tags, event handlers, untrusted iframes, etc. stripped). is_rules_post and share_token cannot be set via the API — those lifecycles stay UI-only.",
            'scope'       => 'write',
            'body'        => [
                'title'        => 'string, required (max 200 chars)',
                'content'      => 'string, required. Sanitized HTML; non-empty after sanitization.',
                'pinned'       => 'boolean, optional (default false)',
                'hidden'       => 'boolean, optional (default false). Hidden posts are not returned by GET /posts or GET /posts/{id}.',
                'published_at' => 'string, optional. ISO-8601 UTC instant. Defaults to current time. Future values create a scheduled post (invisible until publish time).',
            ],
            'response'    => '{id, title, content_html, author_display_name, created_at, pinned, hidden, share_url?}',
            'rate_limit'  => '60 successful creations per hour per key',
        ],
        [
            'method'      => 'PATCH',
            'path'        => $base . '/posts/{id}',
            'description' => "Partial update of an existing post. Only fields present in the body are touched. is_rules_post, share_token, and published_at cannot be edited via the API.",
            'scope'       => 'write',
            'body'        => [
                'title'   => 'string, optional',
                'content' => 'string, optional. Sanitized on write.',
                'pinned'  => 'boolean, optional',
                'hidden'  => 'boolean, optional',
            ],
            'response'    => '{id, title, content_html, author_display_name, created_at, pinned, hidden, share_url?, fields_changed}',
            'rate_limit'  => '60 successful updates per hour per key',
        ],
        [
            'method'      => 'DELETE',
            'path'        => $base . '/posts/{id}',
            'description' => "Hard-delete a post. Cascades to comments where type='post' AND content_id=post_id. Wrapped in a transaction. Returns 404 post_not_found for posts in other leagues.",
            'scope'       => 'write',
            'response'    => '{post_id, deleted, comments_deleted}',
            'rate_limit'  => '60 successful deletions per hour per key',
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
            'method'      => 'PATCH',
            'path'        => $base . '/events/{id}',
            'description' => "Partial update of an existing event. Only fields present in the body are touched. Same field shape and validation as POST /events, except invitees (use POST /events/{id}/invites) and league/visibility (immutable). When start_at moves and the event is in the future, queues an `event_updated` notification to all approved base invitees. Reminder queue is rebuilt automatically when timing or reminder fields change. Wrapped in a transaction.",
            'scope'       => 'write',
            'response'    => '{event_id, title, start_at, end_at, is_poker, fields_changed, notifications_queued}',
            'rate_limit'  => '60 successful updates per hour per key',
        ],
        [
            'method'      => 'POST',
            'path'        => $base . '/events/{id}/invites',
            'description' => "Add invitees to an existing event. Body: {invitees: [{user_id: int, manager?: bool}, ...]}. Each user_id must be a member of the bound league. Idempotent on duplicates (skip-if-exists; existing rows' manager flag is NOT changed). Honors the poker waitlist when capacity is exceeded. Newly-added approved invitees get an invite notification.",
            'scope'       => 'write',
            'response'    => '{event_id, added, skipped, waitlisted, notifications_queued}',
            'rate_limit'  => '60 successful calls per hour per key',
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
        '404' => 'Resource not found. event_not_found for events that don\'t exist or belong to a different league; invitee_not_found for users not currently invited; member_not_found for league_members not addressable by user_id; post_not_found for posts hidden, scheduled, in another league, or simply missing.',
        '405' => 'Method not allowed for this endpoint.',
        '409' => 'Conflict (e.g. username_taken, contact_taken on POST /users).',
        '429' => 'Per-key rate limit exceeded (write endpoints).',
    ],
]);
