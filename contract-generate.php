<?php
/**
 * SVSML-ERP — Contract PDF generator
 *
 * Module 8.
 *
 * POST endpoint: receives a contract_id + crew_id, renders the SVSML
 * contract template via dompdf, saves the PDF under
 *   uploads/contracts/{crew_id}/svsml_{crew_id}_{timestamp}.pdf
 * and stamps the contract row with svsml_contract_path /
 * svsml_contract_generated_at / svsml_contract_generated_by.
 *
 * dompdf is a Composer dependency declared in composer.json. Because
 * GoDaddy shared hosting can't always run `composer install` from cPanel,
 * this file falls back gracefully when vendor/autoload.php is missing —
 * it surfaces a clear error so the operator knows what to do.
 *
 * Permissions: admin / sub_admin / staff (per matrix).
 */

require __DIR__ . '/config/app.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/helpers.php';

requireRole(['admin', 'sub_admin', 'staff']);

$user       = currentUser();
$crewId     = (int)($_POST['crew_id']     ?? $_GET['crew_id']     ?? 0);
$contractId = (int)($_POST['contract_id'] ?? $_GET['contract_id'] ?? 0);
$backUrl    = url('crew-contracts.php?id=' . $crewId);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    flash('error', 'PDF generation must be triggered via the form on the contracts page.');
    header('Location: ' . $backUrl);
    exit;
}

verifyCsrf();

if ($crewId <= 0 || $contractId <= 0) {
    flash('error', 'Missing crew or contract id.');
    header('Location: ' . $backUrl);
    exit;
}

// 1. Load the contract + crew + joined identity for placeholder substitution.
$row = $pdo->prepare(
    "SELECT c.*,
            cr.full_name        AS crew_name,
            cr.passport_number,
            cr.indos_number,
            cr.date_of_birth,
            r.rank_name,
            v.vessel_name,
            co.company_name,
            (SELECT cdc_number
               FROM sailing_history sh
              WHERE sh.crew_id = cr.id AND sh.cdc_number IS NOT NULL
              ORDER BY sh.id DESC LIMIT 1) AS cdc_number
       FROM contracts c
       JOIN crew      cr ON cr.id = c.crew_id
       LEFT JOIN ranks     r  ON r.id  = cr.rank_id
       LEFT JOIN vessels   v  ON v.id  = cr.vessel_id
       LEFT JOIN companies co ON co.id = cr.company_id
      WHERE c.id = :i AND c.crew_id = :c
      LIMIT 1"
);
$row->execute([':i' => $contractId, ':c' => $crewId]);
$row = $row->fetch();
if (!$row) {
    flash('error', 'Contract not found.');
    header('Location: ' . $backUrl);
    exit;
}

// 2. Load the template.
$tplPath = __DIR__ . '/templates/contract-svsml.html';
if (!is_file($tplPath)) {
    flash('error', 'Contract template missing: templates/contract-svsml.html');
    header('Location: ' . $backUrl);
    exit;
}
$template = file_get_contents($tplPath);

// 3. Build placeholder map.
$sys = getSystemSettings($pdo);
$vars = [
    'reference_number'           => $row['reference_number'],
    'contract_date'              => $row['contract_date'] ?? '',
    'crew_name'                  => $row['crew_name'],
    'passport_number'            => $row['passport_number'] ?? '',
    'cdc_number'                 => $row['cdc_number'] ?? '',
    'indos_number'               => $row['indos_number'] ?? '',
    'rank'                       => $row['rank_name'] ?? '',
    'vessel'                     => $row['vessel_name'] ?? '',
    'company'                    => $row['company_name'] ?? '',
    'contract_period'            => $row['contract_period'] ?? '',
    'salary'                     => $row['total_salary'] !== null ? number_format((float)$row['total_salary'], 2) : '',
    'commencement_date'          => $row['commencement_date'] ?? '',
    'place_of_birth'             => $row['place_of_birth'] ?? '',
    'home_town'                  => $row['home_town'] ?? '',
    'nearest_airport'            => $row['nearest_airport'] ?? '',
    'next_of_kin_name'           => $row['next_of_kin_name'] ?? '',
    'next_of_kin_relationship'   => $row['next_of_kin_relationship'] ?? '',
    'next_of_kin_contact'        => $row['next_of_kin_contact'] ?? '',
    'next_of_kin_email'          => $row['next_of_kin_email'] ?? '',
    'next_of_kin_address'        => $row['next_of_kin_address'] ?? '',
    'beneficiary_name'           => $row['beneficiary_name'] ?? '',
    'beneficiary_relationship'   => $row['beneficiary_relationship'] ?? '',
    'beneficiary_percentage'     => $row['beneficiary_percentage'] !== null ? rtrim(rtrim((string)$row['beneficiary_percentage'], '0'), '.') : '',
    'date_of_birth'              => $row['date_of_birth'] ?? '',
    'age'                        => ageFromDOB($row['date_of_birth'] ?? null),
    'company_short_name'         => $sys['short_name']        ?? 'SVSML',
    'company_full_name'          => $sys['company_name']      ?? 'SEA VOYAGE SHIP MANAGEMENT LLP',
    'rpsl_number'                => $sys['rpsl_number']       ?? '',
    'contract_footer_text'       => $sys['contract_footer_text'] ?? '',
];

$html = fillContractTemplate($template, $vars);

// 4. Make sure the output directory exists.
$relDir = 'contracts/' . $crewId;
$absDir = rtrim(UPLOAD_DIR, '/') . '/' . $relDir;
if (!is_dir($absDir) && !mkdir($absDir, 0755, true) && !is_dir($absDir)) {
    flash('error', 'Could not create output directory: ' . htmlspecialchars($absDir));
    header('Location: ' . $backUrl);
    exit;
}
$fileName = sprintf('svsml_%d_%d.pdf', $crewId, time());
$relPath  = $relDir . '/' . $fileName;
$absPath  = $absDir . '/' . $fileName;

// 5. Run dompdf if available; fall back to a clear error otherwise.
$autoload = __DIR__ . '/vendor/autoload.php';
if (!file_exists($autoload)) {
    flash('error',
        'dompdf is not installed. Run "composer install" from cPanel Terminal '
        . 'on the server, or upload a pre-built /vendor directory. '
        . 'See the README for setup details.'
    );
    header('Location: ' . $backUrl);
    exit;
}
require_once $autoload;

if (!class_exists('Dompdf\\Dompdf')) {
    flash('error', 'vendor/autoload.php loaded but Dompdf class not found. Did "composer install" run cleanly?');
    header('Location: ' . $backUrl);
    exit;
}

try {
    $opts = new Dompdf\Options();
    $opts->set('isRemoteEnabled', false);   // safer default
    $opts->set('isHtml5ParserEnabled', true);
    $opts->set('defaultFont', 'DejaVu Sans');

    $dompdf = new Dompdf\Dompdf($opts);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    $pdfData = $dompdf->output();
    if ($pdfData === '' || $pdfData === false) {
        throw new RuntimeException('dompdf produced empty output.');
    }
    if (file_put_contents($absPath, $pdfData) === false) {
        throw new RuntimeException('Could not write PDF to ' . $absPath);
    }
} catch (Throwable $e) {
    error_log('[SVSML-ERP] PDF generation failed: ' . $e->getMessage());
    flash('error', 'PDF generation failed: ' . $e->getMessage());
    header('Location: ' . $backUrl);
    exit;
}

// 6. Stamp the contract row.
$pdo->prepare(
    "UPDATE contracts
        SET svsml_contract_path         = :path,
            svsml_contract_generated_at = CURRENT_TIMESTAMP,
            svsml_contract_generated_by = :uid,
            updated_at                  = CURRENT_TIMESTAMP
      WHERE id = :i AND crew_id = :c"
)->execute([
    ':path' => $relPath,
    ':uid'  => $user['id'],
    ':i'    => $contractId,
    ':c'    => $crewId,
]);

logActivity(
    $pdo, $user['id'], 'generate', 'contracts', $contractId,
    "Generated SVSML PDF {$row['reference_number']} for crew {$crewId}"
);

flash('success', 'PDF generated: ' . $row['reference_number'] . '.pdf');
header('Location: ' . $backUrl);
exit;
