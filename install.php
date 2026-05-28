<?php
/**
 * SVSML-ERP — One-time installer
 *
 * Purpose:
 *   1. Verify the database schema exists (spot-check a few tables).
 *   2. Create the first admin user.
 *   3. Write install.lock to disable itself.
 *
 * DELETE THIS FILE from the server after use.
 */

require __DIR__ . '/config/app.php';
require __DIR__ . '/includes/helpers.php';

// Block re-running
if (file_exists(__DIR__ . '/install.lock')) {
    exit('Installation already completed. Delete install.lock to re-run (not recommended).');
}

$errors  = [];
$success = false;

// POST — create admin
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name    = trim($_POST['full_name'] ?? '');
    $email   = trim($_POST['email'] ?? '');
    $pass    = (string)($_POST['password'] ?? '');
    $pass2   = (string)($_POST['password2'] ?? '');
    $contact = trim($_POST['contact_number'] ?? '');

    if ($name === '')  $errors[] = 'Full name is required.';
    if ($email === '') $errors[] = 'Email is required.';
    if (strlen($pass) < 6) $errors[] = 'Password must be at least 6 characters.';
    if ($pass !== $pass2) $errors[] = 'Passwords do not match.';

    // Verify schema exists (quick sanity check)
    try {
        $pdo->query("SELECT 1 FROM users LIMIT 0");
        $pdo->query("SELECT 1 FROM ranks LIMIT 0");
        $pdo->query("SELECT 1 FROM staff_activity LIMIT 0");
        $pdo->query("SELECT 1 FROM system_settings LIMIT 0");
    } catch (PDOException $e) {
        $errors[] = 'Schema check failed: ' . $e->getMessage() . '  — Did you import schema.sql and seed.sql?';
    }

    if (empty($errors)) {
        // Check duplicate email
        $chk = $pdo->prepare("SELECT id FROM users WHERE email = :e LIMIT 1");
        $chk->execute([':e' => $email]);
        if ($chk->fetch()) {
            $errors[] = 'A user with that email already exists.';
        }
    }

    if (empty($errors)) {
        $hash = password_hash($pass, PASSWORD_BCRYPT);
        $stmt = $pdo->prepare(
            "INSERT INTO users (full_name, email, password, role, contact_number, is_active, created_at, updated_at)
             VALUES (:name, :email, :pass, 'admin', :contact, 1, NOW(), NOW())"
        );
        $stmt->execute([
            ':name'    => $name,
            ':email'   => $email,
            ':pass'    => $hash,
            ':contact' => $contact ?: null,
        ]);

        // Write lock file
        file_put_contents(__DIR__ . '/install.lock', date('Y-m-d H:i:s') . ' — installed by ' . $email);

        $success = true;
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Install — <?= h(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= asset('assets/css/style.css') ?>">
</head>
<body>
<div class="auth-wrap">
    <div class="auth-card" style="max-width:440px;">
        <h1><?= h(APP_SHORT) ?> Installer</h1>

        <?php if ($success): ?>
            <div class="flash flash-success">
                Admin account created. <a href="<?= asset('login.php') ?>"><strong>Sign in now &rarr;</strong></a>
            </div>
            <p class="help-text">
                Please <strong>delete install.php</strong> from the server for security.
            </p>
        <?php else: ?>
            <p class="subtitle">Create the first admin user</p>

            <?php foreach ($errors as $e): ?>
                <div class="flash flash-error"><?= h($e) ?></div>
            <?php endforeach; ?>

            <p class="help-text">
                Before continuing, make sure you have already imported
                <code>database/schema.sql</code> and <code>database/seed.sql</code>
                into your MySQL database via phpMyAdmin (cPanel).
            </p>

            <form method="post" novalidate>
                <div class="form-row">
                    <label for="full_name">Full name</label>
                    <input type="text" id="full_name" name="full_name" required
                           value="<?= h($_POST['full_name'] ?? '') ?>">
                </div>
                <div class="form-row">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" required
                           value="<?= h($_POST['email'] ?? '') ?>">
                </div>
                <div class="form-row">
                    <label for="contact_number">Contact number <small>(optional)</small></label>
                    <input type="text" id="contact_number" name="contact_number"
                           value="<?= h($_POST['contact_number'] ?? '') ?>">
                </div>
                <div class="form-row">
                    <label for="password">Password (min 6 characters)</label>
                    <input type="password" id="password" name="password" required>
                </div>
                <div class="form-row">
                    <label for="password2">Confirm password</label>
                    <input type="password" id="password2" name="password2" required>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn">Create admin &amp; finish</button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
