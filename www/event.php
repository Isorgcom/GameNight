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
    $cq = $db->prepare("SELECT c.id, c.body, c.created_at, u.username
                        FROM comments c JOIN users u ON u.id = c.user_id
                        WHERE c.type = 'event' AND c.content_id = ?
                        ORDER BY c.created_at ASC, c.id ASC");
    $cq->execute([$page_eid]);
    $comments = $cq->fetchAll();

    $csrf     = csrf_token();
    $selfUrl  = '/event.php?id=' . $page_eid;
    $calUrl   = '/calendar.php?m=' . urlencode(substr($ev['start_date'], 0, 7)) . '&open=' . $page_eid . '&date=' . urlencode($ev['start_date']);
    $myRsvp   = $myInv ? strtolower((string)($myInv['rsvp'] ?? '')) : '';
    $isInvited   = $myInv !== null;
    $isPendingMe = $myInv && ($myInv['approval_status'] ?? 'approved') === 'pending';
    $isWaitMe    = $myInv && ($myInv['approval_status'] ?? 'approved') === 'waitlisted';
    $isCreator   = (int)$ev['created_by'] === (int)$current['id'];
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
        <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-top:1.1rem">
            <a class="btn btn-primary" style="text-decoration:none" href="/event_edit.php?id=<?= $page_eid ?>">Edit</a>
            <a class="btn btn-outline" style="text-decoration:none" href="/event_edit.php?copy=<?= $page_eid ?>">Duplicate</a>
            <?php if ((int)$ev['is_poker'] === 1): ?>
            <a class="btn" style="background:#059669;color:#fff;text-decoration:none" href="/checkin.php?event_id=<?= $page_eid ?>">Manage Game</a>
            <?php endif; ?>
            <a class="btn btn-outline" style="text-decoration:none" href="/event_polls.php?event_id=<?= $page_eid ?>">Polls</a>
        </div>
        <?php endif; ?>

        <!-- RSVP -->
        <div style="margin-top:1.4rem;padding-top:1.1rem;border-top:1px solid #e2e8f0">
            <?php if ($isPendingMe): ?>
                <div style="font-size:.9rem;color:#92400e;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:.55rem .7rem">Your sign-up is waiting for host approval.</div>
            <?php elseif ($isWaitMe): ?>
                <div style="font-size:.9rem;color:#92400e;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:.55rem .7rem">You are on the waitlist for this event.</div>
            <?php elseif ($isInvited): ?>
                <label style="font-size:.85rem;font-weight:600;color:#475569">My RSVP
                    <select id="evpRsvp" style="margin-left:.5rem;padding:.35rem .55rem;border:1.5px solid #e2e8f0;border-radius:6px">
                        <option value="" <?= $myRsvp === '' ? 'selected' : '' ?>>&mdash;</option>
                        <option value="yes" <?= $myRsvp === 'yes' ? 'selected' : '' ?>>Yes</option>
                        <?php if ($allowMaybe): ?><option value="maybe" <?= $myRsvp === 'maybe' ? 'selected' : '' ?>>Maybe</option><?php endif; ?>
                        <option value="no" <?= $myRsvp === 'no' ? 'selected' : '' ?>>No</option>
                    </select>
                </label>
                <span id="evpSaved" style="visibility:hidden;margin-left:.6rem;color:#166534;background:#dcfce7;border-radius:6px;padding:.2rem .6rem;font-size:.8rem;font-weight:600">Saved</span>
                <?php if (!$isCreator): ?>
                <button type="button" class="btn btn-outline btn-sm" id="evpLeaveBtn" style="margin-left:.75rem">Leave this event</button>
                <?php endif; ?>
            <?php else: ?>
                <button type="button" class="btn btn-primary" id="evpSignupBtn">Sign up to attend</button>
            <?php endif; ?>
        </div>

        <!-- Who's coming -->
        <?php if (empty($ev['hide_guest_list']) || $canManage): ?>
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

        <!-- Comments -->
        <div style="margin-top:1.25rem;padding-top:1.1rem;border-top:1px solid #e2e8f0">
            <div style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#94a3b8;margin-bottom:.3rem">
                <?= count($comments) ?> <?= count($comments) === 1 ? 'Comment' : 'Comments' ?>
            </div>
            <?php foreach ($comments as $c): ?>
            <div class="evp-comment">
                <span class="who"><?= htmlspecialchars($c['username']) ?></span><span class="when"><?= htmlspecialchars($c['created_at']) ?></span>
                <div class="body"><?= htmlspecialchars($c['body']) ?></div>
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
    var sel = document.getElementById('evpRsvp');
    if (sel) sel.addEventListener('change', function () {
        post('update_rsvp', { rsvp: sel.value, occurrence_date: '' }).then(function (res) {
            if (!res.ok) { pkAlert(res.error || 'Request failed — your RSVP was not saved.'); return; }
            var s = document.getElementById('evpSaved');
            s.style.visibility = 'visible';
            setTimeout(function () { s.style.visibility = 'hidden'; }, 3000);
        }).catch(function () { pkAlert('Request failed — your RSVP was not saved.'); });
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
})();
</script>
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
