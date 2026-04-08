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

// ── Parse RSVP keyword ───────────────────────────────────────────────────────
$keyword = strtolower(trim($body));
$rsvpMap = [
    'yes'   => 'yes',   'y' => 'yes', 'going' => 'yes', 'attend' => 'yes',
    'no'    => 'no',     'n' => 'no',  'not going' => 'no', 'decline' => 'no',
    'maybe' => 'maybe',  'm' => 'maybe', 'unsure' => 'maybe',
];

$rsvp = $rsvpMap[$keyword] ?? null;

if (!$rsvp) {
    http_response_code(200);
    respond_to_provider($provider, 'Reply YES, NO, or MAYBE to update your RSVP.');
    exit;
}

// ── Find the user's most recent event invite ─────────────────────────────────
$invStmt = $db->prepare("
    SELECT ei.event_id, ei.id as invite_id, ei.rsvp as old_rsvp, e.title, e.start_date
    FROM event_invites ei
    JOIN events e ON e.id = ei.event_id
    WHERE LOWER(ei.username) = LOWER(?)
      AND e.start_date >= date('now')
    ORDER BY e.start_date ASC
    LIMIT 1
");
$invStmt->execute([$user['username']]);
$invite = $invStmt->fetch();

if (!$invite) {
    http_response_code(200);
    respond_to_provider($provider, 'You don\'t have any upcoming event invites to RSVP for.');
    exit;
}

$rsvp_changed = ($invite['old_rsvp'] ?? '') !== $rsvp;

// ── Update the RSVP ──────────────────────────────────────────────────────────
$db->prepare('UPDATE event_invites SET rsvp = ? WHERE id = ?')
   ->execute([$rsvp, $invite['invite_id']]);

// Log the activity
$db->prepare('INSERT INTO activity_log (user_id, action, ip) VALUES (?, ?, ?)')
   ->execute([$user['id'], "SMS RSVP $rsvp for event id: " . $invite['event_id'], $from]);

// ── Send confirmation reply ──────────────────────────────────────────────────
$label = ucfirst($rsvp);
$reply = "Got it! Your RSVP for \"{$invite['title']}\" on {$invite['start_date']} is now: $label.";

http_response_code(200);
respond_to_provider($provider, $reply);

// ── Notify event creator only if RSVP changed ──────────────────────────────
if ($rsvp_changed) {
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

exit;

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
