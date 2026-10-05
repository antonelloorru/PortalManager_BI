# TECHNICAL DESIGN — v1.9.98

## Mappatura campi Relazione IT → entità DGB
| Campo Relazione IT | Parametro GET | Sorgente DGB | Livello |
|---|---|---|---|
| Contratto / PM Project | `contratti` | `PmContractFilter` su `a.id_contract` | attività |
| Stato commessa | `stato_commessa[]` | `cm_projects.operational_status` (via `dgb_contract_id`); chiusa = Chiusa/Annullata/Persa | attività |
| Cliente (testo) | `cliente` | `clients.name` di `COALESCE(contract.id_customer_comp, a.id_customer_comp)` | attività |
| Cerca ovunque | `q` | code, ticket, `cm_projects` code/name/client_raw, cliente | attività |
| Linea di servizio | `linee[]` | `cm_contract_models.label` (o `service_line`) della commessa | attività |
| Codice linea | `codici[]` | `cm_projects.service_line` | attività |
| Azienda esecutrice | `aziende[]` | `companies.name` di `cm_projects.exec_company_id` | attività |
| Natura | `ricavo` | `cm_contract_models.has_revenue` (default 1) | attività |
| Fascia oraria | `fasce[]` | weekend → fuori; `date_start` 09–13/14–18 → in orario; null → non rilevata | attività |
| Incaricato | `operator[]` | `ao.id_operator` | allocazione |
| Settore tecnologico | `settori[]` | `dgb_operator_map → cm_tech_profiles(attivo) → cm_tech_units` | allocazione |
| Sede di riferimento | `sedi[]` | `dgb_operator_map → employees → company_locations` | allocazione |
| Modalità | `mode[]` | reperibilità > smart > remoto > trasferta (presso cliente) > in sede | allocazione |
| Durata | `durate[]` | `ao.hours` ≥ 4 giornata, > 0 mezza, altrimenti non rilevata | allocazione |
| Raggruppa per | `gb[]` | `DgbModel::DIM` (15 dimensioni), default incaricato › contratto | — |

Filtri di allocazione: in `whereActivities` una sola `EXISTS` (stesso incaricato); in `whereDetail` direttamente su `ao`.

## Calcolo `DgbModel::aggrega()`
FROM: `dgb_forms_activity_operator ao JOIN dgb_forms_activity a` + LEFT JOIN operatore, mappa, dipendente, sede, profilo tecnico, unità, contratto, commessa (MIN id per contratto), modello, azienda, cliente, fascia tipo attività.
WHERE: `whereDetail()` (stesso perimetro di KPI orari, quadro del periodo, distribuzione).
- ore = Σ hours · straordinario = Σ LEAST(extra, hours) esclusa reperibilità · reperibilità = Σ hours con during_availability · ordinarie = ore − straordinario − reperibilità
- giornate-uomo = COUNT DISTINCT (incaricato, data lavoro) · margine = ricavo − costo
- `aggregaTotale()` = stessa query senza GROUP BY · `aggregaGruppi()` = numero gruppi (troncamento a video 1.000, export 50.000).

Verifica: Σ ore del dettaglio = `hoursBreakdown().total_hours` per ogni combinazione testata.

## Schema ER
Nessuna modifica. Relazioni usate: `dgb_forms_activity.id_contract` = `cm_projects.dgb_contract_id`; `dgb_operator_map.dgb_operator_id` → `employees.id` → `cm_tech_profiles.employee_id`.
