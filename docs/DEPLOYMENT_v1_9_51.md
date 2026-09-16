# Deployment — PortalManager v1.9.51

1. Copiare in root: `device_manager.php`, `device_handover.php`, `device_import.php`,
   `device_export.php`, `device_print.php`, `employee_cv.php`, `cv_import.php`,
   `credly_manual_import.php`, `access_control.php`, `r.php`, `rbac_debug.php`;
   in `app/`: `Session.php`.
2. SQL: eseguire `sql/migration_v1_9_51.sql` (idempotente).

Via system_console.php: Aggiornamento (ZIP) → `PortalManager_v1_9_51.zip` → Analizza → Applica.
Via PowerShell: `Expand-Archive ... -Force` + SQL Runner. Stop+Start Apache, Ctrl+F5.

## Verifica post-deploy
| Passo | Esito atteso |
|---|---|
| Login come debora (Coordinatore Tecnico, role 10) → Gestione dispositivi | accede (non più "Accesso negato") |
| Ruoli con can_view=1 in "permessi ruoli" su device_manager | accedono |
| Ruoli con can_view=0 | restano negati |
| Dipendente che apre/stampa il PROPRIO dispositivo/CV | consentito (accesso self) |
| Cambiare in "permessi ruoli" l'accesso a device_manager per un ruolo | effetto immediato (nessun elenco hardcoded da toccare) |
| `schema_version` | 1.9.51 |
