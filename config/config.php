<?php
/**
 * SVSML-ERP — App-wide configuration
 */

// App identity
define('APP_NAME',  'SVSML ERP');
define('APP_SHORT', 'SVSML');

// Path constants
define('APP_ROOT',   dirname(__DIR__));                    // /projects/.../SVSML-ERP
define('UPLOAD_DIR', APP_ROOT . '/uploads');
define('UPLOAD_URL', '/uploads');                          // public URL prefix

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

// Session: secure-ish defaults
if (session_status() === PHP_SESSION_NONE) {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('SVSMLSESSID');
    session_start();
}
