<?php
/**
 * FinalTable: the client, and everything shared between the pages that make,
 * drive and record an online game.
 *
 * A poker tournament marked "Online at <app>" (events.online_app_id → an
 * sso_apps row that holds a game key) is played on that FinalTable server.
 * The organiser presses "Set up the table" on the event page; this side
 * POSTs /api/games with the roster, the blinds and a webhook, keeps what
 * came back in finaltable_games, and from then on FinalTable reports every
 * bust-out, re-entry, level and the ending to finaltable_webhook.php, which
 * writes them into the same poker_players rows an in-person game uses.
 *
 * The contract is FinalTable's docs/API.md. Every call here is made by a
 * person pressing a button (or by a delete they asked for), so there is no
 * retry queue: a refusal is shown to them in FinalTable's own words.
 *
 * Shared include: denied to the browser by .htaccess.
 */
require_once __DIR__ . '/db.php';

/**
 * One request to a FinalTable server. Returns the envelope FinalTable answers
 * with, flattened: ['ok' => bool, 'status' => int, 'data' => mixed, 'error' => string].
 * A transport failure is ok:false with status 0. Never throws, never logs the key.
 */
function finaltable_request(array $app, string $method, string $path, ?array $body = null, bool $keyed = true): array {
    $name = (string)($app['name'] ?? 'FinalTable');
    if (!str_starts_with($path, '/api/')) {
        return ['ok' => false, 'status' => 0, 'data' => null, 'error' => 'Bad path'];
    }
    $headers = ['Accept: application/json'];
    if ($keyed) {
        $key = decrypt_value((string)($app['api_key'] ?? ''));
        if ($key === '') {
            return ['ok' => false, 'status' => 0, 'data' => null,
                    'error' => "No game key for $name; an admin sets it under Connected Apps."];
        }
        $headers[] = 'Authorization: Bearer ' . $key;
    }
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT        => 8,
        // A redirect would carry the bearer somewhere else. FinalTable never
        // redirects an API call, so nothing is lost by refusing to follow.
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_USERAGENT      => 'GameNight/' . (defined('APP_VERSION') ? APP_VERSION : 'dev'),
    ];
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        $opts[CURLOPT_POSTFIELDS] = json_encode($body);
    }
    $opts[CURLOPT_HTTPHEADER] = $headers;

    $ch = curl_init(rtrim((string)$app['base_url'], '/') . $path);
    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    // curl_close() intentionally omitted — a no-op (and deprecated) on PHP 8+.

    if ($cerr !== '' || $resp === false) {
        return ['ok' => false, 'status' => 0, 'data' => null, 'error' => "Cannot reach $name: " . ($cerr ?: 'no answer')];
    }
    $j = json_decode((string)$resp, true);
    if (!is_array($j)) {
        if ($code === 429) return ['ok' => false, 'status' => 429, 'data' => null, 'error' => "$name is busy; try again in a minute."];
        return ['ok' => false, 'status' => $code, 'data' => null, 'error' => "$name answered $code without saying why."];
    }
    return [
        'ok'     => !empty($j['ok']),
        'status' => $code,
        'data'   => $j['data'] ?? null,
        'error'  => (string)($j['error'] ?? ($code >= 400 ? "$name answered $code." : '')),
        'raw'    => $j,
    ];
}

/**
 * Prove the address and the key: ['ok' => bool, 'msg' => string]. The unkeyed
 * status route says whether anything FinalTable-shaped answers there at all;
 * the keyed read of a game that does not exist is a 404 when the key is
 * accepted and FinalTable's own 401 sentence when it is not.
 */
function finaltable_probe(array $app): array {
    $name = (string)($app['name'] ?? 'FinalTable');
    $s = finaltable_request($app, 'GET', '/api/status', null, false);
    if ($s['status'] === 0) return ['ok' => false, 'msg' => $s['error']];
    $raw = $s['raw'] ?? null;   // /api/status is not in the {ok,data} envelope
    if ($s['status'] !== 200 || !is_array($raw) || !isset($raw['activeTournaments'])) {
        return ['ok' => false, 'msg' => "Nothing at {$app['base_url']} answers like FinalTable (HTTP {$s['status']})."];
    }
    $running = (int)$raw['activeTournaments'];
    $k = finaltable_request($app, 'GET', '/api/games/probe');
    if ($k['status'] === 404) {
        return ['ok' => true, 'msg' => "$name answered: $running game" . ($running === 1 ? '' : 's') . " running; the key is accepted."];
    }
    if ($k['status'] === 401) return ['ok' => false, 'msg' => $k['error']];
    return ['ok' => false, 'msg' => $k['error'] !== '' ? $k['error'] : "$name answered {$k['status']} to a keyed request."];
}

/** The connected app an event is played on, or null when it is not online, the app is disabled or has no key. */
function finaltable_app_for_event(PDO $db, int $event_id): ?array {
    $q = $db->prepare("SELECT a.* FROM sso_apps a JOIN events e ON e.online_app_id = a.id
                       WHERE e.id = ? AND a.enabled = 1 AND a.api_key IS NOT NULL AND a.api_key <> ''");
    $q->execute([$event_id]);
    return $q->fetch() ?: null;
}

/** The finaltable_games row for an event, or null. Carries the encrypted secret: never send it to a browser. */
function finaltable_game_for_event(PDO $db, int $event_id): ?array {
    $q = $db->prepare('SELECT * FROM finaltable_games WHERE event_id = ?');
    $q->execute([$event_id]);
    return $q->fetch() ?: null;
}

/**
 * The join and watch links. FinalTable's own links are null until its Mail
 * tab holds a public address; the game exists either way and we know where
 * the server is, so the fallback is built from base_url and the codes.
 */
function finaltable_links(array $app, array $row): array {
    $base = rtrim((string)($app['base_url'] ?? ''), '/');
    $join = (string)($row['join_url'] ?? '');
    $rail = (string)($row['rail_url'] ?? '');
    if ($join === '' && !empty($row['code'])) $join = $base . '/?t=' . rawurlencode((string)$row['code']);
    if ($rail === '' && !empty($row['rail'])) $rail = $base . '/?w=' . rawurlencode((string)$row['rail']);
    return ['join' => $join, 'rail' => $rail];
}

/** A finaltable_games row shaped for a browser: no secret, JSON columns decoded, links filled in. */
function finaltable_public_game(array $row, array $app): array {
    $links = finaltable_links($app, $row);
    return [
        'game_id'           => (string)$row['game_id'],
        'code'              => $row['code'],
        'rail'              => $row['rail'],
        'join_url'          => $links['join'],
        'rail_url'          => $links['rail'],
        'status'            => (string)$row['status'],
        'outcome'           => $row['outcome'],
        'reason'            => $row['reason'],
        'last_status'       => $row['last_status'] ? (json_decode((string)$row['last_status'], true) ?: null) : null,
        'last_event_at'     => $row['last_event_at'],
        'last_heartbeat_at' => $row['last_heartbeat_at'],
        'started_at'        => $row['started_at'],
        'ended_at'          => $row['ended_at'],
        'created_at'        => $row['created_at'],
    ];
}

/**
 * Who goes to the table. The organiser (events.created_by) hosts, first and
 * flagged manager whether or not they hold an invite row; then every approved
 * invitee who has an account here. The user id is the invite's stored id and
 * the name is the account's - never the string somebody typed on the invite,
 * which is what authority resolves through (see CLAUDE.md). Invitees without
 * an account cannot be sent: FinalTable seats people by GameNight id.
 *
 * Returns ['invitees' => [[user_id, username, manager?]...], 'skipped' => [names], 'host' => row|null].
 */
function finaltable_roster(PDO $db, array $ev): array {
    $invitees = []; $seen = []; $skipped = [];
    $h = $db->prepare('SELECT id, username FROM users WHERE id = ?');
    $h->execute([(int)$ev['created_by']]);
    $host = $h->fetch() ?: null;
    if ($host) {
        $invitees[] = ['user_id' => (int)$host['id'], 'username' => (string)$host['username'], 'manager' => true];
        $seen[(int)$host['id']] = true;
    }
    $q = $db->prepare("SELECT ei.username AS invite_name, ei.user_id, u.username
                       FROM event_invites ei LEFT JOIN users u ON u.id = ei.user_id
                       WHERE ei.event_id = ? AND ei.occurrence_date IS NULL AND ei.approval_status = 'approved'
                       ORDER BY COALESCE(ei.sort_order, 999999), ei.username");
    $q->execute([(int)$ev['id']]);
    foreach ($q->fetchAll() as $r) {
        $uid = (int)($r['user_id'] ?? 0);
        if ($uid <= 0 || $r['username'] === null) { $skipped[] = (string)$r['invite_name']; continue; }
        if (isset($seen[$uid])) continue;
        $seen[$uid] = true;
        $invitees[] = ['user_id' => $uid, 'username' => (string)$r['username']];
    }
    return ['invitees' => $invitees, 'skipped' => $skipped, 'host' => $host];
}

/**
 * The blind schedule FinalTable will be sent, in its own row shape, plus a
 * note when it is not the one the organiser might expect. The session's
 * schedule first; the library's default preset when the session has none;
 * nothing at all (FinalTable then plays its Standard structure) when neither
 * exists or the blinds are in cents, which FinalTable's whole-chip table
 * would drop level by level.
 */
function finaltable_blind_levels(PDO $db, int $session_id, int $user_id = 0): array {
    $note = null; $name = null;
    pk_ensure_timer_row($db, $session_id, $user_id > 0 ? pk_user_beta_pref($db, $user_id) : 0);
    $rows = pk_session_blind_levels($db, $session_id);
    if ($rows) {
        // The schedule's own name: FinalTable shows it in its lobby as the structure.
        $n = $db->prepare('SELECT bp.name FROM timer_state ts JOIN blind_presets bp ON bp.id = ts.preset_id WHERE ts.session_id = ?');
        $n->execute([$session_id]);
        $name = (string)($n->fetchColumn() ?: 'GameNight');
    } else {
        $d = $db->prepare('SELECT id, name FROM blind_presets WHERE is_default = 1 AND session_id IS NULL ORDER BY id LIMIT 1');
        $d->execute();
        if ($def = $d->fetch()) {
            $l = $db->prepare('SELECT small_blind, big_blind, ante, duration_minutes, is_break
                               FROM blind_preset_levels WHERE preset_id = ? ORDER BY level_number');
            $l->execute([(int)$def['id']]);
            $rows = pk_clean_blind_levels($l->fetchAll(PDO::FETCH_ASSOC));
            if ($rows) { $name = (string)$def['name']; $note = 'No blind schedule is set for this game, so the default preset "' . $def['name'] . '" goes.'; }
        }
    }
    $levels = [];
    foreach ($rows as $r) {
        $isBreak = !empty($r['is_break']);
        $sb = (int)round((float)$r['small_blind']);
        if (!$isBreak && $sb < 1) {
            return ['levels' => [], 'name' => null,
                    'note' => "The blinds here are in cents; FinalTable plays whole chips, so its Standard structure applies."];
        }
        $levels[] = [
            'small_blind'      => $sb,
            'big_blind'        => (int)round((float)$r['big_blind']),
            'ante'             => (int)round((float)$r['ante']),
            'duration_minutes' => (int)$r['duration_minutes'],
            'is_break'         => $isBreak ? 1 : 0,
        ];
    }
    if (!$levels) $note = "No blind schedule is set for this game, so FinalTable's Standard structure applies.";
    return ['levels' => $levels, 'name' => $name, 'note' => $note];
}

/**
 * Everything the page and the setup action need to agree on before the
 * button is pressed: the app, the session, the roster, the blinds, the
 * start, and any reason it cannot be done. ['ok' => bool, 'error' => ?string, ...facts].
 */
function finaltable_setup_preview(PDO $db, array $ev, int $user_id = 0): array {
    $out = ['ok' => false, 'error' => null, 'app' => null, 'session' => null, 'roster' => null,
            'blinds' => ['levels' => [], 'name' => null, 'note' => null], 'start_at' => null, 'start_note' => null,
            'seats' => 8, 'chips' => 5000, 'buyin' => 0, 'addon' => false, 'reentry_levels' => 0, 'notes' => []];
    if ((int)($ev['is_poker'] ?? 0) !== 1 || empty($ev['online_app_id'])) {
        $out['error'] = 'This event is not played online.'; return $out;
    }
    $app = finaltable_app_for_event($db, (int)$ev['id']);
    if (!$app) {
        $a = $db->prepare('SELECT name, enabled FROM sso_apps WHERE id = ?');
        $a->execute([(int)$ev['online_app_id']]);
        $ar = $a->fetch();
        $out['error'] = !$ar ? 'The app this event was to be played on no longer exists.'
                      : (!(int)$ar['enabled'] ? "{$ar['name']} is disabled under Connected Apps."
                      : "{$ar['name']} has no game key; an admin sets it under Connected Apps.");
        return $out;
    }
    $out['app'] = $app;
    $s = $db->prepare('SELECT * FROM poker_sessions WHERE event_id = ?');
    $s->execute([(int)$ev['id']]);
    $sess = $s->fetch();
    if (!$sess) { $out['error'] = 'This event has no game session.'; return $out; }
    if (($sess['game_type'] ?? '') !== 'tournament') { $out['error'] = 'Only a tournament can be played on FinalTable.'; return $out; }
    $out['session'] = $sess;

    $out['seats']          = max(2, min(8, (int)($sess['seats_per_table'] ?? 8)));
    // A session made before chips were a setting carries 0; FinalTable's own
    // default is the one to show, not a zero that reads as a mistake.
    $out['chips']          = (int)($sess['starting_chips'] ?? 0) > 0 ? (int)$sess['starting_chips'] : 5000;
    $out['buyin']          = min(10000, intdiv(max(0, (int)($sess['buyin_amount'] ?? 0)), 100));
    $out['addon']          = !empty($sess['addon_allowed']);
    $out['reentry_levels'] = !empty($sess['rebuy_allowed']) ? 3 : 0;
    if ((int)($sess['seats_per_table'] ?? 8) > 8) $out['notes'][] = 'FinalTable seats eight to a table; ' . (int)$sess['seats_per_table'] . ' was asked for.';
    if (!in_array($out['chips'], [1000, 2000, 5000, 10000], true)) $out['notes'][] = 'FinalTable stacks are 1000, 2000, 5000 or 10000 chips; ' . $out['chips'] . ' becomes 5000.';

    $out['roster'] = finaltable_roster($db, $ev);
    if (!$out['roster']['host']) { $out['error'] = 'The organiser of this event no longer has an account.'; return $out; }
    if (count($out['roster']['invitees']) < 2) { $out['error'] = 'Nobody else on the guest list has an account here, and FinalTable seats people by account.'; return $out; }
    if (count($out['roster']['invitees']) > 200) { $out['error'] = 'FinalTable takes a guest list of two hundred at most.'; return $out; }

    // The start, as an instant. Stored wall-clock is in the site's timezone.
    if (!empty($ev['start_time'])) {
        try {
            $site_tz = new DateTimeZone(get_setting('timezone', 'UTC'));
            $start   = new DateTime($ev['start_date'] . ' ' . $ev['start_time'], $site_tz);
            $now     = new DateTime('now', $site_tz);
            $limit   = (clone $now)->modify('+7 days');
            if ($start > $limit) {
                $when = (clone $start)->modify('-7 days');
                $out['error'] = 'FinalTable takes a game up to seven days ahead; come back on ' . $when->format('l, M j') . '.';
                return $out;
            }
            if ($start > $now) {
                $out['start_at'] = (clone $start)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
            } else {
                $out['start_note'] = 'The start time has passed, so the clock runs from whenever the host starts it.';
            }
        } catch (Throwable $e) { $out['start_note'] = 'The start time could not be read; the host starts the clock by hand.'; }
    } else {
        $out['start_note'] = 'This is an all-day event, so the host starts the clock by hand.';
    }

    $out['blinds'] = finaltable_blind_levels($db, (int)$sess['id'], $user_id);
    if ($out['blinds']['note']) $out['notes'][] = $out['blinds']['note'];
    if ($out['start_note']) $out['notes'][] = $out['start_note'];
    $out['ok'] = true;
    return $out;
}

/**
 * End a player's sessions on every FinalTable that has a key. Called when a
 * member is removed from a league or an account is deleted. 404 is the
 * ordinary answer for somebody who has never been there. Never throws.
 */
function finaltable_sign_out_everywhere(PDO $db, int $user_id): void {
    if ($user_id <= 0) return;
    try {
        $apps = $db->query("SELECT * FROM sso_apps WHERE enabled = 1 AND api_key IS NOT NULL AND api_key <> ''")->fetchAll();
    } catch (Throwable $e) { return; }
    foreach ($apps as $app) {
        $r = finaltable_request($app, 'POST', '/api/players/' . $user_id . '/sign-out');
        if ($r['ok']) {
            db_log_anon_activity("finaltable sign-out user #$user_id at " . $app['slug'] . ' devices=' . (int)($r['data']['devices'] ?? 0));
        } elseif ($r['status'] !== 404) {
            error_log('[GameNight] finaltable sign-out user #' . $user_id . ' at ' . $app['slug'] . ' failed: ' . $r['error']);
        }
    }
}

/**
 * Call a live game off before its event is deleted. The row cascades with
 * the event; FinalTable is told so the table does not sit there waiting.
 */
function finaltable_cancel_if_live(PDO $db, int $event_id): void {
    try {
        $row = finaltable_game_for_event($db, $event_id);
        if (!$row || !in_array($row['status'], ['registering', 'running'], true)) return;
        $a = $db->prepare('SELECT * FROM sso_apps WHERE id = ?');
        $a->execute([(int)$row['app_id']]);
        $app = $a->fetch();
        if (!$app) return;
        $r = finaltable_request($app, 'POST', '/api/games/' . rawurlencode((string)$row['game_id']) . '/cancel');
        db_log_anon_activity('finaltable cancel event #' . $event_id . ' game=' . $row['game_id'] . ' (event deleted) ok=' . ($r['ok'] ? 1 : 0));
    } catch (Throwable $e) { /* best-effort */ }
}

/**
 * The poker_players row for a GameNight user at this session, made if it is
 * missing. Everyone at the table was on the guest list, so the row normally
 * exists from sync_invitees(); the gaps are an invite deleted after setup
 * (the row was soft-removed - bring it back, they played) and the name join
 * sync_invitees() does missing. A made row gets an invite beside it, or the
 * next Manage Game poll would soft-remove it again.
 */
function finaltable_player_row(PDO $db, int $session_id, int $event_id, int $user_id, ?string $name): ?array {
    $q = $db->prepare('SELECT * FROM poker_players WHERE session_id = ? AND user_id = ? ORDER BY removed ASC, id ASC LIMIT 1');
    $q->execute([$session_id, $user_id]);
    $row = $q->fetch();
    if ($row) {
        if ((int)$row['removed'] === 1) {
            $db->prepare('UPDATE poker_players SET removed = 0 WHERE id = ?')->execute([(int)$row['id']]);
            $row['removed'] = 0;
        }
        return $row;
    }
    $u = $db->prepare('SELECT username FROM users WHERE id = ?');
    $u->execute([$user_id]);
    $uname = (string)($u->fetchColumn() ?: ($name ?: 'Player ' . $user_id));
    pk_ticket_ensure_invite($db, $event_id, $user_id, $uname, null);
    $db->prepare("INSERT INTO poker_players (session_id, user_id, display_name, rsvp, checked_in, bought_in)
                  VALUES (?, ?, ?, 'yes', 1, 1)")->execute([$session_id, $user_id, $uname]);
    $id = (int)$db->lastInsertId();
    pk_log($db, $session_id, null, 'add', $id, $uname, null, 'FinalTable: seated without a roster row here');
    $q->execute([$session_id, $user_id]);
    return $q->fetch() ?: null;
}

/**
 * Apply one verified, not-yet-seen webhook to the game row and the session.
 * $d is the decoded body. Throws on a database failure so the receiver can
 * answer 500 and let FinalTable try again.
 */
function finaltable_apply_event(PDO $db, array $game, string $event, array $d): void {
    $gid = (int)$game['id'];
    $eid = (int)$game['event_id'];
    $now = gmdate('Y-m-d H:i:s');
    $prev = $game['last_status'] ? (json_decode((string)$game['last_status'], true) ?: []) : [];
    $payload = $d;
    unset($payload['event'], $payload['delivery_id'], $payload['sent_at'], $payload['game']);

    $s = $db->prepare('SELECT id, status, game_type FROM poker_sessions WHERE event_id = ?');
    $s->execute([$eid]);
    $sess = $s->fetch() ?: null;
    $sid  = $sess ? (int)$sess['id'] : 0;

    $put = function (array $set) use ($db, $gid, $now): void {
        $cols = []; $vals = [];
        foreach ($set as $k => $v) { $cols[] = "$k = ?"; $vals[] = $v; }
        $cols[] = 'last_event_at = ?'; $vals[] = $now;
        $vals[] = $gid;
        $db->prepare('UPDATE finaltable_games SET ' . implode(', ', $cols) . ' WHERE id = ?')->execute($vals);
    };
    $pick = function (array $keys) use ($d): array {
        $o = [];
        foreach ($keys as $k) if (array_key_exists($k, $d)) $o[$k] = $d[$k];
        return $o;
    };
    // Who this game was sent. A delivery is signed with that game's own
    // secret, so it comes from the server the table was handed to - but a
    // result for somebody who was never on the guest list is not a result,
    // and acting on one would make that account a player here and give it an
    // approved invite to the event. NULL means a game set up before the
    // column existed: do not check, or a table already running stops
    // recording. FinalTable has no way to amend a guest list after creation,
    // so this list and its roster cannot drift apart.
    $roster = null;
    if (isset($game['roster']) && $game['roster'] !== null && $game['roster'] !== '') {
        $decoded = json_decode((string)$game['roster'], true);
        if (is_array($decoded)) $roster = array_map('intval', $decoded);
    }
    $refused = [];
    $playerOf = function (array $p) use ($db, $sid, $eid, $roster, &$refused): ?array {
        $uid = (int)($p['user_id'] ?? 0);
        if ($uid <= 0 || !empty($p['is_bot']) || $sid <= 0) return null;
        if ($roster !== null && !in_array($uid, $roster, true)) {
            // Collected rather than logged one by one: a crafted completion
            // carries a whole standings list, and that is one event, not ten.
            if (!in_array($uid, $refused, true)) $refused[] = $uid;
            return null;
        }
        return finaltable_player_row($db, $sid, $eid, $uid, isset($p['name']) ? (string)$p['name'] : null);
    };

    switch ($event) {
        case 'tournament.started':
            $put(['status' => 'running', 'started_at' => $now, 'last_status' => json_encode($payload)]);
            if ($sid && ($sess['status'] ?? '') === 'setup') {   // never regress a finished session
                $db->prepare("UPDATE poker_sessions SET status = 'active' WHERE id = ?")->execute([$sid]);
                pk_log($db, $sid, null, 'note', null, null, null, 'FinalTable: the clock started');
            }
            break;

        case 'tournament.level':
        case 'tournament.paused':
        case 'tournament.resumed':
            // Pause and resume carry no blinds; keep the last level's underneath.
            $merged = array_merge($prev, $payload);
            if ($event !== 'tournament.level') $merged['paused'] = ($event === 'tournament.paused');
            $put(['last_status' => json_encode($merged)]);
            break;

        case 'tournament.heartbeat':
            $set = ['last_heartbeat_at' => $now, 'last_status' => json_encode(array_merge($prev, $payload))];
            $hs = (string)($d['status'] ?? '');
            if (in_array($hs, ['registering', 'running'], true) && $hs !== (string)$game['status']
                && in_array((string)$game['status'], ['registering', 'running'], true)) {
                $set['status'] = $hs;   // the table knows better than we do
            }
            $put($set);
            break;

        case 'player.eliminated':
            $p = $playerOf((array)($d['player'] ?? []));
            if ($p) {
                $place = (int)($d['place'] ?? 0);
                $db->prepare('UPDATE poker_players SET eliminated = 1, finish_position = ?, checked_in = 1, bought_in = 1,
                                                       table_number = NULL, seat_number = NULL WHERE id = ?')
                   ->execute([$place > 0 ? $place : null, (int)$p['id']]);
                $how = (string)($d['how'] ?? 'busted');
                pk_log($db, $sid, null, 'eliminate', (int)$p['id'], (string)$p['display_name'], null,
                       'FinalTable: out in ' . pk_ordinal($place) . ' (' . $how . (empty($d['final']) ? ', provisional' : '') . ')');
            }
            $put(['last_status' => json_encode(array_merge($prev, $pick(['remaining', 'entrants', 'at'])))]);
            break;

        case 'player.reentered':
            $p = $playerOf((array)($d['player'] ?? []));
            if ($p) {
                $n = (int)($d['reentries'] ?? 1);
                $db->prepare('UPDATE poker_players SET eliminated = 0, finish_position = NULL, rebuys = ? WHERE id = ?')
                   ->execute([$n, (int)$p['id']]);
                pk_log($db, $sid, null, 'rebuy', (int)$p['id'], (string)$p['display_name'], null,
                       'FinalTable: re-entered (' . $n . ')');
            }
            $put(['last_status' => json_encode(array_merge($prev, $pick(['remaining', 'entries', 'at'])))]);
            break;

        case 'tournament.completed':
            foreach ((array)($d['standings'] ?? []) as $st) {
                if (!is_array($st)) continue;
                $p = $playerOf($st);
                if (!$p) continue;
                $place = (int)($st['place'] ?? 0);
                $db->prepare('UPDATE poker_players SET finish_position = ?, bought_in = 1, checked_in = 1, rebuys = ?, addons = ?,
                                                       eliminated = ?, table_number = NULL, seat_number = NULL WHERE id = ?')
                   ->execute([$place > 0 ? $place : null, (int)($st['reentries'] ?? 0), !empty($st['add_on']) ? 1 : 0,
                              $place > 1 ? 1 : 0, (int)$p['id']]);
            }
            // Rows not in the standings never took a seat: bought_in stays 0,
            // so the pool and the league table leave them out.
            if ($sid && ($sess['game_type'] ?? '') === 'tournament') {
                $db->prepare("UPDATE poker_sessions SET status = 'finished' WHERE id = ?")->execute([$sid]);
                $cb = $db->prepare('SELECT created_by FROM events WHERE id = ?');
                $cb->execute([$eid]);
                pk_finish_session($db, $sid, (int)$cb->fetchColumn());
                pk_log($db, $sid, null, 'note', null, null, null,
                       'FinalTable: finished - ' . (string)($d['winner']['name'] ?? '?') . ' won, '
                       . (int)($d['entrants'] ?? 0) . ' players, ' . (int)($d['hands'] ?? 0) . ' hands');
            }
            $put(['status' => 'finished', 'outcome' => 'winner', 'ended_at' => $now,
                  'last_status' => json_encode($pick(['outcome', 'winner', 'standings', 'entrants', 'humans', 'entries',
                                                      'prize_pool', 'buy_in', 'level', 'hands', 'started_at', 'finished_at']))]);
            break;

        case 'tournament.cancelled':
            $reason = (string)($d['reason'] ?? 'ended');
            $put(['status' => 'cancelled', 'outcome' => 'cancelled', 'reason' => $reason, 'ended_at' => $now,
                  'last_status' => json_encode($pick(['outcome', 'reason', 'standings', 'entrants', 'started_at', 'ended_at']))]);
            if ($sid) pk_log($db, $sid, null, 'note', null, null, null, 'FinalTable: game called off (' . $reason . ')');
            break;

        default:
            break;   // an event this side does not know: acknowledged, ignored
    }

    // One line however many names were refused, so a misbehaving app is
    // visible without a crafted completion being able to fill the log.
    if ($refused) {
        db_log_anon_activity(
            'finaltable webhook: ' . $event . ' for game=' . (string)$game['game_id']
            . ' named ' . count($refused) . ' account(s) not on its roster (#'
            . implode(', #', array_slice($refused, 0, 10)) . ')',
            'warning'
        );
    }
}
