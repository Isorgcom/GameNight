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
// Point the Timer link at the user's in-progress game (so its prize pool /
// players sync live), falling back to a standalone timer when none is running.
$_timer_href = '/timer.php';
if ($_nu && !empty($_nu['id']) && function_exists('user_active_poker_event_id')) {
    try {
        $_aeid = user_active_poker_event_id(get_db(), (int)$_nu['id'], (($_nu['role'] ?? '') === 'admin'));
        if ($_aeid) $_timer_href = '/timer.php?event_id=' . $_aeid;
    } catch (Throwable $e) { /* fall back to standalone */ }
}
// Admin-only "update available" dot on the Site Settings link.
$_show_update_dot      = ($_nu && ($_nu['role'] ?? '') === 'admin'
                          && function_exists('update_available') && update_available());
// Unread in-app notifications (bell badge). Guarded: pre-migration DBs lack the table.
$_nf_unread = 0;
if ($_nu && !empty($_nu['id'])) {
    try {
        $_nfq = get_db()->prepare('SELECT COUNT(*) FROM user_notifications WHERE user_id = ? AND is_read = 0');
        $_nfq->execute([(int)$_nu['id']]);
        $_nf_unread = (int)$_nfq->fetchColumn();
    } catch (Throwable $e) {}
}
// Unread direct messages (Messages link badge). Guarded like the bell.
$_dm_unread = 0;
if ($_nu && !empty($_nu['id'])) {
    try {
        $_dmq = get_db()->prepare('SELECT COUNT(*) FROM dm_messages m
            JOIN dm_participants p ON p.conversation_id = m.conversation_id AND p.user_id = :me
            WHERE m.sender_id <> :me
              AND m.id > MAX(p.last_read_msg_id, p.cleared_before_id)');
        $_dmq->execute([':me' => (int)$_nu['id']]);
        $_dm_unread = (int)$_dmq->fetchColumn();
    } catch (Throwable $e) {}
}
// Badges are always rendered (hidden at zero) so the live poller in
// _footer.php can update them in place without a page reload.
$_dm_badge = ' <span class="js-dm-badge" style="background:#dc2626;color:#fff;font-size:.65rem;font-weight:700;border-radius:999px;padding:.05rem .35rem'
           . ($_dm_unread > 0 ? '' : ';display:none') . '">' . ($_dm_unread > 99 ? '99+' : $_dm_unread) . '</span>';
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
            <?php if ($_nu):
                $_combined = $_nf_unread + $_dm_unread;
            ?>
                <!-- Account avatar + personal menu (Notifications, Messages, My Events, Contacts, Settings, Sign out) -->
                <div class="nav-dropdown-wrap nav-account-wrap">
                    <button type="button" class="nav-avatar-btn" title="Your account"
                            onclick="var d=this.nextElementSibling;d.style.display=d.style.display==='block'?'none':'block';">
                        <?= avatar_html($_nu['username'], $_nu['avatar_path'] ?? null, 34) ?>
                        <span class="js-avatar-badge nav-avatar-badge" style="<?= $_combined > 0 ? '' : 'display:none' ?>"><?= $_combined > 99 ? '99+' : $_combined ?></span>
                    </button>
                    <div class="nav-dropdown nav-account-dropdown">
                        <div class="nav-account-head">
                            <?= avatar_html($_nu['username'], $_nu['avatar_path'] ?? null, 40) ?>
                            <div class="nav-account-name"><?= htmlspecialchars($_nu['username']) ?></div>
                        </div>
                        <a href="/notifications.php"<?= $_active === 'notifications' ? ' class="active"' : '' ?>>Notifications <span class="js-bell-badge nav-menu-count" style="<?= $_nf_unread > 0 ? '' : 'display:none' ?>"><?= $_nf_unread > 99 ? '99+' : $_nf_unread ?></span></a>
                        <a href="/messages.php"<?= $_active === 'messages' ? ' class="active"' : '' ?>>Messages <span class="js-dm-badge nav-menu-count" style="<?= $_dm_unread > 0 ? '' : 'display:none' ?>"><?= $_dm_unread > 99 ? '99+' : $_dm_unread ?></span></a>
                        <a href="/my_events.php"<?= $_active === 'my-events' ? ' class="active"' : '' ?>>My Events</a>
                        <a href="/contacts.php"<?= $_active === 'contacts' ? ' class="active"' : '' ?>>Contacts</a>
                        <a href="/settings.php"<?= $_active === 'settings' ? ' class="active"' : '' ?>>My Settings</a>
                        <div class="nav-mobile-divider"></div>
                        <a href="/logout.php" class="nav-dropdown-signout">Sign out</a>
                    </div>
                </div>
                <!-- Hamburger = site navigation only -->
                <div class="nav-dropdown-wrap">
                    <button class="nav-hamburger" title="Menu" onclick="var d=this.nextElementSibling;d.style.display=d.style.display==='block'?'none':'block';">&#9776;</button>
                    <div class="nav-dropdown">
                        <!-- Page links shown only on mobile (nav-links row hidden) -->
                        <a href="/" class="nav-mobile-link<?= $_active === 'home' ? ' active' : '' ?>">Home</a>
                        <a href="/leagues.php" class="nav-mobile-link<?= $_active === 'leagues' ? ' active' : '' ?>">Leagues</a>
                        <?php if (get_setting('show_calendar', '1') === '1'): ?>
                        <a href="/calendar.php" class="nav-mobile-link<?= $_active === 'calendar' ? ' active' : '' ?>">Calendar</a>
                        <?php endif; ?>
                        <?php if ($_nu && $_nu['role'] === 'admin'): ?>
                        <a href="/admin_posts.php" class="nav-mobile-link<?= $_active === 'posts' ? ' active' : '' ?>">Posts</a>
                        <a href="/admin_settings.php" class="nav-mobile-link<?= $_active === 'site-settings' ? ' active' : '' ?>">Site Settings<?php if ($_show_update_dot): ?> <span class="nav-update-dot" title="Update available: v<?= htmlspecialchars(get_setting('latest_version')) ?>"></span><?php endif; ?></a>
                        <?php endif; ?>
                        <a href="<?= htmlspecialchars($_timer_href, ENT_QUOTES) ?>" class="nav-mobile-link">Tournament Timer</a>
                        <div class="nav-mobile-divider"></div>
                        <div class="nav-help-group<?= $_active === 'help' || $_active === 'support' ? ' open' : '' ?>">
                            <button type="button" class="nav-help-toggle" onclick="this.parentElement.classList.toggle('open');">Help <span class="nav-help-caret" aria-hidden="true">&#9656;</span></button>
                            <div class="nav-help-sub">
                                <a href="/help-hosts.php">Host Guide</a>
                                <a href="/help-guests.php">Guest Guide</a>
                                <a href="/support.php"<?= $_active === 'support' ? ' class="active"' : '' ?>>Support</a>
                            </div>
                        </div>
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
                        <div class="nav-help-group<?= $_active === 'help' ? ' open' : '' ?>">
                            <button type="button" class="nav-help-toggle" onclick="this.parentElement.classList.toggle('open');">Help <span class="nav-help-caret" aria-hidden="true">&#9656;</span></button>
                            <div class="nav-help-sub">
                                <a href="/help-hosts.php">Host Guide</a>
                                <a href="/help-guests.php">Guest Guide</a>
                            </div>
                        </div>
                        <div class="nav-mobile-divider"></div>
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
        <?php /* My Events / Contacts / Messages now live in the account (avatar) menu */ ?>
        <?php if ($_nu && $_nu['role'] === 'admin'): ?>
            <a href="/admin_posts.php"<?= $_active === 'posts' ? ' class="active"' : '' ?>>Posts</a>
            <a href="/admin_settings.php"<?= $_active === 'site-settings' ? ' class="active"' : '' ?>>Site Settings<?php if ($_show_update_dot): ?> <span class="nav-update-dot" title="Update available: v<?= htmlspecialchars(get_setting('latest_version')) ?>"></span><?php endif; ?></a>
        <?php endif; ?>
    </div>
</nav>
<?php if ($_banner): ?>
<script nonce="<?= csp_nonce() ?>">
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
nav.nav-collapsed .nav-top{height:42px !important;padding:0 .5rem !important}
nav.nav-collapsed .nav-collapse-btn:not(.nav-collapse-banner){transform:rotate(180deg)}
nav.nav-collapsed .nav-collapse-banner{max-height:24px}
nav.nav-collapsed .nav-banner-img{max-height:24px !important}
nav.nav-collapsed .nav-hamburger{font-size:1rem}
nav.nav-collapsed .nav-dropdown-wrap{position:static}
nav.nav-collapsed .nav-top{justify-content:space-between}
.nav-help-toggle{display:flex;align-items:center;gap:.4rem;width:100%;box-sizing:border-box;padding:.6rem 1rem;background:none;border:none;cursor:pointer;color:#94a3b8;font:inherit;font-size:.875rem;text-align:left}
.nav-help-toggle:hover{background:rgba(255,255,255,.08);color:#fff}
.nav-help-caret{font-size:.65rem;transition:transform .15s}
.nav-help-group.open .nav-help-caret{transform:rotate(90deg)}
.nav-help-sub{display:none}
.nav-help-group.open .nav-help-sub{display:block}
.nav-help-sub a{padding-left:1.85rem}
.nav-update-dot{display:inline-block;width:8px;height:8px;border-radius:50%;background:#f59e0b;margin-left:.35rem;vertical-align:middle;box-shadow:0 0 0 2px rgba(245,158,11,.25)}
/* Account avatar button + personal dropdown */
.nav-account-wrap{margin-right:.15rem}
.nav-avatar-btn{position:relative;background:none;border:none;padding:0;cursor:pointer;line-height:0;border-radius:50%;display:inline-flex}
.nav-avatar-btn .gn-avatar{box-shadow:0 0 0 2px rgba(255,255,255,.25)}
.nav-avatar-btn:hover .gn-avatar{box-shadow:0 0 0 2px rgba(255,255,255,.55)}
.nav-avatar-badge{position:absolute;top:-4px;right:-5px;background:#dc2626;color:#fff;font-size:.6rem;font-weight:700;border-radius:999px;padding:.08rem .3rem;line-height:1.2;min-width:.65rem;text-align:center}
.nav-account-dropdown .nav-account-head{display:flex;align-items:center;gap:.55rem;padding:.7rem 1rem;border-bottom:1px solid rgba(255,255,255,.12);margin-bottom:.25rem}
.nav-account-name{font-weight:700;font-size:.9rem;color:#fff;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.nav-menu-count{background:#dc2626;color:#fff;font-size:.65rem;font-weight:700;border-radius:999px;padding:.05rem .35rem;margin-left:.35rem;vertical-align:middle}
</style>
<script nonce="<?= csp_nonce() ?>">
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
// Nav dropdowns: opening one closes the other; clicking outside closes both.
(function(){
    document.addEventListener('click', function(e){
        var wrap = e.target.closest('.nav-dropdown-wrap');
        document.querySelectorAll('.nav-dropdown-wrap .nav-dropdown').forEach(function(dd){
            if (!wrap || dd.parentElement !== wrap) dd.style.display = 'none';
        });
    });
})();
</script>
<script src="/nav.js" defer></script>
