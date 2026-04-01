<?php
require_once __DIR__ . '/auth.php';

$current = require_login();
if ($current['role'] !== 'admin') {
    http_response_code(403);
    exit('Access denied.');
}

$db        = get_db();
$site_name = get_setting('site_name', 'Game Night');
$flash     = ['type' => '', 'msg' => ''];
$sms_result = null;

session_start_safe();
if (!empty($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
}

// ── POST ─────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => 'Invalid request token.'];
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'save_credentials') {
            set_setting('twilio_sid',   trim($_POST['twilio_sid']   ?? ''));
            set_setting('twilio_token', trim($_POST['twilio_token'] ?? ''));
            set_setting('twilio_from',  trim($_POST['twilio_from']  ?? ''));
            db_log_activity($current['id'], 'updated Twilio credentials');
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Credentials saved.'];
            header('Location: /SMSTesting.php');
            exit;
        }

        if ($action === 'send_test') {
            $to   = normalize_phone(trim($_POST['to']   ?? ''));
            $body = trim($_POST['body'] ?? '');

            if (!$to || !$body) {
                $flash = ['type' => 'error', 'msg' => 'Phone number and message are required.'];
            } else {
                $sid   = get_setting('twilio_sid');
                $token = get_setting('twilio_token');
                $from  = get_setting('twilio_from');

                if (!$sid || !$token || !$from) {
                    $flash = ['type' => 'error', 'msg' => 'Twilio credentials not configured.'];
                } else {
                    $url  = 'https://api.twilio.com/2010-04-01/Accounts/' . $sid . '/Messages.json';
                    $data = ['From' => $from, 'To' => $to, 'Body' => $body];

                    $ch = curl_init($url);
                    curl_setopt_array($ch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_POST           => true,
                        CURLOPT_POSTFIELDS     => http_build_query($data),
                        CURLOPT_USERPWD        => $sid . ':' . $token,
                    ]);
                    $response = curl_exec($ch);
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);

                    $json = json_decode($response, true);
                    $sms_result = [
                        'ok'      => $httpCode === 201,
                        'code'    => $httpCode,
                        'sid'     => $json['sid']     ?? null,
                        'status'  => $json['status']  ?? null,
                        'error'   => $json['message'] ?? null,
                        'to'      => $to,
                        'body'    => $body,
                    ];

                    if ($sms_result['ok']) {
                        db_log_activity($current['id'], "sent test SMS to $to");
                        $flash = ['type' => 'success', 'msg' => 'SMS sent! SID: ' . $sms_result['sid']];
                    } else {
                        $flash = ['type' => 'error', 'msg' => 'Send failed: ' . ($sms_result['error'] ?? "HTTP $httpCode")];
                    }
                }
            }
        }
    }
}

$token      = csrf_token();
$twilio_sid   = get_setting('twilio_sid');
$twilio_token = get_setting('twilio_token');
$twilio_from  = get_setting('twilio_from');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($site_name) ?></title>
    <link rel="stylesheet" href="/style.css">
    <style>
        .sms-grid { display:grid; grid-template-columns:1fr 1fr; gap:1.5rem; }
        @media (max-width:640px) { .sms-grid { grid-template-columns:1fr; } }
        .result-box { background:#f8fafc; border:1.5px solid #e2e8f0; border-radius:8px; padding:1rem; margin-top:1rem; font-size:.875rem; }
        .result-box .ok   { color:#166534; font-weight:700; }
        .result-box .fail { color:#dc2626; font-weight:700; }
        .result-row { display:flex; gap:.5rem; margin-top:.35rem; }
        .result-label { color:#94a3b8; min-width:80px; font-size:.8rem; }
        .cred-note { font-size:.78rem; color:#94a3b8; margin-top:.25rem; }
    </style>
</head>
<body>

<?php $nav_active = ''; require __DIR__ . '/_nav.php'; ?>

<div class="dash-wrap">

    <div class="dash-header">
        <h1>SMS Testing</h1>
        <p>Configure Twilio credentials and send test messages.</p>
    </div>

    <?php if ($flash['msg']): ?>
        <div class="alert alert-<?= $flash['type'] === 'error' ? 'error' : 'success' ?>" style="margin-bottom:1.5rem">
            <?= htmlspecialchars($flash['msg']) ?>
        </div>
    <?php endif; ?>

    <div class="sms-grid">

        <!-- Twilio Credentials -->
        <div class="card" style="max-width:100%">
            <h2>Twilio Credentials</h2>
            <p class="subtitle">Stored in site settings. Keep your auth token private.</p>
            <form method="post" action="/SMSTesting.php">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">
                <input type="hidden" name="action" value="save_credentials">

                <div class="form-group">
                    <label for="twilio_sid">Account SID</label>
                    <input type="text" id="twilio_sid" name="twilio_sid"
                           value="<?= htmlspecialchars($twilio_sid) ?>"
                           placeholder="ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
                           autocomplete="off">
                </div>
                <div class="form-group">
                    <label for="twilio_token">Auth Token</label>
                    <input type="password" id="twilio_token" name="twilio_token"
                           value="<?= htmlspecialchars($twilio_token) ?>"
                           placeholder="your_auth_token"
                           autocomplete="new-password">
                    <p class="cred-note">Token is stored as-is. Use environment variables in production.</p>
                </div>
                <div class="form-group">
                    <label for="twilio_from">From Number</label>
                    <input type="text" id="twilio_from" name="twilio_from"
                           value="<?= htmlspecialchars($twilio_from) ?>"
                           placeholder="+12015550123">
                    <p class="cred-note">Must be a Twilio-verified number or messaging service SID.</p>
                </div>

                <div style="display:flex;align-items:center;gap:.75rem;margin-top:.25rem">
                    <button type="submit" class="btn btn-primary">Save Credentials</button>
                    <?php if ($twilio_sid && $twilio_token && $twilio_from): ?>
                        <span style="color:#16a34a;font-size:.8rem;font-weight:600">&#10003; Configured</span>
                    <?php else: ?>
                        <span style="color:#dc2626;font-size:.8rem;font-weight:600">&#9679; Not configured</span>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- Send Test SMS -->
        <div class="card" style="max-width:100%">
            <h2>Send Test Message</h2>
            <p class="subtitle">Send a one-off SMS to any number to verify delivery.</p>
            <form method="post" action="/SMSTesting.php">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">
                <input type="hidden" name="action" value="send_test">

                <div class="form-group">
                    <label for="to">To (phone number)</label>
                    <input type="tel" id="to" name="to"
                           placeholder="+12015550199"
                           value="<?= htmlspecialchars($_POST['to'] ?? '') ?>"
                           required>
                </div>
                <div class="form-group">
                    <label for="body">Message</label>
                    <textarea id="body" name="body" rows="4"
                              style="width:100%;resize:vertical"
                              placeholder="Hello from Game Night!"
                              required><?= htmlspecialchars($_POST['body'] ?? 'Hello from ' . $site_name . '! This is a test message.') ?></textarea>
                </div>
                <button type="submit" class="btn btn-primary"
                        <?= (!$twilio_sid || !$twilio_token || !$twilio_from) ? 'disabled title="Configure credentials first"' : '' ?>>
                    Send SMS
                </button>
            </form>

            <?php if ($sms_result): ?>
            <div class="result-box">
                <div class="<?= $sms_result['ok'] ? 'ok' : 'fail' ?>">
                    <?= $sms_result['ok'] ? '&#10003; Sent successfully' : '&#10007; Send failed' ?>
                </div>
                <div class="result-row"><span class="result-label">HTTP</span> <?= (int)$sms_result['code'] ?></div>
                <?php if ($sms_result['sid']): ?>
                <div class="result-row"><span class="result-label">SID</span> <?= htmlspecialchars($sms_result['sid']) ?></div>
                <?php endif; ?>
                <?php if ($sms_result['status']): ?>
                <div class="result-row"><span class="result-label">Status</span> <?= htmlspecialchars($sms_result['status']) ?></div>
                <?php endif; ?>
                <?php if ($sms_result['error']): ?>
                <div class="result-row"><span class="result-label">Error</span> <span style="color:#dc2626"><?= htmlspecialchars($sms_result['error']) ?></span></div>
                <?php endif; ?>
                <div class="result-row"><span class="result-label">To</span> <?= htmlspecialchars($sms_result['to']) ?></div>
            </div>
            <?php endif; ?>
        </div>

    </div>

    <!-- Quick reference -->
    <div class="table-card" style="margin-top:1.5rem;max-width:620px">
        <h3>Twilio Quick Reference</h3>
        <table>
            <tbody>
                <tr><td style="color:#64748b;width:160px">Console</td><td><a href="https://console.twilio.com" target="_blank" rel="noopener">console.twilio.com</a></td></tr>
                <tr><td style="color:#64748b">Account SID</td><td>Found on Console dashboard, starts with <code>AC</code></td></tr>
                <tr><td style="color:#64748b">Auth Token</td><td>Found on Console dashboard (click to reveal)</td></tr>
                <tr><td style="color:#64748b">From Number</td><td>Buy a number under Phone Numbers &rsaquo; Manage</td></tr>
                <tr><td style="color:#64748b">Trial limits</td><td>Trial accounts can only send to verified numbers</td></tr>
            </tbody>
        </table>
    </div>

</div>

<footer>&copy; <?= (new DateTime('now', new DateTimeZone(get_setting('timezone', 'UTC'))))->format('Y') ?> <?= htmlspecialchars($site_name) ?> &nbsp;&mdash;&nbsp; <?= (new DateTime('now', new DateTimeZone(get_setting('timezone', 'UTC'))))->format('F j, Y g:i A') ?></footer>

</body>
</html>
