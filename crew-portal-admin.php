<?php
/**
 * SVSML-ERP — Crew Portal administration
 *
 * Module 16 admin tool. Single page that lets admin / sub_admin:
 *
 *   1. Flip the master `system_settings.crew_self_login_enabled` switch
 *      (the kill switch for the whole portal). Mirrors the toggle in
 *      settings.php but lives front-and-centre here.
 *   2. See every crew row with its portal state (access enabled?
 *      password set? last login?), filterable / searchable.
 *   3. Bulk-enable or bulk-disable per-crew access for selected rows.
 *   4. Bulk-generate strong random passwords for selected rows. The
 *      generated passwords are displayed ONCE, in a printable list,
 *      so the operator can copy them into a secure share with each
 *      crew member. They're never stored or shown again.
 *
 * Why a dedicated page: the original Module 16 design required the
 * operator to open each crew profile individually, tick access, then
 * set a password — fine for one crew, painful for fifty. This screen
 * makes onboarding a whole intake feasible in a single sitting.
 *
 * Permissions: admin / sub_admin only.
 */

require __DIR__ . '/config/app.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/helpers.php';

requireRole(['admin', 'sub_admin']);
$user = currentUser();

// -------------------------------------------------------------
// Schema probe — gracefully degrade if CHANGES.sql hasn't been
// applied yet, so the operator sees a friendly notice instead of
// a fatal SQL error.
// -------------------------------------------------------------
$hasPasswordCol = false;
try {
    $col = $pdo->query("SHOW COLUMNS FROM crew LIKE 'password_hash'")->fetch();
    $hasPasswordCol = (bool)$col;
} catch (PDOException $e) { /* ignore */ }

/**
 * Generate a friendly random password for a crew member.
 *
 * Avoids visually-ambiguous characters (0/O, 1/I/l) so the operator
 * can confidently read it off a printed list when sharing with crew.
 * 10 characters of [A-Z2-9] gives ~50 bits of entropy — comfortably
 * above the 8-char minimum required by the portal.
 */
function generateCrewPortalPassword(): string
{
    $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $len      = strlen($alphabet);
    $out      = '';
    for ($i = 0; $i < 10; $i++) {
        $out .= $alphabet[random_int(0, $len - 1)];
    }
    return $out;
}

// Holds the (passport, name, password) tuples after a bulk password
// generation, so we can render a one-time printable table below the
// form. NEVER persisted across requests — refreshing the page wipes it.
$generatedPasswords = [];

// -------------------------------------------------------------
// POST handlers
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    /* ---- Master switch toggle ---- */
    if ($action === 'toggle_master') {
        $newValue = !empty($_POST['crew_self_login_enabled']) ? 1 : 0;
        $pdo->prepare(
            "UPDATE system_settings SET crew_self_login_enabled = :v WHERE id = (SELECT id FROM (SELECT id FROM system_settings ORDER BY id ASC LIMIT 1) t)"
        )->execute([':v' => $newValue]);
        logActivity(
            $pdo, $user['id'], 'update', 'system_settings', null,
            'Crew self-login master switch ' . ($newValue ? 'enabled' : 'disabled')
        );
        flash(
            $newValue ? 'success' : 'warning',
            $newValue
                ? 'Crew portal master switch is now ENABLED.'
                : 'Crew portal master switch is now DISABLED — no crew can sign in until you turn it back on.'
        );
        header('Location: ' . url('crew-portal-admin.php'));
        exit;
    }

    /* ---- Bulk operations on selected crew ids ---- */
    elseif ($action === 'bulk_enable' || $action === 'bulk_disable' || $action === 'bulk_passwords') {
        $ids = $_POST['crew_ids'] ?? [];
        if (!is_array($ids)) $ids = [];
        $ids = array_values(array_filter(array_map('intval', $ids), function ($v) { return $v > 0; }));

        if (empty($ids)) {
            flash('error', 'Select at least one crew member first.');
        } elseif (!$hasPasswordCol && $action === 'bulk_passwords') {
            flash('error', 'Database migration pending — run CHANGES.sql before generating passwords.');
        } else {
            // Inline an IN-clause for the bulk update — safe because we
            // re-cast every id to int above.
            $inClause = '(' . implode(',', $ids) . ')';

            try {
                $pdo->beginTransaction();

                if ($action === 'bulk_enable') {
                    $pdo->exec("UPDATE crew SET crew_access_enabled = 1 WHERE id IN $inClause");
                    logActivity(
                        $pdo, $user['id'], 'update', 'crew_access', null,
                        'Bulk-enabled portal access for crew ids: ' . implode(',', $ids)
                    );
                    flash('success', count($ids) . ' crew member(s) — portal access enabled.');
                }

                elseif ($action === 'bulk_disable') {
                    $pdo->exec("UPDATE crew SET crew_access_enabled = 0 WHERE id IN $inClause");
                    logActivity(
                        $pdo, $user['id'], 'update', 'crew_access', null,
                        'Bulk-disabled portal access for crew ids: ' . implode(',', $ids)
                    );
                    flash('warning', count($ids) . ' crew member(s) — portal access disabled.');
                }

                elseif ($action === 'bulk_passwords') {
                    // Pull names + passports BEFORE the password write so the
                    // post-action display can show who got what.
                    $sel = $pdo->query(
                        "SELECT id, full_name, passport_number FROM crew WHERE id IN $inClause"
                    )->fetchAll();

                    $upd = $pdo->prepare(
                        "UPDATE crew
                            SET password_hash       = :h,
                                password_set_at     = CURRENT_TIMESTAMP,
                                crew_access_enabled = 1
                          WHERE id = :i"
                    );

                    foreach ($sel as $row) {
                        $plain = generateCrewPortalPassword();
                        $hash  = password_hash($plain, PASSWORD_BCRYPT);
                        $upd->execute([':h' => $hash, ':i' => (int)$row['id']]);
                        $generatedPasswords[] = [
                            'crew_id'  => (int)$row['id'],
                            'name'     => $row['full_name'],
                            'passport' => $row['passport_number'] ?? '',
                            'password' => $plain,
                        ];
                    }

                    logActivity(
                        $pdo, $user['id'], 'update', 'crew_password', null,
                        'Bulk-generated portal passwords for crew ids: ' . implode(',', $ids)
                    );
                    flash('success', count($generatedPasswords) . ' password(s) generated. Copy them now — they will not be shown again.');
                }

                $pdo->commit();
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('[SVSML-ERP] crew-portal-admin: ' . $e->getMessage());
                flash('error', 'Database error: ' . $e->getMessage());
            }
        }

        // Note: when $generatedPasswords is non-empty we deliberately do
        // NOT redirect — we need to render the printable list below the
        // form. The flash messages still fire on the same render.
        if (empty($generatedPasswords)) {
            header('Location: ' . url('crew-portal-admin.php'));
            exit;
        }
    }
}

// -------------------------------------------------------------
// Load master switch + crew portal state
// -------------------------------------------------------------
$sys = $pdo->query(
    "SELECT crew_self_login_enabled FROM system_settings ORDER BY id ASC LIMIT 1"
)->fetch();
$masterOn = $sys ? (int)$sys['crew_self_login_enabled'] === 1 : true;

// Filter / search.
$search    = trim($_GET['q'] ?? '');
$accessFilter = $_GET['access'] ?? ''; // '', enabled, disabled
$pwdFilter    = $_GET['pwd']    ?? ''; // '', set, unset

$where  = [];
$params = [];
if ($search !== '') {
    $where[] = "(cr.full_name LIKE :q OR cr.passport_number LIKE :q OR cr.indos_number LIKE :q)";
    $params[':q'] = '%' . $search . '%';
}
if ($accessFilter === 'enabled')  $where[] = 'cr.crew_access_enabled = 1';
if ($accessFilter === 'disabled') $where[] = 'cr.crew_access_enabled = 0';
if ($hasPasswordCol) {
    if ($pwdFilter === 'set')   $where[] = 'cr.password_hash IS NOT NULL';
    if ($pwdFilter === 'unset') $where[] = 'cr.password_hash IS NULL';
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Build the SELECT — only reference password columns when the schema
// actually has them so this page works on un-migrated databases.
$pwdColumns = $hasPasswordCol
    ? ', cr.password_hash, cr.password_set_at, cr.last_login_at'
    : ', NULL AS password_hash, NULL AS password_set_at, NULL AS last_login_at';

$rows = $pdo->prepare(
    "SELECT cr.id, cr.full_name, cr.passport_number, cr.indos_number,
            cr.crew_access_enabled, r.rank_name, v.vessel_name
            $pwdColumns
       FROM crew cr
       LEFT JOIN ranks   r ON r.id = cr.rank_id
       LEFT JOIN vessels v ON v.id = cr.vessel_id
       $whereSql
       ORDER BY cr.full_name"
);
$rows->execute($params);
$rows = $rows->fetchAll();

// Summary counts (across ALL crew, not just the filtered subset, so the
// header always reflects the true portal state).
$summary = $pdo->query(
    "SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN crew_access_enabled = 1 THEN 1 ELSE 0 END) AS access_on" .
        ($hasPasswordCol ? ", SUM(CASE WHEN password_hash IS NOT NULL THEN 1 ELSE 0 END) AS with_password" : ", 0 AS with_password") .
     " FROM crew"
)->fetch();

$pageTitle = 'Crew Portal Admin';
include __DIR__ . '/includes/header.php';
?>

<div class="card">
    <div class="toolbar">
        <h2 class="card-title" style="margin:0">Crew Portal Admin</h2>
        <a class="btn btn-ghost btn-sm" href="<?= asset('settings.php') ?>">System settings →</a>
    </div>

    <?php if (!$hasPasswordCol): ?>
        <div class="flash flash-warning">
            <strong>Database migration pending.</strong>
            The <code>password_hash</code> / <code>password_set_at</code> / <code>last_login_at</code>
            columns aren't on your <code>crew</code> table yet — please import
            <code>CHANGES.sql</code> before using this page.
        </div>
    <?php endif; ?>

    <p class="help-text">
        Three things must be true before a crew can sign in to the portal:
        (1) the master switch below is ON,
        (2) the crew has <strong>Access enabled</strong>,
        (3) the crew has a <strong>password set</strong>.
        Use the bulk actions to do (2) and (3) for many crew at once.
    </p>
</div>

<div class="card">
    <h3 class="card-title">Master switch</h3>
    <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="toggle_master">
        <div class="switch-row" style="padding:0;">
            <input type="checkbox" id="csl" name="crew_self_login_enabled" value="1" <?= $masterOn ? 'checked' : '' ?>>
            <label for="csl">
                Crew self-login enabled (system-wide)
                <?php if ($masterOn): ?>
                    <span class="status status-green" style="margin-left:8px;">ON</span>
                <?php else: ?>
                    <span class="status status-red" style="margin-left:8px;">OFF</span>
                <?php endif; ?>
            </label>
        </div>
        <p class="help-text">
            When OFF, no crew member can sign in regardless of their per-crew settings.
            This is the kill switch for the whole portal.
        </p>
        <div class="form-actions">
            <button type="submit" class="btn">Save master switch</button>
        </div>
    </form>
</div>

<?php if (!empty($generatedPasswords)): ?>
    <div class="card" style="border:2px solid var(--yellow); background:#fffbeb;">
        <h3 class="card-title" style="color:var(--yellow)">Generated passwords — copy now</h3>
        <p>
            <strong>These passwords are shown once.</strong> Copy or print this table
            and share each password securely with the corresponding crew member —
            they will not be shown again, and the system stores only a hash.
        </p>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Crew name</th>
                    <th>Passport (login id)</th>
                    <th>Password</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($generatedPasswords as $g): ?>
                    <tr>
                        <td><?= h($g['name']) ?></td>
                        <td><code><?= h($g['passport']) ?></code></td>
                        <td><code style="font-size:15px; letter-spacing:1px;"><?= h($g['password']) ?></code></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p class="help-text" style="margin-top:14px;">
            Suggest crew change to a personal password from the
            <a href="<?= asset('crew-portal-password.php') ?>">Change password</a> page after first sign-in.
        </p>
    </div>
<?php endif; ?>

<div class="card">
    <h3 class="card-title">
        Crew &mdash; portal state
        <small class="help-text">
            (<?= (int)($summary['access_on']     ?? 0) ?> with access · <?= (int)($summary['with_password'] ?? 0) ?> with password · <?= (int)($summary['total'] ?? 0) ?> total)
        </small>
    </h3>

    <form method="get" class="filter-bar" novalidate>
        <div class="form-row">
            <input type="search" name="q" value="<?= h($search) ?>"
                   placeholder="Name, passport or INDOS" autocomplete="off">
        </div>
        <div class="form-row">
            <select name="access">
                <option value=""         <?= $accessFilter === ''         ? 'selected' : '' ?>>Any access state</option>
                <option value="enabled"  <?= $accessFilter === 'enabled'  ? 'selected' : '' ?>>Access enabled</option>
                <option value="disabled" <?= $accessFilter === 'disabled' ? 'selected' : '' ?>>Access disabled</option>
            </select>
        </div>
        <?php if ($hasPasswordCol): ?>
            <div class="form-row">
                <select name="pwd">
                    <option value=""      <?= $pwdFilter === ''      ? 'selected' : '' ?>>Any password state</option>
                    <option value="set"   <?= $pwdFilter === 'set'   ? 'selected' : '' ?>>Password set</option>
                    <option value="unset" <?= $pwdFilter === 'unset' ? 'selected' : '' ?>>Password not set</option>
                </select>
            </div>
        <?php endif; ?>
        <div class="filter-actions">
            <button type="submit" class="btn btn-secondary">Apply</button>
            <a class="btn btn-ghost" href="<?= asset('crew-portal-admin.php') ?>">Reset</a>
        </div>
    </form>

    <?php if (empty($rows)): ?>
        <div class="empty-state">
            <h3>No crew match the filters</h3>
            <p>Add crew from the <a href="<?= asset('crew.php') ?>">Crew List</a>, or relax the filter above.</p>
        </div>
    <?php else: ?>
        <form method="post" id="bulk-form" novalidate>
            <?= csrfField() ?>

            <div class="toolbar" style="margin:0 0 10px 0;">
                <div class="travel-toolbar-actions">
                    <button type="submit" name="action" value="bulk_enable"  class="btn btn-secondary btn-sm">Enable access</button>
                    <button type="submit" name="action" value="bulk_disable" class="btn btn-secondary btn-sm"
                            data-confirm="Disable portal access for the selected crew?">Disable access</button>
                    <?php if ($hasPasswordCol): ?>
                        <button type="submit" name="action" value="bulk_passwords" class="btn"
                                data-confirm="Generate a fresh password for each selected crew? This OVERWRITES any existing password — they will not be able to sign in with the old one.">
                            Generate passwords (and enable)
                        </button>
                    <?php endif; ?>
                </div>
                <span class="help-text">
                    Tick rows below, then click an action. "Generate passwords" also flips Access on automatically.
                </span>
            </div>

            <table class="data-table">
                <thead>
                    <tr>
                        <th style="width:36px"><input type="checkbox" id="select-all" aria-label="Select all"></th>
                        <th>Name</th>
                        <th>Passport / INDOS</th>
                        <th>Rank / Vessel</th>
                        <th>Access</th>
                        <th>Password</th>
                        <th>Last login</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r):
                        $accessOn = (int)$r['crew_access_enabled'] === 1;
                        $hasPwd   = $hasPasswordCol && !empty($r['password_hash']);
                        $loginReady = $masterOn && $accessOn && $hasPwd;
                    ?>
                        <tr<?= $loginReady ? ' class="row-ready"' : '' ?>>
                            <td><input type="checkbox" name="crew_ids[]" value="<?= (int)$r['id'] ?>" class="row-pick"></td>
                            <td>
                                <a href="<?= asset('crew-edit.php?id=' . (int)$r['id']) ?>"><strong><?= h($r['full_name']) ?></strong></a>
                            </td>
                            <td>
                                <?= h($r['passport_number'] ?? '—') ?>
                                <?php if (!empty($r['indos_number'])): ?>
                                    <br><small class="help-text">INDOS <?= h($r['indos_number']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= h($r['rank_name']    ?? '—') ?>
                                <?php if (!empty($r['vessel_name'])): ?>
                                    <br><small class="help-text"><?= h($r['vessel_name']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($accessOn): ?>
                                    <span class="status status-green">Enabled</span>
                                <?php else: ?>
                                    <span class="status status-gray">Disabled</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!$hasPasswordCol): ?>
                                    <span class="muted">—</span>
                                <?php elseif ($hasPwd): ?>
                                    <span class="status status-green">Set</span>
                                    <?php if (!empty($r['password_set_at'])): ?>
                                        <br><small class="help-text"><?= h($r['password_set_at']) ?></small>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="status status-yellow">Not set</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($r['last_login_at'])): ?>
                                    <?= h($r['last_login_at']) ?>
                                <?php else: ?>
                                    <span class="muted">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </form>

        <p class="help-text" style="margin-top:14px;">
            Rows highlighted green are <strong>fully ready to log in</strong>: master ON + access enabled + password set.
        </p>
    <?php endif; ?>
</div>

<script>
(function () {
    var selectAll = document.getElementById('select-all');
    if (!selectAll) return;
    selectAll.addEventListener('change', function () {
        document.querySelectorAll('input.row-pick').forEach(function (cb) {
            cb.checked = selectAll.checked;
        });
    });
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
