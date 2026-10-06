# TECHNICAL DESIGN — v1.10.12

## Componenti
| File | Scopo |
|---|---|
| `app/PmReport.php` | Report indipendente dal formato: blocchi `meta`, `box`, `note`, `heading`, `kpi`, `table`, `bars`, `pageBreak`; resa `toDocx` (DocxWriter), `toPdf` (PdfWriter), `toXlsx` (XlsxWriter: «Riepilogo» + un foglio per tabella), `toCsv` (sezioni, `;`, UTF-8 BOM); `send()`, `writeToFile()`, `bundle()` (ZIP per risorsa), `toolbar()` (UI) |
| `app/PdfWriter.php` | PDF 1.4 nativo: Helvetica/Helvetica-Bold WinAnsi, metriche per a capo, tabelle con header ripetuto e righe alternate, KPI, barre, box, footer «Pagina n di N», stream FlateDecode |
| `app/SocReport.php` | Contenuto Service SOC (SocModel + ItServiceModel) |
| `app/SdReport.php` | Contenuto Service Desk (SdModel) |
| `app/DgbReport.php` | Contenuto DGB (DgbModel + v_dgb_anomalie_orario) |

## Flusso
```
GET pagina?<filtro principale>&rep=<docx|xlsx|csv|pdf>[&rep_zip=1]
  └─ can('export', pagina)
     ├─ rep_zip=1 e nessun filtro tecnico → PmReport::bundle(fmt, tecnici($f), k → build($f + tecnico=k))
     └─ build($f) → PmReport → send(fmt)
```
Tecnico per pagina: SOC `tec` (nome SOC), Service Desk `tec` (componente), DGB `operator` (id operatore, `operators=[id]`).
Risorse del pannello: SOC `SocReport::tecnici` (incaricati/autori con ticket), SD `teamDettaglio` (ticket presi o moduli > 0), DGB `DgbModel::operatoriPerimetro` (allocazioni nel perimetro).

## Valori
Righe con valori grezzi: DOCX/PDF formattati all'italiana (interi senza decimali, decimali 1 cifra salvo `dec`), XLSX numerici, CSV con virgola decimale. DOCX/PDF limitano gli elenchi lunghi a 1.000 righe (nota nel report), XLSX/CSV a 20.000.

## DGB — anomalie dal filtro principale
`DgbModel::anomalieWhere($f)`: `giorno BETWEEN from/to`, `operator_id IN operators`, contratti con `ctrOperatoreGiorno`. Usato da riepilogo, elenco, contatore nella scheda, export `aexport` e report. Rimossi i parametri `atec`, `atipo`, `asev`, `adal`, `aal`.

## Coerenza
Σ ore dei report per incaricato = ore del report generale (verificato in tools/verify_v1_10_12.php).
