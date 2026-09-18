# Technical Design — v1.9.41

## Sezione Riepilogo per Codice Contratto
Query su `v_cm_sd_moduli` (rapportini reali) con:
`GROUP BY m.contratto, m.commessa` → interventi (`COUNT DISTINCT report_id`),
giorni-uomo (`COUNT(DISTINCT CONCAT(giorno,'#',tecnico))`), ore, ore extra,
produzione teorica (`SUM(ore × listino(fascia))` via `v_rsi_report_fascia` →
`cm_rate_bands` → `cm_rate_band_rates` cost_type='Cliente', regime 'Ordinario').

I risultati sono raggruppati lato PHP per `contratto`; il rendering emette una riga di
sottototale per contratto e le righe di dettaglio per commessa. Nessun uso di
`dgb_forms_activity_operator` (tabella vuota) → il raggruppamento per commessa torna a
popolarsi.
