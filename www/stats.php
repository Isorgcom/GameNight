<?php
require_once __DIR__ . '/auth.php';
$current = current_user();
if (!$current) { header('Location: /login.php'); exit; }
$db = get_db();
$first = $db->prepare('SELECT league_id FROM league_members WHERE user_id = ? AND user_id IS NOT NULL ORDER BY league_id LIMIT 1');
$first->execute([(int)$current['id']]);
$lid = $first->fetchColumn();
if ($lid) {
    header('Location: /league.php?id=' . (int)$lid . '&tab=stats');
} else {
    header('Location: /leagues.php');
}
exit;
