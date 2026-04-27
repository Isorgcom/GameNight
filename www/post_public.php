<?php
/**
 * Public share-link viewer for league posts. Token-bearer can read a single
 * league post without being a member of the league.
 *
 * Usage: /post_public.php?token=<32-char hex>
 *
 * The post is reachable ONLY through this route — feed queries continue to hide
 * league posts from non-members. Logged-out visitors see the post read-only;
 * logged-in league members (and admins) see and use the comment form, just
 * like they would in a normal feed view.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/_posts.php';
require_once __DIR__ . '/version.php';

$user      = current_user();
$db        = get_db();
$site_name = get_setting('site_name', 'Game Night');
$local_tz  = new DateTimeZone(get_setting('timezone', 'UTC'));
$isAdmin   = $user && ($user['role'] ?? '') === 'admin';

$token = trim($_GET['token'] ?? '');
if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
    http_response_code(404);
    require __DIR__ . '/_footer.php';
    exit;
}

$stmt = $db->prepare(
    "SELECT p.*, l.name AS league_name
       FROM posts p
       LEFT JOIN leagues l ON l.id = p.league_id
      WHERE p.share_token = ?"
);
$stmt->execute([$token]);
$post = $stmt->fetch();

// 404 for any miss: revoked token, hidden post, or somehow a global post
// (share tokens are never minted on global posts, but be defensive).
if (!$post || (int)($post['hidden'] ?? 0) === 1 || (int)($post['league_id'] ?? 0) === 0) {
    http_response_code(404);
    ?>
    <!DOCTYPE html>
    <html lang="en"><head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="robots" content="noindex,nofollow">
        <title>Link Not Found — <?= htmlspecialchars($site_name) ?></title>
        <link rel="stylesheet" href="/style.css">
    </head><body style="display:flex;align-items:center;justify-content:center;min-height:100vh;padding:1rem">
        <div style="max-width:480px;width:100%;text-align:center">
            <div style="background:#fef2f2;border:2px solid #dc2626;border-radius:12px;padding:2rem 1.5rem;margin-bottom:1.5rem">
                <h1 style="font-size:1.5rem;color:#dc2626;margin:0 0 .75rem">Link Not Found</h1>
                <div style="font-size:1rem;color:#334155;line-height:1.6">This share link is no longer valid. It may have been disabled or replaced.</div>
            </div>
            <a href="/" style="color:#2563eb;text-decoration:none;font-size:.9rem">Go to <?= htmlspecialchars($site_name) ?></a>
        </div>
    </body></html>
    <?php
    exit;
}

// Comment form gate: only logged-in league members + admins may comment.
$can_comment = $user && post_is_visible_to($db, $post, (int)$user['id'], $isAdmin);

// Load existing comments (always read-only for share-link viewers without membership).
$cs = $db->prepare(
    "SELECT c.*, u.username FROM comments c
     JOIN users u ON u.id = c.user_id
     WHERE c.type = 'post' AND c.content_id = ?
     ORDER BY c.created_at ASC"
);
$cs->execute([(int)$post['id']]);
$comments = $cs->fetchAll();

$csrf  = $user ? csrf_token() : '';
$redir = '/post_public.php?token=' . urlencode($token);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow">
    <title><?= htmlspecialchars($post['title']) ?> — <?= htmlspecialchars($site_name) ?></title>
    <link rel="stylesheet" href="/style.css">
    <style>
        .page-layout { max-width: 740px; margin: 2rem auto 0; padding: 0 1.5rem; }
        .post-card { background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:1.75rem; margin-bottom:1.5rem; }
        .post-meta { font-size:.78rem; color:#94a3b8; margin-bottom:.6rem; display:flex; align-items:center; gap:.75rem; flex-wrap:wrap; }
        .post-title { font-size:1.4rem; font-weight:700; color:#0f172a; margin-bottom:.85rem; }
        .post-body { line-height:1.75; color:#334155; font-size:.97rem; overflow:hidden; }
        .post-body p { margin-bottom:.85rem; }
        .post-body img { max-width:100%; height:auto; display:block; border-radius:6px; margin:.5rem 0; }
        .post-body a { color:#2563eb; }
        .league-badge { font-size:.72rem; font-weight:600; color:#1e40af; background:#dbeafe; border:1px solid #93c5fd; border-radius:999px; padding:.15rem .6rem; }
        .public-badge { font-size:.72rem; font-weight:600; color:#166534; background:#dcfce7; border:1px solid #86efac; border-radius:999px; padding:.15rem .6rem; }
        .share-banner { background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:.75rem 1rem; font-size:.85rem; color:#475569; margin-bottom:1.5rem; }
    </style>
</head>
<body>

<?php if ($user): ?>
    <?php $nav_active = ''; $nav_user = $user; require __DIR__ . '/_nav.php'; ?>
<?php else:
    // Logged-out viewers: render a minimal top bar so they aren't orphaned.
    // The site's standard _nav.php returns empty when show_landing_page=1, which would
    // leave this page without any navigation/header at all.
    $__hdr_banner = get_setting('header_banner_path', '');
    $__allow_reg  = get_setting('allow_registration', '1') === '1';
?>
<nav style="background:#0f172a;color:#fff;padding:.75rem 1.25rem;display:flex;align-items:center;gap:1rem;border-bottom:1px solid #1e293b">
    <a href="/" style="color:#fff;text-decoration:none;font-weight:700;font-size:1.05rem;display:flex;align-items:center;gap:.6rem">
        <?php if ($__hdr_banner): ?>
            <img src="<?= htmlspecialchars($__hdr_banner) ?>" alt="<?= htmlspecialchars($site_name) ?>" style="max-height:40px;width:auto;display:block">
        <?php else: ?>
            <?= htmlspecialchars($site_name) ?>
        <?php endif; ?>
    </a>
    <div style="margin-left:auto;display:flex;gap:.5rem">
        <a href="/login.php?redirect=<?= urlencode($redir) ?>" style="color:#fff;text-decoration:none;padding:.4rem .9rem;border-radius:6px;background:#2563eb;font-size:.9rem;font-weight:600">Log in</a>
        <?php if ($__allow_reg): ?>
            <a href="/register.php" style="color:#fff;text-decoration:none;padding:.4rem .9rem;border-radius:6px;border:1px solid #475569;font-size:.9rem;font-weight:600">Sign up</a>
        <?php endif; ?>
    </div>
</nav>
<?php endif; ?>

<div class="page-layout">
    <div class="share-banner">
        Shared from <?= htmlspecialchars($post['league_name'] ?? 'a league') ?>.
        <?php if (!$user): ?>
            <a href="/login.php?redirect=<?= urlencode($redir) ?>" style="color:#2563eb;text-decoration:none;font-weight:600">Log in</a>
            to comment or to see other posts from this league.
        <?php endif; ?>
    </div>

    <div class="post-card" id="post-<?= (int)$post['id'] ?>">
        <div class="post-meta">
            <?php if (!empty($post['league_name'])): ?>
                <?php if ($user): ?>
                    <a class="league-badge" href="/league.php?id=<?= (int)$post['league_id'] ?>" style="text-decoration:none">&#127942; <?= htmlspecialchars($post['league_name']) ?></a>
                <?php else: ?>
                    <span class="league-badge">&#127942; <?= htmlspecialchars($post['league_name']) ?></span>
                <?php endif; ?>
            <?php endif; ?>
            <span class="public-badge">&#128279; Public link</span>
            <span>&#128197; <?= htmlspecialchars((new DateTime($post['created_at'], new DateTimeZone('UTC')))->setTimezone($local_tz)->format('F j, Y')) ?></span>
        </div>
        <div class="post-title"><?= htmlspecialchars($post['title']) ?></div>
        <div class="post-body"><?= sanitize_html($post['content']) ?></div>

        <div class="comments-section" id="csec-<?= (int)$post['id'] ?>" style="margin-top:1.5rem;padding-top:1rem;border-top:1px solid #e2e8f0">
            <div style="font-size:.85rem;font-weight:600;color:#475569;margin-bottom:.75rem">
                <?= count($comments) ?> Comment<?= count($comments) !== 1 ? 's' : '' ?>
            </div>

            <?php foreach ($comments as $c): ?>
            <div class="comment" id="cmt-<?= (int)$c['id'] ?>">
                <div class="comment-avatar"><?= htmlspecialchars(mb_substr($c['username'], 0, 1)) ?></div>
                <div class="comment-content">
                    <div class="comment-meta">
                        <strong><?= htmlspecialchars($c['username']) ?></strong>
                        <span><?= htmlspecialchars((new DateTime($c['created_at'], new DateTimeZone('UTC')))->setTimezone($local_tz)->format('M j, Y g:i A')) ?></span>
                    </div>
                    <div class="comment-body"><?= htmlspecialchars($c['body']) ?></div>
                </div>
            </div>
            <?php endforeach; ?>

            <?php if ($can_comment): ?>
            <form method="post" action="/comment.php" class="comment-form" style="margin-top:1rem">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="action" value="add">
                <input type="hidden" name="type" value="post">
                <input type="hidden" name="content_id" value="<?= (int)$post['id'] ?>">
                <input type="hidden" name="redirect" value="<?= htmlspecialchars($redir) ?>">
                <textarea name="body" placeholder="Write a comment…" required maxlength="2000"></textarea>
                <button type="submit" class="btn btn-primary btn-post">Post</button>
            </form>
            <?php elseif ($user): ?>
            <p style="margin-top:1rem;color:#64748b;font-size:.85rem">Only members of this league can comment.</p>
            <?php else: ?>
            <p style="margin-top:1rem;color:#64748b;font-size:.85rem">
                <a href="/login.php?redirect=<?= urlencode($redir) ?>">Log in</a> as a league member to leave a comment.
            </p>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require __DIR__ . '/_footer.php'; ?>
</body>
</html>
