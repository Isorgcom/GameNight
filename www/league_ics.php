<?php
/**
 * Public league calendar feed: /league/<slug>.ics (rewritten to ?slug=<slug>).
 *
 * Multi-VEVENT ICS for leagues with a public landing page enabled. Same gating
 * as league_public.php (public_page = 1 AND is_hidden = 0); any miss is a plain
 * 404 with an identical body so the endpoint is not an existence oracle.
 *
 * Emits only SUMMARY, DTSTART/DTEND, LOCATION (venue name only, never the
 * street address), and a URL back to the public page. Deliberately no
 * DESCRIPTION (event descriptions can reference members) and no per-event
 * links into the login-gated app.
 *
 * Times are stored as wall-clock in the site timezone; the ICS emits UTC so
 * every subscriber's calendar app shows events in their own local time.
 */
require_once __DIR__ . '/auth.php';

$db        = get_db();
$site_name = get_setting('site_name', 'Game Night');
$site_tz   = new DateTimeZone(get_setting('timezone', 'UTC'));
$utc_tz    = new DateTimeZone('UTC');

$slug = strtolower(trim($_GET['slug'] ?? ''));
$league = null;
if (preg_match('/^[a-z0-9-]{1,60}$/', $slug)) {
    $L = $db->prepare("SELECT id, name, slug FROM leagues WHERE slug = ? AND public_page = 1 AND is_hidden = 0");
    $L->execute([$slug]);
    $league = $L->fetch();
}
if (!$league) {
    http_response_code(404);
    exit('Not found.');
}

// Recent past (30 days) plus everything upcoming, so subscribed calendars keep
// a little history. Allowlisted columns only, and events an author marked
// 'invitees_only' stay out of the public feed (matches league_public.php).
$cutoff = (new DateTime('now', $site_tz))->modify('-30 days')->format('Y-m-d');
$st = $db->prepare(
    "SELECT e.id, e.title, e.start_date, e.end_date, e.start_time, e.end_time, e.venue_name
       FROM events e
      WHERE e.league_id = ? AND e.visibility IN ('league', 'public')
        AND COALESCE(NULLIF(e.end_date, ''), e.start_date) >= ?
      ORDER BY e.start_date ASC, e.start_time ASC
      LIMIT 100"
);
$st->execute([(int)$league['id'], $cutoff]);
$events = $st->fetchAll();

// Kept in sync with ics.php (single-event endpoint).
function ics_escape(string $s): string {
    return str_replace(["\\", ";", ",", "\r\n", "\n"], ["\\\\", "\\;", "\\,", "\\n", "\\n"], $s);
}

$host    = parse_url(get_site_url(), PHP_URL_HOST) ?: 'gamenight';
$now     = (new DateTime('now', $utc_tz))->format('Ymd\THis\Z');
$pubUrl  = get_site_url() . '/league/' . $league['slug'];

$lines = [
    'BEGIN:VCALENDAR',
    'VERSION:2.0',
    'PRODID:-//' . ics_escape($site_name) . '//GameNight//EN',
    'CALSCALE:GREGORIAN',
    'METHOD:PUBLISH',
    'X-WR-CALNAME:' . ics_escape($league['name']),
    // Tell subscribers how often to re-poll. Without these, clients guess, and some
    // default to daily or weekly, which makes a subscribed calendar look stale.
    // RFC 7986 property plus the older Apple/Outlook X- equivalent.
    'REFRESH-INTERVAL;VALUE=DURATION:PT4H',
    'X-PUBLISHED-TTL:PT4H',
];

foreach ($events as $event) {
    // Start/end logic kept in sync with ics.php: all-day events use DATE values
    // with an exclusive DTEND; timed events convert site-tz wall-clock to UTC,
    // cross-midnight ends roll to the next day, and a missing end time means a
    // 3-hour game night.
    $all_day = empty($event['start_time']);
    if ($all_day) {
        $lastDay   = $event['end_date'] ?: $event['start_date'];
        $ics_start = str_replace('-', '', $event['start_date']);
        $ics_end   = (new DateTime($lastDay))->modify('+1 day')->format('Ymd');
    } else {
        $start = new DateTime($event['start_date'] . ' ' . $event['start_time'], $site_tz);
        if (!empty($event['end_time'])) {
            $endBase = $event['end_date'] ?: $event['start_date'];
            $end = new DateTime($endBase . ' ' . $event['end_time'], $site_tz);
            if ($end <= $start) $end->modify('+1 day');
        } else {
            $end = (clone $start)->modify('+3 hours');
        }
        $start->setTimezone($utc_tz);
        $end->setTimezone($utc_tz);
        $ics_start = $start->format('Ymd\THis\Z');
        $ics_end   = $end->format('Ymd\THis\Z');
    }

    $location = trim((string)($event['venue_name'] ?? ''));

    $lines[] = 'BEGIN:VEVENT';
    $lines[] = 'UID:gamenight-league-' . (int)$league['id'] . '-ev-' . (int)$event['id'] . '@' . $host;
    $lines[] = 'DTSTAMP:' . $now;
    $lines[] = $all_day ? 'DTSTART;VALUE=DATE:' . $ics_start : 'DTSTART:' . $ics_start;
    $lines[] = $all_day ? 'DTEND;VALUE=DATE:' . $ics_end     : 'DTEND:' . $ics_end;
    $lines[] = 'SUMMARY:' . ics_escape((string)$event['title']);
    if ($location !== '') $lines[] = 'LOCATION:' . ics_escape($location);
    $lines[] = 'URL:' . $pubUrl;
    $lines[] = 'END:VEVENT';
}

$lines[] = 'END:VCALENDAR';

// RFC 5545 line folding, kept in sync with ics.php: <= 75 octets per line,
// continuations start with a space, never split a multibyte character.
$out = '';
foreach ($lines as $line) {
    while (strlen($line) > 73) {
        $cut = 73;
        while ($cut > 1 && (ord($line[$cut]) & 0xC0) === 0x80) $cut--;
        $out .= substr($line, 0, $cut) . "\r\n";
        $line = ' ' . substr($line, $cut);
    }
    $out .= $line . "\r\n";
}

header('Content-Type: text/calendar; charset=utf-8');
// inline (not attachment) so calendar clients subscribe rather than download a copy.
header('Content-Disposition: inline; filename="' . $league['slug'] . '.ics"');
// Never let a proxy hand a subscriber a stale copy of the schedule.
header('Cache-Control: no-cache, must-revalidate');
echo $out;
