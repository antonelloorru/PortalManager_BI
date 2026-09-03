-- =====================================================================
-- PortalManager v1.9.24 - Sistema Gestione Candidature (Careers Portal)
-- Migration idempotente MariaDB 10.4.32 / PHP 8.2.12
-- =====================================================================

-- ------- 1. JOB POSITIONS (posizioni aperte pubblicabili) -------------
CREATE TABLE IF NOT EXISTS `job_positions` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `slug`             VARCHAR(160) NOT NULL,
  `title`            VARCHAR(200) NOT NULL,
  `department`       VARCHAR(120) NULL,
  `location`         VARCHAR(160) NULL,
  `contract_type`    VARCHAR(80)  NULL,
  `seniority`        VARCHAR(80)  NULL,
  `description`      MEDIUMTEXT   NULL,
  `requirements`     MEDIUMTEXT   NULL,
  `benefits`         MEDIUMTEXT   NULL,
  `status`           ENUM('draft','open','closed','archived') NOT NULL DEFAULT 'draft',
  `openings`         SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  `published_at`     DATETIME NULL,
  `expires_at`       DATETIME NULL,
  `owner_user_id`    INT UNSIGNED NULL,
  `company_id`       INT UNSIGNED NULL,
  `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_job_slug` (`slug`),
  KEY `idx_status_pub` (`status`,`published_at`),
  KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------- 2. CANDIDATES (anagrafica candidato, deduplicata su email) ---
CREATE TABLE IF NOT EXISTS `candidates` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `first_name`       VARCHAR(80)  NOT NULL,
  `last_name`        VARCHAR(80)  NOT NULL,
  `email`            VARCHAR(190) NOT NULL,
  `email_norm`       VARCHAR(190) GENERATED ALWAYS AS (LOWER(TRIM(`email`))) STORED,
  `phone`            VARCHAR(40)  NULL,
  `fiscal_code`      VARCHAR(32)  NULL,
  `birth_date`       DATE NULL,
  `city`             VARCHAR(120) NULL,
  `country`          VARCHAR(80)  NULL,
  `linkedin_url`     VARCHAR(255) NULL,
  `source`           VARCHAR(60)  NULL DEFAULT 'careers_portal',
  `consent_privacy`  TINYINT(1) NOT NULL DEFAULT 0,
  `consent_marketing` TINYINT(1) NOT NULL DEFAULT 0,
  `consent_ts`       DATETIME NULL,
  `consent_ip`       VARBINARY(16) NULL,
  `linked_employee_id` INT UNSIGNED NULL,
  `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cand_email` (`email_norm`),
  KEY `idx_cand_fc` (`fiscal_code`),
  KEY `idx_cand_linked` (`linked_employee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------- 3. APPLICATIONS (candidatura = candidato x posizione) --------
CREATE TABLE IF NOT EXISTS `job_applications` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `candidate_id`     INT UNSIGNED NOT NULL,
  `position_id`      INT UNSIGNED NOT NULL,
  `status`           ENUM('new','screening','interview','offer','hired','rejected','withdrawn') NOT NULL DEFAULT 'new',
  `cover_letter`     MEDIUMTEXT NULL,
  `cv_file_id`       INT UNSIGNED NULL,
  `salary_expectation` DECIMAL(10,2) NULL,
  `availability`     VARCHAR(120) NULL,
  `submitted_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `submitted_ip`     VARBINARY(16) NULL,
  `submitted_ua`     VARCHAR(255) NULL,
  `assigned_to`      INT UNSIGNED NULL,
  `rating`           TINYINT UNSIGNED NULL,
  `notes`            MEDIUMTEXT NULL,
  `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_app_cand_pos` (`candidate_id`,`position_id`),
  KEY `idx_app_status` (`status`),
  KEY `idx_app_position` (`position_id`,`status`),
  CONSTRAINT `fk_app_candidate` FOREIGN KEY (`candidate_id`) REFERENCES `candidates`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_app_position`  FOREIGN KEY (`position_id`)  REFERENCES `job_positions`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------- 4. CV FILES (metadata + path su disco protetto) --------------
CREATE TABLE IF NOT EXISTS `candidate_cv_files` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `candidate_id`     INT UNSIGNED NOT NULL,
  `original_name`    VARCHAR(255) NOT NULL,
  `stored_name`      VARCHAR(190) NOT NULL,
  `mime_type`        VARCHAR(120) NOT NULL,
  `size_bytes`       INT UNSIGNED NOT NULL,
  `sha256`           CHAR(64) NOT NULL,
  `is_active`        TINYINT(1) NOT NULL DEFAULT 1,
  `uploaded_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cv_stored` (`stored_name`),
  KEY `idx_cv_cand` (`candidate_id`),
  KEY `idx_cv_sha` (`sha256`),
  CONSTRAINT `fk_cv_candidate` FOREIGN KEY (`candidate_id`) REFERENCES `candidates`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------- 5. API CLIENTS (chiavi HMAC per portale esterno) -------------
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

-- ------- 6. RATE LIMIT & AUDIT ----------------------------------------
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

-- ------- 7. VISTA pubblica posizioni open (safe fields only) ----------
CREATE OR REPLACE VIEW `v_public_open_positions` AS
SELECT
  `id`, `slug`, `title`, `department`, `location`,
  `contract_type`, `seniority`, `description`, `requirements`, `benefits`,
  `openings`, `published_at`, `expires_at`
FROM `job_positions`
WHERE `status` = 'open'
  AND (`published_at` IS NULL OR `published_at` <= NOW())
  AND (`expires_at` IS NULL OR `expires_at` >= NOW());

-- ------- 8. PERMESSI RBAC (voci nuove per pagine gestione) ------------
INSERT IGNORE INTO `permissions` (`code`,`label`,`section`) VALUES
  ('manage_job_positions.php','Gestione Posizioni Aperte','Recruiting'),
  ('manage_applications.php','Gestione Candidature','Recruiting'),
  ('manage_public_api_clients.php','Chiavi API Portale Esterno','Sistema');

-- Assegnazione ruoli standard (Super Admin=1, HR Director=2, Recruiter=5)
INSERT IGNORE INTO `role_permissions` (`role_id`,`permission_code`)
SELECT r.id, p.code
FROM `roles` r
CROSS JOIN (SELECT 'manage_job_positions.php' code UNION SELECT 'manage_applications.php' UNION SELECT 'manage_public_api_clients.php') p
WHERE r.id IN (1);

INSERT IGNORE INTO `role_permissions` (`role_id`,`permission_code`)
SELECT r.id, p.code
FROM `roles` r
CROSS JOIN (SELECT 'manage_job_positions.php' code UNION SELECT 'manage_applications.php') p
WHERE r.id IN (2,5);

-- ------- 9. SETTINGS di modulo ----------------------------------------
INSERT IGNORE INTO `app_settings` (`skey`,`svalue`,`descr`) VALUES
  ('careers.cv_max_bytes','5242880','Dimensione massima CV in byte (default 5 MB)'),
  ('careers.cv_allowed_mime','application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document','MIME accettati per il CV'),
  ('careers.rate_email_check_per_hour','20','Tentativi orari di verifica email per IP'),
  ('careers.rate_apply_per_day','5','Candidature massime al giorno per IP'),
  ('careers.storage_path','uploads/candidates','Path relativo di archiviazione CV (fuori webroot in prod)'),
  ('careers.notify_email','hr@example.com','Email HR per notifica nuova candidatura');

-- ------- 10. MIGRATION LOG --------------------------------------------
INSERT INTO `pm_migration_sql` (`version`,`filename`,`applied_at`)
VALUES ('1.9.24','migration_v1_9_24.sql',NOW())
ON DUPLICATE KEY UPDATE `applied_at`=NOW();

-- Baseline additivo: v1.9.24 non tocca cm_projects, cm_intervention_reports, dgb_*.
-- Nuove tabelle isolate nel namespace Careers (job_positions, candidates, ecc.).
