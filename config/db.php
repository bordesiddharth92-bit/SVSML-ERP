<?php
/**
 * SVSML-ERP — Database connection (PDO MySQL)
 *
 * Edit the four DB_* constants below to match your cPanel
 * MySQL database. This file must NEVER be committed with real
 * production credentials.
 */

// ---- Edit these for your environment -------------------------
define('DB_HOST',    'localhost');
define('DB_NAME',    'svsml_erp');
define('DB_USER',    'root');
define('DB_PASS',    '');
define('DB_CHARSET', 'utf8mb4');
// --------------------------------------------------------------

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET,
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    // Show a generic message to users; full error goes to PHP error log.
    error_log('[SVSML-ERP] DB connection failed: ' . $e->getMessage());
    http_response_code(500);
    exit('Database connection failed. Please contact the administrator.');
}
