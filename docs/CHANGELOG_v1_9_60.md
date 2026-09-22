# PortalManager v1.9.60 — Import Pratix legge il .xls nativamente

## Problema
Import Pratix: "Formato 'xls' non leggibile: installare PhpSpreadsheet…". XAMPP non ha
PhpSpreadsheet e il progetto evita dipendenze esterne.

## Fix
Nuovo `app/XlsReader.php`: lettore .xls (Excel 97-2003, BIFF8) in PHP puro, zero
dipendenze (come XlsxReader). Interpreta OLE2/CFB + record BIFF8 (SST+CONTINUE,
LABELSST, LABEL, RK, MULRK, NUMBER, BOUNDSHEET). `PratixImporter::readRows()`:
.xls → XlsReader, .xlsx → XlsxReader, PhpSpreadsheet solo fallback opzionale.

## QA (PRATIX2026_09_15.xls, 4029 righe)
- Stringhe e Codici identici a xlrd (4028 distinti).
- SUM(Totale) righe con Codice = 133.224.255,71 (= totale file).
- import() end-to-end: 4028 upsert, 1 riga totali scartata, 0 errori.
- php -l OK; migration RUN1/RUN2 err=0; schema_version → 1.9.60.
