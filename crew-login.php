<?php
/**
 * Crew login — placeholder page for Module 1.
 *
 * Full crew login flow (passport-number username, first-login
 * password setup, admin toggle) lands in Module 16.
 */
require __DIR__ . '/config/config.php';
require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/helpers.php';

if (isLoggedIn()) {
    header('Location: /dashboard.php');
    exit;
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
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<div class="auth-wrap">
    <div class="auth-card">
        <h1><?= h(APP_SHORT) ?></h1>
        <p class="subtitle">Crew sign in</p>

        <div class="flash flash-info">
            Crew self-service login will be enabled in <strong>Module 16</strong>.
            Once activated, you will sign in here with your <strong>passport number</strong>.
        </div>

        <div class="auth-switch">
            Staff member? <a href="/login.php">Sign in here</a>
        </div>
    </div>
</div>
</body>
</html>
