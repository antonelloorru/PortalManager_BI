# RELEASE CHECKLIST — v1.10.24

| Controllo | Esito |
|---|---|
| VERSION, PM_VERSION, app_version/schema_version/release_label = 1.10.24 | ✔ |
| migration_v1_10_24.sql idempotente: RUN1/RUN2 err=0 su pmrepo e pm1980; nessun `;` nei commenti | ✔ |
| `php -l` su tutti i file PHP modificati | ✔ |
| 13 ambiti: conteggio, righe, ordinamento su ogni colonna, testo libero + correlazioni + periodo, filtro su ogni colonna per tipo — 0 errori SQL su pmrepo e pm1980 | ✔ |
| Export CSV/XLSX/DOCX/PDF: file validi (XLSX aperto, DOCX zip integro, PDF reso), filtri e totale riportati, troncamento 50.000/3.000 | ✔ |
| Playwright (Super Admin): menu → Ricerca, Tutto il database con testo, filtro numerico/data, filtro non valido segnalato, scelta colonne, link alla scheda commessa, download dei 4 formati, vista 390 px | ✔ |
| Playwright (ruolo Responsabile Commerciale): solo ambiti DGB e PRJ, nessuna colonna economica, colonne/filtri/ordinamento/export economici da URL manipolato ignorati, ambito non consentito → Tutto il database | ✔ |
| Router (slug opaco), menu, catalogo permessi, permessi ruoli iniziali | ✔ |
| verify_v1_10_24.php 0 KO su pmrepo e pm1980 | ✔ |
| Pacchetto update_v1.10.24.zip; docs (6); manifest | ✔ |
