<?php
require_once __DIR__ . '/db.php';

$code = trim($_GET['code'] ?? '');
if ($code !== '') {
    $stmt = get_db()->prepare('SELECT target_url FROM short_links WHERE code = ?');
    $stmt->execute([$code]);
    $url = $stmt->fetchColumn();
    if ($url) {
        header('Location: ' . $url, true, 301);
        exit;
    }
}

header('Location: /');
exit;
