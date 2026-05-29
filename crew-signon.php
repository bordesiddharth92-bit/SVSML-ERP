<?php
/**
 * SVSML-ERP — Crew sign on / sign off
 *
 * Module 7.
 *
 * Manages sign_on_off rows for one crew member.
 *
 * The lifecycle is:
 *   1. "Sign on" — operator records the sign-on date and (optionally)
 *      uploads the sign-on CDC page. A new row is created with
 *      sign_off_date = NULL.
 *   2. "Sign off" — when the crew comes off the vessel, the operator
 *      records the sign-off date on the same row. We compute days_on_board
 *      automatically from the two dates.
 *      On sign-off we also:
 *        - If system_settings.auto_joiner_detection is on: flip
 *          crew.joiner_type to 'rejoiner' (because they now have history).
 *        - Auto-create a sailing_history row snapshotting the crew's
 *          current identity + assignment + the dates from this tour.
 *          (Module 6 had to record these manually; Module 7 closes that
 *          loop so completing a tour writes the history automatically.)
 *
 * Permissions: admin / sub_admin / staff per the matrix. Crew users will
 * be able to upload their CDC page from their own profile in Module 16.
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

$alerts = getAlertSettings($pdo);
$sys    = getSystemSettings($pdo);

/**
 * Snapshot the current crew row into sailing_history at the close of a tour.
 * Called automatically when a sign-off date is recorded.
 */
function snapshotSailingHistory(PDO $pdo, array $crew, array $event, ?int $userId): int
{
    $days = daysBetween($event['sign_on_date'] ?? null, $event['sign_off_date'] ?? null);
    $stmt = $pdo->prepare(
        "INSERT INTO sailing_history
            (crew_id, indos_number, cdc_number, seafarer_name, passport_number,
             rank_id, vessel_id, company_id,
             sign_on_date, sign_off_date, days_on_vessel, joiner_type, created_by)
         VALUES (:c, :in, :cdc, :sn, :pp, :rk, :ve, :co,
                 :so, :sf, :dv, :jt, :u)"
    );
    $stmt->execute([
        ':c'   => (int)$crew['id'],
        ':in'  => $crew['indos_number'] ?? null,
        ':cdc' => null, // cdc_number not on the crew row; operator can edit later
        ':sn'  => $crew['full_name'],
        ':pp'  => $crew['passport_number'] ?? null,
        ':rk'  => $crew['rank_id'] ?? null,
        ':ve'  => $crew['vessel_id'] ?? null,
        ':co'  => $crew['company_id'] ?? null,
        ':so'  => $event['sign_on_date'],
        ':sf'  => $event['sign_off_date'],
        ':dv'  => $days,
        ':jt'  => $crew['joiner_type'] ?? 'new_joiner',
        ':u'   => $userId,
    ]);
    return (int)$pdo->lastInsertId();
}

// -------------------------------------------------------------
// POST handlers
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    /* -------- Sign on (create a new event) -------- */
    if ($action === 'sign_on') {
        $signOnDate = trim($_POST['sign_on_date'] ?? '');
        $errors = [];
        if ($signOnDate === '')                                                $errors[] = 'Sign-on date is required.';
        if ($signOnDate !== '' && !DateTime::createFromFormat('Y-m-d', $signOnDate)) $errors[] = 'Invalid sign-on date.';

        $cdcPath = null;
        if (empty($errors) && !empty($_FILES['cdc_file']['name'])) {
            try {
                $cdcPath = uploadFile('cdc_file', 'crew/' . $crewId, 'signon_cdc', $crewId);
            } catch (RuntimeException $e) {
                $errors[] = 'CDC upload failed: ' . $e->getMessage();
            }
        }

        if (!empty($errors)) {
            foreach ($errors as $e) flash('error', $e);
        } else {
            $stmt = $pdo->prepare(
                "INSERT INTO sign_on_off
                    (crew_id, sign_on_date, sign_on_cdc_path, created_by)
                 VALUES (:c, :so, :path, :u)"
            );
            $stmt->execute([
                ':c'    => $crewId,
                ':so'   => $signOnDate,
                ':path' => $cdcPath,
                ':u'    => $user['id'],
            ]);
            $newId = (int)$pdo->lastInsertId();
            logActivity(
                $pdo, $user['id'], $cdcPath ? 'upload' : 'create', 'sign_on_off', $newId,
                "Sign-on {$signOnDate} for crew {$crewId}"
            );
            flash('success', 'Sign-on recorded.');
        }
    }

    /* -------- Sign off (close an existing event) -------- */
    elseif ($action === 'sign_off') {
        $rowId       = (int)($_POST['event_id'] ?? 0);
        $signOffDate = trim($_POST['sign_off_date'] ?? '');

        $errors = [];
        if ($signOffDate === '')                                                  $errors[] = 'Sign-off date is required.';
        if ($signOffDate !== '' && !DateTime::createFromFormat('Y-m-d', $signOffDate)) $errors[] = 'Invalid sign-off date.';

        // Look up the row so we can compute days_on_board and validate ordering.
        $cur = $pdo->prepare("SELECT * FROM sign_on_off WHERE id = :i AND crew_id = :c");
        $cur->execute([':i' => $rowId, ':c' => $crewId]);
        $row = $cur->fetch();
        if (!$row) $errors[] = 'Sign-on event not found.';
        if ($row && !empty($row['sign_off_date'])) $errors[] = 'This tour is already closed.';
        if (empty($errors) && strcmp($signOffDate, $row['sign_on_date']) < 0) {
            $errors[] = 'Sign-off date must be on or after sign-on date.';
        }

        $cdcPath = null;
        if (empty($errors) && !empty($_FILES['cdc_file']['name'])) {
            try {
                $cdcPath = uploadFile('cdc_file', 'crew/' . $crewId, 'signoff_cdc', $crewId);
            } catch (RuntimeException $e) {
                $errors[] = 'CDC upload failed: ' . $e->getMessage();
            }
        }

        if (!empty($errors)) {
            foreach ($errors as $e) flash('error', $e);
        } else {
            $days = daysBetween($row['sign_on_date'], $signOffDate);
            $stmt = $pdo->prepare(
                "UPDATE sign_on_off
                    SET sign_off_date     = :sf,
                        sign_off_cdc_path = :path,
                        days_on_board     = :dv
                  WHERE id = :i AND crew_id = :c"
            );
            $stmt->execute([
                ':sf'   => $signOffDate,
                ':path' => $cdcPath ?? $row['sign_off_cdc_path'],
                ':dv'   => $days,
                ':i'    => $rowId,
                ':c'    => $crewId,
            ]);
            $row['sign_off_date'] = $signOffDate;
            logActivity(
                $pdo, $user['id'], $cdcPath ? 'upload' : 'update', 'sign_on_off', $rowId,
                "Sign-off {$signOffDate} ({$days} days) for crew {$crewId}"
            );

            // Auto-snapshot to sailing_history for the closed tour.
            $histId = snapshotSailingHistory($pdo, $crew, $row, $user['id']);
            logActivity(
                $pdo, $user['id'], 'create', 'sailing_history', $histId,
                "Auto-snapshot from sign-on/off {$rowId}"
            );

            // Auto joiner-type: any closed tour means the crew is a 'rejoiner'.
            if (!empty($sys['auto_joiner_detection']) && $crew['joiner_type'] !== 'rejoiner') {
                $pdo->prepare("UPDATE crew SET joiner_type = 'rejoiner' WHERE id = :i")
                    ->execute([':i' => $crewId]);
                logActivity(
                    $pdo, $user['id'], 'update', 'crew', $crewId,
                    'Auto-set joiner_type=rejoiner after sign-off'
                );
            }

            flash('success', "Sign-off recorded. {$days} days on board. Sailing history snapshot created.");
        }
    }

    /* -------- Update existing event (dates / file) -------- */
    elseif ($action === 'update') {
        $rowId       = (int)($_POST['event_id'] ?? 0);
        $signOnDate  = trim($_POST['sign_on_date']  ?? '');
        $signOffDate = trim($_POST['sign_off_date'] ?? '');

        $errors = [];
        if ($signOnDate === '')                                              $errors[] = 'Sign-on date is required.';
        if (!DateTime::createFromFormat('Y-m-d', $signOnDate))               $errors[] = 'Invalid sign-on date.';
        if ($signOffDate !== '' && !DateTime::createFromFormat('Y-m-d', $signOffDate)) $errors[] = 'Invalid sign-off date.';
        if ($signOffDate !== '' && strcmp($signOffDate, $signOnDate) < 0)    $errors[] = 'Sign-off date must be on or after sign-on date.';

        $cur = $pdo->prepare("SELECT * FROM sign_on_off WHERE id = :i AND crew_id = :c");
        $cur->execute([':i' => $rowId, ':c' => $crewId]);
        $row = $cur->fetch();
        if (!$row) $errors[] = 'Sign-on event not found.';

        if (!empty($errors)) {
            foreach ($errors as $e) flash('error', $e);
        } else {
            $days = $signOffDate !== '' ? daysBetween($signOnDate, $signOffDate) : null;
            $stmt = $pdo->prepare(
                "UPDATE sign_on_off
                    SET sign_on_date  = :so,
                        sign_off_date = :sf,
                        days_on_board = :dv
                  WHERE id = :i AND crew_id = :c"
            );
            $stmt->execute([
                ':so' => $signOnDate,
                ':sf' => $signOffDate !== '' ? $signOffDate : null,
                ':dv' => $days,
                ':i'  => $rowId,
                ':c'  => $crewId,
            ]);
            logActivity(
                $pdo, $user['id'], 'update', 'sign_on_off', $rowId,
                "Updated sign-on/off {$rowId} for crew {$crewId}"
            );
            flash('success', 'Event updated.');
        }
    }

    /* -------- Delete event -------- */
    elseif ($action === 'delete') {
        $rowId = (int)($_POST['event_id'] ?? 0);
        $cur   = $pdo->prepare("SELECT sign_on_cdc_path, sign_off_cdc_path FROM sign_on_off WHERE id = :i AND crew_id = :c");
        $cur->execute([':i' => $rowId, ':c' => $crewId]);
        $row = $cur->fetch();
        if ($row) {
            $stmt = $pdo->prepare("DELETE FROM sign_on_off WHERE id = :i AND crew_id = :c");
            $stmt->execute([':i' => $rowId, ':c' => $crewId]);
            foreach (['sign_on_cdc_path', 'sign_off_cdc_path'] as $col) {
                if (!empty($row[$col])) {
                    $abs = rtrim(UPLOAD_DIR, '/') . '/' . ltrim($row[$col], '/');
                    if (is_file($abs)) @unlink($abs);
                }
            }
            logActivity(
                $pdo, $user['id'], 'delete', 'sign_on_off', $rowId,
                "Deleted sign-on/off {$rowId} for crew {$crewId}"
            );
            flash('success', 'Event deleted. Note: any sailing_history snapshot for this tour was kept.');
        }
    }

    header('Location: ' . url('crew-signon.php?id=' . $crewId));
    exit;
}

// -------------------------------------------------------------
// GET — load all events for this crew
// -------------------------------------------------------------
$events = $pdo->prepare(
    "SELECT * FROM sign_on_off WHERE crew_id = :c ORDER BY sign_on_date DESC, id DESC"
);
$events->execute([':c' => $crewId]);
$events = $events->fetchAll();

$openEvent = null;
foreach ($events as $e) {
    if (empty($e['sign_off_date'])) { $openEvent = $e; break; }
}

$pageTitle  = 'Sign on / off — ' . $crew['full_name'];
$currentTab = 'signon';
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/crew-tabs.php';
?>

<div class="card">
    <h3 class="card-title">Current status</h3>
    <?php if ($openEvent): ?>
        <?php $st = signOnDurationStatus($openEvent['sign_on_date'], null, $alerts); ?>
        <div class="kpi-grid">
            <div class="kpi">
                <div class="kpi-label">Status</div>
                <div class="kpi-value">
                    <span class="status status-<?= h($st['class']) ?>"><?= h($st['label']) ?></span>
                </div>
            </div>
            <div class="kpi">
                <div class="kpi-label">Sign on</div>
                <div class="kpi-value" style="font-size:16px;"><?= h($openEvent['sign_on_date']) ?></div>
            </div>
            <div class="kpi">
                <div class="kpi-label">CDC</div>
                <div class="kpi-value" style="font-size:14px;">
                    <?php if (!empty($openEvent['sign_on_cdc_path'])): ?>
                        <a target="_blank" href="<?= asset('uploads/' . $openEvent['sign_on_cdc_path']) ?>">View</a>
                    <?php else: ?>
                        <span class="help-text">— missing —</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="kpi">
                <div class="kpi-label">Vessel</div>
                <div class="kpi-value" style="font-size:14px;"><?= h($crew['vessel_name'] ?? '—') ?></div>
            </div>
        </div>

        <div class="section-title">Close this tour (Sign off)</div>
        <form method="post" enctype="multipart/form-data" novalidate>
            <?= csrfField() ?>
            <input type="hidden" name="action"   value="sign_off">
            <input type="hidden" name="event_id" value="<?= (int)$openEvent['id'] ?>">
            <div class="form-grid">
                <div class="form-row">
                    <label for="sign_off_date">Sign-off date *</label>
                    <input type="date" id="sign_off_date" name="sign_off_date" required>
                </div>
                <div class="form-row">
                    <label for="cdc_file">Sign-off CDC (optional)</label>
                    <input type="file" id="cdc_file" name="cdc_file" accept=".pdf,.jpg,.jpeg,.png,.docx">
                </div>
            </div>
            <p class="help-text">
                On sign-off we will: compute days on board, snapshot this tour to sailing history,
                and (if auto joiner detection is on) flip the crew to <em>rejoiner</em>.
            </p>
            <div class="form-actions">
                <button type="submit" class="btn">Record sign-off</button>
            </div>
        </form>
    <?php else: ?>
        <p class="help-text"><strong>Not currently onboard.</strong> Use the form below to record a new sign-on.</p>

        <div class="section-title">Record new sign-on</div>
        <form method="post" enctype="multipart/form-data" novalidate>
            <?= csrfField() ?>
            <input type="hidden" name="action" value="sign_on">
            <div class="form-grid">
                <div class="form-row">
                    <label for="sign_on_date">Sign-on date *</label>
                    <input type="date" id="sign_on_date" name="sign_on_date" required value="<?= h(date('Y-m-d')) ?>">
                </div>
                <div class="form-row">
                    <label for="cdc_file">Sign-on CDC (optional)</label>
                    <input type="file" id="cdc_file" name="cdc_file" accept=".pdf,.jpg,.jpeg,.png,.docx">
                </div>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn">Record sign-on</button>
                <button type="reset"  class="btn btn-secondary">Reset</button>
            </div>
        </form>
    <?php endif; ?>
</div>

<div class="card">
    <h3 class="card-title">All sign-on / off events <small class="help-text">(<?= count($events) ?>)</small></h3>

    <?php if (empty($events)): ?>
        <div class="empty-state">
            <h3>No events yet</h3>
            <p>Record the first sign-on above.</p>
        </div>
    <?php else: ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Sign on</th>
                    <th>Sign off</th>
                    <th>Days</th>
                    <th>Status</th>
                    <th>CDC files</th>
                    <th style="text-align:right">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($events as $ev): ?>
                    <?php $st = signOnDurationStatus($ev['sign_on_date'], $ev['sign_off_date'], $alerts); ?>
                    <tr>
                        <td><strong><?= h($ev['sign_on_date']) ?></strong></td>
                        <td><?= h($ev['sign_off_date'] ?? '—') ?></td>
                        <td><?= h((string)($ev['days_on_board'] ?? '—')) ?></td>
                        <td><span class="status status-<?= h($st['class']) ?>"><?= h($st['label']) ?></span></td>
                        <td>
                            <?php if (!empty($ev['sign_on_cdc_path'])): ?>
                                <a class="action-link" target="_blank" href="<?= asset('uploads/' . $ev['sign_on_cdc_path']) ?>">On</a>
                            <?php endif; ?>
                            <?php if (!empty($ev['sign_off_cdc_path'])): ?>
                                <a class="action-link" target="_blank" href="<?= asset('uploads/' . $ev['sign_off_cdc_path']) ?>">Off</a>
                            <?php endif; ?>
                            <?php if (empty($ev['sign_on_cdc_path']) && empty($ev['sign_off_cdc_path'])): ?>
                                <span class="help-text">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="row-actions">
                                <a class="btn btn-secondary btn-sm" href="<?= asset('crew-signon.php?id=' . $crewId . '#evt-' . (int)$ev['id']) ?>">Edit</a>
                                <form method="post" style="display:inline">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action"   value="delete">
                                    <input type="hidden" name="event_id" value="<?= (int)$ev['id'] ?>">
                                    <button type="submit" class="btn btn-danger btn-sm"
                                            data-confirm="Delete this sign-on/off event? Linked CDC files will be removed.">
                                        Delete
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <details>
            <summary style="cursor:pointer; font-weight:500; color:var(--primary);">
                Inline edit existing events
            </summary>
            <?php foreach ($events as $ev): ?>
                <form method="post" id="evt-<?= (int)$ev['id'] ?>"
                      style="border-top:1px solid var(--border); padding-top:14px; margin-top:14px;">
                    <?= csrfField() ?>
                    <input type="hidden" name="action"   value="update">
                    <input type="hidden" name="event_id" value="<?= (int)$ev['id'] ?>">
                    <div class="form-grid">
                        <div class="form-row">
                            <label>Sign-on date *</label>
                            <input type="date" name="sign_on_date" required value="<?= h($ev['sign_on_date']) ?>">
                        </div>
                        <div class="form-row">
                            <label>Sign-off date</label>
                            <input type="date" name="sign_off_date" value="<?= h($ev['sign_off_date'] ?? '') ?>">
                        </div>
                    </div>
                    <p class="help-text">Days are recomputed from the dates. To attach or replace a CDC file, delete this row and re-record.</p>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-secondary btn-sm">Save</button>
                    </div>
                </form>
            <?php endforeach; ?>
        </details>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
