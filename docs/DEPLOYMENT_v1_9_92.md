# DEPLOYMENT — v1.9.92

Prerequisito: v1.9.91 installata.

1. Backup file.
2. `Expand-Archive -Path PortalManager_v1_9_92.zip -DestinationPath P:\xampp\htdocs\demo_portalmanager -Force`
   File: `VERSION`, `app/ItServiceModel.php`, `it_service.php`, `manage_projects.php`, `sql/`, `docs/`.
3. Eseguire `sql/migration_v1_9_92.sql` (solo versione).
4. Ctrl+F5. Verifica: Relazione IT → XLSX + pivot → foglio «Giorni per commessa»: Cliente | Descrizione | Commessa | …;
   Commesse / Progetti → colonne «Link SP» e «Scheda Progetto» affiancate.

Rollback: ripristinare i 3 file PHP della v1.9.91.
