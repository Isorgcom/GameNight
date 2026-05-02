<?php
/**
 * /api/v1/posts
 *
 * GET     /posts          — list posts for the bound league (paginated). Excludes
 *                            the rules post (use /rules), hidden posts, and any
 *                            post scheduled in the future.
 * GET     /posts/{id}     — fetch one post by id. Same visibility filters as
 *                            the list — anything filtered out returns 404.
 * POST    /posts          — create a post in the bound league. Requires write scope.
 * PATCH   /posts/{id}     — partial update of title/content/pinned/hidden. Write scope.
 * DELETE  /posts/{id}     — hard-delete a post and its comments. Write scope.
 *
 * content_html is sanitized HTML on both reads and writes (defense-in-depth);
 * share_url is included only when the post has a public share token. The
 * is_rules_post flag and share_token lifecycle stay UI-only — sister sites
 * cannot promote a post to rules or mint share links via the API.
 */

require_once __DIR__ . '/../_auth.php';
require_once __DIR__ . '/../_time.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'OPTIONS') {
    api_send_headers(0);
    http_response_code(204);
    exit;
}

$post_id = (int)($_GET['id'] ?? 0);

if ($method === 'POST') { handle_posts_post(); exit; }
if ($method === 'PATCH'  && $post_id > 0) { handle_posts_patch();  exit; }
if ($method === 'DELETE' && $post_id > 0) { handle_posts_delete(); exit; }

if ($method !== 'GET') {
    api_log_request(null, 405);
    api_fail('Method not allowed', 405);
}

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

// ─────────────────────────────────────────────────────────────────────────────
// Helpers shared across the write handlers
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Reject locked fields with a specific 400. Lifecycle for is_rules_post and
 * share tokens stays UI-only; sister sites that need them have a human flip
 * the toggle in the league Posts tab.
 */
function posts_reject_locked_fields(array $body, int $key_id, array $extra = []): void {
    $locked = array_merge(['is_rules_post', 'share_token', 'make_public'], $extra);
    foreach ($locked as $f) {
        if (array_key_exists($f, $body)) {
            api_log_request($key_id, 400);
            api_fail("$f is not settable via the API; use the in-app UI", 400);
        }
    }
}

/** Build the same response shape as GET /posts/{id}, given the row + share base. */
function posts_serialize_row(array $r, string $base): array {
    $row = [
        'id'                  => (int)$r['id'],
        'title'               => (string)$r['title'],
        'content_html'        => sanitize_html((string)$r['content']),
        'author_display_name' => (string)$r['author_display_name'],
        'created_at'          => (string)$r['created_at'],
        'pinned'              => (int)($r['pinned'] ?? 0) === 1,
        'hidden'              => (int)($r['hidden'] ?? 0) === 1,
    ];
    if (!empty($r['share_token'])) {
        $row['share_url'] = $base . '/post_public.php?token=' . $r['share_token'];
    }
    return $row;
}

// ─────────────────────────────────────────────────────────────────────────────
// POST /posts — create a post in the bound league
// ─────────────────────────────────────────────────────────────────────────────
function handle_posts_post(): void {
    $key = api_authenticate();
    api_require_scope($key, 'write');

    $db        = get_db();
    $key_id    = (int)$key['id'];
    $league_id = (int)$key['league_id'];

    // Per-key rate limit. Filter on POST to /api/v1/posts but exclude any per-id
    // path so the rate counter doesn't conflate creates with PATCH/DELETE traffic.
    $rl = $db->prepare(
        "SELECT COUNT(*) FROM api_request_log
          WHERE key_id = ?
            AND status = 200
            AND method = 'POST'
            AND path LIKE '%/api/v1/posts%'
            AND path NOT LIKE '%/api/v1/posts/%'
            AND created_at > datetime('now','-1 hour')"
    );
    $rl->execute([$key_id]);
    if ((int)$rl->fetchColumn() >= 60) {
        api_log_request($key_id, 429);
        api_fail('Rate limit exceeded: 60 post creations per hour per key', 429);
    }

    $raw  = file_get_contents('php://input');
    $body = json_decode($raw ?: '', true);
    if (!is_array($body)) {
        api_log_request($key_id, 400);
        api_fail('Request body must be valid JSON', 400);
    }

    posts_reject_locked_fields($body, $key_id);

    $title = trim((string)($body['title'] ?? ''));
    if ($title === '') {
        api_log_request($key_id, 400);
        api_fail('title is required', 400);
    }
    if (mb_strlen($title) > 200) {
        api_log_request($key_id, 400);
        api_fail('title must be 200 characters or fewer', 400);
    }

    $content_raw = (string)($body['content'] ?? '');
    $content     = sanitize_html($content_raw);
    if (trim($content) === '') {
        api_log_request($key_id, 400);
        api_fail('content is required', 400);
    }

    $pinned = !empty($body['pinned']) ? 1 : 0;
    $hidden = !empty($body['hidden']) ? 1 : 0;

    // published_at: optional ISO-8601 UTC instant. Stored UTC. Future values
    // create a scheduled post; GET /posts already filters created_at <= now.
    $published_at_utc = (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    if (isset($body['published_at']) && $body['published_at'] !== '') {
        try {
            $dt = new DateTime((string)$body['published_at']);
            $dt->setTimezone(new DateTimeZone('UTC'));
            $published_at_utc = $dt->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            api_log_request($key_id, 400);
            api_fail('published_at must be ISO-8601 (e.g. "2026-05-17T20:00:00Z")', 400);
        }
    }

    // Author = league owner, mirroring how POST /events sets created_by.
    $ow = $db->prepare('SELECT owner_id FROM leagues WHERE id = ?');
    $ow->execute([$league_id]);
    $owner_id = (int)$ow->fetchColumn();
    if ($owner_id <= 0) {
        api_log_request($key_id, 404);
        api_fail('League not found', 404);
    }

    try {
        $db->prepare(
            'INSERT INTO posts (title, content, league_id, author_id, pinned, hidden, is_rules_post, created_at)
             VALUES (?, ?, ?, ?, ?, ?, 0, ?)'
        )->execute([$title, $content, $league_id, $owner_id, $pinned, $hidden, $published_at_utc]);
    } catch (Exception $e) {
        api_log_request($key_id, 500);
        api_fail('Failed to create post', 500);
    }
    $post_id = (int)$db->lastInsertId();

    db_log_anon_activity("api_create_post: '$title' (id=$post_id) via key=$key_id league=$league_id" . ($pinned ? ' pinned' : '') . ($hidden ? ' hidden' : ''));

    // Re-fetch with the LEFT JOIN so the response includes author_display_name.
    $rowStmt = $db->prepare(
        "SELECT p.id, p.title, p.content, p.created_at, p.share_token, p.pinned, p.hidden,
                COALESCE(u.username, '') AS author_display_name
         FROM posts p
         LEFT JOIN users u ON u.id = p.author_id
         WHERE p.id = ?"
    );
    $rowStmt->execute([$post_id]);
    $r = $rowStmt->fetch();

    api_log_request($key_id, 200);
    api_ok(posts_serialize_row($r, rtrim(get_site_url(), '/')), 0);
}

// ─────────────────────────────────────────────────────────────────────────────
// PATCH /posts/{id} — partial update of an existing post
// ─────────────────────────────────────────────────────────────────────────────
function handle_posts_patch(): void {
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
            AND path LIKE '%/api/v1/posts/%'
            AND created_at > datetime('now','-1 hour')"
    );
    $rl->execute([$key_id]);
    if ((int)$rl->fetchColumn() >= 60) {
        api_log_request($key_id, 429);
        api_fail('Rate limit exceeded: 60 post updates per hour per key', 429);
    }

    $post_id = (int)($_GET['id'] ?? 0);
    if ($post_id <= 0) {
        api_log_request($key_id, 404);
        api_fail('post_not_found', 404);
    }

    $stmt = $db->prepare(
        'SELECT id, title, content, league_id, pinned, hidden FROM posts WHERE id = ?'
    );
    $stmt->execute([$post_id]);
    $current = $stmt->fetch();
    if (!$current || (int)$current['league_id'] !== $league_id) {
        api_log_request($key_id, 404);
        api_fail('post_not_found', 404);
    }

    $raw  = file_get_contents('php://input');
    $body = json_decode($raw ?: '', true);
    if (!is_array($body) || empty($body)) {
        api_log_request($key_id, 400);
        api_fail('Request body must be a non-empty JSON object', 400);
    }

    // PATCH adds published_at to the locked list — retroactive publish-date
    // edits create a confusing audit story.
    posts_reject_locked_fields($body, $key_id, ['published_at']);

    $allowed = ['title', 'content', 'pinned', 'hidden'];
    foreach (array_keys($body) as $k) {
        if (!in_array($k, $allowed, true)) {
            api_log_request($key_id, 400);
            api_fail("Unknown field: $k. Allowed: " . implode(', ', $allowed), 400);
        }
    }

    $updates = [];
    $fields_changed = [];

    if (array_key_exists('title', $body)) {
        $t = trim((string)$body['title']);
        if ($t === '') { api_log_request($key_id, 400); api_fail('title cannot be empty', 400); }
        if (mb_strlen($t) > 200) { api_log_request($key_id, 400); api_fail('title must be 200 characters or fewer', 400); }
        if ($t !== (string)$current['title']) { $updates['title'] = $t; $fields_changed[] = 'title'; }
    }
    if (array_key_exists('content', $body)) {
        $c = sanitize_html((string)$body['content']);
        if (trim($c) === '') { api_log_request($key_id, 400); api_fail('content cannot be empty', 400); }
        if ($c !== (string)$current['content']) { $updates['content'] = $c; $fields_changed[] = 'content'; }
    }
    foreach (['pinned', 'hidden'] as $bf) {
        if (array_key_exists($bf, $body)) {
            $new = !empty($body[$bf]) ? 1 : 0;
            if ((int)$current[$bf] !== $new) { $updates[$bf] = $new; $fields_changed[] = $bf; }
        }
    }

    if (empty($updates)) {
        api_log_request($key_id, 400);
        api_fail('no_fields_to_update', 400);
    }

    try {
        $db->beginTransaction();
        $sets = [];
        $args = [];
        foreach ($updates as $col => $val) { $sets[] = "$col = ?"; $args[] = $val; }
        $args[] = $post_id;
        $db->prepare('UPDATE posts SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($args);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        api_log_request($key_id, 500);
        api_fail('Failed to update post', 500);
    }

    db_log_anon_activity("api_update_post: '" . ($updates['title'] ?? $current['title']) . "' (id=$post_id) via key=$key_id league=$league_id changed=" . implode(',', $fields_changed));

    // Re-fetch the updated row for the echoed response.
    $rowStmt = $db->prepare(
        "SELECT p.id, p.title, p.content, p.created_at, p.share_token, p.pinned, p.hidden,
                COALESCE(u.username, '') AS author_display_name
         FROM posts p
         LEFT JOIN users u ON u.id = p.author_id
         WHERE p.id = ?"
    );
    $rowStmt->execute([$post_id]);
    $r = $rowStmt->fetch();

    $resp = posts_serialize_row($r, rtrim(get_site_url(), '/'));
    $resp['fields_changed'] = $fields_changed;

    api_log_request($key_id, 200);
    api_ok($resp, 0);
}

// ─────────────────────────────────────────────────────────────────────────────
// DELETE /posts/{id} — hard delete + comment cascade
// ─────────────────────────────────────────────────────────────────────────────
function handle_posts_delete(): void {
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
            AND path LIKE '%/api/v1/posts/%'
            AND created_at > datetime('now','-1 hour')"
    );
    $rl->execute([$key_id]);
    if ((int)$rl->fetchColumn() >= 60) {
        api_log_request($key_id, 429);
        api_fail('Rate limit exceeded: 60 post deletions per hour per key', 429);
    }

    $post_id = (int)($_GET['id'] ?? 0);
    if ($post_id <= 0) {
        api_log_request($key_id, 404);
        api_fail('post_not_found', 404);
    }

    $stmt = $db->prepare('SELECT id, title, league_id FROM posts WHERE id = ?');
    $stmt->execute([$post_id]);
    $row = $stmt->fetch();
    if (!$row || (int)$row['league_id'] !== $league_id) {
        api_log_request($key_id, 404);
        api_fail('post_not_found', 404);
    }
    $title = (string)$row['title'];

    $comments_deleted = 0;
    try {
        $db->beginTransaction();

        $cd = $db->prepare("DELETE FROM comments WHERE type = 'post' AND content_id = ?");
        $cd->execute([$post_id]);
        $comments_deleted = $cd->rowCount();

        $db->prepare('DELETE FROM posts WHERE id = ?')->execute([$post_id]);

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        api_log_request($key_id, 500);
        api_fail('Failed to delete post', 500);
    }

    db_log_anon_activity("api_delete_post: '$title' (id=$post_id) via key=$key_id league=$league_id" . ($comments_deleted > 0 ? " (comments=$comments_deleted)" : ''));

    api_log_request($key_id, 200);
    api_ok([
        'post_id'          => $post_id,
        'deleted'          => true,
        'comments_deleted' => $comments_deleted,
    ], 0);
}
