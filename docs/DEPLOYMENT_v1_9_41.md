# Deployment — PortalManager v1.9.41

1. SQL: `sql/migration_v1_9_41.sql` (o `sql/upgrade_1_9_39_to_1_9_41.sql` da versione incerta).
2. File: `relazione_servizio_it.php` nella root del webroot.

Via system_console.php: Aggiornamento (ZIP) → `PortalManager_v1_9_41.zip` → Analizza → Applica.
Via PowerShell: `Expand-Archive ... -Force` + SQL Runner sulla migration. Stop+Start Apache, Ctrl+F5.

## Verifica post-deploy
| Passo | Esito atteso |
|---|---|
| Relazione di Servizio IT → in fondo | sezione "Riepilogo per Codice Contratto" |
| Sotto ogni contratto | righe di dettaglio per commessa (interventi, giorni-uomo, ore, €) |
| `SELECT setting_value FROM app_settings WHERE setting_key='schema_version'` | 1.9.41 |
