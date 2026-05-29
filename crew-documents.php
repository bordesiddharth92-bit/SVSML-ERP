<?php
/**
 * SVSML-ERP — Crew documents
 *
 * Module 5.
 *
 * Manages the 5 document types defined by the crew_documents.document_type
 * ENUM ('cv','passport','cdc','visa','sid') for a single crew member.
 *
 * Each section shows existing entries (with file links + status badges)
 * and an "Add new" form. Visa section additionally requires a visa_type_id
 * (FK to dropdown_items category=visa_type). Other types take optional
 * document_number + issue_date + expiry_date.
 *
 * File uploads:
 *   uploads/crew/{crew_id}/{type}_{crew_id}_{timestamp}.ext
 *   PDF / JPG / JPEG / PNG / DOCX, max 10 MB (per uploadFile()).
 *
 * On replace (update with new file), the old file_path is left on disk
 * so we never accidentally lose a document; the row points to the new
 * path. Delete removes both the row and the file.
 *
 * Permissions: admin / sub_admin / staff (per matrix).
 * (Crew users will be able to view/upload their own docs in Module 16.)
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

// The 5 sections we render, in display order. Visa is the only one
// that uses visa_type_id; the rest take just document_number + dates.
$sections = [
    'cv'       => ['label' => 'Curriculum Vitae (CV)',   'needs_visa_type' => false, 'has_dates' => false],
    'passport' => ['label' => 'Passport',                 'needs_visa_type' => false, 'has_dates' => true],
    'cdc'      => ['label' => 'CDC',                      'needs_visa_type' => false, 'has_dates' => true],
    'visa'     => ['label' => 'Visa',                     'needs_visa_type' => true,  'has_dates' => true],
    'sid'      => ['label' => 'Seafarer Identity Document (SID)', 'needs_visa_type' => false, 'has_dates' => true],
];

// -------------------------------------------------------------
// POST handlers — add / replace / delete
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';
    $docType = $_POST['document_type'] ?? '';
    if (!isset($sections[$docType])) {
        flash('error', 'Unknown document type.');
        header('Location: ' . url('crew-documents.php?id=' . $crewId));
        exit;
    }

    if ($action === 'add' || $action === 'update') {
        $rowId       = (int)($_POST['doc_id'] ?? 0);
        $docNumber   = trim($_POST['document_number'] ?? '');
        $issueDate   = trim($_POST['issue_date']      ?? '');
        $expiryDate  = trim($_POST['expiry_date']     ?? '');
        $visaTypeId  = ($docType === 'visa' && !empty($_POST['visa_type_id']))
            ? (int)$_POST['visa_type_id'] : null;

        $errors = [];
        if (mb_strlen($docNumber) > 100) $errors[] = 'Document number too long (max 100).';
        if ($issueDate  !== '' && !DateTime::createFromFormat('Y-m-d', $issueDate))  $errors[] = 'Invalid issue date.';
        if ($expiryDate !== '' && !DateTime::createFromFormat('Y-m-d', $expiryDate)) $errors[] = 'Invalid expiry date.';
        if ($docType === 'visa' && $action === 'add' && $visaTypeId === null) {
            $errors[] = 'Visa type is required.';
        }

        // Optional file upload — only validate / move if a file was sent.
        $newFilePath = null;
        if (!empty($_FILES['file']['name'])) {
            try {
                $newFilePath = uploadFile('file', 'crew/' . $crewId, $docType, $crewId);
            } catch (RuntimeException $e) {
                $errors[] = 'Upload failed: ' . $e->getMessage();
            }
        }

        if (!empty($errors)) {
            foreach ($errors as $e) flash('error', $e);
            header('Location: ' . url('crew-documents.php?id=' . $crewId));
            exit;
        }

        if ($action === 'add') {
            $stmt = $pdo->prepare(
                "INSERT INTO crew_documents
                    (crew_id, document_type, visa_type_id, document_number,
                     issue_date, expiry_date, file_path, created_by, created_at, updated_at)
                 VALUES (:c, :t, :vt, :dn, :id, :ed, :fp, :u, NOW(), NOW())"
            );
            $stmt->execute([
                ':c'  => $crewId,
                ':t'  => $docType,
                ':vt' => $visaTypeId,
                ':dn' => $docNumber !== '' ? $docNumber : null,
                ':id' => $issueDate !== '' ? $issueDate : null,
                ':ed' => $expiryDate !== '' ? $expiryDate : null,
                ':fp' => $newFilePath,
                ':u'  => $user['id'],
            ]);
            $newId = (int)$pdo->lastInsertId();
            logActivity(
                $pdo, $user['id'], $newFilePath ? 'upload' : 'create', 'crew_documents', $newId,
                "Added {$docType} for crew {$crewId}"
            );
            flash('success', 'Document added.');
        } else {
            // UPDATE: load current row first so we keep the old file_path
            // when no new file is uploaded.
            $cur = $pdo->prepare("SELECT * FROM crew_documents WHERE id = :i AND crew_id = :c");
            $cur->execute([':i' => $rowId, ':c' => $crewId]);
            $row = $cur->fetch();
            if (!$row) {
                flash('error', 'Document not found.');
                header('Location: ' . url('crew-documents.php?id=' . $crewId));
                exit;
            }
            $stmt = $pdo->prepare(
                "UPDATE crew_documents
                    SET visa_type_id    = :vt,
                        document_number = :dn,
                        issue_date      = :id,
                        expiry_date     = :ed,
                        file_path       = :fp,
                        updated_at      = NOW()
                  WHERE id = :i AND crew_id = :c"
            );
            $stmt->execute([
                ':vt' => $docType === 'visa' ? $visaTypeId : null,
                ':dn' => $docNumber !== '' ? $docNumber : null,
                ':id' => $issueDate !== '' ? $issueDate : null,
                ':ed' => $expiryDate !== '' ? $expiryDate : null,
                ':fp' => $newFilePath ?? $row['file_path'],
                ':i'  => $rowId,
                ':c'  => $crewId,
            ]);
            logActivity(
                $pdo, $user['id'], $newFilePath ? 'upload' : 'update', 'crew_documents', $rowId,
                "Updated {$docType} for crew {$crewId}"
            );
            flash('success', 'Document updated.');
        }
    }

    elseif ($action === 'delete') {
        $rowId = (int)($_POST['doc_id'] ?? 0);
        $cur   = $pdo->prepare("SELECT file_path FROM crew_documents WHERE id = :i AND crew_id = :c");
        $cur->execute([':i' => $rowId, ':c' => $crewId]);
        $row = $cur->fetch();
        if ($row) {
            $stmt = $pdo->prepare("DELETE FROM crew_documents WHERE id = :i AND crew_id = :c");
            $stmt->execute([':i' => $rowId, ':c' => $crewId]);
            // Try to remove the file from disk (best-effort).
            if (!empty($row['file_path'])) {
                $abs = rtrim(UPLOAD_DIR, '/') . '/' . ltrim($row['file_path'], '/');
                if (is_file($abs)) @unlink($abs);
            }
            logActivity(
                $pdo, $user['id'], 'delete', 'crew_documents', $rowId,
                "Deleted {$docType} for crew {$crewId}"
            );
            flash('success', 'Document deleted.');
        }
    }

    header('Location: ' . url('crew-documents.php?id=' . $crewId));
    exit;
}

// -------------------------------------------------------------
// GET — fetch all documents for this crew, grouped by type
// -------------------------------------------------------------
$rows = $pdo->prepare(
    "SELECT cd.*, di.label AS visa_type_label
       FROM crew_documents cd
       LEFT JOIN dropdown_items di ON di.id = cd.visa_type_id
      WHERE cd.crew_id = :c
      ORDER BY cd.document_type, cd.created_at DESC"
);
$rows->execute([':c' => $crewId]);
$rows = $rows->fetchAll();

$grouped = ['cv' => [], 'passport' => [], 'cdc' => [], 'visa' => [], 'sid' => []];
foreach ($rows as $r) {
    if (isset($grouped[$r['document_type']])) {
        $grouped[$r['document_type']][] = $r;
    }
}

$visaTypes = getDropdownOptions($pdo, 'visa_type', true);

$pageTitle  = 'Documents — ' . $crew['full_name'];
$currentTab = 'documents';
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/crew-tabs.php';
?>

<?php foreach ($sections as $type => $sec): ?>
    <div class="card">
        <h3 class="card-title"><?= h($sec['label']) ?></h3>

        <?php if (empty($grouped[$type])): ?>
            <p class="help-text">No <?= h($sec['label']) ?> records yet.</p>
        <?php else: ?>
            <table class="data-table" style="margin-bottom:14px;">
                <thead>
                    <tr>
                        <?php if ($sec['needs_visa_type']): ?><th>Visa type</th><?php endif; ?>
                        <th>Number</th>
                        <?php if ($sec['has_dates']): ?>
                            <th>Issue</th>
                            <th>Expiry</th>
                            <th>Status</th>
                        <?php endif; ?>
                        <th>File</th>
                        <th style="text-align:right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($grouped[$type] as $row): ?>
                        <tr>
                            <form method="post" enctype="multipart/form-data" style="display:contents">
                                <?= csrfField() ?>
                                <input type="hidden" name="action"        value="update">
                                <input type="hidden" name="document_type" value="<?= h($type) ?>">
                                <input type="hidden" name="doc_id"        value="<?= (int)$row['id'] ?>">
                                <?php if ($sec['needs_visa_type']): ?>
                                    <td>
                                        <select name="visa_type_id">
                                            <option value="">— Select —</option>
                                            <?php foreach ($visaTypes as $vt): ?>
                                                <option value="<?= (int)$vt['id'] ?>"
                                                    <?= ((string)$row['visa_type_id'] === (string)$vt['id']) ? 'selected' : '' ?>>
                                                    <?= h($vt['label']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                <?php endif; ?>
                                <td>
                                    <input type="text" name="document_number" maxlength="100"
                                           value="<?= h($row['document_number'] ?? '') ?>">
                                </td>
                                <?php if ($sec['has_dates']): ?>
                                    <td><input type="date" name="issue_date"  value="<?= h($row['issue_date']  ?? '') ?>"></td>
                                    <td><input type="date" name="expiry_date" value="<?= h($row['expiry_date'] ?? '') ?>"></td>
                                    <td><?= dateStatusBadge($row['expiry_date'] ?? null) ?></td>
                                <?php endif; ?>
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
                                <input type="hidden" name="action"        value="delete">
                                <input type="hidden" name="document_type" value="<?= h($type) ?>">
                                <input type="hidden" name="doc_id"        value="<?= (int)$row['id'] ?>">
                                <button type="submit" class="btn btn-danger btn-sm"
                                        data-confirm="Delete this <?= h($sec['label']) ?> record? The file will be removed from the server.">
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
                + Add new <?= h($sec['label']) ?> record
            </summary>
            <form method="post" enctype="multipart/form-data" style="margin-top:10px">
                <?= csrfField() ?>
                <input type="hidden" name="action"        value="add">
                <input type="hidden" name="document_type" value="<?= h($type) ?>">
                <div class="form-grid">
                    <?php if ($sec['needs_visa_type']): ?>
                        <div class="form-row">
                            <label>Visa type *</label>
                            <select name="visa_type_id" required>
                                <option value="">— Select visa type —</option>
                                <?php foreach ($visaTypes as $vt): ?>
                                    <option value="<?= (int)$vt['id'] ?>"><?= h($vt['label']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>
                    <div class="form-row">
                        <label>Document number</label>
                        <input type="text" name="document_number" maxlength="100">
                    </div>
                    <?php if ($sec['has_dates']): ?>
                        <div class="form-row">
                            <label>Issue date</label>
                            <input type="date" name="issue_date">
                        </div>
                        <div class="form-row">
                            <label>Expiry date</label>
                            <input type="date" name="expiry_date">
                        </div>
                    <?php endif; ?>
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
<?php endforeach; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
