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

// How many times to attempt a send before giving up, and the base backoff
// (seconds) between attempts. Gmail's SMTP relay intermittently drops the
// STARTTLS handshake ("Could not connect to SMTP host ... Called QUIT without
// being connected"); when a cron reminder batch hits one of these blips every
// recipient in that run used to fail silently with no retry. A couple of
// retries with a short backoff recovers the vast majority of these.
const SMTP_MAX_ATTEMPTS = 3;
const SMTP_RETRY_BASE_SECONDS = 1;

/**
 * Heuristic: is this PHPMailer error a transient connection/handshake failure
 * worth retrying, versus a permanent error (bad credentials, rejected
 * recipient) where retrying would only hammer the server?
 */
function _smtp_error_is_transient(?string $err): bool {
    if ($err === null || $err === '') {
        return false;
    }
    // Don't retry authentication problems — the credentials won't fix themselves.
    if (stripos($err, 'authenticate') !== false) {
        return false;
    }
    $transient = [
        'Could not connect to SMTP host',
        'STARTTLS',
        'Called QUIT without being connected',
        'SMTP connect() failed',
        'timed out',
        'timeout',
        'Connection reset',
        'Connection refused',
        'Temporary System Problem',
        'SMTP code: 4',   // 4xx = transient per RFC 5321
    ];
    foreach ($transient as $needle) {
        if (stripos($err, $needle) !== false) {
            return true;
        }
    }
    return false;
}

/**
 * Heuristic: is this a provider *quota / rate-limit* rejection rather than a
 * connection blip? e.g. Gmail/Workspace "Daily SMTP relay limit exceeded",
 * SES "Daily message quota exceeded", or generic "rate limit"/"throttled"/
 * "try again later". These are temporary but on a much longer clock — the daily
 * window only frees up after hours — so they are retried on a stretched backoff
 * (see _email_queue_backoff_minutes) instead of being dropped as permanent.
 */
function _smtp_error_is_quota(?string $err): bool {
    if ($err === null || $err === '') {
        return false;
    }
    $needles = [
        'limit exceeded', 'sending limit', 'rate limit', 'rate-limit',
        'quota exceeded', 'throttl', 'too many', 'try again later',
    ];
    foreach ($needles as $needle) {
        if (stripos($err, $needle) !== false) {
            return true;
        }
    }
    return false;
}

/** A failure worth parking in the retry queue: a transient blip OR a quota/rate cap. */
function _smtp_error_is_retryable(?string $err): bool {
    return _smtp_error_is_transient($err) || _smtp_error_is_quota($err);
}

// Persistent retry queue. Inline retries (above) only cover sub-second blips
// within a single request; a relay outage lasting minutes still loses the mail.
// When inline retries are exhausted on a *transient* error, the message is
// parked in email_retry_queue and cron.php re-attempts it on a backoff until it
// either delivers or exhausts EMAIL_QUEUE_MAX_ATTEMPTS.
const EMAIL_QUEUE_MAX_ATTEMPTS = 6;

/**
 * Minutes to wait before the Nth queued retry (1-indexed). Transient blips use a
 * short, roughly exponential schedule (~6 hours total). Quota/rate-cap failures
 * use a stretched schedule whose attempts span well past 24 hours, so retries
 * land after the provider's rolling daily window has reset instead of burning
 * every attempt while it is still exhausted.
 */
function _email_queue_backoff_minutes(int $attemptNumber, bool $isQuota = false): int {
    // Quota:    1h, 4h, 8h, 12h, 24h, 24h  → attempts at ~1h/5h/13h/25h/49h/73h
    // Transient: 5m,15m,30m, 1h,  2h,  4h
    $schedule = $isQuota ? [60, 240, 480, 720, 1440, 1440] : [5, 15, 30, 60, 120, 240];
    $i = max(0, min($attemptNumber - 1, count($schedule) - 1));
    return $schedule[$i];
}

/**
 * Low-level send: gather config and make up to SMTP_MAX_ATTEMPTS inline attempts.
 * Returns null on success, or the error string. Does NOT log or enqueue — that
 * is the caller's job, so both the live path and the queue drain can share it.
 */
function _email_attempt(string $toAddress, string $toName, string $subject, string $htmlBody): ?string {
    // Prefer config.php constants; fall back to database values.
    $host     = defined('SMTP_HOST')       ? SMTP_HOST       : get_setting('smtp_host', '');
    $port     = defined('SMTP_PORT')       ? (int)SMTP_PORT  : (int)get_setting('smtp_port', '587');
    $user     = defined('SMTP_USER')       ? SMTP_USER       : get_setting('smtp_user', '');
    $pass     = defined('SMTP_PASS')       ? SMTP_PASS       : get_setting('smtp_pass', '');
    $from     = defined('SMTP_FROM')       ? SMTP_FROM       : get_setting('smtp_from', '');
    $fromName = defined('SMTP_FROM_NAME')  ? SMTP_FROM_NAME  : get_setting('smtp_from_name', get_setting('site_name', 'App'));
    $enc      = defined('SMTP_ENCRYPTION') ? SMTP_ENCRYPTION : get_setting('smtp_encryption', 'tls');

    if ($host === '' || $from === '') {
        return 'SMTP is not configured. Define SMTP_HOST and SMTP_FROM in config.php, or set them in Site Settings → Email.';
    }

    $lastError = null;
    for ($attempt = 1; $attempt <= SMTP_MAX_ATTEMPTS; $attempt++) {
        // Build a fresh PHPMailer each attempt — a failed handshake can leave the
        // connection in an unusable state, so we never reuse it.
        $mail = new PHPMailer(true);
        try {
            // PHPMailer defaults to ISO-8859-1; without this, any UTF-8 character in a
            // subject or body (accents, dashes, emoji in event titles) renders as
            // mojibake like "â€”" in most clients.
            $mail->CharSet = 'UTF-8';
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
            $lastError = $mail->ErrorInfo;
            if ($attempt < SMTP_MAX_ATTEMPTS && _smtp_error_is_transient($lastError)) {
                sleep(SMTP_RETRY_BASE_SECONDS * $attempt);
                continue;
            }
            break;
        }
    }
    return $lastError;
}

/**
 * Send an email. Returns null on success, error string on failure.
 *
 * Transient SMTP connection failures are retried up to SMTP_MAX_ATTEMPTS times
 * inline with a short linear backoff. If those are exhausted and the failure is
 * still transient, the message is parked in the persistent retry queue (drained
 * by cron) so a longer relay outage doesn't silently drop it — unless
 * $queueOnFailure is false (e.g. interactive "send test email", where a
 * background retry would be misleading). Permanent failures (auth, rejected
 * recipient) are never retried or queued.
 */
function send_email(string $toAddress, string $toName, string $subject, string $htmlBody, bool $queueOnFailure = true): ?string {
    $err = _email_attempt($toAddress, $toName, $subject, $htmlBody);
    if ($err === null) {
        _log_email($toAddress, $subject, 'sent', null);
        return null;
    }

    if ($queueOnFailure && _smtp_error_is_retryable($err) && _enqueue_email_retry($toAddress, $toName, $subject, $htmlBody, $err)) {
        // Parked for background retry — record as 'queued', not 'failed', so the
        // notification history reflects that delivery is still pending.
        _log_email($toAddress, $subject, 'queued', $err);
    } else {
        _log_email($toAddress, $subject, 'failed', $err);
    }
    return $err;
}

/**
 * Park a failed-but-transient send in the retry queue. Returns true if it was
 * stored (so the caller can log 'queued' vs 'failed'). First retry is due after
 * one backoff interval.
 */
function _enqueue_email_retry(string $toAddress, string $toName, string $subject, string $htmlBody, ?string $error): bool {
    try {
        $delay = _email_queue_backoff_minutes(1, _smtp_error_is_quota($error));
        get_db()->prepare(
            "INSERT INTO email_retry_queue (to_address, to_name, subject, body, attempts, last_error, next_attempt_at)
             VALUES (?, ?, ?, ?, 0, ?, datetime('now', ? || ' minutes'))"
        )->execute([$toAddress, $toName, $subject, $htmlBody, $error, '+' . $delay]);
        return true;
    } catch (Exception $e) {
        error_log('email_retry_queue enqueue failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Drain due rows from the email retry queue. Called from cron.php. Returns
 * ['sent' => int, 'failed' => int, 'requeued' => int] for logging.
 */
function process_email_retry_queue(PDO $db, int $limit = 50): array {
    $sent = 0; $failed = 0; $requeued = 0;
    try {
        $rows = $db->prepare(
            "SELECT id, to_address, to_name, subject, body, attempts
             FROM email_retry_queue
             WHERE next_attempt_at <= CURRENT_TIMESTAMP
             ORDER BY next_attempt_at, id LIMIT ?"
        );
        $rows->execute([$limit]);
        $rows = $rows->fetchAll();
    } catch (Throwable $e) {
        return ['sent' => 0, 'failed' => 0, 'requeued' => 0];
    }

    foreach ($rows as $row) {
        $id = (int)$row['id'];
        // Atomic claim: bump attempts and tentatively push next_attempt_at an hour
        // out. A concurrent/overlapping cron run matches zero rows and skips, so
        // no message is sent twice.
        $claim = $db->prepare(
            "UPDATE email_retry_queue
             SET attempts = attempts + 1, next_attempt_at = datetime('now', '+1 hour')
             WHERE id = ? AND next_attempt_at <= CURRENT_TIMESTAMP"
        );
        $claim->execute([$id]);
        if ($claim->rowCount() < 1) continue;

        $attemptNo = (int)$row['attempts'] + 1; // this attempt's 1-indexed number
        $err = _email_attempt($row['to_address'], $row['to_name'], $row['subject'], $row['body']);

        if ($err === null) {
            _log_email($row['to_address'], $row['subject'], 'sent', "recovered from queue (attempt $attemptNo)");
            $db->prepare('DELETE FROM email_retry_queue WHERE id = ?')->execute([$id]);
            $sent++;
        } elseif ($attemptNo >= EMAIL_QUEUE_MAX_ATTEMPTS || !_smtp_error_is_retryable($err)) {
            // Out of attempts, or a now-permanent error: give up and record it.
            _log_email($row['to_address'], $row['subject'], 'failed', $err);
            $db->prepare('DELETE FROM email_retry_queue WHERE id = ?')->execute([$id]);
            $failed++;
        } else {
            // Reschedule with the proper backoff (overwriting the tentative +1h claim).
            $delay = _email_queue_backoff_minutes($attemptNo + 1, _smtp_error_is_quota($err));
            $db->prepare(
                "UPDATE email_retry_queue
                 SET last_error = ?, next_attempt_at = datetime('now', ? || ' minutes')
                 WHERE id = ?"
            )->execute([$err, '+' . $delay, $id]);
            $requeued++;
        }
    }

    return ['sent' => $sent, 'failed' => $failed, 'requeued' => $requeued];
}

/**
 * Log email sends to sms_log table for unified notification history.
 */
function _log_email(string $to, string $subject, string $status, ?string $error): void {
    try {
        $db = get_db();
        $db->prepare('INSERT INTO sms_log (direction, phone, body, provider, status, error) VALUES (?, ?, ?, ?, ?, ?)')
           ->execute(['outbound', $to, $subject, 'email', $status, $error]);
    } catch (Exception $e) {}
}
