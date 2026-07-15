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
    "SELECT username, phone, email, rsvp, occurrence_date, approval_status, sort_order, event_role,
            (SELECT 1 FROM event_notifications_sent ens
             WHERE ens.event_id = event_invites.event_id AND ens.notification_type = 'invite'
               AND ens.occurrence_date = '' AND ens.user_identifier = LOWER(event_invites.username)
             LIMIT 1) AS invite_sent
     FROM event_invites
     WHERE event_id = ?
     ORDER BY COALESCE(sort_order, 999999), username"
);
$stmt->execute([$eid]);

// Per-recipient delivery outcome for this event's invites/nudges, combining:
//  - queue state (waiting / dead after retries) from pending_notifications
//  - the latest correlated provider log row (sent / failed / parked-for-retry).
// Log correlation exists only for notifications sent after the event_id/username
// columns were added; older sends simply have no delivery info (state null).
$q_state = [];
$qs = $db->prepare(
    "SELECT LOWER(username) AS u,
            SUM(CASE WHEN attempted_at IS NULL AND attempts < 3 THEN 1 ELSE 0 END) AS waiting,
            SUM(CASE WHEN attempted_at IS NULL AND attempts >= 3 THEN 1 ELSE 0 END) AS dead
     FROM pending_notifications
     WHERE event_id = ? AND notify_type IN ('invite', 'rsvp_nudge')
     GROUP BY LOWER(username)"
);
$qs->execute([$eid]);
foreach ($qs->fetchAll() as $r) $q_state[$r['u']] = $r;

$log_state = [];
$ls = $db->prepare(
    "SELECT LOWER(username) AS u, status, error, provider, MAX(id) AS mid
     FROM sms_log
     WHERE event_id = ? AND direction = 'outbound' AND username IS NOT NULL
     GROUP BY LOWER(username)"
);
$ls->execute([$eid]);
foreach ($ls->fetchAll() as $r) $log_state[$r['u']] = $r;

function _invite_delivery(?array $q, ?array $l): array {
    // A fresh queue row (e.g. after Retry) outranks an old dead one.
    if ($q && (int)$q['waiting'] > 0) {
        return ['state' => 'sending', 'error' => null];
    }
    if ($q && (int)$q['dead'] > 0) {
        return ['state' => 'failed', 'error' => $l['error'] ?? 'Gave up after retries'];
    }
    if ($l) {
        if ($l['status'] === 'failed') return ['state' => 'failed', 'error' => $l['error'] ?: 'Provider error'];
        if ($l['status'] === 'queued') return ['state' => 'sending', 'error' => null]; // parked in email retry queue
        if ($l['status'] === 'sent')   return ['state' => 'delivered', 'error' => null];
    }
    return ['state' => null, 'error' => null]; // no correlated info (pre-tracking sends)
}

$base = [];
$occ  = [];
foreach ($stmt->fetchAll() as $inv) {
    if ($inv['occurrence_date'] === null) {
        $u = strtolower($inv['username']);
        $delivery = _invite_delivery($q_state[$u] ?? null, $log_state[$u] ?? null);
        $row = ['username' => $inv['username'], 'rsvp' => $inv['rsvp'], 'approval_status' => $inv['approval_status'], 'sort_order' => $inv['sort_order'], 'event_role' => $inv['event_role'] ?? 'invitee', 'sent' => !empty($inv['invite_sent']), 'phone' => $inv['phone'] ?? '', 'email' => $inv['email'] ?? '', 'no_contact' => (trim((string)($inv['phone'] ?? '')) === '' && trim((string)($inv['email'] ?? '')) === ''), 'delivery' => $delivery['state'], 'delivery_error' => $delivery['error']];
        $base[] = $row;
    } else {
        $occ[$inv['occurrence_date']][] = ['username' => $inv['username'], 'rsvp' => $inv['rsvp'], 'approval_status' => $inv['approval_status']];
    }
}

// Live invite delivery status: invites for this event still waiting in the
// queue, already dispatched, or dead after exhausting retries. The modal polls
// this, so the host sees "sending…" progress instead of guessing whether the
// send button worked. (Failed = attempts exhausted; row ages out after 7 days.)
$q = $db->prepare(
    "SELECT
        SUM(CASE WHEN attempted_at IS NULL AND attempts < 3 THEN 1 ELSE 0 END)  AS pending,
        SUM(CASE WHEN attempted_at IS NOT NULL THEN 1 ELSE 0 END)               AS dispatched,
        SUM(CASE WHEN attempted_at IS NULL AND attempts >= 3 THEN 1 ELSE 0 END) AS failed
     FROM pending_notifications
     WHERE event_id = ? AND notify_type IN ('invite', 'rsvp_nudge')"
);
$q->execute([$eid]);
$qr = $q->fetch() ?: [];
$invite_queue = [
    'pending'    => (int)($qr['pending'] ?? 0),
    'dispatched' => (int)($qr['dispatched'] ?? 0),
    'failed'     => (int)($qr['failed'] ?? 0),
];

header('Content-Type: application/json');
echo json_encode(['ok' => true, 'base' => $base, 'occ' => $occ, 'invite_queue' => $invite_queue]);
