<?php
require __DIR__ . '/config/config.php';
require __DIR__ . '/includes/auth.php';

logoutCurrentUser();

// Restart a fresh session so we can show a flash on the login page.
session_start();
$_SESSION['flash'][] = ['type' => 'info', 'message' => 'You have been signed out.'];

header('Location: /login.php');
exit;
