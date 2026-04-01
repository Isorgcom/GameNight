<?php
/**
 * Mail helper — requires auth.php / db.php to already be loaded.
 * Usage:
 *   $err = send_email('to@example.com', 'To Name', 'Subject', '<p>HTML body</p>');
 *   if ($err) { /* handle error *\/ }
 *
 * SMTP settings are read from PHP constants defined in /var/config/config.php.
 * If a constant is not defined, the value falls back to the database (site_settings table).
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception as MailException;

require_once __DIR__ . '/vendor/phpmailer/Exception.php';
require_once __DIR__ . '/vendor/phpmailer/PHPMailer.php';
require_once __DIR__ . '/vendor/phpmailer/SMTP.php';

/**
 * Returns true if every SMTP constant is defined in config.php.
 */
function smtp_from_config(): bool {
    return defined('SMTP_HOST') && defined('SMTP_FROM');
}

/**
 * Send an email. Returns null on success, error string on failure.
 */
function send_email(string $toAddress, string $toName, string $subject, string $htmlBody): ?string {
    // Prefer config.php constants; fall back to database values.
    $host     = defined('SMTP_HOST')       ? SMTP_HOST       : get_setting('smtp_host', '');
    $port     = defined('SMTP_PORT')       ? (int)SMTP_PORT  : (int)get_setting('smtp_port', '587');
    $user     = defined('SMTP_USER')       ? SMTP_USER       : get_setting('smtp_username', '');
    $pass     = defined('SMTP_PASS')       ? SMTP_PASS       : get_setting('smtp_password', '');
    $from     = defined('SMTP_FROM')       ? SMTP_FROM       : get_setting('smtp_from', '');
    $fromName = defined('SMTP_FROM_NAME')  ? SMTP_FROM_NAME  : get_setting('smtp_from_name', get_setting('site_name', 'App'));
    $enc      = defined('SMTP_ENCRYPTION') ? SMTP_ENCRYPTION : get_setting('smtp_encryption', 'tls');

    if ($host === '' || $from === '') {
        return 'SMTP is not configured. Define SMTP_HOST and SMTP_FROM in config.php, or set them in Site Settings → Email.';
    }

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host     = $host;
        $mail->Port     = $port;
        $mail->SMTPAuth = $user !== '';
        if ($user !== '') {
            $mail->Username = $user;
            $mail->Password = $pass;
        }
        if ($enc === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($enc === 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mail->SMTPAutoTLS = false;
            $mail->SMTPSecure  = '';
        }

        $mail->setFrom($from, $fromName);
        $mail->addAddress($toAddress, $toName);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->AltBody = strip_tags($htmlBody);

        $mail->send();
        return null;
    } catch (MailException $e) {
        return $mail->ErrorInfo;
    }
}
