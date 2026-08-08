<?php
/**
 * Edit one contact — reached by clicking a row on /contacts.php. All changes
 * save in one explicit Save (contacts_dl.php save_row); Delete and Message
 * live here too, keeping the list page clean.
 */
require_once __DIR__ . '/auth.php';

$current   = require_login();
$db        = get_db();
$site_name = get_setting('site_name', 'Game Night');
$uid       = (int)$current['id'];
$csrf      = csrf_token();

$cid = (int)($_GET['id'] ?? 0);
$rs = $db->prepare(
    "SELECT c.*, u.username AS linked_username, u.preferred_contact AS linked_pref
     FROM user_contacts c
     LEFT JOIN users u ON u.id = c.linked_user_id
     WHERE c.id = ? AND c.owner_user_id = ?"
);
$rs->execute([$cid, $uid]);
$c = $rs->fetch();
if (!$c) {
    http_response_code(404);
    exit('Contact not found.');
}
$isLinked = !empty($c['linked_user_id']) && !empty($c['linked_username']);
$canMsg   = $isLinked && (int)$c['linked_user_id'] !== $uid;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Contact &ndash; <?= htmlspecialchars($site_name) ?></title>
    <link rel="stylesheet" href="/style.css?v=<?= htmlspecialchars(APP_VERSION . '.' . (@filemtime(__DIR__ . '/style.css') ?: 0)) ?>">
    <style>
        .ce-wrap { max-width: 560px; margin: 1.25rem auto 2rem; padding: 0 1rem; }
        .ce-head { display: flex; align-items: center; gap: .6rem; margin-bottom: 1rem; flex-wrap: wrap; }
        .ce-head h1 { font-size: 1.3rem; font-weight: 800; color: #1e293b; margin: 0; flex: 1; }
        .ce-card { background: #fff; border: 1.5px solid #e2e8f0; border-radius: 12px; padding: 1.1rem 1.25rem; }
        .ce-field { margin-bottom: .9rem; }
        .ce-field label { display: block; font-size: .8rem; font-weight: 700; color: #475569; margin-bottom: .25rem; }
        .ce-field input, .ce-field select, .ce-field textarea {
            width: 100%; padding: .5rem .65rem; border: 1.5px solid #cbd5e1; border-radius: 8px;
            font: inherit; color: #1e293b; box-sizing: border-box; background: #fff;
        }
        .ce-field .ce-hint { font-size: .75rem; color: #94a3b8; margin-top: .25rem; }
        .ce-actions { display: flex; gap: .6rem; align-items: center; margin-top: 1.1rem; flex-wrap: wrap; }
        .ce-del { margin-left: auto; background: #fff; color: #dc2626; border: 1.5px solid #fecaca; border-radius: 8px; padding: .45rem .9rem; font-size: .85rem; font-weight: 600; cursor: pointer; }
        .ce-del:hover { background: #fee2e2; }
        .c-badge { display: inline-block; font-size: .7rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; padding: .15rem .5rem; border-radius: 999px; }
        .c-badge-linked { background: #dcfce7; color: #166534; }
        .c-badge-pending { background: #fef3c7; color: #92400e; }
    </style>
</head>
<body>
<?php $nav_active = 'contacts'; $nav_user = $current; require __DIR__ . '/_nav.php'; ?>
<div class="ce-wrap">
    <div class="ce-head">
        <a href="/contacts.php" class="btn btn-outline" style="font-size:.8rem;padding:.3rem .6rem">&laquo; Contacts</a>
        <h1>Edit Contact</h1>
        <?php if ($isLinked): ?>
            <span class="c-badge c-badge-linked" title="This contact has a <?= htmlspecialchars($site_name) ?> account (<?= htmlspecialchars($c['linked_username']) ?>)">Linked</span>
            <?php if ($canMsg): ?>
            <a class="btn btn-outline" style="font-size:.8rem;padding:.3rem .6rem" href="/message_thread.php?user=<?= (int)$c['linked_user_id'] ?>">&#9993; Message</a>
            <?php endif; ?>
        <?php else: ?>
            <span class="c-badge c-badge-pending" title="No account yet — invites go to their email or phone">Pending</span>
        <?php endif; ?>
    </div>

    <div class="ce-card">
        <div class="ce-field">
            <label for="ceName">Name</label>
            <input type="text" id="ceName" value="<?= htmlspecialchars($c['contact_name'] ?? '') ?>">
        </div>
        <div class="ce-field">
            <label for="ceEmail">Email</label>
            <input type="email" id="ceEmail" value="<?= htmlspecialchars($c['contact_email'] ?? '') ?>" placeholder="(none)">
        </div>
        <div class="ce-field">
            <label for="cePhone">Phone</label>
            <input type="tel" id="cePhone" value="<?= htmlspecialchars($c['contact_phone'] ?? '') ?>" placeholder="(none)">
            <div class="ce-hint">A contact needs at least an email or a phone number.</div>
        </div>
        <div class="ce-field">
            <label for="ceVia">Send invites via</label>
            <?php if ($isLinked):
                $prefLabels = ['email' => 'Email', 'sms' => 'Text message', 'whatsapp' => 'WhatsApp', 'both' => 'Email + text', 'none' => 'None'];
            ?>
            <input type="text" value="<?= htmlspecialchars($prefLabels[$c['linked_pref'] ?? 'email'] ?? 'Email') ?> (their setting)" disabled style="background:#f8fafc;color:#64748b">
            <input type="hidden" id="ceVia" value="<?= htmlspecialchars($c['invite_via'] ?? 'email') ?>">
            <div class="ce-hint">This contact has a <?= htmlspecialchars($site_name) ?> account, so invites follow the contact method they chose in their own settings.</div>
            <?php else: ?>
            <select id="ceVia">
                <option value="email" <?= ($c['invite_via'] ?? 'email') !== 'sms' ? 'selected' : '' ?>>Email</option>
                <option value="sms" <?= ($c['invite_via'] ?? 'email') === 'sms' ? 'selected' : '' ?>>Text message</option>
            </select>
            <div class="ce-hint">Used when both an email and a phone are on file. Once this person registers, their own notification preference takes over.</div>
            <?php endif; ?>
        </div>
        <div class="ce-field">
            <label for="ceNotes">Notes</label>
            <textarea id="ceNotes" rows="2"><?= htmlspecialchars($c['notes'] ?? '') ?></textarea>
        </div>
        <div class="ce-actions">
            <button class="btn" type="button" id="ceSave" onclick="saveContact(this)">Save</button>
            <a href="/contacts.php" class="btn btn-outline">Cancel</a>
            <button class="ce-del" type="button" onclick="deleteContact()">Delete contact</button>
        </div>
    </div>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
<script src="/_phone_input.js"></script>
<script>
initPhoneAutoFormat();
var CSRF = <?= json_encode($csrf) ?>;
var CID = <?= $cid ?>;

function post(data) {
    var fd = new FormData();
    fd.append('csrf_token', CSRF);
    for (var k in data) fd.append(k, data[k]);
    return fetch('/contacts_dl.php', { method: 'POST', body: fd, credentials: 'same-origin', keepalive: true }).then(function(r) { return r.json(); });
}

function saveContact(btn) {
    var p = post({
        action: 'save_row',
        contact_id: CID,
        contact_name: document.getElementById('ceName').value,
        contact_email: document.getElementById('ceEmail').value,
        contact_phone: document.getElementById('cePhone').value,
        invite_via: document.getElementById('ceVia').value,
        notes: document.getElementById('ceNotes').value
    }).then(function(j) {
        if (!j.ok) { pkAlert(j.error || 'Save failed.'); return; }
        location.href = '/contacts.php';
    });
    if (typeof pkBusy === 'function') pkBusy(btn, p);
}

async function deleteContact() {
    if (!(await pkConfirm('Delete this contact? This cannot be undone.'))) return;
    post({ action: 'delete_contact', contact_id: CID }).then(function(j) {
        if (j.ok) location.href = '/contacts.php';
        else pkAlert(j.error || 'Failed.');
    });
}
</script>
</body>
</html>
