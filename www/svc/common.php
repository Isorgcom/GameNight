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

/**
 * A one-time confirmation for a risky action from the API or a connected app
 * (v1.38.0). Called without a valid code, it answers 409 confirmation_required
 * with $preview (what would happen) and a fresh code, good once for 10 minutes
 * and only for this person, this token, this action and this target. The same
 * call with "confirm": "<code>" then goes ahead. A bare true is no longer
 * enough: an assistant could set that on the first call because text it read
 * told it to, and the code makes it come back with what it was shown. $target
 * pins what is confirmed (for a message to guests, a hash of its text, so the
 * text cannot change after the preview). The website has its own dialogs and
 * is not asked.
 */
function gn_confirm(PDO $db, array $actor, string $action, string $target, $given, string $preview): ?array {
    if (($actor['via'] ?? '') === 'web') return null;
    $uid = (int)$actor['id']; $tid = isset($actor['token_id']) ? (int)$actor['token_id'] : null;
    if (is_string($given) && preg_match('/^cfm_[a-f0-9]{24}$/', $given)) {
        $u = $db->prepare("UPDATE confirm_codes SET used_at = CURRENT_TIMESTAMP
                           WHERE code_hash = ? AND user_id = ? AND COALESCE(token_id, 0) = ? AND action = ? AND target = ?
                             AND used_at IS NULL AND expires_at > datetime('now')");
        // COALESCE() has no column affinity, so the token id is bound as an
        // integer: bound as PDO's default text, 99 never equals '99' here.
        $u->bindValue(1, hash('sha256', $given)); $u->bindValue(2, $uid, PDO::PARAM_INT); $u->bindValue(3, (int)$tid, PDO::PARAM_INT);
        $u->bindValue(4, $action); $u->bindValue(5, $target);
        $u->execute();
        if ($u->rowCount() === 1) return null;
    }
    $code = 'cfm_' . bin2hex(random_bytes(12));
    $db->prepare("INSERT INTO confirm_codes (code_hash, user_id, token_id, action, target, expires_at) VALUES (?, ?, ?, ?, ?, datetime('now', '+10 minutes'))")
       ->execute([hash('sha256', $code), $uid, $tid, $action, $target]);
    $lead = is_string($given) && $given !== '' ? 'That confirmation code is not valid for this (it is used, expired, or for something else). ' : '';
    return gn_err('confirmation_required', $lead . $preview . ' Ask the person; if they agree, send the same request again with "confirm": "' . $code . '" (good once, for 10 minutes).', 409)
        + ['confirm_code' => $code];
}

/**
 * Refuse an action that would tell people something when the actor's token
 * lacks the `messages` scope, else null (v1.36.0). Sending is one checkbox: a
 * connection allowed to change events but not to send must not reach anyone
 * by cancelling, approving or removing either. The web actor has every scope.
 */
function gn_needs_messages(array $actor, string $what): ?array {
    if (gn_actor_has_scope($actor, 'messages')) return null;
    return gn_err('messages_permission_needed',
        "$what tells people, and this connection is not allowed to send messages. Do it on the site, or turn on the Messages permission for this app under Settings, Connected Apps.", 403);
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
