# DEPLOYMENT — v1.9.84

1. Backup file + DB (Console di sistema → Aggiornamento, oppure mysqldump).
2. Estrarre lo ZIP nella root del portale (sovrascrive `VERSION`, `app/SourceDb.php`, `app/SyncRunner.php`, aggiunge `sql/` e `docs/`).
   PowerShell: `Expand-Archive -Path PortalManager_v1_9_84.zip -DestinationPath P:\xampp\htdocs\demo_portalmanager -Force`
3. Eseguire `sql/migration_v1_9_84.sql` (SQL Runner o phpMyAdmin). Idempotente.
4. Verifica: Sincronizzazione gestionale → «Esegui ora» della pianificazione; esito atteso senza errori 2006.
   Nel log possono comparire righe «connessione al gestionale scaduta: riconnessione» (comportamento corretto).
5. Opzionale lato gestionale: se l'utente di sola lettura non può modificare `wait_timeout`, la riconnessione automatica copre comunque il caso.

Rollback: ripristinare i due file `app/` precedenti; nessuna modifica di schema da annullare.
