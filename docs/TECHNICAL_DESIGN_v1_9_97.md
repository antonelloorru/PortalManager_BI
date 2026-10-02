# TECHNICAL DESIGN — v1.9.97

## Parametri GET (array o CSV)
| Chiave | Tipo | Condizione |
|---|---|---|
| operator | int[] | allocazione.id_operator IN |
| status | string[] | a.status IN |
| report_type | STD/R_ANTEA[] | allocazione.exec_report_type IN |
| mode | sede/remoto/smart[] | OR di: from_remote=1 · smart_working=1 · (né remoto né smart) |
| schedule | ordinario/turni[] | dgb_operator_profile.schedule_type IN |
| clienti | int[] | COALESCE(contratto.id_customer_comp, a.id_customer_comp) IN |
| linee | string[] | EXISTS cm_projects (dgb_contract_id = a.id_contract, service_line IN) |
| tipi | int[] | a.id_activitytype IN (etichette da cm_um_fasce) |
| oncall | 1/0 | profilo on_call |
| rep / extra | 1/0 | allocazione during_availability / extra_hours > 0 |
| ticket / modulo / sforo | 1/0 | ticket valorizzato · EXISTS cm_intervention_reports.dgb_activity_id · human_resource_hours > planned_hours (>0) |
| from / to / q / stdh / contratti | — | invariati |

## Struttura
`normFilters()` → array normalizzati (whitelist enum, interi validati) + chiavi singole di compatibilità.
`activityConds($f,$args,'a')` + `allocConds($f,$args,'x'|'ao')`; `whereActivities` usa una sola EXISTS sull'allocazione,
`whereDetail` applica le condizioni direttamente su `ao`. `query($f)` = parametri GET per link/export; `activeCount($f)` = badge.
Opzioni: `clientiOptions()`, `lineeOptions()`, `tipiOptions()`.

## Indici
dgb_forms_activity(id_activitytype), (id_customer_comp); dgb_forms_activity_operator(id_activity, id_operator).
