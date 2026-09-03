# PortalManager — Guida al Deployment v1.8.4
## Aggiornamento a v1.8.4
Da **v1.8.3** già applicata: eseguire solo `sql/migration_v1_8_4.sql` (solo allineamento versione; la modifica è applicativa).
Da **v1.8.0/1.8.1**: eseguire in ordine le migrazioni successive fino a `migration_v1_8_3.sql`.
Da **v1.7.99** o precedenti: eseguire in ordine `migration_v1_8_0.sql`, `migration_v1_8_1.sql`, `migration_v1_8_2.sql`, `migration_v1_8_3.sql`, `migration_v1_8_4.sql`, oppure il consolidato `upgrade_1_7_56_to_1_8_4.sql`.

Ambiente di riferimento: Windows + XAMPP (Apache 2.4.58, PHP 8.2.12, MariaDB 10.4.32,
phpMyAdmin 5.2.1). Percorso applicazione: `P:\xampp\htdocs\portalmanager`.

### 0. Prerequisiti
- Backup completo **prima** di procedere: cartella applicazione + dump del database
  (`system_backup.php` oppure `mysqldump`).
- Utenza con permessi di scrittura su `P:\xampp\htdocs\portalmanager`.

### 1. Contenuto del pacchetto `PortalManager_v1.8.4.zip`
```
app/CostModel.php                 (modificato — calcolo anno-aware)
app/MenuManager.php               (modificato — voci menu)
app/Version.php                   (modificato — PM_VERSION 1.8.4)
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
app/Workload.php                  (modificato - metodi giornalieri)
workload_overview.php             (modificato - grafico giornaliero)
app/Gantt.php                     (modificato - helper rendering)
project_gantt.php                 (modificato - Gantt portfolio ridisegnato)
project_dashboard.php             (modificato - tab Gantt ridisegnato)
app/ProjectModel.php              (modificato - listAll filtri/colonne)
manage_projects.php               (modificato - filtri ed export XLSX/CSV)
upgrade_1_7_56_to_1_8_4.sql       (consolidato root per system_update)
VERSION                           (1.8.4)
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
Expand-Archive -Path .\PortalManager_v1.8.4.zip -DestinationPath P:\xampp\htdocs\portalmanager -Force
```
`-Force` sovrascrive i file modificati. I file nuovi vengono aggiunti. Nessun file
esistente viene eliminato.

### 3. Migrazione del database
Opzione consigliata (dalla UI, come amministratore):
1. Accedere al portale e aprire **Sistema → Console di sistema** (o **SQL Runner**).
2. Eseguire `sql/migration_v1_8_0.sql` e `sql/migration_v1_8_1.sql` (idempotenti, ri-eseguibili). Se già in 1.8.0, basta 1.8.1.
3. In alternativa, **Aggiorna sistema** (`system_update.php`) applica il consolidato
   `upgrade_1_7_56_to_1_8_4.sql`.

Opzione manuale (phpMyAdmin): importare `sql/migration_v1_8_0.sql` sul database del portale.

Effetti della migrazione:
- crea `hr_economic_years` (seed esercizio 2025 corrente) e `hr_employee_economics`;
- migra i dati economici esistenti come esercizio 2025 (dipendenti con dati valorizzati);
- aggiunge la colonna `year` a `hr_reference_values`/`hr_reference_history` (retro-riempita a 2025)
  e sposta la chiave UNIQUE su `ref_key`+`year`;
- registra i permessi delle nuove pagine per i ruoli 1, 2, 10;
- porta `app_version`/`schema_version`/`release_label` a `1.8.0`.

### 4. Verifica post-aggiornamento
- Footer/Impostazioni: versione **1.8.4**.
- **Amministrazione → Annualità economiche**: presente l'esercizio **2025 (corrente)**.
- **Finance**: selettore anno presente; i valori 2025 coincidono con quelli pre-aggiornamento.
- **Confronto annualità** e **Import dati economici** accessibili al ruolo **Finance** (oltre a HR e Amministratore).
- **Aggiornamento DB** (`db_upgrade.php`): il controllo integrità non segnala elementi mancanti (0 su tutti gli step).
- **Console/SQL Runner**: ri-eseguendo la migrazione non si producono errori (idempotenza).

### 5. Rollback
Ripristinare il backup della cartella applicazione e il dump del database precedenti
all'aggiornamento. Le nuove tabelle e la colonna `year` sono additive: in caso di
ripristino del solo codice, lo schema 1.8.0 resta compatibile con la 1.7.99.

### 6. Compatibilità stack
Testato su PHP 8.2/8.3 e MariaDB 10.4/10.11. Le DDL idempotenti
(`ADD COLUMN IF NOT EXISTS`, `DROP INDEX IF EXISTS`, `ADD UNIQUE KEY IF NOT EXISTS`)
sono supportate da MariaDB 10.4+.
