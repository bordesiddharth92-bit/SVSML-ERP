<?php
/**
 * SVSML-ERP — Authentication & role guards
 *
 * Requires config/config.php to have been loaded first
 * (it starts the session).
 */

/** Returns the logged-in user array, or null. */
function currentUser(): ?array
{
    return $_SESSION['user'] ?? null;
}

/** True if a user is logged in at all. */
function isLoggedIn(): bool
{
    return !empty($_SESSION['user']);
}

/** Redirect to staff login if not logged in. */
function requireLogin(string $redirect = '/login.php'): void
{
    if (!isLoggedIn()) {
        header('Location: ' . $redirect);
        exit;
    }
}

/**
 * Require the logged-in user to have one of the given roles.
 * Roles: 'admin', 'sub_admin', 'staff', 'crew'
 */
function requireRole(array $roles): void
{
    requireLogin();
    $role = $_SESSION['user']['role'] ?? null;
    if (!in_array($role, $roles, true)) {
        http_response_code(403);
        exit('Forbidden — your role does not have access to this page.');
    }
}

/** True for admin or sub_admin. */
function isAdminLike(): bool
{
    $u = currentUser();
    return $u && in_array($u['role'], ['admin', 'sub_admin'], true);
}

/** True for admin / sub_admin / staff (i.e. any internal user). */
function isStaffLevel(): bool
{
    $u = currentUser();
    return $u && in_array($u['role'], ['admin', 'sub_admin', 'staff'], true);
}

/** True only for the crew role. */
function isCrew(): bool
{
    $u = currentUser();
    return $u && $u['role'] === 'crew';
}

/** Destroy the session completely. */
function logoutCurrentUser(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(
            session_name(), '', time() - 42000,
            $p['path'], $p['domain'], $p['secure'], $p['httponly']
        );
    }
    session_destroy();
}

/**
 * Attempt to log a staff/admin/sub_admin user in by email + password.
 * Returns the user array on success, or null.
 */
function attemptStaffLogin(PDO $pdo, string $email, string $password): ?array
{
    $stmt = $pdo->prepare(
        "SELECT id, full_name, email, password, role, is_active
           FROM users
          WHERE email = :email
            AND role IN ('admin','sub_admin','staff')
          LIMIT 1"
    );
    $stmt->execute([':email' => $email]);
    $u = $stmt->fetch();
    if (!$u || !$u['is_active']) return null;
    if (!password_verify($password, $u['password'])) return null;
    return $u;
}

/** Save the user to the session after a successful login. */
function loginUser(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user'] = [
        'id'        => (int)$user['id'],
        'full_name' => $user['full_name'],
        'email'     => $user['email'],
        'role'      => $user['role'],
    ];
}
