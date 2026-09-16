# Deployment — PortalManager v1.9.47

1. Copiare `recruiting_posizioni.php` nella root del webroot.
2. SQL: `sql/migration_v1_9_47.sql` (idempotente). In alternativa, la colonna viene
   creata automaticamente al primo caricamento della pagina.

Via system_console.php: Aggiornamento (ZIP) → `PortalManager_v1_9_47.zip` → Analizza → Applica.
Via PowerShell: `Expand-Archive ... -Force` + SQL Runner. Stop+Start Apache, Ctrl+F5.

## Verifica post-deploy
| Passo | Esito atteso |
|---|---|
| Recruiting & Agenzie → Posizioni aperte → Nuova/Modifica | campo "Codice Posizione LinkedIn" presente |
| Salva con un codice | valore persistito in `job_positions.linkedin_code` |
| Riapri in modifica | il campo è precompilato |
| Card posizione | mostra il codice LinkedIn se valorizzato |
