<?php
/**
 * SVSML-ERP — Crew upload path helpers
 *
 * Implements the canonical per-crew folder structure:
 *
 *     uploads/{Company_Name}/{Crew_Name}_{Rank}/
 *         {Rank}_{Crew_Name}_{DocType}.{ext}
 *
 * All path components are filesystem-slugified (alpha-numeric only,
 * no spaces) so a company called "Maersk Line A/S" lands in
 * `uploads/MaerskLineAS/...`.
 *
 * Backwards compatibility: the previous `uploads/crew/{crew_id}/...`
 * layout is still readable. Existing rows in the DB hold paths in
 * that old form; the helpers below only affect NEW writes. When the
 * operator changes a crew's company or rank, `relocateCrewUploads()`
 * moves the folder and rewrites every file_path column that points
 * at it, so the next page render still finds the files.
 */

/**
 * Filesystem-slugify a string. Strips everything except [A-Za-z0-9],
 * collapses internal whitespace, and clamps to a sensible length.
 * Returns a non-empty fallback when the input is empty.
 */
function fsSlug(?string $value, string $fallback = 'unknown', int $maxLen = 60): string
{
    $v = trim((string)$value);
    if ($v === '') return $fallback;

    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $v);
        if ($converted !== false) $v = $converted;
    }
    $v = preg_replace('/[^A-Za-z0-9]+/', '', $v);
    if ($v === '' || $v === null) return $fallback;
    if (mb_strlen($v) > $maxLen) $v = mb_substr($v, 0, $maxLen);
    return $v;
}

/**
 * Returns the company-level upload directory (relative to the project
 * uploads root) for a given crew row. Creates the directory on disk
 * if it doesn't exist already.
 */
function ensureCompanyUploadDir(?string $companyName): string
{
    $slug = fsSlug($companyName, '__noCompany__');
    $rel  = $slug;
    $abs  = rtrim(UPLOAD_DIR, '/') . '/' . $rel;
    if (!is_dir($abs)) @mkdir($abs, 0775, true);
    return $rel;
}

/**
 * Returns the per-crew folder path (relative to the uploads root)
 * for the supplied crew row, creating both the company and crew
 * folders on disk if needed.
 *
 *   uploads/{Company}/{CrewName}_{Rank}
 */
function ensureCrewUploadDir(array $crew): string
{
    $companyRel = ensureCompanyUploadDir($crew['company_name'] ?? null);

    $name = fsSlug($crew['full_name'] ?? '', 'crew' . (int)($crew['id'] ?? 0));
    $rank = fsSlug($crew['rank_name'] ?? '', 'NoRank');

    $crewFolder = $name . '_' . $rank;
    $rel        = $companyRel . '/' . $crewFolder;
    $abs        = rtrim(UPLOAD_DIR, '/') . '/' . $rel;
    if (!is_dir($abs)) @mkdir($abs, 0775, true);
    return $rel;
}

/**
 * Compute (without creating) the relative crew folder for a row.
 * Used by relocateCrewUploads() to decide whether a move is needed.
 */
function computeCrewUploadDir(array $crew): string
{
    $company = fsSlug($crew['company_name'] ?? null, '__noCompany__');
    $name    = fsSlug($crew['full_name']    ?? '', 'crew' . (int)($crew['id'] ?? 0));
    $rank    = fsSlug($crew['rank_name']    ?? '', 'NoRank');
    return $company . '/' . $name . '_' . $rank;
}

/**
 * Construct the canonical filename for a crew document of a given
 * type and extension:
 *
 *     {Rank}_{CrewName}_{DocType}.{ext}
 */
function buildCrewUploadFilename(array $crew, string $docType, string $ext): string
{
    $name = fsSlug($crew['full_name'] ?? '', 'crew' . (int)($crew['id'] ?? 0));
    $rank = fsSlug($crew['rank_name'] ?? '', 'NoRank');
    $type = fsSlug($docType, 'File', 40);
    $ext  = strtolower(preg_replace('/[^a-z0-9]/i', '', $ext) ?? '');
    if ($ext === '') $ext = 'bin';
    return $rank . '_' . $name . '_' . $type . '.' . $ext;
}

/**
 * Move and overwrite a previously-uploaded $_FILES entry into the
 * canonical per-crew folder. Returns the relative path (suitable for
 * storage in *.file_path columns) or throws RuntimeException on
 * validation / I/O failure.
 *
 * If a file already exists at the target path, it's overwritten —
 * "Replace file if same doc type uploaded again" per the spec.
 */
function saveCrewUpload(string $field, array $crew, string $docType, bool $optional = true, ?string $subfolder = null): ?string
{
    if (!isset($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
        if ($optional) return null;
        throw new RuntimeException('No file selected.');
    }
    $f = $_FILES[$field];
    if ($f['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload error code: ' . (int)$f['error']);
    }
    if ($f['size'] <= 0) throw new RuntimeException('Empty file.');
    if ($f['size'] > UPLOAD_MAX_SIZE) {
        throw new RuntimeException('File too large (max ' . UPLOAD_MAX_SIZE . ' bytes).');
    }

    $allowed = ['pdf','jpg','jpeg','png','docx'];
    $orig    = $f['name'] ?? 'file';
    $ext     = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) {
        throw new RuntimeException('File type "' . $ext . '" is not allowed (use ' . implode(', ', $allowed) . ').');
    }

    $relDir   = ensureCrewUploadDir($crew);
    if ($subfolder !== null && $subfolder !== '') {
        $sub = fsSlug($subfolder, 'sub', 40);
        $relDir .= '/' . $sub;
        $absSub  = rtrim(UPLOAD_DIR, '/') . '/' . $relDir;
        if (!is_dir($absSub)) @mkdir($absSub, 0775, true);
    }

    $filename = buildCrewUploadFilename($crew, $docType, $ext);
    $relPath  = $relDir . '/' . $filename;
    $absPath  = rtrim(UPLOAD_DIR, '/') . '/' . $relPath;

    if (is_file($absPath)) @unlink($absPath);
    if (!@move_uploaded_file($f['tmp_name'], $absPath)) {
        throw new RuntimeException('Could not save file to disk.');
    }
    @chmod($absPath, 0664);
    return $relPath;
}

/**
 * Move a crew's per-crew folder when their company / rank changes,
 * AND rewrite every column that stores a file_path pointing at the
 * old folder so the application keeps finding the files.
 *
 * Safe to call when the move is a no-op (same source and target).
 * Failures during the move are logged via error_log but do not
 * propagate (we never want a folder-rename hiccup to abort a
 * profile save).
 */
function relocateCrewUploads(PDO $pdo, array $oldCrew, array $newCrew): void
{
    $oldRel = computeCrewUploadDir($oldCrew);
    $newRel = computeCrewUploadDir($newCrew);
    if ($oldRel === $newRel) return;

    $base    = rtrim(UPLOAD_DIR, '/');
    $oldAbs  = $base . '/' . $oldRel;
    $newAbs  = $base . '/' . $newRel;

    if (is_dir($oldAbs)) {
        $newParent = dirname($newAbs);
        if (!is_dir($newParent)) @mkdir($newParent, 0775, true);

        if (is_dir($newAbs)) {
            // Target already exists — merge files in.
            foreach (scandir($oldAbs) ?: [] as $f) {
                if ($f === '.' || $f === '..') continue;
                @rename($oldAbs . '/' . $f, $newAbs . '/' . $f);
            }
            @rmdir($oldAbs);
        } else {
            if (!@rename($oldAbs, $newAbs)) {
                error_log('[SVSML-ERP] relocateCrewUploads rename failed: ' . $oldAbs . ' -> ' . $newAbs);
                return;
            }
        }
    }

    $crewId = (int)($newCrew['id'] ?? $oldCrew['id'] ?? 0);
    if ($crewId <= 0) return;

    $tables = [
        ['crew_documents',  'file_path', 'crew_id'],
        ['crew_medical',    'file_path', 'crew_id'],
        ['basic_courses',   'file_path', 'crew_id'],
        ['advanced_courses','file_path', 'crew_id'],
        ['travel_details',  'file_path', 'crew_id'],
    ];
    foreach ($tables as $t) {
        [$tbl, $col, $fk] = $t;
        try {
            $sel = $pdo->prepare("SELECT id, $col FROM $tbl WHERE $fk = :c AND $col LIKE :p");
            $sel->execute([':c' => $crewId, ':p' => $oldRel . '%']);
            $upd = $pdo->prepare("UPDATE $tbl SET $col = :v WHERE id = :i");
            foreach ($sel as $r) {
                $newVal = $newRel . substr((string)$r[$col], strlen($oldRel));
                $upd->execute([':v' => $newVal, ':i' => $r['id']]);
            }
        } catch (PDOException $e) {
            error_log('[SVSML-ERP] relocateCrewUploads rewrite ' . $tbl . ': ' . $e->getMessage());
        }
    }

    // Profile photo (lives on crew row directly).
    try {
        $sel = $pdo->prepare("SELECT profile_photo FROM crew WHERE id = :i");
        $sel->execute([':i' => $crewId]);
        $cur = $sel->fetchColumn();
        if (is_string($cur) && $cur !== '' && strpos($cur, $oldRel) === 0) {
            $newVal = $newRel . substr($cur, strlen($oldRel));
            $upd = $pdo->prepare("UPDATE crew SET profile_photo = :v WHERE id = :i");
            $upd->execute([':v' => $newVal, ':i' => $crewId]);
        }
    } catch (PDOException $e) {
        error_log('[SVSML-ERP] relocateCrewUploads profile_photo: ' . $e->getMessage());
    }
}
