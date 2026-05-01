<?php
/**
 * /api/v1/posts
 *
 * GET     /posts          — list posts for the bound league (paginated). Excludes
 *                            the rules post (use /rules), hidden posts, and any
 *                            post scheduled in the future.
 * GET     /posts/{id}     — fetch one post by id. Same visibility filters as
 *                            the list — anything filtered out returns 404.
 *
 * content_html is sanitized HTML; share_url is included only when the post has
 * a public share token, so sister sites can deep-link to the public viewer.
 */

require_once __DIR__ . '/../_auth.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'OPTIONS') {
    api_send_headers(0);
    http_response_code(204);
    exit;
}
if ($method !== 'GET') {
    api_log_request(null, 405);
    api_fail('Method not allowed', 405);
}

$post_id = (int)($_GET['id'] ?? 0);
if ($post_id > 0) {
    handle_posts_get_one($post_id);
    exit;
}

// ── List handler ─────────────────────────────────────────────────────────────
$key = api_authenticate();
$db  = get_db();
$lid = (int)$key['league_id'];

$limit  = (int)($_GET['limit']  ?? 20);
$offset = (int)($_GET['offset'] ?? 0);
if ($limit  < 1)   $limit  = 20;
if ($limit  > 50)  $limit  = 50;
if ($offset < 0)   $offset = 0;

$now_utc = (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

$stmt = $db->prepare(
    "SELECT p.id, p.title, p.content, p.created_at, p.share_token,
            COALESCE(u.username, '') AS author_display_name
     FROM posts p
     LEFT JOIN users u ON u.id = p.author_id
     WHERE p.league_id = ?
       AND p.is_rules_post = 0
       AND p.hidden = 0
       AND p.created_at <= ?
     ORDER BY p.pinned DESC, p.created_at DESC
     LIMIT ? OFFSET ?"
);
$stmt->execute([$lid, $now_utc, $limit, $offset]);

// Total count for pagination — same filters minus limit/offset.
$tc = $db->prepare(
    "SELECT COUNT(*) FROM posts
      WHERE league_id = ? AND is_rules_post = 0 AND hidden = 0 AND created_at <= ?"
);
$tc->execute([$lid, $now_utc]);
$total = (int)$tc->fetchColumn();

$base = rtrim(get_site_url(), '/');
$posts = [];
foreach ($stmt->fetchAll() as $r) {
    $row = [
        'id'                  => (int)$r['id'],
        'title'               => (string)$r['title'],
        'content_html'        => sanitize_html((string)$r['content']),
        'author_display_name' => (string)$r['author_display_name'],
        'created_at'          => (string)$r['created_at'],
    ];
    if (!empty($r['share_token'])) {
        $row['share_url'] = $base . '/post_public.php?token=' . $r['share_token'];
    }
    $posts[] = $row;
}

api_log_request((int)$key['id'], 200);
api_ok([
    'total'  => $total,
    'limit'  => $limit,
    'offset' => $offset,
    'count'  => count($posts),
    'posts'  => $posts,
]);

// ─────────────────────────────────────────────────────────────────────────────
// GET /posts/{id} — single post
// ─────────────────────────────────────────────────────────────────────────────
function handle_posts_get_one(int $post_id): void {
    $key = api_authenticate();
    $db  = get_db();
    $key_id = (int)$key['id'];
    $lid    = (int)$key['league_id'];

    $now_utc = (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

    $stmt = $db->prepare(
        "SELECT p.id, p.title, p.content, p.created_at, p.share_token,
                COALESCE(u.username, '') AS author_display_name
         FROM posts p
         LEFT JOIN users u ON u.id = p.author_id
         WHERE p.id = ?
           AND p.league_id = ?
           AND p.is_rules_post = 0
           AND p.hidden = 0
           AND p.created_at <= ?"
    );
    $stmt->execute([$post_id, $lid, $now_utc]);
    $r = $stmt->fetch();
    if (!$r) {
        api_log_request($key_id, 404);
        api_fail('post_not_found', 404);
    }

    $base = rtrim(get_site_url(), '/');
    $post = [
        'id'                  => (int)$r['id'],
        'title'               => (string)$r['title'],
        'content_html'        => sanitize_html((string)$r['content']),
        'author_display_name' => (string)$r['author_display_name'],
        'created_at'          => (string)$r['created_at'],
    ];
    if (!empty($r['share_token'])) {
        $post['share_url'] = $base . '/post_public.php?token=' . $r['share_token'];
    }

    api_log_request($key_id, 200);
    api_ok($post);
}
