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
