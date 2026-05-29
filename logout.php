<?php
require __DIR__ . '/config/app.php';
require __DIR__ . '/includes/auth.php';

// Remember role before we wipe the session so we can route the user back
// to the right login page (staff → /login.php, crew → /crew-login.php).
$wasCrew = isset($_SESSION['user']['role']) && $_SESSION['user']['role'] === 'crew';

logoutCurrentUser();

// Restart a fresh session so we can show a flash on the login page.
// Use the same scoped cookie path as the rest of the app.
$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => BASE_URL,
    'secure'   => $secure,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_name('SVSMLSESSID');
session_start();
$_SESSION['flash'][] = ['type' => 'info', 'message' => 'You have been signed out.'];

header('Location: ' . url($wasCrew ? 'crew-login.php' : 'login.php'));
exit;
