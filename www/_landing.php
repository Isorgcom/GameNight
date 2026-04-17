<!-- ── SaaS-style marketing landing page for visitors ── -->
<div class="hero">
    <?php $_lp_banner = get_setting('header_banner_path', ''); if ($_lp_banner): ?>
    <img src="<?= htmlspecialchars($_lp_banner) ?>" alt="<?= htmlspecialchars(get_setting('site_name', 'Game Night')) ?>" style="max-width:400px;width:90%;margin-bottom:1.5rem">
    <?php endif; ?>
    <h1>Your Game Nights,<br>Organized.</h1>
    <p>The all-in-one platform for organizing leagues, scheduling game nights, managing RSVPs, running poker tournaments, and keeping your crew in the loop.</p>
    <div class="cta-group">
        <?php if (get_setting('allow_registration', '1') === '1'): ?>
        <a href="/register.php" class="btn btn-primary" style="padding:.65rem 2rem;font-size:1rem">Get Started Free</a>
        <?php endif; ?>
        <a href="/login.php" class="btn btn-outline" style="padding:.65rem 2rem;font-size:1rem">Sign In</a>
    </div>
</div>

<div class="feature-grid">
    <div class="feature-card">
        <div class="icon">&#127942;</div>
        <h3>Leagues</h3>
        <p>Create private leagues for your poker group, board game crew, or any circle. Build a roster, invite members by email or shareable link, and keep your events and contacts separate from other groups on the site.</p>
    </div>
    <div class="feature-card">
        <div class="icon">&#128101;</div>
        <h3>Roster Management</h3>
        <p>Add members by name and email — even before they sign up. Import your whole roster via CSV. Pending contacts auto-link when they create an account, becoming full members instantly.</p>
    </div>
    <div class="feature-card">
        <div class="icon">&#128197;</div>
        <h3>Event Scheduling</h3>
        <p>Create events scoped to your league or just your personal invite list. Set dates, times, and visibility. Only members and invitees see what's meant for them — no comingled calendars.</p>
    </div>
    <div class="feature-card">
        <div class="icon">&#9989;</div>
        <h3>RSVP Management</h3>
        <p>One-click RSVPs from email or text. See who's in, who's out, and who's on the fence. Automatic reminders keep your headcount accurate.</p>
    </div>
    <div class="feature-card">
        <div class="icon">&#127922;</div>
        <h3>Tournament Tools</h3>
        <p>Full-screen tournament timer with customizable blind structures, player check-in, table assignments, random seating, and payout calculators (ICM, Standard, Chip Chop).</p>
    </div>
    <div class="feature-card">
        <div class="icon">&#128202;</div>
        <h3>Player Stats &amp; Leaderboard</h3>
        <p>Track every player's games, wins, finish positions, and weighted scores across tournaments. Filter the leaderboard by date range to compare recent form against lifetime stats.</p>
    </div>
    <div class="feature-card">
        <div class="icon">&#128241;</div>
        <h3>Walk-in QR Registration</h3>
        <p>Generate a QR code for public events. Guests scan, register in seconds, and get assigned a table and seat — no app download required.</p>
    </div>
    <div class="feature-card">
        <div class="icon">&#128274;</div>
        <h3>Privacy &amp; Approval Controls</h3>
        <p>League events stay private to members. Join-request approval lets owners vet newcomers. Host approval mode queues walk-ins and self-signups for your review before they're on the list.</p>
    </div>
    <div class="feature-card">
        <div class="icon">&#128176;</div>
        <h3>Multi-Table &amp; Payouts</h3>
        <p>Seat players across multiple tables, balance on the fly, protect button positions, break up tables as the field shrinks, and display live payout structures on the timer screen.</p>
    </div>
    <div class="feature-card">
        <div class="icon">&#128276;</div>
        <h3>Smart Notifications</h3>
        <p>Email, SMS, and WhatsApp — each person picks their preference. Invites, RSVP confirmations, reminders, league requests, and approval alerts all routed automatically.</p>
    </div>
    <div class="feature-card">
        <div class="icon">&#128227;</div>
        <h3>Posts &amp; Comments</h3>
        <p>Share announcements, pin important updates, and let your group discuss. Rich-text editor, comment threads, and a pinned-post feed on the home page.</p>
    </div>
    <div class="feature-card">
        <div class="icon">&#128295;</div>
        <h3>Self-Hosted &amp; Yours</h3>
        <p>Run it on your own server with Docker. SQLite database, no external dependencies, full admin panel. Your data stays with you — always.</p>
    </div>
</div>

<div style="text-align:center;padding:3rem 1.5rem 4rem">
    <p style="color:#64748b;font-size:1rem;margin-bottom:1.5rem">Ready to level up your game nights?</p>
    <div class="cta-group">
        <?php if (get_setting('allow_registration', '1') === '1'): ?>
        <a href="/register.php" class="btn btn-primary" style="padding:.65rem 2rem;font-size:1rem">Create Your Free Account</a>
        <?php endif; ?>
    </div>
</div>
