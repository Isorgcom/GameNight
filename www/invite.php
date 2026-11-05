<?php
/**
 * The shared invite link: /invite.php?e=<event id>&t=<share token>.
 *
 * Public, no login. A host posts it in a group chat, AirDrops it, or shows
 * its QR (event.php → Share invite). Whoever opens it sees the game (title,
 * when, the venue's NAME, the host) and puts themselves on the list with a
 * name and an email or phone, or with one tap when signed in. The street
 * address is not shown here: the link can be forwarded to anyone, so the
 * address reaches a guest once they are on the list (their own event link,
 * in the confirmation or the approval notice).
 *
 * The join rules are shared with the walk-up QR (_public_join.php); unlike
 * the walk-in, nobody is seated from here. Token: events.share_token, its own
 * secret, separate from walkin_token, '' when the host turned the link off.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_notifications.php';
require_once __DIR__ . '/_public_join.php';

session_start_safe();

const SHARE_JOINS_PER_IP_PER_HOUR = 10;
const SHARE_JOINS_PER_EVENT_PER_DAY = 100;

$db        = get_db();
$site_name = get_setting('site_name', 'Game Night');
$event_id  = (int)($_GET['e'] ?? 0);
$token     = trim((string)($_GET['t'] ?? ''));
$viewer    = current_user();

// ── Which page this is ─────────────────────────────────────────────────────────
$event = null;
$state = 'inactive';   // inactive | cancelled | past | open
if ($event_id > 0 && $token !== '') {
    $s = $db->prepare('SELECT e.id, e.title, e.description, e.start_date, e.start_time, e.end_time, e.venue_name,
                              e.created_by, e.cancelled_at, e.share_token, e.is_poker, e.league_id,
                              u.username AS host_name, ps.game_type
                       FROM events e
                       LEFT JOIN users u ON u.id = e.created_by
                       LEFT JOIN poker_sessions ps ON ps.event_id = e.id
                       WHERE e.id = ?');
    $s->execute([$event_id]);
    $row = $s->fetch();
    if ($row && (string)$row['share_token'] !== '' && hash_equals((string)$row['share_token'], $token)) {
        $event = $row;
        $today = (new DateTime('now', new DateTimeZone(get_setting('timezone', 'UTC'))))->format('Y-m-d');
        if (!empty($row['cancelled_at']))                  $state = 'cancelled';
        elseif ((string)$row['start_date'] < $today)       $state = 'past';
        else                                               $state = 'open';
    }
}
$selfPath = '/invite.php?e=' . $event_id . '&t=' . urlencode($token);

function invite_when(array $ev, ?array $viewer): string {
    $uid = !empty($viewer['id']) ? (int)$viewer['id'] : ((int)($ev['created_by'] ?? 0) ?: null);
    $lbl = event_public_time_labels((string)$ev['start_date'], $ev['start_time'] ?: null, $ev['end_time'] ?: null, $uid);
    return $lbl['date_lbl'] . ($lbl['time_lbl'] !== '' ? ' · ' . html_entity_decode($lbl['time_lbl'], ENT_QUOTES | ENT_HTML5, 'UTF-8') : '');
}

function share_rate_limited(PDO $db, int $eid): string {
    $db->prepare("DELETE FROM share_attempts WHERE created_at < datetime('now', '-1 day')")->execute();
    $ip = get_client_ip();
    $a = $db->prepare("SELECT COUNT(*) FROM share_attempts WHERE event_id = ? AND ip = ? AND created_at > datetime('now', '-1 hour')");
    $a->execute([$eid, $ip]);
    if ((int)$a->fetchColumn() >= SHARE_JOINS_PER_IP_PER_HOUR) return 'Too many sign-ups from this connection. Please try again in an hour.';
    $b = $db->prepare("SELECT COUNT(*) FROM share_attempts WHERE event_id = ? AND created_at > datetime('now', '-1 day')");
    $b->execute([$eid]);
    if ((int)$b->fetchColumn() >= SHARE_JOINS_PER_EVENT_PER_DAY) return 'This invite link has had a lot of sign-ups today. Please ask the host to add you.';
    return '';
}

// ── Seats, and whether the viewer is already on the list ──────────────────────
$cap = $state === 'open' ? event_capacity($db, $event_id) : ['capacity' => 0, 'filled' => 0, 'waitlist' => true];
$seatsLeft = $cap['capacity'] > 0 ? max(0, $cap['capacity'] - $cap['filled']) : null;
$isFull    = $seatsLeft === 0;
$needsOk   = $state === 'open' && share_needs_approval($db, $event_id);

$mine = null;
if ($state === 'open' && $viewer) {
    $mine = public_join_existing_row($db, $event_id, (int)$viewer['id']);
}

// ── POST ──────────────────────────────────────────────────────────────────────
$error  = '';
$result = null;   // public_join_* answer
if ($state === 'open' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error = 'Invalid request. Please refresh and try again.';
    } elseif (($lim = share_rate_limited($db, $event_id)) !== '') {
        $error = $lim;
    } elseif ($viewer && ($_POST['as_me'] ?? '') === '1') {
        $db->prepare('INSERT INTO share_attempts (event_id, ip) VALUES (?, ?)')->execute([$event_id, get_client_ip()]);
        $result = public_join_account($db, $event, $viewer, 'share');
    } else {
        $in = public_join_parse($_POST);
        if ($in['error'] !== '') {
            $error = $in['error'];
        } else {
            $db->prepare('INSERT INTO share_attempts (event_id, ip) VALUES (?, ?)')->execute([$event_id, get_client_ip()]);
            $result = public_join_event($db, $event, $in, 'share');
            if ($result['status'] === 'error') { $error = $result['error']; $result = null; }
        }
    }
    if ($result && $result['status'] === 'full') {
        $error = 'Sorry, this game just filled up.';
        $result = null;
    }
}

$csrf = csrf_token();
$when = $event ? invite_when($event, $viewer) : '';
$gameLbl = '';
if ($event && (int)$event['is_poker'] === 1) {
    $gameLbl = ($event['game_type'] ?? '') === 'cash' ? 'Cash game' : 'Poker tournament';
}

// Link-preview card: the league's banner when the event has one.
$previewImg = null;
if ($event && !empty($event['league_id'])) {
    $b = $db->prepare('SELECT banner_path FROM leagues WHERE id = ?');
    $b->execute([(int)$event['league_id']]);
    $bp = (string)($b->fetchColumn() ?: '');
    if ($bp !== '') $previewImg = get_site_url() . $bp;
}
$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= $event ? $h($event['title']) . ' – ' : 'Invite – ' ?><?= $h($site_name) ?></title>
    <?php if ($event && $state === 'open'):
        $short = $event['start_time']
            ? date('D M j', strtotime($event['start_date'])) . ', ' . date('g:i A', strtotime($event['start_date'] . ' ' . $event['start_time']))
            : date('D M j', strtotime($event['start_date']));
        $desc = 'You\'re invited' . (!empty($event['host_name']) ? ' by ' . $event['host_name'] : '')
              . (!empty($event['venue_name']) ? ' · ' . $event['venue_name'] : '') . '. Tap to save your seat.';
        render_seo_meta($event['title'] . ' · ' . $short, $desc, ltrim($selfPath, '/'), $previewImg);
    endif; ?>
    <link rel="stylesheet" href="/style.css?v=<?= $h(APP_VERSION . '.' . (@filemtime(__DIR__ . '/style.css') ?: 0)) ?>">
    <style>
        body { background: var(--bg, #f8fafc); }
        .iv-wrap { min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 1.5rem 1rem; box-sizing: border-box; }
        .iv-card { background: #fff; border-radius: 14px; box-shadow: 0 8px 32px rgba(0,0,0,.12); padding: 1.75rem 1.5rem 1.5rem; width: 100%; max-width: 440px; box-sizing: border-box; }
        .iv-site { text-align: center; font-size: .85rem; color: #64748b; margin-bottom: 1rem; }
        .iv-site img { max-height: 48px; }
        .iv-kicker { font-size: .78rem; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: #2563eb; margin: 0 0 .3rem; }
        .iv-title { font-size: 1.35rem; font-weight: 800; color: #0f172a; margin: 0 0 .6rem; line-height: 1.25; overflow-wrap: anywhere; }
        .iv-meta { list-style: none; margin: 0 0 1rem; padding: 0; font-size: .92rem; color: #334155; }
        .iv-meta li { padding: .18rem 0; }
        .iv-desc { font-size: .9rem; color: #475569; line-height: 1.55; margin: 0 0 1rem; overflow-wrap: anywhere; }
        .iv-seats { font-size: .88rem; font-weight: 600; border-radius: 8px; padding: .5rem .75rem; margin-bottom: 1.1rem; }
        .iv-seats.open { background: #f0fdf4; color: #166534; }
        .iv-seats.full { background: #fffbeb; color: #92400e; border: 1px solid #f59e0b; }
        .iv-note { font-size: .82rem; color: #64748b; margin: 0 0 .8rem; }
        .iv-ok { background: #f0fdf4; border: 1.5px solid #86efac; border-radius: 8px; padding: 1rem 1.1rem; color: #166534; line-height: 1.5; margin-bottom: 1rem; }
        .iv-wait { background: #fffbeb; border: 1.5px solid #f59e0b; border-radius: 8px; padding: 1rem 1.1rem; color: #92400e; line-height: 1.5; margin-bottom: 1rem; }
        .iv-err { background: #fef2f2; border: 1.5px solid #fca5a5; border-radius: 8px; padding: .75rem 1rem; color: #991b1b; font-size: .88rem; margin-bottom: 1rem; }
        .iv-gone { text-align: center; color: #475569; padding: 1rem 0 .25rem; }
        .iv-gone h1 { font-size: 1.2rem; color: #0f172a; margin: 0 0 .5rem; }
        .iv-btn { width: 100%; padding: .75rem; font-size: 1rem; margin-top: .4rem; }
        .iv-alt { text-align: center; font-size: .85rem; color: #64748b; margin: 1rem 0 0; }
        .sr-only { position:absolute;width:1px;height:1px;margin:-1px;padding:0;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap;border:0; }
        .iv-verify { margin-top: 1rem; padding: 1rem; background: #f8fafc; border: 1.5px solid #e2e8f0; border-radius: 10px; font-size: .88rem; color: #334155; line-height: 1.5; }
    </style>
</head>
<body>
<main class="iv-wrap">
    <div class="iv-card">
        <?php $logo = get_setting('logo_path', ''); ?>
        <div class="iv-site"><?php if ($logo): ?><img src="<?= $h($logo) ?>" alt="<?= $h($site_name) ?>"><?php else: ?><?= $h($site_name) ?><?php endif; ?></div>

        <?php if ($state !== 'open'): ?>
        <div class="iv-gone">
            <?php if ($state === 'cancelled'): ?>
            <h1>This game has been called off</h1>
            <p><?= $h($event['title']) ?> was cancelled by the host, so there is nothing to sign up for.</p>
            <?php elseif ($state === 'past'): ?>
            <h1>This game has already happened</h1>
            <p><?= $h($event['title']) ?> was on <?= $h($when) ?>. Ask the host about the next one.</p>
            <?php else: ?>
            <h1>This invite link is no longer active</h1>
            <p>The host may have turned it off or made a new one. Ask them for the current link.</p>
            <?php endif; ?>
        </div>

        <?php else: ?>
        <p class="iv-kicker">You're invited</p>
        <h1 class="iv-title"><?= $h($event['title']) ?></h1>
        <ul class="iv-meta">
            <li>&#128197; <?= $h($when) ?></li>
            <?php if (!empty($event['venue_name'])): ?><li>&#128205; <?= $h($event['venue_name']) ?></li><?php endif; ?>
            <?php if ($gameLbl !== ''): ?><li>&#9824;&#65039; <?= $h($gameLbl) ?></li><?php endif; ?>
            <?php if (!empty($event['host_name'])): ?><li>&#128100; Hosted by <?= $h($event['host_name']) ?></li><?php endif; ?>
        </ul>

        <?php if ($result): ?>
            <?php $st = $result['status']; ?>
            <?php if ($st === 'approved'): ?>
            <div class="iv-ok" role="status"><strong>You're on the list.</strong> We've sent you the details, including where it is.</div>
            <?php elseif ($st === 'already'): ?>
            <div class="iv-ok" role="status"><strong>You're already on the list</strong> for this one.</div>
            <?php elseif ($st === 'waitlisted'): ?>
            <div class="iv-wait" role="status"><strong>It's full, so you're on the waiting list.</strong> We'll tell you if a seat opens up.</div>
            <?php else: ?>
            <div class="iv-wait" role="status"><strong>Request sent.</strong> The host will let you know once you're in, with the address.</div>
            <?php endif; ?>
            <?php if (($result['verify'] ?? '') === 'phone_inline'): ?>
            <div class="iv-verify">
                We texted a 6-digit code to <strong><?= $h($in['phone'] ?? '') ?></strong> so you can sign in later. You can skip it; your spot does not depend on it.
                <form method="post" action="/verify_phone.php" style="display:flex;gap:.5rem;margin-top:.6rem;flex-wrap:wrap">
                    <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
                    <label for="iv_code" class="sr-only">Code</label>
                    <input type="text" id="iv_code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="\d{6}" required placeholder="123456"
                           style="flex:1;min-width:130px;padding:.55rem .75rem;border:1.5px solid #cbd5e1;border-radius:6px;font-size:1.1rem;letter-spacing:.3em;text-align:center">
                    <button type="submit" class="btn btn-primary">Verify</button>
                </form>
            </div>
            <?php elseif (($result['verify'] ?? '') === 'email_note'): ?>
            <div class="iv-verify">&#128231; We also emailed <strong><?= $h($in['email'] ?? '') ?></strong> a link to set a password, so you can sign in later. Your spot does not depend on it.</div>
            <?php endif; ?>
            <?php if ($viewer): ?>
            <p class="iv-alt"><a href="/event.php?id=<?= (int)$event_id ?>">Open the event</a></p>
            <?php endif; ?>

        <?php elseif ($mine && in_array($mine['approval_status'] ?? 'approved', ['approved'], true) && ($mine['rsvp'] ?? '') !== 'no'): ?>
            <div class="iv-ok" role="status"><strong>You're already on the list.</strong></div>
            <p class="iv-alt"><a href="/event.php?id=<?= (int)$event_id ?>">Open the event</a></p>

        <?php elseif ($mine && in_array($mine['approval_status'] ?? '', ['pending', 'denied', 'waitlisted'], true)): ?>
            <div class="iv-wait" role="status"><strong><?= ($mine['approval_status'] === 'waitlisted') ? "You're on the waiting list." : "You've asked to join." ?></strong> The host will let you know.</div>

        <?php else: ?>
            <?php if (!empty($event['description'])): ?>
            <p class="iv-desc"><?= nl2br($h($event['description'])) ?></p>
            <?php endif; ?>

            <?php if ($seatsLeft !== null): ?>
            <div class="iv-seats <?= $isFull ? 'full' : 'open' ?>">
                <?php if (!$isFull): ?>
                <?= (int)$seatsLeft ?> of <?= (int)$cap['capacity'] ?> <?= (int)$event['is_poker'] === 1 ? 'seats' : 'spots' ?> left
                <?php elseif ($cap['waitlist']): ?>
                It's full: you'll go on the waiting list.
                <?php else: ?>
                It's full.
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if ($error !== ''): ?><div class="iv-err" role="alert"><?= $h($error) ?></div><?php endif; ?>

            <?php if (!$isFull || $cap['waitlist']): ?>
                <?php if ($needsOk): ?><p class="iv-note">The host approves each guest. You'll hear back once you're in, with the address.</p><?php endif; ?>
                <?php if ($viewer): ?>
                <form method="post" action="<?= $h($selfPath) ?>">
                    <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
                    <input type="hidden" name="as_me" value="1">
                    <button type="submit" class="btn btn-primary iv-btn"><?= $needsOk ? 'Ask to join' : "I'm in" ?></button>
                </form>
                <p class="iv-alt">Signed in as <?= $h($viewer['username']) ?>.</p>
                <?php else: ?>
                <form method="post" action="<?= $h($selfPath) ?>">
                    <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
                    <div class="form-group">
                        <label for="iv_name">Your name</label>
                        <input type="text" id="iv_name" name="display_name" required maxlength="40" autocomplete="name"
                               placeholder="Jane Smith" value="<?= $h($_POST['display_name'] ?? '') ?>" style="width:100%">
                    </div>
                    <div class="form-group">
                        <label for="iv_contact">Email or phone</label>
                        <input type="text" id="iv_contact" name="contact" data-phone-contact="1" required autocomplete="email"
                               placeholder="you@example.com or 555-123-4567" value="<?= $h($_POST['contact'] ?? '') ?>" style="width:100%">
                    </div>
                    <p class="iv-note">Only the host sees this. It's how they reach you about the game.</p>
                    <button type="submit" class="btn btn-primary iv-btn"><?= $isFull ? 'Join the waiting list' : ($needsOk ? 'Ask to join' : "I'm in") ?></button>
                </form>
                <p class="iv-alt">Already have an account? <a href="/login.php?redirect=<?= $h(urlencode($selfPath)) ?>">Sign in</a></p>
                <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</main>
<script src="/_phone_input.js?v=<?= $h(APP_VERSION . '.' . (@filemtime(__DIR__ . '/_phone_input.js') ?: 0)) ?>"></script>
<script nonce="<?= csp_nonce() ?>">initPhoneAutoFormat();</script>
</body>
</html>
