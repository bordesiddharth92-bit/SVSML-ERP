<?php
/**
 * SVSML-ERP — Crew sign in
 *
 * Module 16.
 *
 * Crew members sign in with their passport number + password.
 * The password is set/reset by an admin from the crew profile.
 *
 * Login is gated by:
 *   - system_settings.crew_self_login_enabled  (master switch)
 *   - crew.crew_access_enabled                 (per-crew opt-in)
 *   - crew.password_hash                       (must be set)
 *
 * Failures all return the same generic error so that an attacker can't
 * enumerate which passport numbers exist in the system.
 */

require __DIR__ . '/config/app.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/helpers.php';

if (isLoggedIn()) {
    // If they're already crew, send them to the portal. Otherwise dashboard.
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
    <link rel="stylesheet" href="<?= asset('assets/css/style.css') ?>">
</head>
<body>
<div class="auth-wrap">
    <div class="auth-card">
        <h1><?= h(APP_SHORT) ?></h1>
        <p class="subtitle">Crew sign in</p>

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
                           autocomplete="username" required value="<?= h($passport) ?>">
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
                First time here? Your password is set by SVSML — please contact your
                manning agent if you don't have one yet.
            </p>
        <?php endif; ?>

        <div class="auth-switch">
            Staff member? <a href="<?= asset('login.php') ?>">Sign in here</a>
        </div>
    </div>
</div>
</body>
</html>
