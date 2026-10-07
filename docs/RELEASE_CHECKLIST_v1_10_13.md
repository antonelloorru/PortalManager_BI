# RELEASE CHECKLIST — v1.10.13

| Controllo | Esito |
|---|---|
| VERSION, PM_VERSION, app_version/schema_version/release_label = 1.10.13 | ✔ |
| migration_v1_10_13.sql idempotente: RUN1/RUN2 err=0 su pmrepo e pm1980; nessun `;` nei commenti | ✔ |
| `php -l` su tutti i PHP nuovi e modificati | ✔ |
| Relazione generale: PDF (112 pagine, qpdf ok), DOCX, XLSX (numeri, dettaglio commesse in 1 foglio), CSV | ✔ |
| Report personale (1 incaricato) nei 4 formati; Σ ore per incaricato = generale (7.891,5 h) | ✔ |
| ZIP per incaricato: 80 file XLSX | ✔ |
| Browser: barra Report + pannello 80 incaricati, download PDF/DOCX/XLSX/CSV, stampa personale per riga, alias export=docx | ✔ |
| Un solo blocco filtri (1 form, PM_NO_AUTOFILTER, nessun ListFilter) | ✔ |
| Service Desk: link «Stampa» per componente (4) | ✔ |
| verify_v1_10_13.php: 28 OK, 0 KO su pmrepo e pm1980; verify_v1_10_12: 2 KO attesi (solo numero di versione) | ✔ |
| Docs: CHANGELOG, TECHNICAL_DESIGN, MANUALE_ADMIN, MANUALE_UTENTE, DEPLOYMENT, RELEASE_CHECKLIST | ✔ |
| update_manifest.json cumulativo da 1.10.06 | ✔ |
