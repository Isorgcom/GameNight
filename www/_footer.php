<?php
/* Shared footer partial — included by every full-page template. */
$_ftz  = new DateTimeZone(display_timezone());
$_fnow = new DateTime('now', $_ftz);
?>
<footer>
    &copy; <?= $_fnow->format('Y') ?> <?= htmlspecialchars($site_name) ?>
    &nbsp;&mdash;&nbsp; <?= $_fnow->format('F j, Y g:i A') ?>
    &nbsp;&mdash;&nbsp; v<?= htmlspecialchars(APP_VERSION) ?>
    &nbsp;&mdash;&nbsp;
    <a href="/privacy.php" style="color:inherit;opacity:.65;text-decoration:none">Privacy Policy</a>
    &nbsp;&middot;&nbsp;
    <a href="/terms.php" style="color:inherit;opacity:.65;text-decoration:none">Terms &amp; Conditions</a>
    <?php $_fdon = get_setting('donation_url', ''); if ($_fdon !== ''): ?>
    &nbsp;&middot;&nbsp;
    <a href="<?= htmlspecialchars($_fdon) ?>" target="_blank" rel="noopener" style="color:inherit;opacity:.65;text-decoration:none">&#10084; Support this site</a>
    <?php endif; ?>
</footer>
<?php
/* In-app help bubbles: only for logged-in users, only on screens that have
   enabled tips. Tips are inlined as JSON so there's no extra round-trip. */
$_hb_user = function_exists('current_user') ? current_user() : null;
if ($_hb_user) {
    $_hb_screen = basename($_SERVER['SCRIPT_NAME'] ?? '', '.php');
    // Fresh = pinned tips + tips this user hasn't individually dismissed.
    // Any fresh tips auto-show; otherwise ship the full set behind the "?" pill.
    $_hb_fresh   = help_fresh_bubbles_for_screen((int)$_hb_user['id'], $_hb_screen);
    $_hb_bubbles = $_hb_fresh ?: help_bubbles_for_screen($_hb_screen);
    if ($_hb_bubbles) {
        $_hb_tips = array_map(function ($b) {
            return [
                'id'              => (int)$b['id'],
                'title'           => $b['title'] ?? '',
                'body'            => $b['body'] ?? '',
                'anchor_selector' => $b['anchor_selector'] ?? '',
                'idx'             => isset($b['bubble_index']) && $b['bubble_index'] !== null ? (int)$b['bubble_index'] : null,
            ];
        }, $_hb_bubbles);
        $_hb_payload = [
            'screen'    => $_hb_screen,
            'tips'      => $_hb_tips,
            'dismissed' => !$_hb_fresh,
            'csrf'      => csrf_token(),
            'preview'   => false,
        ];
        ?>
        <script>window.__help = <?= json_encode($_hb_payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
        <script src="/help-bubble.js?v=<?= htmlspecialchars(APP_VERSION) ?>" defer></script>
        <?php
    }
}

/* One-time timezone backfill: for a logged-in user who never set a timezone,
   detect the browser zone and store it. The endpoint only fills it when still
   empty, so an explicit Settings choice is never overwritten. Self-terminating —
   once stored, this block stops emitting on the next page load. */
if ($_hb_user && empty($_hb_user['timezone'])) {
    ?>
    <script>
    (function () {
        try {
            var tz = Intl.DateTimeFormat().resolvedOptions().timeZone;
            if (!tz) return;
            fetch('/set_timezone_dl.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'csrf_token=' + encodeURIComponent(<?= json_encode(csrf_token(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>) +
                      '&timezone=' + encodeURIComponent(tz)
            });
        } catch (e) {}
    })();
    </script>
    <?php
}
?>
<script src="/pk-dialogs.js?v=<?= htmlspecialchars(APP_VERSION) ?>" defer></script>
