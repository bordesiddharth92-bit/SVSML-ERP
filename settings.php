<?php
/**
 * SVSML-ERP — System & Alert Settings
 *
 * Module 2.
 *
 * Edits the single-row `system_settings` (company info, feature toggles,
 * SMTP credentials, contract footer) and `alert_settings` (expiry
 * thresholds and dashboard/email notification toggles).
 *
 * Per the permissions matrix this page is admin / sub-admin only —
 * staff cannot edit system settings.
 *
 * Two independent forms, each with its own action and CSRF check.
 * The SMTP password is never re-displayed; submitting an empty
 * password field leaves the existing value intact.
 */

require __DIR__ . '/config/app.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/helpers.php';

requireRole(['admin', 'sub_admin']);

$user = currentUser();

// Helper: convert a checkbox post value to 0/1.
$cb = static function ($key): int {
    return !empty($_POST[$key]) ? 1 : 0;
};

// Helper: trim and return null when empty.
$nullable = static function ($key): ?string {
    $v = isset($_POST[$key]) ? trim((string)$_POST[$key]) : '';
    return $v === '' ? null : $v;
};

// -------------------------------------------------------------
// POST handlers
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_system') {
        $companyName = trim($_POST['company_name'] ?? '');
        $shortName   = trim($_POST['short_name']   ?? '');
        if ($companyName === '' || $shortName === '') {
            flash('error', 'Company name and short name are required.');
        } else {
            // Look up the existing row so we can preserve the SMTP password
            // when the form leaves that field blank.
            $existing = $pdo->query(
                "SELECT id, smtp_password FROM system_settings ORDER BY id ASC LIMIT 1"
            )->fetch();

            $smtpPasswordNew    = $_POST['smtp_password'] ?? '';
            $smtpPasswordToSave = ($smtpPasswordNew === '')
                ? ($existing['smtp_password'] ?? null)
                : $smtpPasswordNew;

            $params = [
                ':cn'   => $companyName,
                ':sn'   => $shortName,
                ':rn'   => $nullable('rpsl_number'),
                ':ce'   => $nullable('contact_email'),
                ':ph'   => $nullable('phone'),
                ':wb'   => $nullable('website'),
                ':ad'   => $nullable('address'),
                ':cft'  => $nullable('contract_footer_text'),
                ':csl'  => $cb('crew_self_login_enabled'),
                ':dpdp' => $cb('dpdp_consent_required'),
                ':ajd'  => $cb('auto_joiner_detection'),
                ':sal'  => $cb('staff_activity_logging'),
                ':ceas' => $cb('contract_email_auto_send'),
                ':sh'   => $nullable('smtp_host'),
                ':sp'   => ($nullable('smtp_port') !== null) ? (int)$_POST['smtp_port'] : null,
                ':se'   => $nullable('smtp_email'),
                ':spw'  => $smtpPasswordToSave,
            ];

            if ($existing) {
                // Update the existing row.
                $params[':i'] = (int)$existing['id'];
                $stmt = $pdo->prepare(
                    "UPDATE system_settings SET
                        company_name = :cn, short_name = :sn, rpsl_number = :rn,
                        contact_email = :ce, phone = :ph, website = :wb,
                        address = :ad, contract_footer_text = :cft,
                        crew_self_login_enabled = :csl, dpdp_consent_required = :dpdp,
                        auto_joiner_detection = :ajd, staff_activity_logging = :sal,
                        contract_email_auto_send = :ceas,
                        smtp_host = :sh, smtp_port = :sp,
                        smtp_email = :se, smtp_password = :spw
                     WHERE id = :i"
                );
                $stmt->execute($params);
                $rowId = (int)$existing['id'];
            } else {
                // First save — insert a row.
                $stmt = $pdo->prepare(
                    "INSERT INTO system_settings
                        (company_name, short_name, rpsl_number, contact_email, phone,
                         website, address, contract_footer_text,
                         crew_self_login_enabled, dpdp_consent_required,
                         auto_joiner_detection, staff_activity_logging,
                         contract_email_auto_send,
                         smtp_host, smtp_port, smtp_email, smtp_password)
                     VALUES
                        (:cn, :sn, :rn, :ce, :ph,
                         :wb, :ad, :cft,
                         :csl, :dpdp,
                         :ajd, :sal,
                         :ceas,
                         :sh, :sp, :se, :spw)"
                );
                $stmt->execute($params);
                $rowId = (int)$pdo->lastInsertId();
            }

            logActivity(
                $pdo, $user['id'], 'update', 'settings', $rowId,
                'System settings updated'
            );
            flash('success', 'System settings saved.');
        }
        header('Location: ' . url('settings.php#system'));
        exit;
    }

    if ($action === 'save_alerts') {
        $existing = $pdo->query(
            "SELECT id FROM alert_settings ORDER BY id ASC LIMIT 1"
        )->fetch();

        $params = [
            ':y1'    => max(0, (int)($_POST['doc_expiry_yellow_days']   ?? 30)),
            ':r1'    => (int)($_POST['doc_expiry_red_days']             ?? 0),
            ':y2'    => max(0, (int)($_POST['sign_on_yellow_days']      ?? 150)),
            ':r2'    => max(0, (int)($_POST['sign_on_red_days']         ?? 180)),
            ':vw'    => max(0, (int)($_POST['vessel_doc_warning_days']  ?? 30)),
            ':dash'  => $cb('dashboard_alerts_enabled'),
            ':email' => $cb('email_notifications_enabled'),
            ':sign'  => $cb('sign_on_alerts_enabled'),
        ];

        if ($existing) {
            $params[':i'] = (int)$existing['id'];
            $stmt = $pdo->prepare(
                "UPDATE alert_settings SET
                    doc_expiry_yellow_days = :y1, doc_expiry_red_days = :r1,
                    sign_on_yellow_days = :y2, sign_on_red_days = :r2,
                    vessel_doc_warning_days = :vw,
                    dashboard_alerts_enabled    = :dash,
                    email_notifications_enabled = :email,
                    sign_on_alerts_enabled      = :sign
                 WHERE id = :i"
            );
            $stmt->execute($params);
            $rowId = (int)$existing['id'];
        } else {
            $stmt = $pdo->prepare(
                "INSERT INTO alert_settings
                    (doc_expiry_yellow_days, doc_expiry_red_days,
                     sign_on_yellow_days, sign_on_red_days,
                     vessel_doc_warning_days,
                     dashboard_alerts_enabled, email_notifications_enabled,
                     sign_on_alerts_enabled)
                 VALUES (:y1, :r1, :y2, :r2, :vw, :dash, :email, :sign)"
            );
            $stmt->execute($params);
            $rowId = (int)$pdo->lastInsertId();
        }

        logActivity(
            $pdo, $user['id'], 'update', 'settings', $rowId,
            'Alert settings updated'
        );
        flash('success', 'Alert settings saved.');
        header('Location: ' . url('settings.php#alerts'));
        exit;
    }
}

// -------------------------------------------------------------
// GET — load current settings
// -------------------------------------------------------------
$sys = $pdo->query("SELECT * FROM system_settings ORDER BY id ASC LIMIT 1")->fetch() ?: [];
$alr = $pdo->query("SELECT * FROM alert_settings  ORDER BY id ASC LIMIT 1")->fetch() ?: [];

// Provide sensible defaults if either table is empty (first install).
$sys += [
    'company_name'                => 'SEA VOYAGE SHIP MANAGEMENT LLP',
    'short_name'                  => 'SVSML',
    'rpsl_number'                 => '',
    'contact_email'               => '',
    'phone'                       => '',
    'website'                     => '',
    'address'                     => '',
    'contract_footer_text'        => '',
    'crew_self_login_enabled'     => 1,
    'dpdp_consent_required'       => 1,
    'auto_joiner_detection'       => 1,
    'staff_activity_logging'      => 1,
    'contract_email_auto_send'    => 0,
    'smtp_host'                   => '',
    'smtp_port'                   => '',
    'smtp_email'                  => '',
    'smtp_password'               => '',
];
$alr += [
    'doc_expiry_yellow_days'      => 30,
    'doc_expiry_red_days'         => 0,
    'sign_on_yellow_days'         => 150,
    'sign_on_red_days'            => 180,
    'vessel_doc_warning_days'     => 30,
    'dashboard_alerts_enabled'    => 1,
    'email_notifications_enabled' => 0,
    'sign_on_alerts_enabled'      => 1,
];

$pageTitle = 'System settings';
include __DIR__ . '/includes/header.php';
?>

<form method="post" novalidate id="system">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="save_system">

    <div class="card">
        <h2 class="card-title">System Settings</h2>
        <p class="help-text">
            These values are used across the ERP for branding, contracts and
            crew-facing pages. Only Admin and Sub-admin roles can edit this page.
        </p>

        <div class="section-title">Company Information</div>
        <div class="form-grid">
            <div class="form-row">
                <label for="company_name">Company name *</label>
                <input type="text" id="company_name" name="company_name"
                       value="<?= h($sys['company_name']) ?>" maxlength="200" required>
            </div>
            <div class="form-row">
                <label for="short_name">Short name *</label>
                <input type="text" id="short_name" name="short_name"
                       value="<?= h($sys['short_name']) ?>" maxlength="20" required>
            </div>
            <div class="form-row">
                <label for="rpsl_number">RPSL number</label>
                <input type="text" id="rpsl_number" name="rpsl_number"
                       value="<?= h($sys['rpsl_number']) ?>" maxlength="50">
            </div>
            <div class="form-row">
                <label for="contact_email">Contact email</label>
                <input type="email" id="contact_email" name="contact_email"
                       value="<?= h($sys['contact_email']) ?>" maxlength="100">
            </div>
            <div class="form-row">
                <label for="phone">Phone</label>
                <input type="text" id="phone" name="phone"
                       value="<?= h($sys['phone']) ?>" maxlength="30">
            </div>
            <div class="form-row">
                <label for="website">Website</label>
                <input type="text" id="website" name="website"
                       value="<?= h($sys['website']) ?>" maxlength="100"
                       placeholder="https://...">
            </div>
            <div class="form-row full-row">
                <label for="address">Address</label>
                <textarea id="address" name="address" rows="2"><?= h($sys['address']) ?></textarea>
            </div>
            <div class="form-row full-row">
                <label for="contract_footer_text">Contract footer text</label>
                <textarea id="contract_footer_text" name="contract_footer_text"
                          rows="2"><?= h($sys['contract_footer_text']) ?></textarea>
                <p class="help-text">
                    Appears at the bottom of every generated SVSML contract PDF (Module 8).
                </p>
            </div>
        </div>

        <div class="section-title">Feature Toggles</div>
        <div class="switch-row">
            <input type="checkbox" id="crew_self_login_enabled" name="crew_self_login_enabled"
                   value="1" <?= $sys['crew_self_login_enabled'] ? 'checked' : '' ?>>
            <label for="crew_self_login_enabled">Crew self-login enabled</label>
            <span class="help-text">Crew can sign in with their passport number (Module 16).</span>
        </div>
        <div class="switch-row">
            <input type="checkbox" id="dpdp_consent_required" name="dpdp_consent_required"
                   value="1" <?= $sys['dpdp_consent_required'] ? 'checked' : '' ?>>
            <label for="dpdp_consent_required">DPDP consent required on contracts</label>
            <span class="help-text">Crew must accept the data-protection consent before contract is generated.</span>
        </div>
        <div class="switch-row">
            <input type="checkbox" id="auto_joiner_detection" name="auto_joiner_detection"
                   value="1" <?= $sys['auto_joiner_detection'] ? 'checked' : '' ?>>
            <label for="auto_joiner_detection">Auto joiner-type detection</label>
            <span class="help-text">Mark crew as new_joiner or rejoiner based on prior sign-ons.</span>
        </div>
        <div class="switch-row">
            <input type="checkbox" id="staff_activity_logging" name="staff_activity_logging"
                   value="1" <?= $sys['staff_activity_logging'] ? 'checked' : '' ?>>
            <label for="staff_activity_logging">Staff activity logging</label>
            <span class="help-text">Write to staff_activity on every DB write.</span>
        </div>
        <div class="switch-row">
            <input type="checkbox" id="contract_email_auto_send" name="contract_email_auto_send"
                   value="1" <?= $sys['contract_email_auto_send'] ? 'checked' : '' ?>>
            <label for="contract_email_auto_send">Auto-email contracts to crew</label>
            <span class="help-text">Requires SMTP credentials below.</span>
        </div>

        <div class="section-title">SMTP (Outgoing Email)</div>
        <div class="form-grid">
            <div class="form-row">
                <label for="smtp_host">SMTP host</label>
                <input type="text" id="smtp_host" name="smtp_host"
                       value="<?= h($sys['smtp_host']) ?>" maxlength="100"
                       placeholder="smtp.gmail.com">
            </div>
            <div class="form-row">
                <label for="smtp_port">SMTP port</label>
                <input type="number" id="smtp_port" name="smtp_port"
                       value="<?= h($sys['smtp_port']) ?>" min="1" max="65535"
                       placeholder="587">
            </div>
            <div class="form-row">
                <label for="smtp_email">SMTP username / email</label>
                <input type="email" id="smtp_email" name="smtp_email"
                       value="<?= h($sys['smtp_email']) ?>" maxlength="100"
                       autocomplete="off">
            </div>
            <div class="form-row">
                <label for="smtp_password">SMTP password</label>
                <input type="password" id="smtp_password" name="smtp_password"
                       autocomplete="new-password"
                       placeholder="<?= !empty($sys['smtp_password']) ? '(unchanged — leave blank to keep)' : '' ?>">
                <p class="help-text">
                    Leave blank to keep the existing password.
                </p>
            </div>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn">Save system settings</button>
            <button type="reset" class="btn btn-secondary">Reset</button>
        </div>
    </div>
</form>

<form method="post" novalidate id="alerts">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="save_alerts">

    <div class="card">
        <h2 class="card-title">Alert Settings</h2>
        <p class="help-text">
            Thresholds drive the colour-coded badges throughout the ERP and the
            data shown on the dashboard / Expiry Alerts page.
            See the date-status convention: more than yellow days remaining = green,
            from 0 to yellow = yellow, less than red = red.
        </p>

        <div class="section-title">Document Expiry Thresholds</div>
        <div class="form-grid">
            <div class="form-row">
                <label for="doc_expiry_yellow_days">Yellow when expiring within (days)</label>
                <input type="number" id="doc_expiry_yellow_days"
                       name="doc_expiry_yellow_days"
                       value="<?= (int)$alr['doc_expiry_yellow_days'] ?>" min="0" max="365">
            </div>
            <div class="form-row">
                <label for="doc_expiry_red_days">Red when expired by (days)</label>
                <input type="number" id="doc_expiry_red_days"
                       name="doc_expiry_red_days"
                       value="<?= (int)$alr['doc_expiry_red_days'] ?>" min="0" max="365">
            </div>
            <div class="form-row">
                <label for="vessel_doc_warning_days">Vessel doc warning window (days)</label>
                <input type="number" id="vessel_doc_warning_days"
                       name="vessel_doc_warning_days"
                       value="<?= (int)$alr['vessel_doc_warning_days'] ?>" min="0" max="365">
            </div>
        </div>

        <div class="section-title">Sign-on Duration Thresholds</div>
        <div class="form-grid">
            <div class="form-row">
                <label for="sign_on_yellow_days">Yellow when on board (days)</label>
                <input type="number" id="sign_on_yellow_days"
                       name="sign_on_yellow_days"
                       value="<?= (int)$alr['sign_on_yellow_days'] ?>" min="0" max="730">
            </div>
            <div class="form-row">
                <label for="sign_on_red_days">Red when on board (days)</label>
                <input type="number" id="sign_on_red_days"
                       name="sign_on_red_days"
                       value="<?= (int)$alr['sign_on_red_days'] ?>" min="0" max="730">
            </div>
        </div>

        <div class="section-title">Notification Channels</div>
        <div class="switch-row">
            <input type="checkbox" id="dashboard_alerts_enabled" name="dashboard_alerts_enabled"
                   value="1" <?= $alr['dashboard_alerts_enabled'] ? 'checked' : '' ?>>
            <label for="dashboard_alerts_enabled">Show alerts on the dashboard</label>
        </div>
        <div class="switch-row">
            <input type="checkbox" id="email_notifications_enabled" name="email_notifications_enabled"
                   value="1" <?= $alr['email_notifications_enabled'] ? 'checked' : '' ?>>
            <label for="email_notifications_enabled">Email notifications for alerts</label>
            <span class="help-text">Requires SMTP configured above.</span>
        </div>
        <div class="switch-row">
            <input type="checkbox" id="sign_on_alerts_enabled" name="sign_on_alerts_enabled"
                   value="1" <?= $alr['sign_on_alerts_enabled'] ? 'checked' : '' ?>>
            <label for="sign_on_alerts_enabled">Surface long sign-on durations as alerts</label>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn">Save alert settings</button>
            <button type="reset" class="btn btn-secondary">Reset</button>
        </div>
    </div>
</form>

<?php include __DIR__ . '/includes/footer.php'; ?>
