-- migration_pratix_ext.sql — Ingestione Pratix: schema + viste arricchite
-- Chiave di join: cm_pratix_ext.order_code = Excel `Codice` = order_code applicativo.

-- 1) Tabella di destinazione (12 campi importati + chiave + audit)
CREATE TABLE IF NOT EXISTS `cm_pratix_ext` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_code` VARCHAR(64) NOT NULL,
  `cliente_effettivo` VARCHAR(255) DEFAULT NULL,
  `cliente_fatturazione` VARCHAR(255) DEFAULT NULL,
  `progetto` VARCHAR(255) DEFAULT NULL,
  `descrizione` VARCHAR(1000) DEFAULT NULL,
  `stato` VARCHAR(100) DEFAULT NULL,
  `azienda` VARCHAR(255) DEFAULT NULL,
  `numero_documento` VARCHAR(255) DEFAULT NULL,
  `tipologia` VARCHAR(100) DEFAULT NULL,
  `totale` DECIMAL(14,2) DEFAULT NULL,
  `firma_tecnica` VARCHAR(100) DEFAULT NULL,
  `firma_commerciale` VARCHAR(100) DEFAULT NULL,
  `linea_business` VARCHAR(255) DEFAULT NULL,
  `source_file` VARCHAR(255) DEFAULT NULL,
  `imported_at` DATETIME NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pratix_ext_order_code` (`order_code`),
  KEY `idx_pratix_ext_cliente` (`cliente_effettivo`),
  KEY `idx_pratix_ext_stato` (`stato`),
  KEY `idx_pratix_ext_lb` (`linea_business`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2) Vista arricchita "Ordini Pratix": ordinativi + dati Pratix (LEFT JOIN su order_code)
CREATE OR REPLACE VIEW `v_cm_pratix_ordinativi_ext` AS
SELECT o.*,
       p.`cliente_effettivo`    AS `px_cliente_effettivo`,
       p.`cliente_fatturazione` AS `px_cliente_fatturazione`,
       p.`progetto`             AS `px_progetto`,
       p.`descrizione`          AS `px_descrizione`,
       p.`stato`                AS `px_stato`,
       p.`azienda`              AS `px_azienda`,
       p.`numero_documento`     AS `px_numero_documento`,
       p.`tipologia`            AS `px_tipologia`,
       p.`totale`               AS `px_totale`,
       p.`firma_tecnica`        AS `px_firma_tecnica`,
       p.`firma_commerciale`    AS `px_firma_commerciale`,
       p.`linea_business`       AS `px_linea_business`
FROM `v_cm_pratix_ordinativi` o
LEFT JOIN `cm_pratix_ext` p ON p.`order_code` = o.`order_code` COLLATE utf8mb4_unicode_ci;

-- 3) Vista di mappatura Commessa -> dati Pratix.
--    La commessa si lega al Pratix tramite l'order_code presente nelle righe pratix.
--    Si prende l'order_code rappresentativo (MIN) per project_code, poi il relativo ext.
CREATE OR REPLACE VIEW `v_cm_pratix_commessa_ext` AS
SELECT pr.`project_code`,
       m.`order_code`           AS `px_order_code`,
       p.`cliente_effettivo`    AS `px_cliente_effettivo`,
       p.`cliente_fatturazione` AS `px_cliente_fatturazione`,
       p.`progetto`             AS `px_progetto`,
       p.`descrizione`          AS `px_descrizione`,
       p.`stato`                AS `px_stato`,
       p.`azienda`              AS `px_azienda`,
       p.`numero_documento`     AS `px_numero_documento`,
       p.`tipologia`            AS `px_tipologia`,
       p.`totale`               AS `px_totale`,
       p.`firma_tecnica`        AS `px_firma_tecnica`,
       p.`firma_commerciale`    AS `px_firma_commerciale`,
       p.`linea_business`       AS `px_linea_business`
FROM `cm_projects` pr
LEFT JOIN (
    SELECT `project_code`, MIN(`order_code`) AS `order_code`
    FROM `v_cm_pratix_righe`
    WHERE `project_code` IS NOT NULL AND `project_code` <> ''
    GROUP BY `project_code`
) m ON m.`project_code` COLLATE utf8mb4_unicode_ci = pr.`project_code` COLLATE utf8mb4_unicode_ci
LEFT JOIN `cm_pratix_ext` p ON p.`order_code` = m.`order_code` COLLATE utf8mb4_unicode_ci;

-- 4) Registro migrazioni + allineamento versione
CREATE TABLE IF NOT EXISTS `pm_migration_sql` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `version` varchar(20) NOT NULL,
  `filename` varchar(190) NOT NULL,
  `applied_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pm_migration_v_f` (`version`,`filename`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE `app_settings` SET `setting_value`='1.9.58'
 WHERE `setting_key` IN ('app_version','schema_version','release_label');

INSERT IGNORE INTO `pm_migration_sql` (`version`,`filename`)
 VALUES ('1.9.58','migration_pratix_ext.sql');
