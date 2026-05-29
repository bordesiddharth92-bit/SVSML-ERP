<?php
/**
 * SVSML-ERP — Dashboard
 *
 * Module 14.
 *
 * Role-based:
 *   - crew                     → redirected to /crew-portal.php (Module 16)
 *   - admin / sub_admin / staff→ KPI grid + recent activity + key alerts
 *
 * KPIs are intentionally compact: aggregate counts and totals only.
 * For detailed lists the user clicks through to the respective module.
 */

require __DIR__ . '/config/app.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/helpers.php';

requireLogin();

$user = currentUser();

// Crew users have their own self-service portal in Module 16.
if ($user['role'] === 'crew') {
    header('Location: ' . url('crew-portal.php'));
    exit;
}

$alerts = getAlertSettings($pdo);
$sys    = getSystemSettings($pdo);

$yellowDays = (int)($alerts['doc_expiry_yellow_days'] ?? EXPIRY_YELLOW_DAYS);

/**
 * Convenience: count how many rows of $table.$dateCol are
 *   - expired (date < today)        → 'red'
 *   - expiring in next $yellowDays  → 'yellow'
 */
function expiryBuckets(PDO $pdo, string $table, string $dateCol, int $yellowDays): array
{
    $sql = "SELECT
                SUM(CASE WHEN `{$dateCol}` IS NOT NULL AND `{$dateCol}` < CURDATE() THEN 1 ELSE 0 END) AS expired,
                SUM(CASE WHEN `{$dateCol}` IS NOT NULL
                          AND `{$dateCol}` >= CURDATE()
                          AND `{$dateCol}` <= DATE_ADD(CURDATE(), INTERVAL :d DAY) THEN 1 ELSE 0 END) AS expiring,
                SUM(CASE WHEN `{$dateCol}` IS NULL THEN 1 ELSE 0 END) AS missing
              FROM `{$table}`";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':d' => $yellowDays]);
    $r = $stmt->fetch();
    return [
        'expired'  => (int)($r['expired']  ?? 0),
        'expiring' => (int)($r['expiring'] ?? 0),
        'missing'  => (int)($r['missing']  ?? 0),
    ];
}

// -------------------------------------------------------------
// Summary numbers
// -------------------------------------------------------------
$totalCrew    = (int)$pdo->query("SELECT COUNT(*) FROM crew")->fetchColumn();
$totalVessels = (int)$pdo->query("SELECT COUNT(*) FROM vessels")->fetchColumn();
$onboardNow   = (int)$pdo->query("SELECT COUNT(DISTINCT crew_id) FROM sign_on_off WHERE sign_off_date IS NULL")->fetchColumn();

$docs    = expiryBuckets($pdo, 'crew_documents',   'expiry_date', $yellowDays);
$medical = expiryBuckets($pdo, 'crew_medical',     'expiry_date', $yellowDays);
$basic   = expiryBuckets($pdo, 'basic_courses',    'expiry_date', $yellowDays);
$advCrs  = expiryBuckets($pdo, 'advanced_courses', 'expiry_date', $yellowDays);

$pendingTravel = (int)$pdo->query("SELECT COUNT(*) FROM travel_details WHERE final_status = 'pending'")->fetchColumn();
$openTravel    = (int)$pdo->query("SELECT COUNT(*) FROM travel_details WHERE is_done = 0")->fetchColumn();

$pendingClientContract = (int)$pdo->query(
    "SELECT COUNT(*) FROM contracts WHERE client_contract_status = 'pending'"
)->fetchColumn();
$totalContracts = (int)$pdo->query("SELECT COUNT(*) FROM contracts")->fetchColumn();

$svsmlDues = (float)$pdo->query(
    "SELECT COALESCE(SUM(pending_amount), 0) FROM svsml_approvals WHERE pending_amount > 0"
)->fetchColumn();
$svsmlPaid = (float)$pdo->query(
    "SELECT COALESCE(SUM(paid_amount), 0) FROM svsml_approvals"
)->fetchColumn();

$clientApprovalsTotal = (float)$pdo->query(
    "SELECT COALESCE(SUM(cost), 0) FROM client_approvals"
)->fetchColumn();

// Vessel doc expiries — manning agency cares about these too.
$vesselExpiries = [
    'pni' => expiryBuckets($pdo, 'vessels', 'pni_date',                $yellowDays),
    'mlc' => expiryBuckets($pdo, 'vessels', 'mlc_date',                $yellowDays),
    'fs'  => expiryBuckets($pdo, 'vessels', 'financial_security_date', $yellowDays),
];

// Sign-on overstay (only sub_admin / admin really care, but useful here).
$signOnYellow = (int)($alerts['sign_on_yellow_days'] ?? 150);
$signOnRed    = (int)($alerts['sign_on_red_days']    ?? 180);

$st = $pdo->prepare(
    "SELECT COUNT(*) FROM sign_on_off
      WHERE sign_off_date IS NULL
        AND sign_on_date <= DATE_SUB(CURDATE(), INTERVAL :d DAY)
        AND sign_on_date >  DATE_SUB(CURDATE(), INTERVAL :r DAY)"
);
$st->execute([':d' => $signOnYellow, ':r' => $signOnRed]);
$overstayYellow = (int)$st->fetchColumn();

$st = $pdo->prepare(
    "SELECT COUNT(*) FROM sign_on_off
      WHERE sign_off_date IS NULL
        AND sign_on_date <= DATE_SUB(CURDATE(), INTERVAL :r DAY)"
);
$st->execute([':r' => $signOnRed]);
$overstayRed = (int)$st->fetchColumn();

// Recent activity (last 10).
$recent = $pdo->query(
    "SELECT sa.id, sa.action_type, sa.module, sa.record_id, sa.description, sa.created_at,
            u.full_name AS user_name, u.role AS user_role
       FROM staff_activity sa
       LEFT JOIN users u ON u.id = sa.user_id
      ORDER BY sa.created_at DESC, sa.id DESC
      LIMIT 10"
)->fetchAll();

$pageTitle = 'Dashboard';
include __DIR__ . '/includes/header.php';

/** Render a small KPI tile. */
function kpiTile(string $label, $value, string $color = '', ?string $href = null): string
{
    $valColor = $color ? "color: var(--{$color});" : '';
    $body = '<div class="kpi-label">' . h($label) . '</div>'
          . '<div class="kpi-value" style="' . $valColor . '">' . h((string)$value) . '</div>';
    if ($href) {
        return '<a class="kpi" href="' . $href . '" style="text-decoration:none; color:inherit;">' . $body . '</a>';
    }
    return '<div class="kpi">' . $body . '</div>';
}

/** Format a money figure for KPI display. */
function moneyShort(float $n): string
{
    return number_format($n, 2);
}
?>

<div class="card">
    <h2 class="card-title">Welcome, <?= h($user['full_name']) ?></h2>
    <p>
        Signed in as <span class="badge badge-<?= h($user['role']) ?>"><?= h(strtoupper($user['role'])) ?></span>
        on <strong><?= h($sys['company_name'] ?? APP_NAME) ?></strong>.
        <span class="help-text" style="margin-left:8px;">Yellow threshold: <?= (int)$yellowDays ?> days.</span>
    </p>
</div>

<div class="card">
    <h3 class="card-title">Crew</h3>
    <div class="kpi-grid">
        <?= kpiTile('Total crew',          $totalCrew,    '',     asset('crew.php')) ?>
        <?= kpiTile('On board now',        $onboardNow,   'green',asset('signon.php')) ?>
        <?= kpiTile('Onboard 150–180d',    $overstayYellow, $overstayYellow ? 'yellow' : '', asset('signon.php')) ?>
        <?= kpiTile('Onboard >180d',       $overstayRed,    $overstayRed    ? 'red'    : '', asset('signon.php')) ?>
        <?= kpiTile('Vessels',             $totalVessels) ?>
    </div>
</div>

<div class="card">
    <h3 class="card-title">Document & training expiries</h3>
    <p class="help-text">Click any tile to drill into the Expiry Alerts page.</p>
    <div class="kpi-grid">
        <?= kpiTile('Documents expired',  $docs['expired'],     $docs['expired']  ? 'red'    : '', asset('alerts.php?bucket=expired&type=documents')) ?>
        <?= kpiTile('Documents expiring', $docs['expiring'],    $docs['expiring'] ? 'yellow' : '', asset('alerts.php?bucket=expiring&type=documents')) ?>
        <?= kpiTile('Medical expired',    $medical['expired'],  $medical['expired']  ? 'red'    : '', asset('alerts.php?bucket=expired&type=medical')) ?>
        <?= kpiTile('Medical expiring',   $medical['expiring'], $medical['expiring'] ? 'yellow' : '', asset('alerts.php?bucket=expiring&type=medical')) ?>
        <?= kpiTile('Basic courses red',  $basic['expired'],    $basic['expired']  ? 'red'    : '', asset('alerts.php?bucket=expired&type=basic')) ?>
        <?= kpiTile('Basic courses yellow', $basic['expiring'], $basic['expiring'] ? 'yellow' : '', asset('alerts.php?bucket=expiring&type=basic')) ?>
        <?= kpiTile('Adv courses red',    $advCrs['expired'],   $advCrs['expired']  ? 'red'    : '', asset('alerts.php?bucket=expired&type=advanced')) ?>
        <?= kpiTile('Adv courses yellow', $advCrs['expiring'],  $advCrs['expiring'] ? 'yellow' : '', asset('alerts.php?bucket=expiring&type=advanced')) ?>
    </div>
</div>

<div class="card">
    <h3 class="card-title">Vessel certificates</h3>
    <div class="kpi-grid">
        <?= kpiTile('PnI expired',  $vesselExpiries['pni']['expired'],  $vesselExpiries['pni']['expired']  ? 'red'    : '', asset('alerts.php?bucket=expired&type=vessel_pni')) ?>
        <?= kpiTile('PnI expiring', $vesselExpiries['pni']['expiring'], $vesselExpiries['pni']['expiring'] ? 'yellow' : '', asset('alerts.php?bucket=expiring&type=vessel_pni')) ?>
        <?= kpiTile('MLC expired',  $vesselExpiries['mlc']['expired'],  $vesselExpiries['mlc']['expired']  ? 'red'    : '', asset('alerts.php?bucket=expired&type=vessel_mlc')) ?>
        <?= kpiTile('MLC expiring', $vesselExpiries['mlc']['expiring'], $vesselExpiries['mlc']['expiring'] ? 'yellow' : '', asset('alerts.php?bucket=expiring&type=vessel_mlc')) ?>
        <?= kpiTile('FS expired',   $vesselExpiries['fs']['expired'],   $vesselExpiries['fs']['expired']   ? 'red'    : '', asset('alerts.php?bucket=expired&type=vessel_fs')) ?>
        <?= kpiTile('FS expiring',  $vesselExpiries['fs']['expiring'],  $vesselExpiries['fs']['expiring']  ? 'yellow' : '', asset('alerts.php?bucket=expiring&type=vessel_fs')) ?>
    </div>
</div>

<div class="card">
    <h3 class="card-title">Operations & approvals</h3>
    <div class="kpi-grid">
        <?= kpiTile('Travel pending',     $pendingTravel,        $pendingTravel ? 'yellow' : '', asset('travel.php?status=pending')) ?>
        <?= kpiTile('Travel open (todo)', $openTravel,           $openTravel    ? 'yellow' : '', asset('travel.php?only_open=1')) ?>
        <?= kpiTile('Total contracts',    $totalContracts, '', asset('contracts.php')) ?>
        <?= kpiTile('Client contract pending', $pendingClientContract,
                    $pendingClientContract ? 'yellow' : '', asset('contracts.php?status=pending')) ?>
        <?= kpiTile('Client approvals total', moneyShort($clientApprovalsTotal), '', asset('client-approvals.php')) ?>
        <?= kpiTile('SVSML dues outstanding', moneyShort($svsmlDues), $svsmlDues > 0 ? 'yellow' : 'green', asset('svsml-approvals.php?only_pending=1')) ?>
        <?= kpiTile('SVSML paid (lifetime)', moneyShort($svsmlPaid), '', asset('svsml-approvals.php')) ?>
    </div>
</div>

<?php if (in_array($user['role'], ['admin', 'sub_admin'], true)): ?>
<div class="card">
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
        <h3 class="card-title" style="margin:0">Recent activity</h3>
        <a class="btn btn-secondary btn-sm" href="<?= asset('activity-log.php') ?>">Full log →</a>
    </div>
    <?php if (empty($recent)): ?>
        <p class="help-text">No staff activity recorded yet.</p>
    <?php else: ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width:18%">When</th>
                    <th style="width:18%">User</th>
                    <th style="width:10%">Action</th>
                    <th style="width:14%">Module</th>
                    <th>Description</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recent as $r): ?>
                    <tr>
                        <td><?= h(date('d/m/Y H:i', strtotime($r['created_at']))) ?></td>
                        <td><?= h($r['user_name'] ?? '—') ?></td>
                        <td><?= h(ucfirst($r['action_type'])) ?></td>
                        <td><code><?= h($r['module']) ?></code></td>
                        <td><?= h($r['description'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
