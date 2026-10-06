-- migration_v1_10_07.sql — Service SOC: pipeline di sincronizzazione unica e Unita Organizzativa SOC.
-- Registro delle esecuzioni della pipeline, impostazioni (intervallo, cartella di arrivo, unita), unita SOC garantita. Idempotente.

CREATE TABLE IF NOT EXISTS `pm_migration_sql` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `version` varchar(20) NOT NULL, `filename` varchar(190) NOT NULL,
  `applied_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`), UNIQUE KEY `uq_pm_migration_v_f` (`version`,`filename`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `cm_soc_sync_runs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `trigger_type` enum('manuale','pianificata','giornaliera','caricamento') NOT NULL DEFAULT 'pianificata',
  `status` enum('running','ok','parziale','errore') NOT NULL DEFAULT 'running',
  `started_at` datetime NOT NULL,
  `finished_at` datetime DEFAULT NULL,
  `seconds` decimal(10,1) DEFAULT NULL,
  `files` int(11) NOT NULL DEFAULT 0,
  `files_err` int(11) NOT NULL DEFAULT 0,
  `db_status` varchar(20) DEFAULT NULL COMMENT 'ok, errore, non configurato',
  `rows_read` int(11) NOT NULL DEFAULT 0,
  `rows_new` int(11) NOT NULL DEFAULT 0,
  `rows_updated` int(11) NOT NULL DEFAULT 0,
  `tickets` int(11) DEFAULT NULL,
  `uo_assigned` int(11) NOT NULL DEFAULT 0,
  `uo_conflicts` int(11) NOT NULL DEFAULT 0,
  `message` varchar(1000) DEFAULT NULL,
  `detail` text DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_socr_started` (`started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Esecuzioni della pipeline di sincronizzazione del Service SOC';

INSERT IGNORE INTO `app_settings` (`setting_key`,`setting_value`,`description`) VALUES
 ('soc.interval_min','60','Service SOC: minuti fra due esecuzioni automatiche della pipeline'),
 ('soc.inbox_dir','uploads/soc_inbox','Service SOC: cartella di arrivo degli export XLSX/CSV (importati e archiviati dalla pipeline)'),
 ('soc.uo_code','SOC','Service SOC: codice dell Unita Organizzativa dei tecnici del servizio'),
 ('soc.uo_auto','1','Service SOC: assegna automaticamente all unita SOC i tecnici che erogano il servizio (0/1)');

INSERT IGNORE INTO `cm_tech_units` (`code`,`name`,`description`,`color`,`is_oncall`,`sort_order`,`is_active`)
VALUES ('SOC','SOC','Security Operation Center','#7c3aed',0,70,1);

UPDATE `app_settings` SET `setting_value`='1.10.07'
 WHERE `setting_key` IN ('app_version','schema_version','release_label');

INSERT IGNORE INTO `pm_migration_sql` (`version`,`filename`) VALUES ('1.10.07','migration_v1_10_07.sql');
