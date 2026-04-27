<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_posts.php';
require_once __DIR__ . '/sms.php'; // shorten_url() used in share-panel render and league invite flows

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
$canPost          = user_can_author_league_post($db, $league_id, $uid, $isAdmin);

// Rules post lookup: shown as a prominent button in the header when present.
$rulesStmt = $db->prepare('SELECT id, title, content, created_at, author_id, league_id, is_rules_post FROM posts WHERE league_id = ? AND is_rules_post = 1 AND hidden = 0 LIMIT 1');
$rulesStmt->execute([$league_id]);
$rulesPost = $rulesStmt->fetch() ?: null;

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

$allowed_tabs = ['members', 'events', 'posts', 'stats', 'requests', 'settings', 'rules'];
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

// ── Stats data (only when the Stats tab is active) ────────────────────
$leaderboard = [];
$myStats     = null;
$_st_range   = 'all';
$_st_from_in = '';
$_st_to_in   = '';
$_st_from_date = null;
$_st_to_date   = null;

if ($tab === 'stats') {
    $allowed_ranges = ['7', '30', '90', '365', 'ytd', 'all', 'custom'];
    $_st_range   = $_GET['range'] ?? 'all';
    $_st_from_in = trim($_GET['from'] ?? '');
    $_st_to_in   = trim($_GET['to']   ?? '');
    if (!in_array($_st_range, $allowed_ranges, true)) $_st_range = 'all';

    $tz    = new DateTimeZone(get_setting('timezone', 'UTC'));
    $today = new DateTime('now', $tz);

    if ($_st_range === 'custom') {
        $_st_from_date = DateTime::createFromFormat('Y-m-d', $_st_from_in, $tz) ?: null;
        $_st_to_date   = DateTime::createFromFormat('Y-m-d', $_st_to_in,   $tz) ?: null;
    } elseif ($_st_range === 'ytd') {
        $_st_from_date = new DateTime($today->format('Y-01-01'), $tz);
        $_st_to_date   = $today;
    } elseif ($_st_range !== 'all') {
        $days = (int)$_st_range;
        $_st_from_date = (clone $today)->modify("-{$days} days");
        $_st_to_date   = $today;
    }

    $from_sql = $_st_from_date ? $_st_from_date->format('Y-m-d') : null;
    $to_sql   = $_st_to_date   ? $_st_to_date->format('Y-m-d')   : null;

    $where_date = '';
    $params     = [$league_id];
    if ($from_sql) { $where_date .= " AND e.start_date >= ?"; $params[] = $from_sql; }
    if ($to_sql)   { $where_date .= " AND e.start_date <= ?"; $params[] = $to_sql;   }

    $stmt = $db->prepare("
        SELECT
            g.player_key, g.display_name, g.user_id,
            COUNT(*) as games,
            SUM(CASE WHEN g.finish_position = 1 THEN 1 ELSE 0 END) as wins,
            MIN(g.finish_position) as best_finish,
            ROUND(AVG(g.finish_position), 1) as avg_finish,
            ROUND(AVG(g.score), 1) as avg_score,
            SUM(g.score) as total_score
        FROM (
            SELECT
                pp.user_id,
                COALESCE(u.username, pp.display_name) as display_name,
                COALESCE(CAST(pp.user_id AS TEXT), 'g_' || LOWER(pp.display_name)) as player_key,
                COALESCE(pp.finish_position, pc.field_size) as finish_position,
                pp.session_id, pc.field_size,
                CASE WHEN pc.field_size > 1
                    THEN ROUND(CAST(pc.field_size - COALESCE(pp.finish_position, pc.field_size) AS REAL) / pc.field_size * 80 + 20, 1)
                    ELSE 100
                END as score
            FROM poker_players pp
            JOIN poker_sessions ps ON ps.id = pp.session_id
            JOIN events e ON e.id = ps.event_id
            LEFT JOIN users u ON u.id = pp.user_id
            JOIN (
                SELECT session_id, COUNT(*) as field_size
                FROM poker_players WHERE bought_in = 1 AND removed = 0
                GROUP BY session_id
            ) pc ON pc.session_id = pp.session_id
            WHERE pp.bought_in = 1 AND pp.removed = 0 AND pp.user_id IS NOT NULL
              AND ps.status = 'finished' AND ps.game_type = 'tournament'
              AND e.league_id = ?
              $where_date
        ) g
        GROUP BY g.player_key
        ORDER BY avg_score DESC, wins DESC, games ASC
    ");
    $stmt->execute($params);
    $leaderboard = $stmt->fetchAll();

    $myKey = (string)$uid;
    foreach ($leaderboard as $row) {
        if ($row['player_key'] === $myKey) { $myStats = $row; break; }
    }
}

function ordinal($n) {
    $n = (int)$n;
    if ($n <= 0) return '—';
    $s = ['th','st','nd','rd'];
    $v = $n % 100;
    return $n . ($s[($v - 20) % 10] ?? $s[$v] ?? $s[0]);
}
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
            <?php if ($rulesPost): ?>
                &middot; <a class="lg-btn lg-btn-rules" href="?id=<?= $league_id ?>&tab=rules" style="padding:.25rem .7rem;font-size:.75rem;background:#fef3c7;color:#92400e;border:1px solid #fcd34d;border-radius:6px;text-decoration:none;font-weight:600">&#128220; League Rules</a>
            <?php endif; ?>
            <?php if ($myRole !== null && $myRole !== 'owner'): ?>
                <button class="lg-btn" style="margin-left:.6rem;padding:.3rem .8rem;font-size:.78rem;font-weight:600;background:#fff;color:#dc2626;border:1.5px solid #fca5a5;border-radius:6px;cursor:pointer" onclick="leaveLeague()"
                        onmouseover="this.style.background='#fee2e2';this.style.borderColor='#dc2626'"
                        onmouseout="this.style.background='#fff';this.style.borderColor='#fca5a5'">&#10060; Leave league</button>
            <?php endif; ?>
        </div>
    </div>

    <div class="lg-tabs">
        <a class="lg-tab<?= $tab==='members'  ? ' active' : '' ?>" href="?id=<?= $league_id ?>&tab=members">Members (<?= $member_count ?>)</a>
        <a class="lg-tab<?= $tab==='events'   ? ' active' : '' ?>" href="?id=<?= $league_id ?>&tab=events">Events (<?= count($leagueEvents) ?>)</a>
        <a class="lg-tab<?= $tab==='posts'    ? ' active' : '' ?>" href="?id=<?= $league_id ?>&tab=posts">Posts</a>
        <a class="lg-tab<?= $tab==='stats'    ? ' active' : '' ?>" href="?id=<?= $league_id ?>&tab=stats">Stats</a>
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
                    <?php if ($canManageMembers): ?><th>Email</th>
                    <th class="mg-phone-col">Phone</th><?php endif; ?>
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
                    <?php if ($canManageMembers): ?>
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
                    <?php endif; ?>
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
            <?php if (empty($members)):
                $colCount = 4 + ($canManageMembers ? 3 : 0); // status+name+role+date + (email+phone+actions)
            ?>
                <tr><td colspan="<?= $colCount ?>" style="padding:1.5rem;text-align:center;color:#94a3b8">No members yet.</td></tr>
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

    <?php elseif ($tab === 'stats'): ?>
        <style>
            .stats-filter { display:flex; flex-wrap:wrap; gap:.5rem; align-items:center; font-size:.85rem; color:#475569; margin-bottom:1rem; }
            .stats-filter label { display:inline-flex; align-items:center; gap:.4rem; font-weight:600; }
            .stats-filter select, .stats-filter input[type="date"] { font-size:.85rem; padding:.35rem .5rem; border:1.5px solid #e2e8f0; border-radius:6px; background:#fff; color:#1e293b; }
            .stats-filter button { font-size:.8rem; font-weight:600; padding:.4rem .75rem; border:none; border-radius:6px; background:#2563eb; color:#fff; cursor:pointer; }
            .stats-filter button:hover { background:#1d4ed8; }
            .stats-filter .custom-range { display:inline-flex; align-items:center; gap:.4rem; }
            .my-stats { display:grid; grid-template-columns:repeat(auto-fit,minmax(120px,1fr)); gap:.5rem; margin-bottom:1.5rem; background:#fff; border:1.5px solid #e2e8f0; border-radius:10px; padding:1rem; }
            .stat-item { text-align:center; padding:.5rem .25rem; }
            .stat-value { font-size:1.4rem; font-weight:800; color:#1e293b; line-height:1.2; }
            .stat-label { font-size:.7rem; font-weight:600; text-transform:uppercase; letter-spacing:.04em; color:#94a3b8; margin-top:.15rem; }
            .stat-gold { color:#f59e0b; }
            .stat-negative { color:#dc2626; }
            .lb-table { width:100%; border-collapse:collapse; background:#fff; border:1.5px solid #e2e8f0; border-radius:10px; overflow:hidden; }
            .lb-table th { background:#f8fafc; font-size:.72rem; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:#64748b; padding:.5rem .6rem; text-align:left; border-bottom:1.5px solid #e2e8f0; }
            .lb-table td { padding:.5rem .6rem; font-size:.85rem; border-bottom:1px solid #f1f5f9; }
            .lb-table tr:last-child td { border-bottom:none; }
            .lb-table tr.is-me { background:#eff6ff; }
            .lb-rank { font-weight:700; color:#94a3b8; width:2rem; text-align:center; }
            .lb-rank-1 { color:#f59e0b; } .lb-rank-2 { color:#94a3b8; } .lb-rank-3 { color:#b45309; }
            .lb-name { font-weight:600; }
            .no-stats { text-align:center; padding:2.5rem 1rem; color:#94a3b8; }
            .no-stats .icon { font-size:3rem; margin-bottom:.75rem; }
            @media(max-width:640px) {
                .my-stats { grid-template-columns:repeat(3,1fr); gap:.35rem; padding:.6rem; }
                .stat-value { font-size:1.1rem; } .stat-label { font-size:.6rem; }
                .lb-table th,.lb-table td { padding:.35rem .4rem; font-size:.75rem; }
                .lb-table .lb-hide-mobile { display:none; }
            }
        </style>

        <p style="color:#64748b;font-size:.9rem;margin:0 0 .75rem">
        <?php if ($_st_range === 'all'): ?>
            Lifetime poker statistics from finished league games.
        <?php elseif ($_st_range === 'custom' && $_st_from_date && $_st_to_date): ?>
            Stats from <?= htmlspecialchars($_st_from_date->format('M j, Y')) ?> to <?= htmlspecialchars($_st_to_date->format('M j, Y')) ?>.
        <?php elseif ($_st_range === 'ytd'): ?>
            Stats for year to date.
        <?php else: ?>
            Stats for the last <?= (int)$_st_range ?> days.
        <?php endif; ?>
        </p>

        <form method="get" class="stats-filter" id="stats-filter">
            <input type="hidden" name="id" value="<?= $league_id ?>">
            <input type="hidden" name="tab" value="stats">
            <label>Range:
                <select name="range" onchange="onRangeChange(this)">
                    <option value="all"    <?= $_st_range==='all'    ? 'selected' : '' ?>>All time</option>
                    <option value="7"      <?= $_st_range==='7'      ? 'selected' : '' ?>>Last 7 days</option>
                    <option value="30"     <?= $_st_range==='30'     ? 'selected' : '' ?>>Last 30 days</option>
                    <option value="90"     <?= $_st_range==='90'     ? 'selected' : '' ?>>Last 90 days</option>
                    <option value="365"    <?= $_st_range==='365'    ? 'selected' : '' ?>>Last year</option>
                    <option value="ytd"    <?= $_st_range==='ytd'    ? 'selected' : '' ?>>Year to date</option>
                    <option value="custom" <?= $_st_range==='custom' ? 'selected' : '' ?>>Custom&hellip;</option>
                </select>
            </label>
            <span class="custom-range" id="custom-range" style="<?= $_st_range==='custom' ? '' : 'display:none' ?>">
                <input type="date" name="from" value="<?= htmlspecialchars($_st_from_in) ?>">
                <span>&rarr;</span>
                <input type="date" name="to"   value="<?= htmlspecialchars($_st_to_in) ?>">
                <button type="submit">Apply</button>
            </span>
        </form>
        <script>
        function onRangeChange(sel) {
            var cr = document.getElementById('custom-range');
            if (sel.value === 'custom') { cr.style.display = ''; }
            else {
                var form = document.getElementById('stats-filter');
                form.querySelector('input[name="from"]').value = '';
                form.querySelector('input[name="to"]').value = '';
                form.submit();
            }
        }
        </script>

        <?php if (empty($leaderboard)): ?>
        <div class="no-stats">
            <div class="icon">&#128200;</div>
            <?php if ($_st_range === 'all'): ?>
            <p>No finished tournament games yet. Stats will appear after your first completed tournament.</p>
            <?php else: ?>
            <p>No finished games in this date range. Try a wider range or select <strong>All time</strong>.</p>
            <?php endif; ?>
        </div>
        <?php else: ?>

        <?php if ($myStats): ?>
        <div class="my-stats">
            <?php
            $games  = (int)$myStats['games'];
            $wins   = (int)$myStats['wins'];
            $losses = $games - $wins;
            $winPct = $games > 0 ? round($wins / $games * 100) : 0;
            ?>
            <div class="stat-item"><div class="stat-value"><?= $games ?></div><div class="stat-label">Games</div></div>
            <div class="stat-item"><div class="stat-value stat-gold"><?= $wins ?></div><div class="stat-label">Wins</div></div>
            <div class="stat-item"><div class="stat-value stat-negative"><?= $losses ?></div><div class="stat-label">Losses</div></div>
            <div class="stat-item"><div class="stat-value"><?= $winPct ?>%</div><div class="stat-label">Win Rate</div></div>
            <div class="stat-item"><div class="stat-value"><?= ordinal($myStats['best_finish']) ?></div><div class="stat-label">Best Finish</div></div>
            <div class="stat-item"><div class="stat-value"><?= $myStats['avg_finish'] ?></div><div class="stat-label">Avg Finish</div></div>
            <div class="stat-item"><div class="stat-value stat-gold"><?= $myStats['avg_score'] ?></div><div class="stat-label">Avg Score</div></div>
        </div>
        <?php endif; ?>

        <h2 style="font-size:1.1rem;font-weight:700;margin-bottom:.75rem">Leaderboard</h2>
        <table class="lb-table">
            <thead><tr>
                <th>#</th><th>Player</th><th>Games</th><th>Wins</th><th>Losses</th><th>Win%</th><th>Score</th>
                <th class="lb-hide-mobile">Best</th><th class="lb-hide-mobile">Avg</th>
            </tr></thead>
            <tbody>
            <?php foreach ($leaderboard as $i => $row):
                $rank   = $i + 1;
                $games  = (int)$row['games'];
                $wins   = (int)$row['wins'];
                $losses = $games - $wins;
                $winPct = $games > 0 ? round($wins / $games * 100) : 0;
                $isMe   = $row['player_key'] === (string)$uid;
                $rankCls= $rank <= 3 ? ' lb-rank-' . $rank : '';
            ?>
                <tr class="<?= $isMe ? 'is-me' : '' ?>">
                    <td class="lb-rank<?= $rankCls ?>"><?= $rank ?></td>
                    <td class="lb-name"><?= htmlspecialchars($row['display_name']) ?></td>
                    <td><?= $games ?></td>
                    <td class="stat-gold"><?= $wins ?></td>
                    <td class="stat-negative"><?= $losses ?></td>
                    <td><?= $winPct ?>%</td>
                    <td class="stat-gold" style="font-weight:700"><?= $row['avg_score'] ?></td>
                    <td class="lb-hide-mobile"><?= ordinal($row['best_finish']) ?></td>
                    <td class="lb-hide-mobile"><?= $row['avg_finish'] ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

    <?php elseif ($tab === 'rules'): ?>
        <div class="lg-card" style="display:block">
            <?php if (!$rulesPost): ?>
                <p style="color:#64748b">No league rules have been set yet.<?php if ($canPost): ?> Create a post in the Posts tab and mark it as the league rules.<?php endif; ?></p>
            <?php else: ?>
                <?php $canEditRules = user_can_edit_post($db, $rulesPost, $uid, $isAdmin); ?>
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.75rem;gap:.5rem;flex-wrap:wrap">
                    <h2 style="margin:0;font-size:1.3rem;color:#92400e">&#128220; <?= htmlspecialchars($rulesPost['title']) ?></h2>
                    <?php if ($canEditRules || $canPost): ?>
                    <div style="display:flex;gap:.5rem;flex-wrap:wrap">
                        <?php if ($canEditRules): ?>
                        <a href="?id=<?= $league_id ?>&tab=posts&edit=<?= (int)$rulesPost['id'] ?>"
                           class="lg-btn lg-btn-ghost" style="font-size:.75rem">Edit</a>
                        <?php endif; ?>
                        <?php if ($canPost): ?>
                        <form method="post" action="/league_posts_dl.php" style="margin:0"
                              onsubmit="return confirm('Unset this post as the league rules? It will reappear in the Posts feed.')">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                            <input type="hidden" name="action" value="clear_rules">
                            <input type="hidden" name="post_id" value="<?= (int)$rulesPost['id'] ?>">
                            <input type="hidden" name="redirect" value="/league.php?id=<?= $league_id ?>&tab=posts">
                            <button type="submit" class="lg-btn lg-btn-ghost" style="font-size:.75rem">Unset rules flag</button>
                        </form>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="post-body" style="line-height:1.75;color:#334155"><?= sanitize_html($rulesPost['content']) ?></div>
            <?php endif; ?>
        </div>

    <?php elseif ($tab === 'posts'): ?>
        <?php
        // Load league posts for this tab (excluding rules-flagged, excluding hidden — admins included).
        $lpStmt = $db->prepare("SELECT p.id, p.title, p.content, p.created_at, p.pinned, p.league_id, p.author_id, p.share_token, u.username AS author_name
                                FROM posts p LEFT JOIN users u ON u.id = p.author_id
                                WHERE p.league_id = ? AND p.is_rules_post = 0 AND p.hidden = 0
                                ORDER BY p.pinned DESC, p.created_at DESC");
        $lpStmt->execute([$league_id]);
        $leaguePosts = $lpStmt->fetchAll();

        $editPostId = (int)($_GET['edit'] ?? 0);
        $editPost   = null;
        if ($editPostId > 0) {
            $ep = $db->prepare('SELECT * FROM posts WHERE id = ? AND league_id = ?');
            $ep->execute([$editPostId, $league_id]);
            $editPost = $ep->fetch() ?: null;
            if ($editPost && !user_can_edit_post($db, $editPost, $uid, $isAdmin)) $editPost = null;
        }
        ?>

        <?php
        // When editing the rules post, round-trip the user back to the Rules tab on save/cancel.
        $backTab = ($editPost && !empty($editPost['is_rules_post'])) ? 'rules' : 'posts';
        ?>
        <?php if ($canPost || ($editPost && $backTab === 'rules')): ?>
        <div class="lg-card" style="display:block">
            <h3 style="margin:0 0 .75rem"><?= $editPost ? ($backTab === 'rules' ? 'Edit rules' : 'Edit post') : 'New post' ?></h3>
            <form method="post" action="/league_posts_dl.php" id="leaguePostForm">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                <input type="hidden" name="action" value="<?= $editPost ? 'update' : 'create' ?>">
                <input type="hidden" name="league_id" value="<?= $league_id ?>">
                <?php if ($editPost): ?>
                    <input type="hidden" name="post_id" value="<?= (int)$editPost['id'] ?>">
                <?php endif; ?>
                <input type="hidden" name="redirect" value="/league.php?id=<?= $league_id ?>&tab=<?= $backTab ?>">
                <label style="display:block;font-size:.8rem;font-weight:600;margin-bottom:.25rem">Title</label>
                <input type="text" name="title" required maxlength="200" value="<?= htmlspecialchars($editPost['title'] ?? '') ?>"
                       style="width:100%;padding:.5rem;border:1.5px solid #cbd5e1;border-radius:6px;margin-bottom:.75rem;font:inherit">
                <label style="display:block;font-size:.8rem;font-weight:600;margin-bottom:.25rem">Content</label>
                <textarea id="lp-editor" name="content"><?= htmlspecialchars($editPost['content'] ?? '') ?></textarea>
                <div style="display:flex;gap:.5rem;margin-top:.75rem">
                    <button type="submit" class="lg-btn lg-btn-primary"><?= $editPost ? 'Save changes' : 'Publish' ?></button>
                    <?php if ($editPost): ?>
                    <a href="?id=<?= $league_id ?>&tab=<?= $backTab ?>" class="lg-btn lg-btn-ghost">Cancel</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
        <script src="/vendor/jodit/jodit.min.js"></script>
        <link rel="stylesheet" href="/vendor/jodit/jodit.min.css">
        <script>
            (function(){
                if (typeof Jodit === 'undefined') return;
                Jodit.make('#lp-editor', {
                    height: 340,
                    toolbarAdaptive: false,
                    buttons: ['bold','italic','underline','|','ul','ol','|','outdent','indent','|','link','image','|','hr','brush','|','undo','redo'],
                    uploader: {
                        url: '/upload.php',
                        headers: {},
                        format: 'json',
                        prepareData: function(fd) { fd.append('csrf_token', '<?= htmlspecialchars(csrf_token()) ?>'); return fd; },
                        isSuccess: function(r){ return r && r.ok; },
                        process: function(r){ return { files: [r.url], baseurl: '' }; },
                        defaultHandlerSuccess: function(data){ var img = this.j.createInside.element('img'); img.setAttribute('src', data.files[0]); this.j.s.insertImage(img); }
                    }
                });
            })();
        </script>
        <?php endif; ?>

        <?php if (empty($leaguePosts)): ?>
            <div class="lg-card" style="display:block;text-align:center;color:#94a3b8;padding:2.5rem 1rem">
                <div style="font-size:2rem">&#128196;</div>
                <p style="margin-top:.5rem">No posts yet.</p>
            </div>
        <?php else: foreach ($leaguePosts as $lp):
            $lp_can_edit = user_can_edit_post($db, $lp, $uid, $isAdmin);
            $lp_comments_stmt = $db->prepare("SELECT c.*, u.username FROM comments c JOIN users u ON u.id = c.user_id WHERE c.type='post' AND c.content_id = ? ORDER BY c.created_at ASC");
            $lp_comments_stmt->execute([$lp['id']]);
            $lp_comments = $lp_comments_stmt->fetchAll();
        ?>
        <div class="lg-card" style="display:block" id="post-<?= (int)$lp['id'] ?>">
            <div style="display:flex;align-items:center;gap:.75rem;margin-bottom:.5rem;font-size:.78rem;color:#94a3b8;flex-wrap:wrap">
                <?php if ($lp['pinned']): ?><span class="pin-badge">&#128204; Pinned</span><?php endif; ?>
                <?php if (!empty($lp['share_token'])): ?>
                    <span title="This post has a public share link" style="font-size:.7rem;font-weight:600;color:#166534;background:#dcfce7;border:1px solid #86efac;border-radius:999px;padding:.1rem .5rem">&#128279; Public link</span>
                <?php endif; ?>
                <span>&#128197; <?= htmlspecialchars((new DateTime($lp['created_at'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(get_setting('timezone', 'UTC')))->format('F j, Y')) ?></span>
                <?php if (!empty($lp['author_name'])): ?><span>by <?= htmlspecialchars($lp['author_name']) ?></span><?php endif; ?>
                <?php if ($lp_can_edit): ?>
                <?php $__pbtn = 'font-size:.72rem;padding:.25rem .7rem;min-width:72px;text-align:center;line-height:1.2;box-sizing:border-box;display:inline-flex;align-items:center;justify-content:center'; ?>
                <div style="margin-left:auto;display:flex;gap:.4rem;align-items:center;flex-wrap:wrap">
                    <a href="?id=<?= $league_id ?>&tab=posts&edit=<?= (int)$lp['id'] ?>" class="lg-btn lg-btn-ghost" style="<?= $__pbtn ?>">Edit</a>
                    <form method="post" action="/league_posts_dl.php" style="margin:0" onsubmit="return confirm('Delete this post?')">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="post_id" value="<?= (int)$lp['id'] ?>">
                        <input type="hidden" name="redirect" value="/league.php?id=<?= $league_id ?>&tab=posts">
                        <button type="submit" class="lg-btn lg-btn-ghost" style="<?= $__pbtn ?>;color:#ef4444">Delete</button>
                    </form>
                    <?php if ($canPost): ?>
                    <form method="post" action="/league_posts_dl.php" style="margin:0"
                          onsubmit="return confirm(<?= $rulesPost ? "'This will replace the current league rules post. Continue?'" : "'Mark this post as the league rules? It will be moved out of the Posts feed and accessible via the League Rules button.'" ?>);">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                        <input type="hidden" name="action" value="set_rules">
                        <input type="hidden" name="post_id" value="<?= (int)$lp['id'] ?>">
                        <input type="hidden" name="redirect" value="/league.php?id=<?= $league_id ?>&tab=rules">
                        <button type="submit" class="lg-btn lg-btn-ghost" style="<?= $__pbtn ?>;color:#92400e">Set as rules</button>
                    </form>
                    <?php if (empty($lp['share_token'])): ?>
                    <form method="post" action="/league_posts_dl.php" style="margin:0"
                          onsubmit="return confirm('Make this post readable by anyone with the link? It will not appear in any public feed.');">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                        <input type="hidden" name="action" value="share_enable">
                        <input type="hidden" name="post_id" value="<?= (int)$lp['id'] ?>">
                        <input type="hidden" name="redirect" value="/league.php?id=<?= $league_id ?>&tab=posts#post-<?= (int)$lp['id'] ?>">
                        <button type="submit" class="lg-btn lg-btn-ghost" style="<?= $__pbtn ?>;color:#166534">Make public</button>
                    </form>
                    <?php else: ?>
                    <button type="button" class="lg-btn lg-btn-ghost" style="<?= $__pbtn ?>;color:#166534"
                            onclick="document.getElementById('share-panel-<?= (int)$lp['id'] ?>').style.display = (document.getElementById('share-panel-<?= (int)$lp['id'] ?>').style.display === 'none' ? '' : 'none')">
                        Share link
                    </button>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php if ($canPost && !empty($lp['share_token'])):
                $__share_url = get_site_url() . '/post_public.php?token=' . $lp['share_token'];
                if (get_setting('url_shortener_enabled') === '1') { $__share_url = shorten_url($__share_url); }
            ?>
            <div id="share-panel-<?= (int)$lp['id'] ?>" style="display:none;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:.75rem 1rem;margin-bottom:.75rem;font-size:.82rem;color:#475569">
                <div style="margin-bottom:.5rem"><strong>Public link</strong> &mdash; anyone with this URL can read the post (it stays hidden from feeds).</div>
                <div style="display:flex;gap:.4rem;align-items:center;flex-wrap:wrap">
                    <input type="text" readonly value="<?= htmlspecialchars($__share_url) ?>"
                           id="share-url-<?= (int)$lp['id'] ?>"
                           onclick="this.select()"
                           style="flex:1;min-width:200px;font-family:monospace;font-size:.78rem;padding:.35rem .5rem;border:1px solid #cbd5e1;border-radius:5px;background:#fff">
                    <button type="button" class="lg-btn lg-btn-ghost" style="font-size:.72rem;padding:.25rem .7rem"
                            onclick="(async()=>{try{await navigator.clipboard.writeText(document.getElementById('share-url-<?= (int)$lp['id'] ?>').value);this.textContent='Copied';setTimeout(()=>this.textContent='Copy',1500);}catch(e){document.getElementById('share-url-<?= (int)$lp['id'] ?>').select();document.execCommand('copy');this.textContent='Copied';setTimeout(()=>this.textContent='Copy',1500);}})()">Copy</button>
                    <form method="post" action="/league_posts_dl.php" style="margin:0"
                          onsubmit="return confirm('Generate a new link? The current link will stop working.');">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                        <input type="hidden" name="action" value="share_regen">
                        <input type="hidden" name="post_id" value="<?= (int)$lp['id'] ?>">
                        <input type="hidden" name="redirect" value="/league.php?id=<?= $league_id ?>&tab=posts#post-<?= (int)$lp['id'] ?>">
                        <button type="submit" class="lg-btn lg-btn-ghost" style="font-size:.72rem;padding:.25rem .7rem;color:#92400e">Regenerate</button>
                    </form>
                    <form method="post" action="/league_posts_dl.php" style="margin:0"
                          onsubmit="return confirm('Disable the public link? The URL will stop working immediately.');">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                        <input type="hidden" name="action" value="share_disable">
                        <input type="hidden" name="post_id" value="<?= (int)$lp['id'] ?>">
                        <input type="hidden" name="redirect" value="/league.php?id=<?= $league_id ?>&tab=posts#post-<?= (int)$lp['id'] ?>">
                        <button type="submit" class="lg-btn lg-btn-ghost" style="font-size:.72rem;padding:.25rem .7rem;color:#ef4444">Disable</button>
                    </form>
                </div>
            </div>
            <?php endif; ?>
            <h3 style="margin:0 0 .5rem;font-size:1.15rem"><?= htmlspecialchars($lp['title']) ?></h3>
            <div class="post-body" style="line-height:1.75;color:#334155"><?= sanitize_html($lp['content']) ?></div>

            <div class="comments-section" id="csec-<?= (int)$lp['id'] ?>" style="margin-top:1rem;padding-top:.75rem;border-top:1px solid #e2e8f0">
                <div class="comments-heading" onclick="toggleComments(<?= (int)$lp['id'] ?>)" style="cursor:pointer;user-select:none">
                    <span class="cmts-toggle-label" style="display:flex;align-items:center;gap:.4rem;color:#475569;font-size:.85rem;font-weight:600">
                        <span class="cmts-chevron" style="font-size:.65rem;color:#94a3b8">&#9658;</span>
                        <?= count($lp_comments) ?> Comment<?= count($lp_comments) !== 1 ? 's' : '' ?>
                    </span>
                </div>
                <div class="comments-body" id="cmts-body-<?= (int)$lp['id'] ?>" style="display:none;margin-top:.65rem">
                    <?php foreach ($lp_comments as $c): ?>
                    <div class="comment" style="display:flex;gap:.6rem;margin-bottom:.6rem">
                        <div class="comment-avatar" style="width:30px;height:30px;border-radius:50%;background:#e2e8f0;color:#475569;display:flex;align-items:center;justify-content:center;font-weight:600;font-size:.85rem;flex-shrink:0"><?= htmlspecialchars(mb_substr($c['username'], 0, 1)) ?></div>
                        <div class="comment-content" style="flex:1;min-width:0">
                            <div style="font-size:.75rem;color:#94a3b8;margin-bottom:.15rem">
                                <strong style="color:#334155"><?= htmlspecialchars($c['username']) ?></strong>
                                <?= htmlspecialchars((new DateTime($c['created_at'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(get_setting('timezone', 'UTC')))->format('M j, Y g:i A')) ?>
                            </div>
                            <div style="font-size:.9rem;color:#334155"><?= htmlspecialchars($c['body']) ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <form method="post" action="/comment.php" class="comment-form" style="margin-top:.5rem">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                        <input type="hidden" name="action" value="add">
                        <input type="hidden" name="type" value="post">
                        <input type="hidden" name="content_id" value="<?= (int)$lp['id'] ?>">
                        <input type="hidden" name="redirect" value="/league.php?id=<?= $league_id ?>&tab=posts#post-<?= (int)$lp['id'] ?>">
                        <textarea name="body" placeholder="Write a comment…" required maxlength="2000"
                                  style="width:100%;min-height:60px;padding:.4rem .6rem;border:1.5px solid #cbd5e1;border-radius:6px;font:inherit"></textarea>
                        <button type="submit" class="lg-btn lg-btn-primary" style="margin-top:.35rem;font-size:.8rem">Post</button>
                    </form>
                </div>
            </div>
        </div>
        <?php endforeach; endif; ?>

        <script>
        function toggleComments(pid) {
            var body = document.getElementById('cmts-body-' + pid);
            if (!body) return;
            body.style.display = body.style.display === 'none' ? '' : 'none';
        }
        </script>

    <?php elseif ($tab === 'settings' && $isOwner): ?>
        <div class="lg-card" style="display:block">
            <h3 style="margin:0 0 .75rem">League settings</h3>
            <p style="font-size:.85rem;color:#64748b;margin:0 0 .75rem">Edit your league details. <a href="/league_edit.php?id=<?= $league_id ?>">Full edit form &rarr;</a></p>
        </div>
        <div class="lg-card" style="display:block">
            <h3 style="margin:0 0 .5rem">Shareable invite link</h3>
            <p style="font-size:.85rem;color:#64748b;margin:0 0 .75rem">
                Anyone with this link can
                <?= $league['approval_mode'] === 'auto' ? '<strong>join instantly</strong>' : '<strong>request to join</strong>' ?>.
                Share it in a chat, group email, or printed card. Regenerating the link invalidates the old one.
            </p>
            <?php
                $inviteLinkFull = get_site_url() . '/join_league.php?code=' . urlencode($league['invite_code'] ?? '');
                require_once __DIR__ . '/sms.php';
                $inviteLink = shorten_url($inviteLinkFull);
            ?>
            <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center">
                <input type="text" id="inviteLinkField" readonly value="<?= htmlspecialchars($inviteLink) ?>"
                       onclick="this.select()"
                       style="flex:1;min-width:260px;padding:.5rem .6rem;border:1.5px solid #cbd5e1;border-radius:6px;font-family:ui-monospace,monospace;font-size:.82rem;background:#f8fafc;color:#334155">
                <button class="lg-btn" type="button" onclick="copyInviteLink()">Copy</button>
                <button class="lg-btn lg-btn-ghost" type="button" onclick="regen()">Regenerate</button>
            </div>
            <div id="inviteLinkFlash" style="display:none;margin-top:.4rem;font-size:.78rem;color:#16a34a">&#10003; Copied to clipboard.</div>
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
    if (!confirm('Regenerate the invite link? The old link will stop working immediately.')) return;
    post({ action: 'regenerate_invite_code', league_id: LEAGUE_ID }).then(function(j) {
        if (j.ok) {
            var f = document.getElementById('inviteLinkField');
            if (f && j.invite_url) {
                f.value = j.invite_url;
            } else {
                location.reload();
            }
        } else {
            alert(j.error || 'Failed');
        }
    });
}
function copyInviteLink() {
    var f = document.getElementById('inviteLinkField');
    if (!f) return;
    f.select();
    var ok = false;
    try {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(f.value);
            ok = true;
        } else {
            ok = document.execCommand('copy');
        }
    } catch (e) {}
    if (ok) {
        var flash = document.getElementById('inviteLinkFlash');
        flash.style.display = 'block';
        setTimeout(function() { flash.style.display = 'none'; }, 1800);
    }
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
<script src="/_phone_input.js"></script>
<script>initPhoneAutoFormat();</script>
</body>
</html>
