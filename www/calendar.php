<?php
require_once __DIR__ . '/auth.php';

$db      = get_db();
$current = current_user();
$isAdmin = $current && $current['role'] === 'admin';
$allowUserEvents = get_setting('allow_user_events', '0') === '1';
$canCreateEvents = $isAdmin || ($current && $allowUserEvents);
$allUsers   = $db->query('SELECT username, email, phone FROM users ORDER BY username')->fetchAll();
$allowMaybe = get_setting('allow_maybe_rsvp', '1') === '1';

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

    // Non-admins may only update their own RSVP, self-signup, or self-remove
    // When allow_user_events is on, logged-in users can also add/edit/delete their own events
    $userEventActions = ['add', 'edit', 'delete', 'delete_occurrence'];
    if (!$isAdmin && !in_array($action, ['update_rsvp', 'self_signup', 'self_remove'], true)) {
        if (!$canCreateEvents || !in_array($action, $userEventActions, true)) {
            http_response_code(403); exit('Access denied.');
        }
    }
    $inv_usernames = array_map('trim', (array)($_POST['invite_username'] ?? []));
    $inv_phones    = array_map('trim', (array)($_POST['invite_phone']    ?? []));
    $inv_emails    = array_map('trim', (array)($_POST['invite_email']    ?? []));
    $inv_rsvps     = array_map('trim', (array)($_POST['invite_rsvp']     ?? []));
    $valid_rsvps   = array_merge(['', 'yes', 'no'], $allowMaybe ? ['maybe'] : []);
    // occurrence_date: null = manage base (all occurrences), date = manage this date only
    $invite_occ_date = (preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['occurrence_date'] ?? '')) ? $_POST['occurrence_date'] : null;
    $save_invites  = function(int $eid, array &$new_usernames = []) use ($db, $inv_usernames, $inv_phones, $inv_emails, $inv_rsvps, $valid_rsvps, $invite_occ_date): void {
        if ($invite_occ_date) {
            // Occurrence-specific: only manage rows for this date; leave base rows untouched
            $old = $db->prepare('SELECT LOWER(username) as uname FROM event_invites WHERE event_id=? AND occurrence_date=?');
            $old->execute([$eid, $invite_occ_date]);
            $old_names = array_column($old->fetchAll(), 'uname');
            $db->prepare('DELETE FROM event_invites WHERE event_id=? AND occurrence_date=?')->execute([$eid, $invite_occ_date]);
        } else {
            // Base (all occurrences): only manage rows where occurrence_date IS NULL
            $old = $db->prepare('SELECT LOWER(username) as uname FROM event_invites WHERE event_id=? AND occurrence_date IS NULL');
            $old->execute([$eid]);
            $old_names = array_column($old->fetchAll(), 'uname');
            $db->prepare('DELETE FROM event_invites WHERE event_id=? AND occurrence_date IS NULL')->execute([$eid]);
        }

        $ins = $db->prepare('INSERT INTO event_invites (event_id, username, phone, email, rsvp, rsvp_token, occurrence_date) VALUES (?, ?, ?, ?, ?, ?, ?)');
        // Build a lookup of user contact info for auto-filling
        $userLookup = [];
        $uAll = $db->query('SELECT username, email, phone FROM users ORDER BY username')->fetchAll();
        foreach ($uAll as $uRow) $userLookup[strtolower($uRow['username'])] = $uRow;

        for ($i = 0; $i < count($inv_usernames); $i++) {
            if ($inv_usernames[$i] === '') continue;
            $rsvp = in_array($inv_rsvps[$i] ?? '', $valid_rsvps, true) ? ($inv_rsvps[$i] ?: null) : null;
            // Auto-fill phone/email from user record if not provided
            $uKey = strtolower($inv_usernames[$i]);
            $phone_raw = $inv_phones[$i] !== '' ? $inv_phones[$i] : ($userLookup[$uKey]['phone'] ?? '');
            $email_raw = $inv_emails[$i] !== '' ? $inv_emails[$i] : ($userLookup[$uKey]['email'] ?? '');
            $phone_norm = $phone_raw !== '' ? normalize_phone($phone_raw) : '';
            $token = bin2hex(random_bytes(16));
            $ins->execute([$eid, strtolower($inv_usernames[$i]), $phone_norm ?: null, $email_raw ?: null, $rsvp, $token, $invite_occ_date]);
            // Only track new invitees for base (all-occurrence) saves so notifications go out
            if (!$invite_occ_date && !in_array(strtolower($inv_usernames[$i]), $old_names, true)) {
                $new_usernames[] = strtolower($inv_usernames[$i]);
            }
        }
    };

    // Ownership check: non-admins can only edit/delete their own events
    if (!$isAdmin && in_array($action, ['edit', 'delete', 'delete_occurrence'], true)) {
        $chkId = (int)($_POST['id'] ?? 0);
        if ($chkId > 0) {
            $ownerStmt = $db->prepare('SELECT created_by FROM events WHERE id=?');
            $ownerStmt->execute([$chkId]);
            $ownerRow = $ownerStmt->fetch();
            if (!$ownerRow || (int)$ownerRow['created_by'] !== (int)$current['id']) {
                http_response_code(403); exit('You can only modify your own events.');
            }
        }
    }

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
        if ($title === '' || $sd === '') {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Title and start date are required.'];
        } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $sd) || ($ed && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $ed))) {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Invalid date format.'];
        } else {
            $suppress_notify = !empty($_POST['suppress_notify']);
            $is_poker = !empty($_POST['is_poker']) ? 1 : 0;
            $new_invitee_usernames = [];
            if ($action === 'add') {
                $db->prepare('INSERT INTO events (title, description, start_date, end_date, start_time, end_time, color, created_by, is_poker)
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
                   ->execute([$title, $desc ?: null, $sd, $ed, $st, $et, $color, $current['id'], $is_poker]);
                $notify_eid = (int)$db->lastInsertId();
                $save_invites($notify_eid, $new_invitee_usernames);
                db_log_activity($current['id'], "created event: $title");
                $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Event added.'];
            } else {
                $db->prepare('UPDATE events SET title=?, description=?, start_date=?, end_date=?, start_time=?, end_time=?, color=?, is_poker=? WHERE id=?')
                   ->execute([$title, $desc ?: null, $sd, $ed, $st, $et, $color, $is_poker, $id]);
                $notify_eid = $id;
                $save_invites($id, $new_invitee_usernames);
                db_log_activity($current['id'], "edited event id: $id");
                $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Event updated.'];
            }

            // Build invite email helper
            require_once __DIR__ . '/mail.php';
            require_once __DIR__ . '/sms.php';
            $date_str  = $sd . ($st ? ' at ' . date('g:i A', strtotime($st)) : '');
            $base_url  = get_site_url();

            $build_invite_email = function(string $invite_username) use ($db, $notify_eid, $title, $desc, $date_str, $base_url, $sd, $allowMaybe): ?array {
                // Look up invite token and email
                $inv = $db->prepare('SELECT ei.rsvp_token, COALESCE(NULLIF(ei.email, \'\'), u.email) as email, u.username
                    FROM event_invites ei
                    LEFT JOIN users u ON LOWER(u.username) = LOWER(ei.username)
                    WHERE ei.event_id = ? AND LOWER(ei.username) = LOWER(?) AND ei.occurrence_date IS NULL');
                $inv->execute([$notify_eid, $invite_username]);
                $row = $inv->fetch();
                if (!$row || empty($row['email'])) return null;

                $rsvp_base = $base_url . '/rsvp.php?token=' . urlencode($row['rsvp_token']);
                $yes_url   = $rsvp_base . '&r=yes';
                $no_url    = $rsvp_base . '&r=no';
                $maybe_url = $rsvp_base . '&r=maybe';

                $month_str = substr($sd, 0, 7);
                $event_url = $base_url . '/calendar.php?m=' . urlencode($month_str) . '&open=' . $notify_eid . '&date=' . urlencode($sd);

                $html = '<p>You have been invited to <strong>' . htmlspecialchars($title) . '</strong> on ' . htmlspecialchars($date_str) . '.</p>'
                      . ($desc ? '<p>' . nl2br(htmlspecialchars($desc)) . '</p>' : '')
                      . '<p style="margin-top:1.5rem">RSVP now:</p>'
                      . '<p>'
                      . '<a href="' . htmlspecialchars($yes_url) . '" style="display:inline-block;margin:.25rem .3rem;padding:.5rem 1.2rem;border-radius:6px;text-decoration:none;font-weight:600;background:#16a34a;color:#fff">Yes</a>'
                      . '<a href="' . htmlspecialchars($no_url) . '" style="display:inline-block;margin:.25rem .3rem;padding:.5rem 1.2rem;border-radius:6px;text-decoration:none;font-weight:600;background:#dc2626;color:#fff">No</a>'
                      . ($allowMaybe ? '<a href="' . htmlspecialchars($maybe_url) . '" style="display:inline-block;margin:.25rem .3rem;padding:.5rem 1.2rem;border-radius:6px;text-decoration:none;font-weight:600;background:#d97706;color:#fff">Maybe</a>' : '')
                      . '</p>'
                      . '<p style="margin-top:1rem"><a href="' . htmlspecialchars($event_url) . '" style="display:inline-block;padding:.5rem 1.5rem;border-radius:6px;text-decoration:none;font-weight:600;background:#2563eb;color:#fff">Event Details</a></p>';

                return ['email' => $row['email'], 'html' => $html];
            };

            // Notify newly added invitees unless suppressed
            if (!$suppress_notify) {
                if (empty($new_invitee_usernames) && !empty($inv_usernames) && array_filter($inv_usernames)) {
                    db_log_activity($current['id'], "invite emails: 0 new invitees detected (all were existing) for event $notify_eid", 'info');
                }
                if (!empty($new_invitee_usernames)) {
                    $subject = 'You\'re invited: ' . $title;
                    foreach ($new_invitee_usernames as $new_user) {
                        $data = $build_invite_email($new_user);
                        if ($data) {
                            $err = send_email($data['email'], $new_user, $subject, $data['html']);
                            if ($err) db_log_activity($current['id'], "invite email failed for $new_user: $err", 'warning');
                        } else {
                            db_log_activity($current['id'], "invite email skipped for $new_user: no email address found", 'warning');
                        }
                    }
                }

                // On edit, also notify all existing invitees of the update
                if ($action === 'edit') {
                    $subject = 'Event updated: ' . $title;
                    $all_inv = $db->prepare('SELECT LOWER(username) as uname FROM event_invites WHERE event_id=?');
                    $all_inv->execute([$notify_eid]);
                    foreach ($all_inv->fetchAll(PDO::FETCH_COLUMN) as $uname) {
                        if (in_array($uname, $new_invitee_usernames, true)) continue; // already notified
                        $data = $build_invite_email($uname);
                        if ($data) {
                            $err = send_email($data['email'], $uname, $subject, $data['html']);
                            if ($err) db_log_activity($current['id'], "update email failed for $uname: $err", 'warning');
                        }
                    }
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
        $eid     = (int)($_POST['event_id'] ?? 0);
        $rsvp    = in_array($_POST['rsvp'] ?? '', array_merge(['', 'yes', 'no'], $allowMaybe ? ['maybe'] : []), true) ? ($_POST['rsvp'] ?: null) : null;
        $occDate = (preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['occurrence_date'] ?? '')) ? $_POST['occurrence_date'] : null;

        // Admins and event owners may update any invitee's RSVP via target_username
        $target_username = $current['username'];
        $on_behalf = false;
        if (!empty($_POST['target_username']) && trim($_POST['target_username']) !== $current['username']) {
            $evOwner = $db->prepare('SELECT created_by FROM events WHERE id=?');
            $evOwner->execute([$eid]);
            $ownerRow = $evOwner->fetch();
            $isOwner  = $ownerRow && (int)$ownerRow['created_by'] === (int)$current['id'];
            if ($isAdmin || $isOwner) {
                $target_username = trim($_POST['target_username']);
                $on_behalf = true;
            }
        }

        if ($eid > 0) {
            if ($occDate) {
                // Per-occurrence RSVP: upsert occurrence-specific row
                $chk = $db->prepare('SELECT id, rsvp FROM event_invites WHERE event_id=? AND LOWER(username)=LOWER(?) AND occurrence_date=?');
                $chk->execute([$eid, $target_username, $occDate]);
                $existing = $chk->fetch();
                $oldRsvp  = $existing ? ($existing['rsvp'] ?: null) : null;
                if ($existing) {
                    $db->prepare('UPDATE event_invites SET rsvp=? WHERE id=?')->execute([$rsvp, $existing['id']]);
                } else {
                    // Copy contact info from base invite row
                    $baseStmt = $db->prepare('SELECT phone, email FROM event_invites WHERE event_id=? AND LOWER(username)=LOWER(?) AND occurrence_date IS NULL');
                    $baseStmt->execute([$eid, $target_username]);
                    $baseRow = $baseStmt->fetch();
                    $db->prepare('INSERT INTO event_invites (event_id, username, phone, email, rsvp, rsvp_token, occurrence_date) VALUES (?, ?, ?, ?, ?, ?, ?)')
                       ->execute([$eid, strtolower($target_username), $baseRow['phone'] ?? null, $baseRow['email'] ?? null, $rsvp, bin2hex(random_bytes(16)), $occDate]);
                }
            } else {
                // Base RSVP (non-recurring or updating all-occurrence default)
                $oldRsvpStmt = $db->prepare('SELECT rsvp FROM event_invites WHERE event_id=? AND LOWER(username)=LOWER(?) AND occurrence_date IS NULL');
                $oldRsvpStmt->execute([$eid, $target_username]);
                $oldRsvp = ($oldRsvpStmt->fetchColumn()) ?: null;
                $db->prepare('UPDATE event_invites SET rsvp=? WHERE event_id=? AND LOWER(username)=LOWER(?) AND occurrence_date IS NULL')
                   ->execute([$rsvp, $eid, $target_username]);
            }
            db_log_activity($current['id'], "updated RSVP for event id: $eid" . ($occDate ? " on $occDate" : '') . ($on_behalf ? " (on behalf of $target_username)" : ''));

            // Notify event creator only if RSVP actually changed and editor is not acting on behalf
            if (!$on_behalf && $rsvp && $rsvp !== $oldRsvp) {
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
                $db->prepare('INSERT INTO event_invites (event_id, username, phone, email, rsvp, rsvp_token) VALUES (?, ?, ?, ?, NULL, ?)')
                   ->execute([$eid, strtolower($current['username']), $udata['phone'] ?? null, $udata['email'] ?? null, bin2hex(random_bytes(16))]);
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

    if ($action === 'self_remove' && $current) {
        $eid = (int)($_POST['event_id'] ?? 0);
        if ($eid > 0) {
            $db->prepare('DELETE FROM event_invites WHERE event_id=? AND LOWER(username)=LOWER(?)')
               ->execute([$eid, $current['username']]);
            db_log_activity($current['id'], "removed self from event id: $eid");
        }
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
            header('Content-Type: application/json');
            echo json_encode(['ok' => true]);
            exit;
        }
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'You have been removed from the event.'];
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

// Fetch events that overlap the month
$evQuery = $db->prepare(
    "SELECT * FROM events WHERE
       start_date <= ? AND (end_date >= ? OR (end_date IS NULL AND start_date >= ?))
     ORDER BY start_date, start_time"
);
$evQuery->execute([$monthEnd, $monthStart, $monthStart]);
$allEvents = $evQuery->fetchAll();


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
           start_date <= ? AND (end_date >= ? OR (end_date IS NULL AND start_date >= ?))
         ORDER BY start_date, start_time"
    );
    $wkEvQ->execute([$wkEndStr, $wkStartStr, $wkStartStr]);
    $wkAllEvents = $wkEvQ->fetchAll();
    $wkByDate    = build_event_by_date($wkAllEvents, $wkStartStr, $wkEndStr, $local_tz);
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

// Batch-load invites for all events on this page (base + occurrence-specific)
$ev_invites     = [];  // [eid][] — base rows (occurrence_date IS NULL)
$ev_invites_occ = [];  // [eid][occ_date][] — per-occurrence rows
if (!empty($allPageEids)) {
    $iph = implode(',', array_fill(0, count($allPageEids), '?'));
    $is  = $db->prepare("SELECT event_id, username, phone, email, rsvp, occurrence_date FROM event_invites WHERE event_id IN ($iph) ORDER BY username");
    $is->execute($allPageEids);
    foreach ($is->fetchAll() as $inv) {
        if ($inv['occurrence_date'] === null) {
            $ev_invites[$inv['event_id']][] = $inv;
        } else {
            $ev_invites_occ[$inv['event_id']][$inv['occurrence_date']][] = $inv;
        }
    }
}
// Non-admins only see username + rsvp (no contact details)
if (!$isAdmin) {
    foreach ($ev_invites as &$_invList) {
        foreach ($_invList as &$_inv) { unset($_inv['phone'], $_inv['email']); }
    }
    foreach ($ev_invites_occ as &$_occMap) {
        foreach ($_occMap as &$_invList) {
            foreach ($_invList as &$_inv) { unset($_inv['phone'], $_inv['email']); }
        }
    }
    unset($_invList, $_inv, $_occMap);
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
            border-radius: 10px; overflow: hidden; width: 100%;
        }
        .cal-dow {
            background: #f8fafc; padding: .45rem .5rem;
            text-align: center; font-size: .75rem; font-weight: 600;
            color: #64748b; text-transform: uppercase; letter-spacing: .04em;
            border-right: 1.5px solid #e2e8f0; border-bottom: 1.5px solid #e2e8f0;
            min-width: 0; overflow: hidden;
        }
        .cal-cell {
            min-height: 100px; padding: .35rem .4rem;
            border-right: 1.5px solid #e2e8f0; border-bottom: 1.5px solid #e2e8f0;
            background: #fff; vertical-align: top; position: relative;
            min-width: 0; overflow: hidden;
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
        /* ── Edit modal ── */
        #editModal .modal { max-width:min(96vw,860px);display:flex;flex-direction:column;padding:0;overflow:hidden; }
        #editModal .modal-header { padding:.9rem 1.25rem;margin-bottom:0;border-bottom:1px solid #e2e8f0;flex-shrink:0; }
        #editModal form { display:flex;flex-direction:column;flex:1;min-height:0;overflow-y:auto; }

        /* Header row: color dot + title + date + time + duration */
        .edit-header-row { display:flex;align-items:center;gap:.6rem;padding:1rem 1.25rem .75rem;flex-wrap:wrap;flex-shrink:0; }
        .edit-header-row .form-group { margin:0; }
        #eColorDot { width:38px;height:38px;border-radius:50%;cursor:pointer;border:3px solid transparent;flex-shrink:0;transition:border-color .15s,box-shadow .15s;position:relative; }
        #eColorDot:hover { border-color:#1e293b; }
        #eColorDot.open { box-shadow:0 0 0 3px rgba(37,99,235,.3);border-color:#2563eb; }
        #eColorPicker { position:absolute;top:calc(100% + 6px);left:0;background:#fff;border:1.5px solid #e2e8f0;border-radius:10px;padding:.6rem .75rem;display:none;gap:.5rem;z-index:9999;box-shadow:0 8px 24px rgba(0,0,0,.15); }
        #eColorPicker.open { display:flex; }
        #eColorPicker .color-swatch { width:26px;height:26px; }
        #eColorDotWrap { position:relative;flex-shrink:0; }
        .edit-title-input { flex:1;min-width:140px;padding:.45rem .7rem;border:1.5px solid #e2e8f0;border-radius:7px;font-size:.95rem;font-weight:500; }
        .edit-title-input:focus { outline:none;border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.08); }
        .edit-hdr-label { font-size:.72rem;color:#94a3b8;font-weight:600;text-transform:uppercase;letter-spacing:.04em;margin-bottom:.2rem;display:block; }
        .edit-hdr-field { display:flex;flex-direction:column; }
        .edit-hdr-dur { display:flex;align-items:center;gap:.3rem; }
        .edit-hdr-dur input { width:4.5rem;padding:.45rem .5rem;border:1.5px solid #e2e8f0;border-radius:7px;font-size:.875rem;text-align:center; }
        .edit-hdr-dur input:focus { outline:none;border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.08); }
        .edit-hdr-dur span { font-size:.8rem;color:#64748b;white-space:nowrap; }
        .edit-time-selects { display:flex;gap:.25rem; }
        .edit-time-selects select { padding:.42rem .3rem;border:1.5px solid #e2e8f0;border-radius:7px;font-size:.875rem;background:#fff;cursor:pointer; }
        .edit-time-selects select:focus { outline:none;border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.08); }

        /* Invite panel */
        .edit-invite-panel { display:grid;grid-template-columns:1fr 1fr;gap:.75rem;padding:0 1.25rem;flex-shrink:0; }
        .invite-pane { display:flex;flex-direction:column;border:1.5px solid #e2e8f0;border-radius:8px;overflow:hidden;height:220px; }
        .invite-pane-header { background:#f8fafc;padding:.35rem .65rem;font-size:.7rem;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.05em;flex-shrink:0;border-bottom:1px solid #e2e8f0; }
        .invite-pane-search { width:100%;padding:.38rem .65rem;border:none;border-bottom:1.5px solid #e2e8f0;font-size:.85rem;box-sizing:border-box;flex-shrink:0; }
        .invite-pane-search:focus { outline:none;border-color:#2563eb; }
        .invite-pane-list { flex:1;overflow-y:auto;list-style:none;margin:0;padding:.2rem; }
        .invite-pane-list li { padding:.35rem .6rem;border-radius:5px;font-size:.875rem;cursor:pointer;user-select:none;white-space:nowrap;overflow:hidden;text-overflow:ellipsis; }
        .invite-pane-list li:hover { background:#f1f5f9; }
        .invite-pane-list li.dimmed { color:#cbd5e1;cursor:default; }
        .invite-pane-list li.dimmed:hover { background:transparent; }
        .invite-pane-list li.custom-row { padding:.2rem .4rem;cursor:default; }
        .invite-pane-list li.custom-row:hover { background:transparent; }
        .custom-row-inner { display:flex;gap:.3rem;align-items:center; }
        .custom-row-inner input { padding:.28rem .45rem;border:1.5px solid #e2e8f0;border-radius:5px;font-size:.8rem;min-width:0; }
        .custom-row-inner .cr-name { flex:2; }
        .custom-row-inner .cr-email { flex:2.5; }
        .custom-row-inner .cr-remove { flex-shrink:0;padding:.2rem .4rem;border:1px solid #e2e8f0;border-radius:5px;background:#fff;cursor:pointer;color:#94a3b8;font-size:.85rem;line-height:1; }
        .custom-row-inner .cr-remove:hover { background:#fee2e2;color:#dc2626;border-color:#fca5a5; }
        /* hidden invite inputs container */
        #eInviteData { display:none; }

        /* Bottom row */
        .edit-bottom-row { display:grid;grid-template-columns:1fr auto;gap:.75rem;padding:.75rem 1.25rem 1rem;align-items:end;flex-shrink:0; }
        .edit-bottom-row textarea { width:100%;resize:vertical;min-height:72px;padding:.5rem .7rem;border:1.5px solid #e2e8f0;border-radius:7px;font-size:.875rem;box-sizing:border-box;font-family:inherit; }
        .edit-bottom-row textarea:focus { outline:none;border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.08); }
        .edit-bottom-actions { display:flex;flex-direction:column;gap:.5rem;align-items:flex-end;justify-content:flex-end; }
        .edit-notify-row { display:flex;align-items:center;gap:.4rem;font-size:.8rem;cursor:pointer;user-select:none;white-space:nowrap;color:#64748b; }
        .pk-toggle-input { display:none; }
        .pk-toggle-slider { position:relative;width:36px;height:20px;background:#cbd5e1;border-radius:99px;transition:background .2s;flex-shrink:0; }
        .pk-toggle-slider::after { content:'';position:absolute;top:2px;left:2px;width:16px;height:16px;background:#fff;border-radius:50%;transition:transform .2s;box-shadow:0 1px 3px rgba(0,0,0,.2); }
        .pk-toggle-input:checked + .pk-toggle-slider { background:#22c55e; }
        .pk-toggle-input:checked + .pk-toggle-slider::after { transform:translateX(16px); }

        /* Color swatches (legacy — kept for color picker) */
        .color-swatches { display: flex; gap: .5rem; flex-wrap: wrap; margin-top: .25rem; }
        .color-swatch {
            width: 28px; height: 28px; border-radius: 50%; cursor: pointer;
            border: 3px solid transparent; transition: border-color .15s;
        }
        .color-swatch.selected,
        .color-swatch:hover { border-color: #1e293b; }

        @media (max-width: 640px) {
            /* Stack header fields vertically */
            .edit-header-row { flex-wrap:wrap;align-items:center;gap:.75rem;padding:1rem; }
            #eColorDotWrap { order:-1; }
            .edit-title-input { order:-1;flex:1 1 calc(100% - 50px);height:auto; }
            .edit-hdr-field { width:calc(50% - .5rem); }
            .edit-hdr-field { width:100%; }
            .edit-hdr-label { font-size:.85rem; }
            .edit-time-selects { gap:.5rem; }
            .edit-time-selects select,
            .edit-hdr-field select,
            .edit-hdr-field input,
            .edit-hdr-dur select { min-height:44px;font-size:1rem;padding:.4rem .5rem; }
            #eColorDot { width:32px;height:32px; }

            /* Invite panes */
            .edit-invite-panel { grid-template-columns:1fr;height:auto;padding:0 1rem; }
            .invite-pane { height:200px; }
            .invite-pane-list li { padding:.5rem .75rem;font-size:.95rem; }
            .invite-pane input[type="text"] { min-height:44px;font-size:1rem; }

            /* Bottom actions full-width */
            .edit-bottom-row { grid-template-columns:1fr;gap:.75rem;padding:.75rem 1rem 1rem; }
            .edit-bottom-actions { flex-direction:column;gap:.5rem; }
            .edit-bottom-actions button,
            .edit-bottom-actions .btn { width:100%;min-height:44px;font-size:.95rem; }
        }
        @keyframes rsvpSavedFade { 0%,60%{opacity:1} 100%{opacity:0} }
        .rsvp-saved-anim { animation: rsvpSavedFade 3s ease forwards; }
        .rsvp-yes   { background:#dcfce7; color:#166534; border-radius:4px; padding:.1rem .4rem; font-size:.75rem; font-weight:600; }
        .rsvp-no    { background:#fee2e2; color:#991b1b; border-radius:4px; padding:.1rem .4rem; font-size:.75rem; font-weight:600; }
        .rsvp-maybe { background:#fef9c3; color:#854d0e; border-radius:4px; padding:.1rem .4rem; font-size:.75rem; font-weight:600; }
        .inv-rsvp-sel { font-size:.75rem; padding:.15rem .3rem; border:1px solid #e2e8f0; border-radius:5px; background:#fff; cursor:pointer; min-width:58px; }
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

            /* Full-screen modals on mobile */
            .modal-overlay {
                padding: 0 !important;
                background: #fff !important;
                align-items: stretch !important;
            }
            .modal-overlay .modal {
                max-width: 100% !important;
                max-height: 100vh !important;
                width: 100% !important;
                height: 100% !important;
                border-radius: 0 !important;
                box-shadow: none !important;
                overflow-y: auto !important;
            }
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
        <?php if ($canCreateEvents): ?>
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
                        <?php if ($isAdmin || ($canCreateEvents && (int)$ev['created_by'] === (int)$current['id'])): ?>
                        <button class="ev-edit-btn" title="Edit event"
                                onclick="event.stopPropagation();openEditModal(<?= htmlspecialchars(json_encode($ev)) ?>)">&#9998;</button>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <?php if ($canCreateEvents): ?>
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
                    <?php if ($isAdmin || ($canCreateEvents && (int)$ev['created_by'] === (int)$current['id'])): ?>
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
            <input type="hidden" id="vRsvpOccDate" value="">
            <div style="font-size:.8rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#2563eb;margin-bottom:.5rem">Are you coming? &mdash; RSVP</div>
            <div style="display:flex;gap:.75rem;align-items:center">
                <div id="vRsvpStatus" style="min-width:62px;text-align:center"></div>
                <select id="vRsvpSelect"
                        style="padding:.42rem .7rem;border:1.5px solid #93c5fd;border-radius:7px;font-size:.9rem;background:#fff;color:#1e3a5f;font-weight:500">
                    <option value="">-- select --</option>
                    <option value="yes">Yes</option>
                    <option value="no">No</option>
                    <?php if ($allowMaybe): ?><option value="maybe">Maybe</option><?php endif; ?>
                </select>
            </div>
        </div>
        <?php endif; ?>
        <div id="vInvites" style="display:none;margin:.25rem 0 0;padding:.6rem 0;border-top:1px solid #f1f5f9"></div>
        <?php if ($current): ?>
        <div id="vSignupWrap" style="display:none;padding:.5rem 0;border-top:1px solid #f1f5f9">
            <button id="vSignupBtn" class="btn btn-primary" style="width:100%;font-size:.875rem">Sign up to attend</button>
        </div>
        <div id="vLeaveWrap" style="display:none;padding:.5rem 0;border-top:1px solid #f1f5f9">
            <button id="vLeaveBtn" class="btn btn-outline" style="width:100%;font-size:.875rem;color:#dc2626;border-color:#fca5a5">Leave this event</button>
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
        <?php if ($canCreateEvents): ?>
        <div class="ev-view-actions" id="vEventActions" style="display:none">
            <a id="vManageGameBtn" href="#" class="btn" style="background:#059669;color:#fff;text-decoration:none">Manage Game</a>
            <button type="button" class="btn btn-primary" onclick="editFromView()">Edit</button>
            <form method="post" action="/calendar.php" style="margin:0" id="vDeleteOccForm" style="display:none">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">
                <input type="hidden" name="action" value="delete_occurrence">
                <input type="hidden" name="id" id="vDeleteOccId" value="">
                <input type="hidden" name="occurrence_date" id="vDeleteOccDate" value="">
                <input type="hidden" name="month_param" value="<?= htmlspecialchars($monthParam) ?>">
            </form>
            <form method="post" action="/calendar.php" style="margin:0"
                  onsubmit="return confirm('Delete this event?')">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" id="vDeleteId">
                <input type="hidden" name="month_param" value="<?= htmlspecialchars($monthParam) ?>">
                <button type="submit" class="btn" style="background:#dc2626;color:#fff">Delete</button>
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

<?php if ($canCreateEvents): ?>
<!-- ── Add / Edit Event Modal ── -->
<div class="modal-overlay" id="editModal" onclick="if(event.target===this)closeEdit()">
    <div class="modal">
        <div class="modal-header">
            <h2 id="editModalTitle">Add Event</h2>
            <button class="modal-close" onclick="closeEdit()">&#x2715;</button>
        </div>
        <form method="post" action="/calendar.php" id="editForm">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">
            <input type="hidden" name="action" id="eAction" value="add">
            <input type="hidden" name="id" id="eId" value="">
            <input type="hidden" name="month_param" value="<?= htmlspecialchars($monthParam) ?>">
            <input type="hidden" name="occurrence_date" id="eOccDate" value="">
            <input type="hidden" name="end_date" id="eEndDate" value="">
            <input type="hidden" name="end_time" id="eEndTime" value="">
            <input type="hidden" name="color" id="eColor" value="#2563eb">

            <!-- ── Row 1: color dot + title + date + time + duration ── -->
            <div class="edit-header-row">
                <div id="eColorDotWrap">
                    <div id="eColorDot" style="background:#2563eb" onclick="toggleColorPicker(event)" title="Pick color"></div>
                    <div id="eColorPicker">
                        <?php foreach (['#2563eb','#16a34a','#dc2626','#d97706','#7c3aed','#0891b2','#db2777'] as $c): ?>
                            <div class="color-swatch" style="background:<?= $c ?>" data-color="<?= $c ?>"
                                 onclick="selectColor('<?= $c ?>')"></div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <input type="text" name="title" id="eTitle" class="edit-title-input" placeholder="Event title" required autocomplete="off">
                <div class="edit-hdr-field">
                    <span class="edit-hdr-label">Date</span>
                    <input type="date" name="start_date" id="eStartDate" required style="padding:.42rem .5rem;border:1.5px solid #e2e8f0;border-radius:7px;font-size:.875rem;">
                </div>
                <div class="edit-hdr-field">
                    <span class="edit-hdr-label">Start Time</span>
                    <div class="edit-time-selects">
                        <select id="eTimeHour">
                            <option value="">--</option>
                            <?php for ($h = 1; $h <= 12; $h++): ?>
                            <option value="<?= $h ?>"><?= $h ?></option>
                            <?php endfor; ?>
                        </select>
                        <select id="eTimeMin">
                            <option value="00">00</option>
                            <option value="15">15</option>
                            <option value="30">30</option>
                            <option value="45">45</option>
                        </select>
                        <select id="eTimeAmPm">
                            <option value="AM">AM</option>
                            <option value="PM">PM</option>
                        </select>
                    </div>
                    <input type="hidden" name="start_time" id="eStartTime">
                </div>
                <div class="edit-hdr-field">
                    <span class="edit-hdr-label">Duration</span>
                    <select id="eDuration" style="padding:.42rem .4rem;border:1.5px solid #e2e8f0;border-radius:7px;font-size:.875rem;background:#fff;cursor:pointer;">
                        <option value="">—</option>
                        <option value="0.25">15 min</option>
                        <option value="0.5">30 min</option>
                        <option value="0.75">45 min</option>
                        <option value="1">1 hr</option>
                        <option value="1.5">1.5 hrs</option>
                        <option value="2">2 hrs</option>
                        <option value="2.5">2.5 hrs</option>
                        <option value="3">3 hrs</option>
                        <option value="4">4 hrs</option>
                        <option value="6">6 hrs</option>
                        <option value="8">8 hrs</option>
                    </select>
                </div>
            </div>

            <!-- ── Row 2: dual-pane invite panel ── -->
            <div class="edit-invite-panel">
                <!-- Left: all users -->
                <div class="invite-pane">
                    <div class="invite-pane-header">All Users &mdash; double-click to invite</div>
                    <input type="text" id="eUserSearch" class="invite-pane-search"
                           placeholder="<?= $isAdmin ? 'Search name, email, phone&hellip;' : 'Search name&hellip;' ?>"
                           oninput="filterAllUsers(this.value)" autocomplete="off">
                    <ul class="invite-pane-list" id="eAllUsersList"></ul>
                </div>
                <!-- Right: invited users -->
                <div class="invite-pane">
                    <div class="invite-pane-header">Invited &mdash; double-click to remove</div>
                    <ul class="invite-pane-list" id="eInvitedList"></ul>
                </div>
            </div>
            <!-- Hidden inputs synced from invite lists -->
            <div id="eInviteData"></div>

            <!-- ── Row 3: description + actions ── -->
            <div class="edit-bottom-row">
                <div>
                    <span class="edit-hdr-label" style="margin-bottom:.3rem">Description <span style="color:#94a3b8;font-weight:400;text-transform:none;letter-spacing:0">(optional)</span></span>
                    <textarea name="description" id="eDesc" rows="3"></textarea>
                </div>
                <div class="edit-bottom-actions">
                    <button type="button" class="btn btn-outline" style="font-size:.8rem;white-space:nowrap" onclick="addBlankInviteRow()">+ Custom Invitee</button>
                    <div style="flex:1"></div>
                    <label class="edit-notify-row" style="display:flex;align-items:center;gap:.5rem;cursor:pointer">
                        <span style="font-size:.82rem;color:#475569">Poker Game</span>
                        <input type="checkbox" name="is_poker" id="eIsPoker" value="1" class="pk-toggle-input">
                        <span class="pk-toggle-slider"></span>
                    </label>
                    <label class="edit-notify-row" style="display:flex;align-items:center;gap:.5rem;cursor:pointer">
                        <span style="font-size:.82rem;color:#475569">Don't Notify</span>
                        <input type="checkbox" name="suppress_notify" id="eSuppressNotify" value="1" class="pk-toggle-input">
                        <span class="pk-toggle-slider"></span>
                    </label>
                    <div style="display:flex;gap:.5rem;">
                        <button type="submit" class="btn btn-primary" id="eSubmitBtn">Add Event</button>
                        <button type="button" class="btn btn-outline" onclick="closeEdit()">Cancel</button>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/_footer.php'; ?>

<script>
let currentEvent = null;
const eventComments      = <?= json_encode($ev_comments) ?>;
const eventInvites       = <?= json_encode($ev_invites) ?>;
const eventInvitesByOcc  = <?= json_encode($ev_invites_occ) ?>;
const CURRENT_USERNAME  = <?= json_encode($current['username'] ?? '') ?>;
const CURRENT_USER_ID   = <?= json_encode($current['id'] ?? null) ?>;
const CAL_REDIR         = '/calendar.php?m=<?= htmlspecialchars($monthParam) ?>';
const CAL_CSRF          = <?= json_encode($token) ?>;
const CAL_CURRENT_ID    = <?= json_encode((int)($current['id'] ?? 0)) ?>;
const IS_ADMIN = <?= $isAdmin ? 'true' : 'false' ?>;
const CAN_CREATE_EVENTS = <?= $canCreateEvents ? 'true' : 'false' ?>;
const ALLOW_MAYBE = <?= $allowMaybe ? 'true' : 'false' ?>;
<?php if ($canCreateEvents): ?>
<?php if ($isAdmin): ?>
const ALL_USERS = <?= json_encode(array_values($allUsers)) ?>;
<?php else: ?>
const ALL_USERS = <?= json_encode(array_values(array_map(function($u) { return ['username' => $u['username']]; }, $allUsers))) ?>;
<?php endif; ?>
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

    document.getElementById('vRecurr').textContent = '';

    document.getElementById('vDesc').textContent = ev.description || '';

    const occDate  = null;
    const invites  = getEffectiveInvites(ev.id, occDate);
    const myInvite = CURRENT_USERNAME ? invites.find(inv => inv.username.toLowerCase() === CURRENT_USERNAME.toLowerCase()) : undefined;
    const isInvited = myInvite !== undefined;

    // My RSVP form (shown only when current user is in the invite list)
    const vRsvpWrap = document.getElementById('vRsvpWrap');
    if (vRsvpWrap) {
        if (isInvited) {
            document.getElementById('vRsvpEventId').value  = ev.id;
            document.getElementById('vRsvpOccDate').value  = occDate || '';
            document.getElementById('vRsvpSelect').value   = myInvite.rsvp || '';
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
    // Leave button (shown when invited and not the event creator)
    const vLeaveWrap = document.getElementById('vLeaveWrap');
    if (vLeaveWrap) {
        const isCreator = CURRENT_USER_ID && ev.created_by == CURRENT_USER_ID;
        vLeaveWrap.style.display = (isInvited && !isCreator) ? '' : 'none';
        document.getElementById('vLeaveBtn').dataset.eid = ev.id;
    }
    const _evRedir = '/calendar.php?m=' + ev.start_date.substring(0,7) + '&open=' + ev.id + '&date=' + ev.start_date;
    const vLoginBtn = document.getElementById('vLoginBtn');
    if (vLoginBtn) vLoginBtn.href = '/login.php?redirect=' + encodeURIComponent(_evRedir);
    const vSignupLink = document.getElementById('vSignupLink');
    if (vSignupLink) vSignupLink.href = '/register.php?redirect=' + encodeURIComponent(_evRedir);
    window._calCanManage = IS_ADMIN || (CURRENT_USER_ID && ev.created_by == CURRENT_USER_ID);
    renderInvitesPanel(ev.id);
    <?php if ($canCreateEvents): ?>
    // Show edit/delete actions only for admins or event owner
    const canManageThis = window._calCanManage;
    const actionsDiv = document.getElementById('vEventActions');
    if (actionsDiv) actionsDiv.style.display = canManageThis ? '' : 'none';
    if (canManageThis) {
        const delId = document.getElementById('vDeleteId');
        if (delId) delId.value = ev.id;
        const occForm = document.getElementById('vDeleteOccForm');
        if (occForm) occForm.style.display = 'none';
        const mgBtn = document.getElementById('vManageGameBtn');
        if (mgBtn) {
            if (parseInt(ev.is_poker)) {
                mgBtn.href = '/checkin.php?event_id=' + ev.id;
                mgBtn.style.display = 'inline-block';
            } else {
                mgBtn.style.display = 'none';
            }
        }
    }
    <?php endif; ?>

    // Populate comments
    <?php if ($current): ?>
    document.getElementById('vCommentEventId').value  = ev.id;
    document.getElementById('vCommentRedirect').value = CAL_REDIR;
    <?php endif; ?>
    renderCommentsPanel(ev.id);

    startRsvpPoll(ev.id);

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
    const invites    = getEffectiveInvites(eid, null);
    const vInvDiv    = document.getElementById('vInvites');
    const canManage  = window._calCanManage || false;
    const rsvpClass  = {yes:'rsvp-yes', no:'rsvp-no', maybe:'rsvp-maybe'};
    const rsvpText   = {yes:'Yes', no:'No', maybe:'Maybe'};
    if (invites.length) {
        let ih = '<div style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#94a3b8;margin-bottom:.4rem">Invites (' + invites.length + ')</div>';
        ih += '<div style="display:flex;flex-direction:column;gap:.2rem;max-height:8.5rem;overflow-y:auto;padding-right:.25rem">';
        invites.forEach(inv => {
            ih += '<div style="font-size:.875rem;color:#334155;display:flex;align-items:center;gap:.5rem;flex-wrap:wrap">';
            if (canManage) {
                const r = inv.rsvp || '';
                ih += '<select class="inv-rsvp-sel" data-eid="' + eid + '" data-username="' + escHtml(inv.username) + '">'
                    + '<option value=""'      + (r===''      ?' selected':'') + '>--</option>'
                    + '<option value="yes"'   + (r==='yes'   ?' selected':'') + '>Yes</option>'
                    + '<option value="no"'    + (r==='no'    ?' selected':'') + '>No</option>'
                    + (ALLOW_MAYBE ? '<option value="maybe"' + (r==='maybe'?' selected':'') + '>Maybe</option>' : '')
                    + '</select>';
            } else {
                const badge = inv.rsvp && rsvpClass[inv.rsvp]
                    ? '<span class="' + rsvpClass[inv.rsvp] + '">' + rsvpText[inv.rsvp] + '</span>'
                    : '<span style="font-size:.75rem;color:#cbd5e1;font-weight:600">--</span>';
                ih += '<span style="min-width:52px;text-align:center">' + badge + '</span>';
            }
            ih += escHtml(inv.username);
            if (IS_ADMIN && inv.phone) ih += ' <span style="color:#64748b">&middot; ' + escHtml(inv.phone) + '</span>';
            if (IS_ADMIN && inv.email) ih += ' <span style="color:#64748b">&middot; ' + escHtml(inv.email) + '</span>';
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
// Returns the effective invite list for an event occurrence.
// Base rows are used as the invite list; occurrence-specific rows override each person's RSVP,
// and any occ-only rows (not on the base list) are appended.
function getEffectiveInvites(eid, occDate) {
    const base = eventInvites[eid] || [];
    if (!occDate) return base;
    const occRows = (eventInvitesByOcc[eid] || {})[occDate] || [];
    const merged = base.map(inv => {
        const ov = occRows.find(o => o.username.toLowerCase() === inv.username.toLowerCase());
        return ov ? Object.assign({}, inv, {rsvp: ov.rsvp}) : inv;
    });
    occRows.forEach(occ => {
        if (!merged.find(m => m.username.toLowerCase() === occ.username.toLowerCase()))
            merged.push(Object.assign({}, occ));
    });
    return merged;
}
function closeView() {
    document.getElementById('viewModal').classList.remove('open');
    if (typeof stopRsvpPoll === 'function') stopRsvpPoll();
}

// ── Live RSVP polling (all users) ────────────────────────────────────────────
let _rsvpPollTimer = null;
let _rsvpPollEid   = null;

function startRsvpPoll(eid) {
    stopRsvpPoll();
    _rsvpPollEid = eid;
    _rsvpPollTimer = setInterval(() => pollRsvps(eid), 4000);
}

function stopRsvpPoll() {
    if (_rsvpPollTimer) { clearInterval(_rsvpPollTimer); _rsvpPollTimer = null; }
    _rsvpPollEid = null;
}

function pollRsvps(eid) {
    if (!document.getElementById('viewModal').classList.contains('open')) { stopRsvpPoll(); return; }
    fetch('/event_invites_dl.php?eid=' + eid, {headers: {'X-Requested-With': 'XMLHttpRequest'}})
        .then(r => r.ok ? r.json() : null)
        .then(data => {
            if (!data || !data.ok) return;
            // Update local cache and re-render only if anything changed
            const oldJson = JSON.stringify(eventInvites[eid] || []);
            const newJson = JSON.stringify(data.base);
            if (oldJson !== newJson) {
                eventInvites[eid] = data.base;
                if (currentEvent && currentEvent.id == eid) renderInvitesPanel(eid);
            }
            // Merge occ overrides
            if (data.occ) {
                const oldOccJson = JSON.stringify((eventInvitesByOcc[eid] || {}));
                const newOccJson = JSON.stringify(data.occ);
                if (oldOccJson !== newOccJson) {
                    eventInvitesByOcc[eid] = data.occ;
                    if (currentEvent && currentEvent.id == eid) renderInvitesPanel(eid);
                }
            }
        })
        .catch(() => {});
}

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
        const eid     = parseInt(document.getElementById('vRsvpEventId').value);
        const rsvp    = this.value;
        const occDate = document.getElementById('vRsvpOccDate').value || '';
        const data = new FormData();
        data.append('csrf_token',     document.getElementById('vRsvpCsrf').value);
        data.append('action',         'update_rsvp');
        data.append('event_id',       eid);
        data.append('rsvp',           rsvp);
        data.append('occurrence_date', occDate);
        fetch('/calendar.php', {
            method: 'POST',
            body: data,
            headers: {'X-Requested-With': 'XMLHttpRequest'}
        })
        .then(r => r.json())
        .then(res => {
            if (!res.ok) return;
            if (occDate) {
                // Update or add occurrence-specific RSVP in local cache
                if (!eventInvitesByOcc[eid]) eventInvitesByOcc[eid] = {};
                if (!eventInvitesByOcc[eid][occDate]) eventInvitesByOcc[eid][occDate] = [];
                const occList = eventInvitesByOcc[eid][occDate];
                const occInv  = occList.find(i => i.username.toLowerCase() === CURRENT_USERNAME.toLowerCase());
                if (occInv) { occInv.rsvp = rsvp || null; }
                else { occList.push({username: CURRENT_USERNAME, rsvp: rsvp || null}); }
            } else {
                const list = eventInvites[eid];
                if (list) {
                    const inv = list.find(i => i.username.toLowerCase() === CURRENT_USERNAME.toLowerCase());
                    if (inv) inv.rsvp = rsvp || null;
                }
            }
            updateRsvpStatusBadge(rsvp);
            renderInvitesPanel(eid);
            showSavedBar();
        })
        .catch(() => {});
    });
}

// Delegated listener: owner/admin RSVP dropdowns in the invites panel
const vInvDiv = document.getElementById('vInvites');
if (vInvDiv) {
    vInvDiv.addEventListener('change', function(e) {
        const sel = e.target.closest('.inv-rsvp-sel');
        if (!sel) return;
        const eid      = parseInt(sel.dataset.eid);
        const username = sel.dataset.username;
        const rsvp     = sel.value;
        const data = new FormData();
        const csrfEl = document.getElementById('vRsvpCsrf');
        if (!csrfEl) return;
        data.append('csrf_token',      csrfEl.value);
        data.append('action',          'update_rsvp');
        data.append('event_id',        eid);
        data.append('rsvp',            rsvp);
        data.append('occurrence_date', '');
        data.append('target_username', username);
        fetch('/calendar.php', {method:'POST', body:data, headers:{'X-Requested-With':'XMLHttpRequest'}})
            .then(r => r.json())
            .then(res => {
                if (!res.ok) return;
                const list = eventInvites[eid];
                if (list) {
                    const inv = list.find(i => i.username.toLowerCase() === username.toLowerCase());
                    if (inv) inv.rsvp = rsvp || null;
                }
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
            // Show leave button, hide signup
            const vLW = document.getElementById('vLeaveWrap');
            if (vLW) { vLW.style.display = ''; document.getElementById('vLeaveBtn').dataset.eid = eid; }
        })
        .catch(() => {});
    });
}

const vLeaveBtn = document.getElementById('vLeaveBtn');
if (vLeaveBtn) {
    vLeaveBtn.addEventListener('click', function() {
        if (!confirm('Remove yourself from this event?')) return;
        const eid  = parseInt(this.dataset.eid);
        const data = new FormData();
        data.append('csrf_token', CAL_CSRF);
        data.append('action', 'self_remove');
        data.append('event_id', eid);
        fetch('/calendar.php', {
            method: 'POST',
            body: data,
            headers: {'X-Requested-With': 'XMLHttpRequest'}
        })
        .then(r => r.json())
        .then(res => {
            if (!res.ok) return;
            // Remove from local invites array
            if (eventInvites[eid]) {
                eventInvites[eid] = eventInvites[eid].filter(i => i.username.toLowerCase() !== CURRENT_USERNAME.toLowerCase());
            }
            renderInvitesPanel(eid);
            // Hide RSVP + leave, show signup
            const vRsvpW = document.getElementById('vRsvpWrap');
            if (vRsvpW) vRsvpW.style.display = 'none';
            document.getElementById('vLeaveWrap').style.display = 'none';
            document.getElementById('vSignupWrap').style.display = '';
            document.getElementById('vSignupBtn').dataset.eid = eid;
            showSavedBar('Removed');
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

<?php if ($canCreateEvents): ?>
// ── Edit / Add modal ──────────────────────────────────────────────────────────
function openAddModal(date) {
    openEditModal(null);
    if (date) document.getElementById('eStartDate').value = date;
}

// ── Color picker ──────────────────────────────────────────────────────────────
function toggleColorPicker(e) {
    e.stopPropagation();
    const picker = document.getElementById('eColorPicker');
    const dot    = document.getElementById('eColorDot');
    const open   = picker.classList.toggle('open');
    dot.classList.toggle('open', open);
}
function closeColorPicker() {
    const picker = document.getElementById('eColorPicker');
    const dot    = document.getElementById('eColorDot');
    if (picker) picker.classList.remove('open');
    if (dot)    dot.classList.remove('open');
}
document.addEventListener('click', e => {
    const wrap = document.getElementById('eColorDotWrap');
    if (wrap && !wrap.contains(e.target)) closeColorPicker();
});
function selectColor(c) {
    const colorInput = document.getElementById('eColor');
    const dot        = document.getElementById('eColorDot');
    if (colorInput) colorInput.value = c;
    if (dot) dot.style.background = c;
    document.querySelectorAll('#eColorPicker .color-swatch').forEach(s =>
        s.classList.toggle('selected', s.dataset.color === c));
    closeColorPicker();
}
selectColor('#2563eb');

// ── All-users pane ────────────────────────────────────────────────────────────
function buildAllUsersList() {
    const ul = document.getElementById('eAllUsersList');
    ul.innerHTML = '';
    ALL_USERS.forEach(u => {
        const li = document.createElement('li');
        li.dataset.username = u.username.toLowerCase();
        li.dataset.email    = (u.email || '').toLowerCase();
        li.dataset.phone    = (u.phone || '').replace(/\D/g,'');
        li.dataset.uname    = u.username;
        li.dataset.uemail   = u.email   || '';
        li.dataset.uphone   = u.phone   || '';
        li.textContent = u.username;
        li.title = 'Double-click to invite';
        li.addEventListener('dblclick', () => inviteUser(li.dataset.uname, li.dataset.uphone, li.dataset.uemail));
        ul.appendChild(li);
    });
}

function filterAllUsers(q) {
    const raw    = q.toLowerCase();
    const digits = raw.replace(/\D/g,'');
    document.querySelectorAll('#eAllUsersList li:not(.custom-row)').forEach(li => {
        const match = !raw ||
            li.dataset.username.includes(raw) ||
            li.dataset.email.includes(raw) ||
            (digits && li.dataset.phone.includes(digits));
        li.style.display = match ? '' : 'none';
    });
}

// ── Invited pane ──────────────────────────────────────────────────────────────
function inviteUser(username, phone, email, rsvp) {
    // Skip if already invited
    const existing = Array.from(document.querySelectorAll('#eInvitedList li[data-iname]'))
        .map(li => li.dataset.iname.toLowerCase());
    if (existing.includes(username.toLowerCase())) return;

    const li = document.createElement('li');
    li.dataset.iname  = username;
    li.dataset.iphone = phone  || '';
    li.dataset.iemail = email  || '';
    li.dataset.irsvp  = rsvp   || '';
    li.textContent = username;
    li.title = 'Double-click to remove';
    li.addEventListener('dblclick', () => removeInvite(username));
    document.getElementById('eInvitedList').appendChild(li);
    syncInviteState();
}

function removeInvite(username) {
    const li = Array.from(document.querySelectorAll('#eInvitedList li[data-iname]'))
        .find(l => l.dataset.iname.toLowerCase() === username.toLowerCase());
    if (li) li.remove();
    syncInviteState();
}

function addBlankInviteRow() {
    const ul = document.getElementById('eInvitedList');
    const li = document.createElement('li');
    li.className = 'custom-row';
    li.innerHTML = '<div class="custom-row-inner">' +
        '<input type="text"  class="cr-name"  placeholder="Username *">' +
        '<input type="email" class="cr-email" placeholder="Email">' +
        '<button type="button" class="cr-remove" onclick="this.closest(\'li\').remove()">&times;</button>' +
        '</div>';
    ul.appendChild(li);
    li.querySelector('.cr-name').focus();
}

function syncInviteState() {
    const invited = Array.from(document.querySelectorAll('#eInvitedList li[data-iname]'))
        .map(li => li.dataset.iname.toLowerCase());
    document.querySelectorAll('#eAllUsersList li').forEach(li => {
        const isDimmed = invited.includes(li.dataset.username);
        li.classList.toggle('dimmed', isDimmed);
        li.title = isDimmed ? 'Already invited' : 'Double-click to invite';
    });
}

// Sync hidden inputs from invited pane before submit
// ── Time dropdown helpers ─────────────────────────────────────────────────────
function setTimePicker(hhmm) {
    // hhmm: '14:30' (24h) or '' to clear
    const hour  = document.getElementById('eTimeHour');
    const min   = document.getElementById('eTimeMin');
    const ampm  = document.getElementById('eTimeAmPm');
    if (!hhmm) {
        hour.value = ''; min.value = '00'; ampm.value = 'AM';
        return;
    }
    const [h24, m] = hhmm.split(':').map(Number);
    const isPm  = h24 >= 12;
    const h12   = h24 % 12 || 12;
    hour.value  = h12;
    min.value   = String(m).padStart(2, '0');
    ampm.value  = isPm ? 'PM' : 'AM';
}
function getTimePicker() {
    // Returns HH:MM (24h) or '' if no hour selected
    const h = parseInt(document.getElementById('eTimeHour').value);
    if (!h) return '';
    const m    = document.getElementById('eTimeMin').value;
    const isPm = document.getElementById('eTimeAmPm').value === 'PM';
    const h24  = isPm ? (h === 12 ? 12 : h + 12) : (h === 12 ? 0 : h);
    return String(h24).padStart(2, '0') + ':' + m;
}

document.getElementById('editForm').addEventListener('submit', function() {
    // Sync time picker → hidden input
    const st = getTimePicker();
    document.getElementById('eStartTime').value = st;

    // Calculate end_time from start_time + duration
    const dur = parseFloat(document.getElementById('eDuration').value) || 0;
    if (st && dur > 0) {
        const [h, m] = st.split(':').map(Number);
        const total  = h * 60 + m + Math.round(dur * 60);
        const eh = Math.floor(total / 60) % 24;
        const em = total % 60;
        document.getElementById('eEndTime').value = String(eh).padStart(2,'0') + ':' + String(em).padStart(2,'0');
    } else {
        document.getElementById('eEndTime').value = '';
    }

    // Build hidden invite inputs from both panes
    const container = document.getElementById('eInviteData');
    container.innerHTML = '';
    function addHidden(name, val) {
        const inp = document.createElement('input');
        inp.type = 'hidden'; inp.name = name; inp.value = val;
        container.appendChild(inp);
    }
    // Regular invited users
    document.querySelectorAll('#eInvitedList li[data-iname]').forEach(li => {
        addHidden('invite_username[]', li.dataset.iname);
        addHidden('invite_phone[]',    li.dataset.iphone);
        addHidden('invite_email[]',    li.dataset.iemail);
        addHidden('invite_rsvp[]',     li.dataset.irsvp);
    });
    // Custom rows
    document.querySelectorAll('#eInvitedList li.custom-row').forEach(li => {
        const uname = li.querySelector('.cr-name').value.trim();
        const email = li.querySelector('.cr-email').value.trim();
        if (!uname) return;
        addHidden('invite_username[]', uname);
        addHidden('invite_phone[]',    '');
        addHidden('invite_email[]',    email);
        addHidden('invite_rsvp[]',     '');
    });
});

function openEditModal(ev) {
    currentEvent = ev;
    closeView();
    document.getElementById('editModalTitle').textContent = ev ? 'Edit Event' : 'Add Event';
    document.getElementById('eAction').value    = ev ? 'edit' : 'add';
    document.getElementById('eId').value        = ev ? ev.id : '';
    document.getElementById('eOccDate').value   = '';
    document.getElementById('eTitle').value     = ev ? ev.title : '';
    document.getElementById('eStartDate').value = ev ? ev.start_date : new Date().toLocaleDateString('en-CA');
    setTimePicker(ev ? (ev.start_time || '') : '');
    document.getElementById('eDesc').value      = ev ? (ev.description || '') : '';
    document.getElementById('eSuppressNotify').checked = false;
    document.getElementById('eIsPoker').checked = ev ? !!parseInt(ev.is_poker) : true;
    document.getElementById('eUserSearch').value = '';
    document.getElementById('eSubmitBtn').textContent = ev ? 'Save Changes' : 'Add Event';

    // Pre-fill duration from start_time/end_time diff
    const dur = document.getElementById('eDuration');
    if (ev && ev.start_time && ev.end_time) {
        const [sh, sm] = ev.start_time.split(':').map(Number);
        const [eh, em] = ev.end_time.split(':').map(Number);
        const diff = (eh * 60 + em) - (sh * 60 + sm);
        dur.value = diff > 0 ? (diff / 60) : '';
    } else {
        dur.value = '';
    }

    selectColor((ev && ev.color) ? ev.color : '#2563eb');

    // Rebuild all-users list and invited pane
    buildAllUsersList();
    document.getElementById('eInvitedList').innerHTML = '';
    if (ev) {
        (eventInvites[ev.id] || []).forEach(inv =>
            inviteUser(inv.username, inv.phone || '', inv.email || '', inv.rsvp || ''));
    }
    syncInviteState();
    filterAllUsers('');

    document.getElementById('editModal').classList.add('open');
    document.getElementById('eTitle').focus();
}
function editFromView() { openEditModal(currentEvent); }
function closeEdit() { document.getElementById('editModal').classList.remove('open'); }

buildAllUsersList();
<?php endif; ?>

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') { closeView(); <?php if ($canCreateEvents): ?>closeEdit();<?php endif; ?> }
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

        if (IS_ADMIN || (CAN_CREATE_EVENTS && CURRENT_USER_ID && ev.created_by == CURRENT_USER_ID)) {
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
