# TECHNICAL DESIGN — v1.10.11

## Flusso
```
Filtro principale Service SOC ($f) ──SocModel::consuntivoFiltri──▶ filtri ItServiceModel ($cf)
   periodo, contratti                    → from, to, contratti
   Componente (nome SOC)                 → dipendenti = [cm_soc_people.employee_id]
   (nessun componente)                   → dipendenti = componenti UO SOC (cm_tech_profiles attivi, unità soc.uo_code)
   categoria/cliente/commessa/stato/esito/q → tickets = codici dei ticket filtrati (SocModel::where senza periodo)
$cf ──ItServiceModel──▶ totali · aggrega(gb) · andamento · incaricatiDipendenti
```

## Relazioni
`cm_tech_units (SOC)` 1─N `cm_tech_profiles` N─1 `employees` 1─N `cm_intervention_reports` (technician_id) N─1 `cm_projects` (service_line) N─1 `cm_contract_models` → **Tipologia di contratto** (`linea_servizio`, `linea_label`, `modello_contratto` in `v_cm_it_servizio`).
`cm_soc_tickets.ticket_code` = `cm_intervention_reports.ticket` (perimetro dei filtri sui ticket; indice `idx_ir_ticket`).

## Logica di calcolo (invariata, ItServiceModel::oreClassi)
- reperibilità: modalità «reperibilita» o `on_call` del rapportino;
- ordinarie: sovrapposizione reale `start_at`–`end_at` con le fasce ordinarie (PmOrario), altrimenti fascia del modulo;
- fuori orario: ore − ordinarie (non reperibilità);
- non classificate: senza rapportino né fascia;
- giornate-uomo: `COUNT(DISTINCT incaricato|giorno)`.

## ItServiceModel::where — perimetri aggiunti
| Chiave | Condizione |
|---|---|
| `dipendenti` int[] | `s.employee_id IN (…)` |
| `tickets` string[] | `s.report_id IN (SELECT id FROM cm_intervention_reports WHERE ticket IN (…))`; lista vuota → nessuna riga |

Chiavi assenti da `normFilters()`: la Relazione IT non cambia comportamento.

## Viste della scheda
| Sezione | Fonte |
|---|---|
| KPI | `totali($cf)` |
| Andamento mensile (impilato) | `andamento($cf)` |
| Ore per operatore e classe | `aggrega(gb incaricato)` |
| Consuntivo per tipologia | `aggrega(gb linea_servizio, linea_label, modello_contratto)` |
| Consuntivo per operatore | `aggrega(gb incaricato)` + componenti senza moduli (`membriUo − incaricatiDipendenti`) |
| Operatore × tipologia | `aggrega(gb incaricato, linea_label)` |
