<?php
/**
 * SVSML-ERP — Crew Portal: Courses (Module 18 update)
 *
 * Crew can:
 *   - View their basic + advanced courses
 *   - Upload a new course (basic or advanced)
 *   - Replace the file on an existing course row
 *   - Cannot delete (only admin/staff can)
 */

require __DIR__ . '/config/app.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/helpers.php';

requireOnboardedCrew($pdo);
$crewId = currentCrewId();
$crew   = $crewId ? fetchCrewWithJoins($pdo, $crewId) : null;
if (!$crew) { logoutCurrentUser(); header('Location: ' . url('crew-login.php')); exit; }

$latestContract = fetchLatestContractForCrew($pdo, $crewId);

/** Validate that a 'category' input matches one of our two course tables. */
function courseTableFor(string $category): ?string
{
    if ($category === 'basic')    return 'basic_courses';
    if ($category === 'advanced') return 'advanced_courses';
    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $cat       = $_POST['category']      ?? '';
        $courseName = trim($_POST['course_name']   ?? '');
        $courseNum  = trim($_POST['course_number'] ?? '');
        $issue      = trim($_POST['issue_date']    ?? '');
        $expiry     = trim($_POST['expiry_date']   ?? '');

        $tbl = courseTableFor($cat);
        $errors = [];
        if (!$tbl)              $errors[] = 'Invalid course category.';
        if ($courseName === '') $errors[] = 'Course name is required.';
        if (($e = validateIssueDate($issue))                       !== null) $errors[] = $e;
        if (($e = validateExpiryAfterIssue($expiry, $issue))       !== null) $errors[] = $e;

        $filePath = null;
        if (empty($errors)) {
            try {
                $slug = ucfirst($cat) . 'Course_' . $courseName;
                $filePath = saveCrewUpload('file', $crew, $slug, true);
            } catch (RuntimeException $upErr) {
                $errors[] = 'File: ' . $upErr->getMessage();
            }
        }

        if (!empty($errors)) {
            foreach ($errors as $e) flash('error', $e);
        } else {
            $stmt = $pdo->prepare(
                "INSERT INTO {$tbl}
                    (crew_id, course_name, course_number, issue_date, expiry_date, file_path, created_at, updated_at)
                 VALUES (:c, :n, :no, :is, :ex, :fp, NOW(), NOW())"
            );
            $stmt->execute([
                ':c' => $crewId,
                ':n' => $courseName,
                ':no'=> $courseNum !== '' ? $courseNum : null,
                ':is'=> $issue  !== '' ? $issue  : null,
                ':ex'=> $expiry !== '' ? $expiry : null,
                ':fp'=> $filePath,
            ]);
            $newId = (int)$pdo->lastInsertId();
            logActivity($pdo, null, $filePath ? 'upload' : 'create', $tbl, $newId,
                "Crew {$crewId} uploaded {$cat} course '{$courseName}'");
            flash('success', ucfirst($cat) . ' course added.');
        }
        header('Location: ' . url('crew-portal-courses.php'));
        exit;
    }

    if ($action === 'replace_file') {
        $cat = $_POST['category'] ?? '';
        $tbl = courseTableFor($cat);
        $id  = (int)($_POST['course_id'] ?? 0);
        if (!$tbl) { flash('error', 'Invalid course category.'); }
        else {
            $cur = $pdo->prepare("SELECT * FROM {$tbl} WHERE id = :i AND crew_id = :c");
            $cur->execute([':i' => $id, ':c' => $crewId]);
            $row = $cur->fetch();
            if (!$row) {
                flash('error', 'Course not found.');
            } else {
                try {
                    $slug = ucfirst($cat) . 'Course_' . ($row['course_name'] ?? 'Course');
                    $newPath = saveCrewUpload('file', $crew, $slug, false);
                    $pdo->prepare("UPDATE {$tbl} SET file_path = :f, updated_at = NOW() WHERE id = :i AND crew_id = :c")
                        ->execute([':f' => $newPath, ':i' => $id, ':c' => $crewId]);
                    logActivity($pdo, null, 'upload', $tbl, $id,
                        "Crew {$crewId} replaced file on {$cat} course {$id}");
                    flash('success', 'File replaced.');
                } catch (RuntimeException $e) {
                    flash('error', 'Upload failed: ' . $e->getMessage());
                }
            }
        }
        header('Location: ' . url('crew-portal-courses.php'));
        exit;
    }
}

// -------------------------------------------------------------
// GET
// -------------------------------------------------------------
$basic = $pdo->prepare("SELECT * FROM basic_courses WHERE crew_id = :c ORDER BY course_name");
$basic->execute([':c' => $crewId]);
$basic = $basic->fetchAll();

$advanced = $pdo->prepare("SELECT * FROM advanced_courses WHERE crew_id = :c ORDER BY course_name");
$advanced->execute([':c' => $crewId]);
$advanced = $advanced->fetchAll();

/**
 * Render a course list with the 'Replace file' control. Both basic
 * and advanced share the same shape so we share the renderer.
 */
function renderCourseTable(array $rows, string $category, string $emptyMsg): void
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
                <th>Replace file</th>
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
                    <td>
                        <form method="post" enctype="multipart/form-data" style="display:flex; gap:6px; align-items:center;">
                            <?= csrfField() ?>
                            <input type="hidden" name="action"   value="replace_file">
                            <input type="hidden" name="category" value="<?= h($category) ?>">
                            <input type="hidden" name="course_id" value="<?= (int)$c['id'] ?>">
                            <input type="file" name="file" required accept=".pdf,.jpg,.jpeg,.png,.docx" style="font-size:12px;">
                            <button type="submit" class="btn btn-secondary btn-sm">Replace</button>
                        </form>
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
    <?php renderCourseTable($basic, 'basic', 'No basic courses on file yet.'); ?>
</div>

<div class="card">
    <h3 class="card-title">Advanced courses <small class="help-text">(<?= count($advanced) ?>)</small></h3>
    <?php renderCourseTable($advanced, 'advanced', 'No advanced courses on file yet.'); ?>
</div>

<div class="card">
    <h3 class="card-title">Upload a new course</h3>
    <p class="help-text">Need to delete or correct an entry? Please contact SVSML.</p>
    <form method="post" enctype="multipart/form-data" novalidate>
        <?= csrfField() ?>
        <input type="hidden" name="action" value="add">
        <div class="form-grid">
            <div class="form-row">
                <label for="category">Category *</label>
                <select id="category" name="category" required>
                    <option value="basic">Basic course</option>
                    <option value="advanced">Advanced course</option>
                </select>
            </div>
            <div class="form-row">
                <label for="course_name">Course name *</label>
                <input type="text" id="course_name" name="course_name" required maxlength="100">
            </div>
            <div class="form-row">
                <label for="course_number">Course / certificate number</label>
                <input type="text" id="course_number" name="course_number" maxlength="100">
            </div>
            <div class="form-row">
                <label for="issue_date">Issue date</label>
                <input type="date" id="issue_date" name="issue_date">
            </div>
            <div class="form-row">
                <label for="expiry_date">Expiry date</label>
                <input type="date" id="expiry_date" name="expiry_date">
            </div>
            <div class="form-row full-row">
                <label for="file">File (PDF / JPG / PNG / DOCX, max 10 MB)</label>
                <input type="file" id="file" name="file" accept=".pdf,.jpg,.jpeg,.png,.docx">
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn">Add course</button>
        </div>
    </form>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
