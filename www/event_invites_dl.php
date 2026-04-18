<?php
/**
 * Admin-only JSON endpoint: returns current invite list for an event.
 * Used by the calendar page to poll for live RSVP updates.
 *
 * GET /event_invites_dl.php?eid=123
 */
require_once __DIR__ . '/auth.php';

require_login();
$current = current_user();
$isAdmin = $current && $current['role'] === 'admin';

$eid = (int)($_GET['eid'] ?? 0);
if ($eid <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false]);
    exit;
}

$db   = get_db();

// Verify user has access to this event (owner, event-manager, league owner/manager, or admin)
$evStmt = $db->prepare('SELECT created_by, league_id FROM events WHERE id = ?');
$evStmt->execute([$eid]);
$ev = $evStmt->fetch();
if (!$ev) { http_response_code(404); echo json_encode(['ok' => false]); exit; }
if (!$isAdmin && (int)$ev['created_by'] !== (int)$current['id']) {
    $mgrStmt = $db->prepare("SELECT 1 FROM event_invites WHERE event_id=? AND LOWER(username)=LOWER(?) AND event_role='manager' LIMIT 1");
    $mgrStmt->execute([$eid, $current['username']]);
    $isLeagueMgr = false;
    if (!empty($ev['league_id'])) {
        $lr = league_role((int)$ev['league_id'], (int)$current['id']);
        $isLeagueMgr = in_array($lr, ['owner', 'manager'], true);
    }
    if (!$mgrStmt->fetch() && !$isLeagueMgr) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Access denied']);
        exit;
    }
}

$stmt = $db->prepare(
    'SELECT username, phone, email, rsvp, occurrence_date, approval_status, sort_order, event_role
     FROM event_invites
     WHERE event_id = ?
     ORDER BY COALESCE(sort_order, 999999), username'
);
$stmt->execute([$eid]);

$base = [];
$occ  = [];
foreach ($stmt->fetchAll() as $inv) {
    if ($inv['occurrence_date'] === null) {
        $row = ['username' => $inv['username'], 'rsvp' => $inv['rsvp'], 'approval_status' => $inv['approval_status'], 'sort_order' => $inv['sort_order'], 'event_role' => $inv['event_role'] ?? 'invitee'];
        $base[] = $row;
    } else {
        $occ[$inv['occurrence_date']][] = ['username' => $inv['username'], 'rsvp' => $inv['rsvp'], 'approval_status' => $inv['approval_status']];
    }
}

header('Content-Type: application/json');
echo json_encode(['ok' => true, 'base' => $base, 'occ' => $occ]);
