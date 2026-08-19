<?php
/**
 * PWA manifest, served as PHP so the name tracks the site_name setting.
 * Required for iOS Safari push (site must be installed to the home screen
 * before Notification permission can even be requested there); harmless
 * everywhere else and gives Android installs a proper icon.
 */
require_once __DIR__ . '/db.php';

header('Content-Type: application/manifest+json');
header('Cache-Control: public, max-age=3600');

echo json_encode([
    'name'             => get_setting('site_name', 'Game Night'),
    'short_name'       => get_setting('site_name', 'Game Night'),
    'start_url'        => '/',
    'scope'            => '/',
    'display'          => 'standalone',
    'background_color' => '#0f172a',
    'theme_color'      => '#0f172a',
    'icons'            => [
        ['src' => '/img/app-icon-192.png', 'sizes' => '192x192', 'type' => 'image/png'],
        ['src' => '/img/app-icon-512.png', 'sizes' => '512x512', 'type' => 'image/png'],
    ],
], JSON_UNESCAPED_SLASHES);
