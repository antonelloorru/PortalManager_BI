# Deployment — PortalManager v1.9.38

## Ordine di applicazione
1. **SQL prima**: eseguire `sql/migration_v1_9_38.sql` (o, da versione incerta,
   `sql/upgrade_1_9_36_to_1_9_38.sql`). Aggiunge/ri-asserisce la colonna
   `commerciale` in `v_cm_pratix_righe`.
2. **File poi**: `pratix_orders.php` e `pratix_orders_print.php` nella root del webroot.

La pagina è resiliente all'ordine inverso (file prima di SQL): i select Commerciale/
Cliente restano temporaneamente vuoti ma non generano errore.

## Via system_console.php (consigliata)
Tab **Aggiornamento (ZIP)** → carica `PortalManager_v1_9_38.zip` → Analizza → Applica.
La migration in `sql/` viene applicata dal motore; i file vengono copiati preservando i path.
Stop + Start Apache, poi Ctrl+F5.

## Via PowerShell + SQL Runner (alternativa)
1. `Expand-Archive PortalManager_v1_9_38.zip -DestinationPath P:\xampp\htdocs\portalmanager -Force`
2. SQL Runner: `sql/migration_v1_9_38.sql`.
3. Stop + Start Apache, Ctrl+F5.

## Dipendenze
`app/XlsxWriter.php` deve essere già presente (lo è dalle release precedenti):
non è incluso nel pacchetto per non sovrascrivere la versione installata.

## Verifica post-deploy
| Passo | Esito atteso |
|---|---|
| Ordinativi Pratix → Filtri | select Commerciale e Cliente popolati |
| Filtra per un commerciale | lista ristretta agli ordinativi con quel commerciale |
| Pulsante XLSX | scarica .xlsx a 3 fogli, con colonna Commerciale nel dettaglio |
| Pulsante CSV | scarica .csv (`;`, BOM) dell'elenco ordinativi filtrato |
| Pulsante PDF | apre la vista di stampa; "Stampa" → Salva come PDF |
| `SELECT setting_value FROM app_settings WHERE setting_key='schema_version'` | 1.9.38 |

## Rollback
Ripristinare `pratix_orders.php` dal backup dell'updater ed eliminare
`pratix_orders_print.php`. Lo schema non richiede rollback (sola vista).
