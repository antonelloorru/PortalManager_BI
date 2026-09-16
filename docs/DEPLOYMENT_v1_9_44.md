# Deployment — PortalManager v1.9.44

1. File: `app/DatasetSync.php` nella cartella `app/` del webroot.
2. SQL: `sql/migration_v1_9_44.sql` (solo allineamento versione).
   Da versione incerta: `sql/upgrade_1_9_42_to_1_9_44.sql`.

Via system_console.php: Aggiornamento (ZIP) → `PortalManager_v1_9_44.zip` → Analizza → Applica.
Via PowerShell: `Expand-Archive ... -Force` (preserva `app/`) + SQL Runner. Stop+Start Apache.

## Verifica post-deploy
| Passo | Esito atteso |
|---|---|
| Sincronizzazione gestionale → dataset "Ore per operatore su attività DGB" | completa senza errore 1062 |
| Ri-esecuzione della stessa sincronizzazione | idempotente: nessun errore, righe aggiornate |
| Conteggio inseriti/aggiornati nel report | coerente (aggiornamenti contati come tali) |
| `schema_version` | 1.9.44 |
