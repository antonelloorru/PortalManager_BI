-- migration_v1_10_17.sql — Sito web WordPress (pm-ats): analisi di rete nella diagnostica (porte, host alternativo, proxy di sistema)
-- e impostazione IP forzato per NAT hairpin o DNS interno. Idempotente.

CREATE TABLE IF NOT EXISTS `pm_migration_sql` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `version` varchar(20) NOT NULL, `filename` varchar(190) NOT NULL,
  `applied_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`), UNIQUE KEY `uq_pm_migration_v_f` (`version`,`filename`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `app_settings` (`setting_key`,`setting_value`,`description`) VALUES
 ('wpats.resolve_ip','','Sito WordPress: IP forzato per il nome host del sito (NAT hairpin o DNS interno), vuoto = DNS');

UPDATE `app_settings` SET `setting_value`='1.10.17'
 WHERE `setting_key` IN ('app_version','schema_version','release_label');

INSERT IGNORE INTO `pm_migration_sql` (`version`,`filename`) VALUES ('1.10.17','migration_v1_10_17.sql');
