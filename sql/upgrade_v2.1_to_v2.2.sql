-- ============================================================
--  certV  —  UPGRADE v2.1  →  v2.2
--  Separazione Anagrafica Personale / Utenze di Accesso
-- ============================================================
--  PREREQUISITO: backup prima di eseguire
--    mysqldump -u root cert_management > backup_pre_v2.2.sql
--  ESECUZIONE: phpMyAdmin → cert_management → tab SQL → Esegui
--  IDEMPOTENTE: sicuro da eseguire più volte
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = '';
START TRANSACTION;

-- ────────────────────────────────────────────────────────────
-- STEP 1  Crea employees
-- ────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `employees` (
  `id`               INT          NOT NULL AUTO_INCREMENT,
  `company_id`       INT          DEFAULT 1,
  `location_id`      INT          DEFAULT NULL,
  `work_mode_id`     INT          DEFAULT NULL,
  `first_name`       VARCHAR(100) NOT NULL,
  `last_name`        VARCHAR(100) NOT NULL,
  `fiscal_code`      VARCHAR(20)  DEFAULT NULL,
  `date_of_birth`    DATE         DEFAULT NULL,
  `phone`            VARCHAR(30)  DEFAULT NULL,
  `personal_email`   VARCHAR(150) DEFAULT NULL,
  `employee_code`    VARCHAR(30)  DEFAULT NULL,
  `job_title`        VARCHAR(100) DEFAULT NULL,
  `department`       VARCHAR(100) DEFAULT NULL,
  `contract_type`    ENUM('Indeterminato','Determinato','Somministrazione','Consulenza','Stage','Partita IVA') DEFAULT 'Indeterminato',
  `hire_date`        DATE         DEFAULT NULL,
  `end_date`         DATE         DEFAULT NULL,
  `status`           ENUM('active','inactive','terminated') DEFAULT 'active',
  `bio`              TEXT         DEFAULT NULL,
  `technical_skills` TEXT         DEFAULT NULL,
  `soft_skills`      TEXT         DEFAULT NULL,
  `cv_path`          VARCHAR(255) DEFAULT NULL,
  `notes`            TEXT         DEFAULT NULL,
  `created_at`       TIMESTAMP    NOT NULL DEFAULT current_timestamp(),
  `updated_at`       TIMESTAMP    NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_employee_code` (`employee_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Anagrafica HR — separata dalle utenze v2.2';

-- FK employees → azienda/sede/lavoro
ALTER TABLE `employees`
  ADD CONSTRAINT IF NOT EXISTS `emp_fk_co`  FOREIGN KEY (`company_id`)   REFERENCES `companies`         (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT IF NOT EXISTS `emp_fk_loc` FOREIGN KEY (`location_id`)  REFERENCES `company_locations` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT IF NOT EXISTS `emp_fk_wm`  FOREIGN KEY (`work_mode_id`) REFERENCES `work_modes`        (`id`) ON DELETE SET NULL;

-- ────────────────────────────────────────────────────────────
-- STEP 2  Migra users → employees (stessi ID, idempotente)
-- ────────────────────────────────────────────────────────────

INSERT INTO `employees`
  (id, company_id, location_id, work_mode_id,
   first_name, last_name, job_title, hire_date, end_date,
   status, bio, technical_skills, soft_skills, cv_path, created_at)
SELECT
  id, company_id, location_id, work_mode_id,
  first_name, last_name, job_title, hire_date, end_date,
  CASE status WHEN 'active' THEN 'active' ELSE 'inactive' END,
  bio, technical_skills, soft_skills, cv_path, created_at
FROM `users`
ON DUPLICATE KEY UPDATE
  first_name = VALUES(first_name),
  last_name  = VALUES(last_name),
  job_title  = VALUES(job_title);

-- ────────────────────────────────────────────────────────────
-- STEP 3  Aggiungi employee_id + display_name a users
-- ────────────────────────────────────────────────────────────

ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `employee_id`  INT DEFAULT NULL COMMENT 'FK → employees.id',
  ADD COLUMN IF NOT EXISTS `display_name` VARCHAR(150) DEFAULT NULL COMMENT 'Per account di servizio senza employee';

-- Collega ogni user all'employee con stesso ID
UPDATE `users` u
  INNER JOIN `employees` e ON e.id = u.id
SET u.employee_id = u.id
WHERE u.employee_id IS NULL;

-- FK users.employee_id
ALTER TABLE `users`
  ADD CONSTRAINT IF NOT EXISTS `u_fk_employee`
  FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE SET NULL;

-- ────────────────────────────────────────────────────────────
-- STEP 4  user_certifications: rinomina user_id → employee_id
-- ────────────────────────────────────────────────────────────

ALTER TABLE `user_certifications` DROP FOREIGN KEY IF EXISTS `uc_fk1`;

ALTER TABLE `user_certifications`
  CHANGE COLUMN IF EXISTS `user_id` `employee_id` INT NOT NULL
    COMMENT 'FK → employees.id (ex user_id v2.1)';

ALTER TABLE `user_certifications`
  ADD COLUMN IF NOT EXISTS `uploaded_by` INT DEFAULT NULL
    COMMENT 'FK → users.id — chi ha caricato';

ALTER TABLE `user_certifications`
  ADD CONSTRAINT IF NOT EXISTS `uc_fk_emp`
  FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE;

-- ────────────────────────────────────────────────────────────
-- STEP 5  training_plans: rinomina user_id → employee_id
-- ────────────────────────────────────────────────────────────

ALTER TABLE `training_plans` DROP FOREIGN KEY IF EXISTS `tp_fk1`;

ALTER TABLE `training_plans`
  CHANGE COLUMN IF EXISTS `user_id` `employee_id` INT NOT NULL
    COMMENT 'FK → employees.id (ex user_id v2.1)';

ALTER TABLE `training_plans`
  ADD CONSTRAINT IF NOT EXISTS `tp_fk_emp`
  FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE;

-- ────────────────────────────────────────────────────────────
-- STEP 6  planned_exams: rinomina user_id → employee_id
-- ────────────────────────────────────────────────────────────

ALTER TABLE `planned_exams` DROP FOREIGN KEY IF EXISTS `pe_fk1`;

ALTER TABLE `planned_exams`
  CHANGE COLUMN IF EXISTS `user_id` `employee_id` INT NOT NULL
    COMMENT 'FK → employees.id (ex user_id v2.1)';

ALTER TABLE `planned_exams`
  ADD CONSTRAINT IF NOT EXISTS `pe_fk_emp`
  FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE;

-- ────────────────────────────────────────────────────────────
-- STEP 7  brand_referents: rinomina user_id → employee_id
-- ────────────────────────────────────────────────────────────

ALTER TABLE `brand_referents` DROP FOREIGN KEY IF EXISTS `bref_fk2`;

ALTER TABLE `brand_referents`
  CHANGE COLUMN IF EXISTS `user_id` `employee_id` INT NOT NULL
    COMMENT 'FK → employees.id (ex user_id v2.1)';

ALTER TABLE `brand_referents`
  ADD CONSTRAINT IF NOT EXISTS `bref_fk_emp`
  FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE;

-- ────────────────────────────────────────────────────────────
-- STEP 8  user_brands → employee_brands (nuova tabella)
-- ────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `employee_brands` (
  `employee_id` INT NOT NULL,
  `brand_id`    INT NOT NULL,
  PRIMARY KEY (`employee_id`, `brand_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `employee_brands` (employee_id, brand_id)
SELECT user_id, brand_id FROM `user_brands`;

ALTER TABLE `employee_brands`
  ADD CONSTRAINT IF NOT EXISTS `eb_fk_emp`   FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT IF NOT EXISTS `eb_fk_brand` FOREIGN KEY (`brand_id`)    REFERENCES `brands`    (`id`) ON DELETE CASCADE;

-- ────────────────────────────────────────────────────────────
-- STEP 9  Rimuovi colonne anagrafiche da users
-- ────────────────────────────────────────────────────────────

-- Rimuovi vecchie FK sulle colonne da droppare
ALTER TABLE `users`
  DROP FOREIGN KEY IF EXISTS `u_fk_co`,
  DROP FOREIGN KEY IF EXISTS `u_fk_loc`,
  DROP FOREIGN KEY IF EXISTS `u_fk_wm`;

ALTER TABLE `users`
  DROP COLUMN IF EXISTS `first_name`,
  DROP COLUMN IF EXISTS `last_name`,
  DROP COLUMN IF EXISTS `job_title`,
  DROP COLUMN IF EXISTS `hire_date`,
  DROP COLUMN IF EXISTS `end_date`,
  DROP COLUMN IF EXISTS `company_id`,
  DROP COLUMN IF EXISTS `location_id`,
  DROP COLUMN IF EXISTS `work_mode_id`,
  DROP COLUMN IF EXISTS `bio`,
  DROP COLUMN IF EXISTS `technical_skills`,
  DROP COLUMN IF EXISTS `soft_skills`,
  DROP COLUMN IF EXISTS `cv_path`;

ALTER TABLE `users`
  DROP INDEX IF EXISTS `company_id`,
  DROP INDEX IF EXISTS `location_id`,
  DROP INDEX IF EXISTS `work_mode_id`;

-- ────────────────────────────────────────────────────────────
-- STEP 10  Aggiungi permessi manage_employees.php
-- ────────────────────────────────────────────────────────────

INSERT IGNORE INTO `role_permissions` (role_id, page_name) VALUES
  (1,'manage_employees.php'),
  (2,'manage_employees.php');

-- ────────────────────────────────────────────────────────────
-- STEP 11  Versione e log
-- ────────────────────────────────────────────────────────────

INSERT INTO `app_settings` (setting_key, setting_value, description)
VALUES ('app_version', '2.2', 'Separazione anagrafica/accessi')
ON DUPLICATE KEY UPDATE setting_value = '2.2';

INSERT IGNORE INTO `app_settings` (setting_key, setting_value, description)
VALUES ('employee_code_prefix', 'EMP-', 'Prefisso matricola dipendenti');

INSERT INTO `app_logs` (category, level, message, ip_address)
VALUES ('Migration', 'success', 'Upgrade v2.1→v2.2: employees creata, anagrafica migrata, FK aggiornate', '127.0.0.1');

COMMIT;
SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- VERIFICA POST-UPGRADE
-- ============================================================
/*
SELECT 'employees migrati' lbl, COUNT(*) n FROM employees
UNION SELECT 'users totali',              COUNT(*) FROM users
UNION SELECT 'users senza employee_id',   COUNT(*) FROM users WHERE employee_id IS NULL;

SELECT TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME
-- v2.4: query originale usava information_schema. Verificare FK con:
-- SHOW CREATE TABLE users;
-- SHOW CREATE TABLE user_certifications;
-- SHOW CREATE TABLE training_plans;
*/
