<?php
/**
 * League-scoped posts write endpoint. Mirrors leagues_dl.php's conventions:
 *   POST-only, CSRF-verified, JSON responses, role-checked per action.
 *
 * Actions:
 *   create        — owner/manager/admin: insert a post with league_id + author_id
 *   update        — author / owner / manager / admin: update title + content
 *   delete        — author / owner / manager / admin: hard delete + cascade comments
 *   set_rules     — owner/manager/admin: mark one post as the league's rules post
 *   clear_rules   — owner/manager/admin: unset the rules flag
 *   toggle_pin    — owner/manager/admin: pin/unpin a league post
 *   toggle_hide   — owner/manager/admin: hide/unhide
 *   share_enable  — owner/manager/admin: mint share_token if absent (idempotent)
 *   share_disable — owner/manager/admin: clear share_token (kills public link)
 *   share_regen   — owner/manager/admin: rotate share_token (invalidates old link)
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/_posts.php';
require_once __DIR__ . '/sms.php'; // shorten_url()

$current = require_login();
$db      = get_db();
$uid     = (int)$current['id'];
$isAdmin = ($current['role'] ?? '') === 'admin';

// Two response modes: redirect-based HTML forms (send &redirect=/foo),
// or JSON for fetch() callers. Everything below decides via $__redirect.
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

$action = $_POST['action'] ?? '';

function pfail(string $msg, int $code = 400): void {
    global $__is_json, $__redirect;
    http_response_code($code);
    if ($__is_json) echo json_encode(['ok' => false, 'error' => $msg]);
    else {
        $_SESSION['flash'] = ['type' => 'error', 'msg' => $msg];
        header('Location: ' . $__redirect);
    }
    exit;
}
function pok(array $extra = []): void {
    global $__is_json, $__redirect;
    if ($__is_json) echo json_encode(array_merge(['ok' => true], $extra));
    else { header('Location: ' . $__redirect); }
    exit;
}

/** Load a post row or fail. */
function load_post(PDO $db, int $post_id): array {
    $stmt = $db->prepare('SELECT * FROM posts WHERE id = ?');
    $stmt->execute([$post_id]);
    $p = $stmt->fetch();
    if (!$p) pfail('Post not found', 404);
    return $p;
}

switch ($action) {

    case 'create': {
        $league_id = (int)($_POST['league_id'] ?? 0);
        $title     = trim($_POST['title'] ?? '');
        $content   = $_POST['content'] ?? '';
        if ($league_id <= 0) pfail('league_id required');
        if ($title === '')    pfail('Title required');
        if (!user_can_author_league_post($db, $league_id, $uid, $isAdmin)) pfail('Not allowed', 403);

        $clean = sanitize_html($content);
        $db->prepare('INSERT INTO posts (title, content, league_id, author_id, pinned, hidden, is_rules_post, created_at)
                      VALUES (?, ?, ?, ?, 0, 0, 0, CURRENT_TIMESTAMP)')
           ->execute([$title, $clean, $league_id, $uid]);
        $pid = (int)$db->lastInsertId();
        db_log_activity($uid, "created league post id=$pid league=$league_id");
        pok(['post_id' => $pid]);
    }

    case 'update': {
        $post_id = (int)($_POST['post_id'] ?? 0);
        $title   = trim($_POST['title'] ?? '');
        $content = $_POST['content'] ?? '';
        if ($post_id <= 0) pfail('post_id required');
        if ($title === '')  pfail('Title required');
        $p = load_post($db, $post_id);
        if ((int)($p['league_id'] ?? 0) === 0) pfail('Not a league post', 400);
        if (!user_can_edit_post($db, $p, $uid, $isAdmin)) pfail('Not allowed', 403);

        $clean = sanitize_html($content);
        $db->prepare('UPDATE posts SET title = ?, content = ? WHERE id = ?')
           ->execute([$title, $clean, $post_id]);
        db_log_activity($uid, "edited league post id=$post_id");
        pok();
    }

    case 'delete': {
        $post_id = (int)($_POST['post_id'] ?? 0);
        if ($post_id <= 0) pfail('post_id required');
        $p = load_post($db, $post_id);
        if ((int)($p['league_id'] ?? 0) === 0) pfail('Not a league post', 400);
        if (!user_can_edit_post($db, $p, $uid, $isAdmin)) pfail('Not allowed', 403);

        $db->prepare("DELETE FROM comments WHERE type = 'post' AND content_id = ?")->execute([$post_id]);
        $db->prepare('DELETE FROM posts WHERE id = ?')->execute([$post_id]);
        db_log_activity($uid, "deleted league post id=$post_id");
        pok();
    }

    case 'set_rules': {
        $post_id = (int)($_POST['post_id'] ?? 0);
        if ($post_id <= 0) pfail('post_id required');
        $p = load_post($db, $post_id);
        $league_id = (int)($p['league_id'] ?? 0);
        if ($league_id === 0) pfail('Not a league post', 400);
        if (!user_can_author_league_post($db, $league_id, $uid, $isAdmin)) pfail('Not allowed', 403);

        // Partial unique index would reject a second rules row; clear the old one first.
        $db->prepare('UPDATE posts SET is_rules_post = 0 WHERE league_id = ? AND is_rules_post = 1 AND id <> ?')
           ->execute([$league_id, $post_id]);
        $db->prepare('UPDATE posts SET is_rules_post = 1 WHERE id = ?')->execute([$post_id]);
        db_log_activity($uid, "set rules post id=$post_id league=$league_id");
        pok();
    }

    case 'clear_rules': {
        $post_id = (int)($_POST['post_id'] ?? 0);
        if ($post_id <= 0) pfail('post_id required');
        $p = load_post($db, $post_id);
        $league_id = (int)($p['league_id'] ?? 0);
        if ($league_id === 0) pfail('Not a league post', 400);
        if (!user_can_author_league_post($db, $league_id, $uid, $isAdmin)) pfail('Not allowed', 403);

        $db->prepare('UPDATE posts SET is_rules_post = 0 WHERE id = ?')->execute([$post_id]);
        db_log_activity($uid, "cleared rules flag post id=$post_id");
        pok();
    }

    case 'toggle_pin': {
        $post_id = (int)($_POST['post_id'] ?? 0);
        if ($post_id <= 0) pfail('post_id required');
        $p = load_post($db, $post_id);
        $league_id = (int)($p['league_id'] ?? 0);
        if ($league_id === 0) pfail('Not a league post', 400);
        if (!user_can_author_league_post($db, $league_id, $uid, $isAdmin)) pfail('Not allowed', 403);

        $new = (int)($p['pinned'] ?? 0) === 1 ? 0 : 1;
        $db->prepare('UPDATE posts SET pinned = ? WHERE id = ?')->execute([$new, $post_id]);
        pok(['pinned' => $new]);
    }

    case 'toggle_hide': {
        $post_id = (int)($_POST['post_id'] ?? 0);
        if ($post_id <= 0) pfail('post_id required');
        $p = load_post($db, $post_id);
        $league_id = (int)($p['league_id'] ?? 0);
        if ($league_id === 0) pfail('Not a league post', 400);
        if (!user_can_author_league_post($db, $league_id, $uid, $isAdmin)) pfail('Not allowed', 403);

        $new = (int)($p['hidden'] ?? 0) === 1 ? 0 : 1;
        $db->prepare('UPDATE posts SET hidden = ? WHERE id = ?')->execute([$new, $post_id]);
        pok(['hidden' => $new]);
    }

    case 'share_enable':
    case 'share_regen': {
        $post_id = (int)($_POST['post_id'] ?? 0);
        if ($post_id <= 0) pfail('post_id required');
        $p = load_post($db, $post_id);
        $league_id = (int)($p['league_id'] ?? 0);
        if ($league_id === 0) pfail('Not a league post', 400);
        if (!user_can_author_league_post($db, $league_id, $uid, $isAdmin)) pfail('Not allowed', 403);

        $existing = (string)($p['share_token'] ?? '');
        // share_enable is idempotent (keep token if present); share_regen always rotates.
        if ($action === 'share_enable' && $existing !== '') {
            $token = $existing;
        } else {
            $token = bin2hex(random_bytes(16));
            $db->prepare('UPDATE posts SET share_token = ? WHERE id = ?')->execute([$token, $post_id]);
            db_log_activity($uid, ($action === 'share_regen' ? 'regenerated' : 'enabled')
                . " share link post id=$post_id league=$league_id");
        }
        $share_url = get_site_url() . '/post_public.php?token=' . $token;
        if (get_setting('url_shortener_enabled') === '1') {
            $share_url = shorten_url($share_url);
        }
        pok(['share_token' => $token, 'share_url' => $share_url]);
    }

    case 'share_disable': {
        $post_id = (int)($_POST['post_id'] ?? 0);
        if ($post_id <= 0) pfail('post_id required');
        $p = load_post($db, $post_id);
        $league_id = (int)($p['league_id'] ?? 0);
        if ($league_id === 0) pfail('Not a league post', 400);
        if (!user_can_author_league_post($db, $league_id, $uid, $isAdmin)) pfail('Not allowed', 403);

        $db->prepare('UPDATE posts SET share_token = NULL WHERE id = ?')->execute([$post_id]);
        db_log_activity($uid, "disabled share link post id=$post_id league=$league_id");
        pok();
    }

    default:
        pfail('Unknown action', 400);
}
