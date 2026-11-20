<?php
// CSP violation reports (auth.php: Reporting-Endpoints / report-to / report-uri).
//
// A browser POSTs here when the policy blocks something: an inline handler
// that slipped back in, a script without its nonce, a frame from a host not
// on the list. Each report becomes one line in the PHP error log, which is the
// container's stderr, so `docker logs gamenight | grep csp-report` shows them.
// Nothing is stored and nothing is echoed back.
//
// Two body shapes arrive: application/csp-report ({"csp-report": {...}}, from
// report-uri) and application/reports+json ([{"type":"csp-violation","body":
// {...}}], from report-to). Anyone can post here, so the body is size-capped,
// only known fields are logged, and each is cut short and stripped of control
// characters so a crafted report cannot forge extra log lines.

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}

$raw = file_get_contents('php://input', false, null, 0, 16384);

// One line per distinct report per day. The Trusted Types report-only policy
// (auth.php) fires on every innerHTML write of every page view; without this
// the log would repeat the same few hundred sinks all day. The seen-list is a
// day-stamped file beside the database, capped so a flood of posts made up to
// defeat the de-duplication cannot grow it without bound.
require_once __DIR__ . '/db.php';
function csp_report_seen(string $key): bool {
    $dir  = dirname(DB_PATH);
    $file = $dir . '/csp_seen-' . gmdate('Ymd') . '.txt';
    foreach (glob($dir . '/csp_seen-*.txt') ?: [] as $old) { if ($old !== $file) @unlink($old); }
    $h = hash('sha256', $key);
    $seen = @file_get_contents($file);
    if ($seen !== false && str_contains($seen, $h)) return true;
    if ($seen !== false && strlen($seen) > 262144) return true;   // cap: stop logging new ones today
    @file_put_contents($file, $h . "\n", FILE_APPEND | LOCK_EX);
    return false;
}
// One address may add at most 50 new lines a day (v1.39.0): a single sender
// could otherwise fill the day's seen-list with made-up reports, and every
// real report after that went unlogged.
function csp_report_ip_spent(): bool {
    $dir  = dirname(DB_PATH);
    $file = $dir . '/csp_ip-' . gmdate('Ymd') . '.txt';
    foreach (glob($dir . '/csp_ip-*.txt') ?: [] as $old) { if ($old !== $file) @unlink($old); }
    $ip = hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? ''));
    $seen = (string)@file_get_contents($file);
    if (strlen($seen) > 1048576) return true;
    return substr_count($seen, $ip) >= 50;
}
function csp_report_ip_count(): void {
    @file_put_contents(dirname(DB_PATH) . '/csp_ip-' . gmdate('Ymd') . '.txt', hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? '')) . "\n", FILE_APPEND | LOCK_EX);
}
$data = json_decode((string)$raw, true);
http_response_code(204);
if (!is_array($data)) exit;
// Every field is read as plain text (v1.39.0): a report is anyone's JSON, and an
// array where a string belongs raised a warning on each use.
$str = fn($v): string => is_scalar($v) ? (string)$v : '';

$reports = [];
if (isset($data['csp-report']) && is_array($data['csp-report'])) {
    $r = $data['csp-report'];
    $reports[] = [
        'mode'      => $str($r['disposition'] ?? ''),
        'directive' => $str($r['effective-directive'] ?? $r['violated-directive'] ?? ''),
        'blocked'   => $str($r['blocked-uri'] ?? ''),
        'page'      => $str($r['document-uri'] ?? ''),
        'source'    => $str($r['source-file'] ?? '') . (isset($r['line-number']) ? ':' . $str($r['line-number']) : ''),
        'sample'    => $str($r['script-sample'] ?? ''),
    ];
} elseif (array_is_list($data)) {
    foreach (array_slice($data, 0, 10) as $item) {
        if (!is_array($item) || ($item['type'] ?? '') !== 'csp-violation' || !is_array($item['body'] ?? null)) continue;
        $b = $item['body'];
        $reports[] = [
            'mode'      => $str($b['disposition'] ?? ''),
            'directive' => $str($b['effectiveDirective'] ?? ''),
            'blocked'   => $str($b['blockedURL'] ?? ''),
            'page'      => $str($b['documentURL'] ?? ($item['url'] ?? '')),
            'source'    => $str($b['sourceFile'] ?? '') . (isset($b['lineNumber']) ? ':' . $str($b['lineNumber']) : ''),
            'sample'    => $str($b['sample'] ?? ''),
        ];
    }
}

$clean = function ($v, int $max): string {
    $v = preg_replace('/[\x00-\x1F\x7F]+/', ' ', is_scalar($v) ? (string)$v : '');
    return mb_substr($v, 0, $max);
};
foreach ($reports as $r) {
    // The page is left out of the key: the same sink reached from two URLs is
    // one thing to fix.
    if (csp_report_ip_spent()) break;
    if (csp_report_seen($r['directive'] . '|' . $r['blocked'] . '|' . $r['source'] . '|' . mb_substr((string)$r['sample'], 0, 40))) continue;
    csp_report_ip_count();
    error_log(sprintf('csp-report mode=%s directive=%s blocked=%s page=%s source=%s sample=%s',
        $clean($r['mode'], 10), $clean($r['directive'], 60), $clean($r['blocked'], 200), $clean($r['page'], 200),
        $clean($r['source'], 200), $clean($r['sample'], 120)));
}
