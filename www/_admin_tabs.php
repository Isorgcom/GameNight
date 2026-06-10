<?php
/**
 * Shared Site Settings tab bar.
 *
 * Included by admin_settings.php (its many panel tabs), admin_api_keys.php, and
 * admin_help.php so the tab strip stays visible across all of them. Set the
 * active tab before including:
 *   $admin_tab — one of: dashboard | reports | general | appearance | logs |
 *                users | events | leagues | communication | cron | backup |
 *                apikeys | help
 * Styles for .tabs / .tab-btn live in style.css so every page gets them.
 */
$_atab = $admin_tab ?? '';
$_atabs = [
    ['key' => 'dashboard',     'href' => '/admin_settings.php?tab=dashboard',   'label' => 'Site Settings'],
    ['key' => 'reports',       'href' => '/admin_settings.php?tab=reports',     'label' => 'Reports'],
    ['key' => 'general',       'href' => '/admin_settings.php?tab=general',     'label' => 'General'],
    ['key' => 'appearance',    'href' => '/admin_settings.php?tab=appearance',  'label' => 'Appearance'],
    ['key' => 'logs',          'href' => '/admin_settings.php?tab=logs',        'label' => 'Logs'],
    ['key' => 'users',         'href' => '/admin_settings.php?tab=users',       'label' => 'Users'],
    ['key' => 'events',        'href' => '/admin_settings.php?tab=events',      'label' => 'Events'],
    ['key' => 'leagues',       'href' => '/admin_settings.php?tab=leagues',     'label' => 'Leagues'],
    ['key' => 'communication', 'href' => '/admin_settings.php?tab=email',       'label' => 'Communication'],
    ['key' => 'cron',          'href' => '/admin_settings.php?tab=cron',        'label' => 'Cron'],
    ['key' => 'backup',        'href' => '/admin_settings.php?tab=backup',      'label' => 'Backup'],
    ['key' => 'apikeys',       'href' => '/admin_api_keys.php',                 'label' => 'API Keys'],
    ['key' => 'help',          'href' => '/admin_help.php',                     'label' => 'Help Tips'],
];
?>
<div class="tabs">
    <?php foreach ($_atabs as $t): ?>
    <a href="<?= htmlspecialchars($t['href']) ?>" class="tab-btn<?= $_atab === $t['key'] ? ' active' : '' ?>"><?= htmlspecialchars($t['label']) ?></a>
    <?php endforeach; ?>
</div>
