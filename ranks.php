<?php
/**
 * SVSML-ERP — Ranks manager
 *
 * Module 2.
 *
 * Maintains the global list of crew ranks (Master, Chief Officer, ...).
 * Admin / Sub-admin / Staff can add, rename and delete ranks.
 *
 * Deleting a rank sets crew.rank_id and sailing_history.rank_id to NULL
 * (ON DELETE SET NULL), so dependent rows are kept but lose their rank
 * pointer. The page surfaces the affected count so the user can decide
 * whether to proceed.
 *
 * All POSTs are CSRF-protected and write to staff_activity via logActivity().
 */

require __DIR__ . '/config/app.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/helpers.php';

requireRole(['admin', 'sub_admin', 'staff']);

$user = currentUser();

// -------------------------------------------------------------
// POST handlers — create / update / delete
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $name = trim($_POST['rank_name'] ?? '');
        if ($name === '') {
            flash('error', 'Rank name cannot be empty.');
        } elseif (mb_strlen($name) > 100) {
            flash('error', 'Rank name too long (max 100 characters).');
        } else {
            $chk = $pdo->prepare(
                "SELECT id FROM ranks WHERE LOWER(rank_name) = LOWER(:n) LIMIT 1"
            );
            $chk->execute([':n' => $name]);
            if ($chk->fetch()) {
                flash('error', "'{$name}' already exists.");
            } else {
                $stmt = $pdo->prepare(
                    "INSERT INTO ranks (rank_name, created_by, created_at)
                     VALUES (:n, :u, NOW())"
                );
                $stmt->execute([':n' => $name, ':u' => $user['id']]);
                $newId = (int)$pdo->lastInsertId();
                logActivity(
                    $pdo, $user['id'], 'create', 'ranks', $newId,
                    "Added rank '{$name}'"
                );
                flash('success', "Added '{$name}'.");
            }
        }
    }

    elseif ($action === 'update') {
        $id   = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['rank_name'] ?? '');
        if ($id <= 0 || $name === '') {
            flash('error', 'Invalid input — rank name is required.');
        } elseif (mb_strlen($name) > 100) {
            flash('error', 'Rank name too long (max 100 characters).');
        } else {
            $chk = $pdo->prepare(
                "SELECT id FROM ranks
                  WHERE LOWER(rank_name) = LOWER(:n) AND id <> :i LIMIT 1"
            );
            $chk->execute([':n' => $name, ':i' => $id]);
            if ($chk->fetch()) {
                flash('error', "'{$name}' already exists.");
            } else {
                $stmt = $pdo->prepare("UPDATE ranks SET rank_name = :n WHERE id = :i");
                $stmt->execute([':n' => $name, ':i' => $id]);
                if ($stmt->rowCount() > 0) {
                    logActivity(
                        $pdo, $user['id'], 'update', 'ranks', $id,
                        "Renamed rank.id={$id} to '{$name}'"
                    );
                    flash('success', "Renamed to '{$name}'.");
                }
            }
        }
    }

    elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $row = $pdo->prepare("SELECT rank_name FROM ranks WHERE id = :i");
            $row->execute([':i' => $id]);
            $name = $row->fetchColumn();
            if ($name !== false) {
                // Count usage so we can include it in the activity log.
                $crewCount = (int)$pdo->query(
                    "SELECT COUNT(*) FROM crew WHERE rank_id = " . $id
                )->fetchColumn();
                $histCount = (int)$pdo->query(
                    "SELECT COUNT(*) FROM sailing_history WHERE rank_id = " . $id
                )->fetchColumn();

                $stmt = $pdo->prepare("DELETE FROM ranks WHERE id = :i");
                $stmt->execute([':i' => $id]);

                $details = "Deleted rank '{$name}'";
                if ($crewCount + $histCount > 0) {
                    $details .= " (cleared on {$crewCount} crew, {$histCount} sailing history rows)";
                }
                logActivity($pdo, $user['id'], 'delete', 'ranks', $id, $details);

                $msg = "Deleted '{$name}'.";
                if ($crewCount + $histCount > 0) {
                    $msg .= " {$crewCount} crew and {$histCount} sailing history rows now have no rank.";
                }
                flash('success', $msg);
            }
        }
    }

    header('Location: ' . url('ranks.php'));
    exit;
}

// -------------------------------------------------------------
// GET — load ranks with usage counts (LEFT JOIN with subquery)
// -------------------------------------------------------------
$ranks = $pdo->query("
    SELECT r.id,
           r.rank_name,
           (SELECT COUNT(*) FROM crew            c WHERE c.rank_id = r.id) AS crew_count,
           (SELECT COUNT(*) FROM sailing_history s WHERE s.rank_id = r.id) AS history_count
      FROM ranks r
     ORDER BY r.rank_name
")->fetchAll();

$pageTitle = 'Ranks';
include __DIR__ . '/includes/header.php';
?>

<div class="card">
    <h2 class="card-title">Ranks</h2>
    <p class="help-text">
        The global rank catalogue. Used when adding crew, recording sailing
        history, and on the Quick Approval form. Deleting a rank that is
        already in use will clear the rank pointer on those rows
        (the rows themselves are kept).
    </p>
</div>

<div class="card">
    <h3 class="card-title">Add new rank</h3>
    <form method="post" novalidate>
        <?= csrfField() ?>
        <input type="hidden" name="action" value="create">
        <div class="inline-form">
            <input type="text" name="rank_name" placeholder="e.g. Chief Officer"
                   maxlength="100" required autocomplete="off">
            <button type="submit" class="btn">Add</button>
        </div>
    </form>
</div>

<div class="card">
    <h3 class="card-title">
        Existing ranks
        <small class="help-text">(<?= count($ranks) ?> total)</small>
    </h3>

    <?php if (empty($ranks)): ?>
        <p class="help-text">No ranks defined yet. Add one above.</p>
    <?php else: ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width:55%">Rank</th>
                    <th style="width:20%">In use</th>
                    <th style="width:25%; text-align:right">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($ranks as $r): ?>
                    <?php
                        $crewCount = (int)$r['crew_count'];
                        $histCount = (int)$r['history_count'];
                        $totalUse  = $crewCount + $histCount;
                    ?>
                    <tr>
                        <td>
                            <form method="post" class="inline-form" novalidate>
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="update">
                                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                <input type="text" name="rank_name"
                                       value="<?= h($r['rank_name']) ?>"
                                       maxlength="100" required>
                                <button type="submit" class="btn btn-secondary btn-sm">Save</button>
                            </form>
                        </td>
                        <td>
                            <?php if ($totalUse === 0): ?>
                                <span class="status status-gray">Not used</span>
                            <?php else: ?>
                                <span class="status status-green" title="<?= (int)$crewCount ?> crew, <?= (int)$histCount ?> history rows">
                                    <?= (int)$totalUse ?> rows
                                </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="row-actions">
                                <form method="post">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                    <?php
                                        $confirmMsg = "Delete '{$r['rank_name']}'?";
                                        if ($totalUse > 0) {
                                            $confirmMsg .= " {$crewCount} crew and {$histCount} history rows will lose their rank.";
                                        }
                                    ?>
                                    <button type="submit" class="btn btn-danger btn-sm"
                                            data-confirm="<?= h($confirmMsg) ?>">
                                        Delete
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
