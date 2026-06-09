<?php
/**
 * Returns the list of "invite suggestions" for the event editor.
 *
 *   GET /calendar_contacts_dl.php?league_id=0    → user's personal contacts only
 *   GET /calendar_contacts_dl.php?league_id=N    → league roster MERGED with user's personal contacts
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
$seen  = []; // dedup by username-key

// Pre-compute the league's registered-member user_ids so each emitted row
// can be tagged is_league_member. Empty when no league selected.
$leagueMemberIds = [];
if ($league_id > 0) {
    $lm = $db->prepare('SELECT user_id FROM league_members WHERE league_id = ? AND user_id IS NOT NULL');
    $lm->execute([$league_id]);
    foreach ($lm->fetchAll(PDO::FETCH_COLUMN) as $mid) {
        $leagueMemberIds[(int)$mid] = true;
    }
}

function _add_seen(array &$users, array &$seen, array $row): void {
    $key = strtolower($row['username'] ?? '');
    if ($key === '' || isset($seen[$key])) return;
    $seen[$key] = true;
    $users[] = $row;
}

if ($isAdmin) {
    $rows = $db->query('SELECT id, username, email, phone FROM users ORDER BY LOWER(username)')->fetchAll();
    foreach ($rows as $r) {
        _add_seen($users, $seen, [
            'username'         => $r['username'],
            'email'            => $r['email'] ?? '',
            'phone'            => $r['phone'] ?? '',
            'display_name'     => $r['username'],
            'is_pending'       => 0,
            'is_league_member' => isset($leagueMemberIds[(int)$r['id']]) ? 1 : 0,
        ]);
    }
    // Admins also need pending (not-yet-signed-up) league members in the picker
    // when a league is selected, so they can invite the same not-yet-registered
    // people non-admins can.
    if ($league_id > 0) {
        $q = $db->prepare(
            "SELECT COALESCE(NULLIF(LOWER(contact_email), ''),
                             NULLIF(contact_phone, ''),
                             'pending:' || id) AS username,
                    contact_email AS email,
                    contact_phone AS phone,
                    contact_name  AS display_name,
                    1             AS is_pending,
                    1             AS is_league_member
             FROM league_members
             WHERE league_id = ? AND user_id IS NULL
             ORDER BY LOWER(contact_name)"
        );
        $q->execute([$league_id]);
        foreach ($q->fetchAll() as $r) { _add_seen($users, $seen, $r); }
        usort($users, function($a, $b) { return strcasecmp($a['display_name'] ?? '', $b['display_name'] ?? ''); });
    }
    echo json_encode(['ok' => true, 'users' => $users]);
    exit;
}

// The current user should always appear in their own picker so they can invite themselves.
_add_seen($users, $seen, [
    'username'         => $current['username'],
    'email'            => $current['email']    ?? '',
    'phone'            => $current['phone']    ?? '',
    'display_name'     => $current['username'] . ' (you)',
    'is_pending'       => 0,
    'is_league_member' => ($league_id > 0 && isset($leagueMemberIds[$uid])) ? 1 : 0,
]);

// ── Personal contacts (always included for non-admin) ──────────────────
$pc = $db->prepare(
    "SELECT COALESCE(u.username,
                     NULLIF(LOWER(c.contact_email), ''),
                     NULLIF(c.contact_phone, ''),
                     'contact:' || c.id) AS username,
            c.contact_email AS email,
            c.contact_phone AS phone,
            c.contact_name  AS display_name,
            CASE WHEN c.linked_user_id IS NULL THEN 1 ELSE 0 END AS is_pending,
            c.linked_user_id AS _linked_uid
     FROM user_contacts c
     LEFT JOIN users u ON u.id = c.linked_user_id
     WHERE c.owner_user_id = ?
     ORDER BY LOWER(c.contact_name)"
);
$pc->execute([$uid]);
$personal = $pc->fetchAll();
foreach ($personal as &$_pcRow) {
    $_pcRow['is_league_member'] = ($league_id > 0
        && !empty($_pcRow['_linked_uid'])
        && isset($leagueMemberIds[(int)$_pcRow['_linked_uid']])) ? 1 : 0;
    unset($_pcRow['_linked_uid']);
}
unset($_pcRow);

if ($league_id > 0) {
    // Must be a member of this league to see its roster.
    $role = league_role($league_id, $uid);
    if ($role === null) {
        echo json_encode(['ok' => false, 'error' => 'Not a member of that league.']);
        exit;
    }

    // Linked members of the league
    $q1 = $db->prepare(
        "SELECT u.username, u.email, u.phone, u.username AS display_name,
                0 AS is_pending, 1 AS is_league_member
         FROM league_members lm
         JOIN users u ON u.id = lm.user_id
         WHERE lm.league_id = ? AND u.id <> ?
         ORDER BY LOWER(u.username)"
    );
    $q1->execute([$league_id, $uid]);
    foreach ($q1->fetchAll() as $r) { _add_seen($users, $seen, $r); }

    // Pending league contacts (not yet signed up). Some pending invitees were added
    // with phone only (no email), so contact_email may be NULL — fall back to phone,
    // then to a synthetic key from the row id, so they still appear in the picker.
    $q2 = $db->prepare(
        "SELECT COALESCE(NULLIF(LOWER(contact_email), ''),
                         NULLIF(contact_phone, ''),
                         'pending:' || id) AS username,
                contact_email AS email,
                contact_phone AS phone,
                contact_name  AS display_name,
                1             AS is_pending,
                1             AS is_league_member
         FROM league_members
         WHERE league_id = ? AND user_id IS NULL
         ORDER BY LOWER(contact_name)"
    );
    $q2->execute([$league_id]);
    foreach ($q2->fetchAll() as $r) { _add_seen($users, $seen, $r); }

    // Merge personal contacts (deduped by username-key)
    foreach ($personal as $r) { _add_seen($users, $seen, $r); }

    usort($users, function($a, $b) { return strcasecmp($a['display_name'] ?? '', $b['display_name'] ?? ''); });
    echo json_encode(['ok' => true, 'users' => $users]);
    exit;
}

// No league picked → personal contacts only.
foreach ($personal as $r) { _add_seen($users, $seen, $r); }
usort($users, function($a, $b) { return strcasecmp($a['display_name'] ?? '', $b['display_name'] ?? ''); });
echo json_encode(['ok' => true, 'users' => $users]);
