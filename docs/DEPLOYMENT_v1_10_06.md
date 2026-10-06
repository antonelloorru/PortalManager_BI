# Deployment v1.10.06

Prerequisito: v1.10.05 installata.
1. Backup DB e cartella applicazione.
2. Estrarre `update_v1.10.06.zip` nella root (Sistema › Aggiornamento, o
   `Expand-Archive update_v1.10.06.zip -DestinationPath P:\xampp\htdocs\demo_portalmanager -Force`).
3. Eseguire `sql/migration_v1_10_06.sql` (SQL Runner o phpMyAdmin). Idempotente.
4. php.ini: `upload_max_filesize = 32M`, `post_max_size = 32M` (export XLSX di qualche MB). Stop+Start Apache, Ctrl+F5.
5. Gestione Commesse › Service SOC › Ingestion: importare l'export oppure configurare il DB SOC (Super Admin).
6. Facoltativo: pianificare `cron_soc_sync.php` (Manuale Amministratore).
7. `php tools/verify_v1_10_06.php --db=<database> [--file=<export.xlsx>]` → nessun KO.

File: VERSION, app/Version.php, app/SocIngest.php, app/SocModel.php, app/soc_ticket_table.php, app/Router.php, app/MenuManager.php,
app/PermissionCatalog.php, manage_permissions.php, service_soc.php, cron_soc_sync.php, wp_ats_sync.php, sql/migration_v1_10_06.sql,
tools/verify_v1_10_06.php, docs/*_v1_10_06.md, update_manifest.json.
Rollback: file v1.10.05; le tabelle `cm_soc_*` possono restare.
