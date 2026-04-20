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
// $_is_mobile is set in auth.php
?>
<?php if ($_nav_bg || $_nav_text || $_accent || $_header_banner || $_banner): ?>
<style>
.nav-collapse-banner{max-height:38px;width:auto}
<?php if ($_accent): ?>:root{--accent:<?= htmlspecialchars($_accent,ENT_QUOTES) ?>;--accent-h:<?= htmlspecialchars($_accent,ENT_QUOTES) ?>;}<?php endif; ?>
<?php if ($_nav_bg): ?>nav{background:<?= htmlspecialchars($_nav_bg,ENT_QUOTES) ?> !important;}<?php endif; ?>
<?php if ($_nav_text): ?>nav .brand,nav .brand:hover{color:<?= htmlspecialchars($_nav_text,ENT_QUOTES) ?> !important;}<?php endif; ?>
<?php if ($_header_banner && !$_is_mobile): ?>
.nav-top{height:<?= $_header_banner_height ?>px !important;align-items:flex-start !important;padding-top:8px !important;}
<?php endif; ?>
</style>
<?php endif; ?>
<?php if (!$_nu && get_setting('show_landing_page', '0') === '1'): ?>
<!-- SaaS landing mode: no nav for guests -->
<?php return; endif; ?>
<nav<?= $_nu ? ' class="nav-has-user"' : '' ?> id="mainNav">
    <div class="nav-top">
        <?php if (!$_banner): ?>
        <a class="brand nav-collapsible" href="/">
            <?= htmlspecialchars($site_name) ?>
        </a>
        <?php endif; ?>
        <?php if ($_header_banner): ?>
        <div class="nav-banner-wrap" style="flex:1;min-width:0;overflow:hidden;text-align:center;padding:0 .5rem">
            <img class="nav-banner-img" src="<?= htmlspecialchars($_header_banner) ?>?v=<?= $_header_banner_v ?>" alt="<?= htmlspecialchars($site_name) ?>"
                 style="max-height:<?= $_is_mobile ? '45' : ($_header_banner_height - 10) ?>px;width:auto;display:block;margin:0 auto;">
        </div>
        <?php else: ?>
        <div class="nav-collapsible" style="flex:1"></div>
        <?php endif; ?>
        <div class="nav-user">
            <?php if ($_nu): ?>
                <span class="nav-collapsible"><?= htmlspecialchars($_nu['username']) ?></span>
                <div class="nav-dropdown-wrap">
                    <button class="nav-hamburger" title="Menu" onclick="var d=this.nextElementSibling;d.style.display=d.style.display==='block'?'none':'block';">&#9776;</button>
                    <div class="nav-dropdown">
                        <!-- Page links shown only on mobile (nav-links row hidden) -->
                        <a href="/" class="nav-mobile-link<?= $_active === 'home' ? ' active' : '' ?>">Home</a>
                        <a href="/leagues.php" class="nav-mobile-link<?= $_active === 'leagues' ? ' active' : '' ?>">Leagues</a>
                        <?php if (get_setting('show_calendar', '1') === '1'): ?>
                        <a href="/calendar.php" class="nav-mobile-link<?= $_active === 'calendar' ? ' active' : '' ?>">Calendar</a>
                        <?php endif; ?>
                        <a href="/my_events.php" class="nav-mobile-link<?= $_active === 'my-events' ? ' active' : '' ?>">My Events</a>
                        <a href="/contacts.php" class="nav-mobile-link<?= $_active === 'contacts' ? ' active' : '' ?>">Contacts</a>
                        <?php if ($_nu && $_nu['role'] === 'admin'): ?>
                        <a href="/admin_posts.php" class="nav-mobile-link<?= $_active === 'posts' ? ' active' : '' ?>">Posts</a>
                        <a href="/admin_settings.php" class="nav-mobile-link<?= $_active === 'site-settings' ? ' active' : '' ?>">Site Settings</a>
                        <?php endif; ?>
                        <a href="/timer.php" class="nav-mobile-link">Tournament Timer</a>
                        <div class="nav-mobile-divider"></div>
                        <a href="/settings.php"<?= $_active === 'settings' ? ' class="active"' : '' ?>>My Settings</a>
                        <a href="/logout.php" class="nav-dropdown-signout">Sign out</a>
                    </div>
                </div>
            <?php else: ?>
                <div class="nav-dropdown-wrap">
                    <button class="nav-hamburger" title="Menu" onclick="var d=this.nextElementSibling;d.style.display=d.style.display==='block'?'none':'block';">&#9776;</button>
                    <div class="nav-dropdown">
                        <?php if (get_setting('show_landing_page', '0') !== '1'): ?>
                        <a href="/timer.php" class="nav-mobile-link">Tournament Timer</a>
                        <div class="nav-mobile-divider"></div>
                        <?php endif; ?>
                        <?php if (get_setting('allow_registration', '1') === '1'): ?>
                        <a href="/register.php">Sign Up</a>
                        <?php endif; ?>
                        <a href="/login.php">Login</a>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <?php if ($_banner): ?>
        <img class="nav-collapse-btn nav-collapse-banner" id="navCollapseBtn"
             src="<?= htmlspecialchars($_banner) ?>?v=<?= $_banner_v ?>"
             alt="<?= htmlspecialchars($site_name) ?>"
             style="max-height:38px;width:auto"
             onclick="toggleNavCollapse()" title="Toggle navigation">
        <?php else: ?>
        <button class="nav-collapse-btn" id="navCollapseBtn" title="Toggle navigation" onclick="toggleNavCollapse()">&#x25B2;</button>
        <?php endif; ?>
    </div>
    <div class="nav-links nav-collapsible">
        <a href="/"<?= $_active === 'home' ? ' class="active"' : '' ?>>Home</a>
        <?php if ($_nu): ?>
        <a href="/leagues.php"<?= $_active === 'leagues' ? ' class="active"' : '' ?>>Leagues</a>
        <?php endif; ?>
        <?php if (get_setting('show_calendar', '1') === '1'): ?>
        <a href="/calendar.php"<?= $_active === 'calendar' ? ' class="active"' : '' ?>>Calendar</a>
        <?php endif; ?>
        <?php if ($_nu): ?>
        <a href="/my_events.php"<?= $_active === 'my-events' ? ' class="active"' : '' ?>>My Events</a>
        <a href="/contacts.php"<?= $_active === 'contacts' ? ' class="active"' : '' ?>>Contacts</a>
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
<style>
.nav-collapse-btn{background:transparent;border:none;color:#64748b;cursor:pointer;font-size:.7rem;padding:.2rem .4rem;margin-right:.3rem;border-radius:4px;line-height:1;transition:transform .2s;order:-1}
.nav-collapse-btn:hover{color:#fff;background:rgba(255,255,255,.1)}
.nav-collapse-banner{max-height:38px;width:auto;cursor:pointer;padding:0;border:none;border-radius:0}
nav.nav-collapsed .nav-collapsible{display:none !important}
nav.nav-collapsed .nav-top{height:32px !important;padding:0 .5rem !important}
nav.nav-collapsed .nav-collapse-btn:not(.nav-collapse-banner){transform:rotate(180deg)}
nav.nav-collapsed .nav-collapse-banner{max-height:24px}
nav.nav-collapsed .nav-banner-img{max-height:24px !important}
nav.nav-collapsed .nav-hamburger{font-size:1rem}
nav.nav-collapsed .nav-dropdown-wrap{position:static}
nav.nav-collapsed .nav-top{justify-content:space-between}
</style>
<script>
function toggleNavCollapse(){
    var nav=document.getElementById('mainNav');
    if(!nav)return;
    nav.classList.toggle('nav-collapsed');
    localStorage.setItem('nav_collapsed',nav.classList.contains('nav-collapsed')?'1':'0');
}
(function(){
    var nav=document.getElementById('mainNav');
    if(!nav)return;
    var isMobile=<?= $_is_mobile ? 'true' : 'false' ?>;
    if(isMobile){
        nav.classList.add('nav-collapsed');
    }else if(localStorage.getItem('nav_collapsed')==='1'){
        nav.classList.add('nav-collapsed');
    }
})();
</script>
<script src="/nav.js" defer></script>
