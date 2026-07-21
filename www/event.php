<?php
/**
 * Public, token-based event page — lets an invitee view event details, see who's coming,
 * and set/change their RSVP WITHOUT logging in. URL: /event.php?token=<rsvp_token>
 *
 * The token is the per-invitee event_invites.rsvp_token (same one used by rsvp.php). It only
 * exposes display names + RSVP state — never emails or phone numbers. RSVP buttons link to
 * rsvp.php (confirm-on-POST) which redirects back here with &just=<value> after applying.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

session_start_safe();

$db         = get_db();
$token      = trim($_GET['token'] ?? '');
$just       = strtolower(trim($_GET['just'] ?? ''));
$site_name  = get_setting('site_name', 'Game Night');
$allowMaybe = get_setting('allow_maybe_rsvp', '1') === '1';
$validRsvp  = array_merge(['yes', 'no'], $allowMaybe ? ['maybe'] : []);

// ── Render a simple branded page (shared by error + main views) ──────────────
function ev_show_simple(string $heading, string $body, string $type = 'error'): void {
    global $site_name;
    $color = $type === 'success' ? '#16a34a' : '#dc2626';
    $bg    = $type === 'success' ? '#f0fdf4' : '#fef2f2';
    ?><!DOCTYPE html>
<html lang="en"><head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($heading) ?> &ndash; <?= htmlspecialchars($site_name) ?></title>
    <link rel="stylesheet" href="/style.css?v=<?= htmlspecialchars(APP_VERSION) ?>">
</head>
<body style="display:flex;align-items:center;justify-content:center;min-height:100vh;padding:1rem">
    <div style="max-width:480px;width:100%;text-align:center">
        <div style="background:<?= $bg ?>;border:2px solid <?= $color ?>;border-radius:12px;padding:2rem 1.5rem;margin-bottom:1.5rem">
            <h1 style="font-size:1.5rem;color:<?= $color ?>;margin:0 0 .75rem"><?= htmlspecialchars($heading) ?></h1>
            <div style="font-size:1rem;color:#334155;line-height:1.6"><?= $body ?></div>
        </div>
        <a href="/" style="color:#2563eb;text-decoration:none;font-size:.9rem">Go to <?= htmlspecialchars($site_name) ?></a>
    </div>
</body></html><?php
}

// ── Canonical logged-in event page: /event.php?id=<event_id> ─────────────────
// The shareable, bookmarkable home for an event. Notification links and the
// modal's copy-link land here. Managers get shortcut buttons; invite management
// stays in the calendar modal / editor.
$page_eid = (int)($_GET['id'] ?? 0);
if ($token === '' && $page_eid > 0) {
    $current = current_user();
    if (!$current) {
        header('Location: /login.php?redirect=' . urlencode('/event.php?id=' . $page_eid));
        exit;
    }
    $isAdmin = $current['role'] === 'admin';

    $vis = event_visibility_sql('e', (int)$current['id']);
    $evq = $db->prepare("SELECT e.*, l.name AS league_name FROM events e
                         LEFT JOIN leagues l ON l.id = e.league_id
                         WHERE e.id = ? AND {$vis['sql']}");
    $evq->execute(array_merge([$page_eid], $vis['params']));
    $ev = $evq->fetch();
    if (!$ev) {
        http_response_code(404);
        ev_show_simple('Event not found', 'This event does not exist or you do not have access to it.');
        exit;
    }

    $canManage = can_manage_event($db, $page_eid, (int)$current['id'], $isAdmin);

    // Viewer-tz labels (same helper the token page uses further down)
    $_evt2    = event_public_time_labels($ev['start_date'], $ev['start_time'] ?? null, $ev['end_time'] ?? null, (int)$current['id']);
    $date_lbl2 = $_evt2['date_lbl'];
    $time_lbl2 = $_evt2['time_lbl'];

    // Roster + my own invite row
    $att = $db->prepare("SELECT username, rsvp, approval_status FROM event_invites
                         WHERE event_id = ? AND occurrence_date IS NULL
                         ORDER BY COALESCE(sort_order, 999999), username");
    $att->execute([$page_eid]);
    $going = []; $maybe = []; $pendingR = []; $declined = []; $myInv = null;
    $capUsed = 0;
    foreach ($att->fetchAll() as $a) {
        if (strcasecmp($a['username'], $current['username']) === 0) $myInv = $a;
        if (($a['approval_status'] ?? 'approved') !== 'approved') continue;
        switch (strtolower((string)($a['rsvp'] ?? ''))) {
            case 'yes':   $going[]    = $a['username']; break;
            case 'maybe': $maybe[]    = $a['username']; break;
            case 'no':    $declined[] = $a['username']; break;
            default:      $pendingR[] = $a['username']; break;
        }
    }

    // Poker capacity for the meta line
    $psq = $db->prepare('SELECT seats_per_table, num_tables FROM poker_sessions WHERE event_id = ?');
    $psq->execute([$page_eid]);
    $psRow = $psq->fetch();

    // Comments (registered users only, newest last — same order as the modal)
    $cq = $db->prepare("SELECT c.id, c.user_id, c.body, c.created_at, u.username
                        FROM comments c JOIN users u ON u.id = c.user_id
                        WHERE c.type = 'event' AND c.content_id = ?
                        ORDER BY c.created_at ASC, c.id ASC");
    $cq->execute([$page_eid]);
    $comments = $cq->fetchAll();

    $csrf     = csrf_token();
    $occDateQ = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'] ?? '') ? $_GET['date'] : $ev['start_date'];
    $selfUrl  = '/event.php?id=' . $page_eid;
    $calUrl   = '/calendar.php?m=' . urlencode(substr($occDateQ, 0, 7)) . '&date=' . urlencode($occDateQ);
    $myRsvp   = $myInv ? strtolower((string)($myInv['rsvp'] ?? '')) : '';
    $isInvited   = $myInv !== null;
    $isPendingMe = $myInv && ($myInv['approval_status'] ?? 'approved') === 'pending';
    $isWaitMe    = $myInv && ($myInv['approval_status'] ?? 'approved') === 'waitlisted';
    $isCreator   = (int)$ev['created_by'] === (int)$current['id'];
    $notifsEnabled = get_setting('notifications_enabled', '0') === '1';

    // Waitlist position (same computation the calendar modal used)
    $waitPos = 0;
    if ($isWaitMe) {
        $wq = $db->prepare("SELECT username FROM event_invites
                            WHERE event_id = ? AND occurrence_date IS NULL AND approval_status = 'waitlisted'
                            ORDER BY id");
        $wq->execute([$page_eid]);
        $pos = 0;
        foreach ($wq->fetchAll(PDO::FETCH_COLUMN) as $wname) {
            $pos++;
            if (strcasecmp($wname, $current['username']) === 0) { $waitPos = $pos; break; }
        }
    }

    // My scheduled reminder offsets, split by state: pending rows can still be
    // cancelled (dropdown toggles them off); sent ones are locked history.
    $remPending = [];
    $remSent    = [];
    if ($isInvited) {
        try {
            $rq = $db->prepare("SELECT payload FROM pending_notifications
                                WHERE event_id = ? AND LOWER(username) = LOWER(?) AND notify_type = 'reminder' AND attempted_at IS NULL");
            $rq->execute([$page_eid, $current['username']]);
            foreach ($rq->fetchAll(PDO::FETCH_COLUMN) as $pl) {
                $o = (int)((json_decode((string)$pl, true) ?: [])['offset_minutes'] ?? 0);
                if ($o > 0) $remPending[$o] = true;
            }
            $sq = $db->prepare("SELECT notification_type FROM event_notifications_sent
                                WHERE event_id = ? AND user_identifier = ? AND notification_type LIKE 'reminder_%'");
            $sq->execute([$page_eid, strtolower($current['username'])]);
            foreach ($sq->fetchAll(PDO::FETCH_COLUMN) as $tag) {
                $o = (int)substr($tag, strlen('reminder_'));
                if ($o > 0) $remSent[$o] = true;
            }
        } catch (Throwable $e) {}
    }

    // Host messages ("final details"): history for everyone, compose/delete for managers.
    $evMsgs = [];
    try {
        $mq = $db->prepare("SELECT m.id, m.subject, m.body_html, m.audience, m.created_at, u.username AS author
                            FROM event_messages m LEFT JOIN users u ON u.id = m.created_by
                            WHERE m.event_id = ? ORDER BY m.created_at DESC, m.id DESC");
        $mq->execute([$page_eid]);
        $evMsgs = $mq->fetchAll();
    } catch (Throwable $e) {}
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($ev['title']) ?> &ndash; <?= htmlspecialchars($site_name) ?></title>
    <link rel="stylesheet" href="/style.css?v=<?= htmlspecialchars(APP_VERSION) ?>">
    <style>
        .evp-wrap { max-width: 640px; margin: 1.25rem auto 2rem; padding: 0 1rem; }
        .evp-card { background:#fff; border:1.5px solid #e2e8f0; border-radius:12px; padding:1.5rem; }
        .evp-meta { color:#475569; font-size:.95rem; margin-bottom:.15rem; }
        .evp-comment { border-top:1px solid #f1f5f9; padding:.55rem 0; }
        .evp-comment .who { font-weight:600; font-size:.85rem; color:#334155; }
        .evp-comment .when { color:#94a3b8; font-size:.75rem; margin-left:.4rem; }
        .evp-comment .body { font-size:.9rem; color:#334155; margin-top:.15rem; white-space:pre-wrap; }
    </style>
</head>
<body>
<?php require __DIR__ . '/_nav.php'; ?>
<div class="evp-wrap">
    <?php
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    if ($flash && !empty($flash['msg'])):
        $ok = ($flash['type'] ?? '') === 'success'; ?>
    <div style="margin-bottom:.9rem;padding:.6rem .8rem;border-radius:8px;font-size:.9rem;font-weight:600;<?= $ok ? 'background:#f0fdf4;border:1px solid #bbf7d0;color:#166534' : 'background:#fef2f2;border:1px solid #fca5a5;color:#dc2626' ?>">
        <?= htmlspecialchars($flash['msg']) ?>
    </div>
    <?php endif; ?>
    <div class="evp-card">
        <div style="display:flex;align-items:flex-start;gap:.6rem;flex-wrap:wrap">
            <h1 style="font-size:1.4rem;font-weight:800;color:#1e293b;margin:0 0 .5rem;flex:1;min-width:0"><?= htmlspecialchars($ev['title']) ?></h1>
            <?php if (!empty($ev['league_name'])): ?>
            <span style="background:#eef2ff;color:#4338ca;font-size:.72rem;font-weight:700;padding:.2rem .55rem;border-radius:999px"><?= htmlspecialchars($ev['league_name']) ?></span>
            <?php endif; ?>
        </div>
        <div class="evp-meta"><?= htmlspecialchars($date_lbl2) ?><?= $time_lbl2 !== '' ? ' &middot; ' . $time_lbl2 : '' ?>
            <?php if ($psRow): ?>
                &middot; <?= count($going) ?>/<?= max(1, (int)$psRow['seats_per_table'] * (int)$psRow['num_tables']) ?> seats filled
            <?php else: ?>
                &middot; <?= count($going) ?><?= !empty($ev['max_guests']) ? '/' . (int)$ev['max_guests'] : '' ?> going
            <?php endif; ?>
        </div>
        <?php if (!empty($ev['location'])): ?>
        <div style="font-size:.9rem;color:#475569;margin-top:.3rem">
            &#128205; <?= htmlspecialchars($ev['location']) ?>
            &middot; <a href="https://www.google.com/maps/search/?api=1&amp;query=<?= urlencode($ev['location']) ?>" target="_blank" rel="noopener">Open in Maps</a>
        </div>
        <?php endif; ?>
        <div style="font-size:.85rem;color:#64748b;margin-top:.35rem">
            &#128197; <a href="/ics.php?id=<?= $page_eid ?>">Add to calendar</a>
            &middot; <a href="/ics.php?id=<?= $page_eid ?>&amp;google=1" target="_blank" rel="noopener">Google</a>
            &middot; <a href="<?= htmlspecialchars($calUrl) ?>">Open in calendar</a>
        </div>

        <?php if (!empty($ev['description'])): ?>
        <div style="margin-top:1rem;font-size:.95rem;color:#334155;line-height:1.55"><?= nl2br(htmlspecialchars($ev['description'])) ?></div>
        <?php endif; ?>

        <?php if ($canManage): ?>
        <!-- Primary host actions stay visible; the rest fold into "More" so the
             event info isn't buried under a wall of buttons on phones. Delete
             lives isolated at the bottom of the menu, away from casual taps. -->
        <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-top:1.1rem;align-items:center">
            <a class="btn btn-primary" style="text-decoration:none" href="/event_edit.php?id=<?= $page_eid ?>">Edit</a>
            <?php if ((int)$ev['is_poker'] === 1): ?>
            <a class="btn" style="background:#059669;color:#fff;text-decoration:none" href="/checkin.php?event_id=<?= $page_eid ?>">Manage Game</a>
            <?php endif; ?>
            <div class="ev-more-dd" id="evMoreDD">
                <button type="button" class="btn btn-outline" onclick="toggleEvMore(event)">More <span style="font-size:.7rem">&#9662;</span></button>
                <div class="ev-more-panel" id="evMorePanel">
                    <a href="/event_edit.php?copy=<?= $page_eid ?>">Duplicate event</a>
                    <a href="/event_polls.php?event_id=<?= $page_eid ?>">Polls</a>
                    <a href="javascript:void(0)" onclick="evpCopyLink(this);toggleEvMore()">&#128279; Copy link</a>
                    <?php if ($isAdmin): ?>
                    <a href="/walkin_display.php?event_id=<?= $page_eid ?>" target="_blank" rel="noopener">&#x1F4F1; Walk-up QR</a>
                    <?php endif; ?>
                    <form method="post" action="/calendar.php" style="margin:0;border-top:1px solid #f1f5f9"
                          onsubmit="return pkConfirmForm(this, 'Delete this event?', {okLabel:'Delete', danger:true})">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= $page_eid ?>">
                        <input type="hidden" name="month_param" value="<?= htmlspecialchars(substr($occDateQ, 0, 7)) ?>">
                        <button type="submit">Delete event</button>
                    </form>
                </div>
            </div>
        </div>
        <style>
        .ev-more-dd { position:relative; }
        .ev-more-panel { display:none;position:absolute;top:calc(100% + 4px);left:0;z-index:60;background:#fff;
            border:1.5px solid #cbd5e1;border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,.14);min-width:180px;overflow:hidden; }
        .ev-more-dd.open .ev-more-panel { display:block; }
        .ev-more-panel a, .ev-more-panel button { display:block;width:100%;text-align:left;padding:.55rem .85rem;
            font-size:.88rem;color:#334155;text-decoration:none;background:none;border:0;cursor:pointer;font-family:inherit; }
        .ev-more-panel a:hover, .ev-more-panel button:hover { background:#f1f5f9; }
        .ev-more-panel form button { color:#dc2626;font-weight:600; }
        </style>
        <?php endif; ?>

        <!-- RSVP -->
        <div style="margin-top:1.4rem;padding-top:1.1rem;border-top:1px solid #e2e8f0">
            <?php if ($isPendingMe): ?>
                <div style="font-size:.9rem;color:#92400e;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:.55rem .7rem">Your sign-up is waiting for host approval.</div>
            <?php elseif ($isWaitMe): ?>
                <div style="font-size:.9rem;color:#92400e;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:.55rem .7rem">You are on the waitlist for this event<?= $waitPos > 0 ? ' — position ' . $waitPos : '' ?>. You'll be notified if a seat opens up.</div>
            <?php elseif ($isInvited): ?>
                <div style="display:flex;align-items:baseline;gap:.6rem;margin-bottom:.55rem">
                    <span style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#2563eb">Are you coming?</span>
                    <span id="evpSaved" style="visibility:hidden;color:#166534;background:#dcfce7;border-radius:6px;padding:.1rem .55rem;font-size:.75rem;font-weight:600">Saved</span>
                </div>
                <div style="display:flex;gap:.5rem;max-width:380px" id="evpRsvpBtns">
                    <button type="button" class="evp-rb" data-rsvp="yes">&#10003; Yes</button>
                    <?php if ($allowMaybe): ?><button type="button" class="evp-rb" data-rsvp="maybe">? Maybe</button><?php endif; ?>
                    <button type="button" class="evp-rb" data-rsvp="no">&#10007; No</button>
                </div>
                <div style="margin-top:.8rem;display:flex;align-items:center;gap:.45rem;flex-wrap:wrap;font-size:.85rem;color:#475569">
                    <label style="font-weight:600;display:inline-flex;align-items:center;gap:.4rem">&#9200; Remind me
                        <select id="evpRemind" title="An extra reminder just for you, on top of the host's, via your usual channel" style="padding:.3rem .5rem;border:1.5px solid #e2e8f0;border-radius:6px;font-size:.83rem">
                            <option value="" id="evpRemindNone">None</option>
                            <?php foreach ([60 => '1 hour before', 180 => '3 hours before', 720 => '12 hours before', 1440 => '1 day before'] as $__o => $__l):
                                $__sent = isset($remSent[$__o]) && !isset($remPending[$__o]);
                                $__set  = isset($remPending[$__o]);
                            ?>
                            <option value="<?= $__o ?>" data-set="<?= $__set ? '1' : '0' ?>" <?= $__sent ? 'disabled' : '' ?>><?= $__l ?><?= $__set ? ' ✓ set' : ($__sent ? ' — sent' : '') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <?php if (!$isCreator): ?>
                    <a href="javascript:void(0)" id="evpLeaveBtn" style="margin-left:auto;font-size:.78rem;color:#dc2626;text-decoration:none">Leave this event</a>
                    <?php endif; ?>
                </div>
                <style>
                .evp-rb { flex:1;padding:.6rem .5rem;border-radius:9px;font-size:.95rem;font-weight:700;cursor:pointer;
                    background:#fff;border:2px solid #cbd5e1;color:#64748b;font-family:inherit; }
                .evp-rb[data-rsvp=yes].on   { background:#dcfce7;border-color:#16a34a;color:#166534; }
                .evp-rb[data-rsvp=maybe].on { background:#fef3c7;border-color:#d97706;color:#92400e; }
                .evp-rb[data-rsvp=no].on    { background:#fee2e2;border-color:#dc2626;color:#991b1b; }
                .evp-rb:hover { border-color:#93c5fd; }
                </style>
            <?php else: ?>
                <button type="button" class="btn btn-primary" id="evpSignupBtn">Sign up to attend</button>
            <?php endif; ?>
        </div>

        <?php
        // Event chat: host/managers always; guests once they're an approved yes.
        $chatEligible = $canManage
            || ($isInvited && $myRsvp === 'yes' && ($myInv['approval_status'] ?? 'approved') === 'approved');
        if ($chatEligible): ?>
        <div style="margin-top:1.1rem;display:flex;align-items:center;gap:.6rem;flex-wrap:wrap">
            <a class="btn btn-outline" style="text-decoration:none" href="/message_thread.php?event=<?= $page_eid ?>">&#128172; Event chat</a>
            <span style="font-size:.78rem;color:#94a3b8">group chat for the host and everyone going</span>
        </div>
        <?php endif; ?>

        <!-- Who's coming: managers get the live panel (delivery status, approvals,
             on-behalf RSVPs — ported from the old calendar popup); guests keep the
             simple name list, honoring hide_guest_list. -->
        <?php if ($canManage): ?>
        <div id="evInvPanel" style="margin-top:1.25rem;padding-top:1.1rem;border-top:1px solid #e2e8f0"></div>
        <?php elseif (empty($ev['hide_guest_list'])): ?>
        <div style="margin-top:1.25rem;padding-top:1.1rem;border-top:1px solid #e2e8f0">
            <?= ev_names_block('Going', $going, '#16a34a')
              . ev_names_block('Maybe', $maybe, '#d97706')
              . ev_names_block('Invited', $pendingR, '#64748b')
              . ev_names_block("Can't make it", $declined, '#94a3b8') ?>
        </div>
        <?php elseif (count($going) > 0): ?>
        <div style="margin-top:1.25rem;padding-top:1.1rem;border-top:1px solid #e2e8f0;font-size:.9rem;color:#475569">
            <strong style="color:#16a34a"><?= count($going) ?></strong> going<?= count($maybe) > 0 ? ' · ' . count($maybe) . ' maybe' : '' ?>
        </div>
        <?php endif; ?>

        <!-- Messages from the host: history for everyone; compose + delete for managers -->
        <?php if ($evMsgs || ($canManage && $notifsEnabled)): ?>
        <div style="margin-top:1.25rem;padding-top:1.1rem;border-top:1px solid #e2e8f0">
            <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.45rem">
                <span style="flex:1;font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#94a3b8">Messages from the host</span>
                <?php if ($canManage && $notifsEnabled): ?>
                <button type="button" class="btn" style="background:#16a34a;color:#fff;font-size:.78rem;padding:.3rem .8rem" onclick="openEventMsgModal()">Message guests</button>
                <?php endif; ?>
            </div>
            <?php if ($evMsgs): ?>
            <div style="display:flex;flex-direction:column;gap:.45rem">
                <?php foreach ($evMsgs as $m):
                    $aud = $m['audience'] === 'all' ? 'All guests' : ($m['audience'] === 'yes_maybe' ? 'Yes & Maybe' : 'Going (Yes)');
                ?>
                <div style="border:1px solid #e2e8f0;border-radius:8px;padding:.5rem .6rem;background:#fff">
                    <div style="display:flex;align-items:flex-start;gap:.5rem">
                        <div style="flex:1;min-width:0;font-weight:600;font-size:.85rem;color:#1e293b"><?= htmlspecialchars($m['subject']) ?></div>
                        <?php if ($canManage): ?>
                        <button type="button" title="Delete this message" onclick="deleteEventMsg(<?= (int)$m['id'] ?>, this)" style="background:none;border:0;color:#cbd5e1;cursor:pointer;font-size:1.15rem;line-height:1;padding:0 .15rem">&times;</button>
                        <?php endif; ?>
                    </div>
                    <div style="font-size:.7rem;color:#94a3b8;margin:.1rem 0 .4rem"><?= htmlspecialchars($m['created_at']) ?><?= $canManage ? ' &middot; ' . htmlspecialchars($aud) : '' ?></div>
                    <div style="font-size:.85rem;color:#334155;line-height:1.5"><?= $m['body_html'] /* sanitized at store time */ ?></div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php elseif ($canManage): ?>
            <div style="font-size:.78rem;color:#94a3b8">No messages sent yet. Use &ldquo;Message guests&rdquo; to send the address and final details.</div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Comments -->
        <div style="margin-top:1.25rem;padding-top:1.1rem;border-top:1px solid #e2e8f0">
            <div style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#94a3b8;margin-bottom:.3rem">
                <?= count($comments) ?> <?= count($comments) === 1 ? 'Comment' : 'Comments' ?>
            </div>
            <?php foreach ($comments as $c):
                $canModC = (int)$c['user_id'] === (int)$current['id'] || $isAdmin;
            ?>
            <div class="evp-comment" id="evc-<?= (int)$c['id'] ?>">
                <span class="who"><?= htmlspecialchars($c['username']) ?></span><span class="when"><?= htmlspecialchars($c['created_at']) ?></span>
                <?php if ($canModC): ?>
                <span style="float:right;display:inline-flex;gap:.3rem">
                    <button type="button" title="Edit" onclick="editComment(<?= (int)$c['id'] ?>)" style="background:none;border:0;color:#94a3b8;cursor:pointer;font-size:.85rem;padding:0">&#9998;</button>
                    <button type="button" title="Delete" onclick="deleteComment(<?= (int)$c['id'] ?>)" style="background:none;border:0;color:#94a3b8;cursor:pointer;font-size:.85rem;padding:0">&#x2715;</button>
                </span>
                <?php endif; ?>
                <div class="body" id="evcb-<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['body']) ?></div>
            </div>
            <?php endforeach; ?>
            <form method="post" action="/comment.php" style="margin-top:.75rem;display:flex;gap:.5rem;align-items:flex-start">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="action" value="add">
                <input type="hidden" name="type" value="event">
                <input type="hidden" name="content_id" value="<?= $page_eid ?>">
                <input type="hidden" name="redirect" value="<?= htmlspecialchars($selfUrl) ?>">
                <textarea name="body" required maxlength="2000" placeholder="Write a comment…" rows="2" style="flex:1;padding:.5rem;border:1.5px solid #e2e8f0;border-radius:8px;font-size:.9rem"></textarea>
                <button type="submit" class="btn btn-primary">Post</button>
            </form>
        </div>
    </div>
</div>
<script>
(function () {
    var CSRF = <?= json_encode($csrf) ?>;
    var EID  = <?= (int)$page_eid ?>;
    function post(action, extra) {
        var fd = new FormData();
        fd.append('csrf_token', CSRF);
        fd.append('action', action);
        fd.append('event_id', EID);
        for (var k in (extra || {})) fd.append(k, extra[k]);
        return fetch('/calendar.php', { method: 'POST', body: fd, headers: {'X-Requested-With': 'XMLHttpRequest'} })
            .then(function (r) { return r.json(); });
    }
    // Big RSVP buttons: tap to choose, tap the active one again to clear.
    var MY_RSVP = <?= json_encode($myRsvp) ?>;
    function paintRsvpBtns() {
        document.querySelectorAll('.evp-rb').forEach(function (b) {
            b.classList.toggle('on', b.dataset.rsvp === MY_RSVP);
        });
    }
    paintRsvpBtns();
    document.querySelectorAll('.evp-rb').forEach(function (b) {
        b.addEventListener('click', function () {
            var val = this.dataset.rsvp === MY_RSVP ? '' : this.dataset.rsvp;
            var btn = this;
            pkBusy(btn, post('update_rsvp', { rsvp: val, occurrence_date: '' }).then(function (res) {
                if (!res.ok) { pkAlert(res.error || 'Request failed — your RSVP was not saved.'); return; }
                MY_RSVP = val;
                paintRsvpBtns();
                var s = document.getElementById('evpSaved');
                s.style.visibility = 'visible';
                setTimeout(function () { s.style.visibility = 'hidden'; }, 3000);
            }).catch(function () { pkAlert('Request failed — your RSVP was not saved.'); }));
        });
    });
    var su = document.getElementById('evpSignupBtn');
    if (su) su.addEventListener('click', function () {
        pkBusy(su, post('self_signup').then(function (res) {
            if (!res.ok) { pkAlert(res.error || 'Sign-up did not go through.'); return; }
            location.reload();
        }).catch(function () { pkAlert('Request failed — sign-up did not go through.'); }));
    });
    var lv = document.getElementById('evpLeaveBtn');
    if (lv) lv.addEventListener('click', async function () {
        if (!(await pkConfirm('Remove yourself from this event?'))) return;
        pkBusy(lv, post('self_remove').then(function (res) {
            if (!res.ok) { pkAlert(res.error || 'Could not remove you.'); return; }
            location.reload();
        }).catch(function () { pkAlert('Request failed.'); }));
    });
    // The resting (blank) line of the dropdown summarizes what's scheduled,
    // e.g. "1 hr, 1 day" — so the closed control always tells the truth.
    function updateRemindLabel() {
        var none = document.getElementById('evpRemindNone');
        if (!none) return;
        var shorts = { '60': '1 hr', '180': '3 hr', '720': '12 hr', '1440': '1 day' };
        var set = [];
        document.querySelectorAll('#evpRemind option[data-set="1"]').forEach(function (o) {
            set.push(shorts[o.value] || o.value);
        });
        none.text = set.length ? set.join(', ') : 'None';
    }
    updateRemindLabel();

    // "Remind me" is a toggle: pick a time to schedule it; pick a "✓ set" time
    // again to cancel it (sent reminders are locked).
    var rm = document.getElementById('evpRemind');
    if (rm) rm.addEventListener('change', async function () {
        if (!rm.value) return;
        var opt = rm.options[rm.selectedIndex];
        var offset = rm.value;
        rm.value = '';
        if (opt.dataset.set === '1') {
            if (!(await pkConfirm('Remove this reminder?'))) return;
            post('self_reminder_remove', { offset: offset }).then(function (res) {
                if (!res.ok) { pkAlert(res.error || 'Could not remove the reminder.'); return; }
                opt.dataset.set = '0';
                opt.text = opt.text.replace(/ ✓ set$/, '');
                updateRemindLabel();
                pkAlert('Reminder removed.', { title: 'Reminder' });
            }).catch(function () { pkAlert('Request failed.'); });
            return;
        }
        var chosen = opt.text;
        post('self_reminder', { offset: offset }).then(function (res) {
            if (!res.ok) { pkAlert(res.error || 'Could not set the reminder.'); return; }
            opt.dataset.set = '1';
            if (opt.text.indexOf('✓') === -1) opt.text = opt.text + ' ✓ set';
            updateRemindLabel();
            if (!res.already) {
                pkAlert('Reminder set — you\'ll hear from us ' + chosen.toLowerCase() + ' the event. Pick it again to remove it.', { title: 'Reminder' });
            }
        }).catch(function () { pkAlert('Request failed.'); });
    });
})();

// ── Shared helpers (copy link, comment moderation) ──────────────────────────
var EV_CSRF = <?= json_encode($csrf) ?>;
var EV_EID  = <?= (int)$page_eid ?>;
function evPost(action, extra) {
    var fd = new FormData();
    fd.append('csrf_token', EV_CSRF);
    fd.append('action', action);
    fd.append('event_id', EV_EID);
    for (var k in (extra || {})) fd.append(k, extra[k]);
    return fetch('/calendar.php', { method: 'POST', body: fd, headers: {'X-Requested-With': 'XMLHttpRequest'} })
        .then(function (r) { return r.json(); });
}
function evToast(msg) {
    var t = document.createElement('div');
    t.textContent = msg || 'Saved';
    t.style.cssText = 'position:fixed;bottom:1.2rem;left:50%;transform:translateX(-50%);background:#dcfce7;color:#166534;border:1px solid #86efac;border-radius:8px;padding:.45rem 1rem;font-size:.85rem;font-weight:600;z-index:1200';
    document.body.appendChild(t);
    setTimeout(function () { t.remove(); }, 2500);
}
function toggleEvMore(e) {
    if (e) e.stopPropagation();
    var dd = document.getElementById('evMoreDD');
    if (dd) dd.classList.toggle('open');
}
document.addEventListener('click', function (e) {
    var dd = document.getElementById('evMoreDD');
    if (dd && dd.classList.contains('open') && !dd.contains(e.target)) dd.classList.remove('open');
});
function evpCopyLink(btn) {
    navigator.clipboard.writeText(location.origin + '/event.php?id=' + EV_EID).then(function () {
        evToast('Link copied');
    }).catch(function () { pkAlert(location.origin + '/event.php?id=' + EV_EID, { title: 'Event link' }); });
}
async function deleteComment(id) {
    if (!(await pkConfirm('Delete this comment?'))) return;
    var fd = new FormData();
    fd.append('csrf_token', EV_CSRF);
    fd.append('action', 'delete');
    fd.append('comment_id', id);
    fetch('/comment.php', { method: 'POST', body: fd, headers: {'X-Requested-With': 'XMLHttpRequest'} })
        .then(function (r) { return r.json(); })
        .then(function (j) {
            if (!j.ok) { pkAlert('Could not delete the comment.'); return; }
            var el = document.getElementById('evc-' + id);
            if (el) el.remove();
        }).catch(function () { pkAlert('Request failed.'); });
}
async function editComment(id) {
    var bodyEl = document.getElementById('evcb-' + id);
    var newBody = await pkPrompt('Edit comment', { 'default': bodyEl ? bodyEl.textContent : '' });
    if (newBody === null || newBody === undefined) return;
    newBody = String(newBody).trim();
    if (newBody === '') return;
    var fd = new FormData();
    fd.append('csrf_token', EV_CSRF);
    fd.append('action', 'edit');
    fd.append('comment_id', id);
    fd.append('body', newBody);
    fetch('/comment.php', { method: 'POST', body: fd, headers: {'X-Requested-With': 'XMLHttpRequest'} })
        .then(function (r) { return r.json(); })
        .then(function (j) {
            if (!j.ok) { pkAlert('Could not save the comment.'); return; }
            if (bodyEl) bodyEl.textContent = j.body;
            evToast('Comment updated');
        }).catch(function () { pkAlert('Request failed.'); });
}
</script>

<?php if ($canManage): ?>
<!-- Compose "final details" message (ported from the calendar popup) -->
<style>
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:1100;align-items:center;justify-content:center;padding:1rem}
.modal-overlay.open{display:flex}
.modal{background:#fff;border-radius:14px;padding:1.1rem 1.25rem;max-width:640px;width:100%;box-shadow:0 20px 50px rgba(0,0,0,.25)}
.inv-rsvp-sel{padding:.2rem .35rem;border:1px solid #cbd5e1;border-radius:5px;font-size:.78rem;background:#fff}
.inv-nocontact-tag{font-size:.65rem;font-weight:700;color:#991b1b;background:#fef2f2;border:1px solid #fecaca;border-radius:4px;padding:.05rem .3rem}
.rsvp-yes{color:#16a34a;font-weight:700;font-size:.78rem}.rsvp-no{color:#dc2626;font-weight:700;font-size:.78rem}.rsvp-maybe{color:#d97706;font-weight:700;font-size:.78rem}
</style>
<div class="modal-overlay" id="eventMsgModal" onclick="if(event.target===this)closeEventMsgModal()">
    <div class="modal" style="display:flex;flex-direction:column;max-height:92vh">
        <div style="display:flex;align-items:center;gap:.6rem;border-bottom:1px solid #e2e8f0;padding-bottom:.6rem;margin-bottom:.75rem">
            <h2 style="margin:0;flex:1;font-size:1.15rem">Message going guests</h2>
            <a href="javascript:void(0)" onclick="closeEventMsgModal()" aria-label="Close" style="font-size:1.5rem;line-height:1;color:#94a3b8;text-decoration:none">&times;</a>
        </div>
        <p style="margin-top:0;color:#64748b;font-size:.85rem">Send a message to your guests. They'll get it by their preferred channel; text recipients get a link to read it.</p>
        <div class="form-group">
            <label for="emSubject">Subject</label>
            <input type="text" id="emSubject" maxlength="150" placeholder="Enter a subject" style="width:100%;padding:.5rem .75rem;border:1.5px solid #e2e8f0;border-radius:8px;box-sizing:border-box">
        </div>
        <div class="form-group">
            <label for="emAudience">Send to</label>
            <select id="emAudience" style="width:100%;padding:.5rem .75rem;border:1.5px solid #e2e8f0;border-radius:8px;background:#fff">
                <option value="yes">Going (Yes) only</option>
                <option value="yes_maybe">Going &amp; Maybe</option>
                <option value="all">All invited (any response)</option>
            </select>
        </div>
        <div class="form-group" style="flex:1;min-height:0">
            <label for="emBody">Message</label>
            <textarea id="emBody" name="body"></textarea>
        </div>
        <div style="display:flex;gap:.6rem;justify-content:flex-end;margin-top:.5rem">
            <button type="button" class="btn btn-outline" onclick="closeEventMsgModal()">Cancel</button>
            <button type="button" class="btn btn-primary" id="emSendBtn" onclick="sendEventMessage()">Send message</button>
        </div>
    </div>
</div>
<script src="/vendor/jodit/jodit.min.js" defer></script>
<script>
// ── Manager panel: live roster, approvals, delivery status, invites ─────────
// Ported from the calendar popup (renderInvitesPanel/pollRsvps) — one view now.
var NOTIFS_ON   = <?= $notifsEnabled ? 'true' : 'false' ?>;
var ALLOW_MAYBE = <?= $allowMaybe ? 'true' : 'false' ?>;
var invBase = [], invQueue = {}, sawPending = false;
function escH(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;'); }

function renderInvPanel() {
    var panel = document.getElementById('evInvPanel');
    if (!panel) return;
    var rsvpClass = {yes:'rsvp-yes', no:'rsvp-no', maybe:'rsvp-maybe'};
    var approved   = invBase.filter(function(i){ return (i.approval_status||'approved')==='approved' && i.rsvp!=='no'; });
    var declined   = invBase.filter(function(i){ return (i.approval_status||'approved')==='approved' && i.rsvp==='no'; });
    var pending    = invBase.filter(function(i){ return (i.approval_status||'approved')==='pending'; });
    var waitlisted = invBase.filter(function(i){ return i.approval_status==='waitlisted'; });
    var ih = '';

    if (NOTIFS_ON) {
        var q = invQueue;
        if (q.pending > 0) {
            ih += '<div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:.5rem .7rem;margin-bottom:.7rem;font-size:.8rem;color:#1e40af;font-weight:600">&#9203; ' + q.pending + ' invitation' + (q.pending===1?'':'s') + ' queued &mdash; sending now&hellip; <span style="font-weight:400;color:#3b82f6">(updates automatically)</span></div>';
        } else if (sawPending) {
            ih += '<div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:.5rem .7rem;margin-bottom:.7rem;font-size:.8rem;color:#166534;font-weight:600">&#10003; All queued invitations were sent.</div>';
        }
        if (q.failed > 0) {
            ih += '<div style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:.5rem .7rem;margin-bottom:.7rem;font-size:.8rem;color:#991b1b;font-weight:600">&#9888; ' + q.failed + ' invitation' + (q.failed===1?'':'s') + ' could not be sent after several retries. <a href="/sms_log.php?event=' + EV_EID + '&status=failed" style="color:#991b1b">View delivery log</a></div>';
        }
        var unsent    = approved.filter(function(i){ return !i.sent && !i.no_contact; });
        var noContact = approved.filter(function(i){ return i.no_contact; });
        if (unsent.length) {
            ih += '<div style="display:flex;align-items:center;gap:.6rem;flex-wrap:wrap;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:.55rem .7rem;margin-bottom:.7rem">'
                + '<span style="flex:1;min-width:0;font-size:.8rem;color:#92400e;font-weight:600">&#9888; Invitations not sent to ' + unsent.length + ' ' + (unsent.length===1?'person':'people') + '</span>'
                + '<button type="button" class="btn-send-invites" style="font-size:.78rem;padding:.3rem .8rem;border-radius:6px;border:0;background:#2563eb;color:#fff;font-weight:600;cursor:pointer">Send Invitations</button></div>';
        }
        if (noContact.length) {
            ih += '<div style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:.5rem .7rem;margin-bottom:.7rem;font-size:.78rem;color:#991b1b;line-height:1.45">&#9888; ' + noContact.length + ' ' + (noContact.length===1?'invitee has':'invitees have') + ' no email or phone and can’t be notified. Edit the event to add a contact for them.</div>';
        }
        var nonresp = approved.filter(function(i){ return i.sent && !i.rsvp && !i.no_contact; });
        if (nonresp.length) {
            ih += '<div style="display:flex;align-items:center;gap:.6rem;flex-wrap:wrap;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:.55rem .7rem;margin-bottom:.7rem">'
                + '<span style="flex:1;min-width:0;font-size:.8rem;color:#475569;font-weight:600">&#9200; ' + nonresp.length + ' invited, no response yet</span>'
                + '<button type="button" class="btn-nudge" style="font-size:.78rem;padding:.3rem .8rem;border-radius:6px;border:1.5px solid #cbd5e1;background:#fff;color:#334155;font-weight:600;cursor:pointer">Send reminder</button></div>';
        }
    }

    function rsvpSel(inv) {
        var r = inv.rsvp || '';
        return '<select class="inv-rsvp-sel" data-username="' + escH(inv.username) + '">'
            + '<option value=""' + (r===''?' selected':'') + '>--</option>'
            + '<option value="yes"' + (r==='yes'?' selected':'') + '>Yes</option>'
            + '<option value="no"' + (r==='no'?' selected':'') + '>No</option>'
            + (ALLOW_MAYBE ? '<option value="maybe"' + (r==='maybe'?' selected':'') + '>Maybe</option>' : '')
            + '</select>';
    }

    if (approved.length) {
        ih += '<div style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#94a3b8;margin-bottom:.4rem">Invites (' + approved.length + ')</div>';
        ih += '<div style="display:flex;flex-direction:column;gap:.2rem;max-height:14rem;overflow-y:auto;padding-right:.25rem">';
        approved.forEach(function(inv) {
            ih += '<div style="font-size:.875rem;color:#334155;display:flex;align-items:center;gap:.5rem;flex-wrap:wrap">' + rsvpSel(inv)
                + '<span style="flex:1;min-width:0">' + escH(inv.username)
                + (inv.no_contact ? ' <span class="inv-nocontact-tag" title="No email or phone on file">no contact</span>' : '') + '</span>';
            if (inv.delivery === 'failed') {
                ih += '<span title="' + escH(inv.delivery_error || 'Delivery failed') + '" style="font-size:.68rem;font-weight:700;color:#dc2626;background:#fef2f2;border:1px solid #fecaca;border-radius:5px;padding:.1rem .4rem;cursor:help">&#10007; failed</span>';
            } else if (inv.delivery === 'sending') {
                ih += '<span title="Queued — delivery in progress" style="font-size:.68rem;font-weight:700;color:#1e40af;background:#eff6ff;border:1px solid #bfdbfe;border-radius:5px;padding:.1rem .4rem">&#9203; sending</span>';
            } else if (inv.delivery === 'delivered') {
                ih += '<span title="Handed to the provider successfully" style="font-size:.68rem;font-weight:700;color:#166534;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:5px;padding:.1rem .4rem">&#10003;</span>';
            }
            if (NOTIFS_ON && (!inv.rsvp || inv.delivery === 'failed')) {
                var lbl = inv.delivery === 'failed' ? 'Retry' : (inv.sent ? 'Resend' : 'Send');
                ih += '<button type="button" class="btn-resend-inv" data-username="' + escH(inv.username) + '" title="' + lbl + ' invite SMS/email" style="font-size:.7rem;padding:.15rem .5rem;border-radius:5px;border:1px solid #cbd5e1;background:#fff;color:#475569;font-weight:600;cursor:pointer">' + lbl + '</button>';
            }
            ih += '</div>';
        });
        ih += '</div>';
    }

    if (pending.length) {
        ih += '<div style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#d97706;margin-top:.7rem;margin-bottom:.4rem">&#9203; Pending Approval (' + pending.length + ')</div>';
        pending.forEach(function(inv) {
            ih += '<div style="font-size:.875rem;color:#334155;display:flex;align-items:center;gap:.4rem;flex-wrap:wrap;background:#fefce8;border:1px solid #fde68a;border-radius:6px;padding:.35rem .5rem;margin-bottom:.3rem">'
                + '<span style="flex:1;min-width:0">' + escH(inv.username) + '</span>'
                + '<button type="button" class="btn-approve-inv" data-username="' + escH(inv.username) + '" style="font-size:.75rem;padding:.2rem .55rem;border-radius:5px;border:0;background:#16a34a;color:#fff;font-weight:600;cursor:pointer">Approve</button>'
                + '<button type="button" class="btn-deny-inv" data-username="' + escH(inv.username) + '" style="font-size:.75rem;padding:.2rem .55rem;border-radius:5px;border:0;background:#dc2626;color:#fff;font-weight:600;cursor:pointer">Deny</button></div>';
        });
    }

    if (waitlisted.length) {
        ih += '<div style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#1e40af;margin-top:.7rem;margin-bottom:.4rem">Waitlisted (' + waitlisted.length + ')</div>';
        ih += '<div style="opacity:.7">';
        waitlisted.forEach(function(inv) { ih += '<div style="font-size:.82rem;color:#475569;padding:.15rem 0">' + escH(inv.username) + '</div>'; });
        ih += '</div>';
    }

    if (declined.length) {
        ih += '<div style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#dc2626;margin-top:.7rem;margin-bottom:.4rem">Declined (' + declined.length + ')</div>';
        ih += '<div style="opacity:.75">';
        declined.forEach(function(inv) {
            ih += '<div style="font-size:.82rem;color:#475569;display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;padding:.15rem 0">' + rsvpSel(inv)
                + '<span style="flex:1;min-width:0;text-decoration:line-through;text-decoration-color:#cbd5e1">' + escH(inv.username) + '</span></div>';
        });
        ih += '</div>';
    }

    panel.innerHTML = ih;
}

function pollInv() {
    if (document.hidden) return;
    fetch('/event_invites_dl.php?eid=' + EV_EID, { headers: {'X-Requested-With': 'XMLHttpRequest'} })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (data) {
            if (!data || !data.ok) return;
            var changed = false;
            if (JSON.stringify(invBase) !== JSON.stringify(data.base)) { invBase = data.base; changed = true; }
            if (data.invite_queue) {
                if (data.invite_queue.pending > 0) sawPending = true;
                if (JSON.stringify(invQueue) !== JSON.stringify(data.invite_queue)) { invQueue = data.invite_queue; changed = true; }
            }
            if (changed) renderInvPanel();
        }).catch(function () {});
}
pollInv();
setInterval(pollInv, 4000);

// Delegated manager actions on the panel
var evPanel = document.getElementById('evInvPanel');
if (evPanel) {
    evPanel.addEventListener('change', function (e) {
        var sel = e.target.closest('.inv-rsvp-sel');
        if (!sel) return;
        evPost('update_rsvp', { rsvp: sel.value, occurrence_date: '', target_username: sel.dataset.username })
            .then(function (res) {
                if (!res.ok) { pkAlert(res.error || 'The RSVP was not saved.'); return; }
                evToast('Saved');
                pollInv();
            }).catch(function () { pkAlert('Request failed.'); });
    });
    evPanel.addEventListener('click', async function (e) {
        var b;
        if ((b = e.target.closest('.btn-send-invites'))) {
            b.disabled = true; b.textContent = 'Sending…';
            evPost('send_invites').then(function (res) {
                if (!res.ok) { b.disabled = false; b.textContent = 'Send Invitations'; pkAlert(res.error || 'Could not send invitations.'); return; }
                if (res.sent > 0) { sawPending = true; invQueue = { pending: res.sent, dispatched: 0, failed: invQueue.failed || 0 }; evToast(res.sent + ' invitation' + (res.sent===1?'':'s') + ' queued — sending now'); renderInvPanel(); }
                else evToast('Already sent');
                pollInv();
            }).catch(function () { b.disabled = false; b.textContent = 'Send Invitations'; pkAlert('Network error.'); });
        } else if ((b = e.target.closest('.btn-nudge'))) {
            pkBusy(b, evPost('nudge_nonresponders').then(function (res) {
                if (!res.ok) { pkAlert(res.error || 'Could not send reminders.'); return; }
                if (res.sent > 0) { sawPending = true; evToast(res.sent + ' reminder' + (res.sent===1?'':'s') + ' queued — sending now'); }
                else { evToast('Everyone was already reminded today'); b.textContent = 'Reminded today'; }
                pollInv();
            }).catch(function () { pkAlert('Network error.'); }));
        } else if ((b = e.target.closest('.btn-resend-inv'))) {
            b.disabled = true; var orig = b.textContent; b.textContent = 'Sending…';
            evPost('resend_invite', { target_username: b.dataset.username }).then(function (res) {
                if (!res.ok) { b.disabled = false; b.textContent = orig; pkAlert(res.error || 'Could not resend invite.'); return; }
                b.textContent = 'Sent ✓';
                evToast('Invite queued');
                pollInv();
            }).catch(function () { b.disabled = false; b.textContent = orig; pkAlert('Network error.'); });
        } else if ((b = e.target.closest('.btn-approve-inv')) || (b = e.target.closest('.btn-deny-inv'))) {
            var isApprove = b.classList.contains('btn-approve-inv');
            b.disabled = true;
            evPost(isApprove ? 'approve_invite' : 'deny_invite', { target_username: b.dataset.username })
                .then(function (res) {
                    if (!res.ok) { b.disabled = false; pkAlert(res.error || 'Failed.'); return; }
                    evToast(isApprove ? 'Approved' : 'Denied');
                    pollInv();
                }).catch(function () { b.disabled = false; pkAlert('Network error.'); });
        }
    });
}

// Message-guests compose (Jodit), reloading after send so history refreshes
var _emEditor = null;
function _emEnsureEditor() {
    if (_emEditor || typeof Jodit === 'undefined') return;
    _emEditor = Jodit.make('#emBody', {
        height: 280, toolbarAdaptive: false,
        buttons: ['bold','italic','underline','|','ul','ol','|','link','|','paragraph','align','|','undo','redo'],
        uploader: { insertImageAsBase64URI: true },
        placeholder: 'Address, parking, what to bring, etc.'
    });
}
function openEventMsgModal() {
    document.getElementById('emAudience').value = 'yes';
    document.getElementById('emSubject').value = '';
    _emEnsureEditor();
    if (_emEditor) _emEditor.value = '';
    document.getElementById('eventMsgModal').classList.add('open');
}
function closeEventMsgModal() {
    document.getElementById('eventMsgModal').classList.remove('open');
}
function sendEventMessage() {
    var subject  = document.getElementById('emSubject').value.trim();
    var audience = document.getElementById('emAudience').value;
    var body     = _emEditor ? _emEditor.value : document.getElementById('emBody').value;
    if (!subject) { pkAlert('Please enter a subject.'); return; }
    if (!body || !body.replace(/<[^>]*>/g, '').trim()) { pkAlert('Please write a message.'); return; }
    var btn = document.getElementById('emSendBtn');
    btn.disabled = true; var orig = btn.textContent; btn.textContent = 'Sending…';
    evPost('send_event_message', { subject: subject, audience: audience, body: body })
        .then(function (res) {
            btn.disabled = false; btn.textContent = orig;
            if (!res.ok) { pkAlert(res.error || 'Could not send the message.'); return; }
            location.reload();
        }).catch(function () { btn.disabled = false; btn.textContent = orig; pkAlert('Could not reach the server.'); });
}
async function deleteEventMsg(mid, btn) {
    if (!(await pkConfirm('Delete this message? Guests will no longer be able to open its link.'))) return;
    btn.disabled = true;
    evPost('delete_event_message', { message_id: mid }).then(function (res) {
        if (!res.ok) { btn.disabled = false; pkAlert(res.error || 'Could not delete the message.'); return; }
        location.reload();
    }).catch(function () { btn.disabled = false; pkAlert('Network error.'); });
}
</script>
<?php endif; ?>
<?php require __DIR__ . '/_footer.php'; ?>
</body>
</html>
    <?php
    exit;
}

if ($token === '') {
    http_response_code(400);
    ev_show_simple('Invalid Link', 'This event link is invalid or incomplete.');
    exit;
}

// ── Look up the invite + event by token ──────────────────────────────────────
$stmt = $db->prepare('SELECT ei.id, ei.event_id, ei.username, ei.rsvp, ei.approval_status,
                             e.title, e.description, e.location, e.start_date, e.start_time, e.end_time, e.hide_guest_list, e.created_by
                      FROM event_invites ei
                      JOIN events e ON e.id = ei.event_id
                      WHERE ei.rsvp_token = ?');
$stmt->execute([$token]);
$invite = $stmt->fetch();

if (!$invite) {
    ev_show_simple('Link Expired', 'This event link is no longer valid. The event may have been updated or removed.');
    exit;
}

if (($invite['approval_status'] ?? 'approved') !== 'approved') {
    ev_show_simple('Awaiting Approval',
        'Your spot for <strong>' . htmlspecialchars($invite['title']) . '</strong> is waiting for the host to approve. '
        . 'You will receive another notification once you have been approved.');
    exit;
}

// ── Build display values ─────────────────────────────────────────────────────
$eid      = (int)$invite['event_id'];
$my_rsvp  = strtolower((string)($invite['rsvp'] ?? ''));
// Logged-in viewers see the event in THEIR timezone; logged-out invite-link viewers
// (no account tz) see it in the event creator's timezone. Labeled either way so the
// zone is unambiguous.
$_cu      = current_user();
$_tz_uid  = !empty($_cu['id']) ? (int)$_cu['id'] : ((int)($invite['created_by'] ?? 0) ?: null);
$_evt     = event_public_time_labels($invite['start_date'], $invite['start_time'] ?? null, $invite['end_time'] ?? null, $_tz_uid);
$date_lbl = $_evt['date_lbl'];
$time_lbl = $_evt['time_lbl'];

// Who's coming — approved base invitees only, display names + RSVP state (no contact info).
$attStmt = $db->prepare("SELECT username, rsvp FROM event_invites
                         WHERE event_id = ? AND occurrence_date IS NULL AND approval_status = 'approved'
                         ORDER BY COALESCE(sort_order, 999999), username");
$attStmt->execute([$eid]);
$going = []; $maybe = []; $declined = []; $pending = [];
foreach ($attStmt->fetchAll() as $a) {
    switch (strtolower((string)($a['rsvp'] ?? ''))) {
        case 'yes':   $going[]    = $a['username']; break;
        case 'maybe': $maybe[]    = $a['username']; break;
        case 'no':    $declined[] = $a['username']; break;
        default:      $pending[]  = $a['username']; break; // invited, no response yet
    }
}

$rsvp_base = '/rsvp.php?token=' . urlencode($token);
$btn_meta  = [
    'yes'   => ['Yes',   '#16a34a'],
    'maybe' => ['Maybe', '#d97706'],
    'no'    => ['No',    '#dc2626'],
];

function ev_names_block(string $label, array $names, string $color): string {
    if (empty($names)) return '';
    $out  = '<div style="margin-top:1rem"><div style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:' . $color . ';margin-bottom:.35rem">'
          . htmlspecialchars($label) . ' (' . count($names) . ')</div>';
    $out .= '<div style="display:flex;flex-wrap:wrap;gap:.35rem">';
    foreach ($names as $n) {
        $out .= '<span style="font-size:.85rem;color:#334155;background:#f1f5f9;border-radius:999px;padding:.2rem .7rem">' . htmlspecialchars($n) . '</span>';
    }
    return $out . '</div></div>';
}
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($invite['title']) ?> &ndash; <?= htmlspecialchars($site_name) ?></title>
    <link rel="stylesheet" href="/style.css?v=<?= htmlspecialchars(APP_VERSION) ?>">
    <style>
        body { background: var(--bg, #f8fafc); }
        .ev-wrap { min-height:100vh; display:flex; align-items:flex-start; justify-content:center; padding:1.5rem; }
        .ev-card { background:#fff; border-radius:14px; box-shadow:0 8px 32px rgba(0,0,0,.12); padding:1.75rem 1.75rem 1.5rem; width:100%; max-width:480px; margin-top:1rem; }
        .ev-rsvp-btn { display:inline-block; flex:1; text-align:center; padding:.6rem .5rem; border-radius:8px; text-decoration:none; font-weight:700; font-size:.95rem; border:2px solid; }
    </style>
</head>
<body>
<div class="ev-wrap">
    <div class="ev-card">
        <?php
        $logo = get_setting('logo_path', '');
        if ($logo): ?>
        <div style="text-align:center;margin-bottom:1rem"><img src="<?= htmlspecialchars($logo) ?>" alt="<?= htmlspecialchars($site_name) ?>" style="max-height:44px"></div>
        <?php else: ?>
        <div style="text-align:center;font-size:.82rem;color:#64748b;margin-bottom:1rem"><?= htmlspecialchars($site_name) ?></div>
        <?php endif; ?>

        <?php if (in_array($just, $validRsvp, true)): ?>
        <div style="background:#f0fdf4;border:1.5px solid #86efac;border-radius:8px;padding:.7rem 1rem;color:#166534;font-size:.9rem;margin-bottom:1.1rem">
            &#10003; Your RSVP is set to <strong><?= htmlspecialchars(ucfirst($just)) ?></strong>.
        </div>
        <?php endif; ?>

        <h1 style="font-size:1.45rem;font-weight:800;color:#1e293b;margin:0 0 .5rem"><?= htmlspecialchars($invite['title']) ?></h1>
        <div style="font-size:.95rem;color:#475569;margin-bottom:.15rem"><?= htmlspecialchars($date_lbl) ?></div>
        <?php if ($time_lbl !== ''): ?>
        <div style="font-size:.95rem;color:#475569"><?= $time_lbl ?></div>
        <?php endif; ?>
        <?php if (!empty($invite['location'])): ?>
        <div style="font-size:.9rem;color:#475569;margin-top:.35rem">
            &#128205; <?= htmlspecialchars($invite['location']) ?>
            &middot; <a href="https://www.google.com/maps/search/?api=1&amp;query=<?= urlencode($invite['location']) ?>" target="_blank" rel="noopener">Open in Maps</a>
        </div>
        <?php endif; ?>
        <div style="font-size:.85rem;color:#64748b;margin-top:.45rem">
            &#128197; Add to calendar:
            <a href="/ics.php?token=<?= urlencode($token) ?>">Apple / Outlook</a>
            &middot; <a href="/ics.php?token=<?= urlencode($token) ?>&amp;google=1" target="_blank" rel="noopener">Google</a>
        </div>

        <?php if (!empty($invite['description'])): ?>
        <div style="margin-top:1rem;font-size:.95rem;color:#334155;line-height:1.55"><?= nl2br(htmlspecialchars($invite['description'])) ?></div>
        <?php endif; ?>

        <!-- RSVP buttons -->
        <div style="margin-top:1.5rem">
            <div style="font-size:.8rem;font-weight:600;color:#64748b;margin-bottom:.5rem">
                <?php if ($my_rsvp !== '' && isset($btn_meta[$my_rsvp])): ?>
                    You're going as <strong style="color:<?= $btn_meta[$my_rsvp][1] ?>"><?= htmlspecialchars($btn_meta[$my_rsvp][0]) ?></strong> &middot; tap to change:
                <?php else: ?>
                    Will you make it?
                <?php endif; ?>
            </div>
            <div style="display:flex;gap:.5rem">
                <?php foreach ($validRsvp as $opt):
                    [$lbl, $col] = $btn_meta[$opt];
                    $active = ($opt === $my_rsvp);
                    $style  = $active
                        ? 'background:' . $col . ';color:#fff;border-color:' . $col
                        : 'background:#fff;color:' . $col . ';border-color:' . $col;
                ?>
                <a class="ev-rsvp-btn" style="<?= $style ?>" href="<?= htmlspecialchars($rsvp_base . '&r=' . $opt) ?>"><?= htmlspecialchars($lbl) ?></a>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Who's coming. With hide_guest_list the roster stays private but the
             headcount still shows, so guests know the party is happening. -->
        <?php
        $whos = empty($invite['hide_guest_list'])
              ? ev_names_block('Going', $going, '#16a34a')
              . ev_names_block('Maybe', $maybe, '#d97706')
              . ev_names_block('Invited', $pending, '#64748b')
              . ev_names_block("Can't make it", $declined, '#94a3b8')
              : '';
        if ($whos !== ''): ?>
        <div style="margin-top:1.5rem;padding-top:1.25rem;border-top:1px solid #e2e8f0"><?= $whos ?></div>
        <?php elseif (count($going) > 0): ?>
        <div style="margin-top:1.5rem;padding-top:1.25rem;border-top:1px solid #e2e8f0;font-size:.9rem;color:#475569">
            <strong style="color:#16a34a"><?= count($going) ?></strong> going<?= count($maybe) > 0 ? ' · ' . count($maybe) . ' maybe' : '' ?>
        </div>
        <?php endif; ?>

        <!-- Optional: full account features -->
        <div style="margin-top:1.5rem;padding-top:1.25rem;border-top:1px solid #e2e8f0">
            <p style="color:#64748b;font-size:.84rem;margin:0 0 .6rem">Want comments and the full calendar?</p>
            <?php
            $month_str = substr($invite['start_date'], 0, 7);
            $event_redirect = '/calendar.php?m=' . urlencode($month_str) . '&open=' . $eid . '&date=' . urlencode($invite['start_date']);
            $has_account = false;
            $uChk = $db->prepare('SELECT 1 FROM users WHERE LOWER(username) = LOWER(?)');
            $uChk->execute([$invite['username']]);
            $has_account = (bool)$uChk->fetchColumn();
            $allow_reg = get_setting('allow_registration', '1') === '1';
            ?>
            <a href="/login.php?redirect=<?= urlencode($event_redirect) ?>" style="display:inline-block;margin:.2rem .3rem .2rem 0;padding:.45rem 1.2rem;border-radius:6px;text-decoration:none;font-weight:600;background:#2563eb;color:#fff;font-size:.88rem">Log in</a>
            <?php if (!$has_account && $allow_reg): ?>
            <a href="/register.php?redirect=<?= urlencode($event_redirect) ?>" style="display:inline-block;margin:.2rem .3rem;padding:.45rem 1.2rem;border-radius:6px;text-decoration:none;font-weight:600;border:2px solid #2563eb;color:#2563eb;background:#fff;font-size:.88rem">Create Account</a>
            <?php endif; ?>
        </div>

        <!-- Exit / close the public event page -->
        <div style="text-align:center;margin-top:1.5rem">
            <a href="/" style="color:#94a3b8;text-decoration:none;font-size:.84rem">Done &middot; Go to <?= htmlspecialchars($site_name) ?></a>
        </div>
    </div>
</div>
</body>
</html>
