<?php
/**
 * Support ticket actions: create, reply (a user reply on a resolved ticket
 * reopens it), set_status (admin). Alerts ride notify_user_direct():
 * new tickets / user replies go to every admin (email carries the content,
 * SMS stays link-only); admin replies alert the ticket owner link-only.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_notifications.php';
header('Content-Type: application/json');

$current = require_login();
$db      = get_db();
$uid     = (int)$current['id'];
$isAdmin = $current['role'] === 'admin';

function fail(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}
function ok(array $extra = []): void {
    echo json_encode(array_merge(['ok' => true], $extra));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('POST required', 405);
if (!csrf_verify()) fail('Invalid CSRF token', 403);

/** Uploaded-screenshot path must be exactly what upload.php hands out. */
function clean_screenshot(?string $path): ?string {
    $path = trim((string)$path);
    if ($path === '') return null;
    // \z (not $) so a trailing newline can't sneak into the stored path.
    // Legacy flat path OR the namespaced tickets/u<id>_… that upload.php now returns.
    if (!preg_match('#^/uploads/(tickets/u\d+_)?[a-f0-9]{32}\.(jpg|png|gif|webp)\z#', $path)) return null;
    return $path;
}

/** Alert every admin except the actor about ticket activity. */
function alert_admins(PDO $db, int $actor_id, int $ticket_id, string $subject, string $inbox, string $sms, string $html): void {
    $admins = $db->query("SELECT id FROM users WHERE role = 'admin'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($admins as $aid) {
        if ((int)$aid === $actor_id) continue;
        notify_user_direct($db, (int)$aid, 'ticket_admin', $subject, $inbox,
                           '/support_ticket.php?id=' . $ticket_id, $sms, $html);
    }
}

$action = $_POST['action'] ?? '';
$site   = get_setting('site_name', 'Game Night');

switch ($action) {

    case 'create': {
        $subject = trim((string)($_POST['subject'] ?? ''));
        $body    = trim((string)($_POST['body'] ?? ''));
        if ($subject === '' || mb_strlen($subject) > 150) fail('Enter a subject (150 characters max).');
        if ($body === '') fail('Describe the problem or question.');
        if (mb_strlen($body) > 5000) fail('Description is too long (5000 character max).');
        $shot = clean_screenshot($_POST['screenshot_path'] ?? '');

        $s = $db->prepare("SELECT COUNT(*) FROM tickets WHERE user_id = ? AND created_at > datetime('now','-1 day')");
        $s->execute([$uid]);
        if ((int)$s->fetchColumn() >= MAX_TICKETS_PER_DAY) fail('You have opened several tickets today — please wait for a reply.', 429);

        $db->prepare('INSERT INTO tickets (user_id, subject) VALUES (?, ?)')->execute([$uid, $subject]);
        $tid = (int)$db->lastInsertId();
        $db->prepare('INSERT INTO ticket_messages (ticket_id, user_id, is_admin_reply, body, screenshot_path) VALUES (?, ?, ?, ?, ?)')
           ->execute([$tid, $uid, $isAdmin ? 1 : 0, $body, $shot]);
        db_log_activity($uid, "opened support ticket #$tid: $subject");

        $mailSubject = "[Ticket #$tid] $subject";
        alert_admins($db, $uid, $tid,
            $mailSubject,
            $current['username'] . ': ' . mb_substr($body, 0, 120),
            "New support ticket from {$current['username']} on $site: " . shorten_url(get_site_url() . '/support_ticket.php?id=' . $tid),
            '<p><strong>' . htmlspecialchars($current['username']) . '</strong> opened a support ticket on ' . htmlspecialchars($site) . ':</p>'
            . '<p style="font-weight:700">' . htmlspecialchars($subject) . '</p>'
            . '<blockquote style="margin:.5rem 0;padding:.5rem .8rem;background:#f1f5f9;border-left:3px solid #94a3b8;border-radius:4px">' . nl2br(htmlspecialchars($body)) . '</blockquote>'
            . '<p><a href="' . htmlspecialchars(get_site_url() . '/support_ticket.php?id=' . $tid) . '" style="display:inline-block;padding:.5rem 1.2rem;border-radius:6px;text-decoration:none;font-weight:600;color:#fff;background:#2563eb">Open ticket</a></p>');
        ok(['id' => $tid]);
    }

    case 'reply': {
        $tid  = (int)($_POST['ticket_id'] ?? 0);
        $body = trim((string)($_POST['body'] ?? ''));
        if ($body === '') fail('Write a reply first.');
        if (mb_strlen($body) > 5000) fail('Reply is too long (5000 character max).');
        $shot = clean_screenshot($_POST['screenshot_path'] ?? '');

        $t = $db->prepare('SELECT t.*, u.username AS owner_name FROM tickets t JOIN users u ON u.id = t.user_id WHERE t.id = ?');
        $t->execute([$tid]);
        $ticket = $t->fetch();
        if (!$ticket || (!$isAdmin && (int)$ticket['user_id'] !== $uid)) fail('Ticket not found.', 404);

        $s = $db->prepare("SELECT COUNT(*) FROM ticket_messages WHERE user_id = ? AND created_at > datetime('now','-1 hour')");
        $s->execute([$uid]);
        if ((int)$s->fetchColumn() >= MAX_TICKET_REPLIES_PER_HOUR) fail('Slow down — too many replies this hour.', 429);

        $db->prepare('INSERT INTO ticket_messages (ticket_id, user_id, is_admin_reply, body, screenshot_path) VALUES (?, ?, ?, ?, ?)')
           ->execute([$tid, $uid, $isAdmin ? 1 : 0, $body, $shot]);
        // A user reply on a resolved ticket reopens it.
        $reopened = false;
        if (!$isAdmin && $ticket['status'] === 'resolved') {
            $db->prepare("UPDATE tickets SET status = 'open', updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$tid]);
            $reopened = true;
        } else {
            $db->prepare('UPDATE tickets SET updated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$tid]);
        }

        $url = get_site_url() . '/support_ticket.php?id=' . $tid;
        if ($isAdmin) {
            // Admin replied: alert the ticket owner (unless replying to self).
            if ((int)$ticket['user_id'] !== $uid) {
                notify_user_direct($db, (int)$ticket['user_id'], 'ticket_reply',
                    'Reply to your support ticket on ' . $site,
                    mb_substr($body, 0, 120),
                    '/support_ticket.php?id=' . $tid,
                    "An admin replied to your support ticket on $site: " . shorten_url($url),
                    '<p>An admin replied to your support ticket <strong>' . htmlspecialchars($ticket['subject']) . '</strong>:</p>'
                    . '<blockquote style="margin:.5rem 0;padding:.5rem .8rem;background:#f1f5f9;border-left:3px solid #94a3b8;border-radius:4px">' . nl2br(htmlspecialchars($body)) . '</blockquote>'
                    . '<p><a href="' . htmlspecialchars($url) . '" style="display:inline-block;padding:.5rem 1.2rem;border-radius:6px;text-decoration:none;font-weight:600;color:#fff;background:#2563eb">Open ticket</a></p>');
            }
        } else {
            alert_admins($db, $uid, $tid,
                '[Ticket #' . $tid . '] New reply from ' . $current['username'] . ($reopened ? ' (reopened)' : ''),
                $current['username'] . ': ' . mb_substr($body, 0, 120),
                "New support activity on $site: " . shorten_url($url),
                '<p><strong>' . htmlspecialchars($current['username']) . '</strong> replied on ticket <strong>' . htmlspecialchars($ticket['subject']) . '</strong>' . ($reopened ? ' (reopened)' : '') . ':</p>'
                . '<blockquote style="margin:.5rem 0;padding:.5rem .8rem;background:#f1f5f9;border-left:3px solid #94a3b8;border-radius:4px">' . nl2br(htmlspecialchars($body)) . '</blockquote>'
                . '<p><a href="' . htmlspecialchars($url) . '" style="display:inline-block;padding:.5rem 1.2rem;border-radius:6px;text-decoration:none;font-weight:600;color:#fff;background:#2563eb">Open ticket</a></p>');
        }
        db_log_activity($uid, "replied on support ticket #$tid");
        ok(['reopened' => $reopened]);
    }

    case 'set_status': {
        if (!$isAdmin) fail('Admins only.', 403);
        $tid    = (int)($_POST['ticket_id'] ?? 0);
        $status = ($_POST['status'] ?? '') === 'resolved' ? 'resolved' : 'open';
        $u = $db->prepare('UPDATE tickets SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
        $u->execute([$status, $tid]);
        if ($u->rowCount() < 1) fail('Ticket not found.', 404);
        db_log_activity($uid, "marked support ticket #$tid $status");
        ok(['status' => $status]);
    }

    default:
        fail('Unknown action.');
}
