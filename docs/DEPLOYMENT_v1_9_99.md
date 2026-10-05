# DEPLOYMENT — v1.9.99
Prerequisito: v1.9.98 installata.

## Aggiornamento da console
1. `system_console.php` → Aggiornamento → caricare `update_v1.9.99.zip`.
2. Eseguire **subito** `sql/migration_v1_9_99.sql` dalla console SQL. `PM_VERSION` ora segue VERSION: il codice riallinea la versione in `app_settings`, quindi lo schema va applicato insieme al codice.
3. Stop + Start di Apache, poi Ctrl+F5.
4. Verifica: `php tools/verify_v1_9_99.php --db=demo_portalmanager` → atteso «33 OK, 0 KO».

## Installazione manuale
1. `Expand-Archive -Path update_v1.9.99.zip -DestinationPath P:\xampp\htdocs\demo_portalmanager -Force`
2. File:
   - `VERSION`, `update_manifest.json`;
   - `app/Version.php`, `app/Router.php`;
   - `manage_permissions.php`, `merge_employees.php`;
   - `tools/verify_v1_9_99.php`;
   - `sql/migration_v1_9_99.sql`, `docs/`.
3. phpMyAdmin → database → Importa `sql/migration_v1_9_99.sql`. È idempotente e si può rieseguire.
4. Stop + Start di Apache, poi Ctrl+F5.

## Rollback
- Ripristinare i 4 file PHP e `VERSION` della v1.9.98.
- Le tabelle `cm_prj*` non sono usate da altre pagine e possono restare. In alternativa si eliminano in ordine inverso di creazione (figlie prima di `cm_prj`).
