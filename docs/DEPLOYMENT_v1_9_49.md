# Deployment — PortalManager v1.9.49

1. Copiare in root: `access_control.php`, `r.php`, `rbac_debug.php`; in `app/`: `Session.php`.
2. SQL: `sql/migration_v1_9_49.sql` (solo versione).

Via system_console.php: Aggiornamento (ZIP) → `PortalManager_v1_9_49.zip` → Analizza → Applica.
Via PowerShell: `Expand-Archive ... -Force` + SQL Runner. Stop+Start Apache, Ctrl+F5.

## Verifica / diagnosi
1. Aprire `rbac_debug.php` (via router) con l'utente che riscontra il blocco, indicando
   la pagina problematica in `?page=`. Leggere "Causa probabile":
   - "RUOLO DI SESSIONE STALE" → risolto da questo aggiornamento (o rifare login);
   - "DENY ESPLICITO / override" → correggere in Gestione permessi;
   - "NESSUNA riga" → concedere il permesso al ruolo per quella pagina.
2. Cambiare il ruolo di un utente loggato da admin: al click successivo l'utente ha i
   permessi del nuovo ruolo, senza rifare login.
3. `schema_version` = 1.9.49.
