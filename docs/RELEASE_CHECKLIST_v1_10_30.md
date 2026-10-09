# RELEASE CHECKLIST — v1.10.30

| Controllo | Esito |
|---|---|
| VERSION, PM_VERSION, app_version/schema_version/release_label = 1.10.30 | ✔ |
| migration_v1_10_30.sql: RUN1/RUN2 err=0 su pmrepo e pm1980 | ✔ |
| `php -l` su tutti i file modificati | ✔ |
| Campo «Unità Organizzativa» presente e preselezionato su 8 pagine, risultati ridotti, nessun errore PHP (Playwright) | ✔ |
| Export con filtro UO: Commesse XLSX/CSV, Direzionale XLSX, SD XLSX, SOC XLSX, IT XLSX/DOCX, DGB XLSX/CSV, Carico XLSX, Tecnici XLSX/CSV | ✔ |
| verify_v1_10_30.php 0 KO su pmrepo e pm1980 | ✔ |
| Pacchetto, docs (6), manifest (incl. app/PmUoFilter.php) | ✔ |
