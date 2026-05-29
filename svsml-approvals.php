<?php
/**
 * SVSML-ERP — SVSML approvals (global view)
 *
 * Module 11.
 *
 * Lists every svsml_approvals row across all crew with running totals,
 * filter by description and crew company, and a focus toggle for rows
 * that still have pending balances.
 *
 * Permissions: admin / sub_admin / staff.
 */

require __DIR__ . '/config/app.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/helpers.php';

requireRole(['admin', 'sub_admin', 'staff']);

$user = currentUser();

$descId      = isset($_GET['description_id']) && $_GET['description_id'] !== '' ? (int)$_GET['description_id'] : null;
$companyId   = isset($_GET['company_id'])     && $_GET['company_id']     !== '' ? (int)$_GET['company_id']     : null;
$onlyPending = isset($_GET['only_pending']) ? (int)$_GET['only_pending'] : 0;
$page        = max(1, (int)($_GET['page'] ?? 1));
$perPage     = defined('PAGE_SIZE') ? PAGE_SIZE : 20;

$where  = [];
$params = [];
if ($descId !== null)    { $where[] = 'sa.description_id = :d';   $params[':d']  = $descId; }
if ($companyId !== null) { $where[] = 'cr.company_id = :co';      $params[':co'] = $companyId; }
if ($onlyPending)        { $where[] = 'sa.pending_amount > 0'; }
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$sumStmt = $pdo->prepare(
    "SELECT COUNT(*) AS cnt,
            COALESCE(SUM(sa.total_amount),0)   AS total_total,
            COALESCE(SUM(sa.discount),0)       AS total_discount,
            COALESCE(SUM(sa.paid_amount),0)    AS total_paid,
            COALESCE(SUM(sa.pending_amount),0) AS total_pending
       FROM svsml_approvals sa
       JOIN crew cr ON cr.id = sa.crew_id
       $whereSql"
);
$sumStmt->execute($params);
$summary = $sumStmt->fetch();
$totalRows  = (int)($summary['cnt'] ?? 0);
$totalPages = (int)max(1, ceil($totalRows / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

$rows = $pdo->prepare(
    "SELECT sa.id, sa.roll_number, sa.total_amount, sa.discount, sa.paid_amount, sa.pending_amount,
            d.label AS description_label,
            cr.id AS crew_id, cr.full_name AS crew_name,
            c.company_name
       FROM svsml_approvals sa
       JOIN crew cr ON cr.id = sa.crew_id
       LEFT JOIN dropdown_items d ON d.id = sa.description_id
       LEFT JOIN companies c     ON c.id = cr.company_id
       $whereSql
       ORDER BY sa.id DESC
       LIMIT $perPage OFFSET $offset"
);
$rows->execute($params);
$rows = $rows->fetchAll();

$descriptions = getDropdownOptions($pdo, 'approval_description', false);
$companies    = $pdo->query("SELECT id, company_name FROM companies ORDER BY company_name")->fetchAll();

$qsBase = [];
if ($descId    !== null) $qsBase['description_id'] = $descId;
if ($companyId !== null) $qsBase['company_id']     = $companyId;
if ($onlyPending)        $qsBase['only_pending']   = 1;
$baseUrl = url('svsml-approvals.php' . ($qsBase ? '?' . http_build_query($qsBase) : ''));

$pageTitle = 'SVSML Approvals';
include __DIR__ . '/includes/header.php';
?>

<div class="card">
    <div class="toolbar">
        <h2 class="card-title" style="margin:0">SVSML Approvals</h2>
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
        <div class="form-row">
            <label style="font-weight:400;">
                <input type="checkbox" name="only_pending" value="1" <?= $onlyPending ? 'checked' : '' ?>>
                Only with pending balance
            </label>
        </div>
        <div class="filter-actions">
            <button type="submit" class="btn btn-secondary">Apply</button>
            <a class="btn btn-ghost" href="<?= asset('svsml-approvals.php') ?>">Reset</a>
        </div>
    </form>

    <div class="kpi-grid" style="margin-bottom:14px;">
        <div class="kpi"><div class="kpi-label">Total amount</div>  <div class="kpi-value"><?= h(number_format((float)$summary['total_total'],   2)) ?></div></div>
        <div class="kpi"><div class="kpi-label">Discount</div>      <div class="kpi-value"><?= h(number_format((float)$summary['total_discount'],2)) ?></div></div>
        <div class="kpi"><div class="kpi-label">Paid</div>          <div class="kpi-value"><?= h(number_format((float)$summary['total_paid'],    2)) ?></div></div>
        <div class="kpi"><div class="kpi-label">Pending</div>       <div class="kpi-value"><?= h(number_format((float)$summary['total_pending'], 2)) ?></div></div>
    </div>

    <p class="result-count">
        <?= count($rows) ?> of <?= (int)$totalRows ?>
        <?= $totalRows === 1 ? 'line' : 'lines' ?>
        <?php if ($totalPages > 1): ?> · Page <?= (int)$page ?> of <?= (int)$totalPages ?><?php endif; ?>.
    </p>

    <?php if (empty($rows)): ?>
        <div class="empty-state">
            <h3>No SVSML approval lines match the filters</h3>
            <p>Add lines from a crew profile's "SVSML approvals" tab.</p>
        </div>
    <?php else: ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Crew</th>
                    <th>Company</th>
                    <th>#</th>
                    <th>Description</th>
                    <th style="text-align:right">Total</th>
                    <th style="text-align:right">Discount</th>
                    <th style="text-align:right">Paid</th>
                    <th style="text-align:right">Pending</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                    <?php $pendCls = ((float)$r['pending_amount'] > 0) ? 'status-yellow' : 'status-green'; ?>
                    <tr>
                        <td>
                            <a href="<?= asset('crew-svsml-approvals.php?id=' . (int)$r['crew_id']) ?>">
                                <strong><?= h($r['crew_name']) ?></strong>
                            </a>
                        </td>
                        <td><?= h($r['company_name'] ?? '—') ?></td>
                        <td><?= (int)$r['roll_number'] ?></td>
                        <td><?= h($r['description_label'] ?? '—') ?></td>
                        <td style="text-align:right"><?= h(number_format((float)$r['total_amount'],   2)) ?></td>
                        <td style="text-align:right"><?= h(number_format((float)$r['discount'],       2)) ?></td>
                        <td style="text-align:right"><?= h(number_format((float)$r['paid_amount'],    2)) ?></td>
                        <td style="text-align:right">
                            <span class="status <?= $pendCls ?>"><?= h(number_format((float)$r['pending_amount'], 2)) ?></span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?= paginate($page, $totalPages, $baseUrl) ?>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
