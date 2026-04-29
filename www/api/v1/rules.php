<?php
/**
 * GET /api/v1/rules
 *
 * Returns the league's rules post (the post flagged is_rules_post = 1),
 * or rules: null when the league has not configured rules yet. content_html
 * is the already-sanitized HTML body. Hidden rules posts are treated as
 * absent.
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
    "SELECT p.id, p.title, p.content, p.created_at,
            COALESCE(u.username, '') AS author_display_name
     FROM posts p
     LEFT JOIN users u ON u.id = p.author_id
     WHERE p.league_id = ?
       AND p.is_rules_post = 1
       AND p.hidden = 0
     LIMIT 1"
);
$stmt->execute([$lid]);
$row = $stmt->fetch();

$rules = null;
if ($row) {
    $rules = [
        'id'                  => (int)$row['id'],
        'title'               => (string)$row['title'],
        'content_html'        => sanitize_html((string)$row['content']),
        'author_display_name' => (string)$row['author_display_name'],
        'created_at'          => (string)$row['created_at'],
    ];
}

api_log_request((int)$key['id'], 200);
api_ok(['rules' => $rules]);
