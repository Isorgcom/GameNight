<?php
/**
 * Timer BETA layout editor (phase B).
 *
 * The preview pane is the real display page (timer_beta.php?embed=1) in an
 * iframe, so what you see is exactly what a TV gets — same renderer, same
 * fit-to-box, same vh sizing, driven through window.TBPreview (same origin).
 *
 * The editor never touches timer state; it only reads/writes timer_layouts
 * through timer_beta_dl.php, which sanitizes every save server-side.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_login();
$db = get_db();
$current = current_user();
$site_name = get_setting('site_name', 'Game Night');
$csrf = csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Timer BETA Layout Editor &mdash; <?= htmlspecialchars($site_name) ?></title>
    <link rel="stylesheet" href="/style.css?v=<?= htmlspecialchars(APP_VERSION . '.' . (@filemtime(__DIR__ . '/style.css') ?: 0)) ?>">
    <link rel="stylesheet" href="/timer_beta_edit.css?v=<?= htmlspecialchars(APP_VERSION . '.' . (@filemtime(__DIR__ . '/timer_beta_edit.css') ?: 0)) ?>">
</head>
<body class="tbe-body">
<?php $nav_active = 'timer-beta'; $nav_user = $current; require __DIR__ . '/_nav.php'; ?>

<div class="tbe-wrap">
    <div class="tbe-header">
        <h1>Timer Layout Editor <span class="tb-badge">BETA</span></h1>
        <div class="tbe-header-controls">
            <select id="tbeLoad" title="Load a layout"><option value="">Load&hellip;</option></select>
            <input type="text" id="tbeName" maxlength="80" placeholder="Layout name">
            <button id="tbeSave" class="tbe-btn tbe-btn-primary">Save</button>
            <button id="tbeSaveCopy" class="tbe-btn">Save as copy</button>
            <button id="tbeExport" class="tbe-btn" title="Download this layout as a file">Export</button>
            <button id="tbeImport" class="tbe-btn" title="Load a layout from a file">Import</button>
            <input type="file" id="tbeImportFile" accept="application/json,.json" hidden>
            <button id="tbeDelete" class="tbe-btn tbe-btn-danger">Delete</button>
            <a class="tbe-btn tbe-btn-ghost" href="/timer_beta.php" target="_blank">Open display</a>
        </div>
    </div>

    <div class="tbe-main">
        <div class="tbe-preview-pane">
            <div class="tbe-preview-stage">
                <div class="tbe-preview-frame">
                    <iframe id="tbeFrame" src="/timer_beta.php?embed=1" title="Layout preview"></iframe>
                </div>
            </div>
            <div class="tbe-statebar">
                <span>Preview state:</span>
                <button data-state="normal" class="tbe-chip active">Running</button>
                <button data-state="paused" class="tbe-chip">Paused</button>
                <button data-state="break" class="tbe-chip">On break</button>
                <button data-state="over" class="tbe-chip">Game over</button>
                <span class="tbe-hint">Click any part of the preview to select it.</span>
            </div>
        </div>

        <div class="tbe-side">
            <div class="tbe-panel">
                <div class="tbe-panel-head">
                    <span>Structure</span>
                    <span class="tbe-panel-actions">
                        <button id="tbeUndo" class="tbe-mini" title="Undo (Ctrl+Z)">&#8630;</button>
                    </span>
                </div>
                <div id="tbeScreens" class="tbe-screens"></div>
                <div id="tbeTree" class="tbe-tree"></div>
                <div class="tbe-addbar">
                    <button id="tbeAddCell" class="tbe-mini">+ Cell</button>
                    <button id="tbeAddRow"  class="tbe-mini">+ Row</button>
                    <button id="tbeAddCol"  class="tbe-mini">+ Column</button>
                    <button id="tbeDup"     class="tbe-mini">Duplicate</button>
                    <button id="tbeUp"      class="tbe-mini">&#9650;</button>
                    <button id="tbeDown"    class="tbe-mini">&#9660;</button>
                    <button id="tbeRemove"  class="tbe-mini tbe-mini-danger">Remove</button>
                </div>
            </div>
            <div class="tbe-panel">
                <div class="tbe-panel-head"><span id="tbeInspTitle">Inspector</span></div>
                <div id="tbeInspector" class="tbe-inspector">
                    <p class="tbe-empty">Select something in the preview or the structure tree.</p>
                </div>
            </div>
        </div>
    </div>
</div>

<script nonce="<?= csp_nonce() ?>">
var TBE_CSRF = <?= json_encode($csrf) ?>;
var TBE_IS_ADMIN = <?= json_encode($current['role'] === 'admin') ?>;
</script>
<script src="/timer_beta_edit.js?v=<?= htmlspecialchars(APP_VERSION . '.' . (@filemtime(__DIR__ . '/timer_beta_edit.js') ?: 0)) ?>" defer></script>

<?php require __DIR__ . '/_footer.php'; ?>
</body>
</html>
