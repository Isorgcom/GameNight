<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/svc/contacts.php';   // gn_contact_save(): the one set of rules for a contact
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
    // Formula-leading cells are neutralised by csv_cell() (db.php), shared
    // with the league and user exports.
    $csvSafe = 'csv_cell';
    $out = fopen('php://output', 'w');
    fputcsv($out, ['name', 'email', 'phone', 'notes', 'status']);
    foreach ($rows->fetchAll() as $r) {
        fputcsv($out, [
            $csvSafe($r['contact_name'] ?? ''),
            $csvSafe($r['contact_email'] ?? ''),
            $csvSafe($r['contact_phone'] ?? ''),
            $csvSafe($r['notes'] ?? ''),
            $r['linked_user_id'] ? 'linked' : 'pending',
        ]);
    }
    fclose($out);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('POST required', 405);
if (!csrf_verify()) fail('Invalid CSRF token', 403);

$action = $_POST['action'] ?? '';

/** Answer with a service failure in this page's own shape (its errors are 400, or 404 for a contact that is not yours). */
function fail_from(array $res): void {
    fail((string)$res['message'], (int)($res['http'] ?? 400) === 404 ? 404 : 400);
}

switch ($action) {

case 'add_contact': {
    $res = gn_contact_save($db, $uid, null, [
        'name' => $_POST['contact_name'] ?? '', 'email' => $_POST['contact_email'] ?? '',
        'phone' => $_POST['contact_phone'] ?? '', 'notes' => $_POST['notes'] ?? '',
    ]);
    if (empty($res['ok'])) fail_from($res);
    // Whether the typed email or phone matches an account stays server-side:
    // the page reloads and shows the Linked badge for contacts the owner
    // already has, but the save response itself is not a lookup service.
    ok(['id' => (int)$res['id']]);
}

case 'update_contact': {
    // One cell edited on the page: the stored row with that one field changed.
    $cid   = (int)($_POST['contact_id'] ?? 0);
    $field = (string)($_POST['field'] ?? '');
    $map   = ['contact_name' => 'name', 'contact_email' => 'email', 'contact_phone' => 'phone', 'notes' => 'notes', 'invite_via' => 'invite_via'];
    if (!isset($map[$field])) fail('Field not editable.');
    $rs = $db->prepare('SELECT * FROM user_contacts WHERE id = ? AND owner_user_id = ?');
    $rs->execute([$cid, $uid]);
    $row = $rs->fetch();
    if (!$row) fail('Contact not found.', 404);
    $f = ['name' => $row['contact_name'], 'email' => $row['contact_email'], 'phone' => $row['contact_phone'],
          'notes' => $row['notes'], 'invite_via' => $row['invite_via'] ?: 'email'];
    $f[$map[$field]] = trim((string)($_POST['value'] ?? ''));
    $res = gn_contact_save($db, $uid, $cid, $f);
    if (empty($res['ok'])) fail_from($res);
    ok();
}

case 'save_row': {
    // Explicit whole-row save (the per-row Save button): validates and writes
    // every field in one shot, so nothing depends on per-cell blur autosaves.
    $cid = (int)($_POST['contact_id'] ?? 0);
    $via = (string)($_POST['invite_via'] ?? 'email');
    $res = gn_contact_save($db, $uid, $cid, [
        'name' => $_POST['contact_name'] ?? '', 'email' => $_POST['contact_email'] ?? '',
        'phone' => $_POST['contact_phone'] ?? '', 'notes' => $_POST['notes'] ?? '',
        'invite_via' => in_array($via, ['email', 'sms'], true) ? $via : 'email',
    ]);
    if (empty($res['ok'])) fail_from($res);
    ok(['phone' => (string)($res['phone'] ?? '')]);
}

case 'delete_contact': {
    $cid = (int)($_POST['contact_id'] ?? 0);
    $db->prepare('DELETE FROM user_contacts WHERE id = ? AND owner_user_id = ?')->execute([$cid, $uid]);
    db_log_activity($uid, "deleted contact id=$cid");
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
        $email = trim((string)($row[$colIdx['email']] ?? ''));
        $phone = trim((string)($row[$colIdx['phone']] ?? ''));
        if ($name === '' && $email === '' && $phone === '') continue;
        try {
            $res = gn_contact_save($db, $uid, null, ['name' => $name, 'email' => $email, 'phone' => $phone], false);
        } catch (Throwable $e) { $errors++; continue; }
        if (!empty($res['ok']))                               $imported++;
        elseif (($res['code'] ?? '') === 'duplicate_contact') $skipped++;
        else                                                  $errors++;
    }
    fclose($handle);
    $msg = "Imported $imported contact" . ($imported !== 1 ? 's' : '') . ".";
    if ($skipped) $msg .= " Skipped $skipped (duplicate email).";
    if ($errors)  $msg .= " $errors row(s) had errors.";
    $_SESSION['flash'] = ['type' => $imported > 0 ? 'success' : 'error', 'msg' => $msg];
    db_log_activity($uid, "imported contacts: $imported added, $skipped skipped, $errors error(s)");
    header('Location: /contacts.php');
    exit;
}

default:
    fail('Unknown action.');
}
