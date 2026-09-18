# Technical Design — v1.9.40

## Sorgenti
- `v_cm_sd_moduli` — un rapportino per riga: `report_id (=cm_intervention_reports.id)`,
  `tecnico`, `giorno (=report_date)`, `commessa (=project_code)`, `codice_linea`,
  `modello`, `ore (=quantity_hours)`. Costruita con `_operator` in LEFT JOIN → popolata
  anche a tabella operatori vuota (fix della regressione a 0 righe).
- `v_rsi_report_fascia` — fascia professionale per rapportino, tre fonti in cascata con
  `fascia_origine`; espone anche `area_tecnologica (=tech_sector)`.
- `cm_projects.operational_status` per l'attività della commessa; `cm_rate_band_rates`
  (`cost_type='Cliente'`, `regime='Ordinario'`) per il listino di fascia.

## Query per persona (GROUP BY tecnico)
- **M1** `COUNT(DISTINCT CASE WHEN status IN (attivi) THEN giorno END)`.
- **M2** conteggi condizionali `SUM(fascia='C')`, `SUM(fascia='D')`, altre, N/D;
  dettaglio per (tecnico, fascia) in seconda query.
- **M3** `GROUP_CONCAT(DISTINCT COALESCE(modello, codice_linea))`.
- **M4** `SUM(CASE WHEN status IN (attivi) THEN ore*listino(fascia) END)` — join
  `v_rsi_report_fascia → cm_rate_bands → cm_rate_band_rates(Cliente,Ordinario)`.

Join fascia→listino: `rf.fascia_etichetta = cm_rate_bands.band_name = cm_rate_band_rates.band_id`.

## Note
- Filtri: periodo + `m.tecnico` + `m.contratto` (liste dai moduli). Cliente/regime rimossi
  (non esposti dalla vista).
- `RSI_STATI_ATTIVI` (default `['APERTA']`) è l'unico punto di configurazione degli stati.
- Reperibilità non distinta (tabella operatori vuota) → listino `Ordinario`.
