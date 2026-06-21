<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_notifications.php';

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
    if (!$isAdmin) {
        $ownerActions = ['edit', 'delete', 'delete_occurrence', 'cancel_series', 'uncancel_series', 'remove_invitee'];
        if (in_array($action, $ownerActions, true)) {
            // Single authoritative check (creator, per-event manager, league owner/manager, or admin).
            $chkId = (int)($_POST['id'] ?? 0);
            if ($chkId <= 0 || !can_manage_event($db, $chkId, (int)$current['id'], $isAdmin)) {
                http_response_code(403); exit('Access denied.');
            }
        } elseif (!in_array($action, ['update_rsvp', 'self_signup', 'remove_self'], true)) {
            http_response_code(403); exit('Access denied.');
        }
    }
    // The add/edit save path that used to live here was dead code: nothing in the
    // app posts action=add/edit to this endpoint (the editor modal always posted to
    // calendar.php, now via the shared _event_save.php; the standalone editor page
    // posts to event_edit.php). It had drifted from the live path (no token
    // preservation on edit) and was removed in v0.1970 rather than consolidated.

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            // Shared notify-then-delete sequence (see _notifications.php).
            if (cancel_event_with_notifications($db, $id, (int)$current['id']) !== null) {
                $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Event deleted.'];
            }
        }
    }

    if ($action === 'delete_occurrence') {
        $id   = (int)($_POST['id'] ?? 0);
        $date = trim($_POST['occurrence_date'] ?? '');
        if ($id > 0 && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $db->prepare('INSERT OR IGNORE INTO event_exceptions (event_id, date) VALUES (?, ?)')
               ->execute([$id, $date]);

            // ── Feature 2: queue cancellation notifications to RSVPed invitees ──
            $occ_inv = get_occurrence_invitees($db, $id, $date, true);
            foreach ($occ_inv as $inv) {
                if (!in_array($inv['rsvp'] ?? '', ['yes', 'maybe'])) continue;
                queue_event_notification($db, $id, $inv['username'], 'cancel_occurrence', $date);
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
            // Queue cancellation notifications (queue_event_notification handles the template)
            $all_inv = $db->prepare("SELECT ei.username FROM event_invites ei
                                     WHERE ei.event_id=? AND ei.occurrence_date IS NULL");
            $all_inv->execute([$id]);
            foreach ($all_inv->fetchAll() as $inv) {
                queue_event_notification($db, $id, $inv['username'], 'cancel_occurrence', $cancel_from);
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
            // Queue removal notification — use event_updated template; user is already removed
            // from the invite list so they just need a heads-up.
            queue_event_notification($db, $id, $target_user, 'cancel_event');
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
                       ->execute([$eid, $current['username'], $base_row2['phone'] ?? null, $base_row2['email'] ?? null, $rsvp, $occ_date, $base_approval2]);
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
        // Authorization: only allow self-signup to events this user is permitted to
        // see/join (public, a league they belong to, created by them, already invited,
        // or admin). Without this, a user could POST any event_id and self-insert an
        // event_invites row, which both joins and reveals private events (IDOR).
        $signup_allowed = false;
        if ($eid > 0) {
            $vis  = event_visibility_sql('e', (int)$current['id']);
            $gate = $db->prepare("SELECT e.id FROM events e WHERE e.id = ? AND {$vis['sql']}");
            $gate->execute(array_merge([$eid], $vis['params']));
            $signup_allowed = (bool)$gate->fetch();
        }
        if (!$signup_allowed) {
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
                http_response_code(403);
                header('Content-Type: application/json');
                echo json_encode(['ok' => false, 'error' => 'You are not allowed to sign up for that event.']);
                exit;
            }
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'You are not allowed to sign up for that event.'];
        } else {
            $chk = $db->prepare('SELECT id, approval_status FROM event_invites WHERE event_id=? AND LOWER(username)=LOWER(?)');
            $chk->execute([$eid, $current['username']]);
            $existing_signup = $chk->fetch();
            if (!$existing_signup) {
                // Self-signup: approval gate fires if the event has requires_approval=1.
                $approval = invite_approval_status($eid, 'self');
                $db->prepare('INSERT INTO event_invites (event_id, username, phone, email, rsvp, approval_status) VALUES (?, ?, ?, ?, NULL, ?)')
                   ->execute([$eid, $current['username'], $udata['phone'] ?? null, $udata['email'] ?? null, $approval]);
                db_log_activity($current['id'], "signed up for event id: $eid" . ($approval === 'pending' ? ' (pending approval)' : ''));
                if ($approval === 'pending') {
                    $signup_pending = true;
                    notify_creator_of_pending($eid, $current['username']);
                }
            } elseif (($existing_signup['approval_status'] ?? 'approved') === 'pending') {
                $signup_pending = true;
            }
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
                $inv = ['username' => $current['username'], 'rsvp' => null, 'approval_status' => $signup_pending ? 'pending' : 'approved'];
                if ($isAdmin) { $inv['phone'] = $udata['phone'] ?? null; $inv['email'] = $udata['email'] ?? null; }
                header('Content-Type: application/json');
                echo json_encode(['ok' => true, 'invite' => $inv, 'pending' => $signup_pending]);
                exit;
            }
            $_SESSION['flash'] = ['type' => 'success', 'msg' => $signup_pending
                ? 'Request sent — waiting for host approval.'
                : 'You have been added to the event.'];
        }
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

// calendar_dl.php is a POST-only action endpoint. The GET calendar view below is
// legacy (superseded by calendar.php) and references the long-removed `recurrence`
// column, so it would fatal. Send any direct GET to the real calendar page.
$gm = (preg_match('/^\d{4}-\d{2}$/', $_GET['m'] ?? '')) ? $_GET['m'] : '';
header('Location: /calendar.php' . ($gm ? '?m=' . urlencode($gm) : ''));
exit;
