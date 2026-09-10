<?php
/**
 * Admin: connected apps for the sign-in bridge (connect.php / _sso.php).
 *
 * A connected app is an external site that may sign people in with their
 * account here: it sends the browser to connect.php and receives a signed
 * identity token back. This page registers those apps (slug + the one origin
 * a token may be returned to), shows the public signing key an app needs to
 * verify tokens, and can rotate that key. Site admins only.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_sso.php';

$current = require_login();
if (($current['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('Access denied.');
}

$db        = get_db();
$site_name = get_setting('site_name', 'Game Night');

session_start_safe();
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

function sso_admin_base_url_error(string $url): ?string {
    if ($url === '' || strlen($url) > 255) return 'Base URL is required.';
    if (!filter_var($url, FILTER_VALIDATE_URL)) return 'Base URL is not a valid URL.';
    $p = parse_url($url);
    if (!in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true)) return 'Base URL must start with http:// or https://.';
    if (isset($p['query']) || isset($p['fragment']) || isset($p['user']) || isset($p['pass'])) return 'Base URL must be a bare origin (optionally with a path): no query, fragment or credentials.';
    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Invalid request token.'];
        header('Location: /admin_sso_apps.php');
        exit;
    }
    $action = (string)($_POST['action'] ?? '');
    $uid    = (int)$current['id'];

    if ($action === 'create') {
        $slug = strtolower(trim((string)($_POST['slug'] ?? '')));
        $name = trim((string)($_POST['name'] ?? ''));
        $base = rtrim(trim((string)($_POST['base_url'] ?? '')), '/');
        $err  = null;
        if (!preg_match('/^[a-z0-9-]{2,32}$/', $slug)) $err = 'Slug must be 2-32 characters: lowercase letters, digits and hyphens.';
        elseif ($name === '' || mb_strlen($name) > 80)  $err = 'Name is required (80 characters max).';
        else $err = sso_admin_base_url_error($base);
        if ($err === null) {
            try {
                $db->prepare('INSERT INTO sso_apps (slug, name, base_url, created_by) VALUES (?, ?, ?, ?)')
                   ->execute([$slug, $name, $base, $uid]);
                db_log_activity($uid, "admin sso app create slug=$slug base_url=$base");
                $_SESSION['flash'] = ['type' => 'success', 'msg' => "Connected app \"$name\" added. Its audience is \"$slug\"."];
            } catch (PDOException $e) {
                $_SESSION['flash'] = ['type' => 'error', 'msg' => "An app with the slug \"$slug\" already exists."];
            }
        } else {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => $err];
        }
        header('Location: /admin_sso_apps.php');
        exit;
    }

    if ($action === 'toggle' || $action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $st = $db->prepare('SELECT * FROM sso_apps WHERE id = ?');
        $st->execute([$id]);
        $app = $st->fetch();
        if (!$app) {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'That app no longer exists.'];
        } elseif ($action === 'toggle') {
            $on = (int)$app['enabled'] ? 0 : 1;
            $db->prepare('UPDATE sso_apps SET enabled = ? WHERE id = ?')->execute([$on, $id]);
            db_log_activity($uid, 'admin sso app ' . ($on ? 'enable' : 'disable') . ' slug=' . $app['slug']);
            $_SESSION['flash'] = ['type' => 'success', 'msg' => '"' . $app['name'] . '" ' . ($on ? 'enabled' : 'disabled') . '.'];
        } else {
            $db->prepare('DELETE FROM sso_apps WHERE id = ?')->execute([$id]);
            db_log_activity($uid, 'admin sso app delete slug=' . $app['slug']);
            $_SESSION['flash'] = ['type' => 'success', 'msg' => '"' . $app['name'] . '" removed. Sign-in links from it now get a 404.'];
        }
        header('Location: /admin_sso_apps.php');
        exit;
    }

    if ($action === 'rotate') {
        $keys = sso_rotate_keys();
        db_log_activity($uid, 'admin sso keys rotated kid=' . $keys['kid'], 'warning');
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Signing key regenerated. Every connected app must be given the new public key before sign-in works again.'];
        header('Location: /admin_sso_apps.php');
        exit;
    }

    header('Location: /admin_sso_apps.php');
    exit;
}

$keys   = sso_keys();
$issuer = rtrim(get_site_url(), '/');
$apps   = $db->query('SELECT * FROM sso_apps ORDER BY created_at DESC')->fetchAll();

$local_tz = new DateTimeZone(display_timezone());
function sso_admin_fmt(?string $utc_dt, DateTimeZone $local_tz): string {
    if (!$utc_dt) return '—';
    try {
        return (new DateTime($utc_dt, new DateTimeZone('UTC')))
            ->setTimezone($local_tz)->format('M j, Y g:i A');
    } catch (Exception $e) { return $utc_dt; }
}

// The .env snippet an app operator pastes: one line per variable, so the PEM's
// newlines are written as the two characters "\n" (which the app unescapes).
$env_pem = str_replace("\n", '\n', trim($keys['public_pem']));
$e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Connected Apps — <?= $e($site_name) ?></title>
    <link rel="stylesheet" href="/style.css?v=<?= htmlspecialchars(APP_VERSION . '.' . (@filemtime(__DIR__ . '/style.css') ?: 0)) ?>">
    <style>
        .sa-card { background:#fff; border:1.5px solid #e2e8f0; border-radius:10px; padding:1.25rem; margin-bottom:1rem; }
        .sa-card h2 { font-size:1.05rem; font-weight:700; margin:0 0 .5rem; }
        .sa-help { color:#64748b; font-size:.875rem; line-height:1.55; margin:0 0 1rem; }
        .sa-table { width:100%; border-collapse:collapse; font-size:.875rem; }
        .sa-table th, .sa-table td { padding:.55rem .6rem; border-bottom:1px solid #f1f5f9; text-align:left; vertical-align:middle; }
        .sa-table th { font-size:.7rem; text-transform:uppercase; letter-spacing:.05em; color:#94a3b8; font-weight:700; }
        .sa-btn { border:none; border-radius:6px; padding:.3rem .8rem; font-size:.78rem; font-weight:600; cursor:pointer; color:#fff; background:#475569; }
        .sa-btn.danger { background:#dc2626; }
        .sa-btn.primary { background:var(--accent); }
        .sa-flash { border:1.5px solid; border-radius:10px; padding:1rem 1.25rem; margin-bottom:1rem; font-size:.9rem; }
        .sa-flash.success { background:#f0fdf4; border-color:#86efac; color:#166534; }
        .sa-flash.error   { background:#fef2f2; border-color:#fca5a5; color:#991b1b; }
        .sa-pill { display:inline-block; padding:.1rem .5rem; border-radius:999px; font-size:.72rem; font-weight:700; }
        .sa-pill.on  { background:#dcfce7; color:#166534; }
        .sa-pill.off { background:#f1f5f9; color:#64748b; }
        .sa-pre { width:100%; box-sizing:border-box; font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:.75rem; line-height:1.4; border:1.5px solid #e2e8f0; border-radius:8px; padding:.6rem .75rem; background:#f8fafc; color:#1e293b; resize:vertical; }
        .sa-row { display:flex; gap:.6rem; flex-wrap:wrap; align-items:center; margin-top:.5rem; }
        .sa-form { display:grid; grid-template-columns:1fr 1fr 2fr auto; gap:.6rem; align-items:end; }
        .sa-form label { display:block; font-size:.72rem; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.05em; margin-bottom:.25rem; }
        .sa-form input { width:100%; box-sizing:border-box; padding:.45rem .6rem; border:1.5px solid #e2e8f0; border-radius:6px; font-size:.875rem; }
        .sa-warn { background:#fffbeb; border:1.5px solid #f59e0b; color:#92400e; border-radius:8px; padding:.75rem 1rem; font-size:.85rem; line-height:1.5; margin-top:1rem; }
        .sa-steps { background:#f8fafc; border:1.5px solid #e2e8f0; border-radius:8px; padding:.9rem 1rem; }
        .sa-steps-head { font-size:.7rem; text-transform:uppercase; letter-spacing:.05em; color:#94a3b8; font-weight:700; margin-bottom:.5rem; }
        .sa-steps ol { margin:0; padding-left:1.15rem; font-size:.875rem; color:#334155; line-height:1.6; }
        .sa-steps li + li { margin-top:.5rem; }
        .sa-kv { display:grid; grid-template-columns:auto 1fr; gap:.3rem .7rem; align-items:center; margin-top:.45rem; }
        .sa-kv span { font-size:.7rem; text-transform:uppercase; letter-spacing:.05em; color:#94a3b8; font-weight:700; }
        .sa-kv code { background:#fff; border:1px solid #e2e8f0; border-radius:5px; padding:.2rem .45rem; font-size:.8rem; }
        .sa-details { margin-top:1rem; border-top:1px solid #f1f5f9; padding-top:.75rem; }
        .sa-details > summary { cursor:pointer; font-size:.8rem; color:#64748b; font-weight:600; }
        .sa-details > summary:hover { color:#334155; }
        @media (max-width: 720px) { .sa-form { grid-template-columns:1fr; } }
    </style>
</head>
<body>
<?php $nav_active = 'site-settings'; $nav_user = $current; require __DIR__ . '/_nav.php'; ?>

<div class="dash-wrap">
    <?php $admin_tab = 'sso'; require __DIR__ . '/_admin_tabs.php'; ?>
    <h1 style="font-size:1.5rem;font-weight:700;margin:0 0 1rem">Connected Apps</h1>
    <p class="sa-help">
        A connected app lets people sign in with their <?= $e($site_name) ?> account instead of
        creating another one. The app sends the browser to <code>/connect.php</code>, the normal
        login (including verification and two-factor) runs here, and the browser returns to the app
        with a signed token carrying the username and nothing else. Passwords never leave this site.
    </p>

    <?php if ($flash): ?>
    <div class="sa-flash <?= $e($flash['type'] ?? 'success') ?>"><?= $e($flash['msg'] ?? '') ?></div>
    <?php endif; ?>

    <div class="sa-card">
        <h2>Registered apps</h2>
        <?php if (empty($apps)): ?>
            <p style="color:#94a3b8;font-size:.9rem;margin:0 0 1rem">No apps connected yet.</p>
        <?php else: ?>
        <table class="sa-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Slug (audience)</th>
                    <th>Base URL</th>
                    <th>Status</th>
                    <th>Created</th>
                    <th>Last sign-in</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($apps as $a): ?>
                <tr>
                    <td><?= $e($a['name']) ?></td>
                    <td><code><?= $e($a['slug']) ?></code></td>
                    <td><code><?= $e($a['base_url']) ?></code></td>
                    <td><span class="sa-pill <?= (int)$a['enabled'] ? 'on' : 'off' ?>"><?= (int)$a['enabled'] ? 'Enabled' : 'Disabled' ?></span></td>
                    <td><?= $e(sso_admin_fmt($a['created_at'], $local_tz)) ?></td>
                    <td><?= $e(sso_admin_fmt($a['last_used_at'], $local_tz)) ?></td>
                    <td style="text-align:right;white-space:nowrap">
                        <form method="post" style="margin:0;display:inline">
                            <input type="hidden" name="csrf_token" value="<?= $e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                            <button type="submit" class="sa-btn"><?= (int)$a['enabled'] ? 'Disable' : 'Enable' ?></button>
                        </form>
                        <form method="post" style="margin:0;display:inline" data-confirm="Remove <?= $e($a['name']) ?>? Sign-in links from it will stop working immediately." data-confirm-ok="Remove" data-confirm-danger="1">
                            <input type="hidden" name="csrf_token" value="<?= $e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                            <button type="submit" class="sa-btn danger">Remove</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <h2 style="margin-top:1.25rem">Add an app</h2>
        <form method="post" class="sa-form">
            <input type="hidden" name="csrf_token" value="<?= $e(csrf_token()) ?>">
            <input type="hidden" name="action" value="create">
            <div>
                <label for="saSlug">Slug</label>
                <input type="text" id="saSlug" name="slug" placeholder="finaltable" maxlength="32" pattern="[a-z0-9-]{2,32}" required>
            </div>
            <div>
                <label for="saName">Name</label>
                <input type="text" id="saName" name="name" placeholder="FinalTable" maxlength="80" required>
            </div>
            <div>
                <label for="saBase">Base URL</label>
                <input type="url" id="saBase" name="base_url" placeholder="https://play.example.com" maxlength="255" required>
            </div>
            <div><button type="submit" class="sa-btn primary" style="padding:.5rem 1rem">Add</button></div>
        </form>
        <p class="sa-help" style="margin:.6rem 0 0">
            The slug becomes the token's audience and the app's <code>GAMENIGHT_AUDIENCE</code>.
            The base URL is the only origin a token will ever be sent to: scheme, host and port must match exactly.
        </p>
    </div>

    <div class="sa-card">
        <h2>Signing key</h2>
        <p class="sa-help" style="margin-bottom:.75rem">
            Tokens are signed here with a private key that never leaves this server. An app checks them
            with the public half, which it fetches from this site by itself. There is nothing to copy and
            no file to edit.
        </p>

        <div class="sa-steps">
            <div class="sa-steps-head">Connecting an app</div>
            <ol>
                <li>Add it above: a slug, a name, and the address players use to reach it.</li>
                <li>
                    Open that app's own operator settings &mdash; in <strong>FinalTable</strong> that is the
                    <em>Operator</em> link in its lobby, behind its admin password &mdash; and give it this
                    address and the slug:
                    <div class="sa-kv">
                        <span>Address</span><code><?= $e($issuer) ?></code>
                        <span>Slug</span><code><?= $e(!empty($apps) ? $apps[0]['slug'] : 'finaltable') ?></code>
                    </div>
                </li>
                <li>
                    It fetches the key and shows its id. Check it reads
                    <code><?= $e($keys['kid']) ?></code>, and that is the whole job.
                </li>
            </ol>
        </div>

        <details class="sa-details">
            <summary>Setting it by hand instead</summary>
            <p class="sa-help" style="margin:.75rem 0 .5rem">
                Only for an app that cannot fetch the key itself, or a server being built from a script
                before it has a browser pointed at it. FinalTable reads these three from its <code>.env</code>
                at first boot and never again once it has been paired from its Operator page; applying them
                takes <code>docker compose up -d</code>, since <code>restart</code> does not re-read the file.
            </p>
            <textarea class="sa-pre" id="ssoEnv" rows="4" readonly>GAMENIGHT_URL=<?= $e($issuer) ?>

GAMENIGHT_AUDIENCE=<?= $e(!empty($apps) ? $apps[0]['slug'] : 'finaltable') ?>

GAMENIGHT_PUBLIC_KEY="<?= $e($env_pem) ?>"</textarea>
            <div class="sa-row">
                <button type="button" class="sa-btn" data-act="ssoCopy" data-a1="ssoEnv">Copy .env snippet</button>
            </div>
            <p class="sa-help" style="margin:1rem 0 .5rem">The key on its own, for anything else that asks for it:</p>
            <textarea class="sa-pre" id="ssoPubKey" rows="5" readonly><?= $e($keys['public_pem']) ?></textarea>
            <div class="sa-row">
                <button type="button" class="sa-btn" data-act="ssoCopy" data-a1="ssoPubKey">Copy public key</button>
            </div>
        </details>

        <div class="sa-warn">
            Regenerating the key invalidates every token in flight and breaks sign-in for every connected app
            until each one has the new public key.
            <form method="post" style="margin:.6rem 0 0" data-confirm="Regenerate the signing key? Every connected app will need the new public key before sign-in works again." data-confirm-ok="Regenerate" data-confirm-danger="1">
                <input type="hidden" name="csrf_token" value="<?= $e(csrf_token()) ?>">
                <input type="hidden" name="action" value="rotate">
                <button type="submit" class="sa-btn danger">Regenerate signing key</button>
            </form>
        </div>
    </div>
</div>

<?php require __DIR__ . '/_footer.php'; ?>
<script nonce="<?= csp_nonce() ?>">
window.ssoCopy = function (id) {
    var el = document.getElementById(id);
    if (!el) return;
    pkCopy(el.value).then(function (ok) {
        if (!ok) pkAlert('Could not copy. Select the text and copy it by hand.');
    });
};
</script>
</body>
</html>
