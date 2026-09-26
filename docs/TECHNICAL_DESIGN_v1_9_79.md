# Technical Design — v1.9.79

## Relazioni
```
cm_intervention_reports.dgb_source_id ──► dgb_forms_activity_operator.id (allocazione: attività + tecnico)
                                            └─► dgb_forms_activity.id ──► r.dgb_activity_id / r.dgb_activity_code
cm_intervention_reports.report_code  ──► dgb_forms_activity.code (fallback, solo codici univoci)
```
Le viste leggono `ao.during_availability` (reperibilità), `ao.smart_working`, `ao.from_remote`,
`ao.trip_hours` (presso cliente) e `a.date_start` (fascia oraria) tramite `r.dgb_activity_id`.

## Punti di collegamento
| Momento | Componente |
|---|---|
| Sync dal gestionale (qualunque percorso) | `SyncDatasets['rapporti']`: `a.id` → `dgb_activity_id` |
| Fine sync pianificata | `SyncRunner::run()` → `PmReportLink::relink()` |
| Import CSV DGB | `dgb_activities.php` (action import) → `PmReportLink::relink()` |
| Aggiornamento | `migration_v1_9_79.sql` |
| Monitoraggio | avviso in Relazione IT (`PmReportLink::unlinked($from, $to)`) |
