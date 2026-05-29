<?php
/**
 * SVSML-ERP — Vessels list
 *
 * Module 3.
 *
 * Lists all vessels with the three required dates (PNI, MLC, Financial
 * Security) shown as colour-coded expiry badges using dateStatus().
 * Supports filtering by company and a free-text search on vessel name
 * and IMO number. The "Add vessel" and "Edit" actions are handled by
 * vessel-edit.php; this page only handles list + delete.
 *
 * Permissions: admin / sub_admin / staff (per matrix). Crew has no access.
 */

require __DIR__ . '/config/app.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/helpers.php';

requireRole(['admin', 'sub_admin', 'staff']);

$user = currentUser();

// -------------------------------------------------------------
// POST: delete (only action handled here — add/edit go to vessel-edit.php)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $row = $pdo->prepare("SELECT vessel_name FROM vessels WHERE id = :i");
            $row->execute([':i' => $id]);
            $vname = $row->fetchColumn();
            if ($vname !== false) {
                // Count dependent rows for the activity log.
                $crewCount = (int)$pdo->query(
                    "SELECT COUNT(*) FROM crew WHERE vessel_id = " . $id
                )->fetchColumn();
                $histCount = (int)$pdo->query(
                    "SELECT COUNT(*) FROM sailing_history WHERE vessel_id = " . $id
                )->fetchColumn();

                // vessel_extra_fields is ON DELETE CASCADE so it cleans up automatically.
                $stmt = $pdo->prepare("DELETE FROM vessels WHERE id = :i");
                $stmt->execute([':i' => $id]);

                $details = "Deleted vessel '{$vname}'";
                if ($crewCount + $histCount > 0) {
                    $details .= " (cleared on {$crewCount} crew, {$histCount} sailing history rows)";
                }
                logActivity($pdo, $user['id'], 'delete', 'vessels', $id, $details);

                $msg = "Deleted '{$vname}'.";
                if ($crewCount + $histCount > 0) {
                    $msg .= " {$crewCount} crew and {$histCount} history rows now have no vessel.";
                }
                flash('success', $msg);
            }
        }
    }

    header('Location: ' . url('vessels.php'
        . (!empty($_POST['company_id']) ? '?company_id=' . (int)$_POST['company_id'] : '')));
    exit;
}

// -------------------------------------------------------------
// GET — filters + list
// -------------------------------------------------------------
$filterCompany = isset($_GET['company_id']) && $_GET['company_id'] !== '' ? (int)$_GET['company_id'] : null;
$search        = trim($_GET['q'] ?? '');

$where  = [];
$params = [];
if ($filterCompany !== null) {
    $where[]               = 'v.company_id = :company_id';
    $params[':company_id'] = $filterCompany;
}
if ($search !== '') {
    $where[]    = '(v.vessel_name LIKE :q OR v.imo_number LIKE :q)';
    $params[':q'] = '%' . $search . '%';
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$vessels = $pdo->prepare(
    "SELECT v.id, v.vessel_name, v.imo_number, v.ship_type, v.ship_flag,
            v.pni_date, v.mlc_date, v.financial_security_date,
            c.company_name
       FROM vessels v
       LEFT JOIN companies c ON c.id = v.company_id
       $whereSql
       ORDER BY v.vessel_name"
);
$vessels->execute($params);
$vessels = $vessels->fetchAll();

// Build the filter dropdown source.
$companies = $pdo->query("SELECT id, company_name FROM companies ORDER BY company_name")->fetchAll();

// Preserve filters in pagination/links if needed later.
$qsParts = [];
if ($filterCompany !== null) $qsParts['company_id'] = $filterCompany;
if ($search !== '')          $qsParts['q']          = $search;
$qs = $qsParts ? '?' . http_build_query($qsParts) : '';

$pageTitle = 'Vessels';
include __DIR__ . '/includes/header.php';
?>

<div class="card">
    <div class="toolbar">
        <h2 class="card-title" style="margin:0">Vessels</h2>
        <a class="btn" href="<?= asset('vessel-edit.php') ?>">+ Add vessel</a>
    </div>

    <form method="get" class="filter-bar" novalidate>
        <div class="form-row">
            <input type="search" name="q" value="<?= h($search) ?>"
                   placeholder="Search by name or IMO" autocomplete="off">
        </div>
        <div class="form-row">
            <select name="company_id">
                <option value="">All companies</option>
                <?php foreach ($companies as $c): ?>
                    <option value="<?= (int)$c['id'] ?>" <?= ($filterCompany === (int)$c['id']) ? 'selected' : '' ?>>
                        <?= h($c['company_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-actions">
            <button type="submit" class="btn btn-secondary">Apply</button>
            <?php if ($filterCompany !== null || $search !== ''): ?>
                <a class="btn btn-ghost" href="<?= asset('vessels.php') ?>">Clear</a>
            <?php endif; ?>
        </div>
    </form>

    <p class="result-count">
        <?= count($vessels) ?> vessel<?= count($vessels) === 1 ? '' : 's' ?>
        <?= ($filterCompany !== null || $search !== '') ? 'matching the filters' : 'total' ?>.
    </p>

    <?php if (empty($vessels)): ?>
        <div class="empty-state">
            <h3>No vessels yet</h3>
            <p>
                <?php if ($filterCompany !== null || $search !== ''): ?>
                    No vessels match the current filters.
                    <a href="<?= asset('vessels.php') ?>">Clear filters</a>.
                <?php else: ?>
                    Add your first vessel to get started.
                <?php endif; ?>
            </p>
            <?php if ($filterCompany === null && $search === ''): ?>
                <a class="btn" href="<?= asset('vessel-edit.php') ?>">+ Add vessel</a>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Vessel</th>
                    <th>Company</th>
                    <th>IMO</th>
                    <th>Type / Flag</th>
                    <th>P&amp;I</th>
                    <th>MLC</th>
                    <th>Fin. Security</th>
                    <th style="text-align:right">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($vessels as $v): ?>
                    <?php
                        $pni  = dateStatus($v['pni_date']);
                        $mlc  = dateStatus($v['mlc_date']);
                        $fsec = dateStatus($v['financial_security_date']);
                    ?>
                    <tr>
                        <td>
                            <a href="<?= asset('vessel-edit.php?id=' . (int)$v['id']) ?>">
                                <strong><?= h($v['vessel_name']) ?></strong>
                            </a>
                        </td>
                        <td><?= h($v['company_name'] ?? '—') ?></td>
                        <td><?= h($v['imo_number'] ?? '—') ?></td>
                        <td>
                            <?= h($v['ship_type'] ?? '—') ?>
                            <?php if (!empty($v['ship_flag'])): ?>
                                <span class="help-text">/ <?= h($v['ship_flag']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="status status-<?= h($pni['class']) ?>"
                                  title="<?= h($v['pni_date'] ?? '—') ?>">
                                <?= h($pni['label']) ?>
                            </span>
                        </td>
                        <td>
                            <span class="status status-<?= h($mlc['class']) ?>"
                                  title="<?= h($v['mlc_date'] ?? '—') ?>">
                                <?= h($mlc['label']) ?>
                            </span>
                        </td>
                        <td>
                            <span class="status status-<?= h($fsec['class']) ?>"
                                  title="<?= h($v['financial_security_date'] ?? '—') ?>">
                                <?= h($fsec['label']) ?>
                            </span>
                        </td>
                        <td>
                            <div class="row-actions">
                                <a class="btn btn-secondary btn-sm"
                                   href="<?= asset('vessel-edit.php?id=' . (int)$v['id']) ?>">Edit</a>
                                <form method="post">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
                                    <?php if ($filterCompany !== null): ?>
                                        <input type="hidden" name="company_id" value="<?= (int)$filterCompany ?>">
                                    <?php endif; ?>
                                    <button type="submit" class="btn btn-danger btn-sm"
                                            data-confirm="Delete vessel '<?= h($v['vessel_name']) ?>'? Crew currently assigned will lose their vessel pointer.">
                                        Delete
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
