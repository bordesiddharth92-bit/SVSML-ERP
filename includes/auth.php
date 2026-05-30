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
function requireLogin(?string $redirect = null): void
{
    if (!isLoggedIn()) {
        // Default to the BASE_URL-aware login URL.
        header('Location: ' . ($redirect ?? url('login.php')));
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
    // Backwards-compat alias: an older deployed copy of any edit page
    // may still read $_SESSION['user_id'] directly. Mirroring it here
    // means foreign-key writes (created_by) keep working until the
    // updated PHP files reach production.
    $_SESSION['user_id'] = (int)$user['id'];
}

/**
 * Returns the staff/admin user id for the currently signed-in session,
 * or null when no staff user is logged in (including when a crew user
 * is signed in via the portal — they don't have a row in `users`).
 *
 * Use this in any code path that needs a `created_by` value: it gives
 * a single source of truth and avoids the historic
 * $_SESSION['user_id'] vs $_SESSION['user']['id'] confusion.
 */
function currentUserId(): ?int
{
    if (isset($_SESSION['user']['id']) && $_SESSION['user']['id'] !== null) {
        return (int)$_SESSION['user']['id'];
    }
    if (isset($_SESSION['user_id']) && $_SESSION['user_id'] !== null) {
        return (int)$_SESSION['user_id'];
    }
    return null;
}

/* =========================================================
   Module 16 — Crew self-service login
   ========================================================= */

/**
 * Attempt to log a crew member in by passport number + password.
 *
 * Lookup is keyed by passport_number. The candidate crew row must:
 *   - exist
 *   - have crew_access_enabled = 1   (admin must opt them in)
 *   - have a non-empty password_hash (admin must have set a password)
 *
 * The system_settings.crew_self_login_enabled master switch must also be on.
 *
 * Returns the crew row on success, or null. Uses password_verify() for
 * constant-time comparison against the bcrypt hash.
 */
function attemptCrewLogin(PDO $pdo, string $passportNumber, string $password): ?array
{
    if ($passportNumber === '' || $password === '') return null;

    // Master switch.
    $sys = $pdo->query("SELECT crew_self_login_enabled FROM system_settings ORDER BY id ASC LIMIT 1")->fetch();
    if ($sys && (int)$sys['crew_self_login_enabled'] !== 1) return null;

    $stmt = $pdo->prepare(
        "SELECT id, full_name, passport_number, crew_access_enabled, password_hash
           FROM crew
          WHERE passport_number = :p
          LIMIT 1"
    );
    $stmt->execute([':p' => $passportNumber]);
    $row = $stmt->fetch();
    if (!$row)                                   return null;
    if ((int)$row['crew_access_enabled'] !== 1)  return null;
    if (empty($row['password_hash']))            return null;
    if (!password_verify($password, $row['password_hash'])) return null;
    return $row;
}

/**
 * Persist a successful crew login into the session and timestamp it
 * on the crew row so admins can see when the crew last accessed the portal.
 *
 * The session shape mirrors loginUser() but adds crew_id and uses the
 * passport_number as the "email" placeholder so the topbar still has
 * something sensible to display.
 */
function loginCrew(PDO $pdo, array $crewRow): void
{
    session_regenerate_id(true);
    $_SESSION['user'] = [
        'id'        => null,                                  // crew aren't in users table
        'crew_id'   => (int)$crewRow['id'],
        'full_name' => $crewRow['full_name'],
        'email'     => $crewRow['passport_number'] ?? '',
        'role'      => 'crew',
    ];
    // Backwards-compat alias: keep $_SESSION['user_id'] null for crew
    // sessions (they have no users.id) so any code path that reads it
    // and writes to created_by either skips the column or stores NULL,
    // never accidentally an unrelated id.
    $_SESSION['user_id'] = null;
    try {
        $pdo->prepare("UPDATE crew SET last_login_at = CURRENT_TIMESTAMP WHERE id = :i")
            ->execute([':i' => (int)$crewRow['id']]);
    } catch (PDOException $e) {
        // last_login_at is best-effort; pre-migration schemas without the
        // column would otherwise block login. Log and continue.
        error_log('[SVSML-ERP] loginCrew last_login_at update failed: ' . $e->getMessage());
    }
}

/**
 * Returns the crew_id for the currently-logged-in crew user, or null.
 * Used by crew-portal*.php to scope queries to the signed-in crew.
 */
function currentCrewId(): ?int
{
    $u = currentUser();
    if (!$u || $u['role'] !== 'crew') return null;
    return isset($u['crew_id']) ? (int)$u['crew_id'] : null;
}

/** Redirect to crew-login if not signed in as crew. */
function requireCrew(): void
{
    if (!isLoggedIn() || ($_SESSION['user']['role'] ?? null) !== 'crew') {
        header('Location: ' . url('crew-login.php'));
        exit;
    }
}
