<?php
/**
 * Shared JSON response helpers for the public read-only API at /api/v1/*.
 *
 * Every API response goes through api_ok() or api_fail() so the headers
 * (Content-Type, cache, CORS) stay identical regardless of which endpoint
 * is responding. Successful payloads are wrapped in {"ok": true, "data": ...}
 * and errors are {"ok": false, "error": "..."}, matching the convention used
 * by every _dl.php endpoint elsewhere in the site.
 */

function api_send_headers(int $cache_seconds = 60): void {
    static $sent = false;
    if ($sent) return;
    $sent = true;
    header('Content-Type: application/json; charset=utf-8');
    // A cacheable answer is a successful GET: the key holder's own browser or
    // consumer may hold it for a minute. It is `private`, never `public`, so
    // a shared cache in front of the site cannot serve one key's answer
    // (members' contact details, a league's pending list) to the next
    // caller. Writes, errors and uncached reads are not stored at all.
    $method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($cache_seconds > 0 && ($method === 'GET' || $method === 'HEAD')) {
        header('Cache-Control: private, max-age=' . (int)$cache_seconds);
    } else {
        header('Cache-Control: no-store');
    }
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: Authorization, Content-Type');
    header('Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS');
}

function api_ok(array $data, int $cache_seconds = 60): void {
    api_send_headers($cache_seconds);
    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_SLASHES);
    exit;
}

function api_fail(string $message, int $http_code = 400): void {
    api_send_headers(0);
    http_response_code($http_code);
    echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_SLASHES);
    exit;
}
