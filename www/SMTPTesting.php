<?php
require_once __DIR__ . '/auth.php';

$current = require_login();
if ($current['role'] !== 'admin') { http_response_code(403); exit('Access denied.'); }

$db        = get_db();
$site_name = get_setting('site_name', 'Game Night');
$flash     = ['type' => '', 'msg' => ''];
$result    = null;

session_start_safe();
if (!empty($_SESSION['flash'])) { $flash = $_SESSION['flash']; unset($_SESSION['flash']); }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_credentials') {
        set_setting('smtp_host',     trim($_POST['smtp_host']     ?? ''));
        set_setting('smtp_port',     trim($_POST['smtp_port']     ?? '587'));
        set_setting('smtp_username', trim($_POST['smtp_username'] ?? ''));
        set_setting('smtp_password', trim($_POST['smtp_password'] ?? ''));
        set_setting('smtp_from',     trim($_POST['smtp_from']     ?? ''));
        set_setting('smtp_from_name',trim($_POST['smtp_from_name']?? ''));
        db_log_activity($current['id'], 'updated SMTP credentials');
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'SMTP settings saved.'];
        header('Location: /SMTPTesting.php'); exit;
    }

    if ($action === 'send_test') {
        $to      = trim($_POST['to']      ?? '');
        $subject = trim($_POST['subject'] ?? 'Test Email');
        $body    = trim($_POST['body']    ?? '');

        if (!$to || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $flash = ['type' => 'error', 'msg' => 'Valid recipient email required.'];
        } else {
            require_once __DIR__ . '/mail.php';
            $err = send_email($to, $to, $subject, nl2br(htmlspecialchars($body)));
            if ($err === null) {
                $result = ['ok' => true];
                db_log_activity($current['id'], "sent test email to $to");
                $flash = ['type' => 'success', 'msg' => "Email sent to $to"];
            } else {
                $result = ['ok' => false, 'error' => $err];
                $flash  = ['type' => 'error', 'msg' => 'Send failed: ' . $err];
            }
        }
    }
}

$token     = csrf_token();
$smtp_host      = get_setting('smtp_host');
$smtp_port      = get_setting('smtp_port', '587');
$smtp_username  = get_setting('smtp_username');
$smtp_password  = get_setting('smtp_password');
$smtp_from      = get_setting('smtp_from');
$smtp_from_name = get_setting('smtp_from_name');
$configured     = $smtp_host && $smtp_username && $smtp_password && $smtp_from;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($site_name) ?></title>
    <link rel="stylesheet" href="/style.css">
    <style>
        .smtp-grid { display:grid; grid-template-columns:1fr 1fr; gap:1.5rem; }
        @media (max-width:640px) { .smtp-grid { grid-template-columns:1fr; } }
        .result-box { background:#f8fafc; border:1.5px solid #e2e8f0; border-radius:8px; padding:1rem; margin-top:1rem; font-size:.875rem; }
        .cred-note { font-size:.78rem; color:#94a3b8; margin-top:.25rem; }
    </style>
</head>
<body>
<?php $nav_active = ''; require __DIR__ . '/_nav.php'; ?>
<div class="dash-wrap">

    <div class="dash-header">
        <h1>SMTP Testing</h1>
        <p>Configure SMTP credentials and send test emails. Works with SendGrid, Gmail, or any SMTP relay.</p>
    </div>

    <?php if ($flash['msg']): ?>
        <div class="alert alert-<?= $flash['type'] === 'error' ? 'error' : 'success' ?>" style="margin-bottom:1.5rem">
            <?= htmlspecialchars($flash['msg']) ?>
        </div>
    <?php endif; ?>

    <div class="smtp-grid">

        <!-- Credentials -->
        <div class="card" style="max-width:100%">
            <h2>SMTP Settings</h2>
            <p class="subtitle">Twilio SendGrid: host <code>smtp.sendgrid.net</code>, port <code>587</code>, user <code>apikey</code>.</p>
            <form method="post" action="/SMTPTesting.php">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">
                <input type="hidden" name="action" value="save_credentials">
                <div class="form-group">
                    <label>SMTP Host</label>
                    <input type="text" name="smtp_host" value="<?= htmlspecialchars($smtp_host) ?>" placeholder="smtp.sendgrid.net">
                </div>
                <div class="form-group">
                    <label>Port</label>
                    <input type="number" name="smtp_port" value="<?= htmlspecialchars($smtp_port) ?>" placeholder="587">
                    <p class="cred-note">587 = TLS (recommended) &nbsp;&bull;&nbsp; 465 = SSL</p>
                </div>
                <div class="form-group">
                    <label>Username</label>
                    <input type="text" name="smtp_username" value="<?= htmlspecialchars($smtp_username) ?>" placeholder="apikey" autocomplete="off">
                    <p class="cred-note">SendGrid: literally the word <code>apikey</code></p>
                </div>
                <div class="form-group">
                    <label>Password / API Key</label>
                    <input type="password" name="smtp_password" value="<?= htmlspecialchars($smtp_password) ?>" autocomplete="new-password">
                    <p class="cred-note">SendGrid: your API key starting with <code>SG.</code></p>
                </div>
                <div class="form-group">
                    <label>From Address</label>
                    <input type="email" name="smtp_from" value="<?= htmlspecialchars($smtp_from) ?>" placeholder="noreply@yourdomain.com">
                </div>
                <div class="form-group">
                    <label>From Name</label>
                    <input type="text" name="smtp_from_name" value="<?= htmlspecialchars($smtp_from_name) ?>" placeholder="<?= htmlspecialchars($site_name) ?>">
                </div>
                <div style="display:flex;align-items:center;gap:.75rem">
                    <button type="submit" class="btn btn-primary">Save Settings</button>
                    <?php if ($configured): ?>
                        <span style="color:#16a34a;font-size:.8rem;font-weight:600">&#10003; Configured</span>
                    <?php else: ?>
                        <span style="color:#dc2626;font-size:.8rem;font-weight:600">&#9679; Not configured</span>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- Send Test -->
        <div class="card" style="max-width:100%">
            <h2>Send Test Email</h2>
            <p class="subtitle">Send a test message to verify your SMTP settings are working.</p>
            <form method="post" action="/SMTPTesting.php">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">
                <input type="hidden" name="action" value="send_test">
                <div class="form-group">
                    <label>To</label>
                    <input type="email" name="to" value="<?= htmlspecialchars($_POST['to'] ?? $current['email'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                    <label>Subject</label>
                    <input type="text" name="subject" value="<?= htmlspecialchars($_POST['subject'] ?? 'Test Email from ' . $site_name) ?>" required>
                </div>
                <div class="form-group">
                    <label>Message</label>
                    <textarea name="body" rows="5" style="width:100%;resize:vertical" required><?= htmlspecialchars($_POST['body'] ?? 'Hello, this is a test email from ' . $site_name . '.') ?></textarea>
                </div>
                <button type="submit" class="btn btn-primary" <?= !$configured ? 'disabled title="Configure SMTP first"' : '' ?>>
                    Send Email
                </button>
            </form>

            <?php if ($result): ?>
            <div class="result-box">
                <?php if ($result['ok']): ?>
                    <span style="color:#166534;font-weight:700">&#10003; Sent successfully</span>
                <?php else: ?>
                    <span style="color:#dc2626;font-weight:700">&#10007; Failed</span>
                    <div style="margin-top:.5rem;color:#dc2626;font-size:.85rem"><?= htmlspecialchars($result['error']) ?></div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

    </div>

    <!-- Quick ref -->
    <div class="table-card" style="margin-top:1.5rem;max-width:620px">
        <h3>SendGrid Quick Reference</h3>
        <table><tbody>
            <tr><td style="color:#64748b;width:160px">Console</td><td><a href="https://app.sendgrid.com" target="_blank" rel="noopener">app.sendgrid.com</a></td></tr>
            <tr><td style="color:#64748b">SMTP Host</td><td><code>smtp.sendgrid.net</code></td></tr>
            <tr><td style="color:#64748b">Port</td><td>587 (TLS) or 465 (SSL)</td></tr>
            <tr><td style="color:#64748b">Username</td><td><code>apikey</code> (literal)</td></tr>
            <tr><td style="color:#64748b">Password</td><td>API key from Settings &rsaquo; API Keys (starts with <code>SG.</code>)</td></tr>
            <tr><td style="color:#64748b">From domain</td><td>Must be verified under Settings &rsaquo; Sender Authentication</td></tr>
        </tbody></table>
    </div>

</div>
<footer>&copy; <?= (new DateTime('now', new DateTimeZone(get_setting('timezone', 'UTC'))))->format('Y') ?> <?= htmlspecialchars($site_name) ?> &nbsp;&mdash;&nbsp; <?= (new DateTime('now', new DateTimeZone(get_setting('timezone', 'UTC'))))->format('F j, Y g:i A') ?></footer>
</body>
</html>
