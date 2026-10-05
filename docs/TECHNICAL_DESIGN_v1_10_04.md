# Technical Design v1.10.04 — Classi orarie DGB = Relazione di Servizio IT

## Regola unica
```
rep   = COALESCE(ao.during_availability,0) = 1
ord   = CASE WHEN rep THEN 0 ELSE PmOrario::ordinarieSql(a.date_start, a.date_dead_line, ao.hours) END
fuori = CASE WHEN rep THEN 0 ELSE ao.hours − PmOrario::ordinarieSql(...) END
rep_h = CASE WHEN rep THEN ao.hours ELSE 0 END
ord + fuori + rep_h = ao.hours
```
Relazione IT (`ItServiceModel::oreClassi`): stessa funzione su `ir.start_at`, `ir.end_at`, `ir.quantity_hours`, rep = `modalita LIKE 'reperibilit%' OR ir.on_call = 1`.
Corrispondenze: `ir.start_at = a.date_start`, `ir.end_at = a.date_dead_line` (DgbSync + backfill), `ir.on_call = ao.during_availability`, `modalita 'reperibilita'` ⇔ `during_availability = 1`.
«Non classificate» (IT): solo righe senza rapportino → sempre 0 per DGB.

`PmOrario::ordinarieSql`: sovrapposizione reale con le fasce feriali `app_settings.pm_orario_fasce` (default 09:00–13:00, 14:00–18:00), fino a 3 giorni; senza inizio → tutte ordinarie; fine assente o ≤ inizio → tutte ordinarie se l'inizio cade in fascia, altrimenti 0; risultato limitato alle ore dichiarate.

## Viste e calcoli coinvolti
| Metodo `DgbModel` | Uso pagina | Campi |
|---|---|---|
| `classi()` | sorgente unica | rep, ord, fuori, repC |
| `hoursBreakdown()` | card orario, API | ordinary, overtime (=fuori), oncall, extra_declared, total_hours, workload, overtime_pct, oncall_pct |
| `periodSummary()` | Quadro del periodo | ore_in_orario, ore_fuori_orario, ore_reperibilita, ore_extra |
| `temporalDistribution()` | grafico + export | ordinary, overtime, oncall per bucket |
| `aggrega()/aggregaTotale()` | Dettaglio aggregato + XLSX | ore_ordinarie, ore_fuori_orario, ore_reperibilita, ore_extra |
| `hourlyHeatmap()` | matrice oraria | ora ordinaria se inizio ora in fascia configurata, feriale, non in reperibilità |

Tutte usano `whereDetail($f)`: stesso perimetro per ogni filtro (periodo, incaricato, stato, modalità, fascia oraria, durata, cliente, linea, settore, azienda, sede, contratti, reperibilità, extra…).

## ER (invariato)
`dgb_forms_activity (a) 1─N dgb_forms_activity_operator (ao)`; `ao.id ─ cm_intervention_reports.dgb_source_id`; `a.id ─ cm_intervention_reports.dgb_activity_id`; `v_cm_it_servizio.report_id ─ cm_intervention_reports.id`.

## Scrittura
`DgbSync::syncReports()`: colonna `end_at` aggiunta all'UPSERT, `end_at = COALESCE(end_at, VALUES(end_at))`.
