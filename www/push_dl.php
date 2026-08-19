<?php
/**
 * Data endpoint for Web Push subscriptions (settings.php "Browser
 * notifications" card). POST-only, JSON out.
 *
 * Actions:
 *   status      → VAPID public key + how many devices this user has enabled
 *   subscribe   → store this browser's subscription (upsert by endpoint —
 *                 an endpoint is unique per browser profile, so if another
 *                 account on the same browser had it, ownership moves)
 *   unsubscribe → remove this browser's subscription
 *   test        → push a test notification to all of the user's devices
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/webpush.php';

header('Content-Type: application/json');
$current = current_user();
if (!$current) { http_response_code(401); echo json_encode(['ok' => false, 'error' => 'Not authenticated']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok' => false, 'error' => 'POST only']); exit; }
if (!csrf_verify()) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'CSRF token invalid']); exit; }

$db     = get_db();
$uid    = (int)$current['id'];
$action = $_POST['action'] ?? '';

switch ($action) {
    case 'status': {
        $keys = wp_vapid_keys();
        $cnt  = $db->prepare('SELECT COUNT(*) FROM push_subscriptions WHERE user_id = ?');
        $cnt->execute([$uid]);
        echo json_encode(['ok' => true, 'pubkey' => $keys['public'], 'devices' => (int)$cnt->fetchColumn()]);
        break;
    }

    case 'subscribe': {
        $endpoint = trim((string)($_POST['endpoint'] ?? ''));
        $p256dh   = trim((string)($_POST['p256dh'] ?? ''));
        $auth     = trim((string)($_POST['auth'] ?? ''));
        $label    = mb_substr(trim((string)($_POST['label'] ?? '')), 0, 80);
        if ($endpoint === '' || !preg_match('#^https://#', $endpoint) || $p256dh === '' || $auth === '') {
            echo json_encode(['ok' => false, 'error' => 'Invalid subscription']); break;
        }
        // Sanity-check the keys decode to the expected sizes before storing.
        if (strlen(wp_b64url_decode($p256dh)) !== 65 || strlen(wp_b64url_decode($auth)) !== 16) {
            echo json_encode(['ok' => false, 'error' => 'Invalid subscription keys']); break;
        }
        $db->prepare('INSERT INTO push_subscriptions (user_id, endpoint, p256dh, auth, label)
                      VALUES (?, ?, ?, ?, ?)
                      ON CONFLICT(endpoint) DO UPDATE SET
                        user_id = excluded.user_id, p256dh = excluded.p256dh,
                        auth = excluded.auth, label = excluded.label')
           ->execute([$uid, $endpoint, $p256dh, $auth, $label]);
        db_log_activity($uid, 'Enabled browser notifications' . ($label !== '' ? " ($label)" : ''));
        echo json_encode(['ok' => true]);
        break;
    }

    case 'unsubscribe': {
        $endpoint = trim((string)($_POST['endpoint'] ?? ''));
        if ($endpoint === '') { echo json_encode(['ok' => false, 'error' => 'Missing endpoint']); break; }
        // Possession of the endpoint means the request comes from that
        // browser, so delete regardless of which account stored it.
        $db->prepare('DELETE FROM push_subscriptions WHERE endpoint = ?')->execute([$endpoint]);
        echo json_encode(['ok' => true]);
        break;
    }

    case 'test': {
        $cnt = $db->prepare('SELECT COUNT(*) FROM push_subscriptions WHERE user_id = ?');
        $cnt->execute([$uid]);
        $n = (int)$cnt->fetchColumn();
        if ($n === 0) { echo json_encode(['ok' => false, 'error' => 'No devices enabled']); break; }
        webpush_notify_user($db, $uid, 'Test notification',
            'Browser notifications are working on this account. 🎉', '/settings.php');
        echo json_encode(['ok' => true, 'devices' => $n]);
        break;
    }

    case 'dismiss_prompt': {
        // "No thanks" on the footer opt-in card: permanent, server-side.
        // Enabling later from settings still works; this only stops the ask.
        $db->prepare('UPDATE users SET push_prompt_dismissed = 1 WHERE id = ?')->execute([$uid]);
        echo json_encode(['ok' => true]);
        break;
    }

    default:
        echo json_encode(['ok' => false, 'error' => 'Unknown action']);
}
