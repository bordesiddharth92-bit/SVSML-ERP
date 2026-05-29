<?php
/**
 * Left sidebar navigation. Items are stubs for the modules
 * that have not shipped yet; active modules link normally.
 */
$user = currentUser();
$role = $user['role'] ?? null;

// Active-link detection — match the current script name.
$current = basename($_SERVER['SCRIPT_NAME'] ?? '');

/**
 * Render a navigation link. $href can be null (= disabled stub).
 *
 * Active-link detection treats vessel-edit.php as part of "Vessels"
 * and crew-edit.php as part of "Crew List", so the sidebar still
 * highlights the right section when the user drills into an edit page.
 */
function nav_link(?string $href, string $label, string $current, ?string $tooltip = null): string
{
    if ($href === null) {
        $title = $tooltip ? ' title="' . h($tooltip) . '"' : '';
        return '<a class="nav-link disabled"' . $title . '>' . h($label) . '</a>';
    }
    $hrefBase = basename($href);
    // Group edit pages with their list page for highlighting purposes.
    static $editGroups = [
        'vessels.php' => ['vessel-edit.php'],
        'crew.php'    => ['crew-edit.php'],
    ];
    $isActive = ($hrefBase === $current)
        || (isset($editGroups[$hrefBase]) && in_array($current, $editGroups[$hrefBase], true));
    $active = $isActive ? ' active' : '';
    return '<a class="nav-link' . $active . '" href="' . asset($href) . '">' . h($label) . '</a>';
}
?>
<aside class="sidebar">
    <div class="brand">
        <div class="brand-short"><?= h(APP_SHORT) ?></div>
        <div class="brand-full">Sea Voyage Ship Management</div>
    </div>

    <nav class="nav">
        <?= nav_link('dashboard.php', 'Dashboard', $current) ?>

        <?php if (in_array($role, ['admin', 'sub_admin', 'staff'], true)): ?>
            <div class="nav-section">Crew</div>
            <?= nav_link('crew.php',      'Crew List', $current) ?>
            <?= nav_link('crew-edit.php', 'Add Crew',  $current) ?>

            <div class="nav-section">Operations</div>
            <?= nav_link('companies.php', 'Companies', $current) ?>
            <?= nav_link('vessels.php',   'Vessels',   $current) ?>
            <?= nav_link(null, 'Sign On / Off', $current, 'Coming in Module 7') ?>
            <?= nav_link(null, 'Contracts',     $current, 'Coming in Module 8') ?>
            <?= nav_link(null, 'Travel Details',$current, 'Coming in Module 9') ?>

            <div class="nav-section">Approvals</div>
            <?= nav_link(null, 'Client Approval', $current, 'Coming in Module 10') ?>
            <?= nav_link(null, 'SVSML Approval',  $current, 'Coming in Module 11') ?>
            <?= nav_link(null, 'Quick Approval',  $current, 'Coming in Module 12') ?>

            <div class="nav-section">Reports</div>
            <?= nav_link(null, 'Expiry Alerts', $current, 'Coming in Module 15') ?>
            <?= nav_link(null, 'Activity Log',  $current, 'Coming in Module 13') ?>

            <div class="nav-section">Settings</div>
            <?= nav_link('dropdowns.php', 'Dropdowns', $current) ?>
            <?= nav_link('ranks.php',     'Ranks',     $current) ?>
        <?php endif; ?>

        <?php if (in_array($role, ['admin', 'sub_admin'], true)): ?>
            <?= nav_link('settings.php', 'System Settings', $current) ?>
        <?php endif; ?>

        <?php if ($role === 'crew'): ?>
            <div class="nav-section">My Profile</div>
            <?= nav_link(null, 'My Documents', $current, 'Coming in Module 16') ?>
            <?= nav_link(null, 'My Travel',    $current, 'Coming in Module 16') ?>
            <?= nav_link(null, 'My Contract',  $current, 'Coming in Module 16') ?>
        <?php endif; ?>
    </nav>
</aside>
