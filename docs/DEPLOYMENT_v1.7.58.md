# Deployment — v1.7.58 (installazione manuale)

## Prerequisiti
Apache 2.4.58, PHP 8.2.12 (compat 8.1/8.0), MariaDB 10.4.32. Backup DB + files prima di procedere.

## 1. Backup
```powershell
mysqldump -u root -p portalmanager > backup_pre_1.7.58.sql
Copy-Item -Recurse P:\xampp\htdocs\portalmanager P:\backup\portalmanager_pre_1.7.58
```

## 2. Estrazione (file già patchati, drop-in)
```powershell
Expand-Archive -Path .\portalmanager_v1.7.58.zip -DestinationPath P:\xampp\htdocs\portalmanager -Force
```
Sovrascrive: `manage_employees.php`, `employee_profile.php`, `manage_permissions.php`, `db_upgrade.php`, `app/MenuManager.php`, `app/Version.php`, `VERSION`.
Aggiunge: `manage_departments.php`, `sql/migration_v1_7_58.sql`.

## 3. Migrazione DB (SQL Runner o phpMyAdmin)
SQL Runner dal portale → applica `1.7.58`, oppure:
```sql
SOURCE sql/migration_v1_7_58.sql;
```
Idempotente / ri-eseguibile. Crea `departments`/`department_history`, aggiunge `employees.department_id` + FK, esegue il backfill dal testo legacy e allinea `app_settings` a 1.7.58.

## 4. Permessi
Gestione Permessi → assegnare `manage_departments.php` (view + edit/create) ai ruoli HR/Admin. Super Admin già abilitato dalla migration.

## 5. Verifica
- `VERSION` = 1.7.58; footer/`app_settings` allineati (auto-bump via `Version::autoBumpIfNeeded`).
- Menu Amministrazione → "Dipartimenti / Unità Org." visibile.
- Select Dipartimento popolato nel form dipendente e nel profilo.
- Salvataggio dipendente: `department_id` e `department` coerenti.
- Backfill: dipendenti con vecchio testo risultano collegati.
- Storico registra una modifica di prova.

## Rollback
Ripristinare `backup_pre_1.7.58.sql` + cartella di backup. Tabelle `departments`/`department_history` droppabili; colonna `employees.department_id` può restare (nullable, innocua).
