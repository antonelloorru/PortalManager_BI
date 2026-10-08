# CHANGELOG — v1.10.24 (2026-10-08)

Software 1.10.24 · Schema 1.10.24 · Upgrade `sql/migration_v1_10_24.sql` (cumulativo da 1.10.06) · Plugin pm-ats 1.3.4 (invariato)

## Gestione Commesse › Ricerca
Nuova pagina `cm_search.php` (voce di menu «Ricerca», subito dopo «Commesse / Progetti»): una vista tabellare filtrabile su tutti gli archivi del modulo, che usa le stesse correlazioni delle pagine esistenti.

| Ambito | Tabella principale | Correlazioni | Pagina che ne abilita la vista |
|---|---|---|---|
| Commesse | `cm_projects` | cliente, società, moduli (n., ore, ultimo), contratto DGB, progetti PRJ | manage_projects |
| Moduli di intervento | `cm_intervention_reports` | commessa, cliente, società, tecnico (dipendente → professionista → testo), fascia | project_dashboard, import_intervention_reports |
| Pianificazione | `cm_project_allocations` | commessa, cliente, società | workload_overview, project_gantt |
| Impegni risorse | `cm_operator_commitments` | risorsa | workload_overview |
| Operazioni di commessa | `cm_project_operations` | commessa, cliente, tipo operazione | project_dashboard, pratix_orders |
| Team di commessa | `cm_team` | commessa, dipendente / professionista | project_dashboard |
| Professionisti | `cm_professionals` | società, dipendente collegato, moduli | professionals |
| Anagrafica tecnica | `cm_tech_profiles` | risorsa, unità, sotto-unità | tech_registry |
| Ordinativi Pratix | `cm_pratix_ext` | commesse via operazioni (`order_code`) | pratix_orders |
| Attività DGB | `dgb_forms_activity` | commessa via `dgb_contract_id`, incaricato | dgb_activities |
| Progetti PRJ | `cm_prj` | cliente, società, commessa SP | manage_projects_prj |
| Timesheet | `cm_timesheet_entries` | dipendente, commessa | timesheet |
| Ticket SOC | `cm_soc_tickets` | cliente, owner / assegnatario | service_soc |

### Funzioni
- **Tutto il database**: per ogni ambito consultabile mostra il numero di righe, le prime corrispondenze e il link «Apri». La ricerca usa il testo libero e i filtri comuni.
- **Filtri comuni**: cerca ovunque, commessa, cliente, risorsa/persona, società esecutrice, periodo dal/al. Su pianificazione e impegni il periodo seleziona gli intervalli che si sovrappongono.
- **Filtro per ogni colonna**: si scrive nella riga sotto le intestazioni. Funziona su testo, numeri, date e sì/no; la sintassi è riportata nella pagina e nel manuale utente.
- **Ordinamento** su ogni colonna, **scelta delle colonne**, **totali** delle colonne numeriche sull'intero risultato, paginazione da 25 a 250 righe.
- Link diretti alla scheda commessa e alla scheda PRJ.
- **Export** dei risultati in **CSV**, **XLSX**, **DOCX** e **PDF**, con le stesse colonne, filtri e ordinamento della vista:
  - ogni file riporta i filtri applicati, il numero di righe trovate e una riga «Totale»;
  - limiti: 50.000 righe per XLSX e CSV, 3.000 per DOCX e PDF; i testi lunghi in DOCX e PDF sono abbreviati a 400 caratteri.
  - Ogni export viene registrato nel log applicativo.

### Sicurezza
- Pagina: permesso `cm_search.php`; l'export richiede `can_export`.
- Ogni ambito è visibile solo a chi può vedere la sua pagina sorgente.
- Le colonne economiche (valori, costi, ricavi, margini, costi orari, totali Pratix) richiedono il permesso virtuale `cm_search_economics.php`. Senza il permesso le colonne vengono rimosse lato server: non sono selezionabili, filtrabili, ordinabili né esportabili, nemmeno modificando l'URL.
- Le espressioni SQL provengono solo dal registro interno. Colonne e ordinamento sono ammessi solo da un elenco chiuso e i valori passano sempre come parametri.
