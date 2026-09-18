# Release Notes — PortalManager v1.9.39

Data: 2026-09-07
Allineamento versioni: Software 1.9.39 · Schema 1.9.39 · Upgrade 1.9.39
Sezione: Ordinativi Pratix (`pratix_orders.php`)

## Nome Commerciale accanto al Codice Pratix
Nell'intestazione di ogni ordinativo, accanto al codice (`order_code`), è mostrato
il **Nome Commerciale** ricavato dalle commesse collegate. Se l'ordinativo abbraccia
più commerciali si mostra il primo con l'indicatore «+N» e l'elenco completo nel
tooltip. Placeholder «(non indicato)» esclusi.

## Ordinamento per Commerciale e Cliente
Il menu «Ordina per» offre due nuove opzioni: **Commerciale (A→Z)** e
**Cliente (A→Z)**. Poiché un ordinativo può avere più commerciali/clienti, la lista
è ordinata sul primo valore in ordine alfabetico (MIN sulle righe collegate), con
l'importo come chiave secondaria. Nessun campo aggiuntivo in `v_cm_pratix_ordinativi`:
l'ordinamento usa una sottoquery correlata su `v_cm_pratix_righe`.

## Note tecniche
- Modifiche esclusivamente lato PHP (`pratix_orders.php`). Nessuna variazione
  strutturale: la migration ri-asserisce `v_cm_pratix_righe` (idempotente) solo per
  garantire la colonna `commerciale` su installazioni non ancora allineate.
- `pratix_orders_print.php` incluso invariato (dipendenza dell'export PDF).
- `app/XlsxWriter.php` riusato dall'installazione (non incluso).

## Contenuto pacchetto
```
VERSION                              1.9.39
pratix_orders.php          (ROOT)    nome commerciale su codice + sort commerciale/cliente
pratix_orders_print.php    (ROOT)    vista di stampa (invariata)
sql/migration_v1_9_39.sql            ri-assert vista + bump versioni + registrazione
sql/upgrade_1_9_37_to_1_9_39.sql     consolidato ultime 2 versioni -> 1.9.39
docs/                                changelog, manuali, deployment, technical design
```

## QA
- `php -l` OK su `pratix_orders.php` e `pratix_orders_print.php`.
- ORDER BY correlate (commerciale/cliente): parse ed esecuzione verificate su MariaDB.
- SQL migration/consolidato: RUN1/RUN2 err=0; `;` nei commenti = 0.
- Post-upgrade: `app_version/schema_version/release_label` = 1.9.39.
