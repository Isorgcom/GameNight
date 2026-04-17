<?php
/* Shared footer partial — included by every full-page template. */
$_ftz  = new DateTimeZone(get_setting('timezone', 'UTC'));
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
