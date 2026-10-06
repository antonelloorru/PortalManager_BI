# RELEASE CHECKLIST — v1.10.12

| Controllo | Esito |
|---|---|
| VERSION, PM_VERSION, app_version/schema_version/release_label = 1.10.12 | ✔ |
| migration_v1_10_12.sql idempotente: RUN1/RUN2 err=0 su pmrepo e pm1980; nessun `;` nei commenti | ✔ |
| `php -l` su tutti i PHP nuovi e modificati | ✔ |
| PDF validi (qpdf --check, pdfinfo, rendering pdftoppm): SOC 62 pagine, SD 4, DGB 46 | ✔ |
| DOCX/XLSX validi (ZIP OOXML), CSV con BOM, ZIP per tecnico (SOC 8 file, SD 4) | ✔ |
| Browser: barra Report e pannello per tecnico su SOC, SD, DGB (Analisi e Anomalie); download PDF/DOCX/XLSX/ZIP | ✔ |
| Filtro tecnico attivo → report del tecnico, pannello per tecnico nascosto | ✔ |
| DGB Anomalie: blocco filtri proprio rimosso, filtro principale visibile, 44 segnalazioni ago-2026 | ✔ |
| Un solo blocco filtri (0 ListFilter, 1 form GET) sulle tre pagine | ✔ |
| Σ ore report per incaricato = report generale (DGB) | ✔ |
| verify_v1_10_12.php: 47 OK, 0 KO su pmrepo e pm1980 | ✔ |
| Docs: CHANGELOG, TECHNICAL_DESIGN, MANUALE_ADMIN, MANUALE_UTENTE, DEPLOYMENT, RELEASE_CHECKLIST | ✔ |
| update_manifest.json cumulativo da 1.10.06 | ✔ |
