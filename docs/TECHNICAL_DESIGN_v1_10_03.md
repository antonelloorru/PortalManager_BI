# TECHNICAL DESIGN — Modulo Progetti PRJ (consolidato v1.10.03)
Documenti di dettaglio:
- `TECHNICAL_DESIGN_v1_9_99.md`: ER, decisioni, permessi;
- `TECHNICAL_DESIGN_v1_10_00.md`: motore di calcolo;
- `TECHNICAL_DESIGN_v1_10_01.md`: interfaccia;
- `TECHNICAL_DESIGN_v1_10_02.md`: KPI, punteggio, storico.

## 1. Scopo dei moduli
| Componente | Scopo |
|---|---|
| `cm_prj*` (39 tabelle) | Progetti PRJ (gare/iniziative), distinti dalle commesse SP; dati di gara versionati |
| `app/PrjCalc.php` | Motore puro: carico ticket, costo per profilo, strutturali, overhead, totali scenario, per anno, conguaglio, penali, punteggio |
| `app/PrjRepo.php` | Lettura as-of, input del motore, calc run immutabili, confronto, scrittura versionata, creazione e clonazione, codice PRJ |
| `app/PrjLink.php` | Collegamento PRJ ↔ commessa SP, storico, suggerimenti, hook post-sincronizzazione |
| `app/PrjUi.php` | Griglie versionate in whitelist, formattazione |
| `app/PrjActuals.php` | Consuntivo mensile, confronto con lo stimato, scostamenti, team, SLA |
| `app/PrjExport.php` | Export XLSX e DOCX della scheda |
| `app/prj_list.php`, `prj_dashboard.php`, `prj_parameters.php`, `prj_history.php`, `api_prj.php` | Interfaccia |

## 2. Relazioni tra le viste
```
Gestione Commesse
 ├─ Commesse / Progetti ── Commesse SP (manage_projects.php) ── colonna/filtro Progetti PRJ ──► Scheda commessa · tab Progetti PRJ
 │                       └ Progetti PRJ (?view=prj → app/prj_list.php) ──► Scheda progetto PRJ (prj_dashboard.php)
 │                                                                            ├ Anagrafica · Collegamento ◄──► cm_projects (lettura)
 │                                                                            ├ Gara · Servizi · Asset & Volumi · Profili · Costi
 │                                                                            ├ Scenari ──(Calcola e salva)──► cm_prj_calc_run/result
 │                                                                            ├ KPI & Penali · Punteggio
 │                                                                            ├ Stimato vs Consuntivo ◄── rapporti, timesheet, DGB, costi HR
 │                                                                            └ Storico ◄── calc run, EntityChangeLog, link history
 ├─ Parametri dimensionamento (prj_parameters.php) ── parametri globali versionati (prj_key = 0)
 └─ Scenari & confronti progetti (prj_history.php) ── calc run di tutti i PRJ, confronto, export
Sincronizzazione gestionale (CommesseSync) ──► PrjLink::afterSync (orfani, collegamento da commercial_ref)
AlertEngine::rileva ──► v_cm_alert_da_rilevare ∪ v_cm_prj_alert_da_rilevare (cm_prj_deviation)
```

## 3. Schema ER (sintesi)
- `cm_prj` 1—N tabelle figlie (`prj_id`, ON DELETE CASCADE).
- `cm_prj.sp_project_id` → `cm_projects` (ON DELETE SET NULL).
- `cm_prj_calc_run` → `cm_prj` (RESTRICT) 1—N `cm_prj_calc_result`.
- `cm_prj_actual`, `cm_prj_deviation` per (prj, mese).
- `cm_prj_link_history` per prj.
- Esterne in lettura: clients, companies, technologies, certifications, employees, cm_professionals, users, cm_team, cm_intervention_reports, cm_timesheet_entries, dgb_forms_activity(_operator), cm_employee_cost_year, cm_rate_band*, cm_sd_sla, hr_reference_values.

Dettaglio colonne: `TECHNICAL_DESIGN_v1_9_99.md` §2 e migration.

## 4. Logiche di calcolo
- **Dimensionamento**: formule §5 (TD v1.10.00). Risultati ASPI riprodotti entro ±1 k€ (verify v1.10.00).
- **Consuntivo** (PrjActuals):

  | Grandezza | Regola |
  |---|---|
  | Periodo | mesi da `start_date` della commessa (o `sp_linked_at`) a oggi, entro `end_date` |
  | ore | Σ quantity_hours dei rapporti + Σ hours del timesheet (Ordinario, Reperibilità, Trasferta); ore DGB solo nei mesi senza rapporti |
  | fte | ore / (giorni lavorativi del mese × ore/giorno), da `Workload::monthlyCapacity` |
  | costo | dipendente: ore × costo_ora dell'anno (ultimo anno ≤ mese); altrimenti company_cost_calc, poi company_cost_import, poi RateResolver sulla fascia; timesheet: ore × costo_ora; DGB: costo dell'attività |
  | stimato | anni[anno].fte e anni[anno].costo / 12 dello scenario di riferimento (fuori dagli anni di contratto: totali annui) |
  | scostamento % | (consuntivo − stimato) / stimato × 100, registrato per i mesi con ore |

- **Alert**: ultimo mese completo (`ym` < mese corrente). |scostamento| ≥ threshold_warn → attenzione, ≥ threshold_alarm → allarme. La firma contiene regola|PRJ|mese|fascia, quindi un evento per mese e fascia.

## 5. Sicurezza
- CSRF e PRG su ogni POST, prepared statement (emulazione disattivata), whitelist di tabelle e campi.
- Appartenenza delle righe al progetto verificata lato server; API con controllo del permesso per azione.
- `cm_projects` in sola lettura; costi reali solo con permesso dedicato, sia a video sia negli export.
- Calc run immutabili; cancellazioni via RecycleBin; audit con write_log ed EntityChangeLog.
