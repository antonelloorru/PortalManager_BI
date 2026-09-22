# Deployment — PortalManager v1.8.40

Ambiente di riferimento: XAMPP per Windows · PHP 8.2 · Apache 2.4.58 ·
MariaDB 10.4.32 · path `P:\xampp\htdocs\demo_portalmanager` · DB `demo_portalmanager`.

## 1. Contenuto del pacchetto

```
VERSION                              1.8.40
manage_projects.php                  (ROOT)  elenco commesse — 29 colonne standard
project_gantt.php                    (ROOT)  indicatore record allineato
workload_overview.php                (ROOT)  indicatore record allineato
app/ProjectModel.php                         listAll() esteso + nuovi filtri
app/Version.php                              PM_VERSION = 1.8.40
sql/migration_v1_8_40.sql                    migration della singola release
sql/upgrade_1_7_56_to_1_8_40.sql             consolidato cumulativo (252 statement)
docs/                                        questa documentazione
```

Tutti i file PHP sono **completi e già patchati**: si sovrascrivono, non si applicano diff.

## 2. Backup preliminare (obbligatorio)

1. Esportare il DB da phpMyAdmin (`demo_portalmanager` → Esporta → SQL).
2. Copiare la cartella `demo_portalmanager` o almeno i quattro file PHP sostituiti.

## 3. Aggiornamento

1. Accedere come **Super Admin** → `system_console.php` → tab **Aggiornamento**.
2. Caricare lo ZIP oppure estrarlo manualmente su
   `P:\xampp\htdocs\demo_portalmanager`, **rispettando i percorsi**:
   - i tre file di ROOT vanno nella radice del portale;
   - `ProjectModel.php` e `Version.php` vanno in `app\`.
3. Eseguire la migration nel **SQL Runner**:
   - aggiornamento da v1.8.39 → `sql/migration_v1_8_40.sql`;
   - installazione da v1.7.56 o versione incerta → `sql/upgrade_1_7_56_to_1_8_40.sql`
     (cumulativo e idempotente: eseguibile più volte senza effetti collaterali).
4. **Stop + Start Apache** dal pannello di controllo XAMPP.
5. **Ctrl+F5** sul browser per invalidare la cache.

## 4. Verifica post-deploy

| Controllo | Esito atteso |
|---|---|
| Footer del portale | mostra `1.8.40` |
| `system_console.php` → versioni | `app_version` = `schema_version` = `1.8.40` |
| Menu → Gestione Commesse → Commesse / Progetti | tabella con 29 colonne standard, scorrimento orizzontale |
| Intestazione della pagina | indicatore `N / M commesse` |
| Filtri → Applica | il numeratore cambia, compare "(filtrate)" |
| Esporta → XLSX | file `lista_commesse_<timestamp>.xlsx` che si apre in Excel, foglio "Lista commesse", 29 colonne |
| Esporta → CSV | separatore `;`, UTF-8 con BOM, stessi 29 header |
| Menu → Gantt commesse | indicatore `N / M commesse` |
| Menu → Carico risorse | indicatore `N / M commesse` in intestazione |

## 5. Popolamento dei dati

Se le colonne economiche risultano vuote, i record presenti provengono dalla sola
sincronizzazione DGB (che valorizza `project_code`, `name`, `external_link`).
Per popolarle:

**Gestione Commesse → Import Commesse** → caricare `export_lista_commesse.xlsx`.

L'import esegue UPSERT su `project_code`: le commesse già presenti vengono
arricchite, le nuove create. L'operazione è ripetibile.

## 6. Rollback

1. Ripristinare i quattro file PHP dal backup.
2. La migration non è distruttiva (solo `ADD COLUMN/INDEX IF NOT EXISTS` e un
   `INSERT ... ON DUPLICATE KEY UPDATE` su `app_settings`): non richiede rollback
   di schema. Per riportare l'etichetta di versione:

```sql
UPDATE app_settings SET setting_value='1.8.39'
 WHERE setting_key IN ('app_version','schema_version','release_label');
```

3. Stop + Start Apache, Ctrl+F5.
