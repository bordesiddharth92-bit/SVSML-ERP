<?php
/**
 * SVSML-ERP — Vessel add / edit
 *
 * Module 3.
 *
 * Single page that handles both creating a new vessel (?id missing) and
 * editing an existing vessel (?id=N). Fields covered:
 *   - core: vessel_name (required), company_id, imo_number (unique),
 *           lsa_number, grt, kilo_watt, ship_type, ship_flag
 *   - dates: pni_date (required), mlc_date, financial_security_date (required)
 *   - extra: vessel_extra_fields (key/value rows added below the main form)
 *
 * Permissions: admin / sub_admin / staff (per matrix). Crew has no access.
 *
 * All POSTs are CSRF-protected and write to staff_activity via logActivity().
 */

require __DIR__ . '/config/app.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/helpers.php';

requireRole(['admin', 'sub_admin', 'staff']);

$user      = currentUser();
$vesselId  = isset($_GET['id']) && $_GET['id'] !== '' ? (int)$_GET['id'] : 0;
$isEditing = $vesselId > 0;

// -------------------------------------------------------------
// POST: save / add_extra / delete_extra
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action   = $_POST['action'] ?? '';
    $postId   = (int)($_POST['vessel_id'] ?? 0);

    if ($action === 'save') {
        // Gather and validate input.
        $vesselName = trim($_POST['vessel_name'] ?? '');
        $companyId  = isset($_POST['company_id']) && $_POST['company_id'] !== '' ? (int)$_POST['company_id'] : null;
        $imoNumber  = trim($_POST['imo_number']  ?? '');
        $lsaNumber  = trim($_POST['lsa_number']  ?? '');
        $grt        = trim($_POST['grt']         ?? '');
        $kw         = trim($_POST['kilo_watt']   ?? '');
        $shipType   = trim($_POST['ship_type']   ?? '');
        $shipFlag   = trim($_POST['ship_flag']   ?? '');
        $pniDate    = trim($_POST['pni_date']    ?? '');
        $mlcDate    = trim($_POST['mlc_date']    ?? '');
        $finSecDate = trim($_POST['financial_security_date'] ?? '');

        $errors = [];
        if ($vesselName === '')                              $errors[] = 'Vessel name is required.';
        if (mb_strlen($vesselName) > 100)                    $errors[] = 'Vessel name too long (max 100 chars).';
        if ($pniDate === '')                                 $errors[] = 'P&I date is required.';
        if ($finSecDate === '')                              $errors[] = 'Financial security date is required.';
        if ($imoNumber !== '' && mb_strlen($imoNumber) > 50) $errors[] = 'IMO number too long (max 50 chars).';
        if ($grt !== '' && !is_numeric($grt))                $errors[] = 'GRT must be numeric.';
        if ($kw  !== '' && !is_numeric($kw))                 $errors[] = 'Kilo Watt must be numeric.';

        // IMO uniqueness (if provided).
        if (empty($errors) && $imoNumber !== '') {
            $sql = "SELECT id FROM vessels WHERE imo_number = :imo" . ($postId > 0 ? " AND id <> :i" : "") . " LIMIT 1";
            $stmt = $pdo->prepare($sql);
            $bind = [':imo' => $imoNumber];
            if ($postId > 0) $bind[':i'] = $postId;
            $stmt->execute($bind);
            if ($stmt->fetch()) {
                $errors[] = "IMO '{$imoNumber}' already exists on another vessel.";
            }
        }

        if (!empty($errors)) {
            foreach ($errors as $e) flash('error', $e);
            // Re-render the form with the user's submitted values so nothing is lost.
            $isEditing = $postId > 0;
            $vesselId  = $postId;
            $vessel = [
                'id'                      => $postId,
                'vessel_name'             => $vesselName,
                'company_id'              => $companyId,
                'imo_number'              => $imoNumber,
                'lsa_number'              => $lsaNumber,
                'grt'                     => $grt,
                'kilo_watt'               => $kw,
                'ship_type'               => $shipType,
                'ship_flag'               => $shipFlag,
                'pni_date'                => $pniDate,
                'mlc_date'                => $mlcDate,
                'financial_security_date' => $finSecDate,
            ];
            // Skip the GET-time loaders below — fall through to render.
            $skipGetLoad = true;
        } else {
            $params = [
                ':vname' => $vesselName,
                ':cid'   => $companyId,
                ':imo'   => $imoNumber !== '' ? $imoNumber : null,
                ':lsa'   => $lsaNumber !== '' ? $lsaNumber : null,
                ':grt'   => $grt !== '' ? (float)$grt : null,
                ':kw'    => $kw  !== '' ? (float)$kw  : null,
                ':st'    => $shipType !== '' ? $shipType : null,
                ':sf'    => $shipFlag !== '' ? $shipFlag : null,
                ':pni'   => $pniDate,
                ':mlc'   => $mlcDate !== '' ? $mlcDate : null,
                ':fsec'  => $finSecDate,
                ':uid'   => $user['id'],
            ];

            if ($postId > 0) {
                // UPDATE
                $params[':i'] = $postId;
                $stmt = $pdo->prepare(
                    "UPDATE vessels SET
                        vessel_name = :vname, company_id = :cid,
                        imo_number = :imo, lsa_number = :lsa,
                        grt = :grt, kilo_watt = :kw,
                        ship_type = :st, ship_flag = :sf,
                        pni_date = :pni, mlc_date = :mlc,
                        financial_security_date = :fsec
                     WHERE id = :i"
                );
                $stmt->execute($params);
                logActivity(
                    $pdo, $user['id'], 'update', 'vessels', $postId,
                    "Updated vessel '{$vesselName}'"
                );
                flash('success', "Updated '{$vesselName}'.");
                header('Location: ' . url('vessel-edit.php?id=' . $postId));
            } else {
                // INSERT
                $stmt = $pdo->prepare(
                    "INSERT INTO vessels
                        (vessel_name, company_id, imo_number, lsa_number,
                         grt, kilo_watt, ship_type, ship_flag,
                         pni_date, mlc_date, financial_security_date,
                         created_by, created_at, updated_at)
                     VALUES (:vname, :cid, :imo, :lsa,
                             :grt, :kw, :st, :sf,
                             :pni, :mlc, :fsec,
                             :uid, NOW(), NOW())"
                );
                $stmt->execute($params);
                $newId = (int)$pdo->lastInsertId();
                logActivity(
                    $pdo, $user['id'], 'create', 'vessels', $newId,
                    "Added vessel '{$vesselName}'"
                );
                flash('success', "Added '{$vesselName}'.");
                header('Location: ' . url('vessel-edit.php?id=' . $newId));
            }
            exit;
        }
    }

    elseif ($action === 'add_extra' && $postId > 0) {
        $fname = trim($_POST['field_name']  ?? '');
        $fval  = trim($_POST['field_value'] ?? '');
        if ($fname === '') {
            flash('error', 'Field name is required for an extra field.');
        } elseif (mb_strlen($fname) > 100) {
            flash('error', 'Field name too long (max 100 chars).');
        } else {
            // Confirm the vessel exists before inserting.
            $chk = $pdo->prepare("SELECT id FROM vessels WHERE id = :i");
            $chk->execute([':i' => $postId]);
            if ($chk->fetch()) {
                $stmt = $pdo->prepare(
                    "INSERT INTO vessel_extra_fields
                        (vessel_id, field_name, field_value, created_by, created_at)
                     VALUES (:vid, :fn, :fv, :uid, NOW())"
                );
                $stmt->execute([
                    ':vid' => $postId,
                    ':fn'  => $fname,
                    ':fv'  => $fval,
                    ':uid' => $user['id'],
                ]);
                logActivity(
                    $pdo, $user['id'], 'create', 'vessels', $postId,
                    "Added extra field '{$fname}' to vessel {$postId}"
                );
                flash('success', "Added extra field '{$fname}'.");
            }
        }
        header('Location: ' . url('vessel-edit.php?id=' . $postId));
        exit;
    }

    elseif ($action === 'delete_extra' && $postId > 0) {
        $extraId = (int)($_POST['extra_id'] ?? 0);
        if ($extraId > 0) {
            $row = $pdo->prepare(
                "SELECT field_name FROM vessel_extra_fields WHERE id = :i AND vessel_id = :v"
            );
            $row->execute([':i' => $extraId, ':v' => $postId]);
            $fname = $row->fetchColumn();
            if ($fname !== false) {
                $stmt = $pdo->prepare(
                    "DELETE FROM vessel_extra_fields WHERE id = :i AND vessel_id = :v"
                );
                $stmt->execute([':i' => $extraId, ':v' => $postId]);
                logActivity(
                    $pdo, $user['id'], 'delete', 'vessels', $postId,
                    "Deleted extra field '{$fname}' from vessel {$postId}"
                );
                flash('success', "Deleted extra field '{$fname}'.");
            }
        }
        header('Location: ' . url('vessel-edit.php?id=' . $postId));
        exit;
    }
}

// -------------------------------------------------------------
// GET — load existing vessel (or build empty form)
// -------------------------------------------------------------
if (empty($skipGetLoad)) {
    if ($isEditing) {
        $stmt = $pdo->prepare("SELECT * FROM vessels WHERE id = :i");
        $stmt->execute([':i' => $vesselId]);
        $vessel = $stmt->fetch();
        if (!$vessel) {
            flash('error', 'Vessel not found.');
            header('Location: ' . url('vessels.php'));
            exit;
        }
    } else {
        $vessel = [
            'id'                      => 0,
            'vessel_name'             => '',
            'company_id'              => null,
            'imo_number'              => '',
            'lsa_number'              => '',
            'grt'                     => '',
            'kilo_watt'               => '',
            'ship_type'               => '',
            'ship_flag'               => '',
            'pni_date'                => '',
            'mlc_date'                => '',
            'financial_security_date' => '',
        ];
    }
}

// Companies for the company_id select.
$companies = $pdo->query("SELECT id, company_name FROM companies ORDER BY company_name")->fetchAll();

// Extra fields (only when editing — newly created vessels start with none).
$extraFields = [];
if ($isEditing && (int)$vessel['id'] > 0) {
    $stmt = $pdo->prepare(
        "SELECT id, field_name, field_value, created_at
           FROM vessel_extra_fields
          WHERE vessel_id = :v
          ORDER BY field_name"
    );
    $stmt->execute([':v' => (int)$vessel['id']]);
    $extraFields = $stmt->fetchAll();
}

$pageTitle = $isEditing ? ('Edit vessel — ' . $vessel['vessel_name']) : 'Add vessel';
include __DIR__ . '/includes/header.php';
?>

<div class="card">
    <div class="toolbar">
        <h2 class="card-title" style="margin:0">
            <?= $isEditing ? 'Edit vessel' : 'Add new vessel' ?>
        </h2>
        <a class="btn btn-ghost" href="<?= asset('vessels.php') ?>">← Back to vessels</a>
    </div>

    <form method="post" novalidate>
        <?= csrfField() ?>
        <input type="hidden" name="action"    value="save">
        <input type="hidden" name="vessel_id" value="<?= (int)$vessel['id'] ?>">

        <div class="section-title">Vessel Identity</div>
        <div class="form-grid">
            <div class="form-row full-row">
                <label for="vessel_name">Vessel name *</label>
                <input type="text" id="vessel_name" name="vessel_name"
                       value="<?= h($vessel['vessel_name']) ?>" maxlength="100" required>
            </div>
            <div class="form-row">
                <label for="company_id">Company</label>
                <select id="company_id" name="company_id">
                    <option value="">— None —</option>
                    <?php foreach ($companies as $c): ?>
                        <option value="<?= (int)$c['id'] ?>"
                            <?= ((string)$vessel['company_id'] === (string)$c['id']) ? 'selected' : '' ?>>
                            <?= h($c['company_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="help-text">
                    Add new companies on the <a href="<?= asset('companies.php') ?>">Companies</a> page.
                </p>
            </div>
            <div class="form-row">
                <label for="imo_number">IMO number</label>
                <input type="text" id="imo_number" name="imo_number"
                       value="<?= h($vessel['imo_number']) ?>" maxlength="50">
            </div>
            <div class="form-row">
                <label for="lsa_number">LSA number</label>
                <input type="text" id="lsa_number" name="lsa_number"
                       value="<?= h($vessel['lsa_number']) ?>" maxlength="50">
            </div>
            <div class="form-row">
                <label for="grt">GRT</label>
                <input type="number" id="grt" name="grt" step="0.01" min="0"
                       value="<?= h($vessel['grt']) ?>">
            </div>
            <div class="form-row">
                <label for="kilo_watt">Kilo Watt</label>
                <input type="number" id="kilo_watt" name="kilo_watt" step="0.01" min="0"
                       value="<?= h($vessel['kilo_watt']) ?>">
            </div>
            <div class="form-row">
                <label for="ship_type">Ship type</label>
                <?= dropdownSelectByLabel($pdo, 'ship_type', 'ship_type', $vessel['ship_type'], ['blank' => '— Select type —']) ?>
            </div>
            <div class="form-row">
                <label for="ship_flag">Ship flag</label>
                <?= dropdownSelectByLabel($pdo, 'ship_flag', 'ship_flag', $vessel['ship_flag'], ['blank' => '— Select flag —']) ?>
            </div>
        </div>

        <div class="section-title">Statutory Dates</div>
        <div class="form-grid">
            <div class="form-row">
                <label for="pni_date">P&amp;I expiry *</label>
                <input type="date" id="pni_date" name="pni_date"
                       value="<?= h($vessel['pni_date']) ?>" required>
            </div>
            <div class="form-row">
                <label for="financial_security_date">Financial security expiry *</label>
                <input type="date" id="financial_security_date" name="financial_security_date"
                       value="<?= h($vessel['financial_security_date']) ?>" required>
            </div>
            <div class="form-row">
                <label for="mlc_date">MLC expiry</label>
                <input type="date" id="mlc_date" name="mlc_date"
                       value="<?= h($vessel['mlc_date']) ?>">
                <p class="help-text">Optional — leave blank if MLC does not apply.</p>
            </div>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn">
                <?= $isEditing ? 'Save changes' : 'Add vessel' ?>
            </button>
            <button type="reset" class="btn btn-secondary">Reset</button>
            <?php if ($isEditing): ?>
                <a class="btn btn-ghost" href="<?= asset('vessels.php') ?>">Cancel</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<?php if ($isEditing): ?>
    <div class="card">
        <h3 class="card-title">Extra fields</h3>
        <p class="help-text">
            Use this section to record any vessel attribute that isn't part of
            the standard form (e.g. classification society, owner contact,
            internal reference). Stored in <code>vessel_extra_fields</code>.
        </p>

        <form method="post" novalidate>
            <?= csrfField() ?>
            <input type="hidden" name="action"    value="add_extra">
            <input type="hidden" name="vessel_id" value="<?= (int)$vessel['id'] ?>">
            <div class="form-grid">
                <div class="form-row">
                    <label for="field_name">Field name</label>
                    <input type="text" id="field_name" name="field_name"
                           maxlength="100" placeholder="e.g. Classification Society" required>
                </div>
                <div class="form-row">
                    <label for="field_value">Value</label>
                    <input type="text" id="field_value" name="field_value"
                           placeholder="e.g. Lloyd's Register">
                </div>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-secondary">+ Add field</button>
            </div>
        </form>

        <?php if (empty($extraFields)): ?>
            <p class="help-text">No extra fields yet.</p>
        <?php else: ?>
            <table class="data-table" style="margin-top:14px">
                <thead>
                    <tr>
                        <th style="width:30%">Name</th>
                        <th>Value</th>
                        <th style="width:120px; text-align:right">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($extraFields as $ef): ?>
                        <tr>
                            <td><strong><?= h($ef['field_name']) ?></strong></td>
                            <td><?= h($ef['field_value'] ?? '') ?: '<span class="help-text">(empty)</span>' ?></td>
                            <td>
                                <div class="row-actions">
                                    <form method="post">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action"    value="delete_extra">
                                        <input type="hidden" name="vessel_id" value="<?= (int)$vessel['id'] ?>">
                                        <input type="hidden" name="extra_id"  value="<?= (int)$ef['id'] ?>">
                                        <button type="submit" class="btn btn-danger btn-sm"
                                                data-confirm="Delete extra field '<?= h($ef['field_name']) ?>'?">
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
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
