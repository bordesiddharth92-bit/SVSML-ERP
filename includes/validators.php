<?php
/**
 * SVSML-ERP — Field validators
 *
 * Server-side validation rules for the identity fields the operator
 * captures while creating / editing crew, documents, and contracts:
 *
 *   - Passport number    — relaxed: "[A-Z0-9\-\s]{5,20}", case-insensitive.
 *                          (Country-specific patterns kept as a doc-only
 *                          extension point; not enforced.)
 *   - Mobile number      — international E.164
 *   - Email              — standard format check (no MX / OTP / 3rd-party)
 *   - CDC number         — "[A-Z0-9]{5,20}"
 *   - INDOS number       — "[0-9]{2}[A-Z]{2}[0-9]{4}", required for India, unique
 *
 * Each validator returns null on success or a human-readable error
 * message on failure. Normalisers run BEFORE validation so trimming,
 * uppercasing, and structural cleanup (e.g. stripping spaces / dashes
 * from mobile numbers) is applied consistently — the value passed to
 * the validator is the same value we'll persist to the database.
 *
 * Companion file: assets/js/validators.js — same rules client-side.
 */

// =========================================================
// Normalisers — apply BEFORE storing AND before validating
// =========================================================

/** Uppercase + trim. Used for passport / CDC / INDOS. */
function normalizeUpperTrim(?string $v): string
{
    return strtoupper(trim((string)$v));
}

/** Lowercase + trim. Used for email. */
function normalizeEmailValue(?string $v): string
{
    return strtolower(trim((string)$v));
}

/**
 * Strip the formatting characters operators commonly type into mobile
 * numbers (spaces, dashes, parentheses) and trim. The leading '+' and
 * digits are preserved verbatim so E.164 validation still bites.
 */
function normalizeMobileValue(?string $v): string
{
    $v = trim((string)$v);
    return preg_replace('/[\s\-()]+/', '', $v) ?? '';
}

// =========================================================
// Country-specific passport formats (extension point)
//
// Keyed on uppercased ISO 3166-1 alpha-2, alpha-3 and full country
// names (so the lookup is forgiving regardless of how nationality is
// stored in the form). Add new entries as the team identifies more
// country formats — the global regex is the fallback when the country
// isn't in this map.
// =========================================================
const COUNTRY_PASSPORT_FORMATS = [
    'IN'    => '/^[A-Z][0-9]{7}$/',          // India: 1 letter + 7 digits
    'IND'   => '/^[A-Z][0-9]{7}$/',
    'INDIA' => '/^[A-Z][0-9]{7}$/',

    'US'    => '/^[A-Z0-9]{6,9}$/',          // US: 6-9 alphanumeric
    'USA'   => '/^[A-Z0-9]{6,9}$/',
    'UNITED STATES'             => '/^[A-Z0-9]{6,9}$/',
    'UNITED STATES OF AMERICA'  => '/^[A-Z0-9]{6,9}$/',

    'GB'             => '/^[0-9]{9}$/',      // UK: 9 digits
    'GBR'            => '/^[0-9]{9}$/',
    'UK'             => '/^[0-9]{9}$/',
    'UNITED KINGDOM' => '/^[0-9]{9}$/',

    'AE'                    => '/^[A-Z0-9]{8,9}$/',  // UAE
    'ARE'                   => '/^[A-Z0-9]{8,9}$/',
    'UNITED ARAB EMIRATES'  => '/^[A-Z0-9]{8,9}$/',

    'PH'          => '/^[A-Z]{2}[0-9]{7}$/', // Philippines: 2 letters + 7 digits
    'PHL'         => '/^[A-Z]{2}[0-9]{7}$/',
    'PHILIPPINES' => '/^[A-Z]{2}[0-9]{7}$/',
];

// =========================================================
// Validators — return null on success, error string on failure
// =========================================================

/**
 * Validate a passport number.
 *
 * Per the relaxed spec, passport numbers are accepted as 5–20 chars of
 * letters / digits / hyphens / spaces. Real-world passport books for
 * many countries embed hyphens (e.g. UK "123-456-789") and SVSML's
 * operations team needs the form to accept the value as printed.
 *
 * The country-specific patterns in COUNTRY_PASSPORT_FORMATS are kept
 * in this file as a documented extension point but are deliberately
 * NOT applied by this validator — they were rejecting real passports
 * during onboarding. Any future stricter-by-country mode should call
 * those patterns directly rather than going through this function.
 *
 * @param string|null $value    raw operator input
 * @param string|null $country  retained for API compatibility; ignored
 * @param bool        $required true to also enforce non-empty
 */
function validatePassportField(?string $value, ?string $country = null, bool $required = false): ?string
{
    $v = normalizeUpperTrim($value);
    if ($v === '') {
        return $required ? 'Passport number is required.' : null;
    }
    // Universal relaxed pattern: letters / digits / hyphens / spaces,
    // 5–20 chars. The /i flag is harmless because $v is already
    // upper-cased by normalizeUpperTrim().
    return preg_match('/^[A-Z0-9\-\s]{5,20}$/i', $v)
        ? null
        : 'Invalid passport number format.';
}

/** Validate an E.164 mobile number. */
function validateMobileField(?string $value, bool $required = false): ?string
{
    $v = normalizeMobileValue($value);
    if ($v === '') {
        return $required ? 'Mobile number is required.' : null;
    }
    return preg_match('/^\+[1-9]\d{5,14}$/', $v)
        ? null
        : 'Please enter a valid mobile number.';
}

/** Validate an email address (format only — no MX / OTP / 3rd-party). */
function validateEmailField(?string $value, bool $required = false): ?string
{
    $v = normalizeEmailValue($value);
    if ($v === '') {
        return $required ? 'Email is required.' : null;
    }
    return preg_match('/^[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}$/', $v)
        ? null
        : 'Please enter a valid email address.';
}

/** Validate a CDC (Continuous Discharge Certificate) number. */
function validateCDCField(?string $value, bool $required = false): ?string
{
    $v = normalizeUpperTrim($value);
    if ($v === '') {
        return $required ? 'CDC number is required.' : null;
    }
    return preg_match('/^[A-Z0-9]{5,20}$/', $v)
        ? null
        : 'Invalid CDC number format.';
}

/**
 * Validate an INDOS number.
 *
 * @param bool $required true when nationality is India (then the
 *                       field is mandatory). Outside India INDOS is
 *                       optional but, if supplied, must still match
 *                       the format.
 */
function validateINDOSField(?string $value, bool $required = false): ?string
{
    $v = normalizeUpperTrim($value);
    if ($v === '') {
        return $required ? 'INDOS number is required for Indian nationality.' : null;
    }
    return preg_match('/^[0-9]{2}[A-Z]{2}[0-9]{4}$/', $v)
        ? null
        : 'Please enter a valid INDOS number.';
}

// =========================================================
// Date checks (used for CDC / documents / courses / medical)
// =========================================================

/** Issue date cannot be in the future. */
function validateIssueDate(?string $issue): ?string
{
    if ($issue === null || trim($issue) === '') return null;
    try {
        $d = new DateTime($issue);
    } catch (Exception $e) {
        return 'Invalid issue date.';
    }
    $today = new DateTime('today');
    if ($d > $today) return 'Issue Date cannot be in the future.';
    return null;
}

/** Expiry date must be strictly after the issue date (when both supplied). */
function validateExpiryAfterIssue(?string $expiry, ?string $issue): ?string
{
    if (empty($expiry) || empty($issue)) return null;
    try {
        $i = new DateTime($issue);
        $e = new DateTime($expiry);
    } catch (Exception $ex) {
        return 'Invalid expiry / issue date.';
    }
    if ($e <= $i) return 'Expiry Date must be later than Issue Date.';
    return null;
}

// =========================================================
// Uniqueness helpers (DB-aware)
// =========================================================

/**
 * Returns true when the supplied INDOS doesn't collide with any other
 * crew row. An empty value is "unique" by definition (caller decides
 * whether emptiness is allowed via validateINDOSField).
 *
 * @param int|null $excludeCrewId pass the crew id when editing so the
 *                                row's own current value doesn't count
 *                                as a collision.
 */
function isINDOSUnique(PDO $pdo, ?string $indos, ?int $excludeCrewId = null): bool
{
    $v = normalizeUpperTrim($indos);
    if ($v === '') return true;
    $sql = "SELECT id FROM crew WHERE indos_number = :v";
    $params = [':v' => $v];
    if ($excludeCrewId !== null) {
        $sql .= " AND id <> :ex";
        $params[':ex'] = $excludeCrewId;
    }
    $sql .= " LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return !$stmt->fetch();
}

/** Same shape as isINDOSUnique() but for passport_number. */
function isPassportUnique(PDO $pdo, ?string $passport, ?int $excludeCrewId = null): bool
{
    $v = normalizeUpperTrim($passport);
    if ($v === '') return true;
    $sql = "SELECT id FROM crew WHERE passport_number = :v";
    $params = [':v' => $v];
    if ($excludeCrewId !== null) {
        $sql .= " AND id <> :ex";
        $params[':ex'] = $excludeCrewId;
    }
    $sql .= " LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return !$stmt->fetch();
}
