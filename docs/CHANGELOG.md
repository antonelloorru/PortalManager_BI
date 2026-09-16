# PortalManager — CHANGELOG

## [1.8.11] - 2026-07-29 — Riconciliazione DGB ↔ Commesse
### Aggiunto
- **Collegamento DGB ↔ commesse native**: il contratto DogoBit è dedotto da
  `cm_projects.external_link` (`.../contract/editV2/<id>`) e materializzato nella nuova colonna
  **`cm_projects.dgb_contract_id`** (mappatura persistente, indicizzata; 1050 commesse mappate,
  786 con attività DGB).
- Vista **`vw_dgb_commessa_rollup`**: aggregato per contratto di attività, ore (ordinario,
  straordinario, trasferta), costi, ricavi e periodo.
- **Scheda commessa** (`project_dashboard.php`): nuova **tab DGB** con rollup (attività, ore,
  straordinario, trasferta, costo, ricavo, incaricati, periodo), confronto con valore/consuntivo
  della commessa, elenco ultime attività DGB e link all'analisi DGB filtrata sul contratto.
- **Elenco commesse** (`manage_projects.php`): colonna **DGB att/ore** con attività e ore
  consuntivate per ogni commessa collegata.
- **Analisi DGB** (`dgb_activities.php`): filtro **Commessa**, colonna **Commessa** nella tabella
  attività con link alla scheda, deep-link `?contract=<id>` dalla scheda commessa.
### Modificato
- `app/DgbModel.php`: `commessaRollup()`, `rollupForContract()`, `activitiesForContract()`,
  `contractLabels()`; `table()` include `id_contract`. Rollup UI via aggregati indicizzati
  (predicato sul contratto) invece della vista materializzata, per prestazioni.
### Note
- Popolamento `dgb_contract_id` idempotente: valorizza solo dove NULL (preserva override manuali).
- Su MariaDB 10.4: usato `ALTER TABLE ADD INDEX IF NOT EXISTS` e `ADD COLUMN IF NOT EXISTS`.

## [1.8.10] - 2026-07-29 — Hotfix updater: backup DB memory-safe
### Corretto
- Durante l'aggiornamento via `system_console.php` compariva
  *«Allowed memory size … exhausted … in app/UpdaterCore.php»*: lo Step 2 (backup DB)
  caricava ogni tabella interamente in memoria (`fetchAll`) concatenando tutte le INSERT in
  un'unica stringa, esaurendo la memoria sulle tabelle grandi del modulo DGB
  (`dgb_forms_activity`, `dgb_operator_allocation`, `dgb_forms_activity_operator`).
- Il backup DB è ora in **streaming**: query non bufferizzata + scrittura incrementale a
  blocchi su file (memoria costante). Verificato con `memory_limit=256M`: picco ~4 MB.
### Modificato
- `app/UpdaterCore.php`: Step 2 riscritto (fwrite a blocchi, `MYSQL_ATTR_USE_BUFFERED_QUERY=false`
  durante il dump, ripristino a fine ciclo, gestione errori per tabella).
### Note
- Modifica solo applicativa: nessuna variazione di schema.
- **Sblocco**: poiché il crash avviene nel backup prima dell'estrazione, copiare manualmente
  `app/UpdaterCore.php` dal pacchetto nella cartella del portale (oppure alzare temporaneamente
  `memory_limit`), quindi rieseguire l'aggiornamento.

## [1.8.9] - 2026-07-29 — DGB: orario ordinario/straordinario, carico e distribuzione temporale
### Aggiunto
- **Orario & Carico** in *Attività & Rendicontazione DGB*: KPI ore ordinarie, straordinario
  (con %), trasferta, carico totale, **capacità standard** (giorni lavorativi × ore ordinarie/giorno
  × incaricati) e **saturazione** rispetto alla capacità.
- **Distribuzione temporale** delle ore lavorate (ordinario + straordinario impilati) con **linea
  baseline di orario ordinario**, in due viste: **Mesi** (distribuzione sul periodo di riferimento,
  non cumulativa) e **Giorni** (distribuzione per giorno di un mese selezionato, con sfondo weekend).
- **Nuovi filtri**: Tipo report (STD/R_ANTEA), Modalità (sede/remoto), Ore ordinarie/giorno
  (baseline configurabile), oltre a data lavoro, incaricato e stato.
- **Export della distribuzione** in **XLSX**, **CSV** e **grafico SVG** (immagine scaricabile).
- API estesa (`dgb_api.php`): nuove azioni `hours` e `distribution`, parametri `gran` (day/month)
  e `month`; `charts` include ora `hours_breakdown` e `temporal_distribution`.
### Modificato
- `app/DgbModel.php`: `whereDetail()`, `hoursBreakdown()`, `temporalDistribution()`,
  `workingDaysBetween()`, `normFilters()` estesa; `chartsJson()` con breakdown e distribuzione.
- `dgb_activities.php`: builder SVG condiviso `dgb_dist_svg()`, sezione Orario & Carico, grafico
  distribuzione con toggle mese/giorno, handler export distsvg/distxlsx/distcsv.
### Note
- Data di riferimento del lavoro: `report_date` (fallback `date_start`).
- Modifica solo applicativa: nessuna variazione di schema (usa colonne e viste della v1.8.8).

## [1.8.8] - 2026-07-28 — Gestione Commesse: Attività & Rendicontazione DGB
### Aggiunto
- Nuovo modulo **Attività & Rendicontazione DGB** (sorgente gestionale DogoBit) sotto
  *Gestione Commesse*, pagina `dgb_activities.php` con due schede: **Analisi & KPI** e **Import & Diff**.
- **Ingestion batch** (`app/DgbImporter.php`): import dei 5 modelli (CSV separatore `|`, parser
  `fgetcsv` con campi quotati multi-riga), casting rigido Datetime e gestione NULL, upsert su PK.
  5 tabelle: `dgb_operator`, `dgb_operator_allocation`, `dgb_forms_activity_planning`,
  `dgb_forms_activity`, `dgb_forms_activity_operator`.
- **Gerarchia** pianificazione (padre) → attività (figlio, FK `id_activity_planning`, autore
  `id_report_author`) → incaricati (dettaglio, FK `id_activity`, incaricato `id_operator`):
  vista `vw_dgb_activity_hierarchy`.
- **KPI & Data Quality**: SLA d'innesco (scostamento avvio previsto vs effettivo, `vw_dgb_sla`),
  consuntivo vs pianificato (`vw_dgb_planned_vs_actual`), distribuzione carico sede/remoto per
  incaricato con baseline (`vw_dgb_load_by_operator`), ticket orfani e piani vuoti.
- **API JSON** (`dgb_api.php`) parametrizzata (range date, incaricato, stato): azioni
  `filters|table|kpi|charts|anomalies`; struttura tabella (ID, SLA, ore, costi) e grafici
  (gauge consuntivo/pianificato, distribuzione carico con baseline).
- **Rigenerazione con diff** (`dgb_import_log`): al re-import calcola nuovi/modificati/invariati
  via firma per riga (`sig`) e produce il report differenziale, con storico batch.
- Grafici server-side SVG (gauge + distribuzione con baseline), export XLSX/CSV, voce menu e
  permessi (`dgb_activities.php`, `dgb_api.php`) per Super Admin, Direttore IT, Resp. Commerciale,
  Coordinatore Tecnico, Finance.
### Note
- Nessuna FK hard tra le tabelle DGB (dataset esterno con riferimenti pendenti): solo indici.
- I CSV sorgente duplicano ogni riga: dedup automatico via PK in upsert.

## [1.8.7] - 2026-07-25 — Hotfix: warning migrazioni assenti in db_upgrade
### Corretto
- Aprendo `db_upgrade.php` comparivano in testata numerosi warning
  *«file_get_contents(… migration_v1_7_xx.sql): Failed to open stream: No such file or directory»*
  per le migrazioni storiche già applicate e non più presenti nella cartella `sql/`.
  Le voci `$UPGRADE_SQL[...]` ora leggono il file **solo se esiste**, tramite l'helper
  `pm_migration_sql()` (nessun warning; per i file assenti si registra stringa vuota, senza
  effetti sull'upgrade delle versioni già applicate).
### Modificato
- `db_upgrade.php`: aggiunto `pm_migration_sql()`; tutte le 82 letture delle migrazioni convertite.
### Note
- Modifica solo applicativa: nessuna variazione di schema. Migrazione `1.8.7` = allineamento versione.

## [1.8.6] - 2026-07-25 — Carico & Sovrapposizioni: filtri estesi
### Aggiunto
- Nel filtro di **Carico risorse e sovrapposizioni** (*Gestione Commesse → Carico &
  Sovrapposizioni*), oltre alla selezione di una o più risorse, ora si può filtrare per:
  **Società** di appartenenza del dipendente, **Cliente**, **Commessa/progetto** (già presente),
  **Stato operativo**, **Linea di servizio** (già presente) e **Tipologia**.
- I filtri si applicano in modo coerente a heatmap persona × mese, grafico mensile, **grafico
  giornaliero**, conflitti per risorsa, sovrapposizioni tra commesse ed **export XLSX**.
### Modificato
- `app/Workload.php`: helper `addCommonFilters()` / `needsProjectJoin()` e applicazione a
  `matrix()`, `dailyMatrix()` e `projectOverlaps()` (JOIN a cm_projects resa condizionale ai filtri).
- `workload_overview.php`: nuovi menu a tendina nel pannello filtri, preservazione dei filtri nei
  form di selezione risorse, mese di dettaglio ed export.
### Note
- Modifica solo applicativa: nessuna variazione di schema. Migrazione `1.8.6` = allineamento versione.

## [1.8.5] - 2026-07-25 — Commesse: Report & Avanzamento + Workflow agganciato al Gantt
### Aggiunto
- Nuovo tab **Report & Avanzamento** nella scheda commessa (da *Gestione Commesse → Commesse /
  Progetti* → apri commessa).
- **Report / note di avanzamento**: l'operatore inserisce note **datate** con **tipo** (nota,
  avanzamento, rischio, decisione, milestone), **titolo**, testo, **valutazione** (1–5 stelle),
  percentuale di avanzamento, collegamento a fase/step e **allegati** multipli (PDF, Office,
  immagini, ZIP…). Elenco cronologico con download/rimozione allegati, modifica ed eliminazione.
- **Workflow programmabile**: step configurabili per commessa (nome, descrizione, **fase collegata**,
  **stato** da fare/in corso/completato/bloccato, inizio/scadenza, **responsabile**, avanzamento %,
  **gate**, ordine). Cambio rapido di stato, riepilogo per stato e avanzamento medio.
- **Aggancio al Gantt**: gli step collegati a una fase **ricalcolano automaticamente** la percentuale
  della fase mostrata nel Gantt; nel tab Gantt compare una riga **Workflow** con marcatori a rombo
  posizionati sulle **scadenze** degli step (i gate sono evidenziati).
### Schema
- Nuove tabelle `cm_project_updates`, `cm_project_update_files`, `cm_workflow_steps` (FK su
  `cm_projects` con ON DELETE CASCADE). Nessuna modifica a tabelle esistenti.
### Modificato
- `app/ProjectWorkflow.php` (nuovo): letture, riepilogo e ricalcolo avanzamento fase (aggancio Gantt).
- `project_dashboard.php`: tab, POST handler (PRG+CSRF), upload/download allegati (UploadGuard),
  soft-delete, marcatori workflow nel Gantt.
### Note
- Allegati salvati in `uploads/commesse/<id commessa>/`; download servito dall'applicazione
  con nome originale. Le scritture richiedono il permesso di modifica della scheda commessa.

## [1.8.4] - 2026-07-25 — Commesse/Progetti: filtri ed esportazione
### Aggiunto
- In **Gestione Commesse → Commesse / Progetti** (`manage_projects.php`) un **pannello filtri**:
  ricerca (codice/nome), stato operativo, stato commerciale, tipologia, linea di servizio,
  azienda esecutrice, cliente, intervallo di **valore** (min/max), intervallo di **data d'inizio**
  e **ordinamento** (codice, nome, valore, margine, inizio).
- **Esportazione XLSX e CSV** dell'elenco che **rispetta i filtri applicati**, con colonne:
  codice, nome, cliente, azienda esecutrice, linea di servizio, tipologia, stato operativo,
  stato commerciale, valore, costi materiali, margine, data inizio e fine.
- La tabella mostra ora anche tipologia e stato commerciale; contatore delle commesse filtrate.
### Modificato
- `app/ProjectModel.php`: `listAll()` estesa con i nuovi filtri e l'ordinamento e con colonne
  aggiuntive (project_type, commercial_status, material_costs, start_date, end_date). Retrocompatibile.
- `manage_projects.php`: pannello filtri, gestione export (XLSX via XlsxWriter, CSV con `;` e UTF-8 BOM), tabella arricchita.
### Note
- Modifica solo applicativa: nessuna variazione di schema. L'export è consentito a chi può visualizzare la pagina.

## [1.8.3] - 2026-07-25 — Gantt commesse: grafica ridisegnata
### Corretto / Migliorato
- Il **Gantt commesse** (portfolio *Gestione Commesse → Gantt commesse*) e il **Gantt della
  scheda commessa** (tab Gantt) risultavano compressi e con barre/tacche sovrapposte su periodi
  lunghi. Grafica ridisegnata per leggibilità:
  - **timeline a larghezza minima** con scorrimento orizzontale (non si comprime più): le barre
    mantengono spessore leggibile anche su molti mesi;
  - barre **pianificato** ed **effettivo** su corsie separate e più alte, con angoli arrotondati
    e ombra leggera; barra effettiva rossa se supera la fine pianificata;
  - **griglia mensile allineata** in un unico livello (una linea per mese, diradata se troppe) e
    **tacche dei mesi non sovrapposte** (densità adattata alla larghezza);
  - colonna descrizioni **fissa** (sticky) durante lo scorrimento; linea **oggi** a tutta altezza;
  - **istogramma del carico mensile** (scheda commessa) rivisto: colonne con larghezza minima,
    scorrimento, etichette non ruotate/leggibili.
### Modificato
- `app/Gantt.php`: nuovi helper `timelineMinWidth()`, `tickBudget()`, `monthGridlines()` (con
  diradamento) e `css()` (stile condiviso `.pm-gantt` / `.pm-hist`); metodi dati invariati.
- `project_gantt.php` e `project_dashboard.php` (tab Gantt): rendering ricostruito con il nuovo layout.
### Note
- Modifica solo applicativa: nessuna variazione di schema. Migrazione `1.8.3` = allineamento versione.

## [1.8.2] - 2026-07-25 — Carico risorse: andamento giornaliero per mese
### Aggiunto
- In **Carico risorse e sovrapposizioni**, oltre all'andamento mensile, è ora disponibile la
  vista **giornaliera** di un mese: nuovo grafico *«Andamento giornaliero del carico per risorsa»*
  con una polilinea per risorsa (ore consuntivate al giorno), **bande dei weekend** evidenziate e
  **linea di capacità giornaliera** (ore/giorno feriale) di riferimento.
- Selettore **Mese di dettaglio** (a discesa sui mesi del periodo) per scegliere il mese da
  analizzare in dettaglio; rispetta i filtri attivi (risorse, commessa, linea di servizio) e il
  limite alle prime 12 risorse per leggibilità.
- Export XLSX: nuovo foglio **«Giornaliero YYYY-MM»** (risorsa × giorno) per il mese selezionato.
### Modificato
- `app/Workload.php`: nuovi metodi `daysOfMonth()`, `isWorkingDay()`, `dailyCapacity()`,
  `dailyMatrix()`, `dailyChartSeries()` (granularità giornaliera; nessuna modifica alle viste mensili).
- `workload_overview.php`: card del grafico giornaliero con SVG server-side (nessuna dipendenza JS)
  e foglio giornaliero nell'export.
### Note
- Modifica solo applicativa: nessuna variazione di schema. Migrazione `1.8.2` = allineamento versione.

## [1.8.1] - 2026-07-25 — Fix permessi pagine economiche (ruolo Finance)
### Corretto
- Le nuove pagine 1.8.0 (`hr_economic_years.php`, `finance_compare.php`, `import_economics_xlsx.php`)
  venivano assegnate per **id fisso 10** assumendo che fosse il Responsabile Finanziario. In questo
  ambiente il ruolo finanziario è **«Finance» (id 11)**, mentre l'id 10 è «Coordinatore Tecnico»:
  le pagine finivano sul ruolo errato e il ruolo Finance restava escluso.
- La migrazione **1.8.1** assegna ora le pagine al ruolo finanziario **per nome**
  («Finance» o «Responsabile Finanziario»), robusto rispetto all'id, e rimuove l'assegnazione errata
  dall'id 10 quando non è un ruolo finanziario.
- Allineato il metadata di `db_upgrade.php` all'id reale del ruolo Finance (11) per gli step
  **1.7.90**, **1.7.97** e **1.8.0**: risolve il banner *«Disallineamento rilevato — 2 elementi
  mancanti»* mostrato dal controllo integrità pur essendo alla versione target.
### Verificato
- Migrazione idempotente con entrambi gli executor (tokenizer `sql_runner`: 5 stmt RUN1/RUN2 ok;
  splitter naive `system_update` sul consolidato: 171 stmt RUN1/RUN2 ok).
- Su installazione pulita: pagine economiche assegnate a ruoli 1, 2, 11; zero residui sul ruolo 10.
- Controllo integrità: 0 elementi mancanti su tutti gli step.

## [1.8.0] - 2026-07-25 — Dati economici per anno di competenza
### Aggiunto
- **Annualità economiche**: i dati economici (input per-dipendente e valori di riferimento globali)
  hanno ora validità **annuale** e sono catalogati per anno di competenza.
  - Nuova tabella `hr_economic_years` (catalogo esercizi: corrente, blocco, note).
  - Nuova tabella `hr_employee_economics` (input economici per dipendente e anno, UNIQUE `employee_id`+`year`).
  - `hr_reference_values` e `hr_reference_history` estese con la colonna `year` (UNIQUE spostata su `ref_key`+`year`).
- **Selettore anno di competenza** nelle viste **Finance** (`finance_overview.php`) e **Compensation & Benefit**
  (`employee_compensation.php`): i valori economici e i parametri di riferimento sono relativi all'esercizio scelto.
- **Confronto tra annualità** (`finance_compare.php`): confronto di una metrica economica (TotaleFTE+CA,
  TotCostoTab, FullCost, CostoNoAuto, ValoreFTE, RAL) tra due esercizi, per dipendente e in aggregato,
  con delta assoluto e percentuale ed esportazione XLSX.
- **Gestione annualità** (`hr_economic_years.php`): creazione esercizi, impostazione esercizio corrente,
  blocco/sblocco, ed eventuale **clonazione** degli input economici e dei riferimenti da un anno sorgente
  (per avviare la compilazione massiva del nuovo anno).
- **Import massivo dati economici per anno** (`import_economics_xlsx.php`): download di un **template XLSX**
  standard e import XLSX/CSV con match del dipendente per Codice dipendente → Codice fiscale → Email aziendale,
  UPSERT idempotente su (dipendente, anno), riepilogo per riga.
- Voci di menu in *Amministrazione*: Confronto annualità, Import dati economici, Annualità economiche.
- Permessi delle nuove pagine per Super Admin, HR Director e Responsabile Finanziario.
### Modificato
- `app/CostModel.php`: calcolo **anno-aware**. `refs($year)` legge i riferimenti per anno con fallback
  all'anno precedente più vicino → `app_settings` → default di fabbrica; nuovi metodi `currentYear()`,
  `years()`, `resolveYear()`, `economics($empId,$year)`; `compute($e,$year)`.
- `employee_compensation.php`: opera per esercizio; salva in `hr_employee_economics`; per l'anno corrente
  rispecchia i valori nelle colonne di `employees` (retrocompatibilità scheda anagrafica); blocco esercizio.
- `finance_overview.php`: colonne economiche e calcoli per l'esercizio selezionato (LEFT JOIN
  `hr_employee_economics`), export con anno nel nome file.
### Migrazione
- I dati economici attualmente presenti nella scheda dipendente sono migrati come **esercizio 2025**
  (annualità corrente): 200 dipendenti con dati.
- Migrazione idempotente/ri-eseguibile, verificata con entrambi gli executor (tokenizer `sql_runner` e
  splitter naive `system_update`).


## [1.7.99] - 2026-07-21 — Hotfix: file XLSX segnalato come danneggiato all'apertura
### Corretto
- I file XLSX esportati risultavano **danneggiati in Excel** quando una colonna conteneva valori
  decimali con zero iniziale, come Val.KM `0,5` o OverHead Aziendale `0,03`. Causa: nel generatore
  `XlsxWriter` la tabella delle stringhe condivise veniva serializzata **prima** della costruzione dei
  fogli; quei valori, esclusi dalla pre-indicizzazione ma trattati come testo in fase di scrittura,
  venivano aggiunti alla tabella già chiusa, e le celle finivano per referenziare indici inesistenti.
- Correzioni applicate: i fogli sono ora costruiti **prima** di `sharedStrings.xml` (indici sempre
  coerenti); riconoscimento numerico riscritto su forma decimale canonica, così `0,5` e `0,03` sono
  numeri veri mentre matricole e telefoni con zeri iniziali (`007`, `0552…`) restano testo e non vengono
  troncati; gestione del foglio senza righe (elemento `<cols>` vuoto non più emesso); `styles.xml`
  completato con `cellStyles`, `dxfs` e `tableStyles`.
### Verificato
- Riproduzione del difetto e conferma della correzione: indice massimo usato 7 su 6 stringhe (file
  corrotto) → 5 su 6 (coerente).
- Validazione con parser OOXML reale su casi limite (zeri iniziali, negativi, `&`/`<`/`>`/apici,
  accenti ed euro, celle vuote, foglio vuoto): tutte le parti XML ben formate, apertura senza avvisi.
- Export Finance completo a 29 colonne: apertura corretta, 48 stringhe condivise coerenti, valori
  numerici tipizzati (ValoreFTE 8.490,81 · TotaleFTE+CA 94.583,24 · Val.KM 0,5 · OverHead 0,03).
### Note
- Correzione solo applicativa: nessuna variazione di schema. Riguarda **tutte** le esportazioni XLSX del
  portale (Finance, estrazione anagrafica, anomalie import, timesheet, carico risorse).

## [1.7.98] - 2026-07-21 — Finance: vista personalizzabile e colonne economiche
### Aggiunto
- **Personalizzazione della vista**: flag di visibilità per ogni colonna e campo d'ordine per disporle a
  piacere; preferenze salvate per utente in `finance_view_prefs`, con pulsante di ripristino.
- **17 colonne economiche**: RAL, FullCost, ValoreTABP, Qt. Trasferte Annue, Qt. Buoni Pasto,
  TotAAxTA+BP, Km concordati (annui), Val.KM, Rimborso KM, Incentivazione Extra, Valore Medio anno Auto,
  TotalePreOverHead, OverHead Aziendale, TotCostoTab, CostoNoAuto, ValoreFTE, TotaleFTE+CA — derivate da
  `CostModel` e **visibili solo con il permesso riservato HR** (escluse anche da pannello ed export).
- **Esportazione a due modalità**: *tutti i campi* (default) oppure *come la vista personalizzata*;
  la scelta è memorizzata e forzabile al volo dai link dedicati. I filtri sono sempre rispettati.
### Verificato — dati sintetici
- Registro: 29 colonne totali, 12 senza permesso HR, tutti e 17 i campi economici presenti.
- Valori in riga coerenti col prospetto (FullCost 62.238,32 · TotCostoTab 86.092,43 · ValoreFTE 8.490,81 ·
  TotaleFTE+CA 94.583,24).
- Preferenze: default 12 colonne; vista salvata con ordine personalizzato (TotaleFTE+CA in prima colonna)
  correttamente riletta; export 29 colonne in modalità *tutti* e 5 in modalità *vista*; XLSX valido.
- Render con tutti i controlli (flag, ordine, radio export, ripristino, link diretti) senza warning.
- QA SQL: sequenza completa (1.7.98, ri-eseguibile) → schema **1.7.98**; root consolidato 150/150 ×2.
### Note
- I campi sorgente per i calcoli sono selezionati solo se presenti in `employees`, per compatibilità con
  schemi parziali.

## [1.7.97] - 2026-07-21 — Sezione Finance in Amministrazione
### Aggiunto
- Nuova pagina **`finance_overview.php`** (menu *Amministrazione → Finance*) con il quadro completo del
  personale: Cognome, Nome, Email aziendale, **Azienda o Agenzia**, Sede, Dipartimento, Qualifica/Ruolo,
  Tipo di rapporto, Stato, Data assunzione, Data fine, Classificazione finanziaria.
- **Filtri** per ricerca libera, azienda, agenzia, sede, dipartimento, tipo di rapporto, classificazione
  finanziaria, stato e periodo di assunzione; **export XLSX/CSV** coerente con i filtri e tracciato nel log.
- Collegamenti diretti dalla riga alla **scheda anagrafica** e alla **scheda riservata HR Compensation &
  Benefit** (visibili solo con i relativi permessi).
- Nuovo campo **`employees.agency`** (Azienda o Agenzia di provenienza), editabile nell'inquadramento
  contrattuale; se assente si mostra l'azienda del dipendente.
- Permessi per Super Admin, HR Director e **Responsabile Finanziario** (che riceve anche l'accesso alla
  scheda anagrafica e alla sezione riservata HR); restano abilitabili singoli utenti dalla mappa permessi.
### Verificato — dati sintetici
- Tutte le 12 colonne richieste presenti e valorizzate; *Azienda o Agenzia* mostra l'agenzia quando
  presente (Agenzia Hays) e l'azienda altrimenti.
- Filtri verificati uno per uno (stato 2/1, tipo rapporto, agenzia, classificazione, ricerca, dipartimento,
  data assunzione); export XLSX valido; render e filtro applicato senza warning; permessi su ruoli 1, 2, 10.
- QA SQL: sequenza completa (1.7.97, ri-eseguibile) → schema **1.7.97**; root consolidato 149/149 ×2.
### Note
- La vista non contiene dati retributivi: i valori economici restano nella scheda riservata HR.

## [1.7.96] - 2026-07-21 — Inquadramento contrattuale: tipo di rapporto "Interinale"
### Aggiunto
- Nuovo valore **Interinale** per il tipo di rapporto: ENUM `employees.contract_type` esteso
  (Indeterminato, Determinato, Apprendistato, **Interinale**, Somministrazione, Consulenza, Stage,
  Partita IVA), opzione presente nel select della scheda dipendente e nell'elenco dei tipi contratto di
  *Anagrafica dipendenti* (usato anche dal filtro e dal modulo di inserimento/modifica).
- La whitelist server-side del salvataggio e' stata aggiornata di conseguenza.
### Verificato — dati sintetici
- ENUM esteso correttamente; salvataggio e rilettura del valore *Interinale*; tutti i valori preesistenti
  conservati (8 tipi, 1 record ciascuno); 8 opzioni presenti nel select della scheda.
- QA SQL: sequenza completa (1.7.96, ri-eseguibile) → schema **1.7.96**; root consolidato 141/141 x2.
### Note
- La migrazione crea la colonna se assente e poi estende l'ENUM, restando applicabile anche su schemi
  parziali. `manage_employees.php` entra nello staging del pacchetto per allineare l'elenco dei tipi.

## [1.7.95] - 2026-07-21 — Formule di calcolo modificabili e storicizzate; ValoreFTE su CostoNoAuto
### Modificato / Aggiunto
- **ValoreFTE = CostoNoAuto × Moltiplicatore FTE** (prima su TotCostoTab). Con questa modifica l'intero
  modello coincide con il prospetto di riferimento (ValoreFTE 8.490,81 · TotaleFTE+CA 94.583,24).
- Le otto formule sono ora **configurabili da interfaccia**: nuova tabella `hr_formulas`, sezione
  *Formule di calcolo* dentro *Valori di riferimento HR*, con **storico** in `hr_formula_history`
  (espressione precedente, nuova, motivo, autore, data).
- Nuovo `app/FormulaEval.php`: parser a discesa ricorsiva che ammette solo numeri, nomi noti, `+ - * /`
  e parentesi. **Nessun eval()**: tentativi come `system("ls")`, `phpinfo()` o `ral; DROP TABLE x` sono
  rifiutati. Validazione al salvataggio e fallback alla formula predefinita in caso di errore a runtime.
- La scheda Compensation mostra le formule correnti in calce, con rimando alla pagina di configurazione.
### Verificato
- Modello allineato al prospetto: TotCostoTab 86.092,43 · CostoNoAuto 68.584,88 · **ValoreFTE 8.490,81** ·
  **TotaleFTE+CA 94.583,24**.
- Sicurezza del valutatore: 6 espressioni malevole/non valide correttamente rifiutate, 3 valide accettate.
- Modifica di una formula applicata immediatamente ai calcoli e registrata nello storico; fallback su
  formula non valida verificato. Render di entrambe le pagine senza warning.
- QA SQL: sequenza completa (1.7.95, ri-eseguibile) → schema **1.7.95**, 8 formule create; root
  consolidato 139/139 ×2.

## [1.7.94] - 2026-07-21 — Riferimenti HR storicizzati, scheda Compensation separata, CostoNoAuto
### Modificato / Aggiunto
- **CostoNoAuto** ora vale **FullCost + TotAAxTA+BP + Incentivazione Extra** (prima: TotCostoTab meno il
  valore auto). Coincide con la colonna «Costo senza auto» del prospetto di riferimento.
- I valori di riferimento globali passano da `app_settings` alla nuova tabella **`hr_reference_values`**,
  gestita da **Amministrazione → Valori di riferimento HR** (`hr_reference_values.php`), con **storico
  completo** delle variazioni in `hr_reference_history` (valore precedente, nuovo, motivo, autore, data).
  `CostModel` legge dalla tabella con fallback ad app_settings e ai default: aggiornamento retro-compatibile.
- La sezione **Compensation & Benefit** diventa una **scheda separata** (`employee_compensation.php`),
  riservata HR; la scheda dipendente mantiene un rimando. Nella nuova pagina i valori di riferimento sono
  mostrati in sola lettura (con stato *in uso* / *sovrascritto*), modificabili solo da Amministrazione.
- Voci di menu e permessi per le due nuove pagine (Super Admin e HR).
### Verificato — dati sintetici e prospetto di riferimento
- CostoNoAuto: 20.650,00 sul caso di prova e **68.584,88** per il profilo del prospetto (= colonna T).
- Riferimenti letti dalla nuova tabella; modifica storicizzata (1,42540 → 1,50000 con motivo) e subito
  applicata ai calcoli (FullCost 15.000,00 su RAL 10.000). Render di entrambe le pagine senza warning.
- QA SQL: sequenza completa (1.7.94, ri-eseguibile) → schema **1.7.94**, 5 parametri creati;
  root consolidato 136/136 ×2.
### Note
- La richiesta riportava due volte «Incentivazione Extra» nella formula di CostoNoAuto: è stata
  interpretata come singola occorrenza, coerentemente con il prospetto allegato (T = F + K + O).

## [1.7.93] - 2026-07-21 — Compensation & Benefit: costo pieno e valore FTE
### Modificato / Aggiunto
- La sezione **Compensation & Benefit** è stata spostata nell'area riservata HR della scheda dipendente
  (subito prima di *Note riservate HR*).
- Nuovi campi manuali su `employees`: **Moltiplicatore FC** (5 decimali), **Qt. Trasferte Annue**,
  **Qt. Buoni Pasto**, **ValoreTABP**, **Val.KM** (4 decimali, accanto a *Km concordati*),
  **Incentivazione Extra**, **Valore Medio anno Auto**, **OverHead Aziendale**, **Moltiplicatore FTE**.
- Nuovi **valori di riferimento aziendali** in `app_settings` (1,42540 / 46,48 / 0,5000 / 0,0300 /
  0,1238) usati quando il campo del dipendente è vuoto, evidenziati in interfaccia con il badge **RIF.**
- Nuovo `app/CostModel.php` con i campi **calcolati** (non memorizzati): FullCost, TotAAxTA+BP,
  Rimborso KM, TotalePreOverHead, TotCostoTab, CostoNoAuto, ValoreFTE, TotaleFTE+CA.
- I nuovi campi sono inclusi nell'anti-leak server-side per chi non ha il permesso compensation.
### Verificato — file di riferimento fornito (riservato, elaborato solo nel container)
- Le formule riproducono i valori del prospetto: FullCost 62.238,32 · TotAAxTA+BP 3.346,56 ·
  Rimborso KM 15.000,00 · TotalePreOverHead 83.584,88 · **TotCostoTab 86.092,43** · ValoreFTE 10.658,24 ·
  **TotaleFTE+CA 96.750,67**. Verifica passo-passo di tutte le otto formule superata.
- Override per dipendente e fallback al riferimento globale funzionanti; sezione renderizzata con tutti i
  campi, valori calcolati e badge RIF., senza warning.
- QA SQL: sequenza completa (1.7.93, ri-eseguibile) → schema **1.7.93**, colonne con la precisione
  richiesta; root consolidato 130/130 ×2.
### Note
- Nel prospetto originale ValoreFTE è calcolato sul costo senza auto; qui si applica la formula
  richiesta (TotCostoTab × Moltiplicatore FTE).

## [1.7.92] - 2026-07-21 — Merge anagrafiche: controllo "Email aziendale mancante"
### Aggiunto
- Nuovo criterio **Email aziendale mancante** in *Verifica & Merge Anagrafiche Dipendenti*: elenca i
  dipendenti con `personal_email` valorizzata e `business_email` vuota (sia NULL sia stringa vuota),
  con selezione per riga e *seleziona tutti*. Il pulsante **Applica ai selezionati** copia la personale
  nell'aziendale **solo** sui record scelti dall'operatore; la UPDATE è comunque vincolata a
  aziendale vuota + personale presente, quindi non sovrascrive mai un valore esistente.
- Operazione registrata in `app_logs`; richiede il permesso di modifica sulla pagina.
### Verificato — dati sintetici
- Candidati rilevati correttamente (NULL e stringa vuota), esclusi sia chi ha già l'email aziendale sia
  chi non ha la personale; applicazione selettiva su un solo record con gli altri lasciati invariati;
  render del nuovo tab senza warning.
- QA SQL: sequenza completa (1.7.92) → schema **1.7.92**; root consolidato 120/120 ×2.
### Note
- Modifica solo applicativa: nessuna variazione di schema.

## [1.7.91] - 2026-07-21 — Estrazione anagrafica: colonna Email aziendale
### Aggiunto
- L'estrazione anagrafica dipendenti (XLSX/CSV) include ora la colonna **Email aziendale**, ricavata da
  `employees.business_email` con fallback su `work_email`/`email` se lo schema differisce. La colonna è
  posizionata dopo *Matricola*.
### Verificato — dati sintetici
- Colonna presente in intestazioni, anteprima, XLSX e CSV con i valori corretti; filtri invariati.
- QA SQL: sequenza completa (1.7.91) → schema **1.7.91**; root consolidato 120/120 ×2.
### Note
- Modifica solo applicativa: nessuna variazione di schema.

## [1.7.90] - 2026-07-21 — Estrazione anagrafica dipendenti (XLSX/CSV) e ruolo Responsabile Finanziario
### Aggiunto
- Nuova pagina **`export_employees.php`** (menu *Anagrafica → Estrazione anagrafica*): estrazione in
  **XLSX** e **CSV** (separatore `;`, UTF-8 con BOM) con le colonne Cognome, Nome, Codice fiscale,
  Matricola, Contratto, Data assunzione, Data cessazione, Azienda, Sede, Tipo rapporto, Dipartimento,
  Qualifica/Ruolo, Classificazione finanziaria, Stato. Filtri per azienda e stato (in forza/cessati/tutti),
  anteprima a video, tracciamento dell'estrazione nel log.
- Nuovo ruolo **Responsabile Finanziario** (creato solo se assente). L'accesso alla pagina è riservato a
  Super Admin, HR Director e Responsabile Finanziario; il download richiede il permesso di esportazione.
- Le colonne di `employees` sono rilevate via `information_schema` e le denominazioni risolte dalle lookup
  (aziende, sedi, modalità di lavoro, dipartimenti). Nessun dato retributivo nell'estrazione.
### Verificato — dati sintetici
- Tutte le colonne richieste presenti; valori corretti da lookup; filtri attivi/cessati/azienda coerenti;
  XLSX valido (archivio leggibile) e CSV con BOM e intestazioni corrette; ruolo #10 creato e permessi
  assegnati ai soli ruoli 1, 2 e 10; render della pagina senza warning.
- QA SQL: sequenza completa (1.7.90, ri-eseguibile) → schema **1.7.90**; root consolidato 120/120 ×2.
### Note
- La creazione del ruolo è idempotente e non sovrascrive ruoli esistenti con lo stesso id o nome.

## [1.7.89] - 2026-07-21 — Fasce legate alla singola commessa (multi-valore E, fascia X per professionista)
### Aggiunto
- **Alias di fascia per commessa**: `cm_alias_band.project_id` (0 = globale) con UNIQUE
  `(raw_band, project_id)`. La stessa sigla può essere mappata su fasce diverse per commessa; in
  riapplicazione la priorità è alias di commessa → nome → alias globale. In *Fasce non risolte* ogni
  sigla mostra ora le commesse in cui compare, con menu di mappatura dedicato.
- **Tariffe di fascia per commessa e professionista**: nuova tabella `cm_project_band_rates`
  (`professional_id = 0` → tutta la commessa). Copre la fascia `E` con valori diversi per commessa e la
  fascia `X` legata al professionista che esegue l'attività. Nuova card *Tariffe di fascia per questa
  commessa* nella dashboard, con aggiunta/rimozione (rimozione tracciata nel Cestino).
- `RateResolver::rateFor()`/`calcCostsFor()` e il ricalcolo di `cm_reapply()` applicano la priorità
  **commessa+professionista → commessa → tariffa generale**, con fallback Reperibilità → Ordinario.
### Verificato — dati sintetici
- Sigla `E` mappata su E1 in commessa A e su E2 in commessa B: risoluzione corretta per entrambe.
- Tariffe: E su A = 550 (override di commessa 55 €/h), E su B = 600 (tariffa generale 60), fascia `X`
  del professionista su A = 950 (95 €/h dedicati). `rateFor` coerente su tutti gli scope.
- QA SQL: sequenza completa (1.7.89, migration ri-eseguibile con sostituzione della UNIQUE) → schema
  **1.7.89**; root consolidato 116/116 ×2. Render di entrambe le pagine senza warning.
### Note
- Migrazione idempotente; gli alias esistenti restano globali (`project_id = 0`).

## [1.7.88] - 2026-07-21 — Riconciliazione: mappatura dei tecnici anche su Anagrafica Professionisti
### Corretto / Aggiunto
- Il menu **Mappa a** dei tecnici non risolti non considerava l'Anagrafica Professionisti: ora elenca
  dipendenti **e** professionisti. I professionisti collegati puntano al dipendente; gli **esterni** usano
  la chiave `P<id>` con etichetta *[professionista esterno]*.
- La scelta è persistita nella nuova colonna `cm_alias_technician.professional_id`: in riapplicazione
  risolve su `technician_id` (professionista collegato) o su `technician_professional_id` (esterno).
  Anche il reimport del foglio mappature accetta id `P<id>`; il foglio di riferimento esporta id/risorsa/tipo.
- **Suggerimento migliorato**: il confronto valuta anche la forma con token ordinati, così i nomi invertiti
  vengono proposti (es. "Luca Esterni" ↔ "Esterni Luca": da 58% a 100%; "Mario Rossi" ↔ "Rossi Mario" era 45%).
### Verificato — dati sintetici
- Professionista esterno presente in elenco come `P<id>`; suggerimento 100% su nome invertito; mappature
  salvate e riapplicate: 5/5 rapporti all'esterno (`technician_professional_id`), 3/3 al dipendente
  collegato (`technician_id`), 0 residui. Render select e etichette senza warning.
- QA SQL: sequenza completa (1.7.88, migration ri-eseguibile) → schema **1.7.88**, colonna presente;
  root consolidato 111/111 ×2.
### Note
- Migrazione idempotente (`ADD COLUMN/KEY IF NOT EXISTS`).

## [1.7.87] - 2026-07-21 — Professionista esterno come tecnico del rapporto e nel team
### Aggiunto
- Un rapporto di intervento può essere attribuito a un **professionista esterno**: nuova colonna
  `cm_intervention_reports.technician_professional_id` (→ cm_professionals). La riconciliazione
  (`cm_reapply`) associa automaticamente i rapporti non risolti ai professionisti esterni (match
  username/sigla/nome/nome invertito), quando non c'è un dipendente corrispondente.
- `cm_team` ora ospita anche gli esterni: `employee_id` nullable, nuove colonne `professional_id`
  (UNIQUE per commessa) e `member_type` ('dipendente'/'esterno'). *Popola team dai rapporti* include gli
  esterni; la vista team mostra un badge **Dipendente/Esterno**. Costo orario esterni dal loro
  `hourly_cost`, dipendenti dalla RAL.
### Verificato — dati sintetici
- 7 rapporti associati all'esterno (username + nome); team con 2 risorse: esterno 56h €/h 45,00 e
  dipendente 10h €/h da RAL; badge Tipo renderizzati senza warning.
- QA SQL: sequenza completa (1.7.87, migration ri-eseguibile) → schema **1.7.87**, colonne presenti;
  root consolidato 109/109 ×2.
### Note
- Migrazione idempotente (`ADD COLUMN/KEY IF NOT EXISTS`, `MODIFY`); i team esistenti restano 'dipendente'.
## [1.7.86] - 2026-07-21 — Hotfix: ricerca Anagrafica Professionisti (colonna ambigua)
### Corretto
- La ricerca (e i filtri) nell'Anagrafica Professionisti generava un errore fatale
  *"Column 'first_name' in where clause is ambiguous"*: la query di elenco fa il JOIN con `employees`
  (che ha le stesse colonne nome/cognome) e le condizioni del WHERE non erano qualificate con l'alias di
  tabella. Ora tutte le colonne dei filtri (ricerca, stato, azienda, attivi, tipo) sono qualificate con
  l'alias `p`, e la query di conteggio usa lo stesso alias. Ricerca e filtri tornano operativi.
### Verificato
- Ricerca per nome/username/email/sigla e tutti i filtri (stato, azienda, attivi, tipo, combinati) senza
  eccezioni; render pagina con ricerca attiva pulito.
- QA SQL: sequenza completa (1.7.86) → schema **1.7.86**; root consolidato 101/101 ×2.
### Note
- Modifica solo applicativa: nessuna variazione di schema.

## [1.7.85] - 2026-07-21 — Riconciliazione: verifica su anagrafiche Dipendenti e Professionisti
### Aggiunto
- Nuovo pannello **Verifica anagrafiche (Dipendenti + Professionisti)** in *Controllo & Riconciliazione*:
  confronta i tecnici non risolti con l'anagrafica Dipendenti aggiornata e con l'anagrafica Professionisti
  e li classifica (corrispondono a Dipendenti / Professionisti collegati / Professionisti esterni da
  promuovere / senza corrispondenza), con conteggi e dettaglio. Pulsante **Allinea ora** per applicare
  retroattivamente la risoluzione ai rapporti già importati; avviso e link diretto per i professionisti
  esterni da promuovere/collegare.
### Verificato — dati sintetici
- Classificazione corretta con priorità Dipendente > Professionista: dipendente 3, professionista
  collegato 1 (via username), professionista esterno 2, senza corrispondenza 1. Render del pannello e del
  pulsante senza warning.
- QA SQL: sequenza completa (1.7.85) → schema **1.7.85**; root consolidato 101/101 ×2.
### Note
- Modifica solo applicativa: nessuna variazione di schema.

## [1.7.84] - 2026-07-21 — Merge anagrafiche: criterio "Stesso nome (simile)"
### Aggiunto
- Nuovo criterio di confronto **Stesso nome (simile)** in *Verifica & Merge Anagrafiche Dipendenti*:
  raggruppa per la prima parola di cognome e nome e mostra solo i gruppi in cui il nome completo
  differisce. Serve a unire schede prive di **secondo nome** o con **cognome composto** parziale
  (es. "Mario Rossi" ↔ "Mario Giuseppe Rossi", "Anna Rossi" ↔ "Anna Rossi Bianchi"). Merge sempre manuale.
### Verificato — dati sintetici
- Raggruppa correttamente i casi target (secondo nome / cognome composto mancante) ed **esclude** i
  doppioni con nome completo identico (già coperti dagli altri criteri), evitando rumore. Render del nuovo
  tab senza warning.
- QA SQL: sequenza completa (1.7.84) → schema **1.7.84**; root consolidato 101/101 ×2.
### Note
- Modifica solo applicativa: nessuna variazione di schema. La pagina merge_employees.php entra nello
  staging del pacchetto (era già presente in produzione dalla v1.7.49).

## [1.7.83] - 2026-07-21 — Riconciliazione via Professionisti + promozione a Dipendente
### Corretto / Aggiunto
- **Controllo & Riconciliazione** ora vede e usa l'Anagrafica Professionisti (e Dipendenti): in
  ri-applicazione semina gli **alias tecnico** dai professionisti collegati a un dipendente (username,
  sigla, nome/cognome anche invertiti) e risolve i tecnici anche via JOIN su `cm_professionals`. I
  rapporti dei professionisti/dipendenti si agganciano automaticamente.
- **Anagrafica Professionisti**: nuovo pulsante **In Dipendenti** che importa un professionista esterno
  nell'anagrafica dipendenti creando il record `employees` e collegandolo. Se non attivo, imposta la
  **data di cessazione** (`end_date`), proposta automaticamente; il dipendente è creato come inattivo.
### Verificato — dati reali
- Promozione: dipendente attivo (nessuna cessazione) e dipendente cessato (`end_date` valorizzata,
  status inactive); professionista portato a stato *unito*.
- Riconciliazione: 25/25 rapporti di test risolti (username, nome, nome invertito) grazie alla semina
  alias e alla JOIN sui professionisti; 8 alias seminati.
- QA SQL: sequenza completa (1.7.83) → schema **1.7.83**; root consolidato 101/101 ×2.
### Note
- Nessuna variazione di schema: modifiche solo applicative. `promoteToEmployee` si adatta alle colonne
  realmente presenti in `employees` (rilevate via information_schema).

## [1.7.82] - 2026-07-21 — Professionisti: distinzione Esterni vs Dipendenti
### Aggiunto / Corretto
- Un operatore che è già un dipendente ora è **distinguibile** in anagrafica professionisti. In fase di
  import viene rilevata la corrispondenza con un dipendente (per **email** o **nome**, anche invertito) e
  registrata sul professionista (`employee_match`, `matched_employee_id`, `match_type`).
- Nella scheda: colonna **Tipo** con badge **Dipendente**/**Esterno**, filtro **Tipo** (solo esterni /
  solo dipendenti), contatori **Esterni** e **Dipendenti**, e pulsante **Rileva dipendenti** per
  riesaminare le corrispondenze quando cambia l'anagrafica dipendenti.
- L'import conta e segnala i "riconosciuti dipendenti"; l'auto-collegamento per email resta disponibile.
### Verificato — file reale riservato
- 237 professionisti importati, tutti inizialmente esterni; dopo il rilevamento (5 email + 3 nome) →
  8 dipendenti / 229 esterni; filtro Tipo e badge coerenti; `match_type` corretto.
- QA SQL: sequenza completa (1.7.82) → schema **1.7.82**, colonna `employee_match` presente; root
  consolidato 101/101 ×2.
### Note
- Nuove colonne aggiunte in modo idempotente (`ADD COLUMN IF NOT EXISTS`).

## [1.7.81] - 2026-07-21 — Anagrafica Professionisti (operatori dal gestionale + merge)
### Aggiunto
- Nuova **anagrafica professionisti** (`cm_professionals`, `app/ProfessionalStore.php`) separata dai
  dipendenti, per gli operatori del gestionale non presenti tra i dipendenti.
- **`import_professionals.php`**: importa l'export "operator" (CSV separatore `|`) con UPSERT su ID
  operatore. **Per riservatezza NON importa le credenziali** (`password`, `temp_password`,
  `password_history`, `rfid`): solo dati anagrafici, azienda (da sigla), recapiti, costo orario/pieno,
  skill, note. Opzioni: auto-collegamento per email identica, inclusione degli eliminati.
- **`professionals.php`**: scheda di gestione con ricerca, filtri (stato/azienda/attivi), contatori e
  **merge verso dipendenti** — suggerimento per email o nome (anche invertito) e collegamento che crea
  gli alias tecnico per agganciare i rapporti al dipendente. Stati: nuovo/confermato/unito/ignorato.
- Voci di menu e permessi Super Admin per entrambe le pagine.
### Verificato — file reale riservato (elaborato solo nel container, non diffuso)
- Import: 237 professionisti (10 eliminati saltati), 2ª passata idempotente (0 nuovi). Nessuna colonna
  credenziale nella tabella. Merge: suggerimento per email e per nome corretti; collegamento imposta
  stato *unito*, employee_id e crea 4 alias tecnico.
- QA SQL: sequenza completa (1.7.81) → schema **1.7.81**, tabella `cm_professionals` e 2 permessi
  presenti; root consolidato 98/98 ×2 (idempotente).
### Note
- I codici azienda (WTS/NIS/ANT/WEN) sono risolti dalla sigla; l'anagrafica è indipendente da `employees`.

## [1.7.80] - 2026-07-20 — Import Commesse DB (export nativo del gestionale)
### Aggiunto
- Nuova pagina **`import_commesse_db.php`** (menu *Gestione Commesse → Import Commesse DB*), a fianco
  dell'import XLSX. Importa l'export nativo del gestionale (tabella `contract`): CSV con separatore `|`,
  quoting `"`, CRLF, letto con parser nativo in streaming. UPSERT su `code`→`project_code` (ri-eseguibile
  senza duplicati). Mappatura completa dei campi economici (valore/valore a oggi, margine, residuo,
  consuntivato), date, stato (OPEN/CLOSED/SUSPENDED→Aperta/Chiusa/Sospesa), anomalie; cliente creato in
  anagrafica e azienda esecutrice dal prefisso del codice. Righe `deleted=1` saltate salvo opt-in.
### Verificato — file reale riservato (elaborato solo nel container, non diffuso)
- 1.134 righe → 1.054 commesse importate (80 eliminate saltate), 298 clienti auto-creati; 2a passata
  idempotente (0 nuove, 1.054 aggiornate); mappatura campione corretta (codice, cliente, stato, date,
  valori); stati tradotti (Aperta 548 / Chiusa 485 / Sospesa 21); azienda esecutrice su 1054/1054;
  opzione include-deleted porta il totale a 1.134.
- QA SQL: sequenza completa (1.7.80: 1/1) → schema **1.7.80**, permesso presente; root consolidato 64/64 ×2.
### Note
- Solo permesso di pagina, nessuna variazione di schema. I codici combaciano con i rapporti già importati.

## [1.7.79] - 2026-07-20 — Carico & Sovrapposizioni: filtro per linea di servizio
### Aggiunto
- Nuovo filtro **Linea di servizio** nella vista *Carico risorse e sovrapposizioni*: applica
  `cm_projects.service_line` a heatmap, conflitti per risorsa e sovrapposizioni tra commesse (e all'export
  XLSX). Le opzioni sono popolate dalle linee effettivamente presenti sulle commesse.
### Verificato — dati reali
- Ripartizione corretta per linea (es. Manutenzione 15.922 h / Progetti 4.310 h); conflitti 361 → 216/0
  e coppie di commesse 3 → 1/0 in funzione della linea. Render con dropdown popolato e selezione
  mantenuta, senza warning.
- QA SQL: sequenza completa (1.7.79: 1/1) → schema **1.7.79**; root consolidato 63/63 ×2.
### Note
- Nessuna variazione di schema (`service_line` già presente su `cm_projects`).

## [1.7.78] - 2026-07-20 — Cestino nella sezione Sistema
### Modificato
- La voce di menu **Cestino** è stata spostata dalla sezione *Gestione Commesse* alla sezione
  **Sistema**, dove risiede insieme a Console di sistema e agli altri strumenti trasversali. Aggiornata
  di conseguenza la mappa permessi (`manage_permissions.php`). Nessuna variazione di funzionalità: il
  cestino continua a coprire i record cancellati di tutto il portale.
### Note
- Nessuna variazione di schema né di permessi.

## [1.7.77] - 2026-07-20 — Cestino esteso a tutto il portale
### Aggiunto / Modificato
- Il **recupero dei record cancellati** ora copre **ogni tabella dati** del portale, non più solo il
  modulo Gestione Commesse. `app/RecycleBin.php` è passato da whitelist a **denylist**: intercetta tutte
  le cancellazioni tranne quelle su tabelle di sistema (`cm_deleted_records`, `app_logs`, `app_settings`)
  e su tabelle riscritte in blocco o in sincronizzazione (`role_permissions`, `user_permissions`,
  `menu_preferences`, `employee_credly_link`, `employee_linkedin_link`).
- **Chiave primaria auto-rilevata** da `information_schema` (con cache): il ripristino funziona anche per
  tabelle con PK diversa da `id`.
- Nuovo helper `RecycleBin::capture()` per agganciare una cancellazione al cestino in una riga.
- Agganciate al cestino le cancellazioni reali di: certificazioni (`user_certifications`), reparti
  (`departments`) e sotto-record del dipendente in `employee_profile.php` (lingue, titoli di studio,
  esperienze, telefono, SIM, notebook, veicolo, interventi veicolo, carte carburante, log rifornimenti,
  carte di credito, estratti conto). Etichette descrittive per ciascun tipo.
### Verificato — dati reali
- Politica denylist corretta (dati recuperabili, sistema escluso). PK auto-rilevata (es. tabella con PK
  `code`). Ciclo soft-delete → cestino → restore con PK originale su tabella non-`cm_` (departments) e su
  PK alternativa. Denylist: delete diretta senza archivio (app_logs).
- QA SQL: sequenza completa (1.7.77: 1/1) → schema **1.7.77**; root consolidato 63/63 ×2.
### Note
- Nessuna variazione di schema (`cm_deleted_records` già presente dalla 1.7.76).

## [1.7.76] - 2026-07-20 — Cestino: ripristino dei record cancellati per errore
### Aggiunto
- **`recycle_bin.php`** (menu *Gestione Commesse → Cestino*, `app/RecycleBin.php`, tabella
  `cm_deleted_records`): meccanismo di soft-delete + ripristino. Prima di eliminare uno o più record,
  il sistema ne archivia lo stato completo (JSON) con autore, data e contesto; dal Cestino è possibile
  **ripristinarli** (re-inserimento con la PK originale) o eliminarli definitivamente, oltre a svuotare
  le voci più vecchie di N giorni.
  - Ripristino sicuro: se esiste già un record con la stessa chiave, l'operazione viene bloccata e
    segnalata (nessuna sovrascrittura).
  - Whitelist di tabelle gestite (`cm_team`, `cm_project_phases`, `cm_timesheet_entries`,
    `cm_intervention_reports`, `cm_projects`, `cm_presales_effort`, `cm_alias_*`).
  - Vista filtrabile per tipo/stato/testo; autore ricavato da `employees` (fallback display_name/email).
- Agganciati al cestino i delete di **membri team** e **fasi** (`project_dashboard.php`) e delle **voci
  timesheet** (`timesheet.php`): ora passano da `softDelete()` invece del DELETE diretto.
### Verificato — dati reali
- Ciclo completo: soft-delete di 2 fasi → 2 voci in cestino con JSON corretto → ripristino (fase
  ripristinata, conteggio coerente) → conflitto PK rilevato e bloccato → purge → purgeOlderThan.
  Tabella non in whitelist: delete diretta senza archivio. `listItems` con nome autore e filtri OK.
- QA SQL: sequenza completa (1.7.76: 2/2) → schema **1.7.76**, tabella e permesso presenti; root
  consolidato 63/63 ×2. Render pagina senza warning.
### Note
- La cancellazione fisica di una commessa propaga (FK ON DELETE CASCADE) su team/fasi/timesheet: il
  cestino cattura la riga richiesta esplicitamente; per un recupero completo ripristinare prima la
  commessa e poi gli elementi figli.

## [1.7.75] - 2026-07-20 — HOTFIX console: tab Log non mostrava le righe
### Corretto
- Nella **Console di sistema → Log** non compariva alcun evento. La query univa `app_logs` a `users`
  leggendo `users.first_name`/`users.last_name`, colonne **rimosse in v2.2**: falliva con
  *"Unknown column"* e, intercettata dal `try/catch`, lasciava la tabella vuota senza segnalazioni.
  Ora il nome utente è ricavato da **`employees`** tramite `users.employee_id` (stesso pattern di
  `view_logs.php`), con fallback all'email e poi all'id. In caso di errore la tabella mostra ora un
  messaggio diagnostico invece di restare muta.
### Verificato
- Contro lo schema reale (`users` senza `first_name`/`last_name`): la query legge le righe e risolve il
  nome da `employees` (o email / id per le azioni di sistema). La vecchia query conferma l'errore
  *Unknown column 'u.first_name'*.
### Note
- Nessuna variazione di schema.

## [1.7.74] - 2026-07-18 — Carico & Sovrapposizioni: filtri completi e fascia temporale
### Corretto
- **Le "Sovrapposizioni tra commesse" non rispettavano i filtri commessa e risorse** (solo il periodo
  era applicato, e non sempre in modo evidente). Ora `projectOverlaps()` applica anche il filtro
  commessa (mostra solo le coppie che la includono) e il filtro risorse (coppie che coinvolgono le
  persone selezionate); il periodo restringe correttamente mesi e ore.
### Aggiunto
- Le sovrapposizioni tra commesse riportano la **fascia temporale** della sovrapposizione (dal primo
  all'ultimo mese in comune, ricalcolata sul periodo), le **ore per ciascuna commessa** (Ore A / Ore B)
  e il totale in contesa, oltre a mesi e risorse condivise. Nuovo foglio "Sovrapposizioni commesse"
  nell'export XLSX.
### Verificato — dati reali
- Filtro periodo: fascia e ore si restringono (es. WTS_3016↔WTS_3018: 43 mesi/9.030 h sull'intero
  arco → 6 mesi/991 h su 2025-01..2025-06 → 3 mesi/810 h su 2026-01..2026-03).
- Filtro commessa: 3 → 2 coppie, tutte contenenti la commessa scelta. Filtro risorse: vincolo rispettato.
- QA SQL: sequenza completa (1.7.74: 1/1) → schema **1.7.74**; root consolidato 61/61 ×2. Render senza warning.
### Note
- Nessuna variazione di schema.

## [1.7.73] - 2026-07-18 — Carico & Sovrapposizioni: grafico, ordinamento e filtro multi-risorsa
### Aggiunto
- **Legenda descrittiva** delle fasce di saturazione (sotto-utilizzo / ottimale / al limite /
  sovraccarico) e del marcatore ⚠ (più commesse nello stesso mese).
- **Ordinamento delle risorse** nella heatmap: per ore (decrescente/crescente) o per nome.
- **Filtro multi-risorsa** (select multipla): heatmap, conflitti e grafico si concentrano sul gruppo
  selezionato; azzeramento rapido.
- **Grafico SVG del carico** per risorsa (server-side, nessuna dipendenza JS): una linea per persona più
  la linea tratteggiata della capacità mensile; con oltre 12 risorse mostra le prime 12 per monte ore e
  invita a restringere col filtro.
- `Workload::matrix()` estesa con `employee_ids` e `sort`; nuovi `chartSeries()` e `capacitySeries()`.
### Verificato — dati reali
- Ordinamento nelle 3 modalità; filtro multi-risorsa (15 → 3); serie grafico (3 linee, 12 punti,
  picco 475 h) con capacità 168-184 h/mese. Render della pagina senza warning in entrambi gli scenari.
- QA SQL: sequenza completa `sql/` (1.7.73: 1/1) → schema **1.7.73**; root consolidato 61/61 ×2.
### Note
- Nessuna variazione di schema (solo presentazione).

## [1.7.72] - 2026-07-18 — HOTFIX anteprima aggiornamento (console)
### Corretto
- Nella scheda **Aggiornamento (ZIP)** della console, l'anteprima mostrava
  `Warning: Array to string conversion` in corrispondenza di *Nuovi* e *Modificati*: `analyze_zip()`
  restituisce quei campi come **elenchi di file** (array), mentre venivano stampati come scalari.
  Ora sono conteggiati con `count()`. Corretta anche l'etichetta *Versione* (`old_version` non è tra
  le chiavi di `analyze_zip`: fallback alla versione installata).
### Note
- Nessuna variazione di schema.

## [1.7.71] - 2026-07-18 — Vista Carico risorse e sovrapposizioni
### Aggiunto
- **`workload_overview.php`** (menu *Gestione Commesse → Carico & Sovrapposizioni*, `app/Workload.php`):
  ricostruisce dai rapporti l'impegno persona × commessa nel tempo (granularità mensile) e ne evidenzia
  le sovrapposizioni. Capacità mensile = giorni feriali × `ts_daily_hours`.
  - **Heatmap risorsa × mese** con colore di saturazione (<60/60-90/90-110/>110%), marcatore per i mesi
    multi-commessa e drill-down per commessa sulla cella.
  - **Conflitti per risorsa**: mesi con più commesse in parallelo e/o oltre capacità (sovraccarico),
    ordinati per gravità, con le commesse coinvolte.
  - **Sovrapposizioni tra commesse**: coppie che condividono risorse negli stessi mesi (contesa), con
    numero di risorse, mesi e ore.
  - Filtri per intervallo mesi/commessa/risorsa e *solo sovraccarichi*; export XLSX (heatmap + capacità
    + conflitti su fogli separati).
### Verificato — dati reali (66.822 rapporti)
- Heatmap su 12 mesi; conflitti persona: 85 righe di cui 38 sovraccarichi (es. una risorsa a 279% della
  capacità con 2 commesse in parallelo); 3 coppie di commesse in contesa (fino a 7 risorse, 12 mesi,
  3.011 h condivise); impegno per commessa (persone/mesi/ore/intervallo) coerente.
- QA SQL: sequenza completa `sql/` con tokenizer reale (1.7.71: 2/2) → schema **1.7.71**, permesso
  presente; root consolidato 61/61 ×2 (idempotente).
### Note
- Nessuna variazione di schema: la vista deriva dai dati già importati.

## [1.7.70] - 2026-07-18 — Console di sistema unificata
### Aggiunto
- **`system_console.php`**: un'unica pagina a schede che riunisce **Aggiornamento (ZIP)**,
  **Migrazioni DB**, **SQL Runner** e **Log** (`app_logs` filtrabili per categoria/livello/testo,
  con paginazione). Riservata al Super Admin.
- Estratti due moduli condivisi per non duplicare logica: `app/UpdaterCore.php` (`analyze_zip`,
  `apply_update`) e `app/SqlConsole.php` (`sql_split_statements` comment/quote-aware, `sql_classify`).
  `sql_runner.php` ora consuma `app/SqlConsole.php` invece delle proprie copie.
### Modificato
- Menu *Sistema*: le voci *DB Upgrade / System update / SQL Runner* sono sostituite da **Console di
  sistema**; *DB Upgrade* resta come "DB Upgrade (motore)" per la procedura completa di migrazione.
- `system_update.php` e `sql_runner.php` reindirizzano alla scheda corrispondente della console
  (compatibilità con bookmark e link esistenti).
### Verificato
- Le 4 schede renderizzano correttamente; anteprima ed esecuzione SQL funzionanti.
- QA SQL: sequenza completa `sql/` con tokenizer reale (1.7.70: 2/2) → schema **1.7.70**, permesso
  `system_console.php` presente; root consolidato 60/60 ×2 (idempotente).
### Note
- Nessuna variazione di schema oltre al permesso di pagina.

## [1.7.69] - 2026-07-18 — Timesheet risorse e Gantt delle commesse
### Aggiunto
- **Timesheet** (`timesheet.php`, `app/Timesheet.php`): griglia dipendente × giorno del mese che somma
  le ore **dai rapporti di intervento** (sola lettura) e le **voci manuali** (`cm_timesheet_entries`:
  Ordinario, Reperibilità, Trasferta, Formazione, Ferie, Permesso, Malattia, Altro). Saturazione sulle
  ore attese (giorni feriali × `ts_daily_hours`, default 8), filtri commessa/dipendente/mese, dettaglio
  giornaliero cliccabile, inserimento/eliminazione voci (PRG+CSRF+audit) ed export XLSX del cartellino.
- **Gantt** (`app/Gantt.php`, HTML/CSS senza JavaScript, stampabile):
  - **Portfolio** (`project_gantt.php`): una barra per commessa, pianificato vs effettivo dai rapporti;
    barra rossa se l'ultimo rapporto supera la fine pianificata. Filtri per stato/azienda/testo.
  - **Tab Gantt nella scheda commessa**: pianificato vs effettivo, **fasi** (`cm_project_phases`) con %
    di avanzamento, barre per singola risorsa e istogramma del carico mensile; gestione fasi inline.
- Menu *Gestione Commesse*: nuove voci **Timesheet** e **Gantt commesse**. Permessi Super Admin su
  `timesheet.php` e `project_gantt.php`. Setting `ts_daily_hours`.
### Verificato — dati reali
- QA SQL: sequenza completa `sql/` con tokenizer reale (1.7.69: 5/5) → schema **1.7.69**;
  root consolidato 59/59 ×2 (idempotente).
- Timesheet: mese reale con 2 risorse, 32 celle da rapporti; voce manuale (Ferie 8h + Formazione 2h =
  10h sommate), dettaglio ed eliminazione corretti.
- Gantt commessa (6.737 rapporti): effettivo 2023-01→2026-07 (28.002 h), 3 fasi rese con avanzamento,
  2 barre per tecnico, 43 mesi di carico; matematica barre left/width e tacche asse coerenti.
  Portfolio: barra disegnata per ogni commessa con almeno una data.
### Note
- I valori economici e le ore dei rapporti restano la fonte di verità: il timesheet li mostra, non li altera.

## [1.7.68] - 2026-07-17 — Consuntivo, dettaglio e modifica rapporti, Team dai rapporti
### Aggiunto
- **Consuntivo — dettaglio completo**: ogni rapporto è espandibile e mostra tutti i campi (cliente, sede,
  riferimento cliente, ticket, tipo servizio, settore tecnologico, inizio/fine, ore pianificate/quantità/
  differenza/extra, ricavo e costo **importati vs calcolati**, costo commerciale calcolato, batch di import,
  richiesta intervento e lavoro eseguito).
- **Consuntivo — modifica del rapporto**: form completo con ricalcolo automatico dei valori `*_calc` via
  `RateResolver` (fascia × ore × regime, Reperibilità con fallback su Ordinario). Modificabili anche
  tecnico, fascia, cliente/sede (select a cascata) e **la commessa di appartenenza** (riassegnazione).
  I valori importati restano la fonte di verità. Soggetta a `can('edit')`, CSRF, PRG e `write_log()`.
- **Consuntivo — elenco paginato e filtrabile**: 50 rapporti per pagina, ricerca su rapporto/ticket/
  tecnico/lavoro eseguito, filtri Approvato e Reperibilità (prima: primi 200 senza filtri).
- **Team — popolamento dai rapporti**: `ProjectModel::syncTeamFromReports()` set-based aggiunge i tecnici
  che hanno lavorato sulla commessa con le ore effettive. Nuova colonna `cm_team.source`
  (`Manuale`|`Rapporti`): le righe inserite a mano **non vengono mai sovrascritte**. Operazione idempotente.
- **Team — ore effettive**: colonne *Ore da rapporti*, *Rapporti* e *Costo su rapporti* accanto alle ore
  allocate, con riga totali; avviso sui tecnici presenti nei rapporti ma non ancora in team.
  Nuovi `ProjectModel::interventionsPaged()`, `intervention()`, `techniciansMissingFromTeam()`.
### Verificato — dati reali (commessa con 6.737 rapporti)
- Sync team: 1 tecnico aggiunto da rapporti (1.958,5 h su 534 rapporti, costo 51.743,57 €);
  riga manuale (999 h, ruolo *Project Manager*) **preservata** pur avendo 3.579 h da rapporti;
  seconda esecuzione idempotente (team invariato).
- Consuntivo: paginazione 6.737 → 50/pagina; filtro *approvato* → 2.898; dettaglio con 43 campi per riga.
- QA SQL: sequenza completa `sql/` con tokenizer reale (1.7.68: 2/2) → schema **1.7.68**;
  root consolidato 55/55 ×2 (idempotente).

## [1.7.67] - 2026-07-17 — Controllo & Riconciliazione import
### Aggiunto
- **Nuova sezione `import_control.php`** (menu *Gestione Commesse → Controllo & Riconciliazione*):
  rende azionabili le righe importate ma non risolte, invece di limitarsi a contarle nell'esito.
  - Riepilogo rapporti non risolti per commessa/tecnico/fascia con percentuali.
  - **Anomalie raggruppate per valore grezzo** con occorrenze e periodo: su dati reali 66.822 righe
    senza tecnico = **145 valori distinti** da mappare.
  - **Suggerimento automatico** per similarità (`similar_text`, soglia 60%), preselezionato.
  - **Export XLSX** delle anomalie (via `XlsxWriter`): 3 fogli anomalie con `mappa_a_id`/`ignora`
    da compilare + 3 fogli di riferimento con gli ID validi.
  - **Reimport del file compilato** → crea gli alias e riapplica.
  - **Riapplicazione massiva** set-based (`UPDATE … JOIN`), senza reimportare l'export originale,
    con ricalcolo di `client_revenue_calc`/`company_cost_calc`/`commercial_cost_calc` secondo la
    stessa regola di `RateResolver` (Reperibilità con fallback su Ordinario).
- **Alias persistenti**: `cm_alias_project`, `cm_alias_technician`, `cm_alias_band` (+ `app/AliasStore.php`).
  Consultati **prima** dell'euristica da `ProjectModel::resolveTechnician()`, dal nuovo
  `ProjectModel::resolveProjectId()` e da `RateResolver::bandIdByName()`: gli import successivi
  risolvono automaticamente. `ignored = 1` esclude un valore dalla lista di lavoro mantenendone traccia.
- Esito dell'import: collegamento diretto alla sezione di controllo quando restano righe non risolte.
### Verificato — dati reali (import 69 MB, 66.822 rapporti)
- Anomalie tecnico: **145 valori distinti** (vs 66.822 righe); 2 alias mappati → **6.064 righe risolte**
  in una riapplicazione; 1 valore marcato *ignora* → escluso dalla lista (145 → 142 residui).
- `AliasStore::resolve()` restituisce la mappatura salvata (cache invalidata correttamente).
- QA SQL: tutti i file `sql/` eseguiti con il tokenizer reale (1.7.67: 5/5) → schema_version **1.7.67**;
  root consolidato 54/54 ×2 (idempotente).
### Note
- I valori economici importati restano la fonte di verità: la riconciliazione non li altera.

## [1.7.66] - 2026-07-17 — HOTFIX sintassi migration 1.7.65 + QA sui file SQL
### Corretto
- **`sql/migration_v1_7_65.sql`: errore 1064 allo statement #11** (`... near 'ON DUPLICATE KEY UPDATE'`).
  Nel generare il file avevo rimosso i seed `proj_*` dallo statement di bump lasciando una
  **virgola pendente** prima di `ON DUPLICATE KEY UPDATE`. I 10 `ALTER` venivano applicati, il bump no
  (schema_version restava a 1.7.64). File rigenerato e verificato.
- `sql/migration_v1_7_66.sql`: riesecuzione idempotente degli `ALTER` + riallineamento versione,
  per le installazioni che avevano subito il fallimento parziale.
- Igiene SQL: rimossi i `;` dalle righe di commento dei file `sql/` (innocui per il tokenizer di
  `sql_runner.php`, ma insidiosi per qualunque splitter naive basato su `explode(';')`).
### Aggiunto — QA (causa a monte del difetto)
- La verifica pre-rilascio eseguiva solo il **consolidato di root**: i singoli file `sql/` non venivano
  mai eseguiti. Ora la checklist impone l'**esecuzione di ogni file SQL del pacchetto su MariaDB**,
  con gli esecutori reali:
  - `sql/*.sql` con il tokenizer `sql_split_statements()` di `sql_runner.php` (comment/quote-aware);
  - `upgrade_*.sql` di root con lo splitter naive di `system_update.php`, due volte (idempotenza).
### Verificato
- Sequenza `sql/` su DB fresco: 56→66 tutti OK (`1.7.58` 20/20, `1.7.60` 39/39, `1.7.65` 11/11,
  `1.7.66` 11/11) → `schema_version` finale **1.7.66**.
- `migration_v1_7_65.sql` e `migration_v1_7_66.sql` singolarmente su DB fresco: 11/11, 0 errori.
- Root consolidato con splitter naive: 50/50 ×2 (idempotente). Consolidato commentato via
  SQL Runner: 50/50.
### Note
- Nessuna variazione di schema rispetto alla 1.7.65.

## [1.7.65] - 2026-07-16 — Import rapporti su dati reali: intestazione e colonne
### Corretto
- **`Errore import: Colonna "N." (codice rapporto) non trovata`.** Gli export reali hanno una riga di
  **titolo** in prima posizione (una sola cella), una riga vuota, e le intestazioni in **riga 3**.
  `XlsxReader` assumeva come intestazione la prima riga non vuota, intercettando il titolo.
  Ora la riga di intestazione viene **riconosciuta**: scansione delle prime righe (default 25) con
  match sugli `header_hints` (le chiavi della mappa colonne) e ripiego sulla riga con più celle.
  Le righe precedenti sono scartate, quelle successive restano dati. `XlsxReader::norm()` esposto
  per un confronto coerente; nuovi `$opts['header_hints'|'header_scan_rows'|'min_header_cells']`.
- **`Data too long for column 'ticket'`** su dati reali: `ticket` era `VARCHAR(80)` ma il massimo
  effettivo misurato è **234** caratteri. Colonne di `cm_intervention_reports` riviste sui dati reali:
  `ticket` 80→**500**, `report_code` 60→80, `project_name_raw`/`client_raw`/`site_raw`/
  `client_reference` →255, `technician_raw` →200, `tech_sector` →150, `band_raw`/`service_type` →80.
  (Export commesse verificato: nessuna colonna sottodimensionata.)
- Messaggio d'errore degli importer ora elenca le **intestazioni rilevate**, per diagnosi immediata.
### Aggiunto
- **Clamp difensivo** negli importer: un valore fuori misura viene troncato e conteggiato
  (totale riportato nell'esito) invece di abortire un import di decine di migliaia di righe.
### Verificato — file reale (69 MB, 66.860 righe di foglio)
- Intestazioni rilevate: **25/25**, colonna `N.` trovata; nessuna regressione sugli export senza titolo.
- Lettura: **66.845 righe**, picco **18 MB** con `memory_limit=512M`, 5,4 s.
- Import in DB: **66.822 rapporti** (23 codici duplicati nel file, consolidati dall'UPSERT),
  269 clienti, 5 fasce, 26.668 approvati, 140 in reperibilità, 64.119 in orario di lavoro,
  335.092 ore totali; **0 troncamenti**; re-import idempotente (66.822 invariati).
- Migration consolidata: RUN1/RUN2 puliti; `ticket`=500, `client_reference`=255, `report_code`=80.

## [1.7.64] - 2026-07-16 — HOTFIX auto-bump a "2.1"
### Corretto
- **L'auto-bump portava `app_version` a `2.1` con codice 1.7.63.** L'ordine auto-manutenuto
  introdotto in 1.7.63 accodava ogni versione sconosciuta ordinandola con `version_compare()`:
  le chiavi storiche **`2.0`/`2.1`** presenti in `$VERSIONS` (era *certV*, **precedente** alla
  rinomina in PortalManager) finivano dopo le 1.7.x e diventavano "ultima versione".
- `db_upgrade.php` → `pm_version_order()` classifica ora le versioni per **era**: tutto ciò che non
  è `1.x` appartiene alla serie pre-rinomina e viene collocato **prima** di `1.0.0`; dentro ciascuna
  era `version_compare()` è affidabile. Nessuna manutenzione manuale per le release future.
- `db_upgrade.php` → l'auto-bump v1.5.4 non può più superare `PM_VERSION` né regredire rispetto al DB.
- `app/Version.php` → consapevole delle ere (`isLegacyEra()`/`isAhead()`): un DB fermo su `2.x` viene
  riconosciuto come più vecchio del codice e si autoripara. Prima `version_compare('1.7.64','2.1')`
  lo dava per "avanti" e l'auto-bump non interveniva mai.
- `sql/migration_v1_7_64.sql` ripara le chiavi di versione scritte male (1.7.57 o 2.1).
### Verificato
- Ordine con le chiavi reali (incluse 2.0/2.1): testa `2.0 -> 2.1 -> 2.2 ... 5.9`, coda
  `... 1.7.62 -> 1.7.63 -> 1.7.64`; `pm_latest_version()` = **1.7.64**.
- Confronti: `2.1 < 1.7.63`, `1.0.0 > 5.9`, `1.7.64 > 1.7.63`, `2.1 > 2.0`, `1.7.6 < 1.7.57`.
- Auto-bump con DB a 1.7.63 e codice 1.7.64 → scrive **1.7.64** (non più 2.1).
- `Version::isAhead()`: db `2.1` → codice avanti SI; `1.7.64` → NO; `1.7.99` → NO.
### Note
- Nessuna variazione di schema.

## [1.7.63] - 2026-07-16 — HOTFIX versionamento invertito (regressione a 1.7.57)
### Corretto
- **Eseguendo l'upgrade a 1.7.61 il DB tornava a 1.7.57.** In `db_upgrade.php` l'ordine cronologico
  delle versioni era una **lista hardcoded ferma a `'1.7.57'`**: le release successive risultavano
  sconosciute (indice `PHP_INT_MAX`, quindi indistinguibili tra loro), non venivano mai selezionate e
  il **target di default era `'1.7.57'`**, valore scritto in `app_settings.app_version`. Una seconda
  lista hardcoded (`$_ordered`/`$_target_latest`) alimentava un auto-bump con lo stesso effetto.
- `db_upgrade.php`: nuovo `pm_version_order()` **auto-manutenuto** — la sequenza storica resta esplicita
  (dopo la v5.9 il versioning è ripartito da 1.0.0: `version_compare()` non può saperlo), le nuove
  release 1.7.x vengono accodate automaticamente da `$UPGRADE_SQL`/`$VERSIONS`/`PM_VERSION`.
  Aggiunti `pm_version_cmp()`, `pm_latest_version()`, **guardia anti-regressione** (mai scrivere una
  versione precedente a quella a DB; mai superare `PM_VERSION`) e allineamento di
  `app_version`/`schema_version`/`release_label` (prima solo `app_version`).
- **`system_update.php`: lo splitter SQL scartava i chunk che iniziavano con `--`.** Con `explode(';')`
  il blocco di commenti precedente a un'istruzione appartiene allo stesso chunk: l'istruzione veniva
  quindi eliminata in silenzio. Ora i commenti vengono rimossi e si salta solo se non resta SQL.
### Verificato
- Splitter, sui file reali: `migration_v1_7_61.sql` 0→**1** istruzioni; `migration_v1_7_58.sql`
  15→**20** (5 perse, incluso il bump di versione); `upgrade_*` di root 40/40 (già comment-free).
- Ordinamento: `1.7.61 > 1.7.57`, `1.7.63 > 1.7.62`, `1.0.0 > 5.9` (rinomina), `1.7.6 < 1.7.57`.
- Scenario reale — DB a 1.7.57, target vuoto: applica 1.7.58→1.7.63 e scrive **1.7.63**
  (prima: nessun upgrade applicato e versione riscritta a 1.7.57).
### Note
- Nessuna variazione di schema.

## [1.7.62] - 2026-07-16 — Import XLSX in streaming + diagnostica upload
### Corretto
- **"403 — Token di sicurezza non valido" all'import di file grandi.** Non era un problema di CSRF:
  superato `post_max_size`, PHP scarta l'intero body, `$_POST`/`$_FILES` arrivano vuoti e il token
  risulta mancante. Nuovo `app/UploadGuard.php`: intercetta la condizione **prima** di `Csrf::verify()`
  e riporta dimensione inviata, limite attivo (`post_max_size`/`upload_max_filesize`) e rimedi.
  Tradotti anche i codici `UPLOAD_ERR_*` in messaggi leggibili.
- **Esaurimento memoria su XLSX grandi.** `app/XlsxReader.php` riscritto con `XMLReader` in streaming
  sullo stream `zip://`: memoria costante rispetto alla dimensione del foglio. Il vecchio parser
  costruiva il DOM SimpleXML dell'intero foglio decompresso.
### Modificato
- `import_commesse.php` / `import_intervention_reports.php`: elaborazione riga-per-riga via
  `XlsxReader::each()`, commit ogni 500 record, `set_time_limit(0)`, rollback su errore,
  totali del batch consolidati a fine import, picco memoria riportato nell'esito.
- Form di import: indicazione del limite di caricamento corrente.
### Verificato
- Parità di output vecchio/nuovo parser sui file reali (headers, righe, valori identici).
- File 26 MB compressi / 287 MB di XML / 150.000 righe con `memory_limit=512M`:
  vecchio parser **Killed (OOM)**; nuovo parser **picco 2,0 MB**, import completo in DB (150.000 righe),
  re-import idempotente (UPSERT su `report_code`).
### Note
- Nessuna variazione di schema. Restano da alzare in `php.ini` i limiti di caricamento
  (`upload_max_filesize`, `post_max_size`) — vedi `docs/DEPLOYMENT_v1.7.62.md`.

## [1.7.61] - 2026-07-16 — HOTFIX versionamento (auto-bump mai eseguito)
### Corretto
- **La versione a DB non si aggiornava dopo un update.** Causa doppia:
  1. `Version::autoBumpIfNeeded()` era **codice morto**: `app/bootstrap.php` ne demandava
     l'esecuzione a `Config.php`, che però è un **file protetto** mai sovrascritto dai pacchetti
     di update — la chiamata non è mai arrivata su nessuna installazione (dalla v1.7.16).
  2. `system_update.php` aggiornava **solo `app_version`** (dal file `VERSION`), lasciando
     `schema_version` e `release_label` fermi alla release precedente.
     Stato osservato in produzione: `app_version=1.7.58`, `schema_version=1.7.57`, `release_label=1.7.57`.
- `app/bootstrap.php`: innesco dell'auto-bump (usa `$GLOBALS['pdo']`, una volta per sessione/versione,
  in try/catch: non può mai bloccare il bootstrap).
- `app/Version.php`: `needsBump()`/`dbVersions()`; allinea tutte e tre le chiavi; **preserva una
  `release_label` personalizzata** (non semver).
- `system_update.php`: lo Step 5 allinea `app_version`, `schema_version` e `release_label`.
- `sql/migration_v1_7_61.sql`: riparazione delle chiavi rimaste indietro (nessuna variazione di schema).
### Verificato
- Auto-bump da bootstrap su DB 1.7.58/1.7.57/1.7.57 → tutte a 1.7.61; seconda esecuzione no-op;
  `release_label` personalizzata preservata; `system_update` patchato → 40/40 statement, tutte le chiavi allineate.

## [1.7.60] - 2026-07-16 — HOTFIX collisione tabelle modulo Commesse
### Corretto
- **Fatal error `manage_projects.php`** (`Unknown column 'p.project_code'`): la 1.7.59 creava le tabelle
  `projects`/`clients` già esistenti nel modulo *Progetti & Referenze*; i `CREATE TABLE IF NOT EXISTS`
  erano no-op e il modulo interrogava le tabelle sbagliate.
- Tabelle del modulo ricreate nel namespace **`cm_*`** (`cm_projects`, `cm_team`, `cm_presales_effort`,
  `cm_intervention_reports`, `cm_import_batches`, `cm_rate_bands`, `cm_rate_band_rates`,
  `cm_rate_band_history`, `cm_company_prefix_map`).
- **Registro clienti `clients` riusato** (registro unico condiviso); `projects` (Referenze) mai toccata.
- `DROP TABLE IF EXISTS` delle tabelle senza prefisso create dalla 1.7.59 (vuote, mai operative).
### Aggiornamento
- **Fase unica**: file PHP completi già patchati + **un solo SQL in root**
  `upgrade_1_7_56_to_1_7_60.sql`, consolidamento idempotente **1.7.56 → 1.7.60**
  (partenza supportata da 1.7.56 / 1.7.57 / 1.7.58 / 1.7.59).
- `sql/`: migration per-versione 56/57/58/59/60 + consolidato commentato per SQL Runner.
- Inclusi i file di 1.7.56/1.7.57 (`cert_import_cisco.php`, `report_certificazioni.php`).
### Verificato
- QA su DB che replica produzione (`projects`/`clients` popolati + tabelle 1.7.59): doppia esecuzione
  pulita, dati preesistenti invariati, 0 tabelle legacy residue, percorso sequenziale 1.7.58→59→60 OK.

## [1.7.59] - 2026-07-14 — Modulo Gestione Commesse ⚠️ DIFETTOSA (superseded da 1.7.60)
### Aggiunto
- Modulo commesse/progetti: `manage_projects.php`, `project_dashboard.php` (5 tab), `manage_rate_bands.php`.
- Import massivi: `import_commesse.php`, `import_intervention_reports.php` (parser XLSX nativo `XlsxReader`).
- Classi: `ProjectModel`, `RateResolver`, `PrefixResolver`, `XlsxReader`.
- 11 tabelle: clients, client_locations, company_prefix_map, hourly_rate_bands, hourly_rate_band_rates,
  hourly_rate_band_history, projects, project_presales_effort, project_team, intervention_reports,
  intervention_import_batches.
- Aziende del gruppo **Wenest SRL** (prefisso WEN, sede Marcon) e **Weenergy** (WEE, Montevarchi) + mappa prefissi.
- Fasce costo orario storicizzate (tipologia × regime); costo/ricavo interventi import+calcolato.
- Colonna generata `in_working_hours`; redditività previsionale/consuntiva con ripartizioni Valore/Diretto-Indiretto.
### Modificato
- `manage_permissions.php`: **"Gestione Commesse" è ora un gruppo di primo livello indipendente**
  (allineato a Brand & Partnership, Competenze & Formazione, Recruiting & Agenzie), non più annidato in Anagrafica & HR.
- `manage_permissions.php`: **sezioni comprimibili** (clic sull'intestazione), con chevron, contatore
  ("n/m attive" per ruolo, "n override" per utente), comandi *Espandi tutte / Comprimi tutte* e stato persistito.
### Corretto
- **Fatal error su `manage_projects.php`** (`Unknown column 'p.project_code'`): collisione di nomi con le
  tabelle `projects` e `clients` del modulo preesistente *Progetti & Referenze*, che rendeva no-op i
  `CREATE TABLE IF NOT EXISTS`. Tutte le tabelle del modulo usano ora il prefisso **`cm_`**; il registro
  clienti **`clients` viene riusato** (registro unico) e `projects` non è più toccata.
- La migration rimuove le tabelle senza prefisso create dalla 1.7.59 pre-fix (vuote e inutilizzabili).
### Aggiornamento
- **Fase unica**: ZIP con file PHP completi già patchati + **un solo SQL in root**
  `upgrade_1_7_56_to_1_7_59.sql`, consolidamento idempotente **1.7.56 → 1.7.59**
  (supporta partenza da 1.7.56/1.7.57/1.7.58/1.7.59 pre-fix). Inclusi anche i file di 1.7.56/1.7.57
  (`cert_import_cisco.php`, `report_certificazioni.php`).
### Note
- Release **cumulativa su 1.7.58**: include lo schema Dipartimenti e i relativi file (idempotenti).
- Migration verificata idempotente (RUN1/2/3) su MariaDB; upgrade root splitter-safe per `system_update.php`.


## v1.7.58 — 2026-07-13 — "Refactoring Dipartimento (lookup + storicizzazione)"

### Aggiunto
- Nuova tabella lookup `departments` (name univoco + `value_type` ENUM 'Servizio a Valore'/'Non a Valore' obbligatorio + `is_active`).
- Nuova tabella `department_history`: storicizzazione di ogni mutazione (CREATE/UPDATE/DELETE) con snapshot old→new e autore.
- Nuova pagina CRUD `manage_departments.php` (PRG, CSRF, `write_log`, auto-migration difensiva). Voce menu "Dipartimenti / Unità Org." in sezione Amministrazione; permesso `manage_departments.php` in `manage_permissions.php`.

### Modificato
- `employees`: nuova colonna `department_id` (FK → `departments`, ON UPDATE CASCADE / ON DELETE SET NULL). Il campo testo `department` è mantenuto e sincronizzato lato applicativo col nome del dipartimento selezionato (retrocompatibilità viste/export).
- `manage_employees.php` ed `employee_profile.php`: campo Dipartimento da input testo → `<select>` dinamico alimentato dai dipartimenti attivi; POST handler risolve e valida `department_id` e sincronizza il testo. JS di edit aggiornato (`dep` → `department_id`).
- `app/Version.php` → 1.7.58. `db_upgrade.php`: backfill metadata versioni 1.7.54–1.7.58 + mapping `sql/migration_v1_7_58.sql`.

### Schema
- `sql/migration_v1_7_58.sql` (idempotente): crea `departments`/`department_history`, aggiunge `employees.department_id` + FK + indice, backfill guarded dal testo libero legacy, permesso RBAC, allineamento versione.

---



## v1.7.57 — 2026-07-08 — "Hotfix delete Report certificazioni"

### Corretto
- `report_certificazioni.php`: l'eliminazione di una certificazione restituiva una pagina con la sola intestazione.
  - **Causa**: `header.php` incluso in cima al file → al `redirect_self()` (PRG post-delete) gli header HTTP erano già inviati, il redirect falliva e l'`exit` troncava l'output.
  - **Fix**: `header.php` spostato dopo i POST handler (save/delete). Nessuna modifica di schema.

### Versione
- `app/Version.php` → 1.7.57. `sql/migration_v1_7_57.sql` (solo allineamento versione). `db_upgrade.php`.

---


## v1.7.56 — 2026-07-08 — "Import Cisco senza data"

### Modificato
- `cert_import_cisco.php`: ammesse le certificazioni Cisco **senza data di conseguimento** (tracce/esami).
  - Toggle *"Includi certificazioni senza data"* nell'anteprima: attiva le righe (match dipendente presente) prive di Cert Date.
  - Righe con data pre-selezionate; righe senza data evidenziate (ambra) e opzionali.
  - UPSERT difensivo: una riga senza data non azzera `issue_date`/`expiry_date`/`certificate_code` già presenti (`COALESCE`).
- `app/Version.php` → 1.7.56. `db_upgrade.php`.

### Schema
- `sql/migration_v1_7_56.sql`: `user_certifications.issue_date` reso **NULLABLE** (era NOT NULL) per registrare cert senza data.

---


## v1.7.55 — 2026-07-08 — "Import certificazioni Cisco"

### Aggiunto
- Nuova pagina `cert_import_cisco.php`: importer del report XLSX Cisco *Certifications by Individual*.
  - Parser XLSX nativo (ZipArchive + SimpleXML, nessuna dipendenza esterna).
  - Header case-insensitive con sinonimi; date `d-M-Y` (mesi EN) → ISO.
  - Match dipendente automatico per **email** (business/personal) → fallback **nome+cognome**.
  - Brand fisso **Cisco** (auto-creato se assente); tecnologia fallback *Generic*.
  - Import delle sole certificazioni **acquisite** (con Cert Date); tracce d'esame senza data escluse.
  - **UPSERT** logico su `user_certifications` per (employee_id, certification_id): inserisce o aggiorna scadenza/stato/certificate_code → aggiorna i certificati posseduti.
  - Stato calcolato da Expiry Date (active / expiring ≤90gg / expired).
  - Anteprima con match per riga, selezione multipla, opzione "salta esistenti".
- Voce menu *Import certificazioni Cisco* in "Competenze & Formazione"; permesso `cert_import_cisco.php` in `manage_permissions`.

### Modificato
- `app/Version.php` → 1.7.55. `db_upgrade.php`, `app/MenuManager.php`, `manage_permissions.php`.

---


## v1.7.54 — 2026-07-08 — "Classificazione Finanziaria"

### Aggiunto
- Campo **Classificazione Finanziaria** ENUM('Diretto','Indiretto') nella sezione riservata HR *Compensation & Benefit*.
- Colonna `employees.classificazione_finanziaria` (dopo `premio_concordato`), NULL default.

### Modificato
- `manage_employees.php`: comp_fields (validazione whitelist), UPDATE/INSERT compensation, unset defensive, form modale, JS auto-fill (`e_clf`).
- `employee_profile.php`: UPDATE compensation, unset defensive, form scheda profilo.
- `app/Version.php` → 1.7.54. `db_upgrade.php`: registrazione migration + array versioni + target_ver.

### Sicurezza
- Campo trattato come sensibile: reso/scritto solo se `can('view','manage_employees_compensation.php')`; UNSET server-side per non autorizzati (anti-leak DOM/JSON); anti mass-assignment (validazione `in_array` con whitelist valori).

---

# certV — CHANGELOG v5.8.0

## v5.8.0 — 2026-05-06 — "Extensible ENUM con approvazione"

### Aggiunto

**Estensione dinamica ENUM**
- Nuovo tipo schema validator `extensible_enum` (vs `enum` rigido)
- Tabella `enum_proposals` per accumulare valori non censiti durante import
- Idempotente: stesso valore re-importato incrementa `occurrences` invece di duplicare
- ALTER TABLE eseguita solo dopo approvazione esplicita admin
- Match fuzzy case-insensitive (es. `associate` → `Associate`)
- Mappatura "Senior → Professional" applicata anche alle future occorrenze

**Helper `EnumExtender`**
- `getEnumValues` lettura runtime da information_schema
- `recordProposal` UPSERT idempotente su (table, column, value)
- `approveProposal` esegue ALTER TABLE preservando NULL/DEFAULT
- `mapProposal` → status='mapped' con valore canonico
- Whitelist hardcoded: solo `certifications.level`, `certifications.category`, `employee_skills.level`

**Auto-completamento LDB post-approvazione**
- `ImportProcessor::applyEnumDecision()` scansiona staging rows con proposta matching
- Completa automaticamente i record LDB pendenti con il valore canonico
- Funziona sia per Approve (valore originale esteso) che Map (valore mappato)

**UI `manage_enum_proposals.php`**
- Lista proposte raggruppate per status (pending/approved/mapped/rejected)
- Per ogni pending: 3 azioni (Approva con preview ENUM finale / Mappa con dropdown valori esistenti / Rifiuta)
- Mostra valori ENUM correnti per riferimento
- Audit chi-quando-cosa per le decisioni

### Allineamento schema validator

- `catalogo.level`: prima dichiarava `['Foundation','Associate','Professional','Expert','Specialist','Other']`, ora `extensible_enum` su `certifications.level` (DB reale: Foundation/Associate/Professional/Expert/Specialty)
- `catalogo.category`: aggiunta come `extensible_enum` (prima mancava)
- `employee_skills.level`: convertita a `extensible_enum`

### Database

- Nuova tabella `enum_proposals` con UNIQUE su (target_table, target_column, proposed_value)
- 2 permessi inseriti per `manage_enum_proposals.php`
- Schema version → 5.8.0

Migrazione 100% IDEMPOTENTE.

### File modificati (8)

- `sql/migration_v5_8.sql` (NUOVO)
- `app/EnumExtender.php` (NUOVO)
- `app/ImportValidator.php` — extensible_enum + allineamento DB reale
- `app/ImportProcessor.php` — applyEnumDecision + setJobIdForProposals
- `app/Router.php` — slug nuovo
- `header.php` — voce menu
- `manage_enum_proposals.php` (NUOVO)
- `docs/V5_8_TECHNICAL.md` (NUOVO)
