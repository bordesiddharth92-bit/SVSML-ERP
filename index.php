<?php
/** SVSML-ERP — entry point. Redirects based on session state. */
require __DIR__ . '/config/config.php';
require __DIR__ . '/includes/auth.php';

if (isLoggedIn()) {
    header('Location: /dashboard.php');
} else {
    header('Location: /login.php');
}
exit;
