<?php
/**
 * SVSML-ERP — Crew self-service portal
 *
 * Module 16.
 *
 * Read-only single-page view of the crew's own data:
 *   - Personal details
 *   - Documents (passport, CDC, visa, SID) with expiry status
 *   - Medical certificates
 *   - Basic + Advanced courses
 *   - Sailing history
 *   - Sign on / off events
 *   - Contracts
 *   - Travel segments
 *
 * The crew CANNOT modify anything from this page. Edits go through SVSML
 * staff — that's by design (the crew is a read consumer here).
 *
 * The page also lets the crew change their own password.
 */

require __DIR__ . '/config/app.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/helpers.php';

requireCrew();

$crewId = currentCrewId();
$user   = currentUser();

if (!$crewId) {
    flash('error', 'Session error — please sign in again.');
    header('Location: ' . url('crew-login.php'));
    exit;
}

// -------------------------------------------------------------
// POST: change own password
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'change_password') {
        $current = (string)($_POST['current_password'] ?? '');
        $new     = (string)($_POST['new_password']     ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');

        $errors = [];
        if ($current === '' || $new === '' || $confirm === '') {
            $errors[] = 'All password fields are required.';
        }
        if ($new !== $confirm)   $errors[] = 'New password and confirmation do not match.';
        if (mb_strlen($new) < 8) $errors[] = 'New password must be at least 8 characters.';

        if (empty($errors)) {
            $row = $pdo->prepare("SELECT password_hash FROM crew WHERE id = :i");
            $row->execute([':i' => $crewId]);
            $hash = $row->fetchColumn();
            if (!$hash || !password_verify($current, $hash)) {
                $errors[] = 'Current password is incorrect.';
            }
        }

        if (!empty($errors)) {
            foreach ($errors as $e) flash('error', $e);
        } else {
            $newHash = password_hash($new, PASSWORD_BCRYPT);
            $pdo->prepare(
                "UPDATE crew
                    SET password_hash = :h, password_set_at = CURRENT_TIMESTAMP
                  WHERE id = :i"
            )->execute([':h' => $newHash, ':i' => $crewId]);
            // Activity log: user_id is null (crew is not in users table) — module captures the actor.
            logActivity(
                $pdo, null, 'update', 'crew_password', $crewId,
                "Crew {$crewId} changed own password"
            );
            flash('success', 'Password changed.');
        }
        header('Location: ' . url('crew-portal.php'));
        exit;
    }
}

// -------------------------------------------------------------
// GET: load every section the crew should see
// -------------------------------------------------------------
$crew = fetchCrewWithJoins($pdo, $crewId);
if (!$crew) {
    flash('error', 'Your crew record was not found.');
    logoutCurrentUser();
    header('Location: ' . url('crew-login.php'));
    exit;
}

$documents = $pdo->prepare(
    "SELECT cd.*, d.label AS visa_type_label
       FROM crew_documents cd
       LEFT JOIN dropdown_items d ON d.id = cd.visa_type_id
      WHERE cd.crew_id = :c
      ORDER BY cd.document_type, cd.id"
);
$documents->execute([':c' => $crewId]);
$documents = $documents->fetchAll();

$medical = $pdo->prepare(
    "SELECT cm.*, d.label AS medical_type_label
       FROM crew_medical cm
       LEFT JOIN dropdown_items d ON d.id = cm.medical_type_id
      WHERE cm.crew_id = :c
      ORDER BY cm.id"
);
$medical->execute([':c' => $crewId]);
$medical = $medical->fetchAll();

$basic = $pdo->prepare("SELECT * FROM basic_courses    WHERE crew_id = :c ORDER BY course_name");
$basic->execute([':c' => $crewId]);
$basic = $basic->fetchAll();

$advanced = $pdo->prepare("SELECT * FROM advanced_courses WHERE crew_id = :c ORDER BY course_name");
$advanced->execute([':c' => $crewId]);
$advanced = $advanced->fetchAll();

$sailing = $pdo->prepare(
    "SELECT sh.*, r.rank_name, v.vessel_name, c.company_name
       FROM sailing_history sh
       LEFT JOIN ranks     r ON r.id = sh.rank_id
       LEFT JOIN vessels   v ON v.id = sh.vessel_id
       LEFT JOIN companies c ON c.id = sh.company_id
      WHERE sh.crew_id = :c
      ORDER BY sh.sign_on_date DESC, sh.id DESC"
);
$sailing->execute([':c' => $crewId]);
$sailing = $sailing->fetchAll();

$signOn = $pdo->prepare(
    "SELECT * FROM sign_on_off WHERE crew_id = :c ORDER BY sign_on_date DESC, id DESC"
);
$signOn->execute([':c' => $crewId]);
$signOn = $signOn->fetchAll();

$contracts = $pdo->prepare(
    "SELECT * FROM contracts WHERE crew_id = :c ORDER BY id DESC"
);
$contracts->execute([':c' => $crewId]);
$contracts = $contracts->fetchAll();

$travel = $pdo->prepare(
    "SELECT * FROM travel_details WHERE crew_id = :c ORDER BY sr_number, id"
);
$travel->execute([':c' => $crewId]);
$travel = $travel->fetchAll();

$pageTitle = 'My Profile';
include __DIR__ . '/includes/header.php';
?>

<div class="card">
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
        <div>
            <strong style="font-size:18px;"><?= h($crew['full_name']) ?></strong>
            <span class="help-text">
                <?php if (!empty($crew['rank_name'])):    ?> &middot; <?= h($crew['rank_name']) ?>    <?php endif; ?>
                <?php if (!empty($crew['vessel_name'])):  ?> &middot; <?= h($crew['vessel_name']) ?>  <?php endif; ?>
                <?php if (!empty($crew['company_name'])): ?> &middot; <?= h($crew['company_name']) ?> <?php endif; ?>
            </span>
        </div>
        <span class="badge badge-crew">CREW</span>
    </div>
    <p class="help-text" style="margin-top:10px;">
        This is a read-only view. To update any information please contact SVSML.
    </p>
</div>

<div class="card">
    <h3 class="card-title">Personal details</h3>
    <div class="form-grid">
        <div class="form-row"><label>Full name</label><div><?= h($crew['full_name']) ?></div></div>
        <div class="form-row"><label>Date of birth</label><div><?= h($crew['date_of_birth'] ?? '—') ?></div></div>
        <div class="form-row"><label>INDOS</label><div><?= h($crew['indos_number'] ?? '—') ?></div></div>
        <div class="form-row"><label>Passport</label><div><?= h($crew['passport_number'] ?? '—') ?></div></div>
        <div class="form-row"><label>Contact number</label><div><?= h($crew['contact_number'] ?? '—') ?></div></div>
        <div class="form-row"><label>Email</label><div><?= h($crew['email'] ?? '—') ?></div></div>
        <div class="form-row full-row"><label>Address</label><div><?= nl2br(h($crew['full_address'] ?? '—')) ?></div></div>
        <div class="form-row"><label>Rank</label><div><?= h($crew['rank_name'] ?? '—') ?></div></div>
        <div class="form-row"><label>Joiner type</label>
            <div>
                <?php if ($crew['joiner_type'] === 'rejoiner'): ?>
                    <span class="status status-green">Rejoiner</span>
                <?php else: ?>
                    <span class="status status-gray">New joiner</span>
                <?php endif; ?>
            </div>
        </div>
        <div class="form-row"><label>Company</label><div><?= h($crew['company_name'] ?? '—') ?></div></div>
        <div class="form-row"><label>Vessel</label><div><?= h($crew['vessel_name']  ?? '—') ?></div></div>
    </div>
</div>

<div class="card">
    <h3 class="card-title">Documents <small class="help-text">(<?= count($documents) ?>)</small></h3>
    <?php if (empty($documents)): ?>
        <p class="help-text">No documents on file yet.</p>
    <?php else: ?>
        <table class="data-table">
            <thead>
                <tr><th>Type</th><th>Number</th><th>Issue</th><th>Expiry</th><th>Status</th><th>File</th></tr>
            </thead>
            <tbody>
                <?php foreach ($documents as $d):
                    $label = strtoupper($d['document_type']);
                    if ($d['document_type'] === 'visa' && !empty($d['visa_type_label'])) {
                        $label .= ' (' . $d['visa_type_label'] . ')';
                    }
                ?>
                    <tr>
                        <td><?= h($label) ?></td>
                        <td><?= h($d['document_number'] ?? '—') ?></td>
                        <td><?= h($d['issue_date']  ?? '—') ?></td>
                        <td><?= h($d['expiry_date'] ?? '—') ?></td>
                        <td><?= dateStatusBadge($d['expiry_date'] ?? null) ?></td>
                        <td>
                            <?php if (!empty($d['file_path'])): ?>
                                <a class="action-link" target="_blank" href="<?= asset('uploads/' . $d['file_path']) ?>">View</a>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<div class="card">
    <h3 class="card-title">Medical certificates <small class="help-text">(<?= count($medical) ?>)</small></h3>
    <?php if (empty($medical)): ?>
        <p class="help-text">No medical certificates on file yet.</p>
    <?php else: ?>
        <table class="data-table">
            <thead><tr><th>Type</th><th>Issue</th><th>Expiry</th><th>Status</th><th>File</th></tr></thead>
            <tbody>
                <?php foreach ($medical as $m): ?>
                    <tr>
                        <td><?= h($m['medical_type_label'] ?? '—') ?></td>
                        <td><?= h($m['issue_date']  ?? '—') ?></td>
                        <td><?= h($m['expiry_date'] ?? '—') ?></td>
                        <td><?= dateStatusBadge($m['expiry_date'] ?? null) ?></td>
                        <td>
                            <?php if (!empty($m['file_path'])): ?>
                                <a class="action-link" target="_blank" href="<?= asset('uploads/' . $m['file_path']) ?>">View</a>
                            <?php else: ?> — <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<div class="card">
    <h3 class="card-title">Courses</h3>
    <div class="section-title">Basic courses (<?= count($basic) ?>)</div>
    <?php if (empty($basic)): ?>
        <p class="help-text">No basic courses on file.</p>
    <?php else: ?>
        <table class="data-table">
            <thead><tr><th>Course</th><th>Number</th><th>Issue</th><th>Expiry</th><th>Status</th><th>File</th></tr></thead>
            <tbody>
                <?php foreach ($basic as $c): ?>
                    <tr>
                        <td><?= h($c['course_name']) ?></td>
                        <td><?= h($c['course_number'] ?? '—') ?></td>
                        <td><?= h($c['issue_date']    ?? '—') ?></td>
                        <td><?= h($c['expiry_date']   ?? '—') ?></td>
                        <td><?= dateStatusBadge($c['expiry_date'] ?? null) ?></td>
                        <td><?php if (!empty($c['file_path'])): ?><a class="action-link" target="_blank" href="<?= asset('uploads/' . $c['file_path']) ?>">View</a><?php else: ?> — <?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <div class="section-title">Advanced courses (<?= count($advanced) ?>)</div>
    <?php if (empty($advanced)): ?>
        <p class="help-text">No advanced courses on file.</p>
    <?php else: ?>
        <table class="data-table">
            <thead><tr><th>Course</th><th>Number</th><th>Issue</th><th>Expiry</th><th>Status</th><th>File</th></tr></thead>
            <tbody>
                <?php foreach ($advanced as $c): ?>
                    <tr>
                        <td><?= h($c['course_name']) ?></td>
                        <td><?= h($c['course_number'] ?? '—') ?></td>
                        <td><?= h($c['issue_date']    ?? '—') ?></td>
                        <td><?= h($c['expiry_date']   ?? '—') ?></td>
                        <td><?= dateStatusBadge($c['expiry_date'] ?? null) ?></td>
                        <td><?php if (!empty($c['file_path'])): ?><a class="action-link" target="_blank" href="<?= asset('uploads/' . $c['file_path']) ?>">View</a><?php else: ?> — <?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<div class="card">
    <h3 class="card-title">Sign on / off <small class="help-text">(<?= count($signOn) ?>)</small></h3>
    <?php if (empty($signOn)): ?>
        <p class="help-text">No sign-on / off events recorded.</p>
    <?php else: ?>
        <table class="data-table">
            <thead><tr><th>Sign on</th><th>Sign off</th><th>Days</th></tr></thead>
            <tbody>
                <?php foreach ($signOn as $e): ?>
                    <tr>
                        <td><?= h($e['sign_on_date']) ?></td>
                        <td><?= h($e['sign_off_date'] ?? '— still onboard —') ?></td>
                        <td><?= h((string)($e['days_on_board'] ?? '—')) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<div class="card">
    <h3 class="card-title">Sailing history <small class="help-text">(<?= count($sailing) ?>)</small></h3>
    <?php if (empty($sailing)): ?>
        <p class="help-text">No sailing history yet.</p>
    <?php else: ?>
        <table class="data-table">
            <thead>
                <tr><th>Vessel</th><th>Company</th><th>Rank</th><th>Sign on</th><th>Sign off</th><th>Days</th></tr>
            </thead>
            <tbody>
                <?php foreach ($sailing as $s): ?>
                    <tr>
                        <td><?= h($s['vessel_name']  ?? '—') ?></td>
                        <td><?= h($s['company_name'] ?? '—') ?></td>
                        <td><?= h($s['rank_name']    ?? '—') ?></td>
                        <td><?= h($s['sign_on_date']  ?? '—') ?></td>
                        <td><?= h($s['sign_off_date'] ?? '—') ?></td>
                        <td><?= h((string)($s['days_on_vessel'] ?? '—')) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<div class="card">
    <h3 class="card-title">Contracts <small class="help-text">(<?= count($contracts) ?>)</small></h3>
    <?php if (empty($contracts)): ?>
        <p class="help-text">No contracts generated yet.</p>
    <?php else: ?>
        <table class="data-table">
            <thead><tr><th>Reference</th><th>Date</th><th>Period</th><th>SVSML PDF</th><th>Client status</th></tr></thead>
            <tbody>
                <?php foreach ($contracts as $c): ?>
                    <tr>
                        <td><strong><?= h($c['reference_number'] ?? '—') ?></strong></td>
                        <td><?= h($c['contract_date']   ?? '—') ?></td>
                        <td><?= h($c['contract_period'] ?? '—') ?></td>
                        <td>
                            <?php if (!empty($c['svsml_contract_path'])): ?>
                                <a class="action-link" target="_blank" href="<?= asset('uploads/' . $c['svsml_contract_path']) ?>">Download</a>
                            <?php else: ?> — <?php endif; ?>
                        </td>
                        <td>
                            <?php if (($c['client_contract_status'] ?? '') === 'uploaded'): ?>
                                <span class="status status-green">Uploaded</span>
                            <?php else: ?>
                                <span class="status status-gray">Pending</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<div class="card">
    <h3 class="card-title">Travel <small class="help-text">(<?= count($travel) ?>)</small></h3>
    <?php if (empty($travel)): ?>
        <p class="help-text">No travel arranged yet.</p>
    <?php else: ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th>#</th><th>Label</th><th>Departure</th><th>Arrival</th>
                    <th>Done</th><th>Status</th><th>File</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($travel as $t):
                    $rt = travelRowType($t['detail_label'], $t['field_type'] ?? 'default');
                ?>
                    <tr>
                        <td><?= (int)$t['sr_number'] ?></td>
                        <td><?= h($t['detail_label']) ?></td>
                        <td><?= h(travelFieldDisplay($t['departure'] ?? '', $rt)) ?></td>
                        <td><?= h(travelFieldDisplay($t['arrival']   ?? '', $rt)) ?></td>
                        <td>
                            <?= ((int)$t['is_done']) ? '<span class="status status-green">Done</span>' : '<span class="status status-gray">Open</span>' ?>
                        </td>
                        <td><?= travelStatusBadge($t['final_status'] ?? null) ?></td>
                        <td>
                            <?php if (!empty($t['file_path'])): ?>
                                <a class="action-link" target="_blank" href="<?= asset('uploads/' . $t['file_path']) ?>">View</a>
                            <?php else: ?> — <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<div class="card">
    <h3 class="card-title">Change my password</h3>
    <form method="post" novalidate>
        <?= csrfField() ?>
        <input type="hidden" name="action" value="change_password">
        <div class="form-grid">
            <div class="form-row">
                <label for="current_password">Current password *</label>
                <input type="password" id="current_password" name="current_password" autocomplete="current-password" required>
            </div>
            <div class="form-row"></div>
            <div class="form-row">
                <label for="new_password">New password *</label>
                <input type="password" id="new_password" name="new_password" autocomplete="new-password" minlength="8" required>
                <p class="help-text">At least 8 characters.</p>
            </div>
            <div class="form-row">
                <label for="confirm_password">Confirm new password *</label>
                <input type="password" id="confirm_password" name="confirm_password" autocomplete="new-password" minlength="8" required>
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn">Change password</button>
        </div>
    </form>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
