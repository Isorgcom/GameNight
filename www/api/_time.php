<?php
/**
 * Shared time helpers for the public API. Originally inlined in
 * www/api/v1/events.php; extracted so other endpoints (posts, etc.) can
 * accept and emit ISO-8601 UTC instants without duplicating the logic.
 *
 * The site stores most user-facing dates/times in the site's display
 * timezone (calendar dates, event times). API consumers shouldn't have
 * to know that timezone, so write paths convert UTC inbound → local
 * stored, and read paths convert local stored → UTC outbound.
 *
 * `created_at` columns are an exception: those are SQLite's
 * CURRENT_TIMESTAMP (UTC by default). They get a simpler shape converter.
 */

if (!function_exists('api_local_to_utc_iso')) {
    /**
     * Convert a stored (local) date + optional time pair into an ISO-8601 string.
     *  - "" / no date           → null
     *  - date only (no time)    → "YYYY-MM-DD"
     *  - date + time (HH:MM)    → "YYYY-MM-DDTHH:MM:SSZ" in UTC
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
}

if (!function_exists('api_db_utc_to_iso')) {
    /**
     * `created_at` is stored as 'YYYY-MM-DD HH:MM:SS' in UTC (sqlite default).
     * Render it as ISO-8601 with a trailing Z so consumers don't have to guess.
     */
    function api_db_utc_to_iso(string $val): string {
        if ($val === '') return '';
        return str_replace(' ', 'T', $val) . 'Z';
    }
}

if (!function_exists('api_parse_inbound_at')) {
    /**
     * Parse an inbound start_at / end_at / published_at value into a stored
     * (date, time-or-null) pair in the site's display timezone. Accepts:
     *  - "YYYY-MM-DD"                          → all-day, time = null
     *  - "YYYY-MM-DDTHH:MM:SSZ"                → UTC instant
     *  - "YYYY-MM-DDTHH:MM:SS+HH:MM"           → instant with offset
     *  - "YYYY-MM-DDTHH:MMZ" / "...+HH:MM"     → seconds optional
     * Returns ['YYYY-MM-DD', 'HH:MM' | null], or null if unparseable.
     */
    function api_parse_inbound_at(string $raw, DateTimeZone $site_tz): ?array {
        $raw = trim($raw);
        if ($raw === '') return null;
        // Date-only, all-day
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
}
