-- =====================================================================
-- PortalManager v1.9.34 — Master: Service Desk + Relazione di Servizio IT
-- Ricrea vista v_rsi_dettaglio_commessa (idempotente) e logga versione.
-- =====================================================================

CREATE OR REPLACE VIEW `v_rsi_dettaglio_commessa` AS
SELECT
  c.id AS contract_id, c.code AS contract_code, c.code_x_installation,
  cli.name AS customer_name, c.description AS contract_description,
  p.project_code AS pm_project_code,
  a.id AS activity_id, a.report_date, a.ticket,
  ao.id AS activity_operator_id, ao.id_operator,
  TRIM(CONCAT_WS(' ', op.second_name, op.first_name)) AS operator_name,
  map.employee_id,
  COALESCE(rbb.band_name, op.type, 'Default') AS fascia,
  CASE WHEN COALESCE(ao.during_availability,0)=1 THEN 'Reperibilità'
       WHEN COALESCE(ao.extra_hours,0)         >0 THEN 'Straordinario'
       ELSE 'Ordinario' END AS regime,
  ROUND(COALESCE(ao.hours,0),2) AS ore,
  ROUND(COALESCE(ao.cost,0),2) AS costo_contratto,
  ROUND(CASE WHEN COALESCE(ao.during_availability,0)=1
         THEN COALESCE(rb_rep.rate_hour, op.hourly_cost, 0)*COALESCE(ao.hours,0)
         ELSE COALESCE(rb_ord.rate_hour, op.hourly_cost, 0)*COALESCE(ao.hours,0)
    END, 2) AS tot_costo_tab,
  CONCAT_WS(' | ',
    NULLIF(c.code,''), NULLIF(c.code_x_installation,''),
    NULLIF(cli.name,''), NULLIF(c.description,''), '',
    NULLIF(a.ticket,''),
    NULLIF(COALESCE(rbb.band_name, op.type),''),
    CAST(ROUND(COALESCE(ao.hours,0),2) AS CHAR),
    CONCAT(FORMAT(ROUND(COALESCE(ao.cost,0),2), 2), ' €'),
    CONCAT(FORMAT(ROUND(
      CASE WHEN COALESCE(ao.during_availability,0)=1
           THEN COALESCE(rb_rep.rate_hour, op.hourly_cost, 0)*COALESCE(ao.hours,0)
           ELSE COALESCE(rb_ord.rate_hour, op.hourly_cost, 0)*COALESCE(ao.hours,0)
      END, 2), 2), ' €')
  ) AS riga_formattata
FROM dgb_forms_activity a
JOIN dgb_forms_activity_operator ao ON ao.id_activity=a.id
JOIN dgb_operator op ON op.id=ao.id_operator
LEFT JOIN dgb_operator_map map ON map.dgb_operator_id=op.id
JOIN dgb_forms_contract c ON c.id=a.id_contract
LEFT JOIN clients cli ON cli.id=c.id_customer_comp
LEFT JOIN cm_projects p ON p.dgb_contract_id=c.id
LEFT JOIN cm_rate_bands rbb ON rbb.band_name=COALESCE(op.type,'Default')
LEFT JOIN cm_rate_band_rates rb_ord ON rb_ord.band_id=rbb.id AND rb_ord.cost_type='Aziendale' AND rb_ord.regime='Ordinario'
LEFT JOIN cm_rate_band_rates rb_rep ON rb_rep.band_id=rbb.id AND rb_rep.cost_type='Aziendale' AND rb_rep.regime='Reperibilità'
WHERE COALESCE(a.deleted,0) <> 1;

CREATE TABLE IF NOT EXISTS `pm_migration_sql` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `version` VARCHAR(20) NOT NULL,
  `filename` VARCHAR(190) NOT NULL,
  `applied_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), UNIQUE KEY `uq_pm_migration_v_f` (`version`,`filename`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `pm_migration_sql` (`version`,`filename`,`applied_at`)
VALUES ('1.9.34','migration_v1_9_34.sql',NOW())
ON DUPLICATE KEY UPDATE `applied_at`=NOW();

INSERT INTO `app_settings` (`setting_key`,`setting_value`,`description`)
VALUES ('app_version','1.9.34','Master: Service Desk + Relazione di Servizio IT rigenerati (multi-select + dettaglio commessa)')
ON DUPLICATE KEY UPDATE `setting_value`='1.9.34', `description`=VALUES(`description`);
