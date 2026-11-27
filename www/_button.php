<?php
/**
 * DealerButton pairing (v1.42.1): a button paired ONCE to an account, which
 * then always shows that host's current game and moves on to the next game by
 * itself. Spec: Trilium vJKfuzpryuew, "Addition 2, detailed spec: follow my
 * next game"; the firmware is written against these names, so change both.
 *
 *  1. The button mints a code: tv_dl.php?action=new&device=button.
 *  2. The host enters it on /button (button_pair.php); Send to TV passes a
 *     button's code there, and /button refuses a TV's code.
 *  3. The button's poll collects {"button_token": 64 hex, "name"} exactly
 *     once. Only the token's SHA-256 is stored, and the token is minted at
 *     collection, so it never sits in the database in the clear.
 *  4. get_brief with "Authorization: Bearer <token>" (or &button=<token>)
 *     answers the current game (button_current_game) or idle; an unknown or
 *     removed token is 401 "revoked".
 *  5. Settings > Security > DealerButtons lists, renames and removes them.
 *     Remove sets revoked_at; the next poll is refused.
 *
 * A token buys exactly what a TV showing the host's game gets: the clock,
 * read-only. It can never send a command (those need a signed-in manager and
 * a CSRF token) and is accepted nowhere but get_brief.
 *
 * Firmware that pairs without device=button still goes through Send to TV and
 * gets a game's timer key, as before.
 */

require_once __DIR__ . '/_tv_pair.php';

// Buttons one account may have paired at once.
const BUTTON_MAX_PER_USER = 20;
// last_seen_at / last_ip are written at most this often, not on every poll.
const BUTTON_SEEN_EVERY_SEC = 60;
// A finished game stays on the button this long ("Game over"), then it moves on.
const BUTTON_FINISHED_SHOW_MIN = 30;
// A game in setup is shown when its event starts today or within this many hours.
const BUTTON_SETUP_AHEAD_HOURS = 12;

/** A claimed device row whose token was never collected is dropped after this. */
function button_drop_uncollected(PDO $db): void {
    $db->exec("DELETE FROM button_devices WHERE token_hash IS NULL AND created_at < datetime('now', '-" . (TV_CODE_MINUTES + 5) . " minutes')");
}

/** The button token on this request (Bearer header, else &button=), '' if none. */
function button_token_from_request(): string {
    $hdr = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if ($hdr === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            if (strcasecmp($k, 'Authorization') === 0) { $hdr = (string)$v; break; }
        }
    }
    $tok = stripos($hdr, 'Bearer ') === 0 ? trim(substr($hdr, 7)) : trim((string)($_GET['button'] ?? ''));
    return preg_match('/^[0-9a-f]{64}$/', $tok) ? $tok : '';
}

/** The live (not removed) device row for a token, or null. Notes the visit, at most once a minute. */
function button_device_for_token(PDO $db, string $token): ?array {
    if ($token === '') return null;
    $q = $db->prepare('SELECT * FROM button_devices WHERE token_hash = ? AND revoked_at IS NULL');
    $q->execute([hash('sha256', $token)]);
    $dev = $q->fetch(PDO::FETCH_ASSOC);
    if (!$dev) return null;
    $db->prepare("UPDATE button_devices SET last_seen_at = datetime('now'), last_ip = ?
                  WHERE id = ? AND (last_seen_at IS NULL OR last_seen_at < datetime('now', ?))")
       ->execute([get_client_ip(), (int)$dev['id'], '-' . BUTTON_SEEN_EVERY_SEC . ' seconds']);
    return $dev;
}

/**
 * The SQL condition "this user runs event e": created it, is an event
 * manager, or owns/manages its league. The rule Send to TV lists games by
 * (user_poker_events); an admin gets only their own here too, as there.
 * Binds the user id three times.
 */
function button_runs_sql(): string {
    return "(e.created_by = ?
             OR EXISTS (SELECT 1 FROM event_invites ei
                        WHERE ei.event_id = e.id AND ei.user_id = ? AND ei.event_role = 'manager')
             OR EXISTS (SELECT 1 FROM league_members lm
                        WHERE lm.league_id = e.league_id AND lm.user_id = ? AND lm.role IN ('owner','manager')))";
}

/** An event's start as epoch ms in the site timezone, or null. */
function button_event_start_ms(?string $date, ?string $time): ?int {
    if (!$date) return null;
    $ts = strtotime($date . ' ' . ($time ?: '00:00'));
    return $ts === false ? null : $ts * 1000;
}

/**
 * The game a host's button shows. Candidates: tournaments on events the host
 * runs, not cancelled. In order:
 *   1. one being played (status active, running or paused), most recently
 *      started first;
 *   2. one finished in the last BUTTON_FINISHED_SHOW_MIN minutes ("Game over");
 *   3. one in setup whose event is today or starts within
 *      BUTTON_SETUP_AHEAD_HOURS hours, soonest first.
 * Returns the session id, or null for idle.
 */
function button_current_game(PDO $db, int $user_id): ?int {
    $base = "FROM events e
             JOIN poker_sessions ps ON ps.event_id = e.id
             LEFT JOIN timer_state t ON t.session_id = ps.id
             WHERE e.cancelled_at IS NULL
               AND COALESCE(ps.game_type, 'tournament') <> 'cash'
               AND " . button_runs_sql();
    $who = [$user_id, $user_id, $user_id];

    $q = $db->prepare("SELECT ps.id $base AND ps.status = 'active'
                       ORDER BY COALESCE(ps.started_at, t.started_at, t.updated_at, '') DESC, ps.id DESC LIMIT 1");
    $q->execute($who);
    if (($sid = $q->fetchColumn()) !== false) return (int)$sid;

    $q = $db->prepare("SELECT ps.id $base AND ps.status = 'finished'
                         AND ps.finished_at >= datetime('now', ?)
                       ORDER BY ps.finished_at DESC LIMIT 1");
    $q->execute(array_merge($who, ['-' . BUTTON_FINISHED_SHOW_MIN . ' minutes']));
    if (($sid = $q->fetchColumn()) !== false) return (int)$sid;

    // Setup: dated today (site timezone), or starting inside the window, which
    // covers a game dated tomorrow that starts early, seen the evening before.
    // Worked out in PHP because the stored date and time are site-local, not UTC.
    $today = date('Y-m-d');
    $until = date('Y-m-d', time() + BUTTON_SETUP_AHEAD_HOURS * 3600);
    $q = $db->prepare("SELECT ps.id, e.start_date, e.start_time $base AND ps.status = 'setup'
                         AND e.start_date >= ? AND e.start_date <= ?
                       ORDER BY e.start_date, e.start_time, ps.id");
    $q->execute(array_merge($who, [$today, $until]));
    $now_ms = time() * 1000;
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $start = button_event_start_ms($r['start_date'], $r['start_time']);
        if ($r['start_date'] === $today || ($start !== null && $start - $now_ms <= BUTTON_SETUP_AHEAD_HOURS * 3600 * 1000)) {
            return (int)$r['id'];
        }
    }
    return null;
}

/** The host's next upcoming tournament for the idle screen: ['title', 'starts_at_ms'] or null. */
function button_next_event(PDO $db, int $user_id): ?array {
    $q = $db->prepare("SELECT e.title, e.start_date, e.start_time
        FROM events e JOIN poker_sessions ps ON ps.event_id = e.id
        WHERE e.cancelled_at IS NULL AND COALESCE(ps.game_type, 'tournament') <> 'cash'
          AND ps.status = 'setup' AND e.start_date >= ? AND " . button_runs_sql() . "
        ORDER BY e.start_date, e.start_time, e.id LIMIT 5");
    $q->execute([date('Y-m-d'), $user_id, $user_id, $user_id]);
    $now_ms = time() * 1000;
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $start = button_event_start_ms($r['start_date'], $r['start_time']);
        if ($start === null || $start < $now_ms) continue;   // earlier today: not "next"
        $title = (string)$r['title'];
        if (mb_strlen($title) > 80) $title = mb_substr($title, 0, 80);
        return ['title' => $title, 'starts_at_ms' => $start];
    }
    return null;
}

/** The live pairing a code names (TV's or button's), or null when used or expired. */
function button_pairing_for_code(PDO $db, string $code): ?array {
    $q = $db->prepare("SELECT id, device FROM tv_pairings WHERE code = ? AND target IS NULL AND expires_at >= datetime('now')");
    $q->execute([$code]);
    $row = $q->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/** How many buttons this account has paired and not removed. */
function button_count(PDO $db, int $user_id): int {
    $q = $db->prepare('SELECT COUNT(*) FROM button_devices WHERE user_id = ? AND token_hash IS NOT NULL AND revoked_at IS NULL');
    $q->execute([$user_id]);
    return (int)$q->fetchColumn();
}

/** The host's half: make the device row and point the pairing at it. */
function button_claim(PDO $db, int $pairing_id, int $user_id, string $name): bool {
    button_drop_uncollected($db);
    $db->beginTransaction();
    try {
        $db->prepare('INSERT INTO button_devices (user_id, name) VALUES (?, ?)')->execute([$user_id, $name]);
        $dev_id = (int)$db->lastInsertId();
        $u = $db->prepare("UPDATE tv_pairings SET target = ?, paired_by = ?, paired_at = datetime('now')
                           WHERE id = ? AND target IS NULL AND device = 'button'");
        $u->execute(['button:' . $dev_id, $user_id, $pairing_id]);
        if ($u->rowCount() !== 1) { $db->rollBack(); return false; }
        $db->commit();
        return true;
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

/**
 * The button's half, from tv_dl.php's poll: mint the token for a claimed
 * pairing, keep its hash, hand it over once. ['button_token', 'name'], or null
 * when the device row is gone or was removed before the button collected it.
 */
function button_collect(PDO $db, int $device_id): ?array {
    // A claim is collected within seconds by the button that asked for it.
    // One left lying (v1.45.2) is dropped here too, not only when the next
    // host pairs: until then it could be collected at any later time, by
    // whoever held the pairing's secret, and it is not listed in Settings.
    button_drop_uncollected($db);
    $token = bin2hex(random_bytes(32));
    $u = $db->prepare('UPDATE button_devices SET token_hash = ? WHERE id = ? AND token_hash IS NULL AND revoked_at IS NULL');
    $u->execute([hash('sha256', $token), $device_id]);
    if ($u->rowCount() !== 1) return null;
    $n = $db->prepare('SELECT name FROM button_devices WHERE id = ?');
    $n->execute([$device_id]);
    return ['button_token' => $token, 'name' => (string)$n->fetchColumn()];
}

/** Tidy a host-typed name: one line, at most 40 characters, a default if empty. */
function button_clean_name(string $raw): string {
    $n = trim(preg_replace('/\s+/u', ' ', $raw) ?? '');
    if (mb_strlen($n) > 40) $n = mb_substr($n, 0, 40);
    return $n === '' ? 'DealerButton' : $n;
}
