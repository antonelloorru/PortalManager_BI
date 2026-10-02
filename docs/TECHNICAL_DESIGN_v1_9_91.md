# TECHNICAL DESIGN — v1.9.91

## Attività DGB senza modulo (ItServiceModel::smFrom)
Base: `dgb_forms_activity a` non annullata, `NOT EXISTS cm_intervention_reports.dgb_activity_id = a.id`,
data attività (`report_date`, in mancanza `date_start`) nel periodo.
Join: allocazione principale, operatore, contratto DGB, PM project (MIN id per dgb_contract_id), modello di contratto, cliente.
Filtri: PmContractFilter (id contratto), stato commessa (cm_projects.dgb_contract_id), incaricato (nome operatore DGB, due ordini),
cliente (clients.name / client_raw), codice linea e linea (cm_projects.service_line / cm_contract_models.label), ricerca (codice attività, contratto, PM project, cliente, ticket).
Ore = allocate, in mancanza human_resource_hours o planned_hours.

| Stato DGB | Motivo |
|---|---|
| assigned, new, planned, scheduled | Assegnata, non ancora rendicontata |
| in_progress | In corso, non ancora rendicontata |
| frozen_* | Congelata / sospesa |
| completed, closed, approved | Eseguita ma senza modulo (da sincronizzare) |
| aborted | Annullata |

## Filtro unico di pagina
`$GLOBALS['PM_NO_AUTOFILTER'] = true` prima di `header.php` in it_service, service_desk, dir_report, dgb_activities:
`footer.php` → `ListFilter::renderAuto()` non aggancia la barra client-side. pm-ui-boost non incluso in nessuna delle quattro pagine.

## Schema ER
Invariato. Indice `dgb_forms_activity(report_date)`.
