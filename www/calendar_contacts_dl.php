<?php
/**
 * Returns the list of "invite suggestions" for the event editor.
 *
 *   GET /calendar_contacts_dl.php?league_id=0    → user's personal contacts only
 *   GET /calendar_contacts_dl.php?league_id=N    → league roster MERGED with user's personal contacts
 *
 * Admins always get the full user list regardless of league_id.
 * Response: {ok: true, users: [{username, email, phone, display_name, is_pending, is_league_member, pref}]}
 * `pref` is how that person is notified (email / sms / whatsapp / both / none),
 * or '' for the default rule; the editor lights the matching contact glyph.
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

// The signed-in user's own contacts decide a typed-in guest's channel (the
// way guest_invite_channel() reads them at send time); pending league rows
// below look their name up here.
$hostPrefs = host_contact_prefs($db, $uid);
$prefOf = function (array $r) use ($hostPrefs): string {
    return $hostPrefs[strtolower(trim((string)($r['display_name'] ?? '')))] ?? '';
};

if ($isAdmin) {
    $rows = $db->query('SELECT id, username, email, phone, preferred_contact FROM users ORDER BY LOWER(username)')->fetchAll();
    foreach ($rows as $r) {
        _add_seen($users, $seen, [
            'username'         => $r['username'],
            'email'            => $r['email'] ?? '',
            'phone'            => $r['phone'] ?? '',
            'display_name'     => $r['username'],
            'is_pending'       => 0,
            'is_league_member' => isset($leagueMemberIds[(int)$r['id']]) ? 1 : 0,
            'pref'             => strtolower((string)($r['preferred_contact'] ?? 'email')),
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
        foreach ($q->fetchAll() as $r) { $r['pref'] = $prefOf($r); _add_seen($users, $seen, $r); }
        usort($users, function($a, $b) { return strcasecmp($a['display_name'] ?? '', $b['display_name'] ?? ''); });
    }
    echo json_encode(['ok' => true, 'users' => $users]);
    exit;
}

// The current user should always appear in their own picker so they can invite
// themselves. current_user() carries no phone or contact preference, so read them.
$meRow = $db->prepare('SELECT phone, preferred_contact FROM users WHERE id = ?');
$meRow->execute([$uid]);
$me = $meRow->fetch() ?: [];
_add_seen($users, $seen, [
    'username'         => $current['username'],
    'email'            => $current['email']    ?? '',
    'phone'            => $me['phone'] ?? '',
    'display_name'     => $current['username'] . ' (you)',
    'is_pending'       => 0,
    'is_league_member' => ($league_id > 0 && isset($leagueMemberIds[$uid])) ? 1 : 0,
    'pref'             => strtolower((string)($me['preferred_contact'] ?? 'email')),
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
            LOWER(COALESCE(NULLIF(u.preferred_contact, ''), CASE WHEN c.invite_via = 'sms' THEN 'sms' ELSE 'email' END)) AS pref,
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
    // Must be a member of this league to see its roster. Contact details are
    // for the league's owner and managers: a plain member gets names only
    // (_event_save.php fills in email/phone from the account when the picker
    // sends none) and never the typed-in details of pending contacts.
    $role = league_role($league_id, $uid);
    if ($role === null) {
        echo json_encode(['ok' => false, 'error' => 'Not a member of that league.']);
        exit;
    }
    $canManage = in_array($role, ['owner', 'manager'], true);

    // Linked members of the league
    $q1 = $db->prepare(
        "SELECT u.username, " . ($canManage ? "u.email, u.phone, LOWER(u.preferred_contact) AS pref" : "'' AS email, '' AS phone, '' AS pref") . ", u.username AS display_name, lm.known_as,
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
    if ($canManage) {
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
    foreach ($q2->fetchAll() as $r) { $r['pref'] = $prefOf($r); _add_seen($users, $seen, $r); }
    }

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
