<?php
/**
 * Putting a person on an event's guest list from a public page, with no host
 * involved: the walk-up QR at the door (walkin.php, source 'walkin') and the
 * shared invite link (invite.php, source 'share').
 *
 * Both pages take a name and ONE contact (email or phone), find the account
 * that owns that contact or make a soft one, and add a base invite row by
 * user_id. The rules that differ by source live here so the two pages cannot
 * drift apart:
 *
 *   - An existing account found by the typed contact is always PENDING: the
 *     person holding the QR or the link may have typed someone else's address.
 *     An already-approved row is never flipped by either page.
 *   - A new account: walk-in follows the event's requires_approval; the share
 *     link also follows the host's share_requires_approval switch, and fills
 *     up to capacity, then waitlists (or refuses when the waitlist is off).
 *   - Seating is the walk-in page's business (the guest is at the door); the
 *     share link never seats anyone, approve_event_invitee() does that later.
 *
 * Caller loads db.php, auth.php and _notifications.php first.
 */

/**
 * Read the form: name + one contact (auto-detected: '@' = email, else phone).
 * The legacy separate email/phone slots still work for old bookmarks.
 */
function public_join_parse(array $post): array {
    $contact_raw = trim((string)($post['contact'] ?? ''));
    $email = strtolower(trim((string)($post['email'] ?? '')));
    $phone = trim((string)($post['phone'] ?? ''));
    if ($contact_raw !== '' && $email === '' && $phone === '') {
        if (strpos($contact_raw, '@') !== false) $email = strtolower($contact_raw);
        else $phone = $contact_raw;
    }
    $display_name = trim((string)($post['display_name'] ?? ''));
    $username_raw = preg_replace('/\s+/', '_', $display_name);
    $username_raw = preg_replace('/[^a-zA-Z0-9_]/', '', $username_raw);

    $phone_normalized = ($phone !== '') ? normalize_phone($phone) : '';
    $digits    = preg_replace('/\D/', '', $phone_normalized);
    $has_email = ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL));
    $has_phone = ($phone_normalized !== '' && strlen($digits) >= 7 && strlen($digits) <= 15);

    $error = '';
    if ($display_name === '') {
        $error = 'Display name is required.';
    } elseif (strlen($username_raw) < 3 || strlen($username_raw) > 30) {
        $error = 'Display name must produce a username between 3 and 30 characters (letters, numbers, spaces, underscores).';
    } elseif (!$has_email && !$has_phone) {
        $error = 'Enter an email address or phone number.';
    } elseif ($email !== '' && !$has_email) {
        $error = 'Invalid email address.';
    } elseif ($phone !== '' && !$has_phone) {
        $error = 'Invalid phone number.';
    }
    return [
        'error'        => $error,
        'display_name' => $display_name,
        'username_raw' => $username_raw,
        'contact_raw'  => $contact_raw !== '' ? $contact_raw : ($email ?: $phone),
        'email'        => $has_email ? $email : '',
        'phone'        => $has_phone ? $phone_normalized : '',
        'has_email'    => $has_email,
        'has_phone'    => $has_phone,
    ];
}

/**
 * Where a share-link join lands when nothing forces it to pending:
 * 'approved' while there is room, 'waitlisted' once full (waitlist on),
 * 'full' once full with the waitlist off (the caller refuses).
 */
function share_capacity_status(PDO $db, int $event_id): string {
    $cap = event_capacity($db, $event_id);
    if ($cap['capacity'] > 0 && $cap['filled'] >= $cap['capacity']) {
        return $cap['waitlist'] ? 'waitlisted' : 'full';
    }
    return 'approved';
}

/** Does the share link put new people on hold for the host? */
function share_needs_approval(PDO $db, int $event_id): bool {
    $s = $db->prepare('SELECT requires_approval, share_requires_approval FROM events WHERE id = ?');
    $s->execute([$event_id]);
    $r = $s->fetch();
    if (!$r) return true;
    return (int)$r['requires_approval'] === 1 || (int)($r['share_requires_approval'] ?? 1) === 1;
}

/**
 * The status a NEW invite row gets. $forcePending: an existing account reached
 * by a typed contact (never trusted with a confirmed spot).
 */
function public_join_status(PDO $db, int $event_id, string $source, bool $forcePending): string {
    if ($forcePending) return 'pending';
    if ($source === 'share') {
        if (share_needs_approval($db, $event_id)) return 'pending';
        return share_capacity_status($db, $event_id);
    }
    return invite_approval_status($event_id, 'self');
}

/** The account's base invite row on this event, or null. */
function public_join_existing_row(PDO $db, int $event_id, int $uid): ?array {
    $chk = $db->prepare('SELECT id, approval_status, rsvp FROM event_invites WHERE event_id = ? AND user_id = ? AND occurrence_date IS NULL');
    $chk->execute([$event_id, $uid]);
    $r = $chk->fetch();
    return $r ?: null;
}

/** Insert the base row by account id. Every row gets an RSVP token so the
 *  guest's own link (event.php?token=) works from the first notice. */
function public_join_insert(PDO $db, int $event_id, int $uid, string $username, ?string $email, ?string $phone, string $status, string $source): void {
    $db->prepare('INSERT INTO event_invites (event_id, username, user_id, email, phone, rsvp, rsvp_token, approval_status, join_source) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
       ->execute([$event_id, $username, $uid, $email ?: null, $phone ?: null, 'yes', bin2hex(random_bytes(16)), $status, $source]);
    if ($status === 'waitlisted' && function_exists('recompact_sort_order')) recompact_sort_order($db, $event_id);
}

/**
 * What the joiner and the hosts hear. Pending → host gets the approval
 * request, the joiner a waiting-list note (both sent now, as the walk-in always
 * did). Share link approved → the joiner gets "you're on the list" with their
 * own event link (it carries the address), the hosts an RSVP notice saying it
 * came through the link. Waitlisted → the joiner hears so.
 */
function public_join_notify(PDO $db, array $event, int $uid, string $username, string $status, string $source, bool $isNewRow): void {
    if (!$isNewRow) return;
    $eid = (int)$event['id'];
    if ($status === 'pending') {
        notify_creator_of_pending($eid, $username);
    }
    if (get_setting('notifications_enabled', '0') !== '1') return;

    if ($status === 'pending' || $status === 'waitlisted') {
        $u = $db->prepare('SELECT username, email, phone, preferred_contact FROM users WHERE id = ?');
        $u->execute([$uid]);
        $row = $u->fetch();
        if (!$row || !function_exists('send_notification')) return;
        $when  = public_join_when($event);
        $title = (string)$event['title'];
        if ($status === 'pending') {
            $sms  = "You're on the waiting list for \"$title\" on $when. The host will approve your registration shortly.";
            $html = '<p>You are on the waiting list for <strong>' . htmlspecialchars($title) . '</strong> on ' . htmlspecialchars($when) . '.</p>'
                  . '<p style="color:#64748b">The host will approve your registration shortly. You will receive another notification when approved.</p>';
            $subj = 'Waiting list: ' . $title;
        } else {
            $sms  = "\"$title\" on $when is full, so you're on the waiting list. We'll tell you if a seat opens up.";
            $html = '<p><strong>' . htmlspecialchars($title) . '</strong> on ' . htmlspecialchars($when) . ' is full, so you are on the waiting list.</p>'
                  . '<p style="color:#64748b">We will tell you if a seat opens up.</p>';
            $subj = 'Waiting list: ' . $title;
        }
        send_notification($row['username'], $row['email'] ?? '', $row['phone'] ?? '',
            $row['preferred_contact'] ?: (!empty($row['phone']) ? 'sms' : 'email'), $subj, $sms, $html);
        return;
    }

    if ($status === 'approved' && $source === 'share') {
        queue_event_notification($db, $eid, $username, 'joined_event', null, null, null, $uid);
        queue_rsvp_reply_notifications($db, $eid, null, $username, $username, 'yes', 'share');
        if (function_exists('drain_queue_async')) drain_queue_async();
    }
}

/** "Saturday, November 8, 2026 · 7:00 PM CST" in the creator's zone. */
function public_join_when(array $event): string {
    $lbl = event_public_time_labels((string)$event['start_date'], $event['start_time'] ?: null, $event['end_time'] ?? null,
                                    (int)($event['created_by'] ?? 0) ?: null);
    $out = $lbl['date_lbl'];
    if ($lbl['time_lbl'] !== '') $out .= ' · ' . html_entity_decode($lbl['time_lbl'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return $out;
}

/**
 * Join by a typed name + contact. $in is public_join_parse()'s answer with no
 * error. Returns:
 *   status    approved | pending | waitlisted | full | already | error
 *   error     text when status = error
 *   user_id, username, is_new_user
 *   verify    '' | 'phone_inline' | 'email_note' (a new soft account's check)
 * 'already' = the account was on the list and approved; nothing changed.
 * A pending or denied row reads as 'pending' (a denial is never revealed).
 */
function public_join_event(PDO $db, array $event, array $in, string $source): array {
    $eid = (int)$event['id'];
    $out = ['status' => 'error', 'error' => '', 'user_id' => 0, 'username' => '', 'is_new_user' => false, 'verify' => ''];

    if ($in['has_email']) {
        $s = $db->prepare('SELECT id, username FROM users WHERE LOWER(email) = ?');
        $s->execute([$in['email']]);
    } else {
        $s = $db->prepare('SELECT id, username FROM users WHERE phone = ?');
        $s->execute([$in['phone']]);
    }
    $existing = $s->fetch();

    if ($existing) {
        $uid = (int)$existing['id'];
        $username = (string)$existing['username'];
        $out['user_id'] = $uid; $out['username'] = $username;
        $row = public_join_existing_row($db, $eid, $uid);
        if ($row) {
            $st = $row['approval_status'] ?? 'approved';
            $out['status'] = ($st === 'approved') ? 'already' : ($st === 'waitlisted' ? 'waitlisted' : 'pending');
            db_log_anon_activity("{$source}_rsvp: existing user $username for event $eid (already listed)");
            return $out;
        }
        public_join_insert($db, $eid, $uid, $username, $in['email'] ?: null, null, 'pending', $source);
        $out['status'] = 'pending';
        db_log_anon_activity("{$source}_rsvp: existing user $username for event $eid (waiting list)");
        public_join_notify($db, $event, $uid, $username, 'pending', $source, true);
        return $out;
    }

    // New person: a soft account, unique username.
    $final = $in['username_raw'];
    for ($n = 2; ; $n++) {
        $u = $db->prepare('SELECT id FROM users WHERE LOWER(username) = LOWER(?)');
        $u->execute([$final]);
        if (!$u->fetch()) break;
        if ($n > 999) { $out['error'] = 'Could not generate a unique username. Please try a different name.'; return $out; }
        $final = $in['username_raw'] . $n;
    }

    $status = public_join_status($db, $eid, $source, false);
    if ($status === 'full') {
        $out['status'] = 'full';
        return $out;
    }

    // Verification goes by email when there is one (the link sets a password),
    // else by SMS code. Ongoing notifications default to text when a phone
    // was given. Soft: unverified accounts still attend.
    $method    = $in['has_email'] ? 'email' : 'sms';
    $preferred = $in['has_phone'] ? 'sms' : 'email';
    $db->prepare('INSERT INTO users (username, password_hash, email, phone, role, email_verified, phone_verified, must_change_password, preferred_contact, verification_method) VALUES (?, ?, ?, ?, ?, 0, 0, 1, ?, ?)')
       ->execute([$final, '', $in['email'] ?: null, $in['phone'] ?: null, 'user', $preferred, $method]);
    $uid = (int)$db->lastInsertId();

    public_join_insert($db, $eid, $uid, $final, $in['email'] ?: null, $in['phone'] ?: null, $status, $source);
    if ($status === 'approved') auto_add_to_league($db, $eid, $uid);
    db_log_anon_activity("{$source}_new_user: $final for event $eid" . ($status !== 'approved' ? " ($status)" : ''));

    if ($method === 'email') {
        send_verification_email($uid, $in['email'], $final);
        $out['verify'] = 'email_note';
    } else {
        send_verification_code($uid, $in['phone'], 'sms');
        if (session_status() === PHP_SESSION_NONE) session_start_safe();
        $_SESSION['verify_user_id'] = $uid;
        $_SESSION['verify_method']  = 'sms';
        if ($source === 'walkin') $_SESSION['walkin_event_id'] = $eid;
        $out['verify'] = 'phone_inline';
    }

    $out['status'] = $status;
    $out['user_id'] = $uid; $out['username'] = $final; $out['is_new_user'] = true;
    public_join_notify($db, $event, $uid, $final, $status, $source, true);
    return $out;
}

/**
 * Join as the signed-in account ("I'm in" on the invite page). The account is
 * proven by its session, so it follows the share switch like a new person.
 */
function public_join_account(PDO $db, array $event, array $user, string $source): array {
    $eid = (int)$event['id'];
    $uid = (int)$user['id'];
    $out = ['status' => 'error', 'error' => '', 'user_id' => $uid, 'username' => (string)$user['username'], 'is_new_user' => false, 'verify' => ''];
    $row = public_join_existing_row($db, $eid, $uid);
    if ($row) {
        $st = $row['approval_status'] ?? 'approved';
        if ($st === 'approved' && ($row['rsvp'] ?? '') === 'no') {
            // On the list but said No: the link is them changing their mind.
            $db->prepare("UPDATE event_invites SET rsvp = 'yes' WHERE id = ?")->execute([(int)$row['id']]);
            db_log_activity($uid, "rsvp yes via share link for event id: $eid");
            if (get_setting('notifications_enabled', '0') === '1') {
                queue_rsvp_reply_notifications($db, $eid, null, (string)$user['username'], (string)$user['username'], 'yes');
                if (function_exists('drain_queue_async')) drain_queue_async();
            }
            $out['status'] = 'approved';
            return $out;
        }
        $out['status'] = ($st === 'approved') ? 'already' : ($st === 'waitlisted' ? 'waitlisted' : 'pending');
        return $out;
    }
    $status = public_join_status($db, $eid, $source, false);
    if ($status === 'full') { $out['status'] = 'full'; return $out; }

    $u = $db->prepare('SELECT email, phone FROM users WHERE id = ?');
    $u->execute([$uid]);
    $ud = $u->fetch() ?: [];
    public_join_insert($db, $eid, $uid, (string)$user['username'], $ud['email'] ?? null, $ud['phone'] ?? null, $status, $source);
    if ($status === 'approved') auto_add_to_league($db, $eid, $uid);
    db_log_activity($uid, "joined event id: $eid via $source link ($status)");
    public_join_notify($db, $event, $uid, (string)$user['username'], $status, $source, true);
    $out['status'] = $status;
    return $out;
}
