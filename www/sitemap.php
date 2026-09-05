<?php
/**
 * XML sitemap of the public (non-login) pages, served at /sitemap.xml via the
 * .htaccess rewrite. URLs are built from get_site_url() so they're correct on
 * any host. Login-gated app pages are intentionally omitted (see robots.txt),
 * and so is login.php: a sign-in form is not a landing page.
 *
 * <lastmod> comes from the mtime of the file that renders each page, which
 * moves with every deploy that touched it, and for league pages from their
 * newest event. A crawler uses it to decide what to re-fetch, so a wrong date
 * is worse than none; anything unknown is simply left out.
 */
require_once __DIR__ . '/db.php';

header('Content-Type: application/xml; charset=utf-8');

$site = get_site_url();

// [path relative to root, priority, change frequency, file whose mtime dates it]
$pages = [
    ['',                '1.0', 'weekly',  '_landing.php'],
    ['help-hosts.php',  '0.8', 'monthly', 'help-hosts.php'],
    ['help-guests.php', '0.8', 'monthly', 'help-guests.php'],
    ['help-timer.php',  '0.8', 'monthly', 'help-timer.php'],
    ['league',          '0.7', 'weekly',  'league_public.php'],
    ['register.php',    '0.5', 'monthly', 'register.php'],
    ['terms.php',       '0.2', 'yearly',  'terms.php'],
    ['privacy.php',     '0.2', 'yearly',  'privacy.php'],
];

function sitemap_url(string $loc, string $freq, string $priority, ?string $lastmod): string {
    $x = '  <url><loc>' . htmlspecialchars($loc, ENT_QUOTES | ENT_SUBSTITUTE) . '</loc>';
    if ($lastmod !== null && $lastmod !== '') $x .= '<lastmod>' . htmlspecialchars($lastmod, ENT_QUOTES | ENT_SUBSTITUTE) . '</lastmod>';
    return $x . '<changefreq>' . $freq . '</changefreq><priority>' . $priority . '</priority></url>' . "\n";
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($pages as [$path, $priority, $freq, $file]) {
    $mtime = @filemtime(__DIR__ . '/' . $file);
    echo sitemap_url($site . '/' . ltrim($path, '/'), $freq, $priority, $mtime ? gmdate('Y-m-d', $mtime) : null);
}

// League landing pages: only leagues whose owner turned on BOTH the public page
// and "let search engines list this page" (leagues.seo_index). Public alone
// means reachable by link; listing a page of players' names is the owner's call.
try {
    $pub = get_db()->query(
        "SELECT l.slug,
                (SELECT MAX(e.created_at) FROM events e WHERE e.league_id = l.id) AS newest_event
           FROM leagues l
          WHERE l.public_page = 1 AND l.is_hidden = 0 AND l.seo_index = 1
            AND l.slug IS NOT NULL AND l.slug <> ''
          ORDER BY l.slug"
    )->fetchAll();
    foreach ($pub as $r) {
        $lm = null;
        if (!empty($r['newest_event'])) {
            $ts = strtotime((string)$r['newest_event']);
            if ($ts) $lm = gmdate('Y-m-d', $ts);
        }
        echo sitemap_url($site . '/league/' . $r['slug'], 'weekly', '0.6', $lm);
    }
} catch (Exception $e) {}
echo '</urlset>' . "\n";
