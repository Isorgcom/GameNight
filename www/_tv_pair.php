<?php
/**
 * Send to TV: pair a TV's browser with a game without typing a timer link.
 *
 * tv.php (the TV, no login) mints a row: a short CODE people read off the
 * screen and a long SECRET only that browser holds. A signed-in host enters
 * the code on tv_send.php (or scans the TV's QR, which carries it) and picks
 * a game they manage; the row gets that game's session. The TV polls
 * tv_dl.php with its SECRET, never the code, so seeing or guessing a code
 * lets nobody read a timer key: the most a code buys is putting a game you
 * already run on the screen showing it.
 *
 * What the TV is sent is the same view-only display a QR scan opens.
 */

// No 0/O, 1/I/L, 2/Z, 5/S, 8/B: read across a room on a TV.
const TV_CODE_ALPHABET = 'ACDEFGHJKMNPQRTUVWXY3479';
const TV_CODE_LEN = 4;
const TV_CODE_MINUTES = 10;
// Pairings one address may mint an hour (/tv loads and in-place renewals,
// both without a login): a TV renews six times an hour, so thirty is a
// household of TVs, not a script filling the table.
const TV_MINT_PER_HOUR = 30;
// Codes a host may get wrong in ten minutes before this address waits.
const TV_SEND_MISSES_PER_10MIN = 10;

/**
 * True once this address minted TV_MINT_PER_HOUR pairings in the last hour.
 * Counted in activity_log ('tv_pair_mint', user 0) by tv_pair_new() itself,
 * so every mint path is covered.
 */
function tv_pair_mint_limited(PDO $db): bool {
    $q = $db->prepare("SELECT COUNT(*) FROM activity_log WHERE ip = ? AND action = 'tv_pair_mint' AND created_at > datetime('now', '-1 hour')");
    $q->execute([get_client_ip()]);
    return (int)$q->fetchColumn() >= TV_MINT_PER_HOUR;
}

/**
 * A fresh pairing for a TV: ['code', 'secret', 'expires_in'] (seconds). The
 * code is one no live pairing holds: a host's entry must mean one TV. With
 * 24^4 codes and a ten-minute life a collision is rare; fifty misses in a
 * row means the table is being filled, and then minting stops rather than
 * handing two TVs one code (RuntimeException).
 */
function tv_pair_new(PDO $db): array {
    $db->exec("DELETE FROM tv_pairings WHERE expires_at < datetime('now')");
    $live = $db->prepare("SELECT 1 FROM tv_pairings WHERE code = ?");
    $code = '';
    for ($try = 0; ; $try++) {
        if ($try >= 50) throw new RuntimeException('No free TV pairing code');
        $code = '';
        for ($i = 0; $i < TV_CODE_LEN; $i++) {
            $code .= TV_CODE_ALPHABET[random_int(0, strlen(TV_CODE_ALPHABET) - 1)];
        }
        $live->execute([$code]);
        if (!$live->fetchColumn()) break;
    }
    db_log_anon_activity('tv_pair_mint');
    $secret = bin2hex(random_bytes(16));
    $db->prepare("INSERT INTO tv_pairings (code, secret, expires_at) VALUES (?, ?, datetime('now', ?))")
       ->execute([$code, $secret, '+' . TV_CODE_MINUTES . ' minutes']);
    return ['code' => $code, 'secret' => $secret, 'expires_in' => TV_CODE_MINUTES * 60];
}

/** True once this address missed TV_SEND_MISSES_PER_10MIN codes in ten minutes. */
function tv_send_miss_limited(PDO $db): bool {
    $q = $db->prepare("SELECT COUNT(*) FROM activity_log WHERE ip = ? AND action = 'tv_send_miss' AND created_at > datetime('now', '-10 minutes')");
    $q->execute([get_client_ip()]);
    return (int)$q->fetchColumn() >= TV_SEND_MISSES_PER_10MIN;
}

/** Uppercase, drop spaces and dashes; '' unless it could be a code. */
function tv_pair_clean_code(string $raw): string {
    $c = strtoupper(preg_replace('/[\s-]+/', '', $raw));
    return preg_match('/^[' . TV_CODE_ALPHABET . ']{' . TV_CODE_LEN . '}$/', $c) ? $c : '';
}

/**
 * Where the TV goes for a session: the game's own display choice, so a
 * Classic game gets Classic's TV display. Creates the timer row (and so the
 * key) when the game has never opened a timer, seeded as the rest of the app
 * seeds it. tv=1 on either page marks a TV (sound on) and lets tv.php frame
 * it, which is how the TV's full screen survives the game arriving.
 */
function tv_pair_target_url(PDO $db, int $session_id, int $user_id): string {
    $timer = pk_ensure_timer_row($db, $session_id, pk_user_beta_pref($db, $user_id));
    $key = rawurlencode((string)$timer['remote_key']);
    return !empty($timer['use_beta'])
        ? '/timer.php?key=' . $key . '&tv=1'
        : '/timer_classic.php?view=remote&key=' . $key . '&display=1&tv=1';
}
