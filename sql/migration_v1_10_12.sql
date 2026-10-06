-- migration_v1_10_12.sql — Report DOCX, XLSX, CSV, PDF (generale e per tecnico) per Service Desk, Service SOC e Attivita e Rendicontazione DGB.
-- Nessuna modifica di schema: report generati dal filtro principale con PmReport e PdfWriter. Allineamento versione. Idempotente.

CREATE TABLE IF NOT EXISTS `pm_migration_sql` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `version` varchar(20) NOT NULL, `filename` varchar(190) NOT NULL,
  `applied_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`), UNIQUE KEY `uq_pm_migration_v_f` (`version`,`filename`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE `app_settings` SET `setting_value`='1.10.12'
 WHERE `setting_key` IN ('app_version','schema_version','release_label');

INSERT IGNORE INTO `pm_migration_sql` (`version`,`filename`) VALUES ('1.10.12','migration_v1_10_12.sql');
