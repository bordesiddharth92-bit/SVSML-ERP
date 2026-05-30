<?php
/**
 * SVSML-ERP — Crew sign in
 *
 * Module 16. Premium maritime split layout, mirrors login.php in
 * style but takes passport number + password and gates on the
 * master switch + per-crew access flag.
 */

require __DIR__ . '/config/app.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/helpers.php';

if (isLoggedIn()) {
    $role = $_SESSION['user']['role'] ?? '';
    header('Location: ' . url($role === 'crew' ? 'crew-portal.php' : 'dashboard.php'));
    exit;
}

// Master switch: if disabled, show a maintenance message instead of the form.
$sys = $pdo->query("SELECT crew_self_login_enabled FROM system_settings ORDER BY id ASC LIMIT 1")->fetch();
$loginEnabled = $sys ? (int)$sys['crew_self_login_enabled'] === 1 : true;

$err      = null;
$passport = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $loginEnabled) {
    $passport = trim($_POST['passport_number'] ?? '');
    $pass     = (string)($_POST['password'] ?? '');

    if ($passport === '' || $pass === '') {
        $err = 'Please enter your passport number and password.';
    } else {
        $row = attemptCrewLogin($pdo, $passport, $pass);
        if ($row) {
            loginCrew($pdo, $row);
            flash('success', 'Welcome, ' . $row['full_name'] . '.');
            header('Location: ' . url('crew-portal.php'));
            exit;
        }
        // Generic error — do not reveal whether passport exists.
        $err = 'Invalid passport number or password, or your portal access is not enabled.';
    }
}

$pageTitle = 'Crew sign in';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Crew sign in &mdash; <?= h(APP_NAME) ?></title>
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
                <div class="sub">Crew self-service portal</div>
            </div>
        </div>

        <div class="auth-art-tagline">
            <h2>Your career, <span class="accent">one tap away</span>.</h2>
            <p>
                Documents, certificates, travel, contracts and approvals —
                all in one place. Sign in with your passport number to view
                everything SVSML has on file for you.
            </p>
        </div>

        <div class="auth-art-foot">
            <span>Read-only self-service</span>
            <span>&bull;</span>
            <span>RPSL Manning Agency</span>
        </div>
    </aside>

    <section class="auth-form-side">
        <div class="auth-card">
            <h1>Welcome aboard</h1>
            <p class="subtitle">Sign in with your passport number</p>

            <?php if (!$loginEnabled): ?>
                <div class="flash flash-warning">
                    Crew self-service login is currently disabled by the administrator.
                    Please contact SVSML for assistance.
                </div>
            <?php else: ?>
                <?php if ($err): ?>
                    <div class="flash flash-error"><?= h($err) ?></div>
                <?php endif; ?>

                <?php foreach (getFlashes() as $f): ?>
                    <div class="flash flash-<?= h($f['type']) ?>"><?= h($f['message']) ?></div>
                <?php endforeach; ?>

                <form method="post" action="<?= asset('crew-login.php') ?>" novalidate>
                    <div class="form-row">
                        <label for="passport_number">Passport number</label>
                        <input type="text" id="passport_number" name="passport_number"
                               autocomplete="username" required value="<?= h($passport) ?>"
                               placeholder="e.g. N1234567">
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

                <p class="help-text" style="margin-top:14px;">
                    First time here? Your password is set by SVSML &mdash; please
                    contact your manning agent if you don't have one yet.
                </p>
            <?php endif; ?>

            <div class="auth-switch">
                Staff member? <a href="<?= asset('login.php') ?>">Sign in to the staff portal</a>
            </div>
        </div>
    </section>
</div>
</body>
</html>
