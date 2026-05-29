<?php
/**
 * Crew detail sub-navigation.
 *
 * Caller must set:
 *   $crew        - associative array of the current crew row, joined with
 *                  ranks, companies and vessels (use fetchCrewWithJoins()).
 *   $currentTab  - one of: 'personal' | 'documents' | 'medical' | 'courses' |
 *                  'sailing' | 'signon' | 'contract' | 'travel'.
 *
 * Renders a header card showing the crew identity plus a tabs row that
 * links to the sibling pages. Tabs whose modules haven't shipped yet
 * appear disabled with a tooltip indicating which module they ship in.
 */

$crewId = (int)($crew['id'] ?? 0);

$tabs = [
    'personal'  => ['label' => 'Personal',          'href' => 'crew-edit.php?id='            . $crewId, 'available' => true],
    'documents' => ['label' => 'Documents',         'href' => 'crew-documents.php?id='       . $crewId, 'available' => true],
    'medical'   => ['label' => 'Medical',           'href' => 'crew-medical.php?id='         . $crewId, 'available' => true],
    'courses'   => ['label' => 'Courses',           'href' => 'crew-courses.php?id='         . $crewId, 'available' => true],
    'sailing'   => ['label' => 'Sailing history',   'href' => 'crew-sailing-history.php?id=' . $crewId, 'available' => true],
    'signon'    => ['label' => 'Sign on/off',       'href' => null, 'tooltip' => 'Coming in Module 7'],
    'contract'  => ['label' => 'Contract',          'href' => null, 'tooltip' => 'Coming in Module 8'],
    'travel'    => ['label' => 'Travel',            'href' => null, 'tooltip' => 'Coming in Module 9'],
];

$currentTab = $currentTab ?? 'personal';
?>
<div class="card">
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
        <div>
            <strong style="font-size:18px;"><?= h($crew['full_name'] ?? '') ?></strong>
            <span class="help-text">
                <?php if (!empty($crew['rank_name'])): ?>
                    &middot; <?= h($crew['rank_name']) ?>
                <?php endif; ?>
                <?php if (!empty($crew['vessel_name'])): ?>
                    &middot; <?= h($crew['vessel_name']) ?>
                <?php endif; ?>
                <?php if (!empty($crew['company_name'])): ?>
                    &middot; <?= h($crew['company_name']) ?>
                <?php endif; ?>
                <?php if (!empty($crew['passport_number'])): ?>
                    &middot; Passport <?= h($crew['passport_number']) ?>
                <?php endif; ?>
            </span>
        </div>
        <a class="btn btn-ghost btn-sm" href="<?= asset('crew.php') ?>">← Crew list</a>
    </div>

    <div class="tabs" style="margin-top:14px;">
        <?php foreach ($tabs as $key => $t): ?>
            <?php if (!empty($t['available']) && $t['href']): ?>
                <a class="tab<?= $currentTab === $key ? ' active' : '' ?>"
                   href="<?= asset($t['href']) ?>"><?= h($t['label']) ?></a>
            <?php else: ?>
                <span class="tab" title="<?= h($t['tooltip'] ?? '') ?>"
                      style="opacity:0.5; cursor:not-allowed;"><?= h($t['label']) ?></span>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
</div>
