<?php
/**
 * SVSML-ERP — Contracts (global operations view)
 *
 * Module 8.
 *
 * Lists every contract across all crew, with quick-filter by year and
 * client_contract_status. Each row links to the per-crew contracts tab.
 *
 * Permissions: admin / sub_admin / staff (per matrix).
 */

require __DIR__ . '/config/app.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/helpers.php';

requireRole(['admin', 'sub_admin', 'staff']);

$user = currentUser();

$year   = isset($_GET['year']) && $_GET['year'] !== '' ? (int)$_GET['year'] : null;
$status = $_GET['status'] ?? '';   // '', pending, uploaded
$page   = max(1, (int)($_GET['page'] ?? 1));
$perPage = defined('PAGE_SIZE') ? PAGE_SIZE : 20;

$where  = [];
$params = [];
if ($year !== null) {
    $where[] = 'c.reference_number LIKE :ref_like';
    $params[':ref_like'] = sprintf('SVSML/%d/%%', $year);
}
if ($status === 'pending' || $status === 'uploaded') {
    $where[] = 'c.client_contract_status = :st';
    $params[':st'] = $status;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$total = $pdo->prepare("SELECT COUNT(*) FROM contracts c $whereSql");
$total->execute($params);
$totalRows  = (int)$total->fetchColumn();
$totalPages = (int)max(1, ceil($totalRows / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

$rows = $pdo->prepare(
    "SELECT c.id, c.reference_number, c.contract_date, c.contract_period,
            c.client_contract_status, c.svsml_contract_path,
            c.dpdp_consent,
            cr.id        AS crew_id,
            cr.full_name AS crew_name,
            r.rank_name,
            v.vessel_name
       FROM contracts c
       JOIN crew      cr ON cr.id = c.crew_id
       LEFT JOIN ranks   r ON r.id = cr.rank_id
       LEFT JOIN vessels v ON v.id = cr.vessel_id
       $whereSql
       ORDER BY c.id DESC
       LIMIT $perPage OFFSET $offset"
);
$rows->execute($params);
$rows = $rows->fetchAll();

// Year list = distinct YYYY taken from existing reference_numbers
$years = $pdo->query(
    "SELECT DISTINCT SUBSTR(reference_number, 7, 4) AS y
       FROM contracts
      WHERE reference_number IS NOT NULL
      ORDER BY y DESC"
)->fetchAll(PDO::FETCH_COLUMN) ?: [];

$qsBase = [];
if ($year   !== null) $qsBase['year']   = $year;
if ($status !== '')   $qsBase['status'] = $status;
$baseUrl = url('contracts.php' . ($qsBase ? '?' . http_build_query($qsBase) : ''));

$pageTitle = 'Contracts';
include __DIR__ . '/includes/header.php';
?>

<div class="card">
    <div class="toolbar">
        <h2 class="card-title" style="margin:0">Contracts</h2>
    </div>

    <form method="get" class="filter-bar" novalidate>
        <div class="form-row">
            <select name="year">
                <option value="">All years</option>
                <?php foreach ($years as $y): ?>
                    <option value="<?= h($y) ?>" <?= ($year !== null && (string)$year === (string)$y) ? 'selected' : '' ?>>
                        <?= h($y) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-row">
            <select name="status">
                <option value=""         <?= $status === ''         ? 'selected' : '' ?>>Any client status</option>
                <option value="pending"  <?= $status === 'pending'  ? 'selected' : '' ?>>Client contract pending</option>
                <option value="uploaded" <?= $status === 'uploaded' ? 'selected' : '' ?>>Client contract uploaded</option>
            </select>
        </div>
        <div class="filter-actions">
            <button type="submit" class="btn btn-secondary">Apply</button>
            <a class="btn btn-ghost" href="<?= asset('contracts.php') ?>">Reset</a>
        </div>
    </form>

    <p class="result-count">
        <?= count($rows) ?> of <?= (int)$totalRows ?>
        <?= $totalRows === 1 ? 'contract' : 'contracts' ?>
        <?php if ($totalPages > 1): ?> · Page <?= (int)$page ?> of <?= (int)$totalPages ?><?php endif; ?>.
    </p>

    <?php if (empty($rows)): ?>
        <div class="empty-state">
            <h3>No contracts match the filters</h3>
            <p>Generate a contract from a crew profile to get started.</p>
        </div>
    <?php else: ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Reference</th>
                    <th>Crew</th>
                    <th>Rank / Vessel</th>
                    <th>Contract date</th>
                    <th>Period</th>
                    <th>SVSML PDF</th>
                    <th>Client</th>
                    <th>Consent</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><strong><?= h($r['reference_number']) ?></strong></td>
                        <td>
                            <a href="<?= asset('crew-contracts.php?id=' . (int)$r['crew_id']) ?>">
                                <?= h($r['crew_name']) ?>
                            </a>
                        </td>
                        <td>
                            <?= h($r['rank_name'] ?? '—') ?>
                            <?php if (!empty($r['vessel_name'])): ?>
                                <br><small class="help-text"><?= h($r['vessel_name']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td><?= h($r['contract_date'] ?? '—') ?></td>
                        <td><?= h($r['contract_period'] ?? '—') ?></td>
                        <td>
                            <?php if (!empty($r['svsml_contract_path'])): ?>
                                <a class="action-link" target="_blank" href="<?= asset('uploads/' . $r['svsml_contract_path']) ?>">View</a>
                            <?php else: ?>
                                <span class="help-text">— not generated —</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($r['client_contract_status'] === 'uploaded'): ?>
                                <span class="status status-green">Uploaded</span>
                            <?php else: ?>
                                <span class="status status-yellow">Pending</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ((int)$r['dpdp_consent']): ?>
                                <span class="status status-green">Given</span>
                            <?php else: ?>
                                <span class="status status-gray">Pending</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?= paginate($page, $totalPages, $baseUrl) ?>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
