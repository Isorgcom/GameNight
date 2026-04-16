<?php
require_once __DIR__ . '/auth.php';

$current   = require_login();
$db        = get_db();

// Handle range changes from inline selects
$allowed_past = [7,14,30,60,90,180,365];
if (isset($_GET['past_days'])) {
    if (in_array((int)$_GET['past_days'], $allowed_past)) {
        $db->prepare('UPDATE users SET my_events_past_days = ? WHERE id = ?')->execute([(int)$_GET['past_days'], $current['id']]);
    }
    header('Location: /my_events.php');
    exit;
}

$site_name = get_setting('site_name', 'Game Night');
$local_tz  = new DateTimeZone(get_setting('timezone', 'UTC'));
$now       = new DateTime('now', $local_tz);
$past_days   = (int)($current['my_events_past_days'] ?? 30);
$cutoff_past = (clone $now)->modify("-{$past_days} days")->format('Y-m-d');

// All events the user is invited to, created, or can see via league membership.
$stmt = $db->prepare("
    SELECT e.id, e.title, e.description, e.start_date, e.end_date,
           e.start_time, e.end_time, e.color, e.created_by, e.is_poker,
           e.league_id, e.visibility,
           ei.rsvp, ei.approval_status,
           CASE WHEN e.created_by = :uid THEN 1 ELSE 0 END AS is_creator
    FROM events e
    LEFT JOIN event_invites ei ON ei.event_id = e.id AND LOWER(ei.username) = LOWER(:uname)
    WHERE e.created_by = :uid2
       OR ei.id IS NOT NULL
       OR (e.visibility = 'league' AND e.league_id IN (SELECT league_id FROM league_members WHERE user_id = :uid3))
    GROUP BY e.id
    ORDER BY e.start_date ASC, e.start_time ASC
");
$stmt->execute([':uid' => $current['id'], ':uname' => $current['username'], ':uid2' => $current['id'], ':uid3' => $current['id']]);
$all_events = $stmt->fetchAll();

// Split into upcoming and past using full datetime (end_time > start_time > end of day)
$upcoming = [];
$past     = [];
foreach ($all_events as $ev) {
    $ev_end_time = $ev['end_time'] ?: $ev['start_time'] ?: '23:59';
    $ev_end_date = $ev['end_date'] ?: $ev['start_date'];
    $ev_end = new DateTime($ev_end_date . ' ' . $ev_end_time, $local_tz);
    if ($ev_end >= $now) {
        $upcoming[] = $ev;
    } else {
        if ($ev['start_date'] >= $cutoff_past) {
            $past[] = $ev;
        }
    }
}
// Past events: most recent event date first
usort($past, function($a, $b) use ($local_tz) {
    $da = new DateTime($a['start_date'] . ' ' . ($a['start_time'] ?? '00:00'), $local_tz);
    $db_dt = new DateTime($b['start_date'] . ' ' . ($b['start_time'] ?? '00:00'), $local_tz);
    return $db_dt <=> $da;
});

$token = csrf_token();

function fmt_date(string $date, ?string $time, DateTimeZone $tz): string {
    $dt = new DateTime($date . ($time ? ' ' . $time : ''), $tz);
    return $dt->format('D, M j, Y') . ($time ? ' &middot; ' . $dt->format('g:i A') : '');
}

function rsvp_badge(?string $rsvp, ?string $approval_status = 'approved'): string {
    // Pending self-signups display a "waiting for approval" badge instead of an RSVP state.
    if ($approval_status === 'pending') {
        return '<span class="me-badge" style="background:#fefce8;color:#854d0e;border:1px solid #fde68a;border-radius:4px;padding:.1rem .5rem;font-size:.75rem;font-weight:600">⏳ Awaiting approval</span>';
    }
    if ($rsvp === 'yes')   return '<span class="me-badge" style="background:#dcfce7;color:#166534;border-radius:4px;padding:.1rem .5rem;font-size:.75rem;font-weight:600">Yes</span>';
    if ($rsvp === 'no')    return '<span class="me-badge" style="background:#fee2e2;color:#991b1b;border-radius:4px;padding:.1rem .5rem;font-size:.75rem;font-weight:600">No</span>';
    if ($rsvp === 'maybe') return '<span class="me-badge" style="background:#fef9c3;color:#854d0e;border-radius:4px;padding:.1rem .5rem;font-size:.75rem;font-weight:600">Maybe</span>';
    return '<span class="me-badge" style="background:#f1f5f9;color:#64748b;border-radius:4px;padding:.1rem .5rem;font-size:.75rem;font-weight:600">No response</span>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Events — <?= htmlspecialchars($site_name) ?></title>
    <link rel="stylesheet" href="/style.css">
    <style>
        @media (max-width: 1024px) {
            .me-view-btn { min-height:44px !important;font-size:.9rem !important;padding:.5rem .85rem !important;display:inline-flex;align-items:center; }
            .me-badge { padding:.2rem .6rem !important;font-size:.8rem !important; }
        }
    </style>
</head>
<body>

<?php $nav_active = 'my-events'; require __DIR__ . '/_nav.php'; ?>

<div style="max-width:760px;margin:2rem auto;padding:0 1rem">

    <div style="display:flex;align-items:center;flex-wrap:wrap;gap:.75rem;margin-bottom:1.75rem">
        <h2 style="font-size:1.4rem;font-weight:700;color:#1e293b;margin:0">My Events</h2>
        <div style="margin-left:auto;display:flex;align-items:center;gap:.5rem;font-size:.8rem;color:#64748b">
            <label>Past:
                <select onchange="window.location='/my_events.php?past_days='+this.value" style="padding:.2rem .4rem;border:1px solid #e2e8f0;border-radius:5px;font-size:.8rem;background:#fff">
                    <?php foreach ([7=>'7d',14=>'14d',30=>'30d',60=>'60d',90=>'90d',180=>'6mo',365=>'1yr'] as $v=>$l): ?>
                    <option value="<?= $v ?>"<?= $past_days === $v ? ' selected' : '' ?>><?= $l ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
    </div>

    <!-- Upcoming -->
    <h3 style="font-size:.8rem;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:.07em;margin-bottom:.75rem">
        Upcoming &mdash; <?= count($upcoming) ?>
    </h3>

    <?php if (empty($upcoming)): ?>
    <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:1.5rem;text-align:center;color:#94a3b8;margin-bottom:2rem">
        No upcoming events.
    </div>
    <?php else: ?>
    <div style="display:flex;flex-direction:column;gap:.75rem;margin-bottom:2rem">
        <?php foreach ($upcoming as $ev): ?>
        <?php
            $month_str = substr($ev['start_date'], 0, 7);
            $cal_url   = '/calendar.php?m=' . urlencode($month_str) . '&open=' . $ev['id'] . '&date=' . urlencode($ev['start_date']);
        ?>
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:1rem 1.25rem;display:flex;align-items:flex-start;gap:1rem;border-left:4px solid <?= htmlspecialchars($ev['color']) ?>">
            <div style="flex:1;min-width:0">
                <div style="display:flex;align-items:center;gap:.6rem;flex-wrap:wrap;margin-bottom:.25rem">
                    <a href="<?= htmlspecialchars($cal_url) ?>"
                       style="font-weight:600;color:#1e293b;text-decoration:none;font-size:1rem;line-height:1.3">
                        <?= htmlspecialchars($ev['title']) ?>
                    </a>
                    <?= rsvp_badge($ev['rsvp'], $ev['approval_status'] ?? 'approved') ?>
                    <?php if ($ev['is_creator']): ?>
                    <span class="me-badge" style="background:#ede9fe;color:#5b21b6;border-radius:4px;padding:.1rem .5rem;font-size:.75rem;font-weight:600">Organizer</span>
                    <?php if (!empty($ev['is_poker'])): ?>
                    <a href="/checkin.php?event_id=<?= $ev['id'] ?>" class="me-badge" style="background:#059669;color:#fff;border-radius:4px;padding:.1rem .5rem;font-size:.75rem;font-weight:600;text-decoration:none">Manage Game</a>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
                <div style="font-size:.85rem;color:#64748b">
                    <?= fmt_date($ev['start_date'], $ev['start_time'], $local_tz) ?>
                    <?php if ($ev['end_date'] && $ev['end_date'] !== $ev['start_date']): ?>
                    &ndash; <?= fmt_date($ev['end_date'], $ev['end_time'], $local_tz) ?>
                    <?php elseif ($ev['end_time']): ?>
                    &ndash; <?= (new DateTime($ev['start_date'] . ' ' . $ev['end_time'], $local_tz))->format('g:i A') ?>
                    <?php endif; ?>
                </div>
                <?php if (!empty($ev['description'])): ?>
                <div style="font-size:.825rem;color:#94a3b8;margin-top:.3rem;white-space:pre-wrap;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical">
                    <?= htmlspecialchars($ev['description']) ?>
                </div>
                <?php endif; ?>
            </div>
            <a href="<?= htmlspecialchars($cal_url) ?>"
               class="me-view-btn" style="flex-shrink:0;font-size:.8rem;color:#2563eb;text-decoration:none;white-space:nowrap;padding:.3rem .7rem;border:1px solid #bfdbfe;border-radius:6px">
                View
            </a>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Past -->
    <h3 style="font-size:.8rem;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:.07em;margin-bottom:.75rem">
        Past &mdash; <?= count($past) ?>
    </h3>

    <?php if (empty($past)): ?>
    <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:1.5rem;text-align:center;color:#94a3b8">
        No past events.
    </div>
    <?php else: ?>
    <div style="display:flex;flex-direction:column;gap:.75rem">
        <?php foreach ($past as $ev): ?>
        <?php
            $month_str = substr($ev['start_date'], 0, 7);
            $cal_url   = '/calendar.php?m=' . urlencode($month_str) . '&open=' . $ev['id'] . '&date=' . urlencode($ev['start_date']);
        ?>
        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:1rem 1.25rem;display:flex;align-items:flex-start;gap:1rem;border-left:4px solid #cbd5e1;opacity:.8">
            <div style="flex:1;min-width:0">
                <div style="display:flex;align-items:center;gap:.6rem;flex-wrap:wrap;margin-bottom:.25rem">
                    <a href="<?= htmlspecialchars($cal_url) ?>"
                       style="font-weight:600;color:#475569;text-decoration:none;font-size:1rem;line-height:1.3">
                        <?= htmlspecialchars($ev['title']) ?>
                    </a>
                    <?= rsvp_badge($ev['rsvp'], $ev['approval_status'] ?? 'approved') ?>
                    <?php if ($ev['is_creator']): ?>
                    <span style="background:#f3f4f6;color:#6b7280;border-radius:4px;padding:.1rem .5rem;font-size:.75rem;font-weight:600">Organizer</span>
                    <?php if (!empty($ev['is_poker'])): ?>
                    <a href="/checkin.php?event_id=<?= $ev['id'] ?>" style="background:#059669;color:#fff;border-radius:4px;padding:.1rem .5rem;font-size:.75rem;font-weight:600;text-decoration:none">Manage Game</a>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
                <div style="font-size:.85rem;color:#94a3b8">
                    <?= fmt_date($ev['start_date'], $ev['start_time'], $local_tz) ?>
                </div>
            </div>
            <a href="<?= htmlspecialchars($cal_url) ?>"
               style="flex-shrink:0;font-size:.8rem;color:#64748b;text-decoration:none;white-space:nowrap;padding:.3rem .7rem;border:1px solid #e2e8f0;border-radius:6px">
                View
            </a>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

</div>

<?php require __DIR__ . '/_footer.php'; ?>
</body>
</html>
