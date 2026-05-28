-- =============================================================
-- SVSML-ERP — Seed Data
-- Run AFTER schema.sql.
-- Seeds: ranks (27), dropdown_items (8 categories),
--        alert_settings (1 row), system_settings (1 row).
-- The first admin user is created by /install.php (NOT here)
-- so the password is hashed at runtime via password_hash().
-- =============================================================

SET NAMES utf8mb4;

-- -------------------------------------------------------------
-- ranks
-- Spec lists 27 default rank names. (Spec text says "28 ranks"
-- but only enumerates 27 — seeding the 27 named entries.)
-- -------------------------------------------------------------
INSERT INTO `ranks` (`rank_name`) VALUES
    ('Master'),
    ('Chief Officer'),
    ('Second Officer'),
    ('Watch Keeping Deck Officer OINW/Third Officer'),
    ('Deck Cadet'),
    ('Chief Engineer (NCV)'),
    ('2nd Engineer'),
    ('3rd Engineer'),
    ('Watch Keeping Engineer Officer OICEW'),
    ('Electro Technical Officer'),
    ('Electrical/Electronics Officer'),
    ('Radio Officer'),
    ('Trainee Marine Engineer'),
    ('Engineer Cadet'),
    ('Bosun'),
    ('Crane Operator'),
    ('Engine Petty Officer'),
    ('Cook'),
    ('Saloon Rating'),
    ('Deck Rating'),
    ('Deck Watchkeeping Rating'),
    ('Engine Rating'),
    ('Ordinary Seaman'),
    ('Oiler'),
    ('Wiper'),
    ('AB (Able Seaman)'),
    ('Crew (Others)');

-- -------------------------------------------------------------
-- dropdown_items
-- -------------------------------------------------------------

-- visa_type
INSERT INTO `dropdown_items` (`category`, `label`) VALUES
    ('visa_type', 'Schengen'),
    ('visa_type', 'US Visa'),
    ('visa_type', 'UAE Visa'),
    ('visa_type', 'UK Visa'),
    ('visa_type', 'Other');

-- medical_type
INSERT INTO `dropdown_items` (`category`, `label`) VALUES
    ('medical_type', 'ENG1'),
    ('medical_type', 'Yellow Fever'),
    ('medical_type', 'Indian Medical Certificate'),
    ('medical_type', 'Other');

-- ship_type
INSERT INTO `dropdown_items` (`category`, `label`) VALUES
    ('ship_type', 'Bulk Carrier'),
    ('ship_type', 'Container'),
    ('ship_type', 'Tanker'),
    ('ship_type', 'General Cargo'),
    ('ship_type', 'Passenger'),
    ('ship_type', 'RoRo'),
    ('ship_type', 'Other');

-- ship_flag
INSERT INTO `dropdown_items` (`category`, `label`) VALUES
    ('ship_flag', 'Panama'),
    ('ship_flag', 'Marshall Islands'),
    ('ship_flag', 'Singapore'),
    ('ship_flag', 'Liberia'),
    ('ship_flag', 'Bahamas'),
    ('ship_flag', 'India'),
    ('ship_flag', 'Other');

-- approval_description
INSERT INTO `dropdown_items` (`category`, `label`) VALUES
    ('approval_description', 'Medical'),
    ('approval_description', 'Visa Fee'),
    ('approval_description', 'STCW Course'),
    ('approval_description', 'Passport'),
    ('approval_description', 'CDC'),
    ('approval_description', 'Travel');

-- contract_period
INSERT INTO `dropdown_items` (`category`, `label`) VALUES
    ('contract_period', '1-3 Months'),
    ('contract_period', '2-6 Months'),
    ('contract_period', '3-9 Months');

-- relationship
INSERT INTO `dropdown_items` (`category`, `label`) VALUES
    ('relationship', 'Father'),
    ('relationship', 'Mother'),
    ('relationship', 'Wife'),
    ('relationship', 'Son'),
    ('relationship', 'Daughter'),
    ('relationship', 'Brother'),
    ('relationship', 'Sister'),
    ('relationship', 'Other');

-- visa_country
INSERT INTO `dropdown_items` (`category`, `label`) VALUES
    ('visa_country', 'UAE'),
    ('visa_country', 'USA'),
    ('visa_country', 'UK'),
    ('visa_country', 'Schengen'),
    ('visa_country', 'Singapore'),
    ('visa_country', 'Other');

-- -------------------------------------------------------------
-- alert_settings (single-row defaults)
-- -------------------------------------------------------------
INSERT INTO `alert_settings`
    (`doc_expiry_yellow_days`, `doc_expiry_red_days`,
     `sign_on_yellow_days`,    `sign_on_red_days`,
     `vessel_doc_warning_days`,
     `dashboard_alerts_enabled`, `email_notifications_enabled`, `sign_on_alerts_enabled`)
VALUES
    (30, 0, 150, 180, 30, 1, 0, 1);

-- -------------------------------------------------------------
-- system_settings (single-row defaults)
-- -------------------------------------------------------------
INSERT INTO `system_settings`
    (`company_name`, `short_name`,
     `crew_self_login_enabled`, `dpdp_consent_required`,
     `auto_joiner_detection`,   `staff_activity_logging`,
     `contract_email_auto_send`)
VALUES
    ('SEA VOYAGE SHIP MANAGEMENT LLP', 'SVSML',
     1, 1, 1, 1, 0);
