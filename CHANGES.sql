-- =============================================================
-- SVSML-ERP — Database changes
--
-- Branch: module-9-fix-10-to-16-complete
-- Apply this AFTER the previously-deployed schema.sql + seed.sql.
--
-- Modules covered by this branch:
--   * Module 9 bug fix (Travel date/time picker) — NO DB CHANGES.
--     travel_details.departure / arrival are already VARCHAR(100), so
--     the new "YYYY-MM-DD HH:MM" canonical storage value fits.
--   * Module 10 Client Approval     — uses existing client_approvals.
--   * Module 11 SVSML Approval      — uses existing svsml_approvals.
--   * Module 12 Quick Approval      — uses existing quick_approvals
--                                     + quick_approval_items.
--   * Module 13 Staff Activity Log  — uses existing staff_activity.
--   * Module 14 Dashboard           — query-only.
--   * Module 15 Expiry Alerts       — query-only.
--   * Module 16 Crew Login          — REQUIRES the columns added below.
--
-- The dynamic-SQL pattern below is portable across MySQL 5.7 / 8.0 and
-- MariaDB 10+. Re-running this file is safe (idempotent) — already-added
-- columns are skipped.
-- =============================================================

SET NAMES utf8mb4;

-- -------------------------------------------------------------
-- Module 16 — Crew portal authentication
--
-- crew.password_hash    : bcrypt hash; set/cleared by admin from
--                         crew-edit.php, or by the crew themselves
--                         from crew-portal.php.
-- crew.password_set_at  : audit timestamp of last password change.
-- crew.last_login_at    : audit timestamp of last successful crew login.
-- -------------------------------------------------------------

-- password_hash ------------------------------------------------
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'crew'
       AND COLUMN_NAME  = 'password_hash'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `crew` ADD COLUMN `password_hash` VARCHAR(255) NULL DEFAULT NULL AFTER `crew_access_enabled`',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- password_set_at ---------------------------------------------
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'crew'
       AND COLUMN_NAME  = 'password_set_at'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `crew` ADD COLUMN `password_set_at` TIMESTAMP NULL DEFAULT NULL AFTER `password_hash`',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- last_login_at -----------------------------------------------
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'crew'
       AND COLUMN_NAME  = 'last_login_at'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `crew` ADD COLUMN `last_login_at` TIMESTAMP NULL DEFAULT NULL AFTER `password_set_at`',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- =============================================================
-- After running this file:
--   1. Log in as admin.
--   2. Open any crew profile.
--   3. Tick the "Allow this crew to sign in to the portal" checkbox.
--   4. Use the new "Crew Portal Password" card to set a password.
--   5. Share the password securely with the crew member.
--
-- The crew can then sign in at /crew-login.php using their
-- passport number + the password you set, and will land on
-- /crew-portal.php (read-only self-service view).
-- =============================================================
