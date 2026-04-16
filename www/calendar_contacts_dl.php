<?php
/**
 * Returns the list of "invite suggestions" for the event editor,
 * scoped by the selected league.
 *
 *   GET /calendar_contacts_dl.php?league_id=0    → personal network (no league)
 *   GET /calendar_contacts_dl.php?league_id=N    → that league's roster only (must be a member)
 *
 * Admins always get the full user list regardless of league_id.
 * Response: {ok: true, users: [{username, email, phone, display_name, is_pending}]}
 */
require_once __DIR__ . '/auth.php';
header('Content-Type: application/json');

$current = require_login();
$db      = get_db();
$uid     = (int)$current['id'];
$isAdmin = ($current['role'] ?? '') === 'admin';

$league_id = (int)($_GET['league_id'] ?? 0);

$users = [];

if ($isAdmin) {
    $rows = $db->query('SELECT username, email, phone FROM users ORDER BY LOWER(username)')->fetchAll();
    foreach ($rows as $r) {
        $users[] = [
            'username'     => $r['username'],
            'email'        => $r['email'] ?? '',
            'phone'        => $r['phone'] ?? '',
            'display_name' => $r['username'],
            'is_pending'   => 0,
        ];
    }
    echo json_encode(['ok' => true, 'users' => $users]);
    exit;
}

if ($league_id > 0) {
    // Must be a member of this league to see its roster.
    $role = league_role($league_id, $uid);
    if ($role === null) {
        echo json_encode(['ok' => false, 'error' => 'Not a member of that league.']);
        exit;
    }

    // Linked members
    $q1 = $db->prepare(
        "SELECT u.username, u.email, u.phone, u.username AS display_name, 0 AS is_pending
         FROM league_members lm
         JOIN users u ON u.id = lm.user_id
         WHERE lm.league_id = ?
         ORDER BY LOWER(u.username)"
    );
    $q1->execute([$league_id]);
    foreach ($q1->fetchAll() as $r) { $users[] = $r; }

    // Pending contacts (keyed by email so inviteUser() has a stable handle)
    $q2 = $db->prepare(
        "SELECT LOWER(contact_email) AS username,
                contact_email         AS email,
                contact_phone         AS phone,
                contact_name          AS display_name,
                1                     AS is_pending
         FROM league_members
         WHERE league_id = ? AND user_id IS NULL
         ORDER BY LOWER(contact_name)"
    );
    $q2->execute([$league_id]);
    foreach ($q2->fetchAll() as $r) { $users[] = $r; }

    echo json_encode(['ok' => true, 'users' => $users]);
    exit;
}

// No league picked — return the user's personal "network":
//  a) other members of leagues they're in
//  b) people they've previously invited on their own events
$q1 = $db->prepare(
    "SELECT DISTINCT u.username, u.email, u.phone, u.username AS display_name, 0 AS is_pending
     FROM users u
     JOIN league_members lm ON lm.user_id = u.id
     WHERE lm.league_id IN (SELECT league_id FROM league_members WHERE user_id = ?)
       AND u.id <> ?"
);
$q1->execute([$uid, $uid]);
$seen = [];
foreach ($q1->fetchAll() as $r) {
    $key = strtolower($r['username']);
    if (isset($seen[$key])) continue;
    $seen[$key] = true;
    $users[] = $r;
}

$q2 = $db->prepare(
    "SELECT DISTINCT LOWER(ei.username) AS username,
                     COALESCE(NULLIF(ei.email, ''), u.email) AS email,
                     COALESCE(NULLIF(ei.phone, ''), u.phone) AS phone,
                     COALESCE(u.username, ei.username) AS display_name,
                     0 AS is_pending
     FROM event_invites ei
     JOIN events e        ON e.id = ei.event_id
     LEFT JOIN users u    ON LOWER(u.username) = LOWER(ei.username)
     WHERE e.created_by = ?
       AND ei.username <> ''"
);
$q2->execute([$uid]);
foreach ($q2->fetchAll() as $r) {
    $key = strtolower($r['username']);
    if (isset($seen[$key])) continue;
    $seen[$key] = true;
    $users[] = $r;
}

usort($users, function($a, $b) { return strcasecmp($a['display_name'] ?? '', $b['display_name'] ?? ''); });

echo json_encode(['ok' => true, 'users' => $users]);
