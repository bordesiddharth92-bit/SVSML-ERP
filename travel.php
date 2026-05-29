<?php
/**
 * SVSML-ERP — Travel Details (global operations view)
 *
 * Module 9.
 *
 * Lists every travel_details row across all crew, filterable by
 * final_status (valid / pending / invalid / any). Useful for the
 * operations team to see what's still pending across the fleet.
 *
 * Each row links to the per-crew travel tab.
 *
 * Permissions: admin / sub_admin / staff (per matrix).
 */

require __DIR__ . '/config/app.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/helpers.php';

requireRole(['admin', 'sub_admin', 'staff']);

$user = currentUser();

$status   = $_GET['status'] ?? '';   // '', valid, pending, invalid
$onlyOpen = isset($_GET['only_open']) ? (int)$_GET['only_open'] : 0;
$page     = max(1, (int)($_GET['page'] ?? 1));
$perPage  = defined('PAGE_SIZE') ? PAGE_SIZE : 20;

$where  = [];
$params = [];
if (in_array($status, ['valid','pending','invalid'], true)) {
    $where[] = 't.final_status = :st';
    $params[':st'] = $status;
}
if ($onlyOpen) {
    $where[] = 't.is_done = 0';
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$total = $pdo->prepare("SELECT COUNT(*) FROM travel_details t $whereSql");
$total->execute($params);
$totalRows  = (int)$total->fetchColumn();
$totalPages = (int)max(1, ceil($totalRows / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

$rows = $pdo->prepare(
    "SELECT t.id, t.sr_number, t.detail_label, t.departure, t.arrival,
            t.is_done, t.final_status,
            cr.id        AS crew_id,
            cr.full_name AS crew_name,
            v.vessel_name
       FROM travel_details t
       JOIN crew      cr ON cr.id = t.crew_id
       LEFT JOIN vessels v ON v.id = cr.vessel_id
       $whereSql
       ORDER BY t.id DESC
       LIMIT $perPage OFFSET $offset"
);
$rows->execute($params);
$rows = $rows->fetchAll();

$qsBase = [];
if ($status   !== '') $qsBase['status']    = $status;
if ($onlyOpen)        $qsBase['only_open'] = 1;
$baseUrl = url('travel.php' . ($qsBase ? '?' . http_build_query($qsBase) : ''));

$pageTitle = 'Travel Details';
include __DIR__ . '/includes/header.php';
?>

<div class="card">
    <div class="toolbar">
        <h2 class="card-title" style="margin:0">Travel Details</h2>
    </div>

    <form method="get" class="filter-bar" novalidate>
        <div class="form-row">
            <select name="status">
                <option value=""        <?= $status === ''        ? 'selected' : '' ?>>Any status</option>
                <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>Pending</option>
                <option value="valid"   <?= $status === 'valid'   ? 'selected' : '' ?>>Valid</option>
                <option value="invalid" <?= $status === 'invalid' ? 'selected' : '' ?>>Invalid</option>
            </select>
        </div>
        <div class="form-row">
            <label style="font-weight:400;">
                <input type="checkbox" name="only_open" value="1" <?= $onlyOpen ? 'checked' : '' ?>>
                Open (not done) only
            </label>
        </div>
        <div class="filter-actions">
            <button type="submit" class="btn btn-secondary">Apply</button>
            <a class="btn btn-ghost" href="<?= asset('travel.php') ?>">Reset</a>
        </div>
    </form>

    <p class="result-count">
        <?= count($rows) ?> of <?= (int)$totalRows ?>
        <?= $totalRows === 1 ? 'row' : 'rows' ?>
        <?php if ($totalPages > 1): ?> · Page <?= (int)$page ?> of <?= (int)$totalPages ?><?php endif; ?>.
    </p>

    <?php if (empty($rows)): ?>
        <div class="empty-state">
            <h3>No travel rows match the filters</h3>
            <p>Add travel from a crew profile to get started.</p>
        </div>
    <?php else: ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Crew</th>
                    <th>Vessel</th>
                    <th>#</th>
                    <th>Label</th>
                    <th>Departure</th>
                    <th>Arrival</th>
                    <th>Done</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td>
                            <a href="<?= asset('crew-travel.php?id=' . (int)$r['crew_id']) ?>">
                                <strong><?= h($r['crew_name']) ?></strong>
                            </a>
                        </td>
                        <td><?= h($r['vessel_name'] ?? '—') ?></td>
                        <td><?= (int)$r['sr_number'] ?></td>
                        <td><?= h($r['detail_label']) ?></td>
                        <td><?= h(formatTravelDateTimeForDisplay($r['departure'] ?? '')) ?: '—' ?></td>
                        <td><?= h(formatTravelDateTimeForDisplay($r['arrival']   ?? '')) ?: '—' ?></td>
                        <td>
                            <?php if ((int)$r['is_done']): ?>
                                <span class="status status-green">Done</span>
                            <?php else: ?>
                                <span class="status status-gray">Open</span>
                            <?php endif; ?>
                        </td>
                        <td><?= travelStatusBadge($r['final_status'] ?? null) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?= paginate($page, $totalPages, $baseUrl) ?>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
