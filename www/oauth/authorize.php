<?php
/**
 * /oauth/authorize — where an app sends a person to sign in and say what the
 * app may do. The one page of the sign-in a human sees.
 *
 * Nothing about the request is trusted until it is checked, in this order:
 * the app must be one we know, and the address it wants the browser sent back
 * to must be one it registered. Until both hold, any problem is shown HERE,
 * on our page: sending an error "back" to an address we have not verified
 * would make this page a way to bounce people to anywhere. After that,
 * problems go back to the app, as OAuth has it.
 */
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../_oauth.php';

$db        = get_db();
$site_name = (string)get_setting('site_name', 'Game Night');
$in        = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
$g         = fn(string $k) => is_string($in[$k] ?? null) ? trim($in[$k]) : '';
$req = ['response_type' => $g('response_type'), 'client_id' => $g('client_id'), 'redirect_uri' => $g('redirect_uri'), 'state' => $g('state'),
        'scope' => $g('scope'), 'code_challenge' => $g('code_challenge'), 'code_challenge_method' => $g('code_challenge_method'), 'resource' => $g('resource')];
$self_uri = '/oauth/authorize?' . http_build_query(array_filter($req, 'strlen'));
$e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE);

function oauth_page(string $title, callable $body, int $code = 200): void {
    global $site_name, $current, $_is_mobile, $e;
    http_response_code($code);
    header('Cache-Control: no-store');
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $e($title) ?> &mdash; <?= $e($site_name) ?></title>
    <meta name="robots" content="noindex">
    <link rel="stylesheet" href="/style.css?v=<?= htmlspecialchars(APP_VERSION . '.' . (@filemtime(__DIR__ . '/../style.css') ?: 0)) ?>">
    <style>
        .oa-card { max-width: 480px; }
        .oa-who, .oa-where { display: flex; align-items: center; gap: .85rem; padding: .8rem 1rem; border: 1.5px solid var(--border); border-radius: 10px; background: #f8fafc; margin: 1rem 0; }
        .oa-name { font-weight: 700; color: #1e293b; overflow-wrap: anywhere; }
        .oa-sub { font-size: .8rem; color: var(--muted); }
        .oa-where { display: block; }
        .oa-where code { font-size: .9rem; font-weight: 600; overflow-wrap: anywhere; }
        .oa-warn { background: #fffbeb; border: 1.5px solid #f59e0b; color: #92400e; border-radius: 8px; padding: .7rem .9rem; font-size: .85rem; line-height: 1.45; margin: 1rem 0; }
        .oa-perms { border: 0; padding: 0; margin: 1.1rem 0 0; min-inline-size: 0; }
        .oa-perms legend { font-size: .85rem; font-weight: 700; padding: 0; margin-bottom: .2rem; }
        .oa-perm { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: .65rem 0; border-bottom: 1px solid #f1f5f9; min-block-size: 48px; cursor: pointer; font-size: .9rem; }
        .oa-perm:last-of-type { border-bottom: none; }
        .oa-switch { appearance: none; -webkit-appearance: none; flex: none; inline-size: 44px; block-size: 26px; margin: 0; border-radius: 13px; background: #94a3b8; position: relative; cursor: pointer; transition: background .15s; }
        .oa-switch::after { content: ""; position: absolute; inset-block-start: 3px; inset-inline-start: 3px; inline-size: 20px; block-size: 20px; border-radius: 50%; background: #fff; box-shadow: 0 1px 2px rgba(15,23,42,.3); transition: translate .15s; }
        .oa-switch:checked { background: var(--accent); }
        .oa-switch:checked::after { translate: 18px 0; }
        .oa-switch:focus-visible { outline: 3px solid #1d4ed8; outline-offset: 3px; }
        .oa-switch:disabled { opacity: .55; cursor: default; }
        .oa-actions { display: flex; gap: .6rem; flex-wrap: wrap; align-items: center; margin-top: 1.25rem; }
        .oa-actions .btn { min-block-size: 44px; }
        .oa-link { background: none; border: none; padding: .4rem .2rem; font-size: .8rem; color: var(--muted); text-decoration: underline; cursor: pointer; }
        .oa-note { font-size: .8rem; color: var(--muted); margin-top: 1rem; line-height: 1.5; }
        @media (forced-colors: active) { .oa-switch { appearance: auto; -webkit-appearance: auto; inline-size: auto; block-size: auto; } .oa-switch::after { display: none; } }
        @media (max-width: 520px) { .oa-actions .btn { inline-size: 100%; } }
    </style>
</head>
<body>
<?php $nav_active = ''; require __DIR__ . '/../_nav.php'; ?>
<div class="card-wrap">
    <div class="card oa-card">
        <?php $body(); ?>
    </div>
</div>
<?php require __DIR__ . '/../_footer.php'; ?>
</body>
</html>
    <?php
    exit;
}
function oauth_page_error(string $title, string $message, int $code = 400): void {
    global $site_name, $e;
    oauth_page($title, function () use ($title, $message, $site_name, $e) { ?>
        <h2><?= $e($title) ?></h2>
        <p class="subtitle"><?= $e($message) ?></p>
        <p style="margin-top:1.25rem"><a class="btn btn-outline" href="/">Back to <?= $e($site_name) ?></a></p>
    <?php }, $code);
}

// ── 1. Who is asking, and where would the browser go back to? ───────────────
$client = $req['client_id'] !== '' ? oauth_client($db, $req['client_id']) : null;
$current = current_user();      // for the nav on an error page; sign-in is enforced below
if (!$client) {
    oauth_page_error('Unknown app', 'This sign-in request did not come from an app ' . $site_name . ' recognises, so nothing has been shared. Go back to the app and try connecting again.');
}
if (!oauth_redirect_uri_allowed($client, $req['redirect_uri'])) {
    oauth_page_error('Sign-in request not valid', 'The app asked to send you somewhere it has not registered, so the sign-in was stopped and nothing has been shared.');
}

// The return address is the registered one. Only the person's answer goes there.
$back = function (array $params) use ($req) {
    $params += ['iss' => oauth_issuer()];
    if ($req['state'] !== '') $params['state'] = $req['state'];
    $sep = str_contains($req['redirect_uri'], '?') ? '&' : '?';
    header('Cache-Control: no-store');
    header('Location: ' . $req['redirect_uri'] . $sep . http_build_query($params));
    exit;
};
// A request that is malformed before the person has seen anything is shown
// here, never bounced to the app (v1.38.0). Anyone can register an app, so
// "the app's registered address" can be any site: redirecting these errors
// let a link starting with this site's address land somewhere else. Only the
// person's own answer (Allow or Cancel) goes back to the app.
if ($req['response_type'] !== 'code') oauth_page_error('Sign-in request not valid', 'The app asked for a kind of sign-in this site does not offer (only response_type=code is supported), so nothing has been shared.');
if ($req['code_challenge_method'] !== 'S256' || !preg_match('/^[A-Za-z0-9_-]{43}$/', $req['code_challenge'])) {
    oauth_page_error('Sign-in request not valid', 'The app did not send the security check this site requires (PKCE with S256), so nothing has been shared.');
}
if (strlen($req['state']) > 512) oauth_page_error('Sign-in request not valid', 'The app sent a malformed request, so nothing has been shared.');
if (!oauth_resource_ok($req['resource'])) oauth_page_error('Sign-in request not valid', 'The app asked for access to something other than ' . oauth_resource() . ', so nothing has been shared.');

// ── 2. Who is the person? ───────────────────────────────────────────────────
if (!$current) {
    header('Location: /login.php?redirect=' . urlencode($self_uri));
    exit;
}
$user = require_login();        // also the must-change-password gate

$asked   = oauth_scopes_requested($req['scope']);
$label   = oauth_client_label($client);
$rhost   = (string)parse_url($req['redirect_uri'], PHP_URL_HOST);
$rport   = parse_url($req['redirect_uri'], PHP_URL_PORT);
$local   = oauth_is_loopback($req['redirect_uri']);
$origin  = parse_url($req['redirect_uri'], PHP_URL_SCHEME) . '://' . $rhost . ($rport ? ':' . $rport : '');

// Allow and Cancel both answer with a redirect to the app. Chromium checks
// form-action against that redirect, so the verified return origin is allowed
// for this one response.
csp_allow_form_action_to($origin);
header('Cache-Control: no-store');

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error = 'Invalid request token. Please try again.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'switch') {
            logout();
            header('Location: /login.php?redirect=' . urlencode($self_uri));
            exit;
        }
        if ($action !== 'allow') {
            db_log_activity((int)$user['id'], "declined to connect app '$label'");
            $back(['error' => 'access_denied', 'error_description' => 'The person declined.']);
        }
        // Only what was asked for AND left switched on. "read" is always part of it.
        $ticked  = array_map('strval', (array)($_POST['scopes'] ?? []));
        $granted = array_values(array_filter($asked, fn($s) => $s === 'read' || in_array($s, $ticked, true)));
        $code = bin2hex(random_bytes(32));
        $db->prepare('DELETE FROM oauth_codes WHERE expires_at < datetime(\'now\', \'-1 day\')')->execute();
        $db->prepare('INSERT INTO oauth_codes (code_hash, client_pk, user_id, redirect_uri, code_challenge, scopes, resource, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
           ->execute([hash('sha256', $code), (int)$client['id'], (int)$user['id'], $req['redirect_uri'], $req['code_challenge'],
                      implode(',', $granted), oauth_resource(), gmdate('Y-m-d H:i:s', time() + OAUTH_CODE_TTL)]);
        db_log_activity((int)$user['id'], "connected app '$label' (" . implode(',', $granted) . ") returning to $rhost");
        send_account_notice((int)$user['id'], 'An app was connected to your account',
            "\"$label\" was connected to your account and can now act as you, within: " . implode(', ', $granted) . ". It returned to $rhost. If this was not you, open Settings, Security and disconnect it, then change your password.");
        $back(['code' => $code]);
    }
}

$token = csrf_token();
oauth_page('Connect ' . $label, function () use ($e, $label, $client, $user, $site_name, $asked, $rhost, $local, $req, $token, $error) { ?>
        <h2>Connect <?= $e($label) ?>?</h2>
        <p class="subtitle"><?= $e($label) ?> is asking to work with your <?= $e($site_name) ?> account. It will act as you.</p>

        <?php if ($error): ?><div class="alert alert-error"><?= $e($error) ?></div><?php endif; ?>

        <div class="oa-who">
            <?= avatar_html((string)$user['username'], $user['avatar_path'] ?? null, 44) ?>
            <div style="min-width:0">
                <div class="oa-name"><?= $e($user['username']) ?></div>
                <div class="oa-sub">Signed in to <?= $e($site_name) ?></div>
            </div>
        </div>

        <div class="oa-where">
            <div class="oa-sub">After you choose, your browser goes back to</div>
            <code><?= $e($local ? 'a program on this computer (' . $rhost . ')' : $rhost) ?></code>
        </div>
        <?php if ($local): ?>
            <div class="oa-warn">This request comes from a program running on this computer, such as Claude Code. Only continue if you just started connecting <?= $e($site_name) ?> from that program yourself.</div>
        <?php else: ?>
            <div class="oa-warn"><?= $e($site_name) ?> has not checked who runs <?= $e($label) ?>. The name is what the app calls itself; the address above is where it really is. Only continue if you started this from an app you trust.</div>
        <?php endif; ?>

        <form method="post" action="/oauth/authorize" aria-label="Connect <?= $e($label) ?>">
            <input type="hidden" name="csrf_token" value="<?= $e($token) ?>">
            <?php foreach ($req as $k => $v): ?><input type="hidden" name="<?= $e($k) ?>" value="<?= $e($v) ?>"><?php endforeach; ?>
            <fieldset class="oa-perms">
                <legend>What it may do</legend>
                <?php foreach ($asked as $sc): ?>
                <label class="oa-perm">
                    <span><?= $e(API_TOKEN_SCOPES[$sc]) ?></span>
                    <input type="checkbox" switch role="switch" class="oa-switch" name="scopes[]" value="<?= $e($sc) ?>" checked <?= $sc === 'read' ? 'disabled' : '' ?>>
                </label>
                <?php endforeach; ?>
            </fieldset>
            <div class="oa-actions">
                <button type="submit" class="btn btn-primary" name="action" value="allow">Allow</button>
                <button type="submit" class="btn btn-outline" name="action" value="cancel">Cancel</button>
                <button type="submit" class="oa-link" name="action" value="switch">Not you? Switch account</button>
            </div>
        </form>
        <p class="oa-note">Switch off anything you do not want it to do. You can disconnect it at any time under Settings &rarr; Security, and changing your password disconnects everything.</p>
<?php });
