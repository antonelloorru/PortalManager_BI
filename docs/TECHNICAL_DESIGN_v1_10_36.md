# TECHNICAL DESIGN — v1.10.36

## SdModel
- Costanti:
  - `CLIENTI_COMMESSA = 'WTS_3119'`;
  - `CLIENTI_ETICHETTA = 'Ticket e Attività dei clienti'`;
  - `TICKET_RE` (= `ItServiceModel::TICKET_RE`).
- `attivitaClienti($f)`, su `v_cm_sd_moduli m LEFT JOIN cm_intervention_reports ir ON ir.id = m.report_id`, con condizione `m.giorno BETWEEN :da AND :a AND m.commessa = 'WTS_3119'` più componente, `ctr()` contratto e `uoSql()`. Restituisce:
  - attività = `COUNT(*)`;
  - ticket = `COUNT(DISTINCT TRIM(ir.ticket))` con REGEXP;
  - riferimenti liberi e moduli senza ticket;
  - ore = `SUM(m.ore)`;
  - tecnici = `COUNT(DISTINCT m.tecnico)`;
  - giornate = `COUNT(DISTINCT tecnico|giorno)`.
- `scorporo($col)` = `AND COALESCE($col,'') <> 'WTS_3119'`, aggiunto al filtro contratto `ctr($f,'code',…)` in:
  - `moduliContratto`, `moduliRiepilogo`, `moduliCodice` (scheda del componente);
  - `codiciLinea`, `aziendeEsecutrici`;
  - `operativita` (sottoquery dei moduli);
  - `teamQuadro`, `teamDettaglio`, `teamFascia`, `teamContratto`;
  - `obj21Quadro`, `obj2xDettaglio` (OBJ_2.1/2.2), `obj23Tecnici`.

  Non si applica a `costiQuery`, che usa `v_cm_sd_costi_valorizzati` con un perimetro non limitato all'UO, né alle viste sui ticket.
- Proprietà: per lo stesso periodo e filtro, `teamQuadro.moduli + attivitaClienti.attivita` = moduli dell'UO in `v_cm_sd_moduli`.

## Pagina, stampa, report
- `service_desk.php`: `$aCli` nel blocco dati; card `.sd-cli` nella griglia degli indicatori (`repeat(4,1fr) minmax(220px,1.15fr)`); note di scorporo; righe nel foglio «Filtri» dell'export.
- `SdReport::build`: titolo, KPI e nota dopo il quadro del periodo.

## XlsxWriter
`writeToFile`: costruisce i fogli e poi serializza la tabella delle stringhe condivise.

## Schema ER
Nessuna modifica.
