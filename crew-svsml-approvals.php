<?php
/**
 * SVSML-ERP — Crew SVSML approvals
 *
 * Module 11.
 *
 * Manages svsml_approvals rows for one crew member. Internal billing
 * ledger maintained by SVSML covering its own services / fees per crew.
 *
 * Per row (per schema):
 *   roll_number    INT (sr no)
 *   description_id FK → dropdown_items (category 'approval_description')
 *   total_amount   DECIMAL  - the gross amount agreed
 *   discount       DECIMAL  - any discount given
 *   paid_amount    DECIMAL  - amount received so far
 *   pending_amount DECIMAL  - auto-computed = total - discount - paid
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

/** Read & validate the four money inputs from POST. */
function svsmlReadMoney(array $post): array
{
    $errors = [];
    $vals   = [];
    foreach (['total_amount', 'discount', 'paid_amount'] as $key) {
        $v = $post[$key] ?? '';
        if ($v === '' || $v === null) {
            $vals[$key] = ($key === 'total_amount') ? null : 0.0;
        } elseif (!is_numeric($v)) {
            $errors[] = ucwords(str_replace('_', ' ', $key)) . ' must be a number.';
            $vals[$key] = null;
        } else {
            $vals[$key] = (float)$v;
            if ($vals[$key] < 0) $errors[] = ucwords(str_replace('_', ' ', $key)) . ' cannot be negative.';
        }
    }
    if ($vals['total_amount'] === null) $errors[] = 'Total amount is required.';
    return [$vals, $errors];
}

// -------------------------------------------------------------
// POST handlers
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'update') {
        $descId = isset($_POST['description_id']) && $_POST['description_id'] !== ''
            ? (int)$_POST['description_id'] : null;
        [$money, $errors] = svsmlReadMoney($_POST);
        if ($descId === null) array_unshift($errors, 'Description is required.');

        if (!empty($errors)) {
            foreach ($errors as $e) flash('error', $e);
        } else {
            $pending = max(
                0.0,
                (float)$money['total_amount'] - (float)$money['discount'] - (float)$money['paid_amount']
            );

            if ($action === 'add') {
                $next = (int)$pdo->query(
                    "SELECT COALESCE(MAX(roll_number), 0) FROM svsml_approvals WHERE crew_id = " . $crewId
                )->fetchColumn() + 1;
                $stmt = $pdo->prepare(
                    "INSERT INTO svsml_approvals
                        (crew_id, roll_number, description_id,
                         total_amount, discount, paid_amount, pending_amount, created_by)
                     VALUES (:c, :rn, :d, :t, :ds, :p, :pe, :u)"
                );
                $stmt->execute([
                    ':c'  => $crewId,
                    ':rn' => $next,
                    ':d'  => $descId,
                    ':t'  => $money['total_amount'],
                    ':ds' => $money['discount'],
                    ':p'  => $money['paid_amount'],
                    ':pe' => $pending,
                    ':u'  => $user['id'],
                ]);
                $newId = (int)$pdo->lastInsertId();
                logActivity(
                    $pdo, $user['id'], 'create', 'svsml_approvals', $newId,
                    "Added SVSML approval for crew {$crewId}: total={$money['total_amount']} pending={$pending}"
                );
                flash('success', 'SVSML approval line added.');
            } else {
                $rowId = (int)($_POST['row_id'] ?? 0);
                $stmt = $pdo->prepare(
                    "UPDATE svsml_approvals
                        SET description_id = :d,
                            total_amount   = :t,
                            discount       = :ds,
                            paid_amount    = :p,
                            pending_amount = :pe,
                            updated_at     = CURRENT_TIMESTAMP
                      WHERE id = :i AND crew_id = :c"
                );
                $stmt->execute([
                    ':d'  => $descId,
                    ':t'  => $money['total_amount'],
                    ':ds' => $money['discount'],
                    ':p'  => $money['paid_amount'],
                    ':pe' => $pending,
                    ':i'  => $rowId,
                    ':c'  => $crewId,
                ]);
                logActivity(
                    $pdo, $user['id'], 'update', 'svsml_approvals', $rowId,
                    "Updated SVSML approval {$rowId}: total={$money['total_amount']} pending={$pending}"
                );
                flash('success', 'SVSML approval line updated.');
            }
        }
    }

    elseif ($action === 'delete') {
        $rowId = (int)($_POST['row_id'] ?? 0);
        $stmt = $pdo->prepare("DELETE FROM svsml_approvals WHERE id = :i AND crew_id = :c");
        $stmt->execute([':i' => $rowId, ':c' => $crewId]);
        if ($stmt->rowCount() > 0) {
            logActivity(
                $pdo, $user['id'], 'delete', 'svsml_approvals', $rowId,
                "Deleted SVSML approval {$rowId} for crew {$crewId}"
            );
            flash('success', 'Approval line deleted.');
        }
    }

    header('Location: ' . url('crew-svsml-approvals.php?id=' . $crewId));
    exit;
}

// -------------------------------------------------------------
// GET — load all approvals
// -------------------------------------------------------------
$rows = $pdo->prepare(
    "SELECT sa.*, d.label AS description_label
       FROM svsml_approvals sa
       LEFT JOIN dropdown_items d ON d.id = sa.description_id
      WHERE sa.crew_id = :c
      ORDER BY sa.roll_number, sa.id"
);
$rows->execute([':c' => $crewId]);
$rows = $rows->fetchAll();

$tot = ['total' => 0.0, 'discount' => 0.0, 'paid' => 0.0, 'pending' => 0.0];
foreach ($rows as $r) {
    $tot['total']    += (float)$r['total_amount'];
    $tot['discount'] += (float)$r['discount'];
    $tot['paid']     += (float)$r['paid_amount'];
    $tot['pending']  += (float)$r['pending_amount'];
}

$pageTitle  = 'SVSML approvals — ' . $crew['full_name'];
$currentTab = 'svsml_approvals';
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/crew-tabs.php';
?>

<div class="card">
    <h3 class="card-title">SVSML approvals <small class="help-text">(<?= count($rows) ?>)</small></h3>

    <?php if (empty($rows)): ?>
        <p class="help-text">No approval lines yet. Add one below.</p>
    <?php else: ?>
        <table class="data-table" style="margin-bottom:14px;">
            <thead>
                <tr>
                    <th style="width:5%">#</th>
                    <th style="width:30%">Description</th>
                    <th style="width:13%">Total</th>
                    <th style="width:13%">Discount</th>
                    <th style="width:13%">Paid</th>
                    <th style="width:13%">Pending</th>
                    <th style="text-align:right">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                    <?php $pendCls = ((float)$row['pending_amount'] > 0) ? 'status-yellow' : 'status-green'; ?>
                    <tr>
                        <form method="post" style="display:contents">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="update">
                            <input type="hidden" name="row_id" value="<?= (int)$row['id'] ?>">
                            <td><strong><?= (int)$row['roll_number'] ?></strong></td>
                            <td><?= dropdownSelect($pdo, 'description_id', 'approval_description', $row['description_id'], ['required' => true]) ?></td>
                            <td><input type="number" step="0.01" min="0" name="total_amount" value="<?= h(number_format((float)$row['total_amount'], 2, '.', '')) ?>" required></td>
                            <td><input type="number" step="0.01" min="0" name="discount"     value="<?= h(number_format((float)$row['discount'],     2, '.', '')) ?>"></td>
                            <td><input type="number" step="0.01" min="0" name="paid_amount"  value="<?= h(number_format((float)$row['paid_amount'],  2, '.', '')) ?>"></td>
                            <td><span class="status <?= $pendCls ?>"><?= h(number_format((float)$row['pending_amount'], 2)) ?></span></td>
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
                    <td colspan="2" style="text-align:right; font-weight:600;">Totals</td>
                    <td style="font-weight:700;"><?= h(number_format($tot['total'],    2)) ?></td>
                    <td style="font-weight:700;"><?= h(number_format($tot['discount'], 2)) ?></td>
                    <td style="font-weight:700;"><?= h(number_format($tot['paid'],     2)) ?></td>
                    <td style="font-weight:700;">
                        <span class="status <?= $tot['pending'] > 0 ? 'status-yellow' : 'status-green' ?>">
                            <?= h(number_format($tot['pending'], 2)) ?>
                        </span>
                    </td>
                    <td></td>
                </tr>
            </tbody>
        </table>
        <p class="help-text">Pending is auto-computed: <code>total − discount − paid</code> (clamped to ≥ 0).</p>
    <?php endif; ?>
</div>

<div class="card">
    <h3 class="card-title">Add approval line</h3>
    <form method="post" novalidate>
        <?= csrfField() ?>
        <input type="hidden" name="action" value="add">
        <div class="form-grid">
            <div class="form-row full-row">
                <label for="description_id">Description *</label>
                <?= dropdownSelect($pdo, 'description_id', 'approval_description', null, ['required' => true]) ?>
                <p class="help-text">Manage choices in <a href="<?= asset('dropdowns.php?category=approval_description') ?>">Settings → Dropdowns</a>.</p>
            </div>
            <div class="form-row">
                <label for="total_amount">Total amount *</label>
                <input type="number" step="0.01" min="0" id="total_amount" name="total_amount" required>
            </div>
            <div class="form-row">
                <label for="discount">Discount</label>
                <input type="number" step="0.01" min="0" id="discount" name="discount" value="0.00">
            </div>
            <div class="form-row">
                <label for="paid_amount">Paid amount</label>
                <input type="number" step="0.01" min="0" id="paid_amount" name="paid_amount" value="0.00">
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn">Add line</button>
            <button type="reset"  class="btn btn-secondary">Reset</button>
        </div>
    </form>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
