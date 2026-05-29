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

/* ---------------- CSRF protection ---------------- */

/**
 * Get (or generate) a per-session CSRF token. Used by every form
 * that performs a state-changing POST.
 */
function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Render the hidden CSRF input. Drop this inside every <form method="post">.
 */
function csrfField(): string
{
    return '<input type="hidden" name="_csrf" value="'
         . htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') . '">';
}

/**
 * Verify the CSRF token on a POST. Aborts with 403 on mismatch.
 * Call this once at the top of any POST handler.
 */
function verifyCsrf(): void
{
    $sent     = $_POST['_csrf'] ?? '';
    $expected = $_SESSION['csrf_token'] ?? '';
    if (!is_string($sent) || $expected === '' || !hash_equals($expected, $sent)) {
        http_response_code(403);
        exit('CSRF token mismatch. Reload the page and try again.');
    }
}

/* ---------------- Dropdown helpers ---------------- */

/**
 * The canonical list of dropdown categories the ERP knows about,
 * in the order they should appear in admin UIs.
 *
 * Returns an associative array of slug => human-readable label.
 */
function getDropdownCategories(): array
{
    return [
        'visa_type'             => 'Visa Type',
        'visa_country'          => 'Visa Country',
        'medical_type'          => 'Medical Type',
        'ship_type'             => 'Ship Type',
        'ship_flag'             => 'Ship Flag',
        'approval_description'  => 'Approval Description',
        'contract_period'       => 'Contract Period',
        'relationship'          => 'Relationship',
    ];
}

/**
 * Fetch all dropdown_items in a category.
 *
 *   $activeOnly = true  → only is_active=1 rows (use in user-facing selects)
 *   $activeOnly = false → all rows (use in admin pages)
 *
 * Sorted alphabetically with an "Other" suffix pinned to the end,
 * which matches the seed convention.
 */
function getDropdownOptions(PDO $pdo, string $category, bool $activeOnly = true): array
{
    $sql = "SELECT id, label, is_active
              FROM dropdown_items
             WHERE category = :c"
         . ($activeOnly ? " AND is_active = 1" : "")
         . " ORDER BY (label = 'Other'), label";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':c' => $category]);
    return $stmt->fetchAll();
}

/**
 * Render an HTML <select> populated from a dropdown_items category.
 *
 *   $name      → form field name
 *   $category  → dropdown category slug (e.g. 'ship_type')
 *   $selectedId → currently-selected dropdown_items.id (or null)
 *   $opts      → ['blank' => 'Choose...', 'required' => true, 'id' => 'foo']
 *
 * Used by every later module that renders a category-driven select.
 */
function dropdownSelect(PDO $pdo, string $name, string $category, $selectedId = null, array $opts = []): string
{
    $rows  = getDropdownOptions($pdo, $category, true);
    $blank = $opts['blank']    ?? '— Select —';
    $req   = !empty($opts['required']) ? ' required' : '';
    $id    = $opts['id']       ?? $name;

    $html  = '<select name="' . h($name) . '" id="' . h($id) . '"' . $req . '>';
    $html .= '<option value="">' . h($blank) . '</option>';
    foreach ($rows as $r) {
        $sel = ((string)$r['id'] === (string)$selectedId) ? ' selected' : '';
        $html .= '<option value="' . (int)$r['id'] . '"' . $sel . '>' . h($r['label']) . '</option>';
    }
    $html .= '</select>';
    return $html;
}

/**
 * Render an HTML <select> whose option values are the dropdown_items
 * LABELS (not ids). Used for fields stored as VARCHAR — e.g. vessels.ship_type
 * and vessels.ship_flag — where the label is what gets persisted.
 */
function dropdownSelectByLabel(PDO $pdo, string $name, string $category, ?string $selectedLabel = null, array $opts = []): string
{
    $rows  = getDropdownOptions($pdo, $category, true);
    $blank = $opts['blank']    ?? '— Select —';
    $req   = !empty($opts['required']) ? ' required' : '';
    $id    = $opts['id']       ?? $name;

    $html  = '<select name="' . h($name) . '" id="' . h($id) . '"' . $req . '>';
    $html .= '<option value="">' . h($blank) . '</option>';
    foreach ($rows as $r) {
        $sel = ($selectedLabel !== null && $r['label'] === $selectedLabel) ? ' selected' : '';
        $html .= '<option value="' . h($r['label']) . '"' . $sel . '>' . h($r['label']) . '</option>';
    }
    $html .= '</select>';
    return $html;
}

/* ---------------- Pagination ---------------- */

/**
 * Render compact pagination links.
 *
 *   $page         current page (1-indexed)
 *   $totalPages   computed as ceil(totalRows / PAGE_SIZE)
 *   $baseUrl      URL to link to, with all current query params except 'page'
 *                 (e.g. url('crew.php?company_id=3') — pagination appends
 *                 &page=2 / ?page=2 as appropriate)
 *
 * Returns '' when there is only one page.
 */
function paginate(int $page, int $totalPages, string $baseUrl): string
{
    if ($totalPages <= 1) return '';
    $page = max(1, min($page, $totalPages));
    $sep  = (strpos($baseUrl, '?') === false) ? '?' : '&';

    $link = function (int $p, string $label, bool $disabled = false, bool $current = false) use ($baseUrl, $sep): string {
        if ($current)  return '<span class="current">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
        if ($disabled) return '<span class="disabled">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
        return '<a href="' . htmlspecialchars($baseUrl . $sep . 'page=' . $p, ENT_QUOTES, 'UTF-8') . '">'
             . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</a>';
    };

    // Window of 5 page numbers around the current page.
    $start = max(1, $page - 2);
    $end   = min($totalPages, $start + 4);
    if ($end - $start < 4) $start = max(1, $end - 4);

    $out  = '<nav class="pagination" aria-label="Pagination">';
    $out .= $link($page - 1, '« Prev', $page <= 1);
    if ($start > 1) {
        $out .= $link(1, '1');
        if ($start > 2) $out .= '<span class="disabled">…</span>';
    }
    for ($p = $start; $p <= $end; $p++) {
        $out .= $link($p, (string)$p, false, $p === $page);
    }
    if ($end < $totalPages) {
        if ($end < $totalPages - 1) $out .= '<span class="disabled">…</span>';
        $out .= $link($totalPages, (string)$totalPages);
    }
    $out .= $link($page + 1, 'Next »', $page >= $totalPages);
    $out .= '</nav>';
    return $out;
}



/* ---------------- Size dropdown helpers ---------------- */

/** Clothing sizes used for boiler suit / shirt. */
function clothingSizeOptions(): array
{
    return ['XS', 'S', 'M', 'L', 'XL', 'XXL', 'XXXL'];
}

/** Numeric sizes used for safety shoes / pants. */
function numericSizeOptions(): array
{
    return ['28', '30', '32', '34', '36', '38', '40', '42', '44', '46'];
}

/**
 * Render an HTML <select> backed by a fixed list of size strings.
 * Preserves legacy values that aren't in the preset list by appending
 * them as an extra option, so editing an old crew record never loses data.
 */
function renderSizeSelect(string $name, array $options, ?string $selected, string $id = ''): string
{
    $id = $id !== '' ? $id : $name;
    $html = '<select name="' . h($name) . '" id="' . h($id) . '">';
    $html .= '<option value="">— Select —</option>';
    $found = false;
    foreach ($options as $opt) {
        $sel = ((string)$selected === (string)$opt) ? ' selected' : '';
        if ($sel) $found = true;
        $html .= '<option value="' . h($opt) . '"' . $sel . '>' . h($opt) . '</option>';
    }
    if (!$found && $selected !== null && $selected !== '') {
        // Preserve any legacy value typed before this dropdown existed.
        $html .= '<option value="' . h($selected) . '" selected>' . h($selected) . '</option>';
    }
    $html .= '</select>';
    return $html;
}

/* ---------------- Crew helpers ---------------- */

/**
 * Fetch a crew row joined with its rank / company / vessel labels.
 * Returns the row array or null if not found.
 */
function fetchCrewWithJoins(PDO $pdo, int $crewId): ?array
{
    $stmt = $pdo->prepare(
        "SELECT cr.*,
                r.rank_name,
                c.company_name,
                v.vessel_name
           FROM crew cr
           LEFT JOIN ranks     r ON r.id = cr.rank_id
           LEFT JOIN companies c ON c.id = cr.company_id
           LEFT JOIN vessels   v ON v.id = cr.vessel_id
          WHERE cr.id = :i
          LIMIT 1"
    );
    $stmt->execute([':i' => $crewId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/* ---------------- Default course seeding ---------------- */

/** Default basic course names seeded for every new crew. */
function defaultBasicCourseNames(): array
{
    return ['BST', 'PST', 'PSSR', 'FPFF', 'EFA', 'STSDSD', 'Security Awareness'];
}

/** Default advanced course names seeded for every new crew. */
function defaultAdvancedCourseNames(): array
{
    return ['AFF', 'MFA', 'PSCRB', 'BRM/ERM', 'RADAR/ARPA', 'ECDIS',
            'Tanker Advanced', 'BOSIET/OGUK', 'Food Handling', 'H2S'];
}

/**
 * Seed the standard set of basic + advanced courses for a crew, but only
 * if the crew has no rows in the corresponding table yet (idempotent).
 *
 * Called from crew-edit.php on INSERT, and as a lazy-seed safety net
 * from crew-courses.php on first view (handles crew records that were
 * created before Module 5 was deployed).
 */
function seedDefaultCoursesForCrew(PDO $pdo, int $crewId, ?int $userId): void
{
    $basicCount = (int)$pdo->query(
        "SELECT COUNT(*) FROM basic_courses WHERE crew_id = " . $crewId
    )->fetchColumn();
    if ($basicCount === 0) {
        $stmt = $pdo->prepare(
            "INSERT INTO basic_courses (crew_id, course_name, created_by, created_at)
             VALUES (:c, :n, :u, NOW())"
        );
        foreach (defaultBasicCourseNames() as $n) {
            $stmt->execute([':c' => $crewId, ':n' => $n, ':u' => $userId]);
        }
    }

    $advCount = (int)$pdo->query(
        "SELECT COUNT(*) FROM advanced_courses WHERE crew_id = " . $crewId
    )->fetchColumn();
    if ($advCount === 0) {
        $stmt = $pdo->prepare(
            "INSERT INTO advanced_courses (crew_id, course_name, created_by, created_at)
             VALUES (:c, :n, :u, NOW())"
        );
        foreach (defaultAdvancedCourseNames() as $n) {
            $stmt->execute([':c' => $crewId, ':n' => $n, ':u' => $userId]);
        }
    }
}

/* ---------------- Misc rendering helpers ---------------- */

/** Render a small status badge for a date (issue/expiry). */
function dateStatusBadge(?string $expiry): string
{
    $s = dateStatus($expiry);
    return '<span class="status status-' . h($s['class']) . '" title="'
         . h($expiry ?? '—') . '">' . h($s['label']) . '</span>';
}

/** Compute days between two YYYY-MM-DD dates inclusive. Returns null if either is empty/invalid. */
function daysBetween(?string $from, ?string $to): ?int
{
    if (empty($from) || empty($to)) return null;
    $d1 = DateTime::createFromFormat('Y-m-d', $from);
    $d2 = DateTime::createFromFormat('Y-m-d', $to);
    if (!$d1 || !$d2) return null;
    $diff = (int)$d1->diff($d2)->format('%r%a');
    return abs($diff) + 1;
}
