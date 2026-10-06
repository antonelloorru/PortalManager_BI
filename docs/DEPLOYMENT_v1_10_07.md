# Deployment v1.10.07

Prerequisito: v1.10.06 installata.
1. Backup DB e cartella applicazione.
2. Estrarre `update_v1.10.07.zip` nella root (Sistema › Aggiornamento o `Expand-Archive … -Force`).
3. Eseguire `sql/migration_v1_10_07.sql` (idempotente).
4. Stop+Start Apache, Ctrl+F5.
5. L'utente di Apache deve poter scrivere in `uploads/soc_inbox` (creata alla prima esecuzione con `archivio` e `scartati`).
6. Sincronizzazione gestionale › SOC: verificare la connessione al DB SOC (già salvata in v1.10.06), attivare l'esecuzione automatica, «Esegui ora».
7. Se in v1.10.06 era stata creata l'attività pianificata `cron_soc_sync.php`, può restare (stesso lock: nessuna doppia esecuzione) o essere rimossa se lo scheduler del portale è attivo.
8. `php tools/verify_v1_10_07.php --db=<database>` → nessun KO.
Rollback: file v1.10.06; `cm_soc_sync_runs` può restare.
