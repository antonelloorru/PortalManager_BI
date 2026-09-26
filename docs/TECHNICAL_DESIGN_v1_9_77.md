# Technical Design — v1.9.77

## Modulo
Relazione di Servizio IT (`it_service.php`, `app/ItServiceModel.php`, `app/it_service_print.php`).

## Filtro `contratti`
- Chiave GET: `contratti[]` (o CSV `contratti=`); normalizzata in `normFilters()` da
  `normContratti()`.
- Valori: `project_code` (cm_projects) | `dgb:<id_contract>`.
- `ctrParts($f)` → `[codici, id DGB]`.
- `ctrCond($colCommessa)` — per le viste dei rapportini:
  `col IN (codici) OR col IN (SELECT project_code FROM cm_projects WHERE dgb_contract_id IN (id))`.
- `ctrCondDgb($colContract)` — per le sezioni DGB:
  `col IN (SELECT dgb_contract_id FROM cm_projects WHERE project_code IN (codici)) OR col IN (id)`.
- Opzioni: `valoriContratti()` (commesse della vista + contratti DGB non collegati).
- Descrizione: `descrizioneFiltri($f, $etichette)`.

## Relazioni (ER)
```
cm_intervention_reports.project_code ─┐
                                      ├─ cm_projects.project_code (UNIQUE)
v_cm_it_servizio.commessa ────────────┤
v_cm_sd_costi_valorizzati.commessa ───┤
v_cm_it_giorni_base.commessa ─────────┘
cm_projects.dgb_contract_id ── dgb_forms_activity.id_contract ── dgb_forms_contract.id
```

## Copertura
Tutti i metodi di lettura del modello ricevono `$f`: `totali`, `aggrega`, `perDimensione`,
`andamento`, `andamentoGiornaliero`, `statoKm`, `costiRiepilogo`, `costiQuadro`,
`giorniOperatore`, `giorniArea`, `giorniQuadro`, `giorniRiconcilia`, `riepilogoContratto`,
`dettaglioCommessa`, `dettaglioCommessaSintesi`. Escluso `distanzeMancanti` (globale).
