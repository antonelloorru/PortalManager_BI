# Technical Design — Ingestione Pratix

## Pipeline ETL (app/PratixImporter.php)
- `readRows($path)`: parser. Usa **PhpSpreadsheet** se disponibile (legge .xls BIFF e .xlsx),
  altrimenti fallback al reader nativo `app/XlsxReader.php` (solo .xlsx). Individua la riga
  header cercando 'codice' + 'totale'; scarta la riga dei totali (priva di Codice) a valle.
- `validateSchema($headers)`: verifica header obbligatori (`codice`,`totale`,`cliente effettivo`).
- `mapRow()`: header Excel (normalizzati: lowercase, no newline, spazi collassati) -> campo canonico.
- Pulizia: `cut()` (trim, collasso spazi, N/A->null, truncation); `toDecimal()` (IT/EN/€ -> DECIMAL);
  `toDate()` (seriali Excel) per usi futuri.
- `upsertRows()`: **transazione ACID** con `INSERT ... ON DUPLICATE KEY UPDATE` su
  `cm_pratix_ext(order_code UNIQUE)`. Righe senza Codice **saltate** (non bloccano);
  errori su singola riga raccolti senza interrompere; fallimento globale -> `rollBack()`.

## Modello dati
`cm_pratix_ext` — una riga per `order_code` (UNIQUE, = business key). 12 campi importati +
`source_file` + `imported_at`. Indici secondari su `cliente_effettivo`, `stato`, `linea_business`.
Nessun campo ridondante; la chiave di join è indicizzata (UNIQUE) per letture rapide.

## Viste
- `v_cm_pratix_ordinativi_ext` = `v_cm_pratix_ordinativi` LEFT JOIN `cm_pratix_ext`
  ON `order_code` (join collation-safe, `COLLATE utf8mb4_unicode_ci`).
- `v_cm_pratix_commessa_ext` = `cm_projects.project_code` -> `order_code` rappresentativo
  (MIN da `v_cm_pratix_righe`) -> LEFT JOIN `cm_pratix_ext`.

## Verifiche (dati reali PRATIX2026_09_15.xls, 4028 righe)
- Upsert: 4028 record, 0 errori, **idempotente** (re-run non duplica).
- `SUM(totale)` = 133.224.255,71 = riga totali del file -> cast valuta corretto su tutte le righe.
- Null gestiti (celle vuote -> NULL). Viste `_ext` popolate correttamente (px_* valorizzati).
