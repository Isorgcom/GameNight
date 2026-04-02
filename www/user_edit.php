<?php
require_once __DIR__ . '/auth.php';

$current = require_login();
if ($current['role'] !== 'admin') {
    http_response_code(403);
    exit('Access denied.');
}

$db    = get_db();
$id    = (int)($_GET['id'] ?? 0);
$flash = ['type' => '', 'msg' => ''];

// Load target user
$stmt = $db->prepare('SELECT id, username, email, phone, role, preferred_contact, created_at, last_login FROM users WHERE id = ?');
$stmt->execute([$id]);
$target = $stmt->fetch();

if (!$target) {
    header('Location: /users.php');
    exit;
}

session_start_safe();
if (!empty($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => 'Invalid request token.'];
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'update_profile') {
            $username = trim($_POST['username'] ?? '');
            $email    = strtolower(trim($_POST['email'] ?? ''));
            $phone    = trim($_POST['phone'] ?? '');
            $phone    = $phone !== '' ? normalize_phone($phone) : '';
            $role     = in_array($_POST['role'] ?? '', ['admin', 'user']) ? $_POST['role'] : 'user';
            $pref_contact = in_array($_POST['preferred_contact'] ?? '', ['email', 'sms', 'both', 'none']) ? $_POST['preferred_contact'] : 'email';

            if ($username === '') {
                $flash = ['type' => 'error', 'msg' => 'Username cannot be empty.'];
            } elseif ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $flash = ['type' => 'error', 'msg' => 'A valid email address is required.'];
            } else {
                // Check email uniqueness (excluding this user)
                $chk = $db->prepare('SELECT id FROM users WHERE LOWER(email)=? AND id != ?');
                $chk->execute([$email, $id]);
                if ($chk->fetch()) {
                    $flash = ['type' => 'error', 'msg' => 'That email is already in use.'];
                    $_SESSION['flash'] = $flash;
                    header("Location: /user_edit.php?id=$id");
                    exit;
                }
                // Guard: cannot demote last admin
                if ($role !== 'admin' && $target['role'] === 'admin') {
                    $adminCount = (int)$db->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn();
                    if ($adminCount <= 1) {
                        $flash = ['type' => 'error', 'msg' => 'Cannot demote the last admin.'];
                        $_SESSION['flash'] = $flash;
                        header("Location: /user_edit.php?id=$id");
                        exit;
                    }
                }
                try {
                    $db->prepare('UPDATE users SET username=?, email=?, phone=?, role=?, preferred_contact=? WHERE id=?')
                       ->execute([$username, $email, $phone ?: null, $role, $pref_contact, $id]);
                    db_log_activity($current['id'], "admin updated profile for user id: $id");
                    $flash = ['type' => 'success', 'msg' => 'Profile updated.'];
                    // Reload target
                    $stmt->execute([$id]);
                    $target = $stmt->fetch();
                } catch (PDOException $e) {
                    $flash = ['type' => 'error', 'msg' => 'That username is already taken.'];
                }
            }
        }

        elseif ($action === 'delete') {
            $err = null;
            if ($id === (int)$current['id']) {
                $err = 'You cannot delete your own account.';
            } elseif ($target['role'] === 'admin') {
                $adminCount = (int)$db->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn();
                if ($adminCount <= 1) $err = 'Cannot delete the last admin.';
            }
            if ($err) {
                $flash = ['type' => 'error', 'msg' => $err];
            } else {
                $db->prepare('DELETE FROM activity_log WHERE user_id = ?')->execute([$id]);
                $db->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
                db_log_activity($current['id'], "admin deleted user: {$target['username']}");
                $_SESSION['flash'] = ['type' => 'success', 'msg' => "User \"{$target['username']}\" deleted."];
                header('Location: /users.php');
                exit;
            }
        }

        elseif ($action === 'update_password') {
            $new_pw     = $_POST['new_password'] ?? '';
            $confirm_pw = $_POST['confirm_password'] ?? '';

            if (strlen($new_pw) < 12) {
                $flash = ['type' => 'error', 'msg' => 'Password must be at least 12 characters.'];
            } elseif ($new_pw !== $confirm_pw) {
                $flash = ['type' => 'error', 'msg' => 'Passwords do not match.'];
            } else {
                $hash = password_hash($new_pw, PASSWORD_BCRYPT);
                $db->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([$hash, $id]);
                db_log_activity($current['id'], "admin reset password for user id: $id");
                $flash = ['type' => 'success', 'msg' => 'Password updated.'];
            }
        }
    }

    $_SESSION['flash'] = $flash;
    header("Location: /user_edit.php?id=$id");
    exit;
}

$token = csrf_token();
$sel   = fn($a, $b) => $a === $b ? 'selected' : '';
$site_name = get_setting('site_name', 'Game Night');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($site_name) ?></title>
    <link rel="stylesheet" href="/style.css">
    <style>
        .edit-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; }
        @media (max-width: 640px) { .edit-grid { grid-template-columns: 1fr; } }
        select.form-select {
            width: 100%; padding: .6rem .85rem;
            border: 1.5px solid #e2e8f0; border-radius: 7px;
            font-size: .95rem; background: #f8fafc;
        }
        select.form-select:focus { outline: none; border-color: #2563eb; background: #fff; }
    </style>
</head>
<body>

<?php $nav_active = 'site-settings'; require __DIR__ . '/_nav.php'; ?>

<div class="dash-wrap">

    <div class="dash-header">
        <div style="display:flex;align-items:center;gap:1rem;flex-wrap:wrap">
            <div>
                <h1>Edit User: <?= htmlspecialchars($target['username']) ?></h1>
                <p>
                    <a href="/admin_settings.php?tab=users">&larr; Back to Users</a>
                </p>
            </div>
        </div>
    </div>

    <?php if ($flash['msg']): ?>
        <div class="alert alert-<?= $flash['type'] === 'error' ? 'error' : 'success' ?>" style="margin-bottom:1.5rem">
            <?= htmlspecialchars($flash['msg']) ?>
        </div>
    <?php endif; ?>

    <div class="edit-grid">

        <!-- Profile + Role -->
        <div class="card" style="max-width:100%">
            <h2>Profile</h2>
            <p class="subtitle">Username, email, and role.</p>
            <form method="post" action="/user_edit.php?id=<?= $id ?>">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">
                <input type="hidden" name="action" value="update_profile">

                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username"
                           value="<?= htmlspecialchars($target['username']) ?>" required>
                </div>
                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email"
                           value="<?= htmlspecialchars($target['email'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label for="phone">Phone</label>
                    <input type="tel" id="phone" name="phone"
                           value="<?= htmlspecialchars($target['phone'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label for="role">Role</label>
                    <select id="role" name="role" class="form-select">
                        <option value="user"  <?= $sel($target['role'], 'user') ?>>User</option>
                        <option value="admin" <?= $sel($target['role'], 'admin') ?>>Admin</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="preferred_contact">Notification Preference</label>
                    <select id="preferred_contact" name="preferred_contact" class="form-select">
                        <option value="email" <?= $sel($target['preferred_contact'] ?? 'email', 'email') ?>>Email</option>
                        <option value="sms"   <?= $sel($target['preferred_contact'] ?? '', 'sms') ?>>SMS</option>
                        <option value="both"  <?= $sel($target['preferred_contact'] ?? '', 'both') ?>>Email &amp; SMS</option>
                        <option value="none"  <?= $sel($target['preferred_contact'] ?? '', 'none') ?>>None</option>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary" style="width:100%">Save Profile</button>
            </form>
        </div>

        <!-- Password reset (no current password required for admin) -->
        <div class="card" style="max-width:100%">
            <h2>Reset Password</h2>
            <p class="subtitle">Set a new password for this user.</p>
            <form method="post" action="/user_edit.php?id=<?= $id ?>">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">
                <input type="hidden" name="action" value="update_password">

                <div class="form-group">
                    <label for="new_password">New Password</label>
                    <input type="password" id="new_password" name="new_password"
                           autocomplete="new-password" required>
                </div>
                <div class="form-group">
                    <label for="confirm_password">Confirm Password</label>
                    <input type="password" id="confirm_password" name="confirm_password"
                           autocomplete="new-password" required>
                </div>
                <button type="submit" class="btn btn-primary" style="width:100%">Update Password</button>
            </form>
        </div>

    </div>

    <!-- Read-only account info -->
    <div class="table-card" style="margin-top:1.5rem;max-width:540px">
        <h3>Account Info</h3>
        <table>
            <tbody>
                <tr><td style="color:#64748b;width:140px">User ID</td><td><?= (int)$target['id'] ?></td></tr>
                <tr><td style="color:#64748b">Role</td>
                    <td><span class="badge badge-<?= $target['role'] === 'admin' ? 'admin' : 'user' ?>">
                        <?= htmlspecialchars($target['role']) ?></span></td></tr>
                <tr><td style="color:#64748b">Member since</td><td><?= htmlspecialchars($target['created_at']) ?></td></tr>
                <tr><td style="color:#64748b">Last login</td><td><?= htmlspecialchars($target['last_login'] ?? 'Never') ?></td></tr>
            </tbody>
        </table>
    </div>

    <!-- Danger zone -->
    <?php if ($id !== $current['id']): ?>
    <div style="margin-top:2rem;max-width:540px;border:1.5px solid #fca5a5;border-radius:10px;padding:1.25rem 1.5rem;background:#fff">
        <h3 style="color:#dc2626;margin-bottom:.4rem">Danger Zone</h3>
        <p style="font-size:.875rem;color:#64748b;margin-bottom:1rem">
            Permanently delete this account and all associated activity logs. This cannot be undone.
        </p>
        <form method="post" action="/user_edit.php?id=<?= $id ?>"
              onsubmit="return confirm('Permanently delete ' + <?= json_encode($target['username']) ?> + '? This cannot be undone.')">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">
            <input type="hidden" name="action" value="delete">
            <button type="submit" class="btn" style="background:#dc2626;color:#fff">
                Delete <?= htmlspecialchars($target['username']) ?>
            </button>
        </form>
    </div>
    <?php endif; ?>


</div>

<?php require __DIR__ . '/_footer.php'; ?>

</body>
</html>
