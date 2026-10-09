# DEPLOYMENT — v1.10.30

Pacchetto `update_v1.10.30.zip` cumulativo da 1.10.06 (include v1.10.07 → v1.10.29).

1. Sistema › Console › Aggiornamento → `update_v1.10.30.zip` → Aggiornamento DB (o `sql/migration_v1_10_30.sql`).
2. Installazione manuale: `app/PmUoFilter.php` (nuovo), `app/ItServiceModel.php`, `app/SocModel.php`, `app/SdModel.php`, `app/DirModel.php`, `app/DgbModel.php`, `app/ProjectModel.php`, `it_service.php`, `tech_report.php`, `service_soc.php`, `service_desk.php`, `dir_report.php`, `dgb_activities.php`, `workload_overview.php`, `manage_projects.php`, `app/Version.php`, `VERSION`.
3. Ctrl+F5.
4. Verifica: `P:\xampp\php\php.exe tools\verify_v1_10_30.php --db=demo_portalmanager` → `0 KO`.
