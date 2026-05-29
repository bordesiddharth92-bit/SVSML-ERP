<?php
/**
 * SVSML-ERP — Staff Activity Log
 *
 * Module 13.
 *
 * Read-only viewer for the staff_activity table. Every DB write
 * across the application calls logActivity() (helpers.php), which
 * inserts here. This page lets admins audit who did what.
 *
 * Filters:
 *   - user_id        (any staff/admin user)
 *   - action_type    (create / update / delete / upload / generate)
 *   - module         (free-text module name as logged)
 *   - date range     (from / to — inclusive, on created_at)
 *
 * Permissions: admin / sub_admin only. Staff users do not see the audit
 * trail; that's an admin oversight tool.
 */

require __DIR__ . '/config/app.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/helpers.php';

requireRole(['admin', 'sub_admin']);

$user = currentUser();

$userId   = isset($_GET['user_id'])     && $_GET['user_id']     !== '' ? (int)$_GET['user_id'] : null;
$action   = $_GET['action_type'] ?? '';
$module   = trim($_GET['module']  ?? '');
$dateFrom = trim($_GET['from']    ?? '');
$dateTo   = trim($_GET['to']      ?? '');
$page     = max(1, (int)($_GET['page'] ?? 1));
$perPage  = defined('PAGE_SIZE') ? PAGE_SIZE : 20;

$where  = [];
$params = [];
if ($userId !== null) {
    $where[] = 'sa.user_id = :u';
    $params[':u'] = $userId;
}
if (in_array($action, ['create','update','delete','upload','generate'], true)) {
    $where[] = 'sa.action_type = :a';
    $params[':a'] = $action;
}
if ($module !== '') {
    $where[] = 'sa.module = :m';
    $params[':m'] = $module;
}
if ($dateFrom !== '' && DateTime::createFromFormat('Y-m-d', $dateFrom)) {
    $where[] = 'sa.created_at >= :df';
    $params[':df'] = $dateFrom . ' 00:00:00';
}
if ($dateTo !== '' && DateTime::createFromFormat('Y-m-d', $dateTo)) {
    $where[] = 'sa.created_at <= :dt';
    $params[':dt'] = $dateTo . ' 23:59:59';
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM staff_activity sa $whereSql");
$countStmt->execute($params);
$totalRows  = (int)$countStmt->fetchColumn();
$totalPages = (int)max(1, ceil($totalRows / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

$rows = $pdo->prepare(
    "SELECT sa.id, sa.user_id, sa.action_type, sa.module, sa.record_id,
            sa.description, sa.created_at,
            u.full_name AS user_name, u.role AS user_role
       FROM staff_activity sa
       LEFT JOIN users u ON u.id = sa.user_id
       $whereSql
       ORDER BY sa.created_at DESC, sa.id DESC
       LIMIT $perPage OFFSET $offset"
);
$rows->execute($params);
$rows = $rows->fetchAll();

$staffUsers = $pdo->query(
    "SELECT id, full_name, role
       FROM users
      WHERE role IN ('admin','sub_admin','staff')
      ORDER BY full_name"
)->fetchAll();

$modules = $pdo->query(
    "SELECT DISTINCT module FROM staff_activity ORDER BY module"
)->fetchAll(PDO::FETCH_COLUMN);

$qsBase = [];
if ($userId !== null)  $qsBase['user_id']     = $userId;
if ($action !== '')    $qsBase['action_type'] = $action;
if ($module !== '')    $qsBase['module']      = $module;
if ($dateFrom !== '')  $qsBase['from']        = $dateFrom;
if ($dateTo   !== '')  $qsBase['to']          = $dateTo;
$baseUrl = url('activity-log.php' . ($qsBase ? '?' . http_build_query($qsBase) : ''));

/** Tiny helper for the action-type pill colour. */
function activityBadgeClass(string $a): string
{
    switch ($a) {
        case 'create':   return 'status-green';
        case 'update':   return 'status-yellow';
        case 'delete':   return 'status-red';
        case 'upload':   return 'status-yellow';
        case 'generate': return 'status-yellow';
    }
    return 'status-gray';
}

$pageTitle = 'Activity Log';
include __DIR__ . '/includes/header.php';
?>

<div class="card">
    <div class="toolbar">
        <h2 class="card-title" style="margin:0">Staff Activity Log</h2>
    </div>

    <form method="get" class="filter-bar" novalidate>
        <div class="form-row">
            <select name="user_id">
                <option value="">All users</option>
                <?php foreach ($staffUsers as $u): ?>
                    <option value="<?= (int)$u['id'] ?>" <?= ($userId === (int)$u['id']) ? 'selected' : '' ?>>
                        <?= h($u['full_name']) ?> (<?= h($u['role']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-row">
            <select name="action_type">
                <option value="">All actions</option>
                <?php foreach (['create','update','delete','upload','generate'] as $a): ?>
                    <option value="<?= h($a) ?>" <?= $action === $a ? 'selected' : '' ?>><?= h(ucfirst($a)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-row">
            <select name="module">
                <option value="">All modules</option>
                <?php foreach ($modules as $m): ?>
                    <option value="<?= h($m) ?>" <?= $module === $m ? 'selected' : '' ?>><?= h($m) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-row">
            <input type="date" name="from" value="<?= h($dateFrom) ?>" title="From date">
        </div>
        <div class="form-row">
            <input type="date" name="to"   value="<?= h($dateTo)   ?>" title="To date">
        </div>
        <div class="filter-actions">
            <button type="submit" class="btn btn-secondary">Apply</button>
            <a class="btn btn-ghost" href="<?= asset('activity-log.php') ?>">Reset</a>
        </div>
    </form>

    <p class="result-count">
        <?= count($rows) ?> of <?= (int)$totalRows ?>
        <?= $totalRows === 1 ? 'event' : 'events' ?>
        <?php if ($totalPages > 1): ?> · Page <?= (int)$page ?> of <?= (int)$totalPages ?><?php endif; ?>.
    </p>

    <?php if (empty($rows)): ?>
        <div class="empty-state">
            <h3>No activity recorded</h3>
            <p>Activity events appear here as soon as anyone creates, updates, deletes, uploads or generates content in the system.</p>
        </div>
    <?php else: ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width:16%">When</th>
                    <th style="width:18%">User</th>
                    <th style="width:10%">Action</th>
                    <th style="width:12%">Module</th>
                    <th style="width:7%">Rec ID</th>
                    <th>Description</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td>
                            <?= h(date('d/m/Y H:i', strtotime($r['created_at']))) ?>
                            <br><small class="help-text"><?= h($r['created_at']) ?></small>
                        </td>
                        <td>
                            <?php if (!empty($r['user_name'])): ?>
                                <?= h($r['user_name']) ?>
                                <br><span class="badge badge-<?= h($r['user_role']) ?>"><?= h(strtoupper($r['user_role'])) ?></span>
                            <?php else: ?>
                                <span class="help-text">— deleted user —</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="status <?= h(activityBadgeClass($r['action_type'])) ?>">
                                <?= h(ucfirst($r['action_type'])) ?>
                            </span>
                        </td>
                        <td><code><?= h($r['module']) ?></code></td>
                        <td><?= h((string)($r['record_id'] ?? '—')) ?></td>
                        <td><?= h($r['description'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?= paginate($page, $totalPages, $baseUrl) ?>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
