<?php
/**
 * Admin-only JSON endpoint: returns current invite list for an event.
 * Used by the calendar page to poll for live RSVP updates.
 *
 * GET /event_invites_dl.php?eid=123
 */
require_once __DIR__ . '/auth.php';

$current = current_user();
if (!$current || $current['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['ok' => false]);
    exit;
}

$eid = (int)($_GET['eid'] ?? 0);
if ($eid <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false]);
    exit;
}

$db   = get_db();
$stmt = $db->prepare(
    'SELECT username, phone, email, rsvp, occurrence_date
     FROM event_invites
     WHERE event_id = ?
     ORDER BY username'
);
$stmt->execute([$eid]);

$base = [];
$occ  = [];
foreach ($stmt->fetchAll() as $inv) {
    if ($inv['occurrence_date'] === null) {
        $base[] = ['username' => $inv['username'], 'phone' => $inv['phone'], 'email' => $inv['email'], 'rsvp' => $inv['rsvp']];
    } else {
        $occ[$inv['occurrence_date']][] = ['username' => $inv['username'], 'rsvp' => $inv['rsvp']];
    }
}

header('Content-Type: application/json');
echo json_encode(['ok' => true, 'base' => $base, 'occ' => $occ]);
