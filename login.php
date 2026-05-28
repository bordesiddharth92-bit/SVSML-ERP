<?php
/** Staff / Admin / Sub-Admin login. */
require __DIR__ . '/config/app.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/helpers.php';

if (isLoggedIn()) {
    header('Location: ' . url('dashboard.php'));
    exit;
}

$err   = null;
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $pass  = (string)($_POST['password'] ?? '');

    if ($email === '' || $pass === '') {
        $err = 'Please enter your email and password.';
    } else {
        $u = attemptStaffLogin($pdo, $email, $pass);
        if ($u) {
            loginUser($u);
            flash('success', 'Welcome back, ' . $u['full_name'] . '.');
            header('Location: ' . url('dashboard.php'));
            exit;
        }
        $err = 'Invalid email or password.';
    }
}

$pageTitle = 'Sign in';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Sign in &mdash; <?= h(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= asset('assets/css/style.css') ?>">
</head>
<body>
<div class="auth-wrap">
    <div class="auth-card">
        <h1><?= h(APP_SHORT) ?></h1>
        <p class="subtitle">Staff sign in</p>

        <?php if ($err): ?>
            <div class="flash flash-error"><?= h($err) ?></div>
        <?php endif; ?>

        <?php foreach (getFlashes() as $f): ?>
            <div class="flash flash-<?= h($f['type']) ?>"><?= h($f['message']) ?></div>
        <?php endforeach; ?>

        <form method="post" action="<?= asset('login.php') ?>" novalidate>
            <div class="form-row">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" autocomplete="username" required value="<?= h($email) ?>">
            </div>
            <div class="form-row">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" autocomplete="current-password" required>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn">Sign in</button>
            </div>
        </form>

        <div class="auth-switch">
            Crew member? <a href="<?= asset('crew-login.php') ?>">Sign in here</a>
        </div>
    </div>
</div>
</body>
</html>
