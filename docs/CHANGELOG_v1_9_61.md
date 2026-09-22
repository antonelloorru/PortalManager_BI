# PortalManager v1.9.61 — Dettaglio commessa: tab "Pratix" (1 commessa → N Codici Pratix)

## UI
Nuovo tab **Pratix** nella scheda commessa (`project_dashboard.php`), accanto a DGB.
Mostra, per la commessa corrente, tutti i Codici Pratix collegati con i relativi clienti:
- **Codice Pratix**
- **Cliente Fatturazione**
- **Cliente Effettivo**
Il badge sul tab riporta il numero di codici collegati.

## Logica DB (relazione 1-a-N)
Nuova vista `v_cm_pratix_commessa_codici`: per ogni commessa (`project_code`) elenca
TUTTI i Codici Pratix (`order_code`) presenti in `v_cm_pratix_righe`, con i clienti presi
da `cm_pratix_ext` per singolo codice. Poiché i clienti possono variare per ogni Codice
Pratix, la relazione è 1-a-N e la vista restituisce una riga per (commessa, codice).

```sql
CREATE OR REPLACE VIEW v_cm_pratix_commessa_codici AS
SELECT DISTINCT r.commessa AS project_code, r.order_code AS codice_pratix,
       e.cliente_fatturazione, e.cliente_effettivo
FROM v_cm_pratix_righe r
LEFT JOIN cm_pratix_ext e ON e.order_code = r.order_code COLLATE utf8mb4_unicode_ci
WHERE r.commessa IS NOT NULL AND r.commessa <> '';
```

La pagina interroga la vista con `WHERE project_code = <project_code della commessa>`.

## QA
- Vista 1-a-N verificata: una commessa con 2 Codici Pratix restituisce 2 righe con
  clienti distinti per codice (es. 10359 → AZIENDA USL TOSCANA CENTRO, C3046 → TERRA INNOVATUM).
- `php -l` OK; migration RUN1/RUN2 err=0; `;` nei commenti = 0; schema_version → 1.9.61.

## File
```
VERSION                       1.9.61
project_dashboard.php         tab + pannello + query Pratix (1-a-N)
sql/migration_v1_9_61.sql     vista v_cm_pratix_commessa_codici + bump
docs/
```
