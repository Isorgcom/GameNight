<?php
require_once __DIR__ . '/auth.php';

$db      = get_db();
$current = current_user();
$isAdmin = $current && $current['role'] === 'admin';
$allowUserEvents = get_setting('allow_user_events', '0') === '1';
$canCreateEvents = $isAdmin || ($current && $allowUserEvents);
$isAnyEventManager = false;
if ($current && !$isAdmin) {
    $mgrCheck = $db->prepare("SELECT 1 FROM event_invites WHERE LOWER(username)=LOWER(?) AND event_role='manager' LIMIT 1");
    $mgrCheck->execute([$current['username']]);
    $isAnyEventManager = (bool)$mgrCheck->fetch();
}
$canEditEvents = $canCreateEvents || $isAnyEventManager;
// For non-admins we no longer preload every site user into the event editor —
// the picker fetches a scoped list from /calendar_contacts_dl.php when the modal opens
// or the league dropdown changes. Admins still get the full list so they see everyone.
$allUsers = ($current && $current['role'] === 'admin')
    ? $db->query('SELECT username, email, phone FROM users ORDER BY username')->fetchAll()
    : [];
$allowMaybe = get_setting('allow_maybe_rsvp', '1') === '1';
// Leagues the current user can pick from when creating/editing events
$myLeaguesForForm = $current ? user_leagues((int)$current['id']) : [];
// Reminder preset catalog + site default (used for the event editor checkboxes)
$reminder_presets_available = json_decode(get_setting('reminder_offsets_available', '[10080,4320,2880,1440,720,120,30]'), true) ?: [10080,4320,2880,1440,720,120,30];
$reminder_default_offsets   = json_decode(get_setting('default_reminder_offsets',    '[2880,720]'), true) ?: [2880,720];
// All league names for badge display in event view (lightweight — id+name only)
$_leagueNames = [];
foreach ($db->query('SELECT id, name FROM leagues')->fetchAll() as $_ln) {
    $_leagueNames[(int)$_ln['id']] = $_ln['name'];
}

if (get_setting('show_calendar', '1') !== '1') {
    http_response_code(403);
    exit('Calendar is disabled.');
}
// In landing-page mode, guests can't browse the calendar — redirect to home.
if (!$current && get_setting('show_landing_page', '0') === '1') {
    header('Location: /');
    exit;
}

$site_name = get_setting('site_name', 'Game Night');
$local_tz  = new DateTimeZone(display_timezone());

session_start_safe();
$flash = ['type' => '', 'msg' => ''];
if (!empty($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
}

// ── POST ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $__isXhr = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest';
    if (!csrf_verify()) {
        // For AJAX callers, return JSON so the client can show a real message instead
        // of silently following the redirect to HTML and failing to parse it.
        if ($__isXhr) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Your session expired. Please reload the page and try again.']);
            exit;
        }
        $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Invalid request token.'];
        header('Location: /calendar.php');
        exit;
    }

    $action        = $_POST['action'] ?? '';

    // Permission checks below use can_manage_event() from db.php — single source of truth
    // (creator, per-event manager, league owner/manager, or site admin).

    // Non-admins may only update their own RSVP, self-signup, or self-remove
    // When allow_user_events is on, logged-in users can also add/edit/delete their own events
    // Event managers can also edit/delete events they manage
    $userEventActions = ['add', 'edit', 'delete', 'delete_occurrence'];
    // Per-event management actions that target an existing event via event_id (not id).
    // Each is allowed for anyone who can manage that specific event; the handlers below
    // re-check with can_manage_event(), so this gate just needs to let managers through.
    $eventMgmtActions = ['send_invites', 'resend_invite', 'approve_invite', 'deny_invite', 'send_event_message', 'delete_event_message'];
    if (!$isAdmin && !in_array($action, ['update_rsvp', 'self_signup', 'self_remove'], true)) {
        $chkIdForMgr  = (int)($_POST['id'] ?? 0);
        $chkEidForMgr = (int)($_POST['event_id'] ?? 0);
        // Allow edit/delete/delete_occurrence (keyed on id) or the event-management actions
        // (keyed on event_id) if the user can manage this specific event — creator,
        // event-manager, or league owner/manager. Fine-grained checks happen again per-action.
        $isMgr = false;
        if ($chkIdForMgr > 0 && in_array($action, ['edit', 'delete', 'delete_occurrence'], true)) {
            $isMgr = can_manage_event($db, $chkIdForMgr, (int)$current['id'], $isAdmin);
        } elseif ($chkEidForMgr > 0 && in_array($action, $eventMgmtActions, true)) {
            $isMgr = can_manage_event($db, $chkEidForMgr, (int)$current['id'], $isAdmin);
        }
        if (!$isMgr && (!$canCreateEvents || !in_array($action, $userEventActions, true))) {
            if ($__isXhr) {
                http_response_code(403); header('Content-Type: application/json');
                echo json_encode(['ok' => false, 'error' => 'You do not have permission to manage this event.']); exit;
            }
            http_response_code(403); exit('Access denied.');
        }
    }

    // Ownership check: non-admins can only edit/delete events they're permitted to manage.
    // Routed through the single can_manage_event() helper in db.php so the same rules
    // apply everywhere (creator, event-manager, league owner/manager, or site admin).
    if (in_array($action, ['edit', 'delete', 'delete_occurrence'], true)) {
        $chkId = (int)($_POST['id'] ?? 0);
        if ($chkId > 0 && !can_manage_event($db, $chkId, (int)$current['id'], $isAdmin)) {
            http_response_code(403); exit('You can only modify events you manage.');
        }
    }

    if ($action === 'add' || $action === 'edit') {
        require_once __DIR__ . '/_event_save.php';
        $__save_res = event_save_from_post($db, $current, $isAdmin, $allowMaybe);
        if (!$__save_res['ok']) {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => $__save_res['error']];
        } elseif (($__save_res['invites_sent'] ?? 0) > 0) {
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Event saved. Invitations sent to ' . (int)$__save_res['invites_sent'] . ' invitee(s).'];
        } else {
            $_SESSION['flash'] = ['type' => 'success', 'msg' => $action === 'add' ? 'Event added.' : 'Event updated.'];
        }
        $notify_eid = $__save_res['event_id'] ?? null;
        $__save_open_date = $__save_res['ok'] ? ($__save_res['open_date'] ?? '') : '';
    }
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $row = $db->prepare('SELECT title, start_date FROM events WHERE id=?');
            $row->execute([$id]);
            $evt = $row->fetch();
            $t = $evt['title'] ?? $id;

            // Queue cancellation notifications for future events (carry title/date in payload
            // since the event row is about to be deleted below).
            if ($evt && ($evt['start_date'] ?? '') >= date('Y-m-d')) {
                require_once __DIR__ . '/_notifications.php';
                $invStmt = $db->prepare("SELECT ei.username FROM event_invites ei
                    WHERE ei.event_id=? AND ei.occurrence_date IS NULL");
                $invStmt->execute([$id]);
                foreach ($invStmt->fetchAll() as $inv) {
                    queue_event_notification($db, $id, $inv['username'], 'cancel_event', null, [
                        'title' => $t,
                        'start_date' => $evt['start_date'],
                    ]);
                }
            }

            $db->prepare("DELETE FROM comments WHERE type='event' AND content_id=?")->execute([$id]);
            try { $db->prepare('DELETE FROM event_messages WHERE event_id=?')->execute([$id]); } catch (Exception $e) {}
            $db->prepare('DELETE FROM event_exceptions WHERE event_id=?')->execute([$id]);
            $db->prepare('DELETE FROM event_invites WHERE event_id=?')->execute([$id]);
            // Clean up already-sent notification history for this event; leave any unsent
            // rows (e.g., cancel_event queued seconds ago) alone so the drain can finish them.
            $db->prepare('DELETE FROM pending_notifications WHERE event_id=? AND attempted_at IS NOT NULL')->execute([$id]);
            $db->prepare('DELETE FROM event_notifications_sent WHERE event_id=?')->execute([$id]);
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

            // Queue cancellation notifications for RSVPed invitees
            require_once __DIR__ . '/_notifications.php';
            $occ_inv = get_occurrence_invitees($db, $id, $date, true);
            foreach ($occ_inv as $inv) {
                if (!in_array($inv['rsvp'] ?? '', ['yes', 'maybe'])) continue;
                queue_event_notification($db, $id, $inv['username'], 'cancel_occurrence', $date);
            }

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
            // Approval gate: a non-host user cannot RSVP for themselves while their invite is pending or denied.
            // A host (creator/manager/admin) acting on_behalf implicitly approves the row by setting an RSVP.
            $statusStmt = $db->prepare('SELECT approval_status FROM event_invites WHERE event_id=? AND LOWER(username)=LOWER(?) AND occurrence_date IS NULL');
            $statusStmt->execute([$eid, $target_username]);
            $currentApproval = $statusStmt->fetchColumn() ?: 'approved';
            if (!$on_behalf && $currentApproval !== 'approved') {
                if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
                    header('Content-Type: application/json');
                    echo json_encode(['ok' => false, 'error' => 'Awaiting host approval.']);
                    exit;
                }
                $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Your spot for this event is waiting for the host to approve.'];
                header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? '/calendar.php'));
                exit;
            }
            // Host action implicitly approves the row.
            $approval_clause = $on_behalf ? ", approval_status='approved'" : "";

            if ($occDate) {
                // Per-occurrence RSVP: upsert occurrence-specific row
                $chk = $db->prepare('SELECT id, rsvp FROM event_invites WHERE event_id=? AND LOWER(username)=LOWER(?) AND occurrence_date=?');
                $chk->execute([$eid, $target_username, $occDate]);
                $existing = $chk->fetch();
                $oldRsvp  = $existing ? ($existing['rsvp'] ?: null) : null;
                if ($existing) {
                    $db->prepare("UPDATE event_invites SET rsvp=? {$approval_clause} WHERE id=?")->execute([$rsvp, $existing['id']]);
                } else {
                    // Copy contact info and approval_status from base invite row so per-occurrence rows inherit gating.
                    $baseStmt = $db->prepare('SELECT phone, email, approval_status FROM event_invites WHERE event_id=? AND LOWER(username)=LOWER(?) AND occurrence_date IS NULL');
                    $baseStmt->execute([$eid, $target_username]);
                    $baseRow = $baseStmt->fetch();
                    $baseApproval = $on_behalf ? 'approved' : ($baseRow['approval_status'] ?? 'approved');
                    $db->prepare('INSERT INTO event_invites (event_id, username, phone, email, rsvp, rsvp_token, occurrence_date, approval_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                       ->execute([$eid, canonical_username($target_username), $baseRow['phone'] ?? null, $baseRow['email'] ?? null, $rsvp, bin2hex(random_bytes(16)), $occDate, $baseApproval]);
                }
            } else {
                // Base RSVP (non-recurring or updating all-occurrence default)
                $oldRsvpStmt = $db->prepare('SELECT rsvp FROM event_invites WHERE event_id=? AND LOWER(username)=LOWER(?) AND occurrence_date IS NULL');
                $oldRsvpStmt->execute([$eid, $target_username]);
                $oldRsvp = ($oldRsvpStmt->fetchColumn()) ?: null;
                $db->prepare("UPDATE event_invites SET rsvp=? {$approval_clause} WHERE event_id=? AND LOWER(username)=LOWER(?) AND occurrence_date IS NULL")
                   ->execute([$rsvp, $eid, $target_username]);
            }
            db_log_activity($current['id'], "updated RSVP for event id: $eid" . ($occDate ? " on $occDate" : '') . ($on_behalf ? " (on behalf of $target_username)" : ''));

            // Notify the event owner AND every per-event manager if the RSVP
            // actually changed and the editor isn't acting on someone's behalf.
            if (!$on_behalf && $rsvp && $rsvp !== $oldRsvp) {
                require_once __DIR__ . '/_notifications.php';
                queue_rsvp_reply_notifications($db, $eid, null, $current['username'], $current['username'], $rsvp);
            }
        }
        // Auto-promote waitlisted invitee if someone declined
        if ($rsvp === 'no' && $eid > 0) {
            maybe_promote_waitlisted($db, $eid);
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
        $signup_pending = false;
        if ($eid > 0) {
            $chk = $db->prepare('SELECT id, approval_status FROM event_invites WHERE event_id=? AND LOWER(username)=LOWER(?)');
            $chk->execute([$eid, $current['username']]);
            $existing_signup = $chk->fetch();
            if (!$existing_signup) {
                // Self-signup: approval gate fires if the event has requires_approval=1.
                $approval = invite_approval_status($eid, 'self');
                $db->prepare('INSERT INTO event_invites (event_id, username, phone, email, rsvp, rsvp_token, approval_status) VALUES (?, ?, ?, ?, NULL, ?, ?)')
                   ->execute([$eid, $current['username'], $udata['phone'] ?? null, $udata['email'] ?? null, bin2hex(random_bytes(16)), $approval]);
                db_log_activity($current['id'], "signed up for event id: $eid" . ($approval === 'pending' ? ' (pending approval)' : ''));
                if ($approval === 'pending') {
                    $signup_pending = true;
                    notify_creator_of_pending($eid, $current['username']);
                }
            } elseif (($existing_signup['approval_status'] ?? 'approved') === 'pending') {
                // Already pending — show the same waiting-list message, no duplicate notification.
                $signup_pending = true;
            }
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

    // Approve / deny a pending invite. Allowed for admin, event creator, or event manager.
    if (in_array($action, ['approve_invite', 'deny_invite'], true) && $current) {
        $eid    = (int)($_POST['event_id'] ?? 0);
        $target = trim($_POST['target_username'] ?? '');
        if ($eid > 0 && $target !== '') {
            $owner = $db->prepare('SELECT created_by, title, start_date FROM events WHERE id=?');
            $owner->execute([$eid]);
            $evRow = $owner->fetch();
            if (can_manage_event($db, $eid, (int)$current['id'], $isAdmin)) {
                $newStatus = ($action === 'approve_invite') ? 'approved' : 'denied';
                if ($newStatus === 'approved') {
                    // Shared approve sequence: status flip + poker sync + notification.
                    require_once __DIR__ . '/_notifications.php';
                    approve_event_invitee($db, $eid, $target, (int)$current['id']);
                } else {
                    $db->prepare("UPDATE event_invites SET approval_status='denied' WHERE event_id=? AND LOWER(username)=LOWER(?)")
                       ->execute([$eid, $target]);
                    db_log_activity($current['id'], "{$action} for $target on event id: $eid");
                }

                if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
                    header('Content-Type: application/json');
                    echo json_encode(['ok' => true, 'status' => $newStatus]);
                    exit;
                }
                $_SESSION['flash'] = ['type' => 'success', 'msg' => $newStatus === 'approved' ? 'Invite approved.' : 'Invite denied.'];
            } else {
                if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
                    http_response_code(403);
                    header('Content-Type: application/json');
                    echo json_encode(['ok' => false, 'error' => 'Permission denied.']);
                    exit;
                }
                http_response_code(403);
                exit('Permission denied.');
            }
        }
    }

    // Resend an invite SMS/email to a single invitee. Allowed for admin, event creator, or event manager.
    // Clears the dedup marker so the queue drain will actually fire, then re-queues.
    if ($action === 'resend_invite' && $current) {
        $eid    = (int)($_POST['event_id'] ?? 0);
        $target = trim($_POST['target_username'] ?? '');
        $isXhr  = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest';
        if ($eid > 0 && $target !== '' && can_manage_event($db, $eid, (int)$current['id'], $isAdmin)) {
            // Verify the invitee actually exists on this event before doing anything.
            $chk = $db->prepare('SELECT 1 FROM event_invites WHERE event_id=? AND LOWER(username)=LOWER(?) AND occurrence_date IS NULL');
            $chk->execute([$eid, $target]);
            if (!$chk->fetchColumn()) {
                if ($isXhr) {
                    http_response_code(404);
                    header('Content-Type: application/json');
                    echo json_encode(['ok' => false, 'error' => 'Invitee not found on this event.']);
                    exit;
                }
                http_response_code(404);
                exit('Invitee not found.');
            }
            if (get_setting('notifications_enabled', '0') !== '1') {
                if ($isXhr) {
                    header('Content-Type: application/json');
                    echo json_encode(['ok' => false, 'error' => 'Notifications are currently disabled site-wide.']);
                    exit;
                }
                http_response_code(400);
                exit('Notifications are currently disabled.');
            }
            // Clear the dedup marker so the queue drain will actually fire for this invitee.
            $db->prepare("DELETE FROM event_notifications_sent WHERE event_id=? AND occurrence_date='' AND user_identifier=? AND notification_type='invite'")
               ->execute([$eid, strtolower($target)]);
            // Queue a fresh invite notification.
            $db->prepare("INSERT INTO pending_notifications (event_id, username, notify_type) VALUES (?, ?, 'invite')")
               ->execute([$eid, $target]);
            db_log_activity((int)$current['id'], "resent invite to $target on event id: $eid");
            drain_queue_async();
            if ($isXhr) {
                header('Content-Type: application/json');
                echo json_encode(['ok' => true]);
                exit;
            }
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Invite resent to ' . $target . '.'];
        } else {
            if ($isXhr) {
                http_response_code(403);
                header('Content-Type: application/json');
                echo json_encode(['ok' => false, 'error' => 'Permission denied.']);
                exit;
            }
            http_response_code(403);
            exit('Permission denied.');
        }
    }

    // Bulk-send invites for an event to every approved base invitee who has not been sent one yet.
    // This is the explicit "Send Invitations" action — invites no longer go out automatically on save.
    // Unlike resend_invite, this does NOT clear existing dedup markers, so re-clicking only reaches
    // people who still haven't been notified (newly added invitees, failed/never-sent rows).
    if ($action === 'send_invites' && $current) {
        $eid   = (int)($_POST['event_id'] ?? 0);
        $isXhr = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest';
        if ($eid > 0 && can_manage_event($db, $eid, (int)$current['id'], $isAdmin)) {
            if (get_setting('notifications_enabled', '0') !== '1') {
                if ($isXhr) {
                    header('Content-Type: application/json');
                    echo json_encode(['ok' => false, 'error' => 'Notifications are currently disabled site-wide.']);
                    exit;
                }
                http_response_code(400);
                exit('Notifications are currently disabled.');
            }
            // Approved base invitees with no existing 'invite' dedup marker for this event.
            $rows = $db->prepare(
                "SELECT ei.username FROM event_invites ei
                 WHERE ei.event_id = ? AND ei.occurrence_date IS NULL AND ei.approval_status = 'approved'
                   AND NOT EXISTS (
                       SELECT 1 FROM event_notifications_sent ens
                       WHERE ens.event_id = ei.event_id AND ens.notification_type = 'invite'
                         AND ens.occurrence_date = '' AND ens.user_identifier = LOWER(ei.username)
                   )"
            );
            $rows->execute([$eid]);
            $targets = array_column($rows->fetchAll(), 'username');
            $queueStmt = $db->prepare("INSERT INTO pending_notifications (event_id, username, notify_type) VALUES (?, ?, 'invite')");
            $sent = 0;
            foreach ($targets as $uname) { $queueStmt->execute([$eid, $uname]); $sent++; }
            if ($sent > 0) {
                db_log_activity((int)$current['id'], "sent invites to $sent invitee(s) on event id: $eid");
                drain_queue_async();
            }
            if ($isXhr) {
                header('Content-Type: application/json');
                echo json_encode(['ok' => true, 'sent' => $sent]);
                exit;
            }
            $_SESSION['flash'] = ['type' => 'success', 'msg' => $sent > 0 ? "Invitations sent to $sent invitee(s)." : 'Everyone has already been invited.'];
        } else {
            if ($isXhr) {
                http_response_code(403);
                header('Content-Type: application/json');
                echo json_encode(['ok' => false, 'error' => 'Permission denied.']);
                exit;
            }
            http_response_code(403);
            exit('Permission denied.');
        }
    }

    // Host-authored "final details" message to attendees (owner/manager only).
    if ($action === 'send_event_message' && $current) {
        $eid   = (int)($_POST['event_id'] ?? 0);
        $isXhr = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest';
        $fail = function (string $msg, int $code = 400) use ($isXhr) {
            if ($isXhr) { http_response_code($code); header('Content-Type: application/json'); echo json_encode(['ok' => false, 'error' => $msg]); exit; }
            http_response_code($code); exit($msg);
        };
        if ($eid <= 0 || !can_manage_event($db, $eid, (int)$current['id'], $isAdmin)) {
            $fail('Permission denied.', 403);
        }
        if (get_setting('notifications_enabled', '0') !== '1') {
            $fail('Notifications are currently disabled site-wide.');
        }
        $subject  = trim($_POST['subject'] ?? '');
        $audience = in_array($_POST['audience'] ?? '', ['yes', 'yes_maybe', 'all'], true) ? $_POST['audience'] : 'yes';
        $body     = sanitize_html((string)($_POST['body'] ?? ''));
        if ($subject === '' || trim(strip_tags($body)) === '') {
            $fail('A subject and a message are required.');
        }
        // Event date for display/storage on the message row.
        $evStmt = $db->prepare('SELECT start_date FROM events WHERE id = ?');
        $evStmt->execute([$eid]);
        $occ = (string)($_POST['occurrence_date'] ?? '');
        $occ = preg_match('/^\d{4}-\d{2}-\d{2}$/', $occ) ? $occ : ($evStmt->fetchColumn() ?: null);

        // Recipients by audience, among approved base invitees.
        $rsvpFilter = $audience === 'yes' ? "AND rsvp = 'yes'"
                    : ($audience === 'yes_maybe' ? "AND rsvp IN ('yes','maybe')" : '');
        $rows = $db->prepare(
            "SELECT username FROM event_invites
             WHERE event_id = ? AND occurrence_date IS NULL AND approval_status = 'approved' $rsvpFilter"
        );
        $rows->execute([$eid]);
        $targets = array_column($rows->fetchAll(), 'username');

        // Store the message (history + tokenized view link).
        $token = bin2hex(random_bytes(16));
        $db->prepare('INSERT INTO event_messages (event_id, occurrence_date, token, subject, body_html, audience, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)')
           ->execute([$eid, $occ, $token, $subject, $body, $audience, (int)$current['id']]);
        $msgId = (int)$db->lastInsertId();

        require_once __DIR__ . '/_notifications.php';
        $sent = 0;
        foreach ($targets as $uname) {
            // queue_event_notification() schedules a single shutdown drain itself;
            // do NOT also call drain_queue_async() here or two drains race.
            queue_event_notification($db, $eid, $uname, 'event_message', null, ['message_id' => $msgId]);
            $sent++;
        }
        db_log_activity((int)$current['id'], "sent event message ($audience) to $sent guest(s) on event id: $eid");

        if ($isXhr) { header('Content-Type: application/json'); echo json_encode(['ok' => true, 'sent' => $sent, 'msg_id' => $msgId]); exit; }
        $_SESSION['flash'] = ['type' => 'success', 'msg' => "Message sent to $sent guest(s)."];
        header('Location: /calendar.php'); exit;
    }

    // Delete a host message (owner/manager only). The tokenized view link then 404s,
    // and any not-yet-sent queued copies drop on dispatch (message lookup misses).
    if ($action === 'delete_event_message' && $current) {
        $eid   = (int)($_POST['event_id'] ?? 0);
        $mid   = (int)($_POST['message_id'] ?? 0);
        $isXhr = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest';
        if ($eid <= 0 || $mid <= 0 || !can_manage_event($db, $eid, (int)$current['id'], $isAdmin)) {
            if ($isXhr) { http_response_code(403); header('Content-Type: application/json'); echo json_encode(['ok' => false, 'error' => 'Permission denied.']); exit; }
            http_response_code(403); exit('Permission denied.');
        }
        $db->prepare('DELETE FROM event_messages WHERE id = ? AND event_id = ?')->execute([$mid, $eid]);
        db_log_activity((int)$current['id'], "deleted event message id $mid on event id: $eid");
        if ($isXhr) { header('Content-Type: application/json'); echo json_encode(['ok' => true]); exit; }
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Message deleted.'];
        header('Location: /calendar.php'); exit;
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

    $back_wk = $_POST['wk_param'] ?? '';
    $back_m  = $_POST['month_param'] ?? '';
    // Date the event's detail view should reopen on: the edited occurrence when a single
    // occurrence was managed, otherwise the (possibly newly-moved) start date.
    $open_date = $__save_open_date ?? '';
    // After add OR edit: navigate to the event's week/month so the user can see it.
    if (in_array($action, ['add', 'edit'], true) && !empty($open_date)) {
        if (!empty($back_wk)) {
            // Came from week view - compute the Sunday of the event's date
            $evDt  = new DateTime($open_date, $local_tz);
            $evDow = (int)$evDt->format('w');
            $back_wk = (clone $evDt)->modify("-{$evDow} days")->format('Y-m-d');
        } else {
            $back_m = substr($open_date, 0, 7);
        }
    }
    // Auto-open the event's detail view after add or edit so the host lands back on the
    // event (where the "Send Invitations" prompt and RSVP roster live) instead of the bare
    // month grid. Reuses the generic ?open=ID&date=DATE path handled later in this file.
    $openSuffix = (in_array($action, ['add', 'edit'], true) && !empty($notify_eid) && !empty($open_date))
        ? '&open=' . (int)$notify_eid . '&date=' . urlencode($open_date)
        : '';
    if (!empty($back_wk)) {
        header('Location: /calendar.php?wk=' . urlencode($back_wk) . $openSuffix);
    } else {
        header('Location: /calendar.php' . ($back_m ? '?m=' . urlencode($back_m) : '') . $openSuffix);
    }
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

// Fetch events that overlap the month (join leagues so the calendar cells can show a league tag)
$_vis = event_visibility_sql('events', (int)$current['id']);
$evQuery = $db->prepare(
    "SELECT events.*, leagues.name AS league_name FROM events
     LEFT JOIN leagues ON leagues.id = events.league_id
     WHERE events.start_date <= ? AND (events.end_date >= ? OR (events.end_date IS NULL AND events.start_date >= ?))
       AND {$_vis['sql']}
     ORDER BY events.start_date, events.start_time"
);
$evQuery->execute(array_merge([$monthEnd, $monthStart, $monthStart], $_vis['params']));
$allEvents = $evQuery->fetchAll();

// Enrich each event with viewer-tz formatted time strings. Event start_time/end_time
// are stored as wall-clock in site tz; viewers in different tz get their own labels.
$_site_tz = new DateTimeZone(get_setting('timezone', 'UTC'));
foreach ($allEvents as &$_ev) { $_ev = event_display_times($_ev, $_site_tz, $local_tz); }
unset($_ev);

$exceptions = load_exceptions($db, $allEvents);
$byDate     = build_event_by_date($allEvents, $monthStart, $monthEnd, $local_tz, $exceptions);

$pvEvents = [];

// ── View mode (month / week) ───────────────────────────────────────────────────
$viewMode = (($_GET['view'] ?? '') === 'month') ? 'month' : 'week';

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
        "SELECT events.*, leagues.name AS league_name FROM events
         LEFT JOIN leagues ON leagues.id = events.league_id
         WHERE events.start_date <= ? AND (events.end_date >= ? OR (events.end_date IS NULL AND events.start_date >= ?))
           AND {$_visW['sql']}
         ORDER BY events.start_date, events.start_time"
    );
    $wkEvQ->execute(array_merge([$wkEndStr, $wkStartStr, $wkStartStr], $_visW['params']));
    $wkAllEvents = $wkEvQ->fetchAll();
    foreach ($wkAllEvents as &$_ev) { $_ev = event_display_times($_ev, $_site_tz, $local_tz); }
    unset($_ev);
    $wkByDate    = build_event_by_date($wkAllEvents, $wkStartStr, $wkEndStr, $local_tz);
}
// In week view, derive the back-navigation month from the visible week rather than
// the ?m= param (which is absent in week view). This ensures edit/add form redirects
// return to the correct month instead of always defaulting to the current month.
if ($viewMode === 'week' && $wkStart && $mParam === null) {
    $monthParam = $wkStart->format('Y-m');
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
    $is  = $db->prepare("SELECT event_id, username, phone, email, rsvp, occurrence_date, event_role, approval_status, sort_order FROM event_invites WHERE event_id IN ($iph) ORDER BY COALESCE(sort_order, 999999), username");
    $is->execute($allPageEids);
    foreach ($is->fetchAll() as $inv) {
        if ($inv['occurrence_date'] === null) {
            $ev_invites[$inv['event_id']][] = $inv;
        } else {
            $ev_invites_occ[$inv['event_id']][$inv['occurrence_date']][] = $inv;
        }
    }
}
// Which base invitees already have an invite notification on record. Drives the
// "Send Invitations" banner / per-invitee Send vs Resend label in the event view.
$ev_invite_sent = [];  // [eid][username_lower] = true
if (!empty($allPageEids)) {
    $snph = implode(',', array_fill(0, count($allPageEids), '?'));
    $ss = $db->prepare("SELECT event_id, user_identifier FROM event_notifications_sent
                        WHERE notification_type='invite' AND occurrence_date='' AND event_id IN ($snph)");
    $ss->execute($allPageEids);
    foreach ($ss->fetchAll() as $sr) {
        $ev_invite_sent[(int)$sr['event_id']][strtolower($sr['user_identifier'])] = true;
    }
}
// Batch-load poker sessions for events on this page
$ev_poker = [];
if (!empty($allPageEids)) {
    $pph = implode(',', array_fill(0, count($allPageEids), '?'));
    $ps  = $db->prepare("SELECT event_id, game_type, buyin_amount, num_tables, seats_per_table FROM poker_sessions WHERE event_id IN ($pph)");
    $ps->execute($allPageEids);
    foreach ($ps->fetchAll() as $pr) { $ev_poker[(int)$pr['event_id']] = $pr; }
}

// Build list of event IDs the current user manages (per-event manager role
// on event_invites, OR owner/manager of the event's league). Drives the
// edit pencil icon on calendar chips and the "Edit" button in the event
// detail modal. Site admins see everything regardless.
$managedEventIds = [];
if ($current && !$isAdmin) {
    foreach ($ev_invites as $eid => $_invList) {
        foreach ($_invList as $_inv) {
            if (strcasecmp($_inv['username'], $current['username']) === 0 && ($_inv['event_role'] ?? '') === 'manager') {
                $managedEventIds[] = (int)$eid;
            }
        }
    }
    // Add every event in a league where the current user is owner or manager.
    $__mgrLeagueStmt = $db->prepare(
        "SELECT e.id FROM events e
         JOIN league_members lm ON lm.league_id = e.league_id
         WHERE lm.user_id = ? AND lm.role IN ('owner','manager')"
    );
    $__mgrLeagueStmt->execute([(int)$current['id']]);
    foreach ($__mgrLeagueStmt->fetchAll() as $__r) {
        $managedEventIds[] = (int)$__r['id'];
    }
    $managedEventIds = array_values(array_unique($managedEventIds));
}

// Batch-load host messages ("final details") for page events, audience-gated per viewer:
// managers see all; an invitee sees only messages whose audience includes their RSVP.
$ev_messages = [];
if ($current && !empty($allPageEids)) {
    $curLc = strtolower($current['username']);
    $mph = implode(',', array_fill(0, count($allPageEids), '?'));
    $mq  = $db->prepare("SELECT m.id, m.event_id, m.subject, m.body_html, m.audience, m.created_at, u.username AS author, e.created_by
                         FROM event_messages m JOIN events e ON e.id = m.event_id LEFT JOIN users u ON u.id = m.created_by
                         WHERE m.event_id IN ($mph) ORDER BY m.created_at ASC");
    $mq->execute($allPageEids);
    foreach ($mq->fetchAll() as $m) {
        $eid    = (int)$m['event_id'];
        // Manager view = site admin, event creator, per-event manager, or league owner/manager.
        // Mirror the client's _calCanManage (which counts the creator) so an owner sees the history.
        $canMng = $isAdmin || (int)$m['created_by'] === (int)$current['id'] || in_array($eid, $managedEventIds, true);
        $visible = $canMng;
        if (!$visible) {
            foreach (($ev_invites[$eid] ?? []) as $iv) {
                if (strtolower($iv['username']) === $curLc && ($iv['approval_status'] ?? 'approved') === 'approved') {
                    $r = $iv['rsvp'] ?? '';
                    $visible = ($m['audience'] === 'all')
                            || ($m['audience'] === 'yes' && $r === 'yes')
                            || ($m['audience'] === 'yes_maybe' && in_array($r, ['yes', 'maybe'], true));
                    break;
                }
            }
        }
        if ($visible) {
            $ev_messages[$eid][] = [
                'id'         => (int)$m['id'],
                'subject'    => $m['subject'],
                'body_html'  => $m['body_html'],
                'audience'   => $m['audience'],
                'created_at' => $m['created_at'],
                'author'     => $m['author'],
                'can_manage' => $canMng,
            ];
        }
    }
}

// Map each page event to its creator, so invites saved with no contact info can be
// back-filled from that creator's saved contacts (e.g. a contact whose email was added
// after the invite was created). Surfaces the email in the editor; re-saving persists it.
$ev_created_by = [];
if (!empty($allPageEids)) {
    $cbph = implode(',', array_fill(0, count($allPageEids), '?'));
    $cbs  = $db->prepare("SELECT id, created_by FROM events WHERE id IN ($cbph)");
    $cbs->execute($allPageEids);
    foreach ($cbs->fetchAll() as $cbr) $ev_created_by[(int)$cbr['id']] = (int)$cbr['created_by'];
}

// Contact details (phone/email) are kept in the invite data so the event editor can show
// and edit each invitee's contact inline. `no_contact` flags invitees with neither, to warn
// the host they can't be notified.
{
    foreach ($ev_invites as $eid => &$_invList) {
        $creatorId = $ev_created_by[(int)$eid] ?? 0;
        foreach ($_invList as &$_inv) {
            if (trim((string)($_inv['phone'] ?? '')) === '' && trim((string)($_inv['email'] ?? '')) === '' && $creatorId > 0) {
                $resolved = invitee_contact_from_contacts($db, $creatorId, (string)$_inv['username']);
                if ($resolved['email'] !== '' || $resolved['phone'] !== '') {
                    $_inv['email'] = $resolved['email'];
                    $_inv['phone'] = $resolved['phone'];
                }
            }
            $_inv['no_contact'] = (trim((string)($_inv['phone'] ?? '')) === '' && trim((string)($_inv['email'] ?? '')) === '');
            $_inv['sent'] = !empty($ev_invite_sent[(int)$eid][strtolower($_inv['username'])]);
        }
    }
    foreach ($ev_invites_occ as &$_occMap) {
        foreach ($_occMap as &$_invList) {
            foreach ($_invList as &$_inv) {
                $_inv['no_contact'] = (trim((string)($_inv['phone'] ?? '')) === '' && trim((string)($_inv['email'] ?? '')) === '');
            }
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
        /* Compact league identifier shown at the start of an event chip. */
        .ev-league-tag {
            display: inline-block; font-size: .6rem; font-weight: 700;
            padding: 0 4px; margin-right: 3px; border-radius: 3px;
            background: rgba(255,255,255,.28); color: #fff;
            letter-spacing: .04em; line-height: 1.35; flex-shrink: 0;
            vertical-align: middle;
        }
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
        /* Event view card: roomy centered card on desktop, full-screen takeover on
           phones AND iPads in BOTH orientations (portrait via max-width, landscape
           via coarse-pointer query) so the action buttons are never off-screen.
           id-scoped so the orientation overrides win on specificity. */
        #viewModal .modal { max-width:520px; max-height:88vh; }
        @media (max-width:1024px) {
            #viewModal .modal { max-width:100%; width:100%; max-height:100vh; height:100%; border-radius:0; }
        }
        @media (pointer:coarse) and (min-width:1025px) and (max-width:1366px) {
            #viewModal .modal { max-width:100%; width:100%; max-height:100vh; height:100%; border-radius:0; }
            #viewModal.modal-overlay { padding:0; align-items:stretch; }
        }
        /* ── Edit modal ── */
        #editModal .modal { max-width:95vw;width:95vw;max-height:95vh;height:95vh;display:flex;flex-direction:column;padding:0;overflow:hidden; }
        #editModal .modal-header { padding:.9rem 1.25rem;margin-bottom:0;border-bottom:1px solid #e2e8f0;flex-shrink:0; }
        #editModal form { display:flex;flex-direction:column;flex:1;min-height:0;overflow-y:auto; }

        /* Header row: color dot + title + date + time + duration */
        .ev-league-badge { display:inline-block;font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;padding:.2rem .6rem;border-radius:999px;background:#dbeafe;color:#1e40af;white-space:nowrap;vertical-align:middle; }
        .edit-top-bar { display:flex;align-items:center;gap:.75rem;padding:.6rem 1.25rem;flex-wrap:wrap;flex-shrink:0;border-bottom:1px solid #e2e8f0;background:#f8fafc; }
        .edit-top-bar select, .edit-top-bar input[type="text"], .edit-top-bar input[type="date"], .edit-top-bar input[type="time"] {
            padding:.32rem .45rem;border:1.5px solid #e2e8f0;border-radius:6px;font-size:.82rem;background:#fff;color:#1e293b;
        }
        .edit-top-bar select:focus, .edit-top-bar input:focus { border-color:#2563eb;outline:none; }
        .edit-top-bar .edit-title-input { flex:1;min-width:140px; }
        .edit-top-bar label { font-size:.72rem;font-weight:600;color:#64748b;display:flex;flex-direction:column;gap:.1rem; }
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
        #eTimeNative:focus { outline:none;border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.08); }

        /* Manager toggle in invite pane */
        .inv-name-text { flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis; }
        .mgr-toggle { display:inline-flex;align-items:center;gap:.25rem;margin-left:auto;cursor:pointer;flex-shrink:0;user-select:none; }
        .mgr-label { font-size:.65rem;color:#64748b;font-weight:600;text-transform:uppercase;letter-spacing:.03em; }
        .pk-toggle-sm { position:relative;width:28px;height:16px;background:#cbd5e1;border-radius:99px;transition:background .2s;flex-shrink:0; }
        .pk-toggle-sm::after { content:'';position:absolute;top:2px;left:2px;width:12px;height:12px;background:#fff;border-radius:50%;transition:transform .2s;box-shadow:0 1px 2px rgba(0,0,0,.2); }
        .pk-toggle-input:checked + .pk-toggle-sm { background:#7c3aed; }
        .pk-toggle-input:checked + .pk-toggle-sm::after { transform:translateX(12px); }
        #eInvitedList li[data-iname] { display:flex;align-items:center;gap:.4rem;cursor:grab; }
        #eInvitedList li[data-iname].inv-dragging { opacity:.4;background:#dbeafe; }
        /* Auto-number the invited list (1. 2. 3. …). Scoped to real invitee rows so the
           custom-entry row and capacity/declined dividers don't get counted, and reflows
           automatically on add/remove with no JS. */
        #eInvitedList { counter-reset: inv; }
        #eInvitedList li[data-iname] { counter-increment: inv; }
        #eInvitedList li[data-iname] .inv-name-text::before { content: counter(inv) ". "; color:#94a3b8;font-weight:600; }
        .inv-rsvp-badge { font-size:.6rem;font-weight:700;padding:.1rem .35rem;border-radius:3px;text-transform:uppercase;letter-spacing:.03em;flex-shrink:0; }
        .inv-rsvp-yes { background:#dcfce7;color:#166534; }
        .inv-rsvp-no { background:#fee2e2;color:#991b1b; }
        .inv-rsvp-maybe { background:#fef9c3;color:#854d0e; }
        .inv-rsvp-waitlist { background:#eff6ff;color:#1e40af;border:1px solid #93c5fd; }
        .inv-nocontact-tag { display:inline-block;font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.03em;padding:.05rem .35rem;border-radius:3px;background:#fee2e2;color:#991b1b;vertical-align:middle;flex-shrink:0; }
        /* Per-invitee clickable contact indicator (editor invited list) */
        .inv-contact-ctl { flex-shrink:0;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;gap:.15rem;padding:.05rem .3rem;border-radius:4px;line-height:1; }
        .inv-contact-ctl:hover { background:#e2e8f0; }
        .inv-contact-ctl .inv-ci { color:#16a34a;font-size:.95rem;line-height:1; }
        .inv-contact-ctl .inv-na { font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.03em;color:#991b1b;background:#fee2e2;border-radius:3px;padding:.05rem .3rem; }
        .inv-capacity-divider {
            padding:.3rem .5rem;text-align:center;font-size:.7rem;font-weight:700;
            color:#dc2626;background:#fee2e2;border-top:2px dashed #fca5a5;border-bottom:2px dashed #fca5a5;
            margin:.2rem 0;letter-spacing:.03em;cursor:default !important;user-select:none;
        }
        .inv-declined-divider {
            padding:.4rem .5rem;text-align:center;font-size:.75rem;font-weight:700;
            color:#64748b;background:#f1f5f9;border-top:1.5px solid #e2e8f0;
            margin:.3rem 0 .1rem;cursor:pointer !important;user-select:none;
        }
        .inv-declined-divider:hover { background:#e2e8f0; }
        .inv-declined-item { opacity:.5;cursor:default !important; }
        .inv-declined-item .inv-rsvp-badge { display:inline-block !important; }

        /* Invite panel */
        .edit-invite-panel { display:grid;grid-template-columns:1fr auto 1fr;gap:.5rem;padding:0 1.25rem;flex:1;min-height:0; }
        .invite-arrows { display:flex;flex-direction:column;justify-content:center;gap:.4rem;padding:.25rem 0; }
        .inv-arrow-btn { width:32px;height:32px;border:1.5px solid #cbd5e1;border-radius:6px;background:#fff;color:#475569;font-size:1.1rem;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;line-height:1; }
        .inv-arrow-btn:hover { background:#eff6ff;border-color:#2563eb;color:#2563eb; }
        .arrow-mobile { display:none; }
        .invite-pane { display:flex;flex-direction:column;border:1.5px solid #e2e8f0;border-radius:8px;overflow:hidden;min-height:200px; }
        .invite-pane-header { background:#f8fafc;padding:.35rem .65rem;font-size:.7rem;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.05em;flex-shrink:0;border-bottom:1px solid #e2e8f0; }
        .inv-col-head { display:flex;align-items:center;gap:.4rem;padding:.25rem .6rem;font-size:.58rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#94a3b8;border-bottom:1px solid #eef2f7;flex-shrink:0; }
        .inv-col-head span:not(:first-child) { text-align:center; }
        /* Fixed-width trailing columns so the Invited header lines up with each row */
        .inv-col-contact { width:2.8rem;flex-shrink:0; }
        .inv-col-rsvp    { width:3.4rem;flex-shrink:0; }
        .inv-col-mgr     { width:3.4rem;flex-shrink:0; }
        #eInvitedList li[data-iname] .inv-col-rsvp,
        #eInvitedList li[data-iname] .inv-col-mgr { display:inline-flex;align-items:center;justify-content:center; }
        #eInvitedList li[data-iname] .inv-col-mgr .mgr-toggle { margin-left:0; }
        .invite-pane-search { width:100%;padding:.38rem .65rem;border:none;border-bottom:1.5px solid #e2e8f0;font-size:.85rem;box-sizing:border-box;flex-shrink:0; }
        .invite-pane-search:focus { outline:none;border-color:#2563eb; }
        .invite-pane-list { flex:1;overflow-y:auto;list-style:none;margin:0;padding:.2rem; }
        .invite-pane-list li { padding:.35rem .6rem;border-radius:5px;font-size:.875rem;cursor:pointer;user-select:none;white-space:nowrap;overflow:hidden;text-overflow:ellipsis; }
        .invite-pane-list li:hover { background:#f1f5f9; }
        .invite-pane-list li.inv-selected { background:#dbeafe !important;color:#1e40af; }
        /* Already-invited contacts: a green check + muted (but still legible) text so it's
           obvious at a glance which of many names are already on the right. */
        .invite-pane-list li.dimmed { color:#94a3b8;cursor:default; }
        .invite-pane-list li.dimmed::before { content:'\2713\00a0';color:#16a34a;font-weight:700; }
        .invite-pane-list li.dimmed:hover { background:transparent; }
        .invite-pane-list li.custom-row { padding:.2rem .4rem;cursor:default; }
        .invite-pane-list li.custom-row:hover { background:transparent; }
        .inv-mem-tag { display:inline-block;font-size:.7rem;font-weight:700;text-transform:uppercase;padding:.05rem .4rem;border-radius:999px;margin-left:.4rem;vertical-align:middle; }
        .inv-mem-yes { background:#dcfce7;color:#166534; }
        .inv-mem-no  { background:#e2e8f0;color:#475569; }
        .custom-row-inner { display:flex;gap:.3rem;align-items:center;flex-wrap:wrap; }
        .custom-row-inner input { padding:.28rem .45rem;border:1.5px solid #e2e8f0;border-radius:5px;font-size:.8rem;min-width:0; }
        .custom-row-inner .cr-name    { flex:1.5;min-width:110px; }
        .custom-row-inner .cr-contact { flex:2.5;min-width:160px; }
        .custom-row-inner .cr-remove  { flex-shrink:0;padding:.2rem .4rem;border:1px solid #e2e8f0;border-radius:5px;background:#fff;cursor:pointer;color:#94a3b8;font-size:.85rem;line-height:1; }
        .custom-row-inner .cr-remove:hover { background:#fee2e2;color:#dc2626;border-color:#fca5a5; }
        /* hidden invite inputs container */
        #eInviteData { display:none; }

        /* Toolbar + description */
        .edit-toolbar { display:flex;align-items:center;gap:.6rem;padding:.4rem 1rem;flex-wrap:wrap;flex-shrink:0;border-top:1px solid #e2e8f0;background:#f8fafc; }
        .edit-toolbar .btn { font-size:.78rem;padding:.3rem .65rem; }
        .edit-desc-wrap { padding:0 1rem .5rem;flex-shrink:0; }
        .edit-desc-wrap textarea { width:100%;resize:vertical;min-height:80px;max-height:150px;padding:.5rem .7rem;border:1.5px solid #e2e8f0;border-radius:7px;font-size:.85rem;box-sizing:border-box;font-family:inherit; }
        .edit-desc-wrap textarea:focus { outline:none;border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.08); }
        .edit-desc-toggle { font-size:.82rem;color:#2563eb;cursor:pointer;padding:.3rem 1rem;flex-shrink:0; }
        .edit-desc-toggle:hover { text-decoration:underline; }
        .edit-poker-bar { display:flex;align-items:center;gap:.5rem;padding:.3rem 1rem;flex-wrap:wrap;flex-shrink:0;background:#f0f9ff;border-top:1px solid #bfdbfe;font-size:.78rem;color:#475569; }
        .edit-poker-bar label { display:flex;align-items:center;gap:.25rem; }
        .edit-poker-bar select, .edit-poker-bar input { padding:.25rem .35rem;border:1px solid #cbd5e1;border-radius:4px;font-size:.78rem;background:#fff;width:auto; }
        .edit-poker-bar input[type="number"] { width:60px; }
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

        @media (max-width: 1024px) {
            .edit-top-bar { gap:.5rem;padding:.5rem .75rem; }
            .edit-top-bar .edit-title-input { flex:1 1 100%;min-width:0; }

            .edit-invite-panel { grid-template-columns:1fr;height:auto;padding:0 .75rem; }
            .invite-arrows { flex-direction:row;justify-content:center;padding:.25rem 0; }
            .arrow-desktop { display:none; }
            .arrow-mobile { display:inline; }
            .invite-pane { min-height:180px; }
            .invite-pane-list li { padding:.5rem .75rem;font-size:.95rem; }
            .invite-pane input[type="text"] { min-height:44px;font-size:1rem; }
            #eAllUsersList li:not(.dimmed):not(.custom-row)::after { content:'+';float:right;color:#22c55e;font-weight:700;font-size:1.1rem; }
            #eInvitedList li[data-iname]::after { content:'\00d7';float:right;color:#dc2626;font-weight:700;font-size:1.1rem; }

            .edit-toolbar { gap:.4rem;padding:.4rem .75rem; }
            .edit-toolbar .btn { width:auto;min-height:38px;font-size:.85rem; }
            .edit-poker-bar { padding:.3rem .75rem; }
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
        .ev-view-desc  {
            font-size: .9rem; color: #334155; white-space: pre-wrap;
            max-height: 30vh; overflow-y: auto;
            overscroll-behavior: contain; padding-right: .25rem;
        }
        .ev-view-actions { display: flex; gap: .5rem; margin-top: 1.25rem; }

        /* Color swatches */
        .color-swatches { display: flex; gap: .5rem; flex-wrap: wrap; margin-top: .25rem; }
        .color-swatch {
            width: 28px; height: 28px; border-radius: 50%; cursor: pointer;
            border: 3px solid transparent; transition: border-color .15s;
        }
        .color-swatch.selected,
        .color-swatch:hover { border-color: #1e293b; }

        @media (max-width: 1024px) {
            /* Month view */
            .cal-header { gap: .5rem; }
            .cal-nav .month-label { min-width: 120px; font-size: .9rem; }

            /* Show hover-only buttons on touch devices (touch has no hover) */
            .cal-event .ev-edit-btn { display:block;padding:2px 6px;font-size:.85rem; }
            .cal-add-btn { display:flex !important;width:28px;height:28px; }
            .week-allday-chip .ev-edit-btn { display:block;font-size:.75rem;padding:2px 6px; }
            .week-event .ev-edit-btn { display:block;padding:4px 6px;font-size:.8rem; }

            /* Bigger RSVP selects */
            .inv-rsvp-sel { min-height:36px;font-size:.85rem !important;padding:.3rem .5rem !important; }

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
    <?php if ($isAdmin): ?><script src="/vendor/qrcode.min.js"></script><?php endif; ?>
    <?php if ($current): ?><link href="/vendor/jodit/jodit.min.css" rel="stylesheet"><?php endif; ?>
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
                <a href="/calendar.php?view=week&amp;wk=<?= $currentWeekStr ?>"
                   class="<?= $viewMode === 'week' ? 'vt-active' : '' ?>">Week</a>
                <a href="/calendar.php?view=month&amp;m=<?= $monthParam ?>"
                   class="<?= $viewMode === 'month' ? 'vt-active' : '' ?>">Month</a>
            </div>
            <?php if ($viewMode === 'month'): ?>
            <div class="cal-nav">
                <a href="/calendar.php?m=<?= $prevMonth ?>&view=month" title="Previous month">&#8249;</a>
                <span class="month-label"><?= $display->format('F Y') ?></span>
                <a href="/calendar.php?m=<?= $nextMonth ?>&view=month" title="Next month">&#8250;</a>
                <a href="/calendar.php?view=month" style="font-size:.75rem;width:auto;padding:0 .65rem;font-weight:600" title="Today">Today</a>
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
                    <?php
                        $_lgName = $ev['league_name'] ?? '';
                        $_lgTag  = '';
                        if ($_lgName !== '') {
                            // Build a short tag from the league name: first 3 letters of the first 2 words, uppercase.
                            $_lgWords = preg_split('/\s+/', trim($_lgName));
                            $_lgTag   = mb_strtoupper(substr($_lgWords[0] ?? '', 0, 3));
                            if (isset($_lgWords[1])) $_lgTag .= mb_strtoupper(substr($_lgWords[1], 0, 2));
                        }
                    ?>
                    <div class="cal-event"
                         style="background:<?= htmlspecialchars($ev['color']) ?>"
                         onclick="viewEvent(<?= htmlspecialchars(json_encode($ev)) ?>)"
                         title="<?= htmlspecialchars($_lgName ? $_lgName . ' — ' . $ev['title'] : $ev['title']) ?>">
                        <span class="ev-label">
                            <?php if ($ev['start_time'] && $ev['start_date'] === $dateStr): ?>
                                <?= htmlspecialchars($ev['start_time_display'] ?: date('g:ia', strtotime($ev['start_time']))) ?>
                            <?php endif; ?>
                            <?php if ($_lgTag !== ''): ?><span class="ev-league-tag" title="<?= htmlspecialchars($_lgName) ?>"><?= htmlspecialchars($_lgTag) ?></span><?php endif; ?>
                            <?= htmlspecialchars($ev['title']) ?>
                        </span>
                        <?php if ($isAdmin || ($canCreateEvents && (int)$ev['created_by'] === (int)$current['id']) || in_array((int)$ev['id'], $managedEventIds, true)): ?>
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
                <?php
                    $_lgName = $ev['league_name'] ?? '';
                    $_lgTag  = '';
                    if ($_lgName !== '') {
                        $_lgWords = preg_split('/\s+/', trim($_lgName));
                        $_lgTag   = mb_strtoupper(substr($_lgWords[0] ?? '', 0, 3));
                        if (isset($_lgWords[1])) $_lgTag .= mb_strtoupper(substr($_lgWords[1], 0, 2));
                    }
                ?>
                <div class="week-allday-chip"
                     style="background:<?= htmlspecialchars($ev['color']) ?>"
                     title="<?= htmlspecialchars($_lgName ? $_lgName . ' — ' . $ev['title'] : $ev['title']) ?>"
                     onclick="viewEvent(<?= htmlspecialchars(json_encode($ev)) ?>)">
                    <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;flex:1">
                        <?php if ($_lgTag !== ''): ?><span class="ev-league-tag" title="<?= htmlspecialchars($_lgName) ?>"><?= htmlspecialchars($_lgTag) ?></span><?php endif; ?>
                        <?= htmlspecialchars($ev['title']) ?>
                    </span>
                    <?php if ($isAdmin || ($canCreateEvents && (int)$ev['created_by'] === (int)$current['id']) || in_array((int)$ev['id'], $managedEventIds, true)): ?>
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
    <div class="modal" style="overflow:hidden;display:flex;flex-direction:column">
        <div class="modal-header" style="flex-shrink:0">
            <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;flex:1;min-width:0">
                <span id="vLeagueBadge" class="ev-league-badge" style="display:none"></span>
                <h2 id="vTitle" class="ev-view-title" style="margin:0"></h2>
            </div>
            <div style="display:flex;gap:.3rem;align-items:center">
                <button class="modal-close" id="vCopyLinkBtn" title="Copy link to this event"
                        onclick="copyEventLink()" style="font-size:.95rem">&#128279;</button>
                <button class="modal-close" onclick="closeView()">&#x2715;</button>
            </div>
        </div>
        <!-- Single scroll region: header stays pinned, everything else (incl. the
             action buttons) scrolls, so a tall event can't push the buttons off-screen. -->
        <div id="vScrollBody" style="flex:1;min-height:0;overflow-y:auto;-webkit-overflow-scrolling:touch">
        <div id="vSavedBar" style="visibility:hidden;background:#dcfce7;color:#166534;border-radius:7px;padding:.2rem .9rem;font-size:.8rem;font-weight:600;margin-bottom:.5rem;text-align:center">
            Saved
        </div>
        <div id="vMeta"    class="ev-view-meta"></div>
        <div id="vWaitlistNotice" style="display:none;padding:.4rem .75rem;margin:.4rem 0;font-size:.82rem;font-weight:600;color:#1e40af;background:#eff6ff;border:1px solid #93c5fd;border-radius:6px"></div>
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
        <?php if ($canEditEvents): ?>
        <div class="ev-view-actions" id="vEventActions" style="display:none">
            <a id="vManageGameBtn" href="#" class="btn" style="background:#059669;color:#fff;text-decoration:none">Manage Game</a>
            <button type="button" class="btn btn-primary" onclick="editFromView()">Edit</button>
            <?php if ($isAdmin): ?><button type="button" class="btn btn-outline" title="Walk-up QR code" onclick="openWalkinQR()" style="font-size:1rem;padding:.38rem .65rem">&#x1F4F1; QR</button><?php endif; ?>
            <form method="post" action="/calendar.php" style="margin:0" id="vDeleteOccForm" style="display:none">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">
                <input type="hidden" name="action" value="delete_occurrence">
                <input type="hidden" name="id" id="vDeleteOccId" value="">
                <input type="hidden" name="occurrence_date" id="vDeleteOccDate" value="">
                <input type="hidden" name="month_param" value="<?= htmlspecialchars($monthParam) ?>">
                <input type="hidden" name="wk_param" value="<?= $wkStart !== null ? htmlspecialchars($wkStartStr) : '' ?>">
            </form>
            <form method="post" action="/calendar.php" style="margin:0"
                  onsubmit="return confirm('Delete this event?')">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" id="vDeleteId">
                <input type="hidden" name="month_param" value="<?= htmlspecialchars($monthParam) ?>">
                <input type="hidden" name="wk_param" value="<?= $wkStart !== null ? htmlspecialchars($wkStartStr) : '' ?>">
                <button type="submit" class="btn" style="background:#dc2626;color:#fff">Delete</button>
            </form>
        </div>
        <?php endif; ?>

        <!-- Comments -->
        <div class="comments-section" id="vCommentsSection" style="margin-top:.75rem">
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
            <div id="vCommentsScroll" style="padding-right:.25rem">
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
        </div><!-- /vScrollBody -->
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
        <button class="btn btn-outline" onclick="openWalkinSeparate()" style="width:100%;margin-bottom:.5rem">Open on separate screen</button>
        <button class="btn" onclick="closeWalkinQR()" style="width:100%;background:#f1f5f9;color:#475569">Close</button>
    </div>
</div>
<?php endif; ?>

<?php if ($canEditEvents): ?>
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
            <input type="hidden" name="wk_param" value="<?= $wkStart !== null ? htmlspecialchars($wkStartStr) : '' ?>">
            <input type="hidden" name="occurrence_date" id="eOccDate" value="">
            <input type="hidden" name="end_date" id="eEndDate" value="">
            <input type="hidden" name="end_time" id="eEndTime" value="">
            <input type="hidden" name="color" id="eColor" value="#2563eb">
            <input type="hidden" name="send_after_save" id="eSendAfterSave" value="">

            <!-- ── Unified top bar: league + vis + color + title + date + time + duration ── -->
            <div class="edit-top-bar">
                <label>League
                    <select name="league_id" id="eLeagueId" onchange="onLeagueChange()">
                        <option value="0">None</option>
                        <?php foreach ($myLeaguesForForm as $_lg): ?>
                            <option value="<?= (int)$_lg['id'] ?>" data-default-visibility="<?= htmlspecialchars($_lg['default_visibility']) ?>"><?= htmlspecialchars($_lg['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Visibility
                    <select name="visibility" id="eVisibility">
                        <option value="invitees_only">Invitees only</option>
                        <option value="league" id="eVisLeagueOpt" disabled>League members only</option>
                        <?php if ($isAdmin): ?><option value="public">Public</option><?php endif; ?>
                    </select>
                </label>
                <div id="eColorDotWrap" style="align-self:center">
                    <div id="eColorDot" style="background:#2563eb" onclick="toggleColorPicker(event)" title="Pick color"></div>
                    <div id="eColorPicker">
                        <?php foreach (['#2563eb','#16a34a','#dc2626','#d97706','#7c3aed','#0891b2','#db2777'] as $c): ?>
                            <div class="color-swatch" style="background:<?= $c ?>" data-color="<?= $c ?>" onclick="selectColor('<?= $c ?>')"></div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <input type="text" name="title" id="eTitle" class="edit-title-input" placeholder="Event title" required autocomplete="off">
                <label>Date <input type="date" name="start_date" id="eStartDate" required></label>
                <label>Time <input type="time" id="eTimeNative"><input type="hidden" name="start_time" id="eStartTime"></label>
                <label>Duration
                    <select id="eDuration">
                        <option value="">—</option>
                        <option value="0.5">30m</option><option value="1">1h</option><option value="1.5">1.5h</option>
                        <option value="2">2h</option><option value="3">3h</option><option value="4">4h</option>
                        <option value="6">6h</option><option value="8">8h</option>
                    </select>
                </label>
            </div>

            <!-- ── Toolbar: toggles + actions ── -->
            <div class="edit-toolbar">
                <button type="button" class="btn btn-outline" onclick="addBlankInviteRow()">+ Custom Invitee</button>
                <label class="edit-notify-row"><span>Poker</span><input type="checkbox" name="is_poker" id="eIsPoker" value="1" class="pk-toggle-input" onchange="togglePokerFields()"><span class="pk-toggle-slider"></span></label>
                <label class="edit-notify-row" id="eWaitlistLabel" style="display:none"><span>Waitlist</span><input type="checkbox" name="waitlist_enabled" id="eWaitlistEnabled" value="1" class="pk-toggle-input" onchange="updateCapacityLine()"><span class="pk-toggle-slider"></span></label>
                <label class="edit-notify-row" title="Walk-in QR and self-signups require approval"><span>Approval</span><input type="checkbox" name="requires_approval" id="eRequiresApproval" value="1" class="pk-toggle-input"><span class="pk-toggle-slider"></span></label>
                <label class="edit-notify-row" title="Send reminders before the event"><span>Reminders</span><input type="checkbox" name="reminders_enabled" id="eRemindersEnabled" value="1" class="pk-toggle-input" onchange="toggleReminderFields()" checked><span class="pk-toggle-slider"></span></label>
                <span class="edit-desc-toggle" id="eDescToggle" onclick="toggleDesc()">+ Description</span>
                <div style="flex:1"></div>
                <button type="submit" class="btn btn-primary" id="eSubmitBtn" onclick="document.getElementById('eSendAfterSave').value=''">Add Event</button>
                <?php if (get_setting('notifications_enabled', '0') === '1'): ?>
                <button type="submit" class="btn btn-primary" id="eSubmitSendBtn" style="background:#16a34a;border-color:#16a34a" onclick="document.getElementById('eSendAfterSave').value='1'" title="Save the event and send invitations now">Save &amp; Send Invites</button>
                <?php endif; ?>
                <button type="button" class="btn btn-outline" onclick="closeEdit()">Cancel</button>
            </div>

            <!-- ── Poker settings bar (inline, hidden by default) ── -->
            <div class="edit-poker-bar" id="ePokerFields" style="display:none">
                <label>Type <select name="poker_game_type" id="ePokerGameType"><option value="tournament">Tournament</option><option value="cash">Cash</option></select></label>
                <label>Buy-in $ <input type="number" name="poker_buyin" id="ePokerBuyin" min="0" step="1" value="20"></label>
                <label>Tables <input type="number" name="poker_tables" id="ePokerTables" min="1" max="50" value="1" onchange="updateCapacityLine()" oninput="updateCapacityLine()"></label>
                <label>Seats <input type="number" name="poker_seats" id="ePokerSeats" min="2" max="12" value="8" onchange="updateCapacityLine()" oninput="updateCapacityLine()"></label>
                <label>Deadline <select name="rsvp_deadline_hours" id="eRsvpDeadline"><option value="">None</option><option value="24">24h</option><option value="48">48h</option><option value="72">72h</option></select></label>
                <span id="eCapacityHint" style="font-weight:700;color:#2563eb">8 seats</span>
            </div>

            <!-- ── Reminders bar (multi-select presets; hidden when reminders off) ── -->
            <div class="edit-poker-bar" id="eReminderFields">
                <span style="font-weight:600;color:#475569">Send reminders:</span>
                <?php foreach ($reminder_presets_available as $__off):
                    $__off = (int)$__off;
                    $__checked = in_array($__off, $reminder_default_offsets, true) ? 'checked' : '';
                    $__label = $__off >= 10080 && $__off % 10080 === 0 ? ($__off/10080 . ' wk')
                            : ($__off >= 1440 && $__off % 1440 === 0 ? ($__off/1440 . ' day')
                            : ($__off >= 60   && $__off % 60   === 0 ? ($__off/60   . ' hr')
                            : ($__off . ' min')));
                ?>
                <label style="display:inline-flex;align-items:center;gap:.25rem;font-weight:500;white-space:nowrap">
                    <input type="checkbox" name="reminder_offsets[]" class="eReminderPreset" value="<?= $__off ?>" <?= $__checked ?>>
                    <?= htmlspecialchars($__label) ?>
                </label>
                <?php endforeach; ?>
            </div>

            <!-- ── Description (collapsed by default) ── -->
            <div class="edit-desc-wrap" id="eDescWrap" style="display:none">
                <textarea name="description" id="eDesc" rows="3" placeholder="Event description (optional)"></textarea>
            </div>

            <!-- ── Dual-pane invite panel with arrow buttons ── -->
            <div class="edit-invite-panel">
                <!-- Left: all users -->
                <div class="invite-pane">
                    <div class="invite-pane-header">All Users</div>
                    <input type="text" id="eUserSearch" class="invite-pane-search"
                           placeholder="<?= $isAdmin ? 'Search name, email, phone&hellip;' : 'Search name&hellip;' ?>"
                           oninput="filterAllUsers(this.value)" autocomplete="off">
                    <label id="eHideNonMembersWrap" style="display:none;align-items:center;gap:.4rem;padding:.25rem .65rem .35rem;font-size:.75rem;color:#64748b;cursor:pointer">
                        <input type="checkbox" id="eHideNonMembers" class="pk-toggle-input" onchange="onHideNonMembersChange()">
                        <span class="pk-toggle-sm"></span>
                        <span>Hide non-members</span>
                    </label>
                    <ul class="invite-pane-list" id="eAllUsersList"></ul>
                </div>
                <!-- Center: arrow buttons (desktop: left/right, mobile: up/down) -->
                <div class="invite-arrows">
                    <button type="button" class="inv-arrow-btn" onclick="moveRight()" title="Add selected"><span class="arrow-desktop">&rsaquo;</span><span class="arrow-mobile">&darr;</span></button>
                    <button type="button" class="inv-arrow-btn" onclick="moveAllRight()" title="Add all visible"><span class="arrow-desktop">&raquo;</span><span class="arrow-mobile">&dArr;</span></button>
                    <button type="button" class="inv-arrow-btn" onclick="moveLeft()" title="Remove selected"><span class="arrow-desktop">&lsaquo;</span><span class="arrow-mobile">&uarr;</span></button>
                    <button type="button" class="inv-arrow-btn" onclick="moveAllLeft()" title="Remove all"><span class="arrow-desktop">&laquo;</span><span class="arrow-mobile">&uArr;</span></button>
                </div>
                <!-- Right: invited users -->
                <div class="invite-pane">
                    <div class="invite-pane-header">Invited</div>
                    <div class="inv-col-head">
                        <span style="flex:1;min-width:0">Name</span>
                        <span class="inv-col-contact" title="Click the icon on a row to add/edit email &amp; phone">Contact</span>
                        <span class="inv-col-rsvp">RSVP</span>
                        <span class="inv-col-mgr"></span>
                    </div>
                    <ul class="invite-pane-list" id="eInvitedList"></ul>
                </div>
            </div>
            <!-- Hidden inputs synced from invite lists -->
            <div id="eInviteData"></div>
        </form>
        <?php if ($isAdmin): ?>
        <div id="eRegenWalkinWrap" style="display:none;padding:.4rem 1rem .6rem;flex-shrink:0">
            <button type="button" id="eRegenWalkinBtn"
                    style="width:100%;padding:.38rem;border:1.5px solid #cbd5e1;border-radius:7px;background:#fff;color:#64748b;font-size:.78rem;cursor:pointer;font-weight:600"
                    onclick="regenWalkinFromEdit()">
                Regenerate walk-up link
            </button>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ── Per-invitee contact editor (email / phone) ── -->
<div class="modal-overlay" id="invContactModal" onclick="if(event.target===this)closeContactEdit()">
    <div class="modal" style="max-width:340px">
        <div class="modal-header" style="justify-content:space-between">
            <h2 style="font-size:1rem;font-weight:700">Contact for <span id="invContactName"></span></h2>
            <button class="modal-close" type="button" onclick="closeContactEdit()">&#x2715;</button>
        </div>
        <label style="display:block;font-size:.8rem;color:#475569;margin:.4rem 0 .15rem">Email</label>
        <input type="email" id="invContactEmail" autocomplete="off" placeholder="name@example.com"
               style="width:100%;padding:.5rem .6rem;border:1.5px solid #e2e8f0;border-radius:7px;font-size:.9rem;box-sizing:border-box">
        <label style="display:block;font-size:.8rem;color:#475569;margin:.6rem 0 .15rem">Phone</label>
        <input type="tel" id="invContactPhone" autocomplete="off" placeholder="(555) 123-4567"
               style="width:100%;padding:.5rem .6rem;border:1.5px solid #e2e8f0;border-radius:7px;font-size:.9rem;box-sizing:border-box">
        <div style="display:flex;gap:.5rem;margin-top:1rem">
            <button type="button" class="btn btn-primary" style="flex:1" onclick="saveContactEdit()">Save</button>
            <button type="button" class="btn btn-outline" style="flex:1" onclick="closeContactEdit()">Cancel</button>
        </div>
    </div>
</div>

<!-- ── Save / Send progress overlay (shown while the editor POSTs + queues invites) ── -->
<div id="saveSendOverlay" style="display:none;position:fixed;inset:0;z-index:10000;background:rgba(15,23,42,.55);align-items:center;justify-content:center;padding:1rem">
    <div style="background:#fff;border-radius:14px;padding:1.75rem 2rem;max-width:360px;width:100%;text-align:center;box-shadow:0 16px 48px rgba(0,0,0,.3)">
        <div id="saveSendTitle" style="font-size:1.05rem;font-weight:700;color:#1e293b;margin-bottom:.4rem">Saving event&hellip;</div>
        <div id="saveSendMsg" style="font-size:.85rem;color:#64748b;margin-bottom:1.1rem">Please wait.</div>
        <div style="height:9px;background:#e2e8f0;border-radius:99px;overflow:hidden">
            <div id="saveSendBar" style="height:100%;width:8%;background:#16a34a;border-radius:99px;transition:width .35s ease"></div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/_footer.php'; ?>

<script>
let currentEvent = null;
const eventComments      = <?= json_encode($ev_comments, JSON_HEX_TAG) ?>;
/* (object) cast keeps the top level an object (incl. empty {}) WITHOUT JSON_FORCE_OBJECT,
   which would recursively turn each event's message ARRAY into an object and break msgs.length/forEach. */
const eventMessages      = <?= json_encode((object)$ev_messages, JSON_HEX_TAG) ?>;
const eventInvites       = <?= json_encode($ev_invites, JSON_HEX_TAG) ?>;
const eventInvitesByOcc  = <?= json_encode($ev_invites_occ, JSON_HEX_TAG) ?>;
const eventPoker         = <?= json_encode($ev_poker, JSON_HEX_TAG | JSON_FORCE_OBJECT) ?>;
const CURRENT_USERNAME  = <?= json_encode($current['username'] ?? '', JSON_HEX_TAG) ?>;
const CURRENT_USER_ID   = <?= json_encode($current['id'] ?? null, JSON_HEX_TAG) ?>;
const CAL_REDIR         = '/calendar.php?m=<?= htmlspecialchars($monthParam) ?>';
const CAL_CSRF          = <?= json_encode($token, JSON_HEX_TAG) ?>;
const CAL_CURRENT_ID    = <?= (int)($current['id'] ?? 0) ?>;
const IS_ADMIN = <?= $isAdmin ? 'true' : 'false' ?>;
const CAN_CREATE_EVENTS = <?= $canCreateEvents ? 'true' : 'false' ?>;
const ALLOW_MAYBE = <?= $allowMaybe ? 'true' : 'false' ?>;
const NOTIFS_ENABLED = <?= get_setting('notifications_enabled', '0') === '1' ? 'true' : 'false' ?>;
const LEAGUE_NAMES = <?= json_encode((object)$_leagueNames, JSON_HEX_TAG | JSON_FORCE_OBJECT) ?>;
const MANAGED_EVENT_IDS = <?= json_encode(array_values($managedEventIds), JSON_HEX_TAG) ?>;
<?php if ($canEditEvents): ?>
<?php if ($isAdmin): ?>
var ALL_USERS = <?= json_encode(array_values($allUsers), JSON_HEX_TAG) ?>;
<?php else: ?>
var ALL_USERS = [];
<?php endif; ?>
<?php endif; ?>

// ── View modal ────────────────────────────────────────────────────────────────
function viewEvent(ev) {
    currentEvent = ev;
    document.getElementById('vTitle').textContent = ev.title;
    var lbadge = document.getElementById('vLeagueBadge');
    if (ev.league_id && LEAGUE_NAMES[ev.league_id]) {
        lbadge.textContent = LEAGUE_NAMES[ev.league_id];
        lbadge.style.display = '';
    } else {
        lbadge.style.display = 'none';
    }

    let meta = ev.start_date;
    if (ev.end_date && ev.end_date !== ev.start_date) meta += ' \u2013 ' + ev.end_date;
    if (ev.start_time) {
        meta += '  \u00b7  ' + (ev.start_time_display || fmt12(ev.start_time));
        if (ev.end_time) meta += ' \u2013 ' + (ev.end_time_display || fmt12(ev.end_time));
    }
    // Seat count for poker events
    var ps = ev ? (eventPoker[ev.id] || null) : null;
    if (ps) {
        var cap = (parseInt(ps.seats_per_table,10) || 8) * (parseInt(ps.num_tables,10) || 1);
        var invList = eventInvites[ev.id] || [];
        var yesCount = invList.filter(function(i) { return i.rsvp === 'yes' && i.approval_status === 'approved'; }).length;
        meta += '  \u00b7  ' + yesCount + '/' + cap + ' seats filled';
    }
    document.getElementById('vMeta').textContent = meta;

    // Waitlist notice for the current user
    var vWaitlistEl = document.getElementById('vWaitlistNotice');
    if (vWaitlistEl) vWaitlistEl.style.display = 'none';
    if (ps && CURRENT_USERNAME) {
        var allInvSorted = (eventInvites[ev.id] || []).slice().sort(function(a,b) { return (a.sort_order||999)-(b.sort_order||999); });
        var myInv = allInvSorted.find(function(i) { return i.username.toLowerCase() === CURRENT_USERNAME.toLowerCase(); });
        if (myInv && myInv.approval_status === 'waitlisted') {
            var wlPos = 0;
            allInvSorted.forEach(function(i,idx) {
                if (i.approval_status === 'waitlisted' && i.username.toLowerCase() === CURRENT_USERNAME.toLowerCase()) {
                    wlPos = idx + 1;
                }
            });
            if (vWaitlistEl) {
                var cap2 = (parseInt(ps.seats_per_table,10)||8) * (parseInt(ps.num_tables,10)||1);
                vWaitlistEl.textContent = 'You are on the waitlist (position #' + (wlPos - cap2) + '). You\'ll be notified if a seat opens.';
                vWaitlistEl.style.display = '';
            }
        }
    }

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
    window._calCanManage = IS_ADMIN || (CURRENT_USER_ID && ev.created_by == CURRENT_USER_ID) || MANAGED_EVENT_IDS.includes(ev.id);
    renderInvitesPanel(ev.id);
    <?php if ($canEditEvents): ?>
    // Show edit/delete actions only for admins, event owner, or managers
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

    const sb = document.getElementById('vScrollBody');
    if (sb) sb.scrollTop = 0;
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
    const allInvites = getEffectiveInvites(eid, null);
    const vInvDiv    = document.getElementById('vInvites');
    const canManage  = window._calCanManage || false;
    const rsvpClass  = {yes:'rsvp-yes', no:'rsvp-no', maybe:'rsvp-maybe'};
    const rsvpText   = {yes:'Yes', no:'No', maybe:'Maybe'};

    // Split by approval_status. Approved non-declined go in the main list; pending rows
    // get their own section visible only to managers (creator/manager/admin).
    // Declined (rsvp='no') get their own subsection so managers can see who said no
    // without it crowding the main attendee count.
    const approved = allInvites.filter(inv => (inv.approval_status || 'approved') === 'approved' && inv.rsvp !== 'no');
    const declined = allInvites.filter(inv => (inv.approval_status || 'approved') === 'approved' && inv.rsvp === 'no');
    const pending  = allInvites.filter(inv => (inv.approval_status || 'approved') === 'pending');
    const waitlisted = allInvites.filter(inv => inv.approval_status === 'waitlisted');

    let ih = '';

    // "Send Invitations" banner — managers only, when notifications are on and some approved
    // invitee still has no invite on record. Invites are not auto-sent on save anymore.
    if (canManage && NOTIFS_ENABLED) {
        // Count self too — a host who invites themselves should still be able to send
        // (and receive) the invite email for their own event. Invitees with no email/phone
        // can never be reached, so keep them out of the "not sent" count and warn separately.
        const unsent    = approved.filter(inv => !inv.sent && !inv.no_contact);
        const noContact = approved.filter(inv => inv.no_contact);
        if (unsent.length) {
            ih += '<div style="display:flex;align-items:center;gap:.6rem;flex-wrap:wrap;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:.55rem .7rem;margin-bottom:.7rem">'
                + '<span style="flex:1;min-width:0;font-size:.8rem;color:#92400e;font-weight:600">&#9888; Invitations not sent to ' + unsent.length + ' ' + (unsent.length === 1 ? 'person' : 'people') + '</span>'
                + '<button type="button" class="btn-send-invites" data-eid="' + eid + '" style="font-size:.78rem;padding:.3rem .8rem;border-radius:6px;border:0;background:#2563eb;color:#fff;font-weight:600;cursor:pointer">Send Invitations</button>'
                + '</div>';
        }
        if (noContact.length) {
            ih += '<div style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:.5rem .7rem;margin-bottom:.7rem;font-size:.78rem;color:#991b1b;line-height:1.45">'
                + '&#9888; ' + noContact.length + ' ' + (noContact.length === 1 ? 'invitee has' : 'invitees have') + ' no email or phone and can’t be notified. Edit the event to add a contact for them.'
                + '</div>';
        }
    }

    if (approved.length) {
        ih += '<div style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#94a3b8;margin-bottom:.4rem">Invites (' + approved.length + ')</div>';
        ih += '<div style="display:flex;flex-direction:column;gap:.2rem;max-height:8.5rem;overflow-y:auto;padding-right:.25rem">';
        approved.forEach(inv => {
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
            ih += '<span style="flex:1;min-width:0">' + escHtml(inv.username)
                + (inv.no_contact && canManage ? ' <span class="inv-nocontact-tag" title="No email or phone on file — this person can’t be sent an invite">no contact</span>' : '')
                + '</span>';
            // Resend button: only for managers, only when no RSVP yet, only for non-self.
            if (canManage && NOTIFS_ENABLED && !inv.rsvp) {
                const sendLabel = inv.sent ? 'Resend' : 'Send';
                ih += '<button type="button" class="btn-resend-inv" data-eid="' + eid + '" data-username="' + escHtml(inv.username) + '" title="' + sendLabel + ' invite SMS/email" style="font-size:.7rem;padding:.15rem .5rem;border-radius:5px;border:1px solid #cbd5e1;background:#fff;color:#475569;font-weight:600;cursor:pointer">' + sendLabel + '</button>';
            }
            ih += '</div>';
        });
        ih += '</div>';
    }

    // Pending approval section — only managers see it.
    if (canManage && pending.length) {
        ih += '<div style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#d97706;margin-top:.7rem;margin-bottom:.4rem">⏳ Pending Approval (' + pending.length + ')</div>';
        ih += '<div style="display:flex;flex-direction:column;gap:.3rem;max-height:8.5rem;overflow-y:auto;padding-right:.25rem">';
        pending.forEach(inv => {
            ih += '<div style="font-size:.875rem;color:#334155;display:flex;align-items:center;gap:.4rem;flex-wrap:wrap;background:#fefce8;border:1px solid #fde68a;border-radius:6px;padding:.35rem .5rem">';
            ih += '<span style="flex:1;min-width:0">' + escHtml(inv.username);
            ih += '</span>';
            ih += '<button type="button" class="btn-approve-inv" data-eid="' + eid + '" data-username="' + escHtml(inv.username) + '" style="font-size:.75rem;padding:.2rem .55rem;border-radius:5px;border:0;background:#16a34a;color:#fff;font-weight:600;cursor:pointer">Approve</button>';
            ih += '<button type="button" class="btn-deny-inv" data-eid="' + eid + '" data-username="' + escHtml(inv.username) + '" style="font-size:.75rem;padding:.2rem .55rem;border-radius:5px;border:0;background:#dc2626;color:#fff;font-weight:600;cursor:pointer">Deny</button>';
            ih += '</div>';
        });
        ih += '</div>';
    }

    // Waitlisted section
    if (waitlisted.length) {
        ih += '<div style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#1e40af;margin-top:.7rem;margin-bottom:.4rem">Waitlisted (' + waitlisted.length + ')</div>';
        ih += '<div style="display:flex;flex-direction:column;gap:.2rem;max-height:5rem;overflow-y:auto;padding-right:.25rem;opacity:.7">';
        waitlisted.forEach(inv => {
            ih += '<div style="font-size:.82rem;color:#475569;padding:.15rem 0">' + escHtml(inv.username) + '</div>';
        });
        ih += '</div>';
    }

    // Declined section. Managers can flip the RSVP back to yes/maybe; non-managers see a faded list.
    if (declined.length) {
        ih += '<div style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#dc2626;margin-top:.7rem;margin-bottom:.4rem">Declined (' + declined.length + ')</div>';
        ih += '<div style="display:flex;flex-direction:column;gap:.2rem;max-height:6rem;overflow-y:auto;padding-right:.25rem;opacity:.75">';
        declined.forEach(inv => {
            ih += '<div style="font-size:.82rem;color:#475569;display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;padding:.15rem 0">';
            if (canManage) {
                const r = inv.rsvp || '';
                ih += '<select class="inv-rsvp-sel" data-eid="' + eid + '" data-username="' + escHtml(inv.username) + '">'
                    + '<option value=""'      + (r===''      ?' selected':'') + '>--</option>'
                    + '<option value="yes"'   + (r==='yes'   ?' selected':'') + '>Yes</option>'
                    + '<option value="no"'    + (r==='no'    ?' selected':'') + '>No</option>'
                    + (ALLOW_MAYBE ? '<option value="maybe"' + (r==='maybe'?' selected':'') + '>Maybe</option>' : '')
                    + '</select>';
            } else {
                ih += '<span class="rsvp-no" style="min-width:52px;text-align:center">No</span>';
            }
            ih += '<span style="flex:1;min-width:0;text-decoration:line-through;text-decoration-color:#cbd5e1">' + escHtml(inv.username) + '</span>';
            ih += '</div>';
        });
        ih += '</div>';
    }

    // Host messages ("final details"): compose button for managers + read-only history.
    const msgs = eventMessages[eid] || [];
    if ((canManage && NOTIFS_ENABLED) || msgs.length) {
        ih += '<div style="margin-top:.8rem;border-top:1px solid #f1f5f9;padding-top:.6rem">';
        ih += '<div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.45rem">'
            + '<span style="flex:1;font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#94a3b8">Messages from the host</span>';
        if (canManage && NOTIFS_ENABLED) {
            ih += '<button type="button" class="btn-msg-guests" data-eid="' + eid + '" style="font-size:.75rem;padding:.25rem .7rem;border-radius:6px;border:0;background:#16a34a;color:#fff;font-weight:600;cursor:pointer">Message guests</button>';
        }
        ih += '</div>';
        if (msgs.length) {
            ih += '<div style="display:flex;flex-direction:column;gap:.45rem;max-height:15rem;overflow-y:auto;padding-right:.25rem">';
            msgs.forEach(m => {
                const aud = m.audience === 'all' ? 'All guests' : (m.audience === 'yes_maybe' ? 'Yes &amp; Maybe' : 'Going (Yes)');
                const delBtn = (m.can_manage && m.id)
                    ? '<button type="button" class="btn-del-msg" data-eid="' + eid + '" data-mid="' + m.id + '" title="Delete this message" style="background:none;border:0;color:#cbd5e1;cursor:pointer;font-size:1.15rem;line-height:1;padding:0 .15rem">&times;</button>'
                    : '';
                ih += '<div style="border:1px solid #e2e8f0;border-radius:8px;padding:.5rem .6rem;background:#fff">'
                    + '<div style="display:flex;align-items:flex-start;gap:.5rem">'
                    +   '<div style="flex:1;min-width:0;font-weight:600;font-size:.85rem;color:#1e293b">' + escHtml(m.subject) + '</div>'
                    +   delBtn
                    + '</div>'
                    + '<div style="font-size:.7rem;color:#94a3b8;margin:.1rem 0 .4rem">' + escHtml(m.created_at) + (m.can_manage ? ' &middot; ' + aud : '') + '</div>'
                    + '<div style="font-size:.85rem;color:#334155;line-height:1.5">' + m.body_html + '</div>'
                    + '</div>';
            });
            ih += '</div>';
        } else if (canManage) {
            ih += '<div style="font-size:.78rem;color:#94a3b8">No messages sent yet. Use “Message guests” to send the address and final details.</div>';
        }
        ih += '</div>';
    }

    if (ih) {
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
            const scroll = document.getElementById('vScrollBody');
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

    // Delegated listener: Approve / Deny / Resend / Send-all buttons in the invites panel
    vInvDiv.addEventListener('click', function(e) {
        // Bulk "Send Invitations" — sends to every approved invitee not yet notified.
        const sendAllBtn = e.target.closest('.btn-send-invites');
        if (sendAllBtn) {
            const eid    = parseInt(sendAllBtn.dataset.eid);
            const csrfEl = document.getElementById('vRsvpCsrf');
            if (!csrfEl) return;
            sendAllBtn.disabled = true;
            const origText = sendAllBtn.textContent;
            sendAllBtn.textContent = 'Sending…';
            const data = new FormData();
            data.append('csrf_token', csrfEl.value);
            data.append('action',     'send_invites');
            data.append('event_id',   eid);
            fetch('/calendar.php', {method:'POST', body:data, headers:{'X-Requested-With':'XMLHttpRequest'}})
                .then(r => r.json())
                .then(res => {
                    if (!res.ok) {
                        sendAllBtn.disabled = false;
                        sendAllBtn.textContent = origText;
                        alert(res.error || 'Could not send invitations.');
                        return;
                    }
                    showSavedBar(res.sent > 0 ? ('Invitations sent to ' + res.sent) : 'Already sent');
                    pollRsvps(eid); // refresh sent flags → banner clears, buttons flip to Resend
                })
                .catch(() => {
                    sendAllBtn.disabled = false;
                    sendAllBtn.textContent = origText;
                    alert('Network error. Please try again.');
                });
            return;
        }

        // "Message guests" — open the compose popup for this event.
        const msgBtn = e.target.closest('.btn-msg-guests');
        if (msgBtn) {
            openEventMsgModal(parseInt(msgBtn.dataset.eid));
            return;
        }

        // Delete a host message (owner/manager).
        const delMsgBtn = e.target.closest('.btn-del-msg');
        if (delMsgBtn) {
            if (!confirm('Delete this message? Guests will no longer be able to open its link.')) return;
            const eid = parseInt(delMsgBtn.dataset.eid);
            const mid = parseInt(delMsgBtn.dataset.mid);
            const csrfEl = document.getElementById('vRsvpCsrf');
            if (!csrfEl) return;
            delMsgBtn.disabled = true;
            const data = new FormData();
            data.append('csrf_token', csrfEl.value);
            data.append('action',     'delete_event_message');
            data.append('event_id',   eid);
            data.append('message_id', mid);
            fetch('/calendar.php', {method:'POST', body:data, headers:{'X-Requested-With':'XMLHttpRequest'}})
                .then(r => r.json())
                .then(res => {
                    if (!res.ok) { delMsgBtn.disabled = false; alert(res.error || 'Could not delete the message.'); return; }
                    if (eventMessages[eid]) eventMessages[eid] = eventMessages[eid].filter(m => m.id != mid);
                    if (typeof showSavedBar === 'function') showSavedBar('Message deleted');
                    renderInvitesPanel(eid);
                })
                .catch(() => { delMsgBtn.disabled = false; alert('Network error. Please try again.'); });
            return;
        }

        const approveBtn = e.target.closest('.btn-approve-inv');
        const denyBtn    = e.target.closest('.btn-deny-inv');
        const resendBtn  = e.target.closest('.btn-resend-inv');
        const btn        = approveBtn || denyBtn || resendBtn;
        if (!btn) return;
        const eid      = parseInt(btn.dataset.eid);
        const username = btn.dataset.username;
        const csrfEl   = document.getElementById('vRsvpCsrf');
        if (!csrfEl) return;
        btn.disabled = true;
        const data = new FormData();
        data.append('csrf_token',      csrfEl.value);
        data.append('event_id',        eid);
        data.append('target_username', username);

        if (resendBtn) {
            data.append('action', 'resend_invite');
            const originalText = btn.textContent;
            btn.textContent = 'Sending…';
            fetch('/calendar.php', {method:'POST', body:data, headers:{'X-Requested-With':'XMLHttpRequest'}})
                .then(r => r.json())
                .then(res => {
                    if (!res.ok) {
                        btn.disabled = false;
                        btn.textContent = originalText;
                        alert(res.error || 'Could not resend invite.');
                        return;
                    }
                    btn.textContent = 'Sent ✓';
                    btn.style.background = '#dcfce7';
                    btn.style.borderColor = '#86efac';
                    btn.style.color = '#166534';
                    showSavedBar();
                    // Refetch invite state so the "Invitations not sent to N" banner count
                    // recomputes after this individual send (same refresh the bulk path uses).
                    pollRsvps(eid);
                })
                .catch(() => {
                    btn.disabled = false;
                    btn.textContent = originalText;
                    alert('Network error. Please try again.');
                });
            return;
        }

        const decision = approveBtn ? 'approved' : 'denied';
        data.append('action', decision === 'approved' ? 'approve_invite' : 'deny_invite');
        fetch('/calendar.php', {method:'POST', body:data, headers:{'X-Requested-With':'XMLHttpRequest'}})
            .then(r => r.json())
            .then(res => {
                if (!res.ok) { btn.disabled = false; return; }
                const list = eventInvites[eid];
                if (list) {
                    const inv = list.find(i => i.username.toLowerCase() === username.toLowerCase());
                    if (inv) inv.approval_status = decision;
                }
                renderInvitesPanel(eid);
                showSavedBar();
            })
            .catch(() => { btn.disabled = false; });
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
            // Hide signup button regardless (we've made the request).
            document.getElementById('vSignupWrap').style.display = 'none';
            if (res.pending) {
                // Pending: don't show the RSVP form (gated). Show leave (cancel-request) button + waiting message.
                const vLW = document.getElementById('vLeaveWrap');
                if (vLW) { vLW.style.display = ''; document.getElementById('vLeaveBtn').dataset.eid = eid; }
                showSavedBar('Request sent — waiting for host approval');
            } else {
                // Approved (default): swap to RSVP form as before.
                const vRsvpW = document.getElementById('vRsvpWrap');
                if (vRsvpW) {
                    document.getElementById('vRsvpEventId').value = eid;
                    document.getElementById('vRsvpSelect').value  = '';
                    updateRsvpStatusBadge('');
                    vRsvpW.style.display = '';
                }
                showSavedBar('Signed up!');
                const vLW = document.getElementById('vLeaveWrap');
                if (vLW) { vLW.style.display = ''; document.getElementById('vLeaveBtn').dataset.eid = eid; }
            }
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

<?php if ($canEditEvents): ?>
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

// ── Mobile detection for invite tap behavior ────────────────────────────────
const isMobileInvite = window.matchMedia('(max-width: 1024px)').matches;
(function() {
    const hints = document.querySelectorAll('.invite-action-hint');
    if (isMobileInvite) hints.forEach(el => el.textContent = 'tap');
})();

// ── All-users pane ────────────────────────────────────────────────────────────
function buildAllUsersList() {
    const ul = document.getElementById('eAllUsersList');
    ul.innerHTML = '';
    const lgSel = document.getElementById('eLeagueId');
    const leagueSelected = !!(lgSel && parseInt(lgSel.value, 10) > 0);
    const hideWrap = document.getElementById('eHideNonMembersWrap');
    if (hideWrap) hideWrap.style.display = leagueSelected ? 'flex' : 'none';
    if (!leagueSelected) {
        const hideCb = document.getElementById('eHideNonMembers');
        if (hideCb) hideCb.checked = false;
    }
    ALL_USERS.forEach(u => {
        const display = u.display_name || u.username;
        // For pending invitees the synthetic username is a phone number or "pending:NN".
        // Use the human display_name as the saved invite_username so the invited row
        // shows a real name and the saved invite carries the name (not a phone).
        const savedName = u.is_pending ? (u.display_name || u.username || '') : (u.username || '');
        const li = document.createElement('li');
        li.dataset.username = (u.username || '').toLowerCase();
        li.dataset.email    = (u.email    || '').toLowerCase();
        li.dataset.phone    = (u.phone    || '').replace(/\D/g,'');
        li.dataset.display  = (display    || '').toLowerCase();
        li.dataset.uname    = savedName;
        li.dataset.uemail   = u.email     || '';
        li.dataset.uphone   = u.phone     || '';
        li.dataset.member   = u.is_league_member ? '1' : '0';
        li.textContent = display;
        if (u.is_pending) {
            const tag = document.createElement('span');
            tag.textContent = ' (pending)';
            tag.style.cssText = 'color:#92400e;font-size:.75rem;margin-left:.25rem';
            li.appendChild(tag);
        }
        if (leagueSelected) {
            const memTag = document.createElement('span');
            memTag.className = 'inv-mem-tag ' + (u.is_league_member ? 'inv-mem-yes' : 'inv-mem-no');
            memTag.textContent = u.is_league_member ? 'Member' : 'Not a member';
            li.appendChild(memTag);
        }
        li.title = 'Click to select, or double-click to invite';
        li.addEventListener('click', function(e) {
            if (this.classList.contains('dimmed')) return;
            handleListSelect(e, this, 'eAllUsersList');
        });
        // Double-click a name to add them straight to the invited list (same as ›).
        li.addEventListener('dblclick', function(e) {
            if (this.classList.contains('dimmed') || !this.dataset.uname) return;
            e.preventDefault();
            if (window.getSelection) { try { window.getSelection().removeAllRanges(); } catch (err) {} }
            inviteUser(this.dataset.uname, this.dataset.uphone, this.dataset.uemail);
            this.classList.remove('inv-selected');
        });
        ul.appendChild(li);
    });
    // Re-apply the already-invited dimming/checkmarks every time the list is (re)built.
    // This list gets rebuilt asynchronously (refreshUserList, league changes), which would
    // otherwise wipe the state set when an event editor first opens with invitees loaded.
    syncInviteState();
}

// Fetch the scoped contact list for the current league selection and rebuild the pane.
function refreshUserList() {
    var lgSel = document.getElementById('eLeagueId');
    var leagueId = lgSel ? (parseInt(lgSel.value, 10) || 0) : 0;
    fetch('/calendar_contacts_dl.php?league_id=' + leagueId, { credentials: 'same-origin' })
        .then(r => r.json())
        .then(j => {
            if (j && j.ok) {
                ALL_USERS = j.users || [];
                buildAllUsersList();
                var searchEl = document.getElementById('eUserSearch');
                filterAllUsers(searchEl ? searchEl.value : '');
            }
        })
        .catch(() => {});
}

function filterAllUsers(q) {
    const raw    = (q || '').toLowerCase();
    const digits = raw.replace(/\D/g,'');
    const hideCb = document.getElementById('eHideNonMembers');
    const hideNonMembers = !!(hideCb && hideCb.checked);
    document.querySelectorAll('#eAllUsersList li:not(.custom-row)').forEach(li => {
        const textMatch = !raw ||
            (li.dataset.username && li.dataset.username.includes(raw)) ||
            (li.dataset.display  && li.dataset.display.includes(raw))  ||
            (li.dataset.email    && li.dataset.email.includes(raw))    ||
            (digits && li.dataset.phone && li.dataset.phone.includes(digits));
        const memberMatch = !hideNonMembers || li.dataset.member === '1';
        li.style.display = (textMatch && memberMatch) ? '' : 'none';
    });
}

function onHideNonMembersChange() {
    const searchEl = document.getElementById('eUserSearch');
    filterAllUsers(searchEl ? searchEl.value : '');
}

// ── Invited pane ──────────────────────────────────────────────────────────────
function inviteUser(username, phone, email, rsvp, role, approvalStatus) {
    // Skip if already invited
    const existing = Array.from(document.querySelectorAll('#eInvitedList li[data-iname]'))
        .map(li => li.dataset.iname.toLowerCase());
    if (existing.includes(username.toLowerCase())) return;

    const li = document.createElement('li');
    li.dataset.iname    = username;
    li.dataset.iphone   = phone  || '';
    li.dataset.iemail   = email  || '';
    li.dataset.irsvp    = rsvp   || '';
    li.dataset.irole    = role   || 'invitee';
    li.dataset.istatus  = approvalStatus || 'approved';

    // Build content: name + RSVP badge + manager toggle
    const nameSpan = document.createElement('span');
    nameSpan.textContent = username;
    nameSpan.className = 'inv-name-text';
    li.appendChild(nameSpan);

    // Clickable contact indicator: shows an email/phone glyph for whatever is on file (or
    // "NA" when neither). Click opens a small popup to edit this invitee's email + phone.
    const contactCtl = document.createElement('span');
    contactCtl.className = 'inv-contact-ctl inv-col-contact';
    contactCtl.title = 'Click to edit email / phone';
    contactCtl.innerHTML = invContactCtlInner(li.dataset.iemail, li.dataset.iphone);
    contactCtl.addEventListener('click', function(e) { e.stopPropagation(); openContactEdit(li); });
    contactCtl.addEventListener('dblclick', function(e) { e.stopPropagation(); });
    li.appendChild(contactCtl);

    // RSVP status badge — in a fixed-width column so the header lines up. Empty when no reply.
    const rsvpSlot = document.createElement('span');
    rsvpSlot.className = 'inv-col-rsvp';
    var badge = document.createElement('span');
    badge.className = 'inv-rsvp-badge';
    if (rsvp === 'yes')        { badge.textContent = 'Yes';    badge.classList.add('inv-rsvp-yes');    rsvpSlot.appendChild(badge); }
    else if (rsvp === 'no')    { badge.textContent = 'No';     badge.classList.add('inv-rsvp-no');     rsvpSlot.appendChild(badge); }
    else if (rsvp === 'maybe') { badge.textContent = 'Maybe';  badge.classList.add('inv-rsvp-maybe');  rsvpSlot.appendChild(badge); }
    else if (approvalStatus === 'waitlisted') { badge.textContent = 'Waitlist'; badge.classList.add('inv-rsvp-waitlist'); rsvpSlot.appendChild(badge); }
    li.appendChild(rsvpSlot);

    // Manager toggle column (fixed width; toggle shown to admins, the event creator, and —
    // on a brand-new event — the host creating it). The empty column keeps rows aligned.
    const mgrSlot = document.createElement('span');
    mgrSlot.className = 'inv-col-mgr';
    const editingEvId = parseInt(document.getElementById('eId').value) || 0;
    const editingCreatedBy = currentEvent ? currentEvent.created_by : null;
    const canGrantManager = IS_ADMIN || (!editingEvId && CAN_CREATE_EVENTS)
                            || (CURRENT_USER_ID && editingCreatedBy == CURRENT_USER_ID);
    if (canGrantManager) {
        const tog = document.createElement('label');
        tog.className = 'mgr-toggle';
        tog.title = 'Grant manager access';
        tog.innerHTML = '<input type="checkbox" class="pk-toggle-input mgr-toggle-cb"' + (li.dataset.irole === 'manager' ? ' checked' : '') + '><span class="pk-toggle-slider pk-toggle-sm"></span><span class="mgr-label">Mgr</span>';
        tog.querySelector('.mgr-toggle-cb').addEventListener('change', function(e) {
            e.stopPropagation();
            li.dataset.irole = this.checked ? 'manager' : 'invitee';
        });
        tog.addEventListener('click', function(e) { e.stopPropagation(); });
        tog.addEventListener('dblclick', function(e) { e.stopPropagation(); });
        mgrSlot.appendChild(tog);
    }
    li.appendChild(mgrSlot);

    li.title = 'Click to select, or double-click to remove';
    li.addEventListener('click', function(e) {
        if (e.target.closest('.mgr-toggle')) return;
        handleListSelect(e, this, 'eInvitedList');
    });
    // Double-click a name to remove them from the invited list (same as ‹).
    li.addEventListener('dblclick', function(e) {
        if (e.target.closest('.mgr-toggle')) return;
        e.preventDefault();
        if (window.getSelection) { try { window.getSelection().removeAllRanges(); } catch (err) {} }
        removeInvite(this.dataset.iname);
    });

    // Drag-and-drop for priority ordering (poker events)
    li.draggable = true;
    li.addEventListener('dragstart', function(e) {
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', '');
        this.classList.add('inv-dragging');
    });
    li.addEventListener('dragend', function() {
        this.classList.remove('inv-dragging');
        updateDividerLine();
    });

    document.getElementById('eInvitedList').appendChild(li);
    syncInviteState();
    updateDividerLine();
}

// ── Per-invitee contact icon + inline editor ─────────────────────────────────
// Builds the little email/phone/NA indicator shown on each invited row.
function invContactCtlInner(email, phone) {
    var hasE = !!(email && String(email).trim());
    var hasP = !!(phone && String(phone).trim());
    if (!hasE && !hasP) return '<span class="inv-na">NA</span>';
    var s = '';
    if (hasE) s += '<span class="inv-ci" title="Has email">&#9993;</span>';
    if (hasP) s += '<span class="inv-ci" title="Has phone">&#9742;</span>';
    return s;
}
var _contactEditLi = null;
function openContactEdit(li) {
    _contactEditLi = li;
    document.getElementById('invContactName').textContent = li.dataset.iname || 'invitee';
    document.getElementById('invContactEmail').value = li.dataset.iemail || '';
    document.getElementById('invContactPhone').value = li.dataset.iphone || '';
    document.getElementById('invContactModal').classList.add('open');
    setTimeout(function(){ document.getElementById('invContactEmail').focus(); }, 30);
}
function closeContactEdit() {
    document.getElementById('invContactModal').classList.remove('open');
    _contactEditLi = null;
}
function saveContactEdit() {
    if (!_contactEditLi) { closeContactEdit(); return; }
    _contactEditLi.dataset.iemail = document.getElementById('invContactEmail').value.trim();
    _contactEditLi.dataset.iphone = document.getElementById('invContactPhone').value.trim();
    var ctl = _contactEditLi.querySelector('.inv-contact-ctl');
    if (ctl) ctl.innerHTML = invContactCtlInner(_contactEditLi.dataset.iemail, _contactEditLi.dataset.iphone);
    closeContactEdit();
}

function removeInvite(username) {
    const li = Array.from(document.querySelectorAll('#eInvitedList li[data-iname]'))
        .find(l => l.dataset.iname.toLowerCase() === username.toLowerCase());
    if (li) li.remove();
    syncInviteState();
    updateDividerLine();
}

function addBlankInviteRow() {
    const ul = document.getElementById('eInvitedList');
    const li = document.createElement('li');
    li.className = 'custom-row';
    li.innerHTML = '<div class="custom-row-inner">' +
        '<input type="text" class="cr-name"    placeholder="Name *">' +
        '<input type="text" class="cr-contact" placeholder="Email or phone" autocomplete="off">' +
        '<button type="button" class="cr-remove" onclick="this.closest(\'li\').remove()">&times;</button>' +
        '</div>';
    ul.appendChild(li);
    li.querySelector('.cr-name').focus();
}

function syncInviteState() {
    const invited = Array.from(document.querySelectorAll('#eInvitedList li[data-iname]'))
        .map(li => li.dataset.iname.toLowerCase());
    document.querySelectorAll('#eAllUsersList li').forEach(li => {
        const isDimmed = invited.includes((li.dataset.uname || '').toLowerCase());
        li.classList.toggle('dimmed', isDimmed);
        li.title = isDimmed ? 'Already invited' : 'Double-click to invite';
    });
}

// ── Multi-select + arrow button handlers ─────────────────────────────────────
var _lastClickedAll = null;
var _lastClickedInv = null;

function handleListSelect(e, li, listId) {
    var ul = document.getElementById(listId);
    var items = Array.from(ul.querySelectorAll('li:not(.dimmed):not(.custom-row):not(.inv-capacity-divider):not(.inv-declined-divider):not(.inv-declined-item)'));

    if (e.shiftKey && (listId === 'eAllUsersList' ? _lastClickedAll : _lastClickedInv)) {
        // Range select
        var last = listId === 'eAllUsersList' ? _lastClickedAll : _lastClickedInv;
        var startIdx = items.indexOf(last);
        var endIdx = items.indexOf(li);
        if (startIdx > -1 && endIdx > -1) {
            var lo = Math.min(startIdx, endIdx), hi = Math.max(startIdx, endIdx);
            for (var i = lo; i <= hi; i++) items[i].classList.add('inv-selected');
        }
    } else if (e.ctrlKey || e.metaKey) {
        // Toggle single
        li.classList.toggle('inv-selected');
    } else {
        // Clear others, select this one
        items.forEach(function(el) { el.classList.remove('inv-selected'); });
        li.classList.add('inv-selected');
    }
    if (listId === 'eAllUsersList') _lastClickedAll = li;
    else _lastClickedInv = li;
}

function moveRight() {
    var selected = document.querySelectorAll('#eAllUsersList li.inv-selected:not(.dimmed)');
    selected.forEach(function(li) {
        inviteUser(li.dataset.uname, li.dataset.uphone, li.dataset.uemail);
        li.classList.remove('inv-selected');
    });
}
function moveAllRight() {
    var visible = document.querySelectorAll('#eAllUsersList li:not(.dimmed):not([style*="display: none"]):not([style*="display:none"])');
    visible.forEach(function(li) {
        if (li.dataset.uname) inviteUser(li.dataset.uname, li.dataset.uphone, li.dataset.uemail);
    });
}
function moveLeft() {
    var selected = Array.from(document.querySelectorAll('#eInvitedList li.inv-selected[data-iname]'));
    selected.forEach(function(li) {
        removeInvite(li.dataset.iname);
    });
}
function moveAllLeft() {
    var all = Array.from(document.querySelectorAll('#eInvitedList li[data-iname]'));
    all.forEach(function(li) {
        removeInvite(li.dataset.iname);
    });
}

// ── Drag-and-drop reorder + capacity divider ────────────────────────────────
(function() {
    var ul = document.getElementById('eInvitedList');
    if (!ul) return;
    ul.addEventListener('dragover', function(e) {
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        var dragging = ul.querySelector('.inv-dragging');
        if (!dragging) return;
        var afterEl = getDragAfterElement(ul, e.clientY);
        if (afterEl) ul.insertBefore(dragging, afterEl);
        else ul.appendChild(dragging);
    });
    function getDragAfterElement(container, y) {
        var items = Array.from(container.querySelectorAll('li[data-iname]:not(.inv-dragging)'));
        var closest = null, closestOffset = Number.NEGATIVE_INFINITY;
        items.forEach(function(child) {
            var box = child.getBoundingClientRect();
            var offset = y - box.top - box.height / 2;
            if (offset < 0 && offset > closestOffset) { closestOffset = offset; closest = child; }
        });
        return closest;
    }
})();

function updateDividerLine() {
    var ul = document.getElementById('eInvitedList');
    if (!ul) return;

    // Remove old dividers only (not the LI items themselves)
    ul.querySelectorAll('.inv-capacity-divider, .inv-declined-divider').forEach(function(el) { el.remove(); });
    // Reset declined styling on all items so we can re-sort
    ul.querySelectorAll('.inv-declined-item').forEach(function(li) {
        li.classList.remove('inv-declined-item');
        li.draggable = true;
        li.style.display = '';
    });

    var cap = getPokerCapacity();

    // Separate active (non-declined) from declined
    var allItems = Array.from(ul.querySelectorAll('li[data-iname]'));
    var active = [];
    var declined = [];
    allItems.forEach(function(li) {
        if (li.dataset.irsvp === 'no') {
            declined.push(li);
        } else {
            active.push(li);
        }
    });

    // Re-append active items first (preserves their order), then declined
    active.forEach(function(li) { ul.appendChild(li); });

    // Insert capacity divider among active items
    if (cap > 0 && active.length > cap) {
        var divider = document.createElement('li');
        divider.className = 'inv-capacity-divider';
        divider.textContent = '--- Seat cutoff (' + cap + ' seats) --- waitlist below ---';
        divider.draggable = false;
        ul.insertBefore(divider, active[cap]);
    }

    // Add declined section at the bottom
    if (declined.length > 0) {
        var decDivider = document.createElement('li');
        decDivider.className = 'inv-declined-divider';
        decDivider.innerHTML = '&blacktriangledown; Declined (' + declined.length + ')';
        decDivider.draggable = false;
        decDivider.onclick = function() { toggleDeclined(); };
        ul.appendChild(decDivider);

        declined.forEach(function(li) {
            li.classList.add('inv-declined-item');
            li.draggable = false;
            ul.appendChild(li);
        });
    }
}

function toggleDeclined() {
    var items = document.querySelectorAll('#eInvitedList .inv-declined-item');
    var allHidden = items.length > 0 && items[0].style.display === 'none';
    items.forEach(function(li) { li.style.display = allHidden ? '' : 'none'; });
    var divider = document.querySelector('.inv-declined-divider');
    if (divider) {
        var count = items.length;
        divider.innerHTML = (allHidden ? '&blacktriangledown;' : '&blacktriangleright;') + ' Declined (' + count + ')';
    }
}

// Sync hidden inputs from invited pane before submit
// ── Time picker helpers ──────────────────────────────────────────────────────
function setTimePicker(hhmm) {
    if (!hhmm) {
        const now = new Date();
        hhmm = String(now.getHours()).padStart(2,'0') + ':' + String(now.getMinutes()).padStart(2,'0');
    }
    document.getElementById('eTimeNative').value = hhmm;
}
function getTimePicker() {
    return document.getElementById('eTimeNative').value || '';
}

// Progress overlay shown during the editor's full-page POST. Because the form navigates
// (POST → redirect), the overlay stays up until the new page loads, then vanishes with it.
// The bar eases toward ~92% to convey motion; real "100%" is the page reload itself.
function showSaveSendOverlay(sending) {
    const ov = document.getElementById('saveSendOverlay');
    if (!ov) return;
    document.getElementById('saveSendTitle').textContent = sending ? 'Saving & sending invitations…' : 'Saving event…';
    document.getElementById('saveSendMsg').textContent   = sending ? 'Saving the event and sending invitation emails.' : 'Saving your changes.';
    const bar = document.getElementById('saveSendBar');
    let pct = 8; bar.style.width = pct + '%';
    ov.style.display = 'flex';
    const iv = setInterval(function() {
        pct += Math.max(1, (92 - pct) * 0.12);
        if (pct >= 92) { pct = 92; clearInterval(iv); }
        bar.style.width = pct + '%';
    }, 180);
}

document.getElementById('editForm').addEventListener('submit', function(e) {
    // Show the saving/sending progress overlay (this fires only after HTML5 validation passes).
    const _sas = document.getElementById('eSendAfterSave');
    showSaveSendOverlay(!!(_sas && _sas.value === '1'));

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
    // Regular invited users (order in DOM = priority order)
    var sortIdx = 0;
    document.querySelectorAll('#eInvitedList li[data-iname]').forEach(li => {
        sortIdx++;
        addHidden('invite_username[]',   li.dataset.iname);
        addHidden('invite_phone[]',      li.dataset.iphone);
        addHidden('invite_email[]',      li.dataset.iemail);
        addHidden('invite_rsvp[]',       li.dataset.irsvp);
        addHidden('invite_role[]',       li.dataset.irole || 'invitee');
        addHidden('invite_sort_order[]', sortIdx);
    });
    // Custom rows — host-typed invitees (not registered users). Single contact field:
    // auto-detect email (contains '@') vs phone (everything else), then route to the
    // appropriate POST slot so the existing backend keeps working.
    document.querySelectorAll('#eInvitedList li.custom-row').forEach(li => {
        const uname   = li.querySelector('.cr-name').value.trim();
        const contact = (li.querySelector('.cr-contact') || {value:''}).value.trim();
        if (!uname) return;
        let email = '', phone = '';
        if (contact) {
            if (contact.indexOf('@') !== -1) email = contact;
            else phone = contact;
        }
        sortIdx++;
        addHidden('invite_username[]',   uname);
        addHidden('invite_phone[]',      phone);
        addHidden('invite_email[]',      email);
        addHidden('invite_rsvp[]',       '');
        addHidden('invite_role[]',       'invitee');
        addHidden('invite_sort_order[]', sortIdx);
    });

    // Hold the actual navigation back a beat so the progress bar is visible — the save
    // itself is near-instant and a flash of overlay reads as a glitch. form.submit()
    // (called below) does NOT re-fire this 'submit' handler, so there's no loop.
    e.preventDefault();
    const _form = this;
    setTimeout(function() { _form.submit(); }, 1300);
});

function openEditModal(ev) {
    currentEvent = ev;
    closeView();
    document.getElementById('editModalTitle').textContent = ev ? 'Edit Event' : 'Add Event';
    document.getElementById('eAction').value    = ev ? 'edit' : 'add';
    document.getElementById('eId').value        = ev ? ev.id : '';
    var _sas = document.getElementById('eSendAfterSave'); if (_sas) _sas.value = '';
    document.getElementById('eOccDate').value   = '';
    document.getElementById('eTitle').value     = ev ? ev.title : '';
    document.getElementById('eStartDate').value = ev ? (ev.start_date_input || ev.start_date) : new Date().toLocaleDateString('en-CA');
    setTimePicker(ev ? (ev.start_time_input || ev.start_time || '') : '');
    document.getElementById('eDesc').value      = ev ? (ev.description || '') : '';
    // Show description section if event has one; collapse for new events
    var hasDesc = ev && ev.description && ev.description.trim() !== '';
    document.getElementById('eDescWrap').style.display = hasDesc ? '' : 'none';
    document.getElementById('eDescToggle').textContent = hasDesc ? '- Hide description' : '+ Description';
    document.getElementById('eIsPoker').checked = ev ? !!parseInt(ev.is_poker) : <?= ((int)($current['last_poker_default'] ?? 1)) ? 'true' : 'false' ?>;
    document.getElementById('eRequiresApproval').checked = ev ? !!parseInt(ev.requires_approval) : false;
    // Pre-fill poker session fields
    var ps = ev ? (eventPoker[ev.id] || null) : null;
    document.getElementById('ePokerGameType').value = ps ? ps.game_type : 'tournament';
    document.getElementById('ePokerBuyin').value    = ps ? Math.round(parseInt(ps.buyin_amount,10)/100) : '20';
    document.getElementById('ePokerTables').value   = ps ? ps.num_tables : '1';
    document.getElementById('ePokerSeats').value    = ps ? ps.seats_per_table : '8';
    document.getElementById('eRsvpDeadline').value  = (ev && ev.rsvp_deadline_hours) ? String(ev.rsvp_deadline_hours) : '';
    document.getElementById('eWaitlistEnabled').checked = ev ? !!(parseInt(ev.waitlist_enabled) || ev.waitlist_enabled === null) : false;
    togglePokerFields();

    // Reminder config: on for new events; for edits, respect the stored toggle.
    var remEnabled = ev ? (parseInt(ev.reminders_enabled ?? 1) === 1) : true;
    document.getElementById('eRemindersEnabled').checked = remEnabled;
    if (ev && ev.reminder_offsets) {
        try {
            var parsed = JSON.parse(ev.reminder_offsets);
            if (Array.isArray(parsed)) applyReminderOffsets(parsed);
        } catch (e) { /* leave defaults */ }
    }
    toggleReminderFields();
    // Flag for onLeagueChange so it knows this is a fresh-event open (not an existing session)
    _isNewEventOpen = !ev;
    // League + visibility
    var lgSel  = document.getElementById('eLeagueId');
    var visSel = document.getElementById('eVisibility');
    if (lgSel && visSel) {
        lgSel.value  = (ev && ev.league_id)  ? String(ev.league_id)  : '0';
        visSel.value = (ev && ev.visibility) ? ev.visibility         : 'invitees_only';
        onLeagueChange();
        if (ev && ev.visibility) visSel.value = ev.visibility;
    }
    document.getElementById('eUserSearch').value = '';
    document.getElementById('eSubmitBtn').textContent = ev ? 'Save Changes' : 'Add Event';

    // Pre-fill duration from start_time/end_time diff (use viewer-tz inputs so duration
    // is computed against the same wall-clock values shown in the form).
    const dur = document.getElementById('eDuration');
    const _st_in = ev ? (ev.start_time_input || ev.start_time) : '';
    const _et_in = ev ? (ev.end_time_input   || ev.end_time)   : '';
    if (ev && _st_in && _et_in) {
        const [sh, sm] = _st_in.split(':').map(Number);
        const [eh, em] = _et_in.split(':').map(Number);
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
            inviteUser(inv.username, inv.phone || '', inv.email || '', inv.rsvp || '', inv.event_role || 'invitee', inv.approval_status || 'approved'));
    }
    syncInviteState();
    filterAllUsers('');
    updateDividerLine();
    // Fetch the scoped contact list for the current league selection.
    refreshUserList();

    document.getElementById('editModal').classList.add('open');
    document.getElementById('eTitle').focus();
    <?php if ($isAdmin): ?>
    var regenWrap = document.getElementById('eRegenWalkinWrap');
    if (regenWrap) regenWrap.style.display = ev ? '' : 'none';
    <?php endif; ?>
}
var _editFromView = false;
function editFromView() {
    _editFromView = true;
    openEditModal(currentEvent);
}
function closeEdit() {
    document.getElementById('editModal').classList.remove('open');
    if (_editFromView && currentEvent) {
        _editFromView = false;
        viewEvent(currentEvent);
    } else {
        _editFromView = false;
    }
}

function togglePokerFields() {
    var show = document.getElementById('eIsPoker').checked;
    document.getElementById('ePokerFields').style.display = show ? '' : 'none';
    document.getElementById('eWaitlistLabel').style.display = show ? '' : 'none';
    if (show) updateCapacityLine();
    else updateDividerLine(); // clear divider when poker is off
}

function toggleReminderFields() {
    var el = document.getElementById('eRemindersEnabled');
    var bar = document.getElementById('eReminderFields');
    if (!el || !bar) return;
    bar.style.display = el.checked ? '' : 'none';
}

// Apply a list of offset values (array of ints) to the reminder checkboxes.
// Passing null resets to the site-default set (whatever was pre-checked server-side).
function applyReminderOffsets(offsets) {
    var boxes = document.querySelectorAll('.eReminderPreset');
    if (offsets === null) {
        // Reset to site default: re-read the pre-checked state baked into the DOM by PHP.
        // We don't have the site default in JS, so just leave boxes as-is on first render.
        return;
    }
    var set = {};
    offsets.forEach(function(o) { set[parseInt(o,10)] = true; });
    boxes.forEach(function(b) { b.checked = !!set[parseInt(b.value,10)]; });
}
function toggleDesc() {
    var wrap = document.getElementById('eDescWrap');
    var tog  = document.getElementById('eDescToggle');
    if (wrap.style.display === 'none') {
        wrap.style.display = '';
        tog.textContent = '- Hide description';
        document.getElementById('eDesc').focus();
    } else {
        wrap.style.display = 'none';
        tog.textContent = '+ Description';
    }
}
function updateCapacityLine() {
    var tables = parseInt(document.getElementById('ePokerTables').value, 10) || 1;
    var seats  = parseInt(document.getElementById('ePokerSeats').value, 10) || 8;
    var cap    = tables * seats;
    document.getElementById('eCapacityHint').textContent = 'Capacity: ' + cap + ' seat' + (cap !== 1 ? 's' : '');
    if (typeof updateDividerLine === 'function') updateDividerLine();
}
function getPokerCapacity() {
    if (!document.getElementById('eIsPoker').checked) return 0;
    if (!document.getElementById('eWaitlistEnabled').checked) return 0;
    var tables = parseInt(document.getElementById('ePokerTables').value, 10) || 1;
    var seats  = parseInt(document.getElementById('ePokerSeats').value, 10) || 8;
    return tables * seats;
}

function onLeagueChange() {
    var lgSel  = document.getElementById('eLeagueId');
    var visSel = document.getElementById('eVisibility');
    var lgOpt  = document.getElementById('eVisLeagueOpt');
    if (!lgSel || !visSel || !lgOpt) return;
    var hasLeague = lgSel.value && lgSel.value !== '0';
    lgOpt.disabled = !hasLeague;
    if (hasLeague) {
        // If a league is picked, default visibility to league members only (matches the league's default)
        var opt = lgSel.options[lgSel.selectedIndex];
        var defVis = opt ? (opt.getAttribute('data-default-visibility') || 'league') : 'league';
        visSel.value = defVis;
    } else {
        // No league selected — fall back to invitees_only if the current selection requires a league
        if (visSel.value === 'league') visSel.value = 'invitees_only';
    }
    // Scope the invite picker to the newly-selected league (or personal network when 0).
    if (typeof refreshUserList === 'function') refreshUserList();
    // Re-fetch remembered poker defaults scoped to the new league (new events only).
    if (_isNewEventOpen) loadPokerDefaultsIntoEditor();
}

var _isNewEventOpen = false;

// Fetch the caller's last-used poker session defaults (scoped to the currently-selected league)
// and populate the event-editor's poker fields. Only used when creating a NEW event.
function loadPokerDefaultsIntoEditor() {
    var lgSel = document.getElementById('eLeagueId');
    var leagueId = (lgSel && lgSel.value && lgSel.value !== '0') ? parseInt(lgSel.value, 10) : 0;
    var qs = leagueId ? '?league_id=' + leagueId : '';
    fetch('/checkin_dl.php?action=get_session_defaults' + qs)
        .then(function(r) { return r.json(); })
        .then(function(j) {
            if (!j.ok || !j.defaults) return;
            var d = j.defaults;
            var gt = document.getElementById('ePokerGameType'); if (gt) gt.value = d.game_type || 'tournament';
            var by = document.getElementById('ePokerBuyin');    if (by) by.value = Math.round((d.buyin_amount || 2000) / 100);
            var tb = document.getElementById('ePokerTables');   if (tb) tb.value = d.num_tables || 1;
            var st = document.getElementById('ePokerSeats');    if (st) st.value = d.seats_per_table || 8;
            if (typeof updateCapacityLine === 'function') updateCapacityLine();
        });
}

buildAllUsersList();
<?php endif; ?>

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') { closeView(); <?php if ($canEditEvents): ?>closeEdit();<?php endif; ?> }
});

function fmt12(t) {
    if (!t) return '';
    const [h, m] = t.split(':').map(Number);
    const ampm = h >= 12 ? 'pm' : 'am';
    return ((h % 12) || 12) + ':' + String(m).padStart(2, '0') + ampm;
}

// ── Auto-open event from landing page link ────────────────────────────────────
<?php if ($autoOpenEvent): ?>
<?php $__autoEdit = !empty($_GET['edit']) && in_array((int)($_GET['edit'] ?? 0), [1, (int)$autoOpenEvent['id']], true); ?>
<?php if ($__autoEdit): ?>
openEditModal(<?= json_encode($autoOpenEvent, JSON_HEX_TAG) ?>);
<?php else: ?>
viewEvent(<?= json_encode($autoOpenEvent, JSON_HEX_TAG) ?>);
<?php endif; ?>
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
        chip.title = (ev.league_name ? ev.league_name + ' \u2014 ' : '') + ev.title;
        chip.addEventListener('click', () => viewEvent(ev));

        const timeStr = (ev.start_time_display || fmt12(ev.start_time)) + (ev.end_time ? '\u2013' + (ev.end_time_display || fmt12(ev.end_time)) : '');
        let _lgTag = '';
        if (ev.league_name) {
            const _words = String(ev.league_name).trim().split(/\s+/);
            _lgTag = (_words[0] || '').substring(0, 3).toUpperCase();
            if (_words[1]) _lgTag += _words[1].substring(0, 2).toUpperCase();
        }
        chip.innerHTML =
            (_lgTag ? '<span class="ev-league-tag" title="' + escHtml(ev.league_name) + '">' + escHtml(_lgTag) + '</span>' : '')
            + '<span class="week-event-title">' + escHtml(ev.title) + '</span>'
            + (heightPx >= 32 ? '<span class="week-event-time">' + escHtml(timeStr) + '</span>' : '');

        if (IS_ADMIN || (CAN_CREATE_EVENTS && CURRENT_USER_ID && ev.created_by == CURRENT_USER_ID) || MANAGED_EVENT_IDS.includes(ev.id)) {
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
    // Use the qrcode library's own image output. The previous hand-rolled canvas draw
    // rendered as a solid black square in some browsers, so render to an <img> instead.
    var img = document.createElement('img');
    img.src = qr.createDataURL(8, 4);
    img.width = size; img.height = size;
    img.style.imageRendering = 'pixelated';
    img.alt = 'QR code';
    return img;
}

function openWalkinQR() {
    var ev = currentEvent;
    if (!ev) return;
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

function openWalkinSeparate() {
    var ev = currentEvent;
    if (!ev) return;
    window.open('/walkin_display.php?event_id=' + ev.id, '_blank');
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
        setTimeout(function() { btn.textContent = 'Regenerate walk-up link'; btn.disabled = false; }, 2500);
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
    .then(function(j) { if (j.ok && j.walkin_token && typeof callback === 'function') callback(j.walkin_token); });
}
<?php endif; ?>

// Auto-open the Add Event modal when arriving with ?new=1 (e.g. from /my_events.php).
(function() {
    try {
        var p = new URLSearchParams(window.location.search);
        if (p.get('new') === '1' && typeof openAddModal === 'function') {
            document.addEventListener('DOMContentLoaded', function() { openAddModal(''); });
        }
    } catch (e) {}
})();
</script>

<?php if ($current): ?>
<!-- Compose "final details" message to going guests (host/manager only) -->
<div class="modal-overlay" id="eventMsgModal" onclick="if(event.target===this)closeEventMsgModal()">
    <div class="modal" style="max-width:640px;display:flex;flex-direction:column;max-height:92vh">
        <div class="modal-header" style="display:flex;align-items:center;gap:.6rem;border-bottom:1px solid #e2e8f0;padding-bottom:.6rem;margin-bottom:.75rem">
            <h2 style="margin:0;flex:1;font-size:1.15rem">Message going guests</h2>
            <a href="javascript:void(0)" onclick="closeEventMsgModal()" aria-label="Close" style="font-size:1.5rem;line-height:1;color:#94a3b8;text-decoration:none">&times;</a>
        </div>
        <p class="subtitle" style="margin-top:0">Send a message to your guests. They'll get it by their preferred channel; text recipients get a link to read it.</p>
        <input type="hidden" id="emEventId" value="">
        <div class="form-group">
            <label for="emSubject">Subject</label>
            <input type="text" id="emSubject" maxlength="150" placeholder="Enter a subject">
        </div>
        <div class="form-group">
            <label for="emAudience">Send to</label>
            <select id="emAudience" class="form-select" style="width:100%;padding:.5rem .75rem;border:1.5px solid #e2e8f0;border-radius:8px;background:#fff">
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
            <button type="button" class="btn" onclick="closeEventMsgModal()">Cancel</button>
            <button type="button" class="btn btn-primary" id="emSendBtn" onclick="sendEventMessage()">Send message</button>
        </div>
    </div>
</div>
<script src="/vendor/jodit/jodit.min.js"></script>
<script>
let _emEditor = null;
function _emEnsureEditor() {
    if (_emEditor || typeof Jodit === 'undefined') return;
    _emEditor = Jodit.make('#emBody', {
        height: 280,
        toolbarAdaptive: false,
        buttons: ['bold','italic','underline','|','ul','ol','|','link','|','paragraph','align','|','undo','redo'],
        uploader: { insertImageAsBase64URI: true },
        placeholder: 'Address, parking, what to bring, etc.'
    });
}
function openEventMsgModal(eid) {
    document.getElementById('emEventId').value = eid;
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
    const eid     = parseInt(document.getElementById('emEventId').value);
    const subject = document.getElementById('emSubject').value.trim();
    const audience= document.getElementById('emAudience').value;
    const body    = _emEditor ? _emEditor.value : document.getElementById('emBody').value;
    const csrfEl  = document.getElementById('vRsvpCsrf');
    if (!subject) { alert('Please enter a subject.'); return; }
    if (!body || !body.replace(/<[^>]*>/g, '').trim()) { alert('Please write a message.'); return; }
    if (!csrfEl) { alert('Session error, please reload.'); return; }
    const btn = document.getElementById('emSendBtn');
    btn.disabled = true; const orig = btn.textContent; btn.textContent = 'Sending…';
    const data = new FormData();
    data.append('csrf_token', csrfEl.value);
    data.append('action',     'send_event_message');
    data.append('event_id',   eid);
    data.append('subject',    subject);
    data.append('audience',   audience);
    data.append('body',       body);
    fetch('/calendar.php', {method:'POST', body:data, headers:{'X-Requested-With':'XMLHttpRequest'}})
        .then(async r => {
            const txt = await r.text();
            let res = null; try { res = JSON.parse(txt); } catch (e) {}
            btn.disabled = false; btn.textContent = orig;
            if (!res) { alert('Unexpected response (HTTP ' + r.status + '). Please reload the page and try again.'); return; }
            if (!res.ok) { alert(res.error || 'Could not send the message.'); return; }
            // Success — the message is already sent server-side. Everything below is
            // best-effort UI; nothing here may surface as a failure.
            try { closeEventMsgModal(); } catch (e) {}
            try {
                if (typeof showSavedBar === 'function') showSavedBar(res.sent > 0 ? ('Message sent to ' + res.sent + ' guest(s)') : 'Sent (no matching guests)');
                else alert(res.sent > 0 ? ('Message sent to ' + res.sent + ' guest(s).') : 'Message sent.');
            } catch (e) {}
            try {
                if (!Array.isArray(eventMessages[eid])) eventMessages[eid] = [];
                eventMessages[eid].push({
                    id: res.msg_id || 0, subject: subject, body_html: body,
                    audience: audience, created_at: 'Just now', author: null, can_manage: true
                });
                if (typeof renderInvitesPanel === 'function') renderInvitesPanel(eid);
            } catch (e) { /* history refreshes next time the event is opened */ }
        })
        .catch(err => {
            btn.disabled = false; btn.textContent = orig;
            alert('Could not reach the server: ' + (err && err.message ? err.message : err));
        });
}
</script>
<?php endif; ?>

</body>
</html>
