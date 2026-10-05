# TECHNICAL DESIGN — v1.10.01 (interfaccia Progetti PRJ)
Riferimenti: `TECHNICAL_DESIGN_v1_9_99.md` (ER, permessi, decisioni), `TECHNICAL_DESIGN_v1_10_00.md` (motore).

## Viste e relazioni
| Vista | File | Dati | Permesso |
|---|---|---|---|
| Commesse / Progetti → Progetti PRJ | `manage_projects.php?view=prj` → `app/prj_list.php` | `cm_prj` + clients, companies, cm_projects (lettura), scenario di riferimento, ultimo run; KPI da `PrjRepo::calc` | manage_projects_prj.php view/create/export |
| Scheda progetto | `prj_dashboard.php?id=&tab=&sc=` | tutte le `cm_prj_*` del progetto (versioni vigenti), calcolo live degli scenari | prj_dashboard.php view/edit; calc: prj_dashboard_calc.php edit; link: prj_link.php edit |
| Parametri | `prj_parameters.php?h=&e=` | `cm_prj_param/zone/nearshore/equipment/site_cost/overhead` con prj_key = 0, `cm_prj_source` globali | prj_parameters.php view/edit |
| API | `api_prj.php?action=sp_search\|suggest` | `PrjLink::search/suggestions` (sola lettura) | prj_link.php edit |

`access_control.php`: la vista PRJ di manage_projects.php è ammessa con il permesso `manage_projects_prj.php`; `api_prj.php` è ad accesso diretto e controlla il permesso per ogni azione.

## Scrittura versionata (PrjUi::saveGrid)
- Le tabelle e i campi modificabili sono solo quelli di `PrjUi::EDITABLE`, ciascuno con il suo tipo (num, int, bool, text, enum).
- La riga deve essere vigente e appartenere al progetto (`prj_id`) o essere globale (`prj_key = 0`).
- Numeri: accetta i formati 1.234,56 e 1234.56 e rifiuta i valori negativi.
- Per ogni riga cambiata chiama `PrjRepo::writeVersion(table, id, campi cambiati, decorrenza, utente, nota)`:
  - decorrenza > valid_from vigente → nuova versione;
  - decorrenza = valid_from → rettifica in place;
  - decorrenza < valid_from → errore.
  In tutti i casi le modifiche finiscono in EntityChangeLog.
- Dati non versionati (anagrafica, presales, flag profilo, assegnazioni, scenari, override): UPDATE/INSERT diretti + EntityChangeLog; le cancellazioni passano da RecycleBin.

## Collegamento PRJ ↔ commessa SP (PrjLink)
- `link`: verifica che la commessa esista e non sia un segnaposto DGB-, blocca la riga del PRJ (FOR UPDATE), registra l'azione `collegato`/`sostituito` nello storico, in EntityChangeLog e in write_log.
- `unlink`: registra l'azione `scollegato`.
- `suggestions` (punteggio 0-100):

  | Criterio | Punti |
  |---|---|
  | Stesso cliente | 30 |
  | commercial_ref contiene il codice PRJ o il CIG | 30 |
  | Similarità del nome (trigrammi) | fino a 15 |
  | Sovrapposizione delle date | fino a 15 |
  | Stessa società esecutrice | 10 |

- `afterSync` (chiamata da `CommesseSync::run` dopo il commit):
  - registra gli orfani: ultimo evento collegato/sostituito ma `sp_project_id` NULL dopo il SET NULL;
  - collega i PRJ non collegati il cui codice `PRJ-AAAA-NNNN` compare nel `commercial_ref`.

  Non scrive mai su `cm_projects`.

## Creazione e clonazione (PrjRepo)
- `createPrj`: codice atomico, riga `cm_prj`, produttività (dai parametri globali) e gara alla versione 1.
- `clonePrj`: nuovo codice, stato Bozza, nessuna commessa.
  - Copia le versioni vigenti di servizi, tecnologie, asset, gruppi, volumi, mapping, ore medie per ticket, produttività, profili, requisiti, certificazioni, FTE per servizio, fasce RAL, KPI e associazioni, criteri e input, parametri di progetto, scenari e override.
  - Rimappa gli id e gli ent_id.
  - Non copia calc run, consuntivi, storico collegamenti e assegnazioni di persone.

## Calcolo nelle viste
- Scenari, Costi, Asset & Volumi e Profili ricalcolano tutti gli scenari alla data odierna. Le altre tab ricalcolano solo lo scenario selezionato.
- «Calcola e salva» registra un calc run immutabile alla data as-of scelta.
