<?php
/**
 * /mcp — Game Night as a Model Context Protocol server (Streamable HTTP).
 *
 * This is what lets an assistant such as Claude work with a host's events,
 * guests and games: it offers the tools in _mcp_tools.php, each a wrapper
 * over the same service functions /api/v2 calls. The caller is a person,
 * known by their personal token (Settings → Security → API Tokens), and a
 * tool is offered only if the token has the permission it needs. An app can
 * also get a token by sending the person to sign in (the OAuth endpoints
 * under /oauth, described by /.well-known/oauth-protected-resource).
 *
 * Stateless by design: every request stands alone, is answered with one JSON
 * body, and nothing is remembered between requests. Two generations of the
 * protocol are served on this one address:
 *   - 2026-07-28: no handshake. A request carries its protocol version in
 *     params._meta and the MCP-Protocol-Version header; server/discover says
 *     what this server offers.
 *   - 2025-11-25 and earlier: the client opens with `initialize`. It is
 *     answered, and no session id is issued, so its later requests need none.
 * A request with a modern _meta is modern; anything else is treated as the
 * older style, which asks less of the client.
 */

require_once __DIR__ . '/api/_auth.php';
require_once __DIR__ . '/_mcp_tools.php';

const MCP_MODERN   = '2026-07-28';
const MCP_LEGACY   = ['2025-11-25', '2025-06-18', '2025-03-26'];
const MCP_META_VER = 'io.modelcontextprotocol/protocolVersion';

function mcp_headers(): void {
    static $sent = false;
    if ($sent) return;
    $sent = true;
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: Authorization, Content-Type, Accept, MCP-Protocol-Version, Mcp-Method, Mcp-Name, Mcp-Session-Id');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
}

function mcp_send(int $http, ?array $body): void {
    mcp_headers();
    http_response_code($http);
    if ($body !== null) echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function mcp_error(int $http, $id, int $code, string $message, ?array $data = null): void {
    $err = ['code' => $code, 'message' => $message];
    if ($data !== null) $err['data'] = $data;
    $body = ['jsonrpc' => '2.0', 'error' => $err];
    if ($id !== null) $body = ['jsonrpc' => '2.0', 'id' => $id, 'error' => $err];
    mcp_send($http, $body);
}

/** A successful JSON-RPC result. Modern results carry resultType, as that revision requires. */
function mcp_result($id, array $result, bool $modern): void {
    if ($modern) $result = ['resultType' => 'complete'] + $result;
    mcp_send(200, ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result === [] ? new stdClass() : $result]);
}

function mcp_instructions(): string {
    return 'Game Night runs home poker nights and other get-togethers: events with guest lists and RSVPs, poker tournaments and cash games, and leagues with standings. '
         . 'You act as the signed-in person and can do what they can do on the site. Start with whoami, then list_events to find an event\'s id. '
         . 'Money is always in cents. Times are UTC; whoami gives the site\'s timezone and today\'s date there, and times you send need a UTC offset. '
         . 'Tools whose description says SENDS email or text real people, and cancel_event tells every guest: say what will happen and get a clear yes first. '
         . 'delete_event, finishing a game and resetting the clock need confirm: true, which you set only after the person agrees. '
         . 'People are named as the host says them; if a name matches more than one person the tool lists the candidates, so ask which.';
}

/** A JSON Schema for a tool's arguments. An empty properties map must serialise as {} and not []. */
function mcp_input_schema(array $tool): array {
    return ['type' => 'object', 'properties' => $tool['args'] ? $tool['args'] : new stdClass(), 'required' => array_values($tool['required']), 'additionalProperties' => false];
}

/** A service result as a tool result: the data as JSON text and as structured content; a refusal as isError. */
function mcp_tool_result(array $res): array {
    if (!empty($res['ok'])) {
        unset($res['ok']);
        return ['content' => [['type' => 'text', 'text' => json_encode($res, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)]],
                'structuredContent' => $res ?: new stdClass(), 'isError' => false];
    }
    $code = (string)($res['code'] ?? 'error'); $msg = (string)($res['message'] ?? 'That could not be done.');
    return ['content' => [['type' => 'text', 'text' => $msg . " ($code)"]], 'structuredContent' => ['error' => $code, 'message' => $msg], 'isError' => true];
}

// ─────────────────────────────────────────────────────────────────────────────

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'OPTIONS') { mcp_headers(); http_response_code(204); exit; }

// A browser page on another site has no business posting here with someone's
// token; a real MCP client either sends no Origin or is the site itself (or a
// developer's tool on their own machine).
$origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
if ($origin !== '') {
    $site = parse_url(get_site_url());
    $o    = parse_url($origin);
    $same = $o && $site && strcasecmp((string)($o['host'] ?? ''), (string)($site['host'] ?? '')) === 0;
    $local = $o && in_array(strtolower((string)($o['host'] ?? '')), ['localhost', '127.0.0.1', '[::1]'], true);
    if (!$same && !$local) mcp_error(403, null, -32600, 'Requests from this origin are not accepted.');
}

if ($method !== 'POST') {
    // No stream to open and no session to end: this server answers each POST in full.
    header('Allow: POST, OPTIONS');
    mcp_error(405, null, -32600, 'This MCP server answers POST requests only.');
}

$actor = api_token_actor('mcp');
if (isset($actor['ok'])) {
    api_token_log($actor['token_id'] ?? null, (int)$actor['http'], '/mcp');
    // Tell an app where the sign-in lives (RFC 9728). No scope is named: the
    // app then asks for everything on offer and the person chooses on the
    // consent page what it actually gets.
    if ((int)$actor['http'] === 401) {
        header('WWW-Authenticate: Bearer realm="GameNight", resource_metadata="' . rtrim(get_site_url(), '/') . '/.well-known/oauth-protected-resource"'
             . (($actor['code'] ?? '') !== 'missing_token' ? ', error="invalid_token"' : ''));
    }
    mcp_error((int)$actor['http'], null, -32001, (string)$actor['message'] . ' Connect by signing in, or mint a personal token under Settings → Security → API Tokens.');
}
$actor['via'] = 'mcp';
$tid = (int)$actor['token_id'];

$msg = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($msg) || array_is_list($msg) || ($msg['jsonrpc'] ?? '') !== '2.0' || !is_string($msg['method'] ?? null)) {
    api_token_log($tid, 400, '/mcp');
    mcp_error(400, null, -32600, 'Send one JSON-RPC 2.0 request per POST. Batches are not accepted.');
}
$rpc    = $msg['method'];
$id     = $msg['id'] ?? null;
$params = is_array($msg['params'] ?? null) ? $msg['params'] : [];
$meta   = is_array($params['_meta'] ?? null) ? $params['_meta'] : [];
$log_as = '/mcp/' . preg_replace('/[^a-z_\/]/', '', strtolower($rpc));

// A notification asks for nothing back.
if (!array_key_exists('id', $msg)) { api_token_log($tid, 202, $log_as); mcp_headers(); http_response_code(202); exit; }

// ── Which generation of the protocol is this request? ───────────────────────
$hdr_ver  = trim((string)($_SERVER['HTTP_MCP_PROTOCOL_VERSION'] ?? ''));
$meta_ver = is_string($meta[MCP_META_VER] ?? null) ? $meta[MCP_META_VER] : '';
$modern   = $meta_ver !== '' && $rpc !== 'initialize';
$all      = array_merge([MCP_MODERN], MCP_LEGACY);

if ($modern) {
    if ($hdr_ver !== '' && $hdr_ver !== $meta_ver) { api_token_log($tid, 400, $log_as); mcp_error(400, $id, -32020, "Header mismatch: MCP-Protocol-Version header value '$hdr_ver' does not match body value '$meta_ver'"); }
    if ($meta_ver !== MCP_MODERN) {
        // An older version named the modern way: tell the client what is on offer.
        api_token_log($tid, 400, $log_as);
        mcp_error(400, $id, -32022, 'Unsupported protocol version', ['supported' => $all, 'requested' => $meta_ver]);
    }
    $hm = trim((string)($_SERVER['HTTP_MCP_METHOD'] ?? ''));
    if ($hm !== '' && $hm !== $rpc) { api_token_log($tid, 400, $log_as); mcp_error(400, $id, -32020, "Header mismatch: Mcp-Method header value '$hm' does not match body value '$rpc'"); }
} elseif ($hdr_ver !== '' && !in_array($hdr_ver, $all, true)) {
    api_token_log($tid, 400, $log_as);
    mcp_error(400, $id, -32022, 'Unsupported protocol version', ['supported' => $all, 'requested' => $hdr_ver]);
}

require_once __DIR__ . '/version.php';
$server_info  = ['name' => 'gamenight', 'title' => (string)get_setting('site_name', 'Game Night'), 'version' => defined('APP_VERSION') ? APP_VERSION : '0'];
$db           = get_db();
$tools        = gn_mcp_tools();
$visible      = array_filter($tools, fn($t) => gn_actor_has_scope($actor, $t['scope']));

switch ($rpc) {
    case 'initialize': {
        // Older clients open with this. Answer in the version they asked for
        // when it is one served here, else the newest older one. No session
        // id is issued, so nothing has to be remembered for them.
        $want = is_string($params['protocolVersion'] ?? null) ? $params['protocolVersion'] : '';
        api_token_log($tid, 200, $log_as);
        mcp_result($id, ['protocolVersion' => in_array($want, MCP_LEGACY, true) ? $want : MCP_LEGACY[0],
            'capabilities' => ['tools' => ['listChanged' => false]], 'serverInfo' => $server_info, 'instructions' => mcp_instructions()], false);
    }
    case 'ping':
        api_token_log($tid, 200, $log_as);
        mcp_result($id, [], false);
    case 'server/discover':
        api_token_log($tid, 200, $log_as);
        // What is offered depends on whose token asked, so it is cacheable
        // only for that token.
        mcp_result($id, ['supportedVersions' => $all, 'capabilities' => ['tools' => new stdClass()],
            'instructions' => mcp_instructions(), '_meta' => ['io.modelcontextprotocol/serverInfo' => $server_info],
            'ttlMs' => 3600000, 'cacheScope' => 'private'], true);
    case 'tools/list': {
        $list = [];
        foreach ($visible as $name => $t) {
            $list[] = ['name' => $name, 'title' => $t['title'], 'description' => $t['description'],
                       'inputSchema' => mcp_input_schema($t), 'annotations' => ['title' => $t['title']] + $t['hints']];
        }
        api_token_log($tid, 200, $log_as);
        $res = ['tools' => $list];
        if ($modern) $res += ['ttlMs' => 300000, 'cacheScope' => 'private'];
        mcp_result($id, $res, $modern);
    }
    case 'tools/call': {
        $name = is_string($params['name'] ?? null) ? $params['name'] : '';
        $args = $params['arguments'] ?? [];
        if ($args instanceof stdClass || $args === null) $args = [];
        $hn = trim((string)($_SERVER['HTTP_MCP_NAME'] ?? ''));
        if ($modern && $hn !== '' && $hn !== $name) { api_token_log($tid, 400, $log_as); mcp_error(400, $id, -32020, "Header mismatch: Mcp-Name header value '$hn' does not match body value '$name'"); }
        if (!isset($tools[$name])) { api_token_log($tid, 400, $log_as); mcp_error(200, $id, -32602, 'Unknown tool: ' . mb_substr($name, 0, 80)); }
        if (!is_array($args) || ($args && array_is_list($args))) { api_token_log($tid, 400, $log_as); mcp_error(200, $id, -32602, 'Tool arguments must be an object.'); }
        $tool   = $tools[$name];
        $log_as = '/mcp/tools/' . $name;

        // Everything from here is the tool's own answer, good or bad.
        $finish = function (array $res) use ($id, $tid, $log_as, $modern): void {
            api_token_log($tid, !empty($res['ok']) ? 200 : (int)($res['http'] ?? 400), $log_as);
            mcp_result($id, mcp_tool_result($res), $modern);
        };
        if (!isset($visible[$name])) {
            $finish(gn_err('missing_scope', "This connection's token does not have the \"{$tool['scope']}\" permission, which $name needs. The person can mint a token that has it under Settings → Security → API Tokens.", 403));
        }
        // Arguments the tool does not know are refused by name rather than ignored.
        $unknown = array_diff(array_keys($args), array_keys($tool['args']));
        if ($unknown) $finish(gn_err('unknown_field', "$name does not take: " . implode(', ', $unknown) . '. It takes: ' . (implode(', ', array_keys($tool['args'])) ?: 'nothing') . '.', 400));
        foreach ($tool['required'] as $req) {
            if (!array_key_exists($req, $args) || $args[$req] === '' || $args[$req] === null) $finish(gn_err('bad_field', "$name needs \"$req\".", 400));
        }
        foreach (['event_id', 'league_id', 'season_id', 'request_id', 'entry_id'] as $k) {
            if (array_key_exists($k, $args) && (!is_int($args[$k]) || $args[$k] < 1)) $finish(gn_err('bad_field', "\"$k\" must be a whole number id.", 400));
        }
        // A tool that changes something: the same hourly ceiling as a route of /api/v2.
        if (empty($tool['hints']['readOnlyHint'])) {
            try {
                $wl = $db->prepare("SELECT COUNT(*) FROM api_request_log WHERE token_id = ? AND status = 200 AND path = ? AND created_at > datetime('now', '-1 hour')");
                $wl->execute([$tid, $log_as]);
                if ((int)$wl->fetchColumn() >= 60) $finish(gn_err('write_limit', "$name has been used 60 times in the last hour with this token. Try again later.", 429));
            } catch (Exception $e) { /* a counter glitch never blocks a real client */ }
        }
        try {
            $res = ($tool['run'])($db, $actor, $args, $finish);
        } catch (Throwable $e) {
            if ($e instanceof PDOException && stripos($e->getMessage(), 'database is locked') !== false) {
                if ($db->inTransaction()) $db->rollBack();
                $res = gn_err('busy', 'The site is busy sending notifications. Nothing was changed; try again in a few seconds.', 503);
            } else {
                error_log('mcp ' . $name . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
                $res = gn_err('server_error', 'Something went wrong on the server.', 500);
            }
        }
        $finish($res);
    }
    default:
        api_token_log($tid, 404, $log_as);
        mcp_error($modern ? 404 : 200, $id, -32601, 'Method not found: ' . mb_substr($rpc, 0, 80));
}
