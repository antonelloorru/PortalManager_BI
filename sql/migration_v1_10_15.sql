-- migration_v1_10_15.sql — Report Direzionale: schede per tipologia (ACM, WTS-CSS, WTS-CC, WTS-MEG, NV_, Moduli di intervento)
-- con vista, stampa ed export DOCX / XLSX / CSV / PDF dal filtro principale. Nuova impostazione della tolleranza In-Line ACM.
-- Nessuna modifica di schema. Idempotente.

CREATE TABLE IF NOT EXISTS `pm_migration_sql` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `version` varchar(20) NOT NULL, `filename` varchar(190) NOT NULL,
  `applied_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`), UNIQUE KEY `uq_pm_migration_v_f` (`version`,`filename`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `app_settings` (`setting_key`,`setting_value`,`description`) VALUES
 ('dir.acm_tolleranza_pct','5','Report direzionale: tolleranza In-Line ACM in punti percentuali sul consumo a listino (100 +/- valore)');

UPDATE `app_settings` SET `setting_value`='1.10.15'
 WHERE `setting_key` IN ('app_version','schema_version','release_label');

INSERT IGNORE INTO `pm_migration_sql` (`version`,`filename`) VALUES ('1.10.15','migration_v1_10_15.sql');
