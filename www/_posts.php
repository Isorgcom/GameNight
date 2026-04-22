<?php
/**
 * Shared helpers for the posts feed (global admin posts + league-scoped posts).
 *
 * Visibility rules (kept in one place so every feed query stays in sync):
 *   - league_id IS NULL  → global admin post, visible to everyone
 *   - league_id set      → only visible to members of that league (and site admins)
 *   - is_rules_post = 1  → excluded from feeds (only reachable via the league rules button)
 *   - hidden = 1         → excluded
 *   - created_at > NOW   → excluded (scheduled in the future)
 */

require_once __DIR__ . '/db.php';

/**
 * Build the WHERE fragment and params list for "posts this user is allowed to see in the feed."
 *
 * Callers typically prefix with their own extra filters, eg:
 *   $vis = posts_feed_sql_for_user($uid, $isAdmin);
 *   $db->prepare("SELECT … FROM posts p WHERE {$vis['sql']} ORDER BY …");
 *   $db->execute($vis['params']);
 *
 * @return array{sql:string, params:array}
 */
function posts_feed_sql_for_user(?int $user_id, bool $is_admin): array {
    $now = (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    $params = [$now];

    // Always: not hidden, not a rules post, not future-scheduled.
    $common = "p.hidden = 0 AND p.is_rules_post = 0 AND p.created_at <= ?";

    if ($is_admin) {
        // Admins see every post that passes the common filter.
        return ['sql' => $common, 'params' => $params];
    }

    // Global admin posts are visible to anyone (including signed-out visitors).
    if ($user_id === null) {
        return ['sql' => "$common AND p.league_id IS NULL", 'params' => $params];
    }

    // Logged-in, non-admin: global posts OR posts from leagues they belong to.
    $leagues = user_leagues($user_id);
    $ids = array_map(fn($l) => (int)$l['id'], $leagues);

    if (empty($ids)) {
        return ['sql' => "$common AND p.league_id IS NULL", 'params' => $params];
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $sql = "$common AND (p.league_id IS NULL OR p.league_id IN ($placeholders))";
    return ['sql' => $sql, 'params' => array_merge($params, $ids)];
}

/**
 * Single-row visibility check (used by comment.php and detail-view guards).
 * Expects a $post row with at least league_id, hidden, is_rules_post, created_at.
 * The is_rules_post row is still VIEWABLE by members — it's just hidden from feeds.
 */
function post_is_visible_to(PDO $db, array $post, ?int $user_id, bool $is_admin): bool {
    if ((int)($post['hidden'] ?? 0) === 1 && !$is_admin) return false;
    // Future-scheduled: not visible to readers (admins see it in admin_posts.php separately).
    if (!empty($post['created_at'])) {
        $nowUtc = new DateTime('now', new DateTimeZone('UTC'));
        $created = new DateTime($post['created_at'], new DateTimeZone('UTC'));
        if ($created > $nowUtc && !$is_admin) return false;
    }
    $league_id = isset($post['league_id']) ? (int)$post['league_id'] : 0;
    if ($league_id === 0) return true;                 // global
    if ($is_admin) return true;
    if ($user_id === null) return false;
    return league_role($league_id, $user_id) !== null;
}

/**
 * True if this user may author / edit / delete posts on behalf of a league.
 * Admins always yes; league owners and managers may author.
 */
function user_can_author_league_post(PDO $db, int $league_id, int $user_id, bool $is_admin): bool {
    if ($is_admin) return true;
    return in_array(league_role($league_id, $user_id), ['owner', 'manager'], true);
}

/**
 * True if this user may edit/delete a specific post. Rules:
 *   - Site admins: always yes.
 *   - League posts: original author, OR current owner/manager of that league.
 *   - Global (admin) posts: admins only (handled above).
 */
function user_can_edit_post(PDO $db, array $post, int $user_id, bool $is_admin): bool {
    if ($is_admin) return true;
    $league_id = isset($post['league_id']) ? (int)$post['league_id'] : 0;
    if ($league_id === 0) return false;                // global admin post
    $author_id = isset($post['author_id']) ? (int)$post['author_id'] : 0;
    if ($author_id === $user_id && $author_id > 0) return true;
    return in_array(league_role($league_id, $user_id), ['owner', 'manager'], true);
}
