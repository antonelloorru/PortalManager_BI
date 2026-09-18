# Technical Design — v1.9.37

## 1. Fix timeout backup (system_console.php)
Blocco `action === 'apply'`: la chiamata a `apply_update()` (che in
`app/UpdaterCore.php` esegue backup file + backup DB in streaming) era protetta
da `set_time_limit(300)`. Su DB con tabelle DGP/DGB da centinaia di MB il backup
superava i 300s e veniva interrotto.

Intervento (solo invocazione, logica di backup invariata):
- `@set_time_limit(0)`, `@ini_set('max_execution_time','0')` — nessun cap di durata.
- `ignore_user_abort(true)` — la disconnessione del client non aborta il processo.
- `@ini_set('memory_limit','1024M')` — margine per tabelle voluminose.
- svuotamento buffer output residui prima dell'avvio.

## 2. Vista v_cm_pratix_righe (schema)
Sorgente dati Ordinativi Pratix. Relazioni:

```
cm_project_operations o
  └─(o.project_id = p.id)─ cm_projects p
        ├─(p.client_id = cl.id)──── clients cl
        └─(p.service_line = cm.service_line)─ cm_contract_models cm
```

Dimensioni derivate dall'entità commessa (`cm_projects`):
- `cliente = coalesce(cl.name, p.client_raw, '(non indicato)')`
- `commerciale = coalesce(p.commercial_ref, '(non indicato)')`  ← nuovo in 1.9.37

Colonne 20 → 21. `v_cm_pratix_ordinativi` (raggruppata per `order_code`) e
`v_cm_pratix_quadro` restano invariate e continuano a leggere da questa vista;
il filtro per commerciale/cliente a livello di ordinativo si esprime come
`EXISTS(SELECT 1 FROM v_cm_pratix_righe r WHERE r.order_code = <grp> AND r.commerciale = ? AND r.cliente = ?)`.

## 3. Idempotenza e versioning
`CREATE OR REPLACE VIEW` + `CREATE TABLE IF NOT EXISTS pm_migration_sql` +
`INSERT IGNORE` su UNIQUE `(version,filename)` + `UPDATE app_settings`.
RUN1/RUN2 err=0. Software/Schema/Upgrade allineati a 1.9.37.
