# DEPLOYMENT — v1.10.26

Pacchetto `update_v1.10.26.zip` cumulativo da 1.10.06 (include v1.10.07 → v1.10.25, plugin pm-ats 1.3.4).

1. Sistema › Console › Aggiornamento → `update_v1.10.26.zip` → Aggiornamento DB (o `sql/migration_v1_10_26.sql`).
2. Installazione manuale: `assets/js/pm-multiselect.js`, `assets/css/pm-multiselect.css`, `it_service.php`, `tech_report.php`, `service_desk.php`, `service_soc.php`, `dir_report.php`, `dgb_activities.php`, `app/Version.php`, `VERSION`.
3. Ctrl+F5.
4. Verifica: `P:\xampp\php\php.exe tools\verify_v1_10_26.php --db=demo_portalmanager` → `0 KO`.
