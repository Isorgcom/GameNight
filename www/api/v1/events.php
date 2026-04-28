<?php
/**
 * GET /api/v1/events?from=YYYY-MM-DD&to=YYYY-MM-DD
 *
 * Returns events that belong to the league bound to the API key, falling
 * within [from, to]. Default window is today through +90 days. RSVP counts
 * are computed from approved invites in event_invites.
 *
 * Dates are returned exactly as stored: start_date/end_date are local-day
 * strings, start_time/end_time are local-time strings (per the existing
 * site convention; see CLAUDE.md "All dates stored in UTC" — that applies
 * to created_at and similar audit timestamps, not to the date/time of the
 * event itself which is stored in the site's display tz). created_at IS
 * UTC.
 *
 * Recurrence: this codebase removed recurring events (build_event_by_date()
 * comment in db.php confirms: "recurrence was removed"), so each event
 * appears at most once per response.
 */

require_once __DIR__ . '/../_auth.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    api_log_request(null, 405);
    api_fail('Method not allowed', 405);
}

$key = api_authenticate();
$db  = get_db();
$lid = (int)$key['league_id'];

// Parse window. Default: today (in site tz) through +90 days. Cap span at 366 days
// so a runaway query can't scan years of data.
$site_tz = new DateTimeZone(get_setting('timezone', 'UTC'));
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

// Pull all events for this league that overlap the window. An event "overlaps"
// the window if its start_date is <= $to AND its end_date (or start_date for
// single-day events) is >= $from.
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

// Batch-load RSVP counts: one query, group by event_id + rsvp value.
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
        'start_date'        => (string)$r['start_date'],
        'end_date'          => (string)($r['end_date'] ?? ''),
        'start_time'        => (string)($r['start_time'] ?? ''),
        'end_time'          => (string)($r['end_time'] ?? ''),
        'color'             => (string)$r['color'],
        'is_poker'          => (int)$r['is_poker'] === 1,
        'rsvp_yes_count'    => (int)($ec['yes']   ?? 0),
        'rsvp_no_count'     => (int)($ec['no']    ?? 0),
        'rsvp_maybe_count'  => (int)($ec['maybe'] ?? 0),
        'created_at'        => (string)$r['created_at'],
    ];
}

api_log_request((int)$key['id'], 200);
api_ok([
    'from'   => $from,
    'to'     => $to,
    'count'  => count($events),
    'events' => $events,
]);
