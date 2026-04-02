<?php
require_once __DIR__ . '/db.php';
$site_name = get_setting('site_name', 'Game Night');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Privacy Policy &mdash; <?= htmlspecialchars($site_name) ?></title>
    <link rel="stylesheet" href="/style.css">
</head>
<body>
<?php $nav_active = ''; require __DIR__ . '/_nav.php'; ?>

<div class="card-wrap">
    <div class="card" style="max-width:720px">
        <h2>Privacy Policy</h2>
        <p class="subtitle">Last updated: <?= date('F j, Y') ?></p>

        <h3 style="margin-top:1.5rem">1. Information We Collect</h3>
        <p>When you create an account or use <?= htmlspecialchars($site_name) ?>, we may collect:</p>
        <ul>
            <li><strong>Account information</strong> — username, email address, and optionally a phone number.</li>
            <li><strong>Usage data</strong> — pages visited, actions taken (e.g., RSVPs, posts), and your IP address, stored in activity logs.</li>
            <li><strong>Communications</strong> — if you opt in to SMS or email notifications, we store records of messages sent to you.</li>
        </ul>

        <h3 style="margin-top:1.5rem">2. How We Use Your Information</h3>
        <p>We use the information we collect to:</p>
        <ul>
            <li>Operate and improve the site (event management, posts, calendar).</li>
            <li>Send you event reminders or RSVP confirmations if you have opted in.</li>
            <li>Maintain security and investigate abuse.</li>
        </ul>

        <h3 style="margin-top:1.5rem">3. Data Sharing</h3>
        <p>We do <strong>not</strong> sell or share your personal information with third parties for marketing purposes. Your data may be shared only:</p>
        <ul>
            <li>With SMS/email delivery providers (e.g., Twilio, Telnyx) solely to deliver messages you have requested.</li>
            <li>When required by law or to protect the rights and safety of our users.</li>
        </ul>

        <h3 style="margin-top:1.5rem">4. Data Retention</h3>
        <p>Your account data is retained while your account is active. You may request deletion of your account and associated data by contacting an administrator.</p>

        <h3 style="margin-top:1.5rem">5. Cookies &amp; Sessions</h3>
        <p>We use a single session cookie to keep you signed in. No third-party tracking cookies are used.</p>

        <h3 style="margin-top:1.5rem">6. Security</h3>
        <p>Passwords are stored using strong one-way hashing (bcrypt). We use HTTPS to encrypt data in transit. While we take reasonable precautions, no system is completely secure.</p>

        <h3 style="margin-top:1.5rem">7. Changes to This Policy</h3>
        <p>We may update this policy from time to time. Continued use of the site after changes constitutes acceptance of the updated policy.</p>

        <h3 style="margin-top:1.5rem">8. Contact</h3>
        <p>Questions about this policy? Contact a site administrator.</p>

        <p style="margin-top:2rem">
            <a href="/terms.php">&rarr; View Terms &amp; Conditions</a>
        </p>
    </div>
</div>

<?php require __DIR__ . '/_footer.php'; ?>
</body>
</html>
