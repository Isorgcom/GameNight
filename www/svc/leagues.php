<?php
/**
 * Service layer: leagues. Read side.
 *
 * A league is readable by its members and by site admins. To anyone else it
 * does not exist (404), hidden or not: browsing public leagues is a page on
 * the site, not something a token needs.
 */

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/../auth.php';      // send_notification(), shorten_url()
require_once __DIR__ . '/../_posts.php';

function gn_league_shape(array $l, ?string $my_role, int $member_count): array {
    $site = rtrim(get_site_url(), '/');
    return [
        'id'          => (int)$l['id'],
        'name'        => (string)$l['name'],
        'description' => (string)($l['description'] ?? ''),
        'my_role'     => $my_role,                    // owner | manager | member | admin
        'member_count' => $member_count,
        'approval_mode' => (string)($l['approval_mode'] ?? 'manual'),
        'hidden'      => (int)($l['is_hidden'] ?? 0) === 1,
        'public_page' => (int)($l['public_page'] ?? 0) === 1 && !empty($l['slug']) ? $site . '/league/' . $l['slug'] : null,
        'created_at'  => gn_iso($l['created_at'] ?? null),
        'url'         => $site . '/league.php?id=' . (int)$l['id'],
    ];
}

/** The leagues the actor belongs to, with their role in each. */
function gn_leagues_list(PDO $db, array $actor): array {
    $st = $db->prepare(
        "SELECT l.*, lm.role AS my_role,
                (SELECT COUNT(*) FROM league_members m WHERE m.league_id = l.id AND m.user_id IS NOT NULL) AS member_count
           FROM league_members lm JOIN leagues l ON l.id = lm.league_id
          WHERE lm.user_id = ? ORDER BY LOWER(l.name)");
    $st->execute([(int)$actor['id']]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $l) $out[] = gn_league_shape($l, (string)$l['my_role'], (int)$l['member_count']);
    return gn_ok(['count' => count($out), 'leagues' => $out]);
}

/** The league row when the actor may read it, else a failure array (test with isset($r['ok'])). */
function gn_league_guard(PDO $db, array $actor, int $league_id, array $need_roles = []): array {
    $st = $db->prepare('SELECT * FROM leagues WHERE id = ?');
    $st->execute([$league_id]);
    $l = $st->fetch(PDO::FETCH_ASSOC);
    $standing = $l ? gn_league_standing($actor, $league_id) : null;
    if (!$l || $standing === null) return gn_err('league_not_found', 'No such league.', 404);
    if ($need_roles && $standing !== 'admin' && !in_array($standing, $need_roles, true)) {
        return gn_err('not_league_manager', 'Only the league\'s ' . implode(' or ', $need_roles) . ' can do that.', 403);
    }
    $l['_standing'] = $standing;
    return $l;
}

function gn_league_get(PDO $db, array $actor, int $league_id): array {
    $l = gn_league_guard($db, $actor, $league_id);
    if (isset($l['ok'])) return $l;
    $c = $db->prepare('SELECT COUNT(*) FROM league_members WHERE league_id = ? AND user_id IS NOT NULL');
    $c->execute([$league_id]);
    $out = gn_league_shape($l, $l['_standing'], (int)$c->fetchColumn());
    $s = $db->prepare('SELECT id, name, start_date, end_date FROM league_seasons WHERE league_id = ? ORDER BY start_date DESC, id DESC');
    $s->execute([$league_id]);
    $out['seasons'] = array_map(fn($r) => ['id' => (int)$r['id'], 'name' => (string)$r['name'],
        'start_date' => (string)$r['start_date'], 'end_date' => (string)$r['end_date']], $s->fetchAll(PDO::FETCH_ASSOC));
    return gn_ok(['league' => $out]);
}

/**
 * The roster. Every member sees names and roles. The owner and managers also
 * see people who were added by contact and have not joined yet, with the
 * contact details the league itself entered for them.
 */
function gn_league_members(PDO $db, array $actor, int $league_id): array {
    $l = gn_league_guard($db, $actor, $league_id);
    if (isset($l['ok'])) return $l;
    $manages = in_array($l['_standing'], ['owner', 'manager', 'admin'], true);
    $st = $db->prepare(
        "SELECT lm.id AS member_id, lm.role, lm.joined_at, lm.user_id, lm.invited_at, lm.known_as,
                lm.contact_name, lm.contact_email, lm.contact_phone, u.username
           FROM league_members lm LEFT JOIN users u ON u.id = lm.user_id
          WHERE lm.league_id = ?
          ORDER BY CASE lm.role WHEN 'owner' THEN 0 WHEN 'manager' THEN 1 ELSE 2 END,
                   CASE WHEN lm.user_id IS NULL THEN 1 ELSE 0 END,
                   LOWER(COALESCE(u.username, lm.contact_name))");
    $st->execute([$league_id]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $pending = $r['user_id'] === null;
        if ($pending && !$manages) continue;
        $name = trim((string)($pending ? $r['contact_name'] : $r['username']));
        if ($name === '') continue;
        $row = [
            'member_id' => (int)$r['member_id'],
            'user_id'   => $pending ? null : (int)$r['user_id'],
            'name'      => $name,
            'known_as'  => ($r['known_as'] ?? '') !== '' ? (string)$r['known_as'] : null,
            'role'      => (string)$r['role'],
            'pending'   => $pending,
            'joined_at' => $pending ? null : gn_iso($r['joined_at']),
        ];
        if ($pending) {
            $row['invited_at'] = gn_iso($r['invited_at']);
            $row['email'] = $r['contact_email'] ?: null;
            $row['phone'] = $r['contact_phone'] ?: null;
        }
        $out[] = $row;
    }
    return gn_ok(['league_id' => $league_id, 'count' => count($out), 'members' => $out]);
}

// ═════════════════════════════════════════════════════════════════════════════
// Write side: seasons, join requests, adding people, posts. Creating, renaming
// and deleting a league, and changing who runs it, stay on the site.
// ═════════════════════════════════════════════════════════════════════════════

/**
 * Add ONE contact to a league using the same rules as the single "Add" action:
 * resolve to an existing user by email/phone (linked path: add as member +
 * "Added to {league}" notice) or create a pending invited member (pending path:
 * + "invite to join" link). Dedups against current membership. Returns:
 *   'linked'  — added an existing user as a member (notified)
 *   'invited' — the email/phone belongs to an account: it was invited (accept link sent)
 *   'pending' — created a pending invite (invite sent)
 *   'already' — already a member, or already a pending contact for that email
 *   'invalid' — missing name, no email/phone, or a malformed email
 * Notifications run through send_notification(), which honors the site
 * notifications toggle and each recipient's preferred contact method.
 * Used by both the single add_contact action and bulk import_contacts.
 */
function league_add_one_contact(PDO $db, int $league_id, string $lname, string $name, string $email, string $phone, int $invitedBy): string {
    $name  = trim($name);
    $email = strtolower(trim($email));
    $phone = trim($phone) !== '' ? normalize_phone(trim($phone)) : '';
    if ($name === '') return 'invalid';
    if ($email === '' && $phone === '') return 'invalid';
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) return 'invalid';

    // Resolve to an existing user by email, then phone.
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

    if ($existing) {
        // A registered account is invited, never enrolled by someone else's act:
        // it gets an accept link addressed to it (league_invite_account()).
        return league_invite_account($db, $league_id, (int)$existing['id'], $email, $phone, $invitedBy, true);
    }

    // Pending path — dedup against an existing pending row for this email.
    if ($email !== '') {
        $dup = $db->prepare('SELECT 1 FROM league_members WHERE league_id = ? AND user_id IS NULL AND LOWER(contact_email) = ? LIMIT 1');
        $dup->execute([$league_id, $email]);
        if ($dup->fetchColumn()) return 'already';
    }
    $token = bin2hex(random_bytes(16));
    $db->prepare(
        "INSERT INTO league_members (league_id, user_id, role, contact_name, contact_email, contact_phone, invited_by, invited_at, invite_token)
         VALUES (?, NULL, 'member', ?, ?, ?, ?, CURRENT_TIMESTAMP, ?)"
    )->execute([$league_id, $name, $email ?: null, $phone ?: null, $invitedBy, $token]);
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
    db_log_activity($invitedBy, "invited contact to league id=$league_id (" . ($email !== '' ? $email : $name) . ")");
    return 'pending';
}


/**
 * Add someone to a league by name and email or phone (owner or manager).
 * Someone with an account is sent an invitation to accept; anyone else is
 * added as a pending member and sent a link to sign up. Either way a message
 * goes out, which is why the API asks for the messages permission.
 */
function gn_league_member_add(PDO $db, array $actor, int $league_id, array $body): array {
    $l = gn_league_guard($db, $actor, $league_id, ['owner', 'manager']);
    if (isset($l['ok'])) return $l;
    $bad = array_diff(array_keys($body), ['name', 'email', 'phone']);
    if ($bad) return gn_err('unknown_field', 'Unknown field: ' . implode(', ', $bad) . '.', 400);
    $name = trim((string)($body['name'] ?? '')); $email = strtolower(trim((string)($body['email'] ?? ''))); $phone = trim((string)($body['phone'] ?? ''));
    if ($name === '' || mb_strlen($name) > 60) return gn_err('bad_field', 'A name of up to 60 characters is required.', 400);
    if ($email === '' && $phone === '') return gn_err('bad_field', 'Provide at least an email or a phone number.', 400);
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) return gn_err('bad_field', 'That is not an email address.', 400);
    $res = league_add_one_contact($db, $league_id, (string)$l['name'], $name, $email, $phone, (int)$actor['id']);
    if ($res === 'invalid') return gn_err('bad_field', 'Provide a valid name and an email or phone number.', 400);
    return gn_ok(['league_id' => $league_id, 'name' => $name,
                  // already: on the roster or already invited. invited: has an account, sent an invitation to accept.
                  // pending: no account yet, added as a pending member and sent a sign-up link.
                  'result' => in_array($res, ['already', 'invited', 'pending'], true) ? $res : 'invited',
                  'message_sent' => $res !== 'already' && get_setting('notifications_enabled', '0') === '1']);
}

/** Add a season: a named date window for standings (owner or manager). */
function gn_season_add(PDO $db, array $actor, int $league_id, array $body): array {
    $l = gn_league_guard($db, $actor, $league_id, ['owner', 'manager']);
    if (isset($l['ok'])) return $l;
    $bad = array_diff(array_keys($body), ['name', 'start_date', 'end_date']);
    if ($bad) return gn_err('unknown_field', 'Unknown field: ' . implode(', ', $bad) . '.', 400);
    $name = trim((string)($body['name'] ?? '')); $sd = (string)($body['start_date'] ?? ''); $ed = (string)($body['end_date'] ?? '');
    $isDate = fn(string $v) => (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && ($d = DateTime::createFromFormat('!Y-m-d', $v)) && $d->format('Y-m-d') === $v;
    if ($name === '' || mb_strlen($name) > 60 || !$isDate($sd) || !$isDate($ed) || $ed < $sd) {
        return gn_err('bad_field', 'Season needs a name and a valid start/end date (end after start).', 400);
    }
    $db->prepare('INSERT INTO league_seasons (league_id, name, start_date, end_date, created_by) VALUES (?, ?, ?, ?, ?)')
       ->execute([$league_id, $name, $sd, $ed, (int)$actor['id']]);
    $sid = (int)$db->lastInsertId();
    db_log_activity((int)$actor['id'], "added season '$name' ($sd to $ed) to league id=$league_id");
    return gn_ok(['league_id' => $league_id, 'season' => ['id' => $sid, 'name' => $name, 'start_date' => $sd, 'end_date' => $ed]]);
}

/** Remove a season. Games and results are untouched; only the named window goes. */
function gn_season_delete(PDO $db, array $actor, int $league_id, int $season_id): array {
    $l = gn_league_guard($db, $actor, $league_id, ['owner', 'manager']);
    if (isset($l['ok'])) return $l;
    $st = $db->prepare('SELECT name FROM league_seasons WHERE id = ? AND league_id = ?');
    $st->execute([$season_id, $league_id]);
    $name = $st->fetchColumn();
    if ($name === false) return gn_err('season_not_found', 'No such season in this league.', 404);
    $db->prepare('DELETE FROM league_seasons WHERE id = ? AND league_id = ?')->execute([$season_id, $league_id]);
    db_log_activity((int)$actor['id'], "deleted season id=$season_id from league id=$league_id");
    return gn_ok(['league_id' => $league_id, 'season_id' => $season_id, 'name' => (string)$name, 'deleted' => true]);
}

/** People waiting for an answer on a request to join (owner or manager). */
function gn_join_requests_list(PDO $db, array $actor, int $league_id): array {
    $l = gn_league_guard($db, $actor, $league_id, ['owner', 'manager']);
    if (isset($l['ok'])) return $l;
    $st = $db->prepare("SELECT r.id, r.user_id, r.message, r.requested_at, u.username
                        FROM league_join_requests r JOIN users u ON u.id = r.user_id
                        WHERE r.league_id = ? AND r.status = 'pending' ORDER BY r.requested_at, r.id");
    $st->execute([$league_id]);
    $out = array_map(fn($r) => ['request_id' => (int)$r['id'], 'user_id' => (int)$r['user_id'], 'name' => (string)$r['username'],
        'message' => (string)($r['message'] ?? ''), 'requested_at' => gn_iso($r['requested_at'])], $st->fetchAll(PDO::FETCH_ASSOC));
    return gn_ok(['league_id' => $league_id, 'count' => count($out), 'requests' => $out]);
}

/** Approve or decline a request to join. The person is told either way. */
function gn_join_request_decide(PDO $db, array $actor, int $request_id, bool $approve, ?int $league_id = null): array {
    $r = $db->prepare('SELECT * FROM league_join_requests WHERE id = ?');
    $r->execute([$request_id]);
    $req = $r->fetch(PDO::FETCH_ASSOC);
    // A request in a league the actor cannot see is "not found", as is one
    // addressed through the wrong league.
    if (!$req || ($league_id !== null && (int)$req['league_id'] !== $league_id) || gn_league_standing($actor, (int)$req['league_id']) === null) {
        return gn_err('request_not_found', 'No such request.', 404);
    }
    $lid = (int)$req['league_id']; $target = (int)$req['user_id'];
    $l = gn_league_guard($db, $actor, $lid, ['owner', 'manager']);
    if (isset($l['ok'])) return $l;
    if ($req['status'] !== 'pending') return gn_err('already_decided', 'That request was already answered.', 409);

    $status = $approve ? 'approved' : 'denied';
    $db->beginTransaction();
    try {
        // Clear any earlier approved/denied row for this person so the
        // UNIQUE(league_id, user_id, status) constraint lets the pending row flip.
        $db->prepare('DELETE FROM league_join_requests WHERE league_id = ? AND user_id = ? AND status = ? AND id <> ?')
           ->execute([$lid, $target, $status, $request_id]);
        $db->prepare('UPDATE league_join_requests SET status = ?, decided_at = CURRENT_TIMESTAMP, decided_by = ? WHERE id = ?')
           ->execute([$status, (int)$actor['id'], $request_id]);
        if ($approve) $db->prepare("INSERT OR IGNORE INTO league_members (league_id, user_id, role) VALUES (?, ?, 'member')")->execute([$lid, $target]);
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }

    $lname = (string)$l['name'];
    $u = $db->prepare('SELECT username, email, phone, preferred_contact FROM users WHERE id = ?');
    $u->execute([$target]);
    if ($who = $u->fetch(PDO::FETCH_ASSOC)) {
        if ($approve) {
            $lurl = get_site_url() . '/league.php?id=' . $lid;
            if (get_setting('url_shortener_enabled') === '1') $lurl = shorten_url($lurl);
            [$subj, $sms, $html] = ['You joined ' . $lname, 'Your request to join "' . $lname . '" was approved. View: ' . $lurl,
                '<p>Your request to join <strong>' . htmlspecialchars($lname) . '</strong> was approved. <a href="' . htmlspecialchars($lurl) . '">View league</a></p>'];
        } else {
            [$subj, $sms, $html] = ['Request declined — ' . $lname, 'Your request to join "' . $lname . '" was declined.',
                '<p>Your request to join <strong>' . htmlspecialchars($lname) . '</strong> was declined.</p>'];
        }
        send_notification((string)($who['username'] ?? ''), (string)($who['email'] ?? ''), (string)($who['phone'] ?? ''), (string)($who['preferred_contact'] ?? 'email'), $subj, $sms, $html);
    }
    db_log_activity((int)$actor['id'], "$status join request id=$request_id (league id=$lid, user #$target)");
    return gn_ok(['league_id' => $lid, 'request_id' => $request_id, 'name' => (string)($who['username'] ?? ''), 'status' => $status]);
}

/** A league's posts, newest first, pinned ones on top (members). $args: limit (default 20, at most 50), offset. */
function gn_league_posts(PDO $db, array $actor, int $league_id, array $args = []): array {
    $l = gn_league_guard($db, $actor, $league_id);
    if (isset($l['ok'])) return $l;
    $limit = max(1, min(50, (int)($args['limit'] ?? 20))); $offset = max(0, (int)($args['offset'] ?? 0));
    $st = $db->prepare("SELECT p.id, p.title, p.content, p.pinned, p.created_at, u.username AS author
                        FROM posts p LEFT JOIN users u ON u.id = p.author_id
                        WHERE p.league_id = ? AND p.hidden = 0 AND p.is_rules_post = 0 AND p.created_at <= CURRENT_TIMESTAMP
                        ORDER BY p.pinned DESC, p.created_at DESC, p.id DESC LIMIT $limit OFFSET $offset");
    $st->execute([$league_id]);
    $out = array_map('gn_post_shape', $st->fetchAll(PDO::FETCH_ASSOC));
    return gn_ok(['league_id' => $league_id, 'count' => count($out), 'limit' => $limit, 'offset' => $offset, 'posts' => $out]);
}

function gn_post_shape(array $p): array {
    return ['id' => (int)$p['id'], 'title' => (string)$p['title'],
            'text' => trim(html_entity_decode(strip_tags(preg_replace(['#<br\s*/?>\r?\n?#i', '#</(p|div|li|h[1-6])>\s*#i'], "\n", (string)$p['content'])), ENT_QUOTES | ENT_HTML5)),
            'html' => (string)$p['content'], 'pinned' => (int)($p['pinned'] ?? 0) === 1,
            'author' => $p['author'] ?? null, 'posted_at' => gn_iso($p['created_at'] ?? null)];
}

/** Post to a league (owner or manager). $body: title, body (text or simple HTML). Nobody is notified; it appears in the league's feed. */
function gn_league_post_create(PDO $db, array $actor, int $league_id, array $body): array {
    $l = gn_league_guard($db, $actor, $league_id);
    if (isset($l['ok'])) return $l;
    if (!user_can_author_league_post($db, $league_id, (int)$actor['id'], (bool)$actor['is_admin'])) {
        return gn_err('not_league_manager', 'Only the league\'s owner or a manager can post.', 403);
    }
    $bad = array_diff(array_keys($body), ['title', 'body']);
    if ($bad) return gn_err('unknown_field', 'Unknown field: ' . implode(', ', $bad) . '.', 400);
    $title = trim((string)($body['title'] ?? '')); $raw = (string)($body['body'] ?? '');
    if ($title === '' || mb_strlen($title) > 200) return gn_err('bad_field', 'A title of up to 200 characters is required.', 400);
    $clean = sanitize_html($raw);
    if (trim(strip_tags($clean)) === '') return gn_err('bad_field', 'A post needs some text.', 400);
    $db->prepare('INSERT INTO posts (title, content, league_id, author_id, pinned, hidden, is_rules_post, created_at) VALUES (?, ?, ?, ?, 0, 0, 0, CURRENT_TIMESTAMP)')
       ->execute([$title, $clean, $league_id, (int)$actor['id']]);
    $pid = (int)$db->lastInsertId();
    db_log_activity((int)$actor['id'], "created league post id=$pid league=$league_id");
    return gn_ok(['league_id' => $league_id, 'post_id' => $pid, 'title' => $title,
                  'url' => rtrim(get_site_url(), '/') . '/league.php?id=' . $league_id . '&tab=posts']);
}
