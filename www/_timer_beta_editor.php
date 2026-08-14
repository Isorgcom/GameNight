<?php
/**
 * The Timer BETA layout editor body — shared by timer_beta_edit.php (library
 * editing, no event context) and event_display.php (same editor below the
 * event's layout-binding bar). Caller must have required auth.php and set
 * $current; pages include this inside <body> and keep their own <head>.
 * The editor JS boots purely off these element ids.
 */
$csrf = csrf_token();
?>
<div class="tbe-wrap">
    <div class="tbe-header">
        <h1>Timer Layout Editor <span class="tb-badge">BETA</span></h1>
        <div class="tbe-header-controls">
            <select id="tbeLoad" title="Load a layout"><option value="">Load&hellip;</option></select>
            <input type="text" id="tbeName" maxlength="80" placeholder="Layout name">
            <?php /* "Save layout", not "Save": embedded in the check-in Setup
                     editor this sits a few inches from the button that saves the
                     GAME, and two unqualified Saves on one screen is a coin
                     flip. Named the same standalone, where it is also true. */ ?>
            <button id="tbeSave" class="tbe-btn tbe-btn-primary">Save layout</button>
            <button id="tbeSaveCopy" class="tbe-btn">Save layout as copy</button>
            <button id="tbeExport" class="tbe-btn" title="Download this layout as a file">Export</button>
            <button id="tbeImport" class="tbe-btn" title="Load a layout from a .gntimer.json file exported from here or another install">Import</button>
            <?php /* No accept filter ON PURPOSE. iOS greys out any extension it has
                     no registered type for, and a double extension like
                     `.gntimer.json` is exactly the kind it gets wrong, so the file
                     could be browsed to but never selected on an iPhone or iPad.
                     The format is checked from the file's CONTENTS anyway (see
                     importFile's handler), so the filter bought nothing and cost
                     the feature on touch devices. */ ?>
            <input type="file" id="tbeImportFile" hidden>
            <button id="tbeDelete" class="tbe-btn tbe-btn-danger">Delete</button>
            <a class="tbe-btn tbe-btn-ghost" href="/timer_beta.php" target="_blank">Open display</a>
            <a class="tbe-btn tbe-btn-ghost" href="/help-timer.php" target="_blank" title="Elements, conditions, artwork, casting — the whole guide">Help</a>
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
            <div id="tbeTriggers" class="tbe-triggers-pane"></div>
        </div>

        <div class="tbe-side">
            <div class="tbe-panel">
                <div class="tbe-panel-head">
                    <span>Structure</span>
                    <span class="tbe-panel-actions">
                        <button id="tbeHelpBtn" class="tbe-mini" title="What everything in a layout means">?</button>
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
