# RELEASE CHECKLIST — v1.10.33

| Controllo | Esito |
|---|---|
| VERSION, PM_VERSION, app_version/schema_version/release_label = 1.10.33 | ✔ |
| migration_v1_10_33.sql: RUN1/RUN2 err=0 su pmrepo e pm1980 | ✔ |
| `php -l` su tutti i file modificati | ✔ |
| Nessun modulo diurno (non segnato e con fine oltre le 09:00) fra gli interventi in reperibilità | ✔ |
| Cliente / Commessa / Tipo per entrambi gli interventi (11 colonne), intestazione a gruppi, «notte del» | ✔ |
| Filtri globali e di colonna, stampa ed export (CSV, XLSX, DOCX, PDF) — Playwright senza errori PHP | ✔ |
| verify_v1_10_33.php 0 KO su pmrepo e pm1980 | ✔ |
| Pacchetto, docs (6), manifest | ✔ |
