<?php
/**
 * Pair a DealerButton to your account (v1.42.1), at /button. Reached by
 * scanning the QR the button shows, which fills in the code. The host
 * enters the code and a name; the button collects its token on its next poll
 * and from then on shows whatever game this host is running. See _button.php.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_poker_helpers.php';
require_once __DIR__ . '/_button.php';

$current = current_user();
if (!$current) { header('Location: /login.php?redirect=' . urlencode($_SERVER['REQUEST_URI'] ?? '/button')); exit; }
$user = require_login();
$db = get_db();
$site_name = get_setting('site_name', 'Game Night');
$nav_active = '';

$error = '';
$raw_code = (string)($_POST['code'] ?? $_GET['c'] ?? '');
$code = tv_pair_clean_code($raw_code);
$name = button_clean_name((string)($_POST['name'] ?? ''));
$tv_code = false;   // the code entered is a TV's, not a button's

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    session_start_safe();
    if (!csrf_verify()) {
        $error = 'Your session expired. Try again.';
    } elseif (tv_send_miss_limited($db)) {
        $error = 'Too many codes that did not match. Wait a few minutes, then try the code on the button again.';
    } elseif ($code === '') {
        $error = $raw_code === '' ? 'Enter the code showing on the button.' : 'That is not a button code. It is four letters and numbers, shown on the button\'s screen.';
    } elseif (button_count($db, (int)$current['id']) >= BUTTON_MAX_PER_USER) {
        $error = 'You have ' . BUTTON_MAX_PER_USER . ' buttons paired, which is the most one account can have. Remove one in Settings first.';
    } else {
        $pair = button_pairing_for_code($db, $code);
        if (!$pair) {
            db_log_activity((int)$current['id'], 'tv_send_miss');
            $error = 'No button is showing ' . $code . ' right now. Codes change every ' . TV_CODE_MINUTES . ' minutes, so check the button and try again.';
        } elseif (($pair['device'] ?? '') !== 'button') {
            $tv_code = true;
        } elseif (!button_claim($db, (int)$pair['id'], (int)$current['id'], $name)) {
            $error = 'That code was just used. Check the button for a new one.';
        } else {
            db_log_activity((int)$current['id'], "paired a DealerButton '$name'");
            // Told by email, as for a new API token or connected app (v1.45.2):
            // pairing asks for no password, so this is how the owner hears of
            // one made from a session somebody else was using.
            send_account_notice((int)$current['id'], 'A DealerButton was paired',
                "A DealerButton named \"$name\" was paired to your account. It shows the clock of the games you run and can do nothing else. If this was not you, remove it under Settings, Security, DealerButtons, and change your password, which also unpairs every button.");
            $_SESSION['button_paired'] = $name;
            header('Location: /button?paired=1');
            exit;
        }
    }
}

$paired_name = '';
if (!empty($_GET['paired'])) {
    session_start_safe();
    $paired_name = (string)($_SESSION['button_paired'] ?? '');
    unset($_SESSION['button_paired']);
}
$h = fn(string $s) => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title>Pair a button &mdash; <?= $h($site_name) ?></title>
    <link rel="stylesheet" href="/style.css?v=<?= $h(APP_VERSION . '.' . (@filemtime(__DIR__ . '/style.css') ?: 0)) ?>">
    <style>
        .btp { max-width: 520px; margin: 2rem auto 4rem; padding: 0 1rem; }
        .btp h1 { font-size: 1.5rem; margin: 0 0 .4rem; }
        .btp-lead { color: var(--fg-475569, #475569); margin: 0 0 1.4rem; line-height: 1.45; }
        .btp-code { width: 100%; box-sizing: border-box; font-size: 2rem; font-weight: 800; letter-spacing: .25em;
                    text-transform: uppercase; text-align: center; padding: .5rem; border: 2px solid var(--bd-cbd5e1, #cbd5e1);
                    border-radius: 10px; font-variant-numeric: tabular-nums; }
        .btp-code:focus { border-color: #2563eb; }
        .btp-label { display: block; font-weight: 600; font-size: .9rem; margin: 1rem 0 .4rem; }
        .btp-label:first-of-type { margin-top: 0; }
        .btp-name { width: 100%; box-sizing: border-box; }
        .btp-go { margin-top: 1.2rem; width: 100%; min-height: 48px; font-size: 1rem; }
        .btp-help { margin-top: 2rem; font-size: .88rem; color: var(--fg-475569, #475569); line-height: 1.5; }
    </style>
</head>
<body>
<?php require __DIR__ . '/_nav.php'; ?>
<main class="btp">
    <h1>Pair a button</h1>
    <?php if ($paired_name !== ''): ?>
        <div class="alert alert-success" role="status"><strong>Paired.</strong> <?= $h($paired_name) ?> picks up your game in a few seconds.</div>
        <p class="btp-lead">From now on it shows whichever tournament you are running, every game night, with no pairing again. To unpair it, remove it under <a href="/settings.php#paired-buttons">Settings, Security, DealerButtons</a>.</p>
    <?php else: ?>
        <p class="btp-lead">Connect a DealerButton to your account. Press PAIR on the button, then enter the code it shows. Once paired, it shows the clock of whichever tournament you are running.</p>
        <?php if ($tv_code): ?>
            <div class="alert alert-error" role="alert">That code is showing on a TV, not a button. To put a game on that TV, use <a href="/tv/send?c=<?= $h($code) ?>">Send to TV</a>.</div>
        <?php elseif ($error !== ''): ?>
            <div class="alert alert-error" role="alert"><?= $h($error) ?></div>
        <?php endif; ?>
        <form method="post" action="/button">
            <input type="hidden" name="csrf_token" value="<?= $h(csrf_token()) ?>">
            <label class="btp-label" for="btpCode">Code on the button</label>
            <input class="btp-code" id="btpCode" name="code" maxlength="6" autocomplete="off" autocapitalize="characters"
                   spellcheck="false" inputmode="text" required
                   value="<?= $h($code !== '' ? $code : strtoupper(substr($raw_code, 0, 6))) ?>">
            <label class="btp-label" for="btpName">Name it</label>
            <input class="btp-name" id="btpName" name="name" maxlength="40" autocomplete="off"
                   placeholder="DealerButton" value="<?= $h(isset($_POST['name']) ? $name : '') ?>">
            <button type="submit" class="btn btn-primary btp-go">Pair button</button>
        </form>
    <?php endif; ?>
    <p class="btp-help">A paired button only shows the clock: the level, the blinds, the time left and the players left. It can't control the game, and it sees only tournaments you run or help run. A game being played comes first, then one that finished in the last half hour, then one set up for today or the next 12 hours. When a game ends it moves on to your next one by itself.</p>
</main>
<?php require __DIR__ . '/_footer.php'; ?>
</body>
</html>
