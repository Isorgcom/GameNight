<?php
/**
 * Infinite-scroll chunk endpoint.
 * Returns an HTML fragment: post cards + a trailing marker div.
 * Empty response = no more posts.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_posts.php';

$user     = current_user();
$db       = get_db();
$local_tz = new DateTimeZone(display_timezone());
$isAdmin  = $user && $user['role'] === 'admin';
$csrf     = $user ? csrf_token() : '';
$showHidden = $user && ($_GET['show'] ?? '') === 'hidden';

$limit       = min(10, max(1, (int)($_GET['limit']     ?? 5)));
$offset      = max(0,         (int)($_GET['offset']    ?? 0));
$prevMonth   = $_GET['prev_month'] ?? '';
$monthFilter = preg_match('/^\d{4}-\d{2}$/', $_GET['month'] ?? '') ? $_GET['month'] : null;

$_vp = posts_feed_sql_for_user($user ? (int)$user['id'] : null, $isAdmin, $showHidden ? 'only' : 'exclude');

if ($monthFilter) {
    $stmt = $db->prepare(
        "SELECT p.id, p.title, p.content, p.created_at, p.pinned, p.league_id, p.author_id, l.name AS league_name
         FROM posts p LEFT JOIN leagues l ON l.id = p.league_id
         WHERE {$_vp['sql']} AND strftime('%Y-%m', datetime(p.created_at)) = ?
         ORDER BY p.pinned DESC, p.created_at DESC LIMIT ? OFFSET ?"
    );
    $stmt->execute(array_merge($_vp['params'], [$monthFilter, $limit, $offset]));
} else {
    $stmt = $db->prepare(
        "SELECT p.id, p.title, p.content, p.created_at, p.pinned, p.league_id, p.author_id, l.name AS league_name
         FROM posts p LEFT JOIN leagues l ON l.id = p.league_id
         WHERE {$_vp['sql']}
         ORDER BY p.pinned DESC, p.created_at DESC LIMIT ? OFFSET ?"
    );
    $stmt->execute(array_merge($_vp['params'], [$limit, $offset]));
}
$posts = $stmt->fetchAll();

if (empty($posts)) {
    exit; // signals "no more" to JS
}

// Batch-load comments
$pids = array_column($posts, 'id');
$ph   = implode(',', array_fill(0, count($pids), '?'));
$cs   = $db->prepare(
    "SELECT c.*, u.username, u.avatar_path FROM comments c
     JOIN users u ON u.id = c.user_id
     WHERE c.type = 'post' AND c.content_id IN ($ph)
     ORDER BY c.created_at ASC"
);
$cs->execute($pids);
$post_comments = [];
foreach ($cs->fetchAll() as $c) $post_comments[$c['content_id']][] = $c;

$tlPrevMonth = $prevMonth;

foreach ($posts as $post):
    if (!$post['pinned']) {
        $tlPostMonth = (new DateTime($post['created_at'], new DateTimeZone('UTC')))
                           ->setTimezone($local_tz)->format('Y-m');
        if ($tlPostMonth !== $tlPrevMonth) {
            $tlPrevMonth = $tlPostMonth;
            echo '<div id="month-' . htmlspecialchars($tlPostMonth) . '" class="month-anchor"></div>';
        }
    }
    $comments = $post_comments[$post['id']] ?? [];
    include __DIR__ . '/_post_card.php';
endforeach;
?>
<div hidden data-chunk-count="<?= count($posts) ?>" data-last-month="<?= htmlspecialchars($tlPrevMonth) ?>"></div>
