<?php
require_once __DIR__ . '/db.php';

/**
 * Provider configuration: fields needed for each SMS provider.
 * Used by both send_sms() and the admin settings UI.
 */
function get_sms_providers(): array {
    return [
        'twilio' => [
            'label'  => 'Twilio',
            'fields' => [
                'sms_sid'   => ['label' => 'Account SID',  'type' => 'text',     'placeholder' => 'ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx'],
                'sms_token' => ['label' => 'Auth Token',    'type' => 'password', 'placeholder' => 'your_auth_token'],
                'sms_from'  => ['label' => 'From Number',   'type' => 'text',     'placeholder' => '+12015550123'],
            ],
            'help' => [
                ['Console', 'https://console.twilio.com'],
                ['Account SID', 'Found on Console dashboard, starts with <code>AC</code>'],
                ['Auth Token', 'Found on Console dashboard (click to reveal)'],
                ['From Number', 'Buy a number under Phone Numbers &rsaquo; Manage'],
                ['Trial limits', 'Trial accounts can only send to verified numbers'],
            ],
        ],
        'plivo' => [
            'label'  => 'Plivo',
            'fields' => [
                'sms_sid'   => ['label' => 'Auth ID',    'type' => 'text',     'placeholder' => 'your_auth_id'],
                'sms_token' => ['label' => 'Auth Token',  'type' => 'password', 'placeholder' => 'your_auth_token'],
                'sms_from'  => ['label' => 'From Number', 'type' => 'text',     'placeholder' => '+12015550123'],
            ],
            'help' => [
                ['Console', 'https://console.plivo.com'],
                ['Auth ID / Token', 'Found on the Plivo Console dashboard'],
                ['From Number', 'Buy a number under Phone Numbers'],
                ['Pricing', 'Outbound ~$0.005/msg, inbound free'],
            ],
        ],
        'telnyx' => [
            'label'  => 'Telnyx',
            'fields' => [
                'sms_token' => ['label' => 'API Key',     'type' => 'password', 'placeholder' => 'KEY0...'],
                'sms_from'  => ['label' => 'From Number',  'type' => 'text',     'placeholder' => '+12015550123'],
            ],
            'help' => [
                ['Portal', 'https://portal.telnyx.com'],
                ['API Key', 'Create under Auth &rsaquo; API Keys'],
                ['From Number', 'Buy a number under Numbers'],
                ['Pricing', 'Outbound ~$0.004/msg, inbound ~$0.002/msg'],
            ],
        ],
        'vonage' => [
            'label'  => 'Vonage (Nexmo)',
            'fields' => [
                'sms_sid'   => ['label' => 'API Key',     'type' => 'text',     'placeholder' => 'your_api_key'],
                'sms_token' => ['label' => 'API Secret',   'type' => 'password', 'placeholder' => 'your_api_secret'],
                'sms_from'  => ['label' => 'From Number',  'type' => 'text',     'placeholder' => '+12015550123'],
            ],
            'help' => [
                ['Dashboard', 'https://dashboard.nexmo.com'],
                ['API Key / Secret', 'Found on the Vonage API Dashboard'],
                ['From Number', 'Buy a number under Numbers'],
                ['Pricing', 'Outbound ~$0.0068/msg, inbound ~$0.005/msg'],
            ],
        ],
    ];
}

/**
 * Normalize a phone number to E.164 (+1XXXXXXXXXX) format.
 */
function sms_normalize_phone(string $to): ?string {
    $digits = preg_replace('/\D/', '', $to);
    if (strlen($digits) === 10) $digits = '1' . $digits;
    if (strlen($digits) !== 11) return null;
    return '+' . $digits;
}

/**
 * Send an SMS via the configured provider.
 * Returns null on success, error string on failure.
 */
function send_sms(string $to, string $body): ?string {
    $e164 = sms_normalize_phone($to);
    if (!$e164) return 'Invalid phone number.';

    $provider = get_setting('sms_provider', 'twilio');
    $sid      = get_setting('sms_sid');
    $token    = get_setting('sms_token');
    $from     = get_setting('sms_from');

    // Backwards compat: fall back to old twilio_* keys if sms_* are empty
    if (!$sid)   $sid   = get_setting('twilio_sid');
    if (!$token) $token = get_setting('twilio_token');
    if (!$from)  $from  = get_setting('twilio_from');

    if (!$token || !$from) return 'SMS not configured.';

    switch ($provider) {
        case 'twilio':
            $err = _sms_twilio($sid, $token, $from, $e164, $body); break;
        case 'plivo':
            $err = _sms_plivo($sid, $token, $from, $e164, $body); break;
        case 'telnyx':
            $err = _sms_telnyx($token, $from, $e164, $body); break;
        case 'vonage':
            $err = _sms_vonage($sid, $token, $from, $e164, $body); break;
        default:
            $err = "Unknown SMS provider: $provider";
    }

    sms_log('outbound', $e164, $body, $provider, $err === null ? 'sent' : 'failed', $err);
    return $err;
}

/**
 * Log an inbound SMS (called from sms_webhook.php).
 */
function sms_log_inbound(string $phone, string $body, string $provider): void {
    sms_log('inbound', $phone, $body, $provider, 'received', null);
}

function sms_log(string $direction, string $phone, string $body, ?string $provider, string $status, ?string $error): void {
    try {
        get_db()->prepare('INSERT INTO sms_log (direction, phone, body, provider, status, error) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$direction, $phone, $body, $provider, $status, $error]);
    } catch (Exception $e) {
        // Don't let logging failures break SMS sending
    }
}

/* ── Provider implementations ─────────────────────────────────────────────── */

function _sms_twilio(string $sid, string $token, string $from, string $to, string $body): ?string {
    if (!$sid) return 'Twilio Account SID is required.';
    $url = 'https://api.twilio.com/2010-04-01/Accounts/' . $sid . '/Messages.json';
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query(['From' => $from, 'To' => $to, 'Body' => $body]),
        CURLOPT_USERPWD        => $sid . ':' . $token,
    ]);
    $response = curl_exec($ch);
    $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code === 201) return null;
    $json = json_decode($response, true);
    return $json['message'] ?? "HTTP $code";
}

function _sms_plivo(string $authId, string $authToken, string $from, string $to, string $body): ?string {
    if (!$authId) return 'Plivo Auth ID is required.';
    $url = 'https://api.plivo.com/v1/Account/' . $authId . '/Message/';
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode(['src' => $from, 'dst' => $to, 'text' => $body]),
        CURLOPT_USERPWD        => $authId . ':' . $authToken,
    ]);
    $response = curl_exec($ch);
    $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code >= 200 && $code < 300) return null;
    $json = json_decode($response, true);
    return $json['error'] ?? $json['message'] ?? "HTTP $code";
}

function _sms_telnyx(string $apiKey, string $from, string $to, string $body): ?string {
    $url = 'https://api.telnyx.com/v2/messages';
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS     => json_encode(['from' => $from, 'to' => $to, 'text' => $body]),
    ]);
    $response = curl_exec($ch);
    $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code >= 200 && $code < 300) return null;
    $json = json_decode($response, true);
    return $json['errors'][0]['detail'] ?? $json['message'] ?? "HTTP $code";
}

function _sms_vonage(string $apiKey, string $apiSecret, string $from, string $to, string $body): ?string {
    $url = 'https://rest.nexmo.com/sms/json';
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'api_key'    => $apiKey,
            'api_secret' => $apiSecret,
            'from'       => $from,
            'to'         => $to,
            'text'       => $body,
        ]),
    ]);
    $response = curl_exec($ch);
    $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200) return "HTTP $code";
    $json = json_decode($response, true);
    $msg  = $json['messages'][0] ?? [];
    if (($msg['status'] ?? '1') === '0') return null;
    return $msg['error-text'] ?? 'Unknown Vonage error';
}
