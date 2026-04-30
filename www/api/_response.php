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
    // Public read API: anyone with a valid key gets the same answer, so client
    // caches and CDNs can hold it briefly without leaking per-user data.
    header('Cache-Control: public, max-age=' . max(0, $cache_seconds));
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
