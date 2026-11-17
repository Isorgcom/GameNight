<?php
/**
 * /api/v2 — the personal API. One front controller and a route table.
 *
 * Where /api/v1 serves one league to one sister site with a league key, v2
 * serves a PERSON: the caller holds a personal token (Settings → API tokens),
 * and every answer is what that person could see or do on the site. No logic
 * lives here. A route checks the token's scope and calls a service function
 * in www/svc/, the same function the web pages and the MCP endpoint call.
 *
 * Envelope:  {"ok": true,  "data": {...}}
 *            {"ok": false, "error": "snake_case_code", "message": "A sentence."}
 * Money is integer cents in fields ending _cents. Timestamps are ISO 8601 UTC.
 * A date with no time of day is "YYYY-MM-DD".
 */

require_once __DIR__ . '/../_auth.php';
require_once __DIR__ . '/../../svc/common.php';
require_once __DIR__ . '/../../svc/events.php';
require_once __DIR__ . '/../../svc/leagues.php';
require_once __DIR__ . '/../../svc/stats.php';
require_once __DIR__ . '/../../svc/game.php';
require_once __DIR__ . '/../../svc/messages.php';

const API_V2_BASE = '/api/v2';

function v2_send(int $http, array $body): void {
    api_send_headers(0);          // personal, live data: never stored
    http_response_code($http);
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/** Answer with a service result, logging the request against the token. $idem, when set, is where to keep the answer for a replay. */
function v2_finish(array $actor, string $pattern, array $res, ?array $idem = null): void {
    if (!empty($res['ok'])) {
        unset($res['ok']);
        api_token_log($actor['token_id'], 200, API_V2_BASE . $pattern);
        $body = ['ok' => true, 'data' => $res];
        if ($idem) {
            try {
                get_db()->prepare('INSERT OR IGNORE INTO api_idempotency (token_id, idem_key, path, status, body) VALUES (?, ?, ?, 200, ?)')
                        ->execute([$idem['token_id'], $idem['key'], $idem['path'], json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
            } catch (Exception $e) { /* a replay then simply runs again */ }
        }
        v2_send(200, $body);
    }
    $http = (int)($res['http'] ?? 400);
    api_token_log($actor['token_id'], $http, API_V2_BASE . $pattern);
    v2_send($http, ['ok' => false, 'error' => (string)($res['code'] ?? 'error'), 'message' => (string)($res['message'] ?? '')]);
}

/**
 * The routes: [method, pattern, scope, summary, handler(actor, path params, query, body)].
 * {id}-style segments are digits; {key} is any single segment. The summary is
 * what the discovery document prints. $b is the decoded JSON body of a write.
 */
function v2_routes(): array {
    $db = get_db();
    return [
        ['GET', '/me', 'read', 'Who the token belongs to, what it may do, and your leagues.',
            fn($a, $p, $q) => v2_me($db, $a)],
        ['GET', '/me/events', 'read', 'Events you can see. Query: from, to (YYYY-MM-DD), role=any|host|guest, league_id, include_cancelled=1.',
            fn($a, $p, $q) => gn_events_list($db, $a, $q)],

        ['GET', '/leagues', 'read', 'The leagues you belong to, with your role in each.',
            fn($a, $p, $q) => gn_leagues_list($db, $a)],
        ['GET', '/leagues/{id}', 'read', 'One league, with its seasons.',
            fn($a, $p, $q) => gn_league_get($db, $a, $p['id'])],
        ['GET', '/leagues/{id}/members', 'read', 'The roster. Owners and managers also see people added by contact who have not joined.',
            fn($a, $p, $q) => gn_league_members($db, $a, $p['id'])],
        ['GET', '/leagues/{id}/standings', 'read', 'The leaderboard. Query: range=7|30|90|365|ytd|all|custom (with from, to), or season_id; rank=score|net|wins|points.',
            fn($a, $p, $q) => v2_standings($db, $a, $p['id'], $q)],
        ['GET', '/leagues/{id}/players/{key}', 'read', 'One player\'s finished games in the league. {key} is a user id, or g_<name> for a guest without an account. Same range query as standings.',
            fn($a, $p, $q) => gn_player_history($db, $a, $p['id'], $p['key'], $q)],

        ['POST', '/leagues/{id}/members', 'messages', 'Owner or manager. Add someone by name and email or phone. They are sent an invitation to join (or to accept, if they have an account). Body: name, email, phone.',
            fn($a, $p, $q, $b) => gn_league_member_add($db, $a, $p['id'], $b)],
        ['GET', '/leagues/{id}/requests', 'read', 'Owner or manager. People waiting for an answer on a request to join.',
            fn($a, $p, $q, $b) => gn_join_requests_list($db, $a, $p['id'])],
        ['POST', '/leagues/{id}/requests/{rid}/approve', 'events', 'Owner or manager. Let them in. They are told.',
            fn($a, $p, $q, $b) => gn_join_request_decide($db, $a, $p['rid'], true, $p['id'])],
        ['POST', '/leagues/{id}/requests/{rid}/deny', 'events', 'Owner or manager. Decline the request. They are told.',
            fn($a, $p, $q, $b) => gn_join_request_decide($db, $a, $p['rid'], false, $p['id'])],
        ['POST', '/leagues/{id}/seasons', 'events', 'Owner or manager. Add a season: a named date window for standings. Body: name, start_date, end_date (YYYY-MM-DD).',
            fn($a, $p, $q, $b) => gn_season_add($db, $a, $p['id'], $b)],
        ['DELETE', '/leagues/{id}/seasons/{sid}', 'events', 'Owner or manager. Remove a season. Games and results are untouched.',
            fn($a, $p, $q, $b) => gn_season_delete($db, $a, $p['id'], $p['sid'])],
        ['GET', '/leagues/{id}/posts', 'read', 'The league\'s posts, pinned first then newest. Query: limit (default 20, at most 50), offset.',
            fn($a, $p, $q, $b) => gn_league_posts($db, $a, $p['id'], $q)],
        ['POST', '/leagues/{id}/posts', 'events', 'Owner or manager. Post to the league. Body: title, body (text or simple HTML). Nobody is notified.',
            fn($a, $p, $q, $b) => v2_league_post($db, $a, $p['id'], $b)],

        ['GET', '/events', 'read', 'Same as /me/events.',
            fn($a, $p, $q) => gn_events_list($db, $a, $q)],
        ['GET', '/events/{id}', 'read', 'One event, with RSVP totals, capacity and a summary of its game.',
            fn($a, $p, $q) => gn_event_get($db, $a, $p['id'])],
        ['GET', '/events/{id}/invites', 'read', 'The guest list. The host sees invite state and hand-entered contact details.',
            fn($a, $p, $q) => gn_event_invites($db, $a, $p['id'])],

        ['POST', '/events', 'events', 'Create an event. Body: title, start_at (required); description, location, venue_name, color, end_at, league_id, visibility, is_poker, poker{game_type,buyin_cents,tables,seats_per_table}, requires_approval, hide_guest_list, waitlist_enabled, max_guests, rsvp_deadline_hours, reminders_enabled, reminder_minutes[], guests[]. Sends nothing to the guests.',
            fn($a, $p, $q, $b) => gn_event_create($db, $a, $b)],
        ['PATCH', '/events/{id}', 'events', 'Host. Change an event: any of the create fields except guests. Only what you send changes.',
            fn($a, $p, $q, $b) => gn_event_update($db, $a, $p['id'], $b)],
        ['POST', '/events/{id}/cancel', 'events', 'Host. Call the event off and keep it: every guest is told, and it can still be duplicated.',
            fn($a, $p, $q, $b) => gn_event_cancel($db, $a, $p['id'])],
        ['DELETE', '/events/{id}', 'events', 'Host. Delete the event for good, with its guest list, messages and game. Body: {"confirm": true}; without it the answer says what would be lost.',
            fn($a, $p, $q, $b) => gn_event_delete($db, $a, $p['id'], $b)],
        ['POST', '/events/{id}/duplicate', 'events', 'Host. A new event copied from this one, guest list included, nobody\'s answer carried over. Body: start_at (required), title, end_at.',
            fn($a, $p, $q, $b) => gn_event_duplicate($db, $a, $p['id'], $b)],

        ['POST', '/events/{id}/invites', 'events', 'Host. Add guests. Body: {"guests": [{"user_id": 12} or {"name": "Dave", "email": "...", "phone": "..."}, each optionally "role": "manager"]}. Someone already on the list is skipped. Sends nothing.',
            fn($a, $p, $q, $b) => gn_invites_add($db, $a, $p['id'], $b)],
        ['PATCH', '/events/{id}/invites/{iid}', 'events', 'Host. Change one guest. Body: rsvp (yes, no, maybe or null), role (invitee or manager).',
            fn($a, $p, $q, $b) => gn_invite_update($db, $a, $p['id'], $p['iid'], $b)],
        ['DELETE', '/events/{id}/invites/{iid}', 'events', 'Host. Take a guest off the list. On an upcoming event they are told.',
            fn($a, $p, $q, $b) => gn_invite_remove($db, $a, $p['id'], $p['iid'])],
        ['POST', '/events/{id}/invites/{iid}/approve', 'events', 'Host. Approve a guest who asked to come or is on the waitlist. They are told, and seated if it is a poker game.',
            fn($a, $p, $q, $b) => gn_invite_decide($db, $a, $p['id'], $p['iid'], true)],
        ['POST', '/events/{id}/invites/{iid}/deny', 'events', 'Host. Turn down a guest who asked to come.',
            fn($a, $p, $q, $b) => gn_invite_decide($db, $a, $p['id'], $p['iid'], false)],

        ['POST', '/events/{id}/invitations/send', 'messages', 'Host. Send invitations to every approved guest who has not had one. Safe to repeat.',
            fn($a, $p, $q, $b) => gn_invitations_send($db, $a, $p['id'])],
        ['POST', '/events/{id}/invites/{iid}/resend', 'messages', 'Host. Send one guest their invitation again.',
            fn($a, $p, $q, $b) => v2_invite_resend($db, $a, $p['id'], $p['iid'])],
        ['POST', '/events/{id}/nudge', 'messages', 'Host. Remind invited guests who have not answered. At most once a day per guest.',
            fn($a, $p, $q, $b) => gn_guests_nudge($db, $a, $p['id'])],
        ['GET', '/events/{id}/messages', 'read', 'Host. The messages sent to this event\'s guests, newest first, and the daily limit.',
            fn($a, $p, $q, $b) => gn_host_messages_list($db, $a, $p['id'])],
        ['POST', '/events/{id}/messages', 'messages', 'Host. Send a message to the guests. Body: subject, body (text or simple HTML), audience (yes, yes_maybe or all; default yes). Limited per event per day.',
            fn($a, $p, $q, $b) => v2_host_message($db, $a, $p['id'], $b)],

        ['GET', '/events/{id}/game', 'read', 'Host only. The whole game in one read: setup, players, prize pool, payouts with amounts, blinds and the clock.',
            fn($a, $p, $q) => gn_game_state($db, $a, $p['id'])],
        ['GET', '/events/{id}/game/results', 'read', 'How the game came out: finishing order, payouts, points. Open to the host and to members of the event\'s league.',
            fn($a, $p, $q) => gn_game_results($db, $a, $p['id'])],
        ['GET', '/events/{id}/game/log', 'read', 'Host only. The game\'s activity log, newest first. Query: limit (default 200, at most 500).',
            fn($a, $p, $q) => gn_game_log($db, $a, $p['id'], (int)($q['limit'] ?? 200))],
        ['GET', '/events/{id}/game/clock', 'read', 'Host only. The tournament clock and the blind schedule.',
            fn($a, $p, $q) => gn_game_clock($db, $a, $p['id'])],
        ['GET', '/events/{id}/game/players/{pid}/ledger', 'read', 'Host only. One player\'s buy-in, rebuy, add-on and cash entries.',
            fn($a, $p, $q) => gn_player_ledger($db, $a, $p['id'], $p['pid'])],
    ];
}

function v2_me(PDO $db, array $actor): array {
    $u = $db->prepare('SELECT id, username, role, timezone FROM users WHERE id = ?');
    $u->execute([$actor['id']]);
    $user = $u->fetch(PDO::FETCH_ASSOC);
    $leagues = gn_leagues_list($db, $actor);
    return gn_ok([
        'user' => [
            'id' => (int)$user['id'], 'name' => (string)$user['username'],
            'site_admin' => $user['role'] === 'admin',
            'timezone' => (string)($user['timezone'] ?: get_setting('timezone', 'UTC')),
        ],
        'token' => ['scopes' => $actor['scopes']],
        'site'  => [
            'name' => (string)get_setting('site_name', 'GameNight'),
            'url'  => rtrim(get_site_url(), '/'),
            'timezone' => (string)get_setting('timezone', 'UTC'),
            'version'  => defined('APP_VERSION') ? APP_VERSION : null,
        ],
        'leagues' => $leagues['leagues'],
    ]);
}

function v2_invite_resend(PDO $db, array $actor, int $event_id, int $invite_id): array {
    // The guard runs first so a guest id on someone else's event says nothing.
    $ev = gn_event_guard($db, $actor, $event_id);
    if (isset($ev['ok'])) return $ev;
    $inv = gn_invite_row($db, $event_id, $invite_id);
    if (!$inv) return gn_err('invite_not_found', 'No such guest on this event.', 404);
    return gn_invite_resend($db, $actor, $event_id, (string)$inv['username']);
}

function v2_host_message(PDO $db, array $actor, int $event_id, array $b): array {
    $bad = array_diff(array_keys($b), ['subject', 'body', 'audience']);
    if ($bad) return gn_err('unknown_field', 'Unknown field: ' . implode(', ', $bad) . '.', 400);
    foreach (['subject', 'body'] as $k) if (!is_string($b[$k] ?? null)) return gn_err('bad_field', "\"$k\" is required and must be text.", 400);
    $body = v2_text_or_html($b['body']);
    return gn_host_message_send($db, $actor, $event_id, ['subject' => $b['subject'], 'body' => $body, 'audience' => $b['audience'] ?? 'yes']);
}

/** A body that uses formatting tags is HTML; anything else is plain text that keeps its line breaks. */
function v2_text_or_html(string $body): string {
    $is_html = (bool)preg_match('#<(p|br|div|ul|ol|li|strong|b|em|i|u|a|h[1-6]|blockquote)(\\s[^>]*)?/?>#i', $body);
    return $is_html ? $body : nl2br(htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE));
}

function v2_league_post(PDO $db, array $actor, int $league_id, array $b): array {
    if (isset($b['body']) && is_string($b['body'])) $b['body'] = v2_text_or_html($b['body']);
    return gn_league_post_create($db, $actor, $league_id, $b);
}

function v2_standings(PDO $db, array $actor, int $league_id, array $q): array {
    $res = gn_league_standings($db, $actor, $league_id, $q);
    if (empty($res['ok'])) return $res;
    $pos = 0;
    $res['standings'] = array_map(function ($r) use (&$pos) { return gn_standings_shape($r, ++$pos); }, $res['rows']);
    unset($res['rows']);
    return $res;
}

// ─────────────────────────────────────────────────────────────────────────────

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'OPTIONS') {
    api_send_headers(0);
    http_response_code(204);
    exit;
}

$path = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
$path = '/' . trim((string)preg_replace('#^' . preg_quote(API_V2_BASE, '#') . '(/index\.php)?#', '', $path), '/');
if ($path === '/') $path = '';

$routes = v2_routes();

// Discovery: what exists, no token needed.
if ($path === '') {
    if ($method !== 'GET') v2_send(405, ['ok' => false, 'error' => 'method_not_allowed', 'message' => 'The discovery document is read with GET.']);
    require_once __DIR__ . '/../../version.php';
    $site = rtrim(get_site_url(), '/');
    v2_send(200, ['ok' => true, 'data' => [
        'name'    => 'GameNight personal API',
        'version' => 'v2',
        'app_version' => defined('APP_VERSION') ? APP_VERSION : null,
        'base'    => $site . API_V2_BASE,
        'auth'    => [
            'type'   => 'Personal token in the Authorization header: "Authorization: Bearer gnp_..."',
            'mint'   => $site . '/settings.php#api-tokens',
            'scopes' => API_TOKEN_SCOPES,
            'note'   => 'A token acts as the person who minted it and can do only what they can do on the site. League keys belong to /api/v1.',
        ],
        'conventions' => [
            'envelope' => '{"ok":true,"data":{...}} or {"ok":false,"error":"code","message":"sentence"}',
            'money'    => 'Integer cents, in fields ending _cents.',
            'time'     => 'ISO 8601 UTC ("2026-12-12T01:00:00Z"); a date with no time of day is "YYYY-MM-DD".',
            'rate_limit' => '120 requests a minute per token; 60 successful changes an hour per token on any one route.',
            'writes'   => 'Send a JSON object. An unknown field is refused by name. A POST may carry an Idempotency-Key header (8 to 128 characters): asked again within a day, it returns the first answer instead of doing the thing twice. Something that cannot be undone needs "confirm": true.',
        ],
        'endpoints' => array_map(fn($r) => ['method' => $r[0], 'path' => API_V2_BASE . $r[1], 'scope' => $r[2], 'summary' => $r[3]], $routes),
        'docs' => 'https://github.com/Isorgcom/GameNight/blob/main/DOCS.md#personal-api-v2',
    ]]);
}

// Match the path first, so a wrong method on a real path is 405 and not 404.
$match = null; $path_known = false; $known_pattern = ''; $params = [];
foreach ($routes as $r) {
    $re = '#^' . preg_replace(['#\{(id|pid|sid|iid|rid)\}#', '#\{(key)\}#'], ['(?P<$1>\d{1,12})', '(?P<$1>[^/]{1,200})'], $r[1]) . '$#';
    if (!preg_match($re, $path, $m)) continue;
    $path_known = true; $known_pattern = $r[1];
    if ($r[0] !== $method) continue;
    $match = $r;
    foreach ($m as $k => $v) {
        if (is_string($k)) $params[$k] = $k === 'key' ? rawurldecode($v) : (int)$v;
    }
    break;
}

$actor = api_token_actor();
if (isset($actor['ok'])) {      // a failure array
    api_token_log($actor['token_id'] ?? null, (int)$actor['http'], API_V2_BASE . ($match[1] ?? ($path_known ? $known_pattern : '/(unknown)')));
    if ((int)$actor['http'] === 401) header('WWW-Authenticate: Bearer realm="GameNight"');
    v2_send((int)$actor['http'], ['ok' => false, 'error' => $actor['code'], 'message' => $actor['message']]);
}

if (!$match) {
    $code = $path_known ? 405 : 404;
    // A real path asked the wrong way is logged as that path; anything else
    // as (unknown), never as the address that was typed.
    api_token_log($actor['token_id'], $code, API_V2_BASE . ($path_known ? $known_pattern : '/(unknown)'));
    v2_send($code, ['ok' => false,
        'error' => $path_known ? 'method_not_allowed' : 'not_found',
        'message' => $path_known ? "That path does not accept $method." : 'No such endpoint. GET ' . API_V2_BASE . ' lists them.']);
}

if (!gn_actor_has_scope($actor, $match[2])) {
    v2_finish($actor, $match[1], gn_err('missing_scope',
        "This token does not have the \"{$match[2]}\" scope. Mint one that does under Settings.", 403));
}

// ── Writes: a JSON body, a per-route hourly limit, and replay protection ────
$body = []; $idem = null;
if ($method !== 'GET') {
    $raw = (string)file_get_contents('php://input');
    if (trim($raw) !== '') {
        $body = json_decode($raw, true);
        if (!is_array($body) || ($body && array_is_list($body))) {
            v2_finish($actor, $match[1], gn_err('bad_json', 'The request body must be a JSON object.', 400));
        }
    }
    // 60 successful writes an hour per token on any one route: enough for a
    // host working through a guest list, not enough for a loop gone wrong.
    try {
        $wl = get_db()->prepare("SELECT COUNT(*) FROM api_request_log WHERE token_id = ? AND status = 200 AND method = ? AND path = ? AND created_at > datetime('now', '-1 hour')");
        $wl->execute([$actor['token_id'], $method, API_V2_BASE . $match[1]]);
        if ((int)$wl->fetchColumn() >= 60) {
            header('Retry-After: 600');
            v2_finish($actor, $match[1], gn_err('write_limit', 'This token has made 60 of these changes in the last hour. Try again later.', 429));
        }
    } catch (Exception $e) { /* a counter glitch never blocks a real client */ }

    // Idempotency-Key on a POST: the first answer is kept for a day and
    // returned again, so asking twice does the thing once.
    $ik = trim((string)($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ''));
    if ($method === 'POST' && $ik !== '') {
        if (!preg_match('/^[A-Za-z0-9_.:\-]{8,128}$/', $ik)) {
            v2_finish($actor, $match[1], gn_err('bad_idempotency_key', 'Idempotency-Key must be 8 to 128 letters, digits, dots, colons, dashes or underscores.', 400));
        }
        $idem = ['token_id' => $actor['token_id'], 'key' => $ik, 'path' => $path];
        try {
            $idb = get_db();
            $idb->exec("DELETE FROM api_idempotency WHERE created_at < datetime('now', '-1 day')");
            $prev = $idb->prepare('SELECT status, body FROM api_idempotency WHERE token_id = ? AND idem_key = ? AND path = ?');
            $prev->execute([$idem['token_id'], $ik, $path]);
            if ($row = $prev->fetch(PDO::FETCH_ASSOC)) {
                api_token_log($actor['token_id'], (int)$row['status'], API_V2_BASE . $match[1]);
                header('Idempotent-Replay: true');
                api_send_headers(0);
                http_response_code((int)$row['status']);
                echo $row['body'];
                exit;
            }
        } catch (Exception $e) { $idem = null; }
    }
}

try {
    $res = ($match[4])($actor, $params, $_GET, $body);
} catch (Throwable $e) {
    if ($e instanceof PDOException && stripos($e->getMessage(), 'database is locked') !== false) {
        // Another writer (usually the notification queue going out) held the
        // database longer than the five seconds a request waits. Nothing was
        // changed; the caller should simply ask again.
        if (get_db()->inTransaction()) get_db()->rollBack();
        header('Retry-After: 3');
        $res = gn_err('busy', 'The site is busy sending notifications. Nothing was changed; try again in a few seconds.', 503);
    } else {
        error_log('api v2 ' . $method . ' ' . $match[1] . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        $res = gn_err('server_error', 'Something went wrong on the server.', 500);
    }
}
v2_finish($actor, $match[1], $res, $idem);
