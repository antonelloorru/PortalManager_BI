# PortalManager — Guida al Deployment v1.8.19
## Aggiornamento a v1.8.19
Da **v1.8.11** già applicata: eseguire `sql/migration_v1_8_19.sql` (re-seed permessi DGB + bump; nessuna variazione di schema). NB (precedente): eseguire `sql/migration_v1_8_11.sql` (aggiunge `cm_projects.dgb_contract_id`, popola la mappatura da external_link e crea la vista `vw_dgb_commessa_rollup`). Aggiornare anche `app/DgbModel.php`, `project_dashboard.php`, `manage_projects.php`, `dgb_activities.php`.
Da **v1.8.0/1.8.1**: eseguire in ordine le migrazioni successive fino a `migration_v1_8_3.sql`.
Da **v1.7.99** o precedenti: eseguire in ordine `migration_v1_8_0.sql`, `migration_v1_8_1.sql`, `migration_v1_8_2.sql`, `migration_v1_8_3.sql`, `migration_v1_8_4.sql`, `migration_v1_8_5.sql`, `migration_v1_8_6.sql`, `migration_v1_8_19.sql`, oppure il consolidato `sql/upgrade_1_7_56_to_1_8_19.sql`.

Ambiente di riferimento: Windows + XAMPP (Apache 2.4.58, PHP 8.2.12, MariaDB 10.4.32,
phpMyAdmin 5.2.1). Percorso applicazione: `P:\xampp\htdocs\portalmanager`.

### 0. Prerequisiti
- Backup completo **prima** di procedere: cartella applicazione + dump del database
  (`system_backup.php` oppure `mysqldump`).
- Utenza con permessi di scrittura su `P:\xampp\htdocs\portalmanager`.

### 1. Contenuto del pacchetto `PortalManager_v1.8.19.zip`
```
app/CostModel.php                 (modificato — calcolo anno-aware)
app/MenuManager.php               (modificato — voci menu)
app/Version.php                   (modificato — PM_VERSION 1.8.19)
employee_compensation.php         (modificato — selettore anno)
finance_overview.php              (modificato — selettore anno)
manage_permissions.php            (modificato — nuove pagine in page_map)
db_upgrade.php                    (modificato — registrazione 1.8.0)
hr_economic_years.php             (nuovo)
finance_compare.php               (nuovo)
import_economics_xlsx.php         (nuovo)
sql/migration_v1_8_0.sql          (dati economici per anno)
sql/migration_v1_8_1.sql          (fix permessi ruolo Finance)
sql/migration_v1_8_2.sql          (vista giornaliera carico - bump versione)
sql/migration_v1_8_3.sql          (redesign Gantt - bump versione)
sql/migration_v1_8_4.sql          (filtri/export commesse - bump versione)
sql/migration_v1_8_5.sql          (Report & Avanzamento + Workflow: 3 tabelle)
app/ProjectWorkflow.php           (nuovo - helper report/workflow)
sql/migration_v1_8_6.sql          (filtri carico estesi - bump versione)
app/Workload.php                  (modificato - filtri societa/cliente/stato/tipologia)
workload_overview.php             (modificato - nuovi filtri UI)
sql/migration_v1_8_7.sql          (hotfix warning db_upgrade - bump versione)
sql/migration_v1_8_8.sql          (modulo DGB: 5 tabelle + log + 4 viste + permessi)
app/DgbImporter.php               (nuovo - ingestion batch + diff)
app/DgbModel.php                  (nuovo - KPI/tabella/grafici/anomalie)
dgb_activities.php                (nuovo - pagina Attivita & Rendicontazione DGB)
dgb_api.php                       (nuovo - endpoint JSON parametrizzato)
app/MenuManager.php               (modificato - voce menu DGB)
manage_permissions.php            (modificato - page_map DGB)
db_upgrade.php                    (modificato - metadata + UPGRADE_SQL 1.8.8)
sql/migration_v1_8_9.sql          (DGB orario/carico + distribuzione - bump versione)
app/DgbModel.php                  (modificato - breakdown orario, distribuzione, filtri)
dgb_activities.php                (modificato - Orario & Carico, grafico distribuzione, export)
dgb_api.php                       (modificato - azioni hours/distribution)
sql/migration_v1_8_10.sql         (hotfix updater backup - bump versione)
app/UpdaterCore.php               (modificato - backup DB in streaming memory-safe)
sql/migration_v1_8_11.sql         (riconciliazione DGB<->commesse: colonna + vista)
app/DgbModel.php                  (modificato - rollup/riconciliazione commessa)
project_dashboard.php             (modificato - tab DGB nella scheda commessa)
manage_projects.php               (modificato - colonna DGB in elenco)
dgb_activities.php                (modificato - filtro/colonna commessa)
app/Workload.php                  (modificato - metodi giornalieri)
workload_overview.php             (modificato - grafico giornaliero)
app/Gantt.php                     (modificato - helper rendering)
project_gantt.php                 (modificato - Gantt portfolio ridisegnato)
project_dashboard.php             (modificato - tab Gantt ridisegnato)
app/ProjectModel.php              (modificato - listAll filtri/colonne)
manage_projects.php               (modificato - filtri ed export XLSX/CSV)
project_dashboard.php             (modificato - tab Report & Avanzamento)
sql/upgrade_1_7_56_to_1_8_19.sql       (consolidato root per system_update)
VERSION                           (1.8.12)
CHANGELOG.md
docs/TECHNICAL_DESIGN_v1.8.0.md
docs/MANUALE_ADMIN_v1.8.0.md
docs/MANUALE_UTENTE_v1.8.0.md
docs/DEPLOYMENT_v1.8.0.md
RELEASE_CHECKLIST_v1.8.0.md
```

### 2. Copia dei file (PowerShell)
```powershell
# dalla cartella che contiene il pacchetto
Expand-Archive -Path .\PortalManager_v1.8.19.zip -DestinationPath P:\xampp\htdocs\portalmanager -Force
```
`-Force` sovrascrive i file modificati. I file nuovi vengono aggiunti. Nessun file
esistente viene eliminato.

### 3. Migrazione del database
Opzione consigliata (dalla UI, come amministratore):
1. Accedere al portale e aprire **Sistema → Console di sistema** (o **SQL Runner**).
2. Eseguire `sql/migration_v1_8_0.sql` e `sql/migration_v1_8_1.sql` (idempotenti, ri-eseguibili). Se già in 1.8.0, basta 1.8.1.
3. In alternativa, **Aggiorna sistema** (`system_update.php`) applica il consolidato
   `sql/upgrade_1_7_56_to_1_8_19.sql`.

Opzione manuale (phpMyAdmin): importare `sql/migration_v1_8_0.sql` sul database del portale.

Effetti della migrazione:
- crea `hr_economic_years` (seed esercizio 2025 corrente) e `hr_employee_economics`;
- migra i dati economici esistenti come esercizio 2025 (dipendenti con dati valorizzati);
- aggiunge la colonna `year` a `hr_reference_values`/`hr_reference_history` (retro-riempita a 2025)
  e sposta la chiave UNIQUE su `ref_key`+`year`;
- registra i permessi delle nuove pagine per i ruoli 1, 2, 10;
- porta `app_version`/`schema_version`/`release_label` a `1.8.0`.

### 4. Verifica post-aggiornamento
- Footer/Impostazioni: versione **1.8.19**.
- **Amministrazione → Annualità economiche**: presente l'esercizio **2025 (corrente)**.
- **Finance**: selettore anno presente; i valori 2025 coincidono con quelli pre-aggiornamento.
- **Confronto annualità** e **Import dati economici** accessibili al ruolo **Finance** (oltre a HR e Amministratore).
- **Aggiornamento DB** (`db_upgrade.php`): il controllo integrità non segnala elementi mancanti (0 su tutti gli step).
- **Console/SQL Runner**: ri-eseguendo la migrazione non si producono errori (idempotenza).

### 5. Rollback
Ripristinare il backup della cartella applicazione e il dump del database precedenti
all'aggiornamento. Le nuove tabelle e la colonna `year` sono additive: in caso di
ripristino del solo codice, lo schema 1.8.0 resta compatibile con la 1.7.99.

### 5-bis. Cartella allegati
Gli allegati dei report sono salvati in `uploads/commesse/<id>/`, creata automaticamente al primo caricamento. Assicurarsi che `uploads/` sia scrivibile dal web server.

### 6. Compatibilità stack
Testato su PHP 8.2/8.3 e MariaDB 10.4/10.11. Le DDL idempotenti
(`ADD COLUMN IF NOT EXISTS`, `DROP INDEX IF EXISTS`, `ADD UNIQUE KEY IF NOT EXISTS`)
sono supportate da MariaDB 10.4+.


## Note modulo DGB (v1.8.8)
- La cartella `uploads/dgb_tmp/` deve essere scrivibile dal web server (creata automaticamente all'import).
- Per l'import via web dei file grandi (es. allocations ~7 MB, forms_activity) verificare `upload_max_filesize` e `post_max_size` in `php.ini` (consigliato >= 32M) e `max_execution_time` adeguato.
- I 5 CSV sono riconosciuti dal nome file; i modelli attesi sono dgb_operator, dgb_operator_allocations_on_forms_contract, forms_activity_planning, forms_activity, forms_activity_has_dgb_operator.


## Sblocco errore memoria updater (v1.8.10)
Se l'aggiornamento fallisce con «Allowed memory size … exhausted in app/UpdaterCore.php» (versioni precedenti alla 1.8.10, tipico dopo l'import DGB), il crash avviene nel backup DB prima dell'estrazione. Sbloccare in uno dei due modi, poi rieseguire l'aggiornamento:
1. Copia manuale: estrarre dal pacchetto solo `app/UpdaterCore.php` e sovrascriverlo in `P:\\xampp\\htdocs\\demo_portalmanager\\app\\`, poi rilanciare l'aggiornamento (il backup ora è in streaming).
2. In alternativa: alzare temporaneamente `memory_limit` in `php.ini` (es. 1024M) e riavviare Apache, poi eseguire l'aggiornamento.
Nota: i backup DB includono le tabelle DGB e possono essere grandi (centinaia di MB); eliminare periodicamente i backup vecchi dalla cartella backup.


## Riconciliazione DGB (v1.8.11)
Il collegamento tra attività DGB e commesse native avviene tramite `cm_projects.dgb_contract_id`, dedotto da `external_link` (`.../contract/editV2/<id>`). La migration lo popola solo dove NULL: per rimappare una commessa manualmente, impostare `dgb_contract_id` a mano (non verrà sovrascritto ai successivi aggiornamenti).


## Struttura pacchetto (v1.8.12)
- I file `.sql` (migrazioni e consolidato `upgrade_1_7_56_to_1_8_19.sql`) sono in `sql/`.
- La documentazione `.md` (CHANGELOG, RELEASE_CHECKLIST, manuali, technical design, deployment) è in `docs/`.
- In root resta solo `VERSION`. L'updater preserva i path dello ZIP durante l'estrazione.

## Anonimizzazione URL (Router)
`app/Router.php` include ora nella whitelist `PAGES` le pagine Commesse/Finance/DGB (URL opachi via slug). Il file è ripartito dalla baseline con l'aggiunta di queste pagine: se in passato erano state aggiunte manualmente altre pagine a `Router::PAGES`, effettuare il merge dopo l'aggiornamento. L'endpoint `dgb_api.php` resta ad accesso diretto (come gli altri `api_*`).


## Sync DGB -> Commesse & classificazione (v1.8.13)
1. File modificati: `sql/migration_v1_8_19.sql`, `app/DgbSync.php` (nuovo), `app/DgbModel.php`, `dgb_activities.php`.
2. Dopo l'aggiornamento: Attivita & Rendicontazione DGB -> tab **Import** -> **Sincronizza ora** per popolare `cm_projects` e `cm_intervention_reports` (idempotente via `dgb_source_id`).
3. Tab **Incaricati** -> **Auto-classifica** per assegnare orario (ordinario/turni) e reperibilita; correzioni manuali + Salva profili.
4. `cm_intervention_reports.in_working_hours` e' colonna generata (da `start_at`): non va scritta.


## Hotfix import dati economici (v1.8.14)
File modificato: `import_economics_xlsx.php`. Se erano stati importati valori con decimali errati, rieseguire l'import del file (UPSERT idempotente per dipendente+anno).


## Anonimizzazione URL (v1.8.15)
File modificato: `app/Router.php` (whitelist PAGES estesa a tutte le voci di menu). Se Router.php era stato personalizzato manualmente, effettuare il merge. Regola: ogni nuova pagina di menu va aggiunta a Router::PAGES.


## Anonimizzazione (v1.8.16)
File modificato: `app/Router.php`. Le pagine Sistema/manutenzione tornano ad accesso per path esatto (non anonimizzate). Regola: le pagine ad alto privilegio/manutenzione non vanno mai in Router::PAGES; url()/isRoutable() escludono comunque i RESTRICTED.


## Export XLSX (v1.8.17)
File modificati: `XlsxWriter.php` e `app/XlsxWriter.php` (writer condiviso). Sovrascrivere entrambe le copie. Corregge tutti gli export XLSX (download() ora svuota i buffer e termina con exit).


## Filtri pagine anonimizzate (v1.8.18)
File modificati: `app/UrlHelper.php` + le pagine con filtri (finance_overview, finance_compare, workload_overview, manage_projects, dgb_activities, employee_compensation, import_economics_xlsx). Sovrascriverli tutti. Risolve il 404 al submit dei filtri quando le pretty-URL sono disattivate.


## Finance / Compensation (v1.8.19)
File modificati: `finance_overview.php` (export con colonna anno) e `employee_compensation.php` (creazione nuovo anno di competenza + link a Finance). Sovrascriverli entrambi.
