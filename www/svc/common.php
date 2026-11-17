<?php
/**
 * Service layer: shared pieces.
 *
 * A service function is what a web endpoint, the REST API and the MCP endpoint
 * all call to do one thing. It takes the database, the ACTOR doing it and an
 * array of arguments, and returns an array. It never reads $_POST / $_GET,
 * never calls current_user(), and never echoes or exits, so it can be reached
 * from any entry point.
 *
 *   $actor = ['id' => int, 'is_admin' => bool, 'via' => 'web'|'api'|'mcp',
 *             'token_id' => ?int, 'scopes' => string[]]
 *
 *   success: ['ok' => true, ...data]
 *   failure: ['ok' => false, 'code' => 'snake_case', 'message' => 'sentence', 'http' => 4xx]
 *
 * Permission is decided INSIDE the service function, by the same helpers the
 * pages use (can_manage_event, league_role, event_visibility_sql), so an entry
 * point cannot forget it and cannot widen it.
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../api/_time.php';

function gn_ok(array $data = []): array {
    return ['ok' => true] + $data;
}

function gn_err(string $code, string $message, int $http = 400): array {
    return ['ok' => false, 'code' => $code, 'message' => $message, 'http' => $http];
}

/** The actor for a signed-in web request. */
function gn_web_actor(array $user): array {
    return ['id' => (int)$user['id'], 'is_admin' => ($user['role'] ?? '') === 'admin',
            'via' => 'web', 'token_id' => null, 'scopes' => ['*']];
}

function gn_actor_has_scope(array $actor, string $scope): bool {
    $s = $actor['scopes'] ?? [];
    return in_array('*', $s, true) || in_array($scope, $s, true);
}

/** 'YYYY-MM-DD HH:MM:SS' (UTC, as SQLite stores it) to ISO 8601 with a Z, or null. */
function gn_iso(?string $db_utc): ?string {
    $db_utc = trim((string)$db_utc);
    if ($db_utc === '') return null;
    return api_db_utc_to_iso($db_utc);
}

/** A stored local date + time pair (site timezone) to ISO 8601 UTC; date-only stays a date. */
function gn_iso_local(?string $date, ?string $time): ?string {
    static $site = null, $utc = null;
    if ($site === null) {
        $site = new DateTimeZone(get_setting('timezone', 'UTC'));
        $utc  = new DateTimeZone('UTC');
    }
    return api_local_to_utc_iso((string)$date, (string)$time, $site, $utc);
}

/**
 * The event row if the actor may SEE it, else null. Missing and not-visible
 * are the same answer on purpose: an id probe learns nothing.
 */
function gn_event_visible(PDO $db, array $actor, int $event_id): ?array {
    if ($event_id <= 0) return null;
    $vis = $actor['is_admin'] ? ['sql' => '1=1', 'params' => []] : event_visibility_sql('e', (int)$actor['id']);
    $st = $db->prepare("SELECT e.* FROM events e WHERE e.id = ? AND {$vis['sql']}");
    $st->execute(array_merge([$event_id], $vis['params']));
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function gn_can_manage_event(PDO $db, array $actor, int $event_id): bool {
    return can_manage_event($db, $event_id, (int)$actor['id'], (bool)$actor['is_admin']);
}

/**
 * The non-exiting twin of verify_event_access() (_poker_helpers.php): the
 * event row when the actor may MANAGE it, or a failure array. A cancelled
 * event is refused unless $allow_cancelled (reads may look at one).
 * Callers test `isset($r['ok'])` to tell the two apart.
 */
function gn_event_guard(PDO $db, array $actor, int $event_id, bool $allow_cancelled = false): array {
    $st = $db->prepare('SELECT * FROM events WHERE id = ?');
    $st->execute([$event_id]);
    $ev = $st->fetch(PDO::FETCH_ASSOC);
    // An event the actor cannot even see is "not found", like a missing one.
    if (!$ev || !gn_event_visible($db, $actor, $event_id)) {
        return gn_err('event_not_found', 'No such event.', 404);
    }
    if (!gn_can_manage_event($db, $actor, $event_id)) {
        return gn_err('not_event_host', 'Only the host of this event can do that.', 403);
    }
    if (!$allow_cancelled && !empty($ev['cancelled_at'])) {
        return gn_err('event_cancelled', 'This event was cancelled.', 409);
    }
    return $ev;
}

/**
 * The actor's standing in a league: 'owner' | 'manager' | 'member', 'admin'
 * for a site admin who is not a member, or null for an outsider.
 */
function gn_league_standing(array $actor, int $league_id): ?string {
    $role = league_role($league_id, (int)$actor['id']);
    if ($role !== null) return $role;
    return $actor['is_admin'] ? 'admin' : null;
}
