<?php
/**
 * SVSML-ERP — Common helpers
 *
 *   h()            HTML-escape
 *   flash() / getFlashes()
 *   logActivity()  audit trail writer
 *   dateStatus()   green/yellow/red/gray classification
 *   uploadFile()   safe file upload with allow-list
 *   getSetting()   read system_settings / alert_settings
 */

/** Short HTML-escape helper for output. */
function h($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/* ---------------- Flash messages ---------------- */

function flash(string $type, string $message): void
{
    if (!isset($_SESSION['flash'])) $_SESSION['flash'] = [];
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

/** Returns and clears all queued flash messages. */
function getFlashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

/* ---------------- Activity log ------------------ */

/**
 * Write a row to staff_activity. Call this on every DB write
 * (create / update / delete / upload / generate).
 *
 * @param PDO         $pdo
 * @param int|null    $userId       users.id of the actor (null if system)
 * @param string      $actionType   one of: create|update|delete|upload|generate
 * @param string      $module       short module name e.g. 'crew', 'vessel'
 * @param int|null    $recordId     primary key of the affected row
 * @param string      $description  free-text description
 */
function logActivity(
    PDO $pdo,
    ?int $userId,
    string $actionType,
    string $module,
    ?int $recordId,
    string $description = ''
): void {
    $allowed = ['create', 'update', 'delete', 'upload', 'generate'];
    if (!in_array($actionType, $allowed, true)) {
        // fail soft — we never want a logging bug to break a write
        error_log("[SVSML-ERP] logActivity invalid action_type: $actionType");
        return;
    }
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO staff_activity
                (user_id, action_type, module, record_id, description, created_at)
             VALUES (:uid, :act, :mod, :rid, :desc, NOW())"
        );
        $stmt->execute([
            ':uid'  => $userId,
            ':act'  => $actionType,
            ':mod'  => $module,
            ':rid'  => $recordId,
            ':desc' => $description,
        ]);
    } catch (PDOException $e) {
        error_log('[SVSML-ERP] logActivity failed: ' . $e->getMessage());
    }
}

/* ---------------- Date status ------------------- */

/**
 * Classify an expiry date.
 *
 * Returns:
 *   ['class' => 'green'|'yellow'|'red'|'gray',
 *    'label' => 'Valid'|'Expiring Soon'|'Expired'|'Missing',
 *    'days'  => int|null  (days remaining; negative if expired)]
 */
function dateStatus(?string $expiry): array
{
    if (empty($expiry)) {
        return ['class' => 'gray', 'label' => 'Missing', 'days' => null];
    }
    $exp = DateTime::createFromFormat('Y-m-d', $expiry);
    if (!$exp) {
        return ['class' => 'gray', 'label' => 'Invalid', 'days' => null];
    }
    $today = new DateTime('today');
    $diff  = (int)$today->diff($exp)->format('%r%a'); // signed days
    if ($diff < 0)  return ['class' => 'red',    'label' => 'Expired',       'days' => $diff];
    if ($diff <= 30) return ['class' => 'yellow', 'label' => 'Expiring Soon', 'days' => $diff];
    return ['class' => 'green', 'label' => 'Valid', 'days' => $diff];
}

/* ---------------- File upload ------------------- */

/**
 * Move an uploaded file into a target directory with safe naming.
 *
 *   $_FILES key, target subdirectory (relative to /uploads),
 *   prefix used in the filename (e.g. 'passport'),
 *   crew/vessel id used in the filename.
 *
 * Returns the relative path under /uploads on success, or
 * throws RuntimeException on validation failure.
 */
function uploadFile(string $fileKey, string $subDir, string $prefix, int $ownerId): string
{
    if (empty($_FILES[$fileKey]) || !is_uploaded_file($_FILES[$fileKey]['tmp_name'] ?? '')) {
        throw new RuntimeException('No file uploaded.');
    }
    $f = $_FILES[$fileKey];
    if ($f['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload error code ' . (int)$f['error']);
    }
    if ($f['size'] > MAX_UPLOAD_BYTES) {
        throw new RuntimeException('File exceeds 10 MB maximum.');
    }
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ALLOWED_UPLOAD_EXT, true)) {
        throw new RuntimeException('File type not allowed. Allowed: ' . implode(', ', ALLOWED_UPLOAD_EXT));
    }

    $targetDir = rtrim(UPLOAD_DIR, '/') . '/' . trim($subDir, '/');
    if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
        throw new RuntimeException('Could not create upload directory.');
    }

    $safePrefix = preg_replace('/[^a-z0-9_-]/i', '', $prefix) ?: 'file';
    $name       = sprintf('%s_%d_%d.%s', $safePrefix, $ownerId, time(), $ext);
    $absPath    = $targetDir . '/' . $name;
    if (!move_uploaded_file($f['tmp_name'], $absPath)) {
        throw new RuntimeException('Could not move uploaded file.');
    }

    // Return path relative to /uploads (suitable for storing in DB).
    return trim($subDir, '/') . '/' . $name;
}

/* ---------------- Settings cache ---------------- */

/** Fetch the single-row system_settings as an associative array. */
function getSystemSettings(PDO $pdo): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $row = $pdo->query("SELECT * FROM system_settings ORDER BY id ASC LIMIT 1")->fetch();
    return $cache = $row ?: [];
}

/** Fetch the single-row alert_settings as an associative array. */
function getAlertSettings(PDO $pdo): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $row = $pdo->query("SELECT * FROM alert_settings ORDER BY id ASC LIMIT 1")->fetch();
    return $cache = $row ?: [];
}
