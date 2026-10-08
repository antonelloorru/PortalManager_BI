# RELEASE CHECKLIST — v1.10.25

| Controllo | Esito |
|---|---|
| VERSION, PM_VERSION, app_version/schema_version/release_label = 1.10.25 | ✔ |
| migration_v1_10_25.sql idempotente: RUN1/RUN2 err=0 su pmrepo e pm1980; nessun `;` nei commenti | ✔ |
| `php -l` su tutti i file modificati | ✔ |
| Letture ItServiceModel (tecniciLinea, valorizzazione, rapporti*) su pmrepo e pm1980 (vista giorni senza `valorizzata`: ripiego su produzione teorica) | ✔ |
| Filtri tipologia e provenienza: conteggi coerenti (ticket + testo + commessa = moduli) | ✔ |
| Giorni lavorabili: settembre 2026 = 22, festivi nazionali e Pasquetta esclusi | ✔ |
| Playwright: menu → Relazione Tecnici, KPI, righe tecnico × linea con totali per tecnico, stampa, export 4 formati (scheda Tecnici e Rapporti con dettaglio), drill-down moduli, export della singola commessa, filtro tipologia da tabella | ✔ |
| Relazione di Servizio IT invariata (filtri nuovi vuoti) | ✔ |
| verify_v1_10_25.php 0 KO su pmrepo e pm1980 | ✔ |
| Pacchetto update_v1.10.25.zip; docs (6); manifest | ✔ |
