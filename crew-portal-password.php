<?php
/**
 * SVSML-ERP — Crew Portal: Change my password
 *
 * Module 16.
 *
 * The only writable page in the crew portal: lets the signed-in crew
 * change their own password. Verifies the current password (via
 * password_verify against crew.password_hash) before letting the new
 * one through. Activity is logged so an admin can audit.
 *
 * If CHANGES.sql hasn't been applied yet (so the password_hash column
 * is missing) we render a friendly notice instead of crashing.
 */

require __DIR__ . '/config/app.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/helpers.php';

requireCrew();
$crewId = currentCrewId();
$crew   = $crewId ? fetchCrewWithJoins($pdo, $crewId) : null;
if (!$crew) { logoutCurrentUser(); header('Location: ' . url('crew-login.php')); exit; }

$latestContract = fetchLatestContractForCrew($pdo, $crewId);

// Schema probe: degrade gracefully if CHANGES.sql hasn't been run.
$hasPasswordCol = false;
try {
    $col = $pdo->query("SHOW COLUMNS FROM crew LIKE 'password_hash'")->fetch();
    $hasPasswordCol = (bool)$col;
} catch (PDOException $e) { /* ignore */ }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $hasPasswordCol) {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'change_password') {
        $current = (string)($_POST['current_password'] ?? '');
        $new     = (string)($_POST['new_password']     ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');

        $errors = [];
        if ($current === '' || $new === '' || $confirm === '') {
            $errors[] = 'All password fields are required.';
        }
        if ($new !== $confirm)   $errors[] = 'New password and confirmation do not match.';
        if (mb_strlen($new) < 8) $errors[] = 'New password must be at least 8 characters.';

        if (empty($errors)) {
            $row = $pdo->prepare("SELECT password_hash FROM crew WHERE id = :i");
            $row->execute([':i' => $crewId]);
            $hash = $row->fetchColumn();
            if (!$hash || !password_verify($current, $hash)) {
                $errors[] = 'Current password is incorrect.';
            }
        }

        if (!empty($errors)) {
            foreach ($errors as $e) flash('error', $e);
        } else {
            $newHash = password_hash($new, PASSWORD_BCRYPT);
            $pdo->prepare(
                "UPDATE crew SET password_hash = :h, password_set_at = CURRENT_TIMESTAMP WHERE id = :i"
            )->execute([':h' => $newHash, ':i' => $crewId]);
            // user_id is null because crew aren't in users; module name records the actor.
            logActivity(
                $pdo, null, 'update', 'crew_password', $crewId,
                "Crew {$crewId} changed own password"
            );
            flash('success', 'Password changed.');
        }

        header('Location: ' . url('crew-portal-password.php'));
        exit;
    }
}

// Read the audit timestamps for the friendly status line.
$passwordSetAt = null;
$lastLoginAt   = null;
if ($hasPasswordCol) {
    $st = $pdo->prepare("SELECT password_set_at, last_login_at FROM crew WHERE id = :i");
    $st->execute([':i' => $crewId]);
    $info = $st->fetch();
    $passwordSetAt = $info['password_set_at'] ?? null;
    $lastLoginAt   = $info['last_login_at']   ?? null;
}

$pageTitle  = 'My Profile — Password';
$currentTab = 'password';
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/crew-portal-hero.php';
include __DIR__ . '/includes/crew-portal-tabs.php';
?>

<div class="card">
    <h3 class="card-title">Change my password</h3>

    <?php if (!$hasPasswordCol): ?>
        <div class="flash flash-warning">
            <strong>Database migration pending.</strong>
            Crew portal password support hasn't been enabled on this server yet.
            Please ask your manning agent to apply <code>CHANGES.sql</code> to
            unlock this feature.
        </div>
    <?php else: ?>
        <p class="help-text">
            <?php if ($passwordSetAt): ?>
                Password last set: <strong><?= h($passwordSetAt) ?></strong>.
            <?php endif; ?>
            <?php if ($lastLoginAt): ?>
                Last sign-in: <strong><?= h($lastLoginAt) ?></strong>.
            <?php endif; ?>
        </p>

        <form method="post" novalidate autocomplete="off">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="change_password">
            <div class="form-grid">
                <div class="form-row full-row">
                    <label for="current_password">Current password *</label>
                    <input type="password" id="current_password" name="current_password"
                           autocomplete="current-password" required>
                </div>
                <div class="form-row">
                    <label for="new_password">New password *</label>
                    <input type="password" id="new_password" name="new_password"
                           autocomplete="new-password" minlength="8" required>
                    <p class="help-text">At least 8 characters. Mix letters and numbers for a stronger password.</p>
                </div>
                <div class="form-row">
                    <label for="confirm_password">Confirm new password *</label>
                    <input type="password" id="confirm_password" name="confirm_password"
                           autocomplete="new-password" minlength="8" required>
                </div>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn">Change password</button>
            </div>
        </form>

        <p class="help-text" style="margin-top:18px;">
            Forgot your current password? Please contact SVSML — your manning agent
            can reset it for you from the admin side.
        </p>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
