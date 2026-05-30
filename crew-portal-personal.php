<?php
/**
 * SVSML-ERP — Crew Portal: Personal details
 *
 * Module 16. Read-only.
 *
 * Pulls together everything the crew should be able to see about
 * themselves: identity, contact, sizes, and the next-of-kin /
 * beneficiary block (which lives on the latest contract, not crew).
 */

require __DIR__ . '/config/app.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/helpers.php';

requireOnboardedCrew($pdo);
$crewId = currentCrewId();
$crew   = $crewId ? fetchCrewWithJoins($pdo, $crewId) : null;
if (!$crew) { logoutCurrentUser(); header('Location: ' . url('crew-login.php')); exit; }

$latestContract = fetchLatestContractForCrew($pdo, $crewId);

$pageTitle  = 'My Profile — Personal';
$currentTab = 'personal';
include __DIR__ . '/includes/header.php';

/** Tiny helper: wraps a "—" placeholder in a muted span. */
function pv($v): string
{
    $v = trim((string)$v);
    return $v === '' ? '<span class="muted">—</span>' : h($v);
}
?>

<?php
$photoUrl = crewPhotoUrl($latestContract);
?>
<div class="portal-hero">
    <div class="portal-avatar">
        <?php if ($photoUrl): ?><img src="<?= h($photoUrl) ?>" alt="Profile photo">
        <?php else: ?><?= h(crewInitials($crew['full_name'])) ?><?php endif; ?>
    </div>
    <div class="portal-hero-meta">
        <h2><?= h($crew['full_name']) ?></h2>
        <div class="meta-line"><?= h($crew['rank_name'] ?? 'Rank not set') ?></div>
    </div>
</div>

<?php include __DIR__ . '/includes/crew-portal-tabs.php'; ?>

<div class="card">
    <h3 class="card-title">Identity</h3>
    <div class="kv-grid">
        <div class="kv-row"><div class="kv-label">Full name</div>      <div class="kv-value"><?= pv($crew['full_name']) ?></div></div>
        <div class="kv-row"><div class="kv-label">Date of birth</div>  <div class="kv-value"><?= pv($crew['date_of_birth']) ?></div></div>
        <div class="kv-row"><div class="kv-label">INDOS number</div>   <div class="kv-value"><?= pv($crew['indos_number']) ?></div></div>
        <div class="kv-row"><div class="kv-label">Passport number</div><div class="kv-value"><?= pv($crew['passport_number']) ?></div></div>
        <div class="kv-row"><div class="kv-label">Joiner type</div>
            <div class="kv-value">
                <?php if (($crew['joiner_type'] ?? '') === 'rejoiner'): ?>
                    <span class="status status-green">Rejoiner</span>
                <?php else: ?>
                    <span class="status status-gray">New joiner</span>
                <?php endif; ?>
            </div>
        </div>
        <div class="kv-row"><div class="kv-label">Rank</div>            <div class="kv-value"><?= pv($crew['rank_name']) ?></div></div>
    </div>
</div>

<div class="card">
    <h3 class="card-title">Contact</h3>
    <div class="kv-grid">
        <div class="kv-row"><div class="kv-label">Phone</div> <div class="kv-value"><?= pv($crew['contact_number']) ?></div></div>
        <div class="kv-row"><div class="kv-label">Email</div> <div class="kv-value"><?= pv($crew['email']) ?></div></div>
        <div class="kv-row full"><div class="kv-label">Address</div> <div class="kv-value"><?= nl2br(pv($crew['full_address'])) ?></div></div>
    </div>
</div>

<div class="card">
    <h3 class="card-title">Assignment</h3>
    <div class="kv-grid">
        <div class="kv-row"><div class="kv-label">Company</div> <div class="kv-value"><?= pv($crew['company_name']) ?></div></div>
        <div class="kv-row"><div class="kv-label">Vessel</div>  <div class="kv-value"><?= pv($crew['vessel_name']) ?></div></div>
    </div>
</div>

<div class="card">
    <h3 class="card-title">Sizes</h3>
    <div class="kv-grid">
        <div class="kv-row"><div class="kv-label">Boiler suit</div>  <div class="kv-value"><?= pv($crew['boiler_suit_size']) ?></div></div>
        <div class="kv-row"><div class="kv-label">Safety shoes</div> <div class="kv-value"><?= pv($crew['safety_shoes_size']) ?></div></div>
        <div class="kv-row"><div class="kv-label">Shirt</div>        <div class="kv-value"><?= pv($crew['shirt_size']) ?></div></div>
        <div class="kv-row"><div class="kv-label">Pant</div>         <div class="kv-value"><?= pv($crew['pant_size']) ?></div></div>
    </div>
</div>

<?php if ($latestContract): ?>
<div class="card">
    <h3 class="card-title">From your latest contract</h3>
    <p class="help-text">
        These fields are captured per contract. Reference number
        <strong><?= h($latestContract['reference_number'] ?? '—') ?></strong>
        dated <?= h($latestContract['contract_date'] ?? '—') ?>.
    </p>
    <div class="kv-grid">
        <div class="kv-row"><div class="kv-label">Place of birth</div>  <div class="kv-value"><?= pv($latestContract['place_of_birth']) ?></div></div>
        <div class="kv-row"><div class="kv-label">Home town</div>       <div class="kv-value"><?= pv($latestContract['home_town']) ?></div></div>
        <div class="kv-row"><div class="kv-label">Nearest airport</div> <div class="kv-value"><?= pv($latestContract['nearest_airport']) ?></div></div>
    </div>
</div>

<div class="card">
    <h3 class="card-title">Next of kin</h3>
    <div class="kv-grid">
        <div class="kv-row"><div class="kv-label">Name</div>         <div class="kv-value"><?= pv($latestContract['next_of_kin_name']) ?></div></div>
        <div class="kv-row"><div class="kv-label">Relationship</div> <div class="kv-value"><?= pv($latestContract['next_of_kin_relationship']) ?></div></div>
        <div class="kv-row"><div class="kv-label">Phone</div>        <div class="kv-value"><?= pv($latestContract['next_of_kin_contact']) ?></div></div>
        <div class="kv-row"><div class="kv-label">Email</div>        <div class="kv-value"><?= pv($latestContract['next_of_kin_email']) ?></div></div>
        <div class="kv-row full"><div class="kv-label">Address</div> <div class="kv-value"><?= nl2br(pv($latestContract['next_of_kin_address'])) ?></div></div>
    </div>
</div>

<div class="card">
    <h3 class="card-title">Beneficiary</h3>
    <div class="kv-grid">
        <div class="kv-row"><div class="kv-label">Name</div>         <div class="kv-value"><?= pv($latestContract['beneficiary_name']) ?></div></div>
        <div class="kv-row"><div class="kv-label">Relationship</div> <div class="kv-value"><?= pv($latestContract['beneficiary_relationship']) ?></div></div>
        <div class="kv-row"><div class="kv-label">Percentage</div>
            <div class="kv-value">
                <?php
                    $pct = $latestContract['beneficiary_percentage'] ?? null;
                    echo ($pct === null || $pct === '') ? '<span class="muted">—</span>'
                         : h(rtrim(rtrim(number_format((float)$pct, 2), '0'), '.')) . '%';
                ?>
            </div>
        </div>
    </div>
</div>
<?php else: ?>
<div class="card">
    <p class="help-text">No contract is on file yet — once your first contract is generated, your place of birth, home town, next-of-kin and beneficiary information will appear here.</p>
</div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
