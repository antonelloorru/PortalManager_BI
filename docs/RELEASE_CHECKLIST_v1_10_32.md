# RELEASE CHECKLIST — v1.10.32

| Controllo | Esito |
|---|---|
| VERSION, PM_VERSION, app_version/schema_version/release_label = 1.10.32 | ✔ |
| migration_v1_10_32.sql: RUN1/RUN2 err=0 su pmrepo e pm1980 | ✔ |
| `php -l` su tutti i file modificati | ✔ |
| Scheda Controllo Reperibilità: 8 colonne, KPI, nessun errore PHP (Playwright) | ✔ |
| Correlazione: turno, giorno lavorativo successivo (festivi), primo modulo 09:00–18:00 dopo la fine della reperibilità | ✔ |
| Filtri globali (UO, modalità, periodo) e di colonna; filtri di colonna su stampa / CSV / XLSX / DOCX / PDF e su «Applica» | ✔ |
| giorniLavorabili invariato dopo il refactoring dei festivi (2026 = 254) | ✔ |
| verify_v1_10_32.php 0 KO su pmrepo e pm1980 | ✔ |
| Pacchetto, docs (6), manifest | ✔ |
