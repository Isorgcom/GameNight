<?php
require_once __DIR__ . '/db.php';

$code = trim($_GET['code'] ?? '');
if ($code !== '') {
    $stmt = get_db()->prepare('SELECT target_url FROM short_links WHERE code = ?');
    $stmt->execute([$code]);
    $url = $stmt->fetchColumn();
    if ($url) {
        // Only redirect to our own origin (or a relative path). Short links are
        // created server-side for app URLs, so this never rejects a legitimate
        // link; it prevents s.php from becoming an open-redirect/phishing hop if
        // an attacker-influenced value ever reaches short_links.target_url.
        $host     = parse_url($url, PHP_URL_HOST);
        $siteHost = parse_url(rtrim(get_site_url(), '/'), PHP_URL_HOST);
        if ($host === null || strcasecmp((string)$host, (string)$siteHost) === 0) {
            header('Location: ' . $url, true, 301);
            exit;
        }
    }
}

header('Location: /');
exit;
