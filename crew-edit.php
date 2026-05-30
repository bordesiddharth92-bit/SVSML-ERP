<?php
/**
 * SVSML-ERP — Crew add / edit (personal details)
 *
 * Module 4.
 *
 * Single page that handles both creating a new crew record (?id missing)
 * and editing an existing one (?id=N). Module 4 covers ONLY personal
 * details — documents, medical, courses, contracts, sign-on/off, etc.
 * are added in later modules.
 *
 * Sections:
 *   - Identity   : full_name, date_of_birth, indos_number, passport_number
 *   - Contact    : contact_number, email, full_address
 *   - Assignment : rank_id, company_id, vessel_id, joiner_type
 *   - Source     : source_type (staff/client) + conditional source_staff_id
 *   - Sizes      : boiler_suit_size, safety_shoes_size, shirt_size, pant_size
 *   - Access     : crew_access_enabled  (admin / sub_admin only — per matrix)
 *
 * Permissions: admin / sub_admin / staff (per matrix). Crew toggles the
 * crew_access_enabled flag; that part of the form is read-only for staff.
 */

require __DIR__ . '/config/app.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/helpers.php';

requireRole(['admin', 'sub_admin', 'staff']);

$user      = currentUser();
$crewId    = isset($_GET['id']) && $_GET['id'] !== '' ? (int)$_GET['id'] : 0;
$isEditing = $crewId > 0;
$canToggleAccess = in_array($user['role'], ['admin', 'sub_admin'], true);

// -------------------------------------------------------------
// POST: save
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';
    $postId = (int)($_POST['crew_id'] ?? 0);

    /* ----- Module 16: set / clear crew portal password ----- */
    if (($action === 'set_password' || $action === 'clear_password') && $postId > 0 && $canToggleAccess) {
        try {
            if ($action === 'set_password') {
                $new     = (string)($_POST['new_password']     ?? '');
                $confirm = (string)($_POST['confirm_password'] ?? '');
                if (mb_strlen($new) < 8)  flash('error', 'Password must be at least 8 characters.');
                elseif ($new !== $confirm) flash('error', 'Password and confirmation do not match.');
                else {
                    $hash = password_hash($new, PASSWORD_BCRYPT);
                    $pdo->prepare(
                        "UPDATE crew SET password_hash = :h, password_set_at = CURRENT_TIMESTAMP WHERE id = :i"
                    )->execute([':h' => $hash, ':i' => $postId]);
                    logActivity(
                        $pdo, $user['id'], 'update', 'crew_password', $postId,
                        "Set portal password for crew {$postId}"
                    );
                    flash('success', 'Crew portal password set.');
                }
            } else { // clear_password
                $pdo->prepare(
                    "UPDATE crew SET password_hash = NULL, password_set_at = NULL WHERE id = :i"
                )->execute([':i' => $postId]);
                logActivity(
                    $pdo, $user['id'], 'update', 'crew_password', $postId,
                    "Cleared portal password for crew {$postId}"
                );
                flash('success', 'Crew portal password cleared.');
            }
        } catch (PDOException $e) {
            error_log('[SVSML-ERP] crew password update failed: ' . $e->getMessage());
            flash('error', 'Database error — has CHANGES.sql been applied?');
        }
        header('Location: ' . url('crew-edit.php?id=' . $postId));
        exit;
    }

    /* ----- Module 18: view / edit crew onboarding & additional details ----- */
    if ($action === 'save_onboarding' && $postId > 0) {
        $obCols = tableColumnSet($pdo, 'crew');
        $obHas  = fn (string $c): bool => isset($obCols[$c]);

        $placeBirth     = trim($_POST['place_of_birth'] ?? '');
        $nationality    = trim($_POST['nationality']    ?? '');
        $cdc            = normalizeUpperTrim($_POST['cdc_number'] ?? '');
        $expectedDep    = trim($_POST['expected_departure_date'] ?? '');
        $contractPeriod = trim($_POST['contract_period_months']  ?? '');
        $holder         = trim($_POST['bank_account_holder'] ?? '');
        $accNo          = trim($_POST['bank_account_no']     ?? '');
        $bank           = trim($_POST['bank_name']           ?? '');
        $ifsc           = strtoupper(trim($_POST['bank_ifsc'] ?? ''));

        $errors = [];
        if ($expectedDep !== '' && DateTime::createFromFormat('Y-m-d', $expectedDep) === false) $errors[] = 'Invalid expected departure date.';
        if ($cdc !== '' && ($e = validateCDCField($cdc)) !== null) $errors[] = $e;
        if ($accNo !== '' && !preg_match('/^[A-Z0-9]{6,30}$/i', $accNo)) $errors[] = 'Account number must be 6–30 alphanumeric characters.';
        if ($ifsc !== '' && !preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', $ifsc)) $errors[] = 'IFSC must be in the standard 11-character format (e.g. SBIN0001234).';

        $kinJson = null;
        if ($obHas('next_of_kin')) $kinJson = collectNextOfKinFromPost($_POST, $errors, false);

        if (!empty($errors)) {
            foreach ($errors as $e) flash('error', $e);
        } else {
            $optional = [
                'place_of_birth'          => $placeBirth     !== '' ? $placeBirth     : null,
                'nationality'             => $nationality    !== '' ? $nationality    : null,
                'cdc_number'              => $cdc            !== '' ? $cdc            : null,
                'expected_departure_date' => $expectedDep    !== '' ? $expectedDep    : null,
                'contract_period_months'  => $contractPeriod !== '' ? $contractPeriod : null,
                'bank_account_holder'     => $holder         !== '' ? $holder         : null,
                'bank_account_no'         => $accNo          !== '' ? $accNo          : null,
                'bank_name'               => $bank           !== '' ? $bank           : null,
                'bank_ifsc'               => $ifsc           !== '' ? $ifsc           : null,
            ];
            $fields = [];
            $params = [':i' => $postId];
            foreach ($optional as $col => $val) {
                if ($obHas($col)) { $fields[] = "`{$col}` = :{$col}"; $params[':' . $col] = $val; }
            }
            if ($obHas('next_of_kin')) { $fields[] = 'next_of_kin = :nok'; $params[':nok'] = $kinJson; }

            if (empty($fields)) {
                flash('error', 'Onboarding columns are not present yet — apply CHANGES.sql first.');
            } else {
                if ($obHas('updated_at')) $fields[] = 'updated_at = CURRENT_TIMESTAMP';
                try {
                    $pdo->prepare('UPDATE crew SET ' . implode(', ', $fields) . ' WHERE id = :i')->execute($params);
                    logActivity($pdo, $user['id'], 'update', 'crew', $postId, "Updated onboarding details for crew {$postId}");
                    flash('success', 'Onboarding details saved.');
                } catch (PDOException $e) {
                    error_log('[SVSML-ERP] save_onboarding: ' . $e->getMessage());
                    flash('error', 'Database error — has CHANGES.sql been applied?');
                }
            }
        }
        header('Location: ' . url('crew-edit.php?id=' . $postId));
        exit;
    }

    if ($action === 'save') {
        // ---- gather ------------------------------------------------
        $fullName       = trim($_POST['full_name']       ?? '');
        $indosNumber    = normalizeUpperTrim($_POST['indos_number']    ?? '');
        $passportNumber = normalizeUpperTrim($_POST['passport_number'] ?? '');
        $dob            = trim($_POST['date_of_birth']   ?? '');
        $contactNumber  = normalizeMobileValue($_POST['contact_number']  ?? '');
        $email          = normalizeEmailValue($_POST['email']           ?? '');
        $fullAddress    = trim($_POST['full_address']    ?? '');

        $rankId         = isset($_POST['rank_id'])    && $_POST['rank_id']    !== '' ? (int)$_POST['rank_id']    : null;
        $companyId      = isset($_POST['company_id']) && $_POST['company_id'] !== '' ? (int)$_POST['company_id'] : null;
        $vesselId       = isset($_POST['vessel_id'])  && $_POST['vessel_id']  !== '' ? (int)$_POST['vessel_id']  : null;
        $joinerType     = ($_POST['joiner_type']  ?? 'new_joiner') === 'rejoiner' ? 'rejoiner' : 'new_joiner';
        $sourceType     = ($_POST['source_type']  ?? 'staff')      === 'client'   ? 'client'   : 'staff';
        $sourceStaffId  = ($sourceType === 'staff' && isset($_POST['source_staff_id']) && $_POST['source_staff_id'] !== '')
            ? (int)$_POST['source_staff_id'] : null;

        $boilerSuit     = trim($_POST['boiler_suit_size']  ?? '');
        $safetyShoes    = trim($_POST['safety_shoes_size'] ?? '');
        $shirt          = trim($_POST['shirt_size']        ?? '');
        $pant           = trim($_POST['pant_size']         ?? '');

        // crew_access_enabled is admin/sub_admin only.
        $crewAccessEnabled = $canToggleAccess ? (!empty($_POST['crew_access_enabled']) ? 1 : 0) : null;

        // ---- validate ----------------------------------------------
        $errors = [];
        if ($fullName === '')                                  $errors[] = 'Full name is required.';
        if (mb_strlen($fullName) > 100)                        $errors[] = 'Full name too long (max 100 chars).';
        if (mb_strlen($indosNumber) > 50)                      $errors[] = 'INDOS number too long (max 50 chars).';
        if (mb_strlen($passportNumber) > 50)                   $errors[] = 'Passport number too long (max 50 chars).';
        if ($dob !== '' && DateTime::createFromFormat('Y-m-d', $dob) === false) $errors[] = 'Invalid date of birth.';

        // Format validation per the validation spec (passport / mobile /
        // email / INDOS). All five are optional at the schema level — we
        // only enforce shape when the operator typed something.
        if (($e = validatePassportField($passportNumber)) !== null) $errors[] = $e;
        if (($e = validateMobileField($contactNumber))    !== null) $errors[] = $e;
        if (($e = validateEmailField($email))             !== null) $errors[] = $e;
        if (($e = validateINDOSField($indosNumber))       !== null) $errors[] = $e;

        // INDOS uniqueness (if provided).
        if (empty($errors) && $indosNumber !== '' && !isINDOSUnique($pdo, $indosNumber, $postId > 0 ? $postId : null)) {
            $errors[] = "INDOS '{$indosNumber}' is already used by another crew member.";
        }
        // Passport uniqueness (if provided).
        if (empty($errors) && $passportNumber !== '' && !isPassportUnique($pdo, $passportNumber, $postId > 0 ? $postId : null)) {
            $errors[] = "Passport '{$passportNumber}' is already used by another crew member.";
        }

        if (!empty($errors)) {
            foreach ($errors as $e) flash('error', $e);
            $crewId    = $postId;
            $isEditing = $postId > 0;
            $crew = [
                'id'                  => $postId,
                'full_name'           => $fullName,
                'indos_number'        => $indosNumber,
                'passport_number'     => $passportNumber,
                'date_of_birth'       => $dob,
                'contact_number'      => $contactNumber,
                'email'               => $email,
                'full_address'        => $fullAddress,
                'rank_id'             => $rankId,
                'company_id'          => $companyId,
                'vessel_id'           => $vesselId,
                'joiner_type'         => $joinerType,
                'source_type'         => $sourceType,
                'source_staff_id'     => $sourceStaffId,
                'boiler_suit_size'    => $boilerSuit,
                'safety_shoes_size'   => $safetyShoes,
                'shirt_size'          => $shirt,
                'pant_size'           => $pant,
                'crew_access_enabled' => $crewAccessEnabled !== null ? $crewAccessEnabled : 0,
            ];
            $skipGetLoad = true;
        } else {
            $params = [
                ':fn'  => $fullName,
                ':in'  => $indosNumber !== '' ? $indosNumber : null,
                ':pp'  => $passportNumber !== '' ? $passportNumber : null,
                ':dob' => $dob !== '' ? $dob : null,
                ':cn'  => $contactNumber !== '' ? $contactNumber : null,
                ':em'  => $email !== '' ? $email : null,
                ':ad'  => $fullAddress !== '' ? $fullAddress : null,
                ':rk'  => $rankId,
                ':co'  => $companyId,
                ':ve'  => $vesselId,
                ':jt'  => $joinerType,
                ':st'  => $sourceType,
                ':ss'  => $sourceStaffId,
                ':bs'  => $boilerSuit !== '' ? $boilerSuit : null,
                ':sh'  => $safetyShoes !== '' ? $safetyShoes : null,
                ':sr'  => $shirt !== '' ? $shirt : null,
                ':pn'  => $pant !== '' ? $pant : null,
                ':uid' => $user['id'],
            ];

            if ($postId > 0) {
                // UPDATE - only set crew_access_enabled when current user can.
                $params[':i'] = $postId;
                $accessSql = $canToggleAccess ? ", crew_access_enabled = :ca" : "";
                if ($canToggleAccess) $params[':ca'] = $crewAccessEnabled;

                // Capture the crew row BEFORE update so relocateCrewUploads()
                // knows which folder to move from when company / rank change.
                $oldCrewRow = fetchCrewWithJoins($pdo, $postId);

                $stmt = $pdo->prepare(
                    "UPDATE crew SET
                        full_name = :fn, indos_number = :in, passport_number = :pp,
                        date_of_birth = :dob, contact_number = :cn, email = :em,
                        full_address = :ad,
                        rank_id = :rk, company_id = :co, vessel_id = :ve,
                        joiner_type = :jt, source_type = :st, source_staff_id = :ss,
                        boiler_suit_size = :bs, safety_shoes_size = :sh,
                        shirt_size = :sr, pant_size = :pn
                        $accessSql
                     WHERE id = :i"
                );
                $stmt->execute($params);

                // Move uploads if the crew folder identity changed.
                $newCrewRow = fetchCrewWithJoins($pdo, $postId);
                if ($oldCrewRow && $newCrewRow) {
                    relocateCrewUploads($pdo, $oldCrewRow, $newCrewRow);
                }

                logActivity(
                    $pdo, $user['id'], 'update', 'crew', $postId,
                    "Updated crew '{$fullName}'"
                );
                flash('success', "Updated '{$fullName}'.");
                header('Location: ' . url('crew-edit.php?id=' . $postId));
            } else {
                // INSERT
                $params[':ca'] = $canToggleAccess ? $crewAccessEnabled : 0;
                $stmt = $pdo->prepare(
                    "INSERT INTO crew
                        (full_name, indos_number, passport_number, date_of_birth,
                         contact_number, email, full_address,
                         rank_id, company_id, vessel_id, joiner_type,
                         source_type, source_staff_id,
                         boiler_suit_size, safety_shoes_size, shirt_size, pant_size,
                         crew_access_enabled, created_by, created_at, updated_at)
                     VALUES
                        (:fn, :in, :pp, :dob,
                         :cn, :em, :ad,
                         :rk, :co, :ve, :jt,
                         :st, :ss,
                         :bs, :sh, :sr, :pn,
                         :ca, :uid, NOW(), NOW())"
                );
                $stmt->execute($params);
                $newId = (int)$pdo->lastInsertId();
                logActivity(
                    $pdo, $user['id'], 'create', 'crew', $newId,
                    "Added crew '{$fullName}'"
                );
                // Seed the default basic + advanced courses (Module 5).
                seedDefaultCoursesForCrew($pdo, $newId, $user['id']);
                flash('success', "Added '{$fullName}'.");
                header('Location: ' . url('crew-edit.php?id=' . $newId));
            }
            exit;
        }
    }
}

// -------------------------------------------------------------
// GET — load existing crew (or build empty form)
// -------------------------------------------------------------
if (empty($skipGetLoad)) {
    if ($isEditing) {
        $stmt = $pdo->prepare("SELECT * FROM crew WHERE id = :i");
        $stmt->execute([':i' => $crewId]);
        $crew = $stmt->fetch();
        if (!$crew) {
            flash('error', 'Crew not found.');
            header('Location: ' . url('crew.php'));
            exit;
        }
    } else {
        $crew = [
            'id'                  => 0,
            'full_name'           => '',
            'indos_number'        => '',
            'passport_number'     => '',
            'date_of_birth'       => '',
            'contact_number'      => '',
            'email'               => '',
            'full_address'        => '',
            'rank_id'             => null,
            'company_id'          => null,
            'vessel_id'           => null,
            'joiner_type'         => 'new_joiner',
            'source_type'         => 'staff',
            'source_staff_id'     => $user['id'],   // sensible default = current user
            'boiler_suit_size'    => '',
            'safety_shoes_size'   => '',
            'shirt_size'          => '',
            'pant_size'           => '',
            'crew_access_enabled' => 0,
        ];
    }
}

// Source data for the selects.
$ranks     = $pdo->query("SELECT id, rank_name    FROM ranks     ORDER BY rank_name")->fetchAll();
$companies = $pdo->query("SELECT id, company_name FROM companies ORDER BY company_name")->fetchAll();
$vessels   = $pdo->query(
    "SELECT v.id, v.vessel_name, c.company_name
       FROM vessels v
       LEFT JOIN companies c ON c.id = v.company_id
      ORDER BY v.vessel_name"
)->fetchAll();
$staffUsers = $pdo->query(
    "SELECT id, full_name FROM users
      WHERE role IN ('admin','sub_admin','staff') AND is_active = 1
      ORDER BY full_name"
)->fetchAll();

$pageTitle  = $isEditing ? ('Edit crew — ' . $crew['full_name']) : 'Add crew';
$currentTab = 'personal';
include __DIR__ . '/includes/header.php';
?>

<?php
// Show the crew sub-nav (Personal / Documents / Medical / Courses / Sailing)
// when editing — it needs the joined rank/company/vessel labels.
if ($isEditing):
    $crewWithJoins = fetchCrewWithJoins($pdo, (int)$crew['id']);
    if ($crewWithJoins):
        $crew = array_merge($crew, $crewWithJoins);
        include __DIR__ . '/includes/crew-tabs.php';
    endif;
endif;
?>

<div class="card">
    <div class="toolbar">
        <h2 class="card-title" style="margin:0">
            <?= $isEditing ? 'Personal details' : 'Add new crew member' ?>
        </h2>
        <?php if (!$isEditing): ?>
            <a class="btn btn-ghost" href="<?= asset('crew.php') ?>">← Back to crew list</a>
        <?php endif; ?>
    </div>

    <form method="post" novalidate>
        <?= csrfField() ?>
        <input type="hidden" name="action"  value="save">
        <input type="hidden" name="crew_id" value="<?= (int)$crew['id'] ?>">

        <div class="section-title">Identity</div>
        <div class="form-grid">
            <div class="form-row full-row">
                <label for="full_name">Full name *</label>
                <input type="text" id="full_name" name="full_name"
                       value="<?= h($crew['full_name']) ?>" maxlength="100" required>
            </div>
            <div class="form-row">
                <label for="passport_number">Passport number</label>
                <input type="text" id="passport_number" name="passport_number"
                       data-validate="passport"
                       value="<?= h($crew['passport_number']) ?>" maxlength="50">
                <p class="help-text">
                    Used as the crew login username from Module 16 onward.
                    Format: 3–20 uppercase letters / digits, no spaces or symbols.
                </p>
            </div>
            <div class="form-row">
                <label for="indos_number">INDOS number</label>
                <input type="text" id="indos_number" name="indos_number"
                       data-validate="indos"
                       value="<?= h($crew['indos_number']) ?>" maxlength="50">
                <p class="help-text">
                    Mandatory for Indian crew. Format: 2 digits + 2 uppercase letters + 4 digits (e.g. <code>12HL3456</code>).
                </p>
            </div>
            <div class="form-row">
                <label for="date_of_birth">Date of birth</label>
                <input type="date" id="date_of_birth" name="date_of_birth"
                       value="<?= h($crew['date_of_birth']) ?>">
            </div>
        </div>

        <div class="section-title">Contact</div>
        <div class="form-grid">
            <div class="form-row">
                <label for="contact_number">Contact number</label>
                <input type="text" id="contact_number" name="contact_number"
                       data-validate="mobile"
                       value="<?= h($crew['contact_number']) ?>" maxlength="20"
                       placeholder="+919876543210">
                <p class="help-text">
                    International format with country code (E.164), e.g. <code>+919876543210</code>.
                    Spaces, dashes and brackets are removed automatically.
                </p>
            </div>
            <div class="form-row">
                <label for="email">Email</label>
                <input type="email" id="email" name="email"
                       data-validate="email"
                       value="<?= h($crew['email']) ?>" maxlength="100">
            </div>
            <div class="form-row full-row">
                <label for="full_address">Full address</label>
                <textarea id="full_address" name="full_address" rows="2"><?= h($crew['full_address']) ?></textarea>
            </div>
        </div>

        <div class="section-title">Assignment</div>
        <div class="form-grid">
            <div class="form-row">
                <label for="rank_id">Rank</label>
                <select id="rank_id" name="rank_id">
                    <option value="">— Select rank —</option>
                    <?php foreach ($ranks as $r): ?>
                        <option value="<?= (int)$r['id'] ?>"
                            <?= ((string)$crew['rank_id'] === (string)$r['id']) ? 'selected' : '' ?>>
                            <?= h($r['rank_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row">
                <label for="joiner_type">Joiner type</label>
                <select id="joiner_type" name="joiner_type">
                    <option value="new_joiner" <?= $crew['joiner_type'] === 'new_joiner' ? 'selected' : '' ?>>New joiner</option>
                    <option value="rejoiner"   <?= $crew['joiner_type'] === 'rejoiner'   ? 'selected' : '' ?>>Rejoiner</option>
                </select>
                <p class="help-text">
                    Auto-detection from sign-on history is wired up in Module 6/7.
                </p>
            </div>
            <div class="form-row">
                <label for="company_id">Company</label>
                <select id="company_id" name="company_id">
                    <option value="">— None —</option>
                    <?php foreach ($companies as $c): ?>
                        <option value="<?= (int)$c['id'] ?>"
                            <?= ((string)$crew['company_id'] === (string)$c['id']) ? 'selected' : '' ?>>
                            <?= h($c['company_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row">
                <label for="vessel_id">Vessel</label>
                <select id="vessel_id" name="vessel_id">
                    <option value="">— None —</option>
                    <?php foreach ($vessels as $v): ?>
                        <option value="<?= (int)$v['id'] ?>"
                            <?= ((string)$crew['vessel_id'] === (string)$v['id']) ? 'selected' : '' ?>>
                            <?= h($v['vessel_name']) ?>
                            <?= $v['company_name'] ? ' — ' . h($v['company_name']) : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="section-title">Source</div>
        <div class="form-grid">
            <div class="form-row full-row">
                <label>How did this crew member reach SVSML?</label>
                <div style="display:flex; gap:18px; padding-top:4px;">
                    <label style="font-weight:400">
                        <input type="radio" name="source_type" value="staff"
                            <?= $crew['source_type'] === 'staff' ? 'checked' : '' ?>>
                        Through a staff member
                    </label>
                    <label style="font-weight:400">
                        <input type="radio" name="source_type" value="client"
                            <?= $crew['source_type'] === 'client' ? 'checked' : '' ?>>
                        Direct from client
                    </label>
                </div>
            </div>
            <div class="form-row full-row conditional" id="source_staff_field">
                <label for="source_staff_id">Sourcing staff member</label>
                <select id="source_staff_id" name="source_staff_id">
                    <option value="">— Select staff —</option>
                    <?php foreach ($staffUsers as $u): ?>
                        <option value="<?= (int)$u['id'] ?>"
                            <?= ((string)$crew['source_staff_id'] === (string)$u['id']) ? 'selected' : '' ?>>
                            <?= h($u['full_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="section-title">Sizes</div>
        <div class="form-grid">
            <div class="form-row">
                <label for="boiler_suit_size">Boiler suit</label>
                <?= renderSizeSelect('boiler_suit_size', clothingSizeOptions(), $crew['boiler_suit_size']) ?>
            </div>
            <div class="form-row">
                <label for="safety_shoes_size">Safety shoes</label>
                <?= renderSizeSelect('safety_shoes_size', numericSizeOptions(), $crew['safety_shoes_size']) ?>
            </div>
            <div class="form-row">
                <label for="shirt_size">Shirt</label>
                <?= renderSizeSelect('shirt_size', clothingSizeOptions(), $crew['shirt_size']) ?>
            </div>
            <div class="form-row">
                <label for="pant_size">Pant</label>
                <?= renderSizeSelect('pant_size', numericSizeOptions(), $crew['pant_size']) ?>
            </div>
        </div>

        <div class="section-title">Crew Self-Login Access</div>
        <div class="switch-row">
            <input type="checkbox" id="crew_access_enabled" name="crew_access_enabled"
                   value="1"
                   <?= (int)$crew['crew_access_enabled'] ? 'checked' : '' ?>
                   <?= $canToggleAccess ? '' : 'disabled' ?>>
            <label for="crew_access_enabled">Allow this crew to sign in to the portal</label>
            <span class="help-text">
                <?php if ($canToggleAccess): ?>
                    Crew can then sign in using their passport number (Module 16).
                <?php else: ?>
                    Only Admin / Sub-admin can change this setting.
                <?php endif; ?>
            </span>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn">
                <?= $isEditing ? 'Save changes' : 'Add crew member' ?>
            </button>
            <button type="reset" class="btn btn-secondary">Reset</button>
            <?php if ($isEditing): ?>
                <a class="btn btn-ghost" href="<?= asset('crew.php') ?>">Cancel</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<?php
// -------------------------------------------------------------
// Module 16 — Crew portal password (admin / sub_admin only,
// existing crew records only). Lives in its own form so that
// password changes are explicit and don't ride on top of a
// personal-details edit.
// -------------------------------------------------------------
if ($isEditing && $canToggleAccess):
    // Probe schema for the password column so this section degrades
    // gracefully if CHANGES.sql hasn't been applied yet.
    $hasPasswordCol = false;
    try {
        $col = $pdo->query("SHOW COLUMNS FROM crew LIKE 'password_hash'")->fetch();
        $hasPasswordCol = (bool)$col;
    } catch (PDOException $e) { /* ignore */ }

    $passwordSetAt = null;
    $lastLoginAt   = null;
    if ($hasPasswordCol) {
        $st = $pdo->prepare("SELECT password_hash, password_set_at, last_login_at FROM crew WHERE id = :i");
        $st->execute([':i' => (int)$crew['id']]);
        $info = $st->fetch();
        $passwordSetAt = $info['password_set_at'] ?? null;
        $lastLoginAt   = $info['last_login_at']   ?? null;
        $hasPassword   = !empty($info['password_hash']);
    } else {
        $hasPassword = false;
    }
?>
<div class="card">
    <h3 class="card-title">Crew Portal Password</h3>
    <?php if (!$hasPasswordCol): ?>
        <div class="flash flash-warning">
            <strong>Database migration pending.</strong>
            Run <code>CHANGES.sql</code> on the production database to enable
            crew portal passwords (adds <code>password_hash</code>, <code>password_set_at</code>,
            <code>last_login_at</code> columns to the <code>crew</code> table).
        </div>
    <?php else: ?>
        <p class="help-text">
            Status:
            <?php if ($hasPassword): ?>
                <span class="status status-green">Password set</span>
                <?php if ($passwordSetAt): ?> · set on <?= h($passwordSetAt) ?><?php endif; ?>
                <?php if ($lastLoginAt):   ?> · last login <?= h($lastLoginAt) ?><?php endif; ?>
            <?php else: ?>
                <span class="status status-gray">No password yet</span>
            <?php endif; ?>
            <?php if ((int)$crew['crew_access_enabled'] !== 1): ?>
                · <span class="status status-yellow">Access disabled</span> — turn on the toggle above before the crew can sign in.
            <?php endif; ?>
        </p>
        <form method="post" action="<?= asset('crew-edit.php?id=' . (int)$crew['id']) ?>" novalidate>
            <?= csrfField() ?>
            <input type="hidden" name="action"  value="set_password">
            <input type="hidden" name="crew_id" value="<?= (int)$crew['id'] ?>">
            <div class="form-grid">
                <div class="form-row">
                    <label for="new_password">New password *</label>
                    <input type="text" id="new_password" name="new_password" minlength="8" required autocomplete="new-password">
                    <p class="help-text">At least 8 characters. Share this with the crew via a secure channel.</p>
                </div>
                <div class="form-row">
                    <label for="confirm_password">Confirm *</label>
                    <input type="text" id="confirm_password" name="confirm_password" minlength="8" required autocomplete="new-password">
                </div>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn"><?= $hasPassword ? 'Reset password' : 'Set password' ?></button>
                <?php if ($hasPassword): ?>
                    <button type="submit" name="action" value="clear_password" formnovalidate
                            class="btn btn-danger"
                            data-confirm="Clear the password? The crew will not be able to sign in until a new password is set.">
                        Clear password
                    </button>
                <?php endif; ?>
            </div>
        </form>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php
// -------------------------------------------------------------
// Module 18 — Crew onboarding & additional details (admin/staff view).
// Shows everything the crew captured during onboarding, editable here so
// staff can correct it. Degrades gracefully when CHANGES.sql is pending.
// -------------------------------------------------------------
if ($isEditing):
    $obCols = tableColumnSet($pdo, 'crew');
    $obHas  = fn (string $c): bool => isset($obCols[$c]);
    $obExtended = $obHas('place_of_birth') || $obHas('nationality') || $obHas('cdc_number')
        || $obHas('expected_departure_date') || $obHas('contract_period_months')
        || $obHas('bank_account_holder') || $obHas('bank_account_no')
        || $obHas('bank_name') || $obHas('bank_ifsc') || $obHas('next_of_kin');

    $obKin = decodeNextOfKinJson($crew['next_of_kin'] ?? null);
    while (count($obKin) < 2) $obKin[] = emptyNextOfKin();
    $obCountries = TRAVEL_COUNTRIES;

    $onbComplete = $obHas('onboarding_complete') ? (int)($crew['onboarding_complete'] ?? 0) : null;
?>
<div class="card">
    <h3 class="card-title">Onboarding &amp; additional details</h3>

    <?php if (!$obExtended): ?>
        <div class="flash flash-warning">
            <strong>Database migration pending.</strong>
            The onboarding columns aren't present on the <code>crew</code> table yet.
            Run <code>CHANGES.sql</code> to enable place of birth, nationality, CDC,
            bank details and next-of-kin capture.
        </div>
    <?php else: ?>
        <p class="help-text">
            Status:
            <?php if ($onbComplete === 1): ?>
                <span class="status status-green">Onboarding complete</span>
                <?php if (!empty($crew['onboarded_at'])): ?> · finished <?= h($crew['onboarded_at']) ?><?php endif; ?>
            <?php elseif ($onbComplete === 0): ?>
                <span class="status status-yellow">Onboarding pending</span> — the crew will be asked to complete it on next sign-in.
            <?php endif; ?>
            <?php if ($obHas('last_login_at') && !empty($crew['last_login_at'])): ?>
                · last portal login <?= h($crew['last_login_at']) ?>
            <?php endif; ?>
            <?php if ($obHas('profile_photo') && !empty($crew['profile_photo'])): ?>
                · <a href="<?= h(asset('uploads/' . $crew['profile_photo'])) ?>" target="_blank" rel="noopener">Profile photo</a>
            <?php endif; ?>
        </p>

        <form method="post" action="<?= asset('crew-edit.php?id=' . (int)$crew['id']) ?>" novalidate>
            <?= csrfField() ?>
            <input type="hidden" name="action"  value="save_onboarding">
            <input type="hidden" name="crew_id" value="<?= (int)$crew['id'] ?>">

            <div class="form-grid">
                <?php if ($obHas('place_of_birth')): ?>
                <div class="form-row">
                    <label for="ob_place_of_birth">Place of birth</label>
                    <input type="text" id="ob_place_of_birth" name="place_of_birth" maxlength="100"
                           value="<?= h($crew['place_of_birth'] ?? '') ?>">
                </div>
                <?php endif; ?>
                <?php if ($obHas('nationality')): ?>
                <div class="form-row">
                    <label for="ob_nationality">Nationality</label>
                    <input list="ob-countries" id="ob_nationality" name="nationality" maxlength="80"
                           value="<?= h($crew['nationality'] ?? '') ?>" autocomplete="off">
                    <datalist id="ob-countries">
                        <?php foreach ($obCountries as $c): ?><option value="<?= h($c) ?>"></option><?php endforeach; ?>
                    </datalist>
                </div>
                <?php endif; ?>
                <?php if ($obHas('cdc_number')): ?>
                <div class="form-row">
                    <label for="ob_cdc_number">Seaman / CDC book number</label>
                    <input type="text" id="ob_cdc_number" name="cdc_number" maxlength="50" data-validate="cdc"
                           value="<?= h($crew['cdc_number'] ?? '') ?>" placeholder="MUM123456">
                </div>
                <?php endif; ?>
                <?php if ($obHas('expected_departure_date')): ?>
                <div class="form-row">
                    <label for="ob_expected_departure_date">Expected date of departure</label>
                    <input type="date" id="ob_expected_departure_date" name="expected_departure_date"
                           value="<?= h($crew['expected_departure_date'] ?? '') ?>">
                </div>
                <?php endif; ?>
                <?php if ($obHas('contract_period_months')): ?>
                <div class="form-row">
                    <label for="ob_contract_period_months">Contract period</label>
                    <input type="text" id="ob_contract_period_months" name="contract_period_months" maxlength="50"
                           value="<?= h($crew['contract_period_months'] ?? '') ?>" placeholder="e.g. 6 months &plusmn; 1">
                </div>
                <?php endif; ?>
            </div>

            <?php if ($obHas('bank_account_holder') || $obHas('bank_account_no') || $obHas('bank_name') || $obHas('bank_ifsc')): ?>
            <div class="section-title">Bank details</div>
            <div class="form-grid">
                <?php if ($obHas('bank_account_holder')): ?>
                <div class="form-row">
                    <label for="ob_bank_account_holder">Account holder name</label>
                    <input type="text" id="ob_bank_account_holder" name="bank_account_holder" maxlength="120"
                           value="<?= h($crew['bank_account_holder'] ?? '') ?>">
                </div>
                <?php endif; ?>
                <?php if ($obHas('bank_account_no')): ?>
                <div class="form-row">
                    <label for="ob_bank_account_no">Account number</label>
                    <input type="text" id="ob_bank_account_no" name="bank_account_no" maxlength="50"
                           value="<?= h($crew['bank_account_no'] ?? '') ?>">
                </div>
                <?php endif; ?>
                <?php if ($obHas('bank_name')): ?>
                <div class="form-row">
                    <label for="ob_bank_name">Bank name</label>
                    <input type="text" id="ob_bank_name" name="bank_name" maxlength="120"
                           value="<?= h($crew['bank_name'] ?? '') ?>">
                </div>
                <?php endif; ?>
                <?php if ($obHas('bank_ifsc')): ?>
                <div class="form-row">
                    <label for="ob_bank_ifsc">IFSC code</label>
                    <input type="text" id="ob_bank_ifsc" name="bank_ifsc" maxlength="20" placeholder="SBIN0001234"
                           style="text-transform: uppercase;" value="<?= h($crew['bank_ifsc'] ?? '') ?>">
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if ($obHas('next_of_kin')): ?>
            <div class="section-title">Next of kin <small style="font-weight:400;color:var(--text-muted)">(up to 2)</small></div>
            <?php for ($i = 0; $i < 2; $i++): $row = $obKin[$i]; ?>
                <div class="form-grid" style="margin-bottom:6px">
                    <div class="form-row">
                        <label>Kin #<?= $i + 1 ?> — name</label>
                        <input type="text" name="kin[<?= $i ?>][name]" maxlength="100" value="<?= h($row['name']) ?>">
                    </div>
                    <div class="form-row">
                        <label>Relation</label>
                        <input type="text" name="kin[<?= $i ?>][relation]" maxlength="50" value="<?= h($row['relation']) ?>">
                    </div>
                    <div class="form-row full-row">
                        <label>Address</label>
                        <textarea name="kin[<?= $i ?>][address]" rows="2"><?= h($row['address']) ?></textarea>
                    </div>
                    <div class="form-row">
                        <label>Percentage</label>
                        <input type="number" step="0.01" min="0" max="100" name="kin[<?= $i ?>][percentage]"
                               value="<?= h($row['percentage']) ?>" placeholder="0–100">
                    </div>
                    <div class="form-row">
                        <label>Email</label>
                        <input type="email" name="kin[<?= $i ?>][email]" maxlength="100" data-validate="email"
                               value="<?= h($row['email']) ?>">
                    </div>
                    <div class="form-row">
                        <label>Mobile 1</label>
                        <input type="text" name="kin[<?= $i ?>][mobile1]" maxlength="20" data-validate="mobile"
                               value="<?= h($row['mobile1']) ?>">
                    </div>
                    <div class="form-row">
                        <label>Mobile 2</label>
                        <input type="text" name="kin[<?= $i ?>][mobile2]" maxlength="20" data-validate="mobile"
                               value="<?= h($row['mobile2']) ?>">
                    </div>
                </div>
            <?php endfor; ?>
            <?php endif; ?>

            <div class="form-actions">
                <button type="submit" class="btn">Save onboarding details</button>
            </div>
        </form>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
