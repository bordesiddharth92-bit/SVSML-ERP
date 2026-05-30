<?php
/**
 * Left sidebar navigation. Premium maritime theme: dark navy with
 * gold accent on the active item, lucide-style stroke icons next to
 * every link.
 *
 * Module 1–17 destinations are wired up. Disabled stubs would be
 * reserved for any future modules that haven't shipped (currently
 * none — every link is live).
 */
$user = currentUser();
$role = $user['role'] ?? null;

// Active-link detection — match the current script name.
$current = basename($_SERVER['SCRIPT_NAME'] ?? '');

/**
 * Inline SVG icon strings for the sidebar. Lucide-icons-style strokes
 * at 18×18; the CSS handles colour via currentColor + sets stroke
 * width. Returning '' for unknown names keeps the layout clean if a
 * caller passes a typo.
 */
function nav_icon(string $name): string
{
    $svg = function (string $body): string {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" '
             . 'stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">'
             . $body . '</svg>';
    };
    switch ($name) {
        case 'dashboard': return $svg('<rect x="3" y="3" width="7" height="9"></rect><rect x="14" y="3" width="7" height="5"></rect><rect x="14" y="12" width="7" height="9"></rect><rect x="3" y="16" width="7" height="5"></rect>');
        case 'users':     return $svg('<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path>');
        case 'user-plus': return $svg('<path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="8.5" cy="7" r="4"></circle><line x1="20" y1="8" x2="20" y2="14"></line><line x1="23" y1="11" x2="17" y2="11"></line>');
        case 'briefcase': return $svg('<rect x="2" y="7" width="20" height="14" rx="2" ry="2"></rect><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"></path>');
        case 'ship':      return $svg('<path d="M2 20a2.5 2.5 0 0 0 2 1 2.5 2.5 0 0 0 2-1 2.5 2.5 0 0 1 2-1 2.5 2.5 0 0 1 2 1 2.5 2.5 0 0 0 2 1 2.5 2.5 0 0 0 2-1 2.5 2.5 0 0 1 2-1 2.5 2.5 0 0 1 2 1 2.5 2.5 0 0 0 2 1 2.5 2.5 0 0 0 2-1"></path><path d="M14 5V3.5a2.5 2.5 0 0 0-5 0V5"></path><path d="M3 17l9-13 9 13"></path><path d="M12 4v13"></path>');
        case 'calendar':  return $svg('<rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line>');
        case 'file-text': return $svg('<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line>');
        case 'plane':     return $svg('<path d="M17.8 19.2L16 11l3.5-3.5C21 6 21.5 4 21 3c-1-.5-3 0-4.5 1.5L13 8 4.8 6.2c-.5-.1-.9.1-1.1.5l-.3.5c-.2.5-.1 1 .3 1.3L9 12l-2 3H4l-1 1 3 2 2 3 1-1v-3l3-2 3.5 5.3c.3.4.8.5 1.3.3l.5-.2c.4-.3.6-.7.5-1.2z"></path>');
        case 'check':     return $svg('<polyline points="20 6 9 17 4 12"></polyline>');
        case 'check-square': return $svg('<polyline points="9 11 12 14 22 4"></polyline><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path>');
        case 'zap':       return $svg('<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon>');
        case 'alert':     return $svg('<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9"  x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line>');
        case 'list':      return $svg('<line x1="8" y1="6" x2="21" y2="6"></line><line x1="8" y1="12" x2="21" y2="12"></line><line x1="8" y1="18" x2="21" y2="18"></line><line x1="3" y1="6" x2="3.01" y2="6"></line><line x1="3" y1="12" x2="3.01" y2="12"></line><line x1="3" y1="18" x2="3.01" y2="18"></line>');
        case 'sliders':   return $svg('<line x1="4" y1="21" x2="4" y2="14"></line><line x1="4" y1="10" x2="4" y2="3"></line><line x1="12" y1="21" x2="12" y2="12"></line><line x1="12" y1="8" x2="12" y2="3"></line><line x1="20" y1="21" x2="20" y2="16"></line><line x1="20" y1="12" x2="20" y2="3"></line><line x1="1" y1="14" x2="7" y2="14"></line><line x1="9" y1="8" x2="15" y2="8"></line><line x1="17" y1="16" x2="23" y2="16"></line>');
        case 'tag':       return $svg('<path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"></path><line x1="7" y1="7" x2="7.01" y2="7"></line>');
        case 'settings':  return $svg('<circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path>');
        case 'shield':    return $svg('<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>');
        case 'user':      return $svg('<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle>');
        case 'heart':     return $svg('<path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"></path>');
        case 'book':      return $svg('<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path>');
        case 'anchor':    return $svg('<circle cx="12" cy="5" r="3"></circle><line x1="12" y1="22" x2="12" y2="8"></line><path d="M5 12H2a10 10 0 0 0 20 0h-3"></path>');
        case 'key':       return $svg('<path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"></path>');
        case 'dollar':    return $svg('<line x1="12" y1="1" x2="12" y2="23"></line><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path>');
        case 'home':      return $svg('<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline>');
    }
    return '';
}

/**
 * Render a navigation link. $href can be null (= disabled stub).
 *
 * Active-link detection treats edit pages as part of their list page
 * (e.g. vessel-edit.php → "Vessels", crew-* tabs → "Crew List",
 * quick-approval-edit.php → "Quick Approval") so the sidebar still
 * highlights the right section when the user drills in.
 */
function nav_link(?string $href, string $label, string $current, string $icon = '', ?string $tooltip = null): string
{
    $iconHtml = $icon !== '' ? nav_icon($icon) : '';

    if ($href === null) {
        $title = $tooltip ? ' title="' . h($tooltip) . '"' : '';
        return '<a class="nav-link disabled"' . $title . '>' . $iconHtml . '<span>' . h($label) . '</span></a>';
    }
    $hrefBase = basename($href);
    static $editGroups = [
        'vessels.php' => ['vessel-edit.php'],
        'crew.php'    => [
            'crew-edit.php', 'crew-documents.php', 'crew-medical.php',
            'crew-courses.php', 'crew-sailing-history.php', 'crew-signon.php',
            'crew-contracts.php', 'crew-travel.php',
            'crew-client-approvals.php', 'crew-svsml-approvals.php',
        ],
        'signon.php'           => ['crew-signon.php'],
        'contracts.php'        => ['crew-contracts.php'],
        'travel.php'           => ['crew-travel.php'],
        'client-approvals.php' => ['crew-client-approvals.php'],
        'svsml-approvals.php'  => ['crew-svsml-approvals.php'],
        'quick-approvals.php'  => ['quick-approval-edit.php'],
    ];
    $isActive = ($hrefBase === $current)
        || (isset($editGroups[$hrefBase]) && in_array($current, $editGroups[$hrefBase], true));
    $active = $isActive ? ' active' : '';
    return '<a class="nav-link' . $active . '" href="' . asset($href) . '">'
         . $iconHtml . '<span>' . h($label) . '</span></a>';
}
?>
<aside class="sidebar" id="js-sidebar">
    <div class="brand">
        <div class="brand-logo"><?= h(APP_SHORT) ?></div>
        <div class="brand-text">
            <div class="brand-short"><?= h(APP_SHORT) ?></div>
            <div class="brand-full">Sea Voyage Ship Management</div>
        </div>
    </div>

    <nav class="nav">
        <?php if (in_array($role, ['admin', 'sub_admin', 'staff'], true)): ?>
            <?= nav_link('dashboard.php', 'Dashboard', $current, 'dashboard') ?>

            <div class="nav-section">Crew</div>
            <?= nav_link('crew.php',      'Crew List', $current, 'users') ?>
            <?= nav_link('crew-edit.php', 'Add Crew',  $current, 'user-plus') ?>

            <div class="nav-section">Operations</div>
            <?= nav_link('companies.php', 'Companies',      $current, 'briefcase') ?>
            <?= nav_link('vessels.php',   'Vessels',        $current, 'ship') ?>
            <?= nav_link('signon.php',    'Sign On / Off',  $current, 'calendar') ?>
            <?= nav_link('contracts.php', 'Contracts',      $current, 'file-text') ?>
            <?= nav_link('travel.php',    'Travel Details', $current, 'plane') ?>

            <div class="nav-section">Approvals</div>
            <?= nav_link('client-approvals.php', 'Client Approval', $current, 'check-square') ?>
            <?= nav_link('svsml-approvals.php',  'SVSML Approval',  $current, 'check') ?>
            <?= nav_link('quick-approvals.php',  'Quick Approval',  $current, 'zap') ?>

            <div class="nav-section">Reports</div>
            <?= nav_link('alerts.php', 'Expiry Alerts', $current, 'alert') ?>
            <?php if (in_array($role, ['admin', 'sub_admin'], true)): ?>
                <?= nav_link('activity-log.php', 'Activity Log', $current, 'list') ?>
            <?php endif; ?>

            <div class="nav-section">Settings</div>
            <?= nav_link('dropdowns.php', 'Dropdowns', $current, 'sliders') ?>
            <?= nav_link('ranks.php',     'Ranks',     $current, 'tag') ?>
        <?php endif; ?>

        <?php if (in_array($role, ['admin', 'sub_admin'], true)): ?>
            <?= nav_link('crew-portal-admin.php', 'Crew Portal Admin', $current, 'shield') ?>
            <?= nav_link('settings.php',          'System Settings',   $current, 'settings') ?>
        <?php endif; ?>

        <?php if ($role === 'crew'): ?>
            <div class="nav-section">My Profile</div>
            <?= nav_link('crew-portal.php',           'Overview',  $current, 'home') ?>
            <?= nav_link('crew-portal-personal.php',  'Personal',  $current, 'user') ?>
            <?= nav_link('crew-portal-documents.php', 'Documents', $current, 'file-text') ?>
            <?= nav_link('crew-portal-medical.php',   'Medical',   $current, 'heart') ?>
            <?= nav_link('crew-portal-courses.php',   'Courses',   $current, 'book') ?>
            <?= nav_link('crew-portal-sailing.php',   'Sailing',   $current, 'anchor') ?>
            <?= nav_link('crew-portal-contracts.php', 'Contracts', $current, 'file-text') ?>
            <?= nav_link('crew-portal-travel.php',    'Travel',    $current, 'plane') ?>
            <?php /* Approvals tab removed — crew never see financial / dues data. */ ?>

            <div class="nav-section">Account</div>
            <?= nav_link('crew-portal-password.php',  'Change password', $current, 'key') ?>
        <?php endif; ?>
    </nav>
</aside>
