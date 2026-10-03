<?php
require_once __DIR__ . '/auth.php';
// Without this the nav partial sees a GUEST even when someone is signed in
// ($user is only set inside require_login, which a public page never calls) —
// and with landing-page mode on, a guest gets no nav at all.
$current = current_user();
$site_name   = get_setting('site_name', 'Game Night');
$nav_active  = 'help';
// The one list the sidebar, the scrollspy and the prev/next pager all read.
// Ids are linked from elsewhere (the editor's Help button, check-in, the
// landing page), so keep the existing ones stable when adding sections.
$help_sections = [
    ['two-timers',    'Two timers, one switch'],
    ['choose-layout', 'Choose what the display shows'],
    ['running',       'Running the display'],
    ['qr-casting',    'More screens: the QR code'],
    ['editor',        'The editor at a glance'],
    ['building',      'Building a layout'],
    ['cells',         'Cells beyond text'],
    ['elements',      'Elements: live values in text'],
    ['conditions',    'Conditions'],
    ['variants',      'Variants'],
    ['screens',       'Screens & rotation'],
    ['styles',        'Shared styles & custom elements'],
    ['artwork',       'Artwork: screen or box'],
    ['triggers',      'Sounds & triggers'],
    ['sharing',       'Saving & sharing layouts'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Timer Guide &mdash; <?= htmlspecialchars($site_name) ?></title>
    <?php render_seo_meta('Timer Guide', 'How to run the tournament timer display: choose a layout, cast to a second screen with a QR code, build layouts with screens, triggers and video, and use condition expressions.', 'help-timer.php'); ?>
    <link rel="stylesheet" href="/style.css?v=<?= htmlspecialchars(APP_VERSION . '.' . (@filemtime(__DIR__ . '/style.css') ?: 0)) ?>">
    <style>
        html { scroll-behavior: smooth; }
        @media (prefers-reduced-motion: reduce) { html { scroll-behavior: auto; } }
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
        .help-step h3 { font-size: 1.02rem; margin: 1.3rem 0 .4rem; color: #0f172a; }
        .help-step p { margin: .5rem 0; line-height: 1.65; color: #334155; }
        .help-step ul { margin: .5rem 0 .5rem 1.25rem; line-height: 1.65; color: #334155; }
        .help-step li { margin: .3rem 0; }
        .help-step .hint { background: #f1f5f9; border-left: 3px solid #94a3b8;
                           padding: .75rem 1rem; margin: .9rem 0; font-size: .92rem; color: #475569; border-radius: 4px; }
        .help-step code { background: #f1f5f9; border: 1px solid #e2e8f0; border-radius: 4px;
                          padding: .1rem .35rem; font-size: .86em; color: #0f172a;
                          font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
                          overflow-wrap: anywhere; }
        .help-step kbd { font: .85em ui-monospace, SFMono-Regular, Menlo, monospace; color: #0f172a;
                         background: #fff; border: 1px solid #cbd5e1; border-bottom-width: 2px;
                         border-radius: 4px; padding: .05rem .4rem; white-space: nowrap; }
        .help-table { width: 100%; border-collapse: collapse; margin: .75rem 0; font-size: .9rem; }
        /* white-space: normal on purpose: the site sheet keeps data-table cells
           on one line for phones, and inherited by the long <code> lists here
           that made the whole page 1,400px wide on an iPhone. */
        .help-table th, .help-table td { text-align: left; padding: .45rem .6rem; border-bottom: 1px solid #e2e8f0;
                                         vertical-align: top; white-space: normal; }
        .help-table th { color: #475569; font-size: .78rem; text-transform: uppercase; letter-spacing: .03em; }
        figure.help-shot { margin: 1rem 0; }
        figure.help-shot img { max-width: 100%; height: auto; display: block;
                               border: 1px solid #e2e8f0; border-radius: 8px; }
        figure.help-shot figcaption { font-size: .82rem; color: #64748b; margin-top: .4rem; line-height: 1.5; }
        .shot-pair { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
        @media (max-width: 640px) { .shot-pair { grid-template-columns: 1fr; } }
        .shot-narrow img { max-width: 340px; }
        .shot-phone img { max-width: 260px; }

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
        <p class="subtitle">Running the tournament clock on a big screen: picking a layout, casting to more screens, and building a display of your own, from the first cell to sounds, screens and a live video feed.</p>

    <div class="help-step" id="two-timers">
        <h2><span class="step-num">1</span> Two timers, one switch</h2>
        <p>Every game can use the <strong>Tournament Timer</strong>, with designable layouts, break screens and multi-screen casting, or <strong>Tournament Timer Classic</strong>, the original clock. Switch per game in check-in under <strong>Setup &rarr; Timer</strong>: the switch reads <em>Use the Tournament Timer (off: Classic)</em>. The Timer button then opens whichever one is on, and you can switch back any time.</p>
        <p>You can also make the new timer your default. The first time you open a tournament's check-in console you'll be asked once which timer you'd like; whichever you answer is remembered, and it's never asked again. After that, <strong>new games you set up start on the timer you chose</strong>, while each game's own switch still wins and games you've already configured are left alone. Change your mind any time under <strong>Settings &rarr; Tournament timer</strong>.</p>
    </div>

    <div class="help-step" id="choose-layout">
        <h2><span class="step-num">2</span> Choose what the display shows</h2>
        <p>On the same Setup &rarr; Timer pane, pick the <strong>layout</strong> this game's display uses: one of the built-ins, or any layout you have saved. The layout editor sits right there on the pane, and the bar above it asks the only question that matters, <strong>Use this layout for <em>your event</em></strong>, with a yes/no switch. Flip it on and the loaded layout drives the game's display; flip it off and the display goes back to the default. When some other layout is already on the display, the bar names it, so switching on reads as a replacement rather than a first choice.</p>
        <figure class="help-shot">
            <img src="/img/help/timer-bind.png?v=<?= @filemtime(__DIR__ . '/img/help/timer-bind.png') ?: 0 ?>" alt="The bar above the editor: Use this layout for Friday Night Poker, with the switch set to Yes" loading="lazy">
            <figcaption>The binding bar names the game, so it is obvious which display changes. The <strong>Load&hellip;</strong> list marks the layout a game is using with <em>&bull; this event</em>.</figcaption>
        </figure>
        <p>The display follows your choice live, so you can change it mid-game and every connected screen updates without a reload. A game with nothing chosen shows <strong>Default Layout</strong>, the built-in feature tour, which is also what the editor opens on for that game, so what you see while editing is what the TV is showing.</p>
        <p>The <strong>Load&hellip;</strong> list has two groups. <strong>Built-in</strong> holds Default Layout, Classic, Black &amp; Green, Minimalist, Two Column, PCF Poker Chip Forum and Card Room. <strong>Saved</strong> holds your own layouts, plus any the site admin has shared with every host (marked <em>(site)</em>).</p>
        <figure class="help-shot">
            <img src="/img/help/timer-pcf.jpg?v=<?= @filemtime(__DIR__ . '/img/help/timer-pcf.jpg') ?: 0 ?>" alt="The PCF Poker Chip Forum built-in layout: dark felt, glossy plates, clock, blinds, stats panel and chip legend" loading="lazy">
            <figcaption>The <strong>PCF Poker Chip Forum</strong> built-in. Most examples in this guide are drawn from it.</figcaption>
        </figure>
        <p>The <strong>chip set</strong> has its own tab next to Timer: the denominations in play with their colours, drawn on the display as a legend wherever the layout puts one (see <a href="#cells">Cells beyond text</a>). It rides along with a game preset, so a recurring game keeps its chips. Each chip can also carry a photo of the real thing instead of a flat colour.</p>
    </div>

    <div class="help-step" id="running">
        <h2><span class="step-num">3</span> Running the display</h2>
        <p>If you can manage the game, a control tray appears along the bottom of the display. Anyone else sees a clean display with no controls. Every button is checked against your rights on the server, so a display left on a TV can never be driven by a guest who finds it.</p>
        <figure class="help-shot">
            <img src="/img/help/timer-controls.jpg?v=<?= @filemtime(__DIR__ . '/img/help/timer-controls.jpg') ?: 0 ?>" alt="The PCF display for a live game with the control tray showing along the bottom: previous level, start, next level, minus and plus one minute, reset level, undo, fullscreen and Exit" loading="lazy">
            <figcaption>A live game on the PCF layout, paused (the clock turns red), with the host's control tray showing. The speaker and fullscreen buttons sit in the bottom-right corner for every viewer.</figcaption>
        </figure>
        <ul>
            <li><strong>The tray:</strong> previous level, start / stop, next level; <strong>&minus;1m</strong> and <strong>+1m</strong> nudge the clock; <strong>&#8635;</strong> resets the current level; <strong>&#8630;</strong> undoes the last action; fullscreen; and <strong>Exit</strong>, which goes back to the game's check-in console (the only way out when the timer is installed as an app, with no address bar). The tray fades after a few seconds of nothing happening and comes back on any movement or touch.</li>
            <li><strong>Keyboard:</strong> <kbd>Space</kbd> starts and stops, <kbd>&larr;</kbd> and <kbd>&rarr;</kbd> step a level back or forward. Handy with a wireless keyboard at the TV.</li>
            <li><strong>Fullscreen:</strong> the round button in the bottom corner, for every viewer. It fades out when nothing is moving and comes back on any touch.</li>
            <li><strong>Sound:</strong> the speaker button beside it switches trigger sounds and announcements on or off for this device, and remembers the choice. See <a href="#triggers">Sounds &amp; triggers</a> for who hears what.</li>
            <li><strong>iPad / iPhone:</strong> Safari cannot go fullscreen on its own. Tap <strong>Share &rarr; Add to Home Screen</strong>; opening the timer from that icon fills the screen, and the icon remembers this game.</li>
            <li><strong>Staying awake:</strong> a phone or tablet showing the timer is kept awake automatically. If the device needs a tap first, a banner says so; tap anywhere once.</li>
            <li><strong>Stays current:</strong> a display left open on a TV updates itself. When a new version of the site ships, every open timer screen notices within a few seconds and reloads on its own; you never need to walk over and refresh it.</li>
        </ul>
        <div class="hint">Every screen counts down from the same moment, not from the last number it was sent, so a wall display, your tablet and a phone that scanned the QR code all tick over together.</div>
    </div>

    <div class="help-step" id="qr-casting">
        <h2><span class="step-num">4</span> More screens: the QR code</h2>
        <p>Add a <strong>QR code</strong> cell to a layout and any phone, tablet or TV browser that scans it opens the same timer, live and in sync, showing the layout you chose. No account needed. A scanned screen gets no layout picker and no links, just the display, and it starts with sound off.</p>
        <div class="hint"><strong>Scanning only ever grants viewing.</strong> The link in the code shows the display; whether that device also gets controls depends on who is signed in on it. A guest's phone is a spectator screen, your own tablet is a remote control.</div>
        <p>The same layout runs on every screen that scans it, which is where the <code>mobile</code>, <code>tablet</code> and <code>desktop</code> conditions earn their keep: a layout can carry a <strong>Phone</strong> screen that only phones see, with the clock and blinds stacked large and nothing else. The Default Layout ships one, and <strong>+ Screen</strong> in the editor offers it ready-made (see <a href="#screens">Screens &amp; rotation</a>).</p>
        <figure class="help-shot shot-phone">
            <img src="/img/help/timer-phone.png?v=<?= @filemtime(__DIR__ . '/img/help/timer-phone.png') ?: 0 ?>" alt="A phone showing the Default Layout's Phone screen: a large clock, the blinds in gold, the next level, the round and players left" loading="lazy">
            <figcaption>What a phone gets after scanning the Default Layout's QR code: its own Phone screen, the speaker and fullscreen buttons, and the tap-to-stay-awake banner.</figcaption>
        </figure>
    </div>

    <div class="help-step" id="editor">
        <h2><span class="step-num">5</span> The editor at a glance</h2>
        <p>Open <strong>Tournament Timer</strong> from the site menu, or edit right on a game's Setup &rarr; Timer pane. The editor is the real display in a frame, with the tools around it.</p>
        <figure class="help-shot">
            <img src="/img/help/timer-editor.jpg?v=<?= @filemtime(__DIR__ . '/img/help/timer-editor.jpg') ?: 0 ?>" alt="The layout editor: toolbar across the top, the live preview on the left with the preview-state bar and Triggers below it, the Structure panel with screen tabs and the layout tree in the middle, and the Cell inspector on the right" loading="lazy">
            <figcaption>The editor with PCF loaded and the clock cell selected: preview and state bar on the left, Structure in the middle, the inspector on the right.</figcaption>
        </figure>
        <ul>
            <li><strong>The toolbar:</strong> <strong>Load&hellip;</strong> picks a built-in or saved layout, the name box names it, then <strong>Save layout</strong>, <strong>Save layout as copy</strong>, <strong>Export</strong>, <strong>Import</strong>, <strong>Delete</strong>, <strong>Open display</strong> (the display page on sample data, in a new tab) and <strong>Help</strong>, which is this page. Saving and sharing are covered in <a href="#sharing">Saving &amp; sharing layouts</a>.</li>
            <li><strong>The preview:</strong> click any part of it to select that box; the inspector shows its settings. It runs on sample data (round 5, 12 of 18 players, a six-place payout) so every element has something to show.</li>
            <li><strong>Preview state:</strong> the chips under the preview put it in <strong>Running</strong>, <strong>Paused</strong>, <strong>On break</strong> or <strong>Game over</strong>, and the <strong>TV / PC</strong>, <strong>Tablet</strong>, <strong>Phone</strong> switch previews it as that kind of screen, so you can see every conditional screen, variant and device rule without a game in progress.</li>
            <li><strong>Triggers:</strong> the bar under the state chips folds open the sound and announcement panel (<a href="#triggers">Sounds &amp; triggers</a>).</li>
            <li><strong>Structure:</strong> the screen tabs, then the layout as a tree of rows, columns and cells. Click a row to select it; the buttons underneath add a <strong>Cell</strong>, <strong>Row</strong> or <strong>Column</strong>, <strong>Duplicate</strong>, move up or down, or <strong>Remove</strong>. The <strong>&#8630;</strong> in the panel's corner is undo.</li>
            <li><strong>The inspector:</strong> everything about the selected box. With a cell selected it is titled <strong>Cell</strong>; with the screen itself selected it is <strong>Screen background</strong>, which is also where shared styles and custom elements live.</li>
        </ul>
        <figure class="help-shot">
            <img src="/img/help/timer-toolbar.png?v=<?= @filemtime(__DIR__ . '/img/help/timer-toolbar.png') ?: 0 ?>" alt="The editor toolbar: Load, the layout name, Save layout, Save layout as copy, Export, Import, Delete, Open display, Help" loading="lazy">
        </figure>
        <figure class="help-shot">
            <img src="/img/help/timer-statebar.png?v=<?= @filemtime(__DIR__ . '/img/help/timer-statebar.png') ?: 0 ?>" alt="The preview state bar: Running, Paused, On break, Game over chips and the TV / PC, Tablet, Phone switch" loading="lazy">
            <figcaption>The state chips and the device switch under the preview. The preview itself is the display, so what you see here is what the screen will do.</figcaption>
        </figure>
        <p>Everything is reachable two ways, and either is fine:</p>
        <ul>
            <li><strong>Right-click anything</strong>, in the preview or in the structure tree, for its full menu: text, size, colour, font, alignment, padding, duplicate, delete, what kind of cell it is, and on the screen itself, background image, screen shape and panel colours. A tick in the menu is the current value, so it doubles as a readout.</li>
            <li><strong>Drag in the preview:</strong> drag a boundary between boxes to resize them, drag a box to move it into another row or column. One <kbd>Ctrl</kbd>+<kbd>Z</kbd> undoes a whole drag.</li>
        </ul>
        <p><strong>Shortcuts:</strong> <kbd>Ctrl</kbd>+<kbd>Z</kbd> undoes the last change, however it was made, and keeps working after you click in the preview. <kbd>Ctrl</kbd>+<kbd>C</kbd>, <kbd>Ctrl</kbd>+<kbd>X</kbd> and <kbd>Ctrl</kbd>+<kbd>V</kbd> copy, cut and paste the selected box, a whole row or column included, so a stats panel built once can be pasted onto another screen. <kbd>Esc</kbd> closes a menu. (On a Mac, <kbd>&#8984;</kbd> in place of <kbd>Ctrl</kbd>.)</p>
    </div>

    <div class="help-step" id="building">
        <h2><span class="step-num">6</span> Building a layout</h2>
        <p>A layout is rows and columns of cells. A box's share of space is its <strong>weight</strong>; a box with no weight hugs its content. Sizes are a share of the screen (<strong>Size</strong> is a percentage of the screen's height), so a layout built on a laptop fills a projector. Boxes can never overlap or fall off the screen: the engine lays them out like a table, so the worst a mistake can do is look crowded.</p>
        <p>The right-click menu says where a new box lands: <strong>Add cell inside</strong> on a row or column, <strong>Add cell after</strong> on a cell. <strong>Duplicate</strong> copies a box next to itself, settings and all.</p>
        <div class="hint"><strong>Text never escapes its box.</strong> A cell's size is a maximum, not a promise: when a value outgrows the box you sized it in (blinds double every round, and by round 19 that cell holds <code>2,000,000 / 4,000,000</code>), the text wraps or shrinks inside the box, then returns to full size when values shorten. You size cells for the values you can see; the engine handles the ones you can't. <strong>Fit to box</strong> goes the other way: the text grows to fill whatever room the box has, which is how the big clock is made.</div>
        <p><strong>Looks:</strong> every text cell has <strong>Bold</strong>, <strong>Colour</strong>, <strong>Background</strong>, <strong>Border</strong>, <strong>Align</strong>, <strong>Letter spacing</strong> and <strong>Opacity</strong>. Containers have a <strong>Gap</strong> between their children and <strong>Justify</strong> for where the children sit when they don't fill the space.</p>
        <p><strong>Fonts:</strong> every text cell has a <strong>Font</strong> setting in the inspector and the right-click menu, each choice previewed in the dropdown itself. Eight are system looks (Serif, Monospace, Condensed, Wide, Heavy, Impact, Script, Comic) built from faces every device already has; the rest ship with the site: scoreboard and poster faces (Bebas Neue, Oswald, Anton, Orbitron), casino serifs (Cinzel, Playfair), a western saloon (Rye), a neon sign (Monoton), a brush script (Lobster), and <strong>Digital clock</strong>, a real 7-segment face made for <code>&lt;clock&gt;</code> and blinds cells (digits only; it is not for sentences). Bundled fonts load from this site, never a third party, and every choice has a same-shape fallback while it loads. A <strong>shared style</strong> can carry a font too, so one setting gives a whole design its typeface.</p>
        <p><strong>Padding</strong> is a box's inside margin: the gap between its edge and its own content, written CSS-style in the inspector, one value for all sides, two for top/bottom &amp; left/right, four for top&nbsp;right&nbsp;bottom&nbsp;left. Use <code>vh</code> (% of screen height) and <code>vw</code> (% of screen width) so the gap scales with the display. While the Padding field has focus, the preview marks the padding as <strong>green bands</strong> with a dashed line around the space the content actually gets, and the bands follow every keystroke, so you can watch the room appear before you commit. Hovering the field shows the same reference as a tooltip.</p>
        <figure class="help-shot">
            <img src="/img/help/timer-padding.png?v=<?= @filemtime(__DIR__ . '/img/help/timer-padding.png') ?: 0 ?>" alt="The Blinds plate with its padding shown as green bands and a dashed outline around the content area" loading="lazy">
            <figcaption>Focus the Padding field and the preview shows where the padding sits. This plate pads its top so the value stays clear of the painted <em>Blinds</em> tab, the most common reason to pad at all.</figcaption>
        </figure>
        <p><strong>Scrolling:</strong> any text cell has a <strong>Scroll</strong> setting. <strong>Up (credits)</strong> rolls the content like film credits, and only when it is taller than its box: a short list sits still, a long one loops. Give the cell a weight, or it grows to fit its text and never has anything to roll. <strong>Left (ticker)</strong> is a ticker that always moves, for a welcome line or a sponsor message along the bottom. Speed is a pace (slow, normal, fast), and the offset starts the loop part-way: set <em>Halfway</em> on a second copy of a list and it shows the other half. Both stop for anyone who has asked their device for reduced motion.</p>
    </div>

    <div class="help-step" id="cells">
        <h2><span class="step-num">7</span> Cells beyond text</h2>
        <p>A cell holds text until you tell it otherwise. Right-click it (or use the buttons at the top of the inspector) and the <strong>Use a &hellip; instead</strong> items turn it into something else. Each kind has a <strong>Remove &hellip; (back to text)</strong> button, and the box settings (background, box image, padding, opacity, border, size in parent) apply whatever the cell holds.</p>
        <figure class="help-shot shot-narrow">
            <img src="/img/help/timer-cellmenu.png?v=<?= @filemtime(__DIR__ . '/img/help/timer-cellmenu.png') ?: 0 ?>" alt="Part of a cell's right-click menu: Use an image instead, Use a QR code instead, Use a chip legend instead, Use a seat map instead, Use a payout table instead, Use a video stream instead" loading="lazy">
            <figcaption>The six conversions, in the middle of a cell's right-click menu.</figcaption>
        </figure>
        <ul>
            <li><strong>Image:</strong> a picture fills the cell, from your library or a new upload (see <a href="#artwork">Artwork</a> for the library). <strong>Image fit</strong> is Contain (the whole picture, letterboxed if need be) or Cover (fill the box, cropping the edges); <strong>Replace image&hellip;</strong> swaps it. This is for a logo or a sponsor; for plates and panels behind text, use a <em>box image</em> instead.</li>
            <li><strong>QR code:</strong> the join-this-display code from <a href="#qr-casting">More screens</a>. There is nothing to type: the code always points at this display, so a shared layout can never send a scanner anywhere else. It brings its own white backing.</li>
            <li><strong>Chip legend:</strong> the game's chip set (Setup &rarr; Chip set) as coloured discs with their values, in ascending order. The layout says where the legend goes; the game says what is in it, and until a chip set is entered the cell shows nothing. <strong>Chip size</strong> sets the discs apart from the numbers.</li>
            <li><strong>Seat map:</strong> every player still in, at their assigned seat around a table: photo or initials, name and seat number, from the table and seat assignments in check-in. <strong>Table number</strong> pins one table; leave it blank and the busiest table is drawn, which at a final table is the table. Empty seats stay as dim rings so a short-handed table reads as one. The recipe for a screen that appears by itself at the final table is in <a href="#screens">Screens &amp; rotation</a>.</li>
            <li><strong>Payout table:</strong> every paid place as a row, place, dotted leader, reward, from the game's payout structure, so the layout only says where and how big. Pick the <strong>Monospace</strong> font for the classic card-room look, tick <strong>Only places still to be won</strong> to drop places already taken, and set Scroll to Up for a Remaining Places panel that rolls when the field is deep. The Card Room built-in is made of these.</li>
            <li><strong>Video stream:</strong> a live feed in a cell, below.</li>
        </ul>
        <h3>A video stream in a cell</h3>
        <p>Choose <strong>Use a video stream instead</strong> and paste a link into the inspector's <strong>Stream URL</strong> field. Three kinds of link work: a YouTube video or live stream (any form of YouTube link), a Twitch channel, Vimeo or Kick; any other embeddable host the site admin has allowed under Settings &rarr; General; and a <strong>direct video address</strong>, an <code>https</code> link ending in <code>.m3u8</code>, <code>.mp4</code>, <code>.m4v</code> or <code>.webm</code> from any server, which is how a restream (an IPTV channel, a camera rig at the table) gets onto the board, and needs no admin approval. The player fills the cell, so give the box weight for a bigger picture. The field checks the link as you type and says so when a link is not one the display can show; such a link is dropped on save and the cell goes back to text rather than showing a dead player on the night.</p>
        <div class="shot-pair">
            <figure class="help-shot">
                <img src="/img/help/timer-video-preview.jpg?v=<?= @filemtime(__DIR__ . '/img/help/timer-video-preview.jpg') ?: 0 ?>" alt="A layout in the editor preview with a clock, the blinds and a YouTube player filling the right-hand cell" loading="lazy">
                <figcaption>A YouTube feed in the right-hand cell, beside the clock and blinds. In the editor the player ignores clicks, so you can still select and move the cell.</figcaption>
            </figure>
            <figure class="help-shot shot-narrow">
                <img src="/img/help/timer-video.png?v=<?= @filemtime(__DIR__ . '/img/help/timer-video.png') ?: 0 ?>" alt="The video cell's inspector: the Stream URL field and the panel headed While an alarm plays, this stream: drops to, fading down over, staying down for, coming back over" loading="lazy">
                <figcaption>The video cell's inspector: the link, and how the stream ducks under alarms.</figcaption>
            </figure>
        </div>
        <p><strong>Under the alarms:</strong> when a trigger plays a sound, the stream ducks so the chime is heard. The panel in the video cell's inspector sets how, for every alarm in the layout: <strong>drops to</strong> silence, 10%, 20% or half volume (a level rather than silence keeps the feed present, so it doesn't sound like it died); <strong>fading down over</strong> 0.1 to 1.2 seconds; <strong>staying down for</strong> 3 to 12 seconds; <strong>coming back over</strong> 0.3 to 2.5 seconds, deliberately slower than the fade down, because a return as abrupt as the drop draws more attention than the alarm. A direct stream follows all of that exactly. A YouTube or Vimeo player can only be muted and unmuted from outside, so it drops to silence and comes straight back after the hold; Twitch and Kick have no control to speak to and keep playing. For an alarm that must not start over a loud feed, give that trigger's sound a <strong>warm-up</strong> (see <a href="#triggers">Sounds &amp; triggers</a>) and the stream fades down first.</p>
    </div>

    <div class="help-step" id="elements">
        <h2><span class="step-num">8</span> Elements: live values in text</h2>
        <p>Type <code>&lt;clock&gt;</code> in a cell and the display keeps it live. Any cell can mix plain text and elements: <code>Round &lt;round.num&gt; of &lt;round.total&gt;</code>. Names are grouped by subject with a dot, so related ones sort and read together. The <strong>Insert element&hellip;</strong> dropdown under the Text field lists every element with its current value and drops it into the text. The families:</p>
        <table class="help-table">
            <tr><th>Event / game</th><td><code>event.name &middot; game.name &middot; game.next</code></td></tr>
            <tr><th>Round</th><td><code>round.num &middot; round.orBreak &middot; round.total &middot; round.toBreak</code></td></tr>
            <tr><th>Time</th><td><code>clock &middot; time.now &middot; time.elapsed &middot; time.nextBreak &middot; time.start</code></td></tr>
            <tr><th>Blinds</th><td><code>blinds.small &middot; blinds.big &middot; blinds.ante &middot; blinds.now &middot; blinds.next &middot; blinds.nextSmall &middot; blinds.nextBig &middot; blinds.nextAnte</code></td></tr>
            <tr><th>Players</th><td><code>players.line &middot; players.left &middot; players.total &middot; players.entries &middot; players.buyIns &middot; players.rebuys &middot; players.addOns &middot; players.out &middot; players.cashed &middot; players.lastOut &middot; players.lastOutPlace</code></td></tr>
            <tr><th>Chips</th><td><code>chips.total &middot; chips.avg &middot; chips.avgBB &middot; chips.start &middot; chips.addOn</code></td></tr>
            <tr><th>Money</th><td><code>money.pot &middot; money.bounty &middot; money.jackpot &middot; money.buyIn &middot; money.rebuy &middot; money.addOn &middot; money.line</code></td></tr>
            <tr><th>Prizes</th><td><code>prizes.line &middot; prizes.list &middot; prizes.stacked</code> (or a <strong>payout table</strong> cell for real rows)</td></tr>
            <tr><th>Room</th><td><code>table.count &middot; table.seats</code></td></tr>
        </table>
        <div class="hint">Capitalisation never matters. A name the timer doesn't know shows as &#10216;name&#10217; on screen instead of vanishing, so typos stay visible. The built-in layouts and layouts made earlier use the original spellings, <code>&lt;playersLeft&gt;</code>, <code>&lt;bigBlind&gt;</code>, <code>&lt;nextBlinds&gt;</code> and so on; those still work, and the two can be mixed.</div>
        <p><strong>Styling one element apart from its line:</strong> select the cell and open <strong>Element styles</strong> in the inspector. It offers the elements present in that cell's text; pick one, and give it its own colour, bold, or a size relative to the line (0.7 means 70% of the surrounding text). The classic use, an ante that only shows on ante rounds and stands out when it does:</p>
        <ul>
            <li>Cell <strong>Text</strong>: <code>&lt;blinds.small&gt; / &lt;blinds.big&gt;</code></li>
            <li>Add a <strong>variant</strong> with condition <code>hasAnte</code> and text <code>&lt;blinds.small&gt; / &lt;blinds.big&gt; / &lt;blinds.ante&gt;</code></li>
            <li><strong>Element styles</strong> &rarr; <code>&lt;blinds.ante&gt;</code> &rarr; orange, bold, size 0.7</li>
        </ul>
        <div class="shot-pair">
            <figure class="help-shot">
                <img src="/img/help/timer-ante-off.jpg?v=<?= @filemtime(__DIR__ . '/img/help/timer-ante-off.jpg') ?: 0 ?>" alt="Blinds plate showing 100 / 200, no ante" loading="lazy">
                <figcaption>Rounds without an ante: the base text.</figcaption>
            </figure>
            <figure class="help-shot">
                <img src="/img/help/timer-ante-on.jpg?v=<?= @filemtime(__DIR__ . '/img/help/timer-ante-on.jpg') ?: 0 ?>" alt="Blinds plate showing 100 / 200 / 25 with the ante smaller, bold and tinted" loading="lazy">
                <figcaption>An ante round: the variant swaps the text in, and the element style makes the ante its own.</figcaption>
            </figure>
        </div>
        <figure class="help-shot shot-narrow">
            <img src="/img/help/timer-elstyles.png?v=<?= @filemtime(__DIR__ . '/img/help/timer-elstyles.png') ?: 0 ?>" alt="The Element styles panel in the inspector: ante entry with colour, bold and size fields" loading="lazy">
            <figcaption>The <strong>Element styles</strong> panel that produced it: <code>&lt;blinds.ante&gt;</code> with a colour, bold, and size 0.7.</figcaption>
        </figure>
        <p>No ante, plain blinds; ante rounds get the long form with just the ante highlighted. Element styles follow the element through variants, and they scale with the line if a long value makes the whole cell shrink.</p>
        <p>You can also define your own fixed-text elements per layout, a sponsor name or a house-rules line, under <a href="#styles">Custom elements</a>.</p>
    </div>

    <div class="help-step" id="conditions">
        <h2><span class="step-num">9</span> Conditions: show things only when they apply</h2>
        <p>Screens, cells, variants and triggers can all carry a <strong>condition</strong>. The simplest is a state: show this screen <em>on break</em>, show this cell <em>when the game is over</em>. The four pickers under every condition field cover the common cases without typing: a state (Running, Paused, On break, Pre-game, Game over), a round rule (<code>&gt;3</code>, <code>even</code>, <code>all</code>), whether there is an ante, whether there are rebuys. They combine: all of them have to hold.</p>
        <p>For anything beyond that, write an <strong>expression</strong> in the field:</p>
        <table class="help-table">
            <tr><td><code>blinds.big &gt; 10000</code></td><td>once the big blind passes 10,000</td></tr>
            <tr><td><code>players.left &lt;= 9 and not onBreak</code></td><td>final table, but not during a break</td></tr>
            <tr><td><code>(round &gt;= 6 or entries &gt; 20) and running</code></td><td>grouping with parentheses</td></tr>
            <tr><td><code>clock.minutes &lt; 5</code></td><td>the last five minutes of a level</td></tr>
        </table>
        <p>Comparisons are <code>&lt; &lt;= &gt; &gt;= = !=</code>, joined with <code>and</code>, <code>or</code>, <code>not</code>. The values you can test: <code>round &middot; blinds.small &middot; blinds.big &middot; blinds.ante &middot; players.left &middot; players.total &middot; players.entries &middot; players.buyIns &middot; players.rebuys &middot; players.addOns &middot; players.out &middot; chips.total &middot; chips.avg &middot; money.pot &middot; table.count &middot; table.seats &middot; clock.minutes &middot; clock.seconds</code>, and the true/false states <code>running paused onBreak preGame gameOver hasAnte hasRebuys</code>. <strong>Values you can use</strong>, folded under every condition field, lists the same names.</p>
        <p>Three more tell you <em>what kind of screen is watching</em>: <code>mobile</code>, <code>tablet</code>, <code>desktop</code>. With QR casting the same layout runs on every scanned device at once, so a cell with <code>when: desktop</code> puts the QR code on the TV only, and <code>not mobile</code> hides a dense stats block on phones. A phone stays a phone when rotated, and a touch-screen laptop counts as a desktop. The <strong>TV / PC &middot; Tablet &middot; Phone</strong> switch under the preview shows each one.</p>
        <figure class="help-shot shot-narrow">
            <img src="/img/help/timer-expression.png?v=<?= @filemtime(__DIR__ . '/img/help/timer-expression.png') ?: 0 ?>" alt="The Show when field with a valid expression and the list of comparable values" loading="lazy">
            <figcaption>The expression checks itself as you type, and the values you can compare are listed right below.</figcaption>
        </figure>
        <div class="hint">The editor checks the expression as you type and names anything it doesn't recognise. A condition with a mistake in it never matches, so a typo can't make something show at the wrong moment.</div>
    </div>

    <div class="help-step" id="variants">
        <h2><span class="step-num">10</span> Variants: one cell, different looks</h2>
        <p>A cell can hold <strong>variants</strong>: alternate text, colour, background, bold or opacity, each behind its own condition. The first matching variant wins; with no match the cell shows its base look. That is how "show A, else B" works: the base is B, a variant with your condition is A. A variant only changes how a cell looks, never where it sits, so a layout can't jump around as conditions change.</p>
        <figure class="help-shot shot-narrow">
            <img src="/img/help/timer-variants.png?v=<?= @filemtime(__DIR__ . '/img/help/timer-variants.png') ?: 0 ?>" alt="The Variants panel for the PCF clock cell: one variant whose condition is Paused, with text, a red colour, background and bold fields, and an Add variant button" loading="lazy">
            <figcaption>The PCF clock's one variant: when the game is <strong>Paused</strong>, the clock turns red. The amber last minute and the red at zero come from the cell's separate <strong>Clock colours</strong> setting.</figcaption>
        </figure>
        <p>Open <strong>Variants</strong> in the inspector, <strong>+ Add variant</strong>, give it a condition and fill in only what should change; anything left blank is inherited from the base. A cell can carry up to twelve.</p>
    </div>

    <div class="help-step" id="screens">
        <h2><span class="step-num">11</span> Screens: break, final table, phone, rotation</h2>
        <p>A layout can hold several whole <strong>screens</strong>, each with its own condition, and the display shows the first one whose condition holds. They are the tabs at the top of the Structure panel. The screen with no condition is the default, and it should be last, because screens are checked top to bottom and the first match wins. That is how a break screen takes over during breaks: it sits above Main with the condition <em>On break</em>, and the moment the schedule reaches a break every connected screen switches to it, and back again when play resumes.</p>
        <figure class="help-shot shot-narrow">
            <img src="/img/help/timer-screens.png?v=<?= @filemtime(__DIR__ . '/img/help/timer-screens.png') ?: 0 ?>" alt="The screens area of the Structure panel: Main, Break and Final Table tabs, + Screen, the screen name, Delete screen, Show this screen when with the On break state picked, and Rotate after (seconds)" loading="lazy">
            <figcaption>PCF's three screens. The Break tab is selected: its condition is the <strong>On break</strong> state, and it has no rotation time, so it takes over outright.</figcaption>
        </figure>
        <ul>
            <li><strong>+ Screen</strong> offers ready-made screens that inherit the layout's background: <strong>Break</strong> (takes over during breaks), <strong>Final table</strong> (a seat map once ten or fewer remain), <strong>Phone</strong> (a simple stacked view for phones that scan the QR), <strong>Game over</strong> (the wrap-up once a winner stands), or a <strong>Blank</strong> one. A blank screen needs a condition, or the default screen stays in front of it.</li>
            <li>The box under the tabs renames the selected screen; <strong>Delete screen</strong> removes it. A layout can hold up to six.</li>
            <li><strong>Show this screen when</strong> is the screen's condition, with the same pickers and expression field as a cell.</li>
            <li><strong>Rotate after (seconds):</strong> give two or more screens a time and the display cycles through the ones whose conditions match, each for its own time, from 2 seconds to an hour. A screen without a time (Break) still takes over outright while its condition holds, and the rotation resumes when it stops. Use it for a stats page that shows for twenty seconds every couple of minutes.</li>
        </ul>
        <p><strong>The final table, automatically:</strong> add a screen with the condition <code>players.left &lt;= 10 and players.left &gt; 1</code> and put a <strong>seat map</strong> cell on it (right-click a cell &rarr; <em>Use a seat map instead</em>), which is exactly what the ready-made Final table screen does. The seat map draws every remaining player at their assigned seat, with their avatar or initials, name, and seat number, using the table and seat assignments from check-in. The moment the field drops to ten, every screen switches to it by itself; the PCF built-in ships with this screen ready-made.</p>
        <figure class="help-shot">
            <img src="/img/help/timer-finaltable.jpg?v=<?= @filemtime(__DIR__ . '/img/help/timer-finaltable.jpg') ?: 0 ?>" alt="The PCF Final Table screen: nine players around an oval table, each at their seat with an initials disc and name" loading="lazy">
            <figcaption>PCF's built-in Final Table screen. Players with a profile photo get it; everyone else gets their initials on their own colour.</figcaption>
        </figure>
        <div class="hint"><strong>Order matters for phones too.</strong> The Default Layout lists its Phone screen first, so a phone keeps its simple view even during a break, and announces the break with a conditional cell instead. Put the most specific screens at the top and the catch-all at the bottom.</div>
    </div>

    <div class="help-step" id="styles">
        <h2><span class="step-num">12</span> Shared styles &amp; custom elements</h2>
        <p>Both live on the screen's inspector: click the <strong>Screen</strong> row at the top of the tree (or an empty part of the preview) and scroll down past the background settings.</p>
        <h3>Shared styles</h3>
        <p>A <strong>shared style</strong> is a named look, size, bold, font, colour, background and alignment, that any number of cells can use. Change it once and every cell wearing it updates, on every screen; that is how a whole design gets its typeface or its label colour from one place. Add one with a name (letters and digits) and <strong>+ Add</strong>, set its look, then give a cell that look with the <strong>Shared style</strong> dropdown in the cell's inspector or right-click menu. A cell's own settings still win over its shared style, so one cell can be the exception. Text, conditions, variants and images never come from a style; it is purely a look.</p>
        <figure class="help-shot shot-narrow">
            <img src="/img/help/timer-styles.png?v=<?= @filemtime(__DIR__ . '/img/help/timer-styles.png') ?: 0 ?>" alt="The Shared styles panel on the screen inspector: two named styles, plate and label, each with size, bold, font, colour, background and align fields" loading="lazy">
            <figcaption>Two shared styles, <code>plate</code> for the big values and <code>label</code> for the small print. Cells pick one from their <strong>Shared style</strong> dropdown.</figcaption>
        </figure>
        <h3>Custom elements</h3>
        <p>A <strong>custom element</strong> is a name of your own with fixed text behind it: <code>&lt;sponsor&gt;</code> for the bar that put up the trophy, <code>&lt;rules&gt;</code> for the house line along the bottom. Define it once on the screen inspector and use it in any cell's text on any screen, like a built-in element; it appears in the <strong>Insert element&hellip;</strong> list too. Plain text only, up to thirty per layout, and a built-in name always wins if you pick the same one. Both shared styles and custom elements travel with the layout when it is saved or exported.</p>
        <figure class="help-shot shot-narrow">
            <img src="/img/help/timer-customel.png?v=<?= @filemtime(__DIR__ . '/img/help/timer-customel.png') ?: 0 ?>" alt="The Custom elements panel: sponsor and rules entries with their text, and a field to add another" loading="lazy">
            <figcaption>Two custom elements. <code>Tonight by &lt;sponsor&gt;</code> in a cell renders the text behind the name.</figcaption>
        </figure>
    </div>

    <div class="help-step" id="artwork">
        <h2><span class="step-num">13</span> Artwork: on the screen, or on the box</h2>
        <p>There are two places a picture can live, and choosing right saves a lot of nudging:</p>
        <ul>
            <li><strong>On a box (preferred for plates and panels):</strong> right-click any cell or container &rarr; <strong>Box image</strong>. The picture becomes that box's own background and moves, resizes and reflows <em>with</em> it, so a value can never drift off its plate, on any screen shape. Default fit is Stretch, because plate art is drawn for the box it decorates; Cover and Contain are there too. The PCF built-in is made this way: the felt is the screen, every glossy plate rides its own box. Art with a label or a mascot painted into it pairs with <strong>Padding</strong> (<a href="#building">Building a layout</a>): pad that side so the text starts clear of the painted part, and focus the Padding field to see exactly where the clearance sits.</li>
            <li><strong>On the screen:</strong> right-click the screen &rarr; <strong>Screen background</strong> for the backdrop itself: a solid colour, a gradient, or a full-screen picture (felt, a poster, league branding) with its own fit.</li>
            <li><strong>As the content of a cell:</strong> <em>Use an image instead</em> (<a href="#cells">Cells beyond text</a>), for a logo or a sponsor that is a thing on the screen rather than the surface behind a value.</li>
            <li><strong>Picking an image opens your library first:</strong> everything you've already uploaded plus the built-in artwork (the PCF felt and plates are all reusable). Uploading a new file is the button in the corner, and re-using an existing image costs nothing against the daily upload allowance. PNG, JPEG, GIF and WebP up to 8 MB.</li>
        </ul>
        <figure class="help-shot">
            <img src="/img/help/timer-pcf-wide.jpg?v=<?= @filemtime(__DIR__ . '/img/help/timer-pcf-wide.jpg') ?: 0 ?>" alt="The same PCF layout filling an ultrawide screen, every plate stretched with its box" loading="lazy">
            <figcaption>The same PCF layout on an ultrawide screen: no black bars, no drift. Each plate simply rides its box.</figcaption>
        </figure>
        <div class="hint"><strong>Don't paint buttons into a full-screen picture.</strong> A screen image with plates drawn into it forces the layout to land cells on pixels it can't see, and they drift the moment the screen shape changes. Give each plate to its box instead. (The QR code needs no plate at all; it brings its own white backing.)</div>
        <p>For a full-screen design that must keep its exact proportions anyway, the old tools remain: <strong>Screen shape</strong> locks the layout to the shape the artwork was drawn at and letterboxes elsewhere; <strong>Fit: Stretch</strong> distorts the picture to cover the layout's area instead of cropping; <strong>Panel colours</strong> switches the whole layout between painting its cell backgrounds and letting artwork show through.</p>
    </div>

    <div class="help-step" id="triggers">
        <h2><span class="step-num">14</span> Sounds &amp; triggers</h2>
        <p>A <strong>trigger</strong> makes something happen the moment a condition <em>becomes</em> true: play a sound, flash the screen, speak a line, or take over the display with another screen for a few seconds. The <strong>Triggers</strong> bar sits right under the preview, next to the preview-state toggles; click it to fold the panel open. <strong>+ Add trigger</strong> offers ready-made triggers to pick and tweak: <strong>Level change</strong> (a chime), <strong>One-minute warning</strong> (tick and flash), <strong>Announce the blinds</strong> (speaks the new numbers each round), <strong>Break starts</strong> (a horn), <strong>Final table</strong>, <strong>Heads-up</strong>, <strong>Game over</strong>, <strong>Player eliminated</strong> (announces who, by name), or a <strong>Blank trigger</strong>. A layout can carry up to twenty.</p>
        <p>Each trigger is a condition plus a list of actions, added with <strong>+ Action</strong>:</p>
        <ul>
            <li><strong>Play sound:</strong> a dozen built-in tones (buzzer, chime, casino, horn, countdown, double, descending, five3s, tick, pulse, chirp, gentle) that work everywhere, or upload your own (MP3, M4A, WAV, OGG, WebM or AAC, up to 5 MB). Uploaded sounds live in your library and travel inside exports like images do. A sound action also has a <strong>warm-up</strong>, in seconds: how long before the sound a video stream on the layout starts fading down, so the alarm doesn't cut across a loud feed.</li>
            <li><strong>Show screen:</strong> jump to a named screen for 1 to 120 seconds, then return. Good for a "BLINDS UP" splash or a final-table fanfare page.</li>
            <li><strong>Flash:</strong> a short amber pulse around the whole display.</li>
            <li><strong>Announce:</strong> the display speaks the line out loud, with elements filled in: <code>Blinds up: &lt;blinds.now&gt;</code> says the actual numbers.</li>
        </ul>
        <p>The conditions you'll reach for most:</p>
        <ul>
            <li><code>levelChange</code>: true for an instant whenever the round number moves. The classic "new level" chime.</li>
            <li><code>clock.seconds &lt;= 60 and running</code>: the one-minute warning. It naturally re-arms each level.</li>
            <li><code>players.left &lt;= 10 and players.left &gt; 1</code>: final table reached. Pair it with <strong>once</strong> so it fires a single time.</li>
            <li><code>playerEliminated</code>: someone was just knocked out (undoing an elimination stays silent). Pair it with the <code>&lt;players.lastOut&gt;</code> element: announce <code>&lt;players.lastOut&gt; has been eliminated</code> and the display speaks the actual name. <code>&lt;players.lastOutPlace&gt;</code> adds their finishing place.</li>
        </ul>
        <figure class="help-shot">
            <img src="/img/help/timer-triggers.png?v=<?= @filemtime(__DIR__ . '/img/help/timer-triggers.png') ?: 0 ?>" alt="The Triggers panel in the layout editor: a levelChange trigger playing a chime, with Test and Remove buttons" loading="lazy">
            <figcaption>The Triggers panel on the PCF built-in: <code>levelChange</code> plays a chime. The green tick means the condition parses; &#9654; Test runs the actions right now.</figcaption>
        </figure>
        <p>Triggers fire on the <em>change</em>, never on the state: a screen that joins mid-game stays quiet about things that were already true, and a condition must go false and come true again before its trigger fires twice. <strong>Cooldown</strong> sets a minimum quiet time between fires; <strong>once per game</strong> means exactly that. The <strong>&#9654; Test</strong> button on each trigger runs its actions in the preview immediately, the quickest way to audition a sound.</p>
        <div class="hint"><strong>Who hears what:</strong> the main display sounds by default, but a screen someone opened by scanning the QR code is their phone, and it starts muted. Every display gets a speaker button in the corner (next to fullscreen) to switch sounds on or off; the choice sticks per device. The PCF built-in ships with a level-change chime, a one-minute warning and a final-table fanfare, so the fastest way to hear triggers is to load PCF and press &#9654; Test. A video stream in the layout ducks under every alarm; how far and for how long is set on the video cell (<a href="#cells">Cells beyond text</a>).</div>
    </div>

    <div class="help-step" id="sharing">
        <h2><span class="step-num">15</span> Saving &amp; sharing layouts</h2>
        <ul>
            <li><strong>Save layout</strong> saves the layout under the name in the toolbar. A built-in, or a layout someone else shared, can't be changed in place: saving one makes your own copy, which is why the name box reads <em>PCF Poker Chip Forum (mine)</em> the moment you load PCF. Rename it to taste.</li>
            <li><strong>Save layout as copy</strong> keeps the one you loaded as it was and saves your changes as a new layout, for trying a variation without losing the original.</li>
            <li><strong>Delete</strong> removes a layout of yours. A game that was using it goes back to the default display.</li>
            <li><strong>Export</strong> downloads a layout as a single <code>.gntimer.json</code> file with every image <em>and sound</em> embedded, so it carries its own artwork and audio. <strong>Import</strong> reads one back in, re-uploads the media here, and saves it as a new layout in your list. That's the way to move a design between installs or share it with another host. Nothing in an imported file runs; it is read as data and checked like anything you'd have typed yourself.</li>
            <li><strong>Site layout</strong> (site admins only, a button in the toolbar): a layout marked this way appears in every host's <strong>Load&hellip;</strong> list as <em>(site)</em>. Other hosts can load it and save their own copies; only an admin can change the shared one.</li>
        </ul>
        <p>Pick a layout for a game from the Setup &rarr; Timer pane (<a href="#choose-layout">Choose what the display shows</a>), where the binding bar's switch does the pointing. Saving a layout never changes what a game shows unless that game is already using it, in which case the display picks up the new version by itself.</p>
    </div>

    <div class="help-cta" style="text-align:center;padding:2.5rem 1rem;background:#f8fafc;border-radius:8px;margin-top:2rem">
        <p style="color:#475569;margin-bottom:1.25rem">Layouts are safe to experiment with: the editor has undo everywhere, and a game only shows the layout you point it at.</p>
        <a href="/timer_layouts.php" class="btn btn-primary" style="text-decoration:none">Open the layout editor</a>
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
    // highlight. Cheap scan on scroll — fifteen sections, no observer juggling.
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
