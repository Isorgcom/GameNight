<?php
/**
 * Shared post-card partial used by the home feed (index.php) and the
 * infinite-scroll chunk endpoint (posts_chunk.php). Keeps a single source of
 * truth for the card markup, the per-post kebab menu, and the comments section.
 *
 * Expected in-scope variables (set by the caller before include):
 *   $post        — post row (id, title, content, created_at, pinned, league_id, author_id, league_name)
 *   $comments    — array of comment rows for this post
 *   $user        — current_user() array or null
 *   $isAdmin     — bool
 *   $db          — PDO
 *   $local_tz    — DateTimeZone
 *   $csrf        — csrf token string
 *   $monthFilter — current month filter (string) or null
 *   $showHidden  — bool: rendering the "Show hidden" feed (Unhide instead of Hide)
 */
$__p_league_id = (int)($post['league_id'] ?? 0);
$__p_is_global = ($__p_league_id === 0);
$__p_can_edit  = $user && user_can_edit_post($db, $post, (int)$user['id'], $isAdmin);
$__p_showHidden = !empty($showHidden);
$redir = '/' . ($monthFilter ? '?month=' . urlencode($monthFilter) : '') . '#post-' . (int)$post['id'];
?>
<div class="post-card<?= $post['pinned'] ? ' pinned' : '' ?>" id="post-<?= (int)$post['id'] ?>">
    <div class="post-meta">
        <?php if ($post['pinned']): ?><span class="pin-badge">&#128204; Pinned</span><?php endif; ?>
        <?php if ($__p_showHidden): ?><span class="hidden-badge">&#128065; Hidden</span><?php endif; ?>
        <?php if (!$__p_is_global && !empty($post['league_name'])): ?>
            <a class="league-badge" href="/league.php?id=<?= $__p_league_id ?>">&#127942; <?= htmlspecialchars($post['league_name']) ?></a>
        <?php endif; ?>
        <span>&#128197; <?= htmlspecialchars((new DateTime($post['created_at'], new DateTimeZone('UTC')))->setTimezone($local_tz)->format('F j, Y')) ?></span>
        <?php if ($user): ?>
        <div class="post-menu-wrap">
            <button type="button" class="post-menu" aria-label="Post actions" aria-haspopup="true">&#8943;</button>
            <div class="post-menu-dropdown">
                <?php if ($__p_showHidden): ?>
                    <button type="button" class="post-menu-item post-unhide-btn" data-post-id="<?= (int)$post['id'] ?>">Unhide</button>
                <?php else: ?>
                    <button type="button" class="post-menu-item post-hide-btn" data-post-id="<?= (int)$post['id'] ?>">Hide</button>
                <?php endif; ?>
                <?php if ($__p_can_edit): ?>
                    <a class="post-menu-item" href="<?= $__p_is_global
                        ? '/admin_posts.php?edit=' . (int)$post['id']
                        : '/league.php?id=' . $__p_league_id . '&tab=posts&edit=' . (int)$post['id'] ?>">Edit</a>
                    <form method="post" action="<?= $__p_is_global ? '/admin_posts.php' : '/league_posts_dl.php' ?>" style="margin:0"
                          onsubmit="return pkConfirmForm(this, 'Delete this post?', {okLabel:'Delete', danger:true})">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                        <input type="hidden" name="action" value="delete">
                        <?php if ($__p_is_global): ?>
                            <input type="hidden" name="id" value="<?= (int)$post['id'] ?>">
                        <?php else: ?>
                            <input type="hidden" name="post_id" value="<?= (int)$post['id'] ?>">
                            <input type="hidden" name="redirect" value="/<?= $monthFilter ? '?month=' . urlencode($monthFilter) : '' ?>">
                        <?php endif; ?>
                        <button type="submit" class="post-menu-item danger">Delete</button>
                    </form>
                <?php endif; ?>
            </div>
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
                <?= avatar_html($c['username'], $c['avatar_path'] ?? null, 34) ?>
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
                              onsubmit="return pkConfirmForm(this, 'Delete this comment?', {okLabel:'Delete', danger:true})">
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
