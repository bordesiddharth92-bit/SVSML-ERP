<?php
/**
 * SVSML-ERP — Crew Portal: Overview
 *
 * Module 16 (landing page).
 *
 * Read-only dashboard for the signed-in crew member. Shows:
 *   - profile hero (photo / initials, name, rank, vessel, company)
 *   - KPI tiles for things they should pay attention to
 *     (expiring documents, expired documents, upcoming travel, pending approvals)
 *   - quick "next action" hints
 *
 * Other portal pages (personal / documents / medical / courses / sailing /
 * contracts / travel / approvals / password) are linked via the portal
 * tabs include and the sidebar.
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

$crew = fetchCrewWithJoins($pdo, $crewId);
if (!$crew) {
    flash('error', 'Your crew record was not found.');
    logoutCurrentUser();
    header('Location: ' . url('crew-login.php'));
    exit;
}

$alerts     = getAlertSettings($pdo);
$yellowDays = (int)($alerts['doc_expiry_yellow_days'] ?? EXPIRY_YELLOW_DAYS);
$latestContract = fetchLatestContractForCrew($pdo, $crewId);

/**
 * Bucket counts (red / yellow) for one source-table.expiry_date column,
 * scoped to this one crew member. Yellow uses the system-wide threshold.
 */
function crewExpiryBuckets(PDO $pdo, string $table, string $dateCol, int $crewId, int $yellowDays): array
{
    $sql = "SELECT
                SUM(CASE WHEN `{$dateCol}` IS NOT NULL AND `{$dateCol}` < CURDATE() THEN 1 ELSE 0 END) AS expired,
                SUM(CASE WHEN `{$dateCol}` IS NOT NULL
                          AND `{$dateCol}` >= CURDATE()
                          AND `{$dateCol}` <= DATE_ADD(CURDATE(), INTERVAL :d DAY) THEN 1 ELSE 0 END) AS expiring
              FROM `{$table}` WHERE crew_id = :c";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':d' => $yellowDays, ':c' => $crewId]);
    $r = $stmt->fetch();
    return [
        'expired'  => (int)($r['expired']  ?? 0),
        'expiring' => (int)($r['expiring'] ?? 0),
    ];
}

$docs    = crewExpiryBuckets($pdo, 'crew_documents',   'expiry_date', $crewId, $yellowDays);
$medical = crewExpiryBuckets($pdo, 'crew_medical',     'expiry_date', $crewId, $yellowDays);
$basic   = crewExpiryBuckets($pdo, 'basic_courses',    'expiry_date', $crewId, $yellowDays);
$advCrs  = crewExpiryBuckets($pdo, 'advanced_courses', 'expiry_date', $crewId, $yellowDays);

$st = $pdo->prepare("SELECT COUNT(*) FROM crew_documents WHERE crew_id = :c"); $st->execute([':c' => $crewId]);
$totalDocs = (int)$st->fetchColumn();

$st = $pdo->prepare("SELECT COUNT(*) FROM crew_medical WHERE crew_id = :c"); $st->execute([':c' => $crewId]);
$totalMed = (int)$st->fetchColumn();

$st = $pdo->prepare("SELECT COUNT(*) FROM basic_courses WHERE crew_id = :c"); $st->execute([':c' => $crewId]);
$totalBasic = (int)$st->fetchColumn();

$st = $pdo->prepare("SELECT COUNT(*) FROM advanced_courses WHERE crew_id = :c"); $st->execute([':c' => $crewId]);
$totalAdv = (int)$st->fetchColumn();

// Travel: open vs done.
$st = $pdo->prepare("SELECT COUNT(*) FROM travel_details WHERE crew_id = :c AND is_done = 0");
$st->execute([':c' => $crewId]);
$travelOpen = (int)$st->fetchColumn();
$st = $pdo->prepare("SELECT COUNT(*) FROM travel_details WHERE crew_id = :c");
$st->execute([':c' => $crewId]);
$travelTotal = (int)$st->fetchColumn();

// Sign-on currently?
$st = $pdo->prepare(
    "SELECT sign_on_date FROM sign_on_off
      WHERE crew_id = :c AND sign_off_date IS NULL ORDER BY id DESC LIMIT 1"
);
$st->execute([':c' => $crewId]);
$onboardSince = $st->fetchColumn();

// Contracts.
$st = $pdo->prepare("SELECT COUNT(*) FROM contracts WHERE crew_id = :c"); $st->execute([':c' => $crewId]);
$totalContracts = (int)$st->fetchColumn();
$st = $pdo->prepare("SELECT COUNT(*) FROM contracts WHERE crew_id = :c AND client_contract_status = 'pending'");
$st->execute([':c' => $crewId]);
$pendingClientContracts = (int)$st->fetchColumn();

// Approvals — they should know what they owe / what's been approved.
$st = $pdo->prepare("SELECT COALESCE(SUM(cost),0) FROM client_approvals WHERE crew_id = :c");
$st->execute([':c' => $crewId]);
$clientApprTotal = (float)$st->fetchColumn();

$st = $pdo->prepare(
    "SELECT COALESCE(SUM(total_amount),0)   AS total,
            COALESCE(SUM(paid_amount),0)    AS paid,
            COALESCE(SUM(pending_amount),0) AS pending
       FROM svsml_approvals WHERE crew_id = :c"
);
$st->execute([':c' => $crewId]);
$svsmlSummary = $st->fetch() ?: ['total' => 0, 'paid' => 0, 'pending' => 0];

$pageTitle  = 'My Profile — Overview';
$currentTab = 'overview';
include __DIR__ . '/includes/header.php';

/** Local copy of the dashboard's KPI tile renderer — keeps the portal
 *  self-contained without depending on staff-only code paths. */
function portalKpi(string $label, $value, string $colour = '', ?string $href = null): string
{
    $valColor = $colour ? "color: var(--{$colour});" : '';
    $body = '<div class="kpi-label">' . h($label) . '</div>'
          . '<div class="kpi-value" style="' . $valColor . '">' . h((string)$value) . '</div>';
    if ($href) {
        return '<a class="kpi" href="' . $href . '" style="text-decoration:none; color:inherit;">' . $body . '</a>';
    }
    return '<div class="kpi">' . $body . '</div>';
}
?>

<?php
// -------------------------------------------------------------
// Profile hero (rendered as a partial pattern — repeated on each portal
// page for continuity). Kept inline here; the other portal pages
// re-render the same block.
// -------------------------------------------------------------
$photoUrl = crewPhotoUrl($latestContract);
?>
<div class="portal-hero">
    <div class="portal-avatar">
        <?php if ($photoUrl): ?>
            <img src="<?= h($photoUrl) ?>" alt="Profile photo">
        <?php else: ?>
            <?= h(crewInitials($crew['full_name'])) ?>
        <?php endif; ?>
    </div>
    <div class="portal-hero-meta">
        <h2><?= h($crew['full_name']) ?></h2>
        <div class="meta-line">
            <?= h($crew['rank_name'] ?? 'Rank not set') ?>
            <?php if (!empty($crew['vessel_name'])):  ?> &middot; <?= h($crew['vessel_name']) ?>  <?php endif; ?>
            <?php if (!empty($crew['company_name'])): ?> &middot; <?= h($crew['company_name']) ?> <?php endif; ?>
        </div>
        <div class="meta-line">
            Passport: <strong><?= h($crew['passport_number'] ?? '—') ?></strong>
            <?php if (!empty($crew['indos_number'])): ?>
                &middot; INDOS: <strong><?= h($crew['indos_number']) ?></strong>
            <?php endif; ?>
        </div>
    </div>
    <div class="portal-hero-actions">
        <?php if ($onboardSince): ?>
            <span class="badge">ON BOARD since <?= h($onboardSince) ?></span>
        <?php else: ?>
            <span class="badge">Ashore</span>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/includes/crew-portal-tabs.php'; ?>

<div class="card">
    <h3 class="card-title">Things to keep an eye on</h3>
    <p class="help-text">Yellow tiles expire within <?= (int)$yellowDays ?> days. Red tiles have already expired — please contact SVSML to renew.</p>

    <div class="kpi-grid">
        <?= portalKpi('Documents — expired',  $docs['expired'],  $docs['expired']  ? 'red'    : '', asset('crew-portal-documents.php')) ?>
        <?= portalKpi('Documents — expiring', $docs['expiring'], $docs['expiring'] ? 'yellow' : '', asset('crew-portal-documents.php')) ?>
        <?= portalKpi('Medical — expired',    $medical['expired'],  $medical['expired']  ? 'red'    : '', asset('crew-portal-medical.php')) ?>
        <?= portalKpi('Medical — expiring',   $medical['expiring'], $medical['expiring'] ? 'yellow' : '', asset('crew-portal-medical.php')) ?>
        <?= portalKpi('Basic course — red',   $basic['expired'],  $basic['expired']  ? 'red'    : '', asset('crew-portal-courses.php')) ?>
        <?= portalKpi('Basic course — yellow',$basic['expiring'], $basic['expiring'] ? 'yellow' : '', asset('crew-portal-courses.php')) ?>
        <?= portalKpi('Adv course — red',     $advCrs['expired'], $advCrs['expired']  ? 'red'    : '', asset('crew-portal-courses.php')) ?>
        <?= portalKpi('Adv course — yellow',  $advCrs['expiring'],$advCrs['expiring'] ? 'yellow' : '', asset('crew-portal-courses.php')) ?>
    </div>
</div>

<div class="card">
    <h3 class="card-title">Records on file</h3>
    <div class="kpi-grid">
        <?= portalKpi('Documents',       $totalDocs,   '', asset('crew-portal-documents.php')) ?>
        <?= portalKpi('Medical',         $totalMed,    '', asset('crew-portal-medical.php')) ?>
        <?= portalKpi('Basic courses',   $totalBasic,  '', asset('crew-portal-courses.php')) ?>
        <?= portalKpi('Advanced courses',$totalAdv,    '', asset('crew-portal-courses.php')) ?>
        <?= portalKpi('Travel rows',     $travelTotal, '', asset('crew-portal-travel.php')) ?>
        <?= portalKpi('Travel — open',   $travelOpen,  $travelOpen ? 'yellow' : '', asset('crew-portal-travel.php')) ?>
        <?= portalKpi('Contracts',       $totalContracts, '', asset('crew-portal-contracts.php')) ?>
        <?= portalKpi('Client contract pending', $pendingClientContracts,
                      $pendingClientContracts ? 'yellow' : '', asset('crew-portal-contracts.php')) ?>
    </div>
</div>

<div class="card">
    <h3 class="card-title">Approvals & dues</h3>
    <div class="kpi-grid">
        <?= portalKpi('Client approvals (total)',  number_format($clientApprTotal, 2), '', asset('crew-portal-approvals.php')) ?>
        <?= portalKpi('SVSML — total agreed',  number_format((float)$svsmlSummary['total'], 2),   '', asset('crew-portal-approvals.php')) ?>
        <?= portalKpi('SVSML — paid',          number_format((float)$svsmlSummary['paid'], 2),    'green', asset('crew-portal-approvals.php')) ?>
        <?= portalKpi('SVSML — pending',       number_format((float)$svsmlSummary['pending'], 2),
                      ((float)$svsmlSummary['pending']) > 0 ? 'yellow' : '', asset('crew-portal-approvals.php')) ?>
    </div>
</div>

<div class="card">
    <h3 class="card-title">Need to update something?</h3>
    <p class="help-text">
        This portal is read-only by design. To update any of your records,
        please contact SVSML — your manning agent will make the change for you.
        You can change your portal password yourself from the
        <a href="<?= asset('crew-portal-password.php') ?>">Password</a> tab.
    </p>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
