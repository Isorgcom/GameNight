/**
 * Timer BETA layout engine — phase A (renderer + built-in layouts).
 *
 * A layout is a JSON tree:
 *   node       := { row: [node…], …props } | { col: [node…], …props } | { cell: {…} }
 *   container  := weight (flex-grow), gap ("0.5vh"), pad, bg, align, border
 *   cell       := text        string; may contain <elements> and newlines
 *                 size        font size as % of viewport height (scales anywhere)
 *                 fit         true = fill the box instead (clock cells)
 *                 color/bg/bold/pad/align/weight/border/opacity/spacing
 *                 clockColors true = colour tracks warn/critical thresholds
 *                 when        visibility gate: a WHEN key or a condition object
 *                             {state,hasAnte,hasRebuys,round}; false = cell hidden
 *                 variants    [{ when, text?, color?, bg?, bold?, opacity? }] —
 *                             conditional emphasis, first match wins over base
 *                             (conditional per-cell styling). Structural props
 *                             (size/align/weight) stay base-only.
 *
 * A condition (in `when` or a variant) is a WHEN key string, or an object whose
 * clauses AND together: state (running|paused|on_break|pre_game|game_over),
 * hasAnte, hasRebuys, round (">3","even","all",…).
 *
 * A screen may also carry `cycle` (seconds): when the first matching screen
 * has one, the display rotates through every matching screen that also has
 * one, each shown for its own duration. Screens without `cycle` keep the
 * first-match-wins rule, so a Break screen ordered first still takes over.
 *
 * A layout may carry `styles` — a name → {visual props} map. A cell opts in
 * with style:"name" and inherits size/fit/color/bg/bold/pad/align/opacity/
 * spacing from it; the cell's own props win over the shared ones. Text,
 * when, variants and images never come from a shared style.
 *
 * The tree renders to nested flexbox, so nothing can overlap or leave the
 * screen — the property the absolutely-positioned theme model could not give.
 * Cell text is authored data: every string lands via textContent, elements become
 * <span data-el>, and nothing is ever innerHTML'd. Keep it that way; the
 * editor phase will feed user input straight through this path.
 *
 * Four built-in layouts ship as starting points: Classic (three-column),
 * Black & Green (banded), Minimalist, and Two Column.
 */
(function () {
'use strict';

/* ── Built-in layouts ─────────────────────────────────────────────────── */

/* What a display shows when nothing has chosen for it: no ?layout=, no layout
 * bound to the game, and nothing remembered on this device. Named once so the
 * three fallback sites cannot drift apart. Changing it only affects displays
 * that never made a choice — a bound game and a device that has picked before
 * both keep what they have. */
var DEFAULT_LAYOUT = 'showcase';

var LAYOUTS = {

    classic: {
        name: 'Classic',
        bg: { color: '#1d4ed8', gradient: ['#1e40af', '#2563eb'] },
        root: { col: [
            { cell: { text: '<eventName>', size: 4.6, bold: true, color: '#1e3a8a', bg: '#f8fafc', pad: '0.6vh 1vw' } },
            { cell: { text: '<buyinLine>', size: 2.0, color: '#dbeafe', pad: '0.4vh 0' } },
            { row: [
                { col: [
                    { cell: { text: 'Round\n<level>',      size: 2.4, color: '#fff' } },
                    { cell: { text: 'Entries\n<entries>',  size: 2.4, color: '#fff' } },
                    { cell: { text: 'Players In\n<players>', size: 2.4, color: '#fff' } },
                    { cell: { text: 'Rebuys\n<rebuys>',    size: 2.4, color: '#fff', when: 'has_rebuys' } },
                    { cell: { text: 'Chip Count\n<chipCount>', size: 2.4, color: '#fff' } },
                    { cell: { text: 'Avg Stack\n<avgStack>',   size: 2.4, color: '#fff' } },
                    { cell: { text: 'Total Pot\n<pot>',    size: 2.4, color: '#fff' } }
                ], weight: 1, gap: '0.3vh', pad: '1vh 0.5vw', justify: 'center' },
                { col: [
                    { cell: { text: '<clock>', fit: true, bold: true, color: '#fff', clockColors: true, variants: [
                        { when: 'game_over', text: 'GAME OVER', color: '#ef4444' },
                        { when: 'on_break',  color: '#fbbf24' }
                    ] }, weight: 5 },
                    { cell: { text: '<gameName>', size: 3.0, color: '#dbeafe' }, weight: 1 },
                    { cell: { text: 'Blinds\n<blinds>', size: 4.2, bold: true, color: '#fff' }, weight: 2 },
                    { cell: { text: 'Ante: <ante>', size: 2.6, color: '#dbeafe', when: 'has_ante' }, weight: 1 }
                ], weight: 3 },
                { col: [
                    { cell: { text: 'Current Time\n<currentTime>', size: 2.4, color: '#fff' } },
                    { cell: { text: 'Elapsed Time\n<elapsedTime>', size: 2.4, color: '#fff' } },
                    { cell: { text: 'Next Break\n<nextBreak>',     size: 2.4, color: '#fff' } }
                ], weight: 1, gap: '0.5vh', pad: '1vh 0.5vw', justify: 'center' }
            ], weight: 1 },
            { cell: { text: 'Next Round: <nextBlinds>', size: 2.4, color: '#bfdbfe', bg: 'rgba(15,23,42,0.35)', pad: '0.8vh 0' } },
            { cell: { text: '<prizes>', size: 2.2, color: '#fff', bg: 'rgba(15,23,42,0.45)', pad: '0.8vh 1vw' } }
        ] }
    },

    black_green: {
        name: 'Black & Green',
        bg: { color: '#000' },
        root: { col: [
            { cell: { text: '<eventName>', size: 4.4, bold: true, color: '#22c55e', pad: '0.8vh 0' } },
            { cell: { text: 'Round: <level>      Next Break: <nextBreak>      Players Remaining: <players>',
                      size: 2.2, bold: true, color: '#000', bg: '#16a34a', pad: '0.7vh 1vw' } },
            { col: [
                { cell: { text: '<clock>', fit: true, bold: true, color: '#fff', clockColors: true }, weight: 4 },
                { cell: { text: '<gameName>', size: 2.8, color: '#e2e8f0' }, weight: 1 },
                { cell: { text: 'Blinds: <blinds>', size: 4.0, bold: true, color: '#22c55e' }, weight: 1.4 },
                { cell: { text: 'Ante: <ante>', size: 2.4, color: '#86efac', when: 'has_ante' }, weight: 0.8 },
                { cell: { text: 'Next Round: <nextGameName>\nNext Blinds: <nextBlinds>', size: 2.2, color: '#94a3b8' }, weight: 1.2 }
            ], weight: 1, pad: '1vh 0' },
            { cell: { text: '# Entries: <entries>   # Chips: <chipCount>   Prize Pool: <pot>   # Rebuys: <rebuys>',
                      size: 2.2, bold: true, color: '#000', bg: '#16a34a', pad: '0.7vh 1vw' } }
        ] }
    },

    minimalist: {
        name: 'Minimalist',
        bg: { color: '#000' },
        root: { col: [
            { cell: { text: '<clock>', fit: true, bold: true, color: '#fff', clockColors: true }, weight: 3 },
            { row: [
                { cell: { text: 'Small Blind\n<smallBlind>', size: 3.4, color: '#cbd5e1' }, weight: 1 },
                { cell: { text: 'Big Blind\n<bigBlind>',     size: 3.4, color: '#cbd5e1' }, weight: 1 }
            ], weight: 1 },
            { cell: { text: 'Ante\n<ante>', size: 3.0, color: '#94a3b8', when: 'has_ante' }, weight: 0.8 }
        ], pad: '2vh 2vw' }
    },

    two_column: {
        name: 'Two Column',
        bg: { color: '#065f46', gradient: ['#064e3b', '#059669'] },
        root: { col: [
            { cell: { text: '<eventName>', size: 4.2, bold: true, color: '#064e3b', bg: '#f0fdf4', pad: '0.6vh 1vw' } },
            { row: [
                { col: [
                    { cell: { text: 'Next Break: <nextBreak>',   size: 2.1, color: '#0f172a', align: 'left' } },
                    { cell: { text: 'Elapsed Time: <elapsedTime>', size: 2.1, color: '#0f172a', align: 'left' } },
                    { cell: { text: 'Current Time: <currentTime>', size: 2.1, color: '#0f172a', align: 'left' } },
                    { cell: { text: 'Round: <level>',            size: 2.1, color: '#0f172a', align: 'left' } },
                    { cell: { text: 'Entries: <entries>',        size: 2.1, color: '#0f172a', align: 'left' } },
                    { cell: { text: 'Players In: <players>',     size: 2.1, color: '#0f172a', align: 'left' } },
                    { cell: { text: 'Chip Count: <chipCount>',   size: 2.1, color: '#0f172a', align: 'left' } },
                    { cell: { text: 'Avg Stack: <avgStack>',     size: 2.1, color: '#0f172a', align: 'left' } },
                    { cell: { text: 'Total Pot: <pot>',          size: 2.1, color: '#0f172a', align: 'left' } },
                    { cell: { text: '<prizeList>', size: 1.9, color: '#334155', align: 'left' }, weight: 1 }
                ], weight: 1, bg: '#f8fafc', pad: '1vh 1vw', gap: '0.2vh' },
                { col: [
                    { cell: { text: '<clock>', fit: true, bold: true, color: '#fff', clockColors: true }, weight: 3 },
                    { cell: { text: '<gameName>', size: 2.6, color: '#d1fae5' }, weight: 0.8 },
                    { cell: { text: 'Blinds\n<blinds>', size: 4.0, bold: true, color: '#fff' }, weight: 1.6 },
                    { cell: { text: 'Next Round: <nextGameName>\nNext Blinds: <nextBlinds>', size: 2.1, color: '#a7f3d0' }, weight: 1 }
                ], weight: 2.2 }
            ], weight: 1 }
        ] }
    }
};

/* ── Live state, normalised for elements ────────────────────────────────── */

var S = {
    eventName: TB_EVENT_TITLE || 'Tournament Timer',
    level: 1, remaining: 900, running: false, isBreak: false, gameOver: false,
    fetchedAt: Date.now(),
    synced: false,          // first poll always takes the server's word
    // Exactly one of these is non-null once the server has answered: an epoch
    // ms deadline while running, a frozen millisecond count while paused.
    anchorEndsAt: null,
    anchorRemainingMs: null,
    levels: [], sb: 0, bb: 0, ante: 0, nsb: null, nbb: null, nante: null, nextIsBreak: false,
    players: '-', entries: '-', rebuys: 0, pot: '-', chipCount: '-', avgStack: '-',
    stillNum: 0, totalNum: 0, chipsNum: 0,
    buyIns: 0, addOns: 0, elimNum: 0, cashedNum: 0,
    lastElim: '', lastElimPlace: 0,   // most recent knockout: name + finishing place
    seatPlayers: [],        // still-in players with seats, for the seat map
    bountyPool: 0, jackpotPool: 0,
    game: null,             // fees, chips per buy-in, table plan, start time
    prizes: [], warnSecs: 60,
    sample: !TB_SESSION_ID
};

if (S.sample) {
    S.eventName = 'Saturday Night Poker';
    S.level = 5; S.remaining = 754; S.running = true;
    S.levels = [
        { level_number: 5, small_blind: 75,  big_blind: 150, ante: 0,  duration_minutes: 20, is_break: 0 },
        { level_number: 6, small_blind: 100, big_blind: 200, ante: 25, duration_minutes: 20, is_break: 0 },
        { level_number: 7, small_blind: 0,   big_blind: 0,   ante: 0,  duration_minutes: 10, is_break: 1 },
        { level_number: 8, small_blind: 150, big_blind: 300, ante: 50, duration_minutes: 20, is_break: 0 }
    ];
    S.players = '12/18'; S.entries = 18; S.rebuys = 3; S.pot = '$1,050';
    S.buyIns = 18; S.addOns = 4; S.elimNum = 6; S.cashedNum = 2;
    S.lastElim = 'Danny R.'; S.lastElimPlace = 13;
    S.bountyPool = 9000; S.jackpotPool = 3600;
    S.game = { buyin: 2500, rebuy: 2500, addon: 1000, start_chips: 5000, addon_chips: 5000,
               tables: 2, seats: 9, start_date: '2026-08-15', start_time: '19:00' };
    // Two of them walk-ins with no account, so the preview shows the initials
    // fallback the way a real game would.
    S.seatPlayers = [
        { name: 'Bryce',   table: 1, seat: 1, avatar: null },
        { name: 'Maria',   table: 1, seat: 2, avatar: null },
        { name: 'Deuce',   table: 1, seat: 3, avatar: null },
        { name: 'Kat',     table: 1, seat: 4, avatar: null },
        { name: 'Hollis',  table: 1, seat: 5, avatar: null },
        { name: 'Ana',     table: 1, seat: 6, avatar: null },
        { name: 'Jimmy',   table: 1, seat: 7, avatar: null },
        { name: 'Pistol',  table: 1, seat: 8, avatar: null },
        { name: 'June',    table: 1, seat: 9, avatar: null }
    ];
    S.chipCount = '67,500'; S.avgStack = '5,625';
    S.stillNum = 12; S.totalNum = 18; S.chipsNum = 67500;
    S.prizes = ['1st: $525', '2nd: $315', '3rd: $210'];
    S.chips = [{ v: 25, c: '#ffffff' }, { v: 100, c: '#ef4444' }, { v: 500, c: '#22c55e' },
               { v: 1000, c: '#2563eb' }, { v: 5000, c: '#0f172a' }];
}

/* The Default Layout: what an unconfigured display shows (DEFAULT_LAYOUT), the
 * feature tour, and the layout to copy when starting a design.
 * Three screens demonstrate the engine's three switching ideas at once — a
 * catch-all Main, a state-conditioned Break that takes over on its own, and a
 * device-conditioned Phone view for whoever scans the QR. Along the way it uses
 * a share QR, per-cell conditions (the ante line only exists in an ante round),
 * a paused-clock variant, and a one-minute warning before the blinds move.
 *
 * The Phone screen is listed FIRST on purpose: screens are scanned in order and
 * the first match wins, so a phone keeps its simple view even during a break,
 * and the break is announced there by a conditional cell instead. Deliberately
 * no chip legend and no seat map: both render nothing until a host has entered
 * a chip set or seated players, and a showcase that looks broken on a fresh
 * install is worse than one that shows less. */
LAYOUTS.showcase = {
    // Display name only. The KEY stays `showcase`: it is what
    // timer_state.layout_builtin stores for a game bound to this layout, so
    // renaming it would orphan every existing binding.
    name: 'Default Layout',
    screens: [
        {
            name: 'Phone',
            when: 'mobile',
            bg: { color: '#0b1220', gradient: ['#0b1220', '#111827'] },
            root: { col: [
                // No weights anywhere, and the column centres itself. A weight
                // is flex-grow: weight the cells and they stretch, each
                // centring its text inside a tall box, which reads as dead
                // bands rather than bigger type. Sized cells plus `justify:
                // center` keep the stack together in the middle of the phone.
                // Sizes are % of viewport height and deliberately large; a
                // cell that outgrows its width is capped down automatically.
                { cell: { text: 'ON BREAK', size: 5, bold: true, color: '#fbbf24', when: 'on_break' } },
                { cell: { text: '<clock>', size: 16, bold: true, color: '#f8fafc', clockColors: true,
                          variants: [{ when: 'paused', color: '#f87171' }] } },
                { cell: { text: '<blinds.now>', size: 8, bold: true, color: '#fbbf24' } },
                { cell: { text: 'Ante <blinds.ante>', size: 4, color: '#94a3b8', when: 'hasAnte' } },
                { cell: { text: 'Next: <blinds.next>', size: 4, color: '#f8fafc' } },
                { cell: { text: 'Round <round.num>  ·  <players.left> of <players.total> left',
                          size: 3, color: '#94a3b8' } }
            ], pad: '3vh 4vw', gap: '2vh', justify: 'center' }
        },
        {
            name: 'Break',
            when: 'on_break',
            bg: { color: '#0b1220', gradient: ['#0b1220', '#111827'] },
            root: { col: [
                { cell: { text: 'ON BREAK', size: 7, bold: true, color: '#fbbf24' }, weight: 2 },
                { cell: { text: '<clock>', fit: true, bold: true, color: '#34d399' }, weight: 4 },
                { cell: { text: 'Back with <blinds.next>', size: 3.2, color: '#f8fafc' }, weight: 1 },
                { row: [
                    { cell: { text: '·', size: 2, opacity: 0 }, weight: 1 },
                    { col: [
                        { cell: { text: '', size: 2, qr: 'display' }, weight: 3 },
                        { cell: { text: 'Scan to watch on your phone', size: 1.8, color: '#94a3b8' }, weight: 0.7 }
                    ], weight: 1.1, gap: '0.3vh' },
                    { cell: { text: '·', size: 2, opacity: 0 }, weight: 1 }
                ], weight: 3.4, gap: '1vw' },
                { cell: { text: '<event.name>', size: 2.2, color: '#94a3b8' }, weight: 0.8 }
            ], pad: '2vh 1.6vw', gap: '1vh' }
        },
        {
            name: 'Main',
            bg: { color: '#0b1220', gradient: ['#0b1220', '#111827'] },
            root: { col: [
                { row: [
                    { col: [
                        { cell: { text: '<event.name>', size: 3, bold: true, color: '#f8fafc', align: 'left' }, weight: 1 },
                        { cell: { text: '<game.name>', size: 2.1, color: '#94a3b8', align: 'left' }, weight: 0.8 },
                        { cell: { text: 'Round <round.num> of <round.total>', size: 2.1, color: '#94a3b8', align: 'left' }, weight: 0.8 }
                    ], weight: 2.6, gap: '0.4vh', justify: 'center' },
                    { cell: { text: '<clock>', fit: true, bold: true, color: '#f8fafc', clockColors: true,
                              variants: [
                                  { when: 'paused',    color: '#f87171' },
                                  { when: 'game_over', text: 'GAME OVER', color: '#ef4444' }
                              ] }, weight: 5 },
                    { col: [
                        { cell: { text: '', size: 2, qr: 'display' }, weight: 3 },
                        { cell: { text: 'Scan to watch', size: 1.7, color: '#94a3b8' }, weight: 0.8 }
                    ], weight: 2, gap: '0.3vh' }
                ], weight: 28, gap: '1vw' },
                // Blinds over next level, not beside it: stacked, the current
                // blinds get the full width of the board and the next level
                // reads as a footnote to them instead of a rival panel.
                { col: [
                    { col: [
                        { cell: { text: 'BLINDS', size: 2.4, bold: true, color: '#fbbf24' }, weight: 0.8 },
                        { cell: { text: '<blinds.now>', size: 13, bold: true, color: '#f8fafc' }, weight: 3.4 },
                        { cell: { text: 'Ante <blinds.ante>', size: 3, color: '#fcd34d', when: 'hasAnte' }, weight: 0.8 }
                    ], weight: 3, bg: 'rgba(251,191,36,0.12)', border: '3px solid rgba(251,191,36,0.65)',
                       pad: '1.2vh 1vw', gap: '0.2vh' },
                    { row: [
                        { col: [
                            { cell: { text: 'NEXT LEVEL', size: 1.9, bold: true, color: '#94a3b8' }, weight: 0.8 },
                            { cell: { text: '<blinds.next>', size: 4, bold: true, color: '#f8fafc' }, weight: 1 }
                        ], weight: 1, gap: '0.2vh' },
                        { col: [
                            { cell: { text: 'NEXT BREAK', size: 1.9, bold: true, color: '#94a3b8' }, weight: 0.8 },
                            { cell: { text: '<time.nextBreak>', size: 4, bold: true, color: '#f8fafc' }, weight: 1 }
                        ], weight: 1, gap: '0.2vh' }
                    ], weight: 1.15, bg: 'rgba(255,255,255,0.05)', border: '2px solid rgba(148,163,184,0.35)',
                       pad: '0.9vh 1vw', gap: '1vw' }
                ], weight: 46, gap: '1vh' },
                { row: [
                    { col: [
                        { cell: { text: 'PLAYERS', size: 1.9, bold: true, color: '#94a3b8' }, weight: 0.8 },
                        { cell: { text: '<players.left> of <players.total>', size: 3.1, bold: true, color: '#f8fafc' }, weight: 1 }
                    ], weight: 1, gap: '0.2vh' },
                    { col: [
                        { cell: { text: 'AVG STACK', size: 1.9, bold: true, color: '#94a3b8' }, weight: 0.8 },
                        { cell: { text: '<chips.avg>  (<chips.avgBB>)', size: 3.1, bold: true, color: '#f8fafc' }, weight: 1 }
                    ], weight: 1, gap: '0.2vh' },
                    { col: [
                        { cell: { text: 'ENTRIES', size: 1.9, bold: true, color: '#94a3b8' }, weight: 0.8 },
                        { cell: { text: '<players.entries>', size: 3.1, bold: true, color: '#f8fafc' }, weight: 1 }
                    ], weight: 1, gap: '0.2vh' },
                    { col: [
                        { cell: { text: 'PRIZE POOL', size: 1.9, bold: true, color: '#94a3b8' }, weight: 0.8 },
                        { cell: { text: '<money.pot>', size: 3.1, bold: true, color: '#34d399' }, weight: 1 }
                    ], weight: 1, gap: '0.2vh' }
                ], weight: 22, gap: '0.8vw', bg: 'rgba(255,255,255,0.04)', pad: '1vh 1vw' },
                { cell: { text: '<prizes.line>', size: 2, color: '#94a3b8' }, weight: 8 }
            ], pad: '2vh 1.6vw', gap: '1.2vh' }
        }
    ],
    triggers: [
        // The ask: one minute of warning before the blinds move.
        { when: 'clock.seconds <= 60 and running', do: [{ sound: 'preset:countdown' }, { flash: 'screen' }] },
        { when: 'levelChange', do: [{ sound: 'preset:chime' }, { announce: 'Blinds are now <blinds.now>' }] },
        { when: 'onBreak',  do: [{ sound: 'preset:gentle' }] },
        { when: 'gameOver', do: [{ sound: 'preset:casino' }], once: true }
    ]
};

/* PCF, cut the per-box way: the felt is the only full-screen image; every
 * plate is its own transparent PNG carried by its box (bgImage), so plates
 * move, resize and reflow WITH their boxes — no aspect lock, no registration,
 * no geometry constants to keep in step. Tabs and the "Chips:" label are baked
 * into their plates' pixels (flex boxes cannot overlap; a plate's own art
 * can). Art ships in www/img/timer_beta/ (why safeImageSrc/pk_lo_img allow
 * that prefix); regenerated by the pcf2 art builder in the session scratchpad.
 * Keeps the paused-red clock variant and a QR on every device. */
LAYOUTS.pcf = {"v": 1,"screens": [{"name": "Break","when": "on_break","bg": {"image": "/img/timer_beta/pcf2-felt.jpg"},"root": {"col": [{"row": [{"col": [{"cell": {"text": "","size": 2,"bgImage": "/img/timer_beta/pcf2-logoplate.png","bgImageFit": "contain"},"weight": 1.5},{"cell": {"text": "","size": 2,"qr": "display","pad": "0.6vh 0"},"weight": 1.35},{"cell": {"text": "·","size": 2,"opacity": 0},"weight": 0.15}],"weight": 3.2,"gap": "0.8vh"},{"cell": {"text": "<clock>","size": 15.5,"color": "#ffffff","bold": true,"bgImage": "/img/timer_beta/pcf2-clock-orange.png","pad": "1.5vh 2vw","variants": [{"when": "paused","text": "<clock>","color": "#ef4444"}]},"weight": 6.6},{"col": [{"cell": {"text": "·","size": 2,"opacity": 0},"weight": 1.15},{"cell": {"text": "<gameName>","size": 2,"color": "#ffffff","bold": true,"bgImage": "/img/timer_beta/pcf2-gameplate.png","pad": "1vh 0.8vw"},"weight": 1.15},{"cell": {"text": "·","size": 2,"opacity": 0},"weight": 0.6}],"weight": 2.4}],"weight": 29,"gap": "0.9vw"},{"row": [{"col": [{"cell": {"text": "Round: <level>","size": 3.1,"color": "#ffffff","bold": true},"weight": 1},{"cell": {"text": "<playersLeft> of <playersTotal> Players","size": 3.1,"color": "#ffffff","bold": true},"weight": 1},{"cell": {"text": "Total Chips: <chipCount>","size": 3.1,"color": "#ffffff","bold": true},"weight": 1},{"cell": {"text": "Avg Stack: <avgStack>  (<avgStackBB>)","size": 3.1,"color": "#ffffff","bold": true},"weight": 1},{"cell": {"text": "Next Break in: <nextBreak>","size": 3.1,"color": "#ffffff","bold": true},"weight": 1}],"weight": 4.7,"bgImage": "/img/timer_beta/pcf2-panel.png","pad": "2.5vh 1vw"},{"col": [{"cell": {"text": "BREAK","size": 9,"color": "#ffffff","bold": true},"weight": 2},{"cell": {"text": "Next up: <nextBlinds>","size": 3.4,"color": "#ffffff","bold": true},"weight": 1}],"weight": 5.3,"bgImage": "/img/timer_beta/pcf2-breakpanel.png","pad": "5vh 1vw 2vh"}],"weight": 50.5,"gap": "0.9vw"}],"pad": "2.2vh 1.6vw","gap": "1.6vh"}},{"name": "Final Table","when": "players.left <= 10 and players.left > 1","bg": {"image": "/img/timer_beta/pcf2-felt.jpg"},"root": {"col": [{"row": [{"col": [{"cell": {"text": "","size": 2,"bgImage": "/img/timer_beta/pcf2-logoplate.png","bgImageFit": "contain"},"weight": 1.5},{"cell": {"text": "","size": 2,"qr": "display","pad": "0.6vh 0"},"weight": 1.35},{"cell": {"text": "·","size": 2,"opacity": 0},"weight": 0.15}],"weight": 3.2,"gap": "0.8vh"},{"cell": {"text": "<clock>","size": 15.5,"color": "#ffffff","bold": true,"bgImage": "/img/timer_beta/pcf2-clock-dark.png","pad": "1.5vh 2vw","variants": [{"when": "paused","text": "<clock>","color": "#ef4444"}]},"weight": 6.6},{"col": [{"cell": {"text": "·","size": 2,"opacity": 0},"weight": 1.15},{"cell": {"text": "<gameName>","size": 2,"color": "#ffffff","bold": true,"bgImage": "/img/timer_beta/pcf2-gameplate.png","pad": "1vh 0.8vw"},"weight": 1.15},{"cell": {"text": "·","size": 2,"opacity": 0},"weight": 0.6}],"weight": 2.4}],"weight": 29,"gap": "0.9vw"},{"row": [{"cell": {"text": "FINAL TABLE","size": 4.2,"color": "#e8862a","bold": true},"weight": 1}],"weight": 8},{"row": [{"cell": {"text": "","size": 2.6,"color": "#ffffff","bold": true,"seats": true},"weight": 1}],"weight": 42.5}],"pad": "2.2vh 1.6vw","gap": "1.2vh"}},{"name": "Main","bg": {"image": "/img/timer_beta/pcf2-felt.jpg"},"root": {"col": [{"row": [{"col": [{"cell": {"text": "","size": 2,"bgImage": "/img/timer_beta/pcf2-logoplate.png","bgImageFit": "contain"},"weight": 1.5},{"cell": {"text": "","size": 2,"qr": "display","pad": "0.6vh 0"},"weight": 1.35},{"cell": {"text": "·","size": 2,"opacity": 0},"weight": 0.15}],"weight": 3.2,"gap": "0.8vh"},{"cell": {"text": "<clock>","size": 15.5,"color": "#ffffff","bold": true,"bgImage": "/img/timer_beta/pcf2-clock-dark.png","pad": "1.5vh 2vw","variants": [{"when": "paused","text": "<clock>","color": "#ef4444"}]},"weight": 6.6},{"col": [{"cell": {"text": "·","size": 2,"opacity": 0},"weight": 1.15},{"cell": {"text": "<gameName>","size": 2,"color": "#ffffff","bold": true,"bgImage": "/img/timer_beta/pcf2-gameplate.png","pad": "1vh 0.8vw"},"weight": 1.15},{"cell": {"text": "·","size": 2,"opacity": 0},"weight": 0.6}],"weight": 2.4}],"weight": 29,"gap": "0.9vw"},{"row": [{"col": [{"cell": {"text": "Round: <level>","size": 3.1,"color": "#ffffff","bold": true},"weight": 1},{"cell": {"text": "<playersLeft> of <playersTotal> Players","size": 3.1,"color": "#ffffff","bold": true},"weight": 1},{"cell": {"text": "Total Chips: <chipCount>","size": 3.1,"color": "#ffffff","bold": true},"weight": 1},{"cell": {"text": "Avg Stack: <avgStack>  (<avgStackBB>)","size": 3.1,"color": "#ffffff","bold": true},"weight": 1},{"cell": {"text": "Next Break in: <nextBreak>","size": 3.1,"color": "#ffffff","bold": true},"weight": 1}],"weight": 4.7,"bgImage": "/img/timer_beta/pcf2-panel.png","pad": "2.5vh 1vw"},{"col": [{"cell": {"text": "<blinds>","size": 7.5,"color": "#ffffff","bold": true,"bgImage": "/img/timer_beta/pcf2-blinds.png","pad": "5vh 2vw 1.5vh"},"weight": 2},{"cell": {"text": "<nextBlinds>","size": 4,"color": "#ffffff","bold": true,"bgImage": "/img/timer_beta/pcf2-nextblinds.png","pad": "4.5vh 1.5vw 1vh"},"weight": 1.45}],"weight": 5.3,"gap": "1.4vh"}],"weight": 42,"gap": "0.9vw"},{"row": [{"cell": {"text": "<prizes.line>","size": 2.8,"color": "#ffffff","bold": true,"bgImage": "/img/timer_beta/pcf2-bar-plain.png","pad": "0 3vw"},"weight": 1}],"weight": 8.5,"pad": "0 6vw"}],"pad": "2.2vh 1.6vw","gap": "1.6vh"}}],"triggers": [{"when": "levelChange","do": [{"sound": "preset:chime"}]},{"when": "clock.seconds <= 60 and running","do": [{"sound": "preset:tick"},{"flash": "screen"}]},{"when": "players.left <= 10 and players.left > 1","do": [{"sound": "preset:casino"},{"flash": "screen"}],"once": true}],"name": "PCF Poker Chip Forum"};

/* The default built-in leads every list of built-ins. Pickers iterate
 * Object.keys(LAYOUTS), which is insertion order, and this one is defined
 * after the compact built-ins because its definition is long — so put it
 * first here rather than hoisting a hundred lines. Re-assigning an existing
 * key does not move it, so the rest keep their order. */
LAYOUTS = Object.assign({ showcase: LAYOUTS.showcase }, LAYOUTS);


/* Kept in step with fmtChips() in timer.js: the literal
 * amount, grouped, never 2K. The fraction-digit branch matters now that blinds
 * can be money — this version had no options, so a .50 blind rendered as "0.5".
 * Number() first because get_state hands these back as strings, and String's
 * own toLocaleString ignores the options object entirely. */
function ordinal(n) {
    var v = n % 100;
    var suf = (v >= 11 && v <= 13) ? 'th' : ({ 1: 'st', 2: 'nd', 3: 'rd' })[n % 10] || 'th';
    return n + suf;
}
function fmtChips(n) {
    if (n === null || n === undefined || n === '') return '-';
    var v = Number(n);
    if (!isFinite(v)) return '-';
    return (v % 1 === 0)
        ? v.toLocaleString('en-US')
        : v.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
function fmtClock(sec) {
    sec = Math.max(0, Math.floor(sec));
    var h = Math.floor(sec / 3600), m = Math.floor((sec % 3600) / 60), s = sec % 60;
    var p = function (x) { return String(x).padStart(2, '0'); };
    return h > 0 ? h + ':' + p(m) + ':' + p(s) : p(m) + ':' + p(s);
}
/* This screen's estimate of the server's clock, in ms to add to Date.now().
 * Cristian's algorithm: the sample with the SMALLEST round trip is the most
 * trustworthy, because the uncertainty in it is bounded by that trip. Averaging
 * is worse — one slow response poisons the estimate for as long as it is in the
 * window. */
var clockSamples = [];      // {rtt, offset}, newest last, capped
var CLOCK_SAMPLE_WINDOW = 8;

function noteClockSample(serverNowMs, requestedAt, receivedAt) {
    if (!serverNowMs) return;
    var rtt = receivedAt - requestedAt;
    // The server's answer was true roughly half a round trip before it landed.
    var offset = (serverNowMs + rtt / 2) - receivedAt;
    clockSamples.push({ rtt: rtt, offset: offset });
    if (clockSamples.length > CLOCK_SAMPLE_WINDOW) clockSamples.shift();
}
/* Median of the three fastest round trips. A single best sample is still
 * hostage to one lucky-but-asymmetric trip (the reply took longer than the
 * request, or vice versa); the median of the three least-uncertain ones throws
 * that out. It matters beyond accuracy: two screens agree on the displayed
 * second only while their offset ESTIMATES agree, so shrinking the spread
 * between screens is what keeps a video wall in step. */
function clockOffset() {
    if (!clockSamples.length) return 0;
    var byRtt = clockSamples.slice().sort(function (a, b) { return a.rtt - b.rtt; });
    var take = byRtt.slice(0, Math.min(3, byRtt.length))
                    .map(function (x) { return x.offset; })
                    .sort(function (a, b) { return a - b; });
    return take[(take.length - 1) >> 1];
}
function serverNow() { return Date.now() + clockOffset(); }

/* Seconds left. Derived from the ANCHOR when the server sent one: ends_at is a
 * constant between commands, so every tick and every screen computes the same
 * value and the display cannot stutter. The old interpolate-from-last-poll path
 * is kept as a fallback for a server that does not send an anchor yet. */
function liveRemaining() {
    if (S.anchorEndsAt !== null) return (S.anchorEndsAt - serverNow()) / 1000;
    if (S.anchorRemainingMs !== null) return S.anchorRemainingMs / 1000;
    return S.running ? S.remaining - (Date.now() - S.fetchedAt) / 1000 : S.remaining;
}
function findLevel(n) {
    for (var i = 0; i < S.levels.length; i++) if ((S.levels[i].level_number | 0) === n) return S.levels[i];
    return null;
}
// Countdown to the start of the next break: what's left of this level plus the
// full length of every level between here and the first is_break row.
function nextBreakSecs() {
    if (S.isBreak) return 0;
    var secs = liveRemaining(), i, found = false;
    for (i = 0; i < S.levels.length; i++) {
        var lv = S.levels[i];
        if ((lv.level_number | 0) <= S.level) continue;
        if (lv.is_break | 0) { found = true; break; }
        secs += (lv.duration_minutes | 0) * 60;
    }
    return found ? secs : null;
}
// Play time so far: completed levels plus the used part of this one. Ignores
// pauses, which is the honest label for a blind-schedule elapsed figure.
function elapsedSecs() {
    var secs = 0;
    for (var i = 0; i < S.levels.length; i++) {
        var lv = S.levels[i];
        if ((lv.level_number | 0) < S.level) secs += (lv.duration_minutes | 0) * 60;
        else if ((lv.level_number | 0) === S.level) secs += (lv.duration_minutes | 0) * 60 - liveRemaining();
    }
    return Math.max(0, secs);
}

/* ── Element registry — add an element, get it everywhere ─────────────── */

var ELEMENTS = {
    eventName:    function () { return S.eventName; },
    level:        function () { return S.isBreak ? 'Break' : String(S.level); },
    clock:        function () { return fmtClock(liveRemaining()); },
    gameName:     function () { return S.isBreak ? 'BREAK' : "No Limit Texas Hold 'Em"; },
    nextGameName: function () { return S.nextIsBreak ? 'Break' : "No Limit Texas Hold 'Em"; },
    smallBlind:   function () { return fmtChips(S.sb); },
    bigBlind:     function () { return fmtChips(S.bb); },
    ante:         function () { return S.ante ? fmtChips(S.ante) : '-'; },
    blinds:       function () { return S.isBreak ? 'On Break' : fmtChips(S.sb) + ' / ' + fmtChips(S.bb); },
    nextBlinds:   function () {
        if (S.nsb === null) return '-';
        if (S.nextIsBreak) return 'Break';
        return fmtChips(S.nsb) + ' / ' + fmtChips(S.nbb) + (S.nante ? '  Ante ' + fmtChips(S.nante) : '');
    },
    players:      function () { return String(S.players); },
    entries:      function () { return String(S.entries); },
    rebuys:       function () { return String(S.rebuys); },
    pot:          function () { return String(S.pot); },
    chipCount:    function () { return String(S.chipCount); },
    avgStack:     function () { return String(S.avgStack); },
    buyinLine:    function () { return S.sample ? '$25.00 Buy-in  ·  $25.00 Rebuys' : ''; },
    currentTime:  function () {
        var d = new Date(), h = d.getHours(), m = String(d.getMinutes()).padStart(2, '0');
        var ap = h >= 12 ? 'pm' : 'am'; h = h % 12 || 12;
        return h + ':' + m + ' ' + ap;
    },
    elapsedTime:  function () { return fmtClock(elapsedSecs()); },
    nextBreak:    function () { var s = nextBreakSecs(); return s === null ? '-' : (S.isBreak ? 'now' : fmtClock(s)); },
    prizes:       function () { return S.prizes.length ? S.prizes.join('    ') : ''; },
    prizeList:    function () { return S.prizes.length ? 'Prizes\n' + S.prizes.join('\n') : ''; },
    // Same places, one per line, WITHOUT a heading — for a cell that already
    // writes its own label above them. prizeList would print a second "Prizes".
    prizesStacked: function () { return S.prizes.join('\n'); },
    playersLeft:  function () { return String(S.stillNum); },
    playersTotal: function () { return String(S.totalNum); },
    // Average stack in big blinds — the poker-meaningful health number a
    // a poker display shows. Falls back to '-' before chips are counted.
    avgStackBB:   function () {
        if (S.chipsNum > 0 && S.stillNum > 0 && S.bb > 0) return Math.round((S.chipsNum / S.stillNum) / S.bb) + ' BB';
        return '-';
    },
    // "Level 5" or "Break" — a single label that follows the break state, so a
    // layout needs one cell instead of a level cell plus a break variant.
    levelOrBreak: function () { return S.isBreak ? 'Break' : 'Level ' + S.level; },

    /* ── The next level's blinds, one part at a time ──────────────────────
     * <nextBlinds> gives the whole line, which is what most layouts want. A
     * design that puts each number in its own box drawn for it needs the parts
     * separately, and had no way to ask. */
    nextSmallBlind: function () { return S.nsb === null ? '-' : (S.nextIsBreak ? 'Break' : fmtChips(S.nsb)); },
    nextBigBlind:   function () { return S.nbb === null ? '-' : (S.nextIsBreak ? 'Break' : fmtChips(S.nbb)); },
    nextAnte:       function () { return S.nante ? fmtChips(S.nante) : '-'; },

    /* ── Counts ───────────────────────────────────────────────────────────
     * entries counts buy-ins INCLUDING rebuys; these are the separate figures
     * a host reads out at the table. */
    buyIns:      function () { return String(S.buyIns); },
    addOns:      function () { return String(S.addOns); },
    eliminated:  function () { return String(S.elimNum); },
    cashedOut:   function () { return String(S.cashedNum); },
    // The most recent knockout — pair with the playerEliminated trigger:
    // announce "Out: <lastEliminated>" speaks the actual name.
    lastEliminated:      function () { return S.lastElim || '-'; },
    lastEliminatedPlace: function () { return S.lastElimPlace > 0 ? ordinal(S.lastElimPlace) : '-'; },

    /* ── Money ────────────────────────────────────────────────────────────
     * Stored in cents everywhere, shown as whole currency here. prizePool is
     * the same figure as <pot>, named the way a screen labels it. */
    prizePool:   function () { return S.pot; },
    bountyPool:  function () { return money(S.bountyPool); },
    jackpotPool: function () { return money(S.jackpotPool); },
    buyInFee:    function () { return S.game ? money(S.game.buyin) : '-'; },
    rebuyFee:    function () { return S.game ? money(S.game.rebuy) : '-'; },
    addOnFee:    function () { return S.game ? money(S.game.addon) : '-'; },

    /* ── Setup ────────────────────────────────────────────────────────────
     * What a player asks before the cards are out: what does a buy-in get me,
     * where am I sitting, when does it start. */
    startChips:  function () { return S.game ? fmtChips(S.game.start_chips) : '-'; },
    addOnChips:  function () { return S.game ? fmtChips(S.game.addon_chips) : '-'; },
    tables:      function () { return S.game ? String(S.game.tables) : '-'; },
    seats:       function () { return S.game ? String(S.game.seats) : '-'; },
    startTime:   function () {
        if (!S.game || !S.game.start_time) return '-';
        var t = String(S.game.start_time).split(':');
        var h = parseInt(t[0], 10); if (isNaN(h)) return '-';
        var ap = h >= 12 ? 'pm' : 'am'; h = h % 12 || 12;
        return h + ':' + (t[1] || '00') + ' ' + ap;
    },

    /* ── Rounds until the break ───────────────────────────────────────────
     * <nextBreak> answers "how long"; this answers "how many more rounds",
     * which is what a table asks when deciding whether to order food. Derived
     * from the schedule already on the display, so it costs no extra state. */
    roundsToBreak: function () {
        if (S.isBreak) return 'now';
        var n = 0;
        for (var i = 0; i < S.levels.length; i++) {
            var L = S.levels[i];
            if ((L.level_number | 0) <= (S.level | 0)) continue;
            if (L.is_break) return String(n);
            n++;
        }
        return '-';
    },
    // How many rounds the schedule holds in total, breaks excluded.
    roundsTotal: function () {
        var n = 0;
        for (var i = 0; i < S.levels.length; i++) if (!S.levels[i].is_break) n++;
        return String(n);
    }
};

/* Cents to a whole-currency string. Every money figure on the wire is cents. */
function money(c) {
    var v = Math.round((Number(c) || 0) / 100);
    return '$' + v.toLocaleString('en-US');
}

/* The namespaced vocabulary. These are the names the editor shows and the
 * docs teach: grouped by subject so they sort together (blinds.small,
 * blinds.big, blinds.next...). The flat registry keys underneath are
 * implementation names. <clock> stays undotted: it is the one everyone
 * types first. */
var ELEMENT_NS = {
    'event.name': 'eventName',
    'game.name': 'gameName', 'game.next': 'nextGameName',
    'round.num': 'level', 'round.orBreak': 'levelOrBreak',
    'round.total': 'roundsTotal', 'round.toBreak': 'roundsToBreak',
    'time.now': 'currentTime', 'time.elapsed': 'elapsedTime',
    'time.nextBreak': 'nextBreak', 'time.start': 'startTime',
    'blinds.small': 'smallBlind', 'blinds.big': 'bigBlind', 'blinds.ante': 'ante',
    'blinds.now': 'blinds', 'blinds.next': 'nextBlinds',
    'blinds.nextSmall': 'nextSmallBlind', 'blinds.nextBig': 'nextBigBlind', 'blinds.nextAnte': 'nextAnte',
    'players.line': 'players', 'players.left': 'playersLeft', 'players.total': 'playersTotal',
    'players.entries': 'entries', 'players.buyIns': 'buyIns', 'players.rebuys': 'rebuys',
    'players.addOns': 'addOns', 'players.out': 'eliminated', 'players.cashed': 'cashedOut',
    'players.lastOut': 'lastEliminated', 'players.lastOutPlace': 'lastEliminatedPlace',
    'chips.total': 'chipCount', 'chips.avg': 'avgStack', 'chips.avgBB': 'avgStackBB',
    'chips.start': 'startChips', 'chips.addOn': 'addOnChips',
    'money.pot': 'prizePool', 'money.bounty': 'bountyPool', 'money.jackpot': 'jackpotPool',
    'money.buyIn': 'buyInFee', 'money.rebuy': 'rebuyFee', 'money.addOn': 'addOnFee',
    'money.line': 'buyinLine',
    'prizes.line': 'prizes', 'prizes.list': 'prizeList', 'prizes.stacked': 'prizesStacked',
    'table.count': 'tables', 'table.seats': 'seats'
};

/* One lowercase index over canonical names, aliases and layout-defined custom
 * elements. Rebuilt whenever the custom set changes. */
var elementIndex = {};
function rebuildElementIndex() {
    elementIndex = {};
    Object.keys(ELEMENTS).forEach(function (n) { elementIndex[n.toLowerCase()] = n; });
    Object.keys(ELEMENT_NS).forEach(function (a) {
        var target = ELEMENT_NS[a];
        if (ELEMENTS[target]) elementIndex[a.toLowerCase()] = target;
    });
}
rebuildElementIndex();

// State predicates. `always` is the implicit default; the rest map a tournament
// state to a boolean; these are the condition clauses.
var WHEN = {
    always:     function () { return true; },
    running:    function () { return S.running && !S.isBreak && !S.gameOver; },
    paused:     function () { return !S.running && !S.gameOver; },
    on_break:   function () { return S.isBreak; },
    pre_game:   function () { return S.level <= 1 && !S.running && !S.gameOver; },
    has_ante:   function () { return (S.ante | 0) > 0; },
    has_rebuys: function () { return (S.rebuys | 0) > 0; },
    game_over:  function () { return S.gameOver; }
};

/* ── Sound + triggers ─────────────────────────────────────────────────────
 * The classic timer's preset alarms, ported as DATA: each preset is a list of
 * tone segments [wave, f0, f1, at, dur, gain, env] played through one Web
 * Audio context. env: 0 = flat, 1 = fade out, 2 = fade in and out. No files,
 * no downloads, no CSP media changes — a preset is ~40 bytes of numbers. */
var TB_PRESETS = {
    buzzer:     [['square', 180, 0, 0, 1.2, 0.3, 1]],
    chime:      [['sine', 523, 0, 0, 0.3, 0.3, 0], ['sine', 659, 0, 0.35, 0.3, 0.3, 0], ['sine', 784, 0, 0.7, 0.3, 0.3, 0]],
    casino:     [1200, 1400, 1200, 1400, 1600].map(function (f, i) { return ['sine', f, 0, i * 0.15, 0.15, 0.25, 1]; }),
    horn:       [['sawtooth', 200, 600, 0, 1.0, 0.25, 1]],
    countdown:  [['sine', 800, 0, 0, 0.15, 0.3, 0], ['sine', 800, 0, 0.6, 0.15, 0.3, 0], ['sine', 800, 0, 1.2, 0.15, 0.3, 0], ['sine', 1200, 0, 1.8, 0.8, 0.35, 1]],
    double:     [['square', 700, 0, 0, 0.2, 0.25, 0], ['square', 700, 0, 0.4, 0.2, 0.25, 0]],
    descending: [0, 1, 2].map(function (i) { return ['sine', 880 - i * 110, 0, i, 0.4, 0.35, 0]; }),
    five3s:     [0, 1, 2, 3, 4].map(function (i) { return ['sine', 880, 0, i * 0.6, 0.35, 0.3, 0]; }),
    tick:       [0, 1, 2, 3, 4, 5, 6, 7].map(function (i) { return ['sine', 2000, 0, i * 0.12, 0.02, 0.15, 0]; }),
    pulse:      [0, 0.15, 0.6, 0.75].map(function (d) { return ['sine', 80, 0, d, 0.15, 0.3, 1]; }),
    chirp:      [0, 1, 2, 3].map(function (i) { return ['sine', 1500, 2500, i * 0.25, 0.1, 0.2, 0]; }),
    gentle:     [['sine', 440, 0, 0, 1.5, 0.2, 2]]
};
var TB_PRESET_NAMES = {
    buzzer: 'Buzzer', chime: 'Chime', casino: 'Casino bells', horn: 'Air horn',
    countdown: '3-2-1-go', double: 'Double beep', descending: 'Descending', five3s: 'Five beeps',
    tick: 'Tick tick', pulse: 'Heartbeat', chirp: 'Chirp', gentle: 'Gentle tone'
};

var tbAudioCtx = null, tbAudioUnlocked = false;
function tbUnlockAudio() {
    if (tbAudioUnlocked) return;
    try {
        if (!tbAudioCtx) tbAudioCtx = new (window.AudioContext || window.webkitAudioContext)();
        if (tbAudioCtx.state === 'suspended') tbAudioCtx.resume();
        var buf = tbAudioCtx.createBuffer(1, 1, 22050);
        var src = tbAudioCtx.createBufferSource();
        src.buffer = buf; src.connect(tbAudioCtx.destination); src.start(0);
        tbAudioUnlocked = true;
        if (typeof hideSoundBanner === 'function') hideSoundBanner();
    } catch (e) {}
}
document.addEventListener('click', tbUnlockAudio, true);
document.addEventListener('touchend', tbUnlockAudio, true);

function safeSoundSrc(v) {
    return (typeof v === 'string' && /^\/uploads\/timer_sounds\/[A-Za-z0-9._-]{1,160}$/.test(v)) ? v : null;
}

/* Sound policy: host/event screens sound by default, a key-scanned screen is
 * someone's PHONE and starts muted. The toggle persists per display. */
var soundOn = (function () {
    try {
        var saved = localStorage.getItem('tb_sound_on');
        if (saved !== null) return saved === '1';
    } catch (e) {}
    return !(typeof TB_KEY !== 'undefined' && TB_KEY);
})();

var soundHook = null;   // QA observation point — set via TBPreview, embed only
// Editor preview only: pretend to be this device class ('mobile'/'tablet'/
// 'desktop', null = honest detection). Set via TBPreview.device().
var deviceOverride = null;
/* ── Stream mute during trigger sounds ────────────────────────────────────
 * Ported from the classic timer (§7.15.1): postMessage mute/unmute to
 * YouTube / Vimeo embeds so a streaming cell doesn't drown out an alarm.
 * Other hosts (Twitch, Kick, Prime) expose no message API — they simply
 * keep playing, same as the classic timer. Honours the same localStorage
 * toggle ('gn.muteStreamDuringAlarms', default ON) so a device's choice on
 * the classic timer carries over here. */
var STREAM_MUTED_BY_ALARM = false;
var STREAM_UNMUTE_TIMER = null;
function tbStreamMute(on) {
    videoFrames.forEach(function (frame) {
        if (!frame.isConnected || !frame.src) return;
        try {
            var host = new URL(frame.src).hostname;
            if (/youtube/.test(host)) {
                frame.contentWindow.postMessage(JSON.stringify({
                    event: 'command', func: on ? 'mute' : 'unMute', args: []
                }), '*');
            } else if (/vimeo/.test(host)) {
                frame.contentWindow.postMessage(JSON.stringify({
                    method: 'setMuted', value: !!on
                }), '*');
            }
        } catch (e) {}
    });
}
function tbMuteStreamForAlarm(durationMs) {
    if (!videoFrames.length) return;
    try {
        if (localStorage.getItem('gn.muteStreamDuringAlarms') === 'false') return;
    } catch (e) {}
    tbStreamMute(true);
    STREAM_MUTED_BY_ALARM = true;
    if (STREAM_UNMUTE_TIMER) clearTimeout(STREAM_UNMUTE_TIMER);
    STREAM_UNMUTE_TIMER = setTimeout(function () {
        if (STREAM_MUTED_BY_ALARM) { tbStreamMute(false); STREAM_MUTED_BY_ALARM = false; }
        STREAM_UNMUTE_TIMER = null;
    }, durationMs);
}

function tbPlaySound(val) {
    if (!soundOn) return;
    if (typeof val !== 'string') return;
    if (soundHook) { soundHook(val); return; }   // heard, not played
    // Duck any streaming cells while the sound plays. Presets run ~1-2s and
    // uploaded sounds are capped short; a flat 5s window (sound + padding)
    // matches the classic timer's per-alarm windows without tracking each
    // sound's real length. Reentrant — an overlapping sound extends the mute.
    tbMuteStreamForAlarm(5000);
    if (val.indexOf('preset:') === 0) {
        var segs = TB_PRESETS[val.slice(7)];
        if (!segs) return;
        try {
            if (!tbAudioCtx) tbAudioCtx = new (window.AudioContext || window.webkitAudioContext)();
            if (tbAudioCtx.state === 'suspended') tbAudioCtx.resume();
            var t = tbAudioCtx.currentTime;
            segs.forEach(function (sg) {
                var o = tbAudioCtx.createOscillator(), g = tbAudioCtx.createGain();
                o.type = sg[0]; o.frequency.setValueAtTime(sg[1], t + sg[3]);
                if (sg[2]) o.frequency.linearRampToValueAtTime(sg[2], t + sg[3] + sg[4]);
                if (sg[6] === 2) {
                    g.gain.setValueAtTime(0, t + sg[3]);
                    g.gain.linearRampToValueAtTime(sg[5], t + sg[3] + 0.1);
                    g.gain.linearRampToValueAtTime(0, t + sg[3] + sg[4]);
                } else if (sg[6] === 1) {
                    g.gain.setValueAtTime(sg[5], t + sg[3]);
                    g.gain.linearRampToValueAtTime(0, t + sg[3] + sg[4]);
                } else {
                    g.gain.value = sg[5];
                }
                o.connect(g); g.connect(tbAudioCtx.destination);
                o.start(t + sg[3]); o.stop(t + sg[3] + sg[4] + 0.02);
            });
        } catch (e) {}
        return;
    }
    var src = safeSoundSrc(val);
    if (!src) return;
    try {
        var a = new Audio(src);
        a.volume = 0.8;
        a.play().catch(function () {});
    } catch (e) {}
}

/* ── Triggers: fire when a condition BECOMES true ─────────────────────────
 * Edge, not state. Each trigger arms only after its first evaluation, so a
 * display that joins late never fires for a condition that has been true for
 * an hour; it fires on false->true, re-arms on true->false, and honours
 * cooldown (min seconds between fires) and once (never again this session). */
var triggerState = [];
var condTickLevel = null, condLevelUp = false;
var condTickElim = null, condPlayerOut = false;
function condTickUpdate() {
    var lv = S.level | 0;
    condLevelUp = (condTickLevel !== null && lv !== condTickLevel);
    condTickLevel = lv;
    // Count going UP is a knockout; an elimination UNDO moves it down and
    // must stay silent.
    var el = S.elimNum | 0;
    condPlayerOut = (condTickElim !== null && el > condTickElim);
    condTickElim = el;
}
function resetTriggers() {
    triggerState = ((CURRENT_LAYOUT && CURRENT_LAYOUT.triggers) || []).map(function () {
        return { armed: false, wasTrue: false, lastFired: 0, doneOnce: false };
    });
}

var takeoverTimer = null;
var flashTimer = null;
function runTriggerActions(list) {
    for (var i = 0; i < (list || []).length; i++) {
        var act = list[i];
        if (!act || typeof act !== 'object') continue;
        if (act.sound) tbPlaySound(act.sound);
        if (act.flash) {
            root.classList.remove('tb-flash');
            void root.offsetWidth;                    // restart the animation
            root.classList.add('tb-flash');
            // Clear after the pulse (reduced-motion never fires animationend,
            // so a timeout, per the house rule). One timer: a re-fire resets
            // it rather than yanking the class mid-pulse.
            if (flashTimer) clearTimeout(flashTimer);
            flashTimer = setTimeout(function () {
                flashTimer = null;
                root.classList.remove('tb-flash');
            }, 1800);
        }
        if (act.announce && soundOn && typeof speechSynthesis !== 'undefined') {
            try {
                // Elements resolve at fire time: "Blinds up: <blinds>" speaks
                // the actual numbers.
                var text = String(act.announce).replace(/<([a-zA-Z][a-zA-Z0-9.]*)>/g, function (m, n) {
                    var v = elementValue(n);
                    return v === null ? '' : v;
                });
                if (soundHook) { soundHook('announce:' + text); }   // QA observes the RESOLVED text
                else {
                    speechSynthesis.cancel();
                    speechSynthesis.speak(new SpeechSynthesisUtterance(text));
                }
            } catch (e) {}
        }
        if (act.takeover && CURRENT_LAYOUT && CURRENT_LAYOUT.screens) {
            for (var si = 0; si < CURRENT_LAYOUT.screens.length; si++) {
                if (CURRENT_LAYOUT.screens[si].name === act.takeover) {
                    // Latest takeover wins; one timeout, no stacking.
                    if (takeoverTimer) clearTimeout(takeoverTimer);
                    forceScreen = si;
                    buildScreen(pickScreen());
                    var secs = Math.max(1, Math.min(120, act.seconds | 0 || 8));
                    takeoverTimer = setTimeout(function () {
                        takeoverTimer = null;
                        forceScreen = null;
                        if (CURRENT_LAYOUT) buildScreen(pickScreen());
                    }, secs * 1000);
                    break;
                }
            }
        }
    }
}

var _evalingTriggers = false;
function evalTriggers() {
    var trigs = (CURRENT_LAYOUT && CURRENT_LAYOUT.triggers) || [];
    if (!trigs.length) return;
    // Re-entrancy: a takeover action rebuilds the screen, and buildScreen's
    // updateAll would land back here before st.wasTrue is written — the same
    // trigger would fire recursively until the stack died.
    if (_evalingTriggers) return;
    _evalingTriggers = true;
    if (triggerState.length !== trigs.length) resetTriggers();
    var now = Date.now();
    for (var i = 0; i < trigs.length; i++) {
        var tg = trigs[i], st = triggerState[i];
        var isTrue;
        try { isTrue = matchCond(tg.when); } catch (e) { isTrue = false; }
        if (!st.armed) {            // first look: record, never fire
            st.armed = true;
            st.wasTrue = !!isTrue;
            continue;
        }
        if (isTrue && !st.wasTrue) {
            var cd = (tg.cooldown | 0) * 1000;
            if (!(tg.once && st.doneOnce) && (!cd || now - st.lastFired >= cd)) {
                st.lastFired = now;
                st.doneOnce = true;
                // A throwing action must not strand _evalingTriggers true —
                // that would kill every trigger for the rest of the session.
                try { runTriggerActions(tg['do']); } catch (e) {}
            }
        }
        st.wasTrue = !!isTrue;
    }
    _evalingTriggers = false;
}

/* ── Condition expressions ────────────────────────────────────────────────
 * A `when` may be a string expression: "bigBlind > 10000", "playersLeft <= 9
 * and not onBreak", "(round >= 6 or entries > 20) and running". Parsed with a
 * real grammar and evaluated against a REGISTRY of live values — never eval,
 * same discipline as everything else user-authored here.
 *
 *   expr    := or
 *   or      := and (("or"|"||") and)*
 *   and     := not (("and"|"&&") not)*
 *   not     := ("not"|"!") not | cmp
 *   cmp     := operand (("<"|"<="|">"|">="|"="|"=="|"!="|"<>") operand)?
 *   operand := number | identifier | "(" expr ")"
 *
 * Identifiers are case-insensitive (the same decision element lookup made:
 * failing over capitalisation is the worst kind of dead, because it looks
 * right). An unknown identifier or a parse error makes the whole condition
 * FALSE, never silently true — the old clause matcher ignored what it did not
 * know, which is how a mistyped clause "worked" by matching everything. */

// The registry IS the foundation: one map, raw numbers only (a formatted
// "10,000" compares as text). Adding a value here + the PHP whitelist is the
// whole cost of a new comparable; grammar, editor hints and docs read this.
var COND_VALUES = {
    round:        function () { return S.level | 0; },
    level:        function () { return S.level | 0; },
    smallblind:   function () { return Number(S.sb) || 0; },
    bigblind:     function () { return Number(S.bb) || 0; },
    ante:         function () { return Number(S.ante) || 0; },
    playersleft:  function () { return S.stillNum | 0; },
    playerstotal: function () { return S.totalNum | 0; },
    entries:      function () { return Number(S.entries) || 0; },
    buyins:       function () { return S.buyIns | 0; },
    rebuys:       function () { return S.rebuys | 0; },
    addons:       function () { return S.addOns | 0; },
    eliminated:   function () { return S.elimNum | 0; },
    chipcount:    function () { return S.chipsNum | 0; },
    avgstack:     function () { return (S.chipsNum > 0 && S.stillNum > 0) ? Math.round(S.chipsNum / S.stillNum) : 0; },
    prizepool:    function () { var m = /^\$?([\d,]+)/.exec(String(S.pot)); return m ? Number(m[1].replace(/,/g, '')) : 0; },
    tables:       function () { return S.game ? (S.game.tables | 0) : 0; },
    seats:        function () { return S.game ? (S.game.seats | 0) : 0; },
    minutesleft:  function () { return Math.floor(liveRemaining() / 60); },
    secondsleft:  function () { return Math.floor(liveRemaining()); },
    // True for exactly one update tick when the level number changes (either
    // direction — a host rewinding a level is still a change worth a chime).
    // Snapshotted once per tick in condTickUpdate(), never computed on read:
    // variants evaluate a condition many times per paint, and a read that
    // mutated its own history would answer differently mid-tick.
    levelchange:  function () { return condLevelUp; },
    levelup:      function () { return condLevelUp; },   // spoken alias
    playereliminated: function () { return condPlayerOut; },
    playerout:        function () { return condPlayerOut; },   // spoken alias
    // What kind of screen is LOOKING at this display. Evaluated live (per
    // tick, like everything here) and per device, so one layout can hide its
    // chip legend on a phone or show the QR cell only on the TV: with casting,
    // every scanner runs the same layout on a different class of screen.
    //
    // "Coarse pointer" is the signal, not touch: a touch LAPTOP has a fine
    // primary pointer and calls itself a PC, which is what its user would say.
    // The phone/tablet split is the smaller viewport side, which rotation
    // cannot change.
    // deviceOverride first: the editor's Desktop/Tablet/Phone preview toggle
    // forces these so a layout's device conditions can be auditioned from a PC.
    mobile:       function () { return deviceOverride ? deviceOverride === 'mobile'  : (isCoarse() && minSide() < 600); },
    tablet:       function () { return deviceOverride ? deviceOverride === 'tablet'  : (isCoarse() && minSide() >= 600); },
    desktop:      function () { return deviceOverride ? deviceOverride === 'desktop' : !isCoarse(); },
    pc:           function () { return deviceOverride ? deviceOverride === 'desktop' : !isCoarse(); },   // spoken alias
    // Booleans, riding the same WHEN predicates the state clauses use.
    running:      function () { return WHEN.running(); },
    paused:       function () { return WHEN.paused(); },
    onbreak:      function () { return WHEN.on_break(); },
    pregame:      function () { return WHEN.pre_game(); },
    gameover:     function () { return WHEN.game_over(); },
    hasante:      function () { return WHEN.has_ante(); },
    hasrebuys:    function () { return WHEN.has_rebuys(); }
};

// The namespaced spellings, registered over the same functions — grouped so
// they sort together, exactly like the element names. States, events and
// device classes read naturally flat and stay that way.
(function () {
    var ns = {
        'blinds.small': 'smallblind', 'blinds.big': 'bigblind', 'blinds.ante': 'ante',
        'players.left': 'playersleft', 'players.total': 'playerstotal',
        'players.entries': 'entries', 'players.buyins': 'buyins', 'players.rebuys': 'rebuys',
        'players.addons': 'addons', 'players.out': 'eliminated',
        'chips.total': 'chipcount', 'chips.avg': 'avgstack',
        'money.pot': 'prizepool',
        'table.count': 'tables', 'table.seats': 'seats',
        'clock.minutes': 'minutesleft', 'clock.seconds': 'secondsleft'
    };
    Object.keys(ns).forEach(function (d) { COND_VALUES[d] = COND_VALUES[ns[d]]; });
})();

function isCoarse() {
    try { if (window.matchMedia) return window.matchMedia('(pointer: coarse)').matches; } catch (e) {}
    return ('ontouchstart' in window) || (navigator.maxTouchPoints | 0) > 0;
}
function minSide() { return Math.min(window.innerWidth, window.innerHeight); }

// Presentation-cased names, for the editor's hint line and the help page.
// `pc` resolves but stays out of the hint: one canonical name to read there.
var COND_NAMES = ['round',
    'blinds.small', 'blinds.big', 'blinds.ante',
    'players.left', 'players.total', 'players.entries', 'players.buyIns',
    'players.rebuys', 'players.addOns', 'players.out',
    'chips.total', 'chips.avg', 'money.pot', 'table.count', 'table.seats',
    'clock.minutes', 'clock.seconds',
    'levelChange', 'playerEliminated',
    'running', 'paused', 'onBreak', 'preGame', 'gameOver', 'hasAnte', 'hasRebuys',
    'mobile', 'tablet', 'desktop'];

function condLex(src) {
    var toks = [], i = 0, s = String(src);
    while (i < s.length) {
        var c = s[i];
        if (c === ' ' || c === '\t' || c === '\n') { i++; continue; }
        if (c === '(' || c === ')') { toks.push({ t: c }); i++; continue; }
        var op = /^(<=|>=|==|!=|<>|&&|\|\||[<>=!])/.exec(s.slice(i));
        if (op) { toks.push({ t: 'op', v: op[1] }); i += op[1].length; continue; }
        var num = /^\d+(\.\d+)?/.exec(s.slice(i));
        if (num) { toks.push({ t: 'num', v: parseFloat(num[0]) }); i += num[0].length; continue; }
        var id = /^[a-zA-Z][a-zA-Z0-9.]*/.exec(s.slice(i));
        if (id) { toks.push({ t: 'id', v: id[0] }); i += id[0].length; continue; }
        throw new Error('Unexpected "' + c + '" at position ' + (i + 1));
    }
    return toks;
}

// Parses to a closure tree; evaluation per tick is just calling it.
function condParse(toks) {
    var pos = 0;
    function peek() { return toks[pos]; }
    function isWord(w) { var t = peek(); return t && t.t === 'id' && t.v.toLowerCase() === w; }
    function isOp(v) { var t = peek(); return t && t.t === 'op' && t.v === v; }

    function operand() {
        var t = peek();
        if (!t) throw new Error('Expression ends where a value was expected');
        if (t.t === '(') {
            pos++;
            var e = orExpr();
            if (!peek() || peek().t !== ')') throw new Error('Missing closing ")"');
            pos++;
            return e;
        }
        if (t.t === 'num') { pos++; var n = t.v; return function () { return n; }; }
        if (t.t === 'id') {
            var key = t.v.toLowerCase();
            if (key === 'true')  { pos++; return function () { return true; }; }
            if (key === 'false') { pos++; return function () { return false; }; }
            var fn = COND_VALUES[key];
            if (!fn) throw new Error('"' + t.v + '" is not a value the timer knows');
            pos++;
            return fn;
        }
        throw new Error('Unexpected "' + (t.v || t.t) + '"');
    }
    function cmp() {
        var left = operand();
        var t = peek();
        if (t && t.t === 'op' && t.v !== '&&' && t.v !== '||' && t.v !== '!') {
            var op = t.v; pos++;
            var right = operand();
            return function () {
                var a = Number(left()), b = Number(right());
                switch (op) {
                    case '<': return a < b;   case '<=': return a <= b;
                    case '>': return a > b;   case '>=': return a >= b;
                    case '!=': case '<>': return a !== b;
                    default: return a === b;                    // = and ==
                }
            };
        }
        return function () { return !!left(); };               // bare boolean
    }
    function notExpr() {
        if (isWord('not') || isOp('!')) { pos++; var e = notExpr(); return function () { return !e(); }; }
        return cmp();
    }
    function andExpr() {
        var left = notExpr();
        while (isWord('and') || isOp('&&')) {
            pos++;
            var right = notExpr();
            left = (function (a, b) { return function () { return a() && b(); }; })(left, right);
        }
        return left;
    }
    function orExpr() {
        var left = andExpr();
        while (isWord('or') || isOp('||')) {
            pos++;
            var right = andExpr();
            left = (function (a, b) { return function () { return a() || b(); }; })(left, right);
        }
        return left;
    }
    var out = orExpr();
    if (pos < toks.length) throw new Error('Unexpected "' + (toks[pos].v || toks[pos].t) + '" after the expression');
    return out;
}

// Cache per source string: conditions re-evaluate every tick, and a layout has
// a handful of distinct expressions at most. A failed compile caches too — the
// string will not get righter, and it must read as FALSE, not as absent.
var condCache = {};
function compileCond(src) {
    if (condCache.hasOwnProperty(src)) return condCache[src];
    var out;
    try { out = { fn: condParse(condLex(src)), error: null }; }
    catch (e) { out = { fn: null, error: e.message || 'Invalid expression' }; }
    condCache[src] = out;
    return out;
}

// Numeric comparison for a round clause: ">3", "<=5", "=1", "even", "odd", "all".
function roundMatches(spec) {
    if (!spec || spec === 'all') return true;
    if (spec === 'even') return (S.level % 2) === 0;
    if (spec === 'odd')  return (S.level % 2) === 1;
    var m = String(spec).match(/^(<=|>=|<|>|=|!=)?\s*(\d+)$/);
    if (!m) return true;
    var op = m[1] || '=', n = parseInt(m[2], 10), v = S.level;
    switch (op) {
        case '<':  return v < n;   case '<=': return v <= n;
        case '>':  return v > n;   case '>=': return v >= n;
        case '!=': return v !== n; default:   return v === n;
    }
}

// A condition is a WHEN key (string shorthand) or an object of clauses that AND
// together: { state, hasAnte, hasRebuys, round }. Empty / missing = always.
function matchCond(cond) {
    if (cond === undefined || cond === null || cond === '' || cond === 'always') return true;
    if (typeof cond === 'string') {
        if (WHEN[cond]) return WHEN[cond]();          // state shorthand, as ever
        // Anything else is an expression. One that does not compile is FALSE:
        // matching everything is how a typo used to look like it worked.
        var c = compileCond(cond);
        if (!c.fn) return false;
        try { return !!c.fn(); } catch (e) { return false; }
    }
    if (cond.state && WHEN[cond.state] && !WHEN[cond.state]()) return false;
    if (cond.hasAnte === true  && (S.ante | 0) <= 0) return false;
    if (cond.hasAnte === false && (S.ante | 0) >  0) return false;
    if (cond.hasRebuys === true  && (S.rebuys | 0) <= 0) return false;
    if (cond.hasRebuys === false && (S.rebuys | 0) >  0) return false;
    if (cond.round && !roundMatches(cond.round)) return false;
    return true;
}

/* ── Renderer: JSON tree → nested flexbox ─────────────────────────────── */

var root = document.getElementById('tbRoot');

/* A layout may lock the shape it was authored at. Without this the background
   art is cropped to fill the screen while the text spreads over the whole of
   it, so on anything but the design's own ratio the two drift apart — on a
   21:9 monitor a 16:10 design loses a third of its height and nothing lands in
   the buttons drawn for it. */
function applyAspect() {
    var a = CURRENT_LAYOUT && +CURRENT_LAYOUT.aspect;
    if (!a || !isFinite(a) || a < 0.4 || a > 4) {
        root.classList.remove('tb-aspect');
        document.body.classList.remove('tb-letterbox');
        root.style.removeProperty('--tb-aspect');
        root.style.removeProperty('--tb-scale');
        return;
    }
    root.classList.add('tb-aspect');
    document.body.classList.add('tb-letterbox');
    root.style.setProperty('--tb-aspect', a);
    var wantW = window.innerHeight * a;
    root.style.setProperty('--tb-scale', wantW > window.innerWidth ? (window.innerWidth / wantW) : 1);
}
window.addEventListener('resize', function () { requestAnimationFrame(applyAspect); setTimeout(applyAspect, 500); });
var allCells = [];   // { el, inner, spec, variants, elSpans, lastText, lastVariant, isFit }
var whenBoxes = [];  // containers (row/col) carrying a `when` — toggled in updateAll
var fitCells = [];   // subset of allCells with fit:true
var qrCells  = [];   // cells rendering a QR code
var chipCells = [];  // cells rendering the chip legend
var seatCells = [];  // cells rendering the final-table seat map
var clockCells = []; // cells whose colour tracks warn/critical
var videoFrames = []; // live streaming <iframe>s — the alarm mute walks these

function applyBox(el, node) {
    if (node.weight !== undefined) { el.style.flexGrow = String(node.weight); el.style.flexBasis = '0'; }
    if (node.gap) el.style.gap = node.gap;
    if (node.pad) el.style.padding = node.pad;
    if (node.bg) el.style.background = node.bg;
    applyBoxImage(el, node);
    if (node.border) el.style.border = node.border;
    if (node.justify) el.style.justifyContent = node.justify;
}

/* A box's OWN background image — a plate that moves and resizes WITH the box,
 * so a value can never misalign with the artwork behind it. One full-screen
 * picture with plates painted in makes the layout land cells on pixels it
 * cannot see; per-box art removes the registration problem instead of tuning
 * it. Default fit is STRETCH: plate art is drawn for the box it decorates, and
 * covering exactly the box's area is the point. */
function applyBoxImage(el, node) {
    var src = safeImageSrc(node.bgImage);
    if (!src) return;
    el.style.backgroundImage = 'url("' + src + '")';
    el.style.backgroundSize = node.bgImageFit === 'cover' ? 'cover'
                            : node.bgImageFit === 'contain' ? 'contain' : '100% 100%';
    el.style.backgroundPosition = 'center';
    el.style.backgroundRepeat = 'no-repeat';
}

// Build the element/text spans for one line of cell content into `inner`,
// appending any {el,name} element spans it creates to `elList`.
// Layout-defined custom elements: a flat name→plain-text map applied to every
// screen. Resolved after the built-ins, so a built-in name always wins.
var customEls = {};
// A layout-defined custom element wins nothing over a built-in (the built-in is
// checked first), but it IS matched exactly rather than case-insensitively: the
// author chose that name and typed it into their own layout.
function resolveElement(name) {
    var canon = elementIndex[String(name).toLowerCase()];
    if (canon) return canon;
    return customEls.hasOwnProperty(name) ? name : null;
}
function isKnownElement(name) { return resolveElement(name) !== null; }
function elementValue(name) {
    var canon = resolveElement(name);
    if (canon === null) return null;
    if (ELEMENTS[canon]) return String(ELEMENTS[canon]());
    if (customEls.hasOwnProperty(canon)) return String(customEls[canon]);
    return null;
}

/* Per-element styling within one cell: every element is already its own span,
 * so "<ante> bold and orange inside the blinds line" is a style applied at
 * span-build time from the cell's elStyles map. Keys match the element the
 * author MEANT: case-insensitive through the same index as element
 * lookup itself — a style on "Ante" must hit <ante>, and one on "round" must
 * hit <level>. `scale` is an em multiplier, deliberately relative: capCell()
 * shrinks an overflowing cell by font-size, and a styled element must shrink
 * WITH its line, not hold still while the rest compresses. */
function applyElStyle(span, name, elStyles) {
    if (!elStyles) return;
    var want = (resolveElement(name) || name).toLowerCase();
    for (var k in elStyles) {
        if (!elStyles.hasOwnProperty(k)) continue;
        if ((resolveElement(k) || k).toLowerCase() !== want) continue;
        var st = elStyles[k];
        if (st && typeof st === 'object') {
            if (st.color) span.style.color = st.color;
            if (st.bold === true) span.style.fontWeight = '700';
            if (st.bold === false) span.style.fontWeight = '400';
            if (typeof st.scale === 'number') span.style.fontSize = st.scale + 'em';
        }
        return;
    }
}

function buildInner(inner, text, elList, elStyles) {
    var lines = String(text || '').split('\n');
    for (var li = 0; li < lines.length; li++) {
        if (li > 0) inner.appendChild(document.createElement('br'));
        var parts = lines[li].split(/(<[a-zA-Z][a-zA-Z0-9.]*>)/);
        for (var pi = 0; pi < parts.length; pi++) {
            var p = parts[pi];
            if (!p) continue;
            var m = p.match(/^<([a-zA-Z][a-zA-Z0-9.]*)>$/);
            if (m && isKnownElement(m[1])) {
                var span = document.createElement('span');
                span.setAttribute('data-el', m[1]);
                applyElStyle(span, m[1], elStyles);
                inner.appendChild(span);
                elList.push({ el: span, name: m[1] });
            } else {
                // Unknown elements render visibly (⟨name⟩) rather than vanishing —
                // the editor relies on this to flag typos.
                inner.appendChild(document.createTextNode(m ? '⟨' + m[1] + '⟩' : p));
            }
        }
    }
}

// Only these props may differ between a cell's base and its conditional
// variants; structural props (size/align/weight) stay base-only so a variant
// can never reflow the layout, only re-emphasise it.
var VARIANT_PROPS = ['text', 'color', 'bg', 'bold', 'opacity'];

// Named fonts: key → CSS stack. A layout carries the KEY, never a raw
// font-family string — the same rule as image paths and QR targets: a layout
// is a shareable document, so it names looks this engine defines rather than
// carrying CSS of its own (the server sanitizer enforces the same list).
// Two tiers, one contract. The first eight are system faces every device
// already has; the rest are BUNDLED woff2 files (www/fonts/ via fonts.css,
// same-origin so the closed CSP covers them). Either way each stack ends in
// faces of the same SHAPE, so text painted before a webfont arrives — or on
// a device that never gets it — still reads as the intended kind of letter.
var FONTS = {
    serif:     "Georgia, 'Times New Roman', serif",
    mono:      "Consolas, Menlo, 'Courier New', monospace",
    condensed: "'Arial Narrow', 'Helvetica Condensed', sans-serif-condensed, sans-serif",
    wide:      "Verdana, Geneva, Tahoma, sans-serif",
    heavy:     "'Arial Black', 'Segoe UI Black', sans-serif",
    impact:    "Impact, Haettenschweiler, 'Arial Black', sans-serif",
    script:    "'Segoe Script', 'Brush Script MT', cursive",
    comic:     "'Comic Sans MS', 'Chalkboard SE', 'Comic Neue', cursive",
    // Bundled (fonts.css) — display faces picked for a tournament wall:
    // condensed scoreboard caps, a heavy poster face, a 7-segment clock,
    // luxury casino serifs, a saloon western, a neon sign, a brush script.
    bebas:     "'Bebas Neue', 'Arial Narrow', sans-serif",
    oswald:    "Oswald, 'Arial Narrow', sans-serif",
    anton:     "Anton, 'Arial Black', sans-serif",
    orbitron:  "Orbitron, 'Segoe UI', sans-serif",
    digital:   "'DSEG7', Consolas, monospace",
    cinzel:    "Cinzel, Georgia, serif",
    playfair:  "'Playfair Display', Georgia, serif",
    rye:       "Rye, Georgia, serif",
    monoton:   "Monoton, 'Arial Black', sans-serif",
    lobster:   "Lobster, 'Segoe Script', cursive"
};

// Apply the emphasis props of `spec` to the element, clearing any the spec
// omits so a variant switching off leaves no residue.
function applyEmphasis(el, spec) {
    el.style.color = spec.color || '';
    // backgroundColor, never the `background` shorthand: the shorthand resets
    // background-image, silently erasing a box's own plate art on the first
    // update pass — the same trap the chip discs hit.
    el.style.backgroundColor = spec.bg || '';
    el.style.fontWeight = spec.bold ? '700' : '';
    el.style.opacity = spec.opacity !== undefined ? String(spec.opacity) : '';
}

// Only same-origin timer-layout upload paths are allowed as image sources;
// anything else (external URL, another feature's upload, javascript:, …) is
// dropped. The server sanitizer enforces the same rule.
function safeImageSrc(v) {
    if (typeof v !== 'string') return null;
    // Uploads, or the repo's own timer artwork (built-in layouts ship theirs
    // as static files). Both same-origin, both closed lists of writers.
    return (/^\/uploads\/timer_layouts\/[A-Za-z0-9._-]{1,120}$/.test(v)
         || /^\/img\/timer_beta\/[a-z0-9._-]{1,80}$/.test(v)) ? v : null;
}

// Normalize a user-pasted streaming URL into a safe embed URL — the classic
// timer's normalizeStreamUrl, ported verbatim so the two timers accept the
// same links. Returns '' for anything unrecognised so the iframe stays blank
// (dashed placeholder) rather than loading an arbitrary cross-origin page.
// The server sanitizer (pk_lo_stream_url) enforces the same host list on
// save; the global CSP frame-src is the third, browser-enforced copy. Twitch
// needs a parent= matching the embedding hostname — sourced from
// location.hostname so it works in dev (localhost) and prod without settings.
function normalizeStreamUrl(raw) {
    if (!raw) return '';
    raw = String(raw).trim();
    var u;
    try { u = new URL(raw); } catch (e) { return ''; }
    if (u.protocol !== 'https:' && u.protocol !== 'http:') return '';
    var h = u.hostname.replace(/^www\./, '').toLowerCase();
    // YouTube — full watch URL, short youtu.be, embed/, live/, shorts/.
    // `?enablejsapi=1` lets this page postMessage mute/unmute commands —
    // used by the alarm-mute feature.
    var YT = 'https://www.youtube-nocookie.com/embed/';
    var YT_PARAMS = '?enablejsapi=1';
    if (h === 'youtube.com' || h === 'm.youtube.com' || h === 'music.youtube.com') {
        var v = u.searchParams.get('v');
        if (v) return YT + encodeURIComponent(v) + YT_PARAMS;
        var m = u.pathname.match(/^\/(?:embed|live|shorts)\/([\w-]{6,})/);
        if (m) return YT + m[1] + YT_PARAMS;
    }
    if (h === 'youtu.be') {
        var id = u.pathname.replace(/^\//, '').split('/')[0];
        if (id) return YT + encodeURIComponent(id) + YT_PARAMS;
    }
    // YouTube TV — try the /watch/<id> as a regular embed; live/subscription
    // content won't play inside the iframe, but shared VOD will.
    if (h === 'tv.youtube.com') {
        var mtv = u.pathname.match(/^\/watch\/([\w-]{6,})/);
        if (mtv) return YT + mtv[1] + YT_PARAMS;
    }
    if (h === 'youtube-nocookie.com') {
        if (raw.indexOf('enablejsapi=') === -1) {
            return raw + (raw.indexOf('?') === -1 ? '?' : '&') + 'enablejsapi=1';
        }
        return raw;
    }
    // Twitch — first path segment is the channel name.
    if (h === 'twitch.tv') {
        var ch = u.pathname.replace(/^\//, '').split('/')[0];
        if (ch) return 'https://player.twitch.tv/?channel=' + encodeURIComponent(ch)
            + '&parent=' + encodeURIComponent(location.hostname || 'localhost');
    }
    if (h === 'player.twitch.tv') {
        u.searchParams.set('parent', location.hostname || 'localhost');
        return u.toString();
    }
    // Vimeo — extract the numeric video ID and use player.vimeo.com.
    if (h === 'vimeo.com') {
        var vparts = u.pathname.split('/').filter(Boolean);
        for (var vi = vparts.length - 1; vi >= 0; vi--) {
            if (/^\d{5,}$/.test(vparts[vi])) {
                return 'https://player.vimeo.com/video/' + vparts[vi];
            }
        }
    }
    if (h === 'player.vimeo.com') return raw;
    // Kick — channel URL; embed lives at player.kick.com/<channel>.
    if (h === 'kick.com') {
        var kch = u.pathname.replace(/^\//, '').split('/')[0];
        if (kch) return 'https://player.kick.com/' + encodeURIComponent(kch);
    }
    if (h === 'player.kick.com') return raw;
    // Prime Video — best-effort pass-through; Amazon usually refuses framing.
    if (h === 'primevideo.com' || h === 'amazon.com' || h.endsWith('.amazon.com')) {
        return raw;
    }
    // Admin-allowlisted custom hosts (Settings → General). Forced to https —
    // an http embed would be mixed-content blocked. Must stay in sync with
    // the CSP frame-src built in auth.php.
    var rawHost = u.hostname.toLowerCase();
    var extra = window.TB_STREAM_HOSTS || [];
    for (var ei = 0; ei < extra.length; ei++) {
        var pat = String(extra[ei]).toLowerCase();
        var hit = (pat.indexOf('*.') === 0) ? rawHost.endsWith(pat.slice(1)) : (rawHost === pat);
        if (hit) { u.protocol = 'https:'; return u.toString(); }
    }
    // Unknown host — render nothing (safer than allowing arbitrary embeds).
    return '';
}

function buildCell(spec) {
    var el = document.createElement('div');
    el.className = 'tb-cell';
    var inner = document.createElement('div');
    inner.className = 'tb-cell-inner';
    el.appendChild(inner);

    // Image cell: an <img> filling the box. src is validated; never innerHTML.
    var imgSrc = safeImageSrc(spec.image);
    if (imgSrc) {
        el.classList.add('tb-cell-img');
        var img = document.createElement('img');
        img.src = imgSrc;
        img.alt = '';
        img.className = 'tb-img' + (spec.imageFit === 'cover' ? ' tb-img-cover' : '');
        inner.appendChild(img);
    }

    // Chip legend: the denominations in play, each as a coloured disc with its
    // value — the thing everyone asks at colour-up. A cell type rather than a
    // text element because it is DOM (coloured discs), not a string; the values
    // themselves still land via textContent.
    if (spec.chips) {
        el.classList.add('tb-cell-chips');
        chipCells.push({ el: el, inner: inner, drawn: null });
    }

    // Final-table seat map: the layout says WHERE the table sits, the game
    // says who is in which seat. A cell type for the same reason chips is —
    // it draws DOM (a felt, seats, avatars), not a string.
    if (spec.seats) {
        el.classList.add('tb-cell-seats');
        seatCells.push({ el: el, inner: inner, spec: spec, drawn: null });
    }

    // QR cell: a code another screen scans to join this display.
    //
    // The URL is built HERE from the session's own remote_key — never from
    // anything the layout author typed. A layout is a shareable document, so an
    // author-supplied QR payload would be a phishing primitive aimed at a wall
    // of screens: what people scan has to be decided by the app, not the file.
    // `qr` therefore names a target from a whitelist; it carries no content.
    if (spec.qr) {
        el.classList.add('tb-cell-qr');
        var qimg = document.createElement('img');
        qimg.className = 'tb-qr';
        qimg.alt = 'QR code to open this timer on another screen';
        inner.appendChild(qimg);
        qrCells.push({ el: el, img: qimg, target: spec.qr, drawn: null });
    }

    // Streaming video cell: an <iframe> filling the box — the classic timer's
    // stream panel as a layout cell. The layout stores the RAW pasted URL;
    // the iframe only ever loads what normalizeStreamUrl() built from it, so
    // an unrecognised host draws the dashed placeholder instead of an embed
    // (CSP frame-src would refuse it anyway — belt and braces). The src is an
    // attribute assignment, never innerHTML.
    if ('video' in spec) {
        el.classList.add('tb-cell-video');
        var vSrc = normalizeStreamUrl(spec.video);
        if (vSrc) {
            var ifr = document.createElement('iframe');
            ifr.className = 'tb-video';
            ifr.src = vSrc;
            ifr.setAttribute('frameborder', '0');
            ifr.setAttribute('allow', 'autoplay; encrypted-media; picture-in-picture; fullscreen');
            ifr.setAttribute('allowfullscreen', '');
            // In the editor preview the iframe must not swallow the click
            // that selects its cell (direct manipulation) — and nobody
            // operates the video from inside the editor anyway.
            if (window.TB_EMBED) ifr.style.pointerEvents = 'none';
            inner.appendChild(ifr);
            videoFrames.push(ifr);
        } else {
            // No/unrecognised URL: a visible stub, same idea as tb-qr-empty —
            // the editor needs to see the cell's footprint, never a blank.
            el.classList.add('tb-video-empty');
        }
    }

    // Structural / base-only styling, set once.
    if (spec.fit) el.classList.add('tb-fit');
    else el.style.fontSize = (spec.size || 2.4) + 'vh';
    if (spec.pad) el.style.padding = spec.pad;
    // Cell-level border. Static on purpose (border is not a VARIANT_PROP, so
    // applyEmphasis never clears it). The sanitizer has always preserved
    // `border` on cells, but nothing painted it — a cell border only rendered
    // when the author happened to put it on the node wrapper instead.
    if (spec.border) el.style.border = spec.border;
    applyBoxImage(el, spec);
    // As a RATIO of the cell's font, not a fixed vh: capCell() shrinks
    // overflowing text by font-size, and discs pinned in vh would ignore the
    // shrink and overflow the bar on a narrow screen while their numbers
    // dutifully got smaller beside them.
    if (spec.chipSize) el.style.setProperty('--tb-chip-disc', (spec.chipSize / (spec.size || 2.4)).toFixed(3) + 'em');
    if (spec.spacing) el.style.letterSpacing = spec.spacing;
    // Base-only like size/align: a font swap changes metrics, so it must not
    // arrive via a variant and reflow the layout mid-game.
    if (spec.font && FONTS[spec.font]) el.style.fontFamily = FONTS[spec.font];
    if (spec.align === 'left')  { el.style.justifyContent = 'flex-start'; el.style.textAlign = 'left'; }
    if (spec.align === 'right') { el.style.justifyContent = 'flex-end';   el.style.textAlign = 'right'; }
    if (spec.clockColors) clockCells.push(el);

    var rec = {
        el: el, inner: inner, spec: spec, isImage: !!imgSrc || !!spec.qr || !!spec.chips || !!spec.seats || ('video' in spec),
        variants: Array.isArray(spec.variants) ? spec.variants : [],
        elSpans: [], lastText: null, lastVariant: -2, isFit: !!spec.fit
    };
    allCells.push(rec);
    if (spec.fit) fitCells.push(rec);
    return el;
}

// Shared named styles: layout-level name → visual-prop map; cells opt in via
// style:"name". Merge order: shared props under the cell's own (cell wins).
// Only visual props transfer — text/when/variants/image stay per-cell.
var sharedStyles = {};
var SHARED_KEYS = ['size', 'fit', 'color', 'bg', 'bold', 'pad', 'align', 'opacity', 'spacing', 'font'];

function withSharedStyle(spec) {
    var st = (spec && typeof spec.style === 'string') ? sharedStyles[spec.style] : null;
    if (!st || typeof st !== 'object') return spec;
    var merged = {};
    for (var i = 0; i < SHARED_KEYS.length; i++) {
        if (st[SHARED_KEYS[i]] !== undefined) merged[SHARED_KEYS[i]] = st[SHARED_KEYS[i]];
    }
    for (var k in spec) if (Object.prototype.hasOwnProperty.call(spec, k)) merged[k] = spec[k];
    return merged;
}

function buildNode(node, path) {
    var el;
    if (node.cell) {
        el = buildCell(withSharedStyle(node.cell));
        applyBox(el, node);
    } else {
        var kids = node.row || node.col || [];
        el = document.createElement('div');
        el.className = node.row ? 'tb-row' : 'tb-col';
        applyBox(el, node);
        for (var i = 0; i < kids.length; i++) el.appendChild(buildNode(kids[i], path.concat(i)));
        // A container's `when` hides the whole row/column, space and all: the
        // flex item leaves the layout and its siblings absorb the share.
        if (node.when !== undefined) whenBoxes.push({ el: el, when: node.when, shown: null });
    }
    // The node's address in the layout tree ("2.0.1"): the editor selects,
    // highlights and edits through these.
    el.setAttribute('data-path', path.join('.'));
    return el;
}

var CURRENT_LAYOUT = null;   // normalized {screens:[...]}
var activeScreen = -1;       // index currently built into the DOM
var forceScreen = null;      // editor pins a screen; null = auto by condition
var _building = false;       // guards against updateAll re-entrancy while building

// A layout is either single-screen ({bg, root}) or multi-screen
// ({screens:[{name, when, bg, root}]}). Normalize to the screens form so the
// renderer only deals with one shape. Screens are scanned in order; the first
// whose condition matches wins, and a screen with no `when` is the default.
function normalizeScreens(layout) {
    if (layout && Array.isArray(layout.screens) && layout.screens.length) return layout;
    // customElements / styles / triggers are layout-level; carry them across.
    return {
        screens: [{ name: 'Main', bg: layout ? layout.bg : null, root: layout ? layout.root : { col: [] } }],
        customElements: layout ? layout.customElements : undefined,
        styles: layout ? layout.styles : undefined,
        triggers: layout ? layout.triggers : undefined
    };
}

// Rotation state for `cycle` screens. Session-only; reset on layout load.
var _cycScreen = -1;   // screen index the rotation is currently showing
var _cycSince = 0;     // when it was entered (ms epoch)

function pickScreen() {
    var scr = CURRENT_LAYOUT.screens;
    if (forceScreen !== null && scr[forceScreen]) return forceScreen;
    var matching = [];
    for (var i = 0; i < scr.length; i++) {
        if (scr[i].when === undefined || scr[i].when === null || matchCond(scr[i].when)) matching.push(i);
    }
    if (!matching.length) return scr.length - 1;
    var first = matching[0];
    // Cycling: only when the screen that WOULD win opts in. That keeps a
    // no-cycle screen ordered first (Break) absolute: it interrupts any
    // rotation for as long as its condition matches.
    if (!(scr[first].cycle > 0)) { _cycScreen = -1; return first; }
    var set = matching.filter(function (ix) { return scr[ix].cycle > 0; });
    if (set.length < 2) { _cycScreen = -1; return first; }
    var now = Date.now();
    var pos = set.indexOf(_cycScreen);
    if (pos === -1) { _cycScreen = set[0]; _cycSince = now; }           // (re)enter the rotation
    else if (now - _cycSince >= scr[_cycScreen].cycle * 1000) {
        _cycScreen = set[(pos + 1) % set.length];                       // dwell over → next
        _cycSince = now;
    }
    return _cycScreen;
}

function buildScreen(idx) {
    _building = true;
    activeScreen = idx;
    customEls = (CURRENT_LAYOUT && CURRENT_LAYOUT.customElements && typeof CURRENT_LAYOUT.customElements === 'object')
        ? CURRENT_LAYOUT.customElements : {};
    sharedStyles = (CURRENT_LAYOUT && CURRENT_LAYOUT.styles && typeof CURRENT_LAYOUT.styles === 'object')
        ? CURRENT_LAYOUT.styles : {};
    rebuildElementIndex();
    var screen = CURRENT_LAYOUT.screens[idx] || { root: { col: [] } };
    fitCells = []; clockCells = []; allCells = []; whenBoxes = []; qrCells = []; chipCells = []; seatCells = []; videoFrames = [];
    root.textContent = '';
    root.style.background = '';
    root.style.backgroundImage = '';
    if (screen.bg) {
        root.style.background = screen.bg.gradient
            ? 'linear-gradient(160deg, ' + screen.bg.gradient[0] + ', ' + screen.bg.gradient[1] + ')'
            : (screen.bg.color || '#000');
        // Background image sits over the colour/gradient (validated same-origin).
        var bgImg = safeImageSrc(screen.bg.image);
        if (bgImg) {
            root.style.backgroundImage = 'url("' + bgImg + '")';
            root.style.backgroundSize = screen.bg.imageFit === 'contain' ? 'contain'
                                      : screen.bg.imageFit === 'stretch' ? '100% 100%' : 'cover';
            root.style.backgroundPosition = 'center';
            root.style.backgroundRepeat = 'no-repeat';
        }
    }
    applyAspect();
    var top = buildNode(screen.root || { col: [] }, []);
    top.classList.add('tb-top');
    if (screen.root && screen.root.pad) top.style.padding = screen.root.pad;
    root.appendChild(top);
    _building = false;
    updateAll();
    requestAnimationFrame(fitAll);
    drawQrCells();
    drawChipCells();
    drawSeatCells();
}

function renderLayoutObj(layout) {
    CURRENT_LAYOUT = normalizeScreens(layout);
    _cycScreen = -1;   // new layout, new screen indices — restart any rotation
    // New layout, new triggers: drop armed/fired state and cancel any takeover
    // the OLD layout was holding, or its pinned screen index would point into
    // the new layout's screen list.
    if (takeoverTimer) { clearTimeout(takeoverTimer); takeoverTimer = null; forceScreen = null; }
    resetTriggers();
    buildScreen(pickScreen());
}

function renderLayout(key) {
    renderLayoutObj(LAYOUTS[key] || LAYOUTS.classic);
}

/* ── Update loop ──────────────────────────────────────────────────────── */

// Refresh one element span in place; multiline ones (prizeList) rebuild breaks.
function paintElSpan(t) {
    var v = elementValue(t.name);
    if (v === null) v = '⟨' + t.name + '⟩';
    if (v.indexOf('\n') !== -1) {
        if (t.el.getAttribute('data-multi') !== v) {
            t.el.setAttribute('data-multi', v);
            t.el.textContent = '';
            var ls = v.split('\n');
            for (var j = 0; j < ls.length; j++) {
                if (j > 0) t.el.appendChild(document.createElement('br'));
                t.el.appendChild(document.createTextNode(ls[j]));
            }
        }
    } else if (t.el.textContent !== v) {
        t.el.textContent = v;
    }
}

// The active spec for a cell right now: its base, with the first matching
// variant's emphasis props merged over it. Variant index -1 means base only.
function resolveCell(rec) {
    for (var i = 0; i < rec.variants.length; i++) {
        if (matchCond(rec.variants[i].when)) return i;
    }
    return -1;
}

function updateAll() {
    if (typeof syncControls === 'function') syncControls();
    // The chip set can change mid-game (a host edits it in Setup), so the
    // legend belongs on the update path, not only on build. It is keyed on the
    // set's contents and returns immediately when nothing moved.
    drawChipCells();
    drawSeatCells();
    condTickUpdate();
    evalTriggers();
    // State may have moved us to a different screen (e.g. onto the break
    // screen). Rebuild for it, unless we're mid-build ourselves.
    if (!_building && CURRENT_LAYOUT && pickScreen() !== activeScreen) {
        buildScreen(pickScreen());
        return;
    }
    // Container gates first: like cells, evaluated per tick so a device- or
    // state-conditioned row/column follows along live.
    for (var wb = 0; wb < whenBoxes.length; wb++) {
        var box = whenBoxes[wb], show;
        try { show = matchCond(box.when); } catch (e) { show = true; }
        if (show !== box.shown) { box.shown = show; box.el.style.display = show ? '' : 'none'; }
    }
    for (var a = 0; a < allCells.length; a++) {
        var rec = allCells[a];

        // A cell's `when` gates visibility outright (no matching style → hidden).
        if (rec.spec.when !== undefined && !matchCond(rec.spec.when)) {
            rec.el.style.display = 'none';
            continue;
        }

        // Pick the active variant; only touch the DOM when it changed.
        var vi = rec.variants.length ? resolveCell(rec) : -1;
        var active = vi >= 0 ? rec.variants[vi] : rec.spec;
        if (vi !== rec.lastVariant) {
            rec.lastVariant = vi;
            // Emphasis: variant value if the variant sets it, else base value.
            var eff = {};
            for (var k = 0; k < VARIANT_PROPS.length; k++) {
                var key = VARIANT_PROPS[k];
                eff[key] = (vi >= 0 && active[key] !== undefined) ? active[key] : rec.spec[key];
            }
            applyEmphasis(rec.el, eff);
            // Text is a variant prop too, so rebuild the inner when it changes —
            // but never for an image cell (that would wipe its <img>).
            var newText = (vi >= 0 && active.text !== undefined) ? active.text : rec.spec.text;
            if (!rec.isImage && newText !== rec.lastText) {
                rec.lastText = newText;
                rec.inner.textContent = '';
                rec.elSpans = [];
                buildInner(rec.inner, newText, rec.elSpans, rec.spec.elStyles);
            }
        }

        // Live element values.
        for (var t = 0; t < rec.elSpans.length; t++) paintElSpan(rec.elSpans[t]);

        // A cell whose content resolved to nothing hides entirely, so an empty
        // prize bar or buy-in line paints no bare background band. Image cells
        // are exempt — they have no text content but should still show.
        // Auto-hide an empty cell. A chip legend is "empty" when the game has
        // no chip set — it draws nodes rather than text, so the text test would
        // call it empty always, and the isImage exemption would call it full
        // always. Deciding it here keeps ONE place that hides empty cells.
        var blank = rec.spec && (rec.spec.chips || rec.spec.seats) ? !rec.inner.firstChild
                  : (!rec.isImage && !rec.spec.bgImage && rec.inner.textContent.trim() === '');
        rec.el.style.display = blank ? 'none' : '';

        // Declared sizes are a maximum: when the rendered text changes SHAPE
        // (same digit-normalisation as the fit cells, so a ticking clock never
        // re-measures), re-check that it still fits its box and shrink the
        // inner if not — 2,000,000 / 4,000,000 by round 19 must compress, not
        // walk out over the artwork.
        if (!rec.isFit && !blank && (!rec.isImage || (rec.spec && rec.spec.chips))) {
            var ckey = fitShape(rec.inner.textContent);
            if (ckey !== rec.capLastText) { rec.capLastText = ckey; capCell(rec); }
            // Self-heal: a phone rotation can invalidate a cap AFTER it was
            // measured (iOS grows vh fonts later than the resize event), and a
            // ticking clock never changes shape — without this the overflow
            // sticks until the text itself does.
            else if (rec.inner.scrollWidth > rec.el.clientWidth + 1
                  || rec.inner.scrollHeight > rec.el.clientHeight + 1) capCell(rec);
        }
    }

    var rem = liveRemaining();
    for (var c = 0; c < clockCells.length; c++) {
        var el = clockCells[c];
        el.classList.toggle('tb-crit', rem <= 30);
        el.classList.toggle('tb-warn', rem > 30 && rem <= S.warnSecs);
        el.classList.toggle('tb-paused', !S.running && !S.sample);
    }

    // Re-fit only the fit cells whose rendered text actually changed SHAPE.
    // Digits are interchangeable here: with tabular figures every digit is the
    // same width, so 03:54 and 03:53 need identical sizes, and re-measuring
    // between them can only introduce jitter (a font WITHOUT tabular figures
    // measures a hair narrower or wider and the clock pumps once a second).
    // Normalising digits away means a tick never re-fits; a real shape change
    // — 9:59 to 10:00, or a break label appearing — still does.
    for (var f = 0; f < fitCells.length; f++) {
        var fc = fitCells[f], key = fitShape(fc.inner.textContent);
        if (key !== fc.fitLastText) { fc.fitLastText = key; fitCell(fc); }
    }
}

function fitShape(t) { return String(t).replace(/[0-9]/g, '0'); }

/* Fit-to-box: measure at a probe size, scale to the limiting dimension. */
function fitCell(fc) {
    var boxW = fc.el.clientWidth, boxH = fc.el.clientHeight;
    if (!boxW || !boxH) return;
    fc.inner.style.fontSize = '100px';
    var w = fc.inner.scrollWidth, h = fc.inner.scrollHeight;
    if (!w || !h) return;
    var size = Math.max(8, Math.floor(100 * Math.min(boxW / w, boxH / h) * 0.92));
    fc.inner.style.fontSize = size + 'px';
}
/* Declared sizes are a MAXIMUM, not a promise. A sized cell whose text has
 * outgrown its box (blinds at 2,000,000 / 4,000,000 by round 19) walks out
 * over the artwork; shrink the INNER instead, and let it grow back when the
 * text shortens. The declared size stays on the cell element — resetting the
 * inner to 'inherit' before measuring is what makes growth possible. */
function capCell(rec) {
    // A chip legend is isImage for auto-hide purposes but its discs are DOM
    // nodes sized in em — font shrink is exactly how it compresses, so it
    // takes the cap. True image/QR cells stay out (object-fit contains them).
    if (rec.isImage && !(rec.spec && rec.spec.chips)) return;
    rec.inner.style.fontSize = '';
    var boxW = rec.el.clientWidth, boxH = rec.el.clientHeight;
    if (!boxW || !boxH) return;
    var w = rec.inner.scrollWidth, h = rec.inner.scrollHeight;
    if (!w || !h) return;
    if (w <= boxW && h <= boxH) return;              // fits at its declared size
    var base = parseFloat(getComputedStyle(rec.el).fontSize) || 16;
    var scaled = Math.max(8, Math.floor(base * Math.min(boxW / w, boxH / h) * 0.95));
    rec.inner.style.fontSize = scaled + 'px';
}
function fitAll() {
    for (var i = 0; i < fitCells.length; i++) fitCell(fitCells[i]);
    for (var j = 0; j < allCells.length; j++) {
        if (!allCells[j].isFit) capCell(allCells[j]);
    }
}
/* A rotation is not one resize: iOS settles the viewport (and vh-based font
 * sizes) over a few hundred ms AFTER the resize event, so a single next-frame
 * fit measures stale geometry and leaves the clock walked out of its box.
 * Fit now for the common case, then twice more as the flip settles. */
function scheduleRefit() {
    requestAnimationFrame(fitAll);
    setTimeout(fitAll, 180);
    setTimeout(fitAll, 500);
}

/* ── QR cells ─────────────────────────────────────────────────────────────
 * Targets are resolved here, from app state, so a layout can never choose what
 * a scanner is sent to. 'display' opens this timer read-only on the scanning
 * device; whether that device can also CONTROL is decided by who is logged in
 * on it — get_state returns can_control from the session, and every command
 * re-checks manage rights server-side. The key buys a view, nothing more. */
function qrTargetUrl(target) {
    var key = (typeof TB_CAST_KEY !== 'undefined' && TB_CAST_KEY) ? TB_CAST_KEY : null;
    if (!key) return null;
    if (target === 'display') return location.origin + '/timer_beta.php?key=' + encodeURIComponent(key);
    return null;
}

/* Redrawn only when the set actually changes — this runs on every tick. */
/* ── Final-table seat map ─────────────────────────────────────────────────
 * An ellipse of seats around a felt, each still-in player at their assigned
 * seat: avatar (their photo when they have one, initials on a hue disc when
 * they do not — same algorithm as avatar_html in db.php), name, seat badge.
 * Empty seats draw as dim rings so the table's shape reads even short-handed.
 *
 * Which table: spec.table pins one; the default is the table holding the most
 * still-in players (ties -> lowest number), which at an actual final table is
 * simply THE table. Two players on the same seat (the DB has no constraint)
 * both render, nudged apart — the display must never lose a player. */
// Same algorithm as avatar_hue() (db.php) / gnAvatarHue (avatar.js), so a
// player's initials disc is the same colour on the wall as in the app.
function avatarHue(name) {
    var s = String(name || '').toLowerCase().trim(), h = 0;
    for (var i = 0; i < s.length; i++) h = (h * 31 + s.charCodeAt(i)) >>> 0;
    return s.length ? h % 360 : 210;
}
function safeAvatarSrc(v) {
    return (typeof v === 'string' && /^\/uploads\/avatars\/[A-Za-z0-9._/-]{1,160}$/.test(v)) ? v : null;
}

function drawSeatCells() {
    if (!seatCells.length) return;
    for (var i = 0; i < seatCells.length; i++) {
        var c = seatCells[i];
        var players = S.seatPlayers || [];
        // Pinned table, or the busiest one.
        var tableNo = c.spec.table | 0;
        if (!tableNo) {
            var counts = {};
            for (var p0 = 0; p0 < players.length; p0++) {
                var t = players[p0].table | 0;
                counts[t] = (counts[t] || 0) + 1;
            }
            var best = 0;
            for (var k in counts) {
                if (!best || counts[k] > counts[best] || (counts[k] === counts[best] && +k < +best)) best = +k;
            }
            tableNo = best || 1;
        }
        var seated = players.filter(function (p) { return (p.table | 0) === tableNo; });
        // A still-in player with NO seat (manual seating, or freshly restored)
        // must never vanish from the display: they wait in a tray under the
        // table until the host seats them.
        var unseated = players.filter(function (p) { return !(p.table | 0) || !(p.seat | 0); });

        // Seat count: the room's configured seats, stretched if anyone is
        // over-sat (pick_random_seat legitimately hands out seat N+1 when a
        // table is full).
        var nSeats = (S.game && (S.game.seats | 0)) || 9;
        for (var p1 = 0; p1 < seated.length; p1++) nSeats = Math.max(nSeats, seated[p1].seat | 0);

        var key = JSON.stringify([tableNo, nSeats, seated, unseated]);
        if (c.drawn === key) continue;
        c.drawn = key;
        c.inner.textContent = '';
        if (!seated.length && !unseated.length) continue;   // updateAll hides it

        var wrap = document.createElement('div');
        wrap.className = 'tb-seatmap';
        // The table itself: rail (leather edge), felt, betting line — layered
        // stadium shapes, the racetrack silhouette of a real seating chart.
        var rail = document.createElement('div');
        rail.className = 'tb-seatmap-rail';
        var felt = document.createElement('div');
        felt.className = 'tb-seatmap-felt';
        var line = document.createElement('div');
        line.className = 'tb-seatmap-line';
        felt.appendChild(line);
        var centre = document.createElement('div');
        centre.className = 'tb-seatmap-centre';
        centre.textContent = 'TABLE ' + tableNo;
        felt.appendChild(centre);
        rail.appendChild(felt);
        wrap.appendChild(rail);

        var bySeat = {};
        for (var p2 = 0; p2 < seated.length; p2++) {
            var sn = seated[p2].seat | 0;
            (bySeat[sn] = bySeat[sn] || []).push(seated[p2]);
        }

        // Walk the stadium's perimeter by distance, so seats space evenly
        // along straights and arcs alike. Working in the wrap's 100x100
        // percent space; the CSS margins leave that ring for the seats.
        var SX = 40, SY = 36;          // half-extents of the seat ring
        var SS = 16;                    // half-length of each straight rail
        var AX = SX - SS;               // arc horizontal radius
        var arcLen = Math.PI * Math.sqrt((AX * AX + SY * SY) / 2);   // ~half-ellipse
        var per = 4 * SS + 2 * arcLen; // two straights + two arc ends
        function stadiumPoint(d) {
            d = ((d % per) + per) % per;
            // Segment 1: bottom straight, centre -> left (clockwise for the viewer).
            if (d < SS) return { x: 50 - d, y: 50 + SY };
            d -= SS;
            // Segment 2: left arc, bottom -> top.
            if (d < arcLen) {
                var t1 = Math.PI / 2 + Math.PI * (d / arcLen);
                return { x: 50 - SS + AX * Math.cos(t1), y: 50 + SY * Math.sin(t1) };
            }
            d -= arcLen;
            // Segment 3: top straight, left -> right.
            if (d < 2 * SS) return { x: 50 - SS + d, y: 50 - SY };
            d -= 2 * SS;
            // Segment 4: right arc, top -> bottom.
            if (d < arcLen) {
                var t2 = -Math.PI / 2 + Math.PI * (d / arcLen);
                return { x: 50 + SS + AX * Math.cos(t2), y: 50 + SY * Math.sin(t2) };
            }
            // Segment 5: bottom straight, right -> centre.
            d -= arcLen;
            return { x: 50 + SS - d, y: 50 + SY };
        }
        for (var seat = 1; seat <= nSeats; seat++) {
            var pt = stadiumPoint((seat - 1) * per / nSeats);
            var cx = pt.x, cy = pt.y;
            var here = bySeat[seat] || [null];
            for (var d = 0; d < here.length; d++) {
                var pl = here[d];
                var node = document.createElement('div');
                node.className = 'tb-seat' + (pl ? '' : ' tb-seat-empty');
                node.style.left = (cx + d * 4) + '%';
                node.style.top = (cy + d * 4) + '%';

                var disc = document.createElement('div');
                disc.className = 'tb-seat-disc';
                if (pl) {
                    var src = safeAvatarSrc(pl.avatar);
                    if (src) {
                        var img = document.createElement('img');
                        img.src = src;
                        img.alt = '';
                        disc.appendChild(img);
                    } else {
                        disc.style.background = 'hsl(' + avatarHue(pl.name) + ',60%,30%)';
                        var ch = String(pl.name || '').replace(/[^a-zA-Z0-9]/g, '').charAt(0);
                        disc.textContent = (ch || '?').toUpperCase();
                    }
                } else {
                    disc.textContent = String(seat);
                }
                node.appendChild(disc);

                if (pl) {
                    var nm = document.createElement('div');
                    nm.className = 'tb-seat-name';
                    nm.textContent = pl.name;
                    node.appendChild(nm);
                    var badge = document.createElement('div');
                    badge.className = 'tb-seat-num';
                    badge.textContent = String(seat);
                    node.appendChild(badge);
                }
                wrap.appendChild(node);
            }
        }
        if (unseated.length) {
            var tray = document.createElement('div');
            tray.className = 'tb-seat-tray';
            var lbl = document.createElement('span');
            lbl.className = 'tb-seat-tray-label';
            lbl.textContent = 'Awaiting seat:';
            tray.appendChild(lbl);
            for (var u = 0; u < unseated.length; u++) {
                var un = document.createElement('span');
                un.className = 'tb-seat-tray-name';
                un.textContent = unseated[u].name;
                tray.appendChild(un);
            }
            wrap.appendChild(tray);
        }
        c.inner.appendChild(wrap);
    }
}

function drawChipCells() {
    if (!chipCells.length) return;
    var key = JSON.stringify(S.chips || []);
    for (var i = 0; i < chipCells.length; i++) {
        var c = chipCells[i];
        if (c.drawn === key) continue;
        c.drawn = key;
        c.inner.textContent = '';
        var list = S.chips || [];
        // Visibility is not decided here: updateAll() owns hiding empty cells,
        // and two places writing style.display means whichever runs last wins.
        for (var j = 0; j < list.length; j++) {
            var wrap = document.createElement('span');
            wrap.className = 'tb-chip';
            var disc = document.createElement('span');
            disc.className = 'tb-chip-disc';
            disc.style.background = list[j].c;
            // A photo of the real chip, drawn over the colour rather than
            // instead of it: if the file is ever deleted the legend degrades to
            // the colour it always had, instead of a row of empty rings.
            var cimg = safeImageSrc(list[j].img);
            if (cimg) {
                disc.style.backgroundImage = 'url("' + cimg + '")';
                disc.style.backgroundSize = 'cover';
                disc.style.backgroundPosition = 'center';
                disc.classList.add('tb-chip-photo');
            }
            var val = document.createElement('span');
            val.className = 'tb-chip-val';
            val.textContent = fmtChips(list[j].v);
            wrap.appendChild(disc);
            wrap.appendChild(val);
            c.inner.appendChild(wrap);
        }
    }
}

function drawQrCells() {
    if (!qrCells.length) return;
    for (var i = 0; i < qrCells.length; i++) {
        var q = qrCells[i];
        var url = qrTargetUrl(q.target);
        // The library missing is the only case with nothing to draw: keep the
        // dashed footprint so the cell still shows its size and position.
        if (typeof qrcode === 'undefined') {
            if (q.drawn !== '__placeholder__') {
                q.drawn = '__placeholder__';
                q.img.removeAttribute('src');
                // Drop the alt too: a src-less <img> renders its alt text, and
                // the cell is already labelled by the dashed placeholder.
                q.img.alt = '';
                q.el.classList.add('tb-qr-empty');
            }
            continue;
        }
        // Sample and embed modes have no session, so there is no honest code
        // to show — but a dashed stub reads as a broken cell in the editor.
        // Draw a REAL code so the author sees the true footprint and contrast,
        // pointed somewhere deliberately absurd so a stale screenshot of it
        // can never pass for a live link. Anyone who scans the preview has
        // been rickrolled, which is its own documentation.
        var sample = !url;
        if (sample) url = 'https://youtu.be/dQw4w9WgXcQ';
        if (q.drawn === url) continue;
        q.drawn = url;
        q.img.alt = sample ? 'Sample QR code (the real one appears on a live game)'
                           : 'QR code to open this timer on another screen';
        q.el.classList.remove('tb-qr-empty');
        try {
            var qr = qrcode(0, 'M');
            qr.addData(url);
            qr.make();
            // The library's own image output: a hand-rolled canvas draw rendered
            // as a solid black square in some browsers (see timer.js).
            q.img.src = qr.createDataURL(8, 2);
        } catch (e) {
            q.drawn = null;
        }
    }
}

function refreshDerived() {
    var cur = findLevel(S.level), nxt = findLevel(S.level + 1);
    S.isBreak = !!(cur && (cur.is_break | 0));
    S.sb = cur ? cur.small_blind : 0;
    S.bb = cur ? cur.big_blind : 0;
    S.ante = cur ? cur.ante : 0;
    S.nextIsBreak = !!(nxt && (nxt.is_break | 0));
    S.nsb = nxt ? nxt.small_blind : null;
    S.nbb = nxt ? nxt.big_blind : null;
    S.nante = nxt ? nxt.ante : null;
}

// Display-mode hook: set by the layout-picker section so a change to the
// event's stored layout (event_display.php) is followed without a reload.
var onEventLayout = null;

/* How far the server may disagree with the local countdown before we believe
 * it. compute_live_state() answers in WHOLE seconds, computed at request time,
 * and the reply takes a variable few tens of milliseconds to arrive — so
 * snapping to it on every 2-second poll moved the countdown backwards and
 * forwards by a second or two. The clock and "next break" stuttered
 * (1:18:30 → 1:18:27 → 1:18:29) instead of counting down. Anything smaller
 * than this is poll noise; anything larger is a real correction — someone
 * adjusted the clock, or this tab was asleep. */
var RESYNC_TOLERANCE = 2.5;

function applyTimerSync(t, requestedAt) {
    var lvl = t.current_level | 0;
    var rem = t.time_remaining_seconds | 0;
    var run = !!(t.is_running | 0);

    // Anchor path: level and running state are discrete and safe to take
    // verbatim; the countdown is derived, never assigned, so there is nothing
    // for a poll to jog. RESYNC_TOLERANCE below exists only for the legacy
    // path — with an anchor the stutter is not smoothed, it is impossible.
    if (t.ends_at_ms || t.remaining_ms !== null && t.remaining_ms !== undefined) {
        S.level = lvl;
        S.running = run;
        S.remaining = rem;                 // kept for anything still reading it
        S.fetchedAt = Date.now();
        S.synced = true;
        S.anchorEndsAt = t.ends_at_ms ? +t.ends_at_ms : null;
        S.anchorRemainingMs = (t.remaining_ms === null || t.remaining_ms === undefined) ? null : +t.remaining_ms;
        return;
    }
    S.anchorEndsAt = null; S.anchorRemainingMs = null;
    // Compare against the CURRENT local value before touching state.
    var drift = Math.abs(rem - liveRemaining());
    // A level change, a start/stop, or a paused clock is always authoritative;
    // there is nothing to interpolate and the value genuinely moved.
    var structural = !S.synced || lvl !== S.level || run !== S.running || !run;
    if (!structural && drift <= RESYNC_TOLERANCE) return;
    S.level = lvl;
    S.remaining = rem;
    S.running = run;
    // Stamp the sample at the MIDPOINT of the round trip, not on arrival: the
    // server's answer was true somewhere in the middle of it, so crediting it
    // to the end builds the whole latency into the displayed time.
    S.fetchedAt = requestedAt + (Date.now() - requestedAt) / 2;
    S.synced = true;
}

function poll() {
    // A screen opened by QR has no event access, so it must poll by key — the
    // same authorisation it used to open the page.
    var byKey = (typeof TB_KEY !== 'undefined' && TB_KEY);
    if (!TB_SESSION_ID && !byKey) return;
    var requestedAt = Date.now();
    var url = byKey
        ? '/timer_dl.php?action=get_state&key=' + encodeURIComponent(TB_KEY)
        : '/timer_dl.php?action=get_state&session_id=' + TB_SESSION_ID;
    fetch(url)
        .then(function (r) { return r.json(); })
        .then(function (j) {
            if (!j || !j.ok) return;
            if (typeof onEventLayout === 'function') onEventLayout(j.layout_id || null, j.layout_builtin || null);
            // Number(), never |0: epoch milliseconds is ~1.79e12 and a bitwise
            // OR truncates to 32 bits, which turned the server's clock into
            // near-zero and the countdown into a 496307-hour number.
            noteClockSample(Number(j.server_now_ms) || 0, requestedAt, Date.now());
            // A new build shipped while this screen was open: reload once and
            // come back on the fresh code. Displays run for hours — without
            // this, no fix ever reaches a screen that is already on the wall.
            if (typeof TB_ASSET_V !== 'undefined' && TB_ASSET_V && j.asset_v
                && Number(j.asset_v) !== Number(TB_ASSET_V) && !window._tbReloading) {
                window._tbReloading = true;   // one reload, not a loop
                setTimeout(function () { location.reload(); }, 500);
                return;
            }
            applyTimerSync(j.timer, requestedAt);
            S.levels = j.levels || [];
            S.chips = Array.isArray(j.chips) ? j.chips : [];
            S.warnSecs = (j.sounds && j.sounds.warning_seconds) || 60;
            if (j.event_title) S.eventName = j.event_title;
            S.gameOver = j.session_status === 'finished';
            var p = j.pool;
            if (p) {
                S.stillNum = p.still_playing | 0; S.totalNum = p.total_players | 0; S.chipsNum = p.chips_in_play | 0;
                S.players = S.stillNum + '/' + S.totalNum;
                S.entries = p.total_buyins | 0;
                S.rebuys = p.total_rebuys | 0;
                S.pot = '$' + ((p.pool_total | 0) / 100).toLocaleString('en-US');
                S.chipCount = (p.chips_in_play | 0) > 0 ? fmtChips(p.chips_in_play) : '-';
                S.avgStack = (p.chips_in_play | 0) > 0 && (p.still_playing | 0) > 0
                    ? fmtChips(Math.round(p.chips_in_play / p.still_playing)) : '-';
                S.buyIns = p.bought_in | 0;
                S.addOns = p.total_addons | 0;
                S.elimNum = p.eliminated | 0;
                S.cashedNum = p.cashed_out | 0;
                S.bountyPool = p.bounty_collected | 0;
                S.jackpotPool = p.jackpot_collected | 0;
            }
            S.game = j.game || null;
            S.seatPlayers = Array.isArray(j.players) ? j.players : [];
            S.lastElim = (j.last_eliminated && j.last_eliminated.name) ? String(j.last_eliminated.name) : '';
            S.lastElimPlace = (j.last_eliminated && j.last_eliminated.place) ? (j.last_eliminated.place | 0) : 0;
            S.prizes = [];
            var pay = j.payouts || [], poolCents = p ? (p.pool_total | 0) : 0;
            for (var i = 0; i < pay.length; i++) {
                // A place's reward is whatever the structure grants — a cut of
                // a real pool, points, an entry ticket, a named prize, or
                // several at once (Payout 2.0). The dollar amount is one part
                // among equals, not the gatekeeper it used to be: a free
                // points league has no pool, and its prize elements rendered
                // BLANK on a live game because nothing else was consulted.
                var parts = [];
                if (poolCents && Number(pay[i].percentage) > 0) {
                    parts.push('$' + Math.round(poolCents * Number(pay[i].percentage) / 100 / 100).toLocaleString('en-US'));
                }
                if ((pay[i].points | 0) > 0) parts.push((pay[i].points | 0) + ' pts');
                if ((pay[i].ticket_cents | 0) > 0) parts.push('🎟 $' + ((pay[i].ticket_cents | 0) / 100).toLocaleString('en-US'));
                if (pay[i].prize_label) parts.push(String(pay[i].prize_label));
                if (parts.length) S.prizes.push(ordinal(pay[i].place | 0) + ': ' + parts.join(' · '));
            }
            // Control: whether this viewer may drive the timer, and the CSRF
            // token to do it. The server re-checks manage rights on every
            // command regardless, so these only decide what the client offers.
            CAN_CONTROL = !!j.can_control;
            if (j.csrf_token) CSRF = j.csrf_token;
            refreshDerived();
            updateAll();
        })
        .catch(function () { /* transient network errors: keep ticking on last state */ });
}

// Whether keyboard control is live: a real event session, the viewer can
// control it, we have a CSRF token, and we are not the editor's preview iframe.
var CAN_CONTROL = false;
var CSRF = null;
function controlArmed() {
    return !window.TB_EMBED && !!TB_SESSION_ID && CAN_CONTROL && !!CSRF;
}
function sendCommand(cmd) {
    if (!controlArmed()) return;
    var body = new URLSearchParams();
    body.set('action', 'command');
    body.set('cmd', cmd);
    body.set('session_id', String(TB_SESSION_ID));
    body.set('csrf_token', CSRF);
    fetch('/timer_dl.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: String(body) })
        .then(function (r) { return r.json(); })
        .then(function (j) { if (j && j.csrf_token) CSRF = j.csrf_token; poll(); })   // reflect the new state at once
        .catch(function () {});
}

// On-screen control tray. It only exists in the DOM on an event-linked, non
// -embed page; syncControls() (called from updateAll) reveals it once
// get_state confirms the viewer can control, and keeps the play/stop button in
// sync with the running state.
var ctrls = document.getElementById('tbControls');
if (ctrls) {
    ctrls.addEventListener('click', function (e) {
        var btn = e.target.closest('button');
        if (!btn) return;
        if (btn.getAttribute('data-act') === 'fullscreen') {
            var fe = document.documentElement;
            if (document.fullscreenElement) document.exitFullscreen();
            else if (fe.requestFullscreen) fe.requestFullscreen();
            else if (fe.webkitRequestFullscreen) fe.webkitRequestFullscreen();
            return;
        }
        var cmd = btn.getAttribute('data-cmd');
        if (cmd) sendCommand(cmd);
    });
}
/* ── Fullscreen, for any viewer ───────────────────────────────────────────
 * The tray's fullscreen button only exists once the server says this viewer can
 * CONTROL the game. A screen opened by scanning the QR code usually cannot, and
 * that is exactly the screen most likely to want fullscreen, so it gets its own
 * button.
 *
 * iOS is the interesting case: Safari implements the Fullscreen API for <video>
 * and nothing else, on iPad included. Feature-detected rather than sniffed, and
 * where it is missing the button explains Add to Home Screen instead of being a
 * control that does nothing. */
var fsBtn = document.getElementById('tbFsBtn');
var fsHint = document.getElementById('tbFsHint');
var fsRoot = document.documentElement;
var canFullscreen = !!(fsRoot.requestFullscreen || fsRoot.webkitRequestFullscreen);
// Launched from the home screen, or already fullscreen: no chrome to escape.
function inStandalone() {
    return !!navigator.standalone ||
           (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches);
}
function toggleFullscreen() {
    if (canFullscreen) {
        if (document.fullscreenElement || document.webkitFullscreenElement) {
            (document.exitFullscreen || document.webkitExitFullscreen).call(document);
        } else {
            (fsRoot.requestFullscreen || fsRoot.webkitRequestFullscreen).call(fsRoot);
        }
        return;
    }
    if (!fsHint) return;
    // No API here. Say what actually works on this device.
    fsHint.textContent = '';
    var line = document.createElement('div');
    line.textContent = 'This browser cannot go fullscreen on its own.';
    var how = document.createElement('div');
    how.style.marginTop = '.35rem';
    how.appendChild(document.createTextNode('Tap '));
    var b1 = document.createElement('b'); b1.textContent = 'Share';
    how.appendChild(b1);
    how.appendChild(document.createTextNode(' then '));
    var b2 = document.createElement('b'); b2.textContent = 'Add to Home Screen';
    how.appendChild(b2);
    how.appendChild(document.createTextNode(' — opening the timer from that icon fills the screen, and it remembers this game.'));
    fsHint.appendChild(line);
    fsHint.appendChild(how);
    fsHint.hidden = false;
    clearTimeout(fsHint._t);
    fsHint._t = setTimeout(function () { if (fsHint) fsHint.hidden = true; }, 9000);
}
if (fsBtn) {
    if (inStandalone()) fsBtn.remove();
    else fsBtn.addEventListener('click', function (e) { e.stopPropagation(); toggleFullscreen(); });
}

// Auto-hide: the tray is solid while active, then fades fully out after a few
// seconds of no interaction. Any pointer move, tap or key brings it back.
var idleTimer = null;
function showControls() {
    // The fullscreen button follows the same idle rule but exists without the
    // tray, so it is faded independently rather than as part of it.
    if (fsBtn) fsBtn.classList.remove('tb-idle');
    var sB = document.getElementById('tbSndBtn');
    if (sB) sB.classList.remove('tb-idle');
    if (ctrls && !ctrls.hidden) ctrls.classList.remove('tb-idle');
    if (idleTimer) clearTimeout(idleTimer);
    idleTimer = setTimeout(function () {
        if (ctrls) ctrls.classList.add('tb-idle');
        if (fsBtn) fsBtn.classList.add('tb-idle');
        if (sB) sB.classList.add('tb-idle');
    }, 3000);
}
['mousemove', 'mousedown', 'touchstart', 'keydown'].forEach(function (ev) {
    document.addEventListener(ev, showControls, { passive: true });
});
showControls();

function syncControls() {
    if (!ctrls) return;
    var armed = controlArmed();
    var wasHidden = ctrls.hidden;
    ctrls.hidden = !armed;
    // First time it becomes available, reveal it and start the idle countdown.
    if (armed && wasHidden) showControls();
    if (!armed && idleTimer) { clearTimeout(idleTimer); idleTimer = null; }
    var play = document.getElementById('tbPlayBtn');
    if (play) {
        play.classList.toggle('is-running', S.running);
        play.innerHTML = S.running ? '&#9208;' : '&#9654;';   // ⏸ / ▶
    }
}

/* Sample mode loops its clock so the display always looks alive. */
function tick() {
    if (S.sample && S.running && liveRemaining() <= 0) {
        S.remaining = 1200; S.fetchedAt = Date.now();
    }
    updateAll();
}

/* ── Embed mode: the editor drives this page inside an iframe ─────────── */

if (window.TB_EMBED) {
    window.TBPreview = {
        builtins:  LAYOUTS,
        // Which built-in an unconfigured display shows, so the editor can
        // mirror it instead of guessing.
        defaultKey: DEFAULT_LAYOUT,
        // The named-font whitelist, so the editor's picker can never offer a
        // face this engine won't paint (same pattern as elementNames).
        fonts: FONTS,
        setLayout: function (layoutObj) { renderLayoutObj(layoutObj); },
        setState:  function (partial) { Object.assign(S, partial); refreshDerived(); updateAll(); },
        refresh:   function () { updateAll(); requestAnimationFrame(fitAll); },
        // Editor pins the screen it is editing so the preview shows it
        // regardless of the sample state; null returns to auto-by-condition.
        forceScreen: function (i) { forceScreen = (i === null || i === undefined) ? null : i; if (CURRENT_LAYOUT) buildScreen(pickScreen()); },
        activeScreenIndex: function () { return activeScreen; },
        elementNames: function () { return Object.keys(ELEMENTS).concat(Object.keys(customEls)); },
        // The advertised (namespaced) element list: dotted names plus the
        // undotted icons and the layout's custom elements.
        elementNamesNS: function () {
            return ['clock'].concat(Object.keys(ELEMENT_NS)).concat(Object.keys(customEls));
        },
        // Condition expressions: the editor validates through the renderer's
        // own parser rather than reimplementing the grammar, so the tick the
        // author sees and the truth the display computes can never disagree.
        validateCondition: function (src) {
            var t = String(src || '').trim();
            if (t === '') return { ok: true, error: null };
            var c = compileCond(t);
            return { ok: !!c.fn, error: c.error };
        },
        // Streaming-URL check: the editor validates a pasted link through the
        // renderer's own normalizer (same reason as validateCondition — the
        // tick the author sees and what the display loads can never disagree).
        normalizeStreamUrl: function (raw) { return normalizeStreamUrl(raw); },
        conditionNames: function () { return COND_NAMES.slice(); },
        // Trigger "Test" button: run an action list right now, ignoring
        // when/cooldown/once — auditioning, not simulating.
        runActions: function (list) { runTriggerActions(list); },
        // QA hooks: the engine is IIFE-wrapped, so the suite observes sound
        // policy and would-be playback here instead of stubbing internals.
        sound: {
            on:   function () { return soundOn; },
            set:  function (v) { soundOn = !!v; },
            safe: function (v) { return safeSoundSrc(v); },
            hook: function (fn) { soundHook = fn; }
        },
        // Preview device toggle: force mobile/tablet/desktop conditions, or
        // null for honest detection. Triggers re-arm first so a device flip
        // never edge-fires a sound in the editor.
        device: function (d) {
            deviceOverride = (d === 'mobile' || d === 'tablet' || d === 'desktop') ? d : null;
            resetTriggers();
            updateAll();
        },
        // Current value of every element, for the editor's picker (so it can show
        // "<clock> — 12:31" and never drift from the renderer's real list).
        elementValues: function () {
            var out = {};
            Object.keys(ELEMENTS).forEach(function (n) { try { out[n] = String(ELEMENTS[n]()); } catch (e) { out[n] = ''; } });
            // The namespaced spellings too, so a picker showing blinds.small
            // can label it with the same live value smallBlind carries.
            Object.keys(ELEMENT_NS).forEach(function (n) {
                var t = ELEMENT_NS[n];
                if (ELEMENTS[t]) { try { out[n] = String(ELEMENTS[t]()); } catch (e) { out[n] = ''; } }
            });
            Object.keys(customEls).forEach(function (n) { out[n] = String(customEls[n]); });
            return out;
        },
        select:    function (pathStr) {
            document.querySelectorAll('.tb-selected').forEach(function (n) { n.classList.remove('tb-selected'); });
            if (pathStr === null || pathStr === undefined) return;
            var el = document.querySelector('[data-path="' + pathStr + '"]');
            if (el) el.classList.add('tb-selected');
        },
        onSelect: null
    };
    document.addEventListener('click', function (e) {
        var el = e.target.closest ? e.target.closest('[data-path]') : null;
        if (!el || !window.TBPreview.onSelect) return;
        e.preventDefault();
        window.TBPreview.onSelect(el.getAttribute('data-path'));
    }, true);
    window.addEventListener('resize', scheduleRefit);
    refreshDerived();
    // No seed paint. The editor pushes the real layout via setLayout as soon
    // as it boots, and anything painted here shows through as a flash of the
    // wrong design during the handoff (Classic did, once the editor stopped
    // booting on Classic itself). An empty root over the black page background
    // reads as loading instead. The update loop tolerates the layoutless
    // window: every consumer of CURRENT_LAYOUT guards on it, and the cell
    // arrays start empty. Seeding a trigger-free built-in is not an option
    // either, since any visible seed that differs from what the editor loads
    // brings the flash right back.
    setInterval(tick, 500);
} else {

/* ── Layout picker (display mode) ─────────────────────────────────────── */

var sel = document.getElementById('tbLayoutSelect');
var saved = null;
try { saved = localStorage.getItem('tb_layout'); } catch (e) {}
var params = new URLSearchParams(location.search);
// Priority: explicit ?layout= URL > the event's stored choice (a saved row
// OR a built-in key) > this browser's remembered pick > classic.
var eventLayoutId = (typeof TB_EVENT_LAYOUT_ID !== 'undefined' && TB_EVENT_LAYOUT_ID) ? (TB_EVENT_LAYOUT_ID | 0) : null;
var eventLayoutKey = (typeof TB_EVENT_LAYOUT_KEY !== 'undefined' && TB_EVENT_LAYOUT_KEY) ? String(TB_EVENT_LAYOUT_KEY) : null;
var urlOverride = !!params.get('layout');
var manualOverride = false;   // picking at the TV opts out of following, until reload
var pick = params.get('layout')
        || (eventLayoutId ? 'id:' + eventLayoutId : null)
        || (eventLayoutKey && LAYOUTS[eventLayoutKey] ? eventLayoutKey : null)
        || saved;

// A key names one timer, and that timer names one layout, so a scanned screen
// can read the layout it is meant to show without an account.
function castKeyParam() {
    var k = (typeof TB_KEY !== 'undefined' && TB_KEY) ? TB_KEY : null;
    return k ? '&key=' + encodeURIComponent(k) : '';
}

// Follow the event's binding live: when get_state reports a different
// binding (row id or built-in key) than the one applied, swap. Overrides win.
onEventLayout = function (lid, lkey) {
    lid = lid ? (lid | 0) : null;
    lkey = lkey ? String(lkey) : null;
    if (urlOverride || manualOverride || (lid === eventLayoutId && lkey === eventLayoutKey)) return;
    eventLayoutId = lid;
    eventLayoutKey = lkey;
    if (lid) {
        // A scanned screen asks by KEY: it may not be logged in, and even if it
        // is, the layout belongs to the host rather than to whoever scanned it.
        fetch('/timer_beta_dl.php?action=get_layout&id=' + lid + castKeyParam())
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (j && j.ok && j.layout) {
                    renderLayoutObj(j.layout);
                    if (sel) sel.value = 'id:' + lid;
                }
            })
            .catch(function () {});
    } else if (lkey && LAYOUTS[lkey]) {
        renderLayout(lkey);
        if (sel) sel.value = lkey;
    }
    // binding cleared entirely: keep showing what's up
};

// The picker only exists when the corner bar does — a scanned screen has no
// bar, and populating a control that is not there threw on the first
// appendChild and took the whole display with it.
if (sel) {
    Object.keys(LAYOUTS).forEach(function (k) {
        var o = document.createElement('option');
        o.value = k; o.textContent = LAYOUTS[k].name;
        sel.appendChild(o);
    });

    // Saved layouts load after the built-ins; the picker groups them.
    fetch('/timer_beta_dl.php?action=get_layouts')
        .then(function (r) { return r.json(); })
        .then(function (j) {
            if (!j || !j.ok || !j.layouts.length) return;
            var grp = document.createElement('optgroup');
            grp.label = 'Saved';
            j.layouts.forEach(function (L) {
                var o = document.createElement('option');
                o.value = 'id:' + L.id;
                o.textContent = L.name + (L.is_global ? ' (site)' : (L.league_name ? ' (' + L.league_name + ')' : ''));
                grp.appendChild(o);
            });
            sel.appendChild(grp);
            // Only reflect the choice in the control. Rendering it happens at
            // boot below, because this list never arrives on a screen that has
            // no picker — which is how a scanned display ended up showing the
            // built-in default instead of the layout the host chose.
            if (/^id:\d+$/.test(pick || '')) sel.value = pick;
        })
        .catch(function () {});
}

function applyPick(v) {
    if (/^id:\d+$/.test(v)) {
        fetch('/timer_beta_dl.php?action=get_layout&id=' + v.slice(3) + castKeyParam())
            .then(function (r) { return r.json(); })
            // Accept both stored shapes: single-screen {root} and the
            // normalized {screens:[…]} every editor save produces. The old
            // .root-only guard silently ignored all multi-screen layouts.
            .then(function (j) { if (j && j.ok && j.layout && (j.layout.root || j.layout.screens)) renderLayoutObj(j.layout); })
            .catch(function () {});
    } else {
        renderLayout(v);
    }
}

if (!/^id:\d+$/.test(pick || '') && !LAYOUTS[pick]) pick = DEFAULT_LAYOUT;
// First paint, independent of the picker: a saved layout is fetched by id (with
// the cast key when there is one), a built-in is rendered outright.
applyPick(pick);
if (sel) {
    if (LAYOUTS[pick]) sel.value = pick;
    sel.addEventListener('change', function () {
        manualOverride = true;   // a hands-on pick at the display wins over the event binding
        try { localStorage.setItem('tb_layout', sel.value); } catch (e) {}
        applyPick(sel.value);
    });
}

window.addEventListener('resize', scheduleRefit);
window.addEventListener('orientationchange', scheduleRefit);

// Keyboard control on a live, event-linked display: Space start/stop, Left/Right
// previous/next level. Only reaches the wire when controlArmed() (a session,
// the viewer can control it, a CSRF token, not the editor preview); the server
// re-checks manage rights on every command. Suppressed while typing.
document.addEventListener('keydown', function (e) {
    if (e.target.closest && e.target.closest('input, textarea, select, [contenteditable]')) return;
    if (!controlArmed()) return;
    if (e.code === 'Space') { e.preventDefault(); sendCommand('toggle_play'); }
    else if (e.code === 'ArrowRight') { e.preventDefault(); sendCommand('skip_next'); }
    else if (e.code === 'ArrowLeft')  { e.preventDefault(); sendCommand('skip_prev'); }
});

/* ── Keep the screen awake ────────────────────────────────────────────────
 * A tournament clock is watched, not touched, so the phone or tablet showing it
 * dims and sleeps mid-level. Both mechanisms, exactly as timer.js does it:
 *
 *   navigator.wakeLock  — the real API, but absent on iOS Safari over plain
 *                         HTTP, which is precisely how a LAN second screen is
 *                         opened from a QR code.
 *   NoSleep.js          — a hidden silent video; the only thing that works
 *                         there. Needs a genuine user gesture to start.
 *
 * So: try the API on load, and try BOTH again inside the first tap. The banner
 * says so, and removes itself the moment either one takes. */
var wakeLock = null, wakeHeld = false, noSleep = null, noSleepOn = false;
var wakeBanner = document.getElementById('tbWakeBanner');
try { if (typeof NoSleep !== 'undefined') noSleep = new NoSleep(); } catch (e) {}

function hideWakeBanner() {
    if (!wakeBanner) return;
    wakeBanner.style.opacity = '0';
    setTimeout(function () { if (wakeBanner) wakeBanner.remove(); wakeBanner = null; }, 600);
}
function requestWakeLock() {
    if (!('wakeLock' in navigator) || wakeHeld) return;
    try {
        navigator.wakeLock.request('screen').then(function (wl) {
            wakeLock = wl; wakeHeld = true; hideWakeBanner();
            wl.addEventListener('release', function () { wakeLock = null; wakeHeld = false; });
        }).catch(function () {});
    } catch (e) {}
}
function acquireWakeFromGesture() {
    requestWakeLock();                       // the gesture is captured at call time
    if (!noSleep || noSleepOn) return;
    var p;
    try { p = noSleep.enable(); } catch (e) { return; }
    if (p && typeof p.then === 'function') {
        p.then(function () { noSleepOn = true; hideWakeBanner(); }).catch(function () {});
    } else {
        noSleepOn = true; hideWakeBanner();  // older builds resolve synchronously
    }
}
// Nothing to prompt on a desktop: it does not sleep out from under a viewer,
// and a banner asking for a tap would just be noise on a projector feed.
if (!('ontouchstart' in window) && !navigator.maxTouchPoints) hideWakeBanner();
requestWakeLock();
document.addEventListener('click', acquireWakeFromGesture, true);
document.addEventListener('touchend', acquireWakeFromGesture, true);
document.addEventListener('visibilitychange', function () {
    if (document.visibilityState !== 'visible') return;
    wakeHeld = false;
    requestWakeLock();     // NoSleep needs a fresh gesture, so it is not restarted here
    poll();                // and resync at once — a hidden tab has throttled intervals
});

refreshDerived();
applyPick(LAYOUTS[pick] ? pick : DEFAULT_LAYOUT);
poll();
setInterval(tick, 500);
setInterval(poll, 2000);
}
})();
