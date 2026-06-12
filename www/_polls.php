<?php
/**
 * Event poll helpers — shared by the manager page (event_polls.php), the public
 * answer page (poll.php), the notification dispatcher (_notifications.php), and
 * the SMS/WhatsApp webhooks (reply-by-number conversations).
 *
 * Anonymity model: answers are stored per recipient (so votes can be changed,
 * double-votes blocked, and turnout shown) but no UI ever joins answers to a
 * name — results are counts only.
 */

/** Load a poll with nested questions and options, or null. */
function poll_load(PDO $db, int $pollId): ?array {
    $p = $db->prepare('SELECT * FROM event_polls WHERE id = ?');
    $p->execute([$pollId]);
    $poll = $p->fetch();
    if (!$poll) return null;
    $poll['questions'] = [];
    $q = $db->prepare('SELECT id, question, sort_order FROM poll_questions WHERE poll_id = ? ORDER BY sort_order, id');
    $q->execute([$pollId]);
    foreach ($q->fetchAll() as $qr) {
        $o = $db->prepare('SELECT id, label, sort_order FROM poll_options WHERE question_id = ? ORDER BY sort_order, id');
        $o->execute([(int)$qr['id']]);
        $qr['options'] = $o->fetchAll();
        $poll['questions'][] = $qr;
    }
    return $poll;
}

/** Per-question option counts: [question_id => [option_id => count]]. */
function poll_counts(PDO $db, int $pollId): array {
    $out = [];
    $s = $db->prepare(
        'SELECT a.question_id, a.option_id, COUNT(*) AS n
         FROM poll_answers a
         JOIN poll_questions q ON q.id = a.question_id
         WHERE q.poll_id = ?
         GROUP BY a.question_id, a.option_id'
    );
    $s->execute([$pollId]);
    foreach ($s->fetchAll() as $r) {
        $out[(int)$r['question_id']][(int)$r['option_id']] = (int)$r['n'];
    }
    return $out;
}

/** Turnout: who has answered at least one question (names only, never choices). */
function poll_turnout(PDO $db, int $pollId): array {
    $all = $db->prepare('SELECT id, username FROM poll_recipients WHERE poll_id = ? ORDER BY username');
    $all->execute([$pollId]);
    $recipients = $all->fetchAll();
    $resp = $db->prepare(
        'SELECT DISTINCT a.recipient_id FROM poll_answers a
         JOIN poll_questions q ON q.id = a.question_id WHERE q.poll_id = ?'
    );
    $resp->execute([$pollId]);
    $respIds = array_map('intval', array_column($resp->fetchAll(), 'recipient_id'));
    $responded = []; $waiting = [];
    foreach ($recipients as $r) {
        if (in_array((int)$r['id'], $respIds, true)) $responded[] = $r['username'];
        else $waiting[] = $r['username'];
    }
    return ['responded' => $responded, 'waiting' => $waiting, 'total' => count($recipients)];
}

/** A recipient's current answers: [question_id => option_id]. */
function poll_recipient_answers(PDO $db, int $recipientId): array {
    $s = $db->prepare('SELECT question_id, option_id FROM poll_answers WHERE recipient_id = ?');
    $s->execute([$recipientId]);
    $out = [];
    foreach ($s->fetchAll() as $r) $out[(int)$r['question_id']] = (int)$r['option_id'];
    return $out;
}

/**
 * Record (upsert) one answer. Validates the option belongs to the question, the
 * question belongs to the recipient's poll, and the poll is open.
 */
function poll_record_answer(PDO $db, array $recipient, int $questionId, int $optionId): bool {
    $chk = $db->prepare(
        'SELECT 1 FROM poll_options o
         JOIN poll_questions q ON q.id = o.question_id
         JOIN event_polls p ON p.id = q.poll_id
         WHERE o.id = ? AND q.id = ? AND p.id = ? AND p.status = "open"'
    );
    $chk->execute([$optionId, $questionId, (int)$recipient['poll_id']]);
    if (!$chk->fetchColumn()) return false;
    $db->prepare(
        'INSERT INTO poll_answers (question_id, recipient_id, option_id, answered_at)
         VALUES (?, ?, ?, datetime("now"))
         ON CONFLICT(question_id, recipient_id) DO UPDATE SET option_id = excluded.option_id, answered_at = excluded.answered_at'
    )->execute([$questionId, (int)$recipient['id'], $optionId]);
    return true;
}

/** Eligible audience: approved base invitees who RSVP'd yes or maybe. */
function poll_audience(PDO $db, int $eventId): array {
    $s = $db->prepare(
        "SELECT username FROM event_invites
         WHERE event_id = ? AND occurrence_date IS NULL
           AND approval_status = 'approved' AND rsvp IN ('yes','maybe')
         ORDER BY username"
    );
    $s->execute([$eventId]);
    return array_column($s->fetchAll(), 'username');
}

/**
 * Mint recipient rows for everyone currently eligible (skips people who already
 * have one) and queue an event_poll notification for each not-yet-notified
 * recipient. Returns the number queued. Used for both first send and the
 * "send to new respondents" resend.
 */
function poll_send_to_audience(PDO $db, array $poll): int {
    require_once __DIR__ . '/_notifications.php';
    $eventId = (int)$poll['event_id'];
    $pollId  = (int)$poll['id'];
    foreach (poll_audience($db, $eventId) as $uname) {
        $db->prepare('INSERT OR IGNORE INTO poll_recipients (poll_id, username, token) VALUES (?, ?, ?)')
           ->execute([$pollId, $uname, bin2hex(random_bytes(16))]);
    }
    $todo = $db->prepare('SELECT id, username FROM poll_recipients WHERE poll_id = ? AND notified_at IS NULL');
    $todo->execute([$pollId]);
    $queued = 0;
    foreach ($todo->fetchAll() as $r) {
        queue_event_notification($db, $eventId, $r['username'], 'event_poll', null, [
            'poll_id'      => $pollId,
            'recipient_id' => (int)$r['id'],
        ]);
        $db->prepare('UPDATE poll_recipients SET notified_at = datetime("now") WHERE id = ?')->execute([(int)$r['id']]);
        $queued++;
    }
    if ($queued > 0) drain_queue_async();
    return $queued;
}

/** Canonical 10-digit phone key for sms_pending_poll (strips +1 / punctuation). */
function poll_phone_key(string $phone): string {
    $d = preg_replace('/\D/', '', $phone);
    if (strlen($d) === 11 && $d[0] === '1') $d = substr($d, 1);
    return $d;
}

/** Begin (or restart) a reply-by-number conversation for a phone. */
function poll_start_conversation(PDO $db, string $phone, int $recipientId): void {
    $key = poll_phone_key($phone);
    if (strlen($key) !== 10) return;
    $db->prepare("INSERT OR REPLACE INTO sms_pending_poll (phone, recipient_id, question_idx, created_at)
                  VALUES (?, ?, 0, datetime('now'))")
       ->execute([$key, $recipientId]);
}

/** Plain-text rendering of one question for SMS/WhatsApp. */
function poll_question_text(array $poll, int $idx): string {
    $q = $poll['questions'][$idx];
    $n = count($poll['questions']);
    $txt = 'Q' . ($idx + 1) . ' of ' . $n . ': ' . $q['question'] . "\n";
    foreach ($q['options'] as $i => $opt) {
        $txt .= ($i + 1) . '=' . $opt['label'] . ' ';
    }
    return rtrim($txt) . "\nReply with a number.";
}

/** The recipient's personal answer-page link (shortened when enabled). */
function poll_link_for_recipient(array $recipient): string {
    $url = get_site_url() . '/poll.php?t=' . urlencode($recipient['token']);
    if (get_setting('url_shortener_enabled') === '1') {
        $url = shorten_url($url);
    }
    return $url;
}

/**
 * Handle an inbound SMS/WhatsApp body for a phone that may have an active poll
 * conversation. Returns the reply text to send, or null when this message is
 * not a poll answer (caller continues its normal parsing). Used by BOTH
 * sms_webhook.php and wa_webhook.php, including for phones with no user row.
 */
function poll_handle_inbound(PDO $db, string $phoneRaw, string $body): ?string {
    $key = poll_phone_key($phoneRaw);
    if (strlen($key) !== 10) return null;
    $body = trim($body);
    if (!preg_match('/^\d{1,2}$/', $body)) return null; // only bare numbers are poll answers

    // Expire stale conversations (48h — polls get answered over days, unlike
    // the 10-minute RSVP menus).
    $db->prepare("DELETE FROM sms_pending_poll WHERE created_at < datetime('now', '-48 hours')")->execute();

    $s = $db->prepare('SELECT * FROM sms_pending_poll WHERE phone = ?');
    $s->execute([$key]);
    $state = $s->fetch();
    if (!$state) return null;

    $rec = $db->prepare('SELECT * FROM poll_recipients WHERE id = ?');
    $rec->execute([(int)$state['recipient_id']]);
    $recipient = $rec->fetch();
    if (!$recipient) {
        $db->prepare('DELETE FROM sms_pending_poll WHERE phone = ?')->execute([$key]);
        return null;
    }
    $poll = poll_load($db, (int)$recipient['poll_id']);
    if (!$poll || $poll['status'] !== 'open' || empty($poll['questions'])) {
        $db->prepare('DELETE FROM sms_pending_poll WHERE phone = ?')->execute([$key]);
        return 'That poll has closed. Thanks anyway!';
    }

    $idx = (int)$state['question_idx'];
    if ($idx >= count($poll['questions'])) { // shouldn't happen; clean up
        $db->prepare('DELETE FROM sms_pending_poll WHERE phone = ?')->execute([$key]);
        return null;
    }
    $q = $poll['questions'][$idx];
    $n = (int)$body;
    if ($n < 1 || $n > count($q['options'])) {
        return "Please reply 1-" . count($q['options']) . ".\n" . poll_question_text($poll, $idx);
    }
    $opt = $q['options'][$n - 1];
    if (!poll_record_answer($db, $recipient, (int)$q['id'], (int)$opt['id'])) {
        $db->prepare('DELETE FROM sms_pending_poll WHERE phone = ?')->execute([$key]);
        return 'That poll has closed. Thanks anyway!';
    }

    $next = $idx + 1;
    if ($next < count($poll['questions'])) {
        $db->prepare('UPDATE sms_pending_poll SET question_idx = ? WHERE phone = ?')->execute([$next, $key]);
        return poll_question_text($poll, $next);
    }
    $db->prepare('DELETE FROM sms_pending_poll WHERE phone = ?')->execute([$key]);
    return "Thanks! Your answers are recorded.\nSee results: " . poll_link_for_recipient($recipient);
}
