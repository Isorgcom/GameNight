<?php
require_once __DIR__ . '/db.php';
$site_name = get_setting('site_name', 'Game Night');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Terms &amp; Conditions &mdash; <?= htmlspecialchars($site_name) ?></title>
    <link rel="stylesheet" href="/style.css">
</head>
<body>
<?php $nav_active = ''; require __DIR__ . '/_nav.php'; ?>

<div class="card-wrap">
    <div class="card" style="max-width:720px">
        <h2>Terms &amp; Conditions</h2>
        <p class="subtitle">Last updated: <?= date('F j, Y') ?></p>

        <h3 style="margin-top:1.5rem">1. Acceptance of Terms</h3>
        <p>By creating an account or using <?= htmlspecialchars($site_name) ?>, you agree to these Terms &amp; Conditions. If you do not agree, please do not use the site.</p>

        <h3 style="margin-top:1.5rem">2. User Accounts</h3>
        <ul>
            <li>You are responsible for maintaining the confidentiality of your password.</li>
            <li>You must provide accurate information when registering.</li>
            <li>You may not share your account or register on behalf of another person without their consent.</li>
            <li>We reserve the right to suspend or terminate accounts that violate these terms.</li>
        </ul>

        <h3 style="margin-top:1.5rem">3. Acceptable Use</h3>
        <p>You agree not to:</p>
        <ul>
            <li>Post content that is abusive, harassing, defamatory, or illegal.</li>
            <li>Attempt to gain unauthorized access to other accounts or the site's systems.</li>
            <li>Use the site in any way that could damage, disable, or impair it.</li>
        </ul>

        <h3 style="margin-top:1.5rem">4. Content</h3>
        <p>You retain ownership of content you post (comments, RSVPs, etc.). By posting, you grant <?= htmlspecialchars($site_name) ?> a non-exclusive license to display that content to other authorized users of the site.</p>
        <p>Administrators may remove content that violates these terms at any time.</p>

        <h3 style="margin-top:1.5rem">5. SMS &amp; Email Notifications</h3>
        <p>If you provide a phone number or email address and opt in to notifications, you consent to receive transactional messages (event reminders, RSVP confirmations) from <?= htmlspecialchars($site_name) ?>. Message and data rates may apply. You can opt out by updating your notification preferences in account settings.</p>

        <h3 style="margin-top:1.5rem">6. Disclaimers</h3>
        <p><?= htmlspecialchars($site_name) ?> is provided "as is" without warranties of any kind. We do not guarantee uninterrupted or error-free operation.</p>

        <h3 style="margin-top:1.5rem">7. Limitation of Liability</h3>
        <p>To the maximum extent permitted by law, the site operators shall not be liable for any indirect, incidental, or consequential damages arising from your use of the site.</p>

        <h3 style="margin-top:1.5rem">8. Changes to These Terms</h3>
        <p>We may update these terms from time to time. Continued use of the site after changes constitutes your acceptance of the revised terms.</p>

        <h3 style="margin-top:1.5rem">9. Contact</h3>
        <p>Questions about these terms? Contact a site administrator.</p>

        <p style="margin-top:2rem">
            <a href="/privacy.php">&rarr; View Privacy Policy</a>
        </p>
    </div>
</div>

<?php require __DIR__ . '/_footer.php'; ?>
</body>
</html>
