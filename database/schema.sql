-- =============================================================
-- SVSML-ERP — Database Schema
-- Module 1: Initial schema with corrected names
--   * staff_activity (NOT activity_log)
--   * crew.source_type ENUM (NOT source_is_client BOOLEAN)
--   * vessel_extra_fields, alert_settings, system_settings included
-- Engine: InnoDB, Charset: utf8mb4
-- =============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- -------------------------------------------------------------
-- 1. users
-- -------------------------------------------------------------
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
    `id`             INT NOT NULL AUTO_INCREMENT,
    `full_name`      VARCHAR(100) NOT NULL,
    `email`          VARCHAR(100) NOT NULL,
    `password`       VARCHAR(255) NOT NULL,
    `role`           ENUM('admin','sub_admin','staff','crew') NOT NULL,
    `contact_number` VARCHAR(20) DEFAULT NULL,
    `is_active`      TINYINT(1) NOT NULL DEFAULT 1,
    `created_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_users_email` (`email`),
    KEY `ix_users_role` (`role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- 2. companies
-- -------------------------------------------------------------
DROP TABLE IF EXISTS `companies`;
CREATE TABLE `companies` (
    `id`           INT NOT NULL AUTO_INCREMENT,
    `company_name` VARCHAR(100) NOT NULL,
    `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `ix_companies_name` (`company_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- 3. dropdown_items   (used by many later tables)
-- -------------------------------------------------------------
DROP TABLE IF EXISTS `dropdown_items`;
CREATE TABLE `dropdown_items` (
    `id`         INT NOT NULL AUTO_INCREMENT,
    `category`   VARCHAR(50) NOT NULL,
    `label`      VARCHAR(100) NOT NULL,
    `is_active`  TINYINT(1) NOT NULL DEFAULT 1,
    `created_by` INT DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `ix_dropdown_category` (`category`),
    KEY `fk_dropdown_user` (`created_by`),
    CONSTRAINT `fk_dropdown_user` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- 4. ranks
-- -------------------------------------------------------------
DROP TABLE IF EXISTS `ranks`;
CREATE TABLE `ranks` (
    `id`         INT NOT NULL AUTO_INCREMENT,
    `rank_name`  VARCHAR(100) NOT NULL,
    `created_by` INT DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_rank_name` (`rank_name`),
    KEY `fk_ranks_user` (`created_by`),
    CONSTRAINT `fk_ranks_user` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- 5. vessels
-- -------------------------------------------------------------
DROP TABLE IF EXISTS `vessels`;
CREATE TABLE `vessels` (
    `id`                      INT NOT NULL AUTO_INCREMENT,
    `company_id`              INT DEFAULT NULL,
    `vessel_name`             VARCHAR(100) NOT NULL,
    `imo_number`              VARCHAR(50) DEFAULT NULL,
    `lsa_number`              VARCHAR(50) DEFAULT NULL,
    `grt`                     DECIMAL(10,2) DEFAULT NULL,
    `kilo_watt`               DECIMAL(10,2) DEFAULT NULL,
    `ship_type`               VARCHAR(50) DEFAULT NULL,
    `ship_flag`               VARCHAR(50) DEFAULT NULL,
    `pni_date`                DATE NOT NULL,
    `mlc_date`                DATE DEFAULT NULL,
    `financial_security_date` DATE NOT NULL,
    `created_by`              INT DEFAULT NULL,
    `created_at`              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_vessels_imo` (`imo_number`),
    KEY `fk_vessels_company` (`company_id`),
    KEY `fk_vessels_user` (`created_by`),
    CONSTRAINT `fk_vessels_company` FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_vessels_user`    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`)     ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- 6. vessel_extra_fields
-- -------------------------------------------------------------
DROP TABLE IF EXISTS `vessel_extra_fields`;
CREATE TABLE `vessel_extra_fields` (
    `id`          INT NOT NULL AUTO_INCREMENT,
    `vessel_id`   INT NOT NULL,
    `field_name`  VARCHAR(100) NOT NULL,
    `field_value` TEXT,
    `created_by`  INT DEFAULT NULL,
    `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `fk_vef_vessel` (`vessel_id`),
    KEY `fk_vef_user`   (`created_by`),
    CONSTRAINT `fk_vef_vessel` FOREIGN KEY (`vessel_id`)  REFERENCES `vessels`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_vef_user`   FOREIGN KEY (`created_by`) REFERENCES `users`(`id`)   ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- 7. crew
--    NOTE: source_type ENUM('staff','client') replaces the old
--          source_is_client BOOLEAN (per Module 1 reconciliation).
-- -------------------------------------------------------------
DROP TABLE IF EXISTS `crew`;
CREATE TABLE `crew` (
    `id`                   INT NOT NULL AUTO_INCREMENT,
    `indos_number`         VARCHAR(50) DEFAULT NULL,
    `full_name`            VARCHAR(100) NOT NULL,
    `rank_id`              INT DEFAULT NULL,
    `date_of_birth`        DATE DEFAULT NULL,
    `contact_number`       VARCHAR(20) DEFAULT NULL,
    `email`                VARCHAR(100) DEFAULT NULL,
    `full_address`         TEXT,
    `source_staff_id`      INT DEFAULT NULL,
    `source_type`          ENUM('staff','client') NOT NULL DEFAULT 'staff',
    `company_id`           INT DEFAULT NULL,
    `vessel_id`            INT DEFAULT NULL,
    `joiner_type`          ENUM('new_joiner','rejoiner') NOT NULL DEFAULT 'new_joiner',
    `passport_number`      VARCHAR(50) DEFAULT NULL,
    `crew_access_enabled`  TINYINT(1) NOT NULL DEFAULT 0,
    `boiler_suit_size`     VARCHAR(10) DEFAULT NULL,
    `safety_shoes_size`    VARCHAR(10) DEFAULT NULL,
    `shirt_size`           VARCHAR(10) DEFAULT NULL,
    `pant_size`            VARCHAR(10) DEFAULT NULL,
    `created_by`           INT DEFAULT NULL,
    `created_at`           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_crew_indos`    (`indos_number`),
    UNIQUE KEY `uq_crew_passport` (`passport_number`),
    KEY `fk_crew_rank`         (`rank_id`),
    KEY `fk_crew_company`      (`company_id`),
    KEY `fk_crew_vessel`       (`vessel_id`),
    KEY `fk_crew_source_staff` (`source_staff_id`),
    KEY `fk_crew_user`         (`created_by`),
    KEY `ix_crew_full_name`    (`full_name`),
    CONSTRAINT `fk_crew_rank`         FOREIGN KEY (`rank_id`)         REFERENCES `ranks`(`id`)     ON DELETE SET NULL,
    CONSTRAINT `fk_crew_company`      FOREIGN KEY (`company_id`)      REFERENCES `companies`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_crew_vessel`       FOREIGN KEY (`vessel_id`)       REFERENCES `vessels`(`id`)   ON DELETE SET NULL,
    CONSTRAINT `fk_crew_source_staff` FOREIGN KEY (`source_staff_id`) REFERENCES `users`(`id`)     ON DELETE SET NULL,
    CONSTRAINT `fk_crew_user`         FOREIGN KEY (`created_by`)      REFERENCES `users`(`id`)     ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- 8. crew_documents
-- -------------------------------------------------------------
DROP TABLE IF EXISTS `crew_documents`;
CREATE TABLE `crew_documents` (
    `id`              INT NOT NULL AUTO_INCREMENT,
    `crew_id`         INT NOT NULL,
    `document_type`   ENUM('cv','passport','cdc','visa','sid') NOT NULL,
    `visa_type_id`    INT DEFAULT NULL,
    `document_number` VARCHAR(100) DEFAULT NULL,
    `issue_date`      DATE DEFAULT NULL,
    `expiry_date`     DATE DEFAULT NULL,
    `file_path`       VARCHAR(255) DEFAULT NULL,
    `created_by`      INT DEFAULT NULL,
    `created_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `fk_crewdoc_crew`     (`crew_id`),
    KEY `fk_crewdoc_visatype` (`visa_type_id`),
    KEY `fk_crewdoc_user`     (`created_by`),
    KEY `ix_crewdoc_expiry`   (`expiry_date`),
    CONSTRAINT `fk_crewdoc_crew`     FOREIGN KEY (`crew_id`)      REFERENCES `crew`(`id`)            ON DELETE CASCADE,
    CONSTRAINT `fk_crewdoc_visatype` FOREIGN KEY (`visa_type_id`) REFERENCES `dropdown_items`(`id`)  ON DELETE SET NULL,
    CONSTRAINT `fk_crewdoc_user`     FOREIGN KEY (`created_by`)   REFERENCES `users`(`id`)           ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- 9. crew_medical
-- -------------------------------------------------------------
DROP TABLE IF EXISTS `crew_medical`;
CREATE TABLE `crew_medical` (
    `id`              INT NOT NULL AUTO_INCREMENT,
    `crew_id`         INT NOT NULL,
    `medical_type_id` INT DEFAULT NULL,
    `issue_date`      DATE DEFAULT NULL,
    `expiry_date`     DATE DEFAULT NULL,
    `file_path`       VARCHAR(255) DEFAULT NULL,
    `created_by`      INT DEFAULT NULL,
    `created_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `fk_crewmed_crew` (`crew_id`),
    KEY `fk_crewmed_type` (`medical_type_id`),
    KEY `fk_crewmed_user` (`created_by`),
    KEY `ix_crewmed_expiry` (`expiry_date`),
    CONSTRAINT `fk_crewmed_crew` FOREIGN KEY (`crew_id`)         REFERENCES `crew`(`id`)           ON DELETE CASCADE,
    CONSTRAINT `fk_crewmed_type` FOREIGN KEY (`medical_type_id`) REFERENCES `dropdown_items`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_crewmed_user` FOREIGN KEY (`created_by`)      REFERENCES `users`(`id`)          ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- 10. basic_courses
-- -------------------------------------------------------------
DROP TABLE IF EXISTS `basic_courses`;
CREATE TABLE `basic_courses` (
    `id`            INT NOT NULL AUTO_INCREMENT,
    `crew_id`       INT NOT NULL,
    `course_name`   VARCHAR(100) NOT NULL,
    `course_number` VARCHAR(100) DEFAULT NULL,
    `issue_date`    DATE DEFAULT NULL,
    `expiry_date`   DATE DEFAULT NULL,
    `file_path`     VARCHAR(255) DEFAULT NULL,
    `created_by`    INT DEFAULT NULL,
    `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `fk_bc_crew`     (`crew_id`),
    KEY `fk_bc_user`     (`created_by`),
    KEY `ix_bc_expiry`   (`expiry_date`),
    CONSTRAINT `fk_bc_crew` FOREIGN KEY (`crew_id`)    REFERENCES `crew`(`id`)  ON DELETE CASCADE,
    CONSTRAINT `fk_bc_user` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- 11. advanced_courses
-- -------------------------------------------------------------
DROP TABLE IF EXISTS `advanced_courses`;
CREATE TABLE `advanced_courses` (
    `id`            INT NOT NULL AUTO_INCREMENT,
    `crew_id`       INT NOT NULL,
    `course_name`   VARCHAR(100) NOT NULL,
    `course_number` VARCHAR(100) DEFAULT NULL,
    `issue_date`    DATE DEFAULT NULL,
    `expiry_date`   DATE DEFAULT NULL,
    `file_path`     VARCHAR(255) DEFAULT NULL,
    `created_by`    INT DEFAULT NULL,
    `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `fk_ac_crew` (`crew_id`),
    KEY `fk_ac_user` (`created_by`),
    KEY `ix_ac_expiry` (`expiry_date`),
    CONSTRAINT `fk_ac_crew` FOREIGN KEY (`crew_id`)    REFERENCES `crew`(`id`)  ON DELETE CASCADE,
    CONSTRAINT `fk_ac_user` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- 12. contracts
-- -------------------------------------------------------------
DROP TABLE IF EXISTS `contracts`;
CREATE TABLE `contracts` (
    `id`                            INT NOT NULL AUTO_INCREMENT,
    `crew_id`                       INT NOT NULL,
    `reference_number`              VARCHAR(50) DEFAULT NULL,
    `contract_date`                 DATE DEFAULT NULL,
    `place_of_birth`                VARCHAR(100) DEFAULT NULL,
    `home_town`                     VARCHAR(100) DEFAULT NULL,
    `nearest_airport`               VARCHAR(100) DEFAULT NULL,
    `contract_period`               VARCHAR(50) DEFAULT NULL,
    `total_salary`                  DECIMAL(10,2) DEFAULT NULL,
    `commencement_date`             DATE DEFAULT NULL,
    `next_of_kin_name`              VARCHAR(100) DEFAULT NULL,
    `next_of_kin_relationship`      VARCHAR(50) DEFAULT NULL,
    `next_of_kin_address`           TEXT,
    `next_of_kin_contact`           VARCHAR(20) DEFAULT NULL,
    `next_of_kin_email`             VARCHAR(100) DEFAULT NULL,
    `beneficiary_name`              VARCHAR(100) DEFAULT NULL,
    `beneficiary_relationship`      VARCHAR(50) DEFAULT NULL,
    `beneficiary_percentage`        DECIMAL(5,2) DEFAULT NULL,
    `photo_path`                    VARCHAR(255) DEFAULT NULL,
    `dpdp_consent`                  TINYINT(1) NOT NULL DEFAULT 0,
    `dpdp_consent_at`               TIMESTAMP NULL DEFAULT NULL,
    `svsml_contract_path`           VARCHAR(255) DEFAULT NULL,
    `svsml_contract_generated_at`   TIMESTAMP NULL DEFAULT NULL,
    `svsml_contract_generated_by`   INT DEFAULT NULL,
    `client_contract_path`          VARCHAR(255) DEFAULT NULL,
    `client_contract_status`        ENUM('pending','uploaded') NOT NULL DEFAULT 'pending',
    `created_by`                    INT DEFAULT NULL,
    `created_at`                    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`                    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_contracts_ref` (`reference_number`),
    KEY `fk_contracts_crew`     (`crew_id`),
    KEY `fk_contracts_user`     (`created_by`),
    KEY `fk_contracts_genby`    (`svsml_contract_generated_by`),
    CONSTRAINT `fk_contracts_crew`  FOREIGN KEY (`crew_id`)                     REFERENCES `crew`(`id`)  ON DELETE CASCADE,
    CONSTRAINT `fk_contracts_user`  FOREIGN KEY (`created_by`)                  REFERENCES `users`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_contracts_genby` FOREIGN KEY (`svsml_contract_generated_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- 13. client_approvals
-- -------------------------------------------------------------
DROP TABLE IF EXISTS `client_approvals`;
CREATE TABLE `client_approvals` (
    `id`             INT NOT NULL AUTO_INCREMENT,
    `crew_id`        INT NOT NULL,
    `roll_number`    INT DEFAULT NULL,
    `description_id` INT DEFAULT NULL,
    `cost`           DECIMAL(10,2) DEFAULT NULL,
    `created_by`     INT DEFAULT NULL,
    `created_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `fk_ca_crew` (`crew_id`),
    KEY `fk_ca_desc` (`description_id`),
    KEY `fk_ca_user` (`created_by`),
    CONSTRAINT `fk_ca_crew` FOREIGN KEY (`crew_id`)        REFERENCES `crew`(`id`)           ON DELETE CASCADE,
    CONSTRAINT `fk_ca_desc` FOREIGN KEY (`description_id`) REFERENCES `dropdown_items`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_ca_user` FOREIGN KEY (`created_by`)     REFERENCES `users`(`id`)          ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- 14. svsml_approvals
-- -------------------------------------------------------------
DROP TABLE IF EXISTS `svsml_approvals`;
CREATE TABLE `svsml_approvals` (
    `id`             INT NOT NULL AUTO_INCREMENT,
    `crew_id`        INT NOT NULL,
    `roll_number`    INT DEFAULT NULL,
    `description_id` INT DEFAULT NULL,
    `total_amount`   DECIMAL(10,2) DEFAULT NULL,
    `discount`       DECIMAL(10,2) NOT NULL DEFAULT 0,
    `paid_amount`    DECIMAL(10,2) NOT NULL DEFAULT 0,
    `pending_amount` DECIMAL(10,2) DEFAULT NULL,
    `created_by`     INT DEFAULT NULL,
    `created_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `fk_sa_crew` (`crew_id`),
    KEY `fk_sa_desc` (`description_id`),
    KEY `fk_sa_user` (`created_by`),
    CONSTRAINT `fk_sa_crew` FOREIGN KEY (`crew_id`)        REFERENCES `crew`(`id`)           ON DELETE CASCADE,
    CONSTRAINT `fk_sa_desc` FOREIGN KEY (`description_id`) REFERENCES `dropdown_items`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_sa_user` FOREIGN KEY (`created_by`)     REFERENCES `users`(`id`)          ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- 15. travel_details
-- -------------------------------------------------------------
DROP TABLE IF EXISTS `travel_details`;
CREATE TABLE `travel_details` (
    `id`           INT NOT NULL AUTO_INCREMENT,
    `crew_id`      INT NOT NULL,
    `sr_number`    INT DEFAULT NULL,
    `detail_label` VARCHAR(100) DEFAULT NULL,
    `departure`    VARCHAR(100) DEFAULT NULL,
    `arrival`      VARCHAR(100) DEFAULT NULL,
    `is_done`      TINYINT(1) NOT NULL DEFAULT 0,
    `final_status` ENUM('valid','pending','invalid') NOT NULL DEFAULT 'pending',
    `remarks`      TEXT,
    `file_path`    VARCHAR(255) DEFAULT NULL,
    `field_type`   ENUM('default','custom') NOT NULL DEFAULT 'default',
    `created_by`   INT DEFAULT NULL,
    `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `fk_td_crew` (`crew_id`),
    KEY `fk_td_user` (`created_by`),
    CONSTRAINT `fk_td_crew` FOREIGN KEY (`crew_id`)    REFERENCES `crew`(`id`)  ON DELETE CASCADE,
    CONSTRAINT `fk_td_user` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- 16. sign_on_off
-- -------------------------------------------------------------
DROP TABLE IF EXISTS `sign_on_off`;
CREATE TABLE `sign_on_off` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `crew_id`           INT NOT NULL,
    `sign_on_date`      DATE DEFAULT NULL,
    `sign_on_cdc_path`  VARCHAR(255) DEFAULT NULL,
    `sign_off_date`     DATE DEFAULT NULL,
    `sign_off_cdc_path` VARCHAR(255) DEFAULT NULL,
    `days_on_board`     INT DEFAULT NULL,
    `created_by`        INT DEFAULT NULL,
    `created_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `fk_so_crew` (`crew_id`),
    KEY `fk_so_user` (`created_by`),
    KEY `ix_so_dates` (`sign_on_date`,`sign_off_date`),
    CONSTRAINT `fk_so_crew` FOREIGN KEY (`crew_id`)    REFERENCES `crew`(`id`)  ON DELETE CASCADE,
    CONSTRAINT `fk_so_user` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- 17. sailing_history
-- -------------------------------------------------------------
DROP TABLE IF EXISTS `sailing_history`;
CREATE TABLE `sailing_history` (
    `id`              INT NOT NULL AUTO_INCREMENT,
    `crew_id`         INT NOT NULL,
    `indos_number`    VARCHAR(50) DEFAULT NULL,
    `cdc_number`      VARCHAR(50) DEFAULT NULL,
    `seafarer_name`   VARCHAR(100) DEFAULT NULL,
    `passport_number` VARCHAR(50) DEFAULT NULL,
    `rank_id`         INT DEFAULT NULL,
    `vessel_id`       INT DEFAULT NULL,
    `company_id`      INT DEFAULT NULL,
    `sign_on_date`    DATE DEFAULT NULL,
    `sign_off_date`   DATE DEFAULT NULL,
    `days_on_vessel`  INT DEFAULT NULL,
    `joiner_type`     ENUM('new_joiner','rejoiner') NOT NULL DEFAULT 'new_joiner',
    `created_by`      INT DEFAULT NULL,
    `created_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `fk_sh_crew`    (`crew_id`),
    KEY `fk_sh_rank`    (`rank_id`),
    KEY `fk_sh_vessel`  (`vessel_id`),
    KEY `fk_sh_company` (`company_id`),
    KEY `fk_sh_user`    (`created_by`),
    CONSTRAINT `fk_sh_crew`    FOREIGN KEY (`crew_id`)    REFERENCES `crew`(`id`)      ON DELETE CASCADE,
    CONSTRAINT `fk_sh_rank`    FOREIGN KEY (`rank_id`)    REFERENCES `ranks`(`id`)     ON DELETE SET NULL,
    CONSTRAINT `fk_sh_vessel`  FOREIGN KEY (`vessel_id`)  REFERENCES `vessels`(`id`)   ON DELETE SET NULL,
    CONSTRAINT `fk_sh_company` FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_sh_user`    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`)     ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- 18. quick_approvals
-- -------------------------------------------------------------
DROP TABLE IF EXISTS `quick_approvals`;
CREATE TABLE `quick_approvals` (
    `id`            INT NOT NULL AUTO_INCREMENT,
    `crew_name`     VARCHAR(100) NOT NULL,
    `company_id`    INT DEFAULT NULL,
    `rank_id`       INT DEFAULT NULL,
    `entry_date`    DATE DEFAULT NULL,
    `total_amount`  DECIMAL(10,2) DEFAULT NULL,
    `created_by`    INT DEFAULT NULL,
    `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `fk_qa_company` (`company_id`),
    KEY `fk_qa_rank`    (`rank_id`),
    KEY `fk_qa_user`    (`created_by`),
    CONSTRAINT `fk_qa_company` FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_qa_rank`    FOREIGN KEY (`rank_id`)    REFERENCES `ranks`(`id`)     ON DELETE SET NULL,
    CONSTRAINT `fk_qa_user`    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`)     ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- 19. quick_approval_items
-- -------------------------------------------------------------
DROP TABLE IF EXISTS `quick_approval_items`;
CREATE TABLE `quick_approval_items` (
    `id`                 INT NOT NULL AUTO_INCREMENT,
    `quick_approval_id`  INT NOT NULL,
    `description_id`     INT DEFAULT NULL,
    `amount`             DECIMAL(10,2) DEFAULT NULL,
    `created_at`         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `fk_qai_qa`   (`quick_approval_id`),
    KEY `fk_qai_desc` (`description_id`),
    CONSTRAINT `fk_qai_qa`   FOREIGN KEY (`quick_approval_id`) REFERENCES `quick_approvals`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_qai_desc` FOREIGN KEY (`description_id`)    REFERENCES `dropdown_items`(`id`)  ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- 20. staff_activity   (renamed from activity_log)
-- -------------------------------------------------------------
DROP TABLE IF EXISTS `staff_activity`;
CREATE TABLE `staff_activity` (
    `id`           INT NOT NULL AUTO_INCREMENT,
    `user_id`      INT DEFAULT NULL,
    `action_type`  ENUM('create','update','delete','upload','generate') NOT NULL,
    `module`       VARCHAR(50) NOT NULL,
    `record_id`    INT DEFAULT NULL,
    `description`  TEXT,
    `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `fk_sact_user`   (`user_id`),
    KEY `ix_sact_module` (`module`),
    KEY `ix_sact_action` (`action_type`),
    CONSTRAINT `fk_sact_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- 21. alert_settings   (single-row table)
-- -------------------------------------------------------------
DROP TABLE IF EXISTS `alert_settings`;
CREATE TABLE `alert_settings` (
    `id`                            INT NOT NULL AUTO_INCREMENT,
    `doc_expiry_yellow_days`        INT NOT NULL DEFAULT 30,
    `doc_expiry_red_days`           INT NOT NULL DEFAULT 0,
    `sign_on_yellow_days`           INT NOT NULL DEFAULT 150,
    `sign_on_red_days`              INT NOT NULL DEFAULT 180,
    `vessel_doc_warning_days`       INT NOT NULL DEFAULT 30,
    `dashboard_alerts_enabled`      TINYINT(1) NOT NULL DEFAULT 1,
    `email_notifications_enabled`   TINYINT(1) NOT NULL DEFAULT 0,
    `sign_on_alerts_enabled`        TINYINT(1) NOT NULL DEFAULT 1,
    `updated_at`                    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- 22. system_settings   (single-row table)
-- -------------------------------------------------------------
DROP TABLE IF EXISTS `system_settings`;
CREATE TABLE `system_settings` (
    `id`                          INT NOT NULL AUTO_INCREMENT,
    `company_name`                VARCHAR(200) NOT NULL DEFAULT 'SEA VOYAGE SHIP MANAGEMENT LLP',
    `short_name`                  VARCHAR(20)  NOT NULL DEFAULT 'SVSML',
    `rpsl_number`                 VARCHAR(50)  DEFAULT NULL,
    `contact_email`               VARCHAR(100) DEFAULT NULL,
    `phone`                       VARCHAR(30)  DEFAULT NULL,
    `website`                     VARCHAR(100) DEFAULT NULL,
    `address`                     TEXT,
    `contract_footer_text`        TEXT,
    `crew_self_login_enabled`     TINYINT(1) NOT NULL DEFAULT 1,
    `dpdp_consent_required`       TINYINT(1) NOT NULL DEFAULT 1,
    `auto_joiner_detection`       TINYINT(1) NOT NULL DEFAULT 1,
    `staff_activity_logging`      TINYINT(1) NOT NULL DEFAULT 1,
    `contract_email_auto_send`    TINYINT(1) NOT NULL DEFAULT 0,
    `smtp_host`                   VARCHAR(100) DEFAULT NULL,
    `smtp_port`                   INT DEFAULT NULL,
    `smtp_email`                  VARCHAR(100) DEFAULT NULL,
    `smtp_password`               VARCHAR(255) DEFAULT NULL,
    `updated_at`                  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
