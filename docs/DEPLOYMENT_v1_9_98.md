# DEPLOYMENT — v1.9.98
Prerequisito: v1.9.97 installata.
1. `Expand-Archive -Path PortalManager_v1_9_98.zip -DestinationPath P:\xampp\htdocs\demo_portalmanager -Force`
   File: `VERSION`, `app/DgbModel.php`, `dgb_activities.php`, `sql/`, `docs/`.
2. Eseguire `sql/migration_v1_9_98.sql` (solo versione). Idempotente.
3. Ctrl+F5 su Attività & Rendicontazione DGB → Analisi & KPI → «Filtri» e sezione «Dettaglio».
Rollback: ripristinare i 2 file PHP della v1.9.97.
