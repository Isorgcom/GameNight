<?php
// The stop link from a verification email (v1.19.8). Whoever holds the mail,
// which may be someone who never signed up (a script fed their address to the
// form), asks for no more sign-up or verification mail to the address. The link
// carries the address and an HMAC of it under the app secret (email_stop_url()
// in db.php), so only the holder of the mail can act on it, and it keeps
// working after the account is gone. Nothing happens on GET: mail scanners
// open links, so the page asks for a button press, as verify_email.php does.
// Pressing it adds the address to email_suppressions and removes any
// unverified sign-up carrying it; the same page lets the address back in.
require_once __DIR__ . '/auth.php';

$site_name = get_setting('site_name', 'Game Night');
$e_raw   = (string)($_REQUEST['e'] ?? '');
$key     = (string)($_REQUEST['k'] ?? '');
$email   = email_stop_decode($e_raw);
$valid   = $email !== '' && preg_match('/^[0-9a-f]{64}$/', $key) && hash_equals(email_stop_key($email), $key);
$state   = 'bad';      // bad | ask | stopped | allowed
$error   = '';
$removed = [];
$acted   = false;      // this request changed something (wording differs from a revisit)

if ($valid) {
    $state = email_suppressed($email) ? 'stopped' : 'ask';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        session_start_safe();
        if (!csrf_verify()) {
            $error = 'This page expired. Open the link from your email again.';
        } elseif (($_POST['do'] ?? '') === 'allow') {
            email_unsuppress($email);
            db_log_anon_activity('email_stop: ' . email_masked($email) . ' allowed sign-up mail again');
            $state = 'allowed';
            $acted = true;
        } else {
            email_suppress($email, 'stop link');
            foreach (unverified_signup_ids(null, $email) as $id) {
                $name = remove_unverified_signup($id, 'the address owner used the stop link');
                if ($name !== '') $removed[] = $name;
            }
            db_log_anon_activity('email_stop: ' . email_masked($email) . ' opted out of sign-up mail' . ($removed ? ' (removed ' . implode(', ', $removed) . ')' : ''), 'warning');
            $state = 'stopped';
            $acted = true;
        }
    }
}
$csrf = $valid ? csrf_token() : '';
$h = fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title><?= $h($site_name) ?></title>
    <link rel="stylesheet" href="/style.css?v=<?= $h(APP_VERSION . '.' . (@filemtime(__DIR__ . '/style.css') ?: 0)) ?>">
</head>
<body>
<nav><div class="nav-top"><a class="brand" href="/"><?= $h($site_name) ?></a></div></nav>
<div class="card-wrap">
    <div class="card" style="text-align:center">
        <?php if ($error !== ''): ?>
            <h2>Try again</h2>
            <div class="alert alert-error" style="text-align:left"><?= $h($error) ?></div>
        <?php elseif ($state === 'bad'): ?>
            <h2>This link is not valid</h2>
            <p class="subtitle">Open the link from the email again. If it still lands here, the email was not sent by <?= $h($site_name) ?>.</p>
        <?php elseif ($state === 'ask'): ?>
            <h2>Stop sign-up mail to this address?</h2>
            <p class="subtitle">Someone, maybe not you, entered <strong><?= $h($email) ?></strong> on the <?= $h($site_name) ?> sign-up form. Nobody can use that account without the link in the email, and an account that is never verified is removed on its own after <?= (int)UNVERIFIED_ACCOUNT_DAYS ?> days.</p>
            <p class="subtitle">Press the button and <?= $h($site_name) ?> will send no more sign-up or verification mail to this address. Any unverified account carrying it is removed now.</p>
            <form method="post" action="/email_stop.php" style="margin-top:1rem">
                <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
                <input type="hidden" name="e" value="<?= $h($e_raw) ?>">
                <input type="hidden" name="k" value="<?= $h($key) ?>">
                <input type="hidden" name="do" value="stop">
                <button type="submit" class="btn btn-primary" style="display:inline-block">Stop mail to this address</button>
            </form>
        <?php elseif ($state === 'stopped'): ?>
            <h2><?= $acted ? 'Done' : 'Already stopped' ?></h2>
            <p class="subtitle"><?= $h($site_name) ?> will not send sign-up or verification mail to <strong><?= $h($email) ?></strong>.<?= $removed ? ' The unverified account that carried it has been removed.' : '' ?></p>
            <p class="subtitle">Changed your mind, or want to sign up yourself one day? Let the address back in first.</p>
            <form method="post" action="/email_stop.php" style="margin-top:1rem">
                <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
                <input type="hidden" name="e" value="<?= $h($e_raw) ?>">
                <input type="hidden" name="k" value="<?= $h($key) ?>">
                <input type="hidden" name="do" value="allow">
                <button type="submit" class="btn btn-outline" style="display:inline-block">Allow sign-up mail again</button>
            </form>
        <?php else: ?>
            <h2>Allowed again</h2>
            <p class="subtitle"><strong><?= $h($email) ?></strong> can receive sign-up and verification mail from <?= $h($site_name) ?> again.</p>
            <a href="/register.php" class="btn btn-primary" style="display:inline-block;margin-top:1rem">Sign Up</a>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
