# DEPLOYMENT — v1.9.97
Prerequisito: v1.9.96 installata.
1. `Expand-Archive -Path PortalManager_v1_9_97.zip -DestinationPath P:\xampp\htdocs\demo_portalmanager -Force`
   File: `VERSION`, `app/DgbModel.php`, `dgb_activities.php`, `sql/`, `docs/`.
2. Eseguire `sql/migration_v1_9_97.sql` (indici + versione). Idempotente.
3. Ctrl+F5 su Attività & Rendicontazione DGB → scheda Analisi & KPI → «Filtri».
Rollback: ripristinare i 2 file PHP della v1.9.96.
