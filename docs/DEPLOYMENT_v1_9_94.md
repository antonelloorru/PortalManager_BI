# DEPLOYMENT — v1.9.94

Prerequisito: v1.9.93 installata.
1. Backup file + DB.
2. `Expand-Archive -Path PortalManager_v1_9_94.zip -DestinationPath P:\xampp\htdocs\demo_portalmanager -Force`
   File: `VERSION`, `app/ProRata.php` (nuovo), `app/DirModel.php`, `app/dir_report_print.php`, `dir_report.php`, `sql/`, `docs/`.
3. Eseguire `sql/migration_v1_9_94.sql` (indice + versione). Idempotente.
4. Ctrl+F5 su Report direzionale. Verifica: Filtri → Periodo; sezione «Valore ordini per competenza» in fondo; badge FIDO;
   XLSX con i tre fogli di competenza.
Rollback: ripristinare i file della v1.9.93 e rimuovere `app/ProRata.php`.
