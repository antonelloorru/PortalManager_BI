# TECHNICAL DESIGN — v1.10.34

## ItServiceModel::controlloReperibilita
- Interventi in reperibilità:
  - `s.modalita = ItServiceModel::REP_MODALITA` ('reperibilita'), cioè la colonna del filtro Modalità;
  - origine DGB `during_availability`, equivalente a `cm_intervention_reports.on_call` (verificato: 0 differenze);
  - restano la fascia di inizio 18:01–08:59 (`REP_NOTTE`) e il perimetro `where($f)`;
  - `repFlagSql()` della v1.10.33 è rimossa.
- Giorno successivo:
  - `COALESCE(ir.on_call,0) = 0`;
  - cliente `COALESCE(clients(p.client_id).name, ir.client_raw)`, tipo `COALESCE(p.service_line,'(nessuna)')`, etichetta `cm_contract_models.label`, con le stesse regole di `v_cm_it_servizio`.
- Turno, giorno lavorativo successivo e vincolo «non prima della fine» sono invariati.

## TechReport
- `H_REP`: intestazioni come richieste (Cliente / Codice Commessa / Tipo ripetuti).
- `H_REP_GRUPPO` (n/r/g), `hRepColori()` (`C_NEUTRO 475569`, `C_REP A0442C`, `C_GS 15803D`).
- `hRepEstese()`: intestazioni univoche, usate per il CSV, per la descrizione dei filtri di colonna e per le aria-label.
- `build()`: `table(..., ['hcolors' => hRepColori()])`.

## Report multi-formato (opzione `hcolors`)
| Componente | Supporto |
|---|---|
| `PmReport::toHtml` | `th` con `background` e `print-color-adjust:exact` |
| `toDocx` → `DocxWriter::table` | `fill` per colonna |
| `toPdf` → `PdfWriter::table` | rettangolo di intestazione per colonna |
| `toXlsx` → `XlsxWriter::addSheet($name, $rows, $headerColors)` | fill/xf dinamici in `styles.xml` (stile 1 = standard, 2.. = colori) |

La `XlsxWriter` di root ignora il terzo argomento: nessun impatto sulle pagine che la caricano.

## Schema ER
Nessuna modifica.
