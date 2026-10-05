# Release Checklist — v1.10.01
- [x] VERSION = 1.10.01, PM_VERSION = 1.10.01, migration aggiorna app_version / schema_version / release_label, `pm_migration_sql` ('1.10.01','migration_v1_10_01.sql')
- [x] Base: `origin/main` (v1.10.00 caricata), file del repository come fonte di verità; terminatori di riga preservati (MenuManager con riga CRLF, PermissionCatalog LF)
- [x] Migration idempotente, nessun `;` nei commenti, RUN1/RUN2 err=0 (Dump 19.80 + v1.9.99 + v1.10.00, DB di test)
- [x] `php -l`: prj_dashboard.php, prj_parameters.php, api_prj.php, manage_projects.php, access_control.php, app/prj_list.php, app/PrjLink.php, app/PrjUi.php, app/PrjRepo.php, app/CommesseSync.php, app/MenuManager.php, app/Router.php, app/PermissionCatalog.php, app/Version.php, tools/verify_v1_10_01.php
- [x] `tools/verify_v1_10_01.php` 30 OK; `tools/verify_v1_10_00.php` 43 OK (nessuna regressione del motore)
- [x] Test browser Super Admin (8 tab, POST di ogni azione) e Finance (permessi di sola lettura, 403 sull'API)
- [x] Sicurezza: CSRF su ogni POST, PRG, prepared statement, whitelist di tabelle e campi, appartenenza delle righe al progetto, API con controllo del permesso, cm_projects in sola lettura
- [x] Docs: CHANGELOG, DEPLOYMENT, TECHNICAL_DESIGN, MANUALE (Admin e Utente), RELEASE_CHECKLIST; `update_manifest.json`
