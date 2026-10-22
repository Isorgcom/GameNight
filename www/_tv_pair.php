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

/** A fresh pairing for a TV: ['code', 'secret', 'expires_in'] (seconds). */
function tv_pair_new(PDO $db): array {
    $db->exec("DELETE FROM tv_pairings WHERE expires_at < datetime('now')");
    $live = $db->prepare("SELECT 1 FROM tv_pairings WHERE code = ?");
    $code = '';
    for ($try = 0; $try < 20; $try++) {
        $code = '';
        for ($i = 0; $i < TV_CODE_LEN; $i++) {
            $code .= TV_CODE_ALPHABET[random_int(0, strlen(TV_CODE_ALPHABET) - 1)];
        }
        $live->execute([$code]);
        if (!$live->fetchColumn()) break;
    }
    $secret = bin2hex(random_bytes(16));
    $db->prepare("INSERT INTO tv_pairings (code, secret, expires_at) VALUES (?, ?, datetime('now', ?))")
       ->execute([$code, $secret, '+' . TV_CODE_MINUTES . ' minutes']);
    return ['code' => $code, 'secret' => $secret, 'expires_in' => TV_CODE_MINUTES * 60];
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
 * seeds it.
 */
function tv_pair_target_url(PDO $db, int $session_id, int $user_id): string {
    $timer = pk_ensure_timer_row($db, $session_id, pk_user_beta_pref($db, $user_id));
    $key = rawurlencode((string)$timer['remote_key']);
    return !empty($timer['use_beta'])
        ? '/timer.php?key=' . $key . '&tv=1'
        : '/timer_classic.php?view=remote&key=' . $key . '&display=1';
}
