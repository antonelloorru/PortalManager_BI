# Deployment — Ingestione Pratix (v1.9.58)

1. File: `app/PratixImporter.php` (in `app/`), `pratix_import.php` e `pratix_orders.php` (in root).
2. SQL: eseguire `sql/migration_pratix_ext.sql` (crea `cm_pratix_ext`, le viste `_ext`, bump versione).
3. (Opzionale) Integrare `manage_projects.php` con i frammenti in `docs/QUERY_FRAGMENTS.md`.
4. Menu/permessi: aggiungere la voce `pratix_import` e i permessi ai ruoli previsti (es. 1,2,11).

## Requisiti parser .xls
Il file fornito è **.xls (BIFF)**: per leggerlo serve **PhpSpreadsheet**
(`composer require phpoffice/phpspreadsheet`). In alternativa esportare il report Pratix
in **.xlsx**, letto dal reader nativo `app/XlsxReader.php` senza dipendenze.

## Verifica post-deploy
| Passo | Esito atteso |
|---|---|
| Import Pratix (.xls/.xlsx) | report: righe lette / upsert / senza codice / errori |
| Ordini Pratix | ogni card mostra il blocco "Dati Pratix" con le 12 colonne "(da Pratix)" |
| Commesse/Progetti (dopo integrazione) | 12 colonne "(da Pratix)" per commessa |
| Re-import stesso file | nessun duplicato (UPSERT idempotente su order_code) |
| schema_version | 1.9.58 |
