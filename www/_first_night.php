<?php
/**
 * "Your first game night": the home page's four-step card for a host who has
 * not run a night yet. Each step is ticked from the database, not from clicks
 * (an event exists; it has a guest; a game's setup is saved; a timer has been
 * opened), the next step carries the button, and the card is gone for good
 * once all four are done or the host dismisses it. A help reset in My
 * Settings brings it back. The per-page help bubbles pick up on the pages
 * the card points to.
 *
 * Expects $user, $db and $canCreateEvents from index.php. Shown only to
 * someone who may create events, has not dismissed it, has a step left, and
 * is not merely a guest: an account that exists because it was invited
 * somewhere, with no events of its own, is not told to host.
 */
if (empty($user) || empty($canCreateEvents)) return;
$fnUid = (int)$user['id'];

$fnQ = $db->prepare("SELECT 1 FROM user_help_dismissed WHERE user_id = ? AND screen_key = 'first_night'");
$fnQ->execute([$fnUid]);
if ($fnQ->fetchColumn()) return;

$fnQ = $db->prepare('SELECT id, title, is_poker FROM events WHERE created_by = ? ORDER BY id DESC LIMIT 1');
$fnQ->execute([$fnUid]);
$fnEvent = $fnQ->fetch() ?: null;
if (!$fnEvent) {
    $fnQ = $db->prepare('SELECT 1 FROM event_invites WHERE user_id = ? LIMIT 1');
    $fnQ->execute([$fnUid]);
    if ($fnQ->fetchColumn()) return;   // a guest, not a host
}

$fnOne = function (string $sql) use ($db, $fnUid): bool {
    $s = $db->prepare($sql);
    $s->execute([$fnUid]);
    return (bool)$s->fetchColumn();
};
$fnDone = [
    'event'  => (bool)$fnEvent,
    'invite' => $fnEvent && $fnOne('SELECT 1 FROM event_invites i JOIN events e ON e.id = i.event_id WHERE e.created_by = ? LIMIT 1'),
    'setup'  => $fnEvent && $fnOne('SELECT 1 FROM poker_sessions ps JOIN events e ON e.id = ps.event_id WHERE e.created_by = ? AND ps.setup_saved = 1 LIMIT 1'),
    'timer'  => $fnEvent && $fnOne('SELECT 1 FROM timer_state ts JOIN poker_sessions ps ON ps.id = ts.session_id JOIN events e ON e.id = ps.event_id WHERE e.created_by = ? LIMIT 1'),
];
if (!in_array(false, $fnDone, true)) return;   // all four done: nothing left to say

$fnEid   = $fnEvent ? (int)$fnEvent['id'] : 0;
$fnTitle = $fnEvent ? (string)$fnEvent['title'] : '';
$fnSteps = [
    ['key' => 'event',  'title' => 'Create the event',
     'body' => $fnEvent ? 'Created: ' . $fnTitle : 'A title, a date and where. Poker is on to start, with the buy-in, tables and seats beside it.',
     'href' => $fnEvent ? '/event.php?id=' . $fnEid : '/event_edit.php', 'cta' => $fnEvent ? 'Open' : '+ Add Event'],
    ['key' => 'invite', 'title' => 'Invite your guests',
     'body' => 'A name and an email or phone each. Nobody needs an account; they answer from the link.',
     'href' => $fnEvent ? '/event_edit.php?id=' . $fnEid : '/event_edit.php', 'cta' => 'Add guests'],
    ['key' => 'setup',  'title' => 'Set up the game',
     'body' => 'Buy-in, chips, rebuys, blinds and payouts, under Manage Game and its Setup button.',
     'href' => $fnEvent ? '/checkin.php?event_id=' . $fnEid : '/event_edit.php', 'cta' => 'Set up game'],
    ['key' => 'timer',  'title' => 'Put the clock on the TV',
     'body' => 'The Tournament Timer runs the night from Manage Game; scan its QR code to add any phone or screen.',
     'href' => $fnEvent ? '/timer.php?event_id=' . $fnEid : '/event_edit.php', 'cta' => 'Open timer'],
];
$fnNext = null;
foreach ($fnSteps as $s) { if (!$fnDone[$s['key']]) { $fnNext = $s['key']; break; } }
?>
<style>
    .fn-card { background:#fff; border:1px solid #bfdbfe; border-radius:10px; padding:1rem 1.1rem 1rem; margin-bottom:1.25rem;
               box-shadow:0 1px 3px rgba(15,23,42,.05); }
    .fn-head { display:flex; align-items:flex-start; gap:.75rem; }
    .fn-head h2 { margin:0; font-size:1.15rem; color:#0f172a; }
    .fn-head p { margin:.2rem 0 0; font-size:.88rem; color:#64748b; }
    .fn-dismiss { margin:0 0 0 auto; }
    .fn-dismiss button { background:none; border:none; color:#94a3b8; cursor:pointer; font-size:1.3rem; line-height:1; padding:.1rem .3rem; }
    .fn-dismiss button:hover { color:#0f172a; }
    .fn-steps { list-style:none; margin:.9rem 0 0; padding:0; display:grid; grid-template-columns:repeat(4, minmax(0,1fr)); gap:.6rem; }
    .fn-step { display:flex; flex-direction:column; gap:.45rem; padding:.7rem .75rem; border:1px solid #e2e8f0; border-radius:8px; background:#f8fafc; }
    .fn-step.next { background:#eff6ff; border-color:#93c5fd; }
    .fn-step.done { background:#f0fdf4; border-color:#bbf7d0; }
    .fn-num { display:inline-flex; align-items:center; justify-content:center; width:24px; height:24px; border-radius:50%; flex-shrink:0;
              background:#e2e8f0; color:#475569; font-size:.8rem; font-weight:700; }
    .fn-step.next .fn-num { background:#2563eb; color:#fff; }
    .fn-step.done .fn-num { background:#16a34a; color:#fff; }
    .fn-step strong { display:block; font-size:.92rem; color:#0f172a; margin-top:.2rem; }
    .fn-step.done strong { color:#166534; }
    .fn-step span.fn-body { font-size:.8rem; color:#475569; line-height:1.45; flex:1; }
    .fn-step a.fn-cta { align-self:flex-start; font-size:.8rem; padding:.35rem .75rem; border-radius:6px; text-decoration:none; font-weight:600;
                        border:1.5px solid #cbd5e1; color:#334155; background:#fff; }
    .fn-step.next a.fn-cta { background:#2563eb; border-color:#2563eb; color:#fff; }
    .fn-step.done a.fn-cta { border-color:#bbf7d0; color:#166534; }
    .fn-foot { margin:.8rem 0 0; font-size:.8rem; color:#64748b; }
    @media (max-width: 760px) { .fn-steps { grid-template-columns:1fr 1fr; } }
    @media (max-width: 480px) { .fn-steps { grid-template-columns:1fr; } }
</style>
<section class="fn-card" id="firstNightCard" aria-label="Your first game night">
    <div class="fn-head">
        <div>
            <h2>&#127183; Your first game night</h2>
            <p>Four steps from here to cards in the air. Each one ticks itself off as you go.</p>
        </div>
        <form method="post" action="/" class="fn-dismiss">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES | ENT_SUBSTITUTE) ?>">
            <input type="hidden" name="action" value="dismiss_first_night">
            <button type="submit" aria-label="Dismiss this card" title="Dismiss">&times;</button>
        </form>
    </div>
    <ol class="fn-steps">
        <?php foreach ($fnSteps as $i => $s): $done = $fnDone[$s['key']]; $cls = $done ? 'done' : ($s['key'] === $fnNext ? 'next' : 'later'); ?>
        <li class="fn-step <?= $cls ?>" data-step="<?= htmlspecialchars($s['key'], ENT_QUOTES | ENT_SUBSTITUTE) ?>">
            <span class="fn-num" aria-hidden="true"><?= $done ? '&#10003;' : $i + 1 ?></span>
            <strong><?= htmlspecialchars($s['title'], ENT_QUOTES | ENT_SUBSTITUTE) ?></strong>
            <span class="fn-body"><?= htmlspecialchars($s['body'], ENT_QUOTES | ENT_SUBSTITUTE) ?></span>
            <a class="fn-cta" href="<?= htmlspecialchars($s['href'], ENT_QUOTES | ENT_SUBSTITUTE) ?>"><?= htmlspecialchars($s['cta'], ENT_QUOTES | ENT_SUBSTITUTE) ?></a>
        </li>
        <?php endforeach; ?>
    </ol>
    <p class="fn-foot">Stuck? The <a href="/help-hosts.php">Host Guide</a> walks through every step, and the <a href="/help-timer.php">Timer Guide</a> covers the clock.</p>
</section>
