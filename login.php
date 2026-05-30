<?php
/** Staff / Admin / Sub-Admin login. Premium maritime split layout. */
require __DIR__ . '/config/app.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/helpers.php';

if (isLoggedIn()) {
    $role = $_SESSION['user']['role'] ?? '';
    header('Location: ' . url($role === 'crew' ? 'crew-portal.php' : 'dashboard.php'));
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
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="<?= asset('assets/css/style.css') ?>">
</head>
<body>
<div class="auth-wrap">
    <aside class="auth-art">
        <div class="auth-art-brand">
            <div class="brand-logo"><?= h(APP_SHORT) ?></div>
            <div class="auth-art-brand-text">
                <div class="top"><?= h(APP_SHORT) ?></div>
                <div class="sub">Sea Voyage Ship Management LLP</div>
            </div>
        </div>

        <div class="auth-art-tagline">
            <h2>Crewing made <span class="accent">simple</span>.</h2>
            <p>
                A purpose-built ERP for RPSL manning agencies — manage crew,
                vessels, contracts, travel and approvals in one place. Built
                for the way real maritime teams work.
            </p>
        </div>

        <div class="auth-art-foot">
            <span>RPSL Manning Agency</span>
            <span>&bull;</span>
            <span>Established 2024</span>
        </div>
    </aside>

    <section class="auth-form-side">
        <div class="auth-card">
            <h1>Welcome back</h1>
            <p class="subtitle">Sign in to your SVSML staff account</p>

            <?php if ($err): ?>
                <div class="flash flash-error"><?= h($err) ?></div>
            <?php endif; ?>

            <?php foreach (getFlashes() as $f): ?>
                <div class="flash flash-<?= h($f['type']) ?>"><?= h($f['message']) ?></div>
            <?php endforeach; ?>

            <form method="post" action="<?= asset('login.php') ?>" novalidate>
                <div class="form-row">
                    <label for="email">Email address</label>
                    <input type="email" id="email" name="email"
                           autocomplete="username" required value="<?= h($email) ?>"
                           placeholder="you@svsml.com">
                </div>
                <div class="form-row">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password"
                           autocomplete="current-password" required>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn">Sign in</button>
                </div>
            </form>

            <div class="auth-switch">
                Crew member? <a href="<?= asset('crew-login.php') ?>">Sign in to the crew portal</a>
            </div>
        </div>
    </section>
</div>
</body>
</html>
