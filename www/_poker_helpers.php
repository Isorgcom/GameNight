<?php
/**
 * Shared poker helper functions used by checkin_dl.php and timer_dl.php.
 */

// Verify event ownership (owner, manager, or admin)
function verify_event_access($db, $event_id, $current, $isAdmin) {
    $stmt = $db->prepare('SELECT created_by FROM events WHERE id = ?');
    $stmt->execute([$event_id]);
    $event = $stmt->fetch();
    if (!$event) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Event not found']);
        exit;
    }
    if (!$isAdmin && (int)$event['created_by'] !== (int)$current['id']) {
        $mgrStmt = $db->prepare("SELECT 1 FROM event_invites WHERE event_id=? AND LOWER(username)=LOWER(?) AND event_role='manager' LIMIT 1");
        $mgrStmt->execute([$event_id, $current['username']]);
        if (!$mgrStmt->fetch()) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Access denied']);
            exit;
        }
    }
}

// Check if user has event access without exiting (returns true/false)
function check_event_access($db, $event_id, $current, $isAdmin) {
    $stmt = $db->prepare('SELECT created_by FROM events WHERE id = ?');
    $stmt->execute([$event_id]);
    $event = $stmt->fetch();
    if (!$event) return false;
    if ($isAdmin) return true;
    if ((int)$event['created_by'] === (int)$current['id']) return true;
    $mgrStmt = $db->prepare("SELECT 1 FROM event_invites WHERE event_id=? AND LOWER(username)=LOWER(?) AND event_role='manager' LIMIT 1");
    $mgrStmt->execute([$event_id, $current['username']]);
    return (bool)$mgrStmt->fetch();
}

// Verify session access via player_id
function get_session_from_player($db, $player_id) {
    $stmt = $db->prepare('SELECT ps.* FROM poker_players pp JOIN poker_sessions ps ON pp.session_id = ps.id WHERE pp.id = ?');
    $stmt->execute([$player_id]);
    return $stmt->fetch();
}

// Calculate pool stats for a session
function calc_pool($db, $session_id) {
    $sess = $db->prepare('SELECT buyin_amount, rebuy_amount, addon_amount, game_type FROM poker_sessions WHERE id = ?');
    $sess->execute([$session_id]);
    $s = $sess->fetch();

    $stats = $db->prepare('SELECT
        COUNT(*) as total_players,
        SUM(checked_in) as checked_in,
        SUM(bought_in) as bought_in,
        SUM(CASE WHEN eliminated = 0 AND bought_in = 1 THEN 1 ELSE 0 END) as still_playing,
        SUM(eliminated) as eliminated,
        SUM(bought_in) as total_buyins,
        SUM(rebuys) as total_rebuys,
        SUM(addons) as total_addons,
        SUM(CASE WHEN cash_out IS NOT NULL THEN 1 ELSE 0 END) as cashed_out,
        SUM(COALESCE(cash_out, 0)) as total_cash_out,
        SUM(COALESCE(cash_in, 0)) as total_cash_in
    FROM poker_players WHERE session_id = ? AND removed = 0');
    $stats->execute([$session_id]);
    $r = $stats->fetch();

    if ($s['game_type'] === 'cash') {
        $pool_total = (int)$r['total_cash_in'];
        $buyin_total = $pool_total;
        $rebuy_total = 0;
        $addon_total = 0;
    } else {
        $buyin_total  = (int)$r['total_buyins'] * (int)$s['buyin_amount'];
        $rebuy_total  = (int)$r['total_rebuys'] * (int)$s['rebuy_amount'];
        $addon_total  = (int)$r['total_addons'] * (int)$s['addon_amount'];
        $pool_total   = $buyin_total + $rebuy_total + $addon_total;
    }

    return [
        'total_players'  => (int)$r['total_players'],
        'checked_in'     => (int)$r['checked_in'],
        'bought_in'      => (int)$r['bought_in'],
        'still_playing'  => (int)$r['still_playing'],
        'eliminated'     => (int)$r['eliminated'],
        'total_buyins'   => (int)$r['total_buyins'],
        'total_rebuys'   => (int)$r['total_rebuys'],
        'total_addons'   => (int)$r['total_addons'],
        'buyin_total'    => $buyin_total,
        'rebuy_total'    => $rebuy_total,
        'addon_total'    => $addon_total,
        'pool_total'     => $pool_total,
        'cashed_out'     => (int)$r['cashed_out'],
        'total_cash_out' => (int)$r['total_cash_out'],
        'total_cash_in'  => (int)$r['total_cash_in'],
    ];
}

// Sync invitees from event_invites into poker_players
function sync_invitees($db, $session_id, $event_id) {
    // Include removed players so they don't get re-added
    $existing = $db->prepare('SELECT LOWER(display_name) as dn FROM poker_players WHERE session_id = ?');
    $existing->execute([$session_id]);
    $existingNames = array_column($existing->fetchAll(), 'dn');

    $invites = $db->prepare("SELECT ei.username, ei.rsvp, u.id as user_id FROM event_invites ei LEFT JOIN users u ON LOWER(ei.username) = LOWER(u.username) WHERE ei.event_id = ? GROUP BY LOWER(ei.username)");
    $invites->execute([$event_id]);

    $pIns = $db->prepare('INSERT INTO poker_players (session_id, user_id, display_name, rsvp) VALUES (?, ?, ?, ?)');
    $pUpd = $db->prepare('UPDATE poker_players SET rsvp = ? WHERE session_id = ? AND LOWER(display_name) = LOWER(?)');

    foreach ($invites->fetchAll() as $inv) {
        if (!in_array(strtolower($inv['username']), $existingNames)) {
            $pIns->execute([$session_id, $inv['user_id'], $inv['username'], $inv['rsvp']]);
        } else {
            $pUpd->execute([$inv['rsvp'], $session_id, $inv['username']]);
        }
    }
}

// Get all players for a session (excludes removed players)
function get_players($db, $session_id) {
    $stmt = $db->prepare("SELECT * FROM poker_players WHERE session_id = ? AND removed = 0 ORDER BY CASE WHEN rsvp='no' THEN 2 WHEN rsvp IS NULL THEN 1 ELSE 0 END, eliminated ASC, display_name ASC");
    $stmt->execute([$session_id]);
    return $stmt->fetchAll();
}

// Get payouts for a session
function get_payouts($db, $session_id) {
    $stmt = $db->prepare('SELECT * FROM poker_payouts WHERE session_id = ? ORDER BY place ASC');
    $stmt->execute([$session_id]);
    return $stmt->fetchAll();
}
