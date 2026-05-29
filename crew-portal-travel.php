<?php
/**
 * SVSML-ERP — Crew Portal: Travel
 *
 * Module 16. Read-only.
 *
 * Reuses the Module 9 type-aware display helpers (travelRowType +
 * travelFieldDisplay) so a "Flight Ticket (Domestic)" row shows
 * airport / date / time, an OKTB row shows status + detail, etc.
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
    "SELECT * FROM travel_details WHERE crew_id = :c ORDER BY sr_number, id"
);
$stmt->execute([':c' => $crewId]);
$travel = $stmt->fetchAll();

$pageTitle  = 'My Profile — Travel';
$currentTab = 'travel';
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/crew-portal-hero.php';
include __DIR__ . '/includes/crew-portal-tabs.php';
?>

<div class="card">
    <h3 class="card-title">Travel segments <small class="help-text">(<?= count($travel) ?>)</small></h3>

    <?php if (empty($travel)): ?>
        <div class="empty-state">
            <h3>No travel arranged yet</h3>
            <p>Your travel itinerary will appear here once SVSML adds your flights, visas and OKTB / LG details.</p>
        </div>
    <?php else: ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Detail</th>
                    <th>Departure</th>
                    <th>Arrival</th>
                    <th>Done</th>
                    <th>Final status</th>
                    <th>Remarks</th>
                    <th>File</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($travel as $t):
                    $rt = travelRowType($t['detail_label'], $t['field_type'] ?? 'default');
                ?>
                    <tr>
                        <td><strong><?= (int)$t['sr_number'] ?></strong></td>
                        <td><?= h($t['detail_label']) ?></td>
                        <td><?= h(travelFieldDisplay($t['departure'] ?? '', $rt)) ?></td>
                        <td><?= h(travelFieldDisplay($t['arrival']   ?? '', $rt)) ?></td>
                        <td>
                            <?= ((int)$t['is_done'])
                                ? '<span class="status status-green">Done</span>'
                                : '<span class="status status-gray">Open</span>' ?>
                        </td>
                        <td><?= travelStatusBadge($t['final_status'] ?? null) ?></td>
                        <td><?= h($t['remarks'] ?? '—') ?></td>
                        <td>
                            <?php if (!empty($t['file_path'])): ?>
                                <a class="action-link" target="_blank" rel="noopener"
                                   href="<?= asset('uploads/' . $t['file_path']) ?>">View</a>
                            <?php else: ?>
                                <span class="muted">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p class="help-text" style="margin-top:14px;">
            Times shown in DD/MM/YYYY HH:MM (24hr). If anything looks wrong, please contact SVSML before travelling.
        </p>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
