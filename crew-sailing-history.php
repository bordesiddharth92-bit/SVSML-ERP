<?php
/**
 * SVSML-ERP — Crew sailing history
 *
 * Module 6.
 *
 * Manages the sailing_history rows for a single crew member. Each row is
 * a snapshot of one tour:
 *   - identity at that time : indos_number, cdc_number, seafarer_name,
 *                             passport_number
 *   - assignment            : rank_id, vessel_id, company_id
 *   - dates                 : sign_on_date, sign_off_date,
 *                             days_on_vessel (computed inclusive)
 *   - joiner_type           : new_joiner | rejoiner
 *
 * Module 6 ships the manual CRUD. Auto-population from sign-on / off
 * completion lands in Module 7 (which will INSERT into this table when
 * a sign-off is recorded).
 *
 * On add, identity fields default to the current crew row's values; the
 * user can override them to record what they actually were at the time
 * of that tour (e.g. an old CDC number).
 *
 * days_on_vessel is auto-computed when both dates are set, but the
 * user can also override it manually.
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

// -------------------------------------------------------------
// POST handlers — add / update / delete
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'update') {
        $rowId          = (int)($_POST['hist_id'] ?? 0);
        $indos          = trim($_POST['indos_number']    ?? '');
        $cdc            = trim($_POST['cdc_number']      ?? '');
        $seafarer       = trim($_POST['seafarer_name']   ?? '');
        $passport       = trim($_POST['passport_number'] ?? '');
        $rankId         = !empty($_POST['rank_id'])    ? (int)$_POST['rank_id']    : null;
        $vesselId       = !empty($_POST['vessel_id'])  ? (int)$_POST['vessel_id']  : null;
        $companyId      = !empty($_POST['company_id']) ? (int)$_POST['company_id'] : null;
        $signOn         = trim($_POST['sign_on_date']  ?? '');
        $signOff        = trim($_POST['sign_off_date'] ?? '');
        $daysManual     = trim($_POST['days_on_vessel'] ?? '');
        $joinerType     = ($_POST['joiner_type'] ?? 'new_joiner') === 'rejoiner' ? 'rejoiner' : 'new_joiner';

        $errors = [];
        if ($seafarer === '')                                                       $errors[] = 'Seafarer name is required.';
        if ($signOn  === '')                                                        $errors[] = 'Sign-on date is required.';
        if ($signOn  !== '' && !DateTime::createFromFormat('Y-m-d', $signOn))       $errors[] = 'Invalid sign-on date.';
        if ($signOff !== '' && !DateTime::createFromFormat('Y-m-d', $signOff))      $errors[] = 'Invalid sign-off date.';
        if ($signOn !== '' && $signOff !== '' && strcmp($signOff, $signOn) < 0)     $errors[] = 'Sign-off date must be on or after sign-on date.';
        if ($daysManual !== '' && (!ctype_digit($daysManual) || (int)$daysManual < 0)) $errors[] = 'Days must be a non-negative integer.';

        if (!empty($errors)) {
            foreach ($errors as $e) flash('error', $e);
            header('Location: ' . url('crew-sailing-history.php?id=' . $crewId));
            exit;
        }

        // Auto-compute days when not manually set and both dates are present.
        $days = ($daysManual !== '')
            ? (int)$daysManual
            : daysBetween($signOn !== '' ? $signOn : null, $signOff !== '' ? $signOff : null);

        $params = [
            ':c'   => $crewId,
            ':in'  => $indos    !== '' ? $indos    : null,
            ':cdc' => $cdc      !== '' ? $cdc      : null,
            ':sn'  => $seafarer,
            ':pp'  => $passport !== '' ? $passport : null,
            ':rk'  => $rankId,
            ':ve'  => $vesselId,
            ':co'  => $companyId,
            ':so'  => $signOn,
            ':sf'  => $signOff !== '' ? $signOff : null,
            ':dv'  => $days,
            ':jt'  => $joinerType,
            ':u'   => $user['id'],
        ];

        if ($action === 'add') {
            $stmt = $pdo->prepare(
                "INSERT INTO sailing_history
                    (crew_id, indos_number, cdc_number, seafarer_name, passport_number,
                     rank_id, vessel_id, company_id,
                     sign_on_date, sign_off_date, days_on_vessel, joiner_type,
                     created_by, created_at)
                 VALUES
                    (:c, :in, :cdc, :sn, :pp, :rk, :ve, :co,
                     :so, :sf, :dv, :jt, :u, NOW())"
            );
            $stmt->execute($params);
            $newId = (int)$pdo->lastInsertId();
            logActivity(
                $pdo, $user['id'], 'create', 'sailing_history', $newId,
                "Added sailing history (sign-on {$signOn}) for crew {$crewId}"
            );
            flash('success', 'Sailing history added.');
        } else {
            $params[':i'] = $rowId;
            $stmt = $pdo->prepare(
                "UPDATE sailing_history
                    SET indos_number    = :in,
                        cdc_number      = :cdc,
                        seafarer_name   = :sn,
                        passport_number = :pp,
                        rank_id         = :rk,
                        vessel_id       = :ve,
                        company_id      = :co,
                        sign_on_date    = :so,
                        sign_off_date   = :sf,
                        days_on_vessel  = :dv,
                        joiner_type     = :jt
                  WHERE id = :i AND crew_id = :c"
            );
            $stmt->execute($params);
            logActivity(
                $pdo, $user['id'], 'update', 'sailing_history', $rowId,
                "Updated sailing history {$rowId} for crew {$crewId}"
            );
            flash('success', 'Sailing history updated.');
        }
    }

    elseif ($action === 'delete') {
        $rowId = (int)($_POST['hist_id'] ?? 0);
        $cur   = $pdo->prepare(
            "SELECT sign_on_date FROM sailing_history WHERE id = :i AND crew_id = :c"
        );
        $cur->execute([':i' => $rowId, ':c' => $crewId]);
        if ($cur->fetch()) {
            $stmt = $pdo->prepare(
                "DELETE FROM sailing_history WHERE id = :i AND crew_id = :c"
            );
            $stmt->execute([':i' => $rowId, ':c' => $crewId]);
            logActivity(
                $pdo, $user['id'], 'delete', 'sailing_history', $rowId,
                "Deleted sailing history {$rowId} for crew {$crewId}"
            );
            flash('success', 'Sailing history deleted.');
        }
    }

    header('Location: ' . url('crew-sailing-history.php?id=' . $crewId));
    exit;
}

// -------------------------------------------------------------
// GET — load all sailing history rows + dropdown sources
// -------------------------------------------------------------
$rows = $pdo->prepare(
    "SELECT sh.*,
            r.rank_name,
            v.vessel_name,
            co.company_name
       FROM sailing_history sh
       LEFT JOIN ranks     r  ON r.id  = sh.rank_id
       LEFT JOIN vessels   v  ON v.id  = sh.vessel_id
       LEFT JOIN companies co ON co.id = sh.company_id
      WHERE sh.crew_id = :c
      ORDER BY sh.sign_on_date DESC, sh.id DESC"
);
$rows->execute([':c' => $crewId]);
$rows = $rows->fetchAll();

// Aggregates for the summary card
$totalDays   = 0;
$tourCount   = count($rows);
$lastSignOff = null;
foreach ($rows as $r) {
    $totalDays += (int)($r['days_on_vessel'] ?? 0);
    if (!empty($r['sign_off_date']) && ($lastSignOff === null || strcmp($r['sign_off_date'], $lastSignOff) > 0)) {
        $lastSignOff = $r['sign_off_date'];
    }
}

$ranks     = $pdo->query("SELECT id, rank_name    FROM ranks     ORDER BY rank_name")->fetchAll();
$companies = $pdo->query("SELECT id, company_name FROM companies ORDER BY company_name")->fetchAll();
$vessels   = $pdo->query(
    "SELECT v.id, v.vessel_name, c.company_name
       FROM vessels v
       LEFT JOIN companies c ON c.id = v.company_id
      ORDER BY v.vessel_name"
)->fetchAll();

$pageTitle  = 'Sailing history — ' . $crew['full_name'];
$currentTab = 'sailing';
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/crew-tabs.php';
?>

<div class="card">
    <h3 class="card-title">Summary</h3>
    <div class="kpi-grid">
        <div class="kpi">
            <div class="kpi-label">Tours recorded</div>
            <div class="kpi-value"><?= (int)$tourCount ?></div>
        </div>
        <div class="kpi">
            <div class="kpi-label">Total days at sea</div>
            <div class="kpi-value"><?= (int)$totalDays ?></div>
        </div>
        <div class="kpi">
            <div class="kpi-label">Last sign-off</div>
            <div class="kpi-value" style="font-size:16px;"><?= $lastSignOff ? h($lastSignOff) : '—' ?></div>
        </div>
        <div class="kpi">
            <div class="kpi-label">Default joiner type</div>
            <div class="kpi-value" style="font-size:16px;">
                <?= $tourCount > 0 ? 'Rejoiner' : 'New joiner' ?>
            </div>
        </div>
    </div>
    <p class="help-text">
        Auto-population from sign-on / off events lands in Module 7. Until then,
        sailing history is recorded manually here.
    </p>
</div>

<div class="card">
    <h3 class="card-title">Sailing history (<?= (int)$tourCount ?>)</h3>

    <?php if (empty($rows)): ?>
        <div class="empty-state">
            <h3>No sailing history yet</h3>
            <p>Add the first tour below.</p>
        </div>
    <?php else: ?>
        <table class="data-table" style="margin-bottom:14px;">
            <thead>
                <tr>
                    <th>Sign on</th>
                    <th>Sign off</th>
                    <th>Days</th>
                    <th>Rank</th>
                    <th>Vessel / Company</th>
                    <th>Joiner</th>
                    <th style="text-align:right">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><strong><?= h($row['sign_on_date']) ?></strong></td>
                        <td><?= h($row['sign_off_date'] ?? '—') ?></td>
                        <td><?= h((string)($row['days_on_vessel'] ?? '—')) ?></td>
                        <td><?= h($row['rank_name'] ?? '—') ?></td>
                        <td>
                            <?= h($row['vessel_name'] ?? '—') ?>
                            <?php if (!empty($row['company_name'])): ?>
                                <span class="help-text">/ <?= h($row['company_name']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($row['joiner_type'] === 'rejoiner'): ?>
                                <span class="status status-green">Rejoiner</span>
                            <?php else: ?>
                                <span class="status status-gray">New joiner</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="row-actions">
                                <a class="btn btn-secondary btn-sm"
                                   href="<?= asset('crew-sailing-history.php?id=' . $crewId . '#hist-' . (int)$row['id']) ?>">
                                    Edit
                                </a>
                                <form method="post" style="display:inline">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action"  value="delete">
                                    <input type="hidden" name="hist_id" value="<?= (int)$row['id'] ?>">
                                    <button type="submit" class="btn btn-danger btn-sm"
                                            data-confirm="Delete this sailing history row?">
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
                Inline edit existing tours
            </summary>
            <?php foreach ($rows as $row): ?>
                <form method="post" id="hist-<?= (int)$row['id'] ?>"
                      style="border-top:1px solid var(--border); padding-top:14px; margin-top:14px;">
                    <?= csrfField() ?>
                    <input type="hidden" name="action"  value="update">
                    <input type="hidden" name="hist_id" value="<?= (int)$row['id'] ?>">
                    <div class="form-grid">
                        <div class="form-row"><label>Seafarer name</label>
                            <input type="text" name="seafarer_name" required value="<?= h($row['seafarer_name']) ?>"></div>
                        <div class="form-row"><label>Passport number</label>
                            <input type="text" name="passport_number" value="<?= h($row['passport_number'] ?? '') ?>"></div>
                        <div class="form-row"><label>INDOS number</label>
                            <input type="text" name="indos_number" value="<?= h($row['indos_number'] ?? '') ?>"></div>
                        <div class="form-row"><label>CDC number</label>
                            <input type="text" name="cdc_number" value="<?= h($row['cdc_number'] ?? '') ?>"></div>
                        <div class="form-row"><label>Sign-on date *</label>
                            <input type="date" name="sign_on_date" required value="<?= h($row['sign_on_date']) ?>"></div>
                        <div class="form-row"><label>Sign-off date</label>
                            <input type="date" name="sign_off_date" value="<?= h($row['sign_off_date'] ?? '') ?>"></div>
                        <div class="form-row"><label>Days on vessel</label>
                            <input type="number" name="days_on_vessel" min="0" value="<?= h((string)($row['days_on_vessel'] ?? '')) ?>">
                            <p class="help-text">Auto-calculated from dates if left blank.</p></div>
                        <div class="form-row"><label>Joiner type</label>
                            <select name="joiner_type">
                                <option value="new_joiner" <?= $row['joiner_type']==='new_joiner' ? 'selected' : '' ?>>New joiner</option>
                                <option value="rejoiner"   <?= $row['joiner_type']==='rejoiner'   ? 'selected' : '' ?>>Rejoiner</option>
                            </select></div>
                        <div class="form-row"><label>Rank</label>
                            <select name="rank_id">
                                <option value="">— None —</option>
                                <?php foreach ($ranks as $r): ?>
                                    <option value="<?= (int)$r['id'] ?>" <?= ((string)$row['rank_id']===(string)$r['id'])?'selected':'' ?>>
                                        <?= h($r['rank_name']) ?></option>
                                <?php endforeach; ?>
                            </select></div>
                        <div class="form-row"><label>Vessel</label>
                            <select name="vessel_id">
                                <option value="">— None —</option>
                                <?php foreach ($vessels as $v): ?>
                                    <option value="<?= (int)$v['id'] ?>" <?= ((string)$row['vessel_id']===(string)$v['id'])?'selected':'' ?>>
                                        <?= h($v['vessel_name']) ?><?= $v['company_name'] ? ' — ' . h($v['company_name']) : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select></div>
                        <div class="form-row"><label>Company</label>
                            <select name="company_id">
                                <option value="">— None —</option>
                                <?php foreach ($companies as $co): ?>
                                    <option value="<?= (int)$co['id'] ?>" <?= ((string)$row['company_id']===(string)$co['id'])?'selected':'' ?>>
                                        <?= h($co['company_name']) ?></option>
                                <?php endforeach; ?>
                            </select></div>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn">Save changes</button>
                    </div>
                </form>
            <?php endforeach; ?>
        </details>
    <?php endif; ?>
</div>

<div class="card">
    <h3 class="card-title">Add a new tour</h3>
    <form method="post" novalidate>
        <?= csrfField() ?>
        <input type="hidden" name="action" value="add">
        <div class="form-grid">
            <div class="form-row"><label>Seafarer name *</label>
                <input type="text" name="seafarer_name" required value="<?= h($crew['full_name']) ?>"></div>
            <div class="form-row"><label>Passport number</label>
                <input type="text" name="passport_number" value="<?= h($crew['passport_number'] ?? '') ?>"></div>
            <div class="form-row"><label>INDOS number</label>
                <input type="text" name="indos_number" value="<?= h($crew['indos_number'] ?? '') ?>"></div>
            <div class="form-row"><label>CDC number</label>
                <input type="text" name="cdc_number"></div>
            <div class="form-row"><label>Sign-on date *</label>
                <input type="date" name="sign_on_date" required></div>
            <div class="form-row"><label>Sign-off date</label>
                <input type="date" name="sign_off_date"></div>
            <div class="form-row"><label>Days on vessel</label>
                <input type="number" name="days_on_vessel" min="0">
                <p class="help-text">Auto-calculated from dates if left blank.</p></div>
            <div class="form-row"><label>Joiner type</label>
                <select name="joiner_type">
                    <option value="new_joiner" <?= $tourCount === 0 ? 'selected' : '' ?>>New joiner</option>
                    <option value="rejoiner"   <?= $tourCount  >  0 ? 'selected' : '' ?>>Rejoiner</option>
                </select></div>
            <div class="form-row"><label>Rank</label>
                <select name="rank_id">
                    <option value="">— None —</option>
                    <?php foreach ($ranks as $r): ?>
                        <option value="<?= (int)$r['id'] ?>" <?= ((string)$crew['rank_id']===(string)$r['id'])?'selected':'' ?>>
                            <?= h($r['rank_name']) ?></option>
                    <?php endforeach; ?>
                </select></div>
            <div class="form-row"><label>Vessel</label>
                <select name="vessel_id">
                    <option value="">— None —</option>
                    <?php foreach ($vessels as $v): ?>
                        <option value="<?= (int)$v['id'] ?>" <?= ((string)$crew['vessel_id']===(string)$v['id'])?'selected':'' ?>>
                            <?= h($v['vessel_name']) ?><?= $v['company_name'] ? ' — ' . h($v['company_name']) : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select></div>
            <div class="form-row"><label>Company</label>
                <select name="company_id">
                    <option value="">— None —</option>
                    <?php foreach ($companies as $co): ?>
                        <option value="<?= (int)$co['id'] ?>" <?= ((string)$crew['company_id']===(string)$co['id'])?'selected':'' ?>>
                            <?= h($co['company_name']) ?></option>
                    <?php endforeach; ?>
                </select></div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn">Add tour</button>
            <button type="reset"  class="btn btn-secondary">Reset</button>
        </div>
    </form>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
