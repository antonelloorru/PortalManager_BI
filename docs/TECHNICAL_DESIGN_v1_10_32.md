# TECHNICAL DESIGN — v1.10.32

## Moduli
| Componente | Modifica |
|---|---|
| `ItServiceModel::festivi(y1, y2)` | festivi nazionali (cache per anno), ora condivisi con `giorniLavorabili()` |
| `ItServiceModel::prossimoLavorativo(Y-m-d)` | primo lun–ven non festivo successivo |
| `ItServiceModel::controlloReperibilita($f)` | logica di correlazione (sotto) |
| `TechReport` | `TABS['reperibilita']`, `H_REP`, `rep()`, `colFiltri()`, `filtraColonne()`, ramo in `data()` e `build()` |
| `tech_report.php` | scheda, riga dei filtri di colonna, sincronizzazione dei link di stampa/export e del form del pannello |

## Logica di calcolo (`controlloReperibilita`)
1. **Interventi in reperibilità**: `v_cm_it_servizio s` (perimetro `where($f)`: tutti i filtri globali) con `LEFT JOIN cm_intervention_reports ir`, condizione `TIME(ir.start_at) >= '18:01:00' OR TIME(ir.start_at) < '09:00:00'`. Limite di elaborazione 20.000 righe, con il flag `troncato`.
2. **Turno e giorno successivo**:
   - turno = `DATE(start_at)` se l'inizio è dalle 18:01, altrimenti il giorno precedente;
   - giorno successivo = `prossimoLavorativo(turno)`.
3. **Moduli ordinari**: una sola query su `cm_intervention_reports`:
   - `start_at` nell'intervallo dei giorni candidati (indice `idx_ir_start`);
   - `DATE(start_at) IN (…)`, `TIME(start_at) BETWEEN '09:00:00' AND '18:00:59'`;
   - tecnici `technician_id IN (…)` OR (`technician_id IS NULL` AND `technician_professional_id IN (…)`).

   Senza filtri di commessa: il controllo riguarda la persona.
4. **Abbinamento in PHP**: chiave tecnico|giorno → primo modulo con `start_at >= end_at` dell'intervento in reperibilità (o `start_at` se la fine manca), escluso il modulo stesso.
5. **Filtri di colonna**:
   - lato server applicati a stampa ed export; lato client nella vista;
   - confronto «contiene» normalizzato (minuscolo, senza diacritici);
   - KPI calcolati prima dei filtri di colonna.

## Relazioni tra viste
`v_cm_it_servizio.report_id → cm_intervention_reports.id` (modulo, inizio/fine, tecnico); `cm_intervention_reports.project_id → cm_projects` (commessa del modulo del giorno successivo).

## Schema ER
Nessuna modifica.
