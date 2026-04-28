<?php
/**
 * Admin: cross-league audit view of API keys.
 *
 * League owners mint and manage their own keys via the API tab on /league.php.
 * This page exists so site admins can see every key across every league at
 * once and revoke any of them in case of abuse. No minting here — issuance
 * is owner-driven so it stays scoped to the league that's exposing data.
 */
require_once __DIR__ . '/auth.php';

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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Invalid request token.'];
        header('Location: /admin_api_keys.php');
        exit;
    }
    if (($_POST['action'] ?? '') === 'revoke') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $db->prepare('UPDATE api_keys SET revoked_at = CURRENT_TIMESTAMP WHERE id = ? AND revoked_at IS NULL')
               ->execute([$id]);
            db_log_activity((int)$current['id'], "admin revoked API key id=$id");
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'API key revoked.'];
        }
        header('Location: /admin_api_keys.php');
        exit;
    }
}

$keys = $db->query(
    "SELECT k.id, k.label, k.league_id, k.created_at, k.last_used_at, k.revoked_at,
            l.name AS league_name
     FROM api_keys k
     LEFT JOIN leagues l ON l.id = k.league_id
     ORDER BY k.revoked_at IS NOT NULL, k.created_at DESC"
)->fetchAll();

$local_tz = new DateTimeZone(get_setting('timezone', 'UTC'));
function api_keys_admin_fmt(?string $utc_dt, DateTimeZone $local_tz): string {
    if (!$utc_dt) return '—';
    try {
        return (new DateTime($utc_dt, new DateTimeZone('UTC')))
            ->setTimezone($local_tz)->format('M j, Y g:i A');
    } catch (Exception $e) { return $utc_dt; }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>API Keys — <?= htmlspecialchars($site_name) ?></title>
    <link rel="stylesheet" href="/style.css">
    <style>
        .ak-wrap { max-width: 960px; margin: 1.5rem auto; padding: 0 1rem; }
        .ak-card { background:#fff; border:1.5px solid #e2e8f0; border-radius:10px; padding:1.25rem; margin-bottom:1rem; }
        .ak-table { width:100%; border-collapse:collapse; font-size:.875rem; }
        .ak-table th, .ak-table td { padding:.55rem .6rem; border-bottom:1px solid #f1f5f9; text-align:left; }
        .ak-table th { font-size:.7rem; text-transform:uppercase; letter-spacing:.05em; color:#94a3b8; font-weight:700; }
        .ak-pill { display:inline-block; font-size:.7rem; font-weight:700; padding:.1rem .5rem; border-radius:999px; }
        .ak-pill.active { background:#dcfce7; color:#166534; }
        .ak-pill.revoked { background:#fee2e2; color:#991b1b; }
        .ak-btn { background:#dc2626; color:#fff; border:none; border-radius:6px; padding:.3rem .8rem; font-size:.78rem; font-weight:600; cursor:pointer; }
        .ak-flash { border:1.5px solid; border-radius:10px; padding:1rem 1.25rem; margin-bottom:1rem; font-size:.9rem; }
        .ak-flash.success { background:#f0fdf4; border-color:#86efac; color:#166534; }
        .ak-flash.error   { background:#fef2f2; border-color:#fca5a5; color:#991b1b; }
        .ak-revoked-row { opacity:.55; }
    </style>
</head>
<body>

<?php $nav_active = 'site-settings'; $nav_user = $current; require __DIR__ . '/_nav.php'; ?>

<div class="ak-wrap">
    <h1 style="font-size:1.5rem;font-weight:700;margin:0 0 1rem">API Keys (audit view)</h1>
    <p style="color:#64748b;margin:0 0 1.25rem;font-size:.9rem;line-height:1.55">
        League owners mint and manage their own keys from the API tab on each league page.
        This audit view shows every key across every league — useful when investigating
        abuse or rotating credentials site-wide. You can revoke any key here.
    </p>

    <?php if ($flash): ?>
    <div class="ak-flash <?= htmlspecialchars($flash['type'] ?? 'success') ?>"><?= htmlspecialchars($flash['msg'] ?? '') ?></div>
    <?php endif; ?>

    <div class="ak-card">
        <?php if (empty($keys)): ?>
            <p style="color:#94a3b8;font-size:.9rem;margin:0">No keys exist yet.</p>
        <?php else: ?>
        <table class="ak-table">
            <thead>
                <tr>
                    <th>Label</th>
                    <th>League</th>
                    <th>Status</th>
                    <th>Created</th>
                    <th>Last used</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($keys as $k): $revoked = !empty($k['revoked_at']); ?>
                <tr<?= $revoked ? ' class="ak-revoked-row"' : '' ?>>
                    <td><?= htmlspecialchars($k['label']) ?></td>
                    <td>
                        <?php if (!empty($k['league_name'])): ?>
                            <a href="/league.php?id=<?= (int)$k['league_id'] ?>&tab=api" style="color:#2563eb;text-decoration:none"><?= htmlspecialchars($k['league_name']) ?></a>
                        <?php else: ?>
                            <span style="color:#94a3b8">league <?= (int)$k['league_id'] ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($revoked): ?>
                            <span class="ak-pill revoked">Revoked</span>
                        <?php else: ?>
                            <span class="ak-pill active">Active</span>
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars(api_keys_admin_fmt($k['created_at'], $local_tz)) ?></td>
                    <td><?= htmlspecialchars(api_keys_admin_fmt($k['last_used_at'], $local_tz)) ?></td>
                    <td style="text-align:right">
                        <?php if (!$revoked): ?>
                        <form method="post" style="margin:0;display:inline" onsubmit="return confirm('Revoke this API key? Consumers using it will start getting 401 immediately.')">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                            <input type="hidden" name="action" value="revoke">
                            <input type="hidden" name="id" value="<?= (int)$k['id'] ?>">
                            <button type="submit" class="ak-btn">Revoke</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/_footer.php'; ?>
</body>
</html>
