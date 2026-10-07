# TECHNICAL DESIGN — v1.10.13

## Flusso
```
it_service.php?<filtro principale>&rep=<docx|xlsx|csv|pdf>[&rep_zip=1]   (export=docx → rep=docx)
  └─ can('export', it_service.php)
     ├─ rep_zip e ≠ 1 incaricato → PmReport::bundle(fmt, it_service_report_tecnici($f), k → it_service_report($f + incaricati=[k]))
     └─ it_service_report($it, $f, $vCtr, $inc, $fmt, $target) → PmReport → send
```
`it_service_report()` legge con ItServiceModel (totali, aggrega, andamento, statoKm, perDimensione, costi, giorni, riepilogoContratto, dettaglioCommessa, attivitaSenzaModulo) sullo stesso `$f` della pagina e scrive il contenuto del report Word v1.9.46 su `PmReportDoc`.

## PmReportDoc (interfaccia DocxWriter → PmReport)
| Metodo | Blocco PmReport |
|---|---|
| heading / paragraph / meta / note / box / kpi / pageBreak | h / para / meta / note / box / kpi / br (salto pagina solo DOCX/PDF) |
| table | table (nome = ultimo titolo; sotto un titolo di livello 3 → `group` = [sezione, titolo]) |
| bars / stackedbars | bars (etichetta di valore) / stacked |
| download | send nel formato richiesto |

## PmReport (estensioni)
- `para`, `stacked`, opzioni tabella `notitle`, `group`.
- XLSX: tabelle `group` con la stessa intestazione unite in un foglio con colonna «Gruppo»; `itNum()` converte «1.234,5» → 1234.5.
- PdfWriter: `stackedbars()` con legenda.

## Report singoli
Incaricati = `ItServiceModel::incaricatiDipendenti($f)` (nomi sul modulo nel perimetro). Filtro singolo `incaricati=[nome]` → scheda personale. Σ ore dei report singoli = report generale (verify).
