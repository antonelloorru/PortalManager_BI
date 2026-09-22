# Deployment — PortalManager v1.9.39

## Ordine di applicazione
1. SQL: `sql/migration_v1_9_39.sql` (da versione incerta: `sql/upgrade_1_9_37_to_1_9_39.sql`).
2. File: `pratix_orders.php` e `pratix_orders_print.php` nella root del webroot.

Ordine inverso tollerato: senza la colonna `commerciale` la pagina resta funzionante
(chip commerciale e sort per commerciale semplicemente vuoti/neutri).

## Via system_console.php (consigliata)
Tab **Aggiornamento (ZIP)** → carica `PortalManager_v1_9_39.zip` → Analizza → Applica.
Stop + Start Apache, Ctrl+F5.

## Via PowerShell + SQL Runner
1. `Expand-Archive PortalManager_v1_9_39.zip -DestinationPath P:\xampp\htdocs\portalmanager -Force`
2. SQL Runner: `sql/migration_v1_9_39.sql`.
3. Stop + Start Apache, Ctrl+F5.

## Dipendenze
`app/XlsxWriter.php` già presente dalle release precedenti (non incluso).

## Verifica post-deploy
| Passo | Esito atteso |
|---|---|
| Intestazione di un ordinativo | Codice + Nome Commerciale accanto |
| Ordina per → Commerciale (A→Z) | lista riordinata per commerciale |
| Ordina per → Cliente (A→Z) | lista riordinata per cliente |
| `SELECT setting_value FROM app_settings WHERE setting_key='schema_version'` | 1.9.39 |

## Rollback
Ripristinare `pratix_orders.php` dal backup dell'updater. Schema invariato.
