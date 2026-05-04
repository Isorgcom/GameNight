<?php
/**
 * /api/v1/pending-contacts/{member_id}
 *
 * PATCH  — edit contact_name / contact_email / contact_phone on a pending
 *          (user_id IS NULL) row in league_members. When email or phone
 *          changes, the invite_token is regenerated so the old invite link
 *          dies and the response carries the new token.
 * DELETE — hard-delete a pending row. Silent (no notification — there's
 *          no account to notify).
 *
 * Both endpoints are league-scoped via the API key, gated by the write
 * scope, and refuse to operate on registered (user_id IS NOT NULL) rows.
 * Use /api/v1/members/{user_id} for those.
 */

require_once __DIR__ . '/../_auth.php';
require_once __DIR__ . '/../../db.php';   // normalize_phone()

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'OPTIONS') {
    api_send_headers(0);
    http_response_code(204);
    exit;
}
if ($method === 'PATCH')  { handle_pending_contacts_patch();  exit; }
if ($method === 'DELETE') { handle_pending_contacts_delete(); exit; }

api_log_request(null, 405);
api_fail('Method not allowed', 405);

// ─────────────────────────────────────────────────────────────────────────────
// PATCH /pending-contacts/{member_id}
// ─────────────────────────────────────────────────────────────────────────────
function handle_pending_contacts_patch(): void {
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
            AND path LIKE '%/api/v1/pending-contacts/%'
            AND created_at > datetime('now','-1 hour')"
    );
    $rl->execute([$key_id]);
    if ((int)$rl->fetchColumn() >= 60) {
        api_log_request($key_id, 429);
        api_fail('Rate limit exceeded: 60 pending-contact updates per hour per key', 429);
    }

    $member_id = (int)($_GET['id'] ?? 0);
    if ($member_id <= 0) {
        api_log_request($key_id, 404);
        api_fail('pending_contact_not_found', 404);
    }

    // Resolve. Cross-league lookups collapse to the same 404. Registered rows
    // get distinguished from missing rows so callers know to use the other
    // endpoint, since they'd see the row in GET /members.
    $rowStmt = $db->prepare(
        'SELECT id, user_id, contact_name, contact_email, contact_phone, invite_token
           FROM league_members WHERE id = ? AND league_id = ?'
    );
    $rowStmt->execute([$member_id, $league_id]);
    $row = $rowStmt->fetch();
    if (!$row) {
        api_log_request($key_id, 404);
        api_fail('pending_contact_not_found', 404);
    }
    if ($row['user_id'] !== null) {
        api_log_request($key_id, 400);
        api_fail('not_a_pending_contact', 400);
    }

    $raw  = file_get_contents('php://input');
    $body = json_decode($raw ?: '', true);
    if (!is_array($body) || empty($body)) {
        api_log_request($key_id, 400);
        api_fail('Request body must be a non-empty JSON object', 400);
    }
    $allowed = ['display_name', 'email', 'phone'];
    foreach (array_keys($body) as $k) {
        if (!in_array($k, $allowed, true)) {
            api_log_request($key_id, 400);
            api_fail("Unknown field: $k. Allowed: " . implode(', ', $allowed), 400);
        }
    }

    $updates = [];
    $fields_changed = [];

    if (array_key_exists('display_name', $body)) {
        $name = trim((string)$body['display_name']);
        if ($name === '') {
            api_log_request($key_id, 400);
            api_fail('display_name cannot be empty', 400);
        }
        if (mb_strlen($name) > 200) {
            api_log_request($key_id, 400);
            api_fail('display_name must be 200 characters or fewer', 400);
        }
        if ($name !== (string)($row['contact_name'] ?? '')) {
            $updates['contact_name'] = $name;
            $fields_changed[] = 'display_name';
        }
    }

    if (array_key_exists('email', $body)) {
        $email_raw = strtolower(trim((string)$body['email']));
        if ($email_raw !== '' && !filter_var($email_raw, FILTER_VALIDATE_EMAIL)) {
            api_log_request($key_id, 400);
            api_fail('email is not a valid address', 400);
        }
        $new_email = $email_raw === '' ? null : $email_raw;
        if ($new_email !== ($row['contact_email'] ?? null)) {
            $updates['contact_email'] = $new_email;
            $fields_changed[] = 'email';
        }
    }

    if (array_key_exists('phone', $body)) {
        $phone_raw = trim((string)$body['phone']);
        $new_phone = $phone_raw === '' ? null : normalize_phone($phone_raw);
        if ($new_phone !== ($row['contact_phone'] ?? null)) {
            $updates['contact_phone'] = $new_phone;
            $fields_changed[] = 'phone';
        }
    }

    // No-op edit: zero fields differ. Return idempotent success WITHOUT touching
    // the token (regenerating on a no-op would silently kill working invite links).
    if (empty($updates)) {
        api_log_request($key_id, 200);
        api_ok([
            'member_id'         => $member_id,
            'fields_changed'    => [],
            'token_regenerated' => false,
        ], 0);
    }

    // Identifier guard: must keep at least email or phone.
    $effective_email = array_key_exists('contact_email', $updates) ? $updates['contact_email'] : ($row['contact_email'] ?? null);
    $effective_phone = array_key_exists('contact_phone', $updates) ? $updates['contact_phone'] : ($row['contact_phone'] ?? null);
    if ($effective_email === null && $effective_phone === null) {
        api_log_request($key_id, 400);
        api_fail('must_keep_email_or_phone', 400);
    }

    // Email uniqueness within league (pending rows only). Mirrors the in-app
    // pre-write check; the partial unique index would reject the UPDATE anyway,
    // but we want a clean error code rather than a 500.
    if (array_key_exists('contact_email', $updates) && $updates['contact_email'] !== null) {
        $dup = $db->prepare(
            "SELECT 1 FROM league_members
              WHERE league_id = ?
                AND user_id IS NULL
                AND LOWER(contact_email) = LOWER(?)
                AND id <> ?"
        );
        $dup->execute([$league_id, $updates['contact_email'], $member_id]);
        if ($dup->fetchColumn()) {
            api_log_request($key_id, 400);
            api_fail('email_already_pending', 400);
        }
    }

    // Token regenerates when email or phone moves. Old invite link dies.
    $token_regenerated = false;
    $new_token = null;
    if (in_array('email', $fields_changed, true) || in_array('phone', $fields_changed, true)) {
        $new_token = bin2hex(random_bytes(16));
        $updates['invite_token'] = $new_token;
        $token_regenerated = true;
    }

    try {
        $db->beginTransaction();
        $sets = [];
        $args = [];
        foreach ($updates as $col => $val) { $sets[] = "$col = ?"; $args[] = $val; }
        $args[] = $member_id;
        $db->prepare('UPDATE league_members SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($args);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        api_log_request($key_id, 500);
        api_fail('Failed to update pending contact', 500);
    }

    db_log_anon_activity("api_pending_contact_patch: member_id=$member_id league=$league_id changed=" . implode(',', $fields_changed) . " via key=$key_id");

    $resp = [
        'member_id'         => $member_id,
        'fields_changed'    => $fields_changed,
        'token_regenerated' => $token_regenerated,
    ];
    if ($token_regenerated) {
        $resp['invite_token'] = $new_token;
    }

    api_log_request($key_id, 200);
    api_ok($resp, 0);
}

// ─────────────────────────────────────────────────────────────────────────────
// DELETE /pending-contacts/{member_id}
// ─────────────────────────────────────────────────────────────────────────────
function handle_pending_contacts_delete(): void {
    $key = api_authenticate();
    api_require_scope($key, 'write');

    $db        = get_db();
    $key_id    = (int)$key['id'];
    $league_id = (int)$key['league_id'];

    $rl = $db->prepare(
        "SELECT COUNT(*) FROM api_request_log
          WHERE key_id = ?
            AND status = 200
            AND method = 'DELETE'
            AND path LIKE '%/api/v1/pending-contacts/%'
            AND created_at > datetime('now','-1 hour')"
    );
    $rl->execute([$key_id]);
    if ((int)$rl->fetchColumn() >= 60) {
        api_log_request($key_id, 429);
        api_fail('Rate limit exceeded: 60 pending-contact deletions per hour per key', 429);
    }

    $member_id = (int)($_GET['id'] ?? 0);
    if ($member_id <= 0) {
        api_log_request($key_id, 404);
        api_fail('pending_contact_not_found', 404);
    }

    // Hard delete with the user_id IS NULL filter baked in. Three protections
    // in one query: not-found / wrong-league / registered-row all collapse to
    // 404 without distinguishing between them.
    $del = $db->prepare(
        'DELETE FROM league_members
          WHERE id = ? AND league_id = ? AND user_id IS NULL'
    );
    $del->execute([$member_id, $league_id]);
    if ($del->rowCount() === 0) {
        api_log_request($key_id, 404);
        api_fail('pending_contact_not_found', 404);
    }

    db_log_anon_activity("api_pending_contact_deleted: member_id=$member_id league=$league_id via key=$key_id");

    api_log_request($key_id, 200);
    api_ok([
        'member_id' => $member_id,
        'deleted'   => true,
    ], 0);
}
