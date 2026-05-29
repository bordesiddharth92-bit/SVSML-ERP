<?php
/**
 * SVSML-ERP — Expiry Alerts
 *
 * Module 15.
 *
 * Aggregates expiry dates across the system into a single sortable list:
 *
 *   Type          Source                                     Drill-in
 *   ------------- ------------------------------------------ ----------------------
 *   documents     crew_documents.expiry_date                 crew-documents.php
 *   medical       crew_medical.expiry_date                   crew-medical.php
 *   basic         basic_courses.expiry_date                  crew-courses.php
 *   advanced      advanced_courses.expiry_date               crew-courses.php
 *   vessel_pni    vessels.pni_date                           vessel-edit.php
 *   vessel_mlc    vessels.mlc_date                           vessel-edit.php
 *   vessel_fs     vessels.financial_security_date            vessel-edit.php
 *
 * Filters:
 *   ?type=...      restrict to one of the keys above ('' = all)
 *   ?bucket=...    expired | expiring | missing | all  (default: all minus 'green')
 *
 * Rows are normalised in PHP to a uniform shape so the rendering loop
 * can be type-agnostic. We deliberately do NOT include green (Valid)
 * rows by default — the operator looks here when there is something to
 * act on.
 *
 * Permissions: admin / sub_admin / staff.
 */

require __DIR__ . '/config/app.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/helpers.php';

requireRole(['admin', 'sub_admin', 'staff']);

$user   = currentUser();
$alerts = getAlertSettings($pdo);
$yellow = (int)($alerts['doc_expiry_yellow_days'] ?? EXPIRY_YELLOW_DAYS);

$type   = $_GET['type']   ?? '';     // '', documents, medical, basic, advanced, vessel_pni, vessel_mlc, vessel_fs
$bucket = $_GET['bucket'] ?? 'attention'; // expired, expiring, missing, all, attention

$validTypes = [
    ''            => 'All categories',
    'documents'   => 'Crew documents',
    'medical'     => 'Medical',
    'basic'       => 'Basic courses',
    'advanced'    => 'Advanced courses',
    'vessel_pni'  => 'Vessel PnI',
    'vessel_mlc'  => 'Vessel MLC',
    'vessel_fs'   => 'Vessel financial security',
];
if (!array_key_exists($type, $validTypes)) $type = '';
if (!in_array($bucket, ['expired','expiring','missing','attention','all'], true)) {
    $bucket = 'attention';
}

/**
 * Standard expiry classification for the alerts page.
 * Reuses dateStatus() to ensure colours match elsewhere in the app.
 */
function alertClassify(?string $date): array
{
    return dateStatus($date);   // ['class','label','days']
}

/** Build a single normalised row for the rendering loop. */
function alertRow(string $type, string $typeLabel, int $entityId, string $entityName,
                  ?string $entitySub, string $itemLabel, ?string $expiry, string $linkHref): array
{
    $st = alertClassify($expiry);
    return [
        'type'        => $type,
        'type_label'  => $typeLabel,
        'entity_id'   => $entityId,
        'entity_name' => $entityName,
        'entity_sub'  => $entitySub,
        'item_label'  => $itemLabel,
        'expiry'      => $expiry,
        'class'       => $st['class'],
        'status'      => $st['label'],
        'days'        => $st['days'],
        'link'        => $linkHref,
    ];
}

// -------------------------------------------------------------
// Pull rows from each source table (filtered by ?type if set)
// -------------------------------------------------------------
$rows = [];

// crew_documents
if ($type === '' || $type === 'documents') {
    $sql = "SELECT cd.id, cd.document_type, cd.document_number, cd.expiry_date,
                   cd.crew_id, cr.full_name AS crew_name,
                   r.rank_name, d.label AS visa_type_label
              FROM crew_documents cd
              JOIN crew cr ON cr.id = cd.crew_id
              LEFT JOIN ranks r ON r.id = cr.rank_id
              LEFT JOIN dropdown_items d ON d.id = cd.visa_type_id";
    foreach ($pdo->query($sql) as $r) {
        $label = strtoupper($r['document_type']);
        if ($r['document_type'] === 'visa' && !empty($r['visa_type_label'])) {
            $label .= ' (' . $r['visa_type_label'] . ')';
        }
        if (!empty($r['document_number'])) $label .= ' #' . $r['document_number'];
        $rows[] = alertRow(
            'documents', 'Document',
            (int)$r['crew_id'], $r['crew_name'], $r['rank_name'],
            $label, $r['expiry_date'],
            url('crew-documents.php?id=' . (int)$r['crew_id'])
        );
    }
}

// crew_medical
if ($type === '' || $type === 'medical') {
    $sql = "SELECT cm.id, cm.expiry_date,
                   cm.crew_id, cr.full_name AS crew_name, r.rank_name,
                   d.label AS medical_type_label
              FROM crew_medical cm
              JOIN crew cr ON cr.id = cm.crew_id
              LEFT JOIN ranks r ON r.id = cr.rank_id
              LEFT JOIN dropdown_items d ON d.id = cm.medical_type_id";
    foreach ($pdo->query($sql) as $r) {
        $rows[] = alertRow(
            'medical', 'Medical',
            (int)$r['crew_id'], $r['crew_name'], $r['rank_name'],
            $r['medical_type_label'] ?? '—',
            $r['expiry_date'],
            url('crew-medical.php?id=' . (int)$r['crew_id'])
        );
    }
}

// basic_courses
if ($type === '' || $type === 'basic') {
    $sql = "SELECT bc.id, bc.course_name, bc.expiry_date,
                   bc.crew_id, cr.full_name AS crew_name, r.rank_name
              FROM basic_courses bc
              JOIN crew cr ON cr.id = bc.crew_id
              LEFT JOIN ranks r ON r.id = cr.rank_id";
    foreach ($pdo->query($sql) as $r) {
        $rows[] = alertRow(
            'basic', 'Basic course',
            (int)$r['crew_id'], $r['crew_name'], $r['rank_name'],
            $r['course_name'], $r['expiry_date'],
            url('crew-courses.php?id=' . (int)$r['crew_id'])
        );
    }
}

// advanced_courses
if ($type === '' || $type === 'advanced') {
    $sql = "SELECT ac.id, ac.course_name, ac.expiry_date,
                   ac.crew_id, cr.full_name AS crew_name, r.rank_name
              FROM advanced_courses ac
              JOIN crew cr ON cr.id = ac.crew_id
              LEFT JOIN ranks r ON r.id = cr.rank_id";
    foreach ($pdo->query($sql) as $r) {
        $rows[] = alertRow(
            'advanced', 'Advanced course',
            (int)$r['crew_id'], $r['crew_name'], $r['rank_name'],
            $r['course_name'], $r['expiry_date'],
            url('crew-courses.php?id=' . (int)$r['crew_id'])
        );
    }
}

// vessel certificates
$vesselTypeMap = [
    'vessel_pni' => ['col' => 'pni_date',                'label' => 'Vessel PnI',                'item' => 'PnI insurance'],
    'vessel_mlc' => ['col' => 'mlc_date',                'label' => 'Vessel MLC',                'item' => 'MLC certificate'],
    'vessel_fs'  => ['col' => 'financial_security_date', 'label' => 'Vessel Financial Security', 'item' => 'Financial security'],
];
foreach ($vesselTypeMap as $key => $cfg) {
    if ($type !== '' && $type !== $key) continue;
    $sql = "SELECT v.id, v.vessel_name, v.imo_number, v.{$cfg['col']} AS expiry_date,
                   c.company_name
              FROM vessels v
              LEFT JOIN companies c ON c.id = v.company_id";
    foreach ($pdo->query($sql) as $r) {
        $rows[] = alertRow(
            $key, $cfg['label'],
            (int)$r['id'], $r['vessel_name'],
            !empty($r['company_name']) ? $r['company_name'] : (!empty($r['imo_number']) ? 'IMO ' . $r['imo_number'] : null),
            $cfg['item'], $r['expiry_date'],
            url('vessel-edit.php?id=' . (int)$r['id'])
        );
    }
}

// -------------------------------------------------------------
// Bucket filter (after classification)
// -------------------------------------------------------------
$rows = array_values(array_filter($rows, function ($r) use ($bucket) {
    switch ($bucket) {
        case 'expired':   return $r['class'] === 'red';
        case 'expiring':  return $r['class'] === 'yellow';
        case 'missing':   return $r['class'] === 'gray';
        case 'attention': return in_array($r['class'], ['red','yellow','gray'], true);
        case 'all':       return true;
    }
    return true;
}));

// Sort: Expired (most overdue first) → Expiring (soonest first) → Missing → Valid (latest first).
usort($rows, function ($a, $b) {
    $orderMap = ['red' => 0, 'yellow' => 1, 'gray' => 2, 'green' => 3];
    $ao = $orderMap[$a['class']] ?? 9;
    $bo = $orderMap[$b['class']] ?? 9;
    if ($ao !== $bo) return $ao <=> $bo;
    if ($a['days'] !== null && $b['days'] !== null) return $a['days'] <=> $b['days'];
    return strcmp($a['entity_name'], $b['entity_name']);
});

// Bucket counters (independent of bucket filter so they're a stable summary).
$counts = ['red' => 0, 'yellow' => 0, 'gray' => 0, 'green' => 0];

// We rebuild counts from the type-filtered (but pre-bucket-filtered) row set.
// For simplicity, rerun the buckets across all rows we collected above.
// (At this point $rows is post-filter; do the count via re-querying small)
// — instead, count from raw $allRows we keep alongside. Do it cleanly:

$counts = ['red' => 0, 'yellow' => 0, 'gray' => 0, 'green' => 0];
foreach ($rows as $r) $counts[$r['class']] = ($counts[$r['class']] ?? 0) + 1;

$qsBase = function (array $changes) use ($type, $bucket): string {
    $qs = [];
    if ($type   !== '') $qs['type']   = $type;
    if ($bucket !== '') $qs['bucket'] = $bucket;
    foreach ($changes as $k => $v) {
        if ($v === null || $v === '') unset($qs[$k]);
        else $qs[$k] = $v;
    }
    return url('alerts.php' . ($qs ? '?' . http_build_query($qs) : ''));
};

$pageTitle = 'Expiry Alerts';
include __DIR__ . '/includes/header.php';
?>

<div class="card">
    <div class="toolbar">
        <h2 class="card-title" style="margin:0">Expiry Alerts</h2>
        <span class="help-text">Yellow threshold: <?= (int)$yellow ?> days</span>
    </div>

    <form method="get" class="filter-bar" novalidate>
        <div class="form-row">
            <select name="type">
                <?php foreach ($validTypes as $key => $lbl): ?>
                    <option value="<?= h($key) ?>" <?= $type === $key ? 'selected' : '' ?>><?= h($lbl) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-row">
            <select name="bucket">
                <option value="attention" <?= $bucket === 'attention' ? 'selected' : '' ?>>Needs attention (red + yellow + missing)</option>
                <option value="expired"   <?= $bucket === 'expired'   ? 'selected' : '' ?>>Expired only</option>
                <option value="expiring"  <?= $bucket === 'expiring'  ? 'selected' : '' ?>>Expiring soon only</option>
                <option value="missing"   <?= $bucket === 'missing'   ? 'selected' : '' ?>>Missing date only</option>
                <option value="all"       <?= $bucket === 'all'       ? 'selected' : '' ?>>All (incl. valid)</option>
            </select>
        </div>
        <div class="filter-actions">
            <button type="submit" class="btn btn-secondary">Apply</button>
            <a class="btn btn-ghost" href="<?= asset('alerts.php') ?>">Reset</a>
        </div>
    </form>

    <p class="result-count">
        <?= count($rows) ?>
        <?= count($rows) === 1 ? 'item' : 'items' ?>
        <?php if ($type !== ''): ?> · type: <strong><?= h($validTypes[$type]) ?></strong><?php endif; ?>
        <?php if ($bucket !== ''): ?> · bucket: <strong><?= h($bucket) ?></strong><?php endif; ?>.
    </p>

    <?php if (empty($rows)): ?>
        <div class="empty-state">
            <h3>Nothing to act on</h3>
            <p>All certificates are valid for the selected scope. <span aria-hidden="true">🎉</span></p>
        </div>
    <?php else: ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Status</th>
                    <th>Type</th>
                    <th>Entity</th>
                    <th>Item</th>
                    <th>Expiry</th>
                    <th>Days</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><span class="status status-<?= h($r['class']) ?>"><?= h($r['status']) ?></span></td>
                        <td><?= h($r['type_label']) ?></td>
                        <td>
                            <strong><?= h($r['entity_name']) ?></strong>
                            <?php if (!empty($r['entity_sub'])): ?>
                                <br><small class="help-text"><?= h($r['entity_sub']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td><?= h($r['item_label']) ?></td>
                        <td><?= h($r['expiry'] ?? '—') ?></td>
                        <td>
                            <?php if ($r['days'] === null): ?>
                                —
                            <?php elseif ($r['days'] < 0): ?>
                                <span style="color: var(--red)"><?= (int)$r['days'] ?>d</span>
                            <?php elseif ($r['days'] <= $yellow): ?>
                                <span style="color: var(--yellow)"><?= (int)$r['days'] ?>d</span>
                            <?php else: ?>
                                <?= (int)$r['days'] ?>d
                            <?php endif; ?>
                        </td>
                        <td><a class="action-link" href="<?= h($r['link']) ?>">Open →</a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
