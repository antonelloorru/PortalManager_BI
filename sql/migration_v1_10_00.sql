-- migration_v1_10_00.sql — Progetti PRJ, fase 3 di 6: motore di calcolo PrjCalc e PrjRepo.
-- Nessuna modifica di schema (tabelle cm_prj* dalla v1.9.99). Indice di supporto alle letture as-of e versione.

CREATE TABLE IF NOT EXISTS `pm_migration_sql` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `version` varchar(20) NOT NULL, `filename` varchar(190) NOT NULL,
  `applied_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`), UNIQUE KEY `uq_pm_migration_v_f` (`version`,`filename`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX IF NOT EXISTS `idx_pcres_metrica` ON `cm_prj_calc_result` (`ambito`,`metrica`);

UPDATE `app_settings` SET `setting_value`='1.10.00'
 WHERE `setting_key` IN ('app_version','schema_version','release_label');

INSERT IGNORE INTO `pm_migration_sql` (`version`,`filename`) VALUES ('1.10.00','migration_v1_10_00.sql');
