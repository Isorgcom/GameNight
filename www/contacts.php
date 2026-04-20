<?php
require_once __DIR__ . '/auth.php';

$current   = require_login();
$db        = get_db();
$site_name = get_setting('site_name', 'Game Night');
$uid       = (int)$current['id'];
$csrf      = csrf_token();

$rows = $db->prepare(
    "SELECT c.*, u.username AS linked_username, u.email AS linked_email
     FROM user_contacts c
     LEFT JOIN users u ON u.id = c.linked_user_id
     WHERE c.owner_user_id = ?
     ORDER BY CASE WHEN c.linked_user_id IS NULL THEN 1 ELSE 0 END, LOWER(c.contact_name)"
);
$rows->execute([$uid]);
$contacts = $rows->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contacts — <?= htmlspecialchars($site_name) ?></title>
    <link rel="stylesheet" href="/style.css">
    <style>
        .c-wrap { max-width: 1100px; margin: 1.25rem auto; padding: 0 1rem; }
        .c-header { display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 1rem; margin-bottom: 1rem; }
        .c-header h1 { font-size: 1.5rem; font-weight: 700; margin: 0; }
        .c-header p { color: #64748b; font-size: .9rem; margin: .25rem 0 0; }

        .c-toolbar { display: flex; gap: .5rem; flex-wrap: wrap; align-items: center; margin-bottom: .75rem; }
        .c-btn { background: #2563eb; color: #fff; border: none; border-radius: 6px; padding: .45rem .9rem; font-size: .85rem; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-block; }
        .c-btn:hover { background: #1d4ed8; }
        .c-btn-ghost { background: #fff; color: #475569; border: 1.5px solid #cbd5e1; }
        .c-btn-ghost:hover { background: #f1f5f9; }

        .c-add-card { background: #f8fafc; border: 1.5px solid #e2e8f0; border-radius: 10px; padding: .75rem 1rem; margin-bottom: .75rem; }
        .c-add-card h3 { margin: 0 0 .5rem; font-size: 1rem; }
        .c-add-grid { display: grid; grid-template-columns: 1fr 1.5fr 1fr auto; gap: .5rem; align-items: end; }
        .c-add-grid label { font-size: .8rem; color: #475569; font-weight: 600; display: flex; flex-direction: column; gap: .2rem; }
        .c-add-grid input { padding: .4rem .5rem; border: 1.5px solid #cbd5e1; border-radius: 6px; font: inherit; }

        .c-import-card { display: none; background: #fffbeb; border: 1.5px solid #fde68a; border-radius: 10px; padding: .75rem 1rem; margin-bottom: .75rem; gap: .75rem; flex-wrap: wrap; align-items: center; }
        .c-import-card.open { display: flex; }

        #cGrid { width: 100%; border-collapse: collapse; font-size: .875rem; background: #fff; border: 1.5px solid #e2e8f0; border-radius: 10px; overflow: hidden; }
        #cGrid th { background: #f1f5f9; color: #475569; font-weight: 600; font-size: .72rem; text-transform: uppercase; letter-spacing: .04em; padding: .55rem .75rem; border-bottom: 2px solid #e2e8f0; border-right: 1px solid #e2e8f0; text-align: left; white-space: nowrap; position: sticky; top: 0; z-index: 2; }
        #cGrid td { padding: 0; border-bottom: 1px solid #e2e8f0; border-right: 1px solid #e2e8f0; vertical-align: middle; }
        #cGrid tr:last-child td { border-bottom: none; }
        #cGrid td:last-child, #cGrid th:last-child { border-right: none; }
        #cGrid tr:hover td { background: #f8fafc; }
        #cGrid tr.c-pending td { background: #fffbeb33; }
        #cGrid tr.c-pending:hover td { background: #fef3c7; }

        .c-status-col { width: 100px; text-align: center; padding: .5rem .75rem !important; }
        .c-name-col { min-width: 160px; }
        .c-phone-col { width: 150px; }
        .c-notes-col { min-width: 140px; }
        .c-act-col { width: 56px; text-align: center; }

        .c-cell-input { width: 100%; padding: .45rem .6rem; border: none; background: transparent; font: inherit; color: #1e293b; box-sizing: border-box; outline: none; }
        .c-cell-input:focus { background: #eff6ff; outline: 2px solid #2563eb; outline-offset: -2px; border-radius: 2px; }

        .c-badge { display: inline-block; font-size: .7rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; padding: .15rem .5rem; border-radius: 999px; }
        .c-badge-linked { background: #dcfce7; color: #166534; }
        .c-badge-pending { background: #fef3c7; color: #92400e; }

        .c-del-btn { background: transparent; border: 1px solid #fecaca; color: #dc2626; border-radius: 6px; padding: .25rem .55rem; font-size: .95rem; line-height: 1; cursor: pointer; }
        .c-del-btn:hover { background: #fee2e2; }

        #cSaved { display: none; margin: .5rem 0; font-size: .78rem; color: #16a34a; }

        @media (max-width: 720px) {
            .c-add-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<?php $nav_active = 'contacts'; $nav_user = $current; require __DIR__ . '/_nav.php'; ?>

<div class="c-wrap">
    <div class="c-header">
        <div>
            <h1>My Contacts</h1>
            <p>Your private address book. Only visible to you — never shared with other users.</p>
        </div>
    </div>

    <div class="c-toolbar">
        <a class="c-btn c-btn-ghost" href="/contacts_dl.php?action=export">&#8681; Export CSV</a>
        <button class="c-btn c-btn-ghost" type="button" onclick="document.getElementById('cImport').classList.toggle('open')">&#8679; Import CSV</button>
        <span style="color:#94a3b8;font-size:.78rem;margin-left:auto"><?= count($contacts) ?> contact<?= count($contacts) === 1 ? '' : 's' ?></span>
    </div>

    <div class="c-add-card">
        <h3>Add a contact</h3>
        <div class="c-add-grid">
            <label>Name <input type="text" id="acName" placeholder="Display name"></label>
            <label>Email <input type="email" id="acEmail" placeholder="name@example.com"></label>
            <label>Phone <input type="tel" id="acPhone" placeholder="Optional"></label>
            <button class="c-btn" type="button" onclick="addContact()">Add</button>
        </div>
    </div>

    <div class="c-import-card" id="cImport">
        <form method="post" action="/contacts_dl.php" enctype="multipart/form-data" style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;flex:1">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="action" value="import_csv">
            <input type="file" name="csv_file" accept=".csv" required style="font-size:.82rem;padding:.3rem .5rem;border:1.5px solid #e2e8f0;border-radius:6px;background:#fff">
            <button type="submit" class="c-btn">Import</button>
        </form>
        <div style="font-size:.78rem;color:#92400e;flex-basis:100%">
            CSV columns: <code>name, email, phone</code>. Existing contacts (by email) are skipped.
        </div>
    </div>

    <?php if (!empty($_SESSION['flash'])):
        $f = $_SESSION['flash']; unset($_SESSION['flash']);
        $c = $f['type'] === 'success' ? 'background:#dcfce7;color:#14532d;border:1px solid #86efac' : 'background:#fee2e2;color:#7f1d1d;border:1px solid #fca5a5';
    ?>
        <div style="padding:.55rem .85rem;border-radius:8px;font-size:.85rem;margin-bottom:.75rem;<?= $c ?>"><?= htmlspecialchars($f['msg']) ?></div>
    <?php endif; ?>

    <?php if (empty($contacts)): ?>
        <div style="background:#fff;border:1.5px dashed #cbd5e1;border-radius:10px;padding:2.5rem;text-align:center;color:#94a3b8">
            No contacts yet. Add one above, import a CSV, or invite people to an event — they'll be saved here automatically.
        </div>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table id="cGrid">
        <thead>
            <tr>
                <th class="c-status-col">Status</th>
                <th class="c-name-col">Name</th>
                <th>Email</th>
                <th class="c-phone-col">Phone</th>
                <th class="c-notes-col">Notes</th>
                <th class="c-act-col"></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($contacts as $c):
            $isLinked = !empty($c['linked_user_id']);
            $cid = (int)$c['id'];
        ?>
            <tr data-contact-id="<?= $cid ?>"<?= $isLinked ? '' : ' class="c-pending"' ?>>
                <td class="c-status-col">
                    <?php if ($isLinked): ?>
                        <span class="c-badge c-badge-linked">Linked</span>
                    <?php else: ?>
                        <span class="c-badge c-badge-pending">Pending</span>
                    <?php endif; ?>
                </td>
                <td class="c-name-col">
                    <input type="text" class="c-cell-input" data-field="contact_name" value="<?= htmlspecialchars($c['contact_name'] ?? '') ?>">
                </td>
                <td>
                    <input type="email" class="c-cell-input" data-field="contact_email" value="<?= htmlspecialchars($c['contact_email'] ?? '') ?>" placeholder="(none)">
                </td>
                <td class="c-phone-col">
                    <input type="tel" class="c-cell-input" data-field="contact_phone" value="<?= htmlspecialchars($c['contact_phone'] ?? '') ?>" placeholder="(none)">
                </td>
                <td class="c-notes-col">
                    <input type="text" class="c-cell-input" data-field="notes" value="<?= htmlspecialchars($c['notes'] ?? '') ?>" placeholder="(none)">
                </td>
                <td class="c-act-col">
                    <button class="c-del-btn" type="button" onclick="deleteContact(<?= $cid ?>)" title="Delete">&times;</button>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <div id="cSaved">&#10003; Saved</div>
    <?php endif; ?>
</div>

<script>
var CSRF = <?= json_encode($csrf) ?>;

function post(data) {
    var fd = new FormData();
    fd.append('csrf_token', CSRF);
    for (var k in data) fd.append(k, data[k]);
    return fetch('/contacts_dl.php', { method: 'POST', body: fd, credentials: 'same-origin' }).then(function(r) { return r.json(); });
}

function addContact() {
    var name  = document.getElementById('acName').value.trim();
    var email = document.getElementById('acEmail').value.trim();
    var phone = document.getElementById('acPhone').value.trim();
    if (!name)  { alert('Name is required.'); return; }
    if (!email && !phone) { alert('Enter an email or phone.'); return; }
    post({ action: 'add_contact', contact_name: name, contact_email: email, contact_phone: phone }).then(function(j) {
        if (j.ok) location.reload();
        else alert(j.error || 'Failed');
    });
}

function deleteContact(cid) {
    if (!confirm('Delete this contact?')) return;
    post({ action: 'delete_contact', contact_id: cid }).then(function(j) {
        if (j.ok) {
            var row = document.querySelector('tr[data-contact-id="' + cid + '"]');
            if (row) row.remove();
        } else {
            alert(j.error || 'Failed');
        }
    });
}

// Inline autosave
(function() {
    var grid = document.getElementById('cGrid');
    if (!grid) return;
    var savedInd = document.getElementById('cSaved');
    var savedTimer;
    function flashSaved() {
        if (!savedInd) return;
        savedInd.style.display = 'block';
        clearTimeout(savedTimer);
        savedTimer = setTimeout(function() { savedInd.style.display = 'none'; }, 1500);
    }
    grid.querySelectorAll('.c-cell-input').forEach(function(inp) {
        inp.dataset.orig = inp.value;
        inp.addEventListener('change', function() {
            var row = this.closest('tr');
            var cid = row && row.dataset.contactId;
            if (!cid) return;
            if (this.dataset.orig === this.value) return;
            var orig = this.dataset.orig;
            var self = this;
            post({ action: 'update_contact', contact_id: cid, field: this.dataset.field, value: this.value }).then(function(j) {
                if (j.ok) {
                    self.dataset.orig = self.value;
                    flashSaved();
                } else {
                    alert(j.error || 'Save failed');
                    self.value = orig;
                }
            });
        });
    });
})();
</script>

<?php require __DIR__ . '/_footer.php'; ?>
</body>
</html>
