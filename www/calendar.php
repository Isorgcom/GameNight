<?php
require_once __DIR__ . '/auth.php';

$db      = get_db();
$current = current_user();
$isAdmin = $current && $current['role'] === 'admin';
$allUsers = $db->query('SELECT username, email, phone FROM users ORDER BY username')->fetchAll();

if (get_setting('show_calendar', '1') !== '1') {
    http_response_code(403);
    exit('Calendar is disabled.');
}

$site_name = get_setting('site_name', 'Game Night');
$local_tz  = new DateTimeZone(get_setting('timezone', 'UTC'));

session_start_safe();
$flash = ['type' => '', 'msg' => ''];
if (!empty($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
}

// ── POST ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Invalid request token.'];
        header('Location: /calendar.php');
        exit;
    }

    $action        = $_POST['action'] ?? '';

    // Non-admins may only update their own RSVP or self-signup
    if (!$isAdmin && $action !== 'update_rsvp' && $action !== 'self_signup') {
        http_response_code(403); exit('Access denied.');
    }
    $inv_usernames = array_map('trim', (array)($_POST['invite_username'] ?? []));
    $inv_phones    = array_map('trim', (array)($_POST['invite_phone']    ?? []));
    $inv_emails    = array_map('trim', (array)($_POST['invite_email']    ?? []));
    $inv_rsvps     = array_map('trim', (array)($_POST['invite_rsvp']     ?? []));
    $valid_rsvps   = ['', 'yes', 'no', 'maybe'];
    $save_invites  = function(int $eid) use ($db, $inv_usernames, $inv_phones, $inv_emails, $inv_rsvps, $valid_rsvps): void {
        $db->prepare('DELETE FROM event_invites WHERE event_id=?')->execute([$eid]);
        $ins = $db->prepare('INSERT INTO event_invites (event_id, username, phone, email, rsvp) VALUES (?, ?, ?, ?, ?)');
        for ($i = 0; $i < count($inv_usernames); $i++) {
            if ($inv_usernames[$i] === '') continue;
            $rsvp = in_array($inv_rsvps[$i] ?? '', $valid_rsvps, true) ? ($inv_rsvps[$i] ?: null) : null;
            $phone_norm = $inv_phones[$i] !== '' ? normalize_phone($inv_phones[$i]) : '';
            $ins->execute([$eid, strtolower($inv_usernames[$i]), $phone_norm ?: null, $inv_emails[$i] ?: null, $rsvp]);
        }
    };

    if ($action === 'add' || $action === 'edit') {
        $id    = (int)($_POST['id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $desc  = trim($_POST['description'] ?? '');
        $sd    = trim($_POST['start_date'] ?? '');
        $ed    = trim($_POST['end_date'] ?? '') ?: null;
        $st    = trim($_POST['start_time'] ?? '') ?: null;
        $et    = trim($_POST['end_time'] ?? '') ?: null;
        $color = in_array($_POST['color'] ?? '', ['#2563eb','#16a34a','#dc2626','#d97706','#7c3aed','#0891b2','#db2777'])
                 ? $_POST['color'] : '#2563eb';
        $recurrence = in_array($_POST['recurrence'] ?? '', ['none','daily','weekly','monthly','yearly'])
                      ? $_POST['recurrence'] : 'none';
        $recEnd = trim($_POST['recurrence_end'] ?? '') ?: null;

        if ($title === '' || $sd === '') {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Title and start date are required.'];
        } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $sd) || ($ed && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $ed))) {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Invalid date format.'];
        } else {
            $notify_invitees = !empty($_POST['notify_invitees']);
            if ($action === 'add') {
                $db->prepare('INSERT INTO events (title, description, start_date, end_date, start_time, end_time, color, recurrence, recurrence_end, created_by)
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                   ->execute([$title, $desc ?: null, $sd, $ed, $st, $et, $color, $recurrence, $recEnd, $current['id']]);
                $notify_eid = (int)$db->lastInsertId();
                $save_invites($notify_eid);
                db_log_activity($current['id'], "created event: $title");
                $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Event added.'];
            } else {
                $db->prepare('UPDATE events SET title=?, description=?, start_date=?, end_date=?, start_time=?, end_time=?, color=?, recurrence=?, recurrence_end=? WHERE id=?')
                   ->execute([$title, $desc ?: null, $sd, $ed, $st, $et, $color, $recurrence, $recEnd, $id]);
                $notify_eid = $id;
                $save_invites($id);
                db_log_activity($current['id'], "edited event id: $id");
                $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Event updated.'];
            }
            if ($notify_invitees) {
                require_once __DIR__ . '/mail.php';
                $date_str = $sd . ($st ? ' at ' . date('g:i A', strtotime($st)) : '');
                $subject  = 'You\'re invited: ' . $title;
                $html     = '<p>You have been invited to <strong>' . htmlspecialchars($title) . '</strong> on ' . htmlspecialchars($date_str) . '.</p>'
                          . ($desc ? '<p>' . nl2br(htmlspecialchars($desc)) . '</p>' : '')
                          . '<p>Log in to ' . htmlspecialchars(get_setting('site_name', 'Game Night')) . ' to view the event and RSVP.</p>';
                $inv_stmt = $db->prepare('SELECT email FROM event_invites WHERE event_id=? AND email IS NOT NULL AND email != \'\'');
                $inv_stmt->execute([$notify_eid]);
                foreach ($inv_stmt->fetchAll(PDO::FETCH_COLUMN) as $inv_email) {
                    send_email($inv_email, $inv_email, $subject, $html);
                }
            }
        }
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $row = $db->prepare('SELECT title FROM events WHERE id=?');
            $row->execute([$id]);
            $t = $row->fetchColumn() ?? $id;
            $db->prepare('DELETE FROM event_exceptions WHERE event_id=?')->execute([$id]);
            $db->prepare('DELETE FROM event_invites WHERE event_id=?')->execute([$id]);
            $db->prepare('DELETE FROM events WHERE id=?')->execute([$id]);
            db_log_activity($current['id'], "deleted event: $t");
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Event deleted.'];
        }
    }

    if ($action === 'delete_occurrence') {
        $id   = (int)($_POST['id'] ?? 0);
        $date = trim($_POST['occurrence_date'] ?? '');
        if ($id > 0 && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $db->prepare('INSERT OR IGNORE INTO event_exceptions (event_id, date) VALUES (?, ?)')
               ->execute([$id, $date]);
            db_log_activity($current['id'], "removed occurrence $date from event id: $id");
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Occurrence removed.'];
        }
    }

    if ($action === 'update_rsvp' && $current) {
        $eid  = (int)($_POST['event_id'] ?? 0);
        $rsvp = in_array($_POST['rsvp'] ?? '', ['', 'yes', 'no', 'maybe'], true) ? ($_POST['rsvp'] ?: null) : null;
        if ($eid > 0) {
            $db->prepare('UPDATE event_invites SET rsvp=? WHERE event_id=? AND LOWER(username)=LOWER(?)')
               ->execute([$rsvp, $eid, $current['username']]);
            db_log_activity($current['id'], "updated RSVP for event id: $eid");

            // Notify event creator about the RSVP
            if ($rsvp) {
                $evRow = $db->prepare('SELECT e.title, e.start_date, u.email, u.phone, u.preferred_contact, u.username FROM events e JOIN users u ON u.id=e.created_by WHERE e.id=?');
                $evRow->execute([$eid]);
                $creator = $evRow->fetch();
                if ($creator && strtolower($creator['username']) !== strtolower($current['username'])) {
                    require_once __DIR__ . '/auth_dl.php';
                    $rsvpLabel = ucfirst($rsvp);
                    $smsBody = $current['username'] . " RSVPed $rsvpLabel to \"" . $creator['title'] . '" on ' . $creator['start_date'];
                    $htmlBody = '<p><strong>' . htmlspecialchars($current['username']) . '</strong> RSVPed <strong>' . $rsvpLabel . '</strong> to '
                              . '<em>' . htmlspecialchars($creator['title']) . '</em> on ' . htmlspecialchars($creator['start_date']) . '.</p>';
                    send_notification($creator['username'], $creator['email'] ?? '', $creator['phone'] ?? '',
                        $creator['preferred_contact'] ?? 'email',
                        $current['username'] . " RSVPed $rsvpLabel: " . $creator['title'],
                        $smsBody, $htmlBody);
                }
            }
        }
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
            header('Content-Type: application/json');
            echo json_encode(['ok' => true]);
            exit;
        }
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'RSVP updated.'];
    }

    if ($action === 'self_signup' && $current) {
        $eid  = (int)($_POST['event_id'] ?? 0);
        $urow = $db->prepare('SELECT phone, email FROM users WHERE id=?');
        $urow->execute([$current['id']]);
        $udata = $urow->fetch();
        if ($eid > 0) {
            $chk = $db->prepare('SELECT id FROM event_invites WHERE event_id=? AND LOWER(username)=LOWER(?)');
            $chk->execute([$eid, $current['username']]);
            if (!$chk->fetch()) {
                $db->prepare('INSERT INTO event_invites (event_id, username, phone, email, rsvp) VALUES (?, ?, ?, ?, NULL)')
                   ->execute([$eid, strtolower($current['username']), $udata['phone'] ?? null, $udata['email'] ?? null]);
                db_log_activity($current['id'], "signed up for event id: $eid");
            }
        }
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
            $inv = ['username' => strtolower($current['username']), 'rsvp' => null];
            if ($isAdmin) { $inv['phone'] = $udata['phone'] ?? null; $inv['email'] = $udata['email'] ?? null; }
            header('Content-Type: application/json');
            echo json_encode(['ok' => true, 'invite' => $inv]);
            exit;
        }
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'You have been added to the event.'];
    }

    $back = $_POST['month_param'] ?? '';
    header('Location: /calendar.php' . ($back ? '?m=' . urlencode($back) : ''));
    exit;
}

// ── Auto-open event (e.g. after login redirect) ───────────────────────────────
$autoOpenEvent = null;
if (!empty($_GET['event']) && ctype_digit((string)$_GET['event'])) {
    $aoRow = $db->prepare('SELECT * FROM events WHERE id = ?');
    $aoRow->execute([(int)$_GET['event']]);
    $aoRow = $aoRow->fetch();
    if ($aoRow) {
        $aoRow['recurrence']     = $aoRow['recurrence']     ?? 'none';
        $aoRow['recurrence_end'] = $aoRow['recurrence_end'] ?? null;
        $autoOpenEvent = $aoRow;
        // Navigate to the correct month so the event is visible
        if (!isset($_GET['m'])) {
            $_GET['m'] = substr($aoRow['start_date'], 0, 7);
        }
    }
}

// ── Month navigation ──────────────────────────────────────────────────────────
$mParam  = preg_match('/^\d{4}-\d{2}$/', $_GET['m'] ?? '') ? $_GET['m'] : null;
$today   = new DateTime('now', $local_tz);
$display = $mParam ? new DateTime($mParam . '-01', $local_tz) : (clone $today)->modify('first day of this month');
$display->setTime(0, 0, 0);

$prevMonth = (clone $display)->modify('-1 month')->format('Y-m');
$nextMonth = (clone $display)->modify('+1 month')->format('Y-m');
$monthParam = $display->format('Y-m');

$firstDay  = (int)$display->format('N'); // 1=Mon … 7=Sun → convert to 0=Sun
$firstDay  = $firstDay % 7;              // Sun=0, Mon=1 … Sat=6
$daysInMonth = (int)$display->format('t');
$monthStart = $display->format('Y-m-01');
$monthEnd   = $display->format('Y-m-') . $daysInMonth;

// Fetch events: non-recurring that overlap the month, plus any recurring that
// started before month-end and haven't expired before month-start.
$evQuery = $db->prepare(
    "SELECT * FROM events WHERE
       (recurrence = 'none' AND start_date <= ? AND (end_date >= ? OR (end_date IS NULL AND start_date >= ?)))
       OR
       (recurrence != 'none' AND start_date <= ? AND (recurrence_end IS NULL OR recurrence_end >= ?))
     ORDER BY start_date, start_time"
);
$evQuery->execute([$monthEnd, $monthStart, $monthStart, $monthEnd, $monthStart]);
$allEvents = $evQuery->fetchAll();

// ── Helper: expand events (including recurring) into a date-keyed array ───────
// $exceptions: [event_id => ['YYYY-MM-DD', ...]] — occurrence start dates to skip
function build_event_by_date(array $events, string $rangeStart, string $rangeEnd, DateTimeZone $tz, array $exceptions = []): array {
    $byDate = [];
    $rangeS = new DateTime($rangeStart, $tz);
    $rangeE = new DateTime($rangeEnd, $tz);

    foreach ($events as $ev) {
        $recurrence = $ev['recurrence'] ?? 'none';
        $startDt    = new DateTime($ev['start_date'], $tz);
        $endDt      = $ev['end_date'] ? new DateTime($ev['end_date'], $tz) : clone $startDt;
        $duration   = (int)$startDt->diff($endDt)->days;
        $skip       = $exceptions[$ev['id']] ?? [];

        if ($recurrence === 'none') {
            $cur = clone $startDt;
            while ($cur <= $endDt) {
                $k = $cur->format('Y-m-d');
                if ($k >= $rangeStart && $k <= $rangeEnd) {
                    $byDate[$k][] = array_merge($ev, ['occurrence_start' => $ev['start_date']]);
                }
                $cur->modify('+1 day');
            }
        } else {
            $recEnd = $ev['recurrence_end'] ? new DateTime($ev['recurrence_end'], $tz) : null;
            $cur    = clone $startDt;
            $limit  = 1000;
            while ($cur <= $rangeE && $limit-- > 0) {
                if ($recEnd && $cur > $recEnd) break;
                $occDate = $cur->format('Y-m-d');
                if (!in_array($occDate, $skip, true)) {
                    $instEnd = (clone $cur)->modify("+{$duration} days");
                    if ($instEnd >= $rangeS) {
                        $day = clone $cur;
                        while ($day <= $instEnd) {
                            $k = $day->format('Y-m-d');
                            if ($k >= $rangeStart && $k <= $rangeEnd) {
                                $byDate[$k][] = array_merge($ev, ['occurrence_start' => $occDate]);
                            }
                            $day->modify('+1 day');
                        }
                    }
                }
                switch ($recurrence) {
                    case 'daily':   $cur->modify('+1 day');   break;
                    case 'weekly':  $cur->modify('+1 week');  break;
                    case 'monthly': $cur->modify('+1 month'); break;
                    case 'yearly':  $cur->modify('+1 year');  break;
                    default: break 2;
                }
            }
        }
    }
    return $byDate;
}

// Load exceptions for all recurring events
function load_exceptions(PDO $db, array $events): array {
    $ids = array_column(array_filter($events, fn($e) => ($e['recurrence'] ?? 'none') !== 'none'), 'id');
    if (empty($ids)) return [];
    $ph   = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare("SELECT event_id, date FROM event_exceptions WHERE event_id IN ($ph)");
    $stmt->execute(array_values($ids));
    $out  = [];
    foreach ($stmt->fetchAll() as $row) $out[$row['event_id']][] = $row['date'];
    return $out;
}

$exceptions = load_exceptions($db, $allEvents);
$byDate     = build_event_by_date($allEvents, $monthStart, $monthEnd, $local_tz, $exceptions);

$pvEvents = [];

// ── View mode (month / week) ───────────────────────────────────────────────────
$viewMode = (($_GET['view'] ?? '') === 'week') ? 'week' : 'month';

// Current week start (Sunday) — used for the Week toggle link
$_cwDow      = (int)$today->format('w');
$_cwStart    = (clone $today)->modify("-{$_cwDow} days");
$_cwStart->setTime(0, 0, 0);
$currentWeekStr = $_cwStart->format('Y-m-d');

$wkByDate    = [];
$wkAllEvents = [];
$wkStart     = null;
$wkEnd       = null;
$wkStartStr  = $wkEndStr = $prevWk = $nextWk = $currentWeekStr;

if ($viewMode === 'week') {
    $wkParam = $_GET['wk'] ?? null;
    if ($wkParam && preg_match('/^\d{4}-\d{2}-\d{2}$/', $wkParam)) {
        $wkAnchor = new DateTime($wkParam, $local_tz);
    } else {
        $wkAnchor = clone $today;
    }
    $wkAnchor->setTime(0, 0, 0);
    $wkDow   = (int)$wkAnchor->format('w');
    $wkStart = (clone $wkAnchor)->modify("-{$wkDow} days");
    $wkEnd   = (clone $wkStart)->modify('+6 days');
    $wkStartStr = $wkStart->format('Y-m-d');
    $wkEndStr   = $wkEnd->format('Y-m-d');
    $prevWk = (clone $wkStart)->modify('-7 days')->format('Y-m-d');
    $nextWk = (clone $wkStart)->modify('+7 days')->format('Y-m-d');

    $wkEvQ = $db->prepare(
        "SELECT * FROM events WHERE
           (recurrence = 'none' AND start_date <= ? AND (end_date >= ? OR (end_date IS NULL AND start_date >= ?)))
           OR
           (recurrence != 'none' AND start_date <= ? AND (recurrence_end IS NULL OR recurrence_end >= ?))
         ORDER BY start_date, start_time"
    );
    $wkEvQ->execute([$wkEndStr, $wkStartStr, $wkStartStr, $wkEndStr, $wkStartStr]);
    $wkAllEvents  = $wkEvQ->fetchAll();
    $wkExceptions = load_exceptions($db, $wkAllEvents);
    $wkByDate     = build_event_by_date($wkAllEvents, $wkStartStr, $wkEndStr, $local_tz, $wkExceptions);
}

// Batch-load comments for all events on this page (month view, preview, and week view)
$ev_comments = [];
$allPageEids = array_values(array_unique(array_merge(
    array_column($allEvents, 'id'),
    array_column($pvEvents, 'id'),
    array_column($wkAllEvents, 'id')
)));
if (!empty($allPageEids)) {
    $ph = implode(',', array_fill(0, count($allPageEids), '?'));
    $cs = $db->prepare("SELECT c.*, u.username FROM comments c JOIN users u ON u.id=c.user_id WHERE c.type='event' AND c.content_id IN ($ph) ORDER BY c.created_at ASC");
    $cs->execute($allPageEids);
    foreach ($cs->fetchAll() as $c) $ev_comments[$c['content_id']][] = $c;
}

// Batch-load invites for all events on this page
$ev_invites = [];
if (!empty($allPageEids)) {
    $iph = implode(',', array_fill(0, count($allPageEids), '?'));
    $is  = $db->prepare("SELECT event_id, username, phone, email, rsvp FROM event_invites WHERE event_id IN ($iph) ORDER BY username");
    $is->execute($allPageEids);
    foreach ($is->fetchAll() as $inv) $ev_invites[$inv['event_id']][] = $inv;
}
// Non-admins only see username + rsvp (no contact details)
if (!$isAdmin) {
    foreach ($ev_invites as &$_invList) {
        foreach ($_invList as &$_inv) {
            unset($_inv['phone'], $_inv['email']);
        }
    }
    unset($_invList, $_inv);
}

// Auto-open a specific event when ?open=ID&date=DATE is present (from landing page links)
$autoOpenId    = (int)($_GET['open'] ?? 0);
$autoOpenDate  = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'] ?? '') ? $_GET['date'] : null;
$autoOpenEvent = null;
if ($autoOpenId > 0 && $autoOpenDate) {
    // Redirect to the correct month if the event date isn't in the current view
    $targetM = substr($autoOpenDate, 0, 7);
    if ($targetM !== $monthParam) {
        header('Location: /calendar.php?m=' . urlencode($targetM) . '&open=' . $autoOpenId . '&date=' . urlencode($autoOpenDate));
        exit;
    }
    $searchSets = [$byDate, $pvByDate, $wkByDate];
    foreach ($searchSets as $set) {
        foreach ($set[$autoOpenDate] ?? [] as $ev) {
            if ((int)$ev['id'] === $autoOpenId) {
                $autoOpenEvent = $ev;
                break 2;
            }
        }
    }
}

$token = ($isAdmin || $current) ? csrf_token() : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($site_name) ?></title>
    <link rel="stylesheet" href="/style.css">
    <style>

        .cal-header {
            display: flex; align-items: center; justify-content: space-between;
            margin-bottom: 1.25rem; flex-wrap: wrap; gap: .75rem;
        }
        .cal-header h1 { font-size: 1.5rem; }
        .cal-nav { display: flex; align-items: center; gap: .5rem; }
        .cal-nav a {
            display: inline-flex; align-items: center; justify-content: center;
            width: 34px; height: 34px; border-radius: 7px;
            border: 1.5px solid #e2e8f0; background: #f8fafc;
            color: #475569; text-decoration: none; font-size: 1rem;
        }
        .cal-nav a:hover { background: #e2e8f0; color: #1e293b; }
        .cal-nav .month-label {
            font-size: 1.1rem; font-weight: 600; color: #1e293b;
            min-width: 160px; text-align: center;
        }

        /* View toggle */
        .view-toggle { display: flex; gap: 2px; }
        .view-toggle a {
            padding: .3rem .85rem; border-radius: 6px; font-size: .8rem; font-weight: 600;
            text-decoration: none; border: 1.5px solid #e2e8f0;
            color: #475569; background: #f8fafc; transition: background .1s;
        }
        .view-toggle a.vt-active { background: #2563eb; color: #fff; border-color: #2563eb; }
        .view-toggle a:hover:not(.vt-active) { background: #e2e8f0; color: #1e293b; }

        /* Calendar grid (month view) */
        .cal-grid {
            display: grid; grid-template-columns: repeat(7, 1fr);
            border-left: 1.5px solid #e2e8f0; border-top: 1.5px solid #e2e8f0;
            border-radius: 10px; overflow: hidden;
        }
        .cal-dow {
            background: #f8fafc; padding: .45rem .5rem;
            text-align: center; font-size: .75rem; font-weight: 600;
            color: #64748b; text-transform: uppercase; letter-spacing: .04em;
            border-right: 1.5px solid #e2e8f0; border-bottom: 1.5px solid #e2e8f0;
        }
        .cal-cell {
            min-height: 100px; padding: .35rem .4rem;
            border-right: 1.5px solid #e2e8f0; border-bottom: 1.5px solid #e2e8f0;
            background: #fff; vertical-align: top; position: relative;
        }
        .cal-cell.other-month { background: #f8fafc; }
        .cal-cell.today { background: #eff6ff; }
        .cal-day {
            font-size: .8rem; font-weight: 600; color: #94a3b8;
            margin-bottom: .25rem; line-height: 1;
        }
        .cal-cell.today .cal-day {
            background: #2563eb; color: #fff;
            width: 22px; height: 22px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
        }
        .cal-event {
            font-size: .72rem; padding: 2px 6px; border-radius: 4px;
            margin-bottom: 2px; color: #fff; cursor: pointer;
            display: flex; align-items: center;
            overflow: hidden; line-height: 1.5; position: relative;
        }
        .cal-event .ev-label {
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis; flex: 1;
        }
        .cal-event .ev-edit-btn {
            display: none; flex-shrink: 0; margin-left: 3px;
            background: none; border: none; color: rgba(255,255,255,.85);
            cursor: pointer; font-size: .7rem; padding: 0 2px; line-height: 1;
        }
        .cal-event:hover .ev-edit-btn { display: block; }
        .cal-event:hover { filter: brightness(1.1); }
        .cal-add-btn {
            position: absolute; top: .3rem; right: .3rem;
            width: 20px; height: 20px; border-radius: 4px;
            background: transparent; border: none;
            color: #cbd5e1; font-size: 1rem; cursor: pointer;
            display: none; align-items: center; justify-content: center;
            line-height: 1; padding: 0;
        }
        .cal-cell:hover .cal-add-btn { display: flex; }
        .cal-add-btn:hover { background: #e2e8f0; color: #2563eb; }

        /* ── Week view ───────────────────────────────────────────── */
        .week-header-row {
            display: grid; grid-template-columns: 52px repeat(7, 1fr);
            border: 1.5px solid #e2e8f0; border-radius: 10px 10px 0 0;
            overflow: hidden; background: #f8fafc;
        }
        .week-hdr-gutter {
            border-right: 1.5px solid #e2e8f0;
        }
        .week-day-hdr {
            text-align: center; padding: .5rem .25rem .4rem;
            font-size: .72rem; font-weight: 600; color: #64748b;
            border-right: 1.5px solid #e2e8f0;
            text-transform: uppercase; letter-spacing: .04em;
            line-height: 1.3;
        }
        .week-day-hdr:last-child { border-right: none; }
        .week-day-hdr .wk-day-num {
            display: block; font-size: 1.05rem; font-weight: 700;
            color: #1e293b; line-height: 1.4;
        }
        .week-day-hdr.wk-today { background: #eff6ff; }
        .week-day-hdr.wk-today .wk-day-num {
            background: #2563eb; color: #fff;
            width: 28px; height: 28px; border-radius: 50%;
            display: inline-flex; align-items: center; justify-content: center;
        }

        .week-allday-row {
            display: grid; grid-template-columns: 52px repeat(7, 1fr);
            border: 1.5px solid #e2e8f0; border-top: none;
            min-height: 26px; background: #fff;
        }
        .week-allday-gutter {
            font-size: .62rem; color: #94a3b8; text-align: right;
            padding: .3rem .45rem 0 0; border-right: 1.5px solid #e2e8f0;
        }
        .week-allday-col {
            border-right: 1.5px solid #e2e8f0; padding: 2px 3px;
        }
        .week-allday-col:last-child { border-right: none; }
        .week-allday-chip {
            font-size: .68rem; padding: 1px 5px; border-radius: 3px;
            color: #fff; cursor: pointer; margin-bottom: 1px;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
            display: flex; align-items: center; line-height: 1.6;
        }
        .week-allday-chip:hover { filter: brightness(1.1); }
        .week-allday-chip .ev-edit-btn {
            display: none; margin-left: auto; flex-shrink: 0;
            background: none; border: none; color: rgba(255,255,255,.85);
            cursor: pointer; font-size: .65rem; padding: 0 2px; line-height: 1;
        }
        .week-allday-chip:hover .ev-edit-btn { display: block; }

        .week-scroll {
            height: 540px; overflow-y: auto;
            border: 1.5px solid #e2e8f0; border-top: none;
            border-radius: 0 0 10px 10px;
        }
        .week-inner {
            display: grid; grid-template-columns: 52px repeat(7, 1fr);
            position: relative;
            /* 17 hours × 60px = 1020px (6 AM – 11 PM) */
            min-height: 1020px;
        }
        .week-time-gutter {
            background: #f8fafc; border-right: 1.5px solid #e2e8f0;
            position: relative;
        }
        .week-hour-label {
            position: absolute; right: 6px;
            font-size: .63rem; color: #94a3b8;
            transform: translateY(-50%);
            white-space: nowrap; user-select: none;
        }
        .week-day-col {
            position: relative; border-right: 1.5px solid #e2e8f0;
        }
        .week-day-col:last-child { border-right: none; }
        .week-day-col.wk-today { background: #fafeff; }
        .week-hour-line {
            position: absolute; left: 0; right: 0;
            border-top: 1px solid #f1f5f9; pointer-events: none; z-index: 0;
        }
        .week-half-line {
            position: absolute; left: 0; right: 0;
            border-top: 1px dashed #f8fafc; pointer-events: none; z-index: 0;
        }
        .week-now-line {
            position: absolute; left: 0; right: 0; z-index: 5;
            border-top: 2px solid #ef4444; pointer-events: none;
        }
        .week-now-line::before {
            content: ''; position: absolute; left: -4px; top: -5px;
            width: 8px; height: 8px; border-radius: 50%; background: #ef4444;
        }
        .week-event {
            position: absolute; border-radius: 4px;
            padding: 2px 5px; font-size: .72rem; color: #fff;
            cursor: pointer; overflow: hidden; line-height: 1.3;
            box-sizing: border-box; min-height: 20px;
            display: flex; flex-direction: column;
            border-left: 3px solid rgba(0,0,0,.15);
            transition: filter .1s;
        }
        .week-event:hover { filter: brightness(1.1); z-index: 10; }
        .week-event-title {
            font-weight: 600; white-space: nowrap;
            overflow: hidden; text-overflow: ellipsis;
        }
        .week-event-time { font-size: .63rem; opacity: .88; white-space: nowrap; }
        .week-event .ev-edit-btn {
            display: none; position: absolute; top: 2px; right: 2px;
            background: none; border: none; color: rgba(255,255,255,.85);
            cursor: pointer; font-size: .7rem; padding: 0 2px; line-height: 1;
        }
        .week-event:hover .ev-edit-btn { display: block; }

        /* Modal */
        .modal-overlay {
            display: none; position: fixed; inset: 0;
            background: rgba(0,0,0,.45); z-index: 200;
            align-items: center; justify-content: center; padding: 1rem;
        }
        .modal-overlay.open { display: flex; }
        .modal {
            background: #fff; border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0,0,0,.25);
            width: 100%; max-width: 480px; padding: 1.75rem;
            animation: modalIn .15s ease;
        }
        #editModal .modal { max-width: 600px; }
        .invite-row { display:flex; gap:.35rem; align-items:center; }
        .invite-row input { padding:.38rem .6rem; border:1.5px solid #e2e8f0; border-radius:6px; font-size:.85rem; min-width:0; }
        .invite-row input:nth-child(1) { flex:2; }
        .invite-row input:nth-child(2) { flex:1.5; }
        .invite-row input:nth-child(3) { flex:2; }
        .invite-row select { flex-shrink:0; }
        .invite-row .inv-remove { flex-shrink:0; padding:.3rem .5rem; border:1px solid #e2e8f0; border-radius:6px; background:#fff; cursor:pointer; color:#94a3b8; font-size:.9rem; line-height:1; }
        .invite-row .inv-remove:hover { background:#fee2e2; color:#dc2626; border-color:#fca5a5; }
        /* Edit modal layout */
        .edit-form-body { display:contents; }
        .edit-footer { display:flex; gap:.75rem; margin-top:1.25rem; }
        #eInviteHeader { display:none; }
        #eUserChecklist { display:none; }
        #eAddCustomBtn { display:none; }
        /* Checklist (desktop invites) */
        .checklist-item { display:flex;align-items:center;gap:.65rem;padding:.5rem .75rem;cursor:pointer;border-bottom:1px solid #f1f5f9;font-size:.875rem;user-select:none; }
        .checklist-item:last-child { border-bottom:none; }
        .checklist-item:hover { background:#f8fafc; }
        .checklist-item.ci-invited { background:#f0fdf4; }
        .ci-check { width:17px;height:17px;border-radius:4px;border:2px solid #cbd5e1;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:.65rem;color:#fff; }
        .checklist-item.ci-invited .ci-check { background:#16a34a;border-color:#16a34a; }
        .ci-name { flex:1;font-weight:500; }
        .ci-badge { font-size:.7rem;font-weight:700;padding:.1rem .4rem;border-radius:4px; }
        .ci-badge-yes { background:#dcfce7;color:#166534; }
        .ci-badge-no  { background:#fee2e2;color:#991b1b; }
        .ci-badge-maybe { background:#fef9c3;color:#854d0e; }
        .ci-info { flex:1;min-width:0; }
        .ci-sub  { font-size:.72rem;color:#94a3b8;display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis; }
        @media (min-width:769px) {
            #editModal .modal { max-width:min(96vw,1140px);max-height:92vh;display:flex;flex-direction:column;padding:0;overflow:hidden; }
            #editModal .modal-header { padding:1.1rem 1.75rem;margin-bottom:0;border-bottom:1px solid #e2e8f0;flex-shrink:0; }
            #editModal .edit-form-body { display:grid;grid-template-columns:1fr 1fr;flex:1;min-height:0;overflow:hidden; }
            /* min-height:0 is critical on grid children — without it they ignore overflow */
            #editModal .edit-col-left { padding:1.25rem 1.5rem;overflow-y:auto;border-right:1px solid #e2e8f0;min-height:0; }
            #editModal .edit-col-right { padding:1.25rem 1.5rem;display:flex;flex-direction:column;min-height:0;overflow:hidden; }
            #editModal .edit-footer { padding:.75rem 1.5rem;border-top:1px solid #e2e8f0;margin-top:0;flex-shrink:0; }
            #editModal #eDesc { min-height:130px; }
            #eMobileInvites { display:none; }
            #eUserSelectWrap { display:none !important; }
            /* checklist takes half the column, scrolls internally */
            #eUserChecklist { display:flex;flex-direction:column;flex:1 1 0;min-height:0;overflow:hidden; }
            #eChecklistItems { flex:1 1 0;overflow-y:auto;min-height:0; }
            /* invited list takes the other half, scrolls internally */
            .invited-section { display:flex;flex-direction:column;flex:1 1 0;min-height:0;overflow:hidden;margin-top:.65rem;border-top:1px solid #f1f5f9;padding-top:.5rem; }
            #eInviteList { flex:1 1 0;overflow-y:auto;min-height:0; }
            #eInviteHeader { display:grid;flex-shrink:0; }
            #eAddCustomBtn { display:block;flex-shrink:0; }
        }
        @keyframes rsvpSavedFade { 0%,60%{opacity:1} 100%{opacity:0} }
        .rsvp-saved-anim { animation: rsvpSavedFade 3s ease forwards; }
        .rsvp-yes   { background:#dcfce7; color:#166534; border-radius:4px; padding:.1rem .4rem; font-size:.75rem; font-weight:600; }
        .rsvp-no    { background:#fee2e2; color:#991b1b; border-radius:4px; padding:.1rem .4rem; font-size:.75rem; font-weight:600; }
        .rsvp-maybe { background:#fef9c3; color:#854d0e; border-radius:4px; padding:.1rem .4rem; font-size:.75rem; font-weight:600; }
        @keyframes modalIn {
            from { transform: translateY(-10px); opacity: 0; }
            to   { transform: none; opacity: 1; }
        }
        .modal-header {
            display: flex; align-items: center;
            justify-content: space-between; margin-bottom: 1.25rem;
        }
        .modal-header h2 { font-size: 1.1rem; }
        .modal-close {
            width: 30px; height: 30px; border-radius: 6px;
            border: none; background: #f1f5f9; cursor: pointer;
            font-size: 1rem; color: #64748b;
        }
        .modal-close:hover { background: #e2e8f0; }

        /* View modal */
        .ev-view-title { font-size: 1.15rem; font-weight: 700; margin-bottom: .25rem; }
        .ev-view-meta  { font-size: .82rem; color: #64748b; margin-bottom: .75rem; }
        .ev-view-desc  { font-size: .9rem; color: #334155; white-space: pre-wrap; }
        .ev-view-actions { display: flex; gap: .5rem; margin-top: 1.25rem; }

        /* Color swatches */
        .color-swatches { display: flex; gap: .5rem; flex-wrap: wrap; margin-top: .25rem; }
        .color-swatch {
            width: 28px; height: 28px; border-radius: 50%; cursor: pointer;
            border: 3px solid transparent; transition: border-color .15s;
        }
        .color-swatch.selected,
        .color-swatch:hover { border-color: #1e293b; }

        @media (max-width: 640px) {
            /* Month view: already handled by global style.css breakpoint */
            .cal-header { gap: .5rem; }
            .cal-nav .month-label { min-width: 120px; font-size: .9rem; }

            /* Week view: constrain to viewport, scroll internally */
            .week-outer {
                width: 100%;
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }
            /* Give the week grid a comfortable minimum so columns aren't squashed */
            .week-header-row,
            .week-allday-row,
            .week-inner {
                grid-template-columns: 44px repeat(7, 80px);
                min-width: 604px; /* 44 + 7*80 */
            }
            .week-scroll { height: 480px; }

        }
    </style>
</head>
<body>

<?php $nav_active = 'calendar'; $nav_user = $current; require __DIR__ . '/_nav.php'; ?>

<div class="dash-wrap">

    <?php if ($flash['msg']): ?>
        <div class="alert alert-<?= $flash['type'] === 'error' ? 'error' : 'success' ?>" style="margin-bottom:1rem">
            <?= htmlspecialchars($flash['msg']) ?>
        </div>
    <?php endif; ?>


    <!-- Calendar header: view toggle + navigation + add button -->
    <div class="cal-header">
        <div style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap">
            <div class="view-toggle">
                <a href="/calendar.php?m=<?= $monthParam ?>"
                   class="<?= $viewMode === 'month' ? 'vt-active' : '' ?>">Month</a>
                <a href="/calendar.php?view=week&amp;wk=<?= $currentWeekStr ?>"
                   class="<?= $viewMode === 'week' ? 'vt-active' : '' ?>">Week</a>
            </div>
            <?php if ($viewMode === 'month'): ?>
            <div class="cal-nav">
                <a href="/calendar.php?m=<?= $prevMonth ?>" title="Previous month">&#8249;</a>
                <span class="month-label"><?= $display->format('F Y') ?></span>
                <a href="/calendar.php?m=<?= $nextMonth ?>" title="Next month">&#8250;</a>
                <a href="/calendar.php" style="font-size:.75rem;width:auto;padding:0 .65rem;font-weight:600" title="Today">Today</a>
            </div>
            <?php else: ?>
            <div class="cal-nav">
                <a href="/calendar.php?view=week&amp;wk=<?= $prevWk ?>" title="Previous week">&#8249;</a>
                <span class="month-label" style="font-size:.95rem">
                    <?= $wkStart->format('M j') ?> &ndash; <?= $wkEnd->format($wkStart->format('M') === $wkEnd->format('M') ? 'j, Y' : 'M j, Y') ?>
                </span>
                <a href="/calendar.php?view=week&amp;wk=<?= $nextWk ?>" title="Next week">&#8250;</a>
                <a href="/calendar.php?view=week" style="font-size:.75rem;width:auto;padding:0 .65rem;font-weight:600" title="This week">Today</a>
            </div>
            <?php endif; ?>
        </div>
        <?php if ($isAdmin): ?>
            <button class="btn btn-primary" onclick="openAddModal('')">&#43; Add Event</button>
        <?php endif; ?>
    </div>

    <?php if ($viewMode === 'month'): ?>
    <!-- ── Month grid ── -->
    <div class="cal-grid">
        <?php foreach (['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $dow): ?>
            <div class="cal-dow"><?= $dow ?></div>
        <?php endforeach; ?>

        <?php
        // Blank cells before the 1st
        for ($i = 0; $i < $firstDay; $i++):
        ?>
            <div class="cal-cell other-month"></div>
        <?php endfor; ?>

        <?php for ($d = 1; $d <= $daysInMonth; $d++):
            $dateStr  = $display->format('Y-m-') . str_pad($d, 2, '0', STR_PAD_LEFT);
            $isToday  = $dateStr === $today->format('Y-m-d');
            $dayEvents = $byDate[$dateStr] ?? [];
        ?>
            <div class="cal-cell<?= $isToday ? ' today' : '' ?>">
                <div class="cal-day"><?= $d ?></div>
                <?php foreach ($dayEvents as $ev): ?>
                    <div class="cal-event"
                         style="background:<?= htmlspecialchars($ev['color']) ?>"
                         onclick="viewEvent(<?= htmlspecialchars(json_encode($ev)) ?>)"
                         title="<?= htmlspecialchars($ev['title']) ?>">
                        <span class="ev-label">
                            <?php if ($ev['start_time'] && $ev['start_date'] === $dateStr): ?>
                                <?= htmlspecialchars(date('g:ia', strtotime($ev['start_time']))) ?>
                            <?php endif; ?>
                            <?= htmlspecialchars($ev['title']) ?>
                        </span>
                        <?php if ($isAdmin): ?>
                        <button class="ev-edit-btn" title="Edit event"
                                onclick="event.stopPropagation();openEditModal(<?= htmlspecialchars(json_encode($ev)) ?>)">&#9998;</button>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <?php if ($isAdmin): ?>
                    <button class="cal-add-btn" onclick="openAddModal('<?= $dateStr ?>')" title="Add event">&#43;</button>
                <?php endif; ?>
            </div>
        <?php endfor; ?>

        <?php
        // Trailing blank cells to complete the last row
        $total = $firstDay + $daysInMonth;
        $remainder = $total % 7;
        if ($remainder > 0):
            for ($i = 0; $i < (7 - $remainder); $i++):
        ?>
            <div class="cal-cell other-month"></div>
        <?php endfor; endif; ?>
    </div>

    <?php else: /* week view */ ?>
    <!-- ── Week view ── -->
    <div id="weekView" style="max-width:100%;overflow:hidden">
      <div class="week-outer">
        <!-- Day header row -->
        <div class="week-header-row">
            <div class="week-hdr-gutter"></div>
            <?php
            $wkCursor = clone $wkStart;
            for ($i = 0; $i < 7; $i++):
                $wkDs = $wkCursor->format('Y-m-d');
                $isWkToday = ($wkDs === $today->format('Y-m-d'));
            ?>
            <div class="week-day-hdr<?= $isWkToday ? ' wk-today' : '' ?>">
                <?= $wkCursor->format('D') ?>
                <span class="wk-day-num"><?= $wkCursor->format('j') ?></span>
            </div>
            <?php $wkCursor->modify('+1 day'); endfor; ?>
        </div>

        <!-- All-day events row -->
        <div class="week-allday-row">
            <div class="week-allday-gutter">all&#8209;day</div>
            <?php
            $wkCursor2 = clone $wkStart;
            for ($i = 0; $i < 7; $i++):
                $wkDs2  = $wkCursor2->format('Y-m-d');
                $dayEvs = $wkByDate[$wkDs2] ?? [];
                $alldayEvs = array_values(array_filter($dayEvs, fn($e) => !$e['start_time']));
            ?>
            <div class="week-allday-col">
                <?php foreach ($alldayEvs as $ev): ?>
                <div class="week-allday-chip"
                     style="background:<?= htmlspecialchars($ev['color']) ?>"
                     title="<?= htmlspecialchars($ev['title']) ?>"
                     onclick="viewEvent(<?= htmlspecialchars(json_encode($ev)) ?>)">
                    <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;flex:1">
                        <?= htmlspecialchars($ev['title']) ?>
                    </span>
                    <?php if ($isAdmin): ?>
                    <button class="ev-edit-btn" title="Edit event"
                            onclick="event.stopPropagation();openEditModal(<?= htmlspecialchars(json_encode($ev)) ?>)">&#9998;</button>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php $wkCursor2->modify('+1 day'); endfor; ?>
        </div>

        <!-- Scrollable time grid -->
        <div class="week-scroll" id="weekScroll">
            <div class="week-inner" id="weekInner">
                <!-- Time gutter column -->
                <div class="week-time-gutter" id="weekTimeGutter"></div>
                <!-- Day columns (JS fills in event chips) -->
                <?php
                $wkCursor3 = clone $wkStart;
                for ($i = 0; $i < 7; $i++):
                    $wkDs3 = $wkCursor3->format('Y-m-d');
                    $isWkToday3 = ($wkDs3 === $today->format('Y-m-d'));
                ?>
                <div class="week-day-col<?= $isWkToday3 ? ' wk-today' : '' ?>"
                     id="wkCol-<?= $wkDs3 ?>"
                     data-date="<?= $wkDs3 ?>">
                </div>
                <?php $wkCursor3->modify('+1 day'); endfor; ?>
            </div>
        </div>
      </div><!-- /.week-outer -->
    </div>
    <?php endif; ?>

</div>

<!-- ── View Event Modal ── -->
<div class="modal-overlay" id="viewModal" onclick="if(event.target===this)closeView()">
    <div class="modal" style="max-height:88vh;overflow:hidden;max-width:520px;display:flex;flex-direction:column">
        <div style="flex-shrink:0">
        <div class="modal-header">
            <h2 id="vTitle" class="ev-view-title"></h2>
            <div style="display:flex;gap:.3rem;align-items:center">
                <button class="modal-close" id="vCopyLinkBtn" title="Copy link to this event"
                        onclick="copyEventLink()" style="font-size:.95rem">&#128279;</button>
                <button class="modal-close" onclick="closeView()">&#x2715;</button>
            </div>
        </div>
        <div id="vSavedBar" style="visibility:hidden;background:#dcfce7;color:#166534;border-radius:7px;padding:.2rem .9rem;font-size:.8rem;font-weight:600;margin-bottom:.5rem;text-align:center">
            Saved
        </div>
        <div id="vMeta"    class="ev-view-meta"></div>
        <div id="vRecurr" class="ev-view-meta" style="font-style:italic"></div>
        <div id="vDesc"    class="ev-view-desc"></div>
        <?php if ($current): ?>
        <div id="vRsvpWrap" style="display:none;margin:.5rem 0 0;padding:.65rem .85rem;border:2px solid #bfdbfe;border-radius:10px;background:#eff6ff">
            <input type="hidden" id="vRsvpCsrf" value="<?= htmlspecialchars($token) ?>">
            <input type="hidden" id="vRsvpEventId" value="">
            <div style="font-size:.8rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#2563eb;margin-bottom:.5rem">Are you coming? &mdash; RSVP</div>
            <div style="display:flex;gap:.75rem;align-items:center">
                <div id="vRsvpStatus" style="min-width:62px;text-align:center"></div>
                <select id="vRsvpSelect"
                        style="padding:.42rem .7rem;border:1.5px solid #93c5fd;border-radius:7px;font-size:.9rem;background:#fff;color:#1e3a5f;font-weight:500">
                    <option value="">-- select --</option>
                    <option value="yes">Yes</option>
                    <option value="no">No</option>
                    <option value="maybe">Maybe</option>
                </select>
            </div>
        </div>
        <?php endif; ?>
        <div id="vInvites" style="display:none;margin:.25rem 0 0;padding:.6rem 0;border-top:1px solid #f1f5f9"></div>
        <?php if ($current): ?>
        <div id="vSignupWrap" style="display:none;padding:.5rem 0;border-top:1px solid #f1f5f9">
            <button id="vSignupBtn" class="btn btn-primary" style="width:100%;font-size:.875rem">Sign up to attend</button>
        </div>
        <?php endif; ?>
        <?php if (!$current): ?>
        <div style="padding:.5rem 0;border-top:1px solid #f1f5f9;display:flex;gap:.5rem">
            <a id="vLoginBtn" href="/login.php" class="btn btn-primary" style="flex:1;text-align:center;font-size:.875rem;text-decoration:none">
                Login to join
            </a>
            <?php if (get_setting('allow_registration', '1') === '1'): ?>
            <a id="vSignupLink" href="/register.php" class="btn btn-outline" style="flex:1;text-align:center;font-size:.875rem;text-decoration:none">
                Sign up
            </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <?php if ($isAdmin): ?>
        <div class="ev-view-actions">
            <button class="btn btn-primary" onclick="editFromView()">Edit</button>
            <!-- Delete this occurrence only (shown for recurring events) -->
            <form method="post" action="/calendar.php" style="margin:0" id="vDeleteOccForm"
                  onsubmit="return confirm('Remove just this occurrence?')">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">
                <input type="hidden" name="action" value="delete_occurrence">
                <input type="hidden" name="id" id="vDeleteOccId" value="">
                <input type="hidden" name="occurrence_date" id="vDeleteOccDate" value="">
                <input type="hidden" name="month_param" value="<?= htmlspecialchars($monthParam) ?>">
                <button type="submit" class="btn btn-outline" style="color:#dc2626;border-color:#fca5a5">Delete this date</button>
            </form>
            <!-- Delete entire event -->
            <form method="post" action="/calendar.php" style="margin:0"
                  onsubmit="return confirm(currentEvent && currentEvent.recurrence !== 'none' ? 'Delete the entire repeating series? This cannot be undone.' : 'Delete this event?')">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" id="vDeleteId">
                <input type="hidden" name="month_param" value="<?= htmlspecialchars($monthParam) ?>">
                <button type="submit" id="vDeleteAllBtn" class="btn" style="background:#dc2626;color:#fff">Delete</button>
            </form>
        </div>
        <?php endif; ?>

        </div><!-- /static-top -->
        <!-- Comments -->
        <div class="comments-section" id="vCommentsSection" style="flex:1;min-height:0;display:flex;flex-direction:column;overflow:hidden;margin-top:.75rem">
            <div class="comments-heading">
                <span id="vCommentsHeading">0 Comments</span>
                <?php if ($isAdmin): ?>
                <label class="sel-all-label" id="vSelAllWrap" style="display:none">
                    <input type="checkbox" id="vSelAll" onchange="toggleCalSelAll(this)"> Select all
                </label>
                <?php endif; ?>
            </div>
            <?php if ($isAdmin): ?>
            <div class="bulk-bar" id="vBulkBar" style="display:none">
                <span class="bulk-count" id="vBulkCount">0 selected</span>
                <form method="post" action="/comment.php" style="margin:0;display:contents"
                      onsubmit="return prepareCalBulkDelete(this)">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">
                    <input type="hidden" name="action" value="bulk_delete">
                    <input type="hidden" name="comment_ids" id="vBulkIds" value="">
                    <input type="hidden" name="redirect" id="vBulkRedir" value="">
                    <button type="submit" class="btn btn-danger" style="font-size:.75rem;padding:.25rem .65rem">Delete selected</button>
                </form>
                <button type="button" onclick="clearCalSel()"
                        class="btn btn-outline" style="font-size:.75rem;padding:.25rem .65rem">Cancel</button>
            </div>
            <?php endif; ?>
            <div id="vCommentsScroll" style="flex:1;min-height:0;overflow-y:auto;padding-right:.25rem">
                <div id="vCommentsList"></div>
            </div>
            <?php if ($current): ?>
            <form method="post" action="/comment.php" class="comment-form" id="vCommentForm" style="flex-shrink:0">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">
                <input type="hidden" name="action" value="add">
                <input type="hidden" name="type" value="event">
                <input type="hidden" name="content_id" id="vCommentEventId" value="">
                <input type="hidden" name="redirect" id="vCommentRedirect" value="">
                <textarea name="body" placeholder="Write a comment…" required maxlength="2000"></textarea>
                <button type="submit" class="btn btn-primary btn-post">Post</button>
            </form>
            <?php else: ?>
            <p class="comment-login"><a href="/login.php">Log in</a> to leave a comment.</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($isAdmin): ?>
<!-- ── Add / Edit Event Modal ── -->
<div class="modal-overlay" id="editModal" onclick="if(event.target===this)closeEdit()">
    <div class="modal">
        <div class="modal-header">
            <h2 id="editModalTitle">Add Event</h2>
            <button class="modal-close" onclick="closeEdit()">&#x2715;</button>
        </div>
        <form method="post" action="/calendar.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">
            <input type="hidden" name="action" id="eAction" value="add">
            <input type="hidden" name="id" id="eId" value="">
            <input type="hidden" name="month_param" value="<?= htmlspecialchars($monthParam) ?>">

            <div class="edit-form-body">

                <!-- ── Left column: event details ── -->
                <div class="edit-col-left">
                    <div class="form-group">
                        <label>Title</label>
                        <input type="text" name="title" id="eTitle" required autocomplete="off">
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem">
                        <div class="form-group">
                            <label>Start Date</label>
                            <input type="date" name="start_date" id="eStartDate" required>
                        </div>
                        <div class="form-group">
                            <label>End Date <span style="color:#94a3b8;font-weight:400">(optional)</span></label>
                            <input type="date" name="end_date" id="eEndDate">
                        </div>
                        <div class="form-group">
                            <label>Start Time <span style="color:#94a3b8;font-weight:400">(optional)</span></label>
                            <input type="time" name="start_time" id="eStartTime">
                        </div>
                        <div class="form-group">
                            <label>End Time <span style="color:#94a3b8;font-weight:400">(optional)</span></label>
                            <input type="time" name="end_time" id="eEndTime">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Recurrence</label>
                        <select name="recurrence" id="eRecurrence" class="form-select"
                                onchange="toggleRecEnd(this.value)">
                            <option value="none">Does not repeat</option>
                            <option value="daily">Daily</option>
                            <option value="weekly">Weekly</option>
                            <option value="monthly">Monthly</option>
                            <option value="yearly">Yearly</option>
                        </select>
                    </div>
                    <div class="form-group" id="recEndGroup" style="display:none">
                        <label>Repeat until <span style="color:#94a3b8;font-weight:400">(optional)</span></label>
                        <input type="date" name="recurrence_end" id="eRecEnd">
                    </div>
                    <div class="form-group">
                        <label>Color</label>
                        <div class="color-swatches" id="colorSwatches">
                            <?php foreach (['#2563eb','#16a34a','#dc2626','#d97706','#7c3aed','#0891b2','#db2777'] as $c): ?>
                                <div class="color-swatch" style="background:<?= $c ?>" data-color="<?= $c ?>"
                                     onclick="selectColor('<?= $c ?>')"></div>
                            <?php endforeach; ?>
                        </div>
                        <input type="hidden" name="color" id="eColor" value="#2563eb">
                    </div>
                    <div class="form-group">
                        <label>Description <span style="color:#94a3b8;font-weight:400">(optional)</span></label>
                        <textarea name="description" id="eDesc" rows="3" style="width:100%;resize:vertical"></textarea>
                    </div>
                    <!-- Mobile-only invite section -->
                    <div id="eMobileInvites" class="form-group" style="margin-top:.5rem">
                        <label>Invites</label>
                        <div id="eUserSelectWrap" style="display:flex;gap:.5rem;margin-bottom:.4rem">
                            <select id="eUserSelect" multiple
                                    style="flex:1;min-height:72px;border:1.5px solid #e2e8f0;border-radius:7px;padding:.35rem;font-size:.875rem">
                                <?php foreach ($allUsers as $u): ?>
                                <option value="<?= htmlspecialchars($u['username']) ?>"
                                        data-email="<?= htmlspecialchars($u['email'] ?? '') ?>"
                                        data-phone="<?= htmlspecialchars($u['phone'] ?? '') ?>">
                                    <?= htmlspecialchars($u['username']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <div style="display:flex;flex-direction:column;gap:.35rem;flex-shrink:0">
                                <button type="button" class="btn btn-outline" style="font-size:.8rem;padding:.35rem .7rem;white-space:nowrap" onclick="addSelectedInvites()">+ Add Selected</button>
                                <button type="button" class="btn btn-outline" style="font-size:.8rem;padding:.35rem .7rem;white-space:nowrap" onclick="addBlankInviteRow()">+ Custom</button>
                            </div>
                        </div>
                        <p class="hint">Select one or more users and click &ldquo;Add Selected&rdquo;, or &ldquo;Custom&rdquo; for unlisted invitees.</p>
                    </div>
                </div>

                <!-- ── Right column: invites (desktop only) ── -->
                <div class="edit-col-right">
                    <label style="font-weight:600;font-size:.875rem;color:#374151;display:block;margin-bottom:.5rem;flex-shrink:0">Invites</label>

                    <!-- Searchable user checklist (top half, scrolls) -->
                    <div id="eUserChecklist">
                        <input type="text" id="eUserSearch" placeholder="Search by name, email, or phone&hellip;"
                               oninput="filterChecklist(this.value)" autocomplete="off"
                               style="width:100%;margin-bottom:.4rem;padding:.42rem .65rem;border:1.5px solid #e2e8f0;border-radius:7px;font-size:.875rem;box-sizing:border-box;flex-shrink:0">
                        <div id="eChecklistItems" style="border:1.5px solid #e2e8f0;border-radius:7px;margin-bottom:.4rem"></div>
                    </div>

                    <!-- Invited rows (bottom half, scrolls independently) -->
                    <div class="invited-section">
                        <div style="display:grid;grid-template-columns:2fr 1.5fr 2fr auto;gap:.25rem;margin-bottom:.3rem;padding:0 .1rem;flex-shrink:0" id="eInviteHeader">
                            <span style="font-size:.72rem;color:#94a3b8;font-weight:600;text-transform:uppercase;letter-spacing:.04em">Username *</span>
                            <span style="font-size:.72rem;color:#94a3b8;font-weight:600;text-transform:uppercase;letter-spacing:.04em">Phone</span>
                            <span style="font-size:.72rem;color:#94a3b8;font-weight:600;text-transform:uppercase;letter-spacing:.04em">Email</span>
                            <span></span>
                        </div>
                        <div id="eInviteList" style="display:flex;flex-direction:column;gap:.3rem"></div>
                        <button type="button" id="eAddCustomBtn" class="btn btn-outline"
                                style="font-size:.8rem;padding:.38rem .7rem;width:100%;margin-top:.5rem;flex-shrink:0"
                                onclick="addBlankInviteRow()">+ Custom Invitee</button>
                        <p class="hint" style="margin-top:.4rem;flex-shrink:0">Click a name to toggle. Use &ldquo;Custom&rdquo; for guests not in the user list.</p>
                    </div>
                </div>

            </div>

            <div class="edit-footer">
                <label style="display:flex;align-items:center;gap:.4rem;font-size:.875rem;cursor:pointer;user-select:none;white-space:nowrap">
                    <input type="checkbox" name="notify_invitees" id="eNotifyInvitees" value="1">
                    Notify invitees by email
                </label>
                <div style="flex:1"></div>
                <button type="submit" class="btn btn-primary" id="eSubmitBtn">Add Event</button>
                <button type="button" class="btn btn-outline" onclick="closeEdit()">Cancel</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<footer>&copy; <?= (new DateTime('now', new DateTimeZone(get_setting('timezone', 'UTC'))))->format('Y') ?> <?= htmlspecialchars($site_name) ?> &nbsp;&mdash;&nbsp; <?= (new DateTime('now', new DateTimeZone(get_setting('timezone', 'UTC'))))->format('F j, Y g:i A') ?> &nbsp;&mdash;&nbsp; v<?= htmlspecialchars(APP_VERSION) ?></footer>

<script>
let currentEvent = null;
const eventComments   = <?= json_encode($ev_comments) ?>;
const eventInvites    = <?= json_encode($ev_invites) ?>;
const CURRENT_USERNAME  = <?= json_encode($current['username'] ?? '') ?>;
const CAL_REDIR         = '/calendar.php?m=<?= htmlspecialchars($monthParam) ?>';
const CAL_CSRF          = <?= json_encode($token) ?>;
const CAL_CURRENT_ID    = <?= json_encode((int)($current['id'] ?? 0)) ?>;
const IS_ADMIN = <?= $isAdmin ? 'true' : 'false' ?>;
<?php if ($isAdmin): ?>
const ALL_USERS = <?= json_encode(array_values($allUsers)) ?>;
<?php endif; ?>

// ── View modal ────────────────────────────────────────────────────────────────
function viewEvent(ev) {
    currentEvent = ev;
    document.getElementById('vTitle').textContent = ev.title;

    let meta = ev.start_date;
    if (ev.end_date && ev.end_date !== ev.start_date) meta += ' \u2013 ' + ev.end_date;
    if (ev.start_time) {
        meta += '  \u00b7  ' + fmt12(ev.start_time);
        if (ev.end_time) meta += ' \u2013 ' + fmt12(ev.end_time);
    }
    document.getElementById('vMeta').textContent = meta;

    const recLabels = {none:'',daily:'Repeats daily',weekly:'Repeats weekly',monthly:'Repeats monthly',yearly:'Repeats yearly'};
    let recTxt = recLabels[ev.recurrence] || '';
    if (recTxt && ev.recurrence_end) recTxt += ' until ' + ev.recurrence_end;
    document.getElementById('vRecurr').textContent = recTxt;

    document.getElementById('vDesc').textContent = ev.description || '';

    const invites  = eventInvites[ev.id] || [];
    const myInvite = CURRENT_USERNAME ? invites.find(inv => inv.username.toLowerCase() === CURRENT_USERNAME.toLowerCase()) : undefined;
    const isInvited = myInvite !== undefined;

    // My RSVP form (shown only when current user is in the invite list)
    const vRsvpWrap = document.getElementById('vRsvpWrap');
    if (vRsvpWrap) {
        if (isInvited) {
            document.getElementById('vRsvpEventId').value = ev.id;
            document.getElementById('vRsvpSelect').value  = myInvite.rsvp || '';
            updateRsvpStatusBadge(myInvite.rsvp || '');
            vRsvpWrap.style.display = '';
        } else {
            vRsvpWrap.style.display = 'none';
        }
    }
    // Sign up button (shown only when NOT yet in the invite list)
    const vSignupWrap = document.getElementById('vSignupWrap');
    if (vSignupWrap) {
        vSignupWrap.style.display = isInvited ? 'none' : '';
        document.getElementById('vSignupBtn').dataset.eid = ev.id;
    }
    renderInvitesPanel(ev.id);
    const _evRedir = '/calendar.php?m=' + ev.start_date.substring(0,7) + '&open=' + ev.id + '&date=' + ev.start_date;
    const vLoginBtn = document.getElementById('vLoginBtn');
    if (vLoginBtn) vLoginBtn.href = '/login.php?redirect=' + encodeURIComponent(_evRedir);
    const vSignupLink = document.getElementById('vSignupLink');
    if (vSignupLink) vSignupLink.href = '/register.php?redirect=' + encodeURIComponent(_evRedir);
    <?php if ($isAdmin): ?>
    document.getElementById('vDeleteId').value = ev.id;
    // Show/configure occurrence-delete only for recurring events
    const isRecurring = ev.recurrence && ev.recurrence !== 'none';
    const occForm = document.getElementById('vDeleteOccForm');
    if (occForm) {
        occForm.style.display = isRecurring ? '' : 'none';
        document.getElementById('vDeleteOccId').value   = ev.id;
        document.getElementById('vDeleteOccDate').value = ev.occurrence_start || ev.start_date;
        document.getElementById('vDeleteAllBtn').textContent = isRecurring ? 'Delete all' : 'Delete';
    }
    <?php endif; ?>

    // Populate comments
    <?php if ($current): ?>
    document.getElementById('vCommentEventId').value  = ev.id;
    document.getElementById('vCommentRedirect').value = CAL_REDIR;
    <?php endif; ?>
    renderCommentsPanel(ev.id);

    document.getElementById('viewModal').classList.add('open');
}
function showSavedBar(msg) {
    const bar = document.getElementById('vSavedBar');
    bar.textContent = msg || 'Saved';
    bar.classList.remove('rsvp-saved-anim');
    bar.style.visibility = 'visible';
    bar.style.opacity    = '1';
    void bar.offsetWidth;
    bar.classList.add('rsvp-saved-anim');
    setTimeout(() => { bar.style.visibility = 'hidden'; bar.classList.remove('rsvp-saved-anim'); }, 3000);
}
function copyEventLink() {
    if (!currentEvent) return;
    const d   = currentEvent.start_date;
    const m   = d.substring(0, 7);
    const url = window.location.origin + '/calendar.php?m=' + m + '&open=' + currentEvent.id + '&date=' + d;
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(url).then(() => showSavedBar('Link copied!'));
    } else {
        const ta = document.createElement('textarea');
        ta.value = url;
        ta.style.cssText = 'position:fixed;opacity:0;pointer-events:none';
        document.body.appendChild(ta);
        ta.select();
        try { document.execCommand('copy'); showSavedBar('Link copied!'); } catch(e) {}
        ta.remove();
    }
}
function renderCommentsPanel(eid) {
    const comments = eventComments[eid] || [];
    const heading  = document.getElementById('vCommentsHeading');
    const list     = document.getElementById('vCommentsList');
    heading.textContent = comments.length + (comments.length === 1 ? ' Comment' : ' Comments');
    <?php if ($isAdmin): ?>
    const selAllWrap = document.getElementById('vSelAllWrap');
    const selAllCb   = document.getElementById('vSelAll');
    selAllWrap.style.display = comments.length > 0 ? '' : 'none';
    selAllCb.checked = false;
    selAllCb.indeterminate = false;
    document.getElementById('vBulkBar').style.display = 'none';
    document.getElementById('vBulkRedir').value = CAL_REDIR;
    <?php endif; ?>
    list.innerHTML = comments.map(c => {
        const canAct = CAL_CURRENT_ID && (CAL_CURRENT_ID == c.user_id || IS_ADMIN);
        const checkbox = IS_ADMIN
            ? `<input type="checkbox" class="comment-sel cal-comment-sel" value="${c.id}" onchange="onCalSelChange()">`
            : '';
        const actBtns = canAct ? `
            <div class="comment-actions">
                <button type="button" class="comment-delete" title="Edit"
                        onclick="editCalComment(${c.id}, this, ${escHtml(JSON.stringify(c.body))})">&#9998;</button>
                <button type="button" class="comment-delete" title="Delete"
                        onclick="deleteCalComment(${c.id})">&#x2715;</button>
            </div>` : '';
        return `
        <div class="comment" id="ccmt-${c.id}">
            ${checkbox}
            <div class="comment-left">
                <div class="comment-avatar">${c.username.charAt(0).toUpperCase()}</div>
                ${actBtns}
            </div>
            <div class="comment-content">
                <div class="comment-meta">
                    <strong>${escHtml(c.username)}</strong>
                    <span>${escHtml(c.created_at)}</span>
                </div>
                <div class="comment-body" id="ccbody-${c.id}">${escHtml(c.body)}</div>
            </div>
        </div>`;
    }).join('');
}
function renderInvitesPanel(eid) {
    const invites  = eventInvites[eid] || [];
    const vInvDiv  = document.getElementById('vInvites');
    const rsvpClass = {yes:'rsvp-yes', no:'rsvp-no', maybe:'rsvp-maybe'};
    const rsvpText  = {yes:'Yes', no:'No', maybe:'Maybe'};
    if (invites.length) {
        let ih = '<div style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#94a3b8;margin-bottom:.4rem">Invites (' + invites.length + ')</div>';
        ih += '<div style="display:flex;flex-direction:column;gap:.2rem;max-height:8.5rem;overflow-y:auto;padding-right:.25rem">';
        invites.forEach(inv => {
            const badge = inv.rsvp && rsvpClass[inv.rsvp]
                ? '<span class="' + rsvpClass[inv.rsvp] + '">' + rsvpText[inv.rsvp] + '</span>'
                : '<span style="font-size:.75rem;color:#cbd5e1;font-weight:600">--</span>';
            ih += '<div style="font-size:.875rem;color:#334155;display:flex;align-items:center;gap:.5rem;flex-wrap:wrap">';
            ih += '<span style="min-width:52px;text-align:center">' + badge + '</span>';
            ih += escHtml(inv.username);
            if (inv.phone) ih += ' <span style="color:#64748b">&middot; ' + escHtml(inv.phone) + '</span>';
            if (inv.email) ih += ' <span style="color:#64748b">&middot; ' + escHtml(inv.email) + '</span>';
            ih += '</div>';
        });
        ih += '</div>';
        vInvDiv.innerHTML = ih;
        vInvDiv.style.display = '';
    } else {
        vInvDiv.innerHTML = '';
        vInvDiv.style.display = 'none';
    }
}
function closeView() { document.getElementById('viewModal').classList.remove('open'); }

const vCommentForm = document.getElementById('vCommentForm');
if (vCommentForm) {
    vCommentForm.addEventListener('submit', function(e) {
        e.preventDefault();
        const textarea = this.querySelector('textarea[name="body"]');
        const data = new FormData(this);
        fetch('/comment.php', {
            method: 'POST',
            body: data,
            headers: {'X-Requested-With': 'XMLHttpRequest'}
        })
        .then(r => r.json())
        .then(res => {
            if (!res.ok || !res.comment) return;
            const eid = parseInt(document.getElementById('vCommentEventId').value);
            if (!eventComments[eid]) eventComments[eid] = [];
            eventComments[eid].push(res.comment);
            // Append new comment directly — no full re-render needed
            const c      = res.comment;
            const canAct = CAL_CURRENT_ID && CAL_CURRENT_ID == c.user_id;
            const actBtns = canAct ? `
                <div class="comment-actions">
                    <button type="button" class="comment-delete" title="Edit"
                            onclick="editCalComment(${c.id}, this, ${escHtml(JSON.stringify(c.body))})">&#9998;</button>
                    <button type="button" class="comment-delete" title="Delete"
                            onclick="deleteCalComment(${c.id})">&#x2715;</button>
                </div>` : '';
            const div = document.createElement('div');
            div.className = 'comment';
            div.id = 'ccmt-' + c.id;
            div.innerHTML = `
                <div class="comment-left">
                    <div class="comment-avatar">${c.username.charAt(0).toUpperCase()}</div>
                    ${actBtns}
                </div>
                <div class="comment-content">
                    <div class="comment-meta">
                        <strong>${escHtml(c.username)}</strong>
                        <span>${escHtml(c.created_at)}</span>
                    </div>
                    <div class="comment-body" id="ccbody-${c.id}">${escHtml(c.body)}</div>
                </div>`;
            document.getElementById('vCommentsList').appendChild(div);
            // Update heading count
            const cnt = eventComments[eid].length;
            document.getElementById('vCommentsHeading').textContent = cnt + (cnt === 1 ? ' Comment' : ' Comments');
            // Scroll to bottom of comment box
            const scroll = document.getElementById('vCommentsScroll');
            if (scroll) scroll.scrollTop = scroll.scrollHeight;
            textarea.value = '';
            showSavedBar();
        })
        .catch(() => {});
    });
}

function updateRsvpStatusBadge(rsvp) {
    const el = document.getElementById('vRsvpStatus');
    if (!el) return;
    const cls  = {yes:'rsvp-yes', no:'rsvp-no', maybe:'rsvp-maybe'};
    const text = {yes:'Yes',      no:'No',       maybe:'Maybe'};
    if (rsvp && cls[rsvp]) {
        el.innerHTML = '<span class="' + cls[rsvp] + '">' + text[rsvp] + '</span>';
    } else {
        el.innerHTML = '<span style="font-size:.78rem;color:#94a3b8">--</span>';
    }
}

const vRsvpSelect = document.getElementById('vRsvpSelect');
if (vRsvpSelect) {
    vRsvpSelect.addEventListener('change', function() {
        const eid  = parseInt(document.getElementById('vRsvpEventId').value);
        const rsvp = this.value;
        const data = new FormData();
        data.append('csrf_token', document.getElementById('vRsvpCsrf').value);
        data.append('action',     'update_rsvp');
        data.append('event_id',   eid);
        data.append('rsvp',       rsvp);
        fetch('/calendar.php', {
            method: 'POST',
            body: data,
            headers: {'X-Requested-With': 'XMLHttpRequest'}
        })
        .then(r => r.json())
        .then(res => {
            if (!res.ok) return;
            const list = eventInvites[eid];
            if (list) {
                const inv = list.find(i => i.username.toLowerCase() === CURRENT_USERNAME.toLowerCase());
                if (inv) inv.rsvp = rsvp || null;
            }
            updateRsvpStatusBadge(rsvp);
            renderInvitesPanel(eid);
            showSavedBar();
        })
        .catch(() => {});
    });
}

const vSignupBtn = document.getElementById('vSignupBtn');
if (vSignupBtn) {
    vSignupBtn.addEventListener('click', function() {
        const eid  = parseInt(this.dataset.eid);
        const data = new FormData();
        data.append('csrf_token', CAL_CSRF);
        data.append('action', 'self_signup');
        data.append('event_id', eid);
        fetch('/calendar.php', {
            method: 'POST',
            body: data,
            headers: {'X-Requested-With': 'XMLHttpRequest'}
        })
        .then(r => r.json())
        .then(res => {
            if (!res.ok) return;
            if (!eventInvites[eid]) eventInvites[eid] = [];
            eventInvites[eid].push(res.invite);
            renderInvitesPanel(eid);
            // Swap signup button for RSVP form
            document.getElementById('vSignupWrap').style.display = 'none';
            const vRsvpW = document.getElementById('vRsvpWrap');
            if (vRsvpW) {
                document.getElementById('vRsvpEventId').value = eid;
                document.getElementById('vRsvpSelect').value  = '';
                updateRsvpStatusBadge('');
                vRsvpW.style.display = '';
            }
            showSavedBar('Signed up!');
        })
        .catch(() => {});
    });
}

function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function editCalComment(id, btn, origBody) {
    const bodyEl = document.getElementById('ccbody-' + id);
    bodyEl.innerHTML = '';
    const form = document.createElement('form');
    form.style.cssText = 'margin:0';
    form.innerHTML = `
        <input type="hidden" name="csrf_token" value="${CAL_CSRF}">
        <input type="hidden" name="action" value="edit">
        <input type="hidden" name="comment_id" value="${id}">
        <textarea name="body" required maxlength="2000"
            style="width:100%;min-height:60px;resize:vertical;font-size:.875rem;padding:.4rem .65rem;border:1px solid #2563eb;border-radius:6px;font-family:inherit;line-height:1.6">${escHtml(origBody)}</textarea>
        <div style="display:flex;gap:.5rem;margin-top:.35rem">
            <button type="submit" class="btn btn-primary" style="font-size:.78rem;padding:.3rem .8rem">Save</button>
            <button type="button" class="btn btn-outline" style="font-size:.78rem;padding:.3rem .8rem">Cancel</button>
        </div>`;
    bodyEl.appendChild(form);
    form.querySelector('textarea').focus();
    btn.style.display = 'none';

    form.querySelector('.btn-outline').addEventListener('click', () => cancelCalEdit(id, btn, origBody));

    form.addEventListener('submit', function(e) {
        e.preventDefault();
        const data = new FormData(this);
        fetch('/comment.php', {
            method: 'POST',
            body: data,
            headers: {'X-Requested-With': 'XMLHttpRequest'}
        })
        .then(r => r.json())
        .then(res => {
            if (!res.ok) return;
            // Update in-memory cache
            const eid = parseInt(document.getElementById('vCommentEventId').value);
            if (eventComments[eid]) {
                const cm = eventComments[eid].find(c => c.id == id);
                if (cm) cm.body = res.body;
            }
            // Restore body text and show edit button
            bodyEl.textContent = res.body;
            btn.style.display = '';
            showSavedBar();
        })
        .catch(() => {});
    });
}

function cancelCalEdit(id, cancelBtn, origBody) {
    const bodyEl = document.getElementById('ccbody-' + id);
    bodyEl.textContent = origBody;
    const actions = bodyEl.closest('.comment').querySelector('.comment-actions');
    actions.querySelectorAll('button[title="Edit"]').forEach(b => b.style.display = '');
}
function deleteCalComment(id) {
    if (!confirm('Delete this comment?')) return;
    const data = new FormData();
    data.append('csrf_token', CAL_CSRF);
    data.append('action', 'delete');
    data.append('comment_id', id);
    fetch('/comment.php', {
        method: 'POST',
        body: data,
        headers: {'X-Requested-With': 'XMLHttpRequest'}
    })
    .then(r => r.json())
    .then(res => {
        if (!res.ok) return;
        const el = document.getElementById('ccmt-' + id);
        if (el) el.remove();
        const eid = parseInt(document.getElementById('vCommentEventId').value);
        if (eventComments[eid]) {
            eventComments[eid] = eventComments[eid].filter(c => c.id != id);
            const cnt = eventComments[eid].length;
            document.getElementById('vCommentsHeading').textContent = cnt + (cnt === 1 ? ' Comment' : ' Comments');
        }
        if (IS_ADMIN) {
            const selAllWrap = document.getElementById('vSelAllWrap');
            if (selAllWrap) selAllWrap.style.display = document.querySelectorAll('.cal-comment-sel').length > 0 ? '' : 'none';
            onCalSelChange();
        }
    })
    .catch(() => {});
}

function onCalSelChange() {
    const all     = document.querySelectorAll('.cal-comment-sel');
    const checked = document.querySelectorAll('.cal-comment-sel:checked');
    const bar     = document.getElementById('vBulkBar');
    const countEl = document.getElementById('vBulkCount');
    const selAll  = document.getElementById('vSelAll');
    bar.style.display = checked.length > 0 ? '' : 'none';
    countEl.textContent = checked.length + ' selected';
    selAll.indeterminate = checked.length > 0 && checked.length < all.length;
    selAll.checked = all.length > 0 && checked.length === all.length;
}

function toggleCalSelAll(cb) {
    document.querySelectorAll('.cal-comment-sel').forEach(c => c.checked = cb.checked);
    onCalSelChange();
}

function clearCalSel() {
    document.querySelectorAll('.cal-comment-sel').forEach(c => c.checked = false);
    onCalSelChange();
}

function prepareCalBulkDelete(form) {
    const ids = Array.from(document.querySelectorAll('.cal-comment-sel:checked')).map(c => parseInt(c.value));
    if (!ids.length) return false;
    if (!confirm('Delete ' + ids.length + ' comment' + (ids.length !== 1 ? 's' : '') + '?')) return false;
    document.getElementById('vBulkIds').value = JSON.stringify(ids);
    return true;
}

<?php if ($isAdmin): ?>
// ── Edit / Add modal ──────────────────────────────────────────────────────────
function openAddModal(date) {
    document.getElementById('editModalTitle').textContent = 'Add Event';
    document.getElementById('eAction').value  = 'add';
    document.getElementById('eId').value      = '';
    document.getElementById('eTitle').value   = '';
    document.getElementById('eStartDate').value = date || '';
    document.getElementById('eEndDate').value   = '';
    document.getElementById('eStartTime').value = '';
    document.getElementById('eEndTime').value   = '';
    document.getElementById('eDesc').value      = '';
    document.getElementById('eRecurrence').value = 'none';
    document.getElementById('eRecEnd').value      = '';
    toggleRecEnd('none');
    document.getElementById('eSubmitBtn').textContent = 'Add Event';
    selectColor('#2563eb');
    document.getElementById('eInviteList').innerHTML = '';
    document.getElementById('eUserSelect').selectedIndex = -1;
    document.getElementById('eNotifyInvitees').checked = false;
    syncChecklistState();
    document.getElementById('editModal').classList.add('open');
    document.getElementById('eTitle').focus();
}
function openEditModal(ev) {
    currentEvent = ev;
    closeView();
    document.getElementById('editModalTitle').textContent = 'Edit Event';
    document.getElementById('eAction').value    = 'edit';
    document.getElementById('eId').value        = ev.id;
    document.getElementById('eTitle').value     = ev.title;
    document.getElementById('eStartDate').value = ev.start_date;
    document.getElementById('eEndDate').value   = ev.end_date || '';
    document.getElementById('eStartTime').value = ev.start_time || '';
    document.getElementById('eEndTime').value   = ev.end_time || '';
    document.getElementById('eDesc').value      = ev.description || '';
    document.getElementById('eRecurrence').value  = ev.recurrence || 'none';
    document.getElementById('eRecEnd').value      = ev.recurrence_end || '';
    toggleRecEnd(ev.recurrence || 'none');
    document.getElementById('eSubmitBtn').textContent = 'Save Changes';
    selectColor(ev.color || '#2563eb');
    document.getElementById('eInviteList').innerHTML = '';
    document.getElementById('eUserSelect').selectedIndex = -1;
    document.getElementById('eUserSearch').value = '';
    document.getElementById('eNotifyInvitees').checked = false;
    filterChecklist('');
    (eventInvites[ev.id] || []).forEach(inv => addInviteRow(inv.username, inv.phone || '', inv.email || '', inv.rsvp || ''));
    syncChecklistState();
    document.getElementById('editModal').classList.add('open');
    document.getElementById('eTitle').focus();
}
function editFromView() { openEditModal(currentEvent); }
function closeEdit() { document.getElementById('editModal').classList.remove('open'); }

function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}
const RSVP_LABELS = {'':'', yes:'Yes', no:'No', maybe:'Maybe'};
function addInviteRow(username, phone, email, rsvp) {
    const list = document.getElementById('eInviteList');
    const row  = document.createElement('div');
    row.className = 'invite-row';
    const rsvpVal = RSVP_LABELS.hasOwnProperty(rsvp) ? rsvp : '';
    row.innerHTML =
        '<input type="text"  name="invite_username[]" value="' + escHtml(username) + '" placeholder="Username *" required>' +
        '<input type="text"  name="invite_phone[]"    value="' + escHtml(phone)    + '" placeholder="Phone">' +
        '<input type="email" name="invite_email[]"    value="' + escHtml(email)    + '" placeholder="Email">' +
        '<select name="invite_rsvp[]" style="padding:.38rem .4rem;border:1.5px solid #e2e8f0;border-radius:6px;font-size:.85rem;min-width:0;background:#fff">' +
            '<option value=""'      + (rsvpVal===''      ? ' selected' : '') + '>--</option>' +
            '<option value="yes"'   + (rsvpVal==='yes'   ? ' selected' : '') + '>Yes</option>' +
            '<option value="no"'    + (rsvpVal==='no'    ? ' selected' : '') + '>No</option>' +
            '<option value="maybe"' + (rsvpVal==='maybe' ? ' selected' : '') + '>Maybe</option>' +
        '</select>' +
        '<button type="button" class="inv-remove" onclick="this.closest(\'.invite-row\').remove();syncChecklistState()">&#x2715;</button>';
    list.appendChild(row);
}
function addSelectedInvites() {
    const sel      = document.getElementById('eUserSelect');
    const existing = Array.from(document.querySelectorAll('#eInviteList [name="invite_username[]"]'))
                          .map(i => i.value.trim().toLowerCase());
    Array.from(sel.selectedOptions).forEach(opt => {
        if (existing.includes(opt.value.toLowerCase())) return;
        addInviteRow(opt.value, opt.dataset.phone || '', opt.dataset.email || '', '');
        existing.push(opt.value.toLowerCase());
    });
    sel.selectedIndex = -1;
}
function addBlankInviteRow() { addInviteRow('', '', '', ''); }

// ── Desktop invite checklist ──────────────────────────────────────────────────
function buildChecklist() {
    const container = document.getElementById('eChecklistItems');
    if (!container) return;
    container.innerHTML = '';
    ALL_USERS.forEach(u => {
        const item = document.createElement('div');
        item.className = 'checklist-item';
        item.dataset.username = u.username.toLowerCase();
        item.dataset.email    = (u.email   || '').toLowerCase();
        item.dataset.phone    = (u.phone   || '').replace(/\D/g,'');
        const sub = [u.email, u.phone].filter(Boolean).join(' · ');
        item.innerHTML =
            '<div class="ci-check">&#x2713;</div>' +
            '<div class="ci-info"><span class="ci-name">' + escHtml(u.username) + '</span>' +
            (sub ? '<span class="ci-sub">' + escHtml(sub) + '</span>' : '') + '</div>' +
            '<span class="ci-badge"></span>';
        item.addEventListener('click', () => {
            toggleUserInvite(u.username, u.phone || '', u.email || '');
        });
        container.appendChild(item);
    });
}
function filterChecklist(q) {
    q = q.toLowerCase().replace(/\D/g,'') || q.toLowerCase();
    const raw = document.getElementById('eUserSearch').value.toLowerCase();
    const digits = raw.replace(/\D/g,'');
    document.querySelectorAll('#eChecklistItems .checklist-item').forEach(item => {
        const match = !raw ||
            item.dataset.username.includes(raw) ||
            item.dataset.email.includes(raw) ||
            (digits && item.dataset.phone.includes(digits));
        item.style.display = match ? '' : 'none';
    });
}
function toggleUserInvite(username, phone, email) {
    const inputs  = Array.from(document.querySelectorAll('#eInviteList [name="invite_username[]"]'));
    const match   = inputs.find(i => i.value.trim().toLowerCase() === username.toLowerCase());
    if (match) {
        match.closest('.invite-row').remove();
    } else {
        addInviteRow(username, phone, email, '');
    }
    syncChecklistState();
}
function syncChecklistState() {
    const invited = Array.from(document.querySelectorAll('#eInviteList [name="invite_username[]"]'))
        .map(i => {
            const row = i.closest('.invite-row');
            return { name: i.value.trim().toLowerCase(), rsvp: row ? row.querySelector('[name="invite_rsvp[]"]').value : '' };
        });
    document.querySelectorAll('#eChecklistItems .checklist-item').forEach(item => {
        const inv = invited.find(i => i.name === item.dataset.username);
        const badge = item.querySelector('.ci-badge');
        if (inv) {
            item.classList.add('ci-invited');
            const rsvpMap = { yes:'Yes', no:'No', maybe:'Maybe?', '':'' };
            const cls = inv.rsvp ? 'ci-badge ci-badge-' + inv.rsvp : 'ci-badge';
            badge.className = cls;
            badge.textContent = rsvpMap[inv.rsvp] || '';
        } else {
            item.classList.remove('ci-invited');
            badge.className = 'ci-badge';
            badge.textContent = '';
        }
    });
}
buildChecklist();

function toggleRecEnd(val) {
    document.getElementById('recEndGroup').style.display = val === 'none' ? 'none' : '';
}

function selectColor(c) {
    document.getElementById('eColor').value = c;
    document.querySelectorAll('.color-swatch').forEach(s =>
        s.classList.toggle('selected', s.dataset.color === c));
}
selectColor('#2563eb');
<?php endif; ?>

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') { closeView(); <?php if ($isAdmin): ?>closeEdit();<?php endif; ?> }
});

function fmt12(t) {
    if (!t) return '';
    const [h, m] = t.split(':').map(Number);
    const ampm = h >= 12 ? 'pm' : 'am';
    return ((h % 12) || 12) + ':' + String(m).padStart(2, '0') + ampm;
}

// ── Auto-open event from landing page link ────────────────────────────────────
<?php if ($autoOpenEvent): ?>
viewEvent(<?= json_encode($autoOpenEvent) ?>);
<?php endif; ?>

// ── Week view rendering ───────────────────────────────────────────────────────
<?php if ($viewMode === 'week'): ?>
const WK_BY_DATE  = <?= json_encode($wkByDate) ?>;
const WK_TODAY    = '<?= $today->format('Y-m-d') ?>';
const WK_START    = '<?= $wkStartStr ?>';
const WK_END      = '<?= $wkEndStr ?>';
const GRID_START  = 6;   // 6 AM
const GRID_END    = 23;  // 11 PM (exclusive — last label shown is 10 PM)
const HOUR_PX     = 60;

// Convert 'HH:MM' string to minutes since midnight
function timeToMin(t) {
    if (!t) return 0;
    const [h, m] = t.split(':').map(Number);
    return h * 60 + m;
}

// Convert minutes since midnight to px offset from grid top
function minToY(min) {
    return (min - GRID_START * 60);
}

/**
 * Assign slot columns to overlapping timed events within one day.
 * Returns a new array of event objects augmented with _col and _numCols.
 */
function layoutTimedEvents(events) {
    if (!events.length) return [];

    // Augment with start/end minutes
    const augmented = events.map(ev => {
        const startMin = timeToMin(ev.start_time);
        let endMin = ev.end_time ? timeToMin(ev.end_time) : startMin + 60;
        if (endMin <= startMin) endMin = startMin + 30;
        return { ...ev, _startMin: startMin, _endMin: endMin };
    });

    augmented.sort((a, b) => a._startMin - b._startMin || b._endMin - a._endMin);

    // Greedy column assignment
    const colEnds = [];
    augmented.forEach(ev => {
        let col = -1;
        for (let i = 0; i < colEnds.length; i++) {
            if (colEnds[i] <= ev._startMin) {
                col = i;
                colEnds[i] = ev._endMin;
                break;
            }
        }
        if (col === -1) {
            col = colEnds.length;
            colEnds.push(ev._endMin);
        }
        ev._col = col;
    });

    // For each event, find the max column index of all events it overlaps with,
    // so it knows how wide to be.
    augmented.forEach(ev => {
        let maxCol = 0;
        augmented.forEach(other => {
            if (other._startMin < ev._endMin && other._endMin > ev._startMin) {
                if (other._col > maxCol) maxCol = other._col;
            }
        });
        ev._numCols = maxCol + 1;
    });

    return augmented;
}

function renderDayCol(col, date) {
    const allDayEvs   = (WK_BY_DATE[date] || []).filter(e => !e.start_time);
    const timedEvs    = (WK_BY_DATE[date] || []).filter(e =>  e.start_time);
    const totalPx     = (GRID_END - GRID_START) * HOUR_PX;

    // Hour and half-hour grid lines
    for (let h = GRID_START; h < GRID_END; h++) {
        const y = (h - GRID_START) * HOUR_PX;
        const line = document.createElement('div');
        line.className = 'week-hour-line';
        line.style.top = y + 'px';
        col.appendChild(line);

        const half = document.createElement('div');
        half.className = 'week-half-line';
        half.style.top = (y + 30) + 'px';
        col.appendChild(half);
    }

    // Current-time indicator (today only)
    if (date === WK_TODAY) {
        const now  = new Date();
        const curY = minToY(now.getHours() * 60 + now.getMinutes());
        if (curY >= 0 && curY <= totalPx) {
            const nowLine = document.createElement('div');
            nowLine.className = 'week-now-line';
            nowLine.style.top = curY + 'px';
            col.appendChild(nowLine);
        }
    }

    // Render timed events
    const laid = layoutTimedEvents(timedEvs);
    laid.forEach(ev => {
        const startY   = minToY(ev._startMin);
        const heightPx = Math.max(20, ev._endMin - ev._startMin);
        const leftPct  = (ev._col / ev._numCols) * 100;
        const widthPct = (1 / ev._numCols) * 100;

        const chip = document.createElement('div');
        chip.className = 'week-event';
        chip.style.cssText = [
            'background:' + ev.color,
            'top:' + startY + 'px',
            'height:' + heightPx + 'px',
            'left:calc(' + leftPct + '% + 1px)',
            'width:calc(' + widthPct + '% - 3px)',
        ].join(';');
        chip.title = ev.title;
        chip.addEventListener('click', () => viewEvent(ev));

        const timeStr = fmt12(ev.start_time) + (ev.end_time ? '\u2013' + fmt12(ev.end_time) : '');
        chip.innerHTML = '<span class="week-event-title">' + escHtml(ev.title) + '</span>'
            + (heightPx >= 32 ? '<span class="week-event-time">' + escHtml(timeStr) + '</span>' : '');

        if (IS_ADMIN) {
            const editBtn = document.createElement('button');
            editBtn.className = 'ev-edit-btn';
            editBtn.title = 'Edit event';
            editBtn.textContent = '\u270e';
            editBtn.addEventListener('click', e => { e.stopPropagation(); openEditModal(ev); });
            chip.appendChild(editBtn);
        }

        col.appendChild(chip);
    });
}

function initWeekView() {
    const gutter = document.getElementById('weekTimeGutter');

    // Hour labels in the gutter
    for (let h = GRID_START; h <= GRID_END; h++) {
        const lbl = document.createElement('div');
        lbl.className = 'week-hour-label';
        lbl.style.top = ((h - GRID_START) * HOUR_PX) + 'px';
        lbl.textContent = h === 12 ? '12 pm' : h < 12 ? h + ' am' : (h - 12) + ' pm';
        gutter.appendChild(lbl);
    }

    // Render each day column
    document.querySelectorAll('.week-day-col').forEach(col => {
        renderDayCol(col, col.dataset.date);
    });

    // Auto-scroll: if today is in the displayed week, scroll near current time;
    // otherwise scroll to 8 AM.
    const scroll = document.getElementById('weekScroll');
    let scrollH = GRID_START + 2; // default: 8 AM
    if (WK_START <= WK_TODAY && WK_TODAY <= WK_END) {
        const now = new Date();
        scrollH = Math.max(GRID_START, now.getHours() - 1);
    }
    scroll.scrollTop = (scrollH - GRID_START) * HOUR_PX;
}

document.addEventListener('DOMContentLoaded', initWeekView);
<?php endif; ?>

</script>

</body>
</html>
