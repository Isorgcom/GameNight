<?php
define('APP_VERSION', '0.2023');

// Source for the "update available" check (public repo, no auth needed).
// run_update_check() in db.php fetches this, regexes out APP_VERSION, and
// caches it in site_settings; admins see a dot when the running version is older.
define('UPDATE_SOURCE_URL', 'https://raw.githubusercontent.com/Isorgcom/GameNight/main/www/version.php');
define('CHANGELOG_URL', 'https://github.com/Isorgcom/GameNight/blob/main/CHANGELOG.md');
