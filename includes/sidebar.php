<?php
/**
 * Left sidebar navigation. Items are stubs for Modules 2-16
 * and will be wired up as those modules ship.
 */
$user = currentUser();
$role = $user['role'] ?? null;
?>
<aside class="sidebar">
    <div class="brand">
        <div class="brand-short"><?= h(APP_SHORT) ?></div>
        <div class="brand-full">Sea Voyage Ship Management</div>
    </div>

    <nav class="nav">
        <a class="nav-link" href="<?= asset('dashboard.php') ?>">Dashboard</a>

        <?php if (in_array($role, ['admin', 'sub_admin', 'staff'], true)): ?>
            <div class="nav-section">Crew</div>
            <a class="nav-link disabled" title="Coming in Module 4">Crew List</a>
            <a class="nav-link disabled" title="Coming in Module 4">Add Crew</a>

            <div class="nav-section">Operations</div>
            <a class="nav-link disabled" title="Coming in Module 3">Companies &amp; Vessels</a>
            <a class="nav-link disabled" title="Coming in Module 7">Sign On / Off</a>
            <a class="nav-link disabled" title="Coming in Module 8">Contracts</a>
            <a class="nav-link disabled" title="Coming in Module 9">Travel Details</a>

            <div class="nav-section">Approvals</div>
            <a class="nav-link disabled" title="Coming in Module 10">Client Approval</a>
            <a class="nav-link disabled" title="Coming in Module 11">SVSML Approval</a>
            <a class="nav-link disabled" title="Coming in Module 12">Quick Approval</a>

            <div class="nav-section">Reports</div>
            <a class="nav-link disabled" title="Coming in Module 15">Expiry Alerts</a>
            <a class="nav-link disabled" title="Coming in Module 13">Activity Log</a>
        <?php endif; ?>

        <?php if (in_array($role, ['admin', 'sub_admin'], true)): ?>
            <div class="nav-section">Admin</div>
            <a class="nav-link disabled" title="Coming in Module 2">Dropdowns</a>
            <a class="nav-link disabled" title="Coming in Module 2">System Settings</a>
        <?php endif; ?>

        <?php if ($role === 'crew'): ?>
            <div class="nav-section">My Profile</div>
            <a class="nav-link disabled" title="Coming in Module 16">My Documents</a>
            <a class="nav-link disabled" title="Coming in Module 16">My Travel</a>
            <a class="nav-link disabled" title="Coming in Module 16">My Contract</a>
        <?php endif; ?>
    </nav>
</aside>
