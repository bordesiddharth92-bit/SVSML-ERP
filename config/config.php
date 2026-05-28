<?php
/**
 * SVSML-ERP — App-wide configuration
 *
 * Loaded by config/app.php (which has already defined BASE_URL).
 * Pages should require config/app.php, not this file directly.
 */

// App identity
define('APP_NAME',  'SVSML ERP');
define('APP_SHORT', 'SVSML');

// Path constants
define('APP_ROOT',   dirname(__DIR__));                    // /projects/.../SVSML-ERP
define('UPLOAD_DIR', APP_ROOT . '/uploads');               // server filesystem path
// Public URL prefix for uploaded files. Always built from BASE_URL so it
// works correctly under any subfolder install.
if (!defined('UPLOAD_URL')) {
    define('UPLOAD_URL', rtrim(BASE_URL, '/') . '/uploads');
}

// Upload rules (per spec)
define('MAX_UPLOAD_BYTES',  10 * 1024 * 1024);             // 10 MB
define('ALLOWED_UPLOAD_EXT', ['pdf', 'jpg', 'jpeg', 'png', 'docx']);

// Date status thresholds (kept here so they match alert_settings defaults)
define('EXPIRY_YELLOW_DAYS', 30);
define('EXPIRY_RED_DAYS',    0);

// Pagination
define('PAGE_SIZE', 20);

// Timezone
date_default_timezone_set('Asia/Kolkata');

// Error display: off in production, log only.
ini_set('display_errors',         '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors',             '1');
error_reporting(E_ALL);

// Session: scope the cookie to BASE_URL so the SVSML session does not
// collide with any other application living on the same domain.
if (session_status() === PHP_SESSION_NONE) {
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
}
