<?php
/**
 * The tools the MCP endpoint (mcp.php) offers. Each is a thin, task-shaped
 * wrapper over a service function in www/svc/: the same functions /api/v2
 * calls, with the same permission checks inside them. Nothing here decides
 * who may do what.
 *
 * A tool is: title, description (written for the model that will choose it),
 * the token permission it needs, its arguments, hints, and `run`, which
 * returns a service result array. The game tools answer from inside the
 * check-in console (gn_game_act()) and so call $finish instead of returning.
 *
 * People are named the way a host says them. A tool that acts on a guest or
 * a player takes "guest" or "player" as a name OR an id; a name that matches
 * several people is refused with the candidates, never guessed.
 */

require_once __DIR__ . '/svc/common.php';
require_once __DIR__ . '/svc/events.php';
require_once __DIR__ . '/svc/leagues.php';
require_once __DIR__ . '/svc/stats.php';
require_once __DIR__ . '/svc/game.php';
require_once __DIR__ . '/svc/messages.php';
require_once __DIR__ . '/svc/contacts.php';

// ── Argument schema shorthands ───────────────────────────────────────────────
function mcp_s(string $d, array $more = []): array { return ['type' => 'string', 'description' => $d] + $more; }
function mcp_i(string $d, array $more = []): array { return ['type' => 'integer', 'description' => $d] + $more; }
function mcp_b(string $d): array { return ['type' => 'boolean', 'description' => $d]; }
function mcp_e(array $values, string $d): array { return ['type' => 'string', 'enum' => $values, 'description' => $d]; }
function mcp_ref(string $d): array { return ['type' => ['string', 'integer'], 'description' => $d]; }

const MCP_EVENT_ID = 'The event\'s id, from list_events.';
const MCP_WHEN     = 'A moment with its UTC offset, like 2026-12-12T19:00:00-06:00, or a bare date like 2026-12-12 for an all-day event. whoami gives the site\'s timezone.';

/** Pick the one row a reference names, or a failure listing the candidates. $rows: [id => name, ...] (+ optional aliases [id => alias]). */
function mcp_pick($ref, array $rows, array $aliases, string $what, string $nf_code): array {
    if (is_int($ref) || (is_string($ref) && ctype_digit($ref))) {
        return isset($rows[(int)$ref]) ? ['id' => (int)$ref] : gn_err($nf_code, "No $what with id $ref here.", 404);
    }
    $q = mb_strtolower(trim((string)$ref));
    if ($q === '') return gn_err('bad_field', "Say which $what, by name or id.", 400);
    $exact = []; $partial = [];
    foreach ($rows as $id => $name) {
        $names = [mb_strtolower($name)];
        if (!empty($aliases[$id])) $names[] = mb_strtolower($aliases[$id]);
        foreach ($names as $n) {
            if ($n === $q) { $exact[$id] = $name; break; }
            if (str_contains($n, $q)) $partial[$id] = $name;
        }
    }
    $hits = $exact ?: $partial;
    if (count($hits) === 1) return ['id' => (int)array_key_first($hits)];
    if (!$hits) return gn_err($nf_code, "No $what called \"$ref\" here. There are: " . (implode(', ', array_slice(array_values($rows), 0, 40)) ?: 'none') . '.', 404);
    $list = implode(', ', array_map(fn($id, $n) => "$n (id $id)", array_keys($hits), $hits));
    return gn_err('ambiguous_name', "\"$ref\" could be: $list. Say which, by full name or id.", 409);
}

/** A player of an event's game by name or id. Checks the caller hosts the event first. */
function mcp_player(PDO $db, array $actor, int $event_id, $ref): array {
    $ev = gn_event_guard($db, $actor, $event_id, true);
    if (isset($ev['ok'])) return $ev;
    $s = gn_game_session($db, $event_id);
    if (!$s) return gn_err('game_not_set_up', 'This event has no game set up yet.', 404);
    sync_invitees($db, (int)$s['id'], $event_id);
    $rows = []; $alias = [];
    foreach (get_players($db, (int)$s['id']) as $p) { $rows[(int)$p['id']] = (string)$p['display_name']; $alias[(int)$p['id']] = (string)($p['known_as'] ?? ''); }
    return mcp_pick($ref, $rows, $alias, 'player', 'player_not_found');
}

/** A guest on an event's list by name or invite id. Checks the caller hosts the event first. */
function mcp_guest(PDO $db, array $actor, int $event_id, $ref): array {
    $ev = gn_event_guard($db, $actor, $event_id, true);
    if (isset($ev['ok'])) return $ev;
    $st = $db->prepare('SELECT id, username FROM event_invites WHERE event_id = ? AND occurrence_date IS NULL');
    $st->execute([$event_id]);
    $rows = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $rows[(int)$r['id']] = (string)$r['username'];
    return mcp_pick($ref, $rows, [], 'guest', 'invite_not_found');
}

/** Someone on a league's roster (member or pending contact) by name, email or member id. Checks the caller runs the league first. */
function mcp_league_member(PDO $db, array $actor, int $league_id, $ref): array {
    $l = gn_league_guard($db, $actor, $league_id, ['owner', 'manager']);
    if (isset($l['ok'])) return $l;
    $st = $db->prepare('SELECT lm.id, lm.known_as, lm.contact_name, lm.contact_email, u.username FROM league_members lm LEFT JOIN users u ON u.id = lm.user_id WHERE lm.league_id = ?');
    $st->execute([$league_id]);
    $rows = []; $alias = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $name = trim((string)($r['username'] ?? '')) ?: trim((string)($r['contact_name'] ?? ''));
        if ($name === '') continue;
        $rows[(int)$r['id']] = $name;
        $alias[(int)$r['id']] = (string)(($r['known_as'] ?? '') !== '' ? $r['known_as'] : ($r['contact_email'] ?? ''));
    }
    return mcp_pick($ref, $rows, $alias, 'league member', 'member_not_found');
}

/** One of the actor's own contacts by name or id. */
function mcp_contact(PDO $db, array $actor, $ref): array {
    $st = $db->prepare('SELECT id, contact_name, contact_email FROM user_contacts WHERE owner_user_id = ?');
    $st->execute([(int)$actor['id']]);
    $rows = []; $alias = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $rows[(int)$r['id']] = (string)$r['contact_name']; $alias[(int)$r['id']] = (string)($r['contact_email'] ?? ''); }
    return mcp_pick($ref, $rows, $alias, 'contact', 'contact_not_found');
}

/** Keep only the arguments a service body may carry, dropping the tool's own addressing arguments. */
function mcp_body(array $a, array $drop): array { return array_diff_key($a, array_flip($drop)); }

function gn_mcp_tools(): array {
    $guest_items = ['type' => 'array', 'description' => 'People to invite. Each is {"name": "Dave", "email": "...", "phone": "..."} for someone typed in (email and phone optional), or {"user_id": 12} for a league member or contact. Add "role": "manager" to make one a co-host.',
        'items' => ['type' => 'object', 'properties' => ['name' => mcp_s('Their name.'), 'email' => mcp_s('Email, optional.'), 'phone' => mcp_s('Phone, optional.'),
            'user_id' => mcp_i('An account id, instead of a name.'), 'role' => mcp_e(['invitee', 'manager'], 'manager makes them a co-host.')]]];
    $event_fields = [
        'title' => mcp_s('The event\'s name.'), 'description' => mcp_s('Details shown to guests.'), 'location' => mcp_s('Address.'), 'venue_name' => mcp_s('Name of the place.'),
        'start_at' => mcp_s('When it starts. ' . MCP_WHEN), 'end_at' => mcp_s('When it ends, same forms. Optional.'),
        'league_id' => mcp_i('Put it on this league\'s calendar (you must own or manage the league).'),
        'visibility' => mcp_e(['invitees_only', 'league', 'public'], 'Who can see it. public is for site admins.'),
        'is_poker' => mcp_b('True for a poker game: sets up a game with buy-ins, tables and a clock.'),
        'poker' => ['type' => 'object', 'description' => 'For a poker game.', 'properties' => ['game_type' => mcp_e(['tournament', 'cash'], 'Kind of game.'),
            'buyin_cents' => mcp_i('Buy-in in cents: 4000 is $40.'), 'tables' => mcp_i('Number of tables.'), 'seats_per_table' => mcp_i('Seats at each table.')]],
        'requires_approval' => mcp_b('Guests who sign themselves up wait for the host\'s approval.'), 'hide_guest_list' => mcp_b('Guests cannot see who else is coming.'),
        'waitlist_enabled' => mcp_b('People past capacity go on a waitlist.'), 'max_guests' => mcp_i('Capacity, for an event that is not a poker game.'),
        'rsvp_deadline_hours' => mcp_i('Hours before the start by which guests must answer.'), 'reminders_enabled' => mcp_b('Send reminders before the event.'),
        'reminder_minutes' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'When to remind, in minutes before the start, e.g. [1440, 120].'],
    ];
    $range = ['range' => mcp_e(['all', '7', '30', '90', '365', 'ytd', 'custom'], 'Which games to count. Default all. custom uses from and to.'),
              'from' => mcp_s('YYYY-MM-DD, with range custom.'), 'to' => mcp_s('YYYY-MM-DD, with range custom.'), 'season_id' => mcp_i('Count only a season\'s games (from get_league). Wins over range.')];
    $eid = ['event_id' => mcp_i(MCP_EVENT_ID)];
    $ro = ['readOnlyHint' => true];
    $wr = ['readOnlyHint' => false, 'destructiveHint' => false];
    $act = fn(string $a) => fn($db, $ac, $x, $f) => gn_game_act($db, $ac, (int)$x['event_id'], $a, mcp_body($x, ['event_id']), $f);

    return [
        // ── Reading ──────────────────────────────────────────────────────────
        'whoami' => ['title' => 'Who am I', 'scope' => 'read', 'hints' => $ro, 'args' => [], 'required' => [],
            'description' => 'Who this connection acts as on Game Night, what it is allowed to do, the site\'s timezone, and the leagues they belong to with their role in each. Call this first.',
            'run' => function ($db, $a, $x) {
                $u = $db->prepare('SELECT id, username, role, timezone FROM users WHERE id = ?'); $u->execute([$a['id']]); $u = $u->fetch(PDO::FETCH_ASSOC);
                return gn_ok(['user' => ['id' => (int)$u['id'], 'name' => (string)$u['username'], 'site_admin' => $u['role'] === 'admin', 'timezone' => (string)($u['timezone'] ?: get_setting('timezone', 'UTC'))],
                    'permissions' => $a['scopes'], 'site' => ['name' => (string)get_setting('site_name', 'Game Night'), 'url' => rtrim(get_site_url(), '/'), 'timezone' => (string)get_setting('timezone', 'UTC'),
                        'today' => (new DateTime('now', new DateTimeZone(get_setting('timezone', 'UTC'))))->format('Y-m-d')],
                    'leagues' => gn_leagues_list($db, $a)['leagues']]);
            }],
        'list_events' => ['title' => 'List events', 'scope' => 'read', 'hints' => $ro, 'required' => [],
            'description' => 'Events the user hosts or is invited to, in a date window (default: today to 90 days ahead; at most 366 days). Each has its id, time, RSVP totals and whether the user hosts it. Use from and to in the past to find earlier games.',
            'args' => ['from' => mcp_s('First day, YYYY-MM-DD.'), 'to' => mcp_s('Last day, YYYY-MM-DD.'), 'role' => mcp_e(['any', 'host', 'guest'], 'host: only events they run. guest: only ones they are invited to.'),
                       'league_id' => mcp_i('Only this league\'s events.'), 'include_cancelled' => mcp_b('Include events that were called off.')],
            'run' => fn($db, $a, $x) => gn_events_list($db, $a, $x)],
        'get_event' => ['title' => 'Get an event', 'scope' => 'read', 'hints' => $ro, 'args' => $eid, 'required' => ['event_id'],
            'description' => 'One event in full: details, RSVP totals, capacity, and a summary of its poker game if it has one.',
            'run' => fn($db, $a, $x) => gn_event_get($db, $a, (int)$x['event_id'])],
        'list_guests' => ['title' => 'List guests', 'scope' => 'read', 'hints' => $ro, 'args' => $eid, 'required' => ['event_id'],
            'description' => 'The guest list of an event: each guest\'s name, answer (yes, no, maybe, or null for not answered), and for the host their status (approved, pending, waitlisted, denied). Use it to see who has not answered.',
            'run' => fn($db, $a, $x) => gn_event_invites($db, $a, (int)$x['event_id'])],
        'list_messages' => ['title' => 'List host messages', 'scope' => 'read', 'hints' => $ro, 'args' => $eid, 'required' => ['event_id'],
            'description' => 'Messages the host has sent to an event\'s guests, newest first, and how many may be sent per day.',
            'run' => fn($db, $a, $x) => gn_host_messages_list($db, $a, (int)$x['event_id'])],
        'list_contacts' => ['title' => 'List contacts', 'scope' => 'read', 'hints' => $ro, 'required' => [],
            'description' => 'The user\'s own contacts: the address book they invite people from. Each has a name, email and/or phone, notes, how a typed-in invitation reaches them (invite_via), and whether that person has an account on the site (has_account, with a user_id that add_guests accepts). Use search to find someone.',
            'args' => ['search' => mcp_s('Optional: part of a name, email or phone.')],
            'run' => fn($db, $a, $x) => gn_contacts_list($db, $a, $x)],
        'get_league' => ['title' => 'Get a league', 'scope' => 'read', 'hints' => $ro, 'args' => ['league_id' => mcp_i('The league\'s id, from whoami.'), 'include_members' => mcp_b('Also list the members.')], 'required' => ['league_id'],
            'description' => 'A league: its details and seasons, and optionally its roster. Owners and managers also see people who were added but have not joined yet.',
            'run' => function ($db, $a, $x) {
                $r = gn_league_get($db, $a, (int)$x['league_id']);
                if (!empty($r['ok']) && !empty($x['include_members'])) $r['members'] = gn_league_members($db, $a, (int)$x['league_id'])['members'] ?? [];
                return $r;
            }],
        'get_standings' => ['title' => 'League standings', 'scope' => 'read', 'hints' => $ro, 'required' => ['league_id'],
            'description' => 'A league\'s leaderboard from its finished games: games played, wins, points, money in and out. Players with fewer than the minimum number of games are listed after those who qualify. Money is in cents.',
            'args' => ['league_id' => mcp_i('The league\'s id.'), 'rank' => mcp_e(['score', 'net', 'wins', 'points'], 'What to rank by. Default score (how well they place).')] + $range,
            'run' => function ($db, $a, $x) {
                $r = gn_league_standings($db, $a, (int)$x['league_id'], $x);
                if (empty($r['ok'])) return $r;
                $pos = 0; $r['standings'] = array_map(function ($row) use (&$pos) { return gn_standings_shape($row, ++$pos); }, $r['rows']); unset($r['rows']);
                return $r;
            }],
        'get_player_history' => ['title' => 'A player\'s games', 'scope' => 'read', 'hints' => $ro, 'required' => ['league_id', 'player_key'],
            'description' => 'One player\'s finished games in a league, newest first, with their totals. player_key comes from get_standings.',
            'args' => ['league_id' => mcp_i('The league\'s id.'), 'player_key' => mcp_s('From get_standings: a user id like "301", or "g_dave smith" for a guest without an account.')] + $range,
            'run' => fn($db, $a, $x) => gn_player_history($db, $a, (int)$x['league_id'], (string)$x['player_key'], $x)],
        'list_league_posts' => ['title' => 'League posts', 'scope' => 'read', 'hints' => $ro, 'args' => ['league_id' => mcp_i('The league\'s id.'), 'limit' => mcp_i('How many, at most 50. Default 20.')], 'required' => ['league_id'],
            'description' => 'A league\'s posts, pinned first then newest.',
            'run' => fn($db, $a, $x) => gn_league_posts($db, $a, (int)$x['league_id'], $x)],
        'list_join_requests' => ['title' => 'Requests to join', 'scope' => 'read', 'hints' => $ro, 'args' => ['league_id' => mcp_i('The league\'s id.')], 'required' => ['league_id'],
            'description' => 'People waiting for an answer on a request to join a league the user owns or manages.',
            'run' => fn($db, $a, $x) => gn_join_requests_list($db, $a, (int)$x['league_id'])],
        'get_game' => ['title' => 'Get the game', 'scope' => 'read', 'hints' => $ro, 'args' => $eid, 'required' => ['event_id'],
            'description' => 'Host only. The poker game of an event as it stands right now: its setup, every player (bought in or not, rebuys, table and seat, finishing place), the prize pool, what each place pays, the blind schedule and the clock. Money is in cents. Look here before changing a game.',
            'run' => fn($db, $a, $x) => gn_game_state($db, $a, (int)$x['event_id'])],
        'get_results' => ['title' => 'Game results', 'scope' => 'read', 'hints' => $ro, 'args' => $eid, 'required' => ['event_id'],
            'description' => 'How a poker game came out: finishing order, what each player put in and took home, points. For the host and for members of the event\'s league. While the game is still running it is the standings so far ("final": false).',
            'run' => fn($db, $a, $x) => gn_game_results($db, $a, (int)$x['event_id'])],
        'get_game_log' => ['title' => 'Game log', 'scope' => 'read', 'hints' => $ro, 'required' => ['event_id'],
            'description' => 'Host only. What has happened in a game, newest first: buy-ins, rebuys, knockouts, voids, who did each. Give a player to see only their money entries, each with an id that void_ledger_entry takes.',
            'args' => $eid + ['player' => mcp_ref('Optional: a player\'s name or id, for their money entries only.'), 'limit' => mcp_i('How many entries, at most 500. Default 100.')],
            'run' => function ($db, $a, $x) {
                if (!isset($x['player'])) return gn_game_log($db, $a, (int)$x['event_id'], (int)($x['limit'] ?? 100));
                $p = mcp_player($db, $a, (int)$x['event_id'], $x['player']);
                return isset($p['ok']) ? $p : gn_player_ledger($db, $a, (int)$x['event_id'], $p['id']);
            }],

        // ── Events and guests (permission: events) ───────────────────────────
        'create_event' => ['title' => 'Create an event', 'scope' => 'events', 'hints' => $wr, 'required' => ['title', 'start_at'],
            'description' => 'Create an event, optionally a poker game, with a guest list. Nobody is told: invitations go out only with send_invitations. To repeat an earlier event with the same guests, use duplicate_event instead.',
            'args' => $event_fields + ['guests' => $guest_items],
            'run' => fn($db, $a, $x) => gn_event_create($db, $a, $x)],
        'update_event' => ['title' => 'Change an event', 'scope' => 'events', 'hints' => $wr + ['idempotentHint' => true], 'required' => ['event_id'],
            'description' => 'Host only. Change an event\'s details. Only what you give changes. Moving the start moves the end with it. Guests are not told about a change; if the time moved, consider message_guests.',
            'args' => $eid + $event_fields,
            'run' => fn($db, $a, $x) => gn_event_update($db, $a, (int)$x['event_id'], mcp_body($x, ['event_id']))],
        'cancel_event' => ['title' => 'Cancel an event', 'scope' => 'events', 'hints' => ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true], 'args' => $eid, 'required' => ['event_id'],
            'description' => 'Host only. Call an event off. EVERY guest is told it is cancelled, by email or text. The event is kept, so it can still be duplicated for a new date. Confirm with the user before calling this.',
            'run' => fn($db, $a, $x) => gn_event_cancel($db, $a, (int)$x['event_id'])],
        'delete_event' => ['title' => 'Delete an event', 'scope' => 'events', 'hints' => ['readOnlyHint' => false, 'destructiveHint' => true], 'required' => ['event_id'],
            'description' => 'Host only. Delete an event for good, with its guest list, messages and game results. Cannot be undone. Call it first without confirm to learn what would be lost, show the user, and only then call it with confirm true. To call an event off but keep the record, use cancel_event.',
            'args' => $eid + ['confirm' => mcp_b('True only after the user has agreed to the deletion.')],
            'run' => fn($db, $a, $x) => gn_event_delete($db, $a, (int)$x['event_id'], ['confirm' => ($x['confirm'] ?? false) === true ? true : null])],
        'duplicate_event' => ['title' => 'Copy an event to a new date', 'scope' => 'events', 'hints' => $wr, 'required' => ['event_id', 'start_at'],
            'description' => 'Host only. Make a new event from an existing one: same details, game settings and guest list, with nobody\'s answer carried over. The usual way to set up "the same game next month". Nobody is told until send_invitations.',
            'args' => $eid + ['start_at' => mcp_s('When the copy starts. ' . MCP_WHEN), 'end_at' => mcp_s('When it ends. Optional.'), 'title' => mcp_s('A new name. Optional.')],
            'run' => fn($db, $a, $x) => gn_event_duplicate($db, $a, (int)$x['event_id'], mcp_body($x, ['event_id']))],
        'add_guests' => ['title' => 'Add guests', 'scope' => 'events', 'hints' => $wr + ['idempotentHint' => true], 'args' => $eid + ['guests' => $guest_items], 'required' => ['event_id', 'guests'],
            'description' => 'Host only. Add people to an event\'s guest list. Anyone already on it is skipped. With a waitlist, people past capacity land on it. Nobody is told until send_invitations.',
            'run' => fn($db, $a, $x) => gn_invites_add($db, $a, (int)$x['event_id'], ['guests' => $x['guests'] ?? null])],
        'update_guest' => ['title' => 'Change a guest', 'scope' => 'events', 'hints' => $wr + ['idempotentHint' => true], 'required' => ['event_id', 'guest'],
            'description' => 'Host only. Record a guest\'s answer for them, or make them a co-host. A "no" can free a seat for someone on the waitlist.',
            'args' => $eid + ['guest' => mcp_ref('The guest\'s name, or their invite id.'), 'rsvp' => ['type' => ['string', 'null'], 'enum' => ['yes', 'no', 'maybe', null], 'description' => 'Their answer; null clears it.'],
                       'role' => mcp_e(['invitee', 'manager'], 'manager makes them a co-host (they need an account).')],
            'run' => function ($db, $a, $x) {
                $g = mcp_guest($db, $a, (int)$x['event_id'], $x['guest'] ?? '');
                return isset($g['ok']) ? $g : gn_invite_update($db, $a, (int)$x['event_id'], $g['id'], mcp_body($x, ['event_id', 'guest']));
            }],
        'remove_guest' => ['title' => 'Remove a guest', 'scope' => 'events', 'hints' => ['readOnlyHint' => false, 'destructiveHint' => true], 'required' => ['event_id', 'guest'],
            'description' => 'Host only. Take a guest off the list. On an event still ahead, they are told they were removed.',
            'args' => $eid + ['guest' => mcp_ref('The guest\'s name, or their invite id.')],
            'run' => function ($db, $a, $x) {
                $g = mcp_guest($db, $a, (int)$x['event_id'], $x['guest'] ?? '');
                return isset($g['ok']) ? $g : gn_invite_remove($db, $a, (int)$x['event_id'], $g['id']);
            }],
        'decide_guest' => ['title' => 'Approve or turn down a guest', 'scope' => 'events', 'hints' => $wr + ['idempotentHint' => true], 'required' => ['event_id', 'guest', 'decision'],
            'description' => 'Host only. Answer a guest who asked to come (status pending) or is on the waitlist. Approving tells them and, for a poker game, seats them.',
            'args' => $eid + ['guest' => mcp_ref('The guest\'s name, or their invite id.'), 'decision' => mcp_e(['approve', 'deny'], 'approve or deny.')],
            'run' => function ($db, $a, $x) {
                if (!in_array($x['decision'] ?? '', ['approve', 'deny'], true)) return gn_err('bad_field', 'decision must be approve or deny.', 400);
                $g = mcp_guest($db, $a, (int)$x['event_id'], $x['guest'] ?? '');
                return isset($g['ok']) ? $g : gn_invite_decide($db, $a, (int)$x['event_id'], $g['id'], $x['decision'] === 'approve');
            }],
        'add_contacts' => ['title' => 'Add contacts', 'scope' => 'events', 'hints' => $wr + ['idempotentHint' => true], 'required' => ['contacts'],
            'description' => 'Add people to the user\'s contacts. Each needs a name and an email or a phone (or both). Give them all in one call (up to 200): someone already there with the same email is skipped, one that is not valid is reported, and the rest are added. Nobody is told; a contact is only an address book entry.',
            'args' => ['contacts' => ['type' => 'array', 'description' => 'The people to add.', 'items' => ['type' => 'object', 'properties' => ['name' => mcp_s('Their name.'), 'email' => mcp_s('Email.'), 'phone' => mcp_s('Phone.'), 'notes' => mcp_s('A note to yourself about them.'), 'invite_via' => mcp_e(['email', 'sms'], 'How invitations should reach them. Default email.')], 'required' => ['name']]]],
            'run' => fn($db, $a, $x) => gn_contacts_add($db, $a, ['contacts' => $x['contacts'] ?? null])],
        'update_contact' => ['title' => 'Change a contact', 'scope' => 'events', 'hints' => $wr + ['idempotentHint' => true], 'required' => ['contact'],
            'description' => 'Change one of the user\'s contacts: their name, email, phone, notes, or how invitations reach them. Only what you give changes; give an empty string to clear the email or the phone (one of the two must remain).',
            'args' => ['contact' => mcp_ref('The contact\'s name or email, or their id from list_contacts.'), 'name' => mcp_s('New name.'), 'email' => mcp_s('New email, or "" to clear.'), 'phone' => mcp_s('New phone, or "" to clear.'),
                       'notes' => mcp_s('New notes, or "" to clear.'), 'invite_via' => mcp_e(['email', 'sms'], 'How invitations should reach them.')],
            'run' => function ($db, $a, $x) {
                $c = mcp_contact($db, $a, $x['contact'] ?? '');
                return isset($c['ok']) ? $c : gn_contact_update($db, $a, $c['id'], mcp_body($x, ['contact']));
            }],
        'delete_contact' => ['title' => 'Delete a contact', 'scope' => 'events', 'hints' => ['readOnlyHint' => false, 'destructiveHint' => true], 'required' => ['contact'],
            'description' => 'Remove one person from the user\'s contacts. Events they are already invited to are not changed. Confirm with the user which contact before calling this.',
            'args' => ['contact' => mcp_ref('The contact\'s name or email, or their id from list_contacts.')],
            'run' => function ($db, $a, $x) {
                $c = mcp_contact($db, $a, $x['contact'] ?? '');
                return isset($c['ok']) ? $c : gn_contact_delete($db, $a, $c['id']);
            }],
        'remove_league_member' => ['title' => 'Remove someone from a league', 'scope' => 'events', 'hints' => ['readOnlyHint' => false, 'destructiveHint' => true], 'required' => ['league_id', 'member'],
            'description' => 'League owner or manager. Remove someone from a league\'s roster: a member, or a person who was added by contact and has not joined yet (shown as pending in get_league). A member with an account is told they were removed; a pending contact is not told. The owner cannot be removed. This does not touch the user\'s own contacts (that is delete_contact). Confirm with the user who and which league before calling this.',
            'args' => ['league_id' => mcp_i('The league\'s id.'), 'member' => mcp_ref('Their name or email, or their member_id from get_league.')],
            'run' => function ($db, $a, $x) {
                $m = mcp_league_member($db, $a, (int)$x['league_id'], $x['member'] ?? '');
                return isset($m['ok']) ? $m : gn_league_member_remove($db, $a, (int)$x['league_id'], $m['id']);
            }],
        'add_season' => ['title' => 'Add a league season', 'scope' => 'events', 'hints' => $wr, 'required' => ['league_id', 'name', 'start_date', 'end_date'],
            'description' => 'League owner or manager. Add a season: a named range of dates that standings can be limited to.',
            'args' => ['league_id' => mcp_i('The league\'s id.'), 'name' => mcp_s('E.g. "Spring 2027".'), 'start_date' => mcp_s('YYYY-MM-DD.'), 'end_date' => mcp_s('YYYY-MM-DD.')],
            'run' => fn($db, $a, $x) => gn_season_add($db, $a, (int)$x['league_id'], mcp_body($x, ['league_id']))],
        'delete_season' => ['title' => 'Remove a league season', 'scope' => 'events', 'hints' => ['readOnlyHint' => false, 'destructiveHint' => true], 'required' => ['league_id', 'season_id'],
            'description' => 'League owner or manager. Remove a season. Games and results are not touched; only the named range goes.',
            'args' => ['league_id' => mcp_i('The league\'s id.'), 'season_id' => mcp_i('From get_league.')],
            'run' => fn($db, $a, $x) => gn_season_delete($db, $a, (int)$x['league_id'], (int)$x['season_id'])],
        'create_league_post' => ['title' => 'Post to a league', 'scope' => 'events', 'hints' => $wr, 'required' => ['league_id', 'title', 'body'],
            'description' => 'League owner or manager. Put a post in the league\'s feed. Nobody is notified; members see it when they look.',
            'args' => ['league_id' => mcp_i('The league\'s id.'), 'title' => mcp_s('Headline.'), 'body' => mcp_s('The post, as plain text. Line breaks are kept.')],
            'run' => function ($db, $a, $x) {
                $b = is_string($x['body'] ?? null) ? nl2br(htmlspecialchars($x['body'], ENT_QUOTES | ENT_SUBSTITUTE)) : '';
                return gn_league_post_create($db, $a, (int)$x['league_id'], ['title' => $x['title'] ?? '', 'body' => $b]);
            }],
        'decide_join_request' => ['title' => 'Answer a request to join', 'scope' => 'events', 'hints' => $wr, 'required' => ['league_id', 'request_id', 'decision'],
            'description' => 'League owner or manager. Let someone into the league or decline. They are told either way.',
            'args' => ['league_id' => mcp_i('The league\'s id.'), 'request_id' => mcp_i('From list_join_requests.'), 'decision' => mcp_e(['approve', 'deny'], 'approve or deny.')],
            'run' => fn($db, $a, $x) => in_array($x['decision'] ?? '', ['approve', 'deny'], true)
                ? gn_join_request_decide($db, $a, (int)$x['request_id'], $x['decision'] === 'approve', (int)$x['league_id'])
                : gn_err('bad_field', 'decision must be approve or deny.', 400)],

        // ── Reaching people (permission: messages). Each of these sends real email or texts. ──
        'send_invitations' => ['title' => 'Send invitations', 'scope' => 'messages', 'hints' => $wr + ['idempotentHint' => true, 'openWorldHint' => true], 'args' => $eid, 'required' => ['event_id'],
            'description' => 'Host only. SENDS email or texts: invites every approved guest who has not had an invitation yet. Safe to repeat; people already invited are not sent another. Say how many it will reach and get the user\'s go-ahead first.',
            'run' => fn($db, $a, $x) => gn_invitations_send($db, $a, (int)$x['event_id'])],
        'resend_invitation' => ['title' => 'Resend one invitation', 'scope' => 'messages', 'hints' => $wr + ['openWorldHint' => true], 'required' => ['event_id', 'guest'],
            'description' => 'Host only. SENDS one guest their invitation again.',
            'args' => $eid + ['guest' => mcp_ref('The guest\'s name, or their invite id.')],
            'run' => function ($db, $a, $x) {
                $g = mcp_guest($db, $a, (int)$x['event_id'], $x['guest'] ?? '');
                if (isset($g['ok'])) return $g;
                $row = gn_invite_row($db, (int)$x['event_id'], $g['id']);
                return gn_invite_resend($db, $a, (int)$x['event_id'], (string)$row['username']);
            }],
        'nudge_guests' => ['title' => 'Nudge guests who have not answered', 'scope' => 'messages', 'hints' => $wr + ['idempotentHint' => true, 'openWorldHint' => true], 'args' => $eid, 'required' => ['event_id'],
            'description' => 'Host only. SENDS a reminder to invited guests who have not answered. Each guest gets at most one a day, so repeating it the same day reaches nobody new.',
            'run' => fn($db, $a, $x) => gn_guests_nudge($db, $a, (int)$x['event_id'])],
        'message_guests' => ['title' => 'Message the guests', 'scope' => 'messages', 'hints' => $wr + ['openWorldHint' => true], 'required' => ['event_id', 'subject', 'body'],
            'description' => 'Host only. SENDS a message from the host to an event\'s guests, by email or text, at once. An event may send only a few a day (list_messages shows the limit), so write one complete message. Show the user the exact subject and text and get their go-ahead before sending.',
            'args' => $eid + ['subject' => mcp_s('Subject line.'), 'body' => mcp_s('The message, as plain text. Line breaks are kept.'),
                       'audience' => mcp_e(['yes', 'yes_maybe', 'all'], 'Who gets it: those who said yes (default), yes or maybe, or everyone on the list.')],
            'run' => function ($db, $a, $x) {
                if (!is_string($x['subject'] ?? null) || !is_string($x['body'] ?? null)) return gn_err('bad_field', 'subject and body are required and must be text.', 400);
                return gn_host_message_send($db, $a, (int)$x['event_id'], ['subject' => $x['subject'], 'body' => nl2br(htmlspecialchars($x['body'], ENT_QUOTES | ENT_SUBSTITUTE)), 'audience' => $x['audience'] ?? 'yes']);
            }],
        'add_league_member' => ['title' => 'Invite someone to a league', 'scope' => 'messages', 'hints' => $wr + ['idempotentHint' => true, 'openWorldHint' => true], 'required' => ['league_id', 'name'],
            'description' => 'League owner or manager. SENDS an invitation to join the league to someone, by email or phone. Someone with an account joins when they accept; anyone else gets a link to sign up.',
            'args' => ['league_id' => mcp_i('The league\'s id.'), 'name' => mcp_s('Their name.'), 'email' => mcp_s('Their email.'), 'phone' => mcp_s('Their phone, if no email.')],
            'run' => fn($db, $a, $x) => gn_league_member_add($db, $a, (int)$x['league_id'], mcp_body($x, ['league_id']))],

        // ── Running a game (permission: game) ────────────────────────────────
        'configure_game' => ['title' => 'Set up the game', 'scope' => 'game', 'hints' => $wr + ['idempotentHint' => true], 'required' => ['event_id'],
            'description' => 'Host only. Change a poker game\'s setup. Only what you give changes. All money is in cents (2000 is $20). Changing tables or seats may reseat players.',
            'args' => $eid + ['game_type' => mcp_e(['tournament', 'cash'], 'Kind of game.'), 'buyin_cents' => mcp_i('Buy-in.'), 'rebuy_cents' => mcp_i('Cost of a rebuy.'), 'addon_cents' => mcp_i('Cost of an add-on.'),
                'starting_chips' => mcp_i('Chips for a buy-in or rebuy.'), 'addon_chips' => mcp_i('Chips for an add-on.'), 'tables' => mcp_i('Number of tables.'), 'seats_per_table' => mcp_i('Seats per table.'),
                'auto_assign_tables' => mcp_b('Seat players as they buy in.'), 'rebuy_allowed' => mcp_b('Allow rebuys.'), 'addon_allowed' => mcp_b('Allow add-ons.'), 'max_rebuys' => mcp_i('Rebuys per player; 0 for no limit.'),
                'rebuys_included' => mcp_i('Rebuys that come with the buy-in.'), 'addons_included' => mcp_i('Add-ons that come with the buy-in.'), 'addons_share_rebuys' => mcp_b('Unused included rebuys may be taken as add-ons.'),
                'rebuy_cutoff_level' => mcp_i('No rebuys once the clock is past this level; 0 for none.'), 'payout_round_cents' => ['type' => 'integer', 'enum' => [0, 500, 1000, 2000], 'description' => 'Round prizes to the nearest $5, $10 or $20; 0 for exact.'],
                'bounty_cents' => mcp_i('Bounty on each player.'), 'bounty_points' => mcp_i('Points for a knockout.'), 'bounty_optional' => mcp_b('Bounty is bought on the side instead of taken from the buy-in.')],
            'run' => $act('setup')],
        'set_blinds' => ['title' => 'Set the blind schedule', 'scope' => 'game', 'hints' => $wr + ['idempotentHint' => true], 'required' => ['event_id', 'levels'],
            'description' => 'Host only. Replace a tournament\'s whole blind schedule. Give every level in order; a break is {"is_break": true, "minutes": 10}.',
            'args' => $eid + ['levels' => ['type' => 'array', 'description' => 'The levels, first to last.', 'items' => ['type' => 'object', 'properties' => [
                'small_blind' => ['type' => 'number', 'description' => 'Small blind.'], 'big_blind' => ['type' => 'number', 'description' => 'Big blind.'], 'ante' => ['type' => 'number', 'description' => 'Ante, if any.'],
                'minutes' => mcp_i('How long the level lasts.'), 'is_break' => mcp_b('True for a break instead of a level.')], 'required' => ['minutes']]]],
            'run' => fn($db, $a, $x) => gn_game_blinds_set($db, $a, (int)$x['event_id'], ['levels' => $x['levels'] ?? null])],
        'set_payouts' => ['title' => 'Set the payout table', 'scope' => 'game', 'hints' => $wr + ['idempotentHint' => true], 'required' => ['event_id', 'places'],
            'description' => 'Host only. Replace a tournament\'s whole payout table: what share of the pool each place gets, and optionally league points. Percentages may total at most 100.',
            'args' => $eid + ['places' => ['type' => 'array', 'description' => 'Every paid place.', 'items' => ['type' => 'object', 'properties' => [
                'place' => mcp_i('1 for first, 2 for second...'), 'percentage' => ['type' => 'number', 'description' => 'Share of the prize pool.'], 'points' => mcp_i('League points for this place.'),
                'label' => mcp_s('A prize that is not money, e.g. "Trophy".')], 'required' => ['place']]]],
            'run' => $act('payouts')],
        'game_status' => ['title' => 'Start, finish or reopen the game', 'scope' => 'game', 'hints' => ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true], 'required' => ['event_id', 'to'],
            'description' => 'Host only. start begins play. finish records the final results and payouts, issues entry tickets, banks jackpot money and tells the winners: call it without confirm first, show the user what it says, then with confirm true. A game also finishes by itself when the last opponent is knocked out. reopen puts a finished game back in play.',
            'args' => $eid + ['to' => mcp_e(['start', 'finish', 'reopen'], 'What to do.'), 'confirm' => mcp_b('For finish: true only after the user has agreed.')],
            'run' => function ($db, $a, $x, $f) {
                if (!in_array($x['to'] ?? '', ['start', 'finish', 'reopen'], true)) return gn_err('bad_field', 'to must be start, finish or reopen.', 400);
                return gn_game_act($db, $a, (int)$x['event_id'], $x['to'], ($x['confirm'] ?? false) === true ? ['confirm' => true] : [], $f);
            }],
        'add_walk_in' => ['title' => 'Add a walk-in', 'scope' => 'game', 'hints' => $wr, 'args' => $eid + ['name' => mcp_s('The player\'s name.')], 'required' => ['event_id', 'name'],
            'description' => 'Host only. Add someone who turned up without being on the list. They join the guest list as coming; take their buy-in with player_action.',
            'run' => $act('walk_in')],
        'player_action' => ['title' => 'Do something for a player', 'scope' => 'game', 'hints' => $wr, 'required' => ['event_id', 'player', 'action'],
            'description' => 'Host only. One action for one player in a poker game. buy_in takes their buy-in; rebuy and add_on take one each (a rebuy brings a knocked-out player back); eliminate knocks them out and gives them the next place from the bottom, and when one player is left the game finishes with that player as the winner; reinstate undoes a knockout; the undo_ actions take back one buy-in, rebuy or add-on. cash_in and cash_out are for a cash game. seat puts them at a table; unseat takes them off. remove takes someone who has not bought in off the roster and the guest list. One call does one thing: for two rebuys, call twice.',
            'args' => $eid + ['player' => mcp_ref('The player\'s name, or their id from get_game.'),
                'action' => mcp_e(['buy_in', 'undo_buy_in', 'rebuy', 'undo_rebuy', 'add_on', 'undo_add_on', 'eliminate', 'reinstate', 'cash_in', 'cash_out', 'seat', 'unseat', 'notes', 'remove'], 'What to do.'),
                'knocked_out_by' => mcp_ref('With eliminate: the name or id of the player who knocked them out (this is what pays a bounty).'),
                'finish_position' => mcp_i('With eliminate: their place, if it should not be the next from the bottom.'),
                'after_cutoff' => mcp_b('With rebuy: true to record a rebuy after the game\'s rebuy cutoff, once the user has agreed.'),
                'amount_cents' => ['type' => ['integer', 'null'], 'description' => 'With cash_in: how much to add. With cash_out: what they left with, or null to clear it.'],
                'table' => mcp_i('With seat: the table number.'), 'notes' => mcp_s('With notes: the note to keep on this player.')],
            'run' => function ($db, $a, $x, $f) {
                $eid = (int)$x['event_id']; $do = (string)($x['action'] ?? '');
                $map = ['buy_in' => 'buy_in', 'undo_buy_in' => 'undo_buy_in', 'rebuy' => 'rebuy', 'undo_rebuy' => 'undo_rebuy', 'add_on' => 'add_on', 'undo_add_on' => 'undo_add_on',
                        'eliminate' => 'eliminate', 'reinstate' => 'reinstate', 'cash_in' => 'cash_in', 'cash_out' => 'cash_out', 'seat' => 'move', 'unseat' => 'unseat', 'notes' => 'notes', 'remove' => 'remove'];
                if (!isset($map[$do])) return gn_err('bad_field', 'action must be one of: ' . implode(', ', array_keys($map)) . '.', 400);
                $p = mcp_player($db, $a, $eid, $x['player'] ?? '');
                if (isset($p['ok'])) return $p;
                $args = ['player_id' => $p['id']];
                if ($do === 'eliminate') {
                    if (isset($x['knocked_out_by'])) { $by = mcp_player($db, $a, $eid, $x['knocked_out_by']); if (isset($by['ok'])) return $by; $args['by_player_id'] = $by['id']; }
                    if (isset($x['finish_position'])) $args['finish_position'] = $x['finish_position'];
                }
                if ($do === 'rebuy' && ($x['after_cutoff'] ?? false) === true) $args['after_cutoff'] = true;
                if ($do === 'cash_in')  $args['amount_cents'] = $x['amount_cents'] ?? null;
                if ($do === 'cash_out') { if (!array_key_exists('amount_cents', $x)) return gn_err('bad_field', 'cash_out needs amount_cents (or null to clear).', 400); $args['amount_cents'] = $x['amount_cents']; }
                if ($do === 'seat')  $args['table'] = $x['table'] ?? null;
                if ($do === 'notes') $args['notes'] = $x['notes'] ?? null;
                return gn_game_act($db, $a, $eid, $map[$do], $args, $f);
            }],
        'add_on_for_players' => ['title' => 'Add-on for several players', 'scope' => 'game', 'hints' => $wr, 'required' => ['event_id', 'players'],
            'description' => 'Host only. Give an add-on to several players at once, typically at the add-on break.',
            'args' => $eid + ['players' => ['type' => 'array', 'items' => ['type' => ['string', 'integer']], 'description' => 'The players, by name or id.']],
            'run' => function ($db, $a, $x, $f) {
                if (!is_array($x['players'] ?? null) || !$x['players']) return gn_err('bad_field', 'players must be a list of names or ids.', 400);
                $ids = [];
                foreach ($x['players'] as $ref) { $p = mcp_player($db, $a, (int)$x['event_id'], $ref); if (isset($p['ok'])) return $p; $ids[] = $p['id']; }
                return gn_game_act($db, $a, (int)$x['event_id'], 'bulk_add_on', ['player_ids' => array_values(array_unique($ids))], $f);
            }],
        'manage_tables' => ['title' => 'Rebalance or break up tables', 'scope' => 'game', 'hints' => $wr, 'required' => ['event_id', 'action'],
            'description' => 'Host only. rebalance evens out the tables. break_up closes one table and seats its players at the others. The answer lists who moved where, to read out.',
            'args' => $eid + ['action' => mcp_e(['rebalance', 'break_up'], 'What to do.'), 'table' => mcp_i('With break_up: the table to close.')],
            'run' => function ($db, $a, $x, $f) {
                if (($x['action'] ?? '') === 'rebalance') return gn_game_act($db, $a, (int)$x['event_id'], 'rebalance', [], $f);
                if (($x['action'] ?? '') === 'break_up')  return gn_game_act($db, $a, (int)$x['event_id'], 'break_up_table', ['table' => $x['table'] ?? null], $f);
                return gn_err('bad_field', 'action must be rebalance or break_up.', 400);
            }],
        'void_ledger_entry' => ['title' => 'Void a money entry', 'scope' => 'game', 'hints' => ['readOnlyHint' => false, 'destructiveHint' => true], 'required' => ['event_id', 'entry_id'],
            'description' => 'Host only. Strike out one wrong money entry (a buy-in, rebuy, add-on, cash in or cash out). It is reversed and stays in the log, marked voided. Find the entry id with get_game_log for that player.',
            'args' => $eid + ['entry_id' => mcp_i('The entry\'s id, from get_game_log with a player.')],
            'run' => $act('void_entry')],
        'clock' => ['title' => 'Work the tournament clock', 'scope' => 'game', 'hints' => $wr, 'required' => ['event_id', 'command'],
            'description' => 'Host only. Control a tournament\'s clock; every screen showing the timer follows. play and pause start and stop it; next and previous move a level; add_minute and subtract_minute adjust the time left; reset_level restarts the current level; undo takes back the last command; reset puts it back to level 1 and needs confirm true.',
            'args' => $eid + ['command' => mcp_e(['play', 'pause', 'next', 'previous', 'add_minute', 'subtract_minute', 'reset_level', 'undo', 'reset'], 'What to do.'), 'confirm' => mcp_b('For reset: true only after the user has agreed.')],
            'run' => function ($db, $a, $x, $f) {
                $args = ['command' => $x['command'] ?? ''];
                if (($x['confirm'] ?? false) === true) $args['confirm'] = true;
                return gn_game_act($db, $a, (int)$x['event_id'], 'clock', $args, $f);
            }],
    ];
}
