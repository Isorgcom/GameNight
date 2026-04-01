<?php
/**
 * Infinite-scroll chunk endpoint.
 * Returns an HTML fragment: post cards + a trailing marker div.
 * Empty response = no more posts.
 */
require_once __DIR__ . '/auth.php';

$user     = current_user();
$db       = get_db();
$local_tz = new DateTimeZone(get_setting('timezone', 'UTC'));
$now      = (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
$isAdmin  = $user && $user['role'] === 'admin';
$csrf     = $user ? csrf_token() : '';

$limit       = min(10, max(1, (int)($_GET['limit']     ?? 5)));
$offset      = max(0,         (int)($_GET['offset']    ?? 0));
$prevMonth   = $_GET['prev_month'] ?? '';
$monthFilter = preg_match('/^\d{4}-\d{2}$/', $_GET['month'] ?? '') ? $_GET['month'] : null;

if ($monthFilter) {
    $stmt = $db->prepare(
        "SELECT id, title, content, created_at, pinned FROM posts
         WHERE created_at <= ? AND hidden = 0 AND strftime('%Y-%m', datetime(created_at)) = ?
         ORDER BY pinned DESC, created_at DESC LIMIT ? OFFSET ?"
    );
    $stmt->execute([$now, $monthFilter, $limit, $offset]);
} else {
    $stmt = $db->prepare(
        'SELECT id, title, content, created_at, pinned FROM posts
         WHERE created_at <= ? AND hidden = 0
         ORDER BY pinned DESC, created_at DESC LIMIT ? OFFSET ?'
    );
    $stmt->execute([$now, $limit, $offset]);
}
$posts = $stmt->fetchAll();

if (empty($posts)) {
    exit; // signals "no more" to JS
}

// Batch-load comments
$pids = array_column($posts, 'id');
$ph   = implode(',', array_fill(0, count($pids), '?'));
$cs   = $db->prepare(
    "SELECT c.*, u.username FROM comments c
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
    $redir    = '/' . ($monthFilter ? '?month=' . urlencode($monthFilter) : '') . '#post-' . (int)$post['id'];
?>
<div class="post-card<?= $post['pinned'] ? ' pinned' : '' ?>" id="post-<?= (int)$post['id'] ?>">
    <div class="post-meta">
        <?php if ($post['pinned']): ?><span class="pin-badge">&#128204; Pinned</span><?php endif; ?>
        <span>&#128197; <?= htmlspecialchars((new DateTime($post['created_at'], new DateTimeZone('UTC')))->setTimezone($local_tz)->format('F j, Y')) ?></span>
        <?php if ($isAdmin): ?>
        <div class="post-actions">
            <a href="/admin_posts.php?edit=<?= (int)$post['id'] ?>">Edit</a>
            <form method="post" action="/admin_posts.php" style="margin:0"
                  onsubmit="return confirm('Delete this post?')">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int)$post['id'] ?>">
                <button type="submit" class="danger">Delete</button>
            </form>
        </div>
        <?php endif; ?>
    </div>
    <div class="post-title"><?= htmlspecialchars($post['title']) ?></div>
    <div class="post-body"><?= sanitize_html($post['content']) ?></div>

    <!-- Comments -->
    <div class="comments-section" id="csec-<?= (int)$post['id'] ?>">
        <div class="comments-heading" onclick="toggleComments(<?= (int)$post['id'] ?>)">
            <span class="cmts-toggle-label">
                <span class="cmts-chevron">&#9658;</span>
                <?= count($comments) ?> Comment<?= count($comments) !== 1 ? 's' : '' ?>
            </span>
            <?php if ($isAdmin && count($comments) > 0): ?>
            <label class="sel-all-label" onclick="event.stopPropagation()">
                <input type="checkbox" class="sel-all" onchange="toggleSelAll(<?= (int)$post['id'] ?>, this)"> Select all
            </label>
            <?php endif; ?>
        </div>
        <div class="comments-body" id="cmts-body-<?= (int)$post['id'] ?>" style="display:none">
            <?php if ($isAdmin && count($comments) > 0): ?>
            <div class="bulk-bar" id="bulk-<?= (int)$post['id'] ?>" style="display:none">
                <span class="bulk-count" id="bulkcount-<?= (int)$post['id'] ?>">0 selected</span>
                <form method="post" action="/comment.php" style="margin:0;display:contents"
                      onsubmit="return prepareBulkDelete(<?= (int)$post['id'] ?>, this)">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="action" value="bulk_delete">
                    <input type="hidden" name="comment_ids" value="">
                    <input type="hidden" name="redirect" value="<?= htmlspecialchars($redir) ?>">
                    <button type="submit" class="btn btn-danger" style="font-size:.75rem;padding:.25rem .65rem">Delete selected</button>
                </form>
                <button type="button" onclick="clearSel(<?= (int)$post['id'] ?>)"
                        class="btn btn-outline" style="font-size:.75rem;padding:.25rem .65rem">Cancel</button>
            </div>
            <?php endif; ?>

            <?php foreach ($comments as $c): ?>
            <div class="comment" id="cmt-<?= (int)$c['id'] ?>">
                <?php if ($isAdmin): ?>
                <input type="checkbox" class="comment-sel" value="<?= (int)$c['id'] ?>"
                       onchange="onSelChange(<?= (int)$post['id'] ?>)">
                <?php endif; ?>
                <div class="comment-avatar"><?= htmlspecialchars(mb_substr($c['username'], 0, 1)) ?></div>
                <div class="comment-content">
                    <div class="comment-meta">
                        <strong><?= htmlspecialchars($c['username']) ?></strong>
                        <span><?= htmlspecialchars((new DateTime($c['created_at'], new DateTimeZone('UTC')))->setTimezone($local_tz)->format('M j, Y g:i A')) ?></span>
                    </div>
                    <div class="comment-body" id="cbody-<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['body']) ?></div>
                    <?php if ($user && ($user['id'] == $c['user_id'] || $isAdmin)): ?>
                    <div class="comment-actions">
                        <button type="button" class="comment-delete"
                                onclick="editComment(<?= (int)$c['id'] ?>, this)"
                                title="Edit">&#9998;</button>
                        <form method="post" action="/comment.php" style="margin:0;display:contents"
                              onsubmit="return confirm('Delete this comment?')">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="comment_id" value="<?= (int)$c['id'] ?>">
                            <input type="hidden" name="redirect" value="<?= htmlspecialchars($redir) ?>">
                            <button type="submit" class="comment-delete" title="Delete">&#x2715;</button>
                        </form>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>

            <?php if ($user): ?>
            <form method="post" action="/comment.php" class="comment-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="action" value="add">
                <input type="hidden" name="type" value="post">
                <input type="hidden" name="content_id" value="<?= (int)$post['id'] ?>">
                <input type="hidden" name="redirect" value="<?= htmlspecialchars($redir) ?>">
                <textarea name="body" placeholder="Write a comment…" required maxlength="2000"></textarea>
                <button type="submit" class="btn btn-primary btn-post">Post</button>
            </form>
            <?php else: ?>
            <p class="comment-login"><a href="/login.php">Log in</a> to leave a comment.</p>
            <?php endif; ?>
        </div><!-- /.comments-body -->
    </div><!-- /.comments-section -->
</div><!-- /.post-card -->
<?php endforeach; ?>
<div hidden data-chunk-count="<?= count($posts) ?>" data-last-month="<?= htmlspecialchars($tlPrevMonth) ?>"></div>
