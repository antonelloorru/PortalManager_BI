-- ════════════════════════════════════════════════════════════════════════════
--  certV — upgrade_to_v2.4.sql
--  Script di ALLINEAMENTO del database corrente alla versione 2.4
--
--  ANALISI ESEGUITA il 03/04/2026 sul dump cert_manager_ultimo.sql
--
--  PROBLEMI RILEVATI:
--  1. app_version ancora '2.2' → deve essere '2.4'
--  2. 7 permessi mancanti per il ruolo HR Director (ruolo 2)
--  3. Permessi mancanti per config_notifiche, mass_upload, db_upgrade
--  4. Nessun permesso per le nuove pagine db_upgrade.php
--
--  STRUTTURA DB: OK (tutte le tabelle v2.4 presenti)
--  ├─ brands.priority + priority_color    ✓ presenti
--  ├─ brand_technologies                  ✓ presente
--  ├─ email_log                           ✓ presente
--  ├─ position_publications               ✓ presente
--  ├─ users.employee_id/display_name      ✓ presenti
--  ├─ candidates.education_*              ✓ presenti
--  ├─ SMTP settings in app_settings       ✓ presenti
--  ├─ idx_brands_priority                 ✓ presente
--  └─ Tutte le FK                         ✓ presenti
--
--  SICURO DA ESEGUIRE PIÙ VOLTE (INSERT IGNORE / IF NOT EXISTS)
-- ════════════════════════════════════════════════════════════════════════════

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";
SET NAMES utf8mb4;

-- ══════════════════════════════════════════════════════════════
--  1. AGGIORNA VERSIONE
-- ══════════════════════════════════════════════════════════════

UPDATE `app_settings`
SET `setting_value` = '2.4', `description` = 'Versione build'
WHERE `setting_key` = 'app_version';

-- ══════════════════════════════════════════════════════════════
--  2. PERMESSI MANCANTI — Ruolo 2 (HR Director)
--     Il ruolo 2 non può vedere: brand.php, brand_referents.php,
--     config_notifiche.php, gap_analysis.php, manager_users.php,
--     mass_upload.php, report_certificazioni.php
-- ══════════════════════════════════════════════════════════════

INSERT IGNORE INTO `role_permissions` (`role_id`, `page_name`) VALUES
  -- HR Director: accesso completo Brand & Partnership
  (2, 'brand.php'),
  (2, 'brand_referents.php'),
  (2, 'gap_analysis.php'),
  (2, 'report_certificazioni.php'),
  -- HR Director: accesso Amministrazione
  (2, 'manager_users.php'),
  (2, 'mass_upload.php'),
  (2, 'config_notifiche.php');

-- ══════════════════════════════════════════════════════════════
--  3. PERMESSI NUOVE PAGINE v2.4
--     Verifica che tutti i ruoli abbiano accesso alle pagine giuste
-- ══════════════════════════════════════════════════════════════

-- db_upgrade.php: solo Super Admin (ruolo 1 ha accesso implicito, non serve in role_permissions)
-- smtp_settings.php: già presente per ruolo 1

-- brand_technologies.php: aggiunta sicura per ruoli che dovrebbero vederlo
INSERT IGNORE INTO `role_permissions` (`role_id`, `page_name`) VALUES
  (4, 'brand_technologies.php'),
  (5, 'brand_technologies.php');

-- Ruolo 5 (Recruiter): potrebbe servire accesso a report
INSERT IGNORE INTO `role_permissions` (`role_id`, `page_name`) VALUES
  (5, 'report_certificazioni.php'),
  (5, 'training_plans.php'),
  (5, 'visualizza_storico.php');

-- ══════════════════════════════════════════════════════════════
--  4. SICUREZZA STRUTTURALE — Verifica colonne critiche
--     (IF NOT EXISTS garantisce idempotenza)
-- ══════════════════════════════════════════════════════════════

-- Queste ALTER sono no-op se le colonne esistono già (il DB corrente le ha)
ALTER TABLE `brands`
  ADD COLUMN IF NOT EXISTS `priority`       TINYINT(1) NOT NULL DEFAULT 3,
  ADD COLUMN IF NOT EXISTS `priority_color`  VARCHAR(7) NOT NULL DEFAULT '#3b82f6';

ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `employee_id`        INT DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `display_name`       VARCHAR(150) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `notifications_email` TINYINT(1) NOT NULL DEFAULT 1;

ALTER TABLE `candidates`
  ADD COLUMN IF NOT EXISTS `education_level`     VARCHAR(80) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `education_field`     VARCHAR(150) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `education_institute` VARCHAR(200) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `education_year`      VARCHAR(10) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `external_certs`      TEXT DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `test_path`           VARCHAR(255) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `lettera_path`        VARCHAR(255) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `doc_extra_path`      VARCHAR(255) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `soft_skills_notes`   TEXT DEFAULT NULL;

-- Indice priorità (no-op se esiste)
ALTER TABLE `brands` ADD INDEX IF NOT EXISTS `idx_brands_priority` (`priority`);

-- ══════════════════════════════════════════════════════════════
--  5. TABELLE v2.4 — Creazione sicura (IF NOT EXISTS)
-- ══════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS `brand_technologies` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `brand_id` INT NOT NULL,
  `category` ENUM('Tecnologia','Servizio','Prodotto') NOT NULL DEFAULT 'Tecnologia',
  `name` VARCHAR(150) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `version` VARCHAR(50) DEFAULT NULL,
  `status` ENUM('active','deprecated','eol') NOT NULL DEFAULT 'active',
  `doc_url` VARCHAR(500) DEFAULT NULL,
  `relevance` TINYINT(1) NOT NULL DEFAULT 3,
  `notes` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
  PRIMARY KEY (`id`),
  KEY `idx_bt_brand` (`brand_id`),
  KEY `idx_bt_category` (`category`),
  CONSTRAINT `fk_bt_brand` FOREIGN KEY (`brand_id`) REFERENCES `brands`(`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `email_log` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `recipient` VARCHAR(255) NOT NULL,
  `subject` VARCHAR(500) NOT NULL,
  `status` ENUM('sent','failed','queued') NOT NULL DEFAULT 'sent',
  `error_msg` TEXT DEFAULT NULL,
  `smtp_response` TEXT DEFAULT NULL,
  `module` VARCHAR(50) DEFAULT 'system',
  `related_id` INT DEFAULT NULL,
  `sent_by` INT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  PRIMARY KEY (`id`),
  KEY `idx_email_log_status` (`status`),
  KEY `idx_email_log_date` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `position_publications` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `position_id` INT NOT NULL,
  `channel` ENUM('linkedin','indeed','infojobs','glassdoor','monster','jobrapido','custom') NOT NULL DEFAULT 'linkedin',
  `channel_url` VARCHAR(500) DEFAULT NULL,
  `status` ENUM('draft','published','expired','removed') NOT NULL DEFAULT 'draft',
  `published_at` DATETIME DEFAULT NULL,
  `expires_at` DATE DEFAULT NULL,
  `published_by` INT DEFAULT NULL,
  `api_post_id` VARCHAR(255) DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  PRIMARY KEY (`id`),
  KEY `position_id` (`position_id`),
  KEY `channel` (`channel`),
  KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ══════════════════════════════════════════════════════════════
--  6. APP_SETTINGS COMPLETI v2.4
--     Inserisce TUTTI i parametri che dovrebbero esistere
-- ══════════════════════════════════════════════════════════════

INSERT IGNORE INTO `app_settings` (`setting_key`, `setting_value`, `description`) VALUES
  ('app_name', 'certV', 'Nome applicazione'),
  ('app_version', '2.4', 'Versione build'),
  ('primary_color', '#0ea5e9', 'Colore primario UI'),
  ('notify_days_1', '90', '1° alert scadenza cert'),
  ('notify_days_2', '60', '2° alert scadenza cert'),
  ('notify_days_3', '30', '3° alert scadenza cert'),
  ('notify_days_4', '7', 'Alert critico scadenza cert'),
  ('mail_from', 'certv@example.com', 'Email mittente notifiche'),
  ('mail_from_name', 'certV System', 'Nome mittente notifiche'),
  ('employee_code_prefix', 'EMP-', 'Prefisso matricola dipendenti'),
  ('agency_contract_alert_days', '60', 'Alert scadenza contratti agenzie'),
  ('compliance_warning_pct', '80', 'Soglia compliance gialla (%)'),
  ('compliance_critical_pct', '60', 'Soglia compliance rossa (%)'),
  ('smtp_enabled', '0', 'Abilita invio email via SMTP'),
  ('smtp_host', '', 'Server SMTP'),
  ('smtp_port', '587', 'Porta SMTP'),
  ('smtp_encryption', 'tls', 'Crittografia: tls, ssl, none'),
  ('smtp_user', '', 'Username SMTP'),
  ('smtp_pass', '', 'Password SMTP'),
  ('smtp_auth', '1', 'Richiede autenticazione'),
  ('smtp_timeout', '15', 'Timeout connessione secondi'),
  ('smtp_debug', '0', 'Log debug SMTP'),
  ('smtp_test_email', '', 'Email per test invio'),
  ('smtp_verified', '0', 'Ultimo test SMTP riuscito');

-- ══════════════════════════════════════════════════════════════
--  7. DISTRIBUTORI (relazione N:M con ranking Primario/Secondario)
-- ══════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS `distributors` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(200) NOT NULL,
  `type` ENUM('Distributore','VAD','Rivenditore','Aggregatore') NOT NULL DEFAULT 'Distributore',
  `website` VARCHAR(255) DEFAULT NULL,
  `address` VARCHAR(300) DEFAULT NULL,
  `city` VARCHAR(100) DEFAULT NULL,
  `province` VARCHAR(5) DEFAULT NULL,
  `vat_number` VARCHAR(30) DEFAULT NULL,
  `status` ENUM('active','paused','inactive') NOT NULL DEFAULT 'active',
  `commercial_name` VARCHAR(150) DEFAULT NULL,
  `commercial_email` VARCHAR(150) DEFAULT NULL,
  `commercial_phone` VARCHAR(30) DEFAULT NULL,
  `academy_name` VARCHAR(150) DEFAULT NULL,
  `academy_email` VARCHAR(150) DEFAULT NULL,
  `academy_phone` VARCHAR(30) DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_distributor_name` (`name`),
  KEY `idx_dist_status` (`status`),
  KEY `idx_dist_type` (`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `brand_distributors` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `brand_id` INT NOT NULL,
  `distributor_id` INT NOT NULL,
  `ranking` ENUM('primary','secondary') NOT NULL DEFAULT 'primary',
  `priority_order` TINYINT NOT NULL DEFAULT 1,
  `is_volume` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Acquisto a Volume',
  `is_value` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Acquisto a Valore',
  `is_academy` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Academy/Formazione',
  `commercial_ref` VARCHAR(150) DEFAULT NULL,
  `commercial_email` VARCHAR(150) DEFAULT NULL,
  `commercial_phone` VARCHAR(30) DEFAULT NULL,
  `academy_ref` VARCHAR(150) DEFAULT NULL,
  `academy_email` VARCHAR(150) DEFAULT NULL,
  `academy_phone` VARCHAR(30) DEFAULT NULL,
  `contract_ref` VARCHAR(100) DEFAULT NULL,
  `discount_pct` DECIMAL(5,2) DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_brand_dist` (`brand_id`,`distributor_id`),
  KEY `idx_bd_brand` (`brand_id`),
  KEY `idx_bd_dist` (`distributor_id`),
  KEY `idx_bd_ranking` (`ranking`),
  CONSTRAINT `fk_bd_brand` FOREIGN KEY (`brand_id`) REFERENCES `brands`(`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_bd_dist` FOREIGN KEY (`distributor_id`) REFERENCES `distributors`(`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `role_permissions` (`role_id`, `page_name`) VALUES
  (2, 'brand_distributors.php'),
  (3, 'brand_distributors.php'),
  (4, 'brand_distributors.php');

-- ══════════════════════════════════════════════════════════════
--  8. VERIFICA FINALE (senza information_schema)
-- ══════════════════════════════════════════════════════════════

SELECT 'Upgrade completato' AS risultato,
       (SELECT setting_value FROM app_settings WHERE setting_key = 'app_version') AS versione;

SHOW TABLES;

COMMIT;
