<?php
/**
 * Tab navigation rendered at the top of every crew-portal-*.php page.
 *
 * Mirrors the staff-side includes/crew-tabs.php in spirit, but every
 * link goes to a crew-portal-* page (not a crew-* admin page) so the
 * crew never lands on staff edit screens by accident.
 *
 * Set $currentTab in the page before including this file:
 *   $currentTab = 'overview' | 'personal' | 'documents' | 'medical'
 *               | 'courses' | 'sailing' | 'contracts' | 'travel'
 *               | 'approvals' | 'password';
 */
if (!isset($currentTab)) $currentTab = '';

$portalTabs = [
    'overview'   => ['label' => 'Overview',   'href' => 'crew-portal.php'],
    'personal'   => ['label' => 'My Profile', 'href' => 'crew-portal-personal.php'],
    'documents'  => ['label' => 'Documents',  'href' => 'crew-portal-documents.php'],
    'medical'    => ['label' => 'Medical',    'href' => 'crew-portal-medical.php'],
    'courses'    => ['label' => 'Courses',    'href' => 'crew-portal-courses.php'],
    'sailing'    => ['label' => 'Sailing',    'href' => 'crew-portal-sailing.php'],
    'contracts'  => ['label' => 'Contracts',  'href' => 'crew-portal-contracts.php'],
    'travel'     => ['label' => 'Travel',     'href' => 'crew-portal-travel.php'],
    /* 'approvals' tab removed — crew never see financial / dues data. */
    'password'   => ['label' => 'Password',   'href' => 'crew-portal-password.php'],
];
?>
<nav class="tabs">
    <?php foreach ($portalTabs as $key => $tab):
        $isActive = ($currentTab === $key);
    ?>
        <a class="tab<?= $isActive ? ' active' : '' ?>"
           href="<?= asset($tab['href']) ?>"
           <?= $isActive ? 'aria-current="page"' : '' ?>>
            <?= h($tab['label']) ?>
        </a>
    <?php endforeach; ?>
</nav>
