<?php
/**
 * SVSML-ERP — Crew Portal: Sailing & Sign on/off
 *
 * Module 16. Read-only.
 *
 * Two cards on one page: Sign on / off (operational events from
 * sign_on_off) and Sailing history (the historical employment record
 * from sailing_history). They're closely related from a crew POV, so
 * we keep them together.
 */

require __DIR__ . '/config/app.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/helpers.php';

requireOnboardedCrew($pdo);
$crewId = currentCrewId();
$crew   = $crewId ? fetchCrewWithJoins($pdo, $crewId) : null;
if (!$crew) { logoutCurrentUser(); header('Location: ' . url('crew-login.php')); exit; }

$latestContract = fetchLatestContractForCrew($pdo, $crewId);

$signOn = $pdo->prepare(
    "SELECT * FROM sign_on_off WHERE crew_id = :c ORDER BY sign_on_date DESC, id DESC"
);
$signOn->execute([':c' => $crewId]);
$signOn = $signOn->fetchAll();

$sailing = $pdo->prepare(
    "SELECT sh.*, r.rank_name, v.vessel_name, c.company_name
       FROM sailing_history sh
       LEFT JOIN ranks     r ON r.id = sh.rank_id
       LEFT JOIN vessels   v ON v.id = sh.vessel_id
       LEFT JOIN companies c ON c.id = sh.company_id
      WHERE sh.crew_id = :c
      ORDER BY sh.sign_on_date DESC, sh.id DESC"
);
$sailing->execute([':c' => $crewId]);
$sailing = $sailing->fetchAll();

// Quick total of sea time (sum of completed days_on_vessel + currently-onboard days).
$totalSeaDays = 0;
foreach ($sailing as $s) $totalSeaDays += (int)($s['days_on_vessel'] ?? 0);

$pageTitle  = 'My Profile — Sailing';
$currentTab = 'sailing';
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/crew-portal-hero.php';
include __DIR__ . '/includes/crew-portal-tabs.php';
?>

<div class="card">
    <h3 class="card-title">Sign on / off <small class="help-text">(<?= count($signOn) ?>)</small></h3>

    <?php if (empty($signOn)): ?>
        <p class="help-text">No sign-on / off events on record yet.</p>
    <?php else: ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Sign on</th>
                    <th>Sign off</th>
                    <th>Days</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($signOn as $e): ?>
                    <tr>
                        <td><?= h($e['sign_on_date']) ?></td>
                        <td><?= h($e['sign_off_date'] ?? '—') ?></td>
                        <td><?= h((string)($e['days_on_board'] ?? '—')) ?></td>
                        <td>
                            <?php if (empty($e['sign_off_date'])): ?>
                                <span class="status status-green">Currently on board</span>
                            <?php else: ?>
                                <span class="status status-gray">Signed off</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<div class="card">
    <h3 class="card-title">
        Sailing history <small class="help-text">(<?= count($sailing) ?>)</small>
        <?php if ($totalSeaDays > 0): ?>
            <span class="help-text" style="float:right;">
                Total sea time: <strong><?= (int)$totalSeaDays ?></strong> days
            </span>
        <?php endif; ?>
    </h3>

    <?php if (empty($sailing)): ?>
        <p class="help-text">No sailing history on record yet.</p>
    <?php else: ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Vessel</th>
                    <th>Company</th>
                    <th>Rank</th>
                    <th>Sign on</th>
                    <th>Sign off</th>
                    <th>Days</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($sailing as $s): ?>
                    <tr>
                        <td><?= h($s['vessel_name']  ?? '—') ?></td>
                        <td><?= h($s['company_name'] ?? '—') ?></td>
                        <td><?= h($s['rank_name']    ?? '—') ?></td>
                        <td><?= h($s['sign_on_date']  ?? '—') ?></td>
                        <td><?= h($s['sign_off_date'] ?? '—') ?></td>
                        <td><?= h((string)($s['days_on_vessel'] ?? '—')) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
