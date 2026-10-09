# TECHNICAL DESIGN — v1.10.35

## ItServiceModel::serviceDesk($f, $escl)
Costanti: `SD_LINEA = 'WTS-SD'`, `SD_UO = 'Service Desk'`. `sdUnitId()` risolve per nome l'Unità Organizzativa esclusa per default.

**A. Perimetro contratti** — `cm_projects p LEFT JOIN clients`:
- condizione base: `p.service_line = 'WTS-SD' AND ((start ≤ A AND end ≥ Da) OR project_code IN <commesse con moduli>)`;
- filtri globali di contratto: `ctrCond` (Codice contratto / PM Project), `statoCond`, cliente, ricerca;
- valore nel periodo: `value_total / (PERIOD_DIFF(YM(end), YM(start)) + 1) × GREATEST(0, PERIOD_DIFF(YM(LEAST(end, A)), YM(GREATEST(start, Da))) + 1)`.

**B. Perimetro moduli** — `v_cm_it_servizio s LEFT JOIN cm_intervention_reports ir`:
- condizione: `where($f)` (tutti i filtri globali) `AND s.linea_servizio = 'WTS-SD'`;
- aggregati per commessa e totali sull'intero perimetro (COUNT DISTINCT, non somma di righe);
- ticket = `TRIM(ir.ticket)` con `provSql = 'ticket'` (REGEXP `TICKET_RE`).

**C. Esclusione** — `esclSql($escl)`:
- condizione: `COALESCE(s.employee_id,0) IN (PmUoFilter::empSub) OR COALESCE(ir.technician_professional_id,0) IN (PmUoFilter::profSub)`;
- `ticket_altri` = `COUNT(DISTINCT CASE WHEN ticket AND NOT escl …)`; `ticket_solo_escl` = totale − altri;
- ripartizione per unità: `LEFT JOIN cm_tech_profiles` (dipendente o professionista attivo) → `cm_tech_units`, con «(nessuna unità)».

**Media risorse**: media, sui contratti con moduli, di `COUNT(DISTINCT s.incaricato)` per commessa (`cm_team` non è valorizzato per i contratti SD).

`sdDefinizioni()` espone le formule visualizzate nella scheda e negli export.

## TechReport
- `TABS['servicedesk']`, `H_SD_CTR`, `H_SD_UO`, `sdMetriche()`, `sdHCtr()`, `sdCtr($c, $eco)`.
- In `data()`, senza `$eco` i valori economici sono impostati a null lato server.
- `build()`: KPI, Metriche, Per Unità Organizzativa, Per contratto; la meta riporta le unità escluse.

## tech_report.php
- Parametro `uo_escl` (CSV o `uo_escl[]`):
  - assente → [id Service Desk];
  - `0` → nessuna esclusione;
  - il campo nascosto `uo_escl[]=0` rende esplicita la scelta «nessuna».
- `$qs` lo propaga nella scheda; il gruppo «Esclusioni» del pannello compare solo nella scheda ServiceDesk.

## Schema ER
Nessuna modifica. Relazioni: `cm_projects.project_code = v_cm_it_servizio.commessa`; `cm_tech_profiles (employee_id | professional_id) → cm_tech_units`.
