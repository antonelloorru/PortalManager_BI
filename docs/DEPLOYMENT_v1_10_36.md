# DEPLOYMENT — v1.10.36

Pacchetto `update_v1.10.36.zip` cumulativo da 1.10.06 (include v1.10.07 → v1.10.35).

1. Sistema › Console › Aggiornamento → `update_v1.10.36.zip` → Aggiornamento DB (o `sql/migration_v1_10_36.sql`).
2. Installazione manuale: `service_desk.php`, `app/SdModel.php`, `app/SdReport.php`, `app/XlsxWriter.php`, `app/Version.php`, `VERSION`.
3. Ctrl+F5.
4. Verifica: `P:\xampp\php\php.exe tools\verify_v1_10_36.php --db=demo_portalmanager` → `0 KO`.
