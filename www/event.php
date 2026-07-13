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

if ($token === '') {
    http_response_code(400);
    ev_show_simple('Invalid Link', 'This event link is invalid or incomplete.');
    exit;
}

// ── Look up the invite + event by token ──────────────────────────────────────
$stmt = $db->prepare('SELECT ei.id, ei.event_id, ei.username, ei.rsvp, ei.approval_status,
                             e.title, e.description, e.start_date, e.start_time, e.end_time, e.hide_guest_list, e.created_by
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

        <!-- Who's coming -->
        <?php
        $whos = empty($invite['hide_guest_list'])
              ? ev_names_block('Going', $going, '#16a34a')
              . ev_names_block('Maybe', $maybe, '#d97706')
              . ev_names_block('Invited', $pending, '#64748b')
              . ev_names_block("Can't make it", $declined, '#94a3b8')
              : '';
        if ($whos !== ''): ?>
        <div style="margin-top:1.5rem;padding-top:1.25rem;border-top:1px solid #e2e8f0"><?= $whos ?></div>
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
