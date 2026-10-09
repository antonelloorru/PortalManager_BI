# DEPLOYMENT — v1.10.35

Pacchetto `update_v1.10.35.zip` cumulativo da 1.10.06 (include v1.10.07 → v1.10.34).

1. Sistema › Console › Aggiornamento → `update_v1.10.35.zip` → Aggiornamento DB (o `sql/migration_v1_10_35.sql`).
2. Installazione manuale: `tech_report.php`, `app/TechReport.php`, `app/ItServiceModel.php`, `app/PermissionCatalog.php`, `app/Version.php`, `VERSION`.
3. Ctrl+F5.
4. Verifica: `P:\xampp\php\php.exe tools\verify_v1_10_35.php --db=demo_portalmanager` → `0 KO`.
