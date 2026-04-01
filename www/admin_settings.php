<?php
require_once __DIR__ . '/auth.php';

$current = require_login();
if ($current['role'] !== 'admin') {
    http_response_code(403);
    exit('Access denied.');
}

$db = get_db();

// Named timezones — DST-aware where applicable
$tz_offsets = [
    'UTC-12:00 — International Date Line West'          => 'Etc/GMT+12',
    'UTC-11:00 — American Samoa'                        => 'Pacific/Pago_Pago',
    'UTC-10:00 — Hawaii'                                => 'Pacific/Honolulu',
    'UTC-09:30 — Marquesas Islands'                     => 'Pacific/Marquesas',
    'UTC-09:00 — Alaska'                                => 'America/Anchorage',
    'UTC-08:00 — Pacific Time (US & Canada)'            => 'America/Los_Angeles',
    'UTC-07:00 — Mountain Time (US & Canada)'           => 'America/Denver',
    'UTC-07:00 — Arizona (no DST)'                      => 'America/Phoenix',
    'UTC-06:00 — Central Time (US & Canada)'            => 'America/Chicago',
    'UTC-05:00 — Eastern Time (US & Canada)'            => 'America/New_York',
    'UTC-04:00 — Atlantic Time (Canada)'                => 'America/Halifax',
    'UTC-03:30 — Newfoundland'                          => 'America/St_Johns',
    'UTC-03:00 — Buenos Aires'                          => 'America/Argentina/Buenos_Aires',
    'UTC-03:00 — Sao Paulo'                             => 'America/Sao_Paulo',
    'UTC-02:00 — Mid-Atlantic'                          => 'Etc/GMT+2',
    'UTC-01:00 — Azores'                                => 'Atlantic/Azores',
    'UTC+00:00 — London, Dublin, Lisbon'                => 'Europe/London',
    'UTC+00:00 — Reykjavik (no DST)'                   => 'Atlantic/Reykjavik',
    'UTC+01:00 — Paris, Berlin, Rome, Madrid'           => 'Europe/Paris',
    'UTC+02:00 — Helsinki, Cairo, Johannesburg'         => 'Europe/Helsinki',
    'UTC+03:00 — Moscow, Nairobi'                       => 'Europe/Moscow',
    'UTC+03:00 — Baghdad'                               => 'Asia/Baghdad',
    'UTC+03:30 — Tehran'                                => 'Asia/Tehran',
    'UTC+04:00 — Dubai, Abu Dhabi'                      => 'Asia/Dubai',
    'UTC+04:00 — Baku'                                  => 'Asia/Baku',
    'UTC+04:30 — Kabul'                                 => 'Asia/Kabul',
    'UTC+05:00 — Karachi, Islamabad'                    => 'Asia/Karachi',
    'UTC+05:30 — Mumbai, Kolkata, New Delhi'            => 'Asia/Kolkata',
    'UTC+05:45 — Kathmandu'                             => 'Asia/Kathmandu',
    'UTC+06:00 — Dhaka, Almaty'                         => 'Asia/Dhaka',
    'UTC+06:30 — Yangon'                                => 'Asia/Yangon',
    'UTC+07:00 — Bangkok, Hanoi, Jakarta'               => 'Asia/Bangkok',
    'UTC+08:00 — Beijing, Singapore, Hong Kong'         => 'Asia/Shanghai',
    'UTC+08:00 — Perth'                                 => 'Australia/Perth',
    'UTC+08:45 — Eucla'                                 => 'Australia/Eucla',
    'UTC+09:00 — Tokyo, Osaka'                          => 'Asia/Tokyo',
    'UTC+09:00 — Seoul'                                 => 'Asia/Seoul',
    'UTC+09:30 — Darwin (no DST)'                       => 'Australia/Darwin',
    'UTC+09:30 — Adelaide'                              => 'Australia/Adelaide',
    'UTC+10:00 — Sydney, Melbourne'                     => 'Australia/Sydney',
    'UTC+10:00 — Brisbane (no DST)'                     => 'Australia/Brisbane',
    'UTC+10:30 — Lord Howe Island'                      => 'Australia/Lord_Howe',
    'UTC+11:00 — Solomon Islands, New Caledonia'        => 'Pacific/Guadalcanal',
    'UTC+12:00 — Auckland, Wellington'                  => 'Pacific/Auckland',
    'UTC+12:00 — Fiji'                                  => 'Pacific/Fiji',
    'UTC+12:45 — Chatham Islands'                       => 'Pacific/Chatham',
    'UTC+13:00 — Tonga'                                 => 'Pacific/Tongatapu',
    'UTC+13:00 — Samoa'                                 => 'Pacific/Apia',
    'UTC+14:00 — Line Islands (Kiribati)'               => 'Pacific/Kiritimati',
];

session_start_safe();
$flash = ['type' => '', 'msg' => ''];
if (!empty($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
}

$tab = in_array($_GET['tab'] ?? '', ['dashboard', 'general', 'appearance', 'logs', 'users', 'email', 'sms']) ? $_GET['tab'] : 'dashboard';

// ── POST ─────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $post_tab = $_POST['tab'] ?? 'general';
    if (!csrf_verify()) {
        $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Invalid request token.'];
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'general') {
            $site_name = trim($_POST['site_name'] ?? '');
            $timezone  = trim($_POST['timezone'] ?? '');
            if ($site_name === '') {
                $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Site name cannot be empty.'];
            } elseif ($timezone !== '' && !in_array($timezone, array_values($tz_offsets))) {
                $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Invalid timezone selected.'];
            } else {
                set_setting('site_name', $site_name);
                if ($timezone !== '') set_setting('timezone', $timezone);
                set_setting('allow_registration', isset($_POST['allow_registration']) ? '1' : '0');
                set_setting('show_upcoming_events', isset($_POST['show_upcoming_events']) ? '1' : '0');
                set_setting('show_calendar', isset($_POST['show_calendar']) ? '1' : '0');
                db_log_activity($current['id'], 'updated site settings');
                $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Settings saved.'];
            }
        }

        if ($action === 'clear_logs') {
            $db->exec('DELETE FROM activity_log');
            db_log_activity($current['id'], 'cleared all logs');
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Logs cleared.'];
            $post_tab = 'logs';
        }

        if ($action === 'add') {
            $username = strtolower(trim($_POST['username'] ?? ''));
            $email    = trim($_POST['email'] ?? '');
            $role     = in_array($_POST['role'] ?? '', ['admin', 'user']) ? $_POST['role'] : 'user';
            $password = $_POST['password'] ?? '';
            if ($username === '' || $password === '') {
                $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Username and password are required.'];
            } elseif (strlen($password) < 12) {
                $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Password must be at least 12 characters.'];
            } else {
                try {
                    $hash = password_hash($password, PASSWORD_BCRYPT);
                    $db->prepare('INSERT INTO users (username, password_hash, email, role) VALUES (?, ?, ?, ?)')
                       ->execute([$username, $hash, $email ?: null, $role]);
                    db_log_activity($current['id'], "created user: $username");
                    $_SESSION['flash'] = ['type' => 'success', 'msg' => "User \"$username\" created."];
                } catch (PDOException $e) {
                    $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Username already exists.'];
                }
            }
            $post_tab = 'users';
        }

        if ($action === 'delete') {
            $id  = (int)($_POST['id'] ?? 0);
            $err = null;
            if ($id <= 0) {
                $err = 'Invalid user.';
            } elseif ($id === (int)$current['id']) {
                $err = 'You cannot delete your own account.';
            } else {
                $urow = $db->prepare('SELECT role, username FROM users WHERE id = ?');
                $urow->execute([$id]);
                $urow = $urow->fetch();
                if ($urow && $urow['role'] === 'admin') {
                    $count = (int)$db->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn();
                    if ($count <= 1) $err = 'Cannot delete the last admin.';
                }
            }
            if ($err) {
                $_SESSION['flash'] = ['type' => 'error', 'msg' => $err];
            } else {
                $db->prepare('DELETE FROM activity_log WHERE user_id = ?')->execute([$id]);
                $db->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
                db_log_activity($current['id'], 'deleted user: ' . ($urow['username'] ?? $id));
                $_SESSION['flash'] = ['type' => 'success', 'msg' => 'User deleted.'];
            }
            $post_tab = 'users';
        }

        if ($action === 'bulk_delete') {
            $ids        = array_map('intval', (array)($_POST['ids'] ?? []));
            $ids        = array_filter($ids, fn($i) => $i > 0 && $i !== (int)$current['id']);
            $deleted    = 0;
            $skipped    = 0;
            $adminCount = (int)$db->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn();
            foreach ($ids as $id) {
                $urow = $db->prepare('SELECT role, username FROM users WHERE id = ?');
                $urow->execute([$id]);
                $urow = $urow->fetch();
                if (!$urow) continue;
                if ($urow['role'] === 'admin' && $adminCount <= 1) { $skipped++; continue; }
                if ($urow['role'] === 'admin') $adminCount--;
                $db->prepare('DELETE FROM activity_log WHERE user_id = ?')->execute([$id]);
                $db->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
                db_log_activity($current['id'], 'deleted user: ' . $urow['username']);
                $deleted++;
            }
            $msg = "Deleted $deleted user" . ($deleted !== 1 ? 's' : '') . '.';
            if ($skipped) $msg .= " $skipped skipped (last admin).";
            $_SESSION['flash'] = ['type' => $deleted > 0 ? 'success' : 'error', 'msg' => $msg];
            $post_tab = 'users';
        }

        if ($action === 'email_settings') {
            require_once __DIR__ . '/mail.php';
            set_setting('smtp_host',       trim($_POST['smtp_host'] ?? ''));
            set_setting('smtp_port',       (string)(int)($_POST['smtp_port'] ?? 587));
            set_setting('smtp_user',       trim($_POST['smtp_user'] ?? ''));
            set_setting('smtp_from',       trim($_POST['smtp_from'] ?? ''));
            set_setting('smtp_from_name',  trim($_POST['smtp_from_name'] ?? ''));
            set_setting('smtp_encryption', in_array($_POST['smtp_encryption'] ?? '', ['tls','ssl','none']) ? $_POST['smtp_encryption'] : 'tls');
            if (trim($_POST['smtp_pass'] ?? '') !== '') {
                set_setting('smtp_pass', trim($_POST['smtp_pass']));
            }
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Email settings saved.'];
            $post_tab = 'email';
        }

        if ($action === 'email_test') {
            require_once __DIR__ . '/mail.php';
            $to  = trim($_POST['test_to'] ?? '');
            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
                $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Invalid test address.'];
            } else {
                $err = send_email($to, $to, 'Test Email from ' . get_setting('site_name', 'Game Night'), '<p>This is a test email. Your SMTP settings are working correctly.</p>');
                $_SESSION['flash'] = $err
                    ? ['type' => 'error',   'msg' => 'Send failed: ' . $err]
                    : ['type' => 'success', 'msg' => "Test email sent to $to."];
            }
            $post_tab = 'email';
        }

        if ($action === 'email_compose') {
            require_once __DIR__ . '/mail.php';
            $subject  = trim($_POST['compose_subject'] ?? '');
            $body     = trim($_POST['compose_body'] ?? '');
            $to_type  = $_POST['compose_to'] ?? 'all';

            if ($subject === '' || $body === '') {
                $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Subject and message are required.'];
            } else {
                $recipients = [];
                if ($to_type === 'all') {
                    $rows = $db->query("SELECT email, username FROM users WHERE email IS NOT NULL AND email != ''")->fetchAll();
                    foreach ($rows as $r) $recipients[] = [$r['email'], $r['username']];
                } elseif ($to_type === 'custom') {
                    $addr = trim($_POST['compose_custom'] ?? '');
                    if (filter_var($addr, FILTER_VALIDATE_EMAIL)) $recipients[] = [$addr, $addr];
                    else { $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Invalid email address.']; $post_tab = 'email'; goto done; }
                } else {
                    $uid = (int)$to_type;
                    $row = $db->prepare("SELECT email, username FROM users WHERE id=? AND email IS NOT NULL AND email != ''")->execute([$uid]) ? null : null;
                    $stmt2 = $db->prepare("SELECT email, username FROM users WHERE id=? AND email IS NOT NULL AND email != ''");
                    $stmt2->execute([$uid]);
                    $row = $stmt2->fetch();
                    if ($row) $recipients[] = [$row['email'], $row['username']];
                }

                if (empty($recipients)) {
                    $_SESSION['flash'] = ['type' => 'error', 'msg' => 'No valid recipients found.'];
                } else {
                    $sent = 0; $errors = [];
                    $htmlBody = nl2br(htmlspecialchars($body));
                    foreach ($recipients as [$addr, $name]) {
                        $err = send_email($addr, $name, $subject, $htmlBody);
                        if ($err) $errors[] = "$addr: $err";
                        else $sent++;
                    }
                    if ($errors) $_SESSION['flash'] = ['type' => 'error', 'msg' => "$sent sent. Errors: " . implode('; ', $errors)];
                    else $_SESSION['flash'] = ['type' => 'success', 'msg' => "Email sent to $sent recipient" . ($sent !== 1 ? 's' : '') . "."];
                }
            }
            $post_tab = 'email';
        }
        if ($action === 'appearance') {
            foreach (['nav_bg_color', 'nav_text_color', 'accent_color'] as $key) {
                $val = trim($_POST[$key] ?? '');
                if ($val === '' || preg_match('/^#[0-9a-fA-F]{6}$/', $val)) {
                    set_setting($key, $val);
                }
            }
            db_log_activity($current['id'], 'updated appearance colors');
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Colors saved.'];
            $post_tab = 'appearance';
        }

        if ($action === 'banner_upload') {
            if (isset($_FILES['banner']) && $_FILES['banner']['error'] === UPLOAD_ERR_OK) {
                $tmp  = $_FILES['banner']['tmp_name'];
                $mime = mime_content_type($tmp);
                $allowed_mime = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
                if (isset($allowed_mime[$mime]) && $_FILES['banner']['size'] <= 2 * 1024 * 1024) {
                    $ext  = $allowed_mime[$mime];
                    $dest = __DIR__ . '/uploads/banner.' . $ext;
                    foreach (glob(__DIR__ . '/uploads/banner.*') ?: [] as $old) { @unlink($old); }
                    if (move_uploaded_file($tmp, $dest)) {
                        set_setting('banner_path', '/uploads/banner.' . $ext);
                        db_log_activity($current['id'], 'uploaded site banner');
                        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Banner uploaded.'];
                    } else {
                        $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Upload failed — check directory permissions.'];
                    }
                } else {
                    $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Invalid file. Use JPEG, PNG, GIF, or WebP under 2 MB.'];
                }
            } else {
                $_SESSION['flash'] = ['type' => 'error', 'msg' => 'No file received.'];
            }
            $post_tab = 'appearance';
        }

        if ($action === 'sms_credentials') {
            set_setting('twilio_sid',   trim($_POST['twilio_sid']   ?? ''));
            set_setting('twilio_token', trim($_POST['twilio_token'] ?? ''));
            set_setting('twilio_from',  trim($_POST['twilio_from']  ?? ''));
            db_log_activity($current['id'], 'updated Twilio credentials');
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Twilio credentials saved.'];
            $post_tab = 'sms';
        }

        if ($action === 'sms_test') {
            $to   = normalize_phone(trim($_POST['to']   ?? ''));
            $body = trim($_POST['body'] ?? '');

            if (!$to || !$body) {
                $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Phone number and message are required.'];
            } else {
                $twilio_sid_val   = get_setting('twilio_sid');
                $twilio_token_val = get_setting('twilio_token');
                $twilio_from_val  = get_setting('twilio_from');

                if (!$twilio_sid_val || !$twilio_token_val || !$twilio_from_val) {
                    $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Twilio credentials not configured.'];
                } else {
                    $url  = 'https://api.twilio.com/2010-04-01/Accounts/' . $twilio_sid_val . '/Messages.json';
                    $data = ['From' => $twilio_from_val, 'To' => $to, 'Body' => $body];

                    $ch = curl_init($url);
                    curl_setopt_array($ch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_POST           => true,
                        CURLOPT_POSTFIELDS     => http_build_query($data),
                        CURLOPT_USERPWD        => $twilio_sid_val . ':' . $twilio_token_val,
                    ]);
                    $response = curl_exec($ch);
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);

                    $json = json_decode($response, true);
                    if ($httpCode === 201) {
                        db_log_activity($current['id'], "sent test SMS to $to");
                        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'SMS sent! SID: ' . ($json['sid'] ?? 'unknown')];
                    } else {
                        $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Send failed: ' . ($json['message'] ?? "HTTP $httpCode")];
                    }
                }
            }
            $post_tab = 'sms';
        }

        if ($action === 'banner_remove') {
            foreach (glob(__DIR__ . '/uploads/banner.*') ?: [] as $f) { @unlink($f); }
            set_setting('banner_path', '');
            db_log_activity($current['id'], 'removed site banner');
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Banner removed.'];
            $post_tab = 'appearance';
        }

        if ($action === 'header_banner_upload') {
            if (isset($_FILES['header_banner']) && $_FILES['header_banner']['error'] === UPLOAD_ERR_OK) {
                $tmp  = $_FILES['header_banner']['tmp_name'];
                $mime = mime_content_type($tmp);
                $allowed_mime = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
                if (isset($allowed_mime[$mime]) && $_FILES['header_banner']['size'] <= 4 * 1024 * 1024) {
                    $ext  = $allowed_mime[$mime];
                    $dest = __DIR__ . '/uploads/header_banner.' . $ext;
                    foreach (glob(__DIR__ . '/uploads/header_banner.*') ?: [] as $old) { @unlink($old); }
                    if (move_uploaded_file($tmp, $dest)) {
                        set_setting('header_banner_path', '/uploads/header_banner.' . $ext);
                        db_log_activity($current['id'], 'uploaded header banner');
                        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Header banner uploaded.'];
                    } else {
                        $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Upload failed — check directory permissions.'];
                    }
                } else {
                    $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Invalid file. Use JPEG, PNG, GIF, or WebP under 4 MB.'];
                }
            } else {
                $_SESSION['flash'] = ['type' => 'error', 'msg' => 'No file received.'];
            }
            $post_tab = 'appearance';
        }

        if ($action === 'header_banner_remove') {
            foreach (glob(__DIR__ . '/uploads/header_banner.*') ?: [] as $f) { @unlink($f); }
            set_setting('header_banner_path', '');
            db_log_activity($current['id'], 'removed header banner');
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Header banner removed.'];
            $post_tab = 'appearance';
        }

        if ($action === 'header_banner_height') {
            $h = max(20, min(200, (int)($_POST['header_banner_height'] ?? 46)));
            set_setting('header_banner_height', (string)$h);
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Header height saved.'];
            $post_tab = 'appearance';
        }

        done:
    }
    header("Location: /admin_settings.php?tab=$post_tab");
    exit;
}

// ── Logs data ─────────────────────────────────────────────────────────────────
$local_tz = new DateTimeZone(get_setting('timezone', 'UTC'));
$page     = max(1, (int)($_GET['page'] ?? 1));
$per_page = 50;
$offset   = ($page - 1) * $per_page;
$log_total = (int)$db->query('SELECT COUNT(*) FROM activity_log')->fetchColumn();
$log_pages = max(1, (int)ceil($log_total / $per_page));
$log_rows  = $db->prepare("
    SELECT a.action, a.ip, a.created_at, u.username
    FROM   activity_log a
    JOIN   users u ON u.id = a.user_id
    ORDER  BY a.id DESC
    LIMIT  ? OFFSET ?
");
$log_rows->execute([$per_page, $offset]);
$log_rows = $log_rows->fetchAll();

$site_name    = get_setting('site_name', 'Game Night');
$timezone     = get_setting('timezone', 'UTC');
$token        = csrf_token();
$twilio_sid   = get_setting('twilio_sid');
$twilio_token = get_setting('twilio_token');
$twilio_from  = get_setting('twilio_from');

// ── Users data ───────────────────────────────────────────────────────────────
$users = $db->query('SELECT id, username, email, role, created_at, last_login FROM users ORDER BY id')->fetchAll();

// ── Dashboard stats ───────────────────────────────────────────────────────────
$dash_users  = (int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn();
$dash_logins = (int)$db->query("SELECT COUNT(*) FROM activity_log WHERE action = 'login'")->fetchColumn();
$dash_events = (int)$db->query('SELECT COUNT(*) FROM activity_log')->fetchColumn();
$dash_posts  = (int)$db->query('SELECT COUNT(*) FROM posts')->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($site_name) ?></title>
    <link rel="stylesheet" href="/style.css">
    <style>
        .hint { font-size: .78rem; color: #94a3b8; margin-top: .35rem; }
        .sms-grid { display:grid; grid-template-columns:1fr 1fr; gap:1.5rem; }
        @media (max-width:640px) { .sms-grid { grid-template-columns:1fr; } }
        .cred-note { font-size:.78rem; color:#94a3b8; margin-top:.25rem; }

        .tabs {
            display: flex;
            gap: 0;
            border-bottom: 2px solid #e2e8f0;
            margin-bottom: 1.75rem;
        }
        .tab-btn {
            padding: .6rem 1.25rem;
            font-size: .9rem;
            font-weight: 500;
            color: #64748b;
            background: none;
            border: none;
            border-bottom: 2px solid transparent;
            margin-bottom: -2px;
            cursor: pointer;
            text-decoration: none;
        }
        .tab-btn:hover { color: #1e293b; }
        .tab-btn.active { color: #2563eb; border-bottom-color: #2563eb; font-weight: 600; }

        .tab-panel { display: none; }
        .tab-panel.active { display: block; }

        .pagination { display:flex; gap:.4rem; margin-top:1rem; flex-wrap:wrap; }
        .pagination a, .pagination span {
            display:inline-block; padding:.3rem .75rem; border-radius:6px;
            font-size:.82rem; border:1px solid #e2e8f0;
        }
        .pagination a { color:#2563eb; }
        .pagination a:hover { background:#eff6ff; text-decoration:none; }
        .pagination .current { background:#2563eb; color:#fff; border-color:#2563eb; }

        /* ── Users tab ── */
        #bulkBar {
            display: none; align-items: center; gap: .75rem;
            background: #eff6ff; border: 1px solid #bfdbfe;
            border-radius: 8px; padding: .5rem 1rem;
            font-size: .875rem; color: #1e40af; margin-bottom: 1rem;
        }
        #bulkBar .bulk-label { font-weight: 600; }
        .cb-col { width: 40px; text-align: center; }
        input[type="checkbox"] { width: 16px; height: 16px; cursor: pointer; accent-color: #2563eb; }
        .action-btns { display: flex; gap: .4rem; }
        .btn-icon {
            display: inline-flex; align-items: center; justify-content: center;
            width: 32px; height: 32px; border-radius: 7px; font-size: 1rem;
            border: 1.5px solid #e2e8f0; background: #fff; cursor: pointer;
            color: #475569; text-decoration: none;
        }
        .btn-icon:hover { background: #f1f5f9; border-color: #cbd5e1; text-decoration: none; color: #1e293b; }
        .btn-icon.danger { border-color: #fca5a5; color: #ef4444; }
        .btn-icon.danger:hover { background: #fee2e2; border-color: #f87171; }
        .modal-overlay {
            display: none; position: fixed; inset: 0;
            background: rgba(0,0,0,.45); z-index: 200;
            align-items: center; justify-content: center; padding: 1rem;
        }
        .modal-overlay.open { display: flex; }
        .modal {
            background: #fff; border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0,0,0,.25);
            width: 100%; max-width: 440px; padding: 2rem;
            animation: modalIn .18s ease;
        }
        @keyframes modalIn {
            from { transform: translateY(-12px); opacity: 0; }
            to   { transform: none; opacity: 1; }
        }
        .modal-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 1.5rem; }
        .modal-header h2 { font-size: 1.25rem; }
        .modal-close {
            width: 32px; height: 32px; border-radius: 7px; border: none;
            background: #f1f5f9; cursor: pointer; font-size: 1.1rem;
            color: #64748b; display: flex; align-items: center; justify-content: center;
        }
        .modal-close:hover { background: #e2e8f0; }
        .modal select {
            width: 100%; padding: .6rem .85rem; border: 1.5px solid #e2e8f0;
            border-radius: 7px; font-size: .95rem; background: #f8fafc;
        }
        .modal select:focus { outline: none; border-color: #2563eb; background: #fff; }
    </style>
</head>
<body>

<?php $nav_active = 'site-settings'; require __DIR__ . '/_nav.php'; ?>

<div class="dash-wrap">

    <div class="dash-header">
        <h1>Site Settings</h1>
    </div>

    <?php if ($flash['msg']): ?>
        <div class="alert alert-<?= $flash['type'] === 'error' ? 'error' : 'success' ?>"
             style="margin-bottom:1.5rem">
            <?= htmlspecialchars($flash['msg']) ?>
        </div>
    <?php endif; ?>

    <div class="tabs">
        <a href="/admin_settings.php?tab=dashboard"
           class="tab-btn <?= $tab === 'dashboard' ? 'active' : '' ?>">Dashboard</a>
        <a href="/admin_settings.php?tab=general"
           class="tab-btn <?= $tab === 'general' ? 'active' : '' ?>">General</a>
        <a href="/admin_settings.php?tab=appearance"
           class="tab-btn <?= $tab === 'appearance' ? 'active' : '' ?>">Appearance</a>
        <a href="/admin_settings.php?tab=logs"
           class="tab-btn <?= $tab === 'logs' ? 'active' : '' ?>">Logs</a>
        <a href="/admin_settings.php?tab=users"
           class="tab-btn <?= $tab === 'users' ? 'active' : '' ?>">Users</a>
        <a href="/admin_settings.php?tab=email"
           class="tab-btn <?= $tab === 'email' ? 'active' : '' ?>">Email</a>
        <a href="/admin_settings.php?tab=sms"
           class="tab-btn <?= $tab === 'sms' ? 'active' : '' ?>">SMS</a>
    </div>

    <!-- ── Dashboard tab ── -->
    <div class="tab-panel <?= $tab === 'dashboard' ? 'active' : '' ?>">

        <p style="color:#64748b;font-size:.875rem;margin-bottom:1.5rem">
            Welcome back, <?= htmlspecialchars($current['username']) ?>.
            <?php if ($current['last_login']): ?>
                Last login: <?= htmlspecialchars($current['last_login']) ?>
            <?php endif; ?>
        </p>

        <div class="stats">
            <div class="stat-card">
                <div class="label">Total Users</div>
                <div class="value"><?= $dash_users ?></div>
            </div>
            <div class="stat-card">
                <div class="label">Total Logins</div>
                <div class="value"><?= $dash_logins ?></div>
            </div>
            <div class="stat-card">
                <div class="label">Log Entries</div>
                <div class="value"><?= $dash_events ?></div>
            </div>
            <div class="stat-card">
                <div class="label">Posts</div>
                <div class="value"><?= $dash_posts ?></div>
            </div>
        </div>

        <div style="display:flex;gap:.75rem;margin-top:1.5rem">
            <a href="/admin_settings.php?tab=users" class="btn btn-primary">Manage Users</a>
            <a href="/admin_settings.php?tab=logs" class="btn btn-outline">View Logs</a>
        </div>

    </div>

    <!-- ── General tab ── -->
    <div class="tab-panel <?= $tab === 'general' ? 'active' : '' ?>">
        <div class="card" style="max-width:480px">
            <h2>General</h2>
            <p class="subtitle">Basic site-wide configuration.</p>
            <form method="post" action="/admin_settings.php">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">
                <input type="hidden" name="action" value="general">
                <input type="hidden" name="tab" value="general">
                <div class="form-group">
                    <label for="site_name">Site Name</label>
                    <input type="text" id="site_name" name="site_name"
                           value="<?= htmlspecialchars($site_name) ?>"
                           autocomplete="off" required>
                    <p class="hint">Shown in the nav bar, page titles, and footer.</p>
                </div>
                <div class="form-group">
                    <label for="timezone">Timezone</label>
                    <select id="timezone" name="timezone" style="width:100%;padding:.6rem .85rem;border:1.5px solid #e2e8f0;border-radius:7px;font-size:.95rem;background:#f8fafc">
                        <?php foreach ($tz_offsets as $label => $tz_id): ?>
                            <option value="<?= htmlspecialchars($tz_id) ?>"
                                <?= $tz_id === $timezone ? 'selected' : '' ?>>
                                <?= htmlspecialchars($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="hint">Current server time: <strong><?= date('Y-m-d H:i:s') ?></strong></p>
                </div>
                <div class="form-group" style="margin-top:.5rem">
                    <label style="display:flex;align-items:center;gap:.6rem;cursor:pointer;font-weight:500">
                        <input type="checkbox" name="allow_registration" value="1"
                               <?= get_setting('allow_registration', '1') === '1' ? 'checked' : '' ?>
                               style="width:16px;height:16px;accent-color:#2563eb">
                        Allow new user registration
                    </label>
                    <p class="hint">When unchecked, the Sign Up page returns a 403 and the link is hidden from the login page.</p>
                </div>
                <div class="form-group" style="margin-top:.5rem">
                    <label style="display:flex;align-items:center;gap:.6rem;cursor:pointer;font-weight:500">
                        <input type="checkbox" name="show_upcoming_events" value="1"
                               <?= get_setting('show_upcoming_events', '1') === '1' ? 'checked' : '' ?>
                               style="width:16px;height:16px;accent-color:#2563eb">
                        Show &ldquo;Upcoming Events&rdquo; on the landing page
                    </label>
                    <p class="hint">When unchecked, the upcoming-events section is hidden for all visitors.</p>
                </div>
                <div class="form-group" style="margin-top:.5rem">
                    <label style="display:flex;align-items:center;gap:.6rem;cursor:pointer;font-weight:500">
                        <input type="checkbox" name="show_calendar" value="1"
                               <?= get_setting('show_calendar', '1') === '1' ? 'checked' : '' ?>
                               style="width:16px;height:16px;accent-color:#2563eb">
                        Enable Calendar
                    </label>
                    <p class="hint">When unchecked, the Calendar page is disabled and the nav link is hidden.</p>
                </div>
                <button type="submit" class="btn btn-primary" style="width:100%;margin-top:.25rem">
                    Save
                </button>
            </form>
        </div>
    </div>

    <!-- ── Appearance tab ── -->
    <div class="tab-panel <?= $tab === 'appearance' ? 'active' : '' ?>">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;align-items:start;max-width:860px">

            <!-- Colors -->
            <div class="card">
                <h2>Colors</h2>
                <p class="subtitle">Customize the nav bar. Leave a field blank to use the default.</p>
                <form method="post" action="/admin_settings.php">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">
                    <input type="hidden" name="action" value="appearance">
                    <input type="hidden" name="tab" value="appearance">
                    <div class="form-group">
                        <label>Nav Background</label>
                        <div style="display:flex;gap:.5rem;align-items:center">
                            <input type="color" id="nav_bg_picker"
                                   value="<?= htmlspecialchars(get_setting('nav_bg_color','') ?: '#0f172a') ?>"
                                   style="width:40px;height:38px;padding:2px;border:1.5px solid #e2e8f0;border-radius:7px;cursor:pointer;flex-shrink:0"
                                   oninput="syncText('nav_bg_color',this.value);updatePreview()">
                            <input type="text" name="nav_bg_color" id="nav_bg_color"
                                   value="<?= htmlspecialchars(get_setting('nav_bg_color','')) ?>"
                                   placeholder="#0f172a" maxlength="7" style="flex:1"
                                   oninput="syncPicker('nav_bg_picker',this.value);updatePreview()">
                            <button type="button" class="btn btn-outline btn-sm"
                                    onclick="resetColor('nav_bg_color','nav_bg_picker','#0f172a')">Default</button>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Brand / Logo Text Color</label>
                        <div style="display:flex;gap:.5rem;align-items:center">
                            <input type="color" id="nav_text_picker"
                                   value="<?= htmlspecialchars(get_setting('nav_text_color','') ?: '#ffffff') ?>"
                                   style="width:40px;height:38px;padding:2px;border:1.5px solid #e2e8f0;border-radius:7px;cursor:pointer;flex-shrink:0"
                                   oninput="syncText('nav_text_color',this.value);updatePreview()">
                            <input type="text" name="nav_text_color" id="nav_text_color"
                                   value="<?= htmlspecialchars(get_setting('nav_text_color','')) ?>"
                                   placeholder="#ffffff" maxlength="7" style="flex:1"
                                   oninput="syncPicker('nav_text_picker',this.value);updatePreview()">
                            <button type="button" class="btn btn-outline btn-sm"
                                    onclick="resetColor('nav_text_color','nav_text_picker','#ffffff')">Default</button>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Accent Color</label>
                        <div style="display:flex;gap:.5rem;align-items:center">
                            <input type="color" id="accent_picker"
                                   value="<?= htmlspecialchars(get_setting('accent_color','') ?: '#2563eb') ?>"
                                   style="width:40px;height:38px;padding:2px;border:1.5px solid #e2e8f0;border-radius:7px;cursor:pointer;flex-shrink:0"
                                   oninput="syncText('accent_color',this.value);updatePreview()">
                            <input type="text" name="accent_color" id="accent_color"
                                   value="<?= htmlspecialchars(get_setting('accent_color','')) ?>"
                                   placeholder="#2563eb" maxlength="7" style="flex:1"
                                   oninput="syncPicker('accent_picker',this.value);updatePreview()">
                            <button type="button" class="btn btn-outline btn-sm"
                                    onclick="resetColor('accent_color','accent_picker','#2563eb')">Default</button>
                        </div>
                        <p class="hint">Used for buttons, active links, and highlights across the site.</p>
                    </div>
                    <button type="submit" class="btn btn-primary" style="width:100%">Save Colors</button>
                </form>
            </div>

            <!-- Banner -->
            <div class="card">
                <h2>Banner / Logo Image</h2>
                <p class="subtitle">Replaces the site name text in the nav bar. JPEG, PNG, GIF, or WebP &mdash; max 2 MB.</p>
                <?php $banner_path = get_setting('banner_path', ''); ?>
                <?php if ($banner_path): ?>
                <div style="background:<?= htmlspecialchars(get_setting('nav_bg_color','') ?: '#0f172a') ?>;padding:.65rem 1rem;border-radius:8px;margin-bottom:.85rem;display:flex;align-items:center">
                    <img src="<?= htmlspecialchars($banner_path) ?>?v=<?= time() ?>"
                         alt="Current banner" style="max-height:40px;max-width:100%;display:block">
                </div>
                <form method="post" action="/admin_settings.php" style="margin-bottom:1rem">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">
                    <input type="hidden" name="action" value="banner_remove">
                    <input type="hidden" name="tab" value="appearance">
                    <button type="submit" class="btn btn-outline"
                            style="color:#ef4444;border-color:#fca5a5;font-size:.82rem"
                            onclick="return confirm('Remove the banner?')">&#x2715; Remove Banner</button>
                </form>
                <?php endif; ?>
                <form method="post" action="/admin_settings.php" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">
                    <input type="hidden" name="action" value="banner_upload">
                    <input type="hidden" name="tab" value="appearance">
                    <div class="form-group">
                        <label><?= $banner_path ? 'Replace Banner' : 'Upload Banner' ?></label>
                        <input type="file" name="banner" required
                               accept="image/jpeg,image/png,image/gif,image/webp"
                               style="display:block;width:100%;padding:.45rem 0;font-size:.875rem">
                        <p class="hint">Recommended: transparent PNG, ~36–44 px tall.</p>
                    </div>
                    <button type="submit" class="btn btn-primary" style="width:100%">Upload</button>
                </form>
            </div>
        </div>

        <!-- Header Banner -->
        <div class="card" style="max-width:860px;margin-top:1.5rem">
            <h2>Header Banner</h2>
            <p class="subtitle">Large banner displayed in the center of the nav bar. JPEG, PNG, GIF, or WebP &mdash; max 4 MB.</p>
            <?php $header_banner_path = get_setting('header_banner_path', ''); ?>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;align-items:start">
                <div>
                    <?php if ($header_banner_path): ?>
                    <div style="background:<?= htmlspecialchars(get_setting('nav_bg_color','') ?: '#0f172a') ?>;padding:.65rem 1rem;border-radius:8px;margin-bottom:.85rem;text-align:center">
                        <img src="<?= htmlspecialchars($header_banner_path) ?>?v=<?= time() ?>"
                             alt="Current header banner" style="max-height:<?= max(20, min(200, (int)get_setting('header_banner_height','140'))) - 10 ?>px;max-width:100%">
                    </div>
                    <form method="post" action="/admin_settings.php" style="margin-bottom:1rem">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">
                        <input type="hidden" name="action" value="header_banner_remove">
                        <input type="hidden" name="tab" value="appearance">
                        <button type="submit" class="btn btn-outline"
                                style="color:#ef4444;border-color:#fca5a5;font-size:.82rem"
                                onclick="return confirm('Remove the header banner?')">&#x2715; Remove Header Banner</button>
                    </form>
                    <?php endif; ?>
                    <form method="post" action="/admin_settings.php" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">
                        <input type="hidden" name="action" value="header_banner_upload">
                        <input type="hidden" name="tab" value="appearance">
                        <div class="form-group">
                            <label><?= $header_banner_path ? 'Replace Header Banner' : 'Upload Header Banner' ?></label>
                            <input type="file" name="header_banner" required
                                   accept="image/jpeg,image/png,image/gif,image/webp"
                                   style="display:block;width:100%;padding:.45rem 0;font-size:.875rem">
                        </div>
                        <button type="submit" class="btn btn-primary" style="width:100%">Upload</button>
                    </form>
                </div>
                <div>
                    <form method="post" action="/admin_settings.php">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">
                        <input type="hidden" name="action" value="header_banner_height">
                        <input type="hidden" name="tab" value="appearance">
                        <div class="form-group">
                            <label>Header Height: <strong id="hh_label"><?= (int)get_setting('header_banner_height','140') ?>px</strong></label>
                            <input type="range" name="header_banner_height" id="hh_slider"
                                   min="20" max="200" value="<?= (int)get_setting('header_banner_height','140') ?>"
                                   style="width:100%;margin:.5rem 0"
                                   oninput="document.getElementById('hh_label').textContent=this.value+'px'">
                            <p class="hint">Controls the nav bar height when a header banner is displayed (20–200 px, default 46).</p>
                        </div>
                        <button type="submit" class="btn btn-primary" style="width:100%">Save Height</button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Live preview -->
        <div style="margin-top:1.5rem;max-width:860px">
            <p style="font-size:.82rem;font-weight:600;color:#64748b;margin-bottom:.5rem;text-transform:uppercase;letter-spacing:.04em">Live Preview</p>
            <div style="border-radius:10px;overflow:hidden;box-shadow:var(--shadow)">
                <div id="previewTop" style="display:flex;align-items:center;justify-content:space-between;padding:0 2rem;height:52px;background:<?= htmlspecialchars(get_setting('nav_bg_color','') ?: '#0f172a') ?>">
                    <span id="previewBrand" style="font-weight:700;font-size:1.15rem;color:<?= htmlspecialchars(get_setting('nav_text_color','') ?: '#ffffff') ?>">
                        <?= $banner_path ? '<img src="' . htmlspecialchars($banner_path) . '" style="max-height:36px;vertical-align:middle">' : htmlspecialchars($site_name) ?>
                    </span>
                    <span style="color:#94a3b8;font-size:.875rem"><?= htmlspecialchars($current['username']) ?> &#9776;</span>
                </div>
                <div id="previewLinks" style="height:40px;background:rgba(0,0,0,.2);padding:0 2rem;display:flex;align-items:center;gap:1.5rem">
                    <span id="previewAccent" style="font-size:.9rem;font-weight:600;color:<?= htmlspecialchars(get_setting('accent_color','') ?: '#2563eb') ?>">Home</span>
                    <span style="font-size:.9rem;color:#94a3b8">Calendar</span>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Logs tab ── -->
    <div class="tab-panel <?= $tab === 'logs' ? 'active' : '' ?>">

        <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:1rem;margin-bottom:1.25rem">
            <p style="color:#64748b;font-size:.875rem;margin:0">
                All user activity &mdash; <?= number_format($log_total) ?> total entries.
            </p>
            <form method="post" action="/admin_settings.php"
                  onsubmit="return confirm('Clear all log entries? This cannot be undone.')">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">
                <input type="hidden" name="action" value="clear_logs">
                <input type="hidden" name="tab" value="logs">
                <button type="submit" class="btn"
                        style="background:#ef4444;color:#fff">Clear Logs</button>
            </form>
        </div>

        <div class="table-card">
            <?php if (empty($log_rows)): ?>
                <p style="padding:1rem 1.5rem;color:#64748b;font-size:.875rem">No activity recorded yet.</p>
            <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>User</th>
                        <th>Action</th>
                        <th>IP</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($log_rows as $a): ?>
                    <tr>
                        <td style="white-space:nowrap">
                            <?= htmlspecialchars(
                                (new DateTime($a['created_at'], new DateTimeZone('UTC')))
                                    ->setTimezone($local_tz)
                                    ->format('M j, Y g:i A')
                            ) ?>
                        </td>
                        <td><?= htmlspecialchars($a['username']) ?></td>
                        <td><?= htmlspecialchars($a['action']) ?></td>
                        <td><?= htmlspecialchars($a['ip']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ($log_pages > 1): ?>
            <div style="padding:.75rem 1.5rem;border-top:1px solid #e2e8f0">
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?tab=logs&page=<?= $page - 1 ?>">&lsaquo; Prev</a>
                    <?php endif; ?>
                    <?php for ($i = max(1, $page - 3); $i <= min($log_pages, $page + 3); $i++): ?>
                        <?php if ($i === $page): ?>
                            <span class="current"><?= $i ?></span>
                        <?php else: ?>
                            <a href="?tab=logs&page=<?= $i ?>"><?= $i ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>
                    <?php if ($page < $log_pages): ?>
                        <a href="?tab=logs&page=<?= $page + 1 ?>">Next &rsaquo;</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── Users tab ── -->
    <div class="tab-panel <?= $tab === 'users' ? 'active' : '' ?>">

        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem;flex-wrap:wrap;gap:1rem">
            <span style="color:#64748b;font-size:.875rem"><?= count($users) ?> user<?= count($users) !== 1 ? 's' : '' ?></span>
            <button class="btn btn-primary" onclick="openUserModal()">+ New User</button>
        </div>

        <div id="bulkBar">
            <span class="bulk-label"><span id="bulkCount">0</span> selected</span>
            <form id="bulkForm" method="post" action="/admin_settings.php"
                  onsubmit="return confirm('Delete selected users? This cannot be undone.')">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">
                <input type="hidden" name="action" value="bulk_delete">
                <input type="hidden" name="tab" value="users">
                <button type="submit" class="btn btn-sm" style="background:#ef4444;color:#fff">Delete Selected</button>
            </form>
            <button class="btn btn-sm btn-outline" onclick="clearSelection()">Clear</button>
        </div>

        <div class="table-card">
            <table id="userTable">
                <thead>
                    <tr>
                        <th class="cb-col"><input type="checkbox" id="selectAll" title="Select all"></th>
                        <th>#</th>
                        <th>Username</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Last Login</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($users as $u): ?>
                    <tr>
                        <td class="cb-col">
                            <?php if ((int)$u['id'] !== (int)$current['id']): ?>
                            <input type="checkbox" class="row-check"
                                   name="ids[]" value="<?= (int)$u['id'] ?>" form="bulkForm">
                            <?php endif; ?>
                        </td>
                        <td><?= (int)$u['id'] ?></td>
                        <td><?= htmlspecialchars($u['username']) ?></td>
                        <td><?= htmlspecialchars($u['email'] ?? '') ?></td>
                        <td><span class="badge badge-<?= $u['role'] === 'admin' ? 'admin' : 'user' ?>">
                            <?= htmlspecialchars($u['role']) ?></span></td>
                        <td><?= htmlspecialchars($u['last_login'] ?? 'Never') ?></td>
                        <td>
                            <div class="action-btns">
                                <a href="/user_edit.php?id=<?= (int)$u['id'] ?>"
                                   class="btn-icon" title="Edit">&#9881;</a>
                                <?php if ((int)$u['id'] !== (int)$current['id']): ?>
                                <form method="post" action="/admin_settings.php"
                                      onsubmit="return confirm('Delete ' + <?= json_encode($u['username']) ?> + '?')">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="tab" value="users">
                                    <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                                    <button type="submit" class="btn-icon danger" title="Delete">&#128465;</button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

    </div>

    <!-- ── Email tab ── -->
    <div class="tab-panel <?= $tab === 'email' ? 'active' : '' ?>">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;align-items:start">

            <!-- SMTP settings -->
            <div class="card" style="max-width:100%">
                <h2>SMTP Settings</h2>
                <?php require_once __DIR__ . '/mail.php'; $smtp_in_config = smtp_from_config(); ?>
                <?php if ($smtp_in_config): ?>
                <div class="alert alert-success" style="margin-bottom:1.25rem;font-size:.875rem">
                    SMTP is configured in <code>/var/config/config.php</code>. Edit that file to change settings.
                </div>
                <table style="width:100%;font-size:.875rem;border-collapse:collapse">
                    <?php
                    $cfg_rows = [
                        'Host'       => defined('SMTP_HOST')       ? SMTP_HOST       : '',
                        'Port'       => defined('SMTP_PORT')        ? SMTP_PORT        : '',
                        'Encryption' => defined('SMTP_ENCRYPTION')  ? SMTP_ENCRYPTION  : '',
                        'Username'   => defined('SMTP_USER')        ? SMTP_USER        : '',
                        'Password'   => defined('SMTP_PASS')        ? str_repeat('&bull;', 10) : '',
                        'From'       => defined('SMTP_FROM')        ? SMTP_FROM        : '',
                        'From Name'  => defined('SMTP_FROM_NAME')   ? SMTP_FROM_NAME   : '',
                    ];
                    foreach ($cfg_rows as $label => $val): ?>
                    <tr style="border-bottom:1px solid #f1f5f9">
                        <td style="padding:.45rem .5rem .45rem 0;color:#64748b;white-space:nowrap;width:30%"><?= $label ?></td>
                        <td style="padding:.45rem 0;font-family:monospace;word-break:break-all"><?= $label === 'Password' ? $val : htmlspecialchars((string)$val) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </table>
                <?php else: ?>
                <p class="subtitle">Configure outgoing email settings.</p>
                <form method="post" action="/admin_settings.php">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">
                    <input type="hidden" name="action" value="email_settings">
                    <input type="hidden" name="tab" value="email">
                    <div class="form-group">
                        <label>SMTP Host</label>
                        <input type="text" name="smtp_host" value="<?= htmlspecialchars(get_setting('smtp_host','')) ?>" placeholder="smtp.example.com">
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem">
                        <div class="form-group">
                            <label>Port</label>
                            <input type="number" name="smtp_port" value="<?= htmlspecialchars(get_setting('smtp_port','587')) ?>">
                        </div>
                        <div class="form-group">
                            <label>Encryption</label>
                            <select name="smtp_encryption" class="form-select">
                                <?php foreach (['tls'=>'TLS (STARTTLS)','ssl'=>'SSL','none'=>'None'] as $v=>$l): ?>
                                    <option value="<?= $v ?>"<?= get_setting('smtp_encryption','tls')===$v?' selected':'' ?>><?= $l ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Username</label>
                        <input type="text" name="smtp_user" autocomplete="off" value="<?= htmlspecialchars(get_setting('smtp_user','')) ?>">
                    </div>
                    <div class="form-group">
                        <label>Password <span style="color:#94a3b8;font-weight:400">(leave blank to keep current)</span></label>
                        <input type="password" name="smtp_pass" autocomplete="new-password">
                    </div>
                    <div class="form-group">
                        <label>From Address</label>
                        <input type="email" name="smtp_from" value="<?= htmlspecialchars(get_setting('smtp_from','')) ?>" placeholder="no-reply@example.com">
                    </div>
                    <div class="form-group">
                        <label>From Name</label>
                        <input type="text" name="smtp_from_name" value="<?= htmlspecialchars(get_setting('smtp_from_name', get_setting('site_name',''))) ?>">
                    </div>
                    <button type="submit" class="btn btn-primary" style="width:100%">Save Settings</button>
                </form>
                <?php endif; ?>

                <hr style="margin:1.5rem 0;border:none;border-top:1px solid #e2e8f0">

                <h3 style="margin-bottom:.75rem">Effective Settings (from DB)</h3>
                <table style="width:100%;font-size:.8rem;border-collapse:collapse;margin-bottom:1rem">
                <?php
                $diag = [
                    'Host'       => get_setting('smtp_host',''),
                    'Port'       => get_setting('smtp_port',''),
                    'Encryption' => get_setting('smtp_encryption',''),
                    'Username'   => get_setting('smtp_user',''),
                    'Password'   => get_setting('smtp_pass','') !== '' ? str_repeat('•',10) : '<em style="color:#ef4444">not set</em>',
                    'From'       => get_setting('smtp_from',''),
                    'From Name'  => get_setting('smtp_from_name',''),
                ];
                foreach ($diag as $label => $val): ?>
                <tr style="border-bottom:1px solid #f1f5f9">
                    <td style="padding:.3rem .5rem;color:#64748b;white-space:nowrap"><?= $label ?></td>
                    <td style="padding:.3rem .5rem;font-family:monospace"><?= $val !== '' ? htmlspecialchars($val) : '<em style="color:#ef4444">not set</em>' ?></td>
                </tr>
                <?php endforeach; ?>
                </table>

                <hr style="margin:1.5rem 0;border:none;border-top:1px solid #e2e8f0">

                <h3 style="margin-bottom:.75rem">Send Test Email</h3>
                <form method="post" action="/admin_settings.php" style="display:flex;gap:.5rem">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">
                    <input type="hidden" name="action" value="email_test">
                    <input type="hidden" name="tab" value="email">
                    <input type="email" name="test_to" required placeholder="recipient@example.com"
                           value="<?= htmlspecialchars($current['email'] ?? '') ?>" style="flex:1">
                    <button type="submit" class="btn btn-outline">Send Test</button>
                </form>
            </div>

            <!-- Compose -->
            <div class="card" style="max-width:100%">
                <h2>Compose Email</h2>
                <p class="subtitle">Send a message to users or any address.</p>
                <form method="post" action="/admin_settings.php">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">
                    <input type="hidden" name="action" value="email_compose">
                    <input type="hidden" name="tab" value="email">
                    <div class="form-group">
                        <label>To</label>
                        <select name="compose_to" class="form-select" onchange="document.getElementById('customToWrap').style.display=this.value==='custom'?'':'none'">
                            <option value="all">All users (with email)</option>
                            <?php foreach ($users as $u): if (!$u['email']) continue; ?>
                                <option value="<?= (int)$u['id'] ?>"><?= htmlspecialchars($u['username']) ?> &lt;<?= htmlspecialchars($u['email']) ?>&gt;</option>
                            <?php endforeach; ?>
                            <option value="custom">Custom address&hellip;</option>
                        </select>
                    </div>
                    <div class="form-group" id="customToWrap" style="display:none">
                        <label>Email Address</label>
                        <input type="email" name="compose_custom" placeholder="someone@example.com">
                    </div>
                    <div class="form-group">
                        <label>Subject</label>
                        <input type="text" name="compose_subject" required>
                    </div>
                    <div class="form-group">
                        <label>Message</label>
                        <textarea name="compose_body" rows="8" required style="resize:vertical"></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary" style="width:100%">Send Email</button>
                </form>
            </div>

        </div>
    </div>

    <!-- ── SMS tab ── -->
    <div class="tab-panel <?= $tab === 'sms' ? 'active' : '' ?>">
        <div class="sms-grid">

            <!-- Twilio Credentials -->
            <div class="card" style="max-width:100%">
                <h2>Twilio Credentials</h2>
                <p class="subtitle">Stored in site settings. Keep your auth token private.</p>
                <form method="post" action="/admin_settings.php">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">
                    <input type="hidden" name="action" value="sms_credentials">
                    <input type="hidden" name="tab" value="sms">

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
                <form method="post" action="/admin_settings.php">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">
                    <input type="hidden" name="action" value="sms_test">
                    <input type="hidden" name="tab" value="sms">

                    <div class="form-group">
                        <label for="sms_to">To (phone number)</label>
                        <input type="tel" id="sms_to" name="to"
                               placeholder="+12015550199" required>
                    </div>
                    <div class="form-group">
                        <label for="sms_body">Message</label>
                        <textarea id="sms_body" name="body" rows="4"
                                  style="width:100%;resize:vertical"
                                  placeholder="Hello from <?= htmlspecialchars($site_name) ?>!"
                                  required>Hello from <?= htmlspecialchars($site_name) ?>! This is a test message.</textarea>
                    </div>
                    <button type="submit" class="btn btn-primary"
                            <?= (!$twilio_sid || !$twilio_token || !$twilio_from) ? 'disabled title="Configure credentials first"' : '' ?>>
                        Send SMS
                    </button>
                </form>
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

</div>

<!-- ── New User Modal ── -->
<div class="modal-overlay" id="newUserModal" onclick="overlayClick(event)">
    <div class="modal">
        <div class="modal-header">
            <h2>New User</h2>
            <button class="modal-close" onclick="closeUserModal()" title="Close">&#x2715;</button>
        </div>
        <form method="post" action="/admin_settings.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">
            <input type="hidden" name="action" value="add">
            <input type="hidden" name="tab" value="users">
            <div class="form-group">
                <label for="m_username">Username</label>
                <input type="text" id="m_username" name="username" autocomplete="off" required>
            </div>
            <div class="form-group">
                <label for="m_email">Email</label>
                <input type="email" id="m_email" name="email">
            </div>
            <div class="form-group">
                <label for="m_role">Role</label>
                <select id="m_role" name="role">
                    <option value="user">User</option>
                    <option value="admin">Admin</option>
                </select>
            </div>
            <div class="form-group">
                <label for="m_password">Password</label>
                <input type="password" id="m_password" name="password" autocomplete="new-password" required>
            </div>
            <div style="display:flex;gap:.75rem;margin-top:1.5rem">
                <button type="submit" class="btn btn-primary" style="flex:1">Create User</button>
                <button type="button" class="btn btn-outline" onclick="closeUserModal()">Cancel</button>
            </div>
        </form>
    </div>
</div>

<footer>&copy; <?= (new DateTime('now', new DateTimeZone(get_setting('timezone', 'UTC'))))->format('Y') ?> <?= htmlspecialchars($site_name) ?> &nbsp;&mdash;&nbsp; <?= (new DateTime('now', new DateTimeZone(get_setting('timezone', 'UTC'))))->format('F j, Y g:i A') ?></footer>

<script>
function openUserModal() {
    document.getElementById('newUserModal').classList.add('open');
    document.getElementById('m_username').focus();
}
function closeUserModal() {
    document.getElementById('newUserModal').classList.remove('open');
}
function overlayClick(e) {
    if (e.target === document.getElementById('newUserModal')) closeUserModal();
}
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeUserModal();
});

const selectAll = document.getElementById('selectAll');
const bulkBar   = document.getElementById('bulkBar');
const bulkCount = document.getElementById('bulkCount');

function getChecked() { return document.querySelectorAll('.row-check:checked'); }
function updateBulkBar() {
    const n = getChecked().length;
    bulkBar.style.display = n > 0 ? 'flex' : 'none';
    bulkCount.textContent = n;
    const all = document.querySelectorAll('.row-check');
    if (selectAll) {
        selectAll.indeterminate = n > 0 && n < all.length;
        selectAll.checked = n > 0 && n === all.length;
    }
}
function clearSelection() {
    document.querySelectorAll('.row-check').forEach(cb => cb.checked = false);
    if (selectAll) selectAll.checked = false;
    updateBulkBar();
}
if (selectAll) {
    selectAll.addEventListener('change', function() {
        document.querySelectorAll('.row-check').forEach(cb => cb.checked = this.checked);
        updateBulkBar();
    });
}
document.querySelectorAll('.row-check').forEach(cb => cb.addEventListener('change', updateBulkBar));

// ── Appearance tab ─────────────────────────────────────────────────────────────
function syncText(textId, val) {
    document.getElementById(textId).value = val;
}
function syncPicker(pickerId, val) {
    if (/^#[0-9a-fA-F]{6}$/.test(val)) {
        document.getElementById(pickerId).value = val;
    }
}
function resetColor(textId, pickerId, defaultVal) {
    document.getElementById(textId).value = '';
    document.getElementById(pickerId).value = defaultVal;
    updatePreview();
}
function updatePreview() {
    const bg   = document.getElementById('nav_bg_color')   ?.value || '#0f172a';
    const text = document.getElementById('nav_text_color') ?.value || '#ffffff';
    const acc  = document.getElementById('accent_color')   ?.value || '#2563eb';
    const top  = document.getElementById('previewTop');
    const brand = document.getElementById('previewBrand');
    const accentEl = document.getElementById('previewAccent');
    if (top)      top.style.background = /^#[0-9a-fA-F]{6}$/.test(bg)   ? bg   : '#0f172a';
    if (brand)    brand.style.color    = /^#[0-9a-fA-F]{6}$/.test(text) ? text : '#ffffff';
    if (accentEl) accentEl.style.color = /^#[0-9a-fA-F]{6}$/.test(acc)  ? acc  : '#2563eb';
}
</script>
</body>
</html>
