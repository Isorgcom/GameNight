<?php
/**
 * Event polls — manager page (create, send, results, close, resend).
 *
 * Polls go to approved base invitees who RSVP'd Yes or Maybe, over each
 * recipient's preferred channel (email link / SMS / WhatsApp reply-by-number).
 * Results show counts and turnout only — never who voted for what.
 * Public answering happens on poll.php (tokenized, no login).
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_polls.php';

$current = require_login();
$db      = get_db();
$isAdmin = $current['role'] === 'admin';

$eventId = (int)($_GET['event_id'] ?? $_POST['event_id'] ?? 0);
if ($eventId <= 0 || !can_manage_event($db, $eventId, (int)$current['id'], $isAdmin)) {
    http_response_code(403);
    exit('You can only manage polls for events you manage.');
}
$evStmt = $db->prepare('SELECT id, title, start_date, start_time FROM events WHERE id = ?');
$evStmt->execute([$eventId]);
$event = $evStmt->fetch();
if (!$event) { http_response_code(404); exit('Event not found.'); }

session_start_safe();
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Invalid request token.'];
        header('Location: /event_polls.php?event_id=' . $eventId);
        exit;
    }
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $title     = trim($_POST['title'] ?? '');
        $questions = (array)($_POST['question'] ?? []);      // question[i] = text
        $options   = (array)($_POST['options'] ?? []);       // options[i][j] = label
        $clean = [];
        foreach ($questions as $i => $qText) {
            $qText = trim((string)$qText);
            if ($qText === '') continue;
            $opts = [];
            foreach ((array)($options[$i] ?? []) as $oText) {
                $oText = trim((string)$oText);
                if ($oText !== '') $opts[] = $oText;
            }
            if (count($opts) >= 2) $clean[] = ['q' => $qText, 'opts' => $opts];
        }
        if ($title === '' || empty($clean)) {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'A poll needs a title and at least one question with two or more options.'];
        } else {
            $db->prepare('INSERT INTO event_polls (event_id, title, created_by) VALUES (?, ?, ?)')
               ->execute([$eventId, $title, (int)$current['id']]);
            $pollId = (int)$db->lastInsertId();
            $qIns = $db->prepare('INSERT INTO poll_questions (poll_id, question, sort_order) VALUES (?, ?, ?)');
            $oIns = $db->prepare('INSERT INTO poll_options (question_id, label, sort_order) VALUES (?, ?, ?)');
            foreach ($clean as $qi => $qq) {
                $qIns->execute([$pollId, $qq['q'], $qi + 1]);
                $qid = (int)$db->lastInsertId();
                foreach ($qq['opts'] as $oi => $label) {
                    $oIns->execute([$qid, $label, $oi + 1]);
                }
            }
            $poll = poll_load($db, $pollId);
            $sent = poll_send_to_audience($db, $poll);
            db_log_activity((int)$current['id'], "created poll id=$pollId for event id=$eventId, queued to $sent recipient(s)");
            $_SESSION['flash'] = $sent > 0
                ? ['type' => 'success', 'msg' => "Poll sent to $sent Yes/Maybe guest(s)."]
                : ['type' => 'error', 'msg' => 'Poll created, but no Yes/Maybe guests to send it to yet. Use "Send to new respondents" once people RSVP.'];
        }
        header('Location: /event_polls.php?event_id=' . $eventId);
        exit;
    }

    if ($action === 'close' || $action === 'reopen') {
        $pid = (int)($_POST['poll_id'] ?? 0);
        $chk = $db->prepare('SELECT id FROM event_polls WHERE id = ? AND event_id = ?');
        $chk->execute([$pid, $eventId]);
        if ($chk->fetchColumn()) {
            if ($action === 'close') {
                $db->prepare("UPDATE event_polls SET status='closed', closed_at=datetime('now') WHERE id = ?")->execute([$pid]);
                db_log_activity((int)$current['id'], "closed poll id=$pid");
                $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Poll closed.'];
            } else {
                $db->prepare("UPDATE event_polls SET status='open', closed_at=NULL WHERE id = ?")->execute([$pid]);
                db_log_activity((int)$current['id'], "reopened poll id=$pid");
                $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Poll reopened.'];
            }
        }
        header('Location: /event_polls.php?event_id=' . $eventId);
        exit;
    }

    if ($action === 'delete') {
        $pid = (int)($_POST['poll_id'] ?? 0);
        $chk = $db->prepare('SELECT id FROM event_polls WHERE id = ? AND event_id = ?');
        $chk->execute([$pid, $eventId]);
        if ($chk->fetchColumn()) {
            // FK cascades remove questions/options/recipients/answers and any
            // sms_pending_poll conversations; unsent queue rows self-clean on
            // dispatch (missing poll => handled).
            $db->prepare('DELETE FROM event_polls WHERE id = ?')->execute([$pid]);
            db_log_activity((int)$current['id'], "deleted poll id=$pid from event id=$eventId");
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Poll deleted.'];
        }
        header('Location: /event_polls.php?event_id=' . $eventId);
        exit;
    }

    if ($action === 'resend') {
        $pid = (int)($_POST['poll_id'] ?? 0);
        $poll = poll_load($db, $pid);
        if ($poll && (int)$poll['event_id'] === $eventId && $poll['status'] === 'open') {
            $sent = poll_send_to_audience($db, $poll);
            db_log_activity((int)$current['id'], "poll id=$pid resent to $sent new recipient(s)");
            $_SESSION['flash'] = $sent > 0
                ? ['type' => 'success', 'msg' => "Sent to $sent new Yes/Maybe respondent(s)."]
                : ['type' => 'success', 'msg' => 'No new Yes/Maybe respondents to send to.'];
        }
        header('Location: /event_polls.php?event_id=' . $eventId);
        exit;
    }
}

// ── Page data ────────────────────────────────────────────────────────────────
$pollRows = $db->prepare('SELECT id FROM event_polls WHERE event_id = ? ORDER BY created_at DESC, id DESC');
$pollRows->execute([$eventId]);
$polls = [];
foreach ($pollRows->fetchAll() as $pr) {
    $p = poll_load($db, (int)$pr['id']);
    $p['counts']  = poll_counts($db, (int)$pr['id']);
    $p['turnout'] = poll_turnout($db, (int)$pr['id']);
    $polls[] = $p;
}
$audienceNow = poll_audience($db, $eventId);
$token     = csrf_token();
$site_name = get_setting('site_name', 'Game Night');
$backUrl   = '/calendar.php?m=' . urlencode(substr($event['start_date'], 0, 7)) . '&open=' . $eventId . '&date=' . urlencode($event['start_date']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Polls &mdash; <?= htmlspecialchars($event['title']) ?> &mdash; <?= htmlspecialchars($site_name) ?></title>
    <link rel="stylesheet" href="/style.css?v=<?= htmlspecialchars(APP_VERSION . '.' . (@filemtime(__DIR__ . '/style.css') ?: 0)) ?>">
    <style>
        .pl-wrap { max-width: 860px; margin: 1.5rem auto; padding: 0 1rem; }
        .pl-card { background:#fff; border:1.5px solid #e2e8f0; border-radius:10px; padding:1.25rem; margin-bottom:1.25rem; }
        .pl-card h2 { font-size:1.05rem; margin:0 0 .75rem; }
        .pl-field { margin-bottom:.8rem; }
        .pl-field label { display:block; font-size:.78rem; font-weight:700; color:#475569; margin-bottom:.3rem; }
        .pl-field input[type=text] { width:100%; box-sizing:border-box; padding:.5rem .6rem; border:1.5px solid #e2e8f0; border-radius:8px; font-size:.9rem; }
        .pl-q { border:1px solid #e2e8f0; border-radius:8px; padding:.7rem .8rem; margin-bottom:.7rem; background:#f8fafc; }
        .pl-q .pl-opt-row { display:flex; gap:.4rem; margin-top:.4rem; align-items:center; }
        .pl-q .pl-opt-row input { flex:1; }
        .pl-btn { background:#f1f5f9; border:none; border-radius:6px; padding:.35rem .7rem; font-size:.78rem; font-weight:600; cursor:pointer; color:#334155; }
        .pl-btn:hover { background:#e2e8f0; }
        .pl-btn.primary { background:#2563eb; color:#fff; }
        .pl-btn.danger { background:#fef2f2; color:#b91c1c; }
        .pl-bar-wrap { background:#f1f5f9; border-radius:6px; height:20px; position:relative; overflow:hidden; margin:.15rem 0 .4rem; }
        .pl-bar { background:#2563eb; height:100%; border-radius:6px; min-width:2px; }
        .pl-bar-label { position:absolute; left:.5rem; top:0; line-height:20px; font-size:.72rem; font-weight:700; color:#1e293b; }
        .pl-badge { font-size:.68rem; font-weight:700; padding:.12rem .45rem; border-radius:5px; }
        .pl-badge.open { background:#dcfce7; color:#166534; }
        .pl-badge.closed { background:#f1f5f9; color:#64748b; }
        .pl-meta { font-size:.78rem; color:#94a3b8; }
        .pl-names { font-size:.78rem; color:#64748b; line-height:1.5; }
        .pl-actions { display:flex; gap:.4rem; flex-wrap:wrap; margin-top:.75rem; }
    </style>
</head>
<body>

<?php $nav_active = 'calendar'; $nav_user = $current; require __DIR__ . '/_nav.php'; ?>

<div class="pl-wrap">
    <div style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;margin-bottom:1rem">
        <h1 style="font-size:1.4rem;font-weight:700;margin:0">Polls &mdash; <?= htmlspecialchars($event['title']) ?></h1>
        <a href="<?= htmlspecialchars($backUrl) ?>" class="btn btn-outline" style="margin-left:auto;font-size:.8rem;text-decoration:none">Back to event</a>
    </div>
    <p style="color:#64748b;font-size:.88rem;margin:0 0 1.25rem;line-height:1.55">
        Polls go to <strong>approved guests who RSVP'd Yes or Maybe</strong>
        (currently <?= count($audienceNow) ?>) via each person's preferred contact method.
        Results show <strong>counts only</strong> &mdash; nobody can see who picked what.
        Text-message recipients can answer by replying numbers; everyone gets a personal link.
    </p>

    <?php if ($flash && !empty($flash['msg'])): ?>
        <div class="alert alert-<?= ($flash['type'] ?? '') === 'error' ? 'error' : 'success' ?>" style="margin-bottom:1rem">
            <?= htmlspecialchars($flash['msg']) ?>
        </div>
    <?php endif; ?>

    <!-- ── Create poll ── -->
    <div class="pl-card">
        <h2>New poll</h2>
        <form method="post" action="/event_polls.php" id="pollForm">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">
            <input type="hidden" name="action" value="create">
            <input type="hidden" name="event_id" value="<?= $eventId ?>">
            <div class="pl-field">
                <label for="plTitle">Poll title</label>
                <input type="text" id="plTitle" name="title" maxlength="120" placeholder="e.g. Food &amp; start time" required>
            </div>
            <div id="plQuestions"></div>
            <div style="display:flex;gap:.5rem;margin-top:.5rem">
                <button type="button" class="pl-btn" onclick="addQuestion()">+ Add question</button>
                <div style="flex:1"></div>
                <button type="submit" class="pl-btn primary" onclick="return pkConfirmForm(this.form, 'Send this poll to <?= count($audienceNow) ?> Yes/Maybe guest(s) now?')">Create &amp; Send</button>
            </div>
            <p class="pl-meta" style="margin:.6rem 0 0">Questions can't be edited once anyone has voted &mdash; close the poll and make a new one instead.</p>
        </form>
    </div>

    <!-- ── Existing polls ── -->
    <?php if (empty($polls)): ?>
        <p style="color:#94a3b8">No polls yet for this event.</p>
    <?php endif; ?>
    <?php foreach ($polls as $p): $t = $p['turnout']; ?>
    <div class="pl-card">
        <div style="display:flex;align-items:center;gap:.6rem;flex-wrap:wrap">
            <h2 style="margin:0;flex:1"><?= htmlspecialchars($p['title']) ?></h2>
            <span class="pl-badge <?= $p['status'] === 'open' ? 'open' : 'closed' ?>"><?= $p['status'] === 'open' ? 'Open' : 'Closed' ?></span>
        </div>
        <p class="pl-meta" style="margin:.25rem 0 .9rem">
            Sent <?= htmlspecialchars(substr($p['created_at'], 0, 16)) ?> &middot;
            Responded: <strong><?= count($t['responded']) ?> of <?= $t['total'] ?></strong>
        </p>

        <?php foreach ($p['questions'] as $q):
            $qc = $p['counts'][(int)$q['id']] ?? [];
            $qTotal = array_sum($qc);
        ?>
        <div style="margin-bottom:.9rem">
            <div style="font-weight:600;font-size:.9rem;margin-bottom:.3rem"><?= htmlspecialchars($q['question']) ?> <span class="pl-meta">(<?= $qTotal ?> vote<?= $qTotal === 1 ? '' : 's' ?>)</span></div>
            <?php foreach ($q['options'] as $opt):
                $n = $qc[(int)$opt['id']] ?? 0;
                $pct = $qTotal > 0 ? round($n / $qTotal * 100) : 0;
            ?>
            <div class="pl-bar-wrap" title="<?= $n ?> vote<?= $n === 1 ? '' : 's' ?>">
                <div class="pl-bar" style="width:<?= max(2, $pct) ?>%;<?= $n === 0 ? 'background:#e2e8f0;' : '' ?>"></div>
                <span class="pl-bar-label"><?= htmlspecialchars($opt['label']) ?> &mdash; <?= $n ?> (<?= $pct ?>%)</span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endforeach; ?>

        <?php if (!empty($t['responded'])): ?>
        <p class="pl-names"><strong>Responded:</strong> <?= htmlspecialchars(implode(', ', $t['responded'])) ?></p>
        <?php endif; ?>
        <?php if (!empty($t['waiting'])): ?>
        <p class="pl-names"><strong>Waiting on:</strong> <?= htmlspecialchars(implode(', ', $t['waiting'])) ?></p>
        <?php endif; ?>

        <div class="pl-actions">
            <?php if ($p['status'] === 'open'): ?>
            <form method="post" action="/event_polls.php" style="margin:0">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">
                <input type="hidden" name="action" value="resend">
                <input type="hidden" name="event_id" value="<?= $eventId ?>">
                <input type="hidden" name="poll_id" value="<?= (int)$p['id'] ?>">
                <button type="submit" class="pl-btn" title="Sends only to Yes/Maybe guests who haven't received this poll yet">Send to new respondents</button>
            </form>
            <form method="post" action="/event_polls.php" style="margin:0" onsubmit="return pkConfirmForm(this, 'Close this poll? Nobody will be able to vote anymore.')">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">
                <input type="hidden" name="action" value="close">
                <input type="hidden" name="event_id" value="<?= $eventId ?>">
                <input type="hidden" name="poll_id" value="<?= (int)$p['id'] ?>">
                <button type="submit" class="pl-btn danger">Close poll</button>
            </form>
            <?php else: ?>
            <form method="post" action="/event_polls.php" style="margin:0">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">
                <input type="hidden" name="action" value="reopen">
                <input type="hidden" name="event_id" value="<?= $eventId ?>">
                <input type="hidden" name="poll_id" value="<?= (int)$p['id'] ?>">
                <button type="submit" class="pl-btn">Reopen</button>
            </form>
            <?php endif; ?>
            <form method="post" action="/event_polls.php" style="margin:0;margin-left:auto"
                  onsubmit="return pkConfirmForm(this, 'Permanently delete this poll and all its votes? Recipients\' links will stop working. This cannot be undone.', {okLabel:'Delete', danger:true})">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="event_id" value="<?= $eventId ?>">
                <input type="hidden" name="poll_id" value="<?= (int)$p['id'] ?>">
                <button type="submit" class="pl-btn danger">Delete poll</button>
            </form>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<?php require __DIR__ . '/_footer.php'; ?>

<script nonce="<?= csp_nonce() ?>">
var qIdx = 0;
function addQuestion() {
    var wrap = document.getElementById('plQuestions');
    var i = qIdx++;
    var div = document.createElement('div');
    div.className = 'pl-q';
    div.innerHTML =
        '<div style="display:flex;gap:.4rem;align-items:center">' +
        '<input type="text" name="question[' + i + ']" placeholder="Question ' + (i + 1) + '" maxlength="200" style="flex:1;padding:.45rem .6rem;border:1.5px solid #e2e8f0;border-radius:7px;font-size:.9rem">' +
        '<button type="button" class="pl-btn danger" onclick="this.closest(\'.pl-q\').remove()" title="Remove question">&times;</button>' +
        '</div>' +
        '<div class="pl-opts"></div>' +
        '<button type="button" class="pl-btn" style="margin-top:.4rem" onclick="addOption(this, ' + i + ')">+ Option</button>';
    wrap.appendChild(div);
    addOption(div.querySelector('button:last-child'), i);
    addOption(div.querySelector('button:last-child'), i);
}
function addOption(btn, qi) {
    var opts = btn.closest('.pl-q').querySelector('.pl-opts');
    var row = document.createElement('div');
    row.className = 'pl-opt-row';
    row.innerHTML =
        '<input type="text" name="options[' + qi + '][]" placeholder="Option" maxlength="80" style="padding:.4rem .55rem;border:1.5px solid #e2e8f0;border-radius:7px;font-size:.85rem">' +
        '<button type="button" class="pl-btn" onclick="this.closest(\'.pl-opt-row\').remove()" title="Remove option">&times;</button>';
    opts.appendChild(row);
}
addQuestion(); // start with one empty question
</script>
</body>
</html>
