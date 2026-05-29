<?php
/**
 * SVSML-ERP — Crew courses (basic + advanced)
 *
 * Module 5.
 *
 * Manages basic_courses and advanced_courses for a single crew member.
 * On first view, the page lazy-seeds the standard course rows for
 * crew records that were created before Module 5 was deployed
 * (the Module 4 INSERT path now seeds them on creation).
 *
 * Each row is editable in place: course_name, course_number, issue_date,
 * expiry_date, optional file. Status badge driven by expiry_date.
 *
 * Course lists per crew (defaults seeded once):
 *   Basic    : BST, PST, PSSR, FPFF, EFA, STSDSD, Security Awareness
 *   Advanced : AFF, MFA, PSCRB, BRM/ERM, RADAR/ARPA, ECDIS,
 *              Tanker Advanced, BOSIET/OGUK, Food Handling, H2S
 *
 * Permissions: admin / sub_admin / staff (per matrix).
 */

require __DIR__ . '/config/app.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/helpers.php';

requireRole(['admin', 'sub_admin', 'staff']);

$user   = currentUser();
$crewId = isset($_GET['id']) && $_GET['id'] !== '' ? (int)$_GET['id'] : 0;
$crew   = $crewId > 0 ? fetchCrewWithJoins($pdo, $crewId) : null;

if (!$crew) {
    flash('error', 'Crew not found.');
    header('Location: ' . url('crew.php'));
    exit;
}

/**
 * Helper: pick the right table name based on the section param.
 * Returns null for any unknown value (so callers can safely flag it).
 */
function courseTable(?string $section): ?string
{
    return $section === 'basic'    ? 'basic_courses'
         : ($section === 'advanced' ? 'advanced_courses' : null);
}

// -------------------------------------------------------------
// POST handlers — add / update / delete (per section)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action  = $_POST['action']  ?? '';
    $section = $_POST['section'] ?? '';
    $table   = courseTable($section);
    if ($table === null) {
        flash('error', 'Unknown course section.');
        header('Location: ' . url('crew-courses.php?id=' . $crewId));
        exit;
    }

    if ($action === 'add' || $action === 'update') {
        $rowId       = (int)($_POST['course_id'] ?? 0);
        $courseName  = trim($_POST['course_name']   ?? '');
        $courseNum   = trim($_POST['course_number'] ?? '');
        $issueDate   = trim($_POST['issue_date']    ?? '');
        $expiryDate  = trim($_POST['expiry_date']   ?? '');

        $errors = [];
        if ($courseName === '')                         $errors[] = 'Course name is required.';
        if (mb_strlen($courseName) > 100)               $errors[] = 'Course name too long (max 100).';
        if (mb_strlen($courseNum)  > 100)               $errors[] = 'Course number too long (max 100).';
        if ($issueDate  !== '' && !DateTime::createFromFormat('Y-m-d', $issueDate))  $errors[] = 'Invalid issue date.';
        if ($expiryDate !== '' && !DateTime::createFromFormat('Y-m-d', $expiryDate)) $errors[] = 'Invalid expiry date.';

        $newFilePath = null;
        if (!empty($_FILES['file']['name'])) {
            try {
                $newFilePath = uploadFile('file', 'crew/' . $crewId, "course_{$section}", $crewId);
            } catch (RuntimeException $e) {
                $errors[] = 'Upload failed: ' . $e->getMessage();
            }
        }

        if (!empty($errors)) {
            foreach ($errors as $e) flash('error', $e);
            header('Location: ' . url('crew-courses.php?id=' . $crewId));
            exit;
        }

        if ($action === 'add') {
            $stmt = $pdo->prepare(
                "INSERT INTO {$table}
                    (crew_id, course_name, course_number, issue_date, expiry_date,
                     file_path, created_by, created_at, updated_at)
                 VALUES (:c, :n, :cn, :id, :ed, :fp, :u, NOW(), NOW())"
            );
            $stmt->execute([
                ':c'  => $crewId,
                ':n'  => $courseName,
                ':cn' => $courseNum  !== '' ? $courseNum  : null,
                ':id' => $issueDate  !== '' ? $issueDate  : null,
                ':ed' => $expiryDate !== '' ? $expiryDate : null,
                ':fp' => $newFilePath,
                ':u'  => $user['id'],
            ]);
            $newId = (int)$pdo->lastInsertId();
            logActivity(
                $pdo, $user['id'], $newFilePath ? 'upload' : 'create', $table, $newId,
                "Added {$section} course '{$courseName}' for crew {$crewId}"
            );
            flash('success', "Course '{$courseName}' added.");
        } else {
            $cur = $pdo->prepare("SELECT * FROM {$table} WHERE id = :i AND crew_id = :c");
            $cur->execute([':i' => $rowId, ':c' => $crewId]);
            $row = $cur->fetch();
            if (!$row) {
                flash('error', 'Course not found.');
                header('Location: ' . url('crew-courses.php?id=' . $crewId));
                exit;
            }
            $stmt = $pdo->prepare(
                "UPDATE {$table}
                    SET course_name   = :n,
                        course_number = :cn,
                        issue_date    = :id,
                        expiry_date   = :ed,
                        file_path     = :fp,
                        updated_at    = NOW()
                  WHERE id = :i AND crew_id = :c"
            );
            $stmt->execute([
                ':n'  => $courseName,
                ':cn' => $courseNum  !== '' ? $courseNum  : null,
                ':id' => $issueDate  !== '' ? $issueDate  : null,
                ':ed' => $expiryDate !== '' ? $expiryDate : null,
                ':fp' => $newFilePath ?? $row['file_path'],
                ':i'  => $rowId,
                ':c'  => $crewId,
            ]);
            logActivity(
                $pdo, $user['id'], $newFilePath ? 'upload' : 'update', $table, $rowId,
                "Updated {$section} course '{$courseName}' for crew {$crewId}"
            );
            flash('success', "Course '{$courseName}' updated.");
        }
    }

    elseif ($action === 'delete') {
        $rowId = (int)($_POST['course_id'] ?? 0);
        $cur   = $pdo->prepare("SELECT course_name, file_path FROM {$table} WHERE id = :i AND crew_id = :c");
        $cur->execute([':i' => $rowId, ':c' => $crewId]);
        $row = $cur->fetch();
        if ($row) {
            $stmt = $pdo->prepare("DELETE FROM {$table} WHERE id = :i AND crew_id = :c");
            $stmt->execute([':i' => $rowId, ':c' => $crewId]);
            if (!empty($row['file_path'])) {
                $abs = rtrim(UPLOAD_DIR, '/') . '/' . ltrim($row['file_path'], '/');
                if (is_file($abs)) @unlink($abs);
            }
            logActivity(
                $pdo, $user['id'], 'delete', $table, $rowId,
                "Deleted {$section} course '{$row['course_name']}' for crew {$crewId}"
            );
            flash('success', "Course '{$row['course_name']}' deleted.");
        }
    }

    header('Location: ' . url('crew-courses.php?id=' . $crewId));
    exit;
}

// -------------------------------------------------------------
// GET — lazy-seed defaults, then fetch both course lists
// -------------------------------------------------------------
seedDefaultCoursesForCrew($pdo, $crewId, $user['id']);

$basic = $pdo->prepare(
    "SELECT * FROM basic_courses WHERE crew_id = :c ORDER BY id"
);
$basic->execute([':c' => $crewId]);
$basic = $basic->fetchAll();

$advanced = $pdo->prepare(
    "SELECT * FROM advanced_courses WHERE crew_id = :c ORDER BY id"
);
$advanced->execute([':c' => $crewId]);
$advanced = $advanced->fetchAll();

$pageTitle  = 'Courses — ' . $crew['full_name'];
$currentTab = 'courses';
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/crew-tabs.php';

/**
 * Render a course section (basic or advanced).
 * Inline closure rather than a global function to keep $pdo etc.
 * out of the global scope and the helper file lean.
 */
$renderSection = function (string $section, array $rows, string $heading, string $defaultsHelp) use ($crewId): void {
    ?>
    <div class="card">
        <h3 class="card-title"><?= h($heading) ?> <small class="help-text">(<?= count($rows) ?>)</small></h3>
        <p class="help-text"><?= h($defaultsHelp) ?></p>

        <?php if (empty($rows)): ?>
            <p class="help-text">No courses on file yet.</p>
        <?php else: ?>
            <table class="data-table" style="margin-bottom:14px;">
                <thead>
                    <tr>
                        <th style="width:18%">Course</th>
                        <th style="width:14%">Number</th>
                        <th style="width:13%">Issue</th>
                        <th style="width:13%">Expiry</th>
                        <th style="width:11%">Status</th>
                        <th style="width:18%">File</th>
                        <th style="text-align:right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <form method="post" enctype="multipart/form-data" style="display:contents">
                                <?= csrfField() ?>
                                <input type="hidden" name="action"    value="update">
                                <input type="hidden" name="section"   value="<?= h($section) ?>">
                                <input type="hidden" name="course_id" value="<?= (int)$row['id'] ?>">
                                <td><input type="text" name="course_name"   maxlength="100" required value="<?= h($row['course_name']   ?? '') ?>"></td>
                                <td><input type="text" name="course_number" maxlength="100"          value="<?= h($row['course_number'] ?? '') ?>"></td>
                                <td><input type="date" name="issue_date"   value="<?= h($row['issue_date']  ?? '') ?>"></td>
                                <td><input type="date" name="expiry_date"  value="<?= h($row['expiry_date'] ?? '') ?>"></td>
                                <td><?= dateStatusBadge($row['expiry_date'] ?? null) ?></td>
                                <td>
                                    <?php if (!empty($row['file_path'])): ?>
                                        <a class="action-link" target="_blank"
                                           href="<?= asset('uploads/' . $row['file_path']) ?>">View</a>
                                    <?php endif; ?>
                                    <input type="file" name="file" accept=".pdf,.jpg,.jpeg,.png,.docx"
                                           style="display:block; margin-top:4px; font-size:12px;">
                                </td>
                                <td>
                                    <div class="row-actions">
                                        <button type="submit" class="btn btn-secondary btn-sm">Save</button>
                                    </div>
                            </form>
                            <form method="post" style="display:inline">
                                <?= csrfField() ?>
                                <input type="hidden" name="action"    value="delete">
                                <input type="hidden" name="section"   value="<?= h($section) ?>">
                                <input type="hidden" name="course_id" value="<?= (int)$row['id'] ?>">
                                <button type="submit" class="btn btn-danger btn-sm"
                                        data-confirm="Delete '<?= h($row['course_name']) ?>'? The file will be removed from the server.">
                                    Delete
                                </button>
                            </form>
                                </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <details>
            <summary style="cursor:pointer; font-weight:500; color:var(--primary);">
                + Add custom <?= h($section) ?> course
            </summary>
            <form method="post" enctype="multipart/form-data" style="margin-top:10px">
                <?= csrfField() ?>
                <input type="hidden" name="action"  value="add">
                <input type="hidden" name="section" value="<?= h($section) ?>">
                <div class="form-grid">
                    <div class="form-row"><label>Course name *</label> <input type="text" name="course_name"   maxlength="100" required></div>
                    <div class="form-row"><label>Course number</label> <input type="text" name="course_number" maxlength="100"></div>
                    <div class="form-row"><label>Issue date</label>    <input type="date" name="issue_date"></div>
                    <div class="form-row"><label>Expiry date</label>   <input type="date" name="expiry_date"></div>
                    <div class="form-row full-row">
                        <label>File (PDF / JPG / PNG / DOCX, max 10 MB)</label>
                        <input type="file" name="file" accept=".pdf,.jpg,.jpeg,.png,.docx">
                    </div>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn">Add course</button>
                    <button type="reset" class="btn btn-secondary">Reset</button>
                </div>
            </form>
        </details>
    </div>
    <?php
};

$renderSection(
    'basic',
    $basic,
    'Basic STCW Courses',
    'Defaults: ' . implode(', ', defaultBasicCourseNames())
        . '. Add custom rows below for any extra basic certificate.'
);

$renderSection(
    'advanced',
    $advanced,
    'Advanced Courses',
    'Defaults: ' . implode(', ', defaultAdvancedCourseNames())
        . '. Add custom rows below for any extra advanced training.'
);
?>

<?php include __DIR__ . '/includes/footer.php'; ?>
