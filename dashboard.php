<?php
/** Role-based dashboard. Module 1 ships only the skeleton; rich KPIs land in Module 14. */
require __DIR__ . '/config/app.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/helpers.php';

requireLogin();

$user      = currentUser();
$pageTitle = 'Dashboard';

// Tiny health check so the user can confirm the schema is wired up.
$counts = [];
foreach (['users','ranks','companies','vessels','crew','dropdown_items','staff_activity'] as $tbl) {
    try {
        $counts[$tbl] = (int)$pdo->query("SELECT COUNT(*) FROM `$tbl`")->fetchColumn();
    } catch (PDOException $e) {
        $counts[$tbl] = '!';
    }
}
$sys = getSystemSettings($pdo);

include __DIR__ . '/includes/header.php';
?>

<div class="card">
    <h2 class="card-title">Welcome, <?= h($user['full_name']) ?></h2>
    <p>
        You are signed in as <span class="badge badge-<?= h($user['role']) ?>"><?= h(strtoupper($user['role'])) ?></span>
        on <strong><?= h($sys['company_name'] ?? APP_NAME) ?></strong>.
    </p>
    <p class="help-text">
        Module 1 is the foundation only. The functional modules (crew, vessels, contracts, &hellip;)
        will appear in the sidebar as they are delivered.
    </p>
</div>

<div class="card">
    <h3 class="card-title">Schema health check</h3>
    <div class="kpi-grid">
        <?php foreach ($counts as $tbl => $n): ?>
            <div class="kpi">
                <div class="kpi-label"><?= h($tbl) ?></div>
                <div class="kpi-value"><?= h((string)$n) ?></div>
            </div>
        <?php endforeach; ?>
    </div>
    <p class="help-text">
        These counts confirm that <code>schema.sql</code> + <code>seed.sql</code> were imported successfully.
    </p>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
