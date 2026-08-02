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
function send_sms(string $to, string $body, bool $append_optout = true): ?string {
    $e164 = sms_normalize_phone($to);
    if (!$e164) return 'Invalid phone number.';

    // Append opt-out instruction for carrier compliance. Admin/host command
    // replies pass $append_optout=false so their conversational responses stay
    // clean. COMMANDS (not HELP) because SMS platforms intercept the reserved
    // HELP keyword with their own auto-reply before it reaches our webhook.
    if ($append_optout) {
        $body .= "\nReply STOP to unsubscribe, COMMANDS for options.";
    }

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
 * Log an inbound SMS (called from sms_webhook.php / wa_webhook.php).
 * Returns the sms_log row id so the webhook can tag it with attribution.
 */
function sms_log_inbound(string $phone, string $body, string $provider, string $raw = ''): ?int {
    return sms_log('inbound', $phone, $body, $provider, 'received', null, $raw);
}

function sms_log(string $direction, string $phone, string $body, ?string $provider, string $status, ?string $error, string $raw = ''): ?int {
    try {
        [$ctx_eid, $ctx_user] = notif_log_context_get();
        // Digits-only conversation key; stays NULL for email rows (address in phone).
        $d = preg_replace('/\D/', '', $phone);
        if (strlen($d) === 11 && $d[0] === '1') $d = substr($d, 1);
        $digits = strlen($d) === 10 ? $d : null;
        $db = get_db();
        $db->prepare('INSERT INTO sms_log (direction, phone, body, provider, status, error, raw_response, event_id, username, phone_digits) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$direction, $phone, $body, $provider, $status, $error, $raw !== '' ? $raw : null, $ctx_eid, $ctx_user, $digits]);
        return (int)$db->lastInsertId();
    } catch (Exception $e) {
        // Don't let logging failures break SMS sending
        return null;
    }
}

/* ── WhatsApp via WAHA (self-hosted WhatsApp HTTP API) ─────────────────────── */

/**
 * Return null if the WAHA session is WORKING, else a human error string.
 *
 * Result is cached per session for a few seconds so a bulk drain (many
 * recipients in one process) does a single status probe rather than one per
 * message, while still catching a session that drops between batches.
 */
function waha_require_working_session(string $waha_url, string $session, string $apiKey): ?string {
    static $cache = []; // session => [checked_at, error]
    $now = time();
    if (isset($cache[$session]) && ($now - $cache[$session][0]) < 15) {
        return $cache[$session][1];
    }

    $ch = curl_init(rtrim($waha_url, '/') . '/api/sessions/' . rawurlencode($session));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_HTTPHEADER     => ['X-Api-Key: ' . $apiKey],
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);

    if ($cerr) {
        $err = "WhatsApp session unreachable: $cerr";
    } elseif ($code === 404) {
        $err = 'WhatsApp session not started (STOPPED)';
    } else {
        $j      = json_decode($resp, true);
        $status = $j['status'] ?? 'UNKNOWN';
        $err    = $status === 'WORKING' ? null : "WhatsApp session not connected (status: $status)";
    }

    $cache[$session] = [$now, $err];
    return $err;
}


/**
 * WAHA session watchdog — called from cron every ~5 minutes.
 *
 * The July 2026 outages taught us: WAHA never reconnects a FAILED session on
 * its own ("do not reconnect the session"), the start endpoint rejects FAILED
 * sessions with 422 so naive recovery loops spin forever, and nothing told
 * the admin WhatsApp had been down for days. This watchdog fixes all three:
 *   - probes the session and stores its status (Activity tab reads it)
 *   - FAILED, or STARTING for 3+ checks (a wedged reconnect loop) → issues
 *     the CORRECT recovery call (POST /sessions/{s}/restart), at most once
 *     per 10 minutes — transient drops self-heal this way
 *   - non-WORKING for 2+ consecutive checks (~10 min), OR 4+ bad probes in a
 *     rolling 6h window → emails every admin, deduped to once per 24h, with
 *     status-specific instructions (a revoked device needs a human to
 *     re-scan the QR; no restart can fix that)
 *
 * The rolling-window rule exists because a flapping session defeats the
 * consecutive-streak rule: in the 2026-07-27 outage the session reconnected
 * for ~3 minutes between drops, so 5-minute probes almost always read
 * WORKING, the streak reset every time, and 30 hours passed with no alert.
 * For the same reason, recovery (clearing the alert dedup + the flap
 * counter) is only declared after 6 consecutive WORKING probes (~30 min).
 *
 * Self-arming: restarts/alerts only happen after the session has been seen
 * WORKING at least once, so installs that never use WhatsApp stay silent.
 * Disable entirely with site_setting waha_watchdog = '0'.
 */
function waha_watchdog(): array {
    $out = ['status' => 'skipped', 'action' => ''];
    if (get_setting('waha_watchdog', '1') !== '1') return $out;
    $waha_url = get_setting('waha_url', 'http://waha:3000');
    if ($waha_url === '') return $out;
    $session = get_setting('waha_session', 'default');
    $apiKey  = get_setting('waha_api_key', 'gamenight-waha-internal');

    // Probe current status
    $ch = curl_init(rtrim($waha_url, '/') . '/api/sessions/' . rawurlencode($session));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_HTTPHEADER     => ['X-Api-Key: ' . $apiKey],
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);

    if ($cerr)              $status = 'UNREACHABLE';
    elseif ($code === 404)  $status = 'STOPPED';
    else                    $status = strtoupper((string)(json_decode($resp, true)['status'] ?? 'UNKNOWN'));

    $out['status'] = $status;
    set_setting('waha_last_status', $status);
    set_setting('waha_last_status_ts', (string)time());

    if ($status === 'WORKING') {
        set_setting('waha_seen_working', '1');
        set_setting('waha_fail_streak', '0');
        $ok = (int)get_setting('waha_ok_streak', '0') + 1;
        set_setting('waha_ok_streak', (string)$ok);
        // Only declare recovery after ~30 min stable — a flapping session
        // reads WORKING on most probes, and clearing the alert dedup on a
        // single good probe would re-send the alert email on every drop.
        if ($ok >= 6) {
            set_setting('waha_flap_count', '0');
            if (get_setting('waha_down_alerted_at', '') !== '') {
                set_setting('waha_down_alerted_at', '');
                db_log_activity(0, "WAHA watchdog: session '$session' recovered — WORKING again");
            }
        }
        return $out;
    }

    if (get_setting('waha_seen_working', '0') !== '1') return $out; // never armed

    set_setting('waha_ok_streak', '0');
    $streak = (int)get_setting('waha_fail_streak', '0') + 1;
    set_setting('waha_fail_streak', (string)$streak);

    // Flap counter: bad probes in a rolling 6h window, deliberately NOT reset
    // by a WORKING probe (that's the whole point — see function comment).
    $winStart = (int)get_setting('waha_flap_window_start', '0');
    if (time() - $winStart > 21600) {
        set_setting('waha_flap_window_start', (string)time());
        $flap = 1;
    } else {
        $flap = (int)get_setting('waha_flap_count', '0') + 1;
    }
    set_setting('waha_flap_count', (string)$flap);

    // Auto-restart FAILED sessions, and STARTING ones stuck for 3+ checks
    // (~15 min — a healthy start takes seconds; longer means a wedged
    // reconnect loop, e.g. WhatsApp rejecting the engine's login handshake).
    // Not SCAN_QR_CODE (needs a human scan), not STOPPED (may be a
    // deliberate admin stop).
    if ($status === 'FAILED' || ($status === 'STARTING' && $streak >= 3)) {
        $lastRestart = (int)get_setting('waha_last_auto_restart', '0');
        if (time() - $lastRestart >= 600) {
            set_setting('waha_last_auto_restart', (string)time());
            $ch = curl_init(rtrim($waha_url, '/') . '/api/sessions/' . rawurlencode($session) . '/restart');
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => '',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_HTTPHEADER     => ['X-Api-Key: ' . $apiKey, 'Content-Type: application/json'],
            ]);
            curl_exec($ch);
            curl_close($ch);
            db_log_activity(0, "WAHA watchdog: session '$session' $status — auto-restart issued (check #$streak)", 'critical');
            $out['action'] = 'restarted';
        }
    }

    // Alert admins after 2+ consecutive bad checks, or 4+ bad checks in the
    // rolling 6h window (flapping), at most once per day.
    if ($streak >= 2 || $flap >= 4) {
        $lastAlert = (int)get_setting('waha_down_alerted_at', '0');
        if (time() - $lastAlert >= 86400) {
            set_setting('waha_down_alerted_at', (string)time());
            require_once __DIR__ . '/mail.php';
            $hint = match ($status) {
                'SCAN_QR_CODE' => 'The session needs a fresh link: open the WhatsApp tab in Site Settings and scan the QR with the WhatsApp phone (Settings → Linked devices → Link a device).',
                'FAILED'       => 'The watchdog is auto-restarting it every 10 minutes. If it stays FAILED, WhatsApp has likely revoked the linked device — use Logout then Start on the WhatsApp tab and re-scan the QR.',
                'STARTING'     => 'The session is stuck (re)starting. The watchdog auto-restarts it; if it does not recover, WhatsApp is likely rejecting the login — use Logout then Start on the WhatsApp tab and re-scan the QR.',
                'UNREACHABLE'  => 'The WAHA container may be down. Check `docker ps` on the server and restart it if needed.',
                default        => 'Check the WhatsApp tab in Site Settings.',
            };
            $desc = $streak >= 2
                ? 'has not been WORKING for at least ' . (int)($streak * 5) . ' minutes'
                : 'is flapping — it failed ' . (int)$flap . ' status checks over the last few hours, reconnecting in between';
            $subject = 'WhatsApp notifications are down (session ' . $status . ')';
            $html = '<p>The WhatsApp (WAHA) session <strong>' . htmlspecialchars($session) . '</strong> ' . $desc
                  . '. Current status: <strong>' . htmlspecialchars($status) . '</strong>.</p>'
                  . '<p>' . htmlspecialchars($hint) . '</p>'
                  . '<p>Until it recovers, notifications to WhatsApp-preferred users are queued/retried and may be dropped after 3 attempts.</p>'
                  . '<p><a href="' . htmlspecialchars(get_site_url()) . '/admin_settings.php?tab=sms">Open WhatsApp settings</a></p>';
            $admins = get_db()->query("SELECT username, email FROM users WHERE role = 'admin' AND email IS NOT NULL AND email != ''")->fetchAll();
            foreach ($admins as $a) {
                send_email($a['email'], $a['username'], $subject, $html);
            }
            $why = $streak >= 2 ? "for ~" . ($streak * 5) . " min" : "flapping ($flap bad checks in 6h)";
            db_log_activity(0, "WAHA watchdog: alerted " . count($admins) . " admin(s) — session $status $why", 'critical');
            $out['action'] .= ($out['action'] !== '' ? '+' : '') . 'alerted';
        }
    }

    return $out;
}


/**
 * Send a WhatsApp message via WAHA.
 *
 * Verifies the WAHA session is WORKING before sending: a flapping/unlinked
 * session otherwise returns a 422/500 that reads as a hard failure per message.
 * Surfacing "session not connected" lets the queue retry once it recovers
 * instead of silently dropping the notification.
 *
 * Returns null on success, error string on failure.
 */
function send_whatsapp(string $to, string $body): ?string {
    $e164 = sms_normalize_phone($to);
    if (!$e164) return 'Invalid phone number.';

    $waha_url = get_setting('waha_url', 'http://waha:3000');
    $session  = get_setting('waha_session', 'default');
    if (!$waha_url) return 'WhatsApp (WAHA) not configured.';
    $apiKey = get_setting('waha_api_key', 'gamenight-waha-internal');

    // ── Pre-send session check: only send into a WORKING session ──
    $err = waha_require_working_session($waha_url, $session, $apiKey);
    if ($err !== null) {
        sms_log('outbound', $e164, $body, 'waha', 'failed', $err, '');
        return $err;
    }

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

/**
 * Reply to whoever messaged in, on the right channel. Shared by the SMS webhook,
 * the WhatsApp webhook, and the admin-command layer (sms_admin.php):
 *   - twilio/plivo:        synchronous TwiML/XML written as the webhook response
 *   - telnyx/vonage/surge: outbound SMS via API
 *   - whatsapp:            outbound WhatsApp via WAHA
 * Uses the entry script's global $from (the sender's number). Admin-command
 * replies pass $append_optout=false so they don't carry the "Reply STOP" footer.
 */
function respond_to_provider(string $provider, string $message, bool $append_optout = true): void {
    global $from;
    switch ($provider) {
        case 'twilio':
            header('Content-Type: text/xml');
            echo '<?xml version="1.0" encoding="UTF-8"?>';
            echo '<Response><Message>' . htmlspecialchars($message) . '</Message></Response>';
            // TwiML replies never pass through send_sms(), so log them here or the
            // outbound half of a webhook conversation is invisible in sms_log.
            sms_log('outbound', sms_normalize_phone($from) ?? $from, $message, $provider, 'sent', null);
            break;
        case 'plivo':
            header('Content-Type: text/xml');
            echo '<?xml version="1.0" encoding="UTF-8"?>';
            echo '<Response><Message><Body>' . htmlspecialchars($message) . '</Body></Message></Response>';
            sms_log('outbound', sms_normalize_phone($from) ?? $from, $message, $provider, 'sent', null);
            break;
        case 'telnyx':
        case 'vonage':
        case 'surge':
            send_sms($from, $message, $append_optout);
            break;
        case 'whatsapp':
            send_whatsapp($from, $message);
            break;
    }
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
    $raw = $response ?: '';
    if ($curlErr) return 'Connection error: ' . $curlErr;
    if ($code !== 200) return "HTTP $code";
    $json = json_decode($response, true);
    $msg  = $json['messages'][0] ?? [];
    if (($msg['status'] ?? '1') === '0') return null;
    return $msg['error-text'] ?? 'Unknown Vonage error';
}
