<?php
/**
 * Self-contained TOTP (RFC 6238) primitives — no external dependency.
 *
 * Used by the opt-in Multi-Factor Authentication feature. The shared secret is
 * a base32 string compatible with Google Authenticator / Authy / 1Password etc.
 * Defaults match every common authenticator app: SHA1, 6 digits, 30s period.
 */

/**
 * RFC 4648 base32 encode (no padding), uppercase.
 */
function base32_encode(string $bytes): string {
    if ($bytes === '') return '';
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $out = '';
    $buffer = 0;
    $bitsLeft = 0;
    $len = strlen($bytes);
    for ($i = 0; $i < $len; $i++) {
        $buffer = ($buffer << 8) | ord($bytes[$i]);
        $bitsLeft += 8;
        while ($bitsLeft >= 5) {
            $bitsLeft -= 5;
            $out .= $alphabet[($buffer >> $bitsLeft) & 31];
        }
    }
    if ($bitsLeft > 0) {
        $out .= $alphabet[($buffer << (5 - $bitsLeft)) & 31];
    }
    return $out;
}

/**
 * RFC 4648 base32 decode. Ignores padding/whitespace/case. Returns raw bytes
 * (empty string on invalid input).
 */
function base32_decode(string $b32): string {
    $b32 = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $b32));
    if ($b32 === '') return '';
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $out = '';
    $buffer = 0;
    $bitsLeft = 0;
    $len = strlen($b32);
    for ($i = 0; $i < $len; $i++) {
        $val = strpos($alphabet, $b32[$i]);
        if ($val === false) return '';
        $buffer = ($buffer << 5) | $val;
        $bitsLeft += 5;
        if ($bitsLeft >= 8) {
            $bitsLeft -= 8;
            $out .= chr(($buffer >> $bitsLeft) & 0xFF);
        }
    }
    return $out;
}

/**
 * Generate a fresh base32 TOTP secret (20 random bytes → 32 base32 chars).
 */
function totp_generate_secret(): string {
    return base32_encode(random_bytes(20));
}

/**
 * Compute the TOTP code for a given base32 secret at a unix timestamp.
 * Returns a zero-padded 6-digit string.
 */
function totp_code(string $secret, int $timestamp, int $period = 30, int $digits = 6): string {
    $key = base32_decode($secret);
    if ($key === '') return '';
    $counter = intdiv($timestamp, $period);
    // 8-byte big-endian counter
    $binCounter = pack('N*', 0, $counter);
    $hash = hash_hmac('sha1', $binCounter, $key, true);
    $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
    $part = (ord($hash[$offset]) & 0x7F) << 24
          | (ord($hash[$offset + 1]) & 0xFF) << 16
          | (ord($hash[$offset + 2]) & 0xFF) << 8
          | (ord($hash[$offset + 3]) & 0xFF);
    $code = $part % (10 ** $digits);
    return str_pad((string)$code, $digits, '0', STR_PAD_LEFT);
}

/**
 * Verify a user-entered code against a base32 secret, allowing ±$window steps
 * of clock skew (default ±1 = ±30s). Constant-time comparison.
 */
function totp_verify(string $secret, string $code, int $window = 1, int $period = 30, int $digits = 6): bool {
    return totp_matched_step($secret, $code, $window, $period, $digits) !== null;
}

/**
 * Like totp_verify, but returns the matched time-step counter (intdiv(ts,period))
 * instead of a bool, or null if no candidate matched. The step lets callers
 * enforce one-time use per window (see totp_verify_consume).
 */
function totp_matched_step(string $secret, string $code, int $window = 1, int $period = 30, int $digits = 6): ?int {
    $code = preg_replace('/\D/', '', $code);
    if (strlen($code) !== $digits) return null;
    $now = time();
    for ($i = -$window; $i <= $window; $i++) {
        $ts = $now + ($i * $period);
        $candidate = totp_code($secret, $ts, $period, $digits);
        if ($candidate !== '' && hash_equals($candidate, $code)) {
            return intdiv($ts, $period);
        }
    }
    return null;
}

/**
 * Verify a TOTP code AND consume it: a given time-step can authenticate a user
 * only once, so an intercepted code can't be replayed while it's still inside its
 * validity window. Persists the highest accepted step in users.mfa_totp_last_step
 * and rejects any code whose step is <= the last one already used.
 */
function totp_verify_consume(PDO $db, int $uid, string $secret, string $code): bool {
    $step = totp_matched_step($secret, $code);
    if ($step === null) return false;
    $stmt = $db->prepare('SELECT mfa_totp_last_step FROM users WHERE id = ?');
    $stmt->execute([$uid]);
    $last = (int)($stmt->fetchColumn() ?: 0);
    if ($step <= $last) return false; // already used this (or a later) step — replay
    $db->prepare('UPDATE users SET mfa_totp_last_step = ? WHERE id = ?')->execute([$step, $uid]);
    return true;
}

/**
 * Build an otpauth:// provisioning URI for QR-code enrollment.
 * $label is typically the username/email; $issuer is the site name.
 */
function totp_provisioning_uri(string $secret, string $label, string $issuer): string {
    $issuerEnc = rawurlencode($issuer);
    $labelEnc  = rawurlencode($issuer . ':' . $label);
    return 'otpauth://totp/' . $labelEnc
         . '?secret=' . rawurlencode($secret)
         . '&issuer=' . $issuerEnc
         . '&algorithm=SHA1&digits=6&period=30';
}
