<?php
/**
 * Left sidebar navigation. All Module 1–16 destinations are wired up.
 * Disabled stubs are reserved for any future modules that haven't shipped.
 */
$user = currentUser();
$role = $user['role'] ?? null;

// Active-link detection — match the current script name.
$current = basename($_SERVER['SCRIPT_NAME'] ?? '');

/**
 * Render a navigation link. $href can be null (= disabled stub).
 *
 * Active-link detection treats edit pages as part of their list page
 * (e.g. vessel-edit.php → "Vessels", crew-* tabs → "Crew List",
 * quick-approval-edit.php → "Quick Approval") so the sidebar still
 * highlights the right section when the user drills in.
 */
function nav_link(?string $href, string $label, string $current, ?string $tooltip = null): string
{
    if ($href === null) {
        $title = $tooltip ? ' title="' . h($tooltip) . '"' : '';
        return '<a class="nav-link disabled"' . $title . '>' . h($label) . '</a>';
    }
    $hrefBase = basename($href);
    static $editGroups = [
        'vessels.php'           => ['vessel-edit.php'],
        'crew.php'              => [
            'crew-edit.php',
            'crew-documents.php',
            'crew-medical.php',
            'crew-courses.php',
            'crew-sailing-history.php',
            'crew-signon.php',
            'crew-contracts.php',
            'crew-travel.php',
            'crew-client-approvals.php',
            'crew-svsml-approvals.php',
        ],
        'signon.php'            => ['crew-signon.php'],
        'contracts.php'         => ['crew-contracts.php'],
        'travel.php'            => ['crew-travel.php'],
        'client-approvals.php'  => ['crew-client-approvals.php'],
        'svsml-approvals.php'   => ['crew-svsml-approvals.php'],
        'quick-approvals.php'   => ['quick-approval-edit.php'],
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
        <?php if (in_array($role, ['admin', 'sub_admin', 'staff'], true)): ?>
            <?= nav_link('dashboard.php', 'Dashboard', $current) ?>

            <div class="nav-section">Crew</div>
            <?= nav_link('crew.php',      'Crew List', $current) ?>
            <?= nav_link('crew-edit.php', 'Add Crew',  $current) ?>

            <div class="nav-section">Operations</div>
            <?= nav_link('companies.php', 'Companies', $current) ?>
            <?= nav_link('vessels.php',   'Vessels',   $current) ?>
            <?= nav_link('signon.php',    'Sign On / Off',  $current) ?>
            <?= nav_link('contracts.php', 'Contracts',      $current) ?>
            <?= nav_link('travel.php',    'Travel Details', $current) ?>

            <div class="nav-section">Approvals</div>
            <?= nav_link('client-approvals.php', 'Client Approval', $current) ?>
            <?= nav_link('svsml-approvals.php',  'SVSML Approval',  $current) ?>
            <?= nav_link('quick-approvals.php',  'Quick Approval',  $current) ?>

            <div class="nav-section">Reports</div>
            <?= nav_link('alerts.php', 'Expiry Alerts', $current) ?>
            <?php if (in_array($role, ['admin', 'sub_admin'], true)): ?>
                <?= nav_link('activity-log.php', 'Activity Log', $current) ?>
            <?php endif; ?>

            <div class="nav-section">Settings</div>
            <?= nav_link('dropdowns.php', 'Dropdowns', $current) ?>
            <?= nav_link('ranks.php',     'Ranks',     $current) ?>
        <?php endif; ?>

        <?php if (in_array($role, ['admin', 'sub_admin'], true)): ?>
            <?= nav_link('settings.php', 'System Settings', $current) ?>
        <?php endif; ?>

        <?php if ($role === 'crew'): ?>
            <div class="nav-section">My Profile</div>
            <?= nav_link('crew-portal.php',           'Overview',  $current) ?>
            <?= nav_link('crew-portal-personal.php',  'Personal',  $current) ?>
            <?= nav_link('crew-portal-documents.php', 'Documents', $current) ?>
            <?= nav_link('crew-portal-medical.php',   'Medical',   $current) ?>
            <?= nav_link('crew-portal-courses.php',   'Courses',   $current) ?>
            <?= nav_link('crew-portal-sailing.php',   'Sailing',   $current) ?>
            <?= nav_link('crew-portal-contracts.php', 'Contracts', $current) ?>
            <?= nav_link('crew-portal-travel.php',    'Travel',    $current) ?>
            <?= nav_link('crew-portal-approvals.php', 'Approvals', $current) ?>

            <div class="nav-section">Account</div>
            <?= nav_link('crew-portal-password.php',  'Change password', $current) ?>
        <?php endif; ?>
    </nav>
</aside>
