<?php
require_once __DIR__ . '/auth.php';

$current   = require_login();
$db        = get_db();
$site_name = get_setting('site_name', 'Game Night');
$uid       = (int)$current['id'];
$isAdmin   = ($current['role'] ?? '') === 'admin';

$league_id = (int)($_GET['id'] ?? 0);
$isEdit    = $league_id > 0;
$league    = null;

if ($isEdit) {
    $L = $db->prepare('SELECT * FROM leagues WHERE id = ?');
    $L->execute([$league_id]);
    $league = $L->fetch();
    if (!$league) { http_response_code(404); echo 'League not found'; exit; }
    $role = league_role($league_id, $uid);
    if (!$isAdmin && $role !== 'owner') { http_response_code(403); echo 'Not allowed'; exit; }
}

$csrf = csrf_token();
$vals = $league ?: [
    'name' => '', 'description' => '',
    'default_visibility' => 'league', 'approval_mode' => 'manual',
    'is_hidden' => 0,
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $isEdit ? 'Edit' : 'Create' ?> League — <?= htmlspecialchars($site_name) ?></title>
    <link rel="stylesheet" href="/style.css">
    <style>
        .le-wrap { max-width: 560px; margin: 1.5rem auto; padding: 0 1rem; }
        .le-card { background: #fff; border: 1.5px solid #e2e8f0; border-radius: 10px; padding: 1.25rem; }
        .le-card h1 { font-size: 1.3rem; font-weight: 700; margin: 0 0 1rem; }
        .le-row { margin-bottom: 1rem; }
        .le-row label { display: block; font-weight: 600; color: #334155; margin-bottom: .3rem; font-size: .9rem; }
        .le-row input[type=text], .le-row textarea, .le-row select {
            width: 100%; padding: .55rem; border: 1.5px solid #cbd5e1; border-radius: 6px; font: inherit;
        }
        .le-row textarea { min-height: 90px; resize: vertical; }
        .le-hint { font-size: .75rem; color: #64748b; margin-top: .25rem; }
        .le-toggle { display: flex; align-items: center; gap: .5rem; }
        .le-actions { display: flex; gap: .5rem; justify-content: flex-end; margin-top: 1rem; }
        .le-btn { background: #2563eb; color: #fff; border: none; border-radius: 6px; padding: .55rem 1.1rem; font-size: .9rem; font-weight: 600; cursor: pointer; text-decoration: none; }
        .le-btn:hover { background: #1d4ed8; }
        .le-btn-ghost { background: transparent; color: #475569; border: 1.5px solid #cbd5e1; }
        .le-btn-ghost:hover { background: #f1f5f9; }
        .le-err { color: #dc2626; font-size: .9rem; margin-bottom: .75rem; display: none; }
    </style>
</head>
<body>

<?php $nav_active = 'leagues'; $nav_user = $current; require __DIR__ . '/_nav.php'; ?>

<div class="le-wrap">
    <div class="le-card">
        <h1><?= $isEdit ? 'Edit' : 'Create' ?> League</h1>
        <div class="le-err" id="err"></div>
        <form id="f" onsubmit="return submitForm(event)">
            <div class="le-row">
                <label>Name
                    <input type="text" name="name" required maxlength="120" value="<?= htmlspecialchars($vals['name']) ?>">
                </label>
            </div>
            <div class="le-row">
                <label>Description
                    <textarea name="description" placeholder="Optional — shown on the browse directory"><?= htmlspecialchars($vals['description'] ?? '') ?></textarea>
                </label>
            </div>
            <div class="le-row">
                <label>Approval mode
                    <select name="approval_mode">
                        <option value="manual" <?= $vals['approval_mode']==='manual' ? 'selected' : '' ?>>Manual — owner/managers approve each request</option>
                        <option value="auto"   <?= $vals['approval_mode']==='auto'   ? 'selected' : '' ?>>Auto — anyone can join instantly</option>
                    </select>
                </label>
            </div>
            <div class="le-row">
                <label class="le-toggle">
                    <input type="checkbox" name="is_hidden" value="1" <?= (int)$vals['is_hidden'] === 1 ? 'checked' : '' ?>>
                    Hidden from the public Browse directory
                </label>
                <div class="le-hint">Members still see it in their My Leagues. Non-members cannot find it unless you share the direct link.</div>
            </div>
            <div class="le-actions">
                <a class="le-btn le-btn-ghost" href="<?= $isEdit ? '/league.php?id=' . $league_id : '/leagues.php' ?>">Cancel</a>
                <button type="submit" class="le-btn"><?= $isEdit ? 'Save changes' : 'Create league' ?></button>
            </div>
        </form>
    </div>
</div>

<script>
var CSRF = <?= json_encode($csrf) ?>;
var IS_EDIT = <?= $isEdit ? 'true' : 'false' ?>;
var LEAGUE_ID = <?= $isEdit ? $league_id : 0 ?>;

function submitForm(e) {
    e.preventDefault();
    var f = document.getElementById('f');
    var err = document.getElementById('err');
    err.style.display = 'none';
    var fd = new FormData(f);
    fd.append('csrf_token', CSRF);
    fd.append('action', IS_EDIT ? 'update_league' : 'create_league');
    if (IS_EDIT) fd.append('league_id', LEAGUE_ID);
    fetch('/leagues_dl.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(j) {
            if (j.ok) {
                var id = IS_EDIT ? LEAGUE_ID : j.league_id;
                location.href = '/league.php?id=' + id;
            } else {
                err.textContent = j.error || 'Failed';
                err.style.display = 'block';
            }
        })
        .catch(function() {
            err.textContent = 'Network error';
            err.style.display = 'block';
        });
    return false;
}
</script>

<?php require __DIR__ . '/_footer.php'; ?>
</body>
</html>
