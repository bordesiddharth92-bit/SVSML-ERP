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

    if ($action === 'save') {
        // ---- gather ------------------------------------------------
        $fullName       = trim($_POST['full_name']       ?? '');
        $indosNumber    = trim($_POST['indos_number']    ?? '');
        $passportNumber = trim($_POST['passport_number'] ?? '');
        $dob            = trim($_POST['date_of_birth']   ?? '');
        $contactNumber  = trim($_POST['contact_number']  ?? '');
        $email          = trim($_POST['email']           ?? '');
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
        if ($indosNumber !== '' && mb_strlen($indosNumber) > 50)       $errors[] = 'INDOS number too long (max 50 chars).';
        if ($passportNumber !== '' && mb_strlen($passportNumber) > 50) $errors[] = 'Passport number too long (max 50 chars).';
        if ($dob !== '' && DateTime::createFromFormat('Y-m-d', $dob) === false) $errors[] = 'Invalid date of birth.';
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL))        $errors[] = 'Invalid email address.';

        // INDOS uniqueness (if provided).
        if (empty($errors) && $indosNumber !== '') {
            $sql = "SELECT id FROM crew WHERE indos_number = :v" . ($postId > 0 ? " AND id <> :i" : "") . " LIMIT 1";
            $stmt = $pdo->prepare($sql);
            $bind = [':v' => $indosNumber];
            if ($postId > 0) $bind[':i'] = $postId;
            $stmt->execute($bind);
            if ($stmt->fetch()) $errors[] = "INDOS '{$indosNumber}' is already used by another crew member.";
        }
        // Passport uniqueness (if provided).
        if (empty($errors) && $passportNumber !== '') {
            $sql = "SELECT id FROM crew WHERE passport_number = :v" . ($postId > 0 ? " AND id <> :i" : "") . " LIMIT 1";
            $stmt = $pdo->prepare($sql);
            $bind = [':v' => $passportNumber];
            if ($postId > 0) $bind[':i'] = $postId;
            $stmt->execute($bind);
            if ($stmt->fetch()) $errors[] = "Passport '{$passportNumber}' is already used by another crew member.";
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
                       value="<?= h($crew['passport_number']) ?>" maxlength="50">
                <p class="help-text">
                    Used as the crew login username from Module 16 onward.
                </p>
            </div>
            <div class="form-row">
                <label for="indos_number">INDOS number</label>
                <input type="text" id="indos_number" name="indos_number"
                       value="<?= h($crew['indos_number']) ?>" maxlength="50">
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
                       value="<?= h($crew['contact_number']) ?>" maxlength="20">
            </div>
            <div class="form-row">
                <label for="email">Email</label>
                <input type="email" id="email" name="email"
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

<?php /* All later modules now ship - no "Coming soon" placeholder. */ ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
