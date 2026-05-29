<?php
/**
 * SVSML-ERP — Quick Approval add / edit
 *
 * Module 12.
 *
 * Single page handling both create (no ?id) and edit (?id=N) for the
 * quick_approvals header + its quick_approval_items lines.
 *
 * Save flow:
 *   1. Upsert the header.
 *   2. Replace the line items in a single transaction so the page is
 *      idempotent (the operator sees exactly what they posted, no
 *      orphan lines from earlier saves).
 *   3. Recompute total_amount = SUM(items.amount) and persist on header.
 *
 * Permissions: admin / sub_admin / staff.
 */

require __DIR__ . '/config/app.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/helpers.php';

requireRole(['admin', 'sub_admin', 'staff']);

$user      = currentUser();
$id        = isset($_GET['id']) && $_GET['id'] !== '' ? (int)$_GET['id'] : 0;
$isEditing = $id > 0;

// -------------------------------------------------------------
// POST: save
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $postId    = (int)($_POST['id'] ?? 0);
    $isEditing = $postId > 0;

    $crewName  = trim($_POST['crew_name']  ?? '');
    $companyId = isset($_POST['company_id']) && $_POST['company_id'] !== '' ? (int)$_POST['company_id'] : null;
    $rankId    = isset($_POST['rank_id'])    && $_POST['rank_id']    !== '' ? (int)$_POST['rank_id']    : null;
    $entryDate = trim($_POST['entry_date'] ?? '');

    // Read the line items (parallel arrays).
    $itemDescIds = $_POST['item_description_id'] ?? [];
    $itemAmts    = $_POST['item_amount']         ?? [];
    if (!is_array($itemDescIds)) $itemDescIds = [];
    if (!is_array($itemAmts))    $itemAmts    = [];

    $items = [];
    $count = max(count($itemDescIds), count($itemAmts));
    for ($i = 0; $i < $count; $i++) {
        $d = $itemDescIds[$i] ?? '';
        $a = $itemAmts[$i]    ?? '';
        // Skip empty rows (no description AND no amount).
        if ($d === '' && trim((string)$a) === '') continue;
        $items[] = [
            'description_id' => $d !== '' ? (int)$d : null,
            'amount'         => is_numeric($a) ? (float)$a : null,
        ];
    }

    $errors = [];
    if ($crewName === '')                                $errors[] = 'Name is required.';
    if (mb_strlen($crewName) > 100)                      $errors[] = 'Name too long (max 100 chars).';
    if ($entryDate !== '' && !DateTime::createFromFormat('Y-m-d', $entryDate)) {
        $errors[] = 'Invalid entry date.';
    }
    foreach ($items as $idx => $it) {
        $n = $idx + 1;
        if ($it['description_id'] === null) $errors[] = "Item #{$n}: description is required.";
        if ($it['amount']         === null) $errors[] = "Item #{$n}: amount is required.";
        if ($it['amount']         !== null && $it['amount'] < 0) $errors[] = "Item #{$n}: amount cannot be negative.";
    }

    if (!empty($errors)) {
        foreach ($errors as $e) flash('error', $e);
        // Fall through and re-render with submitted values.
        $header = [
            'id'         => $postId,
            'crew_name'  => $crewName,
            'company_id' => $companyId,
            'rank_id'    => $rankId,
            'entry_date' => $entryDate,
            'total_amount' => array_sum(array_map(function($i) { return (float)$i['amount']; }, $items)),
        ];
        $itemRows = [];
        foreach ($items as $it) {
            $itemRows[] = [
                'description_id' => $it['description_id'],
                'amount'         => $it['amount'],
                'description_label' => null,
            ];
        }
    } else {
        try {
            $pdo->beginTransaction();
            $totalAmount = 0.0;
            foreach ($items as $it) $totalAmount += (float)$it['amount'];

            if ($isEditing) {
                $stmt = $pdo->prepare(
                    "UPDATE quick_approvals
                        SET crew_name    = :n,
                            company_id   = :co,
                            rank_id      = :r,
                            entry_date   = :ed,
                            total_amount = :ta,
                            updated_at   = CURRENT_TIMESTAMP
                      WHERE id = :i"
                );
                $stmt->execute([
                    ':n'  => $crewName,
                    ':co' => $companyId,
                    ':r'  => $rankId,
                    ':ed' => $entryDate !== '' ? $entryDate : null,
                    ':ta' => $totalAmount,
                    ':i'  => $postId,
                ]);
                $headerId = $postId;
                $pdo->prepare("DELETE FROM quick_approval_items WHERE quick_approval_id = :h")
                    ->execute([':h' => $headerId]);
            } else {
                $stmt = $pdo->prepare(
                    "INSERT INTO quick_approvals
                        (crew_name, company_id, rank_id, entry_date, total_amount, created_by)
                     VALUES (:n, :co, :r, :ed, :ta, :u)"
                );
                $stmt->execute([
                    ':n'  => $crewName,
                    ':co' => $companyId,
                    ':r'  => $rankId,
                    ':ed' => $entryDate !== '' ? $entryDate : null,
                    ':ta' => $totalAmount,
                    ':u'  => $user['id'],
                ]);
                $headerId = (int)$pdo->lastInsertId();
            }

            $ins = $pdo->prepare(
                "INSERT INTO quick_approval_items
                    (quick_approval_id, description_id, amount)
                 VALUES (:h, :d, :a)"
            );
            foreach ($items as $it) {
                $ins->execute([
                    ':h' => $headerId,
                    ':d' => $it['description_id'],
                    ':a' => $it['amount'],
                ]);
            }
            $pdo->commit();

            logActivity(
                $pdo, $user['id'], $isEditing ? 'update' : 'create', 'quick_approvals', $headerId,
                ($isEditing ? 'Updated' : 'Created') . " quick approval '{$crewName}' (" . count($items) . " lines, total {$totalAmount})"
            );
            flash('success', $isEditing ? 'Quick approval updated.' : 'Quick approval created.');
            header('Location: ' . url('quick-approval-edit.php?id=' . $headerId));
            exit;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[SVSML-ERP] quick-approval save: ' . $e->getMessage());
            flash('error', 'Could not save: ' . $e->getMessage());
        }
    }
}

// -------------------------------------------------------------
// GET / re-render after error
// -------------------------------------------------------------
if (!isset($header)) {
    if ($isEditing) {
        $h = $pdo->prepare("SELECT * FROM quick_approvals WHERE id = :i");
        $h->execute([':i' => $id]);
        $header = $h->fetch();
        if (!$header) {
            flash('error', 'Quick approval not found.');
            header('Location: ' . url('quick-approvals.php'));
            exit;
        }
        $itStmt = $pdo->prepare(
            "SELECT qai.*, d.label AS description_label
               FROM quick_approval_items qai
               LEFT JOIN dropdown_items d ON d.id = qai.description_id
              WHERE qai.quick_approval_id = :h
              ORDER BY qai.id"
        );
        $itStmt->execute([':h' => $id]);
        $itemRows = $itStmt->fetchAll();
    } else {
        $header = [
            'id'         => 0,
            'crew_name'  => '',
            'company_id' => null,
            'rank_id'    => null,
            'entry_date' => date('Y-m-d'),
            'total_amount' => 0.0,
        ];
        $itemRows = [];
    }
}

$companies = $pdo->query("SELECT id, company_name FROM companies ORDER BY company_name")->fetchAll();
$ranks     = $pdo->query("SELECT id, rank_name    FROM ranks     ORDER BY rank_name")->fetchAll();

$pageTitle = $isEditing ? 'Edit Quick Approval' : 'New Quick Approval';
include __DIR__ . '/includes/header.php';
?>

<div class="card">
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
        <h2 class="card-title" style="margin:0">
            <?= $isEditing ? 'Edit quick approval' : 'New quick approval' ?>
        </h2>
        <a class="btn btn-ghost btn-sm" href="<?= asset('quick-approvals.php') ?>">← Back to list</a>
    </div>
</div>

<form method="post" novalidate>
    <?= csrfField() ?>
    <input type="hidden" name="id" value="<?= (int)$header['id'] ?>">

    <div class="card">
        <h3 class="card-title">Header</h3>
        <div class="form-grid">
            <div class="form-row">
                <label for="crew_name">Name *</label>
                <input type="text" id="crew_name" name="crew_name" maxlength="100" required
                       value="<?= h($header['crew_name'] ?? '') ?>">
            </div>
            <div class="form-row">
                <label for="entry_date">Entry date</label>
                <input type="date" id="entry_date" name="entry_date" value="<?= h($header['entry_date'] ?? '') ?>">
            </div>
            <div class="form-row">
                <label for="company_id">Company</label>
                <select id="company_id" name="company_id">
                    <option value="">— Select —</option>
                    <?php foreach ($companies as $c): ?>
                        <option value="<?= (int)$c['id'] ?>" <?= ((int)$header['company_id'] === (int)$c['id']) ? 'selected' : '' ?>>
                            <?= h($c['company_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row">
                <label for="rank_id">Rank</label>
                <select id="rank_id" name="rank_id">
                    <option value="">— Select —</option>
                    <?php foreach ($ranks as $r): ?>
                        <option value="<?= (int)$r['id'] ?>" <?= ((int)$header['rank_id'] === (int)$r['id']) ? 'selected' : '' ?>>
                            <?= h($r['rank_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div>

    <div class="card">
        <h3 class="card-title">Line items</h3>
        <p class="help-text">Each row is one line. Use the <em>+ Add row</em> button to add more. Empty rows are ignored on save.</p>

        <table class="data-table" id="qa-items">
            <thead>
                <tr>
                    <th style="width:65%">Description</th>
                    <th style="width:25%">Amount</th>
                    <th style="text-align:right">&nbsp;</th>
                </tr>
            </thead>
            <tbody>
                <?php
                // Always show at least 3 rows so a fresh form has something to type into.
                $rowsToShow = $itemRows;
                while (count($rowsToShow) < 3) {
                    $rowsToShow[] = ['description_id' => null, 'amount' => null];
                }
                foreach ($rowsToShow as $i => $row): ?>
                    <tr>
                        <td><?= dropdownSelect($pdo, 'item_description_id[]', 'approval_description', $row['description_id'] ?? null) ?></td>
                        <td><input type="number" step="0.01" min="0" name="item_amount[]"
                                   value="<?= isset($row['amount']) && $row['amount'] !== null ? h(number_format((float)$row['amount'], 2, '.', '')) : '' ?>"></td>
                        <td style="text-align:right">
                            <button type="button" class="btn btn-secondary btn-sm js-row-remove">Remove</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div style="margin-top:10px;">
            <button type="button" id="qa-add-row" class="btn btn-secondary btn-sm">+ Add row</button>
            <span class="help-text" style="margin-left:14px;">
                Total: <strong id="qa-total"><?= h(number_format((float)($header['total_amount'] ?? 0), 2)) ?></strong>
            </span>
        </div>
    </div>

    <div class="card">
        <div class="form-actions">
            <button type="submit" class="btn"><?= $isEditing ? 'Save changes' : 'Create quick approval' ?></button>
            <a class="btn btn-secondary" href="<?= asset('quick-approvals.php') ?>">Cancel</a>
        </div>
    </div>
</form>

<script>
(function () {
    var tbody = document.querySelector('#qa-items tbody');
    if (!tbody) return;

    function recomputeTotal() {
        var total = 0;
        tbody.querySelectorAll('input[name="item_amount[]"]').forEach(function (el) {
            var v = parseFloat(el.value);
            if (!isNaN(v)) total += v;
        });
        var out = document.getElementById('qa-total');
        if (out) out.textContent = total.toFixed(2);
    }

    document.getElementById('qa-add-row').addEventListener('click', function () {
        var first = tbody.rows[0];
        if (!first) return;
        var clone = first.cloneNode(true);
        clone.querySelectorAll('input, select').forEach(function (el) {
            if (el.tagName === 'SELECT') el.selectedIndex = 0;
            else el.value = '';
        });
        tbody.appendChild(clone);
    });

    tbody.addEventListener('click', function (ev) {
        if (ev.target.classList.contains('js-row-remove')) {
            var tr = ev.target.closest('tr');
            if (tbody.rows.length > 1) {
                tr.remove();
            } else {
                tr.querySelectorAll('input, select').forEach(function (el) {
                    if (el.tagName === 'SELECT') el.selectedIndex = 0;
                    else el.value = '';
                });
            }
            recomputeTotal();
        }
    });

    tbody.addEventListener('input', function (ev) {
        if (ev.target.matches('input[name="item_amount[]"]')) recomputeTotal();
    });

    recomputeTotal();
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
