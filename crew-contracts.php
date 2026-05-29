<?php
/**
 * SVSML-ERP — Crew contracts
 *
 * Module 8.
 *
 * Manages the contracts table for one crew member.
 *
 * Each contract row carries:
 *   - reference_number (auto-generated SVSML/YYYY/NNN, unique)
 *   - personal context (place_of_birth, home_town, nearest_airport)
 *   - terms (contract_period, total_salary, commencement_date)
 *   - next_of_kin block (5 fields)
 *   - beneficiary block (3 fields)
 *   - photo upload (photo_path)
 *   - DPDP consent fields  (filled by the crew themselves in Module 16)
 *   - svsml_contract_path / generated_at / generated_by  (the PDF this
 *     page produces via contract-generate.php)
 *   - client_contract_path / status (file uploaded by the client side)
 *
 * Permissions:
 *   - Create / edit contract rows                  : admin / sub_admin / staff
 *   - Generate the SVSML PDF                       : admin / sub_admin / staff
 *   - Upload the client contract                   : admin / sub_admin / staff (Module 16 will also let crew)
 *   - DPDP consent                                 : crew only (deferred to Module 16)
 *
 * Generated PDFs land in uploads/contracts/{crew_id}/.
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

$sys             = getSystemSettings($pdo);
$contractPeriods = getDropdownOptions($pdo, 'contract_period', true);
$relationships   = getDropdownOptions($pdo, 'relationship',    true);

// -------------------------------------------------------------
// POST handlers
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    /* ---- Add a new contract row (without PDF generation) ---- */
    if ($action === 'add' || $action === 'update') {
        $rowId = (int)($_POST['contract_id'] ?? 0);

        $contractDate     = trim($_POST['contract_date']            ?? '');
        $placeOfBirth     = trim($_POST['place_of_birth']           ?? '');
        $homeTown         = trim($_POST['home_town']                ?? '');
        $nearestAirport   = trim($_POST['nearest_airport']          ?? '');
        $contractPeriod   = trim($_POST['contract_period']          ?? '');
        $totalSalary      = trim($_POST['total_salary']             ?? '');
        $commencementDate = trim($_POST['commencement_date']        ?? '');
        $nokName          = trim($_POST['next_of_kin_name']         ?? '');
        $nokRel           = trim($_POST['next_of_kin_relationship'] ?? '');
        $nokAddr          = trim($_POST['next_of_kin_address']      ?? '');
        $nokContact       = trim($_POST['next_of_kin_contact']      ?? '');
        $nokEmail         = trim($_POST['next_of_kin_email']        ?? '');
        $benName          = trim($_POST['beneficiary_name']         ?? '');
        $benRel           = trim($_POST['beneficiary_relationship'] ?? '');
        $benPct           = trim($_POST['beneficiary_percentage']   ?? '');

        $errors = [];
        if ($contractDate !== '' && !DateTime::createFromFormat('Y-m-d', $contractDate))             $errors[] = 'Invalid contract date.';
        if ($commencementDate !== '' && !DateTime::createFromFormat('Y-m-d', $commencementDate))     $errors[] = 'Invalid commencement date.';
        if ($totalSalary !== '' && !is_numeric($totalSalary))                                        $errors[] = 'Total salary must be numeric.';
        if ($benPct !== '' && (!is_numeric($benPct) || (float)$benPct < 0 || (float)$benPct > 100))  $errors[] = 'Beneficiary percentage must be 0-100.';
        if ($nokEmail !== '' && !filter_var($nokEmail, FILTER_VALIDATE_EMAIL))                       $errors[] = 'Invalid next-of-kin email.';

        $photoPath = null;
        if (empty($errors) && !empty($_FILES['photo']['name'])) {
            try {
                $photoPath = uploadFile('photo', 'contracts/' . $crewId, 'photo', $crewId);
            } catch (RuntimeException $e) {
                $errors[] = 'Photo upload failed: ' . $e->getMessage();
            }
        }

        if (!empty($errors)) {
            foreach ($errors as $e) flash('error', $e);
            header('Location: ' . url('crew-contracts.php?id=' . $crewId));
            exit;
        }

        $params = [
            ':crew' => $crewId,
            ':cd'   => $contractDate     !== '' ? $contractDate     : null,
            ':pob'  => $placeOfBirth     !== '' ? $placeOfBirth     : null,
            ':ht'   => $homeTown         !== '' ? $homeTown         : null,
            ':na'   => $nearestAirport   !== '' ? $nearestAirport   : null,
            ':cp'   => $contractPeriod   !== '' ? $contractPeriod   : null,
            ':sal'  => $totalSalary      !== '' ? $totalSalary      : null,
            ':com'  => $commencementDate !== '' ? $commencementDate : null,
            ':nn'   => $nokName    !== '' ? $nokName    : null,
            ':nr'   => $nokRel     !== '' ? $nokRel     : null,
            ':nad'  => $nokAddr    !== '' ? $nokAddr    : null,
            ':nc'   => $nokContact !== '' ? $nokContact : null,
            ':ne'   => $nokEmail   !== '' ? $nokEmail   : null,
            ':bn'   => $benName !== '' ? $benName : null,
            ':br'   => $benRel  !== '' ? $benRel  : null,
            ':bp'   => $benPct  !== '' ? $benPct  : null,
            ':uid'  => $user['id'],
        ];

        if ($action === 'add') {
            // New contracts get a fresh reference number per year.
            $ref = nextContractReferenceNumber($pdo);
            $params[':ref']   = $ref;
            $params[':photo'] = $photoPath;
            $stmt = $pdo->prepare(
                "INSERT INTO contracts
                    (crew_id, reference_number, contract_date, place_of_birth, home_town, nearest_airport,
                     contract_period, total_salary, commencement_date,
                     next_of_kin_name, next_of_kin_relationship, next_of_kin_address,
                     next_of_kin_contact, next_of_kin_email,
                     beneficiary_name, beneficiary_relationship, beneficiary_percentage,
                     photo_path, client_contract_status, created_by)
                 VALUES
                    (:crew, :ref, :cd, :pob, :ht, :na,
                     :cp, :sal, :com,
                     :nn, :nr, :nad, :nc, :ne,
                     :bn, :br, :bp,
                     :photo, 'pending', :uid)"
            );
            try {
                $stmt->execute($params);
            } catch (PDOException $e) {
                // Ref number race: ask the user to retry.
                flash('error', 'Reference number collision; please retry. (' . htmlspecialchars($e->getMessage()) . ')');
                header('Location: ' . url('crew-contracts.php?id=' . $crewId));
                exit;
            }
            $newId = (int)$pdo->lastInsertId();
            logActivity(
                $pdo, $user['id'], 'create', 'contracts', $newId,
                "Created contract {$ref} for crew {$crewId}"
            );
            flash('success', "Contract {$ref} created. You can now generate the SVSML PDF.");
        } else {
            // UPDATE - keep existing photo when no new file uploaded.
            $cur = $pdo->prepare("SELECT photo_path FROM contracts WHERE id = :i AND crew_id = :c");
            $cur->execute([':i' => $rowId, ':c' => $crewId]);
            $cur = $cur->fetch();
            if (!$cur) {
                flash('error', 'Contract not found.');
                header('Location: ' . url('crew-contracts.php?id=' . $crewId));
                exit;
            }
            $params[':i']     = $rowId;
            $params[':photo'] = $photoPath ?? $cur['photo_path'];
            $stmt = $pdo->prepare(
                "UPDATE contracts SET
                    contract_date = :cd, place_of_birth = :pob, home_town = :ht,
                    nearest_airport = :na, contract_period = :cp, total_salary = :sal,
                    commencement_date = :com,
                    next_of_kin_name = :nn, next_of_kin_relationship = :nr,
                    next_of_kin_address = :nad, next_of_kin_contact = :nc, next_of_kin_email = :ne,
                    beneficiary_name = :bn, beneficiary_relationship = :br, beneficiary_percentage = :bp,
                    photo_path = :photo,
                    updated_at = CURRENT_TIMESTAMP
                  WHERE id = :i AND crew_id = :crew"
            );
            $stmt->execute($params);
            logActivity(
                $pdo, $user['id'], 'update', 'contracts', $rowId,
                "Updated contract {$rowId} for crew {$crewId}"
            );
            flash('success', 'Contract updated.');
        }
    }

    /* ---- Upload client contract scan ---- */
    elseif ($action === 'upload_client') {
        $rowId = (int)($_POST['contract_id'] ?? 0);
        $errors = [];
        $newPath = null;
        if (empty($_FILES['client_file']['name'])) {
            $errors[] = 'Choose a client contract file.';
        } else {
            try {
                $newPath = uploadFile('client_file', 'contracts/' . $crewId, 'client', $crewId);
            } catch (RuntimeException $e) {
                $errors[] = 'Upload failed: ' . $e->getMessage();
            }
        }
        if (!empty($errors)) {
            foreach ($errors as $e) flash('error', $e);
        } else {
            $stmt = $pdo->prepare(
                "UPDATE contracts
                    SET client_contract_path   = :path,
                        client_contract_status = 'uploaded',
                        updated_at             = CURRENT_TIMESTAMP
                  WHERE id = :i AND crew_id = :c"
            );
            $stmt->execute([':path' => $newPath, ':i' => $rowId, ':c' => $crewId]);
            logActivity(
                $pdo, $user['id'], 'upload', 'contracts', $rowId,
                "Uploaded client contract for crew {$crewId}"
            );
            flash('success', 'Client contract uploaded.');
        }
    }

    /* ---- Delete a contract row ---- */
    elseif ($action === 'delete') {
        $rowId = (int)($_POST['contract_id'] ?? 0);
        $cur = $pdo->prepare(
            "SELECT reference_number, photo_path, svsml_contract_path, client_contract_path
               FROM contracts
              WHERE id = :i AND crew_id = :c"
        );
        $cur->execute([':i' => $rowId, ':c' => $crewId]);
        $row = $cur->fetch();
        if ($row) {
            $stmt = $pdo->prepare("DELETE FROM contracts WHERE id = :i AND crew_id = :c");
            $stmt->execute([':i' => $rowId, ':c' => $crewId]);
            foreach (['photo_path', 'svsml_contract_path', 'client_contract_path'] as $col) {
                if (!empty($row[$col])) {
                    $abs = rtrim(UPLOAD_DIR, '/') . '/' . ltrim($row[$col], '/');
                    if (is_file($abs)) @unlink($abs);
                }
            }
            logActivity(
                $pdo, $user['id'], 'delete', 'contracts', $rowId,
                "Deleted contract {$row['reference_number']} for crew {$crewId}"
            );
            flash('success', "Deleted contract {$row['reference_number']}.");
        }
    }

    header('Location: ' . url('crew-contracts.php?id=' . $crewId));
    exit;
}

// -------------------------------------------------------------
// GET — load all contracts for this crew
// -------------------------------------------------------------
$rows = $pdo->prepare(
    "SELECT * FROM contracts WHERE crew_id = :c ORDER BY id DESC"
);
$rows->execute([':c' => $crewId]);
$rows = $rows->fetchAll();

$pageTitle  = 'Contracts — ' . $crew['full_name'];
$currentTab = 'contract';
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/crew-tabs.php';
?>

<div class="card">
    <h3 class="card-title">Existing contracts <small class="help-text">(<?= count($rows) ?>)</small></h3>

    <?php if (empty($rows)): ?>
        <p class="help-text">No contracts yet. Use the form below to create one.</p>
    <?php else: ?>
        <table class="data-table" style="margin-bottom:14px;">
            <thead>
                <tr>
                    <th>Reference</th>
                    <th>Contract date</th>
                    <th>Period / Salary</th>
                    <th>SVSML PDF</th>
                    <th>Client contract</th>
                    <th>Consent</th>
                    <th style="text-align:right">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><strong><?= h($row['reference_number']) ?></strong></td>
                        <td><?= h($row['contract_date'] ?? '—') ?></td>
                        <td>
                            <?= h($row['contract_period'] ?? '—') ?>
                            <br><small class="help-text"><?= $row['total_salary'] !== null ? h($row['total_salary']) : '—' ?></small>
                        </td>
                        <td>
                            <?php if (!empty($row['svsml_contract_path'])): ?>
                                <a class="action-link" target="_blank" href="<?= asset('uploads/' . $row['svsml_contract_path']) ?>">View PDF</a>
                                <br><small class="help-text">Generated <?= h($row['svsml_contract_generated_at'] ?? '') ?></small>
                            <?php else: ?>
                                <form method="post" action="<?= asset('contract-generate.php') ?>" style="display:inline">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="contract_id" value="<?= (int)$row['id'] ?>">
                                    <input type="hidden" name="crew_id"     value="<?= (int)$crewId ?>">
                                    <button type="submit" class="btn btn-secondary btn-sm">Generate PDF</button>
                                </form>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($row['client_contract_status'] === 'uploaded'): ?>
                                <a class="action-link" target="_blank" href="<?= asset('uploads/' . $row['client_contract_path']) ?>">View</a>
                                <br><span class="status status-green">Uploaded</span>
                            <?php else: ?>
                                <span class="status status-yellow">Pending</span>
                            <?php endif; ?>
                            <form method="post" enctype="multipart/form-data" style="margin-top:6px;">
                                <?= csrfField() ?>
                                <input type="hidden" name="action"      value="upload_client">
                                <input type="hidden" name="contract_id" value="<?= (int)$row['id'] ?>">
                                <input type="file" name="client_file" accept=".pdf,.jpg,.jpeg,.png,.docx" style="font-size:12px;">
                                <button type="submit" class="btn btn-secondary btn-sm" style="margin-top:4px;">Upload</button>
                            </form>
                        </td>
                        <td>
                            <?php if ((int)$row['dpdp_consent']): ?>
                                <span class="status status-green">Given</span>
                                <br><small class="help-text"><?= h($row['dpdp_consent_at'] ?? '') ?></small>
                            <?php else: ?>
                                <span class="status status-gray">Pending</span>
                                <br><small class="help-text">Crew gives consent in Module 16</small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="row-actions">
                                <a class="btn btn-secondary btn-sm" href="<?= asset('crew-contracts.php?id=' . $crewId . '#c-' . (int)$row['id']) ?>">Edit</a>
                                <form method="post" style="display:inline">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action"      value="delete">
                                    <input type="hidden" name="contract_id" value="<?= (int)$row['id'] ?>">
                                    <button type="submit" class="btn btn-danger btn-sm"
                                            data-confirm="Delete contract <?= h($row['reference_number']) ?>? Generated PDF and uploaded files will be removed.">
                                        Delete
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <details>
            <summary style="cursor:pointer; font-weight:500; color:var(--primary);">
                Edit existing contract details
            </summary>
            <?php foreach ($rows as $row): ?>
                <form method="post" enctype="multipart/form-data" id="c-<?= (int)$row['id'] ?>"
                      style="border-top:1px solid var(--border); padding-top:14px; margin-top:14px;">
                    <?= csrfField() ?>
                    <input type="hidden" name="action"      value="update">
                    <input type="hidden" name="contract_id" value="<?= (int)$row['id'] ?>">

                    <h4 style="margin: 0 0 8px;"><?= h($row['reference_number']) ?></h4>

                    <div class="section-title">Contract terms</div>
                    <div class="form-grid">
                        <div class="form-row"><label>Contract date</label>     <input type="date" name="contract_date" value="<?= h($row['contract_date'] ?? '') ?>"></div>
                        <div class="form-row"><label>Commencement date</label> <input type="date" name="commencement_date" value="<?= h($row['commencement_date'] ?? '') ?>"></div>
                        <div class="form-row"><label>Contract period</label>
                            <select name="contract_period">
                                <option value="">— Select —</option>
                                <?php foreach ($contractPeriods as $cp): ?>
                                    <option value="<?= h($cp['label']) ?>" <?= $row['contract_period'] === $cp['label'] ? 'selected' : '' ?>><?= h($cp['label']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-row"><label>Total monthly salary</label> <input type="number" step="0.01" min="0" name="total_salary" value="<?= h($row['total_salary'] ?? '') ?>"></div>
                        <div class="form-row"><label>Place of birth</label>      <input type="text" name="place_of_birth"    value="<?= h($row['place_of_birth'] ?? '') ?>"></div>
                        <div class="form-row"><label>Home town</label>           <input type="text" name="home_town"         value="<?= h($row['home_town']      ?? '') ?>"></div>
                        <div class="form-row"><label>Nearest airport</label>     <input type="text" name="nearest_airport"   value="<?= h($row['nearest_airport'] ?? '') ?>"></div>
                    </div>

                    <div class="section-title">Next of Kin</div>
                    <div class="form-grid">
                        <div class="form-row"><label>Name</label>         <input type="text" name="next_of_kin_name"    value="<?= h($row['next_of_kin_name']    ?? '') ?>"></div>
                        <div class="form-row"><label>Relationship</label>
                            <select name="next_of_kin_relationship">
                                <option value="">— Select —</option>
                                <?php foreach ($relationships as $rel): ?>
                                    <option value="<?= h($rel['label']) ?>" <?= $row['next_of_kin_relationship'] === $rel['label'] ? 'selected' : '' ?>><?= h($rel['label']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-row"><label>Contact</label>      <input type="text"  name="next_of_kin_contact" value="<?= h($row['next_of_kin_contact'] ?? '') ?>"></div>
                        <div class="form-row"><label>Email</label>        <input type="email" name="next_of_kin_email"   value="<?= h($row['next_of_kin_email']   ?? '') ?>"></div>
                        <div class="form-row full-row"><label>Address</label>
                            <textarea name="next_of_kin_address" rows="2"><?= h($row['next_of_kin_address'] ?? '') ?></textarea>
                        </div>
                    </div>

                    <div class="section-title">Beneficiary</div>
                    <div class="form-grid">
                        <div class="form-row"><label>Name</label>          <input type="text" name="beneficiary_name" value="<?= h($row['beneficiary_name'] ?? '') ?>"></div>
                        <div class="form-row"><label>Relationship</label>
                            <select name="beneficiary_relationship">
                                <option value="">— Select —</option>
                                <?php foreach ($relationships as $rel): ?>
                                    <option value="<?= h($rel['label']) ?>" <?= $row['beneficiary_relationship'] === $rel['label'] ? 'selected' : '' ?>><?= h($rel['label']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-row"><label>Percentage (0–100)</label> <input type="number" step="0.01" min="0" max="100" name="beneficiary_percentage" value="<?= h($row['beneficiary_percentage'] ?? '') ?>"></div>
                    </div>

                    <div class="section-title">Photo</div>
                    <?php if (!empty($row['photo_path'])): ?>
                        <p><a target="_blank" href="<?= asset('uploads/' . $row['photo_path']) ?>">View current photo</a></p>
                    <?php endif; ?>
                    <div class="form-row">
                        <input type="file" name="photo" accept=".jpg,.jpeg,.png,.pdf">
                        <p class="help-text">Leave blank to keep the existing photo.</p>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn">Save</button>
                        <?php if (empty($row['svsml_contract_path'])): ?>
                            <span class="help-text">Tip: save first, then click <strong>Generate PDF</strong> in the table above.</span>
                        <?php endif; ?>
                    </div>
                </form>
            <?php endforeach; ?>
        </details>
    <?php endif; ?>
</div>

<div class="card">
    <h3 class="card-title">Add new contract</h3>
    <p class="help-text">
        A reference number (<strong>SVSML/<?= date('Y') ?>/NNN</strong>) will be assigned automatically.
        After saving, click <strong>Generate PDF</strong> in the table above to render the SVSML contract.
    </p>
    <form method="post" enctype="multipart/form-data" novalidate>
        <?= csrfField() ?>
        <input type="hidden" name="action" value="add">

        <div class="section-title">Contract terms</div>
        <div class="form-grid">
            <div class="form-row"><label>Contract date</label>     <input type="date" name="contract_date" value="<?= h(date('Y-m-d')) ?>"></div>
            <div class="form-row"><label>Commencement date</label> <input type="date" name="commencement_date"></div>
            <div class="form-row"><label>Contract period</label>
                <select name="contract_period">
                    <option value="">— Select —</option>
                    <?php foreach ($contractPeriods as $cp): ?>
                        <option value="<?= h($cp['label']) ?>"><?= h($cp['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row"><label>Total monthly salary</label> <input type="number" step="0.01" min="0" name="total_salary"></div>
            <div class="form-row"><label>Place of birth</label>      <input type="text" name="place_of_birth"></div>
            <div class="form-row"><label>Home town</label>           <input type="text" name="home_town"></div>
            <div class="form-row"><label>Nearest airport</label>     <input type="text" name="nearest_airport"></div>
        </div>

        <div class="section-title">Next of Kin</div>
        <div class="form-grid">
            <div class="form-row"><label>Name</label>         <input type="text" name="next_of_kin_name"></div>
            <div class="form-row"><label>Relationship</label>
                <select name="next_of_kin_relationship">
                    <option value="">— Select —</option>
                    <?php foreach ($relationships as $rel): ?>
                        <option value="<?= h($rel['label']) ?>"><?= h($rel['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row"><label>Contact</label>      <input type="text"  name="next_of_kin_contact"></div>
            <div class="form-row"><label>Email</label>        <input type="email" name="next_of_kin_email"></div>
            <div class="form-row full-row"><label>Address</label>
                <textarea name="next_of_kin_address" rows="2"></textarea>
            </div>
        </div>

        <div class="section-title">Beneficiary</div>
        <div class="form-grid">
            <div class="form-row"><label>Name</label>          <input type="text" name="beneficiary_name"></div>
            <div class="form-row"><label>Relationship</label>
                <select name="beneficiary_relationship">
                    <option value="">— Select —</option>
                    <?php foreach ($relationships as $rel): ?>
                        <option value="<?= h($rel['label']) ?>"><?= h($rel['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row"><label>Percentage (0–100)</label> <input type="number" step="0.01" min="0" max="100" name="beneficiary_percentage"></div>
        </div>

        <div class="section-title">Photo (optional)</div>
        <div class="form-row"><input type="file" name="photo" accept=".jpg,.jpeg,.png,.pdf"></div>

        <div class="form-actions">
            <button type="submit" class="btn">Create contract</button>
            <button type="reset"  class="btn btn-secondary">Reset</button>
        </div>
    </form>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
