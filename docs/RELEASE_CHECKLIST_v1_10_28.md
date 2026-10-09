# RELEASE CHECKLIST — v1.10.28

| Controllo | Esito |
|---|---|
| VERSION, PM_VERSION, app_version/schema_version/release_label = 1.10.28 | ✔ |
| migration_v1_10_28.sql: RUN1/RUN2 err=0 su pmrepo e pm1980 | ✔ |
| `php -l` su tech_report.php, TechReport.php, ItServiceModel.php | ✔ |
| Playwright: Tecnici (Riepilogo e Metriche) e Rapporti (Per commessa, drill-down) con «Linea di servizio» subito dopo «Codice linea», valori popolati, totali allineati | ✔ |
| Export CSV/XLSX/DOCX/PDF di entrambe le schede con la nuova colonna (anche Dettaglio moduli) | ✔ |
| verify_v1_10_28.php 0 KO su pmrepo e pm1980 | ✔ |
| Pacchetto, docs (6), manifest | ✔ |
