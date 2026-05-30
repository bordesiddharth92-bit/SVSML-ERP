<?php
/**
 * SVSML-ERP — Crew travel details (Module 9 redesign)
 *
 * Sheet-style editor for travel_details rows. Each row has the same
 * eight columns (SR NO / DETAILS / DEPARTURE / ARRIVAL / DONE /
 * FINAL STATUS / REMARKS / ACTION) but the contents of the DEPARTURE
 * and ARRIVAL cells switch on the row type:
 *
 *   Flight Ticket (Domestic)        — Indian airport + date + time on both sides
 *   Flight Ticket (International)   — Indian airport (dep) → International airport (arr) + date + time
 *   Airport Name (International)    — international airport on both sides
 *   Visa Country                    — country dropdown (departure only)
 *   Visa Type                       — VISIT/TRANSIT/WORK/STUDENT/OTHER (departure only)
 *   OKTB (OK To Board)              — YES/NO/PENDING + free-text detail (departure only)
 *   LG (Landing Permission)         — YES/NO/PENDING + free-text detail (departure only)
 *   custom                          — free text on both sides, free-text label
 *
 * Storage: the existing travel_details.departure / .arrival VARCHAR(100)
 * columns are reused. Structured rows store JSON, custom rows store
 * plain text. travelFieldEncode/Decode in helpers.php handle both.
 *
 * Top toolbar:
 *   - SAVE CHANGES     — bulk save every visible row
 *   - VALIDATE RECORDS — auto-set final_status = 'valid' on every row
 *                        whose data is present and 'is_done' is ticked,
 *                        leave others untouched
 *   - REFRESH          — reload (drops unsaved client-side edits)
 *   - CLEAR            — JS form.reset() (drops unsaved client-side edits)
 *
 * Permissions: admin / sub_admin / staff (per matrix). Crew users use
 * crew-portal.php to view their travel.
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
 * Read a row-side (departure / arrival) from the bulk POST payload and
 * encode it for storage. Picks fields based on the row type so that
 * data from a hidden side (e.g. arrival on a Visa row) doesn't pollute
 * what we store.
 */
function readSidePayload(array $rowPost, string $rowType, string $side): ?string
{
    $get = function (string $key) use ($rowPost, $side) {
        $combined = $side . '_' . $key;
        return isset($rowPost[$combined]) ? trim((string)$rowPost[$combined]) : '';
    };

    switch ($rowType) {
        case 'flight_domestic':
        case 'flight_international':
            return travelFieldEncode([
                'airport' => $get('airport'),
                'date'    => $get('date'),
                'time'    => $get('time'),
            ]);
        case 'airport_intl':
            return travelFieldEncode(['airport' => $get('airport')]);
        case 'visa_country':
            if ($side !== 'departure') return null;
            return travelFieldEncode(['country' => $get('country')]);
        case 'visa_type':
            if ($side !== 'departure') return null;
            $vt = strtoupper($get('visa_type'));
            if ($vt !== '' && !in_array($vt, TRAVEL_VISA_TYPES, true)) $vt = '';
            return travelFieldEncode(['visa_type' => $vt]);
        case 'oktb':
        case 'lg':
            if ($side !== 'departure') return null;
            $ynp = strtoupper($get('ynp'));
            if ($ynp !== '' && !in_array($ynp, TRAVEL_YNP, true)) $ynp = '';
            return travelFieldEncode([
                'ynp'    => $ynp,
                'detail' => $get('detail'),
            ]);
        case 'custom':
        default:
            return travelFieldEncode(['raw' => $get('raw')]);
    }
}

// -------------------------------------------------------------
// POST handlers
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    /* ---- Add a default-typed row from the quick-add buttons ---- */
    if ($action === 'add_default') {
        $detailLabel = trim($_POST['detail_label'] ?? '');
        if (!isset(TRAVEL_ROW_CATALOG[$detailLabel])) {
            flash('error', 'Unknown default label.');
        } else {
            $next = (int)$pdo->query(
                "SELECT COALESCE(MAX(sr_number), 0) FROM travel_details WHERE crew_id = " . $crewId
            )->fetchColumn() + 1;
            $stmt = $pdo->prepare(
                "INSERT INTO travel_details
                    (crew_id, sr_number, detail_label, is_done, final_status, field_type, created_by)
                 VALUES (:c, :sr, :dl, 0, 'pending', 'default', :u)"
            );
            $stmt->execute([
                ':c' => $crewId, ':sr' => $next, ':dl' => $detailLabel, ':u' => $user['id'],
            ]);
            $newId = (int)$pdo->lastInsertId();
            logActivity(
                $pdo, $user['id'], 'create', 'travel_details', $newId,
                "Added travel row '{$detailLabel}' for crew {$crewId}"
            );
            flash('success', "Row '{$detailLabel}' added — fill in the fields and click Save changes.");
        }
    }

    /* ---- Add a custom row ---- */
    elseif ($action === 'add_custom') {
        $detailLabel = trim($_POST['detail_label'] ?? '');
        $depRaw      = trim($_POST['departure_raw'] ?? '');
        $arrRaw      = trim($_POST['arrival_raw']   ?? '');
        $remarks     = trim($_POST['remarks']       ?? '');

        $errors = [];
        if ($detailLabel === '')           $errors[] = 'Label is required.';
        if (mb_strlen($detailLabel) > 100) $errors[] = 'Label too long (max 100 chars).';

        if (!empty($errors)) {
            foreach ($errors as $e) flash('error', $e);
        } else {
            $depEnc = travelFieldEncode(['raw' => $depRaw]);
            $arrEnc = travelFieldEncode(['raw' => $arrRaw]);

            $next = (int)$pdo->query(
                "SELECT COALESCE(MAX(sr_number), 0) FROM travel_details WHERE crew_id = " . $crewId
            )->fetchColumn() + 1;

            $stmt = $pdo->prepare(
                "INSERT INTO travel_details
                    (crew_id, sr_number, detail_label, departure, arrival,
                     is_done, final_status, remarks, field_type, created_by)
                 VALUES (:c, :sr, :dl, :dep, :arr, 0, 'pending', :rm, 'custom', :u)"
            );
            $stmt->execute([
                ':c'  => $crewId, ':sr' => $next, ':dl' => $detailLabel,
                ':dep'=> $depEnc, ':arr'=> $arrEnc,
                ':rm' => $remarks !== '' ? $remarks : null,
                ':u'  => $user['id'],
            ]);
            $newId = (int)$pdo->lastInsertId();
            logActivity(
                $pdo, $user['id'], 'create', 'travel_details', $newId,
                "Added custom travel row '{$detailLabel}' for crew {$crewId}"
            );
            flash('success', "Custom row '{$detailLabel}' added.");
        }
    }

    /* ---- Bulk save (SAVE CHANGES button) ---- */
    elseif ($action === 'save_all') {
        $rowsPost = $_POST['rows'] ?? [];
        if (!is_array($rowsPost)) $rowsPost = [];

        // Pull existing rows so we can verify ownership and learn row types.
        $cur = $pdo->prepare("SELECT * FROM travel_details WHERE crew_id = :c");
        $cur->execute([':c' => $crewId]);
        $existing = [];
        foreach ($cur->fetchAll() as $r) $existing[(int)$r['id']] = $r;

        $updated = 0;
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare(
                "UPDATE travel_details
                    SET detail_label = :dl, departure = :dep, arrival = :arr,
                        is_done = :id, final_status = :fs, remarks = :rm,
                        updated_at = CURRENT_TIMESTAMP
                  WHERE id = :i AND crew_id = :c"
            );

            foreach ($rowsPost as $rid => $payload) {
                $rid = (int)$rid;
                if (!isset($existing[$rid])) continue;
                $cur = $existing[$rid];

                $rowType  = travelRowType($cur['detail_label'], $cur['field_type']);
                // Only the custom row's label is editable.
                $newLabel = $rowType === 'custom'
                    ? trim((string)($payload['detail_label'] ?? $cur['detail_label']))
                    : $cur['detail_label'];
                if ($newLabel === '') $newLabel = $cur['detail_label'];
                if (mb_strlen($newLabel) > 100) $newLabel = mb_substr($newLabel, 0, 100);

                $depEnc = readSidePayload($payload, $rowType, 'departure');
                $arrEnc = readSidePayload($payload, $rowType, 'arrival');

                $isDone = !empty($payload['is_done']) ? 1 : 0;
                $fs     = $payload['final_status'] ?? 'pending';
                if (!in_array($fs, ['valid','pending','invalid'], true)) $fs = 'pending';

                $remarks = trim((string)($payload['remarks'] ?? ''));

                $stmt->execute([
                    ':dl' => $newLabel,
                    ':dep'=> $depEnc,
                    ':arr'=> $arrEnc,
                    ':id' => $isDone,
                    ':fs' => $fs,
                    ':rm' => $remarks !== '' ? $remarks : null,
                    ':i'  => $rid,
                    ':c'  => $crewId,
                ]);
                $updated++;
            }
            $pdo->commit();
            logActivity(
                $pdo, $user['id'], 'update', 'travel_details', $crewId,
                "Bulk-saved {$updated} travel rows for crew {$crewId}"
            );
            flash('success', $updated === 0 ? 'No rows to save.' : "{$updated} row(s) saved.");
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[SVSML-ERP] travel save_all: ' . $e->getMessage());
            flash('error', 'Could not save: ' . $e->getMessage());
        }
    }

    /* ---- Validate records (auto-set status = valid where data + done) ---- */
    elseif ($action === 'validate_records') {
        $cur = $pdo->prepare("SELECT * FROM travel_details WHERE crew_id = :c");
        $cur->execute([':c' => $crewId]);
        $rows = $cur->fetchAll();
        $touched = 0;
        $upd  = $pdo->prepare(
            "UPDATE travel_details SET final_status = :fs, updated_at = CURRENT_TIMESTAMP
              WHERE id = :i AND crew_id = :c"
        );
        foreach ($rows as $r) {
            $rowType = travelRowType($r['detail_label'], $r['field_type']);
            $hasData = false;
            // For each row type, decide what counts as "data present".
            $dep = travelFieldDecodeForType($r['departure'] ?? '', $rowType);
            $arr = travelFieldDecodeForType($r['arrival']   ?? '', $rowType);
            switch ($rowType) {
                case 'flight_domestic':
                case 'flight_international':
                    $hasData = !empty($dep['airport']) && !empty($dep['date']) && !empty($dep['time'])
                            && !empty($arr['airport']) && !empty($arr['date']) && !empty($arr['time']);
                    break;
                case 'airport_intl':
                    $hasData = !empty($dep['airport']) && !empty($arr['airport']);
                    break;
                case 'visa_country': $hasData = !empty($dep['country'])  ; break;
                case 'visa_type':    $hasData = !empty($dep['visa_type']); break;
                case 'oktb':
                case 'lg':           $hasData = !empty($dep['ynp']) && in_array($dep['ynp'], TRAVEL_YNP, true); break;
                case 'custom':
                    $hasData = !empty($dep['raw']) || !empty($arr['raw']);
                    break;
            }
            $isDone = (int)$r['is_done'] === 1;

            // Logic:
            //   data + done  → valid
            //   no data      → invalid
            //   data + !done → pending  (leave alone unless currently 'invalid')
            $newStatus = $r['final_status'];
            if ($isDone && $hasData)      $newStatus = 'valid';
            elseif (!$hasData)            $newStatus = 'invalid';
            elseif ($r['final_status'] === 'invalid' && $hasData) $newStatus = 'pending';

            if ($newStatus !== $r['final_status']) {
                $upd->execute([':fs' => $newStatus, ':i' => $r['id'], ':c' => $crewId]);
                $touched++;
            }
        }
        logActivity(
            $pdo, $user['id'], 'update', 'travel_details', $crewId,
            "Validated travel records for crew {$crewId} — {$touched} status change(s)"
        );
        flash('success', $touched === 0
            ? 'Records validated — no status changes needed.'
            : "Records validated — {$touched} status update(s) applied.");
    }

    /* ---- Per-row delete ---- */
    elseif ($action === 'delete') {
        $rowId = (int)($_POST['travel_id'] ?? 0);
        $cur = $pdo->prepare("SELECT detail_label, file_path FROM travel_details WHERE id = :i AND crew_id = :c");
        $cur->execute([':i' => $rowId, ':c' => $crewId]);
        $row = $cur->fetch();
        if ($row) {
            // Delete the file from disk before removing the row.
            if (!empty($row['file_path'])) {
                $abs = rtrim(UPLOAD_DIR, '/') . '/' . ltrim($row['file_path'], '/');
                if (is_file($abs)) @unlink($abs);
            }
            $pdo->prepare("DELETE FROM travel_details WHERE id = :i AND crew_id = :c")
                ->execute([':i' => $rowId, ':c' => $crewId]);
            logActivity(
                $pdo, $user['id'], 'delete', 'travel_details', $rowId,
                "Deleted travel row '{$row['detail_label']}' for crew {$crewId}"
            );
            flash('success', "Deleted '{$row['detail_label']}'.");
        }
    }

    /* ---- Upload / replace file on a single travel row ---- */
    elseif ($action === 'upload_travel_file') {
        $rowId = (int)($_POST['travel_id'] ?? 0);
        $cur   = $pdo->prepare("SELECT * FROM travel_details WHERE id = :i AND crew_id = :c");
        $cur->execute([':i' => $rowId, ':c' => $crewId]);
        $row   = $cur->fetch();
        if (!$row) {
            flash('error', 'Travel row not found.');
        } else {
            try {
                // Build a friendly slug for the filename: row label + sr.
                $slug    = 'Travel' . (int)$row['sr_number'] . '_' . ($row['detail_label'] ?? 'Segment');
                $newPath = saveCrewUpload('file', $crew, $slug, false, 'travel');
                // If there was already a file on this row at a different
                // path, delete the old one (the helper already overwrote
                // a same-named one in place).
                if (!empty($row['file_path']) && $row['file_path'] !== $newPath) {
                    $oldAbs = rtrim(UPLOAD_DIR, '/') . '/' . ltrim($row['file_path'], '/');
                    if (is_file($oldAbs)) @unlink($oldAbs);
                }
                $pdo->prepare("UPDATE travel_details SET file_path = :f, updated_at = CURRENT_TIMESTAMP WHERE id = :i AND crew_id = :c")
                    ->execute([':f' => $newPath, ':i' => $rowId, ':c' => $crewId]);
                logActivity($pdo, $user['id'], 'upload', 'travel_details', $rowId,
                    "Uploaded ticket file for travel row {$rowId} (crew {$crewId})");
                flash('success', 'File uploaded.');
            } catch (RuntimeException $e) {
                flash('error', 'Upload failed: ' . $e->getMessage());
            }
        }
    }

    header('Location: ' . url('crew-travel.php?id=' . $crewId));
    exit;
}

// -------------------------------------------------------------
// GET — load all travel rows for this crew
// -------------------------------------------------------------
$rows = $pdo->prepare(
    "SELECT * FROM travel_details WHERE crew_id = :c ORDER BY sr_number, id"
);
$rows->execute([':c' => $crewId]);
$rows = $rows->fetchAll();

$pageTitle  = 'Travel — ' . $crew['full_name'];
$currentTab = 'travel';
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/crew-tabs.php';

/**
 * Render a <datalist>-backed text input. Native browser autocomplete
 * provides search/filter; the user can also type a free value (we still
 * store whatever they enter so legacy / off-list values aren't blocked).
 */
function travelDatalistInput(string $name, string $listId, string $value, string $placeholder = ''): string
{
    return '<input type="text" class="travel-search" autocomplete="off" '
         . 'name="' . h($name) . '" '
         . 'list="' . h($listId) . '" '
         . 'value="' . h($value) . '" '
         . 'placeholder="' . h($placeholder) . '">';
}

/** Render a single row of the editor. Returns HTML string. */
function renderTravelRow(array $row): string
{
    $rid     = (int)$row['id'];
    $rowType = travelRowType($row['detail_label'], $row['field_type']);
    $dep     = travelFieldDecodeForType($row['departure'] ?? '', $rowType);
    $arr     = travelFieldDecodeForType($row['arrival']   ?? '', $rowType);

    $cat = travelCatalogByType($rowType);
    $depAirports = $cat ? travelAirportsForSide($cat, 'departure') : [];
    $arrAirports = $cat ? travelAirportsForSide($cat, 'arrival')   : [];

    $depListId = ($rowType === 'flight_international' || $rowType === 'airport_intl')
        ? 'dl-airports-intl'
        : 'dl-airports-indian';
    switch ($rowType) {
        case 'flight_domestic':       $arrListId = 'dl-airports-indian'; break;
        case 'flight_international':  $arrListId = 'dl-airports-intl';   break;
        case 'airport_intl':          $arrListId = 'dl-airports-intl';   break;
        default:                      $arrListId = 'dl-airports-indian';
    }

    ob_start();
    ?>
    <tr class="travel-row travel-row-<?= h($rowType) ?>">
        <td class="td-sr"><strong><?= (int)$row['sr_number'] ?></strong></td>

        <td class="td-details">
            <?php if ($rowType === 'custom'): ?>
                <input type="text" name="rows[<?= $rid ?>][detail_label]"
                       maxlength="100" required
                       value="<?= h($row['detail_label']) ?>">
                <small class="travel-hint">Custom row</small>
            <?php else: ?>
                <strong><?= h($row['detail_label']) ?></strong>
                <?php if ($cat && !empty($cat['hint'])): ?>
                    <small class="travel-hint"><?= h($cat['hint']) ?></small>
                <?php endif; ?>
            <?php endif; ?>
        </td>

        <td class="td-side">
            <?= renderSideCell($rid, 'departure', $rowType, $dep, $depListId) ?>
        </td>

        <td class="td-side">
            <?= renderSideCell($rid, 'arrival', $rowType, $arr, $arrListId) ?>
        </td>

        <td class="td-done">
            <label class="travel-check">
                <input type="checkbox" name="rows[<?= $rid ?>][is_done]" value="1"
                       <?= (int)$row['is_done'] ? 'checked' : '' ?>>
                <span>Done</span>
            </label>
        </td>

        <td class="td-status">
            <select name="rows[<?= $rid ?>][final_status]" class="travel-status-select">
                <option value="pending" <?= $row['final_status'] === 'pending' ? 'selected' : '' ?>>Pending</option>
                <option value="valid"   <?= $row['final_status'] === 'valid'   ? 'selected' : '' ?>>Valid</option>
                <option value="invalid" <?= $row['final_status'] === 'invalid' ? 'selected' : '' ?>>Invalid</option>
            </select>
        </td>

        <td class="td-remarks">
            <textarea name="rows[<?= $rid ?>][remarks]" rows="2"
                      placeholder="Remarks…"><?= h($row['remarks'] ?? '') ?></textarea>
        </td>

        <td class="td-action">
            <div class="travel-action-stack">
                <?php if (!empty($row['file_path'])): ?>
                    <a class="action-link" target="_blank" rel="noopener"
                       href="<?= asset('uploads/' . $row['file_path']) ?>">View file</a>
                <?php endif; ?>
                <button type="submit" form="travel-delete-<?= $rid ?>" class="btn btn-danger btn-sm"
                        data-confirm="Delete travel row '<?= h($row['detail_label']) ?>'?">
                    Delete
                </button>
            </div>
        </td>
    </tr>
    <tr class="travel-row-attach">
        <td colspan="8">
            <form method="post" enctype="multipart/form-data" class="travel-attach-form" novalidate>
                <?= csrfField() ?>
                <input type="hidden" name="action"    value="upload_travel_file">
                <input type="hidden" name="travel_id" value="<?= $rid ?>">
                <label class="travel-attach-label">
                    <?= !empty($row['file_path']) ? 'Replace ticket file:' : 'Attach ticket file:' ?>
                </label>
                <input type="file" name="file" required accept=".pdf,.jpg,.jpeg,.png,.docx">
                <button type="submit" class="btn btn-secondary btn-sm">
                    <?= !empty($row['file_path']) ? 'Replace' : 'Upload' ?>
                </button>
            </form>
        </td>
    </tr>
    <?php
    return ob_get_clean();
}

/**
 * Render the inputs for one side (departure or arrival) of a row.
 * Returns "—" for sides that don't apply to the given row type.
 */
function renderSideCell(int $rid, string $side, string $rowType, array $parts, string $airportListId): string
{
    $name = function (string $key) use ($rid, $side) {
        return "rows[{$rid}][{$side}_{$key}]";
    };

    ob_start();

    switch ($rowType) {
        case 'flight_domestic':
        case 'flight_international':
            ?>
            <div class="travel-stack">
                <div class="travel-sub">
                    <label>Airport</label>
                    <?= travelDatalistInput($name('airport'), $airportListId, (string)($parts['airport'] ?? ''), 'IATA - CITY') ?>
                </div>
                <div class="travel-sub-pair">
                    <div class="travel-sub">
                        <label>Date</label>
                        <input type="date" name="<?= h($name('date')) ?>" value="<?= h($parts['date'] ?? '') ?>">
                    </div>
                    <div class="travel-sub">
                        <label>Time</label>
                        <input type="time" name="<?= h($name('time')) ?>" value="<?= h($parts['time'] ?? '') ?>">
                    </div>
                </div>
            </div>
            <?php
            break;

        case 'airport_intl':
            ?>
            <div class="travel-stack">
                <div class="travel-sub">
                    <label>Airport</label>
                    <?= travelDatalistInput($name('airport'), $airportListId, (string)($parts['airport'] ?? ''), 'IATA - CITY') ?>
                </div>
            </div>
            <?php
            break;

        case 'visa_country':
            if ($side !== 'departure') { echo '<span class="muted">—</span>'; break; }
            ?>
            <div class="travel-stack">
                <div class="travel-sub">
                    <label>Country</label>
                    <?= travelDatalistInput($name('country'), 'dl-countries', (string)($parts['country'] ?? ''), 'Country name') ?>
                </div>
            </div>
            <?php
            break;

        case 'visa_type':
            if ($side !== 'departure') { echo '<span class="muted">—</span>'; break; }
            $cur = (string)($parts['visa_type'] ?? '');
            ?>
            <div class="travel-stack">
                <div class="travel-sub">
                    <label>Type</label>
                    <select name="<?= h($name('visa_type')) ?>">
                        <option value="">— Select —</option>
                        <?php foreach (TRAVEL_VISA_TYPES as $t): ?>
                            <option value="<?= h($t) ?>" <?= $cur === $t ? 'selected' : '' ?>><?= h($t) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <?php
            break;

        case 'oktb':
        case 'lg':
            if ($side !== 'departure') { echo '<span class="muted">—</span>'; break; }
            $cur = (string)($parts['ynp'] ?? '');
            ?>
            <div class="travel-stack">
                <div class="travel-sub">
                    <label>Status</label>
                    <select name="<?= h($name('ynp')) ?>">
                        <option value="">— Select —</option>
                        <?php foreach (TRAVEL_YNP as $t): ?>
                            <option value="<?= h($t) ?>" <?= $cur === $t ? 'selected' : '' ?>><?= h($t) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="travel-sub">
                    <label>Detail</label>
                    <input type="text" name="<?= h($name('detail')) ?>" maxlength="200"
                           value="<?= h((string)($parts['detail'] ?? '')) ?>"
                           placeholder="Optional note">
                </div>
            </div>
            <?php
            break;

        case 'custom':
        default:
            // Free text on each side. Pre-populate from 'raw'; fall back
            // to formatted legacy datetime if that's what's stored.
            $val = (string)($parts['raw'] ?? '');
            if ($val !== '' && $rowType === 'custom') {
                // Display friendlier version of legacy datetimes.
                $legacy = formatTravelDateTimeForDisplay($val);
                if ($legacy !== $val && $legacy !== '') {
                    // Keep raw so user can re-edit, but we display via val below.
                }
            }
            ?>
            <div class="travel-stack">
                <input type="text" name="<?= h($name('raw')) ?>" maxlength="100"
                       value="<?= h($val) ?>" placeholder="Free text">
            </div>
            <?php
            break;
    }

    return ob_get_clean();
}
?>

<datalist id="dl-airports-indian">
    <?php foreach (TRAVEL_INDIAN_AIRPORTS as $a): ?>
        <option value="<?= h($a) ?>"></option>
    <?php endforeach; ?>
</datalist>
<datalist id="dl-airports-intl">
    <?php foreach (TRAVEL_INTL_AIRPORTS as $a): ?>
        <option value="<?= h($a) ?>"></option>
    <?php endforeach; ?>
</datalist>
<datalist id="dl-countries">
    <?php foreach (TRAVEL_COUNTRIES as $c): ?>
        <option value="<?= h($c) ?>"></option>
    <?php endforeach; ?>
</datalist>

<div class="card">
    <div class="toolbar travel-toolbar">
        <h3 class="card-title" style="margin:0">
            Travel segments <small class="help-text">(<?= count($rows) ?>)</small>
        </h3>
        <div class="travel-toolbar-actions">
            <button type="submit" form="travel-form" name="action" value="save_all" class="btn">Save changes</button>
            <button type="submit" form="travel-form" name="action" value="validate_records"
                    class="btn btn-secondary"
                    data-confirm="Recompute Final Status across all rows from current data + Done flag?">
                Validate records
            </button>
            <a class="btn btn-secondary" href="<?= asset('crew-travel.php?id=' . $crewId) ?>">Refresh</a>
            <button type="reset" form="travel-form" class="btn btn-ghost">Clear</button>
        </div>
    </div>

    <?php if (empty($rows)): ?>
        <p class="help-text">No travel rows yet. Use the quick-add buttons or "Add custom row" below.</p>
    <?php else: ?>
        <form id="travel-form" method="post" novalidate>
            <?= csrfField() ?>
            <div class="travel-table-wrap">
                <table class="data-table travel-table">
                    <thead>
                        <tr>
                            <th class="th-sr">SR NO</th>
                            <th class="th-details">DETAILS</th>
                            <th class="th-side">DEPARTURE</th>
                            <th class="th-side">ARRIVAL</th>
                            <th class="th-done">DONE</th>
                            <th class="th-status">FINAL STATUS</th>
                            <th class="th-remarks">REMARKS</th>
                            <th class="th-action">ACTION</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $row) echo renderTravelRow($row); ?>
                    </tbody>
                </table>
            </div>
        </form>

        <?php /* One out-of-band delete form per row, referenced by the delete button via form="...". */ ?>
        <?php foreach ($rows as $row): ?>
            <form id="travel-delete-<?= (int)$row['id'] ?>" method="post" style="display:none">
                <?= csrfField() ?>
                <input type="hidden" name="action"    value="delete">
                <input type="hidden" name="travel_id" value="<?= (int)$row['id'] ?>">
            </form>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<div class="card">
    <h3 class="card-title">Quick-add a typed row</h3>
    <p class="help-text">Click a label to insert a row of that type. Fill in the type-specific fields above and click <strong>Save changes</strong>.</p>
    <div class="travel-quickadd">
        <?php foreach (TRAVEL_ROW_CATALOG as $label => $cfg): ?>
            <form method="post" style="display:inline">
                <?= csrfField() ?>
                <input type="hidden" name="action"       value="add_default">
                <input type="hidden" name="detail_label" value="<?= h($label) ?>">
                <button type="submit" class="btn btn-secondary btn-sm">+ <?= h($label) ?></button>
            </form>
        <?php endforeach; ?>
    </div>
</div>

<div class="card">
    <h3 class="card-title">Add custom row</h3>
    <p class="help-text">Use this for travel details that don't fit the default types.</p>
    <form method="post" novalidate>
        <?= csrfField() ?>
        <input type="hidden" name="action" value="add_custom">
        <div class="form-grid">
            <div class="form-row full-row">
                <label>Label *</label>
                <input type="text" name="detail_label" maxlength="100" required>
            </div>
            <div class="form-row">
                <label>Departure (free text)</label>
                <input type="text" name="departure_raw" maxlength="100">
            </div>
            <div class="form-row">
                <label>Arrival (free text)</label>
                <input type="text" name="arrival_raw" maxlength="100">
            </div>
            <div class="form-row full-row">
                <label>Remarks</label>
                <textarea name="remarks" rows="2"></textarea>
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn">Add custom row</button>
            <button type="reset"  class="btn btn-secondary">Reset</button>
        </div>
    </form>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
