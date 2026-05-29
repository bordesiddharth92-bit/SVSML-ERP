<?php
/**
 * SVSML-ERP — One-shot password reset utility
 *
 * EMERGENCY USE ONLY. Upload to public_html, hit it once in your
 * browser, reset the password, then DELETE THIS FILE.
 *
 * The page is intentionally self-contained — it uses config/db.php to
 * connect but does NOT require an existing session or any other state.
 *
 * What it does:
 *   - lists every user with role admin / sub_admin / staff
 *   - lets you pick one and set a new password
 *   - sets is_active = 1 in case the row was previously disabled
 *   - if there are NO admin users (fresh install), lets you create one
 *
 * SECURITY:
 *   - the page refuses to run when reset-password.lock exists,
 *     so it works once and then blocks itself
 *   - delete the file immediately after use
 */

// Hard-bootstrap (don't pull in auth.php — we don't want session checks).
require __DIR__ . '/config/app.php';

// One-shot guard — once you've used it successfully, this file becomes inert.
$lockFile = __DIR__ . '/reset-password.lock';
if (file_exists($lockFile)) {
    http_response_code(403);
    exit(
        'reset-password.php has already been used and is now disabled. '
      . 'Delete reset-password.lock AND reset-password.php from the server.'
    );
}

$errors  = [];
$success = false;
$created = false;

function rp_h($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

// Load existing internal users so the operator can pick one.
$users = [];
try {
    $stmt = $pdo->query(
        "SELECT id, full_name, email, role, is_active
           FROM users
          WHERE role IN ('admin', 'sub_admin', 'staff')
          ORDER BY role, email"
    );
    $users = $stmt->fetchAll() ?: [];
} catch (PDOException $e) {
    $errors[] = 'Database error reading users: ' . $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($errors)) {
    $action = $_POST['action'] ?? 'reset';

    if ($action === 'reset') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $pass   = (string)($_POST['password']  ?? '');
        $pass2  = (string)($_POST['password2'] ?? '');

        if ($userId <= 0)         $errors[] = 'Pick a user.';
        if (strlen($pass) < 6)    $errors[] = 'Password must be at least 6 characters.';
        if ($pass !== $pass2)     $errors[] = 'Passwords do not match.';

        if (empty($errors)) {
            $hash = password_hash($pass, PASSWORD_BCRYPT);
            $upd  = $pdo->prepare(
                "UPDATE users
                    SET password   = :p,
                        is_active  = 1,
                        updated_at = CURRENT_TIMESTAMP
                  WHERE id = :i AND role IN ('admin','sub_admin','staff')"
            );
            $upd->execute([':p' => $hash, ':i' => $userId]);
            if ($upd->rowCount() > 0) {
                file_put_contents($lockFile, date('Y-m-d H:i:s') . " - reset user_id={$userId}");
                $success = true;
            } else {
                $errors[] = 'No staff/admin user with that id was found.';
            }
        }
    }
    elseif ($action === 'create_admin') {
        $name  = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email']     ?? '');
        $pass  = (string)($_POST['password']  ?? '');
        $pass2 = (string)($_POST['password2'] ?? '');

        if ($name === '')       $errors[] = 'Full name is required.';
        if ($email === '')      $errors[] = 'Email is required.';
        if (strlen($pass) < 6)  $errors[] = 'Password must be at least 6 characters.';
        if ($pass !== $pass2)   $errors[] = 'Passwords do not match.';

        if (empty($errors)) {
            $chk = $pdo->prepare("SELECT id FROM users WHERE email = :e LIMIT 1");
            $chk->execute([':e' => $email]);
            if ($chk->fetch()) {
                $errors[] = 'A user with that email already exists — use the reset form above.';
            }
        }

        if (empty($errors)) {
            $hash = password_hash($pass, PASSWORD_BCRYPT);
            $stmt = $pdo->prepare(
                "INSERT INTO users (full_name, email, password, role, is_active, created_at, updated_at)
                 VALUES (:n, :e, :p, 'admin', 1, NOW(), NOW())"
            );
            $stmt->execute([':n' => $name, ':e' => $email, ':p' => $hash]);
            file_put_contents($lockFile, date('Y-m-d H:i:s') . " - created admin {$email}");
            $created = true;
        }
    }
}

// Re-fetch users for the post-action display.
if ($success || $created) {
    try {
        $stmt = $pdo->query(
            "SELECT id, full_name, email, role, is_active
               FROM users
              WHERE role IN ('admin','sub_admin','staff')
              ORDER BY role, email"
        );
        $users = $stmt->fetchAll() ?: [];
    } catch (PDOException $e) { /* ignore */ }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Password reset — SVSML ERP</title>
    <link rel="stylesheet" href="<?= asset('assets/css/style.css') ?>">
</head>
<body>
<div class="auth-wrap">
    <div class="auth-card" style="max-width:600px;">
        <h1>SVSML ERP</h1>
        <p class="subtitle">Emergency password reset</p>

        <div class="flash flash-warning">
            <strong>Delete this file from the server</strong> as soon as you finish.
            After a successful submission, <code>reset-password.lock</code> is written
            and this page will refuse to run again until both files are removed.
        </div>

        <?php foreach ($errors as $e): ?>
            <div class="flash flash-error"><?= rp_h($e) ?></div>
        <?php endforeach; ?>

        <?php if ($success): ?>
            <div class="flash flash-success">
                Password updated. <a href="<?= asset('login.php') ?>"><strong>Sign in now &rarr;</strong></a>
                <br><small>Now delete <code>reset-password.php</code> AND <code>reset-password.lock</code> from the server.</small>
            </div>
        <?php elseif ($created): ?>
            <div class="flash flash-success">
                Admin user created. <a href="<?= asset('login.php') ?>"><strong>Sign in now &rarr;</strong></a>
                <br><small>Now delete <code>reset-password.php</code> AND <code>reset-password.lock</code> from the server.</small>
            </div>
        <?php else: ?>

            <h3 style="margin-top:18px;">Reset an existing user's password</h3>
            <?php if (empty($users)): ?>
                <p class="help-text">No admin / sub_admin / staff users found in the database. Use the "Create new admin" form below.</p>
            <?php else: ?>
                <form method="post" novalidate>
                    <input type="hidden" name="action" value="reset">
                    <div class="form-row">
                        <label for="user_id">User</label>
                        <select id="user_id" name="user_id" required>
                            <option value="">— Select a user —</option>
                            <?php foreach ($users as $u): ?>
                                <option value="<?= (int)$u['id'] ?>">
                                    <?= rp_h($u['full_name']) ?>
                                    &lt;<?= rp_h($u['email']) ?>&gt;
                                    [<?= rp_h($u['role']) ?>]
                                    <?= !$u['is_active'] ? ' (INACTIVE — will be reactivated)' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-row">
                        <label for="password">New password (min 6 chars)</label>
                        <input type="password" id="password" name="password" autocomplete="new-password" required minlength="6">
                    </div>
                    <div class="form-row">
                        <label for="password2">Confirm new password</label>
                        <input type="password" id="password2" name="password2" autocomplete="new-password" required minlength="6">
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn">Reset password</button>
                    </div>
                </form>
            <?php endif; ?>

            <hr style="margin:22px 0;">

            <h3>Or create a brand-new admin user</h3>
            <p class="help-text">
                Use this when there are no admin users at all (fresh install or accidentally-deleted row).
            </p>
            <form method="post" novalidate>
                <input type="hidden" name="action" value="create_admin">
                <div class="form-row">
                    <label for="ca_full_name">Full name</label>
                    <input type="text" id="ca_full_name" name="full_name" required>
                </div>
                <div class="form-row">
                    <label for="ca_email">Email</label>
                    <input type="email" id="ca_email" name="email" autocomplete="username" required>
                </div>
                <div class="form-row">
                    <label for="ca_password">Password (min 6 chars)</label>
                    <input type="password" id="ca_password" name="password" autocomplete="new-password" required minlength="6">
                </div>
                <div class="form-row">
                    <label for="ca_password2">Confirm password</label>
                    <input type="password" id="ca_password2" name="password2" autocomplete="new-password" required minlength="6">
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn">Create admin user</button>
                </div>
            </form>
        <?php endif; ?>

    </div>
</div>
</body>
</html>
