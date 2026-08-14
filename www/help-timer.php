<?php
require_once __DIR__ . '/auth.php';

$site_name   = get_setting('site_name', 'Game Night');
$nav_active  = 'help';
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
        .help-step code {
            background: #f1f5f9; border: 1px solid #e2e8f0; border-radius: 4px;
            padding: .1rem .35rem; font-size: .88em; color: #0f172a;
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
        }
        .help-table { width: 100%; border-collapse: collapse; margin: .75rem 0; font-size: .9rem; }
        .help-table th, .help-table td { text-align: left; padding: .45rem .6rem; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
        .help-table th { color: #475569; font-size: .8rem; text-transform: uppercase; letter-spacing: .03em; }
        .help-back { display: inline-block; margin-bottom: 1rem; color: #64748b; text-decoration: none; font-size: .9rem; }
        .help-back:hover { color: #2563eb; }
    </style>
</head>
<body>
<?php require __DIR__ . '/_nav.php'; ?>

<div class="help-wrap">
    <a href="/" class="help-back">&larr; Back to home</a>
    <h1>Timer Guide</h1>
    <p class="subtitle">Running the tournament clock on a big screen: picking a layout, casting to more screens, and building a display of your own.</p>

    <div class="help-step">
        <h2><span class="step-num">1</span> Two timers, one switch</h2>
        <p>Every game has the <strong>classic timer</strong>, and a newer <strong>BETA timer</strong> with custom layouts, break screens and multi-screen casting. Switch per game in check-in under <strong>Setup &rarr; Timer &rarr; Use BETA timer</strong>. The Timer button then opens whichever one is on, and you can switch back any time.</p>
    </div>

    <div class="help-step">
        <h2><span class="step-num">2</span> Choose what the display shows</h2>
        <p>On the same Setup &rarr; Timer pane, pick the <strong>layout</strong> this game's display uses: one of the built-ins, or any layout you have saved. The display follows your choice live, so you can change it mid-game and every connected screen updates without a reload.</p>
        <p>The <strong>chip set</strong> lives here too: the denominations in play with their colours, drawn on the display as a legend wherever the layout puts one. It rides along with a game preset, so a recurring game keeps its chips. Each chip can also carry a photo of the real thing instead of a flat colour.</p>
    </div>

    <div class="help-step">
        <h2><span class="step-num">3</span> Running the display</h2>
        <ul>
            <li><strong>Fullscreen:</strong> the round button in the bottom corner. It fades out when nothing is moving and comes back on any touch.</li>
            <li><strong>iPad / iPhone:</strong> Safari cannot go fullscreen on its own. Tap <strong>Share &rarr; Add to Home Screen</strong>; opening the timer from that icon fills the screen, and the icon remembers this game.</li>
            <li><strong>Staying awake:</strong> a phone or tablet showing the timer is kept awake automatically. If the device needs a tap first, a banner says so; tap anywhere once.</li>
            <li><strong>Controls:</strong> if you can manage the game, a control tray appears (start/stop, level skip, add or remove a minute). Anyone else sees a clean display with no controls.</li>
            <li><strong>Stays current:</strong> a display left open on a TV updates itself. When a new version of the site ships, every open timer screen notices within a few seconds and reloads on its own; you never need to walk over and refresh it.</li>
        </ul>
    </div>

    <div class="help-step">
        <h2><span class="step-num">4</span> More screens: the QR code</h2>
        <p>Add a <strong>QR code</strong> cell to a layout and any phone, tablet or TV browser that scans it opens the same timer, live and in sync, showing the same layout you chose. No account needed.</p>
        <div class="hint"><strong>Scanning only ever grants viewing.</strong> The link in the code shows the display; whether that device also gets controls depends on who is signed in on it. A guest's phone is a spectator screen, your own tablet is a remote control.</div>
    </div>

    <div class="help-step">
        <h2><span class="step-num">5</span> Building a layout</h2>
        <p>Open <strong>Timer Layouts</strong> from the site menu (or the Edit button next to the layout picker). The editor shows a live preview; everything is reachable two ways:</p>
        <ul>
            <li><strong>Right-click anything</strong>, in the preview or in the structure tree, for its full menu: text, size, colour, alignment, padding, duplicate, delete, and screen-wide options like background image, screen shape and panel colours.</li>
            <li><strong>Drag in the preview:</strong> drag a boundary between boxes to resize them, drag a box to move it. One Ctrl+Z undoes a whole drag.</li>
        </ul>
        <p>A layout is rows and columns of cells. A cell's share of space is its <strong>weight</strong>; a cell with no weight hugs its content. Sizes are a share of the screen, so a layout built on a laptop fills a projector.</p>
        <div class="hint"><strong>Text never escapes its box.</strong> A cell's size is a maximum, not a promise: when a value outgrows the box you sized it in (blinds double every round, and by round 19 that cell holds <code>2,000,000 / 4,000,000</code>), the text wraps or shrinks inside the box, then returns to full size when values shorten. You size cells for the values you can see; the engine handles the ones you can't.</div>
    </div>

    <div class="help-step">
        <h2><span class="step-num">6</span> Elements: live values in text</h2>
        <p>Type <code>&lt;clock&gt;</code> in a cell and the display keeps it live. Any cell can mix plain text and elements: <code>Round &lt;level&gt; of &lt;roundsTotal&gt;</code>. The editor's element picker lists them all with their current value. The families:</p>
        <table class="help-table">
            <tr><th>Game</th><td><code>eventName gameName level levelOrBreak clock elapsedTime currentTime startTime nextBreak roundsToBreak roundsTotal</code></td></tr>
            <tr><th>Blinds</th><td><code>smallBlind bigBlind ante blinds nextBlinds nextSmallBlind nextBigBlind nextAnte</code></td></tr>
            <tr><th>Players</th><td><code>players playersLeft playersTotal entries buyIns rebuys addOns eliminated cashedOut</code></td></tr>
            <tr><th>Chips</th><td><code>chipCount avgStack avgStackBB startChips addOnChips</code></td></tr>
            <tr><th>Money</th><td><code>pot prizePool bountyPool jackpotPool buyInFee rebuyFee addOnFee buyinLine prizes prizeList prizesStacked</code></td></tr>
            <tr><th>Room</th><td><code>tables seats</code></td></tr>
        </table>
        <div class="hint">Capitalisation never matters, and Tournament Director's names work as aliases (<code>&lt;round&gt;</code>, <code>&lt;timer&gt;</code>, <code>&lt;averagestack&gt;</code>, <code>&lt;totalpot&gt;</code>&hellip;), so a layout written from TD muscle memory just works. A name the timer doesn't know shows as &#10216;name&#10217; on screen instead of vanishing, so typos stay visible.</div>
        <p><strong>Styling one element apart from its line:</strong> select the cell and open <strong>Element styles</strong> in the inspector. It offers the elements present in that cell's text; pick one, and give it its own colour, bold, or a size relative to the line (0.7 means 70% of the surrounding text). The classic use, an ante that only shows on ante rounds and stands out when it does:</p>
        <ul>
            <li>Cell <strong>Text</strong>: <code>&lt;smallBlind&gt; / &lt;bigBlind&gt;</code></li>
            <li>Add a <strong>variant</strong> with condition <code>hasAnte</code> and text <code>&lt;smallBlind&gt; / &lt;bigBlind&gt; / &lt;ante&gt;</code></li>
            <li><strong>Element styles</strong> &rarr; <code>&lt;ante&gt;</code> &rarr; orange, bold, size 0.7</li>
        </ul>
        <p>No ante, plain blinds; ante rounds get the long form with just the ante highlighted. Element styles follow the element through variants, and they scale with the line if a long value makes the whole cell shrink.</p>
        <p>You can also define your own fixed-text elements per layout (sponsor name, house rules line) under the editor's custom elements.</p>
    </div>

    <div class="help-step">
        <h2><span class="step-num">7</span> Conditions: show things only when they apply</h2>
        <p>Screens and cells can carry a <strong>condition</strong>. The simplest is a state: show this screen <em>on break</em>, show this cell <em>when the game is over</em>. Conditional screens are checked top to bottom and the first match wins, which is how a break screen takes over during breaks.</p>
        <p>For anything beyond states, write an <strong>expression</strong>:</p>
        <table class="help-table">
            <tr><td><code>bigBlind &gt; 10000</code></td><td>once the big blind passes 10,000</td></tr>
            <tr><td><code>playersLeft &lt;= 9 and not onBreak</code></td><td>final table, but not during a break</td></tr>
            <tr><td><code>(round &gt;= 6 or entries &gt; 20) and running</code></td><td>grouping with parentheses</td></tr>
            <tr><td><code>minutesLeft &lt; 5</code></td><td>the last five minutes of a level</td></tr>
        </table>
        <p>Comparisons are <code>&lt; &lt;= &gt; &gt;= = !=</code>, joined with <code>and</code>, <code>or</code>, <code>not</code>. The values you can test: <code>round smallBlind bigBlind ante playersLeft playersTotal entries buyIns rebuys addOns eliminated chipCount avgStack prizePool tables seats minutesLeft</code>, and the true/false states <code>running paused onBreak preGame gameOver hasAnte hasRebuys</code>.</p>
        <p>Three more tell you <em>what kind of screen is watching</em>: <code>mobile</code>, <code>tablet</code>, <code>desktop</code>. With QR casting the same layout runs on every scanned device at once, so a cell with <code>when: desktop</code> puts the QR code on the TV only, and <code>not mobile</code> hides a dense stats block on phones. A phone stays a phone when rotated, and a touch-screen laptop counts as a desktop.</p>
        <div class="hint">The editor checks the expression as you type and names anything it doesn't recognise. A condition with a mistake in it never matches, so a typo can't make something show at the wrong moment.</div>
    </div>

    <div class="help-step">
        <h2><span class="step-num">8</span> Variants: one cell, different looks</h2>
        <p>A cell can hold <strong>variants</strong>: alternate text, colour, background, bold or opacity, each behind its own condition. The first matching variant wins; with no match the cell shows its base look. That is how "show A, else B" works: the base is B, a variant with your condition is A. The built-in clock uses the same machinery to turn amber in the last minute and red at the end.</p>
    </div>

    <div class="help-step">
        <h2><span class="step-num">9</span> Artwork: on the screen, or on the box</h2>
        <p>There are two places a picture can live, and choosing right saves a lot of nudging:</p>
        <ul>
            <li><strong>On a box (preferred for plates and panels):</strong> right-click any cell or container &rarr; <strong>Box image</strong>. The picture becomes that box's own background and moves, resizes and reflows <em>with</em> it, so a value can never drift off its plate, on any screen shape. Default fit is Stretch, because plate art is drawn for the box it decorates; Cover and Contain are there too. The PCF built-in is made this way: the felt is the screen, every glossy plate rides its own box.</li>
            <li><strong>On the screen:</strong> right-click the screen &rarr; <strong>Screen background</strong> for the backdrop itself, a colour or a full-screen picture (felt, a poster, league branding).</li>
        </ul>
        <div class="hint"><strong>Don't paint buttons into a full-screen picture.</strong> A screen image with plates drawn into it forces the layout to land cells on pixels it can't see, and they drift the moment the screen shape changes. Give each plate to its box instead. (The QR code needs no plate at all; it brings its own white backing.)</div>
        <p>For a full-screen design that must keep its exact proportions anyway, the old tools remain: <strong>Screen shape</strong> locks the layout to the shape the artwork was drawn at and letterboxes elsewhere; <strong>Fit: Stretch</strong> distorts the picture to cover the layout's area instead of cropping; <strong>Panel colours</strong> switches the whole layout between painting its cell backgrounds and letting artwork show through.</p>
    </div>

    <div class="help-step">
        <h2><span class="step-num">10</span> Sharing layouts</h2>
        <p><strong>Export</strong> downloads a layout as a single <code>.gntimer.json</code> file with every image embedded, so it carries its own artwork. <strong>Import</strong> reads one back in, re-uploads the images here, and saves it as a new layout in your list. That's the way to move a design between installs or share it with another host.</p>
    </div>

    <div class="help-cta" style="text-align:center;padding:2.5rem 1rem;background:#f8fafc;border-radius:8px;margin-top:2rem">
        <p style="color:#475569;margin-bottom:1.25rem">Layouts are safe to experiment with: the editor has undo everywhere, and a game only shows the layout you point it at.</p>
        <a href="/timer_beta_edit.php" class="btn btn-primary" style="text-decoration:none">Open the layout editor</a>
    </div>
</div>

<?php require __DIR__ . '/_footer.php'; ?>
</body>
</html>
