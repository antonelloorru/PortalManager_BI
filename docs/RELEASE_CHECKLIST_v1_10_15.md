# RELEASE CHECKLIST — v1.10.15

| Controllo | Esito |
|---|---|
| VERSION, PM_VERSION, app_version/schema_version/release_label = 1.10.15 | ✔ |
| migration_v1_10_15.sql idempotente: RUN1/RUN2 err=0 su pmrepo e pm1980; nessun `;` nei commenti | ✔ |
| `php -l` su tutti i PHP nuovi e modificati | ✔ |
| 6 schede × (HTML, stampa, DOCX, XLSX, CSV, PDF) validi (PDF qpdf ok) | ✔ |
| ACM: esito e Performance % coerenti con consumo e tolleranza; filtri Esito ripartiscono tutte le commesse | ✔ |
| WTS-CSS: Σ ore = moduli della tipologia nel periodo; NV_: Σ incidenze = ore NV / ore totali; Moduli: Σ per tecnico = Σ per tecnico×fascia; MEG: margine = valore − costo | ✔ |
| Browser: 7 schede senza errori PHP, KPI, grafici, tabelle; download PDF/XLSX/DOCX/CSV per ogni scheda; filtro Esito «Under» + Cliente; stampa | ✔ |
| Un solo blocco filtri (1 form, PM_NO_AUTOFILTER); tutte le schede ereditano il filtro principale | ✔ |
| Compatibilità con viste prive della colonna `valorizzata` (pm1980) | ✔ |
| verify_v1_10_15.php 0 KO su pmrepo e pm1980 | ✔ |
| Docs: CHANGELOG, TECHNICAL_DESIGN, MANUALE_ADMIN, MANUALE_UTENTE, DEPLOYMENT, RELEASE_CHECKLIST | ✔ |
| update_manifest.json cumulativo da 1.10.06 | ✔ |
