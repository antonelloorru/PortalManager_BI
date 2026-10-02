# TECHNICAL DESIGN — v1.9.90

## Flusso dei filtri (unico)
```
pannello <form method=get> ─ $_GET ─ normFilters() ─ $f ─┬─ where($f) → KPI, grafici, dettaglio, andamento
                                                          └─ perimetro($f) = report_id dei moduli filtrati
                                                               ├─ costi, giorni (v1.9.87)
                                                               └─ rsiFrom($f) → riepilogoContratto, dettaglioCommessa(+Sintesi, ajax cid)
```
Nessun altro componente di filtro sulla pagina (PM_NO_AUTOFILTER, niente pm-ui-boost).

## rsiFrom
`cm_intervention_reports ir` (ir.id ∈ perimetro) ⋈ `dgb_forms_activity a` (a.id = ir.dgb_activity_id, non annullata)
⟕ `dgb_forms_activity_operator ao` (ao.id = ir.dgb_source_id) ⟕ operatore, contratto, cliente, PM project, fasce tariffarie.

| Grandezza | Espressione |
|---|---|
| ore | ir.quantity_hours |
| reperibilità | ao.during_availability = 1 OR ir.on_call = 1 |
| straordinario | LEAST(COALESCE(ao.extra_hours, ir.extra_hours), ore), fuori reperibilità |
| ordinarie | ore − straordinario, fuori reperibilità |
| costo contratto | COALESCE(ao.cost, a.human_resource_cost, a.total_cost) |
| giorni-uomo | DISTINCT data modulo × tecnico |

## Schema ER
Invariato. Relazioni: cm_intervention_reports.dgb_source_id → dgb_forms_activity_operator.id; .dgb_activity_id → dgb_forms_activity.id;
dgb_forms_activity.id_contract → dgb_forms_contract.id ← cm_projects.dgb_contract_id.
