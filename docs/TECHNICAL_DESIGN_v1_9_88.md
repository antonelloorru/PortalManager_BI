# TECHNICAL DESIGN — v1.9.88

## Perimetro
| Sezione | Fonte | Data di riferimento | Prima | Ora |
|---|---|---|---|---|
| KPI, grafici, tabelle | v_cm_it_servizio | data modulo | tutto | tutto |
| Giorni lavorati | v_cm_it_giorni_base | data modulo | commesse attive oggi, 16 linee escluse | **tutto** |
| Costi | v_cm_sd_costi_valorizzati | data modulo | righe valorizzate | invariato (per definizione solo valorizzate) |
| DGB | dgb_forms_activity | report_date → date_start → completed_at → closed_at | | **report_date → date_start** |

## v_cm_it_giorni_base (nuova definizione)
Stesse colonne della v1.9.18, più `valorizzata` = 1 se esiste una tariffa di listino
(`cm_contract_rates`: commessa, FASCIA_x, unità D/HD/H, natura R, valore > 0). `codice_linea` e `contratto` con
fallback «(nessuna)». WHERE: data modulo e tecnico valorizzati, nessun filtro su linee o stato.

## Metriche (giorniQuadro / giorniOperatore / giorniPer)
- `ore_valorizzate` = Σ ore con valorizzata=1; `ore_non_valorizzate` = Σ ore con valorizzata=0.
- `giorni_uomo_non_valorizzati` = coppie distinte operatore|giorno con almeno un modulo non valorizzato (un giorno può essere in entrambe le classi).
- `giorniPer($f, $dim)`, dim ∈ GIORNI_DIM (elenco chiuso): codice_linea, area_tecnologica, contratto, cliente, commessa,
  anno_mese, fascia, stato_commessa. Stesso `giorniQuery` (periodo + perimetro unico v1.9.87).

## Schema ER
Nessuna tabella modificata. Viste: v_cm_it_giorni_base (ridefinita), v_cm_it_giorni_operatore / _area / _quadro (ridefinite),
v_cm_it_giorni_tutte (eliminata). app_settings: descrizioni di it_giorni_solo_attive / it_giorni_linee_escluse.
