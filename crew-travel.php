<?php
/**
 * SVSML-ERP — Crew travel details
 *
 * Module 9.
 *
 * Manages travel_details rows for one crew member. Each row is a single
 * travel segment (e.g. "Joining flight Mumbai → Singapore", "Hotel pickup",
 * "Repatriation flight"). Fields per row:
 *   sr_number, detail_label, departure, arrival,
 *   is_done, final_status ENUM('valid','pending','invalid'),
 *   remarks, file_path (e.g. ticket / boarding pass scan),
 *   field_type ENUM('default','custom').
 *
 * field_type:
 *   - 'default'  : added via the quick-add suggestions (typical labels)
 *   - 'custom'   : added via the "Add custom row" form below.
 *   The distinction is purely audit/UI - both behave the same on edit / delete.
 *
 * sr_number is auto-incremented within the crew's existing rows so the
 * operator gets a usable order number without typing it manually.
 *
 * Permissions:
 *   - View: admin / sub_admin / staff / crew (Module 16 will plug crew in)
 *   - Edit: admin / sub_admin / staff (per matrix)
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

// Common labels for the quick-add suggestions. Operators can also type
// any custom label via the "Add custom row" form.
$defaultLabels = [
    'Joining flight',
    'Hotel pickup',
    'Hotel stay',
    'Hotel drop-off',
    'Sign-on travel',
    'Repatriation flight',
];

// -------------------------------------------------------------
// POST handlers
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    /* ---- Add a row (default label or custom) ---- */
    if ($action === 'add') {
        $detailLabel = trim($_POST['detail_label'] ?? '');
        $fieldType   = ($_POST['field_type'] ?? 'custom') === 'default' ? 'default' : 'custom';
        $departure   = parseTravelDateTimeForStorage($_POST['departure'] ?? '');
        $arrival     = parseTravelDateTimeForStorage($_POST['arrival']   ?? '');
        $remarks     = trim($_POST['remarks']      ?? '');
        $finalStatus = $_POST['final_status'] ?? 'pending';
        if (!in_array($finalStatus, ['valid','pending','invalid'], true)) $finalStatus = 'pending';
        $isDone      = !empty($_POST['is_done']) ? 1 : 0;

        $errors = [];
        if ($detailLabel === '')         $errors[] = 'Label is required.';
        if (mb_strlen($detailLabel) > 100) $errors[] = 'Label too long (max 100 chars).';

        $filePath = null;
        if (empty($errors) && !empty($_FILES['file']['name'])) {
            try {
                $filePath = uploadFile('file', 'crew/' . $crewId, 'travel', $crewId);
            } catch (RuntimeException $e) {
                $errors[] = 'Upload failed: ' . $e->getMessage();
            }
        }

        if (!empty($errors)) {
            foreach ($errors as $e) flash('error', $e);
        } else {
            // Compute next sr_number = current MAX + 1, scoped to this crew.
            $next = (int)$pdo->query(
                "SELECT COALESCE(MAX(sr_number), 0) FROM travel_details WHERE crew_id = " . $crewId
            )->fetchColumn() + 1;

            $stmt = $pdo->prepare(
                "INSERT INTO travel_details
                    (crew_id, sr_number, detail_label, departure, arrival,
                     is_done, final_status, remarks, file_path, field_type, created_by)
                 VALUES (:c, :sr, :dl, :dep, :arr, :id, :fs, :rm, :fp, :ft, :u)"
            );
            $stmt->execute([
                ':c'  => $crewId,
                ':sr' => $next,
                ':dl' => $detailLabel,
                ':dep'=> $departure !== null && $departure !== '' ? $departure : null,
                ':arr'=> $arrival   !== null && $arrival   !== '' ? $arrival   : null,
                ':id' => $isDone,
                ':fs' => $finalStatus,
                ':rm' => $remarks !== '' ? $remarks : null,
                ':fp' => $filePath,
                ':ft' => $fieldType,
                ':u'  => $user['id'],
            ]);
            $newId = (int)$pdo->lastInsertId();
            logActivity(
                $pdo, $user['id'], $filePath ? 'upload' : 'create', 'travel_details', $newId,
                "Added travel '{$detailLabel}' for crew {$crewId}"
            );
            flash('success', "Travel row '{$detailLabel}' added.");
        }
    }

    /* ---- Update existing row ---- */
    elseif ($action === 'update') {
        $rowId       = (int)($_POST['travel_id'] ?? 0);
        $detailLabel = trim($_POST['detail_label'] ?? '');
        $departure   = parseTravelDateTimeForStorage($_POST['departure'] ?? '');
        $arrival     = parseTravelDateTimeForStorage($_POST['arrival']   ?? '');
        $remarks     = trim($_POST['remarks']      ?? '');
        $finalStatus = $_POST['final_status'] ?? 'pending';
        if (!in_array($finalStatus, ['valid','pending','invalid'], true)) $finalStatus = 'pending';
        $isDone      = !empty($_POST['is_done']) ? 1 : 0;

        $errors = [];
        if ($detailLabel === '')           $errors[] = 'Label is required.';
        if (mb_strlen($detailLabel) > 100) $errors[] = 'Label too long (max 100 chars).';

        $cur = $pdo->prepare("SELECT * FROM travel_details WHERE id = :i AND crew_id = :c");
        $cur->execute([':i' => $rowId, ':c' => $crewId]);
        $row = $cur->fetch();
        if (!$row) $errors[] = 'Travel row not found.';

        $filePath = null;
        if (empty($errors) && !empty($_FILES['file']['name'])) {
            try {
                $filePath = uploadFile('file', 'crew/' . $crewId, 'travel', $crewId);
            } catch (RuntimeException $e) {
                $errors[] = 'Upload failed: ' . $e->getMessage();
            }
        }

        if (!empty($errors)) {
            foreach ($errors as $e) flash('error', $e);
        } else {
            $stmt = $pdo->prepare(
                "UPDATE travel_details SET
                    detail_label = :dl, departure = :dep, arrival = :arr,
                    is_done = :id, final_status = :fs, remarks = :rm,
                    file_path = :fp,
                    updated_at = CURRENT_TIMESTAMP
                  WHERE id = :i AND crew_id = :c"
            );
            $stmt->execute([
                ':dl' => $detailLabel,
                ':dep'=> $departure !== null && $departure !== '' ? $departure : null,
                ':arr'=> $arrival   !== null && $arrival   !== '' ? $arrival   : null,
                ':id' => $isDone,
                ':fs' => $finalStatus,
                ':rm' => $remarks !== '' ? $remarks : null,
                ':fp' => $filePath ?? $row['file_path'],
                ':i'  => $rowId,
                ':c'  => $crewId,
            ]);
            logActivity(
                $pdo, $user['id'], $filePath ? 'upload' : 'update', 'travel_details', $rowId,
                "Updated travel '{$detailLabel}' for crew {$crewId}"
            );
            flash('success', 'Travel row updated.');
        }
    }

    /* ---- Delete row ---- */
    elseif ($action === 'delete') {
        $rowId = (int)($_POST['travel_id'] ?? 0);
        $cur   = $pdo->prepare("SELECT detail_label, file_path FROM travel_details WHERE id = :i AND crew_id = :c");
        $cur->execute([':i' => $rowId, ':c' => $crewId]);
        $row = $cur->fetch();
        if ($row) {
            $stmt = $pdo->prepare("DELETE FROM travel_details WHERE id = :i AND crew_id = :c");
            $stmt->execute([':i' => $rowId, ':c' => $crewId]);
            if (!empty($row['file_path'])) {
                $abs = rtrim(UPLOAD_DIR, '/') . '/' . ltrim($row['file_path'], '/');
                if (is_file($abs)) @unlink($abs);
            }
            logActivity(
                $pdo, $user['id'], 'delete', 'travel_details', $rowId,
                "Deleted travel '{$row['detail_label']}' for crew {$crewId}"
            );
            flash('success', "Deleted '{$row['detail_label']}'.");
        }
    }

    header('Location: ' . url('crew-travel.php?id=' . $crewId));
    exit;
}

// -------------------------------------------------------------
// GET — load all travel rows for this crew
// -------------------------------------------------------------
$rows = $pdo->prepare(
    "SELECT * FROM travel_details WHERE crew_id = :c ORDER BY sr_number, id"
);
$rows->execute([':c' => $crewId]);
$rows = $rows->fetchAll();

$pageTitle  = 'Travel — ' . $crew['full_name'];
$currentTab = 'travel';
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/crew-tabs.php';
?>

<div class="card">
    <h3 class="card-title">Travel segments <small class="help-text">(<?= count($rows) ?>)</small></h3>

    <?php if (empty($rows)): ?>
        <p class="help-text">No travel rows yet. Add one below.</p>
    <?php else: ?>
        <table class="data-table" style="margin-bottom:14px;">
            <thead>
                <tr>
                    <th style="width:5%">#</th>
                    <th style="width:20%">Label</th>
                    <th style="width:14%">Departure</th>
                    <th style="width:14%">Arrival</th>
                    <th style="width:10%">Done</th>
                    <th style="width:11%">Status</th>
                    <th style="width:10%">File</th>
                    <th style="text-align:right">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <form method="post" enctype="multipart/form-data" style="display:contents">
                            <?= csrfField() ?>
                            <input type="hidden" name="action"    value="update">
                            <input type="hidden" name="travel_id" value="<?= (int)$row['id'] ?>">
                            <td><strong><?= (int)$row['sr_number'] ?></strong></td>
                            <td><input type="text" name="detail_label" maxlength="100" required value="<?= h($row['detail_label']) ?>"></td>
                            <td><input type="datetime-local" name="departure" value="<?= h(formatTravelDateTimeForInput($row['departure'] ?? '')) ?>"></td>
                            <td><input type="datetime-local" name="arrival"   value="<?= h(formatTravelDateTimeForInput($row['arrival']   ?? '')) ?>"></td>
                            <td>
                                <label style="font-weight:400;">
                                    <input type="checkbox" name="is_done" value="1" <?= (int)$row['is_done'] ? 'checked' : '' ?>>
                                    done
                                </label>
                            </td>
                            <td>
                                <select name="final_status">
                                    <option value="pending" <?= $row['final_status'] === 'pending' ? 'selected' : '' ?>>Pending</option>
                                    <option value="valid"   <?= $row['final_status'] === 'valid'   ? 'selected' : '' ?>>Valid</option>
                                    <option value="invalid" <?= $row['final_status'] === 'invalid' ? 'selected' : '' ?>>Invalid</option>
                                </select>
                            </td>
                            <td>
                                <?php if (!empty($row['file_path'])): ?>
                                    <a class="action-link" target="_blank" href="<?= asset('uploads/' . $row['file_path']) ?>">View</a>
                                <?php endif; ?>
                                <input type="file" name="file" accept=".pdf,.jpg,.jpeg,.png,.docx" style="display:block; margin-top:4px; font-size:12px;">
                            </td>
                            <td>
                                <div class="row-actions">
                                    <button type="submit" class="btn btn-secondary btn-sm">Save</button>
                                </div>
                        </form>
                        <form method="post" style="display:inline">
                            <?= csrfField() ?>
                            <input type="hidden" name="action"    value="delete">
                            <input type="hidden" name="travel_id" value="<?= (int)$row['id'] ?>">
                            <button type="submit" class="btn btn-danger btn-sm"
                                    data-confirm="Delete travel row '<?= h($row['detail_label']) ?>'?">
                                Delete
                            </button>
                        </form>
                            </td>
                    </tr>
                    <?php if (!empty($row['remarks'])): ?>
                        <tr>
                            <td colspan="8" class="help-text" style="padding-top:0;">
                                Remarks: <?= nl2br(h($row['remarks'])) ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
            </tbody>
        </table>

        <details>
            <summary style="cursor:pointer; font-weight:500; color:var(--primary);">
                Edit row remarks (text-area)
            </summary>
            <?php foreach ($rows as $row): ?>
                <form method="post" style="border-top:1px solid var(--border); padding-top:12px; margin-top:12px;">
                    <?= csrfField() ?>
                    <input type="hidden" name="action"      value="update">
                    <input type="hidden" name="travel_id"   value="<?= (int)$row['id'] ?>">
                    <input type="hidden" name="detail_label" value="<?= h($row['detail_label']) ?>">
                    <input type="hidden" name="departure"    value="<?= h(formatTravelDateTimeForInput($row['departure'] ?? '')) ?>">
                    <input type="hidden" name="arrival"      value="<?= h(formatTravelDateTimeForInput($row['arrival']   ?? '')) ?>">
                    <input type="hidden" name="is_done"      value="<?= (int)$row['is_done'] ?>">
                    <input type="hidden" name="final_status" value="<?= h($row['final_status']) ?>">
                    <div class="form-row">
                        <label>#<?= (int)$row['sr_number'] ?> — <?= h($row['detail_label']) ?> remarks</label>
                        <textarea name="remarks" rows="2"><?= h($row['remarks'] ?? '') ?></textarea>
                    </div>
                    <button type="submit" class="btn btn-secondary btn-sm">Save remarks</button>
                </form>
            <?php endforeach; ?>
        </details>
    <?php endif; ?>
</div>

<div class="card">
    <h3 class="card-title">Quick-add a typical row</h3>
    <p class="help-text">Click a label to insert a default travel row. You can fill in details inline above afterwards.</p>
    <div style="display:flex; flex-wrap:wrap; gap:8px;">
        <?php foreach ($defaultLabels as $lbl): ?>
            <form method="post" style="display:inline">
                <?= csrfField() ?>
                <input type="hidden" name="action"       value="add">
                <input type="hidden" name="field_type"   value="default">
                <input type="hidden" name="detail_label" value="<?= h($lbl) ?>">
                <input type="hidden" name="final_status" value="pending">
                <button type="submit" class="btn btn-secondary btn-sm">+ <?= h($lbl) ?></button>
            </form>
        <?php endforeach; ?>
    </div>
</div>

<div class="card">
    <h3 class="card-title">Add custom travel row</h3>
    <form method="post" enctype="multipart/form-data" novalidate>
        <?= csrfField() ?>
        <input type="hidden" name="action"     value="add">
        <input type="hidden" name="field_type" value="custom">
        <div class="form-grid">
            <div class="form-row full-row"><label>Label *</label> <input type="text" name="detail_label" maxlength="100" required></div>
            <div class="form-row"><label>Departure</label>        <input type="datetime-local" name="departure"></div>
            <div class="form-row"><label>Arrival</label>          <input type="datetime-local" name="arrival"></div>
            <div class="form-row">
                <label>Status</label>
                <select name="final_status">
                    <option value="pending" selected>Pending</option>
                    <option value="valid">Valid</option>
                    <option value="invalid">Invalid</option>
                </select>
            </div>
            <div class="form-row">
                <div class="switch-row" style="padding:0;">
                    <input type="checkbox" id="is_done" name="is_done" value="1">
                    <label for="is_done">Mark as done</label>
                </div>
            </div>
            <div class="form-row full-row"><label>Remarks</label> <textarea name="remarks" rows="2"></textarea></div>
            <div class="form-row full-row">
                <label>File (PDF / JPG / PNG / DOCX, max 10 MB)</label>
                <input type="file" name="file" accept=".pdf,.jpg,.jpeg,.png,.docx">
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn">Add row</button>
            <button type="reset"  class="btn btn-secondary">Reset</button>
        </div>
    </form>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
