<?php
/**
 * SVSML-ERP — Crew Portal: My Profile (view + edit)
 *
 * Module 16 / 18.
 *
 * The crew member's self-service profile. Crew can view AND edit their
 * own personal, bank and next-of-kin details here. Two things stay
 * read-only because they are an operational decision for SVSML staff:
 *
 *   - Vessel / company assignment  (admin / staff only)
 *   - Passport number, once SVSML has set it (contact the agent to fix)
 *
 * Everything else — name, DOB, place of birth, nationality, CDC, contact,
 * address, expected departure, contract period, profile photo, bank
 * details and up to two next-of-kin entries — is editable by the crew.
 *
 * The page degrades gracefully when CHANGES.sql hasn't been applied:
 * extended columns that don't exist are simply not rendered / written,
 * so the base personal fields still work on an un-migrated database.
 */

require __DIR__ . '/config/app.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/helpers.php';

requireOnboardedCrew($pdo);
$crewId = currentCrewId();
$crew   = $crewId ? fetchCrewWithJoins($pdo, $crewId) : null;
if (!$crew) { logoutCurrentUser(); header('Location: ' . url('crew-login.php')); exit; }

// Which optional (post-migration) columns exist on the crew table?
$crewCols = tableColumnSet($pdo, 'crew');
$has = fn (string $c): bool => isset($crewCols[$c]);

$ranks = $pdo->query("SELECT id, rank_name FROM ranks ORDER BY rank_name")->fetchAll();

// -------------------------------------------------------------
// POST — save the crew's own profile edits
// -------------------------------------------------------------
$submitted = null; // populated on validation error so we can re-fill the form
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_profile') {
    verifyCsrf();

    // Passport is admin-managed once set: never trust a POSTed value when
    // the crew already has one on file.
    $existingPassport = (string)($crew['passport_number'] ?? '');
    $passportLocked   = ($existingPassport !== '');

    $fullName       = trim($_POST['full_name'] ?? '');
    $rankId         = !empty($_POST['rank_id']) ? (int)$_POST['rank_id'] : null;
    $dob            = trim($_POST['date_of_birth']  ?? '');
    $placeBirth     = trim($_POST['place_of_birth'] ?? '');
    $nationality    = trim($_POST['nationality']    ?? '');
    $passport       = $passportLocked
        ? normalizeUpperTrim($existingPassport)
        : normalizeUpperTrim($_POST['passport_number'] ?? '');
    $cdc            = normalizeUpperTrim($_POST['cdc_number']     ?? '');
    $contact        = normalizeMobileValue($_POST['contact_number'] ?? '');
    $email          = normalizeEmailValue($_POST['email']          ?? '');
    $address        = trim($_POST['full_address'] ?? '');
    $expectedDep    = trim($_POST['expected_departure_date'] ?? '');
    $contractPeriod = trim($_POST['contract_period_months'] ?? '');
    $holder         = trim($_POST['bank_account_holder'] ?? '');
    $accNo          = trim($_POST['bank_account_no']     ?? '');
    $bank           = trim($_POST['bank_name']           ?? '');
    $ifsc           = strtoupper(trim($_POST['bank_ifsc'] ?? ''));

    $errors = [];
    if ($fullName === '')                  $errors[] = 'Full name is required.';
    if ($dob !== '' && !DateTime::createFromFormat('Y-m-d', $dob))                 $errors[] = 'Invalid date of birth.';
    if ($expectedDep !== '' && !DateTime::createFromFormat('Y-m-d', $expectedDep)) $errors[] = 'Invalid expected departure date.';
    if (!$passportLocked && ($e = validatePassportField($passport)) !== null) $errors[] = $e;
    if ($cdc !== ''     && ($e = validateCDCField($cdc))      !== null) $errors[] = $e;
    if ($contact !== '' && ($e = validateMobileField($contact)) !== null) $errors[] = $e;
    if ($email !== ''   && ($e = validateEmailField($email))  !== null) $errors[] = $e;
    if ($accNo !== '' && !preg_match('/^[A-Z0-9]{6,30}$/i', $accNo)) {
        $errors[] = 'Account number must be 6–30 alphanumeric characters.';
    }
    if ($ifsc !== '' && !preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', $ifsc)) {
        $errors[] = 'IFSC must be in the standard 11-character format (e.g. SBIN0001234).';
    }
    if (!$passportLocked && empty($errors) && $passport !== '' && !isPassportUnique($pdo, $passport, $crewId)) {
        $errors[] = "Passport '{$passport}' is already used by another crew member.";
    }

    // Next of kin (only when the column exists).
    $kinJson = null;
    if ($has('next_of_kin')) {
        $kinJson = collectNextOfKinFromPost($_POST, $errors, false);
    }

    if (empty($errors)) {
        // Build the UPDATE from only the columns that exist on this DB.
        $fields = ['full_name = :full_name'];
        $params = [':full_name' => $fullName, ':i' => $crewId];

        $fields[] = 'rank_id = :rank_id';          $params[':rank_id'] = $rankId;
        $fields[] = 'date_of_birth = :dob';        $params[':dob']     = $dob !== '' ? $dob : null;
        $fields[] = 'contact_number = :contact';   $params[':contact'] = $contact !== '' ? $contact : null;
        $fields[] = 'email = :email';              $params[':email']   = $email !== '' ? $email : null;
        $fields[] = 'full_address = :addr';        $params[':addr']    = $address !== '' ? $address : null;
        if (!$passportLocked) {
            $fields[] = 'passport_number = :pass';
            $params[':pass'] = $passport !== '' ? $passport : null;
        }

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
        foreach ($optional as $col => $val) {
            if ($has($col)) {
                $fields[]           = "`{$col}` = :{$col}";
                $params[':' . $col] = $val;
            }
        }
        if ($has('next_of_kin')) {
            $fields[]        = 'next_of_kin = :nok';
            $params[':nok']  = $kinJson;
        }
        if ($has('updated_at')) $fields[] = 'updated_at = CURRENT_TIMESTAMP';

        try {
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE crew SET ' . implode(', ', $fields) . ' WHERE id = :i')
                ->execute($params);

            // Profile photo (optional) — only when the column exists.
            if ($has('profile_photo') && !empty($_FILES['profile_photo']['name'])) {
                $freshCrew = fetchCrewWithJoins($pdo, $crewId);
                try {
                    $photoPath = saveCrewUpload('profile_photo', $freshCrew, 'ProfilePhoto', false);
                    if ($photoPath) {
                        $pdo->prepare("UPDATE crew SET profile_photo = :p WHERE id = :i")
                            ->execute([':p' => $photoPath, ':i' => $crewId]);
                    }
                } catch (RuntimeException $upErr) {
                    flash('warning', 'Photo upload skipped: ' . $upErr->getMessage());
                }
            }

            $pdo->commit();
            logActivity($pdo, null, 'update', 'crew', $crewId, "Crew {$crewId} updated their own profile");
            flash('success', 'Your profile has been updated.');
            header('Location: ' . url('crew-portal-personal.php'));
            exit;
        } catch (PDOException $dbErr) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[SVSML-ERP] my-profile save: ' . $dbErr->getMessage());
            flash('error', 'Could not save your profile: ' . $dbErr->getMessage());
        }
    } else {
        foreach ($errors as $e) flash('error', $e);
    }

    // On error, preserve what the crew typed so the form isn't wiped.
    $submitted = [
        'full_name'               => $fullName,
        'rank_id'                 => $rankId,
        'date_of_birth'           => $dob,
        'place_of_birth'          => $placeBirth,
        'nationality'             => $nationality,
        'passport_number'         => $passportLocked ? $existingPassport : $passport,
        'cdc_number'              => $cdc,
        'contact_number'          => $contact,
        'email'                   => $email,
        'full_address'            => $address,
        'expected_departure_date' => $expectedDep,
        'contract_period_months'  => $contractPeriod,
        'bank_account_holder'     => $holder,
        'bank_account_no'         => $accNo,
        'bank_name'               => $bank,
        'bank_ifsc'               => $ifsc,
    ];
}

// -------------------------------------------------------------
// GET / re-render
// -------------------------------------------------------------
$crew = fetchCrewWithJoins($pdo, $crewId);
$latestContract = fetchLatestContractForCrew($pdo, $crewId);

// Merge any just-submitted (but rejected) values over the DB row.
$view = $crew;
if (is_array($submitted)) $view = array_merge($crew, $submitted);

// Next-of-kin: prefer submitted POST data on error, else the stored JSON.
if (is_array($submitted) && isset($_POST['kin']) && is_array($_POST['kin'])) {
    $kin = [];
    for ($i = 0; $i < 2; $i++) {
        $kin[] = [
            'name'       => trim($_POST['kin'][$i]['name']       ?? ''),
            'address'    => trim($_POST['kin'][$i]['address']    ?? ''),
            'relation'   => trim($_POST['kin'][$i]['relation']   ?? ''),
            'percentage' => trim($_POST['kin'][$i]['percentage'] ?? ''),
            'mobile1'    => trim($_POST['kin'][$i]['mobile1']    ?? ''),
            'mobile2'    => trim($_POST['kin'][$i]['mobile2']    ?? ''),
            'email'      => trim($_POST['kin'][$i]['email']      ?? ''),
        ];
    }
} else {
    $kin = decodeNextOfKinJson($view['next_of_kin'] ?? null);
}
while (count($kin) < 2) $kin[] = emptyNextOfKin();

$passportLocked = !empty($crew['passport_number']);
$nationalities  = TRAVEL_COUNTRIES;

// Hero avatar: prefer an image-type profile photo, else contract photo.
$heroPhoto = '';
$pp = (string)($crew['profile_photo'] ?? '');
if ($pp !== '' && preg_match('/\.(jpe?g|png|gif|webp)$/i', $pp)) {
    $heroPhoto = asset('uploads/' . $pp);
} else {
    $heroPhoto = crewPhotoUrl($latestContract);
}

$pageTitle  = 'My Profile';
$currentTab = 'personal';
include __DIR__ . '/includes/header.php';
?>

<div class="portal-hero">
    <div class="portal-avatar">
        <?php if ($heroPhoto): ?><img src="<?= h($heroPhoto) ?>" alt="Profile photo">
        <?php else: ?><?= h(crewInitials($crew['full_name'])) ?><?php endif; ?>
    </div>
    <div class="portal-hero-meta">
        <h2><?= h($crew['full_name']) ?></h2>
        <div class="meta-line"><?= h($crew['rank_name'] ?? 'Rank not set') ?></div>
    </div>
</div>

<?php include __DIR__ . '/includes/crew-portal-tabs.php'; ?>

<div class="card">
    <h3 class="card-title">My details</h3>
    <p class="help-text">
        Keep your information up to date. Your vessel and company assignment
        are managed by SVSML and shown read-only below.
    </p>

    <form method="post" enctype="multipart/form-data" novalidate>
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save_profile">

        <div class="section-title">Personal</div>
        <div class="form-grid">
            <div class="form-row full-row">
                <label for="full_name">Full name *</label>
                <input type="text" id="full_name" name="full_name" required maxlength="100"
                       value="<?= h($view['full_name'] ?? '') ?>">
            </div>
            <div class="form-row">
                <label for="rank_id">Rank</label>
                <select id="rank_id" name="rank_id">
                    <option value="">— Select rank —</option>
                    <?php foreach ($ranks as $r): ?>
                        <option value="<?= (int)$r['id'] ?>" <?= ((int)($view['rank_id'] ?? 0) === (int)$r['id']) ? 'selected' : '' ?>>
                            <?= h($r['rank_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row">
                <label for="date_of_birth">Date of birth</label>
                <input type="date" id="date_of_birth" name="date_of_birth"
                       value="<?= h($view['date_of_birth'] ?? '') ?>">
            </div>
            <?php if ($has('place_of_birth')): ?>
            <div class="form-row">
                <label for="place_of_birth">Place of birth</label>
                <input type="text" id="place_of_birth" name="place_of_birth" maxlength="100"
                       value="<?= h($view['place_of_birth'] ?? '') ?>">
            </div>
            <?php endif; ?>
            <?php if ($has('nationality')): ?>
            <div class="form-row">
                <label for="nationality">Nationality</label>
                <input list="dl-countries" id="nationality" name="nationality" maxlength="80"
                       value="<?= h($view['nationality'] ?? '') ?>" autocomplete="off">
                <datalist id="dl-countries">
                    <?php foreach ($nationalities as $c): ?>
                        <option value="<?= h($c) ?>"></option>
                    <?php endforeach; ?>
                </datalist>
            </div>
            <?php endif; ?>
            <div class="form-row">
                <label for="passport_number">Passport number</label>
                <?php if ($passportLocked): ?>
                    <input type="text" id="passport_number" value="<?= h($crew['passport_number']) ?>"
                           maxlength="50" readonly aria-readonly="true"
                           style="background: var(--surface-alt); cursor: not-allowed;">
                    <p class="help-text">Set by SVSML — contact your manning agent to correct it.</p>
                <?php else: ?>
                    <input type="text" id="passport_number" name="passport_number" maxlength="50"
                           data-validate="passport" placeholder="N1234567"
                           value="<?= h($view['passport_number'] ?? '') ?>">
                <?php endif; ?>
            </div>
            <?php if ($has('cdc_number')): ?>
            <div class="form-row">
                <label for="cdc_number">Seaman / CDC book number</label>
                <input type="text" id="cdc_number" name="cdc_number" maxlength="50" data-validate="cdc"
                       value="<?= h($view['cdc_number'] ?? '') ?>" placeholder="MUM123456">
            </div>
            <?php endif; ?>
            <div class="form-row">
                <label for="contact_number">Contact number</label>
                <input type="text" id="contact_number" name="contact_number" maxlength="20" data-validate="mobile"
                       value="<?= h($view['contact_number'] ?? '') ?>" placeholder="+919876543210">
            </div>
            <div class="form-row">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" maxlength="100" data-validate="email"
                       value="<?= h($view['email'] ?? '') ?>">
            </div>
            <div class="form-row full-row">
                <label for="full_address">Home address</label>
                <textarea id="full_address" name="full_address" rows="2"><?= h($view['full_address'] ?? '') ?></textarea>
            </div>
            <?php if ($has('expected_departure_date')): ?>
            <div class="form-row">
                <label for="expected_departure_date">Expected date of departure</label>
                <input type="date" id="expected_departure_date" name="expected_departure_date"
                       value="<?= h($view['expected_departure_date'] ?? '') ?>">
            </div>
            <?php endif; ?>
            <?php if ($has('contract_period_months')): ?>
            <div class="form-row">
                <label for="contract_period_months">Contract period</label>
                <input type="text" id="contract_period_months" name="contract_period_months" maxlength="50"
                       value="<?= h($view['contract_period_months'] ?? '') ?>" placeholder="e.g. 6 months &plusmn; 1">
            </div>
            <?php endif; ?>
            <?php if ($has('profile_photo')): ?>
            <div class="form-row full-row">
                <label for="profile_photo">Profile photo (PDF / JPG / PNG / DOCX, max 10 MB)</label>
                <input type="file" id="profile_photo" name="profile_photo" accept=".pdf,.jpg,.jpeg,.png,.docx">
                <?php if (!empty($crew['profile_photo'])): ?>
                    <p class="help-text">
                        A file is already on record — uploading a new one will replace it.
                        <a href="<?= h(asset('uploads/' . $crew['profile_photo'])) ?>" target="_blank" rel="noopener">View current</a>
                    </p>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

        <?php if ($has('bank_account_holder') || $has('bank_account_no') || $has('bank_name') || $has('bank_ifsc')): ?>
        <div class="section-title">Bank details</div>
        <div class="form-grid">
            <?php if ($has('bank_account_holder')): ?>
            <div class="form-row">
                <label for="bank_account_holder">Account holder name</label>
                <input type="text" id="bank_account_holder" name="bank_account_holder" maxlength="120"
                       value="<?= h($view['bank_account_holder'] ?? '') ?>">
            </div>
            <?php endif; ?>
            <?php if ($has('bank_account_no')): ?>
            <div class="form-row">
                <label for="bank_account_no">Account number</label>
                <input type="text" id="bank_account_no" name="bank_account_no" maxlength="50"
                       value="<?= h($view['bank_account_no'] ?? '') ?>">
            </div>
            <?php endif; ?>
            <?php if ($has('bank_name')): ?>
            <div class="form-row">
                <label for="bank_name">Bank name</label>
                <input type="text" id="bank_name" name="bank_name" maxlength="120"
                       value="<?= h($view['bank_name'] ?? '') ?>">
            </div>
            <?php endif; ?>
            <?php if ($has('bank_ifsc')): ?>
            <div class="form-row">
                <label for="bank_ifsc">IFSC code</label>
                <input type="text" id="bank_ifsc" name="bank_ifsc" maxlength="20" placeholder="SBIN0001234"
                       style="text-transform: uppercase;" value="<?= h($view['bank_ifsc'] ?? '') ?>">
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($has('next_of_kin')): ?>
        <div class="section-title">Next of kin <small style="font-weight:400;color:var(--text-muted)">(up to 2)</small></div>
        <?php for ($i = 0; $i < 2; $i++): $row = $kin[$i]; ?>
            <div class="form-grid" style="margin-bottom:6px">
                <div class="form-row">
                    <label>Kin #<?= $i + 1 ?> — name</label>
                    <input type="text" name="kin[<?= $i ?>][name]" maxlength="100" value="<?= h($row['name']) ?>">
                </div>
                <div class="form-row">
                    <label>Relation</label>
                    <input type="text" name="kin[<?= $i ?>][relation]" maxlength="50"
                           value="<?= h($row['relation']) ?>" placeholder="Spouse / Parent / Sibling">
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
                           value="<?= h($row['mobile1']) ?>" placeholder="+91...">
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
            <button type="submit" class="btn">Save my profile</button>
        </div>
    </form>
</div>

<div class="card">
    <h3 class="card-title">Assignment <small class="help-text">(managed by SVSML)</small></h3>
    <div class="kv-grid">
        <div class="kv-row"><div class="kv-label">Company</div> <div class="kv-value"><?= ($crew['company_name'] ?? '') !== '' ? h($crew['company_name']) : '<span class="muted">—</span>' ?></div></div>
        <div class="kv-row"><div class="kv-label">Vessel</div>  <div class="kv-value"><?= ($crew['vessel_name']  ?? '') !== '' ? h($crew['vessel_name'])  : '<span class="muted">—</span>' ?></div></div>
        <div class="kv-row"><div class="kv-label">INDOS number</div> <div class="kv-value"><?= ($crew['indos_number'] ?? '') !== '' ? h($crew['indos_number']) : '<span class="muted">—</span>' ?></div></div>
    </div>
</div>

<?php if ($latestContract): ?>
<div class="card">
    <h3 class="card-title">From your latest contract <small class="help-text">(read-only)</small></h3>
    <p class="help-text">
        Captured per contract. Reference
        <strong><?= h($latestContract['reference_number'] ?? '—') ?></strong>
        dated <?= h($latestContract['contract_date'] ?? '—') ?>.
    </p>
    <div class="kv-grid">
        <div class="kv-row"><div class="kv-label">Home town</div>       <div class="kv-value"><?= ($latestContract['home_town'] ?? '') !== '' ? h($latestContract['home_town']) : '<span class="muted">—</span>' ?></div></div>
        <div class="kv-row"><div class="kv-label">Nearest airport</div> <div class="kv-value"><?= ($latestContract['nearest_airport'] ?? '') !== '' ? h($latestContract['nearest_airport']) : '<span class="muted">—</span>' ?></div></div>
        <div class="kv-row"><div class="kv-label">Beneficiary</div>     <div class="kv-value"><?= ($latestContract['beneficiary_name'] ?? '') !== '' ? h($latestContract['beneficiary_name']) : '<span class="muted">—</span>' ?></div></div>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
