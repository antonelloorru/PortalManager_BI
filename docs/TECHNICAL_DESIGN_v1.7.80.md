# PortalManager — Technical Design v1.7.80
## Modulo "Gestione Commesse"

> **Storia versioni.** Il modulo è stato introdotto in **1.7.59**, che è risultata difettosa
> (collisione dei nomi `projects`/`clients` con il modulo *Progetti & Referenze*: i
> `CREATE TABLE IF NOT EXISTS` erano no-op e la pagina Commesse andava in fatal error).
> La **1.7.60** è la release correttiva: namespace `cm_*`, riuso del registro clienti,
> cleanup delle tabelle inutilizzabili. La 1.7.59 resta nella sequenza di `db_upgrade.php`
> come *superseded* (solo allineamento versione), per non alterare release già distribuite.

### 1. Scopo
Introduce la gestione end-to-end delle commesse/progetti: anagrafica, costi nascosti di
presales, allocazione team HR con costo pieno da RAL, fasce di costo orario storicizzate,
import massivo commesse e import del consuntivo (rapporti di intervento), con calcolo di
redditività previsionale e consuntiva.

Release **cumulativa** su v1.7.58 (Dipartimenti): include, in forma idempotente, anche lo
schema `departments`/`department_history` e la FK `employees.department_id`, oltre ai file
lato applicazione della 1.7.58 (necessario perché la parte file della 1.7.58 non risultava
ancora deployata).

### 2. Nuove entità (schema ER sintetico)

> **Namespace `cm_` (commesse).** Il portale contiene già un modulo *Progetti & Referenze* con
> le tabelle `projects` e `clients`. Per evitare collisioni, tutte le tabelle del modulo
> Gestione Commesse usano il prefisso **`cm_`**. Il registro clienti **`clients` è riusato**
> (registro unico condiviso, UNIQUE su `name`), esteso da `client_locations` per le sedi.
> La tabella `projects` del modulo Referenze **non viene modificata né usata**.

```
companies (esistente) ──1:N── company_locations (esistente)
     │  ▲
     │  └───────── cm_company_prefix_map (prefix → company_id)   [WTS,NIS,ANT,MIPS,WEN,WEE]
     │
cm_projects ──N:1── clients (riusata) ──1:N── client_locations
   │  │  └─N:1── companies (exec_company_id, azienda esecutrice del gruppo)
   │  ├─1:N── cm_presales_effort (centro di costo, ore, tariffa)
   │  ├─1:N── cm_team ──N:1── employees   (ore allocate, override classificazione)
   │  └─1:N── cm_intervention_reports
   │
cm_rate_bands ──1:N── cm_rate_band_rates (cost_type × regime × rate_hour)
        └──── cm_rate_band_history (audit variazioni tariffe)

cm_intervention_reports ──N:1── cm_projects | employees(tecnico) | cm_rate_bands | clients | client_locations
cm_import_batches (registro import commesse/interventi)
```

**Tabelle create (10 nuove):** `client_locations`, `cm_company_prefix_map`, `cm_rate_bands`,
`cm_rate_band_rates`, `cm_rate_band_history`, `cm_projects`, `cm_presales_effort`, `cm_team`,
`cm_intervention_reports`, `cm_import_batches`. Riusata: `clients`.

**Cleanup:** la migration rimuove (`DROP TABLE IF EXISTS`) le tabelle senza prefisso create
dalla 1.7.59 pre-fix (`intervention_reports`, `project_team`, `project_presales_effort`,
`intervention_import_batches`, `hourly_rate_band*`, `company_prefix_map`): erano inutilizzabili
perché agganciate per errore alla `projects` del modulo Referenze. `projects` e `clients` non
vengono mai droppate.

### 3. Mappatura aziende del gruppo (prefisso codice commessa → azienda esecutrice)
| Prefisso | Azienda esecutrice | Sede |
|---|---|---|
| WTS | WETECH'S SPA SB | (esistente) |
| NIS | Nis Group srl | (esistente) |
| ANT | Antea srl | (esistente) |
| MIPS | Mips Informatica | (esistente) |
| **WEN** | **Wenest SRL** (nuova) | **Marcon** |
| **WEE** | **Weenergy** (nuova) | **Montevarchi** |

Il prefisso è la porzione alfabetica iniziale del `codice_commessa` (es. `WEN00031` → WEN).
La risoluzione è fatta da `PrefixResolver` su `company_prefix_map`.

I **clienti** finali sono un registro separato (`clients`/`client_locations`), distinto dalle
aziende interne del gruppo, popolato in automatico (upsert) dagli import.

### 4. Logiche di calcolo

**4.1 Costo orario pieno dipendente**
`costo_orario = (RAL × oneri_mult) / ore_annue`
con `oneri_mult` (default 1.42) e `ore_annue` (default 1720) da `app_settings`
(`proj_oneri_mult`, `proj_annual_hours`). Costo HR risorsa = `costo_orario × ore_allocate`.

**4.2 Redditività previsionale (tab Redditività)**
- Ricavi = `cm_projects.value_total` (valore contratto)
- Costi = `cm_projects.material_costs` + Σ costo HR team + Σ costo presales
- Margine = Ricavi − Costi; Margine % = Margine / Ricavi
- Aggregazioni per **value_type** (Servizio a Valore / Non a Valore, da `departments`)
  e per **classificazione** (Diretto / Indiretto, da `employees` con override in `project_team`).
- Se commessa **Persa**: i costi nascosti presales sono riallocati secondo `loss_allocation`
  (Rischio Impresa 100% / Budget Commerciale 100% / Ripartizione 50/50).

**4.3 Costo/Ricavo rapporti di intervento (doppio: import + calcolato)**
Per ogni rapporto vengono salvati sia i valori **da file** (`*_import`) sia quelli **calcolati**
(`*_calc`) = tariffa fascia × ore × regime, via `RateResolver`:
- `client_revenue_calc` = tariffa Cliente × `quantity_hours` (regime Ordinario/Reperibilità)
- `company_cost_calc` = tariffa Aziendale × `quantity_hours`
- `commercial_cost_calc` = tariffa Commerciale × `quantity_hours`
La **fonte di verità economica** resta il valore importato; il calcolato è comparativo.
Ore di riferimento = colonna `Quantità (ore)` (non l'elapsed inizio–fine).

**4.4 `in_working_hours` (colonna generata STORED)**
Derivata da `start_at`: 1 se giorno feriale (lun–ven) e ora ∈ [08:00, 18:00), altrimenti 0.

**4.5 Fasce costo orario**
Matrice `cm_rate_bands` × (`cost_type` ∈ {Aziendale, Cliente, Commerciale}) ×
(`regime` ∈ {Ordinario, Reperibilità}). Ogni variazione tariffa è storicizzata in
`cm_rate_band_history`. Fallback Reperibilità→Ordinario se la tariffa dedicata è 0.

### 5. Relazioni tra viste
- `manage_projects.php` → elenco/creazione commesse → link a `project_dashboard.php?id=…`
- `project_dashboard.php` → 5 tab: Anagrafica, Effort Presales, Team, Redditività, Consuntivo
  - **Consuntivo (v1.7.80)**: elenco paginato (50/pagina) e filtrabile (testo libero su rapporto/
    ticket/tecnico/lavoro eseguito, approvato, reperibilità), **dettaglio completo espandibile** per
    riga (cliente, sede, riferimento, ticket, tipo, settore, inizio/fine, ore pian./qtà/diff./extra,
    importato vs calcolato per ricavo/costo/commerciale, batch di import, richiesta e lavoro eseguito)
    e **modifica** del rapporto. `ProjectModel::interventionsPaged()` / `intervention()`.
  - **Team (v1.7.80)**: colonne *Ore da rapporti*, *Rapporti* e *Costo su rapporti* affiancate alle ore
    allocate, con totali di riga; segnalazione dei tecnici presenti nei rapporti ma non in team.
- `manage_rate_bands.php` → matrice tariffe + storico
- `import_commesse.php` → UPSERT `cm_projects` su `project_code`
- `import_intervention_reports.php` → UPSERT `cm_intervention_reports` su `report_code`
- `ajax_locations.php` / `ajax_client_locations.php` → select a cascata sede

### 5-bis. Menu e permessi
**"Gestione Commesse" è una sezione indipendente di primo livello**, sia nel menu
(`MenuManager`, key `commesse`, icona `fa-file-invoice-dollar`, posizionata prima di
Amministrazione) sia nella matrice permessi (`manage_permissions.php`, gruppo omonimo),
allo stesso livello di *Brand & Partnership*, *Competenze & Formazione*, *Recruiting & Agenzie*.
È distinta dalla preesistente sezione *Progetti & Referenze* (modulo referenze, non correlato).

Voci di permesso del gruppo: `manage_projects.php`, `project_dashboard.php` (sub-route),
`manage_rate_bands.php`, `import_commesse.php`, `import_intervention_reports.php`.
Gli endpoint `ajax_locations.php` / `ajax_client_locations.php` non sono permessi indipendenti:
sono protetti dal permesso di `manage_projects.php` e non compaiono in matrice.

**Sezioni comprimibili (UX permessi)**: ogni intestazione di gruppo, in entrambe le tabelle
(per ruolo e override per utente), è cliccabile e comprime/espande le proprie righe
(`tr.perm-sec` / `tr.perm-row` con `data-sec`). Sono presenti chevron di stato, contatore
sintetico per gruppo ("n/m attive" per ruolo, "n override" per utente) e i comandi
*Espandi tutte / Comprimi tutte*. Lo stato dei gruppi compressi è persistito lato client
(`localStorage`, chiave `pm_perm_collapsed`) e non influisce sul salvataggio: le righe
nascoste restano nel form e mantengono i propri valori.

### 5-ter. Dimensionamento colonne (v1.7.80)
Le lunghezze di `cm_intervention_reports` sono state verificate sui dati reali (66.845 righe) e
allargate con margine: `ticket` 80→**500** (max reale 234: causava *"Data too long for column
'ticket'"* e l'interruzione dell'import), `report_code` 60→80, `project_name_raw`/`client_raw`/
`site_raw`/`client_reference` →255, `technician_raw` →200, `tech_sector` →150, `band_raw`/
`service_type` →80. L'importer applica inoltre un **clamp difensivo**: un valore fuori misura viene
troncato e conteggiato (riportato nell'esito) invece di far fallire l'intero import.

### 5-quater. Controllo & Riconciliazione import (v1.7.80)
Un import di consuntivo lascia inevitabilmente righe non risolte: codici commessa non ancora
importati, tecnici non presenti in anagrafica, fasce con denominazioni diverse. La sezione
`import_control.php` le rende lavorabili.

**Principio: si lavora sui valori distinti, non sulle righe.** Su dati reali, 66.822 righe senza
tecnico corrispondono a soli **145 valori distinti**: l'operatore mappa quelli.

**Alias persistenti** (`cm_alias_project`, `cm_alias_technician`, `cm_alias_band`): la decisione presa
una volta vale per sempre. `AliasStore` è consultato **prima** dell'euristica da
`ProjectModel::resolveTechnician()`, `ProjectModel::resolveProjectId()` e `RateResolver::bandIdByName()`,
quindi gli import successivi risolvono da soli. `ignored = 1` marca un valore volutamente non
mappabile: resta tracciato ma esce dalla lista di lavoro.

**Flusso**
1. Riepilogo: rapporti totali e non risolti per commessa/tecnico/fascia, con percentuali.
2. Elenco anomalie raggruppate per valore grezzo, con occorrenze, periodo e **suggerimento
   automatico** per similarità (`similar_text`, soglia 60%), preselezionato nella tendina.
3. **Export XLSX** (`XlsxWriter`): tre fogli di anomalie (colonne `valore_grezzo`, `occorrenze`,
   `dal`, `al`, `suggerimento`, `suggerimento_id`, `mappa_a_id`, `ignora`) più tre fogli di
   riferimento con gli ID validi da usare.
4. **Reimport del file compilato**: crea/aggiorna gli alias e riapplica.
5. **Riapplicazione massiva** (`cm_reapply()`): set-based, senza reimportare l'export originale —
   `UPDATE … JOIN` su codice/nome esatto e poi su alias, per commessa, tecnico e fascia; infine
   ricalcolo di `client_revenue_calc`/`company_cost_calc`/`commercial_cost_calc` con la stessa
   regola di `RateResolver` (Reperibilità con fallback su Ordinario se tariffa a zero).

I valori economici importati non vengono mai toccati: restano la fonte di verità.

### 5-quinquies. Consuntivo e Team (v1.7.80)
**Modifica del rapporto.** Campi modificabili: data/inizio/fine, commessa (riassegnazione), tecnico,
fascia, cliente e sede (select a cascata via `ajax_client_locations.php`), tipo servizio, settore,
ticket, riferimento cliente, ore (pianificato/quantità/diff./extra), ricavo e costo importati,
flag approvato/da remoto/reperibilità, richiesta e lavoro eseguito. Al salvataggio i tre campi
`*_calc` sono **ricalcolati** con `RateResolver` (fascia × ore × regime, Reperibilità con fallback su
Ordinario). `in_working_hours` resta una colonna generata e si aggiorna da sé. I valori **importati**
non vengono mai sovrascritti dal ricalcolo: restano la fonte di verità. Modifica soggetta a
`can('edit','project_dashboard.php')`, con `Csrf::verify()`, pattern PRG e `write_log()`.

**Popolamento del team dai rapporti.** `ProjectModel::syncTeamFromReports()` è set-based:
`INSERT … SELECT … GROUP BY technician_id` sui rapporti della commessa, con
`ON DUPLICATE KEY UPDATE allocated_hours = IF(cm_team.source='Rapporti', VALUES(allocated_hours), cm_team.allocated_hours)`.
La colonna `cm_team.source` (`Manuale`|`Rapporti`) garantisce che le righe inserite a mano — ore e ruolo —
**non vengano mai sovrascritte**; l'operazione è idempotente. L'inserimento manuale resta disponibile e
`techniciansMissingFromTeam()` segnala chi lavora sulla commessa ma non è ancora in team.

### 5-sexies. Timesheet e Gantt (v1.7.80)
**Timesheet** (`timesheet.php`, classe `app/Timesheet.php`). Griglia dipendente × giorno del mese che
unisce due fonti: le ore **consuntivate dai rapporti** di intervento (`quantity_hours` per
`technician_id`/giorno, sola lettura — la verità resta l'import) e le **voci manuali**
(`cm_timesheet_entries`) per ciò che non transita dai rapporti (Ferie, Permesso, Formazione, Trasferta,
Malattia, ecc.). La saturazione è calcolata sulle ore attese = giorni feriali (lun-ven) × `ts_daily_hours`
(setting, default 8). Filtri per commessa/dipendente/mese, celle cliccabili con dettaglio giornaliero
(rapporti + voci), inserimento/eliminazione voci (PRG, CSRF, audit) ed export XLSX del cartellino.

**Gantt** (`app/Gantt.php`, rendering HTML/CSS senza dipendenze né JavaScript, stampabile). Barre
posizionate in percentuale sull'intervallo temporale complessivo (`Gantt::scale()`/`bar()`/`ticks()`).
Due viste:
- *Portfolio* (`project_gantt.php`): una barra per commessa, **pianificato** (`start_date`→`end_date`)
  sopra ed **effettivo** dai rapporti (primo→ultimo `report_date`) sotto; la barra effettiva diventa
  rossa se sfora la fine pianificata. Filtri per stato, azienda, testo.
- *Dettaglio commessa* (tab **Gantt** in `project_dashboard.php`): pianificato vs effettivo della
  commessa, **fasi** (`cm_project_phases`, con % di avanzamento resa come riempimento della barra),
  barre effettive per singola risorsa e istogramma del **carico mensile**. Gestione fasi
  (crea/modifica/elimina) inline, soggetta a `can('edit')`.

### 5-septies. Console di sistema (v1.7.80)
`system_console.php` riunisce a schede le tre pagine di manutenzione, prima separate, più la
visibilità dei log:
- **Aggiornamento (ZIP)** — analisi e applicazione dei pacchetti di release (ex `system_update.php`).
- **Migrazioni DB** — stato delle chiavi di versione (`app_version`/`schema_version`/`release_label`
  confrontate col codice installato) e accesso al motore di upgrade (`db_upgrade.php`).
- **SQL Runner** — upload/incolla/scelta da `/sql/`, anteprima con conteggio statement e marcatura di
  quelli potenzialmente distruttivi, esecuzione con esito per statement (ex `sql_runner.php`).
- **Log** — `app_logs` filtrabili per categoria, livello e testo, con paginazione.

**Nessuna duplicazione di logica.** Le funzioni collaudate sono state estratte in moduli condivisi e
riusate sia dalla console sia dalle pagine originali: `app/UpdaterCore.php` (`analyze_zip`,
`apply_update`) e `app/SqlConsole.php` (`sql_split_statements` comment/quote-aware, `sql_classify`).
Il motore di migrazione schema resta in `db_upgrade.php` (con backup pre-upgrade, sequenza
`pm_version_order()` e guardia anti-regressione) ed è richiamato dalla scheda Migrazioni DB.
`system_update.php` e `sql_runner.php` restano come **redirect** alla scheda corrispondente per non
rompere menu, bookmark e link interni. Accesso riservato al Super Admin (ruolo 1).

### 5-octies. Carico risorse e sovrapposizioni (v1.7.80)
`workload_overview.php` (classe `app/Workload.php`) ricostruisce dai rapporti di intervento
consuntivati l'impegno di ogni persona su ogni commessa a granularità **mensile**, e ne deriva le
sovrapposizioni. Capacità mensile = giorni feriali × `ts_daily_hours` (stesso parametro del timesheet).

Tre viste sullo stesso periodo:
1. **Heatmap persona × mese** (`Workload::matrix()`): ore impegnate, colore per fascia di saturazione
   (<60 / 60-90 / 90-110 / >110%), marcatore ⚠ quando nel mese ci sono ≥2 commesse; clic sulla cella →
   ripartizione per commessa.
2. **Conflitti per risorsa** (`personOverlaps()`): mesi in cui una persona lavora su più commesse in
   parallelo e/o supera la capacità (**sovraccarico**), ordinati per gravità.
3. **Sovrapposizioni tra commesse** (`projectOverlaps()`): coppie di commesse che condividono le stesse
   risorse negli stessi mesi (contesa). Dalla v1.7.80 rispetta l'intero set di filtri (periodo, commessa,
   risorse) e riporta la **fascia temporale** effettiva della sovrapposizione (primo → ultimo mese in
   comune, ricalcolati sul periodo), il numero di mesi, le risorse condivise e le **ore per ciascuna
   commessa** più il totale in contesa.

`Workload::matrix()` accetta ora una selezione multipla di risorse (`employee_ids`) e una modalità di
ordinamento (`sort`: `hours_desc` default, `hours_asc`, `name`). `chartSeries()`/`capacitySeries()`
producono i dati per il **grafico SVG** del carico mensile per risorsa (una polilinea per persona più la
linea tratteggiata della capacità), renderizzato server-side senza dipendenze JavaScript. La pagina
espone: legenda descrittiva delle fasce di saturazione, selettore di ordinamento, filtro multi-risorsa
(select `multiple`) e il grafico; con oltre 12 risorse il grafico mostra le prime 12 per monte ore,
invitando a restringere con il filtro.

`byProject()` fornisce inoltre l'impegno sintetico per commessa (risorse, mesi, ore, intervallo).
La vista è di sola lettura e non introduce tabelle: deriva interamente dai dati già importati; l'export
XLSX produce heatmap, capacità mensile e conflitti su fogli separati.

### 5-novies. Cestino / ripristino record (v1.7.80)
`recycle_bin.php` (classe `app/RecycleBin.php`, tabella `cm_deleted_records`) protegge da cancellazioni
accidentali. Il metodo `softDelete($table,$where,$args,$label,$userId,$context)` sostituisce un `DELETE`
diretto: legge le righe interessate, ne archivia lo stato completo in JSON (`payload`) con metadati
(tabella, colonna e valore di PK, autore, data, contesto), poi esegue la cancellazione. Solo le tabelle
in whitelist (`cm_team`, `cm_project_phases`, `cm_timesheet_entries`, `cm_intervention_reports`,
`cm_projects`, `cm_presales_effort`, `cm_alias_*`) vengono archiviate; per le altre la delete resta
diretta.

`restore($binId)` re-inserisce la riga con la **PK originale**; se esiste già un record con quella
chiave (PK riutilizzata) il ripristino viene rifiutato per non sovrascrivere dati, segnalando il
conflitto. `purge()`/`purgeOlderThan($days)` eliminano definitivamente. `listItems()` fornisce la vista
filtrabile (tipo, stato, testo) con il nome di chi ha eliminato (da `employees` via `users.employee_id`,
fallback `display_name`/email). Nella v1.7.80 sono agganciati al cestino i delete di membri team e fasi
(`project_dashboard.php`) e delle voci timesheet (`timesheet.php`). Accesso riservato al Super Admin;
ripristino gated da `can('edit'/'create')`, eliminazione definitiva da `can('delete')`.

### 5-novies. Cestino esteso a tutto il portale (v1.7.80)
Il meccanismo di soft-delete di `app/RecycleBin.php` copre ora **ogni record del portale**, non solo il
modulo Gestione Commesse. La logica di ammissione è passata da una whitelist a una **denylist** di sole
tabelle di sistema/infrastruttura da non intercettare mai: il cestino stesso (`cm_deleted_records`),
i log (`app_logs`), la configurazione (`app_settings`), i permessi e le preferenze riscritti in blocco
(`role_permissions`, `user_permissions`, `menu_preferences`) e le tabelle gestite con pattern
"cancella-e-reinserisci" durante le sincronizzazioni (`employee_credly_link`, `employee_linkedin_link`).
La **chiave primaria** viene rilevata automaticamente da `information_schema` (con cache per tabella),
così il ripristino funziona anche per tabelle con PK non standard. Le cancellazioni reali dei sotto-record
del dipendente (lingue, titoli di studio, esperienze, dotazioni: telefono/SIM/notebook/veicolo/carte,
rifornimenti, estratti conto), delle certificazioni (`user_certifications`) e dei reparti
(`departments`) passano ora da `RecycleBin::capture()`. I delete di sincronizzazione e le riscritture di
configurazione restano diretti, per non intasare il cestino con operazioni non accidentali.

### 5-decies. Import Commesse DB (v1.7.80)
`import_commesse_db.php` affianca l'import XLSX importando l'**export nativo del gestionale** (tabella
`contract`): un CSV con separatore `|`, quoting `"` e terminatore CRLF, letto con un parser nativo in
streaming (`fgetcsv`), quindi indipendente dalla dimensione del file. La mappatura verso `cm_projects`:
`code`→`project_code` (chiave UPSERT), `name`→`name`, `customer_comp_name`→cliente (upsert in anagrafica),
`status` OPEN/CLOSED/SUSPENDED→`operational_status` Aperta/Chiusa/Sospesa, `economic_value`/`_till_now`→
`value_total`/`value_todate`, `margin_value`/`_till_now`→`margin_total`/`margin_todate`,
`residual_value`/`_till_now`→`residual_total`/`residual_todate`, `time_material_costs`→`actual_cost`,
`start_date`/`end_date` (troncate a data), `n_ano_open`/`n_ano_open_block`→anomalie. L'azienda esecutrice
è derivata dal prefisso del codice (`PrefixResolver`). UPSERT su `project_code` → ri-eseguibile senza
duplicati; le righe con `deleted=1` sono saltate salvo opt-in. Ogni import registra un `cm_import_batches`
di tipo `commesse`. I codici combaciano con i `project_code` dei rapporti già importati, così le commesse
si agganciano ai consuntivi esistenti.

### 6. Sicurezza
- `can('view'/'create'/'edit', 'pagina.php')` su ogni pagina ed endpoint AJAX.
- `Csrf::verify()` + `csrf_field()` su tutte le form; pattern PRG (POST prima di `header.php`).
- Audit via `write_log()`; storicizzazione tariffe e import batch tracciati.
- Parser XLSX nativo (`ZipArchive`+`XMLReader`), nessuna dipendenza esterna.
- **Import in streaming (v1.7.80)**: `XlsxReader::each($path, $cb)` legge il foglio a flusso
  sullo stream `zip://` con `XMLReader`; memoria costante rispetto alla dimensione del file
  (misurato: 150.000 righe / 287 MB di XML → picco 2 MB). `XlsxReader::read()` resta per
  compatibilità. Gli importer elaborano riga-per-riga con commit ogni 500 record.
- **`UploadGuard` (v1.7.80)**: se il body POST eccede `post_max_size`, PHP lo scarta e `$_POST`
  arriva vuoto; la verifica CSRF fallirebbe restituendo un fuorviante *403 — Token non valido*.
  La guardia intercetta la condizione prima di `Csrf::verify()` e riporta dimensione inviata,
  limite attivo e rimedi. Traduce inoltre i codici `UPLOAD_ERR_*` in messaggi leggibili.

### 7. Idempotenza e sistema di aggiornamento
Tutte le DDL sono `CREATE TABLE IF NOT EXISTS`; la FK `employees.department_id` usa
`FOREIGN KEY IF NOT EXISTS`; i seed sono `INSERT IGNORE` / `INSERT … SELECT` /
`ON DUPLICATE KEY UPDATE`.

**Aggiornamento in fase unica.** Lo ZIP contiene i file PHP completi già patchati e **un solo
SQL nella root**: `upgrade_1_7_56_to_1_7_80.sql`, consolidamento idempotente delle release
**1.7.56 → 1.7.80**. `system_update.php` estrae i file (sovrascrittura) ed esegue quell'unico
script nella stessa fase; non serve applicare le migration in sequenza.

| Punto di partenza | Effetto del consolidato |
|---|---|
| 1.7.56 | applica 1.7.57 (no-schema), 1.7.58, 1.7.59 |
| 1.7.57 | applica 1.7.58, 1.7.59 |
| 1.7.58 | applica 1.7.59 (bump) e 1.7.60 |
| 1.7.59 | applica 1.7.60 (fix collisione + cleanup) e 1.7.61 |
| 1.7.60 | applica 1.7.61 e 1.7.62 |
| 1.7.61 | applica 1.7.62 e 1.7.63 |
| 1.7.62 | applica 1.7.63 e 1.7.64 |
| 1.7.63 | applica 1.7.64 e 1.7.65 |
| 1.7.64 | applica 1.7.65 e 1.7.66 |
| 1.7.65 | applica 1.7.66 e 1.7.67 |
| 1.7.66 | applica 1.7.67 e 1.7.68 |
| 1.7.67 | applica 1.7.68 e 1.7.69 |
| 1.7.68 | applica 1.7.69 e 1.7.70 |
| 1.7.69 | applica 1.7.70 e 1.7.71 |
| 1.7.70 | applica 1.7.71 e 1.7.72 |
| 1.7.71 | applica 1.7.72 e 1.7.73 |
| 1.7.72 | applica 1.7.73 e 1.7.74 |
| 1.7.73 | applica 1.7.74 e 1.7.75 |
| 1.7.74 | applica 1.7.75 e 1.7.76 |
| 1.7.75 | applica 1.7.76 e 1.7.77 |
| 1.7.76 | applica 1.7.77 e 1.7.78 |
| 1.7.77 | applica 1.7.78 e 1.7.79 |
| 1.7.78 | applica 1.7.79 e 1.7.80 |
| 1.7.79 | applica 1.7.80 (import commesse DB) |
| 1.7.59 pre-fix | rimuove le tabelle collidenti e ricrea il modulo come `cm_*` |

In `sql/` restano le migration per-versione (`migration_v1_7_56/…/65.sql`, usate da
`db_upgrade.php`) e la copia commentata del consolidato
(`migration_v1_7_56_to_1_7_80.sql`, per SQL Runner).

Verifica eseguita su MariaDB con DB che replica quello di produzione (con `projects`/`clients`
preesistenti popolati e tabelle 1.7.59 pre-fix): doppia esecuzione senza errori, dati dei moduli
preesistenti invariati.
