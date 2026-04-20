<?php
require_once __DIR__ . '/auth.php';
header('Content-Type: application/json');

$current = require_login();
$db      = get_db();
$uid     = (int)$current['id'];

function fail(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}
function ok(array $extra = []): void {
    echo json_encode(array_merge(['ok' => true], $extra));
    exit;
}

// CSV export (GET)
if (($_GET['action'] ?? '') === 'export') {
    $rows = $db->prepare('SELECT contact_name, contact_email, contact_phone, notes, linked_user_id FROM user_contacts WHERE owner_user_id = ? ORDER BY LOWER(contact_name)');
    $rows->execute([$uid]);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="contacts_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['name', 'email', 'phone', 'notes', 'status']);
    foreach ($rows->fetchAll() as $r) {
        fputcsv($out, [
            $r['contact_name'] ?? '',
            $r['contact_email'] ?? '',
            $r['contact_phone'] ?? '',
            $r['notes'] ?? '',
            $r['linked_user_id'] ? 'linked' : 'pending',
        ]);
    }
    fclose($out);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('POST required', 405);
if (!csrf_verify()) fail('Invalid CSRF token', 403);

$action = $_POST['action'] ?? '';

/**
 * Resolve a users.id for a given email/phone — null if no match.
 */
function resolve_user_id(PDO $db, string $email, string $phone): ?int {
    if ($email !== '') {
        $s = $db->prepare('SELECT id FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1');
        $s->execute([$email]);
        $id = $s->fetchColumn();
        if ($id) return (int)$id;
    }
    if ($phone !== '') {
        $s = $db->prepare('SELECT id FROM users WHERE phone = ? LIMIT 1');
        $s->execute([$phone]);
        $id = $s->fetchColumn();
        if ($id) return (int)$id;
    }
    return null;
}

switch ($action) {

case 'add_contact': {
    $name  = trim($_POST['contact_name']  ?? '');
    $email = strtolower(trim($_POST['contact_email'] ?? ''));
    $phoneRaw = trim($_POST['contact_phone'] ?? '');
    $phone = $phoneRaw !== '' ? normalize_phone($phoneRaw) : '';
    $notes = trim($_POST['notes'] ?? '');

    if ($name === '')                                                fail('Name is required.');
    if ($email === '' && $phone === '')                              fail('Provide an email or phone.');
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Invalid email.');

    // Duplicate check
    if ($email !== '') {
        $dup = $db->prepare('SELECT 1 FROM user_contacts WHERE owner_user_id = ? AND LOWER(contact_email) = ? LIMIT 1');
        $dup->execute([$uid, $email]);
        if ($dup->fetchColumn()) fail('A contact with that email already exists.');
    }

    $linked = resolve_user_id($db, $email, $phone);
    $db->prepare('INSERT INTO user_contacts (owner_user_id, linked_user_id, contact_name, contact_email, contact_phone, notes) VALUES (?, ?, ?, ?, ?, ?)')
       ->execute([$uid, $linked, $name, $email ?: null, $phone ?: null, $notes ?: null]);
    ok(['id' => (int)$db->lastInsertId(), 'linked' => (bool)$linked]);
}

case 'update_contact': {
    $cid   = (int)($_POST['contact_id'] ?? 0);
    $field = (string)($_POST['field'] ?? '');
    $value = trim((string)($_POST['value'] ?? ''));

    if (!in_array($field, ['contact_name', 'contact_email', 'contact_phone', 'notes'], true)) fail('Field not editable.');

    // Verify ownership
    $rs = $db->prepare('SELECT * FROM user_contacts WHERE id = ? AND owner_user_id = ?');
    $rs->execute([$cid, $uid]);
    $row = $rs->fetch();
    if (!$row) fail('Contact not found.', 404);

    if ($field === 'contact_email') {
        $value = strtolower($value);
        if ($value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) fail('Invalid email.');
        if ($value !== '') {
            $dup = $db->prepare('SELECT 1 FROM user_contacts WHERE owner_user_id = ? AND LOWER(contact_email) = ? AND id <> ? LIMIT 1');
            $dup->execute([$uid, $value, $cid]);
            if ($dup->fetchColumn()) fail('Another contact already uses that email.');
        }
    }
    if ($field === 'contact_phone') {
        $value = $value !== '' ? normalize_phone($value) : '';
    }
    if ($field === 'contact_name' && $value === '') fail('Name is required.');

    // Must keep at least one of email/phone
    if (($field === 'contact_email' || $field === 'contact_phone') && $value === '') {
        $other = $field === 'contact_email' ? ($row['contact_phone'] ?? '') : ($row['contact_email'] ?? '');
        if ($other === '' || $other === null) fail('Keep at least an email or phone.');
    }

    $db->prepare("UPDATE user_contacts SET $field = ? WHERE id = ?")->execute([$value !== '' ? $value : null, $cid]);

    // If email/phone changed, re-resolve linked_user_id
    if ($field === 'contact_email' || $field === 'contact_phone') {
        $rs2 = $db->prepare('SELECT contact_email, contact_phone FROM user_contacts WHERE id = ?');
        $rs2->execute([$cid]);
        $r2 = $rs2->fetch();
        $linked = resolve_user_id($db, (string)($r2['contact_email'] ?? ''), (string)($r2['contact_phone'] ?? ''));
        $db->prepare('UPDATE user_contacts SET linked_user_id = ? WHERE id = ?')->execute([$linked, $cid]);
    }
    ok();
}

case 'delete_contact': {
    $cid = (int)($_POST['contact_id'] ?? 0);
    $db->prepare('DELETE FROM user_contacts WHERE id = ? AND owner_user_id = ?')->execute([$cid, $uid]);
    ok();
}

case 'import_csv': {
    $file = $_FILES['csv_file'] ?? null;
    if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
        $_SESSION['flash'] = ['type' => 'error', 'msg' => 'No file uploaded.'];
        header('Location: /contacts.php');
        exit;
    }
    $handle = fopen($file['tmp_name'], 'r');
    $header = fgetcsv($handle);
    // Detect columns — accept "name"/"display_name"/"username" for name, "email", "phone"
    $colIdx = ['name' => 0, 'email' => 1, 'phone' => 2];
    if ($header) {
        $norm = array_map(fn($h) => strtolower(trim((string)$h)), $header);
        foreach (['name','email','phone'] as $k) {
            $m = array_search($k, $norm, true);
            if ($m === false && $k === 'name') {
                $m = array_search('display_name', $norm, true);
                if ($m === false) $m = array_search('username', $norm, true);
            }
            if ($m !== false) $colIdx[$k] = (int)$m;
        }
    }

    $imported = 0; $skipped = 0; $errors = 0;
    while (($row = fgetcsv($handle)) !== false) {
        $name  = trim((string)($row[$colIdx['name']]  ?? ''));
        $email = strtolower(trim((string)($row[$colIdx['email']] ?? '')));
        $phoneRaw = trim((string)($row[$colIdx['phone']] ?? ''));
        $phone = $phoneRaw !== '' ? normalize_phone($phoneRaw) : '';
        if ($name === '' && $email === '' && $phone === '') continue;
        if ($name === '' || ($email === '' && $phone === '')) { $errors++; continue; }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { $errors++; continue; }

        // Dup check by email
        if ($email !== '') {
            $dup = $db->prepare('SELECT 1 FROM user_contacts WHERE owner_user_id = ? AND LOWER(contact_email) = ? LIMIT 1');
            $dup->execute([$uid, $email]);
            if ($dup->fetchColumn()) { $skipped++; continue; }
        }
        $linked = resolve_user_id($db, $email, $phone);
        try {
            $db->prepare('INSERT INTO user_contacts (owner_user_id, linked_user_id, contact_name, contact_email, contact_phone) VALUES (?, ?, ?, ?, ?)')
               ->execute([$uid, $linked, $name, $email ?: null, $phone ?: null]);
            $imported++;
        } catch (Throwable $e) { $errors++; }
    }
    fclose($handle);
    $msg = "Imported $imported contact" . ($imported !== 1 ? 's' : '') . ".";
    if ($skipped) $msg .= " Skipped $skipped (duplicate email).";
    if ($errors)  $msg .= " $errors row(s) had errors.";
    $_SESSION['flash'] = ['type' => $imported > 0 ? 'success' : 'error', 'msg' => $msg];
    header('Location: /contacts.php');
    exit;
}

default:
    fail('Unknown action.');
}
