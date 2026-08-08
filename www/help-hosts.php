<?php
require_once __DIR__ . '/auth.php';

$site_name   = get_setting('site_name', 'Game Night');
$nav_active  = 'help';
$allow_reg   = get_setting('allow_registration', '1') === '1';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Host Guide &mdash; <?= htmlspecialchars($site_name) ?></title>
    <?php render_seo_meta('Host Guide', 'Step-by-step guide to hosting a game night: set up a league, invite guests, adjust event settings, track RSVPs, and run the tournament timer.', 'help-hosts.php'); ?>
    <link rel="stylesheet" href="/style.css?v=<?= htmlspecialchars(APP_VERSION . '.' . (@filemtime(__DIR__ . '/style.css') ?: 0)) ?>">
    <style>
        .help-wrap { max-width: 760px; margin: 2rem auto 4rem; padding: 0 1.5rem; }
        .help-wrap h1 { font-size: 2rem; margin-bottom: .5rem; }
        .help-wrap .subtitle { color: #64748b; margin-bottom: 2.5rem; font-size: 1.05rem; }
        .help-step { margin-bottom: 2.5rem; }
        .help-step h2 { font-size: 1.35rem; margin: 0 0 .75rem; display: flex; align-items: center; gap: .6rem; }
        .help-step .step-num {
            display: inline-flex; align-items: center; justify-content: center;
            width: 32px; height: 32px; border-radius: 50%;
            background: var(--accent, #2563eb); color: #fff;
            font-size: .95rem; font-weight: 600; flex-shrink: 0;
        }
        .help-step p { margin: .5rem 0; line-height: 1.6; color: #334155; }
        .help-step ul { margin: .5rem 0 .5rem 1.25rem; line-height: 1.6; color: #334155; }
        .help-step .hint {
            background: #f1f5f9; border-left: 3px solid #94a3b8;
            padding: .75rem 1rem; margin: .75rem 0; font-size: .92rem; color: #475569;
            border-radius: 4px;
        }
        .help-step img.help-shot {
            max-width: 100%; height: auto; border: 1px solid #e2e8f0; border-radius: 6px;
            margin: .75rem 0; display: block;
        }
        .help-cta {
            text-align: center; padding: 2.5rem 1rem;
            background: #f8fafc; border-radius: 8px; margin-top: 2rem;
        }
        .help-cta p { color: #475569; margin-bottom: 1.25rem; }
        .help-back { display: inline-block; margin-bottom: 1rem; color: #64748b; text-decoration: none; font-size: .9rem; }
        .help-back:hover { color: #2563eb; }
    </style>
</head>
<body>
<?php require __DIR__ . '/_nav.php'; ?>

<div class="help-wrap">
    <a href="/" class="help-back">&larr; Back to home</a>
    <h1>Host Guide</h1>
    <p class="subtitle">Everything you need to run a game night, start to finish &mdash; from setting up your group to running the tournament clock.</p>

    <div class="help-step">
        <h2><span class="step-num">1</span> Set up your league <em style="font-weight:400;color:#94a3b8;font-size:1rem">(optional)</em></h2>
        <p>A <strong>league</strong> is your private group &mdash; your poker crew, board game club, or any circle. It scopes events, contacts, and stats so different groups don't see each other's stuff.</p>
        <p>From the home page, open <a href="/leagues.php"><strong>Leagues</strong></a> in the nav and create one. Give it a name and you're done.</p>
        <img class="help-shot" src="/img/help/leagues-create.png" alt="League creation form">
        <div class="hint"><strong>This step is optional</strong> &mdash; you can create and run events without a league at all. A league only matters when you want to keep separate groups' events, contacts, and stats apart. If you only ever host the same crew, you can skip it.</div>
    </div>

    <div class="help-step">
        <h2><span class="step-num">2</span> Add your roster <em style="font-weight:400;color:#94a3b8;font-size:1rem">(optional, but recommended)</em></h2>
        <p>Open <a href="/contacts.php"><strong>Contacts</strong></a> and add the people you'll invite. You can add them by name plus email or phone &mdash; <em>they don't need to sign up first</em>.</p>
        <ul>
            <li>Bulk-add by pasting a CSV of names and emails</li>
            <li>When a contact later creates an account on the site, they auto-link to the entry you already made &mdash; no double work</li>
        </ul>
        <img class="help-shot" src="/img/help/contacts-add.png" alt="Adding a contact">
        <div class="hint"><strong>Optional, but recommended.</strong> A saved roster makes inviting people in the next step a couple of clicks instead of retyping the same emails every event &mdash; but you can always invite someone who isn't in your contacts yet.</div>
    </div>

    <div class="help-step">
        <h2><span class="step-num">3</span> Create the event</h2>
        <p>Open the <a href="/calendar.php"><strong>Calendar</strong></a> and click <strong>New Event</strong> &mdash; or click the date you want directly on the grid. (You can also start one from <a href="/my_events.php"><strong>My Events</strong></a> with the <strong>+ New Event</strong> button.) The <strong>Add Event</strong> dialog opens.</p>
        <p>Fill in the core fields:</p>
        <ul>
            <li><strong>League</strong> &mdash; pick the group this event belongs to, or leave it on <strong>None</strong>.</li>
            <li><strong>Visibility</strong> &mdash; <em>Invitees only</em> (just the people you invite), <em>League members only</em> (everyone in the league can see it), or <em>Public</em>.</li>
            <li>A <strong>color</strong> swatch to tag the event on the calendar.</li>
            <li><strong>Title</strong> (required) and <strong>Date</strong> (required).</li>
            <li><strong>Time</strong> (optional) and <strong>Duration</strong> (&mdash;, 30m, 1h, up to 8h).</li>
        </ul>
        <p>Need notes for guests? Click <strong>+ Description</strong> to expand a description box. When everything looks right, click <strong>Add Event</strong> (the same button reads <strong>Save Changes</strong> when you reopen an event to edit it).</p>
        <img class="help-shot" src="/img/help/event-create.png" alt="Add Event dialog with title and date filled in">
        <div class="hint"><strong>Visibility</strong> controls who can <em>see</em> the event. Sending invitations is a separate step (next) &mdash; you can invite people to an Invitees-only event without making it visible to your whole league.</div>
    </div>

    <div class="help-step">
        <h2><span class="step-num">4</span> Invite your guests</h2>
        <p>Still in the event dialog, use the two-pane invite picker. <strong>All Users</strong> is on the left (search with the <em>Search name, email, phone&hellip;</em> box); <strong>Invited</strong> is on the right. Move people between the panes with the <strong>&gt;</strong> (add selected), <strong>&gt;&gt;</strong> (add all), <strong>&lt;</strong> (remove selected), and <strong>&lt;&lt;</strong> (remove all) buttons.</p>
        <ul>
            <li>Inviting someone who isn't in the system yet? Click <strong>+ Custom Invitee</strong> and type their email or phone inline &mdash; <em>they don't need an account</em>.</li>
            <li>For each invitee you can preset an <strong>RSVP</strong> (Yes / No / Maybe) and a <strong>Role</strong>: <em>Invitee</em> or <em>Manager</em> (a Manager can edit the event with you).</li>
            <li>On a league event, tick <strong>Hide non-members</strong> to narrow the left list to your league.</li>
        </ul>
        <p>When you save, every invitee gets a <strong>one-click RSVP link</strong> delivered however they prefer &mdash; email, SMS, or WhatsApp &mdash; so they can answer without logging in.</p>
        <img class="help-shot" src="/img/help/event-invite.png" alt="Invite picker showing All Users and Invited panes">
        <div class="hint">You don't have to line everyone up now &mdash; you can also add players <strong>later, during check-in</strong> on event day, by typing their name on the dashboard or letting them register through the walk-in QR code (see step 7).</div>
        <div class="hint">Each guest's contact method comes from their own profile, so the site routes each invite correctly &mdash; you don't pick the channel per person. Guests can also <strong>Sign up to attend</strong> on their own, and <strong>Leave this event</strong> later if plans change.</div>
    </div>

    <div class="help-step">
        <h2><span class="step-num">5</span> Adjust the event's settings</h2>
        <p>The toolbar across the top of the Add/Edit Event dialog has the toggles that shape how the event behaves:</p>
        <ul>
            <li><strong>Poker</strong> &mdash; turns on the poker setup: <strong>Type</strong> (<em>Tournament</em> or <em>Cash</em>), <strong>Buy-in $</strong>, <strong>Tables</strong> (1&ndash;50), <strong>Seats</strong> (2&ndash;12), and a <strong>Deadline</strong> (None / 24h / 48h / 72h). A capacity hint shows the total seats you've configured.</li>
            <li><strong>Waitlist</strong> (appears once Poker is on) &mdash; once you're at capacity, extra guests are automatically marked <strong>Waitlisted</strong>.</li>
            <li><strong>Mute</strong> &mdash; suppress notifications for this one event.</li>
            <li><strong>Approval</strong> &mdash; RSVPs need your sign-off; guests sit at <strong>Pending</strong> until you approve them.</li>
            <li><strong>Reminders</strong> (on by default) &mdash; expands a row of interval checkboxes: <strong>1 wk, 3 days, 2 days, 1 day, 12 hr, 2 hr, 30 min</strong>. Tick the ones you want sent automatically.</li>
        </ul>
        <p>To change any of this later, open the event, click <strong>Edit</strong>, adjust the toggles, and hit <strong>Save Changes</strong>.</p>
        <img class="help-shot" src="/img/help/event-settings.png" alt="Event dialog toolbar with Poker, Waitlist, Approval, and Reminders toggles">
        <div class="hint">As guests respond, each one carries a status: <strong>Approved</strong>, <strong>Pending</strong> (awaiting your approval), <strong>Waitlisted</strong> (past capacity), or <strong>Denied</strong>.</div>
    </div>

    <div class="help-step">
        <h2><span class="step-num">6</span> Track RSVPs</h2>
        <p>Open the event and look at the <strong>Invites</strong> list. You'll see each person's response &mdash; yes, no, maybe, or no answer yet &mdash; and you can change it for them or hit <strong>Resend</strong> to send their invitation again.</p>
        <p>Reminder messages go out automatically before the event &mdash; you don't need to nudge anyone manually.</p>
        <img class="help-shot" src="/img/help/event-rsvps.png" alt="Guest RSVP list">
    </div>

    <div class="help-step">
        <h2><span class="step-num">7</span> Start the game</h2>
        <p>On event day, open the event and go to <strong>Check-in</strong>. The first time you do, you'll see the <strong>Start Poker Session</strong> form:</p>
        <ul>
            <li><strong>Game Type</strong> &mdash; <em>Tournament</em> or <em>Cash Game</em>.</li>
            <li><strong>Buy-in $</strong>, and for tournaments also <strong>Rebuy $</strong>, <strong>Add-on $</strong>, <strong>Starting Chips</strong>, and <strong>Add-on Chips</strong>.</li>
            <li><strong>Number of Tables</strong>.</li>
        </ul>
        <p>Click <strong>Create Session &amp; Import Players</strong> &mdash; this pulls in everyone who RSVP'd Yes. On the check-in dashboard you can add walk-ins with the name field and <strong>+ Add</strong>, filter by <strong>All / RSVP Yes / Playing / Out</strong>, and let <strong>Balance</strong> auto-assign tables and seats. The <strong>QR</strong> button opens a registration screen players can scan to sign themselves in.</p>
        <img class="help-shot" src="/img/help/checkin-start.png" alt="Check-in dashboard after starting a session">
        <p>When you're ready to play, click the <strong>Timer</strong> button to launch the tournament clock. It loads your default blind structure automatically. To customize blinds, click <strong>Levels</strong> to open the <strong>Blind Structure</strong> editor (columns <strong>#, SB, BB, Ante, Min, Type</strong>) where you can <strong>+ Add Level</strong>, <strong>+ Add Break</strong>, then <strong>Save Changes</strong> &mdash; or <strong>Load</strong> / <strong>Save As</strong> / <strong>Set Default</strong> / <strong>Export</strong> / <strong>Import</strong> a preset.</p>
        <img class="help-shot" src="/img/help/blind-structure.png" alt="Blind Structure editor with levels and breaks">
        <p>Run the clock with <strong>Start</strong> / <strong>Pause</strong>, step levels with <strong>Next</strong> and <strong>Prev</strong>, nudge the clock with <strong>&minus;Min</strong> / <strong>+Min</strong>, and use <strong>Reset Level</strong> or <strong>Reset Timer</strong> if needed. <strong>TV</strong> opens a big-screen view for a projector, and <strong>Players</strong> lets you mark eliminations and rebuys as the night goes on.</p>
        <img class="help-shot" src="/img/help/timer-running.png" alt="Tournament timer running with blinds and clock">
        <p>That's it. After the event, results lock in and stats update automatically.</p>
        <div class="hint"><strong>Payouts aren't loaded by default.</strong> No payout structure is set up automatically, so the <strong>Payouts</strong> card starts empty. If you want payout tracking (who finishes in the money, and for how much), set up a split first &mdash; use <strong>Edit in Settings</strong> on the Payouts card, or the <strong>Payout</strong> button on the check-in dashboard.</div>
        <div class="hint">If you turned on <strong>Approval</strong> for the event, players who register by scanning the QR code land in <strong>pending approval</strong> until you wave them in from the check-in dashboard.</div>
    </div>

    <div class="help-cta">
        <p>Ready to host your first game night?</p>
        <div class="cta-group">
            <?php if ($allow_reg && !current_user()): ?>
            <a href="/register.php" class="btn btn-primary" style="padding:.65rem 2rem">Create Your Free Account</a>
            <?php elseif (!current_user()): ?>
            <a href="/login.php" class="btn btn-primary" style="padding:.65rem 2rem">Sign In</a>
            <?php else: ?>
            <a href="/leagues.php" class="btn btn-primary" style="padding:.65rem 2rem">Go to My Leagues</a>
            <?php endif; ?>
            <a href="/help-guests.php" class="btn btn-outline" style="padding:.65rem 2rem">Guest Guide</a>
        </div>
    </div>
</div>

<?php require __DIR__ . '/_footer.php'; ?>
</body>
</html>
