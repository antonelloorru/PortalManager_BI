# Release Checklist — v1.8.11 (Zero-omission)
## Carico risorse e sovrapposizioni

## Feature v1.8.11 — Riconciliazione DGB <-> Commesse
- [x] `sql/migration_v1_8_11.sql`: cm_projects.dgb_contract_id (ADD COLUMN/INDEX IF NOT EXISTS), popolamento da external_link (WHERE IS NULL), vista vw_dgb_commessa_rollup; 0 commenti con ';'
- [x] `app/DgbModel.php`: commessaRollup/rollupForContract/activitiesForContract/contractLabels; table() con id_contract
- [x] `project_dashboard.php`: tab DGB (rollup KPI, confronto valore/consuntivo, elenco attività, link analisi)
- [x] `manage_projects.php`: colonna DGB att/ore (rollup batch dei contratti in elenco)
- [x] `dgb_activities.php`: filtro Commessa, colonna Commessa con link a scheda, deep-link ?contract
- [x] Chiave correlazione: external_link .../contract/editV2/<id> = dgb id_contract; 1050 mappate, 786 con dati
- [x] Prestazioni: rollup via aggregati indicizzati per contratto (0,34s/5 contratti) invece della vista materializzata (lenta)
- [x] Render verificati: dashboard tab DGB (commessa 427/WTS_3016), elenco con colonna, analisi con filtro/colonna commessa (contract=77)
- [x] `db_upgrade.php` metadata '1.8.11' + UPGRADE_SQL; Version.php/VERSION=1.8.11; CHANGELOG
- [x] Idempotenza migration RUN1/RUN2 ok; consolidato naive upgrade_1_7_56_to_1_8_11.sql: 200 stmt RUN1/RUN2 ok; col+5 viste dgb; 1050 mappate


## Hotfix v1.8.10 — updater backup DB memory-safe
- [x] `app/UpdaterCore.php`: Step 2 backup DB riscritto in streaming (fwrite a blocchi da 500, query non bufferizzata, ripristino attributo, try/catch per tabella)
- [x] Causa: fetchAll dell'intera tabella + concatenazione in stringa esauriva i 512M sulle tabelle DGB grandi
- [x] Test con memory_limit=256M su DB reale: backup completo, picco ~4 MB (prima crash a 512M); file dump generato correttamente
- [x] `UpdaterCore.php` incluso nel pacchetto; `db_upgrade.php` metadata '1.8.10' + UPGRADE_SQL; Version.php/VERSION=1.8.10; CHANGELOG
- [x] Nessuna variazione di schema (migrazione = solo bump versione)
- [x] Sblocco documentato in DEPLOYMENT (copia manuale UpdaterCore.php o memory_limit)
- [x] QA tokenizer migration 1.8.10 RUN1/RUN2 ok; consolidato naive upgrade_1_7_56_to_1_8_10.sql: 195 stmt RUN1/RUN2 ok


## Feature v1.8.9 — DGB orario/carico e distribuzione temporale
- [x] `app/DgbModel.php`: whereDetail, hoursBreakdown (ordinario/straordinario/trasferta/carico/capacita/saturazione), temporalDistribution (day/month + baseline), workingDaysBetween, normFilters estesa (report_type/mode/stdh)
- [x] `dgb_api.php`: azioni hours/distribution, parametri gran/month; charts con breakdown+distribuzione
- [x] `dgb_activities.php`: sezione Orario & Carico (6 KPI), grafico distribuzione SVG (barre ordinario+straordinario impilate + baseline), toggle Mesi/Giorni + month picker, nuovi filtri, export distsvg/distxlsx/distcsv
- [x] Export verificati: SVG mese (12 barre + baseline + legenda), SVG giorno 2025-10 (31 barre, 8 weekend, baseline), CSV con riga TOTALE e delta vs baseline
- [x] Modello 2025: ordinarie 116.134h, straordinario 2.059h (1,8%), trasferta 3.562,5h, carico 118.193h, capacita 223.416h, saturazione 52,9%; filtri R_ANTEA/remoto funzionanti
- [x] API distribution JSON ok (12 bucket, saturazione 52,9%); hours (giu/STD) ord 8.958,5
- [x] `db_upgrade.php` metadata '1.8.9' + UPGRADE_SQL; Version.php/VERSION=1.8.9; CHANGELOG
- [x] Nessuna variazione di schema (migrazione = solo bump versione)
- [x] QA tokenizer migration 1.8.9 RUN1/RUN2 ok; consolidato naive upgrade_1_7_56_to_1_8_9.sql: 194 stmt RUN1/RUN2 ok; 4 viste dgb


## Feature v1.8.8 — Attività & Rendicontazione DGB
- [x] `sql/migration_v1_8_8.sql`: 5 tabelle dgb_* + dgb_import_log + 4 viste (gerarchia, SLA, consuntivo, carico) + seed permessi + bump; 0 commenti con ';'
- [x] `app/DgbImporter.php`: parser fgetcsv (pipe, multi-riga), casting Datetime/NULL, upsert PK, diff via sig (Task 1 + Task 5)
- [x] `app/DgbModel.php`: filtri (range/incaricato/stato), tabella, KPI, distribuzione carico+baseline, anomalie, chartsJson
- [x] `dgb_api.php`: endpoint JSON filters|table|kpi|charts|anomalies (Task 4)
- [x] `dgb_activities.php`: schede Analisi&KPI (filtri, 6 KPI, gauge SVG, distribuzione con baseline, data quality, tabella, export XLSX/CSV) e Import&Diff (upload multi-file, report differenziale, storico)
- [x] `app/MenuManager.php` voce sotto Gestione Commesse; `manage_permissions.php` page_map (dgb_activities + dgb_api ↳)
- [x] `db_upgrade.php` metadata '1.8.8' + UPGRADE_SQL; Version.php/VERSION=1.8.8; CHANGELOG
- [x] Import reale testato: operator 256, allocation 99.729, planning 441, activity 80.785, operator_detail 69.853 (dedup PK; 24s)
- [x] Diff: re-import identico → tutto invariato; modifica 1 riga → 1 updated
- [x] KPI 2025: 23.640 attività, achievement 94,6%, baseline carico, orfani 19.146, piani vuoti 5; API 5 azioni JSON valide
- [x] QA tokenizer migration RUN1/RUN2 ok; consolidato naive upgrade_1_7_56_to_1_8_8.sql: 193 stmt RUN1/RUN2 ok=193 err=0; 6 tabelle + 4 viste + 10 permessi


## Hotfix v1.8.7 — warning migrazioni assenti
- [x] `db_upgrade.php`: helper `pm_migration_sql()` (legge solo se il file esiste); 82 letture migrazioni convertite
- [x] Nessun warning `file_get_contents ... Failed to open stream` in testata; file assenti → stringa vuota
- [x] `db_upgrade.php`: metadata `'1.8.7'` + `$UPGRADE_SQL['1.8.7']`; `Version.php`/`VERSION`=1.8.7; `CHANGELOG` voce 1.8.7
- [x] Nessuna variazione di schema (migrazione = solo bump versione)
- [x] Test helper: file mancante len=0 senza warning, file presente letto; php -l OK
- [x] QA tokenizer migration 1.8.7 RUN1/RUN2 ok; consolidato naive `upgrade_1_7_56_to_1_8_7.sql`: 180 stmt RUN1/RUN2 ok


## Feature v1.8.6 — filtri estesi Carico & Sovrapposizioni
- [x] `app/Workload.php`: `addCommonFilters()`/`needsProjectJoin()`; applicati a matrix/dailyMatrix/projectOverlaps (JOIN cm_projects condizionale)
- [x] `workload_overview.php`: filtri Società, Cliente, Stato operativo, Tipologia (+ commessa/linea servizio gia' presenti); preservati in form risorse/mese/export
- [x] Filtri applicati a heatmap, grafico mensile e giornaliero, conflitti, sovrapposizioni, export XLSX
- [x] `db_upgrade.php`: metadata `'1.8.6'` + `$UPGRADE_SQL['1.8.6']`; `Version.php`/`VERSION`=1.8.6; `CHANGELOG` voce 1.8.6
- [x] Nessuna variazione di schema (migrazione = solo bump versione)
- [x] QA su DB reale: base 135 risorse; societa 121; cliente 53; stato APERTA 129; projectOverlaps+cliente 50; dailyMatrix+tipologia 68; combinati 115
- [x] Render pagina: 4 nuovi select presenti e preservati (3x nei form), selezione mantenuta
- [x] QA tokenizer migration 1.8.6 RUN1/RUN2 ok; consolidato naive `upgrade_1_7_56_to_1_8_6.sql`: 179 stmt RUN1/RUN2 ok


## Feature v1.8.5 — Report & Avanzamento + Workflow
- [x] Migrazione `sql/migration_v1_8_5.sql`: `cm_project_updates`, `cm_project_update_files`, `cm_workflow_steps` (idempotente, FK CASCADE)
- [x] `app/ProjectWorkflow.php` (nuovo): updates/steps/summary/recomputePhaseProgress/stepsForGantt/file
- [x] `project_dashboard.php`: tab "Report & Avanzamento", POST handler (add/update/del update; del allegato; add/update/del/set-status step) con PRG+CSRF, soft-delete
- [x] Note datate con tipo, valutazione 1-5, avanzamento, link fase/step; allegati multipli (UploadGuard) con download applicativo e rimozione
- [x] Workflow: step con fase, stato, scadenza, responsabile, gate, avanzamento; cambio stato rapido; riepilogo
- [x] Aggancio Gantt: recompute progress fase dagli step; marcatori a rombo sulle scadenze nel tab Gantt
- [x] `db_upgrade.php`: metadata `'1.8.5'` + `$UPGRADE_SQL['1.8.5']`; `Version.php`/`VERSION`=1.8.5; `CHANGELOG` voce 1.8.5
- [x] QA su DB reale: recompute fase 100+40→70%, summary, note con rating/fase, stepsForGantt
- [x] Render pagina: tab report, card workflow+note, step e nota visibili, 2 marcatori workflow sul Gantt, input allegati, select stato
- [x] QA tokenizer migration 1.8.5 RUN1/RUN2 ok (4 stmt); consolidato naive `upgrade_1_7_56_to_1_8_5.sql`: 178 stmt RUN1/RUN2 ok


## Feature v1.8.4 — Commesse/Progetti: filtri ed export
- [x] `app/ProjectModel.php`: `listAll()` con filtri (status, commercial, type, service_line, company, client, value min/max, date, sort) e colonne extra; retrocompatibile
- [x] `manage_projects.php`: pannello filtri, export XLSX (XlsxWriter) e CSV (; + UTF-8 BOM) che rispetta i filtri, tabella arricchita, contatore
- [x] Export gate: consentito a chi puo' visualizzare la pagina (stessi dati gia' a video)
- [x] `db_upgrade.php`: metadata `'1.8.4'` + `$UPGRADE_SQL['1.8.4']`; `Version.php`/`VERSION`=1.8.4; `CHANGELOG` voce 1.8.4
- [x] Nessuna variazione di schema (migrazione = solo bump versione)
- [x] QA su DB reale: listAll filtri coerenti (APERTA 546, azienda 930, valore 10k-50k 235, sort valore desc)
- [x] Render pagina: pannello filtri completo, 246 righe; export CSV filtrato 13 colonne corretto
- [x] QA tokenizer migration 1.8.4 RUN1/RUN2 ok; consolidato naive `upgrade_1_7_56_to_1_8_4.sql`: 174 stmt RUN1/RUN2 ok


## Feature v1.8.3 — Gantt ridisegnato
- [x] `app/Gantt.php`: helper `timelineMinWidth/tickBudget/monthGridlines(step)/css`; metodi dati invariati
- [x] `project_gantt.php`: layout `.pm-gantt` con timeline min-width scrollabile, barre plan/act separate, gridlayer unico, tacche adattive, colonna sticky
- [x] `project_dashboard.php` (tab Gantt): stesso layout per commessa/fasi/risorse + istogramma `.pm-hist` rivisto
- [x] Ottimizzazione griglia: da migliaia di nodi per-riga a un unico livello allineato (~50 linee)
- [x] `db_upgrade.php`: metadata `'1.8.3'` + `$UPGRADE_SQL['1.8.3']`; `Version.php`/`VERSION`=1.8.3; `CHANGELOG` voce 1.8.3
- [x] Nessuna variazione di schema (migrazione = solo bump versione)
- [x] QA tokenizer migration 1.8.3 RUN1/RUN2 ok; consolidato naive `upgrade_1_7_56_to_1_8_3.sql`: 173 stmt RUN1/RUN2 ok
- [x] Render reale: portfolio 200 righe (min-width 2600, 28 tacche, 56 linee griglia); scheda commessa #427 (78 risorse) render leggibile


## Feature v1.8.2 — andamento giornaliero per mese
- [x] `app/Workload.php`: metodi giornalieri `daysOfMonth/isWorkingDay/dailyCapacity/dailyMatrix/dailyChartSeries` (viste mensili invariate)
- [x] `workload_overview.php`: card grafico giornaliero SVG server-side (bande weekend + capacita' h/giorno), selettore mese di dettaglio, filtri rispettati, top-12
- [x] Export XLSX: foglio 'Giornaliero YYYY-MM' (risorsa x giorno)
- [x] `db_upgrade.php`: metadata `'1.8.2'` + `$UPGRADE_SQL['1.8.2']`; `Version.php`/`VERSION`=1.8.2; `CHANGELOG` voce 1.8.2
- [x] Nessuna variazione di schema (migrazione = solo bump versione)
- [x] QA tokenizer migration 1.8.2: RUN1/RUN2 ok; consolidato naive `upgrade_1_7_56_to_1_8_2.sql`: 172 stmt RUN1/RUN2 ok
- [x] Render pagina completo: grafico giornaliero, selettore dm, 12 polilinee giornaliere, capacita' e weekend presenti
- [x] Test dati reali: mese 2026-07, 31 giorni (23 feriali), 68 risorse, picco 16h/giorno


## Fix v1.8.1 — permessi ruolo Finance
- [x] Difetto 1.8.0 individuato: pagine economiche assegnate per id fisso 10 (Coordinatore Tecnico) anziche' al ruolo Finance (id 11)
- [x] `sql/migration_v1_8_1.sql`: assegnazione per NOME ('Finance'/'Responsabile Finanziario') + rimozione errata da id 10 se non finanziario
- [x] Metadata `db_upgrade.php` allineato all'id reale (11) per step 1.7.90, 1.7.97, 1.8.0 → banner disallineamento risolto
- [x] `db_upgrade.php`: metadata `'1.8.1'` + `$UPGRADE_SQL['1.8.1']` registrati
- [x] `VERSION`=1.8.1, `app/Version.php` PM_VERSION=1.8.1, `CHANGELOG.md` voce 1.8.1
- [x] QA tokenizer: 5 stmt RUN1/RUN2 ok; su DB reale role 10→rimosso, role 11→aggiunto
- [x] QA consolidato naive `upgrade_1_7_56_to_1_8_1.sql`: 171 stmt RUN1/RUN2 ok; fresh install ruoli 1,2,11; residuo id10=0
- [x] Drift-check simulato: 0 elementi mancanti su 1.7.90/1.7.97/1.8.0/1.8.1


## Versionamento coeso
- [x] `VERSION` = 1.8.0
- [x] `app/Version.php` PM_VERSION = 1.8.0 (autoBump app/schema/release_label invariato)
- [x] `db_upgrade.php`: metadata `'1.8.0'` + `$UPGRADE_SQL['1.8.0']` registrati (righe 1169 e 2701)
- [x] `CHANGELOG.md`: voce 1.8.0 in testa
- [x] Migrazione porta `app_version`/`schema_version`/`release_label` a 1.8.0

## Integrità componenti (file completi già patchati)
- [x] Modificati: `app/CostModel.php`, `app/MenuManager.php`, `app/Version.php`,
      `employee_compensation.php`, `finance_overview.php`, `manage_permissions.php`, `db_upgrade.php`
- [x] Nuove pagine: `hr_economic_years.php`, `finance_compare.php`, `import_economics_xlsx.php`
- [x] SQL: `sql/migration_v1_8_0.sql` (migrazione) + `upgrade_1_7_56_to_1_8_0.sql` (consolidato root)
- [x] `php -l` superato su tutti i file PHP modificati/nuovi
- [x] Nessun file esistente rimosso; colonne economiche di `employees` mantenute come mirror

## Schema DB (idempotente)
- [x] `hr_economic_years` (PK year; seed 2025 is_current=1)
- [x] `hr_employee_economics` (UNIQUE employee_id+year; 16 colonne input; precisioni allineate a employees)
- [x] `hr_reference_values` + colonna `year`; UNIQUE spostata da `uq_hr_ref_key` a `uq_hr_ref_key_year`
- [x] `hr_reference_history` + colonna `year`
- [x] Backfill esercizio 2025 dai dati esistenti (solo dipendenti con almeno un campo economico)
- [x] Permessi nuove pagine per ruoli 1 (Super Admin), 2 (HR Director), 10 (Resp. Finanziario)
- [x] Pattern idempotenti: `ADD COLUMN IF NOT EXISTS`, `DROP INDEX IF EXISTS`, `ADD UNIQUE KEY IF NOT EXISTS`, `INSERT IGNORE`, UPSERT
- [x] Nessun `;` all'interno di righe di commento SQL (compat splitter naive)

## QA SQL — eseguito con l'executor reale (regola v1.7.66)
- [x] `migration_v1_8_0.sql` via tokenizer `sql_split_statements()` (SqlConsole): 16 statement,
      RUN1 ok=16 err=0, RUN2 ok=16 err=0 (idempotente) su copia del DB reale
- [x] `migration_v1_8_0.sql` via client MariaDB: RUN1/RUN2 identici; 200 righe econ 2025;
      UNIQUE swappato correttamente
- [x] `upgrade_1_7_56_to_1_8_0.sql` (consolidato root) via splitter naive `explode(';')`
      di system_update: RUN1 ok=166 err=0, RUN2 ok=166 err=0 (idempotente); app_version=1.8.0
- [x] DB reale: 288 dipendenti, 200 con dati economici migrati a 2025

## Logica applicativa — verificata su DB reale
- [x] `CostModel`: `currentYear()`=2025, `years()`, `resolveYear(2099|0)`→2025 (fallback)
- [x] `CostModel::compute`: parità tra riga `employees` e riga `hr_employee_economics` 2025
      (es. emp#2 RAL 60922,82 → TotaleFTE+CA 119949,46, FullCost 86839,39)
- [x] `finance_overview` fin_rows: JOIN anno-scoped su 200 righe; calcoli coerenti
- [x] Clonazione esercizio (hr_economic_years): 200 input + 5 riferimenti clonati; nessun duplicato
- [x] Import: template XLSX round-trip (header letti); UPSERT con decimali a virgola;
      match per Codice e per Codice fiscale; idempotenza
- [x] Confronto annualità: delta assoluto e % calcolati correttamente sui record modificati

## Pattern architetturali rispettati
- [x] PRG: tutti i POST handler prima di `header.php`, con `Csrf::verify()`
- [x] Permessi via `can()`; sezioni economiche gated dal permesso riservato HR
- [x] Anti-leak: nessun campo sensibile aggiuntivo esposto oltre a quanto già gated
- [x] Audit: `write_log` su salvataggi, import, gestione annualità
- [x] Parser file nativi: `XlsxReader`/`XlsxWriter` (no dipendenze esterne); `UploadGuard` sugli upload
- [x] Retrocompatibilità: mirror su `employees` per l'anno corrente; fallback riferimenti anno precedente

## Documentazione (obbligatoria)
- [x] `docs/TECHNICAL_DESIGN_v1.8.0.md` (schema ER, viste, logiche di calcolo, sicurezza)
- [x] `docs/MANUALE_ADMIN_v1.8.0.md`
- [x] `docs/MANUALE_UTENTE_v1.8.0.md`
- [x] `docs/DEPLOYMENT_v1.8.0.md`
- [x] `RELEASE_CHECKLIST_v1.8.0.md` (questo file)

## Packaging
- [x] ZIP versionato con file interi patchati + nuovi file + SQL + consolidato + docs + checklist
- [x] Percorsi con separatore `/` (app/, sql/, docs/)
