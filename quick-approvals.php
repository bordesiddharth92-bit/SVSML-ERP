<?php
/**
 * SVSML-ERP — Quick Approvals (list)
 *
 * Module 12.
 *
 * Quick approvals are stand-alone billing approvals used when a person
 * isn't (yet) a full crew record but the agency still needs to
 * record a description-based cost ledger for them.
 *
 * Schema:
 *   quick_approvals       — header  (crew_name TEXT, company, rank, date, total)
 *   quick_approval_items  — lines   (description_id, amount)
 *
 * The header.total_amount is recomputed from the items every time the
 * record is saved so it stays in sync with the lines.
 *
 * Permissions: admin / sub_admin / staff.
 */

require __DIR__ . '/config/app.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/helpers.php';

requireRole(['admin', 'sub_admin', 'staff']);

$user = currentUser();

// -------------------------------------------------------------
// POST: delete
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $row = $pdo->prepare("SELECT crew_name FROM quick_approvals WHERE id = :i");
            $row->execute([':i' => $id]);
            $name = $row->fetchColumn();
            if ($name !== false) {
                // ON DELETE CASCADE removes children in quick_approval_items.
                $pdo->prepare("DELETE FROM quick_approvals WHERE id = :i")->execute([':i' => $id]);
                logActivity(
                    $pdo, $user['id'], 'delete', 'quick_approvals', $id,
                    "Deleted quick approval '{$name}'"
                );
                flash('success', "Deleted quick approval for '{$name}'.");
            }
        }
    }

    header('Location: ' . url('quick-approvals.php'));
    exit;
}

// -------------------------------------------------------------
// GET: paginated list
// -------------------------------------------------------------
$search    = trim($_GET['q'] ?? '');
$companyId = isset($_GET['company_id']) && $_GET['company_id'] !== '' ? (int)$_GET['company_id'] : null;
$page      = max(1, (int)($_GET['page'] ?? 1));
$perPage   = defined('PAGE_SIZE') ? PAGE_SIZE : 20;

$where  = [];
$params = [];
if ($search !== '')      { $where[] = 'qa.crew_name LIKE :q';   $params[':q']  = '%' . $search . '%'; }
if ($companyId !== null) { $where[] = 'qa.company_id = :co';    $params[':co'] = $companyId; }
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM quick_approvals qa $whereSql");
$countStmt->execute($params);
$totalRows  = (int)$countStmt->fetchColumn();
$totalPages = (int)max(1, ceil($totalRows / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

$rows = $pdo->prepare(
    "SELECT qa.id, qa.crew_name, qa.entry_date, qa.total_amount,
            c.company_name, r.rank_name
       FROM quick_approvals qa
       LEFT JOIN companies c ON c.id = qa.company_id
       LEFT JOIN ranks     r ON r.id = qa.rank_id
       $whereSql
       ORDER BY qa.entry_date DESC, qa.id DESC
       LIMIT $perPage OFFSET $offset"
);
$rows->execute($params);
$rows = $rows->fetchAll();

$companies = $pdo->query("SELECT id, company_name FROM companies ORDER BY company_name")->fetchAll();

$qsBase = [];
if ($search !== '')      $qsBase['q']          = $search;
if ($companyId !== null) $qsBase['company_id'] = $companyId;
$baseUrl = url('quick-approvals.php' . ($qsBase ? '?' . http_build_query($qsBase) : ''));

$pageTitle = 'Quick Approvals';
include __DIR__ . '/includes/header.php';
?>

<div class="card">
    <div class="toolbar">
        <h2 class="card-title" style="margin:0">Quick Approvals</h2>
        <a class="btn" href="<?= asset('quick-approval-edit.php') ?>">+ New quick approval</a>
    </div>

    <form method="get" class="filter-bar" novalidate>
        <div class="form-row">
            <input type="search" name="q" value="<?= h($search) ?>"
                   placeholder="Search by name" autocomplete="off">
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
            <a class="btn btn-ghost" href="<?= asset('quick-approvals.php') ?>">Reset</a>
        </div>
    </form>

    <p class="result-count">
        <?= count($rows) ?> of <?= (int)$totalRows ?>
        <?= $totalRows === 1 ? 'record' : 'records' ?>
        <?php if ($totalPages > 1): ?> · Page <?= (int)$page ?> of <?= (int)$totalPages ?><?php endif; ?>.
    </p>

    <?php if (empty($rows)): ?>
        <div class="empty-state">
            <h3>No quick approvals yet</h3>
            <p>Use this page when an approval needs to be recorded before the candidate has a full crew profile.</p>
            <a class="btn" href="<?= asset('quick-approval-edit.php') ?>">+ New quick approval</a>
        </div>
    <?php else: ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Company</th>
                    <th>Rank</th>
                    <th>Entry date</th>
                    <th style="text-align:right">Total</th>
                    <th style="text-align:right">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td>
                            <a href="<?= asset('quick-approval-edit.php?id=' . (int)$r['id']) ?>">
                                <strong><?= h($r['crew_name']) ?></strong>
                            </a>
                        </td>
                        <td><?= h($r['company_name'] ?? '—') ?></td>
                        <td><?= h($r['rank_name']    ?? '—') ?></td>
                        <td><?= h($r['entry_date']   ?? '—') ?></td>
                        <td style="text-align:right; font-weight:600;">
                            <?= h(number_format((float)($r['total_amount'] ?? 0), 2)) ?>
                        </td>
                        <td>
                            <div class="row-actions">
                                <a class="btn btn-secondary btn-sm"
                                   href="<?= asset('quick-approval-edit.php?id=' . (int)$r['id']) ?>">Edit</a>
                                <form method="post">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id"     value="<?= (int)$r['id'] ?>">
                                    <button type="submit" class="btn btn-danger btn-sm"
                                            data-confirm="Delete quick approval for '<?= h($r['crew_name']) ?>' and all its line items?">
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
