<style>
/* Landing visuals — inline to dodge the un-versioned style.css cache */
/* Hero gallery: a scroll-snap carousel of timer layouts, one shot per
   slide. Each figure is the full strip width and snaps to center, so a
   swipe or chevron click always lands on exactly one image — no
   half-visible neighbours. The strip scrolls inside its own box — the
   page never scrolls sideways. Touch swipes; the chevron buttons drive
   it for mouse users (lpGalNav below, via pk-dispatch). */
.lp-gallery-wrap { position:relative; max-width:1100px; margin:2.25rem auto 0; }
.lp-gallery { display:flex; gap:14px; overflow-x:auto;
  scroll-snap-type:x mandatory; -webkit-overflow-scrolling:touch;
  padding:0 0 .6rem; }
.lp-gallery figure { flex:0 0 100%; scroll-snap-align:center; scroll-snap-stop:always;
  margin:0; display:flex; flex-direction:column; align-items:center; }
.lp-gal-btn { position:absolute; top:50%; transform:translateY(-50%); z-index:2;
  width:42px; height:42px; border-radius:50%; border:1px solid rgba(255,255,255,.3);
  background:rgba(15,23,42,.72); color:#e2e8f0; font-size:1.1rem; line-height:1;
  cursor:pointer; transition:background .15s, border-color .15s; }
.lp-gal-btn:hover { background:rgba(37,99,235,.85); border-color:rgba(255,255,255,.5); }
.lp-gal-prev { left:.5rem; }
.lp-gal-next { right:.5rem; }
/* Fixed 320px box so landscape TV shots and portrait phone shots keep
   the strip the same height; contain letterboxes instead of distorting
   when max-width clamps a wide shot on a narrow screen. */
.lp-gallery img { height:320px; width:auto; max-width:100%; object-fit:contain; display:block;
  border-radius:10px; border:1px solid rgba(255,255,255,.14);
  box-shadow:0 10px 30px rgba(0,0,0,.35); }
.lp-gallery figcaption { font-size:.75rem; color:#94a3b8; margin-top:.35rem; text-align:center; }
.lp-gallery-hint { color:#94a3b8; font-size:.85rem; margin:.6rem auto 0; max-width:700px; }
@media (max-width:640px) { .lp-gallery img { height:210px; } .lp-gallery { gap:10px; } }
.lp-section-head { text-align:center; padding:3rem 1.5rem .5rem; }
.lp-section-head h2 { font-size:1.6rem; margin-bottom:.5rem; }
.lp-section-head p { color:#64748b; }
.lp-screens { display:grid; grid-template-columns:repeat(auto-fit,minmax(240px,1fr)); gap:1.5rem; max-width:960px; margin:1.5rem auto 0; padding:0 1.5rem; }
.lp-screens figure { margin:0; }
.lp-screens img { width:100%; height:auto; border-radius:10px; border:1px solid var(--border,#e2e8f0); box-shadow:0 8px 24px rgba(0,0,0,.12); display:block; }
.lp-screens figcaption { text-align:center; font-size:.85rem; color:#64748b; margin-top:.6rem; }
/* Guest header. _nav.php deliberately renders nothing in landing mode, which
   left the page with no link home and no crawlable path to the league
   directory or the guides: every public page was an orphan except through the
   sitemap. Plain anchors, no JS. */
.lp-topbar { display:flex; align-items:center; gap:.5rem 1rem; flex-wrap:wrap; padding:.7rem 1.25rem;
  background:#0f172a; color:#e2e8f0; font-size:.9rem; }
.lp-topbar .lp-brand { color:#fff; font-weight:800; text-decoration:none; font-size:1.05rem; margin-right:auto; }
.lp-topbar nav { display:flex; gap:.25rem .9rem; flex-wrap:wrap; align-items:center; }
.lp-topbar nav a { color:#cbd5e1; text-decoration:none; padding:.35rem .15rem; }
.lp-topbar nav a:hover { color:#fff; text-decoration:underline; }
.lp-topbar nav a.lp-cta { color:#fff; background:#2563eb; border-radius:7px; padding:.4rem .85rem; }
.lp-topbar nav a.lp-cta:hover { background:#1d4ed8; text-decoration:none; }
.hero-kicker { color:#94a3b8; font-size:.8rem; letter-spacing:.14em; text-transform:uppercase; font-weight:700; margin:0 0 .6rem; }
</style>
<!-- ── SaaS-style marketing landing page for visitors ── -->
<header class="lp-topbar" aria-label="Site">
    <a class="lp-brand" href="/"><?= htmlspecialchars(get_setting('site_name', 'Game Night'), ENT_QUOTES | ENT_SUBSTITUTE) ?></a>
    <nav aria-label="Public pages">
        <a href="/league">Leagues</a>
        <a href="/help-hosts.php">Host Guide</a>
        <a href="/help-guests.php">Guest Guide</a>
        <a href="/help-timer.php">Timer Guide</a>
        <a href="/login.php">Log in</a>
        <?php if (get_setting('allow_registration', '1') === '1'): ?>
        <a class="lp-cta" href="/register.php">Sign up</a>
        <?php endif; ?>
    </nav>
</header>
<div class="hero">
    <?php $_lp_banner = get_setting('header_banner_path', ''); if ($_lp_banner):
        // The banner is the largest thing above the fold (the LCP element) and
        // used to arrive with no size, so the headline jumped when it loaded.
        // Intrinsic width/height let the browser reserve the box (CSS keeps it
        // responsive via height:auto); fetchpriority pulls it ahead of the
        // carousel. getimagesize() is core PHP; a missing file just omits both.
        $_lp_dims = (preg_match('#^/uploads/[A-Za-z0-9._/-]+$#', $_lp_banner) && is_file(__DIR__ . $_lp_banner))
            ? (@getimagesize(__DIR__ . $_lp_banner) ?: null) : null;
        ?>
    <img src="<?= htmlspecialchars($_lp_banner, ENT_QUOTES | ENT_SUBSTITUTE) ?>" alt="<?= htmlspecialchars(get_setting('site_name', 'Game Night'), ENT_QUOTES | ENT_SUBSTITUTE) ?>"<?php if ($_lp_dims && !empty($_lp_dims[0]) && !empty($_lp_dims[1])): ?> width="<?= (int)$_lp_dims[0] ?>" height="<?= (int)$_lp_dims[1] ?>"<?php endif; ?> fetchpriority="high" decoding="async" style="max-width:400px;width:90%;height:auto;margin-bottom:1.5rem">
    <?php endif; ?>
    <?php /* The H1 carries the words people search for; the brand line the
             page opened with becomes the kicker above it. */ ?>
    <p class="hero-kicker">Your game nights, organized.</p>
    <h1>Free Poker Tournament Timer &amp; Game Night Organizer</h1>
    <p>The all-in-one platform for organizing leagues, scheduling game nights, managing RSVPs, running poker tournaments, and keeping your crew in the loop.</p>
    <div class="cta-group">
        <?php if (get_setting('allow_registration', '1') === '1'): ?>
        <a href="/register.php" class="btn btn-primary" style="padding:.65rem 2rem;font-size:1rem">Get Started Free</a>
        <?php endif; ?>
        <a href="/login.php" class="btn btn-outline" style="padding:.65rem 2rem;font-size:1rem">Sign In</a>
    </div>
    <?php /* Six app-in-action screenshots (shot on dev against a seeded
             dummy event), then six themed timer layouts from the layout
             engine, TV and phone views of each. Assets are repo-local:
             CSP img-src is 'self', so the blog originals could not be
             hotlinked. */ ?>
    <div class="lp-gallery-wrap">
    <button type="button" class="lp-gal-btn lp-gal-prev" data-act="lpGalNav" data-a1="-1" aria-label="Previous screenshot">&#10094;</button>
    <button type="button" class="lp-gal-btn lp-gal-next" data-act="lpGalNav" data-a1="1" aria-label="Next screenshot">&#10095;</button>
    <div class="lp-gallery" aria-label="App screenshots and timer layout examples">
        <figure>
            <picture><source type="image/webp" srcset="/img/landing/action-event-form.webp"><img src="/img/landing/action-event-form.jpg" width="1280" height="800" decoding="async" alt="The event editor with a poker night filled out and guests invited"></picture>
            <figcaption>Schedule a game in seconds</figcaption>
        </figure>
        <figure>
            <picture><source type="image/webp" srcset="/img/landing/action-event-page.webp"><img src="/img/landing/action-event-page.jpg" width="1280" height="800" decoding="async" loading="lazy" alt="An event page showing RSVP buttons and the guest list"></picture>
            <figcaption>Event page &mdash; RSVPs at a glance</figcaption>
        </figure>
        <figure>
            <picture><source type="image/webp" srcset="/img/landing/action-calendar.webp"><img src="/img/landing/action-calendar.jpg" width="1280" height="800" decoding="async" loading="lazy" alt="A month calendar with several scheduled game nights"></picture>
            <figcaption>Your group&rsquo;s calendar</figcaption>
        </figure>
        <figure>
            <picture><source type="image/webp" srcset="/img/landing/action-checkin-list.webp"><img src="/img/landing/action-checkin-list.jpg" width="1280" height="800" decoding="async" loading="lazy" alt="The tournament check-in console with players, buy-ins, and the live prize pool"></picture>
            <figcaption>Tournament check-in console</figcaption>
        </figure>
        <figure>
            <picture><source type="image/webp" srcset="/img/landing/action-checkin-tables.webp"><img src="/img/landing/action-checkin-tables.jpg" width="1280" height="800" decoding="async" loading="lazy" alt="The table view showing seat assignments across two tables"></picture>
            <figcaption>Table draw &amp; seating</figcaption>
        </figure>
        <figure>
            <picture><source type="image/webp" srcset="/img/landing/action-setup-payouts.webp"><img src="/img/landing/action-setup-payouts.jpg" width="1280" height="800" decoding="async" loading="lazy" alt="The game setup editor on the payouts and rewards tab"></picture>
            <figcaption>Payouts &amp; rewards setup</figcaption>
        </figure>
        <figure>
            <picture><source type="image/webp" srcset="/img/landing/neon-nights-main-desktop.webp"><img src="/img/landing/neon-nights-main-desktop.jpg" width="896" height="505" decoding="async" loading="lazy" alt="Neon Nights timer layout on a TV"></picture>
            <figcaption>Neon Nights</figcaption>
        </figure>
        <figure>
            <picture><source type="image/webp" srcset="/img/landing/neon-nights-main-phone.webp"><img src="/img/landing/neon-nights-main-phone.jpg" width="256" height="552" decoding="async" loading="lazy" alt="Neon Nights layout on a phone"></picture>
            <figcaption>Neon Nights — on a phone</figcaption>
        </figure>
        <figure>
            <picture><source type="image/webp" srcset="/img/landing/neon-nights-break-desktop.webp"><img src="/img/landing/neon-nights-break-desktop.jpg" width="896" height="505" decoding="async" loading="lazy" alt="Neon Nights break screen on a TV"></picture>
            <figcaption>Neon Nights — break screen</figcaption>
        </figure>
        <figure>
            <picture><source type="image/webp" srcset="/img/landing/neon-nights-break-phone.webp"><img src="/img/landing/neon-nights-break-phone.jpg" width="256" height="552" decoding="async" loading="lazy" alt="Neon Nights break screen on a phone"></picture>
            <figcaption>Neon Nights — break, on a phone</figcaption>
        </figure>
        <figure>
            <picture><source type="image/webp" srcset="/img/landing/dusty-trail-saloon-main-desktop.webp"><img src="/img/landing/dusty-trail-saloon-main-desktop.jpg" width="896" height="505" decoding="async" loading="lazy" alt="Dusty Trail Saloon timer layout on a TV"></picture>
            <figcaption>Dusty Trail Saloon</figcaption>
        </figure>
        <figure>
            <picture><source type="image/webp" srcset="/img/landing/dusty-trail-saloon-main-phone.webp"><img src="/img/landing/dusty-trail-saloon-main-phone.jpg" width="256" height="552" decoding="async" loading="lazy" alt="Dusty Trail Saloon layout on a phone"></picture>
            <figcaption>Dusty Trail Saloon — on a phone</figcaption>
        </figure>
        <figure>
            <picture><source type="image/webp" srcset="/img/landing/dusty-trail-saloon-break-desktop.webp"><img src="/img/landing/dusty-trail-saloon-break-desktop.jpg" width="896" height="505" decoding="async" loading="lazy" alt="Dusty Trail Saloon break screen on a TV"></picture>
            <figcaption>Dusty Trail Saloon — break screen</figcaption>
        </figure>
        <figure>
            <picture><source type="image/webp" srcset="/img/landing/dusty-trail-saloon-break-phone.webp"><img src="/img/landing/dusty-trail-saloon-break-phone.jpg" width="256" height="552" decoding="async" loading="lazy" alt="Dusty Trail Saloon break screen on a phone"></picture>
            <figcaption>Dusty Trail Saloon — break, on a phone</figcaption>
        </figure>
        <figure>
            <picture><source type="image/webp" srcset="/img/landing/zen-minimal-main-desktop.webp"><img src="/img/landing/zen-minimal-main-desktop.jpg" width="896" height="505" decoding="async" loading="lazy" alt="Zen Minimal timer layout on a TV"></picture>
            <figcaption>Zen Minimal</figcaption>
        </figure>
        <figure>
            <picture><source type="image/webp" srcset="/img/landing/zen-minimal-main-phone.webp"><img src="/img/landing/zen-minimal-main-phone.jpg" width="256" height="552" decoding="async" loading="lazy" alt="Zen Minimal layout on a phone"></picture>
            <figcaption>Zen Minimal — on a phone</figcaption>
        </figure>
        <figure>
            <picture><source type="image/webp" srcset="/img/landing/zen-minimal-break-desktop.webp"><img src="/img/landing/zen-minimal-break-desktop.jpg" width="896" height="505" decoding="async" loading="lazy" alt="Zen Minimal break screen on a TV"></picture>
            <figcaption>Zen Minimal — break screen</figcaption>
        </figure>
        <figure>
            <picture><source type="image/webp" srcset="/img/landing/zen-minimal-break-phone.webp"><img src="/img/landing/zen-minimal-break-phone.jpg" width="256" height="552" decoding="async" loading="lazy" alt="Zen Minimal break screen on a phone"></picture>
            <figcaption>Zen Minimal — break, on a phone</figcaption>
        </figure>
        <figure>
            <picture><source type="image/webp" srcset="/img/landing/emerald-high-roller-main-desktop.webp"><img src="/img/landing/emerald-high-roller-main-desktop.jpg" width="896" height="505" decoding="async" loading="lazy" alt="Emerald High Roller timer layout on a TV"></picture>
            <figcaption>Emerald High Roller</figcaption>
        </figure>
        <figure>
            <picture><source type="image/webp" srcset="/img/landing/emerald-high-roller-main-phone.webp"><img src="/img/landing/emerald-high-roller-main-phone.jpg" width="256" height="552" decoding="async" loading="lazy" alt="Emerald High Roller layout on a phone"></picture>
            <figcaption>Emerald High Roller — on a phone</figcaption>
        </figure>
        <figure>
            <picture><source type="image/webp" srcset="/img/landing/emerald-high-roller-break-desktop.webp"><img src="/img/landing/emerald-high-roller-break-desktop.jpg" width="896" height="505" decoding="async" loading="lazy" alt="Emerald High Roller break screen on a TV"></picture>
            <figcaption>Emerald High Roller — break screen</figcaption>
        </figure>
        <figure>
            <picture><source type="image/webp" srcset="/img/landing/emerald-high-roller-break-phone.webp"><img src="/img/landing/emerald-high-roller-break-phone.jpg" width="256" height="552" decoding="async" loading="lazy" alt="Emerald High Roller break screen on a phone"></picture>
            <figcaption>Emerald High Roller — break, on a phone</figcaption>
        </figure>
        <figure>
            <picture><source type="image/webp" srcset="/img/landing/pixel-arcade-main-desktop.webp"><img src="/img/landing/pixel-arcade-main-desktop.jpg" width="896" height="505" decoding="async" loading="lazy" alt="Pixel Arcade timer layout on a TV"></picture>
            <figcaption>Pixel Arcade</figcaption>
        </figure>
        <figure>
            <picture><source type="image/webp" srcset="/img/landing/pixel-arcade-main-phone.webp"><img src="/img/landing/pixel-arcade-main-phone.jpg" width="256" height="552" decoding="async" loading="lazy" alt="Pixel Arcade layout on a phone"></picture>
            <figcaption>Pixel Arcade — on a phone</figcaption>
        </figure>
        <figure>
            <picture><source type="image/webp" srcset="/img/landing/pixel-arcade-break-desktop.webp"><img src="/img/landing/pixel-arcade-break-desktop.jpg" width="896" height="505" decoding="async" loading="lazy" alt="Pixel Arcade break screen on a TV"></picture>
            <figcaption>Pixel Arcade — break screen</figcaption>
        </figure>
        <figure>
            <picture><source type="image/webp" srcset="/img/landing/pixel-arcade-break-phone.webp"><img src="/img/landing/pixel-arcade-break-phone.jpg" width="256" height="552" decoding="async" loading="lazy" alt="Pixel Arcade break screen on a phone"></picture>
            <figcaption>Pixel Arcade — break, on a phone</figcaption>
        </figure>
        <figure>
            <picture><source type="image/webp" srcset="/img/landing/snowman-griffs-main-desktop.webp"><img src="/img/landing/snowman-griffs-main-desktop.jpg" width="896" height="505" decoding="async" loading="lazy" alt="Snowman Poker League timer layout on a TV"></picture>
            <figcaption>Snowman Poker League</figcaption>
        </figure>
        <figure>
            <picture><source type="image/webp" srcset="/img/landing/snowman-griffs-main-phone.webp"><img src="/img/landing/snowman-griffs-main-phone.jpg" width="256" height="552" decoding="async" loading="lazy" alt="Snowman Poker League layout on a phone"></picture>
            <figcaption>Snowman Poker League — dedicated phone screen</figcaption>
        </figure>
        <figure>
            <picture><source type="image/webp" srcset="/img/landing/snowman-griffs-break-desktop.webp"><img src="/img/landing/snowman-griffs-break-desktop.jpg" width="896" height="505" decoding="async" loading="lazy" alt="Snowman Poker League break screen on a TV"></picture>
            <figcaption>Snowman Poker League — break screen</figcaption>
        </figure>
        <figure>
            <picture><source type="image/webp" srcset="/img/landing/snowman-griffs-break-phone.webp"><img src="/img/landing/snowman-griffs-break-phone.jpg" width="256" height="552" decoding="async" loading="lazy" alt="Snowman Poker League break screen on a phone"></picture>
            <figcaption>Snowman Poker League — phone screen on break</figcaption>
        </figure>
    </div>
    </div>
    <p class="lp-gallery-hint">Real screens from the app &mdash; scheduling, RSVPs, check-in, seating and payouts &mdash; plus six ready-made tournament timer layouts in TV and phone views. Build your own in the layout editor.</p>
</div>

<script nonce="<?= csp_nonce() ?>">
// Chevron nav for the hero gallery: find the shot nearest the strip's
// center, step to its neighbour, and scroll it to center (snap settles it).
// Dispatched by pk-dispatch.js via data-act, so no inline handlers.
window.lpGalNav = function (dir) {
    var g = document.querySelector('.lp-gallery');
    if (!g) return;
    var figs = g.querySelectorAll('figure');
    if (!figs.length) return;
    var mid = g.scrollLeft + g.clientWidth / 2;
    var best = 0, bestD = Infinity;
    for (var i = 0; i < figs.length; i++) {
        var c = figs[i].offsetLeft + figs[i].offsetWidth / 2;
        var d = Math.abs(c - mid);
        if (d < bestD) { bestD = d; best = i; }
    }
    var t = Math.max(0, Math.min(figs.length - 1, best + dir));
    var f = figs[t];
    var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    g.scrollTo({ left: f.offsetLeft + f.offsetWidth / 2 - g.clientWidth / 2,
                 behavior: reduce ? 'auto' : 'smooth' });
};
</script>

<div class="lp-section-head">
    <h2>Everything you need to run game night</h2>
    <p>From the first invite to the final hand.</p>
</div>
<div class="feature-grid">
    <div class="feature-card">
        <div class="icon" aria-hidden="true">&#127942;</div>
        <h3>Leagues</h3>
        <p>Create private leagues for your poker group, board game crew, or any circle. Build a roster, invite members by email or shareable link, and keep your events and contacts separate from other groups on the site. <a href="/league">Browse public leagues</a>.</p>
    </div>
    <div class="feature-card">
        <div class="icon" aria-hidden="true">&#128101;</div>
        <h3>Roster Management</h3>
        <p>Add members by name and email — even before they sign up. Import your whole roster via CSV. Pending contacts auto-link when they create an account, becoming full members instantly.</p>
    </div>
    <div class="feature-card">
        <div class="icon" aria-hidden="true">&#128197;</div>
        <h3>Event Scheduling</h3>
        <p>Create events scoped to your league or just your personal invite list. Set dates, times, and visibility. Only members and invitees see what's meant for them — no comingled calendars.</p>
    </div>
    <div class="feature-card">
        <div class="icon" aria-hidden="true">&#9989;</div>
        <h3>RSVP Management</h3>
        <p>One-click RSVPs from email or text. See who's in, who's out, and who's on the fence. Automatic reminders keep your headcount accurate.</p>
    </div>
    <div class="feature-card">
        <div class="icon" aria-hidden="true">&#127922;</div>
        <h3>Tournament Tools</h3>
        <p>Full-screen tournament timer with customizable blind structures, player check-in, table assignments, random seating, and payout calculators (ICM, Standard, Chip Chop).</p>
    </div>
    <div class="feature-card">
        <div class="icon" aria-hidden="true">&#128202;</div>
        <h3>Player Stats &amp; Leaderboard</h3>
        <p>Track every player's games, wins, finish positions, and weighted scores across tournaments. Filter the leaderboard by date range to compare recent form against lifetime stats.</p>
    </div>
    <div class="feature-card">
        <div class="icon" aria-hidden="true">&#128241;</div>
        <h3>Walk-in QR Registration</h3>
        <p>Generate a QR code for public events. Guests scan, register in seconds, and get assigned a table and seat — no app download required.</p>
    </div>
    <div class="feature-card">
        <div class="icon" aria-hidden="true">&#128274;</div>
        <h3>Privacy &amp; Approval Controls</h3>
        <p>League events stay private to members. Join-request approval lets owners vet newcomers. Host approval mode queues walk-ins and self-signups for your review before they're on the list.</p>
    </div>
    <div class="feature-card">
        <div class="icon" aria-hidden="true">&#128176;</div>
        <h3>Multi-Table &amp; Payouts</h3>
        <p>Seat players across multiple tables, balance on the fly, protect button positions, break up tables as the field shrinks, and display live payout structures on the timer screen.</p>
    </div>
    <div class="feature-card">
        <div class="icon" aria-hidden="true">&#128276;</div>
        <h3>Smart Notifications</h3>
        <p>Email, SMS, and WhatsApp — each person picks their preference. Invites, RSVP confirmations, reminders, league requests, and approval alerts all routed automatically.</p>
    </div>
    <div class="feature-card">
        <div class="icon" aria-hidden="true">&#128227;</div>
        <h3>Posts &amp; Comments</h3>
        <p>Share announcements, pin important updates, and let your group discuss. Rich-text editor, comment threads, and a pinned-post feed on the home page.</p>
    </div>
    <div class="feature-card">
        <div class="icon" aria-hidden="true">&#128268;</div>
        <h3>WordPress &amp; API</h3>
        <p>Got a WordPress site for your league? Drop in our <strong>GameNight League</strong> plugin to render events, posts, roster, rules, and RSVP forms as shortcodes anywhere on your site. On a different stack? The same data is one bearer-auth REST call away — read-scope keys for display, write-scope keys let your site mint new accounts.</p>
    </div>
</div>

<div class="lp-section-head" style="padding-bottom:0">
    <h2>See it in action</h2>
</div>
<div class="lp-screens">
    <figure>
        <picture><source type="image/webp" srcset="/img/help/event-create.webp"><img src="/img/help/event-create.png" width="1583" height="320" decoding="async" loading="lazy" alt="The Add Event dialog for scheduling a game night"></picture>
        <figcaption>Schedule an event</figcaption>
    </figure>
    <figure>
        <picture><source type="image/webp" srcset="/img/help/checkin-start.webp"><img src="/img/help/checkin-start.png" width="1651" height="1197" decoding="async" loading="lazy" alt="The check-in dashboard with players, tables, and prize pool"></picture>
        <figcaption>Check players in</figcaption>
    </figure>
    <figure>
        <picture><source type="image/webp" srcset="/img/help/event-rsvps.webp"><img src="/img/help/event-rsvps.png" width="652" height="855" decoding="async" loading="lazy" alt="An event invite list showing yes, no, and maybe RSVPs"></picture>
        <figcaption>Track RSVPs</figcaption>
    </figure>
</div>

<div style="text-align:center;padding:2rem 1.5rem .5rem">
    <h2 style="font-size:1.6rem;margin-bottom:.5rem">How it works</h2>
    <p style="color:#64748b;margin-bottom:1.5rem">New here? Pick the guide that fits.</p>
</div>
<div class="feature-grid" style="max-width:1000px;margin:0 auto">
    <a href="/help-hosts.php" class="feature-card" style="text-decoration:none;color:inherit;display:block">
        <div class="icon" aria-hidden="true">&#127922;</div>
        <h3>Hosting a game night?</h3>
        <p>Quick-start guide for hosts: build your league, schedule an event, invite people, and run game night. A step-by-step walkthrough with screenshots.</p>
    </a>
    <a href="/help-guests.php" class="feature-card" style="text-decoration:none;color:inherit;display:block">
        <div class="icon" aria-hidden="true">&#128231;</div>
        <h3>Got an invite?</h3>
        <p>Quick-start guide for guests: how RSVP links work, walk-in QR codes, and what you get with a free account.</p>
    </a>
    <a href="/help-timer.php" class="feature-card" style="text-decoration:none;color:inherit;display:block">
        <div class="icon" aria-hidden="true">&#9201;</div>
        <h3>Running a tournament?</h3>
        <p>The tournament timer guide: blind schedules and breaks, putting the clock on a TV, phone control, sounds, and sharing the display by QR code.</p>
    </a>
</div>

<div style="text-align:center;padding:3rem 1.5rem 4rem">
    <p style="color:#64748b;font-size:1rem;margin-bottom:1.5rem">Ready to level up your game nights?</p>
    <div class="cta-group">
        <?php if (get_setting('allow_registration', '1') === '1'): ?>
        <a href="/register.php" class="btn btn-primary" style="padding:.65rem 2rem;font-size:1rem">Create Your Free Account</a>
        <?php else: ?>
        <a href="/login.php" class="btn btn-primary" style="padding:.65rem 2rem;font-size:1rem">Sign In</a>
        <?php endif; ?>
    </div>
</div>
