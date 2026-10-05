-- migration_v1_10_05.sql — Recruiting: sincronizzazione con il sito WordPress (plugin pm-ats).
-- PortalManager invia le posizioni aperte e preleva le candidature. Registro sincronizzazioni, mappa delle
-- candidature importate (idempotenza), canale wordpress nelle pubblicazioni, impostazioni wpats, permessi. Idempotente.
-- Il segreto condiviso NON sta nel database: va in .env.php come PM_WPATS_SECRET.

CREATE TABLE IF NOT EXISTS `pm_migration_sql` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `version` varchar(20) NOT NULL, `filename` varchar(190) NOT NULL,
  `applied_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`), UNIQUE KEY `uq_pm_migration_v_f` (`version`,`filename`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wp_ats_sync_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `operation` enum('test','push','pull') NOT NULL,
  `trigger_type` enum('manuale','pianificata','modifica') NOT NULL DEFAULT 'manuale',
  `status` enum('running','ok','warn','error') NOT NULL DEFAULT 'running',
  `started_at` datetime NOT NULL,
  `finished_at` datetime DEFAULT NULL,
  `jobs_sent` int(11) DEFAULT NULL,
  `jobs_created` int(11) DEFAULT NULL,
  `jobs_updated` int(11) DEFAULT NULL,
  `jobs_withdrawn` int(11) DEFAULT NULL,
  `apps_fetched` int(11) DEFAULT NULL,
  `apps_imported` int(11) DEFAULT NULL,
  `apps_failed` int(11) DEFAULT NULL,
  `message` varchar(1000) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL COMMENT 'utente che ha avviato (NULL = pianificata)',
  PRIMARY KEY (`id`),
  KEY `idx_wasl_started` (`started_at`),
  KEY `idx_wasl_op` (`operation`,`status`,`started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Registro sincronizzazioni con il sito WordPress';

CREATE TABLE IF NOT EXISTS `wp_ats_imports` (
  `wp_uuid` char(32) NOT NULL COMMENT 'identificativo della candidatura nel plugin',
  `wp_app_id` int(11) DEFAULT NULL,
  `candidate_id` int(11) DEFAULT NULL,
  `application_id` int(11) DEFAULT NULL,
  `position_id` int(11) DEFAULT NULL,
  `document_id` int(11) DEFAULT NULL,
  `status` enum('imported','failed') NOT NULL DEFAULT 'imported',
  `error` varchar(255) DEFAULT NULL,
  `imported_at` datetime NOT NULL,
  `acked_at` datetime DEFAULT NULL COMMENT 'conferma ricevuta dal sito',
  PRIMARY KEY (`wp_uuid`),
  KEY `idx_wai_candidate` (`candidate_id`),
  KEY `idx_wai_imported` (`imported_at`),
  CONSTRAINT `fk_wai_candidate` FOREIGN KEY (`candidate_id`) REFERENCES `candidates` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Candidature importate dal sito WordPress (idempotenza)';

ALTER TABLE `position_publications`
  MODIFY `channel` enum('linkedin','indeed','infojobs','glassdoor','monster','jobrapido','custom','wordpress') NOT NULL DEFAULT 'linkedin';

INSERT IGNORE INTO `app_settings` (`setting_key`,`setting_value`,`description`) VALUES
 ('wpats.enabled','0','Sito WordPress: sincronizzazione attiva (0/1)'),
 ('wpats.base_url','','Sito WordPress: URL API del plugin pm-ats (es. https://www.sito.it/wp-json/pm-ats/v1)'),
 ('wpats.client_id','portalmanager','Sito WordPress: client ID (uguale a quello del plugin)'),
 ('wpats.verify_tls','1','Sito WordPress: verifica certificato TLS (0/1)'),
 ('wpats.ca_file','','Sito WordPress: file CA (cacert.pem) se PHP non ne ha uno'),
 ('wpats.proxy','','Sito WordPress: proxy HTTP in uscita (host:porta)'),
 ('wpats.timeout','20','Sito WordPress: timeout richieste (secondi)'),
 ('wpats.push_on_change','1','Sito WordPress: invio immediato delle posizioni a ogni modifica (0/1)'),
 ('wpats.pull_batch','20','Sito WordPress: candidature per richiesta');

INSERT IGNORE INTO `role_permissions` (`role_id`,`page_name`,`can_view`,`can_create`,`can_edit`,`can_delete`,`can_export`)
SELECT rp.`role_id`, 'wp_ats_sync.php', 1, 0, rp.`can_edit`, 0, 0
  FROM `role_permissions` rp
 WHERE rp.`page_name` = 'publish_posizione.php' AND rp.`can_view` = 1;

UPDATE `app_settings` SET `setting_value`='1.10.05'
 WHERE `setting_key` IN ('app_version','schema_version','release_label');

INSERT IGNORE INTO `pm_migration_sql` (`version`,`filename`) VALUES ('1.10.05','migration_v1_10_05.sql');
