<?php
/**
 * XML sitemap of the public (non-login) pages, served at /sitemap.xml via the
 * .htaccess rewrite. URLs are built from get_site_url() so they're correct on
 * any host. Login-gated app pages are intentionally omitted (see robots.txt).
 */
require_once __DIR__ . '/db.php';

header('Content-Type: application/xml; charset=utf-8');

$site = get_site_url();

// [path relative to root, priority, change frequency]
$pages = [
    ['',                '1.0', 'weekly'],
    ['help-hosts.php',  '0.8', 'monthly'],
    ['help-guests.php', '0.8', 'monthly'],
    ['league',          '0.7', 'weekly'],
    ['register.php',    '0.6', 'monthly'],
    ['login.php',       '0.3', 'yearly'],
    ['terms.php',       '0.2', 'yearly'],
    ['privacy.php',     '0.2', 'yearly'],
];

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($pages as [$path, $priority, $freq]) {
    $loc = $site . '/' . ltrim($path, '/');
    echo '  <url><loc>' . htmlspecialchars($loc, ENT_QUOTES | ENT_SUBSTITUTE) . '</loc>'
       . '<changefreq>' . $freq . '</changefreq>'
       . '<priority>' . $priority . '</priority></url>' . "\n";
}

// Public league landing pages (leagues that opted in via the settings toggle)
try {
    $pub = get_db()->query(
        "SELECT slug FROM leagues
         WHERE public_page = 1 AND is_hidden = 0 AND slug IS NOT NULL AND slug <> ''
         ORDER BY slug"
    )->fetchAll();
    foreach ($pub as $r) {
        echo '  <url><loc>' . htmlspecialchars($site . '/league/' . $r['slug'], ENT_QUOTES | ENT_SUBSTITUTE) . '</loc>'
           . '<changefreq>weekly</changefreq><priority>0.6</priority></url>' . "\n";
    }
} catch (Exception $e) {}
echo '</urlset>' . "\n";
