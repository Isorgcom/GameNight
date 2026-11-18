<?php
/**
 * Service layer: a person's own contacts.
 *
 * Contacts are the address book a host invites from: a name with an email, a
 * phone, or both. They belong to one account and nobody else can see them.
 * The contacts page, the personal API and the MCP tools all save through
 * gn_contact_save(), so the rules are the same everywhere: a name is
 * required, at least one way to reach the person, and one contact per email.
 */

require_once __DIR__ . '/common.php';

/** The account an email or phone belongs to, or null. Used to mark a contact as someone who has an account. */
function gn_contact_linked_user(PDO $db, string $email, string $phone): ?int {
    if ($email !== '') {
        $s = $db->prepare('SELECT id FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1');
        $s->execute([$email]);
        if ($id = $s->fetchColumn()) return (int)$id;
    }
    if ($phone !== '') {
        $s = $db->prepare('SELECT id FROM users WHERE phone = ? LIMIT 1');
        $s->execute([$phone]);
        if ($id = $s->fetchColumn()) return (int)$id;
    }
    return null;
}

/**
 * Create ($id null) or rewrite a contact of $owner_id with exactly these
 * fields: name, email, phone, notes, invite_via ('email' | 'sms').
 * Returns ['ok' => true, 'id', 'name', 'email', 'phone', 'notes', 'invite_via']
 * or a failure. The messages are the contacts page's own. $log false leaves
 * the activity log to the caller (a bulk add writes one line, not hundreds).
 */
function gn_contact_save(PDO $db, int $owner_id, ?int $id, array $f, bool $log = true): array {
    $name  = trim((string)($f['name'] ?? ''));
    $email = strtolower(trim((string)($f['email'] ?? '')));
    $raw   = trim((string)($f['phone'] ?? ''));
    $phone = $raw !== '' ? (string)normalize_phone($raw) : '';
    $notes = trim((string)($f['notes'] ?? ''));
    $via   = (string)($f['invite_via'] ?? 'email');

    if ($id !== null) {
        $own = $db->prepare('SELECT 1 FROM user_contacts WHERE id = ? AND owner_user_id = ?');
        $own->execute([$id, $owner_id]);
        if (!$own->fetchColumn()) return gn_err('contact_not_found', 'Contact not found.', 404);
    }
    if ($name === '') return gn_err('bad_field', 'Name is required.', 400);
    if (mb_strlen($name) > 80) return gn_err('bad_field', 'That name is longer than 80 characters.', 400);
    if ($raw !== '' && $phone === '') return gn_err('bad_field', 'That is not a phone number.', 400);
    if ($email === '' && $phone === '') return gn_err('bad_field', $id === null ? 'Provide an email or phone.' : 'Keep at least an email or phone.', 400);
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) return gn_err('bad_field', 'Invalid email.', 400);
    if (!in_array($via, ['email', 'sms'], true)) return gn_err('bad_field', 'Invalid invite channel.', 400);
    if (mb_strlen($notes) > 1000) return gn_err('bad_field', 'Notes are longer than 1000 characters.', 400);
    if ($email !== '') {
        $dup = $db->prepare('SELECT 1 FROM user_contacts WHERE owner_user_id = ? AND LOWER(contact_email) = ? AND id <> ? LIMIT 1');
        $dup->execute([$owner_id, $email, (int)$id]);
        if ($dup->fetchColumn()) return gn_err('duplicate_contact', $id === null ? 'A contact with that email already exists.' : 'Another contact already uses that email.', 409);
    }

    $linked = gn_contact_linked_user($db, $email, $phone);
    if ($id === null) {
        $db->prepare('INSERT INTO user_contacts (owner_user_id, linked_user_id, contact_name, contact_email, contact_phone, notes, invite_via) VALUES (?, ?, ?, ?, ?, ?, ?)')
           ->execute([$owner_id, $linked, $name, $email ?: null, $phone ?: null, $notes ?: null, $via]);
        $id = (int)$db->lastInsertId();
        if ($log) db_log_activity($owner_id, "added contact: $name (contact id: $id)");
    } else {
        $db->prepare('UPDATE user_contacts SET contact_name = ?, contact_email = ?, contact_phone = ?, notes = ?, invite_via = ?, linked_user_id = ? WHERE id = ?')
           ->execute([$name, $email ?: null, $phone ?: null, $notes ?: null, $via, $linked, $id]);
        if ($log) db_log_activity($owner_id, "saved contact id=$id");
    }
    return gn_ok(['id' => $id, 'name' => $name, 'email' => $email ?: null, 'phone' => $phone ?: null, 'notes' => $notes, 'invite_via' => $via]);
}

function gn_contact_shape(array $c): array {
    // "Has an account" only counts when that account still exists.
    $linked = !empty($c['linked_user_id']) && !empty($c['linked_username']);
    return [
        'id'    => (int)$c['id'],
        'name'  => (string)$c['contact_name'],
        'email' => $c['contact_email'] ?: null,
        'phone' => $c['contact_phone'] ?: null,
        'notes' => (string)($c['notes'] ?? ''),
        'invite_via'   => (string)($c['invite_via'] ?: 'email'),      // how a typed-in invite reaches them
        'has_account'  => $linked,
        'user_id'      => $linked ? (int)$c['linked_user_id'] : null,
        'account_name' => $linked ? (string)$c['linked_username'] : null,
    ];
}

/** The actor's contacts, people with accounts first, then by name. $args: search (matches name, email or phone). */
function gn_contacts_list(PDO $db, array $actor, array $args = []): array {
    $sql = 'SELECT c.*, u.username AS linked_username FROM user_contacts c LEFT JOIN users u ON u.id = c.linked_user_id WHERE c.owner_user_id = ?';
    $params = [(int)$actor['id']];
    $q = trim((string)($args['search'] ?? ''));
    if ($q !== '') {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($q)) . '%';
        $sql .= " AND (LOWER(c.contact_name) LIKE ? ESCAPE '\\' OR LOWER(COALESCE(c.contact_email, '')) LIKE ? ESCAPE '\\' OR COALESCE(c.contact_phone, '') LIKE ? ESCAPE '\\')";
        array_push($params, $like, $like, $like);
    }
    $sql .= ' ORDER BY CASE WHEN c.linked_user_id IS NULL THEN 1 ELSE 0 END, LOWER(c.contact_name) LIMIT 1000';
    $st = $db->prepare($sql);
    $st->execute($params);
    $out = array_map('gn_contact_shape', $st->fetchAll(PDO::FETCH_ASSOC));
    return gn_ok(['count' => count($out), 'contacts' => $out]);
}

/** One of the actor's contacts as stored, or null. */
function gn_contact_row(PDO $db, array $actor, int $id): ?array {
    $st = $db->prepare('SELECT c.*, u.username AS linked_username FROM user_contacts c LEFT JOIN users u ON u.id = c.linked_user_id WHERE c.id = ? AND c.owner_user_id = ?');
    $st->execute([$id, (int)$actor['id']]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

const GN_CONTACT_FIELDS = ['name', 'email', 'phone', 'notes', 'invite_via'];

/**
 * Add contacts. $body is one contact ({name, email, phone, notes, invite_via})
 * or {"contacts": [...]} for up to 200 at once. With a list, each is taken on
 * its own: one already there (same email) is skipped and one that is not
 * valid is reported, and the rest are still added.
 */
function gn_contacts_add(PDO $db, array $actor, array $body): array {
    $many = array_key_exists('contacts', $body);
    $list = $many ? $body['contacts'] : [$body];
    if ($many && array_diff(array_keys($body), ['contacts'])) return gn_err('unknown_field', 'Send either one contact, or only "contacts".', 400);
    if (!is_array($list) || !array_is_list($list) || !$list || count($list) > 200) return gn_err('bad_field', '"contacts" must be a list of 1 to 200 contacts.', 400);

    $added = []; $skipped = []; $errors = [];
    foreach ($list as $i => $c) {
        if (!is_array($c)) { $errors[] = ['index' => $i, 'name' => null, 'message' => 'Each contact must be an object.']; continue; }
        $bad = array_diff(array_keys($c), GN_CONTACT_FIELDS);
        $res = $bad ? gn_err('unknown_field', 'Unknown field: ' . implode(', ', $bad) . '.', 400) : gn_contact_save($db, (int)$actor['id'], null, $c, !$many);
        if (!$many && empty($res['ok'])) return $res;       // one contact: its own error
        $label = is_string($c['name'] ?? null) ? $c['name'] : null;
        if (!empty($res['ok']))                             { unset($res['ok']); $added[] = $res; }
        elseif (($res['code'] ?? '') === 'duplicate_contact') $skipped[] = ['index' => $i, 'name' => $label, 'message' => 'Already in your contacts.'];
        else                                                  $errors[]  = ['index' => $i, 'name' => $label, 'message' => (string)$res['message']];
    }
    if ($many && $added) db_log_activity((int)$actor['id'], 'added ' . count($added) . ' contact(s)' . ($skipped ? ', ' . count($skipped) . ' already there' : '') . ($errors ? ', ' . count($errors) . ' not valid' : ''));
    return gn_ok(['added' => $added, 'skipped' => $skipped, 'errors' => $errors]);
}

/** Change one contact. Only the fields in $body change. */
function gn_contact_update(PDO $db, array $actor, int $id, array $body): array {
    $row = gn_contact_row($db, $actor, $id);
    if (!$row) return gn_err('contact_not_found', 'No such contact.', 404);
    $bad = array_diff(array_keys($body), GN_CONTACT_FIELDS);
    if ($bad) return gn_err('unknown_field', 'Unknown field: ' . implode(', ', $bad) . '.', 400);
    if (!$body) return gn_err('nothing_to_change', 'Send at least one field to change.', 400);
    $cur = ['name' => $row['contact_name'], 'email' => $row['contact_email'], 'phone' => $row['contact_phone'], 'notes' => $row['notes'], 'invite_via' => $row['invite_via'] ?: 'email'];
    $res = gn_contact_save($db, (int)$actor['id'], $id, $body + $cur);
    if (empty($res['ok'])) return $res;
    return gn_ok(['contact' => gn_contact_shape(gn_contact_row($db, $actor, $id))]);
}

/** Remove a contact from the address book. Events they are already invited to are not touched. */
function gn_contact_delete(PDO $db, array $actor, int $id): array {
    $row = gn_contact_row($db, $actor, $id);
    if (!$row) return gn_err('contact_not_found', 'No such contact.', 404);
    $db->prepare('DELETE FROM user_contacts WHERE id = ? AND owner_user_id = ?')->execute([$id, (int)$actor['id']]);
    db_log_activity((int)$actor['id'], "deleted contact id=$id");
    return gn_ok(['id' => $id, 'name' => (string)$row['contact_name'], 'deleted' => true]);
}
