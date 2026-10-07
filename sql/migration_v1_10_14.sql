-- migration_v1_10_14.sql — Sito web WordPress (pm-ats): configurazione guidata e pagina impostazioni della connessione.
-- Nuove chiavi app_settings wpats.setup_done / setup_at / setup_step / remote_info. Il segreto resta solo in .env.php.
-- Installazioni già configurate (URL presente) segnate come configurazione completata solo alla prima applicazione. Idempotente.

CREATE TABLE IF NOT EXISTS `pm_migration_sql` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `version` varchar(20) NOT NULL, `filename` varchar(190) NOT NULL,
  `applied_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`), UNIQUE KEY `uq_pm_migration_v_f` (`version`,`filename`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `app_settings` (`setting_key`,`setting_value`,`description`)
SELECT 'wpats.setup_done', '1', 'Sito WordPress: configurazione guidata completata (0/1)'
  FROM `app_settings` b
 WHERE b.`setting_key` = 'wpats.base_url' AND TRIM(b.`setting_value`) <> ''
   AND NOT EXISTS (SELECT 1 FROM `pm_migration_sql` m WHERE m.`version` = '1.10.14');

INSERT IGNORE INTO `app_settings` (`setting_key`,`setting_value`,`description`) VALUES
 ('wpats.setup_done','0','Sito WordPress: configurazione guidata completata (0/1)'),
 ('wpats.setup_at','','Sito WordPress: data completamento configurazione guidata'),
 ('wpats.setup_step','1','Sito WordPress: ultimo passo raggiunto della configurazione guidata (1-5)'),
 ('wpats.remote_info','','Sito WordPress: versioni e compatibilita del plugin (ultimo test, JSON)');

UPDATE `app_settings` SET `setting_value`='1.10.14'
 WHERE `setting_key` IN ('app_version','schema_version','release_label');

INSERT IGNORE INTO `pm_migration_sql` (`version`,`filename`) VALUES ('1.10.14','migration_v1_10_14.sql');
