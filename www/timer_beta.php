<?php
/**
 * Old address for the layout display, kept so nothing that was printed,
 * bookmarked or cast before v1.1.0 breaks. The page itself is now
 * /timer.php — the Tournament Timer. See its header for the rename.
 *
 * 301 with the query string intact, so ?key=, ?event_id= and ?embed= all
 * survive the hop.
 */
$qs = (string)($_SERVER['QUERY_STRING'] ?? '');
header('Location: /timer.php' . ($qs !== '' ? '?' . $qs : ''), true, 301);
exit;
