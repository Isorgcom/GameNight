<?php
/**
 * The host's shared invite link for one event (event.php's Share invite panel).
 * POST, JSON, CSRF, can_manage_event(). Actions:
 *   get           the link, minted on first use
 *   set_approval  on=1|0: people joining through the link wait for the host
 *   off           the link stops working (the walk-up QR is a separate token)
 *   rotate        a new link; the old one stops working (also "turn back on")
 *
 * events.share_token: NULL = never minted, '' = turned off by the host,
 * otherwise the live token. Answers the link, whether it is on, the approval
 * switch, and how many people joined through it (and how many wait).
 */
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json');

$current = current_user();
if (!$current) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => "You've been signed out. Reload the page and sign in again."]);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}
if (!csrf_verify()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid request. Reload the page and try again.']);
    exit;
}

$db      = get_db();
$uid     = (int)$current['id'];
$isAdmin = ($current['role'] ?? '') === 'admin';
$eid     = (int)($_POST['event_id'] ?? 0);
$action  = (string)($_POST['action'] ?? 'get');

if ($eid <= 0 || !can_manage_event($db, $eid, $uid, $isAdmin)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Only the event\'s hosts can share its invite link.']);
    exit;
}
if (event_is_cancelled($db, $eid)) {
    http_response_code(409);
    echo json_encode(['ok' => false, 'error' => EVENT_CANCELLED_MSG]);
    exit;
}

function share_link_state(PDO $db, int $eid): array {
    $s = $db->prepare('SELECT share_token, share_requires_approval FROM events WHERE id = ?');
    $s->execute([$eid]);
    $r = $s->fetch() ?: ['share_token' => null, 'share_requires_approval' => 1];
    $tok = (string)($r['share_token'] ?? '');
    $c = $db->prepare("SELECT COUNT(*) AS joined,
                              SUM(CASE WHEN approval_status = 'pending' THEN 1 ELSE 0 END) AS waiting
                       FROM event_invites
                       WHERE event_id = ? AND occurrence_date IS NULL AND join_source = 'share'");
    $c->execute([$eid]);
    $n = $c->fetch() ?: [];
    return [
        'ok'       => true,
        'on'       => $tok !== '',
        'url'      => $tok !== '' ? get_site_url() . '/invite.php?e=' . $eid . '&t=' . $tok : '',
        'approval' => (int)($r['share_requires_approval'] ?? 1) === 1,
        'joined'   => (int)($n['joined'] ?? 0),
        'waiting'  => (int)($n['waiting'] ?? 0),
    ];
}

$newToken = fn() => bin2hex(random_bytes(16));

switch ($action) {
    case 'get':
        // Mint on first use only: a link the host turned off ('') stays off
        // until they ask for a new one.
        $db->prepare('UPDATE events SET share_token = ? WHERE id = ? AND share_token IS NULL')
           ->execute([$newToken(), $eid]);
        break;
    case 'set_approval':
        $on = ($_POST['on'] ?? '1') === '1' ? 1 : 0;
        $db->prepare('UPDATE events SET share_requires_approval = ? WHERE id = ?')->execute([$on, $eid]);
        db_log_activity($uid, "share link approval " . ($on ? 'on' : 'off') . " for event id: $eid");
        break;
    case 'off':
        $db->prepare("UPDATE events SET share_token = '' WHERE id = ?")->execute([$eid]);
        db_log_activity($uid, "turned off share link for event id: $eid");
        break;
    case 'rotate':
        $db->prepare('UPDATE events SET share_token = ? WHERE id = ?')->execute([$newToken(), $eid]);
        db_log_activity($uid, "new share link for event id: $eid");
        break;
    default:
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Unknown action']);
        exit;
}

echo json_encode(share_link_state($db, $eid));
