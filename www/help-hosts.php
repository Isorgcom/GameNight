<?php
require_once __DIR__ . '/auth.php';

// Without this the nav partial sees a GUEST even when someone is signed in
// ($user is only set inside require_login, which a public page never calls) —
// and with landing-page mode on, a guest gets no nav at all.
$current = current_user();

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
    <div class="hint" style="margin-bottom:1.5rem"><strong>New here?</strong> The home page shows a <em>Your first game night</em> card with the four steps that matter, each ticking itself off as you go, and the pages it points to carry short tips of their own. This guide is the long version of the same path.</div>

    <div class="help-step">
        <h2><span class="step-num">1</span> Set up your league <em style="font-weight:400;color:#94a3b8;font-size:1rem">(optional)</em></h2>
        <p>A <strong>league</strong> is your private group &mdash; your poker crew, board game club, or any circle. It scopes events, contacts, and stats so different groups don't see each other's stuff.</p>
        <p>From the home page, open <a href="/leagues.php"><strong>Leagues</strong></a> in the nav and create one. Give it a name and you're done.</p>
        <img class="help-shot" src="/img/help/leagues-create.png?v=<?= @filemtime(__DIR__ . '/img/help/leagues-create.png') ?: 0 ?>" alt="League creation form">
        <div class="hint"><strong>This step is optional</strong> &mdash; you can create and run events without a league at all. A league only matters when you want to keep separate groups' events, contacts, and stats apart. If you only ever host the same crew, you can skip it.</div>
    </div>

    <div class="help-step">
        <h2><span class="step-num">2</span> Add your roster <em style="font-weight:400;color:#94a3b8;font-size:1rem">(optional, but recommended)</em></h2>
        <p>Open <a href="/contacts.php"><strong>Contacts</strong></a> and add the people you'll invite. You can add them by name plus email or phone &mdash; <em>they don't need to sign up first</em>.</p>
        <ul>
            <li>Bulk-add by pasting a CSV of names and emails</li>
            <li>When a contact later creates an account on the site, they auto-link to the entry you already made &mdash; no double work</li>
        </ul>
        <img class="help-shot" src="/img/help/contacts-add.png?v=<?= @filemtime(__DIR__ . '/img/help/contacts-add.png') ?: 0 ?>" alt="Adding a contact">
        <div class="hint"><strong>Optional, but recommended.</strong> A saved roster makes inviting people in the next step a couple of clicks instead of retyping the same emails every event &mdash; but you can always invite someone who isn't in your contacts yet.</div>
    </div>

    <div class="help-step">
        <h2><span class="step-num">3</span> Create the event</h2>
        <p>Click <strong>+ Add Event</strong> on the home page or the <a href="/calendar.php"><strong>Calendar</strong></a>, or click the date you want on the calendar grid (<a href="/my_events.php"><strong>My Events</strong></a>, under your avatar menu, has the same button). The <strong>Add Event</strong> page opens with everything on one screen: the event itself across the top, a toolbar of options under it, and the guest list below.</p>
        <p>The core fields:</p>
        <ul>
            <li><strong>League</strong>: the group this event belongs to, or <strong>None</strong>.</li>
            <li><strong>Visibility</strong>: <em>Invitees only</em> (just the people you invite), <em>League members only</em> (everyone in the league can see it), or <em>Public</em>.</li>
            <li>The <strong>colour</strong> swatch beside the heading tags the event on the calendar.</li>
            <li><strong>Title</strong> (required), <strong>Date</strong> (required; today to start), <strong>Time</strong> (7:00 PM to start) and <strong>Duration</strong> (30m to 8h, or none).</li>
            <li><strong>Venue name</strong> and <strong>Address</strong>, both optional; the event page turns an address into an <em>Open in Maps</em> link.</li>
        </ul>
        <p>Need notes for guests? Click <strong>+ Description</strong>. Then the buttons at the right of the toolbar: with nobody on the guest list yet there is just <strong>Add Event</strong>. Once you have added guests it reads <strong>Save without sending</strong>, beside a green <strong>Save &amp; Send Invites</strong> that saves and sends every invitation straight away. Either way you land on the event's page, and anything unsent can be sent from there.</p>
        <img class="help-shot" src="/img/help/event-create.png?v=<?= @filemtime(__DIR__ . '/img/help/event-create.png') ?: 0 ?>" alt="The Add Event page with a title, date and venue filled in, the Poker and Reminders toggles on, and a single Add Event button">
        <div class="hint"><strong>Visibility</strong> controls who can <em>see</em> the event. Sending invitations is a separate step (next) &mdash; you can invite people to an Invitees-only event without making it visible to your whole league.</div>
    </div>

    <div class="help-step">
        <h2><span class="step-num">4</span> Invite your guests</h2>
        <p>The guest list is the bottom half of the same page. <strong>Invited</strong> is on the right. On the left, <strong>All users</strong> lists the people you can pick from: your saved Contacts who have an account, plus the members of the league you chose. A brand-new host sees only themselves there, which is normal. Search with the box at the top, then move people across with the arrow buttons (<strong>&rsaquo;</strong> adds the selected, <strong>&raquo;</strong> adds everyone, <strong>&lsaquo;</strong> and <strong>&laquo;</strong> take them back), or double-click a name.</p>
        <ul>
            <li>Inviting someone who isn't on the site? Click <strong>+ Add Name</strong> above the Invited list and type their name and an email address or phone number; they don't need an account. For a first event this is the way in, and everyone you add is saved to your Contacts for next time.</li>
            <li>For each invitee you can preset an <strong>RSVP</strong> (Yes / No / Maybe) and a <strong>Role</strong>: <em>Invitee</em> or <em>Manager</em> (a Manager can edit the event with you).</li>
            <li>On a league event, tick <strong>Hide non-members</strong> to narrow the left list to your league.</li>
        </ul>
        <p>Every invitee gets a <strong>one-tap RSVP link</strong> delivered however they prefer, email, SMS or WhatsApp, so they can answer without logging in. <strong>Save &amp; Send Invites</strong> sends them as you save; <strong>Save without sending</strong> holds them, and the event page offers a <strong>Send Invitations</strong> button when you're ready.</p>
        <img class="help-shot" src="/img/help/event-invite.png?v=<?= @filemtime(__DIR__ . '/img/help/event-invite.png') ?: 0 ?>" alt="The guest list: All users on the left, Invited on the right with two guests typed into + Add Name rows, and the Save & Send Invites button above">
        <div class="hint">You don't have to line everyone up now; you can also add players <strong>later, during check-in</strong> on event day, by typing their name on the dashboard or letting them register through the walk-in QR code (see step 7).</div>
        <div class="hint">Each guest's contact method comes from their own profile, so the site routes each invite correctly; you don't pick the channel per person. Guests with accounts can also <strong>Sign up to attend</strong> on their own and <strong>Leave this event</strong> later if plans change. You are not on your own guest list until you put yourself there: <strong>Add yourself to the guest list</strong> on the event page does it.</div>
    </div>

    <div class="help-step">
        <h2><span class="step-num">5</span> Adjust the event's settings</h2>
        <p>The toolbar under the event fields holds the options that shape how it behaves:</p>
        <ul>
            <li><strong>Poker</strong> (on to start) shows the poker row: <strong>Type</strong> (<em>Tournament</em> or <em>Cash</em>), <strong>Buy-in $</strong>, <strong>Tables</strong>, <strong>Seats</strong>, a <strong>Deadline</strong> for RSVPs (None / 24h / 48h / 72h), and <strong>Played</strong>: in person, or online at FinalTable, which adds a <strong>Game</strong> and <strong>Betting</strong> pick. A capacity line totals the seats.</li>
            <li><strong>Reminders</strong> (on to start): the <strong>Send reminders</strong> dropdown lists the intervals (<strong>1 wk, 3 days, 2 days, 1 day, 12 hr, 2 hr, 30 min</strong>); tick the ones you want sent automatically.</li>
            <li><strong>Guest options</strong> opens a small menu: <strong>Waitlist</strong> (once you're at capacity, extra guests are marked <em>Waitlisted</em>), <strong>Require approval</strong> (RSVPs need your sign-off; guests sit at <em>Pending</em> until you approve them), <strong>Hide guest list</strong>, and, when Poker is off, <strong>Max guests</strong> (blank for no limit; with Poker on, capacity is tables &times; seats).</li>
        </ul>
        <p>To change any of this later, open the event, click <strong>Edit</strong>, adjust, and <strong>Save Changes</strong>.</p>
        <img class="help-shot" src="/img/help/event-settings.png?v=<?= @filemtime(__DIR__ . '/img/help/event-settings.png') ?: 0 ?>" alt="The toolbar with Poker and Reminders on and the Guest options menu open, with the poker row beneath">
        <div class="hint">As guests respond, each one carries a status: <strong>Approved</strong>, <strong>Pending</strong> (awaiting your approval), <strong>Waitlisted</strong> (past capacity), or <strong>Denied</strong>.</div>
    </div>

    <div class="help-step">
        <h2><span class="step-num">6</span> Track RSVPs</h2>
        <p>Open the event and look at the <strong>Invites</strong> list. You'll see each person's response &mdash; yes, no, maybe, or no answer yet &mdash; and you can change it for them or hit <strong>Resend</strong> to send their invitation again.</p>
        <p>Reminder messages go out automatically before the event &mdash; you don't need to nudge anyone manually.</p>
        <img class="help-shot" src="/img/help/event-rsvps.png?v=<?= @filemtime(__DIR__ . '/img/help/event-rsvps.png') ?: 0 ?>" alt="Guest RSVP list">
    </div>

    <div class="help-step">
        <h2><span class="step-num">7</span> Start the game</h2>
        <p>On event day, open the event and go to <strong>Check-in</strong>. The first time you do, you'll see the <strong>Start Poker Session</strong> form:</p>
        <ul>
            <li><strong>Game Type</strong> &mdash; <em>Tournament</em> or <em>Cash Game</em>.</li>
            <li><strong>Buy-in $</strong>, and for tournaments also <strong>Rebuy $</strong>, <strong>Add-on $</strong>, <strong>Starting Chips</strong>, and <strong>Add-on Chips</strong>.</li>
            <li><strong>Number of Tables</strong>.</li>
        </ul>
        <p>Click <strong>Create Session &amp; Import Players</strong> &mdash; this pulls in everyone who RSVP'd Yes. On the check-in dashboard you can add walk-ins with the name field and <strong>+ Add</strong>, filter by <strong>All / RSVP Yes / Playing / Out</strong>, and switch to the <strong>Table</strong> view to seat people, where <strong>Balance</strong> auto-assigns tables and seats. The <strong>QR</strong> button opens a registration screen players can scan to sign themselves in.</p>
        <img class="help-shot" src="/img/help/checkin-start.png?v=<?= @filemtime(__DIR__ . '/img/help/checkin-start.png') ?: 0 ?>" alt="The check-in dashboard with a session running: player rows with buy-in, rebuys, table, seat and status, the Setup button, the List / Table / Log / Payouts / Chop views, and the prize pool">
        <p>When you're ready to play, click <strong>Timer</strong> to put the tournament clock on the big screen. It runs the blind schedule from <strong>Setup &rarr; Blinds</strong>, with your default structure loaded for you, breaks included. Everything about the game, blinds included, is edited in <strong>Setup</strong>, which is the next step.</p>
        <img class="help-shot" src="/img/help/blind-structure.png?v=<?= @filemtime(__DIR__ . '/img/help/blind-structure.png') ?: 0 ?>" alt="Setup → Blinds: the level grid with durations, blinds, antes, a break and start times">
        <p>The first time you open a tournament's check-in you're asked, once, which clock you'd like: the <strong>Tournament Timer</strong>, with designable layouts, break and final-table screens, a QR code that puts the clock on any phone or TV, and sounds; or <strong>Tournament Timer Classic</strong>, the original. Your answer becomes the default for new games, and any game can still switch under <strong>Setup &rarr; Timer</strong>.</p>
        <img class="help-shot" src="/img/help/timer-choice.png?v=<?= @filemtime(__DIR__ . '/img/help/timer-choice.png') ?: 0 ?>" alt="The one-time prompt: Try the Tournament Timer, with Not now, Keep Classic and Use the Tournament Timer buttons" style="max-width:420px">
        <p>If you can manage the game, the display carries a control tray along its bottom edge: start / stop, previous and next level, <strong>&minus;1m</strong> / <strong>+1m</strong>, reset the level, undo, fullscreen, and <strong>Exit</strong> back to check-in. From a keyboard, the space bar starts and stops and the arrow keys step a level. Everyone else sees a clean clock. Eliminations and rebuys are marked here on the check-in dashboard as the night goes on (every player row has them), and the display follows.</p>
        <img class="help-shot" src="/img/help/timer-running.png?v=<?= @filemtime(__DIR__ . '/img/help/timer-running.png') ?: 0 ?>" alt="The Tournament Timer's Default Layout on a live game: the clock, blinds, next level and next break, players, average stack, entries and prize pool, the QR code, and the control tray along the bottom">
        <div class="hint">The display, casting it to more screens and building a layout of your own are covered in the <a href="/help-timer.php">Timer Guide</a>. Prefer the original clock? <strong>Tournament Timer Classic</strong> is a switch away under Setup &rarr; Timer.</div>
        <p>That's it. After the event, results lock in and stats update automatically.</p>
        <div class="hint"><strong>Payouts aren't loaded by default.</strong> No payout structure is set up automatically, so the <strong>Payouts</strong> card starts empty. If you want payout tracking (who finishes in the money, and for how much), set up a split first &mdash; use <strong>Edit in Settings</strong> on the Payouts card, or the <strong>Payout</strong> button on the check-in dashboard.</div>
        <div class="hint">If you turned on <strong>Approval</strong> for the event, players who register by scanning the QR code land in <strong>pending approval</strong> until you wave them in from the check-in dashboard.</div>
    </div>

    <div class="help-step">
        <h2><span class="step-num">8</span> Set the game up</h2>
        <p>Everything that defines the game lives behind the <strong>Setup</strong> button on the check-in dashboard. It opens an editor with a tab per topic:</p>
        <ul>
            <li><strong>Game</strong> &mdash; game type, buy-in, rebuys and add-ons, starting chips, tables and seats, and which league the event belongs to.</li>
            <li><strong>Payouts &amp; Rewards</strong> &mdash; the payout split, plus points, entry-ticket values, prize labels, bounties and jackpots.</li>
            <li><strong>Blinds</strong> &mdash; the level schedule as an editable grid (<strong>Level, Duration, Small Blind, Big Blind, Ante, Start Time</strong>), with breaks, undo/redo, and a generator that builds a ladder for you.</li>
            <li><strong>Timer</strong> &mdash; whether this game uses Tournament Timer Classic or the Tournament Timer, and which layout its display shows. The <a href="/help-timer.php">Timer Guide</a> covers the display, casting to more screens, and the layout editor.</li>
            <li><strong>Chip set</strong> &mdash; the denominations and colours drawn on the display as a legend, so players can see what each colour is worth at colour-up.</li>
        </ul>
        <p>Blinds, Timer and Chip set apply to tournaments, so they're hidden for a cash game. Blinds and Timer also stay locked until the game has actually been saved as a tournament.</p>
        <img class="help-shot" src="/img/help/setup-editor.png?v=<?= @filemtime(__DIR__ . '/img/help/setup-editor.png') ?: 0 ?>" alt="The game Setup editor showing its Game, Payouts &amp; Rewards, Blinds, Timer and Chip set tabs">
        <p><strong>Save game</strong> commits the whole editor at once &mdash; every tab, including the blind schedule &mdash; and leaves you exactly where you were, on the same tab, with the button confirming <em>Saved &#10003;</em> for a moment. Setting a game up usually takes a few passes, so saving is a checkpoint rather than an exit. Use <strong>Close</strong> (or Escape) when you're done; if anything is still unsaved you'll be asked before it's discarded.</p>
        <div class="hint"><strong>Presets save the whole editor.</strong> The <strong>Game preset</strong> bar at the top stores game setup, payouts and rewards, the blind schedule and the timer settings as one reusable recipe &mdash; so next week's game is one <strong>Load</strong> away. The line above it always tells you whether this game came from a preset and whether it has drifted from it.</div>
    </div>

    <div class="help-step">
        <h2><span class="step-num">9</span> Put a video on the display <em style="font-weight:400;color:#94a3b8;font-size:1rem">(optional)</em></h2>
        <p>A layout cell can hold video &mdash; the ball game, a stream, a movie &mdash; alongside the clock and blinds. In the layout editor, pick a cell, choose <strong>Use a video stream instead</strong>, and paste a link. YouTube, Twitch, Vimeo and Kick work as-is.</p>
        <p>For anything else, GameNight needs one thing: <strong>a public https address ending in <code>.m3u8</code> or <code>.mp4</code></strong>. No approval, no admin, no allow-list &mdash; paste it and it plays. <code>.m3u8</code> is the format live streams use; <code>.mp4</code> is a plain file.</p>
        <p>How you get that address depends on your source:</p>
        <ul>
            <li><strong>It already gives you one.</strong> Most IPTV subscriptions hand you an https <code>.m3u8</code>. Paste it. Nothing to set up, and this covers most people.</li>
            <li><strong>It doesn't.</strong> A security camera, a raw TV feed or an RTMP stream can't play in a browser and has to be repackaged. Run a restreamer (datarhei Restreamer is the ready-made one) and put <strong>Cloudflare Tunnel</strong> or <strong>Tailscale Funnel</strong> in front of it &mdash; both hand you a public https address for free, with no port forwarding and no certificates to manage.</li>
        </ul>
        <div class="hint"><strong>It has to be https, and it has to be public.</strong> This site runs over https, and browsers refuse to load video over a plain <code>http</code> connection, so an http link can never play no matter how good the stream is. An address like <code>http://192.168.1.50:8080</code> fails twice over: it's not https, and it means nothing on anyone else's phone. If a link is rejected, the editor tells you exactly which of these went wrong.</div>
        <div class="hint"><strong>Everyone watching pulls from wherever the stream lives.</strong> If that's a box in your house, every screen showing it is using your upload at once. One TV in the room is comfortable; a dozen phones is not. Consider putting the video on the big screen and letting people use the QR code for the clock.</div>
        <p>For a live stream, set your restreamer to <strong>2-second segments</strong>. That puts the display about six to ten seconds behind live instead of half a minute. If the source is already H.264, copy it rather than re-encoding &mdash; re-encoding a live feed adds delay and CPU load for nothing.</p>
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
