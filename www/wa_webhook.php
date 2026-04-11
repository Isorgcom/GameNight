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

// WAHA webhook format: { event: "message", payload: { from: "1234567890@c.us", body: "YES", ... } }
$event   = $data['event'] ?? '';
$payload = $data['payload'] ?? [];

// Only process incoming messages
if ($event !== 'message' || empty($payload)) {
    exit;
}

// Extract phone (strip @c.us) and message body
$fromRaw = $payload['from'] ?? '';
$from    = preg_replace('/@c\.us$/', '', $fromRaw);  // e.g. "18325551234"
$body    = trim($payload['body'] ?? '');

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

$rsvp = $rsvpMap[$keyword] ?? null;

if (!$rsvp) {
    send_whatsapp($from, 'Reply YES, NO, or MAYBE to update your RSVP.');
    exit;
}

// ── Find the user's most recent event invite ────────────────────────────────
// Pending/denied invites are excluded so WhatsApp RSVP replies can't bypass the host approval gate.
$invStmt = $db->prepare("
    SELECT ei.event_id, ei.id as invite_id, ei.rsvp as old_rsvp, e.title, e.start_date
    FROM event_invites ei
    JOIN events e ON e.id = ei.event_id
    WHERE LOWER(ei.username) = LOWER(?)
      AND e.start_date >= date('now')
      AND ei.approval_status = 'approved'
    ORDER BY e.start_date ASC
    LIMIT 1
");
$invStmt->execute([$user['username']]);
$invite = $invStmt->fetch();

if (!$invite) {
    // Check whether they have a pending invite so we can give a helpful reply.
    $pendStmt = $db->prepare("
        SELECT COUNT(*) FROM event_invites ei
        JOIN events e ON e.id = ei.event_id
        WHERE LOWER(ei.username) = LOWER(?)
          AND e.start_date >= date('now')
          AND ei.approval_status = 'pending'
    ");
    $pendStmt->execute([$user['username']]);
    $msg = ((int)$pendStmt->fetchColumn() > 0)
        ? 'Your invite is waiting for the host to approve. You will be notified when you have been approved.'
        : "You don't have any upcoming event invites to RSVP for.";
    send_whatsapp($from, $msg);
    exit;
}

$rsvp_changed = ($invite['old_rsvp'] ?? '') !== $rsvp;

// ── Update the RSVP ─────────────────────────────────────────────────────────
$db->prepare('UPDATE event_invites SET rsvp = ? WHERE id = ?')
   ->execute([$rsvp, $invite['invite_id']]);

$db->prepare('INSERT INTO activity_log (user_id, action, ip) VALUES (?, ?, ?)')
   ->execute([$user['id'], "WhatsApp RSVP $rsvp for event id: " . $invite['event_id'], $from]);

// ── Send confirmation reply ─────────────────────────────────────────────────
$label = ucfirst($rsvp);
send_whatsapp($from, "Got it! Your RSVP for \"{$invite['title']}\" on {$invite['start_date']} is now: $label.");

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
