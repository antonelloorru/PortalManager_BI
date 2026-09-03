# Release Checklist — v1.7.92 (Zero-omission)

## Versionamento coeso
- [x] `VERSION` = 1.7.59
- [x] `app/Version.php` PM_VERSION = 1.7.59 (autoBump app/schema/release_label)
- [x] `db_upgrade.php`: metadata `'1.7.59'` + `$UPGRADE_SQL['1.7.59']` registrati
- [x] `CHANGELOG.md`: voce 1.7.59

## Integrità componenti (file completi già patchati)
- [x] Nuove classi: `app/XlsxReader.php`, `app/ProjectModel.php`, `app/RateResolver.php`, `app/PrefixResolver.php`
- [x] Nuove pagine: `manage_projects.php`, `project_dashboard.php`, `manage_rate_bands.php`, `import_commesse.php`, `import_intervention_reports.php`
- [x] AJAX: `ajax_locations.php`, `ajax_client_locations.php`
- [x] Patch: `app/MenuManager.php` (sezione Commesse indipendente, icona `fa-file-invoice-dollar`), `manage_permissions.php`, `db_upgrade.php`
- [x] `manage_permissions.php`: gruppo **Gestione Commesse** di primo livello (5 voci), non annidato in Anagrafica & HR
- [x] `manage_permissions.php`: sezioni comprimibili su entrambe le tabelle (ruoli + override utente), contatori, Espandi/Comprimi tutte, stato persistito
- [x] Cumulativi 1.7.58: `manage_departments.php`, `manage_employees.php`, `employee_profile.php`, `app/MenuManager.php`, `app/Version.php`
- [x] `php -l` superato su tutti i 18 file PHP

## Cestino / ripristino record (1.7.76)
- [x] cm_deleted_records idempotente + permesso Super Admin
- [x] softDelete archivia JSON + metadati e poi elimina (solo tabelle whitelist)
- [x] restore re-inserisce con PK originale; conflitto PK bloccato e segnalato
- [x] purge / purgeOlderThan; listItems filtrabile con autore
- [x] Delete team/fasi/timesheet reindirizzati al cestino
- [x] Ciclo completo verificato su DB reale; render pagina senza warning

## Controllo Email aziendale mancante (1.7.92)
- [x] Tab email_az: elenco con personale valorizzata e aziendale vuota (NULL o '')
- [x] Selezione per riga + seleziona tutti; applicazione solo ai selezionati
- [x] UPDATE vincolata (non sovrascrive email aziendali esistenti); log dell'operazione
- [x] Testato: 2 candidati, 1 applicato, gli altri invariati; render pulito

## Email aziendale nell'estrazione (1.7.91)
- [x] Colonna "Email aziendale" da business_email (fallback work_email/email)
- [x] Presente in anteprima, XLSX e CSV; ordine colonne aggiornato
- [x] Testato con valori reali di esempio

## Estrazione anagrafica dipendenti (1.7.90)
- [x] export_employees.php: XLSX (XlsxWriter) + CSV (';' UTF-8 BOM), colonne richieste complete
- [x] Lookup azienda/sede/tipo rapporto/dipartimento; qualification con fallback job_title
- [x] Filtri azienda e stato; anteprima; log dell'estrazione; nessun dato retributivo
- [x] Nuovo ruolo Responsabile Finanziario (idempotente) + permessi ruoli 1/2/10
- [x] Menu e mappa permessi aggiornati; render senza warning

## Fasce per commessa (1.7.89)
- [x] cm_alias_band.project_id + UNIQUE (raw_band, project_id), sostituzione indice idempotente
- [x] cm_project_band_rates (commessa / professionista) + UI in dashboard commessa
- [x] cm_reapply: priorità alias commessa → nome → alias globale; ricalcolo con override
- [x] RateResolver::rateFor/calcCostsFor con priorità commessa+prof → commessa → generale
- [x] AliasStore::all() per fasce limitato agli alias globali
- [x] Testato: E→E1/E2 per commessa; tariffe 550/600/950; render pulito

## Mappatura tecnici su Professionisti (1.7.88)
- [x] cm_alias_technician.professional_id (idempotente) + risoluzione in cm_reapply
- [x] Dropdown "Mappa a" con dipendenti + professionisti (chiave P<id>, badge esterno)
- [x] save_map e import_map accettano P<id>; export riferimenti con colonna tipo
- [x] cm_suggest tollerante all'ordine dei token (nomi invertiti)
- [x] Testato: 5/5 esterno, 3/3 collegato, 0 residui; render pulito

## Professionista esterno nel rapporto e team (1.7.87)
- [x] cm_intervention_reports.technician_professional_id + reapply auto-associa esterni
- [x] cm_team: professional_id + member_type; employee_id nullable; UNIQUE per commessa
- [x] syncTeamFromReports include esterni; team() risolve nome/costo da employees o professionals
- [x] Vista team: colonna Tipo (badge Dipendente/Esterno); colspan aggiornati
- [x] Migration idempotente; testato: 7 rapporti associati, team esterno 56h €/h 45

## Hotfix ricerca Professionisti (1.7.86)
- [x] Colonne filtri qualificate con alias p. (ricerca/stato/azienda/attivi/tipo)
- [x] Query di conteggio con alias coerente
- [x] Testato: ricerca + tutti i filtri senza eccezioni; render pulito

## Verifica anagrafiche in riconciliazione (1.7.85)
- [x] cm_tech_verify: match esatto vs Dipendenti + Professionisti (nome/username/sigla/invertiti)
- [x] Classi: dipendente / professionista_unito / professionista_esterno / nessuno (priorità dipendente)
- [x] Pannello con conteggi, dettaglio, Allinea ora, avviso esterni + link
- [x] Testato: classificazione e render corretti, nessun warning

## Merge: criterio "Stesso nome (simile)" (1.7.84)
- [x] Metodo similar_name (prima parola cognome+nome; nomi completi differenti)
- [x] Cattura secondo nome / cognome composto mancante; esclude doppioni identici
- [x] Tab UI aggiunto; render senza warning
- [x] Testato: casi target raggruppati, falso-positivo escluso

## Riconciliazione via Professionisti + promozione (1.7.83)
- [x] cm_reapply: seed alias da professionisti collegati (INSERT IGNORE su UNIQUE)
- [x] cm_reapply: risoluzione tecnici via JOIN cm_professionals -> employee_id
- [x] promoteToEmployee: crea employee (colonne rilevate) + end_date se non attivo + link
- [x] Pulsante In Dipendenti con data cessazione (prefill se inattivo)
- [x] Testato su dati reali: 25/25 rapporti risolti; promozione attivo/cessato OK

## Professionisti: Esterni vs Dipendenti (1.7.82)
- [x] Colonne employee_match/matched_employee_id/match_type (idempotenti)
- [x] Rilevamento per email/nome all'import + pulsante Rileva dipendenti
- [x] Badge Dipendente/Esterno, filtro Tipo, contatori Esterni/Dipendenti
- [x] Testato su file reale: 8 dipendenti / 229 esterni, badge e filtro coerenti

## Anagrafica Professionisti (1.7.81)
- [x] cm_professionals + ProfessionalStore; import CSV separatore | con UPSERT su ID operatore
- [x] Credenziali (password/temp_password/password_history/rfid) escluse a monte
- [x] Scheda gestione: ricerca, filtri, contatori, stato
- [x] Merge verso dipendenti (suggerimento email/nome; alias tecnico creati)
- [x] Menu + permessi; render senza warning
- [x] Testato su file reale: 237 importati, idempotenza e merge verificati

## Import Commesse DB (1.7.80)
- [x] Parser CSV nativo separatore | / quoting " / CRLF, in streaming
- [x] Mappatura export gestionale → cm_projects (code, valori, date, stato, anomalie)
- [x] UPSERT su project_code idempotente; cliente upsert; azienda da prefisso
- [x] Skip deleted=1 con opt-in; batch cm_import_batches
- [x] Menu + permesso Super Admin; render senza warning
- [x] Testato su file reale riservato: 1054 importate, idempotenza confermata

## Filtro linea di servizio (1.7.79)
- [x] Filtro service_line su matrix, personOverlaps, projectOverlaps + export
- [x] Dropdown popolato dalle linee presenti; selezione mantenuta
- [x] Testato su dati reali: ripartizione ore/conflitti/coppie per linea coerente

## Cestino nella sezione Sistema (1.7.78)
- [x] Voce menu Cestino spostata da Gestione Commesse a Sistema (MenuManager)
- [x] Mappa permessi aggiornata (categoria Sistema)
- [x] Verificato: recycle_bin presente solo in Sistema

## Cestino esteso a tutto il portale (1.7.77)
- [x] Denylist di sistema al posto della whitelist; PK auto-rilevata; helper capture()
- [x] Aggancio delete reali (employee_profile, certificazioni, reparti); sync/config restano diretti
- [x] Testato: restore departments/PK alternativa OK; denylist app_logs senza archivio

## Hotfix tab Log console (1.7.75)
- [x] Nome utente da employees via users.employee_id (users.first_name/last_name non esistono)
- [x] Fallback email → id; messaggio diagnostico su errore query
- [x] Verificato su schema reale: righe log visualizzate correttamente

## Sovrapposizioni: filtri e fascia temporale (1.7.74)
- [x] projectOverlaps rispetta periodo + commessa + risorse
- [x] Fascia temporale (primo→ultimo mese) ricalcolata sul periodo
- [x] Ore per commessa (A/B) + totale in contesa
- [x] Export XLSX con foglio sovrapposizioni
- [x] Testato su dati reali: fascia/ore si restringono col periodo; filtri commessa/risorse verificati

## Carico: grafico e filtri avanzati (1.7.73)
- [x] Legenda descrittiva delle fasce di saturazione e del marcatore ⚠
- [x] Ordinamento risorse: ore desc/asc, nome
- [x] Filtro multi-risorsa (select multipla) + azzera selezione
- [x] Grafico SVG multi-linea con linea di capacità, senza dipendenze JS
- [x] Fallback prime 12 risorse quando la selezione è ampia
- [x] Render senza warning; testato su dati reali

## Hotfix anteprima console (1.7.72)
- [x] Contatori Nuovi/Modificati con count() (erano array)
- [x] Etichetta Versione con fallback a versione installata
- [x] Render anteprima verificato con ZIP reale: nessun warning

## Carico & Sovrapposizioni (1.7.71)
- [x] Heatmap risorsa × mese con saturazione e drill per commessa
- [x] Conflitti per risorsa (multi-commessa e/o sovraccarico), ordinati per gravità
- [x] Sovrapposizioni tra commesse (risorse condivise negli stessi mesi)
- [x] Filtri periodo/commessa/risorsa/solo-sovraccarichi + export XLSX
- [x] Nessuna nuova tabella: deriva dai rapporti; solo permesso di pagina
- [x] Testato su dati reali: 38 sovraccarichi, 3 coppie di commesse in contesa

## Console di sistema (1.7.70)
- [x] 4 schede (Aggiornamento ZIP, Migrazioni DB, SQL Runner, Log) in un'unica pagina
- [x] Logica non duplicata: UpdaterCore.php + SqlConsole.php condivisi
- [x] sql_runner.php de-duplicato (usa SqlConsole.php)
- [x] system_update.php e sql_runner.php → redirect alla console
- [x] Menu e permessi aggiornati; accesso Super Admin
- [x] Log app_logs filtrabili e paginati
- [x] Render delle 4 schede + preview/exec SQL verificati

## Timesheet & Gantt (1.7.69)
- [x] `cm_timesheet_entries` e `cm_project_phases` idempotenti + setting ts_daily_hours + permessi Super Admin
- [x] Timesheet: ore da rapporti (read-only) + voci manuali, saturazione, filtri, dettaglio giorno, export XLSX
- [x] Voce manuale: add/somma/dettaglio/delete verificati
- [x] Gantt portfolio: pianificato vs effettivo, barra rossa su sforamento, filtri
- [x] Tab Gantt scheda commessa: fasi con avanzamento, barre per risorsa, carico mensile, CRUD fasi
- [x] Matematica barre (scale/bar/ticks) verificata su commessa reale da 6.737 rapporti
- [x] Menu e permessi nella sezione Gestione Commesse

## Consuntivo & Team (1.7.68)
- [x] Dettaglio completo espandibile per rapporto (43 campi, importato vs calcolato)
- [x] Modifica rapporto con ricalcolo `*_calc`, riassegnazione commessa, CSRF+PRG+audit
- [x] Elenco paginato (50/pag) e filtri testo/approvato/reperibilità
- [x] `cm_team.source` idempotente, righe manuali preservate dal sync
- [x] Team con ore/costo effettivi dai rapporti + avviso tecnici mancanti
- [x] Testato su commessa reale da 6.737 rapporti

## Controllo & Riconciliazione (1.7.67)
- [x] Tabelle alias (project/technician/band) idempotenti + permesso Super Admin
- [x] `AliasStore` consultato prima dell'euristica nei 3 resolver
- [x] Pagina: riepilogo, anomalie raggruppate, suggerimenti, export XLSX, reimport, riapplica
- [x] Riapplicazione set-based + ricalcolo calc con fallback Reperibilità→Ordinario
- [x] Voce di menu e voce permessi nella sezione indipendente Gestione Commesse
- [x] Testato su dati reali: 145 valori distinti, 2 alias → 6.064 righe risolte, 1 ignorato escluso

## QA SQL — obbligatoria per ogni release (dalla 1.7.66)
- [x] **Ogni** file `sql/*.sql` eseguito su MariaDB con il tokenizer reale di `sql_runner.php`
- [x] Sequenza completa 56→66 su DB fresco: tutti OK, schema_version finale corretta
- [x] Root `upgrade_*.sql` eseguito 2 volte con lo splitter naive di `system_update.php` (idempotenza)
- [x] Consolidato commentato eseguito via SQL Runner
- [x] Nessun `;` nelle righe di commento dei file SQL
- [x] Fix virgola pendente in `migration_v1_7_65.sql` (errore 1064 #11)

## Import dati reali (fix 1.7.65)
- [x] Riconoscimento automatico riga intestazione (titolo + riga vuota sopra): 25/25 header
- [x] Nessuna regressione su export privi di riga di titolo
- [x] Colonne allargate su misura reale (ticket 80→500; max rilevato 234)
- [x] Export commesse verificato: nessuna colonna sottodimensionata
- [x] Clamp difensivo con contatore troncamenti
- [x] Import reale 69MB: 66.845 righe → 66.822 rapporti, 0 troncamenti, picco 18 MB
- [x] Re-import idempotente; migration RUN1/RUN2 puliti

## Ere di versionamento (fix 1.7.64)
- [x] `pm_version_order()` classifica per era: non-`1.x` (certV) prima di `1.0.0`
- [x] Testato con le chiavi reali 2.0/2.1: `pm_latest_version()` = 1.7.64 (non più 2.1)
- [x] Auto-bump v1.5.4 limitato a `PM_VERSION` e non regressivo
- [x] `app/Version.php` era-aware: DB su 2.x riconosciuto come vecchio e riparato
- [x] Migration di riparazione delle chiavi (1.7.57 / 2.1 → 1.7.64)

## Versionamento (fix 1.7.63)
- [x] `db_upgrade.php`: `pm_version_order()` auto-manutenuto (era hardcoded a 1.7.57)
- [x] Target di default = `pm_latest_version()` (era la stringa '1.7.57')
- [x] Guardia anti-regressione + allineamento app/schema/release_label
- [x] Rimossa la seconda lista hardcoded (`$_ordered`)
- [x] `system_update.php`: splitter non scarta più le istruzioni precedute da commenti
- [x] Testato: DB 1.7.57 + target vuoto → applica 58..63, scrive 1.7.63
- [x] Testato: 1.7.58 15→20 istruzioni, 1.7.61 0→1; root SQL 40/40 invariato

## Import XLSX (fix 1.7.62)
- [x] `app/XlsxReader.php` in streaming (XMLReader su stream zip://): memoria costante
- [x] Parità di output col parser precedente sui file reali
- [x] 150.000 righe / 287 MB XML con memory_limit=512M: picco 2,0 MB (vecchio: OOM Killed)
- [x] `app/UploadGuard.php`: POST scartata intercettata prima di `Csrf::verify()`; `UPLOAD_ERR_*` tradotti
- [x] Importer riga-per-riga, commit ogni 500, rollback su errore, `set_time_limit(0)`
- [x] Form: limite di caricamento corrente mostrato
- [x] Re-import idempotente verificato su DB

## Versionamento (fix 1.7.61)
- [x] `app/bootstrap.php`: auto-bump innescato (Config.php protetto non poteva farlo)
- [x] `app/Version.php`: allinea app/schema/release_label; preserva label personalizzate
- [x] `system_update.php`: Step 5 allinea tutte e tre le chiavi
- [x] Testato: DB 1.7.58/57/57 → 1.7.61; no-op se allineato; label custom preservata

## Anti-collisione (fix 1.7.60)
- [x] Tabelle modulo prefissate `cm_*`; nessuna collisione con `projects`/`clients` preesistenti
- [x] Registro clienti `clients` riusato (UNIQUE su name); `projects` (Referenze) mai toccata
- [x] Cleanup tabelle 1.7.59 pre-fix via `DROP TABLE IF EXISTS` (vuote)
- [x] QA su DB che replica produzione: `projects`/`clients` con dati → invariati dopo doppio upgrade
- [x] Query di `ProjectModel::listAll()` verificata su DB reale (LISTALL_OK)

## Aggiornamento in fase unica
- [x] Root con **un solo** SQL: `upgrade_1_7_56_to_1_7_92.sql` (40 statement, 0 skip indesiderati)
- [x] Consolidamento 1.7.56 → 1.7.92 idempotente; testato da 1.7.56 e da 1.7.59 pre-fix
- [x] File 1.7.56/1.7.57 inclusi (`cert_import_cisco.php`, `report_certificazioni.php`)
- [x] `sql/`: migration per-versione 56/57/58/59 + consolidato commentato

## Database
- [x] Root SQL splitter-safe verificato
- [x] `sql/migration_v1_7_59.sql` (SQL Runner) + `sql/migration_v1_7_58.sql` inclusi
- [x] Idempotenza verificata su MariaDB (RUN1/RUN2/RUN3 puliti)
- [x] 10 tabelle nuove (`cm_*` + `client_locations`) + Dipartimenti (cumulativo)
- [x] Seed: Wenest SRL (Marcon), Weenergy (Montevarchi), 6 prefissi, 6 fasce, 36 tariffe, permessi ruolo 1
- [x] Colonna generata `in_working_hours` testata; ENUM `Reperibilità` (accento) OK

## Documentazione
- [x] `docs/TECHNICAL_DESIGN_v1.7.92.md` (viste, logiche calcolo, moduli, schema ER)
- [x] `docs/MANUALE_ADMIN_v1.7.92.md`
- [x] `docs/MANUALE_UTENTE_v1.7.92.md`
- [x] `docs/DEPLOYMENT_v1.7.92.md`

## Post-deploy
- [ ] Riavvio Apache (OPcache) + Ctrl+F5
- [ ] Verifica menu "Gestione Commesse" e conteggi DB
