# DEPLOYMENT — v1.10.02
Prerequisito: v1.10.01 installata.

1. `system_console.php` → Aggiornamento → `update_v1.10.02.zip`, oppure `Expand-Archive -Path update_v1.10.02.zip -DestinationPath P:\xampp\htdocs\demo_portalmanager -Force`.
   File:
   - `VERSION`, `update_manifest.json`;
   - `prj_dashboard.php`, `prj_history.php`, `project_dashboard.php`, `manage_projects.php`;
   - `app/Version.php`, `app/Router.php`, `app/MenuManager.php`, `app/PmCharts.php`;
   - `tools/verify_v1_10_02.php`, `sql/`, `docs/`.
2. Eseguire subito `sql/migration_v1_10_02.sql` (indici e versione, idempotente).
3. Stop + Start Apache, poi Ctrl+F5.
4. Verifica: `php tools/verify_v1_10_02.php --db=demo_portalmanager` → «20 OK, 0 KO».

Permessi: la voce «Scenari & confronti progetti» compare ai ruoli con permesso view su `prj_history.php`, già assegnato in v1.9.99 a Super Admin, Resp. Commerciale, Direttore IT, Coordinatore Tecnico e Finance.

Rollback: ripristinare i file della v1.10.01 ed eliminare `prj_history.php`. Lo schema non cambia.
