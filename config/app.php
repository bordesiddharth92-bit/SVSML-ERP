<?php
/**
 * SVSML-ERP — Centralized application bootstrap
 *
 * This is the SINGLE entry point that every PHP page should require.
 * It defines BASE_URL (the public URL prefix the app is served from)
 * and the url() helper that all pages use to build links / redirects.
 *
 * Why this matters:
 *   The ERP is installed inside a subfolder on the production domain
 *   (https://seavoyageship.com/erp/), so absolute paths like "/login.php"
 *   would resolve to the domain root and 404. Every link, asset, and
 *   redirect must be built through url() instead.
 *
 * To install in a different folder, change BASE_URL below. That's it.
 *   - Subfolder /erp/   →  define('BASE_URL', '/erp/');
 *   - Subfolder /svsml/ →  define('BASE_URL', '/svsml/');
 *   - Domain root       →  define('BASE_URL', '/');
 *
 * BASE_URL must always start AND end with a forward slash.
 */

if (!defined('BASE_URL')) {
    define('BASE_URL', '/erp/');
}

/**
 * Build a URL relative to BASE_URL.
 *
 *   url()                          => "/erp/"
 *   url('login.php')               => "/erp/login.php"
 *   url('dashboard.php')           => "/erp/dashboard.php"
 *   url('assets/css/style.css')    => "/erp/assets/css/style.css"
 *   url('/login.php')              => "/erp/login.php"   (leading slash tolerated)
 */
function url(string $path = ''): string
{
    return BASE_URL . ltrim($path, '/');
}

/**
 * Convenience: echo an asset URL with HTML-escaping. Use in templates.
 *   <link rel="stylesheet" href="<?= asset('assets/css/style.css') ?>">
 */
function asset(string $path): string
{
    return htmlspecialchars(url($path), ENT_QUOTES, 'UTF-8');
}

// Continue bootstrapping the rest of the app.
require __DIR__ . '/config.php';
require __DIR__ . '/db.php';
