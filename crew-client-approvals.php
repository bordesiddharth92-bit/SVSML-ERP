<?php
/**
 * SVSML-ERP — Crew client approvals
 *
 * Module 10.
 *
 * Manages client_approvals rows for one crew member. Each row is a single
 * approval line item (e.g. "Medical 6500", "Visa Fee 12000") that the
 * client has approved to pay on behalf of the crew. The table acts as
 * a running ledger and shows a running total at the bottom.
 *
 * Fields per row (per schema):
 *   roll_number    INT (sr no, auto-incremented within this crew)
 *   description_id FK → dropdown_items (category 'approval_description')
 *   cost           DECIMAL(10,2)
 *
 * Permissions: admin / sub_admin / staff (per matrix).
 */

require __DIR__ . '/config/app.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/helpers.php';

requireRole(['admin', 'sub_admin', 'staff']);

$user   = currentUser();
$crewId = isset($_GET['id']) && $_GET['id'] !== '' ? (int)$_GET['id'] : 0;
$crew   = $crewId > 0 ? fetchCrewWithJoins($pdo, $crewId) : null;

if (!$crew) {
    flash('error', 'Crew not found.');
    header('Location: ' . url('crew.php'));
    exit;
}

// -------------------------------------------------------------
// POST handlers
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    /* ---- Add ---- */
    if ($action === 'add') {
        $descId = isset($_POST['description_id']) && $_POST['description_id'] !== ''
            ? (int)$_POST['description_id'] : null;
        $cost   = $_POST['cost'] ?? '';
        $cost   = is_numeric($cost) ? (float)$cost : null;

        $errors = [];
        if ($descId === null) $errors[] = 'Description is required.';
        if ($cost   === null) $errors[] = 'Cost must be a number.';
        if ($cost   !== null && $cost < 0) $errors[] = 'Cost cannot be negative.';

        if (!empty($errors)) {
            foreach ($errors as $e) flash('error', $e);
        } else {
            $next = (int)$pdo->query(
                "SELECT COALESCE(MAX(roll_number), 0) FROM client_approvals WHERE crew_id = " . $crewId
            )->fetchColumn() + 1;

            $stmt = $pdo->prepare(
                "INSERT INTO client_approvals
                    (crew_id, roll_number, description_id, cost, created_by)
                 VALUES (:c, :rn, :d, :co, :u)"
            );
            $stmt->execute([
                ':c'  => $crewId,
                ':rn' => $next,
                ':d'  => $descId,
                ':co' => $cost,
                ':u'  => $user['id'],
            ]);
            $newId = (int)$pdo->lastInsertId();
            logActivity(
                $pdo, $user['id'], 'create', 'client_approvals', $newId,
                "Added client approval for crew {$crewId}: cost={$cost}"
            );
            flash('success', 'Approval line added.');
        }
    }

    /* ---- Update ---- */
    elseif ($action === 'update') {
        $rowId  = (int)($_POST['row_id'] ?? 0);
        $descId = isset($_POST['description_id']) && $_POST['description_id'] !== ''
            ? (int)$_POST['description_id'] : null;
        $cost   = $_POST['cost'] ?? '';
        $cost   = is_numeric($cost) ? (float)$cost : null;

        $errors = [];
        if ($descId === null) $errors[] = 'Description is required.';
        if ($cost   === null) $errors[] = 'Cost must be a number.';
        if ($cost   !== null && $cost < 0) $errors[] = 'Cost cannot be negative.';

        if (!empty($errors)) {
            foreach ($errors as $e) flash('error', $e);
        } else {
            $stmt = $pdo->prepare(
                "UPDATE client_approvals
                    SET description_id = :d, cost = :co,
                        updated_at = CURRENT_TIMESTAMP
                  WHERE id = :i AND crew_id = :c"
            );
            $stmt->execute([
                ':d'  => $descId,
                ':co' => $cost,
                ':i'  => $rowId,
                ':c'  => $crewId,
            ]);
            logActivity(
                $pdo, $user['id'], 'update', 'client_approvals', $rowId,
                "Updated client approval {$rowId} for crew {$crewId}"
            );
            flash('success', 'Approval line updated.');
        }
    }

    /* ---- Delete ---- */
    elseif ($action === 'delete') {
        $rowId = (int)($_POST['row_id'] ?? 0);
        $stmt = $pdo->prepare(
            "DELETE FROM client_approvals WHERE id = :i AND crew_id = :c"
        );
        $stmt->execute([':i' => $rowId, ':c' => $crewId]);
        if ($stmt->rowCount() > 0) {
            logActivity(
                $pdo, $user['id'], 'delete', 'client_approvals', $rowId,
                "Deleted client approval {$rowId} for crew {$crewId}"
            );
            flash('success', 'Approval line deleted.');
        }
    }

    header('Location: ' . url('crew-client-approvals.php?id=' . $crewId));
    exit;
}

// -------------------------------------------------------------
// GET — load all approvals
// -------------------------------------------------------------
$rows = $pdo->prepare(
    "SELECT ca.*, d.label AS description_label
       FROM client_approvals ca
       LEFT JOIN dropdown_items d ON d.id = ca.description_id
      WHERE ca.crew_id = :c
      ORDER BY ca.roll_number, ca.id"
);
$rows->execute([':c' => $crewId]);
$rows = $rows->fetchAll();

$total = 0.0;
foreach ($rows as $r) $total += (float)$r['cost'];

$pageTitle  = 'Client approvals — ' . $crew['full_name'];
$currentTab = 'client_approvals';
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/crew-tabs.php';
?>

<div class="card">
    <h3 class="card-title">Client approvals <small class="help-text">(<?= count($rows) ?>)</small></h3>

    <?php if (empty($rows)): ?>
        <p class="help-text">No approval lines yet. Add one below.</p>
    <?php else: ?>
        <table class="data-table" style="margin-bottom:14px;">
            <thead>
                <tr>
                    <th style="width:6%">#</th>
                    <th style="width:50%">Description</th>
                    <th style="width:24%">Cost</th>
                    <th style="text-align:right">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <form method="post" style="display:contents">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="update">
                            <input type="hidden" name="row_id" value="<?= (int)$row['id'] ?>">
                            <td><strong><?= (int)$row['roll_number'] ?></strong></td>
                            <td><?= dropdownSelect($pdo, 'description_id', 'approval_description', $row['description_id'], ['required' => true]) ?></td>
                            <td><input type="number" step="0.01" min="0" name="cost" value="<?= h(number_format((float)$row['cost'], 2, '.', '')) ?>" required></td>
                            <td>
                                <div class="row-actions">
                                    <button type="submit" class="btn btn-secondary btn-sm">Save</button>
                        </form>
                        <form method="post" style="display:inline">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="row_id" value="<?= (int)$row['id'] ?>">
                            <button type="submit" class="btn btn-danger btn-sm"
                                    data-confirm="Delete this approval line?">Delete</button>
                        </form>
                                </div>
                            </td>
                    </tr>
                <?php endforeach; ?>
                <tr>
                    <td colspan="2" style="text-align:right; font-weight:600;">Total</td>
                    <td colspan="2" style="font-weight:700; font-size:15px;">
                        <?= h(number_format($total, 2)) ?>
                    </td>
                </tr>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<div class="card">
    <h3 class="card-title">Add approval line</h3>
    <form method="post" novalidate>
        <?= csrfField() ?>
        <input type="hidden" name="action" value="add">
        <div class="form-grid">
            <div class="form-row">
                <label for="description_id">Description *</label>
                <?= dropdownSelect($pdo, 'description_id', 'approval_description', null, ['required' => true]) ?>
                <p class="help-text">Manage choices in <a href="<?= asset('dropdowns.php?category=approval_description') ?>">Settings → Dropdowns</a>.</p>
            </div>
            <div class="form-row">
                <label for="cost">Cost *</label>
                <input type="number" step="0.01" min="0" id="cost" name="cost" required>
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn">Add line</button>
            <button type="reset"  class="btn btn-secondary">Reset</button>
        </div>
    </form>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
