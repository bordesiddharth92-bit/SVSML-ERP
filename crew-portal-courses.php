<?php
/**
 * SVSML-ERP — Crew Portal: Courses (basic + advanced)
 *
 * Module 16. Read-only.
 */

require __DIR__ . '/config/app.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/helpers.php';

requireCrew();
$crewId = currentCrewId();
$crew   = $crewId ? fetchCrewWithJoins($pdo, $crewId) : null;
if (!$crew) { logoutCurrentUser(); header('Location: ' . url('crew-login.php')); exit; }

$latestContract = fetchLatestContractForCrew($pdo, $crewId);

$basic = $pdo->prepare("SELECT * FROM basic_courses WHERE crew_id = :c ORDER BY course_name");
$basic->execute([':c' => $crewId]);
$basic = $basic->fetchAll();

$advanced = $pdo->prepare("SELECT * FROM advanced_courses WHERE crew_id = :c ORDER BY course_name");
$advanced->execute([':c' => $crewId]);
$advanced = $advanced->fetchAll();

/**
 * Render a course list with consistent column layout. Both basic and
 * advanced courses share the same shape (course_name / course_number /
 * issue_date / expiry_date / file_path), so we share the renderer.
 */
function renderCourseTable(array $rows, string $emptyMsg): void
{
    if (empty($rows)) {
        echo '<p class="help-text">' . h($emptyMsg) . '</p>';
        return;
    }
    ?>
    <table class="data-table">
        <thead>
            <tr>
                <th>Course</th>
                <th>Number</th>
                <th>Issue</th>
                <th>Expiry</th>
                <th>Status</th>
                <th>File</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $c): ?>
                <tr>
                    <td><?= h($c['course_name']) ?></td>
                    <td><?= h($c['course_number'] ?? '—') ?></td>
                    <td><?= h($c['issue_date']    ?? '—') ?></td>
                    <td><?= h($c['expiry_date']   ?? '—') ?></td>
                    <td><?= dateStatusBadge($c['expiry_date'] ?? null) ?></td>
                    <td>
                        <?php if (!empty($c['file_path'])): ?>
                            <a class="action-link" target="_blank" rel="noopener"
                               href="<?= asset('uploads/' . $c['file_path']) ?>">View</a>
                        <?php else: ?>
                            <span class="muted">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php
}

$pageTitle  = 'My Profile — Courses';
$currentTab = 'courses';
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/crew-portal-hero.php';
include __DIR__ . '/includes/crew-portal-tabs.php';
?>

<div class="card">
    <h3 class="card-title">Basic courses <small class="help-text">(<?= count($basic) ?>)</small></h3>
    <?php renderCourseTable($basic, 'No basic courses on file yet.'); ?>
</div>

<div class="card">
    <h3 class="card-title">Advanced courses <small class="help-text">(<?= count($advanced) ?>)</small></h3>
    <?php renderCourseTable($advanced, 'No advanced courses on file yet.'); ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
