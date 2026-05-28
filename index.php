<?php
/** SVSML-ERP — entry point. Redirects based on session state. */
require __DIR__ . '/config/app.php';
require __DIR__ . '/includes/auth.php';

if (isLoggedIn()) {
    header('Location: ' . url('dashboard.php'));
} else {
    header('Location: ' . url('login.php'));
}
exit;
