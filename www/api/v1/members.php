<?php
/**
 * /api/v1/members
 *
 * GET   /members             — roster for the bound league. user_id is null for
 *                               pending contacts. PII (email/phone) is never
 *                               returned.
 * PATCH /members/{user_id}   — promote/demote a registered league member's
 *                               role (member ↔ manager). 'owner' is rejected
 *                               (privilege transfer is UI-only). Pending
 *                               contacts (user_id IS NULL) are not addressable.
 *
 * Sort order matches the league page UI: owners → managers → members, accounts
 * before pending contacts, then alphabetical.
 */

require_once __DIR__ . '/../_auth.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'OPTIONS') {
    api_send_headers(0);
    http_response_code(204);
    exit;
}
if ($method === 'PATCH') {
    handle_members_patch();
    exit;
}
if ($method !== 'GET') {
    api_log_request(null, 405);
    api_fail('Method not allowed', 405);
}

// GET /members/{user_id} → single-member fetch
$single_id = (int)($_GET['id'] ?? 0);
if ($single_id > 0) {
    handle_members_get_one($single_id);
    exit;
}

// ── GET list ─────────────────────────────────────────────────────────────────
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
        'user_id'      => $r['user_id'] !== null ? (int)$r['user_id'] : null,
        'display_name' => $name,
        'role'         => (string)$r['role'],
        'pending'      => $r['user_id'] === null,
        'joined_at'    => (string)($r['joined_at'] ?? ''),
    ];
}

api_log_request((int)$key['id'], 200);
api_ok($members);

// ─────────────────────────────────────────────────────────────────────────────
// GET /members/{user_id} — single member by user_id
// ─────────────────────────────────────────────────────────────────────────────
function handle_members_get_one(int $user_id): void {
    $key = api_authenticate();
    $db  = get_db();
    $key_id = (int)$key['id'];
    $lid    = (int)$key['league_id'];

    $stmt = $db->prepare(
        "SELECT lm.role,
                lm.joined_at,
                lm.user_id,
                COALESCE(u.username, lm.contact_name) AS display_name
         FROM league_members lm
         LEFT JOIN users u ON u.id = lm.user_id
         WHERE lm.league_id = ? AND lm.user_id = ?"
    );
    $stmt->execute([$lid, $user_id]);
    $r = $stmt->fetch();
    if (!$r) {
        api_log_request($key_id, 404);
        api_fail('member_not_found', 404);
    }
    $name = trim((string)($r['display_name'] ?? ''));

    api_log_request($key_id, 200);
    api_ok([
        'user_id'      => (int)$r['user_id'],
        'display_name' => $name,
        'role'         => (string)$r['role'],
        'pending'      => false,
        'joined_at'    => (string)($r['joined_at'] ?? ''),
    ]);
}

// ─────────────────────────────────────────────────────────────────────────────
// PATCH /members/{user_id} — change a member's league role
// ─────────────────────────────────────────────────────────────────────────────
function handle_members_patch(): void {
    $key = api_authenticate();
    api_require_scope($key, 'write');

    $db        = get_db();
    $key_id    = (int)$key['id'];
    $league_id = (int)$key['league_id'];

    $rl = $db->prepare(
        "SELECT COUNT(*) FROM api_request_log
          WHERE key_id = ?
            AND status = 200
            AND method = 'PATCH'
            AND path LIKE '%/api/v1/members/%'
            AND created_at > datetime('now','-1 hour')"
    );
    $rl->execute([$key_id]);
    if ((int)$rl->fetchColumn() >= 60) {
        api_log_request($key_id, 429);
        api_fail('Rate limit exceeded: 60 member updates per hour per key', 429);
    }

    $user_id = (int)($_GET['id'] ?? 0);
    if ($user_id <= 0) {
        api_log_request($key_id, 404);
        api_fail('member_not_found', 404);
    }

    // Locate the member row. user_id IS NULL pending-contact rows aren't
    // reachable here because the caller addressed by user_id.
    $memStmt = $db->prepare(
        'SELECT id, role FROM league_members WHERE league_id = ? AND user_id = ?'
    );
    $memStmt->execute([$league_id, $user_id]);
    $member = $memStmt->fetch();
    if (!$member) {
        api_log_request($key_id, 404);
        api_fail('member_not_found', 404);
    }

    $raw  = file_get_contents('php://input');
    $body = json_decode($raw ?: '', true);
    if (!is_array($body) || empty($body)) {
        api_log_request($key_id, 400);
        api_fail('Request body must be a non-empty JSON object', 400);
    }
    $allowed_keys = ['league_role'];
    foreach (array_keys($body) as $k) {
        if (!in_array($k, $allowed_keys, true)) {
            api_log_request($key_id, 400);
            api_fail("Unknown field: $k. Allowed: " . implode(', ', $allowed_keys), 400);
        }
    }
    if (!array_key_exists('league_role', $body)) {
        api_log_request($key_id, 400);
        api_fail('league_role is required', 400);
    }

    $new_role = (string)$body['league_role'];
    if ($new_role === 'owner') {
        // Privilege escalation guard. Owner transfer is UI-only.
        api_log_request($key_id, 400);
        api_fail('cannot_set_owner_via_api', 400);
    }
    if (!in_array($new_role, ['member', 'manager'], true)) {
        api_log_request($key_id, 400);
        api_fail("league_role must be 'member' or 'manager'", 400);
    }

    $current_role = (string)$member['role'];
    if ($current_role === 'owner') {
        // Don't allow demoting owners through this endpoint — there's a
        // dedicated transfer_ownership flow in the UI for that.
        api_log_request($key_id, 400);
        api_fail('cannot_demote_owner', 400);
    }

    if ($current_role === $new_role) {
        // Idempotent: no work to do, no DB write.
        api_log_request($key_id, 200);
        api_ok([
            'league_id'    => $league_id,
            'user_id'      => $user_id,
            'league_role'  => $new_role,
            'role_changed' => false,
        ], 0);
    }

    try {
        $db->beginTransaction();
        $db->prepare('UPDATE league_members SET role = ? WHERE league_id = ? AND user_id = ?')
           ->execute([$new_role, $league_id, $user_id]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        api_log_request($key_id, 500);
        api_fail('Failed to update member role', 500);
    }

    db_log_anon_activity("api_member_role: user=$user_id league=$league_id changed $current_role -> $new_role via key=$key_id");

    api_log_request($key_id, 200);
    api_ok([
        'league_id'    => $league_id,
        'user_id'      => $user_id,
        'league_role'  => $new_role,
        'role_changed' => true,
    ], 0);
}
