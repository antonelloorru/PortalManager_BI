# RELEASE CHECKLIST — v1.10.35

| Controllo | Esito |
|---|---|
| VERSION, PM_VERSION, app_version/schema_version/release_label = 1.10.35 | ✔ |
| migration_v1_10_35.sql: RUN1/RUN2 err=0 su pmrepo e pm1980 | ✔ |
| `php -l` su tutti i file modificati | ✔ |
| Scheda ServiceDesk: KPI, metriche con formula SQL, per UO, per contratto (Playwright, nessun errore PHP) | ✔ |
| Metriche coerenti: totale ≥ altri team, totale = altri + solo escluse, media = Σ risorse / contratti con moduli | ✔ |
| Esclusione: default Service Desk, multipla, nessuna (uo_escl=0), conservata con Applica ed export | ✔ |
| Filtri globali applicati (es. Unità Organizzativa) | ✔ |
| Valori economici solo con tech_report_economics.php (nessun dato nell'export senza permesso) | ✔ |
| Export CSV / XLSX / DOCX / PDF e stampa | ✔ |
| verify_v1_10_35.php 0 KO su pmrepo e pm1980 | ✔ |
| Pacchetto, docs (6), manifest | ✔ |
