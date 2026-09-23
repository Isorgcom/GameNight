<?php
/**
 * Sign in to a connected app with this account.
 *
 * GET  /connect.php?app=<slug>&return=<url>&state=<nonce>
 *      Not signed in: hop through login.php (which carries the user through
 *      verification and MFA) and come back here. Signed in: one card asking
 *      "Continue to <app> as <you>?".
 * POST continue: mint a signed identity token (see _sso.php) and send the
 *      browser to <return>#gn_token=...&state=...
 * POST cancel:   <return>#gn_error=cancelled&state=...
 * POST switch:   log out and go round through login.php again.
 *
 * A validation failure never redirects. An unknown app or a return URL that
 * is not the registered origin renders an error page here, because the whole
 * point of the check is that the token must not land anywhere else.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_sso.php';

$slug   = trim((string)($_POST['app']    ?? $_GET['app']    ?? ''));
$return = trim((string)($_POST['return'] ?? $_GET['return'] ?? ''));
$state  = trim((string)($_POST['state']  ?? $_GET['state']  ?? ''));
if (!sso_validate_state($state)) $state = '';

// The URI to come back to after login.php or a switch of account.
$self_uri = '/connect.php?' . http_build_query(['app' => $slug, 'return' => $return, 'state' => $state]);

// require_login() sends a guest to a bare /login.php with no way back; the
// ics.php / join_league.php pattern carries the redirect. Then require_login()
// still runs for its must_change_password gate.
if (!current_user()) {
    header('Location: /login.php?redirect=' . urlencode($self_uri));
    exit;
}
$user      = require_login();
$current   = $user;
$site_name = get_setting('site_name', 'Game Night');

function sso_render_error(int $code, string $title, string $message): void {
    global $site_name, $current, $_is_mobile;
    http_response_code($code);
    header('Cache-Control: no-store');
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE) ?> &mdash; <?= htmlspecialchars($site_name, ENT_QUOTES | ENT_SUBSTITUTE) ?></title>
    <meta name="robots" content="noindex">
    <link rel="stylesheet" href="/style.css?v=<?= htmlspecialchars(APP_VERSION . '.' . (@filemtime(__DIR__ . '/style.css') ?: 0)) ?>">
</head>
<body>
<?php $nav_active = ''; require __DIR__ . '/_nav.php'; ?>
<div class="card-wrap">
    <div class="card">
        <h2><?= htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE) ?></h2>
        <p class="subtitle"><?= htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE) ?></p>
        <p style="margin-top:1.25rem"><a class="btn btn-outline" href="/">Back to <?= htmlspecialchars($site_name, ENT_QUOTES | ENT_SUBSTITUTE) ?></a></p>
    </div>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
</body>
</html>
    <?php
    exit;
}

$app = sso_app_by_slug($slug);
if (!$app || !(int)$app['enabled']) {
    sso_render_error(404, 'Not a connected app',
        'This sign-in link did not come from an app that is connected to ' . $site_name . '. Nothing has been shared.');
}
if (!sso_validate_return($app, $return)) {
    sso_render_error(400, 'Invalid return address',
        'The link asked to send you somewhere other than ' . $app['name'] . '. Nothing has been shared. Open ' . $app['name'] . ' and try signing in again.');
}

// The confirm form's POST answers with a redirect to the app. Chromium checks
// form-action against that redirect target, so the app's registered origin
// (never the return parameter) is allowed for this one response.
csp_allow_form_action_to(sso_app_origin($app));
header('Cache-Control: no-store');

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error = 'Invalid request token. Please try again.';
    } else {
        $action = (string)($_POST['action'] ?? 'continue');
        if ($action === 'cancel') {
            header('Location: ' . sso_build_redirect($return, ['gn_error' => 'cancelled', 'state' => $state]));
            exit;
        }
        if ($action === 'switch') {
            logout();
            header('Location: /login.php?redirect=' . urlencode($self_uri));
            exit;
        }
        $now    = time();
        $claims = [
            'iss'  => get_site_url(),
            'aud'  => (string)$app['slug'],
            'sub'  => (string)(int)$user['id'],
            'iat'  => $now,
            'exp'  => $now + SSO_TOKEN_TTL,
            'jti'  => bin2hex(random_bytes(16)),
            'name' => (string)$user['username'],
            'tier' => (string)($user['tier'] ?? 'Free'),
            // The member's photo, as the site-relative path the app fetches it
            // from - never the image itself. Null when they have not set one,
            // and null when it is larger than an avatar has any business being.
            'avatar_path' => sso_avatar_claim($user['avatar_path'] ?? null),
        ];
        $jwt = sso_sign_token($claims);
        sso_touch_app((int)$app['id']);
        db_log_activity((int)$user['id'], 'sso_login app=' . $app['slug']);
        header('Location: ' . sso_build_redirect($return, ['gn_token' => $jwt, 'state' => $state]));
        exit;
    }
}

$token = csrf_token();
$e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Continue to <?= $e($app['name']) ?> &mdash; <?= $e($site_name) ?></title>
    <meta name="robots" content="noindex">
    <link rel="stylesheet" href="/style.css?v=<?= htmlspecialchars(APP_VERSION . '.' . (@filemtime(__DIR__ . '/style.css') ?: 0)) ?>">
    <style>
        .sso-who { display:flex; align-items:center; gap:.85rem; padding:.85rem 1rem; border:1.5px solid #e2e8f0; border-radius:10px; background:#f8fafc; margin:1rem 0 1.25rem; }
        .sso-who .sso-name { font-weight:700; color:#1e293b; }
        .sso-who .sso-sub { font-size:.8rem; color:#64748b; }
        .sso-actions { display:flex; gap:.6rem; flex-wrap:wrap; align-items:center; }
        .sso-switch { background:none; border:none; padding:.4rem .2rem; font-size:.8rem; color:#64748b; text-decoration:underline; cursor:pointer; }
        .sso-note { font-size:.8rem; color:#64748b; margin-top:1rem; line-height:1.5; }
    </style>
</head>
<body>

<?php $nav_active = ''; require __DIR__ . '/_nav.php'; ?>

<div class="card-wrap">
    <div class="card">
        <h2>Continue to <?= $e($app['name']) ?>?</h2>
        <p class="subtitle"><?= $e($app['name']) ?> wants to sign you in with your <?= $e($site_name) ?> account.</p>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= $e($error) ?></div>
        <?php endif; ?>

        <div class="sso-who">
            <?= avatar_html((string)$user['username'], $user['avatar_path'] ?? null, 48) ?>
            <div>
                <div class="sso-name"><?= $e($user['username']) ?></div>
                <div class="sso-sub">Signed in to <?= $e($site_name) ?></div>
            </div>
        </div>

        <form method="post" action="/connect.php">
            <input type="hidden" name="csrf_token" value="<?= $e($token) ?>">
            <input type="hidden" name="app"    value="<?= $e($app['slug']) ?>">
            <input type="hidden" name="return" value="<?= $e($return) ?>">
            <input type="hidden" name="state"  value="<?= $e($state) ?>">
            <div class="sso-actions">
                <button type="submit" class="btn btn-primary" name="action" value="continue">Continue as <?= $e($user['username']) ?></button>
                <button type="submit" class="btn btn-outline" name="action" value="cancel">Cancel</button>
                <button type="submit" class="sso-switch" name="action" value="switch">Not you? Switch account</button>
            </div>
        </form>

        <p class="sso-note">
            <?= $e($app['name']) ?> receives your username and your profile photo, and nothing else: no email address, phone number or password.
            You can stop using it at any time by signing out inside <?= $e($app['name']) ?>.
        </p>
    </div>
</div>

<?php require __DIR__ . '/_footer.php'; ?>
</body>
</html>
