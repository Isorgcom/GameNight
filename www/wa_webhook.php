<?php
/**
 * WhatsApp inbound webhook — handles RSVP replies from users via WhatsApp.
 *
 * Receives messages from WAHA (self-hosted WhatsApp HTTP API).
 * Configure WAHA env: WHATSAPP_HOOK_URL=http://gamenight/wa_webhook.php
 *
 * Supported reply keywords: YES, NO, MAYBE
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/sms.php';

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

// ── Handle inbound message (POST from WAHA) ─────────────────────────────────
$raw_input = file_get_contents('php://input');
$data      = json_decode($raw_input, true);

// Always respond 200 quickly
http_response_code(200);

// WAHA webhook format: { event: "message", payload: { from: "xxx@c.us", body: "YES", ... } }
$event   = $data['event'] ?? '';
$payload = $data['payload'] ?? [];

// Only process 'message' events (ignore 'message.any' duplicates and status updates)
if ($event !== 'message' || empty($payload)) {
    exit;
}

// Skip messages from ourselves (outbound echoes)
if (!empty($payload['fromMe'])) exit;

// Skip group messages (only process direct/private messages)
$fromRawCheck = $payload['from'] ?? '';
if (str_contains($fromRawCheck, '@g.us') || str_contains($fromRawCheck, '@broadcast')) exit;

// Dedup: use the WAHA event ID to prevent processing the same message twice.
// WAHA sometimes fires duplicate webhooks for the same message.
$eventId = $data['id'] ?? '';
if ($eventId) {
    $db = get_db();
    // Use a simple lock row — try to insert, if it already exists we're a duplicate
    try {
        $db->prepare("INSERT INTO site_settings (key, value) VALUES (?, datetime('now'))")
           ->execute(['_wa_dedup_' . $eventId]);
    } catch (Exception $e) {
        // Duplicate key = duplicate webhook — skip
        exit;
    }
    // Clean up old dedup keys (older than 1 minute)
    try { $db->exec("DELETE FROM site_settings WHERE key LIKE '_wa_dedup_%' AND value < datetime('now', '-1 minute')"); } catch (Exception $e) {}
}

// Extract phone and message body.
// NOWEB engine uses LID format (xxx@lid) for 'from' — the real phone is in _data.key.remoteJidAlt (xxx@s.whatsapp.net)
$fromRaw = $payload['from'] ?? '';
$body    = trim($payload['body'] ?? '');

if (str_contains($fromRaw, '@lid')) {
    // LID format — extract phone from remoteJidAlt
    $altJid = $payload['_data']['key']['remoteJidAlt'] ?? '';
    $from = preg_replace('/@s\.whatsapp\.net$/', '', $altJid);
} else {
    $from = preg_replace('/@c\.us$/', '', $fromRaw);
}

if ($from === '' || $body === '') exit;

// Normalize phone for DB lookup (strip country code prefix)
$digits = preg_replace('/\D/', '', $from);
if (strlen($digits) === 11 && $digits[0] === '1') $digits = substr($digits, 1);

// Log inbound
sms_log_inbound('+' . $from, $body, 'whatsapp', $raw_input);

if (strlen($digits) !== 10) exit;

$normalized = substr($digits, 0, 3) . '-' . substr($digits, 3, 3) . '-' . substr($digits, 6, 4);

// ── Look up user by phone ────────────────────────────────────────────────────
$db   = get_db();
$stmt = $db->prepare('SELECT id, username FROM users WHERE phone = ? OR phone = ?');
$stmt->execute([$normalized, $digits]);
$user = $stmt->fetch();

if (!$user) {
    // Generic response — don't reveal whether phone is registered
    send_whatsapp($from, "Thanks for your message.");
    exit;
}

// ── Parse RSVP keyword ──────────────────────────────────────────────────────
$keyword = strtolower(trim($body));
$rsvpMap = [
    'yes'   => 'yes',   'y' => 'yes', 'going' => 'yes', 'attend' => 'yes',
    'no'    => 'no',     'n' => 'no',  'not going' => 'no', 'decline' => 'no',
    'maybe' => 'maybe',  'm' => 'maybe', 'unsure' => 'maybe',
];

// Handle HELP command
if (in_array($keyword, ['help', 'h', '?', 'info'], true)) {
    $siteName = get_setting('site_name', 'Game Night');
    send_whatsapp($from, "$siteName Commands:\n\n"
        . "YES / NO / MAYBE — RSVP to your next event\n"
        . "EVENTS — List your upcoming events\n"
        . "STATUS — Show your RSVP status\n"
        . "STOP — Unsubscribe from notifications\n"
        . "START — Re-enable notifications\n"
        . "HELP — Show this message");
    exit;
}

// STOP / START commands
if (in_array($keyword, ['stop', 'unsubscribe', 'quit', 'cancel'], true)) {
    $db->prepare("UPDATE users SET preferred_contact = 'none' WHERE id = ?")->execute([$user['id']]);
    send_whatsapp($from, "You've been unsubscribed from notifications. Reply START to re-enable.");
    exit;
}
if (in_array($keyword, ['start', 'resume', 'subscribe'], true)) {
    $db->prepare("UPDATE users SET preferred_contact = 'whatsapp' WHERE id = ?")->execute([$user['id']]);
    send_whatsapp($from, "Notifications re-enabled via WhatsApp.");
    exit;
}

// EVENTS / STATUS command
if (in_array($keyword, ['events', 'list', 'e', 'status', 's'], true)) {
    $evStmt = $db->prepare("
        SELECT e.title, e.start_date, ei.rsvp
        FROM event_invites ei
        JOIN events e ON e.id = ei.event_id
        WHERE LOWER(ei.username) = LOWER(?)
          AND e.start_date >= ?
        ORDER BY e.start_date ASC
        LIMIT 10
    ");
    $evStmt->execute([$user['username'], $today]);
    $events = $evStmt->fetchAll();
    if (empty($events)) {
        send_whatsapp($from, "You don't have any upcoming event invites.");
        exit;
    }
    $reply = "Your upcoming events:\n";
    foreach ($events as $i => $ev) {
        $n = $i + 1;
        $date = date('M j', strtotime($ev['start_date']));
        $rsvpLabel = $ev['rsvp'] ? ucfirst($ev['rsvp']) : '—';
        $reply .= "$n. {$ev['title']} ($date) — RSVP: $rsvpLabel\n";
    }
    send_whatsapp($from, trim($reply));
    exit;
}

// ── Parse input: RSVP keyword, number, direct format ("1 yes", "all no") ────
$rsvp = $rsvpMap[$keyword] ?? null;
$isNumber = preg_match('/^\d+$/', $keyword);
$isAll    = in_array($keyword, ['all', 'a'], true);

// Direct format: "1 yes", "2 no", "all maybe"
$directNumber = null;
$directAll    = false;
if (preg_match('/^(\d+)\s+(yes|no|maybe|y|n|m)$/i', $keyword, $dm)) {
    $directNumber = (int)$dm[1];
    $rsvp = $rsvpMap[strtolower($dm[2])] ?? null;
} elseif (preg_match('/^(all|a)\s+(yes|no|maybe|y|n|m)$/i', $keyword, $dm)) {
    $directAll = true;
    $rsvp = $rsvpMap[strtolower($dm[2])] ?? null;
}

// ── Fetch all upcoming approved invites ─────────────────────────────────────
// Use configured timezone for "today" so events don't expire early in UTC
$tz = get_setting('timezone', 'UTC');
$today = (new DateTime('now', new DateTimeZone($tz)))->format('Y-m-d');

$invStmt = $db->prepare("
    SELECT ei.event_id, ei.id as invite_id, ei.rsvp as old_rsvp, e.title, e.start_date
    FROM event_invites ei
    JOIN events e ON e.id = ei.event_id
    WHERE LOWER(ei.username) = LOWER(?)
      AND e.start_date >= ?
      AND ei.approval_status = 'approved'
    ORDER BY e.start_date ASC
    LIMIT 10
");
$invStmt->execute([$user['username'], $today]);
$invites = $invStmt->fetchAll();

$pendStmt = $db->prepare("
    SELECT COUNT(*) FROM event_invites ei
    JOIN events e ON e.id = ei.event_id
    WHERE LOWER(ei.username) = LOWER(?)
      AND e.start_date >= ?
      AND ei.approval_status = 'pending'
");
$pendStmt->execute([$user['username'], $today]);
$has_pending = (int)$pendStmt->fetchColumn() > 0;

// ── Handle direct "N RSVP" or "ALL RSVP" format ────────────────────────────
if ($directNumber !== null || $directAll) {
    if (empty($invites)) {
        send_whatsapp($from, $has_pending
            ? 'Your invite is waiting for the host to approve.'
            : "You don't have any upcoming event invites.");
        exit;
    }
    if ($directAll) {
        $count = 0;
        foreach ($invites as $inv) {
            $db->prepare('UPDATE event_invites SET rsvp = ? WHERE id = ?')->execute([$rsvp, $inv['invite_id']]);
            if ($rsvp === 'no') maybe_promote_waitlisted($db, (int)$inv['event_id']);
            $db->prepare('INSERT INTO activity_log (user_id, action, ip) VALUES (?, ?, ?)')
               ->execute([$user['id'], "WhatsApp RSVP $rsvp for event id: " . $inv['event_id'], $from]);
            $count++;
            _wa_notify_creator($db, $user, $inv, $rsvp, $from);
        }
        send_whatsapp($from, "Updated all $count events to: " . ucfirst($rsvp) . ".");
        exit;
    }
    $idx = $directNumber - 1;
    if ($idx >= 0 && $idx < count($invites)) {
        $invite = $invites[$idx];
        $db->prepare('UPDATE event_invites SET rsvp = ? WHERE id = ?')->execute([$rsvp, $invite['invite_id']]);
        if ($rsvp === 'no') maybe_promote_waitlisted($db, (int)$invite['event_id']);
        $db->prepare('INSERT INTO activity_log (user_id, action, ip) VALUES (?, ?, ?)')
           ->execute([$user['id'], "WhatsApp RSVP $rsvp for event id: " . $invite['event_id'], $from]);
        send_whatsapp($from, "Got it! Your RSVP for \"{$invite['title']}\" on {$invite['start_date']} is now: " . ucfirst($rsvp) . ".");
        _wa_notify_creator($db, $user, $invite, $rsvp, $from);
        exit;
    }
    send_whatsapp($from, "Invalid selection. Reply with a number 1-" . count($invites) . ".");
    exit;
}

// ── Check for pending selection (number or ALL reply to a previous list) ────
if ($isNumber || $isAll) {
    $db->prepare("DELETE FROM sms_pending_rsvp WHERE created_at < datetime('now', '-10 minutes')")->execute();
    $pending = $db->prepare('SELECT rsvp_value FROM sms_pending_rsvp WHERE user_id = ?');
    $pending->execute([$user['id']]);
    $pendingRow = $pending->fetch();

    if ($pendingRow) {
        $rsvp = $pendingRow['rsvp_value'];
        $db->prepare('DELETE FROM sms_pending_rsvp WHERE user_id = ?')->execute([$user['id']]);

        if ($isAll) {
            $count = 0;
            foreach ($invites as $inv) {
                $db->prepare('UPDATE event_invites SET rsvp = ? WHERE id = ?')->execute([$rsvp, $inv['invite_id']]);
                if ($rsvp === 'no') maybe_promote_waitlisted($db, (int)$inv['event_id']);
                $db->prepare('INSERT INTO activity_log (user_id, action, ip) VALUES (?, ?, ?)')
                   ->execute([$user['id'], "WhatsApp RSVP $rsvp for event id: " . $inv['event_id'], $from]);
                $count++;
                _wa_notify_creator($db, $user, $inv, $rsvp, $from);
            }
            send_whatsapp($from, "Updated all $count events to: " . ucfirst($rsvp) . ".");
            exit;
        }

        $idx = (int)$keyword - 1;
        if ($idx >= 0 && $idx < count($invites)) {
            $invite = $invites[$idx];
            $db->prepare('UPDATE event_invites SET rsvp = ? WHERE id = ?')->execute([$rsvp, $invite['invite_id']]);
            if ($rsvp === 'no') maybe_promote_waitlisted($db, (int)$invite['event_id']);
            $db->prepare('INSERT INTO activity_log (user_id, action, ip) VALUES (?, ?, ?)')
               ->execute([$user['id'], "WhatsApp RSVP $rsvp for event id: " . $invite['event_id'], $from]);
            send_whatsapp($from, "Got it! Your RSVP for \"{$invite['title']}\" on {$invite['start_date']} is now: " . ucfirst($rsvp) . ".");
            _wa_notify_creator($db, $user, $invite, $rsvp, $from);
            exit;
        }

        send_whatsapp($from, "Invalid selection. Reply with a number 1-" . count($invites) . " or ALL.");
        exit;
    }
}

// ── Not a valid keyword ─────────────────────────────────────────────────────
if (!$rsvp) {
    send_whatsapp($from, "Reply YES, NO, or MAYBE to RSVP.\nReply HELP for commands.");
    exit;
}

// ── No upcoming invites ─────────────────────────────────────────────────────
if (empty($invites)) {
    send_whatsapp($from, $has_pending
        ? 'Your invite is waiting for the host to approve.'
        : "You don't have any upcoming event invites.");
    exit;
}

// ── Single invite: update immediately ───────────────────────────────────────
if (count($invites) === 1) {
    $invite = $invites[0];
    $db->prepare('UPDATE event_invites SET rsvp = ? WHERE id = ?')->execute([$rsvp, $invite['invite_id']]);
    if ($rsvp === 'no') maybe_promote_waitlisted($db, (int)$invite['event_id']);
    $db->prepare('INSERT INTO activity_log (user_id, action, ip) VALUES (?, ?, ?)')
       ->execute([$user['id'], "WhatsApp RSVP $rsvp for event id: " . $invite['event_id'], $from]);
    $label = ucfirst($rsvp);
    send_whatsapp($from, "Got it! Your RSVP for \"{$invite['title']}\" on {$invite['start_date']} is now: $label.");
    _wa_notify_creator($db, $user, $invite, $rsvp, $from);
    exit;
}

// ── Multiple invites: store intent and send numbered list ───────────────────
$db->prepare("INSERT OR REPLACE INTO sms_pending_rsvp (user_id, rsvp_value, created_at) VALUES (?, ?, datetime('now'))")
   ->execute([$user['id'], $rsvp]);

$label = ucfirst($rsvp);
$reply = "You have " . count($invites) . " upcoming events:\n";
foreach ($invites as $i => $inv) {
    $n = $i + 1;
    $date = date('M j', strtotime($inv['start_date']));
    $reply .= "$n. {$inv['title']} ($date)\n";
}
$reply .= "\nReply 1-" . count($invites) . " or ALL to RSVP $label.";
send_whatsapp($from, $reply);
exit;

// ── Notify event creator of RSVP change ─────────────────────────────────────
function _wa_notify_creator($db, $user, $invite, $rsvp, $from): void {
    $rsvp_changed = ($invite['old_rsvp'] ?? '') !== $rsvp;
    if (!$rsvp_changed) return;
    $label = ucfirst($rsvp);
    $creatorStmt = $db->prepare('SELECT u.username, u.email, u.phone, u.preferred_contact FROM events e JOIN users u ON u.id=e.created_by WHERE e.id=?');
    $creatorStmt->execute([$invite['event_id']]);
    $creator = $creatorStmt->fetch();
    if ($creator && strtolower($creator['username']) !== strtolower($user['username'])) {
        if (function_exists('send_notification')) {
            $smsBody  = $user['username'] . " RSVPed $label to \"{$invite['title']}\" on {$invite['start_date']}";
            $htmlBody = '<p><strong>' . htmlspecialchars($user['username']) . '</strong> RSVPed <strong>' . $label . '</strong> to '
                      . '<em>' . htmlspecialchars($invite['title']) . '</em> on ' . htmlspecialchars($invite['start_date']) . '.</p>';
            send_notification($creator['username'], $creator['email'] ?? '', $creator['phone'] ?? '',
                $creator['preferred_contact'] ?? 'email',
                $user['username'] . " RSVPed $label: " . $invite['title'],
                $smsBody, $htmlBody);
        }
    }
}
