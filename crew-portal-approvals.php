<?php
/**
 * SVSML-ERP — Crew Portal: Approvals
 *
 * Module 16. Read-only.
 *
 * Combined view of:
 *   - Client approvals  (line-items the client agreed to pay for the crew)
 *   - SVSML approvals   (internal billing ledger with pending balance)
 *
 * Lets the crew see exactly what's been agreed and what's still open.
 */

require __DIR__ . '/config/app.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/helpers.php';

requireCrew();
$crewId = currentCrewId();
$crew   = $crewId ? fetchCrewWithJoins($pdo, $crewId) : null;
if (!$crew) { logoutCurrentUser(); header('Location: ' . url('crew-login.php')); exit; }

$latestContract = fetchLatestContractForCrew($pdo, $crewId);

$client = $pdo->prepare(
    "SELECT ca.*, d.label AS description_label
       FROM client_approvals ca
       LEFT JOIN dropdown_items d ON d.id = ca.description_id
      WHERE ca.crew_id = :c
      ORDER BY ca.roll_number, ca.id"
);
$client->execute([':c' => $crewId]);
$client = $client->fetchAll();

$svsml = $pdo->prepare(
    "SELECT sa.*, d.label AS description_label
       FROM svsml_approvals sa
       LEFT JOIN dropdown_items d ON d.id = sa.description_id
      WHERE sa.crew_id = :c
      ORDER BY sa.roll_number, sa.id"
);
$svsml->execute([':c' => $crewId]);
$svsml = $svsml->fetchAll();

// Totals.
$clientTotal = 0.0;
foreach ($client as $r) $clientTotal += (float)$r['cost'];

$svsmlTotals = ['total' => 0.0, 'discount' => 0.0, 'paid' => 0.0, 'pending' => 0.0];
foreach ($svsml as $r) {
    $svsmlTotals['total']    += (float)$r['total_amount'];
    $svsmlTotals['discount'] += (float)$r['discount'];
    $svsmlTotals['paid']     += (float)$r['paid_amount'];
    $svsmlTotals['pending']  += (float)$r['pending_amount'];
}

$pageTitle  = 'My Profile — Approvals';
$currentTab = 'approvals';
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/crew-portal-hero.php';
include __DIR__ . '/includes/crew-portal-tabs.php';
?>

<div class="card">
    <h3 class="card-title">Client approvals <small class="help-text">(<?= count($client) ?>)</small></h3>
    <p class="help-text">Line items the client has approved to cover on your behalf.</p>

    <?php if (empty($client)): ?>
        <p class="help-text">No client approvals on record.</p>
    <?php else: ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width:8%">#</th>
                    <th>Description</th>
                    <th style="text-align:right; width:20%">Cost</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($client as $r): ?>
                    <tr>
                        <td><strong><?= (int)$r['roll_number'] ?></strong></td>
                        <td><?= h($r['description_label'] ?? '—') ?></td>
                        <td style="text-align:right"><?= h(number_format((float)$r['cost'], 2)) ?></td>
                    </tr>
                <?php endforeach; ?>
                <tr>
                    <td colspan="2" style="text-align:right; font-weight:600;">Total</td>
                    <td style="text-align:right; font-weight:700;">
                        <?= h(number_format($clientTotal, 2)) ?>
                    </td>
                </tr>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<div class="card">
    <h3 class="card-title">SVSML approvals <small class="help-text">(<?= count($svsml) ?>)</small></h3>
    <p class="help-text">Internal billing — total agreed amount, paid so far, and any pending balance.</p>

    <?php if (empty($svsml)): ?>
        <p class="help-text">No SVSML approvals on record.</p>
    <?php else: ?>
        <div class="kpi-grid" style="margin-bottom:14px;">
            <div class="kpi"><div class="kpi-label">Total agreed</div><div class="kpi-value"><?= h(number_format($svsmlTotals['total'],   2)) ?></div></div>
            <div class="kpi"><div class="kpi-label">Discount</div>    <div class="kpi-value"><?= h(number_format($svsmlTotals['discount'],2)) ?></div></div>
            <div class="kpi"><div class="kpi-label">Paid</div>        <div class="kpi-value" style="color:var(--green)"><?= h(number_format($svsmlTotals['paid'], 2)) ?></div></div>
            <div class="kpi"><div class="kpi-label">Pending</div>
                <div class="kpi-value" style="color: var(--<?= $svsmlTotals['pending'] > 0 ? 'yellow' : 'green' ?>)">
                    <?= h(number_format($svsmlTotals['pending'], 2)) ?>
                </div>
            </div>
        </div>

        <table class="data-table">
            <thead>
                <tr>
                    <th style="width:6%">#</th>
                    <th>Description</th>
                    <th style="text-align:right">Total</th>
                    <th style="text-align:right">Discount</th>
                    <th style="text-align:right">Paid</th>
                    <th style="text-align:right">Pending</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($svsml as $r):
                    $pendCls = ((float)$r['pending_amount'] > 0) ? 'status-yellow' : 'status-green';
                ?>
                    <tr>
                        <td><strong><?= (int)$r['roll_number'] ?></strong></td>
                        <td><?= h($r['description_label'] ?? '—') ?></td>
                        <td style="text-align:right"><?= h(number_format((float)$r['total_amount'], 2)) ?></td>
                        <td style="text-align:right"><?= h(number_format((float)$r['discount'],     2)) ?></td>
                        <td style="text-align:right"><?= h(number_format((float)$r['paid_amount'],  2)) ?></td>
                        <td style="text-align:right">
                            <span class="status <?= $pendCls ?>">
                                <?= h(number_format((float)$r['pending_amount'], 2)) ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
