# CHANGELOG — v1.10.03
Progetti PRJ — fase 6 di 6: Stimato vs Consuntivo, scostamenti e alert, export XLSX e DOCX, documentazione completa del modulo.
Pacchetto **cumulativo da v1.10.00**: contiene i file e le migration di v1.10.01, v1.10.02 e v1.10.03.

## Funzionalità
- **Scheda progetto → Stimato vs Consuntivo** (attiva quando il PRJ è collegato a una commessa SP):
  - **Periodo**: dall'inizio della commessa (o dalla data di collegamento) al mese corrente, entro la fine della commessa.
  - **Valori sincronizzati** della commessa (valore totale e a oggi, costo consuntivato, margine) accanto a canone e costo stimati nel periodo.
  - **Consuntivo mensile**:
    - ore dai rapporti di intervento più le voci manuali di timesheet; le attività DGB contano solo nei mesi senza rapporti, per non contare due volte le stesse ore;
    - FTE reali = ore / capacità del mese (Workload);
    - costo reale: dipendenti al costo orario dell'anno (`cm_employee_cost_year`); professionisti al costo aziendale del rapporto o della fascia (RateResolver);
    - ticket citati nei rapporti.
  - **Stimato mensile**: scenario di riferimento, FTE e costo dell'anno di contratto / 12.
  - **Scostamenti** per mese con colori di attenzione e allarme dalle soglie delle regole alert; grafico FTE stimati vs reali.
  - **Team** della commessa (`cm_team`) confrontato con le assegnazioni previste nei profili PRJ.
  - **SLA reali** del Service Desk (`cm_sd_sla`) accanto ai KPI di presa in carico e risoluzione della gara.
  - «Aggiorna consuntivi» ricalcola `cm_prj_actual` e `cm_prj_deviation`. Al primo accesso il ricalcolo è automatico.
  - **Costi reali** visibili solo con il permesso «Costi reali dipendenti (PRJ)».
- **Alert**:
  - nuove regole `prj_scost_fte` e `prj_scost_costo` (soglie 10% e 20%, create disattivate);
  - vista `v_cm_prj_alert_da_rilevare` sull'ultimo mese completo;
  - `AlertEngine::rileva()` la legge insieme alla vista principale;
  - destinatario: il commerciale della commessa.
- **Export della scheda progetto**:
  - **XLSX**: Riepilogo, Scenari, Costi per profilo, Carico ticket, Anni, Stimato vs consuntivo, Info;
  - **DOCX**: relazione di dimensionamento con indicatori, confronto scenari, composizione del costo, andamento per anno, carico per servizio, profili, stimato vs consuntivo.

## Schema
- Nuova tabella `cm_prj_deviation` (prj_id, sp_project_id, ym, metrica, stimato, consuntivo, scostamento_pct).
- Nuova vista `v_cm_prj_alert_da_rilevare`.
- Indici `idx_ir_project_date` e `idx_pact_prj`.

## QA
- `tools/verify_v1_10_03.php`: 39 OK, 0 KO; include il controllo complessivo del modulo (migration 1.9.99–1.10.03, file, documenti).
- Consuntivo verificato su commesse reali del DB di test:
  - WTS_3016: 46 mesi, 29.518 h, costo 886.945 € contro 887.075 € sincronizzati;
  - ANT_3518: 2.175,01 €, identico al sincronizzato.
- Test con login reale: Super Admin e Finance, tab, ricalcolo, export XLSX e DOCX (XML validato).
- Migration RUN1/RUN2 err=0; `php -l` su tutti i file.
