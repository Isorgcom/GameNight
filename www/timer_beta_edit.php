<?php
/**
 * Old address for the layout editor, kept so existing bookmarks keep working.
 * The page is now /timer_layouts.php. See TOURNAMENT_TIMER.md for the rename.
 */
$qs = (string)($_SERVER['QUERY_STRING'] ?? '');
header('Location: /timer_layouts.php' . ($qs !== '' ? '?' . $qs : ''), true, 301);
exit;
