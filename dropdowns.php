<?php
/**
 * SVSML-ERP — Dropdown options manager
 *
 * Module 2.
 *
 * Manages rows in the `dropdown_items` table for the 8 canonical
 * categories (visa_type, ship_type, etc.). Admin / Sub-admin / Staff
 * can add, rename, activate/deactivate and delete options. Crew users
 * have no access to this page (per permissions matrix).
 *
 * Every state change is recorded in `staff_activity` via logActivity().
 * All forms are CSRF-protected.
 */

require __DIR__ . '/config/app.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/helpers.php';

requireRole(['admin', 'sub_admin', 'staff']);

$user       = currentUser();
$categories = getDropdownCategories();

// Resolve which category we're viewing.  Fall back to the first one
// if the query string is missing or unknown.
$activeCat = $_GET['cat'] ?? '';
if (!isset($categories[$activeCat])) {
    $activeCat = array_key_first($categories);
}

// -------------------------------------------------------------
// POST handlers — create / update / toggle / delete
// All redirect back to the same category (Post-Redirect-Get).
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action  = $_POST['action'] ?? '';
    $postCat = $_POST['cat']    ?? $activeCat;

    // Make sure POST always operates on a known category, regardless
    // of what the client sent.
    if (!isset($categories[$postCat])) {
        $postCat = $activeCat;
    }

    if ($action === 'create') {
        $label = trim($_POST['label'] ?? '');
        if ($label === '') {
            flash('error', 'Label cannot be empty.');
        } elseif (mb_strlen($label) > 100) {
            flash('error', 'Label too long (max 100 characters).');
        } else {
            // Reject case-insensitive duplicates within the same category.
            $chk = $pdo->prepare(
                "SELECT id FROM dropdown_items
                  WHERE category = :c AND LOWER(label) = LOWER(:l) LIMIT 1"
            );
            $chk->execute([':c' => $postCat, ':l' => $label]);
            if ($chk->fetch()) {
                flash('error', "'{$label}' already exists in {$categories[$postCat]}.");
            } else {
                $stmt = $pdo->prepare(
                    "INSERT INTO dropdown_items
                        (category, label, is_active, created_by, created_at)
                     VALUES (:c, :l, 1, :u, NOW())"
                );
                $stmt->execute([
                    ':c' => $postCat,
                    ':l' => $label,
                    ':u' => $user['id'],
                ]);
                $newId = (int)$pdo->lastInsertId();
                logActivity(
                    $pdo, $user['id'], 'create', 'dropdowns', $newId,
                    "Added '{$label}' to {$postCat}"
                );
                flash('success', "Added '{$label}'.");
            }
        }
    }

    elseif ($action === 'update') {
        $id    = (int)($_POST['id'] ?? 0);
        $label = trim($_POST['label'] ?? '');
        if ($id <= 0 || $label === '') {
            flash('error', 'Invalid input — label is required.');
        } elseif (mb_strlen($label) > 100) {
            flash('error', 'Label too long (max 100 characters).');
        } else {
            // Reject case-insensitive duplicates (other than this same row).
            $chk = $pdo->prepare(
                "SELECT id FROM dropdown_items
                  WHERE category = :c AND LOWER(label) = LOWER(:l) AND id <> :i
                  LIMIT 1"
            );
            $chk->execute([':c' => $postCat, ':l' => $label, ':i' => $id]);
            if ($chk->fetch()) {
                flash('error', "'{$label}' already exists in {$categories[$postCat]}.");
            } else {
                $stmt = $pdo->prepare(
                    "UPDATE dropdown_items
                        SET label = :l
                      WHERE id = :i AND category = :c"
                );
                $stmt->execute([':l' => $label, ':i' => $id, ':c' => $postCat]);
                if ($stmt->rowCount() > 0) {
                    logActivity(
                        $pdo, $user['id'], 'update', 'dropdowns', $id,
                        "Renamed dropdown_items.id={$id} to '{$label}'"
                    );
                    flash('success', "Renamed to '{$label}'.");
                }
            }
        }
    }

    elseif ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare(
                "UPDATE dropdown_items
                    SET is_active = 1 - is_active
                  WHERE id = :i AND category = :c"
            );
            $stmt->execute([':i' => $id, ':c' => $postCat]);

            $now = $pdo->prepare(
                "SELECT label, is_active FROM dropdown_items WHERE id = :i"
            );
            $now->execute([':i' => $id]);
            if ($row = $now->fetch()) {
                $state = (int)$row['is_active'] ? 'active' : 'inactive';
                logActivity(
                    $pdo, $user['id'], 'update', 'dropdowns', $id,
                    "Set '{$row['label']}' {$state}"
                );
                flash('success', "'{$row['label']}' is now {$state}.");
            }
        }
    }

    elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $row = $pdo->prepare(
                "SELECT label FROM dropdown_items WHERE id = :i AND category = :c"
            );
            $row->execute([':i' => $id, ':c' => $postCat]);
            $label = $row->fetchColumn();
            if ($label !== false) {
                $stmt = $pdo->prepare(
                    "DELETE FROM dropdown_items WHERE id = :i AND category = :c"
                );
                $stmt->execute([':i' => $id, ':c' => $postCat]);
                // FK references in crew_documents.visa_type_id, crew_medical.medical_type_id,
                // client_approvals.description_id, svsml_approvals.description_id and
                // quick_approval_items.description_id are ON DELETE SET NULL,
                // so existing rows are not orphaned — they simply lose the link.
                logActivity(
                    $pdo, $user['id'], 'delete', 'dropdowns', $id,
                    "Deleted '{$label}' from {$postCat}"
                );
                flash('success', "Deleted '{$label}'.");
            }
        }
    }

    header('Location: ' . url('dropdowns.php?cat=' . urlencode($activeCat)));
    exit;
}

// -------------------------------------------------------------
// GET — render the page
// -------------------------------------------------------------
$itemsStmt = $pdo->prepare(
    "SELECT id, label, is_active
       FROM dropdown_items
      WHERE category = :c
      ORDER BY (label = 'Other'), label"
);
$itemsStmt->execute([':c' => $activeCat]);
$items = $itemsStmt->fetchAll();

$pageTitle = 'Dropdowns';
include __DIR__ . '/includes/header.php';
?>

<div class="card">
    <h2 class="card-title">Dropdown options</h2>
    <p class="help-text">
        Manage the option lists used throughout the ERP. Crew users can only
        select from these — they cannot add new options. Inactive options stay
        in the database for audit purposes but no longer appear in dropdowns
        on data-entry forms.
    </p>

    <div class="tabs">
        <?php foreach ($categories as $key => $label): ?>
            <a class="tab<?= $key === $activeCat ? ' active' : '' ?>"
               href="<?= asset('dropdowns.php?cat=' . urlencode($key)) ?>">
                <?= h($label) ?>
            </a>
        <?php endforeach; ?>
    </div>
</div>

<div class="card">
    <h3 class="card-title">Add to <?= h($categories[$activeCat]) ?></h3>
    <form method="post" novalidate>
        <?= csrfField() ?>
        <input type="hidden" name="action" value="create">
        <input type="hidden" name="cat"    value="<?= h($activeCat) ?>">
        <div class="inline-form">
            <input type="text" name="label" placeholder="New option label"
                   maxlength="100" required autocomplete="off">
            <button type="submit" class="btn">Add</button>
        </div>
    </form>
</div>

<div class="card">
    <h3 class="card-title">
        <?= h($categories[$activeCat]) ?>
        <small class="help-text">(<?= count($items) ?> total)</small>
    </h3>

    <?php if (empty($items)): ?>
        <p class="help-text">No options in this category yet. Add one above.</p>
    <?php else: ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width:55%">Label</th>
                    <th style="width:15%">Status</th>
                    <th style="width:30%; text-align:right">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item): ?>
                    <tr>
                        <td>
                            <form method="post" class="inline-form" novalidate>
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="update">
                                <input type="hidden" name="id"  value="<?= (int)$item['id'] ?>">
                                <input type="hidden" name="cat" value="<?= h($activeCat) ?>">
                                <input type="text" name="label"
                                       value="<?= h($item['label']) ?>"
                                       maxlength="100" required>
                                <button type="submit" class="btn btn-secondary btn-sm">Save</button>
                            </form>
                        </td>
                        <td>
                            <?php if ((int)$item['is_active']): ?>
                                <span class="status status-green">Active</span>
                            <?php else: ?>
                                <span class="status status-gray">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="row-actions">
                                <form method="post">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="id"  value="<?= (int)$item['id'] ?>">
                                    <input type="hidden" name="cat" value="<?= h($activeCat) ?>">
                                    <button type="submit" class="btn btn-ghost btn-sm">
                                        <?= (int)$item['is_active'] ? 'Deactivate' : 'Activate' ?>
                                    </button>
                                </form>
                                <form method="post">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id"  value="<?= (int)$item['id'] ?>">
                                    <input type="hidden" name="cat" value="<?= h($activeCat) ?>">
                                    <button type="submit" class="btn btn-danger btn-sm"
                                            data-confirm="Delete '<?= h($item['label']) ?>'? Existing references in other tables will be cleared.">
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
