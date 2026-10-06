-- migration_v1_10_06.sql — Gestione Commesse: sezione Service SOC.
-- Eventi dei ticket del sistema di gestione SOC (export XLSX/CSV o sincronizzazione da database SOC separato),
-- ticket ricostruiti, lotti di import, connessione al DB SOC, abbinamenti persone e clienti, impostazioni, permessi.
-- Idempotente. La password del DB SOC e cifrata AES-256-GCM con APP_SECRET (.env.php), mai in chiaro.

CREATE TABLE IF NOT EXISTS `pm_migration_sql` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `version` varchar(20) NOT NULL, `filename` varchar(190) NOT NULL,
  `applied_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`), UNIQUE KEY `uq_pm_migration_v_f` (`version`,`filename`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `cm_soc_batches` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `source` enum('file','db') NOT NULL,
  `origin` varchar(255) DEFAULT NULL COMMENT 'nome file o connessione',
  `trigger_type` enum('manuale','pianificata') NOT NULL DEFAULT 'manuale',
  `status` enum('running','ok','warn','error') NOT NULL DEFAULT 'running',
  `rows_read` int(11) NOT NULL DEFAULT 0,
  `rows_inserted` int(11) NOT NULL DEFAULT 0,
  `rows_updated` int(11) NOT NULL DEFAULT 0,
  `rows_unchanged` int(11) NOT NULL DEFAULT 0,
  `rows_skipped` int(11) NOT NULL DEFAULT 0,
  `tickets` int(11) DEFAULT NULL,
  `date_from` datetime DEFAULT NULL,
  `date_to` datetime DEFAULT NULL,
  `message` varchar(1000) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `started_at` datetime NOT NULL,
  `finished_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_socb_started` (`started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Lotti di import del Service SOC';

CREATE TABLE IF NOT EXISTS `cm_soc_events` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `event_key` char(40) NOT NULL COMMENT 'sha1(ticket, istante, tipo, stati, progressivo): stessa chiave da file e da DB',
  `source` enum('file','db') NOT NULL,
  `source_ref` varchar(40) DEFAULT NULL COMMENT 'id evento nel DB SOC',
  `batch_id` int(11) DEFAULT NULL,
  `event_at` datetime NOT NULL,
  `ticket_code` varchar(60) NOT NULL,
  `event_label` varchar(80) DEFAULT NULL COMMENT 'Evento come nel gestionale',
  `event_kind` enum('supporto','cliente','nota','apertura','altro') NOT NULL DEFAULT 'altro',
  `status_before` varchar(40) DEFAULT NULL,
  `status_after` varchar(40) DEFAULT NULL,
  `author_name` varchar(190) DEFAULT NULL,
  `subject` varchar(400) DEFAULT NULL,
  `queue_name` varchar(120) DEFAULT NULL,
  `mailbox` varchar(120) DEFAULT NULL,
  `owner_name` varchar(150) DEFAULT NULL COMMENT 'Responsabile',
  `assignee_name` varchar(150) DEFAULT NULL COMMENT 'Incaricato',
  `ticket_type` varchar(20) DEFAULT NULL,
  `category` varchar(120) DEFAULT NULL,
  `resolution` varchar(60) DEFAULT NULL,
  `client_name` varchar(190) DEFAULT NULL,
  `soc_contract` varchar(60) DEFAULT NULL COMMENT 'Commessa nel sistema SOC',
  `duration_min` int(11) DEFAULT NULL COMMENT 'Durata del ticket riportata dal gestionale (minuti)',
  `reply_min` int(11) DEFAULT NULL COMMENT 'messaggi del cliente: minuti alla risposta successiva del supporto',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_socev_key` (`event_key`),
  KEY `idx_socev_ticket` (`ticket_code`,`event_at`),
  KEY `idx_socev_at` (`event_at`),
  KEY `idx_socev_kind` (`event_kind`,`event_at`),
  KEY `idx_socev_author` (`author_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Eventi dei ticket del sistema di gestione SOC';

CREATE TABLE IF NOT EXISTS `cm_soc_tickets` (
  `ticket_code` varchar(60) NOT NULL,
  `title` varchar(400) DEFAULT NULL,
  `opened_at` datetime NOT NULL,
  `first_support_at` datetime DEFAULT NULL,
  `last_event_at` datetime NOT NULL,
  `last_kind` varchar(20) DEFAULT NULL,
  `closed_at` datetime DEFAULT NULL COMMENT 'ultimo passaggio a CHIUSO o CHIUSO DAL CLIENTE se lo stato attuale e chiuso',
  `status_now` varchar(40) DEFAULT NULL,
  `is_closed` tinyint(1) NOT NULL DEFAULT 0,
  `resolution` varchar(60) DEFAULT NULL,
  `category` varchar(120) DEFAULT NULL,
  `ticket_type` varchar(20) DEFAULT NULL,
  `queue_name` varchar(120) DEFAULT NULL,
  `mailbox` varchar(120) DEFAULT NULL,
  `client_name` varchar(190) DEFAULT NULL,
  `soc_contract` varchar(60) DEFAULT NULL,
  `owner_name` varchar(150) DEFAULT NULL,
  `assignee_name` varchar(150) DEFAULT NULL,
  `n_events` int(11) NOT NULL DEFAULT 0,
  `n_support` int(11) NOT NULL DEFAULT 0,
  `n_customer` int(11) NOT NULL DEFAULT 0,
  `n_notes` int(11) NOT NULL DEFAULT 0,
  `n_reopen` int(11) NOT NULL DEFAULT 0,
  `opened_by` varchar(20) DEFAULT NULL COMMENT 'tipo del primo evento: supporto, cliente, nota, apertura',
  `first_response_min` int(11) DEFAULT NULL,
  `avg_reply_min` int(11) DEFAULT NULL COMMENT 'media dei tempi di risposta del supporto ai messaggi del cliente',
  `resolution_min` int(11) DEFAULT NULL,
  `duration_min` int(11) DEFAULT NULL,
  `client_id` int(11) DEFAULT NULL,
  `owner_employee_id` int(11) DEFAULT NULL,
  `assignee_employee_id` int(11) DEFAULT NULL,
  `rebuilt_at` datetime NOT NULL,
  PRIMARY KEY (`ticket_code`),
  KEY `idx_soct_opened` (`opened_at`),
  KEY `idx_soct_closed` (`closed_at`),
  KEY `idx_soct_last` (`last_event_at`),
  KEY `idx_soct_assignee` (`assignee_name`),
  KEY `idx_soct_client` (`client_name`),
  KEY `idx_soct_emp` (`assignee_employee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Ticket SOC ricostruiti dagli eventi (rigenerata a ogni import)';

CREATE TABLE IF NOT EXISTS `cm_soc_people` (
  `name` varchar(150) NOT NULL COMMENT 'nome come nel sistema SOC',
  `employee_id` int(11) DEFAULT NULL,
  `is_manual` tinyint(1) NOT NULL DEFAULT 0,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`name`),
  KEY `idx_socp_emp` (`employee_id`),
  CONSTRAINT `fk_socp_emp` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Abbinamento persone SOC - anagrafica dipendenti';

CREATE TABLE IF NOT EXISTS `cm_soc_clients` (
  `name` varchar(190) NOT NULL COMMENT 'cliente come nel sistema SOC',
  `client_id` int(11) DEFAULT NULL,
  `is_manual` tinyint(1) NOT NULL DEFAULT 0,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`name`),
  KEY `idx_socc_client` (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Abbinamento clienti SOC - anagrafica clienti';

CREATE TABLE IF NOT EXISTS `cm_soc_source_db` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `label` varchar(100) NOT NULL DEFAULT 'Gestionale SOC',
  `driver` varchar(20) NOT NULL DEFAULT 'mysql',
  `host` varchar(255) NOT NULL,
  `port` int(11) NOT NULL DEFAULT 3306,
  `dbname` varchar(128) NOT NULL,
  `username` varchar(128) NOT NULL,
  `password_enc` text DEFAULT NULL,
  `source_schema` varchar(64) DEFAULT NULL,
  `timeout` int(11) NOT NULL DEFAULT 10,
  `window_days` int(11) NOT NULL DEFAULT 30 COMMENT 'sincronizzazione incrementale: ultimi N giorni (0 = tutto)',
  `ticket_prefix` varchar(20) DEFAULT NULL COMMENT 'es. WES_ per limitare ai ticket SOC',
  `extract_sql` mediumtext DEFAULT NULL COMMENT 'query di estrazione personalizzata (vuota = predefinita)',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `last_sync_at` datetime DEFAULT NULL,
  `last_sync_note` varchar(255) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Connessione in sola lettura al DB del sistema di gestione SOC';

ALTER TABLE `cm_soc_events` ADD COLUMN IF NOT EXISTS `reply_min` int(11) DEFAULT NULL COMMENT 'messaggi del cliente: minuti alla risposta successiva del supporto' AFTER `duration_min`;
ALTER TABLE `cm_soc_tickets` ADD COLUMN IF NOT EXISTS `opened_by` varchar(20) DEFAULT NULL AFTER `n_reopen`;
ALTER TABLE `cm_soc_tickets` ADD COLUMN IF NOT EXISTS `avg_reply_min` int(11) DEFAULT NULL AFTER `first_response_min`;

INSERT IGNORE INTO `app_settings` (`setting_key`,`setting_value`,`description`) VALUES
 ('soc.sla_risposta_ore','4','Service SOC: ore entro cui il supporto risponde a un messaggio del cliente'),
 ('soc.presidio_ore','24','Service SOC: ore senza risposta del supporto oltre cui un ticket aperto va presidiato'),
 ('soc.sync_enabled','0','Service SOC: sincronizzazione pianificata dal DB SOC (0/1)'),
 ('soc.closed_states','CHIUSO,CHIUSO DAL CLIENTE','Service SOC: stati che chiudono il ticket');

INSERT IGNORE INTO `role_permissions` (`role_id`,`page_name`,`can_view`,`can_create`,`can_edit`,`can_delete`,`can_export`)
SELECT rp.`role_id`, 'service_soc.php', rp.`can_view`, rp.`can_create`, rp.`can_edit`, rp.`can_delete`, rp.`can_export`
  FROM `role_permissions` rp
 WHERE rp.`page_name` = 'service_desk.php' AND rp.`can_view` = 1;

DELETE FROM `app_settings` WHERE `setting_key` = 'soc.sla_presa_ore';

UPDATE `app_settings` SET `setting_value`='1.10.06'
 WHERE `setting_key` IN ('app_version','schema_version','release_label');

INSERT IGNORE INTO `pm_migration_sql` (`version`,`filename`) VALUES ('1.10.06','migration_v1_10_06.sql');
