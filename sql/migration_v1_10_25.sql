-- migration_v1_10_25.sql — Gestione Commesse: Relazione Tecnici (tech_report.php) con schede Tecnici e Rapporti di intervento,
-- filtri della Relazione di Servizio IT piu tipologia contratto e provenienza ticket, stampa ed export CSV XLSX DOCX PDF.
-- Permesso virtuale tech_report_economics.php per produzione teorica e valore addebitato. Nessuna modifica di schema. Idempotente.

CREATE TABLE IF NOT EXISTS `pm_migration_sql` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `version` varchar(20) NOT NULL, `filename` varchar(190) NOT NULL,
  `applied_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`), UNIQUE KEY `uq_pm_migration_v_f` (`version`,`filename`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `permissions` (`name`,`label`,`description`,`module`) VALUES
 ('tech_report.php','Relazione Tecnici','Tecnico per codice linea, metriche di dettaglio, moduli valorizzati e non valorizzati, rapporti di intervento per tipologia e provenienza, stampa ed export','Gestione Commesse'),
 ('tech_report_economics.php','Relazione Tecnici - valori','Permesso virtuale: produzione teorica e valore addebitato dei moduli nella Relazione Tecnici e nei suoi export','Gestione Commesse');

INSERT IGNORE INTO `role_permissions` (`role_id`,`page_name`,`can_view`,`can_create`,`can_edit`,`can_delete`,`can_export`) VALUES
 (1,'tech_report.php',1,0,0,0,1), (1,'tech_report_economics.php',1,0,0,0,1);

-- Relazione Tecnici: stessi ruoli della Relazione di Servizio IT
INSERT IGNORE INTO `role_permissions` (`role_id`,`page_name`,`can_view`,`can_create`,`can_edit`,`can_delete`,`can_export`)
SELECT rp.`role_id`, 'tech_report.php', 1, 0, 0, 0, rp.`can_export`
  FROM `role_permissions` rp
 WHERE rp.`page_name` = 'it_service.php' AND rp.`can_view` = 1;

-- valori: stesso perimetro del Report direzionale
INSERT IGNORE INTO `role_permissions` (`role_id`,`page_name`,`can_view`,`can_create`,`can_edit`,`can_delete`,`can_export`)
SELECT rp.`role_id`, 'tech_report_economics.php', 1, 0, 0, 0, rp.`can_export`
  FROM `role_permissions` rp
 WHERE rp.`page_name` = 'dir_report.php' AND rp.`can_view` = 1;

UPDATE `app_settings` SET `setting_value`='1.10.25'
 WHERE `setting_key` IN ('app_version','schema_version','release_label');

INSERT IGNORE INTO `pm_migration_sql` (`version`,`filename`) VALUES ('1.10.25','migration_v1_10_25.sql');
