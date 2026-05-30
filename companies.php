<?php
/**
 * SVSML-ERP — Companies manager
 *
 * Module 3.
 *
 * Maintains the list of client / charterer companies. Used as a foreign
 * key target on vessels.company_id, crew.company_id, sailing_history.company_id
 * and quick_approvals.company_id. All those FKs are ON DELETE SET NULL,
 * so deleting a company is non-destructive — dependent rows simply lose
 * the company pointer. The page surfaces the affected counts in the
 * confirm message so the operator can decide.
 *
 * Each company also carries contact details — address, contact person,
 * contact number and email — captured here and reused on contracts /
 * approvals correspondence. All four are optional; only the company
 * name is mandatory. The contact columns are added by CHANGES.sql
 * (idempotent ALTERs against the `companies` table).
 *
 * Permissions: admin / sub_admin / staff (per matrix - "Vessel full CRUD").
 * Crew users have no access.
 *
 * All POSTs are CSRF-protected and write to staff_activity via logActivity().
 */

require __DIR__ . '/config/app.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/helpers.php';

requireRole(['admin', 'sub_admin', 'staff']);

$user = currentUser();

/**
 * Validate the shared company field set. Returns an error string, or
 * null when the input is acceptable. Trimming is the caller's job.
 */
function companyValidationError(
    string $name,
    string $address,
    string $contactPerson,
    string $contactNumber,
    string $email
): ?string {
    if ($name === '')                    return 'Company name cannot be empty.';
    if (mb_strlen($name) > 100)          return 'Company name too long (max 100 characters).';
    if (mb_strlen($contactPerson) > 120) return 'Contact person too long (max 120 characters).';
    if (mb_strlen($contactNumber) > 40)  return 'Contact number too long (max 40 characters).';
    if (mb_strlen($email) > 150)         return 'Email too long (max 150 characters).';
    if (mb_strlen($address) > 255)       return 'Address too long (max 255 characters).';
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 'Email address is not valid.';
    }
    return null;
}

// -------------------------------------------------------------
// POST handlers — create / update / delete
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $name          = trim($_POST['company_name']   ?? '');
        $address       = trim($_POST['address']        ?? '');
        $contactPerson = trim($_POST['contact_person'] ?? '');
        $contactNumber = trim($_POST['contact_number'] ?? '');
        $email         = trim($_POST['email']          ?? '');

        $err = companyValidationError($name, $address, $contactPerson, $contactNumber, $email);
        if ($err !== null) {
            flash('error', $err);
        } else {
            $chk = $pdo->prepare(
                "SELECT id FROM companies WHERE LOWER(company_name) = LOWER(:n) LIMIT 1"
            );
            $chk->execute([':n' => $name]);
            if ($chk->fetch()) {
                flash('error', "'{$name}' already exists.");
            } else {
                $stmt = $pdo->prepare(
                    "INSERT INTO companies
                        (company_name, address, contact_person, contact_number, email, created_at)
                     VALUES (:n, :ad, :cp, :cn, :em, NOW())"
                );
                $stmt->execute([
                    ':n'  => $name,
                    ':ad' => $address       !== '' ? $address       : null,
                    ':cp' => $contactPerson !== '' ? $contactPerson : null,
                    ':cn' => $contactNumber !== '' ? $contactNumber : null,
                    ':em' => $email         !== '' ? $email         : null,
                ]);
                $newId = (int)$pdo->lastInsertId();
                // Auto-create the company's upload folder per the path spec.
                ensureCompanyUploadDir($name);
                logActivity(
                    $pdo, $user['id'], 'create', 'companies', $newId,
                    "Added company '{$name}'"
                );
                flash('success', "Added '{$name}'.");
            }
        }
    }

    elseif ($action === 'update') {
        $id            = (int)($_POST['id'] ?? 0);
        $name          = trim($_POST['company_name']   ?? '');
        $address       = trim($_POST['address']        ?? '');
        $contactPerson = trim($_POST['contact_person'] ?? '');
        $contactNumber = trim($_POST['contact_number'] ?? '');
        $email         = trim($_POST['email']          ?? '');

        $err = companyValidationError($name, $address, $contactPerson, $contactNumber, $email);
        if ($id <= 0) {
            flash('error', 'Invalid input — company id is required.');
        } elseif ($err !== null) {
            flash('error', $err);
        } else {
            $chk = $pdo->prepare(
                "SELECT id FROM companies
                  WHERE LOWER(company_name) = LOWER(:n) AND id <> :i LIMIT 1"
            );
            $chk->execute([':n' => $name, ':i' => $id]);
            if ($chk->fetch()) {
                flash('error', "'{$name}' already exists.");
            } else {
                $stmt = $pdo->prepare(
                    "UPDATE companies
                        SET company_name   = :n,
                            address        = :ad,
                            contact_person = :cp,
                            contact_number = :cn,
                            email          = :em
                      WHERE id = :i"
                );
                $stmt->execute([
                    ':n'  => $name,
                    ':ad' => $address       !== '' ? $address       : null,
                    ':cp' => $contactPerson !== '' ? $contactPerson : null,
                    ':cn' => $contactNumber !== '' ? $contactNumber : null,
                    ':em' => $email         !== '' ? $email         : null,
                    ':i'  => $id,
                ]);
                logActivity(
                    $pdo, $user['id'], 'update', 'companies', $id,
                    "Updated company '{$name}' (id={$id})"
                );
                flash('success', "Saved '{$name}'.");
            }
        }
    }

    elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $row = $pdo->prepare("SELECT company_name FROM companies WHERE id = :i");
            $row->execute([':i' => $id]);
            $name = $row->fetchColumn();
            if ($name !== false) {
                // Count dependent rows so we can include them in the activity log.
                $vesselCount = (int)$pdo->query(
                    "SELECT COUNT(*) FROM vessels WHERE company_id = " . $id
                )->fetchColumn();
                $crewCount   = (int)$pdo->query(
                    "SELECT COUNT(*) FROM crew WHERE company_id = " . $id
                )->fetchColumn();

                $stmt = $pdo->prepare("DELETE FROM companies WHERE id = :i");
                $stmt->execute([':i' => $id]);

                $details = "Deleted company '{$name}'";
                if ($vesselCount + $crewCount > 0) {
                    $details .= " (cleared on {$vesselCount} vessels, {$crewCount} crew rows)";
                }
                logActivity($pdo, $user['id'], 'delete', 'companies', $id, $details);

                $msg = "Deleted '{$name}'.";
                if ($vesselCount + $crewCount > 0) {
                    $msg .= " {$vesselCount} vessels and {$crewCount} crew records now have no company.";
                }
                flash('success', $msg);
            }
        }
    }

    header('Location: ' . url('companies.php'));
    exit;
}

// -------------------------------------------------------------
// GET — load companies with usage counts
// -------------------------------------------------------------
$companies = $pdo->query("
    SELECT c.id,
           c.company_name,
           c.address,
           c.contact_person,
           c.contact_number,
           c.email,
           (SELECT COUNT(*) FROM vessels v WHERE v.company_id = c.id) AS vessel_count,
           (SELECT COUNT(*) FROM crew    cr WHERE cr.company_id = c.id) AS crew_count
      FROM companies c
     ORDER BY c.company_name
")->fetchAll();

$pageTitle = 'Companies';
include __DIR__ . '/includes/header.php';
?>

<div class="card">
    <h2 class="card-title">Companies</h2>
    <p class="help-text">
        Client / charterer companies and their contact details. Used when
        adding vessels and crew, and on Quick Approvals. Deleting a company
        that's already in use will clear the company pointer on those vessels
        and crew (the rows themselves are kept). Only the company name is
        required — the contact fields are optional.
    </p>
</div>

<div class="card">
    <h3 class="card-title">Add new company</h3>
    <form method="post" novalidate>
        <?= csrfField() ?>
        <input type="hidden" name="action" value="create">
        <div class="form-grid">
            <div class="form-row">
                <label for="new_company_name">Company name *</label>
                <input type="text" id="new_company_name" name="company_name"
                       placeholder="e.g. Pacific Marine Services Pte Ltd"
                       maxlength="100" required autocomplete="off">
            </div>
            <div class="form-row">
                <label for="new_contact_person">Contact person</label>
                <input type="text" id="new_contact_person" name="contact_person"
                       placeholder="e.g. Capt. R. Sharma"
                       maxlength="120" autocomplete="off">
            </div>
            <div class="form-row">
                <label for="new_contact_number">Contact number</label>
                <input type="tel" id="new_contact_number" name="contact_number"
                       placeholder="e.g. +65 6123 4567"
                       maxlength="40" autocomplete="off">
            </div>
            <div class="form-row">
                <label for="new_email">Email</label>
                <input type="email" id="new_email" name="email"
                       placeholder="e.g. ops@example.com"
                       maxlength="150" autocomplete="off">
            </div>
            <div class="form-row full-row">
                <label for="new_address">Address</label>
                <textarea id="new_address" name="address" maxlength="255"
                          placeholder="Registered / correspondence address"></textarea>
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn">Add company</button>
        </div>
    </form>
</div>

<div class="card">
    <h3 class="card-title">
        Existing companies
        <small class="help-text">(<?= count($companies) ?> total)</small>
    </h3>

    <?php if (empty($companies)): ?>
        <div class="empty-state">
            <h3>No companies yet</h3>
            <p>Add one above to get started.</p>
        </div>
    <?php endif; ?>
</div>

<?php foreach ($companies as $c): ?>
    <?php
        $vesselCount = (int)$c['vessel_count'];
        $crewCount   = (int)$c['crew_count'];
        $totalUse    = $vesselCount + $crewCount;
        $confirmMsg  = "Delete '{$c['company_name']}'?";
        if ($totalUse > 0) {
            $confirmMsg .= " {$vesselCount} vessels and {$crewCount} crew rows will lose their company.";
        }
    ?>
    <div class="card">
        <div class="toolbar">
            <h3 class="card-title" style="margin:0"><?= h($c['company_name']) ?></h3>
            <?php if ($totalUse === 0): ?>
                <span class="status status-gray">Not used</span>
            <?php else: ?>
                <span class="status status-green"
                      title="<?= (int)$vesselCount ?> vessels, <?= (int)$crewCount ?> crew">
                    <?= (int)$vesselCount ?>v · <?= (int)$crewCount ?>c
                </span>
            <?php endif; ?>
        </div>

        <form method="post" novalidate>
            <?= csrfField() ?>
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
            <div class="form-grid">
                <div class="form-row">
                    <label for="name_<?= (int)$c['id'] ?>">Company name *</label>
                    <input type="text" id="name_<?= (int)$c['id'] ?>" name="company_name"
                           value="<?= h($c['company_name']) ?>" maxlength="100" required>
                </div>
                <div class="form-row">
                    <label for="cp_<?= (int)$c['id'] ?>">Contact person</label>
                    <input type="text" id="cp_<?= (int)$c['id'] ?>" name="contact_person"
                           value="<?= h($c['contact_person'] ?? '') ?>" maxlength="120">
                </div>
                <div class="form-row">
                    <label for="cn_<?= (int)$c['id'] ?>">Contact number</label>
                    <input type="tel" id="cn_<?= (int)$c['id'] ?>" name="contact_number"
                           value="<?= h($c['contact_number'] ?? '') ?>" maxlength="40">
                </div>
                <div class="form-row">
                    <label for="em_<?= (int)$c['id'] ?>">Email</label>
                    <input type="email" id="em_<?= (int)$c['id'] ?>" name="email"
                           value="<?= h($c['email'] ?? '') ?>" maxlength="150">
                </div>
                <div class="form-row full-row">
                    <label for="ad_<?= (int)$c['id'] ?>">Address</label>
                    <textarea id="ad_<?= (int)$c['id'] ?>" name="address"
                              maxlength="255"><?= h($c['address'] ?? '') ?></textarea>
                </div>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-secondary">Save changes</button>
            </div>
        </form>

        <div class="row-actions" style="margin-top:12px">
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                <button type="submit" class="btn btn-danger btn-sm"
                        data-confirm="<?= h($confirmMsg) ?>">
                    Delete company
                </button>
            </form>
        </div>
    </div>
<?php endforeach; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
