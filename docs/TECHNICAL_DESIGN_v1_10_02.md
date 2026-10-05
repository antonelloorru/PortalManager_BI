# TECHNICAL DESIGN — v1.10.02
Riferimenti: v1.9.99 (ER, permessi), v1.10.00 (motore), v1.10.01 (interfaccia).

## Viste
| Vista | Dati | Calcolo |
|---|---|---|
| `prj_dashboard.php?tab=kpi` | `cm_prj_kpi` (vigenti), `cm_prj_service_kpi`, base d'asta, volumi, produttività | `PrjCalc::penalties` (input in GET, nessuna scrittura); `PrjCalc::conguaglio` |
| `prj_dashboard.php?tab=punt` | `cm_prj_criterion`, `cm_prj_criterion_input` (scenario NULL) | `PrjCalc::technicalScore`, `PrjCalc::economicScore` |
| `prj_dashboard.php?tab=stor` | `cm_prj_calc_run` + `cm_prj_calc_result`, `entity_change_log`, tabelle versionate, `cm_prj_link_history` | `PrjRepo::compareRuns` |
| `prj_history.php` | tutti i calc run, con metriche `totale` pivotate in SQL; zona da `ambito = 'zona'` | `PrjRepo::runResults` per i run selezionati |
| `project_dashboard.php` tab `prj` | `cm_prj` con `sp_project_id` = commessa, consuntivo da `cm_projects` | `PrjRepo::calc` sullo scenario di riferimento |
| `manage_projects.php` | mappa commessa → codici PRJ (`GROUP_CONCAT`) | filtro in PHP dopo `listAll`, colonna in coda all'export |

## Input del punteggio (cm_prj_criterion_input, chiave → valore)

| Criterio | Chiavi |
|---|---|
| C, D | `p1..pN` (Pi) |
| E | `n_servizi`; `s{i}`, `c{i}` per servizio |
| F | `c1..c4` (0 / 0,5 / 1) |
| G | `rtnc` |
| H | `v` |
| Criteri discrezionali | `coeff` (0-1) |
| I.K1, I.K2 | `s` (ribasso 0-1), `w`, `n` |

`k` (K1 = 20, K2 = 10) è il seed. Il salvataggio sostituisce in transazione gli input del progetto (tranne `k`) e lo registra in EntityChangeLog.

## Simulatore penali (regole per unità)
| Unità | Formula |
|---|---|
| blocco_ticket con importi A/M/B | Σ floor(q_p / 5) × importo_p |
| punto_pct (patch) | punti critiche × 1.000 + punti non critiche × 500 |
| pct_sforamento | 10% × sforamento € |
| una_tantum | importo se la quantità è maggiore di 0 |
| giorno, risorsa, minuto, finestra, rilascio, rollback, ticket, settimana | quantità × importo |
| penale_formula valorizzata | FormulaEval |

% sul canone del periodo: canone medio / 12 (mese) o canone medio (anno).

## Colori dei delta
Rosso quando un costo aumenta o un indicatore migliore-se-alto (margine, ribasso a pareggio, FTE finanziabili, canone) diminuisce.

## Grafici
`PmCharts::groupedBars(labels, series[label, color, values], opts[unit, divisor, decimals, height])` disegna in SVG lato server, con asse a zero e valori negativi ammessi.
