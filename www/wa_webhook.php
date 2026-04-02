<?php
/**
 * WhatsApp inbound webhook — handles RSVP replies from users via WhatsApp.
 *
 * Configure in Meta Developer Portal:
 *   App Dashboard > WhatsApp > Configuration > Webhook URL:
 *   https://yourdomain.com/wa_webhook.php
 *
 * Supported reply keywords: YES, NO, MAYBE
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/sms.php';

// ── Webhook verification (GET request from Meta) ────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $mode      = $_GET['hub_mode'] ?? '';
    $token     = $_GET['hub_verify_token'] ?? '';
    $challenge = $_GET['hub_challenge'] ?? '';
    $expected  = get_setting('wa_verify_token', '');

    if ($mode === 'subscribe' && $token === $expected && $expected !== '') {
        http_response_code(200);
        echo $challenge;
    } else {
        http_response_code(403);
        echo 'Verification failed';
    }
    exit;
}

// ── Handle inbound message (POST from Meta) ─────────────────────────────────
$raw_input = file_get_contents('php://input');
$data      = json_decode($raw_input, true);

// Always respond 200 to Meta webhooks quickly
http_response_code(200);

// Navigate Meta's webhook structure
$entry   = $data['entry'][0] ?? [];
$changes = $entry['changes'][0] ?? [];
$value   = $changes['value'] ?? [];

// Only process incoming messages (not status updates)
if (($changes['field'] ?? '') !== 'messages' || empty($value['messages'])) {
    exit;
}

$message = $value['messages'][0];
$from    = $message['from'] ?? '';       // phone in format: 1XXXXXXXXXX (no +)
$body    = trim($message['text']['body'] ?? '');

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
    send_whatsapp($from, "Sorry, we don't recognize this phone number. Make sure your phone is set in your profile.");
    exit;
}

// ── Parse RSVP keyword ──────────────────────────────────────────────────────
$keyword = strtolower(trim($body));
$rsvpMap = [
    'yes'   => 'yes',   'y' => 'yes', 'going' => 'yes', 'attend' => 'yes',
    'no'    => 'no',     'n' => 'no',  'not going' => 'no', 'decline' => 'no',
    'maybe' => 'maybe',  'm' => 'maybe', 'unsure' => 'maybe',
];

$rsvp = $rsvpMap[$keyword] ?? null;

if (!$rsvp) {
    send_whatsapp($from, 'Reply YES, NO, or MAYBE to update your RSVP.');
    exit;
}

// ── Find the user's most recent event invite ────────────────────────────────
$invStmt = $db->prepare("
    SELECT ei.event_id, ei.id as invite_id, e.title, e.start_date
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
    send_whatsapp($from, "You don't have any upcoming event invites to RSVP for.");
    exit;
}

// ── Update the RSVP ─────────────────────────────────────────────────────────
$db->prepare('UPDATE event_invites SET rsvp = ? WHERE id = ?')
   ->execute([$rsvp, $invite['invite_id']]);

$db->prepare('INSERT INTO activity_log (user_id, action, ip) VALUES (?, ?, ?)')
   ->execute([$user['id'], "WhatsApp RSVP $rsvp for event id: " . $invite['event_id'], $from]);

// ── Send confirmation reply ─────────────────────────────────────────────────
$label = ucfirst($rsvp);
send_whatsapp($from, "Got it! Your RSVP for \"{$invite['title']}\" on {$invite['start_date']} is now: $label.");

// ── Notify event creator ────────────────────────────────────────────────────
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
