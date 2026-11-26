<?php
/**
 * Saves for the invite design editor (invite_design.php).
 * POST, JSON, CSRF, can_manage_event(). Actions:
 *   save          theme, accent, font, effect: each a key of the catalogue in
 *                 _invite_design.php; anything else falls back to the default
 *   cover_upload  the picture in $_FILES['cover'] becomes the event's cover
 *   cover_remove  the cover is taken off and its file deleted
 * Every answer carries the design as now stored.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_invite_design.php';

header('Content-Type: application/json');

function idd_fail(int $code, string $msg): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

$current = current_user();
if (!$current) idd_fail(401, "You've been signed out. Reload the page and sign in again.");
if ($_SERVER['REQUEST_METHOD'] !== 'POST') idd_fail(405, 'POST required');
if (!csrf_verify()) idd_fail(403, 'Invalid request. Reload the page and try again.');

$db      = get_db();
$uid     = (int)$current['id'];
$isAdmin = ($current['role'] ?? '') === 'admin';
$eid     = (int)($_POST['event_id'] ?? 0);
$action  = (string)($_POST['action'] ?? '');

if ($eid <= 0 || !can_manage_event($db, $eid, $uid, $isAdmin)) {
    idd_fail(403, 'Only the event\'s hosts can change its invite design.');
}
if (event_is_cancelled($db, $eid)) idd_fail(409, EVENT_CANCELLED_MSG);

switch ($action) {
    case 'save':
        $d = array_merge(invite_design_for_event($db, $eid), invite_design_parts($_POST));
        invite_design_store($db, $eid, $d);
        db_log_activity($uid, "updated invite design for event id: $eid");
        break;

    case 'cover_upload':
        // The same daily count upload.php keeps: each stored picture logs
        // "uploaded image: ...", so the activity log is the counter.
        if (!$isAdmin) {
            $capQ = $db->prepare("SELECT COUNT(*) FROM activity_log
                                  WHERE user_id = ? AND action LIKE 'uploaded image:%'
                                    AND created_at > datetime('now', '-1 day')");
            $capQ->execute([$uid]);
            if ((int)$capQ->fetchColumn() >= MAX_UPLOADS_PER_DAY) idd_fail(429, 'Daily upload limit reached. Try again tomorrow.');
        }
        $d = invite_design_cover_take($db, $eid, $_FILES['cover'] ?? []);
        if (isset($d['error'])) idd_fail(400, $d['error']);
        db_log_activity($uid, 'uploaded image: ' . ltrim(substr($d['cover'], strlen('/uploads/')), '/'));
        break;

    case 'cover_remove':
        $d = invite_design_cover_remove($db, $eid);
        db_log_activity($uid, "removed invite cover for event id: $eid");
        break;

    default:
        idd_fail(400, 'Unknown action.');
}

echo json_encode(['ok' => true, 'design' => $d]);
