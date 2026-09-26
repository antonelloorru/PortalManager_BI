# Deployment — v1.9.72
1. Copiare `sync_commesse.php` in root; `app/SourceDb.php` e `app/SyncRunner.php` in `app/`.
2. Eseguire `sql/migration_v1_9_72.sql`.
3. Sincronizzazione gestionale → "Esegui ora in background": l'esito compare tra le ultime
   esecuzioni con stato ok.

Se compare "Impossibile decifrare la password del gestionale": `.env.php` non contiene lo
stesso APP_SECRET usato quando la password è stata salvata. Reinserire la password nella
configurazione della connessione e salvare.
