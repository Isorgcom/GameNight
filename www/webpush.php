<?php
/**
 * Self-contained Web Push sender (no composer): VAPID (RFC 8292) +
 * aes128gcm payload encryption (RFC 8291 / RFC 8188) on OpenSSL primitives.
 *
 * Entry point for callers is webpush_notify_user(): look up the user's
 * push_subscriptions rows and send the payload to each, pruning rows the
 * push service reports as gone (404/410). Everything here is best-effort:
 * a push failure must never block or delay the email/SMS/inbox pipeline,
 * so callers wrap in try/catch and this file throws only from the
 * low-level helpers.
 *
 * The VAPID keypair is generated once on first use and stored in
 * site_settings (vapid_private_pem / vapid_public_key). The public key is
 * the uncompressed P-256 point, base64url, exactly what
 * PushManager.subscribe({applicationServerKey}) wants.
 */

require_once __DIR__ . '/db.php';

// ── base64url ────────────────────────────────────────────────────────────────

function wp_b64url_encode(string $bin): string {
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

function wp_b64url_decode(string $s): string {
    $pad = strlen($s) % 4;
    if ($pad) $s .= str_repeat('=', 4 - $pad);
    return base64_decode(strtr($s, '-_', '+/'));
}

// ── VAPID keypair (generated once, stored in site_settings) ─────────────────

/**
 * Returns ['public' => base64url uncompressed point, 'private_pem' => PEM].
 * Generates and persists the pair on first call.
 */
function wp_vapid_keys(): array {
    $pub  = get_setting('vapid_public_key', '');
    $priv = get_setting('vapid_private_pem', '');
    if ($pub !== '' && $priv !== '') {
        return ['public' => $pub, 'private_pem' => $priv];
    }
    $key = openssl_pkey_new([
        'private_key_type' => OPENSSL_KEYTYPE_EC,
        'curve_name'       => 'prime256v1',
    ]);
    if ($key === false) throw new RuntimeException('VAPID keygen failed: ' . openssl_error_string());
    if (!openssl_pkey_export($key, $privPem)) {
        throw new RuntimeException('VAPID key export failed: ' . openssl_error_string());
    }
    $det = openssl_pkey_get_details($key);
    $pubPoint = "\x04" . wp_ec_pad($det['ec']['x']) . wp_ec_pad($det['ec']['y']);
    $pub = wp_b64url_encode($pubPoint);
    set_setting('vapid_public_key', $pub);
    set_setting('vapid_private_pem', $privPem);
    return ['public' => $pub, 'private_pem' => $privPem];
}

/** Left-pad an EC coordinate to 32 bytes (OpenSSL strips leading zeros). */
function wp_ec_pad(string $bin): string {
    return str_pad($bin, 32, "\x00", STR_PAD_LEFT);
}

// ── VAPID JWT (ES256) ────────────────────────────────────────────────────────

/**
 * Authorization header value for one push-service origin:
 * "vapid t=<jwt>, k=<public key>".
 */
function wp_vapid_auth_header(string $audience): string {
    $keys = wp_vapid_keys();
    $sub  = get_setting('site_url', '') ?: 'mailto:admin@localhost';
    $head = wp_b64url_encode(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $claims = wp_b64url_encode(json_encode([
        'aud' => $audience,
        'exp' => time() + 12 * 3600,
        'sub' => $sub,
    ]));
    $signingInput = $head . '.' . $claims;
    if (!openssl_sign($signingInput, $der, $keys['private_pem'], OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('VAPID JWT sign failed: ' . openssl_error_string());
    }
    $jwt = $signingInput . '.' . wp_b64url_encode(wp_der_sig_to_raw($der));
    return 'vapid t=' . $jwt . ', k=' . $keys['public'];
}

/** DER ECDSA signature (SEQUENCE of two INTEGERs) → raw 64-byte R||S. */
function wp_der_sig_to_raw(string $der): string {
    $off = 2;                                   // SEQUENCE tag + length
    if ((ord($der[1]) & 0x80) !== 0) $off += ord($der[1]) & 0x7f; // long-form length
    $out = '';
    for ($i = 0; $i < 2; $i++) {
        if (ord($der[$off]) !== 0x02) throw new RuntimeException('bad DER sig');
        $len = ord($der[$off + 1]);
        $int = substr($der, $off + 2, $len);
        $int = ltrim($int, "\x00");
        $out .= wp_ec_pad($int);
        $off += 2 + $len;
    }
    return $out;
}

// ── RFC 8291 payload encryption (aes128gcm) ──────────────────────────────────

/** HKDF-SHA256, single-block expand (all outputs here are ≤ 32 bytes). */
function wp_hkdf(string $salt, string $ikm, string $info, int $len): string {
    $prk = hash_hmac('sha256', $ikm, $salt, true);
    return substr(hash_hmac('sha256', $info . "\x01", $prk, true), 0, $len);
}

/** Wrap a raw uncompressed P-256 point in a SubjectPublicKeyInfo PEM. */
function wp_point_to_pem(string $point65): string {
    // Fixed DER prefix: SEQ { SEQ { OID ecPublicKey, OID prime256v1 }, BIT STRING (65 bytes) }
    $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $point65;
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

/**
 * Encrypt $payload for a subscription. Returns the full aes128gcm body
 * (header || ciphertext || tag) ready to POST.
 */
function wp_encrypt(string $payload, string $p256dh_b64u, string $auth_b64u): string {
    $uaPub  = wp_b64url_decode($p256dh_b64u);           // 65-byte client point
    $authSecret = wp_b64url_decode($auth_b64u);         // 16-byte auth secret
    if (strlen($uaPub) !== 65 || $uaPub[0] !== "\x04") throw new RuntimeException('bad p256dh');
    if (strlen($authSecret) !== 16) throw new RuntimeException('bad auth secret');

    // Ephemeral application-server keypair + ECDH shared secret
    $eph = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    if ($eph === false) throw new RuntimeException('ephemeral keygen failed');
    $det = openssl_pkey_get_details($eph);
    $asPub = "\x04" . wp_ec_pad($det['ec']['x']) . wp_ec_pad($det['ec']['y']);
    // No length arg: deprecated in PHP 8.5, and P-256 ECDH always yields 32 bytes.
    $shared = openssl_pkey_derive(openssl_pkey_get_public(wp_point_to_pem($uaPub)), $eph);
    if ($shared === false) throw new RuntimeException('ECDH derive failed');

    // RFC 8291 §3.3-3.4 key schedule
    $ikm   = wp_hkdf($authSecret, $shared, "WebPush: info\x00" . $uaPub . $asPub, 32);
    $salt  = random_bytes(16);
    $cek   = wp_hkdf($salt, $ikm, "Content-Encoding: aes128gcm\x00", 16);
    $nonce = wp_hkdf($salt, $ikm, "Content-Encoding: nonce\x00", 12);

    // RFC 8188 body: header || single final record (payload || 0x02 delimiter)
    $rs = 4096;
    $header = $salt . pack('N', $rs) . chr(65) . $asPub;
    $tag = '';
    $ct = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
    if ($ct === false) throw new RuntimeException('AES-GCM encrypt failed');
    return $header . $ct . $tag;
}

// ── Sending ──────────────────────────────────────────────────────────────────

/**
 * Send one push. Returns the HTTP status code (0 on transport error).
 * 404/410 means the subscription is dead and should be deleted.
 */
function wp_send(string $endpoint, string $p256dh, string $auth, string $payload, int $ttl = 86400): int {
    $body = wp_encrypt($payload, $p256dh, $auth);
    $aud  = parse_url($endpoint, PHP_URL_SCHEME) . '://' . parse_url($endpoint, PHP_URL_HOST);
    $port = parse_url($endpoint, PHP_URL_PORT);
    if ($port) $aud .= ':' . $port;

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/octet-stream',
            'Content-Encoding: aes128gcm',
            'Content-Length: ' . strlen($body),
            'TTL: ' . $ttl,
            'Urgency: normal',
            'Authorization: ' . wp_vapid_auth_header($aud),
        ],
    ]);
    curl_exec($ch);
    return (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
}

/**
 * Fan a notification out to every push subscription a user has.
 * Best-effort by design: logs failures, prunes dead subscriptions,
 * never throws. Call AFTER writing the user_notifications inbox row.
 */
function webpush_notify_user(PDO $db, int $user_id, string $title, string $body, string $link = '/'): void {
    try {
        $stmt = $db->prepare('SELECT id, endpoint, p256dh, auth FROM push_subscriptions WHERE user_id = ?');
        $stmt->execute([$user_id]);
        $subs = $stmt->fetchAll();
        if (!$subs) return;

        $payload = json_encode([
            'title' => mb_substr($title, 0, 120),
            'body'  => mb_substr($body, 0, 300),
            'link'  => $link,
        ], JSON_UNESCAPED_UNICODE);

        foreach ($subs as $s) {
            try {
                $code = wp_send($s['endpoint'], $s['p256dh'], $s['auth'], $payload);
                if ($code === 404 || $code === 410) {
                    $db->prepare('DELETE FROM push_subscriptions WHERE id = ?')->execute([$s['id']]);
                } elseif ($code >= 200 && $code < 300) {
                    $db->prepare('UPDATE push_subscriptions SET last_used = CURRENT_TIMESTAMP WHERE id = ?')
                       ->execute([$s['id']]);
                } else {
                    error_log("[GameNight] webpush send got HTTP $code for sub {$s['id']} (user $user_id)");
                }
            } catch (Throwable $e) {
                error_log('[GameNight] webpush send failed for sub ' . $s['id'] . ': ' . $e->getMessage());
            }
        }
    } catch (Throwable $e) {
        error_log('[GameNight] webpush fanout failed for user ' . $user_id . ': ' . $e->getMessage());
    }
}
