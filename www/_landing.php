<!-- ── SaaS-style marketing landing page for visitors ── -->
<div class="hero">
    <h1>Your Game Nights,<br>Organized.</h1>
    <p>The all-in-one platform for scheduling game nights, managing RSVPs, running tournaments, and keeping your crew in the loop.</p>
    <div class="cta-group">
        <?php if (get_setting('allow_registration', '1') === '1'): ?>
        <a href="/register.php" class="btn btn-primary" style="padding:.65rem 2rem;font-size:1rem">Get Started Free</a>
        <?php endif; ?>
        <a href="/login.php" class="btn btn-outline" style="padding:.65rem 2rem;font-size:1rem">Sign In</a>
    </div>
</div>

<div class="feature-grid">
    <div class="feature-card">
        <div class="icon">&#128197;</div>
        <h3>Event Scheduling</h3>
        <p>Create one-time or recurring events, set dates and times, and invite your group with a single click. Everyone gets notified via email, SMS, or WhatsApp.</p>
    </div>
    <div class="feature-card">
        <div class="icon">&#9989;</div>
        <h3>RSVP Management</h3>
        <p>One-click RSVPs from email or text. See who's in, who's out, and who's on the fence. Automatic reminders keep your headcount accurate.</p>
    </div>
    <div class="feature-card">
        <div class="icon">&#127922;</div>
        <h3>Tournament Tools</h3>
        <p>Built-in tournament timer with customizable blind structures, player check-in, table assignments, random seating, and payout calculators.</p>
    </div>
    <div class="feature-card">
        <div class="icon">&#128241;</div>
        <h3>Walk-in Registration</h3>
        <p>Generate a QR code for your event. Guests scan, register in seconds, and get assigned a table and seat — no app download required.</p>
    </div>
    <div class="feature-card">
        <div class="icon">&#128274;</div>
        <h3>Host Approval</h3>
        <p>Control who joins your events. Enable approval mode and every new sign-up lands in a queue you approve or deny before they're on the list.</p>
    </div>
    <div class="feature-card">
        <div class="icon">&#128227;</div>
        <h3>Announcements & Comments</h3>
        <p>Post updates, pin important announcements, and let your group comment and discuss. Keep all communication in one place.</p>
    </div>
    <div class="feature-card">
        <div class="icon">&#128202;</div>
        <h3>Multi-Table Management</h3>
        <p>Seat players across multiple tables, balance on the fly, protect button positions, and break up tables as the field shrinks.</p>
    </div>
    <div class="feature-card">
        <div class="icon">&#128276;</div>
        <h3>Smart Notifications</h3>
        <p>Email, SMS, and WhatsApp — each person picks their preference. Event invites, RSVP confirmations, reminders, and approval alerts all routed automatically.</p>
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
