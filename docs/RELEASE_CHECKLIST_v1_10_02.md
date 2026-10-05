# Release Checklist — v1.10.02
- [x] VERSION = 1.10.02, PM_VERSION = 1.10.02, migration aggiorna app_version / schema_version / release_label, `pm_migration_sql` ('1.10.02','migration_v1_10_02.sql')
- [x] Base: branch v1.10.01 (main del repository ancora a v1.10.00); terminatori di riga preservati (MenuManager)
- [x] Migration idempotente, nessun `;` nei commenti, RUN1/RUN2 err=0 (Dump 19.80 con le migration fino a v1.10.01, DB di test)
- [x] `php -l`: prj_dashboard.php, prj_history.php, project_dashboard.php, manage_projects.php, app/PmCharts.php, app/MenuManager.php, app/Router.php, app/Version.php, tools/verify_v1_10_02.php
- [x] `tools/verify_v1_10_02.php` 20 OK; verifiche v1.10.00 e v1.10.01 senza regressioni (falliscono solo i controlli di versione)
- [x] Test browser Super Admin su tutte le nuove viste e azioni; export XLSX dell'elenco commesse (colonna progetti_prj) e dei calcoli (3 fogli)
- [x] Sicurezza: CSRF e PRG sui POST, simulatori in GET senza scritture, input validati (regex chiavi, numeri), permessi separati per il collegamento dalla commessa, cm_projects in sola lettura
- [x] Docs: CHANGELOG, DEPLOYMENT, TECHNICAL_DESIGN, MANUALE (Admin e Utente), RELEASE_CHECKLIST; `update_manifest.json`
