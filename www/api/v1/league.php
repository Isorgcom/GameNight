<?php
/**
 * GET /api/v1/league
 *
 * Returns the basic public profile of the single league bound to the API key:
 * id, name, description, member_count, created_at. Internal fields like
 * is_hidden, invite_code, owner_id are deliberately omitted — the API key
 * is the proof of authorization, the consumer never needs them.
 */

require_once __DIR__ . '/../_auth.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    api_log_request(null, 405);
    api_fail('Method not allowed', 405);
}

$key = api_authenticate();
$db  = get_db();
$lid = (int)$key['league_id'];

$stmt = $db->prepare('SELECT id, name, description, created_at FROM leagues WHERE id = ?');
$stmt->execute([$lid]);
$league = $stmt->fetch();

if (!$league) {
    // Key references a league that has been deleted. Treat as 404, not 500.
    api_log_request((int)$key['id'], 404);
    api_fail('League not found', 404);
}

// Member count includes both real users and pending contacts (the same way
// the league page renders "X members"). Excludes nothing — admins typically
// want the total roster count exposed.
$mc = $db->prepare('SELECT COUNT(*) FROM league_members WHERE league_id = ?');
$mc->execute([$lid]);
$member_count = (int)$mc->fetchColumn();

api_log_request((int)$key['id'], 200);
api_ok([
    'id'           => (int)$league['id'],
    'name'         => (string)$league['name'],
    'description'  => (string)($league['description'] ?? ''),
    'member_count' => $member_count,
    'created_at'   => (string)$league['created_at'],
]);
