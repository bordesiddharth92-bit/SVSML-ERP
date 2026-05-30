<?php
/**
 * SVSML-ERP — Crew Portal: Documents (Module 18 update)
 *
 * Crew can:
 *   - View their own documents (CV, Passport, CDC, Visa, SID)
 *   - Upload a new document of any type with metadata
 *   - Replace the file on an existing document
 *   - Cannot delete (only admin/staff can)
 */

require __DIR__ . '/config/app.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/helpers.php';

requireOnboardedCrew($pdo);
$crewId = currentCrewId();
$crew   = $crewId ? fetchCrewWithJoins($pdo, $crewId) : null;
if (!$crew) { logoutCurrentUser(); header('Location: ' . url('crew-login.php')); exit; }

$latestContract = fetchLatestContractForCrew($pdo, $crewId);

// Map doc types → human label and the canonical doctype slug used in
// the upload filename. Visa is special — it has a separate dropdown.
$typeLabels = [
    'cv'       => 'CV / Resume',
    'passport' => 'Passport',
    'cdc'      => 'CDC',
    'visa'     => 'Visa',
    'sid'      => 'SID',
];

// -------------------------------------------------------------
// POST: add OR update file on an existing row
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action  = $_POST['action'] ?? '';

    if ($action === 'add') {
        $type     = $_POST['document_type'] ?? '';
        if (!isset($typeLabels[$type])) {
            flash('error', 'Invalid document type.');
        } else {
            $number   = trim($_POST['document_number'] ?? '');
            $issue    = trim($_POST['issue_date']      ?? '');
            $expiry   = trim($_POST['expiry_date']     ?? '');
            $visaType = ($type === 'visa' && !empty($_POST['visa_type_id']))
                ? (int)$_POST['visa_type_id'] : null;

            // Type-specific number normalisation.
            if ($type === 'cdc')      $number = normalizeUpperTrim($number);
            elseif ($type === 'passport') $number = normalizeUpperTrim($number);

            $errors = [];
            if ($number !== '' && $type === 'cdc'      && ($e = validateCDCField($number))      !== null) $errors[] = $e;
            if ($number !== '' && $type === 'passport' && ($e = validatePassportField($number)) !== null) $errors[] = $e;
            if (($e = validateIssueDate($issue))                       !== null) $errors[] = $e;
            if (($e = validateExpiryAfterIssue($expiry, $issue))       !== null) $errors[] = $e;

            $filePath = null;
            if (empty($errors)) {
                try {
                    $docTypeForFile = ucfirst($type) . ($number !== '' ? ('_' . $number) : '');
                    $filePath = saveCrewUpload('file', $crew, $docTypeForFile, true);
                } catch (RuntimeException $upErr) {
                    $errors[] = 'File: ' . $upErr->getMessage();
                }
            }

            if (!empty($errors)) {
                foreach ($errors as $e) flash('error', $e);
            } else {
                $stmt = $pdo->prepare(
                    "INSERT INTO crew_documents
                        (crew_id, document_type, visa_type_id, document_number,
                         issue_date, expiry_date, file_path, created_at, updated_at)
                     VALUES (:c, :t, :vt, :n, :is, :ex, :fp, NOW(), NOW())"
                );
                $stmt->execute([
                    ':c'  => $crewId,
                    ':t'  => $type,
                    ':vt' => $visaType,
                    ':n'  => $number !== '' ? $number : null,
                    ':is' => $issue  !== '' ? $issue  : null,
                    ':ex' => $expiry !== '' ? $expiry : null,
                    ':fp' => $filePath,
                ]);
                $newId = (int)$pdo->lastInsertId();
                logActivity(
                    $pdo, null, $filePath ? 'upload' : 'create',
                    'crew_documents', $newId,
                    "Crew {$crewId} uploaded a {$type} document"
                );
                flash('success', 'Document added.');
            }
        }
        header('Location: ' . url('crew-portal-documents.php'));
        exit;
    }

    /* ---- Replace file on an existing document the crew owns ---- */
    if ($action === 'replace_file') {
        $docId = (int)($_POST['doc_id'] ?? 0);
        $cur   = $pdo->prepare("SELECT * FROM crew_documents WHERE id = :i AND crew_id = :c");
        $cur->execute([':i' => $docId, ':c' => $crewId]);
        $row = $cur->fetch();
        if (!$row) {
            flash('error', 'Document not found.');
        } else {
            try {
                $type    = $row['document_type'];
                $docTypeForFile = ucfirst($type) . (!empty($row['document_number']) ? ('_' . $row['document_number']) : '');
                $newPath = saveCrewUpload('file', $crew, $docTypeForFile, false);
                $pdo->prepare("UPDATE crew_documents SET file_path = :f, updated_at = NOW() WHERE id = :i AND crew_id = :c")
                    ->execute([':f' => $newPath, ':i' => $docId, ':c' => $crewId]);
                logActivity($pdo, null, 'upload', 'crew_documents', $docId,
                    "Crew {$crewId} replaced file on document {$docId}");
                flash('success', 'File replaced.');
            } catch (RuntimeException $e) {
                flash('error', 'Upload failed: ' . $e->getMessage());
            }
        }
        header('Location: ' . url('crew-portal-documents.php'));
        exit;
    }
}

// -------------------------------------------------------------
// GET — list
// -------------------------------------------------------------
$stmt = $pdo->prepare(
    "SELECT cd.*, d.label AS visa_type_label
       FROM crew_documents cd
       LEFT JOIN dropdown_items d ON d.id = cd.visa_type_id
      WHERE cd.crew_id = :c
      ORDER BY cd.document_type, cd.id"
);
$stmt->execute([':c' => $crewId]);
$documents = $stmt->fetchAll();

$grouped = ['cv' => [], 'passport' => [], 'cdc' => [], 'visa' => [], 'sid' => []];
foreach ($documents as $d) {
    $type = $d['document_type'] ?? 'cv';
    if (!isset($grouped[$type])) $grouped[$type] = [];
    $grouped[$type][] = $d;
}

// Visa types for the add-form select.
$visaTypes = $pdo->query(
    "SELECT id, label FROM dropdown_items
      WHERE category = 'visa_type' AND is_active = 1
      ORDER BY id ASC"
)->fetchAll();

$pageTitle  = 'My Profile — Documents';
$currentTab = 'documents';
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/crew-portal-hero.php';
include __DIR__ . '/includes/crew-portal-tabs.php';
?>

<div class="card">
    <h3 class="card-title">My documents <small class="help-text">(<?= count($documents) ?>)</small></h3>

    <?php if (empty($documents)): ?>
        <p class="help-text">No documents on file yet. Use the form below to upload your first one.</p>
    <?php else: ?>
        <?php foreach ($typeLabels as $type => $label): ?>
            <?php $rows = $grouped[$type] ?? []; if (empty($rows)) continue; ?>
            <div class="section-title"><?= h($label) ?> (<?= count($rows) ?>)</div>
            <table class="data-table">
                <thead>
                    <tr>
                        <?php if ($type === 'visa'): ?><th>Visa type</th><?php endif; ?>
                        <th>Number</th>
                        <th>Issue</th>
                        <th>Expiry</th>
                        <th>Status</th>
                        <th>File</th>
                        <th>Replace file</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $d): ?>
                        <tr>
                            <?php if ($type === 'visa'): ?>
                                <td><?= h($d['visa_type_label'] ?? '—') ?></td>
                            <?php endif; ?>
                            <td><?= h($d['document_number'] ?? '—') ?></td>
                            <td><?= h($d['issue_date']  ?? '—') ?></td>
                            <td><?= h($d['expiry_date'] ?? '—') ?></td>
                            <td><?= dateStatusBadge($d['expiry_date'] ?? null) ?></td>
                            <td>
                                <?php if (!empty($d['file_path'])): ?>
                                    <a class="action-link" target="_blank" rel="noopener"
                                       href="<?= asset('uploads/' . $d['file_path']) ?>">View</a>
                                <?php else: ?>
                                    <span class="muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <form method="post" enctype="multipart/form-data" style="display:flex; gap:6px; align-items:center;">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="replace_file">
                                    <input type="hidden" name="doc_id" value="<?= (int)$d['id'] ?>">
                                    <input type="file" name="file" required accept=".pdf,.jpg,.jpeg,.png,.docx" style="font-size:12px;">
                                    <button type="submit" class="btn btn-secondary btn-sm">Replace</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endforeach; ?>
    <?php endif; ?>

    <p class="help-text" style="margin-top:14px;">
        Need to delete or correct an entry? Please contact SVSML — only your manning agent can remove documents.
    </p>
</div>

<div class="card">
    <h3 class="card-title">Upload a new document</h3>
    <form method="post" enctype="multipart/form-data" novalidate>
        <?= csrfField() ?>
        <input type="hidden" name="action" value="add">
        <div class="form-grid">
            <div class="form-row">
                <label for="document_type">Document type *</label>
                <select id="document_type" name="document_type" required>
                    <?php foreach ($typeLabels as $type => $lbl): ?>
                        <option value="<?= h($type) ?>"><?= h($lbl) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row" id="visa-type-field" style="display:none;">
                <label for="visa_type_id">Visa type</label>
                <select id="visa_type_id" name="visa_type_id">
                    <option value="">— Select —</option>
                    <?php foreach ($visaTypes as $v): ?>
                        <option value="<?= (int)$v['id'] ?>"><?= h($v['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row">
                <label for="document_number">Document number</label>
                <input type="text" id="document_number" name="document_number" maxlength="100">
            </div>
            <div class="form-row">
                <label for="issue_date">Issue date</label>
                <input type="date" id="issue_date" name="issue_date">
            </div>
            <div class="form-row">
                <label for="expiry_date">Expiry date</label>
                <input type="date" id="expiry_date" name="expiry_date">
            </div>
            <div class="form-row full-row">
                <label for="file">File (PDF / JPG / PNG / DOCX, max 10 MB)</label>
                <input type="file" id="file" name="file" accept=".pdf,.jpg,.jpeg,.png,.docx">
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn">Add document</button>
        </div>
    </form>
</div>

<script>
(function () {
    var typeSel = document.getElementById('document_type');
    var visaBox = document.getElementById('visa-type-field');
    if (!typeSel || !visaBox) return;
    function sync() { visaBox.style.display = typeSel.value === 'visa' ? '' : 'none'; }
    typeSel.addEventListener('change', sync);
    sync();
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
