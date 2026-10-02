# TECHNICAL DESIGN — v1.9.87

## Flusso dei filtri
```
$_GET ─ normFilters() ─ $f ─┬─ where($f)  ── v_cm_it_servizio s  → totali, aggrega, perDimensione, andamento(+giornaliero), statoKm
                            │      └─ perimetro($f, periodo=si) → TEMP tmp_its_perim_<hash>(report_id)
                            │             ├─ v_cm_sd_costi_valorizzati  report_id IN (…) → costiQuadro, costiRiepilogo
                            │             └─ v_cm_it_giorni_base       report_id IN (…) → giorniQuadro/Operatore/Area/Riconcilia
                            └─ rsiWhere($f) ── dgb_forms_activity a → riepilogoContratto, dettaglioCommessa(+Sintesi, ajax)
                                   ├─ periodo DGB, incaricati, cliente, contratto (PmContractFilter), stato (cm_projects.dgb_contract_id)
                                   └─ se filtri solo-rapportino: a.id IN (dgb_activity_id dei rapportini ∈ perimetro($f, periodo=no))
```

## Stato commessa (`statoCond`)
`EXISTS (SELECT 1 FROM cm_projects pst WHERE <join> AND (<stati in OR>))`, join = `pst.project_code = s.commessa`
oppure `pst.dgb_contract_id = a.id_contract`. Espressioni costanti da elenco chiuso (nessun input in SQL).

| Valore | Condizione su UPPER(TRIM(operational_status)) |
|---|---|
| aperta | = 'APERTA' |
| sospesa | = 'SOSPESA' |
| chiusa | IN ('CHIUSA','ANNULLATA','PERSA') |
| non_chiusa | NOT IN ('CHIUSA','ANNULLATA','PERSA') |

## Perimetro
- Chiave: md5 della clausola + parametri → una tabella temporanea per combinazione nella richiesta (riusata da tutte le sezioni).
- Attivato solo con filtri oltre il periodo (`haFiltri`): senza filtri le sezioni restano sulle condizioni native (stesso risultato, nessun costo aggiuntivo).
- Copertura verificata: 100% delle righe di costi e giorni hanno `report_id` in v_cm_it_servizio; 98,8% delle attività DGB del periodo hanno un rapportino collegato.

## Schema ER
Invariato. Relazioni usate: cm_intervention_reports.project_code → cm_projects.project_code; cm_projects.dgb_contract_id → dgb_forms_activity.id_contract;
cm_intervention_reports.dgb_activity_id → dgb_forms_activity.id.
