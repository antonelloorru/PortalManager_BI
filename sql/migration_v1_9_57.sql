-- migration_v1_9_57.sql
-- PortalManager v1.9.57 — Fix routing 'Personalizza menu': la selezione di un
-- ruolo non redirige piu' alla Home. Intervento solo-PHP (app/UrlHelper.php):
-- nessun delta di schema. Allinea la versione. RUN1/RUN2 err=0.

CREATE TABLE IF NOT EXISTS `pm_migration_sql` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `version` varchar(20) NOT NULL,
  `filename` varchar(190) NOT NULL,
  `applied_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pm_migration_v_f` (`version`,`filename`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE `app_settings` SET `setting_value`='1.9.57'
 WHERE `setting_key` IN ('app_version','schema_version','release_label');

INSERT IGNORE INTO `pm_migration_sql` (`version`,`filename`)
 VALUES ('1.9.57','migration_v1_9_57.sql');
