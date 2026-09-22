# Technical Design — v1.9.39

## 1. Nome Commerciale sull'ordinativo (pratix_orders.php)
Nel ciclo di rendering degli ordinativi, dai `$righe` già caricati per ciascun
`order_code` si estrae `$commOrd` = valori distinti non-placeholder di
`commerciale`. L'intestazione della card, dopo il codice, mostra `$commOrd[0]`
con «+N» quando i commerciali sono più d'uno (elenco completo nel `title`).
Nessuna query aggiuntiva: riuso del dataset di dettaglio.

## 2. Ordinamento per Commerciale/Cliente
Il match `$ordSQL` aggiunge due rami. Un ordinativo aggrega più righe, quindi non
esiste un valore scalare di commerciale/cliente: si usa il minimo alfabetico via
sottoquery correlata sulla vista righe, con l'importo come chiave secondaria:

```
ORDER BY (SELECT MIN(rs.commerciale) FROM v_cm_pratix_righe rs
           WHERE rs.order_code = o.order_code), o.importo_totale DESC
```

(analogo per `cliente`). Il `<select>` «Ordina per» espone le voci
`commerciale` e `cliente`.

## 3. Schema
Nessuna variazione: la migration ri-asserisce `v_cm_pratix_righe` (idempotente),
bump `app_version/schema_version/release_label` a 1.9.39 e registrazione in
`pm_migration_sql`. RUN1/RUN2 err=0.
