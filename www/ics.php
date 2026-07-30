<?php
/**
 * Add-to-calendar endpoint.
 *
 *   /ics.php?token=<rsvp_token>            .ics download for an invitee (no login)
 *   /ics.php?id=<event_id>                 .ics download for a logged-in viewer
 *   ...&google=1                           302 to a Google Calendar template URL instead
 *
 * Times are stored as wall-clock in the site timezone; the ICS emits UTC so
 * every guest's calendar app shows the event in their own local time.
 */
require_once __DIR__ . '/auth.php';

$db        = get_db();
$site_name = get_setting('site_name', 'Game Night');
$site_tz   = new DateTimeZone(get_setting('timezone', 'UTC'));
$utc_tz    = new DateTimeZone('UTC');

$token = trim($_GET['token'] ?? '');
$id    = (int)($_GET['id'] ?? 0);

$event = null;
$event_link = get_site_url();
if ($token !== '') {
    // Possessing the unguessable rsvp_token authorizes the invitee (same trust
    // model as event.php); serve the ICS without a login.
    $st = $db->prepare('SELECT e.* FROM event_invites ei JOIN events e ON e.id = ei.event_id WHERE ei.rsvp_token = ?');
    $st->execute([$token]);
    $event = $st->fetch();
    if ($event) $event_link = get_site_url() . '/event.php?token=' . urlencode($token);
} elseif ($id > 0) {
    $current = current_user();
    if (!$current) { header('Location: /login.php?redirect=' . urlencode($_SERVER['REQUEST_URI'])); exit; }
    // Same view rule as the calendar: creator, invitee, league member, or public.
    $vis = event_visibility_sql('events', (int)$current['id']);
    $st  = $db->prepare("SELECT * FROM events WHERE id = ? AND {$vis['sql']}");
    $st->execute(array_merge([$id], $vis['params']));
    $event = $st->fetch();
    if ($event) {
        $event_link = get_site_url() . '/calendar.php?m=' . urlencode(substr($event['start_date'], 0, 7))
                    . '&open=' . (int)$event['id'] . '&date=' . urlencode($event['start_date']);
    }
}

if (!$event) {
    http_response_code(404);
    exit('Event not found.');
}

// ── Compute start/end ─────────────────────────────────────────────────────────
$all_day = empty($event['start_time']);
if ($all_day) {
    // Date-only event: DATE values, DTEND is exclusive (day after the last day).
    $lastDay  = $event['end_date'] ?: $event['start_date'];
    $ics_start = str_replace('-', '', $event['start_date']);
    $ics_end   = (new DateTime($lastDay))->modify('+1 day')->format('Ymd');
    $g_dates   = $ics_start . '/' . $ics_end;
} else {
    $start = new DateTime($event['start_date'] . ' ' . $event['start_time'], $site_tz);
    if (!empty($event['end_time'])) {
        $endBase = $event['end_date'] ?: $event['start_date'];
        $end = new DateTime($endBase . ' ' . $event['end_time'], $site_tz);
        if ($end <= $start) $end->modify('+1 day'); // cross-midnight without end_date
    } else {
        $end = (clone $start)->modify('+3 hours'); // no end time: assume a 3h game night
    }
    $start->setTimezone($utc_tz);
    $end->setTimezone($utc_tz);
    $ics_start = $start->format('Ymd\THis\Z');
    $ics_end   = $end->format('Ymd\THis\Z');
    $g_dates   = $ics_start . '/' . $ics_end;
}

$title    = (string)$event['title'];
$desc     = trim((string)($event['description'] ?? ''));
// Invitee-facing ICS gets the full location: "Venue Name, address" (either half optional).
$_venue   = trim((string)($event['venue_name'] ?? ''));
$_addr    = trim((string)($event['location'] ?? ''));
$location = trim($_venue . ($_venue !== '' && $_addr !== '' ? ', ' : '') . $_addr);

// ── Google Calendar redirect ──────────────────────────────────────────────────
if (!empty($_GET['google'])) {
    $g = 'https://calendar.google.com/calendar/render?action=TEMPLATE'
       . '&text=' . rawurlencode($title)
       . '&dates=' . rawurlencode($g_dates)
       . ($desc !== '' || $event_link !== '' ? '&details=' . rawurlencode(trim($desc . "\n\n" . $event_link)) : '')
       . ($location !== '' ? '&location=' . rawurlencode($location) : '');
    header('Location: ' . $g);
    exit;
}

// ── ICS download ──────────────────────────────────────────────────────────────
function ics_escape(string $s): string {
    return str_replace(["\\", ";", ",", "\r\n", "\n"], ["\\\\", "\\;", "\\,", "\\n", "\\n"], $s);
}

$host = parse_url(get_site_url(), PHP_URL_HOST) ?: 'gamenight';
$uid  = 'gamenight-event-' . (int)$event['id'] . '@' . $host;
$now  = (new DateTime('now', $utc_tz))->format('Ymd\THis\Z');

$lines = [
    'BEGIN:VCALENDAR',
    'VERSION:2.0',
    'PRODID:-//' . ics_escape($site_name) . '//GameNight//EN',
    'CALSCALE:GREGORIAN',
    'METHOD:PUBLISH',
    'BEGIN:VEVENT',
    'UID:' . $uid,
    'DTSTAMP:' . $now,
    $all_day ? 'DTSTART;VALUE=DATE:' . $ics_start : 'DTSTART:' . $ics_start,
    $all_day ? 'DTEND;VALUE=DATE:' . $ics_end     : 'DTEND:' . $ics_end,
    'SUMMARY:' . ics_escape($title),
];
if ($desc !== '' || $event_link !== '') {
    $lines[] = 'DESCRIPTION:' . ics_escape(trim($desc . ($event_link !== '' ? "\n\n" . $event_link : '')));
}
if ($location !== '') $lines[] = 'LOCATION:' . ics_escape($location);
if ($event_link !== '') $lines[] = 'URL:' . $event_link;
$lines[] = 'END:VEVENT';
$lines[] = 'END:VCALENDAR';

// RFC 5545 line folding: content lines SHOULD be <= 75 octets; continuation
// lines start with a single space. Fold on byte length but never split a
// multibyte UTF-8 character (back off past continuation bytes).
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

$fname = preg_replace('/[^A-Za-z0-9 _-]/', '', $title) ?: 'event';
header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $fname . '.ics"');
echo $out;
