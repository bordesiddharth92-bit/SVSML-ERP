<?php
/**
 * SVSML-ERP — Crew Onboarding (Module 18)
 *
 * Multi-step form shown once on first sign-in, before the crew can
 * use the rest of the portal. Splits into four logical steps:
 *
 *     0. Agreement      — Privacy + T&Cs + acknowledgement (checkboxes only)
 *     1. Personal       — name / rank / DOB / place of birth / nationality /
 *                         passport / CDC / contact / address / expected
 *                         departure / contract period / profile photo
 *     2. Bank           — account holder / no / bank / IFSC
 *     3. Next of kin    — up to 2 entries (name / address / relation /
 *                         percentage / mobile×2 / email)
 *
 * Each step persists incrementally, so the crew can bail out at any
 * point and pick up where they left off on next login. Step 3 saves
 * next_of_kin as JSON in the (already-migrated) crew.next_of_kin
 * column, sets onboarding_complete = 1, stamps onboarded_at, and
 * redirects to /crew-portal.php.
 *
 * Permissions: signed-in crew only (requireCrew, NOT
 * requireOnboardedCrew, otherwise they'd be redirected back here in
 * an infinite loop).
 *
 * The "Assignment" block (vessel / company / rank assignment) is
 * intentionally NOT in this form — that stays admin-managed. The
 * crew can pick their own rank during onboarding, but vessel and
 * company are read-only.
 */

require __DIR__ . '/config/app.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/helpers.php';

requireCrew();
$crewId = currentCrewId();
if (!$crewId) {
    flash('error', 'Session error — please sign in again.');
    header('Location: ' . url('crew-login.php'));
    exit;
}

// -------------------------------------------------------------
// Schema probe — degrade gracefully if CHANGES.sql wasn't applied.
// -------------------------------------------------------------
$hasOnboardingCol = false;
try {
    $col = $pdo->query("SHOW COLUMNS FROM crew LIKE 'onboarding_complete'")->fetch();
    $hasOnboardingCol = (bool)$col;
} catch (PDOException $e) { /* ignore */ }

// Already onboarded? Send them to the portal.
if ($hasOnboardingCol) {
    $st = $pdo->prepare("SELECT onboarding_complete FROM crew WHERE id = :i");
    $st->execute([':i' => $crewId]);
    if ((int)$st->fetchColumn() === 1) {
        header('Location: ' . url('crew-portal.php'));
        exit;
    }
}

$crew = fetchCrewWithJoins($pdo, $crewId);
if (!$crew) {
    flash('error', 'Your crew record was not found.');
    logoutCurrentUser();
    header('Location: ' . url('crew-login.php'));
    exit;
}

// What step are we on? Default 0; bumped only after a step posts cleanly.
$step = isset($_GET['step']) ? max(0, min(3, (int)$_GET['step'])) : 0;

/**
 * Decode the existing crew.next_of_kin JSON into a normalised array
 * of up to 2 entries (each with the same shape as the form fields).
 * Tolerates blank / malformed values by returning an empty array.
 */
function decodeKinJson(?string $raw): array
{
    if ($raw === null || trim($raw) === '') return [];
    $arr = json_decode($raw, true);
    if (!is_array($arr)) return [];
    $out = [];
    foreach ($arr as $k) {
        if (!is_array($k)) continue;
        $out[] = [
            'name'       => (string)($k['name']       ?? ''),
            'address'    => (string)($k['address']    ?? ''),
            'relation'   => (string)($k['relation']   ?? ''),
            'percentage' => (string)($k['percentage'] ?? ''),
            'mobile1'    => (string)($k['mobile1']    ?? ''),
            'mobile2'    => (string)($k['mobile2']    ?? ''),
            'email'      => (string)($k['email']      ?? ''),
        ];
        if (count($out) >= 2) break;
    }
    return $out;
}

/** Empty kin record used to pad up to two slots in the form. */
function emptyKin(): array
{
    return [
        'name' => '', 'address' => '', 'relation' => '', 'percentage' => '',
        'mobile1' => '', 'mobile2' => '', 'email' => '',
    ];
}

// -------------------------------------------------------------
// POST handlers — one per step
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    /* ---- Step 0: agreement ---- */
    if ($action === 'accept_agreement') {
        $errors = [];
        foreach (['agree_privacy', 'agree_terms', 'agree_rights'] as $key) {
            if (empty($_POST[$key])) $errors[] = 'You must tick all three acknowledgements to continue.';
        }
        if (empty($errors)) {
            header('Location: ' . url('crew-onboarding.php?step=1'));
            exit;
        }
        foreach (array_unique($errors) as $e) flash('error', $e);
        $step = 0;
    }

    /* ---- Step 1: personal details + photo ---- */
    elseif ($action === 'save_personal') {
        $fullName    = trim($_POST['full_name']    ?? '');
        $rankId      = !empty($_POST['rank_id']) ? (int)$_POST['rank_id'] : null;
        $dob         = trim($_POST['date_of_birth']  ?? '');
        $placeBirth  = trim($_POST['place_of_birth'] ?? '');
        $nationality = trim($_POST['nationality']    ?? '');
        $passport    = normalizeUpperTrim($_POST['passport_number'] ?? '');
        $cdc         = normalizeUpperTrim($_POST['cdc_number']      ?? '');
        $contact     = normalizeMobileValue($_POST['contact_number'] ?? '');
        $address     = trim($_POST['full_address']    ?? '');
        $expectedDep = trim($_POST['expected_departure_date'] ?? '');
        $contractPeriod = trim($_POST['contract_period_months'] ?? '');

        $errors = [];
        if ($fullName === '') $errors[] = 'Full name is required.';
        if ($rankId  === null) $errors[] = 'Please select your rank.';
        if ($dob !== '' && !DateTime::createFromFormat('Y-m-d', $dob)) $errors[] = 'Invalid date of birth.';
        if ($expectedDep !== '' && !DateTime::createFromFormat('Y-m-d', $expectedDep)) $errors[] = 'Invalid expected departure date.';
        if (($e = validatePassportField($passport, $nationality !== '' ? $nationality : null)) !== null) $errors[] = $e;
        if ($cdc !== '' && ($e = validateCDCField($cdc))     !== null) $errors[] = $e;
        if (($e = validateMobileField($contact))             !== null) $errors[] = $e;

        // INDOS / passport uniqueness against OTHER crew rows (allow self).
        if (empty($errors) && !isPassportUnique($pdo, $passport, $crewId)) {
            $errors[] = "Passport '{$passport}' is already used by another crew member.";
        }

        if (!empty($errors)) {
            foreach ($errors as $e) flash('error', $e);
        } else {
            try {
                $pdo->beginTransaction();
                $pdo->prepare(
                    "UPDATE crew SET
                        full_name              = :n,
                        rank_id                = :r,
                        date_of_birth          = :dob,
                        place_of_birth         = :pob,
                        nationality            = :nat,
                        passport_number        = :pass,
                        cdc_number             = :cdc,
                        contact_number         = :con,
                        full_address           = :addr,
                        expected_departure_date = :exp,
                        contract_period_months = :cp,
                        updated_at             = CURRENT_TIMESTAMP
                      WHERE id = :i"
                )->execute([
                    ':n'    => $fullName,
                    ':r'    => $rankId,
                    ':dob'  => $dob !== '' ? $dob : null,
                    ':pob'  => $placeBirth !== '' ? $placeBirth : null,
                    ':nat'  => $nationality !== '' ? $nationality : null,
                    ':pass' => $passport !== '' ? $passport : null,
                    ':cdc'  => $cdc !== '' ? $cdc : null,
                    ':con'  => $contact !== '' ? $contact : null,
                    ':addr' => $address !== '' ? $address : null,
                    ':exp'  => $expectedDep !== '' ? $expectedDep : null,
                    ':cp'   => $contractPeriod !== '' ? $contractPeriod : null,
                    ':i'    => $crewId,
                ]);

                // Re-fetch with the just-saved rank/company so the
                // upload helper has the right path components.
                $freshCrew = fetchCrewWithJoins($pdo, $crewId);

                // Profile photo (optional).
                if (!empty($_FILES['profile_photo']['name'])) {
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
                logActivity($pdo, null, 'update', 'crew_onboarding', $crewId,
                    "Crew {$crewId} saved Step 1 (personal details)");
                flash('success', 'Personal details saved.');
                header('Location: ' . url('crew-onboarding.php?step=2'));
                exit;
            } catch (PDOException $dbErr) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('[SVSML-ERP] onboarding step 1: ' . $dbErr->getMessage());
                flash('error', 'Could not save: ' . $dbErr->getMessage());
            }
        }
        $step = 1;
    }

    /* ---- Step 2: bank details ---- */
    elseif ($action === 'save_bank') {
        $holder  = trim($_POST['bank_account_holder'] ?? '');
        $accNo   = trim($_POST['bank_account_no']     ?? '');
        $bank    = trim($_POST['bank_name']           ?? '');
        $ifsc    = strtoupper(trim($_POST['bank_ifsc'] ?? ''));

        $errors = [];
        // Bank fields are encouraged but not strictly required — the
        // crew might not have an account yet on day 1. We DO sanity-
        // check format when something is typed.
        if ($accNo !== '' && !preg_match('/^[A-Z0-9]{6,30}$/i', $accNo)) {
            $errors[] = 'Account number must be 6–30 alphanumeric characters.';
        }
        if ($ifsc !== '' && !preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', $ifsc)) {
            $errors[] = 'IFSC must be in the standard 11-character format (e.g. SBIN0001234).';
        }

        if (!empty($errors)) {
            foreach ($errors as $e) flash('error', $e);
        } else {
            try {
                $pdo->prepare(
                    "UPDATE crew SET
                        bank_account_holder = :h,
                        bank_account_no     = :a,
                        bank_name           = :b,
                        bank_ifsc           = :i,
                        updated_at          = CURRENT_TIMESTAMP
                      WHERE id = :id"
                )->execute([
                    ':h' => $holder !== '' ? $holder : null,
                    ':a' => $accNo  !== '' ? $accNo  : null,
                    ':b' => $bank   !== '' ? $bank   : null,
                    ':i' => $ifsc   !== '' ? $ifsc   : null,
                    ':id' => $crewId,
                ]);
                logActivity($pdo, null, 'update', 'crew_onboarding', $crewId,
                    "Crew {$crewId} saved Step 2 (bank)");
                flash('success', 'Bank details saved.');
                header('Location: ' . url('crew-onboarding.php?step=3'));
                exit;
            } catch (PDOException $dbErr) {
                error_log('[SVSML-ERP] onboarding step 2: ' . $dbErr->getMessage());
                flash('error', 'Could not save: ' . $dbErr->getMessage());
            }
        }
        $step = 2;
    }

    /* ---- Step 3: next of kin + finalise ---- */
    elseif ($action === 'save_kin') {
        $kinList   = [];
        $errors    = [];
        $totalPct  = 0.0;
        for ($i = 0; $i < 2; $i++) {
            $name    = trim($_POST['kin'][$i]['name']    ?? '');
            $address = trim($_POST['kin'][$i]['address'] ?? '');
            $relation = trim($_POST['kin'][$i]['relation'] ?? '');
            $pctRaw  = trim($_POST['kin'][$i]['percentage'] ?? '');
            $mob1    = normalizeMobileValue($_POST['kin'][$i]['mobile1'] ?? '');
            $mob2    = normalizeMobileValue($_POST['kin'][$i]['mobile2'] ?? '');
            $email   = normalizeEmailValue($_POST['kin'][$i]['email']    ?? '');

            // Skip entirely empty rows.
            if ($name === '' && $address === '' && $relation === ''
                && $pctRaw === '' && $mob1 === '' && $mob2 === '' && $email === '') continue;

            // Per-field validation only when there's something to check.
            if ($name === '')                     $errors[] = 'Kin #' . ($i+1) . ': name is required.';
            if ($mob1 !== '' && ($e = validateMobileField($mob1)) !== null) $errors[] = 'Kin #' . ($i+1) . ' mobile 1: ' . $e;
            if ($mob2 !== '' && ($e = validateMobileField($mob2)) !== null) $errors[] = 'Kin #' . ($i+1) . ' mobile 2: ' . $e;
            if ($email !== '' && ($e = validateEmailField($email)) !== null) $errors[] = 'Kin #' . ($i+1) . ' email: ' . $e;

            $pct = null;
            if ($pctRaw !== '') {
                if (!is_numeric($pctRaw))                 $errors[] = 'Kin #' . ($i+1) . ': percentage must be a number.';
                elseif ((float)$pctRaw < 0 || (float)$pctRaw > 100) $errors[] = 'Kin #' . ($i+1) . ': percentage must be between 0 and 100.';
                else                                      $pct = (float)$pctRaw;
            }
            if ($pct !== null) $totalPct += $pct;

            $kinList[] = [
                'name'       => $name,
                'address'    => $address,
                'relation'   => $relation,
                'percentage' => $pct === null ? '' : (string)$pct,
                'mobile1'    => $mob1,
                'mobile2'    => $mob2,
                'email'      => $email,
            ];
        }

        if ($totalPct > 100.0001) {
            $errors[] = 'Total percentage across kin entries cannot exceed 100.';
        }

        if (!empty($errors)) {
            foreach ($errors as $e) flash('error', $e);
        } else {
            try {
                $pdo->beginTransaction();
                $pdo->prepare(
                    "UPDATE crew SET
                        next_of_kin         = :k,
                        onboarding_complete = 1,
                        onboarded_at        = CURRENT_TIMESTAMP,
                        updated_at          = CURRENT_TIMESTAMP
                      WHERE id = :i"
                )->execute([
                    ':k' => empty($kinList) ? null : json_encode($kinList, JSON_UNESCAPED_UNICODE),
                    ':i' => $crewId,
                ]);
                $pdo->commit();
                logActivity($pdo, null, 'update', 'crew_onboarding', $crewId,
                    "Crew {$crewId} completed onboarding (" . count($kinList) . " kin entries)");
                flash('success', 'Welcome aboard! Your profile is ready.');
                header('Location: ' . url('crew-portal.php'));
                exit;
            } catch (PDOException $dbErr) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('[SVSML-ERP] onboarding step 3: ' . $dbErr->getMessage());
                flash('error', 'Could not finalise onboarding: ' . $dbErr->getMessage());
            }
        }
        $step = 3;
    }
}

// -------------------------------------------------------------
// GET — render the active step
// -------------------------------------------------------------
$crew = fetchCrewWithJoins($pdo, $crewId);
$kin  = decodeKinJson($crew['next_of_kin'] ?? null);
while (count($kin) < 2) $kin[] = emptyKin();

$ranks      = $pdo->query("SELECT id, rank_name FROM ranks ORDER BY rank_name")->fetchAll();
$nationalities = TRAVEL_COUNTRIES;

$pageTitle = 'Welcome aboard — Onboarding';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title><?= h($pageTitle) ?> &mdash; <?= h(APP_NAME) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="<?= asset('assets/css/style.css') ?>">
</head>
<body class="onboarding-body">
<div class="onboarding-wrap">
    <div class="onboarding-card">
        <header class="onboarding-header">
            <div class="brand-logo"><?= h(APP_SHORT) ?></div>
            <div>
                <div class="onboarding-title">Welcome aboard, <?= h(explode(' ', $crew['full_name'])[0]) ?>.</div>
                <div class="onboarding-subtitle">A few quick steps before we get you sailing.</div>
            </div>
        </header>

        <ol class="onboarding-steps" aria-label="Progress">
            <?php foreach (['Agreement','Personal','Bank','Next of kin'] as $idx => $lbl): ?>
                <li class="<?= $idx === $step ? 'active' : ($idx < $step ? 'done' : '') ?>">
                    <span class="onboarding-step-num"><?= $idx + 1 ?></span>
                    <span class="onboarding-step-lbl"><?= h($lbl) ?></span>
                </li>
            <?php endforeach; ?>
        </ol>

        <?php foreach (getFlashes() as $f): ?>
            <div class="flash flash-<?= h($f['type']) ?>"><?= h($f['message']) ?></div>
        <?php endforeach; ?>

        <?php if (!$hasOnboardingCol): ?>
            <div class="flash flash-warning">
                <strong>Database migration pending.</strong>
                Onboarding requires <code>CHANGES.sql</code> to be applied first.
                Please contact your manning agent.
            </div>
            <p class="help-text"><a href="<?= asset('crew-portal.php') ?>">Skip for now &rarr;</a></p>

        <?php elseif ($step === 0): ?>
            <h3 class="card-title">Step 1 of 4 &mdash; Agreement</h3>
            <p>
                Before you continue, please read and acknowledge the points below.
                These are the same terms shown on the SVSML public website.
            </p>
            <form method="post" novalidate>
                <?= csrfField() ?>
                <input type="hidden" name="action" value="accept_agreement">

                <div class="agree-row">
                    <input type="checkbox" id="agree_privacy" name="agree_privacy" value="1" required>
                    <label for="agree_privacy">
                        <strong>Privacy Policy.</strong>
                        I have read SVSML's Privacy Policy and consent to my personal,
                        professional and document data being processed for the purposes
                        of crew management, statutory compliance and contract execution.
                    </label>
                </div>

                <div class="agree-row">
                    <input type="checkbox" id="agree_terms" name="agree_terms" value="1" required>
                    <label for="agree_terms">
                        <strong>Terms &amp; Conditions.</strong>
                        I accept the SVSML Terms &amp; Conditions and the Code of Conduct
                        applicable to crew engaged through the manning agency.
                    </label>
                </div>

                <div class="agree-row">
                    <input type="checkbox" id="agree_rights" name="agree_rights" value="1" required>
                    <label for="agree_rights">
                        <strong>All Rights Reserved.</strong>
                        I acknowledge that all content, branding and materials in this
                        portal are the property of Sea Voyage Ship Management LLP, and
                        I will not redistribute or republish them.
                    </label>
                </div>

                <div class="contact-block">
                    <h4>Contact us</h4>
                    <p class="help-text">
                        For any clarifications about these terms, please reach out to SVSML on
                        <a href="mailto:contact@seavoyageship.com">contact@seavoyageship.com</a>
                        or call your manning agent. SVSML's RPSL details are available on request.
                    </p>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn">I agree, continue</button>
                </div>
            </form>

        <?php elseif ($step === 1): ?>
            <h3 class="card-title">Step 2 of 4 &mdash; Personal details</h3>
            <p class="help-text">Fill what you can — anything left blank can be updated later by SVSML.</p>
            <form method="post" enctype="multipart/form-data" novalidate>
                <?= csrfField() ?>
                <input type="hidden" name="action" value="save_personal">

                <div class="form-grid">
                    <div class="form-row full-row">
                        <label for="full_name">Full name *</label>
                        <input type="text" id="full_name" name="full_name" required maxlength="100"
                               value="<?= h($crew['full_name'] ?? '') ?>">
                    </div>
                    <div class="form-row">
                        <label for="rank_id">Rank *</label>
                        <select id="rank_id" name="rank_id" required>
                            <option value="">— Select rank —</option>
                            <?php foreach ($ranks as $r): ?>
                                <option value="<?= (int)$r['id'] ?>" <?= ((int)($crew['rank_id'] ?? 0) === (int)$r['id']) ? 'selected' : '' ?>>
                                    <?= h($r['rank_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-row">
                        <label for="date_of_birth">Date of birth</label>
                        <input type="date" id="date_of_birth" name="date_of_birth"
                               value="<?= h($crew['date_of_birth'] ?? '') ?>">
                    </div>
                    <div class="form-row">
                        <label for="place_of_birth">Place of birth</label>
                        <input type="text" id="place_of_birth" name="place_of_birth" maxlength="100"
                               value="<?= h($crew['place_of_birth'] ?? '') ?>">
                    </div>
                    <div class="form-row">
                        <label for="nationality">Nationality</label>
                        <input list="dl-countries" id="nationality" name="nationality" maxlength="80"
                               value="<?= h($crew['nationality'] ?? '') ?>" autocomplete="off">
                        <datalist id="dl-countries">
                            <?php foreach ($nationalities as $c): ?>
                                <option value="<?= h($c) ?>"></option>
                            <?php endforeach; ?>
                        </datalist>
                    </div>
                    <div class="form-row">
                        <label for="passport_number">Passport number *</label>
                        <input type="text" id="passport_number" name="passport_number" required maxlength="50"
                               data-validate="passport"
                               value="<?= h($crew['passport_number'] ?? '') ?>"
                               placeholder="N1234567">
                    </div>
                    <div class="form-row">
                        <label for="cdc_number">Seaman / CDC book number</label>
                        <input type="text" id="cdc_number" name="cdc_number" maxlength="50"
                               data-validate="cdc"
                               value="<?= h($crew['cdc_number'] ?? '') ?>"
                               placeholder="MUM123456">
                    </div>
                    <div class="form-row">
                        <label for="contact_number">Contact number *</label>
                        <input type="text" id="contact_number" name="contact_number" required maxlength="20"
                               data-validate="mobile"
                               value="<?= h($crew['contact_number'] ?? '') ?>"
                               placeholder="+919876543210">
                    </div>
                    <div class="form-row full-row">
                        <label for="full_address">Home address</label>
                        <textarea id="full_address" name="full_address" rows="2"><?= h($crew['full_address'] ?? '') ?></textarea>
                    </div>
                    <div class="form-row">
                        <label for="expected_departure_date">Expected date of departure</label>
                        <input type="date" id="expected_departure_date" name="expected_departure_date"
                               value="<?= h($crew['expected_departure_date'] ?? '') ?>">
                    </div>
                    <div class="form-row">
                        <label for="contract_period_months">Contract period</label>
                        <input type="text" id="contract_period_months" name="contract_period_months" maxlength="50"
                               value="<?= h($crew['contract_period_months'] ?? '') ?>"
                               placeholder="e.g. 6 months &plusmn; 1">
                    </div>
                    <div class="form-row full-row">
                        <label for="profile_photo">Profile photo (PDF / JPG / PNG / DOCX, max 10 MB)</label>
                        <input type="file" id="profile_photo" name="profile_photo"
                               accept=".pdf,.jpg,.jpeg,.png,.docx">
                        <?php if (!empty($crew['profile_photo'])): ?>
                            <p class="help-text">Existing photo on file — uploading a new one will replace it.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="form-actions">
                    <a class="btn btn-ghost" href="<?= asset('crew-onboarding.php?step=0') ?>">&larr; Back</a>
                    <button type="submit" class="btn">Save and continue &rarr;</button>
                </div>
            </form>

        <?php elseif ($step === 2): ?>
            <h3 class="card-title">Step 3 of 4 &mdash; Bank details</h3>
            <p class="help-text">Used for payroll and reimbursements. You can leave this for later if you don't have details ready.</p>
            <form method="post" novalidate>
                <?= csrfField() ?>
                <input type="hidden" name="action" value="save_bank">

                <div class="form-grid">
                    <div class="form-row">
                        <label for="bank_account_holder">Account holder name</label>
                        <input type="text" id="bank_account_holder" name="bank_account_holder" maxlength="120"
                               value="<?= h($crew['bank_account_holder'] ?? '') ?>">
                    </div>
                    <div class="form-row">
                        <label for="bank_account_no">Account number</label>
                        <input type="text" id="bank_account_no" name="bank_account_no" maxlength="50"
                               value="<?= h($crew['bank_account_no'] ?? '') ?>">
                    </div>
                    <div class="form-row">
                        <label for="bank_name">Bank name</label>
                        <input type="text" id="bank_name" name="bank_name" maxlength="120"
                               value="<?= h($crew['bank_name'] ?? '') ?>">
                    </div>
                    <div class="form-row">
                        <label for="bank_ifsc">IFSC code</label>
                        <input type="text" id="bank_ifsc" name="bank_ifsc" maxlength="20"
                               value="<?= h($crew['bank_ifsc'] ?? '') ?>"
                               placeholder="SBIN0001234"
                               style="text-transform: uppercase;">
                    </div>
                </div>

                <div class="form-actions">
                    <a class="btn btn-ghost" href="<?= asset('crew-onboarding.php?step=1') ?>">&larr; Back</a>
                    <button type="submit" class="btn">Save and continue &rarr;</button>
                </div>
            </form>

        <?php else: /* step 3 */ ?>
            <h3 class="card-title">Step 4 of 4 &mdash; Next of kin</h3>
            <p class="help-text">Add up to two emergency contacts. The percentage is the share of any payout in the event of an emergency — leave blank if not applicable.</p>
            <form method="post" novalidate>
                <?= csrfField() ?>
                <input type="hidden" name="action" value="save_kin">

                <?php for ($i = 0; $i < 2; $i++): $row = $kin[$i]; ?>
                    <div class="kin-block">
                        <div class="section-title">Kin #<?= $i + 1 ?> <?= $i === 0 ? '<small style="font-weight:400;color:var(--text-muted)">(required)</small>' : '<small style="font-weight:400;color:var(--text-muted)">(optional)</small>' ?></div>
                        <div class="form-grid">
                            <div class="form-row">
                                <label>Name <?= $i === 0 ? '*' : '' ?></label>
                                <input type="text" name="kin[<?= $i ?>][name]" maxlength="100"
                                       <?= $i === 0 ? 'required' : '' ?>
                                       value="<?= h($row['name']) ?>">
                            </div>
                            <div class="form-row">
                                <label>Relation</label>
                                <input type="text" name="kin[<?= $i ?>][relation]" maxlength="50"
                                       value="<?= h($row['relation']) ?>"
                                       placeholder="Spouse / Parent / Sibling">
                            </div>
                            <div class="form-row full-row">
                                <label>Address</label>
                                <textarea name="kin[<?= $i ?>][address]" rows="2"><?= h($row['address']) ?></textarea>
                            </div>
                            <div class="form-row">
                                <label>Percentage</label>
                                <input type="number" step="0.01" min="0" max="100"
                                       name="kin[<?= $i ?>][percentage]"
                                       value="<?= h($row['percentage']) ?>"
                                       placeholder="0–100">
                            </div>
                            <div class="form-row">
                                <label>Email</label>
                                <input type="email" name="kin[<?= $i ?>][email]" maxlength="100"
                                       data-validate="email"
                                       value="<?= h($row['email']) ?>">
                            </div>
                            <div class="form-row">
                                <label>Mobile 1</label>
                                <input type="text" name="kin[<?= $i ?>][mobile1]" maxlength="20"
                                       data-validate="mobile"
                                       value="<?= h($row['mobile1']) ?>"
                                       placeholder="+91...">
                            </div>
                            <div class="form-row">
                                <label>Mobile 2</label>
                                <input type="text" name="kin[<?= $i ?>][mobile2]" maxlength="20"
                                       data-validate="mobile"
                                       value="<?= h($row['mobile2']) ?>">
                            </div>
                        </div>
                    </div>
                <?php endfor; ?>

                <div class="form-actions">
                    <a class="btn btn-ghost" href="<?= asset('crew-onboarding.php?step=2') ?>">&larr; Back</a>
                    <button type="submit" class="btn btn-success">Finish onboarding &rarr;</button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>
<script src="<?= asset('assets/js/validators.js') ?>"></script>
</body>
</html>
