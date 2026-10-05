# DEPLOYMENT — v1.9.93

Prerequisito: v1.9.92 installata.
1. `Expand-Archive -Path PortalManager_v1_9_93.zip -DestinationPath P:\xampp\htdocs\demo_portalmanager -Force`
   File: `VERSION`, `manage_projects.php`, `sql/`, `docs/`.
2. Eseguire `sql/migration_v1_9_93.sql` (solo versione).
3. Ctrl+F5 su Commesse / Progetti: sopra la tabella non compare più la barra «Cerca in tutta la tabella / Filtri / Viste / Esporta».
Rollback: ripristinare `manage_projects.php` della v1.9.92.
