# TECHNICAL DESIGN — v1.9.89

## Classi di ore (regola unica: ItServiceModel::oreClassi)
| Classe | Condizione |
|---|---|
| Reperibilità | modalità «reperibilità» oppure `cm_intervention_reports.on_call = 1` → tutte le ore della riga |
| Ordinarie | ore dentro le fasce ordinarie (PmOrario, da start_at/end_at del rapportino) |
| Fuori orario | ore − ordinarie |
| Non classificate | senza orario né fascia rilevabile |
Somma = ore. Usata da: totali (KPI), aggrega (Dettaglio), andamento, andamentoGiornaliero, classiPerPersona (Giorni per persona).

Rapportino: `LEFT JOIN cm_intervention_reports ir ON ir.id = s.report_id` (prima: MIN(id) per codice modulo).

## Nuovi metodi
- `classiPerPersona($f)`: per incaricato ore per classe, interventi e giorni in reperibilità, giorni fuori orario, giorni ordinari.
  Unito in pagina a `giorniOperatore` per nome (incaricato = operatore = technician_raw).
- `nonValorizzate($f)`: righe `valorizzata = 0` di v_cm_it_giorni_base raggruppate per codice linea, commessa, persona;
  motivo = EXISTS tariffa R > 0 sulla commessa ? «Tariffa mancante per fascia/unità» : «Commessa senza listino»;
  combinazioni fascia · unità (D giornata, HD mezza giornata, H ora).

## Indici
`cm_contract_rates(project_code, rate_nature)` per il motivo.

## Schema ER
Invariato.
