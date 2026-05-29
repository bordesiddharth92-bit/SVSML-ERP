<?php
/**
 * SVSML-ERP — Sign On / Off (global operations view)
 *
 * Module 7.
 *
 * Lists every sign-on / off event across the entire crew base, with the
 * same colour-coded duration badge used on the per-crew page. Default
 * view is "currently onboard" (sign_off_date IS NULL); the filter
 * dropdown lets the operator switch to "Closed tours" or "All".
 *
 * Each row links to the per-crew sign-on tab for editing.
 *
 * Permissions: admin / sub_admin / staff (per matrix).
 */

require __DIR__ . '/config/app.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/helpers.php';

requireRole(['admin', 'sub_admin', 'staff']);

$user      = currentUser();
$alerts    = getAlertSettings($pdo);

$filter    = $_GET['filter']     ?? 'onboard';
$filterCo  = isset($_GET['company_id']) && $_GET['company_id'] !== '' ? (int)$_GET['company_id'] : null;
$filterVe  = isset($_GET['vessel_id'])  && $_GET['vessel_id']  !== '' ? (int)$_GET['vessel_id']  : null;
$page      = max(1, (int)($_GET['page'] ?? 1));
$perPage   = defined('PAGE_SIZE') ? PAGE_SIZE : 20;

$where  = [];
$params = [];
if ($filter === 'onboard') {
    $where[] = 'so.sign_off_date IS NULL';
} elseif ($filter === 'closed') {
    $where[] = 'so.sign_off_date IS NOT NULL';
}
if ($filterCo !== null) { $where[] = 'cr.company_id = :co'; $params[':co'] = $filterCo; }
if ($filterVe !== null) { $where[] = 'cr.vessel_id = :ve';  $params[':ve'] = $filterVe; }
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$total = $pdo->prepare(
    "SELECT COUNT(*) FROM sign_on_off so
       JOIN crew cr ON cr.id = so.crew_id
       $whereSql"
);
$total->execute($params);
$totalRows  = (int)$total->fetchColumn();
$totalPages = (int)max(1, ceil($totalRows / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

$rows = $pdo->prepare(
    "SELECT so.id, so.sign_on_date, so.sign_off_date, so.days_on_board,
            cr.id   AS crew_id,
            cr.full_name,
            cr.passport_number,
            r.rank_name,
            v.vessel_name,
            co.company_name
       FROM sign_on_off so
       JOIN crew      cr ON cr.id = so.crew_id
       LEFT JOIN ranks     r  ON r.id  = cr.rank_id
       LEFT JOIN vessels   v  ON v.id  = cr.vessel_id
       LEFT JOIN companies co ON co.id = cr.company_id
       $whereSql
       ORDER BY so.sign_on_date DESC, so.id DESC
       LIMIT $perPage OFFSET $offset"
);
$rows->execute($params);
$rows = $rows->fetchAll();

$companies = $pdo->query("SELECT id, company_name FROM companies ORDER BY company_name")->fetchAll();
$vessels   = $pdo->query("SELECT id, vessel_name  FROM vessels   ORDER BY vessel_name")->fetchAll();

$qsBase = ['filter' => $filter];
if ($filterCo !== null) $qsBase['company_id'] = $filterCo;
if ($filterVe !== null) $qsBase['vessel_id']  = $filterVe;
$baseUrl = url('signon.php?' . http_build_query($qsBase));

$pageTitle = 'Sign On / Off';
include __DIR__ . '/includes/header.php';
?>

<div class="card">
    <div class="toolbar">
        <h2 class="card-title" style="margin:0">Sign On / Off</h2>
    </div>

    <form method="get" class="filter-bar" novalidate>
        <div class="form-row">
            <select name="filter">
                <option value="onboard" <?= $filter === 'onboard' ? 'selected' : '' ?>>Currently onboard</option>
                <option value="closed"  <?= $filter === 'closed'  ? 'selected' : '' ?>>Closed tours</option>
                <option value="all"     <?= $filter === 'all'     ? 'selected' : '' ?>>All events</option>
            </select>
        </div>
        <div class="form-row">
            <select name="company_id">
                <option value="">All companies</option>
                <?php foreach ($companies as $c): ?>
                    <option value="<?= (int)$c['id'] ?>" <?= ($filterCo === (int)$c['id']) ? 'selected' : '' ?>>
                        <?= h($c['company_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-row">
            <select name="vessel_id">
                <option value="">All vessels</option>
                <?php foreach ($vessels as $v): ?>
                    <option value="<?= (int)$v['id'] ?>" <?= ($filterVe === (int)$v['id']) ? 'selected' : '' ?>>
                        <?= h($v['vessel_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-actions">
            <button type="submit" class="btn btn-secondary">Apply</button>
            <a class="btn btn-ghost" href="<?= asset('signon.php') ?>">Reset</a>
        </div>
    </form>

    <p class="result-count">
        <?= count($rows) ?> of <?= (int)$totalRows ?>
        <?= $totalRows === 1 ? 'event' : 'events' ?>
        <?php if ($filter === 'onboard'): ?> currently onboard<?php endif; ?>
        <?php if ($totalPages > 1): ?> · Page <?= (int)$page ?> of <?= (int)$totalPages ?><?php endif; ?>.
    </p>

    <?php if (empty($rows)): ?>
        <div class="empty-state">
            <h3>No events match the filters</h3>
            <p>Try widening the filter, or record a sign-on from a crew profile.</p>
        </div>
    <?php else: ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Crew</th>
                    <th>Rank</th>
                    <th>Vessel / Company</th>
                    <th>Sign on</th>
                    <th>Sign off</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                    <?php $st = signOnDurationStatus($r['sign_on_date'], $r['sign_off_date'], $alerts); ?>
                    <tr>
                        <td>
                            <a href="<?= asset('crew-signon.php?id=' . (int)$r['crew_id']) ?>">
                                <strong><?= h($r['full_name']) ?></strong>
                            </a>
                            <?php if (!empty($r['passport_number'])): ?>
                                <br><small class="help-text"><?= h($r['passport_number']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td><?= h($r['rank_name'] ?? '—') ?></td>
                        <td>
                            <?= h($r['vessel_name'] ?? '—') ?>
                            <?php if (!empty($r['company_name'])): ?>
                                <br><small class="help-text"><?= h($r['company_name']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td><?= h($r['sign_on_date']) ?></td>
                        <td><?= h($r['sign_off_date'] ?? '—') ?></td>
                        <td><span class="status status-<?= h($st['class']) ?>"><?= h($st['label']) ?></span></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?= paginate($page, $totalPages, $baseUrl) ?>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
