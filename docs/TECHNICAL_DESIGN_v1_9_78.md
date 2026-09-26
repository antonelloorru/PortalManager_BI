# Technical Design — v1.9.78 — Filtro globale contratto

## Scopo
Un solo filtro «Codice Contratto / PM Project» valido su Relazione IT, Service Desk, Report direzionale,
Attività & Rendicontazione DGB, persistente fra le pagine.

## Modulo `PmContractFilter`
| Metodo | Ruolo |
|---|---|
| `norm($raw)` | valida: `dgb:<int>` o codice ≤ 64 caratteri senza caratteri di controllo |
| `fromRequest($q)` | GET `contratti`/`contratti_set`/`contract` → sessione; altrimenti sessione |
| `canonical($pdo,$sel)` | `dgb:<id>` → PM Project collegati (link storici) |
| `codes()/ids()/pids()/tickets()` | risoluzione unica, cache per istanza |
| `sql($kind,$col,&$args)` / `andSql()` | `IN (?,…)`; attivo e vuoto → `0=1` |
| `options($pdo,$scopeSql)` | `project_code · codice DGB · cliente` + contratti DGB senza PM Project |
| `field()/banner()/describe()/label()` | UI e descrizioni |

## Risoluzione (ER)
```
selezione ──┬─ project_code ──► cm_projects ──► id (pids), dgb_contract_id (ids)
            └─ dgb:<id> ──────► cm_projects.dgb_contract_id ──► project_code (codes)
ids   ──► dgb_forms_activity.ticket        ─┐
codes ──► cm_intervention_reports.ticket   ─┴► tickets ──► cm_sd_messages.ticket_code / v_cm_sd_ticket.ticket
```

## Binding per pagina
| Pagina | Modello | Chiavi |
|---|---|---|
| Relazione IT | `ItServiceModel::ctrCond/ctrCondDgb` | `commessa` (viste), `a.id_contract` (DGB) |
| Service Desk | `SdModel::ctr()`, `ctrPersone()`, `obj2QuadroFiltrato()` | ticket, `commessa`, `r.project_code`, `r.project_id`, persone |
| Direzionale | `DirModel::where()`, `attenzione()`, `andamento()`, `perimetro()` | `commessa`, `ir.project_id` |
| DGB | `DgbModel::whereActivities/whereDetail`, `ctrOperatoreGiorno()` | `a.id_contract`, (operatore, giorno) |

## Viste aggregate senza chiave commessa → ricalcolo con filtro attivo
`v_cm_dir_andamento`, `v_cm_sd_tecnico_mese`, `v_cm_sd_obj2_quadro`, `v_cm_sd_obj2_linee`,
`v_cm_sd_addetti_mese`, `v_cm_sd_obj23_ripartizione`, `v_cm_sd_obj23_code`, `v_dgb_anomalie_riepilogo`,
`v_cm_anomalia_imputazione_riepilogo`: stesse formule sulle sorgenti, ristrette alla selezione;
senza filtro si continua a leggere la vista (o la sua copia `snap_`).

## Indici (migration)
`dgb_forms_activity(ticket)`, `dgb_forms_activity(id_contract)`, `cm_intervention_reports(ticket)`,
`cm_intervention_reports(project_code)`, `cm_projects(dgb_contract_id)`.
