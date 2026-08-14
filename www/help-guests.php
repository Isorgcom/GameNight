<?php
require_once __DIR__ . '/auth.php';

// Without this the nav partial sees a GUEST even when someone is signed in
// ($user is only set inside require_login, which a public page never calls) —
// and with landing-page mode on, a guest gets no nav at all.
$current = current_user();

$site_name   = get_setting('site_name', 'Game Night');
$nav_active  = 'help';
$allow_reg   = get_setting('allow_registration', '1') === '1';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Guest Guide &mdash; <?= htmlspecialchars($site_name) ?></title>
    <?php render_seo_meta('Guest Guide', 'Got an invite to a game night? How to RSVP in one click and check in at the door, no account required.', 'help-guests.php'); ?>
    <link rel="stylesheet" href="/style.css?v=<?= htmlspecialchars(APP_VERSION . '.' . (@filemtime(__DIR__ . '/style.css') ?: 0)) ?>">
    <style>
        .help-wrap { max-width: 760px; margin: 2rem auto 4rem; padding: 0 1.5rem; }
        .help-wrap h1 { font-size: 2rem; margin-bottom: .5rem; }
        .help-wrap .subtitle { color: #64748b; margin-bottom: 2.5rem; font-size: 1.05rem; }
        .help-step { margin-bottom: 2.5rem; }
        .help-step h2 { font-size: 1.35rem; margin: 0 0 .75rem; display: flex; align-items: center; gap: .6rem; }
        .help-step .step-num {
            display: inline-flex; align-items: center; justify-content: center;
            width: 32px; height: 32px; border-radius: 50%;
            background: var(--accent, #2563eb); color: #fff;
            font-size: .95rem; font-weight: 600; flex-shrink: 0;
        }
        .help-step p { margin: .5rem 0; line-height: 1.6; color: #334155; }
        .help-step ul { margin: .5rem 0 .5rem 1.25rem; line-height: 1.6; color: #334155; }
        .help-step .hint {
            background: #f1f5f9; border-left: 3px solid #94a3b8;
            padding: .75rem 1rem; margin: .75rem 0; font-size: .92rem; color: #475569;
            border-radius: 4px;
        }
        .help-step img.help-shot {
            max-width: 100%; height: auto; border: 1px solid #e2e8f0; border-radius: 6px;
            margin: .75rem 0; display: block;
        }
        .help-cta {
            text-align: center; padding: 2.5rem 1rem;
            background: #f8fafc; border-radius: 8px; margin-top: 2rem;
        }
        .help-cta p { color: #475569; margin-bottom: 1.25rem; }
        .help-back { display: inline-block; margin-bottom: 1rem; color: #64748b; text-decoration: none; font-size: .9rem; }
        .help-back:hover { color: #2563eb; }
    </style>
</head>
<body>
<?php require __DIR__ . '/_nav.php'; ?>

<div class="help-wrap">
    <a href="/" class="help-back">&larr; Back to home</a>
    <h1>Guest Guide</h1>
    <p class="subtitle">Got an invite? Here's everything you need to know &mdash; no account required.</p>

    <div class="help-step">
        <h2><span class="step-num">1</span> You got an invite &mdash; what now?</h2>
        <p>When a host invites you, you'll get a message by email, text, or WhatsApp with a link. Tap the link and you'll see the event details and a quick RSVP button.</p>
        <img class="help-shot" src="/img/help/rsvp-page.png" alt="RSVP confirmation page">
        <div class="hint">No password, no sign-up, no app to download. The link itself is your ticket in.</div>
    </div>

    <div class="help-step">
        <h2><span class="step-num">2</span> Tap Yes, No, or Maybe</h2>
        <p>One tap and you're done. The host sees your response right away, and you'll get a reminder closer to event day.</p>
        <p>Changed your mind? Click the link again and pick a different answer &mdash; the same link works for updates too.</p>
        <div class="hint">Each invite link can be reused several times. After that, you'll be asked to create a quick account if you want to keep changing your RSVP.</div>
    </div>

    <div class="help-step">
        <h2><span class="step-num">3</span> Walking in without an invite?</h2>
        <p>Some events have a <strong>walk-in QR code</strong> at the door. Scan it with your phone camera, enter your name and a contact method, and you're checked in. The site can even assign you a table and seat automatically.</p>
        <img class="help-shot" src="/img/help/walkin-qr.png" alt="Walk-in registration after scanning the QR code">
        <div class="hint">Some hosts review walk-ins before adding them to the player list, so you might see a "pending approval" message until the host waves you in.</div>
    </div>

    <div class="help-step">
        <h2><span class="step-num">4</span> Want the full picture?</h2>
        <p>You can use everything above without ever creating an account. But if you want to:</p>
        <ul>
            <li>See who else is coming</li>
            <li>Comment on event announcements and posts</li>
            <li>Manage all your invites in one place</li>
            <li>Pick how you want to be contacted (email vs SMS vs WhatsApp)</li>
        </ul>
        <p>&hellip;then sign up for a free account. It takes about thirty seconds.</p>
        <img class="help-shot" src="/img/help/register.png" alt="Account signup form">
    </div>

    <div class="help-cta">
        <p>Want a free account?</p>
        <div class="cta-group">
            <?php if ($allow_reg && !current_user()): ?>
            <a href="/register.php" class="btn btn-primary" style="padding:.65rem 2rem">Sign Up Free</a>
            <a href="/login.php" class="btn btn-outline" style="padding:.65rem 2rem">Sign In</a>
            <?php elseif (!current_user()): ?>
            <a href="/login.php" class="btn btn-primary" style="padding:.65rem 2rem">Sign In</a>
            <?php else: ?>
            <a href="/" class="btn btn-primary" style="padding:.65rem 2rem">Go to Home</a>
            <?php endif; ?>
            <a href="/help-hosts.php" class="btn btn-outline" style="padding:.65rem 2rem">Hosting? Read the Host Guide</a>
        </div>
    </div>
</div>

<?php require __DIR__ . '/_footer.php'; ?>
</body>
</html>
