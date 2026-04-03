<?php
/**
 * Shared nav partial.
 * Before including, set:
 *   $nav_active — 'home' | 'posts' | 'site-settings' | 'settings' | ''
 *   $nav_user   — optional user array override; falls back to $current, then $user
 *   $site_name  — must already be set by the calling page
 */
$_nu                   = $nav_user ?? $current ?? $user ?? null;
$_active               = $nav_active ?? '';
$_banner               = get_setting('banner_path', '');
$_header_banner        = get_setting('header_banner_path', '');
$_header_banner_height = max(20, min(200, (int)get_setting('header_banner_height', '140')));

// Cache-busting version strings using file modification time
$_banner_v        = $_banner        ? @filemtime(__DIR__ . $_banner)        : 0;
$_header_banner_v = $_header_banner ? @filemtime(__DIR__ . $_header_banner) : 0;
$_nav_bg        = get_setting('nav_bg_color', '');
$_nav_text      = get_setting('nav_text_color', '');
$_accent        = get_setting('accent_color', '');
?>
<?php if ($_nav_bg || $_nav_text || $_accent || $_header_banner): ?>
<style>
<?php if ($_accent): ?>:root{--accent:<?= htmlspecialchars($_accent,ENT_QUOTES) ?>;--accent-h:<?= htmlspecialchars($_accent,ENT_QUOTES) ?>;}<?php endif; ?>
<?php if ($_nav_bg): ?>nav{background:<?= htmlspecialchars($_nav_bg,ENT_QUOTES) ?> !important;}<?php endif; ?>
<?php if ($_nav_text): ?>nav .brand,nav .brand:hover{color:<?= htmlspecialchars($_nav_text,ENT_QUOTES) ?> !important;}<?php endif; ?>
<?php if ($_header_banner): ?>
@media(min-width:769px){.nav-top{height:<?= $_header_banner_height ?>px !important;align-items:flex-start !important;padding-top:8px !important;}.nav-banner-img{max-height:<?= $_header_banner_height - 10 ?>px;}}
<?php endif; ?>
</style>
<?php endif; ?>
<nav<?= $_nu ? ' class="nav-has-user"' : '' ?>>
    <div class="nav-top">
        <a class="brand" href="/">
            <?php if ($_banner): ?>
                <img src="<?= htmlspecialchars($_banner) ?>?v=<?= $_banner_v ?>" alt="<?= htmlspecialchars($site_name) ?>"
                     style="max-height:38px;width:auto;display:block">
            <?php else: ?>
                <?= htmlspecialchars($site_name) ?>
            <?php endif; ?>
        </a>
        <?php if ($_header_banner): ?>
        <div style="flex:1;text-align:center;padding:0 .5rem;overflow:hidden">
            <img class="nav-banner-img" src="<?= htmlspecialchars($_header_banner) ?>?v=<?= $_header_banner_v ?>" alt="<?= htmlspecialchars($site_name) ?>">
        </div>
        <?php else: ?>
        <div style="flex:1"></div>
        <?php endif; ?>
        <div class="nav-user">
            <?php if ($_nu): ?>
                <span><?= htmlspecialchars($_nu['username']) ?></span>
                <div class="nav-dropdown-wrap">
                    <button class="nav-hamburger" onclick="this.nextElementSibling.classList.toggle('open')" title="Menu">&#9776;</button>
                    <div class="nav-dropdown">
                        <!-- Page links shown only on mobile (nav-links row hidden) -->
                        <a href="/" class="nav-mobile-link<?= $_active === 'home' ? ' active' : '' ?>">Home</a>
                        <?php if (get_setting('show_calendar', '1') === '1'): ?>
                        <a href="/calendar.php" class="nav-mobile-link<?= $_active === 'calendar' ? ' active' : '' ?>">Calendar</a>
                        <?php endif; ?>
                        <?php if ($_nu && $_nu['role'] === 'admin'): ?>
                        <a href="/admin_posts.php" class="nav-mobile-link<?= $_active === 'posts' ? ' active' : '' ?>">Posts</a>
                        <a href="/admin_settings.php" class="nav-mobile-link<?= $_active === 'site-settings' ? ' active' : '' ?>">Site Settings</a>
                        <?php endif; ?>
                        <div class="nav-mobile-divider"></div>
                        <a href="/settings.php"<?= $_active === 'settings' ? ' class="active"' : '' ?>>My Settings</a>
                        <a href="/logout.php" class="nav-dropdown-signout">Sign out</a>
                    </div>
                </div>
            <?php else: ?>
                <?php if (get_setting('allow_registration', '1') === '1'): ?>
                <a href="/register.php" class="btn btn-outline btn-sm">Sign Up</a>
                <?php endif; ?>
                <a href="/login.php" class="btn btn-outline btn-sm">Login</a>
            <?php endif; ?>
        </div>
    </div>
    <div class="nav-links">
        <a href="/"<?= $_active === 'home' ? ' class="active"' : '' ?>>Home</a>
        <?php if (get_setting('show_calendar', '1') === '1'): ?>
        <a href="/calendar.php"<?= $_active === 'calendar' ? ' class="active"' : '' ?>>Calendar</a>
        <?php endif; ?>
        <?php if ($_nu && $_nu['role'] === 'admin'): ?>
            <a href="/admin_posts.php"<?= $_active === 'posts' ? ' class="active"' : '' ?>>Posts</a>
            <a href="/admin_settings.php"<?= $_active === 'site-settings' ? ' class="active"' : '' ?>>Site Settings</a>
        <?php endif; ?>
    </div>
</nav>
<?php if ($_banner): ?>
<script>
(function(){
    var l = document.querySelector('link[rel~="icon"]');
    if (!l) { l = document.createElement('link'); l.rel = 'icon'; document.head.appendChild(l); }
    l.href = '<?= htmlspecialchars($_banner, ENT_QUOTES) ?>?v=<?= $_banner_v ?>';
})();
</script>
<?php endif; ?>
<script src="/nav.js"></script>
