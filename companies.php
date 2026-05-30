<?php
/**
 * SVSML-ERP — Companies manager
 *
 * Module 3.
 *
 * Maintains the list of client / charterer companies. Used as a foreign
 * key target on vessels.company_id, crew.company_id, sailing_history.company_id
 * and quick_approvals.company_id. All those FKs are ON DELETE SET NULL,
 * so deleting a company is non-destructive — dependent rows simply lose
 * the company pointer. The page surfaces the affected counts in the
 * confirm message so the operator can decide.
 *
 * Permissions: admin / sub_admin / staff (per matrix - "Vessel full CRUD").
 * Crew users have no access.
 *
 * All POSTs are CSRF-protected and write to staff_activity via logActivity().
 */

require __DIR__ . '/config/app.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/helpers.php';

requireRole(['admin', 'sub_admin', 'staff']);

$user = currentUser();

// -------------------------------------------------------------
// POST handlers — create / update / delete
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $name = trim($_POST['company_name'] ?? '');
        if ($name === '') {
            flash('error', 'Company name cannot be empty.');
        } elseif (mb_strlen($name) > 100) {
            flash('error', 'Company name too long (max 100 characters).');
        } else {
            $chk = $pdo->prepare(
                "SELECT id FROM companies WHERE LOWER(company_name) = LOWER(:n) LIMIT 1"
            );
            $chk->execute([':n' => $name]);
            if ($chk->fetch()) {
                flash('error', "'{$name}' already exists.");
            } else {
                $stmt = $pdo->prepare(
                    "INSERT INTO companies (company_name, created_at) VALUES (:n, NOW())"
                );
                $stmt->execute([':n' => $name]);
                $newId = (int)$pdo->lastInsertId();
                // Auto-create the company's upload folder per the path spec.
                ensureCompanyUploadDir($name);
                logActivity(
                    $pdo, $user['id'], 'create', 'companies', $newId,
                    "Added company '{$name}'"
                );
                flash('success', "Added '{$name}'.");
            }
        }
    }

    elseif ($action === 'update') {
        $id   = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['company_name'] ?? '');
        if ($id <= 0 || $name === '') {
            flash('error', 'Invalid input — company name is required.');
        } elseif (mb_strlen($name) > 100) {
            flash('error', 'Company name too long (max 100 characters).');
        } else {
            $chk = $pdo->prepare(
                "SELECT id FROM companies
                  WHERE LOWER(company_name) = LOWER(:n) AND id <> :i LIMIT 1"
            );
            $chk->execute([':n' => $name, ':i' => $id]);
            if ($chk->fetch()) {
                flash('error', "'{$name}' already exists.");
            } else {
                $stmt = $pdo->prepare("UPDATE companies SET company_name = :n WHERE id = :i");
                $stmt->execute([':n' => $name, ':i' => $id]);
                if ($stmt->rowCount() > 0) {
                    logActivity(
                        $pdo, $user['id'], 'update', 'companies', $id,
                        "Renamed company.id={$id} to '{$name}'"
                    );
                    flash('success', "Renamed to '{$name}'.");
                }
            }
        }
    }

    elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $row = $pdo->prepare("SELECT company_name FROM companies WHERE id = :i");
            $row->execute([':i' => $id]);
            $name = $row->fetchColumn();
            if ($name !== false) {
                // Count dependent rows so we can include them in the activity log.
                $vesselCount = (int)$pdo->query(
                    "SELECT COUNT(*) FROM vessels WHERE company_id = " . $id
                )->fetchColumn();
                $crewCount   = (int)$pdo->query(
                    "SELECT COUNT(*) FROM crew WHERE company_id = " . $id
                )->fetchColumn();

                $stmt = $pdo->prepare("DELETE FROM companies WHERE id = :i");
                $stmt->execute([':i' => $id]);

                $details = "Deleted company '{$name}'";
                if ($vesselCount + $crewCount > 0) {
                    $details .= " (cleared on {$vesselCount} vessels, {$crewCount} crew rows)";
                }
                logActivity($pdo, $user['id'], 'delete', 'companies', $id, $details);

                $msg = "Deleted '{$name}'.";
                if ($vesselCount + $crewCount > 0) {
                    $msg .= " {$vesselCount} vessels and {$crewCount} crew records now have no company.";
                }
                flash('success', $msg);
            }
        }
    }

    header('Location: ' . url('companies.php'));
    exit;
}

// -------------------------------------------------------------
// GET — load companies with usage counts
// -------------------------------------------------------------
$companies = $pdo->query("
    SELECT c.id,
           c.company_name,
           (SELECT COUNT(*) FROM vessels v WHERE v.company_id = c.id) AS vessel_count,
           (SELECT COUNT(*) FROM crew    cr WHERE cr.company_id = c.id) AS crew_count
      FROM companies c
     ORDER BY c.company_name
")->fetchAll();

$pageTitle = 'Companies';
include __DIR__ . '/includes/header.php';
?>

<div class="card">
    <h2 class="card-title">Companies</h2>
    <p class="help-text">
        Client / charterer companies. Used when adding vessels and crew, and
        on Quick Approvals. Deleting a company that's already in use will
        clear the company pointer on those vessels and crew (the rows
        themselves are kept).
    </p>
</div>

<div class="card">
    <h3 class="card-title">Add new company</h3>
    <form method="post" novalidate>
        <?= csrfField() ?>
        <input type="hidden" name="action" value="create">
        <div class="inline-form">
            <input type="text" name="company_name" placeholder="e.g. Pacific Marine Services Pte Ltd"
                   maxlength="100" required autocomplete="off">
            <button type="submit" class="btn">Add</button>
        </div>
    </form>
</div>

<div class="card">
    <h3 class="card-title">
        Existing companies
        <small class="help-text">(<?= count($companies) ?> total)</small>
    </h3>

    <?php if (empty($companies)): ?>
        <div class="empty-state">
            <h3>No companies yet</h3>
            <p>Add one above to get started.</p>
        </div>
    <?php else: ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width:55%">Company</th>
                    <th style="width:20%">In use</th>
                    <th style="width:25%; text-align:right">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($companies as $c): ?>
                    <?php
                        $vesselCount = (int)$c['vessel_count'];
                        $crewCount   = (int)$c['crew_count'];
                        $totalUse    = $vesselCount + $crewCount;
                    ?>
                    <tr>
                        <td>
                            <form method="post" class="inline-form" novalidate>
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="update">
                                <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                                <input type="text" name="company_name"
                                       value="<?= h($c['company_name']) ?>"
                                       maxlength="100" required>
                                <button type="submit" class="btn btn-secondary btn-sm">Save</button>
                            </form>
                        </td>
                        <td>
                            <?php if ($totalUse === 0): ?>
                                <span class="status status-gray">Not used</span>
                            <?php else: ?>
                                <span class="status status-green"
                                      title="<?= (int)$vesselCount ?> vessels, <?= (int)$crewCount ?> crew">
                                    <?= (int)$vesselCount ?>v · <?= (int)$crewCount ?>c
                                </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="row-actions">
                                <form method="post">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                                    <?php
                                        $confirmMsg = "Delete '{$c['company_name']}'?";
                                        if ($totalUse > 0) {
                                            $confirmMsg .= " {$vesselCount} vessels and {$crewCount} crew rows will lose their company.";
                                        }
                                    ?>
                                    <button type="submit" class="btn btn-danger btn-sm"
                                            data-confirm="<?= h($confirmMsg) ?>">
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
