<?php
/**
 * SVSML-ERP — Crew Portal: Medical certificates
 *
 * Module 16. Read-only.
 */

require __DIR__ . '/config/app.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/helpers.php';

requireCrew();
$crewId = currentCrewId();
$crew   = $crewId ? fetchCrewWithJoins($pdo, $crewId) : null;
if (!$crew) { logoutCurrentUser(); header('Location: ' . url('crew-login.php')); exit; }

$latestContract = fetchLatestContractForCrew($pdo, $crewId);

$stmt = $pdo->prepare(
    "SELECT cm.*, d.label AS medical_type_label
       FROM crew_medical cm
       LEFT JOIN dropdown_items d ON d.id = cm.medical_type_id
      WHERE cm.crew_id = :c
      ORDER BY cm.expiry_date DESC, cm.id"
);
$stmt->execute([':c' => $crewId]);
$medical = $stmt->fetchAll();

$pageTitle  = 'My Profile — Medical';
$currentTab = 'medical';
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/crew-portal-hero.php';
include __DIR__ . '/includes/crew-portal-tabs.php';
?>

<div class="card">
    <h3 class="card-title">Medical certificates <small class="help-text">(<?= count($medical) ?>)</small></h3>

    <?php if (empty($medical)): ?>
        <div class="empty-state">
            <h3>No medical certificates on file yet</h3>
            <p>Your manning agent will upload your medicals as they're received.</p>
        </div>
    <?php else: ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Type</th>
                    <th>Issue</th>
                    <th>Expiry</th>
                    <th>Status</th>
                    <th>File</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($medical as $m): ?>
                    <tr>
                        <td><?= h($m['medical_type_label'] ?? '—') ?></td>
                        <td><?= h($m['issue_date']  ?? '—') ?></td>
                        <td><?= h($m['expiry_date'] ?? '—') ?></td>
                        <td><?= dateStatusBadge($m['expiry_date'] ?? null) ?></td>
                        <td>
                            <?php if (!empty($m['file_path'])): ?>
                                <a class="action-link" target="_blank" rel="noopener"
                                   href="<?= asset('uploads/' . $m['file_path']) ?>">View</a>
                            <?php else: ?>
                                <span class="muted">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
