-- =====================================================================
-- PortalManager v1.9.25 — HOTFIX employee_profile.php
-- Nessun DDL applicativo. Registrazione versione nel log migration.
-- Auto-crea `pm_migration_sql` se assente (installazioni prive del registro).
-- =====================================================================

CREATE TABLE IF NOT EXISTS `pm_migration_sql` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `version`    VARCHAR(20)  NOT NULL,
  `filename`   VARCHAR(190) NOT NULL,
  `applied_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pm_migration_v_f` (`version`,`filename`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `pm_migration_sql` (`version`,`filename`,`applied_at`)
VALUES ('1.9.25','migration_v1_9_25.sql',NOW())
ON DUPLICATE KEY UPDATE `applied_at`=NOW();
