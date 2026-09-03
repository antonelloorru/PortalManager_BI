ALTER TABLE `user_certifications` MODIFY COLUMN `issue_date` DATE DEFAULT NULL COMMENT 'Data conseguimento (NULL = cert senza data / traccia esame)';
DROP TABLE IF EXISTS `intervention_reports`;
DROP TABLE IF EXISTS `project_team`;
DROP TABLE IF EXISTS `project_presales_effort`;
DROP TABLE IF EXISTS `intervention_import_batches`;
DROP TABLE IF EXISTS `hourly_rate_band_history`;
DROP TABLE IF EXISTS `hourly_rate_band_rates`;
DROP TABLE IF EXISTS `hourly_rate_bands`;
DROP TABLE IF EXISTS `company_prefix_map`;
CREATE TABLE IF NOT EXISTS `departments` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(150) NOT NULL,
  `value_type` ENUM('Servizio a Valore','Non a Valore') NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), UNIQUE KEY `uq_department_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `department_history` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `department_id` INT UNSIGNED NULL,
  `action` ENUM('CREATE','UPDATE','DELETE') NOT NULL,
  `old_name` VARCHAR(150) NULL, `new_name` VARCHAR(150) NULL,
  `old_value_type` ENUM('Servizio a Valore','Non a Valore') NULL,
  `new_value_type` ENUM('Servizio a Valore','Non a Valore') NULL,
  `old_is_active` TINYINT(1) NULL, `new_is_active` TINYINT(1) NULL,
  `changed_by` INT UNSIGNED NULL,
  `changed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), KEY `idx_dh_department` (`department_id`), KEY `idx_dh_changed_at` (`changed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE `employees` ADD COLUMN IF NOT EXISTS `department_id` INT UNSIGNED NULL AFTER `department`;
ALTER TABLE `employees` ADD CONSTRAINT `fk_employees_department` FOREIGN KEY IF NOT EXISTS (`department_id`) REFERENCES `departments`(`id`) ON UPDATE CASCADE ON DELETE SET NULL;
CREATE INDEX IF NOT EXISTS `idx_employees_department_id` ON `employees`(`department_id`);
INSERT IGNORE INTO `role_permissions` (`role_id`,`page_name`) VALUES (1,'manage_departments.php');
CREATE TABLE IF NOT EXISTS `clients` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(200) NOT NULL COMMENT 'Ragione sociale',
  `vat_number` VARCHAR(30) DEFAULT NULL,
  `is_internal_company` TINYINT(1) NOT NULL DEFAULT 0,
  `internal_company_id` INT DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_by` INT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), UNIQUE KEY `uq_clients_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `client_locations` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `client_id` INT NOT NULL,
  `location_name` VARCHAR(150) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), UNIQUE KEY `uq_client_loc` (`client_id`,`location_name`),
  CONSTRAINT `fk_cl_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `cm_company_prefix_map` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `prefix` VARCHAR(10) NOT NULL,
  `company_id` INT NOT NULL,
  PRIMARY KEY (`id`), UNIQUE KEY `uq_prefix` (`prefix`),
  CONSTRAINT `fk_cpm_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `cm_rate_bands` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `band_name` VARCHAR(50) NOT NULL,
  `description` VARCHAR(150) NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), UNIQUE KEY `uq_band_name` (`band_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `cm_rate_band_rates` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `band_id` INT NOT NULL,
  `cost_type` ENUM('Aziendale','Cliente','Commerciale') NOT NULL,
  `regime` ENUM('Ordinario','Reperibilità') NOT NULL DEFAULT 'Ordinario',
  `rate_hour` DECIMAL(8,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`), UNIQUE KEY `uq_band_type_regime` (`band_id`,`cost_type`,`regime`),
  CONSTRAINT `fk_hrbr_band` FOREIGN KEY (`band_id`) REFERENCES `cm_rate_bands` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `cm_rate_band_history` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `band_id` INT NULL,
  `band_name` VARCHAR(50) NULL,
  `cost_type` ENUM('Aziendale','Cliente','Commerciale') NULL,
  `regime` ENUM('Ordinario','Reperibilità') NULL,
  `action` ENUM('CREATE','UPDATE','DELETE') NOT NULL,
  `old_rate` DECIMAL(8,2) NULL,
  `new_rate` DECIMAL(8,2) NULL,
  `changed_by` INT NULL,
  `changed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), KEY `idx_hrbh_band` (`band_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `cm_projects` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `project_code` VARCHAR(40) NOT NULL,
  `name` VARCHAR(200) NOT NULL,
  `abbr` VARCHAR(20) NULL,
  `service_line` VARCHAR(30) NULL,
  `project_type` ENUM('Gara Consip/MePA/Carrier','Progetto Standard','Trattativa Diretta') NULL,
  `commercial_ref` VARCHAR(120) NULL,
  `external_link` VARCHAR(500) NULL,
  `client_id` INT NULL,
  `client_raw` VARCHAR(180) NULL,
  `exec_company_id` INT NULL,
  `operational_status` VARCHAR(30) NULL,
  `commercial_status` ENUM('In Approvazione','Offerta Presentata','Acquisita','Persa') NULL,
  `economic_status` VARCHAR(20) NULL,
  `economic_status_todate` VARCHAR(20) NULL,
  `description` TEXT NULL,
  `internal_description` TEXT NULL,
  `compliance_to_verify` TINYINT(1) NOT NULL DEFAULT 0,
  `compliance_preauth` TINYINT(1) NOT NULL DEFAULT 0,
  `anomalies_open` INT NOT NULL DEFAULT 0,
  `anomalies_blocking` INT NOT NULL DEFAULT 0,
  `start_date` DATE NULL,
  `end_date` DATE NULL,
  `first_billing_date` DATE NULL,
  `billing_freq_months` INT NULL,
  `value_total` DECIMAL(14,2) NULL,
  `value_todate` DECIMAL(14,2) NULL,
  `actual_cost` DECIMAL(14,2) NULL,
  `margin_total` DECIMAL(14,2) NULL,
  `margin_todate` DECIMAL(14,2) NULL,
  `residual_total` DECIMAL(14,2) NULL,
  `residual_todate` DECIMAL(14,2) NULL,
  `credit_on_value` DECIMAL(14,2) NULL,
  `credit_on_costs` DECIMAL(14,2) NULL,
  `material_costs` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `commercial_budget` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `loss_allocation` ENUM('Rischio Impresa 100%','Budget Commerciale 100%','Ripartizione 50/50') NULL,
  `import_batch_id` INT NULL,
  `created_by` INT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), UNIQUE KEY `uq_project_code` (`project_code`),
  KEY `idx_proj_client` (`client_id`), KEY `idx_proj_execco` (`exec_company_id`),
  KEY `idx_proj_opstatus` (`operational_status`), KEY `idx_proj_service` (`service_line`),
  CONSTRAINT `fk_proj_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_proj_execco` FOREIGN KEY (`exec_company_id`) REFERENCES `companies` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `cm_presales_effort` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `project_id` INT NOT NULL,
  `cost_center` ENUM('Ufficio Gare','Sicurezza','Ingegneria/Analisi Tecnica','Project Management') NOT NULL,
  `hours` DECIMAL(8,2) NOT NULL DEFAULT 0.00,
  `hourly_rate` DECIMAL(8,2) NULL,
  `notes` VARCHAR(255) NULL,
  PRIMARY KEY (`id`), UNIQUE KEY `uq_project_center` (`project_id`,`cost_center`),
  CONSTRAINT `fk_ppe_project` FOREIGN KEY (`project_id`) REFERENCES `cm_projects` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `cm_team` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `project_id` INT NOT NULL,
  `employee_id` INT NOT NULL,
  `allocated_hours` DECIMAL(8,2) NOT NULL DEFAULT 0.00,
  `role_in_project` VARCHAR(100) NULL,
  `employment_type` ENUM('Diretto','Indiretto') NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), UNIQUE KEY `uq_project_employee` (`project_id`,`employee_id`),
  KEY `idx_pt_employee` (`employee_id`),
  CONSTRAINT `fk_pt_project` FOREIGN KEY (`project_id`) REFERENCES `cm_projects` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_pt_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `cm_import_batches` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `filename` VARCHAR(255) NULL,
  `kind` ENUM('interventi','commesse') NOT NULL DEFAULT 'interventi',
  `rows_total` INT NOT NULL DEFAULT 0,
  `rows_ok` INT NOT NULL DEFAULT 0,
  `rows_unmatched` INT NOT NULL DEFAULT 0,
  `created_by` INT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `cm_intervention_reports` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `report_code` VARCHAR(60) NOT NULL,
  `report_date` DATE NULL,
  `start_at` DATETIME NULL,
  `end_at` DATETIME NULL,
  `approved` TINYINT(1) NOT NULL DEFAULT 0,
  `project_id` INT NULL,
  `project_code` VARCHAR(40) NULL,
  `project_name_raw` VARCHAR(200) NULL,
  `service_type` VARCHAR(40) NULL,
  `client_id` INT NULL,
  `client_location_id` INT NULL,
  `client_raw` VARCHAR(180) NULL,
  `site_raw` VARCHAR(150) NULL,
  `client_reference` VARCHAR(150) NULL,
  `band_id` INT NULL,
  `band_raw` VARCHAR(50) NULL,
  `tech_sector` VARCHAR(100) NULL,
  `request_text` TEXT NULL,
  `ticket` VARCHAR(80) NULL,
  `work_done` TEXT NULL,
  `technician_id` INT NULL,
  `technician_raw` VARCHAR(150) NULL,
  `remote` TINYINT(1) NOT NULL DEFAULT 0,
  `on_call` TINYINT(1) NOT NULL DEFAULT 0,
  `in_working_hours` TINYINT(1) GENERATED ALWAYS AS (CASE WHEN `start_at` IS NULL THEN 0 WHEN DAYOFWEEK(`start_at`) IN (1,7) THEN 0 WHEN TIME(`start_at`) >= '08:00:00' AND TIME(`start_at`) < '18:00:00' THEN 1 ELSE 0 END) STORED,
  `planned_hours` DECIMAL(8,2) NOT NULL DEFAULT 0.00,
  `quantity_hours` DECIMAL(8,2) NOT NULL DEFAULT 0.00,
  `diff_hours` DECIMAL(8,2) NOT NULL DEFAULT 0.00,
  `extra_hours` DECIMAL(8,2) NOT NULL DEFAULT 0.00,
  `client_revenue_import` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `client_revenue_calc` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `company_cost_import` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `company_cost_calc` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `commercial_cost_calc` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `import_batch_id` INT NULL,
  `imported_by` INT NULL,
  `imported_at` DATETIME NULL,
  PRIMARY KEY (`id`), UNIQUE KEY `uq_report_code` (`report_code`),
  KEY `idx_ir_project` (`project_id`), KEY `idx_ir_tech` (`technician_id`),
  KEY `idx_ir_band` (`band_id`), KEY `idx_ir_client` (`client_id`),
  KEY `idx_ir_start` (`start_at`), KEY `idx_ir_service` (`service_type`),
  CONSTRAINT `fk_ir_project` FOREIGN KEY (`project_id`) REFERENCES `cm_projects` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_ir_tech` FOREIGN KEY (`technician_id`) REFERENCES `employees` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_ir_band` FOREIGN KEY (`band_id`) REFERENCES `cm_rate_bands` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_ir_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_ir_clientloc` FOREIGN KEY (`client_location_id`) REFERENCES `client_locations` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO `companies` (`name`,`legal_representative`,`vat_number`) VALUES ('Wenest SRL',NULL,NULL);
INSERT IGNORE INTO `companies` (`name`,`legal_representative`,`vat_number`) VALUES ('Weenergy',NULL,NULL);
INSERT IGNORE INTO `company_locations` (`company_id`,`location_name`,`address`) SELECT id,'Marcon',NULL FROM `companies` WHERE `name`='Wenest SRL';
INSERT IGNORE INTO `company_locations` (`company_id`,`location_name`,`address`) SELECT id,'Montevarchi',NULL FROM `companies` WHERE `name`='Weenergy';
INSERT IGNORE INTO `cm_company_prefix_map` (`prefix`,`company_id`) SELECT 'WTS',id FROM `companies` WHERE `name`='WETECH''S SPA SB';
INSERT IGNORE INTO `cm_company_prefix_map` (`prefix`,`company_id`) SELECT 'NIS',id FROM `companies` WHERE `name`='Nis Group srl';
INSERT IGNORE INTO `cm_company_prefix_map` (`prefix`,`company_id`) SELECT 'ANT',id FROM `companies` WHERE `name`='Antea srl';
INSERT IGNORE INTO `cm_company_prefix_map` (`prefix`,`company_id`) SELECT 'MIPS',id FROM `companies` WHERE `name`='Mips Informatica';
INSERT IGNORE INTO `cm_company_prefix_map` (`prefix`,`company_id`) SELECT 'WEN',id FROM `companies` WHERE `name`='Wenest SRL';
INSERT IGNORE INTO `cm_company_prefix_map` (`prefix`,`company_id`) SELECT 'WEE',id FROM `companies` WHERE `name`='Weenergy';
INSERT IGNORE INTO `cm_rate_bands` (`band_name`) VALUES ('Fascia A'),('Fascia B'),('Fascia C'),('Fascia D'),('Fascia E'),('Fascia F');
INSERT IGNORE INTO `cm_rate_band_rates` (`band_id`,`cost_type`,`regime`,`rate_hour`) SELECT b.id, ct.t, rg.r, 0.00 FROM `cm_rate_bands` b JOIN (SELECT 'Aziendale' AS t UNION SELECT 'Cliente' UNION SELECT 'Commerciale') ct JOIN (SELECT 'Ordinario' AS r UNION SELECT 'Reperibilità') rg;
INSERT IGNORE INTO `role_permissions` (`role_id`,`page_name`,`can_view`,`can_create`,`can_edit`,`can_delete`,`can_export`) VALUES (1,'manage_projects.php',1,1,1,1,1),(1,'project_dashboard.php',1,1,1,1,1),(1,'manage_rate_bands.php',1,1,1,1,1),(1,'import_commesse.php',1,1,1,1,1),(1,'import_intervention_reports.php',1,1,1,1,1);
ALTER TABLE `cm_intervention_reports` MODIFY COLUMN `report_code` VARCHAR(80) NOT NULL;
ALTER TABLE `cm_intervention_reports` MODIFY COLUMN `project_name_raw` VARCHAR(255) NULL;
ALTER TABLE `cm_intervention_reports` MODIFY COLUMN `service_type` VARCHAR(80) NULL;
ALTER TABLE `cm_intervention_reports` MODIFY COLUMN `client_raw` VARCHAR(255) NULL;
ALTER TABLE `cm_intervention_reports` MODIFY COLUMN `site_raw` VARCHAR(255) NULL;
ALTER TABLE `cm_intervention_reports` MODIFY COLUMN `client_reference` VARCHAR(255) NULL;
ALTER TABLE `cm_intervention_reports` MODIFY COLUMN `band_raw` VARCHAR(80) NULL;
ALTER TABLE `cm_intervention_reports` MODIFY COLUMN `tech_sector` VARCHAR(150) NULL;
ALTER TABLE `cm_intervention_reports` MODIFY COLUMN `ticket` VARCHAR(500) NULL;
ALTER TABLE `cm_intervention_reports` MODIFY COLUMN `technician_raw` VARCHAR(200) NULL;
CREATE TABLE IF NOT EXISTS `cm_alias_project` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `raw_code` VARCHAR(80) NOT NULL,
  `project_id` INT NULL,
  `ignored` TINYINT(1) NOT NULL DEFAULT 0,
  `created_by` INT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), UNIQUE KEY `uq_alias_project_raw` (`raw_code`),
  CONSTRAINT `fk_alias_project` FOREIGN KEY (`project_id`) REFERENCES `cm_projects` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `cm_alias_technician` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `raw_name` VARCHAR(200) NOT NULL,
  `employee_id` INT NULL,
  `ignored` TINYINT(1) NOT NULL DEFAULT 0,
  `created_by` INT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), UNIQUE KEY `uq_alias_tech_raw` (`raw_name`),
  CONSTRAINT `fk_alias_tech` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `cm_alias_band` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `raw_band` VARCHAR(80) NOT NULL,
  `band_id` INT NULL,
  `ignored` TINYINT(1) NOT NULL DEFAULT 0,
  `created_by` INT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), UNIQUE KEY `uq_alias_band_raw` (`raw_band`),
  CONSTRAINT `fk_alias_band` FOREIGN KEY (`band_id`) REFERENCES `cm_rate_bands` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO `role_permissions` (`role_id`,`page_name`,`can_view`,`can_create`,`can_edit`,`can_delete`,`can_export`) VALUES (1,'import_control.php',1,1,1,1,1);
ALTER TABLE `cm_team` ADD COLUMN IF NOT EXISTS `source` ENUM('Manuale','Rapporti') NOT NULL DEFAULT 'Manuale' AFTER `employment_type`;
CREATE TABLE IF NOT EXISTS `cm_timesheet_entries` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `employee_id` INT NOT NULL,
  `work_date` DATE NOT NULL,
  `project_id` INT NULL,
  `hours` DECIMAL(6,2) NOT NULL DEFAULT 0,
  `activity_type` ENUM('Ordinario','Reperibilità','Trasferta','Formazione','Ferie','Permesso','Malattia','Altro') NOT NULL DEFAULT 'Ordinario',
  `notes` VARCHAR(255) NULL,
  `created_by` INT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ts_emp_date` (`employee_id`,`work_date`),
  KEY `idx_ts_project` (`project_id`),
  CONSTRAINT `fk_ts_emp` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_ts_proj` FOREIGN KEY (`project_id`) REFERENCES `cm_projects` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `cm_project_phases` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `project_id` INT NOT NULL,
  `name` VARCHAR(150) NOT NULL,
  `start_date` DATE NULL,
  `end_date` DATE NULL,
  `progress_pct` TINYINT NOT NULL DEFAULT 0,
  `sort_order` INT NOT NULL DEFAULT 0,
  `notes` VARCHAR(255) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_phase_project` (`project_id`),
  CONSTRAINT `fk_phase_proj` FOREIGN KEY (`project_id`) REFERENCES `cm_projects` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO `app_settings` (`setting_key`,`setting_value`,`description`) VALUES ('ts_daily_hours','8','Ore lavorative giornaliere standard per il calcolo della saturazione timesheet');
INSERT IGNORE INTO `role_permissions` (`role_id`,`page_name`,`can_view`,`can_create`,`can_edit`,`can_delete`,`can_export`) VALUES (1,'timesheet.php',1,1,1,1,1),(1,'project_gantt.php',1,1,1,1,1);
INSERT INTO `app_settings` (`setting_key`,`setting_value`,`description`) VALUES ('app_version','1.7.69','Versione applicazione'),('schema_version','1.7.69','Versione schema database'),('release_label','1.7.69','Etichetta release mostrata in footer'),('proj_annual_hours','1720','Ore/uomo annue per costo orario commesse'),('proj_oneri_mult','1.42','Moltiplicatore oneri aziendali su RAL') ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);
