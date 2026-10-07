# CHANGELOG — v1.10.13 (2026-10-07)

Software 1.10.13 · Schema 1.10.13 · Upgrade `sql/migration_v1_10_13.sql` (pacchetto cumulativo da 1.10.06)

## Relazione di Servizio IT — export multi-formato
- **Report generale** in **DOCX, XLSX, CSV, PDF** dalla barra «Report» sotto il filtro principale.
- **Report singoli per incaricato** (pannello sotto la barra, quando il filtro «Incaricato» non è su una sola persona): per ogni incaricato del perimetro DOCX · XLSX · CSV · PDF, «apri con il filtro» e **Stampa** (report personale HTML); «Tutti (ZIP)» = un file per incaricato.
- Con un solo incaricato nel filtro la barra produce la **scheda personale** (come la stampa).
- Contenuto unico per tutti i formati: lo stesso del report Word v1.9.46 (quadro, ripartizioni, andamento, dettaglio, giorni lavorati, costi, riepilogo per contratto, dettaglio per commessa, attività DGB senza modulo), sezioni scelte in «Dettagli da includere».
- XLSX: valori numerici (non testo), «Dettaglio per Commessa» in un solo foglio con la colonna «Gruppo» (contratto); dettaglio completo (non le prime 300 righe) in XLSX e CSV.
- `export=docx` resta valido (alias del report DOCX). «Dati XLSX + pivot» e «Stampa report …» invariati.
- Service Desk: link **Stampa** per componente nel pannello dei report singoli.

## Filtri
La pagina ha un solo blocco filtri (pannello principale, PM_NO_AUTOFILTER): verificato, nessun filtro secondario da rimuovere. Report, ZIP e stampa ereditano solo il filtro principale (+ l'incaricato per i report singoli).

## Tecnico
- `app/PmReportDoc.php`: adattatore con l'interfaccia di DocxWriter che registra in PmReport → un solo codice del report per i quattro formati.
- `app/it_service_report.php`: `it_service_report()` (dati + contenuto dal filtro) e `it_service_report_tecnici()`.
- PmReport: paragrafi, barre con etichetta, barre impilate, tabelle raggruppate, numeri all'italiana → numeri XLSX. PdfWriter: barre impilate con legenda.
- Fix: variabile `$it` riusata dal template (ora `$itModel` per il pannello report).
