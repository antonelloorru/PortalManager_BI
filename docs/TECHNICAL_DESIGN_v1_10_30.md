# TECHNICAL DESIGN — v1.10.30

## Componente `app/PmUoFilter.php`
Classe statica condivisa. Parametro `uo` (array o CSV), normalizzato a interi positivi univoci (max 30). Gli id validati sono inseriti in chiaro nelle sottoquery: nessun parametro posizionale da allineare con le altre condizioni.

| Metodo | Uso |
|---|---|
| `fromRequest`, `norm`, `query` | lettura e serializzazione del parametro |
| `options(PDO)` | unità attive `[id => name]` (`cm_tech_units`, ordinate per `sort_order`) |
| `empSub`, `profSub` | sottoquery dipendenti/professionisti delle unità (`cm_tech_profiles.is_active = 1`) |
| `empSql(sel, col)` | `col IN (empSub)` |
| `dgbOperatorSql(sel, col)` | via `dgb_operator_map` |
| `projectSql(sel, col)` | `EXISTS` su `cm_intervention_reports` (technician_id / technician_professional_id) OR `cm_team` |
| `projectCodeSql(sel, col)` | per codice commessa (`cm_projects.project_code`) |
| `employeeIds`, `names` | id e nomi (cognome nome / nome cognome) per le sorgenti che identificano per nome |
| `field`, `describe` | rendering `select.pm-ms` e descrizione per i file |

## Integrazione per modello
- `ItServiceModel` (Relazione IT, Relazione Tecnici): `normFilters['uo']`, `where()` → `empSql(uo, s.employee_id)`, `haFiltri`, `descrizioneFiltri`.
- `SocModel`: `where()` → assignee OR owner; `consuntivoFiltri` interseca gli id.
- `SdModel`: `uoNomi`/`uoSql` (`LOWER(TRIM(col)) IN (...)`); `ctrPersone`, `where()` (ticket con presa in carico delle unità), operatori, quadro/fascia/contratto/dettaglio team.
- `DirModel`: `where()` e `attenzione()` → `projectCodeSql`; `andamento()` passa al calcolo su tabelle anche con il solo filtro UO; `perimetro()`.
- `DgbModel`: `allocConds()` (stessa EXISTS degli attributi dell'incaricato), `anomalieWhere()`, `query()`, `activeCount()`.
- `ProjectModel::listAll`: `projectSql(uo, p.id)`.
- `workload_overview.php`: `employee_ids` = intersezione selezione puntuale ∩ dipendenti delle unità (`[-1]` se vuota).

## Schema ER
Nessuna modifica. Relazioni usate: `cm_tech_units 1—N cm_tech_profiles N—1 employees | cm_professionals`; `employees 1—N dgb_operator_map`; `cm_projects 1—N cm_intervention_reports | cm_team`.
