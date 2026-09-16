# Technical Design — v1.9.45

## DocxWriter (app/DocxWriter.php)
Writer OOXML minimale: `[Content_Types].xml`, `_rels/.rels`,
`word/_rels/document.xml.rels`, `word/styles.xml`, `word/document.xml`. API:
`heading()`, `paragraph()`, `table(header, rows)`, `download()/writeToFile()`.
Tabelle con `w:tblGrid` (una colonna per campo), intestazione su sfondo scuro.
Escape via `htmlspecialchars(..., ENT_XML1)`; svuotamento buffer + zlib off prima del
binario (come XlsxWriter).

## Selezione dettagli
`$INC_ALL`/`$incOn()` in `it_service.php`; checkbox `inc[]` nel form; `$qs()` propaga il
sottoinsieme come `inc[]`. Il .docx include le sezioni per cui `$incOn(k)` è vero; il
report di stampa gate-a le stesse sezioni con `if ($incOn(k))`.

## Grafico Andamento
`andamento()` aggiunge `ore_ordinarie` (fascia in orario, non reperibilità) e
`ore_reperibilita` (modalità reperibilità) per mese. Nel grafico SVG: barra viola per la
reperibilità e linea orizzontale tratteggiata al valore target (media mensile ore
ordinarie o `?target=`); la scala include il target così la linea è sempre visibile.

## Schema
Nessun delta: la migration allinea solo `app_version/schema_version/release_label`.

## DOCX allineato al PDF
L'handler `?export=docx` in `it_service.php` ricostruisce le stesse sezioni del
report di stampa (`it_service_print.php`) usando i dati già calcolati in pagina
(`$tot,$km,$trend,$gMod/$gLin/$gSet/$gDur/$gFas,$righe,$gQ,$gOp,$gAr,$gRic,$cQ2,$cRie2,
$riepContratto,$dettCommessa`), la stessa logica «scheda personale» (`count(incaricati)==1`),
lo stesso elenco filtri e lo stesso gating `$incOn()`. `DocxWriter` espone il
vocabolario grafico corrispondente: `heading` (h1 con sottolineatura blu), `meta`,
`box` (barra sinistra + sfondo), `kpi` (card con bordo superiore colorato),
`table` (header scuro + zebra + allineamento colonne), `barlist` (barre a blocchi
colorati per le distribuzioni e l'andamento), `note`, `pageBreak`. I grafici SVG del
PDF sono resi in Word come grafici a barre (Word non ha SVG nativo).
