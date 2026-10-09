# TECHNICAL DESIGN — v1.10.29 · Descrizione tariffa e filtro Tipo

## Formula unica
`ItServiceModel::tariffaExpr($gb, $tm)` restituisce `CONCAT('Fascia ', gb.fascia, ' (', COALESCE(tm.etichetta, gb.um), ')')`:
- origine dei dati: `v_cm_it_giorni_base` (o la sua copia aggiornata `snap_…`, scelta da PmSnapshot) e `cm_um_tempi` (H Ora, HD Mezza giornata, D Giornata);
- è identica a `v_cm_sd_costi_valorizzati.descrizione_tariffa`;
- le parti sono convertite in `utf8mb4_unicode_ci`, perché copia e anagrafica hanno collazioni diverse.

## Relazione Tecnici (`ItServiceModel`)
| Elemento | Implementazione |
|---|---|
| Colonna | `tariffaJoin($f)`: LEFT JOIN su tabella derivata `report_id → MIN(descrizione)` limitata al periodo del filtro (una lettura per giorno). `trSelect` restituisce `GROUP_CONCAT(DISTINCT tt.descr) AS descrizione_tariffa`. Non si usa una sottoquery correlata, perché la copia non ha indice su `report_id`. |
| Filtro | `normFilters`: `tariffe` (multipla). `where()`: `tariffaFiltro()`, un semi-join non correlato `s.report_id IN (SELECT … WHERE descrizione IN (…))`. Il filtro rientra in `haFiltri` e `descrizioneFiltri`. |
| Valori | `valoriTariffe()`: combinazioni presenti, ordinate per fascia e `cm_um_tempi.ordine` |
| Report | `TechReport::H_MAIN[8]` = «Descrizione tariffa»; `main()` legge `descrizione_tariffa`; nota aggiornata |

Prestazioni su pmrepo:

| Periodo | Tempo |
|---|---|
| un mese | 0,12 s |
| nove mesi | 1,8 s |
| con il filtro | 0,4 s |

## Commesse / Progetti
- `manage_projects.php`: `service_line` è un array, letto da `sl[]` oppure da `sl=a,b` per compatibilità. La select è `multiple` con `pm-ms`. Il conteggio dei filtri attivi ignora gli array vuoti; nei link il valore è serializzato con le virgole.
- `ProjectModel::listAll`: `service_line` come stringa o array, condizione `p.service_line IN (…)` con parametri.

## Scheda Progetto › Consuntivo
- `ItServiceModel::tariffePerModuli(ids)`: una query `report_id IN (…)` per la pagina corrente, al massimo 50 righe.
- `project_dashboard.php`: colonna «Descrizione tariffa»; fascia di costo spostata nel dettaglio.
