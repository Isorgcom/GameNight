<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_poker_helpers.php';   // pk_user_beta_pref(), for a game with no timer row yet

$current = require_login();
$db = get_db();
$isAdmin = $current['role'] === 'admin';

$event_id = (int)($_GET['event_id'] ?? 0);
if (!$event_id) { http_response_code(400); exit('Missing event_id'); }

// Verify event exists and user has access (creator, event-manager, league owner/manager, or site admin).
$evStmt = $db->prepare('SELECT * FROM events WHERE id = ?');
$evStmt->execute([$event_id]);
$event = $evStmt->fetch();
if (!$event) { http_response_code(404); exit('Event not found'); }
if (!can_manage_event($db, (int)$event_id, (int)$current['id'], $isAdmin)) {
    http_response_code(403); exit('Access denied');
}
// A cancelled event has no game to run; its page says so and offers Duplicate.
if (!empty($event['cancelled_at'])) {
    $_SESSION['flash'] = ['type' => 'error', 'msg' => EVENT_CANCELLED_MSG . ' The game cannot be run; duplicate the event to plan a new one.'];
    header('Location: /event.php?id=' . (int)$event_id); exit;
}

$site_name = get_setting('site_name', 'Game Night');
$csrf = csrf_token();

// Leagues this host may bind the event to, for the League select in Setup.
// Same rule the server enforces in update_config: owner/manager of that league,
// or site admin. The event's CURRENT league is always included even without a
// role on it, so a co-host can see where the game sits (and re-save) without
// being able to move it somewhere new.
$assignable_leagues = [];
$_curLeague = (int)($event['league_id'] ?? 0);
foreach (user_leagues((int)$current['id']) as $_lg) {
    if (in_array($_lg['role'], ['owner', 'manager'], true)) {
        $assignable_leagues[(int)$_lg['id']] = $_lg['name'];
    }
}
if ($isAdmin) {
    foreach ($db->query('SELECT id, name FROM leagues ORDER BY LOWER(name)') as $_lg) {
        $assignable_leagues[(int)$_lg['id']] = $_lg['name'];
    }
}
// Anyone holding a role on a league can also unbind the event (set None), so the
// picker is offered whenever they have at least one — even if that one is the
// league the event is already on.
$_canMoveLeague = $isAdmin || count($assignable_leagues) > 0;
// The current league is always listed, even without a role on it, so a co-host
// can see where the game sits. Marked so it doesn't read as a free choice.
if ($_curLeague && !isset($assignable_leagues[$_curLeague])) {
    $_lgName = $db->prepare('SELECT name FROM leagues WHERE id = ?');
    $_lgName->execute([$_curLeague]);
    $assignable_leagues[$_curLeague] = ($_lgName->fetchColumn() ?: 'League #' . $_curLeague) . ' (current)';
}

// Walk-in autocomplete suggestions. Admins see every site username; non-admins see only
// usernames they would already have access to via the event editor's invite picker:
// the event's league roster (if any) plus their personal contacts.
if ($isAdmin) {
    $allUsernames = array_column(
        $db->query('SELECT username FROM users ORDER BY username')->fetchAll(),
        'username'
    );
} else {
    $allUsernames = [];
    $_seen = [];
    $_uid = (int)$current['id'];
    $_evLeague = (int)($event['league_id'] ?? 0);
    if ($_evLeague > 0) {
        $st = $db->prepare(
            'SELECT u.username FROM league_members lm
             JOIN users u ON u.id = lm.user_id
             WHERE lm.league_id = ?'
        );
        $st->execute([$_evLeague]);
        foreach ($st->fetchAll() as $r) {
            $key = strtolower($r['username']);
            if (!isset($_seen[$key])) { $_seen[$key] = true; $allUsernames[] = $r['username']; }
        }
    }
    $st = $db->prepare(
        'SELECT u.username FROM user_contacts c
         JOIN users u ON u.id = c.linked_user_id
         WHERE c.owner_user_id = ? AND c.linked_user_id IS NOT NULL'
    );
    $st->execute([$_uid]);
    foreach ($st->fetchAll() as $r) {
        $key = strtolower($r['username']);
        if (!isset($_seen[$key])) { $_seen[$key] = true; $allUsernames[] = $r['username']; }
    }
    sort($allUsernames, SORT_FLAG_CASE | SORT_STRING);
}

// Check if session already exists
$sessStmt = $db->prepare('SELECT * FROM poker_sessions WHERE event_id = ?');
$sessStmt->execute([$event_id]);
$session = $sessStmt->fetch();

// Timer flags for the Setup → Timer tab: whether the Timer button opens the
// Tournament Timer (layout) display, and which layout it is bound to.
$use_beta_timer = 0;
$event_layout_id = null;
$event_layout_key = null;
$is_tournament_session = $session && (($session['game_type'] ?? '') === 'tournament');
if ($session) {
    try {
        $ubq = $db->prepare('SELECT use_beta, layout_id, layout_builtin FROM timer_state WHERE session_id = ?');
        $ubq->execute([(int)$session['id']]);
        $ubrow = $ubq->fetch();
        // No timer row yet: show what it will be born as (the viewer's default),
        // so the Setup box and the clock the Timer button opens agree.
        $use_beta_timer = $ubrow ? (int)($ubrow['use_beta'] ?? 0)
                                 : pk_user_beta_pref($db, $current ? (int)$current['id'] : 0);
        $event_layout_id = (int)($ubrow['layout_id'] ?? 0) ?: null;
        $event_layout_key = ($ubrow['layout_builtin'] ?? null) ?: null;
    } catch (Exception $e) {}
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Game — <?= htmlspecialchars($event['title']) ?> — <?= htmlspecialchars($site_name) ?></title>
    <link rel="stylesheet" href="/style.css?v=<?= htmlspecialchars(APP_VERSION . '.' . (@filemtime(__DIR__ . '/style.css') ?: 0)) ?>">
    <link rel="stylesheet" href="/event_setup.css?v=<?= htmlspecialchars(APP_VERSION . '.' . (@filemtime(__DIR__ . '/event_setup.css') ?: 0)) ?>">
    <script src="/event_blinds.js?v=<?= htmlspecialchars(APP_VERSION . '.' . (@filemtime(__DIR__ . '/event_blinds.js') ?: 0)) ?>" defer></script>
    <?php if ($is_tournament_session): ?>
    <link rel="stylesheet" href="/timer_layouts.css?v=<?= htmlspecialchars(APP_VERSION . '.' . (@filemtime(__DIR__ . '/timer_layouts.css') ?: 0)) ?>">
    <?php endif; ?>
    <link rel="stylesheet" href="/checkin.css?v=<?= htmlspecialchars(APP_VERSION . '.' . (@filemtime(__DIR__ . '/checkin.css') ?: 0)) ?>">
</head>
<body>

<?php $nav_active = ''; require __DIR__ . '/_nav.php'; ?>

<div role="main" id="app"></div>
<!-- Full-screen Game Settings editor lives OUTSIDE #app so dashboard
     re-renders and the 10s poll can never clobber an open editor. -->
<div id="settingsRoot"></div>

<?php /* One-time Tournament Timer ask. Three answers, which is one more than
         pkConfirm offers, so it uses this page's own modal pattern: yes and
         "keep classic" are final and stored; "Not now" is not an answer at
         all — it leaves the account unanswered and only quietens the ask in
         this browser for a while. */ ?>
<div class="pk-modal-overlay" id="betaTimerModal">
    <div class="pk-modal" role="dialog" aria-modal="true" aria-labelledby="betaTimerTitle">
        <h3 id="betaTimerTitle">&#9201; Try the Tournament Timer</h3>
        <p id="betaBannerMsg" style="font-size:.9rem;color:#475569;line-height:1.5;margin:.4rem 0 0">
            Designable layouts, break screens, phone views for players who scan the QR code, and sounds.
            Switch this game over to it and use it for new games too?
        </p>
        <p style="font-size:.8rem;color:#64748b;line-height:1.45;margin:.6rem 0 0">
            Any game can still go back to Classic in Setup, and you can change your mind any time in Settings.
        </p>
        <div class="pk-modal-actions">
            <button type="button" data-act="betaTimerLater">Not now</button>
            <button type="button" data-act="betaTimerAnswer" data-a1="0">Keep Classic</button>
            <button type="button" class="pk-save pk-beta-yes" data-act="betaTimerAnswer" data-a1="1">Use the Tournament Timer</button>
        </div>
    </div>
</div>

<!-- Notes modal -->
<div class="pk-modal-overlay" id="notesModal">
    <div class="pk-modal" role="dialog" aria-modal="true" aria-labelledby="notesTitle">
        <h3 id="notesTitle">Player Notes</h3>
        <textarea id="notesText" aria-label="Notes" placeholder="Notes about this player..."></textarea>
        <div class="pk-modal-actions">
            <button data-act="closeNotes">Cancel</button>
            <button class="pk-save" data-act="saveNotes">Save</button>
        </div>
    </div>
</div>

<!-- Save payout structure modal (opened from the settings editor, so it sits
     above the z-900 full-screen overlay) -->
<!-- League jackpot hit modal (funds display + per-recipient split) -->
<div class="pk-modal-overlay" id="jackpotModal" style="z-index:1000">
    <div class="pk-modal" role="dialog" aria-modal="true" aria-labelledby="jackpotTitle">
        <h3 id="jackpotTitle">💎 League Jackpot</h3>
        <div id="jpBalances" style="font-size:.9rem;color:#334155;margin-bottom:.75rem"></div>
        <label for="jpType" style="font-size:.85rem;font-weight:600;color:#475569;display:block;margin-bottom:.3rem">What hit?</label>
        <select id="jpType" style="width:100%;padding:.5rem;border:1.5px solid var(--border,#e2e8f0);border-radius:6px;font-size:1rem;box-sizing:border-box;margin-bottom:.6rem">
            <option value="badbeat">🃏 Bad Beat</option>
            <option value="royal">👑 Royal Flush</option>
            <option value="other">💎 Other jackpot</option>
        </select>
        <div id="jpPaidTo" style="font-size:.85rem;font-weight:600;color:#475569;display:block;margin-bottom:.3rem">Paid to</div>
        <div id="jpRecipients"></div>
        <button type="button" data-act="addJackpotRecipient" style="font-size:.78rem;padding:.3rem .6rem;border-radius:4px;cursor:pointer;border:1.5px solid var(--border,#e2e8f0);background:#f8fafc;margin-top:.2rem">+ Add recipient</button>

        <div style="margin-top:.9rem;padding-top:.7rem;border-top:1.5px solid #e2e8f0">
            <div style="display:flex;justify-content:space-between;align-items:center">
                <span style="font-size:.85rem;font-weight:600;color:#475569">Fund history</span>
                <button type="button" data-act="jpToggleAdjust" style="font-size:.72rem;padding:.25rem .55rem;border-radius:4px;cursor:pointer;border:1.5px solid var(--border,#e2e8f0);background:#f8fafc">± Adjust fund</button>
            </div>
            <div id="jpAdjustRow" style="display:none;margin-top:.5rem;gap:.4rem;align-items:center">
                <span class="pk-money-wrap" style="flex:0 0 90px"><input type="number" id="jpAdjAmount" step="0.01" placeholder="-5 or 5" aria-label="Adjustment amount"></span>
                <input type="text" id="jpAdjNote" maxlength="120" placeholder="reason…" aria-label="Reason for the adjustment" style="flex:1;padding:.45rem .5rem;border:1.5px solid var(--border,#e2e8f0);border-radius:6px;font-size:.85rem">
                <button type="button" class="pk-save" data-act="confirmJackpotAdjust" style="padding:.4rem .7rem;border-radius:6px;border:none;background:#2563eb;color:#fff;font-size:.8rem;font-weight:600;cursor:pointer">Apply</button>
            </div>
            <div id="jpHistory" style="margin-top:.5rem;max-height:180px;overflow-y:auto;scrollbar-gutter:stable;padding-right:.75rem;font-size:.78rem;color:#334155"></div>
        </div>

        <div class="pk-modal-actions">
            <button data-act="closeJackpotModal">Cancel</button>
            <button class="pk-save" data-act="confirmJackpotHit">Record Hit</button>
        </div>
    </div>
</div>

<!-- No click-outside dismiss: a mis-click must not eat a half-typed name. -->
<div class="pk-modal-overlay" id="saveStructModal" style="z-index:1000">
    <div class="pk-modal" role="dialog" aria-modal="true" aria-labelledby="saveStructTitle">
        <h3 id="saveStructTitle">Save Payout Structure</h3>
        <label for="ssName" style="font-size:.85rem;font-weight:600;color:#475569;display:block;margin-bottom:.3rem">Name</label>
        <input type="text" id="ssName" maxlength="60" placeholder="e.g. Friday night 4-way" style="width:100%;padding:.5rem;border:1.5px solid var(--border,#e2e8f0);border-radius:6px;font-size:1rem;box-sizing:border-box">
        <label for="ssScope" style="font-size:.85rem;font-weight:600;color:#475569;display:block;margin:.75rem 0 .3rem">Save to</label>
        <select id="ssScope" style="width:100%;padding:.5rem;border:1.5px solid var(--border,#e2e8f0);border-radius:6px;font-size:1rem;box-sizing:border-box"></select>
        <div class="pk-modal-actions">
            <button data-act="closeSaveStruct">Cancel</button>
            <button class="pk-save" data-act="confirmSaveStruct">Save</button>
        </div>
    </div>
</div>

<!-- Cash-in adjust modal -->
<div class="pk-modal-overlay" id="cashAdjustModal" data-act-self="closeCashAdjust">
    <div class="pk-modal" role="dialog" aria-modal="true" aria-labelledby="cashAdjustTitle">
        <h3 id="cashAdjustTitle">Add Money</h3>
        <label for="cashAdjustAmount" style="font-size:.85rem;font-weight:600;color:#475569;display:block;margin-bottom:.3rem">Amount ($)</label>
        <input type="number" id="cashAdjustAmount" step="0.01" min="0" style="width:100%;padding:.5rem;border:1.5px solid var(--border,#e2e8f0);border-radius:6px;font-size:1rem;box-sizing:border-box">
        <div class="pk-modal-actions">
            <button data-act="closeCashAdjust">Cancel</button>
            <button class="pk-save" id="cashAdjustOk" data-act="applyCashAdjust">Add</button>
        </div>
    </div>
</div>

<!-- Ledger modal: per-player money history + corrections -->
<div class="pk-modal-overlay" id="ledgerModal" data-act-self="closeLedger">
    <div class="pk-modal pk-log-modal" role="dialog" aria-modal="true" aria-labelledby="ledgerTitle">
        <h3 id="ledgerTitle">Ledger</h3>
        <div class="pk-log-sub">Every buy-in, add-on and cash-out for this player. Tap <b>Edit</b> to fix a wrong amount in place, or <b>Clear</b> to reverse an entry.</div>
        <div class="pk-ledger-list" id="ledgerList"><div class="pk-ledger-empty">Loading&hellip;</div></div>
        <div class="pk-modal-actions">
            <button class="pk-save" data-act="closeLedger">Close</button>
        </div>
    </div>
</div>

<!-- Cash box reconciliation modal (cash games) -->
<div class="pk-modal-overlay" id="cashBoxModal" data-act-self="closeCashBox">
    <div class="pk-modal" role="dialog" aria-modal="true" aria-labelledby="cashBoxTitle">
        <h3 id="cashBoxTitle">&#129534; Cash Box</h3>
        <div class="pk-log-sub">Record tips and square the cash box at the end of the game.</div>
        <div class="pk-cb-grid">
            <div class="pk-cb-row"><span>Total bought in</span><span id="cbCashIn">$0</span></div>
            <div class="pk-cb-row"><span>Total cashed out</span><span id="cbCashOut">$0</span></div>
            <div class="pk-cb-row"><span>Still on table (owed)</span><span id="cbOnTable">$0</span></div>
            <div class="pk-cb-row pk-cb-input-row"><label for="cbTips">Tips (host)</label><span class="pk-cb-money">$<input type="number" inputmode="decimal" step="0.01" min="0" id="cbTips" data-act-input="cashBoxRecompute" placeholder="0.00"></span></div>
            <div class="pk-cb-row pk-cb-sub"><span>Expected in box</span><span id="cbExpected">$0</span></div>
            <div class="pk-cb-row pk-cb-input-row"><label for="cbCounted">Counted in box</label><span class="pk-cb-money">$<input type="number" inputmode="decimal" step="0.01" min="0" id="cbCounted" data-act-input="cashBoxRecompute" placeholder="count it"></span></div>
            <div class="pk-cb-row pk-cb-total"><span>Over / Short</span><span id="cbOverShort" class="pk-cb-os">&mdash;</span></div>
            <button type="button" id="cbTipSurplus" class="pk-cb-tipbtn" data-act="cashBoxTipSurplus" style="display:none">Record the surplus as tips</button>
        </div>
        <div class="pk-modal-actions">
            <button data-act="closeCashBox">Cancel</button>
            <button class="pk-save" data-act="saveCashBox">Save</button>
        </div>
    </div>
</div>

<!-- Help modal -->
<div class="pk-modal-overlay" id="helpModal" data-act-self="closeHelp">
    <div class="pk-modal" role="dialog" aria-modal="true" aria-labelledby="helpTitle">
        <h3 id="helpTitle">How this screen works</h3>
        <div class="pk-help-content" tabindex="-1">
            <?php /* Ordered the way a night goes. The .help-tourney and .help-cash
                     groups are shown or hidden by openHelp() for the game type,
                     so a cash host is not reading about eliminations. */ ?>
            <h4 class="pk-cfg-title" style="margin:0 0 .2rem">Before the game</h4>
            <h5>Setup</h5>
            <p>Do this first. <b>&#9881; Setup</b> holds the buy-in and the tables and seats; for a tournament also the starting chips, rebuy and add-on rules, the payout structure, any bounties, tickets or jackpot, and the <b>Blinds</b> and <b>Timer</b> tabs for the clock. Until it is saved, buy-ins and prizes will not add up.</p>
            <h5>Who is on the list</h5>
            <p>Everyone who answered Yes to the invitation is already here. For anyone who turns up unannounced, type their name in the <b>Walk-in name</b> box and press <b>+ Add</b>.</p>
            <h5>Approve / Deny</h5>
            <p>These appear only for players who put themselves on the list through the QR code or self-signup. <b>Approve</b> puts them on the roster so you can buy them in; <b>Deny</b> turns them away. If you add everyone yourself, you will never see these buttons.</p>
            <h4 class="pk-cfg-title" style="margin:.9rem 0 .2rem">During the game</h4>
            <div class="help-tourney">
            <h5>Buy In</h5>
            <p>Tick the box when a player pays. It also checks them in and gives them a seat; there is no separate check-in step. The &#128210; beside the box is their ledger: every buy-in, rebuy and add-on, with <b>Edit</b> and <b>Clear</b> for mistakes.</p>
            <h5>Rebuys &amp; Add-ons</h5>
            <p>When the game allows them, the + / &minus; counters on a player's row track each one. The prize pool at the top right follows. If the entry fee covers rebuys or add-ons (Setup &rarr; <b>Free with the buy-in</b>), a player's first ones are recorded at $0 and only those beyond count toward the pool. <b>Free rebuy and free add-on: One stack</b> means a player gets one or the other: a rebuy before the break, or the add-on at the break; a player who took the rebuy pays for the add-on. <b>No rebuys after level</b> closes rebuys once the clock is past that level: the + still works but asks first, and the ledger says the rebuy came after the cutoff. At the break, <b>Add-on for everyone still in</b> (above the list on a phone; on a desktop select the players and press <b>Add-on</b> in the bar) records one for each player who has none yet.</p>
            <h5>Eliminate</h5>
            <p>Marks a player out. Their finishing place is filled in by elimination order (9th, 8th &hellip; down to 1st), and if that place is in the money the prize owed shows next to their name, so eliminate players in the order they bust. <b>Undo</b> puts them back. The last player standing wins, and that finishes the game.</p>
            </div>
            <div class="help-cash">
            <h5>Cash In / Cash Out</h5>
            <p>Type what a player buys in for and press Enter; <b>+</b> adds a top-up. When they leave, type what they take with them and press the green check; clear the field to put them back in play. <b>Profit</b> does the arithmetic. The &#128210; beside a name is their ledger, with <b>Edit</b> and <b>Clear</b> for mistakes.</p>
            <h5>Cash Box</h5>
            <p>At the end, <b>Cash Box</b> in the header records tips and squares the box against what came in and went out.</p>
            </div>
            <h5>Remove</h5>
            <p>Takes someone off the list who is not playing: a no-show, a duplicate. It is not an elimination; a player who busted gets <b>Eliminate</b>.</p>
            <h5>Notes</h5>
            <p>A note on a player for tonight, for you and your managers.</p>
            <h5>Several at once</h5>
            <p>Tick the boxes at the left of the rows and the bar above the list acts on all of them: Buy In, Eliminate, Approve, Remove.</p>
            <h5>Filters and sorting</h5>
            <p><b>All</b>, <b>RSVP Yes</b>, <b>Playing</b> and <b>Out</b> narrow the list; click a column heading to sort by it. Guests who said No are folded under a <b>said no</b> line at the foot of All and are not counted as players; <b>Show</b> brings them back, and one who turns up and buys in counts like anyone.</p>
            <h4 class="pk-cfg-title" style="margin:.9rem 0 .2rem">The views</h4>
            <h5>List, Table, Log<span class="help-tourney">, Payouts, Chop</span></h5>
            <p><b>List</b> is this roster. <b>Table</b> shows who sits where: <b>Move&hellip;</b> moves a player, <b>Add Table</b> when the room outgrows one, <b>Balance</b> evens the tables out, <b>Break Up</b> closes a table and spreads its players. <b>Log</b> is every money and roster change, newest first, with Edit and Clear.<span class="help-tourney"> <b>Payouts</b> is the prize ladder and who is owed what as the places fill. <b>Chop</b> is the deal calculator for when the last players agree to split, by chip counts.</span></p>
            <h4 class="pk-cfg-title" style="margin:.9rem 0 .2rem">Also in the header</h4>
            <div class="help-tourney">
            <h5>Timer</h5>
            <p>The tournament clock for the big screen. Its own guide is under Help.</p>
            <h5>TV</h5>
            <p>Puts this game's clock on a TV that has a web browser. Open <b>/tv</b> on the TV; it shows a code. This button opens the page to type that code into, with this game at the top. Scanning the TV's QR code with your phone gets you to the same page.</p>
            </div>
            <h5>Table Mgr</h5>
            <p>A phone-sized console for this same game: buy-ins, knockouts and the clock within one thumb, for walking the room.</p>
            <h5>QR</h5>
            <p>A page to leave on a tablet at the door. Guests scan it to put themselves on the list, and arrive here as <b>Approve / Deny</b>.</p>
            <h5>Finish</h5>
            <p>Locks in the results<span class="help-tourney">: the standings and payouts, and any entry tickets go out</span><span class="help-cash">: every player's cash-out</span>. It warns first if something is not ready. <b>Reopen</b> puts the game back if something was wrong.</p>
            <h5>From the keyboard</h5>
            <p>Tab reaches every control. The arrow keys move along the view strip, the filters and Setup's tabs; Enter opens a card's &#9662; on a phone; Escape closes a dialog, a menu or Setup; the column headings sort on Enter; and the row menu in Setup &rarr; Blinds opens with Shift+F10.</p>
        </div>
        <div class="pk-modal-actions">
            <button class="pk-save" data-act="closeHelp">Got it</button>
        </div>
    </div>
</div>

<!-- Eliminate confirmation modal -->
<div class="pk-modal-overlay" id="elimModal" data-act-self="closeElim">
    <div class="pk-modal" role="dialog" aria-modal="true" aria-labelledby="elimTitle">
        <h3 id="elimTitle">Eliminate Player</h3>
        <p id="elimMsg" style="font-size:.9rem;color:#475569;line-height:1.45;margin:0"></p>
        <div class="pk-modal-actions">
            <button data-act="closeElim">Cancel</button>
            <button class="pk-save" style="background:#dc2626" data-act="confirmElim">Eliminate</button>
        </div>
    </div>
</div>

<!-- Winner / game-over modal -->
<div class="pk-modal-overlay" id="winnerModal" data-act-self="closeWinner">
    <div class="pk-modal" role="dialog" aria-modal="true" aria-labelledby="winnerMsg" style="text-align:center">
        <div style="font-size:2.75rem;line-height:1">🏆</div>
        <h3 id="winnerMsg" style="margin:.4rem 0 .25rem"></h3>
        <p id="winnerSub" style="font-size:.9rem;color:#475569;line-height:1.45;margin:0"></p>
        <div class="pk-modal-actions" style="justify-content:center">
            <button class="pk-save" data-act="closeWinner">Nice!</button>
        </div>
    </div>
</div>

<?php if ($is_tournament_session): ?>
<!-- Setup → Timer: the layout chooser + full layout editor, embedded. Rendered
     ONCE out here (not inside a settings pane) because the editor's preview
     iframe would reload on every pane re-render — panes are rebuilt via
     innerHTML and moving an iframe in the DOM reloads it. syncDisplayHome()
     reveals this block only while the Timer tab is active. -->
<div id="ckDisplayHome" hidden>
    <?php require __DIR__ . '/_timer_layouts_editor.php'; ?>
</div>
<script src="/pk-payouts.js?v=<?= htmlspecialchars(APP_VERSION . '.' . (@filemtime(__DIR__ . '/pk-payouts.js') ?: 0)) ?>"></script>
<script nonce="<?= csp_nonce() ?>">
var ES_CSRF = <?= js_json($csrf) ?>;
var ES_EVENT_ID = <?= (int)$event_id ?>;
var ES_EVENT_NAME = <?= js_json((string)$event['title']) ?>;
var ES_LAYOUT_ID = <?= js_json($event_layout_id) ?>;
var ES_LAYOUT_KEY = <?= js_json($event_layout_key) ?>;
</script>
<?php endif; ?>

<?php require __DIR__ . '/_footer.php'; ?>

<script nonce="<?= csp_nonce() ?>">
var CSRF = <?= js_json($csrf, JSON_HEX_TAG) ?>;
var USE_BETA_TIMER = <?= (int)$use_beta_timer ?>;
<?php
// The league's known-as names by lower-cased screen name (v1.13.0), for the
// walk-in suggestions; the roster rows carry their own from get_players().
$knownByName = [];
if (!empty($event['league_id'])) {
    try {
        $kq = $db->prepare("SELECT LOWER(u.username) AS n, lm.known_as FROM league_members lm JOIN users u ON u.id = lm.user_id WHERE lm.league_id = ? AND lm.known_as IS NOT NULL AND lm.known_as <> ''");
        $kq->execute([(int)$event['league_id']]);
        foreach ($kq->fetchAll() as $r) { if (strcasecmp((string)$r['known_as'], (string)$r['n']) !== 0) $knownByName[$r['n']] = (string)$r['known_as']; }
    } catch (Throwable $e) {}
}
?>
var KNOWN_AS = <?= js_json($knownByName, JSON_FORCE_OBJECT) ?>;
<?php /* One-time Tournament Timer ask: any tournament game whose setup has been
         saved, while the user has never answered (users.beta_timer IS NULL).
         Not before setup: a host opening a brand-new game met this on top of
         the "set up game" prompt, two first-time asks at once, and the timer
         question has no meaning until there is a game to time. The copy
         adapts — a game already on the Tournament Timer asks about making it
         the DEFAULT, since "switch this game" would be incoherent there. Any
         answer is stored; it is never asked twice. */ ?>
var BETA_TIMER_ASK = <?= ($is_tournament_session
                          && !empty($session['setup_saved'])
                          // The Tournament Timer is the default now, so the only
                          // host with a question to answer is one whose game is
                          // still on Classic.
                          && !$use_beta_timer
                          && array_key_exists('beta_timer', $current) && $current['beta_timer'] === null) ? 1 : 0 ?>;
var ALL_USERS = <?= js_json($allUsernames, JSON_HEX_TAG) ?>;
var EVENT_ID = <?= $event_id ?>;
var EVENT_LEAGUE_ID = <?= (int)($event['league_id'] ?? 0) ?>;
var ASSIGNABLE_LEAGUES = <?= js_json(array_map(
        function ($id, $name) { return ['id' => (int)$id, 'name' => $name]; },
        array_keys($assignable_leagues), array_values($assignable_leagues)
    ), JSON_HEX_TAG) ?>;
// A co-host with no league role anywhere can see the binding but must not be
// offered a change — the server refuses it, so don't present it as possible.
var CAN_MOVE_LEAGUE = <?= $_canMoveLeague ? 'true' : 'false' ?>;
var IS_ADMIN = <?= $isAdmin ? 'true' : 'false' ?>;
// Needed to tell "my preset" from "someone else's" without a round-trip; the
// preset menu greys out what the server would refuse, and says why.
var CURRENT_USER_ID = <?= (int)$current['id'] ?>;
var SESSION = <?= $session ? json_encode($session, JSON_HEX_TAG) : 'null' ?>;
var CHECKIN_EVENT_TITLE = <?= js_json((string)$event['title'], JSON_HEX_TAG) ?>;
var CHECKIN_EVENT_ROW_ID = <?= (int)$event['id'] ?>;
</script>
<script src="/checkin.js?v=<?= htmlspecialchars(APP_VERSION . '.' . (@filemtime(__DIR__ . '/checkin.js') ?: 0)) ?>"></script>

<!-- The deal-split calculator is the "Chop" view now (renderChopView), not a modal. -->

</body>
</html>
