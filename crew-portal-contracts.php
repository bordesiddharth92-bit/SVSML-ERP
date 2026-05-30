<?php
/**
 * SVSML-ERP — Crew Portal: Contracts
 *
 * Module 16. Read-only.
 *
 * The crew sees their own contracts: SVSML-side PDF (always), the
 * client-signed PDF if uploaded, financial terms, and DPDP consent
 * status. The "give DPDP consent" workflow is handled by SVSML staff
 * during contract generation — this is a view only.
 */

require __DIR__ . '/config/app.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/helpers.php';

requireOnboardedCrew($pdo);
$crewId = currentCrewId();
$crew   = $crewId ? fetchCrewWithJoins($pdo, $crewId) : null;
if (!$crew) { logoutCurrentUser(); header('Location: ' . url('crew-login.php')); exit; }

$latestContract = fetchLatestContractForCrew($pdo, $crewId);

$stmt = $pdo->prepare(
    "SELECT * FROM contracts WHERE crew_id = :c ORDER BY id DESC"
);
$stmt->execute([':c' => $crewId]);
$contracts = $stmt->fetchAll();

$pageTitle  = 'My Profile — Contracts';
$currentTab = 'contracts';
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/crew-portal-hero.php';
include __DIR__ . '/includes/crew-portal-tabs.php';
?>

<div class="card">
    <h3 class="card-title">Contracts <small class="help-text">(<?= count($contracts) ?>)</small></h3>

    <?php if (empty($contracts)): ?>
        <div class="empty-state">
            <h3>No contracts yet</h3>
            <p>Once SVSML generates your first contract it will appear here, with download links for both the SVSML and (when received) the client-signed PDFs.</p>
        </div>
    <?php else: ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Reference</th>
                    <th>Date</th>
                    <th>Period</th>
                    <th>Salary</th>
                    <th>Commencement</th>
                    <th>SVSML PDF</th>
                    <th>Client PDF</th>
                    <th>DPDP consent</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($contracts as $c): ?>
                    <tr>
                        <td><strong><?= h($c['reference_number'] ?? '—') ?></strong></td>
                        <td><?= h($c['contract_date']    ?? '—') ?></td>
                        <td><?= h($c['contract_period']  ?? '—') ?></td>
                        <td>
                            <?php if ($c['total_salary'] !== null && $c['total_salary'] !== ''): ?>
                                <?= h(number_format((float)$c['total_salary'], 2)) ?>
                            <?php else: ?>
                                <span class="muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td><?= h($c['commencement_date'] ?? '—') ?></td>
                        <td>
                            <?php if (!empty($c['svsml_contract_path'])): ?>
                                <a class="action-link" target="_blank" rel="noopener"
                                   href="<?= asset('uploads/' . $c['svsml_contract_path']) ?>">Download</a>
                            <?php else: ?>
                                <span class="muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (($c['client_contract_status'] ?? '') === 'uploaded' && !empty($c['client_contract_path'])): ?>
                                <a class="action-link" target="_blank" rel="noopener"
                                   href="<?= asset('uploads/' . $c['client_contract_path']) ?>">Download</a>
                            <?php elseif (($c['client_contract_status'] ?? '') === 'uploaded'): ?>
                                <span class="status status-green">Uploaded</span>
                            <?php else: ?>
                                <span class="status status-gray">Pending</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ((int)($c['dpdp_consent'] ?? 0) === 1): ?>
                                <span class="status status-green">Given</span>
                                <?php if (!empty($c['dpdp_consent_at'])): ?>
                                    <br><small class="help-text"><?= h($c['dpdp_consent_at']) ?></small>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="status status-gray">Not given</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p class="help-text" style="margin-top:14px;">
            If a download link is missing or you need a printed copy, please contact SVSML.
        </p>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
