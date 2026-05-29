<?php
/** SVSML-ERP — entry point. Redirects based on session state. */
require __DIR__ . '/config/app.php';
require __DIR__ . '/includes/auth.php';

if (isLoggedIn()) {
    $role = $_SESSION['user']['role'] ?? '';
    // Crew users have their own self-service portal (Module 16).
    header('Location: ' . url($role === 'crew' ? 'crew-portal.php' : 'dashboard.php'));
} else {
    header('Location: ' . url('login.php'));
}
exit;
