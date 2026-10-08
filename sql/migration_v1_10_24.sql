-- migration_v1_10_24.sql — Gestione Commesse: pagina Ricerca (cm_search.php) con vista tabellare filtrabile su tutti gli archivi del modulo
-- ed export CSV, XLSX, DOCX e PDF. Permesso virtuale cm_search_economics.php per le colonne economiche.
-- Nessuna modifica di schema. Idempotente: INSERT IGNORE, i permessi gia personalizzati non vengono toccati.

CREATE TABLE IF NOT EXISTS `pm_migration_sql` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `version` varchar(20) NOT NULL, `filename` varchar(190) NOT NULL,
  `applied_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`), UNIQUE KEY `uq_pm_migration_v_f` (`version`,`filename`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- catalogo permessi
INSERT IGNORE INTO `permissions` (`name`,`label`,`description`,`module`) VALUES
 ('cm_search.php','Ricerca','Vista tabellare filtrabile su tutti gli archivi di Gestione Commesse, export CSV XLSX DOCX PDF. Ogni archivio richiede la vista della pagina sorgente','Gestione Commesse'),
 ('cm_search_economics.php','Ricerca - importi e costi','Permesso virtuale: colonne economiche (valori, costi, ricavi, margini, tariffe) nella Ricerca e nei suoi export','Gestione Commesse');

-- Super Admin
INSERT IGNORE INTO `role_permissions` (`role_id`,`page_name`,`can_view`,`can_create`,`can_edit`,`can_delete`,`can_export`) VALUES
 (1,'cm_search.php',1,0,0,0,1), (1,'cm_search_economics.php',1,0,0,0,1);

-- Ricerca: ruoli che vedono almeno una pagina sorgente del modulo (export se ne esportano almeno una)
INSERT IGNORE INTO `role_permissions` (`role_id`,`page_name`,`can_view`,`can_create`,`can_edit`,`can_delete`,`can_export`)
SELECT rp.`role_id`, 'cm_search.php', 1, 0, 0, 0, MAX(rp.`can_export`)
  FROM `role_permissions` rp
 WHERE rp.`can_view` = 1
   AND rp.`page_name` IN ('manage_projects.php','project_dashboard.php','import_intervention_reports.php','workload_overview.php','project_gantt.php',
                          'pratix_orders.php','professionals.php','tech_registry.php','dgb_activities.php','manage_projects_prj.php','timesheet.php','service_soc.php')
 GROUP BY rp.`role_id`;

-- importi e costi: stesso perimetro del Report direzionale (portafoglio, margini, costi)
INSERT IGNORE INTO `role_permissions` (`role_id`,`page_name`,`can_view`,`can_create`,`can_edit`,`can_delete`,`can_export`)
SELECT rp.`role_id`, 'cm_search_economics.php', 1, 0, 0, 0, rp.`can_export`
  FROM `role_permissions` rp
 WHERE rp.`page_name` = 'dir_report.php' AND rp.`can_view` = 1;

UPDATE `app_settings` SET `setting_value`='1.10.24'
 WHERE `setting_key` IN ('app_version','schema_version','release_label');

INSERT IGNORE INTO `pm_migration_sql` (`version`,`filename`) VALUES ('1.10.24','migration_v1_10_24.sql');
