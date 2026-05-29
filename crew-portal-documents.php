<?php
/**
 * SVSML-ERP — Crew Portal: Documents
 *
 * Module 16. Read-only.
 *
 * Lists every crew_documents row for the signed-in crew with expiry
 * status colouring. Documents are grouped by type (CV / Passport /
 * CDC / Visa / SID) so the crew can quickly see what's on file and
 * what's expiring.
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
    "SELECT cd.*, d.label AS visa_type_label
       FROM crew_documents cd
       LEFT JOIN dropdown_items d ON d.id = cd.visa_type_id
      WHERE cd.crew_id = :c
      ORDER BY cd.document_type, cd.id"
);
$stmt->execute([':c' => $crewId]);
$documents = $stmt->fetchAll();

// Group by document_type for cleaner display.
$grouped = ['cv' => [], 'passport' => [], 'cdc' => [], 'visa' => [], 'sid' => []];
foreach ($documents as $d) {
    $type = $d['document_type'] ?? 'cv';
    if (!isset($grouped[$type])) $grouped[$type] = [];
    $grouped[$type][] = $d;
}

$typeLabels = [
    'cv'       => 'CV / Resume',
    'passport' => 'Passport',
    'cdc'      => 'CDC',
    'visa'     => 'Visa',
    'sid'      => 'SID',
];

$pageTitle  = 'My Profile — Documents';
$currentTab = 'documents';
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/crew-portal-hero.php';
include __DIR__ . '/includes/crew-portal-tabs.php';
?>

<div class="card">
    <h3 class="card-title">Documents <small class="help-text">(<?= count($documents) ?>)</small></h3>

    <?php if (empty($documents)): ?>
        <div class="empty-state">
            <h3>No documents on file yet</h3>
            <p>Your manning agent will upload your documents to the portal as they're received.</p>
        </div>
    <?php else: ?>
        <?php foreach ($typeLabels as $type => $label): ?>
            <?php $rows = $grouped[$type] ?? []; if (empty($rows)) continue; ?>
            <div class="section-title"><?= h($label) ?> (<?= count($rows) ?>)</div>
            <table class="data-table">
                <thead>
                    <tr>
                        <?php if ($type === 'visa'): ?><th>Visa type</th><?php endif; ?>
                        <th>Number</th>
                        <th>Issue</th>
                        <th>Expiry</th>
                        <th>Status</th>
                        <th>File</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $d): ?>
                        <tr>
                            <?php if ($type === 'visa'): ?>
                                <td><?= h($d['visa_type_label'] ?? '—') ?></td>
                            <?php endif; ?>
                            <td><?= h($d['document_number'] ?? '—') ?></td>
                            <td><?= h($d['issue_date']  ?? '—') ?></td>
                            <td><?= h($d['expiry_date'] ?? '—') ?></td>
                            <td><?= dateStatusBadge($d['expiry_date'] ?? null) ?></td>
                            <td>
                                <?php if (!empty($d['file_path'])): ?>
                                    <a class="action-link" target="_blank" rel="noopener"
                                       href="<?= asset('uploads/' . $d['file_path']) ?>">View</a>
                                <?php else: ?>
                                    <span class="muted">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
