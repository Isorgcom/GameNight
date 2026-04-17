<?php
require_once __DIR__ . '/auth.php';

$user      = current_user();
$db        = get_db();
$site_name = get_setting('site_name', 'Game Night');

$chunk = 5;
$monthFilter = preg_match('/^\d{4}-\d{2}$/', $_GET['month'] ?? '') ? $_GET['month'] : null;

// Dates stored in UTC — compare against UTC now, display in configured timezone
$local_tz = new DateTimeZone(get_setting('timezone', 'UTC'));
$now      = (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

// This week's events (today through today+6)
$weekStart = (new DateTime('now', $local_tz))->setTime(0, 0, 0);
$weekEnd   = (clone $weekStart)->modify('+6 days');
$weekStartStr = $weekStart->format('Y-m-d');
$weekEndStr   = $weekEnd->format('Y-m-d');

$_vis = event_visibility_sql('events', $user ? (int)$user['id'] : null);
$wkStmt = $db->prepare(
    "SELECT * FROM events WHERE
       start_date <= ? AND (end_date >= ? OR (end_date IS NULL AND start_date >= ?))
       AND {$_vis['sql']}
     ORDER BY start_date, start_time"
);
$wkStmt->execute(array_merge([$weekEndStr, $weekStartStr, $weekStartStr], $_vis['params']));
$wkEvents = $wkStmt->fetchAll();

$wkByDate = build_event_by_date($wkEvents, $weekStartStr, $weekEndStr, $local_tz);

if ($monthFilter) {
    $cnt = $db->prepare("SELECT COUNT(*) FROM posts WHERE created_at <= ? AND hidden = 0 AND strftime('%Y-%m', datetime(created_at)) = ?");
    $cnt->execute([$now, $monthFilter]);
    $total = (int)$cnt->fetchColumn();
    $stmt = $db->prepare("SELECT id, title, content, created_at, pinned FROM posts WHERE created_at <= ? AND hidden = 0 AND strftime('%Y-%m', datetime(created_at)) = ? ORDER BY pinned DESC, created_at DESC LIMIT ?");
    $stmt->execute([$now, $monthFilter, $chunk]);
} else {
    $cnt = $db->prepare('SELECT COUNT(*) FROM posts WHERE created_at <= ? AND hidden = 0');
    $cnt->execute([$now]);
    $total = (int)$cnt->fetchColumn();
    $stmt = $db->prepare('SELECT id, title, content, created_at, pinned FROM posts WHERE created_at <= ? AND hidden = 0 ORDER BY pinned DESC, created_at DESC LIMIT ?');
    $stmt->execute([$now, $chunk]);
}
$posts = $stmt->fetchAll();

// Batch-load comments for all visible posts
$post_comments = [];
if (!empty($posts)) {
    $pids = array_column($posts, 'id');
    $ph   = implode(',', array_fill(0, count($pids), '?'));
    $cs   = $db->prepare("SELECT c.*, u.username FROM comments c JOIN users u ON u.id=c.user_id WHERE c.type='post' AND c.content_id IN ($ph) ORDER BY c.created_at ASC");
    $cs->execute($pids);
    foreach ($cs->fetchAll() as $c) $post_comments[$c['content_id']][] = $c;
}
$csrf    = csrf_token();
$isAdmin = $user && $user['role'] === 'admin';

// Timeline: all post months with counts (for the sidebar)
$tlStmt = $db->prepare(
    "SELECT strftime('%Y-%m', datetime(created_at)) AS ym, COUNT(*) AS cnt
     FROM posts WHERE created_at <= ? AND hidden = 0
     GROUP BY ym ORDER BY ym DESC"
);
$tlStmt->execute([$now]);
$tlMonths = $tlStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($site_name) ?></title>
    <link rel="stylesheet" href="/style.css">
    <style>
        /* ── Main layout: centered content, sidebar pinned to viewport left ── */
        .page-layout {
            max-width: 740px;
            margin: 2rem auto 0;
            padding: 0 1.5rem;
        }
        .posts-wrap { min-width: 0; }

        /* ── Timeline sidebar: fixed to far-left edge ── */
        .timeline-sidebar {
            position: fixed;
            left: 1rem;
            top: calc(92px + 1rem);
            width: 190px;
            z-index: 10;
        }
        .tl-box {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 1rem 1.1rem 1.25rem;
            max-height: 260px;
            overflow-y: auto;
            scroll-behavior: smooth;
        }
        .tl-heading {
            font-size: .7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .08em;
            color: #94a3b8;
            margin-bottom: .8rem;
            padding-bottom: .5rem;
            border-bottom: 1px solid #f1f5f9;
        }
        .tl-year-label {
            font-size: .68rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .06em;
            color: #cbd5e1;
            margin: .7rem 0 .2rem 1.35rem;
        }
        .tl-year-label:first-of-type { margin-top: 0; }
        /* Vertical line */
        .tl-list {
            position: relative;
            padding-left: 1.35rem;
        }
        .tl-list::before {
            content: '';
            position: absolute;
            left: 5px;
            top: 6px;
            bottom: 6px;
            width: 2px;
            background: #e2e8f0;
            border-radius: 2px;
        }
        .tl-entry {
            position: relative;
            display: flex;
            align-items: center;
            gap: .4rem;
            padding: .22rem 0;
            text-decoration: none;
            color: #475569;
            font-size: .82rem;
            line-height: 1.4;
            transition: color .12s;
        }
        /* Timeline dot */
        .tl-entry::before {
            content: '';
            position: absolute;
            left: -1.35rem;
            top: 50%;
            transform: translateY(-50%);
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #fff;
            border: 2px solid #cbd5e1;
            transition: border-color .12s, background .12s;
            z-index: 1;
        }
        .tl-entry:hover { color: #2563eb; text-decoration: none; }
        .tl-entry:hover::before { border-color: #2563eb; background: #dbeafe; }
        .tl-entry.tl-active { color: #2563eb; font-weight: 600; }
        .tl-entry.tl-active::before { background: #2563eb; border-color: #2563eb; }
        .tl-count {
            margin-left: auto;
            font-size: .68rem;
            color: #94a3b8;
            background: #f1f5f9;
            border-radius: 99px;
            padding: .05rem .42rem;
            font-weight: 500;
            flex-shrink: 0;
        }
        /* Month anchor (invisible scroll target) */
        .month-anchor {
            display: block;
            visibility: hidden;
            height: 0;
            margin: 0;
            padding: 0;
        }

        /* Hide sidebar when viewport is too narrow (sidebar would overlap centered content) */
        @media (max-width: 1140px) { .timeline-sidebar { display: none; } }
        @media (max-width: 640px)  { .page-layout { padding: 0; margin-top: .5rem; } }

        .donate-banner {
            display: flex;
            align-items: center;
            gap: .65rem;
            padding: .6rem 1rem;
            margin-bottom: 1rem;
            background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
            border: 1.5px solid #93c5fd;
            border-radius: 10px;
            font-size: .88rem;
            color: #1e40af;
        }
        .donate-heart { font-size: 1.2rem; flex-shrink: 0; color: #3b82f6; }
        .donate-msg { flex: 1; }
        .donate-btn {
            flex-shrink: 0;
            padding: .35rem .9rem;
            background: #3b82f6;
            color: #fff;
            font-weight: 700;
            font-size: .82rem;
            border-radius: 6px;
            text-decoration: none;
            white-space: nowrap;
        }
        .donate-btn:hover { background: #2563eb; }

        .post-card {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 2rem 2.5rem;
            margin-bottom: 1.5rem;
        }
        .post-meta {
            font-size: .78rem;
            color: #94a3b8;
            margin-bottom: .6rem;
            display: flex;
            align-items: center;
            gap: .75rem;
        }
        .post-title {
            font-size: 1.4rem;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: .85rem;
        }
        .post-body { line-height: 1.75; color: #334155; font-size: .97rem; overflow: hidden; }
        .post-body p  { margin-bottom: .85rem; }
        .post-body h2 { font-size: 1.2rem; margin: 1.2rem 0 .5rem; }
        .post-body h3 { font-size: 1rem; margin: 1rem 0 .4rem; }
        .post-body ul, .post-body ol { margin: .4rem 0 .85rem 1.5rem; }
        .post-body li { margin-bottom: .25rem; }
        .post-body a  { color: #2563eb; }
        .post-body hr { border: none; border-top: 1px solid #e2e8f0; margin: 1.2rem 0; }

        /* ── Constrain rich content so it never overflows the card ── */
        /* Images: scale down to container width, never overflow */
        .post-body img {
            max-width: 100%;
            height: auto;
            display: block;
            border-radius: 6px;
            margin: .5rem 0;
        }
        /* Wide tables: horizontal scroll inside the card */
        .post-body table {
            display: block;
            max-width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            border-collapse: collapse;
        }
        .post-body th,
        .post-body td {
            padding: .4rem .65rem;
            border: 1px solid #e2e8f0;
            white-space: nowrap;
            font-size: .87rem;
        }
        /* Code / pre blocks: horizontal scroll, no text wrap */
        .post-body pre {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            background: #f1f5f9;
            border-radius: 6px;
            padding: .75rem 1rem;
            font-size: .84rem;
            line-height: 1.5;
            margin-bottom: .85rem;
        }
        .post-body code {
            background: #f1f5f9;
            border-radius: 3px;
            padding: .1em .35em;
            font-size: .88em;
        }
        .post-body pre code {
            background: none;
            padding: 0;
            font-size: inherit;
        }
        /* Embedded iframes (YouTube etc): responsive 16:9 */
        .post-body iframe {
            max-width: 100%;
            border-radius: 6px;
        }
        .post-card.pinned { border-color: #fde68a; background: #fffbeb; }
        .pin-badge { font-size: .72rem; font-weight: 600; color: #b45309; background: #fef3c7; border: 1px solid #fde68a; border-radius: 4px; padding: .15rem .45rem; letter-spacing: .02em; }

        .post-actions {
            display: flex; gap: .5rem;
            margin-left: auto;
        }
        .post-actions a, .post-actions button {
            font-size: .75rem; padding: .25rem .65rem;
            border-radius: 5px; border: 1px solid #e2e8f0;
            background: #f8fafc; color: #64748b;
            cursor: pointer; text-decoration: none;
        }
        .post-actions a:hover, .post-actions button:hover {
            background: #f1f5f9; color: #1e293b;
        }
        .post-actions button.danger { border-color: #fca5a5; color: #ef4444; }
        .post-actions button.danger:hover { background: #fee2e2; }

        .no-posts {
            text-align: center;
            padding: 4rem 2rem;
            color: #94a3b8;
        }
        .no-posts p { font-size: 1rem; margin-top: .5rem; }

        /* ── Comments toggle ── */
        .comments-heading {
            cursor: pointer;
            user-select: none;
        }
        .comments-heading:hover .cmts-toggle-label { color: #2563eb; }
        .cmts-toggle-label {
            display: flex;
            align-items: center;
            gap: .4rem;
            color: #475569;
            font-size: .85rem;
            font-weight: 600;
            transition: color .12s;
        }
        .cmts-chevron {
            font-size: .65rem;
            color: #94a3b8;
            transition: transform .18s;
            display: inline-block;
            line-height: 1;
        }
        .comments-heading.open .cmts-chevron { transform: rotate(90deg); }

        /* This week's events */
        .week-preview {
            background: #fff; border: 1px solid #e2e8f0; border-radius: 12px;
            padding: 1.25rem 1.5rem; margin-bottom: 1.75rem;
        }
        .week-preview-header {
            display: flex; align-items: center; justify-content: space-between;
            margin-bottom: 1rem;
        }
        .week-preview-header h2 { font-size: 1rem; font-weight: 700; color: #0f172a; }
        .week-preview-header a  { font-size: .8rem; color: #2563eb; text-decoration: none; }
        .week-preview-header a:hover { text-decoration: underline; }
        .week-grid {
            display: grid; grid-template-columns: repeat(7, 1fr); gap: 4px;
        }
        .wk-cell {
            border: 1px solid #f1f5f9; border-radius: 8px;
            padding: .4rem .35rem; min-height: 68px; background: #fafafa;
            min-width: 0;
        }
        .wk-cell.wk-today { background: #eff6ff; border-color: #bfdbfe; }
        .wk-day {
            font-size: .72rem; font-weight: 700; color: #475569;
            margin-bottom: 4px; white-space: nowrap;
        }
        .wk-cell.wk-today .wk-day { color: #2563eb; }
        .wk-event {
            font-size: .65rem; padding: 1px 5px; border-radius: 3px;
            color: #fff; margin-bottom: 2px; display: block;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
            line-height: 1.6; text-decoration: none;
        }
        .wk-event:hover { filter: brightness(1.1); }
        .wk-more { font-size: .62rem; color: #94a3b8; }
        @media (max-width: 600px) {
            .week-preview { padding: 1rem; }
            /* Switch from a 7-col grid to a horizontal scroll strip */
            .week-grid {
                display: flex;
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
                gap: 5px;
                padding-bottom: 4px;
            }
            .wk-cell {
                flex: 0 0 82px;
                min-height: 60px;
                padding: .35rem .3rem;
            }
            .wk-day { font-size: .68rem; }
        }

    </style>
</head>
<body>

<?php $nav_active = 'home'; $nav_user = $user; require __DIR__ . '/_nav.php'; ?>

<?php if (!$user && get_setting('show_landing_page', '0') === '1'):
    require __DIR__ . '/_landing.php';
    require __DIR__ . '/_footer.php';
    echo '</body></html>';
    exit;
endif; ?>

<div class="page-layout">

<!-- ── Timeline sidebar ── -->
<?php if (!empty($tlMonths)): ?>
<aside class="timeline-sidebar">
    <div class="tl-box">
        <div class="tl-heading">Post Archive</div>
        <?php
        $tlCurYear = '';
        // Group months by year and output
        $tlByYear = [];
        foreach ($tlMonths as $row) {
            [$y, $m] = explode('-', $row['ym']);
            $tlByYear[$y][] = $row;
        }
        foreach ($tlByYear as $year => $months):
        ?>
        <div class="tl-year-label"><?= htmlspecialchars($year) ?></div>
        <div class="tl-list">
            <?php foreach ($months as $row):
                $label = date('F', mktime(0, 0, 0, (int)explode('-', $row['ym'])[1], 1));
            ?>
            <a href="/?month=<?= htmlspecialchars($row['ym']) ?>"
               class="tl-entry<?= $monthFilter === $row['ym'] ? ' tl-active' : '' ?>"
               data-month="<?= htmlspecialchars($row['ym']) ?>">
                <?= htmlspecialchars($label) ?>
                <span class="tl-count"><?= (int)$row['cnt'] ?></span>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
    </div>
</aside>
<?php endif; ?>

<div class="posts-wrap">

    <?php if ($monthFilter): ?>
    <div style="display:flex;align-items:center;gap:.75rem;margin-bottom:1.25rem;padding:.6rem 1rem;background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;font-size:.88rem;color:#1e40af">
        <span>&#128197; Showing posts from <strong><?= htmlspecialchars(date('F Y', mktime(0,0,0,(int)explode('-',$monthFilter)[1],1,(int)explode('-',$monthFilter)[0]))) ?></strong></span>
        <a href="/" style="margin-left:auto;color:#2563eb;text-decoration:none;font-size:.82rem;white-space:nowrap">&larr; All posts</a>
    </div>
    <?php endif; ?>

    <?php if (get_setting('show_upcoming_events', '1') === '1' && get_setting('show_calendar', '1') === '1' && !$monthFilter): ?>
    <!-- This week's events -->
    <div class="week-preview">
        <div class="week-preview-header">
            <h2>&#128197; Upcoming Events</h2>
            <?php if (get_setting('show_calendar', '1') === '1'): ?>
            <a href="/calendar.php">Full Calendar &rarr;</a>
            <?php endif; ?>
        </div>
        <div class="week-grid">
        <?php
        $wkCursor = clone $weekStart;
        for ($i = 0; $i < 7; $i++):
            $wds    = $wkCursor->format('Y-m-d');
            $wkEvs  = $wkByDate[$wds] ?? [];
        ?>
            <div class="wk-cell<?= $i === 0 ? ' wk-today' : '' ?>">
                <div class="wk-day"><?= $wkCursor->format('D M j') ?></div>
                <?php foreach (array_slice($wkEvs, 0, 3) as $ev): ?>
                    <?php
                    $occDate = $ev['occurrence_start'] ?? $ev['start_date'];
                    $evLink  = '/calendar.php?m=' . $wkCursor->format('Y-m')
                             . '&open=' . (int)$ev['id']
                             . '&date=' . urlencode($occDate);
                    ?>
                    <a href="<?= htmlspecialchars($evLink) ?>"
                       class="wk-event"
                       style="background:<?= htmlspecialchars($ev['color']) ?>"
                       title="<?= htmlspecialchars($ev['title']) ?>">
                        <?= htmlspecialchars($ev['title']) ?>
                    </a>
                <?php endforeach; ?>
                <?php if (count($wkEvs) > 3): ?>
                    <div class="wk-more">+<?= count($wkEvs) - 3 ?> more</div>
                <?php endif; ?>
            </div>
        <?php $wkCursor->modify('+1 day'); endfor; ?>
        </div>
    </div>
    <?php endif; /* show_upcoming_events && !monthFilter */ ?>

    <?php
    $_don_url = get_setting('donation_url', '');
    $_don_msg = get_setting('donation_message', '') ?: 'Enjoying Game Night? Help keep the lights on.';
    if ($_don_url !== ''):
    ?>
    <div class="donate-banner">
        <span class="donate-heart">&#10084;</span>
        <span class="donate-msg"><?= htmlspecialchars($_don_msg) ?></span>
        <a href="<?= htmlspecialchars($_don_url) ?>" target="_blank" rel="noopener" class="donate-btn">Donate</a>
    </div>
    <?php endif; ?>

    <?php if (empty($posts)): ?>
        <div class="no-posts">
            <div style="font-size:2.5rem">&#128196;</div>
            <p>No posts yet.</p>
            <?php if ($user && $user['role'] === 'admin'): ?>
                <a href="/admin_posts.php" class="btn btn-primary" style="margin-top:1rem">Create the first post</a>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <?php $tlPrevMonth = ''; foreach ($posts as $post):
            if (!$post['pinned']) {
                $tlPostMonth = (new DateTime($post['created_at'], new DateTimeZone('UTC')))->setTimezone($local_tz)->format('Y-m');
                if ($tlPostMonth !== $tlPrevMonth) {
                    $tlPrevMonth = $tlPostMonth;
                    echo '<div id="month-' . htmlspecialchars($tlPostMonth) . '" class="month-anchor"></div>';
                }
            }
        ?>
        <div class="post-card<?= $post['pinned'] ? ' pinned' : '' ?>" id="post-<?= (int)$post['id'] ?>">
            <div class="post-meta">
                <?php if ($post['pinned']): ?><span class="pin-badge">&#128204; Pinned</span><?php endif; ?>
                <span>&#128197; <?= htmlspecialchars((new DateTime($post['created_at'], new DateTimeZone('UTC')))->setTimezone($local_tz)->format('F j, Y')) ?></span>
                <?php if ($user && $user['role'] === 'admin'): ?>
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
            <?php
            $comments = $post_comments[$post['id']] ?? [];
            $redir    = '/' . ($monthFilter ? '?month=' . urlencode($monthFilter) : '') . '#post-' . (int)$post['id'];
            ?>
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
            </div>

        </div>
        <?php endforeach; ?>

        <div id="posts-sentinel" style="height:1px"></div>
        <div id="posts-loading" style="display:none;text-align:center;padding:1.5rem 0;color:#94a3b8;font-size:.88rem">
            Loading&hellip;
        </div>
    <?php endif; ?>

</div><!-- /.posts-wrap -->
</div><!-- /.page-layout -->

<script>
// ── Timeline active-month highlight (scroll-tracked) ─────────────────────────
<?php if (!$monthFilter): ?>
(function () {
    const tlLinks = document.querySelectorAll('.tl-entry[data-month]');
    if (!tlLinks.length) return;

    function updateActive() {
        const anchors = document.querySelectorAll('.month-anchor[id]');
        if (!anchors.length) return;

        // Detection line: 30% from the top of the viewport
        const line = window.innerHeight * 0.3;
        let activeYm = anchors[0].id.replace('month-', '');

        for (const anchor of anchors) {
            if (anchor.getBoundingClientRect().top <= line) {
                activeYm = anchor.id.replace('month-', '');
            }
        }

        let changed = false;
        tlLinks.forEach(a => {
            const isActive = a.dataset.month === activeYm;
            if (isActive && !a.classList.contains('tl-active')) changed = true;
            a.classList.toggle('tl-active', isActive);
        });

        // Scroll the sidebar box so the active entry stays visible
        if (changed) {
            const box        = document.querySelector('.tl-box');
            const activeLink = box && box.querySelector('.tl-entry.tl-active');
            if (activeLink && box) {
                // getBoundingClientRect gives viewport-relative positions;
                // subtracting box's top and adding scrollTop gives offset inside the scroll container
                const boxRect  = box.getBoundingClientRect();
                const linkRect = activeLink.getBoundingClientRect();
                const linkTop  = linkRect.top - boxRect.top + box.scrollTop;
                const pad      = 36;
                if (linkTop < box.scrollTop + pad) {
                    box.scrollTop = linkTop - pad;
                } else if (linkTop + activeLink.offsetHeight > box.scrollTop + box.clientHeight - pad) {
                    box.scrollTop = linkTop + activeLink.offsetHeight - box.clientHeight + pad;
                }
            }
        }
    }

    // Throttle to one update per animation frame
    let ticking = false;
    window.addEventListener('scroll', () => {
        if (!ticking) {
            requestAnimationFrame(() => { updateActive(); ticking = false; });
            ticking = true;
        }
    }, { passive: true });

    updateActive(); // set on initial load
})();
<?php endif; ?>

// ── Infinite scroll ───────────────────────────────────────────────────────────
(function () {
    const sentinel  = document.getElementById('posts-sentinel');
    const loading   = document.getElementById('posts-loading');
    if (!sentinel) return;

    const CHUNK      = <?= (int)$chunk ?>;
    const MONTH_PARAM = <?= json_encode($monthFilter ? '&month=' . $monthFilter : '') ?>;
    let offset      = <?= count($posts) ?>;
    let hasMore     = <?= json_encode($total > count($posts)) ?>;
    let busy        = false;
    let lastMonth   = <?= json_encode($tlPrevMonth ?? '') ?>;

    if (!hasMore) { sentinel.remove(); return; }

    async function loadMore() {
        if (busy || !hasMore) return;
        busy = true;
        loading.style.display = '';

        const url = '/posts_chunk.php?offset=' + offset +
                    '&limit=' + CHUNK + MONTH_PARAM +
                    '&prev_month=' + encodeURIComponent(lastMonth);
        try {
            const res  = await fetch(url);
            const html = await res.text();

            if (!html.trim()) {
                hasMore = false;
                sentinel.remove();
                return;
            }

            const tpl  = document.createElement('template');
            tpl.innerHTML = html;
            const frag = tpl.content;

            // Read the marker before inserting (it gets consumed with the fragment)
            const marker = frag.querySelector('[data-chunk-count]');
            const count  = marker ? parseInt(marker.dataset.chunkCount, 10) : 0;
            if (marker) {
                lastMonth = marker.dataset.lastMonth || lastMonth;
                marker.remove();
            }

            sentinel.parentNode.insertBefore(frag, sentinel);
            offset += count;

            if (count < CHUNK) {
                hasMore = false;
                sentinel.remove();
            }
        } catch (e) {
            console.error('posts_chunk fetch failed', e);
        } finally {
            busy = false;
            loading.style.display = 'none';
        }
    }

    const obs = new IntersectionObserver(entries => {
        if (entries[0].isIntersecting) loadMore();
    }, { rootMargin: '400px' });

    obs.observe(sentinel);
})();
</script>

<script>
function toggleComments(postId) {
    const body = document.getElementById('cmts-body-' + postId);
    const hdr  = body.previousElementSibling;
    const opening = body.style.display === 'none';
    body.style.display = opening ? '' : 'none';
    hdr.classList.toggle('open', opening);
}
</script>

<?php if ($user): ?>
<script>
const _csrf = <?= json_encode($csrf) ?>;

function editComment(id, btn) {
    const bodyEl  = document.getElementById('cbody-' + id);
    const origTxt = bodyEl.textContent;
    // Replace body with edit form
    bodyEl.innerHTML = '';
    const form = document.createElement('form');
    form.method = 'post';
    form.action = '/comment.php';
    form.style.cssText = 'margin:0';
    form.innerHTML = `
        <input type="hidden" name="csrf_token" value="${_csrf}">
        <input type="hidden" name="action" value="edit">
        <input type="hidden" name="comment_id" value="${id}">
        <input type="hidden" name="redirect" value="${location.pathname}${location.search}#post-${location.hash.replace('#','').split('post-')[1]||''}">
        <textarea name="body" required maxlength="2000"
            style="width:100%;min-height:60px;resize:vertical;font-size:.875rem;padding:.4rem .65rem;border:1px solid #2563eb;border-radius:6px;font-family:inherit;line-height:1.6">${origTxt.replace(/</g,'&lt;')}</textarea>
        <div style="display:flex;gap:.5rem;margin-top:.35rem">
            <button type="submit" class="btn btn-primary" style="font-size:.78rem;padding:.3rem .8rem">Save</button>
            <button type="button" class="btn btn-outline" style="font-size:.78rem;padding:.3rem .8rem"
                    onclick="cancelEdit(${id}, this)">Cancel</button>
        </div>`;
    bodyEl.appendChild(form);
    form.querySelector('textarea').focus();
    // Store original so cancel can restore it
    bodyEl.dataset.orig = origTxt;
    btn.style.display = 'none';
    btn._editBtn = btn;
}

function cancelEdit(id, cancelBtn) {
    const bodyEl = document.getElementById('cbody-' + id);
    bodyEl.textContent = bodyEl.dataset.orig;
    const actions = bodyEl.closest('.comment-content').querySelector('.comment-actions');
    actions.querySelectorAll('button[title="Edit"]').forEach(b => b.style.display = '');
}

function onSelChange(postId) {
    const sec     = document.getElementById('csec-' + postId);
    const all     = sec.querySelectorAll('.comment-sel');
    const checked = sec.querySelectorAll('.comment-sel:checked');
    const bar     = document.getElementById('bulk-' + postId);
    const countEl = document.getElementById('bulkcount-' + postId);
    bar.style.display = checked.length > 0 ? '' : 'none';
    countEl.textContent = checked.length + ' selected';
    const selAll = sec.querySelector('.sel-all');
    if (selAll) {
        selAll.indeterminate = checked.length > 0 && checked.length < all.length;
        selAll.checked = all.length > 0 && checked.length === all.length;
    }
}

function toggleSelAll(postId, cb) {
    document.getElementById('csec-' + postId)
        .querySelectorAll('.comment-sel').forEach(c => c.checked = cb.checked);
    onSelChange(postId);
}

function clearSel(postId) {
    document.getElementById('csec-' + postId)
        .querySelectorAll('.comment-sel').forEach(c => c.checked = false);
    onSelChange(postId);
}

function prepareBulkDelete(postId, form) {
    const ids = Array.from(
        document.getElementById('csec-' + postId).querySelectorAll('.comment-sel:checked')
    ).map(c => parseInt(c.value));
    if (!ids.length) return false;
    if (!confirm('Delete ' + ids.length + ' comment' + (ids.length !== 1 ? 's' : '') + '?')) return false;
    form.querySelector('[name="comment_ids"]').value = JSON.stringify(ids);
    return true;
}
</script>
<?php endif; ?>

<?php require __DIR__ . '/_footer.php'; ?>

</body>
</html>
