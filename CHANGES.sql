-- =============================================================
-- SVSML-ERP — Database changes
--
-- Apply this AFTER the previously-deployed schema.sql + seed.sql.
-- The dynamic-SQL pattern below is portable across MySQL 5.7 / 8.0
-- and MariaDB 10+, idempotent on re-run.
--
-- Modules covered by this file:
--   * Module 9  Travel Details            uses existing travel_details table
--   * Module 10 Client Approval           uses existing client_approvals table
--   * Module 11 SVSML Approval            uses existing svsml_approvals table
--   * Module 12 Quick Approval            uses existing quick_approvals + items
--   * Module 13 Staff Activity Log        uses existing staff_activity table
--   * Module 14 Dashboard                 query-only
--   * Module 15 Expiry Alerts             query-only
--   * Module 16 Crew Login                ALTER `crew` ADD password columns
--   * Module 18 Crew Onboarding           ALTER `crew` ADD onboarding + bank
--                                         + next_of_kin + profile_photo
-- =============================================================

SET NAMES utf8mb4;

-- -------------------------------------------------------------
-- helper: run a single ALTER only when the column is missing
-- -------------------------------------------------------------
-- (We inline the same pattern below per column for portability —
--  MySQL 5.7 doesn't support stored procedures inside CALL chains
--  cleanly when the file is fed via phpMyAdmin import.)

-- =============================================================
-- Module 16 — Crew portal authentication
-- =============================================================

SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crew' AND COLUMN_NAME = 'password_hash');
SET @sql = IF(@col = 0,
    'ALTER TABLE `crew` ADD COLUMN `password_hash` VARCHAR(255) NULL DEFAULT NULL AFTER `crew_access_enabled`',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crew' AND COLUMN_NAME = 'password_set_at');
SET @sql = IF(@col = 0,
    'ALTER TABLE `crew` ADD COLUMN `password_set_at` TIMESTAMP NULL DEFAULT NULL AFTER `password_hash`',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crew' AND COLUMN_NAME = 'last_login_at');
SET @sql = IF(@col = 0,
    'ALTER TABLE `crew` ADD COLUMN `last_login_at` TIMESTAMP NULL DEFAULT NULL AFTER `password_set_at`',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- =============================================================
-- Module 18 — Crew first-time onboarding form
--
-- onboarding_complete : 1 once the crew has finished the multi-step
--                       onboarding form. Until then, /crew-portal*.php
--                       redirect them to /crew-onboarding.php.
-- expected_departure_date / contract_period_months :
--                       captured during onboarding so the operations
--                       team can plan the joining tour.
-- profile_photo       : avatar shown in the crew portal hero block.
-- bank_*              : payroll / reimbursement details.
-- next_of_kin         : JSON array of up to 2 next-of-kin entries
--                       (name / address / relation / percentage /
--                       mobile1 / mobile2 / email). Stored as JSON
--                       so we don't need a separate child table.
-- =============================================================

SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crew' AND COLUMN_NAME = 'onboarding_complete');
SET @sql = IF(@col = 0,
    'ALTER TABLE `crew` ADD COLUMN `onboarding_complete` TINYINT(1) NOT NULL DEFAULT 0 AFTER `last_login_at`',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crew' AND COLUMN_NAME = 'onboarded_at');
SET @sql = IF(@col = 0,
    'ALTER TABLE `crew` ADD COLUMN `onboarded_at` TIMESTAMP NULL DEFAULT NULL AFTER `onboarding_complete`',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crew' AND COLUMN_NAME = 'profile_photo');
SET @sql = IF(@col = 0,
    'ALTER TABLE `crew` ADD COLUMN `profile_photo` VARCHAR(500) NULL DEFAULT NULL AFTER `onboarded_at`',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crew' AND COLUMN_NAME = 'place_of_birth');
SET @sql = IF(@col = 0,
    'ALTER TABLE `crew` ADD COLUMN `place_of_birth` VARCHAR(100) NULL DEFAULT NULL AFTER `date_of_birth`',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crew' AND COLUMN_NAME = 'nationality');
SET @sql = IF(@col = 0,
    'ALTER TABLE `crew` ADD COLUMN `nationality` VARCHAR(80) NULL DEFAULT NULL AFTER `place_of_birth`',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crew' AND COLUMN_NAME = 'cdc_number');
SET @sql = IF(@col = 0,
    'ALTER TABLE `crew` ADD COLUMN `cdc_number` VARCHAR(50) NULL DEFAULT NULL AFTER `passport_number`',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crew' AND COLUMN_NAME = 'expected_departure_date');
SET @sql = IF(@col = 0,
    'ALTER TABLE `crew` ADD COLUMN `expected_departure_date` DATE NULL DEFAULT NULL AFTER `cdc_number`',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crew' AND COLUMN_NAME = 'contract_period_months');
SET @sql = IF(@col = 0,
    'ALTER TABLE `crew` ADD COLUMN `contract_period_months` VARCHAR(50) NULL DEFAULT NULL AFTER `expected_departure_date`',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Bank details (payroll / reimbursement) -------------------
SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crew' AND COLUMN_NAME = 'bank_account_holder');
SET @sql = IF(@col = 0,
    'ALTER TABLE `crew` ADD COLUMN `bank_account_holder` VARCHAR(120) NULL DEFAULT NULL AFTER `contract_period_months`',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crew' AND COLUMN_NAME = 'bank_account_no');
SET @sql = IF(@col = 0,
    'ALTER TABLE `crew` ADD COLUMN `bank_account_no` VARCHAR(50) NULL DEFAULT NULL AFTER `bank_account_holder`',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crew' AND COLUMN_NAME = 'bank_name');
SET @sql = IF(@col = 0,
    'ALTER TABLE `crew` ADD COLUMN `bank_name` VARCHAR(120) NULL DEFAULT NULL AFTER `bank_account_no`',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crew' AND COLUMN_NAME = 'bank_ifsc');
SET @sql = IF(@col = 0,
    'ALTER TABLE `crew` ADD COLUMN `bank_ifsc` VARCHAR(20) NULL DEFAULT NULL AFTER `bank_name`',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- next_of_kin: JSON array, up to 2 entries -------------------
-- TEXT (not JSON) so this works on MySQL 5.7.7 and earlier as well —
-- the application encodes / decodes JSON itself.
SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crew' AND COLUMN_NAME = 'next_of_kin');
SET @sql = IF(@col = 0,
    'ALTER TABLE `crew` ADD COLUMN `next_of_kin` TEXT NULL DEFAULT NULL AFTER `bank_ifsc`',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- =============================================================
-- File path widening: file_path columns may now hold a slightly
-- longer relative path because we organise uploads by company /
-- crew (e.g. "Maersk/JohnSmith_Master/Master_JohnSmith_Passport.pdf").
-- Widening from 255 → 500 across the board for headroom.
-- =============================================================

ALTER TABLE `crew_documents`    MODIFY COLUMN `file_path` VARCHAR(500) DEFAULT NULL;
ALTER TABLE `crew_medical`      MODIFY COLUMN `file_path` VARCHAR(500) DEFAULT NULL;
ALTER TABLE `basic_courses`     MODIFY COLUMN `file_path` VARCHAR(500) DEFAULT NULL;
ALTER TABLE `advanced_courses` MODIFY COLUMN `file_path` VARCHAR(500) DEFAULT NULL;
ALTER TABLE `travel_details`    MODIFY COLUMN `file_path` VARCHAR(500) DEFAULT NULL;
ALTER TABLE `contracts`         MODIFY COLUMN `svsml_contract_path`  VARCHAR(500) DEFAULT NULL;
ALTER TABLE `contracts`         MODIFY COLUMN `client_contract_path` VARCHAR(500) DEFAULT NULL;
ALTER TABLE `contracts`         MODIFY COLUMN `photo_path`           VARCHAR(500) DEFAULT NULL;

-- =============================================================
-- After running this file:
--
--   * Existing crew rows have onboarding_complete = 0, so on next
--     login they'll be redirected to /crew-onboarding.php to fill
--     out personal / bank / next-of-kin info before the portal
--     will let them proceed.
--   * Crew profiles created from now on default the same way.
--   * If you want to bypass onboarding for an existing crew (e.g.
--     a long-time member who shouldn't see the form), run:
--         UPDATE crew SET onboarding_complete = 1, onboarded_at = NOW()
--          WHERE id = <crew_id>;
--
-- The migration is idempotent — re-running this file is safe.
-- =============================================================



-- =============================================================
-- Module 18 follow-up — sort_order columns
--
-- The crew portal documents / medical pages used to ORDER BY
-- sort_order on dropdown_items, which silently broke production
-- because the column didn't exist on `dropdown_items`. The PHP
-- queries have been switched to ORDER BY id ASC (root cause fix),
-- and we add a sort_order column to:
--   * dropdown_items   — defensive, in case any future query needs it
--   * crew_documents   — per spec, for future row ordering UX
--   * crew_medical     — same
-- All three default to 0 so existing rows keep their natural order.
-- =============================================================

SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dropdown_items' AND COLUMN_NAME = 'sort_order');
SET @sql = IF(@col = 0,
    'ALTER TABLE `dropdown_items` ADD COLUMN `sort_order` INT NOT NULL DEFAULT 0 AFTER `is_active`',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crew_documents' AND COLUMN_NAME = 'sort_order');
SET @sql = IF(@col = 0,
    'ALTER TABLE `crew_documents` ADD COLUMN `sort_order` INT NOT NULL DEFAULT 0',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crew_medical' AND COLUMN_NAME = 'sort_order');
SET @sql = IF(@col = 0,
    'ALTER TABLE `crew_medical` ADD COLUMN `sort_order` INT NOT NULL DEFAULT 0',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
