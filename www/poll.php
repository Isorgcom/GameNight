<?php
/**
 * Tokenized poll answer page — no login required.
 *
 * Usage: /poll.php?t=TOKEN  (TOKEN = poll_recipients.token, unique per person)
 *
 * GET renders the question form; POST records answers (same GET-form/POST-write
 * split as rsvp.php so link-preview crawlers can never vote by fetching the URL).
 * Counts are shown only AFTER the visitor has voted (and on closed polls), so
 * late voters can't peek at standings before answering. Results never show who
 * voted for what.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_polls.php';

$db    = get_db();
$token = trim($_REQUEST['t'] ?? '');

$recipient = null;
if ($token !== '' && strlen($token) <= 64) {
    $rs = $db->prepare('SELECT * FROM poll_recipients WHERE token = ?');
    $rs->execute([$token]);
    $recipient = $rs->fetch();
}
$poll  = $recipient ? poll_load($db, (int)$recipient['poll_id']) : null;
$event = null;
if ($poll) {
    $es = $db->prepare('SELECT id, title, start_date, start_time FROM events WHERE id = ?');
    $es->execute([(int)$poll['event_id']]);
    $event = $es->fetch();
}

$site_name = get_setting('site_name', 'Game Night');

if (!$recipient || !$poll || !$event || empty($poll['questions'])) {
    http_response_code(404);
    $invalid = true;
} else {
    $invalid = false;
    $isOpen  = $poll['status'] === 'open';
    $answers = poll_recipient_answers($db, (int)$recipient['id']);
    $hasVoted = !empty($answers);

    // ── POST: record answers (token is the authorization, like rsvp.php) ──
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isOpen) {
        // Abuse guard: a token shouldn't need more than a handful of submissions.
        if ((int)$recipient['submit_count'] >= 50) {
            http_response_code(429);
            exit('Too many submissions for this link.');
        }
        $posted = (array)($_POST['answer'] ?? []); // answer[question_id] = option_id
        $saved = 0;
        foreach ($poll['questions'] as $q) {
            $qid = (int)$q['id'];
            $oid = (int)($posted[$qid] ?? 0);
            if ($oid > 0 && poll_record_answer($db, $recipient, $qid, $oid)) $saved++;
        }
        $db->prepare('UPDATE poll_recipients SET submit_count = submit_count + 1 WHERE id = ?')
           ->execute([(int)$recipient['id']]);
        header('Location: /poll.php?t=' . urlencode($token) . ($saved > 0 ? '&saved=1' : ''));
        exit;
    }

    $showForm = $isOpen && (!$hasVoted || isset($_GET['edit']));
    $counts   = poll_counts($db, (int)$poll['id']);
    $justSaved = isset($_GET['saved']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Poll &mdash; <?= htmlspecialchars($site_name) ?></title>
    <link rel="stylesheet" href="/style.css?v=<?= htmlspecialchars(APP_VERSION . '.' . (@filemtime(__DIR__ . '/style.css') ?: 0)) ?>">
    <style>
        body { background:#f1f5f9; }
        .po-wrap { max-width: 560px; margin: 2rem auto; padding: 0 1rem; }
        .po-card { background:#fff; border:1.5px solid #e2e8f0; border-radius:12px; padding:1.5rem; box-shadow:0 4px 18px rgba(15,23,42,.06); }
        .po-q { margin-bottom:1.1rem; }
        .po-q-title { font-weight:700; font-size:.95rem; margin-bottom:.45rem; }
        .po-opt { display:flex; align-items:center; gap:.5rem; padding:.5rem .65rem; border:1.5px solid #e2e8f0; border-radius:8px; margin-bottom:.35rem; cursor:pointer; font-size:.9rem; }
        .po-opt:hover { border-color:#93c5fd; background:#f8fafc; }
        .po-opt input { width:16px; height:16px; }
        .po-bar-wrap { background:#f1f5f9; border-radius:6px; height:22px; position:relative; overflow:hidden; margin-bottom:.35rem; }
        .po-bar { background:#2563eb; height:100%; border-radius:6px; min-width:2px; }
        .po-bar.mine { background:#16a34a; }
        .po-bar-label { position:absolute; left:.55rem; top:0; line-height:22px; font-size:.74rem; font-weight:700; color:#1e293b; white-space:nowrap; }
        .po-meta { font-size:.78rem; color:#94a3b8; }
    </style>
</head>
<body>
<div class="po-wrap">
    <div class="po-card">
        <?php if ($invalid): ?>
            <h2 style="margin:0 0 .5rem">Poll not found</h2>
            <p style="color:#64748b">This poll link is no longer valid.</p>
        <?php else: ?>
            <p class="po-meta" style="margin:0 0 .25rem"><?= htmlspecialchars($site_name) ?> &middot; <?= htmlspecialchars($event['title']) ?> &middot; <?= htmlspecialchars($event['start_date']) ?></p>
            <h2 style="margin:0 0 .35rem;font-size:1.25rem"><?= htmlspecialchars($poll['title']) ?></h2>
            <p class="po-meta" style="margin:0 0 1.1rem">Answers are anonymous &mdash; only totals are shown, never who picked what.</p>

            <?php if (!empty($justSaved)): ?>
            <div class="alert alert-success" style="margin-bottom:1rem">Your answers are recorded. Thanks!</div>
            <?php endif; ?>
            <?php if (!$isOpen): ?>
            <div class="alert" style="margin-bottom:1rem;background:#f1f5f9;border:1.5px solid #e2e8f0;color:#475569;border-radius:8px;padding:.6rem .9rem">This poll is closed. Final results:</div>
            <?php endif; ?>

            <?php if ($showForm): ?>
            <!-- ── Answer form (counts intentionally hidden until you vote) ── -->
            <form method="post" action="/poll.php">
                <input type="hidden" name="t" value="<?= htmlspecialchars($token) ?>">
                <?php foreach ($poll['questions'] as $q): ?>
                <div class="po-q">
                    <div class="po-q-title"><?= htmlspecialchars($q['question']) ?></div>
                    <?php foreach ($q['options'] as $opt): ?>
                    <label class="po-opt">
                        <input type="radio" name="answer[<?= (int)$q['id'] ?>]" value="<?= (int)$opt['id'] ?>"
                               <?= (($answers[(int)$q['id']] ?? 0) === (int)$opt['id']) ? 'checked' : '' ?> required>
                        <span><?= htmlspecialchars($opt['label']) ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
                <?php endforeach; ?>
                <button type="submit" class="btn btn-primary" style="width:100%"><?= $hasVoted ? 'Update my answers' : 'Submit answers' ?></button>
            </form>
            <?php else: ?>
            <!-- ── Results (visible after voting, or when closed) ── -->
            <?php foreach ($poll['questions'] as $q):
                $qc = $counts[(int)$q['id']] ?? [];
                $qTotal = array_sum($qc);
                $mine = $answers[(int)$q['id']] ?? 0;
            ?>
            <div class="po-q">
                <div class="po-q-title"><?= htmlspecialchars($q['question']) ?> <span class="po-meta">(<?= $qTotal ?> vote<?= $qTotal === 1 ? '' : 's' ?>)</span></div>
                <?php foreach ($q['options'] as $opt):
                    $n = $qc[(int)$opt['id']] ?? 0;
                    $pct = $qTotal > 0 ? round($n / $qTotal * 100) : 0;
                    $isMine = $mine === (int)$opt['id'];
                ?>
                <div class="po-bar-wrap" title="<?= $n ?> vote<?= $n === 1 ? '' : 's' ?>">
                    <div class="po-bar<?= $isMine ? ' mine' : '' ?>" style="width:<?= max(2, $pct) ?>%;<?= $n === 0 ? 'background:#e2e8f0;' : '' ?>"></div>
                    <span class="po-bar-label"><?= htmlspecialchars($opt['label']) ?> &mdash; <?= $n ?> (<?= $pct ?>%)<?= $isMine ? ' &#10003; you' : '' ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endforeach; ?>
            <?php if ($isOpen && $hasVoted): ?>
            <a href="/poll.php?t=<?= urlencode($token) ?>&edit=1" class="btn btn-outline" style="width:100%;display:block;text-align:center;text-decoration:none;box-sizing:border-box">Change my answers</a>
            <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
