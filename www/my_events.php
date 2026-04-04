<?php
require_once __DIR__ . '/auth.php';

$current   = require_login();
$db        = get_db();
$site_name = get_setting('site_name', 'Game Night');
$local_tz  = new DateTimeZone(get_setting('timezone', 'UTC'));
$today     = (new DateTime('now', $local_tz))->format('Y-m-d');

// All events the user is invited to OR created, with their RSVP status
// UNION deduplicates: invited rows take priority (have RSVP); created-only rows get null RSVP
$stmt = $db->prepare("
    SELECT e.id, e.title, e.description, e.start_date, e.end_date,
           e.start_time, e.end_time, e.color, e.created_by,
           ei.rsvp,
           CASE WHEN e.created_by = :uid THEN 1 ELSE 0 END AS is_creator
    FROM events e
    LEFT JOIN event_invites ei ON ei.event_id = e.id AND LOWER(ei.username) = LOWER(:uname)
    WHERE e.created_by = :uid2 OR ei.id IS NOT NULL
    GROUP BY e.id
    ORDER BY e.start_date ASC, e.start_time ASC
");
$stmt->execute([':uid' => $current['id'], ':uname' => $current['username'], ':uid2' => $current['id']]);
$all_events = $stmt->fetchAll();

// Split into upcoming and past
$upcoming = [];
$past     = [];
foreach ($all_events as $ev) {
    if ($ev['start_date'] >= $today) {
        $upcoming[] = $ev;
    } else {
        $past[] = $ev;
    }
}
// Past events: most recent first
$past = array_reverse($past);

$token = csrf_token();

function fmt_date(string $date, ?string $time, DateTimeZone $tz): string {
    $dt = new DateTime($date . ($time ? ' ' . $time : ''), $tz);
    return $dt->format('D, M j, Y') . ($time ? ' &middot; ' . $dt->format('g:i A') : '');
}

function rsvp_badge(?string $rsvp): string {
    if ($rsvp === 'yes')   return '<span style="background:#dcfce7;color:#166534;border-radius:4px;padding:.1rem .5rem;font-size:.75rem;font-weight:600">Yes</span>';
    if ($rsvp === 'no')    return '<span style="background:#fee2e2;color:#991b1b;border-radius:4px;padding:.1rem .5rem;font-size:.75rem;font-weight:600">No</span>';
    if ($rsvp === 'maybe') return '<span style="background:#fef9c3;color:#854d0e;border-radius:4px;padding:.1rem .5rem;font-size:.75rem;font-weight:600">Maybe</span>';
    return '<span style="background:#f1f5f9;color:#64748b;border-radius:4px;padding:.1rem .5rem;font-size:.75rem;font-weight:600">No response</span>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Events — <?= htmlspecialchars($site_name) ?></title>
    <link rel="stylesheet" href="/style.css">
</head>
<body>

<?php $nav_active = 'my-events'; require __DIR__ . '/_nav.php'; ?>

<div style="max-width:760px;margin:2rem auto;padding:0 1rem">

    <h2 style="font-size:1.4rem;font-weight:700;color:#1e293b;margin-bottom:1.75rem">My Events</h2>

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
                    <?= rsvp_badge($ev['rsvp']) ?>
                    <?php if ($ev['is_creator']): ?>
                    <span style="background:#ede9fe;color:#5b21b6;border-radius:4px;padding:.1rem .5rem;font-size:.75rem;font-weight:600">Organizer</span>
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
               style="flex-shrink:0;font-size:.8rem;color:#2563eb;text-decoration:none;white-space:nowrap;padding:.3rem .7rem;border:1px solid #bfdbfe;border-radius:6px">
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
                    <?= rsvp_badge($ev['rsvp']) ?>
                    <?php if ($ev['is_creator']): ?>
                    <span style="background:#f3f4f6;color:#6b7280;border-radius:4px;padding:.1rem .5rem;font-size:.75rem;font-weight:600">Organizer</span>
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
