<?php
/**
 * An event's message history: what was sent, to whom, how, when, what came
 * back, and what is still scheduled. Assembled from
 *   sms_log                  deliveries (email, text, WhatsApp) and replies
 *   pending_notifications    scheduled, in flight, dead after retries, sent
 *   event_notifications_sent dispatched with nothing to log (no contact, muted)
 *   event_messages           the host's own messages (subjects)
 * Nothing here writes. event_history.php renders what these return.
 *
 * Rows logged before 1.15.0 carry no notify_type; eh_classify() works out
 * what they were from the queue row claimed at the same moment, the
 * recipient's inbox row, or, failing both, the wording of the dispatcher's
 * templates in _notifications.php.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/_notifications.php'; // _format_offset_label()

const EH_WINDOW = 120; // seconds between a queue row's claim and its log rows
const EH_HOST_NOTICE_TYPES = ['rsvp_to_creator'];

// ── Loading ─────────────────────────────────────────────────────────────────

function eh_load(PDO $db, int $eid): array {
    $d = ['eid' => $eid, 'event' => null];
    $st = $db->prepare('SELECT id, title, start_date, start_time, created_by, league_id, cancelled_at, reminders_enabled FROM events WHERE id = ?');
    $st->execute([$eid]);
    $d['event'] = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$d['event']) return $d;
    $q = static function (string $sql, array $p) use ($db): array {
        $s = $db->prepare($sql); $s->execute($p); return $s->fetchAll(PDO::FETCH_ASSOC);
    };
    $d['log']      = $q("SELECT id, direction, phone, phone_digits, body, provider, status, error, created_at, username, is_conversation, notify_type
                         FROM sms_log WHERE event_id = ? ORDER BY created_at, id", [$eid]);
    $d['queue']    = $q("SELECT id, username, user_id, notify_type, occurrence_date, payload, scheduled_for, created_at, attempted_at, attempts
                         FROM pending_notifications WHERE event_id = ? ORDER BY id", [$eid]);
    $d['markers']  = $q("SELECT user_identifier, notification_type, occurrence_date, sent_at FROM event_notifications_sent WHERE event_id = ?", [$eid]);
    $d['invites']  = $q("SELECT username, user_id, phone, email, rsvp, event_role, approval_status, sort_order
                         FROM event_invites WHERE event_id = ? AND occurrence_date IS NULL
                         ORDER BY COALESCE(sort_order, 999999), username", [$eid]);
    $d['messages'] = $q("SELECT id, subject, audience, occurrence_date, created_at FROM event_messages WHERE event_id = ? ORDER BY created_at, id", [$eid]);

    // Hosts: the creator (by account id) and every manager row. Lower-cased names.
    $hosts = [];
    foreach ($q('SELECT u.username FROM events e JOIN users u ON u.id = e.created_by WHERE e.id = ?', [$eid]) as $r) {
        $hosts[strtolower((string)$r['username'])] = true;
    }
    foreach ($d['invites'] as $i) {
        if (($i['event_role'] ?? '') === 'manager') $hosts[strtolower((string)$i['username'])] = true;
    }
    $d['hosts'] = $hosts;

    // The league's known-as names, as the event page shows them (v1.13.0).
    $d['known'] = [];
    if (!empty($d['event']['league_id'])) {
        try {
            foreach ($q("SELECT LOWER(i.username) AS n, lm.known_as FROM event_invites i
                         JOIN league_members lm ON lm.user_id = i.user_id AND lm.league_id = ?
                         WHERE i.event_id = ? AND i.occurrence_date IS NULL AND lm.known_as IS NOT NULL AND lm.known_as <> ''",
                        [(int)$d['event']['league_id'], $eid]) as $r) {
                if (strcasecmp((string)$r['known_as'], (string)$r['n']) !== 0) $d['known'][$r['n']] = (string)$r['known_as'];
            }
        } catch (Throwable $e) {}
    }

    // The in-app inbox carries the type for recipients with an account: only
    // needed when some log row has none.
    $d['inbox'] = [];
    $untyped = false;
    foreach ($d['log'] as $r) { if ($r['direction'] === 'outbound' && empty($r['notify_type'])) { $untyped = true; break; } }
    if ($untyped) {
        $ids = [];
        foreach ($d['invites'] as $i) { if ((int)($i['user_id'] ?? 0) > 0) $ids[] = (int)$i['user_id']; }
        if ($ids) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            try {
                $d['inbox'] = $q("SELECT user_id, notify_type, created_at FROM user_notifications WHERE user_id IN ($ph) AND event_id = ?", array_merge($ids, [$eid]));
            } catch (Throwable $e) {}
        }
    }
    return $d;
}

// ── Small helpers ───────────────────────────────────────────────────────────

function eh_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE); }

/** A UTC 'Y-m-d H:i:s' string as a unix time. */
function eh_ts(?string $utc): int { return $utc ? (strtotime($utc . ' UTC') ?: 0) : 0; }

/** A UTC string in the viewer's zone (current_user() already applied it). */
function eh_local(?string $utc, string $fmt = 'D M j, g:i A'): string {
    if (!$utc) return '';
    try {
        $t = (new DateTime($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(date_default_timezone_get()));
        if ($t->format('Y') !== date('Y') && strpos($fmt, 'Y') === false) $fmt = str_replace('j', 'j, Y', $fmt);
        return $t->format($fmt);
    } catch (Throwable $e) { return (string)$utc; }
}
function eh_local_ts(int $ts, string $fmt = 'D M j, g:i A'): string { return eh_local(gmdate('Y-m-d H:i:s', $ts), $fmt); }

function eh_channel(?string $provider): string {
    $p = strtolower((string)$provider);
    if ($p === 'email') return 'email';
    if ($p === 'waha' || $p === 'whatsapp') return 'whatsapp';
    return 'sms';
}
function eh_channel_label(string $ch, int $n = 1): string {
    if ($ch === 'email') return $n === 1 ? 'email' : 'emails';
    if ($ch === 'whatsapp') return 'WhatsApp';
    return $n === 1 ? 'text' : 'texts';
}

function eh_digits(string $s): string {
    $d = preg_replace('/\D/', '', $s);
    if (strlen($d) === 11 && $d[0] === '1') $d = substr($d, 1);
    return $d;
}
function eh_fmt_phone(string $digits): string {
    return strlen($digits) === 10 ? substr($digits, 0, 3) . '-' . substr($digits, 3, 3) . '-' . substr($digits, 6, 4) : $digits;
}
/** The address a row went to, readable: 832-642-2893, or the email address. */
function eh_address(array $r): string {
    if (!empty($r['phone_digits'])) return eh_fmt_phone((string)$r['phone_digits']);
    $p = (string)($r['phone'] ?? '');
    if (strpos($p, '@') !== false) return $p;
    $d = eh_digits($p);
    return strlen($d) === 10 ? eh_fmt_phone($d) : $p;
}

/** A dedup marker tag ('reminder_2880', 'rsvp_to_creator_2461') as [type, meta]. */
function eh_split_tag(string $tag): array {
    if (preg_match('/^reminder_(\d+)$/', $tag, $m)) return ['reminder', ['offset' => (int)$m[1]]];
    if (preg_match('/^(rsvp_to_creator|event_message|event_poll|event_comment|online_table)_\d+$/', $tag, $m)) return [$m[1], []];
    if (preg_match('/^rsvp_nudge_\d{4}-\d{2}-\d{2}$/', $tag)) return ['rsvp_nudge', []];
    return [$tag, []];
}

function eh_payload_meta(array $qrow): array {
    $meta = [];
    $p = !empty($qrow['payload']) ? (json_decode((string)$qrow['payload'], true) ?: []) : [];
    if (isset($p['offset_minutes'])) $meta['offset'] = (int)$p['offset_minutes'];
    if (isset($p['message_id']))     $meta['message_id'] = (int)$p['message_id'];
    if (isset($p['rsvp']))           $meta['rsvp'] = strtolower((string)$p['rsvp']);
    if (isset($p['responder_display'])) $meta['responder'] = (string)$p['responder_display'];
    elseif (isset($p['responder_username'])) $meta['responder'] = (string)$p['responder_username'];
    if (!empty($qrow['occurrence_date'])) $meta['occ'] = (string)$qrow['occurrence_date'];
    return $meta;
}

/** What a reply means as an RSVP, or null (the webhooks' own keyword map). */
function eh_rsvp_word(string $body): ?string {
    $w = strtolower(trim(preg_replace('/[^a-z ]/i', '', $body)));
    $map = ['yes' => 'yes', 'y' => 'yes', 'going' => 'yes', 'attend' => 'yes',
            'no' => 'no', 'n' => 'no', 'not going' => 'no', 'decline' => 'no',
            'maybe' => 'maybe', 'm' => 'maybe', 'unsure' => 'maybe'];
    return $map[$w] ?? null;
}

/** Minutes from a reminder's "in 2 days" / "in 12 hours" / "in 1 week" / "in 30 min". */
function eh_offset_from_text(string $body): ?int {
    if (!preg_match('/\bin (\d+) (week|day|hour|min)s?\b/i', $body, $m)) return null;
    $n = (int)$m[1];
    switch (strtolower($m[2])) {
        case 'week': return $n * 10080;
        case 'day':  return $n * 1440;
        case 'hour': return $n * 60;
        default:     return $n;
    }
}

/** Details read off the wording when the row's type is known but its payload is not. */
function eh_body_meta(string $type, string $body, array $ctx): array {
    $meta = [];
    if ($type === 'reminder') {
        $o = eh_offset_from_text($body);
        if ($o !== null) $meta['offset'] = $o;
    } elseif ($type === 'rsvp_to_creator') {
        if (preg_match('/^(.+?) replied (YES|NO|MAYBE) to /', $body, $m)) { $meta['responder'] = $m[1]; $meta['rsvp'] = strtolower($m[2]); }
    } elseif ($type === 'event_message') {
        $k = strtolower(trim($body));
        if (isset($ctx['subjects'][$k])) $meta['message_id'] = $ctx['subjects'][$k];
    }
    return $meta;
}

/** The row of $rows whose [$tsKey] lies within EH_WINDOW of $ts, nearest first; null if none. */
function eh_nearest(array $rows, string $tsKey, int $ts): ?array {
    $best = null; $bestD = EH_WINDOW + 1;
    foreach ($rows as $r) {
        $d = abs((int)$r[$tsKey] - $ts);
        if ($d <= EH_WINDOW && $d < $bestD) { $best = $r; $bestD = $d; }
    }
    return $best;
}

// ── Classification ──────────────────────────────────────────────────────────

/** Indexes the classifier needs, built once per page. */
function eh_context(array $d): array {
    $ctx = ['queue_by_user' => [], 'inbox_by_user' => [], 'invite_uid' => [], 'subjects' => [], 'messages' => $d['messages'] ?? []];
    foreach ($d['queue'] ?? [] as $qr) {
        if (empty($qr['attempted_at'])) continue;
        $qr['attempted_ts'] = eh_ts($qr['attempted_at']);
        $ctx['queue_by_user'][strtolower((string)$qr['username'])][] = $qr;
    }
    foreach ($d['inbox'] ?? [] as $n) {
        $n['ts'] = eh_ts($n['created_at']);
        $ctx['inbox_by_user'][(int)$n['user_id']][] = $n;
    }
    foreach ($d['invites'] ?? [] as $i) {
        if ((int)($i['user_id'] ?? 0) > 0) $ctx['invite_uid'][strtolower((string)$i['username'])] = (int)$i['user_id'];
    }
    foreach ($d['messages'] ?? [] as $m) $ctx['subjects'][strtolower(trim((string)$m['subject']))] = (int)$m['id'];
    return $ctx;
}

/** ['type' => …, 'meta' => […]] for one sms_log row. */
function eh_classify(array $r, array $ctx): array {
    $u    = strtolower((string)($r['username'] ?? ''));
    $ts   = eh_ts($r['created_at']);
    $body = (string)($r['body'] ?? '');
    $conv = (int)($r['is_conversation'] ?? 0) === 1;

    if (($r['direction'] ?? '') === 'inbound') {
        if ($conv) return ['type' => 'conversation', 'meta' => []];
        if (!empty($r['notify_type'])) return ['type' => (string)$r['notify_type'], 'meta' => []];
        $rsvp = eh_rsvp_word($body);
        return $rsvp !== null ? ['type' => 'rsvp_reply', 'meta' => ['rsvp' => $rsvp]] : ['type' => 'inbound_other', 'meta' => []];
    }

    // The queue row claimed at the same moment carries the payload (offset,
    // message id, who replied) even when the log row names its own type.
    $qrow = $u !== '' ? eh_nearest($ctx['queue_by_user'][$u] ?? [], 'attempted_ts', $ts) : null;
    $meta = $qrow ? eh_payload_meta($qrow) : [];

    if (!empty($r['notify_type'])) {
        $t = (string)$r['notify_type'];
        return ['type' => $t, 'meta' => $meta + eh_body_meta($t, $body, $ctx)];
    }
    if ($conv) return ['type' => 'conversation', 'meta' => []];
    if ($qrow && !empty($qrow['notify_type'])) {
        $t = (string)$qrow['notify_type'];
        return ['type' => $t, 'meta' => $meta + eh_body_meta($t, $body, $ctx)];
    }
    $uid = $ctx['invite_uid'][$u] ?? 0;
    if ($uid > 0) {
        $n = eh_nearest($ctx['inbox_by_user'][$uid] ?? [], 'ts', $ts);
        if ($n && !empty($n['notify_type'])) {
            $t = (string)$n['notify_type'];
            return ['type' => $t, 'meta' => eh_body_meta($t, $body, $ctx)];
        }
    }
    return eh_heuristic($r, $ctx);
}

/** The dispatcher's wording (_notifications.php), newest templates first where two overlap. */
function eh_heuristic(array $r, array $ctx): array {
    $b = (string)($r['body'] ?? '');
    $ch = eh_channel($r['provider'] ?? null);
    if (preg_match('/^Got it/', $b)) return ['type' => 'auto_reply', 'meta' => []];
    // A host message: the email row holds the subject, the text names the sender.
    // Checked before the reminder pattern because the SMS REMIND command saves a
    // message whose subject starts "Reminder:".
    $k = strtolower(trim($b));
    if ($ch === 'email' && isset($ctx['subjects'][$k])) return ['type' => 'event_message', 'meta' => ['message_id' => $ctx['subjects'][$k]]];
    if (preg_match('/: new message from .+ about "/', $b) || preg_match('/^From .+ \xC2\xB7 /u', $b)) {
        return ['type' => 'event_message', 'meta' => eh_nearest_message($r, $ctx)];
    }
    if (preg_match('/You\'ve been invited to "|^You\'re invited: /', $b))              return ['type' => 'invite', 'meta' => []];
    if (preg_match('/^Still deciding\? |Friendly reminder: you haven\'t RSVPed/', $b))   return ['type' => 'rsvp_nudge', 'meta' => []];
    if (preg_match('/^(.+?) replied (YES|NO|MAYBE) to /', $b, $m))                       return ['type' => 'rsvp_to_creator', 'meta' => ['responder' => $m[1], 'rsvp' => strtolower($m[2])]];
    if (preg_match('/^Reminder: /', $b)) { $o = eh_offset_from_text($b); return ['type' => 'reminder', 'meta' => $o !== null ? ['offset' => $o] : []]; }
    if (preg_match('/ occurrence of ".+" has been cancelled/', $b))                       return ['type' => 'cancel_occurrence', 'meta' => []];
    if (preg_match('/has been cancelled|^Cancelled: /', $b))                              return ['type' => 'cancel_event', 'meta' => []];
    if (preg_match('/has been updated|^Updated: /', $b))                                  return ['type' => 'event_updated', 'meta' => []];
    if (preg_match('/^Poll: |: Poll for "/', $b))                                         return ['type' => 'event_poll', 'meta' => []];
    if (preg_match('/ commented on "/', $b))                                              return ['type' => 'event_comment', 'meta' => []];
    if (preg_match('/A seat opened up/', $b))                                             return ['type' => 'waitlist_promoted', 'meta' => []];
    if (preg_match('/^Moved to waitlist: |moved to the waitlist/', $b))                   return ['type' => 'rsvp_deadline_demoted', 'meta' => []];
    if (preg_match('/^Approved for |You\'re approved for "/', $b))                        return ['type' => 'poker_approved', 'meta' => []];
    if (preg_match('/^Your table for |is played online at /', $b))                        return ['type' => 'online_table', 'meta' => []];
    return ['type' => 'message', 'meta' => []];
}

function eh_nearest_message(array $r, array $ctx): array {
    $ts = eh_ts($r['created_at']); $best = null; $bd = PHP_INT_MAX;
    foreach ($ctx['messages'] as $m) {
        $d = abs(eh_ts($m['created_at']) - $ts);
        if ($d < $bd) { $bd = $d; $best = (int)$m['id']; }
    }
    return $best ? ['message_id' => $best] : [];
}

// ── Items ───────────────────────────────────────────────────────────────────
// One flat list of things that happened or will happen. Every item has
//   kind      delivery | inbound | nosend | scheduled | sending
//   ts        unix time (UTC)
//   type/meta what it was
//   ukey      who: lower(username), 'p:<digits>' or 'a:<address>'
//   name      the roster name as written
// Deliveries add channel, address, status, error, note, body, direction.

function eh_ukey(array $r): string {
    $u = strtolower(trim((string)($r['username'] ?? '')));
    if ($u !== '') return $u;
    if (!empty($r['phone_digits'])) return 'p:' . $r['phone_digits'];
    $p = strtolower(trim((string)($r['phone'] ?? '')));
    if (strpos($p, '@') !== false) return 'a:' . $p;
    $d = eh_digits($p);
    return $d !== '' ? 'p:' . $d : 'a:' . $p;
}

function eh_items(array $d): array {
    $ctx   = eh_context($d);
    $items = [];
    $logByKey = []; // ukey => [ts, ...] of log rows, for the nothing-sent check

    foreach ($d['log'] as $r) {
        $c  = eh_classify($r, $ctx);
        $ts = eh_ts($r['created_at']);
        $uk = eh_ukey($r);
        $logByKey[$uk][] = $ts;
        $items[] = [
            'kind'      => $r['direction'] === 'inbound' ? 'inbound' : 'delivery',
            'id'        => (int)$r['id'],
            'ts'        => $ts,
            'type'      => $c['type'],
            'meta'      => $c['meta'],
            'ukey'      => $uk,
            'name'      => (string)($r['username'] ?? ''),
            'channel'   => eh_channel($r['provider'] ?? null),
            'address'   => eh_address($r),
            'status'    => (string)$r['status'],
            'error'     => (string)($r['error'] ?? ''),
            'note'      => '',
            'body'      => (string)($r['body'] ?? ''),
            'direction' => (string)$r['direction'],
            'host_notice' => in_array($c['type'], EH_HOST_NOTICE_TYPES, true),
        ];
    }
    $items = eh_collapse_retries($items);

    // Queue rows: scheduled, sending now, dead after retries, and sent with nothing logged.
    $now = time();
    $groups = [];
    $deadNotes = [];
    foreach ($d['queue'] as $qr) {
        $type = (string)($qr['notify_type'] ?: 'invite');
        $meta = eh_payload_meta($qr);
        $uk   = strtolower((string)$qr['username']);
        $name = (string)$qr['username'];
        if (empty($qr['attempted_at'])) {
            if ((int)$qr['attempts'] >= 3) {
                // Dead. Its failed attempts, if any were logged, carry the note;
                // otherwise it is its own failed item (the send failed before logging:
                // a bad number, a provider not configured).
                $deadNotes[] = ['ukey' => $uk, 'type' => $type, 'name' => $name, 'meta' => $meta, 'ts' => eh_ts($qr['created_at']), 'attempts' => (int)$qr['attempts']];
                continue;
            }
            $sched = !empty($qr['scheduled_for']) ? eh_ts($qr['scheduled_for']) : 0;
            $future = $sched > $now;
            $gk = ($future ? 'scheduled' : 'sending') . '|' . $type . '|' . ($meta['offset'] ?? '') . '|' . ($meta['occ'] ?? '') . '|' . ($future ? $sched : '');
            if (!isset($groups[$gk])) {
                $groups[$gk] = ['kind' => $future ? 'scheduled' : 'sending', 'ts' => $future ? $sched : $now + 1,
                                'type' => $type, 'meta' => $meta, 'ukey' => '', 'name' => '', 'guests' => [], 'host_notice' => false];
            }
            $groups[$gk]['guests'][$uk] = $name;
            continue;
        }
        // Sent (claimed and not released): did anything get logged for this person?
        $ats = eh_ts($qr['attempted_at']);
        $hit = false;
        foreach ($logByKey[$uk] ?? [] as $lts) { if (abs($lts - $ats) <= EH_WINDOW) { $hit = true; break; } }
        if (!$hit) {
            $items[] = ['kind' => 'nosend', 'ts' => $ats, 'type' => $type, 'meta' => $meta, 'ukey' => $uk, 'name' => $name,
                        'channel' => '', 'address' => '', 'status' => 'none', 'error' => '', 'note' => '', 'body' => '', 'direction' => 'outbound',
                        'host_notice' => in_array($type, EH_HOST_NOTICE_TYPES, true)];
            $logByKey[$uk][] = $ats; // so the marker pass below does not add it again
        }
    }
    foreach ($groups as $g) $items[] = $g; // guests stays a map ukey => name

    // Dedup markers (30 days) for a dispatch nothing was logged for and whose queue
    // row is already pruned (7 days). sent_at is written with date() under the
    // site timezone, unlike every other table.
    try { $siteTz = new DateTimeZone(get_setting('timezone', 'UTC')); } catch (Throwable $e) { $siteTz = new DateTimeZone('UTC'); }
    foreach ($d['markers'] as $m) {
        [$type, $meta] = eh_split_tag((string)$m['notification_type']);
        $uk = strtolower((string)$m['user_identifier']);
        try { $ts = (new DateTime((string)$m['sent_at'], $siteTz))->getTimestamp(); } catch (Throwable $e) { continue; }
        $hit = false;
        foreach ($logByKey[$uk] ?? [] as $lts) { if (abs($lts - $ts) <= EH_WINDOW) { $hit = true; break; } }
        if ($hit) continue;
        if (!empty($m['occurrence_date'])) $meta['occ'] = (string)$m['occurrence_date'];
        $items[] = ['kind' => 'nosend', 'ts' => $ts, 'type' => $type, 'meta' => $meta, 'ukey' => $uk, 'name' => $uk,
                    'channel' => '', 'address' => '', 'status' => 'none', 'error' => '', 'note' => '', 'body' => '', 'direction' => 'outbound',
                    'host_notice' => in_array($type, EH_HOST_NOTICE_TYPES, true)];
        $logByKey[$uk][] = $ts;
    }

    // Dead queue rows: note the newest failed delivery of that kind for that person,
    // or stand in for one.
    foreach ($deadNotes as $dn) {
        $idx = null;
        foreach ($items as $i => $it) {
            if ($it['kind'] === 'delivery' && $it['ukey'] === $dn['ukey'] && $it['type'] === $dn['type'] && $it['status'] === 'failed') {
                if ($idx === null || $it['ts'] > $items[$idx]['ts']) $idx = $i;
            }
        }
        $note = 'Gave up after ' . $dn['attempts'] . ' attempts';
        if ($idx !== null) { $items[$idx]['note'] = $note; continue; }
        $items[] = ['kind' => 'delivery', 'id' => 0, 'ts' => $dn['ts'], 'type' => $dn['type'], 'meta' => $dn['meta'], 'ukey' => $dn['ukey'], 'name' => $dn['name'],
                    'channel' => '', 'address' => '', 'status' => 'failed', 'error' => 'Could not be sent', 'note' => $note, 'body' => '', 'direction' => 'outbound',
                    'host_notice' => in_array($dn['type'], EH_HOST_NOTICE_TYPES, true)];
    }

    // Names: the roster's spelling for anyone on it, by lower-cased key.
    $names = [];
    foreach ($d['invites'] as $i) $names[strtolower((string)$i['username'])] = (string)$i['username'];
    foreach ($items as &$it) {
        if ($it['ukey'] !== '' && isset($names[$it['ukey']])) $it['name'] = $names[$it['ukey']];
        elseif ($it['name'] === '' && $it['ukey'] !== '') $it['name'] = eh_key_label($it['ukey']);
    }
    unset($it);

    usort($items, static fn($a, $b) => [$a['ts'], $a['id'] ?? 0] <=> [$b['ts'], $b['id'] ?? 0]);
    return $items;
}

function eh_key_label(string $ukey): string {
    if (strncmp($ukey, 'p:', 2) === 0) return eh_fmt_phone(substr($ukey, 2));
    if (strncmp($ukey, 'a:', 2) === 0) return substr($ukey, 2);
    return $ukey;
}

/**
 * An email parked for retry logs 'queued' when it is parked and a fresh 'sent'
 * ("recovered from queue (attempt N)") or 'failed' row when the retry ends.
 * The pair reads as one delivery, at the time it was first tried.
 */
function eh_collapse_retries(array $items): array {
    $byKey = [];
    foreach ($items as $i => $it) {
        if ($it['kind'] !== 'delivery') continue;
        $byKey[$it['ukey'] . '|' . strtolower($it['address']) . '|' . $it['body']][] = $i;
    }
    $drop = [];
    foreach ($byKey as $idxs) {
        $open = null;
        foreach ($idxs as $i) {
            $it = $items[$i];
            if ($it['status'] === 'queued') { $open = $i; continue; }
            if ($open === null) continue;
            $recovered = $it['status'] === 'sent' && stripos($it['error'], 'recovered from queue') === 0;
            if ($recovered || $it['status'] === 'failed') {
                $items[$open]['status'] = $it['status'];
                $items[$open]['error']  = $recovered ? '' : $it['error'];
                $items[$open]['note']   = $recovered
                    ? 'Parked for retry at ' . eh_local_ts($items[$open]['ts'], 'g:i A') . ', ' . preg_replace('/^recovered from queue \(attempt (\d+)\)$/', 'recovered on attempt $1', $it['error'])
                    : 'Parked for retry, then gave up';
                $drop[$i] = true;
                $open = null;
            }
        }
    }
    foreach ($items as $i => $it) {
        if ($it['kind'] === 'delivery' && $it['status'] === 'queued' && !isset($drop[$i])) $items[$i]['note'] = 'Waiting to retry';
    }
    return array_values(array_filter($items, static fn($it, $i) => !isset($drop[$i]), ARRAY_FILTER_USE_BOTH));
}

// ── Labels ──────────────────────────────────────────────────────────────────

function eh_rsvp_label(?string $r): string {
    return $r === 'yes' ? 'Yes' : ($r === 'no' ? 'No' : ($r === 'maybe' ? 'Maybe' : ''));
}

/** What a kind of item is called; $n > 1 pluralises where English does. */
function eh_label(string $type, array $meta, int $n = 1, string $direction = 'outbound', string $name = ''): string {
    switch ($type) {
        case 'invite':        return $n === 1 ? 'Invitation' : 'Invitations';
        case 'rsvp_nudge':    return 'RSVP reminder';
        case 'reminder':      return 'Reminder' . (isset($meta['offset']) ? ' (' . _format_offset_label((int)$meta['offset']) . ' before)' : '');
        case 'cancel_event':  return 'Cancellation notice';
        case 'cancel_occurrence': return 'Cancellation notice (one date)';
        case 'event_updated': return 'Update notice';
        case 'online_table':  return 'Online table seat';
        case 'rsvp_to_creator':
            return 'Notice to hosts' . (!empty($meta['responder']) ? ': ' . $meta['responder'] . ' replied ' . strtoupper((string)($meta['rsvp'] ?? '')) : '');
        case 'event_message': return 'Message to guests' . (!empty($meta['subject']) ? ': ' . $meta['subject'] : '');
        case 'event_poll':    return 'Poll';
        case 'event_comment': return 'Comment notice';
        case 'waitlist_promoted':     return 'Seat opened up';
        case 'rsvp_deadline_demoted': return 'Moved to the waitlist';
        case 'poker_approved':        return 'Approved to play';
        case 'conversation':  return $direction === 'inbound' ? ('Text from ' . $name) : 'Reply from the host';
        case 'auto_reply':    return 'Automatic reply';
        case 'rsvp_reply':    return $name . ' replied ' . eh_rsvp_label($meta['rsvp'] ?? null);
        case 'inbound_other': return 'Text from ' . $name;
        case 'sms_reply':     return 'Text reply notice';
        default:              return 'Message';
    }
}

// ── Timeline ────────────────────────────────────────────────────────────────
// Outbound deliveries of one kind sent within EH_WINDOW of each other are one
// run ("Invitations to 16 guests: 10 texts, 6 emails"). Replies, conversation
// lines, automatic replies and the queue groups stay single. Newest first;
// what is still scheduled sorts above everything (its time is in the future).

function eh_run_key(array $it): ?string {
    if ($it['kind'] !== 'delivery' && $it['kind'] !== 'nosend') return null;
    if (in_array($it['type'], ['conversation', 'auto_reply'], true)) return null;
    return $it['type'] . '|' . ($it['meta']['message_id'] ?? '') . '|' . ($it['meta']['offset'] ?? '') . '|' . ($it['meta']['occ'] ?? '');
}

function eh_runs(array $items, array $d): array {
    $subjects = [];
    foreach ($d['messages'] as $m) $subjects[(int)$m['id']] = (string)$m['subject'];
    $entries = []; $open = [];
    foreach ($items as $it) {
        if (!empty($it['meta']['message_id']) && isset($subjects[$it['meta']['message_id']])) $it['meta']['subject'] = $subjects[$it['meta']['message_id']];
        $key = eh_run_key($it);
        if ($key === null) {
            $entries[] = eh_entry_from($it);
            continue;
        }
        if (isset($open[$key]) && $it['ts'] - $entries[$open[$key]]['ts_end'] <= EH_WINDOW) {
            eh_entry_add($entries[$open[$key]], $it);
        } else {
            $entries[] = eh_entry_from($it);
            $open[$key] = count($entries) - 1;
        }
    }
    usort($entries, static fn($a, $b) => [$b['ts'], $b['ts_end']] <=> [$a['ts'], $a['ts_end']]);
    return $entries;
}

function eh_entry_from(array $it): array {
    $e = ['kind' => $it['kind'] === 'delivery' || $it['kind'] === 'nosend' ? 'run' : $it['kind'],
          'type' => $it['type'], 'meta' => $it['meta'], 'ts' => $it['ts'], 'ts_end' => $it['ts'],
          'rows' => [], 'guests' => [], 'by_channel' => [], 'by_status' => [], 'failed' => false,
          'host_notice' => !empty($it['host_notice']), 'direction' => $it['direction'] ?? 'outbound', 'name' => $it['name'] ?? ''];
    if ($e['kind'] === 'scheduled' || $e['kind'] === 'sending') { $e['guests'] = $it['guests']; return $e; }
    eh_entry_add($e, $it);
    return $e;
}
function eh_entry_add(array &$e, array $it): void {
    $e['rows'][] = $it;
    $e['ts_end'] = max($e['ts_end'], $it['ts']);
    if ($it['ukey'] !== '') $e['guests'][$it['ukey']] = true;
    if ($it['kind'] === 'delivery' && $it['channel'] !== '') $e['by_channel'][$it['channel']] = ($e['by_channel'][$it['channel']] ?? 0) + 1;
    $e['by_status'][$it['status']] = ($e['by_status'][$it['status']] ?? 0) + 1;
    if ($it['status'] === 'failed') $e['failed'] = true;
    if (!empty($it['host_notice'])) $e['host_notice'] = true;
}

/** "Invitations to 16 guests", "Brad replied Yes", "Reminder (2 days before) to 34 guests". */
function eh_entry_title(array $e): string {
    $n = count($e['guests']);
    if ($e['kind'] === 'inbound') {
        $r = $e['rows'][0];
        return eh_label($r['type'], $r['meta'], 1, 'inbound', $r['name']);
    }
    if ($e['kind'] === 'scheduled' || $e['kind'] === 'sending') {
        return eh_label($e['type'], $e['meta'], 2) . ' to ' . $n . ($n === 1 ? ' guest' : ' guests');
    }
    $r = $e['rows'][0];
    if ($r['type'] === 'conversation' || $r['type'] === 'auto_reply') return eh_label($r['type'], $r['meta'], 1, $r['direction'], $r['name']) . ' to ' . $r['name'];
    if ($e['host_notice']) return eh_label($r['type'], $r['meta'], $n) . ' (' . $n . ($n === 1 ? ' host' : ' hosts') . ')';
    if ($n === 1) return eh_label($r['type'], $r['meta'], 1) . ' to ' . $r['name'];
    return eh_label($r['type'], $r['meta'], $n) . ' to ' . $n . ' guests';
}

/** The chips under a run's title: "10 texts · 6 emails · 1 failed · 2 nothing sent". */
function eh_entry_chips(array $e): array {
    $chips = [];
    foreach (['sms', 'email', 'whatsapp'] as $ch) {
        if (!empty($e['by_channel'][$ch])) $chips[] = ['cls' => 'ch ' . $ch, 'text' => $e['by_channel'][$ch] . ' ' . eh_channel_label($ch, $e['by_channel'][$ch])];
    }
    if (!empty($e['by_status']['failed'])) $chips[] = ['cls' => 'fail', 'text' => $e['by_status']['failed'] . ' failed'];
    if (!empty($e['by_status']['queued'])) $chips[] = ['cls' => 'wait', 'text' => $e['by_status']['queued'] . ' waiting to retry'];
    if (!empty($e['by_status']['none']))   $chips[] = ['cls' => 'none', 'text' => $e['by_status']['none'] . ' nothing sent'];
    return $chips;
}

// ── By guest ────────────────────────────────────────────────────────────────

function eh_threads(array $items, array $d): array {
    $threads = []; $order = [];
    $byDigits = []; $byEmail = [];
    foreach ($d['invites'] as $i) {
        $uk = strtolower((string)$i['username']);
        $threads[$uk] = ['key' => $uk, 'name' => (string)$i['username'], 'known' => $d['known'][$uk] ?? '', 'rsvp' => $i['rsvp'],
                         'role' => (string)($i['event_role'] ?? 'invitee'), 'approval' => (string)($i['approval_status'] ?? 'approved'),
                         'on_list' => true, 'is_host' => isset($d['hosts'][$uk]), 'rows' => [], 'aliases' => [], 'failed' => false, 'only_notices' => true];
        $order[] = $uk;
        $dg = eh_digits((string)($i['phone'] ?? ''));
        if (strlen($dg) === 10) $byDigits[$dg] = $uk;
        $em = strtolower(trim((string)($i['email'] ?? '')));
        if ($em !== '') $byEmail[$em] = $uk;
    }
    $subjects = [];
    foreach ($d['messages'] as $m) $subjects[(int)$m['id']] = (string)$m['subject'];

    $place = function (array $row, string $uk) use (&$threads, &$order, $d): void {
        if (!isset($threads[$uk])) {
            // Off the list now, or never on it: a removed guest, a number with no name.
            $dg = strncmp($uk, 'p:', 2) === 0 ? substr($uk, 2) : '';
            $threads[$uk] = ['key' => $uk, 'name' => $row['name'] !== '' ? $row['name'] : eh_key_label($uk), 'known' => '', 'rsvp' => null,
                             'role' => '', 'approval' => '', 'on_list' => false, 'is_host' => isset($d['hosts'][$uk]), 'rows' => [], 'aliases' => [], 'failed' => false, 'only_notices' => true];
            $order[] = $uk;
        }
        $threads[$uk]['rows'][] = $row;
        if (($row['status'] ?? '') === 'failed') $threads[$uk]['failed'] = true;
        if (empty($row['host_notice'])) $threads[$uk]['only_notices'] = false;
    };

    foreach ($items as $it) {
        if (!empty($it['meta']['message_id']) && isset($subjects[$it['meta']['message_id']])) $it['meta']['subject'] = $subjects[$it['meta']['message_id']];
        if ($it['kind'] === 'scheduled' || $it['kind'] === 'sending') {
            foreach ($it['guests'] as $uk => $name) {
                $row = $it; $row['guests'] = null; $row['ukey'] = $uk; $row['name'] = $name; $row['status'] = $it['kind'];
                $place($row, $uk);
            }
            continue;
        }
        $uk = $it['ukey'];
        if (!isset($threads[$uk])) {
            // A row under a name that is off the list but whose number or address is
            // on it: the same person under an earlier spelling.
            $target = null;
            if (strpos($it['address'], '@') !== false) $target = $byEmail[strtolower($it['address'])] ?? null;
            else { $dg = eh_digits($it['address']); if (strlen($dg) === 10) $target = $byDigits[$dg] ?? null; }
            if ($target !== null) {
                if ($it['name'] !== '' && strcasecmp($it['name'], $threads[$target]['name']) !== 0) {
                    $it['alias'] = $it['name'];
                    $threads[$target]['aliases'][$it['name']] = true;
                }
                $uk = $target;
            }
        }
        $place($it, $uk);
    }

    // Roster order, then people no longer on the list, then hosts whose thread is
    // nothing but notices to them.
    $out = [];
    foreach ($order as $uk) {
        $t = $threads[$uk];
        if (!$t['on_list'] && empty($t['rows'])) continue;
        $t['aliases'] = array_keys($t['aliases']);
        $t['host_only'] = $t['is_host'] && $t['only_notices'] && !empty($t['rows']);
        $out[] = $t;
    }
    usort($out, static function ($a, $b) {
        $ra = $a['host_only'] ? 2 : ($a['on_list'] ? 0 : 1);
        $rb = $b['host_only'] ? 2 : ($b['on_list'] ? 0 : 1);
        return $ra <=> $rb; // usort is stable in PHP 8: roster order survives within a rank
    });
    return $out;
}

// ── Rendering ───────────────────────────────────────────────────────────────

function eh_status_html(array $it): string {
    $st = (string)($it['status'] ?? '');
    switch ($st) {
        case 'sent':      return '<span class="eh-st ok">sent</span>';
        case 'received':  return '';
        case 'failed':    return '<span class="eh-st fail">failed</span>';
        case 'queued':    return '<span class="eh-st wait">waiting to retry</span>';
        case 'none':      return '<span class="eh-st none">nothing sent</span>';
        case 'scheduled': return '<span class="eh-st sched">scheduled</span>';
        case 'sending':   return '<span class="eh-st send">sending now</span>';
    }
    return '';
}

function eh_channel_chip(string $ch): string {
    if ($ch === '') return '';
    $t = $ch === 'email' ? 'Email' : ($ch === 'whatsapp' ? 'WhatsApp' : 'Text');
    return '<span class="eh-ch ' . eh_h($ch) . '">' . $t . '</span>';
}

/** One row inside a run or a thread. $withLabel names the kind (threads); runs already did. */
function eh_row_html(array $it, bool $withLabel, bool $withName): string {
    $h = '<li class="eh-row" data-status="' . eh_h($it['status'] ?? '') . '"' . (!empty($it['host_notice']) ? ' data-host="1"' : '') . '>';
    $h .= '<div class="eh-row-main">';
    if ($withName) $h .= '<span class="eh-name">' . eh_h($it['name']) . (!empty($it['alias']) ? ' <span class="eh-tag">sent as ' . eh_h($it['alias']) . '</span>' : '') . '</span>';
    if ($withLabel) {
        $h .= '<span class="eh-what">' . eh_h(eh_label($it['type'], $it['meta'], 1, $it['direction'] ?? 'outbound', $it['name'])) . '</span>';
        if (!empty($it['alias'])) $h .= '<span class="eh-tag">sent as ' . eh_h($it['alias']) . '</span>';
    }
    $h .= eh_status_html($it);
    $h .= '</div><div class="eh-row-sub">';
    if ($it['kind'] === 'inbound') {
        $h .= eh_channel_chip($it['channel']) . '<span class="eh-addr">from ' . eh_h($it['address']) . '</span>';
    } elseif ($it['kind'] === 'delivery') {
        $h .= eh_channel_chip($it['channel']) . ($it['address'] !== '' ? '<span class="eh-addr">' . eh_h($it['address']) . '</span>' : '');
    } elseif ($it['kind'] === 'scheduled') {
        $h .= '<span class="eh-addr">for ' . eh_h(eh_local_ts($it['ts'])) . '</span>';
    }
    if ($it['kind'] !== 'scheduled' && $it['kind'] !== 'sending') $h .= '<span class="eh-t">' . eh_h(eh_local_ts($it['ts'], 'D M j, g:i:s A')) . '</span>';
    $h .= '</div>';
    if ($it['kind'] === 'inbound' || (($it['type'] ?? '') === 'conversation')) {
        $h .= '<div class="eh-body">' . nl2br(eh_h($it['body'])) . '</div>';
    } elseif ($it['kind'] === 'delivery' && $it['channel'] === 'email' && $it['body'] !== '') {
        $h .= '<div class="eh-body eh-subject">Subject: ' . eh_h($it['body']) . '</div>';
    }
    if (!empty($it['error']) && $it['status'] === 'failed') $h .= '<div class="eh-err">' . eh_h($it['error']) . '</div>';
    if (!empty($it['note'])) $h .= '<div class="eh-note">' . eh_h($it['note']) . '</div>';
    if ($it['kind'] === 'nosend') $h .= '<div class="eh-note">No email or phone on file, or they turned this kind of notice off.</div>';
    return $h . '</li>';
}

/** A Timeline entry. */
function eh_entry_html(array $e): string {
    $host = $e['host_notice'] ? ' data-host="1"' : '';
    $failed = $e['failed'] ? '1' : '0';
    $title = eh_entry_title($e);
    if ($e['kind'] === 'inbound') {
        $r = $e['rows'][0];
        return '<div class="eh-entry eh-inbound" data-failed="0"' . $host . '>'
             . '<div class="eh-entry-head"><span class="eh-when">' . eh_h(eh_local_ts($e['ts'])) . '</span><span class="eh-title">' . eh_h($title) . '</span>'
             . eh_channel_chip($r['channel']) . '</div>'
             . '<div class="eh-body">' . nl2br(eh_h($r['body'])) . '</div></div>';
    }
    if ($e['kind'] === 'scheduled' || $e['kind'] === 'sending') {
        $when = $e['kind'] === 'scheduled' ? eh_local_ts($e['ts']) : 'Now';
        $h = '<details class="eh-run eh-' . $e['kind'] . '" data-failed="0">'
           . '<summary><span class="eh-when">' . eh_h($when) . '</span><span class="eh-title">' . eh_h($title) . '</span>'
           . '<span class="eh-chips">' . eh_status_html(['status' => $e['kind']]) . '</span></summary><ul class="eh-rows eh-names">';
        foreach ($e['guests'] as $name) $h .= '<li>' . eh_h($name) . '</li>';
        return $h . '</ul></details>';
    }
    if (count($e['rows']) === 1 && in_array($e['rows'][0]['type'], ['conversation', 'auto_reply'], true)) {
        $r = $e['rows'][0];
        return '<div class="eh-entry eh-outconv" data-failed="' . $failed . '"' . $host . '>'
             . '<div class="eh-entry-head"><span class="eh-when">' . eh_h(eh_local_ts($e['ts'])) . '</span><span class="eh-title">' . eh_h($title) . '</span>'
             . eh_channel_chip($r['channel']) . eh_status_html($r) . '</div>'
             . ($r['body'] !== '' ? '<div class="eh-body">' . nl2br(eh_h($r['body'])) . '</div>' : '')
             . (!empty($r['error']) && $r['status'] === 'failed' ? '<div class="eh-err">' . eh_h($r['error']) . '</div>' : '')
             . '</div>';
    }
    $h = '<details class="eh-run' . ($e['host_notice'] ? ' eh-host' : '') . '" data-failed="' . $failed . '"' . $host . '>'
       . '<summary><span class="eh-when">' . eh_h(eh_local_ts($e['ts'])) . '</span><span class="eh-title">' . eh_h($title) . '</span><span class="eh-chips">';
    foreach (eh_entry_chips($e) as $c) $h .= '<span class="eh-chip ' . eh_h($c['cls']) . '">' . eh_h($c['text']) . '</span>';
    $h .= '</span></summary><ul class="eh-rows">';
    foreach ($e['rows'] as $r) $h .= eh_row_html($r, false, true);
    return $h . '</ul></details>';
}

/** A By-guest thread. */
function eh_thread_html(array $t): string {
    $h = '<div class="eh-thread' . ($t['on_list'] ? '' : ' eh-gone') . '" data-failed="' . ($t['failed'] ? '1' : '0') . '"' . ($t['host_only'] ? ' data-host="1"' : '') . '>';
    $h .= '<div class="eh-thread-head"><span class="eh-thread-name">' . eh_h($t['name'])
        . ($t['known'] !== '' ? ' <span class="pk-known">(' . eh_h($t['known']) . ')</span>' : '') . '</span>';
    if ($t['role'] === 'manager') $h .= '<span class="eh-tag">co-host</span>';
    elseif ($t['is_host'] && !$t['on_list']) $h .= '<span class="eh-tag">host</span>';
    if (!$t['on_list'] && !$t['is_host']) $h .= '<span class="eh-tag">no longer on the list</span>';
    foreach ($t['aliases'] as $a) $h .= '<span class="eh-tag">also as ' . eh_h($a) . '</span>';
    if ($t['on_list']) {
        $r = $t['rsvp'];
        $h .= '<span class="eh-rsvp ' . ($r === 'yes' ? 'rsvp-yes' : ($r === 'no' ? 'rsvp-no' : ($r === 'maybe' ? 'rsvp-maybe' : 'rsvp-none'))) . '">'
            . ($r ? eh_rsvp_label($r) : 'No answer yet') . '</span>';
    }
    $h .= '</div>';
    if (empty($t['rows'])) {
        $h .= '<div class="eh-none">Nothing sent or received yet.</div>';
    } else {
        $h .= '<ul class="eh-rows">';
        foreach ($t['rows'] as $r) $h .= eh_row_html($r, true, false);
        $h .= '</ul>';
    }
    return $h . '</div>';
}
