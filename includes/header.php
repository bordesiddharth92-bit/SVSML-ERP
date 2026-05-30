<?php
/**
 * Shared HTML head + topbar.
 *
 * Set $pageTitle (and optionally $pageBreadcrumbs as an array of
 * label => href) before including this file. Topbar shows:
 *   - hamburger (mobile only)
 *   - breadcrumb / page title
 *   - notifications bell with unread expiry count for staff
 *   - circular user avatar with name + role
 *   - logout
 */
$pageTitle       = $pageTitle       ?? APP_NAME;
$pageBreadcrumbs = $pageBreadcrumbs ?? null;
$user            = currentUser();

/**
 * Two-letter initials for the topbar avatar. Mirrors crewInitials()
 * so staff and crew users get the same visual treatment.
 */
function topbarInitials(?string $name): string
{
    if (!$name) return '?';
    $name  = trim($name);
    $parts = preg_split('/\s+/u', $name);
    if (!$parts) return '?';
    if (count($parts) === 1) return mb_strtoupper(mb_substr($parts[0], 0, 2, 'UTF-8'));
    return mb_strtoupper(mb_substr($parts[0], 0, 1, 'UTF-8') . mb_substr(end($parts), 0, 1, 'UTF-8'));
}

/**
 * Cheap unread-alert count for the topbar bell. Counts expired and
 * expiring-within-yellow-window items across the four crew tables +
 * three vessel certificate dates. Returns null for crew users (their
 * bell points at their own portal home, not the global alerts page).
 */
function topbarAlertCount(PDO $pdo, ?array $user): ?int
{
    if (!$user || ($user['role'] ?? '') === 'crew') return null;
    try {
        $alerts = function_exists('getAlertSettings') ? getAlertSettings($pdo) : [];
        $yellow = (int)($alerts['doc_expiry_yellow_days'] ?? 30);
        $sql = "SELECT
                    SUM(CASE WHEN expiry_date IS NOT NULL
                              AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL :d DAY)
                              THEN 1 ELSE 0 END) AS cnt
                FROM (
                    SELECT expiry_date FROM crew_documents
                    UNION ALL SELECT expiry_date FROM crew_medical
                    UNION ALL SELECT expiry_date FROM basic_courses
                    UNION ALL SELECT expiry_date FROM advanced_courses
                ) t";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':d' => $yellow]);
        $n = (int)$stmt->fetchColumn();
        return $n;
    } catch (Throwable $e) {
        return null;
    }
}

$alertCount = isset($pdo) ? topbarAlertCount($pdo, $user) : null;
$bellHref   = ($user && ($user['role'] ?? '') === 'crew') ? 'crew-portal.php' : 'alerts.php';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title><?= h($pageTitle) ?> &mdash; <?= h(APP_NAME) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="<?= asset('assets/css/style.css') ?>">
</head>
<body>
<div class="layout">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <main class="main">
        <header class="topbar">
            <div class="topbar-left">
                <button type="button" class="hamburger" id="js-sidebar-toggle" aria-label="Toggle navigation">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="3" y1="6" x2="21" y2="6"></line>
                        <line x1="3" y1="12" x2="21" y2="12"></line>
                        <line x1="3" y1="18" x2="21" y2="18"></line>
                    </svg>
                </button>
                <div>
                    <?php if ($user): ?>
                        <div class="breadcrumb">
                            <a href="<?= asset(($user['role'] ?? '') === 'crew' ? 'crew-portal.php' : 'dashboard.php') ?>">
                                <?= h(($user['role'] ?? '') === 'crew' ? 'My Profile' : 'Home') ?>
                            </a>
                            <span class="breadcrumb-sep">/</span>
                            <span><?= h($pageTitle) ?></span>
                        </div>
                    <?php endif; ?>
                    <div class="topbar-title"><?= h($pageTitle) ?></div>
                </div>
            </div>

            <div class="topbar-right">
                <?php if ($user): ?>
                    <a class="icon-btn" href="<?= asset($bellHref) ?>" aria-label="Alerts" title="Expiry alerts">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                             stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
                            <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
                        </svg>
                        <?php if ($alertCount !== null && $alertCount > 0): ?>
                            <span class="badge-dot"><?= $alertCount > 99 ? '99+' : (int)$alertCount ?></span>
                        <?php endif; ?>
                    </a>

                    <div class="user-chip">
                        <div class="user-avatar"><?= h(topbarInitials($user['full_name'] ?? '')) ?></div>
                        <div class="user-info">
                            <span class="user-name"><?= h($user['full_name']) ?></span>
                            <span class="user-role-text">
                                <span class="badge badge-<?= h($user['role']) ?>"><?= h(strtoupper($user['role'])) ?></span>
                            </span>
                        </div>
                    </div>

                    <a class="icon-btn" href="<?= asset('logout.php') ?>" aria-label="Sign out" title="Sign out">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                             stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                            <polyline points="16 17 21 12 16 7"></polyline>
                            <line x1="21" y1="12" x2="9" y2="12"></line>
                        </svg>
                    </a>
                <?php endif; ?>
            </div>
        </header>

        <div class="content">
            <?php foreach (getFlashes() as $f): ?>
                <div class="flash flash-<?= h($f['type']) ?>"><?= h($f['message']) ?></div>
            <?php endforeach; ?>
