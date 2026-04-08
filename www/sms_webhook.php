<?php
/**
 * Inbound SMS webhook — handles RSVP replies from users.
 *
 * Configure your SMS provider to POST to:
 *   https://yourdomain.com/sms_webhook.php
 *
 * Supported reply keywords: YES, NO, MAYBE
 * Looks up the user by phone number and updates their most recent pending invite.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/sms.php';

$provider = get_setting('sms_provider', 'twilio');

// ── Capture raw payload before any parsing (php://input can only be read once) ─
$raw_input  = file_get_contents('php://input');
$raw_post   = !empty($_POST) ? json_encode($_POST, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : '';

// ── Ignore outbound delivery receipt events (e.g. Telnyx message.finalized) ──
if ($raw_input !== '') {
    $event_check = json_decode($raw_input, true);
    $event_type  = $event_check['data']['event_type'] ?? $event_check['event_type'] ?? $event_check['type'] ?? '';
    if ($event_type !== '' && $event_type !== 'message.received') {
        // Delivery receipt or other status event — acknowledge and stop
        http_response_code(200);
        exit;
    }
}

// ── Verify webhook signature (Surge) ─────────────────────────────────────────
if ($provider === 'surge') {
    $secret = get_setting('sms_webhook_secret');
    $sigHeader = $_SERVER['HTTP_SURGE_SIGNATURE'] ?? '';
    if ($secret !== '' && $sigHeader !== '') {
        // Parse t= and v1= from header
        $parts = [];
        foreach (explode(',', $sigHeader) as $part) {
            [$k, $v] = explode('=', $part, 2) + [1 => ''];
            $parts[$k] = $v;
        }
        $timestamp = $parts['t'] ?? '';
        $signature = $parts['v1'] ?? '';
        // Reject if timestamp is older than 5 minutes
        if ($timestamp && abs(time() - (int)$timestamp) > 300) {
            http_response_code(403);
            exit;
        }
        // Verify HMAC
        if ($timestamp && $signature) {
            $expected = hash_hmac('sha256', $timestamp . '.' . $raw_input, $secret);
            if (!hash_equals($expected, $signature)) {
                http_response_code(403);
                exit;
            }
        }
    }
}

// ── Parse inbound message from the provider ──────────────────────────────────
$from = '';
$body = '';
$raw  = '';

switch ($provider) {
    case 'twilio':
        // Twilio sends form-encoded POST
        $from = $_POST['From'] ?? '';
        $body = trim($_POST['Body'] ?? '');
        $raw  = $raw_post;
        break;
    case 'plivo':
        // Plivo sends form-encoded POST
        $from = $_POST['From'] ?? '';
        $body = trim($_POST['Text'] ?? '');
        $raw  = $raw_post;
        break;
    case 'telnyx':
        // Telnyx sends JSON POST
        $json = json_decode($raw_input, true);
        $payload = $json['data']['payload'] ?? [];
        $from = $payload['from']['phone_number'] ?? '';
        $body = trim($payload['text'] ?? '');
        $raw  = $raw_input;
        break;
    case 'vonage':
        // Vonage sends JSON or form-encoded depending on config
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (str_contains($contentType, 'json')) {
            $json = json_decode($raw_input, true);
            $from = $json['msisdn'] ?? '';
            $body = trim($json['text'] ?? '');
            $raw  = $raw_input;
        } else {
            $from = $_POST['msisdn'] ?? '';
            $body = trim($_POST['text'] ?? '');
            $raw  = $raw_post;
        }
        break;
    case 'surge':
        // Surge sends JSON with message.received event
        $json = json_decode($raw_input, true);
        $from = $json['data']['conversation']['contact']['phone_number'] ?? '';
        $body = trim($json['data']['body'] ?? '');
        $raw  = $raw_input;
        break;
}

// Normalize incoming phone number for lookup
$digits = preg_replace('/\D/', '', $from);
if (strlen($digits) === 11 && $digits[0] === '1') $digits = substr($digits, 1);

if (strlen($digits) !== 10 || $body === '') {
    // Respond with 200 to acknowledge receipt (prevent retries)
    http_response_code(200);
    respond_to_provider($provider, 'Sorry, we could not process your message.');
    exit;
}

// Log inbound message with full raw payload
sms_log_inbound($from, $body, $provider, $raw);

// Normalize to XXX-XXX-XXXX for DB lookup
$normalized = substr($digits, 0, 3) . '-' . substr($digits, 3, 3) . '-' . substr($digits, 6, 4);

// ── Look up user by phone ────────────────────────────────────────────────────
$db   = get_db();
$stmt = $db->prepare('SELECT id, username FROM users WHERE phone = ? OR phone = ?');
$stmt->execute([$normalized, $digits]);
$user = $stmt->fetch();

if (!$user) {
    http_response_code(200);
    // Generic response — don't reveal whether phone is registered
    respond_to_provider($provider, 'Thanks for your message.');
    exit;
}

// ── Parse keyword ────────────────────────────────────────────────────────────
$keyword = strtolower(trim($body));

$helpText = "Commands:\nYES/NO/MAYBE - RSVP to your next event\nEVENTS - List upcoming events\nSTATUS - Show your RSVP status\nSTOP - Opt out of SMS\nSTART - Re-enable SMS\nHELP - Show this message";

// ── HELP command ────────────────────────────────────────────────────────────
if (in_array($keyword, ['help', 'h', '?', 'commands'], true)) {
    http_response_code(200);
    respond_to_provider($provider, $helpText);
    exit;
}

// ── EVENTS / STATUS command ─────────────────────────────────────────────────
if (in_array($keyword, ['events', 'list', 'e', 'status', 's'], true)) {
    $evStmt = $db->prepare("
        SELECT e.title, e.start_date, ei.rsvp
        FROM event_invites ei
        JOIN events e ON e.id = ei.event_id
        WHERE LOWER(ei.username) = LOWER(?)
          AND e.start_date >= date('now')
        ORDER BY e.start_date ASC
        LIMIT 10
    ");
    $evStmt->execute([$user['username']]);
    $events = $evStmt->fetchAll();
    if (empty($events)) {
        http_response_code(200);
        respond_to_provider($provider, "You don't have any upcoming event invites.");
        exit;
    }
    $reply = "Your upcoming events:\n";
    foreach ($events as $i => $ev) {
        $n = $i + 1;
        $date = date('M j', strtotime($ev['start_date']));
        $rsvpLabel = $ev['rsvp'] ? ucfirst($ev['rsvp']) : '(none)';
        $reply .= "$n. {$ev['title']} ($date) - RSVP: $rsvpLabel\n";
    }
    http_response_code(200);
    respond_to_provider($provider, trim($reply));
    exit;
}

// ── STOP command ────────────────────────────────────────────────────────────
if (in_array($keyword, ['stop', 'unsubscribe', 'quit'], true)) {
    $db->prepare('UPDATE users SET preferred_contact = ? WHERE id = ?')->execute(['email', $user['id']]);
    db_log_activity($user['id'], 'SMS opt-out via STOP command');
    http_response_code(200);
    respond_to_provider($provider, "You've been unsubscribed from SMS notifications. You'll still receive emails. Text START to re-enable.");
    exit;
}

// ── START command ───────────────────────────────────────────────────────────
if (in_array($keyword, ['start', 'subscribe'], true)) {
    $db->prepare('UPDATE users SET preferred_contact = ? WHERE id = ?')->execute(['sms', $user['id']]);
    db_log_activity($user['id'], 'SMS opt-in via START command');
    http_response_code(200);
    respond_to_provider($provider, 'SMS notifications re-enabled.');
    exit;
}

// ── Parse RSVP keyword ───────────────────────────────────────────────────────
$rsvpMap = [
    'yes'   => 'yes',   'y' => 'yes', 'going' => 'yes', 'attend' => 'yes',
    'no'    => 'no',     'n' => 'no',  'not going' => 'no', 'decline' => 'no',
    'maybe' => 'maybe',  'm' => 'maybe', 'unsure' => 'maybe',
];

$rsvp = $rsvpMap[$keyword] ?? null;
$isNumber = preg_match('/^\d+$/', $keyword);
$isAll = $keyword === 'all';

// ── Parse combined "N RSVP" format (e.g. "1 yes", "2 no", "all maybe") ─────
$directNumber = null;
$directAll = false;
if (!$rsvp && !$isNumber && !$isAll) {
    $parts = preg_split('/\s+/', $keyword, 2);
    if (count($parts) === 2) {
        $partRsvp = $rsvpMap[$parts[1]] ?? null;
        if ($partRsvp) {
            if (preg_match('/^\d+$/', $parts[0])) {
                $directNumber = (int)$parts[0];
                $rsvp = $partRsvp;
            } elseif ($parts[0] === 'all') {
                $directAll = true;
                $rsvp = $partRsvp;
            }
        }
    }
}

// ── Fetch all upcoming invites for this user ────────────────────────────────
$invStmt = $db->prepare("
    SELECT ei.event_id, ei.id as invite_id, ei.rsvp as old_rsvp, e.title, e.start_date
    FROM event_invites ei
    JOIN events e ON e.id = ei.event_id
    WHERE LOWER(ei.username) = LOWER(?)
      AND e.start_date >= date('now')
    ORDER BY e.start_date ASC
    LIMIT 10
");
$invStmt->execute([$user['username']]);
$invites = $invStmt->fetchAll();

// ── Handle direct "N RSVP" format (e.g. "1 yes", "all no") ─────────────────
if ($directNumber !== null || $directAll) {
    if (empty($invites)) {
        http_response_code(200);
        respond_to_provider($provider, 'You don\'t have any upcoming event invites to RSVP for.');
        exit;
    }
    if ($directAll) {
        $count = 0;
        foreach ($invites as $inv) {
            $db->prepare('UPDATE event_invites SET rsvp = ? WHERE id = ?')->execute([$rsvp, $inv['invite_id']]);
            $db->prepare('INSERT INTO activity_log (user_id, action, ip) VALUES (?, ?, ?)')
               ->execute([$user['id'], "SMS RSVP $rsvp for event id: " . $inv['event_id'], $from]);
            $count++;
            notify_creator_of_rsvp($db, $user, $inv, $rsvp, $from);
        }
        http_response_code(200);
        respond_to_provider($provider, "Updated all $count events to: " . ucfirst($rsvp) . ".");
        exit;
    }
    $idx = $directNumber - 1;
    if ($idx >= 0 && $idx < count($invites)) {
        $invite = $invites[$idx];
        $db->prepare('UPDATE event_invites SET rsvp = ? WHERE id = ?')->execute([$rsvp, $invite['invite_id']]);
        $db->prepare('INSERT INTO activity_log (user_id, action, ip) VALUES (?, ?, ?)')
           ->execute([$user['id'], "SMS RSVP $rsvp for event id: " . $invite['event_id'], $from]);
        $label = ucfirst($rsvp);
        http_response_code(200);
        respond_to_provider($provider, "Got it! Your RSVP for \"{$invite['title']}\" on {$invite['start_date']} is now: $label.");
        notify_creator_of_rsvp($db, $user, $invite, $rsvp, $from);
        exit;
    }
    http_response_code(200);
    respond_to_provider($provider, "Invalid selection. Reply with a number 1-" . count($invites) . ".");
    exit;
}

// ── Check for pending RSVP selection (number or ALL reply) ──────────────────
if ($isNumber || $isAll) {
    // Clean up expired pending RSVPs (older than 10 minutes)
    $db->prepare("DELETE FROM sms_pending_rsvp WHERE created_at < datetime('now', '-10 minutes')")->execute();

    $pending = $db->prepare('SELECT rsvp_value FROM sms_pending_rsvp WHERE user_id = ?');
    $pending->execute([$user['id']]);
    $pendingRow = $pending->fetch();

    if ($pendingRow) {
        $rsvp = $pendingRow['rsvp_value'];
        $db->prepare('DELETE FROM sms_pending_rsvp WHERE user_id = ?')->execute([$user['id']]);

        if ($isAll) {
            // Update all upcoming invites
            $count = 0;
            foreach ($invites as $inv) {
                $db->prepare('UPDATE event_invites SET rsvp = ? WHERE id = ?')->execute([$rsvp, $inv['invite_id']]);
                $db->prepare('INSERT INTO activity_log (user_id, action, ip) VALUES (?, ?, ?)')
                   ->execute([$user['id'], "SMS RSVP $rsvp for event id: " . $inv['event_id'], $from]);
                $count++;
                notify_creator_of_rsvp($db, $user, $inv, $rsvp, $from);
            }
            http_response_code(200);
            respond_to_provider($provider, "Updated all $count events to: " . ucfirst($rsvp) . ".");
            exit;
        }

        $idx = (int)$keyword - 1;
        if ($idx >= 0 && $idx < count($invites)) {
            $invite = $invites[$idx];
            $db->prepare('UPDATE event_invites SET rsvp = ? WHERE id = ?')->execute([$rsvp, $invite['invite_id']]);
            $db->prepare('INSERT INTO activity_log (user_id, action, ip) VALUES (?, ?, ?)')
               ->execute([$user['id'], "SMS RSVP $rsvp for event id: " . $invite['event_id'], $from]);
            $label = ucfirst($rsvp);
            http_response_code(200);
            respond_to_provider($provider, "Got it! Your RSVP for \"{$invite['title']}\" on {$invite['start_date']} is now: $label.");
            notify_creator_of_rsvp($db, $user, $invite, $rsvp, $from);
            exit;
        }

        http_response_code(200);
        respond_to_provider($provider, "Invalid selection. Reply with a number 1-" . count($invites) . " or ALL.");
        exit;
    }
}

// ── Not a valid RSVP keyword, number, or ALL ────────────────────────────────
if (!$rsvp) {
    http_response_code(200);
    respond_to_provider($provider, $helpText);
    exit;
}

// ── No upcoming invites ─────────────────────────────────────────────────────
if (empty($invites)) {
    http_response_code(200);
    respond_to_provider($provider, 'You don\'t have any upcoming event invites to RSVP for.');
    exit;
}

// ── Single invite: update immediately ───────────────────────────────────────
if (count($invites) === 1) {
    $invite = $invites[0];
    $db->prepare('UPDATE event_invites SET rsvp = ? WHERE id = ?')->execute([$rsvp, $invite['invite_id']]);
    $db->prepare('INSERT INTO activity_log (user_id, action, ip) VALUES (?, ?, ?)')
       ->execute([$user['id'], "SMS RSVP $rsvp for event id: " . $invite['event_id'], $from]);
    $label = ucfirst($rsvp);
    http_response_code(200);
    respond_to_provider($provider, "Got it! Your RSVP for \"{$invite['title']}\" on {$invite['start_date']} is now: $label.");
    notify_creator_of_rsvp($db, $user, $invite, $rsvp, $from);
    exit;
}

// ── Multiple invites: store intent and send numbered list ───────────────────
$db->prepare('INSERT OR REPLACE INTO sms_pending_rsvp (user_id, rsvp_value, created_at) VALUES (?, ?, datetime(\'now\'))')
   ->execute([$user['id'], $rsvp]);

$label = ucfirst($rsvp);
$reply = "You have " . count($invites) . " upcoming events:\n";
foreach ($invites as $i => $inv) {
    $n = $i + 1;
    $date = date('M j', strtotime($inv['start_date']));
    $reply .= "$n. {$inv['title']} ($date)\n";
}
$reply .= "Reply 1-" . count($invites) . " or ALL to RSVP $label.";

http_response_code(200);
respond_to_provider($provider, $reply);

exit;

// ── Notify event creator of RSVP change ─────────────────────────────────────
function notify_creator_of_rsvp($db, $user, $invite, $rsvp, $from): void {
    $rsvp_changed = ($invite['old_rsvp'] ?? '') !== $rsvp;
    if (!$rsvp_changed) return;
    $label = ucfirst($rsvp);
    $creatorStmt = $db->prepare('SELECT u.username, u.email, u.phone, u.preferred_contact FROM events e JOIN users u ON u.id=e.created_by WHERE e.id=?');
    $creatorStmt->execute([$invite['event_id']]);
    $creator = $creatorStmt->fetch();
    if ($creator && strtolower($creator['username']) !== strtolower($user['username'])) {
        require_once __DIR__ . '/auth_dl.php';
        $smsBody  = $user['username'] . " RSVPed $label to \"{$invite['title']}\" on {$invite['start_date']}";
        $htmlBody = '<p><strong>' . htmlspecialchars($user['username']) . '</strong> RSVPed <strong>' . $label . '</strong> to '
                  . '<em>' . htmlspecialchars($invite['title']) . '</em> on ' . htmlspecialchars($invite['start_date']) . '.</p>';
        send_notification($creator['username'], $creator['email'] ?? '', $creator['phone'] ?? '',
            $creator['preferred_contact'] ?? 'email',
            $user['username'] . " RSVPed $label: " . $invite['title'],
            $smsBody, $htmlBody);
    }
}

// ── Provider-specific response helpers ───────────────────────────────────────
function respond_to_provider(string $provider, string $message): void {
    switch ($provider) {
        case 'twilio':
            // TwiML response
            header('Content-Type: text/xml');
            echo '<?xml version="1.0" encoding="UTF-8"?>';
            echo '<Response><Message>' . htmlspecialchars($message) . '</Message></Response>';
            break;
        case 'plivo':
            // Plivo XML response
            header('Content-Type: text/xml');
            echo '<?xml version="1.0" encoding="UTF-8"?>';
            echo '<Response><Message><Body>' . htmlspecialchars($message) . '</Body></Message></Response>';
            break;
        case 'telnyx':
        case 'vonage':
        case 'surge':
            // These providers use API calls for replies, not webhook responses.
            // Send an outbound SMS instead.
            global $from;
            send_sms($from, $message);
            break;
    }
}
