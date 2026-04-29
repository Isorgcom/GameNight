<?php
require_once __DIR__ . '/auth.php';

$current = require_login();
if ($current['role'] !== 'admin') {
    http_response_code(403);
    exit('Access denied.');
}

$db        = get_db();
$site_name = get_setting('site_name', 'Game Night');

session_start_safe();
$flash = ['type' => '', 'msg' => ''];
if (!empty($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
}

// ── POST ─────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Invalid request token.'];
        header('Location: /admin_posts.php');
        exit;
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $title   = trim($_POST['title'] ?? '');
        $content = sanitize_html(trim($_POST['content'] ?? ''));
        $d = trim($_POST['post_date'] ?? '');
        $t = trim($_POST['post_time'] ?? '');
        $league_id = (int)($_POST['league_id'] ?? 0) ?: null;
        if ($title === '' || $content === '') {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Title and content are required.'];
        } else {
            $local_tz = new DateTimeZone(get_setting('timezone', 'UTC'));
            $utc_tz   = new DateTimeZone('UTC');
            $dt = ($d !== '')
                ? (new DateTime("$d " . ($t ?: '00:00'), $local_tz))->setTimezone($utc_tz)->format('Y-m-d H:i:s')
                : (new DateTime('now', $utc_tz))->format('Y-m-d H:i:s');
            $db->prepare('INSERT INTO posts (title, content, created_at, league_id, author_id) VALUES (?, ?, ?, ?, ?)')
               ->execute([$title, $content, $dt, $league_id, (int)$current['id']]);
            db_log_activity($current['id'], "created post: $title" . ($league_id ? " (league $league_id)" : ''));
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Post published.'];
        }
        header('Location: /admin_posts.php');
        exit;
    }

    if ($action === 'edit') {
        $id      = (int)($_POST['id'] ?? 0);
        $title   = trim($_POST['title'] ?? '');
        $content = sanitize_html(trim($_POST['content'] ?? ''));
        $d = trim($_POST['post_date'] ?? '');
        $t = trim($_POST['post_time'] ?? '');
        $league_id = (int)($_POST['league_id'] ?? 0) ?: null;
        if ($id <= 0 || $title === '' || $content === '') {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Title and content are required.'];
        } else {
            $local_tz = new DateTimeZone(get_setting('timezone', 'UTC'));
            $utc_tz   = new DateTimeZone('UTC');
            $dt = ($d !== '')
                ? (new DateTime("$d " . ($t ?: '00:00'), $local_tz))->setTimezone($utc_tz)->format('Y-m-d H:i:s')
                : (new DateTime('now', $utc_tz))->format('Y-m-d H:i:s');
            $pinned = isset($_POST['pinned']) ? 1 : 0;
            $db->prepare('UPDATE posts SET title=?, content=?, created_at=?, pinned=?, league_id=? WHERE id=?')
               ->execute([$title, $content, $dt, $pinned, $league_id, $id]);
            db_log_activity($current['id'], "edited post id: $id");
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Post updated.'];
        }
        header('Location: /admin_posts.php');
        exit;
    }

    if ($action === 'hide' || $action === 'unhide') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $db->prepare('UPDATE posts SET hidden=? WHERE id=?')->execute([$action === 'hide' ? 1 : 0, $id]);
            db_log_activity($current['id'], "$action post id: $id");
            $_SESSION['flash'] = ['type' => 'success', 'msg' => $action === 'hide' ? 'Post hidden.' : 'Post unhidden.'];
        }
        header('Location: /admin_posts.php');
        exit;
    }

    if ($action === 'pin' || $action === 'unpin') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $db->prepare('UPDATE posts SET pinned=? WHERE id=?')->execute([$action === 'pin' ? 1 : 0, $id]);
            db_log_activity($current['id'], "$action post id: $id");
            $_SESSION['flash'] = ['type' => 'success', 'msg' => $action === 'pin' ? 'Post pinned.' : 'Post unpinned.'];
        }
        header('Location: /admin_posts.php');
        exit;
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $row = $db->prepare('SELECT title, content FROM posts WHERE id=?');
            $row->execute([$id]);
            $post_row = $row->fetch();
            $title    = $post_row['title'] ?? $id;

            // Extract /uploads/ image srcs from this post's content
            $imgs_deleted = 0;
            if (!empty($post_row['content'])) {
                preg_match_all(
                    '/<img[^>]+src=["\']\/uploads\/([a-f0-9]{32}\.(jpg|jpeg|png|gif|webp))["\'][^>]*>/i',
                    $post_row['content'],
                    $matches
                );
                foreach (array_unique($matches[1]) as $filename) {
                    // Safety: verify filename contains no path traversal
                    if (basename($filename) !== $filename) continue;
                    // Only delete if no other post references this file
                    $inUse = $db->prepare(
                        "SELECT COUNT(*) FROM posts WHERE id != ? AND content LIKE ?"
                    );
                    $inUse->execute([$id, '%/uploads/' . $filename . '%']);
                    if ((int)$inUse->fetchColumn() > 0) continue;

                    $path = __DIR__ . '/uploads/' . $filename;
                    if (file_exists($path)) {
                        unlink($path);
                        $imgs_deleted++;
                    }
                }
            }

            $db->prepare("DELETE FROM comments WHERE type='post' AND content_id=?")->execute([$id]);
            $db->prepare('DELETE FROM posts WHERE id=?')->execute([$id]);
            db_log_activity($current['id'], "deleted post: $title" . ($imgs_deleted ? " ($imgs_deleted image(s) removed)" : ''));
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Post deleted.' . ($imgs_deleted ? " $imgs_deleted image(s) removed." : '')];
        }
        header('Location: /admin_posts.php');
        exit;
    }

    if ($action === 'bulk_delete') {
        $raw_ids = json_decode($_POST['ids'] ?? '[]', true);
        if (!is_array($raw_ids)) $raw_ids = [];
        $ids = array_values(array_filter(array_map('intval', $raw_ids), fn($id) => $id > 0));

        $deleted = 0;
        $imgs_deleted = 0;
        foreach ($ids as $id) {
            $row = $db->prepare('SELECT title, content FROM posts WHERE id=?');
            $row->execute([$id]);
            $post_row = $row->fetch();
            if (!$post_row) continue;

            if (!empty($post_row['content'])) {
                preg_match_all(
                    '/<img[^>]+src=["\']\/uploads\/([a-f0-9]{32}\.(jpg|jpeg|png|gif|webp))["\'][^>]*>/i',
                    $post_row['content'], $matches
                );
                foreach (array_unique($matches[1]) as $filename) {
                    if (basename($filename) !== $filename) continue;
                    $inUse = $db->prepare("SELECT COUNT(*) FROM posts WHERE id != ? AND content LIKE ?");
                    $inUse->execute([$id, '%/uploads/' . $filename . '%']);
                    if ((int)$inUse->fetchColumn() > 0) continue;
                    $path = __DIR__ . '/uploads/' . $filename;
                    if (file_exists($path)) { unlink($path); $imgs_deleted++; }
                }
            }

            $db->prepare("DELETE FROM comments WHERE type='post' AND content_id=?")->execute([$id]);
            $db->prepare('DELETE FROM posts WHERE id=?')->execute([$id]);
            $deleted++;
        }

        db_log_activity($current['id'], "bulk deleted $deleted post(s)" . ($imgs_deleted ? ", $imgs_deleted image(s) removed" : ''));
        $_SESSION['flash'] = ['type' => 'success', 'msg' => "$deleted post(s) deleted." . ($imgs_deleted ? " $imgs_deleted image(s) removed." : '')];
        header('Location: /admin_posts.php');
        exit;
    }

    header('Location: /admin_posts.php');
    exit;
}

// ── GET ───────────────────────────────────────────────────────────────────────
$edit_id   = (int)($_GET['edit'] ?? 0);
$edit_post = null;
if ($edit_id > 0) {
    $s = $db->prepare('SELECT * FROM posts WHERE id=?');
    $s->execute([$edit_id]);
    $edit_post = $s->fetch();
    if (!$edit_post) $edit_id = 0;
}

$posts    = $db->query('SELECT p.id, p.title, p.created_at, p.pinned, p.hidden, p.league_id, l.name AS league_name FROM posts p LEFT JOIN leagues l ON l.id = p.league_id ORDER BY p.pinned DESC, p.created_at DESC')->fetchAll();
$allLeagues = $db->query('SELECT id, name FROM leagues ORDER BY LOWER(name)')->fetchAll();
$token    = csrf_token();
$local_tz = new DateTimeZone(get_setting('timezone', 'UTC'));
$now_local = (new DateTime('now', $local_tz))->format('Y-m-d H:i:s');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($site_name) ?></title>
    <link rel="stylesheet" href="/style.css">
    <link href="/vendor/jodit/jodit.min.css" rel="stylesheet">
    <style>
        .hint { font-size: .78rem; color: #94a3b8; margin-top: .35rem; }

        /* Modal */
        .modal-overlay {
            display: none; position: fixed; inset: 0;
            background: rgba(0,0,0,.45); z-index: 200;
            align-items: flex-start; justify-content: center;
            padding: 2rem 1rem; overflow-y: auto;
        }
        .modal-overlay.open { display: flex; }
        .modal {
            background: #fff; border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0,0,0,.25);
            width: 100%; max-width: 860px; padding: 2rem;
            animation: modalIn .18s ease;
        }
        @keyframes modalIn {
            from { transform: translateY(-12px); opacity: 0; }
            to   { transform: none; opacity: 1; }
        }
        .modal-header {
            display: flex; align-items: center;
            justify-content: space-between; margin-bottom: 1.5rem;
        }
        .modal-header h2 { font-size: 1.25rem; }
        .modal-close {
            width: 32px; height: 32px; border-radius: 7px;
            border: none; background: #f1f5f9; cursor: pointer;
            font-size: 1.1rem; color: #64748b;
            display: flex; align-items: center; justify-content: center;
        }
        .modal-close:hover { background: #e2e8f0; }

        .action-btns { display: flex; gap: .4rem; }
        .btn-sm-text {
            font-size: .78rem; padding: .25rem .65rem;
            border-radius: 5px; border: 1px solid #e2e8f0;
            background: #f8fafc; color: #64748b;
            cursor: pointer; text-decoration: none; white-space: nowrap;
        }
        .btn-sm-text:hover { background: #f1f5f9; color: #1e293b; text-decoration: none; }
        .btn-sm-text.danger { border-color: #fca5a5; color: #ef4444; }
        .btn-sm-text.danger:hover { background: #fee2e2; }
        .btn-sm-text.muted { border-color: #e2e8f0; color: #94a3b8; }
        .btn-sm-text.muted:hover { background: #f1f5f9; color: #475569; }
        .hidden-badge {
            font-size: .68rem; font-weight: 600; color: #64748b;
            background: #f1f5f9; border: 1px solid #e2e8f0;
            border-radius: 4px; padding: .1rem .38rem; margin-right: .35rem;
            vertical-align: middle;
        }
        tr.post-hidden td { opacity: .5; }
        tr.post-hidden td:last-child { opacity: 1; }

        /* Search + bulk bar */
        .table-toolbar {
            display: flex; align-items: center; gap: .75rem;
            padding: .85rem 1.25rem; border-bottom: 1px solid #f1f5f9;
            flex-wrap: wrap;
        }
        .search-wrap { position: relative; flex: 1; min-width: 180px; }
        .search-wrap input {
            width: 100%; padding: .45rem .75rem .45rem 2rem;
            border: 1px solid #e2e8f0; border-radius: 7px;
            font-size: .875rem; background: #f8fafc; color: #1e293b;
            outline: none;
        }
        .search-wrap input:focus { border-color: #93c5fd; background: #fff; }
        .search-wrap::before {
            content: '\1F50D';
            position: absolute; left: .6rem; top: 50%;
            transform: translateY(-50%); font-size: .8rem; pointer-events: none;
        }
        .bulk-bar {
            display: none; align-items: center; gap: .6rem;
            padding: .55rem 1.25rem; background: #eff6ff;
            border-bottom: 1px solid #bfdbfe; font-size: .85rem; color: #1e40af;
        }
        .bulk-bar.visible { display: flex; }
        .bulk-count { font-weight: 600; }
        th.col-cb, td.col-cb {
            width: 36px; padding-left: 1.1rem !important; padding-right: 0 !important;
        }
        input.row-cb { accent-color: #2563eb; cursor: pointer; width: 15px; height: 15px; }
    </style>
</head>
<body>

<?php $nav_active = 'posts'; require __DIR__ . '/_nav.php'; ?>

<div class="dash-wrap">

    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem;flex-wrap:wrap;gap:1rem">
        <h1 style="font-size:1.5rem">Manage Posts</h1>
        <button class="btn btn-primary" onclick="openModal()">&#43; New Post</button>
    </div>

    <?php if ($flash['msg']): ?>
        <div class="alert alert-<?= $flash['type'] === 'error' ? 'error' : 'success' ?>"
             style="margin-bottom:1rem">
            <?= htmlspecialchars($flash['msg']) ?>
        </div>
    <?php endif; ?>

    <!-- Hidden bulk-delete form -->
    <form id="bulk-form" method="post" action="/admin_posts.php" style="display:none">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">
        <input type="hidden" name="action" value="bulk_delete">
        <input type="hidden" name="ids" id="bulk-ids" value="">
    </form>

    <div class="table-card">
        <?php if (empty($posts)): ?>
            <p style="padding:1rem 1.5rem;color:#64748b;font-size:.875rem">No posts yet.</p>
        <?php else: ?>

        <!-- Toolbar -->
        <div class="table-toolbar">
            <div class="search-wrap">
                <input type="text" id="post-search" placeholder="Search posts…" autocomplete="off">
            </div>
        </div>

        <!-- Bulk action bar (shown when rows selected) -->
        <div class="bulk-bar" id="bulk-bar">
            <span class="bulk-count" id="bulk-count-label">0 selected</span>
            <button class="btn-sm-text danger" onclick="bulkDelete()">Delete selected</button>
            <button class="btn-sm-text" onclick="clearSel()">Clear</button>
        </div>

        <table>
            <thead>
                <tr>
                    <th class="col-cb">
                        <input type="checkbox" class="row-cb" id="sel-all" title="Select all">
                    </th>
                    <th>#</th>
                    <th>Title</th>
                    <th>Date &amp; Time</th>
                    <th style="width:1rem"></th>
                    <th></th>
                </tr>
            </thead>
            <tbody id="posts-tbody">
            <?php foreach ($posts as $p): ?>
                <tr data-title="<?= htmlspecialchars(mb_strtolower($p['title'])) ?>"
                    class="<?= $p['hidden'] ? 'post-hidden' : '' ?>">
                    <td class="col-cb">
                        <input type="checkbox" class="row-cb post-cb" value="<?= (int)$p['id'] ?>" onchange="onCbChange()">
                    </td>
                    <td><?= (int)$p['id'] ?></td>
                    <td>
                        <?php if ($p['hidden']): ?>
                            <span class="hidden-badge" title="Hidden from public">Hidden</span>
                        <?php endif; ?>
                        <?php if ($p['pinned']): ?>
                            <span style="color:#f59e0b;margin-right:.35rem" title="Pinned">&#128204;</span>
                        <?php endif; ?>
                        <?php if (!empty($p['league_name'])): ?>
                            <span style="font-size:.7rem;color:#1e40af;background:#dbeafe;border:1px solid #93c5fd;border-radius:999px;padding:.1rem .5rem;margin-right:.35rem"><?= htmlspecialchars($p['league_name']) ?></span>
                        <?php endif; ?>
                        <?= htmlspecialchars($p['title']) ?>
                    </td>
                    <td style="white-space:nowrap"><?= htmlspecialchars(
                        (new DateTime($p['created_at'], new DateTimeZone('UTC')))
                            ->setTimezone($local_tz)
                            ->format('M j, Y g:i A')
                    ) ?></td>
                    <td></td>
                    <td>
                        <div class="action-btns">
                            <a href="/admin_posts.php?edit=<?= (int)$p['id'] ?>"
                               class="btn-sm-text">Edit</a>
                            <form method="post" action="/admin_posts.php" style="margin:0">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">
                                <input type="hidden" name="action" value="<?= $p['pinned'] ? 'unpin' : 'pin' ?>">
                                <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                                <button type="submit" class="btn-sm-text" title="<?= $p['pinned'] ? 'Unpin' : 'Pin to top' ?>">
                                    <?= $p['pinned'] ? 'Unpin' : 'Pin' ?>
                                </button>
                            </form>
                            <form method="post" action="/admin_posts.php" style="margin:0">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">
                                <input type="hidden" name="action" value="<?= $p['hidden'] ? 'unhide' : 'hide' ?>">
                                <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                                <button type="submit" class="btn-sm-text muted" title="<?= $p['hidden'] ? 'Make visible' : 'Hide from public' ?>">
                                    <?= $p['hidden'] ? 'Unhide' : 'Hide' ?>
                                </button>
                            </form>
                            <form method="post" action="/admin_posts.php" style="margin:0"
                                  onsubmit="return confirm('Delete this post?')">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                                <button type="submit" class="btn-sm-text danger">Delete</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

</div>

<!-- ── New / Edit Post Modal ── -->
<div class="modal-overlay" id="postModal">
    <div class="modal">
        <div class="modal-header">
            <h2 id="modalTitle">New Post</h2>
            <button class="modal-close" onclick="closeModal()">&#x2715;</button>
        </div>
        <form method="post" action="/admin_posts.php" id="postForm">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">
            <input type="hidden" name="action" id="formAction" value="add">
            <input type="hidden" name="id" id="formId" value="">

            <div class="form-group">
                <label for="f_title">Title</label>
                <input type="text" id="f_title" name="title" required autocomplete="off">
            </div>
            <div class="form-group">
                <label>Date &amp; Time</label>
                <div style="display:flex;gap:.75rem">
                    <input type="date" id="f_date" name="post_date" style="flex:1">
                    <input type="time" id="f_time" name="post_time" style="width:130px">
                </div>
                <p class="hint">Leave blank to use the current time. Past dates appear in their correct position in the feed. Future dates are held until that time.</p>
            </div>
            <div class="form-group">
                <label for="f_league">Post scope</label>
                <select id="f_league" name="league_id">
                    <option value="">Global (all users)</option>
                    <?php foreach ($allLeagues as $lg): ?>
                        <option value="<?= (int)$lg['id'] ?>"><?= htmlspecialchars($lg['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="hint">Global posts show to everyone. League-scoped posts only show to members of that league.</p>
            </div>
            <div class="form-group" id="pinRow" style="display:none">
                <label style="display:flex;align-items:center;gap:.6rem;cursor:pointer;font-weight:500">
                    <input type="checkbox" name="pinned" id="f_pinned" value="1"
                           style="width:16px;height:16px;accent-color:#f59e0b">
                    <span>&#128204; Pin to top</span>
                </label>
                <p class="hint">Pinned posts always appear above other posts in the feed.</p>
            </div>
            <div class="form-group">
                <label>Content</label>
                <textarea id="jodit-editor" name="content"></textarea>
            </div>
            <div style="display:flex;gap:.75rem;margin-top:1rem">
                <button type="submit" class="btn btn-primary" style="flex:1" id="submitBtn">Publish</button>
                <button type="button" class="btn btn-outline" onclick="closeModal()">Cancel</button>
            </div>
        </form>
    </div>
</div>

<?php require __DIR__ . '/_footer.php'; ?>

<script src="/vendor/jodit/jodit.min.js"></script>
<script>
const csrfToken = <?= json_encode($token) ?>;

// ── Jodit setup ───────────────────────────────────────────────────────────────
const editor = Jodit.make('#jodit-editor', {
    height: 420,
    toolbarButtonSize: 'middle',
    buttons: [
        'bold', 'italic', 'underline', 'strikethrough', '|',
        'ul', 'ol', '|',
        'outdent', 'indent', '|',
        'font', 'fontsize', 'paragraph', '|',
        'left', 'center', 'right', 'justify', '|',
        'table', 'link',
        {
            name: 'uploadImage',
            icon: 'image',
            tooltip: 'Insert Image',
            exec: function (editor) {
                const input = document.createElement('input');
                input.type = 'file';
                input.accept = 'image/jpeg,image/png,image/gif,image/webp';
                input.onchange = function () {
                    if (input.files && input.files[0]) uploadImageToEditor(input.files[0], editor);
                };
                input.click();
            }
        }, '|',
        'hr', 'eraser', 'copyformat', '|', 'source', '|',
        'undo', 'redo', '|',
        'source'
    ],
    uploader: {
        url: '/upload.php',
        method: 'POST',
        prepareData: function (formData) {
            formData.append('csrf_token', csrfToken);
        },
        isSuccess: function (resp) { return !!resp.url; },
        getMsg:    function (resp) { return resp.error || 'Upload failed'; },
        process:   function (resp) {
            return { files: [resp.url], baseurl: '', error: resp.error ? 1 : 0, msg: resp.error || '' };
        },
        defaultHandlerSuccess: function (data) {
            if (data.files && data.files.length) {
                const img = this.j.createInside.element('img');
                img.setAttribute('src', data.files[0]);
                this.j.s.insertNode(img);
            }
        }
    },
    enableDragAndDropFileToEditor: true,
    insertImageAsBase64URI: false
});

async function uploadImageToEditor(file, ed) {
    const form = new FormData();
    form.append('csrf_token', csrfToken);
    form.append('files[0]', file);
    try {
        const res  = await fetch('/upload.php', { method: 'POST', body: form });
        const data = await res.json();
        if (data.url) {
            const img = ed.createInside.element('img');
            img.setAttribute('src', data.url);
            ed.s.insertNode(img);
        } else {
            alert('Image upload failed: ' + (data.error || 'unknown error'));
        }
    } catch (err) {
        alert('Image upload failed: ' + err.message);
    }
}

// Flag set on submit so isDirty() won't prompt when the page redirects after save
let _submitting = false;
document.getElementById('postForm').addEventListener('submit', () => { _submitting = true; });

// ── Modal ─────────────────────────────────────────────────────────────────────
function openModal(id, title, date, content, pinned, leagueId) {
    const editing = !!id;
    document.getElementById('modalTitle').textContent  = editing ? 'Edit Post' : 'New Post';
    document.getElementById('formAction').value        = editing ? 'edit' : 'add';
    document.getElementById('formId').value            = id || '';
    document.getElementById('f_title').value           = title || '';
    document.getElementById('submitBtn').textContent   = editing ? 'Save Changes' : 'Publish';
    document.getElementById('pinRow').style.display    = editing ? '' : 'none';
    document.getElementById('f_pinned').checked        = !!pinned;
    var _lg = document.getElementById('f_league');
    if (_lg) _lg.value = leagueId ? String(leagueId) : '';

    const serverNow = <?= json_encode($now_local) ?>;
    const ts    = date || serverNow;
    const parts = ts.split(' ');
    document.getElementById('f_date').value = parts[0] ?? '';
    document.getElementById('f_time').value = (parts[1] ?? '').substring(0, 5);

    editor.value = content || '';

    document.getElementById('postModal').classList.add('open');
    document.getElementById('f_title').focus();
}

function isDirty() {
    if (_submitting) return false;
    const title   = document.getElementById('f_title').value.trim();
    const content = editor.value.trim();
    return title !== '' || content !== '';
}

function closeModal(force) {
    if (!force && isDirty()) {
        if (!confirm('You have unsaved changes. Discard them?')) return;
    }
    document.getElementById('postModal').classList.remove('open');
}

// Overlay click no longer closes the modal — prevents accidental data loss
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal(); });

// ── Search filter ────────────────────────────────────────────────────────────
document.getElementById('post-search')?.addEventListener('input', function () {
    const q = this.value.toLowerCase().trim();
    document.querySelectorAll('#posts-tbody tr').forEach(tr => {
        tr.style.display = (!q || tr.dataset.title.includes(q)) ? '' : 'none';
    });
    syncSelAll();
});

// ── Checkbox management ───────────────────────────────────────────────────────
function visibleCbs() {
    return Array.from(document.querySelectorAll('#posts-tbody tr'))
        .filter(tr => tr.style.display !== 'none')
        .map(tr => tr.querySelector('.post-cb'));
}

function onCbChange() {
    syncSelAll();
    updateBulkBar();
}

function syncSelAll() {
    const cbs     = visibleCbs();
    const checked = cbs.filter(c => c.checked);
    const selAll  = document.getElementById('sel-all');
    if (!selAll) return;
    selAll.checked       = cbs.length > 0 && checked.length === cbs.length;
    selAll.indeterminate = checked.length > 0 && checked.length < cbs.length;
    updateBulkBar();
}

document.getElementById('sel-all')?.addEventListener('change', function () {
    visibleCbs().forEach(cb => cb.checked = this.checked);
    updateBulkBar();
});

function updateBulkBar() {
    const checked = document.querySelectorAll('.post-cb:checked');
    const bar     = document.getElementById('bulk-bar');
    const label   = document.getElementById('bulk-count-label');
    if (!bar) return;
    bar.classList.toggle('visible', checked.length > 0);
    label.textContent = checked.length + ' selected';
}

function clearSel() {
    document.querySelectorAll('.post-cb').forEach(cb => cb.checked = false);
    const selAll = document.getElementById('sel-all');
    if (selAll) { selAll.checked = false; selAll.indeterminate = false; }
    updateBulkBar();
}

function bulkDelete() {
    const ids = Array.from(document.querySelectorAll('.post-cb:checked')).map(c => parseInt(c.value));
    if (!ids.length) return;
    if (!confirm('Delete ' + ids.length + ' post' + (ids.length !== 1 ? 's' : '') + '? This cannot be undone.')) return;
    document.getElementById('bulk-ids').value = JSON.stringify(ids);
    document.getElementById('bulk-form').submit();
}

<?php if ($edit_post): ?>
openModal(
    <?= (int)$edit_post['id'] ?>,
    <?= json_encode($edit_post['title']) ?>,
    <?= json_encode((new DateTime($edit_post['created_at'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(get_setting('timezone', 'UTC')))->format('Y-m-d H:i:s')) ?>,
    <?= json_encode($edit_post['content']) ?>,
    <?= (int)$edit_post['pinned'] ?>,
    <?= (int)($edit_post['league_id'] ?? 0) ?>
);
<?php endif; ?>
</script>

</body>
</html>
