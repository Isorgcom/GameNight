<?php
/**
 * Service layer: events and guest lists. Read side.
 *
 * What an actor can see follows event_visibility_sql() exactly, the rule the
 * calendar uses. Being able to see an event is not being its host: contact
 * details on the guest list are for the host alone, and a guest list the host
 * hid stays hidden from everyone else.
 */

require_once __DIR__ . '/common.php';

/** The actor's relation to each of some events: [event_id => 'host'|'guest'|'viewer']. */
function gn_event_roles(PDO $db, array $actor, array $event_ids): array {
    $out = [];
    if (!$event_ids) return $out;
    $ph = implode(',', array_fill(0, count($event_ids), '?'));
    $st = $db->prepare("SELECT event_id FROM event_invites WHERE user_id = ? AND occurrence_date IS NULL AND event_id IN ($ph)");
    $st->execute(array_merge([(int)$actor['id']], $event_ids));
    $invited = array_flip(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)));
    foreach ($event_ids as $id) {
        $id = (int)$id;
        $out[$id] = gn_can_manage_event($db, $actor, $id) ? 'host' : (isset($invited[$id]) ? 'guest' : 'viewer');
    }
    return $out;
}

function gn_event_shape(array $r, array $counts, string $my_role, ?string $my_rsvp): array {
    return [
        'id'          => (int)$r['id'],
        'title'       => (string)$r['title'],
        'description' => (string)($r['description'] ?? ''),
        'location'    => (string)($r['location'] ?? ''),
        'venue_name'  => (string)($r['venue_name'] ?? ''),
        'start_at'    => gn_iso_local($r['start_date'] ?? '', $r['start_time'] ?? ''),
        'end_at'      => gn_iso_local($r['end_date'] ?? '', $r['end_time'] ?? ''),
        'color'       => (string)($r['color'] ?? ''),
        'is_poker'    => (int)$r['is_poker'] === 1,
        'league_id'   => !empty($r['league_id']) ? (int)$r['league_id'] : null,
        'visibility'  => (string)($r['visibility'] ?? ''),
        'requires_approval' => (int)($r['requires_approval'] ?? 0) === 1,
        'waitlist_enabled'  => (int)($r['waitlist_enabled'] ?? 0) === 1,
        'rsvp' => ['yes' => (int)($counts['yes'] ?? 0), 'no' => (int)($counts['no'] ?? 0), 'maybe' => (int)($counts['maybe'] ?? 0)],
        'my_role'     => $my_role,      // host | guest | viewer
        'my_rsvp'     => $my_rsvp,      // yes | no | maybe | null
        'cancelled_at' => gn_iso($r['cancelled_at'] ?? null),
        'created_at'   => gn_iso($r['created_at'] ?? null),
        'url'         => rtrim(get_site_url(), '/') . '/event.php?id=' . (int)$r['id'],
    ];
}

/**
 * Events the actor can see, in a date window.
 * $args: from, to (YYYY-MM-DD in the site timezone; default today to +90 days,
 *        at most 366 days apart), league_id, role = any|host|guest,
 *        include_cancelled (bool).
 */
function gn_events_list(PDO $db, array $actor, array $args = []): array {
    $tz    = new DateTimeZone(get_setting('timezone', 'UTC'));
    $today = new DateTime('now', $tz);
    $from = trim((string)($args['from'] ?? '')) ?: $today->format('Y-m-d');
    $to   = trim((string)($args['to'] ?? ''))   ?: (clone $today)->modify('+90 days')->format('Y-m-d');
    foreach (['from' => $from, 'to' => $to] as $k => $v) {
        $d = DateTime::createFromFormat('!Y-m-d', $v);
        if (!$d || $d->format('Y-m-d') !== $v) return gn_err('bad_date', "\"$k\" must be YYYY-MM-DD.", 400);
    }
    if ($from > $to) return gn_err('bad_window', '"from" must be on or before "to".', 400);
    if ((strtotime($to) - strtotime($from)) / 86400 > 366) return gn_err('bad_window', 'The window cannot exceed 366 days.', 400);
    $role = (string)($args['role'] ?? 'any');
    if (!in_array($role, ['any', 'host', 'guest'], true)) return gn_err('bad_role', 'role must be any, host or guest.', 400);

    $vis = $actor['is_admin'] ? ['sql' => '1=1', 'params' => []] : event_visibility_sql('e', (int)$actor['id']);
    $sql = "SELECT e.* FROM events e
            WHERE {$vis['sql']} AND e.start_date <= ? AND COALESCE(e.end_date, e.start_date) >= ?";
    $params = array_merge($vis['params'], [$to, $from]);
    if (empty($args['include_cancelled'])) $sql .= ' AND e.cancelled_at IS NULL';
    if (!empty($args['league_id'])) { $sql .= ' AND e.league_id = ?'; $params[] = (int)$args['league_id']; }
    $sql .= " ORDER BY e.start_date, COALESCE(e.start_time, '00:00'), e.id LIMIT 500";
    $st = $db->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    $ids   = array_map(fn($r) => (int)$r['id'], $rows);
    $roles = gn_event_roles($db, $actor, $ids);
    [$counts, $mine] = gn_event_rsvp_counts($db, $actor, $ids);

    $events = [];
    foreach ($rows as $r) {
        $id = (int)$r['id'];
        if ($role !== 'any' && $roles[$id] !== $role) continue;
        $events[] = gn_event_shape($r, $counts[$id] ?? [], $roles[$id], $mine[$id] ?? null);
    }
    return gn_ok(['from' => $from, 'to' => $to, 'count' => count($events), 'events' => $events]);
}

/** RSVP totals per event (approved rows only) and the actor's own answer. */
function gn_event_rsvp_counts(PDO $db, array $actor, array $ids): array {
    $counts = []; $mine = [];
    if (!$ids) return [$counts, $mine];
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $cs = $db->prepare("SELECT event_id, rsvp, COUNT(*) AS n FROM event_invites
                        WHERE event_id IN ($ph) AND occurrence_date IS NULL
                          AND approval_status = 'approved' AND rsvp IN ('yes','no','maybe')
                        GROUP BY event_id, rsvp");
    $cs->execute($ids);
    foreach ($cs->fetchAll(PDO::FETCH_ASSOC) as $c) $counts[(int)$c['event_id']][$c['rsvp']] = (int)$c['n'];
    $ms = $db->prepare("SELECT event_id, rsvp FROM event_invites
                        WHERE user_id = ? AND occurrence_date IS NULL AND event_id IN ($ph)");
    $ms->execute(array_merge([(int)$actor['id']], $ids));
    foreach ($ms->fetchAll(PDO::FETCH_ASSOC) as $m) {
        $mine[(int)$m['event_id']] = in_array($m['rsvp'], ['yes', 'no', 'maybe'], true) ? $m['rsvp'] : null;
    }
    return [$counts, $mine];
}

/** One event the actor can see, with capacity and (for a poker event) a one-line game summary. */
function gn_event_get(PDO $db, array $actor, int $event_id): array {
    $r = gn_event_visible($db, $actor, $event_id);
    if (!$r) return gn_err('event_not_found', 'No such event.', 404);
    $roles = gn_event_roles($db, $actor, [$event_id]);
    [$counts, $mine] = gn_event_rsvp_counts($db, $actor, [$event_id]);
    $out = gn_event_shape($r, $counts[$event_id] ?? [], $roles[$event_id], $mine[$event_id] ?? null);

    $cap = event_capacity($db, $event_id);
    $out['capacity'] = (int)$cap['capacity'] > 0 ? (int)$cap['capacity'] : null;
    $out['rsvp_deadline_hours'] = $r['rsvp_deadline_hours'] !== null ? (int)$r['rsvp_deadline_hours'] : null;
    $out['guest_list_hidden']   = (int)($r['hide_guest_list'] ?? 0) === 1;
    if (!empty($r['league_id'])) {
        $l = $db->prepare('SELECT name FROM leagues WHERE id = ?');
        $l->execute([(int)$r['league_id']]);
        $out['league_name'] = $l->fetchColumn() ?: null;
    }
    $out['game'] = null;
    if ((int)$r['is_poker'] === 1) {
        $g = $db->prepare('SELECT status, game_type, buyin_amount, num_tables, seats_per_table FROM poker_sessions WHERE event_id = ?');
        $g->execute([$event_id]);
        if ($s = $g->fetch(PDO::FETCH_ASSOC)) {
            $out['game'] = [
                'status' => (string)$s['status'], 'game_type' => (string)($s['game_type'] ?? 'tournament'),
                'buyin_cents' => (int)$s['buyin_amount'], 'tables' => (int)$s['num_tables'],
                'seats_per_table' => (int)($s['seats_per_table'] ?? 0),
            ];
        }
    }
    return gn_ok(['event' => $out]);
}

/**
 * The guest list. The host sees everyone with invite state, and the contact
 * details of guests they typed in by hand. Anyone else who can see the event gets names and answers only, and
 * nothing at all when the host has hidden the list.
 */
function gn_event_invites(PDO $db, array $actor, int $event_id): array {
    $ev = gn_event_visible($db, $actor, $event_id);
    if (!$ev) return gn_err('event_not_found', 'No such event.', 404);
    $is_host = gn_can_manage_event($db, $actor, $event_id);
    if (!$is_host && (int)($ev['hide_guest_list'] ?? 0) === 1) {
        return gn_err('guest_list_hidden', 'The host has hidden the guest list for this event.', 403);
    }
    $st = $db->prepare(
        "SELECT ei.id, ei.user_id, ei.username, ei.email, ei.phone, ei.rsvp, ei.approval_status,
                ei.event_role, ei.sort_order, ei.join_source
           FROM event_invites ei
          WHERE ei.event_id = ? AND ei.occurrence_date IS NULL
          ORDER BY COALESCE(ei.sort_order, 999999), LOWER(ei.username)");
    $st->execute([$event_id]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $i) {
        $status = (string)($i['approval_status'] ?? 'approved');
        // A guest sees the people who are actually on the list, not requests
        // the host has yet to decide or has turned down.
        if (!$is_host && $status !== 'approved') continue;
        $row = [
            'invite_id' => (int)$i['id'],
            'user_id'   => $i['user_id'] !== null ? (int)$i['user_id'] : null,
            'name'      => (string)$i['username'],
            'rsvp'      => in_array($i['rsvp'], ['yes', 'no', 'maybe'], true) ? $i['rsvp'] : null,
            'role'      => (string)($i['event_role'] ?? 'invitee'),   // invitee | manager (co-host)
        ];
        if ($is_host) {
            $row['status']      = $status;                            // approved | pending | waitlisted | denied
            $row['has_account'] = $i['user_id'] !== null;
            // Contact details are given only for a guest the host typed in
            // by hand. Someone with an account keeps theirs to themselves:
            // the site reaches them, the host never needs the address.
            $row['email']       = $i['user_id'] === null ? ($i['email'] ?: null) : null;
            $row['phone']       = $i['user_id'] === null ? ($i['phone'] ?: null) : null;
            $row['joined_via']  = $i['join_source'] ?: null;
        }
        $out[] = $row;
    }
    return gn_ok(['event_id' => $event_id, 'is_host' => $is_host, 'count' => count($out), 'invites' => $out]);
}

// ═════════════════════════════════════════════════════════════════════════════
// Write side. Creating and editing go through event_save() (_event_save.php),
// the same function the event editor posts to, so an event made here is
// indistinguishable from one made on the site.
// ═════════════════════════════════════════════════════════════════════════════

require_once __DIR__ . '/../_event_save.php';
require_once __DIR__ . '/../_notifications.php';

const GN_EVENT_COLORS = ['#2563eb', '#16a34a', '#dc2626', '#d97706', '#7c3aed', '#0891b2', '#db2777'];
/** Every field a create or update body may carry. Anything else is refused by name. */
const GN_EVENT_FIELDS = ['title', 'description', 'location', 'venue_name', 'color', 'start_at', 'end_at',
    'league_id', 'visibility', 'is_poker', 'poker', 'requires_approval', 'hide_guest_list',
    'waitlist_enabled', 'max_guests', 'rsvp_deadline_hours', 'reminders_enabled', 'reminder_minutes'];

/** The users row event_save() expects for the actor. */
function gn_actor_user(PDO $db, array $actor): array {
    $st = $db->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([(int)$actor['id']]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: ['id' => (int)$actor['id']];
}

/** A stored (site timezone) date and time as the actor's own wall clock, the form event_save() takes. */
function gn_site_to_viewer(array $actor, ?string $date, ?string $time): array {
    if (!$date || !$time) return [$date ?: null, null];
    $dt = new DateTime($date . ' ' . substr($time, 0, 5), new DateTimeZone(get_setting('timezone', 'UTC')));
    $dt->setTimezone(new DateTimeZone(display_timezone((int)$actor['id'])));
    return [$dt->format('Y-m-d'), $dt->format('H:i')];
}

/**
 * Check a create/update body and turn it into editor field names. Only the
 * keys present in $body appear in the result, so an update can overlay them
 * on the stored event. Returns ['in' => [...], 'opts' => [...]] or a failure.
 */
function gn_event_body_to_input(PDO $db, array $actor, array $body): array {
    $unknown = array_diff(array_keys($body), GN_EVENT_FIELDS);
    if ($unknown) return gn_err('unknown_field', 'Unknown field: ' . implode(', ', $unknown) . '.', 400);
    $in = []; $opts = [];

    foreach (['title' => 200, 'description' => 5000, 'location' => 200, 'venue_name' => 120] as $k => $max) {
        if (!array_key_exists($k, $body)) continue;
        if ($body[$k] !== null && !is_string($body[$k])) return gn_err('bad_field', "\"$k\" must be text.", 400);
        $v = trim((string)$body[$k]);
        if (mb_strlen($v) > $max) return gn_err('bad_field', "\"$k\" is longer than $max characters.", 400);
        $in[$k] = $v;
    }
    if (array_key_exists('title', $in) && $in['title'] === '') return gn_err('bad_field', 'An event needs a title.', 400);
    if (array_key_exists('color', $body)) {
        if (!in_array($body['color'], GN_EVENT_COLORS, true)) return gn_err('bad_field', 'color must be one of ' . implode(', ', GN_EVENT_COLORS) . '.', 400);
        $in['color'] = $body['color'];
    }

    // start_at / end_at: an instant with an offset or Z, or a bare date for an all-day event.
    foreach (['start_at' => 'start', 'end_at' => 'end'] as $k => $pre) {
        if (!array_key_exists($k, $body)) continue;
        if ($body[$k] === null || $body[$k] === '') {
            if ($k === 'start_at') return gn_err('bad_field', 'start_at cannot be empty.', 400);
            $in['end_date'] = ''; $in['end_time'] = '';
            continue;
        }
        $parsed = is_string($body[$k]) ? api_parse_inbound_at($body[$k], new DateTimeZone(get_setting('timezone', 'UTC'))) : null;
        if (!$parsed) return gn_err('bad_date', "\"$k\" must be YYYY-MM-DD, or a time with an offset such as 2026-12-12T19:00:00-06:00.", 400);
        [$d, $t] = gn_site_to_viewer($actor, $parsed[0], $parsed[1]);
        $in[$pre . '_date'] = $d; $in[$pre . '_time'] = $t ?? '';
    }

    if (array_key_exists('league_id', $body)) {
        $lid = $body['league_id'] === null ? 0 : (int)$body['league_id'];
        if ($lid > 0 && !$actor['is_admin'] && !in_array(league_role($lid, (int)$actor['id']), ['owner', 'manager'], true)) {
            return gn_err('not_league_manager', 'Only a league\'s owner or a manager can put an event on its calendar.', 403);
        }
        $in['league_id'] = $lid;
    }
    if (array_key_exists('visibility', $body)) {
        if (!in_array($body['visibility'], ['invitees_only', 'league', 'public'], true)) return gn_err('bad_field', 'visibility must be invitees_only, league or public.', 400);
        if ($body['visibility'] === 'public' && !$actor['is_admin']) return gn_err('public_needs_admin', 'Only a site admin can make an event public.', 403);
        $in['visibility'] = $body['visibility'];
    }
    foreach (['is_poker', 'requires_approval', 'hide_guest_list', 'waitlist_enabled', 'reminders_enabled'] as $k) {
        if (!array_key_exists($k, $body)) continue;
        if (!is_bool($body[$k])) return gn_err('bad_field', "\"$k\" must be true or false.", 400);
        $in[$k] = $body[$k] ? '1' : '';
    }
    if (array_key_exists('max_guests', $body)) {
        $mg = $body['max_guests'] === null ? 0 : $body['max_guests'];
        if (!is_int($mg) || $mg < 0 || $mg > 999) return gn_err('bad_field', 'max_guests must be a whole number from 0 to 999 (0 or null for no limit).', 400);
        $in['max_guests'] = $mg;
    }
    if (array_key_exists('rsvp_deadline_hours', $body)) {
        $h = $body['rsvp_deadline_hours'] === null ? 0 : $body['rsvp_deadline_hours'];
        if (!is_int($h) || $h < 0 || $h > 720) return gn_err('bad_field', 'rsvp_deadline_hours must be a whole number from 0 to 720 (0 or null for none).', 400);
        $in['rsvp_deadline_hours'] = $h;
    }
    if (array_key_exists('reminder_minutes', $body)) {
        if ($body['reminder_minutes'] === null) {
            $opts['reminder_offsets_json'] = null;          // the site's default schedule
        } elseif (is_array($body['reminder_minutes']) && array_is_list($body['reminder_minutes'])) {
            $opts['reminder_offsets_json'] = json_encode(reminder_offsets_clean($body['reminder_minutes']));
        } else {
            return gn_err('bad_field', 'reminder_minutes must be a list of minutes before the event, or null for the site default.', 400);
        }
    }
    if (array_key_exists('poker', $body)) {
        $p = $body['poker'];
        if (!is_array($p)) return gn_err('bad_field', '"poker" must be an object.', 400);
        $bad = array_diff(array_keys($p), ['game_type', 'buyin_cents', 'tables', 'seats_per_table']);
        if ($bad) return gn_err('unknown_field', 'Unknown field in poker: ' . implode(', ', $bad) . '.', 400);
        if (isset($p['game_type'])) {
            if (!in_array($p['game_type'], ['tournament', 'cash'], true)) return gn_err('bad_field', 'poker.game_type must be tournament or cash.', 400);
            $in['poker_game_type'] = $p['game_type'];
        }
        if (isset($p['buyin_cents'])) {
            if (!is_int($p['buyin_cents']) || $p['buyin_cents'] < 0 || $p['buyin_cents'] > 100000000) return gn_err('bad_field', 'poker.buyin_cents must be a whole number of cents.', 400);
            $in['poker_buyin'] = $p['buyin_cents'] / 100;    // the editor's field is dollars
        }
        if (isset($p['tables'])) {
            if (!is_int($p['tables']) || $p['tables'] < 1 || $p['tables'] > 50) return gn_err('bad_field', 'poker.tables must be from 1 to 50.', 400);
            $in['poker_tables'] = $p['tables'];
        }
        if (isset($p['seats_per_table'])) {
            if (!is_int($p['seats_per_table']) || $p['seats_per_table'] < 2 || $p['seats_per_table'] > 12) return gn_err('bad_field', 'poker.seats_per_table must be from 2 to 12.', 400);
            $in['poker_seats'] = $p['seats_per_table'];
        }
    }
    return ['in' => $in, 'opts' => $opts];
}

/**
 * Turn a list of guests from a request into editor invite rows.
 * A guest is {"user_id": 12} (someone you can already name: a member of a
 * league you are in, one of your contacts, yourself) or {"name": "Dave",
 * "email": ..., "phone": ...} for someone typed in by hand; either may carry
 * "role": "manager" to make them a co-host.
 * Returns ['rows' => [[name, email, phone, role], ...]] or a failure.
 */
function gn_guests_resolve(PDO $db, array $actor, $guests): array {
    if (!is_array($guests) || !array_is_list($guests)) return gn_err('bad_field', '"guests" must be a list.', 400);
    if (count($guests) > MAX_INVITEES_PER_EVENT) return gn_err('too_many_guests', 'At most ' . MAX_INVITEES_PER_EVENT . ' guests per event.', 400);
    $rows = []; $seen = [];
    foreach ($guests as $i => $g) {
        if (!is_array($g)) return gn_err('bad_field', "guests[$i] must be an object.", 400);
        $bad = array_diff(array_keys($g), ['user_id', 'name', 'email', 'phone', 'role']);
        if ($bad) return gn_err('unknown_field', "Unknown field in guests[$i]: " . implode(', ', $bad) . '.', 400);
        $role = $g['role'] ?? 'invitee';
        if (!in_array($role, ['invitee', 'manager'], true)) return gn_err('bad_field', "guests[$i].role must be invitee or manager.", 400);
        if (isset($g['user_id'])) {
            $uid = (int)$g['user_id'];
            $u = $db->prepare('SELECT id, username FROM users WHERE id = ?');
            $u->execute([$uid]);
            $row = $u->fetch(PDO::FETCH_ASSOC);
            // Someone the actor cannot already name is "no such person": an id
            // is not a way to find out who has an account.
            if (!$row || !gn_can_name_user($db, $actor, $uid)) return gn_err('guest_not_found', "guests[$i]: no such person among your leagues or contacts.", 404);
            $name = (string)$row['username']; $email = ''; $phone = '';
        } else {
            $name = trim((string)($g['name'] ?? ''));
            if ($name === '' || mb_strlen($name) > 60) return gn_err('bad_field', "guests[$i] needs a name of up to 60 characters, or a user_id.", 400);
            $email = trim((string)($g['email'] ?? '')); $phone = trim((string)($g['phone'] ?? ''));
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) return gn_err('bad_field', "guests[$i].email is not an email address.", 400);
            if ($phone !== '' && normalize_phone($phone) === '') return gn_err('bad_field', "guests[$i].phone is not a phone number.", 400);
        }
        $key = strtolower($name);
        if (isset($seen[$key])) continue;       // the same person twice is one guest
        $seen[$key] = true;
        $rows[] = [$name, $email, $phone, $role];
    }
    return ['rows' => $rows];
}

/** May the actor refer to this account by id: themselves, a co-member of a league, or one of their contacts. */
function gn_can_name_user(PDO $db, array $actor, int $user_id): bool {
    if ($actor['is_admin'] || $user_id === (int)$actor['id']) return true;
    $q = $db->prepare('SELECT 1 FROM league_members a JOIN league_members b ON b.league_id = a.league_id
                       WHERE a.user_id = ? AND b.user_id = ? LIMIT 1');
    $q->execute([(int)$actor['id'], $user_id]);
    if ($q->fetchColumn()) return true;
    $c = $db->prepare('SELECT 1 FROM user_contacts WHERE owner_user_id = ? AND linked_user_id = ? LIMIT 1');
    $c->execute([(int)$actor['id'], $user_id]);
    return (bool)$c->fetchColumn();
}

/** A failure when the event (editor fields, actor's timezone) would end before it starts, else null. */
function gn_event_end_before_start(array $actor, array $in): ?array {
    $sd = (string)($in['start_date'] ?? ''); $ed = (string)($in['end_date'] ?? '');
    $st = (string)($in['start_time'] ?? ''); $et = (string)($in['end_time'] ?? '');
    if ($sd === '' || ($ed === '' && $et === '')) return null;
    $start = $sd . ' ' . ($st !== '' ? $st : '00:00');
    $end   = ($ed !== '' ? $ed : $sd) . ' ' . ($et !== '' ? $et : '23:59');
    return $end < $start ? gn_err('bad_field', 'The event would end before it starts. Check end_at.', 400) : null;
}

/**
 * Create an event. $body: title and start_at are required; see GN_EVENT_FIELDS,
 * plus "guests" (see gn_guests_resolve). Invitations are NOT sent by creating:
 * that is gn_invitations_send(), a separate permission.
 */
function gn_event_create(PDO $db, array $actor, array $body): array {
    if (!$actor['is_admin'] && get_setting('allow_user_events', '0') !== '1') {
        return gn_err('events_not_allowed', 'This site only lets its admins create events.', 403);
    }
    $guests = $body['guests'] ?? []; unset($body['guests']);
    $x = gn_event_body_to_input($db, $actor, $body);
    if (isset($x['ok'])) return $x;
    $in = $x['in'];
    if (!isset($in['title']))      return gn_err('bad_field', 'An event needs a title.', 400);
    if (!isset($in['start_date'])) return gn_err('bad_field', 'An event needs start_at.', 400);
    if ($e = gn_event_end_before_start($actor, $in)) return $e;
    $g = gn_guests_resolve($db, $actor, $guests);
    if (isset($g['ok'])) return $g;

    $in += ['action' => 'add', 'visibility' => !empty($in['league_id']) ? 'league' : 'invitees_only',
            'reminders_enabled' => '1', 'waitlist_enabled' => '1'];
    foreach ($g['rows'] as $i => [$name, $email, $phone, $role]) {
        $in['invite_username'][] = $name; $in['invite_email'][] = $email; $in['invite_phone'][] = $phone;
        $in['invite_rsvp'][] = ''; $in['invite_role'][] = $role; $in['invite_sort_order'][] = $i + 1;
    }
    // Left out, the reminder schedule is the site's default (stored as NULL).
    $opts = $x['opts'] + ['reminder_offsets_json' => null];

    $db->beginTransaction();
    try {
        $res = event_save($db, gn_actor_user($db, $actor), (bool)$actor['is_admin'], get_setting('allow_maybe_rsvp', '1') === '1', $in, $opts);
        if (empty($res['ok'])) { $db->rollBack(); return gn_err('event_not_saved', (string)$res['error'], 400); }
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    return gn_event_get($db, $actor, (int)$res['event_id']) + ['created' => true];
}

/** The stored event as editor field names, in the actor's timezone: what the edit form would post unchanged. */
function gn_event_stored_input(PDO $db, array $actor, array $ev): array {
    [$sd, $st] = gn_site_to_viewer($actor, $ev['start_date'], $ev['start_time']);
    if (!empty($ev['end_time'])) [$ed, $et] = gn_site_to_viewer($actor, $ev['end_date'] ?: $ev['start_date'], $ev['end_time']);
    else { $ed = $ev['end_date'] ?: ''; $et = ''; }
    $in = [
        'action' => 'edit', 'id' => (int)$ev['id'],
        'title' => (string)$ev['title'], 'description' => (string)($ev['description'] ?? ''),
        'location' => (string)($ev['location'] ?? ''), 'venue_name' => (string)($ev['venue_name'] ?? ''),
        'color' => (string)$ev['color'],
        'start_date' => $sd, 'start_time' => $st ?? '', 'end_date' => $ed ?? '', 'end_time' => $et ?? '',
        'league_id' => (int)($ev['league_id'] ?? 0), 'visibility' => (string)$ev['visibility'],
        'max_guests' => (int)($ev['max_guests'] ?? 0), 'rsvp_deadline_hours' => (int)($ev['rsvp_deadline_hours'] ?? 0),
    ];
    foreach (['is_poker', 'requires_approval', 'hide_guest_list', 'waitlist_enabled', 'reminders_enabled'] as $k) {
        $in[$k] = (int)($ev[$k] ?? 0) === 1 ? '1' : '';
    }
    $ps = $db->prepare('SELECT game_type, buyin_amount, num_tables, seats_per_table FROM poker_sessions WHERE event_id = ?');
    $ps->execute([(int)$ev['id']]);
    if ($s = $ps->fetch(PDO::FETCH_ASSOC)) {
        $in += ['poker_game_type' => (string)($s['game_type'] ?? 'tournament'), 'poker_buyin' => (int)$s['buyin_amount'] / 100,
                'poker_tables' => (int)$s['num_tables'], 'poker_seats' => (int)($s['seats_per_table'] ?: 8)];
    }
    return $in;
}

/**
 * Change an event. Only the fields in $body change; the guest list is never
 * touched here (see the invite functions). Reminders are re-timed when the
 * start moves; guests are not otherwise told, exactly as on the site.
 */
function gn_event_update(PDO $db, array $actor, int $event_id, array $body): array {
    $ev = gn_event_guard($db, $actor, $event_id);
    if (isset($ev['ok'])) return $ev;
    if (isset($body['guests'])) return gn_err('unknown_field', 'The guest list is changed through /invites, not here.', 400);
    if (!$body) return gn_err('nothing_to_change', 'Send at least one field to change.', 400);
    $x = gn_event_body_to_input($db, $actor, $body);
    if (isset($x['ok'])) return $x;

    $stored = gn_event_stored_input($db, $actor, $ev);
    $in     = $x['in'] + $stored;
    // Moving the start without naming an end moves the end with it, so the
    // event keeps its length instead of ending before it begins.
    if (array_key_exists('start_at', $body) && !array_key_exists('end_at', $body)
        && $stored['end_time'] !== '' && $stored['start_time'] !== '' && $in['start_time'] !== '') {
        $tz   = new DateTimeZone(display_timezone((int)$actor['id']));
        $from = new DateTime($stored['start_date'] . ' ' . $stored['start_time'], $tz);
        $to   = new DateTime(($stored['end_date'] ?: $stored['start_date']) . ' ' . $stored['end_time'], $tz);
        $len  = $to->getTimestamp() - $from->getTimestamp();
        if ($len > 0) {
            $end = (new DateTime($in['start_date'] . ' ' . $in['start_time'], $tz))->modify("+$len seconds");
            $in['end_date'] = $end->format('Y-m-d'); $in['end_time'] = $end->format('H:i');
        } else {
            $in['end_date'] = ''; $in['end_time'] = '';
        }
    }
    if ($e = gn_event_end_before_start($actor, $in)) return $e;
    $opts = $x['opts'] + ['reminder_offsets_json' => $ev['reminder_offsets'], 'keep_invites' => true];
    $opts['keep_invites'] = true;

    $db->beginTransaction();
    try {
        $res = event_save($db, gn_actor_user($db, $actor), (bool)$actor['is_admin'], get_setting('allow_maybe_rsvp', '1') === '1', $in, $opts);
        if (empty($res['ok'])) { $db->rollBack(); return gn_err('event_not_saved', (string)$res['error'], 400); }
        // The editor demotes a public event to invitees-only when someone
        // other than an admin saves it. A change that did not mention
        // visibility must not do that behind the caller's back.
        if (!array_key_exists('visibility', $body) && !array_key_exists('league_id', $body)) {
            $db->prepare('UPDATE events SET visibility = ? WHERE id = ?')->execute([(string)$ev['visibility'], $event_id]);
        }
        // Reported back so the caller can decide whether to tell the guests
        // (the editor does not announce a time change by itself either).
        $now = $db->prepare('SELECT start_date, start_time FROM events WHERE id = ?');
        $now->execute([$event_id]);
        $n = $now->fetch(PDO::FETCH_ASSOC);
        $moved = $n['start_date'] !== $ev['start_date'] || (string)($n['start_time'] ?? '') !== (string)($ev['start_time'] ?? '');
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    return gn_event_get($db, $actor, $event_id) + ['updated' => true, 'time_changed' => $moved];
}

/** Cancel and keep: every guest is told it is off, the event stays so it can be duplicated. */
function gn_event_cancel(PDO $db, array $actor, int $event_id): array {
    $ev = gn_event_guard($db, $actor, $event_id, true);
    if (isset($ev['ok'])) return $ev;
    $res = cancel_event_keep($db, $event_id, (int)$actor['id']);
    if ($res === null) return gn_err('event_not_found', 'No such event.', 404);
    return gn_ok(['event_id' => $event_id, 'title' => (string)$res['title'], 'cancelled' => true,
                  'already_cancelled' => !empty($res['already']), 'guests_notified' => (int)$res['notified']]);
}

/**
 * Delete an event for good: its guest list, messages, comments and game go
 * with it, and guests of an upcoming event are told unless notify is false
 * (a silent delete). Needs confirm: true.
 */
function gn_event_delete(PDO $db, array $actor, int $event_id, array $body): array {
    $ev = gn_event_guard($db, $actor, $event_id, true);
    if (isset($ev['ok'])) return $ev;
    $bad = array_diff(array_keys($body), ['confirm', 'notify']);
    if ($bad) return gn_err('unknown_field', 'Unknown field: ' . implode(', ', $bad) . '.', 400);
    if (array_key_exists('notify', $body) && !is_bool($body['notify'])) return gn_err('bad_field', 'notify must be true or false.', 400);
    $notify = ($body['notify'] ?? true) !== false;
    if (($body['confirm'] ?? null) !== true) {
        $n = $db->prepare('SELECT COUNT(*) FROM event_invites WHERE event_id = ? AND occurrence_date IS NULL');
        $n->execute([$event_id]);
        $tz = new DateTimeZone(get_setting('timezone', 'UTC'));
        $wouldTell = $notify && empty($ev['cancelled_at']) && (string)$ev['start_date'] >= (new DateTime('now', $tz))->format('Y-m-d');
        return gn_err('confirmation_required', 'Deleting "' . $ev['title'] . '" removes it for good, with its guest list (' . (int)$n->fetchColumn()
            . '), messages, comments and any game results. It cannot be undone. '
            . ($wouldTell ? 'Every guest is told it is cancelled; send "notify": false to delete without telling anyone. '
                          : 'Nobody is notified. ')
            . 'To keep the record and only call it off, use cancel. Send {"confirm": true} to delete.', 409);
    }
    $db->beginTransaction();
    try {
        $db->prepare('DELETE FROM pending_notifications WHERE event_id = ?')->execute([$event_id]);
        $sids = $db->prepare('SELECT id FROM poker_sessions WHERE event_id = ?');
        $sids->execute([$event_id]);
        foreach ($sids->fetchAll(PDO::FETCH_COLUMN) as $sid) $db->prepare('DELETE FROM timer_state WHERE session_id = ?')->execute([(int)$sid]);
        $res = cancel_event_with_notifications($db, $event_id, (int)$actor['id'], $notify);
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    if ($res === null) return gn_err('event_not_found', 'No such event.', 404);
    if ((int)$res['notified'] > 0) drain_queue_async();
    return gn_ok(['event_id' => $event_id, 'title' => (string)$res['title'], 'deleted' => true, 'guests_notified' => (int)$res['notified']]);
}

/**
 * A new event copied from one you host: its details, game settings and guest
 * list, with nobody's answer carried over. $body: start_at (required), and
 * optionally title, end_at.
 */
function gn_event_duplicate(PDO $db, array $actor, int $event_id, array $body): array {
    $ev = gn_event_guard($db, $actor, $event_id, true);
    if (isset($ev['ok'])) return $ev;
    if (!$actor['is_admin'] && get_setting('allow_user_events', '0') !== '1') {
        return gn_err('events_not_allowed', 'This site only lets its admins create events.', 403);
    }
    $bad = array_diff(array_keys($body), ['start_at', 'end_at', 'title']);
    if ($bad) return gn_err('unknown_field', 'Unknown field: ' . implode(', ', $bad) . '.', 400);
    if (empty($body['start_at'])) return gn_err('bad_field', 'A copy needs its own start_at.', 400);
    $x = gn_event_body_to_input($db, $actor, $body);
    if (isset($x['ok'])) return $x;

    $in = gn_event_stored_input($db, $actor, $ev);
    // The copy keeps the original's length when no end is given.
    // Without its own end, the copy has none: an end is a moment, and the
    // original's would land on the wrong day.
    if (!array_key_exists('end_at', $body)) { $in['end_date'] = ''; $in['end_time'] = ''; }
    $in = $x['in'] + $in;
    $in['action'] = 'add'; unset($in['id']);
    if ($e = gn_event_end_before_start($actor, $in)) return $e;
    if ($in['visibility'] === 'public' && !$actor['is_admin']) $in['visibility'] = !empty($in['league_id']) ? 'league' : 'invitees_only';

    $is = $db->prepare("SELECT username, phone, email, event_role FROM event_invites
                        WHERE event_id = ? AND occurrence_date IS NULL AND COALESCE(approval_status, 'approved') <> 'denied'
                        ORDER BY COALESCE(sort_order, 999999), username");
    $is->execute([$event_id]);
    foreach ($is->fetchAll(PDO::FETCH_ASSOC) as $i => $r) {
        $in['invite_username'][] = (string)$r['username']; $in['invite_email'][] = (string)($r['email'] ?? '');
        $in['invite_phone'][] = (string)($r['phone'] ?? ''); $in['invite_rsvp'][] = '';
        $in['invite_role'][] = (string)($r['event_role'] ?: 'invitee'); $in['invite_sort_order'][] = $i + 1;
    }
    $db->beginTransaction();
    try {
        $res = event_save($db, gn_actor_user($db, $actor), (bool)$actor['is_admin'], get_setting('allow_maybe_rsvp', '1') === '1', $in,
                          ['reminder_offsets_json' => $ev['reminder_offsets']]);
        if (empty($res['ok'])) { $db->rollBack(); return gn_err('event_not_saved', (string)$res['error'], 400); }
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    return gn_event_get($db, $actor, (int)$res['event_id']) + ['created' => true, 'copied_from' => $event_id];
}

// ── Guest list ───────────────────────────────────────────────────────────────

/** One base invite row of an event, or null. */
function gn_invite_row(PDO $db, int $event_id, int $invite_id): ?array {
    $st = $db->prepare('SELECT * FROM event_invites WHERE id = ? AND event_id = ? AND occurrence_date IS NULL');
    $st->execute([$invite_id, $event_id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Add guests to an event you host. Someone already on the list is skipped,
 * so repeating the call is harmless. With a waitlist, anyone past capacity
 * lands on it. Nobody is told: invitations go out with gn_invitations_send().
 * $body: {"guests": [...]} (see gn_guests_resolve).
 */
function gn_invites_add(PDO $db, array $actor, int $event_id, array $body): array {
    $ev = gn_event_guard($db, $actor, $event_id);
    if (isset($ev['ok'])) return $ev;
    $bad = array_diff(array_keys($body), ['guests']);
    if ($bad) return gn_err('unknown_field', 'Unknown field: ' . implode(', ', $bad) . '.', 400);
    $g = gn_guests_resolve($db, $actor, $body['guests'] ?? null);
    if (isset($g['ok'])) return $g;
    if (!$g['rows']) return gn_err('bad_field', 'Give at least one guest.', 400);

    $ex = $db->prepare('SELECT LOWER(username) FROM event_invites WHERE event_id = ? AND occurrence_date IS NULL');
    $ex->execute([$event_id]);
    $already = array_flip($ex->fetchAll(PDO::FETCH_COLUMN));
    if (count($already) + count($g['rows']) > MAX_INVITEES_PER_EVENT) {
        return gn_err('too_many_guests', 'That would put more than ' . MAX_INVITEES_PER_EVENT . ' guests on the event.', 400);
    }
    $ms = $db->prepare('SELECT COALESCE(MAX(sort_order), 0) FROM event_invites WHERE event_id = ?');
    $ms->execute([$event_id]);
    $sort = (int)$ms->fetchColumn();
    $league_id = (int)($ev['league_id'] ?? 0);

    $added = []; $skipped = [];
    $db->beginTransaction();
    try {
        $acct = $db->prepare('SELECT id, username FROM users WHERE LOWER(username) = LOWER(?)');
        $ins  = $db->prepare("INSERT INTO event_invites (event_id, username, user_id, phone, email, rsvp, event_role, approval_status, sort_order, rsvp_token)
                              VALUES (?, ?, ?, ?, ?, NULL, ?, 'approved', ?, ?)");
        foreach ($g['rows'] as [$name, $email, $phone, $role]) {
            $canon = canonical_username($name);
            if (isset($already[strtolower($canon)])) { $skipped[] = $canon; continue; }
            // A name that IS an account is linked to it, as the editor does;
            // a typed-in name for someone with no account stays unlinked.
            $acct->execute([$canon]);
            $u = $acct->fetch(PDO::FETCH_ASSOC) ?: null;
            // A guest with an account is reached through it: the row keeps no email
            // or phone (v1.35.0), never a copy of the account's own.
            $phone_raw = $u ? '' : $phone;
            $email_raw = $u ? '' : $email;
            $ins->execute([$event_id, $canon, $u ? (int)$u['id'] : null,
                           ($phone_raw !== '' ? normalize_phone($phone_raw) : '') ?: null, $email_raw ?: null,
                           $role, ++$sort, bin2hex(random_bytes(16))]);
            $added[(int)$db->lastInsertId()] = $canon;
            $already[strtolower($canon)] = true;
            auto_add_contact($db, (int)$actor['id'], $name, $email, $phone);
            if ($league_id) auto_add_pending_to_league($db, $league_id, $name, $email, $phone, (int)$actor['id']);
        }
        // Past capacity goes to the waitlist, then anyone a free seat can take comes back off it.
        $cap = event_capacity($db, $event_id);
        if ($added && $cap['waitlist'] && (int)$cap['capacity'] > 0) {
            $db->prepare("UPDATE event_invites SET approval_status = 'waitlisted'
                          WHERE event_id = ? AND occurrence_date IS NULL AND sort_order > ? AND approval_status = 'approved'")
               ->execute([$event_id, (int)$cap['capacity']]);
            maybe_promote_waitlisted($db, $event_id);
        }
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }

    $out = []; $waitlisted = 0;
    if ($added) {
        $ph = implode(',', array_fill(0, count($added), '?'));
        $st = $db->prepare("SELECT id, username, user_id, approval_status FROM event_invites WHERE id IN ($ph)");
        $st->execute(array_keys($added));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if ($r['approval_status'] === 'waitlisted') $waitlisted++;
            $out[] = ['invite_id' => (int)$r['id'], 'name' => (string)$r['username'], 'has_account' => $r['user_id'] !== null, 'status' => (string)$r['approval_status']];
        }
        db_log_activity((int)$actor['id'], 'added ' . count($added) . " guest(s) to event id: $event_id");
    }
    return gn_ok(['event_id' => $event_id, 'added' => $out, 'already_on_list' => $skipped, 'waitlisted' => $waitlisted,
                  'invitations_sent' => false]);
}

/**
 * Change one guest: their answer ("rsvp": yes | no | maybe | null) and whether
 * they co-host ("role": invitee | manager). A "no" may free a seat for
 * someone on the waitlist.
 */
function gn_invite_update(PDO $db, array $actor, int $event_id, int $invite_id, array $body): array {
    $ev = gn_event_guard($db, $actor, $event_id);
    if (isset($ev['ok'])) return $ev;
    $inv = gn_invite_row($db, $event_id, $invite_id);
    if (!$inv) return gn_err('invite_not_found', 'No such guest on this event.', 404);
    $bad = array_diff(array_keys($body), ['rsvp', 'role']);
    if ($bad) return gn_err('unknown_field', 'Unknown field: ' . implode(', ', $bad) . '.', 400);

    $set = []; $changed = [];
    if (array_key_exists('rsvp', $body)) {
        $r = $body['rsvp'];
        $allowed = get_setting('allow_maybe_rsvp', '1') === '1' ? ['yes', 'no', 'maybe'] : ['yes', 'no'];
        if ($r !== null && !in_array($r, $allowed, true)) return gn_err('bad_field', 'rsvp must be ' . implode(', ', $allowed) . ' or null.', 400);
        if ($r !== ($inv['rsvp'] ?: null)) { $set['rsvp'] = $r; $changed[] = 'rsvp'; }
    }
    if (array_key_exists('role', $body)) {
        if (!in_array($body['role'], ['invitee', 'manager'], true)) return gn_err('bad_field', 'role must be invitee or manager.', 400);
        if ($body['role'] === 'manager' && $inv['user_id'] === null) return gn_err('needs_account', 'Only someone with an account can co-host.', 409);
        if ($body['role'] !== (string)$inv['event_role']) { $set['event_role'] = $body['role']; $changed[] = 'role'; }
    }
    $promoted = 0;
    if ($set) {
        $db->beginTransaction();
        try {
            $cols = implode(', ', array_map(fn($c) => "$c = ?", array_keys($set)));
            $db->prepare("UPDATE event_invites SET $cols WHERE id = ?")->execute(array_merge(array_values($set), [$invite_id]));
            if (($set['rsvp'] ?? null) === 'no') {
                $cnt = $db->prepare("SELECT COUNT(*) FROM event_invites WHERE event_id = ? AND occurrence_date IS NULL AND approval_status = 'approved'");
                $cnt->execute([$event_id]); $before = (int)$cnt->fetchColumn();
                maybe_promote_waitlisted($db, $event_id);
                $cnt->execute([$event_id]); $promoted = max(0, (int)$cnt->fetchColumn() - $before);
            }
            $db->commit();
        } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
        db_log_activity((int)$actor['id'], "changed guest {$inv['username']} on event id: $event_id (" . implode(',', $changed) . ')');
    }
    return gn_ok(['event_id' => $event_id, 'invite_id' => $invite_id, 'name' => (string)$inv['username'],
                  'changed' => $changed, 'promoted_from_waitlist' => $promoted]);
}

/**
 * Take a guest off the list. On an event still ahead they are told they were
 * removed, as when a host removes them on the site. The event's creator
 * cannot be removed from their own event this way.
 */
function gn_invite_remove(PDO $db, array $actor, int $event_id, int $invite_id): array {
    $ev = gn_event_guard($db, $actor, $event_id);
    if (isset($ev['ok'])) return $ev;
    $inv = gn_invite_row($db, $event_id, $invite_id);
    if (!$inv) return gn_err('invite_not_found', 'No such guest on this event.', 404);

    $tz = new DateTimeZone(get_setting('timezone', 'UTC'));
    $today = (new DateTime('now', $tz))->format('Y-m-d');
    $notify = (string)$ev['start_date'] >= $today;
    $db->beginTransaction();
    try {
        if ($notify) {
            queue_event_notification($db, $event_id, (string)$inv['username'], 'cancel_event', null,
                ['title' => (string)$ev['title'], 'start_date' => (string)$ev['start_date']], null,
                $inv['user_id'] !== null ? (int)$inv['user_id'] : null);
        }
        $db->prepare('DELETE FROM event_invites WHERE id = ?')->execute([$invite_id]);
        purge_queue_for_dropped_guests($db, $event_id);
        maybe_promote_waitlisted($db, $event_id);
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    if ($notify) drain_queue_async();
    db_log_activity((int)$actor['id'], "removed guest {$inv['username']} from event id: $event_id");
    return gn_ok(['event_id' => $event_id, 'invite_id' => $invite_id, 'name' => (string)$inv['username'],
                  'removed' => true, 'guest_notified' => $notify]);
}

/** Approve ($approve) or turn down a guest who asked to come, or who is waiting for a seat. */
function gn_invite_decide(PDO $db, array $actor, int $event_id, int $invite_id, bool $approve): array {
    $ev = gn_event_guard($db, $actor, $event_id);
    if (isset($ev['ok'])) return $ev;
    $inv = gn_invite_row($db, $event_id, $invite_id);
    if (!$inv) return gn_err('invite_not_found', 'No such guest on this event.', 404);
    $status = (string)($inv['approval_status'] ?? 'approved');
    $want   = $approve ? 'approved' : 'denied';
    if ($status === $want) return gn_ok(['event_id' => $event_id, 'invite_id' => $invite_id, 'name' => (string)$inv['username'], 'status' => $want, 'changed' => false]);

    $seat = '';
    if ($approve) {
        // The shared sequence: status, league enrolment, the poker roster and
        // a seat, and the "you are approved" notice.
        $seat = trim((string)(approve_event_invitee($db, $event_id, (string)$inv['username'], (int)$actor['id'])['seat_info'] ?? ''));
    } else {
        $db->prepare("UPDATE event_invites SET approval_status = 'denied' WHERE id = ?")->execute([$invite_id]);
        purge_queue_for_dropped_guests($db, $event_id);
        db_log_activity((int)$actor['id'], "deny_invite for {$inv['username']} on event id: $event_id");
    }
    return gn_ok(['event_id' => $event_id, 'invite_id' => $invite_id, 'name' => (string)$inv['username'],
                  'status' => $want, 'changed' => true, 'seat' => $seat !== '' ? rtrim($seat, '.') : null]);
}
