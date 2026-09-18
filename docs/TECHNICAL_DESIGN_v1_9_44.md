# Technical Design — v1.9.44

## DatasetSync::writeRows() — upsert idempotente
Prima: `SELECT id WHERE $keyF = ?` → UPDATE se presente, altrimenti INSERT semplice.
Il controllo su una sola colonna ($keyF) non copre le UNIQUE COMPOSTE dei target
(es. `uq_dfao_activity_operator (id_activity,id_operator)` su
`dgb_forms_activity_operator`), causando 1062 quando la coppia esiste già sotto un
`id` diverso.

Dopo: il ramo INSERT è
```
INSERT INTO `$target` (...) VALUES (...)
ON DUPLICATE KEY UPDATE `col` = IF(VALUES(`col`) IS NULL OR CAST(VALUES(`col`) AS CHAR)='',
                                   `col`, VALUES(`col`))   -- per ogni col != $keyF
```
- riconcilia su qualunque UNIQUE (singola o composta) violata;
- `$keyF` escluso dall'update: la chiave del dataset non cambia;
- anti-sovrascrittura delle celle vuote come nel ramo UPDATE; confronto del vuoto su
  `CAST(... AS CHAR)` per non incorrere in 1292 (truncated DECIMAL) in strict mode;
- classificazione insert/update via `PDOStatement::rowCount()` (MariaDB: 1=insert, 2=update).

Compatibilità: MariaDB 10.4 (sintassi `VALUES()` in ODKU). Nessun delta di schema.
