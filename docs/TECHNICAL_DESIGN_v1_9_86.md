# TECHNICAL DESIGN — v1.9.86

## Componenti
```
recruiting_posizioni.php ─┐
export_positions_xlsx.php ├─ PositionFilter::parse($_GET) → where($F,$params,alias) + scope(role,user,alias)
export_positions_pdf.php ─┘                       └─ PmFilter (values / in / range / date)
recruiting_posizioni.php ── PositionFilter::options($pdo,$scope) → menu pm-ms con conteggi
```

## Parametri GET
| Chiave | Tipo | Colonna / logica |
|---|---|---|
| q | testo ≤100 | LIKE (con escape di % _) su title, department, location, description, required_skills, nice_to_have, hard_skills, soft_skills, linkedin_code, clients.name |
| f_st / f_pr | multi enum | status / priority (whitelist) |
| f_br | multi int | brand_id |
| f_dep / f_loc | multi testo | TRIM(department) / TRIM(location), vuoto = «(n.d.)» |
| f_ct / f_rem | multi enum | contract_type / remote_policy, + «(n.d.)» |
| f_tl / f_rq | multi int | team_leader_id / requested_by, 0 = non assegnato |
| f_cli | multi int | EXISTS position_clients, 0 = NOT EXISTS |
| f_stage | multi enum | EXISTS candidate_applications con stage |
| op_/tg_/cl_ from-to | data | opened_at / target_date / closed_at (estremi inclusi) |
| age_min | int ≤3650 | DATEDIFF(COALESCE(closed_at,oggi), opened_at) ≥ N |
| f_cand / f_fill / f_late / f_li / f_ral | 1/0 | candidature presenti · assunti ≥ GREATEST(positions_expected,1) · target < oggi su draft/open/paused · linkedin_code valorizzato · ral_min o ral_max > 0 |

## Visibilità per ruolo (`scope`)
Ruolo 4 (TeamLeader): `team_leader_id = utente`. Ruolo 5 (Recruiter): `status IN ('open','paused')`. Applicata a vista, opzioni ed export.

## Indici (migration)
`job_positions(status,priority)`, `job_positions(opened_at)`, `job_positions(target_date)`, `candidate_applications(position_id,stage)`.

## Schema ER
Invariato: job_positions 1—N candidate_applications, job_positions N—N clients (position_clients), job_positions N—1 brands / users (team_leader_id, requested_by).
