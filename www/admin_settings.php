<?php
require_once __DIR__ . '/auth.php';

$current = require_login();
if ($current['role'] !== 'admin') {
    http_response_code(403);
    exit('Access denied.');
}

$db = get_db();

// ── CSV Export (must happen before any output) ────────────────────────────────
if (($_GET['action'] ?? '') === 'export_users') {
    $rows = $db->query('SELECT id, username, email, phone, role, preferred_contact, notes, created_at, last_login FROM users ORDER BY id')->fetchAll();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="users_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['id', 'username', 'email', 'phone', 'role', 'preferred_contact', 'notes', 'created_at', 'last_login']);
    foreach ($rows as $r) {
        fputcsv($out, [$r['id'], $r['username'], $r['email'] ?? '', $r['phone'] ?? '', $r['role'], $r['preferred_contact'] ?? 'email', $r['notes'] ?? '', $r['created_at'] ?? '', $r['last_login'] ?? '']);
    }
    fclose($out);
    exit;
}

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

$tab = in_array($_GET['tab'] ?? '', ['dashboard', 'general', 'appearance', 'logs', 'users', 'events', 'leagues', 'email', 'sms', 'whatsapp', 'cron', 'backup']) ? $_GET['tab'] : 'dashboard';
$isCommTab = in_array($tab, ['email', 'sms', 'whatsapp']);

// ── POST ─────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $post_tab = $_POST['tab'] ?? 'general';
    if (!csrf_verify()) {
        $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Invalid request token.'];
    } else {
        $action = $_POST['action'] ?? '';

        // ── Backup download (streams file, exits early) ──
        if ($action === 'backup_download') {
            db_log_activity($current['id'], 'downloaded database backup');
            $dbPath = DB_PATH;
            // Close PDO to release locks
            // PDO connection released on exit/redirect
            $filename = 'gamenight_backup_' . date('Y-m-d_His') . '.db';
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . filesize($dbPath));
            readfile($dbPath);
            exit;
        }

        // ── Backup restore (upload) ──
        if ($action === 'backup_restore') {
            if (!isset($_FILES['backup_file']) || $_FILES['backup_file']['error'] !== UPLOAD_ERR_OK) {
                $_SESSION['flash'] = ['type' => 'error', 'msg' => 'No file uploaded.'];
            } else {
                $tmp = $_FILES['backup_file']['tmp_name'];
                // Validate: must be a valid SQLite database with a users table
                try {
                    $testDb = new PDO('sqlite:' . $tmp);
                    $testDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                    $check = $testDb->query("SELECT name FROM sqlite_master WHERE type='table' AND name='users'");
                    if (!$check->fetch()) {
                        throw new Exception('Not a valid GameNight database (missing users table).');
                    }
                    $testDb = null;

                    // Auto-backup current DB before restore
                    $backupPath = DB_PATH . '.before_restore';
                    copy(DB_PATH, $backupPath);

                    // Close current DB connection and replace
                    // PDO connection released on exit/redirect
                    if (copy($tmp, DB_PATH)) {
                        db_log_activity($current['id'], 'restored database from backup');
                        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Database restored successfully. Previous database saved as backup.'];
                    } else {
                        $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Failed to write database file. Check permissions.'];
                    }
                } catch (Exception $e) {
                    $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Invalid backup file: ' . $e->getMessage()];
                }
            }
            $post_tab = 'backup';
        }

        if ($action === 'general') {
            $site_name = trim($_POST['site_name'] ?? '');
            $timezone  = trim($_POST['timezone'] ?? '');
            if ($site_name === '') {
                $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Site name cannot be empty.'];
            } elseif ($timezone !== '' && !in_array($timezone, array_values($tz_offsets))) {
                $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Invalid timezone selected.'];
            } else {
                set_setting('site_name', $site_name);
                $site_url = trim($_POST['site_url'] ?? '');
                set_setting('site_url', $site_url);
                if ($timezone !== '') set_setting('timezone', $timezone);
                set_setting('allow_registration', isset($_POST['allow_registration']) ? '1' : '0');
                set_setting('allow_user_events', isset($_POST['allow_user_events']) ? '1' : '0');
                set_setting('show_landing_page', isset($_POST['show_landing_page']) ? '1' : '0');
                set_setting('show_upcoming_events', isset($_POST['show_upcoming_events']) ? '1' : '0');
                set_setting('show_calendar', isset($_POST['show_calendar']) ? '1' : '0');
                set_setting('allow_maybe_rsvp', isset($_POST['allow_maybe_rsvp']) ? '1' : '0');
                set_setting('notifications_enabled', isset($_POST['notifications_enabled']) ? '1' : '0');
                set_setting('donation_url', trim($_POST['donation_url'] ?? ''));
                set_setting('donation_message', trim($_POST['donation_message'] ?? ''));
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
            $phone    = trim($_POST['phone'] ?? '');
            $phone    = $phone !== '' ? normalize_phone($phone) : '';
            $notes    = trim($_POST['notes'] ?? '');
            if ($username === '' || $password === '') {
                $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Username and password are required.'];
            } elseif (strlen($password) < 12) {
                $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Password must be at least 12 characters.'];
            } else {
                try {
                    $hash = password_hash($password, PASSWORD_BCRYPT);
                    $db->prepare('INSERT INTO users (username, password_hash, email, role, phone, notes) VALUES (?, ?, ?, ?, ?, ?)')
                       ->execute([$username, $hash, $email ?: null, $role, $phone ?: null, $notes ?: null]);
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
                delete_user_account($id);
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
                delete_user_account($id);
                db_log_activity($current['id'], 'deleted user: ' . $urow['username']);
                $deleted++;
            }
            $msg = "Deleted $deleted user" . ($deleted !== 1 ? 's' : '') . '.';
            if ($skipped) $msg .= " $skipped skipped (last admin).";
            $_SESSION['flash'] = ['type' => $deleted > 0 ? 'success' : 'error', 'msg' => $msg];
            $post_tab = 'users';
        }

        if ($action === 'update_user') {
            $id    = (int)($_POST['id'] ?? 0);
            $field = $_POST['field'] ?? '';
            $value = trim($_POST['value'] ?? '');
            $allowed_fields = ['username', 'email', 'phone', 'role', 'preferred_contact', 'notes'];
            if ($id > 0 && in_array($field, $allowed_fields, true)) {
                if ($field === 'role') {
                    if (!in_array($value, ['admin', 'user'], true)) {
                        http_response_code(400); exit;
                    }
                    // Guard: cannot demote last admin
                    if ($value !== 'admin') {
                        $urow = $db->prepare('SELECT role FROM users WHERE id = ?');
                        $urow->execute([$id]);
                        $urow = $urow->fetch();
                        if ($urow && $urow['role'] === 'admin') {
                            $adminCount = (int)$db->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn();
                            if ($adminCount <= 1) { http_response_code(409); exit('last_admin'); }
                        }
                    }
                }
                if ($field === 'preferred_contact' && !in_array($value, ['email','sms','both','none'], true)) {
                    http_response_code(400); exit;
                }
                if ($field === 'phone' && $value !== '') {
                    $value = normalize_phone($value);
                }
                $db->prepare("UPDATE users SET $field = ? WHERE id = ?")->execute([$value ?: null, $id]);
                db_log_activity($current['id'], "admin inline-updated user #$id $field");
            }
            http_response_code(200); exit;
        }

        if ($action === 'bulk_role') {
            $ids  = array_map('intval', (array)($_POST['ids'] ?? []));
            $ids  = array_filter($ids, fn($i) => $i > 0);
            $role = in_array($_POST['role'] ?? '', ['admin', 'user']) ? $_POST['role'] : null;
            if ($role && $ids) {
                $adminCount = (int)$db->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn();
                $skipped = 0;
                foreach ($ids as $id) {
                    if ($role !== 'admin') {
                        $urow = $db->prepare('SELECT role FROM users WHERE id = ?');
                        $urow->execute([$id]);
                        $urow = $urow->fetch();
                        if ($urow && $urow['role'] === 'admin' && $adminCount <= 1) { $skipped++; continue; }
                        if ($urow && $urow['role'] === 'admin') $adminCount--;
                    }
                    $db->prepare("UPDATE users SET role = ? WHERE id = ?")->execute([$role, $id]);
                    db_log_activity($current['id'], "admin bulk set role=$role for user #$id");
                }
                $msg = 'Role updated.';
                if ($skipped) $msg .= " $skipped skipped (last admin).";
                $_SESSION['flash'] = ['type' => 'success', 'msg' => $msg];
            }
            $post_tab = 'users';
        }

        if ($action === 'import_users') {
            $file = $_FILES['csv_file'] ?? null;
            if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
                $_SESSION['flash'] = ['type' => 'error', 'msg' => 'No file uploaded.'];
            } else {
                $handle = fopen($file['tmp_name'], 'r');
                $header = fgetcsv($handle); // skip header row
                $imported = 0; $skipped = 0; $errors = [];
                while (($row = fgetcsv($handle)) !== false) {
                    if (count($row) < 2) continue;
                    // Support both full export format and simple username,email,phone,role,notes
                    // Detect by header: if first col is 'id' treat as full export (skip col 0)
                    $offset = (isset($header[0]) && strtolower(trim($header[0])) === 'id') ? 1 : 0;
                    $username = strtolower(trim($row[$offset] ?? ''));
                    $email    = strtolower(trim($row[$offset + 1] ?? ''));
                    $phone    = trim($row[$offset + 2] ?? '');
                    $role     = in_array(trim($row[$offset + 3] ?? ''), ['admin','user']) ? trim($row[$offset + 3]) : 'user';
                    $pref     = in_array(trim($row[$offset + 4] ?? ''), ['email','sms','both','none']) ? trim($row[$offset + 4]) : 'email';
                    $notes    = trim($row[$offset + 5] ?? '');
                    if ($username === '') continue;
                    $phone = $phone !== '' ? normalize_phone($phone) : '';
                    // Check if username already exists
                    $exists = $db->prepare('SELECT id FROM users WHERE LOWER(username)=?');
                    $exists->execute([$username]);
                    if ($exists->fetch()) { $skipped++; continue; }
                    // Generate a random temp password
                    $tmp_pw = bin2hex(random_bytes(8));
                    $hash   = password_hash($tmp_pw, PASSWORD_BCRYPT);
                    try {
                        $db->prepare('INSERT INTO users (username, password_hash, email, phone, role, preferred_contact, notes, must_change_password, email_verified) VALUES (?,?,?,?,?,?,?,1,1)')
                           ->execute([$username, $hash, $email ?: null, $phone ?: null, $role, $pref, $notes ?: null]);
                        db_log_activity($current['id'], "imported user: $username");
                        $imported++;
                    } catch (PDOException $e) {
                        $errors[] = $username;
                    }
                }
                fclose($handle);
                $msg = "Imported $imported user" . ($imported !== 1 ? 's' : '') . ".";
                if ($skipped) $msg .= " $skipped skipped (already exist).";
                if ($errors)  $msg .= " Failed: " . implode(', ', $errors) . ".";
                $_SESSION['flash'] = ['type' => $imported > 0 ? 'success' : 'error', 'msg' => $msg];
            }
            $post_tab = 'users';
        }

        if ($action === 'delete_event') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                $erow = $db->prepare('SELECT title FROM events WHERE id = ?');
                $erow->execute([$id]);
                $erow = $erow->fetch();
                $db->prepare('DELETE FROM event_invites WHERE event_id = ?')->execute([$id]);
                $db->prepare('DELETE FROM event_exceptions WHERE event_id = ?')->execute([$id]);
                $db->prepare('DELETE FROM events WHERE id = ?')->execute([$id]);
                db_log_activity($current['id'], 'deleted event: ' . ($erow['title'] ?? $id));
                $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Event deleted.'];
            }
            $post_tab = 'events';
        }

        if ($action === 'update_event') {
            $id    = (int)($_POST['id'] ?? 0);
            $field = $_POST['field'] ?? '';
            $value = trim($_POST['value'] ?? '');
            $allowed_fields = ['title', 'start_date', 'end_date', 'start_time', 'end_time'];
            if ($id > 0 && in_array($field, $allowed_fields, true)) {
                $db->prepare("UPDATE events SET $field = ? WHERE id = ?")->execute([$value ?: null, $id]);
                db_log_activity($current['id'], "updated event #$id $field");
                $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Event updated.'];
            }
            $post_tab = 'events';
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
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = $finfo->file($tmp);
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
            require_once __DIR__ . '/sms.php';
            $prov = trim($_POST['sms_provider'] ?? 'twilio');
            $providers = get_sms_providers();
            if (!isset($providers[$prov])) $prov = 'twilio';
            set_setting('sms_provider', $prov);
            foreach ($providers[$prov]['fields'] as $key => $def) {
                $val = trim($_POST[$key] ?? '');
                if ($def['type'] === 'password' && $val === '') continue; // keep current
                set_setting($key, $val);
            }
            db_log_activity($current['id'], "updated SMS credentials ($prov)");
            $_SESSION['flash'] = ['type' => 'success', 'msg' => ucfirst($prov) . ' credentials saved.'];
            $post_tab = 'sms';
        }

        if ($action === 'sms_test') {
            require_once __DIR__ . '/sms.php';
            $to   = normalize_phone(trim($_POST['to']   ?? ''));
            $body = trim($_POST['body'] ?? '');

            if (!$to || !$body) {
                $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Phone number and message are required.'];
            } else {
                $err = send_sms($to, $body);
                if ($err === null) {
                    db_log_activity($current['id'], "sent test SMS to $to");
                    $_SESSION['flash'] = ['type' => 'success', 'msg' => 'SMS sent successfully!'];
                } else {
                    $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Send failed: ' . $err];
                }
            }
            $post_tab = 'sms';
        }

        if ($action === 'url_shortener') {
            $enabled = isset($_POST['url_shortener_enabled']) ? '1' : '0';
            set_setting('url_shortener_enabled', $enabled);
            $url_shortener_enabled = $enabled === '1';
            db_log_activity($current['id'], 'updated URL shortener setting');
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'URL shortener ' . ($enabled === '1' ? 'enabled.' : 'disabled.')];
            $post_tab = 'sms';
        }

        if ($action === 'sms_clear_log') {
            get_db()->exec('DELETE FROM sms_log');
            db_log_activity($current['id'], 'cleared SMS log');
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'SMS log cleared.'];
            $post_tab = 'sms';
        }

        if ($action === 'cron_settings') {
            $tok = trim($_POST['cron_token'] ?? '');
            if ($tok !== '') set_setting('cron_token', $tok);
            db_log_activity($current['id'], 'updated cron token');
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Cron token saved.'];
            $post_tab = 'cron';
        }

        if ($action === 'wa_credentials') {
            $url     = trim($_POST['waha_url'] ?? 'http://waha:3000');
            $session = trim($_POST['waha_session'] ?? 'default');
            set_setting('waha_url', $url);
            set_setting('waha_session', $session);
            db_log_activity($current['id'], 'updated WAHA WhatsApp settings');
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'WhatsApp settings saved.'];
            $post_tab = 'whatsapp';
        }

        if ($action === 'wa_test') {
            require_once __DIR__ . '/sms.php';
            $to   = normalize_phone(trim($_POST['to'] ?? ''));
            $body = trim($_POST['body'] ?? '');
            if (!$to || !$body) {
                $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Phone number and message are required.'];
            } else {
                $err = send_whatsapp($to, $body);
                if ($err === null) {
                    db_log_activity($current['id'], "sent test WhatsApp to $to");
                    $_SESSION['flash'] = ['type' => 'success', 'msg' => 'WhatsApp message sent!'];
                } else {
                    $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Send failed: ' . $err];
                }
            }
            $post_tab = 'whatsapp';
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
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = $finfo->file($tmp);
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
    SELECT a.action, a.ip, a.created_at, a.severity, COALESCE(u.username, '—') AS username
    FROM   activity_log a
    LEFT JOIN users u ON u.id = a.user_id AND a.user_id != 0
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

// SMS provider settings
require_once __DIR__ . '/sms.php';
$sms_provider  = get_setting('sms_provider', 'twilio');
$sms_providers = get_sms_providers();
$sms_sid       = get_setting('sms_sid')   ?: $twilio_sid;
$sms_token     = get_setting('sms_token') ?: $twilio_token;
$sms_from      = get_setting('sms_from')  ?: $twilio_from;
$sms_configured = $sms_token && $sms_from;
$url_shortener_enabled = get_setting('url_shortener_enabled') === '1';

// WhatsApp (WAHA) settings
$waha_url        = get_setting('waha_url', 'http://waha:3000');
$waha_session    = get_setting('waha_session', 'default');

// ── Users data ───────────────────────────────────────────────────────────────
$users = $db->query('SELECT id, username, email, phone, role, preferred_contact, notes, created_at, last_login FROM users ORDER BY id')->fetchAll();

// ── Events data ──────────────────────────────────────────────────────────────
$events_filter = trim($_GET['ef'] ?? '');
$events_sort   = in_array($_GET['es'] ?? '', ['id','title','start_date','creator','invites']) ? $_GET['es'] : 'start_date';
$events_dir    = ($_GET['ed'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';
$events_sql = "
    SELECT e.id, e.title, e.start_date, e.end_date, e.start_time, e.end_time,
           COALESCE(u.username,'—') AS creator,
           (SELECT COUNT(*) FROM event_invites WHERE event_id = e.id) AS invites
    FROM   events e
    LEFT JOIN users u ON u.id = e.created_by
";
$events_params = [];
if ($events_filter !== '') {
    $events_sql .= " WHERE e.title LIKE ? OR COALESCE(u.username,'') LIKE ?";
    $events_params = ["%$events_filter%", "%$events_filter%"];
}
$col_map = ['id'=>'e.id','title'=>'e.title','start_date'=>'e.start_date','creator'=>'u.username','invites'=>'invites'];
$events_sql .= " ORDER BY {$col_map[$events_sort]} $events_dir";
$stmt = $db->prepare($events_sql);
$stmt->execute($events_params);
$admin_events = $stmt->fetchAll();

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
        .pk-toggle-input { display:none; }
        .pk-toggle-slider { position:relative;width:36px;height:20px;background:#cbd5e1;border-radius:99px;transition:background .2s;flex-shrink:0;cursor:pointer; }
        .pk-toggle-slider::after { content:'';position:absolute;top:2px;left:2px;width:16px;height:16px;background:#fff;border-radius:50%;transition:transform .2s;box-shadow:0 1px 3px rgba(0,0,0,.2); }
        .pk-toggle-input:checked + .pk-toggle-slider { background:#22c55e; }
        .pk-toggle-input:checked + .pk-toggle-slider::after { transform:translateX(16px); }
        .form-group .setting-toggle { display:flex !important;align-items:center;gap:.6rem;cursor:pointer;font-weight:500;margin-bottom:0; }
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

        .subtabs { display:flex; gap:0; margin-bottom:1.5rem; }
        .subtab-btn {
            padding:.45rem 1rem; font-size:.82rem; font-weight:500; color:#64748b;
            background:#f8fafc; border:1px solid #e2e8f0; border-bottom:none;
            cursor:pointer; text-decoration:none; margin-right:-1px;
        }
        .subtab-btn:first-child { border-radius:8px 0 0 0; }
        .subtab-btn:last-child  { border-radius:0 8px 0 0; margin-right:0; }
        .subtab-btn.active { background:#fff; color:#2563eb; font-weight:600; border-bottom:1px solid #fff; position:relative; z-index:1; }

        .pagination { display:flex; gap:.4rem; margin-top:1rem; flex-wrap:wrap; }
        .pagination a, .pagination span {
            display:inline-block; padding:.3rem .75rem; border-radius:6px;
            font-size:.82rem; border:1px solid #e2e8f0;
        }
        .pagination a { color:#2563eb; }
        .pagination a:hover { background:#eff6ff; text-decoration:none; }
        .pagination .current { background:#2563eb; color:#fff; border-color:#2563eb; }

        /* ── Users tab ── */
        #usersTabPanel { margin: 0 -1.5rem; }
        .ug-wrap {
            width: 100%; overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            border: 1px solid #e2e8f0; border-radius: 10px; background: #fff;
        }
        .ug-toolbar {
            display: flex; align-items: center; gap: .75rem; flex-wrap: wrap;
            padding: .9rem 1rem; border-bottom: 1px solid #e2e8f0;
            background: #f8fafc; border-radius: 10px 10px 0 0;
        }
        #usersGrid {
            border-collapse: collapse; width: 100%; font-size: .85rem;
        }
        #usersGrid th {
            background: #f1f5f9; color: #475569; font-weight: 600;
            font-size: .78rem; text-transform: uppercase; letter-spacing: .04em;
            padding: .6rem .75rem; border-bottom: 2px solid #e2e8f0;
            border-right: 1px solid #e2e8f0; white-space: nowrap;
            position: sticky; top: 0; z-index: 2;
        }
        #usersGrid td {
            padding: 0; border-bottom: 1px solid #e2e8f0;
            border-right: 1px solid #e2e8f0; vertical-align: middle;
        }
        #usersGrid tr:last-child td { border-bottom: none; }
        #usersGrid td:last-child, #usersGrid th:last-child { border-right: none; }
        #usersGrid tr:hover td { background: #f8fafc; }
        #usersGrid tr.ug-selected td { background: #eff6ff !important; }
        .ug-cell-input {
            width: 100%; padding: .42rem .6rem; border: none;
            background: transparent; font-size: .85rem; font-family: inherit;
            color: #1e293b; box-sizing: border-box; outline: none; cursor: text;
        }
        .ug-cell-input:focus {
            background: #eff6ff; outline: 2px solid #2563eb;
            outline-offset: -2px; border-radius: 2px;
        }
        .ug-cell-select {
            width: 100%; padding: .42rem .4rem; border: none;
            background: transparent; font-size: .85rem; font-family: inherit;
            color: #1e293b; cursor: pointer; box-sizing: border-box;
            outline: none; appearance: auto;
        }
        .ug-cell-select:focus {
            background: #eff6ff; outline: 2px solid #2563eb;
            outline-offset: -2px; border-radius: 2px;
        }
        .ug-id-cell   { color: #94a3b8; font-size: .78rem; text-align: center; width: 44px; }
        .ug-cb-cell   { width: 36px; text-align: center; }
        .ug-act-cell  { text-align: center; width: 44px; }
        .ug-del-btn {
            background: none; border: none; cursor: pointer;
            color: #ef4444; font-size: 1rem; padding: .3rem .5rem;
            border-radius: 5px; line-height: 1;
        }
        .ug-del-btn:hover { background: #fee2e2; }
        .ug-edit-btn {
            background: none; border: none; cursor: pointer;
            color: #2563eb; font-size: .8rem; padding: .3rem .5rem;
            border-radius: 5px; line-height: 1; text-decoration: none;
            display: inline-block;
        }
        .ug-edit-btn:hover { background: #eff6ff; }
        #ugBulkBar {
            display: none; align-items: center; gap: .75rem; flex-wrap: wrap;
            background: #eff6ff; border: 1px solid #bfdbfe;
            border-radius: 8px; padding: .5rem 1rem;
            font-size: .875rem; color: #1e40af; margin: .75rem 1.5rem;
        }
        #ugBulkBar .bulk-label { font-weight: 600; }
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
        .ug-save-indicator {
            display: none; position: fixed; bottom: 1.5rem; right: 1.5rem;
            background: #16a34a; color: #fff; padding: .5rem 1rem;
            border-radius: 8px; font-size: .85rem; font-weight: 600;
            box-shadow: 0 4px 12px rgba(0,0,0,.15); z-index: 999;
            animation: fadeInUp .2s ease;
        }
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

        /* ── Mobile/tablet touch optimization ── */
        @media (max-width: 1024px) {
            .tabs { overflow-x:auto;-webkit-overflow-scrolling:touch;flex-wrap:nowrap; }
            .tab-btn { white-space:nowrap;padding:.55rem .85rem;font-size:.85rem; }
            #usersGrid { font-size:.9rem; }
            .ug-cell-input { font-size:1rem;padding:.5rem .6rem;min-height:44px; }
            .ug-cell-select { font-size:1rem;padding:.5rem .4rem;min-height:44px; }
            input[type="checkbox"] { width:22px;height:22px; }
            .ug-del-btn, .ug-edit-btn { padding:.4rem .6rem; }
            .btn-icon { width:40px;height:40px; }
            .ug-toolbar { padding:.75rem .75rem;gap:.5rem; }
            .ug-toolbar input { font-size:1rem;min-height:44px; }
            .ug-toolbar .btn, .ug-toolbar button { min-height:44px;font-size:.85rem; }
            #ugBulkBar { margin:.75rem .75rem;font-size:.9rem; }
            #ugBulkBar select { font-size:1rem;min-height:40px;padding:.35rem .5rem; }
            #ugBulkBar button { min-height:40px; }
            .modal select { font-size:1rem;min-height:44px; }
        }
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
        <a href="/admin_settings.php?tab=events"
           class="tab-btn <?= $tab === 'events' ? 'active' : '' ?>">Events</a>
        <a href="/admin_settings.php?tab=leagues"
           class="tab-btn <?= $tab === 'leagues' ? 'active' : '' ?>">Leagues</a>
        <a href="/admin_settings.php?tab=email"
           class="tab-btn <?= $isCommTab ? 'active' : '' ?>">Communication</a>
        <a href="/admin_settings.php?tab=cron"
           class="tab-btn <?= $tab === 'cron' ? 'active' : '' ?>">Cron</a>
        <a href="/admin_settings.php?tab=backup"
           class="tab-btn <?= $tab === 'backup' ? 'active' : '' ?>">Backup</a>
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
            <div class="stat-card">
                <div class="label">Version</div>
                <div class="value" style="font-size:1.1rem"><?= htmlspecialchars(APP_VERSION) ?></div>
            </div>
        </div>

        <div style="display:flex;gap:.75rem;margin-top:1.5rem;flex-wrap:wrap">
            <a href="/admin_settings.php?tab=users" class="btn btn-primary">Manage Users</a>
            <a href="/admin_settings.php?tab=events" class="btn btn-outline">Manage Events</a>
            <a href="/admin_settings.php?tab=logs" class="btn btn-outline">View Logs</a>
            <a href="/phpadmin/" class="btn btn-outline" target="_blank">Database Admin</a>
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
                    <label for="site_url">Site URL</label>
                    <input type="url" id="site_url" name="site_url"
                           value="<?= htmlspecialchars(get_setting('site_url')) ?>"
                           placeholder="https://yourdomain.com"
                           autocomplete="off">
                    <p class="hint">Full URL (e.g. <code>https://gamenight.example.com</code>). Used in emails and notifications. Leave blank to auto-detect from request.</p>
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
                    <label class="setting-toggle">
                        <input type="checkbox" name="allow_registration" value="1" class="pk-toggle-input"
                               <?= get_setting('allow_registration', '1') === '1' ? 'checked' : '' ?>>
                        <span class="pk-toggle-slider"></span>
                        Allow new user registration
                    </label>
                    <p class="hint">When off, the Sign Up page returns a 403 and the link is hidden from the login page.</p>
                </div>
                <div class="form-group" style="margin-top:.5rem">
                    <label class="setting-toggle">
                        <input type="checkbox" name="allow_user_events" value="1" class="pk-toggle-input"
                               <?= get_setting('allow_user_events', '0') === '1' ? 'checked' : '' ?>>
                        <span class="pk-toggle-slider"></span>
                        Allow users to create events
                    </label>
                    <p class="hint">When on, registered users can create events and invite others. Users can only edit/delete their own events.</p>
                </div>
                <div class="form-group" style="margin-top:.5rem">
                    <label class="setting-toggle">
                        <input type="checkbox" name="show_landing_page" value="1" class="pk-toggle-input"
                               <?= get_setting('show_landing_page', '0') === '1' ? 'checked' : '' ?>>
                        <span class="pk-toggle-slider"></span>
                        Show Landing Page
                    </label>
                    <p class="hint">When on, visitors who are not logged in see a marketing-style landing page instead of the posts feed. Logged-in users always see the normal home page.</p>
                </div>
                <div class="form-group" style="margin-top:.5rem">
                    <label class="setting-toggle">
                        <input type="checkbox" name="show_upcoming_events" value="1" class="pk-toggle-input"
                               <?= get_setting('show_upcoming_events', '1') === '1' ? 'checked' : '' ?>>
                        <span class="pk-toggle-slider"></span>
                        Show &ldquo;Upcoming Events&rdquo; on the landing page
                    </label>
                    <p class="hint">When off, the upcoming-events section is hidden for all visitors.</p>
                </div>
                <div class="form-group" style="margin-top:.5rem">
                    <label class="setting-toggle">
                        <input type="checkbox" name="show_calendar" value="1" class="pk-toggle-input"
                               <?= get_setting('show_calendar', '1') === '1' ? 'checked' : '' ?>>
                        <span class="pk-toggle-slider"></span>
                        Enable Calendar
                    </label>
                    <p class="hint">When off, the Calendar page is disabled and the nav link is hidden.</p>
                </div>
                <div class="form-group" style="margin-top:.5rem">
                    <label class="setting-toggle">
                        <input type="checkbox" name="allow_maybe_rsvp" value="1" class="pk-toggle-input"
                               <?= get_setting('allow_maybe_rsvp', '1') === '1' ? 'checked' : '' ?>>
                        <span class="pk-toggle-slider"></span>
                        Allow &ldquo;Maybe&rdquo; RSVP response
                    </label>
                    <p class="hint">When off, the Maybe option is removed from RSVP buttons and invite emails.</p>
                </div>
                <div class="form-group" style="margin-top:.5rem">
                    <label class="setting-toggle">
                        <input type="checkbox" name="notifications_enabled" value="1" class="pk-toggle-input"
                               <?= get_setting('notifications_enabled', '0') === '1' ? 'checked' : '' ?>>
                        <span class="pk-toggle-slider"></span>
                        Enable Notifications
                    </label>
                    <p class="hint">When off, no email, SMS, or WhatsApp notifications will be sent (invites, reminders, updates). Test messages from the Email/SMS tabs still work.</p>
                </div>

                <hr style="border:none;border-top:1px solid #e2e8f0;margin:1rem 0">
                <h3 style="font-size:.95rem;margin:0 0 .5rem">Donation Banner</h3>
                <p class="hint" style="margin-bottom:.75rem">When a donation URL is set, a banner appears on the home page above the posts and a small link appears in the footer.</p>
                <div class="form-group">
                    <label for="donation_url">Donation URL</label>
                    <input type="url" name="donation_url" id="donation_url"
                           value="<?= htmlspecialchars(get_setting('donation_url', '')) ?>"
                           placeholder="https://paypal.me/yourname or https://venmo.com/yourname">
                    <p class="hint">PayPal.me, Venmo, Cash App, Buy Me a Coffee, etc. Leave blank to disable.</p>
                </div>
                <div class="form-group">
                    <label for="donation_message">Donation Message</label>
                    <input type="text" name="donation_message" id="donation_message"
                           value="<?= htmlspecialchars(get_setting('donation_message', '')) ?>"
                           placeholder="Enjoying Game Night? Help keep the lights on.">
                    <p class="hint">Custom text shown on the banner. If blank, a default message is used.</p>
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
                    <tr<?= $a['severity'] === 'critical' ? ' style="background:#fef2f2;color:#991b1b;font-weight:500"' : '' ?>>
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
    <div class="tab-panel <?= $tab === 'users' ? 'active' : '' ?>" id="usersTabPanel">

        <div class="ug-wrap">
            <div class="ug-toolbar">
                <input type="search" id="ugFilter" placeholder="Filter users…"
                       style="padding:.45rem .8rem;border:1.5px solid #e2e8f0;border-radius:7px;font-size:.85rem;width:220px">
                <span style="color:#64748b;font-size:.82rem;margin-left:auto" id="ugCount">
                    <?= count($users) ?> user<?= count($users) !== 1 ? 's' : '' ?>
                </span>
                <span style="color:#94a3b8;font-size:.75rem">Click any cell to edit &bull; Changes save automatically</span>
                <a href="/admin_settings.php?action=export_users" class="btn btn-outline btn-sm" style="padding:.4rem .85rem;font-size:.82rem">&#8681; Export CSV</a>
                <button class="btn btn-outline btn-sm" onclick="document.getElementById('ugImportWrap').style.display=document.getElementById('ugImportWrap').style.display==='none'?'flex':'none'" style="padding:.4rem .85rem;font-size:.82rem">&#8679; Import CSV</button>
                <button class="btn btn-primary btn-sm" onclick="openUserModal()" style="padding:.4rem .85rem;font-size:.82rem">+ New User</button>
            </div>

            <div id="ugImportWrap" style="display:none;align-items:center;gap:.75rem;flex-wrap:wrap;padding:.75rem 1rem;border-bottom:1px solid #e2e8f0;background:#f8fafc">
                <form method="post" action="/admin_settings.php" enctype="multipart/form-data"
                      style="display:flex;align-items:center;gap:.6rem;flex-wrap:wrap">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">
                    <input type="hidden" name="action" value="import_users">
                    <input type="hidden" name="tab" value="users">
                    <input type="file" name="csv_file" accept=".csv" required
                           style="font-size:.82rem;border:1.5px solid #e2e8f0;border-radius:7px;padding:.3rem .5rem;background:#fff">
                    <button type="submit" class="btn btn-primary btn-sm" style="padding:.4rem .85rem;font-size:.82rem">Import</button>
                </form>
                <span style="font-size:.78rem;color:#94a3b8">
                    CSV columns: <code>username, email, phone, role, preferred_contact, notes</code>
                    &bull; Existing usernames are skipped &bull; Imported users must change password on first login
                    &bull; <a href="/sample_users.csv" download style="color:#2563eb">Download sample CSV</a>
                </span>
            </div>

            <div id="ugBulkBar">
                <span class="bulk-label"><span id="ugBulkCount">0</span> selected</span>
                <form id="ugBulkDeleteForm" method="post" action="/admin_settings.php"
                      onsubmit="return confirm('Delete selected users? This cannot be undone.')">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">
                    <input type="hidden" name="action" value="bulk_delete">
                    <input type="hidden" name="tab" value="users">
                    <button type="submit" class="btn btn-sm" style="background:#ef4444;color:#fff">Delete Selected</button>
                </form>
                <form id="ugBulkRoleForm" method="post" action="/admin_settings.php" style="display:flex;gap:.4rem;align-items:center">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">
                    <input type="hidden" name="action" value="bulk_role">
                    <input type="hidden" name="tab" value="users">
                    <select name="role" style="padding:.3rem .5rem;border:1px solid #bfdbfe;border-radius:6px;font-size:.82rem;background:#fff">
                        <option value="user">Set role: User</option>
                        <option value="admin">Set role: Admin</option>
                    </select>
                    <button type="submit" class="btn btn-sm btn-outline">Apply</button>
                </form>
                <button class="btn btn-sm btn-outline" onclick="ugClearSelection()">Clear</button>
            </div>

            <table id="usersGrid">
                <thead>
                    <tr>
                        <th class="ug-cb-cell"><input type="checkbox" id="ugSelectAll" title="Select all"></th>
                        <th class="ug-id-cell">#</th>
                        <th style="min-width:130px">Username</th>
                        <th style="min-width:180px">Email</th>
                        <th style="min-width:120px">Phone</th>
                        <th style="min-width:90px">Role</th>
                        <th style="min-width:110px">Notification</th>
                        <th style="min-width:200px">Notes</th>
                        <th style="min-width:120px">Last Login</th>
                        <th class="ug-act-cell"></th>
                        <th class="ug-act-cell"></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($users as $u): $uid = (int)$u['id']; $isSelf = $uid === (int)$current['id']; ?>
                    <tr data-id="<?= $uid ?>" data-username="<?= htmlspecialchars(strtolower($u['username'])) ?>">
                        <td class="ug-cb-cell">
                            <?php if (!$isSelf): ?>
                            <input type="checkbox" class="ug-row-check" value="<?= $uid ?>"
                                   form="ugBulkDeleteForm">
                            <?php endif; ?>
                        </td>
                        <td class="ug-id-cell"><?= $uid ?></td>

                        <td><input class="ug-cell-input" type="text"
                                data-field="username" data-id="<?= $uid ?>"
                                value="<?= htmlspecialchars($u['username']) ?>"></td>

                        <td><input class="ug-cell-input" type="email"
                                data-field="email" data-id="<?= $uid ?>"
                                value="<?= htmlspecialchars($u['email'] ?? '') ?>"></td>

                        <td><input class="ug-cell-input" type="tel"
                                data-field="phone" data-id="<?= $uid ?>"
                                value="<?= htmlspecialchars($u['phone'] ?? '') ?>"></td>

                        <td>
                            <select class="ug-cell-select" data-field="role" data-id="<?= $uid ?>"
                                    <?= $isSelf ? 'disabled title="Cannot change your own role"' : '' ?>>
                                <option value="user"  <?= $u['role'] === 'user'  ? 'selected' : '' ?>>user</option>
                                <option value="admin" <?= $u['role'] === 'admin' ? 'selected' : '' ?>>admin</option>
                            </select>
                        </td>

                        <td>
                            <select class="ug-cell-select" data-field="preferred_contact" data-id="<?= $uid ?>">
                                <?php foreach (['email'=>'email','sms'=>'sms','both'=>'both','none'=>'none'] as $v=>$l): ?>
                                <option value="<?= $v ?>" <?= ($u['preferred_contact'] ?? 'email') === $v ? 'selected' : '' ?>><?= $l ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>

                        <td><input class="ug-cell-input" type="text"
                                data-field="notes" data-id="<?= $uid ?>"
                                value="<?= htmlspecialchars($u['notes'] ?? '') ?>"
                                placeholder="—"></td>

                        <td style="padding:.45rem .6rem;color:#64748b;font-size:.8rem;white-space:nowrap">
                            <?= htmlspecialchars($u['last_login'] ?? 'Never') ?>
                        </td>

                        <td class="ug-act-cell">
                            <a href="/user_edit.php?id=<?= $uid ?>" class="ug-edit-btn" title="Full edit">&#9881;</a>
                        </td>

                        <td class="ug-act-cell">
                            <?php if (!$isSelf): ?>
                            <form method="post" action="/admin_settings.php"
                                  onsubmit="return confirm('Delete <?= addslashes(htmlspecialchars($u['username'])) ?>? This cannot be undone.')">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="tab" value="users">
                                <input type="hidden" name="id" value="<?= $uid ?>">
                                <button type="submit" class="ug-del-btn" title="Delete">&#128465;</button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($users)): ?>
                    <tr><td colspan="11" style="text-align:center;color:#94a3b8;padding:2rem">No users found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="ug-save-indicator" id="ugSaveIndicator">Saved</div>

        <script>
        (function () {
            const csrf = <?= json_encode($token) ?>;
            const ind  = document.getElementById('ugSaveIndicator');
            let saveTimer;

            function showSaved() {
                ind.style.display = 'block';
                clearTimeout(saveTimer);
                saveTimer = setTimeout(() => { ind.style.display = 'none'; }, 1800);
            }

            function saveField(id, field, value, el) {
                const fd = new FormData();
                fd.append('csrf_token', csrf);
                fd.append('action', 'update_user');
                fd.append('tab', 'users');
                fd.append('id', id);
                fd.append('field', field);
                fd.append('value', value);
                fetch('/admin_settings.php', { method: 'POST', body: fd })
                    .then(r => {
                        if (r.ok) { showSaved(); }
                        else if (r.status === 409 && el) {
                            // last admin — revert
                            el.value = el.dataset.orig;
                            alert('Cannot demote the last admin.');
                        }
                    });
            }

            // Text inputs — save on change (blur or enter)
            document.querySelectorAll('#usersGrid .ug-cell-input').forEach(inp => {
                inp.dataset.orig = inp.value;
                inp.addEventListener('change', function () {
                    saveField(this.dataset.id, this.dataset.field, this.value, this);
                    this.dataset.orig = this.value;
                });
            });

            // Selects — save immediately
            document.querySelectorAll('#usersGrid .ug-cell-select').forEach(sel => {
                sel.dataset.orig = sel.value;
                sel.addEventListener('change', function () {
                    saveField(this.dataset.id, this.dataset.field, this.value, this);
                    this.dataset.orig = this.value;
                });
            });

            // ── Multi-select ──────────────────────────────────────────────
            const selectAll  = document.getElementById('ugSelectAll');
            const bulkBar    = document.getElementById('ugBulkBar');
            const bulkCount  = document.getElementById('ugBulkCount');
            const deleteForm = document.getElementById('ugBulkDeleteForm');
            const roleForm   = document.getElementById('ugBulkRoleForm');

            function getChecked() { return document.querySelectorAll('.ug-row-check:checked'); }

            function syncHiddenIds() {
                // keep both bulk forms' ids[] in sync
                [deleteForm, roleForm].forEach(form => {
                    form.querySelectorAll('input[name="ids[]"]').forEach(i => i.remove());
                    getChecked().forEach(cb => {
                        const h = document.createElement('input');
                        h.type = 'hidden'; h.name = 'ids[]'; h.value = cb.value;
                        form.appendChild(h);
                    });
                });
            }

            function updateBulkBar() {
                const n   = getChecked().length;
                const all = document.querySelectorAll('.ug-row-check');
                bulkBar.style.display = n > 0 ? 'flex' : 'none';
                bulkCount.textContent = n;
                if (selectAll) {
                    selectAll.indeterminate = n > 0 && n < all.length;
                    selectAll.checked = n > 0 && n === all.length;
                }
                // highlight rows
                document.querySelectorAll('#usersGrid tbody tr').forEach(tr => {
                    const cb = tr.querySelector('.ug-row-check');
                    tr.classList.toggle('ug-selected', !!(cb && cb.checked));
                });
                syncHiddenIds();
            }

            window.ugClearSelection = function () {
                document.querySelectorAll('.ug-row-check').forEach(cb => cb.checked = false);
                if (selectAll) selectAll.checked = false;
                updateBulkBar();
            };

            if (selectAll) {
                selectAll.addEventListener('change', function () {
                    document.querySelectorAll('.ug-row-check').forEach(cb => cb.checked = this.checked);
                    updateBulkBar();
                });
            }
            document.querySelectorAll('.ug-row-check').forEach(cb => cb.addEventListener('change', updateBulkBar));

            // ── Client-side filter ────────────────────────────────────────
            document.getElementById('ugFilter').addEventListener('input', function () {
                const q = this.value.toLowerCase();
                let visible = 0;
                document.querySelectorAll('#usersGrid tbody tr[data-id]').forEach(tr => {
                    const text = tr.querySelectorAll('.ug-cell-input, .ug-cell-select');
                    let match = !q;
                    if (q) {
                        tr.querySelectorAll('.ug-cell-input').forEach(inp => {
                            if (inp.value.toLowerCase().includes(q)) match = true;
                        });
                        tr.querySelectorAll('.ug-cell-select').forEach(sel => {
                            if (sel.value.toLowerCase().includes(q)) match = true;
                        });
                    }
                    tr.style.display = match ? '' : 'none';
                    if (match) visible++;
                });
                document.getElementById('ugCount').textContent = visible + ' user' + (visible !== 1 ? 's' : '');
            });
        })();
        </script>

    </div>

    <!-- ── Events tab ── -->
    <div class="tab-panel <?= $tab === 'events' ? 'active' : '' ?>" id="eventsTabPanel">
        <style>
            /* Break out of the 960px dash-wrap for the events grid */
            #eventsTabPanel { margin: 0 -1.5rem; }
            .ev-grid-wrap {
                width: 100%;
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
                border: 1px solid #e2e8f0;
                border-radius: 10px;
                background: #fff;
            }
            .ev-toolbar {
                display: flex;
                align-items: center;
                gap: .75rem;
                flex-wrap: wrap;
                padding: .9rem 1rem;
                border-bottom: 1px solid #e2e8f0;
                background: #f8fafc;
                border-radius: 10px 10px 0 0;
            }
            #eventsGrid {
                border-collapse: collapse;
                width: 100%;
                font-size: .85rem;
            }
            #eventsGrid th {
                background: #f1f5f9;
                color: #475569;
                font-weight: 600;
                font-size: .78rem;
                text-transform: uppercase;
                letter-spacing: .04em;
                padding: .6rem .75rem;
                border-bottom: 2px solid #e2e8f0;
                border-right: 1px solid #e2e8f0;
                white-space: nowrap;
                position: sticky;
                top: 0;
                z-index: 2;
            }
            #eventsGrid th a { color: inherit; text-decoration: none; }
            #eventsGrid th a:hover { color: #2563eb; }
            #eventsGrid td {
                padding: 0;
                border-bottom: 1px solid #e2e8f0;
                border-right: 1px solid #e2e8f0;
                vertical-align: middle;
            }
            #eventsGrid tr:last-child td { border-bottom: none; }
            #eventsGrid td:last-child, #eventsGrid th:last-child { border-right: none; }
            #eventsGrid tr:hover td { background: #f8fafc; }

            /* Cell contents */
            .ev-cell {
                display: block;
                width: 100%;
                padding: .45rem .75rem;
                box-sizing: border-box;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }
            /* Editable cells — text inputs */
            .ev-cell-input {
                width: 100%;
                padding: .42rem .6rem;
                border: none;
                background: transparent;
                font-size: .85rem;
                font-family: inherit;
                color: #1e293b;
                box-sizing: border-box;
                outline: none;
                cursor: text;
            }
            .ev-cell-input:focus {
                background: #eff6ff;
                outline: 2px solid #2563eb;
                outline-offset: -2px;
                border-radius: 2px;
            }
            /* Editable cells — select boxes */
            .ev-cell-select {
                width: 100%;
                padding: .42rem .4rem;
                border: none;
                background: transparent;
                font-size: .85rem;
                font-family: inherit;
                color: #1e293b;
                cursor: pointer;
                box-sizing: border-box;
                outline: none;
                appearance: auto;
            }
            .ev-cell-select:focus {
                background: #eff6ff;
                outline: 2px solid #2563eb;
                outline-offset: -2px;
                border-radius: 2px;
            }
            .ev-id-cell { color: #94a3b8; font-size: .78rem; text-align: center; width: 44px; }
            .ev-invites-cell { text-align: center; width: 60px; color: #475569; }
            .ev-creator-cell { color: #475569; }
            .ev-del-cell { text-align: center; width: 44px; }
            .ev-open-cell { text-align: center; width: 44px; }
            .ev-open-btn {
                background: none; border: none; cursor: pointer;
                color: #2563eb; font-size: 1rem; padding: .3rem .5rem;
                border-radius: 5px; line-height: 1; text-decoration: none;
                display: inline-block;
            }
            .ev-open-btn:hover { background: #eff6ff; }
            .ev-del-btn {
                background: none; border: none; cursor: pointer;
                color: #ef4444; font-size: 1rem; padding: .3rem .5rem;
                border-radius: 5px; line-height: 1;
            }
            .ev-del-btn:hover { background: #fee2e2; }
            .ev-save-indicator {
                display: none;
                position: fixed;
                bottom: 1.5rem;
                right: 1.5rem;
                background: #16a34a;
                color: #fff;
                padding: .5rem 1rem;
                border-radius: 8px;
                font-size: .85rem;
                font-weight: 600;
                box-shadow: 0 4px 12px rgba(0,0,0,.15);
                z-index: 999;
                animation: fadeInUp .2s ease;
            }
            @keyframes fadeInUp {
                from { opacity: 0; transform: translateY(6px); }
                to   { opacity: 1; transform: none; }
            }
        </style>

        <?php
        function ev_sort_link(string $col, string $label, string $cur_sort, string $cur_dir, string $filter): string {
            $dir = ($cur_sort === $col && $cur_dir === 'ASC') ? 'desc' : 'asc';
            $arrow = $cur_sort === $col ? ($cur_dir === 'ASC' ? ' &#9650;' : ' &#9660;') : '';
            $f = htmlspecialchars($filter);
            return "<a href=\"/admin_settings.php?tab=events&es=$col&ed=$dir&ef=$f\">$label$arrow</a>";
        }
        ?>

        <div class="ev-grid-wrap">
            <div class="ev-toolbar">
                <form method="get" action="/admin_settings.php"
                      style="display:contents">
                    <input type="hidden" name="tab" value="events">
                    <input type="hidden" name="es" value="<?= htmlspecialchars($events_sort) ?>">
                    <input type="hidden" name="ed" value="<?= htmlspecialchars($events_dir === 'DESC' ? 'desc' : 'asc') ?>">
                    <input type="search" name="ef" value="<?= htmlspecialchars($events_filter) ?>"
                           placeholder="Filter by title or creator…"
                           style="padding:.45rem .8rem;border:1.5px solid #e2e8f0;border-radius:7px;font-size:.85rem;width:240px">
                    <button type="submit" class="btn btn-outline" style="padding:.45rem .9rem;font-size:.85rem">Filter</button>
                    <?php if ($events_filter !== ''): ?>
                        <a href="/admin_settings.php?tab=events" class="btn btn-outline" style="padding:.45rem .9rem;font-size:.85rem">Clear</a>
                    <?php endif; ?>
                </form>
                <span style="color:#64748b;font-size:.82rem;margin-left:auto">
                    <?= count($admin_events) ?> event<?= count($admin_events) !== 1 ? 's' : '' ?>
                </span>
                <span style="color:#94a3b8;font-size:.75rem">Click any cell to edit &bull; Changes save automatically</span>
            </div>

            <table id="eventsGrid">
                <thead>
                    <tr>
                        <th class="ev-id-cell"><?= ev_sort_link('id', '#', $events_sort, $events_dir, $events_filter) ?></th>
                        <th style="min-width:220px"><?= ev_sort_link('title', 'Title', $events_sort, $events_dir, $events_filter) ?></th>
                        <th style="min-width:110px"><?= ev_sort_link('start_date', 'Start Date', $events_sort, $events_dir, $events_filter) ?></th>
                        <th style="min-width:110px">End Date</th>
                        <th style="min-width:90px">Start Time</th>
                        <th style="min-width:90px">End Time</th>
                        <th style="min-width:110px"><?= ev_sort_link('creator', 'Created By', $events_sort, $events_dir, $events_filter) ?></th>
                        <th class="ev-invites-cell"><?= ev_sort_link('invites', 'Invites', $events_sort, $events_dir, $events_filter) ?></th>
                        <th class="ev-open-cell"></th>
                        <th class="ev-del-cell"></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($admin_events as $ev): $eid = (int)$ev['id']; ?>
                    <tr data-id="<?= $eid ?>">
                        <td class="ev-id-cell"><span class="ev-cell"><?= $eid ?></span></td>

                        <td><input class="ev-cell-input" type="text"
                                data-field="title" data-id="<?= $eid ?>"
                                value="<?= htmlspecialchars($ev['title']) ?>"></td>

                        <td><input class="ev-cell-input" type="date"
                                data-field="start_date" data-id="<?= $eid ?>"
                                value="<?= htmlspecialchars($ev['start_date']) ?>"></td>

                        <td><input class="ev-cell-input" type="date"
                                data-field="end_date" data-id="<?= $eid ?>"
                                value="<?= htmlspecialchars($ev['end_date'] ?? '') ?>"></td>

                        <td><input class="ev-cell-input" type="time"
                                data-field="start_time" data-id="<?= $eid ?>"
                                value="<?= htmlspecialchars($ev['start_time'] ?? '') ?>"></td>

                        <td><input class="ev-cell-input" type="time"
                                data-field="end_time" data-id="<?= $eid ?>"
                                value="<?= htmlspecialchars($ev['end_time'] ?? '') ?>"></td>

                        <td class="ev-creator-cell"><span class="ev-cell"><?= htmlspecialchars($ev['creator']) ?></span></td>

                        <td class="ev-invites-cell"><span class="ev-cell"><?= (int)$ev['invites'] ?></span></td>

                        <td class="ev-open-cell">
                            <?php
                            $ev_month = substr($ev['start_date'], 0, 7);
                            $ev_edit_url = '/calendar.php?m=' . urlencode($ev_month) . '&open=' . $eid . '&date=' . urlencode($ev['start_date']);
                            ?>
                            <a href="<?= htmlspecialchars($ev_edit_url) ?>" class="ev-open-btn" title="View/edit event" target="_blank">&#9654;</a>
                        </td>

                        <td class="ev-del-cell">
                            <form method="post" action="/admin_settings.php"
                                  onsubmit="return confirm('Delete event &quot;<?= addslashes(htmlspecialchars($ev['title'])) ?>&quot;? This cannot be undone.')">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">
                                <input type="hidden" name="action" value="delete_event">
                                <input type="hidden" name="tab" value="events">
                                <input type="hidden" name="id" value="<?= $eid ?>">
                                <button type="submit" class="ev-del-btn" title="Delete event">&#128465;</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($admin_events)): ?>
                    <tr><td colspan="10" style="text-align:center;color:#94a3b8;padding:2rem">No events found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="ev-save-indicator" id="evSaveIndicator">Saved</div>

        <script>
        (function () {
            const csrf   = <?= json_encode($token) ?>;
            const ind    = document.getElementById('evSaveIndicator');
            let saveTimer;

            function showSaved() {
                ind.style.display = 'block';
                clearTimeout(saveTimer);
                saveTimer = setTimeout(() => { ind.style.display = 'none'; }, 1800);
            }

            function saveField(id, field, value) {
                const fd = new FormData();
                fd.append('csrf_token', csrf);
                fd.append('action', 'update_event');
                fd.append('tab', 'events');
                fd.append('id', id);
                fd.append('field', field);
                fd.append('value', value);
                fetch('/admin_settings.php', { method: 'POST', body: fd })
                    .then(r => { if (r.ok) showSaved(); });
            }

            // Text / date / time inputs — save on blur if changed
            document.querySelectorAll('#eventsGrid .ev-cell-input').forEach(inp => {
                const orig = inp.value;
                inp.dataset.orig = orig;
                inp.addEventListener('change', function () {
                    saveField(this.dataset.id, this.dataset.field, this.value);
                });
            });

            // Select boxes — save immediately on change
            document.querySelectorAll('#eventsGrid .ev-cell-select').forEach(sel => {
                sel.addEventListener('change', function () {
                    saveField(this.dataset.id, this.dataset.field, this.value);
                });
            });
        })();
        </script>

    </div>

    <!-- ── Leagues tab ── -->
    <?php
    $all_leagues = [];
    if ($tab === 'leagues') {
        $all_leagues = $db->query(
            "SELECT l.*,
                    u.username AS owner_username,
                    (SELECT COUNT(*) FROM league_members WHERE league_id = l.id) AS member_count,
                    (SELECT COUNT(*) FROM events         WHERE league_id = l.id) AS event_count,
                    (SELECT COUNT(*) FROM league_join_requests WHERE league_id = l.id AND status = 'pending') AS pending_count
             FROM leagues l
             LEFT JOIN users u ON u.id = l.owner_id
             ORDER BY LOWER(l.name)"
        )->fetchAll();
    }
    ?>
    <div class="tab-panel <?= $tab === 'leagues' ? 'active' : '' ?>">
        <div class="card" style="max-width:100%">
            <h2>Leagues (<?= count($all_leagues) ?>)</h2>
            <p style="color:#64748b;font-size:.875rem;margin:0 0 1rem">All leagues, including hidden ones. As an admin, clicking a league opens its normal management page, where you have full owner-level privileges regardless of your actual role.</p>
            <?php if (empty($all_leagues)): ?>
                <p style="color:#94a3b8;text-align:center;padding:1.5rem 0">No leagues have been created yet.</p>
            <?php else: ?>
            <div style="margin-bottom:.75rem">
                <input type="search" id="adminLgSearch" placeholder="Search by name, description, or owner&hellip;" autocomplete="off"
                       oninput="filterAdminLeagues(this.value)"
                       style="width:100%;padding:.5rem .75rem;border:1.5px solid #cbd5e1;border-radius:6px;font:inherit">
            </div>
            <div style="overflow-x:auto">
            <table style="width:100%;border-collapse:collapse;font-size:.875rem" id="adminLeagueTbl">
                <thead>
                    <tr style="background:#f8fafc;border-bottom:1.5px solid #e2e8f0">
                        <th style="text-align:left;padding:.6rem .5rem">Name</th>
                        <th style="text-align:left;padding:.6rem .5rem">Owner</th>
                        <th style="text-align:center;padding:.6rem .5rem">Members</th>
                        <th style="text-align:center;padding:.6rem .5rem">Events</th>
                        <th style="text-align:center;padding:.6rem .5rem">Pending</th>
                        <th style="text-align:center;padding:.6rem .5rem">Join mode</th>
                        <th style="text-align:center;padding:.6rem .5rem">Hidden</th>
                        <th style="text-align:right;padding:.6rem .5rem">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($all_leagues as $l): ?>
                    <tr class="admin-lg-row" style="border-bottom:1px solid #f1f5f9"
                        data-name="<?= htmlspecialchars(strtolower($l['name'])) ?>"
                        data-desc="<?= htmlspecialchars(strtolower($l['description'] ?? '')) ?>"
                        data-owner="<?= htmlspecialchars(strtolower($l['owner_username'] ?? '')) ?>">
                        <td style="padding:.55rem .5rem">
                            <strong><?= htmlspecialchars($l['name']) ?></strong>
                            <?php if (!empty($l['description'])): ?>
                                <div style="color:#64748b;font-size:.78rem;margin-top:.15rem"><?= htmlspecialchars(mb_strimwidth($l['description'], 0, 80, '…')) ?></div>
                            <?php endif; ?>
                        </td>
                        <td style="padding:.55rem .5rem"><?= htmlspecialchars($l['owner_username'] ?? '—') ?></td>
                        <td style="padding:.55rem .5rem;text-align:center"><?= (int)$l['member_count'] ?></td>
                        <td style="padding:.55rem .5rem;text-align:center"><?= (int)$l['event_count'] ?></td>
                        <td style="padding:.55rem .5rem;text-align:center"><?= (int)$l['pending_count'] ?></td>
                        <td style="padding:.55rem .5rem;text-align:center"><?= htmlspecialchars($l['approval_mode']) ?></td>
                        <td style="padding:.55rem .5rem;text-align:center"><?= (int)$l['is_hidden'] === 1 ? 'Yes' : 'No' ?></td>
                        <td style="padding:.55rem .5rem;text-align:right;white-space:nowrap">
                            <a class="btn btn-outline" style="font-size:.78rem;padding:.3rem .65rem" href="/league.php?id=<?= (int)$l['id'] ?>">View</a>
                            <a class="btn btn-outline" style="font-size:.78rem;padding:.3rem .65rem" href="/league_edit.php?id=<?= (int)$l['id'] ?>">Edit</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <div id="adminLgNoResults" style="display:none;text-align:center;color:#94a3b8;padding:1rem">No leagues match your search.</div>
            </div>
            <?php endif; ?>
        </div>

        <script>
        function filterAdminLeagues(q) {
            q = (q || '').trim().toLowerCase();
            var rows = document.querySelectorAll('.admin-lg-row');
            var shown = 0;
            rows.forEach(function(r) {
                var m = q === '' || r.dataset.name.indexOf(q) !== -1 || r.dataset.desc.indexOf(q) !== -1 || r.dataset.owner.indexOf(q) !== -1;
                r.style.display = m ? '' : 'none';
                if (m) shown++;
            });
            var nr = document.getElementById('adminLgNoResults');
            if (nr) nr.style.display = (shown === 0 && rows.length > 0) ? 'block' : 'none';
        }
        </script>
    </div>

    <!-- ── Email tab ── -->
    <div class="tab-panel <?= $tab === 'email' ? 'active' : '' ?>">
        <div class="subtabs">
            <a href="/admin_settings.php?tab=email" class="subtab-btn active">Email</a>
            <a href="/admin_settings.php?tab=sms" class="subtab-btn">SMS</a>
            <a href="/admin_settings.php?tab=whatsapp" class="subtab-btn">WhatsApp</a>
        </div>
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
        <div class="subtabs">
            <a href="/admin_settings.php?tab=email" class="subtab-btn">Email</a>
            <a href="/admin_settings.php?tab=sms" class="subtab-btn active">SMS</a>
            <a href="/admin_settings.php?tab=whatsapp" class="subtab-btn">WhatsApp</a>
        </div>
        <?php $smsLogCount = (int)$db->query('SELECT COUNT(*) FROM sms_log')->fetchColumn(); ?>
        <div style="margin-bottom:1.5rem">
            <a href="/sms_log.php" class="btn btn-outline" style="font-size:.85rem;padding:.5rem 1rem">
                View SMS Log (<?= $smsLogCount ?>)
            </a>
        </div>
        <div class="sms-grid">

            <!-- SMS Provider Credentials -->
            <div class="card" style="max-width:100%">
                <h2>SMS Provider</h2>
                <p class="subtitle">Choose your SMS provider and enter credentials.</p>
                <form method="post" action="/admin_settings.php" id="smsCredForm">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">
                    <input type="hidden" name="action" value="sms_credentials">
                    <input type="hidden" name="tab" value="sms">

                    <div class="form-group">
                        <label for="sms_provider">Provider</label>
                        <select id="sms_provider" name="sms_provider"
                                style="width:100%;padding:.5rem .75rem;border:1.5px solid #e2e8f0;border-radius:8px;font-size:.95rem;background:#fff"
                                onchange="toggleSmsFields()">
                            <?php foreach ($sms_providers as $key => $prov): ?>
                            <option value="<?= $key ?>"<?= $sms_provider === $key ? ' selected' : '' ?>><?= htmlspecialchars($prov['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <?php
                    // Current values for each field
                    $sms_field_values = [
                        'sms_sid'            => $sms_sid,
                        'sms_token'          => $sms_token,
                        'sms_from'           => $sms_from,
                        'sms_webhook_secret' => get_setting('sms_webhook_secret'),
                    ];
                    foreach ($sms_providers as $pkey => $prov):
                        foreach ($prov['fields'] as $fkey => $fdef):
                    ?>
                    <div class="form-group sms-field sms-field-<?= $pkey ?>" style="<?= $sms_provider !== $pkey ? 'display:none' : '' ?>">
                        <label for="<?= $fkey ?>_<?= $pkey ?>"><?= htmlspecialchars($fdef['label']) ?></label>
                        <input type="<?= $fdef['type'] ?>" id="<?= $fkey ?>_<?= $pkey ?>"
                               name="<?= $fkey ?>"
                               value="<?= $sms_provider === $pkey ? htmlspecialchars($sms_field_values[$fkey] ?? '') : '' ?>"
                               placeholder="<?= htmlspecialchars($fdef['placeholder']) ?>"
                               autocomplete="<?= $fdef['type'] === 'password' ? 'new-password' : 'off' ?>"
                               <?= $sms_provider !== $pkey ? 'disabled' : '' ?>>
                        <?php if ($fdef['type'] === 'password'): ?>
                        <p class="cred-note">Leave blank to keep current value.</p>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; endforeach; ?>

                    <div style="display:flex;align-items:center;gap:.75rem;margin-top:.25rem">
                        <button type="submit" class="btn btn-primary">Save Credentials</button>
                        <?php if ($sms_configured): ?>
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
                <p class="subtitle">Send a one-off SMS to verify delivery.</p>
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
                            <?= !$sms_configured ? 'disabled title="Configure credentials first"' : '' ?>>
                        Send SMS
                    </button>
                </form>
            </div>

            <!-- URL Shortener -->
            <div class="card" style="max-width:100%">
                <h2>URL Shortener</h2>
                <p class="subtitle">Powered by <a href="https://is.gd" target="_blank" rel="noopener">is.gd</a> &mdash; free, no account or API key required.</p>
                <form method="post" action="/admin_settings.php">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">
                    <input type="hidden" name="action" value="url_shortener">
                    <input type="hidden" name="tab" value="sms">

                    <label style="display:flex;align-items:center;gap:.6rem;cursor:pointer;font-weight:500;margin-bottom:.75rem">
                        <input type="checkbox" name="url_shortener_enabled" value="1"
                               <?= $url_shortener_enabled ? 'checked' : '' ?>
                               style="width:1.1rem;height:1.1rem;accent-color:#2563eb">
                        Automatically shorten URLs in outgoing SMS
                    </label>
                    <p style="font-size:.8rem;color:#64748b;margin:0 0 .75rem">
                        When enabled, any <code>http://</code> or <code>https://</code> link in an outgoing SMS is replaced with a short <code>is.gd/xxxx</code> link before sending.
                    </p>

                    <button type="submit" class="btn btn-primary">Save</button>
                </form>
            </div>

        </div>

        <!-- Quick reference (dynamic per provider) -->
        <?php $activeProv = $sms_providers[$sms_provider] ?? $sms_providers['twilio']; ?>
        <?php foreach ($sms_providers as $pkey => $prov): ?>
        <div class="table-card sms-help sms-help-<?= $pkey ?>" style="margin-top:1.5rem;max-width:620px;<?= $sms_provider !== $pkey ? 'display:none' : '' ?>">
            <h3><?= htmlspecialchars($prov['label']) ?> Quick Reference</h3>
            <table>
                <tbody>
                    <?php foreach ($prov['help'] as $row): ?>
                    <tr>
                        <td style="color:#64748b;width:160px"><?= htmlspecialchars($row[0]) ?></td>
                        <td><?php
                            if (str_starts_with($row[1], 'http')) {
                                echo '<a href="' . htmlspecialchars($row[1]) . '" target="_blank" rel="noopener">' . htmlspecialchars($row[1]) . '</a>';
                            } else {
                                echo htmlspecialchars($row[1]);
                            }
                        ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endforeach; ?>

        <!-- Inbound Webhook URL -->
        <?php $webhook_url = get_site_url() . '/sms_webhook.php'; ?>
        <div class="table-card" style="margin-top:1.5rem;max-width:620px">
            <h3>Inbound Webhook URL</h3>
            <p style="font-size:.85rem;color:#64748b;margin:.25rem 0 .75rem">
                Paste this URL into your provider's inbound message webhook / messaging profile so incoming SMS replies are received by this app.
            </p>
            <div style="display:flex;gap:.5rem;align-items:center">
                <input type="text" id="webhook-url-field" readonly value="<?= htmlspecialchars($webhook_url) ?>"
                       style="flex:1;font-family:monospace;font-size:.85rem;background:#f1f5f9;border:1.5px solid #e2e8f0;border-radius:7px;padding:.5rem .75rem;color:#1e293b;cursor:text">
                <button type="button" onclick="
                    navigator.clipboard.writeText(document.getElementById('webhook-url-field').value).then(function(){
                        var b = this; b.textContent = 'Copied!';
                        setTimeout(function(){ b.textContent = 'Copy'; }, 1500);
                    }.bind(this));
                " style="white-space:nowrap" class="btn btn-outline btn-sm">Copy</button>
            </div>
            <p style="font-size:.78rem;color:#94a3b8;margin-top:.6rem">
                <strong>Telnyx:</strong> Messaging &rsaquo; Messaging Profiles &rsaquo; your profile &rsaquo; Inbound Settings &rsaquo; Webhook URL<br>
                <strong>Twilio:</strong> Phone Numbers &rsaquo; your number &rsaquo; Messaging &rsaquo; Webhook (HTTP POST)<br>
                <strong>Plivo:</strong> Phone Numbers &rsaquo; your number &rsaquo; Message URL<br>
                <strong>Vonage:</strong> Numbers &rsaquo; your number &rsaquo; Edit &rsaquo; Inbound Webhook URL
            </p>
        </div>


        <script>
        function toggleSmsFields() {
            var p = document.getElementById('sms_provider').value;
            document.querySelectorAll('.sms-field').forEach(function(el) {
                el.style.display = el.classList.contains('sms-field-' + p) ? '' : 'none';
                el.querySelectorAll('input').forEach(function(inp) {
                    inp.disabled = !el.classList.contains('sms-field-' + p);
                });
            });
            document.querySelectorAll('.sms-help').forEach(function(el) {
                el.style.display = el.classList.contains('sms-help-' + p) ? '' : 'none';
            });
        }
        </script>
    </div>

    <!-- ── WhatsApp tab ── -->
    <div class="tab-panel <?= $tab === 'whatsapp' ? 'active' : '' ?>">
        <div class="subtabs">
            <a href="/admin_settings.php?tab=email" class="subtab-btn">Email</a>
            <a href="/admin_settings.php?tab=sms" class="subtab-btn">SMS</a>
            <a href="/admin_settings.php?tab=whatsapp" class="subtab-btn active">WhatsApp</a>
        </div>

        <div class="sms-grid">
            <!-- WAHA Connection -->
            <div class="card" style="max-width:100%">
                <h2>WhatsApp Connection</h2>
                <p class="subtitle">Connect via <a href="https://github.com/devlikeapro/waha" target="_blank" rel="noopener">WAHA</a> (self-hosted WhatsApp Web API). Scan a QR code to link a WhatsApp account.</p>

                <form method="post" action="/admin_settings.php" style="margin-bottom:1.25rem">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">
                    <input type="hidden" name="action" value="wa_credentials">
                    <input type="hidden" name="tab" value="whatsapp">
                    <div class="form-group">
                        <label for="waha_url">WAHA URL</label>
                        <input type="text" id="waha_url" name="waha_url"
                               value="<?= htmlspecialchars($waha_url) ?>"
                               placeholder="http://waha:3000" autocomplete="off">
                        <p class="cred-note">Internal Docker URL. Usually no need to change.</p>
                    </div>
                    <div class="form-group">
                        <label for="waha_session">Session Name</label>
                        <input type="text" id="waha_session" name="waha_session"
                               value="<?= htmlspecialchars($waha_session) ?>"
                               placeholder="default" autocomplete="off">
                    </div>
                    <button type="submit" class="btn btn-primary">Save Settings</button>
                </form>

                <!-- Session status + QR code area -->
                <div style="border-top:1px solid #e2e8f0;padding-top:1rem">
                    <div style="display:flex;align-items:center;gap:.75rem;margin-bottom:.75rem">
                        <span style="font-weight:700;font-size:.9rem">Session Status:</span>
                        <span id="wahaStatus" style="font-weight:600;font-size:.85rem;color:#94a3b8">Checking...</span>
                    </div>
                    <div id="wahaQrWrap" style="display:none;text-align:center;margin:1rem 0">
                        <p style="font-size:.85rem;color:#64748b;margin-bottom:.75rem">Scan this QR code with your WhatsApp app:</p>
                        <img id="wahaQrImg" src="" alt="QR Code" style="max-width:280px;border:2px solid #e2e8f0;border-radius:10px">
                        <div style="text-align:left;max-width:320px;margin:1rem auto 0;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:.75rem 1rem">
                            <p style="font-size:.78rem;font-weight:700;color:#334155;margin-bottom:.4rem">How to scan:</p>
                            <ol style="font-size:.75rem;color:#64748b;margin:0;padding-left:1.2rem;line-height:1.6">
                                <li>Open <strong>WhatsApp</strong> on your phone</li>
                                <li>Tap <strong>Settings</strong> (gear icon, bottom right on iPhone &mdash; three dots, top right on Android)</li>
                                <li>Tap <strong>Linked Devices</strong></li>
                                <li>Tap <strong>Link a Device</strong></li>
                                <li>Point your phone camera at the QR code above</li>
                            </ol>
                            <p style="font-size:.72rem;color:#94a3b8;margin-top:.5rem;margin-bottom:0">Use a dedicated WhatsApp number for GameNight to keep your personal account separate.</p>
                        </div>
                    </div>
                    <div style="display:flex;gap:.5rem;flex-wrap:wrap">
                        <button type="button" id="wahaStartBtn" class="btn btn-primary" onclick="wahaStart()" style="display:none">Start Session</button>
                        <button type="button" id="wahaStopBtn" class="btn btn-outline" onclick="wahaStop()" style="display:none;color:#dc2626;border-color:#fca5a5">Disconnect</button>
                        <button type="button" class="btn btn-outline" onclick="wahaCheckStatus()">Refresh Status</button>
                    </div>
                </div>
            </div>

            <!-- Send Test WhatsApp -->
            <div class="card" style="max-width:100%">
                <h2>Send Test WhatsApp</h2>
                <p class="subtitle">Send a one-off WhatsApp message to verify delivery.</p>
                <form method="post" action="/admin_settings.php">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">
                    <input type="hidden" name="action" value="wa_test">
                    <input type="hidden" name="tab" value="whatsapp">
                    <div class="form-group">
                        <label for="wa_to">To (phone number)</label>
                        <input type="tel" id="wa_to" name="to" placeholder="+12015550199" required>
                    </div>
                    <div class="form-group">
                        <label for="wa_body">Message</label>
                        <textarea id="wa_body" name="body" rows="4" style="width:100%;resize:vertical"
                                  placeholder="Hello from <?= htmlspecialchars($site_name) ?>!"
                                  required>Hello from <?= htmlspecialchars($site_name) ?>! This is a test message.</textarea>
                    </div>
                    <button type="submit" class="btn btn-primary">Send WhatsApp</button>
                </form>
            </div>
        </div>
    </div>

    <script>
    var WAHA_URL = <?= json_encode($waha_url, JSON_HEX_TAG) ?>;
    var WAHA_SESSION = <?= json_encode($waha_session, JSON_HEX_TAG) ?>;
    var WAHA_CSRF = <?= json_encode($token, JSON_HEX_TAG) ?>;

    function wahaCheckStatus() {
        document.getElementById('wahaStatus').textContent = 'Connecting to WAHA...';
        document.getElementById('wahaStatus').style.color = '#2563eb';
        fetch('/admin_settings_dl.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest'},
            body: 'csrf_token=' + encodeURIComponent(WAHA_CSRF) + '&action=waha_status'
        })
        .then(function(r) { return r.json(); })
        .then(function(j) {
            var el = document.getElementById('wahaStatus');
            var startBtn = document.getElementById('wahaStartBtn');
            var stopBtn  = document.getElementById('wahaStopBtn');
            var qrWrap   = document.getElementById('wahaQrWrap');
            if (!j.ok) {
                el.textContent = j.error || 'Cannot reach WAHA';
                el.style.color = '#dc2626';
                startBtn.style.display = '';
                stopBtn.style.display = 'none';
                qrWrap.style.display = 'none';
                return;
            }
            if (j.status === 'WORKING') {
                el.textContent = 'Connected \u2714';
                el.style.color = '#16a34a';
                startBtn.style.display = 'none';
                stopBtn.style.display = '';
                qrWrap.style.display = 'none';
                if (_wahaQrInterval) { clearInterval(_wahaQrInterval); _wahaQrInterval = null; }
            } else if (j.status === 'SCAN_QR_CODE') {
                el.textContent = 'Waiting for QR scan...';
                el.style.color = '#d97706';
                startBtn.style.display = 'none';
                stopBtn.style.display = '';
                qrWrap.style.display = '';
                wahaLoadQr();
            } else if (j.status === 'STOPPED' || j.status === 'FAILED') {
                el.textContent = j.status;
                el.style.color = '#dc2626';
                startBtn.style.display = '';
                stopBtn.style.display = 'none';
                qrWrap.style.display = 'none';
            } else {
                el.textContent = j.status || 'Unknown';
                el.style.color = '#94a3b8';
                startBtn.style.display = '';
                stopBtn.style.display = '';
                qrWrap.style.display = 'none';
            }
        })
        .catch(function() {
            document.getElementById('wahaStatus').textContent = 'Error contacting server';
            document.getElementById('wahaStatus').style.color = '#dc2626';
        });
    }

    var _wahaQrInterval = null;

    function wahaLoadQr() {
        fetch('/admin_settings_dl.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest'},
            body: 'csrf_token=' + encodeURIComponent(WAHA_CSRF) + '&action=waha_qr'
        })
        .then(function(r) { return r.json(); })
        .then(function(j) {
            if (j.ok && j.qr) {
                document.getElementById('wahaQrImg').src = j.qr;
                document.getElementById('wahaQrWrap').style.display = '';
                // Auto-refresh QR every 15 seconds (QR codes expire)
                if (!_wahaQrInterval) {
                    _wahaQrInterval = setInterval(function() {
                        wahaLoadQr();
                        // Also check if session connected (stop QR refresh)
                        wahaCheckStatus();
                    }, 15000);
                }
            } else {
                document.getElementById('wahaQrWrap').style.display = 'none';
                if (_wahaQrInterval) { clearInterval(_wahaQrInterval); _wahaQrInterval = null; }
            }
        })
        .catch(function() {
            document.getElementById('wahaQrWrap').style.display = 'none';
        });
    }

    function wahaStart() {
        var btn = document.getElementById('wahaStartBtn');
        btn.disabled = true;
        btn.textContent = 'Starting... (this may take a minute)';
        document.getElementById('wahaStatus').textContent = 'Starting session...';
        document.getElementById('wahaStatus').style.color = '#d97706';
        fetch('/admin_settings_dl.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest'},
            body: 'csrf_token=' + encodeURIComponent(WAHA_CSRF) + '&action=waha_start'
        })
        .then(function(r) { return r.json(); })
        .then(function(j) {
            btn.disabled = false;
            btn.textContent = 'Start Session';
            if (j.ok) {
                setTimeout(wahaCheckStatus, 2000);
            } else {
                alert(j.error || 'Failed to start session');
                wahaCheckStatus();
            }
        })
        .catch(function() {
            btn.disabled = false;
            btn.textContent = 'Start Session';
            wahaCheckStatus();
        });
    }

    function wahaStop() {
        if (!confirm('Disconnect WhatsApp session?')) return;
        fetch('/admin_settings_dl.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest'},
            body: 'csrf_token=' + encodeURIComponent(WAHA_CSRF) + '&action=waha_stop'
        })
        .then(function(r) { return r.json(); })
        .then(function(j) {
            wahaCheckStatus();
        });
    }

    // Check status on page load and poll every 10 seconds if on WhatsApp tab
    if (<?= json_encode($tab === 'whatsapp') ?>) {
        wahaCheckStatus();
        setInterval(wahaCheckStatus, 10000);
    }
    </script>

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
                <label for="m_phone">Phone</label>
                <input type="tel" id="m_phone" name="phone">
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
            <div class="form-group">
                <label for="m_notes">Notes</label>
                <textarea id="m_notes" name="notes" rows="3" style="width:100%;resize:vertical"></textarea>
            </div>
            <div style="display:flex;gap:.75rem;margin-top:1.5rem">
                <button type="submit" class="btn btn-primary" style="flex:1">Create User</button>
                <button type="button" class="btn btn-outline" onclick="closeUserModal()">Cancel</button>
            </div>
        </form>
    </div>
</div>

    <!-- ── Scheduled Tasks (Cron) tab ── -->
    <div class="tab-panel <?= $tab === 'cron' ? 'active' : '' ?>">
        <div class="sms-grid">
            <!-- What is the cron job? -->
            <div class="card" style="max-width:100%">
                <h2>Scheduled Tasks</h2>
                <p class="subtitle">GameNight uses a scheduled background task (cron job) that runs every 30 minutes to handle automated work.</p>

                <div style="margin-bottom:1.5rem">
                    <h3 style="font-size:.9rem;margin-bottom:.5rem">What does it do?</h3>
                    <div style="font-size:.85rem;color:#475569;line-height:1.7">
                        <p><strong>Event Reminders</strong> &mdash; Sends automatic reminders to invitees 2 days and 12 hours before each event. Uses each person's preferred contact method (email, SMS, or WhatsApp). Each reminder only fires once per event per person.</p>
                        <p style="margin-top:.5rem"><strong>Database Maintenance</strong> &mdash; Cleans up stale data to keep the database lean:</p>
                        <ul style="margin:.25rem 0 0 1.25rem;padding:0">
                            <li>Expired verification tokens (password resets, email/phone codes) &mdash; deleted after 24 hours</li>
                            <li>Notification dedup records &mdash; deleted after 30 days</li>
                            <li>SMS/email/WhatsApp logs &mdash; deleted after 90 days</li>
                            <li>Activity log entries &mdash; deleted after 90 days</li>
                            <li>URL shortener links &mdash; deleted after 90 days</li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Cron Token -->
            <div class="card" style="max-width:100%">
                <h2>Cron Token</h2>
                <p class="subtitle">A secret password that protects the cron endpoint from unauthorized access.</p>

                <div style="margin-bottom:1rem;padding:.75rem;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;font-size:.82rem;color:#475569;line-height:1.6">
                    <strong>Why is this needed?</strong> The cron job runs by visiting a URL (<code>cron.php?token=...</code>). Without a token, anyone who guessed the URL could trigger reminder emails. The token acts like a password &mdash; only requests with the correct token are processed. Everything else is ignored.
                </div>

                <form method="post" action="/admin_settings.php" style="margin-bottom:1rem">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">
                    <input type="hidden" name="action" value="cron_settings">
                    <input type="hidden" name="tab" value="cron">
                    <div class="form-group">
                        <label>Token</label>
                        <div style="display:flex;gap:.5rem">
                            <input type="text" name="cron_token" id="cronTokenInput"
                                   value="<?= htmlspecialchars(get_setting('cron_token','')) ?>"
                                   placeholder="Click Generate to create one"
                                   autocomplete="off" style="flex:1;font-family:monospace">
                            <button type="button" class="btn btn-outline" style="white-space:nowrap"
                                    onclick="document.getElementById('cronTokenInput').value = Array.from(crypto.getRandomValues(new Uint8Array(20))).map(b=>b.toString(16).padStart(2,'0')).join('')">
                                Generate
                            </button>
                        </div>
                        <p class="hint">Click Generate to create a random token. Save it, then paste it into the cron job command below.</p>
                    </div>
                    <button type="submit" class="btn btn-primary">Save Token</button>
                </form>
            </div>
        </div>

        <!-- Setup instructions -->
        <div class="card" style="max-width:100%;margin-top:1rem">
            <h2>Setup Instructions</h2>
            <p class="subtitle">How to activate scheduled tasks on your server.</p>

            <div style="padding:.75rem;background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;margin-bottom:1rem">
                <p style="font-size:.85rem;font-weight:700;color:#1e40af;margin-bottom:.4rem">&#128051; Docker Installation</p>
                <p style="font-size:.82rem;color:#475569;line-height:1.6;margin:0">
                    <strong>No setup needed.</strong> The GameNight container has a built-in background scheduler that automatically runs every 30 minutes. On first start, it generates a secure cron token and saves it. The scheduler runs inside the container alongside Apache &mdash; no external cron job, no manual configuration. Just <code>docker compose up</code> and everything works.
                </p>
                <p style="font-size:.78rem;color:#94a3b8;margin-top:.4rem;margin-bottom:0">
                    Technical detail: <code>docker-entrypoint.sh</code> launches a background loop that curls <code>http://localhost/cron.php?token=...</code> every 1800 seconds (30 min). The loop runs as a child process of the entrypoint and dies automatically when the container stops.
                </p>
            </div>

            <div style="padding:.75rem;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px">
                <p style="font-size:.85rem;font-weight:700;color:#334155;margin-bottom:.4rem">&#128421; Manual Server (non-Docker)</p>
                <p style="font-size:.82rem;color:#475569;line-height:1.6">
                    If you're running GameNight without Docker (e.g., on XAMPP, Laragon, or a bare Apache/PHP server), you need to set up a cron job manually. First, generate and save a token above. Then add this line to your server's crontab (<code>crontab -e</code> via SSH):
                </p>

                <pre style="background:#0f172a;color:#e2e8f0;border-radius:7px;padding:.75rem 1rem;font-size:.78rem;overflow-x:auto;margin:.75rem 0">*/30 * * * * curl -s "<?= htmlspecialchars(get_site_url()) ?>/cron.php?token=<?= htmlspecialchars(get_setting('cron_token','YOUR_TOKEN_HERE')) ?>" > /dev/null</pre>

                <div style="font-size:.78rem;color:#64748b;line-height:1.6">
                    <p><strong>What each part means:</strong></p>
                    <ul style="margin:.25rem 0 0 1.25rem;padding:0">
                        <li><code>*/30 * * * *</code> &mdash; Run every 30 minutes, 24/7</li>
                        <li><code>curl -s "..."</code> &mdash; Silently visit the cron URL (like opening it in a browser)</li>
                        <li><code>?token=...</code> &mdash; The secret token that proves you're authorized to run tasks</li>
                        <li><code>> /dev/null</code> &mdash; Don't save the output anywhere (run silently)</li>
                    </ul>
                </div>
            </div>

            <?php $cronToken = get_setting('cron_token', ''); ?>
            <?php if ($cronToken === ''): ?>
            <div style="margin-top:1rem;padding:.6rem .75rem;background:#fef2f2;border:1px solid #fca5a5;border-radius:6px;font-size:.82rem;color:#dc2626;font-weight:600">
                &#9888; No cron token set. Generate one above, or restart the container to auto-generate one.
            </div>
            <?php else: ?>
            <div style="margin-top:1rem;padding:.6rem .75rem;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;font-size:.82rem;color:#16a34a;font-weight:600">
                &#10003; Cron token configured. Scheduled tasks are active.
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── Backup tab ── -->
    <div class="tab-panel <?= $tab === 'backup' ? 'active' : '' ?>">
        <h2>Database Backup &amp; Restore</h2>
        <p class="subtitle">Download a full backup of the database or restore from a previous backup.</p>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;max-width:700px;margin-top:1.5rem">
            <!-- Download backup -->
            <div class="card" style="max-width:100%">
                <h3 style="margin-top:0">Download Backup</h3>
                <p style="font-size:.85rem;color:#64748b;margin-bottom:1rem">Download a copy of the entire database including all users, events, settings, and game data.</p>
                <form method="post" action="/admin_settings.php?tab=backup">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                    <input type="hidden" name="action" value="backup_download">
                    <input type="hidden" name="tab" value="backup">
                    <button type="submit" class="btn btn-primary" style="width:100%">Download Backup</button>
                </form>
                <?php
                    $dbSize = file_exists(DB_PATH) ? filesize(DB_PATH) : 0;
                    $dbSizeStr = $dbSize < 1024*1024
                        ? round($dbSize / 1024, 1) . ' KB'
                        : round($dbSize / (1024*1024), 2) . ' MB';
                ?>
                <p style="font-size:.75rem;color:#94a3b8;margin-top:.75rem">Database size: <?= $dbSizeStr ?></p>
            </div>

            <!-- Restore backup -->
            <div class="card" style="max-width:100%;border-color:#fca5a5">
                <h3 style="margin-top:0;color:#dc2626">Restore from Backup</h3>
                <p style="font-size:.85rem;color:#64748b;margin-bottom:1rem">Upload a previously downloaded <code>.db</code> backup file. This will <strong>replace all current data</strong>.</p>
                <form method="post" action="/admin_settings.php?tab=backup" enctype="multipart/form-data"
                      onsubmit="return confirm('This will REPLACE ALL current data with the backup. The current database will be saved as a safety copy. Continue?')">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                    <input type="hidden" name="action" value="backup_restore">
                    <input type="hidden" name="tab" value="backup">
                    <div class="form-group" style="margin-bottom:1rem">
                        <input type="file" name="backup_file" accept=".db" required
                               style="width:100%;padding:.4rem;border:1.5px solid #e2e8f0;border-radius:6px;font-size:.85rem">
                    </div>
                    <button type="submit" class="btn" style="width:100%;background:#dc2626;color:#fff;border:none">Restore Backup</button>
                </form>
                <?php if (file_exists(DB_PATH . '.before_restore')): ?>
                <p style="font-size:.75rem;color:#94a3b8;margin-top:.75rem">Pre-restore backup saved: <?= date('Y-m-d H:i:s', filemtime(DB_PATH . '.before_restore')) ?></p>
                <?php endif; ?>
            </div>
        </div>
    </div>

<?php require __DIR__ . '/_footer.php'; ?>

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
