-- =====================================================================
-- PortalManager v1.9.26 — Careers Portal ALIGNED (aggancio schema reale)
-- Non ricrea candidates / candidate_applications / candidate_documents /
-- job_positions: già presenti nel gestionale.
-- Aggiunge solo:
--   - public_api_clients / public_api_rate_limit / public_api_audit
--   - view v_public_open_positions (sui campi reali di job_positions)
--   - colonne opzionali su candidates/candidate_applications per tracciare
--     provenienza portale esterno
--   - settings e permessi RBAC coerenti con lo schema (setting_key,
--     page_name/can_view/can_create/can_edit/can_delete/can_export)
-- Idempotente. Compat MariaDB 10.4.32.
-- =====================================================================

-- ------- 1. TABELLE NUOVE (public API) ---------------------------------
CREATE TABLE IF NOT EXISTS `public_api_clients` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `client_id`        VARCHAR(64) NOT NULL,
  `client_secret_hash` CHAR(64) NOT NULL,
  `label`            VARCHAR(120) NOT NULL,
  `scopes`           VARCHAR(255) NOT NULL DEFAULT 'positions:read,candidates:check,applications:write',
  `allowed_origins`  VARCHAR(500) NULL,
  `allowed_ips`      VARCHAR(500) NULL,
  `is_active`        TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_used_at`     DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_client_id` (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `public_api_rate_limit` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `bucket_key`       VARCHAR(190) NOT NULL,
  `endpoint`         VARCHAR(80) NOT NULL,
  `hit_at`           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ip`               VARBINARY(16) NULL,
  PRIMARY KEY (`id`),
  KEY `idx_bucket_time` (`bucket_key`,`hit_at`),
  KEY `idx_endpoint_time` (`endpoint`,`hit_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `public_api_audit` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `client_id`        VARCHAR(64) NULL,
  `endpoint`         VARCHAR(80) NOT NULL,
  `method`           VARCHAR(8) NOT NULL,
  `http_status`      SMALLINT UNSIGNED NOT NULL,
  `ip`               VARBINARY(16) NULL,
  `user_agent`       VARCHAR(255) NULL,
  `request_id`       CHAR(32) NULL,
  `error_code`       VARCHAR(60) NULL,
  `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_audit_client_time` (`client_id`,`created_at`),
  KEY `idx_audit_endpoint_time` (`endpoint`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------- 2. COLONNE OPZIONALI SU candidates -----------------------------
ALTER TABLE `candidates` ADD COLUMN IF NOT EXISTS `submitted_ip`   VARBINARY(16) NULL COMMENT 'IP invio dal portale esterno';
ALTER TABLE `candidates` ADD COLUMN IF NOT EXISTS `submitted_ua`   VARCHAR(255)  NULL COMMENT 'User-Agent invio';
ALTER TABLE `candidates` ADD COLUMN IF NOT EXISTS `submitted_ref`  CHAR(32)      NULL COMMENT 'Request-ID API';
ALTER TABLE `candidates` ADD COLUMN IF NOT EXISTS `consent_marketing` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Consenso marketing/future opportunità';
CREATE INDEX IF NOT EXISTS `idx_cand_email_norm` ON `candidates` (`email`);

-- ------- 3. COLONNE OPZIONALI SU candidate_applications -----------------
ALTER TABLE `candidate_applications` ADD COLUMN IF NOT EXISTS `source_channel` VARCHAR(60) NULL DEFAULT 'internal' COMMENT 'internal | careers_portal | ...';
ALTER TABLE `candidate_applications` ADD COLUMN IF NOT EXISTS `submitted_ip`   VARBINARY(16) NULL;
ALTER TABLE `candidate_applications` ADD COLUMN IF NOT EXISTS `submitted_ua`   VARCHAR(255)  NULL;
ALTER TABLE `candidate_applications` ADD COLUMN IF NOT EXISTS `api_request_id` CHAR(32)      NULL;

-- ------- 4. VISTA PUBBLICA (sulle colonne REALI di job_positions) -------
-- Espone SOLO i campi necessari al portale esterno.
-- Filtra per status='open' e finestra temporale opened_at/target_date.
CREATE OR REPLACE VIEW `v_public_open_positions` AS
SELECT
  `id`,
  `title`,
  `department`,
  `location`,
  `contract_type`,
  `remote_policy`,
  `description`,
  `required_skills`,
  `nice_to_have`,
  `hard_skills`,
  `soft_skills`,
  `benefits`,
  `we_offer`,
  `presentation_text`,
  `gender_disclaimer`,
  `offer_info`,
  `positions_expected`,
  `hires_count`,
  `opened_at`,
  `target_date`
FROM `job_positions`
WHERE `status` = 'open'
  AND (`opened_at`   IS NULL OR `opened_at`   <= CURRENT_DATE())
  AND (`target_date` IS NULL OR `target_date` >= CURRENT_DATE());

-- ------- 5. RBAC (role_permissions con page_name + granularità reale) ---
-- Super Admin (1): tutti
INSERT IGNORE INTO `role_permissions` (`role_id`,`page_name`,`can_view`,`can_create`,`can_edit`,`can_delete`,`can_export`)
VALUES (1,'manage_public_api_clients.php',1,1,1,1,1);
-- Le pagine candidate/positions esistenti hanno già i propri permessi.
-- Aggiungo solo il permesso nuovo per la gestione delle chiavi API.

-- ------- 6. SETTINGS (schema reale: setting_key / setting_value / description)
INSERT IGNORE INTO `app_settings` (`setting_key`,`setting_value`,`description`) VALUES
  ('careers.cv_max_bytes','5242880','Dimensione massima CV in byte (default 5 MB)');
INSERT IGNORE INTO `app_settings` (`setting_key`,`setting_value`,`description`) VALUES
  ('careers.cv_allowed_mime','application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document','MIME accettati per il CV');
INSERT IGNORE INTO `app_settings` (`setting_key`,`setting_value`,`description`) VALUES
  ('careers.rate_email_check_per_hour','20','Tentativi orari verifica email per IP');
INSERT IGNORE INTO `app_settings` (`setting_key`,`setting_value`,`description`) VALUES
  ('careers.rate_apply_per_day','5','Candidature massime al giorno per IP');
INSERT IGNORE INTO `app_settings` (`setting_key`,`setting_value`,`description`) VALUES
  ('careers.storage_path','uploads/cv_imports','Path relativo di archiviazione CV (allineato a candidate_documents.file_path)');
INSERT IGNORE INTO `app_settings` (`setting_key`,`setting_value`,`description`) VALUES
  ('careers.notify_email','hr@example.com','Email HR per notifica nuova candidatura');
INSERT IGNORE INTO `app_settings` (`setting_key`,`setting_value`,`description`) VALUES
  ('careers.public_source_tag','Portale','Valore di candidates.source (ENUM: Agenzia|LinkedIn|Referral|Portale|Altro)');

-- ------- 7. MIGRATION LOG (auto-crea tabella se assente) ---------------
CREATE TABLE IF NOT EXISTS `pm_migration_sql` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `version`    VARCHAR(20)  NOT NULL,
  `filename`   VARCHAR(190) NOT NULL,
  `applied_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pm_migration_v_f` (`version`,`filename`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `pm_migration_sql` (`version`,`filename`,`applied_at`)
VALUES ('1.9.26','migration_v1_9_26.sql',NOW())
ON DUPLICATE KEY UPDATE `applied_at`=NOW();

-- ------- 8. BUMP VERSIONE APP (pattern PortalManager) -------------------
INSERT INTO `app_settings` (`setting_key`,`setting_value`,`description`)
VALUES ('app_version','1.9.26','PortalManager — Careers Portal (aggancio schema reale)')
ON DUPLICATE KEY UPDATE `setting_value`='1.9.26', `description`=VALUES(`description`);
