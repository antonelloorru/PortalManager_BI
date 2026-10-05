# DEPLOYMENT — v1.10.01
Prerequisito: v1.10.00 installata.
1. `system_console.php` → Aggiornamento → `update_v1.10.01.zip`.
   In alternativa: `Expand-Archive -Path update_v1.10.01.zip -DestinationPath P:\xampp\htdocs\demo_portalmanager -Force`
2. Eseguire subito `sql/migration_v1_10_01.sql` (indici, permesso di lettura dei parametri, versione; idempotente).
3. Stop + Start di Apache, poi Ctrl+F5.
4. Verifica: `php tools/verify_v1_10_01.php --db=demo_portalmanager` → «30 OK, 0 KO».
5. Gestione permessi: assegnare ai ruoli, se diverso dal default, «Progetti PRJ (elenco)», «Scheda progetto PRJ», «Calcolo scenari PRJ», «Collegamento PRJ - commessa SP», «Parametri dimensionamento».

File:
- `VERSION`, `update_manifest.json`, `access_control.php`, `manage_projects.php`, `prj_dashboard.php`, `prj_parameters.php`, `api_prj.php`;
- `app/` (Version, Router, MenuManager, PermissionCatalog, CommesseSync, PrjRepo, PrjLink, PrjUi, prj_list);
- `assets/pm-filters.css`, `tools/verify_v1_10_01.php`, `sql/`, `docs/`.

Rollback: ripristinare i file della v1.10.00 ed eliminare i file nuovi. Lo schema non cambia.
