<?php
/**
 * Old address for the layout endpoint, now /timer_layouts_dl.php. Only this
 * app's own scripts ever called it, and they were updated with the rename;
 * this exists for a browser still holding a cached copy of the old editor.
 *
 * 307 rather than 301: a redirect that changes a POST into a GET would drop
 * a layout somebody was saving.
 */
$qs = (string)($_SERVER['QUERY_STRING'] ?? '');
header('Location: /timer_layouts_dl.php' . ($qs !== '' ? '?' . $qs : ''), true, 307);
exit;
