<?php
require_once __DIR__ . '/auth.php';

$current   = require_login();
$db        = get_db();
$site_name = get_setting('site_name', 'Game Night');
$uid       = (int)$current['id'];
$csrf      = csrf_token();

$rows = $db->prepare(
    "SELECT c.*, u.username AS linked_username, u.email AS linked_email,
            u.preferred_contact AS linked_pref
     FROM user_contacts c
     LEFT JOIN users u ON u.id = c.linked_user_id
     WHERE c.owner_user_id = ?
     ORDER BY CASE WHEN c.linked_user_id IS NULL THEN 1 ELSE 0 END, LOWER(c.contact_name)"
);
$rows->execute([$uid]);
$contacts = $rows->fetchAll();

// Labels for a linked user's own preferred_contact (their setting wins over
// the owner's invite_via once they have an account).
$prefLabels = ['email' => 'Email', 'sms' => 'Text', 'whatsapp' => 'WhatsApp', 'both' => 'Email + text', 'none' => 'None'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contacts — <?= htmlspecialchars($site_name) ?></title>
    <link rel="stylesheet" href="/style.css?v=<?= htmlspecialchars(APP_VERSION) ?>">
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
        #cGrid td { padding: .5rem .75rem; border-bottom: 1px solid #e2e8f0; border-right: 1px solid #e2e8f0; vertical-align: middle; color: #1e293b; }
        #cGrid tr:last-child td { border-bottom: none; }
        #cGrid td:last-child, #cGrid th:last-child { border-right: none; }
        #cGrid tr:hover td { background: #f8fafc; }
        #cGrid tr.c-pending td { background: #fffbeb33; }
        #cGrid tr.c-pending:hover td { background: #fef3c7; }

        .c-status-col { width: 100px; text-align: center; }
        .c-name-col { min-width: 160px; }
        .c-phone-col { width: 150px; }
        .c-notes-col { min-width: 140px; max-width: 220px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: #64748b; }
        .c-act-col { width: 90px; text-align: center; }

        #cGrid tbody tr { cursor: pointer; }
        .c-name-link { color: #2563eb; font-weight: 600; text-decoration: none; }
        .c-name-link:hover { text-decoration: underline; }
        .c-muted { color: #94a3b8; }
        .c-msg-btn { background: #fff; color: #2563eb; border: 1.5px solid #bfdbfe; border-radius: 6px; padding: .22rem .6rem; font-size: .75rem; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-block; }
        .c-msg-btn:hover { background: #eff6ff; }

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
                <th title="When a contact has both an email and a phone, invites go out on this channel">Invite via</th>
                <th class="c-notes-col">Notes</th>
                <th class="c-act-col">Message</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($contacts as $c):
            // Linked only counts when the joined user actually still exists.
            $isLinked = !empty($c['linked_user_id']) && !empty($c['linked_username']);
            // No Message button for a contact card that is yourself.
            $canMsg = $isLinked && (int)$c['linked_user_id'] !== $uid;
            $cid = (int)$c['id'];
            $hasBoth = !empty($c['contact_email']) && !empty($c['contact_phone']);
        ?>
            <tr data-contact-id="<?= $cid ?>"<?= $isLinked ? '' : ' class="c-pending"' ?> onclick="location.href='/contact_edit.php?id=<?= $cid ?>'" title="Click to edit this contact">
                <td class="c-status-col">
                    <?php if ($isLinked): ?>
                        <span class="c-badge c-badge-linked">Linked</span>
                    <?php else: ?>
                        <span class="c-badge c-badge-pending">Pending</span>
                    <?php endif; ?>
                </td>
                <td class="c-name-col">
                    <a class="c-name-link" href="/contact_edit.php?id=<?= $cid ?>" onclick="event.stopPropagation()"><?= htmlspecialchars($c['contact_name'] ?? '') ?></a>
                </td>
                <td><?= $c['contact_email'] !== null && $c['contact_email'] !== '' ? htmlspecialchars($c['contact_email']) : '<span class="c-muted">&mdash;</span>' ?></td>
                <td class="c-phone-col"><?= $c['contact_phone'] !== null && $c['contact_phone'] !== '' ? htmlspecialchars($c['contact_phone']) : '<span class="c-muted">&mdash;</span>' ?></td>
                <td>
                    <?php if ($isLinked): ?>
                        <?= htmlspecialchars($prefLabels[$c['linked_pref'] ?? 'email'] ?? 'Email') ?>
                        <span class="c-muted" style="font-size:.72rem" title="This person has an account; invites follow their own notification preference">(theirs)</span>
                    <?php elseif ($hasBoth): ?>
                        <?= ($c['invite_via'] ?? 'email') === 'sms' ? 'Text' : 'Email' ?>
                    <?php else: ?>
                        <span class="c-muted">&mdash;</span>
                    <?php endif; ?>
                </td>
                <td class="c-notes-col" title="<?= htmlspecialchars($c['notes'] ?? '') ?>"><?= $c['notes'] !== null && $c['notes'] !== '' ? htmlspecialchars($c['notes']) : '' ?></td>
                <td class="c-act-col" onclick="event.stopPropagation()">
                    <?php if ($canMsg): ?>
                    <a class="c-msg-btn" href="/message_thread.php?user=<?= (int)$c['linked_user_id'] ?>" title="Send a private message">&#9993; Message</a>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <p style="color:#94a3b8;font-size:.78rem;margin-top:.5rem">Click any contact to edit their details, invite channel, or notes.</p>
    <?php endif; ?>
</div>

<script>
var CSRF = <?= json_encode($csrf) ?>;

function post(data) {
    var fd = new FormData();
    fd.append('csrf_token', CSRF);
    for (var k in data) fd.append(k, data[k]);
    // keepalive: lets an autosave finish even if the user navigates away
    // mid-request (the cause of "my phone number didn't stick").
    return fetch('/contacts_dl.php', { method: 'POST', body: fd, credentials: 'same-origin', keepalive: true }).then(function(r) { return r.json(); });
}


function addContact() {
    var name  = document.getElementById('acName').value.trim();
    var email = document.getElementById('acEmail').value.trim();
    var phone = document.getElementById('acPhone').value.trim();
    if (!name)  { pkAlert('Name is required.'); return; }
    if (!email && !phone) { pkAlert('Enter an email or phone.'); return; }
    post({ action: 'add_contact', contact_name: name, contact_email: email, contact_phone: phone }).then(function(j) {
        if (j.ok) location.reload();
        else pkAlert(j.error || 'Failed');
    });
}

</script>

<?php require __DIR__ . '/_footer.php'; ?>
<script src="/_phone_input.js"></script>
<script>initPhoneAutoFormat();</script>
</body>
</html>
