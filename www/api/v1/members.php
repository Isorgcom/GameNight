<?php
/**
 * GET /api/v1/members
 *
 * Returns the roster for the league bound to the API key. Display name + role
 * + joined_at + a `pending` boolean for invitees who haven't created accounts
 * yet. By design, the SELECT does NOT pull email or phone columns — there is
 * no code path here that could accidentally leak contact info even if the
 * shape of the response were changed later.
 *
 * Sort order matches the league page UI: owners → managers → members,
 * accounts before pending contacts, then alphabetical.
 */

require_once __DIR__ . '/../_auth.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    api_log_request(null, 405);
    api_fail('Method not allowed', 405);
}

$key = api_authenticate();
$db  = get_db();
$lid = (int)$key['league_id'];

$stmt = $db->prepare(
    "SELECT lm.role,
            lm.joined_at,
            lm.user_id,
            COALESCE(u.username, lm.contact_name) AS display_name
     FROM league_members lm
     LEFT JOIN users u ON u.id = lm.user_id
     WHERE lm.league_id = ?
     ORDER BY CASE lm.role WHEN 'owner' THEN 0 WHEN 'manager' THEN 1 ELSE 2 END,
              CASE WHEN lm.user_id IS NULL THEN 1 ELSE 0 END,
              LOWER(COALESCE(u.username, lm.contact_name))"
);
$stmt->execute([$lid]);

$members = [];
foreach ($stmt->fetchAll() as $r) {
    $name = trim((string)($r['display_name'] ?? ''));
    if ($name === '') continue; // skip orphaned rows with no usable label
    $members[] = [
        'display_name' => $name,
        'role'         => (string)$r['role'],
        'pending'      => $r['user_id'] === null,
        'joined_at'    => (string)($r['joined_at'] ?? ''),
    ];
}

api_log_request((int)$key['id'], 200);
api_ok($members);
