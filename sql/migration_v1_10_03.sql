-- migration_v1_10_03.sql — Progetti PRJ, fase 6 di 6: Stimato vs Consuntivo, scostamenti e alert, export XLSX e DOCX.
-- Nuova tabella cm_prj_deviation (scostamenti mensili), vista v_cm_prj_alert_da_rilevare letta da AlertEngine,
-- regole prj_scost_fte e prj_scost_costo in cm_alert_rules (create disattivate), versione. Idempotente.

CREATE TABLE IF NOT EXISTS `pm_migration_sql` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `version` varchar(20) NOT NULL, `filename` varchar(190) NOT NULL,
  `applied_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`), UNIQUE KEY `uq_pm_migration_v_f` (`version`,`filename`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `cm_prj_deviation` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `prj_id` int(11) NOT NULL,
  `sp_project_id` int(11) NOT NULL COMMENT 'Commessa SP al momento del calcolo (nessuna FK)',
  `ym` char(7) NOT NULL COMMENT 'Mese AAAA-MM',
  `metrica` enum('fte','costo') NOT NULL,
  `stimato` decimal(18,4) DEFAULT NULL,
  `consuntivo` decimal(18,4) DEFAULT NULL,
  `scostamento_pct` decimal(10,2) DEFAULT NULL COMMENT '(consuntivo - stimato) / stimato x 100',
  `computed_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pdev` (`prj_id`,`ym`,`metrica`),
  KEY `idx_pdev_sp` (`sp_project_id`),
  CONSTRAINT `fk_pdev_prj` FOREIGN KEY (`prj_id`) REFERENCES `cm_prj` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Scostamenti mensili stimato/consuntivo dei Progetti PRJ';

CREATE INDEX IF NOT EXISTS `idx_ir_project_date` ON `cm_intervention_reports` (`project_id`,`report_date`);
CREATE INDEX IF NOT EXISTS `idx_pact_prj` ON `cm_prj_actual` (`prj_id`,`computed_at`);

-- regole di soglia: create disattivate, si attivano da Commesse e Alert o direttamente in cm_alert_rules
INSERT IGNORE INTO `cm_alert_rules` (`code`,`label`,`descrizione`,`kind`,`metric`,`threshold_warn`,`threshold_alarm`,`direction`,`to_agent`,`to_director`,`cadence`,`is_active`) VALUES
 ('prj_scost_fte','Progetto PRJ: FTE reali fuori stima','Scostamento percentuale fra FTE reali e FTE stimati dallo scenario di riferimento, ultimo mese completo','soglia','prj_scostamento',10.00,20.00,'sopra',1,1,'evento',0),
 ('prj_scost_costo','Progetto PRJ: costo reale fuori stima','Scostamento percentuale fra costo reale e costo stimato dallo scenario di riferimento, ultimo mese completo','soglia','prj_scostamento',10.00,20.00,'sopra',1,1,'evento',0);

-- scostamenti dell ultimo mese completo per progetto e metrica, oltre la soglia di attenzione (valore assoluto)
CREATE OR REPLACE VIEW `v_cm_prj_alert_da_rilevare` AS
SELECT
    r.`code`                                                           AS rule_code,
    sp.`project_code`                                                  AS project_code,
    d.`sp_project_id`                                                  AS project_id,
    sp.`commercial_ref`                                                AS agent_name,
    CASE WHEN ABS(d.`scostamento_pct`) >= r.`threshold_alarm` THEN 'allarme' ELSE 'attenzione' END AS severity,
    CONCAT(r.`code`, '|', p.`prj_code`, '|', d.`ym`, '|',
           CASE WHEN ABS(d.`scostamento_pct`) >= r.`threshold_alarm` THEN 'A' ELSE 'W' END) AS signature,
    d.`scostamento_pct`                                                AS metric_value,
    CASE WHEN ABS(d.`scostamento_pct`) >= r.`threshold_alarm` THEN r.`threshold_alarm` ELSE r.`threshold_warn` END AS threshold,
    CONCAT(p.`prj_code`, ' su ', sp.`project_code`, ': ', IF(d.`metrica` = 'fte', 'FTE', 'costo'), ' ', d.`ym`, ' ',
           IF(d.`scostamento_pct` >= 0, '+', ''), FORMAT(d.`scostamento_pct`, 1), '% rispetto alla stima') AS message
FROM `cm_prj_deviation` d
JOIN `cm_prj` p         ON p.`id` = d.`prj_id` AND p.`sp_project_id` = d.`sp_project_id`
JOIN `cm_projects` sp   ON sp.`id` = d.`sp_project_id`
JOIN `cm_alert_rules` r ON r.`code` = CONCAT('prj_scost_', d.`metrica`)
WHERE d.`ym` = (SELECT MAX(d2.`ym`) FROM `cm_prj_deviation` d2
                 WHERE d2.`prj_id` = d.`prj_id` AND d2.`metrica` = d.`metrica` AND d2.`ym` < DATE_FORMAT(CURDATE(), '%Y-%m'))
  AND ABS(d.`scostamento_pct`) >= r.`threshold_warn`;

UPDATE `app_settings` SET `setting_value`='1.10.03'
 WHERE `setting_key` IN ('app_version','schema_version','release_label');

INSERT IGNORE INTO `pm_migration_sql` (`version`,`filename`) VALUES ('1.10.03','migration_v1_10_03.sql');
