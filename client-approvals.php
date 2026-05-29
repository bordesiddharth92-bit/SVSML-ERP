<?php
/**
 * SVSML-ERP — Client approvals (global view)
 *
 * Module 10.
 *
 * Lists every client_approvals row across all crew, filterable by
 * description category and crew. Useful for billing & follow-up.
 *
 * Each row links to the per-crew client-approvals tab.
 *
 * Permissions: admin / sub_admin / staff (per matrix).
 */

require __DIR__ . '/config/app.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/helpers.php';

requireRole(['admin', 'sub_admin', 'staff']);

$user = currentUser();

$descId    = isset($_GET['description_id']) && $_GET['description_id'] !== '' ? (int)$_GET['description_id'] : null;
$companyId = isset($_GET['company_id'])     && $_GET['company_id']     !== '' ? (int)$_GET['company_id']     : null;
$page      = max(1, (int)($_GET['page'] ?? 1));
$perPage   = defined('PAGE_SIZE') ? PAGE_SIZE : 20;

$where  = [];
$params = [];
if ($descId !== null) {
    $where[] = 'ca.description_id = :d';
    $params[':d'] = $descId;
}
if ($companyId !== null) {
    $where[] = 'cr.company_id = :co';
    $params[':co'] = $companyId;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// ---- counts + totals ----
$totalRow = $pdo->prepare(
    "SELECT COUNT(*) AS cnt, COALESCE(SUM(ca.cost),0) AS total_cost
       FROM client_approvals ca
       JOIN crew cr ON cr.id = ca.crew_id
       $whereSql"
);
$totalRow->execute($params);
$summary = $totalRow->fetch();
$totalRows  = (int)($summary['cnt'] ?? 0);
$totalCost  = (float)($summary['total_cost'] ?? 0);
$totalPages = (int)max(1, ceil($totalRows / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

$rows = $pdo->prepare(
    "SELECT ca.id, ca.roll_number, ca.cost,
            d.label AS description_label,
            cr.id AS crew_id, cr.full_name AS crew_name,
            c.company_name
       FROM client_approvals ca
       JOIN crew cr ON cr.id = ca.crew_id
       LEFT JOIN dropdown_items d ON d.id = ca.description_id
       LEFT JOIN companies c     ON c.id = cr.company_id
       $whereSql
       ORDER BY ca.id DESC
       LIMIT $perPage OFFSET $offset"
);
$rows->execute($params);
$rows = $rows->fetchAll();

$descriptions = getDropdownOptions($pdo, 'approval_description', false);
$companies    = $pdo->query("SELECT id, company_name FROM companies ORDER BY company_name")->fetchAll();

$qsBase = [];
if ($descId    !== null) $qsBase['description_id'] = $descId;
if ($companyId !== null) $qsBase['company_id']     = $companyId;
$baseUrl = url('client-approvals.php' . ($qsBase ? '?' . http_build_query($qsBase) : ''));

$pageTitle = 'Client Approvals';
include __DIR__ . '/includes/header.php';
?>

<div class="card">
    <div class="toolbar">
        <h2 class="card-title" style="margin:0">Client Approvals</h2>
    </div>

    <form method="get" class="filter-bar" novalidate>
        <div class="form-row">
            <select name="description_id">
                <option value="">All descriptions</option>
                <?php foreach ($descriptions as $d): ?>
                    <option value="<?= (int)$d['id'] ?>" <?= ($descId === (int)$d['id']) ? 'selected' : '' ?>>
                        <?= h($d['label']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-row">
            <select name="company_id">
                <option value="">All companies</option>
                <?php foreach ($companies as $c): ?>
                    <option value="<?= (int)$c['id'] ?>" <?= ($companyId === (int)$c['id']) ? 'selected' : '' ?>>
                        <?= h($c['company_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-actions">
            <button type="submit" class="btn btn-secondary">Apply</button>
            <a class="btn btn-ghost" href="<?= asset('client-approvals.php') ?>">Reset</a>
        </div>
    </form>

    <p class="result-count">
        <?= count($rows) ?> of <?= (int)$totalRows ?>
        <?= $totalRows === 1 ? 'line' : 'lines' ?> · Total
        <strong><?= h(number_format($totalCost, 2)) ?></strong>
        <?php if ($totalPages > 1): ?> · Page <?= (int)$page ?> of <?= (int)$totalPages ?><?php endif; ?>.
    </p>

    <?php if (empty($rows)): ?>
        <div class="empty-state">
            <h3>No approval lines match the filters</h3>
            <p>Add lines from a crew profile's "Client approvals" tab.</p>
        </div>
    <?php else: ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Crew</th>
                    <th>Company</th>
                    <th>#</th>
                    <th>Description</th>
                    <th style="text-align:right">Cost</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td>
                            <a href="<?= asset('crew-client-approvals.php?id=' . (int)$r['crew_id']) ?>">
                                <strong><?= h($r['crew_name']) ?></strong>
                            </a>
                        </td>
                        <td><?= h($r['company_name'] ?? '—') ?></td>
                        <td><?= (int)$r['roll_number'] ?></td>
                        <td><?= h($r['description_label'] ?? '—') ?></td>
                        <td style="text-align:right"><?= h(number_format((float)$r['cost'], 2)) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?= paginate($page, $totalPages, $baseUrl) ?>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
