<?php
/**
 * SVSML-ERP — Crew list
 *
 * Module 4.
 *
 * Lists all crew members. Supports:
 *   - free-text search across full_name / indos_number / passport_number
 *   - filter by company
 *   - filter by rank
 *   - pagination (PAGE_SIZE = 20 per the spec)
 *
 * Inline delete; add/edit are handled by crew-edit.php.
 *
 * Permissions: admin / sub_admin / staff (per matrix). Crew users will
 * land on their own profile in Module 16 — they don't reach this page.
 */

require __DIR__ . '/config/app.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/helpers.php';

requireRole(['admin', 'sub_admin', 'staff']);

$user = currentUser();

// -------------------------------------------------------------
// POST: delete (only action handled here)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $row = $pdo->prepare("SELECT full_name FROM crew WHERE id = :i");
            $row->execute([':i' => $id]);
            $name = $row->fetchColumn();
            if ($name !== false) {
                // crew has many ON DELETE CASCADE children:
                // crew_documents, crew_medical, basic_courses, advanced_courses,
                // contracts, client_approvals, svsml_approvals, travel_details,
                // sign_on_off, sailing_history. They'll all be removed.
                $stmt = $pdo->prepare("DELETE FROM crew WHERE id = :i");
                $stmt->execute([':i' => $id]);
                logActivity(
                    $pdo, $user['id'], 'delete', 'crew', $id,
                    "Deleted crew '{$name}' and all child records"
                );
                flash('success', "Deleted '{$name}' and all linked documents/contracts.");
            }
        }
    }

    // Preserve filters on the redirect.
    $qs = [];
    foreach (['q', 'company_id', 'rank_id', 'page'] as $k) {
        if (!empty($_POST[$k])) $qs[$k] = $_POST[$k];
    }
    header('Location: ' . url('crew.php' . ($qs ? '?' . http_build_query($qs) : '')));
    exit;
}

// -------------------------------------------------------------
// GET — filters + paginated list
// -------------------------------------------------------------
$search        = trim($_GET['q'] ?? '');
$filterCompany = isset($_GET['company_id']) && $_GET['company_id'] !== '' ? (int)$_GET['company_id'] : null;
$filterRank    = isset($_GET['rank_id'])    && $_GET['rank_id']    !== '' ? (int)$_GET['rank_id']    : null;
$page          = max(1, (int)($_GET['page'] ?? 1));
$perPage       = defined('PAGE_SIZE') ? PAGE_SIZE : 20;

$where  = [];
$params = [];
if ($filterCompany !== null) {
    $where[]               = 'cr.company_id = :company_id';
    $params[':company_id'] = $filterCompany;
}
if ($filterRank !== null) {
    $where[]            = 'cr.rank_id = :rank_id';
    $params[':rank_id'] = $filterRank;
}
if ($search !== '') {
    $where[]      = '(cr.full_name LIKE :q OR cr.indos_number LIKE :q OR cr.passport_number LIKE :q)';
    $params[':q'] = '%' . $search . '%';
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Count total matching rows for pagination.
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM crew cr $whereSql");
$countStmt->execute($params);
$totalRows  = (int)$countStmt->fetchColumn();
$totalPages = (int)max(1, ceil($totalRows / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

// Fetch the page.
$listSql = "
    SELECT cr.id,
           cr.full_name,
           cr.indos_number,
           cr.passport_number,
           cr.joiner_type,
           cr.crew_access_enabled,
           r.rank_name,
           c.company_name,
           v.vessel_name
      FROM crew cr
      LEFT JOIN ranks     r ON r.id = cr.rank_id
      LEFT JOIN companies c ON c.id = cr.company_id
      LEFT JOIN vessels   v ON v.id = cr.vessel_id
      $whereSql
     ORDER BY cr.full_name
     LIMIT $perPage OFFSET $offset
";
$listStmt = $pdo->prepare($listSql);
$listStmt->execute($params);
$crewRows = $listStmt->fetchAll();

// Filter dropdown sources.
$companies = $pdo->query("SELECT id, company_name FROM companies ORDER BY company_name")->fetchAll();
$ranks     = $pdo->query("SELECT id, rank_name    FROM ranks     ORDER BY rank_name")->fetchAll();

// Build a base URL that preserves the current filters (used for pagination).
$qsBase = [];
if ($search !== '')          $qsBase['q']          = $search;
if ($filterCompany !== null) $qsBase['company_id'] = $filterCompany;
if ($filterRank !== null)    $qsBase['rank_id']    = $filterRank;
$baseUrl = url('crew.php' . ($qsBase ? '?' . http_build_query($qsBase) : ''));

$pageTitle = 'Crew';
include __DIR__ . '/includes/header.php';
?>

<div class="card">
    <div class="toolbar">
        <h2 class="card-title" style="margin:0">Crew</h2>
        <a class="btn" href="<?= asset('crew-edit.php') ?>">+ Add crew</a>
    </div>

    <form method="get" class="filter-bar" novalidate>
        <div class="form-row">
            <input type="search" name="q" value="<?= h($search) ?>"
                   placeholder="Search name, INDOS or passport" autocomplete="off">
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
        <div class="form-row">
            <select name="rank_id">
                <option value="">All ranks</option>
                <?php foreach ($ranks as $r): ?>
                    <option value="<?= (int)$r['id'] ?>" <?= ($filterRank === (int)$r['id']) ? 'selected' : '' ?>>
                        <?= h($r['rank_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-actions">
            <button type="submit" class="btn btn-secondary">Apply</button>
            <?php if ($search !== '' || $filterCompany !== null || $filterRank !== null): ?>
                <a class="btn btn-ghost" href="<?= asset('crew.php') ?>">Clear</a>
            <?php endif; ?>
        </div>
    </form>

    <p class="result-count">
        Showing <?= count($crewRows) ?> of <?= (int)$totalRows ?>
        <?= $totalRows === 1 ? 'crew member' : 'crew members' ?>
        <?= ($search !== '' || $filterCompany !== null || $filterRank !== null) ? ' matching the filters' : '' ?>.
        <?php if ($totalPages > 1): ?>
            Page <?= (int)$page ?> of <?= (int)$totalPages ?>.
        <?php endif; ?>
    </p>

    <?php if (empty($crewRows)): ?>
        <div class="empty-state">
            <h3>No crew yet</h3>
            <p>
                <?php if ($search !== '' || $filterCompany !== null || $filterRank !== null): ?>
                    No crew members match the current filters.
                    <a href="<?= asset('crew.php') ?>">Clear filters</a>.
                <?php else: ?>
                    Add your first crew member to get started.
                <?php endif; ?>
            </p>
            <?php if ($search === '' && $filterCompany === null && $filterRank === null): ?>
                <a class="btn" href="<?= asset('crew-edit.php') ?>">+ Add crew</a>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Rank</th>
                    <th>Company / Vessel</th>
                    <th>Passport / INDOS</th>
                    <th>Joiner</th>
                    <th>Login</th>
                    <th style="text-align:right">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($crewRows as $cr): ?>
                    <tr>
                        <td>
                            <a href="<?= asset('crew-edit.php?id=' . (int)$cr['id']) ?>">
                                <strong><?= h($cr['full_name']) ?></strong>
                            </a>
                        </td>
                        <td><?= h($cr['rank_name'] ?? '—') ?></td>
                        <td>
                            <?= h($cr['company_name'] ?? '—') ?>
                            <?php if (!empty($cr['vessel_name'])): ?>
                                <span class="help-text">/ <?= h($cr['vessel_name']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= h($cr['passport_number'] ?? '—') ?>
                            <?php if (!empty($cr['indos_number'])): ?>
                                <br><small class="help-text">INDOS: <?= h($cr['indos_number']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($cr['joiner_type'] === 'rejoiner'): ?>
                                <span class="status status-green">Rejoiner</span>
                            <?php else: ?>
                                <span class="status status-gray">New joiner</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ((int)$cr['crew_access_enabled']): ?>
                                <span class="status status-green">Enabled</span>
                            <?php else: ?>
                                <span class="status status-gray">Disabled</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="row-actions">
                                <a class="btn btn-secondary btn-sm"
                                   href="<?= asset('crew-edit.php?id=' . (int)$cr['id']) ?>">Edit</a>
                                <form method="post">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id"     value="<?= (int)$cr['id'] ?>">
                                    <?php foreach (['q','company_id','rank_id','page'] as $k): ?>
                                        <?php if (!empty($_GET[$k])): ?>
                                            <input type="hidden" name="<?= h($k) ?>" value="<?= h($_GET[$k]) ?>">
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                    <button type="submit" class="btn btn-danger btn-sm"
                                            data-confirm="Delete '<?= h($cr['full_name']) ?>'? All linked documents, courses, contracts, sign-on/off and sailing history will be removed.">
                                        Delete
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?= paginate($page, $totalPages, $baseUrl) ?>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
