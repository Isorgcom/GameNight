<?php
require_once __DIR__ . '/auth.php';

$current   = require_login();
$db        = get_db();
$site_name = get_setting('site_name', 'Game Night');
$uid       = (int)$current['id'];
$isAdmin   = ($current['role'] ?? '') === 'admin';

$league_id = (int)($_GET['id'] ?? 0);
if ($league_id <= 0) { header('Location: /leagues.php'); exit; }

$L = $db->prepare('SELECT * FROM leagues WHERE id = ?');
$L->execute([$league_id]);
$league = $L->fetch();
if (!$league) { http_response_code(404); echo 'League not found'; exit; }

$myRole = league_role($league_id, $uid);
$canViewHidden = $isAdmin || $myRole !== null;
if ((int)$league['is_hidden'] === 1 && !$canViewHidden) {
    http_response_code(403); echo 'Not allowed'; exit;
}

$canManageMembers = $isAdmin || in_array($myRole, ['owner', 'manager'], true);
$isOwner          = $isAdmin || $myRole === 'owner';

// ── CSV Export (must happen before any output) ────────────────────────────────
if ($canManageMembers && (($_GET['action'] ?? '') === 'export_members')) {
    $rows = $db->prepare(
        "SELECT lm.role, lm.joined_at, lm.invited_at,
                u.username, u.email, u.phone,
                lm.contact_name, lm.contact_email, lm.contact_phone,
                CASE WHEN lm.user_id IS NULL THEN 'pending' ELSE 'linked' END AS status
         FROM league_members lm
         LEFT JOIN users u ON u.id = lm.user_id
         WHERE lm.league_id = ?
         ORDER BY CASE lm.role WHEN 'owner' THEN 0 WHEN 'manager' THEN 1 ELSE 2 END,
                  LOWER(COALESCE(u.username, lm.contact_name))"
    );
    $rows->execute([$league_id]);
    $rows = $rows->fetchAll();

    $safeName = preg_replace('/[^a-zA-Z0-9]+/', '_', $league['name']);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="league_' . $safeName . '_members_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['name', 'email', 'phone', 'role', 'status', 'joined_at', 'invited_at']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['username'] ?? $r['contact_name'] ?? '',
            $r['email']    ?? $r['contact_email'] ?? '',
            $r['phone']    ?? $r['contact_phone'] ?? '',
            $r['role'],
            $r['status'],
            $r['joined_at']  ?? '',
            $r['invited_at'] ?? '',
        ]);
    }
    fclose($out);
    exit;
}

// ── CSV Import (also pre-output; redirects back on completion) ────────────────
if ($canManageMembers && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import_members') {
    if (!csrf_verify()) {
        $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Invalid request token.'];
        header('Location: /league.php?id=' . $league_id . '&tab=members'); exit;
    }
    $file = $_FILES['csv_file'] ?? null;
    if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
        $_SESSION['flash'] = ['type' => 'error', 'msg' => 'No file uploaded.'];
        header('Location: /league.php?id=' . $league_id . '&tab=members'); exit;
    }

    $send_invites = !empty($_POST['send_invites']);
    $handle = fopen($file['tmp_name'], 'r');
    $header = fgetcsv($handle);
    // Detect columns by header (case-insensitive). Fall back to first three cols in order.
    $colIdx = ['name' => 0, 'email' => 1, 'phone' => 2];
    if ($header) {
        $normalized = array_map(function ($h) { return strtolower(trim((string)$h)); }, $header);
        foreach (['name','email','phone'] as $key) {
            $match = array_search($key, $normalized, true);
            if ($match === false && $key === 'name') {
                $match = array_search('display_name', $normalized, true);
                if ($match === false) $match = array_search('username', $normalized, true);
            }
            if ($match !== false) $colIdx[$key] = (int)$match;
        }
    }

    $lname = (string)$league['name'];
    $imported = 0; $linked = 0; $pending = 0; $skipped = 0; $errors = [];

    while (($row = fgetcsv($handle)) !== false) {
        $name  = trim((string)($row[$colIdx['name']]  ?? ''));
        $email = strtolower(trim((string)($row[$colIdx['email']] ?? '')));
        $phoneRaw = trim((string)($row[$colIdx['phone']] ?? ''));
        $phone = $phoneRaw !== '' ? normalize_phone($phoneRaw) : '';

        if ($name === '' && $email === '' && $phone === '') continue;     // blank line
        if ($name === '')                    { $errors[] = 'missing name ' . ($email ?: $phone); continue; }
        if ($email === '' && $phone === '')  { $errors[] = $name . ' (no email/phone)'; continue; }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { $errors[] = $name . ' (bad email)'; continue; }

        // Does a user account exist for this email/phone?
        $existing = null;
        if ($email !== '') {
            $u = $db->prepare('SELECT id, username, email, phone, preferred_contact FROM users WHERE LOWER(email) = ? LIMIT 1');
            $u->execute([$email]);
            $existing = $u->fetch() ?: null;
        }
        if (!$existing && $phone !== '') {
            $u = $db->prepare('SELECT id, username, email, phone, preferred_contact FROM users WHERE phone = ? LIMIT 1');
            $u->execute([$phone]);
            $existing = $u->fetch() ?: null;
        }

        if ($existing) {
            // Already a member?
            if (league_role($league_id, (int)$existing['id']) !== null) { $skipped++; continue; }
            try {
                $db->prepare(
                    "INSERT INTO league_members (league_id, user_id, role, invited_by, invited_at)
                     VALUES (?, ?, 'member', ?, CURRENT_TIMESTAMP)"
                )->execute([$league_id, (int)$existing['id'], $uid]);
                $linked++; $imported++;
                if ($send_invites) {
                    $url = get_site_url() . '/league.php?id=' . $league_id;
                    if (get_setting('url_shortener_enabled') === '1') { $url = shorten_url($url); }
                    send_notification(
                        $existing['username'] ?? '', $existing['email'] ?? '', $existing['phone'] ?? '',
                        $existing['preferred_contact'] ?? 'email',
                        'Added to ' . $lname,
                        'You were added to the league "' . $lname . '". View: ' . $url,
                        '<p>You were added to the league <strong>' . htmlspecialchars($lname) . '</strong>. <a href="' . htmlspecialchars($url) . '">View league</a></p>'
                    );
                }
            } catch (Throwable $e) { $errors[] = $name; }
            continue;
        }

        // Duplicate pending row for this email?
        if ($email !== '') {
            $dup = $db->prepare('SELECT 1 FROM league_members WHERE league_id = ? AND user_id IS NULL AND LOWER(contact_email) = ? LIMIT 1');
            $dup->execute([$league_id, $email]);
            if ($dup->fetchColumn()) { $skipped++; continue; }
        }

        $token = bin2hex(random_bytes(16));
        try {
            $db->prepare(
                "INSERT INTO league_members (league_id, user_id, role, contact_name, contact_email, contact_phone, invited_by, invited_at, invite_token)
                 VALUES (?, NULL, 'member', ?, ?, ?, ?, CURRENT_TIMESTAMP, ?)"
            )->execute([$league_id, $name, $email ?: null, $phone ?: null, $uid, $token]);
            $pending++; $imported++;
            if ($send_invites) {
                $inviteUrl = get_site_url() . '/league_invite.php?token=' . $token;
                if (get_setting('url_shortener_enabled') === '1') { $inviteUrl = shorten_url($inviteUrl); }
                send_notification(
                    $name, $email, $phone,
                    $email !== '' ? 'email' : 'sms',
                    'Invitation to join ' . $lname,
                    'You have been invited to join the league "' . $lname . '". Sign up: ' . $inviteUrl,
                    '<p>Hi ' . htmlspecialchars($name) . ',</p>'
                    . '<p>You have been invited to join the league <strong>' . htmlspecialchars($lname) . '</strong>.</p>'
                    . '<p><a href="' . htmlspecialchars($inviteUrl) . '">Accept invite &amp; sign up</a></p>'
                );
            }
        } catch (Throwable $e) { $errors[] = $name; }
    }
    fclose($handle);

    $msg = "Imported $imported ($linked existing, $pending pending).";
    if ($skipped) $msg .= " Skipped $skipped (already a member).";
    if ($errors)  $msg .= ' Errors: ' . htmlspecialchars(implode(', ', array_slice($errors, 0, 5))) . (count($errors) > 5 ? '…' : '');
    $_SESSION['flash'] = ['type' => $imported > 0 ? 'success' : 'error', 'msg' => $msg];
    header('Location: /league.php?id=' . $league_id . '&tab=members');
    exit;
}

$allowed_tabs = ['members', 'events', 'requests', 'settings'];
$tab = $_GET['tab'] ?? 'members';
if (!in_array($tab, $allowed_tabs, true)) $tab = 'members';
if ($tab === 'requests' && !$canManageMembers) $tab = 'members';
if ($tab === 'settings' && !$isOwner)          $tab = 'members';

// Load members (includes pending contacts — rows with user_id IS NULL)
$mStmt = $db->prepare(
    "SELECT lm.*,
            u.username         AS user_username,
            u.email            AS user_email,
            u.phone            AS phone,
            COALESCE(u.username, lm.contact_name) AS display_name
     FROM league_members lm
     LEFT JOIN users u ON u.id = lm.user_id
     WHERE lm.league_id = ?
     ORDER BY CASE lm.role WHEN 'owner' THEN 0 WHEN 'manager' THEN 1 ELSE 2 END,
              CASE WHEN lm.user_id IS NULL THEN 1 ELSE 0 END,
              LOWER(COALESCE(u.username, lm.contact_name))"
);
$mStmt->execute([$league_id]);
$members = $mStmt->fetchAll();

// Load events visible to viewer (scoped to this league)
$vis = event_visibility_sql('e', $uid);
$evStmt = $db->prepare(
    "SELECT e.id, e.title, e.start_date, e.visibility
     FROM events e
     WHERE e.league_id = ? AND {$vis['sql']}
     ORDER BY e.start_date DESC"
);
$evStmt->execute(array_merge([$league_id], $vis['params']));
$leagueEvents = $evStmt->fetchAll();

// Load join requests (only if manager+)
$requests = [];
if ($canManageMembers) {
    $rqStmt = $db->prepare(
        "SELECT r.*, u.username FROM league_join_requests r
         JOIN users u ON u.id = r.user_id
         WHERE r.league_id = ? AND r.status = 'pending'
         ORDER BY r.requested_at ASC"
    );
    $rqStmt->execute([$league_id]);
    $requests = $rqStmt->fetchAll();
}

$csrf = csrf_token();
$member_count = count($members);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($league['name']) ?> — <?= htmlspecialchars($site_name) ?></title>
    <link rel="stylesheet" href="/style.css">
    <style>
        .lg-wrap { max-width: 960px; margin: 1.5rem auto; padding: 0 1rem; }
        .lg-head { background: #fff; border: 1.5px solid #e2e8f0; border-radius: 10px; padding: 1.2rem; margin-bottom: 1rem; }
        .lg-head h1 { font-size: 1.5rem; font-weight: 700; margin: 0 0 .25rem; }
        .lg-head p { color: #64748b; margin: 0 0 .5rem; font-size: .9rem; }
        .lg-head .lg-meta { font-size: .8rem; color: #94a3b8; }
        .lg-pill { display: inline-block; font-size: .7rem; font-weight: 700; padding: .15rem .5rem; border-radius: 999px; background: #e2e8f0; color: #475569; }
        .lg-pill.hidden { background: #fef3c7; color: #92400e; }
        .lg-tabs { display: flex; gap: .25rem; border-bottom: 1.5px solid #e2e8f0; margin-bottom: 1rem; overflow-x: auto; }
        .lg-tab { padding: .6rem 1rem; font-weight: 600; color: #64748b; border-bottom: 2.5px solid transparent; text-decoration: none; white-space: nowrap; }
        .lg-tab.active { color: #2563eb; border-bottom-color: #2563eb; }
        .lg-card { background: #fff; border: 1.5px solid #e2e8f0; border-radius: 10px; padding: .9rem 1.1rem; margin-bottom: .5rem; display: flex; justify-content: space-between; align-items: center; gap: 1rem; flex-wrap: wrap; }
        .lg-btn { background: #2563eb; color: #fff; border: none; border-radius: 6px; padding: .4rem .8rem; font-size: .8rem; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-block; }
        .lg-btn:hover { background: #1d4ed8; }
        .lg-btn-ghost { background: transparent; color: #475569; border: 1.5px solid #cbd5e1; }
        .lg-btn-ghost:hover { background: #f1f5f9; }
        .lg-btn-danger { background: #dc2626; }
        .lg-btn-danger:hover { background: #b91c1c; }
        .lg-role { font-size: .7rem; font-weight: 700; text-transform: uppercase; padding: .15rem .5rem; border-radius: 999px; }
        .lg-role-owner   { background: #fef3c7; color: #92400e; }
        .lg-role-manager { background: #dbeafe; color: #1e40af; }
        .lg-role-member  { background: #e2e8f0; color: #475569; }
        .lg-actions { display: flex; gap: .4rem; flex-wrap: wrap; }
        .lg-empty { text-align: center; padding: 2rem; color: #94a3b8; }
        .lg-form label { display: block; margin-bottom: 1rem; font-weight: 600; color: #334155; font-size: .9rem; }
        .lg-form input[type=text], .lg-form textarea, .lg-form select { width: 100%; padding: .5rem; border: 1.5px solid #cbd5e1; border-radius: 6px; font: inherit; margin-top: .3rem; }
        .lg-form textarea { min-height: 80px; resize: vertical; }
        .lg-hint { font-size: .75rem; font-weight: normal; color: #64748b; }

        /* ── Members spreadsheet-style grid ── */
        #membersGrid { border-collapse: collapse; width: 100%; font-size: .85rem; }
        #membersGrid th {
            background: #f1f5f9; color: #475569; font-weight: 600;
            font-size: .72rem; text-transform: uppercase; letter-spacing: .04em;
            padding: .55rem .75rem; border-bottom: 2px solid #e2e8f0;
            border-right: 1px solid #e2e8f0; text-align: left; white-space: nowrap;
            position: sticky; top: 0; z-index: 2;
        }
        #membersGrid td {
            padding: 0; border-bottom: 1px solid #e2e8f0; border-right: 1px solid #e2e8f0;
            vertical-align: middle;
        }
        #membersGrid tr:last-child td { border-bottom: none; }
        #membersGrid td:last-child, #membersGrid th:last-child { border-right: none; }
        #membersGrid tr:hover td { background: #f8fafc; }
        #membersGrid tr.mg-pending td { background: #fffbeb33; }
        #membersGrid tr.mg-pending:hover td { background: #fef3c7; }

        .mg-status-col { width: 90px;  text-align: center; }
        .mg-status-col, .mg-status-col + td { text-align: left; }
        .mg-status-col .lg-role { display: inline-block; margin-left: .5rem; }
        .mg-name-col   { min-width: 160px; }
        .mg-phone-col  { width: 150px; }
        .mg-role-col   { width: 130px; text-align: center; }
        .mg-joined-col { width: 160px; color: #64748b; padding: .5rem .75rem !important; font-size: .78rem; }
        .mg-act-col    { width: 86px; text-align: center; }

        .mg-cell-input, .mg-cell-select {
            width: 100%; padding: .45rem .6rem; border: none; background: transparent;
            font: inherit; color: #1e293b; box-sizing: border-box; outline: none;
        }
        .mg-cell-input:focus, .mg-cell-select:focus {
            background: #eff6ff; outline: 2px solid #2563eb; outline-offset: -2px; border-radius: 2px;
        }
        .mg-cell-ro { padding: .5rem .75rem; color: #334155; }

        .mg-act-wrap { display: inline-flex; gap: .25rem; padding: .3rem; }
        .mg-iconbtn {
            width: 28px; height: 28px; border: 1px solid #cbd5e1; background: #fff; color: #475569;
            border-radius: 6px; cursor: pointer; font-size: 1rem; line-height: 1;
            display: inline-flex; align-items: center; justify-content: center;
        }
        .mg-iconbtn:hover { background: #f1f5f9; color: #1e293b; }
        .mg-iconbtn-danger { color: #dc2626; border-color: #fecaca; }
        .mg-iconbtn-danger:hover { background: #fee2e2; }
    </style>
</head>
<body>

<?php $nav_active = 'leagues'; $nav_user = $current; require __DIR__ . '/_nav.php'; ?>

<div class="lg-wrap">
    <?php if (!empty($_SESSION['flash'])):
        $_flash = $_SESSION['flash']; unset($_SESSION['flash']);
        $_fcls  = $_flash['type'] === 'success' ? 'background:#dcfce7;color:#14532d;border:1px solid #86efac'
                 : ($_flash['type'] === 'error' ? 'background:#fee2e2;color:#7f1d1d;border:1px solid #fca5a5'
                 : 'background:#f1f5f9;color:#334155;border:1px solid #cbd5e1'); ?>
        <div style="padding:.6rem .9rem;border-radius:8px;font-size:.85rem;margin-bottom:.75rem;<?= $_fcls ?>"><?= $_flash['msg'] ?></div>
    <?php endif; ?>
    <div class="lg-head">
        <a href="/leagues.php" style="font-size:.85rem;color:#64748b;text-decoration:none">&larr; All Leagues</a>
        <h1 style="margin-top:.4rem">
            <?= htmlspecialchars($league['name']) ?>
            <?php if ($myRole): ?><span class="lg-role lg-role-<?= htmlspecialchars($myRole) ?>" style="font-size:.65rem;vertical-align:middle;margin-left:.4rem"><?= htmlspecialchars($myRole) ?></span><?php endif; ?>
            <?php if ((int)$league['is_hidden'] === 1): ?><span class="lg-pill hidden" style="margin-left:.4rem">Hidden</span><?php endif; ?>
        </h1>
        <?php if ($league['description']): ?><p><?= nl2br(htmlspecialchars($league['description'])) ?></p><?php endif; ?>
        <div class="lg-meta">
            <?= $member_count ?> member<?= $member_count === 1 ? '' : 's' ?>
            &middot; Join mode: <?= htmlspecialchars($league['approval_mode']) ?>
            <?php if ($myRole !== null && $myRole !== 'owner'): ?>
                &middot; <button class="lg-btn lg-btn-ghost" style="padding:.2rem .5rem;font-size:.7rem" onclick="leaveLeague()">Leave league</button>
            <?php endif; ?>
        </div>
    </div>

    <div class="lg-tabs">
        <a class="lg-tab<?= $tab==='members'  ? ' active' : '' ?>" href="?id=<?= $league_id ?>&tab=members">Members (<?= $member_count ?>)</a>
        <a class="lg-tab<?= $tab==='events'   ? ' active' : '' ?>" href="?id=<?= $league_id ?>&tab=events">Events (<?= count($leagueEvents) ?>)</a>
        <?php if ($canManageMembers): ?>
        <a class="lg-tab<?= $tab==='requests' ? ' active' : '' ?>" href="?id=<?= $league_id ?>&tab=requests">Requests (<?= count($requests) ?>)</a>
        <?php endif; ?>
        <?php if ($isOwner): ?>
        <a class="lg-tab<?= $tab==='settings' ? ' active' : '' ?>" href="?id=<?= $league_id ?>&tab=settings">Settings</a>
        <?php endif; ?>
    </div>

    <?php if ($tab === 'members'): ?>
        <?php if ($canManageMembers): ?>
        <div class="lg-card" style="display:block;background:#f8fafc">
            <h3 style="margin:0 0 .5rem;font-size:1rem">Add a member</h3>
            <p style="font-size:.8rem;color:#64748b;margin:0 0 .5rem">
                Add people by email or phone. If they already have an account, they're added instantly.
                Otherwise they're saved as a pending contact and will receive an invite to sign up —
                once they do, they become a full member automatically.
            </p>
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr auto;gap:.5rem;align-items:end">
                <label style="font-size:.8rem;color:#475569">Name
                    <input type="text" id="acName" placeholder="Display name"
                           style="width:100%;padding:.4rem;border:1.5px solid #cbd5e1;border-radius:6px;font:inherit;margin-top:.2rem">
                </label>
                <label style="font-size:.8rem;color:#475569">Email
                    <input type="email" id="acEmail" placeholder="name@example.com"
                           style="width:100%;padding:.4rem;border:1.5px solid #cbd5e1;border-radius:6px;font:inherit;margin-top:.2rem">
                </label>
                <label style="font-size:.8rem;color:#475569">Phone
                    <input type="tel" id="acPhone" placeholder="(optional)"
                           style="width:100%;padding:.4rem;border:1.5px solid #cbd5e1;border-radius:6px;font:inherit;margin-top:.2rem">
                </label>
                <button class="lg-btn" type="button" onclick="addContact()" style="height:fit-content">Add</button>
            </div>
        </div>
        <div class="lg-card" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;background:#f8fafc">
            <strong style="font-size:.9rem">Bulk</strong>
            <a class="lg-btn lg-btn-ghost" href="/league.php?id=<?= $league_id ?>&action=export_members">&#8681; Export CSV</a>
            <button class="lg-btn lg-btn-ghost" type="button"
                    onclick="var w=document.getElementById('lgImportWrap'); w.style.display = w.style.display==='none' ? 'flex' : 'none'">
                &#8679; Import CSV
            </button>
            <span style="color:#94a3b8;font-size:.78rem;margin-left:auto">CSV columns: <code>name, email, phone</code></span>
        </div>
        <div id="lgImportWrap" class="lg-card" style="display:none;gap:.75rem;flex-wrap:wrap;align-items:center;background:#fffbeb;border-color:#fde68a">
            <form method="post" action="/league.php?id=<?= $league_id ?>" enctype="multipart/form-data"
                  style="display:flex;align-items:center;gap:.6rem;flex-wrap:wrap;flex:1">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="action" value="import_members">
                <input type="file" name="csv_file" accept=".csv" required
                       style="font-size:.82rem;border:1.5px solid #e2e8f0;border-radius:6px;padding:.3rem .5rem;background:#fff">
                <label style="display:inline-flex;align-items:center;gap:.3rem;font-size:.82rem;color:#475569;cursor:pointer">
                    <input type="checkbox" name="send_invites" value="1" checked> Send invite emails/SMS
                </label>
                <button type="submit" class="lg-btn">Import</button>
            </form>
            <div style="font-size:.78rem;color:#92400e;flex-basis:100%">
                Existing members are skipped. Rows with a matching email/phone that already have a user account become full members; everyone else becomes a pending contact and receives an invite link.
            </div>
        </div>
        <?php endif; ?>

        <div style="background:#fff;border:1.5px solid #e2e8f0;border-radius:10px;overflow-x:auto">
        <table id="membersGrid">
            <thead>
                <tr>
                    <th class="mg-status-col">Status</th>
                    <th class="mg-name-col">Name</th>
                    <th>Email</th>
                    <th class="mg-phone-col">Phone</th>
                    <th class="mg-role-col">Role</th>
                    <th class="mg-joined-col">Joined / Invited</th>
                    <?php if ($canManageMembers): ?><th class="mg-act-col"></th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($members as $m):
                $isPending = empty($m['user_id']);
                $memId     = (int)$m['id'];
                $targetUid = (int)($m['user_id'] ?? 0);
                $dispName  = $isPending ? ($m['contact_name']  ?? '') : ($m['user_username'] ?? '');
                $dispEmail = $isPending ? ($m['contact_email'] ?? '') : ($m['user_email']    ?? '');
                $dispPhone = $isPending ? ($m['contact_phone'] ?? '') : ($m['phone']          ?? '');
                // Editability: managers+owners can edit pending rows; only owner can change linked role.
                $editPending = $canManageMembers && $isPending;
                $editRole    = $canManageMembers && !$isPending && $m['role'] !== 'owner' && $isOwner;
            ?>
                <tr data-member-id="<?= $memId ?>"<?= $isPending ? ' class="mg-pending"' : '' ?>>
                    <td class="mg-status-col">
                        <?php if ($isPending): ?>
                            <span class="lg-role" style="background:#fef3c7;color:#92400e">Pending</span>
                        <?php else: ?>
                            <span class="lg-role lg-role-member" style="background:#dcfce7;color:#166534">Member</span>
                        <?php endif; ?>
                    </td>
                    <td class="mg-name-col">
                        <?php if ($editPending): ?>
                            <input type="text" class="mg-cell-input" data-field="contact_name" value="<?= htmlspecialchars($dispName) ?>" placeholder="Name">
                        <?php else: ?>
                            <div class="mg-cell-ro"><?= htmlspecialchars($dispName) ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($editPending): ?>
                            <input type="email" class="mg-cell-input" data-field="contact_email" value="<?= htmlspecialchars($dispEmail) ?>" placeholder="name@example.com">
                        <?php else: ?>
                            <div class="mg-cell-ro"><?= htmlspecialchars($dispEmail) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="mg-phone-col">
                        <?php if ($editPending): ?>
                            <input type="tel" class="mg-cell-input" data-field="contact_phone" value="<?= htmlspecialchars($dispPhone) ?>" placeholder="Phone">
                        <?php else: ?>
                            <div class="mg-cell-ro"><?= htmlspecialchars($dispPhone) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="mg-role-col">
                        <?php if ($editRole): ?>
                            <select class="mg-cell-select" data-field="role">
                                <option value="member"  <?= $m['role']==='member'  ? 'selected' : '' ?>>Member</option>
                                <option value="manager" <?= $m['role']==='manager' ? 'selected' : '' ?>>Manager</option>
                            </select>
                        <?php else: ?>
                            <span class="lg-role lg-role-<?= htmlspecialchars($m['role']) ?>"><?= htmlspecialchars($m['role']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="mg-joined-col"><?= htmlspecialchars($isPending ? ($m['invited_at'] ?? $m['joined_at']) : $m['joined_at']) ?></td>
                    <?php if ($canManageMembers): ?>
                    <td class="mg-act-col">
                        <?php if ($targetUid === $uid || $m['role'] === 'owner'): ?>
                            &nbsp;
                        <?php else: ?>
                            <div class="mg-act-wrap">
                                <?php if ($isPending): ?>
                                    <button class="mg-iconbtn" title="Resend invite" onclick="resendInvite(<?= $memId ?>)">&#9993;</button>
                                <?php elseif ($isOwner): ?>
                                    <button class="mg-iconbtn" title="Transfer ownership" onclick="act('transfer_ownership', <?= $targetUid ?>, 'Transfer ownership to this member? You will be demoted to member.')">&#9812;</button>
                                <?php endif; ?>
                                <button class="mg-iconbtn mg-iconbtn-danger" title="Remove" onclick="removeMember(<?= $memId ?>, <?= htmlspecialchars(json_encode($isPending ? 'Remove this pending contact?' : 'Remove this member from the league?'), ENT_QUOTES) ?>)">&times;</button>
                            </div>
                        <?php endif; ?>
                    </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($members)): ?>
                <tr><td colspan="<?= $canManageMembers ? 7 : 6 ?>" style="padding:1.5rem;text-align:center;color:#94a3b8">No members yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
        <div id="mgSaved" style="display:none;margin-top:.5rem;font-size:.75rem;color:#16a34a">&#10003; Saved</div>

    <?php elseif ($tab === 'events'): ?>
        <?php if (empty($leagueEvents)): ?>
            <div class="lg-empty">No events yet. <a href="/calendar.php?league_id=<?= $league_id ?>">Create one</a>.</div>
        <?php else: foreach ($leagueEvents as $e): ?>
            <div class="lg-card">
                <div>
                    <strong><?= htmlspecialchars($e['title']) ?></strong>
                    <div style="font-size:.8rem;color:#64748b"><?= htmlspecialchars($e['start_date']) ?> &middot; <?= htmlspecialchars($e['visibility']) ?></div>
                </div>
                <a class="lg-btn lg-btn-ghost" href="/calendar.php?open=<?= (int)$e['id'] ?>&date=<?= urlencode($e['start_date']) ?>">Open</a>
            </div>
        <?php endforeach; endif; ?>

    <?php elseif ($tab === 'requests' && $canManageMembers): ?>
        <?php if (empty($requests)): ?>
            <div class="lg-empty">No pending requests.</div>
        <?php else: foreach ($requests as $r): ?>
            <div class="lg-card">
                <div>
                    <strong><?= htmlspecialchars($r['username']) ?></strong>
                    <?php if ($r['message']): ?><div style="font-size:.85rem;color:#475569;margin-top:.2rem"><em>"<?= htmlspecialchars($r['message']) ?>"</em></div><?php endif; ?>
                    <div style="font-size:.75rem;color:#94a3b8;margin-top:.2rem">Requested <?= htmlspecialchars($r['requested_at']) ?></div>
                </div>
                <div class="lg-actions">
                    <button class="lg-btn"            onclick="decide(<?= (int)$r['id'] ?>, 'approve_request')">Approve</button>
                    <button class="lg-btn lg-btn-ghost" onclick="decide(<?= (int)$r['id'] ?>, 'deny_request')">Deny</button>
                </div>
            </div>
        <?php endforeach; endif; ?>

    <?php elseif ($tab === 'settings' && $isOwner): ?>
        <div class="lg-card" style="display:block">
            <h3 style="margin:0 0 .75rem">League settings</h3>
            <p style="font-size:.85rem;color:#64748b;margin:0 0 .75rem">Edit your league details. <a href="/league_edit.php?id=<?= $league_id ?>">Full edit form &rarr;</a></p>
            <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center">
                <code style="font-size:.85rem;background:#f1f5f9;padding:.3rem .5rem;border-radius:4px">Invite code: <?= htmlspecialchars($league['invite_code'] ?? '') ?></code>
                <button class="lg-btn lg-btn-ghost" onclick="regen()">Regenerate</button>
            </div>
        </div>
        <div class="lg-card" style="display:block;border-color:#fecaca">
            <h3 style="margin:0 0 .75rem;color:#dc2626">Danger zone</h3>
            <p style="font-size:.85rem;color:#64748b;margin:0 0 .75rem">Deleting a league is <strong>permanent</strong> and will also delete every event attached to it (including poker sessions, buy-ins, and results). You'll be shown a full summary before you confirm.</p>
            <button class="lg-btn lg-btn-danger" onclick="openDeleteLeague()">Delete league&hellip;</button>
        </div>
    <?php endif; ?>
</div>

<!-- Delete-league confirmation modal -->
<div id="delModal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.65);z-index:200;align-items:center;justify-content:center;padding:1rem">
    <div style="background:#fff;border-radius:10px;padding:1.25rem;max-width:520px;width:100%;max-height:90vh;overflow:auto">
        <h3 style="margin:0 0 .5rem;color:#dc2626">Delete <?= htmlspecialchars($league['name']) ?>?</h3>
        <p style="font-size:.88rem;color:#334155;margin:0 0 .75rem">This action is <strong>permanent</strong> and cannot be undone.</p>
        <div id="delSummary" style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:.75rem 1rem;font-size:.85rem;color:#7f1d1d;margin:0 0 .75rem">Loading summary&hellip;</div>
        <div id="delEventList" style="max-height:200px;overflow:auto;border:1px solid #e2e8f0;border-radius:6px;padding:.5rem .75rem;font-size:.82rem;color:#475569;background:#f8fafc;margin:0 0 .75rem;display:none"></div>
        <div id="delPokerWarn" style="display:none;background:#fef3c7;border:1px solid #fcd34d;border-radius:8px;padding:.65rem .85rem;font-size:.82rem;color:#92400e;margin:0 0 .75rem">
            <strong>Poker data will be lost.</strong> At least one event has recorded buy-ins or results. Deleting wipes the historical record.
        </div>
        <p style="font-size:.85rem;color:#475569;margin:0 0 .4rem">
            To confirm, type the league name <code style="background:#f1f5f9;padding:.1rem .3rem;border-radius:3px"><?= htmlspecialchars($league['name']) ?></code> below:
        </p>
        <input type="text" id="delConfirmName" placeholder="Type the league name" autocomplete="off"
               oninput="onDelTyping()"
               style="width:100%;padding:.55rem;border:1.5px solid #cbd5e1;border-radius:6px;font:inherit;margin-bottom:.75rem">
        <div style="display:flex;gap:.5rem;justify-content:flex-end">
            <button class="lg-btn lg-btn-ghost" onclick="closeDeleteLeague()">Cancel</button>
            <button id="delConfirmBtn" class="lg-btn lg-btn-danger" disabled
                    style="opacity:.5;pointer-events:none"
                    onclick="confirmDeleteLeague()">Permanently delete</button>
        </div>
    </div>
</div>

<script>
var CSRF      = <?= json_encode($csrf) ?>;
var LEAGUE_ID = <?= $league_id ?>;

function post(data) {
    var fd = new FormData();
    fd.append('csrf_token', CSRF);
    for (var k in data) fd.append(k, data[k]);
    return fetch('/leagues_dl.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function(r) { return r.json(); });
}
function act(action, targetId, confirmMsg) {
    if (confirmMsg && !confirm(confirmMsg)) return;
    post({ action: action, league_id: LEAGUE_ID, user_id: targetId }).then(function(j) {
        if (j.ok) location.reload(); else alert(j.error || 'Failed');
    });
}
function removeMember(memberId, confirmMsg) {
    if (confirmMsg && !confirm(confirmMsg)) return;
    post({ action: 'remove_member', league_id: LEAGUE_ID, member_id: memberId }).then(function(j) {
        if (j.ok) location.reload(); else alert(j.error || 'Failed');
    });
}
function resendInvite(memberId) {
    post({ action: 'resend_contact_invite', league_id: LEAGUE_ID, member_id: memberId }).then(function(j) {
        if (j.ok) alert('Invite sent again.'); else alert(j.error || 'Failed');
    });
}
function addContact() {
    var name  = document.getElementById('acName').value.trim();
    var email = document.getElementById('acEmail').value.trim();
    var phone = document.getElementById('acPhone').value.trim();
    if (!name) { alert('Please enter a name.'); return; }
    if (!email && !phone) { alert('Please enter an email or a phone number.'); return; }
    post({
        action: 'add_contact',
        league_id: LEAGUE_ID,
        contact_name: name,
        contact_email: email,
        contact_phone: phone
    }).then(function(j) {
        if (j.ok) location.reload(); else alert(j.error || 'Failed');
    });
}

// ── Inline cell edits on the members grid ────────────────────────────────
(function() {
    var grid = document.getElementById('membersGrid');
    if (!grid) return;
    var savedInd = document.getElementById('mgSaved');
    var savedTimer = null;
    function flashSaved() {
        if (!savedInd) return;
        savedInd.style.display = 'block';
        clearTimeout(savedTimer);
        savedTimer = setTimeout(function() { savedInd.style.display = 'none'; }, 1500);
    }
    function updateCell(el) {
        var row = el.closest('tr');
        var memberId = row && row.dataset.memberId;
        if (!memberId) return;
        var orig = el.dataset.orig != null ? el.dataset.orig : '';
        if (orig === el.value) return;
        post({
            action: 'update_member',
            league_id: LEAGUE_ID,
            member_id: memberId,
            field: el.dataset.field,
            value: el.value
        }).then(function(j) {
            if (j.ok) {
                el.dataset.orig = el.value;
                flashSaved();
            } else {
                alert(j.error || 'Save failed');
                el.value = orig;
            }
        });
    }
    grid.querySelectorAll('.mg-cell-input').forEach(function(inp) {
        inp.dataset.orig = inp.value;
        inp.addEventListener('change', function() { updateCell(this); });
    });
    grid.querySelectorAll('.mg-cell-select').forEach(function(sel) {
        sel.dataset.orig = sel.value;
        sel.addEventListener('change', function() { updateCell(this); });
    });
})();
function decide(reqId, action) {
    post({ action: action, request_id: reqId }).then(function(j) {
        if (j.ok) location.reload(); else alert(j.error || 'Failed');
    });
}
function leaveLeague() {
    if (!confirm('Leave this league? You can request to rejoin later.')) return;
    post({ action: 'leave_league', league_id: LEAGUE_ID }).then(function(j) {
        if (j.ok) location.href = '/leagues.php'; else alert(j.error || 'Failed');
    });
}
function regen() {
    post({ action: 'regenerate_invite_code', league_id: LEAGUE_ID }).then(function(j) {
        if (j.ok) location.reload(); else alert(j.error || 'Failed');
    });
}
var LEAGUE_NAME = <?= json_encode($league['name']) ?>;

function openDeleteLeague() {
    document.getElementById('delModal').style.display = 'flex';
    document.getElementById('delConfirmName').value = '';
    document.getElementById('delSummary').textContent = 'Loading summary\u2026';
    document.getElementById('delEventList').style.display = 'none';
    document.getElementById('delPokerWarn').style.display = 'none';
    onDelTyping();
    // Fetch the preview
    post({ action: 'delete_league_preview', league_id: LEAGUE_ID }).then(function(j) {
        if (!j.ok) { document.getElementById('delSummary').textContent = j.error || 'Failed to load preview'; return; }
        var parts = [];
        parts.push(j.event_count + ' event' + (j.event_count === 1 ? '' : 's'));
        parts.push(j.member_count + ' member' + (j.member_count === 1 ? '' : 's'));
        if (j.request_count > 0) parts.push(j.request_count + ' pending request' + (j.request_count === 1 ? '' : 's'));
        document.getElementById('delSummary').innerHTML = 'This will permanently delete: <strong>' + parts.join(', ') + '</strong>.';
        if (j.events && j.events.length) {
            var list = document.getElementById('delEventList');
            list.innerHTML = j.events.map(function(e) {
                var label = '<div style="padding:.15rem 0">'
                          + '<span style="font-weight:600">' + escapeHtml(e.title) + '</span>'
                          + ' <span style="color:#94a3b8">&middot; ' + escapeHtml(e.start_date) + '</span>';
                if (e.is_poker) label += ' <span style="color:#b45309">(poker)</span>';
                if (e.paid_players > 0) label += ' <span style="color:#dc2626">&middot; ' + e.paid_players + ' paid player' + (e.paid_players === 1 ? '' : 's') + '</span>';
                return label + '</div>';
            }).join('');
            list.style.display = '';
        }
        if (j.poker_with_data > 0) document.getElementById('delPokerWarn').style.display = '';
    });
}
function closeDeleteLeague() {
    document.getElementById('delModal').style.display = 'none';
}
function onDelTyping() {
    var match = document.getElementById('delConfirmName').value.trim().toLowerCase() === LEAGUE_NAME.toLowerCase();
    var btn = document.getElementById('delConfirmBtn');
    btn.disabled = !match;
    btn.style.opacity = match ? '1' : '.5';
    btn.style.pointerEvents = match ? '' : 'none';
}
function confirmDeleteLeague() {
    var name = document.getElementById('delConfirmName').value.trim();
    post({ action: 'delete_league', league_id: LEAGUE_ID, confirm_name: name }).then(function(j) {
        if (j.ok) {
            alert('League deleted. ' + (j.deleted_events || 0) + ' event(s) removed.');
            location.href = '/leagues.php';
        } else {
            alert(j.error || 'Failed');
        }
    });
}
function escapeHtml(s) {
    var d = document.createElement('div');
    d.appendChild(document.createTextNode(String(s == null ? '' : s)));
    return d.innerHTML;
}
</script>

<?php require __DIR__ . '/_footer.php'; ?>
</body>
</html>
