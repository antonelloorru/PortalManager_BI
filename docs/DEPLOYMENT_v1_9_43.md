# Deployment — PortalManager v1.9.43

1. File: `it_service.php` (root); `app/ItServiceModel.php`, `app/it_service_print.php`,
   `app/DocxWriter.php` (cartella `app/`).
2. SQL: `sql/migration_v1_9_43.sql` (solo versione). Da versione incerta:
   `sql/upgrade_1_9_41_to_1_9_43.sql`.

Via system_console.php: Aggiornamento (ZIP) → `PortalManager_v1_9_43.zip` → Analizza → Applica.
Via PowerShell: `Expand-Archive ... -Force` (preserva `app/`) + SQL Runner. Stop+Start Apache, Ctrl+F5.

## Requisiti
- Estensione PHP **zip** (ZipArchive) attiva: serve a XlsxWriter e ora a DocxWriter.

## Verifica post-deploy
| Passo | Esito atteso |
|---|---|
| Filtri → "Dettagli da includere" | 7 checkbox, tutte spuntate di default |
| Deseleziona alcune → Stampa/Word | il report contiene solo le sezioni spuntate |
| Pulsante Word | scarica un .docx apribile in Word/LibreOffice |
| Grafico Andamento | linea target tratteggiata + marcatore reperibilità viola |
| `?target=1200` sul grafico | la linea target si posiziona a 1200 |
| `schema_version` | 1.9.43 |
