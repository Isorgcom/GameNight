<?php
/**
 * /api/v1/events
 *
 * GET  — list events for the API key's league within a date window. As of
 *        v0.19208, returns ISO-8601 UTC instants (`start_at` / `end_at`) so
 *        sister sites don't need to know the league's display timezone.
 * POST — create a new event in the bound league. Requires the `write` scope.
 *        Mirrors the calendar_dl.php side effects: optional poker_sessions
 *        row, invitee inserts (always approved), waitlist marking, reminder
 *        queueing, async notification drain. Walk-in token is generated
 *        eagerly so the response can return a ready-to-use walkin_url.
 *
 * Visibility is forced to 'league' on POST — public events stay an admin-only
 * UI privilege. league_id is implicit from the API key.
 */

require_once __DIR__ . '/../_auth.php';
require_once __DIR__ . '/../../auth.php';            // send_notification, csrf helpers (required transitively)
require_once __DIR__ . '/../../_notifications.php';  // queue_reminders_for_event, queue_event_notification

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'OPTIONS') {
    api_send_headers(0);
    http_response_code(204);
    exit;
}

if ($method === 'POST') {
    handle_events_post();
    exit;
}

if ($method === 'DELETE') {
    handle_events_delete();
    exit;
}

if ($method !== 'GET') {
    api_log_request(null, 405);
    api_fail('Method not allowed', 405);
}

// ── GET handler ──────────────────────────────────────────────────────────────
$key = api_authenticate();
$db  = get_db();
$lid = (int)$key['league_id'];

$site_tz = new DateTimeZone(get_setting('timezone', 'UTC'));
$utc_tz  = new DateTimeZone('UTC');
$today   = (new DateTime('now', $site_tz))->format('Y-m-d');

$from = isset($_GET['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_GET['from']) ? $_GET['from'] : $today;
$to   = isset($_GET['to'])   && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_GET['to'])   ? $_GET['to']
                                                                                          : (new DateTime($from))->modify('+90 days')->format('Y-m-d');

if ($from > $to) {
    api_log_request((int)$key['id'], 400);
    api_fail('"from" must be on or before "to"', 400);
}
$days_apart = (int)((strtotime($to) - strtotime($from)) / 86400);
if ($days_apart > 366) {
    api_log_request((int)$key['id'], 400);
    api_fail('Window cannot exceed 366 days', 400);
}

$stmt = $db->prepare(
    "SELECT id, title, description, start_date, end_date, start_time, end_time,
            color, is_poker, created_at
     FROM events
     WHERE league_id = ?
       AND start_date <= ?
       AND COALESCE(end_date, start_date) >= ?
     ORDER BY start_date, COALESCE(start_time, '00:00')"
);
$stmt->execute([$lid, $to, $from]);
$rows = $stmt->fetchAll();

$counts = [];
if ($rows) {
    $ids = array_column($rows, 'id');
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $cs  = $db->prepare(
        "SELECT event_id, rsvp, COUNT(*) AS n
           FROM event_invites
          WHERE event_id IN ($ph)
            AND approval_status = 'approved'
            AND rsvp IN ('yes','no','maybe')
          GROUP BY event_id, rsvp"
    );
    $cs->execute($ids);
    foreach ($cs->fetchAll() as $c) {
        $counts[(int)$c['event_id']][$c['rsvp']] = (int)$c['n'];
    }
}

$events = [];
foreach ($rows as $r) {
    $ec = $counts[(int)$r['id']] ?? [];
    $events[] = [
        'id'                => (int)$r['id'],
        'title'             => (string)$r['title'],
        'description'       => (string)($r['description'] ?? ''),
        'start_at'          => api_local_to_utc_iso((string)$r['start_date'], (string)($r['start_time'] ?? ''), $site_tz, $utc_tz),
        'end_at'            => api_local_to_utc_iso((string)($r['end_date'] ?? ''), (string)($r['end_time'] ?? ''), $site_tz, $utc_tz),
        'color'             => (string)$r['color'],
        'is_poker'          => (int)$r['is_poker'] === 1,
        'rsvp_yes_count'    => (int)($ec['yes']   ?? 0),
        'rsvp_no_count'     => (int)($ec['no']    ?? 0),
        'rsvp_maybe_count'  => (int)($ec['maybe'] ?? 0),
        'created_at'        => api_db_utc_to_iso((string)$r['created_at']),
    ];
}

api_log_request((int)$key['id'], 200);
api_ok([
    'from'   => $from,
    'to'     => $to,
    'count'  => count($events),
    'events' => $events,
]);

// ─────────────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Convert a stored (local) date + optional time pair into an ISO-8601 string.
 *  - "" / no date  → null
 *  - date only (no time)         → "YYYY-MM-DD"
 *  - date + time (HH:MM)         → "YYYY-MM-DDTHH:MM:SSZ" in UTC
 *
 * Stored values are in the site's display timezone; the function does the
 * conversion to UTC. Sister sites get unambiguous instants.
 */
function api_local_to_utc_iso(string $date, string $time, DateTimeZone $site_tz, DateTimeZone $utc_tz): ?string {
    if ($date === '') return null;
    if ($time === '') return $date;
    try {
        $dt = DateTime::createFromFormat('Y-m-d H:i', "$date $time", $site_tz);
        if (!$dt) {
            // start_time may be 'HH:MM:SS' in some legacy rows
            $dt = DateTime::createFromFormat('Y-m-d H:i:s', "$date $time", $site_tz);
        }
        if (!$dt) return $date;
        $dt->setTimezone($utc_tz);
        return $dt->format('Y-m-d\TH:i:s\Z');
    } catch (Exception $e) {
        return $date;
    }
}

/**
 * `created_at` is stored as 'YYYY-MM-DD HH:MM:SS' in UTC (sqlite default).
 * Render it as ISO-8601 with a trailing Z so consumers don't have to guess.
 */
function api_db_utc_to_iso(string $val): string {
    if ($val === '') return '';
    return str_replace(' ', 'T', $val) . 'Z';
}

/**
 * Parse an inbound start_at / end_at value into a stored (date, time-or-null)
 * pair. Accepts:
 *  - "YYYY-MM-DD"                          → all-day, time = null
 *  - "YYYY-MM-DDTHH:MM:SSZ"                → UTC instant
 *  - "YYYY-MM-DDTHH:MM:SS+HH:MM"           → instant with offset
 *  - "YYYY-MM-DDTHH:MMZ" / "...+HH:MM"     → seconds optional
 * Returns ['YYYY-MM-DD', 'HH:MM' | null] in the site's display timezone, or
 * null if the input is unparseable.
 */
function api_parse_inbound_at(string $raw, DateTimeZone $site_tz): ?array {
    $raw = trim($raw);
    if ($raw === '') return null;
    // Date-only, all-day event
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
        return [$raw, null];
    }
    // Try the strict ISO-8601 forms PHP knows about.
    $candidates = ['Y-m-d\TH:i:sP', 'Y-m-d\TH:iP', 'Y-m-d\TH:i:s\Z', 'Y-m-d\TH:i\Z'];
    foreach ($candidates as $fmt) {
        $dt = DateTime::createFromFormat($fmt, $raw);
        if ($dt instanceof DateTime) {
            $dt->setTimezone($site_tz);
            return [$dt->format('Y-m-d'), $dt->format('H:i')];
        }
    }
    return null;
}

// ─────────────────────────────────────────────────────────────────────────────
// POST handler
// ─────────────────────────────────────────────────────────────────────────────
function handle_events_post(): void {
    $key       = api_authenticate();
    api_require_scope($key, 'write');

    $db        = get_db();
    $key_id    = (int)$key['id'];
    $league_id = (int)$key['league_id'];

    // Per-key rate limit: 60 successful event creations per hour
    $rl = $db->prepare(
        "SELECT COUNT(*) FROM api_request_log
          WHERE key_id = ?
            AND status = 200
            AND method = 'POST'
            AND path LIKE '%/api/v1/events%'
            AND created_at > datetime('now','-1 hour')"
    );
    $rl->execute([$key_id]);
    if ((int)$rl->fetchColumn() >= 60) {
        api_log_request($key_id, 429);
        api_fail('Rate limit exceeded: 60 event creations per hour per key', 429);
    }

    // ── Parse body ───────────────────────────────────────────────────────────
    $raw  = file_get_contents('php://input');
    $body = json_decode($raw ?: '', true);
    if (!is_array($body)) {
        api_log_request($key_id, 400);
        api_fail('Request body must be valid JSON', 400);
    }

    $title = trim((string)($body['title'] ?? ''));
    if ($title === '') {
        api_log_request($key_id, 400);
        api_fail('title is required', 400);
    }
    if (mb_strlen($title) > 200) {
        api_log_request($key_id, 400);
        api_fail('title must be 200 characters or fewer', 400);
    }

    $description = trim((string)($body['description'] ?? ''));

    $site_tz = new DateTimeZone(get_setting('timezone', 'UTC'));

    $start_at_raw = (string)($body['start_at'] ?? '');
    if ($start_at_raw === '') {
        api_log_request($key_id, 400);
        api_fail('start_at is required (ISO-8601 UTC instant or YYYY-MM-DD for all-day)', 400);
    }
    $start = api_parse_inbound_at($start_at_raw, $site_tz);
    if ($start === null) {
        api_log_request($key_id, 400);
        api_fail('start_at must be ISO-8601 UTC (e.g. "2026-05-17T20:00:00Z") or a date "YYYY-MM-DD"', 400);
    }
    [$start_date, $start_time] = $start;

    $end_at_raw = (string)($body['end_at'] ?? '');
    $end_date = null; $end_time = null;
    if ($end_at_raw !== '') {
        $end = api_parse_inbound_at($end_at_raw, $site_tz);
        if ($end === null) {
            api_log_request($key_id, 400);
            api_fail('end_at must be ISO-8601 UTC or a date', 400);
        }
        [$end_date, $end_time] = $end;
    }

    // ── Whitelist validation for pass-through fields (mirrors calendar_dl.php) ─
    $allowed_colors = ['#2563eb','#16a34a','#dc2626','#d97706','#7c3aed','#0891b2','#db2777'];
    $color = (string)($body['color'] ?? '#2563eb');
    if (!in_array($color, $allowed_colors, true)) {
        api_log_request($key_id, 400);
        api_fail('color must be one of: ' . implode(', ', $allowed_colors), 400);
    }

    // Recurrence: the events table no longer carries recurrence columns
    // (legacy feature, removed from schema). Reject the fields rather than
    // silently dropping them so callers don't think they took effect.
    if (isset($body['recurrence']) && $body['recurrence'] !== '' && $body['recurrence'] !== 'none') {
        api_log_request($key_id, 400);
        api_fail('recurrence is not supported; create one event per occurrence', 400);
    }
    if (isset($body['recurrence_end']) && $body['recurrence_end'] !== '') {
        api_log_request($key_id, 400);
        api_fail('recurrence_end is not supported', 400);
    }

    $requires_approval = !empty($body['requires_approval']) ? 1 : 0;
    $is_poker          = !empty($body['is_poker']) ? 1 : 0;
    $waitlist_enabled  = array_key_exists('waitlist_enabled', $body)
        ? (!empty($body['waitlist_enabled']) ? 1 : 0)
        : 1;
    $reminders_enabled = array_key_exists('reminders_enabled', $body)
        ? (!empty($body['reminders_enabled']) ? 1 : 0)
        : 1;

    // reminder_offsets — array of positive minutes
    $reminder_offsets_json = null;
    if (isset($body['reminder_offsets'])) {
        if (!is_array($body['reminder_offsets'])) {
            api_log_request($key_id, 400);
            api_fail('reminder_offsets must be an array of minutes', 400);
        }
        $clean = [];
        foreach ($body['reminder_offsets'] as $m) {
            $n = (int)$m;
            if ($n > 0 && $n <= 40320) $clean[] = $n;
        }
        $clean = array_values(array_unique($clean));
        if (!empty($clean)) $reminder_offsets_json = json_encode($clean);
    }

    $rsvp_deadline_hrs = null;
    if (isset($body['rsvp_deadline_hours']) && $body['rsvp_deadline_hours'] !== '' && $body['rsvp_deadline_hours'] !== null) {
        $rdh = (int)$body['rsvp_deadline_hours'];
        if ($rdh < 0) {
            api_log_request($key_id, 400);
            api_fail('rsvp_deadline_hours must be a non-negative integer', 400);
        }
        $rsvp_deadline_hrs = $rdh ?: null;
    }

    // Poker inline fields (only meaningful when is_poker=1)
    $poker_game_type = in_array($body['poker_game_type'] ?? '', ['tournament', 'cash'], true) ? $body['poker_game_type'] : 'tournament';
    $poker_buyin     = (int)round(floatval($body['poker_buyin'] ?? 20) * 100);
    $poker_tables    = max(1, (int)($body['poker_tables'] ?? 1));
    $poker_seats     = max(2, (int)($body['poker_seats']  ?? 8));

    // ── Resolve creator: league owner ────────────────────────────────────────
    $ow = $db->prepare('SELECT owner_id FROM leagues WHERE id = ?');
    $ow->execute([$league_id]);
    $owner_id = (int)$ow->fetchColumn();
    if ($owner_id <= 0) {
        api_log_request($key_id, 404);
        api_fail('League not found', 404);
    }

    // ── Validate invitees ────────────────────────────────────────────────────
    $invitees_in = $body['invitees'] ?? [];
    if (!is_array($invitees_in)) {
        api_log_request($key_id, 400);
        api_fail('invitees must be an array', 400);
    }
    if (count($invitees_in) > MAX_INVITEES_PER_EVENT) {
        api_log_request($key_id, 400);
        api_fail('Too many invitees: limit is ' . MAX_INVITEES_PER_EVENT, 400);
    }
    $resolved_invitees = []; // [['user_id'=>int,'username'=>str,'email'=>?,'phone'=>?,'role'=>'invitee'|'manager'], ...]
    if (!empty($invitees_in)) {
        $user_ids = [];
        foreach ($invitees_in as $idx => $inv) {
            if (!is_array($inv) || !isset($inv['user_id'])) {
                api_log_request($key_id, 400);
                api_fail("invitees[$idx] must be an object with a user_id", 400);
            }
            $uid = (int)$inv['user_id'];
            if ($uid <= 0) {
                api_log_request($key_id, 400);
                api_fail("invitees[$idx].user_id must be a positive integer", 400);
            }
            $user_ids[] = $uid;
        }
        $user_ids = array_values(array_unique($user_ids));
        $ph = implode(',', array_fill(0, count($user_ids), '?'));
        // Pull users that are members of this league. Anything missing is rejected.
        $userStmt = $db->prepare(
            "SELECT u.id, u.username, u.email, u.phone
               FROM users u
               JOIN league_members lm ON lm.user_id = u.id
              WHERE lm.league_id = ?
                AND u.id IN ($ph)"
        );
        $userStmt->execute(array_merge([$league_id], $user_ids));
        $found = [];
        foreach ($userStmt->fetchAll() as $u) {
            $found[(int)$u['id']] = $u;
        }
        $missing = array_values(array_filter($user_ids, fn($id) => !isset($found[$id])));
        if (!empty($missing)) {
            api_log_request($key_id, 400);
            api_fail('invitees not found in this league: ' . implode(', ', $missing), 400);
        }
        // Re-walk the original input so we preserve order and per-invitee flags.
        foreach ($invitees_in as $inv) {
            $uid = (int)$inv['user_id'];
            $u = $found[$uid];
            $resolved_invitees[] = [
                'user_id'  => $uid,
                'username' => (string)$u['username'],
                'email'    => $u['email'],
                'phone'    => $u['phone'],
                'role'     => !empty($inv['manager']) ? 'manager' : 'invitee',
            ];
        }
    }

    // ── Generate walk-in token eagerly ───────────────────────────────────────
    $walkin_token = bin2hex(random_bytes(32));

    // ── INSERT event ─────────────────────────────────────────────────────────
    try {
        $db->prepare(
            'INSERT INTO events (
                title, description, start_date, end_date, start_time, end_time,
                color, created_by, requires_approval,
                league_id, visibility, is_poker, rsvp_deadline_hours, waitlist_enabled,
                reminders_enabled, reminder_offsets, walkin_token
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $title,
            $description !== '' ? $description : null,
            $start_date,
            $end_date,
            $start_time,
            $end_time,
            $color,
            $owner_id,
            $requires_approval,
            $league_id,
            'league',
            $is_poker,
            $rsvp_deadline_hrs,
            $waitlist_enabled,
            $reminders_enabled,
            $reminder_offsets_json,
            $walkin_token,
        ]);
    } catch (Exception $e) {
        api_log_request($key_id, 500);
        api_fail('Failed to create event', 500);
    }
    $new_eid = (int)$db->lastInsertId();

    // ── Side effects ─────────────────────────────────────────────────────────

    // Auto-create poker session (matches calendar_dl.php:155-162)
    if ($is_poker) {
        $db->prepare('INSERT INTO poker_sessions (event_id, buyin_amount, num_tables, seats_per_table, game_type) VALUES (?, ?, ?, ?, ?)')
           ->execute([$new_eid, $poker_buyin, $poker_tables, $poker_seats, $poker_game_type]);
    }

    // Insert invitees — always approved, like calendar_dl.php:63
    $invitees_added = 0;
    if (!empty($resolved_invitees)) {
        $ins = $db->prepare(
            "INSERT INTO event_invites (event_id, username, phone, email, rsvp, event_role, approval_status, sort_order, rsvp_token)
             VALUES (?, ?, ?, ?, NULL, ?, 'approved', ?, ?)"
        );
        $sort = 1;
        foreach ($resolved_invitees as $inv) {
            $ins->execute([
                $new_eid,
                strtolower($inv['username']),
                $inv['phone'] ?: null,
                $inv['email'] ?: null,
                $inv['role'],
                $sort,
                bin2hex(random_bytes(16)),
            ]);
            $invitees_added++;
            $sort++;
        }
    }

    // Waitlist beyond capacity for poker events (calendar_dl.php:180-186)
    if ($is_poker && $waitlist_enabled && $invitees_added > 0) {
        $cap = $poker_tables * $poker_seats;
        $db->prepare(
            "UPDATE event_invites SET approval_status = 'waitlisted'
             WHERE event_id = ? AND occurrence_date IS NULL AND sort_order > ?"
        )->execute([$new_eid, $cap]);
    }

    // Queue invite notifications for approved invitees (skip waitlisted)
    if ($invitees_added > 0) {
        $approvedStmt = $db->prepare(
            "SELECT username FROM event_invites
              WHERE event_id = ? AND occurrence_date IS NULL AND approval_status = 'approved'"
        );
        $approvedStmt->execute([$new_eid]);
        $queue = $db->prepare("INSERT INTO pending_notifications (event_id, username, notify_type) VALUES (?, ?, 'invite')");
        foreach ($approvedStmt->fetchAll() as $ar) {
            $queue->execute([$new_eid, (string)$ar['username']]);
        }
        drain_queue_async();
    }

    // Reminder queueing (calendar_dl.php:196-199)
    if ($reminders_enabled) {
        queue_reminders_for_event($db, $new_eid);
        $db->prepare('UPDATE events SET reminders_queued = 1 WHERE id = ?')->execute([$new_eid]);
    }

    db_log_anon_activity("api_create_event: '$title' (id=$new_eid) via key=$key_id league=$league_id" . ($invitees_added > 0 ? " (invitees=$invitees_added)" : ''));

    // Build response
    $site_tz_resp = new DateTimeZone(get_setting('timezone', 'UTC'));
    $utc_tz_resp  = new DateTimeZone('UTC');
    $response_start = api_local_to_utc_iso($start_date, $start_time ?? '', $site_tz_resp, $utc_tz_resp);
    $response_end   = $end_date === null ? null : api_local_to_utc_iso($end_date, $end_time ?? '', $site_tz_resp, $utc_tz_resp);

    $created_row = $db->prepare('SELECT created_at FROM events WHERE id = ?');
    $created_row->execute([$new_eid]);
    $created_at = api_db_utc_to_iso((string)$created_row->fetchColumn());

    $walkin_url = rtrim(get_site_url(), '/') . '/walkin.php?event_id=' . $new_eid . '&token=' . $walkin_token;

    api_log_request($key_id, 200);
    api_ok([
        'event_id'        => $new_eid,
        'title'           => $title,
        'start_at'        => $response_start,
        'end_at'          => $response_end,
        'league_id'       => $league_id,
        'visibility'      => 'league',
        'is_poker'        => $is_poker === 1,
        'walkin_url'      => $walkin_url,
        'invitees_added'  => $invitees_added,
        'created_at'      => $created_at,
    ], 0);
}

// ─────────────────────────────────────────────────────────────────────────────
// DELETE handler
// ─────────────────────────────────────────────────────────────────────────────
function handle_events_delete(): void {
    $key = api_authenticate();
    api_require_scope($key, 'write');

    $db        = get_db();
    $key_id    = (int)$key['id'];
    $league_id = (int)$key['league_id'];

    // Per-key rate limit: 60 successful deletes per hour
    $rl = $db->prepare(
        "SELECT COUNT(*) FROM api_request_log
          WHERE key_id = ?
            AND status = 200
            AND method = 'DELETE'
            AND path LIKE '%/api/v1/events/%'
            AND created_at > datetime('now','-1 hour')"
    );
    $rl->execute([$key_id]);
    if ((int)$rl->fetchColumn() >= 60) {
        api_log_request($key_id, 429);
        api_fail('Rate limit exceeded: 60 event deletions per hour per key', 429);
    }

    $event_id = (int)($_GET['id'] ?? 0);
    if ($event_id <= 0) {
        api_log_request($key_id, 404);
        api_fail('event_not_found', 404);
    }

    // Fetch the event. 404 if missing or in a different league. Don't distinguish
    // the two — leaking "this id exists somewhere" would let the API confirm
    // event existence in leagues the key has no business reading.
    $evtStmt = $db->prepare('SELECT id, title, start_date, league_id FROM events WHERE id = ?');
    $evtStmt->execute([$event_id]);
    $evt = $evtStmt->fetch();
    if (!$evt || (int)$evt['league_id'] !== $league_id) {
        api_log_request($key_id, 404);
        api_fail('event_not_found', 404);
    }

    $title      = (string)$evt['title'];
    $start_date = (string)$evt['start_date'];

    // Future events get cancel_event notifications; past events are deleted
    // silently (mirrors calendar.php:432). "Today" is in the site's TZ — stored
    // start_date is also in the site's TZ.
    $site_tz = new DateTimeZone(get_setting('timezone', 'UTC'));
    $today   = (new DateTime('now', $site_tz))->format('Y-m-d');
    $notify  = ($start_date >= $today);

    $notifications_queued = 0;

    try {
        $db->beginTransaction();

        // Clear all queued notifications for this event FIRST. The UI handler
        // only purges already-attempted rows, which orphans pending reminders
        // for events that get deleted before the reminder fires. We want a
        // clean slate, then we re-queue the cancel notifications below.
        $db->prepare('DELETE FROM pending_notifications WHERE event_id=?')->execute([$event_id]);

        if ($notify) {
            $invStmt = $db->prepare(
                "SELECT username FROM event_invites
                  WHERE event_id = ? AND occurrence_date IS NULL"
            );
            $invStmt->execute([$event_id]);
            foreach ($invStmt->fetchAll() as $inv) {
                queue_event_notification(
                    $db,
                    $event_id,
                    (string)$inv['username'],
                    'cancel_event',
                    null,
                    ['title' => $title, 'start_date' => $start_date]
                );
                $notifications_queued++;
            }
        }

        // Cascade. Order matches calendar.php:430-464 — calendar_dl.php is
        // missing the comments cleanup, so we don't follow that one. We add
        // an explicit poker_sessions delete (and the chained poker tables)
        // because SQLite ignores ON DELETE CASCADE unless foreign_keys=ON,
        // and that PRAGMA isn't set on this connection.
        $db->prepare("DELETE FROM comments WHERE type='event' AND content_id=?")->execute([$event_id]);
        $db->prepare('DELETE FROM event_exceptions WHERE event_id=?')->execute([$event_id]);
        $db->prepare('DELETE FROM event_invites WHERE event_id=?')->execute([$event_id]);
        $db->prepare('DELETE FROM event_notifications_sent WHERE event_id=?')->execute([$event_id]);
        // Poker chain — delete leaves first, then session.
        $sids = $db->prepare('SELECT id FROM poker_sessions WHERE event_id=?');
        $sids->execute([$event_id]);
        $session_ids = array_column($sids->fetchAll(), 'id');
        if (!empty($session_ids)) {
            $sph = implode(',', array_fill(0, count($session_ids), '?'));
            $db->prepare("DELETE FROM poker_players WHERE session_id IN ($sph)")->execute($session_ids);
            $db->prepare("DELETE FROM poker_payouts WHERE session_id IN ($sph)")->execute($session_ids);
            $db->prepare("DELETE FROM timer_state   WHERE session_id IN ($sph)")->execute($session_ids);
            $db->prepare('DELETE FROM poker_sessions WHERE event_id=?')->execute([$event_id]);
        }
        $db->prepare('DELETE FROM events WHERE id=?')->execute([$event_id]);

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        api_log_request($key_id, 500);
        api_fail('Failed to delete event', 500);
    }

    if ($notifications_queued > 0) {
        drain_queue_async();
    }

    db_log_anon_activity("api_delete_event: '$title' (id=$event_id) via key=$key_id league=$league_id" . ($notifications_queued > 0 ? " (notifications=$notifications_queued)" : ''));

    api_log_request($key_id, 200);
    api_ok([
        'event_id'             => $event_id,
        'title'                => $title,
        'deleted'              => true,
        'notifications_queued' => $notifications_queued,
    ], 0);
}
