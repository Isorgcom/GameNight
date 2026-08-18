<?php
require_once __DIR__ . '/auth.php';

// Without this the nav partial sees a GUEST even when someone is signed in
// ($user is only set inside require_login, which a public page never calls) —
// and with landing-page mode on, a guest gets no nav at all.
$current = current_user();

$site_name   = get_setting('site_name', 'Game Night');
$nav_active  = 'help';

// The one list the sidebar, the scrollspy and the prev/next pager all read.
$help_sections = [
    ['two-timers',    'Two timers, one switch'],
    ['choose-layout', 'Choose what the display shows'],
    ['running',       'Running the display'],
    ['qr-casting',    'More screens: the QR code'],
    ['building',      'Building a layout'],
    ['elements',      'Elements: live values in text'],
    ['conditions',    'Conditions'],
    ['variants',      'Variants'],
    ['artwork',       'Artwork: screen or box'],
    ['triggers',      'Sounds & triggers'],
    ['sharing',       'Sharing layouts'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Timer Guide &mdash; <?= htmlspecialchars($site_name) ?></title>
    <?php render_seo_meta('Timer Guide', 'How to run the tournament timer display: choose a layout, cast to a second screen with a QR code, build custom layouts, and use condition expressions.', 'help-timer.php'); ?>
    <link rel="stylesheet" href="/style.css?v=<?= htmlspecialchars(APP_VERSION . '.' . (@filemtime(__DIR__ . '/style.css') ?: 0)) ?>">
    <style>
        html { scroll-behavior: smooth; }
        .docs { display: grid; grid-template-columns: 250px minmax(0, 1fr); gap: 2.5rem;
                max-width: 1080px; margin: 0 auto 4rem; padding: 0 1.5rem; }

        /* ── Sidebar: always in view, always says where you are ── */
        .docs-side { position: sticky; top: calc(var(--pk-nav-h, 64px) + 1.25rem);
                     align-self: start; padding-top: 2rem;
                     max-height: calc(100vh - var(--pk-nav-h, 64px) - 2rem); overflow-y: auto; }
        .docs-side-title { font-size: 1.05rem; font-weight: 700; color: #0f172a; margin-bottom: .9rem; }
        /* The global `nav` element style (dark, sticky, z-100) is for the site
           bar; this inner nav is a light rail and must opt out of all of it. */
        .docs-side nav { display: flex; flex-direction: column; gap: 1px; border-left: 2px solid #e2e8f0;
                         background: none; position: static; z-index: auto; padding: 0; box-shadow: none; }
        .docs-side a { display: block; padding: .42rem .9rem; margin-left: -2px; border-left: 2px solid transparent;
                       color: #475569; text-decoration: none; font-size: .9rem; line-height: 1.35; }
        .docs-side a:hover { color: #0f172a; background: #f1f5f9; }
        .docs-side a.active { color: var(--accent, #2563eb); border-left-color: var(--accent, #2563eb);
                              font-weight: 600; background: #eff6ff; }
        .docs-side .docs-back { margin-top: 1.1rem; font-size: .82rem; color: #94a3b8; border-left: none; padding-left: 0; }
        .docs-side .docs-back:hover { color: #2563eb; background: none; }

        /* ── Content ── */
        .docs-main { padding-top: 2rem; min-width: 0; }
        .docs-main h1 { font-size: 2rem; margin: 0 0 .4rem; }
        .docs-main .subtitle { color: #64748b; margin-bottom: 2.2rem; font-size: 1.02rem; }
        .help-step { margin-bottom: 2.6rem; scroll-margin-top: calc(var(--pk-nav-h, 64px) + 1rem); }
        .help-step h2 { font-size: 1.3rem; margin: 0 0 .75rem; display: flex; align-items: center; gap: .6rem;
                        padding-top: 1.1rem; border-top: 1px solid #e2e8f0; }
        .help-step:first-of-type h2 { border-top: none; padding-top: 0; }
        .help-step .step-num { display: inline-flex; align-items: center; justify-content: center;
                               width: 30px; height: 30px; border-radius: 50%; flex-shrink: 0;
                               background: var(--accent, #2563eb); color: #fff; font-size: .9rem; font-weight: 600; }
        .help-step p { margin: .5rem 0; line-height: 1.65; color: #334155; }
        .help-step ul { margin: .5rem 0 .5rem 1.25rem; line-height: 1.65; color: #334155; }
        .help-step li { margin: .3rem 0; }
        .help-step .hint { background: #f1f5f9; border-left: 3px solid #94a3b8;
                           padding: .75rem 1rem; margin: .9rem 0; font-size: .92rem; color: #475569; border-radius: 4px; }
        .help-step code { background: #f1f5f9; border: 1px solid #e2e8f0; border-radius: 4px;
                          padding: .1rem .35rem; font-size: .86em; color: #0f172a;
                          font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
        .help-table { width: 100%; border-collapse: collapse; margin: .75rem 0; font-size: .9rem; }
        .help-table th, .help-table td { text-align: left; padding: .45rem .6rem; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
        .help-table th { color: #475569; font-size: .78rem; text-transform: uppercase; letter-spacing: .03em; }
        figure.help-shot { margin: 1rem 0; }
        figure.help-shot img { max-width: 100%; height: auto; display: block;
                               border: 1px solid #e2e8f0; border-radius: 8px; }
        figure.help-shot figcaption { font-size: .82rem; color: #64748b; margin-top: .4rem; line-height: 1.5; }
        .shot-pair { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
        @media (max-width: 640px) { .shot-pair { grid-template-columns: 1fr; } }
        .shot-narrow img { max-width: 340px; }

        /* ── Prev / next pager ── */
        .docs-pager { display: flex; gap: 1rem; margin-top: 3rem; }
        .docs-pager a { flex: 1; border: 1px solid #e2e8f0; border-radius: 8px; padding: .8rem 1rem;
                        text-decoration: none; color: #334155; background: #fff; }
        .docs-pager a:hover { border-color: var(--accent, #2563eb); }
        .docs-pager .dir { display: block; font-size: .72rem; text-transform: uppercase;
                           letter-spacing: .05em; color: #94a3b8; margin-bottom: .2rem; }
        .docs-pager .next { text-align: right; }

        /* ── Small screens: the sidebar becomes a sticky contents bar ── */
        .docs-m-toggle { display: none; }
        @media (max-width: 860px) {
            .docs { display: block; }
            .docs-side { display: none; position: fixed; top: var(--pk-nav-h, 56px); left: 0; right: 0;
                         background: #fff; z-index: 90; padding: 1rem 1.5rem 1.25rem;
                         border-bottom: 1px solid #e2e8f0; box-shadow: 0 12px 24px rgba(15,23,42,.12);
                         max-height: calc(100vh - var(--pk-nav-h, 56px)); }
            .docs-side.open { display: block; }
            .docs-m-toggle { display: flex; align-items: center; gap: .5rem; position: sticky;
                             top: var(--pk-nav-h, 56px); z-index: 80; width: 100%;
                             background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px;
                             padding: .6rem .9rem; margin: 0 0 1.2rem; font-size: .9rem; font-weight: 600;
                             color: #334155; cursor: pointer; }
            .docs-m-toggle::after { content: '▾'; margin-left: auto; color: #94a3b8; }
            .docs-m-toggle.open::after { content: '▴'; }
        }
    </style>
</head>
<body>
<?php require __DIR__ . '/_nav.php'; ?>

<div class="docs">
    <aside class="docs-side" id="docsSide">
        <div class="docs-side-title">Timer Guide</div>
        <nav aria-label="Sections">
            <?php foreach ($help_sections as $i => $sec): ?>
            <a href="#<?= $sec[0] ?>" data-sec="<?= $sec[0] ?>"><?= htmlspecialchars($sec[1]) ?></a>
            <?php endforeach; ?>
        </nav>
        <a class="docs-back" href="/">&larr; Back to home</a>
    </aside>

    <main class="docs-main">
        <button type="button" class="docs-m-toggle" id="docsMToggle">On this page</button>
        <h1>Timer Guide</h1>
        <p class="subtitle">Running the tournament clock on a big screen: picking a layout, casting to more screens, and building a display of your own.</p>

<div class="help-step" id="two-timers">
        <h2><span class="step-num">1</span> Two timers, one switch</h2>
        <p>Every game has the <strong>classic timer</strong>, and a newer <strong>BETA timer</strong> with custom layouts, break screens and multi-screen casting. Switch per game in check-in under <strong>Setup &rarr; Timer &rarr; Use BETA timer</strong>. The Timer button then opens whichever one is on, and you can switch back any time.</p>
        <p>You can also make the new timer your default. The first time you open a tournament's check-in console you'll be asked once which timer you'd like; whichever you answer is remembered, and it's never asked again. After that, <strong>new games you set up start on the timer you chose</strong>, while each game's own switch still wins and games you've already configured are left alone. Change your mind any time under <strong>Settings &rarr; Tournament timer</strong>.</p>
    </div>

    <div class="help-step" id="choose-layout">
        <h2><span class="step-num">2</span> Choose what the display shows</h2>
        <p>On the same Setup &rarr; Timer pane, pick the <strong>layout</strong> this game's display uses: one of the built-ins, or any layout you have saved. Load a layout in the editor and the bar above it asks the only question that matters &mdash; <strong>Use this layout for <em>your event</em></strong> &mdash; with a yes/no switch. Flip it on and that layout drives the game's display; flip it off and the display goes back to the default. When some other layout is already on the display, the bar names it, so switching on reads as a replacement rather than a first choice. The display follows your choice live, so you can change it mid-game and every connected screen updates without a reload.</p>
        <p>A game with nothing chosen shows <strong>Default Layout</strong>, the built-in feature tour &mdash; which is also what the editor opens on for that game, so what you see while editing is what the TV is showing.</p>
        <figure class="help-shot">
            <img src="/img/help/timer-pcf.jpg" alt="The PCF Poker Chip Forum built-in layout: dark felt, glossy plates, clock, blinds, stats panel and chip legend" loading="lazy">
            <figcaption>The <strong>PCF Poker Chip Forum</strong> built-in &mdash; every example in this guide is drawn from it.</figcaption>
        </figure>
        <p>The <strong>chip set</strong> has its own tab next to Timer: the denominations in play with their colours, drawn on the display as a legend wherever the layout puts one. It rides along with a game preset, so a recurring game keeps its chips. Each chip can also carry a photo of the real thing instead of a flat colour.</p>
    </div>

    <div class="help-step" id="running">
        <h2><span class="step-num">3</span> Running the display</h2>
        <ul>
            <li><strong>Fullscreen:</strong> the round button in the bottom corner. It fades out when nothing is moving and comes back on any touch.</li>
            <li><strong>iPad / iPhone:</strong> Safari cannot go fullscreen on its own. Tap <strong>Share &rarr; Add to Home Screen</strong>; opening the timer from that icon fills the screen, and the icon remembers this game.</li>
            <li><strong>Staying awake:</strong> a phone or tablet showing the timer is kept awake automatically. If the device needs a tap first, a banner says so; tap anywhere once.</li>
            <li><strong>Controls:</strong> if you can manage the game, a control tray appears (start/stop, level skip, add or remove a minute). Anyone else sees a clean display with no controls.</li>
            <li><strong>Stays current:</strong> a display left open on a TV updates itself. When a new version of the site ships, every open timer screen notices within a few seconds and reloads on its own; you never need to walk over and refresh it.</li>
        </ul>
    </div>

    <div class="help-step" id="qr-casting">
        <h2><span class="step-num">4</span> More screens: the QR code</h2>
        <p>Add a <strong>QR code</strong> cell to a layout and any phone, tablet or TV browser that scans it opens the same timer, live and in sync, showing the same layout you chose. No account needed.</p>
        <div class="hint"><strong>Scanning only ever grants viewing.</strong> The link in the code shows the display; whether that device also gets controls depends on who is signed in on it. A guest's phone is a spectator screen, your own tablet is a remote control.</div>
    </div>

    <div class="help-step" id="building">
        <h2><span class="step-num">5</span> Building a layout</h2>
        <p>Open <strong>Timer Layouts</strong> from the site menu (or the Edit button next to the layout picker). The editor shows a live preview; everything is reachable two ways:</p>
        <ul>
            <li><strong>Right-click anything</strong>, in the preview or in the structure tree, for its full menu: text, size, colour, alignment, padding, duplicate, delete, and screen-wide options like background image, screen shape and panel colours.</li>
            <li><strong>Drag in the preview:</strong> drag a boundary between boxes to resize them, drag a box to move it. One Ctrl+Z undoes a whole drag.</li>
        </ul>
        <p>A layout is rows and columns of cells. A cell's share of space is its <strong>weight</strong>; a cell with no weight hugs its content. Sizes are a share of the screen, so a layout built on a laptop fills a projector.</p>
        <div class="hint"><strong>Text never escapes its box.</strong> A cell's size is a maximum, not a promise: when a value outgrows the box you sized it in (blinds double every round, and by round 19 that cell holds <code>2,000,000 / 4,000,000</code>), the text wraps or shrinks inside the box, then returns to full size when values shorten. You size cells for the values you can see; the engine handles the ones you can't.</div>
        <p><strong>Padding</strong> is a box's inside margin: the gap between its edge and its own content, written CSS-style in the Inspector &mdash; one value for all sides, two for top/bottom &amp; left/right, four for top&nbsp;right&nbsp;bottom&nbsp;left. Use <code>vh</code> (% of screen height) and <code>vw</code> (% of screen width) so the gap scales with the display. While the Padding field has focus, the preview marks the padding as <strong>green bands</strong> with a dashed line around the space the content actually gets, and the bands follow every keystroke, so you can watch the room appear before you commit. Hovering the field shows the same reference as a tooltip.</p>
        <figure class="help-shot">
            <img src="/img/help/timer-padding.png" alt="The Blinds plate with its padding shown as green bands and a dashed outline around the content area" loading="lazy">
            <figcaption>Focus the Padding field and the preview shows where the padding sits. This plate pads its top so the value stays clear of the painted <em>Blinds</em> tab &mdash; the most common reason to pad at all.</figcaption>
        </figure>
    </div>

    <div class="help-step" id="elements">
        <h2><span class="step-num">6</span> Elements: live values in text</h2>
        <p>Type <code>&lt;clock&gt;</code> in a cell and the display keeps it live. Any cell can mix plain text and elements: <code>Round &lt;round.num&gt; of &lt;round.total&gt;</code>. Names are grouped by subject with a dot, so related ones sort and read together. The editor's element picker lists them all with their current value. The families:</p>
        <table class="help-table">
            <tr><th>Event / game</th><td><code>event.name &middot; game.name &middot; game.next</code></td></tr>
            <tr><th>Round</th><td><code>round.num &middot; round.orBreak &middot; round.total &middot; round.toBreak</code></td></tr>
            <tr><th>Time</th><td><code>clock &middot; time.now &middot; time.elapsed &middot; time.nextBreak &middot; time.start</code></td></tr>
            <tr><th>Blinds</th><td><code>blinds.small &middot; blinds.big &middot; blinds.ante &middot; blinds.now &middot; blinds.next &middot; blinds.nextSmall &middot; blinds.nextBig &middot; blinds.nextAnte</code></td></tr>
            <tr><th>Players</th><td><code>players.line &middot; players.left &middot; players.total &middot; players.entries &middot; players.buyIns &middot; players.rebuys &middot; players.addOns &middot; players.out &middot; players.cashed &middot; players.lastOut &middot; players.lastOutPlace</code></td></tr>
            <tr><th>Chips</th><td><code>chips.total &middot; chips.avg &middot; chips.avgBB &middot; chips.start &middot; chips.addOn</code></td></tr>
            <tr><th>Money</th><td><code>money.pot &middot; money.bounty &middot; money.jackpot &middot; money.buyIn &middot; money.rebuy &middot; money.addOn &middot; money.line</code></td></tr>
            <tr><th>Prizes</th><td><code>prizes.line &middot; prizes.list &middot; prizes.stacked</code></td></tr>
            <tr><th>Room</th><td><code>table.count &middot; table.seats</code></td></tr>
        </table>
        <div class="hint">Capitalisation never matters. A name the timer doesn't know shows as &#10216;name&#10217; on screen instead of vanishing, so typos stay visible.</div>
        <p><strong>Styling one element apart from its line:</strong> select the cell and open <strong>Element styles</strong> in the inspector. It offers the elements present in that cell's text; pick one, and give it its own colour, bold, or a size relative to the line (0.7 means 70% of the surrounding text). The classic use, an ante that only shows on ante rounds and stands out when it does:</p>
        <ul>
            <li>Cell <strong>Text</strong>: <code>&lt;blinds.small&gt; / &lt;blinds.big&gt;</code></li>
            <li>Add a <strong>variant</strong> with condition <code>hasAnte</code> and text <code>&lt;blinds.small&gt; / &lt;blinds.big&gt; / &lt;blinds.ante&gt;</code></li>
            <li><strong>Element styles</strong> &rarr; <code>&lt;blinds.ante&gt;</code> &rarr; orange, bold, size 0.7</li>
        </ul>
        <div class="shot-pair">
            <figure class="help-shot">
                <img src="/img/help/timer-ante-off.jpg" alt="Blinds plate showing 100 / 200, no ante" loading="lazy">
                <figcaption>Rounds without an ante: the base text.</figcaption>
            </figure>
            <figure class="help-shot">
                <img src="/img/help/timer-ante-on.jpg" alt="Blinds plate showing 100 / 200 / 25 with the ante smaller, bold and tinted" loading="lazy">
                <figcaption>An ante round: the variant swaps the text in, and the element style makes the ante its own.</figcaption>
            </figure>
        </div>
        <figure class="help-shot shot-narrow">
            <img src="/img/help/timer-elstyles.png" alt="The Element styles panel in the inspector: ante entry with colour, bold and size fields" loading="lazy">
            <figcaption>The <strong>Element styles</strong> panel that produced it: <code>&lt;blinds.ante&gt;</code> with a colour, bold, and size 0.7.</figcaption>
        </figure>
        <p>No ante, plain blinds; ante rounds get the long form with just the ante highlighted. Element styles follow the element through variants, and they scale with the line if a long value makes the whole cell shrink.</p>
        <p>You can also define your own fixed-text elements per layout (sponsor name, house rules line) under the editor's custom elements.</p>
    </div>

    <div class="help-step" id="conditions">
        <h2><span class="step-num">7</span> Conditions: show things only when they apply</h2>
        <p>Screens and cells can carry a <strong>condition</strong>. The simplest is a state: show this screen <em>on break</em>, show this cell <em>when the game is over</em>. Conditional screens are checked top to bottom and the first match wins, which is how a break screen takes over during breaks.</p>
        <p>For anything beyond states, write an <strong>expression</strong>:</p>
        <table class="help-table">
            <tr><td><code>blinds.big &gt; 10000</code></td><td>once the big blind passes 10,000</td></tr>
            <tr><td><code>players.left &lt;= 9 and not onBreak</code></td><td>final table, but not during a break</td></tr>
            <tr><td><code>(round &gt;= 6 or entries &gt; 20) and running</code></td><td>grouping with parentheses</td></tr>
            <tr><td><code>minutesLeft &lt; 5</code></td><td>the last five minutes of a level</td></tr>
        </table>
        <p>Comparisons are <code>&lt; &lt;= &gt; &gt;= = !=</code>, joined with <code>and</code>, <code>or</code>, <code>not</code>. The values you can test: <code>round &middot; blinds.small &middot; blinds.big &middot; blinds.ante &middot; players.left &middot; players.total &middot; players.entries &middot; players.buyIns &middot; players.rebuys &middot; players.addOns &middot; players.out &middot; chips.total &middot; chips.avg &middot; money.pot &middot; table.count &middot; table.seats &middot; clock.minutes &middot; clock.seconds</code>, and the true/false states <code>running paused onBreak preGame gameOver hasAnte hasRebuys</code>.</p>
        <p>Three more tell you <em>what kind of screen is watching</em>: <code>mobile</code>, <code>tablet</code>, <code>desktop</code>. With QR casting the same layout runs on every scanned device at once, so a cell with <code>when: desktop</code> puts the QR code on the TV only, and <code>not mobile</code> hides a dense stats block on phones. A phone stays a phone when rotated, and a touch-screen laptop counts as a desktop.</p>
        <p><strong>The final table, automatically:</strong> add a screen with the condition <code>players.left &lt;= 10 and players.left &gt; 1</code> and put a <strong>seat map</strong> cell on it (right-click a cell &rarr; <em>Use a seat map instead</em>). The seat map draws every remaining player at their assigned seat &mdash; their avatar or initials, name, and seat number &mdash; using the table and seat assignments from check-in. The moment the field drops to ten, every screen switches to it by itself; the PCF built-in ships with this screen ready-made.</p>
        <figure class="help-shot">
            <img src="/img/help/timer-finaltable.jpg" alt="The PCF Final Table screen: nine players around an oval table, each at their seat with an initials disc and name" loading="lazy">
            <figcaption>PCF's built-in Final Table screen. Players with a profile photo get it; everyone else gets their initials on their own colour.</figcaption>
        </figure>
        <figure class="help-shot shot-narrow">
            <img src="/img/help/timer-expression.png" alt="The Show when field with a valid expression and the list of comparable values" loading="lazy">
            <figcaption>The expression checks itself as you type, and the values you can compare are listed right below.</figcaption>
        </figure>
        <div class="hint">The editor checks the expression as you type and names anything it doesn't recognise. A condition with a mistake in it never matches, so a typo can't make something show at the wrong moment.</div>
    </div>

    <div class="help-step" id="variants">
        <h2><span class="step-num">8</span> Variants: one cell, different looks</h2>
        <p>A cell can hold <strong>variants</strong>: alternate text, colour, background, bold or opacity, each behind its own condition. The first matching variant wins; with no match the cell shows its base look. That is how "show A, else B" works: the base is B, a variant with your condition is A. The built-in clock uses the same machinery to turn amber in the last minute and red at the end.</p>
    </div>

    <div class="help-step" id="artwork">
        <h2><span class="step-num">9</span> Artwork: on the screen, or on the box</h2>
        <p>There are two places a picture can live, and choosing right saves a lot of nudging:</p>
        <ul>
            <li><strong>On a box (preferred for plates and panels):</strong> right-click any cell or container &rarr; <strong>Box image</strong>. The picture becomes that box's own background and moves, resizes and reflows <em>with</em> it, so a value can never drift off its plate, on any screen shape. Default fit is Stretch, because plate art is drawn for the box it decorates; Cover and Contain are there too. The PCF built-in is made this way: the felt is the screen, every glossy plate rides its own box. Art with a label or a mascot painted into it pairs with <strong>Padding</strong> (<a href="#building">Building a layout</a>): pad that side so the text starts clear of the painted part, and focus the Padding field to see exactly where the clearance sits.</li>
            <li><strong>On the screen:</strong> right-click the screen &rarr; <strong>Screen background</strong> for the backdrop itself, a colour or a full-screen picture (felt, a poster, league branding).</li>
            <li><strong>Picking an image opens your library first</strong> &mdash; everything you've already uploaded plus the built-in artwork (the PCF felt and plates are all reusable). Uploading a new file is the button in the corner, and re-using an existing image costs nothing against the daily upload allowance.</li>
        </ul>
        <figure class="help-shot">
            <img src="/img/help/timer-pcf-wide.jpg" alt="The same PCF layout filling an ultrawide screen, every plate stretched with its box" loading="lazy">
            <figcaption>The same PCF layout on an ultrawide screen: no black bars, no drift &mdash; each plate simply rides its box.</figcaption>
        </figure>
        <div class="hint"><strong>Don't paint buttons into a full-screen picture.</strong> A screen image with plates drawn into it forces the layout to land cells on pixels it can't see, and they drift the moment the screen shape changes. Give each plate to its box instead. (The QR code needs no plate at all; it brings its own white backing.)</div>
        <p>For a full-screen design that must keep its exact proportions anyway, the old tools remain: <strong>Screen shape</strong> locks the layout to the shape the artwork was drawn at and letterboxes elsewhere; <strong>Fit: Stretch</strong> distorts the picture to cover the layout's area instead of cropping; <strong>Panel colours</strong> switches the whole layout between painting its cell backgrounds and letting artwork show through.</p>
    </div>

    <div class="help-step" id="triggers">
        <h2><span class="step-num">10</span> Sounds &amp; triggers</h2>
        <p>A <strong>trigger</strong> makes something happen the moment a condition <em>becomes</em> true: play a sound, flash the screen, speak a line, or take over the display with another screen for a few seconds. The <strong>Triggers</strong> bar sits right under the preview, next to the preview-state toggles &mdash; click it to fold the panel open. <strong>+ Add trigger</strong> offers a list of ready-made triggers (level-change chime, one-minute warning, final-table fanfare&hellip;) to pick and tweak, or start from a blank one.</p>
        <p>Each trigger is a condition plus a list of actions:</p>
        <ul>
            <li><strong>Play sound</strong> &mdash; a dozen built-in tones (chime, horn, tick, casino&hellip;) that work everywhere, or upload your own MP3/WAV. Uploaded sounds live in your library and travel inside exports like images do.</li>
            <li><strong>Show screen</strong> &mdash; jump to a named screen for 1&ndash;120 seconds, then return. Good for a "BLINDS UP" splash or a final-table fanfare page.</li>
            <li><strong>Flash</strong> &mdash; a short amber pulse around the whole display.</li>
            <li><strong>Announce</strong> &mdash; the display speaks the line out loud, with elements filled in: <code>Blinds up: &lt;blinds.now&gt;</code> says the actual numbers.</li>
        </ul>
        <p>The conditions you'll reach for most:</p>
        <ul>
            <li><code>levelChange</code> &mdash; true for an instant whenever the round number moves. The classic "new level" chime.</li>
            <li><code>clock.seconds &lt;= 60 and running</code> &mdash; the one-minute warning. It naturally re-arms each level.</li>
            <li><code>players.left &lt;= 10 and players.left &gt; 1</code> &mdash; final table reached. Pair it with <strong>once</strong> so it fires a single time.</li>
            <li><code>playerEliminated</code> &mdash; someone was just knocked out (undoing an elimination stays silent). Pair it with the <code>&lt;players.lastOut&gt;</code> element: announce <code>&lt;players.lastOut&gt; has been eliminated</code> and the display speaks the actual name. <code>&lt;players.lastOutPlace&gt;</code> adds their finishing place.</li>
        </ul>
        <figure class="help-shot">
            <img src="/img/help/timer-triggers.png" alt="The Triggers panel in the layout editor: a levelChange trigger playing a chime, with Test and Remove buttons" loading="lazy">
            <figcaption>The Triggers panel on the PCF built-in: <code>levelChange</code> plays a chime. The green tick means the condition parses; ▶ Test runs the actions right now.</figcaption>
        </figure>
        <p>Triggers fire on the <em>change</em>, never on the state: a screen that joins mid-game stays quiet about things that were already true, and a condition must go false and come true again before its trigger fires twice. <strong>Cooldown</strong> sets a minimum quiet time between fires; <strong>once per game</strong> means exactly that. The <strong>&#9654; Test</strong> button on each trigger runs its actions in the preview immediately &mdash; the quickest way to audition a sound.</p>
        <div class="hint"><strong>Who hears what:</strong> the main display sounds by default, but a screen someone opened by scanning the QR code is their phone &mdash; it starts muted. Every display gets a speaker button in the corner (next to fullscreen) to switch sounds on or off; the choice sticks per device. The PCF built-in ships with a level-change chime, a one-minute warning and a final-table fanfare, so the fastest way to hear triggers is to load PCF and press ▶ Test.</div>
    </div>

    <div class="help-step" id="sharing">
        <h2><span class="step-num">11</span> Sharing layouts</h2>
        <p><strong>Export</strong> downloads a layout as a single <code>.gntimer.json</code> file with every image <em>and sound</em> embedded, so it carries its own artwork and audio. <strong>Import</strong> reads one back in, re-uploads the media here, and saves it as a new layout in your list. That's the way to move a design between installs or share it with another host.</p>
    </div>

    <div class="help-cta" style="text-align:center;padding:2.5rem 1rem;background:#f8fafc;border-radius:8px;margin-top:2rem">
        <p style="color:#475569;margin-bottom:1.25rem">Layouts are safe to experiment with: the editor has undo everywhere, and a game only shows the layout you point it at.</p>
        <a href="/timer_beta_edit.php" class="btn btn-primary" style="text-decoration:none">Open the layout editor</a>
    </div>

        <nav class="docs-pager" aria-label="Section pager" id="docsPager"></nav>
    </main>
</div>

<script nonce="<?= csp_nonce() ?>">
(function () {
    'use strict';
    // The sticky site nav's real height, so the sidebar and anchors park
    // beneath it. OBSERVED, not measured once: a load-time measurement runs
    // before the banner image arrives and records the short pre-banner nav,
    // and the sidebar then rides up underneath it once the banner pops in.
    var mainNav = document.getElementById('mainNav');
    function navH() {
        document.documentElement.style.setProperty('--pk-nav-h',
            (mainNav ? mainNav.offsetHeight : 0) + 'px');
    }
    navH();
    if (mainNav && typeof ResizeObserver !== 'undefined') {
        new ResizeObserver(navH).observe(mainNav);
    } else {
        window.addEventListener('resize', navH);
        window.addEventListener('load', navH);
    }

    var links = Array.prototype.slice.call(document.querySelectorAll('.docs-side a[data-sec]'));
    var steps = links.map(function (a) { return document.getElementById(a.getAttribute('data-sec')); });

    // Scrollspy: the section nearest the top of the viewport owns the
    // highlight. Cheap scan on scroll — ten sections, no observer juggling.
    var ticking = false;
    function spy() {
        ticking = false;
        var line = (mainNav ? mainNav.offsetHeight : 0) + 80;
        var current = 0;
        for (var i = 0; i < steps.length; i++) {
            if (steps[i] && steps[i].getBoundingClientRect().top <= line) current = i;
        }
        links.forEach(function (a, i) { a.classList.toggle('active', i === current); });
    }
    window.addEventListener('scroll', function () {
        if (!ticking) { ticking = true; requestAnimationFrame(spy); }
    }, { passive: true });
    spy();

    // Small screens: the sidebar is a dropdown under the contents bar; picking
    // a section closes it.
    var side = document.getElementById('docsSide');
    var toggle = document.getElementById('docsMToggle');
    toggle.addEventListener('click', function () {
        side.classList.toggle('open');
        toggle.classList.toggle('open');
    });
    links.forEach(function (a) {
        a.addEventListener('click', function () {
            side.classList.remove('open');
            toggle.classList.remove('open');
        });
    });

    // Prev / next pager, built from the same list the sidebar renders.
    var pager = document.getElementById('docsPager');
    function pagerFor(idx) {
        pager.textContent = '';
        function card(i, dir, label) {
            var a = document.createElement('a');
            a.href = '#' + links[i].getAttribute('data-sec');
            a.className = dir;
            var d = document.createElement('span');
            d.className = 'dir'; d.textContent = label;
            var t = document.createElement('span');
            t.textContent = links[i].textContent;
            a.appendChild(d); a.appendChild(t);
            pager.appendChild(a);
        }
        if (idx > 0) card(idx - 1, 'prev', 'Previous');
        if (idx < links.length - 1) card(idx + 1, 'next', 'Next');
    }
    // Follow the spy: the pager always offers the neighbours of where you are.
    var lastActive = -1;
    setInterval(function () {
        var idx = links.findIndex(function (a) { return a.classList.contains('active'); });
        if (idx !== -1 && idx !== lastActive) { lastActive = idx; pagerFor(idx); }
    }, 400);
})();
</script>

<?php require __DIR__ . '/_footer.php'; ?>
</body>
</html>
