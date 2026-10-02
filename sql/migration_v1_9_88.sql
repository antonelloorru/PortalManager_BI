-- migration_v1_9_88.sql — Relazione di Servizio IT: perimetro = tutto l'eseguito nel periodo (data del modulo).
-- Giorni lavorati: niente piu' distinzione commesse attive / chiuse alla data e niente linee escluse.
-- Le ore non valorizzate (senza tariffa di listino) restano nel conteggio dei giorni e sono esposte a parte.

CREATE TABLE IF NOT EXISTS `pm_migration_sql` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `version` varchar(20) NOT NULL, `filename` varchar(190) NOT NULL,
  `applied_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`), UNIQUE KEY `uq_pm_migration_v_f` (`version`,`filename`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 1) riga elementare: un modulo di intervento, datato con la sua data di esecuzione
CREATE OR REPLACE VIEW `v_cm_it_giorni_base` AS
SELECT
    r.`id`                                          AS report_id,
    r.`report_date`                                 AS giorno,
    DATE_FORMAT(r.`report_date`, '%Y-%m')           AS anno_mese,
    r.`technician_raw`                              AS operatore,
    COALESCE(n.`ordina`, LOWER(r.`technician_raw`)) AS ordina,
    COALESCE(NULLIF(TRIM(r.`tech_sector`), ''), '(non indicata)') AS area_tecnologica,
    r.`project_code`                                AS commessa,
    COALESCE(cl.`name`, p.`client_raw`)             AS cliente,
    COALESCE(p.`service_line`, '(nessuna)')         AS codice_linea,
    COALESCE(cm.`label`, p.`service_line`, '(nessuna)') AS contratto,
    p.`operational_status`                          AS stato_commessa,
    CASE WHEN COALESCE(p.`operational_status`,'') IN ('Chiusa','Annullata','Persa')
         THEN 0 ELSE 1 END                          AS commessa_attiva,
    ROUND(COALESCE(r.`quantity_hours`, 0), 2)       AS ore,
    ROUND(COALESCE(r.`extra_hours`, 0), 2)          AS ore_extra,
    COALESCE(fa.`fascia`,
        CASE
            WHEN DAYOFWEEK(r.`report_date`) IN (1, 7) THEN 'D'
            WHEN a.`date_start` IS NULL               THEN 'C'
            WHEN TIME_TO_SEC(TIME(a.`date_start`)) BETWEEN 32400 AND 46800
              OR TIME_TO_SEC(TIME(a.`date_start`)) BETWEEN 50400 AND 64800 THEN 'C'
            ELSE 'D'
        END)                                        AS fascia,
    CASE WHEN fa.`fascia` IS NOT NULL THEN 'attivita' ELSE 'dedotta da orario' END AS fascia_origine,
    CASE
        WHEN COALESCE(r.`quantity_hours`, 0) >= 8 THEN 'D'
        WHEN COALESCE(r.`quantity_hours`, 0) >= 4 THEN 'HD'
        ELSE 'H'
    END                                             AS um,
    ur.`rate_value`                                 AS tariffa_ora,
    CASE WHEN ur.`rate_value` IS NOT NULL
         THEN ROUND(COALESCE(r.`quantity_hours`, 0) * ur.`rate_value`, 2) END AS produzione_teorica,
    CASE WHEN ur.`rate_value` IS NOT NULL THEN 1 ELSE 0 END AS valorizzata,
    ROUND(COALESCE(r.`company_cost_import`, 0), 2)  AS valore_addebitato
FROM `cm_intervention_reports` r
JOIN `cm_projects` p              ON p.`id` = r.`project_id`
LEFT JOIN `clients` cl            ON cl.`id` = p.`client_id`
LEFT JOIN `cm_contract_models` cm ON cm.`service_line` = p.`service_line`
LEFT JOIN `v_cm_nomi` n           ON n.`forma` = r.`technician_raw`
LEFT JOIN `dgb_forms_activity` a  ON a.`id` = r.`dgb_activity_id`
LEFT JOIN `cm_um_fasce` fa        ON fa.`id_activitytype` = a.`id_activitytype`
LEFT JOIN `cm_contract_rates` ur
       ON ur.`project_code`  = r.`project_code`
      AND ur.`activity_type` = CONCAT('FASCIA_', COALESCE(fa.`fascia`,
            CASE WHEN DAYOFWEEK(r.`report_date`) IN (1,7) THEN 'D'
                 WHEN a.`date_start` IS NULL THEN 'C'
                 WHEN TIME_TO_SEC(TIME(a.`date_start`)) BETWEEN 32400 AND 46800
                   OR TIME_TO_SEC(TIME(a.`date_start`)) BETWEEN 50400 AND 64800 THEN 'C'
                 ELSE 'D' END))
      AND ur.`rate_unit`     = CASE
            WHEN COALESCE(r.`quantity_hours`, 0) >= 8 THEN 'D'
            WHEN COALESCE(r.`quantity_hours`, 0) >= 4 THEN 'HD'
            ELSE 'H' END
      AND ur.`rate_nature`   = 'R'
      AND ur.`rate_value`    > 0
WHERE r.`report_date` IS NOT NULL
  AND r.`technician_raw` IS NOT NULL AND r.`technician_raw` <> '';

-- 2) viste di riepilogo storiche: stesso perimetro (nessun filtro sulle attive)
CREATE OR REPLACE VIEW `v_cm_it_giorni_operatore` AS
SELECT `operatore`, `ordina`,
       COUNT(DISTINCT `giorno`) AS giorni_lavorati,
       COUNT(*) AS interventi,
       ROUND(SUM(`ore`), 2) AS ore,
       ROUND(SUM(CASE WHEN `valorizzata` = 1 THEN `ore` ELSE 0 END), 2) AS ore_valorizzate,
       ROUND(SUM(CASE WHEN `valorizzata` = 0 THEN `ore` ELSE 0 END), 2) AS ore_non_valorizzate,
       ROUND(SUM(`ore`) / 8, 1) AS giornate_equiv,
       COUNT(DISTINCT `area_tecnologica`) AS aree,
       COUNT(DISTINCT `commessa`) AS commesse,
       COUNT(DISTINCT `codice_linea`) AS linee,
       ROUND(SUM(`produzione_teorica`), 2) AS produzione_teorica,
       MIN(`giorno`) AS dal, MAX(`giorno`) AS al
  FROM `v_cm_it_giorni_base`
 GROUP BY `operatore`, `ordina`;

CREATE OR REPLACE VIEW `v_cm_it_giorni_area` AS
SELECT a.`operatore`, a.`ordina`, a.`area_tecnologica`,
       COUNT(DISTINCT a.`giorno`) AS giorni,
       COUNT(*) AS interventi,
       ROUND(SUM(a.`ore`), 2) AS ore,
       ROUND(SUM(a.`produzione_teorica`), 2) AS produzione_teorica,
       COUNT(DISTINCT a.`commessa`) AS commesse,
       ROUND(100 * SUM(a.`ore`) / NULLIF(MAX(t.`ore_tot`), 0), 1) AS quota_ore_pct
  FROM `v_cm_it_giorni_base` a
  JOIN (SELECT `operatore`, SUM(`ore`) AS ore_tot FROM `v_cm_it_giorni_base` GROUP BY `operatore`) t
    ON t.`operatore` = a.`operatore`
 GROUP BY a.`operatore`, a.`ordina`, a.`area_tecnologica`;

CREATE OR REPLACE VIEW `v_cm_it_giorni_quadro` AS
SELECT COUNT(DISTINCT `operatore`) AS operatori,
       COUNT(DISTINCT `giorno`) AS giorni_calendario,
       COUNT(DISTINCT CONCAT(`operatore`, '|', `giorno`)) AS giorni_uomo,
       COUNT(*) AS interventi,
       ROUND(SUM(`ore`), 2) AS ore,
       ROUND(SUM(CASE WHEN `valorizzata` = 0 THEN `ore` ELSE 0 END), 2) AS ore_non_valorizzate,
       ROUND(SUM(`ore`) / 8, 1) AS giornate_equiv,
       COUNT(DISTINCT `area_tecnologica`) AS aree,
       COUNT(DISTINCT `commessa`) AS commesse,
       COUNT(DISTINCT `codice_linea`) AS linee,
       ROUND(SUM(`produzione_teorica`), 2) AS produzione_teorica
  FROM `v_cm_it_giorni_base`;

-- la riconciliazione attive / chiuse non ha piu' oggetto
DROP VIEW IF EXISTS `v_cm_it_giorni_tutte`;

-- 3) impostazioni non piu' applicate (valori conservati per riferimento)
UPDATE `app_settings` SET `setting_value` = '0',
       `description` = 'Dismesso in v1.9.88: perimetro giorni = tutto l eseguito nel periodo per data del modulo'
 WHERE `setting_key` = 'it_giorni_solo_attive';
UPDATE `app_settings`
   SET `description` = 'Dismesso in v1.9.88: nessuna linea esclusa dai giorni lavorati (le ore senza tariffa sono esposte come non valorizzate)'
 WHERE `setting_key` = 'it_giorni_linee_escluse';

-- 4) copia materializzata della vista: struttura cambiata, si ricostruisce al prossimo aggiornamento
DROP TABLE IF EXISTS `snap_v_cm_it_giorni_base`;
UPDATE `pm_snapshot` SET `status` = 'da_rigenerare', `note` = 'struttura vista cambiata in v1.9.88'
 WHERE `view_name` = 'v_cm_it_giorni_base';

UPDATE `app_settings` SET `setting_value`='1.9.88'
 WHERE `setting_key` IN ('app_version','schema_version','release_label');

INSERT IGNORE INTO `pm_migration_sql` (`version`,`filename`) VALUES ('1.9.88','migration_v1_9_88.sql');
