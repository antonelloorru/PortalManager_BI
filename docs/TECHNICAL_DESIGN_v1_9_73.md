# Technical Design — v1.9.73

## Copie delle viste (app/PmSnapshot.php)
- Registro `pm_snapshot(view_name, mode, enabled, status, rows_count, build_ms, refreshed_at, note)`.
- `names($pdo, [viste])` → mappa vista → nome da interrogare; usato nei costruttori di
  `SdModel`, `ItServiceModel`, `DirModel` (i nomi nelle query sono `{$this->v['v_cm_…']}`).
- `refresh($pdo, $all, $only)`: `CREATE TABLE snap_x__new AS SELECT * FROM x` (tempo
  misurato), indici sulle colonne di filtro presenti, `RENAME TABLE` atomico; lock
  `GET_LOCK('pm_snapshot')`.
- Inneschi: fine sincronizzazione (`SyncRunner`), richiesta in background dalle pagine
  (`CronlessScheduler::dispatch(..., 'snapshot')`, al massimo ogni 10 minuti), pulsante
  in Sistema → Prestazioni.
- Worker: `cronless_worker.php` accetta `task=snapshot`; la firma HMAC include il tipo di
  attività (una firma di sincronizzazione non vale per altre attività).

## Relazione IT — dettaglio su richiesta
`dettaglioSql()` unica query; `dettaglioCommessa($f, $cid)` e `dettaglioCommessaSintesi($f)`.
Endpoint nella pagina: `?ajax=dett_commessa&cid=N` (restituisce solo le righe `<tr>`).

## Grafici (app/PmCharts.php)
`window()`, `fillDays()`, `dailyStacked()`: SVG lato server (schermo e stampa uguali).

## Profiler
`bootstrap.php`: `SET profiling = 1` se la sessione lo richiede (solo ruolo 1);
`footer.php`: `SHOW PROFILES`, prime 40 query ordinate per durata.
