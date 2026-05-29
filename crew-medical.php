<?php
/**
 * SVSML-ERP — Crew medical certificates
 *
 * Module 5.
 *
 * Manages crew_medical rows for a single crew member. Each row pins a
 * medical_type_id (FK to dropdown_items category=medical_type — ENG1,
 * Yellow Fever, Indian Medical Certificate, etc.), an issue date, an
 * expiry date and an optional file. The status badge uses dateStatus()
 * on the expiry date.
 *
 * Same upload conventions as crew_documents:
 *   uploads/crew/{crew_id}/medical_{crew_id}_{timestamp}.ext
 *   PDF / JPG / PNG / DOCX, max 10 MB.
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
// POST handlers — add / update / delete
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'update') {
        $rowId         = (int)($_POST['med_id'] ?? 0);
        $medicalTypeId = !empty($_POST['medical_type_id']) ? (int)$_POST['medical_type_id'] : null;
        $issueDate     = trim($_POST['issue_date']  ?? '');
        $expiryDate    = trim($_POST['expiry_date'] ?? '');

        $errors = [];
        if ($action === 'add' && $medicalTypeId === null) $errors[] = 'Medical type is required.';
        if ($issueDate  !== '' && !DateTime::createFromFormat('Y-m-d', $issueDate))  $errors[] = 'Invalid issue date.';
        if ($expiryDate !== '' && !DateTime::createFromFormat('Y-m-d', $expiryDate)) $errors[] = 'Invalid expiry date.';

        $newFilePath = null;
        if (!empty($_FILES['file']['name'])) {
            try {
                $newFilePath = uploadFile('file', 'crew/' . $crewId, 'medical', $crewId);
            } catch (RuntimeException $e) {
                $errors[] = 'Upload failed: ' . $e->getMessage();
            }
        }

        if (!empty($errors)) {
            foreach ($errors as $e) flash('error', $e);
            header('Location: ' . url('crew-medical.php?id=' . $crewId));
            exit;
        }

        if ($action === 'add') {
            $stmt = $pdo->prepare(
                "INSERT INTO crew_medical
                    (crew_id, medical_type_id, issue_date, expiry_date,
                     file_path, created_by, created_at, updated_at)
                 VALUES (:c, :mt, :id, :ed, :fp, :u, NOW(), NOW())"
            );
            $stmt->execute([
                ':c'  => $crewId,
                ':mt' => $medicalTypeId,
                ':id' => $issueDate  !== '' ? $issueDate  : null,
                ':ed' => $expiryDate !== '' ? $expiryDate : null,
                ':fp' => $newFilePath,
                ':u'  => $user['id'],
            ]);
            $newId = (int)$pdo->lastInsertId();
            logActivity(
                $pdo, $user['id'], $newFilePath ? 'upload' : 'create', 'crew_medical', $newId,
                "Added medical for crew {$crewId}"
            );
            flash('success', 'Medical record added.');
        } else {
            $cur = $pdo->prepare("SELECT * FROM crew_medical WHERE id = :i AND crew_id = :c");
            $cur->execute([':i' => $rowId, ':c' => $crewId]);
            $row = $cur->fetch();
            if (!$row) {
                flash('error', 'Medical record not found.');
                header('Location: ' . url('crew-medical.php?id=' . $crewId));
                exit;
            }
            $stmt = $pdo->prepare(
                "UPDATE crew_medical
                    SET medical_type_id = :mt,
                        issue_date      = :id,
                        expiry_date     = :ed,
                        file_path       = :fp,
                        updated_at      = NOW()
                  WHERE id = :i AND crew_id = :c"
            );
            $stmt->execute([
                ':mt' => $medicalTypeId,
                ':id' => $issueDate  !== '' ? $issueDate  : null,
                ':ed' => $expiryDate !== '' ? $expiryDate : null,
                ':fp' => $newFilePath ?? $row['file_path'],
                ':i'  => $rowId,
                ':c'  => $crewId,
            ]);
            logActivity(
                $pdo, $user['id'], $newFilePath ? 'upload' : 'update', 'crew_medical', $rowId,
                "Updated medical for crew {$crewId}"
            );
            flash('success', 'Medical record updated.');
        }
    }

    elseif ($action === 'delete') {
        $rowId = (int)($_POST['med_id'] ?? 0);
        $cur   = $pdo->prepare("SELECT file_path FROM crew_medical WHERE id = :i AND crew_id = :c");
        $cur->execute([':i' => $rowId, ':c' => $crewId]);
        $row = $cur->fetch();
        if ($row) {
            $stmt = $pdo->prepare("DELETE FROM crew_medical WHERE id = :i AND crew_id = :c");
            $stmt->execute([':i' => $rowId, ':c' => $crewId]);
            if (!empty($row['file_path'])) {
                $abs = rtrim(UPLOAD_DIR, '/') . '/' . ltrim($row['file_path'], '/');
                if (is_file($abs)) @unlink($abs);
            }
            logActivity(
                $pdo, $user['id'], 'delete', 'crew_medical', $rowId,
                "Deleted medical for crew {$crewId}"
            );
            flash('success', 'Medical record deleted.');
        }
    }

    header('Location: ' . url('crew-medical.php?id=' . $crewId));
    exit;
}

// -------------------------------------------------------------
// GET — fetch all medical rows for this crew
// -------------------------------------------------------------
$rows = $pdo->prepare(
    "SELECT cm.*, di.label AS medical_type_label
       FROM crew_medical cm
       LEFT JOIN dropdown_items di ON di.id = cm.medical_type_id
      WHERE cm.crew_id = :c
      ORDER BY (di.label = 'Other'), di.label, cm.created_at DESC"
);
$rows->execute([':c' => $crewId]);
$rows = $rows->fetchAll();

$medicalTypes = getDropdownOptions($pdo, 'medical_type', true);

$pageTitle  = 'Medical — ' . $crew['full_name'];
$currentTab = 'medical';
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/crew-tabs.php';
?>

<div class="card">
    <h3 class="card-title">Medical certificates</h3>

    <?php if (empty($rows)): ?>
        <p class="help-text">No medical certificates on file yet.</p>
    <?php else: ?>
        <table class="data-table" style="margin-bottom:14px;">
            <thead>
                <tr>
                    <th>Type</th>
                    <th>Issue</th>
                    <th>Expiry</th>
                    <th>Status</th>
                    <th>File</th>
                    <th style="text-align:right">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <form method="post" enctype="multipart/form-data" style="display:contents">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="update">
                            <input type="hidden" name="med_id" value="<?= (int)$row['id'] ?>">
                            <td>
                                <select name="medical_type_id">
                                    <?php foreach ($medicalTypes as $mt): ?>
                                        <option value="<?= (int)$mt['id'] ?>"
                                            <?= ((string)$row['medical_type_id'] === (string)$mt['id']) ? 'selected' : '' ?>>
                                            <?= h($mt['label']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td><input type="date" name="issue_date"  value="<?= h($row['issue_date']  ?? '') ?>"></td>
                            <td><input type="date" name="expiry_date" value="<?= h($row['expiry_date'] ?? '') ?>"></td>
                            <td><?= dateStatusBadge($row['expiry_date'] ?? null) ?></td>
                            <td>
                                <?php if (!empty($row['file_path'])): ?>
                                    <a class="action-link" target="_blank"
                                       href="<?= asset('uploads/' . $row['file_path']) ?>">View</a>
                                <?php endif; ?>
                                <input type="file" name="file" accept=".pdf,.jpg,.jpeg,.png,.docx"
                                       style="display:block; margin-top:4px; font-size:12px;">
                            </td>
                            <td>
                                <div class="row-actions">
                                    <button type="submit" class="btn btn-secondary btn-sm">Save</button>
                                </div>
                        </form>
                        <form method="post" style="display:inline">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="med_id" value="<?= (int)$row['id'] ?>">
                            <button type="submit" class="btn btn-danger btn-sm"
                                    data-confirm="Delete this medical record? The file will be removed from the server.">
                                Delete
                            </button>
                        </form>
                            </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <details>
        <summary style="cursor:pointer; font-weight:500; color:var(--primary);">
            + Add new medical certificate
        </summary>
        <form method="post" enctype="multipart/form-data" style="margin-top:10px">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="add">
            <div class="form-grid">
                <div class="form-row">
                    <label>Medical type *</label>
                    <select name="medical_type_id" required>
                        <option value="">— Select type —</option>
                        <?php foreach ($medicalTypes as $mt): ?>
                            <option value="<?= (int)$mt['id'] ?>"><?= h($mt['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="help-text">
                        Manage the type list on the
                        <a href="<?= asset('dropdowns.php?cat=medical_type') ?>">Dropdowns page</a>.
                    </p>
                </div>
                <div class="form-row"><label>Issue date</label>  <input type="date" name="issue_date"></div>
                <div class="form-row"><label>Expiry date</label> <input type="date" name="expiry_date"></div>
                <div class="form-row full-row">
                    <label>File (PDF / JPG / PNG / DOCX, max 10 MB)</label>
                    <input type="file" name="file" accept=".pdf,.jpg,.jpeg,.png,.docx">
                </div>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn">Add</button>
                <button type="reset" class="btn btn-secondary">Reset</button>
            </div>
        </form>
    </details>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
