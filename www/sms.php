<?php
require_once __DIR__ . '/db.php';

/**
 * Provider configuration: fields needed for each SMS provider.
 * Used by both send_sms() and the admin settings UI.
 */
function get_sms_providers(): array {
    return [
        'twilio' => [
            'label'  => 'Twilio (untested)',
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
            'label'  => 'Plivo (untested)',
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
            'label'  => 'Telnyx (untested)',
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
            'label'  => 'Vonage (Nexmo) (untested)',
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
        'surge' => [
            'label'  => 'Surge',
            'fields' => [
                'sms_sid'           => ['label' => 'Account ID',      'type' => 'text',     'placeholder' => 'acct_01j...'],
                'sms_token'         => ['label' => 'API Key',          'type' => 'password', 'placeholder' => 'your_api_key'],
                'sms_from'          => ['label' => 'From Number',      'type' => 'text',     'placeholder' => '+12015550123'],
                'sms_webhook_secret' => ['label' => 'Signing Secret',  'type' => 'password', 'placeholder' => 'whsec_...'],
            ],
            'help' => [
                ['Dashboard', 'https://surge.app'],
                ['Account ID', 'Found on the Surge dashboard, starts with <code>acct_</code> (not <code>usr_</code>)'],
                ['API Key', 'Create under Settings &rsaquo; API Keys'],
                ['From Number', 'Buy a number under Phone Numbers'],
                ['Webhook URL', 'Set to <code>https://yourdomain.com/sms_webhook.php</code>'],
                ['Webhook Events', 'Subscribe to <code>message.received</code> in your webhook settings or inbound replies won&rsquo;t work'],
                ['Signing Secret', 'Copy from your webhook&rsquo;s Signing Secret field to verify inbound requests are from Surge'],
                ['Pricing', 'Outbound ~$0.008/msg, inbound ~$0.008/msg'],
                ['10DLC', 'Fast A2P registration (24-48 hours) via Campaigns'],
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
 * Send a phone verification code via Surge API.
 * Returns ['id' => 'vfn_...'] on success, or ['error' => 'message'] on failure.
 */
function surge_send_verification(string $phone): array {
    $e164 = sms_normalize_phone($phone);
    if (!$e164) return ['error' => 'Invalid phone number.'];

    $token = get_setting('sms_token');
    if (!$token) return ['error' => 'SMS not configured.'];

    $ch = curl_init('https://api.surge.app/verifications');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
        ],
        CURLOPT_POSTFIELDS     => json_encode(['phone_number' => $e164]),
    ]);
    $response = curl_exec($ch);
    $curlErr  = curl_error($ch);
    $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curlErr) return ['error' => 'Connection error: ' . $curlErr];
    $json = json_decode($response, true);
    if ($code === 201 && !empty($json['id'])) {
        return ['id' => $json['id']];
    }
    return ['error' => $json['error']['message'] ?? $json['message'] ?? "HTTP $code"];
}

/**
 * Check a phone verification code via Surge API.
 * Returns 'ok', 'incorrect', 'exhausted', 'expired', or error string.
 */
function surge_check_verification(string $id, string $code): string {
    $token = get_setting('sms_token');
    if (!$token) return 'SMS not configured.';

    $ch = curl_init('https://api.surge.app/verifications/' . urlencode($id) . '/checks');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
        ],
        CURLOPT_POSTFIELDS     => json_encode(['code' => $code]),
    ]);
    $response = curl_exec($ch);
    $curlErr  = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curlErr) return 'Connection error: ' . $curlErr;
    $json = json_decode($response, true);
    return $json['result'] ?? $json['error']['message'] ?? "HTTP $httpCode";
}

/**
 * Shorten a URL using the built-in self-hosted shortener.
 * Stores in short_links table, returns a /s/CODE URL.
 * Falls back to the original URL on any failure.
 */
function shorten_url(string $url): string {
    $apiKey = get_setting('shortio_api_key', '');
    $domain = get_setting('shortio_domain', '');
    if ($apiKey === '' || $domain === '') return $url;

    try {
        $db = get_db();
        // Check local cache first (avoid duplicate API calls)
        $existing = $db->prepare('SELECT code FROM short_links WHERE target_url = ?');
        $existing->execute([$url]);
        $cached = $existing->fetchColumn();
        if ($cached) return $cached;

        // Call Short.io API
        $ch = curl_init('https://api.short.io/links');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: ' . $apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS     => json_encode([
                'domain'      => $domain,
                'originalURL' => $url,
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
        ]);
        $resp   = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status >= 200 && $status < 300) {
            $data  = json_decode($resp, true);
            $short = $data['shortURL'] ?? '';
            if ($short !== '') {
                // Cache locally so we don't call the API again for the same URL
                $db->prepare('INSERT OR IGNORE INTO short_links (code, target_url) VALUES (?, ?)')
                   ->execute([$short, $url]);
                return $short;
            }
        }
    } catch (Exception $e) {}
    return $url; // Fallback to original URL on any error
}

/**
 * Send an SMS via the configured provider.
 * Returns null on success, error string on failure.
 */
function send_sms(string $to, string $body): ?string {
    $e164 = sms_normalize_phone($to);
    if (!$e164) return 'Invalid phone number.';

    // Append opt-out instruction for carrier compliance
    $body .= "\nReply STOP to unsubscribe, HELP for commands.";

    // Auto-shorten any URLs in the body if URL shortener is enabled
    if (get_setting('url_shortener_enabled') === '1') {
        $body = preg_replace_callback(
            '#https?://[^\s]+#',
            fn($m) => shorten_url($m[0]),
            $body
        );
    }

    $provider = get_setting('sms_provider', 'twilio');
    $sid      = get_setting('sms_sid');
    $token    = get_setting('sms_token');
    $from     = get_setting('sms_from');

    // Backwards compat: fall back to old twilio_* keys if sms_* are empty
    if (!$sid)   $sid   = get_setting('twilio_sid');
    if (!$token) $token = get_setting('twilio_token');
    if (!$from)  $from  = get_setting('twilio_from');

    if (!$token || !$from) return 'SMS not configured.';

    $raw = '';
    switch ($provider) {
        case 'twilio':
            $err = _sms_twilio($sid, $token, $from, $e164, $body, $raw); break;
        case 'plivo':
            $err = _sms_plivo($sid, $token, $from, $e164, $body, $raw); break;
        case 'telnyx':
            $err = _sms_telnyx($token, $from, $e164, $body, $raw); break;
        case 'vonage':
            $err = _sms_vonage($sid, $token, $from, $e164, $body, $raw); break;
        case 'surge':
            $err = _sms_surge($sid, $token, $from, $e164, $body, $raw); break;
        default:
            $err = "Unknown SMS provider: $provider";
    }

    sms_log('outbound', $e164, $body, $provider, $err === null ? 'sent' : 'failed', $err, $raw);
    return $err;
}

/**
 * Log an inbound SMS (called from sms_webhook.php).
 */
function sms_log_inbound(string $phone, string $body, string $provider, string $raw = ''): void {
    sms_log('inbound', $phone, $body, $provider, 'received', null, $raw);
}

function sms_log(string $direction, string $phone, string $body, ?string $provider, string $status, ?string $error, string $raw = ''): void {
    try {
        get_db()->prepare('INSERT INTO sms_log (direction, phone, body, provider, status, error, raw_response) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$direction, $phone, $body, $provider, $status, $error, $raw !== '' ? $raw : null]);
    } catch (Exception $e) {
        // Don't let logging failures break SMS sending
    }
}

/* ── WhatsApp via Meta Cloud API ──────────────────────────────────────────── */

/**
 * Send a WhatsApp message via Meta Cloud API.
 * Uses a pre-approved template for business-initiated messages,
 * or plain text if within a 24-hour reply window.
 *
 * Returns null on success, error string on failure.
 */
function send_whatsapp(string $to, string $body): ?string {
    $e164 = sms_normalize_phone($to);
    if (!$e164) return 'Invalid phone number.';

    $waha_url = get_setting('waha_url', 'http://waha:3000');
    $session  = get_setting('waha_session', 'default');
    if (!$waha_url) return 'WhatsApp (WAHA) not configured.';

    // Auto-shorten URLs if enabled
    if (get_setting('url_shortener_enabled') === '1') {
        $body = preg_replace_callback(
            '#https?://[^\s]+#',
            fn($m) => shorten_url($m[0]),
            $body
        );
    }

    // WAHA expects phone@c.us format (no + prefix, digits only)
    $chatId = ltrim($e164, '+') . '@c.us';

    $payload = json_encode([
        'chatId'  => $chatId,
        'text'    => $body,
        'session' => $session,
    ]);

    $apiKey = get_setting('waha_api_key', 'gamenight-waha-internal');
    $ch = curl_init(rtrim($waha_url, '/') . '/api/sendText');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'X-Api-Key: ' . $apiKey],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $response = curl_exec($ch);
    $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    $err = null;
    if ($curlErr) {
        $err = "WAHA connection error: $curlErr";
    } elseif ($code < 200 || $code >= 300) {
        $json = json_decode($response, true);
        $err = $json['message'] ?? "HTTP $code";
    }

    sms_log('outbound', $e164, $body, 'waha', $err === null ? 'sent' : 'failed', $err, $response);
    return $err;
}

/* ── SMS Provider implementations ────────────────────────────────────────── */

function _sms_twilio(string $sid, string $token, string $from, string $to, string $body, string &$raw = ''): ?string {
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
    $curlErr  = curl_error($ch);
    $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $raw = $response ?: '';
    if ($curlErr) return 'Connection error: ' . $curlErr;
    if ($code === 201) return null;
    $json = json_decode($response, true);
    return $json['message'] ?? "HTTP $code";
}

function _sms_plivo(string $authId, string $authToken, string $from, string $to, string $body, string &$raw = ''): ?string {
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
    $curlErr  = curl_error($ch);
    $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $raw = $response ?: '';
    if ($curlErr) return 'Connection error: ' . $curlErr;
    if ($code >= 200 && $code < 300) return null;
    $json = json_decode($response, true);
    return $json['error'] ?? $json['message'] ?? "HTTP $code";
}

function _sms_telnyx(string $apiKey, string $from, string $to, string $body, string &$raw = ''): ?string {
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
    $curlErr  = curl_error($ch);
    $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $raw = $response ?: '';
    if ($curlErr) return 'Connection error: ' . $curlErr;
    if ($code >= 200 && $code < 300) return null;
    $json = json_decode($response, true);
    return $json['errors'][0]['detail'] ?? $json['message'] ?? "HTTP $code";
}

function _sms_surge(string $accountId, string $apiKey, string $from, string $to, string $body, string &$raw = ''): ?string {
    if (!$accountId) return 'Surge Account ID is required.';
    $url = 'https://api.surge.app/accounts/' . $accountId . '/messages';
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS     => json_encode([
            'conversation' => ['contact' => ['phone_number' => $to]],
            'body'         => $body,
        ]),
    ]);
    $response = curl_exec($ch);
    $curlErr  = curl_error($ch);
    $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $raw = $response ?: '';
    if ($curlErr) return 'Connection error: ' . $curlErr;
    if ($code >= 200 && $code < 300) return null;
    $json = json_decode($response, true);
    return $json['error']['message'] ?? $json['message'] ?? "HTTP $code";
}

function _sms_vonage(string $apiKey, string $apiSecret, string $from, string $to, string $body, string &$raw = ''): ?string {
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
    $curlErr  = curl_error($ch);
    $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $raw = $response ?: '';
    if ($curlErr) return 'Connection error: ' . $curlErr;
    if ($code !== 200) return "HTTP $code";
    $json = json_decode($response, true);
    $msg  = $json['messages'][0] ?? [];
    if (($msg['status'] ?? '1') === '0') return null;
    return $msg['error-text'] ?? 'Unknown Vonage error';
}
