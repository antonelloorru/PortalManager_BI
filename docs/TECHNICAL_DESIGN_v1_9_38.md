# Technical Design — v1.9.38

## 1. Filtri (pratix_orders.php)
Nuovo parametro GET `commerciale` accanto a `cliente`. Entrambi applicati alla
lista `v_cm_pratix_ordinativi` (raggruppata per `order_code`) tramite EXISTS sulla
vista di dettaglio:

```
EXISTS (SELECT 1 FROM v_cm_pratix_righe r
         WHERE r.order_code = o.order_code AND r.cliente = ?)
EXISTS (SELECT 1 FROM v_cm_pratix_righe r
         WHERE r.order_code = o.order_code AND r.commerciale = ?)
```

Match esatto (i valori arrivano dai `<select>` popolati dai DISTINCT della vista).
Il filtro `Mostra` (`solo`) resta parallelo e indipendente. Le liste opzioni sono
caricate in un try dedicato: colonna `commerciale` assente ⇒ select vuoti, pagina
comunque funzionante.

## 2. Export (pratix_orders.php + pratix_orders_print.php)
Un unico blocco intercetta `?export ∈ {xlsx,csv,pdf}` prima di `header.php`,
costruisce gli stessi dataset già filtrati e ramifica:

- **xlsx** — `XlsxWriter` (API esistente): 3 fogli; il foglio dettaglio aggiunge
  la colonna `Commerciale`. `download()` gestisce svuotamento buffer, `zlib` off, `exit`.
- **csv** — `php://output`, BOM UTF-8, `fputcsv(...,';')`; buffer svuotati e `zlib`
  off come per l'xlsx; elenco ordinativi.
- **pdf** — include `pratix_orders_print.php`, vista A4 orizzontale con quadro,
  filtri, ordinativi e commesse collegate; PDF via stampa browser (nessuna libreria
  server-side, coerente con `it_service_print.php` / `dir_report_print.php`).

`app/XlsxWriter.php` non è incluso: riuso dell'installato via API pubblica.

## 3. Schema
`v_cm_pratix_righe` ri-asserita idempotente con `commerciale =
coalesce(cm_projects.commercial_ref,'(non indicato)')`. Bump
`app_version/schema_version/release_label` a 1.9.38 e registrazione in
`pm_migration_sql` (UNIQUE `version,filename`). RUN1/RUN2 err=0.
