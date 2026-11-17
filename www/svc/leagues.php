<?php
/**
 * Service layer: leagues. Read side.
 *
 * A league is readable by its members and by site admins. To anyone else it
 * does not exist (404), hidden or not: browsing public leagues is a page on
 * the site, not something a token needs.
 */

require_once __DIR__ . '/common.php';

function gn_league_shape(array $l, ?string $my_role, int $member_count): array {
    $site = rtrim(get_site_url(), '/');
    return [
        'id'          => (int)$l['id'],
        'name'        => (string)$l['name'],
        'description' => (string)($l['description'] ?? ''),
        'my_role'     => $my_role,                    // owner | manager | member | admin
        'member_count' => $member_count,
        'approval_mode' => (string)($l['approval_mode'] ?? 'manual'),
        'hidden'      => (int)($l['is_hidden'] ?? 0) === 1,
        'public_page' => (int)($l['public_page'] ?? 0) === 1 && !empty($l['slug']) ? $site . '/league/' . $l['slug'] : null,
        'created_at'  => gn_iso($l['created_at'] ?? null),
        'url'         => $site . '/league.php?id=' . (int)$l['id'],
    ];
}

/** The leagues the actor belongs to, with their role in each. */
function gn_leagues_list(PDO $db, array $actor): array {
    $st = $db->prepare(
        "SELECT l.*, lm.role AS my_role,
                (SELECT COUNT(*) FROM league_members m WHERE m.league_id = l.id AND m.user_id IS NOT NULL) AS member_count
           FROM league_members lm JOIN leagues l ON l.id = lm.league_id
          WHERE lm.user_id = ? ORDER BY LOWER(l.name)");
    $st->execute([(int)$actor['id']]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $l) $out[] = gn_league_shape($l, (string)$l['my_role'], (int)$l['member_count']);
    return gn_ok(['count' => count($out), 'leagues' => $out]);
}

/** The league row when the actor may read it, else a failure array (test with isset($r['ok'])). */
function gn_league_guard(PDO $db, array $actor, int $league_id, array $need_roles = []): array {
    $st = $db->prepare('SELECT * FROM leagues WHERE id = ?');
    $st->execute([$league_id]);
    $l = $st->fetch(PDO::FETCH_ASSOC);
    $standing = $l ? gn_league_standing($actor, $league_id) : null;
    if (!$l || $standing === null) return gn_err('league_not_found', 'No such league.', 404);
    if ($need_roles && $standing !== 'admin' && !in_array($standing, $need_roles, true)) {
        return gn_err('not_league_manager', 'Only the league\'s ' . implode(' or ', $need_roles) . ' can do that.', 403);
    }
    $l['_standing'] = $standing;
    return $l;
}

function gn_league_get(PDO $db, array $actor, int $league_id): array {
    $l = gn_league_guard($db, $actor, $league_id);
    if (isset($l['ok'])) return $l;
    $c = $db->prepare('SELECT COUNT(*) FROM league_members WHERE league_id = ? AND user_id IS NOT NULL');
    $c->execute([$league_id]);
    $out = gn_league_shape($l, $l['_standing'], (int)$c->fetchColumn());
    $s = $db->prepare('SELECT id, name, start_date, end_date FROM league_seasons WHERE league_id = ? ORDER BY start_date DESC, id DESC');
    $s->execute([$league_id]);
    $out['seasons'] = array_map(fn($r) => ['id' => (int)$r['id'], 'name' => (string)$r['name'],
        'start_date' => (string)$r['start_date'], 'end_date' => (string)$r['end_date']], $s->fetchAll(PDO::FETCH_ASSOC));
    return gn_ok(['league' => $out]);
}

/**
 * The roster. Every member sees names and roles. The owner and managers also
 * see people who were added by contact and have not joined yet, with the
 * contact details the league itself entered for them.
 */
function gn_league_members(PDO $db, array $actor, int $league_id): array {
    $l = gn_league_guard($db, $actor, $league_id);
    if (isset($l['ok'])) return $l;
    $manages = in_array($l['_standing'], ['owner', 'manager', 'admin'], true);
    $st = $db->prepare(
        "SELECT lm.id AS member_id, lm.role, lm.joined_at, lm.user_id, lm.invited_at, lm.known_as,
                lm.contact_name, lm.contact_email, lm.contact_phone, u.username
           FROM league_members lm LEFT JOIN users u ON u.id = lm.user_id
          WHERE lm.league_id = ?
          ORDER BY CASE lm.role WHEN 'owner' THEN 0 WHEN 'manager' THEN 1 ELSE 2 END,
                   CASE WHEN lm.user_id IS NULL THEN 1 ELSE 0 END,
                   LOWER(COALESCE(u.username, lm.contact_name))");
    $st->execute([$league_id]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $pending = $r['user_id'] === null;
        if ($pending && !$manages) continue;
        $name = trim((string)($pending ? $r['contact_name'] : $r['username']));
        if ($name === '') continue;
        $row = [
            'member_id' => (int)$r['member_id'],
            'user_id'   => $pending ? null : (int)$r['user_id'],
            'name'      => $name,
            'known_as'  => ($r['known_as'] ?? '') !== '' ? (string)$r['known_as'] : null,
            'role'      => (string)$r['role'],
            'pending'   => $pending,
            'joined_at' => $pending ? null : gn_iso($r['joined_at']),
        ];
        if ($pending) {
            $row['invited_at'] = gn_iso($r['invited_at']);
            $row['email'] = $r['contact_email'] ?: null;
            $row['phone'] = $r['contact_phone'] ?: null;
        }
        $out[] = $row;
    }
    return gn_ok(['league_id' => $league_id, 'count' => count($out), 'members' => $out]);
}
