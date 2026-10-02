# DEPLOYMENT — v1.9.95
Prerequisito: v1.9.94 installata.
1. `Expand-Archive -Path PortalManager_v1_9_95.zip -DestinationPath P:\xampp\htdocs\demo_portalmanager -Force`
   File: `VERSION`, `app/DirModel.php`, `dir_report.php`, `sql/`, `docs/`.
2. Eseguire `sql/migration_v1_9_95.sql` (solo versione).
3. Ctrl+F5 su Report direzionale: colonne «Link SP» e «Scheda Progetto» dopo «Commessa» in «Commesse da presidiare» e «Valore ordini per competenza».
Rollback: ripristinare i 2 file PHP della v1.9.94.
