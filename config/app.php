<?php
/**
 * SVSML-ERP — Centralized application bootstrap
 *
 * This is the SINGLE entry point that every PHP page should require.
 * It defines BASE_URL (the public URL prefix the app is served from)
 * and the url() helper that all pages use to build links / redirects.
 *
 * The ERP is currently deployed on a dedicated subdomain
 *   https://erp.seavoyageship.com/
 * so the application lives at the document root of that subdomain
 * and BASE_URL is "/".
 *
 * To redeploy in a different location, change BASE_URL below. That's it.
 *   - Subdomain root    →  define('BASE_URL', '/');         (current)
 *   - Domain root       →  define('BASE_URL', '/');
 *   - Subfolder /erp/   →  define('BASE_URL', '/erp/');
 *   - Subfolder /svsml/ →  define('BASE_URL', '/svsml/');
 *
 * BASE_URL must always start AND end with a forward slash.
 */

if (!defined('BASE_URL')) {
    define('BASE_URL', '/');
}

/**
 * Build a URL relative to BASE_URL.
 *
 * With BASE_URL = '/' (subdomain root):
 *   url()                          => "/"
 *   url('login.php')               => "/login.php"
 *   url('dashboard.php')           => "/dashboard.php"
 *   url('assets/css/style.css')    => "/assets/css/style.css"
 *   url('/login.php')              => "/login.php"   (leading slash tolerated)
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
