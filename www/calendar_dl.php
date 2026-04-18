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

    // Permission check: admins can do anything; non-admins are restricted by role and ownership
    $allowUserEvents = get_setting('allow_user_events', '0') === '1';
    $canCreateEvents = $isAdmin || ($current && $allowUserEvents);

    // Helper: check if current user is a manager of the given event
    $isEventManager = function(int $eid) use ($db, $current): bool {
        if (!$current) return false;
        $ms = $db->prepare("SELECT 1 FROM event_invites WHERE event_id=? AND LOWER(username)=LOWER(?) AND event_role='manager' LIMIT 1");
        $ms->execute([$eid, $current['username']]);
        return (bool)$ms->fetch();
    };

    if (!$isAdmin) {
        $ownerActions = ['edit', 'delete', 'delete_occurrence', 'cancel_series', 'uncancel_series', 'remove_invitee'];
        if ($action === 'add') {
            if (!$canCreateEvents) { http_response_code(403); exit('Access denied.'); }
        } elseif (in_array($action, $ownerActions, true)) {
            // Allow if the user owns this event, is an event manager,
            // or is a league owner/manager of the league this event belongs to.
            $chkId    = (int)($_POST['id'] ?? 0);
            $ownerStmt = $db->prepare('SELECT created_by, league_id FROM events WHERE id=?');
            $ownerStmt->execute([$chkId]);
            $ownerRow = $ownerStmt->fetch();
            $isOwner = $ownerRow && (int)$ownerRow['created_by'] === (int)$current['id'];
            $isLeagueMgr = false;
            if ($ownerRow && !empty($ownerRow['league_id'])) {
                $lr = league_role((int)$ownerRow['league_id'], (int)$current['id']);
                $isLeagueMgr = in_array($lr, ['owner', 'manager'], true);
            }
            if (!$isOwner && !$isEventManager($chkId) && !$isLeagueMgr) {
                http_response_code(403); exit('Access denied.');
            }
        } elseif (!in_array($action, ['update_rsvp', 'self_signup', 'remove_self'], true)) {
            http_response_code(403); exit('Access denied.');
        }
    }
    $inv_usernames   = array_map('trim', (array)($_POST['invite_username']   ?? []));
    $inv_phones      = array_map('trim', (array)($_POST['invite_phone']      ?? []));
    $inv_emails      = array_map('trim', (array)($_POST['invite_email']      ?? []));
    $inv_rsvps       = array_map('trim', (array)($_POST['invite_rsvp']       ?? []));
    $inv_roles       = array_map('trim', (array)($_POST['invite_role']       ?? []));
    $inv_sort_orders = array_map('intval', (array)($_POST['invite_sort_order'] ?? []));
    $valid_rsvps   = ['', 'yes', 'no', 'maybe'];
    $save_invites  = function(int $eid) use ($db, $inv_usernames, $inv_phones, $inv_emails, $inv_rsvps, $inv_roles, $inv_sort_orders, $valid_rsvps): void {
        $db->prepare('DELETE FROM event_invites WHERE event_id=?')->execute([$eid]);
        $ins = $db->prepare("INSERT INTO event_invites (event_id, username, phone, email, rsvp, event_role, approval_status, sort_order) VALUES (?, ?, ?, ?, ?, ?, 'approved', ?)");
        for ($i = 0; $i < count($inv_usernames); $i++) {
            if ($inv_usernames[$i] === '') continue;
            $rsvp = in_array($inv_rsvps[$i] ?? '', $valid_rsvps, true) ? ($inv_rsvps[$i] ?: null) : null;
            $role = in_array($inv_roles[$i] ?? '', ['invitee', 'manager'], true) ? $inv_roles[$i] : 'invitee';
            $phone_norm = $inv_phones[$i] !== '' ? normalize_phone($inv_phones[$i]) : '';
            $sortOrd = $inv_sort_orders[$i] ?? ($i + 1);
            $ins->execute([$eid, strtolower($inv_usernames[$i]), $phone_norm ?: null, $inv_emails[$i] ?: null, $rsvp, $role, $sortOrd]);
        }
    };

    // Notify newly-added invitees via their preferred contact method.
    // $new_usernames: list of lowercase usernames that are new (already computed).
    // $next_occ_date: the next upcoming occurrence date to use in the notification.
    $notify_new_invitees = function(int $eid, array $new_usernames, string $evt_title, string $next_occ_date) use ($db): void {
        foreach ($new_usernames as $uname) {
            $urow = $db->prepare('SELECT username, email, phone, preferred_contact FROM users WHERE LOWER(username) = ?');
            $urow->execute([$uname]);
            $udata = $urow->fetch();
            if (!$udata) continue;
            $contact = $udata['preferred_contact'] ?? 'email';
            send_invite_notification($udata['username'], $udata['email'] ?? '', $udata['phone'] ?? '', $contact, $evt_title, $next_occ_date, $eid);
            // Log invite notification so cron won't double-send
            try {
                $db->prepare("INSERT OR IGNORE INTO event_notifications_sent (event_id, occurrence_date, user_identifier, notification_type, sent_at) VALUES (?, ?, ?, 'invite', ?)")
                   ->execute([$eid, $next_occ_date, $uname, date('Y-m-d H:i:s')]);
            } catch (Exception $e) {}
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
        $requires_approval = !empty($_POST['requires_approval']) ? 1 : 0;

        // League + visibility — the creator can only pick leagues they belong to.
        $req_league_id = (int)($_POST['league_id'] ?? 0);
        $league_id     = null;
        if ($req_league_id > 0) {
            $role = league_role($req_league_id, (int)$current['id']);
            if ($role !== null || $isAdmin) {
                $league_id = $req_league_id;
            }
        }
        $visibility = in_array($_POST['visibility'] ?? '', ['public','league','invitees_only'], true)
                      ? $_POST['visibility'] : 'invitees_only';
        if ($visibility === 'league' && $league_id === null) $visibility = 'invitees_only';
        if ($visibility === 'public' && !$isAdmin) $visibility = 'invitees_only';

        // Poker inline fields
        $is_poker = !empty($_POST['is_poker']) ? 1 : 0;
        $poker_game_type   = in_array($_POST['poker_game_type'] ?? '', ['tournament', 'cash'], true) ? $_POST['poker_game_type'] : 'tournament';
        $poker_buyin       = (int)(round(floatval($_POST['poker_buyin'] ?? 20) * 100));
        $poker_tables      = max(1, (int)($_POST['poker_tables'] ?? 1));
        $poker_seats       = max(2, (int)($_POST['poker_seats']  ?? 8));
        $rsvp_deadline_hrs = (int)($_POST['rsvp_deadline_hours'] ?? 0) ?: null;

        if ($title === '' || $sd === '') {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Title and start date are required.'];
        } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $sd) || ($ed && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $ed))) {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Invalid date format.'];
        } else {
            if ($action === 'add') {
                $db->prepare('INSERT INTO events (title, description, start_date, end_date, start_time, end_time, color, recurrence, recurrence_end, created_by, requires_approval, league_id, visibility, is_poker, rsvp_deadline_hours)
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                   ->execute([$title, $desc ?: null, $sd, $ed, $st, $et, $color, $recurrence, $recEnd, $current['id'], $requires_approval, $league_id, $visibility, $is_poker, $rsvp_deadline_hrs]);
                $new_eid = (int)$db->lastInsertId();
                // Auto-create poker session if is_poker
                if ($is_poker) {
                    $chkPs = $db->prepare('SELECT id FROM poker_sessions WHERE event_id = ?');
                    $chkPs->execute([$new_eid]);
                    if (!$chkPs->fetch()) {
                        $db->prepare('INSERT INTO poker_sessions (event_id, buyin_amount, num_tables, seats_per_table, game_type) VALUES (?, ?, ?, ?, ?)')
                           ->execute([$new_eid, $poker_buyin, $poker_tables, $poker_seats, $poker_game_type]);
                    }
                }
                $save_invites($new_eid);
                // For league-wide events, auto-populate invites with current league members.
                if ($visibility === 'league' && $league_id !== null) {
                    $mems = $db->prepare(
                        'SELECT u.username, u.phone, u.email FROM league_members lm JOIN users u ON u.id = lm.user_id WHERE lm.league_id = ? AND lm.user_id != ?'
                    );
                    $mems->execute([$league_id, (int)$current['id']]);
                    $addInv = $db->prepare(
                        "INSERT OR IGNORE INTO event_invites (event_id, username, phone, email, rsvp, approval_status) VALUES (?, ?, ?, ?, NULL, 'approved')"
                    );
                    foreach ($mems->fetchAll() as $mem) {
                        $addInv->execute([$new_eid, $mem['username'], $mem['phone'], $mem['email']]);
                    }
                }
                // For poker events, mark invitees beyond capacity as waitlisted
                if ($is_poker) {
                    $cap = $poker_tables * $poker_seats;
                    $db->prepare(
                        "UPDATE event_invites SET approval_status = 'waitlisted'
                         WHERE event_id = ? AND occurrence_date IS NULL AND sort_order > ?"
                    )->execute([$new_eid, $cap]);
                }
                // For a new event all invitees are "new"; notify only the approved ones
                $all_new = [];
                $invRows = $db->prepare("SELECT LOWER(username) as u, approval_status FROM event_invites WHERE event_id = ? AND occurrence_date IS NULL");
                $invRows->execute([$new_eid]);
                foreach ($invRows->fetchAll() as $_ir) {
                    if ($_ir['approval_status'] === 'approved') $all_new[] = $_ir['u'];
                }
                $notify_new_invitees($new_eid, $all_new, $title, $sd);
                db_log_activity($current['id'], "created event: $title");
                $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Event added.'];
            } else {
                // Capture existing BASE invites before save so we only notify new ones
                $stmt_old = $db->prepare('SELECT LOWER(username) as u FROM event_invites WHERE event_id=? AND occurrence_date IS NULL');
                $stmt_old->execute([$id]);
                $old_inv = array_column($stmt_old->fetchAll(), 'u');

                // If the toggle is being flipped OFF, auto-approve any pending rows so they don't get orphaned.
                if (!$requires_approval) {
                    $prev = $db->prepare('SELECT requires_approval FROM events WHERE id=?');
                    $prev->execute([$id]);
                    if ((int)$prev->fetchColumn() === 1) {
                        $db->prepare("UPDATE event_invites SET approval_status='approved' WHERE event_id=? AND approval_status='pending'")
                           ->execute([$id]);
                    }
                }

                $db->prepare('UPDATE events SET title=?, description=?, start_date=?, end_date=?, start_time=?, end_time=?, color=?, recurrence=?, recurrence_end=?, requires_approval=?, league_id=?, visibility=?, is_poker=?, rsvp_deadline_hours=? WHERE id=?')
                   ->execute([$title, $desc ?: null, $sd, $ed, $st, $et, $color, $recurrence, $recEnd, $requires_approval, $league_id, $visibility, $is_poker, $rsvp_deadline_hrs, $id]);
                // Update or create poker session
                if ($is_poker) {
                    $chkPs = $db->prepare('SELECT id FROM poker_sessions WHERE event_id = ?');
                    $chkPs->execute([$id]);
                    if ($chkPs->fetch()) {
                        $db->prepare('UPDATE poker_sessions SET buyin_amount=?, num_tables=?, seats_per_table=?, game_type=? WHERE event_id=?')
                           ->execute([$poker_buyin, $poker_tables, $poker_seats, $poker_game_type, $id]);
                    } else {
                        $db->prepare('INSERT INTO poker_sessions (event_id, buyin_amount, num_tables, seats_per_table, game_type) VALUES (?, ?, ?, ?, ?)')
                           ->execute([$id, $poker_buyin, $poker_tables, $poker_seats, $poker_game_type]);
                    }
                }

                // ── Feature 1: Only delete/replace BASE rows; leave per-occurrence overrides intact ──
                $db->prepare('DELETE FROM event_invites WHERE event_id=? AND occurrence_date IS NULL')->execute([$id]);

                $today_date   = date('Y-m-d');
                $isRecurring  = $recurrence !== 'none';
                // Creator/manager-added invites auto-approve regardless of the event's requires_approval flag.
                $ins_base     = $db->prepare("INSERT INTO event_invites (event_id, username, phone, email, rsvp, valid_from, approval_status) VALUES (?, ?, ?, ?, ?, ?, 'approved')");
                $new_inv      = [];
                $form_inv     = [];
                for ($i = 0; $i < count($inv_usernames); $i++) {
                    if ($inv_usernames[$i] === '') continue;
                    $uname      = strtolower(trim($inv_usernames[$i]));
                    $form_inv[] = $uname;
                    $rsvp       = in_array($inv_rsvps[$i] ?? '', $valid_rsvps, true) ? ($inv_rsvps[$i] ?: null) : null;
                    $pnorm      = $inv_phones[$i] !== '' ? normalize_phone($inv_phones[$i]) : '';
                    $is_new     = !in_array($uname, $old_inv, true);
                    // New mid-series invitees on recurring events get valid_from = today
                    $vf = ($isRecurring && $is_new) ? $today_date : null;
                    $ins_base->execute([$id, $uname, $pnorm ?: null, $inv_emails[$i] ?: null, $rsvp, $vf]);
                    if ($is_new) $new_inv[] = $uname;
                }
                // Clean up future per-occurrence rows for REMOVED invitees
                $removed = array_diff($old_inv, $form_inv);
                if (!empty($removed)) {
                    $ph = implode(',', array_fill(0, count($removed), '?'));
                    $db->prepare("DELETE FROM event_invites WHERE event_id=? AND LOWER(username) IN ($ph) AND occurrence_date >= ?")
                       ->execute(array_merge([$id], array_values($removed), [$today_date]));
                }

                // Notify new invitees for the next upcoming occurrence
                if (!empty($new_inv)) {
                    $ev_row = $db->prepare('SELECT * FROM events WHERE id=?');
                    $ev_row->execute([$id]);
                    $ev_row = $ev_row->fetch();
                    $exc = load_exceptions($db, [$ev_row]);
                    $next_occ = get_next_occurrence($ev_row, $exc[$id] ?? [], $today_date);
                    $notify_new_invitees($id, $new_inv, $title, $next_occ ?? $sd);
                }

                // Notify existing invitees about the change (if checkbox is checked)
                if (!empty($_POST['notify_invitees']) && !empty($old_inv)) {
                    $month = substr($sd, 0, 7);
                    $evUrl = get_site_url() . '/calendar.php?m=' . urlencode($month) . '&open=' . $id . '&date=' . urlencode($sd);
                    $ph = implode(',', array_fill(0, count($old_inv), '?'));
                    $existInv = $db->prepare("SELECT ei.username, u.email, u.phone, u.preferred_contact FROM event_invites ei JOIN users u ON LOWER(u.username)=LOWER(ei.username) WHERE ei.event_id=? AND LOWER(ei.username) IN ($ph)");
                    $existInv->execute(array_merge([$id], $old_inv));
                    foreach ($existInv->fetchAll() as $inv) {
                        $smsBody  = "\"$title\" has been updated. View: $evUrl";
                        $htmlBody = '<p>The event <strong>' . htmlspecialchars($title) . '</strong> has been updated.</p>'
                                  . '<p style="margin-top:1rem"><a href="' . htmlspecialchars($evUrl) . '" style="background:#2563eb;color:#fff;padding:.5rem 1.2rem;border-radius:6px;text-decoration:none;font-weight:600">View Event</a></p>';
                        send_notification($inv['username'], $inv['email'] ?? '', $inv['phone'] ?? '',
                            $inv['preferred_contact'] ?? 'email',
                            "Event updated: $title", $smsBody, $htmlBody);
                    }
                }

                // For poker events, mark invitees beyond capacity as waitlisted, then promote if seats opened
                if ($is_poker) {
                    $cap = $poker_tables * $poker_seats;
                    $db->prepare(
                        "UPDATE event_invites SET approval_status = 'waitlisted'
                         WHERE event_id = ? AND occurrence_date IS NULL AND sort_order > ? AND approval_status = 'approved'"
                    )->execute([$id, $cap]);
                    maybe_promote_waitlisted($db, $id);
                }
                db_log_activity($current['id'], "edited event id: $id");
                $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Event updated.'];
            }
        }
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $row = $db->prepare('SELECT title, start_date FROM events WHERE id=?');
            $row->execute([$id]);
            $evt = $row->fetch();
            $t = $evt['title'] ?? $id;

            // Notify invitees before deleting
            if ($evt && get_setting('notifications_enabled', '0') === '1') {
                $invStmt = $db->prepare("SELECT ei.username, u.email, u.phone, u.preferred_contact
                    FROM event_invites ei JOIN users u ON LOWER(u.username)=LOWER(ei.username)
                    WHERE ei.event_id=? AND ei.occurrence_date IS NULL");
                $invStmt->execute([$id]);
                foreach ($invStmt->fetchAll() as $inv) {
                    $subject  = 'Cancelled: ' . $t;
                    $smsBody  = "The event \"$t\" on {$evt['start_date']} has been cancelled.";
                    $htmlBody = '<p>The event <strong>' . htmlspecialchars($t) . '</strong> on <strong>' . htmlspecialchars($evt['start_date']) . '</strong> has been <strong>cancelled</strong>.</p>';
                    send_notification($inv['username'], $inv['email'] ?? '', $inv['phone'] ?? '',
                        $inv['preferred_contact'] ?? 'email', $subject, $smsBody, $htmlBody);
                }
            }

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

            // ── Feature 2: Send cancellation notifications to RSVPed invitees ──
            $evt_r = $db->prepare('SELECT title FROM events WHERE id=?');
            $evt_r->execute([$id]);
            $evt_title = $evt_r->fetchColumn();
            if ($evt_title) {
                $occ_inv = get_occurrence_invitees($db, $id, $date, true);
                foreach ($occ_inv as $inv) {
                    if (!in_array($inv['rsvp'] ?? '', ['yes', 'maybe'])) continue;
                    $subject  = 'Cancelled: ' . $evt_title . ' on ' . $date;
                    $smsBody  = "The event \"$evt_title\" on $date has been cancelled.";
                    $htmlBody = '<p>The occurrence of <strong>' . htmlspecialchars($evt_title) . '</strong> on <strong>' . htmlspecialchars($date) . '</strong> has been <strong>cancelled</strong>.</p>';
                    send_notification($inv['username'], $inv['email'] ?? '', $inv['phone'] ?? '',
                        $inv['preferred_contact'] ?? 'email', $subject, $smsBody, $htmlBody);
                }
            }

            db_log_activity($current['id'], "removed occurrence $date from event id: $id");
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Occurrence removed.'];
        }
    }

    // ── Feature 3: Cancel future occurrences of a recurring series ──
    if ($action === 'cancel_series') {
        $id          = (int)($_POST['id'] ?? 0);
        $cancel_from = trim($_POST['cancel_from'] ?? date('Y-m-d'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $cancel_from)) $cancel_from = date('Y-m-d');
        if ($id > 0) {
            $db->prepare('UPDATE events SET cancelled_from=? WHERE id=?')->execute([$cancel_from, $id]);
            // Notify all invitees (base rows) of the cancellation
            $evt_r = $db->prepare('SELECT title FROM events WHERE id=?');
            $evt_r->execute([$id]);
            $evt_title = $evt_r->fetchColumn();
            if ($evt_title) {
                $all_inv = $db->prepare("SELECT ei.username, u.email, u.phone, u.preferred_contact
                                         FROM event_invites ei JOIN users u ON LOWER(u.username)=LOWER(ei.username)
                                         WHERE ei.event_id=? AND ei.occurrence_date IS NULL");
                $all_inv->execute([$id]);
                foreach ($all_inv->fetchAll() as $inv) {
                    $subject  = 'Cancelled: ' . $evt_title . ' (series from ' . $cancel_from . ')';
                    $smsBody  = "All future occurrences of \"$evt_title\" from $cancel_from have been cancelled.";
                    $htmlBody = '<p>All future occurrences of <strong>' . htmlspecialchars($evt_title) . '</strong> from <strong>' . htmlspecialchars($cancel_from) . '</strong> onwards have been <strong>cancelled</strong>.</p>';
                    send_notification($inv['username'], $inv['email'] ?? '', $inv['phone'] ?? '',
                        $inv['preferred_contact'] ?? 'email', $subject, $smsBody, $htmlBody);
                }
            }
            db_log_activity($current['id'], "cancelled series from $cancel_from for event id: $id");
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
                header('Content-Type: application/json');
                echo json_encode(['ok' => true, 'cancelled_from' => $cancel_from]);
                exit;
            }
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Future occurrences cancelled.'];
        }
    }

    // ── Feature 3: Uncancel a series ──
    if ($action === 'uncancel_series') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $db->prepare('UPDATE events SET cancelled_from=NULL WHERE id=?')->execute([$id]);
            db_log_activity($current['id'], "uncancelled series for event id: $id");
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
                header('Content-Type: application/json');
                echo json_encode(['ok' => true]);
                exit;
            }
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Series resumed.'];
        }
    }

    // ── Feature 5: Remove an invitee from all future occurrences ──
    if ($action === 'remove_invitee') {
        $id          = (int)($_POST['id'] ?? 0);
        $target_user = strtolower(trim($_POST['target_username'] ?? ''));
        $today_date  = date('Y-m-d');
        if ($id > 0 && $target_user !== '') {
            // Delete base row and future per-occurrence rows
            $db->prepare("DELETE FROM event_invites WHERE event_id=? AND LOWER(username)=? AND (occurrence_date IS NULL OR occurrence_date >= ?)")
               ->execute([$id, $target_user, $today_date]);
            // Send removal notification
            $evt_r = $db->prepare('SELECT title FROM events WHERE id=?');
            $evt_r->execute([$id]);
            $evt_title = $evt_r->fetchColumn();
            $urow = $db->prepare('SELECT username, email, phone, preferred_contact FROM users WHERE LOWER(username)=?');
            $urow->execute([$target_user]);
            $udata = $urow->fetch();
            if ($udata && $evt_title) {
                $subject  = 'Removed from event: ' . $evt_title;
                $smsBody  = "You have been removed from the event series \"$evt_title\".";
                $htmlBody = '<p>You have been removed from the event series <strong>' . htmlspecialchars($evt_title) . '</strong>.</p>';
                send_notification($udata['username'], $udata['email'] ?? '', $udata['phone'] ?? '',
                    $udata['preferred_contact'] ?? 'email', $subject, $smsBody, $htmlBody);
            }
            db_log_activity($current['id'], "removed $target_user from all occurrences of event id: $id");
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
                header('Content-Type: application/json');
                echo json_encode(['ok' => true]);
                exit;
            }
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Invitee removed from all future occurrences.'];
        }
    }

    if ($action === 'update_rsvp' && $current) {
        $eid      = (int)($_POST['event_id'] ?? 0);
        $occ_date = trim($_POST['occurrence_date'] ?? '');
        if ($occ_date && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $occ_date)) $occ_date = '';
        $rsvp     = in_array($_POST['rsvp'] ?? '', ['', 'yes', 'no', 'maybe'], true) ? ($_POST['rsvp'] ?: null) : null;

        // ── Feature 6: RSVP cutoff — non-admins locked within 1 hour of start ──
        $rsvp_locked = false;
        if (!$isAdmin && $eid > 0) {
            $ev_chk = $db->prepare('SELECT start_date, start_time FROM events WHERE id=?');
            $ev_chk->execute([$eid]);
            $ev_chk = $ev_chk->fetch();
            if ($ev_chk && $ev_chk['start_time']) {
                $use_date = $occ_date ?: $ev_chk['start_date'];
                $startDt  = new DateTime($use_date . ' ' . $ev_chk['start_time'], $local_tz);
                $nowDt    = new DateTime('now', $local_tz);
                if ($startDt->getTimestamp() - $nowDt->getTimestamp() < 3600) {
                    $rsvp_locked = true;
                }
            }
        }

        if ($rsvp_locked) {
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
                header('Content-Type: application/json');
                echo json_encode(['ok' => false, 'locked' => true, 'msg' => 'RSVP is locked — this event starts in less than an hour.']);
                exit;
            }
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'RSVP is locked — this event starts in less than an hour.'];
        } elseif ($eid > 0) {
            // Approval gate: a user can't RSVP for themselves while their invite is pending or denied.
            $statusStmt = $db->prepare('SELECT approval_status FROM event_invites WHERE event_id=? AND LOWER(username)=LOWER(?) AND occurrence_date IS NULL');
            $statusStmt->execute([$eid, $current['username']]);
            $currentApproval = $statusStmt->fetchColumn() ?: 'approved';
            if ($currentApproval === 'waitlisted') {
                $wlMsg = 'You are on the waitlist for this event. You\'ll be notified if a seat opens up.';
                if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
                    header('Content-Type: application/json');
                    echo json_encode(['ok' => false, 'msg' => $wlMsg]);
                    exit;
                }
                $_SESSION['flash'] = ['type' => 'error', 'msg' => $wlMsg];
                header('Location: /calendar.php');
                exit;
            }
            if ($currentApproval !== 'approved') {
                if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
                    header('Content-Type: application/json');
                    echo json_encode(['ok' => false, 'msg' => 'Your spot for this event is waiting for the host to approve.']);
                    exit;
                }
                $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Your spot for this event is waiting for the host to approve.'];
                header('Location: /calendar.php');
                exit;
            }
            if ($occ_date) {
                // Per-occurrence RSVP: update or insert occurrence-specific row
                $chk = $db->prepare('SELECT id FROM event_invites WHERE event_id=? AND LOWER(username)=LOWER(?) AND occurrence_date=?');
                $chk->execute([$eid, $current['username'], $occ_date]);
                if ($chk->fetch()) {
                    $db->prepare('UPDATE event_invites SET rsvp=? WHERE event_id=? AND LOWER(username)=LOWER(?) AND occurrence_date=?')
                       ->execute([$rsvp, $eid, $current['username'], $occ_date]);
                } else {
                    // Copy contact info + approval_status from base row so per-occurrence rows inherit gating.
                    $base_stmt2 = $db->prepare('SELECT phone, email, approval_status FROM event_invites WHERE event_id=? AND LOWER(username)=LOWER(?) AND occurrence_date IS NULL');
                    $base_stmt2->execute([$eid, $current['username']]);
                    $base_row2 = $base_stmt2->fetch() ?: [];
                    $base_approval2 = $base_row2['approval_status'] ?? 'approved';
                    $db->prepare('INSERT INTO event_invites (event_id, username, phone, email, rsvp, occurrence_date, approval_status) VALUES (?, ?, ?, ?, ?, ?, ?)')
                       ->execute([$eid, strtolower($current['username']), $base_row2['phone'] ?? null, $base_row2['email'] ?? null, $rsvp, $occ_date, $base_approval2]);
                }
            } else {
                // Non-recurring: update base row
                $db->prepare('UPDATE event_invites SET rsvp=? WHERE event_id=? AND LOWER(username)=LOWER(?) AND occurrence_date IS NULL')
                   ->execute([$rsvp, $eid, $current['username']]);
            }
            db_log_activity($current['id'], "updated RSVP for event id: $eid" . ($occ_date ? " ($occ_date)" : ''));
            // If they declined, check if a waitlisted invitee should be promoted
            if ($rsvp === 'no') {
                maybe_promote_waitlisted($db, $eid);
            }
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
                header('Content-Type: application/json');
                echo json_encode(['ok' => true]);
                exit;
            }
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'RSVP updated.'];
        }
    }

    if ($action === 'self_signup' && $current) {
        $eid  = (int)($_POST['event_id'] ?? 0);
        $urow = $db->prepare('SELECT phone, email FROM users WHERE id=?');
        $urow->execute([$current['id']]);
        $udata = $urow->fetch();
        $signup_pending = false;
        if ($eid > 0) {
            $chk = $db->prepare('SELECT id, approval_status FROM event_invites WHERE event_id=? AND LOWER(username)=LOWER(?)');
            $chk->execute([$eid, $current['username']]);
            $existing_signup = $chk->fetch();
            if (!$existing_signup) {
                // Self-signup: approval gate fires if the event has requires_approval=1.
                $approval = invite_approval_status($eid, 'self');
                $db->prepare('INSERT INTO event_invites (event_id, username, phone, email, rsvp, approval_status) VALUES (?, ?, ?, ?, NULL, ?)')
                   ->execute([$eid, strtolower($current['username']), $udata['phone'] ?? null, $udata['email'] ?? null, $approval]);
                db_log_activity($current['id'], "signed up for event id: $eid" . ($approval === 'pending' ? ' (pending approval)' : ''));
                if ($approval === 'pending') {
                    $signup_pending = true;
                    notify_creator_of_pending($eid, $current['username']);
                }
            } elseif (($existing_signup['approval_status'] ?? 'approved') === 'pending') {
                $signup_pending = true;
            }
        }
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
            $inv = ['username' => strtolower($current['username']), 'rsvp' => null, 'approval_status' => $signup_pending ? 'pending' : 'approved'];
            if ($isAdmin) { $inv['phone'] = $udata['phone'] ?? null; $inv['email'] = $udata['email'] ?? null; }
            header('Content-Type: application/json');
            echo json_encode(['ok' => true, 'invite' => $inv, 'pending' => $signup_pending]);
            exit;
        }
        $_SESSION['flash'] = ['type' => 'success', 'msg' => $signup_pending
            ? 'Request sent — waiting for host approval.'
            : 'You have been added to the event.'];
    }

    if ($action === 'remove_self' && $current) {
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

    if ($action === 'regenerate_walkin_token' && $isAdmin) {
        $eid = (int)($_POST['event_id'] ?? 0);
        if ($eid > 0) {
            $new_token = bin2hex(random_bytes(32));
            $db->prepare('UPDATE events SET walkin_token = ? WHERE id = ?')->execute([$new_token, $eid]);
            db_log_activity($current['id'], "regenerated walkin_token for event id: $eid");
            header('Content-Type: application/json');
            echo json_encode(['ok' => true, 'walkin_token' => $new_token,
                'url' => get_site_url() . '/walkin.php?event_id=' . $eid . '&token=' . $new_token]);
            exit;
        }
        header('Content-Type: application/json');
        echo json_encode(['ok' => false]);
        exit;
    }

    $back = $_POST['month_param'] ?? '';
    header('Location: /calendar.php' . ($back ? '?m=' . urlencode($back) : ''));
    exit;
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
$_vis = event_visibility_sql('events', (int)$current['id']);
$evQuery = $db->prepare(
    "SELECT * FROM events WHERE
       ((recurrence = 'none' AND start_date <= ? AND (end_date >= ? OR (end_date IS NULL AND start_date >= ?)))
        OR
        (recurrence != 'none' AND start_date <= ? AND (recurrence_end IS NULL OR recurrence_end >= ?)))
       AND {$_vis['sql']}
     ORDER BY start_date, start_time"
);
$evQuery->execute(array_merge([$monthEnd, $monthStart, $monthStart, $monthEnd, $monthStart], $_vis['params']));
$allEvents = $evQuery->fetchAll();

// ── Helper: expand events (including recurring) into a date-keyed array ───────
// $exceptions: [event_id => ['YYYY-MM-DD', ...]] — occurrence start dates to skip
// build_event_by_date() and load_exceptions() are defined in db.php

/**
 * Get the effective invite list for a specific occurrence.
 * Base rows (occurrence_date IS NULL, valid_from <= occ_date) are merged with
 * occurrence-specific override rows (which take precedence).
 * Returns array of rows each with: username, rsvp (and optionally email, phone, preferred_contact).
 */
function get_occurrence_invitees(PDO $db, int $event_id, string $occurrence_date, bool $with_contact = true): array {
    $cols = $with_contact ? 'ei.username, ei.rsvp, u.email, u.phone, u.preferred_contact' : 'ei.username, ei.rsvp';
    $join = $with_contact ? 'JOIN users u ON LOWER(u.username) = LOWER(ei.username)' : '';

    $base = $db->prepare("SELECT $cols FROM event_invites ei $join
                          WHERE ei.event_id = ? AND ei.occurrence_date IS NULL
                          AND (ei.valid_from IS NULL OR ei.valid_from <= ?)");
    $base->execute([$event_id, $occurrence_date]);
    $invitees = [];
    foreach ($base->fetchAll() as $row) {
        $invitees[strtolower($row['username'])] = $row;
    }
    $occ = $db->prepare("SELECT $cols FROM event_invites ei $join
                         WHERE ei.event_id = ? AND ei.occurrence_date = ?");
    $occ->execute([$event_id, $occurrence_date]);
    foreach ($occ->fetchAll() as $row) {
        $invitees[strtolower($row['username'])] = $row; // override
    }
    return array_values($invitees);
}

/**
 * Find the next occurrence date of a recurring event on or after $from_date.
 */
function get_next_occurrence(array $ev, array $skip, string $from_date): ?string {
    if (($ev['recurrence'] ?? 'none') === 'none') {
        return $ev['start_date'] >= $from_date ? $ev['start_date'] : null;
    }
    $tz     = new DateTimeZone(get_setting('timezone', 'UTC'));
    $cur    = new DateTime($ev['start_date'], $tz);
    $recEnd = $ev['recurrence_end'] ?? null;
    $limit  = 500;
    while ($limit-- > 0) {
        if ($recEnd && $cur->format('Y-m-d') > $recEnd) break;
        $occDate = $cur->format('Y-m-d');
        if (!empty($ev['cancelled_from']) && $occDate >= $ev['cancelled_from']) break;
        if ($occDate >= $from_date && !in_array($occDate, $skip, true)) return $occDate;
        switch ($ev['recurrence']) {
            case 'daily':   $cur->modify('+1 day');   break;
            case 'weekly':  $cur->modify('+1 week');  break;
            case 'monthly': $cur->modify('+1 month'); break;
            case 'yearly':  $cur->modify('+1 year');  break;
            default: break 2;
        }
    }
    return null;
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

    $_visW = event_visibility_sql('events', (int)$current['id']);
    $wkEvQ = $db->prepare(
        "SELECT * FROM events WHERE
           ((recurrence = 'none' AND start_date <= ? AND (end_date >= ? OR (end_date IS NULL AND start_date >= ?)))
            OR
            (recurrence != 'none' AND start_date <= ? AND (recurrence_end IS NULL OR recurrence_end >= ?)))
           AND {$_visW['sql']}
         ORDER BY start_date, start_time"
    );
    $wkEvQ->execute(array_merge([$wkEndStr, $wkStartStr, $wkStartStr, $wkEndStr, $wkStartStr], $_visW['params']));
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

// Batch-load BASE invites for all events on this page (occurrence_date IS NULL)
$ev_invites = [];
if (!empty($allPageEids)) {
    $iph = implode(',', array_fill(0, count($allPageEids), '?'));
    $is  = $db->prepare("SELECT event_id, username, phone, email, rsvp FROM event_invites WHERE event_id IN ($iph) AND occurrence_date IS NULL ORDER BY username");
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

// Batch-load occurrence-specific RSVPs for the current user
// Structure: [event_id][occurrence_date] = rsvp_value
$ev_my_occ_rsvps = [];
if ($current && !empty($allPageEids)) {
    $iph2 = implode(',', array_fill(0, count($allPageEids), '?'));
    $ors  = $db->prepare("SELECT event_id, occurrence_date, rsvp FROM event_invites WHERE event_id IN ($iph2) AND LOWER(username)=LOWER(?) AND occurrence_date IS NOT NULL");
    $ors->execute(array_merge($allPageEids, [$current['username']]));
    foreach ($ors->fetchAll() as $r) {
        $ev_my_occ_rsvps[$r['event_id']][$r['occurrence_date']] = $r['rsvp'];
    }
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
        #editModal .modal { max-width: 580px; max-height: 90vh; display:flex; flex-direction:column; padding:0; overflow:hidden; }
        #editModal .modal-header { padding:.85rem 1.25rem; flex-shrink:0; border-bottom:1px solid #f1f5f9; margin-bottom:0; }
        #eScrollBody { overflow-y:auto; flex:1; padding:.65rem 1.25rem; }
        #eScrollBody .form-group { margin-bottom:.45rem; }
        #eScrollBody label { font-size:.8rem; margin-bottom:.2rem; }
        #eScrollBody input, #eScrollBody textarea, #eScrollBody select { padding:.32rem .6rem; font-size:.85rem; }
        #eScrollBody textarea { rows:2; }
        #eUserCheckList { display:flex; flex-direction:column; }
        #eUserCheckList label { display:flex; align-items:center; gap:.5rem; padding:.22rem 0; font-size:.85rem; cursor:pointer; line-height:1.2; }
        #eUserCheckList input[type="checkbox"] { flex-shrink:0; margin:0; width:14px; height:14px; cursor:pointer; }
        #eFooter { padding:.65rem 1.25rem; flex-shrink:0; border-top:1px solid #f1f5f9; }
        .invite-row { display:flex; gap:.35rem; align-items:center; }
        .invite-row input { padding:.38rem .6rem; border:1.5px solid #e2e8f0; border-radius:6px; font-size:.85rem; min-width:0; }
        .invite-row input:nth-child(1) { flex:2; }
        .invite-row input:nth-child(2) { flex:1.5; }
        .invite-row input:nth-child(3) { flex:2; }
        .invite-row select { flex-shrink:0; }
        .invite-row .inv-remove { flex-shrink:0; padding:.3rem .5rem; border:1px solid #e2e8f0; border-radius:6px; background:#fff; cursor:pointer; color:#94a3b8; font-size:.9rem; line-height:1; }
        .invite-row .inv-remove:hover { background:#fee2e2; color:#dc2626; border-color:#fca5a5; }
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
    <?php if ($isAdmin): ?><script src="/vendor/qrcode.min.js"></script><?php endif; ?>
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
        <div id="vInvites" style="display:none;margin:.25rem 0 0;padding:.6rem 0;border-top:1px solid #f1f5f9"></div>
        <?php if ($current): ?>
        <div id="vRsvpWrap" style="display:none;padding:.6rem 0;border-top:1px solid #f1f5f9">
            <form method="post" action="/calendar.php" id="vRsvpForm">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">
                <input type="hidden" name="action" value="update_rsvp">
                <input type="hidden" name="event_id" id="vRsvpEventId" value="">
                <input type="hidden" name="occurrence_date" id="vRsvpOccDate" value="">
                <input type="hidden" name="month_param" value="<?= htmlspecialchars($monthParam) ?>">
                <div style="font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#94a3b8;margin-bottom:.4rem">My RSVP</div>
                <div id="vRsvpLocked" style="display:none;font-size:.8rem;color:#dc2626;font-style:italic;margin-bottom:.35rem">
                    RSVP is locked — this event starts in less than an hour.
                </div>
                <div style="display:flex;gap:.5rem;align-items:center">
                    <select name="rsvp" id="vRsvpSelect"
                            style="padding:.38rem .6rem;border:1.5px solid #e2e8f0;border-radius:7px;font-size:.875rem;background:#fff">
                        <option value="">--</option>
                        <option value="yes">Yes</option>
                        <option value="no">No</option>
                        <option value="maybe">Maybe</option>
                    </select>
                    <button id="vRemoveSelfBtn" type="button"
                            style="padding:.38rem .75rem;font-size:.8rem;background:#dc2626;color:#fff;border:none;border-radius:7px;cursor:pointer;white-space:nowrap">
                        Remove Me
                    </button>
                </div>
            </form>
        </div>
        <?php endif; ?>
        <?php if ($current): ?>
        <div id="vSignupWrap" style="display:none;padding:.5rem 0;border-top:1px solid #f1f5f9">
            <button id="vSignupBtn" class="btn btn-primary" style="width:100%;font-size:.875rem">Sign up to attend</button>
        </div>
        <?php elseif (get_setting('allow_registration', '1') === '1'): ?>
        <div style="padding:.5rem 0;border-top:1px solid #f1f5f9;display:flex;gap:.5rem;justify-content:center">
            <a id="vGuestLogin" href="/login.php" class="btn btn-outline" style="font-size:.875rem;text-decoration:none">Login</a>
            <a id="vGuestSignup" href="/register.php" class="btn btn-primary" style="font-size:.875rem;text-decoration:none">Sign Up to Attend</a>
        </div>
        <?php endif; ?>
        <?php if ($isAdmin): ?>
        <div class="ev-view-actions">
            <button class="btn btn-primary" onclick="editFromView()">Edit</button>
            <button class="btn btn-outline" title="Walk-up QR code" onclick="openWalkinQR()" style="font-size:1rem;padding:.38rem .65rem">&#x1F4F1; QR</button>
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
            <p class="comment-login"><a id="vCommentLogin" href="/login.php">Log in</a> to leave a comment.</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($isAdmin): ?>
<!-- ── Walk-up QR Modal ── -->
<div class="modal-overlay" id="walkinModal" onclick="if(event.target===this)closeWalkinQR()">
    <div class="modal" style="max-width:380px;text-align:center">
        <div class="modal-header" style="justify-content:space-between">
            <h2 style="font-size:1rem;font-weight:700">Walk-up Registration</h2>
            <button class="modal-close" onclick="closeWalkinQR()">&#x2715;</button>
        </div>
        <div id="walkinQRCode" style="display:flex;justify-content:center;margin:.5rem 0 1rem"></div>
        <div id="walkinQRUrl" style="font-size:.72rem;color:#64748b;word-break:break-all;margin-bottom:.75rem;padding:0 .5rem"></div>
        <button class="btn btn-outline" onclick="copyWalkinLink()" style="width:100%;margin-bottom:.5rem" id="walkinCopyBtn">Copy link</button>
        <button class="btn" onclick="closeWalkinQR()" style="width:100%;background:#f1f5f9;color:#475569">Close</button>
    </div>
</div>

<!-- ── Add / Edit Event Modal ── -->
<div class="modal-overlay" id="editModal" onclick="if(event.target===this)closeEdit()">
    <div class="modal">
        <div class="modal-header">
            <h2 id="editModalTitle">Add Event</h2>
            <button class="modal-close" onclick="closeEdit()">&#x2715;</button>
        </div>
        <form method="post" action="/calendar.php" style="display:flex;flex-direction:column;flex:1;min-height:0">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">
            <input type="hidden" name="action" id="eAction" value="add">
            <input type="hidden" name="id" id="eId" value="">
            <input type="hidden" name="month_param" value="<?= htmlspecialchars($monthParam) ?>">

            <div id="eScrollBody">
                <div class="form-group">
                    <label>Title</label>
                    <input type="text" name="title" id="eTitle" required autocomplete="off" style="width:100%">
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem">
                    <div class="form-group">
                        <label>Start Date</label>
                        <input type="date" name="start_date" id="eStartDate" required style="width:100%">
                    </div>
                    <div class="form-group">
                        <label>End Date <span style="color:#94a3b8;font-weight:400">(opt)</span></label>
                        <input type="date" name="end_date" id="eEndDate" style="width:100%">
                    </div>
                    <div class="form-group">
                        <label>Start Time <span style="color:#94a3b8;font-weight:400">(opt)</span></label>
                        <input type="time" name="start_time" id="eStartTime" style="width:100%">
                    </div>
                    <div class="form-group">
                        <label>End Time <span style="color:#94a3b8;font-weight:400">(opt)</span></label>
                        <input type="time" name="end_time" id="eEndTime" style="width:100%">
                    </div>
                </div>
                <div class="form-group">
                    <label>Description <span style="color:#94a3b8;font-weight:400">(optional)</span></label>
                    <textarea name="description" id="eDesc" rows="2" style="width:100%;resize:vertical"></textarea>
                </div>
                <!-- Recurrence + Color side by side -->
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem;align-items:start">
                    <div class="form-group" style="margin-bottom:0">
                        <label>Recurrence</label>
                        <select name="recurrence" id="eRecurrence" class="form-select"
                                onchange="toggleRecEnd(this.value)" style="width:100%">
                            <option value="none">Does not repeat</option>
                            <option value="daily">Daily</option>
                            <option value="weekly">Weekly</option>
                            <option value="monthly">Monthly</option>
                            <option value="yearly">Yearly</option>
                        </select>
                    </div>
                    <div class="form-group" style="margin-bottom:0">
                        <label>Color</label>
                        <div class="color-swatches" id="colorSwatches" style="margin-top:.25rem">
                            <?php foreach (['#2563eb','#16a34a','#dc2626','#d97706','#7c3aed','#0891b2','#db2777'] as $c): ?>
                                <div class="color-swatch" style="background:<?= $c ?>" data-color="<?= $c ?>"
                                     onclick="selectColor('<?= $c ?>')"></div>
                            <?php endforeach; ?>
                        </div>
                        <input type="hidden" name="color" id="eColor" value="#2563eb">
                    </div>
                </div>
                <div class="form-group" id="recEndGroup" style="display:none;margin-top:.45rem">
                    <label>Repeat until <span style="color:#94a3b8;font-weight:400">(optional)</span></label>
                    <input type="date" name="recurrence_end" id="eRecEnd" style="width:100%">
                </div>

                <!-- Invites -->
                <div class="form-group" style="margin-top:.55rem">
                    <label>Invites</label>
                    <!-- Checkbox user picker -->
                    <div id="eUserCheckList"
                         style="max-height:100px;overflow-y:auto;border:1.5px solid #e2e8f0;border-radius:7px;padding:.2rem .6rem;margin-bottom:.3rem">
                        <?php foreach ($allUsers as $u): ?>
                        <label>
                            <input type="checkbox" class="eUserChk"
                                   value="<?= htmlspecialchars($u['username']) ?>"
                                   data-email="<?= htmlspecialchars($u['email'] ?? '') ?>"
                                   data-phone="<?= htmlspecialchars($u['phone'] ?? '') ?>">
                            <?= htmlspecialchars($u['username']) ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <div style="display:flex;gap:.4rem;margin-bottom:.4rem">
                        <button type="button" class="btn btn-outline" style="font-size:.8rem;padding:.28rem .65rem;white-space:nowrap" onclick="addCheckedInvites()">+ Add Selected</button>
                        <button type="button" class="btn btn-outline" style="font-size:.8rem;padding:.28rem .65rem;white-space:nowrap" onclick="addBlankInviteRow()">+ Custom</button>
                    </div>
                    <!-- Invited list (scrollable) -->
                    <div style="display:grid;grid-template-columns:2fr 1.5fr 2fr auto;gap:.25rem;margin-bottom:.2rem;padding:0 .1rem" id="eInviteHeader">
                        <span style="font-size:.7rem;color:#94a3b8;font-weight:600;text-transform:uppercase;letter-spacing:.04em">Username *</span>
                        <span style="font-size:.7rem;color:#94a3b8;font-weight:600;text-transform:uppercase;letter-spacing:.04em">Phone</span>
                        <span style="font-size:.7rem;color:#94a3b8;font-weight:600;text-transform:uppercase;letter-spacing:.04em">Email</span>
                        <span></span>
                    </div>
                    <div id="eInviteList" style="display:flex;flex-direction:column;gap:.25rem;max-height:120px;overflow-y:auto"></div>
                </div>
            </div><!-- /eScrollBody -->

            <div id="eFooter">
                <div style="display:flex;gap:.5rem">
                    <button type="submit" class="btn btn-primary" style="flex:1" id="eSubmitBtn">Add Event</button>
                    <button type="button" class="btn btn-outline" onclick="closeEdit()">Cancel</button>
                </div>
            </div>
        </form>
        <!-- Delete entire event (edit mode only) -->
        <form method="post" action="/calendar.php" id="eDeleteForm"
              style="display:none;padding:0 1.25rem 0;flex-shrink:0"
              onsubmit="return confirm(currentEvent && currentEvent.recurrence !== 'none' ? 'Delete the entire repeating series? This cannot be undone.' : 'Delete this event?')">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" id="eDeleteId">
            <input type="hidden" name="month_param" value="<?= htmlspecialchars($monthParam) ?>">
            <button type="submit" class="btn" style="width:100%;background:#dc2626;color:#fff">Delete Event</button>
        </form>
        <!-- Regenerate walk-up link (edit mode only, shown when event has an id) -->
        <div id="eRegenWalkinWrap" style="display:none;padding:.3rem 1.25rem .1rem;flex-shrink:0">
            <button type="button" id="eRegenWalkinBtn"
                    style="width:100%;padding:.38rem;border:1.5px solid #cbd5e1;border-radius:7px;background:#fff;color:#64748b;font-size:.78rem;cursor:pointer;font-weight:600"
                    onclick="regenWalkinFromEdit()">
                Regenerate walk-up link
            </button>
        </div>
        <!-- Cancel/Uncancel series (recurring events only, edit mode) -->
        <div id="eCancelSeriesWrap" style="display:none;padding:.45rem 1.25rem .65rem;flex-shrink:0;border-top:1px solid #f1f5f9;margin-top:.1rem">
            <button id="eCancelSeriesBtn" type="button"
                    style="width:100%;padding:.4rem;border:1.5px solid #f87171;border-radius:7px;background:#fff;color:#dc2626;font-size:.82rem;cursor:pointer;font-weight:600"
                    onclick="cancelSeriesClick()">
                Cancel future occurrences
            </button>
            <button id="eUncancelSeriesBtn" type="button"
                    style="display:none;width:100%;padding:.4rem;border:1.5px solid #86efac;border-radius:7px;background:#fff;color:#16a34a;font-size:.82rem;cursor:pointer;font-weight:600"
                    onclick="uncancelSeriesClick()">
                Uncancel series (resume)
            </button>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/_footer.php'; ?>

<script>
let currentEvent = null;
const eventComments     = <?= json_encode($ev_comments) ?>;
const eventInvites      = <?= json_encode($ev_invites) ?>;
const EV_MY_OCC_RSVPS  = <?= json_encode($ev_my_occ_rsvps) ?>;
const CURRENT_USERNAME  = <?= json_encode($current['username'] ?? '') ?>;
const CAL_REDIR         = '/calendar.php?m=<?= htmlspecialchars($monthParam) ?>';
const CAL_CSRF          = <?= json_encode($token) ?>;
const CAL_CURRENT_ID    = <?= json_encode((int)($current['id'] ?? 0)) ?>;
const IS_ADMIN          = <?= $isAdmin ? 'true' : 'false' ?>;
const SERVER_NOW_TS     = <?= time() ?>;

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
            const occDate = ev.occurrence_start || ev.start_date;
            document.getElementById('vRsvpEventId').value = ev.id;
            document.getElementById('vRsvpOccDate').value = (ev.recurrence && ev.recurrence !== 'none') ? occDate : '';

            // Determine effective RSVP: occurrence-specific takes precedence over base
            const occRsvps = EV_MY_OCC_RSVPS[ev.id] || {};
            const occRsvp  = occRsvps[occDate];
            const baseRsvp = myInvite.rsvp || '';
            const effectiveRsvp = (occRsvp !== undefined) ? (occRsvp || '') : baseRsvp;
            document.getElementById('vRsvpSelect').value = effectiveRsvp;

            // ── Feature 6: RSVP cutoff — lock RSVP within 1 hour of start ──
            const lockedEl = document.getElementById('vRsvpLocked');
            const rsvpSel  = document.getElementById('vRsvpSelect');
            let rsvpLocked = false;
            if (!IS_ADMIN && ev.start_time) {
                const startTs = (new Date(occDate + 'T' + ev.start_time).getTime() / 1000);
                rsvpLocked = (startTs - SERVER_NOW_TS) < 3600;
            }
            if (lockedEl) lockedEl.style.display = rsvpLocked ? '' : 'none';
            if (rsvpSel)  rsvpSel.disabled = rsvpLocked;

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
    <?php if ($isAdmin): ?>
    // Show/configure occurrence-delete only for recurring events
    const isRecurring = ev.recurrence && ev.recurrence !== 'none';
    const occForm = document.getElementById('vDeleteOccForm');
    if (occForm) {
        occForm.style.display = isRecurring ? '' : 'none';
        document.getElementById('vDeleteOccId').value   = ev.id;
        document.getElementById('vDeleteOccDate').value = ev.occurrence_start || ev.start_date;
    }
    <?php endif; ?>

    // Populate comments
    <?php if ($current): ?>
    document.getElementById('vCommentEventId').value  = ev.id;
    document.getElementById('vCommentRedirect').value = CAL_REDIR;
    <?php endif; ?>
    renderCommentsPanel(ev.id);

    <?php if ($isAdmin): ?>
    startRsvpPoll(ev.id);
    <?php endif; ?>

    // Point login/signup links back to this event so user returns here after auth
    const evUrl = '/calendar.php?m=' + ev.start_date.substring(0, 7) + '&open=' + ev.id + '&date=' + ev.start_date;
    const gl = document.getElementById('vGuestLogin');
    const gs = document.getElementById('vGuestSignup');
    const cl = document.getElementById('vCommentLogin');
    if (gl) gl.href = '/login.php?redirect=' + encodeURIComponent(evUrl);
    if (gs) gs.href = '/register.php?redirect=' + encodeURIComponent(evUrl);
    if (cl) cl.href = '/login.php?redirect=' + encodeURIComponent(evUrl);

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
        const open = vInvDiv.dataset.open !== 'false';
        let ih = '<button type="button" onclick="toggleInvites()" style="background:none;border:none;padding:0;cursor:pointer;display:flex;align-items:center;gap:.35rem;width:100%">'
               + '<span style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#94a3b8">Invites (' + invites.length + ')</span>'
               + '<span id="vInvToggle" style="font-size:.65rem;color:#94a3b8">' + (open ? '&#9650;' : '&#9660;') + '</span>'
               + '</button>';
        ih += '<div id="vInvBody" style="display:' + (open ? 'flex' : 'none') + ';flex-direction:column;gap:.2rem;margin-top:.3rem">';
        invites.forEach(inv => {
            const badge = inv.rsvp && rsvpClass[inv.rsvp]
                ? '<span class="' + rsvpClass[inv.rsvp] + '" style="display:inline-block;width:3rem;text-align:center">' + rsvpText[inv.rsvp] + '</span>'
                : '<span style="display:inline-block;width:3rem"></span>';
            ih += '<div style="font-size:.875rem;color:#334155;display:flex;align-items:center;gap:.4rem;flex-wrap:wrap">' + badge + ' ' + escHtml(inv.username);
            if (inv.phone) ih += ' <span style="color:#64748b">&middot; ' + escHtml(inv.phone) + '</span>';
            if (inv.email) ih += ' <span style="color:#64748b">&middot; ' + escHtml(inv.email) + '</span>';
            ih += '</div>';
        });
        ih += '</div></div>';
        vInvDiv.innerHTML = ih;
        vInvDiv.style.display = '';
    } else {
        vInvDiv.innerHTML = '';
        vInvDiv.style.display = 'none';
    }
}
function toggleInvites() {
    const vInvDiv = document.getElementById('vInvites');
    const body    = document.getElementById('vInvBody');
    const arrow   = document.getElementById('vInvToggle');
    const open    = body.style.display !== 'none';
    body.style.display      = open ? 'none' : 'flex';
    arrow.innerHTML         = open ? '&#9660;' : '&#9650;';
    vInvDiv.dataset.open    = open ? 'false' : 'true';
}
function closeView() {
    document.getElementById('viewModal').classList.remove('open');
    stopRsvpPoll();
}

<?php if ($isAdmin): ?>
// ── Live RSVP polling for admins ─────────────────────────────────────────────
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
            const oldJson = JSON.stringify(eventInvites[eid] || []);
            const newJson = JSON.stringify(data.base);
            if (oldJson !== newJson) {
                eventInvites[eid] = data.base;
                if (currentEvent && currentEvent.id == eid) renderInvitesPanel(eid);
            }
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
<?php endif; ?>

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

const vRsvpSelect = document.getElementById('vRsvpSelect');
if (vRsvpSelect) {
    vRsvpSelect.addEventListener('change', function() {
        if (this.disabled) return;
        const form = document.getElementById('vRsvpForm');
        const data = new FormData(form);
        const prevVal = currentEvent ? ((EV_MY_OCC_RSVPS[currentEvent.id] || {})[document.getElementById('vRsvpOccDate').value || ''] || (eventInvites[currentEvent.id] || []).find(i => i.username.toLowerCase() === CURRENT_USERNAME.toLowerCase())?.rsvp || '') : '';
        fetch('/calendar.php', {
            method: 'POST',
            body: data,
            headers: {'X-Requested-With': 'XMLHttpRequest'}
        })
        .then(r => r.json())
        .then(res => {
            if (!res.ok) {
                if (res.locked) {
                    const lockedEl = document.getElementById('vRsvpLocked');
                    if (lockedEl) lockedEl.style.display = '';
                    this.disabled = true;
                    this.value = prevVal;
                } else {
                    showSavedBar(res.msg || 'Error');
                }
                return;
            }
            const eid     = parseInt(document.getElementById('vRsvpEventId').value);
            const occDate = document.getElementById('vRsvpOccDate').value;
            const rsvp    = vRsvpSelect.value;
            if (occDate) {
                if (!EV_MY_OCC_RSVPS[eid]) EV_MY_OCC_RSVPS[eid] = {};
                EV_MY_OCC_RSVPS[eid][occDate] = rsvp || null;
            } else {
                const list = eventInvites[eid];
                if (list) {
                    const inv = list.find(i => i.username.toLowerCase() === CURRENT_USERNAME.toLowerCase());
                    if (inv) inv.rsvp = rsvp || null;
                }
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
                vRsvpW.style.display = '';
            }
            showSavedBar('Signed up!');
        })
        .catch(() => {});
    });
}

const vRemoveSelfBtn = document.getElementById('vRemoveSelfBtn');
if (vRemoveSelfBtn) {
    vRemoveSelfBtn.addEventListener('click', function() {
        if (!confirm('Remove yourself from this event?')) return;
        const eid = parseInt(document.getElementById('vRsvpEventId').value);
        const data = new FormData();
        data.append('csrf_token', CAL_CSRF);
        data.append('action', 'remove_self');
        data.append('event_id', eid);
        fetch('/calendar.php', {
            method: 'POST',
            body: data,
            headers: {'X-Requested-With': 'XMLHttpRequest'}
        })
        .then(r => r.json())
        .then(res => {
            if (!res.ok) return;
            // Remove from in-memory cache
            if (eventInvites[eid]) {
                eventInvites[eid] = eventInvites[eid].filter(
                    inv => inv.username.toLowerCase() !== CURRENT_USERNAME.toLowerCase()
                );
            }
            renderInvitesPanel(eid);
            // Swap back to signup button
            document.getElementById('vRsvpWrap').style.display = 'none';
            document.getElementById('vSignupWrap').style.display = '';
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
    document.querySelectorAll('.eUserChk').forEach(c => c.checked = false);
    document.getElementById('eDeleteForm').style.display = 'none';
    document.getElementById('eRegenWalkinWrap').style.display = 'none';
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
    document.querySelectorAll('.eUserChk').forEach(c => c.checked = false);
    const isRec = ev.recurrence && ev.recurrence !== 'none';
    (eventInvites[ev.id] || []).forEach(inv => addInviteRow(inv.username, inv.phone || '', inv.email || '', inv.rsvp || '', isRec, ev.id));
    document.getElementById('eDeleteId').value       = ev.id;
    document.getElementById('eDeleteForm').style.display = '';
    document.getElementById('eRegenWalkinWrap').style.display = '';
    // Cancel/Uncancel series controls
    const cancelWrap = document.getElementById('eCancelSeriesWrap');
    const cancelBtn  = document.getElementById('eCancelSeriesBtn');
    const uncancelBtn= document.getElementById('eUncancelSeriesBtn');
    if (cancelWrap) {
        cancelWrap.style.display = isRec ? '' : 'none';
        const isCancelled = !!(ev.cancelled_from);
        cancelBtn.style.display   = isCancelled ? 'none' : '';
        uncancelBtn.style.display = isCancelled ? '' : 'none';
    }
    document.getElementById('editModal').classList.add('open');
    document.getElementById('eTitle').focus();
}
function editFromView() { openEditModal(currentEvent); }
function closeEdit() { document.getElementById('editModal').classList.remove('open'); }

function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}
const RSVP_LABELS = {'':'', yes:'Yes', no:'No', maybe:'Maybe'};
function addInviteRow(username, phone, email, rsvp, showRemoveAll, eid) {
    const list = document.getElementById('eInviteList');
    const row  = document.createElement('div');
    row.className = 'invite-row';
    row.dataset.username = username.toLowerCase();
    const rsvpVal = RSVP_LABELS.hasOwnProperty(rsvp) ? rsvp : '';
    const removeAllLink = (showRemoveAll && username)
        ? '<button type="button" style="flex-shrink:0;padding:.28rem .45rem;font-size:.72rem;border:1px solid #fca5a5;border-radius:5px;background:#fff;color:#dc2626;cursor:pointer;white-space:nowrap" title="Remove from all future occurrences" onclick="removeInviteeFromAll(\'' + escHtml(username.toLowerCase()) + '\',' + (eid||0) + ',this.closest(\'.invite-row\'))">&#x2715; All</button>'
        : '';
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
        removeAllLink +
        '<button type="button" class="inv-remove" onclick="this.closest(\'.invite-row\').remove()">&#x2715;</button>';
    list.appendChild(row);
}
function addBlankInviteRow() { addInviteRow('', '', '', ''); }
function addCheckedInvites() {
    const isRec = currentEvent && currentEvent.recurrence && currentEvent.recurrence !== 'none';
    const eid   = currentEvent ? currentEvent.id : 0;
    const existing = Array.from(document.querySelectorAll('#eInviteList [name="invite_username[]"]'))
                          .map(i => i.value.trim().toLowerCase());
    document.querySelectorAll('.eUserChk:checked').forEach(chk => {
        if (!existing.includes(chk.value.toLowerCase())) {
            addInviteRow(chk.value, chk.dataset.phone || '', chk.dataset.email || '', '', isRec, eid);
            existing.push(chk.value.toLowerCase());
        }
        chk.checked = false;
    });
}

// ── Feature 5: Remove invitee from all future occurrences ──
function removeInviteeFromAll(username, eid, rowEl) {
    if (!confirm('Remove ' + username + ' from all future occurrences of this event?\nA notification will be sent.')) return;
    const data = new FormData();
    data.append('csrf_token', CAL_CSRF);
    data.append('action', 'remove_invitee');
    data.append('id', eid);
    data.append('target_username', username);
    fetch('/calendar.php', { method: 'POST', body: data, headers: {'X-Requested-With': 'XMLHttpRequest'} })
    .then(r => r.json())
    .then(res => {
        if (!res.ok) { alert('Error removing invitee.'); return; }
        if (rowEl) rowEl.remove();
        // Remove from in-memory invites
        if (eventInvites[eid]) {
            eventInvites[eid] = eventInvites[eid].filter(i => i.username.toLowerCase() !== username);
        }
    })
    .catch(() => alert('Error removing invitee.'));
}

// ── Feature 3: Cancel / uncancel series ──
function cancelSeriesClick() {
    if (!currentEvent) return;
    const today = new Date().toISOString().slice(0, 10);
    const d = prompt('Cancel all occurrences from date (YYYY-MM-DD):', today);
    if (!d || !/^\d{4}-\d{2}-\d{2}$/.test(d)) return;
    if (!confirm('This will cancel all occurrences from ' + d + ' and notify all invitees. Continue?')) return;
    const data = new FormData();
    data.append('csrf_token', CAL_CSRF);
    data.append('action', 'cancel_series');
    data.append('id', currentEvent.id);
    data.append('cancel_from', d);
    fetch('/calendar.php', { method: 'POST', body: data, headers: {'X-Requested-With': 'XMLHttpRequest'} })
    .then(r => r.json())
    .then(res => {
        if (!res.ok) { alert('Error cancelling series.'); return; }
        currentEvent.cancelled_from = res.cancelled_from;
        document.getElementById('eCancelSeriesBtn').style.display = 'none';
        document.getElementById('eUncancelSeriesBtn').style.display = '';
        alert('Series cancelled from ' + res.cancelled_from + '. Notifications sent.');
    })
    .catch(() => alert('Error cancelling series.'));
}

function uncancelSeriesClick() {
    if (!currentEvent) return;
    if (!confirm('Resume this event series? Future occurrences will reappear on the calendar.')) return;
    const data = new FormData();
    data.append('csrf_token', CAL_CSRF);
    data.append('action', 'uncancel_series');
    data.append('id', currentEvent.id);
    fetch('/calendar.php', { method: 'POST', body: data, headers: {'X-Requested-With': 'XMLHttpRequest'} })
    .then(r => r.json())
    .then(res => {
        if (!res.ok) { alert('Error uncancelling series.'); return; }
        currentEvent.cancelled_from = null;
        document.getElementById('eCancelSeriesBtn').style.display = '';
        document.getElementById('eUncancelSeriesBtn').style.display = 'none';
        alert('Series resumed.');
    })
    .catch(() => alert('Error uncancelling series.'));
}

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
document.addEventListener('DOMContentLoaded', () =>
    viewEvent(<?= json_encode($autoOpenEvent) ?>));
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

<?php if ($isAdmin): ?>
// ── Walk-up QR modal ──────────────────────────────────────────────────────────
function buildQRCanvas(url, size) {
    if (typeof qrcode === 'undefined') return null;
    var qr = qrcode(0, 'M');
    qr.addData(url);
    qr.make();
    var modules = qr.getModuleCount();
    var canvas = document.createElement('canvas');
    canvas.width = size;
    canvas.height = size;
    var ctx = canvas.getContext('2d');
    var cell = size / modules;
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, size, size);
    ctx.fillStyle = '#000000';
    for (var r = 0; r < modules; r++) {
        for (var c = 0; c < modules; c++) {
            if (qr.isDark(r, c)) ctx.fillRect(c * cell, r * cell, cell + 0.5, cell + 0.5);
        }
    }
    return canvas;
}

function openWalkinQR() {
    var ev = currentEvent;
    if (!ev) return;
    // If event has no walkin_token yet, fetch one from the server first
    if (!ev.walkin_token) {
        regenerateWalkinToken(ev, function(newToken) {
            ev.walkin_token = newToken;
            currentEvent.walkin_token = newToken;
            renderWalkinQR(ev);
        });
        return;
    }
    renderWalkinQR(ev);
}

function renderWalkinQR(ev) {
    var modal  = document.getElementById('walkinModal');
    var qrWrap = document.getElementById('walkinQRCode');
    var urlEl  = document.getElementById('walkinQRUrl');
    qrWrap.innerHTML = '';
    var url = location.origin + '/walkin.php?event_id=' + ev.id + '&token=' + encodeURIComponent(ev.walkin_token);
    var canvas = buildQRCanvas(url, 220);
    if (canvas) qrWrap.appendChild(canvas);
    urlEl.textContent = url;
    modal.classList.add('open');
}

function closeWalkinQR() {
    document.getElementById('walkinModal').classList.remove('open');
}

function copyWalkinLink() {
    var urlEl = document.getElementById('walkinQRUrl');
    var btn   = document.getElementById('walkinCopyBtn');
    navigator.clipboard.writeText(urlEl.textContent).then(function() {
        btn.textContent = 'Copied!';
        setTimeout(function() { btn.textContent = 'Copy link'; }, 2000);
    });
}

function regenWalkinFromEdit() {
    var ev = currentEvent;
    if (!ev) return;
    var btn = document.getElementById('eRegenWalkinBtn');
    btn.textContent = 'Regenerating…';
    btn.disabled = true;
    regenerateWalkinToken(ev, function(newToken) {
        ev.walkin_token = newToken;
        currentEvent.walkin_token = newToken;
        btn.textContent = 'Link regenerated!';
        setTimeout(function() {
            btn.textContent = 'Regenerate walk-up link';
            btn.disabled = false;
        }, 2500);
    });
}

function regenerateWalkinToken(ev, callback) {
    var fd = new FormData();
    fd.append('action', 'regenerate_walkin_token');
    fd.append('csrf_token', CAL_CSRF);
    fd.append('event_id', ev.id);
    fetch('/calendar.php', { method: 'POST', body: fd,
        headers: { 'X-Requested-With': 'XMLHttpRequest' } })
    .then(function(r) { return r.json(); })
    .then(function(j) {
        if (j.ok && j.walkin_token) {
            if (typeof callback === 'function') callback(j.walkin_token);
        }
    });
}
<?php endif; ?>
</script>

</body>
</html>
