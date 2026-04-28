<?php
/**
 * GET /api/v1/posts?limit=20&offset=0
 *
 * Returns posts that belong to the league bound to the API key. Excludes
 * the league's rules post (it lives behind its own UI button) and any post
 * marked hidden, draft, or scheduled in the future. content_html is the
 * already-sanitized HTML body. share_url is included only when a post has
 * a public share token, so the sister site can deep-link to the public
 * viewer page.
 */

require_once __DIR__ . '/../_auth.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    api_log_request(null, 405);
    api_fail('Method not allowed', 405);
}

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
