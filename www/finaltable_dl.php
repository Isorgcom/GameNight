<?php
/**
 * Data endpoint for an event played on FinalTable (event.php's panel).
 *
 *   setup   POST  make the game: the roster, the blinds, a webhook (managers)
 *   start   POST  start a registering game now            (managers)
 *   pause   POST  hold the clock                          (managers)
 *   resume  POST  let it go                               (managers)
 *   cancel  POST  call the game off                       (managers)
 *   remove  POST  take a player out of play  {user_id}    (managers)
 *   state   GET   the game as this side knows it, plus a fresh read while it
 *                 is live (anyone who can see the event)
 *
 * Every POST is CSRF-checked and proxies one call to the FinalTable server
 * the event is tied to; a refusal comes back as {ok:false, error} in
 * FinalTable's own words. `bots` on setup (0-40 seats the server plays, for
 * trying it out) is taken from admins only and has no control in the UI.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/_poker_helpers.php';
require_once __DIR__ . '/_finaltable.php';

header('Content-Type: application/json');
$current = current_user();
if (!$current) { http_response_code(401); echo json_encode(['ok' => false, 'error' => 'Not authenticated']); exit; }
$isAdmin = $current['role'] === 'admin';
$db = get_db();
$uid = (int)$current['id'];

$action   = (string)($_POST['action'] ?? $_GET['action'] ?? '');
$event_id = (int)($_POST['event_id'] ?? $_GET['event_id'] ?? 0);

function ft_fail(string $msg, int $code = 200): void {
    if ($code !== 200) http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

// ── state: anyone who can see the event, but not the same answer for all ──
// The panel shows a viewer less than it shows a manager, and this endpoint
// has to draw the same lines rather than leave them to the page: the guest
// list and the seat-by-seat table are a manager's, the join link belongs to
// people with a seat, and the finishing order is nobody's business here -
// event.php renders the winner's name server-side and sends the rest to
// Manage Game. Anyone who can see the event gets the clock and a head count.
if ($action === 'state') {
    $vis = event_visibility_sql('e', $uid);
    $evq = $db->prepare("SELECT e.* FROM events e WHERE e.id = ? AND {$vis['sql']}");
    $evq->execute(array_merge([$event_id], $vis['params']));
    $ev = $evq->fetch();
    if (!$ev) ft_fail('Event not found', 404);
    $canManage = can_manage_event($db, $event_id, $uid, $isAdmin);

    $row = finaltable_game_for_event($db, $event_id);
    $a = $db->prepare('SELECT * FROM sso_apps WHERE id = ?');
    $a->execute([(int)($row['app_id'] ?? $ev['online_app_id'] ?? 0)]);
    $app = $a->fetch() ?: ['name' => 'FinalTable', 'base_url' => ''];
    if (!$row) { echo json_encode(['ok' => true, 'game' => null, 'live' => null, 'live_error' => null, 'can_manage' => $canManage]); exit; }

    $live = null; $liveError = null;
    if (in_array($row['status'], ['registering', 'running'], true)) {
        $stale = empty($row['last_read_at']) || (time() - strtotime($row['last_read_at'] . ' UTC')) >= 10;
        if ($stale) {
            $r = finaltable_request($app, 'GET', '/api/games/' . rawurlencode((string)$row['game_id']));
            if ($r['ok'] && is_array($r['data'])) {
                $db->prepare("UPDATE finaltable_games SET last_read = ?, last_read_at = ? WHERE id = ?")
                   ->execute([json_encode($r['data']), gmdate('Y-m-d H:i:s'), (int)$row['id']]);
                $live = $r['data'];
            } elseif ($r['status'] === 404) {
                $liveError = $app['name'] . ' no longer holds this game.';
            } else {
                $liveError = $r['error'];
                $live = $row['last_read'] ? (json_decode((string)$row['last_read'], true) ?: null) : null;
            }
        } else {
            $live = $row['last_read'] ? (json_decode((string)$row['last_read'], true) ?: null) : null;
        }
    }
    // On the guest list: a manager, or an approved invitee by account id -
    // the same test event.php makes before it shows the join link.
    $ftIsRoster = $canManage;
    if (!$ftIsRoster) {
        $q = $db->prepare("SELECT 1 FROM event_invites
                           WHERE event_id = ? AND occurrence_date IS NULL
                             AND approval_status = 'approved' AND user_id = ?");
        $q->execute([$event_id, $uid]);
        $ftIsRoster = (bool)$q->fetchColumn();
    }

    $game = finaltable_public_game($row, $app);
    if (!$ftIsRoster) { $game['code'] = null; $game['join_url'] = ''; }
    // The standings ride in on tournament.completed and stay in last_status
    // for the server-rendered panel. Nothing in finaltable.js reads them, and
    // handing every viewer the night's full finishing order - names, ids and
    // prizes - would walk straight past hide_guest_list.
    if (is_array($game['last_status'])) unset($game['last_status']['standings']);

    if (is_array($live)) {
        $seated = is_array($live['entrants'] ?? null) ? count($live['entrants']) : null;
        unset($live['webhook']);   // the callback address is no use to a browser
        if (!$canManage) {
            // The clock and nothing else. roster is the guest list by name,
            // entrants is who is sitting where with how many chips: both are
            // the manager's panel, which is the only thing that renders them.
            $live = array_intersect_key($live, array_flip([
                'id', 'status', 'startsAt', 'startedAt', 'finishedAt', 'level', 'paused',
                'awayHeld', 'awayHeldSince', 'nextLevelIn', 'remaining', 'winner',
            ]));
        }
        $live['seated'] = $seated;   // the head count the status line shows everyone
    }

    echo json_encode([
        'ok'         => true,
        'game'       => $game,
        'live'       => $live,
        'live_error' => $liveError,
        'can_manage' => $canManage,
    ]);
    exit;
}

// ── everything else: a manager, by POST, with a token ────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') ft_fail('POST only', 405);
if (!csrf_verify()) ft_fail('CSRF token invalid', 403);
verify_event_access($db, $event_id, $current, $isAdmin);   // exits on failure

$evq = $db->prepare('SELECT * FROM events WHERE id = ?');
$evq->execute([$event_id]);
$ev = $evq->fetch();
if (!$ev) ft_fail('Event not found', 404);

if ($action === 'setup') {
    $pre = finaltable_setup_preview($db, $ev, $uid);
    if (!$pre['ok']) ft_fail((string)$pre['error']);
    $app  = $pre['app'];
    $sess = $pre['session'];
    $sid  = (int)$sess['id'];

    // A game that is still there is not replaced from here; one FinalTable
    // has let go of (a cancel from its side, a finish it has forgotten) is.
    $old = finaltable_game_for_event($db, $event_id);
    if ($old && in_array($old['status'], ['registering', 'running'], true)) {
        $chk = finaltable_request($app, 'GET', '/api/games/' . rawurlencode((string)$old['game_id']));
        if ($chk['ok']) ft_fail('The table is already set up.');
        if ($chk['status'] !== 404) ft_fail($chk['error']);
    }

    $secret = bin2hex(random_bytes(24));
    $body = [
        'title'           => mb_substr((string)$ev['title'], 0, 24),
        'seats_per_table' => $pre['seats'],
        'starting_chips'  => $pre['chips'],
        'buyin_amount'    => $pre['buyin'],
        'addon_allowed'   => $pre['addon'],
        'reentryLevels'   => $pre['reentry_levels'],
        'invitees'        => $pre['roster']['invitees'],
        'webhook'         => ['url' => get_site_url() . '/finaltable_webhook.php', 'secret' => $secret],
        'external_id'     => (string)$event_id,
    ];
    if ($pre['start_at']) $body['start_at'] = $pre['start_at'];
    if ($pre['blinds']['levels']) {
        $body['blind_levels']   = $pre['blinds']['levels'];
        $body['structure_name'] = mb_substr((string)($pre['blinds']['name'] ?: 'GameNight'), 0, 40);
    }
    if ($isAdmin && isset($_POST['bots'])) $body['bots'] = max(0, min(40, (int)$_POST['bots']));

    $r = finaltable_request($app, 'POST', '/api/games', $body);
    if (!$r['ok'] || !is_array($r['data']) || empty($r['data']['id'])) ft_fail($r['error'] !== '' ? $r['error'] : 'FinalTable did not make the game.');
    $g = $r['data'];

    $db->prepare('DELETE FROM finaltable_games WHERE event_id = ?')->execute([$event_id]);
    $db->prepare("INSERT INTO finaltable_games
                    (event_id, app_id, game_id, code, rail, join_url, rail_url, webhook_secret, status, last_read, last_read_at, created_by)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'registering', ?, ?, ?)")
       ->execute([
           $event_id, (int)$app['id'], (string)$g['id'],
           $g['code'] ?? null, $g['rail'] ?? null,
           $g['links']['join'] ?? null, $g['links']['rail'] ?? null,
           encrypt_value($secret), json_encode($g), gmdate('Y-m-d H:i:s'), $uid,
       ]);
    $row = finaltable_game_for_event($db, $event_id);

    // The roster becomes the session's players the way it does for any game.
    sync_invitees($db, $sid, $event_id);

    // Everyone on the guest list is told where to play, once, with the link.
    require_once __DIR__ . '/_notifications.php';
    $links = finaltable_links($app, $row);
    foreach ($pre['roster']['invitees'] as $inv) {
        queue_event_notification($db, $event_id, (string)$inv['username'], 'online_table', null, [
            'app' => (string)$app['name'], 'join_url' => $links['join'], 'rail_url' => $links['rail'], 'code' => (string)($g['code'] ?? ''),
        ]);
    }

    $notes = $pre['notes'];
    $got = (int)($g['settings']['startChips'] ?? 0);
    if ($got && $got !== $pre['chips']) $notes[] = "Stacks are $got chips there.";
    pk_log($db, $sid, $uid, 'note', null, null, null,
           'Table set up on ' . $app['name'] . ' (' . count($pre['roster']['invitees']) . ' on the guest list)');
    db_log_activity($uid, "finaltable setup event #$event_id game=" . $g['id'] . ' roster=' . count($pre['roster']['invitees']));
    echo json_encode(['ok' => true, 'game' => finaltable_public_game($row, $app), 'live' => $g, 'notes' => array_values(array_unique($notes))]);
    exit;
}

$controls = ['start', 'pause', 'resume', 'cancel', 'remove'];
if (in_array($action, $controls, true)) {
    $row = finaltable_game_for_event($db, $event_id);
    if (!$row || !in_array($row['status'], ['registering', 'running'], true)) ft_fail('There is no live game to drive.');
    $a = $db->prepare('SELECT * FROM sso_apps WHERE id = ?');
    $a->execute([(int)$row['app_id']]);
    $app = $a->fetch();
    if (!$app) ft_fail('The app this game is on no longer exists.');

    $path = '/api/games/' . rawurlencode((string)$row['game_id']) . '/' . $action;
    $body = null;
    if ($action === 'remove') {
        $target = (int)($_POST['user_id'] ?? 0);
        if ($target <= 0) ft_fail('Nobody named.');
        $body = ['user_id' => $target];
    }
    $r = finaltable_request($app, 'POST', $path, $body);
    if (!$r['ok']) {
        if ($r['status'] === 404) {
            $db->prepare("UPDATE finaltable_games SET status = 'cancelled', outcome = 'cancelled', reason = 'gone', ended_at = ? WHERE id = ?")
               ->execute([gmdate('Y-m-d H:i:s'), (int)$row['id']]);
            ft_fail($app['name'] . ' no longer holds this game.');
        }
        ft_fail($r['error'] !== '' ? $r['error'] : 'Refused.');
    }
    $g = is_array($r['data']) ? $r['data'] : [];
    $now = gmdate('Y-m-d H:i:s');
    if ($action === 'cancel') {
        $db->prepare("UPDATE finaltable_games SET status = 'cancelled', outcome = 'cancelled', reason = ?, ended_at = ?, last_read = ?, last_read_at = ? WHERE id = ?")
           ->execute([(string)($g['reason'] ?? 'cancelled by GameNight'), $now, json_encode($g), $now, (int)$row['id']]);
    } else {
        $status = in_array((string)($g['status'] ?? ''), ['registering', 'running', 'finished'], true) ? (string)$g['status'] : $row['status'];
        $db->prepare("UPDATE finaltable_games SET status = ?, last_read = ?, last_read_at = ? WHERE id = ?")
           ->execute([$status, json_encode($g), $now, (int)$row['id']]);
    }
    $row = finaltable_game_for_event($db, $event_id);
    db_log_activity($uid, "finaltable $action event #$event_id game=" . $row['game_id'] . ($body ? ' user #' . $body['user_id'] : ''));
    $out = ['ok' => true, 'game' => finaltable_public_game($row, $app), 'live' => $g];
    if ($action === 'remove') $out['remove'] = $g['remove'] ?? null;
    echo json_encode($out);
    exit;
}

ft_fail('Unknown action');
