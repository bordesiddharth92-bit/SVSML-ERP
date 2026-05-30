<?php
/**
 * SVSML-ERP — Crew Portal: Medical certificates (Module 18 update)
 *
 * Crew can:
 *   - View their own medical records
 *   - Upload a new medical certificate with metadata
 *   - Replace the file on an existing certificate
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $medType = !empty($_POST['medical_type_id']) ? (int)$_POST['medical_type_id'] : null;
        $issue   = trim($_POST['issue_date']  ?? '');
        $expiry  = trim($_POST['expiry_date'] ?? '');

        $errors = [];
        if ($medType === null) $errors[] = 'Medical type is required.';
        if (($e = validateIssueDate($issue))                       !== null) $errors[] = $e;
        if (($e = validateExpiryAfterIssue($expiry, $issue))       !== null) $errors[] = $e;

        $filePath = null;
        if (empty($errors)) {
            try {
                // Look up the type label for the filename (best-effort).
                $st = $pdo->prepare("SELECT label FROM dropdown_items WHERE id = :i");
                $st->execute([':i' => $medType]);
                $typeLabel = (string)$st->fetchColumn();
                $filePath  = saveCrewUpload('file', $crew, 'Medical_' . $typeLabel, true);
            } catch (RuntimeException $upErr) {
                $errors[] = 'File: ' . $upErr->getMessage();
            }
        }

        if (!empty($errors)) {
            foreach ($errors as $e) flash('error', $e);
        } else {
            $stmt = $pdo->prepare(
                "INSERT INTO crew_medical
                    (crew_id, medical_type_id, issue_date, expiry_date, file_path, created_at, updated_at)
                 VALUES (:c, :t, :is, :ex, :fp, NOW(), NOW())"
            );
            $stmt->execute([
                ':c' => $crewId, ':t' => $medType,
                ':is' => $issue !== '' ? $issue : null,
                ':ex' => $expiry !== '' ? $expiry : null,
                ':fp' => $filePath,
            ]);
            $newId = (int)$pdo->lastInsertId();
            logActivity($pdo, null, $filePath ? 'upload' : 'create', 'crew_medical', $newId,
                "Crew {$crewId} uploaded a medical certificate");
            flash('success', 'Medical certificate added.');
        }
        header('Location: ' . url('crew-portal-medical.php'));
        exit;
    }

    if ($action === 'replace_file') {
        $id  = (int)($_POST['med_id'] ?? 0);
        $cur = $pdo->prepare("SELECT cm.*, d.label AS type_label FROM crew_medical cm LEFT JOIN dropdown_items d ON d.id = cm.medical_type_id WHERE cm.id = :i AND cm.crew_id = :c");
        $cur->execute([':i' => $id, ':c' => $crewId]);
        $row = $cur->fetch();
        if (!$row) {
            flash('error', 'Medical record not found.');
        } else {
            try {
                $newPath = saveCrewUpload('file', $crew, 'Medical_' . ($row['type_label'] ?? 'Cert'), false);
                $pdo->prepare("UPDATE crew_medical SET file_path = :f, updated_at = NOW() WHERE id = :i AND crew_id = :c")
                    ->execute([':f' => $newPath, ':i' => $id, ':c' => $crewId]);
                logActivity($pdo, null, 'upload', 'crew_medical', $id,
                    "Crew {$crewId} replaced file on medical {$id}");
                flash('success', 'File replaced.');
            } catch (RuntimeException $e) {
                flash('error', 'Upload failed: ' . $e->getMessage());
            }
        }
        header('Location: ' . url('crew-portal-medical.php'));
        exit;
    }
}

// -------------------------------------------------------------
// GET — list
// -------------------------------------------------------------
$stmt = $pdo->prepare(
    "SELECT cm.*, d.label AS medical_type_label
       FROM crew_medical cm
       LEFT JOIN dropdown_items d ON d.id = cm.medical_type_id
      WHERE cm.crew_id = :c
      ORDER BY cm.expiry_date DESC, cm.id"
);
$stmt->execute([':c' => $crewId]);
$medical = $stmt->fetchAll();

$medicalTypes = $pdo->query(
    "SELECT id, label FROM dropdown_items
      WHERE category = 'medical_type' AND is_active = 1
      ORDER BY sort_order, label"
)->fetchAll();

$pageTitle  = 'My Profile — Medical';
$currentTab = 'medical';
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/crew-portal-hero.php';
include __DIR__ . '/includes/crew-portal-tabs.php';
?>

<div class="card">
    <h3 class="card-title">My medical certificates <small class="help-text">(<?= count($medical) ?>)</small></h3>

    <?php if (empty($medical)): ?>
        <p class="help-text">No medical certificates on file yet. Use the form below to upload one.</p>
    <?php else: ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Type</th>
                    <th>Issue</th>
                    <th>Expiry</th>
                    <th>Status</th>
                    <th>File</th>
                    <th>Replace file</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($medical as $m): ?>
                    <tr>
                        <td><?= h($m['medical_type_label'] ?? '—') ?></td>
                        <td><?= h($m['issue_date']  ?? '—') ?></td>
                        <td><?= h($m['expiry_date'] ?? '—') ?></td>
                        <td><?= dateStatusBadge($m['expiry_date'] ?? null) ?></td>
                        <td>
                            <?php if (!empty($m['file_path'])): ?>
                                <a class="action-link" target="_blank" rel="noopener"
                                   href="<?= asset('uploads/' . $m['file_path']) ?>">View</a>
                            <?php else: ?>
                                <span class="muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <form method="post" enctype="multipart/form-data" style="display:flex; gap:6px; align-items:center;">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="replace_file">
                                <input type="hidden" name="med_id" value="<?= (int)$m['id'] ?>">
                                <input type="file" name="file" required accept=".pdf,.jpg,.jpeg,.png,.docx" style="font-size:12px;">
                                <button type="submit" class="btn btn-secondary btn-sm">Replace</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <p class="help-text" style="margin-top:14px;">
        Need to delete or correct an entry? Please contact SVSML.
    </p>
</div>

<div class="card">
    <h3 class="card-title">Upload a new medical certificate</h3>
    <form method="post" enctype="multipart/form-data" novalidate>
        <?= csrfField() ?>
        <input type="hidden" name="action" value="add">
        <div class="form-grid">
            <div class="form-row">
                <label for="medical_type_id">Medical type *</label>
                <select id="medical_type_id" name="medical_type_id" required>
                    <option value="">— Select —</option>
                    <?php foreach ($medicalTypes as $t): ?>
                        <option value="<?= (int)$t['id'] ?>"><?= h($t['label']) ?></option>
                    <?php endforeach; ?>
                </select>
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
            <button type="submit" class="btn">Add medical record</button>
        </div>
    </form>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
