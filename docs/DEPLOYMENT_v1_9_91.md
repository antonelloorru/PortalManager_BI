# DEPLOYMENT — v1.9.91

Prerequisito: v1.9.90 installata.

1. Backup file + DB.
2. `Expand-Archive -Path PortalManager_v1_9_91.zip -DestinationPath P:\xampp\htdocs\demo_portalmanager -Force`
   File: `VERSION`, `app/ItServiceModel.php`, `app/it_service_print.php`, `it_service.php`, `service_desk.php`,
   `dir_report.php`, `dgb_activities.php`, `sql/`, `docs/`.
3. Eseguire `sql/migration_v1_9_91.sql` (SQL Runner). Idempotente.
4. Ctrl+F5.
5. Verifica: Relazione IT → sezione «Attività DGB senza modulo di intervento» in fondo; Service Desk, Report direzionale,
   Attività & Rendicontazione DGB → nessuna barra «Cerca in tutta la tabella / Filtri / Viste / Esporta» sopra le tabelle.

Rollback: ripristinare i file PHP della v1.9.90.
