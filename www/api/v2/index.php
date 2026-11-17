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

const API_V2_BASE = '/api/v2';

function v2_send(int $http, array $body): void {
    api_send_headers(0);          // personal, live data: never stored
    http_response_code($http);
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/** Answer with a service result, logging the request against the token. */
function v2_finish(array $actor, string $pattern, array $res): void {
    if (!empty($res['ok'])) {
        unset($res['ok']);
        api_token_log($actor['token_id'], 200, API_V2_BASE . $pattern);
        v2_send(200, ['ok' => true, 'data' => $res]);
    }
    $http = (int)($res['http'] ?? 400);
    api_token_log($actor['token_id'], $http, API_V2_BASE . $pattern);
    v2_send($http, ['ok' => false, 'error' => (string)($res['code'] ?? 'error'), 'message' => (string)($res['message'] ?? '')]);
}

/**
 * The routes: [method, pattern, scope, summary, handler(actor, path params, query)].
 * {id}-style segments are digits; {key} is any single segment. The summary is
 * what the discovery document prints.
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

        ['GET', '/events', 'read', 'Same as /me/events.',
            fn($a, $p, $q) => gn_events_list($db, $a, $q)],
        ['GET', '/events/{id}', 'read', 'One event, with RSVP totals, capacity and a summary of its game.',
            fn($a, $p, $q) => gn_event_get($db, $a, $p['id'])],
        ['GET', '/events/{id}/invites', 'read', 'The guest list. The host sees invite state and hand-entered contact details.',
            fn($a, $p, $q) => gn_event_invites($db, $a, $p['id'])],

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
            'rate_limit' => '120 requests a minute per token.',
        ],
        'endpoints' => array_map(fn($r) => ['method' => $r[0], 'path' => API_V2_BASE . $r[1], 'scope' => $r[2], 'summary' => $r[3]], $routes),
        'docs' => 'https://github.com/Isorgcom/GameNight/blob/main/DOCS.md#personal-api-v2',
    ]]);
}

// Match the path first, so a wrong method on a real path is 405 and not 404.
$match = null; $path_known = false; $known_pattern = ''; $params = [];
foreach ($routes as $r) {
    $re = '#^' . preg_replace(['#\{(id|pid|sid)\}#', '#\{(key)\}#'], ['(?P<$1>\d{1,12})', '(?P<$1>[^/]{1,200})'], $r[1]) . '$#';
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

try {
    $res = ($match[4])($actor, $params, $_GET);
} catch (Throwable $e) {
    error_log('api v2 ' . $method . ' ' . $match[1] . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    $res = gn_err('server_error', 'Something went wrong on the server.', 500);
}
v2_finish($actor, $match[1], $res);
