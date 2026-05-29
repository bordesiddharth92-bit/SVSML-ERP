<?php
/**
 * Reusable hero header rendered at the top of every crew-portal-*.php
 * page. Expects the calling page to have set:
 *
 *   $crew           — array (output of fetchCrewWithJoins())
 *   $latestContract — array|null (output of fetchLatestContractForCrew())
 *
 * Pulls the photo from the latest contract (which is where it's stored
 * in our schema) and falls back to two-letter initials when no photo
 * is on file.
 */
$photoUrl = isset($latestContract) ? crewPhotoUrl($latestContract) : '';

// Onboard since? — small badge on the right.
if (!isset($onboardSinceForHero)) {
    $st = $pdo->prepare(
        "SELECT sign_on_date FROM sign_on_off
          WHERE crew_id = :c AND sign_off_date IS NULL ORDER BY id DESC LIMIT 1"
    );
    $st->execute([':c' => (int)$crew['id']]);
    $onboardSinceForHero = $st->fetchColumn();
}
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
        <?php if ($onboardSinceForHero): ?>
            <span class="badge">ON BOARD since <?= h($onboardSinceForHero) ?></span>
        <?php else: ?>
            <span class="badge">Ashore</span>
        <?php endif; ?>
    </div>
</div>
