<?php
/**
 * League-scoped API key write endpoint. Mirrors league_posts_dl.php's conventions:
 * POST-only, CSRF-verified, role-checked. Owners (and site admins) of a league
 * may mint and revoke API keys for that one league. Managers cannot — issuing
 * a key exposes the roster to an external system, which is an owner-level
 * decision.
 *
 * Actions:
 *   create — owner/admin: mint a new 64-char hex key, return plaintext exactly once
 *   revoke — owner/admin: soft-revoke (sets revoked_at) so audit logs survive
 *
 * Plaintext is returned in the JSON response (and via a flash message on the
 * redirect path) so the caller can display it once and never store it.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';

$current = require_login();
$db      = get_db();
$uid     = (int)$current['id'];
$isAdmin = ($current['role'] ?? '') === 'admin';

$__redirect = trim((string)($_POST['redirect'] ?? ''));
$__is_json  = ($__redirect === '');
if ($__is_json) header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    if ($__is_json) echo json_encode(['ok' => false, 'error' => 'POST required']);
    else { header('Location: ' . $__redirect); }
    exit;
}
if (!csrf_verify()) {
    http_response_code(403);
    if ($__is_json) echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
    else { header('Location: ' . $__redirect); }
    exit;
}

function ak_fail(string $msg, int $code = 400): void {
    global $__is_json, $__redirect;
    http_response_code($code);
    if ($__is_json) echo json_encode(['ok' => false, 'error' => $msg]);
    else {
        $_SESSION['flash'] = ['type' => 'error', 'msg' => $msg];
        header('Location: ' . $__redirect);
    }
    exit;
}
function ak_ok(array $extra = []): void {
    global $__is_json, $__redirect;
    if ($__is_json) echo json_encode(array_merge(['ok' => true], $extra));
    else { header('Location: ' . $__redirect); }
    exit;
}

/** Owner-only (and site admins). Managers cannot mint keys for their league. */
function user_can_manage_league_api_keys(PDO $db, int $league_id, int $uid, bool $is_admin): bool {
    if ($is_admin) return true;
    return league_role($league_id, $uid) === 'owner';
}

$action = $_POST['action'] ?? '';

switch ($action) {

    case 'create': {
        $league_id = (int)($_POST['league_id'] ?? 0);
        $label     = trim((string)($_POST['label'] ?? ''));
        if ($league_id <= 0) ak_fail('league_id required');
        if ($label === '')    ak_fail('Label required');
        if (!user_can_manage_league_api_keys($db, $league_id, $uid, $isAdmin)) ak_fail('Not allowed', 403);

        // Confirm league exists.
        $chk = $db->prepare('SELECT 1 FROM leagues WHERE id = ?');
        $chk->execute([$league_id]);
        if (!$chk->fetchColumn()) ak_fail('League not found', 404);

        $plain = bin2hex(random_bytes(32));
        $hash  = hash('sha256', strtolower($plain));
        $db->prepare('INSERT INTO api_keys (key_hash, label, league_id) VALUES (?, ?, ?)')
           ->execute([$hash, $label, $league_id]);
        $kid = (int)$db->lastInsertId();
        db_log_activity($uid, "minted API key id=$kid league=$league_id label=\"$label\"");

        // For redirect callers: stash the plaintext in flash so the league page can show
        // it once. For JSON callers: just return it in the response.
        if (!$__is_json) {
            $_SESSION['flash'] = [
                'type'      => 'created',
                'msg'       => 'API key created. Copy it now — you will not see it again.',
                'plaintext' => $plain,
                'label'     => $label,
                'key_id'    => $kid,
            ];
        }
        ak_ok(['key_id' => $kid, 'plaintext' => $plain, 'label' => $label]);
    }

    case 'revoke': {
        $key_id = (int)($_POST['key_id'] ?? 0);
        if ($key_id <= 0) ak_fail('key_id required');

        $stmt = $db->prepare('SELECT id, league_id, revoked_at FROM api_keys WHERE id = ?');
        $stmt->execute([$key_id]);
        $row = $stmt->fetch();
        if (!$row) ak_fail('Key not found', 404);
        if ($row['revoked_at']) ak_fail('Already revoked', 400);

        if (!user_can_manage_league_api_keys($db, (int)$row['league_id'], $uid, $isAdmin)) {
            ak_fail('Not allowed', 403);
        }

        $db->prepare('UPDATE api_keys SET revoked_at = CURRENT_TIMESTAMP WHERE id = ?')
           ->execute([$key_id]);
        db_log_activity($uid, "revoked API key id=$key_id league=" . (int)$row['league_id']);

        if (!$__is_json) {
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'API key revoked.'];
        }
        ak_ok();
    }

    default:
        ak_fail('Unknown action', 400);
}
