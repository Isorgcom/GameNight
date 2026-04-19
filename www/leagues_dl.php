<?php
require_once __DIR__ . '/auth.php';
header('Content-Type: application/json');

$current = require_login();
$db      = get_db();
$uid     = (int)$current['id'];
$isAdmin = ($current['role'] ?? '') === 'admin';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}
if (!csrf_verify()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

$action = $_POST['action'] ?? '';

function fail(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

function ok(array $extra = []): void {
    echo json_encode(array_merge(['ok' => true], $extra));
    exit;
}

function league_role_or_fail(PDO $db, int $league_id, int $user_id, array $allowed, bool $isAdmin): string {
    if ($isAdmin) return 'admin';
    $role = league_role($league_id, $user_id);
    if ($role === null || !in_array($role, $allowed, true)) {
        fail('Not allowed', 403);
    }
    return $role;
}

function notify_user(PDO $db, int $user_id, string $subject, string $smsBody, string $htmlBody): void {
    $stmt = $db->prepare('SELECT username, email, phone, preferred_contact FROM users WHERE id = ?');
    $stmt->execute([$user_id]);
    $u = $stmt->fetch();
    if (!$u) return;
    send_notification(
        $u['username'] ?? '',
        $u['email'] ?? '',
        $u['phone'] ?? '',
        $u['preferred_contact'] ?? 'email',
        $subject, $smsBody, $htmlBody
    );
}

function generate_invite_code(): string {
    return substr(bin2hex(random_bytes(6)), 0, 10);
}

switch ($action) {

case 'create_league': {
    $name  = trim($_POST['name']  ?? '');
    $desc  = trim($_POST['description'] ?? '');
    // League events are always league-scoped; public events are not allowed via leagues.
    $dv    = 'league';
    $mode  = in_array($_POST['approval_mode']      ?? '', ['manual', 'auto'],    true) ? $_POST['approval_mode']      : 'manual';
    $hidden = !empty($_POST['is_hidden']) ? 1 : 0;
    if ($name === '') fail('Name required');
    if (strlen($name) > 120) fail('Name too long');

    $db->beginTransaction();
    try {
        $ins = $db->prepare(
            'INSERT INTO leagues (name, description, owner_id, default_visibility, approval_mode, is_hidden, invite_code)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $ins->execute([$name, $desc, $uid, $dv, $mode, $hidden, generate_invite_code()]);
        $league_id = (int)$db->lastInsertId();
        $db->prepare('INSERT INTO league_members (league_id, user_id, role) VALUES (?, ?, ?)')
            ->execute([$league_id, $uid, 'owner']);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        fail('Failed to create league', 500);
    }
    ok(['league_id' => $league_id]);
}

case 'update_league': {
    $league_id = (int)($_POST['league_id'] ?? 0);
    league_role_or_fail($db, $league_id, $uid, ['owner'], $isAdmin);
    $name = trim($_POST['name'] ?? '');
    $desc = trim($_POST['description'] ?? '');
    $dv   = 'league';
    $mode = in_array($_POST['approval_mode']      ?? '', ['manual', 'auto'],    true) ? $_POST['approval_mode']      : 'manual';
    $hidden = !empty($_POST['is_hidden']) ? 1 : 0;
    if ($name === '') fail('Name required');

    $db->prepare(
        'UPDATE leagues SET name = ?, description = ?, default_visibility = ?, approval_mode = ?, is_hidden = ? WHERE id = ?'
    )->execute([$name, $desc, $dv, $mode, $hidden, $league_id]);
    ok();
}

case 'delete_league_preview': {
    // Returns a summary of what a delete would destroy. Does not mutate anything.
    $league_id = (int)($_POST['league_id'] ?? 0);
    league_role_or_fail($db, $league_id, $uid, ['owner'], $isAdmin);

    $L = $db->prepare('SELECT name FROM leagues WHERE id = ?');
    $L->execute([$league_id]);
    $name = (string)$L->fetchColumn();

    $members = (int)$db->query("SELECT COUNT(*) FROM league_members WHERE league_id = " . $league_id)->fetchColumn();
    $requests = (int)$db->query("SELECT COUNT(*) FROM league_join_requests WHERE league_id = " . $league_id)->fetchColumn();

    $evRows = $db->prepare(
        "SELECT e.id, e.title, e.start_date, e.is_poker,
                (SELECT COUNT(*) FROM poker_players pp
                  JOIN poker_sessions ps ON ps.id = pp.session_id
                  WHERE ps.event_id = e.id AND pp.bought_in = 1) AS paid_players
         FROM events e
         WHERE e.league_id = ?
         ORDER BY e.start_date DESC"
    );
    $evRows->execute([$league_id]);
    $events = $evRows->fetchAll();

    $poker_with_data = 0;
    foreach ($events as $e) if ((int)$e['paid_players'] > 0) $poker_with_data++;

    ok([
        'name'              => $name,
        'event_count'       => count($events),
        'member_count'      => $members,
        'request_count'     => $requests,
        'poker_with_data'   => $poker_with_data,
        'events'            => array_map(function($e) {
            return [
                'id'           => (int)$e['id'],
                'title'        => (string)$e['title'],
                'start_date'   => (string)$e['start_date'],
                'is_poker'     => (int)$e['is_poker'],
                'paid_players' => (int)$e['paid_players'],
            ];
        }, $events),
    ]);
}

case 'delete_league': {
    $league_id   = (int)($_POST['league_id'] ?? 0);
    $confirmName = trim($_POST['confirm_name'] ?? '');
    league_role_or_fail($db, $league_id, $uid, ['owner'], $isAdmin);

    // Safety rail: require the user to type the exact league name to confirm.
    $L = $db->prepare('SELECT name FROM leagues WHERE id = ?');
    $L->execute([$league_id]);
    $actualName = (string)$L->fetchColumn();
    if ($actualName === '')                                     fail('League not found', 404);
    if (strcasecmp($confirmName, $actualName) !== 0)            fail('Confirmation name did not match.');

    // Count events before delete so we can log it
    $evCount = (int)$db->query('SELECT COUNT(*) FROM events WHERE league_id = ' . $league_id)->fetchColumn();

    $db->beginTransaction();
    try {
        delete_league_cascade($db, $league_id);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        fail('Delete failed: ' . $e->getMessage(), 500);
    }

    db_log_activity($uid, 'deleted league "' . $actualName . '" (' . $evCount . ' event(s))');
    ok(['deleted_events' => $evCount]);
}

case 'request_join': {
    $league_id = (int)($_POST['league_id'] ?? 0);
    $msg = trim($_POST['message'] ?? '');
    $L = $db->prepare('SELECT * FROM leagues WHERE id = ?');
    $L->execute([$league_id]);
    $league = $L->fetch();
    if (!$league) fail('League not found', 404);

    // Already a member?
    if (league_role($league_id, $uid) !== null) fail('Already a member');

    if ($league['approval_mode'] === 'auto') {
        // Auto-join: skip the request row; just add as member.
        $db->prepare('INSERT INTO league_members (league_id, user_id, role) VALUES (?, ?, ?)')
            ->execute([$league_id, $uid, 'member']);
        // FYI notification to owner
        notify_user($db, (int)$league['owner_id'],
            'New member joined ' . $league['name'],
            $current['username'] . ' joined your league "' . $league['name'] . '".',
            '<p><strong>' . htmlspecialchars($current['username']) . '</strong> joined your league <strong>' . htmlspecialchars($league['name']) . '</strong>.</p>'
        );
        ok(['joined' => true]);
    }

    // Manual: insert pending request (UNIQUE prevents duplicates at same status)
    try {
        $db->prepare(
            'INSERT INTO league_join_requests (league_id, user_id, message, status) VALUES (?, ?, ?, ?)'
        )->execute([$league_id, $uid, $msg, 'pending']);
    } catch (Throwable $e) {
        fail('Request already pending');
    }

    // Notify owner + managers
    $reviewUrl = get_site_url() . '/league.php?id=' . $league_id . '&tab=requests';
    if (get_setting('url_shortener_enabled') === '1') { $reviewUrl = shorten_url($reviewUrl); }
    $approvers = $db->prepare(
        "SELECT user_id FROM league_members WHERE league_id = ? AND role IN ('owner','manager')"
    );
    $approvers->execute([$league_id]);
    foreach ($approvers->fetchAll() as $a) {
        notify_user($db, (int)$a['user_id'],
            'New join request for ' . $league['name'],
            $current['username'] . ' requested to join "' . $league['name'] . '". Review: ' . $reviewUrl,
            '<p><strong>' . htmlspecialchars($current['username']) . '</strong> has requested to join <strong>' . htmlspecialchars($league['name']) . '</strong>.</p>'
            . ($msg !== '' ? '<p><em>Message:</em> ' . htmlspecialchars($msg) . '</p>' : '')
            . '<p><a href="' . htmlspecialchars($reviewUrl) . '">Review request</a></p>'
        );
    }
    ok(['requested' => true]);
}

case 'cancel_request': {
    $league_id = (int)($_POST['league_id'] ?? 0);
    $db->prepare("DELETE FROM league_join_requests WHERE league_id = ? AND user_id = ? AND status = 'pending'")
        ->execute([$league_id, $uid]);
    ok();
}

case 'approve_request':
case 'deny_request': {
    $req_id = (int)($_POST['request_id'] ?? 0);
    $r = $db->prepare('SELECT * FROM league_join_requests WHERE id = ?');
    $r->execute([$req_id]);
    $req = $r->fetch();
    if (!$req) fail('Request not found', 404);

    league_role_or_fail($db, (int)$req['league_id'], $uid, ['owner', 'manager'], $isAdmin);
    if ($req['status'] !== 'pending') fail('Already decided');

    $newStatus = $action === 'approve_request' ? 'approved' : 'denied';
    $db->beginTransaction();
    try {
        // Clear any prior approved/denied history rows for this (league, user) so the
        // UNIQUE(league_id, user_id, status) constraint doesn't block flipping the pending row.
        $db->prepare(
            "DELETE FROM league_join_requests
             WHERE league_id = ? AND user_id = ? AND status = ? AND id <> ?"
        )->execute([(int)$req['league_id'], (int)$req['user_id'], $newStatus, $req_id]);

        $db->prepare(
            "UPDATE league_join_requests SET status = ?, decided_at = CURRENT_TIMESTAMP, decided_by = ? WHERE id = ?"
        )->execute([$newStatus, $uid, $req_id]);

        if ($newStatus === 'approved') {
            $db->prepare('INSERT OR IGNORE INTO league_members (league_id, user_id, role) VALUES (?, ?, ?)')
                ->execute([(int)$req['league_id'], (int)$req['user_id'], 'member']);
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        fail('Failed: ' . $e->getMessage(), 500);
    }

    $L = $db->prepare('SELECT name FROM leagues WHERE id = ?');
    $L->execute([(int)$req['league_id']]);
    $lname = (string)$L->fetchColumn();

    if ($newStatus === 'approved') {
        $lurl = get_site_url() . '/league.php?id=' . (int)$req['league_id'];
        if (get_setting('url_shortener_enabled') === '1') { $lurl = shorten_url($lurl); }
        notify_user($db, (int)$req['user_id'],
            'You joined ' . $lname,
            'Your request to join "' . $lname . '" was approved. View: ' . $lurl,
            '<p>Your request to join <strong>' . htmlspecialchars($lname) . '</strong> was approved. <a href="' . htmlspecialchars($lurl) . '">View league</a></p>'
        );
    } else {
        notify_user($db, (int)$req['user_id'],
            'Request declined — ' . $lname,
            'Your request to join "' . $lname . '" was declined.',
            '<p>Your request to join <strong>' . htmlspecialchars($lname) . '</strong> was declined.</p>'
        );
    }
    ok();
}

case 'remove_member': {
    $league_id  = (int)($_POST['league_id'] ?? 0);
    $member_id  = (int)($_POST['member_id'] ?? 0);
    $target_uid = (int)($_POST['user_id']   ?? 0);  // legacy path
    $myRole     = league_role_or_fail($db, $league_id, $uid, ['owner', 'manager'], $isAdmin);

    // Look up the row either by league_members.id (preferred) or by user_id (legacy callers)
    if ($member_id > 0) {
        $rs = $db->prepare('SELECT * FROM league_members WHERE id = ? AND league_id = ?');
        $rs->execute([$member_id, $league_id]);
    } else {
        $rs = $db->prepare('SELECT * FROM league_members WHERE league_id = ? AND user_id = ?');
        $rs->execute([$league_id, $target_uid]);
    }
    $row = $rs->fetch();
    if (!$row) fail('Not a member');

    $tRole = $row['role'] ?? 'member';
    if ($tRole === 'owner') fail('Cannot remove the owner — transfer ownership first');
    if ($myRole === 'manager' && $tRole === 'manager') fail('Managers cannot remove other managers');

    $db->prepare('DELETE FROM league_members WHERE id = ?')->execute([(int)$row['id']]);

    if (!empty($row['user_id'])) {
        // Linked member: clean up any historic join-request rows and notify.
        $db->prepare("DELETE FROM league_join_requests WHERE league_id = ? AND user_id = ?")
            ->execute([$league_id, (int)$row['user_id']]);
        $L = $db->prepare('SELECT name FROM leagues WHERE id = ?');
        $L->execute([$league_id]);
        $lname = (string)$L->fetchColumn();
        notify_user($db, (int)$row['user_id'],
            'Removed from ' . $lname,
            'You were removed from "' . $lname . '".',
            '<p>You were removed from the league <strong>' . htmlspecialchars($lname) . '</strong>.</p>'
        );
    }
    // Pending contacts: no notification (their invite is being rescinded silently).
    ok();
}

case 'add_contact': {
    $league_id = (int)($_POST['league_id'] ?? 0);
    $myRole    = league_role_or_fail($db, $league_id, $uid, ['owner', 'manager'], $isAdmin);

    $name  = trim($_POST['contact_name']  ?? '');
    $email = strtolower(trim($_POST['contact_email'] ?? ''));
    $phoneRaw = trim($_POST['contact_phone'] ?? '');
    $phone = $phoneRaw !== '' ? normalize_phone($phoneRaw) : '';

    if ($name === '')                    fail('Name is required.');
    if ($email === '' && $phone === '')  fail('Provide at least an email or a phone number.');
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Invalid email address.');

    // Look up an existing user by email or phone.
    $existing = null;
    if ($email !== '') {
        $u = $db->prepare('SELECT id, username, email, phone, preferred_contact FROM users WHERE LOWER(email) = ? LIMIT 1');
        $u->execute([$email]);
        $existing = $u->fetch() ?: null;
    }
    if (!$existing && $phone !== '') {
        $u = $db->prepare('SELECT id, username, email, phone, preferred_contact FROM users WHERE phone = ? LIMIT 1');
        $u->execute([$phone]);
        $existing = $u->fetch() ?: null;
    }

    $L = $db->prepare('SELECT name FROM leagues WHERE id = ?');
    $L->execute([$league_id]);
    $lname = (string)$L->fetchColumn();

    if ($existing) {
        // Linked path — insert the real user directly as a member.
        $already = league_role($league_id, (int)$existing['id']);
        if ($already !== null) fail('Already a member.');
        $db->prepare(
            "INSERT INTO league_members (league_id, user_id, role, invited_by, invited_at)
             VALUES (?, ?, 'member', ?, CURRENT_TIMESTAMP)"
        )->execute([$league_id, (int)$existing['id'], $uid]);

        // Notify the user
        $url = get_site_url() . '/league.php?id=' . $league_id;
        if (get_setting('url_shortener_enabled') === '1') { $url = shorten_url($url); }
        send_notification(
            $existing['username'] ?? '',
            $existing['email']    ?? '',
            $existing['phone']    ?? '',
            $existing['preferred_contact'] ?? 'email',
            'Added to ' . $lname,
            'You were added to the league "' . $lname . '". View: ' . $url,
            '<p>You were added to the league <strong>' . htmlspecialchars($lname) . '</strong>. <a href="' . htmlspecialchars($url) . '">View league</a></p>'
        );
        ok(['linked' => true]);
    }

    // Pending path — check we don't already have a pending row for this email.
    if ($email !== '') {
        $dup = $db->prepare('SELECT 1 FROM league_members WHERE league_id = ? AND user_id IS NULL AND LOWER(contact_email) = ? LIMIT 1');
        $dup->execute([$league_id, $email]);
        if ($dup->fetchColumn()) fail('Already a contact in this league.');
    }

    $token = bin2hex(random_bytes(16));
    $db->prepare(
        "INSERT INTO league_members (league_id, user_id, role, contact_name, contact_email, contact_phone, invited_by, invited_at, invite_token)
         VALUES (?, NULL, 'member', ?, ?, ?, ?, CURRENT_TIMESTAMP, ?)"
    )->execute([$league_id, $name, $email ?: null, $phone ?: null, $uid, $token]);

    // Send invite
    $inviteUrl = get_site_url() . '/league_invite.php?token=' . $token;
    if (get_setting('url_shortener_enabled') === '1') { $inviteUrl = shorten_url($inviteUrl); }
    send_notification(
        $name, $email, $phone,
        $email !== '' ? 'email' : 'sms',
        'Invitation to join ' . $lname,
        'You have been invited to join the league "' . $lname . '". Sign up: ' . $inviteUrl,
        '<p>Hi ' . htmlspecialchars($name) . ',</p>'
        . '<p>You have been invited to join the league <strong>' . htmlspecialchars($lname) . '</strong>.</p>'
        . '<p><a href="' . htmlspecialchars($inviteUrl) . '" style="background:#2563eb;color:#fff;padding:.5rem 1rem;border-radius:6px;text-decoration:none;font-weight:600">Accept invite &amp; sign up</a></p>'
    );

    ok(['pending' => true]);
}

case 'update_member': {
    // Inline single-field update from the spreadsheet grid.
    // Allowed fields:
    //   - linked rows:  role  (only owner can change; owner role cannot be set here — use transfer_ownership)
    //   - pending rows: contact_name, contact_email, contact_phone
    $league_id = (int)($_POST['league_id'] ?? 0);
    $member_id = (int)($_POST['member_id'] ?? 0);
    $field     = (string)($_POST['field'] ?? '');
    $value     = trim((string)($_POST['value'] ?? ''));
    $myRole    = league_role_or_fail($db, $league_id, $uid, ['owner', 'manager'], $isAdmin);

    $rs = $db->prepare('SELECT * FROM league_members WHERE id = ? AND league_id = ?');
    $rs->execute([$member_id, $league_id]);
    $row = $rs->fetch();
    if (!$row) fail('Member not found', 404);

    if (!empty($row['user_id'])) {
        // Linked row — role only
        if ($field !== 'role') fail('This field is not editable on a linked member.');
        if (!$isAdmin && $myRole !== 'owner') fail('Only the owner can change roles.');
        if ($row['role'] === 'owner') fail('Use Transfer Ownership to change the owner.');
        if (!in_array($value, ['member', 'manager'], true)) fail('Invalid role.');
        $db->prepare('UPDATE league_members SET role = ? WHERE id = ?')->execute([$value, $member_id]);

        // Notify the user
        $L = $db->prepare('SELECT name FROM leagues WHERE id = ?');
        $L->execute([$league_id]);
        $lname = (string)$L->fetchColumn();
        notify_user($db, (int)$row['user_id'],
            'Role changed in ' . $lname,
            'You are now a ' . $value . ' in "' . $lname . '".',
            '<p>Your role in <strong>' . htmlspecialchars($lname) . '</strong> is now <strong>' . htmlspecialchars($value) . '</strong>.</p>'
        );
        ok();
    }

    // Pending contact
    if (!in_array($field, ['contact_name', 'contact_email', 'contact_phone'], true)) {
        fail('This field is not editable.');
    }
    if ($field === 'contact_email') {
        $value = strtolower($value);
        if ($value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) fail('Invalid email.');
    }
    if ($field === 'contact_phone') {
        $value = $value !== '' ? normalize_phone($value) : '';
    }
    if ($field === 'contact_name' && $value === '') fail('Name is required.');

    // If this row had neither email nor phone, block clearing whichever is the last identifier.
    if ($field !== 'contact_name' && $value === '') {
        $other = $field === 'contact_email' ? ($row['contact_phone'] ?? '') : ($row['contact_email'] ?? '');
        if ($other === '' || $other === null) fail('A pending contact must keep at least an email or a phone.');
    }

    // Email uniqueness across pending rows in this league
    if ($field === 'contact_email' && $value !== '') {
        $dup = $db->prepare('SELECT 1 FROM league_members WHERE league_id = ? AND user_id IS NULL AND LOWER(contact_email) = ? AND id <> ? LIMIT 1');
        $dup->execute([$league_id, $value, $member_id]);
        if ($dup->fetchColumn()) fail('Another pending contact already uses that email.');
    }

    $db->prepare("UPDATE league_members SET $field = ? WHERE id = ?")
        ->execute([$value !== '' ? $value : null, $member_id]);
    ok();
}

case 'resend_contact_invite': {
    $league_id = (int)($_POST['league_id'] ?? 0);
    $member_id = (int)($_POST['member_id'] ?? 0);
    league_role_or_fail($db, $league_id, $uid, ['owner', 'manager'], $isAdmin);

    $rs = $db->prepare('SELECT * FROM league_members WHERE id = ? AND league_id = ? AND user_id IS NULL');
    $rs->execute([$member_id, $league_id]);
    $row = $rs->fetch();
    if (!$row) fail('Pending contact not found.');

    $token = bin2hex(random_bytes(16));
    $db->prepare('UPDATE league_members SET invite_token = ? WHERE id = ?')
        ->execute([$token, (int)$row['id']]);

    $L = $db->prepare('SELECT name FROM leagues WHERE id = ?');
    $L->execute([$league_id]);
    $lname = (string)$L->fetchColumn();

    $inviteUrl = get_site_url() . '/league_invite.php?token=' . $token;
    if (get_setting('url_shortener_enabled') === '1') { $inviteUrl = shorten_url($inviteUrl); }
    send_notification(
        (string)$row['contact_name'],
        (string)($row['contact_email'] ?? ''),
        (string)($row['contact_phone'] ?? ''),
        !empty($row['contact_email']) ? 'email' : 'sms',
        'Reminder: invitation to join ' . $lname,
        'Reminder: you have been invited to join "' . $lname . '". Sign up: ' . $inviteUrl,
        '<p>Reminder: you have been invited to join the league <strong>' . htmlspecialchars($lname) . '</strong>.</p>'
        . '<p><a href="' . htmlspecialchars($inviteUrl) . '">Accept invite &amp; sign up</a></p>'
    );

    ok();
}

case 'leave_league': {
    $league_id = (int)($_POST['league_id'] ?? 0);
    $myRole = league_role($league_id, $uid);
    if ($myRole === null) fail('Not a member');
    if ($myRole === 'owner') fail('Transfer ownership before leaving');
    $db->prepare('DELETE FROM league_members WHERE league_id = ? AND user_id = ?')->execute([$league_id, $uid]);
    $db->prepare("DELETE FROM league_join_requests WHERE league_id = ? AND user_id = ?")->execute([$league_id, $uid]);
    ok();
}

case 'promote_manager':
case 'demote_manager': {
    $league_id = (int)($_POST['league_id'] ?? 0);
    $target    = (int)($_POST['user_id']   ?? 0);
    league_role_or_fail($db, $league_id, $uid, ['owner'], $isAdmin);
    if ($target <= 0) fail('Pending contacts cannot hold a role — they must sign up first.');
    $tRole = league_role($league_id, $target);
    if ($tRole === null) fail('Not a member');
    if ($tRole === 'owner') fail('Cannot change owner role directly');
    $newRole = $action === 'promote_manager' ? 'manager' : 'member';
    $db->prepare('UPDATE league_members SET role = ? WHERE league_id = ? AND user_id = ?')
        ->execute([$newRole, $league_id, $target]);

    $L = $db->prepare('SELECT name FROM leagues WHERE id = ?');
    $L->execute([$league_id]);
    $lname = (string)$L->fetchColumn();
    notify_user($db, $target,
        'Role changed in ' . $lname,
        'You are now a ' . $newRole . ' in "' . $lname . '".',
        '<p>Your role in <strong>' . htmlspecialchars($lname) . '</strong> is now <strong>' . htmlspecialchars($newRole) . '</strong>.</p>'
    );
    ok();
}

case 'transfer_ownership': {
    $league_id = (int)($_POST['league_id'] ?? 0);
    $target    = (int)($_POST['user_id']   ?? 0);
    league_role_or_fail($db, $league_id, $uid, ['owner'], $isAdmin);
    if ($target === $uid) fail('You are already the owner');
    $tRole = league_role($league_id, $target);
    if ($tRole === null) fail('Target user is not a member');

    $db->beginTransaction();
    try {
        $db->prepare('UPDATE league_members SET role = ? WHERE league_id = ? AND user_id = ?')
            ->execute(['member', $league_id, $uid]);
        $db->prepare('UPDATE league_members SET role = ? WHERE league_id = ? AND user_id = ?')
            ->execute(['owner',  $league_id, $target]);
        $db->prepare('UPDATE leagues SET owner_id = ? WHERE id = ?')
            ->execute([$target, $league_id]);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        fail('Transfer failed', 500);
    }

    $L = $db->prepare('SELECT name FROM leagues WHERE id = ?');
    $L->execute([$league_id]);
    $lname = (string)$L->fetchColumn();
    notify_user($db, $target,
        'You are now owner of ' . $lname,
        'Ownership of "' . $lname . '" was transferred to you.',
        '<p>You are now the owner of <strong>' . htmlspecialchars($lname) . '</strong>.</p>'
    );
    notify_user($db, $uid,
        'Ownership transferred — ' . $lname,
        'You transferred ownership of "' . $lname . '".',
        '<p>You transferred ownership of <strong>' . htmlspecialchars($lname) . '</strong>.</p>'
    );
    ok();
}

case 'regenerate_invite_code': {
    $league_id = (int)($_POST['league_id'] ?? 0);
    league_role_or_fail($db, $league_id, $uid, ['owner'], $isAdmin);
    $code = generate_invite_code();
    $db->prepare('UPDATE leagues SET invite_code = ? WHERE id = ?')->execute([$code, $league_id]);
    require_once __DIR__ . '/sms.php';
    $full  = get_site_url() . '/join_league.php?code=' . urlencode($code);
    $short = shorten_url($full);
    ok(['invite_code' => $code, 'invite_url' => $short]);
}

default:
    fail('Unknown action');
}
