<?php
/**
 * Send to TV, the host side. Reached by scanning the QR on tv.php (which
 * fills in the code) or from the check-in console (which picks the game).
 * The host enters the code showing on the TV and taps the game; the TV picks
 * the game's view-only display up on its next poll. See _tv_pair.php.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_poker_helpers.php';
require_once __DIR__ . '/_tv_pair.php';

$current = current_user();
if (!$current) { header('Location: /login.php?redirect=' . urlencode($_SERVER['REQUEST_URI'] ?? '/tv/send')); exit; }
$user = require_login();
$isAdmin = $current['role'] === 'admin';
$db = get_db();
$site_name = get_setting('site_name', 'Game Night');
$nav_active = '';

$error = '';
$code = tv_pair_clean_code((string)($_POST['code'] ?? $_GET['c'] ?? ''));
$raw_code = (string)($_POST['code'] ?? $_GET['c'] ?? '');
$pick = (int)($_POST['event_id'] ?? $_GET['event_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // A miss costs a guess; ten in ten minutes and this browser waits. A code
    // only ever buys showing a game you already run, so this is about noise.
    session_start_safe();
    $fails = array_filter($_SESSION['tv_send_fails'] ?? [], fn($t) => $t > time() - 600);
    $event_id = (int)($_POST['event_id'] ?? 0);
    if (!csrf_verify()) {
        $error = 'Your session expired. Try again.';
    } elseif (count($fails) >= 10) {
        $error = 'Too many codes that did not match. Wait a few minutes, then try the code on the TV again.';
    } elseif ($code === '') {
        $error = $raw_code === '' ? 'Enter the code showing on the TV.' : 'That is not a TV code. It is four letters and numbers, shown in large print on the TV.';
    } elseif (!$event_id || !can_manage_event($db, $event_id, (int)$current['id'], $isAdmin)) {
        $error = 'You can only send a game you run.';
    } else {
        $sq = $db->prepare("SELECT id, game_type FROM poker_sessions WHERE event_id = ?");
        $sq->execute([$event_id]);
        $sess = $sq->fetch();
        $pq = $db->prepare("SELECT id FROM tv_pairings WHERE code = ? AND target IS NULL AND expires_at >= datetime('now')");
        $pq->execute([$code]);
        $pair_id = (int)($pq->fetchColumn() ?: 0);
        if (!$sess || ($sess['game_type'] ?? '') === 'cash') {
            $error = 'That game has no tournament timer to show.';
        } elseif (!$pair_id) {
            $fails[] = time();
            $error = 'No TV is showing ' . $code . ' right now. Codes change every ' . TV_CODE_MINUTES . ' minutes, so check the TV and try again.';
        } else {
            $target = tv_pair_target_url($db, (int)$sess['id'], (int)$current['id']);
            $db->prepare("UPDATE tv_pairings SET session_id = ?, target = ?, paired_by = ?, paired_at = datetime('now') WHERE id = ? AND target IS NULL")
               ->execute([(int)$sess['id'], $target, (int)$current['id'], $pair_id]);
            db_log_activity((int)$current['id'], 'sent the timer for event #' . $event_id . ' to a TV');
            unset($_SESSION['tv_send_fails']);
            header('Location: /tv/send?sent=' . $event_id);
            exit;
        }
    }
    $_SESSION['tv_send_fails'] = array_values($fails);
}

// Games worth offering: tournaments dated yesterday (a night that ran past
// midnight) through the coming week, plus any game being played right now
// whatever its date. A past game nobody pressed Finish on is not one. The
// caller's own games only, even for an admin, whose full list would be every
// host's; an admin sends someone else's game from that game's console, which
// arrives here as ?event_id and is added below.
$from = date('Y-m-d', strtotime('-1 day'));
$to   = date('Y-m-d', strtotime('+7 days'));
$games = array_values(array_filter(
    user_poker_events($db, (int)$current['id'], false),
    fn($e) => ($e['game_type'] ?? 'tournament') !== 'cash'
        && (($e['session_status'] ?? '') === 'active'
            || ((string)$e['start_date'] >= $from && (string)$e['start_date'] <= $to))
));
// Playing now first, then the soonest date.
usort($games, fn($a, $b) => [($a['session_status'] ?? '') !== 'active', (string)$a['start_date'], (int)$a['id']]
                        <=> [($b['session_status'] ?? '') !== 'active', (string)$b['start_date'], (int)$b['id']]);
// The game the console sent us from goes first, listed even when the filter
// above left it out (an admin's, or one dated outside the window).
if ($pick && can_manage_event($db, $pick, (int)$current['id'], $isAdmin)) {
    $games = array_values(array_filter($games, fn($e) => (int)$e['id'] !== $pick));
    $pq = $db->prepare("SELECT e.id, e.title, e.start_date, ps.status AS session_status, ps.game_type
                        FROM events e JOIN poker_sessions ps ON ps.event_id = e.id WHERE e.id = ?");
    $pq->execute([$pick]);
    if (($prow = $pq->fetch()) && ($prow['game_type'] ?? '') !== 'cash') array_unshift($games, $prow);
}
$games = array_slice($games, 0, 12);

$sent_title = '';
if (!empty($_GET['sent'])) {
    $tq = $db->prepare('SELECT title FROM events WHERE id = ?');
    $tq->execute([(int)$_GET['sent']]);
    $sent_title = (string)($tq->fetchColumn() ?: '');
}
$tv_label = preg_replace('#^https?://#', '', rtrim(get_site_url(), '/')) . '/tv';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title>Send to TV &mdash; <?= htmlspecialchars($site_name, ENT_QUOTES | ENT_SUBSTITUTE) ?></title>
    <link rel="stylesheet" href="/style.css?v=<?= htmlspecialchars(APP_VERSION . '.' . (@filemtime(__DIR__ . '/style.css') ?: 0)) ?>">
    <style>
        .tvs { max-width: 520px; margin: 2rem auto 4rem; padding: 0 1rem; }
        .tvs h1 { font-size: 1.5rem; margin: 0 0 .4rem; }
        .tvs-lead { color: #475569; margin: 0 0 1.4rem; line-height: 1.45; }
        .tvs-code { width: 100%; box-sizing: border-box; font-size: 2rem; font-weight: 800; letter-spacing: .25em;
                    text-transform: uppercase; text-align: center; padding: .5rem; border: 2px solid #cbd5e1;
                    border-radius: 10px; font-variant-numeric: tabular-nums; }
        .tvs-code:focus { outline: none; border-color: #2563eb; }
        .tvs-label { display: block; font-weight: 600; font-size: .9rem; margin: 0 0 .4rem; }
        .tvs-games { display: flex; flex-direction: column; gap: .6rem; margin-top: 1.4rem; }
        .tvs-game { display: flex; align-items: center; justify-content: space-between; gap: .8rem; width: 100%;
                    text-align: left; background: #fff; border: 1.5px solid #e2e8f0; border-radius: 10px;
                    padding: .8rem 1rem; min-height: 56px; font: inherit; cursor: pointer; color: #0f172a; }
        .tvs-game:hover, .tvs-game:focus-visible { border-color: #2563eb; }
        .tvs-game:focus-visible { outline: 2px solid #2563eb; outline-offset: 2px; }
        .tvs-game .t { font-weight: 700; }
        .tvs-game .d { display: block; font-size: .82rem; color: #64748b; font-weight: 400; margin-top: .15rem; }
        .tvs-game .go { flex: none; background: #2563eb; color: #fff; border-radius: 8px; padding: .45rem .8rem; font-weight: 700; font-size: .9rem; }
        .tvs-live { color: #15803d; font-weight: 700; }
        .tvs-empty { color: #475569; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 1rem; }
        .tvs-help { margin-top: 2rem; font-size: .88rem; color: #475569; line-height: 1.5; }
    </style>
</head>
<body>
<?php require __DIR__ . '/_nav.php'; ?>
<main class="tvs">
    <h1>Send to TV</h1>
    <?php if ($sent_title !== ''): ?>
        <div class="alert alert-success" role="status"><strong>Sent.</strong> The TV switches to <?= htmlspecialchars($sent_title, ENT_QUOTES | ENT_SUBSTITUTE) ?> in a few seconds.</div>
        <p class="tvs-lead">Another TV? Open <strong><?= htmlspecialchars($tv_label, ENT_QUOTES | ENT_SUBSTITUTE) ?></strong> on it and scan its code.</p>
    <?php else: ?>
        <p class="tvs-lead">Put a game's timer on a TV. Open <strong><?= htmlspecialchars($tv_label, ENT_QUOTES | ENT_SUBSTITUTE) ?></strong> in the TV's browser, then enter the code it shows and pick the game.</p>
        <?php if ($error !== ''): ?>
            <div class="alert alert-error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE) ?></div>
        <?php endif; ?>
        <form method="post" action="/tv/send">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES | ENT_SUBSTITUTE) ?>">
            <label class="tvs-label" for="tvsCode">Code on the TV</label>
            <input class="tvs-code" id="tvsCode" name="code" maxlength="6" autocomplete="off" autocapitalize="characters"
                   spellcheck="false" inputmode="text" required
                   value="<?= htmlspecialchars($code !== '' ? $code : strtoupper(substr($raw_code, 0, 6)), ENT_QUOTES | ENT_SUBSTITUTE) ?>">
            <?php if ($games): ?>
                <div class="tvs-games" role="group" aria-label="Pick the game to show">
                    <?php foreach ($games as $g):
                        $live = ($g['session_status'] ?? '') === 'active';
                        $d = $g['start_date'] ? date('D, M j', strtotime($g['start_date'])) : ''; ?>
                        <button type="submit" class="tvs-game" name="event_id" value="<?= (int)$g['id'] ?>">
                            <span><span class="t"><?= htmlspecialchars($g['title'], ENT_QUOTES | ENT_SUBSTITUTE) ?></span>
                                <span class="d"><?= htmlspecialchars($d, ENT_QUOTES | ENT_SUBSTITUTE) ?><?php if ($live): ?> &middot; <span class="tvs-live">Playing now</span><?php endif; ?></span></span>
                            <span class="go">Show on TV</span>
                        </button>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="tvs-empty">You have no tournament today or in the coming week. Games you host, or help run, appear here once their poker game is set up. To send another host's game, use the <strong>TV</strong> button on that game's check-in dashboard.</p>
            <?php endif; ?>
        </form>
    <?php endif; ?>
    <p class="tvs-help">The TV gets the same view-only timer a guest gets by scanning the timer's QR code. It can't control the game. It works in any TV's web browser: Fire TV's Silk, Samsung and LG TVs, or a browser app on a Chromecast with Google TV. On the TV, press OK on the remote while its code is showing and the timer fills the screen when it arrives.</p>
</main>
<?php require __DIR__ . '/_footer.php'; ?>
</body>
</html>
